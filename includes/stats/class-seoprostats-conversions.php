<?php
/**
 * Conversion reports: goals, funnels and custom properties, on the same
 * ranges, comparisons, filters, data sets and cache as SEOProStats_Query,
 * so the numbers agree with the Overview.
 *
 * - Goals: visits that reached each goal (a page viewed, an event sent),
 *   their visitors, how often, the conversion rate (÷ visits) and, for
 *   event goals, revenue per currency (never added across currencies).
 * - Funnels: visits that reached each step in order within the visit
 *   (steps can mix pages and events; `seq` orders a visit's hits). One
 *   query with a derived table per step, which every supported MySQL and
 *   MariaDB runs (no window functions or CTEs).
 * - Properties: the keys sent with events and pages, or the values of one
 *   key, with counts, visits and event revenue per currency.
 *
 * They read the fact tables (by indexes `name_ts`, `path_ts`, `ts` and
 * `session_seq`), so they reach back as far as retention keeps events
 * (goals and funnels with page steps: visits).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Conversions {

    /**
     * Every goal with its conversions, compared with the other period.
     *
     * @param array<string,mixed> $req From SEOProStats_Query::request().
     * @return array<string,mixed>
     */
    public static function goals(array $req) {
        $goals = SEOProStats_Goals::goals();
        return SEOProStats_Query::cached('goals', $req + array('defs' => $goals), static function () use ($req, $goals) {
            $range  = SEOProStats_Query::range($req);
            $answer = self::goal_period($goals, $range, $req['filters']);
            $other  = SEOProStats_Query::compare_range($range, $req['compare']);
            if ($other) {
                $then              = self::goal_period($goals, $other, $req['filters']);
                $answer['compare'] = $then;
                foreach ($answer['goals'] as $i => $row) {
                    $was                            = $then['goals'][$i];
                    $answer['goals'][$i]['change'] = SEOProStats_Query::change(
                        array('visits' => $row['visits'], 'completions' => $row['completions'], 'conversion_rate' => $row['conversion_rate']),
                        array('visits' => $was['visits'], 'completions' => $was['completions'], 'conversion_rate' => $was['conversion_rate'])
                    );
                }
            }
            return $answer;
        });
    }

    /**
     * Every funnel with the visits at each step, compared with the other
     * period.
     *
     * @param array<string,mixed> $req From SEOProStats_Query::request().
     * @return array<string,mixed>
     */
    public static function funnels(array $req) {
        $funnels = SEOProStats_Goals::funnels();
        return SEOProStats_Query::cached('funnels', $req + array('defs' => $funnels), static function () use ($req, $funnels) {
            $range  = SEOProStats_Query::range($req);
            $answer = self::funnel_period($funnels, $range, $req['filters']);
            $other  = SEOProStats_Query::compare_range($range, $req['compare']);
            if ($other) {
                $then              = self::funnel_period($funnels, $other, $req['filters']);
                $answer['compare'] = $then;
                foreach ($answer['funnels'] as $i => $row) {
                    $was                              = $then['funnels'][$i];
                    $answer['funnels'][$i]['change'] = SEOProStats_Query::change(
                        array('entered' => $row['entered'], 'completed' => $row['completed'], 'conversion_rate' => $row['conversion_rate']),
                        array('entered' => $was['entered'], 'completed' => $was['completed'], 'conversion_rate' => $was['conversion_rate'])
                    );
                }
            }
            return $answer;
        });
    }

    /**
     * Property keys sent with events and pages, or the values of one key.
     *
     * @param array<string,mixed> $req   From SEOProStats_Query::request().
     * @param string              $key   Key whose values to list; '' lists the keys.
     * @param string              $event Only properties of this event; '' for all, with pages.
     * @return array<string,mixed>
     */
    public static function properties(array $req, $key = '', $event = '') {
        $key   = (string) $key;
        $event = (string) $event;
        return SEOProStats_Query::cached('properties', $req + array('key' => $key, 'event' => $event), static function () use ($req, $key, $event) {
            $range    = SEOProStats_Query::range($req);
            $compiled = SEOProStats_Query::compile($req['filters'], $range);
            $visits   = (int) SEOProStats_Query::totals($range, $compiled)['visits'];
            return array(
                'range'  => SEOProStats_Query::range_out($range),
                'key'    => $key,
                'event'  => $event,
                'visits' => $visits,
                'rows'   => self::property_rows($range, $compiled, $key, $event, (int) $req['limit'], (int) $req['offset'], $visits),
            );
        });
    }

    /**
     * Goal rows for one period.
     *
     * @param array<int,array<string,mixed>>                                $goals   Goals.
     * @param array<string,mixed>                                           $range   From SEOProStats_Query::range().
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @return array{range:array<string,string>,visits:int,goals:array<int,array<string,mixed>>}
     */
    private static function goal_period(array $goals, array $range, array $filters) {
        $compiled = SEOProStats_Query::compile($filters, $range);
        $visits   = (int) SEOProStats_Query::totals($range, $compiled)['visits'];
        $rows     = array();
        foreach ($goals as $goal) {
            $rows[] = array(
                'id'    => $goal['id'],
                'name'  => $goal['name'],
                'kind'  => $goal['kind'],
                'match' => $goal['match'],
            ) + self::goal_counts($goal, $range, $compiled, $visits);
        }
        return array(
            'range'  => SEOProStats_Query::range_out($range),
            'visits' => $visits,
            'goals'  => $rows,
        );
    }

    /**
     * One goal's numbers in a period.
     *
     * @param array<string,mixed> $goal     Goal.
     * @param array<string,mixed> $range    From SEOProStats_Query::range().
     * @param array<string,mixed> $compiled From SEOProStats_Query::compile().
     * @param int                 $visits   Visits in the period.
     * @return array{visitors:int,visits:int,completions:int,conversion_rate:float,revenue:array<int,array{currency:string,amount:float,count:int}>}
     */
    private static function goal_counts(array $goal, array $range, array $compiled, $visits) {
        global $wpdb;
        $out = array(
            'visitors'        => 0,
            'visits'          => 0,
            'completions'     => 0,
            'conversion_rate' => 0,
            'revenue'         => array(),
        );
        list($table, $column, $ids) = self::target($goal['kind'], $goal['match']);
        if (!$ids) {
            return $out;
        }
        $holders = implode(', ', array_fill(0, count($ids), '%d'));
        $where   = $compiled['where'];
        $args    = array_merge(
            array(SEOProStats_Schema::table('sessions'), $table),
            SEOProStats_Query::fact_window($range),
            array($column),
            $ids,
            array($range['from'], $range['to']),
            $compiled['args']
        );
        $from = "FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.%i IN ($holders) AND s.started >= %d AND s.started < %d$where";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `name_ts` or `path_ts`, and the primary key; $from holds the placeholders.
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT f.session_id) AS visits, COUNT(*) AS completions $from", $args), ARRAY_A);
        $row = (array) $row;

        $out['visitors']        = isset($row['visitors']) ? (int) $row['visitors'] : 0;
        $out['visits']          = isset($row['visits']) ? (int) $row['visits'] : 0;
        $out['completions']     = isset($row['completions']) ? (int) $row['completions'] : 0;
        $out['conversion_rate'] = $visits ? round($out['visits'] / $visits, 4) : 0;

        if ($goal['kind'] === 'event' && $out['completions']) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above.
            $money          = $wpdb->get_results($wpdb->prepare("SELECT f.currency AS c, SUM(f.revenue) AS r, COUNT(*) AS n $from AND f.revenue <> 0 GROUP BY f.currency ORDER BY r DESC", $args), ARRAY_A);
            $out['revenue'] = self::money((array) $money);
        }
        return $out;
    }

    /**
     * Funnel rows for one period.
     *
     * @param array<int,array<string,mixed>>                                $funnels Funnels.
     * @param array<string,mixed>                                           $range   From SEOProStats_Query::range().
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @return array{range:array<string,string>,visits:int,funnels:array<int,array<string,mixed>>}
     */
    private static function funnel_period(array $funnels, array $range, array $filters) {
        $compiled = SEOProStats_Query::compile($filters, $range);
        $visits   = (int) SEOProStats_Query::totals($range, $compiled)['visits'];
        $rows     = array();
        foreach ($funnels as $funnel) {
            $reached = self::reached($funnel['steps'], $range, $compiled);
            $first   = $reached ? $reached[0] : 0;
            $steps   = array();
            foreach ($funnel['steps'] as $i => $step) {
                $here    = isset($reached[$i]) ? $reached[$i] : 0;
                $before  = $i ? (isset($reached[$i - 1]) ? $reached[$i - 1] : 0) : $here;
                $steps[] = array(
                    'name'      => $step['name'],
                    'kind'      => $step['kind'],
                    'match'     => $step['match'],
                    'visits'    => $here,
                    // Of the visits that started the funnel, and of the step before.
                    'rate'      => $first ? round($here / $first, 4) : 0,
                    'step_rate' => $before ? round($here / $before, 4) : 0,
                    'dropped'   => max(0, $before - $here),
                );
            }
            $last   = $steps ? $steps[count($steps) - 1]['visits'] : 0;
            $rows[] = array(
                'id'              => $funnel['id'],
                'name'            => $funnel['name'],
                'entered'         => $first,
                'completed'       => $last,
                'completion_rate' => $first ? round($last / $first, 4) : 0,
                'conversion_rate' => $visits ? round($last / $visits, 4) : 0,
                'steps'           => $steps,
            );
        }
        return array(
            'range'   => SEOProStats_Query::range_out($range),
            'visits'  => $visits,
            'funnels' => $rows,
        );
    }

    /**
     * Visits that reached each step of a funnel in order.
     *
     * Level 1 is each visit's first hit of step 1. Level k joins the
     * visit's first hit of step k after the hit that reached step k-1
     * (`seq` greater); a visit that missed a step keeps a NULL position,
     * so it reaches no later step. Depth counts the steps reached.
     *
     * @param array<int,array<string,string>> $steps    Steps.
     * @param array<string,mixed>             $range    From SEOProStats_Query::range().
     * @param array<string,mixed>             $compiled From SEOProStats_Query::compile().
     * @return int[] Visits per step, in order.
     */
    private static function reached(array $steps, array $range, array $compiled) {
        global $wpdb;
        $count   = count($steps);
        $targets = array();
        foreach ($steps as $step) {
            $targets[] = self::target($step['kind'], $step['match']);
        }
        if (!$targets[0][2]) {
            return array_fill(0, $count, 0);
        }

        list($table, $column, $ids) = $targets[0];
        $holders                    = implode(', ', array_fill(0, count($ids), '%d'));
        $sql                        = "SELECT f.session_id AS sid, MIN(f.seq) AS seq, 1 AS depth FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.%i IN ($holders) AND s.started >= %d AND s.started < %d{$compiled['where']} GROUP BY f.session_id";
        $args                       = array_merge(
            array(SEOProStats_Schema::table('sessions'), $table),
            SEOProStats_Query::fact_window($range),
            array($column),
            $ids,
            array($range['from'], $range['to']),
            $compiled['args']
        );

        for ($k = 1; $k < $count; $k++) {
            list($table, $column, $ids) = $targets[$k];
            // A step nothing matches stops every visit there.
            $match = $ids ? 'f.%i IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')' : '1 = 0';
            $sql   = "SELECT l.sid, MIN(f.seq) AS seq, MAX(l.depth) + (MIN(f.seq) IS NOT NULL) AS depth FROM ($sql) l LEFT JOIN %i f ON f.session_id = l.sid AND f.seq > l.seq AND $match GROUP BY l.sid";
            $args  = array_merge($args, array($table), $ids ? array_merge(array($column), $ids) : array());
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: step 1 by index `name_ts` or `path_ts`, later steps by `session_seq`; $sql holds only placeholders and fixed SQL.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT x.depth AS d, COUNT(*) AS n FROM ($sql) x GROUP BY x.depth", $args), ARRAY_A);

        $at = array_fill(1, $count, 0);
        foreach ((array) $rows as $row) {
            $depth = min($count, (int) $row['d']);
            if ($depth > 0) {
                $at[$depth] += (int) $row['n'];
            }
        }
        // Reaching step k means a depth of k or more.
        $out = array();
        $sum = 0;
        for ($k = $count; $k >= 1; $k--) {
            $sum             += $at[$k];
            $out[$k - 1] = $sum;
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Property rows: keys (no key given) or one key's values.
     *
     * @param array<string,mixed> $range    From SEOProStats_Query::range().
     * @param array<string,mixed> $compiled From SEOProStats_Query::compile().
     * @param string              $key      Key, or ''.
     * @param string              $event    Event name, or ''.
     * @param int                 $limit    Rows.
     * @param int                 $offset   Rows skipped.
     * @param int                 $visits   Visits in the range, for shares.
     * @return array<int,array<string,mixed>>
     */
    private static function property_rows(array $range, array $compiled, $key, $event, $limit, $offset, $visits) {
        global $wpdb;
        $column = $key === '' ? 'key_id' : 'value_id';
        $filter = '';
        $keys   = array();
        if ($key !== '') {
            $keys = SEOProStats_Dict::find(SEOProStats_Schema::DICT_PROP_KEY, array($key));
            if (!$keys) {
                return array();
            }
            $filter = ' AND p.key_id IN (' . implode(', ', array_fill(0, count($keys), '%d')) . ')';
        }
        $names = array();
        $only  = '';
        if ($event !== '') {
            $names = SEOProStats_Dict::find(SEOProStats_Schema::DICT_EVENT, array($event));
            if (!$names) {
                return array();
            }
            $only = ' AND e.name_id IN (' . implode(', ', array_fill(0, count($names), '%d')) . ')';
        }
        $props  = SEOProStats_Schema::table('props');
        $window = SEOProStats_Query::fact_window($range);

        $parts = array("SELECT p.%i AS k, e.session_id AS sid FROM %i p INNER JOIN %i e ON e.id = p.owner_id WHERE p.owner = %d AND p.ts >= %d AND p.ts < %d$filter$only");
        $args  = array_merge(array($column, $props, SEOProStats_Schema::table('events'), SEOProStats_Schema::OWNER_EVENT), $window, $keys, $names);
        if ($event === '') {
            $parts[] = "SELECT p.%i AS k, v.session_id AS sid FROM %i p INNER JOIN %i v ON v.id = p.owner_id WHERE p.owner = %d AND p.ts >= %d AND p.ts < %d$filter";
            $args    = array_merge($args, array($column, $props, SEOProStats_Schema::table('pageviews'), SEOProStats_Schema::OWNER_PAGEVIEW), $window, $keys);
        }
        $union = implode(' UNION ALL ', $parts);
        $args  = array_merge($args, array(SEOProStats_Schema::table('sessions'), $range['from'], $range['to']), $compiled['args'], array($limit, $offset));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: props by index `ts` or `key_value`, owners and visits by primary key; $union and the where clause hold only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT u.k AS v, COUNT(*) AS n, COUNT(DISTINCT u.sid) AS visits FROM ($union) u INNER JOIN %i s ON s.id = u.sid WHERE s.started >= %d AND s.started < %d{$compiled['where']} GROUP BY u.k ORDER BY n DESC, u.k LIMIT %d OFFSET %d", $args), ARRAY_A);
        if (!$rows) {
            return array();
        }

        // Revenue of the events carrying each value, per currency.
        $money = array();
        if ($key !== '') {
            $values  = array_map('intval', array_column($rows, 'v'));
            $holders = implode(', ', array_fill(0, count($values), '%d'));
            $margs   = array_merge(array($props, SEOProStats_Schema::table('events'), SEOProStats_Schema::table('sessions'), SEOProStats_Schema::OWNER_EVENT), $window, $keys, $names, $values, array($range['from'], $range['to']), $compiled['args']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above; $holders, $filter, $only and the where clause hold only placeholders.
            $found = (array) $wpdb->get_results($wpdb->prepare("SELECT p.value_id AS v, e.currency AS c, SUM(e.revenue) AS r, COUNT(*) AS n FROM %i p INNER JOIN %i e ON e.id = p.owner_id INNER JOIN %i s ON s.id = e.session_id WHERE p.owner = %d AND p.ts >= %d AND p.ts < %d$filter$only AND p.value_id IN ($holders) AND e.revenue <> 0 AND s.started >= %d AND s.started < %d{$compiled['where']} GROUP BY p.value_id, e.currency ORDER BY r DESC", $margs), ARRAY_A);
            foreach ($found as $row) {
                $money[(int) $row['v']][] = $row;
            }
        }

        $text = SEOProStats_Query::texts(array_column($rows, 'v'));
        $out  = array();
        foreach ($rows as $row) {
            $id    = (int) $row['v'];
            $value = isset($text[$id]) ? $text[$id] : '';
            $out[] = array(
                'value'   => $value,
                'label'   => $value === '' ? __('(none)', 'seoprostats') : $value,
                'count'   => (int) $row['n'],
                'visits'  => (int) $row['visits'],
                'share'   => $visits ? round((int) $row['visits'] / $visits, 4) : 0,
                'revenue' => isset($money[$id]) ? self::money($money[$id]) : array(),
            );
        }
        return $out;
    }

    /**
     * The fact table, column and dictionary ids a page or event matches
     * (`*` is any text).
     *
     * @param string $kind  page or event.
     * @param string $match Path or event name.
     * @return array{0:string,1:string,2:int[]}
     */
    private static function target($kind, $match) {
        $page = $kind === 'page';
        $ids  = SEOProStats_Query::dict_ids(
            $page ? SEOProStats_Schema::DICT_PATH : SEOProStats_Schema::DICT_EVENT,
            array(
                'dimension' => $page ? 'page' : 'event',
                'op'        => strpos($match, '*') !== false ? 'matches' : 'is',
                'values'    => array($match),
            )
        );
        return array(
            SEOProStats_Schema::table($page ? 'pageviews' : 'events'),
            $page ? 'path_id' : 'name_id',
            array_map('intval', $ids),
        );
    }

    /**
     * Revenue rows (c currency, r minor units, n events) as amounts in the
     * currency's main unit. Currencies are never added together.
     *
     * @param array<int,array<string,mixed>> $rows Rows.
     * @return array<int,array{currency:string,amount:float,count:int}>
     */
    private static function money(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'currency' => (string) $row['c'],
                'amount'   => round((int) $row['r'] / 100, 2),
                'count'    => (int) $row['n'],
            );
        }
        return $out;
    }
}
