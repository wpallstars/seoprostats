<?php
/**
 * Koko Analytics (koko-analytics), read from a real install of version
 * 2.5.3, the current one when this was written, and from its database
 * migrations for older layouts.
 *
 * What it keeps, in this site's tables (prefix koko_analytics_), already
 * counted per day in the site's time zone: site_stats (date, visitors,
 * pageviews), post_stats (per page: date, path_id into paths, post_id,
 * visitors, pageviews) and referrer_stats (per referrer: date, id into
 * referrer_labels, unique_hits, hits). Older layouts are read too:
 * before its 1.9.991 schema post_stats had only the post ID (column id,
 * later post_id with path_id still empty on large sites until its
 * command moved them), and before its 2.2.5 schema the referrers were
 * referrer_urls (url) with visitors and pageviews columns. It keeps no
 * visits: visitors are counted once a day (by a cookie or a daily
 * changing fingerprint, its setting), and nothing ties a referrer to a
 * page. Its settings are one option, koko_analytics_settings.
 *
 * When it is deactivated it removes its cron hooks and its optimised
 * endpoint file (koko-analytics-collect.php beside wp-config.php). When
 * it is deleted it removes some options and keeps its tables, its
 * migration option and its upload folder (a buffer of hits not yet
 * counted). It has no "delete data when uninstalled" setting: Settings →
 * Data → "Reset statistics" only empties its statistics.
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

final class SEOProStats_Migrate_Koko extends SEOProStats_Migrate_Source {

    const KEY   = 'koko-analytics';
    const NAME  = 'Koko Analytics';
    const SLUGS = array('koko-analytics');

    /** Its settings option. */
    const SETTINGS_OPTION = 'koko_analytics_settings';

    /** @var array<int,string> Post ID => path, for the oldest layout. */
    private $post_paths = array();

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $version = $this->installed_version();
        // Versions before 2.2.6 recorded their own.
        $out = array('version' => $version !== '' ? $version : (string) get_option('koko_analytics_version', ''), 'from' => '', 'to' => '');
        if (!$this->readable()) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table: MIN and MAX on its primary key (date) read one entry each.
        $span        = $wpdb->get_row($wpdb->prepare('SELECT MIN(date) AS a, MAX(date) AS b FROM %i', self::table('site_stats')), ARRAY_A);
        $out['from'] = isset($span['a']) ? (string) $span['a'] : '';
        $out['to']   = isset($span['b']) ? (string) $span['b'] : '';
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, one entry of its primary key (date).
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE date >= %s AND date < %s AND pageviews > 0 LIMIT 1', self::table('site_stats'), self::day_of($start), self::day_of($end)));
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
        // Its visitors are already counted per day; it keeps no visits.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table by its primary key (date); only counts are read.
        $row = $wpdb->get_row($wpdb->prepare('SELECT COALESCE(SUM(pageviews), 0) AS pageviews, COALESCE(SUM(visitors), 0) AS visitors FROM %i WHERE date >= %s AND date <= %s', self::table('site_stats'), $from, $to), ARRAY_A);
        $out['pageviews'] = isset($row['pageviews']) ? (int) $row['pageviews'] : 0;
        $out['visitors']  = isset($row['visitors']) ? (int) $row['visitors'] : 0;
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
     * One day's rows: the site's visitors and pageviews, pages, and
     * referrers as sources and channels. No visits, bounces or time: it
     * keeps none.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function day($day) {
        global $wpdb;
        $rows = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table by its primary key (date).
        $site = $wpdb->get_row($wpdb->prepare('SELECT visitors, pageviews FROM %i WHERE date = %s', self::table('site_stats'), $day), ARRAY_A);
        if (!$site || (int) $site['pageviews'] === 0) {
            return $rows;
        }
        $rows[] = array('', 0, self::metrics($site));
        $rows   = array_merge($rows, $this->pages($day), $this->referrers($day));
        return $rows;
    }

    /**
     * A day's pages, by path; in the oldest layout (or rows its command
     * has not moved yet) by post ID, whose address is looked up as it
     * did.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:string,2:array<string,int>}>
     */
    private function pages($day) {
        global $wpdb;
        $table = self::table('post_stats');
        $cols  = self::columns($table);
        $post  = '';
        if (in_array('post_id', $cols, true)) {
            $post = 'post_id';
        } elseif (in_array('id', $cols, true)) {
            $post = 'id';
        }
        if ($post === '' || !in_array('visitors', $cols, true) || !in_array('pageviews', $cols, true)) {
            return array();
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- another plugin's tables, one day by its primary key (date, then path or post), joined by primary key; only counts and paths are read.
        if (in_array('path_id', $cols, true) && self::table_exists(self::table('paths'))) {
            $found = $wpdb->get_results($wpdb->prepare('SELECT p.path AS path, s.%i AS post, SUM(s.visitors) AS visitors, SUM(s.pageviews) AS pageviews FROM %i s LEFT JOIN %i p ON p.id = s.path_id WHERE s.date = %s GROUP BY p.path, s.%i ORDER BY pageviews DESC LIMIT %d', $post, $table, self::table('paths'), $day, $post, self::ROWS), ARRAY_A);
        } else {
            $found = $wpdb->get_results($wpdb->prepare('SELECT NULL AS path, s.%i AS post, SUM(s.visitors) AS visitors, SUM(s.pageviews) AS pageviews FROM %i s WHERE s.date = %s GROUP BY s.%i ORDER BY pageviews DESC LIMIT %d', $post, $table, $day, $post, self::ROWS), ARRAY_A);
        }
        // phpcs:enable
        $rows = array();
        foreach ((array) $found as $row) {
            $path   = isset($row['path']) && (string) $row['path'] !== '' ? (string) $row['path'] : $this->post_path((int) $row['post']);
            $rows[] = array('page', self::path($path), self::metrics($row));
        }
        return $rows;
    }

    /**
     * A day's referrers as sources (the host) and channels. Its unique
     * hits are the visitors, its hits the pageviews that arrived from it.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function referrers($day) {
        global $wpdb;
        $stats = self::table('referrer_stats');
        $cols  = self::columns($stats);
        $label = array(self::table('referrer_labels'), 'value');
        if (!self::table_exists($label[0])) {
            $label = array(self::table('referrer_urls'), 'url');
        }
        $unique = in_array('unique_hits', $cols, true) ? 'unique_hits' : 'visitors';
        $hits   = in_array('hits', $cols, true) ? 'hits' : 'pageviews';
        if (!in_array($unique, $cols, true) || !in_array($hits, $cols, true) || !in_array($label[1], self::columns($label[0]), true)) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's tables, one day by its primary key (date, id), joined by primary key; only counts and referrer names are read.
        $found = $wpdb->get_results($wpdb->prepare('SELECT l.%i AS v, SUM(r.%i) AS visitors, SUM(r.%i) AS pageviews FROM %i r INNER JOIN %i l ON l.id = r.id WHERE r.date = %s GROUP BY l.%i ORDER BY pageviews DESC LIMIT %d', $label[1], $unique, $hits, $stats, $label[0], $day, $label[1], self::ROWS * 5), ARRAY_A);
        return self::top_sources(self::referrer_sums((array) $found));
    }

    /**
     * Referrer rows summed by host as sources and by channel.
     *
     * @param array<int,array<string,mixed>> $found Rows: v (the referrer), visitors, pageviews.
     * @return array<string,array{0:string,1:int|string,2:array<string,int>}> Dimension and value => row (hosts, and channels as their codes).
     */
    private static function referrer_sums(array $found) {
        $sums = array();
        foreach ($found as $row) {
            $host = self::host((string) $row['v']);
            if ($host === '') {
                continue;
            }
            $metrics = self::metrics($row);
            foreach (array(array('source', $host), array('channel', SEOProStats_Channels::classify($host, array()))) as $item) {
                self::add_referrer($sums, $item[0], $item[1], $metrics);
            }
        }
        return $sums;
    }

    /**
     * Add metrics to one dimension value's row of referrer_sums().
     *
     * @param array<string,array{0:string,1:int|string,2:array<string,int>}> $sums      Dimension and value => row; added to.
     * @param string                                                         $dimension source or channel.
     * @param int|string                                                     $value     Host, or channel code.
     * @param array<string,int>                                              $metrics   visitors and pageviews.
     * @return void
     */
    private static function add_referrer(array &$sums, $dimension, $value, array $metrics) {
        $key = $dimension . "\0" . $value;
        if (!isset($sums[$key])) {
            $sums[$key] = array($dimension, $value, array('visitors' => 0, 'pageviews' => 0));
        }
        foreach ($metrics as $name => $count) {
            $sums[$key][2][$name] += $count;
        }
    }

    /**
     * The top hosts, and every channel.
     *
     * @param array<string,array{0:string,1:int|string,2:array<string,int>}> $sums referrer_sums().
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private static function top_sources(array $sums) {
        $sources  = array_values(array_filter($sums, function ($row) {
            return $row[0] === 'source';
        }));
        $channels = array_values(array_filter($sums, function ($row) {
            return $row[0] === 'channel';
        }));
        usort($sources, function ($a, $b) {
            return $b[2]['pageviews'] - $a[2]['pageviews'];
        });
        return array_merge(array_slice($sources, 0, self::ROWS), $channels);
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $koko = get_option(self::SETTINGS_OPTION, array());
        $koko = is_array($koko) ? $koko : array();
        $out  = array();
        // Its default counts everyone; only roles it leaves out are carried over.
        if (!empty($koko['exclude_user_roles']) && is_array($koko['exclude_user_roles'])) {
            $roles = array_values(array_intersect(array_map('strval', $koko['exclude_user_roles']), array_keys(wp_roles()->get_names())));
            if ($roles) {
                $out[] = array(
                    'key'   => 'tracking_skip_roles',
                    'label' => 'Exclude pageviews from these user roles',
                    'from'  => self::role_names($roles),
                    'value' => $roles,
                );
            }
        }
        $ips = isset($koko['exclude_ip_addresses']) ? $koko['exclude_ip_addresses'] : array();
        $ips = is_array($ips) ? implode(' ', array_map('strval', $ips)) : (string) $ips;
        $ips = array_values(array_filter(array_map('trim', explode(' ', str_replace(array("\r", "\n", "\t", ','), ' ', $ips)))));
        if ($ips) {
            $out[] = array(
                'key'   => 'exclude_ips',
                'label' => 'Exclude pageviews from these IP addresses',
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
    public function leftovers() {
        $uploads = wp_upload_dir(null, false);
        $out     = array(
            'tables'     => self::tables_like('koko_analytics_'),
            'options'    => array_merge(self::options_like(array('koko_analytics_')), self::options_exact(array('widget_koko-analytics-most-viewed-posts'))),
            // Its own, its counter shortcode's (ka_counter_) and its update lock's.
            'transients' => self::options_like(array('_transient_koko_analytics_', '_transient_timeout_koko_analytics_', '_transient_ka_counter_', '_transient_timeout_ka_counter_')),
            'cron'       => self::cron_like('koko_analytics_'),
            'user_meta'  => is_multisite() ? array() : self::user_meta_like('_koko_analytics_'),
            'files'      => self::files_present(array(trailingslashit((string) $uploads['basedir']) . 'koko-analytics')),
            'network'    => array(),
        );
        if (is_multisite()) {
            // Shared by every site: listed, never deleted from one site.
            $out['network'] = array_filter(array(
                'user_meta' => self::user_meta_like('_koko_analytics_'),
            ));
        }
        return $out;
    }

    /**
     * Whether its site totals table is there with the columns read.
     *
     * @return bool
     */
    private function readable() {
        $cols = self::columns(self::table('site_stats'));
        return $cols && !array_diff(array('date', 'visitors', 'pageviews'), $cols);
    }

    /**
     * A post's path, as its own migration worked it out: the home page
     * for 0, else the permalink's path and query, else ?p=ID.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function post_path($post_id) {
        if (!isset($this->post_paths[$post_id])) {
            $url = $post_id > 0 ? get_permalink($post_id) : home_url('/');
            $url = $url ? (string) $url : home_url('/?p=' . $post_id);
            $path  = (string) wp_parse_url($url, PHP_URL_PATH);
            $query = (string) wp_parse_url($url, PHP_URL_QUERY);
            $this->post_paths[$post_id] = ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');
        }
        return $this->post_paths[$post_id];
    }

    /**
     * One of its tables on this site.
     *
     * @param string $name Name after koko_analytics_.
     * @return string
     */
    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'koko_analytics_' . $name;
    }

    /**
     * Counts of a result row, as integers.
     *
     * @param array<string,mixed> $row Row.
     * @return array<string,int>
     */
    private static function metrics(array $row) {
        $out = array();
        foreach (array('visitors', 'pageviews') as $key) {
            $out[$key] = isset($row[$key]) ? max(0, (int) $row[$key]) : 0;
        }
        return $out;
    }

    /**
     * A stored path as SEO Pro Stats's path.
     *
     * @param string $path Its path (with a query when it kept one).
     * @return string
     */
    private static function path($path) {
        return SEOProStats_Processor::split_url($path === '' ? '/' : $path)['path'];
    }
}
