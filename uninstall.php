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
require_once __DIR__ . '/includes/stats/class-seoprostats-collection.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-demo.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-goals.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-purchases.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-connections.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-search-import.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-audit.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-indexation.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-targets.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-backlinks.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-inspections.php';
require_once __DIR__ . '/includes/stats/class-seoprostats-indexnow.php';

/**
 * Delete the plugin's tables (live and demo), collector folder, cron job,
 * options and transients for the current site.
 */
function seoprostats_uninstall_site() {
    global $wpdb;

    SEOProStats_Demo::remove();
    SEOProStats_Schema::drop();
    SEOProStats_Goals::forget();
    SEOProStats_Audit::reset();
    SEOProStats_Indexation::reset();
    SEOProStats_Targets::reset();
    SEOProStats_Backlinks::reset();
    SEOProStats_Inspections::reset();
    SEOProStats_IndexNow::forget();
    SEOProStats_Purchases::forget();
    SEOProStats_Search_Import::forget();
    SEOProStats_Connections::forget();
    SEOProStats_Collection::remove();
    delete_option('seoprostats_options');
    delete_option('seoprostats_options_lock');
    delete_option('seoprostats_db_version');
    delete_option('seoprostats_page_cache');
    delete_option('seoprostats_shares');
    wp_clear_scheduled_hook('seoprostats_page_cache_purge');
    // Imports from other statistics plugins (SEOProStats_Migrate::forget();
    // the found list is a transient, removed below). Their plugins' own
    // data is never touched here.
    wp_clear_scheduled_hook(SEOProStats_Collection::MIGRATE_HOOK);
    wp_clear_scheduled_hook(SEOProStats_Collection::MIGRATE_SCAN_HOOK);
    delete_option(SEOProStats_Collection::MIGRATE_NOTICES);
    delete_option('seoprostats_migrate');
    delete_option('seoprostats_migrate_lock');
    // Our copy of Jetpack Stats' daily counts (SEOProStats_Migrate_Jetpack::SERIES_OPTION).
    delete_option('seoprostats_migrate_jetpack');
    delete_option('seoprostats_imported');
    delete_option('seoprostats_imported_demo');

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

// Hidden "Moving to SEO Pro Stats from other statistics plugins" steps.
delete_metadata('user', 0, 'seoprostats_migrate_notices_hidden', '', true);

// Who chose to see the demo data.
delete_metadata('user', 0, 'seoprostats_data', '', true);

// Latest GitHub releases (the shared GitHub updater). Only a cache: another
// plugin's copy asks again.
delete_site_transient('wpallstars_github_releases');
