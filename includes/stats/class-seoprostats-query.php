<?php
/**
 * The report engine: turns a request (range, comparison, grain, filters,
 * dimension, limit) into answers from the statistics tables.
 *
 * Every report the REST API, WP-CLI and the dashboard show comes from
 * here, so the numbers agree everywhere. Metric definitions, ranges and
 * filters: docs/architecture.md → Reports. Answers are cached for five
 * minutes by request, data set (live or demo, SEOProStats_Schema::set())
 * and data version (the processor's last run).
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

/**
 * One report engine, so the REST API, WP-CLI, abilities and the dashboard
 * agree on every number.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") One engine for every report; each step is a small private helper.
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") Dimension, range and metric tables and their SQL belong to one contract.
 * @SuppressWarnings("PHPMD.TooManyMethods") Named private steps keep each query readable.
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") REST, WP-CLI, abilities, rollup and import code call these entry points.
 */
final class SEOProStats_Query { // NOSONAR: one report engine for REST, WP-CLI, abilities and the dashboard; private helpers decompose its queries.

    /** Named ranges. 7d, 28d, 91d, 182d and 364d are whole weeks, so the previous period starts on the same weekday. */
    const RANGES = array('realtime', 'today', 'yesterday', '24h', '7d', '28d', '30d', '90d', '91d', '182d', '364d', 'week', 'month', 'year', '12mo', 'lastyear', 'all', 'custom');

    /** Ranges of whole days counted back from today: the number is the days. */
    const DAY_RANGES = array('7d', '28d', '30d', '90d', '91d', '182d', '364d');

    /** DAY_RANGES that are whole weeks: search reports keep their length (SEOProStats_Search::days()). */
    const WEEK_RANGES = array('7d', '28d', '91d', '182d', '364d');

    /** Comparisons. */
    const COMPARE = array('none', 'prev', 'year');

    /** Time series grains. */
    const GRAINS = array('auto', 'hour', 'day', 'month');

    /** Filter operators. */
    const OPS = array('is', 'is_not', 'contains', 'matches');

    /**
     * Dimensions for filters and breakdowns: name => [level, column, kind,
     * flag]. Level: session (a column of the visit), page (pageviews),
     * event (events). Kind: a dictionary kind, 'enum', 'text', or
     * 'content' (a column of the pages table, through the pageview's
     * path). Flag (pages): a SEOProStats_Schema::PAGE_* flag the views
     * must have.
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
        'login'        => array('session', 'login', 'enum'),
        'entry'        => array('session', 'entry_id', SEOProStats_Schema::DICT_PATH),
        'exit'         => array('session', 'exit_id', SEOProStats_Schema::DICT_PATH),
        'page'         => array('page', 'path_id', SEOProStats_Schema::DICT_PATH),
        'not_found'    => array('page', 'path_id', SEOProStats_Schema::DICT_PATH, SEOProStats_Schema::PAGE_NOT_FOUND),
        'search'       => array('page', 'search_id', SEOProStats_Schema::DICT_SEARCH, SEOProStats_Schema::PAGE_SEARCH),
        'no_results'   => array('page', 'search_id', SEOProStats_Schema::DICT_SEARCH, SEOProStats_Schema::PAGE_NO_RESULTS),
        'author'       => array('page', 'author_id', 'content'),
        'category'     => array('page', 'term_id', 'content'),
        'post_type'    => array('page', 'post_type', 'content'),
        'event'        => array('event', 'name_id', SEOProStats_Schema::DICT_EVENT),
        // A/B test variants seen (ab_exposures): value "test-id:variant-slug".
        'variant'      => array('variant', 'variant_id', 'variant'),
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

    /** Login codes (SEOProStats_Processor) by name. */
    const LOGINS = array(
        'logged_out' => 0,
        'logged_in'  => 1,
    );

    /** Seconds an answer is kept. */
    const CACHE_TTL = 300;

    /** DateTime modifiers: the next day, and a year back. */
    private const NEXT_DAY    = '+1 day';
    private const YEAR_BEFORE = '-1 year';

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
        $req = array(
            'range'     => self::option_arg($args, 'range', '7d'),
            'from'      => self::option_arg($args, 'from', ''),
            'to'        => self::option_arg($args, 'to', ''),
            'compare'   => self::option_arg($args, 'compare', 'none'),
            'grain'     => self::option_arg($args, 'grain', 'auto'),
            'dimension' => self::option_arg($args, 'dimension', ''),
        );
        $invalid = self::request_invalid($req);
        if ($invalid) {
            return $invalid;
        }

        $filters = self::parse_filters(isset($args['filters']) ? $args['filters'] : array());
        if (is_wp_error($filters)) {
            return $filters;
        }

        $custom = $req['range'] === 'custom';
        return array(
            'range'     => $req['range'],
            'from'      => $custom ? $req['from'] : '',
            'to'        => $custom ? $req['to'] : '',
            'compare'   => $req['compare'],
            'grain'     => $req['grain'],
            'filters'   => $filters,
            'dimension' => $req['dimension'],
            'limit'     => max(1, min(self::MAX_LIMIT, self::number_arg($args, 'limit', 10))),
            'offset'    => max(0, self::number_arg($args, 'offset', 0)),
        );
    }

    /**
     * A text option of a request; empty takes the default.
     *
     * @param array<string,mixed> $args    Request arguments.
     * @param string              $key     Option.
     * @param string              $default Default.
     * @return string
     */
    private static function option_arg(array $args, $key, $default) {
        return isset($args[$key]) && $args[$key] !== '' ? (string) $args[$key] : $default;
    }

    /**
     * A whole-number option of a request.
     *
     * @param array<string,mixed> $args    Request arguments.
     * @param string              $key     Option.
     * @param int                 $default Default when missing.
     * @return int
     */
    private static function number_arg(array $args, $key, $default) {
        return isset($args[$key]) ? (int) $args[$key] : $default;
    }

    /**
     * The first error in a request's options, in the order they are
     * checked (range, comparison, grain, dimension, custom dates), or null.
     *
     * @param array{range:string,from:string,to:string,compare:string,grain:string,dimension:string} $req Options.
     * @return WP_Error|null
     */
    private static function request_invalid(array $req) {
        $error = self::choice_invalid($req);
        if ($error) {
            return $error;
        }
        if ($req['dimension'] !== '' && !isset(self::DIMENSIONS[$req['dimension']])) {
            return new WP_Error('seoprostats_dimension', sprintf(/* translators: %s: list of dimensions */ __('Dimension must be one of: %s.', 'seoprostats'), implode(', ', array_keys(self::DIMENSIONS))), array('status' => 400));
        }
        if ($req['range'] === 'custom' && !self::custom_dates_valid($req['from'], $req['to'])) {
            return new WP_Error('seoprostats_custom', __('A custom range needs from and to dates (YYYY-MM-DD), from not after to.', 'seoprostats'), array('status' => 400));
        }
        return null;
    }

    /**
     * The first error in the fixed-choice options (range, comparison, grain), or null.
     *
     * @param array{range:string,from:string,to:string,compare:string,grain:string,dimension:string} $req Options.
     * @return WP_Error|null
     */
    private static function choice_invalid(array $req) {
        if (!in_array($req['range'], self::RANGES, true)) {
            return new WP_Error('seoprostats_range', sprintf(/* translators: %s: list of ranges */ __('Range must be one of: %s.', 'seoprostats'), implode(', ', self::RANGES)), array('status' => 400));
        }
        if (!in_array($req['compare'], self::COMPARE, true)) {
            return new WP_Error('seoprostats_compare', sprintf(/* translators: %s: list of comparisons */ __('Comparison must be one of: %s.', 'seoprostats'), implode(', ', self::COMPARE)), array('status' => 400));
        }
        if (!in_array($req['grain'], self::GRAINS, true)) {
            return new WP_Error('seoprostats_grain', sprintf(/* translators: %s: list of grains */ __('Grain must be one of: %s.', 'seoprostats'), implode(', ', self::GRAINS)), array('status' => 400));
        }
        return null;
    }

    /**
     * Whether a custom range's dates are both YYYY-MM-DD, from not after to.
     *
     * @param string $from First day.
     * @param string $to   Last day.
     * @return bool
     */
    private static function custom_dates_valid($from, $to) {
        return self::is_date($from) && self::is_date($to) && $from <= $to;
    }

    /**
     * Split a filter's values on commas, reading "\," as a comma and "\\" as
     * a backslash in a value; any other backslash is itself. A scan, not a
     * lookbehind, so a value that ends in a backslash cannot swallow the
     * comma after it (the same rule as packages/core/src/filters.ts).
     *
     * @param string $text The values part of "dimension:operator:values".
     * @return string[]
     */
    private static function split_values($text) {
        $values  = array();
        $current = '';
        $length  = strlen($text);
        $i       = 0;
        while ($i < $length) {
            $char = $text[$i];
            $next = $i + 1 < $length ? $text[$i + 1] : '';
            $i++;
            if ($char === '\\' && ($next === ',' || $next === '\\')) {
                $current .= $next;
                $i++;
            } elseif ($char === ',') {
                $values[] = $current;
                $current  = '';
            } else {
                $current .= $char;
            }
        }
        $values[] = $current;
        return $values;
    }

    /**
     * Read filters: a list of "dimension:operator:value" strings (comma
     * means any of; "\," is a comma and "\\" a backslash in a value), a list
     * of {dimension, op, values} objects, or either as a JSON string.
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
            $parsed = self::parse_filter($filter);
            if (is_wp_error($parsed)) {
                return $parsed;
            }
            $out[] = $parsed;
        }
        return $out;
    }

    /**
     * Normalise and validate one filter, retaining the original error text.
     *
     * @param mixed $filter String or filter object.
     * @return array{dimension:string,op:string,values:string[]}|WP_Error
     */
    private static function parse_filter($filter) {
        if (is_string($filter)) {
            $filter = self::filter_string($filter);
            if (is_wp_error($filter)) {
                return $filter;
            }
        }
        if (!is_array($filter) || !isset($filter['dimension'], $filter['values'])) {
            $json = wp_json_encode($filter);
            return self::filter_error(is_string($json) ? $json : '');
        }
        return self::filter_fields($filter);
    }

    /**
     * Check a filter's dimension, operator (default is) and values (1 to
     * 100, without repeats).
     *
     * @param array<string,mixed> $filter Filter with dimension and values.
     * @return array{dimension:string,op:string,values:string[]}|WP_Error
     */
    private static function filter_fields(array $filter) {
        $dimension = (string) $filter['dimension'];
        $op        = isset($filter['op']) ? (string) $filter['op'] : 'is';
        if (!isset(self::DIMENSIONS[$dimension]) || !in_array($op, self::OPS, true)) {
            return self::filter_error($dimension . ':' . $op);
        }
        $values = array_values(array_unique(array_map('strval', (array) $filter['values'])));
        if (!$values || count($values) > 100) {
            return self::filter_error($dimension . ':' . $op);
        }
        return array('dimension' => $dimension, 'op' => $op, 'values' => $values);
    }

    /**
     * Read a filter string, including the short dimension:values form.
     *
     * @param string $filter Filter string.
     * @return array{dimension:string,op:string,values:string[]}|WP_Error
     */
    private static function filter_string($filter) {
        $parts = explode(':', $filter, 3);
        if (count($parts) === 2) {
            array_splice($parts, 1, 0, 'is');
        }
        if (count($parts) !== 3) {
            return self::filter_error($filter);
        }
        return array('dimension' => $parts[0], 'op' => $parts[1], 'values' => self::split_values($parts[2]));
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
        $state = get_option(SEOProStats_Schema::option(SEOProStats_Collection::PROCESS_OPTION), array());
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
        $key   = (string) $req['range'];

        if ($key === 'realtime' || $key === '24h') {
            list($start, $end) = self::clock_range($key, $now);
        } elseif (in_array($key, array_merge(array('today', 'yesterday', '12mo'), self::DAY_RANGES), true)) {
            list($start, $end) = self::day_range($key, $today);
        } elseif (in_array($key, array('week', 'month', 'year', 'lastyear', 'all'), true)) {
            list($start, $end) = self::calendar_range($key, $now, $today);
        } else {
            $start = new DateTimeImmutable((string) $req['from'], $tz);
            $end   = (new DateTimeImmutable((string) $req['to'], $tz))->modify(self::NEXT_DAY);
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
     * Ranges by the clock: the last 30 minutes, or the 24 hours to the end
     * of this hour.
     *
     * @param string            $key realtime or 24h.
     * @param DateTimeImmutable $now Now, site time.
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable} Start, end.
     */
    private static function clock_range($key, DateTimeImmutable $now) {
        if ($key === 'realtime') {
            return array($now->modify('-30 minutes'), $now);
        }
        $end = $now->setTime((int) $now->format('G'), 0)->modify('+1 hour');
        return array($end->modify('-24 hours'), $end);
    }

    /**
     * Ranges of whole days counted back from today.
     *
     * @param string            $key   today, yesterday, 12mo or one of DAY_RANGES.
     * @param DateTimeImmutable $today Today's midnight, site time.
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable} Start, end.
     */
    private static function day_range($key, DateTimeImmutable $today) {
        $next = $today->modify(self::NEXT_DAY);
        switch ($key) {
            case 'today':
                return array($today, $next);
            case 'yesterday':
                return array($today->modify('-1 day'), $today);
            case '12mo':
                return array($today->modify(self::YEAR_BEFORE)->modify(self::NEXT_DAY), $next);
            default:
                // DAY_RANGES: 7d, 28d, 30d, 90d, 91d, 182d, 364d.
                return array($next->modify('-' . (int) $key . ' days'), $next);
        }
    }

    /**
     * Calendar ranges: this week, month or year to date, last year, and
     * all time (from the first visit).
     *
     * @param string            $key   week, month, year, lastyear or all.
     * @param DateTimeImmutable $now   Now, site time.
     * @param DateTimeImmutable $today Today's midnight, site time.
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable} Start, end.
     */
    private static function calendar_range($key, DateTimeImmutable $now, DateTimeImmutable $today) {
        $next = $today->modify(self::NEXT_DAY);
        $jan  = $today->setDate((int) $today->format('Y'), 1, 1);
        switch ($key) {
            case 'week':
                $back = ((int) $today->format('w') - (int) get_option('start_of_week', 1) + 7) % 7;
                return array($today->modify("-$back days"), $next);
            case 'month':
                return array($today->modify('first day of this month'), $next);
            case 'year':
                return array($jan, $next);
            case 'lastyear':
                return array($jan->modify(self::YEAR_BEFORE), $jan);
            default:
                // all.
                $first = self::first_visit();
                return array($first ? $now->setTimestamp($first)->setTime(0, 0) : $today, $next);
        }
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
            $other_start = $start->modify(self::YEAR_BEFORE);
            $other_end   = $end->modify(self::YEAR_BEFORE);
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
        $out = array(
            'where'   => array(),
            'args'    => array(),
            'pages'   => null,
            'summary' => $filters ? null : array(0, 0),
        );
        foreach ($filters as $filter) {
            self::compile_filter($out, $filter, count($filters) === 1, $range);
        }
        return array(
            // Placeholders only; values are in args.
            'where'   => $out['where'] ? ' AND ' . implode(' AND ', $out['where']) : '',
            'args'    => $out['args'],
            'pages'   => $out['pages'],
            'summary' => $out['summary'],
        );
    }

    /**
     * Add one filter's condition, arguments, pages and summary key.
     *
     * @param array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out    Compiled so far.
     * @param array{dimension:string,op:string,values:string[]}                             $filter Filter.
     * @param bool                                                                           $only   Whether it is the only filter.
     * @param array<string,mixed>                                                            $range  From range().
     * @param-out array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out
     * @return void
     */
    private static function compile_filter(array &$out, array $filter, $only, array $range) {
        list($level, $column, $kind) = self::DIMENSIONS[$filter['dimension']];
        // One visit value: its daily row (-1: no value matches).
        $single = $only && self::one_visit_value($filter, $level);

        if ($kind === 'text') {
            if ($single) {
                $out['summary'] = array(SEOProStats_Rollup::DIMS[$filter['dimension']], SEOProStats_Rollup::country_value($filter['values'][0]));
            }
            self::add_condition($out, self::compile_text($filter, $column));
            return;
        }
        if ($kind === 'variant') {
            self::add_condition($out, self::compile_variant($filter, $range));
            return;
        }
        self::compile_ids($out, $filter, $single, $range);
    }

    /**
     * Whether a filter is "is" one value of a visit dimension the daily
     * table summarises.
     *
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @param string                                            $level  Dimension level.
     * @return bool
     */
    private static function one_visit_value(array $filter, $level) {
        return $level === 'session' && $filter['op'] === 'is' && count($filter['values']) === 1 && isset(SEOProStats_Rollup::DIMS[$filter['dimension']]);
    }

    /**
     * Add an enum, dictionary or content filter: the ids it selects, as a
     * visit column condition or (pages, events) the visits that have one.
     *
     * @param array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out    Compiled so far.
     * @param array{dimension:string,op:string,values:string[]}                             $filter Filter.
     * @param bool                                                                           $single Whether it is the only filter, one visit value.
     * @param array<string,mixed>                                                            $range  From range().
     * @param-out array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out
     * @return void
     */
    private static function compile_ids(array &$out, array $filter, $single, array $range) {
        list($level, $column, $kind) = self::DIMENSIONS[$filter['dimension']];
        $ids = self::filter_ids($kind, $column, $filter);
        if ($kind === 'content') {
            // Authors, categories, post types: the addresses that show them.
            $column = 'path_id';
        }
        if ($single) {
            $out['summary'] = array(SEOProStats_Rollup::DIMS[$filter['dimension']], $ids ? (int) $ids[0] : -1);
        }
        if (!$ids) {
            // Nothing matches: "is" selects nothing, "is not" everything.
            if ($filter['op'] !== 'is_not') {
                $out['where'][] = '1 = 0';
            }
            return;
        }
        if ($level === 'session') {
            $holders = implode(', ', array_fill(0, count($ids), '%d'));
            self::add_condition($out, array(
                'where' => 's.%i ' . self::in_op($filter['op'] === 'is_not') . " ($holders)",
                'args'  => array_merge(array($column), $ids),
            ));
            return;
        }
        self::compile_facts($out, $filter, $level, $column, $ids, $range);
    }

    /**
     * Add a condition and its arguments; an empty condition adds only its
     * arguments.
     *
     * @param array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out       Compiled so far.
     * @param array{where:string,args:array<int,mixed>}                                      $condition Condition.
     * @param-out array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out
     * @return void
     */
    private static function add_condition(array &$out, array $condition) {
        if ($condition['where'] !== '') {
            $out['where'][] = $condition['where'];
        }
        $out['args'] = array_merge($out['args'], $condition['args']);
    }

    /**
     * Ids an enum, dictionary or content filter selects.
     *
     * @param int|string                                        $kind   Dictionary kind, enum or content.
     * @param string                                            $column Column (content: of the pages table).
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @return int[]
     */
    private static function filter_ids($kind, $column, array $filter) {
        if ($kind === 'content') {
            return self::content_paths($column, $filter);
        }
        return $kind === 'enum' ? self::codes($filter) : self::dict_ids((int) $kind, $filter);
    }

    /**
     * IN or NOT IN.
     *
     * @param bool $negate Whether the filter is "is not".
     * @return string
     */
    private static function in_op($negate) {
        return $negate ? 'NOT IN' : 'IN';
    }

    /**
     * Pages and events select the visits that have one (with the
     * dimension's flag: a page not found, a search); a page filter also
     * limits the pageviews counted to its addresses.
     *
     * @param array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out    Compiled so far.
     * @param array{dimension:string,op:string,values:string[]}                             $filter Filter.
     * @param string                                                                         $level  page or event.
     * @param string                                                                         $column Fact column.
     * @param int[]                                                                          $ids    Ids selected.
     * @param array<string,mixed>                                                            $range  From range().
     * @param-out array{where:string[],args:array<int,mixed>,pages:int[]|null,summary:int[]|null} $out
     * @return void
     */
    private static function compile_facts(array &$out, array $filter, $level, $column, array $ids, array $range) {
        $flag    = isset(self::DIMENSIONS[$filter['dimension']][3]) ? self::DIMENSIONS[$filter['dimension']][3] : 0;
        $negate  = $filter['op'] === 'is_not';
        $holders = implode(', ', array_fill(0, count($ids), '%d'));
        $table   = SEOProStats_Schema::table($level === 'page' ? 'pageviews' : 'events');
        $cond    = $flag ? ' AND (f.flags & %d) > 0' : '';
        self::add_condition($out, array(
            'where' => 's.id ' . self::in_op($negate) . " (SELECT f.session_id FROM %i f WHERE f.%i IN ($holders)$cond AND f.ts >= %d AND f.ts < %d)",
            'args'  => array_merge(array($table, $column), $ids, $flag ? array($flag) : array(), self::fact_window($range)),
        ));
        // Pageviews then count views of these addresses only.
        if ($level === 'page' && !$negate && $column === 'path_id') {
            $out['pages'] = $out['pages'] === null ? $ids : array_values(array_intersect($out['pages'], $ids));
        }
    }

    /**
     * Append a country-text condition without changing placeholder order.
     *
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @param string $column Visit column.
     * @return array{where:string,args:array<int,mixed>} Condition and its placeholder arguments.
     */
    private static function compile_text(array $filter, $column) {
        $values = array_map('strtoupper', $filter['values']);
        if (in_array($filter['op'], array('is', 'is_not'), true)) {
            $holders = implode(', ', array_fill(0, count($values), '%s'));
            return array(
                'where' => 's.%i ' . self::in_op($filter['op'] === 'is_not') . " ($holders)",
                'args'  => array_merge(array($column), $values),
            );
        }
        $likes = array_map(array(__CLASS__, 'like'), array_fill(0, count($values), $filter['op']), $values);
        $args  = array();
        foreach ($likes as $like) {
            array_push($args, $column, $like);
        }
        return array('where' => '(' . implode(' OR ', array_fill(0, count($likes), 's.%i LIKE %s')) . ')', 'args' => $args);
    }

    /**
     * Append visits that saw a variant, including others of the same test.
     *
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @param array<string,mixed> $range From range().
     * @return array{where:string,args:array<int,mixed>} Condition and its placeholder arguments.
     */
    private static function compile_variant(array $filter, array $range) {
        $negate = $filter['op'] === 'is_not';
        $pairs  = self::variant_pairs($filter);
        if (!$pairs) {
            return array('where' => $negate ? '' : '1 = 0', 'args' => array());
        }
        list($first, $last) = self::fact_window($range);
        $days               = array((string) wp_date('Y-m-d', $first), (string) wp_date('Y-m-d', $last));
        $ors                = array();
        $sub                = array(SEOProStats_Schema::table('ab_exposures'));
        foreach ($pairs as $pair) {
            $ors[] = '(x.test_id = %d AND x.day >= %s AND x.day <= %s AND x.variant_id = %d)';
            $sub   = array_merge($sub, array($pair[0]), $days, array($pair[1]));
        }
        return array(
            'where' => 's.id ' . self::in_op($negate) . ' (SELECT x.session_id FROM %i x WHERE ' . implode(' OR ', $ors) . ')',
            'args'  => $sub,
        );
    }

    /**
     * The part of a range the daily table answers: from its start (a
     * site-local midnight) to the end of the last summarised day (or,
     * before the first, the last imported one) or the last whole day of
     * the range, whichever is first. Null when none of it can (filters, a
     * range that starts mid-day, nothing summarised or imported).
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
        $through = SEOProStats_Rollup::summary_through();
        if ($through === '' || $start->format('H:i:s') !== '00:00:00') {
            return null;
        }
        $tz    = wp_timezone();
        $after = (new DateTimeImmutable($through, $tz))->modify(self::NEXT_DAY);
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
        $select = "''";
        if ($group === 'month') {
            $select = 'LEFT(day, 7)';
        } elseif ($group === 'day') {
            $select = 'day';
        }
        $by     = $group === '' ? '' : ' GROUP BY b';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `dim_val_day`; $select and $by are fixed SQL.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $select AS b, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(pageviews) AS pageviews, SUM(IF(visits > 0, pageviews, 0)) AS visit_pageviews, SUM(bounces) AS bounces, SUM(engaged_ms) AS engaged_ms, SUM(events) AS events FROM %i WHERE dim = %d AND val = %d AND day >= %s AND day < %s$by", SEOProStats_Schema::table('daily'), $part['dim'], $part['val'], $part['from'], $part['to']), ARRAY_A);
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
        $out['visit_pageviews'] = self::visit_pageviews($a) + self::visit_pageviews($b);
        return $out;
    }

    /**
     * Pageviews of visits, for pages per visit: imported days that kept
     * pageviews but no visits (SEOProStats_Migrate) are left out.
     *
     * @param array<string,mixed> $row Sums; without visit_pageviews, all its pageviews.
     * @return int
     */
    private static function visit_pageviews(array $row) {
        if (isset($row['visit_pageviews'])) {
            return (int) $row['visit_pageviews'];
        }
        return isset($row['pageviews']) ? (int) $row['pageviews'] : 0;
    }

    /**
     * Visit metrics for a range and compiled filters. With a page filter,
     * pageviews counts only views of the matching pages. Also used by
     * SEOProStats_Conversions for conversion rates.
     *
     * @param array<string,mixed> $range    From range().
     * @param array<string,mixed> $compiled From compile().
     * @return array<string,int|float>
     */
    public static function totals(array $range, array $compiled) {
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
            $metrics = self::with_page_views($metrics, (int) self::page_counts($range, $compiled, '')['']);
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
        list($group, $group_args) = self::series_group($grain, $range);
        $by          = self::series_sums($range, $grain, $compiled, $group, $group_args);
        $page_counts = $compiled['pages'] !== null ? self::page_counts($range, $compiled, $group, $group_args) : null;

        /** @var DateTimeImmutable $at */
        $at     = $range['start'];
        $end    = min($range['to'], time() + 1);
        $points = array();
        for ($i = 0; $at->getTimestamp() < $end && $i < 5000; $i++) {
            $key     = self::series_key($at, $grain, $range);
            $metrics = self::metrics(isset($by[$key]) ? $by[$key] : array());
            if ($page_counts !== null) {
                $metrics = self::with_page_views($metrics, isset($page_counts[$key]) ? $page_counts[$key] : 0);
            }
            $points[] = array('t' => $at->format('c')) + $metrics;
            $at       = self::series_next($at, $grain);
        }
        return $points;
    }

    /**
     * The visits table's group expression for a grain, and its arguments.
     *
     * @param string              $grain hour, day or month.
     * @param array<string,mixed> $range From range().
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function series_group($grain, array $range) {
        if ($grain === 'hour') {
            return array('FLOOR((s.started - %d) / 3600)', array($range['from']));
        }
        return array($grain === 'month' ? 'LEFT(s.day, 7)' : 's.day', array());
    }

    /**
     * Summed metrics per point: the daily table over the summarised days
     * (not for hours), then the visits table over the rest.
     *
     * @param array<string,mixed> $range      From range().
     * @param string              $grain      hour, day or month.
     * @param array<string,mixed> $compiled   From compile().
     * @param string              $group      From series_group().
     * @param array<int,mixed>    $group_args Its arguments.
     * @return array<string,array<string,mixed>> Point key => sums.
     */
    private static function series_sums(array $range, $grain, array $compiled, $group, array $group_args) {
        global $wpdb;
        $part = $grain === 'hour' ? null : self::summary_part($range, $compiled);
        $by   = $part ? self::daily_sums($part, $grain) : array();
        $from = $part ? $part['split'] : $range['from'];
        if ($from >= $range['to']) {
            return $by;
        }
        $where = $compiled['where'];
        $cols  = self::VISIT_METRICS;
        $args  = array_merge($group_args, array(SEOProStats_Schema::table('sessions'), $from, $range['to']), $compiled['args']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `started`; $group, $cols and $where are fixed SQL and placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $group AS b, $cols FROM %i s WHERE s.started >= %d AND s.started < %d$where GROUP BY b", $args), ARRAY_A);
        foreach ((array) $rows as $row) {
            $key      = (string) $row['b'];
            $by[$key] = isset($by[$key]) ? self::add($by[$key], $row) : $row;
        }
        return $by;
    }

    /**
     * A point's key: hours since the range's start, or its day or month.
     *
     * @param DateTimeImmutable   $at    The point's start.
     * @param string              $grain hour, day or month.
     * @param array<string,mixed> $range From range().
     * @return string
     */
    private static function series_key(DateTimeImmutable $at, $grain, array $range) {
        if ($grain === 'hour') {
            return (string) intdiv($at->getTimestamp() - $range['from'], 3600);
        }
        return $at->format($grain === 'month' ? 'Y-m' : 'Y-m-d');
    }

    /**
     * The next point's start.
     *
     * @param DateTimeImmutable $at    The point's start.
     * @param string            $grain hour, day or month.
     * @return DateTimeImmutable
     */
    private static function series_next(DateTimeImmutable $at, $grain) {
        if ($grain === 'hour') {
            return $at->setTimestamp($at->getTimestamp() + HOUR_IN_SECONDS);
        }
        if ($grain === 'month') {
            return $at->modify('first day of next month')->setTime(0, 0);
        }
        return $at->modify(self::NEXT_DAY);
    }

    /**
     * Metrics with the pageviews of the filtered pages, and pages per
     * visit from them.
     *
     * @param array<string,int|float> $metrics From metrics().
     * @param int                     $views   Pageviews of the filtered pages.
     * @return array<string,int|float>
     */
    private static function with_page_views(array $metrics, $views) {
        $metrics['pageviews']       = $views;
        $metrics['views_per_visit'] = $metrics['visits'] ? round($metrics['pageviews'] / $metrics['visits'], 2) : 0;
        return $metrics;
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
        list($level, , $kind) = self::DIMENSIONS[$dimension];
        $rows    = self::fetch_rows($dimension, $range, $compiled, $limit, $offset);
        $text    = self::row_texts($dimension, $kind, $rows);
        $running = $dimension === 'page' && $rows && class_exists('SEOProStats_AB_Report') ? SEOProStats_AB_Report::running_paths() : array();
        $out     = array();
        foreach ($rows as $row) {
            list($value, $label) = self::label($dimension, $kind, $row['v'], $text);
            $item                = self::row_metrics($level, $row, $total_visits);
            $item               += array('share' => $total_visits ? round($item['visits'] / $total_visits, 4) : 0);
            if (isset($running[$value])) {
                // The page has a running A/B test.
                $item['ab_test'] = true;
            }
            $out[] = array('value' => $value, 'label' => $label) + $item;
        }
        return $out;
    }

    /**
     * A breakdown's rows from the daily and fact tables, or the fact
     * tables alone, by the dimension's level.
     *
     * @param string              $dimension Dimension name.
     * @param array<string,mixed> $range     From range().
     * @param array<string,mixed> $compiled  From compile().
     * @param int                 $limit     Rows.
     * @param int                 $offset    Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function fetch_rows($dimension, array $range, array $compiled, $limit, $offset) {
        $level = self::DIMENSIONS[$dimension][0];
        $part  = $compiled['summary'] === array(0, 0) && isset(SEOProStats_Rollup::DIMS[$dimension]) ? self::summary_part($range, $compiled) : null;
        if ($part) {
            return self::daily_rows($dimension, $range, $part, $limit, $offset);
        }
        if ($level === 'session') {
            return self::session_rows($dimension, $range, $compiled, $limit, $offset);
        }
        if ($level === 'page') {
            return self::page_rows($dimension, $range, $compiled, $limit, $offset);
        }
        if ($level === 'variant') {
            return self::variant_rows($range, $compiled, $limit, $offset);
        }
        return self::event_rows($range, $compiled, $limit, $offset);
    }

    /**
     * Breakdown rows of a visit column.
     *
     * @param string              $dimension Dimension name.
     * @param array<string,mixed> $range     From range().
     * @param array<string,mixed> $compiled  From compile().
     * @param int                 $limit     Rows.
     * @param int                 $offset    Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function session_rows($dimension, array $range, array $compiled, $limit, $offset) {
        global $wpdb;
        $where = $compiled['where'];
        $cols  = self::VISIT_METRICS;
        $args  = array_merge(array(self::DIMENSIONS[$dimension][1], SEOProStats_Schema::table('sessions'), $range['from'], $range['to']), $compiled['args'], array($limit, $offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by index `started`; $cols is fixed SQL, $where holds only placeholders from compile().
        return (array) $wpdb->get_results($wpdb->prepare("SELECT s.%i AS v, $cols FROM %i s WHERE s.started >= %d AND s.started < %d$where GROUP BY v ORDER BY visits DESC, v LIMIT %d OFFSET %d", $args), ARRAY_A);
    }

    /**
     * Breakdown rows of a pageview column, or (content) a pages-table
     * column through the pageview's path.
     *
     * @param string              $dimension Dimension name.
     * @param array<string,mixed> $range     From range().
     * @param array<string,mixed> $compiled  From compile().
     * @param int                 $limit     Rows.
     * @param int                 $offset    Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function page_rows($dimension, array $range, array $compiled, $limit, $offset) {
        global $wpdb;
        list(, $column, $kind) = self::DIMENSIONS[$dimension];
        $where   = $compiled['where'];
        $holders = '';
        $pages   = array();
        if ($compiled['pages'] !== null) {
            $pages   = $compiled['pages'] ? $compiled['pages'] : array(0);
            $holders = ' AND p.path_id IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
        }
        // Content: through the pages table by its primary key. A flag: those views only.
        $flag    = isset(self::DIMENSIONS[$dimension][3]) ? self::DIMENSIONS[$dimension][3] : 0;
        $content = $kind === 'content';
        $value   = $content ? 'pg.%i' : 'p.%i';
        $join    = $content ? ' INNER JOIN %i pg ON pg.path_id = p.path_id' : '';
        $cond    = $flag ? ' AND (p.flags & %d) > 0' : '';
        $args    = array_merge(array($column, SEOProStats_Schema::table('sessions'), SEOProStats_Schema::table('pageviews')), $content ? array(SEOProStats_Schema::table('pages')) : array(), self::fact_window($range), array($range['from'], $range['to']), $flag ? array($flag) : array(), $compiled['args'], $pages, array($limit, $offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `ts` and the primary keys; $value, $join, $cond, $where and $holders are fixed SQL and placeholders.
        return (array) $wpdb->get_results($wpdb->prepare("SELECT $value AS v, COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT p.session_id) AS visits, COUNT(*) AS pageviews, AVG(p.engaged_ms) AS time_on_page, AVG(p.scroll) AS scroll FROM %i s INNER JOIN %i p ON p.session_id = s.id$join WHERE p.ts >= %d AND p.ts < %d AND s.started >= %d AND s.started < %d$cond$where$holders GROUP BY v ORDER BY pageviews DESC, v LIMIT %d OFFSET %d", $args), ARRAY_A);
    }

    /**
     * Breakdown rows of A/B test variants seen, valued "test-id:variant-slug".
     *
     * @param array<string,mixed> $range    From range().
     * @param array<string,mixed> $compiled From compile().
     * @param int                 $limit    Rows.
     * @param int                 $offset   Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function variant_rows(array $range, array $compiled, $limit, $offset) {
        global $wpdb;
        $where = $compiled['where'];
        $args  = array_merge(array(SEOProStats_Schema::table('sessions'), SEOProStats_Schema::table('ab_exposures')), self::fact_window($range), array($range['from'], $range['to']), $compiled['args'], array($limit, $offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `ts` and the primary key; $where holds only placeholders from compile().
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT x.test_id AS t, x.variant_id AS v, COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT x.session_id) AS visits, COUNT(*) AS pageviews, COALESCE(SUM(x.clicks), 0) AS clicks FROM %i s INNER JOIN %i x ON x.session_id = s.id WHERE x.ts >= %d AND x.ts < %d AND s.started >= %d AND s.started < %d$where GROUP BY t, v ORDER BY visits DESC, t, v LIMIT %d OFFSET %d", $args), ARRAY_A);
        $text = self::texts(array_merge(array_column($rows, 't'), array_column($rows, 'v')));
        foreach ($rows as $i => $row) {
            $rows[$i]['v'] = (isset($text[(int) $row['t']]) ? $text[(int) $row['t']] : '') . ':' . (isset($text[(int) $row['v']]) ? $text[(int) $row['v']] : '');
        }
        return $rows;
    }

    /**
     * Breakdown rows of event names.
     *
     * @param array<string,mixed> $range    From range().
     * @param array<string,mixed> $compiled From compile().
     * @param int                 $limit    Rows.
     * @param int                 $offset   Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function event_rows(array $range, array $compiled, $limit, $offset) {
        global $wpdb;
        $where = $compiled['where'];
        $args  = array_merge(array(SEOProStats_Schema::table('sessions'), SEOProStats_Schema::table('events')), self::fact_window($range), array($range['from'], $range['to']), $compiled['args'], array($limit, $offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `ts` and the primary key; $where holds only placeholders from compile().
        return (array) $wpdb->get_results($wpdb->prepare("SELECT e.name_id AS v, COUNT(DISTINCT s.day, s.visitor) AS visitors, COUNT(DISTINCT e.session_id) AS visits, COUNT(*) AS events FROM %i s INNER JOIN %i e ON e.session_id = s.id WHERE e.ts >= %d AND e.ts < %d AND s.started >= %d AND s.started < %d$where GROUP BY v ORDER BY events DESC, v LIMIT %d OFFSET %d", $args), ARRAY_A);
    }

    /**
     * Texts the rows' values need: dictionary texts, or content names.
     *
     * @param string                         $dimension Dimension name.
     * @param int|string                     $kind      Dictionary kind, enum, text, content or variant.
     * @param array<int,array<string,mixed>> $rows      Rows.
     * @return array<int|string,string>
     */
    private static function row_texts($dimension, $kind, array $rows) {
        if (is_int($kind)) {
            return self::texts(array_column($rows, 'v'));
        }
        return $kind === 'content' ? self::content_names($dimension, array_column($rows, 'v')) : array();
    }

    /**
     * A row's metrics by level: visit metrics, or visitors and visits with
     * the level's own (pages: views, time and scroll; variants: views and
     * clicks; events: events and conversion rate).
     *
     * @param string              $level        session, page, variant or event.
     * @param array<string,mixed> $row          Row.
     * @param int                 $total_visits Visits in the range.
     * @return array<string,int|float>
     */
    private static function row_metrics($level, array $row, $total_visits) {
        if ($level === 'session') {
            return self::metrics($row);
        }
        $item = array(
            'visitors' => (int) $row['visitors'],
            'visits'   => (int) $row['visits'],
        );
        if ($level === 'page') {
            $item['pageviews']    = (int) $row['pageviews'];
            $item['time_on_page'] = (int) round((float) $row['time_on_page'] / 1000);
            $item['scroll']       = (int) round((float) $row['scroll']);
        } elseif ($level === 'variant') {
            $item['pageviews'] = (int) $row['pageviews'];
            $item['clicks']    = (int) $row['clicks'];
        } else {
            $item['events']          = (int) $row['events'];
            $item['conversion_rate'] = $total_visits ? round($row['visits'] / $total_visits, 4) : 0;
        }
        return $item;
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
            // Pageviews of imported days without visits stay out of pages per visit (visit_pageviews).
            $rows = $wpdb->get_results($wpdb->prepare("SELECT u.v, SUM(u.visitors) AS visitors, SUM(u.visits) AS visits, SUM(u.pageviews) AS pageviews, SUM(u.bounces) AS bounces, SUM(u.engaged_ms) AS engaged_ms, SUM(u.events) AS events, SUM(u.vp) AS visit_pageviews FROM (SELECT val AS v, visitors, visits, pageviews, bounces, engaged_ms, events, IF(visits > 0, pageviews, 0) AS vp FROM %i WHERE dim = %d AND day >= %s AND day < %s UNION ALL SELECT $val AS v, $cols, COALESCE(SUM(s.pageviews), 0) AS vp FROM %i s WHERE s.started >= %d AND s.started < %d GROUP BY v) u GROUP BY u.v ORDER BY visits DESC, u.v LIMIT %d OFFSET %d", array_merge($daily, $val_args, array($s), $facts, $page)), ARRAY_A);
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
    public static function fact_window(array $range) {
        return array((int) $range['from'], (int) $range['to'] + DAY_IN_SECONDS);
    }

    /**
     * Metrics from summed columns (also SEOProStats_Content's).
     *
     * @param array<string,mixed> $row visitors, visits, pageviews, bounces, engaged_ms, events; visit_pageviews (optional) for pages per visit.
     * @return array<string,int|float>
     */
    public static function metrics(array $row) {
        $visits    = isset($row['visits']) ? (int) $row['visits'] : 0;
        $pageviews = isset($row['pageviews']) ? (int) $row['pageviews'] : 0;
        return array(
            'visitors'        => isset($row['visitors']) ? (int) $row['visitors'] : 0,
            'visits'          => $visits,
            'pageviews'       => $pageviews,
            'views_per_visit' => $visits ? round(self::visit_pageviews($row) / $visits, 2) : 0,
            'bounce_rate'     => $visits ? round((int) $row['bounces'] / $visits, 4) : 0,
            'visit_duration'  => $visits ? (int) round((int) $row['engaged_ms'] / $visits / 1000) : 0,
            'events'          => isset($row['events']) ? (int) $row['events'] : 0,
        );
    }

    /**
     * A dimension value as the API shows it: [value to filter by, label].
     *
     * @param string                   $dimension Dimension name.
     * @param int|string               $kind      Dictionary kind, enum, text or content.
     * @param mixed                    $raw       Stored value.
     * @param array<int|string,string> $text      Dictionary texts by id; content names by value.
     * @return array{0:string,1:string}
     */
    private static function label($dimension, $kind, $raw, array $text) {
        if ($kind === 'enum') {
            return self::enum_label($dimension, (int) $raw);
        }
        if ($kind === 'text') {
            $code = (string) $raw;
            return array($code, $code === '' ? __('Unknown', 'seoprostats') : $code);
        }
        if ($kind === 'content') {
            return self::content_label((string) $raw, $text);
        }
        if ($kind === 'variant') {
            $value  = (string) $raw;
            $labels = SEOProStats_AB_Report::variant_labels();
            return array($value, isset($labels[$value]) ? $labels[$value] : $value);
        }
        return self::dict_label($dimension, isset($text[(int) $raw]) ? $text[(int) $raw] : '');
    }

    /**
     * An enum code's name and label.
     *
     * @param string $dimension channel, device or login.
     * @param int    $code      Stored code.
     * @return array{0:string,1:string}
     */
    private static function enum_label($dimension, $code) {
        $names = array_flip(self::enum_codes($dimension));
        $name  = isset($names[$code]) ? $names[$code] : 'unknown';
        $label = self::enum_labels($dimension);
        return array($name, isset($label[$name]) ? $label[$name] : $name);
    }

    /**
     * A content value and its name (none: no author, category or type).
     *
     * @param string                   $value ID or post type name.
     * @param array<int|string,string> $text  Names by value.
     * @return array{0:string,1:string}
     */
    private static function content_label($value, array $text) {
        if ($value === '' || $value === '0') {
            return array($value, __('(none)', 'seoprostats'));
        }
        return array($value, isset($text[$value]) && $text[$value] !== '' ? $text[$value] : $value);
    }

    /**
     * A dictionary text as value and label; no text reads as the
     * dimension's empty case (no source is Direct).
     *
     * @param string $dimension Dimension name.
     * @param string $value     Dictionary text, or ''.
     * @return array{0:string,1:string}
     */
    private static function dict_label($dimension, $value) {
        if ($value !== '') {
            return array($value, $value);
        }
        if ($dimension === 'search' || $dimension === 'no_results') {
            return array('', __('(words not recorded)', 'seoprostats'));
        }
        if ($dimension === 'source') {
            return array('', __('Direct', 'seoprostats'));
        }
        return array('', __('(none)', 'seoprostats'));
    }

    /**
     * Codes of an enum dimension by name.
     *
     * @param string $dimension channel, device or login.
     * @return array<string,int>
     */
    private static function enum_codes($dimension) {
        if ($dimension === 'channel') {
            return self::CHANNELS;
        }
        return $dimension === 'login' ? self::LOGINS : self::DEVICES;
    }

    /**
     * Labels of an enum dimension's names.
     *
     * @param string $dimension channel, device or login.
     * @return array<string,string>
     */
    private static function enum_labels($dimension) {
        if ($dimension === 'channel') {
            return self::channel_labels();
        }
        if ($dimension === 'login') {
            return array(
                'logged_out' => __('Not logged in', 'seoprostats'),
                'logged_in'  => __('Logged in', 'seoprostats'),
            );
        }
        return self::device_labels();
    }

    /**
     * Names of authors, categories (or other terms) or post types, looked
     * up now (demo data: SEOProStats_Demo's), by value.
     *
     * @param string           $dimension author, category or post_type.
     * @param array<int,mixed> $values    IDs, or post type names.
     * @return array<int|string,string> Value => name (absent: not found); IDs are integer keys.
     */
    public static function content_names($dimension, array $values) {
        $values = array_values(array_unique(array_filter(array_map('strval', $values), static function ($v) {
            return $v !== '' && $v !== '0';
        })));
        if (!$values) {
            return array();
        }
        if (SEOProStats_Schema::set() === 'demo' && class_exists('SEOProStats_Demo')) {
            return self::demo_names($dimension, $values);
        }
        if ($dimension === 'post_type') {
            return self::post_type_names($values);
        }
        return $dimension === 'author' ? self::author_names($values) : self::term_names($values);
    }

    /**
     * Demo data's names by value.
     *
     * @param string   $dimension author, category or post_type.
     * @param string[] $values    Values.
     * @return array<int|string,string>
     */
    private static function demo_names($dimension, array $values) {
        $out = array();
        foreach ($values as $value) {
            $name = SEOProStats_Demo::name($dimension, $value);
            if ($name !== '') {
                $out[$value] = $name;
            }
        }
        return $out;
    }

    /**
     * Post types' singular names by name.
     *
     * @param string[] $values Post type names.
     * @return array<int|string,string>
     */
    private static function post_type_names(array $values) {
        $out = array();
        foreach ($values as $value) {
            $object = get_post_type_object($value);
            if ($object) {
                $out[$value] = (string) $object->labels->singular_name;
            }
        }
        return $out;
    }

    /**
     * Authors' display names by ID.
     *
     * @param string[] $values User IDs.
     * @return array<int|string,string>
     */
    private static function author_names(array $values) {
        $out = array();
        foreach (get_users(array('include' => array_map('intval', $values), 'fields' => array('ID', 'display_name'))) as $user) {
            $out[(string) $user->ID] = (string) $user->display_name;
        }
        return $out;
    }

    /**
     * Terms' names by ID.
     *
     * @param string[] $values Term IDs.
     * @return array<int|string,string>
     */
    private static function term_names(array $values) {
        $out   = array();
        $terms = get_terms(array('include' => array_map('intval', $values), 'hide_empty' => false, 'fields' => 'id=>name'));
        if (!is_array($terms)) {
            return $out;
        }
        foreach ($terms as $id => $name) {
            $out[(string) $id] = (string) $name;
        }
        return $out;
    }

    /**
     * Path ids of the addresses whose author, category or post type a
     * filter selects (is_not: those it excludes). A value matches by its
     * ID or name, or, for contains and matches, its name in any case.
     *
     * @param string                                            $column Column of the pages table.
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @return int[]
     */
    private static function content_paths($column, array $filter) {
        global $wpdb;
        $table = SEOProStats_Schema::table('pages');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by the column's index; a report request.
        $known = array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SELECT DISTINCT %i FROM %i LIMIT %d', $column, $table, self::MAX_IDS)));
        $names = self::content_names($filter['dimension'], $known);
        $chose = array();
        foreach ($known as $value) {
            if (self::content_matches($filter, $value, isset($names[$value]) ? $names[$value] : '')) {
                $chose[] = $value;
            }
        }
        if (!$chose) {
            return array();
        }
        $holders = implode(', ', array_fill(0, count($chose), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by the column's index; fixed placeholders.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT path_id FROM %i WHERE %i IN ($holders) LIMIT %d", array_merge(array($table, $column), $chose, array(self::MAX_IDS)))));
    }

    /**
     * Whether any of a filter's values matches a content value or its name.
     *
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @param string                                            $value  ID or post type name.
     * @param string                                            $name   Its name, or ''.
     * @return bool
     */
    private static function content_matches(array $filter, $value, $name) {
        foreach ($filter['values'] as $wanted) {
            if (self::content_hit($filter['op'], $value, $name, $wanted)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether one wanted value matches: is and is not by value or name in
     * any case; contains and matches by either in any case.
     *
     * @param string $op     Operator.
     * @param string $value  ID or post type name.
     * @param string $name   Its name, or ''.
     * @param string $wanted Filter value.
     * @return bool
     */
    private static function content_hit($op, $value, $name, $wanted) {
        if (in_array($op, array('is', 'is_not'), true)) {
            return $wanted === $value || ($name !== '' && strtolower($wanted) === strtolower($name));
        }
        $needle = strtolower($wanted);
        return self::text_matches($op, strtolower($value), $needle) || ($name !== '' && self::text_matches($op, strtolower($name), $needle));
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
        $codes = self::enum_codes($filter['dimension']);
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
    public static function dict_ids($kind, array $filter) {
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
     * Dictionary id pairs (test, variant) a variant filter selects: "is"
     * and "is not" by value ("test-id:variant-slug", or "test-id:*" for
     * every variant of a test); "contains" and "matches" by value or label
     * ("Test name: Variant B") among the tests in the registry.
     *
     * @param array{dimension:string,op:string,values:string[]} $filter Filter.
     * @return array<int,array{0:int,1:int}>
     */
    private static function variant_pairs(array $filter) {
        $labels = SEOProStats_AB_Report::variant_labels();
        $exact  = in_array($filter['op'], array('is', 'is_not'), true);
        $wanted = array();
        foreach ($filter['values'] as $value) {
            $value   = trim((string) $value);
            $matched = $exact ? self::variant_exact_keys($value, $labels) : self::variant_like_keys($filter['op'], $value, $labels);
            foreach ($matched as $key) {
                $wanted[$key] = true;
            }
        }
        return $wanted ? self::variant_ids(array_keys($wanted)) : array();
    }

    /**
     * Variant keys an exact value names: itself, or every variant of a
     * test for "test-id:*".
     *
     * @param string               $value  Filter value.
     * @param array<string,string> $labels Labels by "test-id:variant-slug".
     * @return string[]
     */
    private static function variant_exact_keys($value, array $labels) {
        if (substr($value, -2) === ':*') {
            $test = substr($value, 0, -1);
            $keys = array();
            foreach (array_keys($labels) as $key) {
                if (strpos($key, $test) === 0) {
                    $keys[] = $key;
                }
            }
            return $keys;
        }
        return strpos($value, ':') !== false ? array($value) : array();
    }

    /**
     * Variant keys whose key or label contains or matches a value.
     *
     * @param string               $op     contains or matches.
     * @param string               $value  Filter value.
     * @param array<string,string> $labels Labels by "test-id:variant-slug".
     * @return string[]
     */
    private static function variant_like_keys($op, $value, array $labels) {
        $needle = strtolower($value);
        $keys   = array();
        foreach ($labels as $key => $label) {
            if (self::text_matches($op, strtolower($key), $needle) || self::text_matches($op, strtolower($label), $needle)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Dictionary id pairs of variant keys in the dictionary (at most 100).
     *
     * @param array<int,int|string> $keys "test-id:variant-slug" keys.
     * @return array<int,array{0:int,1:int}>
     */
    private static function variant_ids(array $keys) {
        $wanted   = array_flip($keys);
        $tests    = array();
        $variants = array();
        foreach (array_keys($wanted) as $key) {
            list($test, $variant) = explode(':', (string) $key, 2);
            $tests[]              = $test;
            $variants[]           = $variant;
        }
        $test_ids    = self::dict_map(SEOProStats_Schema::DICT_AB_TEST, $tests);
        $variant_ids = self::dict_map(SEOProStats_Schema::DICT_AB_VARIANT, $variants);
        $out         = array();
        foreach (array_keys($wanted) as $key) {
            list($test, $variant) = explode(':', (string) $key, 2);
            if (isset($test_ids[$test], $variant_ids[$variant])) {
                $out[] = array($test_ids[$test], $variant_ids[$variant]);
            }
            if (count($out) >= 100) {
                break;
            }
        }
        return $out;
    }

    /**
     * Dictionary ids of texts that are in the dictionary.
     *
     * @param int      $kind  Dictionary kind.
     * @param string[] $texts Texts.
     * @return array<string,int> Text => id.
     */
    private static function dict_map($kind, array $texts) {
        $ids = SEOProStats_Dict::find($kind, array_values(array_unique($texts)));
        $out = array();
        foreach (self::texts($ids) as $id => $text) {
            $out[(string) $text] = (int) $id;
        }
        return $out;
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
    public static function texts(array $ids) {
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
            if ($days <= 2) {
                return 'hour';
            }
            return $days <= 120 ? 'day' : 'month';
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
     * @param array<string,mixed> $req  From request(), and anything else the answer depends on.
     * @param callable            $work Makes the answer.
     * @return array<string,mixed>
     */
    public static function cached($name, array $req, callable $work) {
        $key     = 'seoprostats_q_' . md5($name . wp_json_encode($req) . get_locale() . wp_timezone_string() . SEOProStats_Schema::set()); // NOSONAR nosemgrep: a cache key, not security.
        $state   = get_option(SEOProStats_Schema::option(SEOProStats_Collection::PROCESS_OPTION), array());
        // New hits, or days imported or undone (SEOProStats_Migrate).
        require_once __DIR__ . '/class-seoprostats-rollup.php';
        $version = (is_array($state) && isset($state['last']) ? (int) $state['last'] : 0) . ':' . SEOProStats_Rollup::imported()['at'];
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
