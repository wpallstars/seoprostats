<?php
/**
 * SEO Pro Stats admin screen markup.
 *
 * The header, the tab navigation and the tab panels of the settings screen
 * (SEOProStats_Admin_Manager::render_settings_page()). The plugin's own screens
 * show the header through SEOProStats_Admin_Manager::render_header().
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

class SEOProStats_Admin_Page {

    /**
     * Render the screen's header: the plugin's name and version, the feature
     * search (for people who can open the settings screen) and the links
     * SEOProStats_Setup::header_links() gives.
     */
    public static function header() {
        $links = SEOProStats_Setup::header_links();
        // The search form sends page and tab itself, so its action is the
        // screen's file alone.
        $action = remove_query_arg('page', SEOProStats_Admin_Manager::page_url());
        ?>
            <header class="spst-header">
                <div class="spst-header__brand">
                    <span class="spst-header__logo dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <h1 class="spst-header__title"><?php esc_html_e('SEO Pro Stats', 'seoprostats'); ?></h1>
                    <span class="spst-badge"><?php echo esc_html('v' . SEOPROSTATS_VERSION); ?></span>
                </div>
                <?php if (current_user_can('manage_options')) : ?>
                <form class="spst-search" role="search" method="get" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr(SEOProStats_Admin_Manager::PAGE); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr(SEOProStats_Admin_Manager::SEARCH); ?>" />
                    <label class="screen-reader-text" for="spst-search-input"><?php esc_html_e('Search features', 'seoprostats'); ?></label>
                    <span class="spst-search__field">
                        <span class="spst-search__icon dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search"
                               id="spst-search-input"
                               class="spst-search__input"
                               name="s"
                               maxlength="100"
                               value="<?php echo esc_attr(SEOProStats_Admin_Manager::search_query()); ?>"
                               placeholder="<?php esc_attr_e('Search features', 'seoprostats'); ?>" />
                    </span>
                    <button type="submit" class="button spst-search__button"><?php esc_html_e('Search', 'seoprostats'); ?></button>
                </form>
                <?php endif; ?>
                <div class="spst-header__actions">
                    <?php if (!empty($links['source'])) : ?>
                        <a class="button spst-header__support" href="<?php echo esc_url($links['source']); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                            <?php esc_html_e('Source code', 'seoprostats'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostats'); ?></span>
                        </a>
                    <?php elseif (!empty($links['website'])) : ?>
                        <?php // Older {Prefix}_Setup classes link the maker's website instead. ?>
                        <a class="button" href="<?php echo esc_url($links['website']); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Visit website', 'seoprostats'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostats'); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($links['support'])) : ?>
                        <a class="button spst-header__support" href="<?php echo esc_url($links['support']); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-sos" aria-hidden="true"></span>
                            <?php esc_html_e('Support', 'seoprostats'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostats'); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($links['donate'])) : ?>
                        <a class="button spst-header__support spst-header__donate" href="<?php echo esc_url($links['donate']); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-coffee" aria-hidden="true"></span>
                            <?php esc_html_e('Buy me a coffee', 'seoprostats'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'seoprostats'); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </header>
        <?php
    }

    /**
     * Render one tab's panel. The script shows another tab's panel when its
     * link is chosen.
     *
     * @param string   $slug   Tab slug.
     * @param callable $render The tab's render callback.
     * @param bool     $shown  Whether it is the active tab.
     */
    public static function panel($slug, $render, $shown) {
        // Not <main>: core's #wpbody already carries role="main".
        ?>
        <div class="spst-main spst-tab-<?php echo esc_attr($slug); ?>" id="spst-tab-<?php echo esc_attr($slug); ?>" data-spst-panel="<?php echo esc_attr($slug); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
            <?php call_user_func($render); ?>
        </div>
        <?php
    }

    /**
     * Render the tab navigation: one labelled group of links per section
     * that has tabs. Links to tabs drawn on the page carry their slug, for
     * the script.
     *
     * @param array    $tabs      Tabs (SEOProStats_Admin_Manager::get_tabs()).
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page (SEOProStats_Admin_Manager::page_tabs()).
     */
    public static function nav(array $tabs, $active, array $page_tabs) {
        $groups = array(
            'settings' => __('Settings', 'seoprostats'),
            'discover' => __('Discover', 'seoprostats'),
            'about'    => __('About', 'seoprostats'),
        );
        ?>
        <nav class="spst-nav" aria-label="<?php esc_attr_e('SEO Pro Stats sections', 'seoprostats'); ?>">
            <?php
            foreach ($groups as $group => $group_label) {
                $group_tabs = array_filter($tabs, function ($tab) use ($group) {
                    return isset($tab['group']) && $tab['group'] === $group;
                });
                if ($group_tabs) {
                    self::nav_group($group_label, $group_tabs, $active, $page_tabs);
                }
            }
            ?>
        </nav>
        <?php
    }

    /**
     * Render one navigation group. A group of links, not form controls, so
     * role="group" with a label rather than <fieldset>.
     *
     * @param string   $label     Group label.
     * @param array    $tabs      The group's tabs.
     * @param string   $active    Active tab slug.
     * @param string[] $page_tabs Tabs drawn on the page.
     */
    private static function nav_group($label, array $tabs, $active, array $page_tabs) {
        ?>
        <div class="spst-nav__group" role="group" aria-label="<?php echo esc_attr($label); ?>">
            <?php foreach ($tabs as $slug => $tab) : ?>
                <?php $slug = (string) $slug; ?>
                <a href="<?php echo esc_url(SEOProStats_Admin_Manager::tab_url($slug)); ?>"
                   class="spst-nav__tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
                   <?php echo in_array($slug, $page_tabs, true) ? 'data-spst-tab="' . esc_attr($slug) . '"' : ''; ?>
                   <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
