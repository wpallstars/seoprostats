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

final class SEOProStats_Processor {

    /** Progress (autoload off): file being read, offset, last run. */
    const STATE_OPTION = SEOProStats_Collection::PROCESS_OPTION;

    /** Lines per batch. */
    const BATCH = 5000;

    /** Seconds per run. */
    const BUDGET = 20;

    /** A visit ends after this long without a hit. */
    const VISIT_GAP = 1800;

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
        foreach (array('ua', 'channels', 'dict') as $part) {
            require_once SEOPROSTATS_DIR . "includes/stats/class-seoprostats-$part.php";
        }
        self::$tz        = wp_timezone();
        self::$own_hosts = array();
        foreach ((array) SEOProStats_Collection::config()['hosts'] as $host) {
            self::$own_hosts[self::bare_host((string) $host)] = true;
        }

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
        return $totals;
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
        foreach (array('ua', 'channels', 'dict') as $part) {
            require_once SEOPROSTATS_DIR . "includes/stats/class-seoprostats-$part.php";
        }
        self::$tz        = wp_timezone();
        self::$own_hosts = array();
        foreach ((array) SEOProStats_Collection::config()['hosts'] as $host) {
            self::$own_hosts[self::bare_host((string) $host)] = true;
        }
        $done = array('pageviews' => 0, 'events' => 0, 'clicks' => 0, 'bots' => 0, 'skipped' => 0);
        foreach (array_chunk($lines, self::BATCH) as $batch) {
            foreach (self::process($batch) as $key => $count) {
                $done[$key] += $count;
            }
        }
        return $done;
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
            if ($raw === '' || $raw[0] !== '{') {
                continue; // The guard line, or a torn write.
            }
            $line = json_decode($raw, true);
            if (is_array($line) && isset($line['ts'], $line['v'], $line['e']) && is_array($line['e'])) {
                $lines[] = $line;
            }
        }
        $next = feof($handle) ? null : ftell($handle);
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return array($lines, $next === false ? null : $next);
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
        $hits   = array();
        $eng    = array();
        $clicks = array();
        foreach ($lines as $line) {
            $agent = isset($line['ua']) ? (string) $line['ua'] : '';
            $ua    = SEOProStats_UA::parse($agent);
            // Purchases written on the server (SEOProStats_Purchases, s: 1)
            // from a page load's visit have no user agent of their own.
            if ($agent === '' && !empty($line['s'])) {
                $ua['bot'] = false;
            }
            if ($ua['bot']) {
                $done['bots'] += count($line['e']);
                continue;
            }
            $visitor = strtolower((string) $line['v']);
            if (!preg_match('/^[0-9a-f]{16}$/', $visitor)) {
                $done['skipped']++;
                continue;
            }
            foreach ($line['e'] as $hit) {
                $type = is_array($hit) && isset($hit['t']) ? (string) $hit['t'] : '';
                $pkey = isset($hit['p']) && is_string($hit['p']) && preg_match('/^[0-9a-f]{16}$/', $hit['p']) ? $hit['p'] : '';
                if ($type === 'eng') {
                    if ($pkey !== '') {
                        $eng[$pkey] = array(
                            max(isset($eng[$pkey]) ? $eng[$pkey][0] : 0, self::int_in($hit, 's', 0, 86400000)),
                            max(isset($eng[$pkey]) ? $eng[$pkey][1] : 0, self::int_in($hit, 'sc', 0, 100)),
                        );
                    }
                    continue;
                }
                if ($type === 'c' || $type === 'f') {
                    if ($pkey !== '') {
                        $clicks[] = self::click($hit, $type, $pkey, (int) $line['ts']);
                    } else {
                        $done['skipped']++;
                    }
                    continue;
                }
                if (($type !== 'pv' || $pkey === '') && ($type !== 'e' || !isset($hit['n']) || !is_string($hit['n']) || trim($hit['n']) === '')) {
                    $done['skipped']++; // Vitals and errors come in later versions.
                    continue;
                }
                $hits[] = array('ts' => (int) $line['ts'], 'visitor' => $visitor, 'line' => $line, 'ua' => $ua, 'hit' => $hit, 'type' => $type, 'pkey' => $pkey);
            }
        }

        // 2. Visits.
        $visits = self::visits($hits);

        // 3. Dictionary ids for every text in the batch, in bulk.
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
        }
        foreach ($clicks as $c) {
            $texts[SEOProStats_Schema::DICT_SELECTOR][] = $c['selector'];
            $texts[SEOProStats_Schema::DICT_LABEL][]    = $c['label'];
            $texts[SEOProStats_Schema::DICT_TARGET][]   = $c['target'];
        }
        $ids = array();
        foreach ($texts as $kind => $values) {
            $ids[$kind] = SEOProStats_Dict::ids($kind, $values);
        }

        // 4. Write visits, then their facts.
        $session_ids = self::write_sessions($visits, $ids);
        $counts      = self::write_facts($visits, $session_ids, $ids);
        $done['pageviews'] += $counts[0];
        $done['events']    += $counts[1];
        $written            = self::write_clicks($clicks, $ids);
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
     * Group hits into visits: a hit joins its visitor's latest visit when
     * that visit's last hit was under VISIT_GAP earlier.
     *
     * @param array<int,array<string,mixed>> $hits Hits.
     * @return array<string,array<string,mixed>> Visit key (hex) => visit.
     */
    private static function visits(array $hits) {
        global $wpdb;
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
        $latest = array();
        $history = array(); // Refunds may arrive after a later same-day visit.
        if ($visitors) {
            foreach (array_chunk(array_keys($visitors), SEOProStats_Dict::CHUNK) as $chunk) {
                $d = implode(', ', array_fill(0, count($days), '%s'));
                $v = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its (day, visitor) index; fixed placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT LOWER(HEX(skey)) AS skey, LOWER(HEX(visitor)) AS visitor, started, ended, pageviews + events AS n FROM %i WHERE day IN ($d) AND visitor IN ($v)", array_merge(array(SEOProStats_Schema::table('sessions')), array_keys($days), $chunk)));
                foreach ((array) $rows as $row) {
                    $history[$row->visitor][] = array('skey' => $row->skey, 'started' => (int) $row->started, 'ended' => (int) $row->ended, 'n' => (int) $row->n, 'stored' => true);
                    if (!isset($latest[$row->visitor]) || (int) $row->ended > $latest[$row->visitor]['ended']) {
                        $latest[$row->visitor] = array('skey' => $row->skey, 'started' => (int) $row->started, 'ended' => (int) $row->ended, 'n' => (int) $row->n, 'stored' => true);
                    }
                }
            }
        }

        $visits = array();
        $open   = array(); // visitor => visit key
        foreach ($hits as $h) {
            $v    = $h['visitor'];
            $prev = isset($open[$v]) ? $visits[$open[$v]] : (isset($latest[$v]) ? $latest[$v] : null);
            $refund = !empty($h['line']['s']) && $h['type'] === 'e' && $h['hit']['n'] === 'Refund';
            if ($refund) {
                // Never create a visit for a refund (including after retention).
                $candidates = isset($history[$v]) ? $history[$v] : array();
                foreach ($visits as $visit_key => $candidate) {
                    if ($candidate['visitor'] === $v) {
                        $candidates[] = $candidate + array('skey' => $visit_key);
                    }
                }
                $prev = null;
                foreach ($candidates as $candidate) {
                    if ($h['ts'] >= $candidate['started'] && $h['ts'] <= $candidate['ended'] && ($prev === null || $candidate['started'] > $prev['started'])) {
                        $prev = $candidate;
                    }
                }
                if ($prev === null) {
                    continue;
                }
            }
            if ($prev !== null && $h['ts'] - $prev['ended'] < self::VISIT_GAP && $h['ts'] >= $prev['started'] - self::VISIT_GAP) {
                $key = $prev['skey'];
                if (!isset($visits[$key])) {
                    $visits[$key] = self::new_visit($h, $key, $prev['started'], $prev['n'], true);
                    $visits[$key]['ended'] = $prev['ended'];
                }
            } else {
                $key          = substr(hash('sha256', $v . '|' . $h['ts']), 0, 16);
                $visits[$key] = self::new_visit($h, $key, $h['ts'], 0, false);
            }
            if (!$refund) {
                $open[$v] = $key;
            }
            $visit    = &$visits[$key];
            $visit['ended'] = max($visit['ended'], $h['ts']);
            $visit['n']++;
            $fact            = self::fact($h, $visit['n']);
            $visit['login']  = max($visit['login'], $fact['login']);
            $visit['hits'][] = $fact;
            unset($visit);
        }
        return $visits;
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
        $props = array();
        if (isset($hit['d']) && is_array($hit['d'])) {
            foreach (array_slice($hit['d'], 0, 30, true) as $key => $value) {
                if (is_scalar($value) && $value !== '' && trim((string) $key) !== '') {
                    $props[substr(trim((string) $key), 0, 100)] = substr(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 0, 300);
                }
            }
        }
        $revenue  = 0;
        $currency = '';
        if (isset($hit['rv']['a'], $hit['rv']['c']) && is_numeric($hit['rv']['a']) && preg_match('/^[A-Za-z]{3}$/', (string) $hit['rv']['c'])) {
            $revenue  = (int) round((float) $hit['rv']['a'] * 100);
            $currency = strtoupper((string) $hit['rv']['c']);
        }
        // The page's context (pageviews only).
        $ctx    = $h['type'] === 'pv' && isset($hit['x']) && is_array($hit['x']) ? $hit['x'] : array();
        $flags  = 0;
        $search = '';
        if (!empty($ctx['n'])) {
            $flags = SEOProStats_Schema::PAGE_NOT_FOUND;
        } elseif (!empty($ctx['q'])) {
            $flags = SEOProStats_Schema::PAGE_SEARCH;
            if (isset($ctx['r']) && is_numeric($ctx['r']) && (int) $ctx['r'] === 0) {
                $flags |= SEOProStats_Schema::PAGE_NO_RESULTS;
            }
            $search = isset($ctx['s']) && is_string($ctx['s']) ? self::search_words($ctx['s']) : '';
        }
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
        );
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
        $words = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $words));
        $words = (string) preg_replace(array('/[^\s@]+@[^\s@]+/u', '/\+?\d(?:[\s().-]?\d){5,}/'), array('…@…', '#'), $words);
        $words = function_exists('mb_strtolower') ? mb_substr(mb_strtolower($words, 'UTF-8'), 0, 100, 'UTF-8') : substr(strtolower($words), 0, 100);
        // PHP 7.4 gives false, not '', for nothing left.
        return (string) $words;
    }

    /**
     * Insert new visits and extend continued ones; ids by visit key.
     *
     * @param array<string,array<string,mixed>> $visits Visits.
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
                $entry  = '';
                foreach ($v['hits'] as $h) {
                    if ($h['type'] === 'pv') {
                        $entry = $h['path'];
                        break;
                    }
                }
                $utm = $v['utm'];
                array_push(
                    $args,
                    (string) $key,
                    $v['visitor'],
                    $v['day'],
                    $v['started'],
                    $v['ended'],
                    self::id($ids, SEOProStats_Schema::DICT_PATH, $entry),
                    self::id($ids, SEOProStats_Schema::DICT_HOST, $v['ref_host']),
                    self::id($ids, SEOProStats_Schema::DICT_PATH, $v['ref_path']),
                    $v['channel'],
                    self::id($ids, SEOProStats_Schema::DICT_UTM, isset($utm['utm_source']) ? $utm['utm_source'] : ''),
                    self::id($ids, SEOProStats_Schema::DICT_UTM, isset($utm['utm_medium']) ? $utm['utm_medium'] : ''),
                    self::id($ids, SEOProStats_Schema::DICT_UTM, isset($utm['utm_campaign']) ? $utm['utm_campaign'] : ''),
                    self::id($ids, SEOProStats_Schema::DICT_UTM, isset($utm['utm_term']) ? $utm['utm_term'] : ''),
                    self::id($ids, SEOProStats_Schema::DICT_UTM, isset($utm['utm_content']) ? $utm['utm_content'] : ''),
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
            }
            // Continued visits keep their first-hit details; only the end
            // moves, and a login part-way through counts for the visit.
            $groups = implode(', ', array_fill(0, count($chunk), '(UNHEX(%s), UNHEX(%s), %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
            $wpdb->query($wpdb->prepare("INSERT INTO %i (skey, visitor, day, started, ended, entry_id, ref_host_id, ref_path_id, channel, utm_source_id, utm_medium_id, utm_campaign_id, utm_term_id, utm_content_id, country, lang_id, browser_id, browser_ver, os_id, os_ver, device, screen, login) VALUES $groups ON DUPLICATE KEY UPDATE ended = GREATEST(ended, VALUES(ended)), login = GREATEST(login, VALUES(login))", $args));

            $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
            $found = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(HEX(skey)) AS k FROM %i WHERE skey IN ($holders)", array_merge(array($table), array_map('strval', array_keys($chunk)))));
            foreach ((array) $found as $row) {
                $out[$row->k] = (int) $row->id;
            }
        }
        return $out;
    }

    /**
     * Insert pageviews, events and their properties.
     *
     * @param array<string,array<string,mixed>> $visits      Visits.
     * @param array<string,int>                 $session_ids Visit key => id.
     * @param array<int,array<string,int>>      $ids         Dictionary ids by kind.
     * @return array{0:int,1:int} Pageviews and events inserted.
     */
    private static function write_facts(array $visits, array $session_ids, array $ids) {
        global $wpdb;
        $pv     = array();
        $ev     = array();
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

        $pageviews = 0;
        $prop_rows = array();
        foreach (array_chunk($pv, self::BATCH) as $chunk) {
            $args = array(SEOProStats_Schema::table('pageviews'));
            foreach ($chunk as $h) {
                array_push($args, $h['pkey'], $h['session_id'], $h['ts'], $h['seq'], $h['path_id'], $h['flags'], self::id($ids, SEOProStats_Schema::DICT_SEARCH, $h['search']));
            }
            // A page-load id seen before (a resumed batch) is skipped.
            $groups = implode(', ', array_fill(0, count($chunk), '(UNHEX(%s), %d, %d, %d, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
            $pageviews += (int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (pkey, session_id, ts, seq, path_id, flags, search_id) VALUES $groups", $args));

            $with_props = array_filter($chunk, static function ($h) {
                return (bool) $h['props'];
            });
            if ($with_props) {
                $holders = implode(', ', array_fill(0, count($with_props), 'UNHEX(%s)'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
                $found = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(HEX(pkey)) AS k FROM %i WHERE pkey IN ($holders)", array_merge(array(SEOProStats_Schema::table('pageviews')), array_column($with_props, 'pkey'))));
                $by    = array();
                foreach ((array) $found as $row) {
                    $by[$row->k] = (int) $row->id;
                }
                foreach ($with_props as $h) {
                    if (isset($by[$h['pkey']])) {
                        $prop_rows = array_merge($prop_rows, self::prop_rows(SEOProStats_Schema::OWNER_PAGEVIEW, $by[$h['pkey']], $h, $ids));
                    }
                }
            }
        }

        // Events without a path: their pageview's, from this batch or stored.
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
        if ($missing) {
            $holders = implode(', ', array_fill(0, count($missing), 'UNHEX(%s)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its unique key; fixed placeholders.
            $found = $wpdb->get_results($wpdb->prepare("SELECT path_id, LOWER(HEX(pkey)) AS k FROM %i WHERE pkey IN ($holders)", array_merge(array(SEOProStats_Schema::table('pageviews')), array_map('strval', array_keys($missing)))));
            foreach ((array) $found as $row) {
                $page_of[$row->k] = (int) $row->path_id;
            }
        }

        $events = 0;
        foreach ($ev as $h) {
            if ($h['path_id'] === 0 && isset($page_of[$h['pkey']])) {
                $h['path_id'] = $page_of[$h['pkey']];
            }
            // One at a time: each event's id is needed for its properties,
            // and events are far fewer than pageviews.
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
            if ($ok) {
                $events++;
                if ($h['props']) {
                    $prop_rows = array_merge($prop_rows, self::prop_rows(SEOProStats_Schema::OWNER_EVENT, (int) $wpdb->insert_id, $h, $ids));
                }
            }
        }

        foreach (array_chunk($prop_rows, self::BATCH) as $chunk) {
            $args = array(SEOProStats_Schema::table('props'));
            foreach ($chunk as $row) {
                array_push($args, ...$row);
            }
            $prop_groups = implode(', ', array_fill(0, count($chunk), '(%d, %d, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $prop_groups holds only fixed placeholder groups, one per row.
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (owner, owner_id, key_id, value_id, ts) VALUES $prop_groups", $args));
        }
        return array($pageviews, $events);
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
        $holders = implode(', ', array_fill(0, count($eng), 'UNHEX(%s)'));
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
            $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
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
        $inserted = 0;
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $args = array(SEOProStats_Schema::table('clicks'));
            foreach ($chunk as $row) {
                array_push($args, ...$row);
            }
            $groups = implode(', ', array_fill(0, count($chunk), '(%d, %d, %d, %d, %d, %d, %d, %d, %d, %d)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
            $inserted += (int) $wpdb->query($wpdb->prepare("INSERT INTO %i (session_id, ts, seq, path_id, kind, selector_id, label_id, target_id, flags, fields) VALUES $groups", $args));
        }
        return $inserted;
    }

    /**
     * What the batch's pages show (post type, author, category), in the
     * pages table: one row per address, written again at most once an
     * hour. Live: the item a pageview names, looked up in WordPress and
     * kept only when its address is the page's. Demo: SEOProStats_Demo's.
     *
     * @param array<string,array<string,mixed>> $visits Visits.
     * @param array<int,array<string,int>>      $ids    Dictionary ids by kind.
     */
    private static function write_pages(array $visits, array $ids) {
        global $wpdb;
        $demo  = SEOProStats_Schema::set() === 'demo';
        $views = array(); // path id => [path, post id, time]
        foreach ($visits as $visit) {
            foreach ($visit['hits'] as $h) {
                if ($h['type'] !== 'pv' || $h['flags'] !== 0 || (!$demo && $h['post'] === 0)) {
                    continue;
                }
                $path_id = self::id($ids, SEOProStats_Schema::DICT_PATH, $h['path']);
                if ($path_id > 0 && (!isset($views[$path_id]) || $h['ts'] >= $views[$path_id][2])) {
                    $views[$path_id] = array($h['path'], $h['post'], $h['ts']);
                }
            }
        }
        if (!$views) {
            return;
        }
        $table = SEOProStats_Schema::table('pages');

        // Rows checked in the last hour for the same item stay as they are.
        $holders = implode(', ', array_fill(0, count($views), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; fixed placeholders.
        $known = $wpdb->get_results($wpdb->prepare("SELECT path_id, post_id, seen FROM %i WHERE path_id IN ($holders)", array_merge(array($table), array_keys($views))));
        foreach ((array) $known as $row) {
            $view = $views[(int) $row->path_id];
            if (($demo || (int) $row->post_id === $view[1]) && (int) $row->seen > $view[2] - HOUR_IN_SECONDS) {
                unset($views[(int) $row->path_id]);
            }
        }
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
        $link = get_permalink($post);
        if (!is_string($link)) {
            return null;
        }
        $want = self::split_url($link)['path'];
        // Kept query parameters (?lang=…) still show the item; plain
        // permalinks (?p=…) need theirs.
        $have = strpos($want, '?') === false ? explode('?', $path, 2)[0] : $path;
        if (untrailingslashit($want) !== untrailingslashit($have)) {
            return null;
        }
        $taxonomy = is_object_in_taxonomy($post->post_type, 'category') ? 'category' : '';
        if ($taxonomy === '') {
            foreach (get_object_taxonomies($post->post_type, 'objects') as $object) {
                if ($object->hierarchical && $object->public) {
                    $taxonomy = $object->name;
                    break;
                }
            }
        }
        $term = 0;
        if ($taxonomy !== '') {
            $terms = get_the_terms($post, $taxonomy);
            if (is_array($terms) && $terms) {
                $assigned = array_map('intval', wp_list_pluck($terms, 'term_id'));
                $term     = $assigned[0];
                foreach (array('_yoast_wpseo_primary_' . $taxonomy, 'rank_math_primary_' . $taxonomy) as $key) {
                    $primary = (int) get_post_meta($post->ID, $key, true);
                    if ($primary && in_array($primary, $assigned, true)) {
                        $term = $primary;
                        break;
                    }
                }
            }
        }
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
        $utm   = array();
        $click = '';
        $keep  = array();
        if ($query !== '') {
            parse_str($query, $params);
            foreach ($params as $key => $value) {
                $key = strtolower((string) $key);
                if (!is_string($value)) {
                    continue;
                }
                if (in_array($key, array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'), true)) {
                    $value = strtolower(trim(substr($value, 0, 200)));
                    if ($value !== '') {
                        $utm[$key] = $value;
                    }
                } elseif ($key === 'ref' || $key === 'source') {
                    if (!isset($utm['utm_source']) && trim($value) !== '') {
                        $utm['utm_source'] = strtolower(trim(substr($value, 0, 200)));
                    }
                } elseif (in_array($key, self::CLICK_IDS, true)) {
                    if ($click === '' && isset(SEOProStats_Channels::CLICK_IDS[$key])) {
                        $click = $key;
                    }
                } else {
                    $keep[$key] = $value;
                }
            }
        }
        ksort($keep);
        $path = '/' . ltrim(rawurldecode($path === '' ? '/' : $path), '/');
        return array(
            'path'  => $keep ? $path . '?' . http_build_query($keep) : $path,
            'utm'   => $utm,
            'click' => $click,
        );
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
