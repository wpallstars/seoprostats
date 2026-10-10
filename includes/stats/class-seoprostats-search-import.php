<?php
/**
 * Imports search data (clicks, impressions and position by page, query,
 * page and query, and device and country) from a connected search engine
 * into the gsc_* tables, one day at a time.
 *
 * Only final days: Search Console's own figures change for about three
 * days, so a day is imported once Search Console marks it final, and never
 * again by the job. On connecting, the 16 months Search Console keeps are
 * imported newest first, in runs a minute apart; after that, the hourly
 * job asks for new final days at most every few hours (one small request
 * when it does). Each run is an imports row, so it can be undone. Runs
 * come from cron, WP-CLI or an administrator only; never a visitor page.
 *
 * Bing Webmaster Tools goes through the same days (its source hands over
 * one day at a time of answers that cover every day), then imports
 * pages with their queries page by page, as Bing gives them, while
 * `pairs_from`/`pairs_to` in the connection's state say a range needs
 * them. Design: docs/architecture.md → Integrations.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Search_Import {

    /** Cron hook: hourly while a source is connected, and a minute apart while catching up. */
    const HOOK = SEOProStats_Collection::IMPORT_HOOK;

    /** Seconds per cron run. */
    const BUDGET = 20;

    /** Seconds between checks for new final days. */
    const CHECK_EVERY = 6 * HOUR_IN_SECONDS;

    /** Rows per INSERT, and per DELETE when undoing or pruning. */
    const CHUNK = 500;
    const BATCH = 5000;

    /** Opens the transaction a replacement of rows runs in. */
    const BEGIN = 'START TRANSACTION';

    /** Default most rows per day and kind (the top by clicks; the API sorts them so). */
    const LIMITS = array(
        'pages'   => 5000,
        'queries' => 5000,
        'pairs'   => 10000,
    );

    /** imports.status */
    const RUNNING = 1;
    const DONE    = 2;
    const FAILED  = 3;
    const UNDONE  = 4;

    /** Tables by kind of row. */
    const TABLES = array(
        'pages'   => 'gsc_pages',
        'queries' => 'gsc_queries',
        'pairs'   => 'gsc_pairs',
        'totals'  => 'gsc_totals',
        'appearance' => 'gsc_appearance',
    );

    /** One run at a time (autoload off): the time it started. */
    const LOCK_OPTION = 'seoprostats_search_import_lock';

    /** The day search rows were last pruned (autoload off). */
    const PRUNED_OPTION = 'seoprostats_search_pruned';

    /** Seconds after which a run's lock counts as left behind. */
    const LOCK_TIME = 10 * MINUTE_IN_SECONDS;

    /** @var array<string,bool>|null The site's hosts, without www. */
    private static $hosts = null;

    /**
     * Schedule the hourly job, or clear it when nothing is connected.
     */
    public static function schedule() {
        $next = wp_next_scheduled(self::HOOK);
        if (SEOProStats_Connections::connected()) {
            if (!$next) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK);
            }
        } elseif ($next) {
            wp_clear_scheduled_hook(self::HOOK);
            wp_clear_scheduled_hook(self::HOOK, array('more'));
        }
    }

    /**
     * Cron: one run of every connected source within the budget, and
     * another in a minute while one is catching up. Then, while Search
     * Console is connected, its sitemaps (daily) and URL inspections
     * (within the daily cap) with their own budget.
     */
    public static function cron() {
        $more      = false;
        $connected = SEOProStats_Connections::connected();
        foreach ($connected as $source) {
            $result = self::run($source, self::BUDGET);
            $more   = $more || (!is_wp_error($result) && !$result['done']);
        }
        if ($more) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, array('more'));
        }
        self::prune(microtime(true));
        // The source key of Search Console (its class loads only when used).
        if (in_array('search-console', $connected, true)) {
            require_once __DIR__ . '/class-seoprostats-inspections.php';
            SEOProStats_Inspections::cron();
        }
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
        $since = (int) get_option(self::LOCK_OPTION, 0);
        if ($since > time() - self::LOCK_TIME) {
            return false;
        }
        // Left behind by a run that died: take it over.
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
     * The error for a run while another one is going.
     *
     * @return WP_Error
     */
    private static function busy() {
        return new WP_Error('seoprostats_import_busy', __('Another import is running. Try again in a few minutes.', 'seoprostats'));
    }

    /**
     * Import a source's new final days, then older ones back to the start
     * of its history, within a budget.
     *
     * @param string $source Source key.
     * @param int    $budget Seconds (0: no limit, for WP-CLI).
     * @param bool   $check  Ask for new final days even if asked recently.
     * @return array{days:int,rows:int,import:int,done:bool,pages?:int}|WP_Error pages: pages whose queries came page by page (Bing).
     */
    public static function run($source, $budget, $check = false) {
        if (!self::lock()) {
            return self::busy();
        }
        try {
            return self::run_days($source, $budget, $check);
        } finally {
            self::unlock();
        }
    }

    /**
     * run(), holding the lock.
     *
     * @param string $source Source key.
     * @param int    $budget Seconds (0: no limit).
     * @param bool   $check  Ask for new final days even if asked recently.
     * @return array{days:int,rows:int,import:int,done:bool,pages?:int}|WP_Error
     */
    private static function run_days($source, $budget, $check) {
        $start = microtime(true);
        if (!$check && !self::due($source)) {
            return array('days' => 0, 'rows' => 0, 'import' => 0, 'done' => true);
        }
        $ready = self::ready($source);
        if (is_wp_error($ready)) {
            return self::failed($source, $ready);
        }
        list($class, $token, $property) = $ready;
        $today = $class::today();
        $state = self::final_state($source, $class, $token, $property, $today, $check);
        if (is_wp_error($state)) {
            return self::failed($source, $state);
        }
        $final = isset($state['final']) ? (string) $state['final'] : '';
        if ($final === '') {
            return array('days' => 0, 'rows' => 0, 'import' => 0, 'done' => true);
        }
        if (!isset($state['through']) || (string) $state['through'] === '') {
            // Nothing yet: the history goes back from the last final day.
            $state = array_merge($state, array('through' => $final, 'back' => self::shift($final, 1), 'first' => self::first_day($class, $token, $property, $final)));
            SEOProStats_Connections::update_state($source, array('through' => $final, 'back' => $state['back'], 'first' => $state['first']));
        }

        $days = self::due_days($class, $state, $final, $today);
        if (!$days) {
            return self::run_extra($source, $class, $token, $property, $start, $budget, array('days' => 0, 'rows' => 0, 'import' => 0, 'done' => true));
        }

        $done = self::import_days($source, $ready, $days, $start, $budget);
        if (is_wp_error($done)) {
            return self::failed($source, $done);
        }
        list($import, $rows, $span) = $done;
        $count = count($span);
        self::finish($import, self::DONE, $span, $rows);
        if (self::disconnected($source, $import)) {
            return array('days' => $count, 'rows' => $rows, 'import' => $import, 'done' => true);
        }
        SEOProStats_Connections::update_state($source, array('last_run' => time(), 'last_import' => $import, 'error' => null, 'error_at' => null));
        self::pairs_due($source, $class, $span);
        self::appearance_due($source, $class, $span);
        $result = array('days' => $count, 'rows' => $rows, 'import' => $import, 'done' => $count === count($days));
        return $result['done'] ? self::run_extra($source, $class, $token, $property, $start, $budget, $result) : $result;
    }

    /**
     * The source's state, with its last final day asked for again when
     * the imported days end before two days ago, at most every few hours.
     *
     * @param string $source   Source key.
     * @param string $class    Source class.
     * @param string $token    Access token.
     * @param string $property Property.
     * @param string $today    The source's today, Y-m-d.
     * @param bool   $check    Ask even if asked recently.
     * @return array<string,mixed>|WP_Error
     */
    private static function final_state($source, $class, $token, $property, $today, $check) {
        $state   = self::state($source);
        $through = isset($state['through']) ? (string) $state['through'] : '';
        $stale   = $through === '' || $through < self::shift($today, -2);
        if ($stale && ($check || empty($state['checked']) || (int) $state['checked'] < time() - self::CHECK_EVERY)) {
            $final = $class::final_through($token, $property);
            if (is_wp_error($final)) {
                return $final;
            }
            $state['final'] = $final;
            SEOProStats_Connections::update_state($source, array('checked' => time(), 'final' => $final));
        }
        return $state;
    }

    /**
     * Days to import: new final days after the last one imported, then
     * the history back to the first day the source keeps.
     *
     * @param string              $class Source class.
     * @param array<string,mixed> $state State with through and back.
     * @param string              $final Last final day.
     * @param string              $today The source's today, Y-m-d.
     * @return array<int,array{0:string,1:string}> Day, and the state key it moves (through or back).
     */
    private static function due_days($class, array $state, $final, $today) {
        $days = array();
        for ($day = self::shift((string) $state['through'], 1); $day <= $final; $day = self::shift($day, 1)) {
            $days[] = array($day, 'through');
        }
        $first = max(isset($state['first']) ? (string) $state['first'] : '', self::shift($today, 0, -self::months($class)));
        for ($day = self::shift((string) $state['back'], -1); $day >= $first; $day = self::shift($day, -1)) {
            $days[] = array($day, 'back');
        }
        return $days;
    }

    /**
     * Import due days in order, within the budget, as one import. A day
     * that fails finishes the import as failed.
     *
     * @param string                              $source Source key.
     * @param array{0:string,1:string,2:string}   $ready  Source class, token and property from ready().
     * @param array<int,array{0:string,1:string}> $days   due_days().
     * @param float                               $start  microtime(true) of the run.
     * @param int                                 $budget Seconds (0: no limit).
     * @return array{0:int,1:int,2:string[]}|WP_Error imports.id (0: none started), rows written, days imported.
     */
    private static function import_days($source, array $ready, array $days, $start, $budget) {
        list($class, $token, $property) = $ready;
        $import = 0;
        $rows   = 0;
        $span   = array();
        foreach ($days as $i => $job) {
            if ($i > 0 && $budget > 0 && !SEOProStats_Feature::more_time($start, $budget)) {
                break;
            }
            if (self::disconnected($source, $import)) {
                break;
            }
            list($day, $edge) = $job;
            if (!$import) {
                $import = self::start($source, $property, $day);
                if (!$import) {
                    return new WP_Error('seoprostats_import_row', __('The import could not be recorded in the database.', 'seoprostats'));
                }
            }
            $added = self::import_day($class, $token, $property, $day, $import);
            if (is_wp_error($added)) {
                self::finish($import, self::FAILED, $span, $rows, $added->get_error_message());
                return $added;
            }
            $rows  += $added;
            $span[] = $day;
            SEOProStats_Connections::update_state($source, array($edge => $day));
        }
        return array($import, $rows, $span);
    }

    /**
     * Queue Google appearances for imported days; merging a range restarts
     * discovery, so no value is missed when older days join the backfill.
     *
     * @param string $source Source key.
     * @param string $class Source class.
     * @param string[] $span Imported days.
     */
    private static function appearance_due($source, $class, array $span) {
        if (!$span || $class !== 'SEOProStats_Source_Search_Console') {
            return;
        }
        $state = self::state($source);
        $from = min($span);
        $to = max($span);
        if (!empty($state['appearance_from'])) {
            $from = min($from, (string) $state['appearance_from']);
            $to = max($to, (string) $state['appearance_to']);
        }
        SEOProStats_Connections::update_state($source, array('appearance_from' => $from, 'appearance_to' => $to, 'appearance_queue' => null));
    }

    /**
     * Finish the source's extra breakdowns under the same run lock.
     *
     * @param string $source Source key.
     * @param string $class Source class.
     * @param string $token Access token.
     * @param string $property Property.
     * @param float $start Run start.
     * @param int $budget Seconds, 0 unlimited.
     * @param array{days:int,rows:int,import:int,done:bool} $result Run so far.
     * @return array{days:int,rows:int,import:int,done:bool,pages?:int}|WP_Error
     */
    private static function run_extra($source, $class, $token, $property, $start, $budget, array $result) {
        if ($class !== 'SEOProStats_Source_Search_Console') {
            return self::run_pairs($source, $class, $token, $property, $start, $budget, $result);
        }
        require_once __DIR__ . '/class-seoprostats-query.php';
        $state = self::state($source);
        $from = isset($state['appearance_from']) ? (string) $state['appearance_from'] : '';
        $to = isset($state['appearance_to']) ? (string) $state['appearance_to'] : '';
        if ($from === '' || $to === '') {
            return $result;
        }
        $result['done'] = false;
        if ($budget > 0 && !SEOProStats_Feature::more_time($start, $budget)) {
            return $result;
        }
        $range = array($from, $to);
        $queue = self::appearance_queue($source, $class, $token, $property, $range, $state);
        if (is_wp_error($queue)) {
            return self::failed($source, $queue);
        }
        $done = self::import_appearances($source, array($class, $token, $property), $range, $queue, $start, $budget);
        if (is_wp_error($done)) {
            return self::failed($source, $done);
        }
        list($import, $rows) = $done;
        if (self::disconnected($source, $import)) {
            $result['done'] = true;
            return $result;
        }
        if ($import) {
            SEOProStats_Connections::update_state($source, array('last_run' => time(), 'last_import' => $import, 'error' => null, 'error_at' => null));
            $result['rows']  += $rows;
            $result['import'] = $import;
        }
        if (!$queue) {
            SEOProStats_Connections::update_state($source, array('appearance_from' => null, 'appearance_to' => null, 'appearance_queue' => null));
            $result['done'] = true;
        }
        return $result;
    }

    /**
     * The appearances left to import for the range: those Google gives now
     * and those already stored (reimport removes values no longer
     * returned), saved in the state when first made.
     *
     * @param string                $source   Source key.
     * @param string                $class    Source class.
     * @param string                $token    Access token.
     * @param string                $property Property.
     * @param array{0:string,1:string} $range First and last day.
     * @param array<string,mixed>   $state    The source's state.
     * @return string[]|WP_Error
     */
    private static function appearance_queue($source, $class, $token, $property, array $range, array $state) {
        global $wpdb;
        if (isset($state['appearance_queue']) && is_array($state['appearance_queue'])) {
            return $state['appearance_queue'];
        }
        list($from, $to) = $range;
        $found = $class::appearances($token, $property, $from, $to);
        if (is_wp_error($found)) {
            return $found;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table's primary key bounds the days.
        $ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT appearance_id FROM %i FORCE INDEX (PRIMARY) WHERE engine = %d AND day >= %s AND day <= %s', SEOProStats_Schema::table('gsc_appearance'), (int) $class::ENGINE, $from, $to));
        $queue = array_values(array_unique(array_merge(array_filter(array_map('strval', array_column(array_column($found, 'keys'), 0))), array_values(SEOProStats_Query::texts(array_map('intval', (array) $ids))))));
        SEOProStats_Connections::update_state($source, array('appearance_queue' => $queue));
        return $queue;
    }

    /**
     * Import queued appearances within the budget, as one import for the
     * run (as for Bing's pages with their queries: one entry in the list,
     * undone together), then finish it.
     *
     * @param string                            $source Source key.
     * @param array{0:string,1:string,2:string} $ready  Source class, token and property.
     * @param array{0:string,1:string}          $range  First and last day.
     * @param string[]                          $queue  appearance_queue(); the values imported are taken off.
     * @param float                             $start  microtime(true) of the run.
     * @param int                               $budget Seconds (0: no limit).
     * @return array{0:int,1:int}|WP_Error imports.id (0: none started) and rows written.
     */
    private static function import_appearances($source, array $ready, array $range, array &$queue, $start, $budget) {
        $import = 0;
        $rows   = 0;
        $days   = array();
        while ($queue && ($budget === 0 || SEOProStats_Feature::more_time($start, $budget))) {
            $step = self::appearance_step($source, $ready, $range, (string) $queue[0], $import);
            if ($step === null) {
                break;
            }
            if (is_wp_error($step)) {
                if ($import) {
                    self::finish($import, self::FAILED, $days, $rows, $step->get_error_message());
                }
                return $step;
            }
            list($data, $added) = $step;
            $rows += $added;
            $days  = self::days_in($days, array_map('strval', array_column(array_column($data, 'keys'), 0)), $range);
            array_shift($queue);
            SEOProStats_Connections::update_state($source, array('appearance_queue' => $queue));
        }
        if ($import) {
            self::finish($import, self::DONE, $days ? $days : $range, $rows);
        }
        return array($import, $rows);
    }

    /**
     * Import one queued appearance, starting the run's import before its
     * first rows are written.
     *
     * @param string                            $source Source key.
     * @param array{0:string,1:string,2:string} $ready  Source class, token and property.
     * @param array{0:string,1:string}          $range  First and last day.
     * @param string                            $value  The appearance.
     * @param int                               $import imports.id, 0 until started; set when started.
     * @return array{0:array<int,array<string,mixed>>,1:int}|WP_Error|null The source's rows and rows written; null when disconnected.
     */
    private static function appearance_step($source, array $ready, array $range, $value, &$import) {
        list($class, $token, $property) = $ready;
        list($from, $to) = $range;
        if (self::disconnected($source, $import)) {
            return null;
        }
        $data = $class::appearances($token, $property, $from, $to, $value);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!$import) {
            // Disconnected during the request above: start no import.
            if (self::disconnected($source, 0)) {
                return null;
            }
            $import = self::start($source, $property, $from);
            if (!$import) {
                return new WP_Error('seoprostats_import_row', __('The import could not be recorded in the database.', 'seoprostats'));
            }
        }
        $added = self::replace_appearance($value, $data, $from, $to, $import);
        return is_wp_error($added) ? $added : array($data, $added);
    }

    /**
     * Days so far with the new days that fall in the range, each once.
     *
     * @param string[]                 $days  Days so far.
     * @param string[]                 $got   New days.
     * @param array{0:string,1:string} $range First and last day.
     * @return string[]
     */
    private static function days_in(array $days, array $got, array $range) {
        list($from, $to) = $range;
        return array_values(array_unique(array_merge($days, array_filter($got, function ($day) use ($from, $to) {
            return $day >= $from && $day <= $to;
        }))));
    }

    /**
     * Atomically replace one appearance's daily rows, including empty days.
     *
     * @param string $value API appearance value.
     * @param array<int,array<string,mixed>> $data Rows grouped by date.
     * @param string $from First day.
     * @param string $to Last day.
     * @param int $import Import ID.
     * @return int|WP_Error Rows written.
     */
    private static function replace_appearance($value, array $data, $from, $to, $import) {
        global $wpdb;
        $ids = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_APPEARANCE, array($value));
        $id = isset($ids[SEOProStats_Dict::clean($value)]) ? (int) $ids[SEOProStats_Dict::clean($value)] : 0;
        $table = SEOProStats_Schema::table('gsc_appearance');
        $wpdb->query(self::BEGIN); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- BEGIN is fixed SQL; one appearance replaced together.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table, bounded by engine and day primary key prefix.
        $ok = $id > 0 && $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d AND day >= %s AND day <= %s AND appearance_id = %d', $table, SEOProStats_Schema::ENGINE_GOOGLE, $from, $to, $id)) !== false;
        $written = 0;
        foreach ($data as $row) {
            $day = isset($row['keys'][0]) ? (string) $row['keys'][0] : '';
            if (!$ok || $day < $from || $day > $to || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $row['keys'] = array($value);
            $added = self::insert($table, 'appearance', SEOProStats_Schema::ENGINE_GOOGLE, $day, $import, self::rows(array('appearance' => array($row)))['appearance']);
            $ok = $added !== false;
            $written += (int) $added;
        }
        if ($ok) {
            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
            return $written;
        }
        $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
        return new WP_Error('seoprostats_import_write', __('The search appearances could not be saved in the database.', 'seoprostats'));
    }

    /**
     * Whether a source gives pages with their queries page by page, after
     * the days (Bing), rather than with each day.
     *
     * @param string $class Source class.
     * @return bool
     */
    public static function by_page($class) {
        return defined($class . '::PAIRS_BY_PAGE') && constant($class . '::PAIRS_BY_PAGE');
    }

    /**
     * Note that pages' queries are due for days just imported (a source
     * that gives them page by page): the range grows to take them, and
     * reaches back over the weeks whose pages' queries may still come.
     * The list of pages is made again for the new range.
     *
     * @param string   $source Source key.
     * @param string   $class  Source class.
     * @param string[] $span   Days imported.
     */
    private static function pairs_due($source, $class, array $span) {
        if (!$span || !self::by_page($class)) {
            return;
        }
        $state = self::state($source);
        $from  = self::shift((string) min($span), -(int) constant($class . '::PAIR_LAG_DAYS'));
        $to    = (string) max($span);
        if (!empty($state['pairs_from'])) {
            $from = min($from, (string) $state['pairs_from']);
            $to   = max($to, (string) $state['pairs_to']);
        }
        SEOProStats_Connections::update_state($source, array('pairs_from' => $from, 'pairs_to' => $to, 'pairs_queue' => null));
    }

    /**
     * Import pages with their queries page by page (Bing: one request a
     * page, every week of the range at once), within the budget. Each
     * page's rows in the range are replaced; each run is an import.
     *
     * @param string                                          $source   Source key.
     * @param string                                          $class    Source class.
     * @param string                                          $token    Token or key.
     * @param string                                          $property Property.
     * @param float                                           $start    microtime(true) of the run.
     * @param int                                             $budget   Seconds (0: no limit).
     * @param array{days:int,rows:int,import:int,done:bool}   $result   The run so far.
     * @return array{days:int,rows:int,import:int,done:bool,pages?:int}|WP_Error
     */
    private static function run_pairs($source, $class, $token, $property, $start, $budget, array $result) {
        if (!self::by_page($class)) {
            return $result;
        }
        $state = self::state($source);
        $from  = isset($state['pairs_from']) ? (string) $state['pairs_from'] : '';
        $to    = isset($state['pairs_to']) ? (string) $state['pairs_to'] : '';
        if ($from === '' || $to === '') {
            return $result;
        }
        $range = array($from, $to);
        $queue = self::pairs_queue($source, $class, $token, $property, $range, $state);
        if (is_wp_error($queue)) {
            return self::failed($source, $queue);
        }
        $done = self::import_pairs($source, array($class, $token, $property), $range, $queue, $start, $budget, $result['days'] > 0);
        if (is_wp_error($done)) {
            return self::failed($source, $done);
        }
        list($import, $rows, $pages) = $done;
        $first = $result['import'] ? $result['import'] : $import;
        if (self::disconnected($source, $import)) {
            return array('days' => $result['days'], 'rows' => $result['rows'] + $rows, 'import' => $first, 'done' => true, 'pages' => $pages);
        }
        if ($import) {
            SEOProStats_Connections::update_state($source, array('last_run' => time(), 'last_import' => $import, 'error' => null, 'error_at' => null));
        }
        if (!$queue) {
            SEOProStats_Connections::update_state($source, array('pairs_from' => null, 'pairs_to' => null, 'pairs_queue' => null));
        }
        return array(
            'days'   => $result['days'],
            'rows'   => $result['rows'] + $rows,
            'import' => $first,
            'done'   => !$queue,
            'pages'  => $pages,
        );
    }

    /**
     * The pages left whose queries to import for the range, one address
     * per page (Bing may list a page with and without www), saved in the
     * state when first made.
     *
     * @param string                   $source   Source key.
     * @param string                   $class    Source class.
     * @param string                   $token    Token or key.
     * @param string                   $property Property.
     * @param array{0:string,1:string} $range    First and last day.
     * @param array<string,mixed>      $state    The source's state.
     * @return string[]|WP_Error Addresses.
     */
    private static function pairs_queue($source, $class, $token, $property, array $range, array $state) {
        if (isset($state['pairs_queue']) && is_array($state['pairs_queue'])) {
            return $state['pairs_queue'];
        }
        $urls = $class::pair_pages($token, $property, $range[0], $range[1]);
        if (is_wp_error($urls)) {
            return $urls;
        }
        $by_path = array();
        foreach ($urls as $url) {
            $path = self::path((string) $url);
            if ($path !== '' && !isset($by_path[$path])) {
                $by_path[$path] = (string) $url;
            }
        }
        $queue = array_values($by_path);
        SEOProStats_Connections::update_state($source, array('pairs_queue' => $queue));
        return $queue;
    }

    /**
     * Import queued pages' queries within the budget, as one import, then
     * finish it.
     *
     * @param string                            $source Source key.
     * @param array{0:string,1:string,2:string} $ready  Source class, token and property.
     * @param array{0:string,1:string}          $range  First and last day.
     * @param string[]                          $queue  pairs_queue(); the pages imported are taken off.
     * @param float                             $start  microtime(true) of the run.
     * @param int                               $budget Seconds (0: no limit).
     * @param bool                              $ran    Whether the run imported days first (then even the first page waits for time).
     * @return array{0:int,1:int,2:int}|WP_Error imports.id (0: none started), rows written, pages imported.
     */
    private static function import_pairs($source, array $ready, array $range, array &$queue, $start, $budget, $ran) {
        $import = 0;
        $rows   = 0;
        $pages  = 0;
        $days   = array();
        while ($queue) {
            if (($pages > 0 || $ran) && $budget > 0 && !SEOProStats_Feature::more_time($start, $budget)) {
                break;
            }
            $step = self::pairs_step($source, $ready, $range, (string) $queue[0], $import);
            if ($step === null) {
                break;
            }
            if (is_wp_error($step)) {
                if ($import) {
                    self::finish($import, self::FAILED, $days, $rows, $step->get_error_message());
                }
                return $step;
            }
            list($data, $added) = $step;
            $rows += $added;
            $days  = array_values(array_unique(array_merge($days, array_map('strval', array_keys($data)))));
            $pages++;
            array_shift($queue);
            SEOProStats_Connections::update_state($source, array('pairs_queue' => $queue));
        }
        if ($import) {
            self::finish($import, self::DONE, $days ? $days : $range, $rows);
        }
        return array($import, $rows, $pages);
    }

    /**
     * Import one queued page's queries, starting the run's import before
     * its first request.
     *
     * @param string                            $source Source key.
     * @param array{0:string,1:string,2:string} $ready  Source class, token and property.
     * @param array{0:string,1:string}          $range  First and last day.
     * @param string                            $url    The page's address.
     * @param int                               $import imports.id, 0 until started; set when started.
     * @return array{0:array<string,array<int,array<string,mixed>>>,1:int}|WP_Error|null The source's rows by day and rows written; null when disconnected.
     */
    private static function pairs_step($source, array $ready, array $range, $url, &$import) {
        list($class, $token, $property) = $ready;
        list($from, $to) = $range;
        if (self::disconnected($source, $import)) {
            return null;
        }
        if (!$import) {
            $import = self::start($source, $property, $from);
            if (!$import) {
                return new WP_Error('seoprostats_import_row', __('The import could not be recorded in the database.', 'seoprostats'));
            }
        }
        $data = $class::page_pairs($token, $property, $url, $from, $to);
        if (is_wp_error($data)) {
            return $data;
        }
        $added = self::replace_pairs((int) $class::ENGINE, $url, $data, $from, $to, $import);
        return is_wp_error($added) ? $added : array($data, $added);
    }

    /**
     * Replace one page's rows with its queries in a range (gsc_pairs by
     * path_day), in one transaction.
     *
     * @param int                                              $engine Engine.
     * @param string                                           $url    The page's address.
     * @param array<string,array<int,array<string,mixed>>>     $data   Day => the source's rows (keys: page, query).
     * @param string                                           $from   Y-m-d.
     * @param string                                           $to     Y-m-d.
     * @param int                                              $import imports.id.
     * @return int|WP_Error Rows written.
     */
    private static function replace_pairs($engine, $url, array $data, $from, $to, $import) {
        global $wpdb;
        $path = self::path($url);
        if ($path === '') {
            return 0;
        }
        $ids     = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array($path));
        $path_id = isset($ids[SEOProStats_Dict::clean($path)]) ? (int) $ids[SEOProStats_Dict::clean($path)] : 0;
        if (!$path_id) {
            return 0;
        }
        $table = SEOProStats_Schema::table('gsc_pairs');
        $wpdb->query(self::BEGIN); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- BEGIN is fixed SQL; one page's rows replaced together.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, one page's days by its path_day key.
        $ok      = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE path_id = %d AND engine = %d AND day >= %s AND day <= %s', $table, $path_id, $engine, $from, $to)) !== false;
        $written = 0;
        foreach ($data as $day => $rows) {
            if (!$ok) {
                break;
            }
            $added = self::insert($table, 'pairs', $engine, (string) $day, $import, self::rows(array('pairs' => $rows))['pairs']);
            $ok    = $added !== false;
            $written += (int) $added;
        }
        if ($ok) {
            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
        } else {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
            /* translators: %s: page path */
            return new WP_Error('seoprostats_import_write', sprintf(__('The search queries of %s could not be saved in the database.', 'seoprostats'), $path));
        }
        return $written;
    }

    /**
     * Import days again (WP-CLI: after an undo, or to refresh), replacing
     * their rows. Only final days.
     *
     * @param string $source Source key.
     * @param string $from   Y-m-d.
     * @param string $to     Y-m-d.
     * @return array{days:int,rows:int,import:int,done:bool}|WP_Error
     */
    public static function reimport($source, $from, $to) {
        if (!self::lock()) {
            return self::busy();
        }
        try {
            return self::reimport_days($source, $from, $to);
        } finally {
            self::unlock();
        }
    }

    /**
     * reimport(), holding the lock.
     *
     * @param string $source Source key.
     * @param string $from   Y-m-d.
     * @param string $to     Y-m-d.
     * @return array{days:int,rows:int,import:int,done:bool}|WP_Error
     */
    private static function reimport_days($source, $from, $to) {
        $ready = self::ready($source);
        if (is_wp_error($ready)) {
            return $ready;
        }
        list($class, $token, $property) = $ready;
        $final = $class::final_through($token, $property);
        if (is_wp_error($final)) {
            return $final;
        }
        $to = min($to, $final);
        if ($from > $to) {
            return new WP_Error('seoprostats_import_range', __('No final days in that range: the search engine\'s figures for the last days are not final yet.', 'seoprostats'));
        }
        $import = self::start($source, $property, $from);
        if (!$import) {
            return new WP_Error('seoprostats_import_row', __('The import could not be recorded in the database.', 'seoprostats'));
        }
        $rows = 0;
        $span   = array();
        for ($day = $from; $day <= $to; $day = self::shift($day, 1)) {
            $added = self::import_day($class, $token, $property, $day, $import);
            if (is_wp_error($added)) {
                self::finish($import, self::FAILED, $span, $rows, $added->get_error_message());
                return $added;
            }
            $rows  += $added;
            $span[] = $day;
        }
        self::finish($import, self::DONE, $span, $rows);
        // Pages with their queries, for a source that gives them page by page: the next run.
        self::pairs_due($source, $class, $span);
        self::appearance_due($source, $class, $span);
        return self::run_extra($source, $class, $token, $property, microtime(true), 0, array('days' => count($span), 'rows' => $rows, 'import' => $import, 'done' => true));
    }

    /**
     * A source's saved import state; empty once it is disconnected, which can
     * happen while a run is under way.
     *
     * @param string $source Source key.
     * @return array<string,mixed>
     */
    private static function state($source) {
        $conn = SEOProStats_Connections::get($source);
        return $conn ? $conn['state'] : array();
    }

    /**
     * Whether a run has anything to ask the source for, from the saved
     * state alone (no request): history still to import, final days known
     * but not imported, or time to ask for new final days. So the hourly
     * job signs in only when there is work.
     *
     * @param string $source Source key.
     * @return bool
     */
    private static function due($source) {
        $class = SEOProStats_Connections::source_class($source);
        $conn  = SEOProStats_Connections::get($source);
        if (!$class || !$conn || !empty($conn['state']['error'])) {
            // Not connected (ready() says so), or the last run failed: try.
            return true;
        }
        $state   = $conn['state'];
        $through = isset($state['through']) ? (string) $state['through'] : '';
        $back    = isset($state['back']) ? (string) $state['back'] : '';
        $final   = isset($state['final']) ? (string) $state['final'] : '';
        if ($through === '' || $back === '' || $final > $through || !empty($state['pairs_from']) || !empty($state['appearance_from'])) {
            return true;
        }
        $today = $class::today();
        $first = max(isset($state['first']) ? (string) $state['first'] : '', self::shift($today, 0, -self::months($class)));
        if ($back > $first) {
            return true;
        }
        $stale = $through < self::shift($today, -2);
        return $stale && (empty($state['checked']) || (int) $state['checked'] < time() - self::CHECK_EVERY);
    }

    /**
     * A source's class, an access token and its property (also for
     * Search Console's sitemaps and URL Inspection, SEOProStats_Inspections).
     *
     * @param string $source Source key.
     * @return array{0:string,1:string,2:string}|WP_Error
     */
    public static function ready($source) {
        if (!SEOProStats_Schema::is_current()) {
            return new WP_Error('seoprostats_tables', __('The statistics tables are being updated. Try again after visiting wp-admin.', 'seoprostats'));
        }
        $class = SEOProStats_Connections::source_class($source);
        $conn  = SEOProStats_Connections::get($source);
        if (!$class || !$conn) {
            return new WP_Error('seoprostats_not_connected', __('This source is not connected.', 'seoprostats'));
        }
        $property = isset($conn['settings']['property']) ? (string) $conn['settings']['property'] : '';
        if ($property === '') {
            return new WP_Error('seoprostats_property_none', __('No property or site is chosen. Connect it again.', 'seoprostats'));
        }
        $key = SEOProStats_Connections::credentials($source);
        if (is_wp_error($key)) {
            return $key;
        }
        $token = $class::token($key);
        if (is_wp_error($token)) {
            return $token;
        }
        foreach (array('dict', 'channels', 'processor') as $part) {
            require_once SEOPROSTATS_DIR . "includes/stats/class-seoprostats-$part.php";
        }
        return array($class, $token, $property);
    }

    /**
     * Record a failure in the connection's state.
     *
     * @param string   $source Source key.
     * @param WP_Error $error  Error.
     * @return WP_Error
     */
    private static function failed($source, WP_Error $error) {
        SEOProStats_Connections::update_state($source, array('error' => $error->get_error_message(), 'error_at' => time()));
        return $error;
    }

    /**
     * The first day of the property's history with any search, within the
     * months Search Console keeps (one request), so the backfill does not
     * ask for days before the site was in Search Console.
     *
     * @param string $class    Source class.
     * @param string $token    Access token.
     * @param string $property Property.
     * @param string $final    Last final day.
     * @return string Y-m-d.
     */
    private static function first_day($class, $token, $property, $final) {
        $from = self::shift($class::today(), 0, -self::months($class));
        $days = $class::days($token, $property, $from, $final);
        return is_wp_error($days) || !$days ? $from : min($days);
    }

    /**
     * Replace one day's rows with the source's.
     *
     * @param string $class    Source class.
     * @param string $token    Access token.
     * @param string $property Property.
     * @param string $day      Y-m-d.
     * @param int    $import   imports.id.
     * @return int|WP_Error Rows written.
     */
    private static function import_day($class, $token, $property, $day, $import) {
        global $wpdb;
        /**
         * Filters the most rows imported per day for pages, queries, and
         * pages with their queries (the top by clicks).
         *
         * @param array{pages:int,queries:int,pairs:int} $limits Rows per kind.
         * @param string                                $source Source key.
         */
        $limits = (array) apply_filters('seoprostats_search_import_limits', self::LIMITS, $class::KEY);
        $data   = $class::day($token, $property, $day, $limits);
        if (is_wp_error($data)) {
            return $data;
        }
        $rows = self::rows($data);

        $engine = (int) $class::ENGINE;
        $wpdb->query(self::BEGIN); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- BEGIN is fixed SQL; one day's rows replaced together.
        $ok      = true;
        $written = 0;
        // Only the kinds the source gives by day (Bing's pages with their queries come page by page).
        foreach (array_intersect_key(self::TABLES, $data) as $kind => $name) {
            $table = SEOProStats_Schema::table($name);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, one day by its primary key.
            if ($wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d AND day = %s', $table, $engine, $day)) === false) {
                $ok = false;
                break;
            }
            $added = self::insert($table, $kind, $engine, $day, $import, $rows[$kind]);
            if ($added === false) {
                $ok = false;
                break;
            }
            $written += $added;
        }
        if ($ok) {
            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
        } else {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
        }
        if (!$ok) {
            /* translators: %s: day */
            return new WP_Error('seoprostats_import_write', sprintf(__('The search data for %s could not be saved in the database.', 'seoprostats'), $day));
        }
        return $written;
    }

    /**
     * The source's rows as table rows: pages of other sites left out,
     * texts as dictionary ids, rows for the same page added together
     * (http and https, www and not), position × impressions × 100.
     *
     * @param array<string,array<int,array<string,mixed>>> $data Kind => the source's rows.
     * @return array<string,array<string,array<int,int|string>>> Kind => key => [keys…, clicks, impressions, pos_impr].
     */
    public static function rows(array $data) {
        $paths   = self::mark_paths($data);
        $queries = self::mark_queries($data);
        $ids     = array(
            'path'  => $paths ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, $paths) : array(),
            'query' => $queries ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_QUERY, $queries) : array(),
        );
        $appearances       = isset($data['appearance']) ? array_map('strval', array_column(array_column($data['appearance'], 'keys'), 0)) : array();
        $ids['appearance'] = $appearances ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_APPEARANCE, $appearances) : array();

        $out = array_fill_keys(array_keys(self::TABLES), array());
        foreach (array_keys(self::TABLES) as $kind) {
            foreach (isset($data[$kind]) ? $data[$kind] : array() as $row) {
                $keys = self::row_keys($kind, $row, $ids);
                if (in_array(0, array_slice($keys, 0, $kind === 'totals' ? 0 : 2), true)) {
                    continue;
                }
                $id  = implode("\t", $keys);
                $add = self::row_sums($row);
                if (!isset($out[$kind][$id])) {
                    $out[$kind][$id] = array_merge($keys, array(0, 0, 0));
                }
                $n                        = count($keys);
                $out[$kind][$id][$n]     += $add[0];
                $out[$kind][$id][$n + 1] += $add[1];
                $out[$kind][$id][$n + 2] += $add[2];
            }
        }
        return $out;
    }

    /**
     * Give page and pair rows their path, and leave out those of other
     * sites.
     *
     * @param array<string,array<int,array<string,mixed>>> $data Kind => the source's rows; changed.
     * @return string[] The paths, in row order.
     */
    private static function mark_paths(array &$data) {
        $paths = array();
        foreach (array('pages', 'pairs') as $kind) {
            foreach (isset($data[$kind]) ? $data[$kind] : array() as $i => $row) {
                $path = self::path(isset($row['keys'][0]) ? (string) $row['keys'][0] : '');
                if ($path === '') {
                    unset($data[$kind][$i]);
                    continue;
                }
                $data[$kind][$i]['path'] = $path;
                $paths[]                 = $path;
            }
        }
        return $paths;
    }

    /**
     * Give query and pair rows their query, and leave out those without
     * one.
     *
     * @param array<string,array<int,array<string,mixed>>> $data Kind => the source's rows; changed.
     * @return string[] The queries, in row order.
     */
    private static function mark_queries(array &$data) {
        $queries = array();
        foreach (array('queries' => 0, 'pairs' => 1) as $kind => $at) {
            foreach (isset($data[$kind]) ? $data[$kind] : array() as $i => $row) {
                $query = self::query(isset($row['keys'][$at]) ? (string) $row['keys'][$at] : '');
                if ($query === '') {
                    unset($data[$kind][$i]);
                    continue;
                }
                $data[$kind][$i]['query'] = $query;
                $queries[]                = $query;
            }
        }
        return $queries;
    }

    /**
     * A row's key columns for its kind (0 for a text without an id).
     *
     * @param string                              $kind A key of TABLES.
     * @param array<string,mixed>                 $row  The source's row, with path and query from mark_paths() and mark_queries().
     * @param array<string,array<string,int>>     $ids  path, query and appearance: text => dictionary id.
     * @return array<int,int|string>
     */
    private static function row_keys($kind, array $row, array $ids) {
        if ($kind === 'pages') {
            return array(self::dict_id($ids['path'], $row['path']));
        }
        if ($kind === 'queries') {
            return array(self::dict_id($ids['query'], $row['query']));
        }
        if ($kind === 'appearance') {
            return array(self::dict_id($ids['appearance'], isset($row['keys'][0]) ? (string) $row['keys'][0] : ''));
        }
        if ($kind === 'pairs') {
            return array(self::dict_id($ids['path'], $row['path']), self::dict_id($ids['query'], $row['query']));
        }
        $device  = strtoupper(isset($row['keys'][0]) ? (string) $row['keys'][0] : '');
        $country = strtolower(isset($row['keys'][1]) ? (string) $row['keys'][1] : '');
        return array(
            isset(SEOProStats_Schema::GSC_DEVICES[$device]) ? SEOProStats_Schema::GSC_DEVICES[$device] : 0,
            preg_match('/^[a-z]{3}$/', $country) ? $country : '',
        );
    }

    /**
     * A text's dictionary id from SEOProStats_Dict::ids(), or 0.
     *
     * @param array<string,int> $ids  Text => id.
     * @param string            $text Text.
     * @return int
     */
    private static function dict_id(array $ids, $text) {
        $text = SEOProStats_Dict::clean($text);
        return isset($ids[$text]) ? $ids[$text] : 0;
    }

    /**
     * A source row's clicks, impressions and position × impressions × 100.
     *
     * @param array<string,mixed> $row The source's row.
     * @return array{0:int,1:int,2:int}
     */
    private static function row_sums(array $row) {
        $clicks      = isset($row['clicks']) ? max(0, (int) round((float) $row['clicks'])) : 0;
        $impressions = isset($row['impressions']) ? max(0, (int) round((float) $row['impressions'])) : 0;
        $pos_impr    = isset($row['position']) ? max(0, (int) round((float) $row['position'] * $impressions * 100)) : 0;
        return array($clicks, $impressions, $pos_impr);
    }

    /**
     * Insert one day's rows of a kind, in chunks (also the demo data's).
     *
     * @param string                          $table  Table.
     * @param string                          $kind   pages, queries, pairs or totals.
     * @param int                             $engine Engine.
     * @param string                          $day    Y-m-d.
     * @param int                             $import imports.id.
     * @param array<string,array<int,int|string>> $rows From rows().
     * @return int|false Rows inserted, or false when a query failed.
     */
    public static function insert($table, $kind, $engine, $day, $import, array $rows) {
        global $wpdb;
        $columns = array(
            'pages'   => array('path_id' => '%d'),
            'queries' => array('query_id' => '%d'),
            'pairs'   => array('path_id' => '%d', 'query_id' => '%d'),
            'totals'  => array('device' => '%d', 'country' => '%s'),
            'appearance' => array('appearance_id' => '%d'),
        );
        $keys    = $columns[$kind];
        $names   = implode(', ', array_keys($keys));
        $group   = '(%d, %s, ' . implode(', ', array_values($keys)) . ', %d, %d, %d, %d)';
        $added   = 0;
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            $args = array($table);
            foreach ($chunk as $row) {
                $args = array_merge($args, array($engine, $day), $row, array($import));
            }
            $groups = implode(', ', array_fill(0, count($chunk), $group));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $names and $groups are fixed column names and placeholder groups, one per row.
            $result = $wpdb->query($wpdb->prepare("INSERT INTO %i (engine, day, $names, clicks, impressions, pos_impr, import_id) VALUES $groups", $args));
            if ($result === false) {
                return false;
            }
            $added += (int) $result;
        }
        return $added;
    }

    /**
     * A page address as the path visits store, or '' when it is not this
     * site's (a domain property also has the domain's other sites).
     *
     * @param string $url Address.
     * @return string
     */
    public static function path($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $host = strpos($host, 'www.') === 0 ? (string) substr($host, 4) : $host;
        if ($host === '' || !isset(self::hosts()[$host])) {
            return '';
        }
        $url = (string) preg_replace('/#.*$/', '', $url);
        return SEOProStats_Processor::split_url($url)['path'];
    }

    /**
     * A search query as stored: trimmed, single spaces, at most the
     * dictionary's length.
     *
     * @param string $query Query.
     * @return string
     */
    public static function query($query) {
        return SEOProStats_Dict::clean(trim((string) preg_replace('/\s+/u', ' ', (string) $query)));
    }

    /**
     * The site's hosts, without www.
     *
     * @return array<string,bool>
     */
    private static function hosts() {
        if (self::$hosts === null) {
            self::$hosts = array();
            foreach (SEOProStats_Collection::hosts() as $host) {
                $host                = strtolower((string) $host);
                $host                = strpos($host, 'www.') === 0 ? (string) substr($host, 4) : $host;
                self::$hosts[$host]  = true;
            }
        }
        return self::$hosts;
    }

    /**
     * Start an imports row.
     *
     * @param string $source   Source key.
     * @param string $property Property.
     * @param string $day      First day.
     * @return int Its id, or 0.
     */
    private static function start($source, $property, $day) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
        $ok = $wpdb->insert(SEOProStats_Schema::table('imports'), array(
            'source'   => $source,
            'status'   => self::RUNNING,
            'started'  => time(),
            'day_from' => $day,
            'day_to'   => $day,
            'meta'     => (string) wp_json_encode(array('property' => $property)),
        ), array('%s', '%d', '%d', '%s', '%s', '%s'));
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Whether the source was disconnected while this run was under way, so
     * the run stops. A disconnect that deleted the data took this run's
     * imports row with it; rows the run wrote after that (its Google
     * request was in flight) are deleted again here.
     *
     * @param string $source Source key.
     * @param int    $import This run's imports.id, 0 before its first write.
     * @return bool
     */
    private static function disconnected($source, $import) {
        if (SEOProStats_Connections::still_connected($source)) {
            return false;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        if ($import && !$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', SEOProStats_Schema::table('imports'), $import))) {
            self::delete_data($source);
        }
        return true;
    }

    /**
     * Finish an imports row.
     *
     * @param int      $id     imports.id.
     * @param int      $status DONE or FAILED.
     * @param string[] $days   Days imported.
     * @param int      $rows   Rows written.
     * @param string   $error  Error message, if any.
     */
    private static function finish($id, $status, array $days, $rows, $error = '') {
        global $wpdb;
        $table = SEOProStats_Schema::table('imports');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $meta = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT meta FROM %i WHERE id = %d', $table, $id)), true);
        $meta = is_array($meta) ? $meta : array();
        $meta['days'] = count($days);
        if ($error !== '') {
            $meta['error'] = $error;
        }
        $values = array(
            'status'     => $status,
            'finished'   => time(),
            'rows_added' => $rows,
            'meta'       => (string) wp_json_encode($meta),
        );
        if ($days) {
            $values['day_from'] = min($days);
            $values['day_to']   = max($days);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update($table, $values, array('id' => $id));
    }

    /**
     * Imports, newest first.
     *
     * @param string $source Source key, or '' for every source.
     * @param int    $limit  Most rows.
     * @return array<int,array<string,mixed>>
     */
    public static function imports($source = '', $limit = 20) {
        global $wpdb;
        $table = SEOProStats_Schema::table('imports');
        if ($source !== '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its source_status key; a short list.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE source = %s ORDER BY id DESC LIMIT %d', $table, $source, (int) $limit), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by primary key; a short list.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, (int) $limit), ARRAY_A);
        }
        $statuses = array(self::RUNNING => 'running', self::DONE => 'done', self::FAILED => 'failed', self::UNDONE => 'undone');
        $out      = array();
        foreach ((array) $rows as $row) {
            $meta  = json_decode((string) $row['meta'], true);
            $out[] = array(
                'id'       => (int) $row['id'],
                'source'   => (string) $row['source'],
                'status'   => isset($statuses[(int) $row['status']]) ? $statuses[(int) $row['status']] : 'unknown',
                'started'  => (int) $row['started'],
                'finished' => (int) $row['finished'],
                'from'     => (string) $row['day_from'],
                'to'       => (string) $row['day_to'],
                'days'     => is_array($meta) && isset($meta['days']) ? (int) $meta['days'] : 0,
                'rows'     => (int) $row['rows_added'],
                'error'    => is_array($meta) && isset($meta['error']) ? (string) $meta['error'] : '',
            );
        }
        return $out;
    }

    /**
     * Undo an import: delete the rows it wrote (by its days, through the
     * primary key, and its id). The days are not imported again by the
     * job; reimport() brings them back.
     *
     * @param int $id imports.id.
     * @return int|WP_Error Rows deleted.
     */
    public static function undo($id) {
        global $wpdb;
        $table = SEOProStats_Schema::table('imports');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, (int) $id), ARRAY_A);
        if (!$row) {
            return new WP_Error('seoprostats_import_unknown', __('There is no import with that ID.', 'seoprostats'));
        }
        if ((int) $row['status'] === self::UNDONE) {
            return 0;
        }
        $class = SEOProStats_Connections::source_class((string) $row['source']);
        if (!$class) {
            return new WP_Error('seoprostats_import_unknown', __('That import is from a source this version does not know.', 'seoprostats'));
        }
        $deleted = 0;
        foreach (self::TABLES as $name) {
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table: the import's days by primary key, in batches.
                $rows     = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d AND day >= %s AND day <= %s AND import_id = %d LIMIT %d', SEOProStats_Schema::table($name), (int) $class::ENGINE, $row['day_from'], $row['day_to'], (int) $id, self::BATCH));
                $deleted += (int) $rows;
            } while ($rows === self::BATCH);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update($table, array('status' => self::UNDONE, 'finished' => time()), array('id' => (int) $id));
        return $deleted;
    }

    /**
     * Delete a source's search data and import history (disconnecting,
     * when asked).
     *
     * @param string $source Source key.
     * @return int Rows deleted.
     */
    public static function delete_data($source) {
        global $wpdb;
        $class = SEOProStats_Connections::source_class($source);
        if (!$class || !SEOProStats_Schema::is_current()) {
            return 0;
        }
        $deleted = 0;
        foreach (self::TABLES as $name) {
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by the primary key's engine prefix, in batches.
                $rows     = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d LIMIT %d', SEOProStats_Schema::table($name), (int) $class::ENGINE, self::BATCH));
                $deleted += (int) $rows;
            } while ($rows === self::BATCH);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its source_status key.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE source = %s', SEOProStats_Schema::table('imports'), $source));
        return $deleted;
    }

    /**
     * Forget the job and its options (uninstall; the tables go with the
     * others).
     */
    public static function forget() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::HOOK, array('more'));
        delete_option(self::LOCK_OPTION);
        delete_option(self::PRUNED_OPTION);
    }

    /**
     * Once a day: delete page, query and pair rows older than their
     * retention (Settings → Data), per engine through the primary key, in
     * batches. Totals are kept.
     *
     * @param float $start microtime(true) when the run began.
     * @return int Rows deleted.
     */
    public static function prune($start) {
        global $wpdb;
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        $months = SEOProStats_Rollup::retention()['search'];
        if ($months <= 0 || !SEOProStats_Schema::is_current() || get_option(self::PRUNED_OPTION) === wp_date('Y-m-d')) {
            return 0;
        }
        $before  = (new DateTimeImmutable('today', wp_timezone()))->modify("-$months months")->format('Y-m-d');
        $deleted = 0;
        foreach (array('pages', 'queries', 'pairs', 'appearance') as $kind) {
            foreach (array(SEOProStats_Schema::ENGINE_GOOGLE, SEOProStats_Schema::ENGINE_BING) as $engine) {
                do {
                    if (!SEOProStats_Feature::more_time($start, self::BUDGET)) {
                        return $deleted;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by the primary key's (engine, day) prefix, in batches.
                    $rows     = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d AND day < %s LIMIT %d', SEOProStats_Schema::table(self::TABLES[$kind]), $engine, $before, self::BATCH));
                    $deleted += (int) $rows;
                } while ($rows === self::BATCH);
            }
        }
        update_option(self::PRUNED_OPTION, wp_date('Y-m-d'), false);
        return $deleted;
    }

    /**
     * Rows a prune would delete now, by table (WP-CLI).
     *
     * @return array<string,int>
     */
    public static function prune_counts() {
        global $wpdb;
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        $months = SEOProStats_Rollup::retention()['search'];
        if ($months <= 0) {
            return array();
        }
        $before = (new DateTimeImmutable('today', wp_timezone()))->modify("-$months months")->format('Y-m-d');
        $out    = array();
        foreach (array('pages', 'queries', 'pairs', 'appearance') as $kind) {
            $name       = self::TABLES[$kind];
            $out[$name] = 0;
            foreach (array(SEOProStats_Schema::ENGINE_GOOGLE, SEOProStats_Schema::ENGINE_BING) as $engine) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by the primary key's (engine, day) prefix; WP-CLI only.
                $out[$name] += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE engine = %d AND day < %s', SEOProStats_Schema::table($name), $engine, $before));
            }
        }
        return $out;
    }

    /**
     * Imported days and rows of an engine (status, doctor).
     *
     * @param int $engine Engine.
     * @return array{from:string,to:string,pages:int}
     */
    public static function coverage($engine) {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array('from' => '', 'to' => '', 'pages' => 0);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, MIN and MAX of the primary key's (engine, day) prefix.
        $row = $wpdb->get_row($wpdb->prepare('SELECT MIN(day) AS f, MAX(day) AS t FROM %i WHERE engine = %d', SEOProStats_Schema::table('gsc_totals'), (int) $engine));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, the primary key's prefix; counts one day's rows.
        $pages = $row && $row->t ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE engine = %d AND day = %s', SEOProStats_Schema::table('gsc_pages'), (int) $engine, $row->t)) : 0;
        return array(
            'from'  => $row && $row->f ? (string) $row->f : '',
            'to'    => $row && $row->t ? (string) $row->t : '',
            'pages' => $pages,
        );
    }

    /**
     * Months of history a source keeps (its MONTHS).
     *
     * @param string $class Source class.
     * @return int
     */
    public static function months($class) {
        return max(1, intval(constant($class . '::MONTHS')));
    }

    /**
     * A day moved by days and months.
     *
     * @param string $day    Y-m-d.
     * @param int    $days   Days.
     * @param int    $months Months.
     * @return string Y-m-d.
     */
    private static function shift($day, $days, $months = 0) {
        $date = new DateTimeImmutable($day, new DateTimeZone('UTC'));
        if ($months) {
            $date = $date->modify(sprintf('%+d months', $months));
        }
        return $date->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
