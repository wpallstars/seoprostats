<?php
/**
 * Fast collector endpoint: receives the tracker's hits without loading
 * WordPress, so a visit costs about a millisecond of PHP.
 *
 * It reads the site's collector config from
 * wp-content/seoprostats/site-{s}/config.php (written by WordPress) and
 * hands the request to SEOProStats_Collector, the same code the REST route
 * runs. The tracker uses this file only after WordPress's loopback test
 * found it working; otherwise it posts to the REST route. Design:
 * docs/architecture.md → Collection → Collector.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.2.0
 */

define('SEOPROSTATS_COLLECTOR', true);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// Runs without WordPress (no nonces or sanitising functions); each value is checked where it is used.
$seoprostats_get    = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public endpoint without WordPress.
$seoprostats_server = $_SERVER;
$seoprostats_site   = isset($seoprostats_get['s']) && is_string($seoprostats_get['s']) ? $seoprostats_get['s'] : '1';
if (!ctype_digit($seoprostats_site) || strlen($seoprostats_site) > 9) {
    http_response_code(400);
    exit;
}

// This file is wp-content/plugins/seoprostats/collect.php.
$seoprostats_dir    = dirname(__DIR__, 2) . '/seoprostats/site-' . (int) $seoprostats_site;
$seoprostats_config = is_file($seoprostats_dir . '/config.php') ? include $seoprostats_dir . '/config.php' : null;
if (!is_array($seoprostats_config)) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/stats/class-seoprostats-collector.php';

$seoprostats_method = isset($seoprostats_server['REQUEST_METHOD']) ? (string) $seoprostats_server['REQUEST_METHOD'] : '';

// Loopback test from WordPress: GET ?s=1&ping=<random>.
if ($seoprostats_method === 'GET' && isset($seoprostats_get['ping']) && is_string($seoprostats_get['ping'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo SEOProStats_Collector::ping($seoprostats_config, substr($seoprostats_get['ping'], 0, 64)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a hex HMAC.
    exit;
}

if ($seoprostats_method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$seoprostats_body = file_get_contents('php://input', false, null, 0, SEOProStats_Collector::MAX_BODY + 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the request body, without WordPress.
http_response_code(SEOProStats_Collector::handle(
    $seoprostats_config,
    $seoprostats_dir,
    is_string($seoprostats_body) ? $seoprostats_body : '',
    $seoprostats_server,
    time()
));
