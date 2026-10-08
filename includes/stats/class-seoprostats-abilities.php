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
 *   pages losing clicks with the likely cause, queries missing from
 *   their page, and queries shared by several pages (read).
 * - seoprostats/audit: published pages with findings from their content
 *   and SEO plugin fields, weighed by search impressions (read).
 * - seoprostats/links: orphan pages, converting pages with few links in,
 *   and links missing between pages that share a search (read).
 * - seoprostats/indexation: published pages and sitemap addresses
 *   search has not shown in its newest days (read).
 * - seoprostats/coverage: one page's queries, each checked against the
 *   page's words, questions and SEO plugin focus keywords (read).
 * - seoprostats/content: per page, search clicks and position with the
 *   visits from search that landed on it and their conversions (read).
 * - seoprostats/experiments: changes and what they were meant to do,
 *   measured against unchanged pages, with a suggested result (read).
 * - seoprostats/experiment-record: record, decide, note or cancel an
 *   experiment (administrators).
 * - seoprostats/queue: the decision queue, a ranked list of search work
 *   with each item's why and score parts (read).
 * - seoprostats/queue-update: accept, do (opens an experiment), dismiss
 *   or restore an item, or set its effort or note (administrators).
 * - seoprostats/targets: the site's search targets, each with its
 *   position, clicks and the page that ranks (read).
 * - seoprostats/targets-import: import or delete search targets
 *   (administrators).
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
            'description'         => __('Where search work pays, from Google Search Console (or Bing Webmaster Tools with engine bing): striking (a page\'s query at position 4–20, with the clicks it could gain in the top three), ctr (a top-10 query whose CTR is well under the site\'s own at that position: improve its title and description), decay (pages losing clicks against the previous period, each with the likely cause, position, demand, ctr or gone, the queries that lost most, and the changes made to the page), missing (a top-20 query whose words its page does not have, or has only some of: the words missing, and whether it is a question to answer) or overlap (a query for which two or more pages each get at least 10% of the impressions, with each page\'s clicks, share and position and whether the page with most impressions changed between the halves of the period: a candidate to review, as two pages can both be right). Expected CTR is the site\'s own. Final days only; at most the newest 91 days of the period are read.', 'seoprostats'),
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
                        'description' => __('striking, ctr, decay, missing or overlap.', 'seoprostats'),
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
        wp_register_ability('seoprostats/audit', array(
            'label'               => __('Content audit', 'seoprostats'),
            'description'         => __('Published pages with findings from their WordPress content and SEO plugin fields, most search impressions first: title or description missing, too long or the same as another page\'s (with those pages), no H1 or several, images without alt text, a thin page (few words, with impressions but no clicks), and noindex or a canonical address elsewhere on a page with search impressions. Each page has its search figures and its facts (lengths, words, H1s, images, robots, when it was read); counts give the pages per finding. Facts are read when a post is saved and by a daily batch.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'finding' => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProStats_Audit::FINDINGS),
                        'default'     => '',
                        'description' => __('Only pages with this finding; all when empty.', 'seoprostats'),
                    ),
                    'engine'  => $engine,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period of the search figures, in the site time zone.', 'seoprostats'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Audit::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset'  => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'checked'   => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'pages'     => array('type' => 'integer'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'audit'),
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
        wp_register_ability('seoprostats/links', array(
            'label'               => __('Internal links', 'seoprostats'),
            'description'         => __('Links between the site\'s own pages, read from the text of published pages, in three lists. orphans: pages no other page links to, most search impressions first. converting: pages whose visits from search reach the goal 3 times or more with 2 or fewer pages linking to them, most conversions first: link to them from related pages. The front page is in neither list. missing: a page shows for a search but does not link to the page that gets most of its clicks, with the searches and both pages\' figures, most impressions first: add the link. Rows name the pages linking in (up to 5); counts give each list\'s size and read how many pages\' links were read so far. Links are read with the content audit when a post is saved and by a daily batch.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Links::KINDS,
                        'default'     => 'orphans',
                        'description' => __('Which list.', 'seoprostats'),
                    ),
                    'goal'   => array(
                        'type'        => 'string',
                        'default'     => '',
                        'description' => __('ID of the goal whose conversions are counted; the first goal when empty.', 'seoprostats'),
                    ),
                    'engine' => $engine,
                    'range'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period of the search figures and conversions, in the site time zone.', 'seoprostats'),
                    ),
                    'from'   => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'to'     => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                    ),
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Links::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'connected' => array('type' => 'boolean'),
                    'kind'      => array('type' => 'string'),
                    'goal'      => array('type' => array('object', 'null')),
                    'rules'     => array('type' => 'object'),
                    'read'      => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'links'),
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
        wp_register_ability('seoprostats/indexation', array(
            'label'               => __('Indexation', 'seoprostats'),
            'description'         => __('Pages search engines do not seem to show, from the site\'s own search data, in two lists. pages: published pages with no search impressions in the engine\'s newest days (28 by default), published before them. sitemap: other addresses in the site\'s own sitemaps (category, tag and author archives, other plugins\'), listed that long, with none. Each row says whether search never showed it (state never) or showed it until last_impression (state lost), with its age in days; pages add words and links_in (pages linking to it). Never shown first, then the newest. Pages that ask not to be indexed or name another page as canonical are left out (skipped counts them). typical is a page\'s clicks per 28 days here when search shows it. Fix: check the page may be indexed and is linked and in the sitemap, ask the engine to crawl it, or improve or merge it. Engine URL inspection is not used.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Indexation::KINDS,
                        'default'     => 'pages',
                        'description' => __('Which list.', 'seoprostats'),
                    ),
                    'days'   => array(
                        'type'        => 'integer',
                        'minimum'     => SEOProStats_Indexation::MIN_DAYS,
                        'maximum'     => SEOProStats_Indexation::MAX_DAYS,
                        'default'     => SEOProStats_Indexation::DAYS,
                        'description' => __('Days without search impressions, and since publishing or first listing.', 'seoprostats'),
                    ),
                    'engine' => $engine,
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Indexation::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'kind'      => array('type' => 'string'),
                    'days'      => array('type' => 'integer'),
                    'rules'     => array('type' => 'object'),
                    'read'      => array('type' => 'object'),
                    'typical'   => array('type' => 'number'),
                    'skipped'   => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'indexation'),
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
        self::register_experiments($data, $engine);
        self::register_queue($data, $engine);
        self::register_targets($data, $engine);
    }

    /**
     * The search target abilities.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_targets(array $data, array $engine) {
        wp_register_ability('seoprostats/targets', array(
            'label'               => __('Search targets', 'seoprostats'),
            'description'         => __('The searches the site chose to win and the page meant for each (imported with seoprostats/targets-import), with how search treats them now: the query\'s clicks, impressions, CTR and position on any page, the page search shows most for it (shown) and the page meant for it (page) with its own figures, as a state: ranking (the page meant for it is the one shown most), wrong_page (another page is), no_page (none chosen yet) or not_shown (no impressions in the period). band is top (positions 1–3), striking (4–20) or beyond. Highest priority first. The decision queue lists open targets shown with the wrong page, and high-priority targets in striking distance (kind target). Final days only; at most the newest 91 days of the period are read.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Targets::FILTERS,
                        'default'     => 'all',
                        'description' => __('all, open (candidate, targeted and live), candidate, targeted, live, won or retired.', 'seoprostats'),
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
                        'enum'        => SEOProStats_Query::COMPARE,
                        'default'     => 'none',
                        'description' => __('previous or year adds each target\'s position and clicks then.', 'seoprostats'),
                    ),
                    'engine'  => $engine,
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Targets::MAX_LIMIT,
                        'default' => SEOProStats_Targets::LIMIT,
                    ),
                    'offset'  => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'statuses'  => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'targets'),
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
        wp_register_ability('seoprostats/targets-import', array(
            'label'               => __('Import or delete search targets', 'seoprostats'),
            'description'         => __('Add or update search targets by query, from targets (a list of objects: query or phrase; page or target_url, a path such as /pricing/ or an address on this site, left out when no page is chosen yet; priority 0–100 or high, medium, low, 50 when left out; status candidate, targeted, live, won or retired, targeted when left out) or text (CSV or tab-separated with a header row, JSON, or the aidevops search targets table in TOON). Rows without search text, with an address that is not on this site, or with a priority or status that cannot be read are skipped and listed with the reason, never guessed. replace deletes targets not in the import; delete removes the searches given (all: every target) instead of importing.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'targets' => array(
                        'type'     => 'array',
                        'maxItems' => SEOProStats_Targets::MAX_ROWS,
                        'items'    => array('type' => 'object'),
                    ),
                    'text'    => array(
                        'type'      => 'string',
                        'maxLength' => SEOProStats_Targets::MAX_BYTES,
                    ),
                    'replace' => array(
                        'type'    => 'boolean',
                        'default' => false,
                    ),
                    'delete'  => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => __('The searches whose targets to delete (instead of importing).', 'seoprostats'),
                    ),
                    'all'     => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => __('With delete: delete every target.', 'seoprostats'),
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'targets_import'),
            'permission_callback' => array('SEOProStats_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => true,
                ),
            ),
        ));
    }

    /**
     * seoprostats/targets.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function targets($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d', 'limit' => SEOProStats_Targets::LIMIT));
        if (is_wp_error($req)) {
            return $req;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $status = isset($input['status']) ? (string) $input['status'] : 'all';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $engine, $status) {
            return SEOProStats_Targets::report((array) $req, $engine, $status);
        });
    }

    /**
     * seoprostats/targets-import.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function targets_import($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProStats_API::on_data(self::data($input), static function () use ($input) {
            if (isset($input['delete']) || !empty($input['all'])) {
                return SEOProStats_Targets::delete(array_map('strval', isset($input['delete']) ? (array) $input['delete'] : array()), !empty($input['all']));
            }
            $format = 'list';
            $rows   = isset($input['targets']) ? (array) $input['targets'] : array();
            if (!$rows) {
                $parsed = SEOProStats_Targets::parse(isset($input['text']) ? (string) $input['text'] : '');
                if (is_wp_error($parsed)) {
                    return $parsed;
                }
                $rows   = $parsed['rows'];
                $format = $parsed['format'];
            }
            $done = SEOProStats_Targets::import($rows, $format === 'toon' ? 'aidevops' : 'list', !empty($input['replace']));
            return is_wp_error($done) ? $done : array('format' => $format) + $done;
        });
    }

    /**
     * The decision queue abilities.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_queue(array $data, array $engine) {
        $period = array(
            'engine' => $engine,
            'range'  => array(
                'type'        => 'string',
                'enum'        => SEOProStats_Query::RANGES,
                'default'     => '90d',
                'description' => __('The period the list is made from, in the site time zone (at most its newest 91 days are read).', 'seoprostats'),
            ),
            'from'   => array(
                'type'        => 'string',
                'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
            ),
            'to'     => array(
                'type'        => 'string',
                'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
            ),
            'goal'   => array(
                'type'        => 'string',
                'description' => __('The goal whose conversions give a page its value; the first goal when left out.', 'seoprostats'),
            ),
            'data'   => $data,
        );
        wp_register_ability('seoprostats/queue', array(
            'label'               => __('Decision queue', 'seoprostats'),
            'description'         => __('One ranked list of search work made from the opportunities (ctr: rewrite a title and description; missing: answer a search the page lacks; striking: improve a page ranking 4–20; decay: find why a page lost clicks, then update it; overlap: review a search several pages share, and make one the clear answer if they serve the same need; audit: fix a content audit finding on a page with search impressions, one item per page and finding; links: internal links to add; index: a page search does not show; refresh: the refresh planner\'s proposal for a page losing clicks, in place of its decay item: update it, leave it (fewer people search), protect it (it converts: change it carefully) or merge it (another page of the site overtook it for a search it lost), with the reason and the numbers in why and figures; it never changes content; target: a search target (seoprostats/targets) shown with another page than the one meant for it (finding wrong_page), or a high-priority target in striking distance (finding striking, in place of its striking item)). Each item has a key, its page and query (or finding), why it is listed, its figures and its score with the parts: potential clicks per 28 days × value (how well the page\'s visits from search convert against the site, at least 1) × confidence (the kind\'s, weighed by impressions) ÷ effort, so you can rank by your own rule. Pages with a running experiment are left out of new items; done items show their experiment\'s result.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => $period + array(
                    'status' => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Queue::FILTERS,
                        'default'     => 'open',
                        'description' => __('open (new and accepted), new, accepted, done, dismissed (in the last 90 days) or all.', 'seoprostats'),
                    ),
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProStats_Queue::KINDS),
                        'description' => __('Only items of this kind, e.g. refresh; every kind when left out.', 'seoprostats'),
                    ),
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Queue::MAX_LIMIT,
                        'default' => 25,
                    ),
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'days'      => array('type' => 'integer'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'items'     => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'queue'),
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
        wp_register_ability('seoprostats/queue-update', array(
            'label'               => __('Act on a decision queue item', 'seoprostats'),
            'description'         => __('Accept an item of the decision queue, mark it done (opens an experiment on its page with the kind\'s measure: CTR, clicks, impressions or position; none for a refresh proposal to leave the page as it is), dismiss it (hidden for 90 days), restore it, or set its effort (1–5) or a note. Use a key from seoprostats/queue, with the same period, engine and goal.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('key', 'action'),
                'properties'           => $period + array(
                    'key'       => array(
                        'type'        => 'string',
                        'pattern'     => '^[0-9a-fA-F]{16}$',
                        'description' => __('The item\'s key.', 'seoprostats'),
                    ),
                    'action'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProStats_Queue::ACTIONS,
                        'description' => __('accept, done, dismiss, restore, effort or note.', 'seoprostats'),
                    ),
                    'effort'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProStats_Queue::MAX_EFFORT,
                    ),
                    'note'      => array(
                        'type'      => 'string',
                        'maxLength' => 190,
                    ),
                    'name'      => array(
                        'type'        => 'string',
                        'maxLength'   => 190,
                        'description' => __('For done: the experiment\'s name.', 'seoprostats'),
                    ),
                    'days'      => array(
                        'type'        => 'integer',
                        'enum'        => SEOProStats_Experiments::WINDOWS,
                        'description' => __('For done: days in each window of the experiment (default 28).', 'seoprostats'),
                    ),
                    'threshold' => array(
                        'type'        => 'number',
                        'minimum'     => 0,
                        'description' => __('For done: the smallest change that counts: percent (default 10), or places for position (default 1).', 'seoprostats'),
                    ),
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'queue_update'),
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
    }

    /**
     * seoprostats/queue.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function queue($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '90d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $status = isset($input['status']) ? (string) $input['status'] : 'open';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $kind   = isset($input['kind']) ? (string) $input['kind'] : '';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $engine, $status, $goal, $kind) {
            return SEOProStats_Queue::report((array) $req, $engine, $status, $goal, $kind);
        });
    }

    /**
     * seoprostats/queue-update.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function queue_update($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request(array_diff_key($input, array('limit' => true)) + array('range' => '90d'));
        if (is_wp_error($req)) {
            return $req;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $key    = isset($input['key']) ? (string) $input['key'] : '';
        return SEOProStats_API::on_data(self::data($input), static function () use ($key, $input, $req, $engine, $goal) {
            return SEOProStats_Queue::update($key, $input, (array) $req, $engine, $goal);
        });
    }

    /**
     * The experiment abilities.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_experiments(array $data, array $engine) {
        wp_register_ability('seoprostats/experiments', array(
            'label'               => __('Experiments', 'seoprostats'),
            'description'         => __('Changes made to pages and what they were meant to do, each measured over equal windows before and after against comparable unchanged pages: the effect, the usual spread of unchanged pages, the search engine updates and other changes of the time, and a suggested result (keep, revise, undo or inconclusive). Due ones first. With id, one experiment.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'id'     => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('One experiment, by id.', 'seoprostats'),
                    ),
                    'status' => array(
                        'type'        => 'string',
                        'enum'        => array('', 'running', 'due', 'decided', 'cancelled'),
                        'description' => __('Only experiments in this state; due means running with data through the review day.', 'seoprostats'),
                    ),
                    'page'   => array(
                        'type'        => 'string',
                        'description' => __('Only experiments on this page (a path such as /pricing/).', 'seoprostats'),
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'experiments'),
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
        wp_register_ability('seoprostats/experiment-record', array(
            'label'               => __('Record or decide an experiment', 'seoprostats'),
            'description'         => __('Record an experiment before its result is known (a change to a page and what it should do: the measure, the direction and the smallest change that counts), or decide one (keep, revise, undo or inconclusive, with a note), or cancel it. Starting from a change in the change log fills in its time and page.', 'seoprostats'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('action'),
                'properties'           => array(
                    'action'     => array(
                        'type'        => 'string',
                        'enum'        => array('add', 'decide', 'note', 'cancel'),
                        'description' => __('add a new experiment; decide, note or cancel one (with id).', 'seoprostats'),
                    ),
                    'id'         => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('For decide, note and cancel: the experiment.', 'seoprostats'),
                    ),
                    'name'       => array(
                        'type'        => 'string',
                        'maxLength'   => 190,
                        'description' => __('For add: the change and what it should do, in one line.', 'seoprostats'),
                    ),
                    'change'     => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => __('For add: the change it measures (its id from seoprostats/markers); its time is the start and its page the page.', 'seoprostats'),
                    ),
                    'start'      => array(
                        'type'        => 'string',
                        'description' => __('For add without change: when the change was made, in the site time zone (2026-10-05 or 2026-10-05 14:30); without it, now.', 'seoprostats'),
                    ),
                    'page'       => array(
                        'type'        => 'string',
                        'description' => __('For add: its page (a path such as /pricing/), or several, comma-separated (up to 50).', 'seoprostats'),
                    ),
                    'days'       => array(
                        'type'        => 'integer',
                        'enum'        => SEOProStats_Experiments::WINDOWS,
                        'description' => __('For add: days in each window, before and after (default 28).', 'seoprostats'),
                    ),
                    'engine'     => $engine,
                    'metric'     => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProStats_Experiments::METRICS),
                        'description' => __('For add: what it should change (default clicks). Visits are visits from search landing on the pages; conversions need goal.', 'seoprostats'),
                    ),
                    'direction'  => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProStats_Experiments::DIRECTIONS),
                        'description' => __('For add: expected direction (default up; for position, up means a better place).', 'seoprostats'),
                    ),
                    'threshold'  => array(
                        'type'        => 'number',
                        'minimum'     => 0,
                        'description' => __('For add: the smallest change that counts: percent (default 10), or places for position (default 1).', 'seoprostats'),
                    ),
                    'goal'       => array(
                        'type'        => 'string',
                        'description' => __('For add with metric conversions: the goal\'s id.', 'seoprostats'),
                    ),
                    'hypothesis' => array(
                        'type'        => 'string',
                        'maxLength'   => 2000,
                        'description' => __('For add: the reasoning, in more words.', 'seoprostats'),
                    ),
                    'result'     => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProStats_Experiments::RESULTS),
                        'description' => __('For decide: the result.', 'seoprostats'),
                    ),
                    'note'       => array(
                        'type'        => 'string',
                        'maxLength'   => 2000,
                        'description' => __('Why, or what was learnt.', 'seoprostats'),
                    ),
                    'data'       => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'experiment_record'),
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
    }

    /**
     * seoprostats/experiments.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function experiments($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProStats_API::on_data(self::data($input), static function () use ($input) {
            if (!empty($input['id'])) {
                return SEOProStats_Experiments::get((int) $input['id']);
            }
            return SEOProStats_Experiments::list_experiments(array(
                'status' => isset($input['status']) ? (string) $input['status'] : '',
                'page'   => isset($input['page']) ? (string) $input['page'] : '',
            ));
        });
    }

    /**
     * seoprostats/experiment-record.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function experiment_record($input = null) {
        $input  = is_array($input) ? $input : array();
        $action = isset($input['action']) ? (string) $input['action'] : '';
        return SEOProStats_API::on_data(self::data($input), static function () use ($input, $action) {
            if ($action === 'add') {
                return SEOProStats_Experiments::add($input);
            }
            if (empty($input['id'])) {
                return new WP_Error('seoprostats_experiment', __('Give the experiment\'s id (seoprostats/experiments lists them).', 'seoprostats'), array('status' => 400));
            }
            return SEOProStats_Experiments::update((int) $input['id'], $input);
        });
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
     * seoprostats/audit.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function audit($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $finding = isset($input['finding']) ? (string) $input['finding'] : '';
        $engine  = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $finding, $engine) {
            return SEOProStats_Audit::report((array) $req, $engine, $finding);
        });
    }

    /**
     * seoprostats/links.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function links($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request($input + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'orphans';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $kind, $goal, $engine) {
            return SEOProStats_Links::report((array) $req, $engine, $kind, $goal);
        });
    }

    /**
     * seoprostats/indexation.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function indexation($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProStats_Query::request(array_diff_key($input, array('days' => 1)) + array('limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'pages';
        $days   = isset($input['days']) ? (int) $input['days'] : SEOProStats_Indexation::DAYS;
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProStats_API::on_data(self::data($input), static function () use ($req, $kind, $days, $engine) {
            return SEOProStats_Indexation::report((array) $req, $engine, $kind, $days);
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
