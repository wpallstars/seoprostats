<?php
/**
 * What makes this plugin SEO Pro Stats: its features, settings tabs,
 * links, settings history and the helpers only it needs.
 *
 * The other files in includes/ and admin/ that the starter plugin also has
 * (the feature registry, settings store, base feature and admin screen)
 * read this class and differ from the starter's only in names. Keep
 * anything that only this plugin needs here, in features or in its own
 * files loaded from here.
 *
 * The starter has no features yet, so its settings screen shows the first
 * settings tab with "No settings yet" and the Read Me tab. Add a feature:
 * STANDARDS.md → Structure and README.md → Developers.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Setup {

    /**
     * Built-in features, in the order their cards appear within each tab.
     * Each lives in includes/features/class-{lowercase-hyphenated-name}.php,
     * for example SEOProStats_Example in class-seoprostats-example.php.
     */
    const FEATURES = array();

    /**
     * Features that some builds leave out, loaded only when their file is
     * present (for example one listed in .distignore-wporg).
     */
    const OPTIONAL_FEATURES = array();

    /**
     * Settings version. SEOProStats_Settings::maybe_migrate() runs
     * migrate() below and every feature's migrate() once per version.
     * After a release, bump it for a new or changed import and add a line.
     *
     * v1: first version.
     */
    const DB_VERSION = 1;

    /**
     * Renamed tab slugs, old => new. Settings that still use an old slug
     * land on the new tab, and old admin links open the new tab.
     */
    const RENAMED_TABS = array();

    /**
     * Links in the settings screen header; leave one out for no button.
     *
     * - source:  the plugin's code (its GitHub repository)
     * - support: where people report problems (the plugin's GitHub issues)
     * - donate:  where people can support the maker
     *
     * @return array<string,string>
     */
    public static function header_links() {
        return array(
            'source'  => 'https://github.com/wpallstars/seoprostats',
            'support' => 'https://github.com/wpallstars/seoprostats/issues',
            'donate'  => 'https://buymeacoffee.com/marcusquinn',
        );
    }

    /**
     * Load the plugin's own helpers, before the features.
     */
    public static function load() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-schema.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-collection.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-api.php';
    }

    /**
     * Register the helpers' hooks, after the settings store's.
     */
    public static function init() {
        // Tables are made and upgraded on admin requests, never on visitor pages.
        add_action('admin_init', static function () {
            SEOProStats_Schema::maybe_upgrade();
        });
        SEOProStats_Collection::init();
        SEOProStats_API::init();

        if (defined('WP_CLI') && WP_CLI) {
            SEOProStats_API::load();
            // Registers `wp seoprostats`.
            require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-cli.php';
        }
    }

    /**
     * Imports that belong to no feature, run once per DB_VERSION before
     * the features' own (for example settings from the plugin's earlier
     * names). Never write or delete other plugins' options.
     *
     * @param array $options      Stored settings (raw, without defaults).
     * @param int   $from_version Stored settings version before this upgrade.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        unset($from_version);
        return $options;
    }

    /**
     * Settings tabs, in navigation order. A tab shows only when a setting
     * uses it; with no settings at all, the first one shows, empty.
     *
     * @return array<string,array{label:string,description:string}>
     */
    public static function settings_tabs() {
        return array(
            'general' => array(
                'label'       => __('General', 'seoprostats'),
                'description' => __('SEO Pro Stats\'s settings appear here as features are added.', 'seoprostats'),
            ),
        );
    }

    /**
     * Admin requests: load and start the plugin's own admin parts (other
     * tabs with the seoprostats_admin_tabs filter, scripts with the
     * seoprostats_admin_enqueue action).
     */
    public static function admin() {
    }
}
