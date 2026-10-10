<?php
/**
 * A/B tests of blocks: the seoprostats/ab-test and seoprostats/ab-variant
 * blocks, the ab_tests registry and the site's variant swap.
 *
 * A test block holds two or more variant blocks, each any blocks. On the
 * site the control (the first variant, or the winner once set) is normal
 * markup and the other variants wait in <template> elements, so crawlers,
 * visitors without JavaScript, feeds and page caches see one variant. For
 * a running test a tiny inline script picks a variant by weight for that
 * page load, before paint, swaps it in and marks the test's wrapper with
 * data-spst-ab="<test>:<variant>". It reads only the markup: no query,
 * option or remote request on visitor pages.
 *
 * Saving a post reads its tests into the ab_tests table (never on a
 * visitor page). Tests taken out of a post are marked removed, not
 * deleted, so their results stay readable. The editor (packages/wp-admin/
 * src/ab-test/) starts and edits tests. Not to be confused with
 * Experiments (SEOProStats_Experiments): a change and its expected effect.
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

final class SEOProStats_AB_Tests {

    /** Block names. */
    const TEST    = 'seoprostats/ab-test';
    const VARIANT = 'seoprostats/ab-variant';

    /** Test statuses and their codes in ab_tests.status. */
    const STATUSES = array(
        'draft'   => 0,
        'running' => 1,
        'paused'  => 2,
        'ended'   => 3,
    );

    /** Variants a test may have. */
    const MAX_VARIANTS = 10;

    /** A variant's weight: relative to the others; 0 never shows it. */
    const WEIGHT     = 50;
    const MAX_WEIGHT = 100;

    /** Goals a test may be judged by. */
    const MAX_GOALS = 20;

    /** Between a variant's rendered blocks, so the test can tell them apart. */
    const MARK = '<!--spst-ab-variant-->';

    /**
     * Register hooks (every request: the blocks render on the site).
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'register'));
        // Saving a post: renew duplicate test ids, then read its tests.
        add_filter('wp_insert_post_data', array(__CLASS__, 'unique_ids'), 10, 2);
        add_action('save_post', array(__CLASS__, 'saved'), 10, 2);
        add_action('deleted_post', array(__CLASS__, 'deleted'), 10, 1);
    }

    /**
     * Register the two blocks. Their editor (labels, icons, toolbar and
     * sidebar) is the ab-test script, loaded in the block editor only.
     */
    public static function register() {
        if (!function_exists('register_block_type') || WP_Block_Type_Registry::get_instance()->is_registered(self::TEST)) {
            return;
        }
        register_block_type(self::TEST, array(
            'api_version'     => '3',
            'title'           => __('A/B test', 'seoprostats'),
            'description'     => __('Shows one of its variants to each visitor, by weight, to learn which does better.', 'seoprostats'),
            'category'        => 'design',
            'keywords'        => array('ab', 'split', 'variant'),
            'attributes'      => array(
                'testId' => array('type' => 'string', 'default' => ''),
                'name'   => array('type' => 'string', 'default' => ''),
                'status' => array('type' => 'string', 'enum' => array_keys(self::STATUSES), 'default' => 'draft'),
                'goals'  => array('type' => 'array', 'items' => array('type' => 'string'), 'default' => array()),
                'winner' => array('type' => 'string', 'default' => ''),
            ),
            'supports'        => array(
                'html'     => false,
                'reusable' => false,
                'align'    => array('wide', 'full'),
                'layout'   => array(
                    'default'         => array('type' => 'constrained'),
                    'allowSwitching'  => false,
                    'allowEditing'    => false,
                    'allowInheriting' => false,
                ),
            ),
            'render_callback' => array(__CLASS__, 'render_test'),
        ));
        register_block_type(self::VARIANT, array(
            'api_version'     => '3',
            'title'           => __('A/B test variant', 'seoprostats'),
            'description'     => __('One version of an A/B test’s content.', 'seoprostats'),
            'category'        => 'design',
            'parent'          => array(self::TEST),
            'attributes'      => array(
                'slug'   => array('type' => 'string', 'default' => ''),
                'label'  => array('type' => 'string', 'default' => ''),
                'weight' => array('type' => 'integer', 'default' => self::WEIGHT),
            ),
            'supports'        => array(
                'html'     => false,
                'reusable' => false,
                'inserter' => false,
            ),
            'render_callback' => array(__CLASS__, 'render_variant'),
        ));
    }

    /**
     * A variant on the site: its blocks with no wrapper of their own (so
     * they sit in the test's layout as they would in the page's), after a
     * mark the test splits on.
     *
     * @param array<string,mixed> $attributes Block attributes.
     * @param string              $content    Rendered inner blocks.
     * @return string
     */
    public static function render_variant($attributes, $content) {
        unset($attributes);
        return self::MARK . $content;
    }

    /**
     * A test on the site: the control or winner as normal markup and,
     * while running, the other variants in <template>s with the script
     * that picks one.
     *
     * @param array<string,mixed> $attributes Block attributes.
     * @param string              $content    Rendered variants, each after MARK.
     * @param WP_Block|null       $block      The block.
     * @return string
     */
    public static function render_test($attributes, $content, $block = null) {
        $variants = self::variants($block instanceof WP_Block ? self::inner_attributes($block->parsed_block) : array());
        if (!$variants) {
            return '';
        }
        $parts = explode(self::MARK, (string) $content);
        array_shift($parts);
        $test   = self::test_attributes((array) $attributes);
        $shown  = self::shown($variants, $test['winner']);
        $html   = static function ($i) use ($parts) {
            return isset($parts[$i]) ? $parts[$i] : '';
        };
        $swap   = $test['status'] === 'running' && $test['winner'] === '' && $test['id'] !== '' && self::swaps();
        $extra  = array('data-spst-test' => $test['id']);
        $others = '';
        if ($swap) {
            foreach ($variants as $i => $variant) {
                if ($i !== $shown && $variant['weight'] > 0) {
                    $others .= '<template data-spst-variant="' . esc_attr($variant['slug']) . '" data-spst-weight="' . (int) $variant['weight'] . '">' . $html($i) . '</template>';
                }
            }
            $swap = $others !== '';
        }
        if ($swap) {
            $extra['data-spst-control'] = $variants[$shown]['slug'];
            $extra['data-spst-weight']  = (string) $variants[$shown]['weight'];
        }
        $out = '<div ' . get_block_wrapper_attributes($extra) . '>' . $html($shown);
        if ($swap) {
            $out .= $others . '<script>' . self::script(SEOProStats_Statistics::ab_one_variant()) . '</script>';
        }
        return $out . '</div>';
    }

    /**
     * Whether this response may carry variants and the swap script: front-end
     * pages, not feeds, embeds, the REST API or wp-admin (they get the
     * control or winner only).
     *
     * @return bool
     */
    private static function swaps() {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || is_feed() || is_embed()) {
            return false;
        }
        /**
         * Filters whether running A/B tests print their other variants and
         * the script that picks one (otherwise only the control shows).
         *
         * @param bool $swaps Default: on front-end pages.
         */
        return (bool) apply_filters('seoprostats_ab_tests_swap', true);
    }

    /**
     * The script after a running test's variants. Reads the weights from
     * the markup, picks a variant at random by weight, swaps it in for the
     * control and marks the wrapper. With $visit (Settings → Tracking, one
     * variant per visit) it first looks for the variant this tab showed
     * before in sessionStorage (spst-ab-<test>) and keeps the one shown
     * there; otherwise nothing is stored. No brackets or ampersands, so
     * content filters leave it alone.
     *
     * @param bool $visit Keep the visit's variant in sessionStorage.
     * @return string
     */
    public static function script($visit = false) {
        return '(function(s,o){var w=s.parentNode,c=w.childNodes,a=+w.getAttribute("data-spst-weight"),t=a,p=w.getAttribute("data-spst-control"),k="spst-ab-"+w.getAttribute("data-spst-test"),m=null,v=null,i,e,r;'
            . 'if(o)try{v=sessionStorage.getItem(k)}catch(x){}'
            // A control weighted 0 is never kept.
            . 'if(!a)if(v===p)v=null;'
            . 'for(i=0;i<c.length;i++){e=c.item(i);if(e.nodeName==="TEMPLATE"){t+=+e.getAttribute("data-spst-weight");if(e.getAttribute("data-spst-variant")===v)m=e}}'
            . 'if(v!==p)if(!m){r=Math.random()*t-a;'
            . 'for(i=0;i<c.length;i++){if(r<0)break;e=c.item(i);if(e.nodeName==="TEMPLATE"){m=e;r-=+e.getAttribute("data-spst-weight")}}}'
            . 'if(m){p=m.getAttribute("data-spst-variant");for(i=c.length-1;i>=0;i--){e=c.item(i);if(e.nodeName!=="TEMPLATE"){if(e!==s)w.removeChild(e)}}w.insertBefore(m.content.cloneNode(true),w.firstChild)}'
            . 'if(o)try{sessionStorage.setItem(k,p)}catch(x){}'
            . 'w.setAttribute("data-spst-ab",w.getAttribute("data-spst-test")+":"+p)})(document.currentScript,' . ($visit ? '1' : '0') . ');';
    }

    /**
     * The variant that shows without the script: the winner, else the first.
     *
     * @param array<int,array{slug:string,label:string,weight:int}> $variants Variants.
     * @param string                                                $winner   Winner's slug, or ''.
     * @return int Index in $variants.
     */
    private static function shown(array $variants, $winner) {
        if ($winner !== '') {
            foreach ($variants as $i => $variant) {
                if ($variant['slug'] === $winner) {
                    return $i;
                }
            }
        }
        return 0;
    }

    /**
     * The attributes of a parsed block's variant blocks, in order.
     *
     * @param array<string,mixed> $parsed A parsed test block.
     * @return array<int,array<string,mixed>>
     */
    private static function inner_attributes(array $parsed) {
        $out = array();
        foreach (isset($parsed['innerBlocks']) ? (array) $parsed['innerBlocks'] : array() as $inner) {
            if (is_array($inner) && isset($inner['blockName']) && $inner['blockName'] === self::VARIANT) {
                $out[] = isset($inner['attrs']) ? (array) $inner['attrs'] : array();
            }
        }
        return $out;
    }

    /**
     * A test's own attributes, checked.
     *
     * @param array<string,mixed> $attrs Block attributes.
     * @return array{id:string,name:string,status:string,goals:string[],winner:string}
     */
    private static function test_attributes(array $attrs) {
        $status = isset($attrs['status']) && is_string($attrs['status']) && isset(self::STATUSES[$attrs['status']]) ? $attrs['status'] : 'draft';
        $goals  = array();
        foreach (isset($attrs['goals']) && is_array($attrs['goals']) ? $attrs['goals'] : array() as $goal) {
            $goal = is_scalar($goal) ? (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $goal)) : '';
            if ($goal !== '' && !in_array($goal, $goals, true) && count($goals) < self::MAX_GOALS) {
                $goals[] = $goal;
            }
        }
        $id = isset($attrs['testId']) ? $attrs['testId'] : '';
        return array(
            'id'     => self::valid_id($id) ? (string) $id : '',
            'name'   => isset($attrs['name']) && is_string($attrs['name']) ? mb_substr(sanitize_text_field($attrs['name']), 0, 190) : '',
            'status' => $status,
            'goals'  => $goals,
            'winner' => isset($attrs['winner']) && is_string($attrs['winner']) ? self::slug($attrs['winner']) : '',
        );
    }

    /**
     * Variants from their blocks' attributes: unique slugs (variant-a,
     * variant-b… for those without one), labels and weights.
     *
     * @param array<int,array<string,mixed>> $list Variant blocks' attributes, in order.
     * @return array<int,array{slug:string,label:string,weight:int}>
     */
    public static function variants(array $list) {
        $out  = array();
        $used = array();
        foreach (array_slice(array_values($list), 0, self::MAX_VARIANTS) as $i => $attrs) {
            $slug = isset($attrs['slug']) && is_string($attrs['slug']) ? self::slug($attrs['slug']) : '';
            if ($slug === '' || isset($used[$slug])) {
                $slug = self::free_slug($used, $i);
            }
            $used[$slug] = true;
            $label       = isset($attrs['label']) && is_string($attrs['label']) ? mb_substr(sanitize_text_field($attrs['label']), 0, 100) : '';
            $weight      = isset($attrs['weight']) && is_numeric($attrs['weight']) ? (int) $attrs['weight'] : self::WEIGHT;
            $out[]       = array(
                'slug'   => $slug,
                'label'  => $label !== '' ? $label : self::letter_label($slug, $i),
                'weight' => max(0, min(self::MAX_WEIGHT, $weight)),
            );
        }
        return $out;
    }

    /**
     * A variant slug: lower-case letters, digits and hyphens.
     *
     * @param string $text Text.
     * @return string
     */
    private static function slug($text) {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($text)), '-');
        return strlen($slug) > 64 ? rtrim(substr($slug, 0, 64), '-') : $slug;
    }

    /**
     * The first unused variant-a, variant-b… slug, from position $i on.
     *
     * @param array<string,bool> $used Slugs taken.
     * @param int                $i    Position.
     * @return string
     */
    private static function free_slug(array $used, $i) {
        for ($n = $i; ; $n++) {
            $slug = 'variant-' . ($n < 26 ? chr(97 + $n) : (string) ($n + 1));
            if (!isset($used[$slug])) {
                return $slug;
            }
        }
    }

    /**
     * Default label: "Variant B" for variant-b, else by position.
     *
     * @param string $slug Slug.
     * @param int    $i    Position.
     * @return string
     */
    private static function letter_label($slug, $i) {
        $letter = preg_match('/^variant-([a-z])$/', $slug, $m) ? strtoupper($m[1]) : (string) ($i + 1);
        /* translators: %s: a letter or number, e.g. B. */
        return sprintf(__('Variant %s', 'seoprostats'), $letter);
    }

    /**
     * Whether text is a test id: 6 to 32 lower-case letters and digits.
     *
     * @param mixed $id Value.
     * @return bool
     */
    public static function valid_id($id) {
        return is_string($id) && (bool) preg_match('/^[a-z0-9]{6,32}$/', $id);
    }

    /**
     * The tests in parsed blocks, at any depth (not inside other tests or
     * synced patterns), in order.
     *
     * @param array<int|string,mixed> $blocks Parsed blocks.
     * @return array<int,array<string,mixed>> Parsed test blocks.
     */
    private static function find(array $blocks) {
        $out = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (isset($block['blockName']) && $block['blockName'] === self::TEST) {
                $out[] = $block;
            } elseif (!empty($block['innerBlocks'])) {
                $out = array_merge($out, self::find((array) $block['innerBlocks']));
            }
        }
        return $out;
    }

    /**
     * A test block's opening comment, as WordPress's block parser reads it:
     * 1 its attributes' JSON, if any; 2 "/" for a block with no content.
     */
    const OPENER = '/<!--\s+wp:seoprostats\/ab-test\s+(\{(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+\}\s+)?(\/)?-->/s';

    /**
     * Before a post is written: give each test without an id, or with one
     * another test in the post or another post already has, an id of its
     * own. The new id comes from the old one and the post, so saving the
     * same content again gives the same id. Only those tests' opening
     * comments are rewritten; the rest of the content stays as it is.
     *
     * @param array<string,mixed> $data    Slashed post fields to write.
     * @param array<string,mixed> $postarr Slashed post fields given.
     * @return array<string,mixed>
     */
    public static function unique_ids($data, $postarr) {
        if (!is_array($data) || !isset($data['post_content']) || !is_string($data['post_content'])
            || strpos($data['post_content'], '<!-- wp:' . self::TEST . ' ') === false
            || (isset($data['post_type']) && $data['post_type'] === 'revision')) {
            return $data;
        }
        $post_id = is_array($postarr) && isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        $content = wp_unslash($data['post_content']);
        if (!preg_match_all(self::OPENER, $content, $found)) {
            return $data;
        }
        $ids = array();
        foreach ($found[1] as $json) {
            $id = self::opener_id($json);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $taken   = self::taken_elsewhere($ids, $post_id);
        $seen    = array();
        $changed = false;
        $content = preg_replace_callback(self::OPENER, static function ($m) use ($post_id, $taken, &$seen, &$changed) {
            // Unmatched groups at the end are left out of $m.
            $json = isset($m[1]) ? $m[1] : '';
            $id   = self::opener_id($json);
            if ($id !== '' && !isset($seen[$id]) && !isset($taken[$id])) {
                $seen[$id] = true;
                return $m[0];
            }
            $attrs           = $json !== '' ? json_decode($json, true) : array();
            $attrs           = is_array($attrs) ? $attrs : array();
            $id              = self::derived_id($id, $post_id, $seen, $taken);
            $seen[$id]       = true;
            $changed         = true;
            $attrs['testId'] = $id;
            return '<!-- wp:' . self::TEST . ' ' . serialize_block_attributes($attrs) . ' ' . (isset($m[2]) ? '/' : '') . '-->';
        }, $content);
        if ($changed && is_string($content)) {
            $data['post_content'] = wp_slash($content);
        }
        return $data;
    }

    /**
     * The valid test id in an opening comment's attributes, or ''.
     *
     * @param string $json Attributes' JSON ('' for none).
     * @return string
     */
    private static function opener_id($json) {
        $attrs = $json !== '' ? json_decode($json, true) : null;
        return is_array($attrs) && isset($attrs['testId']) && self::valid_id($attrs['testId']) ? $attrs['testId'] : '';
    }

    /**
     * Ids of $ids that belong to tests in other posts.
     *
     * @param string[] $ids     Test ids.
     * @param int      $post_id This post (0: new).
     * @return array<string,bool>
     */
    private static function taken_elsewhere(array $ids, $post_id) {
        global $wpdb;
        if (!$ids || !self::ready()) {
            return array();
        }
        $before = SEOProStats_Schema::use_set('live');
        $table  = SEOProStats_Schema::table('ab_tests');
        SEOProStats_Schema::use_set($before);
        $ids    = array_values(array_unique($ids));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- our own table by its primary key, on saving a post; placeholders built for each id.
        $rows   = $wpdb->get_col($wpdb->prepare('SELECT test_id FROM %i WHERE test_id IN (' . implode(',', array_fill(0, count($ids), '%s')) . ') AND post_id <> %d', array_merge(array($table), $ids, array((int) $post_id))));
        return array_fill_keys(array_map('strval', (array) $rows), true);
    }

    /**
     * A new id from an old one and its post: the same each time this
     * content is saved, and free in the post and elsewhere.
     *
     * @param string             $old     Old id ('' for none).
     * @param int                $post_id Post.
     * @param array<string,bool> $seen    Ids taken in this post.
     * @param array<string,bool> $taken   Ids taken elsewhere.
     * @return string
     */
    private static function derived_id($old, $post_id, array $seen, array $taken) {
        for ($n = 0; ; $n++) {
            $id = substr(md5($old . ':' . (int) $post_id . ':' . $n . ':' . count($seen)), 0, 12); // NOSONAR nosemgrep: a short stable ID, not security.
            if (!isset($seen[$id]) && !isset($taken[$id])) {
                return $id;
            }
        }
    }

    /**
     * Whether the live ab_tests table is there (made on admin requests).
     *
     * @return bool
     */
    private static function ready() {
        $before = SEOProStats_Schema::use_set('live');
        $ready  = SEOProStats_Schema::is_current();
        SEOProStats_Schema::use_set($before);
        return $ready;
    }

    /**
     * After a post is saved: write its tests to ab_tests, and mark those no
     * longer in it as removed.
     *
     * @param int          $post_id Post.
     * @param WP_Post|null $post    Post.
     */
    public static function saved($post_id, $post = null) {
        // A new post's auto-draft (Add New) never holds a test.
        if (!$post instanceof WP_Post || $post->post_status === 'auto-draft' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !self::ready()) {
            return;
        }
        $tests = has_block(self::TEST, $post) ? self::find(parse_blocks($post->post_content)) : array();
        self::write((int) $post_id, $tests, get_the_title($post));
    }

    /**
     * A deleted post's tests are marked removed.
     *
     * @param int $post_id Post.
     */
    public static function deleted($post_id) {
        if (self::ready()) {
            self::write((int) $post_id, array(), '');
        }
    }

    /**
     * Write a post's tests and mark its others removed.
     *
     * @param int                            $post_id Post.
     * @param array<int,array<string,mixed>> $tests   Parsed test blocks in it now.
     * @param string                         $title   Post title (a test's name by default).
     */
    private static function write($post_id, array $tests, $title) {
        global $wpdb;
        $before = SEOProStats_Schema::use_set('live');
        $table  = SEOProStats_Schema::table('ab_tests');
        SEOProStats_Schema::use_set($before);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its post_id key, on saving a post; not cached on purpose.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT test_id, status, winner, started, ended, removed FROM %i WHERE post_id = %d', $table, $post_id), ARRAY_A);
        $old  = array();
        foreach ((array) $rows as $row) {
            $old[(string) $row['test_id']] = $row;
        }
        if (!$tests && !$old) {
            return;
        }

        $now  = time();
        $kept = array();
        foreach ($tests as $test) {
            $attrs = self::test_attributes(isset($test['attrs']) ? (array) $test['attrs'] : array());
            if ($attrs['id'] === '' || isset($kept[$attrs['id']])) {
                continue;
            }
            $kept[$attrs['id']] = true;
            $status   = self::STATUSES[$attrs['status']];
            $prev     = isset($old[$attrs['id']]) ? $old[$attrs['id']] : null;
            $started  = $prev ? (int) $prev['started'] : 0;
            $ended    = $prev ? (int) $prev['ended'] : 0;
            if ($status === self::STATUSES['running'] || $status === self::STATUSES['ended']) {
                $started = $started ?: $now;
            }
            if ($status !== self::STATUSES['ended']) {
                $ended = 0;
            } elseif (!$ended) {
                $ended = $now;
            }
            $variants = self::variants(self::inner_attributes($test));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing our own table by its primary key, on saving a post.
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (test_id, post_id, name, variants, goals, status, winner, created, updated, started, ended, removed) VALUES (%s, %d, %s, %s, %s, %d, %s, %d, %d, %d, %d, 0)'
                . ' ON DUPLICATE KEY UPDATE post_id = VALUES(post_id), name = VALUES(name), variants = VALUES(variants), goals = VALUES(goals), status = VALUES(status), winner = VALUES(winner), updated = VALUES(updated), started = VALUES(started), ended = VALUES(ended), removed = 0',
                $table,
                $attrs['id'],
                $post_id,
                $attrs['name'] !== '' ? $attrs['name'] : mb_substr(sanitize_text_field((string) $title), 0, 190),
                (string) wp_json_encode($variants),
                (string) wp_json_encode($attrs['goals']),
                $status,
                $attrs['winner'],
                $now,
                $now,
                $started,
                $ended
            ));
            self::markers($post_id, $attrs, $variants, $prev, $title);
        }
        foreach ($old as $id => $row) {
            // A test that ended with its winner left the post on purpose (pick_winner()).
            $won = (int) $row['status'] === self::STATUSES['ended'] && (string) $row['winner'] !== '';
            if (!isset($kept[$id]) && (int) $row['removed'] === 0 && !$won) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key, on saving a post.
                $wpdb->update($table, array('removed' => $now, 'updated' => $now), array('test_id' => (string) $id), array('%d', '%d'), array('%s'));
            }
        }
    }

    /**
     * Record the winner picked in the editor: picking one replaces the test
     * block with that variant's blocks, so once the post is saved the test
     * is no longer in it, and the editor sends the winner here. The test
     * ends with that winner (and the timeline's markers); its results stay.
     * Only for a test that ran, by someone who may edit its post, while the
     * test is not in the post (else its block's attributes count). Live
     * data only. Saving the same winner again changes nothing.
     *
     * @param string $id   Test id.
     * @param string $slug The winning variant's slug.
     * @return array{id:string,status:string,winner:string}|WP_Error
     */
    public static function pick_winner($id, $slug) {
        global $wpdb;
        if (!self::valid_id($id) || !self::ready()) {
            return new WP_Error('seoprostats_not_found', __('There is no such A/B test.', 'seoprostats'), array('status' => 404));
        }
        $before = SEOProStats_Schema::use_set('live');
        $table  = SEOProStats_Schema::table('ab_tests');
        SEOProStats_Schema::use_set($before);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key, on an editor's request; not cached on purpose.
        $row = $wpdb->get_row($wpdb->prepare('SELECT test_id, post_id, name, variants, goals, status, winner, started, ended, removed FROM %i WHERE test_id = %s', $table, $id), ARRAY_A);
        if (!is_array($row)) {
            return new WP_Error('seoprostats_not_found', __('There is no such A/B test.', 'seoprostats'), array('status' => 404));
        }
        $post_id = (int) $row['post_id'];
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('rest_forbidden', __('You may not edit the post this A/B test is in.', 'seoprostats'), array('status' => rest_authorization_required_code()));
        }
        $list     = json_decode((string) $row['variants'], true);
        $variants = self::variants(is_array($list) ? $list : array());
        if (!in_array($slug, array_column($variants, 'slug'), true)) {
            return new WP_Error('seoprostats_invalid', __('The test has no such variant.', 'seoprostats'), array('status' => 400));
        }
        if (!(int) $row['started']) {
            return new WP_Error('seoprostats_never_ran', __('This A/B test never ran, so it has no winner to record.', 'seoprostats'), array('status' => 409));
        }
        $post = $post_id ? get_post($post_id) : null;
        if ($post instanceof WP_Post && has_block(self::TEST, $post)) {
            foreach (self::find(parse_blocks($post->post_content)) as $test) {
                if (self::test_attributes(isset($test['attrs']) ? (array) $test['attrs'] : array())['id'] === $id) {
                    return new WP_Error('seoprostats_still_in_post', __('The A/B test is still in its post: save the post after picking the winner.', 'seoprostats'), array('status' => 409));
                }
            }
        }
        $now = time();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing our own table by its primary key, on an editor's request.
        $wpdb->update(
            $table,
            array(
                'status'  => self::STATUSES['ended'],
                'winner'  => $slug,
                'ended'   => (int) $row['ended'] ? (int) $row['ended'] : $now,
                'removed' => 0,
                'updated' => $now,
            ),
            array('test_id' => $id),
            array('%d', '%s', '%d', '%d', '%d'),
            array('%s')
        );
        $goals = json_decode((string) $row['goals'], true);
        self::markers($post_id, array(
            'id'     => $id,
            'name'   => (string) $row['name'],
            'status' => 'ended',
            'goals'  => is_array($goals) ? array_map('strval', $goals) : array(),
            'winner' => $slug,
        ), $variants, $row, $post instanceof WP_Post ? get_the_title($post) : '');
        return array('id' => $id, 'status' => 'ended', 'winner' => $slug);
    }

    /**
     * Timeline markers for a test's status and winner as saved: started
     * (or resumed), paused, ended and winner set (SEOProStats_Changes).
     * Only changes: saving a running test again marks nothing.
     *
     * @param int                                                 $post_id  Post.
     * @param array{id:string,name:string,status:string,goals:string[],winner:string} $attrs    The test's attributes now.
     * @param array<int,array{slug:string,label:string,weight:int}> $variants Its variants now.
     * @param array<string,mixed>|null                            $prev     Its row before (null: new).
     * @param string                                              $title    Post title (the name by default).
     */
    private static function markers($post_id, array $attrs, array $variants, $prev, $title) {
        $codes  = array_flip(self::STATUSES);
        $before = $prev && isset($codes[(int) $prev['status']]) ? $codes[(int) $prev['status']] : 'draft';
        $now    = $attrs['status'];
        $name   = $attrs['name'] !== '' ? $attrs['name'] : sanitize_text_field((string) $title);
        $kinds  = array(
            'running' => SEOProStats_Changes::AB_STARTED,
            'paused'  => SEOProStats_Changes::AB_PAUSED,
            'ended'   => SEOProStats_Changes::AB_ENDED,
        );
        $link   = get_permalink($post_id);
        $about  = array(
            'path'        => is_string($link) && get_post_status($post_id) === 'publish' ? SEOProStats_Changes::path($link) : '',
            'object_type' => 'ab_test',
            'object_id'   => $post_id,
        );
        // A test ended straight from a draft never ran: no marker.
        if ($now !== $before && isset($kinds[$now]) && !($now === 'ended' && $before === 'draft')) {
            SEOProStats_Changes::record($kinds[$now], $about + array(
                'old'  => $before,
                'new'  => $attrs['id'],
                'meta' => array('name' => $name, 'test' => $attrs['id']),
            ));
        }
        $winner = $attrs['winner'];
        if ($winner !== '' && (!$prev || (string) $prev['winner'] !== $winner)) {
            $label = $winner;
            foreach ($variants as $variant) {
                if ($variant['slug'] === $winner) {
                    $label = $variant['label'];
                }
            }
            SEOProStats_Changes::record(SEOProStats_Changes::AB_WINNER, $about + array(
                'old'  => $prev ? (string) $prev['winner'] : '',
                'new'  => $winner,
                'meta' => array('name' => $name, 'test' => $attrs['id'], 'label' => $label),
            ));
        }
    }
}
