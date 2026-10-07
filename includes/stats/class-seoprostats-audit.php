<?php
/**
 * The content audit (Search → Audit): facts about each published post,
 * read from WordPress itself (its title, words, headings and images, and
 * its SEO plugin's title, description, robots rule and canonical
 * address), and findings from them weighed by the page's search figures,
 * so the ones that matter come first.
 *
 * Facts are read when a post is saved (at the end of that request, so the
 * SEO plugin's fields are saved too) and by the daily cron, at most BATCH
 * posts within BUDGET seconds, never on visitor pages: one page_facts row
 * per page. The cron reads posts with no facts, changed since, or read
 * more than STALE_DAYS ago, by the posts table's primary key from where
 * the last run stopped; every page is read again after the SEO plugin is
 * switched. A post's row goes when it is deleted or no longer published.
 *
 * Findings: title or description missing or long, the same title or
 * description as another page (by the hash keys), no H1 or several, a
 * thin page (few words, with impressions but no clicks), images without
 * alt text, and noindex or a canonical address elsewhere on a page with
 * search impressions. Lengths are of what was written: an SEO title or
 * description made of its plugin's variables counts as the post's title
 * or excerpt. Themes show the post title as the page's H1, so an H1 in the
 * text makes several.
 *
 * Reads: page_facts by its flags key (pages with a finding of their own),
 * duplicates by title_hash and desc_hash, and each page's search figures
 * from gsc_pages by the primary key. Design: docs/seo-loop.md → Content
 * audit from WordPress.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Audit {

    /** Progress, per data set (autoload off): cursor (post ID), plugin, since (read all again from), version (facts written), last (cron run). */
    const OPTION = 'seoprostats_audit';

    /** Posts read per cron run at most, and its seconds. */
    const BATCH  = 200;
    const BUDGET = 20;

    /** Posts looked at per read of the posts table, and read together. */
    const SCAN  = 1000;
    const CHUNK = 50;

    /** Facts older than this many days are read again. */
    const STALE_DAYS = 30;

    /** Longest title and description (characters), and words below which a page is short. */
    const TITLE_MAX       = 60;
    const DESCRIPTION_MAX = 160;
    const THIN_WORDS      = 300;

    /** Facts rows with a finding of their own read at most; duplicate groups read at most; pages named per duplicate. */
    const MAX_ROWS   = 5000;
    const MAX_GROUPS = 500;
    const SAME       = 5;

    /** Pages listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** Findings, most serious first. */
    const FINDINGS = array('noindex', 'canonical', 'thin', 'title_missing', 'title_duplicate', 'title_long', 'description_missing', 'description_duplicate', 'description_long', 'h1_none', 'h1_several', 'images_alt');

    /** The flags column: findings of the page alone, and short (thin when it has impressions and no clicks). */
    const FLAGS = array(
        'title_missing'       => 1,
        'title_long'          => 2,
        'description_missing' => 4,
        'description_long'    => 8,
        'h1_none'             => 16,
        'h1_several'          => 32,
        'images_alt'          => 64,
        'noindex'             => 128,
        'canonical'           => 256,
        'short'               => 512,
    );

    /**
     * Share of a page's expected clicks a finding puts at stake (the
     * decision queue's potential clicks); a page's are added, at most 1.
     */
    const SHARE = array(
        'noindex'               => 1.0,
        'canonical'             => 1.0,
        'thin'                  => 0.3,
        'title_missing'         => 0.2,
        'title_duplicate'       => 0.15,
        'title_long'            => 0.05,
        'description_missing'   => 0.1,
        'description_duplicate' => 0.1,
        'description_long'      => 0.05,
        'h1_none'               => 0.05,
        'h1_several'            => 0.02,
        'images_alt'            => 0.02,
    );

    /** A hash key for no text. */
    const NONE = '0000000000000000';

    /** @var array<int,bool> Posts saved or deleted in this request, read at its end. */
    private static $saved = array();

    // ------------------------------------------------------------------
    // Reading posts.

    /**
     * A post was saved or deleted, or its SEO fields changed: read it at
     * the end of the request (once), when every field is saved.
     *
     * @param int $post_id Post.
     */
    public static function saved($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        if (!self::$saved) {
            add_action('shutdown', array(__CLASS__, 'save_now'));
        }
        self::$saved[$post_id] = true;
    }

    /**
     * End of a request that saved posts: read the published ones, forget
     * the rest (deleted, or no longer published). Live data only.
     */
    public static function save_now() {
        $ids         = array_keys(self::$saved);
        self::$saved = array();
        $before      = SEOProStats_Schema::use_set('live');
        try {
            if (!SEOProStats_Schema::is_current()) {
                return;
            }
            self::load();
            $read = array();
            $gone = array();
            foreach ($ids as $post_id) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    $gone[] = $post_id;
                } elseif (SEOProStats_Changes::is_public($post)) {
                    if ($post->post_status === 'publish') {
                        $read[] = $post_id;
                    } else {
                        $gone[] = $post_id;
                    }
                }
            }
            // A request saving many posts (a bulk edit) leaves the rest to the daily cron.
            $wrote = $read ? self::read_posts(array_slice($read, 0, self::CHUNK)) : 0;
            $wrote += $gone ? self::forget($gone) : 0;
            if ($wrote) {
                self::touch();
            }
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Daily cron (and WP-CLI): read up to $limit posts whose facts are
     * missing, changed or old, within the time budget, from where the
     * last run stopped; back to the first post at the end. Live data only.
     *
     * @param int $limit  Posts read at most.
     * @param int $budget Seconds.
     * @return array{read:int,looked:int,done:bool} Posts read and looked at; done: the end of the posts was reached.
     */
    public static function batch($limit = self::BATCH, $budget = self::BUDGET) {
        global $wpdb;
        $out = array('read' => 0, 'looked' => 0, 'done' => false);
        if (SEOProStats_Schema::set() !== 'live' || !SEOProStats_Schema::is_current()) {
            return $out;
        }
        self::load();
        $start = microtime(true);
        $limit = max(1, (int) $limit);
        $state = self::state();
        $seo   = SEOProStats_Coverage::seo_plugin();
        if ($state['plugin'] !== $seo) {
            // Another SEO plugin's fields: every page is read again.
            $state['plugin'] = $seo;
            $state['since']  = time();
            $state['cursor'] = 0;
        }
        $types = self::post_types();
        $old   = max((int) $state['since'], time() - self::STALE_DAYS * DAY_IN_SECONDS);
        $stop  = !$types;
        while (!$stop) {
            $holders = implode(', ', array_fill(0, count($types), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WordPress's posts by their primary key, in its order, from the cursor; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_modified_gmt AS m FROM %i FORCE INDEX (`PRIMARY`) WHERE ID > %d AND post_status = 'publish' AND post_type IN ($holders) ORDER BY ID LIMIT %d", array_merge(array($wpdb->posts, (int) $state['cursor']), $types, array(self::SCAN))), ARRAY_A);
            $known   = self::known(array_map('intval', array_column($rows, 'ID')));
            $pending = array();
            $last    = (int) $state['cursor'];
            foreach ($rows as $row) {
                $post_id = (int) $row['ID'];
                ++$out['looked'];
                $was = isset($known[$post_id]) ? $known[$post_id] : null;
                if (!$was || $was['modified'] !== self::time($row['m']) || $was['checked'] < $old) {
                    $pending[] = $post_id;
                }
                $last = $post_id;
                if ($pending && (count($pending) >= self::CHUNK || $out['read'] + count($pending) >= $limit)) {
                    self::read_posts($pending);
                    $out['read']    += count($pending);
                    $pending         = array();
                    $state['cursor'] = $last;
                    if ($out['read'] >= $limit || !SEOProStats_Feature::more_time($start, $budget)) {
                        $stop = true;
                        break;
                    }
                }
            }
            if ($stop) {
                break;
            }
            if ($pending) {
                self::read_posts($pending);
                $out['read'] += count($pending);
            }
            if (count($rows) < self::SCAN) {
                // The end of the posts: from the first again next time.
                $state['cursor'] = 0;
                $out['done']     = true;
                break;
            }
            $state['cursor'] = $last;
            $stop            = !SEOProStats_Feature::more_time($start, $budget);
        }
        if ($out['read']) {
            $state['version'] = time();
        }
        $state['last'] = time();
        update_option(SEOProStats_Schema::option(self::OPTION), $state, false);
        return $out;
    }

    /**
     * Read posts' facts and write them, each on its page (its address
     * now); rows of the posts at other addresses go.
     *
     * @param int[] $post_ids Published posts.
     * @return int Posts written.
     */
    private static function read_posts(array $post_ids) {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (!$post_ids) {
            return 0;
        }
        _prime_post_caches($post_ids, false, true);
        $seo   = SEOProStats_Coverage::seo_fields($post_ids);
        $facts = array();
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            $link = $post instanceof WP_Post ? get_permalink($post) : false;
            if (!is_string($link) || $link === '') {
                continue;
            }
            $path = SEOProStats_Changes::path($link);
            $text = SEOProStats_Coverage::text_of_post($post_id, isset($seo[$post_id]) ? $seo[$post_id] : null);
            if ($text) {
                $facts[$path] = self::facts($text, $path) + array(
                    'post_id'  => $post_id,
                    'modified' => self::time($post->post_modified_gmt),
                );
            }
        }
        return self::write($facts);
    }

    /**
     * Write facts by page path (one row per page), replacing the posts'
     * rows at other addresses.
     *
     * @param array<string,array<string,mixed>> $facts From facts(), with post_id and modified, by path.
     * @return int Rows written.
     */
    public static function write(array $facts) {
        global $wpdb;
        if (!$facts) {
            return 0;
        }
        $ids   = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_keys($facts));
        $table = SEOProStats_Schema::table('page_facts');
        $now   = time();
        $args  = array();
        $keep  = array();
        $n     = 0;
        foreach ($facts as $path => $f) {
            $path_id = isset($ids[SEOProStats_Dict::clean((string) $path)]) ? (int) $ids[SEOProStats_Dict::clean((string) $path)] : 0;
            if (!$path_id) {
                continue;
            }
            $keep[(int) $f['post_id']][] = $path_id;
            array_push($args, $path_id, (int) $f['post_id'], $now, (int) $f['modified'], (int) $f['title_len'], (int) $f['seo_title_len'], (int) $f['desc_len'], (string) $f['title_hash'], (string) $f['desc_hash'], (int) $f['h1'], (int) $f['words'], (int) $f['images'], (int) $f['images_no_alt'], (int) $f['noindex'], (int) $f['canonical_away'], (int) $f['flags']);
            ++$n;
        }
        if (!$n) {
            return 0;
        }
        $groups = implode(', ', array_fill(0, $n, '(%d, %d, %d, %d, %d, %d, %d, UNHEX(%s), UNHEX(%s), %d, %d, %d, %d, %d, %d, %d)'));
        $update = array();
        foreach (array('post_id', 'checked', 'modified', 'title_len', 'seo_title_len', 'desc_len', 'title_hash', 'desc_hash', 'h1', 'words', 'images', 'images_no_alt', 'noindex', 'canonical_away', 'flags') as $col) {
            $update[] = "$col = VALUES($col)";
        }
        $update = implode(', ', $update);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $groups holds only placeholder groups and $update fixed column names.
        $wpdb->query($wpdb->prepare("INSERT INTO %i (path_id, post_id, checked, modified, title_len, seo_title_len, desc_len, title_hash, desc_hash, h1, words, images, images_no_alt, noindex, canonical_away, flags) VALUES $groups ON DUPLICATE KEY UPDATE $update", array_merge(array($table), $args)));

        // A post's rows at its old addresses go (a changed slug or parent).
        foreach ($keep as $post_id => $paths) {
            if (!$post_id) {
                continue;
            }
            $holders = implode(', ', array_fill(0, count($paths), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its post_id key; $holders holds only placeholders.
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d AND path_id NOT IN ($holders)", array_merge(array($table, (int) $post_id), $paths)));
        }
        return $n;
    }

    /**
     * Forget posts' facts.
     *
     * @param int[] $post_ids Posts.
     * @return int Rows deleted.
     */
    private static function forget(array $post_ids) {
        global $wpdb;
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (!$post_ids) {
            return 0;
        }
        $holders = implode(', ', array_fill(0, count($post_ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its post_id key; $holders holds only placeholders.
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE post_id IN ($holders)", array_merge(array(SEOProStats_Schema::table('page_facts')), $post_ids)));
    }

    /**
     * When posts' facts were read and their posts changed, by post.
     *
     * @param int[] $post_ids Posts.
     * @return array<int,array{modified:int,checked:int}>
     */
    private static function known(array $post_ids) {
        global $wpdb;
        if (!$post_ids) {
            return array();
        }
        $holders = implode(', ', array_fill(0, count($post_ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its post_id key; $holders holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT post_id, modified, checked FROM %i WHERE post_id IN ($holders)", array_merge(array(SEOProStats_Schema::table('page_facts')), $post_ids)), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $id = (int) $row['post_id'];
            // A post at two addresses: the oldest read counts.
            if (!isset($out[$id]) || (int) $row['checked'] < $out[$id]['checked']) {
                $out[$id] = array('modified' => (int) $row['modified'], 'checked' => (int) $row['checked']);
            }
        }
        return $out;
    }

    /**
     * A page's facts from its text (SEOProStats_Coverage::text_of_post(),
     * or SEOProStats_Demo::text()), with the flags of its own findings.
     *
     * @param array<string,mixed> $text Text.
     * @param string              $path The page's path.
     * @return array<string,int|string>
     */
    public static function facts(array $text, $path) {
        $html = (string) preg_replace('/\[\/?[a-zA-Z][^\[\]]*\]/', ' ', isset($text['content']) ? (string) $text['content'] : '');
        if (strlen($html) > SEOProStats_Coverage::MAX_TEXT) {
            $html = function_exists('mb_strcut') ? mb_strcut($html, 0, SEOProStats_Coverage::MAX_TEXT, 'UTF-8') : substr($html, 0, SEOProStats_Coverage::MAX_TEXT);
        }
        $title     = self::line(isset($text['title']) ? (string) $text['title'] : '');
        $seo_title = empty($text['seo_title_vars']) ? self::line(isset($text['seo_title']) ? (string) $text['seo_title'] : '') : '';
        $shown     = $seo_title !== '' ? $seo_title : $title;
        $desc      = self::line(isset($text['description']) ? (string) $text['description'] : '');
        if ($desc === '') {
            // SEO plugins describe a page by its excerpt when it has no description of its own.
            $desc = self::line(SEOProStats_Coverage::plain(isset($text['excerpt']) ? (string) $text['excerpt'] : ''));
        }
        $h1     = (int) preg_match_all('/<h1[\s>]/i', $html);
        $images = 0;
        $no_alt = 0;
        if (preg_match_all('/<img\b[^>]*>/i', $html, $found)) {
            foreach ($found[0] as $tag) {
                ++$images;
                if (!preg_match('/\salt\s*=\s*(?:"\s*[^"\s][^"]*"|\'\s*[^\'\s][^\']*\')/i', $tag)) {
                    ++$no_alt;
                }
            }
        }
        $words     = count(SEOProStats_Coverage::words(SEOProStats_Coverage::plain($html)));
        $noindex   = !empty($text['noindex']);
        $canonical = isset($text['canonical']) ? trim((string) $text['canonical']) : '';
        $away      = $canonical !== '' && self::away($canonical, (string) $path);

        $flags = 0;
        if ($shown === '') {
            $flags |= self::FLAGS['title_missing'];
        } elseif (self::length($shown) > self::TITLE_MAX) {
            $flags |= self::FLAGS['title_long'];
        }
        if ($desc === '') {
            $flags |= self::FLAGS['description_missing'];
        } elseif (self::length($desc) > self::DESCRIPTION_MAX) {
            $flags |= self::FLAGS['description_long'];
        }
        if ($h1 === 0 && $title === '') {
            $flags |= self::FLAGS['h1_none'];
        } elseif ($h1 > 1 || ($h1 === 1 && $title !== '')) {
            $flags |= self::FLAGS['h1_several'];
        }
        $flags |= $no_alt ? self::FLAGS['images_alt'] : 0;
        $flags |= $noindex ? self::FLAGS['noindex'] : 0;
        $flags |= $away ? self::FLAGS['canonical'] : 0;
        $flags |= $words < self::THIN_WORDS ? self::FLAGS['short'] : 0;

        return array(
            'title_len'      => min(65535, self::length($shown)),
            'seo_title_len'  => min(65535, self::length($seo_title)),
            'desc_len'       => min(65535, self::length($desc)),
            'title_hash'     => self::hash($shown),
            'desc_hash'      => self::hash($desc),
            'h1'             => min(255, $h1),
            'words'          => $words,
            'images'         => min(65535, $images),
            'images_no_alt'  => min(65535, $no_alt),
            'noindex'        => $noindex ? 1 : 0,
            'canonical_away' => $away ? 1 : 0,
            'flags'          => $flags,
        );
    }

    /**
     * Whether a canonical address is another page than the path.
     *
     * @param string $canonical Canonical address or path.
     * @param string $path      The page's path.
     * @return bool
     */
    private static function away($canonical, $path) {
        $host = wp_parse_url($canonical, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && strtolower($host) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return true;
        }
        return untrailingslashit(SEOProStats_Changes::path($canonical)) !== untrailingslashit((string) $path);
    }

    /**
     * The post types read: those with pages of their own, but not media.
     *
     * @return string[]
     */
    private static function post_types() {
        $types = array();
        // The same post types as SEOProStats_Changes::is_public().
        foreach (get_post_types() as $type) {
            if ($type !== 'attachment' && $type !== 'revision' && is_post_type_viewable($type)) {
                $types[] = (string) $type;
            }
        }
        return $types;
    }

    // ------------------------------------------------------------------
    // The report.

    /**
     * The audit report: pages with findings, most impressions first.
     *
     * @param array<string,mixed> $req     From SEOProStats_Query::request() (range, filters, limit, offset).
     * @param string              $engine  google or bing.
     * @param string              $finding Only pages with this finding; '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $finding = '') {
        self::load();
        if (SEOProStats_Schema::set() === 'live' && !self::state()['last']) {
            // Not read yet (the daily cron has not run since the update): a first few pages now.
            self::batch(self::CHUNK, 5);
        }
        $finding = (string) $finding;
        if ($finding !== '' && !in_array($finding, self::FINDINGS, true)) {
            /* translators: %s: list of findings */
            return new WP_Error('seoprostats_audit_finding', sprintf(__('The finding is one of: %s.', 'seoprostats'), implode(', ', self::FINDINGS)), array('status' => 400));
        }
        $engine = SEOProStats_Search::engine_name($engine);
        $live   = SEOProStats_Schema::set() === 'live';
        $answer = SEOProStats_Query::cached('audit', $req + array('engine' => $engine, 'finding' => $finding, 'imports' => SEOProStats_Search::version(), 'facts' => self::state()['version']), static function () use ($req, $engine, $finding) {
            return self::build($req, $engine, $finding);
        });
        $answer['connected'] = !$live || SEOProStats_Search::connected($engine);
        // Editor links depend on the viewer, so they are added outside the shared cache.
        foreach ($answer['rows'] as &$row) {
            $row = SEOProStats_Clicks::with_edit_url($row);
        }
        unset($row);
        return $answer;
    }

    /**
     * The shared part of the answer (cached).
     *
     * @param array<string,mixed> $req     Request.
     * @param string              $name    Engine name.
     * @param string              $finding Finding asked for, or ''.
     * @return array<string,mixed>
     */
    private static function build(array $req, $name, $finding) {
        $engine  = SEOProStats_Search::ENGINES[$name];
        $bounds  = SEOProStats_Search::bounds($engine);
        $range   = SEOProStats_Query::range($req);
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids($req['filters'], '', $ignored);
        $weekly  = in_array($name, SEOProStats_Search::WEEKLY, true);
        $full    = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds, $weekly) : null;
        $now     = $full ? SEOProStats_Opportunities::cut($full) : null;
        $days    = $now ? SEOProStats_Search::length($now) : 0;
        $limit   = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset  = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $rules   = array(
            'title_max'        => self::TITLE_MAX,
            'description_max'  => self::DESCRIPTION_MAX,
            'thin_words'       => self::THIN_WORDS,
            'thin_impressions' => max(10, $days),
            'batch'            => self::BATCH,
            'stale_days'       => self::STALE_DAYS,
        );

        $answer = array(
            'engine'  => $name,
            'engines' => SEOProStats_Search::engines(),
            'range'   => SEOProStats_Query::range_out($now ? $now : $range),
            'days'    => $days,
            'cut'     => $full && $now && SEOProStats_Search::length($full) > $days,
            'through' => $bounds['to'],
            'first'   => $bounds['from'],
            'ignored' => array_values(array_unique($ignored)),
            'finding' => $finding,
            'rules'   => $rules,
            'checked' => self::checked(),
            'plugin'  => SEOProStats_Schema::set() === 'demo' ? 'demo' : SEOProStats_Coverage::seo_plugin(),
            'counts'  => array_fill_keys(self::FINDINGS, 0),
            'pages'   => 0,
            'rows'    => array(),
            'total'   => 0,
            'more'    => false,
        );
        if ($pages !== null && !$pages) {
            return $answer;
        }

        $read = self::read($pages);
        $sums = $now ? SEOProStats_Opportunities::sums('gsc_pages', $engine, $now, $pages) : array();
        $zero = array('c' => 0, 'i' => 0, 'p' => 0);
        $list = array();
        foreach ($read['rows'] as $path_id => $row) {
            $sum   = isset($sums[(string) $path_id]) ? $sums[(string) $path_id] : $zero;
            $found = self::findings($row, isset($read['same'][$path_id]) ? $read['same'][$path_id] : array(), $sum, $rules);
            if (!$found) {
                continue;
            }
            ++$answer['pages'];
            foreach ($found as $one) {
                ++$answer['counts'][$one];
            }
            if ($finding === '' || in_array($finding, $found, true)) {
                $list[] = array('path_id' => (int) $path_id, 'row' => $row, 'findings' => $found, 'sum' => $sum);
            }
        }
        usort($list, static function ($a, $b) {
            return array($b['sum']['i'], $b['sum']['c'], count($b['findings']), $a['path_id']) <=> array($a['sum']['i'], $a['sum']['c'], count($a['findings']), $b['path_id']);
        });
        $answer['total'] = count($list);
        $answer['more']  = $offset + $limit < count($list);
        $answer['rows']  = self::rows(array_slice($list, $offset, $limit), $read);
        return $answer;
    }

    /**
     * Facts rows to judge: those with a finding of their own (by the flags
     * key) and those sharing a title or description (by the hash keys),
     * with the duplicate groups.
     *
     * @param int[]|null $pages Path ids, or null for every page.
     * @return array{rows:array<int,array<string,mixed>>,same:array<int,array<string,string>>,groups:array<string,int[]>}
     */
    private static function read($pages) {
        global $wpdb;
        $table = SEOProStats_Schema::table('page_facts');
        $cols  = 'path_id, post_id, checked, modified, title_len, seo_title_len, desc_len, LOWER(HEX(title_hash)) AS th, LOWER(HEX(desc_hash)) AS dh, h1, words, images, images_no_alt, noindex, canonical_away, flags';
        $only  = $pages === null ? null : array_flip(array_map('intval', $pages));
        $rows  = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its flags, title_hash, desc_hash and primary keys; $cols is a fixed column list, $key a fixed key name and $holders only placeholders.
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d", $table, self::MAX_ROWS), ARRAY_A) as $row) {
            $rows[(int) $row['path_id']] = $row;
        }

        // Pages sharing a title or description.
        $same   = array();
        $groups = array();
        foreach (array('title' => 'title_hash', 'description' => 'desc_hash') as $what => $key) {
            $hashes = (array) $wpdb->get_col($wpdb->prepare("SELECT LOWER(HEX($key)) FROM %i FORCE INDEX (`$key`) WHERE $key > UNHEX(%s) GROUP BY $key HAVING COUNT(*) > 1 LIMIT %d", $table, self::NONE, self::MAX_GROUPS));
            if (!$hashes) {
                continue;
            }
            $holders = implode(', ', array_fill(0, count($hashes), 'UNHEX(%s)'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT path_id, LOWER(HEX($key)) AS h FROM %i FORCE INDEX (`$key`) WHERE $key IN ($holders)", array_merge(array($table), array_map('strval', $hashes))), ARRAY_A) as $row) {
                $path_id = (int) $row['path_id'];
                if ($only !== null && !isset($only[$path_id])) {
                    continue;
                }
                $same[$path_id][$what]            = (string) $row['h'];
                $groups[$what . ':' . $row['h']][] = $path_id;
            }
        }
        $need = array_values(array_diff(array_keys($same), array_keys($rows)));
        foreach (array_chunk($need, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE path_id IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A) as $row) {
                $rows[(int) $row['path_id']] = $row;
            }
        }
        // phpcs:enable
        if ($only !== null) {
            $rows = array_intersect_key($rows, $only);
        }
        // A group needs two pages left after the page filter.
        foreach ($same as $path_id => $both) {
            foreach ($both as $what => $hash) {
                if (count($groups[$what . ':' . $hash]) < 2) {
                    unset($same[$path_id][$what]);
                }
            }
        }
        return array('rows' => $rows, 'same' => $same, 'groups' => $groups);
    }

    /**
     * A page's findings, most serious first.
     *
     * @param array<string,mixed>     $row   Facts row.
     * @param array<string,string>    $same  title and description: the hash it shares.
     * @param array{c:int,i:int,p:int} $sum  Its search sums.
     * @param array<string,int>       $rules Rules.
     * @return string[]
     */
    private static function findings(array $row, array $same, array $sum, array $rules) {
        $flags = (int) $row['flags'];
        $has   = static function ($name) use ($flags) {
            return (bool) ($flags & self::FLAGS[$name]);
        };
        $on = array(
            'noindex'               => $has('noindex') && $sum['i'] > 0,
            'canonical'             => $has('canonical') && $sum['i'] > 0,
            'thin'                  => $has('short') && $sum['i'] >= (int) $rules['thin_impressions'] && $sum['c'] === 0,
            'title_missing'         => $has('title_missing'),
            'title_duplicate'       => isset($same['title']),
            'title_long'            => $has('title_long'),
            'description_missing'   => $has('description_missing'),
            'description_duplicate' => isset($same['description']),
            'description_long'      => $has('description_long'),
            'h1_none'               => $has('h1_none'),
            'h1_several'            => $has('h1_several'),
            'images_alt'            => $has('images_alt'),
        );
        return array_keys(array_filter($on));
    }

    /**
     * Pages as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $list The pages shown.
     * @param array<string,mixed>            $read From read().
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $list, array $read) {
        $ids = array_column($list, 'path_id');
        foreach ($list as $item) {
            foreach (array('title', 'description') as $what) {
                if (isset($read['same'][$item['path_id']][$what])) {
                    $ids = array_merge($ids, array_slice($read['groups'][$what . ':' . $read['same'][$item['path_id']][$what]], 0, self::SAME + 1));
                }
            }
        }
        $text = SEOProStats_Query::texts(array_unique($ids));
        $live = SEOProStats_Schema::set() === 'live';
        $out  = array();
        foreach ($list as $item) {
            $row  = $item['row'];
            $id   = (int) $item['path_id'];
            $path = isset($text[$id]) ? (string) $text[$id] : '';
            $same = array('title' => array(), 'description' => array());
            foreach ($same as $what => $paths) {
                if (!isset($read['same'][$id][$what])) {
                    continue;
                }
                foreach ($read['groups'][$what . ':' . $read['same'][$id][$what]] as $other) {
                    if ($other !== $id && isset($text[$other]) && count($same[$what]) < self::SAME) {
                        $same[$what][] = (string) $text[$other];
                    }
                }
            }
            $out[] = array(
                'path_id'  => $id,
                'path'     => $path,
                'url'      => self::url($path),
                // Demo posts are not the site's.
                'post_id'  => $live ? (int) $row['post_id'] : 0,
                'edit_url' => null,
            ) + SEOProStats_Search::metrics($item['sum']['c'], $item['sum']['i'], $item['sum']['p']) + array(
                'findings'         => $item['findings'],
                'facts'            => array(
                    'title_length'       => (int) $row['title_len'],
                    'seo_title_length'   => (int) $row['seo_title_len'],
                    'description_length' => (int) $row['desc_len'],
                    'h1'                 => (int) $row['h1'],
                    'words'              => (int) $row['words'],
                    'images'             => (int) $row['images'],
                    'images_no_alt'      => (int) $row['images_no_alt'],
                    'noindex'            => (bool) (int) $row['noindex'],
                    'canonical_away'     => (bool) (int) $row['canonical_away'],
                    'modified'           => (int) $row['modified'] ? gmdate('c', (int) $row['modified']) : null,
                    'checked'            => gmdate('c', (int) $row['checked']),
                ),
                'same_title'       => $same['title'],
                'same_description' => $same['description'],
            );
        }
        return $out;
    }

    /**
     * How many pages have facts, and when the oldest and newest were read.
     *
     * @return array{pages:int,oldest:string|null,newest:string|null}
     */
    private static function checked() {
        global $wpdb;
        $table = SEOProStats_Schema::table('page_facts');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, by its post_id key and the ends of its checked key.
        $pages = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`post_id`) WHERE post_id > 0', $table));
        $ends  = $wpdb->get_row($wpdb->prepare('SELECT MIN(checked) AS o, MAX(checked) AS n FROM %i', $table), ARRAY_A);
        // phpcs:enable
        return array(
            'pages'  => $pages,
            'oldest' => is_array($ends) && (int) $ends['o'] ? gmdate('c', (int) $ends['o']) : null,
            'newest' => is_array($ends) && (int) $ends['n'] ? gmdate('c', (int) $ends['n']) : null,
        );
    }

    // ------------------------------------------------------------------
    // The decision queue's words.

    /**
     * Share of a page's expected clicks its findings put at stake.
     *
     * @param string[] $findings Findings.
     * @return float 0–1.
     */
    public static function share(array $findings) {
        $sum = 0.0;
        foreach ($findings as $finding) {
            $sum += isset(self::SHARE[$finding]) ? self::SHARE[$finding] : 0.0;
        }
        return min(1.0, $sum);
    }

    /**
     * A finding in a few words, for a sentence.
     *
     * @param string              $finding Finding.
     * @param array<string,mixed> $row     The report's row.
     * @return string
     */
    public static function phrase($finding, array $row) {
        $facts = isset($row['facts']) ? (array) $row['facts'] : array();
        $num   = static function ($key) use ($facts) {
            return number_format_i18n(isset($facts[$key]) ? (int) $facts[$key] : 0);
        };
        switch ($finding) {
            case 'noindex':
                return __('it asks search engines not to index it', 'seoprostats');
            case 'canonical':
                return __('its canonical address is another page', 'seoprostats');
            case 'thin':
                /* translators: %s: number of words */
                return sprintf(__('only %s words and no clicks', 'seoprostats'), $num('words'));
            case 'title_missing':
                return __('no title', 'seoprostats');
            case 'title_duplicate':
                /* translators: %s: number of pages */
                return sprintf(__('the same title as %s other pages', 'seoprostats'), number_format_i18n(max(1, count((array) $row['same_title']))));
            case 'title_long':
                /* translators: %s: number of characters */
                return sprintf(__('a %s-character title', 'seoprostats'), $num('title_length'));
            case 'description_missing':
                return __('no description', 'seoprostats');
            case 'description_duplicate':
                /* translators: %s: number of pages */
                return sprintf(__('the same description as %s other pages', 'seoprostats'), number_format_i18n(max(1, count((array) $row['same_description']))));
            case 'description_long':
                /* translators: %s: number of characters */
                return sprintf(__('a %s-character description', 'seoprostats'), $num('description_length'));
            case 'h1_none':
                return __('no H1', 'seoprostats');
            case 'h1_several':
                return __('several H1s', 'seoprostats');
            case 'images_alt':
                /* translators: %s: number of images */
                return sprintf(__('%s images without alt text', 'seoprostats'), $num('images_no_alt'));
        }
        return (string) $finding;
    }

    /**
     * What to do about a page's findings.
     *
     * @param string[] $findings Findings.
     * @return string
     */
    public static function todo(array $findings) {
        $do = array(
            'noindex'               => __('Let search engines index the page, or remove it from search on purpose.', 'seoprostats'),
            'canonical'             => __('Point its canonical address at itself, unless the other page is meant to rank.', 'seoprostats'),
            'thin'                  => __('Answer the searches it shows for in more depth.', 'seoprostats'),
            'title_missing'         => __('Write a title.', 'seoprostats'),
            'title_duplicate'       => __('Give it a title of its own.', 'seoprostats'),
            /* translators: %d: most characters */
            'title_long'            => sprintf(__('Shorten the title to %d characters.', 'seoprostats'), self::TITLE_MAX),
            /* translators: %d: most characters */
            'description_missing'   => sprintf(__('Write a description of up to %d characters.', 'seoprostats'), self::DESCRIPTION_MAX),
            'description_duplicate' => __('Give it a description of its own.', 'seoprostats'),
            /* translators: %d: most characters */
            'description_long'      => sprintf(__('Shorten the description to %d characters.', 'seoprostats'), self::DESCRIPTION_MAX),
            'h1_none'               => __('Give it one main heading.', 'seoprostats'),
            'h1_several'            => __('Keep one H1 (the title) and make the others H2.', 'seoprostats'),
            'images_alt'            => __('Describe its images in their alt text.', 'seoprostats'),
        );
        $out = array();
        foreach ($findings as $finding) {
            if (isset($do[$finding])) {
                $out[] = $do[$finding];
            }
        }
        return implode(' ', $out);
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * Load the classes the audit uses.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-coverage.php';
        require_once __DIR__ . '/class-seoprostats-opportunities.php';
        require_once __DIR__ . '/class-seoprostats-demo.php';
    }

    /**
     * Progress of the current data set.
     *
     * @return array{cursor:int,plugin:string,since:int,version:int,last:int}
     */
    public static function state() {
        $state = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        return array(
            'cursor'  => isset($state['cursor']) ? (int) $state['cursor'] : 0,
            'plugin'  => isset($state['plugin']) ? (string) $state['plugin'] : '',
            'since'   => isset($state['since']) ? (int) $state['since'] : 0,
            'version' => isset($state['version']) ? (int) $state['version'] : 0,
            'last'    => isset($state['last']) ? (int) $state['last'] : 0,
        );
    }

    /**
     * Note that facts were written, so cached reports are made again.
     */
    public static function touch() {
        $state            = self::state();
        $state['version'] = time();
        update_option(SEOProStats_Schema::option(self::OPTION), $state, false);
    }

    /**
     * Delete the current data set's progress (demo removal, uninstall).
     */
    public static function reset() {
        delete_option(SEOProStats_Schema::option(self::OPTION));
    }

    /**
     * The 8-byte key of a title or description, as 16 hex digits: its
     * words in lower case. NONE for no text.
     *
     * @param string $text Text.
     * @return string
     */
    private static function hash($text) {
        $key = function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
        $key = trim((string) preg_replace('/\s+/u', ' ', $key));
        return $key === '' ? self::NONE : SEOProStats_Dict::hash($key);
    }

    /**
     * A text on one line: tags out, entities decoded, spaces joined.
     *
     * @param string $text Text.
     * @return string
     */
    private static function line($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Characters of a text.
     *
     * @param string $text Text.
     * @return int
     */
    private static function length($text) {
        return function_exists('mb_strlen') ? (int) mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }

    /**
     * A GMT date and time (Y-m-d H:i:s) as Unix seconds; 0 for none.
     *
     * @param string $gmt Date and time.
     * @return int
     */
    private static function time($gmt) {
        $gmt = (string) $gmt;
        if ($gmt === '' || strpos($gmt, '0000-00-00') === 0) {
            return 0;
        }
        $ts = strtotime($gmt . ' UTC');
        return $ts ? (int) $ts : 0;
    }

    /**
     * A page's address on this site.
     *
     * @param string $path Path.
     * @return string
     */
    private static function url($path) {
        $info = $path !== '' && $path[0] === '/' ? wp_parse_url(home_url('/')) : null;
        if (!is_array($info) || !isset($info['scheme'], $info['host'])) {
            return '';
        }
        return esc_url_raw($info['scheme'] . '://' . $info['host'] . (isset($info['port']) ? ':' . $info['port'] : '') . $path);
    }
}
