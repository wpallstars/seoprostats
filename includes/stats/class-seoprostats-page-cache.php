<?php
/**
 * Purges known page caches when what the tracker prints changes: a new
 * build of the tracker (a plugin update), or a setting that changes its
 * config or where it is printed. The tracker is inline, so a cached page
 * keeps the copy it was cached with until its cache is purged.
 *
 * Loaded on admin, WP-Cron and WP-CLI requests only, never on visitor
 * pages. Design: docs/architecture.md → Collection → Tracker.
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

final class SEOProStats_Page_Cache {

    /**
     * The tracker build last purged for, and the last purge: time and
     * caches. Autoloaded: every admin request compares the build.
     */
    const OPTION = 'seoprostats_page_cache';

    /** Settings that change what logged-out visitors' pages print. */
    const TRACKER_SETTINGS = array('tracking', 'tracking_params', 'tracking_hosts', 'tracking_clicks', 'tracking_affiliate', 'tracking_file', 'privacy_signals', 'exclusions', 'exclude_paths');

    /**
     * One-off WP-Cron event that purges for a new tracker build. A cron
     * request loads every plugin, where an admin screen may not: plugins
     * that load other plugins only on the screens that need them can leave
     * the cache plugin out.
     */
    const PURGE_HOOK = 'seoprostats_page_cache_purge';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'check_build'));
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge_build'));
        add_action('update_option_' . SEOProStats_Settings::OPTION, array(__CLASS__, 'settings_saved'), 10, 2);
        add_action('add_option_' . SEOProStats_Settings::OPTION, array(__CLASS__, 'settings_added'), 10, 2);
    }

    /**
     * The first save, when no settings were stored: compare with the
     * defaults.
     *
     * @param string $option Option name.
     * @param mixed  $value  Settings saved.
     */
    public static function settings_added($option, $value) {
        self::settings_saved(array(), $value);
    }

    /**
     * admin_init: after the tracker build changes (an update, or the first
     * run), schedule the purge for the next WP-Cron run. No query: the
     * option and the cron list are autoloaded.
     */
    public static function check_build() {
        if (null === get_option(self::OPTION, null)) {
            // Stored and autoloaded at once, so later requests read it
            // with the other options instead of querying until the purge.
            add_option(self::OPTION, array('build' => ''), '', true);
        }
        if (!self::build_changed() || wp_next_scheduled(self::PURGE_HOOK)) {
            return;
        }
        wp_schedule_single_event(time(), self::PURGE_HOOK);
    }

    /**
     * The WP-Cron event: purge once for the current tracker build.
     */
    public static function purge_build() {
        if (self::build_changed()) {
            self::purge('tracker');
        }
    }

    /**
     * Whether the tracker build differs from the one last purged for.
     *
     * @return bool
     */
    private static function build_changed() {
        $state = self::state();
        return !isset($state['build']) || $state['build'] !== self::build();
    }

    /**
     * After the settings are saved: purge when a setting that changes the
     * printed tracker changed.
     *
     * @param mixed $old Settings before.
     * @param mixed $new Settings after.
     */
    public static function settings_saved($old, $new) {
        $old = is_array($old) ? $old : array();
        $new = is_array($new) ? $new : array();
        $defaults = SEOProStats_Settings::defaults();
        foreach (self::TRACKER_SETTINGS as $key) {
            $before = array_key_exists($key, $old) ? $old[$key] : (isset($defaults[$key]) ? $defaults[$key] : null);
            $after  = array_key_exists($key, $new) ? $new[$key] : (isset($defaults[$key]) ? $defaults[$key] : null);
            if ($before !== $after) {
                self::purge('settings');
                return;
            }
        }
    }

    /**
     * The tracker build: the plugin version and the built file's size and
     * time, so a rebuilt file counts even without a version change.
     *
     * @return string
     */
    public static function build() {
        $file = SEOPROSTATS_DIR . 'assets/build/tracker.js';
        clearstatcache(true, $file);
        return SEOPROSTATS_VERSION . ':' . (is_file($file) ? filesize($file) . ':' . filemtime($file) : 'none');
    }

    /**
     * Stored state.
     *
     * @return array<string,mixed>
     */
    public static function state() {
        $state = get_option(self::OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Purge every known page cache that is active, and record it.
     *
     * @param string $why 'tracker', 'settings' or 'manual'.
     * @return string[] Names of the caches purged.
     */
    public static function purge($why = 'manual') {
        /**
         * Filters whether SEO Pro Stats purges page caches when the
         * tracker or its settings change.
         *
         * @param bool   $purge Default true.
         * @param string $why   'tracker', 'settings' or 'manual'.
         */
        $done = apply_filters('seoprostats_purge_page_caches', true, $why) ? self::purge_known() : array();

        /**
         * Fires after SEO Pro Stats purged the page caches it knows, for
         * a cache it does not (a host's or CDN's).
         *
         * @param string   $why  'tracker', 'settings' or 'manual'.
         * @param string[] $done Caches purged.
         */
        do_action('seoprostats_page_caches_purged', $why, $done);

        update_option(self::OPTION, array(
            'build'  => self::build(),
            'purged' => time(),
            'why'    => $why,
            'caches' => $done,
        ), true);
        return $done;
    }

    /**
     * Purge the known caches, each through its own public function or
     * hook, only when it is active.
     *
     * @return string[] Names of the caches purged.
     */
    private static function purge_known() {
        $done = array();
        foreach (self::purgers() as $name => $purge) {
            try {
                if ($purge()) {
                    $done[] = $name;
                }
            } catch (\Throwable $e) {
                // Another plugin's failure must not stop a settings save.
                continue;
            }
        }
        return $done;
    }

    /**
     * One purge per known cache: true when it is active and was purged.
     * Other plugins' functions are called by name, as they may be absent.
     *
     * @return array<string,callable(): bool>
     */
    private static function purgers() {
        $purgers = array(
            'WP-Optimize' => static function () {
                return self::purge_page_cache_object(array('WP_Optimize', 'instance'), 'get_page_cache');
            },
        );
        // Caches with public functions that purge everything: the first
        // must exist, the rest run when they do.
        $functions = array(
            'WP Rocket'                  => array('rocket_clean_domain'),
            'W3 Total Cache'             => array('w3tc_flush_all'),
            'WP Super Cache'             => array('wp_cache_clear_cache'),
            'SiteGround Speed Optimizer' => array('sg_cachepress_purge_everything'),
            'WP Engine'                  => array(array('WpeCommon', 'purge_varnish_cache'), array('WpeCommon', 'purge_memcached')),
        );
        foreach ($functions as $name => $callables) {
            $purgers[$name] = static function () use ($callables) {
                if (!is_callable($callables[0])) {
                    return false;
                }
                foreach ($callables as $callable) {
                    if (is_callable($callable)) {
                        call_user_func($callable);
                    }
                }
                return true;
            };
        }
        // Caches that purge on a hook of their own: only when it is hooked.
        $hooks = array(
            'LiteSpeed Cache'  => 'litespeed_purge_all',
            'Breeze'           => 'breeze_clear_all_cache',
            'Cache Enabler'    => 'cache_enabler_clear_complete_cache',
            'Hummingbird'      => 'wphb_clear_page_cache',
            'Nginx Helper'     => 'rt_nginx_helper_purge_all',
            'WP Fastest Cache' => 'wpfc_clear_all_cache',
        );
        foreach ($hooks as $name => $hook) {
            $purgers[$name] = static function () use ($hook) {
                if (!has_action($hook)) {
                    return false;
                }
                do_action($hook); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the cache plugin's own purge hook.
                return true;
            };
        }
        return $purgers;
    }

    /**
     * Purge a cache plugin's page cache object: its main object's getter
     * returns an object with purge().
     *
     * @param array{0:string,1:string} $instance Static method that returns the plugin's main object.
     * @param string                   $getter   Main object's method that returns the page cache.
     * @return bool True when it was purged.
     */
    private static function purge_page_cache_object(array $instance, $getter) {
        if (!is_callable($instance)) {
            return false;
        }
        $main  = call_user_func($instance);
        $cache = is_object($main) && method_exists($main, $getter) ? $main->$getter() : null;
        return is_object($cache) && method_exists($cache, 'purge') && (bool) $cache->purge();
    }
}
