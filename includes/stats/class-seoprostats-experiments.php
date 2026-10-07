<?php
/**
 * Experiments: a change and what it was meant to do, written down before
 * the result is known, then measured. The same number of days before and
 * after the change are compared, against pages that were left alone, with
 * the search engine updates and other changes of the time named.
 *
 * An experiment names its page or pages, the measure (clicks,
 * impressions, CTR, position, visits from search or conversions of a
 * goal), the direction expected and the smallest change that counts. The
 * plugin suggests keep, revise, undo or inconclusive; a person or agent
 * decides, and the measurement the decision was made on is kept with it.
 *
 * Every read uses an index: gsc_pages by its primary key (engine, day),
 * daily by its search landings, a goal's hits by name_ts or path_ts,
 * changes by ts and experiments by their keys. Measurements are cached as
 * the reports are. Design: docs/seo-loop.md → Experiments.
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

final class SEOProStats_Experiments {

    /** Measures: code (the metric column) => name. Codes never change meaning. */
    const METRICS = array(
        1 => 'clicks',
        2 => 'impressions',
        3 => 'ctr',
        4 => 'position',
        5 => 'visits',
        6 => 'conversions',
    );

    /** Directions expected: code => name. For position, up is better (a lower number). */
    const DIRECTIONS = array(
        1 => 'up',
        2 => 'down',
    );

    /** States: code => name. */
    const STATUSES = array(
        1 => 'running',
        2 => 'decided',
        3 => 'cancelled',
    );

    /** Results decided: code => name (0: none yet). */
    const RESULTS = array(
        1 => 'keep',
        2 => 'revise',
        3 => 'undo',
        4 => 'inconclusive',
    );

    /** Lengths of each window, in days: whole weeks, so both hold each weekday equally often. */
    const WINDOWS = array(7, 14, 28, 56, 84);

    /** Default window length. */
    const DAYS = 28;

    /** Default threshold: percent for counts and CTR, tenths of a place for position. */
    const THRESHOLD = 10;

    /** Most pages in one experiment. */
    const MAX_PAGES = 50;

    /** Most experiments listed (the newest). */
    const LIST_LIMIT = 50;

    /** Most running experiments read for their pages (running_pages()). */
    const MAX_RUNNING = 500;

    /** Most pages in the comparison group (most impressions or visits before). */
    const GROUP = 200;

    /** Fewest pages that make a comparison group. */
    const MIN_GROUP = 5;

    /** Least data on the experiment's pages in each window to judge it. */
    const MIN_CLICKS      = 20;
    const MIN_IMPRESSIONS = 200;
    const MIN_VISITS      = 20;
    const MIN_CONVERSIONS = 5;

    /** The comparison group's spread: its pages' effects from this share to that one. */
    const NOISE_LOW  = 0.1;
    const NOISE_HIGH = 0.9;

    /** Most changes read for one measurement, and most listed per kind of confounder. */
    const MAX_CHANGES     = 5000;
    const MAX_CONFOUNDERS = 20;

    /** @var array<string,string> Newest day with search data by data set and engine code, per request. */
    private static $through = array();

    // ------------------------------------------------------------------
    // Recording.

    /**
     * Record an experiment on the current data set, and its start on the
     * timeline (a change of kind experiment). Run it inside
     * SEOProStats_API::on_data() for the demo data.
     *
     * @param array<string,mixed> $input name; change (a change id) or start (a time; default now); page or pages (paths, up to 50; default the change's page); days, engine, metric, direction, threshold, goal (a goal id; needed for conversions), hypothesis, note.
     * @return array<string,mixed>|WP_Error The experiment.
     */
    public static function add(array $input) {
        global $wpdb;
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-dict.php';
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return self::error('seoprostats_experiment_failed', __('The experiment could not be saved.', 'seoprostats'), 500);
        }
        $name = self::text(isset($input['name']) ? $input['name'] : '');
        if ($name === '') {
            return self::error('seoprostats_experiment', __('An experiment needs a name: the change and what it should do, in one line.', 'seoprostats'));
        }

        $change_id = isset($input['change']) ? max(0, (int) $input['change']) : 0;
        $pages     = self::paths(isset($input['pages']) ? $input['pages'] : (isset($input['page']) ? $input['page'] : ''));
        if ($change_id) {
            $change = SEOProStats_Changes::get($change_id);
            if (!$change) {
                /* translators: %d: change id */
                return self::error('seoprostats_not_found', sprintf(__('There is no change %d.', 'seoprostats'), $change_id), 404);
            }
            $start = (int) strtotime((string) $change['t']);
            if (!$pages && $change['path'] !== null) {
                $pages = array((string) $change['path']);
            }
        } else {
            $start = SEOProStats_Changes::when(isset($input['start']) ? $input['start'] : '');
            if (is_wp_error($start)) {
                return $start;
            }
        }
        if (!$pages) {
            return self::error('seoprostats_experiment_pages', __('Name the page or pages the experiment is about (a site-wide change touches every page, so it has no unchanged pages to compare with).', 'seoprostats'));
        }
        if (count($pages) > self::MAX_PAGES) {
            /* translators: %d: most pages */
            return self::error('seoprostats_experiment_pages', sprintf(__('An experiment can have up to %d pages.', 'seoprostats'), self::MAX_PAGES));
        }

        $fields = self::fields($input);
        if (is_wp_error($fields)) {
            return $fields;
        }
        $ids     = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, $pages);
        $path_id = count($pages) === 1 && isset($ids[SEOProStats_Dict::clean($pages[0])]) ? (int) $ids[SEOProStats_Dict::clean($pages[0])] : 0;
        $meta    = array_filter(array(
            'pages'      => count($pages) > 1 ? $pages : array(),
            'goal'       => $fields['goal'],
            'hypothesis' => self::long_text(isset($input['hypothesis']) ? $input['hypothesis'] : ''),
            'note'       => self::long_text(isset($input['note']) ? $input['note'] : ''),
        ));
        $windows = self::windows($start, $fields['days'], $fields['engine'], $fields['metric']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing our own table.
        $saved = $wpdb->insert(
            SEOProStats_Schema::table('experiments'),
            array(
                'created'   => time(),
                'user_id'   => get_current_user_id(),
                'name'      => $name,
                'start'     => $start,
                'days'      => $fields['days'],
                'review'    => $windows['review'],
                'engine'    => $fields['engine'],
                'metric'    => $fields['metric'],
                'direction' => $fields['direction'],
                'threshold' => $fields['threshold'],
                'change_id' => $change_id,
                'path_id'   => $path_id,
                'status'    => 1,
                'result'    => 0,
                'decided'   => 0,
                'meta'      => self::json($meta),
            ),
            array('%d', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s')
        );
        $id = $saved ? (int) $wpdb->insert_id : 0;
        if (!$id) {
            return self::error('seoprostats_experiment_failed', __('The experiment could not be saved.', 'seoprostats'), 500);
        }

        // Its start on the timeline: on its page, or site-wide for several.
        $marked = SEOProStats_Changes::write(array(
            'ts'          => $start,
            'kind'        => SEOProStats_Changes::EXPERIMENT,
            'path'        => count($pages) === 1 ? $pages[0] : '',
            'object_type' => 'experiment',
            'object_id'   => $id,
            'old'         => '',
            'new'         => $name,
            'meta'        => array('metric' => self::METRICS[$fields['metric']], 'pages' => count($pages)),
            'source'      => SEOProStats_Changes::source(),
            'user_id'     => get_current_user_id(),
        ));
        if ($marked) {
            $meta['marker'] = (int) $wpdb->insert_id;
            self::save_meta($id, $meta);
        }
        self::$through = array();
        return self::get($id);
    }

    /**
     * Decide, note or cancel an experiment of the current data set.
     *
     * Deciding keeps the measurement it was made on (meta.measured), so
     * newer data never rewrites a decision; deciding again changes only
     * the result and the note.
     *
     * @param int                 $id    Experiment id.
     * @param array<string,mixed> $input action (decide, note or cancel), result (keep, revise, undo, inconclusive; for decide), note.
     * @return array<string,mixed>|WP_Error The experiment.
     */
    public static function update($id, array $input) {
        global $wpdb;
        $row = self::row((int) $id);
        if (!$row) {
            return self::not_found((int) $id);
        }
        $action = isset($input['action']) ? (string) $input['action'] : 'note';
        $meta   = self::meta($row);
        if (isset($input['note']) && (string) $input['note'] !== '') {
            $meta['note'] = self::long_text($input['note']);
        }
        $set = array();
        if ($action === 'decide') {
            $result = array_search(isset($input['result']) ? (string) $input['result'] : '', self::RESULTS, true);
            if ($result === false) {
                /* translators: %s: list of results */
                return self::error('seoprostats_experiment_result', sprintf(__('Decide with a result: %s.', 'seoprostats'), implode(', ', self::RESULTS)));
            }
            if ((int) $row['status'] === 3) {
                return self::error('seoprostats_experiment_cancelled', __('This experiment was cancelled.', 'seoprostats'), 409);
            }
            if ((int) $row['status'] !== 2) {
                $meta['measured'] = self::measure($row);
                $set['decided']   = time();
            }
            $set['status'] = 2;
            $set['result'] = (int) $result;
        } elseif ($action === 'cancel') {
            $set['status'] = 3;
        } elseif ($action !== 'note') {
            return self::error('seoprostats_experiment_action', __('The action is decide, note or cancel.', 'seoprostats'));
        }
        $set['meta'] = self::json($meta);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update(SEOProStats_Schema::table('experiments'), $set, array('id' => (int) $id));
        return self::get((int) $id);
    }

    /**
     * Delete an experiment of the current data set and its start on the
     * timeline.
     *
     * @param int $id Experiment id.
     * @return bool Whether it was deleted.
     */
    public static function delete($id) {
        global $wpdb;
        $row = self::row((int) $id);
        if (!$row) {
            return false;
        }
        $meta = self::meta($row);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own tables, by primary key.
        $done = (bool) $wpdb->delete(SEOProStats_Schema::table('experiments'), array('id' => (int) $id), array('%d'));
        if ($done && !empty($meta['marker'])) {
            $wpdb->delete(SEOProStats_Schema::table('changes'), array('id' => (int) $meta['marker'], 'kind' => SEOProStats_Changes::EXPERIMENT), array('%d', '%d'));
        }
        // phpcs:enable
        return $done;
    }

    // ------------------------------------------------------------------
    // Reading.

    /**
     * One experiment of the current data set, with its measurement.
     *
     * @param int $id Experiment id.
     * @return array<string,mixed>|WP_Error
     */
    public static function get($id) {
        $row = self::row((int) $id);
        if (!$row) {
            return self::not_found((int) $id);
        }
        return self::shape($row);
    }

    /**
     * The newest experiments of the current data set (LIST_LIMIT), due
     * for review first, then running, decided and cancelled, newest first
     * in each.
     *
     * @param array<string,mixed> $args status (running, due, decided, cancelled; '' for all), page (a path).
     * @return array{experiments:array<int,array<string,mixed>>,total:int}|WP_Error
     */
    public static function list_experiments(array $args = array()) {
        global $wpdb;
        $status = isset($args['status']) ? (string) $args['status'] : '';
        if ($status !== '' && $status !== 'due' && !in_array($status, self::STATUSES, true)) {
            /* translators: %s: list of states */
            return self::error('seoprostats_experiment_status', sprintf(__('The status is one of: %s.', 'seoprostats'), implode(', ', array_merge(array_values(self::STATUSES), array('due')))));
        }
        $out = array('experiments' => array(), 'total' => 0);
        if (!SEOProStats_Schema::is_current()) {
            return $out;
        }
        $table = SEOProStats_Schema::table('experiments');
        $cols  = 'id, created, user_id, name, start, days, review, engine, metric, direction, threshold, change_id, path_id, status, result, decided, meta';
        $page  = isset($args['page']) ? trim((string) $args['page']) : '';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by primary key, key status_review or key path_id; $cols is a fixed column list.
        if ($page !== '') {
            require_once __DIR__ . '/class-seoprostats-changes.php';
            $path = SEOProStats_Changes::path($page);
            $ids  = SEOProStats_Dict::find(SEOProStats_Schema::DICT_PATH, array($path));
            $id   = $ids ? (int) $ids[0] : -1;
            // The page's own, and those of several pages that list it.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE path_id IN (%d, 0) ORDER BY id DESC LIMIT %d", $table, $id, self::LIST_LIMIT * 4), ARRAY_A);
            $rows = array_values(array_filter(is_array($rows) ? $rows : array(), static function ($row) use ($id, $path) {
                $meta = self::meta($row);
                return (int) $row['path_id'] === $id || (isset($meta['pages']) && in_array($path, (array) $meta['pages'], true));
            }));
        } elseif ($status !== '' && $status !== 'due') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`status_review`) WHERE status = %d ORDER BY review DESC LIMIT %d", $table, (int) array_search($status, self::STATUSES, true), self::LIST_LIMIT), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i ORDER BY id DESC LIMIT %d", $table, self::LIST_LIMIT), ARRAY_A);
        }
        // phpcs:enable
        $list = array();
        foreach (is_array($rows) ? array_slice($rows, 0, self::LIST_LIMIT) : array() as $row) {
            $item = self::shape($row);
            if ($status === '' || $item['status'] === $status || ($status === 'due' && $item['due'])) {
                $list[] = $item;
            }
        }
        $rank = static function ($item) {
            return $item['due'] ? 0 : array_search($item['status'], self::STATUSES, true);
        };
        usort($list, static function ($a, $b) use ($rank) {
            return array($rank($a), $b['id']) <=> array($rank($b), $a['id']);
        });
        $out['experiments'] = $list;
        $out['total']       = count($list);
        return $out;
    }

    /**
     * The pages of the running experiments of the current data set (the
     * newest MAX_RUNNING), by key status_review: a second change there
     * would spoil the measurement.
     *
     * @return array<int,int> Path id => experiment id.
     */
    public static function running_pages() {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        require_once __DIR__ . '/class-seoprostats-dict.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by key status_review.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, path_id, meta FROM %i FORCE INDEX (`status_review`) WHERE status = 1 ORDER BY review DESC LIMIT %d', SEOProStats_Schema::table('experiments'), self::MAX_RUNNING), ARRAY_A);
        $out  = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            foreach (self::row_path_ids($row, self::meta($row)) as $path_id) {
                if (!isset($out[$path_id])) {
                    $out[(int) $path_id] = (int) $row['id'];
                }
            }
        }
        return $out;
    }

    /**
     * One stored row by primary key, or null.
     *
     * @param int $id Experiment id.
     * @return array<string,mixed>|null
     */
    private static function row($id) {
        global $wpdb;
        if ($id <= 0 || !SEOProStats_Schema::is_current()) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, created, user_id, name, start, days, review, engine, metric, direction, threshold, change_id, path_id, status, result, decided, meta FROM %i WHERE id = %d', SEOProStats_Schema::table('experiments'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * A stored experiment as the API answers it.
     *
     * @param array<string,mixed> $row Table row.
     * @return array<string,mixed>
     */
    private static function shape(array $row) {
        require_once __DIR__ . '/class-seoprostats-search.php';
        $meta    = self::meta($row);
        $metric  = isset(self::METRICS[(int) $row['metric']]) ? self::METRICS[(int) $row['metric']] : 'clicks';
        $status  = isset(self::STATUSES[(int) $row['status']]) ? self::STATUSES[(int) $row['status']] : 'running';
        $pages   = self::row_pages($row, $meta);
        $engine  = (int) $row['engine'] === SEOProStats_Schema::ENGINE_BING ? 'bing' : 'google';
        $through = self::data_through((int) $row['engine'], (int) $row['metric']);
        $goal    = null;
        if (!empty($meta['goal'])) {
            require_once __DIR__ . '/class-seoprostats-goals.php';
            $found = SEOProStats_Goals::find('goals', (string) $meta['goal']);
            $goal  = array('id' => (string) $meta['goal'], 'name' => $found ? (string) $found['name'] : null);
        }
        $measured = null;
        if ($status === 'decided' && isset($meta['measured']) && is_array($meta['measured'])) {
            $measured = $meta['measured'];
        } elseif ($status === 'running') {
            $measured = self::measure($row);
        }
        $windows = self::windows((int) $row['start'], (int) $row['days'], (int) $row['engine'], (int) $row['metric']);
        return array(
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'hypothesis'  => isset($meta['hypothesis']) ? (string) $meta['hypothesis'] : '',
            'note'        => isset($meta['note']) ? (string) $meta['note'] : '',
            'created'     => (string) wp_date('c', (int) $row['created']),
            'user'        => self::user_name((int) $row['user_id']),
            'start'       => (string) wp_date('c', (int) $row['start']),
            'days'        => (int) $row['days'],
            'windows'     => array('before' => $windows['before'], 'after' => $windows['after']),
            'review'      => (string) $row['review'],
            'engine'      => $engine,
            'metric'      => $metric,
            'direction'   => isset(self::DIRECTIONS[(int) $row['direction']]) ? self::DIRECTIONS[(int) $row['direction']] : 'up',
            'threshold'   => $metric === 'position' ? round((int) $row['threshold'] / 10, 1) : (float) (int) $row['threshold'],
            'change_id'   => (int) $row['change_id'] ? (int) $row['change_id'] : null,
            'pages'       => $pages,
            'goal'        => $goal,
            'status'      => $status,
            'result'      => isset(self::RESULTS[(int) $row['result']]) ? self::RESULTS[(int) $row['result']] : null,
            'decided'     => (int) $row['decided'] ? (string) wp_date('c', (int) $row['decided']) : null,
            'due'         => $status === 'running' && $through !== '' && $through >= (string) $row['review'],
            'through'     => $through !== '' ? $through : null,
            'measurement' => $measured,
        );
    }

    // ------------------------------------------------------------------
    // Measuring.

    /**
     * Measure an experiment: its pages' figures before and after, against
     * the comparison group, with the noise band, the confounders and the
     * suggested result. Running (no figures) until the data reaches the
     * review day. Cached like the reports, keyed by the newest import.
     *
     * @param array<string,mixed> $row Table row.
     * @return array<string,mixed>
     */
    public static function measure(array $row) {
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-rollup.php';
        $engine  = (int) $row['engine'];
        $metric  = (int) $row['metric'];
        $windows = self::windows((int) $row['start'], (int) $row['days'], $engine, $metric);
        $through = self::data_through($engine, $metric);
        $after   = $windows['after'];
        if ($through === '' || $through < $after['to']) {
            $so_far = $through !== '' && $through >= $after['from'] ? (int) (new DateTimeImmutable($after['from']))->diff(new DateTimeImmutable($through))->days + 1 : 0;
            return array(
                'state'   => 'running',
                'through' => $through !== '' ? $through : null,
                'review'  => $windows['review'],
                'days'    => (int) $row['days'],
                'so_far'  => min((int) $row['days'], $so_far),
                'windows' => array('before' => $windows['before'], 'after' => $after),
            );
        }
        $meta = self::meta($row);
        $key  = array(
            'id'        => (int) $row['id'],
            'start'     => (int) $row['start'],
            'days'      => (int) $row['days'],
            'engine'    => $engine,
            'metric'    => $metric,
            'direction' => (int) $row['direction'],
            'threshold' => (int) $row['threshold'],
            'change'    => (int) $row['change_id'],
            'path'      => (int) $row['path_id'],
            'pages'     => isset($meta['pages']) ? $meta['pages'] : array(),
            'goal'      => isset($meta['goal']) ? $meta['goal'] : '',
            'marker'    => isset($meta['marker']) ? (int) $meta['marker'] : 0,
            'imports'   => SEOProStats_Search::version(),
            'landings'  => SEOProStats_Rollup::landings_from(),
        );
        return SEOProStats_Query::cached('experiment', $key, static function () use ($row, $meta, $windows, $through) {
            return self::compute($row, $meta, $windows, $through);
        });
    }

    /**
     * The measurement (cached by measure()).
     *
     * @param array<string,mixed>                 $row     Table row.
     * @param array<string,mixed>                 $meta    Its meta.
     * @param array<string,mixed>                 $windows From windows().
     * @param string                              $through Newest day with data.
     * @return array<string,mixed>
     */
    private static function compute(array $row, array $meta, array $windows, $through) {
        require_once __DIR__ . '/class-seoprostats-changes.php';
        $engine = (int) $row['engine'];
        $metric = (int) $row['metric'];
        $name   = self::METRICS[$metric];
        $search = $metric <= 4;
        $goal   = null;
        if ($metric === 6 && !empty($meta['goal'])) {
            require_once __DIR__ . '/class-seoprostats-goals.php';
            require_once __DIR__ . '/class-seoprostats-conversions.php';
            $goal = SEOProStats_Goals::find('goals', (string) $meta['goal']);
        }
        $mine   = array_flip(self::row_path_ids($row, $meta));
        $before = self::sums($engine, $metric, $windows['before'], $goal);
        $after  = self::sums($engine, $metric, $windows['after'], $goal);

        // The span read for changes: the before window's first day to the after window's last.
        $tz   = wp_timezone();
        $from = (new DateTimeImmutable($windows['before']['from'], $tz))->getTimestamp();
        $to   = (new DateTimeImmutable($windows['after']['to'], $tz))->modify('+1 day')->getTimestamp();
        $span = self::span_changes($row, $meta, $from, $to, $mine);

        // The comparison group: pages with data in both windows, not in the
        // experiment and with no change of their own; most data first.
        $size  = $search ? 'i' : 'visits';
        $sizes = array();
        foreach ($before as $id => $was) {
            if (!$id || isset($mine[$id]) || isset($span['changed'][$id]) || !isset($after[$id]) || $was[$size] <= 0 || $after[$id][$size] <= 0) {
                continue;
            }
            $sizes[$id] = $was[$size];
        }
        arsort($sizes);
        $group = array_slice(array_keys($sizes), 0, self::GROUP);

        $pages_before = self::total($before, array_keys($mine));
        $pages_after  = self::total($after, array_keys($mine));
        $group_before = self::total($before, $group);
        $group_after  = self::total($after, $group);
        $value_before = self::value($metric, $pages_before);
        $value_after  = self::value($metric, $pages_after);
        $change       = self::delta($metric, $value_before, $value_after);
        $group_change = self::delta($metric, self::value($metric, $group_before), self::value($metric, $group_after));
        $compared     = count($group) >= self::MIN_GROUP && $group_change !== null;
        $effect       = $compared ? self::effect($metric, $value_before, $value_after, self::value($metric, $group_before), self::value($metric, $group_after)) : $change;

        // Noise: each page of the group measured as if it had been changed, against the rest.
        $noise = null;
        if ($compared) {
            $effects = array();
            foreach ($group as $id) {
                $rest_before = self::minus($group_before, $before[$id]);
                $rest_after  = self::minus($group_after, $after[$id]);
                $one         = self::effect($metric, self::value($metric, $before[$id]), self::value($metric, $after[$id]), self::value($metric, $rest_before), self::value($metric, $rest_after));
                if ($one !== null) {
                    $effects[] = $one;
                }
            }
            if (count($effects) >= self::MIN_GROUP) {
                sort($effects);
                $noise = array(
                    'low'   => self::round_effect($metric, self::percentile($effects, self::NOISE_LOW)),
                    'high'  => self::round_effect($metric, self::percentile($effects, self::NOISE_HIGH)),
                    'pages' => count($effects),
                );
            }
        }
        $beyond = $noise !== null && $effect !== null ? ($effect < $noise['low'] || $effect > $noise['high']) : null;

        $enough = self::enough($metric, $pages_before, $pages_after);
        $updates = SEOProStats_Changes::updates_between($from, $to);
        $improve = $effect === null ? null : self::improvement($metric, (int) $row['direction'], $effect);
        $limit   = $metric === 4 ? (int) $row['threshold'] / 10 : (int) $row['threshold'] / 100;
        list($suggested, $reasons) = self::suggest($enough['ok'], $effect, $compared, $noise, $beyond, (bool) $updates, $improve, $limit);

        return array(
            'state'        => 'ready',
            'through'      => $through,
            'review'       => $windows['review'],
            'days'         => (int) $row['days'],
            'windows'      => array('before' => $windows['before'], 'after' => $windows['after']),
            'coverage'     => $search ? self::coverage($engine, $windows) : null,
            'pages'        => array(
                'count'  => count($mine),
                'before' => self::figures($metric, $pages_before),
                'after'  => self::figures($metric, $pages_after),
            ),
            'group'        => array(
                'count'  => count($group),
                'before' => self::figures($metric, $group_before),
                'after'  => self::figures($metric, $group_after),
                'change' => self::round_effect($metric, $group_change),
            ),
            'compared'     => $compared,
            'value'        => array('before' => self::round_value($metric, $value_before), 'after' => self::round_value($metric, $value_after)),
            'change'       => self::round_effect($metric, $change),
            'effect'       => self::round_effect($metric, $effect),
            'improvement'  => self::round_effect($metric, $improve),
            'unit'         => $metric === 4 ? 'places' : 'ratio',
            'noise'        => $noise,
            'beyond_noise' => $beyond,
            'enough'       => $enough,
            'confounders'  => array(
                'updates' => array_slice($updates, 0, self::MAX_CONFOUNDERS),
                'site'    => array_slice($span['site'], 0, self::MAX_CONFOUNDERS),
                'pages'   => array_slice($span['pages'], 0, self::MAX_CONFOUNDERS),
                'total'   => count($updates) + count($span['site']) + count($span['pages']),
            ),
            'suggested'    => $suggested,
            'reasons'      => $reasons,
            'summary'      => self::summary($name, $metric, $effect, $compared, count($group), $beyond, $enough['ok']),
        );
    }

    /**
     * The windows of an experiment (site dates, both days included) and
     * its review day. Let S be the start's site day: before is S−days to
     * S−1, after S+1 to S+days (the change day is left out). Bing's pages
     * come by week, on the week's last day: after takes rows dated S+7 to
     * S+days+6, so every after week begins after the change. Visits and
     * conversions are daily for every engine.
     *
     * @param int $start  Start (Unix).
     * @param int $days   Window length.
     * @param int $engine Engine code.
     * @param int $metric Metric code.
     * @return array{before:array{from:string,to:string},after:array{from:string,to:string},review:string}
     */
    public static function windows($start, $days, $engine, $metric) {
        $tz    = wp_timezone();
        $day   = (new DateTimeImmutable('@' . (int) $start))->setTimezone($tz)->setTime(0, 0);
        $days  = max(1, (int) $days);
        $shift = (int) $engine === SEOProStats_Schema::ENGINE_BING && (int) $metric <= 4 ? 6 : 0;
        $after = array(
            'from' => $day->modify('+' . (1 + $shift) . ' days')->format('Y-m-d'),
            'to'   => $day->modify('+' . ($days + $shift) . ' days')->format('Y-m-d'),
        );
        return array(
            'before' => array(
                'from' => $day->modify('-' . $days . ' days')->format('Y-m-d'),
                'to'   => $day->modify('-1 day')->format('Y-m-d'),
            ),
            'after'  => $after,
            'review' => $after['to'],
        );
    }

    /**
     * The newest day the measure has data for: the engine's newest page
     * day for search measures; the last summarised day for visits and
     * conversions.
     *
     * @param int $engine Engine code.
     * @param int $metric Metric code.
     * @return string Y-m-d, or ''.
     */
    private static function data_through($engine, $metric) {
        global $wpdb;
        if ((int) $metric > 4) {
            require_once __DIR__ . '/class-seoprostats-rollup.php';
            return SEOProStats_Rollup::through();
        }
        $key = SEOProStats_Schema::set() . (int) $engine;
        if (!isset(self::$through[$key])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, MAX of the primary key's (engine, day) prefix.
            self::$through[$key] = SEOProStats_Schema::is_current() ? (string) $wpdb->get_var($wpdb->prepare('SELECT MAX(day) FROM %i WHERE engine = %d', SEOProStats_Schema::table('gsc_pages'), (int) $engine)) : '';
        }
        return self::$through[$key];
    }

    /**
     * Each page's sums in a window: search figures (c clicks, i
     * impressions, p position × impressions × 100) from gsc_pages by its
     * primary key; for visits and conversions, visits from search by entry
     * page (the daily search landings) and the goal's conversions.
     *
     * @param int                         $engine Engine code.
     * @param int                         $metric Metric code.
     * @param array{from:string,to:string} $window Days.
     * @param array<string,mixed>|null    $goal   Goal, for conversions.
     * @return array<int,array<string,int>> Path id => sums.
     */
    private static function sums($engine, $metric, array $window, $goal) {
        global $wpdb;
        $zero = array('c' => 0, 'i' => 0, 'p' => 0, 'visits' => 0, 'conversions' => 0);
        $out  = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own tables: gsc_pages by its primary key (engine, day), daily by its search landings.
        if ((int) $metric <= 4) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT path_id AS v, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`PRIMARY`) WHERE engine = %d AND day >= %s AND day <= %s GROUP BY path_id ORDER BY NULL', SEOProStats_Schema::table('gsc_pages'), (int) $engine, $window['from'], $window['to']), ARRAY_A);
            foreach ((array) $rows as $row) {
                $out[(int) $row['v']] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']) + $zero;
            }
            return $out;
        }
        require_once __DIR__ . '/class-seoprostats-rollup.php';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT val AS v, SUM(visits) AS visits FROM %i WHERE dim = %d AND day >= %s AND day <= %s GROUP BY val ORDER BY NULL', SEOProStats_Schema::table('daily'), SEOProStats_Rollup::SEARCH_LANDING, $window['from'], $window['to']), ARRAY_A);
        // phpcs:enable
        foreach ((array) $rows as $row) {
            if ((int) $row['v']) {
                $out[(int) $row['v']] = array('visits' => (int) $row['visits']) + $zero;
            }
        }
        if ((int) $metric === 6 && $goal) {
            $tz    = wp_timezone();
            $range = array(
                'from' => (new DateTimeImmutable($window['from'], $tz))->getTimestamp(),
                'to'   => (new DateTimeImmutable($window['to'], $tz))->modify('+1 day')->getTimestamp(),
            );
            foreach (SEOProStats_Conversions::by_entry($goal, $range, SEOProStats_Query::CHANNELS['organic_search']) as $id => $count) {
                if (isset($out[$id])) {
                    $out[$id]['conversions'] = (int) $count;
                }
            }
        }
        return $out;
    }

    /**
     * The changes of the measured span: pages with a change of their own
     * (left out of the group), site-wide changes and changes on the
     * experiment's own pages after the start (confounders). Pages of other
     * experiments started in the span count as changed.
     *
     * @param array<string,mixed> $row  Table row.
     * @param array<string,mixed> $meta Its meta.
     * @param int                 $from Span start (Unix).
     * @param int                 $to   Span end (Unix, left out).
     * @param array<int,int>      $mine The experiment's path ids (flipped).
     * @return array{changed:array<int,bool>,site:array<int,array<string,mixed>>,pages:array<int,array<string,mixed>>}
     */
    private static function span_changes(array $row, array $meta, $from, $to, array $mine) {
        global $wpdb;
        $own     = array_filter(array((int) $row['change_id'], isset($meta['marker']) ? (int) $meta['marker'] : 0));
        $out     = array('changed' => array(), 'site' => array(), 'pages' => array());
        foreach (SEOProStats_Changes::between($from, $to, self::MAX_CHANGES) as $change) {
            $path_id = (int) $change['path_id'];
            unset($change['path_id']);
            if ($path_id) {
                $out['changed'][$path_id] = true;
                if (isset($mine[$path_id]) && !in_array((int) $change['id'], $own, true) && strtotime((string) $change['t']) >= (int) $row['start']) {
                    $out['pages'][] = $change;
                }
            } elseif (!in_array($change['kind'], array('search_update', 'experiment'), true)) {
                // Search engine updates are listed on their own; other experiments mark their pages below.
                $out['site'][] = $change;
            }
        }
        // Other experiments of several pages mark their pages in their meta.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by key start.
        $others = $wpdb->get_results($wpdb->prepare('SELECT id, meta FROM %i WHERE start >= %d AND start < %d AND path_id = 0 AND id <> %d', SEOProStats_Schema::table('experiments'), (int) $from, (int) $to, (int) $row['id']), ARRAY_A);
        foreach ((array) $others as $other) {
            $pages = self::meta($other);
            if (!empty($pages['pages'])) {
                foreach (SEOProStats_Dict::find(SEOProStats_Schema::DICT_PATH, (array) $pages['pages']) as $id) {
                    $out['changed'][(int) $id] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Days with search data in each window (gsc_totals by its primary key).
     *
     * @param int                 $engine  Engine code.
     * @param array<string,mixed> $windows From windows().
     * @return array{before:int,after:int,days:int}
     */
    private static function coverage($engine, array $windows) {
        global $wpdb;
        $out = array('days' => (int) (new DateTimeImmutable($windows['after']['from']))->diff(new DateTimeImmutable($windows['after']['to']))->days + 1);
        foreach (array('before', 'after') as $side) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key (engine, day).
            $out[$side] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT day) FROM %i WHERE engine = %d AND day >= %s AND day <= %s', SEOProStats_Schema::table('gsc_totals'), (int) $engine, $windows[$side]['from'], $windows[$side]['to']));
        }
        return $out;
    }

    /**
     * Sums of some pages.
     *
     * @param array<int,array<string,int>> $sums From sums().
     * @param int[]                        $ids  Path ids.
     * @return array<string,int>
     */
    private static function total(array $sums, array $ids) {
        $out = array('c' => 0, 'i' => 0, 'p' => 0, 'visits' => 0, 'conversions' => 0);
        foreach ($ids as $id) {
            if (isset($sums[$id])) {
                foreach ($out as $col => $value) {
                    $out[$col] = $value + (int) $sums[$id][$col];
                }
            }
        }
        return $out;
    }

    /**
     * Sums less one page's.
     *
     * @param array<string,int> $all  Sums.
     * @param array<string,int> $page One page's.
     * @return array<string,int>
     */
    private static function minus(array $all, array $page) {
        foreach ($all as $col => $value) {
            $all[$col] = $value - (int) $page[$col];
        }
        return $all;
    }

    /**
     * The measure's value from sums; null where it has no value (no
     * impressions for CTR and position).
     *
     * @param int               $metric Metric code.
     * @param array<string,int> $sums   Sums.
     * @return float|null
     */
    private static function value($metric, array $sums) {
        switch ((int) $metric) {
            case 1:
                return (float) $sums['c'];
            case 2:
                return (float) $sums['i'];
            case 3:
                return $sums['i'] > 0 ? $sums['c'] / $sums['i'] : null;
            case 4:
                return $sums['i'] > 0 ? $sums['p'] / $sums['i'] / 100 : null;
            case 5:
                return (float) $sums['visits'];
            default:
                return (float) $sums['conversions'];
        }
    }

    /**
     * Change from before to after: relative (after ÷ before − 1) for
     * counts and CTR, places (after − before) for position; null when it
     * cannot be worked out.
     *
     * @param int        $metric Metric code.
     * @param float|null $before Before.
     * @param float|null $after  After.
     * @return float|null
     */
    private static function delta($metric, $before, $after) {
        if ($before === null || $after === null) {
            return null;
        }
        if ((int) $metric === 4) {
            return $after - $before;
        }
        return $before > 0 ? $after / $before - 1 : null;
    }

    /**
     * Effect against a group: for counts and CTR, the pages' ratio over
     * the group's, less 1; for position, the pages' change in places less
     * the group's.
     *
     * @param int        $metric        Metric code.
     * @param float|null $before        Pages before.
     * @param float|null $after         Pages after.
     * @param float|null $group_before  Group before.
     * @param float|null $group_after   Group after.
     * @return float|null
     */
    private static function effect($metric, $before, $after, $group_before, $group_after) {
        $mine  = self::delta($metric, $before, $after);
        $group = self::delta($metric, $group_before, $group_after);
        if ($mine === null || $group === null) {
            return null;
        }
        if ((int) $metric === 4) {
            return $mine - $group;
        }
        return $group > -1 ? (1 + $mine) / (1 + $group) - 1 : null;
    }

    /**
     * The effect in the expected direction: positive when it went the way
     * expected. For position, up means a better (lower) number.
     *
     * @param int   $metric    Metric code.
     * @param int   $direction Direction code.
     * @param float $effect    Effect.
     * @return float
     */
    private static function improvement($metric, $direction, $effect) {
        $up = (int) $direction !== 2;
        if ((int) $metric === 4) {
            return $up ? -$effect : $effect;
        }
        return $up ? $effect : -$effect;
    }

    /**
     * Whether the experiment's pages have enough data in both windows.
     *
     * @param int               $metric Metric code.
     * @param array<string,int> $before Pages before.
     * @param array<string,int> $after  Pages after.
     * @return array{ok:bool,unit:string,needed:int,before:int,after:int}
     */
    private static function enough($metric, array $before, array $after) {
        $rules = array(
            1 => array('clicks', 'c', self::MIN_CLICKS),
            2 => array('impressions', 'i', self::MIN_IMPRESSIONS),
            3 => array('impressions', 'i', self::MIN_IMPRESSIONS),
            4 => array('impressions', 'i', self::MIN_IMPRESSIONS),
            5 => array('visits', 'visits', self::MIN_VISITS),
            6 => array('conversions', 'conversions', self::MIN_CONVERSIONS),
        );
        list($unit, $col, $needed) = $rules[(int) $metric];
        return array(
            'ok'     => $before[$col] >= $needed && $after[$col] >= $needed,
            'unit'   => $unit,
            'needed' => $needed,
            'before' => (int) $before[$col],
            'after'  => (int) $after[$col],
        );
    }

    /**
     * The suggested result and why.
     *
     * @param bool                         $enough   Enough data.
     * @param float|null                   $effect   Effect.
     * @param bool                         $compared Measured against a group.
     * @param array<string,mixed>|null     $noise    Noise band.
     * @param bool|null                    $beyond   Beyond noise.
     * @param bool                         $updates  A search engine update rolled out in a window.
     * @param float|null                   $improve  Effect in the expected direction.
     * @param float                        $limit    The threshold (ratio, or places).
     * @return array{0:string,1:string[]}
     */
    private static function suggest($enough, $effect, $compared, $noise, $beyond, $updates, $improve, $limit) {
        $reasons = array();
        if (!$compared) {
            $reasons[] = 'no_group';
        }
        if ($updates) {
            $reasons[] = 'search_update';
        }
        if (!$enough) {
            $reasons[] = 'too_little_data';
            return array('inconclusive', $reasons);
        }
        if ($effect === null || $improve === null) {
            $reasons[] = 'no_measure';
            return array('inconclusive', $reasons);
        }
        if (!$compared && $updates) {
            return array('inconclusive', $reasons);
        }
        if ($noise !== null && $beyond === false) {
            $reasons[] = 'within_noise';
            return array('inconclusive', $reasons);
        }
        if ($improve > 0) {
            $reasons[] = $improve >= $limit ? 'at_threshold' : 'under_threshold';
            return array($improve >= $limit ? 'keep' : 'revise', $reasons);
        }
        if ($improve < 0) {
            $reasons[] = 'opposite';
            return array('undo', $reasons);
        }
        $reasons[] = 'no_change';
        return array('inconclusive', $reasons);
    }

    /**
     * One sentence saying what was found, in the site's language: against
     * comparable pages only when a group supports it, else "associated
     * with" the change.
     *
     * @param string     $name     Metric name.
     * @param int        $metric   Metric code.
     * @param float|null $effect   Effect.
     * @param bool       $compared Measured against a group.
     * @param int        $pages    Pages in the group.
     * @param bool|null  $beyond   Beyond noise.
     * @param bool       $enough   Enough data.
     * @return string
     */
    private static function summary($name, $metric, $effect, $compared, $pages, $beyond, $enough) {
        $labels = array(
            'clicks'      => __('Clicks', 'seoprostats'),
            'impressions' => __('Impressions', 'seoprostats'),
            'ctr'         => __('CTR', 'seoprostats'),
            'position'    => __('Position', 'seoprostats'),
            'visits'      => __('Visits from search', 'seoprostats'),
            'conversions' => __('Conversions', 'seoprostats'),
        );
        $label = $labels[$name];
        if ($effect === null) {
            /* translators: %s: the measure, such as Clicks */
            return sprintf(__('%s could not be compared: there is no data before the change.', 'seoprostats'), $label);
        }
        $size = (int) $metric === 4 ? self::places_text($effect) : sprintf('%+.1f%%', $effect * 100);
        $note = $enough ? '' : ' ' . __('There is too little data to judge.', 'seoprostats');
        if (!$compared) {
            /* translators: 1: the measure, 2: the change, such as +12.0% */
            return sprintf(__('%1$s changed by %2$s after the change; this is associated with it, as there are too few unchanged pages to compare with.', 'seoprostats'), $label, $size) . $note;
        }
        if ($beyond) {
            /* translators: 1: the measure, 2: the change, such as +12.0%, 3: number of pages */
            return sprintf(_n('%1$s changed by %2$s against %3$d comparable unchanged page, outside their usual spread.', '%1$s changed by %2$s against %3$d comparable unchanged pages, outside their usual spread.', $pages, 'seoprostats'), $label, $size, $pages) . $note;
        }
        /* translators: 1: the measure, 2: the change, such as +12.0%, 3: number of pages */
        return sprintf(_n('%1$s changed by %2$s against %3$d comparable unchanged page, within their usual spread: no clear effect.', '%1$s changed by %2$s against %3$d comparable unchanged pages, within their usual spread: no clear effect.', $pages, 'seoprostats'), $label, $size, $pages) . $note;
    }

    /**
     * A position effect in words, as places up (better) or down (worse);
     * the API's number is the change in position, where lower is better.
     *
     * @param float $effect Change in places (negative: better).
     * @return string
     */
    public static function places_text($effect) {
        $places = number_format_i18n(abs((float) $effect), 1);
        if ((float) $effect < 0) {
            /* translators: %s: places, such as 1.5 */
            return sprintf(__('%s places up', 'seoprostats'), $places);
        }
        if ((float) $effect > 0) {
            /* translators: %s: places, such as 1.5 */
            return sprintf(__('%s places down', 'seoprostats'), $places);
        }
        return __('no places', 'seoprostats');
    }

    /**
     * The figures shown for some pages in a window: counts beside rates.
     *
     * @param int               $metric Metric code.
     * @param array<string,int> $sums   Sums.
     * @return array<string,int|float|null>
     */
    private static function figures($metric, array $sums) {
        if ((int) $metric <= 4) {
            return array(
                'clicks'      => (int) $sums['c'],
                'impressions' => (int) $sums['i'],
                'ctr'         => $sums['i'] ? round($sums['c'] / $sums['i'], 4) : null,
                'position'    => $sums['i'] ? round($sums['p'] / $sums['i'] / 100, 1) : null,
            );
        }
        $out = array('visits' => (int) $sums['visits']);
        if ((int) $metric === 6) {
            $out['conversions'] = (int) $sums['conversions'];
            $out['rate']        = $sums['visits'] ? round($sums['conversions'] / $sums['visits'], 4) : null;
        }
        return $out;
    }

    /**
     * The value at the precision shown.
     *
     * @param int        $metric Metric code.
     * @param float|null $value  Value.
     * @return float|null
     */
    private static function round_value($metric, $value) {
        if ($value === null) {
            return null;
        }
        return round($value, (int) $metric === 3 ? 4 : 1);
    }

    /**
     * An effect at the precision shown: places to 0.1, ratios to 0.001.
     *
     * @param int        $metric Metric code.
     * @param float|null $effect Effect.
     * @return float|null
     */
    private static function round_effect($metric, $effect) {
        if ($effect === null) {
            return null;
        }
        return round($effect, (int) $metric === 4 ? 1 : 3);
    }

    /**
     * A percentile of sorted numbers (linear between neighbours).
     *
     * @param float[] $sorted Numbers, smallest first.
     * @param float   $share  0 to 1.
     * @return float
     */
    private static function percentile(array $sorted, $share) {
        $at   = ($sorted ? count($sorted) - 1 : 0) * $share;
        $low  = (int) floor($at);
        $high = (int) ceil($at);
        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($at - $low);
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * The checked fields of a new experiment, as stored.
     *
     * @param array<string,mixed> $input Input.
     * @return array{days:int,engine:int,metric:int,direction:int,threshold:int,goal:string}|WP_Error
     */
    private static function fields(array $input) {
        $days = isset($input['days']) && $input['days'] !== '' ? (int) $input['days'] : self::DAYS;
        if (!in_array($days, self::WINDOWS, true)) {
            /* translators: %s: list of lengths */
            return self::error('seoprostats_experiment_days', sprintf(__('Each window is 7, 14, 28, 56 or 84 days (%s).', 'seoprostats'), implode(', ', self::WINDOWS)));
        }
        $engine = isset($input['engine']) && (string) $input['engine'] === 'bing' ? SEOProStats_Schema::ENGINE_BING : SEOProStats_Schema::ENGINE_GOOGLE;
        $metric = array_search(isset($input['metric']) && $input['metric'] !== '' ? (string) $input['metric'] : 'clicks', self::METRICS, true);
        if ($metric === false) {
            /* translators: %s: list of measures */
            return self::error('seoprostats_experiment_metric', sprintf(__('The measure is one of: %s.', 'seoprostats'), implode(', ', self::METRICS)));
        }
        $direction = array_search(isset($input['direction']) && $input['direction'] !== '' ? (string) $input['direction'] : 'up', self::DIRECTIONS, true);
        if ($direction === false) {
            return self::error('seoprostats_experiment_direction', __('The direction is up or down (for position, up means a better place).', 'seoprostats'));
        }
        $given = isset($input['threshold']) && $input['threshold'] !== '' ? (float) $input['threshold'] : null;
        if ($given !== null && $given < 0) {
            return self::error('seoprostats_experiment_threshold', __('The threshold is a percent (or places, for position) of 0 or more.', 'seoprostats'));
        }
        // Stored as percent, or tenths of a place for position.
        $threshold = $given === null ? self::THRESHOLD : (int) round($metric === 4 ? $given * 10 : $given);
        $goal      = isset($input['goal']) ? trim((string) $input['goal']) : '';
        if ($metric === 6) {
            require_once __DIR__ . '/class-seoprostats-goals.php';
            if ($goal === '' || !SEOProStats_Goals::find('goals', $goal)) {
                return self::error('seoprostats_experiment_goal', __('Conversions need a goal: give its id (wp seoprostats goals list shows them).', 'seoprostats'));
            }
        } else {
            $goal = '';
        }
        return array(
            'days'      => $days,
            'engine'    => $engine,
            'metric'    => (int) $metric,
            'direction' => (int) $direction,
            'threshold' => min(65535, $threshold),
            'goal'      => $goal,
        );
    }

    /**
     * Page paths from a list or a comma-separated text, cleaned, each once.
     *
     * @param mixed $pages Paths or addresses.
     * @return string[]
     */
    private static function paths($pages) {
        require_once __DIR__ . '/class-seoprostats-changes.php';
        $list = is_array($pages) ? $pages : explode(',', (string) $pages);
        $out  = array();
        foreach ($list as $page) {
            $page = trim(is_scalar($page) ? (string) $page : '');
            if ($page !== '') {
                $out[] = SEOProStats_Changes::path($page);
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    /**
     * An experiment's page paths.
     *
     * @param array<string,mixed> $row  Table row.
     * @param array<string,mixed> $meta Its meta.
     * @return string[]
     */
    private static function row_pages(array $row, array $meta) {
        if ((int) $row['path_id']) {
            $paths = SEOProStats_Dict::values(array((int) $row['path_id']));
            return isset($paths[(int) $row['path_id']]) ? array($paths[(int) $row['path_id']]) : array();
        }
        return isset($meta['pages']) ? array_values(array_map('strval', (array) $meta['pages'])) : array();
    }

    /**
     * An experiment's path ids.
     *
     * @param array<string,mixed> $row  Table row.
     * @param array<string,mixed> $meta Its meta.
     * @return int[]
     */
    private static function row_path_ids(array $row, array $meta) {
        if ((int) $row['path_id']) {
            return array((int) $row['path_id']);
        }
        return isset($meta['pages']) ? array_map('intval', SEOProStats_Dict::find(SEOProStats_Schema::DICT_PATH, (array) $meta['pages'])) : array();
    }

    /**
     * A row's meta.
     *
     * @param array<string,mixed> $row Table row.
     * @return array<string,mixed>
     */
    private static function meta(array $row) {
        $meta = isset($row['meta']) && $row['meta'] !== '' ? json_decode((string) $row['meta'], true) : array();
        return is_array($meta) ? $meta : array();
    }

    /**
     * Save a row's meta.
     *
     * @param int                 $id   Experiment id.
     * @param array<string,mixed> $meta Meta.
     */
    private static function save_meta($id, array $meta) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $wpdb->update(SEOProStats_Schema::table('experiments'), array('meta' => self::json($meta)), array('id' => (int) $id), array('%s'), array('%d'));
    }

    /**
     * JSON for the meta column.
     *
     * @param array<string,mixed> $meta Meta.
     * @return string
     */
    private static function json(array $meta) {
        return $meta ? (string) wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    }

    /**
     * One line of text, cut to the name column (190 bytes).
     *
     * @param mixed $text Text.
     * @return string
     */
    private static function text($text) {
        $text = trim(sanitize_text_field(is_scalar($text) ? (string) $text : ''));
        return function_exists('mb_strcut') ? mb_strcut($text, 0, 190, 'UTF-8') : substr($text, 0, 190);
    }

    /**
     * Longer text (a hypothesis or note), cut to 2,000 bytes.
     *
     * @param mixed $text Text.
     * @return string
     */
    private static function long_text($text) {
        $text = trim(sanitize_textarea_field(is_scalar($text) ? (string) $text : ''));
        return function_exists('mb_strcut') ? mb_strcut($text, 0, 2000, 'UTF-8') : substr($text, 0, 2000);
    }

    /**
     * Who recorded it, for people who may list users (and WP-CLI).
     *
     * @param int $user_id User.
     * @return string|null
     */
    private static function user_name($user_id) {
        if (!$user_id || !((defined('WP_CLI') && WP_CLI) || current_user_can('list_users'))) {
            return null;
        }
        $user = get_userdata($user_id);
        return $user ? (string) $user->display_name : null;
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

    /**
     * The error for an unknown experiment.
     *
     * @param int $id Experiment id.
     * @return WP_Error
     */
    private static function not_found($id) {
        /* translators: %d: experiment id */
        return self::error('seoprostats_not_found', sprintf(__('There is no experiment %d.', 'seoprostats'), $id), 404);
    }
}
