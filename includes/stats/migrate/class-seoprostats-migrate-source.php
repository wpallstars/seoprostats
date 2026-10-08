<?php
/**
 * A statistics plugin SEO Pro Stats can import history from
 * (SEOProStats_Migrate). One class per plugin: it finds the plugin's data
 * on the site, reads it a day at a time as counts, maps its settings to
 * ours, and lists what the plugin leaves behind.
 *
 * Adapters only read: they aggregate in SQL and read only the aggregate,
 * never IP addresses (raw or hashed), visitor or user IDs, user agent
 * strings or anything typed into forms. The only writes to another
 * plugin's data are SEOProStats_Migrate::cleanup()'s, of exactly what
 * leftovers() lists. Design: docs/architecture.md → Moving from other
 * statistics plugins.
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

abstract class SEOProStats_Migrate_Source {

    /** The imports.source value (at most 20 characters). */
    const KEY = '';

    /** The plugin's name. */
    const NAME = '';

    /** Plugin folders it is installed in. */
    const SLUGS = array();

    /** Most values per dimension and day (the top ones). */
    const ROWS = 1000;

    /** @var array<string,string[]> Columns by table, read once a request. */
    private static $columns = array();

    /**
     * Whether its data is on the site, judged from its tables and options,
     * cheaply (no full scans): version (as it recorded it, '' unknown),
     * first and last day with statistics ('' none).
     *
     * @return array{version:string,from:string,to:string}
     */
    abstract public function detect();

    /**
     * Whether it has statistics between two times.
     *
     * @param int $start Unix time (included).
     * @param int $end   Unix time (excluded).
     * @return bool
     */
    abstract protected function has_data($start, $end);

    /**
     * Per day, the site's totals and the top values of each dimension it
     * has. Each row: array(dimension, value, metrics). Dimension: '' for
     * the site, a SEOProStats_Rollup::DIMS name, or 'landing' (visits from
     * organic search by entry page, SEOProStats_Rollup::SEARCH_LANDING).
     * Value: a path (page, entry, exit, landing), a host without www.
     * (source), a code (channel, device: SEOProStats_Query::CHANNELS and
     * DEVICES; country: two letters) or a name (browser, os, utm_*).
     * Metrics: visitors, visits, pageviews, bounces, engaged_ms, events
     * and scroll, as the daily table counts them.
     *
     * @param string $from First day (Y-m-d, site time zone).
     * @param string $to   Last day.
     * @return array<string,array<int,array{0:string,1:int|string,2:array<string,int>}>> Day => rows.
     */
    abstract public function days($from, $to);

    /**
     * The plugin's own totals for a range, for the check after import:
     * pageviews, visits, and visitors as it counts them (once across the
     * range).
     *
     * @param string $from First day.
     * @param string $to   Last day.
     * @return array{pageviews:int,visits:int,visitors:int}
     */
    abstract public function totals($from, $to);

    /**
     * Exactly what it leaves on this site now: tables (with this site's
     * prefix), options, transients (their option names), cron hooks, user
     * meta keys and files or folders (relative to wp-content). network:
     * the same lists for what is shared by the whole network (multisite),
     * which cleanup only lists.
     *
     * @return array{tables:string[],options:string[],transients:string[],cron:string[],user_meta:string[],files:string[],network:array<string,string[]>}
     */
    abstract public function leftovers();

    /**
     * Distinct values per dimension over a range, for the dry run.
     *
     * @param string $from First day.
     * @param string $to   Last day.
     * @return array<string,int> Dimension => values.
     */
    public function values($from, $to) {
        unset($from, $to);
        return array();
    }

    /**
     * Its settings with an equivalent of ours: each with key (ours),
     * label (its own name for it), from (its value, in words), value (ours
     * would be), and also (more of ours that go with it, key => value).
     * Only settings it has set are listed.
     *
     * @return array<int,array{key:string,label:string,from:string,value:mixed,also?:array<string,mixed>}>
     */
    public function settings() {
        return array();
    }

    /**
     * Its own "delete data when uninstalled" setting, if it has one: key,
     * label, where (where to find it in its settings) and on (whether it
     * is on). Null when it has none.
     *
     * @return array{key:string,label:string,where:string,on:bool}|null
     */
    public function uninstall_setting() {
        return null;
    }

    /**
     * The plugin's file and state on this site: active (here), network
     * (for the whole network), inactive, or missing (not installed).
     *
     * @return array{file:string,state:string}
     */
    public function plugin() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $file = '';
        foreach (array_keys(get_plugins()) as $candidate) {
            if (in_array(dirname((string) $candidate), static::SLUGS, true)) {
                $file = (string) $candidate;
                break;
            }
        }
        if ($file === '') {
            // A plugin active in a folder of another name.
            foreach ((array) get_option('active_plugins', array()) as $active) {
                if (in_array(dirname((string) $active), static::SLUGS, true)) {
                    $file = (string) $active;
                }
            }
        }
        if ($file === '') {
            return array('file' => '', 'state' => 'missing');
        }
        if (is_multisite() && is_plugin_active_for_network($file)) {
            return array('file' => $file, 'state' => 'network');
        }
        return array('file' => $file, 'state' => is_plugin_active($file) ? 'active' : 'inactive');
    }

    /**
     * Days with statistics in a range, oldest first (one indexed probe a
     * day).
     *
     * @param string $from First day (Y-m-d).
     * @param string $to   Last day.
     * @return string[]
     */
    public function day_list($from, $to) {
        $out = array();
        if ($from === '' || $to === '' || $from > $to) {
            return $out;
        }
        $tz  = wp_timezone();
        $day = new DateTimeImmutable($from, $tz);
        $end = new DateTimeImmutable($to, $tz);
        while ($day <= $end) {
            $next = $day->modify('+1 day');
            if ($this->has_data($day->getTimestamp(), $next->getTimestamp())) {
                $out[] = $day->format('Y-m-d');
            }
            $day = $next;
        }
        return $out;
    }

    /**
     * A day's start and the next day's, in the site's time zone.
     *
     * @param string $day Y-m-d.
     * @return array{0:int,1:int}
     */
    public static function bounds($day) {
        $start = new DateTimeImmutable($day, wp_timezone());
        return array($start->getTimestamp(), $start->modify('+1 day')->getTimestamp());
    }

    /**
     * A Unix time as a site-local day.
     *
     * @param int $time Unix time.
     * @return string Y-m-d, or '' for none.
     */
    protected static function day_of($time) {
        $time = (int) $time;
        return $time > 0 ? (string) wp_date('Y-m-d', $time) : '';
    }

    /**
     * Whether a table exists.
     *
     * @param string $table Full name.
     * @return bool
     */
    protected static function table_exists($table) {
        return (bool) self::columns($table);
    }

    /**
     * A table's columns ([] when it does not exist), read once a request.
     *
     * @param string $table Full name.
     * @return string[]
     */
    protected static function columns($table) {
        global $wpdb;
        if (!isset(self::$columns[$table])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table layout; cached for the request.
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table layout; cached for the request.
            self::$columns[$table] = $exists ? array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table))) : array();
        }
        return self::$columns[$table];
    }

    /**
     * Forget the tables read (after cleanup drops some).
     */
    public static function forget_tables() {
        self::$columns = array();
    }

    /**
     * This site's tables whose names start with a prefix (after the site's
     * table prefix).
     *
     * @param string $prefix Such as burst_.
     * @return string[]
     */
    protected static function tables_like($prefix) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing another plugin's tables for the Import tab and cleanup.
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $prefix) . '%'));
        sort($tables);
        return array_values(array_map('strval', $tables));
    }

    /**
     * Option names that start with any of some prefixes (the option_name
     * key reads them), without transients.
     *
     * @param string[] $prefixes Prefixes.
     * @param string   $table    options or sitemeta (multisite network).
     * @return string[]
     */
    protected static function options_like(array $prefixes, $table = 'options') {
        global $wpdb;
        $column = $table === 'sitemeta' ? 'meta_key' : 'option_name';
        $out    = array();
        foreach ($prefixes as $prefix) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing another plugin's options by the unique option_name key (prefix).
            $names = (array) $wpdb->get_col($wpdb->prepare('SELECT %i FROM %i WHERE %i LIKE %s', $column, $table === 'sitemeta' ? $wpdb->sitemeta : $wpdb->options, $column, $wpdb->esc_like($prefix) . '%'));
            $out   = array_merge($out, array_map('strval', $names));
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Scheduled cron hooks that start with a prefix.
     *
     * @param string $prefix Prefix.
     * @return string[]
     */
    protected static function cron_like($prefix) {
        $out = array();
        foreach ((array) _get_cron_array() as $hooks) {
            foreach (array_keys((array) $hooks) as $hook) {
                if (strpos((string) $hook, $prefix) === 0) {
                    $out[(string) $hook] = true;
                }
            }
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * User meta keys that start with a prefix (the meta_key index).
     *
     * @param string $prefix Prefix.
     * @return string[]
     */
    protected static function user_meta_like($prefix) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing another plugin's user meta keys by the meta_key index (prefix).
        $keys = (array) $wpdb->get_col($wpdb->prepare('SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like($prefix) . '%'));
        sort($keys);
        return array_values(array_map('strval', $keys));
    }

    /**
     * Files and folders that exist, relative to wp-content.
     *
     * @param string[] $paths Absolute paths.
     * @return string[]
     */
    protected static function files_present(array $paths) {
        $out  = array();
        $base = wp_normalize_path(trailingslashit(WP_CONTENT_DIR));
        foreach ($paths as $path) {
            $path = wp_normalize_path($path);
            if (strpos($path, $base) === 0 && (is_file($path) || is_dir($path))) {
                $out[] = substr($path, strlen($base)) . (is_dir($path) ? '/' : '');
            }
        }
        return $out;
    }

    /**
     * A referrer as the source dimension stores it: the host, lower case,
     * without www. ('' for none).
     *
     * @param string $referrer Host or address.
     * @return string
     */
    public static function host($referrer) {
        $referrer = strtolower(trim((string) $referrer));
        if ($referrer === '') {
            return '';
        }
        if (strpos($referrer, '//') !== false) {
            $referrer = (string) wp_parse_url($referrer, PHP_URL_HOST);
        } else {
            $referrer = (string) preg_replace('~[/?#].*$~', '', $referrer);
        }
        $referrer = trim($referrer, '. ');
        return strpos($referrer, 'www.') === 0 ? (string) substr($referrer, 4) : $referrer;
    }
}
