<?php
/**
 * Google Search Console, connected with a service account's JSON key: the
 * owner makes the account in Google Cloud and adds its address as a user
 * of their Search Console property. No OAuth app or outside server.
 *
 * The key signs a short-lived token request (RS256, PHP's OpenSSL) to
 * Google's fixed token address, with read-only access to Search Console.
 * Requests come only from cron, WP-CLI or an administrator's action.
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

    /** Read-only access. */
    const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** Months of history Search Console keeps, imported on connecting. */
    const MONTHS = 16;

    /** Search Console's days are Pacific time. */
    const TIMEZONE = 'America/Los_Angeles';

    /** Rows per request (the API's most). */
    const PAGE_ROWS = 25000;

    /** Seconds a request may take. */
    const TIMEOUT = 30;

    /** @var array<string,array{token:string,expires:int}> Access tokens by account, for this request. */
    private static $tokens = array();

    /**
     * Check what the owner gave (a key, and maybe a property) by signing
     * in and listing the properties, and say what to store.
     *
     * @param array<string,mixed>      $input    key (the JSON key's text; '' keeps the saved one), property ('' to choose).
     * @param array<string,mixed>|null $existing Saved credentials, or null.
     * @return array{credentials:array<string,mixed>,settings:array<string,mixed>,properties:array<string,string>}|WP_Error
     */
    public static function connect(array $input, $existing) {
        $json = isset($input['key']) ? trim((string) $input['key']) : '';
        if ($json !== '') {
            $key = self::parse_key($json);
        } elseif (is_array($existing)) {
            $key = $existing;
        } else {
            $key = new WP_Error('seoprostats_key_missing', __('Paste the service account\'s JSON key.', 'seoprostats'));
        }
        if (is_wp_error($key)) {
            return $key;
        }
        $token = self::token($key);
        if (is_wp_error($token)) {
            return $token;
        }
        $properties = self::properties($token);
        if (is_wp_error($properties)) {
            return $properties;
        }
        $property = self::pick_property($properties, isset($input['property']) ? (string) $input['property'] : '');
        if (is_wp_error($property)) {
            $property->add_data(array('status' => 400, 'properties' => array_keys($properties), 'account' => $key['client_email']));
            return $property;
        }
        return array(
            'credentials' => $key,
            'settings'    => array(
                'property' => $property,
                'account'  => (string) $key['client_email'],
            ),
            'properties'  => $properties,
        );
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
     * An access token for the account (one per hour, kept for the request).
     *
     * @param array<string,mixed> $key From parse_key().
     * @return string|WP_Error
     */
    public static function token(array $key) {
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
        $pkey   = function_exists('openssl_pkey_get_private') ? openssl_pkey_get_private(isset($key['private_key']) ? (string) $key['private_key'] : '') : false;
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
     * @return string|WP_Error
     */
    public static function pick_property(array $properties, $wanted = '') {
        $wanted = trim((string) $wanted);
        if ($wanted !== '') {
            if (isset($properties[$wanted])) {
                return $wanted;
            }
            /* translators: %s: Search Console property */
            return new WP_Error('seoprostats_property_access', sprintf(__('The service account cannot read the property %s. In Search Console, open the property → Settings → Users and permissions, and add the service account\'s address.', 'seoprostats'), $wanted));
        }
        $home = (string) home_url('/');
        $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
        $bare = (string) preg_replace('/^www\./', '', $host);
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
            $site   = strtolower((string) preg_replace('#^https?://(www\.)?#', '', $home));
            if ($prefix !== '' && strpos($site, rtrim($prefix, '/') . '/') === 0 && strlen($property) > strlen($best)) {
                $best = $property;
            }
        }
        if ($best !== '') {
            return $best;
        }
        if (!$properties) {
            return new WP_Error('seoprostats_property_none', __('The service account cannot read any Search Console property yet. In Search Console, open the property → Settings → Users and permissions, and add the service account\'s address.', 'seoprostats'));
        }
        /* translators: %s: the site's address */
        return new WP_Error('seoprostats_property_choose', sprintf(__('None of the properties the service account can read is for %s. Choose one, or add the service account to this site\'s property in Search Console.', 'seoprostats'), $home));
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
        return is_wp_error($answer) ? $answer : (isset($answer['rows']) && is_array($answer['rows']) ? $answer['rows'] : array());
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
     * @param string                   $path   Path after API.
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
        return self::answer(wp_remote_request(self::API . $path, $args));
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
