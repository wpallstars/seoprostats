<?php
/**
 * DataForSEO's free account check. Paid imports are not enabled yet.
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

final class SEOProStats_Source_Dataforseo {

    const NAME = 'DataForSEO';
    const SOURCE = 'dataforseo';
    const API = 'https://api.dataforseo.com/v3/';
    const SPEND_OPTION = 'seoprostats_dataforseo_spend';

    /**
     * Validate credentials with the free account endpoint.
     *
     * @param array<string,mixed>      $input Fields.
     * @param array<string,mixed>|null $saved Existing credentials.
     * @return array<string,mixed>|WP_Error
     */
    public static function connect(array $input, $saved = null) {
        $credentials = array(
            'login'    => isset($input['login']) && $input['login'] !== '' ? trim((string) $input['login']) : (string) ($saved['login'] ?? ''),
            'password' => isset($input['password']) && $input['password'] !== '' ? (string) $input['password'] : (string) ($saved['password'] ?? ''),
        );
        if ($credentials['login'] === '' || $credentials['password'] === '' || strpos($credentials['login'], ':') !== false) {
            return new WP_Error('seoprostats_dataforseo_credentials', __('Enter the API login and password.', 'seoprostats'));
        }
        $before = SEOProStats_Connections::get(self::SOURCE);
        $limit = $input['monthly_limit'] ?? ($before['settings']['monthly_limit'] ?? 5);
        if (!is_numeric($limit) || !is_finite((float) $limit) || (float) $limit < 0 || (float) $limit > 1000) {
            return new WP_Error('seoprostats_dataforseo_limit', __('The monthly limit must be between $0 and $1,000.', 'seoprostats'));
        }
        $account = self::http($credentials);
        if (is_wp_error($account)) {
            return $account;
        }
        return array(
            'credentials' => $credentials,
            'settings'    => array('monthly_limit' => (float) $limit),
            'state'       => array('balance' => (float) ($account['money']['balance'] ?? 0)),
        );
    }

    /**
     * Read this month's local charges. Disconnecting does not erase them.
     * Unknown charges retain their reservation, never silently freeing budget.
     *
     * @return array{month:string,spent:float,reserved:float,limit:float,left:float}
     */
    public static function spend() {
        $month = gmdate('Y-m');
        $saved = get_option(self::SPEND_OPTION, array());
        $saved = is_array($saved) && ($saved['month'] ?? '') === $month ? $saved : array();
        $conn  = SEOProStats_Connections::get(self::SOURCE);
        $limit = (float) ($conn['settings']['monthly_limit'] ?? 5);
        $spent = (int) ($saved['spent'] ?? 0) / 1000000;
        $held  = (int) ($saved['reserved'] ?? 0) / 1000000;
        return array('month' => $month, 'spent' => $spent, 'reserved' => $held, 'limit' => $limit, 'left' => max(0, $limit - $spent - $held));
    }

    /**
     * Credential-free status; no remote request.
     *
     * @return array<string,mixed>
     */
    public static function status() {
        $conn = SEOProStats_Connections::get(self::SOURCE);
        return array(
            'source'    => self::SOURCE,
            'name'      => self::NAME,
            'connected' => (bool) $conn,
            'spend'     => self::spend(),
            'balance'   => $conn['state']['balance'] ?? null,
            'last_run'  => (int) ($conn['state']['last_run'] ?? 0),
            'summary'   => $conn['state']['summary'] ?? null,
            'error'     => (string) ($conn['state']['error'] ?? ''),
        );
    }

    /**
     * Free fixed-host account request, no redirects or raw service errors.
     *
     * @param array<string,mixed> $credentials Login and password.
     * @return array<string,mixed>|WP_Error
     */
    private static function http(array $credentials) {
        $args = array(
            'method'      => 'GET',
            'timeout'     => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- owner-triggered account check or cron import, never a visitor request.
            'redirection' => 0,
            'limit_response_size' => 4194304,
            'headers'     => array(
                'Authorization' => 'Basic ' . base64_encode((string) $credentials['login'] . ':' . (string) $credentials['password']), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic authentication sent only over TLS to the fixed provider host.
                'Content-Type'  => 'application/json',
            ),
        );
        $response = wp_remote_request(self::API . 'appendix/user_data', $args);
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($body)) {
                if (wp_remote_retrieve_response_code($response) === 200 && ($body['status_code'] ?? 0) === 20000 && ($body['tasks'][0]['status_code'] ?? 0) === 20000 && isset($body['tasks'][0]['result'][0]) && is_array($body['tasks'][0]['result'][0])) {
                    return $body['tasks'][0]['result'][0];
                }
            }
        }
        return new WP_Error('seoprostats_dataforseo_request', __('DataForSEO could not complete the account check. Check the API credentials and service status.', 'seoprostats'));
    }
}
