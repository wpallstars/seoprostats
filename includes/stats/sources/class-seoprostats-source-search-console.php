<?php
/**
 * Google Search Console, connected one of two ways:
 *
 * - Sign in with Google: the owner approves read access on Google's
 *   consent screen through our relay (relay/gsc-oauth, a Cloudflare
 *   Worker that holds the OAuth client's secret). The site keeps the
 *   refresh token, encrypted, and asks the relay for an access token
 *   when it needs one.
 * - A service account's JSON key: the owner makes the account in Google
 *   Cloud and adds its address as a user of their Search Console
 *   property. No outside server: the key signs a short-lived token
 *   request (RS256, PHP's OpenSSL) to Google's fixed token address.
 *
 * Either way, read-only access to Search Console. Requests come only from
 * cron, WP-CLI or an administrator's action.
 * Design: docs/architecture.md → Integrations → Search Console.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Source_Search_Console {

    /** Source key (SEOProStats_Connections::SOURCES). */
    const KEY = 'search-console';

    /** Name shown. */
    const NAME = 'Google Search Console';

    /** Search engine of its rows. */
    const ENGINE = SEOProStats_Schema::ENGINE_GOOGLE;

    /** Google's token address; the key's own token_uri is not used. */
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** The Search Console API. */
    const API = 'https://searchconsole.googleapis.com/webmasters/v3/';

    /** URL Inspection (the version in Google's index, not a live test). */
    const INSPECT_URL = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    /** Language of URL Inspection's texts (coverage states), so they read the same on every site. */
    const INSPECT_LANGUAGE = 'en-US';

    /** Read-only access (enough for search data, sitemaps and URL Inspection). */
    const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** Months of history Search Console keeps, imported on connecting. */
    const MONTHS = 16;

    /** Search Console's days are Pacific time. */
    const TIMEZONE = 'America/Los_Angeles';

    /** Rows per request (the API's most). */
    const PAGE_ROWS = 25000;

    /** Seconds a request may take. */
    const TIMEOUT = 30;

    /**
     * The Sign in with Google relay (relay/gsc-oauth). A test site can
     * point at another with SEOPROSTATS_GOOGLE_RELAY in wp-config.php.
     */
    const RELAY = 'https://gsc-oauth.wpallstars.com';

    /** Google's address for revoking a token (needs no secret). */
    const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Credentials type of a Google sign-in (a service account's key has none). */
    const GOOGLE = 'google';

    /** Sign-ins in progress and just finished, for 15 minutes (one transient). */
    const SIGNIN = 'seoprostats_google_signin';

    /** Seconds a sign-in may take, and its result waits to be used. */
    const SIGNIN_TTL = 900;

    /** The last access token of a Google sign-in, encrypted, while it lasts. */
    const ACCESS = 'seoprostats_google_access';

    /** @var array<string,array{token:string,expires:int}> Access tokens by account, for this request. */
    private static $tokens = array();

    /**
     * Check what the owner gave (a Google sign-in or a key, and maybe a
     * property) by signing in and listing the properties, and say what to
     * store.
     *
     * @param array<string,mixed>      $input    google (true: the current user's finished sign-in), key (the JSON key's text; '' keeps the saved credentials), property ('' to choose).
     * @param array<string,mixed>|null $existing Saved credentials, or null.
     * @return array{credentials:array<string,mixed>,settings:array<string,mixed>,properties:array<string,string>}|WP_Error
     */
    public static function connect(array $input, $existing) {
        list($key, $signin) = self::connect_key($input, $existing);
        if (is_wp_error($key)) {
            return $key;
        }
        $google = self::is_google($key);
        $token  = self::token($key);
        if (is_wp_error($token)) {
            return $token;
        }
        $properties = self::properties($token);
        if (is_wp_error($properties)) {
            return $properties;
        }
        $property = self::pick_property($properties, isset($input['property']) ? (string) $input['property'] : '', $google);
        if (is_wp_error($property)) {
            $property->add_data(array('status' => 400, 'properties' => array_keys($properties), 'account' => $google ? '' : $key['client_email']));
            return $property;
        }
        if ($signin !== null) {
            self::forget_signin(get_current_user_id());
        }
        return array(
            'credentials' => $google ? array('type' => self::GOOGLE, 'refresh_token' => (string) $key['refresh_token']) : $key,
            'settings'    => array(
                'property' => $property,
                'account'  => $google ? '' : (string) $key['client_email'],
                'method'   => $google ? self::GOOGLE : 'key',
            ),
            'properties'  => $properties,
        );
    }

    /**
     * The credentials to connect with: the current user's finished Google
     * sign-in, a pasted JSON key, or the saved credentials; and the
     * sign-in, if one was used.
     *
     * @param array<string,mixed>      $input    As connect().
     * @param array<string,mixed>|null $existing Saved credentials, or null.
     * @return array{0:array<string,mixed>|WP_Error,1:array<string,mixed>|null}
     */
    private static function connect_key(array $input, $existing) {
        $json = isset($input['key']) ? trim((string) $input['key']) : '';
        if (!empty($input['google'])) {
            $signin = self::signed_in(get_current_user_id());
            $key    = $signin === null ? new WP_Error('seoprostats_google_expired', __('The Google sign-in has expired. Choose Sign in with Google again.', 'seoprostats')) : $signin;
            if (is_array($key) && isset($key['error'])) {
                $key = new WP_Error('seoprostats_google_' . $key['error'], self::signin_error((string) $key['error']));
            }
            return array($key, $signin);
        }
        if ($json !== '') {
            return array(self::parse_key($json), null);
        }
        if (is_array($existing)) {
            return array($existing, null);
        }
        return array(new WP_Error('seoprostats_key_missing', __('Choose Sign in with Google, or paste the service account\'s JSON key.', 'seoprostats')), null);
    }

    /**
     * Whether credentials are a Google sign-in's (a refresh token).
     *
     * @param array<string,mixed> $key Credentials.
     * @return bool
     */
    public static function is_google(array $key) {
        return isset($key['type'], $key['refresh_token']) && $key['type'] === self::GOOGLE && is_string($key['refresh_token']) && $key['refresh_token'] !== '';
    }

    /**
     * The relay's address, without a trailing slash.
     *
     * @return string
     */
    public static function relay() {
        $relay = defined('SEOPROSTATS_GOOGLE_RELAY') && is_string(SEOPROSTATS_GOOGLE_RELAY) && SEOPROSTATS_GOOGLE_RELAY !== '' ? SEOPROSTATS_GOOGLE_RELAY : self::RELAY;
        return untrailingslashit($relay);
    }

    /**
     * Where the relay posts Google's answer: admin-post.php, which takes
     * it with or without the admin's cookies (a cross-site post may come
     * without them), then sends the browser to the Connections tab.
     *
     * @return string
     */
    public static function return_url() {
        return admin_url('admin-post.php?action=' . self::SIGNIN);
    }

    /**
     * Start a sign-in for a user: remember a one-time nonce for them, and
     * give the relay's address to send their browser to.
     *
     * @param int $user_id User.
     * @return string
     */
    public static function start_url($user_id) {
        $nonce = wp_generate_password(43, false);
        $all   = self::signins();
        $all['states'][hash('sha256', $nonce)] = array('user' => (int) $user_id, 'expires' => time() + self::SIGNIN_TTL);
        self::save_signins($all);
        return add_query_arg(array(
            'site'  => rawurlencode(self::return_url()),
            'nonce' => $nonce,
        ), self::relay() . '/start');
    }

    /**
     * Take the relay's post: check its nonce, and keep the refresh token
     * (encrypted) or the error for the user who started the sign-in.
     *
     * @param array<string,string> $fields nonce, and refresh_token, access_token and expires_in, or error.
     * @return int|WP_Error The user who started it.
     */
    public static function receive(array $fields) {
        $nonce = isset($fields['nonce']) ? (string) $fields['nonce'] : '';
        $hash  = hash('sha256', $nonce);
        $all   = self::signins();
        if ($nonce === '' || !isset($all['states'][$hash])) {
            return new WP_Error('seoprostats_google_unknown', __('This sign-in was not started here, or has expired. Choose Sign in with Google again.', 'seoprostats'), array('status' => 400));
        }
        $user = (int) $all['states'][$hash]['user'];
        unset($all['states'][$hash]);
        $error   = isset($fields['error']) ? sanitize_key((string) $fields['error']) : '';
        $refresh = isset($fields['refresh_token']) ? (string) $fields['refresh_token'] : '';
        if ($error === '' && $refresh === '') {
            $error = 'no_refresh_token';
        }
        if ($error !== '') {
            $all['done'][$user] = array('error' => $error, 'expires' => time() + self::SIGNIN_TTL);
            self::save_signins($all);
            return $user;
        }
        $secret = SEOProStats_Connections::encrypt((string) wp_json_encode(array('type' => self::GOOGLE, 'refresh_token' => $refresh)));
        if (is_wp_error($secret)) {
            return $secret;
        }
        $all['done'][$user] = array('secret' => $secret, 'expires' => time() + self::SIGNIN_TTL);
        self::save_signins($all);
        $access = isset($fields['access_token']) ? (string) $fields['access_token'] : '';
        if ($access !== '') {
            self::keep_access($refresh, $access, isset($fields['expires_in']) ? (int) $fields['expires_in'] : 0);
        }
        return $user;
    }

    /**
     * A user's finished sign-in: credentials, or array('error' => code);
     * null when there is none.
     *
     * @param int $user_id User.
     * @return array<string,mixed>|null
     */
    public static function signed_in($user_id) {
        $all  = self::signins();
        $done = isset($all['done'][$user_id]) ? $all['done'][$user_id] : null;
        if (!is_array($done)) {
            return null;
        }
        if (isset($done['error'])) {
            return array('error' => (string) $done['error']);
        }
        $json = isset($done['secret']) ? SEOProStats_Connections::decrypt((string) $done['secret']) : false;
        $data = $json !== false ? json_decode($json, true) : null;
        return is_array($data) && self::is_google($data) ? $data : null;
    }

    /**
     * Forget a user's finished sign-in.
     *
     * @param int $user_id User.
     */
    public static function forget_signin($user_id) {
        $all = self::signins();
        unset($all['done'][$user_id]);
        self::save_signins($all);
    }

    /**
     * A sign-in error from the relay, in words.
     *
     * @param string $code access_denied, scope_denied, no_refresh_token, exchange_failed or google_error.
     * @return string
     */
    public static function signin_error($code) {
        switch ($code) {
            case 'access_denied':
                return __('The sign-in was cancelled on Google\'s screen. Choose Sign in with Google to try again.', 'seoprostats');
            case 'scope_denied':
                return __('Google was not allowed to share Search Console data: on Google\'s screen, leave the Search Console box ticked. Choose Sign in with Google to try again.', 'seoprostats');
            case 'no_refresh_token':
                return __('Google did not give lasting access. Choose Sign in with Google to try again.', 'seoprostats');
            default:
                return __('Google could not complete the sign-in. Choose Sign in with Google to try again.', 'seoprostats');
        }
    }

    /**
     * Sign-ins in progress (states, by the nonce's hash) and finished
     * (done, by user), without expired ones.
     *
     * @return array{states:array<string,array{user:int,expires:int}>,done:array<int,array<string,mixed>>}
     */
    private static function signins() {
        $all = get_transient(self::SIGNIN);
        $out = array('states' => array(), 'done' => array());
        $now = time();
        foreach (is_array($all) && isset($all['states']) && is_array($all['states']) ? $all['states'] : array() as $hash => $row) {
            if (is_array($row) && isset($row['user'], $row['expires']) && (int) $row['expires'] > $now) {
                $out['states'][(string) $hash] = array('user' => (int) $row['user'], 'expires' => (int) $row['expires']);
            }
        }
        foreach (is_array($all) && isset($all['done']) && is_array($all['done']) ? $all['done'] : array() as $user => $row) {
            if (is_array($row) && isset($row['expires']) && (int) $row['expires'] > $now) {
                $out['done'][(int) $user] = $row;
            }
        }
        return $out;
    }

    /**
     * Save sign-ins, or delete the transient when there are none.
     *
     * @param array<string,array<mixed>> $all signins().
     */
    private static function save_signins(array $all) {
        if (empty($all['states']) && empty($all['done'])) {
            delete_transient(self::SIGNIN);
            return;
        }
        set_transient(self::SIGNIN, $all, self::SIGNIN_TTL);
    }

    /**
     * Keep a Google sign-in's access token, encrypted, until shortly before
     * it expires, so imports a minute apart ask the relay about once an hour.
     *
     * @param string $refresh Refresh token it belongs to.
     * @param string $token   Access token.
     * @param int    $expires Seconds it lasts.
     */
    private static function keep_access($refresh, $token, $expires) {
        $expires = $expires > 0 ? $expires : 3000;
        $until   = time() + $expires;
        self::$tokens[self::cache_key($refresh)] = array('token' => $token, 'expires' => $until);
        $secret = SEOProStats_Connections::encrypt((string) wp_json_encode(array('for' => self::cache_key($refresh), 'token' => $token, 'expires' => $until)));
        if (!is_wp_error($secret) && $expires > 300) {
            set_transient(self::ACCESS, $secret, $expires - 120);
        }
    }

    /**
     * A kept access token for a refresh token, or ''.
     *
     * @param string $refresh Refresh token.
     * @return string
     */
    private static function kept_access($refresh) {
        $key = self::cache_key($refresh);
        if (isset(self::$tokens[$key]) && self::$tokens[$key]['expires'] > time() + 60) {
            return self::$tokens[$key]['token'];
        }
        $stored = get_transient(self::ACCESS);
        $json   = is_string($stored) ? SEOProStats_Connections::decrypt($stored) : false;
        $data   = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['for'], $data['token'], $data['expires']) || $data['for'] !== $key || (int) $data['expires'] <= time() + 60) {
            return '';
        }
        self::$tokens[$key] = array('token' => (string) $data['token'], 'expires' => (int) $data['expires']);
        return (string) $data['token'];
    }

    /**
     * A short fingerprint of a refresh token, to match a kept access token
     * to it (never the token itself).
     *
     * @param string $refresh Refresh token.
     * @return string
     */
    private static function cache_key($refresh) {
        return 'g:' . substr(hash('sha256', (string) $refresh), 0, 32);
    }

    /**
     * An access token from the relay for a Google sign-in.
     *
     * @param array<string,mixed> $key Credentials (type google, refresh_token).
     * @return string|WP_Error
     */
    private static function google_token(array $key) {
        $refresh = (string) $key['refresh_token'];
        $kept    = self::kept_access($refresh);
        if ($kept !== '') {
            return $kept;
        }
        $response = wp_remote_post(self::relay() . '/refresh', array(
            'timeout'    => self::TIMEOUT,
            'user-agent' => self::user_agent(),
            'headers'    => array('Content-Type' => 'application/json'),
            'body'       => (string) wp_json_encode(array('refresh_token' => $refresh)),
        ));
        if (is_wp_error($response)) {
            /* translators: %s: error message */
            return new WP_Error('seoprostats_google_relay', sprintf(__('The sign-in service could not be reached: %s', 'seoprostats'), $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($data) && !empty($data['access_token'])) {
            self::keep_access($refresh, (string) $data['access_token'], isset($data['expires_in']) ? (int) $data['expires_in'] : 0);
            return (string) $data['access_token'];
        }
        if (is_array($data) && isset($data['error']) && $data['error'] === 'invalid_grant') {
            return new WP_Error('seoprostats_google_revoked', __('Google no longer accepts this connection: access was removed, or it expired. Disconnect, then choose Sign in with Google again.', 'seoprostats'), array('status' => 401));
        }
        /* translators: %d: HTTP status code */
        return new WP_Error('seoprostats_google_relay', sprintf(__('The sign-in service could not get access from Google (HTTP %d). The next import tries again.', 'seoprostats'), $code));
    }

    /**
     * Revoke a Google sign-in's access with Google (disconnecting). Errors
     * are ignored: the site forgets the token either way.
     *
     * @param array<string,mixed> $key Credentials.
     */
    public static function revoke(array $key) {
        if (!self::is_google($key)) {
            return;
        }
        delete_transient(self::ACCESS);
        wp_remote_post(self::REVOKE_URL, array(
            'timeout'    => 3,
            'user-agent' => self::user_agent(),
            'body'       => array('token' => (string) $key['refresh_token']),
        ));
    }

    /**
     * Read a service account's JSON key: its address and private key.
     *
     * @param string $json The key file's text.
     * @return array{client_email:string,private_key:string,private_key_id:string}|WP_Error
     */
    public static function parse_key($json) {
        $data = json_decode(trim((string) $json), true);
        if (!is_array($data)) {
            return new WP_Error('seoprostats_key_format', __('That is not a JSON key. Paste the whole file Google Cloud gave you when you made the service account\'s key.', 'seoprostats'));
        }
        if (!isset($data['type']) || $data['type'] !== 'service_account' || empty($data['client_email']) || empty($data['private_key'])) {
            return new WP_Error('seoprostats_key_type', __('That JSON is not a service account\'s key (it needs type service_account, client_email and private_key).', 'seoprostats'));
        }
        if (!function_exists('openssl_sign') || !function_exists('openssl_pkey_get_private')) {
            return new WP_Error('seoprostats_no_openssl', __('PHP\'s OpenSSL extension is needed to sign in to Google with a service account. Ask your host to switch it on.', 'seoprostats'));
        }
        $email = sanitize_email((string) $data['client_email']);
        if ($email === '' || !openssl_pkey_get_private((string) $data['private_key'])) {
            return new WP_Error('seoprostats_key_invalid', __('The key\'s address or private key cannot be read. Make a new key for the service account and paste it again.', 'seoprostats'));
        }
        return array(
            'client_email'   => $email,
            'private_key'    => (string) $data['private_key'],
            'private_key_id' => isset($data['private_key_id']) ? sanitize_text_field((string) $data['private_key_id']) : '',
        );
    }

    /**
     * An access token (one per hour): from the relay for a Google sign-in,
     * else signed with the service account's key and kept for the request.
     *
     * @param array<string,mixed> $key Credentials: a Google sign-in's, or from parse_key().
     * @return string|WP_Error
     */
    public static function token(array $key) {
        if (self::is_google($key)) {
            return self::google_token($key);
        }
        $email = isset($key['client_email']) ? (string) $key['client_email'] : '';
        if (isset(self::$tokens[$email]) && self::$tokens[$email]['expires'] > time() + 60) {
            return self::$tokens[$email]['token'];
        }
        $now    = time();
        $header = array('alg' => 'RS256', 'typ' => 'JWT');
        if (!empty($key['private_key_id'])) {
            $header['kid'] = (string) $key['private_key_id'];
        }
        $claims = array(
            'iss'   => $email,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + HOUR_IN_SECONDS,
        );
        $input  = self::base64url((string) wp_json_encode($header)) . '.' . self::base64url((string) wp_json_encode($claims));
        $pkey   = false;
        if (function_exists('openssl_pkey_get_private')) {
            $pkey = openssl_pkey_get_private(isset($key['private_key']) ? (string) $key['private_key'] : '');
        }
        $signed = '';
        if (!$pkey || !openssl_sign($input, $signed, $pkey, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('seoprostats_key_sign', __('The private key could not sign the sign-in request. Connect again with a new key.', 'seoprostats'));
        }
        $response = wp_remote_post(self::TOKEN_URL, array(
            'timeout'    => self::TIMEOUT,
            'user-agent' => self::user_agent(),
            'body'       => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $input . '.' . self::base64url($signed),
            ),
        ));
        $answer = self::answer($response);
        if (is_wp_error($answer)) {
            /* translators: %s: Google's message */
            return new WP_Error('seoprostats_google_signin', sprintf(__('Google refused the sign-in: %s', 'seoprostats'), $answer->get_error_message()));
        }
        if (empty($answer['access_token'])) {
            return new WP_Error('seoprostats_google_signin', __('Google answered the sign-in without a token.', 'seoprostats'));
        }
        self::$tokens[$email] = array(
            'token'   => (string) $answer['access_token'],
            'expires' => $now + (isset($answer['expires_in']) ? (int) $answer['expires_in'] : 3000),
        );
        return self::$tokens[$email]['token'];
    }

    /**
     * The properties the account can read: address => permission level.
     *
     * @param string $token Access token.
     * @return array<string,string>|WP_Error
     */
    public static function properties($token) {
        $answer = self::request('GET', 'sites', $token);
        if (is_wp_error($answer)) {
            return $answer;
        }
        $out = array();
        foreach (isset($answer['siteEntry']) && is_array($answer['siteEntry']) ? $answer['siteEntry'] : array() as $entry) {
            if (!is_array($entry) || empty($entry['siteUrl'])) {
                continue;
            }
            $level = isset($entry['permissionLevel']) ? (string) $entry['permissionLevel'] : '';
            if ($level !== 'siteUnverifiedUser') {
                $out[(string) $entry['siteUrl']] = $level;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The property to import: the one asked for when the account can read
     * it, else the one for this site (a domain property before an address
     * prefix one).
     *
     * @param array<string,string> $properties From properties().
     * @param string               $wanted     Property asked for, or '' to choose.
     * @param bool                 $google     Signed in with Google (else a service account).
     * @return string|WP_Error
     */
    public static function pick_property(array $properties, $wanted = '', $google = false) {
        $wanted = trim((string) $wanted);
        if ($wanted !== '') {
            return self::wanted_property($properties, $wanted, $google);
        }
        $home = (string) home_url('/');
        $best = self::site_property($properties, $home);
        if ($best !== '') {
            return $best;
        }
        if ($google) {
            if (!$properties) {
                return new WP_Error('seoprostats_property_none', __('The Google account you signed in with has no Search Console property. Add this site in Search Console, or sign in with the account that has it.', 'seoprostats'));
            }
            /* translators: %s: the site's address */
            return new WP_Error('seoprostats_property_choose', sprintf(__('None of your Search Console properties is for %s. Choose one.', 'seoprostats'), $home));
        }
        if (!$properties) {
            return new WP_Error('seoprostats_property_none', __('The service account cannot read any Search Console property yet. In Search Console, open the property → Settings → Users and permissions, and add the service account\'s address.', 'seoprostats'));
        }
        /* translators: %s: the site's address */
        return new WP_Error('seoprostats_property_choose', sprintf(__('None of the properties the service account can read is for %s. Choose one, or add the service account to this site\'s property in Search Console.', 'seoprostats'), $home));
    }

    /**
     * The property asked for, when the account can read it.
     *
     * @param array<string,string> $properties From properties().
     * @param string               $wanted     Property asked for.
     * @param bool                 $google     Signed in with Google (else a service account).
     * @return string|WP_Error
     */
    private static function wanted_property(array $properties, $wanted, $google) {
        if (isset($properties[$wanted])) {
            return $wanted;
        }
        if ($google) {
            /* translators: %s: Search Console property */
            return new WP_Error('seoprostats_property_access', sprintf(__('The Google account you signed in with cannot read the property %s. Choose one of its properties, or sign in with an account that is a user of that property.', 'seoprostats'), $wanted));
        }
        /* translators: %s: Search Console property */
        return new WP_Error('seoprostats_property_access', sprintf(__('The service account cannot read the property %s. In Search Console, open the property → Settings → Users and permissions, and add the service account\'s address.', 'seoprostats'), $wanted));
    }

    /**
     * The property for this site: a domain property of its host, else the
     * longest address prefix property it is under; '' for none.
     *
     * @param array<string,string> $properties From properties().
     * @param string               $home       The site's address.
     * @return string
     */
    private static function site_property(array $properties, $home) {
        $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
        $bare = (string) preg_replace('/^www\./', '', $host);
        $site = strtolower((string) preg_replace('#^https?://(www\.)?#', '', $home));
        $best = '';
        foreach (array_keys($properties) as $property) {
            if (strpos($property, 'sc-domain:') === 0) {
                $domain = strtolower(substr($property, 10));
                if ($bare === $domain || substr($bare, -strlen('.' . $domain)) === '.' . $domain) {
                    return $property;
                }
                continue;
            }
            $prefix = strtolower((string) preg_replace('#^https?://(www\.)?#', '', $property));
            if ($prefix !== '' && strpos($site, rtrim($prefix, '/') . '/') === 0 && strlen($property) > strlen($best)) {
                $best = $property;
            }
        }
        return $best;
    }

    /**
     * Today in Search Console's time zone (Y-m-d).
     *
     * @return string
     */
    public static function today() {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }

    /**
     * The last day with final data: the newest day the last ten days'
     * final data has, else five days ago (a property with no searches).
     *
     * @param string $token    Access token.
     * @param string $property Property.
     * @return string|WP_Error Y-m-d.
     */
    public static function final_through($token, $property) {
        $today = new DateTimeImmutable(self::today(), new DateTimeZone('UTC'));
        $days  = self::days($token, $property, $today->modify('-10 days')->format('Y-m-d'), $today->format('Y-m-d'));
        if (is_wp_error($days)) {
            return $days;
        }
        return $days ? max($days) : $today->modify('-5 days')->format('Y-m-d');
    }

    /**
     * The days in a range with final data (any impression), in one request.
     *
     * @param string $token    Access token.
     * @param string $property Property.
     * @param string $from     Y-m-d.
     * @param string $to       Y-m-d.
     * @return string[]|WP_Error Y-m-d, oldest first.
     */
    public static function days($token, $property, $from, $to) {
        $answer = self::query($token, $property, array(
            'startDate'  => $from,
            'endDate'    => $to,
            'dimensions' => array('date'),
            'rowLimit'   => self::PAGE_ROWS,
        ));
        if (is_wp_error($answer)) {
            return $answer;
        }
        $out = array();
        foreach (isset($answer['rows']) && is_array($answer['rows']) ? $answer['rows'] : array() as $row) {
            $day = isset($row['keys'][0]) ? (string) $row['keys'][0] : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $out[] = $day;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * One day's final data: rows by page, query, page and query, and
     * device and country, each as keys, clicks, impressions and position.
     *
     * @param string          $token    Access token.
     * @param string          $property Property.
     * @param string          $day      Y-m-d.
     * @param array<string,int> $limits Most rows per kind (pages, queries, pairs).
     * @return array<string,array<int,array<string,mixed>>>|WP_Error Kind => rows.
     */
    public static function day($token, $property, $day, array $limits) {
        $kinds = array(
            'pages'   => array('page'),
            'queries' => array('query'),
            'pairs'   => array('page', 'query'),
            'totals'  => array('device', 'country'),
        );
        $out   = array();
        foreach ($kinds as $kind => $dimensions) {
            $limit = isset($limits[$kind]) ? max(1, (int) $limits[$kind]) : self::PAGE_ROWS;
            $rows  = array();
            do {
                $size   = min(self::PAGE_ROWS, $limit - count($rows));
                $answer = self::query($token, $property, array(
                    'startDate'  => $day,
                    'endDate'    => $day,
                    'dimensions' => $dimensions,
                    'rowLimit'   => $size,
                    'startRow'   => count($rows),
                ));
                if (is_wp_error($answer)) {
                    return $answer;
                }
                $got  = isset($answer['rows']) && is_array($answer['rows']) ? $answer['rows'] : array();
                $rows = array_merge($rows, $got);
            } while (count($got) === $size && count($rows) < $limit);
            $out[$kind] = $rows;
        }
        return $out;
    }

    /**
     * Discover appearances alone, or read one appearance by date. Each
     * call is one request; the importer keeps the remaining queue.
     *
     * @param string $token Access token.
     * @param string $property Property.
     * @param string $from First final day.
     * @param string $to Last final day.
     * @param string $appearance Empty to discover, else the returned value.
     * @return array<int,array<string,mixed>>|WP_Error API rows.
     */
    public static function appearances($token, $property, $from, $to, $appearance = '') {
        $body = array(
            'startDate' => $from,
            'endDate' => $to,
            'dimensions' => array($appearance === '' ? 'searchAppearance' : 'date'),
            'aggregationType' => 'byPage',
            'dataState' => 'final',
            'rowLimit' => self::PAGE_ROWS,
        );
        if ($appearance !== '') {
            $body['dimensionFilterGroups'] = array(array('filters' => array(array(
                'dimension' => 'searchAppearance',
                'operator' => 'equals',
                'expression' => $appearance,
            ))));
        }
        $answer = self::query($token, $property, $body);
        if (is_wp_error($answer)) {
            return $answer;
        }
        return isset($answer['rows']) && is_array($answer['rows']) ? $answer['rows'] : array();
    }

    /**
     * The sitemaps submitted for the property, as Google read them: path,
     * type, lastSubmitted, lastDownloaded, isPending, isSitemapsIndex,
     * errors, warnings and contents (type, submitted; indexed is
     * deprecated and not kept).
     *
     * @param string $token    Access token.
     * @param string $property Property.
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public static function sitemaps($token, $property) {
        $answer = self::request('GET', 'sites/' . rawurlencode($property) . '/sitemaps', $token);
        if (is_wp_error($answer)) {
            return $answer;
        }
        return isset($answer['sitemap']) && is_array($answer['sitemap']) ? array_values(array_filter($answer['sitemap'], 'is_array')) : array();
    }

    /**
     * Inspect one address: the version in Google's index (inspectionResult:
     * indexStatusResult, richResultsResult, inspectionResultLink).
     *
     * @param string $token    Access token.
     * @param string $property Property.
     * @param string $url      Address on the property.
     * @return array<string,mixed>|WP_Error inspectionResult.
     */
    public static function inspect($token, $property, $url) {
        $answer = self::request('POST', self::INSPECT_URL, $token, array(
            'inspectionUrl' => (string) $url,
            'siteUrl'       => (string) $property,
            'languageCode'  => self::INSPECT_LANGUAGE,
        ));
        if (is_wp_error($answer)) {
            return $answer;
        }
        return isset($answer['inspectionResult']) && is_array($answer['inspectionResult']) ? $answer['inspectionResult'] : array();
    }

    /**
     * A Search Analytics query.
     *
     * @param string              $token    Access token.
     * @param string              $property Property.
     * @param array<string,mixed> $body     Query.
     * @return array<string,mixed>|WP_Error
     */
    private static function query($token, $property, array $body) {
        return self::request('POST', 'sites/' . rawurlencode($property) . '/searchAnalytics/query', $token, $body);
    }

    /**
     * A request to the Search Console API.
     *
     * @param string                   $method GET or POST.
     * @param string                   $path   Path after API, or a full address of the same service.
     * @param string                   $token  Access token.
     * @param array<string,mixed>|null $body   JSON body.
     * @return array<string,mixed>|WP_Error
     */
    private static function request($method, $path, $token, $body = null) {
        $args = array(
            'method'     => $method,
            'timeout'    => self::TIMEOUT,
            'user-agent' => self::user_agent(),
            'headers'    => array('Authorization' => 'Bearer ' . $token),
        );
        if ($body !== null) {
            $json = wp_json_encode($body);
            if ($json === false) {
                return new WP_Error('seoprostats_google_request', __('The request to Google could not be encoded.', 'seoprostats'));
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = $json;
        }
        return self::answer(wp_remote_request(strpos($path, 'https://') === 0 ? $path : self::API . $path, $args));
    }

    /**
     * A JSON answer, or Google's error as a WP_Error.
     *
     * @param array<string,mixed>|WP_Error $response Response.
     * @return array<string,mixed>|WP_Error
     */
    private static function answer($response) {
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($data)) {
            return $data;
        }
        $message = '';
        if (is_array($data) && isset($data['error']['message'])) {
            $message = (string) $data['error']['message'];
        } elseif (is_array($data) && isset($data['error_description'])) {
            $message = (string) $data['error_description'];
        } elseif (is_array($data) && isset($data['error']) && is_string($data['error'])) {
            $message = $data['error'];
        }
        /* translators: 1: HTTP status code, 2: Google's message */
        return new WP_Error('seoprostats_google_' . $code, sprintf(__('HTTP %1$d: %2$s', 'seoprostats'), $code, $message !== '' ? sanitize_text_field($message) : __('no message', 'seoprostats')), array('status' => $code));
    }

    /**
     * Base64url without padding.
     *
     * @param string $data Bytes.
     * @return string
     */
    private static function base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
    }

    /**
     * The user agent: the plugin and its version, not the site.
     *
     * @return string
     */
    private static function user_agent() {
        return 'SEO Pro Stats/' . SEOPROSTATS_VERSION . ' (WordPress plugin)';
    }
}
