<?php
/**
 * The REST API (namespace seoprostats/v1): the reports stats, timeseries,
 * breakdown, realtime, markers, changes, goals, funnels, properties, clicks,
 * search, opportunities and content, each on
 * live data or the demo data (data=demo); goals and funnels also add,
 * change and delete their definitions (administrators); demo (make, carry
 * on, remove) and view (the data set a person sees). Contract:
 * docs/api/openapi.yaml.
 *
 * Reading needs the view_seoprostats capability: administrators (anyone
 * with manage_options) and the roles Settings → Data allows. Agents use
 * Application Passwords. The report engine loads
 * only on REST and WP-CLI requests.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_API {

    /** Capability that reads statistics. */
    const CAP = 'view_seoprostats';

    /**
     * Register hooks.
     */
    public static function init() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-shares.php';
        add_filter('map_meta_cap', array(__CLASS__, 'map_meta_cap'), 10, 3);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * view_seoprostats is granted to whoever can manage options, and to
     * people with a role Settings → Data allows.
     *
     * @param string[] $caps    Primitive capabilities required.
     * @param string   $cap     Capability checked.
     * @param int      $user_id User checked.
     * @return string[]
     */
    public static function map_meta_cap($caps, $cap, $user_id = 0) {
        if ($cap !== self::CAP) {
            return $caps;
        }
        $roles = SEOProStats_Statistics::viewer_roles();
        if ($roles && $user_id) {
            $user = get_userdata((int) $user_id);
            if ($user && array_intersect((array) $user->roles, $roles)) {
                return array('read');
            }
        }
        /**
         * Filters the capabilities a user needs to read SEO Pro Stats.
         *
         * @param string[] $caps Default: manage_options.
         */
        $needed = apply_filters('seoprostats_view_caps', array('manage_options'));
        return is_array($needed) && $needed ? array_map('strval', $needed) : array('manage_options');
    }

    /**
     * Load the report engine.
     */
    public static function load() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-dict.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-query.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-demo.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-goals.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-conversions.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-clicks.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-opportunities.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-coverage.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-content.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-changes.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-experiments.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-queue.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-audit.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-links.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-indexation.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-targets.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-loop.php';
    }

    /**
     * Run a report on the data set asked for: live, or demo when it is
     * made (topped up to now first).
     *
     * @param string   $data live or demo.
     * @param callable $work Makes the answer.
     * @return mixed|WP_Error The answer.
     */
    public static function on_data($data, callable $work) {
        if ($data !== 'demo') {
            return $work();
        }
        if (!SEOProStats_Demo::ready()) {
            return new WP_Error('seoprostats_no_demo', __('There is no demo data yet. An administrator can make it: switch on Demo data in SEO Pro Stats, or run wp seoprostats demo make.', 'seoprostats'), array('status' => 404));
        }
        SEOProStats_Demo::refresh();
        return SEOProStats_Demo::run($work);
    }

    /**
     * Encode decimals in their shortest form (0.1667, not
     * 0.16669999999999999) for this request's JSON, as PHP does by
     * default; some servers set serialize_precision to 17.
     */
    public static function short_floats() {
        if ((string) ini_get('serialize_precision') !== '-1') {
            ini_set('serialize_precision', '-1'); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions -- only for our own report answers, which are encoded next.
        }
    }

    /**
     * Register the read routes.
     */
    public static function register_routes() {
        self::load();
        $ns   = SEOProStats_Collection::REST_NAMESPACE;
        $base = self::args(false);
        self::share_routes($ns);
        $read = array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => array(__CLASS__, 'can_read'),
        );

        register_rest_route($ns, '/stats', $read + array(
            'callback' => array(__CLASS__, 'stats'),
            'args'     => $base,
        ));
        register_rest_route($ns, '/timeseries', $read + array(
            'callback' => array(__CLASS__, 'timeseries'),
            'args'     => $base + array(
                'grain' => array(
                    'description' => __('Points per hour, day or month; auto picks by the range length.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Query::GRAINS,
                    'default'     => 'auto',
                ),
            ),
        ));
        register_rest_route($ns, '/breakdown', $read + array(
            'callback' => array(__CLASS__, 'breakdown'),
            'args'     => self::args(true),
        ));
        register_rest_route($ns, '/realtime', $read + array(
            'callback' => array(__CLASS__, 'realtime'),
            'args'     => array('data' => $base['data']),
        ));
        $changes = array(
            'page'  => array(
                'description' => __('Only changes to this page (a path such as /pricing/; * for any text), and the site-wide ones (plugins, themes, settings).', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
            'kinds' => array(
                'description' => __('Only these kinds or groups of change (content, seo, product, site, search, note), comma-separated.', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
        register_rest_route($ns, '/markers', $read + array(
            'callback' => array(__CLASS__, 'markers'),
            'args'     => $base + $changes,
        ));
        register_rest_route($ns, '/changes', $read + array(
            'callback' => array(__CLASS__, 'changes'),
            'args'     => $base + $changes + array(
                'limit'  => array('maximum' => SEOProStats_Changes::MAX_LIMIT, 'default' => 50) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));

        $manage = array(__CLASS__, 'can_manage');
        $data   = array('data' => $base['data']);

        // Notes on the timeline (administrators), on the data set asked for.
        register_rest_route($ns, '/annotations', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'annotate'),
            'args'                => $data + array(
                'note' => array(
                    'description' => __('What happened, such as "Newsletter sent" (up to 190 characters).', 'seoprostats'),
                    'type'        => 'string',
                    'required'    => true,
                ),
                'page' => array(
                    'description' => __('The page it is about (a path such as /pricing/); without it, the whole site.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'time' => array(
                    'description' => __('When, in the site time zone (2026-10-05 or 2026-10-05 14:30), or Unix time; without it, now.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
            ),
        ));
        register_rest_route($ns, '/annotations/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'delete_annotation'),
            'args'                => $data,
        ));

        // Goals and funnels: the report, and (administrators) their definitions.
        foreach (array('goals', 'funnels') as $type) {
            $fields = $type === 'goals' ? self::goal_args() : self::funnel_args();
            register_rest_route($ns, '/' . $type, array(
                $read + array(
                    'callback' => array(__CLASS__, $type),
                    'args'     => $base,
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => $manage,
                    'callback'            => array(__CLASS__, 'save_' . $type),
                    'args'                => $data + $fields,
                ),
            ));
            register_rest_route($ns, '/' . $type . '/(?P<id>[a-z0-9]+)', array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'permission_callback' => $manage,
                    'callback'            => array(__CLASS__, 'save_' . $type),
                    'args'                => $data + $fields,
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'permission_callback' => $manage,
                    'callback'            => array(__CLASS__, 'delete_' . $type),
                    'args'                => $data,
                ),
            ));
        }
        register_rest_route($ns, '/properties', $read + array(
            'callback' => array(__CLASS__, 'properties'),
            'args'     => $base + array(
                'key'    => array(
                    'description' => __('Property whose values to list; without it, the property keys are listed.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'event'  => array(
                    'description' => __('Only properties sent with this event (without it: events and pages).', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/clicks', $read + array(
            'callback' => array(__CLASS__, 'clicks'),
            'args'     => $base + array(
                'kind'   => array(
                    'description' => __('Rows: clicked elements, dead clicks only, link destinations, file links, forms sent or pages.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Clicks::KINDS,
                    'default'     => 'elements',
                ),
                'page'   => array(
                    'description' => __('Only clicks on this page (a path such as /pricing/; * for any text).', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        $engine = array(
            'description' => __('Search engine: google (Search Console) or bing (Bing Webmaster Tools).', 'seoprostats'),
            'type'        => 'string',
            'enum'        => array_keys(SEOProStats_Search::ENGINES),
            'default'     => 'google',
        );
        // Rankings, Opportunities and Content can also add up every engine with data.
        $combined = array(
            'description' => __('Search engine: google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up; as the one engine while only one has data).', 'seoprostats'),
            'enum'        => array_merge(array_keys(SEOProStats_Search::ENGINES), array(SEOProStats_Search::ALL)),
        ) + $engine;
        register_rest_route($ns, '/search', $read + array(
            'callback' => array(__CLASS__, 'search'),
            'args'     => $base + array(
                'engine' => $combined,
                'kind'   => array(
                    'description' => __('Rows: search queries, pages, countries, devices, appearance (countries, devices and appearance for the whole site, Google only) or days (the chart\'s points by day, week or month, newest first).', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Search::KINDS,
                    'default'     => 'queries',
                ),
                'page'   => array(
                    'description' => __('Only searches that showed this page (a path such as /pricing/; * for any text).', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'query'  => array(
                    'description' => __('Only this search query (* for any text).', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/opportunities', $read + array(
            'callback' => array(__CLASS__, 'opportunities'),
            'args'     => $base + array(
                'engine' => $combined,
                'kind'   => array(
                    'description' => __('Opportunities: striking (queries at position 4–20 that could reach the top three), ctr (top-10 queries with a CTR well under the site\'s own at that position), decay (pages losing clicks, with the likely cause), missing (top-20 queries whose words the page does not have) or overlap (queries shared by two or more pages, each with at least 10% of the impressions: candidates to review).', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Opportunities::KINDS,
                    'default'     => 'striking',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/audit', $read + array(
            'callback' => array(__CLASS__, 'audit'),
            'args'     => $base + array(
                'engine'  => $engine,
                'finding' => array(
                    'description' => __('Only pages with this finding; all findings when left out.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProStats_Audit::FINDINGS),
                    'default'     => '',
                ),
                'limit'   => array('maximum' => SEOProStats_Audit::MAX_LIMIT, 'default' => SEOProStats_Audit::LIMIT) + self::args(true)['limit'],
                'offset'  => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/links', $read + array(
            'callback' => array(__CLASS__, 'links'),
            'args'     => $base + array(
                'engine' => $engine,
                'kind'   => array(
                    'description' => __('Internal links: orphans (published pages no other page links to), converting (pages that convert from search with few pages linking to them) or missing (a page showing for a search does not link to the page that gets its clicks).', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Links::KINDS,
                    'default'     => 'orphans',
                ),
                'goal'   => array(
                    'description' => __('ID of the goal whose conversions are counted; the first goal when left out.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => array('maximum' => SEOProStats_Links::MAX_LIMIT, 'default' => SEOProStats_Links::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/indexation', $read + array(
            'callback' => array(__CLASS__, 'indexation'),
            'args'     => $base + array(
                'engine' => $engine,
                'kind'   => array(
                    'description' => __('Indexation: pages (published pages with no search impressions in the engine\'s newest days, published before them) or sitemap (other addresses in the site\'s sitemaps, such as category and author archives, with none).', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Indexation::KINDS,
                    'default'     => 'pages',
                ),
                'days'   => array(
                    'description' => __('Days without search impressions, and since publishing or first listing.', 'seoprostats'),
                    'type'        => 'integer',
                    'minimum'     => SEOProStats_Indexation::MIN_DAYS,
                    'maximum'     => SEOProStats_Indexation::MAX_DAYS,
                    'default'     => SEOProStats_Indexation::DAYS,
                ),
                'limit'  => array('maximum' => SEOProStats_Indexation::MAX_LIMIT, 'default' => SEOProStats_Indexation::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/coverage', $read + array(
            'callback' => array(__CLASS__, 'coverage'),
            'args'     => $base + array(
                'page' => array(
                    'description' => __('The page (a path such as /pricing/); or give post.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'post' => array(
                    'description' => __('The post whose address is the page; or give page.', 'seoprostats'),
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                ),
            ),
        ));
        register_rest_route($ns, '/content', $read + array(
            'callback' => array(__CLASS__, 'content'),
            'args'     => $base + array(
                'engine' => $combined,
                'sort'   => array(
                    'description' => __('Order of the pages, most first: search clicks, visits from search, or conversions of the goal.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Content::SORTS,
                    'default'     => 'clicks',
                ),
                'goal'   => array(
                    'description' => __('ID of the goal whose conversions are counted; the first goal when left out.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        self::experiment_routes($read, $manage, $data);
        self::queue_routes($read, $manage, $base, $engine);
        self::target_routes($read, $manage, $base, $engine);
        // Outside data sources (administrators who may change the settings).
        $settings = array(__CLASS__, 'can_change');
        $source   = '/connections/(?P<source>[a-z0-9-]+)';
        register_rest_route($ns, '/connections', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'connections'),
        ));
        register_rest_route($ns, $source, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'connection'),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'connect'),
                'args'                => array(
                    'key'      => array(
                        'description' => __('Search Console: the service account\'s JSON key, as text; Bing: the API key. Without it, the saved key is kept (to change the property or site).', 'seoprostats'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'property' => array(
                        'description' => __('Search Console: the property to import (https://example.com/ or sc-domain:example.com); Bing: the site (https://example.com/). Without it, the one for this site.', 'seoprostats'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'disconnect'),
                'args'                => array(
                    'delete_data' => array(
                        'description' => __('Also delete the data imported from it.', 'seoprostats'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
        ));
        register_rest_route($ns, $source . '/import', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'import_now'),
        ));
        register_rest_route($ns, '/imports/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'undo_import'),
        ));
        self::migrate_routes($settings);

        register_rest_route($ns, '/demo', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => array(__CLASS__, 'can_read'),
                'callback'            => array(__CLASS__, 'demo_status'),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'demo_make'),
                'args'                => array(
                    'days'    => array(
                        'description' => __('Days of demo visits back from today.', 'seoprostats'),
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => SEOProStats_Demo::MAX_DAYS,
                        'default'     => SEOProStats_Demo::DAYS,
                    ),
                    'restart' => array(
                        'description' => __('Remove the demo data and make it again.', 'seoprostats'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'demo_remove'),
            ),
        ));
        register_rest_route($ns, '/view', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'permission_callback' => array(__CLASS__, 'can_read'),
            'callback'            => array(__CLASS__, 'view'),
            'args'                => array('data' => array('required' => true) + array_diff_key($base['data'], array('default' => true))),
        ));
    }

    /**
     * Register the experiment routes: read with view_seoprostats; add,
     * decide and delete with manage_options.
     *
     * @param array<string,mixed>   $read   Read route base.
     * @param callable              $manage Write permission callback.
     * @param array<string,mixed>   $data   The data argument.
     */
    private static function experiment_routes(array $read, $manage, array $data) {
        $ns = SEOProStats_Collection::REST_NAMESPACE;
        register_rest_route($ns, '/experiments', array(
            $read + array(
                'callback' => array(__CLASS__, 'experiments'),
                'args'     => $data + array(
                    'status' => array(
                        'description' => __('Only experiments in this state: running, due (running, with data through the review day), decided or cancelled.', 'seoprostats'),
                        'type'        => 'string',
                        'enum'        => array('', 'running', 'due', 'decided', 'cancelled'),
                        'default'     => '',
                    ),
                    'page'   => array(
                        'description' => __('Only experiments on this page (a path such as /pricing/).', 'seoprostats'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'experiment_add'),
                'args'                => $data + self::experiment_args(),
            ),
        ));
        register_rest_route($ns, '/experiments/(?P<id>\d+)', array(
            $read + array(
                'callback' => array(__CLASS__, 'experiment'),
                'args'     => $data,
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'experiment_update'),
                'args'                => $data + array(
                    'action' => array(
                        'description' => __('decide (with result), note (change the note) or cancel.', 'seoprostats'),
                        'type'        => 'string',
                        'enum'        => array('decide', 'note', 'cancel'),
                        'required'    => true,
                    ),
                    'result' => array(
                        'description' => __('For decide: keep, revise, undo or inconclusive.', 'seoprostats'),
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), array_values(SEOProStats_Experiments::RESULTS)),
                        'default'     => '',
                    ),
                    'note'   => array(
                        'description' => __('Why, or what was learnt (up to 2,000 characters).', 'seoprostats'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'experiment_delete'),
                'args'                => $data,
            ),
        ));
    }

    /**
     * Register the decision queue routes: read with view_seoprostats; act
     * on an item with manage_options. Both take the period the list is
     * made from (range, from, to, page filters), the engine and the goal,
     * as does the loop export (GET /loop), which carries the queue.
     *
     * @param array<string,mixed> $read   Read route base.
     * @param callable            $manage Write permission callback.
     * @param array<string,mixed> $base   Report arguments.
     * @param array<string,mixed> $engine The engine argument.
     */
    private static function queue_routes(array $read, $manage, array $base, array $engine) {
        $ns   = SEOProStats_Collection::REST_NAMESPACE;
        $list = array_diff_key($base, array('compare' => true)) + array(
            'engine' => $engine,
            'goal'   => array(
                'description' => __('ID of the goal whose conversions give a page its value; the first goal when left out.', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
        $list['range'] = array('default' => '90d') + $list['range'];
        register_rest_route($ns, '/queue', $read + array(
            'callback' => array(__CLASS__, 'queue'),
            'args'     => $list + array(
                'status' => array(
                    'description' => __('Items in this state: open (new and accepted), new, accepted, done, dismissed (in the last 90 days) or all.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Queue::FILTERS,
                    'default'     => 'open',
                ),
                'kind'   => array(
                    'description' => __('Only items of this kind, e.g. refresh for the refresh planner\'s proposals; every kind when left out.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), array_values(SEOProStats_Queue::KINDS)),
                    'default'     => '',
                ),
                'limit'  => array('maximum' => SEOProStats_Queue::MAX_LIMIT, 'default' => SEOProStats_Queue::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/queue/(?P<key>[0-9a-fA-F]{16})', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'queue_update'),
            'args'                => $list + array(
                'action'    => array(
                    'description' => __('accept, done (opens an experiment on the page with the kind\'s measure), dismiss (hidden for 90 days), restore (forget what was done with it), effort or note.', 'seoprostats'),
                    'type'        => 'string',
                    'enum'        => SEOProStats_Queue::ACTIONS,
                    'required'    => true,
                ),
                'effort'    => array(
                    'description' => __('For effort: 1 (least) to 5.', 'seoprostats'),
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => SEOProStats_Queue::MAX_EFFORT,
                ),
                'note'      => array(
                    'description' => __('A note (up to 190 characters).', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'name'      => array(
                    'description' => __('For done: the experiment\'s name; without it, one made from the item.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'days'      => array(
                    'description' => __('For done: days in each window of the experiment.', 'seoprostats'),
                    'type'        => 'integer',
                    'enum'        => SEOProStats_Experiments::WINDOWS,
                    'default'     => SEOProStats_Experiments::DAYS,
                ),
                'threshold' => array(
                    'description' => __('For done: the smallest change that counts: percent (default 10), or places for position (default 1).', 'seoprostats'),
                    'type'        => 'number',
                    'minimum'     => 0,
                ),
            ),
        ));
        $loop          = $list;
        $loop['range'] = array('default' => SEOProStats_Loop::RANGE) + $list['range'];
        register_rest_route($ns, '/loop', $read + array(
            'callback' => array(__CLASS__, 'loop'),
            'args'     => $loop + array(
                'limit' => array(
                    'description' => __('Most queue items (open: new and accepted), best first.', 'seoprostats'),
                    'maximum'     => SEOProStats_Loop::MAX_ITEMS,
                    'default'     => SEOProStats_Loop::ITEMS,
                ) + self::args(true)['limit'],
                'rows'  => array(
                    'description' => __('Most export rows (a search and a page each), most impressions first.', 'seoprostats'),
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => SEOProStats_Loop::MAX_ROWS,
                    'default'     => SEOProStats_Loop::ROWS,
                ),
            ),
        ));
    }

    /**
     * Register the search target routes: the report with view_seoprostats;
     * import and delete with manage_options.
     *
     * @param array<string,mixed> $read   Read route base.
     * @param callable            $manage Write permission callback.
     * @param array<string,mixed> $base   Report arguments.
     * @param array<string,mixed> $engine The engine argument.
     */
    private static function target_routes(array $read, $manage, array $base, array $engine) {
        $ns   = SEOProStats_Collection::REST_NAMESPACE;
        $list = $base + array('engine' => $engine);
        $list['range'] = array('default' => '30d') + $list['range'];
        register_rest_route($ns, '/targets', array(
            $read + array(
                'callback' => array(__CLASS__, 'targets'),
                'args'     => $list + array(
                    'status' => array(
                        'description' => __('Targets in this status: all, open (candidate, targeted and live), candidate, targeted, live, won or retired.', 'seoprostats'),
                        'type'        => 'string',
                        'enum'        => SEOProStats_Targets::FILTERS,
                        'default'     => 'all',
                    ),
                    'limit'  => array('maximum' => SEOProStats_Targets::MAX_LIMIT, 'default' => SEOProStats_Targets::LIMIT) + self::args(true)['limit'],
                    'offset' => self::args(true)['offset'],
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'targets_import'),
                'args'                => array('data' => $base['data']) + array(
                    'targets' => array(
                        'description' => __('Targets as a list of objects: query (or phrase), page (a path such as /pricing/ or an address on this site; or target_url), priority (0–100, or high, medium, low; 50 when left out) and status (candidate, targeted, live, won or retired; targeted when left out). Or give text.', 'seoprostats'),
                        'type'        => 'array',
                        'items'       => array('type' => 'object'),
                    ),
                    'text'    => array(
                        'description' => __('Targets as text: CSV or tab-separated (a header row naming query, page, priority and status, or those columns in that order), JSON, or the aidevops search targets table (TOON). Or give targets.', 'seoprostats'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'replace' => array(
                        'description' => __('Delete the targets that are not in this import.', 'seoprostats'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'targets_delete'),
                'args'                => array('data' => $base['data']) + array(
                    'queries' => array(
                        'description' => __('The searches whose targets to delete.', 'seoprostats'),
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'default'     => array(),
                    ),
                    'all'     => array(
                        'description' => __('Delete every target.', 'seoprostats'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
        ));
    }

    /**
     * Fields of a new experiment.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function experiment_args() {
        return array(
            'name'       => array(
                'description' => __('The change and what it should do, in one line (up to 190 characters).', 'seoprostats'),
                'type'        => 'string',
                'required'    => true,
            ),
            'change'     => array(
                'description' => __('The change it measures (its id in /changes): its time is the start, its page the page unless page is given.', 'seoprostats'),
                'type'        => 'integer',
                'minimum'     => 0,
                'default'     => 0,
            ),
            'start'      => array(
                'description' => __('Without change: when the change was made, in the site time zone (2026-10-05 or 2026-10-05 14:30) or Unix time; without it, now.', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
            'page'       => array(
                'description' => __('Its page (a path such as /pricing/), or several, comma-separated (up to 50).', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
            'days'       => array(
                'description' => __('Days in each window, before and after.', 'seoprostats'),
                'type'        => 'integer',
                'enum'        => SEOProStats_Experiments::WINDOWS,
                'default'     => SEOProStats_Experiments::DAYS,
            ),
            'engine'     => array(
                'description' => __('Search engine whose data measures it: google or bing.', 'seoprostats'),
                'type'        => 'string',
                'enum'        => array_keys(SEOProStats_Search::ENGINES),
                'default'     => 'google',
            ),
            'metric'     => array(
                'description' => __('What it should change: clicks, impressions, ctr, position, visits (from search) or conversions (of goal).', 'seoprostats'),
                'type'        => 'string',
                'enum'        => array_values(SEOProStats_Experiments::METRICS),
                'default'     => 'clicks',
            ),
            'direction'  => array(
                'description' => __('Expected direction: up or down (for position, up means a better place).', 'seoprostats'),
                'type'        => 'string',
                'enum'        => array_values(SEOProStats_Experiments::DIRECTIONS),
                'default'     => 'up',
            ),
            'threshold'  => array(
                'description' => __('The smallest change that counts: percent for counts and CTR (default 10), places for position (default 1).', 'seoprostats'),
                'type'        => 'number',
                'minimum'     => 0,
            ),
            'goal'       => array(
                'description' => __('For conversions: the goal\'s id.', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
            'hypothesis' => array(
                'description' => __('The reasoning, in more words (up to 2,000 characters).', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
            'note'       => array(
                'description' => __('A note (up to 2,000 characters).', 'seoprostats'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
    }

    /**
     * Whether the current user may make and remove demo data.
     *
     * @return bool
     */
    public static function can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * Whether the current user may change the settings (connections).
     *
     * @return bool
     */
    public static function can_change() {
        return SEOProStats_Settings::can_change();
    }

    /**
     * Load the connection classes.
     */
    private static function load_connections() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-connections.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
    }

    /**
     * The source of a /connections/{source} request, or an error.
     *
     * @param WP_REST_Request $request Request.
     * @return string|WP_Error
     */
    private static function source($request) {
        self::load_connections();
        $source = (string) $request->get_param('source');
        if (!SEOProStats_Connections::source_class($source)) {
            return new WP_Error('seoprostats_source_unknown', __('Unknown source.', 'seoprostats'), array('status' => 404));
        }
        return $source;
    }

    /**
     * GET /connections: every source's status. Never credentials.
     *
     * @return WP_REST_Response
     */
    public static function connections() {
        self::load_connections();
        return rest_ensure_response(array('sources' => SEOProStats_Connections::statuses()));
    }

    /**
     * GET /connections/{source}: its status.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function connection($request) {
        $source = self::source($request);
        return is_wp_error($source) ? $source : rest_ensure_response(SEOProStats_Connections::status($source));
    }

    /**
     * POST /connections/{source}: connect it, or change its property. The
     * import starts in cron a few seconds later.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function connect($request) {
        $source = self::source($request);
        if (is_wp_error($source)) {
            return $source;
        }
        $status = SEOProStats_Connections::connect($source, array(
            'key'      => (string) $request->get_param('key'),
            'property' => (string) $request->get_param('property'),
        ));
        return self::with_status($status, 400);
    }

    /**
     * DELETE /connections/{source}: forget its credentials, and its data
     * when asked.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function disconnect($request) {
        $source = self::source($request);
        if (is_wp_error($source)) {
            return $source;
        }
        return self::with_status(SEOProStats_Connections::disconnect($source, (bool) $request->get_param('delete_data')), 400);
    }

    /**
     * POST /connections/{source}/import: import now, for up to
     * SEOProStats_Search_Import::BUDGET seconds; the job carries on.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function import_now($request) {
        $source = self::source($request);
        if (is_wp_error($source)) {
            return $source;
        }
        if (!SEOProStats_Connections::get($source)) {
            return new WP_Error('seoprostats_not_connected', __('This source is not connected.', 'seoprostats'), array('status' => 400));
        }
        $result = SEOProStats_Search_Import::run($source, SEOProStats_Search_Import::BUDGET, true);
        if (is_wp_error($result)) {
            return self::with_status($result, $result->get_error_code() === 'seoprostats_import_busy' ? 409 : 502);
        }
        if (!$result['done']) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, SEOProStats_Search_Import::HOOK, array('more'));
        }
        return rest_ensure_response(array('run' => $result) + SEOProStats_Connections::status($source));
    }

    /**
     * DELETE /imports/{id}: undo an import (delete the rows it wrote).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function undo_import($request) {
        global $wpdb;
        self::load_connections();
        if (!SEOProStats_Schema::is_current()) {
            return new WP_Error('seoprostats_tables', __('The statistics tables are being updated. Try again after visiting wp-admin.', 'seoprostats'), array('status' => 503));
        }
        $id = (int) $request->get_param('id');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $source = (string) $wpdb->get_var($wpdb->prepare('SELECT source FROM %i WHERE id = %d', SEOProStats_Schema::table('imports'), $id));
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        // Imports from other statistics plugins undo through SEOProStats_Migrate.
        $deleted = SEOProStats_Migrate::owns($source) ? SEOProStats_Migrate::undo($id) : SEOProStats_Search_Import::undo($id);
        if (is_wp_error($deleted)) {
            return self::with_status($deleted, 404);
        }
        return rest_ensure_response(array('id' => $id, 'deleted' => $deleted));
    }

    /**
     * Routes of imports from other statistics plugins (SEOProStats_Migrate),
     * for administrators who may change the settings.
     *
     * @param callable $settings Permission callback.
     */
    private static function migrate_routes($settings) {
        $ns     = SEOProStats_Collection::REST_NAMESPACE;
        $source = '/migrate/(?P<source>[a-z0-9-]+)';
        register_rest_route($ns, '/migrate', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'migrate_status'),
            'args'                => array(
                'fresh' => array(
                    'description' => __('Look for statistics plugins again instead of the list kept for ten minutes.', 'seoprostats'),
                    'type'        => 'boolean',
                    'default'     => false,
                ),
            ),
        ));
        register_rest_route($ns, $source, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'migrate_run'),
            'args'                => array(
                'dry_run' => array(
                    'description' => __('Only say what the import would do: days, rows, overlap with other plugins and SEO Pro Stats\'s own days, and settings. Writes nothing.', 'seoprostats'),
                    'type'        => 'boolean',
                    'default'     => false,
                ),
                'prefer'  => array(
                    'description' => __('When another plugin not imported yet has statistics on the same days: the one whose counts fill those days. It imports first.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'from'    => array(
                    'description' => __('First day to import (YYYY-MM-DD); default: its first day.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'to'      => array(
                    'description' => __('Last day to import (YYYY-MM-DD); default: its last day.', 'seoprostats'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'settings' => array(
                    'description' => __('The settings to carry over, by key (the dry run lists them); an empty list carries none. Default: every one the dry run would change.', 'seoprostats'),
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                ),
            ),
        ));
        register_rest_route($ns, $source . '/cleanup', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'migrate_cleanup'),
            'args'                => array(
                'dry_run' => array(
                    'description' => __('Only list what it left behind (default). false deletes exactly that list; it cannot be undone.', 'seoprostats'),
                    'type'        => 'boolean',
                    'default'     => true,
                ),
            ),
        ));
    }

    /**
     * GET /migrate: statistics plugins found, the import job and the
     * imports so far. While an import runs, each read moves it on for a
     * few seconds (the Import tab polls), as well as cron.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function migrate_status($request) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        SEOProStats_Migrate::nudge();
        return rest_ensure_response(SEOProStats_Migrate::status((bool) $request->get_param('fresh')));
    }

    /**
     * POST /migrate/{source}: the dry run, or start an import (cron and
     * GET /migrate carry it on).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function migrate_run($request) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        $source = (string) $request->get_param('source');
        $args   = array(
            'from'   => (string) $request->get_param('from'),
            'to'     => (string) $request->get_param('to'),
            'prefer' => (string) $request->get_param('prefer'),
        );
        if ($request->has_param('settings') && is_array($request->get_param('settings'))) {
            $args['settings'] = array_map('strval', $request->get_param('settings'));
        }
        if ($request->get_param('dry_run')) {
            return self::with_status(SEOProStats_Migrate::plan($source, $args), 400);
        }
        $job = SEOProStats_Migrate::start($source, $args);
        return is_wp_error($job) ? self::with_status($job, 400) : rest_ensure_response(array('job' => $job));
    }

    /**
     * POST /migrate/{source}/cleanup: list, or remove, what a plugin left
     * behind; refused while it is active. People only: no ability does it.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function migrate_cleanup($request) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        return self::with_status(SEOProStats_Migrate::cleanup((string) $request->get_param('source'), (bool) $request->get_param('dry_run')), 400);
    }

    /**
     * An answer, or its error with an HTTP status.
     *
     * @param mixed    $answer Answer or WP_Error.
     * @param int      $status Status for an error without one.
     * @return WP_REST_Response|WP_Error
     */
    private static function with_status($answer, $status) {
        if (!is_wp_error($answer)) {
            return rest_ensure_response($answer);
        }
        $data = $answer->get_error_data();
        $data = is_array($data) ? $data : array();
        // Google's own status (401, 403) is not this request's.
        if (empty($data['status']) || strpos((string) $answer->get_error_code(), 'seoprostats_google_') === 0) {
            $data['status'] = $status;
            // Only when changed: add_data() keeps the old data in additional_data.
            $answer->add_data($data);
        }
        return $answer;
    }

    /**
     * GET /demo: whether demo data is made.
     *
     * @return WP_REST_Response
     */
    public static function demo_status() {
        return rest_ensure_response(SEOProStats_Demo::status() + array('viewing' => SEOProStats_Demo::viewing()));
    }

    /**
     * POST /demo: start making demo data, or carry on; one call does up to
     * SEOProStats_Demo::BUDGET seconds of work. Call again until ready.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function demo_make($request) {
        $status = SEOProStats_Demo::status();
        if ($status['status'] === 'none' || $request->get_param('restart')) {
            if (!SEOProStats_Demo::start((int) $request->get_param('days'))) {
                return new WP_Error('seoprostats_demo_tables', __('The demo tables could not be made.', 'seoprostats'), array('status' => 500));
            }
        }
        return rest_ensure_response(SEOProStats_Demo::step() + array('viewing' => SEOProStats_Demo::viewing()));
    }

    /**
     * DELETE /demo: remove the demo tables. Live data is untouched.
     *
     * @return WP_REST_Response
     */
    public static function demo_remove() {
        SEOProStats_Demo::remove();
        return rest_ensure_response(SEOProStats_Demo::status() + array('viewing' => SEOProStats_Demo::viewing()));
    }

    /**
     * POST /view: the data set the current user sees on the screens.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function view($request) {
        $data = $request->get_param('data') === 'demo' ? 'demo' : 'live';
        if ($data === 'demo') {
            update_user_meta(get_current_user_id(), SEOProStats_Demo::USER_META, 'demo');
        } else {
            delete_user_meta(get_current_user_id(), SEOProStats_Demo::USER_META);
        }
        return rest_ensure_response(array('data' => $data));
    }

    /**
     * Whether the current user may read statistics.
     *
     * @return bool
     */
    public static function can_read() {
        return current_user_can(self::CAP);
    }

    /**
     * Separate public routes: share credentials never authorize normal routes.
     *
     * @param non-falsy-string $ns REST namespace.
     */
    private static function share_routes($ns) {
        $manage = array(__CLASS__, 'can_manage');
        register_rest_route($ns, '/shares', array(
            array('methods' => 'GET', 'permission_callback' => $manage, 'callback' => static function () {
                $shares   = array_values(array_map(array('SEOProStats_Shares', 'summary'), SEOProStats_Shares::all()));
                $defaults = SEOProStats_Shares::branding(array());
                // Logo previews for the editor: id => image address.
                $logos = array();
                foreach (array_merge(array($defaults), array_column($shares, 'branding')) as $brand) {
                    foreach (array('logo', 'agency_logo') as $key) {
                        $id = (int) ($brand[$key] ?? 0);
                        if ($id && !isset($logos[$id])) {
                            $logos[$id] = SEOProStats_Shares::local_logo($id);
                        }
                    }
                }
                // Search sections: one for each engine with data. A new report starts with the sections that have data.
                return array(
                    'shares'   => $shares,
                    'defaults' => $defaults,
                    'logos'    => (object) array_filter($logos),
                    'engines'  => SEOProStats_Search::engines(),
                    'sections' => SEOProStats_Shares::sections_with_data(),
                );
            }),
            array('methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProStats_Shares::save((array) $request->get_json_params());
            }),
        ));
        register_rest_route($ns, '/shares/(?P<id>[a-zA-Z0-9]+)', array(
            array('methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProStats_Shares::save((array) $request->get_json_params(), $request['id']);
            }),
            array('methods' => 'DELETE', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProStats_Shares::revoke($request['id']);
            }),
        ));
        register_rest_route($ns, '/shares/(?P<id>[a-zA-Z0-9]+)/renew', array(
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProStats_Shares::revoke($request['id'], true);
            },
        ));
        register_rest_route($ns, '/share/(?P<token>[a-f0-9]{32})', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'share_open'),
            'args' => array('password' => array('type' => 'string', 'maxLength' => 256, 'default' => '')),
        ));
        // Each read's own arguments are checked against its normal route (share_report()).
        register_rest_route($ns, '/share/(?P<token>[a-f0-9]{32})/(?P<section>' . implode('|', SEOProStats_Shares::SECTIONS) . ')/(?P<report>' . implode('|', array_unique(array_merge(...array_values(SEOProStats_Shares::REPORTS)))) . ')', array(
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'share_report'),
            'args' => self::args(false) + array(
                'page' => array('type' => 'string', 'maxLength' => 2048),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                'offset' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 1000),
            ),
        ));
        add_filter('rest_post_dispatch', array(__CLASS__, 'share_headers'), 10, 3);
    }

    /**
     * Sensitive answers and errors must never enter a public cache.
     *
     * @param WP_REST_Response $response Response.
     * @param WP_REST_Server   $server   Server.
     * @param WP_REST_Request  $request  Request.
     * @return WP_REST_Response
     */
    public static function share_headers($response, $server, $request) {
        unset($server);
        if (strpos($request->get_route(), '/seoprostats/v1/share') === 0) {
            $response->header('Cache-Control', 'private, no-store');
            $response->header('X-Robots-Tag', 'noindex, nofollow');
            $response->header('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }

    /**
     * Unlock and open; uniform failures for token, expiry and password.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function share_open($request) {
        $share = SEOProStats_Shares::find((string) $request['token']);
        if (is_wp_error($share)) {
            return $share;
        }
        if (!SEOProStats_Shares::quota($share, 'passwords', 5)) {
            return SEOProStats_Shares::denied();
        }
        $grant = (string) $request->get_header('X-Seoprostats-Unlock');
        if (!SEOProStats_Shares::unlocked($share, $grant) && !wp_check_password((string) $request->get_param('password'), $share['password_hash'])) {
            return SEOProStats_Shares::denied();
        }
        $share = SEOProStats_Shares::opened($share);
        if (is_wp_error($share)) {
            return $share;
        }
        $answer = SEOProStats_Shares::summary($share);
        unset($answer['id'], $answer['created'], $answer['opens'], $answer['last_opened'], $answer['revoked']);
        $answer['unlock'] = SEOProStats_Shares::grant($share, time() + HOUR_IN_SECONDS);
        $answer['home'] = home_url('/');
        $answer['site_name'] = get_bloginfo('name');
        $logo = $share['branding']['logo'] ?: (int) get_theme_mod('custom_logo');
        $answer['logo_url'] = SEOProStats_Shares::local_logo($logo) ?: SEOProStats_Shares::local_logo((int) get_option('site_icon'));
        $answer['agency_logo_url'] = SEOProStats_Shares::local_logo($share['branding']['agency_logo']);
        // Breakdowns this report leaves out: the reader shows no tab for them, rather than an empty one.
        $answer['hidden_dimensions'] = $share['hide_sensitive'] ? SEOProStats_Shares::SENSITIVE_DIMENSIONS : array();
        return $answer;
    }

    /**
     * Public reports are rebuilt from safe inputs, never an admin request.
     * Filters are ANDed, comparison periods obey the same date boundary.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function share_report($request) {
        $share = SEOProStats_Shares::find((string) $request['token']);
        if (is_wp_error($share) || !SEOProStats_Shares::unlocked($share, (string) $request->get_header('X-Seoprostats-Unlock'))) {
            return SEOProStats_Shares::denied();
        }
        $section = (string) $request['section'];
        $report  = (string) $request['report'];
        if (!in_array($section, array_column($share['views'], 'view'), true) || !in_array($report, SEOProStats_Shares::REPORTS[$section], true) || !SEOProStats_Shares::quota($share, 'reads', 120)) {
            return SEOProStats_Shares::denied();
        }
        if (in_array($section, SEOProStats_Shares::PAGE_ONLY, true) && !SEOProStats_Shares::page_locks_only($share['locked_filters'])) {
            return SEOProStats_Shares::denied();
        }
        $args = array_intersect_key($request->get_query_params(), array_flip(array('range', 'from', 'to', 'compare', 'grain', 'filters', 'dimension', 'limit', 'offset', 'page', 'kind', 'engine', 'query', 'key', 'event', 'kinds', 'sort', 'goal', 'finding', 'days')));
        $filters = SEOProStats_Shares::filters($args['filters'] ?? array());
        if (is_wp_error($filters)) {
            return $filters;
        }
        $args['filters'] = array_merge($filters, $share['locked_filters']);
        $args['data'] = 'live';
        if (isset($args['limit'])) {
            $args['limit'] = min(100, max(1, (int) $args['limit']));
        }
        $args['offset'] = min(1000, max(0, (int) ($args['offset'] ?? 0)));
        // The read's own route checks its arguments, as when an administrator asks.
        $routes  = rest_get_server()->get_routes('seoprostats/v1');
        $handler = $routes['/seoprostats/v1/' . $report][0] ?? null;
        if (!$handler) {
            return SEOProStats_Shares::denied();
        }
        $safe = new WP_REST_Request('GET', '/seoprostats/v1/' . $report);
        $safe->set_query_params($args);
        $safe->set_attributes(array('args' => $handler['args']));
        $defaults = array();
        foreach ($handler['args'] as $name => $spec) {
            if (is_array($spec) && array_key_exists('default', $spec)) {
                $defaults[$name] = $spec['default'];
            }
        }
        $safe->set_default_params($defaults);
        $checked = $safe->has_valid_params();
        if (is_wp_error($checked)) {
            return $checked;
        }
        $checked = $safe->sanitize_params();
        if (is_wp_error($checked)) {
            return $checked;
        }
        // A Search section is one engine's.
        if ($section === 'search' && $report !== 'markers') {
            $engines = array();
            foreach ($share['views'] as $view) {
                if ($view['view'] === 'search') {
                    $engines[] = $view['engine'] ?? 'google';
                }
            }
            if (!in_array((string) ($safe->get_param('engine') ?: 'google'), $engines, true)) {
                return SEOProStats_Shares::denied();
            }
        }
        $req = SEOProStats_Query::request(array_merge($args, array('limit' => min(100, (int) ($safe->get_param('limit') ?: 10)))));
        if (is_wp_error($req)) {
            return $req;
        }
        if ($share['max_days']) {
            $floor = (new DateTimeImmutable('today', wp_timezone()))->modify('-' . ($share['max_days'] - 1) . ' days')->getTimestamp();
            $range = SEOProStats_Query::range($req);
            $other = SEOProStats_Query::compare_range($range, $req['compare']);
            if ($range['from'] < $floor || ($other && $other['from'] < $floor)) {
                return SEOProStats_Shares::denied();
            }
        }
        if ($report === 'realtime' && ($share['hide_realtime'] || $share['locked_filters'])) {
            return rest_ensure_response(array('visitors' => 0));
        }
        if ($report === 'breakdown' && $share['hide_sensitive'] && in_array($req['dimension'], SEOProStats_Shares::SENSITIVE_DIMENSIONS, true)) {
            return rest_ensure_response(array('dimension' => $req['dimension'], 'rows' => array(), 'total' => 0, 'range' => SEOProStats_Query::range_out(SEOProStats_Query::range($req))));
        }
        if ($report === 'markers' || $report === 'changes') {
            return self::share_changes($req, $share, $report, (string) $safe->get_param('page'), (string) $safe->get_param('kinds'), (int) $safe->get_param('limit'), (int) $safe->get_param('offset'));
        }
        $answer = call_user_func(array(__CLASS__, $report), $safe);
        if (is_wp_error($answer)) {
            return $answer;
        }
        $data = self::share_redact($answer->get_data());
        if ($report === 'realtime' && $share['hide_sensitive']) {
            $data['sources'] = array();
        }
        $answer->set_data($data);
        return $answer;
    }

    /**
     * The timeline (markers, oldest first) or the change log (changes,
     * newest first, a page of it) as a minimal projection: never users,
     * notes, settings, plugin or theme names, or before and after values.
     * Visit-level locks cannot scope site changes, so then there are none.
     *
     * @param array  $req    Checked report request.
     * @param array  $share  Share.
     * @param string $report markers or changes.
     * @param string $page   Only this page's (and site-wide) changes.
     * @param string $kinds  Change log: only these groups (comma-separated).
     * @param int    $limit  Change log: most rows.
     * @param int    $offset Change log: rows to skip.
     * @return WP_REST_Response|WP_Error
     */
    private static function share_changes(array $req, array $share, $report, $page, $kinds, $limit, $offset) {
        $list   = $report === 'markers' ? 'markers' : 'changes';
        $answer = array('range' => SEOProStats_Query::range_out(SEOProStats_Query::range($req)), $list => array(), 'total' => 0);
        if ($list === 'changes') {
            $answer += array('limit' => $limit, 'offset' => $offset);
        }
        if (!SEOProStats_Shares::page_locks_only($share['locked_filters'])) {
            return rest_ensure_response($answer);
        }
        $changes = SEOProStats_Changes::list_changes($req, array('page' => $page, 'kinds' => $kinds, 'limit' => SEOProStats_Changes::MAX_LIMIT, 'order' => $list === 'markers' ? 'asc' : 'desc', 'running' => $list === 'markers'));
        if (is_wp_error($changes)) {
            return $changes;
        }
        $rows = array();
        foreach ($changes['changes'] as $change) {
            if ($change['group'] === 'note') {
                continue;
            }
            $allowed = true;
            foreach ($share['locked_filters'] as $filter) {
                if (!$change['path']) {
                    $allowed = $change['group'] === 'search';
                    continue;
                }
                $matches = false;
                foreach ($filter['values'] as $value) {
                    if ($filter['op'] === 'matches') {
                        $pattern = '/^' . str_replace('\\*', '.*', preg_quote($value, '/')) . '$/D';
                        $matches = $matches || (bool) preg_match($pattern, $change['path']);
                    } elseif ($filter['op'] === 'contains') {
                        $matches = $matches || strpos($change['path'], $value) !== false;
                    } else {
                        $matches = $matches || $change['path'] === $value;
                    }
                }
                $allowed = $allowed && ($filter['op'] === 'is_not' ? !$matches : $matches);
            }
            if ($allowed) {
                $safe = array_intersect_key($change, array_flip(array('id', 't', 'kind', 'group', 'path', 'source')));
                $safe['label'] = ucfirst(str_replace('_', ' ', $change['kind']));
                // A published page's or product's name, or a search engine update's: public already.
                $safe['title'] = ($change['path'] && $change['group'] !== 'site') || $change['group'] === 'search' ? (string) ($change['title'] ?? '') : '';
                $safe['old'] = '';
                $safe['new'] = '';
                $safe['object'] = array('type' => $change['group'] === 'search' ? (string) ($change['object']['type'] ?? '') : '', 'id' => 0);
                $safe['user'] = null;
                // A search engine update's name, engine, announcement and end (it rolls out over a span).
                $safe['meta'] = $change['group'] === 'search' ? array_intersect_key((array) ($change['meta'] ?? array()), array_flip(array('name', 'engine', 'url', 'ended'))) : array();
                $rows[] = $safe;
            }
        }
        $answer['total'] = count($rows);
        $answer[$list] = $list === 'markers' ? $rows : array_slice($rows, $offset, $limit);
        return rest_ensure_response($answer);
    }

    /**
     * Strip editor links even when opened by a signed-in administrator.
     *
     * @param mixed $value Answer.
     * @return mixed
     */
    private static function share_redact($value) {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => &$item) {
            $item = $key === 'edit_url' ? null : self::share_redact($item);
        }
        return $value;
    }

    /**
     * GET /stats.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function stats($request) {
        return self::answer($request, 'stats');
    }

    /**
     * GET /timeseries.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function timeseries($request) {
        return self::answer($request, 'timeseries');
    }

    /**
     * GET /breakdown.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function breakdown($request) {
        return self::answer($request, 'breakdown');
    }

    /**
     * GET /realtime.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function realtime($request) {
        $answer = self::on_data((string) $request->get_param('data'), array('SEOProStats_Query', 'realtime'));
        if (is_wp_error($answer)) {
            return $answer;
        }
        self::short_floats();
        return rest_ensure_response($answer);
    }

    /**
     * GET /markers: the changes in the range, oldest first, for the
     * timeline (at most SEOProStats_Changes::MAX_LIMIT), with search
     * engine updates that started earlier and were still rolling out.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function markers($request) {
        $args = array(
            'page'    => (string) $request->get_param('page'),
            'kinds'   => (string) $request->get_param('kinds'),
            'limit'   => SEOProStats_Changes::MAX_LIMIT,
            'order'   => 'asc',
            'running' => true,
        );
        return self::report($request, static function ($req) use ($args) {
            $answer = SEOProStats_Changes::list_changes($req, $args);
            if (is_wp_error($answer)) {
                return $answer;
            }
            return array(
                'range'   => $answer['range'],
                'markers' => $answer['changes'],
                'total'   => $answer['total'],
            );
        });
    }

    /**
     * GET /changes: the change log for the range, newest first, with paging.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function changes($request) {
        $args = array(
            'page'   => (string) $request->get_param('page'),
            'kinds'  => (string) $request->get_param('kinds'),
            'limit'  => (int) $request->get_param('limit'),
            'offset' => (int) $request->get_param('offset'),
        );
        return self::report($request, static function ($req) use ($args) {
            $answer = SEOProStats_Changes::list_changes($req, $args);
            if (is_wp_error($answer)) {
                return $answer;
            }
            return $answer + array('limit' => $args['limit'], 'offset' => $args['offset']);
        });
    }

    /**
     * GET /goals: every goal's conversions and revenue.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function goals($request) {
        return self::report($request, static function ($req) {
            $answer = SEOProStats_Conversions::goals($req);
            $answer['renewals'] = SEOProStats_Purchases::renewals($req);
            return $answer;
        });
    }

    /**
     * GET /funnels: every funnel's visits per step.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function funnels($request) {
        return self::report($request, array('SEOProStats_Conversions', 'funnels'));
    }

    /**
     * GET /properties: property keys, or one key's values.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function properties($request) {
        $key   = (string) $request->get_param('key');
        $event = (string) $request->get_param('event');
        return self::report($request, static function ($req) use ($key, $event) {
            return SEOProStats_Conversions::properties($req, $key, $event);
        });
    }

    /**
     * GET /clicks: clicked elements, dead clicks, links, files or forms.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function clicks($request) {
        $kind = (string) $request->get_param('kind');
        $page = (string) $request->get_param('page');
        return self::report($request, static function ($req) use ($kind, $page) {
            return SEOProStats_Clicks::report($req, $kind, $page);
        });
    }

    /**
     * GET /search: search clicks, impressions, CTR and position, with
     * queries, pages, countries or devices; optionally of a page or query.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function search($request) {
        $kind   = (string) $request->get_param('kind');
        $page   = (string) $request->get_param('page');
        $query  = (string) $request->get_param('query');
        $engine = (string) $request->get_param('engine');
        return self::report($request, static function ($req) use ($kind, $page, $query, $engine) {
            return SEOProStats_Search::report($req, $kind, $page, $query, $engine);
        });
    }

    /**
     * GET /opportunities: striking-distance queries, low-CTR queries,
     * pages losing clicks with the likely cause, queries missing from their
     * page, or queries shared by several pages.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function opportunities($request) {
        $kind   = (string) $request->get_param('kind');
        $engine = (string) $request->get_param('engine');
        return self::report($request, static function ($req) use ($kind, $engine) {
            return SEOProStats_Opportunities::report($req, $kind, $engine);
        });
    }

    /**
     * GET /audit: published pages with findings from their WordPress
     * content and SEO fields, most search impressions first.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function audit($request) {
        $engine  = (string) $request->get_param('engine');
        $finding = (string) $request->get_param('finding');
        return self::report($request, static function ($req) use ($engine, $finding) {
            return SEOProStats_Audit::report($req, $engine, $finding);
        });
    }

    /**
     * GET /links: orphan pages, converting pages with few links in, or
     * links missing between pages that share a search.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function links($request) {
        $engine = (string) $request->get_param('engine');
        $kind   = (string) $request->get_param('kind');
        $goal   = (string) $request->get_param('goal');
        return self::report($request, static function ($req) use ($engine, $kind, $goal) {
            return SEOProStats_Links::report($req, $engine, $kind, $goal);
        });
    }

    /**
     * GET /indexation: published pages and sitemap addresses search has
     * not shown in its newest days.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function indexation($request) {
        $engine = (string) $request->get_param('engine');
        $kind   = (string) $request->get_param('kind');
        $days   = (int) $request->get_param('days');
        return self::report($request, static function ($req) use ($engine, $kind, $days) {
            return SEOProStats_Indexation::report($req, $engine, $kind, $days);
        });
    }

    /**
     * GET /coverage: a page's search queries, each with how far the page's
     * own words cover it, questions and the SEO plugin's focus keywords.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function coverage($request) {
        $page = (string) $request->get_param('page');
        $post = (int) $request->get_param('post');
        return self::report($request, static function ($req) use ($page, $post) {
            return SEOProStats_Coverage::report($req, $page, $post);
        });
    }

    /**
     * GET /content: per page, search clicks and position with the visits
     * from search that landed on it and their conversions of a goal.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function content($request) {
        $sort   = (string) $request->get_param('sort');
        $goal   = (string) $request->get_param('goal');
        $engine = (string) $request->get_param('engine');
        return self::report($request, static function ($req) use ($sort, $goal, $engine) {
            return SEOProStats_Content::report($req, $sort, $goal, $engine);
        });
    }

    /**
     * POST /annotations: add a note to the timeline.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function annotate($request) {
        return self::define($request, static function () use ($request) {
            return SEOProStats_Changes::annotate((string) $request->get_param('note'), (string) $request->get_param('page'), (string) $request->get_param('time'));
        });
    }

    /**
     * DELETE /annotations/{id}: delete a note (only notes).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_annotation($request) {
        $id = (int) $request->get_param('id');
        return self::define($request, static function () use ($id) {
            if (!SEOProStats_Changes::delete_note($id)) {
                return new WP_Error('seoprostats_not_found', __('There is no such note.', 'seoprostats'), array('status' => 404));
            }
            return array('deleted' => true, 'id' => $id);
        });
    }

    /**
     * GET /experiments: the newest experiments, due ones first.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiments($request) {
        $args = array('status' => (string) $request->get_param('status'), 'page' => (string) $request->get_param('page'));
        return self::experiment_answer($request, static function () use ($args) {
            return SEOProStats_Experiments::list_experiments($args);
        });
    }

    /**
     * GET /experiments/{id}: one experiment with its measurement.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment($request) {
        $id = (int) $request->get_param('id');
        return self::experiment_answer($request, static function () use ($id) {
            return SEOProStats_Experiments::get($id);
        });
    }

    /**
     * POST /experiments: record an experiment.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment_add($request) {
        $input = (array) $request->get_params();
        return self::experiment_answer($request, static function () use ($input) {
            return SEOProStats_Experiments::add($input);
        });
    }

    /**
     * POST /experiments/{id}: decide, note or cancel.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment_update($request) {
        $id    = (int) $request->get_param('id');
        $input = (array) $request->get_params();
        return self::experiment_answer($request, static function () use ($id, $input) {
            return SEOProStats_Experiments::update($id, $input);
        });
    }

    /**
     * DELETE /experiments/{id}: delete an experiment and its timeline marker.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment_delete($request) {
        $id = (int) $request->get_param('id');
        return self::define($request, static function () use ($id) {
            if (!SEOProStats_Experiments::delete($id)) {
                return new WP_Error('seoprostats_not_found', __('There is no such experiment.', 'seoprostats'), array('status' => 404));
            }
            return array('deleted' => true, 'id' => $id);
        });
    }

    /**
     * GET /queue: the decision queue, best first, with each item's why and
     * score parts.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function queue($request) {
        $engine = (string) $request->get_param('engine');
        $status = (string) $request->get_param('status');
        $goal   = (string) $request->get_param('goal');
        $kind   = (string) $request->get_param('kind');
        return self::report($request, static function ($req) use ($engine, $status, $goal, $kind) {
            return SEOProStats_Queue::report($req, $engine, $status, $goal, $kind);
        });
    }

    /**
     * POST /queue/{key}: accept, done, dismiss, restore, effort or note.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function queue_update($request) {
        $key    = (string) $request->get_param('key');
        $engine = (string) $request->get_param('engine');
        $goal   = (string) $request->get_param('goal');
        $input  = (array) $request->get_params();
        return self::report($request, static function ($req) use ($key, $input, $engine, $goal) {
            return SEOProStats_Queue::update($key, $input, $req, $engine, $goal);
        });
    }

    /**
     * GET /loop: one answer per cycle for an agent: the open queue items,
     * the experiments due, running and recently decided, and the period's
     * search figures per query and page in the aidevops export layout.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function loop($request) {
        $engine = (string) $request->get_param('engine');
        $goal   = (string) $request->get_param('goal');
        $rows   = (int) $request->get_param('rows');
        return self::report($request, static function ($req) use ($engine, $goal, $rows) {
            return SEOProStats_Loop::report($req, $engine, $goal, $rows);
        });
    }

    /**
     * GET /targets: the site's search targets, each with how search
     * treats it now: position, clicks and the page that ranks.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets($request) {
        $engine = (string) $request->get_param('engine');
        $status = (string) $request->get_param('status');
        return self::report($request, static function ($req) use ($engine, $status) {
            return SEOProStats_Targets::report($req, $engine, $status);
        });
    }

    /**
     * POST /targets: import targets (added or updated by query), with the
     * rows skipped and why.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_import($request) {
        $rows    = $request->get_param('targets');
        $text    = (string) $request->get_param('text');
        $replace = (bool) $request->get_param('replace');
        return self::define($request, static function () use ($rows, $text, $replace) {
            $format = 'list';
            if (!is_array($rows) || !$rows) {
                if (trim($text) === '') {
                    return new WP_Error('seoprostats_targets_empty', __('Give the targets as a list or as text.', 'seoprostats'), array('status' => 400));
                }
                $parsed = SEOProStats_Targets::parse($text);
                if (is_wp_error($parsed)) {
                    return $parsed;
                }
                $rows   = $parsed['rows'];
                $format = $parsed['format'];
            }
            $done = SEOProStats_Targets::import($rows, $format === 'toon' ? 'aidevops' : 'list', $replace);
            return is_wp_error($done) ? $done : array('format' => $format) + $done;
        });
    }

    /**
     * DELETE /targets: delete targets by their searches, or every target.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_delete($request) {
        $queries = array_map('strval', (array) $request->get_param('queries'));
        $all     = (bool) $request->get_param('all');
        return self::define($request, static function () use ($queries, $all) {
            return SEOProStats_Targets::delete($queries, $all);
        });
    }

    /**
     * Run experiment work on the data set asked for, with short decimals.
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $work    Makes the answer or an error.
     * @return WP_REST_Response|WP_Error
     */
    private static function experiment_answer($request, callable $work) {
        self::short_floats();
        return self::define($request, $work);
    }

    /**
     * POST /goals, PUT /goals/{id}: add or change a goal.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function save_goals($request) {
        return self::define($request, static function () use ($request) {
            return SEOProStats_Goals::save_goal((array) $request->get_params(), (string) $request->get_param('id'));
        });
    }

    /**
     * POST /funnels, PUT /funnels/{id}: add or change a funnel.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function save_funnels($request) {
        return self::define($request, static function () use ($request) {
            return SEOProStats_Goals::save_funnel((array) $request->get_params(), (string) $request->get_param('id'));
        });
    }

    /**
     * DELETE /goals/{id}.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_goals($request) {
        return self::remove_definition($request, 'goals');
    }

    /**
     * DELETE /funnels/{id}.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_funnels($request) {
        return self::remove_definition($request, 'funnels');
    }

    /**
     * Delete a goal or funnel on the data set asked for.
     *
     * @param WP_REST_Request $request Request.
     * @param string          $type    goals or funnels.
     * @return WP_REST_Response|WP_Error
     */
    private static function remove_definition($request, $type) {
        $id = (string) $request->get_param('id');
        return self::define($request, static function () use ($type, $id) {
            if (!SEOProStats_Goals::delete($type, $id)) {
                return new WP_Error('seoprostats_not_found', __('There is no such goal or funnel.', 'seoprostats'), array('status' => 404));
            }
            return array('deleted' => true, 'id' => $id);
        });
    }

    /**
     * Change definitions on the data set asked for (the demo data has its
     * own goals and funnels).
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $work    Makes the change; returns the answer or an error.
     * @return WP_REST_Response|WP_Error
     */
    private static function define($request, callable $work) {
        $answer = self::on_data((string) $request->get_param('data'), $work);
        return is_wp_error($answer) ? $answer : rest_ensure_response($answer);
    }

    /**
     * Run a report for a REST request.
     *
     * @param WP_REST_Request $request Request.
     * @param string          $report  stats, timeseries or breakdown.
     * @return WP_REST_Response|WP_Error
     */
    private static function answer($request, $report) {
        return self::report($request, static function ($req) use ($report) {
            if ($report === 'stats') {
                return SEOProStats_Query::stats($req);
            }
            if ($report === 'timeseries') {
                return SEOProStats_Query::timeseries($req);
            }
            return SEOProStats_Query::breakdown($req);
        });
    }

    /**
     * Run a report on the request's range, filters and data set.
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $work    Takes the checked request; makes the answer.
     * @return WP_REST_Response|WP_Error
     */
    private static function report($request, callable $work) {
        $req = SEOProStats_Query::request((array) $request->get_params());
        if (is_wp_error($req)) {
            return $req;
        }
        $answer = self::on_data((string) $request->get_param('data'), static function () use ($req, $work) {
            return $work($req);
        });
        if (is_wp_error($answer)) {
            return $answer;
        }
        // WordPress sends no-cache headers to signed-in users; the engine
        // caches answers on the server instead.
        self::short_floats();
        return rest_ensure_response($answer);
    }

    /**
     * Fields of a goal (also of each funnel step).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function goal_args() {
        return array(
            'name'  => array(
                'description' => __('Name shown in reports.', 'seoprostats'),
                'type'        => 'string',
            ),
            'kind'  => array(
                'description' => __('What counts: a page viewed or an event sent.', 'seoprostats'),
                'type'        => 'string',
                'enum'        => SEOProStats_Goals::KINDS,
            ),
            'match' => array(
                'description' => __('The page path (such as /pricing/; * for any text) or the event name (such as Purchase).', 'seoprostats'),
                'type'        => 'string',
            ),
        );
    }

    /**
     * Fields of a funnel.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function funnel_args() {
        $step = self::goal_args();
        return array(
            'name'  => $step['name'],
            'steps' => array(
                'description' => sprintf(
                    /* translators: 1: fewest steps, 2: most steps */
                    __('%1$d to %2$d steps, reached in this order within one visit; each a name, kind and match as for goals.', 'seoprostats'),
                    SEOProStats_Goals::MIN_STEPS,
                    SEOProStats_Goals::MAX_STEPS
                ),
                'type'        => 'array',
                'items'       => array(
                    'type'       => 'object',
                    'properties' => $step,
                ),
            ),
        );
    }

    /**
     * Arguments shared by the report routes.
     *
     * @param bool $breakdown Whether to add dimension, limit and offset.
     * @return array<string,array<string,mixed>>
     */
    private static function args($breakdown) {
        $args = array(
            'range'   => array(
                'description' => __('Period, in the site time zone.', 'seoprostats'),
                'type'        => 'string',
                'enum'        => SEOProStats_Query::RANGES,
                'default'     => '7d',
            ),
            'from'    => array(
                'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                'type'        => 'string',
                'format'      => 'date',
            ),
            'to'      => array(
                'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoprostats'),
                'type'        => 'string',
                'format'      => 'date',
            ),
            'compare' => array(
                'description' => __('Compare with the previous period or the same period last year.', 'seoprostats'),
                'type'        => 'string',
                'enum'        => SEOProStats_Query::COMPARE,
                'default'     => 'none',
            ),
            // No type: WordPress would split a string on commas, and comma
            // means "any of" inside one filter. The engine checks it.
            'filters' => array(
                'description' => __('Filters: dimension:operator:value strings (comma means any of), or a JSON list of {dimension, op, values}.', 'seoprostats'),
            ),
            'data'    => array(
                'description' => __('Live statistics, or the demo data (made-up visits for training, screenshots and testing).', 'seoprostats'),
                'type'        => 'string',
                'enum'        => SEOProStats_Schema::SETS,
                'default'     => 'live',
            ),
        );
        if ($breakdown) {
            $args['dimension'] = array(
                'description' => __('What to break the visits down by.', 'seoprostats'),
                'type'        => 'string',
                'enum'        => array_keys(SEOProStats_Query::DIMENSIONS),
                'required'    => true,
            );
            $args['limit']     = array(
                'description' => __('Most rows.', 'seoprostats'),
                'type'        => 'integer',
                'minimum'     => 1,
                'maximum'     => SEOProStats_Query::MAX_LIMIT,
                'default'     => 10,
            );
            $args['offset']    = array(
                'description' => __('Rows to skip.', 'seoprostats'),
                'type'        => 'integer',
                'minimum'     => 0,
                'default'     => 0,
            );
        }
        return $args;
    }
}
