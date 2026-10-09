<?php
/**
 * Bounded CrUX collection and indexed field-data reports (not lab tests).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Vitals {
    const FORMS = array('PHONE', 'DESKTOP');
    const THRESHOLDS = array('lcp' => array(2500, 4000), 'inp' => array(200, 500), 'cls' => array(0.1, 0.25), 'fcp' => array(1800, 3000), 'ttfb' => array(800, 1800));

    /**
     * Missing values never count as good.
     *
     * @param string $metric Metric.
     * @param float|null $p75 Value.
     * @return string
     */
    public static function status($metric, $p75) {
        if ($p75 === null || !isset(self::THRESHOLDS[$metric])) {
            return 'unavailable';
        }
        return $p75 <= self::THRESHOLDS[$metric][0] ? 'good' : ($p75 <= self::THRESHOLDS[$metric][1] ? 'needs_improvement' : 'poor');
    }

    /**
     * Pages from both rankings: search clicks and visits, without remote calls.
     *
     * @param int $limit Page cap.
     * @return array<int,array<string,mixed>>
     */
    public static function pages($limit = 100) {
        global $wpdb;
        $limit = max(0, min(1000, (int) $limit));
        if (!$limit) {
            return array();
        }
        $from = wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS);
        $to = wp_date('Y-m-d', time() - DAY_IN_SECONDS);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- indexed 30-day summaries; rank in PHP like Content, avoiding SQL filesorts.
        $clicks = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, SUM(clicks) AS clicks FROM %i FORCE INDEX (PRIMARY) WHERE engine IN (1,2) AND day >= %s AND day <= %s GROUP BY path_id ORDER BY NULL', SEOProStats_Schema::table('gsc_pages'), $from, $to), ARRAY_A);
        $traffic = (array) $wpdb->get_results($wpdb->prepare('SELECT val AS path_id, SUM(visits) AS visits FROM %i FORCE INDEX (PRIMARY) WHERE day >= %s AND day <= %s AND dim = 15 GROUP BY val ORDER BY NULL', SEOProStats_Schema::table('daily'), $from, $to), ARRAY_A);
        // phpcs:enable
        usort($clicks, static function ($a, $b) { return (int) $b['clicks'] <=> (int) $a['clicks']; });
        usort($traffic, static function ($a, $b) { return (int) $b['visits'] <=> (int) $a['visits']; });
        $clicks = array_slice($clicks, 0, $limit);
        $traffic = array_slice($traffic, 0, $limit);
        $texts = SEOProStats_Query::texts(array_map('intval', array_merge(array_column($clicks, 'path_id'), array_column($traffic, 'path_id'))));
        $search = array();
        foreach ($clicks as $row) {
            $row['value'] = (string) ($texts[(int) $row['path_id']] ?? '');
            $search[] = $row;
        }
        $visits = array();
        foreach ((array) $traffic as $row) {
            $row['value'] = (string) ($texts[(int) $row['path_id']] ?? '');
            $visits[] = $row;
        }
        $by_path = array();
        foreach ($search as $row) {
            $path = (string) $row['value'];
            $by_path[$path] = array('path_id' => (int) $row['path_id'], 'path' => $path, 'clicks' => (int) $row['clicks'], 'visits' => 0);
        }
        foreach ($visits as $row) {
            $path = (string) $row['value'];
            if (!isset($by_path[$path])) {
                $by_path[$path] = array('path_id' => (int) $row['path_id'], 'path' => $path, 'clicks' => 0, 'visits' => 0);
            }
            $by_path[$path]['visits'] = (int) $row['visits'];
        }
        $paths = array();
        // Interleave the rankings so visits-only pages are not crowded out.
        for ($i = 0; $i < $limit; ++$i) {
            foreach (array($search, $visits) as $list) {
                if (isset($list[$i])) {
                    $paths[(string) $list[$i]['value']] = true;
                }
            }
        }
        $out = array();
        foreach (array_slice(array_keys($paths), 0, $limit) as $path) {
            if ($by_path[$path]['path_id'] > 0 && self::local_url($path) !== '') {
                $out[] = $by_path[$path];
            }
        }
        return $out;
    }

    /**
     * Only a local path can be submitted to PSI.
     *
     * @param string $path Path.
     * @return string Empty for invalid input.
     */
    public static function local_url($path) {
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || preg_match('/[\\\\\x00-\x20?#]/', $path)) {
            return '';
        }
        require_once __DIR__ . '/sources/class-seoprostats-source-crux.php';
        return SEOProStats_Source_Crux::origin() . $path;
    }

    /**
     * Run under Search Import's lock. Successful and 404 attempts are persisted.
     *
     * @param int $budget Seconds.
     * @return array{days:int,rows:int,import:int,done:bool}|WP_Error
     */
    public static function run($budget = 20) {
        $conn = SEOProStats_Connections::get('crux');
        $out = array('days' => 0, 'rows' => 0, 'import' => 0, 'done' => true);
        if (!$conn || SEOProStats_Schema::set() !== 'live') {
            return $out;
        }
        if (!SEOProStats_Schema::is_current()) {
            return new WP_Error('seoprostats_vitals_schema', __('Update the statistics tables before importing field data.', 'seoprostats'));
        }
        $credentials = SEOProStats_Connections::credentials('crux');
        if (is_wp_error($credentials)) {
            return $credentials;
        }
        require_once __DIR__ . '/sources/class-seoprostats-source-crux.php';
        $key = (string) ($credentials['key'] ?? '');
        if ($key === '') {
            return new WP_Error('seoprostats_crux_key', __('Connect Chrome UX Report again.', 'seoprostats'));
        }
        $start = microtime(true);
        $budget = max(15, min(60, (int) $budget));
        $checked = (array) ($conn['state']['vitals_checked'] ?? array());
        $history = (array) ($conn['state']['vitals_history'] ?? array());
        $available = (array) ($conn['state']['vitals_available'] ?? array());
        $targets = array_merge(array(array('path_id' => 0, 'path' => '')), self::pages((int) ($conn['settings']['pages'] ?? 100)));
        foreach ($targets as $target) {
            foreach (self::FORMS as $form) {
                $id = (int) $target['path_id'];
                $slot = $id . ':' . $form;
                $due = $id === 0 ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
                $is_history = $id === 0 && empty($history[$form]);
                if (!$is_history && (int) ($checked[$slot] ?? 0) > time() - $due) {
                    continue;
                }
                if (microtime(true) - $start >= $budget - 10) {
                    $out['done'] = false;
                    break 2;
                }
                $url = $id === 0 ? SEOProStats_Source_Crux::origin() : self::local_url((string) $target['path']);
                $record = SEOProStats_Source_Crux::query($key, $url, $form, $id === 0, $is_history);
                if (is_wp_error($record)) {
                    SEOProStats_Connections::update_state('crux', array('error' => $record->get_error_message(), 'error_at' => time()));
                    return $record;
                }
                $saved = self::store($record, $id, $form, (string) $target['path'], $is_history);
                if ($saved === false) {
                    return new WP_Error('seoprostats_vitals_store', __('Page experience data could not be saved.', 'seoprostats'));
                }
                $out['rows'] += $saved;
                if ($is_history) {
                    $history[$form] = true;
                    $out['done'] = false;
                } else {
                    $checked[$slot] = time();
                    $available[$slot] = array_keys(is_array($record['metrics'] ?? null) ? $record['metrics'] : array());
                }
                $checked = array_filter($checked, static function ($at) { return (int) $at > time() - 2 * WEEK_IN_SECONDS; });
                $available = array_intersect_key($available, $checked);
                SEOProStats_Connections::update_state('crux', array('vitals_checked' => $checked, 'vitals_history' => $history, 'vitals_available' => $available, 'last_run' => time(), 'error' => null, 'error_at' => null));
                usleep(600000); // At most 100 requests/minute from this reader.
            }
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded retention by primary day range.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE day < %s LIMIT 1000', SEOProStats_Schema::table('vitals'), gmdate('Y-m-d', time() - 400 * DAY_IN_SECONDS)));
        return $out;
    }

    /**
     * Store current/history data; missing and NaN values stay absent.
     *
     * @param array<string,mixed> $record Record.
     * @param int $id Path ID, 0 origin.
     * @param string $form Form factor.
     * @param string $path Path.
     * @param bool $history History.
     * @return int|false Rows stored, false on database error.
     */
    public static function store(array $record, $id, $form, $path = '', $history = false) {
        global $wpdb;
        require_once __DIR__ . '/sources/class-seoprostats-source-crux.php';
        $table = SEOProStats_Schema::table('vitals');
        $periods = $history ? ($record['collectionPeriods'] ?? array()) : array($record['collectionPeriod'] ?? array());
        $count = 0;
        foreach ($periods as $index => $period) {
            $last = $period['lastDate'] ?? array();
            if (!isset($last['year'], $last['month'], $last['day']) || !checkdate((int) $last['month'], (int) $last['day'], (int) $last['year'])) {
                continue;
            }
            $day = sprintf('%04d-%02d-%02d', $last['year'], $last['month'], $last['day']);
            foreach (SEOProStats_Source_Crux::METRICS as $metric => $name) {
                $data = $record['metrics'][$name] ?? array();
                $p75 = $history ? ($data['percentilesTimeseries']['p75s'][$index] ?? null) : ($data['percentiles']['p75'] ?? null);
                if (!is_numeric($p75) || !is_finite((float) $p75) || (float) $p75 < 0) {
                    continue;
                }
                $bins = $history ? ($data['histogramTimeseries'] ?? array()) : ($data['histogram'] ?? array());
                $shares = array();
                foreach (array(0, 1, 2) as $bin) {
                    $value = $history ? ($bins[$bin]['densities'][$index] ?? null) : ($bins[$bin]['density'] ?? null);
                    if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 1) {
                        $shares[] = (float) $value;
                    }
                }
                if (count($shares) !== 3) {
                    continue;
                }
                // phpcs:disable WordPress.DB.DirectDatabaseQuery -- path_day lookup and primary-key replace, own table.
                $before = !$history ? $wpdb->get_var($wpdb->prepare('SELECT p75 FROM %i WHERE path_id = %d AND form_factor = %s AND metric = %s ORDER BY day DESC LIMIT 1', $table, $id, $form, $metric)) : null;
                $ok = $wpdb->replace($table, array('day' => $day, 'path_id' => $id, 'form_factor' => $form, 'metric' => $metric, 'p75' => (float) $p75, 'good' => $shares[0], 'ni' => $shares[1], 'poor' => $shares[2]));
                // phpcs:enable
                if ($ok === false) {
                    return false;
                }
                ++$count;
                $old = self::status($metric, $before === null ? null : (float) $before);
                $new = self::status($metric, (float) $p75);
                if (!$history && $old !== 'unavailable' && $old !== $new && in_array($metric, array('lcp', 'inp', 'cls'), true) && SEOProStats_Schema::set() === 'live') {
                    $identity = array_search($metric, array_keys(self::THRESHOLDS), true) * 2 + ($form === 'PHONE' ? 1 : 2);
                    SEOProStats_Changes::record(52, array('path' => $path, 'object_type' => 'vitals', 'object_id' => $identity, 'old' => $old, 'new' => $new, 'meta' => array('name' => $path === '' ? __('Origin page experience', 'seoprostats') : $path, 'metric' => $metric, 'form_factor' => $form, 'day' => $day), 'user_id' => 0));
                }
            }
        }
        return $count;
    }

    /**
     * Per-metric indexed history for one path.
     *
     * @param int $id Path ID.
     * @param bool $latest Only the newest sample per metric.
     * @return array<int,array<string,mixed>>
     */
    public static function series($id, $latest = false) {
        global $wpdb;
        $out = array();
        foreach (self::FORMS as $form) {
            foreach (array_keys(self::THRESHOLDS) as $metric) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- path_day equality prefix followed by ordered day range.
                $rows = $wpdb->get_results($wpdb->prepare('SELECT day, form_factor, metric, p75, good, ni, poor FROM %i WHERE path_id = %d AND form_factor = %s AND metric = %s AND day >= %s ORDER BY day DESC LIMIT %d', SEOProStats_Schema::table('vitals'), $id, $form, $metric, gmdate('Y-m-d', time() - 400 * DAY_IN_SECONDS), $latest ? 1 : 400), ARRAY_A);
                foreach ((array) $rows as $row) {
                    foreach (array('p75', 'good', 'ni', 'poor') as $field) {
                        $row[$field] = (float) $row[$field];
                    }
                    $row['status'] = self::status($metric, $row['p75']);
                    $row['stale'] = (string) $row['day'] < gmdate('Y-m-d', time() - 14 * DAY_IN_SECONDS);
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Latest samples from a series.
     *
     * @param array<int,array<string,mixed>> $series Series.
     * @return array<int,array<string,mixed>>
     */
    public static function latest(array $series) {
        $out = array();
        foreach ($series as $row) {
            $key = $row['form_factor'] . ':' . $row['metric'];
            if (!isset($out[$key])) {
                $out[$key] = $row;
            }
        }
        return array_values($out);
    }

    /**
     * Local report: origin series and monitored pages, failures by clicks.
     *
     * @param string $page Optional local path.
     * @return array<string,mixed>
     */
    public static function report($page = '') {
        require_once __DIR__ . '/class-seoprostats-connections.php';
        $conn = SEOProStats_Connections::get('crux');
        $key = array('page' => $page, 'connection' => $conn, 'day' => gmdate('Y-m-d'));
        return SEOProStats_Query::cached('vitals', $key, static function () use ($page, $conn) { return self::build($page, $conn); });
    }

    /**
     * Build the shared, credential-free local report.
     *
     * @param string $page Local path.
     * @param array<string,mixed>|null $conn Connection without credentials.
     * @return array<string,mixed>
     */
    private static function build($page, $conn) {
        global $wpdb;
        $demo = SEOProStats_Schema::set() === 'demo';
        $pages = self::pages($demo ? 100 : (int) ($conn['settings']['pages'] ?? 100));
        if ($page !== '' && self::local_url($page) !== '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dictionary unique kind/hash key; reports never create entries.
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE kind = %d AND hash = UNHEX(%s)', SEOProStats_Schema::table('dict'), SEOProStats_Schema::DICT_PATH, SEOProStats_Dict::hash(SEOProStats_Dict::clean($page))));
            // phpcs:disable WordPress.DB.DirectDatabaseQuery -- per-path range keys, at most 30 summary days per engine.
            $visits = $id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(visits) FROM %i WHERE dim = 15 AND val = %d AND day >= %s AND day <= %s', SEOProStats_Schema::table('daily'), $id, wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), wp_date('Y-m-d', time() - DAY_IN_SECONDS))) : 0;
            $clicks = $id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(clicks) FROM %i WHERE path_id = %d AND day >= %s AND day <= %s', SEOProStats_Schema::table('gsc_pages'), $id, wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS), wp_date('Y-m-d', time() - DAY_IN_SECONDS))) : 0;
            // phpcs:enable
            $pages = array(array('path_id' => $id, 'path' => $page, 'clicks' => $clicks, 'visits' => $visits));
        }
        $rows = array();
        foreach ($pages as $item) {
            $samples = $item['path_id'] > 0 ? self::series((int) $item['path_id'], true) : array();
            if (!$demo) {
                require_once __DIR__ . '/sources/class-seoprostats-source-crux.php';
                $availability = (array) ($conn['state']['vitals_available'] ?? array());
                $samples = array_values(array_filter($samples, static function ($sample) use ($availability, $item) {
                    $slot = $item['path_id'] . ':' . $sample['form_factor'];
                    return !isset($availability[$slot]) || in_array(SEOProStats_Source_Crux::METRICS[$sample['metric']], (array) $availability[$slot], true);
                }));
            }
            $failing = false;
            foreach ($samples as $sample) {
                $failing = $failing || (!$sample['stale'] && in_array($sample['metric'], array('lcp', 'inp', 'cls'), true) && $sample['status'] === 'poor');
            }
            $rows[] = $item + array('samples' => $samples, 'available' => (bool) $samples, 'failing' => $failing);
        }
        usort($rows, static function ($a, $b) { return array($b['failing'], $b['clicks'], $b['visits']) <=> array($a['failing'], $a['clicks'], $a['visits']); });
        $series = self::series(0);
        return array('connected' => $conn !== null || SEOProStats_Schema::set() === 'demo', 'source' => 'crux', 'window_days' => 28, 'traffic_days' => 30, 'origin' => self::latest($series), 'series' => $series, 'rows' => $rows);
    }
}
