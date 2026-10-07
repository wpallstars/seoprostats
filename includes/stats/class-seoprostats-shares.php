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
    const SECTIONS = array('overview', 'goals', 'clicks');
    const REPORTS = array(
        'overview' => array('stats', 'timeseries', 'breakdown', 'markers', 'realtime'),
        'goals'    => array('goals'),
        'clicks'   => array('clicks'),
    );

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
        $req = SEOProStats_Query::request(array_merge($view, array('range' => $view['range'] ?? '30d')));
        if (is_wp_error($req)) {
            return $req;
        }
        $out = array(
            'view'    => $view['view'],
            'range'   => $req['range'],
            'compare' => $req['compare'],
            'metric'  => in_array($view['metric'] ?? '', array('visitors', 'visits', 'pageviews', 'bounce_rate', 'duration', 'events'), true) ? $view['metric'] : 'visitors',
            'filters' => $req['filters'],
        );
        if ($req['range'] === 'custom') {
            $out['from'] = $req['from'];
            $out['to']   = $req['to'];
        }
        if ($out['view'] === 'clicks') {
            $kind = $view['kind'] ?? 'elements';
            if (!in_array($kind, array('elements', 'dead', 'links', 'files', 'forms', 'pages'), true)) {
                return self::invalid();
            }
            $out['kind'] = $kind;
            $out['page'] = sanitize_text_field($view['page'] ?? '');
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
     * Create or update; blank password clears protection, omitted keeps it.
     *
     * @param array  $input Share fields.
     * @param string $id    Existing ID, or empty.
     * @return array|WP_Error
     */
    public static function save(array $input, $id = '') {
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
        $locked = SEOProStats_Query::parse_filters($raw['locked_filters'] ?? array());
        if (is_wp_error($locked)) {
            return $locked;
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
     * Accept only local raster media; URLs are never fetched server-side.
     *
     * @param array $raw Branding fields.
     * @return array
     */
    public static function branding(array $raw) {
        $out = array();
        foreach (array('title', 'agency', 'byline') as $key) {
            $out[$key] = substr(sanitize_text_field($raw[$key] ?? ''), 0, 190);
        }
        $out['website'] = esc_url_raw($raw['website'] ?? '', array('https', 'http'));
        foreach (array('logo', 'agency_logo') as $key) {
            $id  = (int) ($raw[$key] ?? 0);
            $out[$key] = self::local_logo($id) ? $id : 0;
        }
        $out['accent'] = sanitize_hex_color($raw['accent'] ?? '') ?: '#2271b1';
        $out['mode']   = in_array($raw['mode'] ?? '', array('light', 'dark', 'system'), true) ? $raw['mode'] : 'system';
        $out['credit'] = !isset($raw['credit']) || (bool) $raw['credit'];
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
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return false;
        }
        try {
            $window = get_transient($key);
            if (!is_array($window) || $window['until'] <= time()) {
                $window = array('until' => time() + 60, 'count' => 0);
            }
            ++$window['count'];
            set_transient($key, $window, max(1, $window['until'] - time()));
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
