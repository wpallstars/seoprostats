<?php
/**
 * Abilities (WordPress 6.9 and later): what SEO Pro Stats can do for AI
 * agents and other tools, through WordPress's Abilities API (and its REST
 * routes under wp-abilities/v1). The same engine as the REST API and
 * WP-CLI answers them.
 *
 * - seoprostats/markers: the changes in a range, oldest first (read).
 * - seoprostats/annotate: add a note to the timeline (administrators).
 * - seoprostats/search: Search Console clicks, impressions, CTR and
 *   position, with top queries, pages, countries or devices (read).
 * - seoprostats/opportunities: striking-distance and low-CTR queries,
 *   pages losing clicks with the likely cause, and queries missing from
 *   their page (read).
 * - seoprostats/coverage: one page's queries, each checked against the
 *   page's words, questions and SEO plugin focus keywords (read).
 * - seoprostats/content: per page, search clicks and position with the
 *   visits from search that landed on it and their conversions (read).
 *
 * On older WordPress the hooks never run.
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

final class SEOProStats_Abilities {

    /** The abilities' category. */
    const CATEGORY = 'site-statistics';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'));
    }

    /**
     * The category, unless another plugin made it first.
     */
    public static function register_category() {
        if (function_exists('wp_has_ability_category') && wp_has_ability_category(self::CATEGORY)) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('Site statistics', 'seoprostats'),
            'description' => __('Visits, sources, conversions and the changes that moved them.', 'seoprostats'),
        ));
    }

    /**
     * The abilities.
     */
    public static function register() {
        SEOProStats_API::load();
        $data = array(
            'type'        => 'string',
            'enum'        => SEOProStats_Schema::SETS,
            'default'     => 'live',
            'description' => __('Live statistics, or the demo data.', 'seoprostats'),
        );
        wp_register_ability('seoprostats/markers', array(
            'label'               => __('Changes on the timeline', 'seoprostats'),
            'description'         => __('What changed on the site in a period, oldest first: posts published, edited or moved, SEO fields, prices and stock, plugin, theme and WordPress updates, settings, search engine updates (when switched on) and notes. Set them against traffic and sales to see what moved them.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'range' => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoprostats'),
                    ),
                    'from'  => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'    => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'page'  => array(
                        'type'        => 'string',
                        'description' => __('Only changes to this page (a path such as /pricing/; * for any text), and the site-wide ones.', 'seoprostats'),
                    ),
                    'kinds' => array(
                        'type'        => 'string',
                        'description' => __('Only these kinds or groups of change (content, seo, product, site, search, note), comma-separated.', 'seoprostats'),
                    ),
                    'data'  => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'range'   => array('type' => 'object'),
                    'markers' => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'   => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'markers'),
            'permission_callback' => array('SEOProStats_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoprostats/annotate', array(
            'label'               => __('Add a note to the timeline', 'seoprostats'),
            'description'         => __('Note something the change log cannot see, such as a newsletter sent, a sale or a move to a new host, so the charts show it next to the traffic it affected.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('note'),
                'properties'           => array(
                    'note' => array(
                        'type'        => 'string',
                        'minLength'   => 1,
                        'maxLength'   => SEOProStats_Changes::MAX_VALUE,
                        'description' => __('What happened.', 'seoprostats'),
                    ),
                    'page' => array(
                        'type'        => 'string',
                        'description' => __('The page it is about (a path such as /pricing/); without it, the whole site.', 'seoprostats'),
                    ),
                    'time' => array(
                        'type'        => 'string',
                        'description' => __('When, in the site time zone (2026-10-05 or 2026-10-05 14:30); without it, now.', 'seoprostats'),
                    ),
                    'data' => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'annotate'),
            'permission_callback' => array('SEOProStats_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ),
            ),
        ));
        $engine = array(
            'type'        => 'string',
            'enum'        => array_keys(SEOProStats_Search::ENGINES),
            'default'     => 'google',
            'description' => __('Search engine: google (Search Console) or bing (Bing Webmaster Tools). Bing gives pages and queries by week, stored on each week\'s last day, and no countries or devices.', 'seoprostats'),
        );
        wp_register_ability('seoprostats/search', array(
            'label'               => __('Search rankings', 'seoprostats'),
            'description'         => __('Search engine clicks, impressions, CTR and average position in a period (Google Search Console, or Bing Webmaster Tools with engine bing), with the comparison, and the top search queries, pages, countries or devices; or one page\'s queries, or one query\'s pages. Search data is final only, so the newest day is some days old; the period is cut there. The answer lists the engines with data.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'engine'  => $engine,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoprostats'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::COMPARE,
                        'default'     => 'none',
                        'description' => __('Compare with the period before (prev) or the same period last year (year).', 'seoprostats'),
                    ),
                    'kind'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Search::KINDS,
                        'default'     => 'queries',
                        'description' => __('Rows: search queries, pages, countries or devices (countries and devices for the whole site only).', 'seoprostats'),
                    ),
                    'page'    => array(
                        'type'        => 'string',
                        'description' => __('Only searches that showed this page (a path such as /pricing/; * for any text).', 'seoprostats'),
                    ),
                    'query'   => array(
                        'type'        => 'string',
                        'description' => __('Only this search query (* for any text).', 'seoprostats'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Query::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'engines'   => array(
                        'type'  => 'array',
                        'items' => array('type' => 'string'),
                    ),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'grain'     => array('type' => 'string'),
                    'totals'    => array('type' => 'object'),
                    'points'    => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'search'),
            'permission_callback' => array('SEOProStats_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoprostats/opportunities', array(
            'label'               => __('Search opportunities', 'seoprostats'),
            'description'         => __('Where search work pays, from Google Search Console (or Bing Webmaster Tools with engine bing): striking (a page\'s query at position 4–20, with the clicks it could gain in the top three), ctr (a top-10 query whose CTR is well under the site\'s own at that position: improve its title and description), decay (pages losing clicks against the previous period, each with the likely cause, position, demand, ctr or gone, the queries that lost most, and the changes made to the page) or missing (a top-20 query whose words its page does not have, or has only some of: the words missing, and whether it is a question to answer). Expected CTR is the site\'s own. Final days only; at most the newest 91 days of the period are read.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Opportunities::KINDS,
                        'default'     => 'striking',
                        'description' => __('striking, ctr, decay or missing.', 'seoprostats'),
                    ),
                    'engine'  => $engine,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoprostats'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => array('prev', 'year'),
                        'default'     => 'prev',
                        'description' => __('For decay: against the period before (prev) or the same period last year (year).', 'seoprostats'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Query::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'kind'      => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'opportunities'),
            'permission_callback' => array('SEOProStats_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoprostats/coverage', array(
            'label'               => __('Query coverage of a page', 'seoprostats'),
            'description'         => __('The Google Search Console queries one page shows for (most impressions first, up to 200), each with how far the page\'s own words cover it: title (every word in the title or SEO title), heading, text, partial or none, the words missing, and whether it is a question. Also the focus keywords of Rank Math, Yoast SEO, SEOPress or All in One SEO when one is active, with their search figures. Queries the page does not cover are cheap wins: add the words, or answer the question in a heading. Works without any SEO plugin. Final days only; at most the newest 91 days of the period are read.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'page'  => array(
                        'type'        => 'string',
                        'description' => __('The page (a path such as /pricing/); or give post.', 'seoprostats'),
                    ),
                    'post'  => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('The post whose address is the page; or give page.', 'seoprostats'),
                    ),
                    'range' => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '90d',
                        'description' => __('Period, in the site time zone.', 'seoprostats'),
                    ),
                    'from'  => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'    => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'data'  => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'page'      => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'text'      => array('type' => array('object', 'null')),
                    'focus'     => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'totals'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'coverage'),
            'permission_callback' => array('SEOProStats_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoprostats/content', array(
            'label'               => __('Content performance', 'seoprostats'),
            'description'         => __('Which pages earn their search traffic: per page, Google Search Console (or Bing Webmaster Tools with engine bing) clicks, impressions, CTR and position, with the visits from search that landed on the page (bounce rate, views per visit, visit duration in seconds) and how many reached a goal (conversions, conversion rate; the first goal unless one is named). A page that ranks but whose visits leave or never convert needs better content or a clearer next step; one that converts but gets few clicks is worth ranking higher. Final search days only.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'sort'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Content::SORTS,
                        'default'     => 'clicks',
                        'description' => __('Order, most first: search clicks, visits from search, or conversions.', 'seoprostats'),
                    ),
                    'goal'    => array(
                        'type'        => 'string',
                        'description' => __('ID of the goal counted (the answer lists the goals); the first when left out.', 'seoprostats'),
                    ),
                    'engine'  => $engine,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoprostats'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => array('none', 'prev', 'year'),
                        'default'     => 'none',
                        'description' => __('Also the period before (prev) or the same period last year (year), with the change.', 'seoprostats'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Query::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'        => array('type' => 'string'),
                    'range'         => array('type' => 'object'),
                    'through'       => array('type' => 'string'),
                    'connected'     => array('type' => 'boolean'),
                    'goal'          => array('type' => array('object', 'null')),
                    'goals'         => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'partial'       => array('type' => 'boolean'),
                    'landings_from' => array('type' => 'string'),
                    'totals'        => array('type' => 'object'),
                    'rows'          => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'         => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'content'),
            'permission_callback' => array('SEOProStats_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
    }

    /**
     * seoprostats/content.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function content($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d', 'compare' => 'none', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $sort   = isset($input['sort']) ? (string) $input['sort'] : 'clicks';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $sort, $goal, $engine) {
            return SEOProStats_Content::report((array) $req, $sort, $goal, $engine);
        });
    }

    /**
     * seoprostats/coverage.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function coverage($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '90d', 'compare' => 'none'));
        if (is_wp_error($req)) {
            return $req;
        }
        $page = isset($input['page']) ? (string) $input['page'] : '';
        $post = isset($input['post']) ? (int) $input['post'] : 0;
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $page, $post) {
            return SEOProStats_Coverage::report((array) $req, $page, $post);
        });
    }

    /**
     * seoprostats/opportunities.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function opportunities($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d', 'compare' => 'prev', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'striking';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $kind, $engine) {
            return SEOProStats_Opportunities::report((array) $req, $kind, $engine);
        });
    }

    /**
     * seoprostats/search.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function search($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind  = isset($input['kind']) ? (string) $input['kind'] : 'queries';
        $page  = isset($input['page']) ? (string) $input['page'] : '';
        $query  = isset($input['query']) ? (string) $input['query'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $kind, $page, $query, $engine) {
            return SEOProStats_Search::report((array) $req, $kind, $page, $query, $engine);
        });
    }

    /**
     * seoprostats/markers.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function markers($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d'));
        if (is_wp_error($req)) {
            return $req;
        }
        $args = array(
            'page'    => isset($input['page']) ? (string) $input['page'] : '',
            'kinds'   => isset($input['kinds']) ? (string) $input['kinds'] : '',
            'limit'   => SEOProStats_Changes::MAX_LIMIT,
            'order'   => 'asc',
            'running' => true,
        );
        $answer = SEOProStats_API::on_data(self::data($input), static function () use ($req, $args) {
            return SEOProStats_Changes::list_changes((array) $req, $args);
        });
        if (is_wp_error($answer)) {
            return $answer;
        }
        return array(
            'range'   => $answer['range'],
            'markers' => $answer['changes'],
            'total'   => $answer['total'],
        );
    }

    /**
     * seoprostats/annotate.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function annotate($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProStats_API::on_data(self::data($input), static function () use ($input) {
            return SEOProStats_Changes::annotate(
                isset($input['note']) ? (string) $input['note'] : '',
                isset($input['page']) ? (string) $input['page'] : '',
                isset($input['time']) ? (string) $input['time'] : ''
            );
        });
    }

    /**
     * The data set asked for.
     *
     * @param array<string,mixed> $input Input.
     * @return string
     */
    private static function data(array $input) {
        return isset($input['data']) && $input['data'] === 'demo' ? 'demo' : 'live';
    }
}
