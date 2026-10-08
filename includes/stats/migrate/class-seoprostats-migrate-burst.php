<?php
/**
 * Burst Statistics (burst-statistics), read from a real install of
 * version 3.7.2, the current one when this was written.
 *
 * What it keeps, in this site's tables (prefix burst_): burst_statistics,
 * one row per pageview (time, page_url, parameters, time_on_page in
 * milliseconds, max_scroll in percent, session_id, uid_id), and
 * burst_sessions, one row per visit (start_time, referrer as a host,
 * bounce, browser_id, platform_id and device_id into burst_browsers,
 * burst_platforms and burst_devices, city_code into burst_locations for
 * the country). Older versions kept the visitor as uid on the pageview;
 * either is read, and only counted. Its settings are one option,
 * burst_options_settings.
 *
 * When it is deleted it removes its transients and its must-use plugin,
 * and keeps its tables, options and upload folder. It has no "delete
 * data when uninstalled" setting: Settings → Data → "Reset statistics"
 * only empties its statistics.
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

final class SEOProStats_Migrate_Burst extends SEOProStats_Migrate_Source {

    const KEY   = 'burst-statistics';
    const NAME  = 'Burst Statistics';
    const SLUGS = array('burst-statistics', 'burst-pro');

    /** Its settings option. */
    const SETTINGS_OPTION = 'burst_options_settings';

    /** Device names it stores => SEOProStats_Query::DEVICES. */
    const DEVICES = array('desktop' => 1, 'mobile' => 2, 'tablet' => 3);

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $out = array('version' => (string) get_option('burst-current-version', ''), 'from' => '', 'to' => '');
        if (!$this->readable()) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table: MIN and MAX on its time index read one entry each.
        $span        = $wpdb->get_row($wpdb->prepare('SELECT MIN(time) AS a, MAX(time) AS b FROM %i', self::table('statistics')), ARRAY_A);
        $out['from'] = self::day_of(isset($span['a']) ? (int) $span['a'] : 0);
        $out['to']   = self::day_of(isset($span['b']) ? (int) $span['b'] : 0);
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, one entry of its time index.
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE time >= %d AND time < %d LIMIT 1', self::table('statistics'), $start, $end));
    }

    /**
     * {@inheritDoc}
     */
    public function totals($from, $to) {
        global $wpdb;
        $out = array('pageviews' => 0, 'visits' => 0, 'visitors' => 0);
        if (!$this->readable() || $from === '' || $to === '') {
            return $out;
        }
        $start = self::bounds($from)[0];
        $end   = self::bounds($to)[1];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table by its time index; only counts are read.
        $row = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS pageviews, COUNT(DISTINCT session_id) AS visits, COUNT(DISTINCT %i) AS visitors FROM %i WHERE time >= %d AND time < %d', $this->uid(), self::table('statistics'), $start, $end), ARRAY_A);
        foreach (array_keys($out) as $key) {
            $out[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function days($from, $to) {
        $out = array();
        if (!$this->readable()) {
            return $out;
        }
        foreach ($this->day_list($from, $to) as $day) {
            $out[$day] = $this->day($day);
        }
        return $out;
    }

    /**
     * One day's rows, by visit start like SEO Pro Stats's own days: a
     * visit that runs past midnight counts on the day it began.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function day($day) {
        global $wpdb;
        list($start, $end) = self::bounds($day);
        $st                = self::table('statistics');
        $ss                = self::table('sessions');
        $sessions          = self::columns($ss);
        $stats             = self::columns($st);
        $rows              = array();

        // Per visit: pageviews, time on pages, first and last pageview,
        // and the visitor's number (only for COUNT DISTINCT; never read).
        $visit = $wpdb->prepare(
            'SELECT st.session_id AS sid, COUNT(*) AS pv, COALESCE(SUM(st.time_on_page), 0) AS ms, MIN(st.ID) AS f, MAX(st.ID) AS l, MIN(st.%i) AS u FROM %i s INNER JOIN %i st ON st.session_id = s.ID WHERE s.start_time >= %d AND s.start_time < %d GROUP BY st.session_id',
            $this->uid(),
            $ss,
            $st,
            $start,
            $end
        );
        $bounce = in_array('bounce', $sessions, true) ? 'COALESCE(SUM(s.bounce), 0)' : 'COALESCE(SUM(x.pv <= 1), 0)';
        $sums   = "COUNT(DISTINCT x.u) AS visitors, COUNT(*) AS visits, COALESCE(SUM(x.pv), 0) AS pageviews, $bounce AS bounces, COALESCE(SUM(x.ms), 0) AS engaged_ms";
        $base   = "FROM ($visit) x INNER JOIN %i s ON s.ID = x.sid";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- another plugin's tables, one day by its sessions' start_time index, joined by primary keys; $visit is prepared above, $sums, $bounce and the joins are fixed SQL. Only counts and names are read.
        $site = $wpdb->get_row($wpdb->prepare("SELECT $sums $base", $ss), ARRAY_A);
        if (!$site || (int) $site['pageviews'] === 0) {
            return $rows;
        }
        $rows[] = array('', 0, self::metrics($site));

        // Pages: each pageview of the day's visits.
        $scroll = in_array('max_scroll', $stats, true) ? 'COALESCE(SUM(st.max_scroll), 0)' : '0';
        $pages  = $wpdb->get_results($wpdb->prepare("SELECT st.page_url AS v, COUNT(DISTINCT st.%i) AS visitors, COUNT(DISTINCT st.session_id) AS visits, COUNT(*) AS pageviews, COALESCE(SUM(st.time_on_page), 0) AS engaged_ms, $scroll AS scroll FROM %i s INNER JOIN %i st ON st.session_id = s.ID WHERE s.start_time >= %d AND s.start_time < %d GROUP BY st.page_url ORDER BY pageviews DESC LIMIT %d", $this->uid(), $ss, $st, $start, $end, self::ROWS), ARRAY_A);
        foreach ((array) $pages as $row) {
            $rows[] = array('page', self::path((string) $row['v']), self::metrics($row));
        }

        // Entry and exit pages.
        foreach (array('entry' => 'f', 'exit' => 'l') as $dimension => $end_column) {
            $found = $wpdb->get_results($wpdb->prepare("SELECT p.page_url AS v, $sums $base INNER JOIN %i p ON p.ID = x.$end_column GROUP BY p.page_url ORDER BY visits DESC LIMIT %d", $ss, $st, self::ROWS), ARRAY_A);
            foreach ((array) $found as $row) {
                $rows[] = array($dimension, self::path((string) $row['v']), self::metrics($row));
            }
        }

        // Names in lookup tables: browser, operating system, device.
        $lookups = array(
            'browser' => array('browser_id', 'browsers'),
            'os'      => array('platform_id', 'platforms'),
            'device'  => array('device_id', 'devices'),
        );
        foreach ($lookups as $dimension => $lookup) {
            if (!in_array($lookup[0], $sessions, true) || !self::table_exists(self::table($lookup[1]))) {
                continue;
            }
            $found = $wpdb->get_results($wpdb->prepare("SELECT n.name AS v, $sums $base LEFT JOIN %i n ON n.ID = s.%i GROUP BY n.name ORDER BY visits DESC LIMIT %d", $ss, self::table($lookup[1]), $lookup[0], self::ROWS), ARRAY_A);
            foreach ((array) $found as $row) {
                $rows[] = array($dimension, self::name($dimension, (string) $row['v']), self::metrics($row));
            }
        }

        // Country: its own column in older versions, else the location's.
        $country = null;
        if (in_array('country_code', $sessions, true)) {
            $country = $wpdb->prepare("SELECT s.country_code AS v, $sums $base GROUP BY s.country_code ORDER BY visits DESC LIMIT %d", $ss, self::ROWS);
        } elseif (in_array('city_code', $sessions, true) && in_array('country_code', self::columns(self::table('locations')), true)) {
            $country = $wpdb->prepare("SELECT c.country_code AS v, $sums $base LEFT JOIN %i c ON c.city_code = s.city_code AND s.city_code > 0 GROUP BY c.country_code ORDER BY visits DESC LIMIT %d", $ss, self::table('locations'), self::ROWS);
        }
        foreach ($country ? (array) $wpdb->get_results($country, ARRAY_A) : array() as $row) {
            $code   = strtoupper(trim((string) $row['v']));
            $rows[] = array('country', preg_match('~^[A-Z]{2}$~', $code) ? $code : '', self::metrics($row));
        }

        // Referrer host and the first page's campaign tags, together, for
        // the source, channel, campaign and search landing dimensions.
        $referrer = in_array('referrer', $sessions, true) ? "COALESCE(s.referrer, '')" : "''";
        $params   = in_array('parameters', $stats, true) ? "COALESCE(p.parameters, '')" : "''";
        $mixed    = $wpdb->get_results($wpdb->prepare("SELECT $referrer AS r, $params AS q, p.page_url AS e, $sums $base INNER JOIN %i p ON p.ID = x.f GROUP BY r, q, e ORDER BY visits DESC LIMIT %d", $ss, $st, self::ROWS * 5), ARRAY_A);
        // phpcs:enable
        $rows = array_merge($rows, $this->visit_sources((array) $mixed));
        return $rows;
    }

    /**
     * Source, channel, campaign tags and search landings from visits
     * grouped by referrer, the first page's tags and the first page.
     *
     * @param array<int,array<string,mixed>> $groups Rows: r, q, e and the sums.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function visit_sources(array $groups) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-channels.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-processor.php';
        $sums = array();
        $add  = function ($dimension, $value, array $metrics) use (&$sums) {
            $key = $dimension . "\0" . $value;
            if (!isset($sums[$key])) {
                $sums[$key] = array($dimension, $value, array_fill_keys(array_keys($metrics), 0));
            }
            foreach ($metrics as $name => $count) {
                $sums[$key][2][$name] += $count;
            }
        };
        foreach ($groups as $row) {
            $metrics = self::metrics($row);
            $host    = self::host((string) $row['r']);
            $host    = $host === 'spammer' ? '' : $host;
            $split   = SEOProStats_Processor::split_url('/?' . ltrim((string) $row['q'], '?'));
            $channel = SEOProStats_Channels::classify($host, $split['utm'], $split['click']);
            $add('source', $host, $metrics);
            $add('channel', $channel, $metrics);
            foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content') as $tag) {
                $add($tag, isset($split['utm'][$tag]) ? $split['utm'][$tag] : '', $metrics);
            }
            if ($channel === SEOProStats_Query::CHANNELS['organic_search']) {
                $add('landing', self::path((string) $row['e']), $metrics);
            }
        }
        return array_values($sums);
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $burst = get_option(self::SETTINGS_OPTION, array());
        $burst = is_array($burst) ? $burst : array();
        $out   = array();
        if (!empty($burst['enable_do_not_track'])) {
            $out[] = array(
                'key'   => 'privacy_signals',
                'label' => "Honor 'Do Not Track' requests",
                'from'  => __('On', 'seoprostats'),
                'value' => true,
            );
        }
        if (isset($burst['user_role_blocklist']) && is_array($burst['user_role_blocklist'])) {
            $roles = array_values(array_intersect(array_map('strval', $burst['user_role_blocklist']), array_keys(wp_roles()->get_names())));
            $out[] = array(
                'key'   => 'tracking_skip_roles',
                'label' => 'Exclude user roles from being tracked',
                'from'  => $roles ? implode(', ', $roles) : __('None', 'seoprostats'),
                'value' => $roles,
            );
        }
        $ips = isset($burst['ip_blocklist']) ? trim((string) $burst['ip_blocklist']) : '';
        if ($ips !== '') {
            $lines = array_values(array_filter(array_map('trim', preg_split('~[\s,]+~', $ips))));
            $out[] = array(
                'key'   => 'exclude_ips',
                'label' => 'Exclude IP addresses from being tracked',
                /* translators: %d: number of addresses. */
                'from'  => sprintf(_n('%d address', '%d addresses', count($lines), 'seoprostats'), count($lines)),
                'value' => implode("\n", $lines),
                'also'  => array('exclusions' => true),
            );
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function leftovers() {
        $uploads = wp_upload_dir(null, false);
        $base    = trailingslashit((string) $uploads['basedir']);
        $files   = array($base . 'burst', trailingslashit(WPMU_PLUGIN_DIR) . 'burst_rest_api_optimizer.php');
        // Its public script copies, in uploads/js (a folder it shares).
        foreach (array('burst.min.js', 'timeme.min.js', substr(hash('sha256', 'burst-' . get_site_url()), 0, 8) . '.min.js') as $name) {
            $files[] = $base . 'js/' . $name;
        }
        $out = array(
            'tables'     => self::tables_like('burst_'),
            'options'    => self::options_like(array('burst_', 'burst-')),
            'transients' => self::options_like(array('_transient_burst_', '_transient_timeout_burst_', '_site_transient_burst_', '_site_transient_timeout_burst_')),
            'cron'       => self::cron_like('burst_'),
            'user_meta'  => is_multisite() ? array() : self::user_meta_like('burst_'),
            'files'      => self::files_present($files),
            'network'    => array(),
        );
        if (is_multisite()) {
            // Shared by every site: listed, never deleted from one site.
            $out['network'] = array_filter(array(
                'options'   => self::options_like(array('burst_', 'burst-', '_site_transient_burst_', '_site_transient_timeout_burst_'), 'sitemeta'),
                'user_meta' => self::user_meta_like('burst_'),
            ));
        }
        return $out;
    }

    /**
     * Whether its two statistics tables are there with the columns read.
     *
     * @return bool
     */
    private function readable() {
        $stats    = self::columns(self::table('statistics'));
        $sessions = self::columns(self::table('sessions'));
        return $stats && $sessions && !array_diff(array('ID', 'time', 'page_url', 'session_id', 'time_on_page'), $stats) && in_array('start_time', $sessions, true) && $this->uid() !== '';
    }

    /**
     * The pageview's visitor column: uid_id, or uid in older versions.
     *
     * @return string '' when neither.
     */
    private function uid() {
        $stats = self::columns(self::table('statistics'));
        foreach (array('uid_id', 'uid') as $column) {
            if (in_array($column, $stats, true)) {
                return $column;
            }
        }
        return '';
    }

    /**
     * One of its tables on this site.
     *
     * @param string $name Name after burst_.
     * @return string
     */
    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'burst_' . $name;
    }

    /**
     * Counts of a result row, as integers.
     *
     * @param array<string,mixed> $row Row.
     * @return array<string,int>
     */
    private static function metrics(array $row) {
        $out = array();
        foreach (array('visitors', 'visits', 'pageviews', 'bounces', 'engaged_ms', 'scroll') as $key) {
            if (isset($row[$key])) {
                $out[$key] = max(0, (int) $row[$key]);
            }
        }
        return $out;
    }

    /**
     * A stored page address as SEO Pro Stats's path.
     *
     * @param string $url Its page_url (a path).
     * @return string
     */
    private static function path($url) {
        return SEOProStats_Processor::split_url($url === '' ? '/' : $url)['path'];
    }

    /**
     * A browser, system or device name as SEO Pro Stats names it.
     *
     * @param string $dimension browser, os or device.
     * @param string $name      Its name.
     * @return int|string
     */
    private static function name($dimension, $name) {
        $name  = trim($name);
        $lower = strtolower($name);
        if ($dimension === 'device') {
            return isset(self::DEVICES[$lower]) ? self::DEVICES[$lower] : 0;
        }
        if ($dimension === 'os') {
            $systems = array(
                'macos'     => '~^(mac|macintosh|os x|mac os)~',
                'iOS'       => '~^(ios|iphone|ipad|ipod)~',
                'Windows'   => '~^windows~',
                'Android'   => '~^android~',
                'ChromeOS'  => '~^(chrome ?os|cros)~',
                'Linux'     => '~^(linux|ubuntu|debian|fedora)~',
            );
            foreach ($systems as $ours => $pattern) {
                if (preg_match($pattern, $lower)) {
                    return $ours === 'macos' ? 'macOS' : $ours;
                }
            }
            return 'Other';
        }
        $browsers = array(
            'Edge'              => '~^(microsoft )?edge~',
            'Opera'             => '~^opera~',
            'Samsung Internet'  => '~^samsung~',
            'Yandex Browser'    => '~^yandex~',
            'Vivaldi'           => '~^vivaldi~',
            'UC Browser'        => '~^uc ?browser~',
            'DuckDuckGo'        => '~^duckduckgo~',
            'Firefox'           => '~firefox~',
            'Chrome'            => '~^(google )?chrome~',
            'Safari'            => '~safari~',
            'Internet Explorer' => '~^(internet explorer|ie$|msie)~',
        );
        foreach ($browsers as $ours => $pattern) {
            if (preg_match($pattern, $lower)) {
                return $ours;
            }
        }
        return 'Other';
    }
}
