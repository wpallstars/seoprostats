<?php
/**
 * The SEO Pro Stats screen and the Dashboard widget.
 *
 * A top-level menu at position 3, where site statistics usually sit, opens
 * one screen holding the dashboard app (packages/wp-admin, built into
 * assets/build/). The settings screen (page=seoprostats) is the menu's last
 * item, Settings (SEOProStats_Setup::MENU_PARENT names this menu). Views
 * live in the URL hash (#/overview?range=30d), so each can be bookmarked.
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

final class SEOProStats_Dashboard {

    /** Screen slug: admin.php?page=seoprostats-dashboard. */
    const SLUG = SEOProStats_Setup::MENU_PARENT;

    /** Menu position: where site statistics usually sit, under Dashboard. */
    const POSITION = 3;

    /** Hook suffix of the screen. */
    const HOOK = 'toplevel_page_seoprostats-dashboard';

    /** Dashboard widget id. */
    const WIDGET = 'seoprostats_widget';

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_widget'));
        add_filter('seoprostack_admin_menu_catalog', array(__CLASS__, 'menu_catalog'));
    }

    /**
     * Keep the menu at the top, under Dashboard, when SEO Pro Stack
     * organises the admin menu into sections; otherwise it would go under
     * Administrators. A place already given in its catalog wins, and
     * people can still move it with SEO Pro Stack's "Move menu entries".
     *
     * @param mixed $catalog SEO Pro Stack's admin menu catalog.
     * @return mixed
     */
    public static function menu_catalog($catalog) {
        if (is_array($catalog) && !isset($catalog['menus'][self::SLUG])) {
            $catalog['menus'][self::SLUG] = 'top';
        }
        return $catalog;
    }

    /**
     * The screen's address.
     *
     * @param string $view Hash view, e.g. 'overview'.
     * @return string
     */
    public static function url($view = 'overview') {
        return admin_url('admin.php?page=' . self::SLUG . '#/' . $view);
    }

    /**
     * Register the menu and its Overview item. Settings is added after
     * these (SEOProStats_Admin_Manager, priority 20).
     */
    public static function register_menu() {
        add_menu_page(
            __('SEO Pro Stats', 'seoprostats'),
            __('SEO Pro Stats', 'seoprostats'),
            SEOProStats_API::CAP,
            self::SLUG,
            array(__CLASS__, 'render'),
            self::icon(),
            self::POSITION
        );
        add_submenu_page(
            self::SLUG,
            __('Overview', 'seoprostats'),
            __('Overview', 'seoprostats'),
            SEOProStats_API::CAP,
            self::SLUG,
            array(__CLASS__, 'render')
        );
    }

    /**
     * The menu icon: rising bars and a star, in one colour so WordPress
     * paints it to match the admin colour scheme.
     *
     * @return string Data URI.
     */
    public static function icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M1 17h18v1.5H1zM2.5 11H6v5H2.5zM8.25 8h3.5v8h-3.5zM14 4h3.5v12H14zM5 1.8l.72 2.21h2.32l-1.88 1.37.72 2.21L5 6.22 3.12 7.59l.72-2.21-1.88-1.37h2.32z"/></svg>';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- add_menu_page() takes an SVG icon as a base64 data URI.
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * The screen: a heading, then the app.
     */
    public static function render() {
        ?>
        <div class="wrap spst-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('SEO Pro Stats', 'seoprostats'); ?></h1>
            <hr class="wp-header-end">
            <div id="spst-dashboard">
                <p class="spst-loading"><?php esc_html_e('Loading statistics…', 'seoprostats'); ?></p>
                <noscript><p><?php esc_html_e('The statistics need JavaScript. They are also available through the REST API and WP-CLI (wp seoprostats stats).', 'seoprostats'); ?></p></noscript>
            </div>
        </div>
        <?php
    }

    /**
     * Register the Dashboard widget for people who can read statistics.
     */
    public static function register_widget() {
        if (!current_user_can(SEOProStats_API::CAP)) {
            return;
        }
        wp_add_dashboard_widget(self::WIDGET, __('SEO Pro Stats', 'seoprostats'), array(__CLASS__, 'render_widget'));
    }

    /**
     * The widget's holder; the app fills it.
     */
    public static function render_widget() {
        echo '<div id="spst-widget"><p class="spst-loading">' . esc_html__('Loading statistics…', 'seoprostats') . '</p></div>';
    }

    /**
     * Load the app on the screen, and the widget's on the Dashboard.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue($hook) {
        if ($hook === self::HOOK) {
            self::enqueue_entry('dashboard');
        } elseif ($hook === 'index.php' && current_user_can(SEOProStats_API::CAP)) {
            self::enqueue_entry('widget');
        }
    }

    /**
     * Enqueue a built entry (assets/build/{name}.js and .css) with the
     * dependencies and version its .asset.php file lists.
     *
     * @param string $name Entry name.
     */
    private static function enqueue_entry($name) {
        $asset_file = SEOPROSTATS_DIR . 'assets/build/' . $name . '.asset.php';
        if (!is_readable($asset_file)) {
            return;
        }
        $asset   = require $asset_file;
        $deps    = isset($asset['dependencies']) ? (array) $asset['dependencies'] : array();
        $version = isset($asset['version']) ? (string) $asset['version'] : SEOPROSTATS_VERSION;
        $handle  = 'seoprostats-' . $name;

        // WordPress before 6.6 has no react-jsx-runtime script: stand in for it.
        $shim = !wp_script_is('react-jsx-runtime', 'registered') && in_array('react-jsx-runtime', $deps, true);
        if ($shim) {
            $deps = array_values(array_diff($deps, array('react-jsx-runtime')));
            $deps = array_values(array_unique(array_merge($deps, array('react'))));
        }

        wp_enqueue_script($handle, SEOPROSTATS_URL . 'assets/build/' . $name . '.js', $deps, $version, true);
        if ($shim) {
            wp_add_inline_script($handle, self::jsx_runtime_shim(), 'before');
        }
        wp_add_inline_script($handle, 'window.seoprostatsBoot = ' . wp_json_encode(self::boot()) . ';', 'before');
        wp_set_script_translations($handle, 'seoprostats');

        $style = 'assets/build/' . $name . (is_rtl() ? '-rtl' : '') . '.css';
        if (is_readable(SEOPROSTATS_DIR . $style)) {
            wp_enqueue_style($handle, SEOPROSTATS_URL . $style, array('wp-components'), $version);
        }
    }

    /**
     * What the app needs to know about the site and the user.
     *
     * @return array<string,mixed>
     */
    private static function boot() {
        return array(
            'locale'       => get_user_locale(),
            'timezone'     => wp_timezone_string(),
            'dashboardUrl' => self::url(),
            'settingsUrl'  => SEOProStats_Admin_Manager::page_url(),
            'canManage'    => current_user_can('manage_options'),
        );
    }

    /**
     * ReactJSXRuntime (jsx, jsxs, Fragment) on WordPress's React, as the
     * react-jsx-runtime script provides from WordPress 6.6.
     *
     * @return string
     */
    private static function jsx_runtime_shim() {
        return 'window.ReactJSXRuntime = window.ReactJSXRuntime || (function (React) {'
            . 'function jsx(type, props, key) {'
            . 'var p = {}; for (var k in props) { if (Object.prototype.hasOwnProperty.call(props, k)) { p[k] = props[k]; } }'
            . 'if (key !== undefined) { p.key = key; }'
            . 'return React.createElement(type, p);'
            . '}'
            . 'return { jsx: jsx, jsxs: jsx, Fragment: React.Fragment };'
            . '}(window.React));';
    }
}
