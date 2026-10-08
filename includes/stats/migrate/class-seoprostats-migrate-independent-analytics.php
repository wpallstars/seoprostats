<?php
/**
 * Independent Analytics (independent-analytics), read from a real install
 * of version 2.15.5 (database version 52), the current one when this was
 * written. It brings its tables to the current layout itself when updated.
 *
 * What it keeps, in this site's tables (prefix independent_analytics_):
 * sessions, a row per visit (created_at and ended_at in UTC; total_views;
 * the first and last view; visitor_id, a number for a hashed visitor;
 * referrer, country, device type, browser and system as IDs of their own
 * tables), views, a row per pageview (viewed_at, next_viewed_at, the
 * resource viewed), resources, a row per page (its address in cached_url),
 * and with its Pro version campaigns, the visit's UTM tags. Days are the
 * site's, from the UTC times. Its visitors table holds the hash, never read;
 * only distinct visitor numbers are counted, each day.
 *
 * Engaged time is the time from each view to the next of the same visit,
 * as it measures time on page (a visit's last page has none).
 *
 * Its settings are options named iawp_. It has no "delete data when
 * deleted" setting: deleting the plugin keeps everything. Deactivating it
 * unschedules its cron hooks (iawp_), deletes its GeoIP database and its
 * must-use plugin file. Its own "Delete all data & deactivate plugin"
 * (Analytics → Settings → Danger zone) removes its iawp_ options and user
 * meta, all its tables, its post meta (iawp_total_views) and its cached
 * icons (uploads/iawp-favicons/).
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

final class SEOProStats_Migrate_Independent_Analytics extends SEOProStats_Migrate_Source {

    const KEY   = 'independent';
    const NAME  = 'Independent Analytics';
    const SLUGS = array('independent-analytics', 'independent-analytics-pro');

    /** Its post meta key (views per post, for its column in the posts list). */
    const POST_META = 'iawp_total_views';

    /** Its "Automatically Delete Old Data" choices, in months (0: keep forever). */
    const PRUNING = array(
        'disabled'                    => 0,
        'thirty-days'                 => 1,
        'sixty-days'                  => 2,
        'ninety-days'                 => 3,
        'one-hundred-and-eighty-days' => 6,
        'one-year'                    => 12,
        'two-years'                   => 24,
        'three-years'                 => 36,
        'four-years'                  => 48,
    );

    /** Referrers it records in place of the real one for ad clicks: ours, with the click ID. */
    const ADS = array(
        'googleads.iawp'   => array('google.com', 'gclid'),
        'facebookads.iawp' => array('facebook.com', ''),
    );

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $out = array('version' => $this->installed_version(), 'from' => '', 'to' => '');
        if (!$this->readable()) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table: MIN and MAX on its created_at index.
        $span = $wpdb->get_row($wpdb->prepare('SELECT MIN(created_at) AS a, MAX(created_at) AS b FROM %i', self::table('sessions')), ARRAY_A);
        if (!empty($span['a'])) {
            $out['from'] = self::local_day((string) $span['a']);
            $out['to']   = self::local_day((string) $span['b']);
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        if (!$this->readable()) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, one entry of its created_at index.
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE created_at >= %s AND created_at < %s LIMIT 1', self::table('sessions'), self::utc($start), self::utc($end)));
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
        $start = self::utc(self::bounds($from)[0]);
        $end   = self::utc(self::bounds($to)[1]);
        // Visitors counted each day and added up, as SEO Pro Stats counts
        // them; days are 24-hour steps from the first day's start, so a
        // daylight-saving change moves an hour's visitors to the next day.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table by its created_at index; only counts are read.
        $row = $wpdb->get_row($wpdb->prepare('SELECT COALESCE(SUM(d.pv), 0) AS pageviews, COALESCE(SUM(d.v), 0) AS visits, COALESCE(SUM(d.u), 0) AS visitors FROM (SELECT COALESCE(SUM(COALESCE(total_views, 1)), 0) AS pv, COUNT(*) AS v, COUNT(DISTINCT %i) AS u FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY FLOOR(TIMESTAMPDIFF(SECOND, %s, created_at) / 86400)) d', $this->visitor_column(), self::table('sessions'), $start, $end, $start), ARRAY_A);
        foreach (array_keys($out) as $key) {
            $out[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string,array<int,array{0:string,1:int|string,2:array<string,int>}>> Day => rows (its tables, never an error).
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
     * One day's rows: the site, pages, entry and exit pages, browsers,
     * systems, devices, countries, sources, channels, campaign tags and
     * search landings.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function day($day) {
        global $wpdb;
        list($start, $end) = self::bounds($day);
        $start     = self::utc($start);
        $end       = self::utc($end);
        $sessions  = self::table('sessions');
        $views     = self::table('views');
        $resources = self::table('resources');
        $uid       = $this->visitor_column();
        $cols      = self::columns($sessions);
        $sums      = 'COUNT(DISTINCT s.%i) AS visitors, COUNT(*) AS visits, COALESCE(SUM(COALESCE(s.total_views, 1)), 0) AS pageviews, COALESCE(SUM(COALESCE(s.total_views, 1) <= 1), 0) AS bounces, COALESCE(SUM(GREATEST(TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.ended_at, s.created_at)), 0)), 0) * 1000 AS engaged_ms';
        $range     = 's.created_at >= %s AND s.created_at < %s';
        $address   = "COALESCE(NULLIF(r.cached_url, ''), r.not_found_url, '')";
        $rows      = array();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- another plugin's tables, one day by its sessions' created_at index, joined by primary keys and the views' session_id index; $sums, $range and $address are fixed SQL. Only grouped names, addresses and counts are read, never its visitor hashes.
        $site = $wpdb->get_row($wpdb->prepare("SELECT $sums FROM %i s WHERE $range", $uid, $sessions, $start, $end), ARRAY_A);
        if (!$site || (int) $site['pageviews'] <= 0) {
            return $rows;
        }
        $rows[] = array('', 0, self::metrics($site));

        $pages = $wpdb->get_results($wpdb->prepare("SELECT $address AS v, COUNT(DISTINCT s.%i) AS visitors, COUNT(DISTINCT v.session_id) AS visits, COUNT(*) AS pageviews, COALESCE(SUM(GREATEST(TIMESTAMPDIFF(SECOND, v.viewed_at, v.next_viewed_at), 0)), 0) * 1000 AS engaged_ms FROM %i s INNER JOIN %i v ON v.session_id = s.session_id INNER JOIN %i r ON r.id = v.resource_id WHERE $range GROUP BY v.resource_id ORDER BY pageviews DESC LIMIT %d", $uid, $sessions, $views, $resources, $start, $end, self::ROWS), ARRAY_A);
        $rows  = array_merge($rows, self::by_path('page', (array) $pages));

        $edges = array('entry' => 's.initial_view_id');
        if (in_array('final_view_id', $cols, true)) {
            $edges['exit'] = 'COALESCE(s.final_view_id, s.initial_view_id)';
        }
        foreach ($edges as $dimension => $view) {
            $found = $wpdb->get_results($wpdb->prepare("SELECT $address AS v, $sums FROM %i s INNER JOIN %i v ON v.id = $view INNER JOIN %i r ON r.id = v.resource_id WHERE $range GROUP BY r.id ORDER BY visits DESC LIMIT %d", $uid, $sessions, $views, $resources, $start, $end, self::ROWS), ARRAY_A);
            $rows  = array_merge($rows, self::by_path($dimension, (array) $found));
        }

        // Names in tables of their own.
        $names = array(
            'browser' => array('device_browser_id', 'device_browsers', 'device_browser_id', 'device_browser'),
            'os'      => array('device_os_id', 'device_oss', 'device_os_id', 'device_os'),
            'device'  => array('device_type_id', 'device_types', 'device_type_id', 'device_type'),
            'country' => array('country_id', 'countries', 'country_id', 'country_code'),
        );
        foreach ($names as $dimension => $join) {
            if (!in_array($join[0], $cols, true) || !in_array($join[3], self::columns(self::table($join[1])), true)) {
                continue;
            }
            $found = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(n.%i, '') AS v, $sums FROM %i s LEFT JOIN %i n ON n.%i = s.%i WHERE $range GROUP BY n.%i ORDER BY visits DESC LIMIT %d", $join[3], $uid, $sessions, self::table($join[1]), $join[2], $join[0], $start, $end, $join[3], self::ROWS), ARRAY_A);
            foreach ((array) $found as $row) {
                $rows[] = array($dimension, self::value($dimension, (string) $row['v']), self::metrics($row));
            }
        }

        // Referrer with the first page and, with its Pro version, the visit's UTM tags.
        $tags   = $this->campaigns();
        $select = "COALESCE(f.domain, '') AS r, $address AS e";
        $join   = '';
        $group  = 'f.domain, r.id';
        if ($tags) {
            $select .= ", COALESCE(c.utm_source, '') AS utm_source, COALESCE(c.utm_medium, '') AS utm_medium, COALESCE(c.utm_campaign, '') AS utm_campaign, COALESCE(c.utm_term, '') AS utm_term, COALESCE(c.utm_content, '') AS utm_content";
            $join    = $wpdb->prepare(' LEFT JOIN (SELECT k.campaign_id, us.utm_source, um.utm_medium, uc.utm_campaign, k.utm_term, k.utm_content FROM %i k LEFT JOIN %i us ON us.id = k.utm_source_id LEFT JOIN %i um ON um.id = k.utm_medium_id LEFT JOIN %i uc ON uc.id = k.utm_campaign_id) c ON c.campaign_id = s.campaign_id', self::table('campaigns'), self::table('utm_sources'), self::table('utm_mediums'), self::table('utm_campaigns'));
            $group  .= ', s.campaign_id';
        }
        $mixed = $wpdb->get_results($wpdb->prepare("SELECT $select, $sums FROM %i s LEFT JOIN %i f ON f.id = s.referrer_id LEFT JOIN %i v ON v.id = s.initial_view_id LEFT JOIN %i r ON r.id = v.resource_id$join WHERE $range GROUP BY $group ORDER BY visits DESC LIMIT %d", $uid, $sessions, self::table('referrers'), $views, $resources, $start, $end, self::ROWS * 5), ARRAY_A);
        // phpcs:enable
        $groups = array();
        foreach ((array) $mixed as $row) {
            $referrer = strtolower((string) $row['r']);
            $click    = '';
            if (isset(self::ADS[$referrer])) {
                list($referrer, $click) = self::ADS[$referrer];
            }
            $utm = array();
            foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content') as $tag) {
                if (isset($row[$tag]) && (string) $row[$tag] !== '') {
                    $utm[$tag] = (string) $row[$tag];
                }
            }
            $groups[] = array(
                'r'       => $referrer,
                'q'       => http_build_query($utm, '', '&', PHP_QUERY_RFC3986),
                'e'       => self::path((string) $row['e']),
                'c'       => $click,
                'metrics' => self::metrics($row),
            );
        }
        return array_merge($rows, self::visit_sources($groups));
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $out   = array();
        $roles = array_keys(wp_roles()->get_names());
        $track = get_option('iawp_track_authenticated_users', null);
        if ($track !== null) {
            if (!$track) {
                $out[] = array(
                    'key'   => 'tracking_skip_roles',
                    'label' => 'Track logged-in users',
                    'from'  => __('Off', 'seoprostats'),
                    'value' => $roles,
                );
            } else {
                $blocked = get_option('iawp_blocked_roles', array('administrator'));
                $blocked = array_values(array_intersect(is_array($blocked) ? array_map('strval', $blocked) : array(), $roles));
                $out[]   = array(
                    'key'   => 'tracking_skip_roles',
                    'label' => 'Ignore User Roles',
                    'from'  => $blocked ? implode(', ', $blocked) : __('None', 'seoprostats'),
                    'value' => $blocked,
                );
            }
        }
        $ips = self::ip_lines((array) get_option('iawp_blocked_ips', array()));
        if ($ips) {
            $out[] = array(
                'key'   => 'exclude_ips',
                'label' => 'Ignore IP Addresses',
                /* translators: %d: number of addresses. */
                'from'  => sprintf(_n('%d address', '%d addresses', count($ips), 'seoprostats'), count($ips)),
                'value' => implode("\n", $ips),
                'also'  => array('exclusions' => true),
            );
        }
        $cutoff = (string) get_option('iawp_pruning_cutoff', '');
        if (isset(self::PRUNING[$cutoff])) {
            $months = self::PRUNING[$cutoff];
            $out[]  = $months === 0
                ? array(
                    'key'   => 'retention',
                    'label' => 'Automatically Delete Old Data',
                    'from'  => 'Keep data forever',
                    'value' => false,
                )
                : array(
                    'key'   => 'retention_visits',
                    'label' => 'Automatically Delete Old Data',
                    /* translators: %d: number of months. */
                    'from'  => sprintf(_n('%d month', '%d months', $months, 'seoprostats'), $months),
                    'value' => $months,
                );
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function leftovers() {
        $uploads = trailingslashit((string) wp_upload_dir(null, false)['basedir']);
        $files   = array($uploads . 'iawp-favicons', $uploads . 'iawp-click-endpoint.php', $uploads . 'iawp-click-config.php', $uploads . 'iawp-click-data.php', trailingslashit(WPMU_PLUGIN_DIR) . 'iawp-performance-boost.php');
        // Its GeoIP database, in the main site's uploads on a network.
        $geo = array($uploads . 'iawp-geo-db.mmdb', $uploads . 'iawp-geo-db.zip');
        $out = array(
            'tables'     => self::tables_like('independent_analytics_'),
            'options'    => self::options_like(array('iawp_')),
            'transients' => self::options_like(array('_transient_iawp_', '_transient_timeout_iawp_')),
            'cron'       => self::cron_like('iawp_'),
            'user_meta'  => is_multisite() ? array() : self::user_meta_like('iawp_'),
            'post_meta'  => array_values(array_intersect(self::post_meta_like(self::POST_META), array(self::POST_META))),
            'files'      => self::files_present(is_multisite() ? $files : array_merge($files, $geo)),
            'network'    => array(),
        );
        if (is_multisite()) {
            // Shared by every site: listed, never deleted from one site.
            $out['network'] = array_filter(array(
                'user_meta' => self::user_meta_like('iawp_'),
                'files'     => is_main_site() ? self::files_present($geo) : array(),
            ));
        }
        return $out;
    }

    /**
     * Whether its sessions, views and resources tables are there with the
     * columns read.
     *
     * @return bool
     */
    private function readable() {
        $sessions = self::columns(self::table('sessions'));
        return $sessions && $this->visitor_column() !== ''
            && !array_diff(array('session_id', 'created_at', 'initial_view_id', 'referrer_id', 'total_views'), $sessions)
            && !array_diff(array('id', 'resource_id', 'session_id', 'viewed_at', 'next_viewed_at'), self::columns(self::table('views')))
            && !array_diff(array('id', 'cached_url'), self::columns(self::table('resources')));
    }

    /**
     * The sessions column that tells visitors apart: visitor_id, or
     * old_visitor_id in layouts before its visitors table.
     *
     * @return string
     */
    private function visitor_column() {
        $cols = self::columns(self::table('sessions'));
        foreach (array('visitor_id', 'old_visitor_id') as $column) {
            if (in_array($column, $cols, true)) {
                return $column;
            }
        }
        return '';
    }

    /**
     * Whether its Pro version's campaign tables are there.
     *
     * @return bool
     */
    private function campaigns() {
        return in_array('campaign_id', self::columns(self::table('sessions')), true)
            && !array_diff(array('campaign_id', 'utm_source_id', 'utm_medium_id', 'utm_campaign_id', 'utm_term', 'utm_content'), self::columns(self::table('campaigns')))
            && in_array('utm_source', self::columns(self::table('utm_sources')), true)
            && in_array('utm_medium', self::columns(self::table('utm_mediums')), true)
            && in_array('utm_campaign', self::columns(self::table('utm_campaigns')), true);
    }

    /**
     * Rows by address, as paths, added up (addresses can share a path).
     *
     * @param string                         $dimension page, entry or exit.
     * @param array<int,array<string,mixed>> $found     Rows: v and the sums.
     * @return array<int,array{0:string,1:string,2:array<string,int>}>
     */
    private static function by_path($dimension, array $found) {
        $sums = array();
        foreach ($found as $row) {
            $path    = self::path((string) $row['v']);
            $metrics = self::metrics($row);
            if (!isset($sums[$path])) {
                $sums[$path] = array_fill_keys(array_keys($metrics), 0);
            }
            foreach ($metrics as $name => $count) {
                $sums[$path][$name] += $count;
            }
        }
        $rows = array();
        foreach ($sums as $path => $metrics) {
            $rows[] = array($dimension, (string) $path, $metrics);
        }
        return $rows;
    }

    /**
     * One of its tables on this site.
     *
     * @param string $name Name after independent_analytics_.
     * @return string
     */
    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'independent_analytics_' . $name;
    }

    /**
     * A Unix time as its stored UTC time.
     *
     * @param int $time Unix time.
     * @return string
     */
    private static function utc($time) {
        return gmdate('Y-m-d H:i:s', (int) $time);
    }

    /**
     * A stored UTC time as the site's day.
     *
     * @param string $utc Y-m-d H:i:s, UTC.
     * @return string
     */
    private static function local_day($utc) {
        $time = strtotime($utc . ' UTC');
        return $time ? self::day_of($time) : '';
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
        foreach (array('visitors', 'visits', 'pageviews', 'bounces', 'engaged_ms') as $key) {
            if (isset($row[$key])) {
                $out[$key] = max(0, (int) $row[$key]);
            }
        }
        return $out;
    }

    /**
     * A stored address as SEO Pro Stats's path.
     *
     * @param string $url Its address (full, or '' for none).
     * @return string
     */
    private static function path($url) {
        $path  = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        return SEOProStats_Processor::split_url(($path === '' ? '/' : $path) . ($query !== '' ? '?' . $query : ''))['path'];
    }
}
