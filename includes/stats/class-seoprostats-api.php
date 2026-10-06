<?php
/**
 * The read REST API (namespace seoprostats/v1): stats, timeseries,
 * breakdown, realtime and markers, each on live data or the demo data
 * (data=demo); demo (make, carry on, remove) and view (the data set a
 * person sees). Contract: docs/api/openapi.yaml.
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
        register_rest_route($ns, '/markers', $read + array(
            'callback' => array(__CLASS__, 'markers'),
            'args'     => $base,
        ));

        $manage = array(__CLASS__, 'can_manage');
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
     * GET /markers: changes on the timeline. Recording changes comes with
     * Phase 3; until then the list is empty.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function markers($request) {
        $req = SEOProStats_Query::request((array) $request->get_params());
        if (is_wp_error($req)) {
            return $req;
        }
        return rest_ensure_response(array(
            'range'   => SEOProStats_Query::range_out(SEOProStats_Query::range($req)),
            'markers' => array(),
        ));
    }

    /**
     * Run a report for a REST request.
     *
     * @param WP_REST_Request $request Request.
     * @param string          $report  stats, timeseries or breakdown.
     * @return WP_REST_Response|WP_Error
     */
    private static function answer($request, $report) {
        $req = SEOProStats_Query::request((array) $request->get_params());
        if (is_wp_error($req)) {
            return $req;
        }
        $answer = self::on_data((string) $request->get_param('data'), static function () use ($req, $report) {
            if ($report === 'stats') {
                return SEOProStats_Query::stats($req);
            }
            if ($report === 'timeseries') {
                return SEOProStats_Query::timeseries($req);
            }
            return SEOProStats_Query::breakdown($req);
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
