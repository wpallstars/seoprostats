<?php
/**
 * Search Console site data: the sitemaps submitted for the property and
 * Google's URL Inspection of the site's pages (the version in Google's
 * index, not a live test).
 *
 * Sitemaps: read once a day by the hourly import job while Search
 * Console is connected (one request), kept in the OPTION: each sitemap's
 * path, type, last submitted and downloaded, pending, errors, warnings
 * and contents (Google's indexed count is deprecated and not kept).
 * Errors or warnings, a sitemap Google has not downloaded for STALE_DAYS
 * days, or the site's own sitemap index not submitted at all are
 * problems: the Indexation report's Sitemaps card shows them and the
 * decision queue lists each (SEOProStats_Queue).
 *
 * URL Inspection: from cron only (and WP-CLI), never on a visitor page,
 * at most the inspections setting a day (Google's own day, Pacific
 * time; Google allows 2,000 a day per property), within BUDGET seconds a
 * run. Order: the Indexation lists' pages (never shown first), then the
 * pages with search impressions in the newest 28 days whose inspection is
 * oldest (never inspected first, most impressions first); each page again
 * after RECHECK_DAYS days. One inspections row per page, Google's values
 * as codes and dictionary ids; findings in its flags (FLAGS): Google
 * chose another canonical, blocked by robots.txt, crawled but not
 * indexed, rich result errors. A page whose verdict changes is a change
 * (index_status) on the timeline.
 *
 * Reads: inspections by its primary key (pages listed), its checked,
 * verdict_checked and flags keys; gsc_pages by its primary key's
 * (engine, day) prefix. Design: docs/seo-loop.md → Indexation.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facade shared by cron, CLI, reports and demo data for one inspection lifecycle.
 * Keeping these entry points together preserves their quota, lock and storage contract.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") The facade coordinates the complete inspection lifecycle; procedural steps are private helpers.
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") Normalization and report field maps belong to the same stored inspection contract.
 * @SuppressWarnings("PHPMD.TooManyMethods") Named private steps keep lifecycle procedures independently readable.
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") Existing cron, CLI, report and demo entry points are compatibility contracts.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") The facade integrates existing dictionary, search, indexation and timeline services.
 */
final class SEOProStats_Inspections { // NOSONAR: a compatibility facade for cron, CLI, reports and demo data; private helpers decompose its procedures.

    /** Remove the optional www host prefix when comparing site addresses. */
    private const WWW_PREFIX = '/^www\./i';

    /** Search Console's source key (SEOProStats_Connections::SOURCES). */
    const SOURCE = 'search-console';

    /** Google's verdicts: code => value (0: unspecified). Codes never change meaning. */
    const VERDICTS = array(1 => 'PASS', 2 => 'PARTIAL', 3 => 'FAIL', 4 => 'NEUTRAL');

    /** Whether the page blocks indexing (indexingState). */
    const INDEXING = array(1 => 'INDEXING_ALLOWED', 2 => 'BLOCKED_BY_META_TAG', 3 => 'BLOCKED_BY_HTTP_HEADER', 4 => 'BLOCKED_BY_ROBOTS_TXT');

    /** Whether robots.txt blocks Google (robotsTxtState). */
    const ROBOTS = array(1 => 'ALLOWED', 2 => 'DISALLOWED');

    /** Whether Google could fetch the page (pageFetchState). */
    const FETCH = array(
        1  => 'SUCCESSFUL',
        2  => 'SOFT_404',
        3  => 'BLOCKED_ROBOTS_TXT',
        4  => 'NOT_FOUND',
        5  => 'ACCESS_DENIED',
        6  => 'SERVER_ERROR',
        7  => 'REDIRECT_ERROR',
        8  => 'ACCESS_FORBIDDEN',
        9  => 'BLOCKED_4XX',
        10 => 'INTERNAL_CRAWL_ERROR',
        11 => 'INVALID_URL',
    );

    /** Google's primary crawler (crawledAs). */
    const CRAWLED_AS = array(1 => 'DESKTOP', 2 => 'MOBILE');

    /** Findings, most serious first: bit in the flags column. */
    const FLAGS = array(
        'robots_blocked'   => 1,
        'not_indexed'      => 2,
        'google_canonical' => 4,
        'rich_errors'      => 8,
    );

    /** Sitemap problems (queue findings are sitemap_ and the problem). */
    const SITEMAP_PROBLEMS = array('errors', 'missing', 'stale', 'warnings');

    /** Share of a typical shown page's clicks a sitemap problem puts at stake (the decision queue's potential clicks). */
    const SITEMAP_SHARE = array('errors' => 1.0, 'missing' => 1.0, 'stale' => 0.5, 'warnings' => 0.2);

    /** Progress, per data set (autoload off): sitemaps, sitemaps_read, sitemaps_error, day, used, last, error, version. */
    const OPTION = 'seoprostats_inspections';

    /** One run at a time (autoload off): the time it started. */
    const LOCK_OPTION = 'seoprostats_inspections_lock';

    /** Seconds after which a run's lock counts as left behind. */
    const LOCK_TIME = 10 * MINUTE_IN_SECONDS;

    /** Seconds per cron run. */
    const BUDGET = 20;

    /** Days before a page is inspected again. */
    const RECHECK_DAYS = 14;

    /** Days without Google downloading a sitemap before it is a problem. */
    const STALE_DAYS = 7;

    /** Pages with search impressions read for the order, most impressions first; days of impressions. */
    const TRAFFIC_PAGES = 2000;
    const TRAFFIC_DAYS  = 28;

    /** Sitemaps kept at most; rich result types, issues, sitemaps and referring addresses kept per page. */
    const MAX_SITEMAPS = 100;
    const MAX_LIST     = 5;

    /** Rows read at most per report, and per IN list. */
    const MAX_ROWS = 5000;
    const CHUNK    = 500;

    /** Rows listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** The sitemap index of each SEO plugin, under the home address (WordPress's own: get_sitemap_url()). */
    const PLUGIN_SITEMAPS = array(
        'yoast'     => '/sitemap_index.xml',
        'rank-math' => '/sitemap_index.xml',
        'seopress'  => '/sitemaps.xml',
        'aioseo'    => '/sitemap.xml',
    );

    // ------------------------------------------------------------------
    // Cron.

    /**
     * Hourly import job, while Search Console is connected: the sitemaps
     * once a day, then inspections within the daily cap and BUDGET.
     * Live data only.
     */
    public static function cron() {
        if (SEOProStats_Schema::set() !== 'live' || !SEOProStats_Schema::is_current() || !self::lock()) {
            return;
        }
        try {
            $start = microtime(true);
            if (self::state()['sitemaps_read'] < time() - DAY_IN_SECONDS + 10 * MINUTE_IN_SECONDS) {
                self::read_sitemaps();
            }
            if (self::daily() > 0) {
                self::inspect_due(self::BUDGET, $start);
            }
        } finally {
            self::unlock();
        }
    }

    /**
     * Read the property's sitemaps from Search Console and keep them.
     *
     * @return array{sitemaps:int}|WP_Error
     */
    public static function read_sitemaps() {
        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        list($class, $token, $property) = $ready;
        $found = $class::sitemaps($token, $property);
        if (is_wp_error($found)) {
            self::save(array('sitemaps_read' => time(), 'sitemaps_error' => $found->get_error_message()));
            return $found;
        }
        $rows = array();
        foreach (array_slice($found, 0, self::MAX_SITEMAPS) as $one) {
            $rows[] = self::sitemap_row($one);
        }
        self::write_sitemaps($rows);
        return array('sitemaps' => count($rows));
    }

    /**
     * Keep sitemaps as read (also the demo's).
     *
     * @param array<int,array<string,mixed>> $rows From sitemap_row().
     * @param int                            $read When they were read.
     */
    public static function write_sitemaps(array $rows, $read = 0) {
        self::save(array(
            'sitemaps'       => array_values($rows),
            'sitemaps_read'  => $read ? (int) $read : time(),
            'sitemaps_error' => null,
            'version'        => time(),
        ));
    }

    /**
     * One sitemap as Google gives it, as kept.
     *
     * @param array<string,mixed> $one WmxSitemap.
     * @return array<string,mixed>
     */
    public static function sitemap_row(array $one) {
        return array(
            'path'       => isset($one['path']) ? esc_url_raw((string) $one['path']) : '',
            'type'       => isset($one['type']) ? sanitize_key((string) $one['type']) : '',
            'index'      => !empty($one['isSitemapsIndex']),
            'pending'    => !empty($one['isPending']),
            'submitted'  => self::sitemap_time($one, 'lastSubmitted'),
            'downloaded' => self::sitemap_time($one, 'lastDownloaded'),
            'errors'     => self::integer_field($one, 'errors'),
            'warnings'   => self::integer_field($one, 'warnings'),
            'contents'   => self::sitemap_contents($one),
        );
    }

    /**
     * Keep submitted counts only; Google's indexed count is deprecated.
     *
     * @param array<string,mixed> $one Sitemap response.
     * @return array<int,array{type:string,submitted:int}>
     */
    private static function sitemap_contents(array $one) {
        $contents = array();
        foreach (self::array_field($one, 'contents') as $content) {
            if (is_array($content)) {
                // indexed is deprecated: not kept.
                $contents[] = array(
                    'type'      => isset($content['type']) ? sanitize_key((string) $content['type']) : '',
                    'submitted' => isset($content['submitted']) ? (int) $content['submitted'] : 0,
                );
            }
        }
        return $contents;
    }

    /**
     * An optional whole-number field from a remote response.
     *
     * @param array<string,mixed> $one Response.
     * @param string              $key Field.
     * @return int
     */
    private static function integer_field(array $one, $key) {
        return isset($one[$key]) ? (int) $one[$key] : 0;
    }

    /**
     * An optional list field from a remote response.
     *
     * @param array<string,mixed> $one Response.
     * @param string              $key Field.
     * @return array<mixed>
     */
    private static function array_field(array $one, $key) {
        return isset($one[$key]) && is_array($one[$key]) ? $one[$key] : array();
    }

    /**
     * Convert an optional sitemap timestamp, including Google's empty values.
     *
     * @param array<string,mixed> $one Sitemap response.
     * @param string $key Timestamp field.
     * @return int
     */
    private static function sitemap_time(array $one, $key) {
        $ts = isset($one[$key]) ? strtotime((string) $one[$key]) : false;
        return $ts ? (int) $ts : 0;
    }

    /**
     * Inspect the pages due (or those given), within the daily cap and a
     * time budget. Stops at Google's first error that is not about one
     * address (quota, access, server), and records it.
     *
     * @param int                     $budget Seconds (0: no limit, for WP-CLI).
     * @param float|null              $start  When the run began (microtime(true)); null now.
     * @param array<int,string>|null  $pages  Path id => path to inspect now; null for those due.
     * @return array{inspected:int,failed:int,left:int,daily:int,used:int,more:bool,error:string|null}|WP_Error
     */
    public static function inspect_due($budget = self::BUDGET, $start = null, $pages = null) {
        $start = $start === null ? microtime(true) : (float) $start;
        $ready = self::ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        list($class, $token, $property) = $ready;
        $daily = self::daily();
        $used  = self::used();
        $out   = array('inspected' => 0, 'failed' => 0, 'left' => max(0, $daily - $used), 'daily' => $daily, 'used' => $used, 'more' => false, 'error' => null);
        if ($out['left'] < 1) {
            $out['more'] = true;
            return $out;
        }
        $pages = $pages === null ? self::due($out['left']) : array_slice($pages, 0, $out['left'], true);
        return self::inspect_pages($pages, $class, $token, $property, $budget, $start, $out);
    }

    /**
     * Inspect the selected pages, counting each attempt before its remote request.
     *
     * @param array<int,string> $pages Selected paths.
     * @param string $class Source class.
     * @param string $token Access token.
     * @param string $property Search property.
     * @param int $budget Seconds available.
     * @param float $start Run start.
     * @param array{inspected:int,failed:int,left:int,daily:int,used:int,more:bool,error:string|null} $out Progress.
     * @return array{inspected:int,failed:int,left:int,daily:int,used:int,more:bool,error:string|null}
     */
    private static function inspect_pages(array $pages, $class, $token, $property, $budget, $start, array $out) {
        $done  = 0;
        foreach ($pages as $path_id => $path) {
            if (self::budget_expired($done, $budget, $start)) {
                break;
            }
            if (self::used() >= $out['daily']) {
                break;
            }
            ++$done;
            self::count_one();
            $result = self::inspect_path($class, $token, $property, (string) $path);
            if (!self::keep_inspection((int) $path_id, (string) $path, $result, $out)) {
                break;
            }
        }
        $out['used'] = self::used();
        $out['left'] = max(0, $out['daily'] - $out['used']);
        $out['more'] = $done < count($pages) || ($pages && $out['left'] < 1);
        if ($out['error'] === null) {
            self::save(array('last' => time(), 'error' => null, 'error_at' => null, 'version' => time()));
        }
        return $out;
    }

    /**
     * Check the budget only after at least one attempt.
     *
     * @param int $done Attempts made.
     * @param int $budget Seconds available.
     * @param float $start Run start.
     * @return bool
     */
    private static function budget_expired($done, $budget, $start) {
        return $done > 0 && $budget > 0 && !SEOProStats_Feature::more_time($start, $budget);
    }

    /**
     * Ask Google only for a valid local path.
     *
     * @param string $class Source class.
     * @param string $token Access token.
     * @param string $property Search property.
     * @param string $path Page path.
     * @return array<string,mixed>|WP_Error
     */
    private static function inspect_path($class, $token, $property, $path) {
        $url = self::url($path);
        return $url === '' ? new WP_Error('seoprostats_inspect_url', __('Not an address on this site.', 'seoprostats'), array('status' => 400)) : $class::inspect($token, $property, $url);
    }

    /**
     * Store page-specific failures; stop on quota, access or server failures.
     *
     * @param int $path_id Path id.
     * @param string $path Page path.
     * @param array<string,mixed>|WP_Error $result Inspection response.
     * @param array{inspected:int,failed:int,left:int,daily:int,used:int,more:bool,error:string|null} $out Run counters, updated in place.
     * @param-out array{inspected:int,failed:int,left:int,daily:int,used:int,more:bool,error:string|null} $out
     * @return bool Whether to continue.
     */
    private static function keep_inspection($path_id, $path, $result, array &$out) {
        if (!is_wp_error($result)) {
            self::store($path_id, $path, $result, time());
            ++$out['inspected'];
            return true;
        }
        $data   = $result->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
        if ($status === 400 || $status === 404) {
            self::store($path_id, $path, array(), time(), $result->get_error_message());
            ++$out['failed'];
            return true;
        }
        $out['error'] = $result->get_error_message();
        self::save(array('last' => time(), 'error' => $out['error'], 'error_at' => time(), 'version' => time()));
        return false;
    }

    /**
     * Pages to inspect, in order, at most $limit: the Indexation lists'
     * pages, then pages with search impressions, those not inspected in
     * RECHECK_DAYS days only.
     *
     * @param int $limit Most pages.
     * @return array<int,string> Path id => path.
     */
    public static function due($limit) {
        self::load();
        $limit = max(0, (int) $limit);
        if (!$limit) {
            return array();
        }
        $listed  = self::listed_pages();
        $traffic = self::traffic();
        $checked = self::checked_of(array_merge(array_keys($listed), array_keys($traffic)));
        $old     = time() - self::RECHECK_DAYS * DAY_IN_SECONDS;
        $out     = array();
        foreach ($listed as $path_id => $path) {
            if (self::is_due($checked, $path_id, $old)) {
                $out[$path_id] = $path;
            }
        }
        $need = array_slice(self::traffic_due($traffic, $checked, $out, $old), 0, max(0, $limit - count($out)));
        $text = SEOProStats_Query::texts($need);
        foreach ($need as $path_id) {
            $path = isset($text[$path_id]) ? (string) $text[$path_id] : '';
            if ($path !== '' && $path[0] === '/') {
                $out[$path_id] = $path;
            }
        }
        return array_slice($out, 0, $limit, true);
    }

    /**
     * The Indexation lists' pages, in list order, each once.
     *
     * @return array<int,string> Path id => path.
     */
    private static function listed_pages() {
        $listed = array();
        $req    = SEOProStats_Query::request(array('limit' => SEOProStats_Indexation::MAX_LIMIT));
        if (is_wp_error($req)) {
            return $listed;
        }
        foreach (SEOProStats_Indexation::KINDS as $kind) {
            $answer = SEOProStats_Indexation::report((array) $req, 'google', $kind);
            $rows   = is_wp_error($answer) ? array() : $answer['rows'];
            foreach ($rows as $row) {
                if ((string) $row['path'] !== '' && !isset($listed[(int) $row['path_id']])) {
                    $listed[(int) $row['path_id']] = (string) $row['path'];
                }
            }
        }
        return $listed;
    }

    /**
     * Whether a page was never inspected or not since $old.
     *
     * @param array<int,int> $checked Path id => checked.
     * @param int            $path_id Path id.
     * @param int            $old     Oldest inspection still current.
     * @return bool
     */
    private static function is_due(array $checked, $path_id, $old) {
        return !isset($checked[$path_id]) || $checked[$path_id] < $old;
    }

    /**
     * Pages with impressions that are due and not listed already: never
     * inspected first, then the oldest inspection; most impressions first.
     *
     * @param array<int,int>    $traffic Path id => impressions.
     * @param array<int,int>    $checked Path id => checked.
     * @param array<int,string> $out     Pages chosen already.
     * @param int               $old     Oldest inspection still current.
     * @return int[] Path ids.
     */
    private static function traffic_due(array $traffic, array $checked, array $out, $old) {
        $rest = array();
        foreach ($traffic as $path_id => $impressions) {
            if (!isset($out[$path_id]) && self::is_due($checked, $path_id, $old)) {
                $rest[] = array($path_id, isset($checked[$path_id]) ? $checked[$path_id] : 0, $impressions);
            }
        }
        usort($rest, static function ($a, $b) {
            return array($a[1], $b[2], $a[0]) <=> array($b[1], $a[2], $b[0]);
        });
        return array_column($rest, 0);
    }

    /**
     * Pages with Google impressions in the newest TRAFFIC_DAYS days of
     * search data, most first, by gsc_pages's primary key.
     *
     * @return array<int,int> Path id => impressions.
     */
    private static function traffic() {
        global $wpdb;
        $bounds = SEOProStats_Search::bounds(SEOProStats_Schema::ENGINE_GOOGLE);
        if ($bounds['to'] === '') {
            return array();
        }
        $from = (new DateTimeImmutable($bounds['to'], wp_timezone()))->modify('-' . (self::TRAFFIC_DAYS - 1) . ' days')->format('Y-m-d');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key's (engine, day) prefix.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, SUM(impressions) AS i FROM %i FORCE INDEX (`PRIMARY`) WHERE engine = %d AND day >= %s AND day <= %s AND impressions > 0 GROUP BY path_id ORDER BY NULL', SEOProStats_Schema::table('gsc_pages'), SEOProStats_Schema::ENGINE_GOOGLE, $from, $bounds['to']), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['path_id']] = (int) $row['i'];
        }
        arsort($out);
        return array_slice($out, 0, self::TRAFFIC_PAGES, true);
    }

    /**
     * When pages were last inspected, by the primary key.
     *
     * @param int[] $ids Path ids.
     * @return array<int,int> Path id => checked.
     */
    private static function checked_of(array $ids) {
        global $wpdb;
        $out = array();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $holders holds only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT path_id, checked FROM %i WHERE path_id IN ($holders)", array_merge(array(SEOProStats_Schema::table('inspections')), $chunk)), ARRAY_A) as $row) {
                $out[(int) $row['path_id']] = (int) $row['checked'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Storing.

    /**
     * Keep one page's inspection (Google's inspectionResult), and record
     * a change when its verdict changed (live data).
     *
     * @param int                 $path_id Path id (0: found from the path).
     * @param string              $path    Path.
     * @param array<string,mixed> $result  inspectionResult; empty when Google would not inspect it.
     * @param int                 $checked When it was inspected.
     * @param string              $error   Google's message when it would not.
     * @return bool
     */
    public static function store($path_id, $path, array $result, $checked, $error = '') {
        global $wpdb;
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        $path_id = (int) $path_id;
        if (!$path_id) {
            $path_id = self::dict_id(SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array((string) $path)), (string) $path);
        }
        if (!$path_id) {
            return false;
        }
        $row     = self::parse($result, self::url((string) $path));
        $urls    = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_URL, array($row['google_canonical'], $row['user_canonical']));
        $cover   = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_COVERAGE, array($row['coverage']));
        $details = $row['details'];
        if ($error !== '') {
            $details['error'] = sanitize_text_field($error);
        }
        $table  = SEOProStats_Schema::table('inspections');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key.
        $before = $wpdb->get_row($wpdb->prepare('SELECT verdict, coverage_id FROM %i WHERE path_id = %d', $table, $path_id), ARRAY_A);
        $done   = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (path_id, checked, verdict, coverage_id, indexing, robots, page_fetch, crawled_as, crawled, google_canonical_id, user_canonical_id, rich, flags, details) VALUES (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s)'
            . ' ON DUPLICATE KEY UPDATE checked = VALUES(checked), verdict = VALUES(verdict), coverage_id = VALUES(coverage_id), indexing = VALUES(indexing), robots = VALUES(robots), page_fetch = VALUES(page_fetch), crawled_as = VALUES(crawled_as), crawled = VALUES(crawled), google_canonical_id = VALUES(google_canonical_id), user_canonical_id = VALUES(user_canonical_id), rich = VALUES(rich), flags = VALUES(flags), details = VALUES(details)',
            $table,
            $path_id,
            (int) $checked,
            $row['verdict'],
            self::dict_id($cover, $row['coverage']),
            $row['indexing'],
            $row['robots'],
            $row['page_fetch'],
            $row['crawled_as'],
            $row['crawled'],
            self::dict_id($urls, $row['google_canonical']),
            self::dict_id($urls, $row['user_canonical']),
            $row['rich'],
            $row['flags'],
            (string) wp_json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));
        // phpcs:enable
        if (is_array($before)) {
            self::record_verdict_change($before, $row, (string) $path, (int) $checked);
        }
        return $done !== false;
    }

    /**
     * A dictionary id from SEOProStats_Dict::ids()'s answer, or 0.
     *
     * @param array<string,int> $ids  Clean text => id.
     * @param string            $text Text.
     * @return int
     */
    private static function dict_id(array $ids, $text) {
        $clean = SEOProStats_Dict::clean((string) $text);
        return isset($ids[$clean]) ? (int) $ids[$clean] : 0;
    }

    /**
     * Record a change when a page's verdict changed (live data only).
     *
     * @param array<string,mixed> $before  The stored verdict and coverage_id.
     * @param array<string,mixed> $row     From parse().
     * @param string              $path    Path.
     * @param int                 $checked When it was inspected.
     * @return void
     */
    private static function record_verdict_change(array $before, array $row, $path, $checked) {
        $was = (int) $before['verdict'];
        if (!$row['verdict'] || !$was || $was === $row['verdict'] || SEOProStats_Schema::set() !== 'live') {
            return;
        }
        require_once __DIR__ . '/class-seoprostats-changes.php';
        $coverage_id = (int) $before['coverage_id'];
        $old         = SEOProStats_Query::texts(array($coverage_id));
        SEOProStats_Changes::record(SEOProStats_Changes::INDEX_STATUS, array(
            'ts'          => $checked,
            'path'        => $path,
            'object_type' => 'inspection',
            'old'         => isset($old[$coverage_id]) ? (string) $old[$coverage_id] : '',
            'new'         => $row['coverage'],
            'meta'        => array('name' => $path, 'verdict' => self::verdict_name((int) $row['verdict']), 'verdict_before' => self::verdict_name($was)),
            // WP-CLI (2) or the import cron (4).
            'source'      => defined('WP_CLI') && WP_CLI ? 2 : 4,
            'user_id'     => 0,
        ));
    }

    /**
     * A verdict code's name, or ''.
     *
     * @param int $code Code from VERDICTS.
     * @return string
     */
    private static function verdict_name($code) {
        return isset(self::VERDICTS[$code]) ? self::VERDICTS[$code] : '';
    }

    /**
     * An inspectionResult as kept: codes, texts, findings and details.
     *
     * @param array<string,mixed> $result inspectionResult.
     * @param string              $url    The address inspected.
     * @return array{verdict:int,coverage:string,indexing:int,robots:int,page_fetch:int,crawled_as:int,crawled:int,google_canonical:string,user_canonical:string,rich:int,flags:int,details:array<string,mixed>}
     */
    public static function parse(array $result, $url) {
        $index    = self::array_field($result, 'indexStatusResult');
        $rich     = self::array_field($result, 'richResultsResult');
        $types    = self::rich_types($rich);
        $google   = esc_url_raw(self::text_of($index, 'googleCanonical'));
        $user     = esc_url_raw(self::text_of($index, 'userCanonical'));
        $coverage = sanitize_text_field(self::text_of($index, 'coverageState'));
        $codes    = array(
            'robots'     => self::code_of(self::ROBOTS, self::text_of($index, 'robotsTxtState')),
            'indexing'   => self::code_of(self::INDEXING, self::text_of($index, 'indexingState')),
            'page_fetch' => self::code_of(self::FETCH, self::text_of($index, 'pageFetchState')),
        );
        $crawled  = strtotime(self::text_of($index, 'lastCrawlTime'));
        return array(
            'verdict'          => self::code_of(self::VERDICTS, self::text_of($index, 'verdict')),
            'coverage'         => $coverage,
            'indexing'         => $codes['indexing'],
            'robots'           => $codes['robots'],
            'page_fetch'       => $codes['page_fetch'],
            'crawled_as'       => self::code_of(self::CRAWLED_AS, self::text_of($index, 'crawledAs')),
            'crawled'          => $crawled ? (int) $crawled : 0,
            'google_canonical' => $google,
            'user_canonical'   => $user,
            'rich'             => self::code_of(self::VERDICTS, self::text_of($rich, 'verdict')),
            // The page's own canonical: declared, else the page itself.
            'flags'            => self::parse_flags($codes, $coverage, $google, $user !== '' ? $user : (string) $url, $types),
            'details'          => array(
                'rich'      => $types,
                'sitemaps'  => self::url_list($index, 'sitemap'),
                'referring' => self::url_list($index, 'referringUrls'),
                'link'      => esc_url_raw(self::text_of($result, 'inspectionResultLink')),
            ),
        );
    }

    /**
     * A scalar field as text, or ''.
     *
     * @param array<mixed> $from Response.
     * @param string       $key  Field.
     * @return string
     */
    private static function text_of(array $from, $key) {
        return isset($from[$key]) && is_scalar($from[$key]) ? (string) $from[$key] : '';
    }

    /**
     * A value's code in a list of Google's values, or 0.
     *
     * @param array<int,string> $codes Code => value.
     * @param string            $value Google's value.
     * @return int
     */
    private static function code_of(array $codes, $value) {
        $found = array_search((string) $value, $codes, true);
        return $found === false ? 0 : (int) $found;
    }

    /**
     * Up to MAX_LIST addresses from a list field.
     *
     * @param array<mixed> $from Response.
     * @param string       $key  Field.
     * @return string[]
     */
    private static function url_list(array $from, $key) {
        $values = isset($from[$key]) && is_array($from[$key]) ? array_filter($from[$key], 'is_scalar') : array();
        return array_slice(array_values(array_filter(array_map('esc_url_raw', array_map('strval', $values)))), 0, self::MAX_LIST);
    }

    /**
     * Findings (FLAGS) of one inspection.
     *
     * @param array{robots:int,indexing:int,page_fetch:int} $codes     Robots, indexing and fetch codes.
     * @param string                                         $coverage  Google's coverage state.
     * @param string                                         $google    Google's canonical.
     * @param string                                         $own       The page's own canonical.
     * @param array<int,array<string,mixed>>                 $types     From rich_types().
     * @return int
     */
    private static function parse_flags(array $codes, $coverage, $google, $own, array $types) {
        $flags = 0;
        if ($codes['robots'] === 2 || $codes['indexing'] === 4 || $codes['page_fetch'] === 3) {
            $flags |= self::FLAGS['robots_blocked'];
        }
        // Google's state in English (the request asks for en-US): "Crawled - currently not indexed".
        if (preg_match('/^crawled\b.*\bnot indexed/i', $coverage)) {
            $flags |= self::FLAGS['not_indexed'];
        }
        // Google chose another canonical than the page's own.
        if ($google !== '' && self::same_url($google, $own) === false) {
            $flags |= self::FLAGS['google_canonical'];
        }
        if (array_filter(array_column($types, 'errors'))) {
            $flags |= self::FLAGS['rich_errors'];
        }
        return $flags;
    }

    /**
     * Rich result types found, with their item, error and warning counts
     * and up to MAX_LIST distinct issues each.
     *
     * @param array<mixed> $rich richResultsResult.
     * @return array<int,array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>}>
     */
    private static function rich_types(array $rich) {
        $types = array();
        foreach (self::array_field($rich, 'detectedItems') as $detected) {
            if (is_array($detected)) {
                $types[] = self::rich_type($detected);
            }
        }
        return $types;
    }

    /**
     * One detected rich result type.
     *
     * @param array<mixed> $detected Detected items.
     * @return array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>}
     */
    private static function rich_type(array $detected) {
        $type = array('type' => sanitize_text_field(self::text_of($detected, 'richResultType')), 'items' => 0, 'errors' => 0, 'warnings' => 0, 'issues' => array());
        foreach (self::array_field($detected, 'items') as $item) {
            if (!is_array($item)) {
                continue;
            }
            ++$type['items'];
            foreach (self::array_field($item, 'issues') as $issue) {
                self::add_issue($type, $issue);
            }
        }
        return $type;
    }

    /**
     * Count one issue and keep its message once.
     *
     * @param array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>} $type  Rich result type.
     * @param mixed                                                                                                      $issue Issue.
     * @param-out array{type:string,items:int,errors:int,warnings:int,issues:array<int,array{message:string,severity:string}>} $type
     * @return void
     */
    private static function add_issue(array &$type, $issue) {
        $issue    = is_array($issue) ? $issue : array();
        $severity = self::text_of($issue, 'severity');
        if ($severity === 'ERROR') {
            ++$type['errors'];
        } elseif ($severity === 'WARNING') {
            ++$type['warnings'];
        }
        $message = $issue ? sanitize_text_field(self::text_of($issue, 'issueMessage')) : '';
        if ($message !== '' && count($type['issues']) < self::MAX_LIST && !in_array($message, array_column($type['issues'], 'message'), true)) {
            $type['issues'][] = array('message' => $message, 'severity' => $severity === 'ERROR' ? 'error' : 'warning');
        }
    }

    /**
     * Whether two addresses are the same page: scheme, host (with or
     * without www) and path with or without a trailing slash; the query
     * counts.
     *
     * @param string $a Address.
     * @param string $b Address.
     * @return bool
     */
    public static function same_url($a, $b) {
        $norm = static function ($url) {
            $parts = wp_parse_url((string) $url);
            if (!is_array($parts)) {
                return (string) $url;
            }
            $host = strtolower((string) preg_replace(self::WWW_PREFIX, '', isset($parts['host']) ? (string) $parts['host'] : ''));
            $path = isset($parts['path']) && $parts['path'] !== '' ? rtrim((string) $parts['path'], '/') : '';
            return strtolower(isset($parts['scheme']) ? (string) $parts['scheme'] : '') . '://' . $host . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        };
        return $norm($a) === $norm($b);
    }

    // ------------------------------------------------------------------
    // Reading.

    /**
     * The inspections report: pages inspected, newest first, with the
     * sitemaps and the run's progress.
     *
     * @param array<string,mixed> $req      From SEOProStats_Query::request() (filters, limit, offset).
     * @param string              $verdict  Only this verdict (VERDICTS), '' for all.
     * @param string              $coverage Only this coverage state (Google's words), '' for all.
     * @param string              $finding  Only pages with this finding (FLAGS), '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $verdict = '', $coverage = '', $finding = '') {
        self::load();
        $verdict  = strtoupper(trim((string) $verdict));
        $coverage = trim((string) $coverage);
        $finding  = trim((string) $finding);
        $invalid  = self::report_invalid($verdict, $finding);
        if ($invalid) {
            return $invalid;
        }
        $limit   = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset  = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids(isset($req['filters']) ? (array) $req['filters'] : array(), '', $ignored);
        $rows    = self::report_rows($pages, $verdict, $coverage, $finding);
        $counts  = self::verdict_counts();
        $inspected = self::inspected();
        $rows      = self::filter_rows($rows, $verdict, $coverage, $finding);
        usort($rows, static function ($a, $b) {
            return array((int) $b['checked'], (int) $a['path_id']) <=> array((int) $a['checked'], (int) $b['path_id']);
        });
        $total = count($rows);
        $shown = self::rows(array_slice($rows, $offset, $limit));
        foreach ($shown as &$row) {
            if ((int) $row['post_id']) {
                $row = SEOProStats_Clicks::with_edit_url($row);
            }
        }
        unset($row);
        return array(
            'connected' => SEOProStats_Schema::set() !== 'live' || SEOProStats_Search::connected('google'),
            'ignored'   => array_values(array_unique($ignored)),
            'verdict'   => $verdict,
            'coverage'  => $coverage,
            'finding'   => $finding,
            'progress'  => self::progress(),
            'rules'     => self::rules(),
            'sitemaps'  => self::sitemaps(),
            'inspected' => $inspected,
            'counts'    => $counts,
            'rows'      => $shown,
            'total'     => $total,
            'more'      => $offset + $limit < $total,
        );
    }

    /**
     * The report's error for a verdict or finding it does not know, or null.
     *
     * @param string $verdict Verdict, '' for all.
     * @param string $finding Finding, '' for all.
     * @return WP_Error|null
     */
    private static function report_invalid($verdict, $finding) {
        if ($verdict !== '' && !in_array($verdict, self::VERDICTS, true)) {
            /* translators: %s: list of verdicts */
            return new WP_Error('seoprostats_inspections_verdict', sprintf(__('The verdict is one of: %s.', 'seoprostats'), implode(', ', self::VERDICTS)), array('status' => 400));
        }
        if ($finding !== '' && !isset(self::FLAGS[$finding])) {
            /* translators: %s: list of findings */
            return new WP_Error('seoprostats_inspections_finding', sprintf(__('The finding is one of: %s.', 'seoprostats'), implode(', ', array_keys(self::FLAGS))), array('status' => 400));
        }
        return null;
    }

    /**
     * Pages with a verdict of each kind, by the verdict_checked key.
     *
     * @return array<string,int> Verdict => pages.
     */
    private static function verdict_counts() {
        global $wpdb;
        $table  = SEOProStats_Schema::table('inspections');
        $counts = array();
        foreach (self::VERDICTS as $code => $name) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its verdict_checked key.
            $counts[$name] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`verdict_checked`) WHERE verdict = %d', $table, $code));
        }
        return $counts;
    }

    /**
     * Rows matching the verdict, coverage state and finding asked for.
     *
     * @param array<int,array<string,mixed>> $rows     Table rows.
     * @param string                         $verdict  Verdict, '' for all.
     * @param string                         $coverage Coverage state, '' for all.
     * @param string                         $finding  Finding, '' for all.
     * @return array<int,array<string,mixed>>
     */
    private static function filter_rows(array $rows, $verdict, $coverage, $finding) {
        $cover_ids = $coverage !== '' ? SEOProStats_Dict::find(SEOProStats_Schema::DICT_COVERAGE, array($coverage)) : array();
        return array_values(array_filter($rows, static function ($row) use ($verdict, $coverage, $cover_ids, $finding) {
            return ($verdict === '' || (int) $row['verdict'] === (int) array_search($verdict, self::VERDICTS, true))
                && ($coverage === '' || in_array((int) $row['coverage_id'], $cover_ids, true))
                && ($finding === '' || ((int) $row['flags'] & self::FLAGS[$finding]));
        }));
    }

    /**
     * The report's candidate rows, by the key that fits what is asked:
     * filtered pages by the primary key, else the verdict, coverage,
     * flags or checked key.
     *
     * @param int[]|null $pages    Filtered path ids, or null.
     * @param string     $verdict  Verdict, '' for all.
     * @param string     $coverage Coverage state, '' for all.
     * @param string     $finding  Finding, '' for all.
     * @return array<int,array<string,mixed>>
     */
    private static function report_rows($pages, $verdict, $coverage, $finding) {
        global $wpdb;
        $table = SEOProStats_Schema::table('inspections');
        $cols  = 'path_id, checked, verdict, coverage_id, indexing, robots, page_fetch, crawled_as, crawled, google_canonical_id, user_canonical_id, rich, flags, details';
        $rows  = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its primary, verdict_checked, coverage_checked, flags and checked keys; $cols is a fixed column list and $holders only placeholders.
        if ($pages !== null) {
            foreach (array_chunk(array_map('intval', $pages), self::CHUNK) as $chunk) {
                $holders = implode(', ', array_fill(0, count($chunk), '%d'));
                $rows    = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE path_id IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A));
            }
        } elseif ($verdict !== '') {
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`verdict_checked`) WHERE verdict = %d ORDER BY checked DESC LIMIT %d", $table, (int) array_search($verdict, self::VERDICTS, true), self::MAX_ROWS), ARRAY_A);
        } elseif ($coverage !== '') {
            $ids  = SEOProStats_Dict::find(SEOProStats_Schema::DICT_COVERAGE, array($coverage));
            $rows = $ids ? (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`coverage_checked`) WHERE coverage_id = %d ORDER BY checked DESC LIMIT %d", $table, (int) $ids[0], self::MAX_ROWS), ARRAY_A) : array();
        } elseif ($finding !== '') {
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d", $table, self::MAX_ROWS), ARRAY_A);
        } else {
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`checked`) WHERE checked > 0 ORDER BY checked DESC LIMIT %d", $table, self::MAX_ROWS), ARRAY_A);
        }
        // phpcs:enable
        return $rows;
    }

    /**
     * Rows as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $rows Table rows.
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $rows) {
        $ids = array();
        foreach ($rows as $row) {
            array_push($ids, (int) $row['path_id'], (int) $row['coverage_id'], (int) $row['google_canonical_id'], (int) $row['user_canonical_id']);
        }
        $text = SEOProStats_Query::texts($ids);
        $live = SEOProStats_Schema::set() === 'live';
        $out  = array();
        foreach ($rows as $row) {
            $path = isset($text[(int) $row['path_id']]) ? (string) $text[(int) $row['path_id']] : '';
            $info = $live && $path !== '' ? SEOProStats_Clicks::page_info($path) : null;
            $out[] = array(
                'path_id'  => (int) $row['path_id'],
                'path'     => $path,
                'url'      => self::url($path),
                'post_id'  => is_array($info) && isset($info['post_id']) ? (int) $info['post_id'] : 0,
                'edit_url' => null,
            ) + self::google($row, $text);
        }
        return $out;
    }

    /**
     * Google's view of a page from its row, as reports give it.
     *
     * @param array<string,mixed> $row  Table row.
     * @param array<int,string>   $text Dictionary texts by id.
     * @return array<string,mixed>
     */
    private static function google(array $row, array $text) {
        $details = json_decode((string) $row['details'], true);
        $details = is_array($details) ? $details : array();
        return array(
            'checked'          => gmdate('c', (int) $row['checked']),
            'verdict'          => self::value_of(self::VERDICTS, $row['verdict']),
            'coverage'         => self::text_by_id($text, $row['coverage_id']),
            'indexing'         => self::value_of(self::INDEXING, $row['indexing']),
            'robots'           => self::value_of(self::ROBOTS, $row['robots']),
            'page_fetch'       => self::value_of(self::FETCH, $row['page_fetch']),
            'crawled_as'       => self::value_of(self::CRAWLED_AS, $row['crawled_as']),
            'last_crawl'       => (int) $row['crawled'] ? gmdate('c', (int) $row['crawled']) : null,
            'google_canonical' => self::text_by_id($text, $row['google_canonical_id']),
            'user_canonical'   => self::text_by_id($text, $row['user_canonical_id']),
            'rich_verdict'     => self::value_of(self::VERDICTS, $row['rich']),
            'rich'             => array_values(self::array_field($details, 'rich')),
            'sitemaps'         => array_values(self::array_field($details, 'sitemaps')),
            'referring'        => array_values(self::array_field($details, 'referring')),
            'link'             => isset($details['link']) && $details['link'] !== '' ? (string) $details['link'] : null,
            'error'            => isset($details['error']) ? (string) $details['error'] : null,
            'findings'         => self::findings((int) $row['flags']),
        );
    }

    /**
     * A stored code's value, or null.
     *
     * @param array<int,string> $codes Code => value.
     * @param mixed             $code  Stored code.
     * @return string|null
     */
    private static function value_of(array $codes, $code) {
        return isset($codes[(int) $code]) ? $codes[(int) $code] : null;
    }

    /**
     * A dictionary text by its id, or null for none.
     *
     * @param array<int,string> $text Dictionary texts by id.
     * @param mixed             $id   Dictionary id.
     * @return string|null
     */
    private static function text_by_id(array $text, $id) {
        return (int) $id && isset($text[(int) $id]) ? (string) $text[(int) $id] : null;
    }

    /**
     * The names of the findings (FLAGS) set in a flags value.
     *
     * @param int $flags Flags.
     * @return string[]
     */
    private static function findings($flags) {
        $findings = array();
        foreach (self::FLAGS as $name => $bit) {
            if ($flags & $bit) {
                $findings[] = $name;
            }
        }
        return $findings;
    }

    /**
     * Google's view of pages, for other reports (Indexation, Audit), by
     * the primary key.
     *
     * @param int[] $ids Path ids.
     * @return array<int,array<string,mixed>> Path id => google().
     */
    public static function of_pages(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !SEOProStats_Schema::is_current()) {
            return array();
        }
        $rows = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $holders holds only placeholders.
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE path_id IN ($holders)", array_merge(array(SEOProStats_Schema::table('inspections')), $chunk)), ARRAY_A));
        }
        $text = array();
        foreach ($rows as $row) {
            array_push($text, (int) $row['coverage_id'], (int) $row['google_canonical_id'], (int) $row['user_canonical_id']);
        }
        $text = SEOProStats_Query::texts($text);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['path_id']] = self::google($row, $text);
        }
        return $out;
    }

    /**
     * Pages inspected, by the checked key.
     *
     * @return int
     */
    public static function inspected() {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its checked key.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`checked`) WHERE checked > 0', SEOProStats_Schema::table('inspections')));
    }

    /**
     * Pages with a finding, by the flags key (the content audit).
     *
     * @param int $limit Most rows.
     * @return array<int,int> Path id => flags.
     */
    public static function flagged($limit = self::MAX_ROWS) {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its flags key.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, flags FROM %i FORCE INDEX (`flags`) WHERE flags > 0 LIMIT %d', SEOProStats_Schema::table('inspections'), (int) $limit), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['path_id']] = (int) $row['flags'];
        }
        return $out;
    }

    /**
     * The sitemaps as Google read them, each with its problems, and
     * whether the site's own sitemap index is submitted.
     *
     * @return array<string,mixed>
     */
    public static function sitemaps() {
        $state = self::state();
        $now   = time();
        $own   = self::own_sitemap();
        $rows  = array();
        foreach ($state['sitemaps'] as $one) {
            $rows[] = self::sitemap_out($one, $now);
        }
        $read = (bool) $state['sitemaps_read'] && $state['sitemaps_error'] === null;
        return array(
            'read'       => self::iso_time($state['sitemaps_read']),
            'error'      => $state['sitemaps_error'],
            'own'        => $own !== '' ? $own : null,
            'submitted'  => !$read || $own === '' || self::index_submitted($state['sitemaps'], $own),
            'stale_days' => self::STALE_DAYS,
            'rows'       => $rows,
        );
    }

    /**
     * Whether the site's own index, or another index on the site, is
     * submitted.
     *
     * @param array<int,array<string,mixed>> $sitemaps Stored sitemaps.
     * @param string                         $own      The site's own sitemap index.
     * @return bool
     */
    private static function index_submitted(array $sitemaps, $own) {
        foreach ($sitemaps as $one) {
            $path = (string) $one['path'];
            if (self::same_url($path, $own) || (!empty($one['index']) && self::on_site($path))) {
                return true;
            }
        }
        return false;
    }

    /**
     * One stored sitemap as the report gives it.
     *
     * @param array<string,mixed> $one Stored sitemap.
     * @param int                 $now Now.
     * @return array<string,mixed>
     */
    private static function sitemap_out(array $one, $now) {
        return array(
            'path'       => (string) $one['path'],
            'type'       => (string) $one['type'],
            'index'      => !empty($one['index']),
            'pending'    => !empty($one['pending']),
            'submitted'  => self::iso_time($one['submitted']),
            'downloaded' => self::iso_time($one['downloaded']),
            'errors'     => (int) $one['errors'],
            'warnings'   => (int) $one['warnings'],
            'contents'   => array_values(self::array_field($one, 'contents')),
            'problems'   => self::problems_of($one, $now),
        );
    }

    /**
     * A timestamp as ISO 8601 (UTC), or null for none.
     *
     * @param mixed $ts Timestamp.
     * @return string|null
     */
    private static function iso_time($ts) {
        return (int) $ts ? gmdate('c', (int) $ts) : null;
    }

    /**
     * One sitemap's problems, in SITEMAP_PROBLEMS order: errors, stale
     * (not downloaded, else submitted, in STALE_DAYS days), warnings.
     *
     * @param array<string,mixed> $one Stored sitemap.
     * @param int                 $now Now.
     * @return string[]
     */
    private static function problems_of(array $one, $now) {
        $problems = array();
        if ((int) $one['errors'] > 0) {
            $problems[] = 'errors';
        }
        $since = (int) $one['downloaded'] ? (int) $one['downloaded'] : (int) $one['submitted'];
        if ($since && $since < $now - self::STALE_DAYS * DAY_IN_SECONDS) {
            $problems[] = 'stale';
        }
        if ((int) $one['warnings'] > 0) {
            $problems[] = 'warnings';
        }
        return $problems;
    }

    /**
     * The sitemap problems, one per sitemap and problem (and one for the
     * site's own index not submitted), for the decision queue.
     *
     * @return array<int,array{problem:string,url:string,path:string,errors:int,warnings:int,downloaded:string|null,submitted:string|null}>
     */
    public static function sitemap_problems() {
        $all = self::sitemaps();
        $out = array();
        if ($all['read'] !== null && $all['error'] === null && !$all['submitted']) {
            $out[] = array('problem' => 'missing', 'url' => (string) $all['own'], 'path' => self::path_of((string) $all['own']), 'errors' => 0, 'warnings' => 0, 'downloaded' => null, 'submitted' => null);
        }
        foreach ($all['rows'] as $row) {
            foreach ($row['problems'] as $problem) {
                $out[] = array(
                    'problem'    => (string) $problem,
                    'url'        => (string) $row['path'],
                    'path'       => self::path_of((string) $row['path']),
                    'errors'     => (int) $row['errors'],
                    'warnings'   => (int) $row['warnings'],
                    'downloaded' => $row['downloaded'] !== null ? (string) $row['downloaded'] : null,
                    'submitted'  => $row['submitted'] !== null ? (string) $row['submitted'] : null,
                );
            }
        }
        return $out;
    }

    /**
     * Why a sitemap problem is listed, in a sentence.
     *
     * @param array<string,mixed> $problem From sitemap_problems().
     * @return string
     */
    public static function why(array $problem) {
        switch ($problem['problem']) {
            case 'missing':
                /* translators: %s: the site's sitemap address */
                return sprintf(__('The site’s sitemap %s is not submitted in Search Console, so Google may find new pages late.', 'seoprostats'), (string) $problem['url']);
            case 'errors':
                /* translators: 1: number of errors, 2: sitemap address */
                return sprintf(_n('Google found %1$s error in the sitemap %2$s; it may not read it.', 'Google found %1$s errors in the sitemap %2$s; it may not read it.', (int) $problem['errors'], 'seoprostats'), number_format_i18n((int) $problem['errors']), (string) $problem['url']);
            case 'stale':
                /* translators: 1: sitemap address, 2: number of days */
                return sprintf(__('Google has not downloaded the sitemap %1$s for over %2$s days.', 'seoprostats'), (string) $problem['url'], number_format_i18n(self::STALE_DAYS));
            default:
                /* translators: 1: number of warnings, 2: sitemap address */
                return sprintf(_n('Google found %1$s warning in the sitemap %2$s.', 'Google found %1$s warnings in the sitemap %2$s.', (int) $problem['warnings'], 'seoprostats'), number_format_i18n((int) $problem['warnings']), (string) $problem['url']);
        }
    }

    /**
     * What to do about a sitemap problem.
     *
     * @param string $problem One of SITEMAP_PROBLEMS.
     * @return string
     */
    public static function todo($problem) {
        switch ($problem) {
            case 'missing':
                return __('Submit it in Search Console → Sitemaps.', 'seoprostats');
            case 'errors':
                return __('Open the sitemap in Search Console → Sitemaps, fix what it names (often an address that fails or a sitemap too large), and submit it again.', 'seoprostats');
            case 'stale':
                return __('Check that the sitemap opens without an error or a redirect and is allowed in robots.txt, then submit it again.', 'seoprostats');
            default:
                return __('Open the sitemap in Search Console → Sitemaps and check the addresses it warns about.', 'seoprostats');
        }
    }

    /**
     * Why a page's inspection finding matters, in a few words (the content
     * audit's phrase).
     *
     * @param string              $finding One of FLAGS.
     * @param array<string,mixed> $google  google() of the page, or empty.
     * @return string
     */
    public static function phrase($finding, array $google) {
        switch ($finding) {
            case 'robots_blocked':
                return __('robots.txt stops Google crawling it', 'seoprostats');
            case 'not_indexed':
                return __('Google crawled it but did not index it', 'seoprostats');
            case 'google_canonical':
                /* translators: %s: the address Google chose as canonical */
                return isset($google['google_canonical']) && $google['google_canonical'] ? sprintf(__('Google chose %s as its canonical', 'seoprostats'), (string) $google['google_canonical']) : __('Google chose another page as its canonical', 'seoprostats');
            default:
                return self::rich_phrase($google);
        }
    }

    /**
     * The rich result errors phrase, naming the types with errors.
     *
     * @param array<string,mixed> $google google() of the page, or empty.
     * @return string
     */
    private static function rich_phrase(array $google) {
        $types = array();
        foreach (isset($google['rich']) ? (array) $google['rich'] : array() as $type) {
            if (is_array($type) && !empty($type['errors'])) {
                $types[] = (string) $type['type'];
            }
        }
        /* translators: %s: rich result types, e.g. "Breadcrumbs, FAQ" */
        return $types ? sprintf(__('rich result errors (%s)', 'seoprostats'), implode(', ', $types)) : __('rich result errors', 'seoprostats');
    }

    /**
     * What to do about a page's inspection finding.
     *
     * @param string $finding One of FLAGS.
     * @return string
     */
    public static function fix($finding) {
        switch ($finding) {
            case 'robots_blocked':
                return __('Allow the page in robots.txt, unless it is kept out of search on purpose.', 'seoprostats');
            case 'not_indexed':
                return __('Make the page clearly worth indexing (more of its own content, links from related pages), then ask Google to index it.', 'seoprostats');
            case 'google_canonical':
                return __('Make the page distinct from the one Google chose, or point the canonical address at that page and link to it.', 'seoprostats');
            default:
                return __('Fix the structured data issues Search Console names, then test the page again.', 'seoprostats');
        }
    }

    // ------------------------------------------------------------------
    // Progress and helpers.

    /**
     * The daily cap and today's use, the last run and its error.
     *
     * @return array<string,mixed>
     */
    public static function progress() {
        $state = self::state();
        return array(
            'daily'    => self::daily(),
            'used'     => self::used(),
            'day'      => self::today(),
            'last'     => $state['last'] ? gmdate('c', $state['last']) : null,
            'error'    => $state['error'],
            'error_at' => $state['error_at'] ? gmdate('c', $state['error_at']) : null,
        );
    }

    /**
     * The rules the run keeps.
     *
     * @return array<string,int>
     */
    public static function rules() {
        return array(
            'daily'        => self::daily(),
            'max_daily'    => 2000,
            'recheck_days' => self::RECHECK_DAYS,
            'budget'       => self::BUDGET,
            'stale_days'   => self::STALE_DAYS,
        );
    }

    /**
     * Inspections a day at most (the setting; the demo shows the default).
     *
     * @return int
     */
    public static function daily() {
        return class_exists('SEOProStats_Statistics') ? SEOProStats_Statistics::inspections() : 200;
    }

    /**
     * Inspections asked for today (Google's day).
     *
     * @return int
     */
    public static function used() {
        $state = self::state();
        return $state['day'] === self::today() ? $state['used'] : 0;
    }

    /**
     * Count one inspection against today's cap.
     */
    private static function count_one() {
        self::save(array('day' => self::today(), 'used' => self::used() + 1));
    }

    /**
     * Today in Google's time zone (Pacific), where its daily quota starts.
     *
     * @return string Y-m-d.
     */
    private static function today() {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
    }

    /**
     * Progress of the current data set.
     *
     * @return array{sitemaps:array<int,array<string,mixed>>,sitemaps_read:int,sitemaps_error:string|null,day:string,used:int,last:int,error:string|null,error_at:int,version:int}
     */
    public static function state() {
        $state = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        return array(
            'sitemaps'       => self::array_field($state, 'sitemaps'),
            'sitemaps_read'  => self::integer_field($state, 'sitemaps_read'),
            'sitemaps_error' => self::nullable_text($state, 'sitemaps_error'),
            'day'            => isset($state['day']) ? (string) $state['day'] : '',
            'used'           => self::integer_field($state, 'used'),
            'last'           => self::integer_field($state, 'last'),
            'error'          => self::nullable_text($state, 'error'),
            'error_at'       => self::integer_field($state, 'error_at'),
            'version'        => self::integer_field($state, 'version'),
        );
    }

    /**
     * An optional text field, or null.
     *
     * @param array<mixed> $from Stored values.
     * @param string       $key  Field.
     * @return string|null
     */
    private static function nullable_text(array $from, $key) {
        return isset($from[$key]) ? (string) $from[$key] : null;
    }

    /**
     * Change some of the progress (null removes a value).
     *
     * @param array<string,mixed> $values Values.
     */
    private static function save(array $values) {
        $state = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }
        update_option(SEOProStats_Schema::option(self::OPTION), $state, false);
    }

    /**
     * Note that inspections were written (the demo), so cached reports are
     * made again.
     */
    public static function touch() {
        self::save(array('version' => time()));
    }

    /**
     * Delete the current data set's progress and sitemaps (demo removal,
     * uninstall); the live run's lock only with live data.
     */
    public static function reset() {
        delete_option(SEOProStats_Schema::option(self::OPTION));
        if (SEOProStats_Schema::set() === 'live') {
            delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * Search Console's class, an access token and the property.
     *
     * @return array{0:string,1:string,2:string}|WP_Error
     */
    private static function ready() {
        if (SEOProStats_Schema::set() !== 'live') {
            return new WP_Error('seoprostats_inspections_demo', __('Google is asked about live data only.', 'seoprostats'), array('status' => 400));
        }
        require_once __DIR__ . '/class-seoprostats-connections.php';
        require_once __DIR__ . '/class-seoprostats-search-import.php';
        if (!SEOProStats_Connections::get(self::SOURCE)) {
            return new WP_Error('seoprostats_not_connected', __('Search Console is not connected. Connect it in SEO Pro Stats → Settings → Connections.', 'seoprostats'), array('status' => 400));
        }
        self::load();
        return SEOProStats_Search_Import::ready(self::SOURCE);
    }

    /**
     * The site's own sitemap index: its SEO plugin's, else WordPress's
     * when its sitemaps are on, else ''.
     *
     * @return string
     */
    private static function own_sitemap() {
        if (SEOProStats_Schema::set() === 'demo') {
            return home_url('/wp-sitemap.xml');
        }
        require_once __DIR__ . '/class-seoprostats-coverage.php';
        $plugin = SEOProStats_Coverage::seo_plugin();
        if (isset(self::PLUGIN_SITEMAPS[$plugin])) {
            return home_url(self::PLUGIN_SITEMAPS[$plugin]);
        }
        $url = function_exists('get_sitemap_url') ? get_sitemap_url('index') : false;
        return is_string($url) ? $url : '';
    }

    /**
     * Whether an address is on this site.
     *
     * @param string $url Address.
     * @return bool
     */
    private static function on_site($url) {
        $host = strtolower((string) preg_replace(self::WWW_PREFIX, '', (string) wp_parse_url((string) $url, PHP_URL_HOST)));
        $home = strtolower((string) preg_replace(self::WWW_PREFIX, '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        return $host !== '' && $host === $home;
    }

    /**
     * An address's path (the queue's page), '' when it is not on the site.
     *
     * @param string $url Address.
     * @return string
     */
    private static function path_of($url) {
        if (!self::on_site($url)) {
            return '';
        }
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        return $path !== '' ? $path : '/';
    }

    /**
     * A page's address on this site, or '' for a path that is not one.
     *
     * @param string $path Path.
     * @return string
     */
    public static function url($path) {
        $info = $path !== '' && $path[0] === '/' ? wp_parse_url(home_url('/')) : null;
        if (!is_array($info) || !isset($info['scheme'], $info['host'])) {
            return '';
        }
        return esc_url_raw($info['scheme'] . '://' . $info['host'] . (isset($info['port']) ? ':' . $info['port'] : '') . $path);
    }

    /**
     * Load the classes used.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-indexation.php';
    }

    /**
     * Take the run lock.
     *
     * @return bool Whether this request has it.
     */
    private static function lock() {
        if (add_option(self::LOCK_OPTION, time(), '', false)) {
            return true;
        }
        if ((int) get_option(self::LOCK_OPTION, 0) > time() - self::LOCK_TIME) {
            return false;
        }
        delete_option(self::LOCK_OPTION);
        return add_option(self::LOCK_OPTION, time(), '', false);
    }

    /**
     * Give the run lock back.
     */
    private static function unlock() {
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Run with the lock (WP-CLI): inspect now.
     *
     * @param int                    $budget Seconds (0: no limit).
     * @param array<int,string>|null $pages  Path id => path, or null for those due.
     * @return array<string,mixed>|WP_Error
     */
    public static function run_now($budget, $pages = null) {
        if (!self::lock()) {
            return new WP_Error('seoprostats_inspections_busy', __('Inspections are running. Try again in a few minutes.', 'seoprostats'));
        }
        try {
            return self::inspect_due((int) $budget, null, $pages);
        } finally {
            self::unlock();
        }
    }
}
