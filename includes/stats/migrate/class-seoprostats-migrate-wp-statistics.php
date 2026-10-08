<?php
/**
 * WP Statistics (wp-statistics), read from a real install of version
 * 14.16.15, the current one when this was written, and from its schema
 * migrations for the layouts still found on sites.
 *
 * What it keeps, in this site's tables (prefix statistics_): visitor, a
 * row per visitor and day (last_counter, the site's time zone; hits, that
 * visitor's pageviews that day; referred, the referring host or address;
 * agent, platform and device, the browser, system and device type; location,
 * the country code), pages, a row per address and day (date, uri, count),
 * visitor_relationships, which pages each visitor row viewed, and
 * summary_totals, the day's visitors and views, which stay when its "Purge
 * Old Data Daily" deletes older visitor and page rows. Its visitor rows
 * also hold the IP address (raw or hashed), the user agent and the user;
 * those columns are never read, only counted rows.
 *
 * Layouts: since 14.12.6 a visitor row has its first and last page
 * (first_page, last_page, read as entry and exit); its source_channel is
 * not read (channels are worked out as the collector's); columns a layout
 * lacks, such as device, are skipped. Before 14.15 the day's views were
 * in a visit table (last_counter, visit), read for days without visitor
 * rows. A day is read from its visitor rows when it has them, else from
 * summary_totals, else from visit, so purged days still bring their
 * totals. Its historical table holds totals that are not per day and is
 * not read.
 *
 * It keeps no visits: each visitor row is one visitor's day, imported as
 * one visit (a bounce when it has one pageview). Days with totals only
 * import visitors and pageviews, no visits.
 *
 * Its settings are one option, wp_statistics. When it is deactivated it
 * keeps everything. When it is deleted it removes its options, tables,
 * transients, cron hooks and its user and post meta only when "Delete All
 * Data on Plugin Deletion" (Settings → Advanced Options → Danger Zone) is
 * on, which is off unless changed; its upload folder (GeoIP database) stays
 * either way.
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

// Paths, sources and channels are worked out as the collector's are.
require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-channels.php';
require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-processor.php';

final class SEOProStats_Migrate_WP_Statistics extends SEOProStats_Migrate_Source {

    const KEY   = 'wp-statistics';
    const NAME  = 'WP Statistics';
    const SLUGS = array('wp-statistics');

    /** Its settings option. */
    const SETTINGS_OPTION = 'wp_statistics';

    /** Its tables (after statistics_) in every layout, with its add-ons'. */
    const TABLES = array('visitor', 'visitor_relationships', 'pages', 'historical', 'summary_totals', 'exclusions', 'events', 'visit', 'useronline', 'search', 'ar_outbox', 'campaigns', 'goals');

    /** Its "Purge Old Data Daily" default, in days. */
    const PURGE_DEFAULT = 180;

    /** @var array<string,array<string,array<string,int>>> Day totals by range, for the request. */
    private $site = array();

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $version = $this->installed_version();
        $out     = array('version' => $version !== '' ? $version : (string) get_option('wp_statistics_plugin_version', ''), 'from' => '', 'to' => '');
        $spans   = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- another plugin's tables: MIN and MAX on their date indexes; the totals tables have one row a day.
        if ($this->readable()) {
            $spans[] = $wpdb->get_row($wpdb->prepare('SELECT MIN(last_counter) AS a, MAX(last_counter) AS b FROM %i', self::table('visitor')), ARRAY_A);
        }
        if (self::has_columns('summary_totals', array('date', 'visitors', 'views'))) {
            $spans[] = $wpdb->get_row($wpdb->prepare('SELECT MIN(date) AS a, MAX(date) AS b FROM %i WHERE views > 0', self::table('summary_totals')), ARRAY_A);
        }
        if (self::has_columns('visit', array('last_counter', 'visit'))) {
            $spans[] = $wpdb->get_row($wpdb->prepare('SELECT MIN(last_counter) AS a, MAX(last_counter) AS b FROM %i WHERE visit > 0', self::table('visit')), ARRAY_A);
        }
        // phpcs:enable
        foreach ($spans as $span) {
            $from = isset($span['a']) ? (string) $span['a'] : '';
            $to   = isset($span['b']) ? (string) $span['b'] : '';
            if ($from !== '' && $from > '1000-01-01') {
                $out['from'] = $out['from'] === '' ? $from : min($out['from'], $from);
                $out['to']   = max($out['to'], $to);
            }
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        $from = self::day_of($start);
        $to   = self::day_of($end);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- another plugin's tables, one entry of their date indexes.
        if ($this->readable() && $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE last_counter >= %s AND last_counter < %s LIMIT 1', self::table('visitor'), $from, $to))) {
            return true;
        }
        if (self::has_columns('summary_totals', array('date', 'visitors', 'views')) && $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE date >= %s AND date < %s AND views > 0 LIMIT 1', self::table('summary_totals'), $from, $to))) {
            return true;
        }
        return self::has_columns('visit', array('last_counter', 'visit')) && (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE last_counter >= %s AND last_counter < %s AND visit > 0 LIMIT 1', self::table('visit'), $from, $to));
        // phpcs:enable
    }

    /**
     * {@inheritDoc}
     */
    public function totals($from, $to) {
        $out = array('pageviews' => 0, 'visits' => 0, 'visitors' => 0);
        if ($from === '' || $to === '') {
            return $out;
        }
        // Its own counts: visitor rows and their hits, else its saved totals.
        foreach ($this->site_days($from, $to) as $day) {
            foreach (array_keys($out) as $key) {
                $out[$key] += $day[$key];
            }
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function days($from, $to) {
        $out  = array();
        $site = $this->site_days($from, $to);
        foreach ($this->day_list($from, $to) as $day) {
            $out[$day] = isset($site[$day]) ? $this->day($day, $site[$day]) : array();
        }
        return $out;
    }

    /**
     * Each day's totals in a range: from its visitor rows (detail: 1),
     * else its saved day totals (summary_totals, or visit before 14.15).
     *
     * @param string $from First day.
     * @param string $to   Last day.
     * @return array<string,array<string,int>> Day => visitors, visits, pageviews, bounces, detail.
     */
    private function site_days($from, $to) {
        global $wpdb;
        $key = $from . ':' . $to;
        if (isset($this->site[$key])) {
            return $this->site[$key];
        }
        $out = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- another plugin's tables by their date indexes; only counts are read.
        if (self::has_columns('visit', array('last_counter', 'visit'))) {
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT last_counter AS d, visit AS pv FROM %i WHERE last_counter >= %s AND last_counter <= %s AND visit > 0', self::table('visit'), $from, $to), ARRAY_A) as $row) {
                $out[(string) $row['d']] = array('visitors' => 0, 'visits' => 0, 'pageviews' => (int) $row['pv'], 'bounces' => 0, 'detail' => 0);
            }
        }
        if (self::has_columns('summary_totals', array('date', 'visitors', 'views'))) {
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT date AS d, visitors AS u, views AS pv FROM %i WHERE date >= %s AND date <= %s AND views > 0', self::table('summary_totals'), $from, $to), ARRAY_A) as $row) {
                $out[(string) $row['d']] = array('visitors' => (int) $row['u'], 'visits' => 0, 'pageviews' => (int) $row['pv'], 'bounces' => 0, 'detail' => 0);
            }
        }
        if ($this->readable()) {
            $found = $wpdb->get_results($wpdb->prepare('SELECT last_counter AS d, COUNT(*) AS n, COALESCE(SUM(hits), 0) AS pv, COALESCE(SUM(COALESCE(hits, 0) <= 1), 0) AS b FROM %i WHERE last_counter >= %s AND last_counter <= %s GROUP BY last_counter', self::table('visitor'), $from, $to), ARRAY_A);
            foreach ((array) $found as $row) {
                if ((int) $row['pv'] > 0) {
                    $out[(string) $row['d']] = array('visitors' => (int) $row['n'], 'visits' => (int) $row['n'], 'pageviews' => (int) $row['pv'], 'bounces' => (int) $row['b'], 'detail' => 1);
                }
            }
        }
        // phpcs:enable
        ksort($out);
        $this->site[$key] = $out;
        return $out;
    }

    /**
     * One day's rows. With visitor rows: the site, pages, entry and exit
     * pages, browsers, systems, devices, countries, sources, channels,
     * campaign tags and search landings; else the site's totals and the
     * pages it still has.
     *
     * @param string             $day  Y-m-d.
     * @param array<string,int>  $site From site_days().
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function day($day, array $site) {
        if ($site['pageviews'] <= 0) {
            return array();
        }
        $metrics = array_intersect_key($site, array_flip(array('visitors', 'visits', 'pageviews', 'bounces')));
        $rows    = array(array('', 0, $site['detail'] ? $metrics : array_intersect_key($metrics, array_flip(array('visitors', 'pageviews')))));
        $rows    = array_merge($rows, $this->pages($day, (bool) $site['detail']));
        if ($site['detail']) {
            $rows = array_merge($rows, $this->visitor_rows($day));
        }
        return $rows;
    }

    /**
     * A day's pages: its pageviews per address, and with visitor rows the
     * visitors who viewed each (each visitor row one visit).
     *
     * @param string $day    Y-m-d.
     * @param bool   $detail Whether the day has visitor rows.
     * @return array<int,array{0:string,1:string,2:array<string,int>}>
     */
    private function pages($day, $detail) {
        global $wpdb;
        if (!self::has_columns('pages', array('uri', 'date', 'count'))) {
            return array();
        }
        $keyed = in_array('page_id', self::columns(self::table('pages')), true);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- another plugin's tables, one day by their date indexes, joined by visitor_id's index; only counts and addresses are read.
        $found = $wpdb->get_results($wpdb->prepare('SELECT %i AS id, uri, count FROM %i WHERE date = %s ORDER BY count DESC LIMIT %d', $keyed ? 'page_id' : 'count', self::table('pages'), $day, self::ROWS), ARRAY_A);
        $seen  = array();
        if ($detail && $keyed && self::has_columns('visitor_relationships', array('visitor_id', 'page_id'))) {
            $counts = $wpdb->get_results($wpdb->prepare('SELECT r.page_id AS id, COUNT(DISTINCT r.visitor_id) AS n FROM %i v INNER JOIN %i r ON r.visitor_id = v.ID WHERE v.last_counter = %s GROUP BY r.page_id', self::table('visitor'), self::table('visitor_relationships'), $day), ARRAY_A);
            foreach ((array) $counts as $row) {
                $seen[(int) $row['id']] = (int) $row['n'];
            }
        }
        // phpcs:enable
        $sums = array();
        foreach ((array) $found as $row) {
            $path = self::path((string) $row['uri']);
            if (!isset($sums[$path])) {
                $sums[$path] = $detail && $seen ? array('visitors' => 0, 'visits' => 0, 'pageviews' => 0) : array('pageviews' => 0);
            }
            $sums[$path]['pageviews'] += max(0, (int) $row['count']);
            if (isset($sums[$path]['visits'])) {
                $n                         = $keyed && isset($seen[(int) $row['id']]) ? $seen[(int) $row['id']] : 0;
                $sums[$path]['visitors'] += $n;
                $sums[$path]['visits']   += $n;
            }
        }
        $rows = array();
        foreach ($sums as $path => $metrics) {
            $rows[] = array('page', (string) $path, $metrics);
        }
        return $rows;
    }

    /**
     * A day's visitor rows by entry and exit page, browser, system, device,
     * country and source. Only the grouped values and counts are read.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function visitor_rows($day) {
        global $wpdb;
        $visitor = self::table('visitor');
        $pages   = self::table('pages');
        $cols    = self::columns($visitor);
        $paged   = in_array('page_id', self::columns($pages), true) && !array_diff(array('first_page', 'last_page'), $cols);
        $sums    = 'COUNT(*) AS visitors, COUNT(*) AS visits, COALESCE(SUM(v.hits), 0) AS pageviews, COALESCE(SUM(COALESCE(v.hits, 0) <= 1), 0) AS bounces';
        $rows    = array();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- another plugin's tables, one day by the visitor table's date index, joined by primary key; $sums is fixed SQL. Only grouped names, addresses and counts are read, never its IP, user agent or user columns.
        if ($paged) {
            foreach (array('entry' => 'first_page', 'exit' => 'last_page') as $dimension => $column) {
                $found = $wpdb->get_results($wpdb->prepare("SELECT p.uri AS v, $sums FROM %i v INNER JOIN %i p ON p.page_id = v.%i WHERE v.last_counter = %s GROUP BY p.uri ORDER BY visits DESC LIMIT %d", $visitor, $pages, $column, $day, self::ROWS), ARRAY_A);
                foreach ((array) $found as $row) {
                    $rows[] = array($dimension, self::path((string) $row['v']), self::metrics($row));
                }
            }
        }

        // Family names, as they are stored per visitor row.
        $names = array('browser' => 'agent', 'os' => 'platform', 'device' => 'device', 'country' => 'location');
        foreach ($names as $dimension => $column) {
            if (!in_array($column, $cols, true)) {
                continue;
            }
            $found = $wpdb->get_results($wpdb->prepare("SELECT v.%i AS v, $sums FROM %i v WHERE v.last_counter = %s GROUP BY v.%i ORDER BY visits DESC LIMIT %d", $column, $visitor, $day, $column, self::ROWS), ARRAY_A);
            foreach ((array) $found as $row) {
                $rows[] = array($dimension, self::value($dimension, (string) $row['v']), self::metrics($row));
            }
        }

        // Referrer with the first page (its campaign tags and the landing).
        if ($paged) {
            $mixed = $wpdb->get_results($wpdb->prepare("SELECT v.referred AS r, COALESCE(p.uri, '') AS e, $sums FROM %i v LEFT JOIN %i p ON p.page_id = v.first_page WHERE v.last_counter = %s GROUP BY v.referred, p.uri ORDER BY visits DESC LIMIT %d", $visitor, $pages, $day, self::ROWS * 5), ARRAY_A);
        } else {
            $mixed = $wpdb->get_results($wpdb->prepare("SELECT v.referred AS r, '' AS e, $sums FROM %i v WHERE v.last_counter = %s GROUP BY v.referred ORDER BY visits DESC LIMIT %d", $visitor, $day, self::ROWS * 5), ARRAY_A);
        }
        // phpcs:enable
        $groups = array();
        foreach ((array) $mixed as $row) {
            $entry    = (string) $row['e'];
            $groups[] = array(
                'r'       => (string) $row['r'],
                'q'       => (string) wp_parse_url($entry, PHP_URL_QUERY),
                'e'       => $entry === '' ? '' : self::path($entry),
                'metrics' => self::metrics($row),
            );
        }
        return array_merge($rows, self::visit_sources($groups));
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $wps = get_option(self::SETTINGS_OPTION, array());
        $out = array();
        if (!is_array($wps)) {
            return $out;
        }
        // Roles it leaves out: exclude_ and the role's name, lower case.
        $roles = array();
        $set   = false;
        foreach (wp_roles()->get_names() as $role => $name) {
            $option = 'exclude_' . str_replace(' ', '_', strtolower((string) $name));
            if (array_key_exists($option, $wps)) {
                $set = true;
                if (!empty($wps[$option])) {
                    $roles[] = (string) $role;
                }
            }
        }
        if ($set) {
            $out[] = array(
                'key'   => 'tracking_skip_roles',
                'label' => 'Filtering & Exceptions',
                'from'  => $roles ? implode(', ', $roles) : __('None', 'seoprostats'),
                'value' => $roles,
            );
        }
        $ips = self::ip_lines(isset($wps['exclude_ip']) ? (string) $wps['exclude_ip'] : '');
        if ($ips) {
            $out[] = array(
                'key'   => 'exclude_ips',
                'label' => 'Excluded IP Address List',
                /* translators: %d: number of addresses. */
                'from'  => sprintf(_n('%d address', '%d addresses', count($ips), 'seoprostats'), count($ips)),
                'value' => implode("\n", $ips),
                'also'  => array('exclusions' => true),
            );
        }
        if (!empty($wps['do_not_track'])) {
            $out[] = array(
                'key'   => 'privacy_signals',
                'label' => 'Do Not Track (DNT)',
                'from'  => __('On', 'seoprostats'),
                'value' => true,
            );
        }
        // Its purge keeps day totals, as ours does; only a choice other than its default is carried over.
        if (isset($wps['schedule_dbmaint_days']) && (string) $wps['schedule_dbmaint_days'] !== '' && (int) $wps['schedule_dbmaint_days'] !== self::PURGE_DEFAULT) {
            $days = (int) $wps['schedule_dbmaint_days'];
            if ($days <= 0) {
                $out[] = array(
                    'key'   => 'retention',
                    'label' => 'Purge Old Data Daily',
                    'from'  => __('Off', 'seoprostats'),
                    'value' => false,
                );
            } else {
                $out[] = array(
                    'key'   => 'retention_visits',
                    'label' => 'Purge Old Data Daily',
                    /* translators: %d: number of days. */
                    'from'  => sprintf(_n('%d day', '%d days', $days, 'seoprostats'), $days),
                    'value' => max(1, min(120, (int) round($days / 30.4375))),
                );
            }
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall_setting() {
        $wps = get_option(self::SETTINGS_OPTION, array());
        if (!is_array($wps)) {
            return null;
        }
        return array(
            'key'   => 'delete_data_on_uninstall',
            'label' => 'Delete All Data on Plugin Deletion',
            'where' => 'Statistics → Settings → Advanced Options → Danger Zone',
            'on'    => !empty($wps['delete_data_on_uninstall']),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function leftovers() {
        $uploads = wp_upload_dir(null, false);
        $tables  = array();
        foreach (self::TABLES as $name) {
            if (self::table_exists(self::table($name))) {
                $tables[] = self::table($name);
            }
        }
        sort($tables);
        // Copies of its script it once made in uploads (listed in an option of its own).
        $files  = array(trailingslashit((string) $uploads['basedir']) . 'wp-statistics');
        $hashed = get_option('wp_statistics_hashed_assets', array());
        foreach (is_array($hashed) ? $hashed : array() as $asset) {
            $dir = is_array($asset) && isset($asset['dir']) ? wp_normalize_path((string) $asset['dir']) : '';
            if ($dir !== '' && strpos($dir, wp_normalize_path(trailingslashit((string) $uploads['basedir']))) === 0) {
                $files[] = $dir;
            }
        }
        $site_transients = array('_site_transient_wp_statistics', '_site_transient_timeout_wp_statistics');
        $out             = array(
            'tables'     => $tables,
            'options'    => array_merge(self::options_like(array('wp_statistics')), self::options_exact(array('widget_wp_statistics_widget', 'wps_robotlist'))),
            // Its own, its caches' (wp_statistics_cache_) and its background jobs' locks.
            'transients' => self::options_like(array_merge(array('_transient_wp_statistics', '_transient_timeout_wp_statistics', '_transient_wps_', '_transient_timeout_wps_'), is_multisite() ? array() : $site_transients)),
            'cron'       => self::cron_like('wp_statistics_'),
            'user_meta'  => is_multisite() ? array() : self::user_meta_like('wp_statistics'),
            'post_meta'  => self::post_meta_like('wp_statistics'),
            'files'      => self::files_present($files),
            'network'    => array(),
        );
        if (is_multisite()) {
            // Shared by every site: listed, never deleted from one site.
            $out['network'] = array_filter(array(
                'options'   => self::options_like($site_transients, 'sitemeta'),
                'user_meta' => self::user_meta_like('wp_statistics'),
            ));
        }
        return $out;
    }

    /**
     * Whether its visitor table is there with the columns read.
     *
     * @return bool
     */
    private function readable() {
        return self::has_columns('visitor', array('ID', 'last_counter', 'referred', 'hits'));
    }

    /**
     * Whether one of its tables has some columns.
     *
     * @param string   $name    Name after statistics_.
     * @param string[] $columns Columns.
     * @return bool
     */
    private static function has_columns($name, array $columns) {
        $cols = self::columns(self::table($name));
        return $cols && !array_diff($columns, $cols);
    }

    /**
     * One of its tables on this site.
     *
     * @param string $name Name after statistics_.
     * @return string
     */
    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'statistics_' . $name;
    }

    /**
     * A stored name as SEO Pro Stats's value.
     *
     * @param string $dimension browser, os, device or country.
     * @param string $name      Its value.
     * @return int|string
     */
    private static function value($dimension, $name) {
        if ($dimension === 'device') {
            return self::device_code($name);
        }
        if ($dimension === 'country') {
            $code = strtoupper(trim($name));
            return preg_match('~^[A-Z]{2}$~', $code) ? $code : '';
        }
        return $dimension === 'os' ? self::os_name($name) : self::browser_name($name);
    }

    /**
     * Counts of a result row, as integers.
     *
     * @param array<string,mixed> $row Row.
     * @return array<string,int>
     */
    private static function metrics(array $row) {
        $out = array();
        foreach (array('visitors', 'visits', 'pageviews', 'bounces') as $key) {
            $out[$key] = isset($row[$key]) ? max(0, (int) $row[$key]) : 0;
        }
        return $out;
    }

    /**
     * A stored address as SEO Pro Stats's path.
     *
     * @param string $uri Its uri (a path, with its query).
     * @return string
     */
    private static function path($uri) {
        return SEOProStats_Processor::split_url($uri === '' ? '/' : $uri)['path'];
    }
}
