<?php
/**
 * A standalone, untracked report shell, without the theme or wp-admin.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Share_Page {

    /** Register only hooks; no options or queries on ordinary pages. */
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'render'), -100);
    }

    /** Print only our dependencies; never run theme head/footer or tracker hooks. */
    public static function render() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a bearer link, not an administrative action.
        if (!isset($_GET['seoprostats_share'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the REST API checks the bearer and optional password.
        $token = is_string($_GET['seoprostats_share']) ? sanitize_text_field(wp_unslash($_GET['seoprostats_share'])) : '';
        $token = preg_match('/^[a-f0-9]{32}$/D', $token) ? $token : str_repeat('0', 32);
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store');
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        status_header(200);
        require_once SEOPROSTATS_DIR . 'includes/admin/class-seoprostats-dashboard.php';
        SEOProStats_Dashboard::enqueue_entry('share', array(
            'locale' => get_locale(), 'timezone' => wp_timezone_string(), 'canManage' => false, 'data' => 'live',
        ));
        wp_add_inline_script('seoprostats-share', 'window.seoprostatsShare = ' . wp_json_encode(array(
            'token' => $token, 'root' => rest_url('seoprostats/v1/'),
        )) . ';', 'before');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php esc_html_e('Shared report', 'seoprostats'); ?></title>
            <?php wp_styles()->do_items(array('seoprostats-share')); ?>
        </head>
        <body class="spst-share">
            <main id="spst-share"><noscript><?php esc_html_e('This report needs JavaScript.', 'seoprostats'); ?></noscript></main>
            <?php wp_scripts()->do_items(array('seoprostats-share'), 1); ?>
        </body>
        </html>
        <?php
        exit;
    }
}
