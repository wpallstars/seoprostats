<?php
/**
 * Remove SEO Pro Stats's settings and caches on uninstall (every site on
 * multisite). Add every option, post meta, user meta, transient, cron hook
 * and file a feature stores (STANDARDS.md → Structure).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/stats/class-seoprostats-schema.php';

/**
 * Delete the plugin's tables, options and transients for the current site.
 */
function seoprostats_uninstall_site() {
    global $wpdb;

    SEOProStats_Schema::drop();
    delete_option('seoprostats_options');
    delete_option('seoprostats_options_lock');
    delete_option('seoprostats_db_version');

    $patterns = array('_transient_seoprostats_', '_transient_timeout_seoprostats_');
    foreach ($patterns as $pattern) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our transients.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($pattern) . '%'));
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $seoprostats_site_id) {
        switch_to_blog($seoprostats_site_id);
        seoprostats_uninstall_site();
        restore_current_blog();
    }
} else {
    seoprostats_uninstall_site();
}

// Hidden "SEO Pro Stats can do the job of these plugins" lines.
delete_metadata('user', 0, 'seoprostats_replaced_plugins_hidden', '', true);

// Latest GitHub releases (the shared GitHub updater). Only a cache: another
// plugin's copy asks again.
delete_site_transient('wpallstars_github_releases');
