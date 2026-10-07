<?php
/**
 * SEO Pro Stats admin loader.
 *
 * Loads the settings screen, the Read Me tab and the Plugins screen notes
 * for replaced plugins, then the plugin's own admin parts
 * (SEOProStats_Setup::admin()). Included from the main plugin file for
 * admin requests only.
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

$seoprostats_admin_files = array(
    'admin/data/readme.php',
    'admin/includes/class-settings-manager.php',
    'admin/includes/class-readme-manager.php',
    'admin/includes/class-admin-page.php',
    'admin/includes/class-admin-manager.php',
    'admin/includes/class-replaced-plugins.php',
);

foreach ($seoprostats_admin_files as $seoprostats_file) {
    require_once SEOPROSTATS_DIR . $seoprostats_file;
}
unset($seoprostats_admin_files, $seoprostats_file);

SEOProStats_Admin_Manager::init();
SEOProStats_Replaced_Plugins::init();
SEOProStats_Setup::admin();
