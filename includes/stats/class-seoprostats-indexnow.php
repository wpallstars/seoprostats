<?php
/**
 * Opt-in page change notifications, batched off visitor requests.
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

final class SEOProStats_IndexNow { // NOSONAR: one notifier for its hooks, settings, WP-CLI and cron; private helpers decompose a send.

    const KEY = 'seoprostats_indexnow_key';
    const STATE = 'seoprostats_indexnow';
    const LOCK = 'seoprostats_indexnow_lock';
    const RETRY = 'seoprostats_indexnow_enqueue';
    const ENDPOINT = 'https://api.indexnow.org/indexnow';
    const LIMIT = 10000;

    /** Register only hooks and the virtual key rewrite. */
    public static function init() {
        add_action('init', array(__CLASS__, 'rewrite'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('parse_request', array(__CLASS__, 'serve_key'));
        add_action(self::RETRY, array(__CLASS__, 'retry'), 10, 2);
    }

    /** @param string[] $urls Addresses. @param int $id Change ID. */
    public static function retry(array $urls, $id) {
        self::enqueue($urls, $id);
    }

    /** @return bool Whether the owner opted in. */
    public static function enabled() {
        return (bool) SEOProStats_Settings::get('indexnow');
    }

    /** A generic rule needs no key option read on visitor pages. */
    public static function rewrite() {
        add_rewrite_rule('^([a-f0-9]{32})\.txt$', 'index.php?seoprostats_indexnow_key=$matches[1]', 'top');
    }

    /** @param string[] $vars Query variables. @return string[] */
    public static function query_vars($vars) {
        $vars[] = 'seoprostats_indexnow_key';
        return $vars;
    }

    /** @param WP $wp Parsed request. */
    public static function serve_key($wp) {
        // Do not expose the key via a query argument; only its virtual file.
        if (!preg_match('/^[a-f0-9]{32}\.txt$/', $wp->request)) {
            return;
        }
        $key = self::enabled() ? (string) get_option(self::KEY, '') : '';
        $ok = $key !== '' && $wp->request === $key . '.txt';
        status_header($ok ? 200 : 404);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo $ok ? esc_html($key) : '';
        exit;
    }

    /** Prepare on a settings save, never on a visitor request. */
    public static function settings_saved() {
        $enabled = self::enabled();
        if ($enabled || get_option(self::STATE, false) !== false) {
            self::mutate(static function ($state) use ($enabled) {
                if (($state['accepting'] ?? true) !== $enabled) {
                    $state['generation'] = ($state['generation'] ?? 0) + 1;
                }
                $state['accepting'] = $enabled;
                if (!$enabled) {
                    $state['queue'] = array();
                }
                return $state;
            });
        }
        if (self::enabled()) {
            self::key();
            self::rewrite();
            $rules = get_option('rewrite_rules', array());
            if (!is_array($rules) || !isset($rules['^([a-f0-9]{32})\.txt$'])) {
                flush_rewrite_rules(false);
            }
        } else {
            wp_clear_scheduled_hook(self::RETRY);
        }
    }

    /** @return string The public verification key, generated on first use. */
    private static function key() {
        $key = (string) get_option(self::KEY, '');
        if ($key === '') {
            add_option(self::KEY, bin2hex(random_bytes(16)), '', false);
            $key = (string) get_option(self::KEY, '');
        }
        return $key;
    }

    /** @return array<string,mixed> Stored queue, recent attempts and receipts. */
    private static function state() {
        $state = get_option(self::STATE, array());
        return (is_array($state) ? $state : array()) + array('queue' => array(), 'recent' => array(), 'log' => array());
    }

    /**
     * Compare-and-swap prevents an editor and cron overwriting each other.
     * @param callable $change Changes the latest state.
     * @return bool Whether persisted.
     */
    private static function mutate($change) {
        global $wpdb;
        add_option(self::STATE, array('queue' => array(), 'recent' => array(), 'log' => array()), '', false);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            wp_cache_delete(self::STATE, 'options');
            $before = self::state();
            $after = $change($before);
            if ($after === $before) {
                return true;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic update of our non-autoloaded option.
            $written = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize($after), self::STATE, maybe_serialize($before)));
            wp_cache_delete(self::STATE, 'options');
            if ($written === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Accept only HTTP(S) page URLs on this exact host, without credentials.
     * @param string $url Address.
     * @return string Clean address or empty.
     */
    public static function url($url) {
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !is_array($home) || empty($parts['host']) || empty($home['host']) || !isset($parts['scheme']) || !in_array($parts['scheme'], array('http', 'https'), true) || strtolower($parts['host']) !== strtolower($home['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? null) !== ($home['port'] ?? null)) {
            return '';
        }
        $root = $home['path'] ?? '/';
        $path = $parts['path'] ?? '/';
        if (strpos($path, $root) !== 0 || preg_match('~(?:^|/)\.\.(?:/|$)~', rawurldecode($path))) {
            return '';
        }
        return esc_url_raw($url, array('http', 'https'));
    }

    /**
     * Queue changed pages and the exact change row for later receipts.
     * @param array<string,mixed> $row Recorded live change.
     * @param int $id Change ID.
     */
    public static function changed(array $row, $id) {
        if (!self::enabled() || !in_array((int) $row['kind'], array(1, 2, 3, 4, 5, 6, 7, 10, 11, 12, 13), true) || !$row['object_id'] || $row['path'] === '') {
            return;
        }
        $paths = array((string) $row['path']);
        if ((int) $row['kind'] === 3) {
            $paths[] = (string) $row['old'];
        }
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || !isset($home['scheme'], $home['host'])) {
            return;
        }
        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        $urls = array();
        foreach ($paths as $path) {
            // Change paths are already relative to the host, including a subdirectory.
            $parts = explode('?', $path, 2);
            $urls[] = $origin . implode('/', array_map('rawurlencode', explode('/', $parts[0]))) . (isset($parts[1]) ? '?' . $parts[1] : '');
        }
        self::enqueue($urls, $id);
    }

    /**
     * Queue (also used by the durable contention retry and WP-CLI).
     * @param string[] $urls Addresses.
     * @param int $id Change ID, or zero for a manual notification.
     * @return bool Whether queued; opt-out never writes.
     */
    public static function enqueue(array $urls, $id = 0) {
        if (!self::enabled()) {
            return false;
        }
        $urls = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'url'), $urls))));
        $generation = self::state()['generation'] ?? 0;
        $token = $id ? (int) $id : wp_generate_uuid4();
        $saved = self::mutate(static function ($state) use ($urls, $token, $generation) {
            if (empty($state['accepting']) || ($state['generation'] ?? 0) !== $generation) {
                return $state;
            }
            foreach ($urls as $url) {
                $ids = $state['queue'][$url] ?? array();
                $ids[] = $token;
                $state['queue'][$url] = array_values(array_unique($ids));
            }
            return $state;
        });
        if (!$saved) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::RETRY, array($urls, (int) $id));
        }
        return $saved;
    }

    /** Send one batch from cron or WP-CLI only. */
    public static function run() {
        if (!self::enabled() || (!wp_doing_cron() && !(defined('WP_CLI') && WP_CLI))) {
            return;
        }
        if (!self::state()['queue']) {
            return;
        }
        // Atomic owner lock; the request times out well before this stale cutoff.
        global $wpdb;
        $owner = time() . ' ' . wp_generate_uuid4();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- remove only an expired lock owned by this feature.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", self::LOCK, time() - 300));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- insert-only lock, unlike add_option's duplicate-key update.
        if (!$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $owner))) {
            return;
        }
        try {
            self::submit();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release only this sender's lease.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $owner));
        }
    }

    /** Submit eligible addresses; failed attempts are retried after an hour. */
    private static function submit() {
        $now = time();
        $batch = self::eligible(self::state(), $now);
        if (!$batch) {
            return;
        }
        $key = self::key();
        $body = wp_json_encode(array('host' => wp_parse_url(home_url('/'), PHP_URL_HOST), 'key' => $key, 'keyLocation' => home_url('/' . $key . '.txt'), 'urlList' => array_keys($batch)));
        if ($body === false) {
            return;
        }
        // Reserve the hourly allowance before contacting the service, even if
        // the process dies after the POST. The pending queue remains durable.
        if (!self::mutate(static function ($latest) use ($batch, $now) {
            return self::reserve($latest, $batch, $now);
        }) || !self::enabled()) {
            return;
        }
        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 3,
            'redirection' => 0,
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => $body,
        ));
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $sent = in_array($code, array(200, 202), true);
        $receipt = array('time' => $now, 'count' => count($batch), 'code' => $code, 'sent' => $sent);
        self::mutate(static function ($latest) use ($batch, $receipt, $now) {
            return self::settle($latest, $batch, $receipt, $now);
        });
        self::receipts($batch, $receipt);
    }

    /**
     * Up to LIMIT valid queued addresses not sent in the last hour.
     * @param array<string,mixed> $state State.
     * @param int $now Time.
     * @return array<string,array<int,int|string>> Address => its change IDs or tokens.
     */
    private static function eligible(array $state, $now) {
        $batch = array();
        foreach ($state['queue'] as $url => $ids) {
            if (self::url($url) !== '' && ($state['recent'][$url] ?? 0) <= $now - HOUR_IN_SECONDS) {
                $batch[$url] = $ids;
                if (count($batch) === self::LIMIT) {
                    break;
                }
            }
        }
        return $batch;
    }

    /**
     * State with sends older than an hour dropped and the batch marked sent now.
     * @param array<string,mixed> $latest State.
     * @param array<string,array<int,int|string>> $batch From eligible().
     * @param int $now Time.
     * @return array<string,mixed>
     */
    private static function reserve(array $latest, array $batch, $now) {
        $latest['recent'] = array_filter($latest['recent'], static function ($ts) use ($now) { return $ts > $now - HOUR_IN_SECONDS; });
        foreach (array_keys($batch) as $url) {
            $latest['recent'][$url] = $now;
        }
        return $latest;
    }

    /**
     * State after a send: the batch reserved again, sent IDs dequeued, and the receipt logged.
     * @param array<string,mixed> $latest State.
     * @param array<string,array<int,int|string>> $batch From eligible().
     * @param array<string,mixed> $receipt time, count, code and sent.
     * @param int $now Time.
     * @return array<string,mixed>
     */
    private static function settle(array $latest, array $batch, array $receipt, $now) {
        $latest = self::reserve($latest, $batch, $now);
        if ($receipt['sent']) {
            foreach ($batch as $url => $ids) {
                $remaining = array_values(array_diff($latest['queue'][$url] ?? array(), $ids));
                if ($remaining) {
                    $latest['queue'][$url] = $remaining;
                } else {
                    unset($latest['queue'][$url]);
                }
            }
        }
        array_unshift($latest['log'], $receipt);
        $latest['log'] = array_slice($latest['log'], 0, 100);
        return $latest;
    }

    /**
     * Record the receipt on each change in the batch (not manual notifications).
     * @param array<string,array<int,int|string>> $batch From eligible().
     * @param array<string,mixed> $receipt time, count, code and sent.
     */
    private static function receipts(array $batch, array $receipt) {
        require_once __DIR__ . '/class-seoprostats-changes.php';
        foreach ($batch as $ids) {
            foreach ($ids as $id) {
                if (is_int($id) && $id > 0) {
                    SEOProStats_Changes::indexnow_receipt($id, $receipt);
                }
            }
        }
    }

    /** @return array<string,mixed> Admin status, with no mutation or network call. */
    public static function status() {
        $state = self::state();
        $key = (string) get_option(self::KEY, '');
        $last = $state['log'][0] ?? null;
        $errors = array(0 => __('The request failed. Check the server connection.', 'seoprostats'), 403 => __('The verification key was not accepted. Check that its address is publicly reachable.', 'seoprostats'), 422 => __('The addresses or key location were not accepted.', 'seoprostats'), 429 => __('Too many requests. The queued pages will be tried again after an hour.', 'seoprostats'));
        return array('enabled' => self::enabled(), 'endpoint' => self::ENDPOINT, 'key_url' => self::enabled() && $key !== '' ? home_url('/' . $key . '.txt') : '', 'queued' => count($state['queue']), 'last' => $last, 'log' => $state['log'], 'error' => $last && !$last['sent'] ? ($errors[$last['code']] ?? __('The service did not accept the request.', 'seoprostats')) : '');
    }

    /** Remove only this feature's state. */
    public static function forget() {
        foreach (array(self::KEY, self::STATE, self::LOCK) as $option) {
            delete_option($option);
        }
        wp_clear_scheduled_hook(self::RETRY);
    }
}
