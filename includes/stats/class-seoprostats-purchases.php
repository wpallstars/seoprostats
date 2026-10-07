<?php
/**
 * Purchases: paid orders from WooCommerce, Easy Digital Downloads,
 * FluentCart and ThriveCart become one Purchase event each, with the
 * order's total in its currency, on the visit that checked out.
 *
 * Shops on the site: when an order is placed in the customer's own
 * checkout request, the visitor hash is worked out as the collector does
 * (same daily salt, address, user agent and host, same exclusions) and
 * kept in the order's meta with the time. When the order is paid, on the
 * spot or later, one event line goes into the buffer with that hash and
 * time, so the processor joins it to the visit like any event; the meta
 * is then replaced by a recorded mark, so the order counts once. Orders
 * made in wp-admin, by cron (renewals) or through an API have no hash
 * and are left out: they are not site visits.
 *
 * ThriveCart (checkout on its own domain): the tracker adds the page
 * load's random ID to ThriveCart links as passthrough[spst]; ThriveCart's
 * order webhook posts it back to the thrivecart REST route, which checks
 * the secret word and joins the order to that page load's visit.
 *
 * No order number, customer name, email or address is stored in the
 * statistics. Design: docs/architecture.md → Purchases.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Purchases {

    /** Event name. */
    const EVENT = 'Purchase';

    /** Order meta: the checkout visit (visitor hash, time, user agent, country, host, path). */
    const META_VISIT = '_seoprostats_visit';

    /** Order meta: when the purchase was recorded. */
    const META_DONE = '_seoprostats_recorded';

    /** ThriveCart orders recorded lately, and counts (autoload off). */
    const STATE_OPTION = 'seoprostats_purchases';

    /** ThriveCart order IDs remembered, so a retried webhook counts once. */
    const KEEP_ORDERS = 500;

    /** The passthrough field ThriveCart sends back. */
    const PASSTHROUGH = 'spst';

    /** @var array<string,bool> Orders recorded in this request. */
    private static $done = array();

    /**
     * Register hooks. Each only runs when its shop calls it.
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_route'));
        // The settings are read on init: their labels are translated.
        add_action('init', array(__CLASS__, 'shop_hooks'), 1);
    }

    /**
     * The shops' order hooks, when purchases are recorded.
     */
    public static function shop_hooks() {
        if (!self::enabled()) {
            return;
        }

        // WooCommerce: classic and block checkout; paid.
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'woo_checkout'), 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'woo_checkout'), 10, 1);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'woo_paid'), 10, 1);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'woo_paid'), 10, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'woo_paid'), 10, 1);

        // Easy Digital Downloads 3: checkout; paid.
        add_action('edd_built_order', array(__CLASS__, 'edd_checkout'), 10, 1);
        add_action('edd_complete_purchase', array(__CLASS__, 'edd_paid'), 10, 1);

        // FluentCart: checkout; paid.
        add_action('fluent_cart/order_created', array(__CLASS__, 'fluentcart_checkout'), 10, 1);
        add_action('fluent_cart/order_paid', array(__CLASS__, 'fluentcart_paid'), 10, 1);
    }

    /**
     * Whether purchases are recorded (Settings → Tracking).
     *
     * @return bool
     */
    public static function enabled() {
        return SEOProStats_Statistics::collecting() && (bool) SEOProStats_Settings::get('purchases');
    }

    /**
     * The ThriveCart secret word, or '' when ThriveCart is not connected.
     *
     * @return string
     */
    public static function thrivecart_secret() {
        return self::enabled() ? trim((string) SEOProStats_Settings::get('purchases_thrivecart')) : '';
    }

    /**
     * The webhook address to paste into ThriveCart (works with any
     * permalink setting).
     *
     * @return string
     */
    public static function thrivecart_url() {
        return home_url('/?rest_route=/' . SEOProStats_Collection::REST_NAMESPACE . '/thrivecart');
    }

    // ------------------------------------------------------------------
    // The checkout visit.

    /**
     * The current request's visit, as the collector would count it: null
     * when it would store nothing (collection off, no salt, an excluded
     * address or role, Do Not Track or Global Privacy Control when they
     * are respected, a host that is not the site's) or when this is not a
     * customer's own request (wp-admin, cron, WP-CLI).
     *
     * @return array{v:string,ts:int,ua:string,cc:string,h:string,u:string}|null
     */
    public static function current_visit() {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }
        if (is_user_logged_in() && array_intersect((array) wp_get_current_user()->roles, SEOProStats_Statistics::skip_roles())) {
            return null;
        }
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared and hashed only, as the collector does; never printed.
        if (SEOProStats_Statistics::respect_signals() && ((isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') || (isset($_SERVER['HTTP_SEC_GPC']) && (string) $_SERVER['HTTP_SEC_GPC'] === '1'))) {
            return null;
        }
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-collector.php';
        $config = SEOProStats_Collection::config();
        if (!empty($config['off'])) {
            return null;
        }
        $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : '';
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST'])) : '';
        $ref  = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        // phpcs:enable
        $ip = SEOProStats_Collector::client_ip($config, $_SERVER);
        if ($ua === '' || $ip === '' || !in_array($host, isset($config['hosts']) ? (array) $config['hosts'] : array(), true)) {
            return null;
        }
        foreach (isset($config['exclude_ips']) ? (array) $config['exclude_ips'] : array() as $range) {
            if (SEOProStats_Collector::ip_in_range($ip, (string) $range)) {
                return null;
            }
        }
        $now  = time();
        $salt = SEOProStats_Collector::salt($config, $now);
        if ($salt === '') {
            return null;
        }
        // The checkout page (a block checkout posts from it to the REST API).
        $path = '';
        if ($ref !== '' && strtolower((string) wp_parse_url($ref, PHP_URL_HOST)) === $host) {
            $path = substr((string) wp_parse_url($ref, PHP_URL_PATH), 0, 300);
        }
        return array(
            'v'  => bin2hex(substr(hash_hmac('sha256', $ip . '|' . $ua . '|' . $host, $salt, true), 0, 8)),
            'ts' => $now,
            'ua' => $ua,
            'cc' => SEOProStats_Collector::country($config, $_SERVER),
            'h'  => $host,
            'u'  => $path,
        );
    }

    /**
     * A stored checkout visit, checked.
     *
     * @param mixed $visit Meta value.
     * @return array<string,mixed>|null
     */
    private static function visit_from($visit) {
        if (is_string($visit)) {
            $visit = json_decode($visit, true);
        }
        if (!is_array($visit) || !isset($visit['v'], $visit['ts']) || !preg_match('/^[0-9a-f]{16}$/', (string) $visit['v'])) {
            return null;
        }
        return $visit;
    }

    /**
     * Append a Purchase event to the buffer.
     *
     * @param array<string,mixed> $visit    Checkout visit (current_visit(), or a page load's from ThriveCart).
     * @param float               $amount   Total in the currency's main unit.
     * @param string              $currency ISO 4217 code.
     * @param string              $source   woocommerce, edd, fluentcart or thrivecart.
     * @param int                 $items    Number of items.
     * @return bool Whether it was written.
     */
    public static function record(array $visit, $amount, $currency, $source, $items) {
        $currency = strtoupper(trim((string) $currency));
        if (!is_numeric($amount) || (float) $amount <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return false; // Free orders are not purchases.
        }
        $hit = array(
            't'  => 'e',
            'n'  => self::EVENT,
            'rv' => array('a' => round((float) $amount, 2), 'c' => $currency),
            'd'  => array('source' => (string) $source, 'items' => max(0, (int) $items)),
        );
        if (!empty($visit['p']) && preg_match('/^[0-9a-f]{16}$/', (string) $visit['p'])) {
            $hit['p'] = (string) $visit['p']; // Takes its page load's path.
        } elseif (!empty($visit['u']) && is_string($visit['u'])) {
            $hit['u'] = $visit['u'];
        }

        /**
         * Filters a purchase before it is recorded: return false to leave
         * it out. Properties may be added to $hit['d'] (scalars only).
         *
         * @param array<string,mixed>|false $hit    Event hit (SEOProStats_Processor lists the fields).
         * @param string                    $source woocommerce, edd, fluentcart or thrivecart.
         */
        $hit = apply_filters('seoprostats_purchase', $hit, (string) $source);
        if (!is_array($hit)) {
            return false;
        }

        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-collector.php';
        $line = array(
            'ts' => (int) $visit['ts'],
            'v'  => (string) $visit['v'],
            'ua' => isset($visit['ua']) ? (string) $visit['ua'] : '',
            'cc' => isset($visit['cc']) ? (string) $visit['cc'] : '',
            'h'  => isset($visit['h']) ? (string) $visit['h'] : '',
            's'  => 1,
            'e'  => array($hit),
        );
        $json = wp_json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return false;
        }
        $dir = SEOProStats_Collection::dir();
        if (!is_dir($dir)) {
            SEOProStats_Collection::write_config();
        }
        return SEOProStats_Collector::append($dir, $json . "\n");
    }

    // ------------------------------------------------------------------
    // WooCommerce.

    /**
     * Checkout: keep the visit on the order.
     *
     * @param int|WC_Order $order Order or its ID (classic checkout passes the ID first).
     */
    public static function woo_checkout($order) {
        $order = is_object($order) ? $order : (function_exists('wc_get_order') ? wc_get_order($order) : null);
        $visit = $order instanceof WC_Order ? self::current_visit() : null;
        if ($visit && !$order->get_meta(self::META_DONE)) {
            $order->update_meta_data(self::META_VISIT, wp_json_encode($visit));
            $order->save_meta_data();
        }
    }

    /**
     * Paid (payment complete, or set to processing or completed).
     *
     * @param int $order_id Order ID.
     */
    public static function woo_paid($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund || isset(self::$done['woo' . $order->get_id()]) || $order->get_meta(self::META_DONE)) {
            return;
        }
        $visit = self::visit_from($order->get_meta(self::META_VISIT));
        if (!$visit) {
            return;
        }
        self::$done['woo' . $order->get_id()] = true;
        self::record($visit, (float) $order->get_total(), (string) $order->get_currency(), 'woocommerce', count($order->get_items()));
        $order->delete_meta_data(self::META_VISIT);
        $order->update_meta_data(self::META_DONE, time());
        $order->save_meta_data();
    }

    // ------------------------------------------------------------------
    // Easy Digital Downloads 3.

    /**
     * Checkout: keep the visit on the order.
     *
     * @param int $order_id Order ID.
     */
    public static function edd_checkout($order_id) {
        if (!function_exists('edd_update_order_meta')) {
            return;
        }
        $visit = self::current_visit();
        if ($visit) {
            edd_update_order_meta((int) $order_id, self::META_VISIT, wp_json_encode($visit));
        }
    }

    /**
     * Paid (complete).
     *
     * @param int $order_id Order ID.
     */
    public static function edd_paid($order_id) {
        $order_id = (int) $order_id;
        if (!function_exists('edd_get_order') || isset(self::$done['edd' . $order_id]) || edd_get_order_meta($order_id, self::META_DONE, true)) {
            return;
        }
        $order = edd_get_order($order_id);
        $visit = self::visit_from(edd_get_order_meta($order_id, self::META_VISIT, true));
        if (!is_object($order) || !$visit || !isset($order->total, $order->currency) || (isset($order->type) && $order->type !== 'sale')) {
            return;
        }
        self::$done['edd' . $order_id] = true;
        $items = method_exists($order, 'get_items') ? count((array) $order->get_items()) : 0;
        self::record($visit, (float) $order->total, (string) $order->currency, 'edd', $items);
        edd_delete_order_meta($order_id, self::META_VISIT);
        edd_update_order_meta($order_id, self::META_DONE, time());
    }

    // ------------------------------------------------------------------
    // FluentCart.

    /**
     * The order model in a FluentCart event's data.
     *
     * @param mixed $data Event data: ['order' => Order, ...].
     * @return mixed The order, or null.
     */
    private static function fluentcart_order($data) {
        return is_array($data) && isset($data['order']) && is_object($data['order']) ? $data['order'] : null;
    }

    /**
     * A FluentCart order's meta value.
     *
     * @param mixed  $order Order model.
     * @param string $key   Meta key.
     * @return mixed False when missing.
     */
    private static function fluentcart_meta($order, $key) {
        return is_object($order) && method_exists($order, 'getMeta') ? $order->getMeta($key) : false;
    }

    /**
     * Set a FluentCart order's meta value.
     *
     * @param mixed  $order Order model.
     * @param string $key   Meta key.
     * @param mixed  $value Value.
     */
    private static function fluentcart_set_meta($order, $key, $value) {
        if (is_object($order) && method_exists($order, 'updateMeta')) {
            $order->updateMeta($key, $value);
        }
    }

    /**
     * A FluentCart order's fields (and loaded relations, such as its items).
     *
     * @param mixed $order Order model.
     * @return array<string,mixed>
     */
    private static function fluentcart_fields($order) {
        $fields = is_object($order) && method_exists($order, 'toArray') ? $order->toArray() : array();
        return is_array($fields) ? $fields : array();
    }

    /**
     * Checkout: keep the visit on the order.
     *
     * @param array<string,mixed> $data Event data.
     */
    public static function fluentcart_checkout($data) {
        $order = self::fluentcart_order($data);
        $visit = $order ? self::current_visit() : null;
        if ($visit && !self::fluentcart_meta($order, self::META_DONE)) {
            self::fluentcart_set_meta($order, self::META_VISIT, wp_json_encode($visit));
        }
    }

    /**
     * Paid. Renewals have no checkout visit; they are skipped by type too.
     *
     * @param array<string,mixed> $data Event data.
     */
    public static function fluentcart_paid($data) {
        $order  = self::fluentcart_order($data);
        $fields = self::fluentcart_fields($order);
        if (!$order || empty($fields['id']) || (isset($fields['type']) && $fields['type'] === 'renewal')) {
            return;
        }
        $key = 'fluentcart' . (int) $fields['id'];
        if (isset(self::$done[$key]) || self::fluentcart_meta($order, self::META_DONE)) {
            return;
        }
        $visit = self::visit_from(self::fluentcart_meta($order, self::META_VISIT));
        if (!$visit || !isset($fields['total_amount'], $fields['currency']) || !is_numeric($fields['total_amount'])) {
            return;
        }
        self::$done[$key] = true;
        $items = isset($fields['order_items']) && is_array($fields['order_items']) ? count($fields['order_items']) : 0;
        // FluentCart keeps amounts in cents.
        self::record($visit, ((float) $fields['total_amount']) / 100, (string) $fields['currency'], 'fluentcart', $items);
        self::fluentcart_set_meta($order, self::META_VISIT, '');
        self::fluentcart_set_meta($order, self::META_DONE, time());
    }

    // ------------------------------------------------------------------
    // ThriveCart.

    /**
     * The webhook route. GET and HEAD answer 200, for ThriveCart's check
     * of the address; POST takes an order.
     */
    public static function register_route() {
        register_rest_route(SEOProStats_Collection::REST_NAMESPACE, '/thrivecart', array(
            array(
                'methods'             => 'GET',
                'callback'            => static function () {
                    return new WP_REST_Response(array('ok' => true), 200);
                },
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'thrivecart_webhook'),
                // Public on purpose: ThriveCart is not logged in. The
                // handler checks the account's secret word.
                'permission_callback' => '__return_true',
            ),
        ));
    }

    /**
     * ThriveCart's webhook (form-encoded, or JSON).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function thrivecart_webhook($request) {
        $secret = self::thrivecart_secret();
        if ($secret === '') {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'not connected'), 404);
        }
        $sent = (string) $request->get_param('thrivecart_secret');
        if ($sent === '' || !hash_equals($secret, $sent)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'secret'), 403);
        }
        $result = self::thrivecart_order(array(
            'event'       => (string) $request->get_param('event'),
            'mode'        => (string) $request->get_param('mode'),
            'order_id'    => (string) $request->get_param('order_id'),
            'currency'    => (string) $request->get_param('currency'),
            'order'       => $request->get_param('order'),
            'purchases'   => $request->get_param('purchases'),
            'passthrough' => $request->get_param('passthrough'),
            'flat'        => $request->get_param('passthrough[' . self::PASSTHROUGH . ']'),
        ));
        return new WP_REST_Response(array('ok' => true, 'result' => $result), 200);
    }

    /**
     * Record a ThriveCart order, once.
     *
     * @param array<string,mixed> $data Webhook fields.
     * @return string recorded, ignored, test, duplicate, not-joined or failed.
     */
    public static function thrivecart_order(array $data) {
        if ($data['event'] !== 'order.success') {
            return 'ignored';
        }
        /**
         * Filters whether ThriveCart test-mode orders are recorded.
         *
         * @param bool $record Default false.
         */
        if ($data['mode'] === 'test' && !apply_filters('seoprostats_thrivecart_test_orders', false)) {
            return 'test';
        }
        $order_id = preg_replace('/[^\w.-]/', '', $data['order_id']);
        $total    = is_array($data['order']) && isset($data['order']['total']) ? $data['order']['total'] : null;
        if ($order_id === '' || !is_numeric($total)) {
            return 'failed';
        }
        $pass = is_array($data['passthrough']) && isset($data['passthrough'][self::PASSTHROUGH]) ? $data['passthrough'][self::PASSTHROUGH] : $data['flat'];
        $pkey = is_string($pass) ? strtolower(trim($pass)) : '';

        $state  = get_option(self::STATE_OPTION, array());
        $state  = is_array($state) ? $state : array();
        $orders = isset($state['thrivecart']) && is_array($state['thrivecart']) ? $state['thrivecart'] : array();
        if (in_array($order_id, $orders, true)) {
            return 'duplicate';
        }
        $orders[]            = $order_id;
        $state['thrivecart'] = array_slice($orders, -self::KEEP_ORDERS);

        $visit = preg_match('/^[0-9a-f]{16}$/', $pkey) ? self::page_load_visit($pkey) : null;
        if (!$visit) {
            $state['not_joined'] = (isset($state['not_joined']) ? (int) $state['not_joined'] : 0) + 1;
            update_option(self::STATE_OPTION, $state, false);
            return 'not-joined';
        }
        update_option(self::STATE_OPTION, $state, false);
        $items = is_array($data['purchases']) ? count($data['purchases']) : 1;
        // ThriveCart sends amounts in cents.
        return self::record($visit, ((float) $total) / 100, $data['currency'], 'thrivecart', $items) ? 'recorded' : 'failed';
    }

    /**
     * The visit of a stored page load: its visitor hash and the page
     * load's time, so the processor joins the visit.
     *
     * @param string $pkey Page-load ID (16 hex).
     * @return array{v:string,ts:int,p:string,ua:string,cc:string,h:string}|null
     */
    private static function page_load_visit($pkey) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own tables, by the unique pkey and primary keys.
        $row = $wpdb->get_row($wpdb->prepare('SELECT p.ts, LOWER(HEX(s.visitor)) AS visitor FROM %i p JOIN %i s ON s.id = p.session_id WHERE p.pkey = UNHEX(%s)', SEOProStats_Schema::table('pageviews'), SEOProStats_Schema::table('sessions'), $pkey));
        if (!$row || !preg_match('/^[0-9a-f]{16}$/', (string) $row->visitor)) {
            return null;
        }
        return array('v' => (string) $row->visitor, 'ts' => (int) $row->ts, 'p' => $pkey, 'ua' => '', 'cc' => '', 'h' => '');
    }

    /**
     * Uninstall: the state option, and the two meta keys in each shop's
     * order meta table that exists (WooCommerce posts and HPOS, EDD 3,
     * FluentCart).
     */
    public static function forget() {
        global $wpdb;
        delete_option(self::STATE_OPTION);
        delete_metadata('post', 0, self::META_VISIT, '', true);
        delete_metadata('post', 0, self::META_DONE, '', true);
        foreach (array('wc_orders_meta', 'edd_ordermeta', 'fct_order_meta') as $name) {
            $table = $wpdb->prefix . $name;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall check.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup of our own meta keys.
                $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key IN (%s, %s)', $table, self::META_VISIT, self::META_DONE));
            }
        }
    }

    /**
     * Counts for WP-CLI doctor: ThriveCart orders without a known page load.
     *
     * @return array{thrivecart_not_joined:int}
     */
    public static function status() {
        $state = get_option(self::STATE_OPTION, array());
        return array('thrivecart_not_joined' => is_array($state) && isset($state['not_joined']) ? (int) $state['not_joined'] : 0);
    }
}
