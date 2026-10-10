<?php
/**
 * Colour mode for the plugin's own screens (Statistics and Settings):
 * Light, Dark or System, chosen per person with the button right of Buy me
 * a coffee in the header, and kept in their user meta, as WordPress keeps
 * the admin colour scheme (and saved through admin-ajax, as it saves that).
 *
 * A few lines in the screen's <head> set the mode's classes on <html>
 * before anything is drawn (spst-dark when Dark, or System while the
 * computer is dark), so the screen never flashes light. The dark styles
 * (admin/css/seoprostats-theme.css) wait for that class; the button and
 * its menu are admin/js/seoprostats-theme.js. Other wp-admin screens, the
 * Dashboard widget and the block editor keep WordPress's colours.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Admin_Theme {

    /** User meta holding the person's mode. */
    const META = 'seoprostats_admin_theme';

    /** The admin-ajax action and nonce action that save it. */
    const ACTION = 'seoprostats_admin_theme';

    /** Modes; the first is the default, WordPress's own light admin. */
    const MODES = array('light', 'dark', 'system');

    /** Dark styles, relative to the plugin directory. */
    const CSS_FILE = 'admin/css/seoprostats-theme.css';

    /** The button and its menu, relative to the plugin directory. */
    const JS_FILE = 'admin/js/seoprostats-theme.js';

    /** Whether this request draws one of the plugin's screens. */
    private static $screen = false;

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        // After the screens' own styles (priority 10), so these load last.
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('admin_head', array(__CLASS__, 'head'), 1);
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'save'));
    }

    /**
     * A person's mode.
     *
     * @param int $user_id User ID; 0 for the current user.
     * @return string One of MODES.
     */
    public static function mode($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $mode    = $user_id ? get_user_meta($user_id, self::META, true) : '';
        return in_array($mode, self::MODES, true) ? $mode : self::MODES[0];
    }

    /**
     * Hook suffixes of the plugin's screens: Statistics and Settings.
     *
     * @return string[]
     */
    private static function screens() {
        return array(SEOProStats_Dashboard::HOOK, SEOProStats_Admin_Manager::hook());
    }

    /**
     * Load the dark styles and the button on the plugin's screens only.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue($hook) {
        if (!in_array($hook, self::screens(), true)) {
            return;
        }
        self::$screen = true;
        $dir          = SEOPROSTATS_DIR;
        $css_version  = file_exists($dir . self::CSS_FILE) ? (string) filemtime($dir . self::CSS_FILE) : SEOPROSTATS_VERSION;
        $js_version   = file_exists($dir . self::JS_FILE) ? (string) filemtime($dir . self::JS_FILE) : SEOPROSTATS_VERSION;

        wp_enqueue_style('seoprostats-theme', SEOPROSTATS_URL . self::CSS_FILE, array('seoprostats-header'), $css_version);
        wp_enqueue_script('seoprostats-theme', SEOPROSTATS_URL . self::JS_FILE, array('wp-i18n', 'wp-a11y'), $js_version, true);
        wp_set_script_translations('seoprostats-theme', 'seoprostats');
        wp_add_inline_script('seoprostats-theme', 'window.seoprostatsTheme = ' . wp_json_encode(array(
            'mode'    => self::mode(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
        )) . ';', 'before');
    }

    /**
     * Set the mode's classes on <html> before the screen is drawn: first in
     * <head>, and only on the plugin's screens.
     */
    public static function head() {
        if (!self::$screen) {
            return;
        }
        $script = '(function(r,m){var q=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)");'
            . 'r.classList.add("spst-theme-"+m);'
            . 'if(m==="dark"||(m==="system"&&q&&q.matches)){r.classList.add("spst-dark");}'
            . '})(document.documentElement,' . wp_json_encode(self::mode()) . ');';
        wp_print_inline_script_tag($script, array('id' => 'seoprostats-theme-mode'));
    }

    /**
     * Save the current person's mode (admin-ajax). Only for people who can
     * open one of the plugin's screens; the value must be one of MODES.
     */
    public static function save() {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!current_user_can(SEOProStats_API::CAP) && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You cannot change this.', 'seoprostats')), 403);
        }
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        if (!in_array($mode, self::MODES, true)) {
            wp_send_json_error(array('message' => __('Unknown colour mode.', 'seoprostats')), 400);
        }
        update_user_meta(get_current_user_id(), self::META, $mode);
        wp_send_json_success(array('mode' => self::mode()));
    }
}
