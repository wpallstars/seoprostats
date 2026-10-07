<?php
/**
 * The REST API (namespace seoprostats/v1): the reports stats, timeseries,
 * breakdown, realtime, markers, changes, goals, funnels, properties and clicks, each on
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
                        'description' => __('Search Console: the service account\'s JSON key, as text; without it, the saved key is kept (to change the property).', 'seoprostats'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'property' => array(
                        'description' => __('Search Console: the property to import (https://example.com/ or sc-domain:example.com); without it, the one for this site.', 'seoprostats'),
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
        self::load_connections();
        if (!SEOProStats_Schema::is_current()) {
            return new WP_Error('seoprostats_tables', __('The statistics tables are being updated. Try again after visiting wp-admin.', 'seoprostats'), array('status' => 503));
        }
        $deleted = SEOProStats_Search_Import::undo((int) $request->get_param('id'));
        if (is_wp_error($deleted)) {
            return self::with_status($deleted, 404);
        }
        return rest_ensure_response(array('id' => (int) $request->get_param('id'), 'deleted' => $deleted));
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
        }
        $answer->add_data($data);
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
        return self::report($request, array('SEOProStats_Conversions', 'goals'));
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
