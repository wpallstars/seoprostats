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

    /** Where a target came from: code (the source column) => name. */
    const SOURCES = array(
        1 => 'list',
        2 => 'aidevops',
        3 => 'demo',
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
        $key    = array(
            'range'   => isset($req['range']) ? $req['range'] : '',
            'from'    => isset($req['from']) ? $req['from'] : '',
            'to'      => isset($req['to']) ? $req['to'] : '',
            'compare' => isset($req['compare']) ? $req['compare'] : 'none',
            'engine'  => $engine,
            'imports' => SEOProStats_Search::version(),
            'targets' => self::version(),
        );
        $all    = SEOProStats_Query::cached('targets', $key, static function () use ($req, $engine) {
            return self::build($req, $engine);
        });
        $list   = array_values(array_filter((array) $all['list'], static function ($row) use ($status) {
            return $status === 'all' || $row['status'] === $status || ($status === 'open' && in_array($row['status'], self::OPEN, true));
        }));
        unset($all['list']);
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($list as $row) {
            ++$counts[$row['state']];
        }
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $rows   = array_slice($list, $offset, $limit);
        // Editor links depend on the viewer, so they are added outside the shared cache.
        foreach ($rows as &$row) {
            foreach (array('page', 'shown') as $field) {
                if ($row[$field] !== null && (int) $row[$field]['post_id']) {
                    $row[$field] = SEOProStats_Clicks::with_edit_url($row[$field]);
                }
            }
        }
        unset($row);
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
        // Texts of the queries and of every page named.
        $text_ids = $ids;
        foreach ($targets as $target) {
            $text_ids[] = $target['path_id'];
        }
        foreach ($pairs as $pages) {
            $text_ids = array_merge($text_ids, array_keys($pages));
        }
        $text = SEOProStats_Query::texts(array_values(array_unique(array_filter($text_ids))));

        $list = array();
        foreach ($targets as $query_id => $target) {
            $sums = isset($totals[$query_id]) ? $totals[$query_id] : array('c' => 0, 'i' => 0, 'p' => 0);
            $list[] = self::row($target, isset($text[$query_id]) ? (string) $text[$query_id] : '', $sums, isset($pairs[$query_id]) ? $pairs[$query_id] : array(), isset($then[$query_id]) ? $then[$query_id] : null, $before !== null, $text);
        }
        // Highest priority first, then most impressions.
        usort($list, static function ($a, $b) {
            return array($b['priority'], $b['impressions'], $a['query']) <=> array($a['priority'], $a['impressions'], $b['query']);
        });
        $answer['list'] = $list;
        return $answer;
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
        $metrics = SEOProStats_Search::metrics($sums['c'], $sums['i'], $sums['p']);
        // The page search shows most for the query: most impressions, then clicks.
        $shown_id = 0;
        foreach ($pages as $path_id => $page) {
            if (!$shown_id || array($page['i'], $page['c'], -$path_id) > array($pages[$shown_id]['i'], $pages[$shown_id]['c'], -$shown_id)) {
                $shown_id = (int) $path_id;
            }
        }
        $meant = (int) $target['path_id'];
        if (!$metrics['impressions']) {
            $state = 'not_shown';
        } elseif (!$meant) {
            $state = 'no_page';
        } else {
            // Without pages for the query (search can leave them out), no other page is known to rank.
            $state = $shown_id && $shown_id !== $meant ? 'wrong_page' : 'ranking';
        }
        $then_metrics = $then ? SEOProStats_Search::metrics($then['c'], $then['i'], $then['p']) : null;
        $then_clicks  = null;
        if ($compared) {
            $then_clicks = $then_metrics ? $then_metrics['clicks'] : 0;
        }
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
            'then_position' => $compared && $then_metrics && $then_metrics['impressions'] ? $then_metrics['position'] : null,
            'then_clicks'   => $then_clicks,
            'page'          => $meant_page,
            'shown'         => $shown_id ? self::page($shown_id, $text, $pages[$shown_id], $metrics['impressions']) : null,
            'pages'         => count($pages),
            'updated'       => wp_date('c', (int) $target['updated']),
        );
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
        foreach ($lines as $line) {
            if (preg_match('/^[A-Za-z_][\w.-]*\[\d+[|\t,]?\]\{.*\}:\s*$/', $line)) {
                $rows = self::parse_toon($lines);
                return is_wp_error($rows) ? $rows : array('format' => 'toon', 'rows' => $rows);
            }
        }
        if ($trim[0] === '[' || $trim[0] === '{') {
            $data = json_decode($trim, true);
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
        return array('format' => 'csv', 'rows' => self::parse_csv($lines));
    }

    /**
     * Rows of a TOON document's first targets table.
     *
     * @param string[] $lines Lines.
     * @return array<int,array<string,string>>|WP_Error
     */
    private static function parse_toon(array $lines) {
        $count = count($lines);
        foreach ($lines as $index => $line) {
            if (!preg_match('/^([A-Za-z_][\w.-]*)\[(\d+)([|\t,]?)\]\{(.*)\}:\s*$/', $line, $m)) {
                continue;
            }
            $delim  = $m[3] !== '' ? $m[3] : ',';
            $fields = $m[4] !== '' ? array_map(array(__CLASS__, 'toon_token'), explode($delim, $m[4])) : array();
            if (!array_intersect($fields, self::COLUMNS['query'])) {
                continue;
            }
            $rows = array();
            for ($at = $index + 1; $at < $count && strpos($lines[$at], '  ') === 0; $at++) {
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
        return self::error('seoprostats_targets_toon', __('The TOON document has no table with a phrase or query column.', 'seoprostats'));
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
     * import are deleted. Rows over MAX_TARGETS are skipped.
     *
     * @param array<int,mixed> $rows    Rows: query (or phrase), page (or address, url, target_url), priority, status.
     * @param string           $source  One of SOURCES.
     * @param bool             $replace Delete targets not in the import.
     * @return array<string,mixed>|WP_Error added, updated, removed, skipped (row, query, reason, message), total.
     */
    public static function import(array $rows, $source = 'list', $replace = false) {
        global $wpdb;
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
        $now      = time();
        $table    = SEOProStats_Schema::table('targets');
        $existing = self::stored();
        $removed  = 0;
        if ($replace && $existing) {
            $keep    = $valid ? SEOProStats_Dict::find(SEOProStats_Schema::DICT_QUERY, array_keys($valid)) : array();
            $gone    = array_values(array_diff(array_keys($existing), array_map('intval', $keep)));
            $removed = self::delete_ids($gone);
            $existing = array_diff_key($existing, array_flip($gone));
        }
        $queries = $valid ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_QUERY, array_keys($valid)) : array();
        $paths   = array_filter(array_column($valid, 'page'));
        $path_id = $paths ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_values($paths)) : array();
        $room    = self::MAX_TARGETS - count($existing);
        $added   = 0;
        $updated = 0;
        $user    = get_current_user_id();
        $statuses = array_flip(self::STATUSES);
        foreach ($valid as $query => $target) {
            $query_id = isset($queries[SEOProStats_Dict::clean($query)]) ? (int) $queries[SEOProStats_Dict::clean($query)] : 0;
            if (!$query_id) {
                $skipped[] = self::skip($target['row'], $query, 'query');
                continue;
            }
            $known = isset($existing[$query_id]);
            if (!$known && $room <= 0) {
                $skipped[] = self::skip($target['row'], $query, 'limit');
                continue;
            }
            $page = $target['page'] !== '' && isset($path_id[SEOProStats_Dict::clean($target['page'])]) ? (int) $path_id[SEOProStats_Dict::clean($target['page'])] : 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing our own table by its primary key.
            $saved = $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (query_id, path_id, priority, status, source, created, updated, user_id) VALUES (%d, %d, %d, %d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE path_id = VALUES(path_id), priority = VALUES(priority), status = VALUES(status), source = VALUES(source), updated = VALUES(updated), user_id = VALUES(user_id)',
                $table,
                $query_id,
                $page,
                (int) $target['priority'],
                (int) $statuses[$target['status']],
                $code,
                $now,
                $now,
                $user
            ));
            if ($saved === false || !self::save_measurements($query_id, $target['measurements'])) {
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
     * A row checked: its query (lower case, as stored), page path ('' for
     * none), priority and status; or why it is skipped.
     *
     * @param array<string,mixed> $row Row.
     * @return array{query:string,page:string,priority:int,status:string,measurements:array<string,mixed>}|string The reason code when skipped.
     */
    private static function check(array $row) {
        $raw   = self::field($row, 'query');
        $query = SEOProStats_Search_Import::query(function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw));
        if ($query === '' || preg_match('/[\x00-\x1f\x7f]/', $query) || (function_exists('mb_check_encoding') && !mb_check_encoding($query, 'UTF-8'))) {
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
            $value = $row[$field];
            if ($value === '') {
                continue; // An empty CSV cell is unknown, not a zero or a deletion.
            }
            if ($value !== null && (is_bool($value) || !is_scalar($value) || !preg_match('/^\d{1,10}$/', (string) $value) || (float) $value > 4294967295)) {
                return null;
            }
            $date = isset($row[$field . '_measured']) && $row[$field . '_measured'] !== '' ? $row[$field . '_measured'] : wp_date('Y-m-d');
            if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $date > wp_date('Y-m-d')) {
                return null;
            }
            $out[$field] = $value === null ? null : (int) $value;
            $out[$field . '_measured'] = $value === null ? '' : $date;
        }
        return $out;
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
     * @param string $reason query, address, priority, status, duplicate or limit.
     * @return array{row:int,query:string,reason:string,message:string}
     */
    private static function skip($row, $query, $reason) {
        $messages = array(
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
