<?php
/**
 * The change log: what changed on the site, when, and on which page, so
 * every chart can show it and agents can tell why traffic, rankings or
 * sales moved.
 *
 * Recorded from WordPress hooks inside the requests that make the change
 * (saving a post, a product or a setting, updating a plugin), never on a
 * visitor's page view: posts and pages published, unpublished, moved,
 * retitled and edited (words, internal links, external hosts); the SEO
 * title, description, robots and canonical of Yoast SEO, Rank Math,
 * SEOPress and The SEO Framework; WooCommerce prices, sales, stock and
 * coupons, and Easy Digital Downloads prices; plugins, themes and
 * WordPress updated or switched; and the settings that change how search
 * engines see the site. Imports, autosaves, revisions and non-public post
 * types are skipped.
 *
 * Changes are site facts: no visitor data is involved. They are kept for
 * good (one small row each). Design: docs/architecture.md → Changes.
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

/**
 * Stable WordPress hook and change-log facade; private helpers isolate each operation.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Hook families share request-local deduplication and snapshots; procedural branches are separated into helpers.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Keeping the public hook facade and its private implementation together preserves the single-file loading contract.
 * @SuppressWarnings(PHPMD.TooManyMethods) Named private operations keep hook implementations simple without changing the public facade.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Public methods are existing WordPress callbacks and change-log API entry points.
 */
final class SEOProStats_Changes { // NOSONAR: existing public hook/API facade stays intact; private helpers separate its operations within the single-file loading contract.

    /**
     * Kinds of change: code (the table's kind column) => name and group.
     * Codes never change meaning; new kinds take new codes. 60 and up are
     * kept for search engine updates (feeds) and 80 and up for notes.
     */
    const KINDS = array(
        1  => array('published', 'content'),
        2  => array('unpublished', 'content'),
        3  => array('address', 'content'),
        4  => array('title', 'content'),
        5  => array('content', 'content'),
        6  => array('links', 'content'),
        7  => array('external_links', 'content'),
        10 => array('seo_title', 'seo'),
        11 => array('meta_description', 'seo'),
        12 => array('robots', 'seo'),
        13 => array('canonical', 'seo'),
        14 => array('backlink_new', 'seo'),
        15 => array('backlink_lost', 'seo'),
        16 => array('index_status', 'search'),
        20 => array('out_of_stock', 'product'),
        21 => array('back_in_stock', 'product'),
        22 => array('price_up', 'product'),
        23 => array('price_down', 'product'),
        24 => array('sale_started', 'product'),
        25 => array('sale_ended', 'product'),
        26 => array('coupon_published', 'product'),
        27 => array('coupon_changed', 'product'),
        28 => array('coupon_removed', 'product'),
        30 => array('ab_test_started', 'content'),
        31 => array('ab_test_paused', 'content'),
        32 => array('ab_test_ended', 'content'),
        33 => array('ab_test_winner', 'content'),
        40 => array('plugin_installed', 'site'),
        41 => array('plugin_updated', 'site'),
        42 => array('plugin_activated', 'site'),
        43 => array('plugin_deactivated', 'site'),
        44 => array('plugin_deleted', 'site'),
        45 => array('theme_switched', 'site'),
        46 => array('theme_updated', 'site'),
        47 => array('core_updated', 'site'),
        48 => array('search_visibility', 'site'),
        49 => array('permalinks', 'site'),
        50 => array('site_address', 'site'),
        51 => array('front_page', 'site'),
        60 => array('search_update', 'search'),
        80 => array('note', 'note'),
        81 => array('experiment', 'note'),
    );

    /**
     * Kind code of a search engine update (SEOProStats_Search_Updates):
     * object_type the engine, old its type (core, spam…), new its id at
     * the source; meta name, engine, url, and ended for rollouts.
     */
    const SEARCH_UPDATE = 60;

    /** Kind code of a note (annotation) added by a person or an agent. */
    const NOTE = 80;

    /**
     * Kind code of an experiment's start (SEOProStats_Experiments):
     * object_type experiment, object_id its id, new its name.
     */
    const EXPERIMENT = 81;

    /**
     * Kind codes of an A/B test's start (or restart after a pause), pause,
     * end and winner (SEOProStats_AB_Tests, on saving its post):
     * object_type ab_test, object_id its post, old the status before, new
     * its id (the winner's slug for a winner); meta name, test, and label
     * (the winner's).
     */
    const AB_STARTED = 30;
    const AB_PAUSED  = 31;
    const AB_ENDED   = 32;
    const AB_WINNER  = 33;

    /**
     * Kind codes of links from another site found or lost
     * (SEOProStats_Backlinks): one change per referring site and day;
     * object_type backlink, new the site's host, path the linked page
     * when there is one ('' for several); meta host, count, links (from,
     * to, anchor; at most MAX_LIST).
     */
    const BACKLINK_NEW  = 14;
    const BACKLINK_LOST = 15;

    /**
     * Kind code of a page whose verdict in Google's index changed, found by
     * URL Inspection (SEOProStats_Inspections): object_type inspection,
     * path the page, old and new Google's coverage states; meta verdict and
     * verdict_before (PASS, PARTIAL, FAIL, NEUTRAL).
     */
    const INDEX_STATUS = 16;

    /** Groups of kinds, for filters. */
    const GROUPS = array('content', 'seo', 'product', 'site', 'search', 'note');

    /**
     * How far before a range the timeline looks for search engine updates
     * still rolling out in it.
     */
    const ROLLOUT_LOOKBACK = 60 * DAY_IN_SECONDS;

    /** Where a change was made: code => name. */
    const SOURCES = array(
        1 => 'wordpress',
        2 => 'cli',
        3 => 'api',
        4 => 'cron',
        5 => 'feed',
        6 => 'note',
    );

    /** Longest old and new value kept (the columns' size). */
    const MAX_VALUE = 190;

    /** Most links or hosts listed in a change's details. */
    const MAX_LIST = 20;

    /** Most changes in one answer. */
    const MAX_LIMIT = 1000;

    /**
     * SEO plugins' post meta: key => kind code, and how to read the value
     * (text, or a robots rule).
     */
    const SEO_META = array(
        '_yoast_wpseo_title'               => array(10, 'text'),
        '_yoast_wpseo_metadesc'            => array(11, 'text'),
        '_yoast_wpseo_meta-robots-noindex' => array(12, 'yoast_index'),
        '_yoast_wpseo_meta-robots-nofollow' => array(12, 'yoast_follow'),
        '_yoast_wpseo_canonical'           => array(13, 'text'),
        'rank_math_title'                  => array(10, 'text'),
        'rank_math_description'            => array(11, 'text'),
        'rank_math_robots'                 => array(12, 'list'),
        'rank_math_canonical_url'          => array(13, 'text'),
        '_seopress_titles_title'           => array(10, 'text'),
        '_seopress_titles_desc'            => array(11, 'text'),
        '_seopress_robots_index'           => array(12, 'yes_noindex'),
        '_seopress_robots_follow'          => array(12, 'yes_nofollow'),
        '_seopress_robots_canonical'       => array(13, 'text'),
        '_genesis_title'                   => array(10, 'text'),
        '_genesis_description'             => array(11, 'text'),
        '_genesis_noindex'                 => array(12, 'tsf_index'),
        '_genesis_nofollow'                => array(12, 'tsf_follow'),
        '_genesis_canonical_uri'           => array(13, 'text'),
        'edd_price'                        => array(22, 'price'),
    );

    /** Settings whose changes are recorded: option => kind code. */
    const OPTIONS = array(
        'blog_public'         => 48,
        'permalink_structure' => 49,
        'home'                => 50,
        'siteurl'             => 50,
        'show_on_front'       => 51,
        'page_on_front'       => 51,
        'page_for_posts'      => 51,
    );

    /** Coupon fields whose changes are recorded. */
    const COUPON_FIELDS = array('code', 'amount', 'discount_type', 'date_expires', 'free_shipping', 'minimum_amount', 'product_ids');

    /** @var array<int,string> Published posts' paths before this request saved them: post ID => path. */
    private static $before = array();

    /** @var array<int,bool> Posts published in this request (their new meta is not a change). */
    private static $published = array();

    /** @var array<string,string> Meta values before this request changed them: "post ID|key" => value. */
    private static $old_meta = array();

    /** @var array<string,array{name:string,version:string}> Plugins and themes before an upgrade: file or slug => name and version. */
    private static $installed = array();

    /** @var array<string,bool> Changes recorded in this request (each once). */
    private static $done = array();

    /** @var string WordPress's version when this request started. */
    private static $wp_version = '';

    /**
     * Register hooks. Each runs only when its change happens.
     */
    public static function init() {
        self::$wp_version = isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '';

        // Posts, pages and products.
        add_action('pre_post_update', array(__CLASS__, 'before_post'), 10, 1);
        add_action('transition_post_status', array(__CLASS__, 'status'), 10, 3);
        add_action('post_updated', array(__CLASS__, 'post'), 10, 3);
        add_action('before_delete_post', array(__CLASS__, 'delete_post'), 10, 1);

        // SEO plugins' fields and Easy Digital Downloads prices.
        add_action('add_post_meta', array(__CLASS__, 'before_add_meta'), 10, 2);
        add_action('update_post_meta', array(__CLASS__, 'before_meta'), 10, 3);
        add_action('delete_post_meta', array(__CLASS__, 'before_delete_meta'), 10, 3);
        add_action('added_post_meta', array(__CLASS__, 'meta'), 10, 4);
        add_action('updated_post_meta', array(__CLASS__, 'meta'), 10, 4);
        add_action('deleted_post_meta', array(__CLASS__, 'deleted_meta'), 10, 3);

        // The content audit: a saved or deleted post's facts are read
        // again at the end of the request (SEO fields above too).
        add_action('save_post', array(__CLASS__, 'audit'), 10, 1);
        add_action('deleted_post', array(__CLASS__, 'audit'), 10, 1);

        // WooCommerce products (and variations) and coupons.
        add_action('woocommerce_before_product_object_save', array(__CLASS__, 'product'), 10, 1);
        add_action('woocommerce_before_product_variation_object_save', array(__CLASS__, 'product'), 10, 1);
        add_action('woocommerce_before_coupon_object_save', array(__CLASS__, 'coupon'), 10, 1);

        // Plugins, themes and WordPress.
        add_filter('upgrader_pre_install', array(__CLASS__, 'before_upgrade'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'upgraded'), 10, 2);
        add_action('activated_plugin', array(__CLASS__, 'activated'), 10, 1);
        add_action('deactivated_plugin', array(__CLASS__, 'deactivated'), 10, 1);
        add_action('delete_plugin', array(__CLASS__, 'before_delete_plugin'), 10, 1);
        add_action('deleted_plugin', array(__CLASS__, 'deleted_plugin'), 10, 2);
        add_action('switch_theme', array(__CLASS__, 'theme'), 10, 3);
        add_action('_core_updated_successfully', array(__CLASS__, 'core'), 10, 1);

        // Settings.
        foreach (array_keys(self::OPTIONS) as $option) {
            add_action('update_option_' . $option, array(__CLASS__, 'option'), 10, 3);
        }
    }

    // ------------------------------------------------------------------
    // Recording.

    /**
     * Record one change on the live data. Each change is kept once per
     * request.
     *
     * @param int                 $kind   Code from KINDS.
     * @param array<string,mixed> $change path, object_type, object_id, old, new, meta, ts.
     * @return bool Whether it was recorded.
     */
    public static function record($kind, array $change) {
        if (!isset(self::KINDS[$kind]) || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return false;
        }
        $row = self::record_object($kind, $change) + self::record_values($kind, $change);
        $key = md5(implode("\0", array($row['kind'], $row['object_type'], $row['object_id'], $row['path'], $row['old'], $row['new']))); // NOSONAR nosemgrep: a duplicate check within one request, not security.
        if (isset(self::$done[$key])) {
            return false;
        }

        /**
         * Filters a change before it is recorded; return false to leave it
         * out.
         *
         * @param array<string,mixed>|false $row  The change: ts, kind, path, object_type, object_id, old, new, meta, source, user_id.
         * @param string                    $name The kind's name, such as published or price_down.
         */
        $row = apply_filters('seoprostats_record_change', $row, self::KINDS[$kind][0]);
        if (!is_array($row)) {
            return false;
        }
        self::$done[$key] = true;

        $before = SEOProStats_Schema::use_set('live');
        try {
            $written = self::write($row);
            if ($written) {
                global $wpdb;
                $id = (int) $wpdb->insert_id;
                require_once __DIR__ . '/class-seoprostats-indexnow.php';
                SEOProStats_IndexNow::changed($row, $id);
            }
            return $written;
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Normalize a change's time and object before reading its values.
     *
     * @param int                 $kind   Kind code.
     * @param array<string,mixed> $change Input change.
     * @return array<string,mixed>
     */
    private static function record_object($kind, array $change) {
        return array(
            'ts'          => isset($change['ts']) ? (int) $change['ts'] : time(),
            'kind'        => (int) $kind,
            'path'        => isset($change['path']) ? (string) $change['path'] : '',
            'object_type' => isset($change['object_type']) ? substr((string) $change['object_type'], 0, 20) : '',
            'object_id'   => isset($change['object_id']) ? max(0, (int) $change['object_id']) : 0,
        );
    }

    /**
     * Normalize values and attribution in their original evaluation order.
     *
     * @param int                 $kind   Code from KINDS.
     * @param array<string,mixed> $change Input change.
     * @return array<string,mixed>
     */
    private static function record_values($kind, array $change) {
        return array(
            'old'     => self::record_old($kind, $change),
            'new'     => self::short(isset($change['new']) ? $change['new'] : ''),
            'meta'    => isset($change['meta']) && is_array($change['meta']) ? $change['meta'] : array(),
            'source'  => isset($change['source']) ? (int) $change['source'] : self::source(),
            'user_id' => self::record_user($change),
        );
    }

    /**
     * The old value: a moved page keeps its full address for notification;
     * write() alone applies the display column's length limit.
     *
     * @param int                 $kind   Code from KINDS.
     * @param array<string,mixed> $change Input change.
     * @return string
     */
    private static function record_old($kind, array $change) {
        if ((int) $kind === 3) {
            return (string) ($change['old'] ?? '');
        }
        return self::short(isset($change['old']) ? $change['old'] : '');
    }

    /**
     * Attribute only site editors, unless the caller supplies a user.
     *
     * @param array<string,mixed> $change Input change.
     * @return int
     */
    private static function record_user(array $change) {
        if (isset($change['user_id'])) {
            return (int) $change['user_id'];
        }
        // A customer whose order took the last item is not named.
        return current_user_can('edit_posts') ? get_current_user_id() : 0;
    }

    /**
     * Write a change to the current data set's table.
     *
     * @param array<string,mixed> $row From record().
     * @return bool
     */
    public static function write(array $row) {
        global $wpdb;
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return false;
        }
        require_once __DIR__ . '/class-seoprostats-dict.php';
        $path    = (string) $row['path'];
        $path_id = 0;
        if ($path !== '') {
            $ids     = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array($path));
            $path_id = isset($ids[SEOProStats_Dict::clean($path)]) ? (int) $ids[SEOProStats_Dict::clean($path)] : 0;
        }
        $meta = $row['meta'] ? (string) wp_json_encode($row['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing our own table.
        return (bool) $wpdb->insert(
            SEOProStats_Schema::table('changes'),
            array(
                'ts'          => (int) $row['ts'],
                'kind'        => (int) $row['kind'],
                'path_id'     => $path_id,
                'object_type' => (string) $row['object_type'],
                'object_id'   => (int) $row['object_id'],
                'old'         => self::short($row['old']),
                'new'         => self::short($row['new']),
                'meta'        => $meta,
                'source'      => (int) $row['source'],
                'user_id'     => max(0, (int) $row['user_id']),
            ),
            array('%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d')
        );
    }

    /**
     * Attach a notification receipt by the change's primary key, live only.
     * @param int $id Change ID.
     * @param array<string,mixed> $receipt Submission result.
     */
    public static function indexnow_receipt($id, array $receipt) {
        global $wpdb;
        $before = SEOProStats_Schema::use_set('live');
        try {
            $table = SEOProStats_Schema::table('changes');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- primary-key lookup in our table.
            $raw = $wpdb->get_var($wpdb->prepare('SELECT meta FROM %i WHERE id = %d', $table, $id));
            if ($raw === null) {
                return;
            }
            $meta = json_decode((string) $raw, true);
            $meta = is_array($meta) ? $meta : array();
            $meta['indexnow'] = $receipt;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- update one change by its primary key.
            $wpdb->update($table, array('meta' => wp_json_encode($meta)), array('id' => $id), array('%s'), array('%d'));
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Where the current request comes from: SOURCES code.
     *
     * @return int
     */
    public static function source() {
        if (defined('WP_CLI') && WP_CLI) {
            return 2;
        }
        if (wp_doing_cron()) {
            return 4;
        }
        if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || did_action('application_password_did_authenticate')) {
            return 3;
        }
        // The block editor saves through the REST API with the login cookie: WordPress's own screens.
        return 1;
    }

    // ------------------------------------------------------------------
    // Posts, pages and products.

    /**
     * Before a post is saved: keep a published post's address, to compare
     * it and to know where an unpublished post was.
     *
     * @param int $post_id Post.
     */
    public static function before_post($post_id) {
        $post = get_post((int) $post_id);
        if ($post instanceof WP_Post && $post->post_status === 'publish' && self::is_public($post)) {
            self::$before[(int) $post->ID] = self::post_path(self::untrashed($post));
        }
    }

    /**
     * A post with the slug it had before the bin: WordPress adds
     * "__trashed" to it before saving the post to the bin.
     *
     * @param WP_Post $post Post.
     * @return WP_Post
     */
    private static function untrashed(WP_Post $post) {
        if (substr($post->post_name, -9) !== '__trashed') {
            return $post;
        }
        $desired         = get_post_meta($post->ID, '_wp_desired_post_slug', true);
        $copy            = clone $post;
        $copy->post_name = is_string($desired) && $desired !== '' ? $desired : substr($post->post_name, 0, -9);
        return $copy;
    }

    /**
     * Published and unpublished (also scheduled posts going live and new
     * posts published straight away); coupons published and removed.
     *
     * @param string  $new  New status.
     * @param string  $old  Status before.
     * @param WP_Post $post The post.
     */
    public static function status($new, $old, $post) {
        if (!$post instanceof WP_Post || $new === $old || ($new !== 'publish' && $old !== 'publish')) {
            return;
        }
        if ($post->post_type === 'shop_coupon') {
            self::record($new === 'publish' ? 26 : 28, array(
                'object_type' => 'coupon',
                'object_id'   => $post->ID,
                'new'         => $post->post_title,
                'meta'        => array('name' => $post->post_title, 'status' => $new),
            ));
            return;
        }
        if (!self::is_public($post)) {
            return;
        }
        if ($new === 'publish') {
            self::$published[(int) $post->ID] = true;
            self::record(1, self::about($post, self::post_path($post)) + array(
                'old' => $old,
                'new' => $new,
            ));
            return;
        }
        self::record(2, self::about($post, self::path_before($post)) + array(
            'old' => $old,
            'new' => $new,
        ));
    }

    /**
     * A published post edited: address, title, words and links.
     *
     * @param int     $post_id Post.
     * @param WP_Post $after   The post now.
     * @param WP_Post $before  The post before.
     */
    public static function post($post_id, $after, $before) {
        unset($post_id); // WordPress's post_updated callback requires this positional argument.
        if (!$after instanceof WP_Post || !$before instanceof WP_Post || $after->post_status !== 'publish' || $before->post_status !== 'publish' || !self::is_public($after)) {
            return;
        }
        $ts   = time();
        $path = self::post_path($after);
        $was  = self::path_before($before);
        self::post_identity($before, $after, $was, $path, $ts);
        if ($before->post_content === $after->post_content) {
            return;
        }
        self::post_words($before, $after, $path, $ts);
        self::post_links($before, $after, $path, $ts);
    }

    /**
     * Record address and title before content changes.
     *
     * @param WP_Post $before Post before.
     * @param WP_Post $after  Post now.
     * @param string  $was    Previous path.
     * @param string  $path   Current path.
     * @param int     $ts     Shared timestamp.
     */
    private static function post_identity(WP_Post $before, WP_Post $after, $was, $path, $ts) {
        if ($was !== '' && $path !== '' && $was !== $path) {
            self::record(3, self::about($after, $path) + array(
                'ts'   => $ts,
                'old'  => $was,
                'new'  => $path,
            ));
        }
        if ($before->post_title !== $after->post_title) {
            self::record(4, self::about($after, $path) + array(
                'ts'  => $ts,
                'old' => $before->post_title,
                'new' => $after->post_title,
            ));
        }
    }

    /**
     * Record word changes before examining links.
     *
     * @param WP_Post $before Post before.
     * @param WP_Post $after  Post now.
     * @param string  $path   Current path.
     * @param int     $ts     Shared timestamp.
     */
    private static function post_words(WP_Post $before, WP_Post $after, $path, $ts) {
        $words = self::word_change($before->post_content, $after->post_content);
        if ($words['added'] || $words['removed']) {
            self::record(5, self::about($after, $path, $words) + array(
                'ts'  => $ts,
                'old' => (string) $words['before'],
                'new' => (string) $words['after'],
            ));
        }
    }

    /**
     * Record internal links followed by external hosts.
     *
     * @param WP_Post $before Post before.
     * @param WP_Post $after  Post now.
     * @param string  $path   Current path.
     * @param int     $ts     Shared timestamp.
     */
    private static function post_links(WP_Post $before, WP_Post $after, $path, $ts) {
        $then = self::links($before->post_content);
        $now  = self::links($after->post_content);
        $diff = self::link_change($then['internal'], $now['internal']);
        if ($diff['added'] || $diff['removed'] || $diff['changed']) {
            self::record(6, self::about($after, $path, $diff) + array(
                'ts'  => $ts,
                'old' => (string) count($then['internal']),
                'new' => (string) count($now['internal']),
            ));
        }
        $gained = array_values(array_diff($now['hosts'], $then['hosts']));
        $lost   = array_values(array_diff($then['hosts'], $now['hosts']));
        if ($gained || $lost) {
            self::record(7, self::about($after, $path, array(
                'gained' => array_slice($gained, 0, self::MAX_LIST),
                'lost'   => array_slice($lost, 0, self::MAX_LIST),
            )) + array(
                'ts'  => $ts,
                'old' => (string) count($then['hosts']),
                'new' => (string) count($now['hosts']),
            ));
        }
    }

    /**
     * A published post deleted without going to the bin first.
     *
     * @param int $post_id Post.
     */
    public static function delete_post($post_id) {
        $post = get_post((int) $post_id);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }
        if ($post->post_type === 'shop_coupon') {
            self::status('deleted', 'publish', $post);
            return;
        }
        if (self::is_public($post)) {
            self::record(2, self::about($post, self::post_path($post)) + array(
                'old' => 'publish',
                'new' => 'deleted',
            ));
        }
    }

    /**
     * Whether changes to a post are recorded: a public post type's item
     * (not media), not a revision or autosave.
     *
     * @param WP_Post $post Post.
     * @return bool
     */
    public static function is_public(WP_Post $post) {
        if ($post->post_type === 'attachment' || $post->post_type === 'revision' || wp_is_post_autosave($post) || wp_is_post_revision($post)) {
            return false;
        }
        return is_post_type_viewable($post->post_type);
    }

    /**
     * Details shared by a post's changes.
     *
     * @param WP_Post             $post Post.
     * @param string              $path Its page path.
     * @param array<string,mixed> $meta More details.
     * @return array<string,mixed>
     */
    private static function about(WP_Post $post, $path, array $meta = array()) {
        return array(
            'path'        => $path,
            'object_type' => $post->post_type,
            'object_id'   => $post->ID,
            'meta'        => array('name' => self::short($post->post_title)) + $meta,
        );
    }

    /**
     * A post's page path, as the statistics store it.
     *
     * @param WP_Post $post Post.
     * @return string
     */
    private static function post_path(WP_Post $post) {
        $link = get_permalink($post);
        return is_string($link) ? self::path($link) : '';
    }

    /**
     * Where a post was published: its address before this request saved
     * it, else its address as if it were still published.
     *
     * @param WP_Post $post The post (before, or now).
     * @return string
     */
    private static function path_before(WP_Post $post) {
        if (isset(self::$before[(int) $post->ID])) {
            return self::$before[(int) $post->ID];
        }
        $copy              = clone $post;
        $copy->post_status = 'publish';
        $desired           = get_post_meta($post->ID, '_wp_desired_post_slug', true);
        if (is_string($desired) && $desired !== '') {
            $copy->post_name = $desired;
        }
        return self::post_path($copy);
    }

    /**
     * Page path of a URL: path and query, as the processor stores them.
     *
     * @param string $url URL or path.
     * @return string
     */
    public static function path($url) {
        $path  = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $path  = '/' . ltrim(rawurldecode($path), '/');
        return $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * Words added and removed between two versions of a text.
     *
     * @param string $before Content before.
     * @param string $after  Content after.
     * @return array{before:int,after:int,added:int,removed:int}
     */
    public static function word_change($before, $after) {
        $old     = self::words($before);
        $new     = self::words($after);
        $added   = 0;
        $removed = 0;
        foreach ($new as $word => $count) {
            $added += max(0, $count - (isset($old[$word]) ? $old[$word] : 0));
        }
        foreach ($old as $word => $count) {
            $removed += max(0, $count - (isset($new[$word]) ? $new[$word] : 0));
        }
        return array(
            'before'  => (int) array_sum($old),
            'after'   => (int) array_sum($new),
            'added'   => $added,
            'removed' => $removed,
        );
    }

    /**
     * Each word of a post's text and how often it is there.
     *
     * @param string $html Content.
     * @return array<string,int>
     */
    private static function words($html) {
        $text  = wp_strip_all_tags(strip_shortcodes((string) $html));
        $text  = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}\'’]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out   = array();
        foreach (is_array($parts) ? $parts : array() as $word) {
            $word       = (string) $word;
            $out[$word] = isset($out[$word]) ? $out[$word] + 1 : 1;
        }
        return $out;
    }

    /**
     * Links in a post's content: internal ones (path => the first link's
     * anchor text, and path => how many links), and the hosts of external
     * ones. Internal: no host, or one of the site's hosts (as the
     * collector's).
     *
     * @param string $html Content.
     * @return array{internal:array<string,string>,counts:array<string,int>,hosts:string[]}
     */
    public static function links($html) {
        $internal = array();
        $counts   = array();
        $hosts    = array();
        if (!preg_match_all('/<a\s[^>]*>(.*?)<\/a>/is', (string) $html, $matches, PREG_SET_ORDER)) {
            return array('internal' => $internal, 'counts' => $counts, 'hosts' => $hosts);
        }
        $site = SEOProStats_Collection::hosts();
        foreach ($matches as $match) {
            $href = self::link_href($match[0]);
            if ($href === '') {
                continue;
            }
            $host = strtolower((string) wp_parse_url(strpos($href, '//') === 0 ? 'https:' . $href : $href, PHP_URL_HOST));
            if ($host === '' || in_array($host, $site, true)) {
                $to = self::path(explode('#', $href, 2)[0]);
                if (!isset($internal[$to])) {
                    $internal[$to] = self::short(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($match[1]))));
                }
                $counts[$to] = isset($counts[$to]) ? $counts[$to] + 1 : 1;
            } else {
                $hosts[strpos($host, 'www.') === 0 ? substr($host, 4) : $host] = true;
            }
        }
        $hosts = array_keys($hosts);
        sort($hosts);
        return array('internal' => $internal, 'counts' => $counts, 'hosts' => array_map('strval', $hosts));
    }

    /**
     * Read an anchor's usable HTTP(S) or relative destination.
     *
     * @param string $html Anchor markup.
     * @return string Empty for ignored links.
     */
    private static function link_href($html) {
        $tags = new WP_HTML_Tag_Processor($html);
        if (!$tags->next_tag(array('tag_name' => 'a'))) {
            return '';
        }
        $href = $tags->get_attribute('href');
        $href = is_string($href) ? trim($href) : '';
        if ($href === '' || $href[0] === '#' || $href[0] === '?') {
            return '';
        }
        $scheme = wp_parse_url($href, PHP_URL_SCHEME);
        if (is_string($scheme) && !in_array(strtolower($scheme), array('http', 'https'), true)) {
            return '';
        }
        return $href;
    }

    /**
     * Internal links added, removed, and with new anchor text.
     *
     * @param array<string,string> $before Path => anchor text.
     * @param array<string,string> $after  Path => anchor text.
     * @return array{added:array<int,array{to:string,text:string}>,removed:array<int,array{to:string,text:string}>,changed:array<int,array{to:string,old:string,new:string}>}
     */
    private static function link_change(array $before, array $after) {
        $out = array('added' => array(), 'removed' => array(), 'changed' => array());
        foreach ($after as $to => $text) {
            if (!isset($before[$to])) {
                $out['added'][] = array('to' => (string) $to, 'text' => $text);
            } elseif ($before[$to] !== $text) {
                $out['changed'][] = array('to' => (string) $to, 'old' => $before[$to], 'new' => $text);
            }
        }
        foreach ($before as $to => $text) {
            if (!isset($after[$to])) {
                $out['removed'][] = array('to' => (string) $to, 'text' => $text);
            }
        }
        foreach ($out as $list => $items) {
            $out[$list] = array_slice($items, 0, self::MAX_LIST);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // SEO fields and Easy Digital Downloads prices (post meta).

    /**
     * Before a watched field is added: it had no value.
     *
     * @param int    $post_id Post.
     * @param string $key     Meta key.
     */
    public static function before_add_meta($post_id, $key) {
        if (isset(self::SEO_META[$key])) {
            self::$old_meta[(int) $post_id . '|' . $key] = '';
        }
    }

    /**
     * Before a watched field changes: keep its value.
     *
     * @param int    $meta_id Meta row.
     * @param int    $post_id Post.
     * @param string $key     Meta key.
     */
    public static function before_meta($meta_id, $post_id, $key) {
        unset($meta_id);
        if (isset(self::SEO_META[$key])) {
            self::$old_meta[(int) $post_id . '|' . $key] = self::meta_text($key, get_post_meta((int) $post_id, $key, true));
        }
    }

    /**
     * Before a watched field is deleted: keep its value.
     *
     * @param int[]  $meta_ids Meta rows.
     * @param int    $post_id  Post.
     * @param string $key      Meta key.
     */
    public static function before_delete_meta($meta_ids, $post_id, $key) {
        self::before_meta(0, $post_id, $key);
        unset($meta_ids);
    }

    /**
     * A watched field added or changed.
     *
     * @param int    $meta_id Meta row.
     * @param int    $post_id Post.
     * @param string $key     Meta key.
     * @param mixed  $value   New value.
     */
    public static function meta($meta_id, $post_id, $key, $value) {
        unset($meta_id);
        if (isset(self::SEO_META[$key])) {
            self::meta_change((int) $post_id, (string) $key, self::meta_text($key, $value));
            self::audit($post_id);
        }
    }

    /**
     * A post saved, deleted or its SEO fields changed: the content audit
     * reads it at the end of the request (the class loads only then).
     *
     * @param int $post_id Post.
     */
    public static function audit($post_id) {
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            // Imports are read by the daily cron.
            return;
        }
        require_once __DIR__ . '/class-seoprostats-audit.php';
        SEOProStats_Audit::saved((int) $post_id);
    }

    /**
     * A watched field deleted.
     *
     * @param int[]  $meta_ids Meta rows.
     * @param int    $post_id  Post.
     * @param string $key      Meta key.
     */
    public static function deleted_meta($meta_ids, $post_id, $key) {
        unset($meta_ids);
        if (isset(self::SEO_META[$key])) {
            self::meta_change((int) $post_id, (string) $key, self::meta_text($key, ''));
            self::audit($post_id);
        }
    }

    /**
     * Record a field's change on a published post.
     *
     * @param int    $post_id Post.
     * @param string $key     Meta key.
     * @param string $new     Value now (as text).
     */
    private static function meta_change($post_id, $key, $new) {
        $slot = $post_id . '|' . $key;
        if (!array_key_exists($slot, self::$old_meta)) {
            return;
        }
        $old = self::$old_meta[$slot];
        unset(self::$old_meta[$slot]);
        $post = get_post($post_id);
        if ($old === $new || !$post instanceof WP_Post || $post->post_status !== 'publish' || isset(self::$published[$post_id]) || !self::is_public($post)) {
            return;
        }
        list($kind, $read) = self::SEO_META[$key];
        self::record_meta_change($post, $key, $read, $kind, $old, $new);
    }

    /**
     * Record SEO text or an established EDD price after consuming its snapshot.
     *
     * @param WP_Post $post Post.
     * @param string  $key  Meta key.
     * @param string  $read Value format.
     * @param int     $kind Kind code.
     * @param string  $old  Previous value.
     * @param string  $new  Current value.
     */
    private static function record_meta_change(WP_Post $post, $key, $read, $kind, $old, $new) {
        $meta = array('field' => $key);
        if ($read === 'price') {
            // Easy Digital Downloads: a price set for the first time is not a change.
            if ($old === '' || $new === '') {
                return;
            }
            $kind = (float) $new > (float) $old ? 22 : 23;
            $meta = array('field' => 'price', 'currency' => function_exists('edd_get_currency') ? (string) edd_get_currency() : '');
        }
        self::record($kind, self::about($post, self::post_path($post), $meta) + array(
            'old' => $old,
            'new' => $new,
        ));
    }

    /**
     * A watched field's value as text: robots rules as words (noindex,
     * nofollow), prices as numbers.
     *
     * @param string $key   Meta key.
     * @param mixed  $value Value.
     * @return string
     */
    public static function meta_text($key, $value) {
        $read = isset(self::SEO_META[$key]) ? self::SEO_META[$key][1] : 'text';
        if (is_array($value)) {
            $value = array_map('strval', array_filter($value, 'is_scalar'));
            sort($value);
            return implode(', ', $value);
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        $rules = array(
            'yoast_index'  => array('1' => 'noindex', '2' => 'index'),
            'yoast_follow' => array('1' => 'nofollow'),
            'tsf_follow'   => array('1' => 'nofollow'),
            'yes_noindex'  => array('yes' => 'noindex'),
            'yes_nofollow' => array('yes' => 'nofollow'),
            'tsf_index'    => array('1' => 'noindex', '-1' => 'index'),
        );
        if (isset($rules[$read])) {
            return isset($rules[$read][$value]) ? $rules[$read][$value] : '';
        }
        if ($read === 'list') {
            $list = maybe_unserialize($value);
            return is_array($list) ? self::meta_text('', $list) : $value;
        }
        return $value;
    }

    // ------------------------------------------------------------------
    // WooCommerce.

    /**
     * A WooCommerce product (or variation) about to be saved: prices,
     * sales and stock, from the stored values and the changes made.
     *
     * @param object $product WC_Product.
     */
    public static function product($product) {
        if (!is_object($product) || !method_exists($product, 'get_changes') || !method_exists($product, 'get_data') || !method_exists($product, 'get_id') || !(int) $product->get_id()) {
            return;
        }
        $changes = (array) $product->get_changes();
        $data    = (array) $product->get_data();
        if (!array_intersect_key($changes, array_flip(array('regular_price', 'sale_price', 'price', 'stock_status')))) {
            return;
        }
        $id     = (int) $product->get_id();
        $parent = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $post   = get_post($parent ? $parent : $id);
        $status = isset($changes['status']) ? (string) $changes['status'] : (isset($data['status']) ? (string) $data['status'] : '');
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || ($status !== '' && $status !== 'publish') || isset(self::$published[(int) $post->ID])) {
            return;
        }
        $name  = method_exists($product, 'get_name') ? (string) $product->get_name() : $post->post_title;
        $about = array(
            'path'        => self::post_path($post),
            'object_type' => 'product',
            'object_id'   => $post->ID,
        );
        $meta  = array(
            'name'     => self::short($name),
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
        ) + ($parent ? array('variation' => $id) : array());
        $ts    = time();
        $was   = static function ($key) use ($data) {
            return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
        };

        if (isset($changes['stock_status'])) {
            $old = $was('stock_status');
            $new = (string) $changes['stock_status'];
            if ($new === 'outofstock' && $old !== 'outofstock') {
                self::record(20, $about + array('ts' => $ts, 'old' => $old, 'new' => $new, 'meta' => $meta));
            } elseif ($old === 'outofstock' && $new !== 'outofstock') {
                self::record(21, $about + array('ts' => $ts, 'old' => $old, 'new' => $new, 'meta' => $meta));
            }
        }

        $prices = false;
        if (isset($changes['regular_price'])) {
            $prices = self::price_change($about, $meta + array('field' => 'regular'), $was('regular_price'), (string) $changes['regular_price'], $ts);
        }
        if (isset($changes['sale_price'])) {
            $old = $was('sale_price');
            $new = (string) $changes['sale_price'];
            $sale_meta = $meta + array('field' => 'sale', 'regular' => isset($changes['regular_price']) ? (string) $changes['regular_price'] : $was('regular_price'));
            if ($old === '' && $new !== '') {
                $from = isset($changes['date_on_sale_from']) ? $changes['date_on_sale_from'] : (isset($data['date_on_sale_from']) ? $data['date_on_sale_from'] : null);
                // A sale scheduled for later starts when WooCommerce's cron sets the price.
                if (!(is_object($from) && method_exists($from, 'getTimestamp') && (int) $from->getTimestamp() > time())) {
                    $prices = self::record(24, $about + array('ts' => $ts, 'old' => $was('regular_price'), 'new' => $new, 'meta' => $sale_meta)) || $prices;
                }
            } elseif ($old !== '' && $new === '') {
                $prices = self::record(25, $about + array('ts' => $ts, 'old' => $old, 'new' => $sale_meta['regular'], 'meta' => $sale_meta)) || $prices;
            } else {
                $prices = self::price_change($about, $sale_meta, $old, $new, $ts) || $prices;
            }
        }
        // The active price alone: a scheduled sale starting (or another change).
        if (!$prices && isset($changes['price']) && !isset($changes['regular_price']) && !isset($changes['sale_price'])) {
            $old  = $was('price');
            $new  = (string) $changes['price'];
            $sale = $was('sale_price');
            if ($old !== '' && $new !== '' && $sale !== '' && (float) $new === (float) $sale && (float) $new < (float) $old) {
                self::record(24, $about + array('ts' => $ts, 'old' => $old, 'new' => $new, 'meta' => $meta + array('field' => 'sale', 'scheduled' => true)));
            } else {
                self::price_change($about, $meta + array('field' => 'price'), $old, $new, $ts);
            }
        }
    }

    /**
     * Record a price going up or down (not a price set for the first time).
     *
     * @param array<string,mixed> $about Path and object.
     * @param array<string,mixed> $meta  Details.
     * @param string              $old   Price before.
     * @param string              $new   Price now.
     * @param int                 $ts    Time.
     * @return bool Whether a change was recorded.
     */
    private static function price_change(array $about, array $meta, $old, $new, $ts) {
        if ($old === '' || $new === '' || (float) $old === (float) $new) {
            return false;
        }
        return self::record((float) $new > (float) $old ? 22 : 23, $about + array('ts' => $ts, 'old' => $old, 'new' => $new, 'meta' => $meta));
    }

    /**
     * A published WooCommerce coupon (a promotion) about to be saved with
     * new terms.
     *
     * @param object $coupon WC_Coupon.
     */
    public static function coupon($coupon) {
        if (!is_object($coupon) || !method_exists($coupon, 'get_changes') || !method_exists($coupon, 'get_data') || !method_exists($coupon, 'get_id') || !(int) $coupon->get_id()) {
            return;
        }
        $post = get_post((int) $coupon->get_id());
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || isset(self::$published[(int) $post->ID])) {
            return;
        }
        $changes = array_intersect_key((array) $coupon->get_changes(), array_flip(self::COUPON_FIELDS));
        $data    = (array) $coupon->get_data();
        $fields  = array();
        foreach ($changes as $field => $value) {
            $old = self::scalar(isset($data[$field]) ? $data[$field] : '');
            $new = self::scalar($value);
            if ($old !== $new) {
                $fields[$field] = array($old, $new);
            }
        }
        if (!$fields) {
            return;
        }
        $code = method_exists($coupon, 'get_code') ? (string) $coupon->get_code() : $post->post_title;
        self::record(27, array(
            'object_type' => 'coupon',
            'object_id'   => $post->ID,
            'old'         => isset($fields['amount']) ? $fields['amount'][0] : '',
            'new'         => isset($fields['amount']) ? $fields['amount'][1] : '',
            'meta'        => array('name' => $code, 'fields' => $fields),
        ));
    }

    /**
     * A WooCommerce value as short text (dates as YYYY-MM-DD, lists joined).
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function scalar($value) {
        if (is_object($value) && method_exists($value, 'date')) {
            return (string) $value->date('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return implode(',', array_map('strval', array_filter($value, 'is_scalar')));
        }
        return is_scalar($value) ? (string) $value : '';
    }

    // ------------------------------------------------------------------
    // Plugins, themes and WordPress.

    /**
     * Before an upgrade replaces a plugin or theme: keep its name and
     * version.
     *
     * @param mixed               $response Passed on unchanged.
     * @param array<string,mixed> $extra    plugin (file) or theme (folder).
     * @return mixed
     */
    public static function before_upgrade($response, $extra) {
        if (is_array($extra) && !empty($extra['plugin']) && is_string($extra['plugin'])) {
            self::$installed['plugin:' . $extra['plugin']] = self::plugin($extra['plugin']);
        }
        if (is_array($extra) && !empty($extra['theme']) && is_string($extra['theme'])) {
            $theme = wp_get_theme($extra['theme']);
            if ($theme->exists()) {
                self::$installed['theme:' . $extra['theme']] = array('name' => (string) $theme->get('Name'), 'version' => (string) $theme->get('Version'));
            }
        }
        return $response;
    }

    /**
     * Plugins and themes installed or updated (WordPress itself:
     * core()).
     *
     * @param object              $upgrader WP_Upgrader.
     * @param array<string,mixed> $extra    type, action, plugins or themes.
     */
    public static function upgraded($upgrader, $extra) {
        if (!is_array($extra) || !isset($extra['type'], $extra['action'])) {
            return;
        }
        $type   = (string) $extra['type'];
        $action = (string) $extra['action'];
        if ($type === 'plugin' && $action === 'install') {
            $data = is_object($upgrader) && isset($upgrader->new_plugin_data) && is_array($upgrader->new_plugin_data) ? $upgrader->new_plugin_data : array();
            $file = is_object($upgrader) && method_exists($upgrader, 'plugin_info') ? (string) $upgrader->plugin_info() : '';
            $name = isset($data['Name']) ? (string) $data['Name'] : ($file !== '' ? self::plugin($file)['name'] : '');
            if ($name !== '') {
                $version = isset($data['Version']) ? (string) $data['Version'] : ($file !== '' ? self::plugin($file)['version'] : '');
                self::record(40, array('object_type' => 'plugin', 'new' => $version, 'meta' => array('name' => $name, 'file' => $file)));
            }
            return;
        }
        if ($type === 'theme' && $action === 'install') {
            $data = is_object($upgrader) && isset($upgrader->new_theme_data) && is_array($upgrader->new_theme_data) ? $upgrader->new_theme_data : array();
            if (isset($data['Name'])) {
                self::record(40, array('object_type' => 'theme', 'new' => isset($data['Version']) ? (string) $data['Version'] : '', 'meta' => array('name' => (string) $data['Name'])));
            }
            return;
        }
        if ($action !== 'update') {
            return;
        }
        if ($type === 'plugin') {
            $files = isset($extra['plugins']) ? (array) $extra['plugins'] : (isset($extra['plugin']) ? array($extra['plugin']) : array());
            foreach ($files as $file) {
                $file = (string) $file;
                $now  = self::plugin($file);
                $was  = isset(self::$installed['plugin:' . $file]) ? self::$installed['plugin:' . $file] : array('name' => '', 'version' => '');
                if ($now['name'] !== '' && $now['version'] !== $was['version']) {
                    self::record(41, array('object_type' => 'plugin', 'old' => $was['version'], 'new' => $now['version'], 'meta' => array('name' => $now['name'], 'file' => $file)));
                }
            }
        } elseif ($type === 'theme') {
            $slugs = isset($extra['themes']) ? (array) $extra['themes'] : (isset($extra['theme']) ? array($extra['theme']) : array());
            foreach ($slugs as $slug) {
                $slug  = (string) $slug;
                $theme = wp_get_theme($slug);
                $theme->cache_delete();
                $theme = wp_get_theme($slug);
                $was   = isset(self::$installed['theme:' . $slug]) ? self::$installed['theme:' . $slug] : array('name' => '', 'version' => '');
                $now   = (string) $theme->get('Version');
                if ($theme->exists() && $now !== $was['version']) {
                    self::record(46, array('object_type' => 'theme', 'old' => $was['version'], 'new' => $now, 'meta' => array('name' => (string) $theme->get('Name'), 'slug' => $slug)));
                }
            }
        }
    }

    /**
     * A plugin's name and version, read from its file.
     *
     * @param string $file Plugin file, relative to the plugins folder.
     * @return array{name:string,version:string}
     */
    private static function plugin($file) {
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (validate_file($file) !== 0 || !is_file($path)) {
            return array('name' => '', 'version' => '');
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($path, false, false);
        return array('name' => (string) $data['Name'], 'version' => (string) $data['Version']);
    }

    /**
     * A plugin activated.
     *
     * @param string $file Plugin file.
     */
    public static function activated($file) {
        self::plugin_change(42, (string) $file);
    }

    /**
     * A plugin deactivated.
     *
     * @param string $file Plugin file.
     */
    public static function deactivated($file) {
        self::plugin_change(43, (string) $file);
    }

    /**
     * Before a plugin is deleted: keep its name and version.
     *
     * @param string $file Plugin file.
     */
    public static function before_delete_plugin($file) {
        self::$installed['plugin:' . $file] = self::plugin((string) $file);
    }

    /**
     * A plugin deleted.
     *
     * @param string $file    Plugin file.
     * @param bool   $deleted Whether it was.
     */
    public static function deleted_plugin($file, $deleted) {
        $was = isset(self::$installed['plugin:' . $file]) ? self::$installed['plugin:' . $file] : array('name' => '', 'version' => '');
        if ($deleted && $was['name'] !== '') {
            self::record(44, array('object_type' => 'plugin', 'old' => $was['version'], 'meta' => array('name' => $was['name'], 'file' => (string) $file)));
        }
    }

    /**
     * Record a plugin's change with its name and version.
     *
     * @param int    $kind Code.
     * @param string $file Plugin file.
     */
    private static function plugin_change($kind, $file) {
        $plugin = self::plugin($file);
        if ($plugin['name'] !== '') {
            self::record($kind, array('object_type' => 'plugin', 'new' => $plugin['version'], 'meta' => array('name' => $plugin['name'], 'file' => $file)));
        }
    }

    /**
     * The theme switched.
     *
     * @param string   $name      New theme's name.
     * @param WP_Theme $new_theme New theme.
     * @param WP_Theme $old_theme Theme before.
     */
    public static function theme($name, $new_theme = null, $old_theme = null) {
        $old = $old_theme instanceof WP_Theme ? (string) $old_theme->get('Name') : '';
        self::record(45, array(
            'object_type' => 'theme',
            'old'         => $old,
            'new'         => (string) $name,
            'meta'        => array(
                'name'    => (string) $name,
                'version' => $new_theme instanceof WP_Theme ? (string) $new_theme->get('Version') : '',
            ),
        ));
    }

    /**
     * WordPress updated.
     *
     * @param string $version New version.
     */
    public static function core($version) {
        if ((string) $version !== self::$wp_version) {
            self::record(47, array('object_type' => 'core', 'old' => self::$wp_version, 'new' => (string) $version, 'meta' => array('name' => 'WordPress')));
        }
    }

    /**
     * A recorded setting changed.
     *
     * @param mixed  $old    Value before.
     * @param mixed  $new    Value now.
     * @param string $option Option name.
     */
    public static function option($old, $new, $option) {
        if (!isset(self::OPTIONS[$option]) || self::scalar($old) === self::scalar($new)) {
            return;
        }
        $kind = self::OPTIONS[$option];
        $meta = array('name' => $option);
        $old  = self::scalar($old);
        $new  = self::scalar($new);
        if ($option === 'page_on_front' || $option === 'page_for_posts') {
            $meta += array('old_title' => $old ? self::short(get_the_title((int) $old)) : '', 'new_title' => $new ? self::short(get_the_title((int) $new)) : '');
        }
        self::record($kind, array('object_type' => 'option', 'old' => $old, 'new' => $new, 'meta' => $meta));
    }

    // ------------------------------------------------------------------
    // Reading.

    // ------------------------------------------------------------------
    // Notes (annotations).

    /**
     * Add a note to the current data set's change log: something the hooks
     * cannot see, such as a newsletter sent, a sale or a move to a new host.
     * Run it inside SEOProStats_API::on_data() for the demo data.
     *
     * @param string     $note Text; cut to 190 bytes.
     * @param string     $page Page path or address it is about; '' for the whole site.
     * @param int|string $when Unix time, or a date and time in the site's time zone (2026-10-05, 2026-10-05 14:30); '' for now.
     * @return array<string,mixed>|WP_Error The note as the change log lists it.
     */
    public static function annotate($note, $page = '', $when = '') {
        global $wpdb;
        $note = trim(sanitize_text_field((string) $note));
        if ($note === '') {
            return new WP_Error('seoprostats_note', __('A note needs some text.', 'seoprostats'), array('status' => 400));
        }
        $ts = self::when($when);
        if (is_wp_error($ts)) {
            return $ts;
        }
        $page  = trim((string) $page);
        $saved = self::write(array(
            'ts'          => $ts,
            'kind'        => self::NOTE,
            'path'        => $page !== '' ? self::path($page) : '',
            'object_type' => 'note',
            'object_id'   => 0,
            'old'         => '',
            'new'         => $note,
            'meta'        => array(),
            'source'      => 6,
            'user_id'     => get_current_user_id(),
        ));
        $change = $saved ? self::get((int) $wpdb->insert_id) : null;
        if (!$change) {
            return new WP_Error('seoprostats_note_failed', __('The note could not be saved.', 'seoprostats'), array('status' => 500));
        }
        return $change;
    }

    /**
     * Delete a note from the current data set. Only notes: recorded
     * changes are the site's history.
     *
     * @param int $id Note id.
     * @return bool Whether a note was deleted.
     */
    public static function delete_note($id) {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        return (bool) $wpdb->delete(SEOProStats_Schema::table('changes'), array('id' => (int) $id, 'kind' => self::NOTE), array('%d', '%d'));
    }

    /**
     * One change of the current data set, as the change log lists it.
     *
     * @param int $id Change id.
     * @return array<string,mixed>|null
     */
    public static function get($id) {
        global $wpdb;
        if ($id <= 0 || !SEOProStats_Schema::is_current()) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, ts, kind, path_id, object_type, object_id, old, new, meta, source, user_id FROM %i WHERE id = %d', SEOProStats_Schema::table('changes'), (int) $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        require_once __DIR__ . '/class-seoprostats-dict.php';
        return self::shape($row, SEOProStats_Dict::values(array((int) $row['path_id'])));
    }

    /**
     * A note's or experiment's time: Unix time, or a date and time in the
     * site's time zone.
     *
     * @param int|string $when Time; '' for now.
     * @return int|WP_Error
     */
    public static function when($when) {
        $when = trim((string) $when);
        if ($when === '') {
            return time();
        }
        if (ctype_digit($when)) {
            return (int) $when;
        }
        $date = date_create_immutable($when, wp_timezone());
        if (!$date || $date->getTimestamp() <= 0) {
            return new WP_Error(
                'seoprostats_note_time',
                /* translators: %s: the time given */
                sprintf(__('"%s" is not a date and time. Use, for example, 2026-10-05 or 2026-10-05 14:30.', 'seoprostats'), $when),
                array('status' => 400)
            );
        }
        return $date->getTimestamp();
    }

    /**
     * Changes in a range, newest first, with paging; or oldest first for
     * the timeline (markers).
     *
     * @param array<string,mixed> $req   From SEOProStats_Query::request().
     * @param array<string,mixed> $args  page (path; * for any text), kinds (names or groups), limit, offset, order (asc or desc).
     * @return array{range:array<string,string>,changes:array<int,array<string,mixed>>,total:int}|WP_Error
     */
    public static function list_changes(array $req, array $args = array()) {
        global $wpdb;
        $range = SEOProStats_Query::range($req);
        $kinds = self::kind_codes(isset($args['kinds']) ? $args['kinds'] : '');
        if (is_wp_error($kinds)) {
            return $kinds;
        }
        $limit  = max(1, min(self::MAX_LIMIT, isset($args['limit']) ? (int) $args['limit'] : 100));
        $offset = max(0, isset($args['offset']) ? (int) $args['offset'] : 0);
        $order  = isset($args['order']) && $args['order'] === 'asc' ? 'ASC' : 'DESC';
        $out    = array(
            'range'   => SEOProStats_Query::range_out($range),
            'changes' => array(),
            'total'   => 0,
        );
        if (!SEOProStats_Schema::is_current()) {
            return $out;
        }

        $where = 'ts >= %d AND ts < %d';
        $vals  = array((int) $range['from'], (int) $range['to']);
        $page  = isset($args['page']) ? trim((string) $args['page']) : '';
        if ($page !== '') {
            $ids = array_map('intval', SEOProStats_Query::dict_ids(SEOProStats_Schema::DICT_PATH, array(
                'dimension' => 'page',
                'op'        => strpos($page, '*') !== false ? 'matches' : 'is',
                'values'    => array($page),
            )));
            // The page's changes and the site's own (plugins, settings), which affect every page.
            $ids    = array_values(array_unique(array_merge(array(0), $ids)));
            $where .= ' AND path_id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
            $vals   = array_merge($vals, $ids);
        }
        if ($kinds) {
            $where .= ' AND kind IN (' . implode(', ', array_fill(0, count($kinds), '%d')) . ')';
            $vals   = array_merge($vals, $kinds);
        }
        $table = SEOProStats_Schema::table('changes');
        // Our own table, by key ts (or path_ts, kind_ts); $where holds only fixed placeholders.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $out['total'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $where", array_merge(array($table), $vals)));
        $rows         = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ts, kind, path_id, object_type, object_id, old, new, meta, source, user_id FROM %i WHERE $where ORDER BY ts $order, id $order LIMIT %d OFFSET %d",
            array_merge(array($table), $vals, array($limit, $offset))
        ), ARRAY_A);
        // phpcs:enable
        $rows = is_array($rows) ? $rows : array();
        if (!empty($args['running']) && $offset === 0 && (!$kinds || in_array(self::SEARCH_UPDATE, $kinds, true))) {
            $running      = self::running($table, (int) $range['from']);
            $rows         = $order === 'ASC' ? array_merge($running, $rows) : array_merge($rows, $running);
            $out['total'] += count($running);
        }
        $paths = SEOProStats_Dict::values(array_map('intval', wp_list_pluck($rows, 'path_id')));
        foreach ($rows as $row) {
            $out['changes'][] = self::shape($row, $paths);
        }
        return $out;
    }

    /**
     * Changes recorded on some pages between two times, newest first, at
     * most $each per page (by key path_ts). Used where a page's figures
     * moved, to set what changed on it beside them.
     *
     * @param int[] $path_ids Path ids.
     * @param int   $from     Start (timestamp, included).
     * @param int   $to       End (timestamp, left out).
     * @param int   $each     Changes per page at most.
     * @return array<int,array<int,array<string,mixed>>> Path id => changes.
     */
    public static function on_pages(array $path_ids, $from, $to, $each = 5) {
        global $wpdb;
        $path_ids = array_values(array_filter(array_unique(array_map('intval', $path_ids))));
        if (!$path_ids || !SEOProStats_Schema::is_current()) {
            return array();
        }
        $in = implode(', ', array_fill(0, count($path_ids), '%d'));
        // Our own table by key path_ts; $in holds only placeholders.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ts, kind, path_id, object_type, object_id, old, new, meta, source, user_id FROM %i FORCE INDEX (`path_ts`) WHERE path_id IN ($in) AND ts >= %d AND ts < %d ORDER BY ts DESC, id DESC LIMIT %d",
            array_merge(array(SEOProStats_Schema::table('changes')), $path_ids, array((int) $from, (int) $to, count($path_ids) * max(1, (int) $each) * 4))
        ), ARRAY_A);
        // phpcs:enable
        $paths = SEOProStats_Dict::values($path_ids);
        $out   = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $id = (int) $row['path_id'];
            if (!isset($out[$id]) || count($out[$id]) < $each) {
                $out[$id][] = self::shape($row, $paths);
            }
        }
        return $out;
    }

    /**
     * Every change recorded between two times, oldest first, at most
     * $limit (by key ts), each as the change log lists it with its
     * path_id added. Used by experiments to find the pages that changed
     * and the site-wide changes of a period.
     *
     * @param int $from  Start (timestamp, included).
     * @param int $to    End (timestamp, left out).
     * @param int $limit Most changes read.
     * @return array<int,array<string,mixed>>
     */
    public static function between($from, $to, $limit = 5000) {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by key ts.
        $rows  = $wpdb->get_results($wpdb->prepare(
            'SELECT id, ts, kind, path_id, object_type, object_id, old, new, meta, source, user_id FROM %i FORCE INDEX (`ts`) WHERE ts >= %d AND ts < %d ORDER BY ts ASC LIMIT %d',
            SEOProStats_Schema::table('changes'),
            (int) $from,
            (int) $to,
            max(1, (int) $limit)
        ), ARRAY_A);
        $rows  = is_array($rows) ? $rows : array();
        $paths = SEOProStats_Dict::values(array_map('intval', wp_list_pluck($rows, 'path_id')));
        $out   = array();
        foreach ($rows as $row) {
            $out[] = self::shape($row, $paths) + array('path_id' => (int) $row['path_id']);
        }
        return $out;
    }

    /**
     * Search engine updates rolling out at any time between two times
     * (started in it, or before it and not ended by its start), oldest
     * first.
     *
     * @param int $from Start (timestamp, included).
     * @param int $to   End (timestamp, left out).
     * @return array<int,array<string,mixed>>
     */
    public static function updates_between($from, $to) {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        $table = SEOProStats_Schema::table('changes');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by key kind_ts.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, ts, kind, path_id, object_type, object_id, old, new, meta, source, user_id FROM %i WHERE kind = %d AND ts >= %d AND ts < %d ORDER BY ts ASC, id ASC',
            $table,
            self::SEARCH_UPDATE,
            (int) $from,
            (int) $to
        ), ARRAY_A);
        $rows = array_merge(self::running($table, (int) $from), is_array($rows) ? $rows : array());
        return array_map(static function ($row) {
            return self::shape($row, array());
        }, $rows);
    }

    /**
     * Search engine updates that started before a range and were still
     * rolling out when it began (or still are), oldest first.
     *
     * @param string $table The changes table.
     * @param int    $from  The range's start.
     * @return array<int,array<string,mixed>> Rows.
     */
    private static function running($table, $from) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by key kind_ts.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, ts, kind, path_id, object_type, object_id, old, new, meta, source, user_id FROM %i WHERE kind = %d AND ts >= %d AND ts < %d ORDER BY ts ASC, id ASC',
            $table,
            self::SEARCH_UPDATE,
            $from - self::ROLLOUT_LOOKBACK,
            $from
        ), ARRAY_A);
        return array_values(array_filter(is_array($rows) ? $rows : array(), static function ($row) use ($from) {
            $meta = json_decode((string) $row['meta'], true);
            if (!is_array($meta) || !array_key_exists('ended', $meta)) {
                return false;
            }
            return $meta['ended'] === '' || strtotime((string) $meta['ended']) >= $from;
        }));
    }

    /**
     * Kind codes from a list of kind names and groups (comma-separated or
     * an array).
     *
     * @param mixed $kinds Names and groups.
     * @return int[]|WP_Error Codes; empty for all.
     */
    public static function kind_codes($kinds) {
        $names = is_array($kinds) ? $kinds : explode(',', (string) $kinds);
        $names = array_filter(array_map('trim', array_map('strval', $names)));
        $codes = array();
        foreach ($names as $name) {
            $found = false;
            foreach (self::KINDS as $code => $kind) {
                if ($kind[0] === $name || $kind[1] === $name) {
                    $codes[] = (int) $code;
                    $found   = true;
                }
            }
            if (!$found) {
                return new WP_Error(
                    'seoprostats_kind',
                    sprintf(
                        /* translators: 1: the kind asked for, 2: list of groups, 3: list of kinds */
                        __('There is no kind of change "%1$s". Groups: %2$s. Kinds: %3$s.', 'seoprostats'),
                        $name,
                        implode(', ', self::GROUPS),
                        implode(', ', self::kind_names())
                    ),
                    array('status' => 400)
                );
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * Every kind's name.
     *
     * @return string[]
     */
    public static function kind_names() {
        return array_values(array_map(static function ($kind) {
            return $kind[0];
        }, self::KINDS));
    }

    /**
     * A stored change as the API answers it.
     *
     * @param array<string,mixed> $row   Table row.
     * @param array<int,string>   $paths Path id => path.
     * @return array<string,mixed>
     */
    private static function shape(array $row, array $paths) {
        static $users = array();
        $code = (int) $row['kind'];
        $kind = isset(self::KINDS[$code]) ? self::KINDS[$code] : array('unknown', 'site');
        $meta = $row['meta'] !== '' && $row['meta'] !== null ? json_decode((string) $row['meta'], true) : array();
        $meta = is_array($meta) ? $meta : array();
        $user = null;
        $uid  = (int) $row['user_id'];
        // WP-CLI already reads the whole database; elsewhere only people who may list users see names.
        if ($uid && ((defined('WP_CLI') && WP_CLI) || current_user_can('list_users'))) {
            if (!array_key_exists($uid, $users)) {
                $data        = get_userdata($uid);
                $users[$uid] = $data ? (string) $data->display_name : null;
            }
            $user = $users[$uid];
        }
        $path   = isset($paths[(int) $row['path_id']]) ? $paths[(int) $row['path_id']] : null;
        $change = array(
            'id'     => (int) $row['id'],
            't'      => (string) wp_date('c', (int) $row['ts']),
            'kind'   => $kind[0],
            'group'  => $kind[1],
            'label'  => '',
            'title'  => isset($meta['name']) ? (string) $meta['name'] : '',
            'path'   => $path,
            'old'    => (string) $row['old'],
            'new'    => (string) $row['new'],
            'object' => array('type' => (string) $row['object_type'], 'id' => (int) $row['object_id']),
            'meta'   => (object) $meta,
            'source' => isset(self::SOURCES[(int) $row['source']]) ? self::SOURCES[(int) $row['source']] : 'wordpress',
            'user'   => $user,
        );
        $change['label'] = self::label($change, $meta);
        return $change;
    }

    /**
     * One line saying what changed, in the site's language.
     *
     * @param array<string,mixed> $c    The change (kind, title, old, new).
     * @param array<string,mixed> $meta Its details.
     * @return string
     */
    public static function label(array $c, array $meta) {
        $title = (string) $c['title'];
        $old   = (string) $c['old'];
        $new   = (string) $c['new'];
        $list  = static function ($items) {
            $text = implode(', ', array_map('strval', is_array($items) ? $items : array()));
            return $text !== '' ? $text : '–';
        };
        $money = static function ($amount) use ($meta) {
            return trim($amount . ' ' . (isset($meta['currency']) ? (string) $meta['currency'] : ''));
        };
        switch ($c['kind']) {
            case 'published':
                /* translators: %s: post title */
                return sprintf(__('Published: %s', 'seoprostats'), $title);
            case 'unpublished':
                /* translators: 1: post title, 2: new status (draft, private, trash, deleted) */
                return sprintf(__('Unpublished: %1$s (%2$s)', 'seoprostats'), $title, $new);
            case 'address':
                /* translators: 1: old path, 2: new path */
                return sprintf(__('Address changed: %1$s → %2$s', 'seoprostats'), $old, $new);
            case 'title':
                /* translators: 1: old title, 2: new title */
                return sprintf(__('Title changed: “%1$s” → “%2$s”', 'seoprostats'), $old, $new);
            case 'content':
                /* translators: 1: post title, 2: words added, 3: words removed, 4: words before, 5: words after */
                return sprintf(__('Content edited: %1$s (+%2$d −%3$d words; %4$d → %5$d)', 'seoprostats'), $title, isset($meta['added']) ? (int) $meta['added'] : 0, isset($meta['removed']) ? (int) $meta['removed'] : 0, (int) $old, (int) $new);
            case 'links':
                /* translators: 1: post title, 2: links added, 3: links removed, 4: links with new text */
                return sprintf(__('Internal links: %1$s (%2$d added, %3$d removed, %4$d changed)', 'seoprostats'), $title, isset($meta['added']) ? count((array) $meta['added']) : 0, isset($meta['removed']) ? count((array) $meta['removed']) : 0, isset($meta['changed']) ? count((array) $meta['changed']) : 0);
            case 'external_links':
                /* translators: 1: post title, 2: hosts linked to now, 3: hosts no longer linked to */
                return sprintf(__('External links: %1$s (gained: %2$s; lost: %3$s)', 'seoprostats'), $title, $list(isset($meta['gained']) ? $meta['gained'] : array()), $list(isset($meta['lost']) ? $meta['lost'] : array()));
            case 'seo_title':
                /* translators: 1: post title, 2: new SEO title */
                return sprintf(__('SEO title changed: %1$s → “%2$s”', 'seoprostats'), $title, $new);
            case 'meta_description':
                /* translators: %s: post title */
                return sprintf(__('Meta description changed: %s', 'seoprostats'), $title);
            case 'robots':
                /* translators: 1: post title, 2: rule before, 3: rule now */
                return sprintf(__('Robots: %1$s (%2$s → %3$s)', 'seoprostats'), $title, $old !== '' ? $old : __('default', 'seoprostats'), $new !== '' ? $new : __('default', 'seoprostats'));
            case 'canonical':
                /* translators: 1: post title, 2: canonical URL now */
                return sprintf(__('Canonical changed: %1$s → %2$s', 'seoprostats'), $title, $new !== '' ? $new : __('default', 'seoprostats'));
            case 'backlink_new':
                /* translators: 1: the linking site, 2: number of links */
                return sprintf(_n('New backlink: %1$s (%2$d link)', 'New backlinks: %1$s (%2$d links)', isset($meta['count']) ? (int) $meta['count'] : 1, 'seoprostats'), $new, isset($meta['count']) ? (int) $meta['count'] : 1);
            case 'backlink_lost':
                /* translators: 1: the linking site, 2: number of links */
                return sprintf(_n('Lost backlink: %1$s (%2$d link)', 'Lost backlinks: %1$s (%2$d links)', isset($meta['count']) ? (int) $meta['count'] : 1, 'seoprostats'), $new, isset($meta['count']) ? (int) $meta['count'] : 1);
            case 'index_status':
                /* translators: 1: page, 2: Google's coverage state before, 3: Google's coverage state now */
                return sprintf(__('Google index status of %1$s: %2$s → %3$s', 'seoprostats'), $title, $old !== '' ? $old : '–', $new !== '' ? $new : '–');
            case 'out_of_stock':
                /* translators: %s: product name */
                return sprintf(__('Out of stock: %s', 'seoprostats'), $title);
            case 'back_in_stock':
                /* translators: %s: product name */
                return sprintf(__('Back in stock: %s', 'seoprostats'), $title);
            case 'price_up':
                /* translators: 1: product name, 2: old price, 3: new price */
                return sprintf(__('Price up: %1$s (%2$s → %3$s)', 'seoprostats'), $title, $old, $money($new));
            case 'price_down':
                /* translators: 1: product name, 2: old price, 3: new price */
                return sprintf(__('Price down: %1$s (%2$s → %3$s)', 'seoprostats'), $title, $old, $money($new));
            case 'sale_started':
                /* translators: 1: product name, 2: sale price */
                return sprintf(__('Sale started: %1$s (%2$s)', 'seoprostats'), $title, $money($new));
            case 'sale_ended':
                /* translators: 1: product name, 2: price now */
                return sprintf(__('Sale ended: %1$s (%2$s)', 'seoprostats'), $title, $money($new));
            case 'coupon_published':
                /* translators: %s: coupon code */
                return sprintf(__('Coupon published: %s', 'seoprostats'), $title);
            case 'coupon_changed':
                /* translators: 1: coupon code, 2: fields changed */
                return sprintf(__('Coupon changed: %1$s (%2$s)', 'seoprostats'), $title, $list(isset($meta['fields']) && is_array($meta['fields']) ? array_keys($meta['fields']) : array()));
            case 'coupon_removed':
                /* translators: %s: coupon code */
                return sprintf(__('Coupon removed: %s', 'seoprostats'), $title);
            case 'ab_test_started':
                return $old === 'paused'
                    /* translators: %s: A/B test name */
                    ? sprintf(__('A/B test resumed: %s', 'seoprostats'), $title)
                    /* translators: %s: A/B test name */
                    : sprintf(__('A/B test started: %s', 'seoprostats'), $title);
            case 'ab_test_paused':
                /* translators: %s: A/B test name */
                return sprintf(__('A/B test paused: %s', 'seoprostats'), $title);
            case 'ab_test_ended':
                /* translators: %s: A/B test name */
                return sprintf(__('A/B test ended: %s', 'seoprostats'), $title);
            case 'ab_test_winner':
                /* translators: 1: A/B test name, 2: the winning variant's label */
                return sprintf(__('A/B test winner: %1$s (%2$s)', 'seoprostats'), $title, isset($meta['label']) && $meta['label'] !== '' ? (string) $meta['label'] : $new);
            case 'plugin_installed':
                /* translators: 1: plugin or theme name, 2: version */
                return sprintf(__('Installed: %1$s %2$s', 'seoprostats'), $title, $new);
            case 'plugin_updated':
                /* translators: 1: plugin name, 2: old version, 3: new version */
                return sprintf(__('Plugin updated: %1$s %2$s → %3$s', 'seoprostats'), $title, $old, $new);
            case 'plugin_activated':
                /* translators: %s: plugin name */
                return sprintf(__('Plugin activated: %s', 'seoprostats'), $title);
            case 'plugin_deactivated':
                /* translators: %s: plugin name */
                return sprintf(__('Plugin deactivated: %s', 'seoprostats'), $title);
            case 'plugin_deleted':
                /* translators: %s: plugin name */
                return sprintf(__('Plugin deleted: %s', 'seoprostats'), $title);
            case 'theme_switched':
                /* translators: 1: old theme, 2: new theme */
                return sprintf(__('Theme switched: %1$s → %2$s', 'seoprostats'), $old, $new);
            case 'theme_updated':
                /* translators: 1: theme name, 2: old version, 3: new version */
                return sprintf(__('Theme updated: %1$s %2$s → %3$s', 'seoprostats'), $title, $old, $new);
            case 'core_updated':
                /* translators: 1: old version, 2: new version */
                return sprintf(__('WordPress updated: %1$s → %2$s', 'seoprostats'), $old, $new);
            case 'search_visibility':
                return $new === '0' ? __('Search engines discouraged from indexing the site', 'seoprostats') : __('Search engines allowed to index the site', 'seoprostats');
            case 'permalinks':
                /* translators: 1: old structure, 2: new structure */
                return sprintf(__('Permalinks changed: %1$s → %2$s', 'seoprostats'), $old !== '' ? $old : __('plain', 'seoprostats'), $new !== '' ? $new : __('plain', 'seoprostats'));
            case 'site_address':
                /* translators: 1: old address, 2: new address */
                return sprintf(__('Site address changed: %1$s → %2$s', 'seoprostats'), $old, $new);
            case 'front_page':
                /* translators: 1: setting, 2: value before, 3: value now */
                return sprintf(__('Front page setting changed: %1$s (%2$s → %3$s)', 'seoprostats'), $title, isset($meta['old_title']) && $meta['old_title'] !== '' ? (string) $meta['old_title'] : $old, isset($meta['new_title']) && $meta['new_title'] !== '' ? (string) $meta['new_title'] : $new);
            case 'search_update':
                $engine = isset($meta['engine']) && $meta['engine'] !== '' ? (string) $meta['engine'] : (isset($c['object']['type']) ? (string) $c['object']['type'] : '');
                if (!array_key_exists('ended', $meta)) {
                    /* translators: 1: search engine or feed, 2: what it announced */
                    return sprintf(__('%1$s: %2$s', 'seoprostats'), $engine, $title);
                }
                if ($meta['ended'] === '') {
                    /* translators: 1: search engine, 2: the update's name */
                    return sprintf(__('%1$s: %2$s (rolling out)', 'seoprostats'), $engine, $title);
                }
                $start = strtotime((string) $c['t']);
                $end   = strtotime((string) $meta['ended']);
                /* translators: 1: search engine, 2: the update's name, 3: how long it took, such as "12 days" */
                return sprintf(__('%1$s: %2$s (%3$s)', 'seoprostats'), $engine, $title, human_time_diff((int) $start, (int) max($start, $end)));
            case 'note':
                return $new;
            case 'experiment':
                /* translators: %s: the experiment's hypothesis */
                return sprintf(__('Experiment started: %s', 'seoprostats'), $new);
            default:
                return $title;
        }
    }

    /**
     * Text cut to the columns' size.
     *
     * @param mixed $value Text.
     * @return string
     */
    private static function short($value) {
        $value = is_scalar($value) ? (string) $value : '';
        if (strlen($value) <= self::MAX_VALUE) {
            return $value;
        }
        return function_exists('mb_strcut') ? mb_strcut($value, 0, self::MAX_VALUE, 'UTF-8') : substr($value, 0, self::MAX_VALUE);
    }
}
