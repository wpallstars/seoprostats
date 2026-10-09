<?php
/**
 * DataForSEO's account and bounded paid requests. Never used on visitor pages.
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
    const LOCK_OPTION = 'seoprostats_dataforseo_lock';

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
        $limit = $input['monthly_limit'] ?? 5;
        if (!is_numeric($limit) || !is_finite((float) $limit) || (float) $limit < 0 || (float) $limit > 1000) {
            return new WP_Error('seoprostats_dataforseo_limit', __('The monthly limit must be between $0 and $1,000.', 'seoprostats'));
        }
        $account = self::http('appendix/user_data', $credentials);
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
     * Send one allowlisted paid task, reserving the current public maximum.
     * The caller holds the provider lock for the entire import. No retries:
     * a timeout may already have been billed. Integer microdollars avoid
     * floating-point under-reservations.
     *
     * @param string              $path API path.
     * @param array<string,mixed> $task One task.
     * @return array<string,mixed>|WP_Error
     */
    public static function request($path, array $task) {
        $caps = array('backlinks/backlinks/live' => 60000, 'backlinks/summary/live' => 25000, 'serp/google/organic/live/advanced' => 2000);
        if (!isset($caps[$path]) || !get_option(self::LOCK_OPTION)) {
            return new WP_Error('seoprostats_dataforseo_lock', __('A provider run must hold the spend lock.', 'seoprostats'));
        }
        $credentials = SEOProStats_Connections::credentials(self::SOURCE);
        if (is_wp_error($credentials)) {
            return $credentials;
        }
        $spend = self::spend();
        $cap   = $caps[$path];
        if ((int) round($spend['left'] * 1000000) < $cap) {
            return new WP_Error('seoprostats_dataforseo_budget', __('The monthly spend limit stops this request. Raise the limit or wait until next month.', 'seoprostats'));
        }
        $ledger = array('month' => $spend['month'], 'spent' => (int) round($spend['spent'] * 1000000), 'reserved' => (int) round($spend['reserved'] * 1000000) + $cap);
        if (!update_option(self::SPEND_OPTION, $ledger, false)) {
            return new WP_Error('seoprostats_dataforseo_ledger', __('The spend reservation could not be saved.', 'seoprostats'));
        }
        $cost = null;
        $got  = self::http($path, $credentials, $task, $cost);
        if ($cost !== null) {
            $ledger['spent'] += (int) ceil($cost * 1000000);
            $ledger['reserved'] -= $cap;
            update_option(self::SPEND_OPTION, $ledger, false);
        }
        return $got;
    }

    /**
     * Fixed-host HTTP, no redirects or raw service errors in public output.
     * Cost is read even for task-level failures and counted exactly once.
     *
     * @param string                   $path API path.
     * @param array<string,mixed>      $credentials Login and password.
     * @param array<string,mixed>|null $task Paid task, null for free GET.
     * @param float|null               $cost Returned cost, null if unknown.
     * @return array<string,mixed>|WP_Error
     */
    private static function http($path, array $credentials, $task = null, &$cost = null) {
        $args = array(
            'method'      => $task === null ? 'GET' : 'POST',
            'timeout'     => 15,
            'redirection' => 0,
            'limit_response_size' => 4194304,
            'headers'     => array(
                'Authorization' => 'Basic ' . base64_encode((string) $credentials['login'] . ':' . (string) $credentials['password']), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic authentication sent only over TLS to the fixed provider host.
                'Content-Type'  => 'application/json',
            ),
        );
        if ($task !== null) {
            $args['body'] = wp_json_encode(array($task));
        }
        $response = wp_remote_request(self::API . $path, $args);
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($body)) {
                if (isset($body['cost']) && is_numeric($body['cost']) && is_finite((float) $body['cost']) && (float) $body['cost'] >= 0) {
                    $cost = (float) $body['cost'];
                }
                if (wp_remote_retrieve_response_code($response) === 200 && ($body['status_code'] ?? 0) === 20000 && ($body['tasks'][0]['status_code'] ?? 0) === 20000 && isset($body['tasks'][0]['result'][0]) && is_array($body['tasks'][0]['result'][0])) {
                    return $body['tasks'][0]['result'][0];
                }
            }
        }
        return new WP_Error('seoprostats_dataforseo_request', __('DataForSEO could not complete the request. Check the API credentials, account balance and service status. An unknown charge stays reserved.', 'seoprostats'));
    }
}
