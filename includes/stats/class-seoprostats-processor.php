<?php
/**
 * Buffer files → visits and facts.
 *
 * Takes the current buffer by renaming it (new hits go to a fresh file),
 * then reads it in batches within a time budget, saving the byte offset
 * after each batch so a timeout resumes where it stopped. Per batch: bots
 * out, user agent, channel and location per visit, visits joined or
 * started (30 minutes), then pageviews, events and properties in bulk, and
 * each touched visit's counters recounted from its rows, so a batch run
 * twice gives the same result. Design: docs/architecture.md → Processing.
 *
 * Hit fields (from the tracker; all optional except t):
 *   pv:  p page-load id (16 hex), u path and query, r referrer URL,
 *        w screen width, tz time zone, l language, d properties,
 *        x context (SEOProStats_Tracker::context(): n not found, q site
 *        search, s its words, r its results, i the item shown, l logged in)
 *   eng: p page-load id, s visible milliseconds so far, sc deepest scroll %
 *   e:   p page-load id, n name, u path, d properties, rv {a amount, c currency}
 *   c:   p page-load id, s selector (tag#id.class), l label, h link target,
 *        f flags (1 dead, 2 outbound, 4 affiliate, 8 download)
 *   f:   p page-load id, s selector, l form name, h form target, n fields
 *
 * Clicks and form submits join their page load's visit (looked up by its
 * id once the batch's pageviews are written) and never start or extend one.
 *
 * Lines marked s (server) are purchases SEOProStats_Purchases wrote with
 * the checkout visit's hash and time; the collector never sets s.
 *
 * A pageview's context sets its flags and search words and the visit's
 * login; the item it shows is looked up in WordPress (and kept only when
 * its address is the page's), for the pages table: the hit says which
 * item, never its author or category, so a forged hit cannot set them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One processing lifecycle owns buffer checkpoints and ordered fact writes.
 * Keeping its private stages together avoids exposing partially processed batches.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
final class SEOProStats_Processor { // NOSONAR: single processing lifecycle facade; private stages preserve checkpoint and write ordering.

    /** Fixed placeholder for a binary key supplied as hex. */
    private const HEX_PLACEHOLDER = 'UNHEX(%s)';

    /** Progress (autoload off): file being read, offset, last run. */
    const STATE_OPTION = SEOProStats_Collection::PROCESS_OPTION;

    /** Lines per batch. */
    const BATCH = 5000;

    /** Seconds per run. */
    const BUDGET = 20;

    /** A visit ends after this long without a hit. */
    const VISIT_GAP = 1800;

    /** Most A/B tests one pageview may name. */
    const AB_MAX = 20;

    /** Click and tracking IDs: kept out of stored paths (SEOProStats_Channels::CLICK_IDS says which are ads). */
    const CLICK_IDS = array('gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'fbclid', 'ttclid', 'twclid', 'li_fat_id', 'yclid', '_ga', '_gl', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'igshid');

    /** @var array<string,bool> Host (lower case, no www.) => true, for the site itself. */
    private static $own_hosts = array();

    /** @var DateTimeZone|null */
    private static $tz = null;

    /**
     * Process buffered hits for up to BUDGET seconds.
     *
     * @return array{lines:int,pageviews:int,events:int,clicks:int,bots:int,skipped:int} This run's totals.
     */
    public static function run() {
        $start  = microtime(true);
        $totals = array('lines' => 0, 'pageviews' => 0, 'events' => 0, 'clicks' => 0, 'bots' => 0, 'skipped' => 0);
        if (!SEOProStats_Schema::is_current()) {
            return $totals;
        }
        self::initialize();

        while (SEOProStats_Feature::more_time($start, self::BUDGET)) {
            $file = self::current_file();
            if ($file === '') {
                break;
            }
            $state  = self::state();
            $offset = isset($state['offset']) ? (int) $state['offset'] : 0;
            list($lines, $next) = self::read($file, $offset, self::BATCH);

            if ($lines) {
                $done = self::process($lines);
                foreach ($done as $key => $count) {
                    $totals[$key] += $count;
                }
                $totals['lines'] += count($lines);
            }

            self::checkpoint($file, $state, $next);
        }
        return $totals;
    }

    /**
     * Save progress only after the batch's ordered writes finish.
     *
     * @param string              $file  Taken file.
     * @param array<string,mixed> $state Progress before reading.
     * @param int|null            $next  Next byte offset, or EOF.
     */
    private static function checkpoint($file, array $state, $next) {
        if ($next === null) {
            // End of the file: finished with it.
            wp_delete_file($file);
            $state['file']   = '';
            $state['offset'] = 0;
            // With no other taken file left, every hit received before
            // this one was taken is processed (SEOProStats_Rollup::clear()).
            if (!glob(SEOProStats_Collection::dir() . '/processing-*.php') && preg_match('/^processing-(\d+)-/', basename($file), $m)) {
                $state['clear'] = max(isset($state['clear']) ? (int) $state['clear'] : 0, (int) $m[1]);
            }
        } else {
            $state['offset'] = $next;
        }
        $state['last'] = time();
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Process lines that did not come through the buffer (demo data,
     * SEOProStats_Demo), the same way as buffered ones, in the current
     * data set. Lines are in the collector's format.
     *
     * @param array<int,array<string,mixed>> $lines Decoded lines.
     * @return array{pageviews:int,events:int,clicks:int,bots:int,skipped:int}
     */
    public static function ingest(array $lines) {
        self::initialize();
        $done = array('pageviews' => 0, 'events' => 0, 'clicks' => 0, 'bots' => 0, 'skipped' => 0);
        foreach (array_chunk($lines, self::BATCH) as $batch) {
            foreach (self::process($batch) as $key => $count) {
                $done[$key] += $count;
            }
        }
        return $done;
    }

    /** Load the shared processing context before the first batch. */
    private static function initialize() {
        foreach (array('ua', 'channels', 'dict') as $part) {
            require_once SEOPROSTATS_DIR . "includes/stats/class-seoprostats-$part.php";
        }
        self::$tz        = wp_timezone();
        self::$own_hosts = array();
        foreach ((array) SEOProStats_Collection::config()['hosts'] as $host) {
            self::$own_hosts[self::bare_host((string) $host)] = true;
        }
    }

    /**
     * Stored progress.
     *
     * @return array<string,mixed>
     */
    public static function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * The file to read: the one in progress, else an older taken file,
     * else the buffer, taken now. '' when there is nothing to do.
     *
     * @return string
     */
    private static function current_file() {
        $dir   = SEOProStats_Collection::dir();
        $state = self::state();
        if (!empty($state['file']) && is_file($dir . '/' . basename((string) $state['file']))) {
            return $dir . '/' . basename((string) $state['file']);
        }

        $taken = glob($dir . '/processing-*.php');
        if (!$taken) {
            $buffer = $dir . '/buffer.php';
            if (!is_file($buffer) || filesize($buffer) === 0) {
                return '';
            }
            $name = 'processing-' . time() . '-' . wp_generate_password(6, false, false) . '.php';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic take-over of our own file; WP_Filesystem has no rename.
            if (!rename($buffer, $dir . '/' . $name)) {
                return '';
            }
            $taken = array($dir . '/' . $name);
            // A request that opened the buffer just before the rename writes
            // to the taken file: wait for its lock before reading.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- our own file, locked like the collector does.
            $handle = fopen($taken[0], 'r');
            if ($handle) {
                usleep(50000);
                flock($handle, LOCK_EX);
                flock($handle, LOCK_UN);
                fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
        }
        sort($taken);
        $state['file']   = basename($taken[0]);
        $state['offset'] = 0;
        update_option(self::STATE_OPTION, $state, false);
        return $taken[0];
    }

    /**
     * Up to $max lines of a taken file from a byte offset.
     *
     * @param string $file   File.
     * @param int    $offset Byte offset.
     * @param int    $max    Lines.
     * @return array{0:array<int,array<string,mixed>>,1:int|null} Decoded lines; the next offset, or null at the end.
     */
    private static function read($file, $offset, $max) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed read of our own, possibly large, file.
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return array(array(), null);
        }
        fseek($handle, $offset);
        $lines = array();
        $count = 0;
        while ($count < $max && ($raw = fgets($handle)) !== false) {
            $count++;
            $line = self::decode_line($raw);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        $next = feof($handle) ? null : ftell($handle);
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return array($lines, $next === false ? null : $next);
    }

    /**
     * Decode a complete collector line, excluding guards and torn writes.
     *
     * @param string $raw Buffer line.
     * @return array<string,mixed>|null
     */
    private static function decode_line($raw) {
        if ($raw === '' || $raw[0] !== '{') {
            return null;
        }
        $line = json_decode($raw, true);
        return is_array($line) && isset($line['ts'], $line['v'], $line['e']) && is_array($line['e']) ? $line : null;
    }

    /**
     * One batch.
     *
     * @param array<int,array<string,mixed>> $lines Decoded buffer lines.
     * @return array{pageviews:int,events:int,clicks:int,bots:int,skipped:int}
     */
    private static function process(array $lines) {
        $done = array('pageviews' => 0, 'events' => 0, 'clicks' => 0, 'bots' => 0, 'skipped' => 0);

        // 1. Flatten to hits with their line's context; bots out.
        $batch = array('hits' => array(), 'eng' => array(), 'clicks' => array());
        foreach ($lines as $line) {
            self::flatten_line($line, $batch, $done);
        }
        $eng    = $batch['eng'];
        $clicks = $batch['clicks'];

        // 2. Visits.
        $visits = self::visits($batch['hits']);

        // 3. Dictionary ids for every text in the batch, in bulk; A/B test
        // variants shown: only tests and variants in ab_tests.
        $known = self::ab_known($visits, $clicks);
        $ids   = array();
        foreach (self::batch_texts($visits, $clicks, $known) as $kind => $values) {
            $ids[$kind] = SEOProStats_Dict::ids($kind, $values);
        }

        // 4. Write visits, then their facts.
        $session_ids = self::write_sessions($visits, $ids);
        $counts      = self::write_facts($visits, $session_ids, $ids);
        $done['pageviews'] += $counts[0];
        $done['events']    += $counts[1];
        self::write_exposures($visits, $session_ids, $ids, $known);
        $written            = self::write_clicks($clicks, $ids);
        self::write_ab_clicks($clicks, $ids, $known);
        $done['clicks']    += $written;
        $done['skipped']   += count($clicks) - $written;
        self::write_pages($visits, $ids);

        // 5. Engagement onto its pageviews.
        $touched = array_values($session_ids);
        $touched = array_merge($touched, self::write_engagement($eng));

        // 6. Recount the touched visits from their rows.
        self::recount(array_unique($touched));
        return $done;
    }

    /**
     * One buffer line's hits into the batch; a bot's or a malformed
     * visitor's are counted, not kept.
     *
     * @param array<string,mixed>                                         $line  Decoded buffer line.
     * @param array<string,array<mixed>>                                  $batch Hits, engagement and clicks so far.
     * @param array{pageviews:int,events:int,clicks:int,bots:int,skipped:int} $done  Counts so far.
     */
    private static function flatten_line(array $line, array &$batch, array &$done) {
        $agent = isset($line['ua']) ? (string) $line['ua'] : '';
        $ua    = SEOProStats_UA::parse($agent);
        // Purchases written on the server (SEOProStats_Purchases, s: 1)
        // from a page load's visit have no user agent of their own.
        if ($agent === '' && !empty($line['s'])) {
            $ua['bot'] = false;
        }
        if ($ua['bot']) {
            $done['bots'] += count($line['e']);
            return;
        }
        $visitor = strtolower((string) $line['v']);
        if (!preg_match('/^[0-9a-f]{16}$/', $visitor)) {
            $done['skipped']++;
            return;
        }
        foreach ($line['e'] as $hit) {
            self::flatten_hit($hit, $line, $visitor, $ua, $batch, $done);
        }
    }

    /**
     * One hit into the batch: engagement, a click, or a pageview or event.
     *
     * @param mixed                      $hit     Hit as sent.
     * @param array<string,mixed>        $line    Its buffer line.
     * @param string                     $visitor Visitor (hex).
     * @param array<string,mixed>        $ua      Parsed user agent.
     * @param array<string,array<mixed>>                                  $batch   Hits, engagement and clicks so far.
     * @param array{pageviews:int,events:int,clicks:int,bots:int,skipped:int} $done    Counts so far.
     */
    private static function flatten_hit($hit, array $line, $visitor, array $ua, array &$batch, array &$done) {
        $type = is_array($hit) && isset($hit['t']) ? (string) $hit['t'] : '';
        $pkey = isset($hit['p']) && is_string($hit['p']) && preg_match('/^[0-9a-f]{16}$/', $hit['p']) ? $hit['p'] : '';
        if ($type === 'eng') {
            if ($pkey !== '') {
                $batch['eng'][$pkey] = self::engagement($batch['eng'], $pkey, $hit);
            }
            return;
        }
        if ($type === 'c' || $type === 'f') {
            if ($pkey !== '') {
                $batch['clicks'][] = self::click($hit, $type, $pkey, (int) $line['ts']);
            } else {
                $done['skipped']++;
            }
            return;
        }
        if (!self::is_fact($type, $pkey, $hit)) {
            $done['skipped']++; // Vitals and errors come in later versions.
            return;
        }
        $batch['hits'][] = array('ts' => (int) $line['ts'], 'visitor' => $visitor, 'line' => $line, 'ua' => $ua, 'hit' => $hit, 'type' => $type, 'pkey' => $pkey);
    }

    /**
     * A page load's engagement with a further report merged in: the
     * larger visible time and scroll depth.
     *
     * @param array<string,array{0:int,1:int}> $eng  Engagement so far.
     * @param string                           $pkey Page-load id.
     * @param array<string,mixed>              $hit  Engagement hit.
     * @return array{0:int,1:int}
     */
    private static function engagement(array $eng, $pkey, array $hit) {
        $prev = isset($eng[$pkey]) ? $eng[$pkey] : array(0, 0);
        return array(
            max($prev[0], self::int_in($hit, 's', 0, 86400000)),
            max($prev[1], self::int_in($hit, 'sc', 0, 100)),
        );
    }

    /**
     * Whether a hit is a pageview (with its page-load id) or a named event.
     *
     * @param string $type Hit type.
     * @param string $pkey Page-load id or ''.
     * @param mixed  $hit  Hit as sent.
     * @return bool
     */
    private static function is_fact($type, $pkey, $hit) {
        if ($type === 'pv') {
            return $pkey !== '';
        }
        return $type === 'e' && isset($hit['n']) && is_string($hit['n']) && trim($hit['n']) !== '';
    }

    /**
     * Every text the batch stores, by dictionary kind, for one bulk
     * lookup per kind.
     *
     * @param array<int|string,array<string,mixed>> $visits Visits.
     * @param array<int,array<string,mixed>>    $clicks Clicks.
     * @param array<string,array<string,bool>>  $known  From ab_known().
     * @return array<int,array<int,mixed>>
     */
    private static function batch_texts(array $visits, array $clicks, array $known) {
        $texts = array();
        foreach ($visits as $visit) {
            $texts[SEOProStats_Schema::DICT_HOST][] = $visit['ref_host'];
            $texts[SEOProStats_Schema::DICT_PATH][] = $visit['ref_path'];
            $texts[SEOProStats_Schema::DICT_BROWSER][] = $visit['browser'];
            $texts[SEOProStats_Schema::DICT_OS][] = $visit['os'];
            $texts[SEOProStats_Schema::DICT_LANGUAGE][] = $visit['lang'];
            foreach ($visit['utm'] as $value) {
                $texts[SEOProStats_Schema::DICT_UTM][] = $value;
            }
            foreach ($visit['hits'] as $h) {
                self::fact_texts($h, $texts);
            }
        }
        foreach ($clicks as $c) {
            $texts[SEOProStats_Schema::DICT_SELECTOR][] = $c['selector'];
            $texts[SEOProStats_Schema::DICT_LABEL][]    = $c['label'];
            $texts[SEOProStats_Schema::DICT_TARGET][]   = $c['target'];
        }
        foreach ($known as $test => $variants) {
            $texts[SEOProStats_Schema::DICT_AB_TEST][] = (string) $test;
            foreach (array_keys($variants) as $variant) {
                $texts[SEOProStats_Schema::DICT_AB_VARIANT][] = (string) $variant;
            }
        }
        return $texts;
    }

    /**
     * A pageview's or event's texts, added by dictionary kind.
     *
     * @param array<string,mixed>  $h     Fact.
     * @param array<int,array<int,mixed>>  $texts Texts so far.
     */
    private static function fact_texts(array $h, array &$texts) {
        $texts[SEOProStats_Schema::DICT_PATH][] = $h['path'];
        if ($h['type'] === 'e') {
            $texts[SEOProStats_Schema::DICT_EVENT][] = $h['name'];
        } elseif ($h['search'] !== '') {
            $texts[SEOProStats_Schema::DICT_SEARCH][] = $h['search'];
        }
        foreach ($h['props'] as $key => $value) {
            $texts[SEOProStats_Schema::DICT_PROP_KEY][] = (string) $key;
            $texts[SEOProStats_Schema::DICT_PROP_VAL][] = $value;
        }
    }

    /**
     * Group hits into visits: a hit joins its visitor's latest visit when
     * that visit's last hit was under VISIT_GAP earlier.
     *
     * @param array<int,array<string,mixed>> $hits Hits.
     * @return array<int|string,array<string,mixed>> Visit key (hex) => visit.
     */
    private static function visits(array $hits) {
        usort($hits, static function ($a, $b) {
            return $a['ts'] - $b['ts'];
        });

        // Latest stored visit of each visitor, by day and visitor.
        $days     = array();
        $visitors = array();
        foreach ($hits as $i => $h) {
            $hits[$i]['day']         = self::day($h['ts']);
            $days[$hits[$i]['day']]  = true;
            $visitors[$h['visitor']] = true;
        }
        list($latest, $history) = self::stored_visits(array_keys($days), array_keys($visitors));

        $visits = array();
        $open   = array(); // visitor => visit key
        foreach ($hits as $h) {
            $v      = $h['visitor'];
            $refund = !empty($h['line']['s']) && $h['type'] === 'e' && $h['hit']['n'] === 'Refund';
            if ($refund) {
                // Never create a visit for a refund (including after retention).
                $prev = self::refund_visit($h, isset($history[$v]) ? $history[$v] : array(), $visits);
                if ($prev === null) {
                    continue;
                }
            } elseif (isset($open[$v])) {
                $prev = $visits[$open[$v]];
            } else {
                $prev = isset($latest[$v]) ? $latest[$v] : null;
            }
            $key = self::join_visit($h, $prev, $visits);
            if (!$refund) {
                $open[$v] = $key;
            }
            self::add_hit($visits[$key], $h);
        }
        return $visits;
    }

    /**
     * Stored visits of the batch's visitors on the batch's days: each
     * visitor's latest, and all of them (refunds may arrive after a later
     * same-day visit).
     *
     * @param string[] $days     Site-local dates.
     * @param string[] $visitors Visitors (hex).
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<int,array<string,mixed>>>} Latest and all, by visitor.
     */
    private static function stored_visits(array $days, array $visitors) {
        global $wpdb;
        $latest  = array();
        $history = array();
        foreach (array_chunk($visitors, SEOProStats_Dict::CHUNK) as $chunk) {
            $d = implode(', ', array_fill(0, count($days), '%s'));
            $v = implode(', ', array_fill(0, count($chunk), self::HEX_PLACEHOLDER));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its (day, visitor) index; fixed placeholders.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT LOWER(HEX(skey)) AS skey, LOWER(HEX(visitor)) AS visitor, started, ended, pageviews + events AS n FROM %i WHERE day IN ($d) AND visitor IN ($v)", array_merge(array(SEOProStats_Schema::table('sessions')), $days, $chunk)));
            foreach ((array) $rows as $row) {
                $visit = array('skey' => $row->skey, 'started' => (int) $row->started, 'ended' => (int) $row->ended, 'n' => (int) $row->n, 'stored' => true);
                $history[$row->visitor][] = $visit;
                if (!isset($latest[$row->visitor]) || $visit['ended'] > $latest[$row->visitor]['ended']) {
                    $latest[$row->visitor] = $visit;
                }
            }
        }
        return array($latest, $history);
    }

    /**
     * The visit a refund belongs to: the latest-started one, stored or in
     * this batch, whose time span holds the refund's time.
     *
     * @param array<string,mixed>               $h          Refund hit.
     * @param array<int,array<string,mixed>>    $candidates The visitor's stored visits.
     * @param array<int|string,array<string,mixed>> $visits     Visits so far in this batch.
     * @return array<string,mixed>|null
     */
    private static function refund_visit(array $h, array $candidates, array $visits) {
        foreach ($visits as $visit_key => $candidate) {
            if ($candidate['visitor'] === $h['visitor']) {
                $candidates[] = $candidate + array('skey' => $visit_key);
            }
        }
        $found = null;
        foreach ($candidates as $candidate) {
            if ($h['ts'] >= $candidate['started'] && $h['ts'] <= $candidate['ended'] && ($found === null || $candidate['started'] > $found['started'])) {
                $found = $candidate;
            }
        }
        return $found;
    }

    /**
     * The key of the visit a hit joins: the previous one when it is close
     * enough in time, else a new one. Either is added to $visits.
     *
     * @param array<string,mixed>               $h      Hit.
     * @param array<string,mixed>|null          $prev   The visitor's previous visit.
     * @param array<int|string,array<string,mixed>> $visits Visits so far.
     * @return string|int Visit key (hex).
     */
    private static function join_visit(array $h, $prev, array &$visits) {
        if ($prev !== null && $h['ts'] - $prev['ended'] < self::VISIT_GAP && $h['ts'] >= $prev['started'] - self::VISIT_GAP) {
            $key = $prev['skey'];
            if (!isset($visits[$key])) {
                $visits[$key] = self::new_visit($h, $key, $prev['started'], $prev['n'], true);
                $visits[$key]['ended'] = $prev['ended'];
            }
            return $key;
        }
        $key          = substr(hash('sha256', $h['visitor'] . '|' . $h['ts']), 0, 16);
        $visits[$key] = self::new_visit($h, $key, $h['ts'], 0, false);
        return $key;
    }

    /**
     * A hit onto its visit, as the visit's next pageview or event.
     *
     * @param array<string,mixed> $visit Visit.
     * @param array<string,mixed> $h     Hit.
     */
    private static function add_hit(array &$visit, array $h) {
        $visit['ended'] = max($visit['ended'], $h['ts']);
        $visit['n']++;
        $fact            = self::fact($h, $visit['n']);
        $visit['login']  = max($visit['login'], $fact['login']);
        $visit['hits'][] = $fact;
    }

    /**
     * A visit started by (or continued with) a hit.
     *
     * @param array<string,mixed> $h       First hit of the visit in this batch.
     * @param string              $key     Visit key (hex).
     * @param int                 $started Start time.
     * @param int                 $n       Hits already stored.
     * @param bool                $stored  Whether the visit is already in the table.
     * @return array<string,mixed>
     */
    private static function new_visit(array $h, $key, $started, $n, $stored) {
        $hit  = $h['hit'];
        $line = $h['line'];
        $url  = self::split_url(isset($hit['u']) ? (string) $hit['u'] : '/');
        $ref  = isset($hit['r']) && is_string($hit['r']) ? $hit['r'] : '';
        $host = self::bare_host((string) wp_parse_url($ref, PHP_URL_HOST));
        if (isset(self::$own_hosts[$host])) {
            $host = '';
            $ref  = '';
        }
        $ref_path = $host !== '' ? (string) wp_parse_url($ref, PHP_URL_PATH) : '';
        $tz       = isset($hit['tz']) && is_string($hit['tz']) ? $hit['tz'] : '';
        $lang     = isset($hit['l']) && is_string($hit['l']) && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $hit['l']) ? $hit['l'] : '';

        return array(
            'skey'     => $key,
            'stored'   => $stored,
            'visitor'  => $h['visitor'],
            'day'      => $h['day'],
            'started'  => $started,
            'ended'    => $started,
            'n'        => $n,
            'hits'     => array(),
            'ref_host' => $host,
            'ref_path' => $ref_path,
            'channel'  => SEOProStats_Channels::classify($host, $url['utm'], $url['click']),
            'utm'      => $url['utm'],
            'country'  => self::country(isset($line['cc']) ? (string) $line['cc'] : '', $tz),
            'lang'     => $lang !== '' ? strtolower(substr($lang, 0, 2)) . substr($lang, 2) : '',
            'browser'  => $h['ua']['browser'],
            'browser_ver' => min(65535, $h['ua']['browser_ver']),
            'os'       => $h['ua']['os'],
            'os_ver'   => min(65535, $h['ua']['os_ver']),
            'device'   => $h['ua']['device'],
            'screen'   => self::int_in($hit, 'w', 0, 65535),
            'login'    => 0,
        );
    }

    /**
     * A pageview or event row (before ids).
     *
     * @param array<string,mixed> $h   Hit.
     * @param int                 $seq Position in the visit.
     * @return array<string,mixed>
     */
    private static function fact(array $h, $seq) {
        $hit = $h['hit'];
        // An event without a path takes its pageview's (write_facts()).
        $url   = isset($hit['u']) && is_string($hit['u']) && $hit['u'] !== '' ? self::split_url($hit['u']) : array('path' => '');
        $props = isset($hit['d']) && is_array($hit['d']) ? self::props($hit['d']) : array();
        list($revenue, $currency) = self::revenue($hit);
        // The page's context (pageviews only).
        $ctx = $h['type'] === 'pv' && isset($hit['x']) && is_array($hit['x']) ? $hit['x'] : array();
        list($flags, $search) = self::page_kind($ctx);
        return array(
            'type'     => $h['type'],
            'pkey'     => $h['pkey'],
            'ts'       => $h['ts'],
            'seq'      => min(65535, $seq),
            'path'     => $url['path'],
            'name'     => $h['type'] === 'e' ? substr(trim((string) $hit['n']), 0, 120) : '',
            'props'    => $props,
            'revenue'  => $revenue,
            'currency' => $currency,
            'flags'    => $flags,
            'search'   => $search,
            'post'     => $flags === 0 ? self::int_in($ctx, 'i', 0, PHP_INT_MAX) : 0,
            'login'    => empty($ctx['l']) ? 0 : 1,
            'ab'       => $h['type'] === 'pv' && isset($hit['ab']) ? self::ab_pairs($hit['ab'], self::AB_MAX) : array(),
        );
    }

    /**
     * A hit's custom properties as stored: the first 30 with a name and
     * a plain value, names to 100 characters and values to 300.
     *
     * @param array<mixed,mixed> $data Hit field d.
     * @return array<string,string> Name => value.
     */
    private static function props(array $data) {
        $props = array();
        foreach (array_slice($data, 0, 30, true) as $key => $value) {
            $name = trim((string) $key);
            if (is_scalar($value) && $value !== '' && $name !== '') {
                $props[substr($name, 0, 100)] = substr(self::prop_text($value), 0, 300);
            }
        }
        return $props;
    }

    /**
     * A property value as text; true and false by name.
     *
     * @param bool|int|float|string $value Value.
     * @return string
     */
    private static function prop_text($value) {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /**
     * A hit's revenue in minor units and its currency, or none.
     *
     * @param array<string,mixed> $hit Hit.
     * @return array{0:int,1:string}
     */
    private static function revenue(array $hit) {
        if (isset($hit['rv']['a'], $hit['rv']['c']) && is_numeric($hit['rv']['a']) && preg_match('/^[A-Za-z]{3}$/', (string) $hit['rv']['c'])) {
            return array((int) round((float) $hit['rv']['a'] * 100), strtoupper((string) $hit['rv']['c']));
        }
        return array(0, '');
    }

    /**
     * What a page was, from its context: not found, a site search (and its
     * words), or neither.
     *
     * @param array<string,mixed> $ctx Pageview field x.
     * @return array{0:int,1:string} SEOProStats_Schema::PAGE_* flags and search words.
     */
    private static function page_kind(array $ctx) {
        if (!empty($ctx['n'])) {
            return array(SEOProStats_Schema::PAGE_NOT_FOUND, '');
        }
        if (empty($ctx['q'])) {
            return array(0, '');
        }
        $flags = SEOProStats_Schema::PAGE_SEARCH;
        if (isset($ctx['r']) && is_numeric($ctx['r']) && (int) $ctx['r'] === 0) {
            $flags |= SEOProStats_Schema::PAGE_NO_RESULTS;
        }
        $search = isset($ctx['s']) && is_string($ctx['s']) ? self::search_words($ctx['s']) : '';
        return array($flags, $search);
    }

    /**
     * A/B test variants a hit names: "test:variant" texts (a list for a
     * pageview, one for a click), checked, the first of each test kept.
     *
     * @param mixed $value Hit field ab.
     * @param int   $max   Most pairs.
     * @return array<string,string> Test id => variant slug.
     */
    private static function ab_pairs($value, $max) {
        $out = array();
        foreach (array_slice(is_array($value) ? array_values($value) : array($value), 0, $max) as $pair) {
            if (is_string($pair) && preg_match('/^([a-z0-9]{6,32}):([a-z0-9-]{1,64})$/', $pair, $m) && !isset($out[$m[1]])) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    /**
     * The batch's A/B tests that are in ab_tests, with their variants: a
     * forged hit cannot add tests or variants. One query by primary key.
     *
     * @param array<int|string,array<string,mixed>> $visits Visits.
     * @param array<int,array<string,mixed>>    $clicks Clicks.
     * @return array<string,array<string,bool>> Test id => variant slug => true.
     */
    private static function ab_known(array $visits, array $clicks) {
        global $wpdb;
        $tests = array();
        foreach ($visits as $visit) {
            foreach ($visit['hits'] as $h) {
                $tests += $h['ab'];
            }
        }
        foreach ($clicks as $c) {
            $tests += $c['ab'];
        }
        if (!$tests) {
            return array();
        }
        $tests   = array_slice(array_map('strval', array_keys($tests)), 0, 1000);
        $holders = implode(', ', array_fill(0, count($tests), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; fixed placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT test_id, variants FROM %i WHERE test_id IN ($holders)", array_merge(array(SEOProStats_Schema::table('ab_tests')), $tests)));
        $out  = array();
        foreach ((array) $rows as $row) {
            foreach (self::variant_slugs((string) $row->variants) as $slug) {
                $out[(string) $row->test_id][$slug] = true;
            }
        }
        return $out;
    }

    /**
     * The variant slugs of an A/B test's stored variants.
     *
     * @param string $json ab_tests.variants.
     * @return string[]
     */
    private static function variant_slugs($json) {
        $variants = json_decode($json, true);
        $slugs    = array();
        foreach (is_array($variants) ? $variants : array() as $variant) {
            if (is_array($variant) && isset($variant['slug']) && is_string($variant['slug'])) {
                $slugs[] = $variant['slug'];
            }
        }
        return $slugs;
    }

    /**
     * Each pageview's A/B test variants into ab_exposures (a page load
     * seen again, as in a resumed batch, is skipped).
     *
     * @param array<int|string,array<string,mixed>> $visits      Visits.
     * @param array<string,int>                 $session_ids Visit key => id.
     * @param array<int,array<string,int>>      $ids         Dictionary ids by kind.
     * @param array<string,array<string,bool>>  $known       From ab_known().
     */
    private static function write_exposures(array $visits, array $session_ids, array $ids, array $known) {
        if (!$known) {
            return;
        }
        $rows = array();
        foreach ($visits as $key => $visit) {
            if (!isset($session_ids[$key])) {
                continue;
            }
            foreach ($visit['hits'] as $h) {
                $rows = array_merge($rows, self::exposure_rows($h, $session_ids[$key], $ids, $known));
            }
        }
        self::insert_rows('INSERT IGNORE INTO %i (pkey, test_id, variant_id, session_id, day, ts) VALUES ', SEOProStats_Schema::table('ab_exposures'), '(UNHEX(%s), %d, %d, %d, %s, %d)', $rows);
    }

    /**
     * A pageview's ab_exposures rows: its known A/B test variants.
     *
     * @param array<string,mixed>              $h          Pageview fact.
     * @param int                              $session_id Its visit's id.
     * @param array<int,array<string,int>>     $ids        Dictionary ids by kind.
     * @param array<string,array<string,bool>> $known      From ab_known().
     * @return array<int,array<int,int|string>>
     */
    private static function exposure_rows(array $h, $session_id, array $ids, array $known) {
        $rows = array();
        foreach ($h['ab'] as $test => $variant) {
            if (!isset($known[$test][$variant])) {
                continue;
            }
            $test_id    = self::id($ids, SEOProStats_Schema::DICT_AB_TEST, (string) $test);
            $variant_id = self::id($ids, SEOProStats_Schema::DICT_AB_VARIANT, $variant);
            if ($test_id && $variant_id) {
                $rows[] = array($h['pkey'], $test_id, $variant_id, $session_id, self::day($h['ts']), $h['ts']);
            }
        }
        return $rows;
    }

    /**
     * Insert rows into one of our tables, BATCH rows per statement.
     *
     * @param string                          $sql   Statement up to its values, the table as %i.
     * @param string                          $table Table.
     * @param string                          $group Fixed placeholder group for one row.
     * @param array<int,array<int,int|string>> $rows  Rows, values in $group's order.
     * @return int Rows affected.
     */
    private static function insert_rows($sql, $table, $group, array $rows) {
        global $wpdb;
        $affected = 0;
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $args = array($table);
            foreach ($chunk as $row) {
                array_push($args, ...$row);
            }
            $groups = implode(', ', array_fill(0, count($chunk), $group));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- our own table; $sql is a fixed statement and $groups holds only fixed placeholder groups, one per row.
            $affected += (int) $wpdb->query($wpdb->prepare($sql . $groups, $args));
        }
        return $affected;
    }

    /**
     * Clicks inside an A/B test variant, counted on its page load's
     * exposure (by primary key; none when the page load showed another).
     *
     * @param array<int,array<string,mixed>>   $clicks From click().
     * @param array<int,array<string,int>>     $ids    Dictionary ids by kind.
     * @param array<string,array<string,bool>> $known  From ab_known().
     */
    private static function write_ab_clicks(array $clicks, array $ids, array $known) {
        global $wpdb;
        $counts = array();
        foreach ($clicks as $c) {
            foreach ($c['ab'] as $test => $variant) {
                if (isset($known[$test][$variant])) {
                    $at          = $c['pkey'] . ':' . $test . ':' . $variant;
                    $counts[$at] = (isset($counts[$at]) ? $counts[$at] : 0) + 1;
                }
            }
        }
        $table = SEOProStats_Schema::table('ab_exposures');
        foreach ($counts as $at => $count) {
            list($pkey, $test, $variant) = explode(':', (string) $at, 3);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET clicks = LEAST(65535, clicks + %d) WHERE pkey = UNHEX(%s) AND test_id = %d AND variant_id = %d',
                $table,
                $count,
                $pkey,
                self::id($ids, SEOProStats_Schema::DICT_AB_TEST, $test),
                self::id($ids, SEOProStats_Schema::DICT_AB_VARIANT, $variant)
            ));
        }
    }

    /**
     * Site search words as stored: one line, lower case, emails and long
     * numbers masked (as click labels), at most 100 characters; '' when
     * Settings → Tracking leaves them out (the tracker can be bypassed).
     *
     * @param string $words Words searched for.
     * @return string
     */
    private static function search_words($words) {
        if (!SEOProStats_Statistics::search_terms()) {
            return '';
        }
        // \s covers \x09-\x0D; the other control characters are listed.
        $words = trim((string) preg_replace('/[\s\x00-\x08\x0E-\x1F\x7F]+/u', ' ', $words));
        $words = (string) preg_replace(array('/[^\s@]+@[^\s@]+/u', '/\+?\d(?:[\s().-]?\d){5,}/'), array('…@…', '#'), $words);
        $words = function_exists('mb_strtolower') ? mb_substr(mb_strtolower($words, 'UTF-8'), 0, 100, 'UTF-8') : substr(strtolower($words), 0, 100);
        // PHP 7.4 gives false, not '', for nothing left.
        return (string) $words;
    }

    /**
     * Insert new visits and extend continued ones; ids by visit key.
     *
     * @param array<int|string,array<string,mixed>> $visits Visits.
     * @param array<int,array<string,int>>      $ids    Dictionary ids by kind.
     * @return array<string,int> Visit key => id.
     */
    private static function write_sessions(array $visits, array $ids) {
        global $wpdb;
        $table = SEOProStats_Schema::table('sessions');
        $out   = array();
        foreach (array_chunk($visits, 200, true) as $chunk) {
            $args = array($table);
            foreach ($chunk as $key => $v) {
                array_push($args, ...self::session_row((string) $key, $v, $ids));
            }
            // Continued visits keep their first-hit details; only the end
            // moves, and a login part-way through counts for the visit.
            $groups = implode(', ', array_fill(0, count($chunk), '(UNHEX(%s), UNHEX(%s), %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
            $wpdb->query($wpdb->prepare("INSERT INTO %i (skey, visitor, day, started, ended, entry_id, ref_host_id, ref_path_id, channel, utm_source_id, utm_medium_id, utm_campaign_id, utm_term_id, utm_content_id, country, lang_id, browser_id, browser_ver, os_id, os_ver, device, screen, login) VALUES $groups ON DUPLICATE KEY UPDATE ended = GREATEST(ended, VALUES(ended)), login = GREATEST(login, VALUES(login))", $args));

            $holders = implode(', ', array_fill(0, count($chunk), self::HEX_PLACEHOLDER));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
            $found = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(HEX(skey)) AS k FROM %i WHERE skey IN ($holders)", array_merge(array($table), array_map('strval', array_keys($chunk)))));
            foreach ((array) $found as $row) {
                $out[$row->k] = (int) $row->id;
            }
        }
        return $out;
    }

    /**
     * A visit's sessions row values, in write_sessions()'s column order.
     *
     * @param string                       $key Visit key (hex).
     * @param array<string,mixed>          $v   Visit.
     * @param array<int,array<string,int>> $ids Dictionary ids by kind.
     * @return array<int,int|string>
     */
    private static function session_row($key, array $v, array $ids) {
        $entry = '';
        foreach ($v['hits'] as $h) {
            if ($h['type'] === 'pv') {
                $entry = $h['path'];
                break;
            }
        }
        $row = array(
            $key,
            $v['visitor'],
            $v['day'],
            $v['started'],
            $v['ended'],
            self::id($ids, SEOProStats_Schema::DICT_PATH, $entry),
            self::id($ids, SEOProStats_Schema::DICT_HOST, $v['ref_host']),
            self::id($ids, SEOProStats_Schema::DICT_PATH, $v['ref_path']),
            $v['channel'],
        );
        foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content') as $tag) {
            $row[] = self::id($ids, SEOProStats_Schema::DICT_UTM, isset($v['utm'][$tag]) ? $v['utm'][$tag] : '');
        }
        array_push(
            $row,
            $v['country'],
            self::id($ids, SEOProStats_Schema::DICT_LANGUAGE, $v['lang']),
            self::id($ids, SEOProStats_Schema::DICT_BROWSER, $v['browser']),
            $v['browser_ver'],
            self::id($ids, SEOProStats_Schema::DICT_OS, $v['os']),
            $v['os_ver'],
            $v['device'],
            $v['screen'],
            $v['login']
        );
        return $row;
    }

    /**
     * Insert pageviews, events and their properties.
     *
     * @param array<int|string,array<string,mixed>> $visits      Visits.
     * @param array<string,int>                 $session_ids Visit key => id.
     * @param array<int,array<string,int>>      $ids         Dictionary ids by kind.
     * @return array{0:int,1:int} Pageviews and events inserted.
     */
    private static function write_facts(array $visits, array $session_ids, array $ids) {
        $pv = array();
        $ev = array();
        foreach ($visits as $key => $visit) {
            if (!isset($session_ids[$key])) {
                continue;
            }
            foreach ($visit['hits'] as $h) {
                $h['session_id'] = $session_ids[$key];
                $h['path_id']    = self::id($ids, SEOProStats_Schema::DICT_PATH, $h['path']);
                if ($h['type'] === 'pv') {
                    $pv[] = $h;
                } else {
                    $ev[] = $h;
                }
            }
        }

        list($pageviews, $pv_props) = self::write_pageviews($pv, $ids);
        list($events, $ev_props)    = self::write_events($ev, self::event_pages($pv, $ev), $ids);
        self::insert_rows('INSERT IGNORE INTO %i (owner, owner_id, key_id, value_id, ts) VALUES ', SEOProStats_Schema::table('props'), '(%d, %d, %d, %d, %d)', array_merge($pv_props, $ev_props));
        return array($pageviews, $events);
    }

    /**
     * Insert pageviews (a page-load id seen before, as in a resumed batch,
     * is skipped).
     *
     * @param array<int,array<string,mixed>> $pv  Pageview facts with their ids.
     * @param array<int,array<string,int>>   $ids Dictionary ids by kind.
     * @return array{0:int,1:array<int,array<int,int>>} Rows inserted, and their property rows.
     */
    private static function write_pageviews(array $pv, array $ids) {
        $pageviews = 0;
        $prop_rows = array();
        foreach (array_chunk($pv, self::BATCH) as $chunk) {
            $rows = array();
            foreach ($chunk as $h) {
                $rows[] = array($h['pkey'], $h['session_id'], $h['ts'], $h['seq'], $h['path_id'], $h['flags'], self::id($ids, SEOProStats_Schema::DICT_SEARCH, $h['search']));
            }
            $pageviews += self::insert_rows('INSERT IGNORE INTO %i (pkey, session_id, ts, seq, path_id, flags, search_id) VALUES ', SEOProStats_Schema::table('pageviews'), '(UNHEX(%s), %d, %d, %d, %d, %d, %d)', $rows);

            $with_props = array_filter($chunk, static function ($h) {
                return (bool) $h['props'];
            });
            if ($with_props) {
                $prop_rows = array_merge($prop_rows, self::pageview_prop_rows($with_props, $ids));
            }
        }
        return array($pageviews, $prop_rows);
    }

    /**
     * Property rows of stored pageviews, by their ids.
     *
     * @param array<int,array<string,mixed>> $with_props Pageview facts with properties.
     * @param array<int,array<string,int>>   $ids        Dictionary ids by kind.
     * @return array<int,array<int,int>>
     */
    private static function pageview_prop_rows(array $with_props, array $ids) {
        global $wpdb;
        $holders = implode(', ', array_fill(0, count($with_props), self::HEX_PLACEHOLDER));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
        $found = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(HEX(pkey)) AS k FROM %i WHERE pkey IN ($holders)", array_merge(array(SEOProStats_Schema::table('pageviews')), array_column($with_props, 'pkey'))));
        $by    = array();
        foreach ((array) $found as $row) {
            $by[$row->k] = (int) $row->id;
        }
        $rows = array();
        foreach ($with_props as $h) {
            if (isset($by[$h['pkey']])) {
                $rows = array_merge($rows, self::prop_rows(SEOProStats_Schema::OWNER_PAGEVIEW, $by[$h['pkey']], $h, $ids));
            }
        }
        return $rows;
    }

    /**
     * Path ids of the page loads events happened on, for events without a
     * path: from this batch's pageviews, else the stored ones.
     *
     * @param array<int,array<string,mixed>> $pv Pageview facts with their ids.
     * @param array<int,array<string,mixed>> $ev Event facts with their ids.
     * @return array<string,int> Page-load id => path id.
     */
    private static function event_pages(array $pv, array $ev) {
        global $wpdb;
        $page_of = array();
        $missing = array();
        foreach ($pv as $h) {
            $page_of[$h['pkey']] = $h['path_id'];
        }
        foreach ($ev as $h) {
            if ($h['path_id'] === 0 && $h['pkey'] !== '' && !isset($page_of[$h['pkey']])) {
                $missing[$h['pkey']] = true;
            }
        }
        if (!$missing) {
            return $page_of;
        }
        $holders = implode(', ', array_fill(0, count($missing), self::HEX_PLACEHOLDER));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
        $found = $wpdb->get_results($wpdb->prepare("SELECT path_id, LOWER(HEX(pkey)) AS k FROM %i WHERE pkey IN ($holders)", array_merge(array(SEOProStats_Schema::table('pageviews')), array_map('strval', array_keys($missing)))));
        foreach ((array) $found as $row) {
            $page_of[$row->k] = (int) $row->path_id;
        }
        return $page_of;
    }

    /**
     * Insert events, one at a time: each event's id is needed for its
     * properties, and events are far fewer than pageviews.
     *
     * @param array<int,array<string,mixed>> $ev      Event facts with their ids.
     * @param array<string,int>              $page_of From event_pages().
     * @param array<int,array<string,int>>   $ids     Dictionary ids by kind.
     * @return array{0:int,1:array<int,array<int,int>>} Rows inserted, and their property rows.
     */
    private static function write_events(array $ev, array $page_of, array $ids) {
        global $wpdb;
        $events    = 0;
        $prop_rows = array();
        foreach ($ev as $h) {
            if ($h['path_id'] === 0 && isset($page_of[$h['pkey']])) {
                $h['path_id'] = $page_of[$h['pkey']];
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
            $ok = $wpdb->insert(
                SEOProStats_Schema::table('events'),
                array(
                    'session_id' => $h['session_id'],
                    'ts'         => $h['ts'],
                    'seq'        => $h['seq'],
                    'path_id'    => $h['path_id'],
                    'name_id'    => self::id($ids, SEOProStats_Schema::DICT_EVENT, $h['name']),
                    'revenue'    => $h['revenue'],
                    'currency'   => $h['currency'],
                ),
                array('%d', '%d', '%d', '%d', '%d', '%d', '%s')
            );
            if (!$ok) {
                continue;
            }
            $events++;
            if ($h['props']) {
                $prop_rows = array_merge($prop_rows, self::prop_rows(SEOProStats_Schema::OWNER_EVENT, (int) $wpdb->insert_id, $h, $ids));
            }
        }
        return array($events, $prop_rows);
    }

    /**
     * Property rows for a pageview or event.
     *
     * @param int                          $owner    SEOProStats_Schema::OWNER_* constant.
     * @param int                          $owner_id Row id.
     * @param array<string,mixed>          $h        Fact.
     * @param array<int,array<string,int>> $ids      Dictionary ids.
     * @return array<int,array<int,int>>
     */
    private static function prop_rows($owner, $owner_id, array $h, array $ids) {
        $rows = array();
        foreach ($h['props'] as $key => $value) {
            $rows[] = array($owner, $owner_id, self::id($ids, SEOProStats_Schema::DICT_PROP_KEY, (string) $key), self::id($ids, SEOProStats_Schema::DICT_PROP_VAL, $value), $h['ts']);
        }
        return $rows;
    }

    /**
     * Engagement onto pageviews (the larger value wins, so repeats are harmless).
     *
     * @param array<string,array{0:int,1:int}> $eng Page-load id => [visible ms, scroll %].
     * @return int[] Ids of the visits touched.
     */
    private static function write_engagement(array $eng) {
        global $wpdb;
        $table = SEOProStats_Schema::table('pageviews');
        foreach ($eng as $pkey => $values) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its unique key.
            $wpdb->query($wpdb->prepare('UPDATE %i SET engaged_ms = GREATEST(engaged_ms, %d), scroll = GREATEST(scroll, %d) WHERE pkey = UNHEX(%s)', $table, $values[0], $values[1], (string) $pkey));
        }
        if (!$eng) {
            return array();
        }
        $holders = implode(', ', array_fill(0, count($eng), self::HEX_PLACEHOLDER));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT session_id FROM %i WHERE pkey IN ($holders)", array_merge(array($table), array_map('strval', array_keys($eng))))));
    }

    /**
     * A click or form submit (before ids), checked again here: the tracker
     * can be bypassed, and nothing a visitor typed may be stored.
     *
     * @param array<string,mixed> $hit  Hit.
     * @param string              $type c or f.
     * @param string              $pkey Page-load id.
     * @param int                 $ts   Time.
     * @return array<string,mixed>
     */
    private static function click(array $hit, $type, $pkey, $ts) {
        $text = static function ($key) use ($hit) {
            return isset($hit[$key]) && is_string($hit[$key]) ? $hit[$key] : '';
        };
        // tag#id.class: letters, digits, _ and - between # and dots.
        $selector = substr((string) preg_replace('/[^\w#.-]/', '', $text('s')), 0, 120);
        $label    = trim((string) preg_replace('/\s+/u', ' ', $text('l')));
        $label    = (string) preg_replace(array('/[^\s@]+@[^\s@]+/u', '/\+?\d(?:[\s().-]?\d){5,}/'), array('…@…', '#'), $label);
        $label    = function_exists('mb_substr') ? mb_substr($label, 0, 60, 'UTF-8') : substr($label, 0, 60);
        $target   = $text('h');
        if (!preg_match('/^(?:mailto|tel|sms):$/', $target)) {
            // A path here or an address elsewhere, without its query or fragment.
            $target = preg_match('~^(?:https?://[^/?#\s]+)?/~i', $target) ? (string) strtok($target, '?#') : '';
            $target = substr($target, 0, 300);
        }
        return array(
            'pkey'     => $pkey,
            'ts'       => $ts,
            'kind'     => $type === 'f' ? SEOProStats_Schema::FORM : SEOProStats_Schema::CLICK,
            'selector' => $selector,
            'label'    => $label,
            'target'   => $target,
            'flags'    => $type === 'f' ? 0 : self::int_in($hit, 'f', 0, 15),
            'fields'   => $type === 'f' ? self::int_in($hit, 'n', 0, 255) : 0,
            // The A/B test variant it was inside (clicks only).
            'ab'       => $type === 'c' && isset($hit['ab']) ? self::ab_pairs($hit['ab'], 1) : array(),
        );
    }

    /**
     * Insert clicks and form submits onto their page loads' visits; those
     * whose page load is not stored are dropped.
     *
     * @param array<int,array<string,mixed>> $clicks From click().
     * @param array<int,array<string,int>>   $ids    Dictionary ids by kind.
     * @return int Rows inserted.
     */
    private static function write_clicks(array $clicks, array $ids) {
        global $wpdb;
        if (!$clicks) {
            return 0;
        }
        $pages = array();
        foreach (array_chunk(array_values(array_unique(array_column($clicks, 'pkey'))), SEOProStats_Dict::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), self::HEX_PLACEHOLDER));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
            $found = $wpdb->get_results($wpdb->prepare("SELECT LOWER(HEX(pkey)) AS k, session_id, seq, path_id FROM %i WHERE pkey IN ($holders)", array_merge(array(SEOProStats_Schema::table('pageviews')), array_map('strval', $chunk))));
            foreach ((array) $found as $row) {
                $pages[$row->k] = array((int) $row->session_id, (int) $row->seq, (int) $row->path_id);
            }
        }

        $rows = array();
        foreach ($clicks as $c) {
            if (isset($pages[$c['pkey']])) {
                list($session_id, $seq, $path_id) = $pages[$c['pkey']];
                $rows[] = array(
                    $session_id,
                    $c['ts'],
                    $seq,
                    $path_id,
                    $c['kind'],
                    self::id($ids, SEOProStats_Schema::DICT_SELECTOR, $c['selector']),
                    self::id($ids, SEOProStats_Schema::DICT_LABEL, $c['label']),
                    self::id($ids, SEOProStats_Schema::DICT_TARGET, $c['target']),
                    $c['flags'],
                    $c['fields'],
                );
            }
        }
        return self::insert_rows('INSERT INTO %i (session_id, ts, seq, path_id, kind, selector_id, label_id, target_id, flags, fields) VALUES ', SEOProStats_Schema::table('clicks'), '(%d, %d, %d, %d, %d, %d, %d, %d, %d, %d)', $rows);
    }

    /**
     * What the batch's pages show (post type, author, category), in the
     * pages table: one row per address, written again at most once an
     * hour. Live: the item a pageview names, looked up in WordPress and
     * kept only when its address is the page's. Demo: SEOProStats_Demo's.
     *
     * @param array<int|string,array<string,mixed>> $visits Visits.
     * @param array<int,array<string,int>>      $ids    Dictionary ids by kind.
     */
    private static function write_pages(array $visits, array $ids) {
        global $wpdb;
        $demo  = SEOProStats_Schema::set() === 'demo';
        $views = array(); // path id => [path, post id, time]
        foreach ($visits as $visit) {
            foreach ($visit['hits'] as $h) {
                self::page_view($views, $h, $ids, $demo);
            }
        }
        if (!$views) {
            return;
        }
        $table = SEOProStats_Schema::table('pages');
        $views = self::stale_pages($table, $views, $demo);
        if (!$demo && $views) {
            _prime_post_caches(array_values(array_unique(array_column($views, 1))), true, true);
        }

        $args = array($table);
        $rows = 0;
        foreach ($views as $path_id => $view) {
            $page = $demo ? SEOProStats_Demo::page($view[0]) : self::page($view[0], $view[1]);
            if ($page) {
                array_push($args, $path_id, $page['post_id'], $page['post_type'], $page['author_id'], $page['term_id'], $view[2]);
                $rows++;
            }
        }
        if (!$rows) {
            return;
        }
        $groups = implode(', ', array_fill(0, $rows, '(%d, %d, %s, %d, %d, %d)'));
        // A later view wins; seen moves last, so the others compare with the old one.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
        $wpdb->query($wpdb->prepare("INSERT INTO %i (path_id, post_id, post_type, author_id, term_id, seen) VALUES $groups ON DUPLICATE KEY UPDATE post_id = IF(VALUES(seen) >= seen, VALUES(post_id), post_id), post_type = IF(VALUES(seen) >= seen, VALUES(post_type), post_type), author_id = IF(VALUES(seen) >= seen, VALUES(author_id), author_id), term_id = IF(VALUES(seen) >= seen, VALUES(term_id), term_id), seen = GREATEST(seen, VALUES(seen))", $args));
    }

    /**
     * A pageview onto the batch's pages, the latest view of an address
     * kept: plain pages (not 404s or searches) that name their item, or
     * any in the demo.
     *
     * @param array<int,array<int,mixed>> $views Path id => [path, post id, time].
     * @param array<string,mixed>                    $h     Fact.
     * @param array<int,array<string,int>>           $ids   Dictionary ids by kind.
     * @param bool                                   $demo  Whether the demo set is in use.
     */
    private static function page_view(array &$views, array $h, array $ids, $demo) {
        if ($h['type'] !== 'pv' || $h['flags'] !== 0 || (!$demo && $h['post'] === 0)) {
            return;
        }
        $path_id = self::id($ids, SEOProStats_Schema::DICT_PATH, $h['path']);
        if ($path_id > 0 && (!isset($views[$path_id]) || $h['ts'] >= $views[$path_id][2])) {
            $views[$path_id] = array($h['path'], $h['post'], $h['ts']);
        }
    }

    /**
     * The pages to write: rows checked in the last hour for the same item
     * stay as they are.
     *
     * @param string                                 $table Pages table.
     * @param array<int,array<int,mixed>> $views From page_view().
     * @param bool                                   $demo  Whether the demo set is in use.
     * @return array<int,array<int,mixed>>
     */
    private static function stale_pages($table, array $views, $demo) {
        global $wpdb;
        $holders = implode(', ', array_fill(0, count($views), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; fixed placeholders.
        $known = $wpdb->get_results($wpdb->prepare("SELECT path_id, post_id, seen FROM %i WHERE path_id IN ($holders)", array_merge(array($table), array_keys($views))));
        foreach ((array) $known as $row) {
            $view = $views[(int) $row->path_id];
            if (($demo || (int) $row->post_id === $view[1]) && (int) $row->seen > $view[2] - HOUR_IN_SECONDS) {
                unset($views[(int) $row->path_id]);
            }
        }
        return $views;
    }

    /**
     * What a post (or page, or other single item) is, when its address is
     * the page's: its type, author and category (the primary one an SEO
     * plugin set, else the first; for types without categories, the first
     * term of their first public hierarchical taxonomy).
     *
     * @param string $path    Page path, with any query kept.
     * @param int    $post_id Item the page showed.
     * @return array{post_id:int,post_type:string,author_id:int,term_id:int}|null
     */
    private static function page($path, $post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !in_array($post->post_status, array('publish', 'private'), true)) {
            return null;
        }
        if (!self::is_post_page($post, $path)) {
            return null;
        }
        $taxonomy = self::page_taxonomy($post->post_type);
        $term     = $taxonomy !== '' ? self::page_term($post, $taxonomy) : 0;
        /**
         * Filters the category (or other term) a post counts under in the
         * statistics' Content report.
         *
         * @param int     $term Term ID, or 0 for none.
         * @param WP_Post $post The post.
         */
        $term = (int) apply_filters('seoprostats_page_term', $term, $post);
        return array(
            'post_id'   => (int) $post->ID,
            'post_type' => substr($post->post_type, 0, 20),
            'author_id' => (int) $post->post_author,
            'term_id'   => max(0, $term),
        );
    }

    /**
     * Whether a page's path is a post's address.
     *
     * @param WP_Post $post Post.
     * @param string  $path Page path, with any query kept.
     * @return bool
     */
    private static function is_post_page($post, $path) {
        $link = get_permalink($post);
        if (!is_string($link)) {
            return false;
        }
        $want = self::split_url($link)['path'];
        // Kept query parameters (?lang=…) still show the item; plain
        // permalinks (?p=…) need theirs.
        $have = strpos($want, '?') === false ? explode('?', $path, 2)[0] : $path;
        return untrailingslashit($want) === untrailingslashit($have);
    }

    /**
     * The taxonomy a post type's posts count under: category, else its
     * first public hierarchical taxonomy, else none ('').
     *
     * @param string $post_type Post type.
     * @return string
     */
    private static function page_taxonomy($post_type) {
        if (is_object_in_taxonomy($post_type, 'category')) {
            return 'category';
        }
        foreach (get_object_taxonomies($post_type, 'objects') as $object) {
            if ($object->hierarchical && $object->public) {
                return $object->name;
            }
        }
        return '';
    }

    /**
     * A post's term in a taxonomy: the primary one an SEO plugin set, else
     * the first; 0 for none.
     *
     * @param WP_Post $post     Post.
     * @param string  $taxonomy Taxonomy.
     * @return int
     */
    private static function page_term($post, $taxonomy) {
        $terms = get_the_terms($post, $taxonomy);
        if (!is_array($terms) || !$terms) {
            return 0;
        }
        $assigned = array_map('intval', wp_list_pluck($terms, 'term_id'));
        foreach (array('_yoast_wpseo_primary_' . $taxonomy, 'rank_math_primary_' . $taxonomy) as $key) {
            $primary = (int) get_post_meta($post->ID, $key, true);
            if ($primary && in_array($primary, $assigned, true)) {
                return $primary;
            }
        }
        return $assigned[0];
    }

    /**
     * Recount visits from their rows: pageviews, events, engaged time and
     * exit page. Idempotent.
     *
     * @param int[] $session_ids Visit ids.
     */
    private static function recount(array $session_ids) {
        global $wpdb;
        $s  = SEOProStats_Schema::table('sessions');
        $pv = SEOProStats_Schema::table('pageviews');
        $ev = SEOProStats_Schema::table('events');
        foreach (array_chunk(array_values(array_filter(array_map('intval', $session_ids))), SEOProStats_Dict::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // Our own tables by the (session_id, seq) indexes; $holders holds only fixed placeholders.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->query($wpdb->prepare(
                "UPDATE %i s SET
                    pageviews = (SELECT COUNT(*) FROM %i p WHERE p.session_id = s.id),
                    events = (SELECT COUNT(*) FROM %i e WHERE e.session_id = s.id),
                    engaged_ms = (SELECT COALESCE(SUM(p.engaged_ms), 0) FROM %i p WHERE p.session_id = s.id),
                    exit_id = COALESCE((SELECT p.path_id FROM %i p WHERE p.session_id = s.id ORDER BY p.seq DESC LIMIT 1), s.entry_id)
                WHERE s.id IN ($holders)",
                array_merge(array($s, $pv, $ev, $pv, $pv), $chunk)
            ));
            // phpcs:enable
        }
    }

    /**
     * Path (with the remaining query) and the campaign tags of a URL.
     * Imports use it too, so their pages are the same dict rows as visits'.
     *
     * @param string $url Path and query, or a full URL.
     * @return array{path:string,utm:array<string,string>,click:string}
     */
    public static function split_url($url) {
        $path  = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $parts = array('utm' => array(), 'click' => '', 'keep' => array());
        if ($query !== '') {
            parse_str($query, $params);
            foreach ($params as $key => $value) {
                if (is_string($value)) {
                    self::url_param($parts, strtolower((string) $key), $value);
                }
            }
        }
        $keep = $parts['keep'];
        ksort($keep);
        $path = '/' . ltrim(rawurldecode($path === '' ? '/' : $path), '/');
        return array(
            'path'  => $keep ? $path . '?' . http_build_query($keep) : $path,
            'utm'   => $parts['utm'],
            'click' => $parts['click'],
        );
    }

    /**
     * One query parameter into split_url()'s parts: a campaign tag (ref
     * and source stand in for utm_source), an ad click id (the first known
     * one names the click), or a parameter the path keeps.
     *
     * @param array{utm:array<string,string>,click:string,keep:array<string,string>} $parts Parts so far.
     * @param string                                                                 $key   Name, lower case.
     * @param string                                                                 $value Value.
     */
    private static function url_param(array &$parts, $key, $value) {
        if (in_array($key, array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'), true)) {
            $value = strtolower(trim(substr($value, 0, 200)));
            if ($value !== '') {
                $parts['utm'][$key] = $value;
            }
        } elseif ($key === 'ref' || $key === 'source') {
            if (!isset($parts['utm']['utm_source']) && trim($value) !== '') {
                $parts['utm']['utm_source'] = strtolower(trim(substr($value, 0, 200)));
            }
        } elseif (in_array($key, self::CLICK_IDS, true)) {
            if ($parts['click'] === '' && isset(SEOProStats_Channels::CLICK_IDS[$key])) {
                $parts['click'] = $key;
            }
        } else {
            $parts['keep'][$key] = $value;
        }
    }

    /**
     * Country: the CDN's header, else the browser time zone's country.
     *
     * @param string $header Country from the CDN (two letters).
     * @param string $tz     Browser time zone (IANA name).
     * @return string Two upper-case letters or ''.
     */
    private static function country($header, $tz) {
        static $by_tz = array();
        $header = strtoupper($header);
        if (preg_match('/^[A-Z]{2}$/', $header) && $header !== 'XX' && $header !== 'T1') {
            return $header;
        }
        if ($tz === '' || !preg_match('~^[A-Za-z_]+(/[A-Za-z0-9_+-]+){1,2}$~', $tz)) {
            return '';
        }
        if (!isset($by_tz[$tz])) {
            $by_tz[$tz] = '';
            try {
                $location = (new DateTimeZone($tz))->getLocation();
                if (is_array($location) && isset($location['country_code']) && preg_match('/^[A-Z]{2}$/', $location['country_code'])) {
                    $by_tz[$tz] = $location['country_code'];
                }
            } catch (Exception $e) {
                unset($e);
            }
        }
        return $by_tz[$tz];
    }

    /**
     * Site-local date of a time.
     *
     * @param int $ts Unix time.
     * @return string Y-m-d.
     */
    private static function day($ts) {
        $date = new DateTime('@' . $ts);
        $date->setTimezone(self::$tz ? self::$tz : new DateTimeZone('UTC'));
        return $date->format('Y-m-d');
    }

    /**
     * A host in lower case without www.
     *
     * @param string $host Host.
     * @return string
     */
    private static function bare_host($host) {
        $host = strtolower(trim($host, '. '));
        // PHP 7.4's substr() gives false, not '', for a bare "www.".
        return strpos($host, 'www.') === 0 ? (string) substr($host, 4) : $host;
    }

    /**
     * An integer field of a hit, clamped.
     *
     * @param array<string,mixed> $hit Hit.
     * @param string              $key Field.
     * @param int                 $min Minimum.
     * @param int                 $max Maximum.
     * @return int
     */
    private static function int_in(array $hit, $key, $min, $max) {
        return isset($hit[$key]) && is_numeric($hit[$key]) ? max($min, min($max, (int) $hit[$key])) : $min;
    }

    /**
     * Dictionary id of a text from the batch's lookups.
     *
     * @param array<int,array<string,int>> $ids   Ids by kind.
     * @param int                          $kind  Kind.
     * @param string                       $value Text.
     * @return int
     */
    private static function id(array $ids, $kind, $value) {
        $value = SEOProStats_Dict::clean((string) $value);
        return isset($ids[$kind][$value]) ? $ids[$kind][$value] : 0;
    }
}
