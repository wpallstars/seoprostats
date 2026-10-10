<?php
/**
 * Each person's last named dashboard period and comparison.
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

final class SEOProStats_Admin_Period {

    const META = 'seoprostats_period';
    const ACTION = 'seoprostats_period';

    /** Register the authenticated screen-preference action. */
    public static function init() {
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'save'));
    }

    /**
     * Whether both values describe a period that can be remembered.
     *
     * @param mixed $range Named range.
     * @param mixed $compare Comparison.
     * @return bool
     */
    private static function valid($range, $compare) {
        return in_array($range, SEOProStats_Query::RANGES, true)
            && !in_array($range, array('custom', 'realtime'), true)
            && in_array($compare, SEOProStats_Query::COMPARE, true);
    }

    /**
     * Current person's preference and the details needed to save it.
     *
     * @return array<string,string>
     */
    public static function boot() {
        $period = get_user_meta(get_current_user_id(), self::META, true);
        $valid  = is_array($period) && isset($period['range'], $period['compare']) && self::valid($period['range'], $period['compare']);
        return array(
            'range'   => $valid ? $period['range'] : '91d',
            'compare' => $valid ? $period['compare'] : 'prev',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
        );
    }

    /** Save only the authenticated person's valid, named period. */
    public static function save() {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!current_user_can(SEOProStats_API::CAP) && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You cannot change this.', 'seoprostats')), 403);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strict allowlist validation below rejects anything but an exact range.
        $range   = isset($_POST['range']) && is_string($_POST['range']) ? wp_unslash($_POST['range']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strict allowlist validation below rejects anything but an exact comparison.
        $compare = isset($_POST['compare']) && is_string($_POST['compare']) ? wp_unslash($_POST['compare']) : '';
        if (!self::valid($range, $compare)) {
            wp_send_json_error(array('message' => __('Unknown period or comparison.', 'seoprostats')), 400);
        }
        $period = array('range' => $range, 'compare' => $compare);
        update_user_meta(get_current_user_id(), self::META, $period);
        wp_send_json_success($period);
    }
}
