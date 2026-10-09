<?php
/**
 * Chrome UX Report field data; requests only from owner actions and cron.
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

final class SEOProStats_Source_Crux {
    const NAME = 'Chrome UX Report';
    const API = 'https://chromeuxreport.googleapis.com/v1/records:';
    const PSI = 'https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed';
    // Background field reads are bounded; an explicit Lighthouse run can take longer.
    const TIMEOUT = 10;
    const LAB_TIMEOUT = 60;
    const METRICS = array(
        'lcp' => 'largest_contentful_paint',
        'inp' => 'interaction_to_next_paint',
        'cls' => 'cumulative_layout_shift',
        'fcp' => 'first_contentful_paint',
        'ttfb' => 'experimental_time_to_first_byte',
    );

    /**
     * Check the key against this site's origin; no record is not a bad key.
     *
     * @param array<string,mixed> $input Input.
     * @param array<string,mixed>|null $existing Saved credentials.
     * @return array<string,mixed>|WP_Error
     */
    public static function connect(array $input, $existing) {
        $key = trim((string) ($input['key'] ?? ($existing['key'] ?? '')));
        if ($key === '' && is_array($existing)) {
            $key = (string) ($existing['key'] ?? '');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/', $key)) {
            return new WP_Error('seoprostats_crux_key', __('Enter a Google Cloud API key with the Chrome UX Report API enabled.', 'seoprostats'));
        }
        $origin = self::origin();
        $check = self::query($key, $origin, 'PHONE', true);
        if (is_wp_error($check)) {
            return $check;
        }
        return array(
            'credentials' => array('key' => $key),
            'settings' => array('property' => $origin, 'account' => '', 'pages' => max(0, min(1000, (int) ($input['pages'] ?? 100)))),
            'properties' => array($origin => 'origin'),
        );
    }

    /** @return string This site's origin, without a path. */
    public static function origin() {
        $parts = wp_parse_url(home_url('/'));
        return (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Read current or historical data. An empty array means insufficient data.
     *
     * @param string $key Key.
     * @param string $url Origin or URL.
     * @param string $form PHONE or DESKTOP.
     * @param bool $origin Origin query.
     * @param bool $history History query.
     * @return array<string,mixed>|WP_Error
     */
    public static function query($key, $url, $form, $origin = false, $history = false) {
        $body = array($origin ? 'origin' : 'url' => $url, 'formFactor' => $form, 'metrics' => array_values(self::METRICS));
        $json = wp_json_encode($body);
        if ($json === false) {
            return new WP_Error('seoprostats_crux_input', __('The field-data request could not be encoded.', 'seoprostats'));
        }
        $response = wp_remote_post(self::API . ($history ? 'queryHistoryRecord' : 'queryRecord'), array(
            'timeout' => self::TIMEOUT,
            'headers' => array('Content-Type' => 'application/json', 'X-Goog-Api-Key' => $key),
            'body' => $json,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('seoprostats_crux_request', __('Chrome UX Report could not be reached.', 'seoprostats'));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return array();
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($data) && isset($data['record']) && is_array($data['record'])) {
            return $data['record'];
        }
        /* translators: %d: HTTP status */
        return new WP_Error('seoprostats_crux_response', sprintf(__('Chrome UX Report returned HTTP %d. Check the API key, API access and quota.', 'seoprostats'), $code));
    }

    /**
     * Owner-triggered Lighthouse lab report, never a field-data fallback.
     *
     * @param string $url Local page URL.
     * @param string $key Key.
     * @return array<string,mixed>|WP_Error
     */
    public static function lighthouse($url, $key) {
        $response = wp_remote_get(add_query_arg(array('url' => $url, 'strategy' => 'mobile', 'category' => 'performance'), self::PSI), array(
            'timeout' => self::LAB_TIMEOUT,
            'headers' => array('X-Goog-Api-Key' => $key),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('seoprostats_psi_request', __('The Lighthouse request could not be completed.', 'seoprostats'));
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ((int) wp_remote_retrieve_response_code($response) !== 200 || !is_array($data) || empty($data['lighthouseResult'])) {
            return new WP_Error('seoprostats_psi_response', __('PageSpeed Insights did not return a Lighthouse report. Check API access and quota.', 'seoprostats'));
        }
        $result = $data['lighthouseResult'];
        $opportunities = array();
        foreach ($result['audits'] ?? array() as $id => $audit) {
            if (is_array($audit) && isset($audit['score']) && is_numeric($audit['score']) && (float) $audit['score'] < 1 && !empty($audit['details']['type']) && $audit['details']['type'] === 'opportunity') {
                $opportunities[] = array('id' => (string) $id, 'title' => (string) ($audit['title'] ?? ''), 'display' => (string) ($audit['displayValue'] ?? ''), 'saved_ms' => (float) ($audit['details']['overallSavingsMs'] ?? 0));
            }
        }
        return array('source' => 'lighthouse', 'url' => $url, 'score' => $result['categories']['performance']['score'] ?? null, 'opportunities' => $opportunities);
    }
}
