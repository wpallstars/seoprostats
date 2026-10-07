<?php
/**
 * Shop-test fixture, copied only into a disposable localhost Docker site.
 * Never install this must-use plugin on a real site.
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

add_filter('pre_wp_mail', '__return_true');
add_action('doing_it_wrong_run', static function ($function, $message) {
    if ($function === '_load_textdomain_just_in_time') {
        error_log('shop-test translation: ' . $message . ' TRACE ' . wp_debug_backtrace_summary());
    }
}, 10, 2);

// EDD's admin installer has not run on a fresh CLI-only installation. Build
// its tables at activation, before the next request tries to schedule logs.
add_action('activated_plugin', static function ($plugin) {
    if (strpos($plugin, 'easy-digital-downloads/') === 0 && function_exists('edd_install_component_database_tables')) {
        edd_install_component_database_tables();
        edd_run_install();
    }
});

/** Configure shops using their own APIs, without creating purchase events. */
function seoprostats_shop_test_setup() {
    SEOProStats_Settings::set('purchases_thrivecart', 'shop-test-only');
    // Cron is disabled and no admin page has run the initial schedule yet.
    SEOProStats_Collection::rotate_salts();
    SEOProStats_Collection::write_config();
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', SEOProStats_Collection::CRON_HOOK);
    wp_schedule_event(time() + MINUTE_IN_SECONDS, 'seoprostats_minute', SEOProStats_Collection::PROCESS_HOOK);
    if (function_exists('WC')) {
        update_option('woocommerce_currency', 'USD');
        update_option('woocommerce_calc_taxes', 'no');
        update_option('woocommerce_cod_settings', array('enabled' => 'yes'));
        update_option('woocommerce_bacs_settings', array('enabled' => 'yes'));
        $product = new WC_Product_Simple();
        $product->set_name('Shop test');
        $product->set_regular_price('19.99');
        $product->set_virtual(true);
        $product->set_status('publish');
        update_option('seoprostats_shop_test_product', $product->save(), false);
    }
    if (function_exists('edd_build_order')) {
        edd_update_option('currency', 'EUR');
        edd_update_option('enable_taxes', false);
        $product = wp_insert_post(array('post_type' => 'download', 'post_status' => 'publish', 'post_title' => 'Shop test download'));
        update_post_meta($product, 'edd_price', '49.00');
        update_option('seoprostats_shop_test_download', $product, false);
    }
}

// Run after the shops and the statistics plugin have registered their hooks.
add_action('template_redirect', static function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- localhost-only disposable fixture; never shipped.
    $action = isset($_GET['spst_test']) ? sanitize_key(wp_unslash($_GET['spst_test'])) : '';
    if ($action === '') {
        return;
    }
    if ($action === 'edd') {
        $product = (int) get_option('seoprostats_shop_test_download');
        $id = edd_build_order(array(
            'user_email' => 'shop@example.com',
            'user_info' => array('id' => 0, 'email' => 'shop@example.com', 'first_name' => 'Shop', 'last_name' => 'Test'),
            'downloads' => array(array('id' => $product, 'options' => array(), 'quantity' => 1)),
            'cart_details' => array(array('id' => $product, 'name' => 'Shop test download', 'item_number' => array('id' => $product, 'options' => array()), 'item_price' => 49, 'quantity' => 1, 'price' => 49, 'subtotal' => 49, 'tax' => 0, 'discount' => 0)),
            'price' => 49, 'subtotal' => 49, 'tax' => 0, 'discount' => 0,
            'currency' => 'EUR', 'status' => 'pending', 'gateway' => 'manual',
        ));
        if (!$id || is_wp_error($id)) {
            wp_send_json_error('EDD did not build an order', 500);
        }
        wp_send_json(array('order_id' => $id));
    }
    if ($action === 'edd_pay') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- localhost-only disposable fixture; never shipped.
        $id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        edd_update_order_status($id, 'complete');
        wp_send_json(array('order_id' => $id));
    }
    if ($action === 'fluentcart') {
        $customer = \FluentCart\App\Models\Customer::create(array(
            'email' => 'shop@example.com', 'first_name' => 'Shop', 'last_name' => 'Test',
        ));
        $order = \FluentCart\App\Models\Order::create(array(
            'status' => 'processing', 'payment_status' => 'pending', 'type' => 'payment',
            'currency' => 'GBP', 'subtotal' => 2500, 'total_amount' => 2500, 'customer_id' => $customer->id,
        ));
        (new \FluentCart\App\Events\Order\OrderCreated($order))->dispatch();
        $order->payment_status = 'paid';
        $order->save();
        (new \FluentCart\App\Events\Order\OrderPaid($order))->dispatch();
        (new \FluentCart\App\Events\Order\OrderPaid($order))->dispatch();
        wp_send_json(array('order_id' => $order->id));
    }
    wp_send_json_error('Unknown shop action', 400);
});

/** Assert raw facts as well as their source properties and visit joins. */
function seoprostats_shop_test_assert() {
    global $wpdb;
    require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-channels.php';
    $expected = array('thrivecart' => array(1, 99700, 'USD'));
    if (function_exists('WC')) {
        $expected['woocommerce'] = array(2, 7996, 'USD');
    }
    if (function_exists('edd_build_order')) {
        $expected['edd'] = array(1, 4900, 'EUR');
    }
    if (class_exists('FluentCart\\App\\Models\\Order')) {
        $expected['fluentcart'] = array(1, 2500, 'GBP');
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded disposable fixture tables only.
    $sessions = $wpdb->get_results($wpdb->prepare('SELECT id, pageviews, channel FROM %i LIMIT 10', SEOProStats_Schema::table('sessions')));
    if (count($sessions) !== 1 || (int) $sessions[0]->pageviews !== 2 || (int) $sessions[0]->channel !== SEOProStats_Channels::EMAIL) {
        WP_CLI::error('FAIL visit: expected one email campaign visit with two pageviews: ' . wp_json_encode($sessions));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded disposable fixture tables only.
    $events = $wpdb->get_results($wpdb->prepare('SELECT e.*, n.value AS name, v.value AS source FROM %i e JOIN %i n ON n.id = e.name_id LEFT JOIN %i p ON p.owner = %d AND p.owner_id = e.id AND p.key_id = (SELECT id FROM %i WHERE kind = %d AND value = %s) LEFT JOIN %i v ON v.id = p.value_id LIMIT 20',
        SEOProStats_Schema::table('events'), SEOProStats_Schema::table('dict'), SEOProStats_Schema::table('props'), SEOProStats_Schema::OWNER_EVENT,
        SEOProStats_Schema::table('dict'), SEOProStats_Schema::DICT_PROP_KEY, 'source', SEOProStats_Schema::table('dict')));
    $actual = array();
    foreach ($events as $event) {
        if ($event->name !== 'Purchase' || (int) $event->session_id !== (int) $sessions[0]->id || (int) $event->seq <= 2 || !isset($expected[$event->source])) {
            WP_CLI::error('FAIL purchase visit, sequence, name or source: ' . wp_json_encode($event));
        }
        if ($event->currency !== $expected[$event->source][2]) {
            WP_CLI::error('FAIL ' . $event->source . ' currency: ' . $event->currency);
        }
        if ((int) $event->revenue !== (int) ($expected[$event->source][1] / $expected[$event->source][0])) {
            WP_CLI::error('FAIL ' . $event->source . ' order amount in cents: ' . $event->revenue);
        }
        if (!isset($actual[$event->source])) {
            $actual[$event->source] = array(0, 0);
        }
        $actual[$event->source][0]++;
        $actual[$event->source][1] += (int) $event->revenue;
    }
    foreach ($expected as $source => $values) {
        $found = isset($actual[$source]) ? $actual[$source] : array(0, 0);
        if ($found !== array($values[0], $values[1])) {
            WP_CLI::error(sprintf('FAIL %s: expected %d purchases / %d cents %s, got %d / %d', $source, $values[0], $values[1], $values[2], $found[0], $found[1]));
        }
        WP_CLI::log(sprintf('PASS %s: %d purchases, %.2f %s, joined to campaign visit', $source, $found[0], $found[1] / 100, $values[2]));
    }
}

/** Fail on PHP messages except WooCommerce's known independent notice. */
function seoprostats_shop_test_log() {
    // A canary proves WP_DEBUG_LOG works; it is the only fixture message ignored.
    error_log('shop-test debug canary');
    $log = file_get_contents(WP_CONTENT_DIR . '/debug.log');
    if (!is_string($log) || strpos($log, 'shop-test debug canary') === false) {
        WP_CLI::error('FAIL debug log canary missing');
    }
    $allowed = 0;
    foreach (explode("\n", $log) as $line) {
        if ($line === '' || strpos($line, 'shop-test debug canary') !== false) {
            continue;
        }
        if (strpos($line, '_load_textdomain_just_in_time') !== false && strpos($line, 'woocommerce') !== false && strpos($line, 'seoprostats') === false) {
            $allowed++;
            continue;
        }
        WP_CLI::error('FAIL PHP log: ' . $line);
    }
    WP_CLI::log('PASS PHP log; allowed WooCommerce early-translation lines: ' . $allowed);
}
