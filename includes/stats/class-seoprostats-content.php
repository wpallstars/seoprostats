<?php
/**
 * The content report (Search → Content): per page, what search showed
 * and what its visits from search did. Search Console's clicks,
 * impressions, CTR and position; the visits from organic search that
 * landed on the page, with their bounce rate, views per visit and time;
 * and how many of them reached a goal.
 *
 * Every read uses an index and none grows with all the visits:
 *
 * - search figures: gsc_pages by its primary key (engine, day), or
 *   path_day with page filters, as SEOProStats_Search;
 * - search visits: the daily summaries of search landings
 *   (SEOProStats_Rollup::SEARCH_LANDING) by dim_val_day;
 * - conversions: one goal at a time (the first unless asked for), its
 *   hits by name_ts or path_ts joined to their visits
 *   (SEOProStats_Conversions::by_entry()).
 *
 * As SEOProStats_Search: days are the final search days only (the period
 * is cut at the newest one), page filters apply and other filters do not
 * (named in `ignored`). Days summarised before search landings existed
 * are filled in by the rollup, newest first; `landings_from` is the oldest
 * day that has them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Content {

    /**
     * Orders of the rows; the first is the default. Each sorts most first
     * unless asked otherwise, except position, lowest first
     * (SEOProStats_Search::sort_order()). Conversions need a goal.
     */
    const SORTS = array('impressions', 'clicks', 'ctr', 'position', 'visits', 'bounce_rate', 'visit_duration', 'conversions', 'conversion_rate');

    /**
     * The content report.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request().
     * @param string              $sort   One of SORTS; '' for the default.
     * @param string              $goal   Goal id; '' for the first goal.
     * @param string              $engine google, bing (SEOProStats_Search::ENGINES) or all (Combined).
     * @param string              $order  asc or desc; '' for the sort's natural order.
     * @return array<string,mixed>
     */
    public static function report(array $req, $sort = '', $goal = '', $engine = 'google', $order = '') {
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-goals.php';
        require_once __DIR__ . '/class-seoprostats-conversions.php';
        require_once __DIR__ . '/class-seoprostats-rollup.php';
        $goals  = SEOProStats_Goals::goals();
        $chosen = $goals ? $goals[0] : null;
        foreach ($goals as $item) {
            if ($item['id'] === (string) $goal) {
                $chosen = $item;
            }
        }
        // Without a goal there are no conversions to sort by.
        $sort   = in_array($sort, self::SORTS, true) && ($chosen !== null || strpos((string) $sort, 'conversion') !== 0) ? (string) $sort : self::SORTS[0];
        $order  = SEOProStats_Search::sort_order($sort, $order);
        $live   = SEOProStats_Schema::set() === 'live';
        $engine = SEOProStats_Search::report_engine($engine);

        $key    = array(
            'sort'     => $sort,
            'order'    => $order,
            'goal'     => $chosen,
            'engine'   => $engine,
            'imports'  => SEOProStats_Search::version(),
            'landings' => SEOProStats_Rollup::landings_from(),
        );
        $answer = SEOProStats_Query::cached('content', $req + $key, static function () use ($req, $sort, $order, $chosen, $engine) {
            return self::build($req, array('sort' => $sort, 'order' => $order), $chosen, $engine);
        });

        $answer['connected'] = !$live || SEOProStats_Search::connected($engine);
        $answer['goals']     = array_map(static function ($item) {
            return array('id' => $item['id'], 'name' => $item['name']);
        }, $goals);
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
     * @param array<string,mixed>             $req  From SEOProStats_Query::request().
     * @param array{sort:string,order:string} $by   The sort (SORTS) and its order (asc or desc).
     * @param array<string,mixed>|null        $goal Goal, or null without goals.
     * @param string                          $name Engine name.
     * @return array<string,mixed>
     */
    private static function build(array $req, array $by, $goal, $name) {
        $engine  = SEOProStats_Search::codes($name);
        $bounds  = SEOProStats_Search::span($name);
        $range   = SEOProStats_Query::range($req);
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids($req['filters'], '', $ignored);
        $weekly  = SEOProStats_Search::weekly($name);
        $now     = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds, $weekly) : null;
        $limit   = (int) $req['limit'];
        $offset  = (int) $req['offset'];
        $filled  = SEOProStats_Rollup::landings_from();

        $answer = array(
            'engine'        => $name,
            'engines'       => SEOProStats_Search::engines(),
            'combined'      => SEOProStats_Search::combinable(),
            'range'         => SEOProStats_Query::range_out($now ? $now : $range),
            'through'       => $bounds['to'],
            'first'         => $bounds['from'],
            'ignored'       => array_values(array_unique($ignored)),
            'sort'          => $by['sort'],
            'order'         => $by['order'],
            'goal'          => $goal ? array('id' => $goal['id'], 'name' => $goal['name'], 'kind' => $goal['kind'], 'match' => $goal['match']) : null,
            'landings_from' => $filled,
            'partial'       => false,
            'totals'        => self::metrics(array(), $goal !== null),
            'rows'          => array(),
            'total'         => 0,
            'more'          => false,
        );
        if (!$now || ($pages !== null && !$pages)) {
            return $answer;
        }

        $list   = self::period($engine, $now, $pages, $goal);
        $oldest = $now['day_from'];
        $other  = SEOProStats_Query::compare_range($now, $req['compare']);
        $then   = $other ? SEOProStats_Search::days($other, array('from' => '', 'to' => ''), $weekly) : null;
        $before = $then ? self::period($engine, $then, $pages, $goal) : null;
        if ($then) {
            $oldest = min($oldest, $then['day_from']);
        }
        // Missing: days of the period the refill has still to reach (not those before the floor, which never will).
        $floor             = SEOProStats_Rollup::landings_floor();
        $answer['partial'] = $floor !== '' && ($filled === '' || $filled > max($oldest, $floor));
        $answer['totals']  = self::metrics(self::sum($list), $goal !== null);

        $order = self::order($list, $by['sort'], $by['order']);
        $shown = array_slice($order, $offset, $limit);
        $text  = SEOProStats_Query::texts($shown);
        foreach ($shown as $path_id) {
            $path = isset($text[$path_id]) ? $text[$path_id] : '';
            $row  = array('id' => (string) $path_id, 'path_id' => $path_id, 'value' => $path, 'label' => $path) + self::metrics($list[$path_id], $goal !== null);
            $row += SEOProStats_Clicks::page_info($path) ?: array('path' => $path, 'url' => '', 'post_id' => 0, 'edit_url' => null);
            if ($before !== null) {
                $was            = self::metrics(isset($before[$path_id]) ? $before[$path_id] : array(), $goal !== null);
                $row['compare'] = $was + array('change' => self::change($row, $was));
            }
            $answer['rows'][] = $row;
        }
        $answer['total'] = count($order);
        $answer['more']  = $offset + $limit < count($order);

        if ($before !== null) {
            $was               = self::metrics(self::sum($before), $goal !== null);
            $answer['compare'] = array(
                'range'  => SEOProStats_Query::range_out($other),
                'totals' => $was,
                'change' => self::change($answer['totals'], $was),
            );
        }
        return $answer;
    }

    /**
     * Each page's sums in a period: search (c clicks, i impressions, p
     * position × impressions × 100), search visits and conversions.
     *
     * @param int[]                    $engine Engine codes (SEOProStats_Search::codes()).
     * @param array<string,mixed>      $days   From SEOProStats_Search::days().
     * @param int[]|null               $pages  Path ids, or null for every page.
     * @param array<string,mixed>|null $goal   Goal, or null.
     * @return array<int,array<string,int>> Path id => sums.
     */
    private static function period(array $engine, array $days, $pages, $goal) {
        global $wpdb;
        $on    = SEOProStats_Search::engine_where($engine);
        $in    = $pages === null ? '' : ' IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
        $ids   = $pages === null ? array() : array_map('intval', $pages);
        $key   = $pages === null ? 'PRIMARY' : 'path_day';
        $paths = $in === '' ? '' : " AND path_id$in";
        $vals  = $in === '' ? '' : " AND val$in";
        $list  = array();
        $zero  = array('c' => 0, 'i' => 0, 'p' => 0, 'visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounces' => 0, 'engaged_ms' => 0, 'events' => 0, 'conversions' => 0);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: gsc_pages by its primary key (engine, day) or path_day, daily by dim_val_day; $key is a fixed key name, and $on, $paths and $vals hold only placeholders.
        $search = $wpdb->get_results($wpdb->prepare("SELECT path_id AS v, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`$key`) WHERE {$on['sql']} AND day >= %s AND day <= %s$paths GROUP BY path_id ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('gsc_pages')), $on['args'], array((string) $days['day_from'], (string) $days['day_to']), $ids)), ARRAY_A);
        $visits = $wpdb->get_results($wpdb->prepare("SELECT val AS v, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(pageviews) AS pageviews, SUM(bounces) AS bounces, SUM(engaged_ms) AS engaged_ms, SUM(events) AS events FROM %i WHERE dim = %d$vals AND day >= %s AND day <= %s GROUP BY val ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('daily'), SEOProStats_Rollup::SEARCH_LANDING), $ids, array((string) $days['day_from'], (string) $days['day_to']))), ARRAY_A);
        // phpcs:enable

        foreach ((array) $search as $row) {
            $list[(int) $row['v']] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']) + $zero;
        }
        foreach ((array) $visits as $row) {
            $id = (int) $row['v'];
            if (!$id) {
                continue; // Visits without a known entry page.
            }
            $base = isset($list[$id]) ? $list[$id] : $zero;
            foreach (array('visitors', 'visits', 'pageviews', 'bounces', 'engaged_ms', 'events') as $col) {
                $base[$col] = (int) $row[$col];
            }
            $list[$id] = $base;
        }
        if ($goal !== null) {
            $wanted = $pages === null ? null : array_flip($ids);
            foreach (SEOProStats_Conversions::by_entry($goal, $days, SEOProStats_Query::CHANNELS['organic_search']) as $id => $count) {
                // Only pages with search visits: before the refill reaches a day, its conversions wait too.
                if (isset($list[$id]) && $list[$id]['visits'] && ($wanted === null || isset($wanted[$id]))) {
                    $list[$id]['conversions'] = $count;
                }
            }
        }
        return $list;
    }

    /**
     * Sums over every page.
     *
     * @param array<int,array<string,int>> $list From period().
     * @return array<string,int>
     */
    private static function sum(array $list) {
        $out = array();
        foreach ($list as $row) {
            foreach ($row as $col => $value) {
                $out[$col] = (isset($out[$col]) ? $out[$col] : 0) + $value;
            }
        }
        return $out;
    }

    /**
     * Path ids in the sort's order, then most impressions, clicks and
     * search visits first, then by id. A rate without its base (CTR or
     * position without impressions, a visit rate without visits) goes
     * last whichever the order.
     *
     * @param array<int,array<string,int>> $list  From period().
     * @param string                       $sort  One of SORTS.
     * @param string                       $order asc or desc.
     * @return int[]
     */
    private static function order(array $list, $sort, $order) {
        $value = array();
        foreach ($list as $id => $sums) {
            $value[$id] = self::sort_value($sums, $sort);
        }
        $ids = array_keys($list);
        usort($ids, static function ($a, $b) use ($list, $value, $order) {
            $by = SEOProStats_Search::compare($value[$a], $value[$b], $order);
            if ($by) {
                return $by;
            }
            foreach (array('i', 'c', 'visits') as $col) {
                if ($list[$a][$col] !== $list[$b][$col]) {
                    return $list[$b][$col] <=> $list[$a][$col];
                }
            }
            return $a <=> $b;
        });
        return $ids;
    }

    /**
     * A page's figure for a sort, from its sums.
     *
     * @param array<string,int> $sums From period().
     * @param string            $sort One of SORTS.
     * @return int|float|null Null for a rate without its base.
     */
    private static function sort_value(array $sums, $sort) {
        $visits = $sums['visits'];
        switch ($sort) {
            case 'visits':
                return $visits;
            case 'bounce_rate':
                return $visits ? $sums['bounces'] / $visits : null;
            case 'visit_duration':
                return $visits ? $sums['engaged_ms'] / $visits : null;
            case 'conversions':
                return $sums['conversions'];
            case 'conversion_rate':
                return $visits ? $sums['conversions'] / $visits : null;
            default:
                return SEOProStats_Search::sort_value($sums, $sort);
        }
    }

    /**
     * Metrics from sums: search, search visits and conversions (null
     * without a goal).
     *
     * @param array<string,int> $sums From period() or sum().
     * @param bool              $goal Whether a goal is counted.
     * @return array<string,int|float|null>
     */
    private static function metrics(array $sums, $goal) {
        $get    = static function ($key) use ($sums) {
            return isset($sums[$key]) ? (int) $sums[$key] : 0;
        };
        $visits = SEOProStats_Query::metrics(array(
            'visitors'   => $get('visitors'),
            'visits'     => $get('visits'),
            'pageviews'  => $get('pageviews'),
            'bounces'    => $get('bounces'),
            'engaged_ms' => $get('engaged_ms'),
            'events'     => $get('events'),
        ));
        $out    = SEOProStats_Search::metrics($get('c'), $get('i'), $get('p')) + array(
            'visits'          => $visits['visits'],
            'bounce_rate'     => $visits['bounce_rate'],
            'views_per_visit' => $visits['views_per_visit'],
            'visit_duration'  => $visits['visit_duration'],
            'conversions'     => null,
            'conversion_rate' => null,
        );
        if ($goal) {
            $out['conversions']     = $get('conversions');
            $out['conversion_rate'] = $visits['visits'] ? round($out['conversions'] / $visits['visits'], 4) : 0.0;
        }
        return $out;
    }

    /**
     * Change from the comparison: relative for counts and rates (null when
     * then is 0); places for position (now − then; lower is better).
     *
     * @param array<string,mixed> $now  From metrics().
     * @param array<string,mixed> $then From metrics().
     * @return array<string,float|null>
     */
    private static function change(array $now, array $then) {
        $out  = SEOProStats_Search::change($now, $then);
        $keys = array('visits', 'bounce_rate', 'views_per_visit', 'visit_duration');
        if ($now['conversions'] !== null) {
            $keys[] = 'conversions';
            $keys[] = 'conversion_rate';
        }
        $pick = array_flip($keys);
        return $out + SEOProStats_Query::change(array_intersect_key($now, $pick), array_intersect_key($then, $pick));
    }
}
