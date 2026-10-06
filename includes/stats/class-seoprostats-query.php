<?php
/**
 * The report engine: turns a request (range, comparison, grain, filters,
 * dimension, limit) into answers from the statistics tables.
 *
 * Every report the REST API, WP-CLI and the dashboard show comes from
 * here, so the numbers agree everywhere. Metric definitions, ranges and
 * filters: docs/architecture.md → Reports. Answers are cached for five
 * minutes by request and data version (the processor's last run).
 *
 * Whole days that are summarised (SEOProStats_Rollup) come from the daily
 * table when the request has no filter, or one filter of one visit value
 * (breakdowns: no filter); the rest of the range, such as today, and
 * every other request, from the fact tables: visits by their start time
 * (index `started`), pageviews and events through their visit (index
 * `session_seq`). Both give the same numbers.
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

final class SEOProStats_Query {

    /** Named ranges. */
    const RANGES = array('realtime', 'today', 'yesterday', '24h', '7d', '30d', '90d', 'week', 'month', 'year', '12mo', 'lastyear', 'all', 'custom');

    /** Comparisons. */
    const COMPARE = array('none', 'prev', 'year');

    /** Time series grains. */
    const GRAINS = array('auto', 'hour', 'day', 'month');

    /** Filter operators. */
    const OPS = array('is', 'is_not', 'contains', 'matches');

    /**
     * Dimensions for filters and breakdowns: name => [level, column, kind].
     * Level: session (a column of the visit), page (pageviews), event
     * (events). Kind: a dictionary kind, or 'enum' or 'text'.
     */
    const DIMENSIONS = array(
        'channel'      => array('session', 'channel', 'enum'),
        'source'       => array('session', 'ref_host_id', SEOProStats_Schema::DICT_HOST),
        'utm_source'   => array('session', 'utm_source_id', SEOProStats_Schema::DICT_UTM),
        'utm_medium'   => array('session', 'utm_medium_id', SEOProStats_Schema::DICT_UTM),
        'utm_campaign' => array('session', 'utm_campaign_id', SEOProStats_Schema::DICT_UTM),
        'utm_term'     => array('session', 'utm_term_id', SEOProStats_Schema::DICT_UTM),
        'utm_content'  => array('session', 'utm_content_id', SEOProStats_Schema::DICT_UTM),
        'country'      => array('session', 'country', 'text'),
        'device'       => array('session', 'device', 'enum'),
        'browser'      => array('session', 'browser_id', SEOProStats_Schema::DICT_BROWSER),
        'os'           => array('session', 'os_id', SEOProStats_Schema::DICT_OS),
        'language'     => array('session', 'lang_id', SEOProStats_Schema::DICT_LANGUAGE),
        'entry'        => array('session', 'entry_id', SEOProStats_Schema::DICT_PATH),
        'exit'         => array('session', 'exit_id', SEOProStats_Schema::DICT_PATH),
        'page'         => array('page', 'path_id', SEOProStats_Schema::DICT_PATH),
        'event'        => array('event', 'name_id', SEOProStats_Schema::DICT_EVENT),
    );

    /** Channel codes (SEOProStats_Channels) by name. */
    const CHANNELS = array(
        'direct'         => 0,
        'organic_search' => 1,
        'paid_search'    => 2,
        'ai'             => 3,
        'organic_social' => 4,
        'paid_social'    => 5,
        'email'          => 6,
        'referral'       => 7,
        'paid_other'     => 8,
    );

    /** Device codes (SEOProStats_UA) by name. */
    const DEVICES = array(
        'unknown' => 0,
        'desktop' => 1,
        'mobile'  => 2,
        'tablet'  => 3,
    );

    /** Seconds an answer is kept. */
    const CACHE_TTL = 300;

    /** Most rows in a breakdown. */
    const MAX_LIMIT = 1000;

    /** Most dictionary ids a contains/matches filter expands to. */
    const MAX_IDS = 5000;

    /** A visit is active when its last hit is this recent (realtime). */
    const ACTIVE = 1800;

    /** Longest visit counted as active (bounds the realtime scan). */
    const LONGEST_VISIT = 21600;

    /**
     * Check and complete a request. Missing values take defaults.
     *
     * @param array<string,mixed> $args range, from, to, compare, grain, filters, dimension, limit, offset.
     * @return array<string,mixed>|WP_Error
     */
    public static function request(array $args) {
        $range = isset($args['range']) && $args['range'] !== '' ? (string) $args['range'] : '7d';
        if (!in_array($range, self::RANGES, true)) {
            return new WP_Error('seoprostats_range', sprintf(/* translators: %s: list of ranges */ __('Range must be one of: %s.', 'seoprostats'), implode(', ', self::RANGES)), array('status' => 400));
        }
        $compare = isset($args['compare']) && $args['compare'] !== '' ? (string) $args['compare'] : 'none';
        if (!in_array($compare, self::COMPARE, true)) {
            return new WP_Error('seoprostats_compare', sprintf(/* translators: %s: list of comparisons */ __('Comparison must be one of: %s.', 'seoprostats'), implode(', ', self::COMPARE)), array('status' => 400));
        }
        $grain = isset($args['grain']) && $args['grain'] !== '' ? (string) $args['grain'] : 'auto';
        if (!in_array($grain, self::GRAINS, true)) {
            return new WP_Error('seoprostats_grain', sprintf(/* translators: %s: list of grains */ __('Grain must be one of: %s.', 'seoprostats'), implode(', ', self::GRAINS)), array('status' => 400));
        }
        $dimension = isset($args['dimension']) ? (string) $args['dimension'] : '';
        if ($dimension !== '' && !isset(self::DIMENSIONS[$dimension])) {
            return new WP_Error('seoprostats_dimension', sprintf(/* translators: %s: list of dimensions */ __('Dimension must be one of: %s.', 'seoprostats'), implode(', ', array_keys(self::DIMENSIONS))), array('status' => 400));
        }

        $from = isset($args['from']) ? (string) $args['from'] : '';
        $to   = isset($args['to']) ? (string) $args['to'] : '';
        if ($range === 'custom' && (!self::is_date($from) || !self::is_date($to) || $from > $to)) {
            return new WP_Error('seoprostats_custom', __('A custom range needs from and to dates (YYYY-MM-DD), from not after to.', 'seoprostats'), array('status' => 400));
        }

        $filters = self::parse_filters(isset($args['filters']) ? $args['filters'] : array());
        if (is_wp_error($filters)) {
            return $filters;
        }

        $limit  = isset($args['limit']) ? (int) $args['limit'] : 10;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

        return array(
            'range'     => $range,
            'from'      => $range === 'custom' ? $from : '',
            'to'        => $range === 'custom' ? $to : '',
            'compare'   => $compare,
            'grain'     => $grain,
            'filters'   => $filters,
            'dimension' => $dimension,
            'limit'     => max(1, min(self::MAX_LIMIT, $limit)),
            'offset'    => max(0, $offset),
        );
    }

    /**
     * Read filters: a list of "dimension:operator:value" strings (comma
     * means any of; "\," is a comma in a value), a list of
     * {dimension, op, values} objects, or either as a JSON string.
     *
     * @param mixed $raw Filters.
     * @return array<int,array{dimension:string,op:string,values:string[]}>|WP_Error
     */
    public static function parse_filters($raw) {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return array();
            }
            $json = $raw[0] === '[' ? json_decode($raw, true) : null;
            $raw  = is_array($json) ? $json : array($raw);
        }
        if (!is_array($raw)) {
            return array();
        }

        $out = array();
        foreach ($raw as $filter) {
            if (is_string($filter)) {
                $parts = explode(':', $filter, 3);
                if (count($parts) === 2) {
                    array_splice($parts, 1, 0, 'is');
                }
                if (count($parts) !== 3) {
                    return self::filter_error($filter);
                }
                $split = preg_split('/(?<!\\\\),/', $parts[2]);
                if (!is_array($split)) {
                    return self::filter_error($filter);
                }
                $values = array_map(static function ($v) {
                    return str_replace('\\,', ',', $v);
                }, $split);
                $filter = array('dimension' => $parts[0], 'op' => $parts[1], 'values' => $values);
            }
            if (!is_array($filter) || !isset($filter['dimension'], $filter['values'])) {
                $json = wp_json_encode($filter);
                return self::filter_error(is_string($json) ? $json : '');
            }
            $dimension = (string) $filter['dimension'];
            $op        = isset($filter['op']) ? (string) $filter['op'] : 'is';
            if (!isset(self::DIMENSIONS[$dimension]) || !in_array($op, self::OPS, true)) {
                return self::filter_error($dimension . ':' . $op);
            }
            $values = array_values(array_unique(array_map('strval', (array) $filter['values'])));
            if (!$values || count($values) > 100) {
                return self::filter_error($dimension . ':' . $op);
            }
            $out[] = array('dimension' => $dimension, 'op' => $op, 'values' => $values);
        }
        return $out;
    }

    /**
     * Headline metrics for the range, with the comparison.
     *
     * @param array<string,mixed> $req From request().
     * @return array<string,mixed>
     */
    public static function stats(array $req) {
        return self::cached('stats', $req, static function () use ($req) {
            $range  = self::range($req);
            $answer = array(
                'range'   => self::range_out($range),
                'metrics' => self::totals($range, self::compile($req['filters'], $range)),
            );
            $other = self::compare_range($range, $req['compare']);
            if ($other) {
                $metrics           = self::totals($other, self::compile($req['filters'], $other));
                $answer['compare'] = array(
                    'range'   => self::range_out($other),
                    'metrics' => $metrics,
                    'change'  => self::change($answer['metrics'], $metrics),
                );
            }
            return $answer;
        });
    }

    /**
     * Metrics per hour, day or month across the range, with the comparison
     * point for point.
     *
     * @param array<string,mixed> $req From request().
     * @return array<string,mixed>
     */
    public static function timeseries(array $req) {
        return self::cached('timeseries', $req, static function () use ($req) {
            $range  = self::range($req);
            $grain  = self::grain($range, $req['grain']);
            $answer = array(
                'range'  => self::range_out($range),
                'grain'  => $grain,
                'points' => self::series($range, $grain, self::compile($req['filters'], $range)),
            );
            $other = self::compare_range($range, $req['compare']);
            if ($other) {
                $answer['compare'] = array(
                    'range'  => self::range_out($other),
                    'points' => self::series($other, $grain, self::compile($req['filters'], $other)),
                );
            }
            return $answer;
        });
    }

    /**
     * Top values of a dimension, with their metrics and share of visits.
     *
     * @param array<string,mixed> $req From request(); dimension required.
     * @return array<string,mixed>|WP_Error
     */
    public static function breakdown(array $req) {
        if ($req['dimension'] === '') {
            return new WP_Error('seoprostats_dimension', __('A breakdown needs a dimension.', 'seoprostats'), array('status' => 400));
        }
        return self::cached('breakdown', $req, static function () use ($req) {
            $range    = self::range($req);
            $compiled = self::compile($req['filters'], $range);
            $total    = self::totals($range, $compiled);
            $rows     = self::rows($req['dimension'], $range, $compiled, $req['limit'], $req['offset'], (int) $total['visits']);
            return array(
                'range'     => self::range_out($range),
                'dimension' => $req['dimension'],
                'total'     => $total,
                'rows'      => $rows,
            );
        });
    }

    /**
     * Who is on the site now: visitors active in the last 30 minutes,
     * pageviews per minute, top pages and sources. Hits wait up to a
     * minute in the buffer before they count.
     *
     * @return array<string,mixed>
     */
    public static function realtime() {
        global $wpdb;
        $now   = time();
        $since = $now - self::ACTIVE;
        $s     = SEOProStats_Schema::table('sessions');
        $p     = SEOProStats_Schema::table('pageviews');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `started`; realtime is not cached.
        $visitors = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT visitor) FROM %i WHERE started >= %d AND ended >= %d', $s, $now - self::LONGEST_VISIT, $since));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `ts`.
        $minutes = $wpdb->get_results($wpdb->prepare('SELECT FLOOR((ts - %d) / 60) AS b, COUNT(*) AS n FROM %i WHERE ts >= %d GROUP BY b', $since, $p, $since));
        $per     = array_fill(0, self::ACTIVE / 60, 0);
        foreach ((array) $minutes as $row) {
            $b = (int) $row->b;
            if (isset($per[$b])) {
                $per[$b] = (int) $row->n;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `ts`.
        $pages = $wpdb->get_results($wpdb->prepare('SELECT path_id AS v, COUNT(*) AS n FROM %i WHERE ts >= %d GROUP BY path_id ORDER BY n DESC LIMIT 10', $p, $since));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `started`.
        $sources = $wpdb->get_results($wpdb->prepare('SELECT ref_host_id AS v, COUNT(*) AS n FROM %i WHERE started >= %d AND ended >= %d GROUP BY ref_host_id ORDER BY n DESC LIMIT 10', $s, $now - self::LONGEST_VISIT, $since));

        $text  = self::texts(array_merge(wp_list_pluck((array) $pages, 'v'), wp_list_pluck((array) $sources, 'v')));
        $state = get_option(SEOProStats_Collection::PROCESS_OPTION, array());
        return array(
            'visitors'   => $visitors,
            'pageviews'  => array_sum($per),
            'per_minute' => $per,
            'pages'      => self::pairs((array) $pages, $text, ''),
            'sources'    => self::pairs((array) $sources, $text, __('Direct', 'seoprostats')),
            'processed'  => is_array($state) && isset($state['last']) ? gmdate('c', (int) $state['last']) : null,
            'generated'  => gmdate('c', $now),
        );
    }

    /**
     * Resolve a request's range in the site time zone: [from, to) in Unix
     * seconds, and the site-local days it covers.
     *
     * @param array<string,mixed> $req From request().
     * @return array{key:string,start:DateTimeImmutable,end:DateTimeImmutable,from:int,to:int}
     */
    public static function range(array $req) {
        $tz    = wp_timezone();
        $now   = new DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0);
        $next  = $today->modify('+1 day');
        $key   = (string) $req['range'];

        switch ($key) {
            case 'realtime':
                $start = $now->modify('-30 minutes');
                $end   = $now;
                break;
            case 'today':
                $start = $today;
                $end   = $next;
                break;
            case 'yesterday':
                $start = $today->modify('-1 day');
                $end   = $today;
                break;
            case '24h':
                $end   = $now->setTime((int) $now->format('G'), 0)->modify('+1 hour');
                $start = $end->modify('-24 hours');
                break;
            case '7d':
            case '30d':
            case '90d':
                $end   = $next;
                $start = $end->modify('-' . (int) $key . ' days');
                break;
            case 'week':
                $back  = ((int) $today->format('w') - (int) get_option('start_of_week', 1) + 7) % 7;
                $start = $today->modify("-$back days");
                $end   = $next;
                break;
            case 'month':
                $start = $today->modify('first day of this month');
                $end   = $next;
                break;
            case 'year':
                $start = $today->setDate((int) $today->format('Y'), 1, 1);
                $end   = $next;
                break;
            case '12mo':
                $start = $today->modify('-1 year')->modify('+1 day');
                $end   = $next;
                break;
            case 'lastyear':
                $end   = $today->setDate((int) $today->format('Y'), 1, 1);
                $start = $end->modify('-1 year');
                break;
            case 'all':
                $first = self::first_visit();
                $start = $first ? $now->setTimestamp($first)->setTime(0, 0) : $today;
                $end   = $next;
                break;
            default:
                $start = new DateTimeImmutable((string) $req['from'], $tz);
                $end   = (new DateTimeImmutable((string) $req['to'], $tz))->modify('+1 day');
        }

        return array(
            'key'   => $key,
            'start' => $start,
            'end'   => $end,
            'from'  => $start->getTimestamp(),
            'to'    => $end->getTimestamp(),
        );
    }

    /**
     * The range to compare with, or null. When the range ends in the
     * future (today, this month), the comparison is cut to the same
     * length, so a partial period meets a partial period.
     *
     * @param array<string,mixed> $range   From range().
     * @param string              $compare none, prev or year.
     * @return array<string,mixed>|null
     */
    public static function compare_range(array $range, $compare) {
        if ($compare === 'none' || $range['key'] === 'all') {
            return null;
        }
        /** @var DateTimeImmutable $start */
        $start = $range['start'];
        /** @var DateTimeImmutable $end */
        $end = $range['end'];

        if ($compare === 'year') {
            $other_start = $start->modify('-1 year');
            $other_end   = $end->modify('-1 year');
        } elseif (in_array($range['key'], array('realtime', '24h'), true)) {
            $length      = $range['to'] - $range['from'];
            $other_start = $start->modify("-$length seconds");
            $other_end   = $start;
        } else {
            // Whole days, so each day meets the same weekday and DST is ignored.
            $days        = (int) $start->diff($end)->days;
            $other_start = $start->modify("-$days days");
            $other_end   = $start;
        }

        $to  = $other_end->getTimestamp();
        $now = time();
        if ($range['to'] > $now) {
            $to = min($to, $other_start->getTimestamp() + ($now - $range['from']));
        }
        return array(
            'key'   => $compare,
            'start' => $other_start,
            'end'   => $other_end,
            'from'  => $other_start->getTimestamp(),
            'to'    => $to,
        );
    }

    /**
     * Change from the comparison: (now - then) / then per metric; null when
     * then is 0.
     *
     * @param array<string,int|float> $now  Metrics.
     * @param array<string,int|float> $then Metrics.
     * @return array<string,float|null>
     */
    public static function change(array $now, array $then) {
        $out = array();
        foreach ($now as $metric => $value) {
            $was          = isset($then[$metric]) ? (float) $then[$metric] : 0.0;
            $out[$metric] = $was == 0.0 ? null : round(((float) $value - $was) / $was, 4);
        }
        return $out;
    }

    /**
     * Turn filters into SQL conditions on the visits table (alias s),
     * looking up dictionary ids once.
     *
     * The summary key is the daily table's [dim, val] that answers the same
     * question: [0, 0] (the site) with no filter, the value's row for one
     * "is" filter of one visit value, else null (the fact tables only).
     *
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @param array<string,mixed>                                         $range   From range().
     * @return array{where:string,args:array<int,mixed>,pages:int[]|null,summary:int[]|null}
     */
    public static function compile(array $filters, array $range) {
        $where   = array();
        $args    = array();
        $pages   = null;
        $summary = $filters ? null : array(0, 0);

        foreach ($filters as $filter) {
            list($level, $column, $kind) = self::DIMENSIONS[$filter['dimension']];
            $negate                      = $filter['op'] === 'is_not';
            // One visit value: its daily row (-1: no value matches).
            $single = count($filters) === 1 && $level === 'session' && $filter['op'] === 'is' && count($filter['values']) === 1;

            if ($kind === 'text') {
                if ($single) {
                    $summary = array(SEOProStats_Rollup::DIMS[$filter['dimension']], SEOProStats_Rollup::country_value($filter['values'][0]));
                }
                $values = array_map('strtoupper', $filter['values']);
                if (in_array($filter['op'], array('is', 'is_not'), true)) {
                    $holders = implode(', ', array_fill(0, count($values), '%s'));
                    $where[] = "s.%i " . ($negate ? 'NOT IN' : 'IN') . " ($holders)";
                    $args    = array_merge($args, array($column), $values);
                } else {
                    $likes   = array_map(array(__CLASS__, 'like'), array_fill(0, count($values), $filter['op']), $values);
                    $where[] = '(' . implode(' OR ', array_fill(0, count($likes), 's.%i LIKE %s')) . ')';
                    foreach ($likes as $like) {
                        array_push($args, $column, $like);
                    }
                }
                continue;
            }

            $ids = $kind === 'enum' ? self::codes($filter) : self::dict_ids($kind, $filter);
            if ($single) {
                $summary = array(SEOProStats_Rollup::DIMS[$filter['dimension']], $ids ? (int) $ids[0] : -1);
            }
            if (!$ids) {
                // Nothing matches: "is" selects nothing, "is not" everything.
                if (!$negate) {
                    $where[] = '1 = 0';
                }
                continue;
            }
            $holders = implode(', ', array_fill(0, count($ids), '%d'));

            if ($level === 'session') {
                $where[] = "s.%i " . ($negate ? 'NOT IN' : 'IN') . " ($holders)";
                $args    = array_merge($args, array($column), $ids);
                continue;
            }

            // Pages and events select the visits that have one.
            $table   = SEOProStats_Schema::table($level === 'page' ? 'pageviews' : 'events');
            $where[] = 's.id ' . ($negate ? 'NOT IN' : 'IN') . " (SELECT f.session_id FROM %i f WHERE f.%i IN ($holders) AND f.ts >= %d AND f.ts < %d)";
            $args    = array_merge($args, array($table, $column), $ids, self::fact_window($range));
            if ($level === 'page' && !$negate) {
                $pages = $pages === null ? $ids : array_values(array_intersect($pages, $ids));
            }
        }

        $sql = $where ? ' AND ' . implode(' AND ', $where) : '';
        return array(
            // Placeholders only; values are in args.
            'where'   => $sql,
            'args'    => $args,
            'pages'   => $pages,
            'summary' => $summary,
        );
    }

    /**
     * The part of a range the daily table answers: from its start (a
     * site-local midnight) to the end of the last summarised day or the
     * last whole day of the range, whichever is first. Null when none of
     * it can (filters, a range that starts mid-day, nothing summarised).
     * The fact tables answer from 'split' to the range's end.
     *
     * @param array<string,mixed> $range    From range().
     * @param array<string,mixed> $compiled From compile().
     * @return array{dim:int,val:int,from:string,to:string,split:int}|null Days from (inclusive) to (exclusive).
     */
    private static function summary_part(array $range, array $compiled) {
        if ($compiled['summary'] === null) {
            return null;
        }
        /** @var DateTimeImmutable $start */
        $start   = $range['start'];
        $through = SEOProStats_Rollup::through();
        if ($through === '' || $start->format('H:i:s') !== '00:00:00') {
            return null;
        }
        $tz    = wp_timezone();
        $after = (new DateTimeImmutable($through, $tz))->modify('+1 day');
        $end   = (new DateTimeImmutable('@' . (int) $range['to']))->setTimezone($tz)->setTime(0, 0);
        $stop  = $end < $after ? $end : $after;
        if ($stop->getTimestamp() <= $range['from']) {
            return null;
        }
        return array(
            'dim'   => (int) $compiled['summary'][0],
            'val'   => (int) $compiled['summary'][1],
            'from'  => $start->format('Y-m-d'),
            'to'    => $stop->format('Y-m-d'),
            'split' => $stop->getTimestamp(),
        );
    }

    /**
     * Summed daily rows of one dimension value over the summarised days,
     * by an optional group (day or month).
     *
     * @param array<string,mixed> $part  From summary_part().
     * @param string              $group '', 'day' or 'month'.
     * @return array<string,array<string,mixed>> Group ('' for all) => sums.
     */
    private static function daily_sums(array $part, $group = '') {
        global $wpdb;
        $select = $group === 'month' ? 'LEFT(day, 7)' : ($group === 'day' ? 'day' : "''");
        $by     = $group === '' ? '' : ' GROUP BY b';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `dim_val_day`; $select and $by are fixed SQL.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $select AS b, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(pageviews) AS pageviews, SUM(bounces) AS bounces, SUM(engaged_ms) AS engaged_ms, SUM(events) AS events FROM %i WHERE dim = %d AND val = %d AND day >= %s AND day < %s$by", SEOProStats_Schema::table('daily'), $part['dim'], $part['val'], $part['from'], $part['to']), ARRAY_A);
        $out  = array();
        foreach ((array) $rows as $row) {
            $out[(string) $row['b']] = $row;
        }
        return $out;
    }

    /**
     * Two rows of summed metrics added together.
     *
     * @param array<string,mixed> $a Sums.
     * @param array<string,mixed> $b Sums.
     * @return array<string,int>
     */
    private static function add(array $a, array $b) {
        $out = array();
        foreach (array('visitors', 'visits', 'pageviews', 'bounces', 'engaged_ms', 'events') as $key) {
            $out[$key] = (isset($a[$key]) ? (int) $a[$key] : 0) + (isset($b[$key]) ? (int) $b[$key] : 0);
        }
        return $out;
    }

    /**
     * Visit metrics for a range and compiled filters. With a page filter,
     * pageviews counts only views of the matching pages.
     *
     * @param array<string,mixed> $range    From range().
     * @param array<string,mixed> $compiled From compile().
     * @return array<string,int|float>
     */
    private static function totals(array $range, array $compiled) {
        global $wpdb;
        $part = self::summary_part($range, $compiled);
        $row  = array();
        if ($part) {
            $sums = self::daily_sums($part);
            $row  = isset($sums['']) ? $sums[''] : array();
        }
        $from = $part ? $part['split'] : $range['from'];
        if ($from < $range['to']) {
            $where = $compiled['where'];
            $cols  = self::VISIT_METRICS;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `started`; $cols is fixed SQL, $where holds only placeholders from compile().
            $facts = $wpdb->get_row($wpdb->prepare("SELECT $cols FROM %i s WHERE s.started >= %d AND s.started < %d$where", array_merge(array(SEOProStats_Schema::table('sessions'), $from, $range['to']), $compiled['args'])), ARRAY_A);
            $row   = $part ? self::add($row, (array) $facts) : (array) $facts;
        }
        $metrics = self::metrics($row);
        if ($compiled['pages'] !== null) {
            $metrics['pageviews']       = (int) self::page_counts($range, $compiled, '')[''];
            $metrics['views_per_visit'] = $metrics['visits'] ? round($metrics['pageviews'] / $metrics['visits'], 2) : 0;
        }
        return $metrics;
    }

    /** Visit metric columns (alias s). */
    const VISIT_METRICS = 'COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(*) AS visits, COALESCE(SUM(s.pageviews), 0) AS pageviews, COALESCE(SUM(s.pageviews <= 1 AND s.events = 0), 0) AS bounces, COALESCE(SUM(s.engaged_ms), 0) AS engaged_ms, COALESCE(SUM(s.events), 0) AS events';

    /**
     * Pageviews of the filtered pages, by a group expression ('' for all).
     *
     * @param array<string,mixed> $range    From range().
     * @param array<string,mixed> $compiled From compile(), with pages.
     * @param string              $group    Fixed SQL expression on s, or ''.
     * @param int[]               $group_args Its arguments.
     * @return array<string,int> Group => pageviews.
     */
    private static function page_counts(array $range, array $compiled, $group, array $group_args = array()) {
        global $wpdb;
        $pages = (array) $compiled['pages'];
        if (!$pages) {
            return array('' => 0);
        }
        $select  = $group === '' ? "''" : $group;
        $by      = $group === '' ? '' : ' GROUP BY b';
        $holders = implode(', ', array_fill(0, count($pages), '%d'));
        $where   = $compiled['where'];
        $args    = array_merge($group === '' ? array() : $group_args, array(SEOProStats_Schema::table('sessions'), SEOProStats_Schema::table('pageviews')), self::fact_window($range), array($range['from'], $range['to']), $compiled['args'], $pages);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by indexes `path_ts` or `ts`, and the primary key; $select, $by, $where and $holders are fixed SQL and placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $select AS b, COUNT(*) AS n FROM %i s INNER JOIN %i p ON p.session_id = s.id WHERE p.ts >= %d AND p.ts < %d AND s.started >= %d AND s.started < %d$where AND p.path_id IN ($holders)$by", $args));
        $out  = array('' => 0);
        foreach ((array) $rows as $row) {
            $out[(string) $row->b] = (int) $row->n;
        }
        return $out;
    }

    /**
     * Points of a time series, zero-filled up to now.
     *
     * @param array<string,mixed> $range    From range().
     * @param string              $grain    hour, day or month.
     * @param array<string,mixed> $compiled From compile().
     * @return array<int,array<string,mixed>>
     */
    private static function series(array $range, $grain, array $compiled) {
        global $wpdb;
        if ($grain === 'hour') {
            $group      = 'FLOOR((s.started - %d) / 3600)';
            $group_args = array($range['from']);
        } elseif ($grain === 'month') {
            $group      = 'LEFT(s.day, 7)';
            $group_args = array();
        } else {
            $group      = 's.day';
            $group_args = array();
        }
        $part = $grain === 'hour' ? null : self::summary_part($range, $compiled);
        $by   = $part ? self::daily_sums($part, $grain) : array();
        $from = $part ? $part['split'] : $range['from'];
        if ($from < $range['to']) {
            $where = $compiled['where'];
            $cols  = self::VISIT_METRICS;
            $args  = array_merge($group_args, array(SEOProStats_Schema::table('sessions'), $from, $range['to']), $compiled['args']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `started`; $group, $cols and $where are fixed SQL and placeholders.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT $group AS b, $cols FROM %i s WHERE s.started >= %d AND s.started < %d$where GROUP BY b", $args), ARRAY_A);
            foreach ((array) $rows as $row) {
                $key      = (string) $row['b'];
                $by[$key] = isset($by[$key]) ? self::add($by[$key], $row) : $row;
            }
        }
        $page_counts = $compiled['pages'] !== null ? self::page_counts($range, $compiled, $group, $group_args) : null;

        /** @var DateTimeImmutable $at */
        $at     = $range['start'];
        $end    = min($range['to'], time() + 1);
        $step   = array('hour' => '+1 hour', 'day' => '+1 day', 'month' => 'first day of next month')[$grain];
        $points = array();
        for ($i = 0; $at->getTimestamp() < $end && $i < 5000; $i++) {
            if ($grain === 'hour') {
                $key = (string) intdiv($at->getTimestamp() - $range['from'], 3600);
            } else {
                $key = $at->format($grain === 'month' ? 'Y-m' : 'Y-m-d');
            }
            $metrics = self::metrics(isset($by[$key]) ? $by[$key] : array());
            if ($page_counts !== null) {
                $metrics['pageviews']       = isset($page_counts[$key]) ? $page_counts[$key] : 0;
                $metrics['views_per_visit'] = $metrics['visits'] ? round($metrics['pageviews'] / $metrics['visits'], 2) : 0;
            }
            $points[] = array('t' => $at->format('c')) + $metrics;
            $at       = $grain === 'hour' ? $at->setTimestamp($at->getTimestamp() + HOUR_IN_SECONDS) : $at->modify($step);
            if ($grain === 'month') {
                $at = $at->setTime(0, 0);
            }
        }
        return $points;
    }

    /**
     * Breakdown rows of a dimension.
     *
     * @param string              $dimension    Dimension name.
     * @param array<string,mixed> $range        From range().
     * @param array<string,mixed> $compiled     From compile().
     * @param int                 $limit        Rows.
     * @param int                 $offset       Rows skipped.
     * @param int                 $total_visits Visits in the range, for shares.
     * @return array<int,array<string,mixed>>
     */
    private static function rows($dimension, array $range, array $compiled, $limit, $offset, $total_visits) {
        global $wpdb;
        list($level, $column, $kind) = self::DIMENSIONS[$dimension];
        $s     = SEOProStats_Schema::table('sessions');
        $where = $compiled['where'];
        $base  = array($range['from'], $range['to']);
        $part  = $compiled['summary'] === array(0, 0) ? self::summary_part($range, $compiled) : null;

        if ($part) {
            $rows = self::daily_rows($dimension, $range, $part, $limit, $offset);
        } elseif ($level === 'session') {
            $cols = self::VISIT_METRICS;
            $args = array_merge(array($column, $s), $base, $compiled['args'], array($limit, $offset));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `started`; $cols is fixed SQL, $where holds only placeholders from compile().
            $rows = $wpdb->get_results($wpdb->prepare("SELECT s.%i AS v, $cols FROM %i s WHERE s.started >= %d AND s.started < %d$where GROUP BY v ORDER BY visits DESC, v LIMIT %d OFFSET %d", $args), ARRAY_A);
        } elseif ($level === 'page') {
            $holders = '';
            $pages   = array();
            if ($compiled['pages'] !== null) {
                $pages   = $compiled['pages'] ? $compiled['pages'] : array(0);
                $holders = ' AND p.path_id IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
            }
            $args = array_merge(array($s, SEOProStats_Schema::table('pageviews')), self::fact_window($range), $base, $compiled['args'], $pages, array($limit, $offset));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `ts` and the primary key; $where and $holders hold only placeholders.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT p.path_id AS v, COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT p.session_id) AS visits, COUNT(*) AS pageviews, AVG(p.engaged_ms) AS time_on_page, AVG(p.scroll) AS scroll FROM %i s INNER JOIN %i p ON p.session_id = s.id WHERE p.ts >= %d AND p.ts < %d AND s.started >= %d AND s.started < %d$where$holders GROUP BY v ORDER BY pageviews DESC, v LIMIT %d OFFSET %d", $args), ARRAY_A);
        } else {
            $args = array_merge(array($s, SEOProStats_Schema::table('events')), self::fact_window($range), $base, $compiled['args'], array($limit, $offset));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `ts` and the primary key; $where holds only placeholders from compile().
            $rows = $wpdb->get_results($wpdb->prepare("SELECT e.name_id AS v, COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT e.session_id) AS visits, COUNT(*) AS events FROM %i s INNER JOIN %i e ON e.session_id = s.id WHERE e.ts >= %d AND e.ts < %d AND s.started >= %d AND s.started < %d$where GROUP BY v ORDER BY events DESC, v LIMIT %d OFFSET %d", $args), ARRAY_A);
        }

        $rows = (array) $rows;
        $text = is_int($kind) ? self::texts(array_column($rows, 'v')) : array();
        $out  = array();
        foreach ($rows as $row) {
            list($value, $label) = self::label($dimension, $kind, $row['v'], $text);
            if ($level === 'session') {
                $item = self::metrics($row);
            } else {
                $item = array(
                    'visitors' => (int) $row['visitors'],
                    'visits'   => (int) $row['visits'],
                );
                if ($level === 'page') {
                    $item['pageviews']    = (int) $row['pageviews'];
                    $item['time_on_page'] = (int) round((float) $row['time_on_page'] / 1000);
                    $item['scroll']       = (int) round((float) $row['scroll']);
                } else {
                    $item['events']          = (int) $row['events'];
                    $item['conversion_rate'] = $total_visits ? round($row['visits'] / $total_visits, 4) : 0;
                }
            }
            $out[] = array('value' => $value, 'label' => $label) + $item + array('share' => $total_visits ? round($item['visits'] / $total_visits, 4) : 0);
        }
        return $out;
    }

    /**
     * Breakdown rows from the daily table over the summarised days and the
     * fact tables over the rest of the range, added up per value in one
     * query, so the order and paging are those of the whole range. Rows
     * are shaped as rows() reads them from the fact tables.
     *
     * @param string              $dimension Dimension name.
     * @param array<string,mixed> $range     From range().
     * @param array<string,mixed> $part      From summary_part().
     * @param int                 $limit     Rows.
     * @param int                 $offset    Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function daily_rows($dimension, array $range, array $part, $limit, $offset) {
        global $wpdb;
        list($level, $column, $kind) = self::DIMENSIONS[$dimension];
        $daily                       = array(SEOProStats_Schema::table('daily'), SEOProStats_Rollup::DIMS[$dimension], $part['from'], $part['to']);
        $s                           = SEOProStats_Schema::table('sessions');
        $facts                       = array($part['split'], $range['to']);
        $window                      = array($part['split'], (int) $range['to'] + DAY_IN_SECONDS);
        $page                        = array($limit, $offset);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: daily by index `dim_val_day`, the rest of the range by `started` or `ts` and the primary key; $val and $cols are fixed SQL.
        if ($level === 'session') {
            list($val, $val_args) = SEOProStats_Rollup::value_sql($column, $kind);
            $cols                 = self::VISIT_METRICS;
            $rows                 = $wpdb->get_results($wpdb->prepare("SELECT u.v, SUM(u.visitors) AS visitors, SUM(u.visits) AS visits, SUM(u.pageviews) AS pageviews, SUM(u.bounces) AS bounces, SUM(u.engaged_ms) AS engaged_ms, SUM(u.events) AS events FROM (SELECT val AS v, visitors, visits, pageviews, bounces, engaged_ms, events FROM %i WHERE dim = %d AND day >= %s AND day < %s UNION ALL SELECT $val AS v, $cols FROM %i s WHERE s.started >= %d AND s.started < %d GROUP BY v) u GROUP BY u.v ORDER BY visits DESC, u.v LIMIT %d OFFSET %d", array_merge($daily, $val_args, array($s), $facts, $page)), ARRAY_A);
        } elseif ($level === 'page') {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT u.v, SUM(u.visitors) AS visitors, SUM(u.visits) AS visits, SUM(u.pageviews) AS pageviews, SUM(u.engaged_ms) AS engaged_ms, SUM(u.scroll) AS scroll FROM (SELECT val AS v, visitors, visits, pageviews, engaged_ms, scroll FROM %i WHERE dim = %d AND day >= %s AND day < %s UNION ALL SELECT p.path_id AS v, COUNT(DISTINCT s.day, s.visitor), COUNT(DISTINCT p.session_id), COUNT(*), COALESCE(SUM(p.engaged_ms), 0), COALESCE(SUM(p.scroll), 0) FROM %i s INNER JOIN %i p ON p.session_id = s.id WHERE p.ts >= %d AND p.ts < %d AND s.started >= %d AND s.started < %d GROUP BY p.path_id) u GROUP BY u.v ORDER BY pageviews DESC, u.v LIMIT %d OFFSET %d', array_merge($daily, array($s, SEOProStats_Schema::table('pageviews')), $window, $facts, $page)), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT u.v, SUM(u.visitors) AS visitors, SUM(u.visits) AS visits, SUM(u.events) AS events FROM (SELECT val AS v, visitors, visits, events FROM %i WHERE dim = %d AND day >= %s AND day < %s UNION ALL SELECT e.name_id AS v, COUNT(DISTINCT s.day, s.visitor), COUNT(DISTINCT e.session_id), COUNT(*) FROM %i s INNER JOIN %i e ON e.session_id = s.id WHERE e.ts >= %d AND e.ts < %d AND s.started >= %d AND s.started < %d GROUP BY e.name_id) u GROUP BY u.v ORDER BY events DESC, u.v LIMIT %d OFFSET %d', array_merge($daily, array($s, SEOProStats_Schema::table('events')), $window, $facts, $page)), ARRAY_A);
        }
        // phpcs:enable

        $out = array();
        foreach ((array) $rows as $row) {
            if ($kind === 'text') {
                $row['v'] = SEOProStats_Rollup::country_code((int) $row['v']);
            }
            if ($level === 'page') {
                // Averages per view, as rows() reads them.
                $views               = (int) $row['pageviews'];
                $row['time_on_page'] = $views ? (int) $row['engaged_ms'] / $views : 0;
                $row['scroll']       = $views ? (int) $row['scroll'] / $views : 0;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Times within which the pageviews and events of the range's visits
     * fall: from the range's start (nothing happens before its visit
     * starts) to a day past its end (a visit ends with its day: the visitor
     * hash's salt changes at midnight, which starts a new visit). Bounding the
     * fact table by its own `ts` lets MySQL read it through an index
     * instead of scanning it.
     *
     * @param array<string,mixed> $range From range().
     * @return array{0:int,1:int}
     */
    private static function fact_window(array $range) {
        return array((int) $range['from'], (int) $range['to'] + DAY_IN_SECONDS);
    }

    /**
     * Metrics from summed columns.
     *
     * @param array<string,mixed> $row visitors, visits, pageviews, bounces, engaged_ms, events.
     * @return array<string,int|float>
     */
    private static function metrics(array $row) {
        $visits    = isset($row['visits']) ? (int) $row['visits'] : 0;
        $pageviews = isset($row['pageviews']) ? (int) $row['pageviews'] : 0;
        return array(
            'visitors'        => isset($row['visitors']) ? (int) $row['visitors'] : 0,
            'visits'          => $visits,
            'pageviews'       => $pageviews,
            'views_per_visit' => $visits ? round($pageviews / $visits, 2) : 0,
            'bounce_rate'     => $visits ? round((int) $row['bounces'] / $visits, 4) : 0,
            'visit_duration'  => $visits ? (int) round((int) $row['engaged_ms'] / $visits / 1000) : 0,
            'events'          => isset($row['events']) ? (int) $row['events'] : 0,
        );
    }

    /**
     * A dimension value as the API shows it: [value to filter by, label].
     *
     * @param string            $dimension Dimension name.
     * @param int|string        $kind      Dictionary kind, enum or text.
     * @param mixed             $raw       Stored value.
     * @param array<int,string> $text      Dictionary texts by id.
     * @return array{0:string,1:string}
     */
    private static function label($dimension, $kind, $raw, array $text) {
        if ($kind === 'enum') {
            $names = $dimension === 'channel' ? array_flip(self::CHANNELS) : array_flip(self::DEVICES);
            $name  = isset($names[(int) $raw]) ? $names[(int) $raw] : 'unknown';
            $label = $dimension === 'channel' ? self::channel_labels() : self::device_labels();
            return array($name, isset($label[$name]) ? $label[$name] : $name);
        }
        if ($kind === 'text') {
            $code = (string) $raw;
            return array($code, $code === '' ? __('Unknown', 'seoprostats') : $code);
        }
        $value = isset($text[(int) $raw]) ? $text[(int) $raw] : '';
        if ($value !== '') {
            return array($value, $value);
        }
        return array('', $dimension === 'source' ? __('Direct', 'seoprostats') : __('(none)', 'seoprostats'));
    }

    /**
     * Channel labels by name.
     *
     * @return array<string,string>
     */
    public static function channel_labels() {
        return array(
            'direct'         => __('Direct', 'seoprostats'),
            'organic_search' => __('Organic search', 'seoprostats'),
            'paid_search'    => __('Paid search', 'seoprostats'),
            'ai'             => __('AI assistants', 'seoprostats'),
            'organic_social' => __('Organic social', 'seoprostats'),
            'paid_social'    => __('Paid social', 'seoprostats'),
            'email'          => __('Email', 'seoprostats'),
            'referral'       => __('Referral', 'seoprostats'),
            'paid_other'     => __('Other paid', 'seoprostats'),
        );
    }

    /**
     * Device labels by name.
     *
     * @return array<string,string>
     */
    public static function device_labels() {
        return array(
            'unknown' => __('Unknown', 'seoprostats'),
            'desktop' => __('Desktop', 'seoprostats'),
            'mobile'  => __('Mobile', 'seoprostats'),
            'tablet'  => __('Tablet', 'seoprostats'),
        );
    }

    /**
     * Codes of an enum dimension that a filter selects (is_not: the codes
     * it excludes).
     *
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @return int[]
     */
    private static function codes(array $filter) {
        $codes = $filter['dimension'] === 'channel' ? self::CHANNELS : self::DEVICES;
        $out   = array();
        foreach ($codes as $name => $code) {
            foreach ($filter['values'] as $value) {
                $value = strtolower($value);
                if (self::text_matches($filter['op'], $name, $value)) {
                    $out[] = $code;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Dictionary ids a filter selects ('' selects id 0: none).
     *
     * @param int                                               $kind   Dictionary kind.
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @return int[]
     */
    private static function dict_ids($kind, array $filter) {
        $ids = in_array('', $filter['values'], true) ? array(0) : array();
        if (in_array($filter['op'], array('is', 'is_not'), true)) {
            return array_merge($ids, SEOProStats_Dict::find($kind, $filter['values']));
        }
        foreach ($filter['values'] as $value) {
            if ($value !== '') {
                $ids = array_merge($ids, SEOProStats_Dict::like($kind, self::like($filter['op'], $value), self::MAX_IDS));
            }
        }
        return array_slice(array_values(array_unique($ids)), 0, self::MAX_IDS);
    }

    /**
     * LIKE pattern for contains (anywhere) or matches (glob: * is any text).
     *
     * @param string $op    contains or matches.
     * @param string $value Text.
     * @return string
     */
    private static function like($op, $value) {
        global $wpdb;
        if ($op === 'matches') {
            return implode('%', array_map(static function ($part) use ($wpdb) {
                return $wpdb->esc_like($part);
            }, explode('*', $value)));
        }
        return '%' . $wpdb->esc_like($value) . '%';
    }

    /**
     * The same matching as like(), in PHP (enum names).
     *
     * @param string $op    Operator.
     * @param string $text  Text tested.
     * @param string $value Filter value.
     * @return bool
     */
    private static function text_matches($op, $text, $value) {
        if ($op === 'contains') {
            return $value !== '' && strpos($text, $value) !== false;
        }
        if ($op === 'matches') {
            $pattern = '~^' . implode('.*', array_map(static function ($part) {
                return preg_quote($part, '~');
            }, explode('*', $value))) . '$~';
            return (bool) preg_match($pattern, $text);
        }
        return $text === $value;
    }

    /**
     * Texts for dictionary ids.
     *
     * @param array<int,mixed> $ids IDs.
     * @return array<int,string>
     */
    private static function texts(array $ids) {
        return SEOProStats_Dict::values(array_map('intval', $ids));
    }

    /**
     * {value, label, count} rows for realtime lists.
     *
     * @param array<int,\stdClass> $rows  Rows with v and n.
     * @param array<int,string> $text  Texts by id.
     * @param string            $empty Label for id 0.
     * @return array<int,array{value:string,label:string,count:int}>
     */
    private static function pairs(array $rows, array $text, $empty) {
        $out = array();
        foreach ($rows as $row) {
            $value = isset($text[(int) $row->v]) ? $text[(int) $row->v] : '';
            $out[] = array(
                'value' => $value,
                'label' => $value === '' ? $empty : $value,
                'count' => (int) $row->n,
            );
        }
        return $out;
    }

    /**
     * Grain for a range: hours up to two days, days up to about four
     * months, else months. Hours are refused past 31 days.
     *
     * @param array<string,mixed> $range From range().
     * @param string              $grain Requested grain.
     * @return string
     */
    private static function grain(array $range, $grain) {
        $days = ($range['to'] - $range['from']) / DAY_IN_SECONDS;
        if ($grain === 'auto') {
            return $days <= 2 ? 'hour' : ($days <= 120 ? 'day' : 'month');
        }
        if ($grain === 'hour' && $days > 31) {
            return 'day';
        }
        return $grain;
    }

    /**
     * A range for answers: ISO times in the site time zone.
     *
     * @param array<string,mixed> $range From range().
     * @return array{key:string,from:string,to:string,timezone:string}
     */
    public static function range_out(array $range) {
        /** @var DateTimeImmutable $start */
        $start = $range['start'];
        return array(
            'key'      => (string) $range['key'],
            'from'     => $start->format('c'),
            'to'       => $start->setTimestamp((int) $range['to'])->format('c'),
            'timezone' => wp_timezone_string(),
        );
    }

    /**
     * Start of the first day with visits, or 0: the first summarised day,
     * as retention deletes old visits, else the first visit stored.
     *
     * @return int
     */
    private static function first_visit() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; MIN() on the primary key (day first) reads one entry.
        $day = (string) $wpdb->get_var($wpdb->prepare('SELECT MIN(day) FROM %i', SEOProStats_Schema::table('daily')));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; MIN() on index `started` reads one entry.
        $first = (int) $wpdb->get_var($wpdb->prepare('SELECT MIN(started) FROM %i', SEOProStats_Schema::table('sessions')));
        if ($day !== '') {
            $start = (new DateTimeImmutable($day, wp_timezone()))->getTimestamp();
            $first = $first ? min($first, $start) : $start;
        }
        return $first;
    }

    /**
     * Answer from the cache when the data has not changed since, else work
     * it out and keep it CACHE_TTL seconds. One entry per request (not per
     * data version), so entries never pile up.
     *
     * @param string              $name Report.
     * @param array<string,mixed> $req  From request().
     * @param callable            $work Makes the answer.
     * @return array<string,mixed>
     */
    private static function cached($name, array $req, callable $work) {
        $key     = 'seoprostats_q_' . md5($name . wp_json_encode($req) . get_locale() . wp_timezone_string());
        $state   = get_option(SEOProStats_Collection::PROCESS_OPTION, array());
        $version = is_array($state) && isset($state['last']) ? (int) $state['last'] : 0;
        $object  = wp_using_ext_object_cache();
        $hit     = $object ? wp_cache_get($key, 'seoprostats') : get_transient($key);

        if (is_array($hit) && isset($hit['v'], $hit['d']) && $hit['v'] === $version && (int) $hit['t'] > time() - self::CACHE_TTL) {
            return $hit['d'] + array('generated' => gmdate('c', (int) $hit['t']), 'cached' => true);
        }

        $answer = $work();
        $entry  = array('v' => $version, 't' => time(), 'd' => $answer);
        if ($object) {
            wp_cache_set($key, $entry, 'seoprostats', 300);
        } else {
            set_transient($key, $entry, 300);
        }
        return $answer + array('generated' => gmdate('c'), 'cached' => false);
    }

    /**
     * Whether a text is a YYYY-MM-DD date.
     *
     * @param string $value Text.
     * @return bool
     */
    private static function is_date($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    /**
     * Error for a filter that cannot be read.
     *
     * @param string $filter The filter.
     * @return WP_Error
     */
    private static function filter_error($filter) {
        return new WP_Error(
            'seoprostats_filter',
            sprintf(
                /* translators: 1: the filter, 2: list of dimensions, 3: list of operators */
                __('Cannot read the filter "%1$s". Write dimension:operator:value (comma means any of). Dimensions: %2$s. Operators: %3$s.', 'seoprostats'),
                $filter,
                implode(', ', array_keys(self::DIMENSIONS)),
                implode(', ', self::OPS)
            ),
            array('status' => 400)
        );
    }
}
