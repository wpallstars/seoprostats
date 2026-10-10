<?php
/**
 * Search targets from the site's own data, besides a pasted list: the
 * focus keywords an SEO plugin (Rank Math, Yoast SEO, SEOPress or All in
 * One SEO) keeps for each published post, each with that post's address
 * as the page meant for it.
 *
 * suggestions() lists them against the targets already kept, one row per
 * search, in a state:
 *
 * - new: the focus keyword of one page and not a target yet;
 * - targeted: already a target (its page and status are given), so it is
 *   left as it is;
 * - clash: the focus keyword of more than one page. Which page is meant
 *   is the owner's choice, so it is listed with its pages and never
 *   imported.
 *
 * import() adds the new ones (all, or the searches given) as targeted,
 * priority 50, source seo-plugin, through SEOProStats_Targets::import()
 * with only_new, so existing targets never change. The main keyword of
 * each page is read; all_keywords adds its other focus keywords.
 *
 * Reads, on an admin request only: postmeta by its meta_key index, one
 * key of the active SEO plugin at a time (every plugin's keys when none
 * is active), published posts only, at most MAX_POSTS posts (All in One
 * SEO: its own table in post_id order, as far); then
 * SEOProStats_Coverage::seo_fields() for those posts, in chunks. The demo
 * data set reads the demo pages' focus keywords. Nothing is stored.
 * Design: docs/seo-loop.md → Search targets.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Target_Sources {

    /** Posts with focus keywords read at most. */
    const MAX_POSTS = 1000;

    /** Posts per seo_fields() read. */
    const CHUNK = 200;

    /** Each SEO plugin's focus keyword meta keys (All in One SEO keeps its own table). */
    const PLUGIN_KEYS = array(
        'rank-math' => array('rank_math_focus_keyword'),
        'yoast'     => array('_yoast_wpseo_focuskw', '_yoast_wpseo_focuskeywords'),
        'seopress'  => array('_seopress_analysis_target_kw'),
        'aioseo'    => array(),
    );

    /** Suggestion states, in the order listed. */
    const STATES = array('new', 'clash', 'targeted');

    /** The status and source of an imported focus keyword. */
    const STATUS = 'targeted';
    const SOURCE = 'seo-plugin';

    /**
     * Focus keywords as target suggestions.
     *
     * @param bool $all_keywords Every focus keyword of a page, not only its main one.
     * @return array<string,mixed> plugin, all_keywords, pages, more, max_posts, counts (by state), rows.
     */
    public static function suggestions($all_keywords = false) {
        self::load();
        $demo = SEOProStats_Schema::set() === 'demo';
        list($pages, $more) = $demo ? self::demo_pages() : self::live_pages();
        $rows   = self::rows($pages, (bool) $all_keywords);
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $row) {
            ++$counts[$row['state']];
        }
        return array(
            'plugin'       => $demo ? 'demo' : SEOProStats_Coverage::seo_plugin(),
            'all_keywords' => (bool) $all_keywords,
            'pages'        => count($pages),
            'more'         => $more,
            'max_posts'    => self::MAX_POSTS,
            'counts'       => $counts,
            'rows'         => $rows,
        );
    }

    /**
     * Import the new suggestions: all of them, or the searches given.
     * Searches given that are targets already, clash, or are no page's
     * focus keyword are skipped with the reason.
     *
     * @param string[] $queries      Searches to import; empty for every new one.
     * @param bool     $all_keywords Every focus keyword of a page, not only its main one.
     * @return array<string,mixed>|WP_Error As SEOProStats_Targets::import(), with format seo-plugin.
     */
    public static function import(array $queries = array(), $all_keywords = false) {
        $answer = self::suggestions($all_keywords);
        $wanted = array();
        foreach ($queries as $query) {
            $text = SEOProStats_Targets::query_text((string) $query);
            if ($text !== '') {
                $wanted[$text] = true;
            }
        }
        $rows    = array();
        $numbers = array();
        $skipped = array();
        $n       = 0;
        foreach ($answer['rows'] as $row) {
            $query = (string) $row['query'];
            if ($wanted && !isset($wanted[$query])) {
                continue;
            }
            unset($wanted[$query]);
            ++$n;
            if ($row['state'] !== 'new') {
                $skipped[] = SEOProStats_Targets::skip($n, $query, $row['state'] === 'clash' ? 'clash' : 'exists');
                continue;
            }
            $rows[]    = array('query' => $query, 'page' => (string) $row['pages'][0]['path'], 'status' => self::STATUS);
            $numbers[] = $n;
        }
        foreach (array_keys($wanted) as $query) {
            $skipped[] = SEOProStats_Targets::skip(++$n, (string) $query, 'unknown');
        }
        $done = $rows ? SEOProStats_Targets::import($rows, self::SOURCE, false, true) : self::nothing();
        if (is_wp_error($done)) {
            return $done;
        }
        // Rows the import skipped are numbered in its own list: number them as the suggestions were.
        foreach ($done['skipped'] as $skip) {
            $skip['row'] = isset($numbers[$skip['row'] - 1]) ? $numbers[$skip['row'] - 1] : $skip['row'];
            $skipped[]   = $skip;
        }
        usort($skipped, static function ($a, $b) {
            return $a['row'] <=> $b['row'];
        });
        return array('format' => self::SOURCE, 'skipped' => $skipped) + $done;
    }

    /**
     * An import's answer when there is nothing to import.
     *
     * @return array<string,mixed>
     */
    private static function nothing() {
        return array(
            'added'   => 0,
            'updated' => 0,
            'removed' => 0,
            'skipped' => array(),
            'total'   => count(SEOProStats_Targets::listed()),
        );
    }

    /**
     * One row per search: its pages and state, new first, then clashes,
     * then those already targeted, each by search.
     *
     * @param array<int,array<string,mixed>> $pages        Pages with their focus keywords.
     * @param bool                           $all_keywords Every focus keyword, not only the main one.
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $pages, $all_keywords) {
        $found = array();
        foreach ($pages as $page) {
            $keywords = $all_keywords ? $page['focus'] : array_slice($page['focus'], 0, 1);
            unset($page['focus']);
            foreach ($keywords as $keyword) {
                $query = SEOProStats_Targets::query_text($keyword);
                if ($query === '') {
                    continue;
                }
                if (!isset($found[$query])) {
                    $found[$query] = array('keyword' => (string) $keyword, 'pages' => array());
                }
                $found[$query]['pages'][$page['path']] = $page;
            }
        }
        $listed = SEOProStats_Targets::listed();
        $rows   = array();
        foreach ($found as $query => $item) {
            $query  = (string) $query;
            $target = isset($listed[$query]) ? $listed[$query] : null;
            $state  = 'new';
            if ($target) {
                $state = 'targeted';
            } elseif (count($item['pages']) > 1) {
                $state = 'clash';
            }
            $rows[] = array(
                'query'   => $query,
                'keyword' => $item['keyword'],
                'state'   => $state,
                'pages'   => array_values($item['pages']),
                'target'  => $target ? array_diff_key($target, array('query' => 1)) : null,
            );
        }
        $order = array_flip(self::STATES);
        usort($rows, static function ($a, $b) use ($order) {
            return array($order[$a['state']], $a['query']) <=> array($order[$b['state']], $b['query']);
        });
        return $rows;
    }

    /**
     * Published posts with focus keywords, as pages.
     *
     * @return array{0:array<int,array<string,mixed>>,1:bool} Pages, and whether there were more posts than read.
     */
    private static function live_pages() {
        $ids    = self::post_ids();
        $more   = count($ids) > self::MAX_POSTS;
        $ids    = array_slice($ids, 0, self::MAX_POSTS);
        $active = SEOProStats_Coverage::seo_plugin();
        $pages  = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            _prime_post_caches($chunk, false, false);
            foreach (SEOProStats_Coverage::seo_fields($chunk) as $post_id => $fields) {
                $page = self::post_page((int) $post_id, self::keywords((array) $fields['focus'], $active));
                if ($page) {
                    $pages[] = $page;
                }
            }
        }
        return array($pages, $more);
    }

    /**
     * A post's focus keywords, main first: the active SEO plugin's (and
     * any a filter added), so keywords another plugin left behind are not
     * read while one is active.
     *
     * @param array<int,array{keyword:string,source:string}> $focus  From seo_fields().
     * @param string                                         $active The active SEO plugin, '' for none.
     * @return string[]
     */
    private static function keywords(array $focus, $active) {
        $out = array();
        foreach ($focus as $item) {
            $source = isset($item['source']) ? (string) $item['source'] : '';
            if ($active === '' || $source === $active || !isset(self::PLUGIN_KEYS[$source])) {
                $out[] = (string) $item['keyword'];
            }
        }
        return $out;
    }

    /**
     * A published, viewable post on this site as a page with its focus
     * keywords; null otherwise.
     *
     * @param int      $post_id  Post.
     * @param string[] $keywords Its focus keywords.
     * @return array<string,mixed>|null
     */
    private static function post_page($post_id, array $keywords) {
        $post = $keywords ? get_post($post_id) : null;
        if (!$post || $post->post_status !== 'publish' || !is_post_type_viewable($post->post_type)) {
            return null;
        }
        $url  = (string) get_permalink($post);
        $path = SEOProStats_Targets::page_path($url);
        if ($path === null || $path === '') {
            return null;
        }
        $edit = current_user_can('edit_post', $post_id) ? get_edit_post_link($post_id, 'raw') : null;
        return array(
            'path'     => $path,
            'post_id'  => (int) $post_id,
            'title'    => wp_strip_all_tags((string) get_the_title($post)),
            'url'      => $url,
            'edit_url' => $edit ? (string) $edit : null,
            'focus'    => $keywords,
        );
    }

    /**
     * Published posts with focus keywords: the active SEO plugin's meta
     * keys (every plugin's when none is active), by the meta_key index,
     * at most MAX_POSTS + 1 per key; All in One SEO's own table as far.
     *
     * @return int[] Post ids, lowest first.
     */
    private static function post_ids() {
        global $wpdb;
        $active = SEOProStats_Coverage::seo_plugin();
        $keys   = $active !== '' ? self::PLUGIN_KEYS[$active] : array_keys(SEOProStats_Coverage::FOCUS_META);
        $ids    = array();
        foreach ($keys as $key) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- postmeta by its meta_key index, one key, bounded; an admin request only.
            $found = (array) $wpdb->get_col($wpdb->prepare("SELECT pm.post_id FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_status = 'publish' LIMIT %d", $wpdb->postmeta, $wpdb->posts, $key, self::MAX_POSTS + 1));
            $ids   = array_merge($ids, array_map('intval', $found));
        }
        if (($active === 'aioseo' || $active === '') && function_exists('aioseo')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- All in One SEO's table in its post_id order, bounded; an admin request only.
            $found = (array) $wpdb->get_col($wpdb->prepare("SELECT a.post_id FROM %i a INNER JOIN %i p ON p.ID = a.post_id WHERE a.keyphrases IS NOT NULL AND a.keyphrases <> '' AND p.post_status = 'publish' ORDER BY a.post_id LIMIT %d", $wpdb->prefix . 'aioseo_posts', $wpdb->posts, self::MAX_POSTS + 1));
            $ids   = array_merge($ids, array_map('intval', $found));
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    /**
     * The demo pages with focus keywords.
     *
     * @return array{0:array<int,array<string,mixed>>,1:bool} Pages, and false (all are read).
     */
    private static function demo_pages() {
        $pages = array();
        foreach (array_keys(SEOProStats_Demo::PAGE_TEXT) as $path) {
            $text  = SEOProStats_Demo::text((string) $path);
            $focus = $text ? array_values(array_filter(array_map('strval', array_column((array) $text['focus'], 'keyword')))) : array();
            if ($focus) {
                $pages[] = array(
                    'path'     => (string) $path,
                    'post_id'  => 0,
                    'title'    => (string) $text['title'],
                    'url'      => home_url((string) $path),
                    'edit_url' => null,
                    'focus'    => $focus,
                );
            }
        }
        return array($pages, false);
    }

    /**
     * Load the classes the suggestions use.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-targets.php';
        require_once __DIR__ . '/class-seoprostats-coverage.php';
        require_once __DIR__ . '/class-seoprostats-demo.php';
    }
}
