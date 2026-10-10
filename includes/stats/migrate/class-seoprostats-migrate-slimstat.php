<?php
/**
 * Slimstat (wp-slimstat), read from a real install of version 5.5.0, the
 * current one when this was written.
 *
 * What it keeps, in this site's tables: slim_stats, a row per pageview
 * (dt, the time, indexed; visit_id, the visit, 0 for a pageview it kept
 * outside a visit; resource, the address viewed; referer; browser,
 * platform (its own system codes, such as win10 or macosx), browser_type
 * (0 desktop, 1 crawler, 2 mobile or tablet, 3 touch screen, not mobile);
 * country; content_type; dt_out, when the visitor left the page, 0 when it
 * is not known), and slim_stats_archive, the same rows moved there by its
 * "Retention Period" while "Archive Mode" is on (the default). Both are
 * read. Its rows also hold the IP addresses, the user name and e-mail,
 * the fingerprint and the user agent: those columns are never read, only
 * grouped counts.
 *
 * dt is not plain Unix time: it is WordPress's legacy "local timestamp"
 * (Unix time plus the site's offset from UTC at that moment), so the
 * site's day is dt's UTC day, and a day is the dt range from that day's
 * midnight UTC, 86,400 seconds long.
 *
 * Visits: the rows of a visit_id, each row with visit_id 0 a visit of its
 * own. It keeps no visitor identity except the IP address and fingerprint,
 * which are never read, so each visit counts as one visitor. Crawler rows
 * (browser_type 1) and wp-admin pageviews (content_type admin) are left
 * out, as SEO Pro Stats counts neither. Engaged time is each pageview's
 * time to dt_out, at most 30 minutes a pageview.
 *
 * Its settings are one option, slimstat_options. When it is deactivated it
 * keeps everything. When it is deleted it removes its tables (and the
 * network's shared slim_browsers, slim_screenres and slim_content_info),
 * its slimstat_ options and transients, its cron hooks, its user meta of
 * screen layouts and its uploads/wp-slimstat folder (GeoIP database),
 * unless "Delete Data on Uninstall" (Slimstat → Settings → Maintenance) is
 * off; it is on until changed.
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

final class SEOProStats_Migrate_Slimstat extends SEOProStats_Migrate_Source {

    const KEY   = 'slimstat';
    const NAME  = 'Slimstat';
    const SLUGS = array('wp-slimstat');

    /** Its settings option. */
    const SETTINGS_OPTION = 'slimstat_options';

    /** Tables read: the pageviews and the ones its retention moved. */
    const READ = array('slim_stats', 'slim_stats_archive');

    /** Its tables on each site, in every layout. */
    const TABLES = array('slim_stats', 'slim_stats_archive', 'slim_events', 'slim_events_archive', 'slim_outbound', 'slim_stats_3', 'slim_stats_archive_3');

    /** Its tables shared by the network (the base prefix). */
    const SHARED_TABLES = array('slim_browsers', 'slim_screenres', 'slim_content_info');

    /** Its "Retention Period" default, in days. */
    const PURGE_DEFAULT = 420;

    /** Longest time counted on one pageview, in seconds. */
    const PAGE_TIME = 1800;

    /** User meta key starts of its screens' layout (box order, hidden and closed boxes). */
    const USER_META = array('meta-box-order_slimstat', 'metaboxhidden_slimstat', 'closedpostboxes_slimstat');

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $opts    = get_option(self::SETTINGS_OPTION, array());
        $version = $this->installed_version();
        if ($version === '' && is_array($opts) && isset($opts['version'])) {
            $version = (string) $opts['version'];
        }
        $out = array('version' => $version, 'from' => '', 'to' => '');
        foreach ($this->tables() as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table: MIN and MAX on its dt index.
            $span = $wpdb->get_row($wpdb->prepare('SELECT MIN(dt) AS a, MAX(dt) AS b FROM %i WHERE dt > 0', $table), ARRAY_A);
            if (!empty($span['a'])) {
                $from        = gmdate('Y-m-d', (int) $span['a']);
                $to          = gmdate('Y-m-d', (int) $span['b']);
                $out['from'] = $out['from'] === '' ? $from : min($out['from'], $from);
                $out['to']   = max($out['to'], $to);
            }
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function size() {
        global $wpdb;
        return self::table_rows(array_map(function ($name) use ($wpdb) {
            return $wpdb->prefix . $name;
        }, self::READ));
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        list($from, $to) = self::span(self::day_of($start));
        foreach ($this->tables() as $table) {
            $keep = $this->keep($table);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- another plugin's table, entries of its dt index; $keep is fixed SQL.
            if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM %i WHERE dt >= %d AND dt < %d AND $keep LIMIT 1", $table, $from, $to))) {
                return true;
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function totals($from, $to) {
        global $wpdb;
        $out = array('pageviews' => 0, 'visits' => 0, 'visitors' => 0);
        if ($from === '' || $to === '') {
            return $out;
        }
        $start = self::span($from)[0];
        $end   = self::span($to)[1];
        foreach ($this->tables() as $table) {
            $keep = $this->keep($table);
            // Its own counts per day, added up: pageviews, and visits (each visitor one, as it keeps no other identity read here).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- another plugin's table by its dt index; only counts are read; $keep is fixed SQL.
            $row = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(d.pv), 0) AS pageviews, COALESCE(SUM(d.v), 0) AS visits FROM (SELECT COUNT(*) AS pv, COUNT(DISTINCT IF(visit_id > 0, visit_id, NULL)) + COALESCE(SUM(visit_id = 0), 0) AS v FROM %i WHERE dt >= %d AND dt < %d AND $keep GROUP BY FLOOR(dt / 86400)) d", $table, $start, $end), ARRAY_A);
            $out['pageviews'] += isset($row['pageviews']) ? (int) $row['pageviews'] : 0;
            $out['visits']    += isset($row['visits']) ? (int) $row['visits'] : 0;
        }
        $out['visitors'] = $out['visits'];
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
     * search landings, from each table read that has the day.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function day($day) {
        list($from, $to) = self::span($day);
        $sums   = array();
        $groups = array();
        foreach ($this->tables() as $table) {
            $this->read_table($table, $from, $to, $sums, $groups);
        }
        if (!isset($sums["\0"]) || $sums["\0"][2]['pageviews'] <= 0) {
            return array();
        }
        return array_merge(array_values($sums), self::visit_sources($groups));
    }

    /**
     * Add one table's rows of a day to the day's sums.
     *
     * @param string                                                 $table  Full name.
     * @param int                                                    $from   First dt.
     * @param int                                                    $to     dt after the day.
     * @param array<string,array{0:string,1:int|string,2:array<string,int>}> $sums   Rows by dimension and value.
     * @param array<int,array{r:string,q:string,e:string,metrics:array<string,int>}> $groups Visit groups for visit_sources().
     */
    private function read_table($table, $from, $to, array &$sums, array &$groups) {
        global $wpdb;
        $cols  = self::columns($table);
        $keep  = $this->keep($table);
        $time  = in_array('dt_out', $cols, true) ? 'LEAST(GREATEST(CAST(dt_out AS SIGNED) - CAST(dt AS SIGNED), 0), ' . self::PAGE_TIME . ')' : '0';
        $key   = 'IF(visit_id > 0, CAST(visit_id AS SIGNED), -CAST(id AS SIGNED))';
        // One row per visit: its pageviews, first and last row, and time.
        $visits = "(SELECT $key AS k, COUNT(*) AS n, MIN(id) AS f, MAX(id) AS l, SUM($time) AS s FROM %i WHERE dt >= %d AND dt < %d AND $keep GROUP BY k) x";
        $vsums  = 'COUNT(*) AS visitors, COUNT(*) AS visits, COALESCE(SUM(x.n), 0) AS pageviews, COALESCE(SUM(x.n <= 1), 0) AS bounces, COALESCE(SUM(x.s), 0) * 1000 AS engaged_ms';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- another plugin's table, one day by its dt index, joined by primary key; $visits, $vsums, $key, $time and $keep are fixed SQL. Only grouped names, addresses and counts are read, never its IP, user, fingerprint or user agent columns.
        $site = $wpdb->get_row($wpdb->prepare("SELECT $vsums FROM $visits", $table, $from, $to), ARRAY_A);
        if (!$site || (int) $site['pageviews'] <= 0) {
            return;
        }
        self::add($sums, '', 0, self::metrics($site));

        $pages = $wpdb->get_results($wpdb->prepare("SELECT resource AS v, COUNT(DISTINCT $key) AS visitors, COUNT(DISTINCT $key) AS visits, COUNT(*) AS pageviews, COALESCE(SUM($time), 0) * 1000 AS engaged_ms FROM %i WHERE dt >= %d AND dt < %d AND $keep GROUP BY resource ORDER BY pageviews DESC LIMIT %d", $table, $from, $to, self::ROWS), ARRAY_A);
        foreach ((array) $pages as $row) {
            self::add($sums, 'page', self::path((string) $row['v']), self::metrics($row));
        }

        // A visit's values are its first row's (its exit: its last row's).
        $dims = array(
            'entry'   => array('resource', 'f'),
            'exit'    => array('resource', 'l'),
            'browser' => array('browser', 'f'),
            'os'      => array('platform', 'f'),
            'device'  => array('browser_type', 'f'),
            'country' => array('country', 'f'),
        );
        foreach ($dims as $dimension => $pick) {
            $found = $wpdb->get_results($wpdb->prepare("SELECT r.%i AS v, $vsums FROM $visits INNER JOIN %i r ON r.id = x.%i GROUP BY r.%i ORDER BY visits DESC LIMIT %d", $pick[0], $table, $from, $to, $table, $pick[1], $pick[0], self::ROWS), ARRAY_A);
            foreach ((array) $found as $row) {
                self::add($sums, $dimension, self::value($dimension, (string) $row['v']), self::metrics($row));
            }
        }

        // Referrer with the first page (its campaign tags and the landing).
        $mixed = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(r.referer, '') AS r, COALESCE(r.resource, '') AS e, $vsums FROM $visits INNER JOIN %i r ON r.id = x.f GROUP BY r.referer, r.resource ORDER BY visits DESC LIMIT %d", $table, $from, $to, $table, self::ROWS * 5), ARRAY_A);
        // phpcs:enable
        $own = self::host(home_url());
        foreach ((array) $mixed as $row) {
            $entry    = (string) $row['e'];
            $referrer = (string) $row['r'];
            $groups[] = array(
                // Its own pages as the referrer: no source.
                'r'       => self::host($referrer) === $own ? '' : $referrer,
                'q'       => (string) wp_parse_url($entry, PHP_URL_QUERY),
                'e'       => self::path($entry),
                'metrics' => self::metrics($row),
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $opts = get_option(self::SETTINGS_OPTION, array());
        $out  = array();
        if (!is_array($opts)) {
            return $out;
        }
        $roles = self::roles_setting($opts);
        if ($roles) {
            $out[] = $roles;
        }
        $ips = self::ip_lines(isset($opts['ignore_ip']) && is_string($opts['ignore_ip']) ? $opts['ignore_ip'] : '');
        if ($ips) {
            $out[] = array(
                'key'   => 'exclude_ips',
                'label' => 'IP Addresses',
                /* translators: %d: number of addresses. */
                'from'  => sprintf(_n('%d address', '%d addresses', count($ips), 'seoprostats'), count($ips)),
                'value' => implode("\n", $ips),
                'also'  => array('exclusions' => true),
            );
        }
        if (isset($opts['do_not_track']) && $opts['do_not_track'] === 'on') {
            $out[] = array(
                'key'   => 'privacy_signals',
                'label' => 'Respect Do Not Track (DNT)',
                'from'  => __('On', 'seoprostats'),
                'value' => true,
            );
        }
        // Only a choice other than its default is carried over.
        if (isset($opts['auto_purge']) && (string) $opts['auto_purge'] !== '' && (int) $opts['auto_purge'] !== self::PURGE_DEFAULT) {
            $days  = (int) $opts['auto_purge'];
            $out[] = $days <= 0
                ? array(
                    'key'   => 'retention',
                    'label' => 'Retention Period',
                    'from'  => __('Off', 'seoprostats'),
                    'value' => false,
                )
                : array(
                    'key'   => 'retention_visits',
                    'label' => 'Retention Period',
                    /* translators: %d: number of days. */
                    'from'  => sprintf(_n('%d day', '%d days', $days, 'seoprostats'), $days),
                    'value' => max(1, min(120, (int) round($days / 30.4375))),
                );
        }
        return $out;
    }

    /**
     * Roles it leaves out: every role for its WP Users choice, else the
     * roles its capabilities list matches.
     *
     * @param array<string,mixed> $opts Its settings.
     * @return array<string,mixed>|null The setting; null when neither is set.
     */
    private static function roles_setting(array $opts) {
        if (isset($opts['ignore_wp_users']) && $opts['ignore_wp_users'] === 'on') {
            return array(
                'key'   => 'tracking_skip_roles',
                'label' => 'WP Users',
                'from'  => __('On', 'seoprostats'),
                'value' => array_keys(wp_roles()->get_names()),
            );
        }
        if (empty($opts['ignore_capabilities']) || !is_string($opts['ignore_capabilities'])) {
            return null;
        }
        return array(
            'key'   => 'tracking_skip_roles',
            'label' => 'Capabilities',
            'from'  => $opts['ignore_capabilities'],
            'value' => self::capability_roles($opts['ignore_capabilities']),
        );
    }

    /**
     * Roles named in its list, or with a capability in it (* any
     * characters).
     *
     * @param string $list Comma-separated roles and capabilities.
     * @return string[]
     */
    private static function capability_roles($list) {
        $patterns = array();
        foreach (array_filter(array_map('trim', explode(',', $list))) as $item) {
            $patterns[] = '@^' . str_replace(array('\\*', '\\!'), array('.*', '.'), preg_quote($item, '@')) . '$@i';
        }
        $roles = array();
        foreach (wp_roles()->roles as $role => $info) {
            $names_to_match = array_merge(array((string) $role), array_keys(array_filter(isset($info['capabilities']) ? (array) $info['capabilities'] : array())));
            foreach ($patterns as $pattern) {
                if (preg_grep($pattern, $names_to_match)) {
                    $roles[] = (string) $role;
                    break;
                }
            }
        }
        return $roles;
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall_setting() {
        $opts = get_option(self::SETTINGS_OPTION, array());
        if (!is_array($opts)) {
            return null;
        }
        // Its uninstall keeps the data only when the setting is there and not on.
        return array(
            'key'   => 'delete_data_on_uninstall',
            'label' => 'Delete Data on Uninstall',
            'where' => 'Slimstat → Settings → Maintenance',
            'on'    => !isset($opts['delete_data_on_uninstall']) || $opts['delete_data_on_uninstall'] === 'on',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function leftovers() {
        global $wpdb;
        $site   = array_map(function ($name) use ($wpdb) {
            return $wpdb->prefix . $name;
        }, self::TABLES);
        $shared = array_map(function ($name) use ($wpdb) {
            return $wpdb->base_prefix . $name;
        }, self::SHARED_TABLES);
        // Its GeoIP folder, in the main site's uploads on a network.
        $uploads = (string) wp_upload_dir(null, false)['basedir'];
        if (is_multisite()) {
            $uploads = (string) preg_replace('~/sites/\d+$~', '', untrailingslashit($uploads));
        }
        $folder    = self::files_present(array(trailingslashit($uploads) . 'wp-slimstat'));
        $user_meta = array();
        foreach (self::USER_META as $prefix) {
            $user_meta = array_merge($user_meta, self::user_meta_like($prefix));
        }
        $out = array(
            'tables'     => array_values(array_intersect(self::tables_like('slim_'), is_multisite() ? $site : array_merge($site, $shared))),
            'options'    => array_merge(self::options_like(array('slimstat_', 'wp_slimstat_')), self::options_exact(array('widget_slimstat_widget'))),
            'transients' => self::options_like(array('_transient_slimstat_', '_transient_timeout_slimstat_', '_transient_wp_slimstat_', '_transient_timeout_wp_slimstat_', '_transient_wp-slimstat-', '_transient_timeout_wp-slimstat-')),
            'cron'       => self::cron_like('wp_slimstat_'),
            'user_meta'  => is_multisite() ? array() : $user_meta,
            'files'      => is_multisite() ? array() : $folder,
            'network'    => array(),
        );
        if (is_multisite()) {
            // Shared by every site: listed, never deleted from one site.
            $found = array();
            foreach ($shared as $table) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing another plugin's network tables.
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
                    $found[] = $table;
                }
            }
            $out['network'] = array_filter(array(
                'tables'    => $found,
                'user_meta' => $user_meta,
                'files'     => $folder,
            ));
        }
        return $out;
    }

    /**
     * The tables read that are there with the columns read.
     *
     * @return string[]
     */
    private function tables() {
        global $wpdb;
        $out = array();
        foreach (self::READ as $name) {
            $table = $wpdb->prefix . $name;
            if (!array_diff(array('id', 'dt', 'visit_id', 'resource', 'referer', 'browser', 'browser_type', 'platform', 'country'), self::columns($table))) {
                $out[] = $table;
            }
        }
        return $out;
    }

    /**
     * The rows counted: not crawlers, not wp-admin pages (fixed SQL).
     *
     * @param string $table Full name.
     * @return string
     */
    private function keep($table) {
        $sql = '(browser_type IS NULL OR browser_type <> 1)';
        if (in_array('content_type', self::columns($table), true)) {
            $sql .= " AND (content_type IS NULL OR content_type <> 'admin')";
        }
        return $sql;
    }

    /**
     * A site day as its dt range (its local time stored as if UTC).
     *
     * @param string $day Y-m-d.
     * @return array{0:int,1:int}
     */
    private static function span($day) {
        $start = (int) strtotime($day . ' 00:00:00 UTC');
        return array($start, $start + DAY_IN_SECONDS);
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
     * A stored value as SEO Pro Stats's.
     *
     * @param string $dimension entry, exit, browser, os, device or country.
     * @param string $value     Its value.
     * @return int|string
     */
    private static function value($dimension, $value) {
        switch ($dimension) {
            case 'entry':
            case 'exit':
                return self::path($value);
            case 'device':
                // 2: mobile or tablet; 0 and 3 (a touch screen, not mobile): desktop.
                if ($value === '2') {
                    return self::device_code('mobile');
                }
                return in_array($value, array('0', '3'), true) ? self::device_code('desktop') : self::device_code('');
            case 'country':
                $code = strtoupper(trim($value));
                return preg_match('~^[A-Z]{2}$~', $code) && $code !== 'XX' ? $code : '';
            case 'os':
                $code = strtolower(trim($value));
                if (strpos($code, 'win') === 0) {
                    return 'Windows';
                }
                if (strpos($code, 'mac') === 0) {
                    return 'macOS';
                }
                return strpos($code, 'iphone') === 0 ? 'iOS' : self::os_name($code);
            default:
                return self::browser_name($value);
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
     * A stored address as SEO Pro Stats's path.
     *
     * @param string $uri Its resource (a path, with its query).
     * @return string
     */
    private static function path($uri) {
        if (strpos($uri, '//') !== false) {
            $path  = (string) wp_parse_url($uri, PHP_URL_PATH);
            $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
            $uri   = ($path === '' ? '/' : $path) . ($query !== '' ? '?' . $query : '');
        }
        return SEOProStats_Processor::split_url($uri === '' ? '/' : $uri)['path'];
    }
}
