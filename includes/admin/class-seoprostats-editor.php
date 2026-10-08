<?php
/**
 * Search queries in the post editor: the Search Console queries a post's
 * page shows for, which its words do not cover yet, its questions and
 * its SEO plugin's focus keywords (SEOProStats_Coverage, GET /coverage).
 *
 * The block editor gets a panel in the document sidebar; the classic
 * editor a meta box. Both are the same script (packages/wp-admin/src/
 * editor.tsx, built into assets/build/editor.js), for people who can read
 * the statistics, on post types with public pages.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Editor {

    /** Meta box id (classic editor). */
    const BOX = 'seoprostats-coverage';

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        add_action('add_meta_boxes', array(__CLASS__, 'classic_editor'), 10, 2);
        // A/B tests (SEOProStats_AB_Tests): the blocks' editor, in every block editor.
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'ab_tests'));
        add_action('enqueue_block_assets', array(__CLASS__, 'ab_tests_canvas'));
    }

    /**
     * Block editors: the A/B test blocks' editor (packages/wp-admin/src/
     * ab-test/), with the goals a test can be judged by. Tests start only
     * in posts and pages for now; the script says so in templates and
     * synced patterns.
     */
    public static function ab_tests() {
        $extra = array('wp-blocks', 'wp-block-editor', 'wp-data', 'wp-hooks', 'wp-compose', 'wp-plugins', 'wp-notices', 'wp-components', 'wp-element', 'wp-i18n');
        if (!SEOProStats_Dashboard::enqueue_entry('ab-test', null, $extra)) {
            return;
        }
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-goals.php';
        $before = SEOProStats_Schema::use_set('live');
        $goals  = array_map(static function ($goal) {
            return array('id' => $goal['id'], 'name' => $goal['name']);
        }, SEOProStats_Goals::goals());
        SEOProStats_Schema::use_set($before);
        wp_add_inline_script('seoprostats-ab-test', 'window.seoprostatsAbTests = ' . wp_json_encode(array(
            'goals'    => $goals,
            'goalsUrl' => current_user_can(SEOProStats_API::CAP) ? SEOProStats_Dashboard::url() . '#/goals' : '',
        )) . ';', 'before');
    }

    /**
     * The block editor's canvas (an iframe): the A/B test blocks' outline
     * and label. wp-admin only; the site gets no styles from them.
     */
    public static function ab_tests_canvas() {
        $style = 'assets/build/ab-test' . (is_rtl() ? '-rtl' : '') . '.css';
        $asset = SEOPROSTATS_DIR . 'assets/build/ab-test.asset.php';
        if (!is_admin() || !is_readable(SEOPROSTATS_DIR . $style) || !is_readable($asset)) {
            return;
        }
        $asset = require $asset;
        wp_enqueue_style('seoprostats-ab-test-canvas', SEOPROSTATS_URL . $style, array(), isset($asset['version']) ? (string) $asset['version'] : SEOPROSTATS_VERSION);
    }

    /**
     * The post being edited, when it is one whose queries may be shown.
     *
     * @return WP_Post|null
     */
    private static function post() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $post   = get_post();
        if (!$screen || $screen->base !== 'post' || !$post instanceof WP_Post) {
            return null;
        }
        return self::shows($post) ? $post : null;
    }

    /**
     * Whether a post gets the panel: people who can read the statistics,
     * on post types with public pages.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    private static function shows(WP_Post $post) {
        if (!current_user_can(SEOProStats_API::CAP) || !is_post_type_viewable($post->post_type)) {
            return false;
        }
        /**
         * Filters whether the editor shows a post's search queries.
         *
         * @param bool    $show Default: for people who can read the statistics, on public post types.
         * @param WP_Post $post The post being edited.
         */
        return (bool) apply_filters('seoprostats_editor_coverage', true, $post);
    }

    /**
     * Block editor: the panel's script.
     */
    public static function block_editor() {
        $post = self::post();
        if ($post) {
            self::enqueue($post, 'block');
        }
    }

    /**
     * Classic editor: a meta box the script fills.
     *
     * @param string       $post_type Post type.
     * @param WP_Post|null $post      Post.
     */
    public static function classic_editor($post_type, $post = null) {
        if (!$post instanceof WP_Post || (function_exists('use_block_editor_for_post') && use_block_editor_for_post($post)) || !self::shows($post)) {
            return;
        }
        add_meta_box(self::BOX, __('Search queries', 'seoprostats'), array(__CLASS__, 'render_box'), $post_type, 'side', 'default');
        self::enqueue($post, 'classic');
    }

    /**
     * The meta box's holder.
     */
    public static function render_box() {
        echo '<div id="spst-coverage"><p class="spst-loading">' . esc_html__('Loading search queries…', 'seoprostats') . '</p></div>';
    }

    /**
     * The script, with the post and the editor it runs in.
     *
     * @param WP_Post $post Post.
     * @param string  $mode block or classic.
     */
    private static function enqueue(WP_Post $post, $mode) {
        $extra = $mode === 'block' ? array('wp-plugins', 'wp-edit-post', 'wp-data') : array();
        if (!SEOProStats_Dashboard::enqueue_entry('editor', null, $extra)) {
            return;
        }
        wp_add_inline_script('seoprostats-editor', 'window.seoprostatsEditor = ' . wp_json_encode(array(
            'postId' => (int) $post->ID,
            'mode'   => $mode,
        )) . ';', 'before');
    }
}
