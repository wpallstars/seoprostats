<?php
/**
 * Moving from another statistics plugin: one clear next step, with its
 * link, from the moment the plugin's data is found until the plugin and
 * its data are gone (SEOProStats_Migrate). Per plugin:
 *
 * - import: its data has days not imported yet (before SEO Pro Stats's own
 *   first day, not filled by another import): Import its history.
 * - check: nothing left to import and it still records statistics: check
 *   the imported days, then deactivate and delete it (turning on its own
 *   delete-data setting first, when it has one), or the adapter's own
 *   step (Jetpack: switch off its Stats module).
 * - remove: it no longer records statistics but its data is on the site:
 *   Delete it (its own uninstall), or Remove leftover data on the Import
 *   tab.
 * - none: plugin and data gone; nothing shown.
 *
 * SEO Pro Stats keeps counting the whole time: statistics plugins are
 * never in a setting's `replaces` list (SEOProStats_Replaced_Plugins),
 * which would pause it.
 *
 * Shown to people who can activate plugins: a note under each plugin's row
 * and a notice at the top of the Plugins screen (also for deleted plugins
 * whose data is left), and the same notice on SEO Pro Stats's own screens.
 * Screens read only what the last look saved (SEOProStats_Migrate::found(),
 * in the background when plugins change or once a day), never other
 * plugins' tables. "Hide" hides the listed steps for that person; a plugin
 * shows again when its step changes.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Migrate_Notices {

    /** admin-post action, also the nonce action. */
    const HIDE = 'seoprostats_hide_migrate_notices';

    /** User meta: "key:state" items the person hid. */
    const HIDDEN = 'seoprostats_migrate_notices_hidden';

    /** The steps, in the order a plugin goes through them. */
    const STATES = array('import', 'check', 'remove');

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        add_action('load-plugins.php', array(__CLASS__, 'load_plugins_screen'));
        add_action('admin_notices', array(__CLASS__, 'own_screen_notice'));
        add_action('admin_post_' . self::HIDE, array(__CLASS__, 'hide'));
    }

    /**
     * Plugins screen: the notice and the notes under each plugin's row.
     */
    public static function load_plugins_screen() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        add_action(is_network_admin() ? 'network_admin_notices' : 'admin_notices', array(__CLASS__, 'notice'));
        add_action('after_plugin_row', array(__CLASS__, 'row_note'), 10, 1);
        add_action('admin_head', array(__CLASS__, 'row_note_style'));
    }

    /**
     * SEO Pro Stats's statistics and settings screens: the same notice,
     * so it is seen without visiting Plugins (not on the Import tab, which
     * shows each step itself).
     */
    public static function own_screen_notice() {
        global $plugin_page;
        $pages = array(SEOProStats_Setup::MENU_PARENT, SEOProStats_Admin_Manager::PAGE);
        if (!in_array((string) $plugin_page, $pages, true) || !current_user_can('activate_plugins')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab is shown.
        if (isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === 'import') {
            return;
        }
        self::notice();
    }

    /**
     * Join a row note to its plugin's row, as core does for update notes.
     */
    public static function row_note_style() {
        echo '<style>.plugins tr:has(+ tr.spst-migrate-row) th, .plugins tr:has(+ tr.spst-migrate-row) td { box-shadow: none; }</style>' . "\n";
    }

    /**
     * Each plugin found and its step, keyed by its key; plugins with no
     * step are left out. From the saved list only (one option read); a
     * missing or day-old list is looked at again in the background.
     *
     * @return array<string,array<string,mixed>> Key => saved facts, state, file, active, network, installed, stop.
     */
    private static function items() {
        static $items = null;
        if (null !== $items) {
            return $items;
        }
        $items = array();
        $saved = get_option(SEOProStats_Collection::MIGRATE_NOTICES);
        if (!is_array($saved) || !isset($saved['sources']) || (int) $saved['at'] < time() - DAY_IN_SECONDS) {
            SEOProStats_Collection::schedule_migrate_scan();
        }
        if (!is_array($saved) || empty($saved['sources']) || !is_array($saved['sources'])) {
            return $items;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ($saved['sources'] as $key => $item) {
            if (!is_array($item) || (int) $item['pending'] < 0) {
                continue;
            }
            $file      = (string) $item['file'];
            $installed = $file !== '' && validate_file($file) === 0 && file_exists(WP_PLUGIN_DIR . '/' . $file);
            $network   = $installed && is_multisite() && is_plugin_active_for_network($file);
            $active    = $installed && ($network || is_plugin_active($file));
            $item     += array(
                'key'       => (string) $key,
                'file'      => $file,
                'installed' => $installed,
                'active'    => $active,
                'network'   => $network,
                'stop'      => null,
            );
            $item['state'] = self::state($item);
            if ($item['state'] !== '') {
                $items[(string) $key] = $item;
            }
        }
        return $items;
    }

    /**
     * A plugin's step.
     *
     * @param array<string,mixed> $item Saved facts with installed and active; stop is filled in.
     * @return string import, check, remove or '' for none.
     */
    private static function state(array &$item) {
        if ((int) $item['pending'] > 0) {
            return 'import';
        }
        $source = self::source((string) $item['key']);
        $stop   = $source ? $source->removal_step() : null;
        if (is_array($stop)) {
            $item['stop'] = $stop;
        }
        $recording = is_array($stop) ? ($item['active'] && empty($stop['done'])) : $item['active'];
        if ($recording) {
            return 'check';
        }
        return !empty($item['leftovers']) ? 'remove' : '';
    }

    /**
     * An adapter, loading the import code only when a step needs it.
     *
     * @param string $key Its key.
     * @return SEOProStats_Migrate_Source|null
     */
    private static function source($key) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        return SEOProStats_Migrate::source($key);
    }

    /**
     * The notice: every plugin with a step, less those the person hid.
     */
    public static function notice() {
        $hidden = (array) get_user_meta(get_current_user_id(), self::HIDDEN, true);
        $items  = array_filter(self::items(), static function ($item) use ($hidden) {
            return !in_array($item['key'] . ':' . $item['state'], $hidden, true);
        });
        if (!$items) {
            return;
        }
        $keys = array_map(static function ($item) {
            return $item['key'] . ':' . $item['state'];
        }, array_values($items));
        $hide = wp_nonce_url(add_query_arg(array('action' => self::HIDE, 'items' => implode(',', $keys)), admin_url('admin-post.php')), self::HIDE);
        ?>
        <div class="notice notice-info spst-migrate-notice">
            <p><strong><?php esc_html_e('Moving to SEO Pro Stats from other statistics plugins:', 'seoprostats'); ?></strong></p>
            <ul class="ul-disc">
                <?php foreach ($items as $item) : ?>
                    <li><?php echo self::line($item, true); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in line(). ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a href="<?php echo esc_url($hide); ?>"><?php esc_html_e('Hide', 'seoprostats'); ?></a></p>
        </div>
        <?php
    }

    /**
     * The note under a plugin's row on the Plugins screen.
     *
     * @param string $file Plugin file.
     */
    public static function row_note($file) {
        global $wp_list_table;
        foreach (self::items() as $item) {
            if ($item['file'] !== $file) {
                continue;
            }
            $columns = ($wp_list_table instanceof WP_List_Table) ? $wp_list_table->get_column_count() : 4;
            $active  = is_plugin_active($file) ? ' active' : ' inactive';
            printf(
                '<tr class="plugin-update-tr spst-migrate-row%1$s"><td colspan="%2$d" class="plugin-update colspanchange"><div class="notice inline notice-info notice-alt"><p>%3$s</p></div></td></tr>',
                esc_attr($active),
                (int) $columns,
                self::line($item, false) // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in line().
            );
            return;
        }
    }

    /**
     * One plugin's step, with its links.
     *
     * @param array<string,mixed> $item From items().
     * @param bool                $name Start with the plugin's name (the notice; a row note is under the name already).
     * @return string HTML.
     */
    private static function line(array $item, $name) {
        $plugin = esc_html((string) $item['name']);
        $start  = $name ? '<strong>' . $plugin . '</strong>: ' : '';
        $links  = array();
        $import = SEOProStats_Admin_Manager::tab_url('import') . '#spst-import-' . rawurlencode((string) $item['key']);

        if ($item['state'] === 'import') {
            $text = sprintf(
                /* translators: 1: first day, 2: last day. */
                esc_html__('SEO Pro Stats can bring in its history (%1$s to %2$s).', 'seoprostats'),
                esc_html(self::day((string) $item['pending_from'])),
                esc_html(self::day((string) $item['pending_to']))
            );
            if (current_user_can('manage_options')) {
                $links[] = self::link($import, __('Import its history', 'seoprostats'));
            }
            return $start . $text . self::links($links);
        }

        $stop = is_array($item['stop']) ? $item['stop'] : null;
        if ($item['state'] === 'check') {
            $imported = !empty($item['imported']);
            if ($stop) {
                $text = sprintf(
                    $imported
                        /* translators: %s: how to stop the other plugin recording statistics, such as "Switch off the Stats module in Jetpack → Settings." */
                        ? esc_html__('Its history is in SEO Pro Stats. Check the imported days, then stop its statistics: %s', 'seoprostats')
                        /* translators: %s: how to stop the other plugin recording statistics, such as "Switch off the Stats module in Jetpack → Settings." */
                        : esc_html__('SEO Pro Stats already records the days it has, so there is nothing to import. Stop its statistics: %s', 'seoprostats'),
                    esc_html((string) $stop['text'])
                );
                if (!empty($stop['url'])) {
                    $links[] = self::link((string) $stop['url'], __('Open the setting', 'seoprostats'));
                }
            } else {
                $text  = $imported
                    ? esc_html__('Its history is in SEO Pro Stats. Check the imported days, then deactivate and delete it.', 'seoprostats')
                    : esc_html__('SEO Pro Stats already records the days it has, so there is nothing to import. You can deactivate and delete it.', 'seoprostats');
                $text .= self::uninstall_hint((string) $item['key']);
                $links[] = self::deactivate_link($item);
            }
            if (!empty($item['imported']) && current_user_can('manage_options')) {
                array_unshift($links, self::link($import, __('Check the import', 'seoprostats')));
            }
            return $start . $text . self::links($links);
        }

        // remove
        if ($stop && $item['installed']) {
            $text = esc_html__('It no longer records statistics, but its data is still on the site.', 'seoprostats');
        } elseif ($item['installed']) {
            $text = esc_html__('It is inactive, but its data is still on the site.', 'seoprostats');
        } else {
            $text = esc_html__('It is deleted, but its data is still on the site.', 'seoprostats');
        }
        if (!$stop && $item['installed'] && !is_multisite() && current_user_can('delete_plugins')) {
            $url = wp_nonce_url(add_query_arg(array('action' => 'delete-selected', 'checked[]' => (string) $item['file'], 'plugin_status' => 'all'), self_admin_url('plugins.php')), 'bulk-plugins');
            /* translators: %s: plugin name. */
            $links[] = self::link($url, sprintf(__('Delete %s', 'seoprostats'), (string) $item['name']));
        }
        if (SEOProStats_Migrate::can_cleanup()) {
            $links[] = self::link($import, __('Remove leftover data', 'seoprostats'));
        }
        return $start . $text . self::links($links);
    }

    /**
     * Its own delete-data setting, when it has one: turn it on first.
     *
     * @param string $key Adapter key.
     * @return string HTML, with a leading space ('' for none).
     */
    private static function uninstall_hint($key) {
        $source  = self::source($key);
        $setting = $source ? $source->uninstall_setting() : null;
        if (!is_array($setting)) {
            return '';
        }
        return ' ' . esc_html(
            $setting['on']
                /* translators: %s: the other plugin's setting name. */
                ? sprintf(__('Its “%s” setting is on, so deleting it removes its data too.', 'seoprostats'), (string) $setting['label'])
                /* translators: 1: the other plugin's setting name, 2: where it is. */
                : sprintf(__('To have it remove its own data when deleted, first turn on its “%1$s” setting (%2$s).', 'seoprostats'), (string) $setting['label'], (string) $setting['where'])
        );
    }

    /**
     * Core's deactivate link, for people who can (network-wide plugins in
     * the network admin), keeping the Plugins list's view.
     *
     * @param array<string,mixed> $item From items().
     * @return string HTML ('' for none).
     */
    private static function deactivate_link(array $item) {
        $file = (string) $item['file'];
        if ($item['network']) {
            return current_user_can('manage_network_plugins') ? self::link(network_admin_url('plugins.php'), __('Deactivate it in Network Admin → Plugins', 'seoprostats')) : '';
        }
        if (!current_user_can('deactivate_plugin', $file)) {
            return '';
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the list being shown.
        $args = array(
            'action'        => 'deactivate',
            'plugin'        => rawurlencode($file),
            'plugin_status' => isset($_GET['plugin_status']) ? sanitize_key(wp_unslash($_GET['plugin_status'])) : 'all',
            'paged'         => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
            's'             => isset($_GET['s']) ? rawurlencode(sanitize_text_field(wp_unslash($_GET['s']))) : '',
        );
        // phpcs:enable
        $url = wp_nonce_url(add_query_arg($args, self_admin_url('plugins.php')), 'deactivate-plugin_' . $file);
        /* translators: %s: plugin name. */
        return self::link($url, sprintf(__('Deactivate %s', 'seoprostats'), (string) $item['name']));
    }

    /**
     * A link.
     *
     * @param string $url  Unescaped URL.
     * @param string $text Unescaped text.
     * @return string HTML.
     */
    private static function link($url, $text) {
        return sprintf('<a href="%1$s">%2$s</a>', esc_url($url), esc_html($text));
    }

    /**
     * Links after a step, separated as core separates row actions.
     *
     * @param string[] $links HTML links.
     * @return string HTML, with a leading space ('' for none).
     */
    private static function links(array $links) {
        $links = array_values(array_filter($links));
        return $links ? ' ' . implode(' | ', $links) : '';
    }

    /**
     * A day in the site's date format.
     *
     * @param string $ymd Y-m-d.
     * @return string
     */
    private static function day($ymd) {
        $time = strtotime($ymd . ' 12:00:00 UTC');
        $text = $ymd !== '' && $time ? wp_date((string) get_option('date_format'), $time, new DateTimeZone('UTC')) : '';
        return is_string($text) && $text !== '' ? $text : $ymd;
    }

    /**
     * admin-post: hide the listed steps for this person.
     */
    public static function hide() {
        check_admin_referer(self::HIDE);
        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('You are not allowed to manage plugins on this site.', 'seoprostats'), '', array('response' => 403));
        }
        $items  = isset($_GET['items']) ? explode(',', sanitize_text_field(wp_unslash($_GET['items']))) : array();
        $states = implode('|', self::STATES);
        $items  = array_filter($items, static function ($item) use ($states) {
            return (bool) preg_match('/^[a-z0-9._-]{1,100}:(' . $states . ')$/', $item);
        });
        $hidden = (array) get_user_meta(get_current_user_id(), self::HIDDEN, true);
        update_user_meta(get_current_user_id(), self::HIDDEN, array_values(array_unique(array_filter(array_merge($hidden, $items)))));

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : self_admin_url('plugins.php'));
        exit;
    }
}
