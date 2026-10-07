<?php
/**
 * Query coverage: the search queries a page shows for, each checked
 * against the page's own words, so people see which queries (and
 * questions) the page never answers.
 *
 * A query's terms are its words without common short words, lightly
 * stemmed (plural s). It is covered by the title when every term is in
 * the post title or SEO title, by a heading when every term is in the
 * headings, by the text when every term is anywhere on the page (text,
 * excerpt, image alt text, SEO description); partly when some are; else
 * not at all. Questions are queries that start with a question word or
 * hold a question mark. packages/core/src/coverage.ts matches the same
 * way, so the editor re-checks as people write.
 *
 * Focus keywords come from Rank Math, Yoast SEO, SEOPress or All in One
 * SEO when one is active (filter seoprostats_focus_keywords for others);
 * without them the report works the same.
 *
 * Only an admin request reads post content, for one page or the bounded
 * pages of Opportunities → Missing from the page. Search data is read by
 * path_day as SEOProStats_Opportunities does.
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

final class SEOProStats_Coverage {

    /** Queries of a page listed at most, most impressions first. */
    const QUERIES = 200;

    /** Characters of a page's text read at most. */
    const MAX_TEXT = 200000;

    /** Short words a query's terms leave out (English). */
    const STOPWORDS = array('a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'did', 'do', 'does', 'for', 'from', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'or', 'our', 'so', 'than', 'that', 'the', 'their', 'then', 'there', 'these', 'this', 'to', 'vs', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'you', 'your');

    /** Words that start a question (English). */
    const QUESTION_WORDS = array('how', 'what', 'why', 'when', 'where', 'who', 'whom', 'whose', 'which', 'can', 'could', 'should', 'would', 'will', 'does', 'do', 'did', 'is', 'are', 'was', 'were', 'am', 'may', 'might', 'shall');

    /** SEO plugins' focus keyword meta: key => [plugin, format]. */
    const FOCUS_META = array(
        'rank_math_focus_keyword'      => array('rank-math', 'list'),
        '_yoast_wpseo_focuskw'         => array('yoast', 'text'),
        '_yoast_wpseo_focuskeywords'   => array('yoast', 'yoast_json'),
        '_seopress_analysis_target_kw' => array('seopress', 'list'),
    );

    /** Match levels, best first. */
    const MATCHES = array('title', 'heading', 'text', 'partial', 'none');

    /** A post's SEO fields when no SEO plugin set them (seo_fields()). */
    const NO_SEO = array(
        'plugin'         => '',
        'seo_title'      => '',
        'seo_title_vars' => false,
        'description'    => '',
        'focus'          => array(),
        'noindex'        => false,
        'canonical'      => '',
    );

    /** Meta key prefix of each SEO plugin seo_plugin() names (All in One SEO keeps its own table). */
    const META_PREFIX = array(
        'rank-math' => 'rank_math_',
        'yoast'     => '_yoast_wpseo_',
        'seopress'  => '_seopress_',
        'aioseo'    => '_aioseo_',
    );

    /** SEO plugins' variables (%%title%%, %sitename%, #post_title), which stand for text read elsewhere. */
    const VARIABLES = '/%%?[a-z0-9_]+%%?|#[a-z_]+/i';

    /**
     * The coverage report of one page: its queries in the period, each
     * with how far the page's words cover it.
     *
     * @param array<string,mixed> $req     From SEOProStats_Query::request().
     * @param string              $page    Page path; '' when $post_id is given.
     * @param int                 $post_id Post (the page is its address); 0 when $page is given.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $page = '', $post_id = 0) {
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-opportunities.php';
        $live    = SEOProStats_Schema::set() === 'live';
        $page    = trim((string) $page);
        $post_id = (int) $post_id;
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('seoprostats_no_post', __('There is no such post.', 'seoprostats'), array('status' => 404));
            }
            $page = SEOProStats_Changes::path((string) get_permalink($post));
        }
        if ($page === '' || $page[0] !== '/' || strpos($page, '*') !== false) {
            return new WP_Error('seoprostats_page', __('Give a page as a path such as /pricing/, or a post ID.', 'seoprostats'), array('status' => 400));
        }

        $ids     = SEOProStats_Dict::find(SEOProStats_Schema::DICT_PATH, array($page));
        $path_id = $ids ? (int) $ids[0] : 0;
        if (!$post_id && $live) {
            $post_id = self::post_ids(array($path_id => $page))[$path_id] ?? 0;
        }
        $modified = $post_id && $live ? (string) get_post_field('post_modified_gmt', $post_id) : '';

        $answer = SEOProStats_Query::cached('coverage', $req + array('page' => $page, 'post' => $post_id, 'modified' => $modified, 'imports' => SEOProStats_Search::version()), static function () use ($req, $page, $path_id, $post_id) {
            return self::build($req, $page, $path_id, $post_id);
        });

        $answer['connected'] = !$live || SEOProStats_Search::connected();
        $info                = SEOProStats_Clicks::page_info($page);
        if ($info) {
            $info['post_id'] = $post_id ?: (int) $info['post_id'];
        }
        $answer['page_info'] = $info ? SEOProStats_Clicks::with_edit_url($info) : null;
        return $answer;
    }

    /**
     * The shared part of the answer (cached).
     *
     * @param array<string,mixed> $req     From SEOProStats_Query::request().
     * @param string              $page    Page path.
     * @param int                 $path_id Its dictionary id; 0 when never seen.
     * @param int                 $post_id Its post; 0 for none.
     * @return array<string,mixed>
     */
    private static function build(array $req, $page, $path_id, $post_id) {
        $engine = SEOProStats_Schema::ENGINE_GOOGLE;
        $bounds = SEOProStats_Search::bounds($engine);
        $range  = SEOProStats_Query::range($req);
        $full   = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds) : null;
        $now    = $full ? SEOProStats_Opportunities::cut($full) : null;
        $days   = $now ? SEOProStats_Search::length($now) : 0;
        $texts  = $path_id ? self::texts(array($path_id => $page), $post_id ? array($path_id => $post_id) : array()) : array();
        $text   = isset($texts[$path_id]) ? $texts[$path_id] : self::text_of_post($post_id);
        $index  = $text ? self::index($text) : null;

        $answer = array(
            'page'    => $page,
            'post_id' => $post_id,
            'range'   => SEOProStats_Query::range_out($now ? $now : $range),
            'days'    => $days,
            'cut'     => $full && $now && SEOProStats_Search::length($full) > $days,
            'through' => $bounds['to'],
            'first'   => $bounds['from'],
            'text'    => $text ? array(
                'source'      => $text['source'],
                'words'       => $index ? $index['words'] : 0,
                'plugin'      => $text['plugin'],
                'seo_title'   => $text['seo_title'],
                'description' => $text['description'],
            ) : null,
            'focus'   => array(),
            'totals'  => array('queries' => 0, 'impressions' => 0, 'clicks' => 0, 'covered' => 0.0, 'missing' => 0, 'questions' => 0),
            'rows'    => array(),
            'more'    => false,
        );

        $rows = $now && $path_id ? self::queries($engine, $now, $path_id) : array();
        $answer['more'] = count($rows) > self::QUERIES;
        $rows           = array_slice($rows, 0, self::QUERIES);
        $words          = SEOProStats_Query::texts(array_column($rows, 'q'));
        $by_query       = array();
        $covered        = 0;
        foreach ($rows as $row) {
            $query = isset($words[$row['q']]) ? $words[$row['q']] : '';
            if ($query === '') {
                continue;
            }
            $item = array('query' => $query) + SEOProStats_Search::metrics($row['c'], $row['i'], $row['p']) + self::match($query, $index);
            $answer['totals']['queries']++;
            $answer['totals']['impressions'] += $item['impressions'];
            $answer['totals']['clicks']      += $item['clicks'];
            $answer['totals']['missing']     += in_array($item['match'], array('partial', 'none'), true) ? 1 : 0;
            $answer['totals']['questions']   += $item['question'] ? 1 : 0;
            $covered                         += in_array($item['match'], array('partial', 'none'), true) ? 0 : $item['impressions'];
            $answer['rows'][]                 = $item;
            $by_query[self::key($query)]      = $item;
        }
        $answer['totals']['covered'] = $answer['totals']['impressions'] ? round($covered / $answer['totals']['impressions'], 4) : 0.0;

        foreach ($text ? $text['focus'] : array() as $focus) {
            $found             = isset($by_query[self::key($focus['keyword'])]) ? $by_query[self::key($focus['keyword'])] : null;
            $answer['focus'][] = array(
                'keyword'     => $focus['keyword'],
                'source'      => $focus['source'],
                'searched'    => $found !== null,
                'clicks'      => $found ? $found['clicks'] : 0,
                'impressions' => $found ? $found['impressions'] : 0,
                'position'    => $found ? $found['position'] : null,
            ) + self::match($focus['keyword'], $index);
        }
        return $answer;
    }

    /**
     * A page's queries in a period, most impressions first: q (query id),
     * c, i and p sums. One more than QUERIES, to know there are more.
     *
     * @param int                 $engine  Engine.
     * @param array<string,mixed> $days    From SEOProStats_Search::days().
     * @param int                 $path_id Path id.
     * @return array<int,array{q:int,c:int,i:int,p:int}>
     */
    private static function queries($engine, array $days, $path_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its path_day key (path_id, day, query_id).
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`path_day`) WHERE path_id = %d AND day >= %s AND day <= %s AND engine = %d GROUP BY query_id ORDER BY i DESC, query_id LIMIT %d', SEOProStats_Schema::table('gsc_pairs'), (int) $path_id, (string) $days['day_from'], (string) $days['day_to'], (int) $engine, self::QUERIES + 1), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[] = array('q' => (int) $row['q'], 'c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
        }
        return $out;
    }

    /**
     * Pages' text: from their posts, or the demo's own. Pages that are
     * not one post (lists, archives) have none.
     *
     * @param array<int,string> $paths Paths by path id.
     * @param array<int,int>    $known Posts already known, by path id.
     * @return array<int,array<string,mixed>> Text (see text_of_post()) by path id.
     */
    public static function texts(array $paths, array $known = array()) {
        if (SEOProStats_Schema::set() === 'demo') {
            $out = array();
            foreach ($paths as $path_id => $path) {
                $text = SEOProStats_Demo::text($path);
                if ($text) {
                    $out[$path_id] = $text;
                }
            }
            return $out;
        }
        $posts = $known + self::post_ids(array_diff_key($paths, $known));
        $posts = array_filter($posts);
        if (!$posts) {
            return array();
        }
        _prime_post_caches(array_values($posts), false, true);
        $seo = self::seo_fields(array_values($posts));
        $out = array();
        foreach ($posts as $path_id => $post_id) {
            $text = self::text_of_post($post_id, isset($seo[$post_id]) ? $seo[$post_id] : null);
            if ($text) {
                $out[$path_id] = $text;
            }
        }
        return $out;
    }

    /**
     * Posts that pages show: from what the processor recorded (the pages
     * table), else WordPress's own lookup.
     *
     * @param array<int,string> $paths Paths by path id.
     * @return array<int,int> Post ids by path id (0: none).
     */
    private static function post_ids(array $paths) {
        global $wpdb;
        $paths = array_filter($paths);
        if (!$paths) {
            return array();
        }
        $ids     = array_map('intval', array_keys($paths));
        $holders = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; $holders holds only fixed placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id, post_id FROM %i WHERE path_id IN ($holders) AND post_id > 0", array_merge(array(SEOProStats_Schema::table('pages')), $ids)), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int) $row['path_id']] = (int) $row['post_id'];
        }
        foreach ($paths as $path_id => $path) {
            if (!isset($out[$path_id])) {
                $info          = SEOProStats_Clicks::page_info((string) $path);
                $out[$path_id] = $info ? (int) $info['post_id'] : 0;
            }
        }
        return $out;
    }

    /**
     * A post's text: title, SEO title and description, headings, the
     * rest of the text, and focus keywords.
     *
     * @param int                       $post_id Post.
     * @param array<string,mixed>|null  $seo     From seo_fields(); null to read them.
     * @return array<string,mixed>|null Null without a post.
     */
    public static function text_of_post($post_id, $seo = null) {
        $post = $post_id ? get_post((int) $post_id) : null;
        if (!$post) {
            return null;
        }
        if ($seo === null) {
            $all = self::seo_fields(array((int) $post->ID));
            $seo = isset($all[$post->ID]) ? $all[$post->ID] : null;
        }
        $seo  = is_array($seo) ? $seo + self::NO_SEO : self::NO_SEO;
        $text = array(
            'source'         => 'post',
            'title'          => (string) $post->post_title,
            'content'        => (string) $post->post_content,
            'excerpt'        => (string) $post->post_excerpt,
            'plugin'         => (string) $seo['plugin'],
            'seo_title'      => (string) $seo['seo_title'],
            'seo_title_vars' => (bool) $seo['seo_title_vars'],
            'description'    => (string) $seo['description'],
            'focus'          => (array) $seo['focus'],
            'noindex'        => (bool) $seo['noindex'],
            'canonical'      => (string) $seo['canonical'],
        );
        /**
         * Filters the text a page's search queries are checked against
         * (and the content audit reads), such as a page builder's text
         * kept outside post_content.
         *
         * @param array<string,mixed> $text    title, content (HTML), excerpt, seo_title, seo_title_vars (it was made of variables), description, focus, plugin, noindex, canonical.
         * @param WP_Post             $post    The post.
         */
        $text = apply_filters('seoprostats_coverage_text', $text, $post);
        return is_array($text) ? $text : null;
    }

    /**
     * SEO plugins' title, description, focus keywords, robots rule and
     * canonical address of posts. Robots and canonical are read from the
     * active SEO plugin's fields (any plugin's when none is active), so
     * those left by a plugin no longer used do not count.
     *
     * @param int[] $post_ids Posts.
     * @return array<int,array{plugin:string,seo_title:string,seo_title_vars:bool,description:string,focus:array<int,array{keyword:string,source:string}>,noindex:bool,canonical:string}>
     */
    public static function seo_fields(array $post_ids) {
        global $wpdb;
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (!$post_ids) {
            return array();
        }
        update_meta_cache('post', $post_ids);
        require_once __DIR__ . '/class-seoprostats-changes.php';

        // All in One SEO keeps its fields in its own table.
        $aioseo = array();
        if (function_exists('aioseo')) {
            $holders = implode(', ', array_fill(0, count($post_ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- All in One SEO's table by its post_id key; $holders holds only fixed placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT post_id, title, description, keyphrases, robots_default, robots_noindex, canonical_url FROM %i WHERE post_id IN ($holders)", array_merge(array($wpdb->prefix . 'aioseo_posts'), $post_ids)), ARRAY_A);
            foreach ($rows as $row) {
                $aioseo[(int) $row['post_id']] = $row;
            }
        }

        $active = self::seo_plugin();
        $out    = array();
        foreach ($post_ids as $post_id) {
            $fields = self::NO_SEO;
            foreach (SEOProStats_Changes::SEO_META as $key => $how) {
                $own = $active === '' || strpos($key, self::META_PREFIX[$active]) === 0;
                if ($how[0] === 12 && $own && !$fields['noindex']) {
                    $fields['noindex'] = preg_match('/\bnoindex\b/', SEOProStats_Changes::meta_text($key, get_post_meta($post_id, $key, true))) === 1;
                } elseif ($how[0] === 13 && $own && $fields['canonical'] === '') {
                    $fields['canonical'] = trim((string) get_post_meta($post_id, $key, true));
                }
                if (($how[0] !== 10 && $how[0] !== 11) || ($fields[$how[0] === 10 ? 'seo_title' : 'description'] !== '')) {
                    continue;
                }
                $value = trim((string) get_post_meta($post_id, $key, true));
                if ($value !== '') {
                    $fields[$how[0] === 10 ? 'seo_title' : 'description'] = $value;
                }
            }
            foreach (self::FOCUS_META as $key => $how) {
                foreach (self::focus_values(get_post_meta($post_id, $key, true), $how[1]) as $keyword) {
                    $fields['focus'][] = array('keyword' => $keyword, 'source' => $how[0]);
                }
            }
            if (isset($aioseo[$post_id])) {
                $row = $aioseo[$post_id];
                if ($fields['seo_title'] === '') {
                    $fields['seo_title'] = (string) $row['title'];
                }
                if ($fields['description'] === '') {
                    $fields['description'] = (string) $row['description'];
                }
                if ($active === 'aioseo' || $active === '') {
                    $fields['noindex']   = $fields['noindex'] || (!(int) $row['robots_default'] && (int) $row['robots_noindex']);
                    $fields['canonical'] = $fields['canonical'] !== '' ? $fields['canonical'] : trim((string) $row['canonical_url']);
                }
                $phrases = json_decode((string) $row['keyphrases'], true);
                $list    = is_array($phrases) ? array_merge(isset($phrases['focus']) ? array($phrases['focus']) : array(), isset($phrases['additional']) && is_array($phrases['additional']) ? $phrases['additional'] : array()) : array();
                foreach ($list as $phrase) {
                    if (is_array($phrase) && isset($phrase['keyphrase']) && trim((string) $phrase['keyphrase']) !== '') {
                        $fields['focus'][] = array('keyword' => trim((string) $phrase['keyphrase']), 'source' => 'aioseo');
                    }
                }
            }
            $fields['seo_title_vars'] = preg_match(self::VARIABLES, $fields['seo_title']) === 1;
            $fields['seo_title']      = self::without_variables($fields['seo_title']);
            $fields['description']    = self::without_variables($fields['description']);
            $fields['plugin']         = $fields['focus'] ? $fields['focus'][0]['source'] : $active;

            /**
             * Filters a post's focus keywords (the search terms its SEO
             * plugin aims it at), for plugins SEO Pro Stats does not read.
             *
             * @param array<int,array{keyword:string,source:string}> $focus   Keywords with the plugin that set them.
             * @param int                                            $post_id Post.
             */
            $focus = apply_filters('seoprostats_focus_keywords', $fields['focus'], $post_id);
            $seen  = array();
            $fields['focus'] = array();
            foreach (is_array($focus) ? $focus : array() as $item) {
                $keyword = is_array($item) && isset($item['keyword']) ? trim((string) $item['keyword']) : '';
                if ($keyword !== '' && !isset($seen[self::key($keyword)]) && count($fields['focus']) < 10) {
                    $seen[self::key($keyword)] = true;
                    $fields['focus'][]         = array('keyword' => $keyword, 'source' => isset($item['source']) ? (string) $item['source'] : '');
                }
            }
            $out[$post_id] = $fields;
        }
        return $out;
    }

    /**
     * The active SEO plugin whose focus keywords are read; '' for none.
     *
     * @return string rank-math, yoast, seopress, aioseo or ''.
     */
    public static function seo_plugin() {
        if (defined('RANK_MATH_VERSION')) {
            return 'rank-math';
        }
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('SEOPRESS_VERSION')) {
            return 'seopress';
        }
        return function_exists('aioseo') ? 'aioseo' : '';
    }

    /**
     * Focus keywords in a meta value.
     *
     * @param mixed  $value  Meta value.
     * @param string $format list (comma-separated), text or yoast_json.
     * @return string[]
     */
    private static function focus_values($value, $format) {
        if (!is_string($value) || trim($value) === '') {
            return array();
        }
        if ($format === 'yoast_json') {
            $list = json_decode($value, true);
            $out  = array();
            foreach (is_array($list) ? $list : array() as $item) {
                if (is_array($item) && isset($item['keyword'])) {
                    $out[] = trim((string) $item['keyword']);
                }
            }
            return array_values(array_filter($out));
        }
        return $format === 'list' ? array_values(array_filter(array_map('trim', explode(',', $value)))) : array(trim($value));
    }

    /**
     * An SEO title or description without its plugin's variables
     * (%%title%%, %sitename%…), which stand for text read elsewhere.
     *
     * @param string $text Text.
     * @return string
     */
    private static function without_variables($text) {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::VARIABLES, ' ', $text)));
    }

    /**
     * What a page's words are, ready for matching: term sets of the
     * title, the headings and the whole page, and its word sequence.
     *
     * @param array<string,mixed> $text From text_of_post() or SEOProStats_Demo::text().
     * @return array{title:array<string,bool>,heading:array<string,bool>,all:array<string,bool>,sequence:string,words:int}
     */
    public static function index(array $text) {
        $html = (string) preg_replace('/\[\/?[a-zA-Z][^\[\]]*\]/', ' ', isset($text['content']) ? (string) $text['content'] : '');
        if (strlen($html) > self::MAX_TEXT) {
            $html = function_exists('mb_strcut') ? mb_strcut($html, 0, self::MAX_TEXT, 'UTF-8') : substr($html, 0, self::MAX_TEXT);
        }
        $headings = array();
        if (preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $html, $found)) {
            $headings = $found[1];
        }
        $alts = array();
        if (preg_match_all('/\balt\s*=\s*"([^"]*)"/i', $html, $found)) {
            $alts = $found[1];
        }
        $title   = (string) (isset($text['title']) ? $text['title'] : '') . ' ' . (string) (isset($text['seo_title']) ? $text['seo_title'] : '');
        $heading = self::plain(implode(' ', $headings));
        $body    = self::plain($html) . ' ' . self::plain(implode(' ', $alts)) . ' ' . self::plain(isset($text['excerpt']) ? (string) $text['excerpt'] : '') . ' ' . (string) (isset($text['description']) ? $text['description'] : '');
        $all     = self::stems($title . ' ' . $body);
        return array(
            'title'    => array_fill_keys(self::stems($title), true),
            'heading'  => array_fill_keys(self::stems($heading), true),
            'all'      => array_fill_keys($all, true),
            'sequence' => ' ' . implode(' ', $all) . ' ',
            'words'    => count(self::words($body)),
        );
    }

    /**
     * How far a page's words cover a query: match (one of MATCHES),
     * phrase (the words in order), missing (its words not on the page)
     * and question.
     *
     * @param string                   $query Query.
     * @param array<string,mixed>|null $index From index(); null when the page has no text.
     * @return array{match:string,phrase:bool,missing:string[],question:bool}
     */
    public static function match($query, $index) {
        $terms    = self::terms($query);
        $question = self::is_question($query);
        if (!$index || !$terms) {
            return array('match' => 'none', 'phrase' => false, 'missing' => array_values($terms), 'question' => $question);
        }
        $missing = array();
        foreach ($terms as $stem => $word) {
            if (!isset($index['all'][$stem])) {
                $missing[] = $word;
            }
        }
        $match = 'none';
        if (!$missing) {
            $match = 'text';
            foreach (array('heading', 'title') as $place) {
                if (!array_diff_key($terms, $index[$place])) {
                    $match = $place;
                }
            }
        } elseif (count($missing) < count($terms)) {
            $match = 'partial';
        }
        $phrase = $match !== 'none' && $match !== 'partial' && strpos($index['sequence'], ' ' . implode(' ', self::stems($query)) . ' ') !== false;
        return array('match' => $match, 'phrase' => $phrase, 'missing' => $missing, 'question' => $question);
    }

    /**
     * Whether a query is a question: it starts with a question word or
     * holds a question mark.
     *
     * @param string $query Query.
     * @return bool
     */
    public static function is_question($query) {
        $words = self::words($query);
        return strpos((string) $query, '?') !== false || ($words && in_array($words[0], self::QUESTION_WORDS, true));
    }

    /**
     * A query's terms: stem => first word, without short common words
     * (all its words when that leaves none).
     *
     * @param string $query Query.
     * @return array<string,string>
     */
    private static function terms($query) {
        $words = self::words($query);
        $kept  = array_values(array_filter($words, static function ($word) {
            return !in_array($word, self::STOPWORDS, true) && !preg_match('/^[a-z]$/', $word);
        }));
        $out = array();
        foreach ($kept ? $kept : $words as $word) {
            $stem = self::stem($word);
            if (!isset($out[$stem])) {
                $out[$stem] = $word;
            }
        }
        return $out;
    }

    /**
     * Words of a text: lower case, accents removed, split on anything
     * not a letter or digit.
     *
     * @param string $text Text.
     * @return string[]
     */
    public static function words($text) {
        $text = remove_accents(function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text));
        $out  = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($out) ? $out : array();
    }

    /**
     * Stems of a text's words, in order.
     *
     * @param string $text Text.
     * @return string[]
     */
    private static function stems($text) {
        return array_map(array(__CLASS__, 'stem'), self::words($text));
    }

    /**
     * A light stem: plural endings off (studies → study, links → link).
     *
     * @param string $word Word, lower case.
     * @return string
     */
    private static function stem($word) {
        $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
        if ($length > 4 && substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        if ($length > 3 && substr($word, -1) === 's' && !in_array(substr($word, -2), array('ss', 'us', 'is'), true)) {
            return substr($word, 0, -1);
        }
        return $word;
    }

    /**
     * Text of HTML: tags, scripts and styles out, entities decoded.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function plain($html) {
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        return html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A query's key for finding it again: its words, in order.
     *
     * @param string $query Query.
     * @return string
     */
    private static function key($query) {
        return implode(' ', self::words($query));
    }
}
