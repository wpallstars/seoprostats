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
 *   and pages losing clicks with the likely cause (read).
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
        wp_register_ability('seoprostats/search', array(
            'label'               => __('Search rankings', 'seoprostats'),
            'description'         => __('Google Search Console clicks, impressions, CTR and average position in a period, with the comparison, and the top search queries, pages, countries or devices; or one page\'s queries, or one query\'s pages. Search data is final only, so the newest day is about three days old; the period is cut there.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
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
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
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
            'description'         => __('Where search work pays, from Google Search Console: striking (a page\'s query at position 4–20, with the clicks it could gain in the top three), ctr (a top-10 query whose CTR is well under the site\'s own at that position: improve its title and description) or decay (pages losing clicks against the previous period, each with the likely cause, position, demand, ctr or gone, the queries that lost most, and the changes made to the page). Expected CTR is the site\'s own. Final days only; at most the newest 91 days of the period are read.', 'seoprostats'),
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
                        'description' => __('striking, ctr or decay.', 'seoprostats'),
                    ),
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
        $kind = isset($input['kind']) ? (string) $input['kind'] : 'striking';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $kind) {
            return SEOProStats_Opportunities::report((array) $req, $kind);
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
        $query = isset($input['query']) ? (string) $input['query'] : '';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $kind, $page, $query) {
            return SEOProStats_Search::report((array) $req, $kind, $page, $query);
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
