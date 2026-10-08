<?php
/**
 * Jetpack Stats (the jetpack plugin, or the standalone jetpack-stats),
 * built from Jetpack's open-source code without a connected test site.
 * People who use Jetpack Stats test it; `wp seoprostats migrate run
 * jetpack --dry-run --debug` shows them what to report.
 *
 * Its statistics are not on the site: WordPress.com keeps them and
 * serves them to the connected site. Read from Jetpack 16.3 (its stats
 * package 0.22.1; Automattic/jetpack, trunk, October 2026) and the
 * WordPress.com Stats screens (Automattic/wp-calypso, trunk):
 *
 * - projects/packages/stats/src/class-wpcom-stats.php: the endpoint is
 *   /sites/{Jetpack_Options::get_option('id')}/stats/{resource}
 *   (build_endpoint()), asked with
 *   Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog($endpoint, '1.1', array('timeout' => 20)),
 *   the query added with http_build_query() (fetch_remote_stats()). The
 *   token errors missing_token, no_possible_tokens, malformed_token,
 *   invalid_token, unknown_token and signature_mismatch mean the site is
 *   not connected. fetch_stats() keeps every answer in a
 *   jetpack_restapi_stats_cache_* transient (and fetch_post_stats() in
 *   _jetpack_restapi_stats_cache_ post meta), so it is not used: a request
 *   per day of history would leave hundreds of them.
 * - projects/packages/stats/src/class-main.php should_track(): it counts
 *   only while (new Automattic\Jetpack\Connection\Manager())->is_connected()
 *   and (new Automattic\Jetpack\Modules())->is_active('stats'), the same
 *   two checks used here; logged-in people only when one of their roles
 *   is in the stats_options option's count_roles (class-options.php; empty
 *   by default).
 * - projects/packages/stats/src/abilities/class-stats-abilities.php
 *   (get_visits(), get_top_content()) and wp-calypso
 *   client/state/stats/lists/utils.js (statsTopPosts, statsReferrers,
 *   statsCountryViews) read the answers:
 *   - stats/visits with unit=day, quantity (at most 90), date and
 *     stat_fields=views,visitors: fields (names, period included) and
 *     data (a row per day in the order of fields; period is Y-m-d).
 *   - stats/top-posts, stats/referrers, stats/country-views with
 *     period=day, date, num=1, max (at most 100): days[date] holds the
 *     day's list. top-posts: postviews[] (id, title, views, href; ID 0 is
 *     "Home page / Archives"). referrers: groups[] (group, name, total,
 *     url sometimes, results[]: name, url, views, children[]).
 *     country-views: views[] (country_code, views; A1, A2 and ZZ are
 *     unknown places).
 *
 * Not proven without a connected site, for testers to confirm: that a
 * visits window ends on its date (the windows here follow the days the
 * answer holds, so either way works), and that days are the site's own.
 *
 * Imported per day: views as pageviews; visitors as visitors and as
 * visits (it has no visits); views of each post and page (its current
 * address; posts deleted since are left out), of each referring host and
 * its channel, and of each country. It keeps no devices, browsers or
 * systems, and its search terms are mostly hidden, so they are left out.
 *
 * Requests: only in cron and WP-CLI, never on visitor pages, screen loads
 * or the REST API. The daily views and visitors are looked up once (90
 * days a request, back to the first day with views, stopping after 180
 * days without any) and kept in the seoprostats_migrate_jetpack option
 * (counts only), refreshed daily. The lists are fetched a day at a time
 * by the import job, which waits and tries again after errors and rate
 * limits.
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

final class SEOProStats_Migrate_Jetpack extends SEOProStats_Migrate_Source {

    const KEY   = 'jetpack';
    const NAME  = 'Jetpack Stats';
    const SLUGS = array('jetpack', 'jetpack-stats');

    /** Full Jetpack's main file (it does other jobs: Stats is switched off instead). */
    const JETPACK_FILE = 'jetpack/jetpack.php';

    /** Its options read: active modules (when its Modules class is not loaded). */
    const MODULES_OPTION = 'jetpack_active_modules';
    const STATS_OPTION   = 'stats_options';

    /** Its caches (WPCOM_Stats::STATS_CACHE_TRANSIENT_PREFIX; post meta with a leading _). */
    const CACHE_PREFIX = 'jetpack_restapi_stats_cache_';

    /** Our copy of its daily views and visitors (counts only). */
    const SERIES_OPTION = 'seoprostats_migrate_jetpack';

    /** WordPress.com REST API version and the timeout (seconds), as Jetpack's. */
    const API     = '1.1';
    const TIMEOUT = 20;

    /** Days per stats/visits request, and items per list (Jetpack's own limits). */
    const WINDOW = 90;
    const MAX    = 100;

    /** Empty windows in a row that end the look back, and the earliest day looked at. */
    const EMPTY_WINDOWS = 2;
    const FLOOR         = '2005-01-01';

    /** Seconds a look may take in cron (WP-CLI: LOOKUP_CLI). */
    const LOOKUP_BUDGET = 15;
    const LOOKUP_CLI    = 600;

    /** Seconds before looking again after WordPress.com failed. */
    const LOOKUP_WAIT = 15 * MINUTE_IN_SECONDS;

    /** Its error codes for a site that is not (or no longer) connected. */
    const TOKEN_ERRORS = array('missing_token', 'no_possible_tokens', 'malformed_token', 'invalid_token', 'unknown_token', 'signature_mismatch', 'site_not_connected');

    /** Requests kept for --debug. */
    const LOG_SIZE = 50;

    /** @var array<int,array<string,string|int>> Requests made in this request, for --debug. */
    private static $log = array();

    /** @var array<int,string> Post paths by ID, this request. */
    private static $paths = array();

    /**
     * {@inheritDoc}
     *
     * Its days, from our copy (looked up or refreshed first where
     * requests are allowed).
     */
    public function detect() {
        $out = array('version' => $this->installed_version(), 'from' => '', 'to' => '');
        if (!self::ready()) {
            return $out;
        }
        $this->look();
        $days = $this->series()['days'];
        if ($days) {
            $out['from'] = (string) min(array_keys($days));
            $out['to']   = (string) max(array_keys($days));
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * No requests: options and Jetpack's own loaded classes only.
     */
    public function unavailable() {
        $state = $this->plugin()['state'];
        if ($state === 'missing' || $state === 'inactive') {
            return '';
        }
        if (!self::client_ready()) {
            return __('Update Jetpack: this version cannot hand its statistics to SEO Pro Stats.', 'seoprostats');
        }
        if (!self::connected() || self::blog_id() <= 0) {
            return __('Connect Jetpack to WordPress.com first: its statistics are kept there, not on this site.', 'seoprostats');
        }
        if (!self::stats_on()) {
            return '';
        }
        $series = $this->series();
        if (empty($series['complete'])) {
            return (int) $series['wait'] > time()
                ? __('WordPress.com did not answer. SEO Pro Stats looks up its history again in a few minutes.', 'seoprostats')
                : __('SEO Pro Stats is looking up its history on WordPress.com in the background. Look again in a minute.', 'seoprostats');
        }
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function has_data($start, $end) {
        return isset($this->series()['days'][self::day_of($start)]);
    }

    /**
     * {@inheritDoc}
     *
     * From our copy of its daily counts: no requests.
     */
    public function totals($from, $to) {
        $out = array('pageviews' => 0, 'visits' => 0, 'visitors' => 0);
        foreach ($this->series()['days'] as $day => $counts) {
            if ($day >= $from && $day <= $to) {
                $out['pageviews'] += (int) $counts[0];
                $out['visitors']  += (int) $counts[1];
            }
        }
        // It has no visits: each visitor's day is one.
        $out['visits'] = $out['visitors'];
        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * Three requests a day. A WP_Error when WordPress.com cannot answer
     * now: its data holds retry (seconds to wait; 0: not in this request)
     * unless trying again cannot help.
     */
    public function days($from, $to) {
        $out = array();
        foreach ($this->day_list($from, $to) as $day) {
            $rows = $this->day($day);
            if (is_wp_error($rows)) {
                return $rows;
            }
            $out[$day] = $rows;
        }
        return $out;
    }

    /**
     * One day: its views and visitors (from our copy), and the views of its
     * posts and pages, referrers and countries.
     *
     * @param string $day Y-m-d.
     * @return array<int,array{0:string,1:int|string,2:array<string,int>}>|WP_Error
     */
    private function day($day) {
        $series = $this->series()['days'];
        if (!isset($series[$day])) {
            return array();
        }
        $visitors = (int) $series[$day][1];
        $rows     = array(array('', 0, array('visitors' => $visitors, 'visits' => $visitors, 'pageviews' => (int) $series[$day][0])));
        $args     = array('period' => 'day', 'date' => $day, 'num' => 1, 'max' => self::MAX);

        $posts = $this->request('top-posts', $args);
        if (is_wp_error($posts)) {
            return $posts;
        }
        $pages = array();
        foreach (self::listed($posts, $day, 'postviews') as $item) {
            $path  = self::post_path(isset($item['id']) ? (int) $item['id'] : 0, isset($item['href']) ? (string) $item['href'] : '');
            $views = isset($item['views']) ? (int) $item['views'] : 0;
            if ($path !== '' && $views > 0) {
                $pages[$path] = (isset($pages[$path]) ? $pages[$path] : 0) + $views;
            }
        }
        arsort($pages);
        foreach (array_slice($pages, 0, self::ROWS, true) as $path => $views) {
            $rows[] = array('page', (string) $path, array('pageviews' => $views));
        }

        $referrers = $this->request('referrers', $args);
        if (is_wp_error($referrers)) {
            return $referrers;
        }
        $own   = self::host(home_url());
        $hosts = array();
        foreach (self::listed($referrers, $day, 'groups') as $group) {
            foreach (self::referrer_hosts($group) as $host => $views) {
                if ($host !== $own) {
                    $hosts[$host] = (isset($hosts[$host]) ? $hosts[$host] : 0) + $views;
                }
            }
        }
        arsort($hosts);
        $channels = array();
        foreach ($hosts as $host => $views) {
            $channel            = SEOProStats_Channels::classify((string) $host, array());
            $channels[$channel] = (isset($channels[$channel]) ? $channels[$channel] : 0) + $views;
        }
        foreach (array_slice($hosts, 0, self::ROWS, true) as $host => $views) {
            $rows[] = array('source', (string) $host, array('pageviews' => $views));
        }
        foreach ($channels as $channel => $views) {
            $rows[] = array('channel', $channel, array('pageviews' => $views));
        }

        $countries = $this->request('country-views', $args);
        if (is_wp_error($countries)) {
            return $countries;
        }
        $places = array();
        foreach (self::listed($countries, $day, 'views') as $item) {
            $code  = isset($item['country_code']) ? strtoupper(trim((string) $item['country_code'])) : '';
            $views = isset($item['views']) ? (int) $item['views'] : 0;
            if (preg_match('~^[A-Z]{2}$~', $code) && !in_array($code, array('A1', 'A2', 'ZZ', 'XX'), true) && $views > 0) {
                $places[$code] = (isset($places[$code]) ? $places[$code] : 0) + $views;
            }
        }
        foreach ($places as $code => $views) {
            $rows[] = array('country', (string) $code, array('pageviews' => $views));
        }
        return $rows;
    }

    /**
     * {@inheritDoc}
     *
     * It counts logged-in people only in the roles in count_roles; ours
     * skips the others.
     */
    public function settings() {
        $stats = get_option(self::STATS_OPTION);
        if (!is_array($stats) || !isset($stats['count_roles']) || !is_array($stats['count_roles'])) {
            return array();
        }
        $count = array_values(array_map('strval', $stats['count_roles']));
        $skip  = array_values(array_diff(array_keys(wp_roles()->get_names()), $count));
        return array(
            array(
                'key'   => 'tracking_skip_roles',
                'label' => 'Count logged in page views from',
                'from'  => $count ? implode(', ', $count) : 'Nobody',
                'value' => $skip,
            ),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Full Jetpack: switch off its Stats module. The standalone plugin:
     * deactivate it (null).
     */
    public function removal_step() {
        $network = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
        if (!in_array(self::JETPACK_FILE, (array) get_option('active_plugins', array()), true) && !isset($network[self::JETPACK_FILE])) {
            return null;
        }
        return array(
            'text' => __('Switch off Stats in Jetpack → Settings → Traffic (Jetpack keeps its other features).', 'seoprostats'),
            'url'  => admin_url('admin.php?page=jetpack#/traffic'),
            'done' => !in_array('stats', (array) get_option(self::MODULES_OPTION, array()), true),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Only its statistics caches: never Jetpack's connection, modules or
     * other options.
     */
    public function leftovers() {
        $meta = '_' . self::CACHE_PREFIX;
        return array(
            'tables'     => array(),
            'options'    => array(),
            'transients' => self::options_like(array('_transient_' . self::CACHE_PREFIX, '_transient_timeout_' . self::CACHE_PREFIX)),
            'cron'       => array(),
            'user_meta'  => array(),
            'post_meta'  => array_values(array_intersect(self::post_meta_like($meta), array($meta))),
            'files'      => array(),
            'network'    => array(),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Either plugin: the active one first.
     */
    public function plugin() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $best = array('file' => '', 'state' => 'missing');
        foreach (array_keys(get_plugins()) as $file) {
            $file = (string) $file;
            if (!in_array(dirname($file), static::SLUGS, true)) {
                continue;
            }
            if (is_multisite() && is_plugin_active_for_network($file)) {
                return array('file' => $file, 'state' => 'network');
            }
            if (is_plugin_active($file)) {
                $best = array('file' => $file, 'state' => 'active');
            } elseif ($best['file'] === '') {
                $best = array('file' => $file, 'state' => 'inactive');
            }
        }
        return $best;
    }

    /**
     * {@inheritDoc}
     */
    public function debug() {
        return self::$log;
    }

    /**
     * Whether its history can be read: the plugin active, its connection
     * client loaded, connected, and Stats on (Jetpack's own checks).
     *
     * @return bool
     */
    private function ready() {
        $state = $this->plugin()['state'];
        return ($state === 'active' || $state === 'network')
            && self::client_ready()
            && self::connected()
            && self::blog_id() > 0
            && self::stats_on();
    }

    /**
     * Look up (or refresh, daily) its daily views and visitors where
     * requests are allowed; elsewhere ask the background look to.
     */
    private function look() {
        $series = $this->series();
        if ((int) $series['wait'] > time()) {
            return;
        }
        if (!empty($series['complete']) && (int) $series['at'] > time() - DAY_IN_SECONDS) {
            return;
        }
        if (!self::remote_allowed()) {
            if (empty($series['complete']) && class_exists('SEOProStats_Collection')) {
                SEOProStats_Collection::schedule_migrate_scan();
            }
            return;
        }
        $done = $this->lookup((defined('WP_CLI') && WP_CLI) ? self::LOOKUP_CLI : self::LOOKUP_BUDGET);
        if (!$done && wp_doing_cron() && class_exists('SEOProStats_Collection')) {
            $wait = (int) $this->series()['wait'];
            if (!wp_next_scheduled(SEOProStats_Collection::MIGRATE_SCAN_HOOK)) {
                // Carry on in the next background look (later, after a failure).
                wp_schedule_single_event(max(time(), $wait), SEOProStats_Collection::MIGRATE_SCAN_HOOK);
            }
        }
    }

    /**
     * Read stats/visits back from today, WINDOW days a request, until
     * EMPTY_WINDOWS windows in a row have no views (refreshing: back to
     * the last day known), within a budget. Progress is saved after each
     * request, so the next look carries on.
     *
     * @param int $budget Seconds.
     * @return bool Whether the look is complete.
     */
    private function lookup($budget) {
        $series = $this->series();
        $tz     = wp_timezone();
        if (!empty($series['complete'])) {
            // Refresh: the days since the last one known.
            $series['complete'] = false;
            $series['next']     = '';
            $series['until']    = $series['days'] ? (string) max(array_keys($series['days'])) : '';
            $series['empty']    = 0;
        }
        if ($series['next'] === '') {
            $series['next'] = (string) wp_date('Y-m-d');
        }
        $start = microtime(true);
        while ($series['next'] !== '' && SEOProStats_Feature::more_time($start, $budget)) {
            $answer = $this->request('visits', array('unit' => 'day', 'quantity' => self::WINDOW, 'date' => $series['next'], 'stat_fields' => 'views,visitors'));
            if (is_wp_error($answer)) {
                $series['wait'] = time() + self::LOOKUP_WAIT;
                update_option(self::SERIES_OPTION, $series, false);
                return false;
            }
            $rows  = self::visits_rows($answer);
            $found = false;
            foreach ($rows as $day => $counts) {
                if ($day <= $series['next'] && ($counts[0] > 0 || $counts[1] > 0)) {
                    $series['days'][$day] = $counts;
                    $found                = true;
                }
            }
            $series['empty'] = $found ? 0 : (int) $series['empty'] + 1;
            $series['wait']  = 0;
            // The window's first day: the earliest the answer holds (else WINDOW days back).
            $first = $rows ? (string) min(array_keys($rows)) : (new DateTimeImmutable($series['next'], $tz))->modify('-' . (self::WINDOW - 1) . ' days')->format('Y-m-d');
            $first = min($first, $series['next']);
            if (($series['until'] !== '' && $first <= $series['until']) || $series['empty'] >= self::EMPTY_WINDOWS || $first <= self::FLOOR) {
                ksort($series['days']);
                $series['next']     = '';
                $series['until']    = '';
                $series['complete'] = true;
                $series['at']       = time();
            } else {
                $series['next'] = (new DateTimeImmutable($first, $tz))->modify('-1 day')->format('Y-m-d');
            }
            update_option(self::SERIES_OPTION, $series, false);
        }
        return !empty($series['complete']);
    }

    /**
     * Our copy of its daily views and visitors, for its WordPress.com site.
     *
     * @return array{blog:int,days:array<string,array{0:int,1:int}>,next:string,until:string,empty:int,complete:bool,at:int,wait:int}
     */
    private function series() {
        $empty  = array('blog' => self::blog_id(), 'days' => array(), 'next' => '', 'until' => '', 'empty' => 0, 'complete' => false, 'at' => 0, 'wait' => 0);
        $series = get_option(self::SERIES_OPTION);
        if (!is_array($series) || !isset($series['blog'], $series['days']) || (int) $series['blog'] !== $empty['blog'] || !is_array($series['days'])) {
            return $empty;
        }
        return array_merge($empty, array_intersect_key($series, $empty));
    }

    /**
     * A stats/visits answer as day => array(views, visitors).
     *
     * @param array<string,mixed> $answer Its answer.
     * @return array<string,array{0:int,1:int}>
     */
    private static function visits_rows(array $answer) {
        $out = array();
        if (!isset($answer['fields'], $answer['data']) || !is_array($answer['fields']) || !is_array($answer['data'])) {
            return $out;
        }
        $index    = array_flip(array_map('strval', $answer['fields']));
        $period   = isset($index['period']) ? $index['period'] : 0;
        $views    = isset($index['views']) ? $index['views'] : null;
        $visitors = isset($index['visitors']) ? $index['visitors'] : null;
        foreach ($answer['data'] as $row) {
            if (!is_array($row) || !isset($row[$period]) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $row[$period])) {
                continue;
            }
            $out[(string) $row[$period]] = array(
                $views !== null && isset($row[$views]) ? max(0, (int) $row[$views]) : 0,
                $visitors !== null && isset($row[$visitors]) ? max(0, (int) $row[$visitors]) : 0,
            );
        }
        return $out;
    }

    /**
     * A day's list from a days-keyed answer: days[day][key] (the only day,
     * as Jetpack's abilities read it, when keyed otherwise).
     *
     * @param array<string,mixed> $answer Its answer.
     * @param string              $day    Y-m-d.
     * @param string              $key    postviews, groups or views.
     * @return array<int,array<string,mixed>>
     */
    private static function listed(array $answer, $day, $key) {
        if (empty($answer['days']) || !is_array($answer['days'])) {
            return array();
        }
        $data = isset($answer['days'][$day]) ? $answer['days'][$day] : (count($answer['days']) === 1 ? reset($answer['days']) : null);
        if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) {
            return array();
        }
        return array_values(array_filter($data[$key], 'is_array'));
    }

    /**
     * A post's current path ('' for a post deleted since). ID 0 is its
     * "Home page / Archives" row: its address when it is this site's, else /.
     *
     * @param int    $id   Post ID.
     * @param string $href Its address.
     * @return string
     */
    private static function post_path($id, $href) {
        if ($id <= 0) {
            $host = self::host($href);
            return $href !== '' && $host !== '' && $host === self::host(home_url()) ? SEOProStats_Processor::split_url($href)['path'] : '/';
        }
        if (!isset(self::$paths[$id])) {
            $post = get_post($id);
            $link = $post && $post->post_status !== 'trash' ? get_permalink($post) : '';

            self::$paths[$id] = is_string($link) && $link !== '' ? (string) SEOProStats_Processor::split_url($link)['path'] : '';
        }
        return self::$paths[$id];
    }

    /**
     * A referrer group's views by host: the group's address and total,
     * else each result's (or its children's) address and views, else names
     * that are hosts.
     *
     * @param array<string,mixed> $group A days[day].groups[] item.
     * @return array<string,int>
     */
    private static function referrer_hosts(array $group) {
        $out = array();
        $add = static function ($host, $views) use (&$out) {
            if ($host !== '' && $views > 0) {
                $out[$host] = (isset($out[$host]) ? $out[$host] : 0) + $views;
            }
        };
        $host = self::host(isset($group['url']) ? (string) $group['url'] : '');
        if ($host === '' && !empty($group['results']) && is_array($group['results'])) {
            foreach ($group['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $one = self::host(isset($result['url']) ? (string) $result['url'] : '');
                if ($one === '' && !empty($result['children']) && is_array($result['children'])) {
                    foreach ($result['children'] as $child) {
                        if (is_array($child)) {
                            $child_host = self::host(isset($child['url']) ? (string) $child['url'] : '');
                            $add($child_host !== '' ? $child_host : self::name_host($child), isset($child['views']) ? (int) $child['views'] : 0);
                        }
                    }
                    continue;
                }
                $add($one !== '' ? $one : self::name_host($result), isset($result['views']) ? (int) $result['views'] : 0);
            }
            return $out;
        }
        $add($host !== '' ? $host : self::name_host($group), isset($group['total']) ? (int) $group['total'] : 0);
        return $out;
    }

    /**
     * An item's name as a host, when it is one (a dot, no spaces).
     *
     * @param array<string,mixed> $item Referrer item.
     * @return string
     */
    private static function name_host(array $item) {
        $name = isset($item['name']) ? trim((string) $item['name']) : '';
        return $name !== '' && strpos($name, '.') !== false && !preg_match('~\s~', $name) ? self::host($name) : '';
    }

    /**
     * One request to WordPress.com as the site, made as Jetpack makes it
     * but not cached; noted for --debug (path, HTTP code, keys, counts;
     * never the token or the body).
     *
     * @param string               $resource Such as visits.
     * @param array<string,scalar> $args     Query.
     * @return array<string,mixed>|WP_Error
     */
    private function request($resource, array $args) {
        if (!self::remote_allowed()) {
            return new WP_Error('seoprostats_jetpack_later', __('Jetpack Stats are fetched from WordPress.com in the background or with WP-CLI, not in this request.', 'seoprostats'), array('status' => 409, 'retry' => 0));
        }
        if (!self::client_ready()) {
            return new WP_Error('seoprostats_jetpack_update', __('Update Jetpack: this version cannot hand its statistics to SEO Pro Stats.', 'seoprostats'), array('status' => 409));
        }
        if (!self::connected() || self::blog_id() <= 0) {
            return new WP_Error('seoprostats_jetpack_connection', __('Connect Jetpack to WordPress.com first: its statistics are kept there, not on this site.', 'seoprostats'), array('status' => 409));
        }
        $path     = sprintf('/sites/%d/stats/%s', self::blog_id(), $resource) . ($args ? '?' . http_build_query($args) : '');
        $response = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog($path, self::API, array('timeout' => self::TIMEOUT));
        $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body     = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $data     = $body !== '' ? json_decode($body, true) : null;
        $error    = '';
        if (is_wp_error($response)) {
            $error = (string) $response->get_error_code();
        } elseif (is_array($data) && (isset($data['error']) || isset($data['code']))) {
            $error = (string) (isset($data['error']) ? $data['error'] : $data['code']);
        }
        self::note($path, $code, $data, $error);

        if (in_array($error, self::TOKEN_ERRORS, true)) {
            return new WP_Error('seoprostats_jetpack_connection', __('Connect Jetpack to WordPress.com first: its statistics are kept there, not on this site.', 'seoprostats'), array('status' => 409));
        }
        if (is_wp_error($response)) {
            /* translators: %s: error message. */
            return new WP_Error('seoprostats_jetpack_request', sprintf(__('WordPress.com did not answer: %s', 'seoprostats'), $response->get_error_message()), array('status' => 502, 'retry' => MINUTE_IN_SECONDS));
        }
        if ($code === 429 || $code >= 500) {
            /* translators: %d: HTTP status code. */
            return new WP_Error('seoprostats_jetpack_busy', sprintf(__('WordPress.com is busy (HTTP %d). The import tries again later.', 'seoprostats'), $code), array('status' => 503, 'retry' => 5 * MINUTE_IN_SECONDS));
        }
        if ($code !== 200 || !is_array($data) || $error !== '') {
            /* translators: 1: HTTP status code, 2: error code. */
            return new WP_Error('seoprostats_jetpack_answer', sprintf(__('WordPress.com answered without statistics (HTTP %1$d %2$s).', 'seoprostats'), $code, $error), array('status' => 502));
        }
        return $data;
    }

    /**
     * Note a request for --debug: its path, HTTP code, error code, the
     * answer's top-level keys and its first day's lists with their counts.
     *
     * @param string $path  Endpoint with query.
     * @param int    $code  HTTP code (0: no answer).
     * @param mixed  $data  Decoded answer.
     * @param string $error Error code.
     */
    private static function note($path, $code, $data, $error) {
        if (count(self::$log) >= self::LOG_SIZE) {
            return;
        }
        $rows = '';
        if (is_array($data) && !empty($data['days']) && is_array($data['days'])) {
            $first = reset($data['days']);
            $parts = array();
            foreach (is_array($first) ? $first : array() as $key => $value) {
                $parts[] = is_array($value) ? $key . ': ' . count($value) : (string) $key;
            }
            $rows = 'days[' . (string) key($data['days']) . '] ' . implode(', ', $parts);
        } elseif (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $fields = isset($data['fields']) && is_array($data['fields']) ? implode(',', array_map('strval', $data['fields'])) : '';
            $rows   = 'data: ' . count($data['data']) . ' rows; fields: ' . $fields;
        }
        self::$log[] = array(
            'request' => $path,
            'http'    => $code,
            'error'   => $error,
            'keys'    => is_array($data) ? implode(', ', array_map('strval', array_keys($data))) : '',
            'rows'    => $rows,
        );
    }

    /**
     * Whether requests may be made now: cron and WP-CLI only.
     *
     * @return bool
     */
    private static function remote_allowed() {
        return wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
    }

    /**
     * Whether the Jetpack loaded has what the import calls: its connection
     * client's request as the site, its connection manager and its options.
     *
     * @return bool
     */
    private static function client_ready() {
        return class_exists('Automattic\Jetpack\Connection\Client')
            && method_exists('Automattic\Jetpack\Connection\Client', 'wpcom_json_api_request_as_blog')
            && class_exists('Automattic\Jetpack\Connection\Manager')
            && class_exists('Jetpack_Options');
    }

    /**
     * Whether Jetpack is connected to WordPress.com (its own check).
     *
     * @return bool
     */
    private static function connected() {
        if (!class_exists('Automattic\Jetpack\Connection\Manager')) {
            return false;
        }
        return (bool) (new \Automattic\Jetpack\Connection\Manager())->is_connected();
    }

    /**
     * Whether its Stats module is on (its own check, else its option).
     *
     * @return bool
     */
    private static function stats_on() {
        if (class_exists('Automattic\Jetpack\Modules')) {
            return (bool) (new \Automattic\Jetpack\Modules())->is_active('stats');
        }
        return in_array('stats', (array) get_option(self::MODULES_OPTION, array()), true);
    }

    /**
     * The site's WordPress.com ID (Jetpack_Options 'id').
     *
     * @return int
     */
    private static function blog_id() {
        return class_exists('Jetpack_Options') ? (int) \Jetpack_Options::get_option('id') : 0;
    }
}
