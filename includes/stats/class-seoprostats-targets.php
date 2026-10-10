<?php
/**
 * Search targets (Search → Targets): the searches the site chose to win
 * and the page meant for each, with how search treats them now.
 *
 * A target is a query (stored lower case, as engines report queries), the
 * page meant for it (or none chosen yet), a priority (0–100), a status
 * (candidate, targeted, live, won, retired) and where it came from. People
 * and agents import them from a simple list (query, address, priority,
 * status) as CSV or tab-separated text, JSON, or the aidevops search
 * targets table (TOON: phrase, target_url, priority, status). Rows are
 * checked one by one: a row without query text, with an address that is
 * not on this site, or with a priority or status that cannot be read is
 * skipped and reported, never guessed.
 *
 * The report gives each target, for a period (cut at the newest search day
 * and to its newest 91 days): the query's clicks, impressions, CTR and
 * position on any page, the page search shows most for it, and the page
 * meant for it with its own figures, as one state:
 *
 * - ranking: the page meant for it is the page search shows most (or
 *   search gave no page for the query);
 * - wrong_page: another page is;
 * - no_page: no page is chosen yet (the page search shows is given);
 * - not_shown: no impressions in the period.
 *
 * The decision queue (SEOProStats_Queue, kind target) lists a target in
 * an open status (candidate, targeted, live) shown with the wrong page,
 * and a high-priority target (HIGH_PRIORITY or more) in striking distance
 * (positions 4–20) with its own page or none chosen.
 *
 * Reads: targets by its primary key (small: MAX_TARGETS rows at most),
 * then gsc_queries and gsc_pairs by query_day for those queries only.
 * Design: docs/seo-loop.md → Search targets.
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

final class SEOProStats_Targets {

    /** Statuses: code (the status column) => name. Codes never change meaning. */
    const STATUSES = array(
        1 => 'candidate',
        2 => 'targeted',
        3 => 'live',
        4 => 'won',
        5 => 'retired',
    );

    /** Other names a status is imported under. */
    const STATUS_ALIASES = array('active' => 'targeted');

    /** Statuses the decision queue works on. */
    const OPEN = array('candidate', 'targeted', 'live');

    /** Status lists the report can ask for: every target, the open ones, or one status. */
    const FILTERS = array('all', 'open', 'candidate', 'targeted', 'live', 'won', 'retired');

    /**
     * Where a target came from: code (the source column) => name. A list,
     * the aidevops table, the demo, an SEO plugin's focus keywords
     * (SEOProStats_Target_Sources), or a search report's row.
     */
    const SOURCES = array(
        1 => 'list',
        2 => 'aidevops',
        3 => 'demo',
        4 => 'seo-plugin',
        5 => 'search',
    );

    /** How search treats a target now. */
    const STATES = array('ranking', 'wrong_page', 'no_page', 'not_shown');

    /** Priority: when none is given, words imported as numbers, and the least a striking item needs. */
    const PRIORITY       = 50;
    const PRIORITY_WORDS = array('high' => 80, 'medium' => 50, 'low' => 20);
    const HIGH_PRIORITY  = 70;

    /** Targets kept at most; rows and bytes an import reads at most. */
    const MAX_TARGETS = 1000;
    const MAX_ROWS    = 5000;
    const MAX_BYTES   = 1048576;

    /** Rows listed: default and most. */
    const LIMIT     = 100;
    const MAX_LIMIT = 1000;

    /** Ids per read. */
    const CHUNK = 500;

    /** What the report depends on besides search data (autoload off): changed at every write. */
    const OPTION = 'seoprostats_targets';

    /** Column names an imported list may use, by field. */
    const COLUMNS = array(
        'query'    => array('query', 'phrase', 'keyword', 'search', 'question'),
        'page'     => array('page', 'address', 'url', 'target_url', 'path', 'target'),
        'priority' => array('priority'),
        'status'   => array('status'),
        'allintitle' => array('allintitle'),
        'volume' => array('volume'),
    );

    // ------------------------------------------------------------------
    // The report.

    /**
     * The targets report.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request() (range, compare, limit, offset).
     * @param string              $engine google or bing.
     * @param string              $status One of FILTERS.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $status = 'all') {
        self::load();
        $status = $status === '' ? 'all' : (string) $status;
        if (!in_array($status, self::FILTERS, true)) {
            /* translators: %s: list of statuses */
            return new WP_Error('seoprostats_targets_status', sprintf(__('The status is one of: %s.', 'seoprostats'), implode(', ', self::FILTERS)), array('status' => 400));
        }
        $engine = SEOProStats_Search::engine_name($engine);
        $live   = SEOProStats_Schema::set() === 'live';
        $all    = SEOProStats_Query::cached('targets', self::cache_key($req, $engine), static function () use ($req, $engine) {
            return self::build($req, $engine);
        });
        $list   = self::with_status((array) $all['list'], $status);
        unset($all['list']);
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($list as $row) {
            ++$counts[$row['state']];
        }
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        // Editor links depend on the viewer, so they are added outside the shared cache.
        $rows   = self::with_edit_urls(array_slice($list, $offset, $limit));
        return $all + array(
            'connected' => !$live || SEOProStats_Search::connected($engine),
            'status'    => $status,
            'counts'    => $counts,
            'rows'      => $rows,
            'total'     => count($list),
            'more'      => $offset + $limit < count($list),
        );
    }

    /**
     * The cache key of the shared part of the report.
     *
     * @param array<string,mixed> $req    Request.
     * @param string              $engine Engine name.
     * @return array<string,mixed>
     */
    private static function cache_key(array $req, $engine) {
        return array(
            'range'   => isset($req['range']) ? $req['range'] : '',
            'from'    => isset($req['from']) ? $req['from'] : '',
            'to'      => isset($req['to']) ? $req['to'] : '',
            'compare' => isset($req['compare']) ? $req['compare'] : 'none',
            'engine'  => $engine,
            'imports' => SEOProStats_Search::version(),
            'targets' => self::version(),
        );
    }

    /**
     * The rows with a status: all, open (any of OPEN) or one status.
     *
     * @param array<int,array<string,mixed>> $list   Rows.
     * @param string                         $status One of FILTERS.
     * @return array<int,array<string,mixed>>
     */
    private static function with_status(array $list, $status) {
        return array_values(array_filter($list, static function ($row) use ($status) {
            return $status === 'all' || $row['status'] === $status || ($status === 'open' && in_array($row['status'], self::OPEN, true));
        }));
    }

    /**
     * Rows with editor links on their pages that are posts.
     *
     * @param array<int,array<string,mixed>> $rows Rows.
     * @return array<int,array<string,mixed>>
     */
    private static function with_edit_urls(array $rows) {
        foreach ($rows as &$row) {
            foreach (array('page', 'shown') as $field) {
                if ($row[$field] !== null && (int) $row[$field]['post_id']) {
                    $row[$field] = SEOProStats_Clicks::with_edit_url($row[$field]);
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * The shared part of the answer, with every target's row (cached).
     *
     * @param array<string,mixed> $req  Request.
     * @param string              $name Engine name.
     * @return array<string,mixed>
     */
    private static function build(array $req, $name) {
        $engine  = SEOProStats_Search::ENGINES[$name];
        $bounds  = SEOProStats_Search::bounds($engine);
        $range   = SEOProStats_Query::range($req);
        $weekly  = in_array($name, SEOProStats_Search::WEEKLY, true);
        $full    = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds, $weekly) : null;
        $now     = $full ? SEOProStats_Opportunities::cut($full) : null;
        $days    = $now ? SEOProStats_Search::length($now) : 0;
        $targets = self::stored();
        $answer  = array(
            'engine'   => $name,
            'engines'  => SEOProStats_Search::engines(),
            'range'    => SEOProStats_Query::range_out($now ? $now : $range),
            'days'     => $days,
            'cut'      => $full && $now && SEOProStats_Search::length($full) > $days,
            'through'  => $bounds['to'],
            'first'    => $bounds['from'],
            'compare'  => null,
            'rules'    => array(
                'priority'      => self::PRIORITY,
                'high_priority' => self::HIGH_PRIORITY,
                'striking_from' => SEOProStats_Opportunities::STRIKING_FROM,
                'striking_to'   => SEOProStats_Opportunities::STRIKING_TO,
                'max_targets'   => self::MAX_TARGETS,
            ),
            'statuses' => array_fill_keys(array_values(self::STATUSES), 0),
            'list'     => array(),
        );
        foreach ($targets as $target) {
            ++$answer['statuses'][$target['status']];
        }
        if (!$targets) {
            return $answer;
        }
        $ids     = array_keys($targets);
        $totals  = $now ? self::query_sums($engine, $ids, $now) : array();
        $pairs   = $now ? self::pair_sums($engine, $ids, $now) : array();
        $then    = array();
        $compare = isset($req['compare']) ? (string) $req['compare'] : 'none';
        $other   = $now ? SEOProStats_Query::compare_range(array('key' => 'custom') + $now, $compare) : null;
        $before  = $other ? SEOProStats_Search::days($other, array('from' => '', 'to' => ''), $weekly) : null;
        if ($before) {
            $then              = self::query_sums($engine, $ids, $before);
            $answer['compare'] = array('range' => SEOProStats_Query::range_out($before));
        }
        $text           = SEOProStats_Query::texts(self::text_ids($ids, $targets, $pairs));
        $answer['list'] = self::target_rows($targets, array('now' => $totals, 'pairs' => $pairs, 'then' => $then), $before !== null, $text);
        return $answer;
    }

    /**
     * Dictionary ids whose texts the rows need: the queries and every
     * page named.
     *
     * @param int[]                                            $ids     Query ids.
     * @param array<int,array<string,mixed>>                   $targets Stored targets.
     * @param array<int,array<int,array{c:int,i:int,p:int}>>   $pairs   Query id => path id => sums.
     * @return int[]
     */
    private static function text_ids(array $ids, array $targets, array $pairs) {
        $text_ids = $ids;
        foreach ($targets as $target) {
            $text_ids[] = $target['path_id'];
        }
        foreach ($pairs as $pages) {
            $text_ids = array_merge($text_ids, array_keys($pages));
        }
        return array_values(array_unique(array_filter($text_ids)));
    }

    /**
     * Every target's row, highest priority first, then most impressions.
     *
     * @param array<int,array<string,mixed>> $targets  Stored targets.
     * @param array<string,array<int,mixed>> $sums     now (query sums), pairs (sums by page) and then (comparison sums), by query id.
     * @param bool                           $compared Whether there is a comparison period.
     * @param array<int,string>              $text     Dictionary texts.
     * @return array<int,array<string,mixed>>
     */
    private static function target_rows(array $targets, array $sums, $compared, array $text) {
        $list = array();
        foreach ($targets as $query_id => $target) {
            $now    = isset($sums['now'][$query_id]) ? $sums['now'][$query_id] : array('c' => 0, 'i' => 0, 'p' => 0);
            $list[] = self::row($target, isset($text[$query_id]) ? (string) $text[$query_id] : '', $now, isset($sums['pairs'][$query_id]) ? $sums['pairs'][$query_id] : array(), isset($sums['then'][$query_id]) ? $sums['then'][$query_id] : null, $compared, $text);
        }
        usort($list, static function ($a, $b) {
            return array($b['priority'], $b['impressions'], $a['query']) <=> array($a['priority'], $a['impressions'], $b['query']);
        });
        return $list;
    }

    /**
     * One target's row.
     *
     * @param array<string,mixed>                $target   Stored target.
     * @param string                             $query    Its query.
     * @param array{c:int,i:int,p:int}           $sums     The query's sums on any page.
     * @param array<int,array{c:int,i:int,p:int}> $pages    The query's sums by page.
     * @param array{c:int,i:int,p:int}|null      $then     The query's sums in the comparison period.
     * @param bool                               $compared Whether there is a comparison period.
     * @param array<int,string>                  $text     Dictionary texts.
     * @return array<string,mixed>
     */
    private static function row(array $target, $query, array $sums, array $pages, $then, $compared, array $text) {
        $metrics  = SEOProStats_Search::metrics($sums['c'], $sums['i'], $sums['p']);
        $shown_id = self::shown_page($pages);
        $meant    = (int) $target['path_id'];
        $state    = self::target_state($metrics['impressions'], $meant, $shown_id);
        list($then_position, $then_clicks) = self::then_figures($then, $compared);
        $meant_page = $meant ? self::page($meant, $text, $pages[$meant] ?? null, $metrics['impressions']) : null;
        return array(
            'query'         => $query,
            'allintitle'    => $target['allintitle'],
            'volume'        => $target['volume'],
            'measured'      => array('allintitle' => $target['allintitle_measured'] ?: null, 'volume' => $target['volume_measured'] ?: null),
            'kgr'           => self::kgr($target['allintitle'], $target['volume']),
            'kgr_band'      => self::kgr_band($target['allintitle'], $target['volume']),
            'priority'      => (int) $target['priority'],
            'status'        => (string) $target['status'],
            'source'        => (string) $target['source'],
            'state'         => $state,
            'band'          => self::band($metrics['impressions'] ? (float) $metrics['position'] : null),
            'clicks'        => $metrics['clicks'],
            'impressions'   => $metrics['impressions'],
            'ctr'           => $metrics['ctr'],
            'position'      => $metrics['impressions'] ? $metrics['position'] : null,
            'then_position' => $then_position,
            'then_clicks'   => $then_clicks,
            'page'          => $meant_page,
            'shown'         => $shown_id ? self::page($shown_id, $text, $pages[$shown_id], $metrics['impressions']) : null,
            'pages'         => count($pages),
            'updated'       => wp_date('c', (int) $target['updated']),
        );
    }

    /**
     * The page search shows most for the query: most impressions, then
     * clicks, then the lowest path id; 0 for none.
     *
     * @param array<int,array{c:int,i:int,p:int}> $pages The query's sums by page.
     * @return int Path id.
     */
    private static function shown_page(array $pages) {
        $shown_id = 0;
        foreach ($pages as $path_id => $page) {
            if (!$shown_id || array($page['i'], $page['c'], -$path_id) > array($pages[$shown_id]['i'], $pages[$shown_id]['c'], -$shown_id)) {
                $shown_id = (int) $path_id;
            }
        }
        return $shown_id;
    }

    /**
     * A target's state: not shown, no page chosen, another page shown, or
     * ranking with the page meant.
     *
     * @param int $impressions The query's impressions.
     * @param int $meant       The path id meant, 0 for none.
     * @param int $shown_id    shown_page().
     * @return string One of STATES.
     */
    private static function target_state($impressions, $meant, $shown_id) {
        if (!$impressions) {
            return 'not_shown';
        }
        if (!$meant) {
            return 'no_page';
        }
        // Without pages for the query (search can leave them out), no other page is known to rank.
        return $shown_id && $shown_id !== $meant ? 'wrong_page' : 'ranking';
    }

    /**
     * The query's position and clicks in the comparison period: null
     * without one; no position, and 0 clicks, when it was not shown.
     *
     * @param array{c:int,i:int,p:int}|null $then     The query's sums in the comparison period.
     * @param bool                          $compared Whether there is a comparison period.
     * @return array{0:float|null,1:int|null}
     */
    private static function then_figures($then, $compared) {
        if (!$compared) {
            return array(null, null);
        }
        $metrics = $then ? SEOProStats_Search::metrics($then['c'], $then['i'], $then['p']) : null;
        if (!$metrics) {
            return array(null, 0);
        }
        return array($metrics['impressions'] ? $metrics['position'] : null, $metrics['clicks']);
    }

    /**
     * A page of a row with its figures for the query.
     *
     * @param int                           $path_id     Path id.
     * @param array<int,string>             $text        Dictionary texts.
     * @param array{c:int,i:int,p:int}|null $sums        Its sums for the query, or null when search did not show it.
     * @param int                           $impressions The query's impressions.
     * @return array<string,mixed>
     */
    private static function page($path_id, array $text, $sums, $impressions) {
        $path = isset($text[$path_id]) ? (string) $text[$path_id] : '';
        $info = SEOProStats_Clicks::page_info($path) ?: array('path' => $path, 'url' => '', 'post_id' => 0, 'edit_url' => null);
        $m    = $sums ? SEOProStats_Search::metrics($sums['c'], $sums['i'], $sums['p']) : null;
        return array('path_id' => (int) $path_id) + $info + array(
            'clicks'      => $m ? $m['clicks'] : 0,
            'impressions' => $m ? $m['impressions'] : 0,
            'position'    => $m && $m['impressions'] ? $m['position'] : null,
            'share'       => $m && $impressions ? round($m['impressions'] / $impressions, 3) : null,
        );
    }

    /**
     * Where a position falls: top (1–3), striking (4–20) or beyond, as
     * Opportunities rounds positions; null when not shown.
     *
     * @param float|null $position Position.
     * @return string|null
     */
    private static function band($position) {
        if ($position === null) {
            return null;
        }
        $place = (int) round($position);
        if ($place < SEOProStats_Opportunities::STRIKING_FROM) {
            return 'top';
        }
        return $place <= SEOProStats_Opportunities::STRIKING_TO ? 'striking' : 'beyond';
    }

    /**
     * Sums by query in a period, by query_day, the queries given only.
     *
     * @param int                 $engine Engine.
     * @param int[]               $ids    Query ids.
     * @param array<string,mixed> $days   From SEOProStats_Search::days().
     * @return array<int,array{c:int,i:int,p:int}>
     */
    private static function query_sums($engine, array $ids, array $days) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its query_day key, the targets' queries only; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`query_day`) WHERE query_id IN ($holders) AND day >= %s AND day <= %s AND engine = %d GROUP BY query_id ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('gsc_queries')), $chunk, array((string) $days['day_from'], (string) $days['day_to'], (int) $engine))), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['q']] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
            }
        }
        return $out;
    }

    /**
     * Sums by query and page in a period, by query_day, the queries given only.
     *
     * @param int                 $engine Engine.
     * @param int[]               $ids    Query ids.
     * @param array<string,mixed> $days   From SEOProStats_Search::days().
     * @return array<int,array<int,array{c:int,i:int,p:int}>> Query id => path id => sums.
     */
    private static function pair_sums($engine, array $ids, array $days) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its query_day key, the targets' queries only; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT query_id AS q, path_id AS pg, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`query_day`) WHERE query_id IN ($holders) AND day >= %s AND day <= %s AND engine = %d GROUP BY query_id, path_id ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $chunk, array((string) $days['day_from'], (string) $days['day_to'], (int) $engine))), ARRAY_A);
            foreach ($rows as $row) {
                if ((int) $row['i'] > 0) {
                    $out[(int) $row['q']][(int) $row['pg']] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
                }
            }
        }
        return $out;
    }

    /**
     * Every stored target, by query id (primary key).
     *
     * @return array<int,array{path_id:int,priority:int,status:string,source:string,created:int,updated:int}>
     */
    private static function stored() {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own small table, by its primary key.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT query_id, path_id, priority, status, source, created, updated, allintitle, volume, allintitle_measured, volume_measured FROM %i FORCE INDEX (`PRIMARY`) ORDER BY query_id LIMIT %d', SEOProStats_Schema::table('targets'), self::MAX_TARGETS), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['query_id']] = array(
                'path_id'  => (int) $row['path_id'],
                'priority' => (int) $row['priority'],
                'status'   => isset(self::STATUSES[(int) $row['status']]) ? self::STATUSES[(int) $row['status']] : 'targeted',
                'source'   => isset(self::SOURCES[(int) $row['source']]) ? self::SOURCES[(int) $row['source']] : 'list',
                'created'  => (int) $row['created'],
                'updated'  => (int) $row['updated'],
                'allintitle' => $row['allintitle'] === null ? null : (int) $row['allintitle'],
                'volume' => $row['volume'] === null ? null : (int) $row['volume'],
                'allintitle_measured' => (string) $row['allintitle_measured'],
                'volume_measured' => (string) $row['volume_measured'],
            );
        }
        return $out;
    }

    /**
     * Every target's query with its page, status and priority, without
     * search figures: by query (lower case, as stored). A primary-key read
     * of the targets and their texts, for the searches reports mark as
     * targets and the SEO plugin suggestions.
     *
     * @return array<string,array{query:string,page:string,status:string,priority:int}>
     */
    public static function listed() {
        self::load();
        $targets = self::stored();
        if (!$targets) {
            return array();
        }
        $text = SEOProStats_Query::texts(array_values(array_unique(array_filter(array_merge(array_keys($targets), array_column($targets, 'path_id'))))));
        $out  = array();
        foreach ($targets as $query_id => $target) {
            $query = isset($text[$query_id]) ? (string) $text[$query_id] : '';
            if ($query === '') {
                continue;
            }
            $out[$query] = array(
                'query'    => $query,
                'page'     => $target['path_id'] && isset($text[$target['path_id']]) ? (string) $text[$target['path_id']] : '',
                'status'   => (string) $target['status'],
                'priority' => (int) $target['priority'],
            );
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    // ------------------------------------------------------------------
    // Importing and deleting.

    /**
     * Rows of a list given as text: the aidevops search targets table
     * (TOON, its first table with a phrase or query column), JSON (a list,
     * or an object with targets), or CSV or tab-separated text (a header
     * row naming the columns, or query, address, priority, status in that
     * order).
     *
     * @param string $text Text.
     * @return array{format:string,rows:array<int,array<string,mixed>>}|WP_Error
     */
    public static function parse($text) {
        $text = (string) $text;
        if (strlen($text) > self::MAX_BYTES) {
            /* translators: %s: size such as 1 MB */
            return self::error('seoprostats_targets_size', sprintf(__('The list is larger than %s.', 'seoprostats'), size_format(self::MAX_BYTES)));
        }
        $text  = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $trim  = trim($text);
        if ($trim === '') {
            return self::error('seoprostats_targets_empty', __('The list is empty.', 'seoprostats'));
        }
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = is_array($lines) ? $lines : array();
        if (self::has_toon($lines)) {
            $rows = self::parse_toon($lines);
            return is_wp_error($rows) ? $rows : array('format' => 'toon', 'rows' => $rows);
        }
        if ($trim[0] === '[' || $trim[0] === '{') {
            return self::parse_json($trim);
        }
        return array('format' => 'csv', 'rows' => self::parse_csv($lines));
    }

    /**
     * Whether any line is a TOON table header.
     *
     * @param string[] $lines Lines.
     * @return bool
     */
    private static function has_toon(array $lines) {
        foreach ($lines as $line) {
            if (preg_match('/^[A-Za-z_][\w.-]*\[\d+[|\t,]?\]\{.*\}:\s*$/', $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rows of a JSON list, or of an object's targets; a text alone is a
     * row with that query.
     *
     * @param string $json JSON, trimmed.
     * @return array{format:string,rows:array<int,array<string,mixed>>}|WP_Error
     */
    private static function parse_json($json) {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return self::error('seoprostats_targets_json', __('The list looks like JSON but cannot be read.', 'seoprostats'));
        }
        if (isset($data['targets']) && is_array($data['targets'])) {
            $data = $data['targets'];
        }
        $rows = array();
        foreach (array_values($data) as $item) {
            if (!is_array($item)) {
                $item = array('query' => is_scalar($item) ? (string) $item : '');
            }
            $rows[] = $item;
        }
        return array('format' => 'json', 'rows' => $rows);
    }

    /**
     * Rows of a TOON document's first targets table.
     *
     * @param string[] $lines Lines.
     * @return array<int,array<string,string>>|WP_Error
     */
    private static function parse_toon(array $lines) {
        foreach ($lines as $index => $line) {
            $header = self::toon_header($line);
            if ($header === null || !array_intersect($header[1], self::COLUMNS['query'])) {
                continue;
            }
            return self::toon_rows($lines, $index + 1, $header[1], $header[0]);
        }
        return self::error('seoprostats_targets_toon', __('The TOON document has no table with a phrase or query column.', 'seoprostats'));
    }

    /**
     * A TOON table header's delimiter and fields; null for another line.
     *
     * @param string $line Line.
     * @return array{0:string,1:string[]}|null
     */
    private static function toon_header($line) {
        if (!preg_match('/^([A-Za-z_][\w.-]*)\[(\d+)([|\t,]?)\]\{(.*)\}:\s*$/', $line, $m)) {
            return null;
        }
        $delim  = $m[3] !== '' ? $m[3] : ',';
        $fields = $m[4] !== '' ? array_map(array(__CLASS__, 'toon_token'), explode($delim, $m[4])) : array();
        return array($delim, $fields);
    }

    /**
     * A TOON table's rows: the indented lines after its header, one over
     * MAX_ROWS at most.
     *
     * @param string[] $lines  Lines.
     * @param int      $from   The first row's line.
     * @param string[] $fields Fields.
     * @param string   $delim  Delimiter.
     * @return array<int,array<string,string>>|WP_Error
     */
    private static function toon_rows(array $lines, $from, array $fields, $delim) {
        $count = count($lines);
        $rows  = array();
        for ($at = $from; $at < $count && strpos($lines[$at], '  ') === 0; $at++) {
            $cells = self::toon_cells(substr($lines[$at], 2), $delim);
            if ($cells === null || count($cells) !== count($fields)) {
                /* translators: %d: row number */
                return self::error('seoprostats_targets_toon', sprintf(__('Row %d of the targets table cannot be read.', 'seoprostats'), count($rows) + 1));
            }
            $row = array();
            foreach ($fields as $column => $field) {
                $row[$field] = $cells[$column];
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    /**
     * A TOON row's cells, quoted cells unescaped; null when malformed.
     *
     * @param string $line  Row without its indent.
     * @param string $delim Delimiter.
     * @return string[]|null
     */
    private static function toon_cells($line, $delim) {
        $sep     = preg_quote($delim, '/');
        $pattern = '/\G[ ]*("(?:[^"\\\\]|\\\\.)*"|[^"' . $sep . ']*?)[ ]*(' . $sep . '|$)/';
        $cells   = array();
        $offset  = 0;
        $length  = strlen($line);
        while (true) {
            if (!preg_match($pattern, $line, $m, 0, $offset)) {
                return null;
            }
            $cells[] = self::toon_token($m[1]);
            if ($m[2] === '' || $offset >= $length) {
                return $cells;
            }
            $offset += strlen($m[0]);
        }
    }

    /**
     * One TOON value as text ('' for empty or null).
     *
     * @param string $token Token.
     * @return string
     */
    private static function toon_token($token) {
        $token = trim((string) $token);
        if (strlen($token) >= 2 && $token[0] === '"' && substr($token, -1) === '"') {
            return (string) preg_replace_callback('/\\\\(.)/', static function ($m) {
                $map = array('n' => "\n", 'r' => "\r", 't' => "\t");
                return isset($map[$m[1]]) ? $map[$m[1]] : $m[1];
            }, substr($token, 1, -1));
        }
        return $token === 'null' ? '' : $token;
    }

    /**
     * Rows of CSV or tab-separated text: by the header row's names, or
     * query, address, priority, status in that order.
     *
     * @param string[] $lines Lines.
     * @return array<int,array<string,string>>
     */
    private static function parse_csv(array $lines) {
        $lines = array_values(array_filter($lines, static function ($line) {
            return trim($line) !== '' && strpos(ltrim($line), '#') !== 0;
        }));
        if (!$lines) {
            return array();
        }
        $delim  = strpos($lines[0], "\t") !== false ? "\t" : ',';
        $first  = array_map(static function ($cell) {
            return strtolower(trim((string) $cell));
        }, str_getcsv($lines[0], $delim, '"', ''));
        $fields = array_intersect($first, self::COLUMNS['query']) ? $first : array('query', 'page', 'priority', 'status');
        $start  = $fields === $first ? 1 : 0;
        $rows   = array();
        $count  = count($lines);
        // One row over the limit tells the caller there were more.
        $last   = min($count, $start + self::MAX_ROWS + 1);
        for ($i = $start; $i < $last; $i++) {
            $cells = str_getcsv($lines[$i], $delim, '"', '');
            $row   = array();
            foreach ($fields as $n => $field) {
                $row[$field] = isset($cells[$n]) ? trim((string) $cells[$n]) : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Import targets: each row checked, valid ones added or updated (by
     * query), the others reported. With $replace, targets not in the
     * import are deleted. With $only_new, searches already listed are
     * skipped (exists) and left as they are, so adding a search from a
     * report never changes a target someone set. Rows over MAX_TARGETS are
     * skipped.
     *
     * @param array<int,mixed> $rows     Rows: query (or phrase), page (or address, url, target_url), priority, status.
     * @param string           $source   One of SOURCES.
     * @param bool             $replace  Delete targets not in the import.
     * @param bool             $only_new Add new searches only; leave listed ones as they are.
     * @return array<string,mixed>|WP_Error added, updated, removed, skipped (row, query, reason, message), total.
     */
    public static function import(array $rows, $source = 'list', $replace = false, $only_new = false) {
        self::load();
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return self::error('seoprostats_targets_failed', __('The targets could not be saved.', 'seoprostats'), 500);
        }
        $code = array_search((string) $source, self::SOURCES, true);
        $code = $code === false ? 1 : (int) $code;
        if (count($rows) > self::MAX_ROWS) {
            /* translators: %d: most rows */
            return self::error('seoprostats_targets_rows', sprintf(__('An import has at most %d rows.', 'seoprostats'), self::MAX_ROWS));
        }
        list($valid, $skipped) = self::check_rows($rows);
        $now      = time();
        $existing = self::stored();
        $removed  = 0;
        if ($replace && !$only_new && $existing) {
            list($removed, $existing) = self::remove_others($existing, $valid);
        }
        $saved = self::save_valid($valid, $existing, array('code' => $code, 'now' => $now, 'only_new' => (bool) $only_new), $skipped);
        if (is_wp_error($saved)) {
            return $saved;
        }
        list($added, $updated) = $saved;
        self::touch();
        usort($skipped, static function ($a, $b) {
            return $a['row'] <=> $b['row'];
        });
        return array(
            'added'   => $added,
            'updated' => $updated,
            'removed' => $removed,
            'skipped' => $skipped,
            'total'   => count(self::stored()),
        );
    }

    /**
     * Rows checked: the valid ones by query (with their row number), and
     * the skipped ones (the first of a query's rows counts).
     *
     * @param array<int,mixed> $rows Rows.
     * @return array{0:array<string,array<string,mixed>>,1:array<int,array<string,mixed>>} Valid, skipped.
     */
    private static function check_rows(array $rows) {
        $valid   = array();
        $skipped = array();
        foreach (array_values($rows) as $n => $row) {
            $checked = self::check(is_array($row) ? $row : array());
            if (is_string($checked)) {
                $skipped[] = self::skip($n + 1, is_array($row) ? self::field($row, 'query') : '', $checked);
                continue;
            }
            if (isset($valid[$checked['query']])) {
                $skipped[] = self::skip($n + 1, $checked['query'], 'duplicate');
                continue;
            }
            $valid[$checked['query']] = $checked + array('row' => $n + 1);
        }
        return array($valid, $skipped);
    }

    /**
     * Delete the targets not in an import.
     *
     * @param array<int,array<string,mixed>>    $existing Stored targets by query id.
     * @param array<string,array<string,mixed>> $valid    check_rows()'s valid rows.
     * @return array{0:int,1:array<int,array<string,mixed>>} Targets deleted, and the targets kept.
     */
    private static function remove_others(array $existing, array $valid) {
        $keep = $valid ? SEOProStats_Dict::find(SEOProStats_Schema::DICT_QUERY, array_keys($valid)) : array();
        $gone = array_values(array_diff(array_keys($existing), array_map('intval', $keep)));
        return array(self::delete_ids($gone), array_diff_key($existing, array_flip($gone)));
    }

    /**
     * Save valid rows, added or updated by query, while there is room.
     *
     * @param array<string,array<string,mixed>> $valid    check_rows()'s valid rows.
     * @param array<int,array<string,mixed>>    $existing Stored targets by query id.
     * @param array{code:int,now:int,only_new:bool} $how  Source code (SOURCES), Unix time, and whether listed searches are left as they are.
     * @param array<int,array<string,mixed>>    $skipped  Skipped rows; added to.
     * @return array{0:int,1:int}|WP_Error Added, updated.
     */
    private static function save_valid(array $valid, array $existing, array $how, array &$skipped) {
        list($queries, $path_id) = self::valid_ids($valid);
        $room    = self::MAX_TARGETS - count($existing);
        $added   = 0;
        $updated = 0;
        foreach ($valid as $query => $target) {
            $query_id = self::dict_id($queries, $query);
            if (!$query_id) {
                $skipped[] = self::skip($target['row'], $query, 'query');
                continue;
            }
            $known = isset($existing[$query_id]);
            if ($known && $how['only_new']) {
                $skipped[] = self::skip($target['row'], $query, 'exists');
                continue;
            }
            if (!$known && $room <= 0) {
                $skipped[] = self::skip($target['row'], $query, 'limit');
                continue;
            }
            $page = $target['page'] !== '' ? self::dict_id($path_id, $target['page']) : 0;
            if (!self::upsert($query_id, $page, $target, $how['code'], $how['now'])) {
                self::touch();
                return self::error('seoprostats_targets_failed', __('The targets could not be saved.', 'seoprostats'), 500);
            }
            if ($known) {
                ++$updated;
            } else {
                ++$added;
                --$room;
            }
        }
        return array($added, $updated);
    }

    /**
     * Dictionary ids of the valid rows' queries and of their pages (made
     * when new).
     *
     * @param array<string,array<string,mixed>> $valid check_rows()'s valid rows.
     * @return array{0:array<string,int>,1:array<string,int>} Query ids and path ids by text.
     */
    private static function valid_ids(array $valid) {
        $queries = $valid ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_QUERY, array_keys($valid)) : array();
        $paths   = array_filter(array_column($valid, 'page'));
        $path_id = $paths ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_values($paths)) : array();
        return array($queries, $path_id);
    }

    /**
     * A text's id from SEOProStats_Dict::ids(), or 0.
     *
     * @param array<string,int> $ids  Text => id.
     * @param string            $text Text.
     * @return int
     */
    private static function dict_id(array $ids, $text) {
        $text = SEOProStats_Dict::clean($text);
        return isset($ids[$text]) ? (int) $ids[$text] : 0;
    }

    /**
     * Add or update one target by its query, with its measurements.
     *
     * @param int                 $query_id Query id.
     * @param int                 $page     Path id, 0 for none.
     * @param array<string,mixed> $target   check()'s row.
     * @param int                 $code     Source code (SOURCES).
     * @param int                 $now      Unix time.
     * @return bool Saved.
     */
    private static function upsert($query_id, $page, array $target, $code, $now) {
        global $wpdb;
        $statuses = array_flip(self::STATUSES);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing our own table by its primary key.
        $saved = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (query_id, path_id, priority, status, source, created, updated, user_id) VALUES (%d, %d, %d, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE path_id = VALUES(path_id), priority = VALUES(priority), status = VALUES(status), source = VALUES(source), updated = VALUES(updated), user_id = VALUES(user_id)',
            SEOProStats_Schema::table('targets'),
            $query_id,
            $page,
            (int) $target['priority'],
            (int) $statuses[$target['status']],
            $code,
            $now,
            $now,
            get_current_user_id()
        ));
        return $saved !== false && self::save_measurements($query_id, $target['measurements']);
    }

    /**
     * A row checked: its query (lower case, as stored), page path ('' for
     * none), priority and status; or why it is skipped.
     *
     * @param array<string,mixed> $row Row.
     * @return array{query:string,page:string,priority:int,status:string,measurements:array<string,mixed>}|string The reason code when skipped.
     */
    private static function check(array $row) {
        $query = self::query_text(self::field($row, 'query'));
        if ($query === '') {
            return 'query';
        }
        $page = self::page_path(self::field($row, 'page'));
        if ($page === null) {
            return 'address';
        }
        $priority = self::priority(self::field($row, 'priority'));
        if ($priority === null) {
            return 'priority';
        }
        $status = strtolower(self::field($row, 'status'));
        if ($status === '') {
            $status = 'targeted';
        }
        $status = self::STATUS_ALIASES[$status] ?? $status;
        if (!in_array($status, self::STATUSES, true)) {
            return 'status';
        }
        $measurements = self::measurements($row);
        if ($measurements === null) {
            return 'measurements';
        }
        return array('query' => $query, 'page' => $page, 'priority' => $priority, 'status' => $status, 'measurements' => $measurements);
    }

    /**
     * A search as targets store it (lower case, as engines report
     * queries); '' when it is not search text.
     *
     * @param string $raw Search as given.
     * @return string
     */
    public static function query_text($raw) {
        self::load();
        $raw   = (string) $raw;
        $query = SEOProStats_Search_Import::query(function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw));
        if ($query === '' || preg_match('/[\x00-\x1f\x7f]/', $query) || (function_exists('mb_check_encoding') && !mb_check_encoding($query, 'UTF-8'))) {
            return '';
        }
        return $query;
    }

    /**
     * Validate supplied research facts; omitted fields keep their earlier facts.
     *
     * @param array<string,mixed> $row Input.
     * @return array<string,mixed>|null Null when invalid.
     */
    private static function measurements(array $row) {
        $out = array();
        foreach (array('allintitle', 'volume') as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $measured = self::measured_field($row, $field);
            if ($measured === null) {
                return null;
            }
            $out += $measured;
        }
        return $out;
    }

    /**
     * One supplied fact with its date (today when not given); empty for
     * an empty cell, which is unknown, not a zero or a deletion; null
     * when invalid. A null count clears the fact.
     *
     * @param array<string,mixed> $row   Input, with the field.
     * @param string              $field allintitle or volume.
     * @return array<string,mixed>|null
     */
    private static function measured_field(array $row, $field) {
        $value = $row[$field];
        if ($value === '') {
            return array();
        }
        if ($value !== null && !self::is_count($value)) {
            return null;
        }
        $date = isset($row[$field . '_measured']) && $row[$field . '_measured'] !== '' ? $row[$field . '_measured'] : wp_date('Y-m-d');
        if (!self::is_measured_date($date)) {
            return null;
        }
        return array(
            $field               => $value === null ? null : (int) $value,
            $field . '_measured' => $value === null ? '' : $date,
        );
    }

    /**
     * Whether a value is a whole count from 0 to 4294967295.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_count($value) {
        return !is_bool($value) && is_scalar($value) && preg_match('/^\d{1,10}$/', (string) $value) && (float) $value <= 4294967295;
    }

    /**
     * Whether a measurement date is a real Y-m-d day, not after today.
     *
     * @param mixed $date Date.
     * @return bool
     */
    private static function is_measured_date($date) {
        return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) && $date <= wp_date('Y-m-d');
    }

    /**
     * Save validated measurements by primary key.
     *
     * @param int $id Query id.
     * @param array<string,mixed> $fields Validated fields.
     * @return bool Saved.
     */
    private static function save_measurements($id, array $fields) {
        global $wpdb;
        if (!$fields) {
            return true;
        }
        $fields['updated'] = time();
        $fields['user_id'] = get_current_user_id();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own bounded targets table, by primary key; wpdb escapes values and handles NULL.
        return $wpdb->update(SEOProStats_Schema::table('targets'), $fields, array('query_id' => $id)) !== false;
    }

    /**
     * Set research facts on an existing target without changing its chosen page.
     *
     * @param string $query Query.
     * @param array<string,mixed> $fields Counts and dates.
     * @return array<string,mixed>|WP_Error Saved fields.
     */
    public static function set($query, array $fields) {
        self::load();
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return self::error('seoprostats_targets_failed', __('The targets could not be saved.', 'seoprostats'), 500);
        }
        $query = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $query), 'UTF-8') : strtolower(trim((string) $query));
        $ids = SEOProStats_Dict::find(SEOProStats_Schema::DICT_QUERY, array($query));
        $id = $ids ? (int) reset($ids) : 0;
        $stored = self::stored();
        $checked = self::measurements($fields);
        if (!$id || !isset($stored[$id]) || !$checked) {
            return self::error('seoprostats_targets_measurements', __('Choose an existing target and give non-negative whole counts and valid measurement dates.', 'seoprostats'));
        }
        if (!self::save_measurements($id, $checked)) {
            return self::error('seoprostats_targets_failed', __('The targets could not be saved.', 'seoprostats'), 500);
        }
        self::touch();
        return array('query' => $query) + $checked;
    }

    /**
     * KGR is defined only for a known count and positive monthly volume up to 250.
     *
     * @param int|null $count Google allintitle count.
     * @param int|null $volume Monthly volume.
     * @return float|null Ratio.
     */
    public static function kgr($count, $volume) {
        return $count !== null && $volume !== null && $volume > 0 && $volume <= 250 ? $count / $volume : null;
    }

    /**
     * Band the unrounded ratio, never the displayed decimal.
     *
     * @param int|null $count Google allintitle count.
     * @param int|null $volume Monthly volume.
     * @return string Band.
     */
    public static function kgr_band($count, $volume) {
        if ($volume !== null && $volume > 250) {
            return 'volume_too_high';
        }
        $ratio = self::kgr($count, $volume);
        if ($ratio === null) {
            return 'unknown';
        }
        if ($ratio < 0.25) {
            return 'good';
        }
        return $ratio <= 1 ? 'possible' : 'crowded';
    }

    /**
     * A field of a row by any of its column names, as trimmed text.
     *
     * @param array<string,mixed> $row   Row.
     * @param string              $field query, page, priority or status.
     * @return string
     */
    private static function field(array $row, $field) {
        foreach (self::COLUMNS[$field] as $name) {
            if (isset($row[$name]) && is_scalar($row[$name]) && trim((string) $row[$name]) !== '') {
                return trim((string) $row[$name]);
            }
        }
        return '';
    }

    /**
     * An address as the path search data stores: '' for none, null when
     * it is not a page of this site (another host, another scheme, or not
     * a path).
     *
     * @param string $address A path such as /pricing/, or an address on this site.
     * @return string|null
     */
    public static function page_path($address) {
        self::load();
        $address = trim((string) $address);
        if ($address === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\*]/', $address) || strlen($address) > SEOProStats_Dict::MAX_LENGTH) {
            return null;
        }
        if ($address[0] === '/') {
            if (strpos($address, '//') === 0) {
                return null;
            }
            $home    = wp_parse_url(home_url('/'));
            if (!is_array($home) || !isset($home['scheme'], $home['host'])) {
                return null;
            }
            $address = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $address;
        }
        $scheme = strtolower((string) wp_parse_url($address, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) {
            return null;
        }
        $path = SEOProStats_Search_Import::path($address);
        return $path === '' || $path[0] !== '/' ? null : $path;
    }

    /**
     * A priority: PRIORITY when empty, 0–100, or high, medium or low.
     *
     * @param string $value Value.
     * @return int|null Null when it cannot be read.
     */
    private static function priority($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return self::PRIORITY;
        }
        if (isset(self::PRIORITY_WORDS[$value])) {
            return self::PRIORITY_WORDS[$value];
        }
        if (!preg_match('/^\d{1,3}(\.\d+)?$/', $value) || (float) $value > 100) {
            return null;
        }
        return (int) round((float) $value);
    }

    /**
     * A skipped row.
     *
     * @param int    $row    Row number (1 for the first data row).
     * @param string $query  Its query as given.
     * @param string $reason query, address, priority, measurements, status, duplicate, limit, exists, clash or unknown.
     * @return array{row:int,query:string,reason:string,message:string}
     */
    public static function skip($row, $query, $reason) {
        $messages = array(
            'exists'    => __('Already a target; left as it is.', 'seoprostats'),
            'clash'     => __('The focus keyword of more than one page: choose its page, then import it as a list.', 'seoprostats'),
            'unknown'   => __('Not a focus keyword of a published page.', 'seoprostats'),
            'query'     => __('No search text.', 'seoprostats'),
            'address'   => __('The address is not a page of this site.', 'seoprostats'),
            'priority'  => __('The priority is not 0 to 100 (or high, medium, low).', 'seoprostats'),
            'measurements' => __('Research counts must be non-negative whole numbers with valid measurement dates.', 'seoprostats'),
            /* translators: %s: list of statuses */
            'status'    => sprintf(__('The status is not one of: %s.', 'seoprostats'), implode(', ', self::STATUSES)),
            'duplicate' => __('The same search is in an earlier row.', 'seoprostats'),
            /* translators: %d: most targets */
            'limit'     => sprintf(__('The site has %d targets, the most it keeps.', 'seoprostats'), self::MAX_TARGETS),
        );
        return array(
            'row'     => (int) $row,
            'query'   => function_exists('mb_substr') ? mb_substr((string) $query, 0, 200) : substr((string) $query, 0, 200),
            'reason'  => $reason,
            'message' => isset($messages[$reason]) ? $messages[$reason] : $reason,
        );
    }

    /**
     * Delete targets by their queries, or every target.
     *
     * @param string[] $queries Queries as given (matched lower case).
     * @param bool     $all     Delete every target instead.
     * @return array{deleted:int,total:int}|WP_Error
     */
    public static function delete(array $queries, $all = false) {
        global $wpdb;
        self::load();
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return self::error('seoprostats_targets_failed', __('The targets could not be saved.', 'seoprostats'), 500);
        }
        if ($all) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- emptying our own small table.
            $deleted = (int) $wpdb->query($wpdb->prepare('DELETE FROM %i', SEOProStats_Schema::table('targets')));
        } else {
            $clean = array();
            foreach ($queries as $query) {
                $text = SEOProStats_Search_Import::query(function_exists('mb_strtolower') ? mb_strtolower((string) $query, 'UTF-8') : strtolower((string) $query));
                if ($text !== '') {
                    $clean[] = $text;
                }
            }
            if (!$clean) {
                return self::error('seoprostats_targets_query', __('Give the searches to delete, or delete every target.', 'seoprostats'));
            }
            $deleted = self::delete_ids(SEOProStats_Dict::find(SEOProStats_Schema::DICT_QUERY, $clean));
        }
        self::touch();
        return array('deleted' => $deleted, 'total' => count(self::stored()));
    }

    /**
     * Delete targets by query id.
     *
     * @param int[] $ids Query ids.
     * @return int Deleted.
     */
    private static function delete_ids(array $ids) {
        global $wpdb;
        $deleted = 0;
        foreach (array_chunk(array_map('intval', $ids), self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $holders holds only placeholders.
            $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE query_id IN ($holders)", array_merge(array(SEOProStats_Schema::table('targets')), $chunk)));
        }
        return $deleted;
    }

    // ------------------------------------------------------------------
    // The decision queue's words.

    /**
     * Why a target is listed, in a sentence.
     *
     * @param string              $finding wrong_page or striking.
     * @param array<string,mixed> $row     The report's row.
     * @param int                 $potential Clicks it could gain.
     * @return string
     */
    public static function why($finding, array $row, $potential) {
        $pos = static function ($value) {
            return number_format_i18n((float) $value, 1);
        };
        if ($finding === 'wrong_page') {
            $shown = $row['shown'];
            $page  = $row['page'];
            $where = $page['position'] !== null
                /* translators: %s: average position */
                ? sprintf(__('position %s', 'seoprostats'), $pos($page['position']))
                : __('not shown for it', 'seoprostats');
            /* translators: 1: search query, 2: the page search shows, 3: its share of the impressions, 4: its position, 5: the page meant for the search, 6: where that page is, e.g. "position 14.2" */
            return sprintf(__('Search shows %2$s for “%1$s” (%3$s of its impressions, position %4$s), not the page meant for it, %5$s (%6$s).', 'seoprostats'), (string) $row['query'], (string) $shown['path'], number_format_i18n(100 * (float) $shown['share'], 0) . '%', $pos($shown['position']), (string) $page['path'], $where);
        }
        /* translators: 1: priority, 2: average position, 3: impressions, 4: clicks it could gain */
        return sprintf(__('A target with priority %1$d ranks %2$s with %3$s impressions: in the top three it could gain about %4$s clicks.', 'seoprostats'), (int) $row['priority'], $pos($row['position']), number_format_i18n((int) $row['impressions']), number_format_i18n((int) $potential));
    }

    /**
     * What to do about a target.
     *
     * @param string              $finding wrong_page or striking.
     * @param array<string,mixed> $row     The report's row.
     * @return string
     */
    public static function todo($finding, array $row) {
        if ($finding === 'wrong_page') {
            /* translators: 1: the page meant for the search, 2: the page search shows */
            return sprintf(__('Make %1$s the clear answer for this search: use its words in the title and headings, and link to it from %2$s with them. If %2$s serves the search better, make it the target instead.', 'seoprostats'), (string) $row['page']['path'], (string) $row['shown']['path']);
        }
        return __('Improve the page for this search and link to it from related pages.', 'seoprostats');
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * What the report depends on besides search data.
     *
     * @return string
     */
    public static function version() {
        return (string) get_option(SEOProStats_Schema::option(self::OPTION), '0');
    }

    /**
     * Record a change, so cached answers are made again.
     */
    private static function touch() {
        update_option(SEOProStats_Schema::option(self::OPTION), sprintf('%.6F', microtime(true)), false);
    }

    /**
     * Delete the current data set's version (demo removal, uninstall).
     */
    public static function reset() {
        delete_option(SEOProStats_Schema::option(self::OPTION));
    }

    /**
     * Load the classes the report and the import use.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-opportunities.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-channels.php';
        require_once __DIR__ . '/class-seoprostats-processor.php';
        require_once __DIR__ . '/class-seoprostats-search-import.php';
    }

    /**
     * An error for a request.
     *
     * @param string $code    Code.
     * @param string $message Message.
     * @param int    $status  HTTP status.
     * @return WP_Error
     */
    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => (int) $status));
    }
}
