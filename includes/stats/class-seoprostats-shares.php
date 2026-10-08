<?php
/**
 * Private report links. Secrets are returned only when created or renewed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Shares {

    const OPTION = 'seoprostats_shares';
    /** Every dashboard tab can be a section; Search once per engine. */
    const SECTIONS = array('overview', 'search', 'goals', 'funnels', 'properties', 'clicks', 'changes');
    /**
     * The reads each section makes. Search leaves out Plan and Experiments
     * (the owner's work list and notes), so a shared Search shows Rankings,
     * Opportunities, Audit and Content.
     */
    const REPORTS = array(
        'overview'   => array('stats', 'timeseries', 'breakdown', 'markers', 'realtime'),
        'search'     => array('search', 'markers', 'opportunities', 'audit', 'links', 'content'),
        'goals'      => array('goals'),
        'funnels'    => array('funnels'),
        'properties' => array('properties', 'breakdown'),
        'clicks'     => array('clicks', 'breakdown'),
        'changes'    => array('changes', 'breakdown'),
    );
    /** Search reports a shared Search section shows. */
    const SEARCH_REPORTS = array('rankings', 'opportunities', 'audit', 'content');
    /** Sections whose data only page filters can narrow (search data and the change log have no visits). */
    const PAGE_ONLY = array('search', 'changes');
    /** Breakdowns left out when a report hides site search terms and referrer addresses. */
    const SENSITIVE_DIMENSIONS = array('search', 'no_results', 'source', 'utm_term');

    /** @return array Stored shares, bounded to 50. */
    public static function all() {
        return (array) get_option(self::OPTION, array());
    }

    /**
     * Remove secrets from an owner-facing share.
     *
     * @param array $share Stored share.
     * @return array
     */
    public static function summary(array $share) {
        $share['protected'] = $share['password_hash'] !== '';
        unset($share['token_hash'], $share['password_hash']);
        return $share;
    }

    /**
     * Validate one saved ViewState against the report engine and UI enums.
     *
     * @param mixed $view ViewState.
     * @return array|WP_Error
     */
    public static function view($view) {
        if (!is_array($view) || !isset($view['view']) || !in_array($view['view'], self::SECTIONS, true)) {
            return self::invalid();
        }
        foreach (array('range', 'from', 'to', 'compare', 'metric', 'kind', 'page', 'query', 'key', 'event', 'engine', 'report', 'tab', 'chart', 'sort', 'goal', 'finding', 'links') as $key) {
            if (isset($view[$key]) && (!is_string($view[$key]) || strlen($view[$key]) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $view[$key]) || $view[$key] !== trim($view[$key]))) {
                return self::invalid();
            }
        }
        if (isset($view['filters']) && (!is_array($view['filters']) || count($view['filters']) > 20)) {
            return self::invalid();
        }
        $filters = self::filters($view['filters'] ?? array());
        if (is_wp_error($filters)) {
            return $filters;
        }
        $req = SEOProStats_Query::request(array_merge($view, array('range' => $view['range'] ?? '30d', 'compare' => $view['compare'] ?? 'prev', 'filters' => $filters)));
        if (is_wp_error($req)) {
            return $req;
        }
        $out = array(
            'view'    => $view['view'],
            'range'   => $req['range'],
            'compare' => $req['compare'],
            'metric'  => in_array($view['metric'] ?? '', array('visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'visit_duration'), true) ? $view['metric'] : 'visitors',
            'filters' => $req['filters'],
        );
        if ($req['range'] === 'custom') {
            $out['from'] = $req['from'];
            $out['to']   = $req['to'];
        }
        if ($out['view'] === 'clicks') {
            $kind = $view['kind'] ?? 'elements';
            if (!in_array($kind, array('elements', 'dead', 'links', 'downloads', 'forms', 'pages'), true)) {
                return self::invalid();
            }
            $out['kind'] = $kind;
            $out['page'] = $view['page'] ?? '';
        }
        if ($out['view'] === 'changes' && ($view['page'] ?? '') !== '') {
            $out['page'] = $view['page'];
        }
        if ($out['view'] === 'properties') {
            foreach (array('key', 'event') as $key) {
                if (($view[$key] ?? '') !== '') {
                    $out[$key] = $view[$key];
                }
            }
        }
        if ($out['view'] === 'search') {
            $search = self::search_view($view);
            if (is_wp_error($search)) {
                return $search;
            }
            $out += $search;
        }
        if ($out['view'] === 'overview' && isset($view['tabs']) && is_array($view['tabs'])) {
            $tabs = array(
                'sources' => array('channel', 'source', 'utm_campaign'),
                'pages' => array('page', 'entry', 'exit', 'not_found'),
                'content' => array('author', 'category', 'post_type'),
                'search' => array('search', 'no_results'),
                'locations' => array('country', 'language'),
                'devices' => array('device', 'browser', 'os', 'login'),
                'events' => array('event'),
            );
            foreach ($view['tabs'] as $card => $tab) {
                if (isset($tabs[$card]) && in_array($tab, $tabs[$card], true)) {
                    $out['tabs'][$card] = $tab;
                }
            }
        }
        return $out;
    }

    /**
     * A Search section's choices: its engine, and the report (of those a
     * shared Search shows) with that report's choices. Defaults left out,
     * as the address leaves them out.
     *
     * @param array $view Checked strings.
     * @return array|WP_Error
     */
    private static function search_view(array $view) {
        $out    = array();
        $engine = $view['engine'] ?? 'google';
        if (!isset(SEOProStats_Search::ENGINES[$engine])) {
            return self::invalid();
        }
        if ($engine !== 'google') {
            $out['engine'] = $engine;
        }
        $report = in_array($view['report'] ?? '', self::SEARCH_REPORTS, true) ? $view['report'] : 'rankings';
        if ($report !== 'rankings') {
            $out['report'] = $report;
        }
        $kinds = $engine === 'google' ? SEOProStats_Search::KINDS : array('queries', 'pages');
        if (in_array($view['tab'] ?? '', $kinds, true) && $view['tab'] !== 'queries') {
            $out['tab'] = $view['tab'];
        }
        if (in_array($view['chart'] ?? '', array('impressions', 'ctr', 'position'), true)) {
            $out['chart'] = $view['chart'];
        }
        foreach (array('page', 'query') as $key) {
            if (($view[$key] ?? '') !== '') {
                $out[$key] = $view[$key];
            }
        }
        if ($report === 'content' && in_array($view['sort'] ?? '', array_diff(SEOProStats_Content::SORTS, array('clicks')), true)) {
            $out['sort'] = $view['sort'];
        }
        if ($report === 'audit') {
            if (in_array($view['finding'] ?? '', SEOProStats_Audit::FINDINGS, true)) {
                $out['finding'] = $view['finding'];
            }
            if (in_array($view['links'] ?? '', array_diff(SEOProStats_Links::KINDS, array('orphans')), true)) {
                $out['links'] = $view['links'];
            }
        }
        if (in_array($report, array('content', 'audit'), true) && ($view['goal'] ?? '') !== '') {
            $out['goal'] = $view['goal'];
        }
        return $out;
    }

    /**
     * What makes a section one of a kind in a report: its tab, and for
     * Search its engine.
     *
     * @param array $view Checked view.
     * @return string
     */
    public static function section_key(array $view) {
        return $view['view'] === 'search' ? 'search:' . ($view['engine'] ?? 'google') : $view['view'];
    }

    /**
     * The sections with something to show on the live data, in tab order
     * (section_key() names): a new report starts with these. Each check
     * reads at most one row, or the stored goal and funnel definitions.
     *
     * @return string[]
     */
    public static function sections_with_data() {
        global $wpdb;
        $any = static function ($name) use ($wpdb) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; one row by the first index entry.
            return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i LIMIT 1', SEOProStats_Schema::table($name)));
        };
        $before = SEOProStats_Schema::use_set('live');
        try {
            $out = array();
            if ($any('daily') || $any('sessions')) {
                $out[] = 'overview';
            }
            foreach (SEOProStats_Search::ENGINES as $engine => $code) {
                if (SEOProStats_Search::bounds($code)['to'] !== '') {
                    $out[] = 'search:' . $engine;
                }
            }
            if (SEOProStats_Goals::goals()) {
                $out[] = 'goals';
            }
            if (SEOProStats_Goals::funnels()) {
                $out[] = 'funnels';
            }
            foreach (array('props' => 'properties', 'clicks' => 'clicks', 'changes' => 'changes') as $table => $section) {
                if ($any($table)) {
                    $out[] = $section;
                }
            }
            return $out;
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Whether locked filters only choose pages (all that search data and
     * the change log can be narrowed by).
     *
     * @param array $locked Parsed filters.
     * @return bool
     */
    public static function page_locks_only(array $locked) {
        foreach ($locked as $filter) {
            if (($filter['dimension'] ?? '') !== 'page') {
                return false;
            }
        }
        return true;
    }

    /**
     * Create or update; blank password clears protection, omitted keeps it.
     *
     * @param array  $input Share fields.
     * @param string $id    Existing ID, or empty.
     * @return array|WP_Error
     */
    public static function save(array $input, $id = '') {
        return self::mutate(static function () use ($input, $id) {
            return self::save_locked($input, $id);
        });
    }

    /**
     * Save under the site-wide mutation lock.
     *
     * @param array  $input Fields.
     * @param string $id ID.
     * @return array|WP_Error
     */
    private static function save_locked(array $input, $id) {
        foreach (array('name', 'note', 'password') as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return self::invalid();
            }
        }
        if ((isset($input['branding']) && !is_array($input['branding'])) || (isset($input['locked_filters']) && (!is_array($input['locked_filters']) || count($input['locked_filters']) > 20))) {
            return self::invalid();
        }
        $all = self::all();
        if (($id && !isset($all[$id])) || (!$id && count($all) >= 50)) {
            return self::invalid();
        }
        $old = $id ? $all[$id] : array();
        $raw = array_merge($old, $input);
        $views = $raw['views'] ?? array();
        if (!is_array($views) || !$views || count($views) > 10) {
            return self::invalid();
        }
        foreach ($views as &$view) {
            $view = self::view($view);
            if (is_wp_error($view)) {
                return $view;
            }
        }
        unset($view);
        $keys = array_map(array(__CLASS__, 'section_key'), $views);
        if (count(array_unique($keys)) !== count($keys)) {
            return new WP_Error('seoprostats_invalid_share', __('Each section can be in a shared report once.', 'seoprostats'), array('status' => 400));
        }
        $locked = self::filters($raw['locked_filters'] ?? array());
        if (is_wp_error($locked)) {
            return $locked;
        }
        if (!self::page_locks_only($locked) && array_intersect(array_column($views, 'view'), self::PAGE_ONLY)) {
            return new WP_Error('seoprostats_invalid_share', __('Search and Changes can only be locked to pages. Remove the other locked filters, or those sections.', 'seoprostats'), array('status' => 400));
        }
        $expires = (int) ($raw['expires'] ?? 0);
        if ($expires && $expires <= time()) {
            return self::invalid();
        }
        $token = $id ? '' : bin2hex(random_bytes(16));
        $id    = $id ?: wp_generate_password(16, false, false);
        $share = array(
            'id'           => $id,
            'name'         => substr(sanitize_text_field($raw['name'] ?? ''), 0, 190),
            'note'         => substr(sanitize_textarea_field($raw['note'] ?? ''), 0, 2000),
            'views'        => array_values($views),
            'locked_filters' => $locked,
            'max_days'     => max(0, min(3650, (int) ($raw['max_days'] ?? 0))),
            'expires'      => $expires,
            'hide_realtime' => !empty($raw['hide_realtime']),
            'hide_sensitive' => !empty($raw['hide_sensitive']),
            'created'      => $old['created'] ?? time(),
            'last_opened'  => $old['last_opened'] ?? 0,
            'opens'        => $old['opens'] ?? 0,
            'revoked'      => $old['revoked'] ?? false,
            'token_hash'   => $old['token_hash'] ?? hash('sha256', $token),
            'password_hash' => $old['password_hash'] ?? '',
            'branding'     => self::branding($raw['branding'] ?? array()),
        );
        if (array_key_exists('password', $input)) {
            if (!is_string($input['password']) || strlen($input['password']) > 256) {
                return self::invalid();
            }
            $share['password_hash'] = $input['password'] === '' ? '' : wp_hash_password($input['password']);
        }
        $all[$id] = $share;
        update_option(self::OPTION, $all, false);
        $answer = self::summary($share);
        if ($token) {
            $answer['url'] = self::url($token);
        }
        return $answer;
    }

    /**
     * Validate public filter shapes before the engine coerces their values.
     *
     * @param mixed $raw Filter list or serialized filters.
     * @return array|WP_Error
     */
    public static function filters($raw) {
        if (is_string($raw)) {
            if (strlen($raw) > 16384) {
                return self::invalid();
            }
            $raw = substr(ltrim($raw), 0, 1) === '[' ? json_decode($raw, true) : array($raw);
        }
        if (!is_array($raw) || count($raw) > 20) {
            return self::invalid();
        }
        foreach ($raw as $filter) {
            if (is_string($filter) && strlen($filter) <= 2048) {
                continue;
            }
            if (!is_array($filter) || !is_string($filter['dimension'] ?? null) || !is_string($filter['op'] ?? 'is') || !is_array($filter['values'] ?? null) || count($filter['values']) > 100) {
                return self::invalid();
            }
            foreach ($filter['values'] as $value) {
                if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    return self::invalid();
                }
            }
        }
        return SEOProStats_Query::parse_filters($raw);
    }

    /**
     * Accept only local raster media; URLs are never fetched server-side.
     *
     * @param array $raw Branding fields.
     * @return array
     */
    public static function branding(array $raw) {
        $raw = array_merge(self::defaults(), $raw);
        $out = array();
        foreach (array('title', 'agency', 'byline') as $key) {
            $out[$key] = is_string($raw[$key] ?? '') ? substr(sanitize_text_field($raw[$key] ?? ''), 0, 190) : '';
        }
        $out['website'] = is_string($raw['website'] ?? '') ? esc_url_raw($raw['website'] ?? '', array('https', 'http')) : '';
        foreach (array('logo', 'agency_logo') as $key) {
            $id  = (int) ($raw[$key] ?? 0);
            $out[$key] = self::local_logo($id) ? $id : 0;
        }
        $out['accent'] = is_string($raw['accent'] ?? '') ? (sanitize_hex_color($raw['accent'] ?? '') ?: '#2271b1') : '#2271b1';
        if (strlen($out['accent']) === 4) {
            $out['accent'] = '#' . $out['accent'][1] . $out['accent'][1] . $out['accent'][2] . $out['accent'][2] . $out['accent'][3] . $out['accent'][3];
        }
        $out['mode']   = in_array($raw['mode'] ?? '', array('light', 'dark', 'system'), true) ? $raw['mode'] : 'system';
        $out['credit'] = !isset($raw['credit']) || (bool) $raw['credit'];
        return $out;
    }

    /**
     * Branding defaults from the shared reports settings tab. With Agency
     * branding off, the agency fields stay empty whatever is stored.
     *
     * @return array
     */
    public static function defaults() {
        $brand = (bool) SEOProStats_Settings::get('share_brand');
        $out   = array();
        foreach (array('agency', 'website', 'byline', 'agency_logo', 'accent', 'mode', 'credit') as $key) {
            $out[$key] = SEOProStats_Settings::get('share_' . $key);
        }
        if (!$brand) {
            $out = array_merge($out, array('agency' => '', 'website' => '', 'byline' => '', 'agency_logo' => 0));
        }
        return $out;
    }

    /**
     * Local raster logo only, including the site's default logo/icon.
     *
     * @param int $id Attachment ID.
     * @return string
     */
    public static function local_logo($id) {
        if (!$id || !in_array(get_post_mime_type($id), array('image/png', 'image/jpeg', 'image/webp', 'image/gif'), true)) {
            return '';
        }
        $url = wp_get_attachment_image_url($id, 'medium');
        return $url && wp_parse_url($url, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST) ? $url : '';
    }

    /**
     * Rotate a link or revoke it. Renewal also invalidates unlock grants.
     *
     * @param string $id    Share ID.
     * @param bool   $renew Whether to renew instead of revoke.
     * @return array|WP_Error
     */
    public static function revoke($id, $renew = false) {
        return self::mutate(static function () use ($id, $renew) {
            return self::revoke_locked($id, $renew);
        });
    }

    /**
     * Rotate under the site-wide mutation lock.
     *
     * @param string $id ID.
     * @param bool $renew Renew.
     * @return array|WP_Error
     */
    private static function revoke_locked($id, $renew) {
        $all = self::all();
        if (!isset($all[$id])) {
            return self::invalid();
        }
        $token = bin2hex(random_bytes(16));
        $all[$id]['token_hash'] = hash('sha256', $token);
        $all[$id]['revoked']    = !$renew;
        update_option(self::OPTION, $all, false);
        return $renew ? array('url' => self::url($token)) : array('revoked' => true);
    }

    /**
     * Count opens without restoring a concurrently revoked link.
     *
     * @param array $share Resolved share.
     * @return array|WP_Error
     */
    public static function opened(array $share) {
        return self::mutate(static function () use ($share) {
            $all = self::all();
            $now = $all[$share['id']] ?? null;
            if (!$now || $now['revoked'] || !hash_equals($now['token_hash'], $share['token_hash']) || !hash_equals($now['password_hash'], $share['password_hash']) || ($now['expires'] && $now['expires'] <= time())) {
                return self::denied();
            }
            $now['last_opened'] = time();
            ++$now['opens'];
            $all[$now['id']] = $now;
            update_option(self::OPTION, $all, false);
            return $now;
        });
    }

    /**
     * Serialize option mutations; refresh the request cache after locking.
     *
     * @param callable $work Mutation.
     * @return mixed
     */
    private static function mutate(callable $work) {
        global $wpdb;
        $lock = substr('seoprostats_shares_' . hash('sha256', $wpdb->prefix), 0, 64);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- security-sensitive read/modify/write serialization.
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
            return self::denied();
        }
        try {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            return $work();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- release the connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Resolve a bearer secret without ever storing it.
     *
     * @param string $token Bearer token.
     * @return array|WP_Error
     */
    public static function find($token) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            return self::denied();
        }
        $hash = hash('sha256', $token);
        foreach (self::all() as $share) {
            if (hash_equals($share['token_hash'], $hash) && !$share['revoked'] && (!$share['expires'] || $share['expires'] > time())) {
                return $share;
            }
        }
        return self::denied();
    }

    /**
     * Atomic fixed-window quota in the object cache, or a DB advisory lock.
     * No visitor address or identity is used. Fail closed on lock contention.
     *
     * @param array  $share Share.
     * @param string $kind  reads or passwords.
     * @param int    $limit Requests per minute.
     * @return bool
     */
    public static function quota(array $share, $kind, $limit) {
        global $wpdb;
        $key = 'seoprostats_share_' . substr($share['token_hash'], 0, 24) . '_' . $kind;
        if (wp_using_ext_object_cache()) {
            // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.LowCacheTime -- a security quota's fixed one-minute window, not a report cache.
            wp_cache_add($key, 0, 'seoprostats', 60);
            $count = wp_cache_incr($key, 1, 'seoprostats');
            return $count !== false && $count <= $limit;
        }
        $lock = $key . '_' . get_current_blog_id();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- connection-owned lock serializes a transient counter.
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
            return false;
        }
        try {
            $window = get_transient($key);
            if (!is_array($window) || $window['until'] <= time()) {
                $window = array('until' => time() + 60, 'count' => 0);
            }
            ++$window['count'];
            set_transient($key, $window, max(1, (int) $window['until'] - time()));
            return $window['count'] <= $limit;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the connection-owned lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Signed, one-hour password grant bound to the current token and hash.
     *
     * @param array $share Share.
     * @param int   $until Expiry.
     * @return string
     */
    public static function grant(array $share, $until) {
        return $until . '.' . hash_hmac('sha256', $share['token_hash'] . $share['password_hash'] . ':' . $until, wp_salt('auth'));
    }

    /**
     * Check a grant; a logged-in administrator gains no extra share access.
     *
     * @param array  $share Share.
     * @param string $grant Grant.
     * @return bool
     */
    public static function unlocked(array $share, $grant) {
        if ($share['password_hash'] === '') {
            return true;
        }
        $until = (int) explode('.', $grant)[0];
        return $until > time() && $until <= time() + HOUR_IN_SECONDS && hash_equals(self::grant($share, $until), $grant);
    }

    /**
     * Build the query-string address; works regardless of permalink setup.
     *
     * @param string $token Token.
     * @return string
     */
    public static function url($token) {
        return add_query_arg('seoprostats_share', $token, home_url('/'));
    }

    /** @return WP_Error Uniform public failure. */
    public static function denied() {
        return new WP_Error('seoprostats_share_unavailable', __('This report is unavailable.', 'seoprostats'), array('status' => 403));
    }

    /** @return WP_Error Owner input failure. */
    private static function invalid() {
        return new WP_Error('seoprostats_share_invalid', __('Check the shared report fields.', 'seoprostats'), array('status' => 400));
    }
}
