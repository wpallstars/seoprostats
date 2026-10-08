<?php
/**
 * Moving from another statistics plugin: find its data on the site, import
 * its history into the daily table as whole days, check it against the
 * plugin's own counts, carry over its settings, and remove what it leaves
 * behind once it is switched off.
 *
 * Each day is filled once: only days before SEO Pro Stats's own first day,
 * and not already filled by an import (of the same plugin or another one).
 * Imported rows carry their imports row's id in daily.import_id, so undo
 * removes exactly them and the daily summaries never touch them. One
 * adapter per plugin (SEOProStats_Migrate_Source); add more with the
 * seoprostats_migrate_sources filter. Design: docs/architecture.md →
 * Moving from other statistics plugins.
 *
 * The admin and REST start a job that cron (and the Import tab's polling)
 * moves on in batches of days within BUDGET, under a lock; WP-CLI runs to
 * the end. Nothing here runs on visitor pages.
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

require_once __DIR__ . '/migrate/class-seoprostats-migrate-source.php';
require_once __DIR__ . '/class-seoprostats-dict.php';
require_once __DIR__ . '/class-seoprostats-query.php';
require_once __DIR__ . '/class-seoprostats-rollup.php';

final class SEOProStats_Migrate {

    /** Adapters: imports.source => class (in includes/stats/migrate/). */
    const SOURCES = array(
        'burst-statistics' => 'SEOProStats_Migrate_Burst',
    );

    /** Cron hook of a running import. */
    const HOOK = SEOProStats_Collection::MIGRATE_HOOK;

    /** The running or last import job (autoload off). */
    const STATE_OPTION = 'seoprostats_migrate';

    /** The run lock (autoload off). */
    const LOCK_OPTION = 'seoprostats_migrate_lock';

    /** Transient: the plugins found, for CACHE_TIME. */
    const CACHE = SEOProStats_Collection::MIGRATE_FOUND;
    const CACHE_TIME = 10 * MINUTE_IN_SECONDS;

    /** Seconds per cron run; a read of the status moves a job on for STEP. */
    const BUDGET = 20;
    const STEP   = 10;

    /** A lock older than this was left by a run that died. */
    const LOCK_TIME = 10 * MINUTE_IN_SECONDS;

    /** Rows per INSERT and per DELETE. */
    const BATCH = 500;
    const DELETE_BATCH = 5000;

    /** Days read for the dry run's row estimate. */
    const SAMPLE = 3;

    /** imports.status, as SEOProStats_Search_Import's. */
    const RUNNING = 1;
    const DONE    = 2;
    const FAILED  = 3;
    const UNDONE  = 4;

    /** Metric columns of the daily table. */
    const METRICS = array('visitors', 'visits', 'pageviews', 'bounces', 'engaged_ms', 'events', 'scroll');

    /**
     * Adapters by key: the built-in ones and those added with the filter.
     *
     * @return array<string,class-string<SEOProStats_Migrate_Source>> Key => class.
     */
    public static function sources() {
        /**
         * Filter the statistics plugins SEO Pro Stats can import from.
         *
         * @param array<string,string> $sources imports.source key => class
         *                                      extending SEOProStats_Migrate_Source
         *                                      (loaded by then), whose KEY is the key.
         */
        $sources = (array) apply_filters('seoprostats_migrate_sources', self::SOURCES);
        $out     = array();
        foreach ($sources as $key => $class) {
            $class = (string) $class;
            if (isset(self::SOURCES[$key]) && self::SOURCES[$key] === $class && !class_exists($class, false)) {
                require_once __DIR__ . '/migrate/class-' . strtolower(str_replace('_', '-', $class)) . '.php';
            }
            if (class_exists($class) && is_subclass_of($class, 'SEOProStats_Migrate_Source') && $class::KEY === (string) $key && strlen((string) $key) <= 20) {
                $out[(string) $key] = $class;
            }
        }
        return $out;
    }

    /**
     * An adapter.
     *
     * @param string $key Its key.
     * @return SEOProStats_Migrate_Source|null
     */
    public static function source($key) {
        $sources = self::sources();
        return isset($sources[$key]) ? new $sources[$key]() : null;
    }

    /**
     * Whether an imports row is a migration (not a search data import).
     *
     * @param string $source imports.source.
     * @return bool
     */
    public static function owns($source) {
        return isset(self::SOURCES[$source]) || isset(self::sources()[$source]);
    }

    /**
     * The plugins whose statistics are on the site (or, while they are not
     * active, whose leftovers are), cached for CACHE_TIME. Looking also
     * saves what the moving notices show (save_notices()).
     *
     * @param bool $fresh Look again.
     * @return array<string,array<string,mixed>> Key => key, name, version, from, to, days, pending (days still to import, -1 unknown), plugin (file, state), leftovers (whether any), uninstall_setting.
     */
    public static function found($fresh = false) {
        $cached = $fresh ? false : get_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached;
        }
        $before = SEOProStats_Schema::use_set('live');
        try {
            $out = array();
            foreach (self::sources() as $key => $class) {
                $source = new $class();
                $data   = $source->detect();
                $plugin = $source->plugin();
                $left   = $plugin['state'] === 'active' || $plugin['state'] === 'network' ? null : $source->leftovers();
                $any    = $left !== null && (bool) array_filter(array_diff_key($left, array('network' => true)));
                if ($data['from'] === '' && !$any) {
                    continue;
                }
                // The days it has and those still to import, in one pass over its days.
                $plan      = $data['from'] !== '' ? self::make_plan($key, array(), false) : null;
                $out[$key] = array(
                    'key'               => $key,
                    'name'              => $class::NAME,
                    'version'           => $data['version'],
                    'from'              => $data['from'],
                    'to'                => $data['to'],
                    'days'              => is_array($plan) ? (int) $plan['days'] : ($data['from'] !== '' ? count($source->day_list($data['from'], $data['to'])) : 0),
                    'pending'           => $plan === null ? 0 : (is_array($plan) ? count($plan['import']) : -1),
                    'pending_from'      => is_array($plan) && $plan['import'] ? (string) min($plan['import']) : '',
                    'pending_to'        => is_array($plan) && $plan['import'] ? (string) max($plan['import']) : '',
                    'plugin'            => $plugin,
                    'leftovers'         => $any,
                    'uninstall_setting' => $source->uninstall_setting(),
                );
            }
            set_transient(self::CACHE, $out, self::CACHE_TIME);
            self::save_notices($out);
            return $out;
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Save what the moving notices show (SEOProStats_Migrate_Notices): per
     * plugin found, only facts, no words (people see them in their own
     * language). Leftovers: whether it leaves data once switched off
     * (statistics, or leftovers found while it was off).
     *
     * @param array<string,array<string,mixed>> $found From found().
     */
    private static function save_notices(array $found) {
        $imported = array();
        if (SEOProStats_Schema::is_current()) {
            foreach (self::imports() as $import) {
                if ($import['status'] === 'done' && $import['rows'] > 0) {
                    $imported[$import['source']] = true;
                }
            }
        }
        $sources = array();
        foreach ($found as $key => $item) {
            $sources[$key] = array(
                'name'         => (string) $item['name'],
                'file'         => (string) $item['plugin']['file'],
                'from'         => (string) $item['from'],
                'to'           => (string) $item['to'],
                'pending'      => (int) $item['pending'],
                'pending_from' => (string) $item['pending_from'],
                'pending_to'   => (string) $item['pending_to'],
                'imported'     => isset($imported[$key]),
                'leftovers'    => !empty($item['leftovers']) || (string) $item['from'] !== '',
            );
        }
        update_option(SEOProStats_Collection::MIGRATE_NOTICES, array('at' => time(), 'sources' => $sources), false);
    }

    /**
     * Look for the plugins again after an import, undo or cleanup changed
     * their data, so the moving notices show the next step at once. These
     * are actions people or the job take, never a screen load.
     */
    public static function forget_found() {
        self::found(true);
    }

    /**
     * Everything the Import tab shows: the plugins found, the job, SEO Pro
     * Stats's own first day and the imports so far.
     *
     * @param bool $fresh Look for plugins again.
     * @return array<string,mixed>
     */
    public static function status($fresh = false) {
        $before = SEOProStats_Schema::use_set('live');
        try {
            $current = SEOProStats_Schema::is_current();
            return array(
                'sources'  => self::found($fresh),
                'job'      => self::job(),
                'own_from' => $current ? self::own_from() : '',
                'imports'  => $current ? self::imports() : array(),
                'can'      => array(
                    'import'  => SEOProStats_Settings::can_change(),
                    'cleanup' => self::can_cleanup(),
                ),
            );
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * SEO Pro Stats's own first day (site time zone): its first summarised
     * day, or the day of the first visit it stored, or today. Imports fill
     * only days before it.
     *
     * @return string Y-m-d.
     */
    public static function own_from() {
        global $wpdb;
        $days     = array((string) wp_date('Y-m-d'));
        $imported = SEOProStats_Rollup::imported()['through'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table: the primary key from the day after the imported ones, one entry.
        $own = (string) $wpdb->get_var($wpdb->prepare('SELECT day FROM %i WHERE day > %s AND dim = 0 AND import_id = 0 ORDER BY day LIMIT 1', SEOProStats_Schema::table('daily'), $imported !== '' ? $imported : '1000-01-01'));
        if ($own !== '') {
            $days[] = $own;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; MIN() on index `started` reads one entry.
        $first = (int) $wpdb->get_var($wpdb->prepare('SELECT MIN(started) FROM %i', SEOProStats_Schema::table('sessions')));
        if ($first > 0) {
            $days[] = (string) wp_date('Y-m-d', $first);
        }
        sort($days);
        return $days[0];
    }

    /**
     * Which of some days already have a site row in the daily table, and
     * whose: 0 for SEO Pro Stats's own, else the imports row's id. One
     * primary key lookup per day.
     *
     * @param string[] $days Y-m-d.
     * @return array<string,int> Day => import_id.
     */
    public static function filled(array $days) {
        global $wpdb;
        $out = array();
        foreach (array_chunk(array_values($days), self::BATCH) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key (day, dim 0, val 0); $holders holds only placeholders, one per day.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT day, import_id FROM %i WHERE day IN ($holders) AND dim = 0 AND val = 0", array_merge(array(SEOProStats_Schema::table('daily')), $chunk)), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[(string) $row['day']] = (int) $row['import_id'];
            }
        }
        return $out;
    }

    /**
     * The dry run: what an import would do. Writes nothing.
     *
     * @param string               $key  Adapter key.
     * @param array<string,string> $args from, to (Y-m-d, optional), prefer (adapter key, optional).
     * @return array<string,mixed>|WP_Error
     */
    public static function plan($key, array $args = array()) {
        $before = SEOProStats_Schema::use_set('live');
        try {
            return self::make_plan($key, $args, true);
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * plan(), on the live data set.
     *
     * @param string               $key  Adapter key.
     * @param array<string,string> $args from, to, prefer.
     * @param bool                 $full With the overlap, rows and settings (the dry run), not only the days.
     * @return array<string,mixed>|WP_Error
     */
    private static function make_plan($key, array $args, $full) {
        $source = self::source($key);
        if (!$source) {
            return new WP_Error('seoprostats_migrate_unknown', __('SEO Pro Stats cannot import from that plugin.', 'seoprostats'), array('status' => 404));
        }
        if (!SEOProStats_Schema::is_current()) {
            return new WP_Error('seoprostats_tables', __('The statistics tables are being updated. Try again after visiting wp-admin.', 'seoprostats'), array('status' => 503));
        }
        $data = $source->detect();
        if ($data['from'] === '') {
            /* translators: %s: plugin name. */
            return new WP_Error('seoprostats_migrate_empty', sprintf(__('There are no %s statistics on this site.', 'seoprostats'), $source::NAME), array('status' => 404));
        }
        foreach (array('from', 'to') as $end) {
            if (!empty($args[$end]) && !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $args[$end])) {
                return new WP_Error('seoprostats_migrate_day', __('Days are written as YYYY-MM-DD.', 'seoprostats'), array('status' => 400));
            }
        }
        $from = !empty($args['from']) ? max((string) $args['from'], $data['from']) : $data['from'];
        $to   = !empty($args['to']) ? min((string) $args['to'], $data['to']) : $data['to'];
        $own  = self::own_from();
        $days = $source->day_list($from, $to);

        $filled  = self::filled($days);
        $owners  = self::owners(array_values(array_filter(array_unique($filled))));
        $import  = array();
        $skipped = array('own' => 0, 'imported' => array());
        foreach ($days as $day) {
            if ($day >= $own || (isset($filled[$day]) && $filled[$day] === 0)) {
                $skipped['own']++;
            } elseif (isset($filled[$day])) {
                $by                       = isset($owners[$filled[$day]]) ? $owners[$filled[$day]] : 'unknown';
                $skipped['imported'][$by] = (isset($skipped['imported'][$by]) ? $skipped['imported'][$by] : 0) + 1;
            } else {
                $import[] = $day;
            }
        }
        $plan = array(
            'source'   => $key,
            'name'     => $source::NAME,
            'version'  => $data['version'],
            'from'     => $from,
            'to'       => $to,
            'own_from' => $own,
            'days'     => count($days),
            'import'   => $import,
            'skipped'  => $skipped,
        );
        if (!$full) {
            return $plan;
        }

        // Plugins not imported yet with statistics on the same days.
        $plan['overlap'] = array();
        $prefer          = isset($args['prefer']) ? (string) $args['prefer'] : '';
        foreach (self::sources() as $other_key => $class) {
            if ($other_key === $key || !$import) {
                continue;
            }
            $other = new $class();
            $span  = $other->detect();
            if ($span['from'] === '') {
                continue;
            }
            $shared = array_values(array_intersect($import, $other->day_list(max(min($import), $span['from']), min(max($import), $span['to']))));
            if (!$shared) {
                continue;
            }
            $ours              = $source->totals(min($shared), max($shared));
            $theirs            = $other->totals(min($shared), max($shared));
            $plan['overlap'][] = array(
                'source'    => $other_key,
                'name'      => $class::NAME,
                'from'      => min($shared),
                'to'        => max($shared),
                'days'      => count($shared),
                'pageviews' => array($key => $ours['pageviews'], $other_key => $theirs['pageviews']),
                'suggested' => $theirs['pageviews'] > $ours['pageviews'] ? $other_key : $key,
            );
        }
        $choices        = array_merge(array($key), wp_list_pluck($plan['overlap'], 'source'));
        $plan['prefer'] = in_array($prefer, $choices, true) ? $prefer : $key;
        $plan['rows']   = self::estimate($source, $import);
        $plan['settings'] = self::settings_plan($source);
        $plan['totals']   = $import ? $source->totals(min($import), max($import)) : array('pageviews' => 0, 'visits' => 0, 'visitors' => 0);
        $plan['plugin']   = $source->plugin();
        $plan['import']   = array(
            'days' => count($import),
            'from' => $import ? min($import) : '',
            'to'   => $import ? max($import) : '',
        );
        return $plan;
    }

    /**
     * Rows an import would write per dimension, estimated from a few of its
     * days (the first, middle and last).
     *
     * @param SEOProStats_Migrate_Source $source Adapter.
     * @param string[]                   $days   Days to import.
     * @return array<string,int> Dimension ('site' for the totals) => rows.
     */
    private static function estimate(SEOProStats_Migrate_Source $source, array $days) {
        $count = count($days);
        if (!$count) {
            return array();
        }
        $sample = array_values(array_unique(array($days[0], $days[(int) floor($count / 2)], $days[$count - 1])));
        $sums   = array();
        foreach (array_slice($sample, 0, self::SAMPLE) as $day) {
            $read = $source->days($day, $day);
            foreach (isset($read[$day]) ? self::encode($read[$day], false) : array() as $row) {
                $name        = self::dimension_name($row[0]);
                $sums[$name] = (isset($sums[$name]) ? $sums[$name] : 0) + 1;
            }
        }
        $out = array();
        foreach ($sums as $name => $rows) {
            $out[$name] = (int) round($rows / count($sample) * $count);
        }
        return $out;
    }

    /**
     * The adapter's settings with ours: what is set now and whether the
     * import would change it (only settings still at their default).
     *
     * @param SEOProStats_Migrate_Source $source Adapter.
     * @return array<int,array<string,mixed>>
     */
    private static function settings_plan(SEOProStats_Migrate_Source $source) {
        $schema   = SEOProStats_Settings::schema();
        $defaults = SEOProStats_Settings::defaults();
        $out      = array();
        foreach ($source->settings() as $setting) {
            $key = (string) $setting['key'];
            if (!isset($schema[$key])) {
                continue;
            }
            $now     = SEOProStats_Settings::get($key);
            $value   = SEOProStats_Settings::sanitize_value($setting['value'], $schema[$key]);
            $default = self::same($now, $defaults[$key]);
            $out[]   = array(
                'key'    => $key,
                'label'  => isset($schema[$key]['label']) ? (string) $schema[$key]['label'] : $key,
                'theirs' => (string) $setting['label'],
                'from'   => (string) $setting['from'],
                'now'    => self::words($now, $schema[$key]),
                'to'     => self::words($value, $schema[$key]),
                'value'  => $value,
                'also'   => isset($setting['also']) ? (array) $setting['also'] : array(),
                'change' => $default && !self::same($now, $value),
                'reason' => !$default ? 'set' : (self::same($now, $value) ? 'same' : ''),
            );
        }
        return $out;
    }

    /**
     * Whether two setting values are the same (lists in any order).
     *
     * @param mixed $a Value.
     * @param mixed $b Value.
     * @return bool
     */
    private static function same($a, $b) {
        if (is_array($a) && is_array($b)) {
            $a = array_map('strval', $a);
            $b = array_map('strval', $b);
            sort($a);
            sort($b);
            return $a === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        return (string) (is_array($a) ? implode(',', $a) : $a) === (string) (is_array($b) ? implode(',', $b) : $b);
    }

    /**
     * A setting's value in words, for the dry run.
     *
     * @param mixed               $value Value.
     * @param array<string,mixed> $field Schema entry.
     * @return string
     */
    private static function words($value, array $field) {
        if (isset($field['type']) && $field['type'] === 'bool') {
            return $value ? __('On', 'seoprostats') : __('Off', 'seoprostats');
        }
        if (is_array($value)) {
            $labels = SEOProStats_Settings::options_for($field);
            $names  = array();
            foreach ($value as $item) {
                $names[] = isset($labels[$item]) ? (string) $labels[$item] : (string) $item;
            }
            return $names ? implode(', ', $names) : __('None', 'seoprostats');
        }
        $value = trim((string) $value);
        if ($value === '') {
            return __('None', 'seoprostats');
        }
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $value));
        /* translators: %d: number of lines. */
        return count($lines) > 3 ? sprintf(_n('%d line', '%d lines', count($lines), 'seoprostats'), count($lines)) : implode(', ', $lines);
    }

    /**
     * Start an import job: the preferred plugin first when another shares
     * days with this one, then this one. Cron carries it on, and so does
     * each read of the status while the Import tab is open.
     *
     * @param string               $key  Adapter key.
     * @param array<string,string> $args from, to, prefer.
     * @param bool                 $cron Schedule it (false: the caller runs step() itself, as WP-CLI does).
     * @return array<string,mixed>|WP_Error The job.
     */
    public static function start($key, array $args = array(), $cron = true) {
        $job = self::state();
        if (isset($job['status']) && $job['status'] === 'running') {
            return new WP_Error('seoprostats_import_busy', __('An import is already running. Wait for it to finish.', 'seoprostats'), array('status' => 409));
        }
        $plan = self::plan($key, $args);
        if (is_wp_error($plan)) {
            return $plan;
        }
        $queue = array();
        foreach (array_unique(array($plan['prefer'], $key)) as $item) {
            $queue[] = array('key' => $item, 'id' => 0, 'days' => null, 'total' => 0, 'done' => 0, 'rows' => 0);
        }
        $state = array(
            'status'   => 'running',
            'from'     => isset($args['from']) ? (string) $args['from'] : '',
            'to'       => isset($args['to']) ? (string) $args['to'] : '',
            'queue'    => $queue,
            'current'  => 0,
            'started'  => time(),
            'finished' => 0,
            'stepped'  => 0,
            'user'     => get_current_user_id(),
        );
        update_option(self::STATE_OPTION, $state, false);
        if ($cron) {
            wp_schedule_single_event(time(), self::HOOK);
        }
        return self::job();
    }

    /**
     * WP-CLI: run an import to the end.
     *
     * @param string               $key      Adapter key.
     * @param array<string,string> $args     from, to, prefer.
     * @param callable|null        $progress Called with the job after each step.
     * @return array<string,mixed>|WP_Error The job.
     */
    public static function run($key, array $args = array(), $progress = null) {
        $job = self::start($key, $args, false);
        if (is_wp_error($job)) {
            return $job;
        }
        do {
            $job = self::step(self::BUDGET);
            if ($progress) {
                call_user_func($progress, $job);
            }
        } while ($job['status'] === 'running' && empty($job['locked']));
        return $job;
    }

    /**
     * Cron: move the job on, and come back while it runs.
     */
    public static function cron() {
        $job = self::step(self::BUDGET);
        if ($job['status'] === 'running') {
            wp_schedule_single_event(time() + 5, self::HOOK);
        }
    }

    /**
     * Move a running job on while a status read waits for it (the Import
     * tab's polling): a short step when no other request holds the lock.
     *
     * @return array<string,mixed> The job.
     */
    public static function nudge() {
        $job = self::state();
        if (!isset($job['status']) || $job['status'] !== 'running') {
            return self::job();
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
        }
        return self::step(self::STEP);
    }

    /**
     * Import days within a budget, under the lock.
     *
     * @param int $budget Seconds.
     * @return array<string,mixed> The job; locked: another request holds the lock.
     */
    public static function step($budget) {
        if (!self::lock()) {
            return self::job() + array('locked' => true);
        }
        $before = SEOProStats_Schema::use_set('live');
        try {
            $state = self::state();
            if (!isset($state['status']) || $state['status'] !== 'running') {
                return self::job();
            }
            $start = microtime(true);
            $own   = self::own_from();
            while (isset($state['queue'][$state['current']]) && SEOProStats_Feature::more_time($start, $budget)) {
                $item = &$state['queue'][$state['current']];
                $item = self::step_item($item, $own);
                if ($item['days'] === array() && !empty($item['id'])) {
                    self::finish($item);
                    $state['current']++;
                }
                unset($item);
                update_option(self::STATE_OPTION, $state, false);
            }
            if (!isset($state['queue'][$state['current']])) {
                $state['status']   = 'done';
                $state['finished'] = time();
                self::refresh_imported();
                self::forget_found();
            }
            $state['stepped'] = time();
            update_option(self::STATE_OPTION, $state, false);
            return self::job();
        } finally {
            SEOProStats_Schema::use_set($before);
            self::unlock();
        }
    }

    /**
     * One day of a queue item (or its start: the imports row and its days).
     *
     * @param array<string,mixed> $item Queue item.
     * @param string              $own  SEO Pro Stats's own first day.
     * @return array<string,mixed> The item.
     */
    private static function step_item(array $item, $own) {
        $source = self::source((string) $item['key']);
        if (!$source) {
            $item['days'] = array();
            $item['id']   = $item['id'] ? $item['id'] : -1;
            return $item;
        }
        if (empty($item['id'])) {
            $plan = self::make_plan((string) $item['key'], array_filter(array('from' => self::state()['from'], 'to' => self::state()['to'])), false);
            if (is_wp_error($plan)) {
                $item['days']  = array();
                $item['id']    = -1;
                $item['error'] = $plan->get_error_message();
                return $item;
            }
            $item['days']    = $plan['import'];
            $item['total']   = count($plan['import']);
            $item['skipped'] = $plan['skipped'];
            $item['version'] = $plan['version'];
            $item['first']   = '';
            $item['last']    = '';
            $item['ours']    = array_fill_keys(array('pageviews', 'visits', 'visitors'), 0);
            $item['theirs']  = array_fill_keys(array('pageviews', 'visits', 'visitors'), 0);
            $item['imported'] = 0;
            $item['id']       = self::insert_import((string) $item['key'], $plan);
            if ($item['id'] <= 0) {
                $item['id']    = -1;
                $item['days']  = array();
                $item['error'] = __('The import could not be recorded.', 'seoprostats');
            }
            return $item;
        }
        $day = (string) array_shift($item['days']);
        $item['done']++;
        $filled = self::filled(array($day));
        if ($day >= $own || isset($filled[$day])) {
            // Filled meanwhile (by our own count or another import).
            $reason = ($day >= $own || $filled[$day] === 0) ? 'own' : 'imported';
            if ($reason === 'own') {
                $item['skipped']['own']++;
            } else {
                $owner                               = self::owners(array($filled[$day]));
                $by                                  = isset($owner[$filled[$day]]) ? $owner[$filled[$day]] : 'unknown';
                $item['skipped']['imported'][$by] = (isset($item['skipped']['imported'][$by]) ? $item['skipped']['imported'][$by] : 0) + 1;
            }
            return $item;
        }
        $read = $source->days($day, $day);
        $rows = isset($read[$day]) ? self::encode($read[$day], true) : array();
        if (!$rows) {
            $item['skipped']['empty'] = (isset($item['skipped']['empty']) ? $item['skipped']['empty'] : 0) + 1;
            return $item;
        }
        $written = self::write($day, (int) $item['id'], $rows);
        if ($written === false) {
            $item['skipped']['failed'] = (isset($item['skipped']['failed']) ? $item['skipped']['failed'] : 0) + 1;
            return $item;
        }
        $item['rows'] += $written;
        $item['imported']++;
        $item['first'] = $item['first'] === '' ? $day : min($item['first'], $day);
        $item['last']  = max($item['last'], $day);
        foreach ($rows as $row) {
            if ($row[0] === 0) {
                foreach (array('pageviews', 'visits', 'visitors') as $metric) {
                    $item['ours'][$metric] += $row[2][$metric];
                }
                break;
            }
        }
        foreach ($source->totals($day, $day) as $metric => $count) {
            $item['theirs'][$metric] += (int) $count;
        }
        return $item;
    }

    /**
     * Rows read from an adapter as daily rows: dimension code, value
     * (dictionary id or code) and all the metrics, one row per code and
     * value (values that become the same are added together).
     *
     * @param array<int,array{0:string,1:int|string,2:array<string,int>}> $rows Adapter rows.
     * @param bool                                                         $ids  Add new texts to the dictionary (false: the dry run; texts count as one value each).
     * @return array<int,array{0:int,1:int,2:array<string,int>}>
     */
    public static function encode(array $rows, $ids) {
        $kinds = array(
            'page'         => SEOProStats_Schema::DICT_PATH,
            'entry'        => SEOProStats_Schema::DICT_PATH,
            'exit'         => SEOProStats_Schema::DICT_PATH,
            'landing'      => SEOProStats_Schema::DICT_PATH,
            'source'       => SEOProStats_Schema::DICT_HOST,
            'utm_source'   => SEOProStats_Schema::DICT_UTM,
            'utm_medium'   => SEOProStats_Schema::DICT_UTM,
            'utm_campaign' => SEOProStats_Schema::DICT_UTM,
            'utm_term'     => SEOProStats_Schema::DICT_UTM,
            'utm_content'  => SEOProStats_Schema::DICT_UTM,
            'browser'      => SEOProStats_Schema::DICT_BROWSER,
            'os'           => SEOProStats_Schema::DICT_OS,
        );
        $texts = array();
        foreach ($rows as $row) {
            if (isset($kinds[$row[0]])) {
                $texts[$kinds[$row[0]]][] = (string) $row[1];
            }
        }
        $dict = array();
        foreach ($texts as $kind => $values) {
            if ($ids) {
                $dict[$kind] = SEOProStats_Dict::ids($kind, $values);
            } else {
                $dict[$kind] = array('' => 0);
                foreach (array_values(array_unique(array_map(array('SEOProStats_Dict', 'clean'), $values))) as $i => $value) {
                    $dict[$kind][$value] = $value === '' ? 0 : $i + 1;
                }
            }
        }
        $out = array();
        foreach ($rows as $row) {
            list($name, $value, $metrics) = $row;
            if ($name === '') {
                $dim = 0;
                $val = 0;
            } elseif ($name === 'landing') {
                $dim = SEOProStats_Rollup::SEARCH_LANDING;
            } elseif (isset(SEOProStats_Rollup::DIMS[$name])) {
                $dim = SEOProStats_Rollup::DIMS[$name];
            } else {
                continue;
            }
            if ($name === '') {
                $val = 0;
            } elseif (isset($kinds[$name])) {
                $clean = SEOProStats_Dict::clean((string) $value);
                $val   = isset($dict[$kinds[$name]][$clean]) ? (int) $dict[$kinds[$name]][$clean] : 0;
            } elseif ($name === 'country') {
                $val = SEOProStats_Rollup::country_value((string) $value);
            } else {
                $val = (int) $value;
            }
            if ($val < 0) {
                continue;
            }
            $key = $dim . ':' . $val;
            if (!isset($out[$key])) {
                $out[$key] = array($dim, $val, array_fill_keys(self::METRICS, 0));
            }
            foreach (self::METRICS as $metric) {
                $out[$key][2][$metric] += isset($metrics[$metric]) ? max(0, (int) $metrics[$metric]) : 0;
            }
        }
        return array_values($out);
    }

    /**
     * A daily code's dimension name, for the dry run.
     *
     * @param int $dim Code.
     * @return string
     */
    private static function dimension_name($dim) {
        if ($dim === 0) {
            return 'site';
        }
        if ($dim === SEOProStats_Rollup::SEARCH_LANDING) {
            return 'landing';
        }
        $name = array_search($dim, SEOProStats_Rollup::DIMS, true);
        return $name === false ? (string) $dim : (string) $name;
    }

    /**
     * Write one imported day, all or nothing.
     *
     * @param string                                         $day    Y-m-d.
     * @param int                                            $import imports.id.
     * @param array<int,array{0:int,1:int,2:array<string,int>}> $rows From encode().
     * @return int|false Rows written.
     */
    private static function write($day, $import, array $rows) {
        global $wpdb;
        $table = SEOProStats_Schema::table('daily');
        $count = 0;
        $ok    = true;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $groups holds only fixed placeholder groups, one per row.
        $wpdb->query('START TRANSACTION');
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $args = array($table);
            foreach ($chunk as $row) {
                array_push($args, $day, $row[0], $row[1]);
                foreach (self::METRICS as $metric) {
                    $args[] = $row[2][$metric];
                }
                $args[] = $import;
            }
            $groups = implode(', ', array_fill(0, count($chunk), '(%s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)'));
            $result = $wpdb->query($wpdb->prepare("INSERT INTO %i (day, dim, val, visitors, visits, pageviews, bounces, engaged_ms, events, scroll, import_id) VALUES $groups", $args));
            if ($result === false) {
                $ok = false;
                break;
            }
            $count += (int) $result;
        }
        if ($ok) {
            $wpdb->query('COMMIT');
        } else {
            $wpdb->query('ROLLBACK');
        }
        // phpcs:enable
        return $ok ? $count : false;
    }

    /**
     * Add an imports row for a run.
     *
     * @param string              $key  Adapter key.
     * @param array<string,mixed> $plan From make_plan().
     * @return int Its id, or 0.
     */
    private static function insert_import($key, array $plan) {
        global $wpdb;
        $days = (array) $plan['import'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
        $ok = $wpdb->insert(SEOProStats_Schema::table('imports'), array(
            'source'   => $key,
            'status'   => self::RUNNING,
            'started'  => time(),
            'day_from' => $days ? min($days) : $plan['from'],
            'day_to'   => $days ? max($days) : $plan['to'],
            'meta'     => (string) wp_json_encode(array('version' => $plan['version'], 'range' => array($plan['from'], $plan['to']))),
        ), array('%s', '%d', '%d', '%s', '%s', '%s'));
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Finish a queue item: its imports row (days, rows, what was skipped,
     * the check against the plugin's own counts, the settings carried
     * over), its settings, and a note on the timeline.
     *
     * @param array<string,mixed> $item Queue item.
     */
    private static function finish(array &$item) {
        global $wpdb;
        if ((int) $item['id'] <= 0) {
            return;
        }
        $source   = self::source((string) $item['key']);
        $settings = $source ? self::apply_settings($source) : array();
        $meta     = array(
            'version'  => isset($item['version']) ? (string) $item['version'] : '',
            'days'     => (int) $item['imported'],
            'skipped'  => isset($item['skipped']) ? $item['skipped'] : array(),
            'check'    => array('source' => $item['theirs'], 'imported' => $item['ours']),
            'settings' => $settings,
        );
        if (!empty($item['error'])) {
            $meta['error'] = (string) $item['error'];
        }
        if ($source && $item['last'] !== '') {
            require_once __DIR__ . '/class-seoprostats-changes.php';
            /* translators: %s: plugin name. */
            $note = SEOProStats_Changes::annotate(sprintf(__('Statistics imported from %s', 'seoprostats'), $source::NAME), '', $item['last'] . ' 23:59');
            if (is_array($note) && isset($note['id'])) {
                $meta['note'] = (int) $note['id'];
            }
        }
        $values = array(
            'status'     => empty($item['error']) ? self::DONE : self::FAILED,
            'finished'   => time(),
            'rows_added' => (int) $item['rows'],
            'meta'       => (string) wp_json_encode($meta),
        );
        if ($item['first'] !== '') {
            $values['day_from'] = $item['first'];
            $values['day_to']   = $item['last'];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update(SEOProStats_Schema::table('imports'), $values, array('id' => (int) $item['id']));
        self::refresh_imported();
    }

    /**
     * Carry over the adapter's settings that are still at our default.
     * The plugin's own options are never written.
     *
     * @param SEOProStats_Migrate_Source $source Adapter.
     * @return array<string,array{0:string,1:string}> Key => before, after (in words).
     */
    private static function apply_settings(SEOProStats_Migrate_Source $source) {
        $schema   = SEOProStats_Settings::schema();
        $defaults = SEOProStats_Settings::defaults();
        $changed  = array();
        foreach (self::settings_plan($source) as $setting) {
            if (!$setting['change']) {
                continue;
            }
            $saved = SEOProStats_Settings::set($setting['key'], $setting['value']);
            if (is_wp_error($saved)) {
                continue;
            }
            $changed[$setting['key']] = array($setting['now'], $setting['to']);
            foreach ($setting['also'] as $key => $value) {
                if (isset($schema[$key]) && self::same(SEOProStats_Settings::get($key), $defaults[$key]) && !self::same($defaults[$key], $value)) {
                    $before = self::words(SEOProStats_Settings::get($key), $schema[$key]);
                    if (!is_wp_error(SEOProStats_Settings::set($key, $value))) {
                        $changed[$key] = array($before, self::words($value, $schema[$key]));
                    }
                }
            }
        }
        return $changed;
    }

    /**
     * Record the last imported day and the time, so the daily summaries
     * are read for imported days and report caches start again.
     */
    private static function refresh_imported() {
        global $wpdb;
        $keys = array_keys(self::SOURCES + self::sources());
        $in   = implode(', ', array_fill(0, count($keys), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own small table; $in holds only placeholders.
        $through = (string) $wpdb->get_var($wpdb->prepare("SELECT MAX(day_to) FROM %i WHERE source IN ($in) AND status = %d AND rows_added > 0", array_merge(array(SEOProStats_Schema::table('imports')), $keys, array(self::DONE))));
        update_option(SEOProStats_Schema::option(SEOProStats_Rollup::IMPORTED_OPTION), array('through' => $through, 'at' => time()), false);
    }

    /**
     * Undo a migration import: delete its daily rows (its days through the
     * primary key, and its id) and its timeline note. Settings it carried
     * over stay.
     *
     * @param int $id imports.id.
     * @return int|WP_Error Rows deleted.
     */
    public static function undo($id) {
        global $wpdb;
        $before = SEOProStats_Schema::use_set('live');
        try {
            $table = SEOProStats_Schema::table('imports');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, (int) $id), ARRAY_A);
            if (!$row || !self::owns((string) $row['source'])) {
                return new WP_Error('seoprostats_import_unknown', __('There is no import with that ID.', 'seoprostats'), array('status' => 404));
            }
            if ((int) $row['status'] === self::UNDONE) {
                return 0;
            }
            $job = self::state();
            if ((int) $row['status'] === self::RUNNING && isset($job['status']) && $job['status'] === 'running') {
                return new WP_Error('seoprostats_import_busy', __('That import is still running. Undo it when it has finished.', 'seoprostats'), array('status' => 409));
            }
            $deleted = 0;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table: the import's days by primary key, and its id, in batches.
                $rows     = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE day >= %s AND day <= %s AND import_id = %d LIMIT %d', SEOProStats_Schema::table('daily'), $row['day_from'], $row['day_to'], (int) $id, self::DELETE_BATCH));
                $deleted += (int) $rows;
            } while ($rows === self::DELETE_BATCH);
            $meta = json_decode((string) $row['meta'], true);
            if (is_array($meta) && !empty($meta['note'])) {
                require_once __DIR__ . '/class-seoprostats-changes.php';
                SEOProStats_Changes::delete_note((int) $meta['note']);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
            $wpdb->update($table, array('status' => self::UNDONE, 'finished' => time()), array('id' => (int) $id));
            self::refresh_imported();
            self::forget_found();
            return $deleted;
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Migration imports, newest first, with their check and settings.
     *
     * @param int $limit Most rows.
     * @return array<int,array<string,mixed>>
     */
    public static function imports($limit = 20) {
        global $wpdb;
        $names = array();
        foreach (self::sources() as $key => $class) {
            $names[$key] = $class::NAME;
        }
        $keys = array_keys(self::SOURCES + $names);
        $in   = implode(', ', array_fill(0, count($keys), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own small table; $in holds only placeholders.
        $rows     = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE source IN ($in) ORDER BY id DESC LIMIT %d", array_merge(array(SEOProStats_Schema::table('imports')), $keys, array((int) $limit))), ARRAY_A);
        $statuses = array(self::RUNNING => 'running', self::DONE => 'done', self::FAILED => 'failed', self::UNDONE => 'undone');
        $out      = array();
        foreach ((array) $rows as $row) {
            $meta  = json_decode((string) $row['meta'], true);
            $meta  = is_array($meta) ? $meta : array();
            $out[] = array(
                'id'       => (int) $row['id'],
                'source'   => (string) $row['source'],
                'name'     => isset($names[$row['source']]) ? $names[$row['source']] : (string) $row['source'],
                'status'   => isset($statuses[(int) $row['status']]) ? $statuses[(int) $row['status']] : 'unknown',
                'started'  => (int) $row['started'],
                'finished' => (int) $row['finished'],
                'from'     => (string) $row['day_from'],
                'to'       => (string) $row['day_to'],
                'days'     => isset($meta['days']) ? (int) $meta['days'] : 0,
                'rows'     => (int) $row['rows_added'],
                'version'  => isset($meta['version']) ? (string) $meta['version'] : '',
                'skipped'  => isset($meta['skipped']) ? $meta['skipped'] : array(),
                'check'    => isset($meta['check']) ? $meta['check'] : array(),
                'settings' => isset($meta['settings']) ? $meta['settings'] : array(),
                'error'    => isset($meta['error']) ? (string) $meta['error'] : '',
            );
        }
        return $out;
    }

    /**
     * The sources of some imports rows.
     *
     * @param int[] $ids imports.id.
     * @return array<int,string> Id => source key.
     */
    private static function owners(array $ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return array();
        }
        $in = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by primary key; $in holds only placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, source FROM %i WHERE id IN ($in)", array_merge(array(SEOProStats_Schema::table('imports')), $ids)), ARRAY_A);
        $out  = array();
        foreach ((array) $rows as $row) {
            $out[(int) $row['id']] = (string) $row['source'];
        }
        return $out;
    }

    /**
     * The job as the Import tab and WP-CLI show it.
     *
     * @return array<string,mixed>
     */
    public static function job() {
        $state = self::state();
        if (!isset($state['status'])) {
            return array('status' => 'none');
        }
        $items = array();
        foreach ((array) $state['queue'] as $item) {
            $class   = isset(self::sources()[$item['key']]) ? self::sources()[$item['key']] : '';
            $items[] = array(
                'source' => (string) $item['key'],
                'name'   => $class ? $class::NAME : (string) $item['key'],
                'id'     => max(0, (int) $item['id']),
                'total'  => (int) $item['total'],
                'done'   => (int) $item['done'],
                'rows'   => (int) $item['rows'],
                'error'  => isset($item['error']) ? (string) $item['error'] : '',
            );
        }
        return array(
            'status'   => (string) $state['status'],
            'queue'    => $items,
            'started'  => (int) $state['started'],
            'finished' => (int) $state['finished'],
        );
    }

    /**
     * The stored job.
     *
     * @return array<string,mixed>
     */
    private static function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Whether the current user may remove another plugin's leftovers.
     * WP-CLI runs as the server's user, who can delete them anyway.
     *
     * @return bool
     */
    public static function can_cleanup() {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }
        return current_user_can('delete_plugins') && current_user_can('manage_options') && SEOProStats_Settings::can_change();
    }

    /**
     * Remove what a plugin left behind: exactly the list from its
     * adapter's leftovers(), on this site only, and only while the plugin
     * is not active here or on the network. Cannot be undone; imported
     * days stay.
     *
     * @param string $key     Adapter key.
     * @param bool   $dry_run Only list them.
     * @return array<string,mixed>|WP_Error
     */
    public static function cleanup($key, $dry_run = true) {
        $source = self::source($key);
        if (!$source) {
            return new WP_Error('seoprostats_migrate_unknown', __('SEO Pro Stats cannot import from that plugin.', 'seoprostats'), array('status' => 404));
        }
        if (!self::can_cleanup()) {
            return new WP_Error('seoprostats_migrate_cleanup_denied', __('Removing another plugin\'s data needs the right to delete plugins and to change settings.', 'seoprostats'), array('status' => 403));
        }
        $plugin = $source->plugin();
        if ($plugin['state'] === 'active' || $plugin['state'] === 'network') {
            return new WP_Error(
                'seoprostats_migrate_active',
                /* translators: %s: plugin name. */
                sprintf(__('%s is active. Deactivate it first: its data can only be removed while it is not running.', 'seoprostats'), $source::NAME),
                array('status' => 409, 'plugin' => $plugin)
            );
        }
        $list = $source->leftovers();
        $out  = array('source' => $key, 'name' => $source::NAME, 'plugin' => $plugin, 'leftovers' => $list, 'dry_run' => (bool) $dry_run);
        if ($dry_run) {
            return $out;
        }
        $out['removed'] = self::remove($list);
        SEOProStats_Migrate_Source::forget_tables();
        self::forget_found();
        $out['left'] = $source->leftovers();

        $before = SEOProStats_Schema::use_set('live');
        try {
            if (SEOProStats_Schema::is_current()) {
                require_once __DIR__ . '/class-seoprostats-changes.php';
                /* translators: %s: plugin name. */
                SEOProStats_Changes::annotate(sprintf(__('Leftover data of %s removed', 'seoprostats'), $source::NAME));
            }
        } finally {
            SEOProStats_Schema::use_set($before);
        }
        return $out;
    }

    /**
     * Delete a leftovers list on this site (network-wide items are only
     * listed, never deleted here).
     *
     * @param array<string,mixed> $list From leftovers().
     * @return array<string,int> Kind => items removed.
     */
    private static function remove(array $list) {
        global $wpdb;
        $done = array_fill_keys(array('tables', 'options', 'transients', 'cron', 'user_meta', 'files'), 0);
        foreach ((array) $list['tables'] as $table) {
            // Only this site's tables (its prefix), one listed name at a time.
            if (strpos((string) $table, $wpdb->prefix) !== 0) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing another plugin's leftover table: confirmed by the owner, while it is inactive (AGENTS.md).
            if ($wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', (string) $table)) !== false) {
                $done['tables']++;
            }
        }
        foreach (array('options', 'transients') as $kind) {
            foreach ((array) $list[$kind] as $name) {
                if (delete_option((string) $name)) {
                    $done[$kind]++;
                }
            }
        }
        foreach ((array) $list['cron'] as $hook) {
            if (wp_unschedule_hook((string) $hook) !== false) {
                $done['cron']++;
            }
        }
        foreach ((array) $list['user_meta'] as $meta_key) {
            if (delete_metadata('user', 0, (string) $meta_key, '', true)) {
                $done['user_meta']++;
            }
        }
        $base = wp_normalize_path(trailingslashit(WP_CONTENT_DIR));
        foreach ((array) $list['files'] as $relative) {
            $relative = ltrim(wp_normalize_path((string) $relative), '/');
            if ($relative === '' || strpos($relative, '..') !== false) {
                continue;
            }
            $path = untrailingslashit($base . $relative);
            if (is_link($path) || is_file($path)) {
                wp_delete_file($path);
            } elseif (is_dir($path)) {
                self::remove_dir($path);
            }
            clearstatcache();
            if (!file_exists($path)) {
                $done['files']++;
            }
        }
        return $done;
    }

    /**
     * Delete a folder and everything in it, without following links.
     *
     * @param string $dir Folder.
     */
    private static function remove_dir($dir) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || !$item->isDir()) {
                wp_delete_file($path);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- an emptied folder of the leftovers list.
                @rmdir($path);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the emptied leftover folder itself.
        @rmdir($dir);
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
     * Forget the job, the lock and the plugins found (uninstall; the
     * imported days go with the tables).
     */
    public static function forget() {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::STATE_OPTION);
        delete_option(self::LOCK_OPTION);
        delete_transient(self::CACHE);
        delete_option(SEOProStats_Rollup::IMPORTED_OPTION);
        delete_option(SEOProStats_Rollup::IMPORTED_OPTION . '_demo');
    }
}
