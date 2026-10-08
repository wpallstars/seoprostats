<?php
/**
 * The loop export: one answer per cycle for an agent working the SEO
 * decision loop (docs/seo-loop-recipes.md).
 *
 * - queue: the decision queue's open items (new and accepted), best first,
 *   with each item's why, figures and score parts (SEOProStats_Queue);
 * - experiments: those due for review (running, with search data through
 *   the review day), those still running, and those decided in the last
 *   RECENT_DAYS days with their results, null and negative ones included
 *   (SEOProStats_Experiments);
 * - export: the period's search figures per query and page in the
 *   aidevops export layout (query, page, clicks, impressions, CTR,
 *   position), most impressions first, for a tracking run.
 *
 * The queue and the export share one period (cut at the newest search day
 * and to its newest 91 days, as Opportunities), engine and page filters;
 * params gives them back, as acting on a queue item needs the same ones.
 * Nothing is stored: the export rows are cached like the reports (keyed by
 * the newest import), the queue's opportunities and the experiments'
 * measurements are cached by their own classes.
 *
 * Reads: those of the queue and the experiments list, and one grouped
 * read of gsc_pairs by its primary key (engine, day), or path_day with
 * page filters. Design: docs/seo-loop.md → Loop export and agent recipes.
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

final class SEOProStats_Loop {

    /** Version of the answer's layout: raised when a field changes meaning or goes. */
    const VERSION = 1;

    /** The period when none is given. */
    const RANGE = '30d';

    /** Queue items: default and most. */
    const ITEMS     = 20;
    const MAX_ITEMS = 200;

    /** Export rows (query and page pairs): default and most. */
    const ROWS     = 1000;
    const MAX_ROWS = 5000;

    /** Decided experiments listed: decided in this many days. */
    const RECENT_DAYS = 90;

    /** The export's columns, in the aidevops export layout's order. */
    const COLUMNS = array('query', 'page', 'clicks', 'impressions', 'ctr', 'position');

    /**
     * The loop answer.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request() (range, from, to, filters, limit: queue items).
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id for the queue's value; '' for the first goal.
     * @param int                 $rows   Most export rows.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $goal = '', $rows = self::ROWS) {
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-opportunities.php';
        require_once __DIR__ . '/class-seoprostats-queue.php';
        require_once __DIR__ . '/class-seoprostats-experiments.php';
        require_once __DIR__ . '/class-seoprostats-dict.php';
        $engine = SEOProStats_Search::engine_name($engine);
        $items  = max(1, min(self::MAX_ITEMS, isset($req['limit']) ? (int) $req['limit'] : self::ITEMS));
        $rows   = max(1, min(self::MAX_ROWS, (int) $rows));

        $queue = SEOProStats_Queue::report(array_merge($req, array('limit' => $items, 'offset' => 0)), $engine, 'open', $goal);
        if (is_wp_error($queue)) {
            return $queue;
        }
        $experiments = self::experiments();
        $export      = self::export($req, $engine, $rows);

        return array(
            'loop'        => self::VERSION,
            'site'        => home_url('/'),
            'engine'      => $engine,
            'engines'     => $queue['engines'],
            'range'       => $queue['range'],
            'days'        => $queue['days'],
            'cut'         => $queue['cut'],
            'through'     => $queue['through'],
            'connected'   => $queue['connected'],
            'ignored'     => $queue['ignored'],
            'goal'        => $queue['goal'],
            'goals'       => $queue['goals'],
            'params'      => self::params($req, $engine, $queue['goal']),
            'summary'     => array(
                'new'       => $queue['counts']['new'],
                'accepted'  => $queue['counts']['accepted'],
                'done'      => $queue['counts']['done'],
                'dismissed' => $queue['counts']['dismissed'],
                'left_out'  => $queue['left_out'],
                'due'       => count($experiments['due']),
                'running'   => count($experiments['running']),
                'decided'   => count($experiments['decided']),
                'rows'      => count($export['rows']),
            ),
            'queue'       => array(
                'total' => $queue['total'],
                'more'  => $queue['more'],
                'rules' => $queue['rules'],
                'items' => $queue['items'],
            ),
            'experiments' => $experiments + array('recent_days' => self::RECENT_DAYS),
            'export'      => $export,
        );
    }

    /**
     * The request's period, engine, goal and page filters, as acting on a
     * queue item (POST /queue/{key}) takes them.
     *
     * @param array<string,mixed>                $req    Request.
     * @param string                             $engine Engine name.
     * @param array{id:string,name:string}|null $goal   The goal used.
     * @return array<string,mixed>
     */
    private static function params(array $req, $engine, $goal) {
        $range = isset($req['range']) ? (string) $req['range'] : self::RANGE;
        $out   = array('range' => $range);
        if ($range === 'custom') {
            $out['from'] = isset($req['from']) ? (string) $req['from'] : '';
            $out['to']   = isset($req['to']) ? (string) $req['to'] : '';
        }
        $out['engine'] = $engine;
        $out['goal']   = $goal ? $goal['id'] : '';
        if (!empty($req['filters'])) {
            $out['filters'] = $req['filters'];
        }
        $out['data'] = SEOProStats_Schema::set();
        return $out;
    }

    /**
     * Experiments due for review, still running, and decided in the last
     * RECENT_DAYS days (from the newest LIST_LIMIT of each state).
     *
     * @return array{due:array<int,array<string,mixed>>,running:array<int,array<string,mixed>>,decided:array<int,array<string,mixed>>}
     */
    private static function experiments() {
        $out     = array('due' => array(), 'running' => array(), 'decided' => array());
        $running = SEOProStats_Experiments::list_experiments(array('status' => 'running'));
        foreach (is_wp_error($running) ? array() : $running['experiments'] as $one) {
            $out[$one['due'] ? 'due' : 'running'][] = $one;
        }
        $since   = time() - self::RECENT_DAYS * DAY_IN_SECONDS;
        $decided = SEOProStats_Experiments::list_experiments(array('status' => 'decided'));
        foreach (is_wp_error($decided) ? array() : $decided['experiments'] as $one) {
            if ($one['decided'] !== null && (int) strtotime((string) $one['decided']) >= $since) {
                $out['decided'][] = $one;
            }
        }
        return $out;
    }

    /**
     * The export: the period's figures per query and page (cached).
     *
     * @param array<string,mixed> $req   Request.
     * @param string              $name  Engine name.
     * @param int                 $limit Most rows.
     * @return array<string,mixed>
     */
    private static function export(array $req, $name, $limit) {
        $key = array(
            'range'   => isset($req['range']) ? $req['range'] : '',
            'from'    => isset($req['from']) ? $req['from'] : '',
            'to'      => isset($req['to']) ? $req['to'] : '',
            'filters' => isset($req['filters']) ? $req['filters'] : array(),
            'engine'  => $name,
            'rows'    => $limit,
            'imports' => SEOProStats_Search::version(),
        );
        return SEOProStats_Query::cached('loop', $key, static function () use ($req, $name, $limit) {
            return self::export_build($req, $name, $limit);
        });
    }

    /**
     * Read the export rows: one grouped read of gsc_pairs for the period.
     *
     * @param array<string,mixed> $req   Request.
     * @param string              $name  Engine name.
     * @param int                 $limit Most rows.
     * @return array<string,mixed>
     */
    private static function export_build(array $req, $name, $limit) {
        global $wpdb;
        $engine  = SEOProStats_Search::ENGINES[$name];
        $bounds  = SEOProStats_Search::bounds($engine);
        $range   = SEOProStats_Query::range($req);
        $weekly  = in_array($name, SEOProStats_Search::WEEKLY, true);
        $full    = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds, $weekly) : null;
        $now     = $full ? SEOProStats_Opportunities::cut($full) : null;
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids(isset($req['filters']) ? (array) $req['filters'] : array(), '', $ignored);
        $host    = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $out     = array(
            'domain'     => is_string($host) ? $host : '',
            'source'     => $name === 'bing' ? 'bing' : 'gsc',
            'exported'   => gmdate('Y-m-d\TH:i:s\Z'),
            'start_date' => $now ? (string) $now['day_from'] : null,
            'end_date'   => $now ? (string) $now['day_to'] : null,
            'columns'    => self::COLUMNS,
            'ignored'    => array_values(array_unique($ignored)),
            'rows'       => array(),
            'more'       => false,
        );
        if (!$now || ($pages !== null && !$pages)) {
            return $out;
        }
        $on = SEOProStats_Opportunities::where($engine, $now, $pages);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day; $on holds only placeholders and a fixed key name.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`{$on['key']}`) WHERE {$on['where']} GROUP BY path_id, query_id HAVING i >= 1 ORDER BY i DESC, c DESC, path_id, query_id LIMIT %d", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $on['args'], array($limit + 1))), ARRAY_A);
        $out['more'] = count($rows) > $limit;
        $rows        = array_slice($rows, 0, $limit);
        $text        = SEOProStats_Query::texts(array_values(array_unique(array_merge(array_column($rows, 'pg'), array_column($rows, 'q')))));
        foreach ($rows as $row) {
            $out['rows'][] = array(
                'query' => isset($text[(int) $row['q']]) ? (string) $text[(int) $row['q']] : '',
                'page'  => isset($text[(int) $row['pg']]) ? (string) $text[(int) $row['pg']] : '',
            ) + SEOProStats_Search::metrics($row['c'], $row['i'], $row['p']);
        }
        return $out;
    }

    /**
     * The export as an aidevops export file (TOON): the header lines, a
     * line of three dashes, the column names and one tab-separated line
     * per row. Tabs and line breaks in a text become spaces.
     *
     * @param array<string,mixed> $export The answer's export.
     * @return string
     */
    public static function toon(array $export) {
        $clean = static function ($value) {
            return trim((string) preg_replace('/[\t\r\n]+/', ' ', (string) $value));
        };
        $lines = array();
        foreach (array('domain', 'source', 'exported', 'start_date', 'end_date') as $field) {
            $lines[] = $field . "\t" . $clean(isset($export[$field]) ? $export[$field] : '');
        }
        $lines[] = '---';
        $lines[] = implode("\t", self::COLUMNS);
        foreach ((array) $export['rows'] as $row) {
            $cells = array();
            foreach (self::COLUMNS as $column) {
                $cells[] = $clean($row[$column]);
            }
            $lines[] = implode("\t", $cells);
        }
        return implode("\n", $lines) . "\n";
    }
}
