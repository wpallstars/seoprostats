<?php
/**
 * Daily summaries and retention.
 *
 * An hour after each site-local day ends, and once every hit received
 * before then is processed, the minute job summarises the day into the
 * daily table: one row for the site and one per value of each dimension,
 * with the numbers the report engine would work out from the fact tables.
 * Long ranges then read a few rows per day instead of every visit. A day
 * can be rebuilt (its rows are replaced in one transaction); the last
 * summarised day is rebuilt once more with the next, for engagement that
 * arrived late. Then, once a day, rows older than their retention are
 * deleted in batches, never from a day that is not summarised.
 * Design: docs/architecture.md → Processing and Storage → Retention.
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

final class SEOProStats_Rollup {

    /** Progress (autoload off): through (last summarised day), pruned (day of the last full prune), kept_from (oldest time kept). */
    const STATE_OPTION = SEOProStats_Collection::ROLLUP_OPTION;

    /**
     * Dimension codes in the daily table, for SEOProStats_Query::DIMENSIONS.
     * Stored in every row: never change or reuse one. 0 is the site.
     */
    const DIMS = array(
        'channel'      => 1,
        'source'       => 2,
        'utm_source'   => 3,
        'utm_medium'   => 4,
        'utm_campaign' => 5,
        'utm_term'     => 6,
        'utm_content'  => 7,
        'country'      => 8,
        'device'       => 9,
        'browser'      => 10,
        'os'           => 11,
        'language'     => 12,
        'entry'        => 13,
        'exit'         => 14,
        'page'         => 15,
        'event'        => 16,
    );

    /** Seconds per run. */
    const BUDGET = 20;

    /** Rows deleted per query. */
    const BATCH = 5000;

    /** Seconds after a day ends before it is summarised: engagement of its last pages arrives late. */
    const GRACE = 3600;

    /** Default retention in months (0 keeps forever): visits with their pageviews; events. */
    const RETENTION = array(
        'visits' => 75,
        'events' => 120,
    );

    /**
     * Summarise the finished days that are due, then prune once a day.
     *
     * @return array{days:int,deleted:int} This run's work.
     */
    public static function run() {
        $start = microtime(true);
        $done  = array('days' => 0, 'deleted' => 0);
        if (!SEOProStats_Schema::is_current()) {
            return $done;
        }
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-query.php';

        $done['days'] = self::catch_up($start);
        $state        = self::state();
        if (!empty($state['through']) && (!isset($state['pruned']) || $state['pruned'] !== wp_date('Y-m-d')) && self::due() === null) {
            $done['deleted'] = self::prune($start)['deleted'];
        }
        return $done;
    }

    /**
     * Summarise each due day, oldest first, within the budget.
     *
     * @param float $start microtime(true) when the run began.
     * @return int Days summarised.
     */
    public static function catch_up($start) {
        $day = self::due();
        if ($day === null) {
            return 0;
        }
        $state = self::state();
        $count = 0;
        if (!empty($state['through']) && SEOProStats_Feature::more_time($start, self::BUDGET)) {
            // Again, with engagement that arrived after it was summarised.
            self::summarise(new DateTimeImmutable((string) $state['through'], wp_timezone()));
        }
        while ($day !== null && SEOProStats_Feature::more_time($start, self::BUDGET)) {
            if (!self::summarise($day)) {
                break;
            }
            $state['through'] = $day->format('Y-m-d');
            update_option(self::STATE_OPTION, $state, false);
            $count++;
            $day = self::due();
        }
        return $count;
    }

    /**
     * The next day to summarise, if it is due: finished, an hour past, and
     * every hit received by then processed. Null when none is.
     *
     * @return DateTimeImmutable|null Its site-local midnight.
     */
    public static function due() {
        $tz    = wp_timezone();
        $state = self::state();
        if (!empty($state['through'])) {
            $day = (new DateTimeImmutable((string) $state['through'], $tz))->modify('+1 day');
        } else {
            $first = self::first_visit();
            if (!$first) {
                return null;
            }
            $day = (new DateTimeImmutable('@' . $first))->setTimezone($tz)->setTime(0, 0);
        }
        $today = new DateTimeImmutable('today', $tz);
        if ($day >= $today || $day->modify('+1 day')->getTimestamp() + self::GRACE > self::clear()) {
            return null;
        }
        return $day;
    }

    /**
     * Time before which every received hit is processed: now when nothing
     * waits, else when the processor took the last file it finished.
     *
     * @return int Unix time.
     */
    public static function clear() {
        $dir = SEOProStats_Collection::dir();
        clearstatcache();
        if (!glob($dir . '/processing-*.php')) {
            $buffer = $dir . '/buffer.php';
            if (!is_file($buffer) || filesize($buffer) === 0) {
                return time();
            }
        }
        $state = get_option(SEOProStats_Collection::PROCESS_OPTION, array());
        return is_array($state) && isset($state['clear']) ? (int) $state['clear'] : 0;
    }

    /**
     * Replace one day's summaries with the day's visits, pageviews and
     * events (by visit start, as the report engine counts them).
     *
     * @param DateTimeImmutable $day Site-local midnight.
     * @return bool Whether the day was written (false: a query failed, or its visits are pruned).
     */
    public static function summarise(DateTimeImmutable $day) {
        global $wpdb;
        $from = $day->getTimestamp();
        $to   = $day->modify('+1 day')->getTimestamp();
        $date = $day->format('Y-m-d');
        if ($from < self::kept_from()) {
            return false;
        }
        $d    = SEOProStats_Schema::table('daily');
        $s    = SEOProStats_Schema::table('sessions');
        $cols = SEOProStats_Query::VISIT_METRICS;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables, one day by index `started`; $cols and $val are fixed SQL.
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE day = %s', $d, $date)) !== false;
        // The site. HAVING: no row for a day without visits.
        $site = $ok ? $wpdb->query($wpdb->prepare("INSERT INTO %i (day, dim, val, visitors, visits, pageviews, bounces, engaged_ms, events) SELECT %s, 0, 0, $cols FROM %i s WHERE s.started >= %d AND s.started < %d HAVING visits > 0", $d, $date, $s, $from, $to)) : false;
        $ok   = $site !== false;

        if ($ok && $site > 0) {
            foreach (SEOProStats_Query::DIMENSIONS as $name => $dimension) {
                list($level, $column, $kind) = $dimension;
                $dim                         = self::DIMS[$name];
                if ($level === 'session') {
                    list($val, $val_args) = self::value_sql($column, $kind);
                    $result               = $wpdb->query($wpdb->prepare("INSERT INTO %i (day, dim, val, visitors, visits, pageviews, bounces, engaged_ms, events) SELECT %s, %d, $val AS v, $cols FROM %i s WHERE s.started >= %d AND s.started < %d GROUP BY v", array_merge(array($d, $date, $dim), $val_args, array($s, $from, $to))));
                } elseif ($level === 'page') {
                    $result = $wpdb->query($wpdb->prepare('INSERT INTO %i (day, dim, val, visitors, visits, pageviews, engaged_ms, scroll) SELECT %s, %d, p.path_id, COUNT(DISTINCT s.day, s.visitor), COUNT(DISTINCT p.session_id), COUNT(*), COALESCE(SUM(p.engaged_ms), 0), COALESCE(SUM(p.scroll), 0) FROM %i s INNER JOIN %i p ON p.session_id = s.id WHERE p.ts >= %d AND p.ts < %d AND s.started >= %d AND s.started < %d GROUP BY p.path_id', $d, $date, $dim, $s, SEOProStats_Schema::table('pageviews'), $from, $to + DAY_IN_SECONDS, $from, $to));
                } else {
                    $result = $wpdb->query($wpdb->prepare('INSERT INTO %i (day, dim, val, visitors, visits, events) SELECT %s, %d, e.name_id, COUNT(DISTINCT s.day, s.visitor), COUNT(DISTINCT e.session_id), COUNT(*) FROM %i s INNER JOIN %i e ON e.session_id = s.id WHERE e.ts >= %d AND e.ts < %d AND s.started >= %d AND s.started < %d GROUP BY e.name_id', $d, $date, $dim, $s, SEOProStats_Schema::table('events'), $from, $to + DAY_IN_SECONDS, $from, $to));
                }
                if ($result === false) {
                    $ok = false;
                    break;
                }
            }
        }
        if ($ok) {
            $wpdb->query('COMMIT');
        } else {
            $wpdb->query('ROLLBACK');
        }
        // phpcs:enable
        return $ok;
    }

    /**
     * Delete rows past their retention, in batches, within the budget.
     * Nothing newer than the last summarised day goes, whatever the setting.
     *
     * @param float $start microtime(true) when the run began.
     * @return array{deleted:int,done:bool} Rows deleted; whether every old row is gone.
     */
    public static function prune($start) {
        global $wpdb;
        $state = self::state();
        if (empty($state['through'])) {
            return array('deleted' => 0, 'done' => true);
        }
        $deleted = 0;
        $jobs    = self::prune_jobs();
        if (isset($jobs['visits'])) {
            // Before deleting: a day whose visits may be gone is never summarised again.
            $state['kept_from'] = max(self::kept_from(), $jobs['visits']);
            update_option(self::STATE_OPTION, $state, false);
        }
        foreach (self::prune_targets($jobs) as $target) {
            list($table, $column, $before, $owner) = $target;
            do {
                if (!SEOProStats_Feature::more_time($start, self::BUDGET)) {
                    return array('deleted' => $deleted, 'done' => false);
                }
                if ($owner) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `ts`, in batches.
                    $rows = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ts < %d AND owner = %d LIMIT %d', $table, $before, $owner, self::BATCH));
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its time index, in batches.
                    $rows = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE %i < %d LIMIT %d', $table, $column, $before, self::BATCH));
                }
                $deleted += (int) $rows;
            } while ($rows === self::BATCH);
        }
        $state           = self::state();
        $state['pruned'] = wp_date('Y-m-d');
        update_option(self::STATE_OPTION, $state, false);
        return array('deleted' => $deleted, 'done' => true);
    }

    /**
     * Rows a prune would delete now, by table, without deleting them.
     *
     * @return array<string,int> Table name (without prefix; props by owner) => rows.
     */
    public static function prune_counts() {
        global $wpdb;
        $out = array();
        foreach (self::prune_targets(self::prune_jobs()) as $target) {
            list($table, $column, $before, $owner) = $target;
            $name = substr($table, strlen(SEOProStats_Schema::table('')));
            if ($owner) {
                $name .= $owner === SEOProStats_Schema::OWNER_PAGEVIEW ? ' (pageviews)' : ' (events)';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `ts`; WP-CLI only.
                $out[$name] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ts < %d AND owner = %d', $table, $before, $owner));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its time index; WP-CLI only.
                $out[$name] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i < %d', $table, $column, $before));
            }
        }
        return $out;
    }

    /**
     * Cut-off time per kind of data: site-local midnight, the retention
     * back from today, but never after the last summarised day.
     *
     * @return array<string,int> visits and events (absent: kept forever) => Unix time.
     */
    private static function prune_jobs() {
        $state = self::state();
        if (empty($state['through'])) {
            return array();
        }
        $tz    = wp_timezone();
        $after = (new DateTimeImmutable((string) $state['through'], $tz))->modify('+1 day')->getTimestamp();
        $today = new DateTimeImmutable('today', $tz);
        $jobs  = array();
        foreach (self::retention() as $kind => $months) {
            if ($months > 0) {
                $jobs[$kind] = min($after, $today->modify("-$months months")->getTimestamp());
            }
        }
        return $jobs;
    }

    /**
     * Tables to prune: [table, time column, before, props owner or 0].
     *
     * @param array<string,int> $jobs From prune_jobs().
     * @return array<int,array{0:string,1:string,2:int,3:int}>
     */
    private static function prune_targets(array $jobs) {
        $out = array();
        if (isset($jobs['visits'])) {
            $out[] = array(SEOProStats_Schema::table('props'), 'ts', $jobs['visits'], SEOProStats_Schema::OWNER_PAGEVIEW);
            $out[] = array(SEOProStats_Schema::table('pageviews'), 'ts', $jobs['visits'], 0);
            $out[] = array(SEOProStats_Schema::table('sessions'), 'started', $jobs['visits'], 0);
        }
        if (isset($jobs['events'])) {
            $out[] = array(SEOProStats_Schema::table('props'), 'ts', $jobs['events'], SEOProStats_Schema::OWNER_EVENT);
            $out[] = array(SEOProStats_Schema::table('events'), 'ts', $jobs['events'], 0);
        }
        return $out;
    }

    /**
     * Retention in months per kind of data.
     *
     * @return array{visits:int,events:int}
     */
    public static function retention() {
        /**
         * Filters how many months visits (with their pageviews and
         * properties) and events are kept; 0 keeps them forever. Daily
         * summaries are always kept.
         *
         * @param array{visits:int,events:int} $months Months by kind, from Settings → Data.
         */
        $months = apply_filters('seoprostats_retention', SEOProStats_Statistics::retention());
        $out    = self::RETENTION;
        foreach ($out as $kind => $default) {
            $out[$kind] = is_array($months) && isset($months[$kind]) && is_numeric($months[$kind]) && (int) $months[$kind] >= 0 ? (int) $months[$kind] : $default;
        }
        return $out;
    }

    /**
     * The daily value expression for a visit column (alias s): the column,
     * or for a country code its two letters as a number.
     *
     * @param string     $column Column of the visits table.
     * @param int|string $kind   Dictionary kind, enum or text.
     * @return array{0:string,1:array<int,string>} SQL with %i placeholders, and their arguments.
     */
    public static function value_sql($column, $kind) {
        if ($kind === 'text') {
            return array('(ASCII(s.%i) * 256 + ASCII(SUBSTRING(s.%i, 2, 1)))', array($column, $column));
        }
        return array('s.%i', array($column));
    }

    /**
     * A country code as its daily value (see value_sql()); -1 for a value
     * that is no code, which matches nothing.
     *
     * @param string $code Two letters, or '' for unknown.
     * @return int
     */
    public static function country_value($code) {
        $code = strtoupper($code);
        if ($code === '') {
            return 0;
        }
        return strlen($code) === 2 ? ord($code[0]) * 256 + ord($code[1]) : -1;
    }

    /**
     * A country's daily value back as its code.
     *
     * @param int $value From country_value().
     * @return string
     */
    public static function country_code($value) {
        $value = (int) $value;
        return $value > 0 ? chr($value >> 8) . chr($value & 255) : '';
    }

    /**
     * The last summarised day (Y-m-d), or '' before the first.
     *
     * @return string
     */
    public static function through() {
        $state = self::state();
        return isset($state['through']) ? (string) $state['through'] : '';
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
     * Oldest visit start kept by retention (0: nothing pruned yet).
     *
     * @return int
     */
    private static function kept_from() {
        $state = self::state();
        return isset($state['kept_from']) ? (int) $state['kept_from'] : 0;
    }

    /**
     * Start of the first visit stored, or 0.
     *
     * @return int
     */
    private static function first_visit() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; MIN() on index `started` reads one entry.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT MIN(started) FROM %i', SEOProStats_Schema::table('sessions')));
    }
}
