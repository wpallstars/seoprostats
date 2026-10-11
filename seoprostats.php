<?php
/**
 * Plugin Name:       SEO Pro Stats
 * Plugin URI:        https://github.com/wpallstars/seoprostats
 * Description:       Actionable analytics: connect your content to the data that shows you how to grow. Private stats, search rankings and sales, no cookies.
 * Version:           1.4.4
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marcus Quinn
 * Author URI:        https://www.wpallstars.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       seoprostats
 * GitHub Plugin URI: wpallstars/seoprostats
 * Primary Branch:    main
 * Release Asset:     true
 *
 * Copyright (C) 2026 Marcus Quinn
 * Parts copyright (C) 2026 Marcus Quinn, from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding)
 *
 * SEO Pro Stats is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version, with the additional terms in ATTRIBUTION.txt
 * (section 7(b) of the License: keep the copyright notices and the
 * starter plugin's "Made from" credit).
 *
 * SEO Pro Stats is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details: LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package SEOProStats
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEOPROSTATS_VERSION', '1.4.4');
define('SEOPROSTATS_FILE', __FILE__);
define('SEOPROSTATS_DIR', plugin_dir_path(__FILE__));
define('SEOPROSTATS_URL', plugin_dir_url(__FILE__));

require_once SEOPROSTATS_DIR . 'includes/class-seoprostats.php';
SEOProStats::load();
register_deactivation_hook(__FILE__, array('SEOProStats', 'deactivate'));

// Translations load just-in-time from WordPress.org language packs (WP 4.6+).
