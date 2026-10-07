<?php
/**
 * Prints the tracker (packages/tracker, built to assets/build/tracker.js)
 * on front-end pages: inline in the footer, so there is no extra request,
 * or as a file when Settings → Tracking or the seoprostats_tracker_inline
 * filter says so (a page cache or Content Security Policy that needs it).
 *
 * A tiny stub in the head queues seoprostats('Name', {...}) calls made
 * before the tracker runs. Costs no query: the endpoint and settings come
 * from autoloaded options (SEOProStats_Statistics), and the page's context
 * (not found, site search, the item shown, logged in) from the request's
 * own query. Design: docs/architecture.md → Collection → Tracker.
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

final class SEOProStats_Tracker {

    /** The built tracker, relative to the plugin folder. */
    const FILE = 'assets/build/tracker.js';

    /** The stub that queues calls made before the tracker runs. */
    const STUB = 'window.seoprostats=window.seoprostats||function(){(window.seoprostats.q=window.seoprostats.q||[]).push(arguments)};';

    /** @var bool|null Whether this page is tracked, once decided. */
    private static $track = null;

    /**
     * Register hooks (front-end requests only).
     */
    public static function init() {
        add_action('wp_head', array(__CLASS__, 'print_stub'), 1);
        add_action('wp_footer', array(__CLASS__, 'print_tracker'), 99);
    }

    /**
     * Whether this page gets the tracker. Not while collection is off, nor
     * for feeds, previews, the customizer or embeds, nor for logged-in
     * people with a role that is not counted (Settings → Tracking), nor on
     * excluded paths. Do Not Track and Global Privacy Control are checked
     * in the browser, so page caches can keep one copy.
     *
     * @return bool
     */
    public static function should_track() {
        if (self::$track !== null) {
            return self::$track;
        }
        $track = SEOProStats_Statistics::collecting()
            && !is_admin() && !is_feed() && !is_preview() && !is_customize_preview() && !is_embed()
            && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)
            && is_file(SEOPROSTATS_DIR . self::FILE);

        if ($track && is_user_logged_in()) {
            $track = !array_intersect((array) wp_get_current_user()->roles, SEOProStats_Statistics::skip_roles());
        }

        if ($track) {
            // Compared with the excluded paths only; never stored or printed.
            $uri   = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $path  = (string) wp_parse_url($uri, PHP_URL_PATH);
            $track = !self::excluded($path === '' ? '/' : $path, self::config()['x']);
        }

        /**
         * Filters whether this page gets the tracker.
         *
         * @param bool $track Whether to print it.
         */
        self::$track = (bool) apply_filters('seoprostats_track', $track);
        return self::$track;
    }

    /**
     * Whether a path matches one of the patterns (* matches any characters).
     *
     * @param string   $path     Path.
     * @param string[] $patterns Patterns.
     * @return bool
     */
    public static function excluded($path, array $patterns) {
        foreach ($patterns as $pattern) {
            $regex = '~^' . implode('.*', array_map(static function ($part) {
                return preg_quote($part, '~');
            }, explode('*', $pattern))) . '$~';
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The tracker's config, printed in its data-cfg attribute.
     *
     * - u:   collector address
     * - h:   the site's hosts (links elsewhere are outbound)
     * - q:   query parameters kept in page addresses, besides UTM tags
     * - dnt: skip browsers that send Do Not Track or Global Privacy Control
     * - x:   paths not tracked (* matches any characters)
     * - c:   autocapture clicks and form submits
     * - a:   affiliate link paths (* matches any characters)
     * - tc:  ThriveCart is connected: its checkout links carry the page
     *        load's ID (SEOProStats_Purchases)
     *
     * @return array{u:string,h:string[],q:string[],dnt:bool,x:string[],c:bool,a:string[],tc:bool}
     */
    public static function config() {
        /**
         * Filters the tracker's config, after the settings (Tracking and
         * Privacy) have set it.
         *
         * @param array<string,mixed> $config u, h, q, dnt, x, c, a and tc (see above).
         */
        $config = apply_filters('seoprostats_tracker_config', array(
            'u'   => SEOProStats_Collection::endpoint(),
            'h'   => SEOProStats_Collection::hosts(),
            'q'   => SEOProStats_Statistics::params(),
            'dnt' => SEOProStats_Statistics::respect_signals(),
            'x'   => SEOProStats_Statistics::excluded_paths(),
            'c'   => SEOProStats_Statistics::autocapture(),
            'a'   => SEOProStats_Statistics::affiliate_paths(),
            'tc'  => SEOProStats_Purchases::thrivecart_secret() !== '',
        ));
        $config = is_array($config) ? $config : array();
        $list   = static function ($key) use ($config) {
            $out = array();
            foreach (isset($config[$key]) && is_array($config[$key]) ? $config[$key] : array() as $value) {
                $value = is_string($value) ? trim($value) : '';
                if ($value !== '') {
                    $out[$value] = true;
                }
            }
            // Keys like "10" become integers: back to text.
            return array_map('strval', array_keys($out));
        };
        return array(
            'u'   => isset($config['u']) && is_string($config['u']) ? $config['u'] : SEOProStats_Collection::endpoint(),
            'h'   => array_map('strtolower', $list('h')),
            'q'   => array_map('strtolower', $list('q')),
            'dnt' => !empty($config['dnt']),
            'x'   => $list('x'),
            'c'   => !empty($config['c']),
            'a'   => $list('a'),
            'tc'  => !empty($config['tc']),
        );
    }

    /**
     * What WordPress knows about this page, printed in the data-ctx
     * attribute and sent with its pageview. Only from what the request has
     * already loaded (no query): the processor looks up the rest.
     *
     * - n: 1 when the page was not found (404)
     * - q: 1 for a site search (its first page of results)
     * - s: the search words, unless Settings → Tracking leaves them out
     * - r: the search's number of results
     * - i: the post, page or other single item shown
     * - l: 1 when the visitor is logged in
     *
     * @return array<string,int|string>
     */
    public static function context() {
        global $wp_query;
        $out = array();
        if (is_404()) {
            $out['n'] = 1;
        } elseif (is_search() && !is_paged()) {
            $out['q'] = 1;
            $words    = trim((string) get_search_query(false));
            if ($words !== '' && SEOProStats_Statistics::search_terms()) {
                $out['s'] = function_exists('mb_substr') ? mb_substr($words, 0, 100, 'UTF-8') : substr($words, 0, 100);
            }
            $out['r'] = $wp_query instanceof WP_Query ? (int) $wp_query->found_posts : 0;
        } elseif (is_singular()) {
            $id = (int) get_queried_object_id();
            if ($id > 0) {
                $out['i'] = $id;
            }
        }
        if (is_user_logged_in()) {
            $out['l'] = 1;
        }
        return $out;
    }

    /**
     * wp_head: the stub, so seoprostats() can be called before the tracker runs.
     */
    public static function print_stub() {
        if (self::should_track()) {
            wp_print_inline_script_tag(self::STUB, array('id' => 'seoprostats-stub'));
        }
    }

    /**
     * wp_footer: the tracker, inline or as a file.
     */
    public static function print_tracker() {
        if (!self::should_track()) {
            return;
        }
        $config = self::config();
        // Smaller attribute: leave out what is empty or off.
        $config = array_filter($config, static function ($value) {
            return $value !== array() && $value !== false;
        });
        $attributes = array(
            'id'       => 'seoprostats-tracker',
            'data-cfg' => wp_json_encode($config, JSON_UNESCAPED_SLASHES),
        );

        /**
         * Filters the properties of this page, sent with its pageview: up
         * to 30 names with string, number or true/false values.
         *
         * @param array<string,string|int|float|bool> $props Properties.
         */
        $props = apply_filters('seoprostats_page_props', array());
        if (is_array($props) && $props) {
            $attributes['data-props'] = wp_json_encode($props, JSON_UNESCAPED_SLASHES);
        }
        $context = self::context();
        if ($context) {
            $attributes['data-ctx'] = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        /**
         * Filters whether the tracker is printed inline (no extra request)
         * or loaded from its file (for a Content Security Policy without
         * inline scripts, or a cache that should keep it apart).
         *
         * @param bool $inline Default true, unless Settings → Tracking loads it as a file.
         */
        if (apply_filters('seoprostats_tracker_inline', !SEOProStats_Statistics::as_file())) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own built file, not a remote request.
            $script = file_get_contents(SEOPROSTATS_DIR . self::FILE);
            if (is_string($script) && $script !== '') {
                wp_print_inline_script_tag(trim($script), $attributes);
            }
            return;
        }
        $attributes['src']   = add_query_arg('ver', SEOPROSTATS_VERSION, plugins_url(self::FILE, SEOPROSTATS_FILE));
        $attributes['defer'] = true;
        wp_print_script_tag($attributes);
    }
}
