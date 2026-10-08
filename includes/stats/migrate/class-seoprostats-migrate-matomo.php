<?php
/**
 * Matomo for WordPress (matomo), read from a real install of version
 * 5.13.1 (Matomo core 5.13), the current one when this was written.
 *
 * What it keeps, in this site's tables (prefix plus matomo_): log_visit, a
 * row per visit (idsite; visit_last_action_time in UTC, indexed with
 * idsite, the time Matomo files a visit under; entry and exit page as
 * log_action IDs; referer_type: 1 direct, 2 search engine, 3 website,
 * 6 campaign, 7 social network, 8 AI assistant; referer_name, _keyword and
 * _url; config_browser_name and config_os, short codes such as CH and WIN;
 * config_device_type, 0 desktop, 1 smartphone, 2 tablet; location_country,
 * two letters in lower case, xx unknown; visit_total_time in seconds),
 * log_link_visit_action, a row per action (idaction_url, the page; its
 * time_spent), log_action, the names (type 1: page addresses, stored
 * without the scheme), and archives of reports per period, often
 * compressed. The visit rows also hold the IP address, the visitor ID and
 * the configuration ID: those columns are never read, only grouped counts.
 *
 * Its reporting API is not used: it loads only while the plugin is active
 * (an import must also work after it is deactivated or deleted), it boots
 * all of Matomo inside the request, and asking it for a day not archived
 * yet starts that archiving. Its archives are not decoded either. The raw
 * log tables are read instead, one day at a time on their time index, as
 * Matomo itself archives a day: visits by their last action's time,
 * pageviews as the visits' page actions.
 *
 * It keeps no visitor identity except the visitor ID, which is never
 * read, so each visit counts as one visitor. Campaign tags: Matomo takes
 * them out of the page address and keeps the campaign's name (and
 * keyword), which become utm_campaign (and utm_term).
 *
 * Its settings are the option matomo-global-option (the network's site
 * option when it is network-activated) and, for excluded IP addresses,
 * its own site table and options. When it is deactivated it keeps
 * everything. When it is deleted with "Delete all data on uninstall"
 * (Matomo Analytics → Settings → Advanced; on until changed, or set by
 * the MATOMO_REMOVE_ALL_DATA constant) it removes its tables, its
 * matomo- and matomo_global- options, its cron hooks, its roles, its
 * dashboard user meta and its uploads/matomo folder; otherwise only the
 * scheduled tasks, roles and dashboard settings.
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

final class SEOProStats_Migrate_Matomo extends SEOProStats_Migrate_Source {

    const KEY   = 'matomo';
    const NAME  = 'Matomo for WordPress';
    const SLUGS = array('matomo');

    /** Its settings option. */
    const SETTINGS_OPTION = 'matomo-global-option';

    /** Its option of this site's Matomo site ID, then the blog ID. */
    const SITE_OPTION = 'matomo-site-id-';

    /** Its options of a version it recorded. */
    const VERSION_OPTION = 'matomo-plugin-version-matomo';

    /** The roles it adds. */
    const ROLES = array('matomo_view_role', 'matomo_write_role', 'matomo_admin_role', 'matomo_superuser_role');

    /** Longest time counted on one pageview, in seconds. */
    const PAGE_TIME = 1800;

    /** Its log_action type of page addresses. */
    const PAGE = 1;

    /** Its referer_type values. */
    const DIRECT   = 1;
    const CAMPAIGN = 6;

    /** Its browser codes with a name SEO Pro Stats knows (the rest: Other). */
    const BROWSERS = array(
        'CH' => 'Chrome',
        'CM' => 'Chrome',
        'CI' => 'Chrome',
        'CV' => 'Chrome',
        'FF' => 'Firefox',
        'FM' => 'Firefox',
        'F1' => 'Firefox',
        'FK' => 'Firefox',
        'SF' => 'Safari',
        'MF' => 'Safari',
        'PS' => 'Edge',
        'EW' => 'Edge',
        'OP' => 'Opera',
        'OM' => 'Opera',
        'OI' => 'Opera',
        'OX' => 'Opera',
        'O1' => 'Opera',
        'SB' => 'Samsung Internet',
        'YA' => 'Yandex Browser',
        'VI' => 'Vivaldi',
        'V7' => 'Vivaldi',
        'UC' => 'UC Browser',
        'DD' => 'DuckDuckGo',
        'IE' => 'Internet Explorer',
        'IM' => 'Internet Explorer',
    );

    /** Its system codes with a name SEO Pro Stats knows (the rest: Other). */
    const SYSTEMS = array(
        'WIN' => 'Windows',
        'MAC' => 'macOS',
        'IOS' => 'iOS',
        'IPA' => 'iOS',
        'AND' => 'Android',
        'COS' => 'ChromeOS',
        'CRS' => 'ChromeOS',
        'LIN' => 'Linux',
        'UBT' => 'Linux',
        'DEB' => 'Linux',
        'FED' => 'Linux',
        'MIN' => 'Linux',
    );

    /** @var int|null This site's Matomo site ID, read once. */
    private $site = null;

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $version = $this->installed_version();
        $out     = array('version' => $version !== '' ? $version : (string) get_option(self::VERSION_OPTION, ''), 'from' => '', 'to' => '');
        $site    = $this->site_id();
        if (!$site || !$this->ready()) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table: MIN and MAX on its (idsite, visit_last_action_time) index.
        $span = $wpdb->get_row($wpdb->prepare('SELECT MIN(visit_last_action_time) AS a, MAX(visit_last_action_time) AS b FROM %i WHERE idsite = %d', $this->table('log_visit'), $site), ARRAY_A);
        if (!empty($span['a'])) {
            $out['from'] = self::day_of((int) strtotime($span['a'] . ' UTC'));
            $out['to']   = self::day_of((int) strtotime($span['b'] . ' UTC'));
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function size() {
        return self::table_rows(array($this->table('log_visit'), $this->table('log_link_visit_action')));
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        $site = $this->site_id();
        if (!$site || !$this->ready()) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, entries of its (idsite, visit_last_action_time) index.
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE idsite = %d AND visit_last_action_time >= %s AND visit_last_action_time < %s LIMIT 1', $this->table('log_visit'), $site, gmdate('Y-m-d H:i:s', (int) $start), gmdate('Y-m-d H:i:s', (int) $end)));
    }

    /**
     * {@inheritDoc}
     */
    public function totals($from, $to) {
        global $wpdb;
        $out = array('pageviews' => 0, 'visits' => 0, 'visitors' => 0);
        if ($from === '' || $to === '' || !$this->site_id() || !$this->ready()) {
            return $out;
        }
        list($visits, $args) = $this->visits(self::bounds($from)[0], self::bounds($to)[1]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- another plugin's tables by their time index; $visits is fixed SQL with its placeholders in $args. Only counts are read.
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS visits, COALESCE(SUM(x.n), 0) AS pageviews FROM $visits WHERE x.n > 0", $args), ARRAY_A);
        $out['pageviews'] = isset($row['pageviews']) ? (int) $row['pageviews'] : 0;
        $out['visits']    = isset($row['visits']) ? (int) $row['visits'] : 0;
        $out['visitors']  = $out['visits'];
        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string,array<int,array{0:string,1:int|string,2:array<string,int>}>> Day => rows (its tables, never an error).
     */
    public function days($from, $to) {
        $out = array();
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
        list($start, $end)   = self::bounds($day);
        list($visits, $args) = $this->visits($start, $end);
        $vsums  = 'COUNT(*) AS visitors, COUNT(*) AS visits, COALESCE(SUM(x.n), 0) AS pageviews, COALESCE(SUM(x.n <= 1), 0) AS bounces, COALESCE(SUM(LEAST(x.t, x.n * ' . self::PAGE_TIME . ')), 0) * 1000 AS engaged_ms';
        $action = $this->table('log_action');
        $time   = in_array('time_spent', self::columns($this->table('log_link_visit_action')), true) ? 'LEAST(COALESCE(a.time_spent, 0), ' . self::PAGE_TIME . ')' : '0';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- another plugin's tables, one day by their time index, joined by primary keys; $visits, $vsums and $time are fixed SQL with their placeholders in $args. Only grouped names, codes and counts are read, never its IP address, visitor ID or configuration ID columns.
        $site = $wpdb->get_row($wpdb->prepare("SELECT $vsums FROM $visits WHERE x.n > 0", $args), ARRAY_A);
        if (!$site || (int) $site['pageviews'] <= 0) {
            return array();
        }
        $sums = array();
        self::add($sums, '', 0, self::metrics($site));

        $pages = $wpdb->get_results($wpdb->prepare("SELECT MAX(p.name) AS v, COUNT(*) AS pageviews, COUNT(DISTINCT a.idvisit) AS visits, COUNT(DISTINCT a.idvisit) AS visitors, COALESCE(SUM($time), 0) * 1000 AS engaged_ms FROM %i v INNER JOIN %i a ON a.idvisit = v.idvisit INNER JOIN %i p ON p.idaction = a.idaction_url WHERE v.idsite = %d AND v.visit_last_action_time >= %s AND v.visit_last_action_time < %s AND p.type = %d GROUP BY a.idaction_url ORDER BY pageviews DESC LIMIT %d", $this->table('log_visit'), $this->table('log_link_visit_action'), $action, $this->site_id(), gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end), self::PAGE, self::ROWS), ARRAY_A);
        foreach ((array) $pages as $row) {
            self::add($sums, 'page', self::path((string) $row['v']), self::metrics($row));
        }

        foreach (array('entry' => 'f', 'exit' => 'l') as $dimension => $column) {
            $found = $wpdb->get_results($wpdb->prepare("SELECT MAX(e.name) AS v, $vsums FROM $visits LEFT JOIN %i e ON e.idaction = x.$column WHERE x.n > 0 GROUP BY x.$column ORDER BY visits DESC LIMIT %d", array_merge($args, array($action, self::ROWS))), ARRAY_A);
            foreach ((array) $found as $row) {
                self::add($sums, $dimension, self::path((string) $row['v']), self::metrics($row));
            }
        }
        foreach (array('browser' => 'b', 'os' => 'o', 'device' => 'd', 'country' => 'c') as $dimension => $column) {
            $found = $wpdb->get_results($wpdb->prepare("SELECT x.$column AS v, $vsums FROM $visits WHERE x.n > 0 GROUP BY x.$column ORDER BY visits DESC LIMIT %d", array_merge($args, array(self::ROWS))), ARRAY_A);
            foreach ((array) $found as $row) {
                self::add($sums, $dimension, self::value($dimension, (string) $row['v']), self::metrics($row));
            }
        }

        // Referrer with the first page (its query and the landing).
        $mixed = $wpdb->get_results($wpdb->prepare("SELECT x.rt, COALESCE(x.rn, '') AS rn, COALESCE(x.rk, '') AS rk, COALESCE(x.ru, '') AS ru, MAX(e.name) AS e, $vsums FROM $visits LEFT JOIN %i e ON e.idaction = x.f WHERE x.n > 0 GROUP BY x.rt, x.rn, x.rk, x.ru, x.f ORDER BY visits DESC LIMIT %d", array_merge($args, array($action, self::ROWS * 5))), ARRAY_A);
        // phpcs:enable
        $own    = self::host(home_url());
        $groups = array();
        foreach ((array) $mixed as $row) {
            $entry = self::address((string) $row['e']);
            $query = (string) wp_parse_url($entry, PHP_URL_QUERY);
            $type  = (int) $row['rt'];
            $from  = '';
            if ($type === self::CAMPAIGN) {
                // Its campaign name and keyword, as the tags it took out of the address.
                $query = http_build_query(array_filter(array('utm_campaign' => (string) $row['rn'], 'utm_term' => (string) $row['rk']), function ($tag) {
                    return $tag !== '';
                }));
                $from  = (string) $row['ru'];
            } elseif ($type !== self::DIRECT) {
                // The address it came from, else a name that is a host (websites).
                $from = (string) $row['ru'] !== '' ? (string) $row['ru'] : (strpos((string) $row['rn'], '.') !== false ? (string) $row['rn'] : '');
            }
            $groups[] = array(
                // Its own pages as the referrer: no source.
                'r'       => self::host($from) === $own ? '' : $from,
                'q'       => $query,
                'e'       => self::path((string) $row['e']),
                'metrics' => self::metrics($row),
            );
        }
        return array_merge(array_values($sums), self::visit_sources($groups));
    }

    /**
     * The day's visits as a derived table x (k: visit, f and l: entry and
     * exit page action, b, o, d, c: browser, system, device, country, rt,
     * rn, rk, ru: referrer type, name, keyword and address, t: its time,
     * n: its pageviews), with its placeholders' values.
     *
     * @param int $start Unix time (included).
     * @param int $end   Unix time (excluded).
     * @return array{0:string,1:array<int,int|string>}
     */
    private function visits($start, $end) {
        $sql = '(SELECT v.idvisit AS k, v.visit_entry_idaction_url AS f, v.visit_exit_idaction_url AS l, v.config_browser_name AS b, v.config_os AS o, v.config_device_type AS d, v.location_country AS c, v.referer_type AS rt, v.referer_name AS rn, v.referer_keyword AS rk, v.referer_url AS ru, COALESCE(v.visit_total_time, 0) AS t,'
            . ' (SELECT COUNT(*) FROM %i a INNER JOIN %i p ON p.idaction = a.idaction_url WHERE a.idvisit = v.idvisit AND p.type = %d) AS n'
            . ' FROM %i v WHERE v.idsite = %d AND v.visit_last_action_time >= %s AND v.visit_last_action_time < %s) x';
        return array($sql, array($this->table('log_link_visit_action'), $this->table('log_action'), self::PAGE, $this->table('log_visit'), $this->site_id(), gmdate('Y-m-d H:i:s', (int) $start), gmdate('Y-m-d H:i:s', (int) $end)));
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $global = $this->global_settings();
        $out    = array();
        if ($global !== null && !empty($global['caps_tracking']) && is_array($global['caps_tracking'])) {
            $roles = array_values(array_intersect(array_map('strval', array_keys(array_filter($global['caps_tracking']))), array_keys(wp_roles()->get_names())));
            if ($roles) {
                $out[] = array(
                    'key'   => 'tracking_skip_roles',
                    'label' => 'Exclude these roles from tracking',
                    'from'  => self::role_names($roles),
                    'value' => $roles,
                );
            }
        }
        $ips = self::ip_lines($this->excluded_ips());
        if ($ips) {
            $out[] = array(
                'key'   => 'exclude_ips',
                'label' => 'Excluded IPs',
                /* translators: %d: number of addresses. */
                'from'  => sprintf(_n('%d address', '%d addresses', count($ips), 'seoprostats'), count($ips)),
                'value' => implode("\n", $ips),
                'also'  => array('exclusions' => true),
            );
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall_setting() {
        $global = $this->global_settings();
        if ($global === null && !defined('MATOMO_REMOVE_ALL_DATA')) {
            return null;
        }
        if (defined('MATOMO_REMOVE_ALL_DATA')) {
            $on = (bool) constant('MATOMO_REMOVE_ALL_DATA');
        } else {
            $on = !isset($global['delete_all_data_uninstall']) || (bool) $global['delete_all_data_uninstall'];
        }
        return array(
            'key'   => 'delete_all_data_uninstall',
            'label' => 'Delete all data on uninstall',
            'where' => defined('MATOMO_REMOVE_ALL_DATA') ? 'MATOMO_REMOVE_ALL_DATA (wp-config.php)' : 'Matomo Analytics → Settings → Advanced',
            'on'    => $on,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function leftovers() {
        $folder = (defined('MATOMO_UPLOAD_DIR') ? (string) constant('MATOMO_UPLOAD_DIR') : 'matomo');
        $files  = self::files_present(array(trailingslashit((string) wp_upload_dir(null, false)['basedir']) . $folder));
        $roles  = array_values(array_filter(self::ROLES, function ($role) {
            return (bool) get_role($role);
        }));
        $meta   = self::user_meta_like('matomo_dashboard_widgets');
        $out    = array(
            'tables'     => self::tables_like($this->prefix()),
            'options'    => self::options_like(array('matomo-', 'matomo_global-')),
            'transients' => self::options_like(array('_transient_matomo_', '_transient_timeout_matomo_', '_transient_plugin-settings-tabs-', '_transient_timeout_plugin-settings-tabs-')),
            'cron'       => self::cron_like('matomo_'),
            'user_meta'  => is_multisite() ? array() : $meta,
            'roles'      => $roles,
            'files'      => $files,
            'network'    => array(),
        );
        if (is_multisite()) {
            // Shared by every site: listed, never deleted from one site.
            $out['network'] = array_filter(array(
                'options'   => self::options_like(array('matomo-', 'matomo_global-'), 'sitemeta'),
                'user_meta' => $meta,
            ));
        }
        return $out;
    }

    /**
     * Its settings option ('' keys when unset), or null without one.
     *
     * @return array<string,mixed>|null
     */
    private function global_settings() {
        $global = get_option(self::SETTINGS_OPTION, null);
        if (!is_array($global) && is_multisite()) {
            $global = get_site_option(self::SETTINGS_OPTION, null);
        }
        return is_array($global) ? $global : null;
    }

    /**
     * Its excluded IP addresses: this site's and the global list.
     *
     * @return string
     */
    private function excluded_ips() {
        global $wpdb;
        $site = $this->site_id();
        $ips  = '';
        if ($site && self::table_exists($this->table('site'))) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's site row, by its primary key.
            $ips .= (string) $wpdb->get_var($wpdb->prepare('SELECT excluded_ips FROM %i WHERE idsite = %d', $this->table('site'), $site));
        }
        if (self::table_exists($this->table('option'))) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's option, by its primary key.
            $ips .= ',' . (string) $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $this->table('option'), 'SitesManager_ExcludedIpsGlobal'));
        }
        return $ips;
    }

    /**
     * This site's Matomo site ID: its mapping option, else the only site in
     * its table (0 for none).
     *
     * @return int
     */
    private function site_id() {
        global $wpdb;
        if ($this->site === null) {
            $this->site = (int) get_site_option(self::SITE_OPTION . get_current_blog_id(), 0);
            if (!$this->site && self::table_exists($this->table('site'))) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's site table (one row a site).
                $ids        = (array) $wpdb->get_col($wpdb->prepare('SELECT idsite FROM %i ORDER BY idsite LIMIT 2', $this->table('site')));
                $this->site = count($ids) === 1 ? (int) $ids[0] : 0;
            }
        }
        return $this->site;
    }

    /**
     * Whether its log tables are there with the columns read.
     *
     * @return bool
     */
    private function ready() {
        return !array_diff(array('idvisit', 'idsite', 'visit_last_action_time', 'visit_entry_idaction_url', 'visit_exit_idaction_url', 'referer_type', 'referer_name', 'referer_keyword', 'referer_url', 'config_browser_name', 'config_os', 'config_device_type', 'location_country', 'visit_total_time'), self::columns($this->table('log_visit')))
            && !array_diff(array('idvisit', 'idaction_url'), self::columns($this->table('log_link_visit_action')))
            && !array_diff(array('idaction', 'name', 'type'), self::columns($this->table('log_action')));
    }

    /**
     * Its table prefix after the site's (matomo_ unless changed).
     *
     * @return string
     */
    private function prefix() {
        return defined('MATOMO_DATABASE_PREFIX') ? (string) constant('MATOMO_DATABASE_PREFIX') : 'matomo_';
    }

    /**
     * One of its tables, full name.
     *
     * @param string $name Such as log_visit.
     * @return string
     */
    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . $this->prefix() . $name;
    }

    /**
     * Add a row's metrics to the sums.
     *
     * @param array<string,array{0:string,1:int|string,2:array<string,int>}> $sums      Rows by dimension and value.
     * @param string                                                         $dimension Dimension.
     * @param int|string                                                     $value     Value.
     * @param array<string,int>                                              $metrics   Metrics.
     */
    private static function add(array &$sums, $dimension, $value, array $metrics) {
        $key = $dimension . "\0" . ($dimension === '' ? '' : $value);
        if (!isset($sums[$key])) {
            $sums[$key] = array($dimension, $value, array_fill_keys(array_keys($metrics), 0));
        }
        foreach ($metrics as $name => $count) {
            $sums[$key][2][$name] = (isset($sums[$key][2][$name]) ? $sums[$key][2][$name] : 0) + $count;
        }
    }

    /**
     * A stored code as SEO Pro Stats's value.
     *
     * @param string $dimension browser, os, device or country.
     * @param string $value     Its code.
     * @return int|string
     */
    private static function value($dimension, $value) {
        switch ($dimension) {
            case 'device':
                if ($value === '') {
                    return self::device_code('');
                }
                // 0 desktop; 1 smartphone, 3 feature phone, 10 phablet; 2 tablet.
                $map = array(0 => 'desktop', 1 => 'mobile', 3 => 'mobile', 10 => 'mobile', 2 => 'tablet');
                return self::device_code(isset($map[(int) $value]) ? $map[(int) $value] : '');
            case 'country':
                $code = strtoupper(trim($value));
                return preg_match('~^[A-Z]{2}$~', $code) && $code !== 'XX' ? $code : '';
            case 'os':
                $code = strtoupper(trim($value));
                return isset(self::SYSTEMS[$code]) ? self::SYSTEMS[$code] : 'Other';
            default:
                $code = strtoupper(trim($value));
                return isset(self::BROWSERS[$code]) ? self::browser_name(self::BROWSERS[$code]) : 'Other';
        }
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
     * A stored page address (host and path, without the scheme, with HTML
     * entities) as an address.
     *
     * @param string $name Its log_action name.
     * @return string
     */
    private static function address($name) {
        $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        if ($name === '' || strpos($name, '//') !== false) {
            return $name;
        }
        return $name[0] === '/' ? 'http://localhost' . $name : 'http://' . $name;
    }

    /**
     * A stored page address as SEO Pro Stats's path.
     *
     * @param string $name Its log_action name.
     * @return string
     */
    private static function path($name) {
        $address = self::address($name);
        $path    = (string) wp_parse_url($address, PHP_URL_PATH);
        $query   = (string) wp_parse_url($address, PHP_URL_QUERY);
        $uri     = ($path === '' ? '/' : $path) . ($query !== '' ? '?' . $query : '');
        return SEOProStats_Processor::split_url($uri)['path'];
    }
}
