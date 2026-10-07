<?php
/**
 * The WordPress side of collection: the site's collector folder and its
 * config file, the daily salts, the loopback test that picks the fast
 * endpoint (collect.php) or the REST route, and the REST route itself.
 *
 * Nothing here runs on visitor pages except the REST route when the fast
 * endpoint cannot be used. Design: docs/architecture.md → Collection.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Collection {

    /** Salts by site-local day (autoload off): only today's and tomorrow's. */
    const SALTS_OPTION = 'seoprostats_salts';

    /** Collector state (autoload off): ping key, fast endpoint test result. */
    const STATE_OPTION = 'seoprostats_collector';

    /**
     * Where the tracker posts, 'fast' or 'rest' (autoloaded: visitor pages
     * read it with no query). Written by test_fast_endpoint().
     */
    const ENDPOINT_OPTION = 'seoprostats_endpoint';

    /** Hourly cron hook: salts, config and the loopback test. */
    const CRON_HOOK = 'seoprostats_hourly';

    /** Cron hook, every minute: the processor (one file check when idle). */
    const PROCESS_HOOK = 'seoprostats_process';

    /** Daily cron hook: search engine updates (only while that setting is on). */
    const DAILY_HOOK = 'seoprostats_daily';

    /** Cron hook: search data imports (SEOProStats_Search_Import; only while a source is connected). */
    const IMPORT_HOOK = 'seoprostats_search_import';

    /** The processor's progress (SEOProStats_Processor::STATE_OPTION). */
    const PROCESS_OPTION = 'seoprostats_processor';

    /** Daily summaries' and retention's progress (SEOProStats_Rollup::STATE_OPTION). */
    const ROLLUP_OPTION = 'seoprostats_rollup';

    /** REST namespace. */
    const REST_NAMESPACE = 'seoprostats/v1';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'refresh'));
        add_action(self::PROCESS_HOOK, array(__CLASS__, 'process'));
        add_action(self::DAILY_HOOK, array(__CLASS__, 'daily'));
        add_action(self::IMPORT_HOOK, array(__CLASS__, 'search_import'));
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules')); // phpcs:ignore WordPress.WP.CronInterval -- one minute on purpose: hits wait in the buffer until it runs, and an idle run is one file check.
        add_action('rest_api_init', array(__CLASS__, 'register_route'));
        add_action('admin_init', array(__CLASS__, 'schedule'));
        add_action('update_option_timezone_string', array(__CLASS__, 'refresh'));
        add_action('update_option_gmt_offset', array(__CLASS__, 'refresh'));
    }

    /**
     * Schedule the hourly job (admin requests only; reading the schedule
     * costs no query, as cron is autoloaded). The first run makes the
     * folder and config straight away.
     */
    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
            self::refresh();
        }
        if (!wp_next_scheduled(self::PROCESS_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'seoprostats_minute', self::PROCESS_HOOK);
        }
        // Search engine updates: the first run a minute after the setting is switched on.
        $daily = wp_next_scheduled(self::DAILY_HOOK);
        if (SEOProStats_Statistics::search_updates() && !$daily) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', self::DAILY_HOOK);
        } elseif ($daily && !SEOProStats_Statistics::search_updates()) {
            wp_clear_scheduled_hook(self::DAILY_HOOK);
        }
        if (get_option(self::ENDPOINT_OPTION) === false) {
            // Sites from before the option: the last test's answer, until the next test.
            $state = self::state();
            update_option(self::ENDPOINT_OPTION, empty($state['fast']) ? 'rest' : 'fast', true);
        }
    }

    /**
     * The one-minute cron schedule. Other plugins can ask for the schedules
     * before init (WooCommerce does), when translating would load the
     * translations too early, so the label is translated only after init.
     *
     * @param array<string,array<string,mixed>> $schedules Schedules.
     * @return array<string,array<string,mixed>>
     */
    public static function cron_schedules($schedules) {
        $label = 'Every minute (SEO Pro Stats)';
        $schedules['seoprostats_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => did_action('init') ? __('Every minute (SEO Pro Stats)', 'seoprostats') : $label,
        );
        return $schedules;
    }

    /**
     * Cron: process buffered hits, then summarise finished days and prune
     * old rows when due (an option read or two when not). The processor and
     * the summaries load only here and in WP-CLI.
     */
    public static function process() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-processor.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        SEOProStats_Processor::run();
        SEOProStats_Rollup::run();
    }

    /**
     * Daily cron: search engine updates, and the content audit's next
     * batch of pages (the classes load only here and in WP-CLI).
     */
    public static function daily() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-updates.php';
        SEOProStats_Search_Updates::run();
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-audit.php';
        SEOProStats_Audit::batch();
    }

    /**
     * Cron: search data imports from connected sources (the classes load
     * only here, in WP-CLI and on the Connections tab and routes).
     */
    public static function search_import() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-connections.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        SEOProStats_Search_Import::cron();
    }

    /**
     * The site's collector folder: wp-content/seoprostats/site-{blog id}.
     *
     * @return string
     */
    public static function dir() {
        return WP_CONTENT_DIR . '/seoprostats/site-' . get_current_blog_id();
    }

    /**
     * Hourly: rotate the salts, write the config, test the fast endpoint.
     */
    public static function refresh() {
        self::rotate_salts();
        if (self::write_config()) {
            self::test_fast_endpoint();
        }
    }

    /**
     * Keep a random salt for today and tomorrow (site-local days) and
     * delete older ones, so yesterday's visitor hashes can never be made
     * again. Tomorrow's is made early so midnight needs no cron run.
     */
    public static function rotate_salts() {
        $stored = get_option(self::SALTS_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $today  = wp_date('Y-m-d');
        $next   = wp_date('Y-m-d', time() + DAY_IN_SECONDS);
        $salts  = array();
        foreach (array($today, $next) as $day) {
            $salts[$day] = isset($stored[$day]) && is_string($stored[$day]) ? $stored[$day] : bin2hex(random_bytes(32));
        }
        if ($salts !== $stored) {
            update_option(self::SALTS_OPTION, $salts, false);
        }
    }

    /**
     * The config collect.php and the REST route read.
     *
     * @return array<string,mixed>
     */
    public static function config() {
        $config = self::filtered_config();

        $salts              = get_option(self::SALTS_OPTION, array());
        $config['salts']    = is_array($salts) ? $salts : array();
        $config['tz']       = wp_timezone_string();
        $config['ping_key'] = self::ping_key();
        return $config;
    }

    /**
     * The hosts the tracker may post from: the site's, with and without
     * www., and any the seoprostats_collector_config filter adds. No query.
     *
     * @return string[] Lower-case host names.
     */
    public static function hosts() {
        $config = self::filtered_config();
        $hosts  = isset($config['hosts']) && is_array($config['hosts']) ? $config['hosts'] : array();
        return array_values(array_unique(array_map('strtolower', array_filter($hosts, 'is_string'))));
    }

    /**
     * The collector config before the salts, time zone and ping key: the
     * owner's part, through the seoprostats_collector_config filter.
     *
     * @return array<string,mixed>
     */
    private static function filtered_config() {
        $host  = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $hosts = array($host);
        // The same site with and without www.
        $hosts[] = strpos($host, 'www.') === 0 ? substr($host, 4) : 'www.' . $host;
        $hosts   = array_merge($hosts, SEOProStats_Statistics::hosts());

        /**
         * Filters the collector config, after the settings (Tracking and
         * Privacy) have set it: whether hits are stored ('off' true: not;
         * pages cached with the tracker keep sending), the hosts the
         * tracker may post from (a site under several domains), addresses
         * to ignore (single or CIDR), and the request headers holding the
         * visitor's address and country behind a proxy or CDN ($_SERVER
         * keys, such as HTTP_X_FORWARDED_FOR).
         *
         * @param array<string,mixed> $config Collector config.
         */
        $config = apply_filters('seoprostats_collector_config', array(
            'off'            => !SEOProStats_Statistics::collecting(),
            'hosts'          => array_values(array_unique($hosts)),
            'exclude_ips'    => SEOProStats_Statistics::excluded_ips(),
            'ip_header'      => SEOProStats_Statistics::ip_header(),
            'country_header' => SEOProStats_Statistics::country_header(),
        ));
        return is_array($config) ? $config : array();
    }

    /**
     * Write the folder's guard files and config.php (atomically: a
     * temporary file, then rename).
     *
     * @return bool
     */
    public static function write_config() {
        $dir = self::dir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        $guards = array(
            dirname($dir) . '/index.php' => "<?php\n// Silence is golden.\n",
            $dir . '/index.php'          => "<?php\n// Silence is golden.\n",
            dirname($dir) . '/.htaccess' => "# SEO Pro Stats: no direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
        );
        foreach ($guards as $file => $content) {
            if (!is_file($file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small file in our own folder; WP_Filesystem may ask for FTP details in cron.
                file_put_contents($file, $content);
            }
        }

        // Written by WordPress; read by include, so it must be valid PHP.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes PHP code, not debug output.
        $php  = "<?php\n// SEO Pro Stats collector config: written by the plugin every hour; edits are overwritten.\nreturn " . var_export(self::config(), true) . ";\n";
        $file = $dir . '/config.php';
        $tmp  = $file . '.' . wp_generate_password(8, false) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- atomic write in our own folder.
        if (file_put_contents($tmp, $php) !== strlen($php)) {
            wp_delete_file($tmp);
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace; WP_Filesystem has no rename.
        if (!rename($tmp, $file)) {
            wp_delete_file($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
        return true;
    }

    /**
     * The site's random ping key, made once.
     *
     * @return string
     */
    private static function ping_key() {
        $state = self::state();
        if (empty($state['ping_key'])) {
            $state['ping_key'] = bin2hex(random_bytes(16));
            update_option(self::STATE_OPTION, $state, false);
        }
        return (string) $state['ping_key'];
    }

    /**
     * Stored collector state.
     *
     * @return array<string,mixed>
     */
    public static function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Ask collect.php over HTTP whether it runs and reads this site's
     * config; record the answer for endpoint().
     *
     * @return bool Whether the fast endpoint works.
     */
    public static function test_fast_endpoint() {
        if (!is_file(SEOPROSTATS_DIR . 'collect.php')) {
            // The WordPress.org build has no fast endpoint (.distignore-wporg).
            $state            = self::state();
            $state['fast']    = false;
            $state['checked'] = time();
            update_option(self::STATE_OPTION, $state, false);
            update_option(self::ENDPOINT_OPTION, 'rest', true);
            return false;
        }
        $nonce    = wp_generate_password(32, false);
        $url      = add_query_arg(array('s' => get_current_blog_id(), 'ping' => $nonce), self::fast_url());
        // sslverify off: a loopback to the site itself, often with a local
        // or self-signed certificate; the HMAC proves the answer.
        $response = wp_remote_get($url, array('timeout' => 3, 'redirection' => 2, 'sslverify' => false));
        $expected = hash_hmac('sha256', $nonce, self::ping_key());
        $works    = !is_wp_error($response)
            && wp_remote_retrieve_response_code($response) === 200
            && hash_equals($expected, trim((string) wp_remote_retrieve_body($response)));

        $state            = self::state();
        $state['fast']    = $works;
        $state['checked'] = time();
        update_option(self::STATE_OPTION, $state, false);
        update_option(self::ENDPOINT_OPTION, $works ? 'fast' : 'rest', true);
        return $works;
    }

    /**
     * URL of collect.php (without the site parameter), with the home URL's
     * scheme: in cron and WP-CLI there is no HTTPS request to copy it from,
     * and an http URL would be redirected.
     *
     * @return string
     */
    public static function fast_url() {
        $scheme = (string) wp_parse_url(home_url(), PHP_URL_SCHEME);
        return set_url_scheme(plugins_url('collect.php', SEOPROSTATS_FILE), $scheme === 'https' ? 'https' : 'http');
    }

    /**
     * Where the tracker posts: collect.php when the loopback test passed,
     * else the REST route. No query (an autoloaded option).
     *
     * @return string
     */
    public static function endpoint() {
        if (get_option(self::ENDPOINT_OPTION) === 'fast') {
            return add_query_arg('s', get_current_blog_id(), self::fast_url());
        }
        return rest_url(self::REST_NAMESPACE . '/collect');
    }

    /**
     * The REST fallback: the same collector, inside WordPress.
     */
    public static function register_route() {
        register_rest_route(self::REST_NAMESPACE, '/collect', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rest_collect'),
            // Public on purpose: visitors are not logged in. The collector
            // checks the host, size and shape of every request.
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle a REST collect request.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function rest_collect($request) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-collector.php';
        $dir = self::dir();
        // The written config (cached by OPcache) costs no query per hit.
        $config = is_file($dir . '/config.php') ? include $dir . '/config.php' : null;
        $status = SEOProStats_Collector::handle(
            is_array($config) ? $config : self::config(),
            $dir,
            (string) $request->get_body(),
            $_SERVER,
            time()
        );
        $response = new WP_REST_Response(null, $status);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Delete the site's collector folder and options (uninstall). The
     * shared parent folder goes once no site folder is left in it.
     */
    public static function remove() {
        $dir = self::dir();
        self::remove_dir($dir);
        $parent = dirname($dir);
        $left   = is_dir($parent) ? glob($parent . '/site-*', GLOB_ONLYDIR) : array();
        if (!$left) {
            self::remove_dir($parent);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::PROCESS_HOOK);
        wp_clear_scheduled_hook(self::DAILY_HOOK);
        delete_option('seoprostats_search_updates');
        delete_option(self::SALTS_OPTION);
        delete_option(self::STATE_OPTION);
        delete_option(self::ENDPOINT_OPTION);
        delete_option(self::PROCESS_OPTION);
        delete_option(self::ROLLUP_OPTION);
    }

    /**
     * Delete the files in one of our folders (no subfolders), then it.
     *
     * @param string $dir Folder.
     */
    private static function remove_dir($dir) {
        $names = is_dir($dir) ? scandir($dir) : false;
        if ($names === false) {
            return;
        }
        foreach ($names as $name) {
            if (is_file($dir . '/' . $name)) {
                wp_delete_file($dir . '/' . $name);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty, folder.
        @rmdir($dir);
    }
}
