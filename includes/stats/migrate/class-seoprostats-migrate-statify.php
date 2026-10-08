<?php
/**
 * Statify (statify), read from a real install of version 2.0.3, the
 * current one when this was written; its table has had the same layout
 * since its first versions.
 *
 * What it keeps, in one table of this site (statify): a row per pageview
 * with its day (created, the site's time zone, indexed), the page (target,
 * a path; with its query only on sites without pretty permalinks) and the
 * address of another site it came from (referrer, '' for none). Nothing
 * else: no visitors, visits or time, so only pageviews are imported, and
 * only for as long as it keeps them (its "Period of data saving", 14 days
 * unless changed). Its settings are one option, statify.
 *
 * When it is deactivated it removes its cron hook and transient. When it
 * is deleted it removes its option and its table on every site, so its
 * history goes with it: import first. It has no setting for that.
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

final class SEOProStats_Migrate_Statify extends SEOProStats_Migrate_Source {

    const KEY   = 'statify';
    const NAME  = 'Statify';
    const SLUGS = array('statify');

    /** Its settings option. */
    const SETTINGS_OPTION = 'statify';

    /** Its "Logged in users" choices: skip all of them, skip administrators. */
    const SKIP_ALL   = 1;
    const SKIP_ADMIN = 2;

    /** Its rows' empty day. */
    const NO_DAY = '1000-01-01';

    /**
     * {@inheritDoc}
     */
    public function detect() {
        global $wpdb;
        $out = array('version' => $this->installed_version(), 'from' => '', 'to' => '');
        if (!$this->readable()) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table: MIN and MAX on its created index read one entry each.
        $span        = $wpdb->get_row($wpdb->prepare('SELECT MIN(created) AS a, MAX(created) AS b FROM %i WHERE created >= %s', self::table(), self::NO_DAY), ARRAY_A);
        $out['from'] = isset($span['a']) ? (string) $span['a'] : '';
        $out['to']   = isset($span['b']) ? (string) $span['b'] : '';
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, one entry of its created index.
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE created >= %s AND created < %s LIMIT 1', self::table(), self::day_of($start), self::day_of($end)));
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
        // It keeps pageviews only.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- another plugin's table, counted on its created index.
        $out['pageviews'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE created >= %s AND created <= %s', self::table(), $from, $to));
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
     * One day's rows: pageviews of the site, of each page, and of each
     * referring host and its channel, counted in SQL on the created index.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>
     */
    private function day($day) {
        global $wpdb;
        $table = self::table();
        $rows  = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- another plugin's table, one day by its created index; only counts, paths and referrers are read.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE created = %s', $table, $day));
        if ($total === 0) {
            return $rows;
        }
        $rows[] = array('', 0, array('pageviews' => $total));

        $pages = $wpdb->get_results($wpdb->prepare('SELECT target AS v, COUNT(*) AS pageviews FROM %i WHERE created = %s GROUP BY target ORDER BY pageviews DESC LIMIT %d', $table, $day, self::ROWS), ARRAY_A);
        foreach ((array) $pages as $row) {
            $rows[] = array('page', self::path((string) $row['v']), array('pageviews' => (int) $row['pageviews']));
        }

        // Referrers are whole addresses: added up by host, then channel.
        $found = $wpdb->get_results($wpdb->prepare("SELECT referrer AS v, COUNT(*) AS pageviews FROM %i WHERE created = %s AND referrer <> '' GROUP BY referrer ORDER BY pageviews DESC LIMIT %d", $table, $day, self::ROWS * 5), ARRAY_A);
        // phpcs:enable
        $hosts    = array();
        $channels = array();
        foreach ((array) $found as $row) {
            $host = self::host((string) $row['v']);
            if ($host === '') {
                continue;
            }
            $hosts[$host] = (isset($hosts[$host]) ? $hosts[$host] : 0) + (int) $row['pageviews'];
        }
        arsort($hosts);
        foreach ($hosts as $host => $count) {
            $channel            = SEOProStats_Channels::classify((string) $host, array());
            $channels[$channel] = (isset($channels[$channel]) ? $channels[$channel] : 0) + $count;
        }
        foreach (array_slice($hosts, 0, self::ROWS, true) as $host => $count) {
            $rows[] = array('source', (string) $host, array('pageviews' => $count));
        }
        foreach ($channels as $channel => $count) {
            $rows[] = array('channel', $channel, array('pageviews' => $count));
        }
        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    public function settings() {
        $statify = get_option(self::SETTINGS_OPTION, array());
        $out     = array();
        if (!is_array($statify) || !isset($statify['skip']['logged_in'])) {
            return $out;
        }
        $skip  = (int) $statify['skip']['logged_in'];
        $roles = array_keys(wp_roles()->get_names());
        if ($skip === self::SKIP_ALL) {
            $out[] = array(
                'key'   => 'tracking_skip_roles',
                'label' => 'Logged in users',
                'from'  => 'Skip all users',
                'value' => $roles,
            );
        } elseif ($skip === self::SKIP_ADMIN && in_array('administrator', $roles, true)) {
            $out[] = array(
                'key'   => 'tracking_skip_roles',
                'label' => 'Logged in users',
                'from'  => 'Skip administrators',
                'value' => array('administrator'),
            );
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function leftovers() {
        $table = self::table();
        return array(
            'tables'     => self::table_exists($table) ? array($table) : array(),
            'options'    => array_values(array_intersect(self::options_like(array(self::SETTINGS_OPTION)), array(self::SETTINGS_OPTION))),
            'transients' => array_values(array_intersect(self::options_like(array('_transient_statify_data', '_transient_timeout_statify_data')), array('_transient_statify_data', '_transient_timeout_statify_data'))),
            'cron'       => array_values(array_intersect(self::cron_like('statify_cleanup'), array('statify_cleanup'))),
            'user_meta'  => array(),
            'files'      => array(),
            'network'    => array(),
        );
    }

    /**
     * Whether its table is there with the columns read.
     *
     * @return bool
     */
    private function readable() {
        $cols = self::columns(self::table());
        return $cols && !array_diff(array('created', 'referrer', 'target'), $cols);
    }

    /**
     * Its table on this site.
     *
     * @return string
     */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'statify';
    }

    /**
     * A stored page as SEO Pro Stats's path.
     *
     * @param string $target Its target (a path, maybe with a query).
     * @return string
     */
    private static function path($target) {
        return SEOProStats_Processor::split_url($target === '' ? '/' : $target)['path'];
    }
}
