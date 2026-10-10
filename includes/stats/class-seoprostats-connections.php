<?php
/**
 * Connections to outside data sources (Settings → Connections): each
 * source's credentials, encrypted at rest, its choices and its state.
 *
 * One option, autoload off, read only by the Connections tab, the REST
 * routes, WP-CLI and the import job; never on a visitor's page. The
 * credentials are encrypted with libsodium (bundled with WordPress) under
 * a key made from the site's salts (or SEOPROSTATS_ENCRYPTION_KEY), and
 * never leave this class except to the source that uses them: not in REST
 * answers, exports, WP-CLI output or logs. Design: docs/architecture.md →
 * Integrations → Connections.
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

final class SEOProStats_Connections {

    /** Every source's connection (autoload off). */
    const OPTION = 'seoprostats_connections';

    /** Sources: key => class, in includes/stats/sources/. */
    const SOURCES = array(
        'search-console' => 'SEOProStats_Source_Search_Console',
        'bing'           => 'SEOProStats_Source_Bing',
    );

    /** Prefix of an encrypted value, so a later scheme can be told apart. */
    const CIPHER = 'v1:';

    /**
     * Load a source's class.
     *
     * @param string $source Source key.
     * @return string|null Its class, or null for an unknown source.
     */
    public static function source_class($source) {
        if (!isset(self::SOURCES[$source])) {
            return null;
        }
        $class = self::SOURCES[$source];
        if (!class_exists($class, false)) {
            require_once SEOPROSTATS_DIR . 'includes/stats/sources/class-' . strtolower(str_replace('_', '-', $class)) . '.php';
        }
        return $class;
    }

    /**
     * Connect a source, or change its choices: check the input with the
     * source (a sign-in), save it, and schedule the import job. Choosing
     * another property starts the import again from the beginning.
     *
     * @param string              $source Source key.
     * @param array<string,mixed> $input  The source's fields (Search Console: google, key, property).
     * @return array<string,mixed>|WP_Error status().
     */
    public static function connect($source, array $input) {
        $class = self::source_class($source);
        if (!$class) {
            return new WP_Error('seoprostats_source_unknown', __('Unknown source.', 'seoprostats'), array('status' => 404));
        }
        $before   = self::get($source);
        $existing = $before ? self::credentials($source) : null;
        $result   = $class::connect($input, is_wp_error($existing) ? null : $existing);
        if (is_wp_error($result)) {
            return $result;
        }
        $saved = self::save($source, $result['credentials'], $result['settings']);
        if (is_wp_error($saved)) {
            return $saved;
        }
        // Replaced sign-in (a key instead, or a new sign-in): revoke the old one with Google.
        if (is_array($existing) && $existing !== $result['credentials'] && method_exists($class, 'revoke')) {
            $class::revoke($existing);
        }
        $reset = array('error' => null, 'error_at' => null, 'properties' => count($result['properties']));
        if (!$before || (isset($before['settings']['property']) ? (string) $before['settings']['property'] : '') !== $result['settings']['property']) {
            $reset += array('through' => null, 'back' => null, 'first' => null, 'final' => null, 'checked' => null, 'pairs_from' => null, 'pairs_to' => null, 'pairs_queue' => null);
        }
        self::update_state($source, $reset);
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        SEOProStats_Search_Import::schedule();
        // The first import a few seconds from now, not on this request.
        wp_schedule_single_event(time() + 5, SEOProStats_Search_Import::HOOK, array('more'));
        return self::status($source);
    }

    /**
     * Disconnect a source: forget its credentials, and its imported data
     * when asked.
     *
     * @param string $source Source key.
     * @param bool   $delete Also delete its imported data.
     * @return array<string,mixed>|WP_Error status(), with deleted (rows).
     */
    public static function disconnect($source, $delete = false) {
        $class = self::source_class($source);
        if (!$class) {
            return new WP_Error('seoprostats_source_unknown', __('Unknown source.', 'seoprostats'), array('status' => 404));
        }
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        // A Google sign-in's access is revoked with Google, not only forgotten.
        $credentials = self::get($source) ? self::credentials($source) : null;
        if (is_array($credentials) && method_exists($class, 'revoke')) {
            $class::revoke($credentials);
        }
        self::remove($source);
        SEOProStats_Search_Import::schedule();
        $deleted = $delete ? SEOProStats_Search_Import::delete_data($source) : 0;
        return self::status($source) + array('deleted' => $deleted);
    }

    /**
     * Every source's status().
     *
     * @return array<int,array<string,mixed>>
     */
    public static function statuses() {
        $out = array();
        foreach (array_keys(self::SOURCES) as $source) {
            $out[] = self::status($source);
        }
        return $out;
    }

    /**
     * A source's status, without credentials: connected, the account and
     * property, how far the import is, the last error, recent imports.
     *
     * @param string $source Source key.
     * @return array<string,mixed>
     */
    public static function status($source) {
        $class = self::source_class($source);
        $conn  = self::get($source);
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        $out = array(
            'source'    => $source,
            'name'      => $class ? $class::NAME : $source,
            'connected' => (bool) $conn,
        );
        if (!$class || !$conn) {
            return $out;
        }
        $state    = $conn['state'];
        $today    = $class::today();
        $oldest   = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('-' . SEOProStats_Search_Import::months($class) . ' months')->format('Y-m-d');
        $first    = max(isset($state['first']) ? (string) $state['first'] : '', $oldest);
        $back     = isset($state['back']) ? (string) $state['back'] : '';
        $through  = isset($state['through']) ? (string) $state['through'] : '';
        $total    = self::days_between($first, $through);
        $done     = $back !== '' ? self::days_between(max($back, $first), $through) : 0;
        $next     = wp_next_scheduled(SEOProStats_Search_Import::HOOK, array('more'));
        $next     = $next ? $next : wp_next_scheduled(SEOProStats_Search_Import::HOOK);
        $coverage = SEOProStats_Search_Import::coverage((int) $class::ENGINE);
        return $out + array(
            'method'       => isset($conn['settings']['method']) ? (string) $conn['settings']['method'] : 'key',
            'account'      => isset($conn['settings']['account']) ? (string) $conn['settings']['account'] : '',
            'property'     => isset($conn['settings']['property']) ? (string) $conn['settings']['property'] : '',
            'connected_at' => $conn['connected'],
            'imported'     => array(
                'from'     => $coverage['from'],
                'to'       => $coverage['to'],
                'days'     => min($done, $total),
                'of'       => $total,
                'complete' => $through !== '' && $back !== '' && $back <= $first,
            ),
            // Pages whose queries are still to import (a source that gives them page by page); null: none due.
            'pages_left'    => !empty($state['pairs_from']) ? (isset($state['pairs_queue']) && is_array($state['pairs_queue']) ? count($state['pairs_queue']) : null) : 0,
            'final_through' => isset($state['final']) ? (string) $state['final'] : '',
            'checked'       => isset($state['checked']) ? (int) $state['checked'] : 0,
            'last_run'      => isset($state['last_run']) ? (int) $state['last_run'] : 0,
            'next_run'      => $next ? (int) $next : 0,
            'error'         => isset($state['error']) ? (string) $state['error'] : '',
            'error_at'      => isset($state['error_at']) ? (int) $state['error_at'] : 0,
            'imports'       => SEOProStats_Schema::is_current() ? SEOProStats_Search_Import::imports($source, 5) : array(),
        );
    }

    /**
     * Days from one day to another, both included; 0 when either is empty
     * or the second is earlier.
     *
     * @param string $from Y-m-d.
     * @param string $to   Y-m-d.
     * @return int
     */
    private static function days_between($from, $to) {
        if ($from === '' || $to === '' || $to < $from) {
            return 0;
        }
        $utc = new DateTimeZone('UTC');
        return (int) (new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->days + 1;
    }

    /**
     * Every stored connection, as stored (credentials still encrypted).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function all() {
        $all = get_option(self::OPTION, array());
        return is_array($all) ? $all : array();
    }

    /**
     * Every stored connection, read again from the database, for a write.
     * An import runs in its own request for a minute or so; if the source
     * is disconnected meanwhile, that request's cached copy still holds the
     * connection, and writing its state back from the copy would bring the
     * connection (and revoked credentials) back.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function all_fresh() {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return self::all();
    }

    /**
     * Whether a source is still connected, from the database (a running
     * import checks between days, so a disconnect stops it).
     *
     * @param string $source Source key.
     * @return bool
     */
    public static function still_connected($source) {
        $all = self::all_fresh();
        return isset($all[$source]) && is_array($all[$source]);
    }

    /**
     * The connected sources' keys.
     *
     * @return string[]
     */
    public static function connected() {
        return array_values(array_intersect(array_keys(self::all()), array_keys(self::SOURCES)));
    }

    /**
     * One connection without its credentials: settings (such as the
     * property), state (last run, errors) and when it was made.
     *
     * @param string $source Source key.
     * @return array{settings:array<string,mixed>,state:array<string,mixed>,connected:int}|null Null when not connected.
     */
    public static function get($source) {
        $all = self::all();
        if (!isset($all[$source]) || !is_array($all[$source])) {
            return null;
        }
        $row = $all[$source];
        return array(
            'settings'  => isset($row['settings']) && is_array($row['settings']) ? $row['settings'] : array(),
            'state'     => isset($row['state']) && is_array($row['state']) ? $row['state'] : array(),
            'connected' => isset($row['connected']) ? (int) $row['connected'] : 0,
        );
    }

    /**
     * A connection's credentials, decrypted.
     *
     * @param string $source Source key.
     * @return array<string,mixed>|WP_Error
     */
    public static function credentials($source) {
        $all = self::all();
        if (!isset($all[$source]['secret'])) {
            return new WP_Error('seoprostats_not_connected', __('This source is not connected.', 'seoprostats'));
        }
        $json = self::decrypt((string) $all[$source]['secret']);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return new WP_Error('seoprostats_credentials_unreadable', __('The saved credentials cannot be read, perhaps because the site\'s security keys changed. Connect again.', 'seoprostats'));
        }
        return $data;
    }

    /**
     * Save a connection: credentials (encrypted) and settings. Keeps the
     * state of an existing connection.
     *
     * @param string              $source      Source key.
     * @param array<string,mixed> $credentials Credentials.
     * @param array<string,mixed> $settings    Settings.
     * @return true|WP_Error
     */
    public static function save($source, array $credentials, array $settings) {
        $secret = self::encrypt((string) wp_json_encode($credentials));
        if (is_wp_error($secret)) {
            return $secret;
        }
        $all          = self::all_fresh();
        $before       = isset($all[$source]) && is_array($all[$source]) ? $all[$source] : array();
        $all[$source] = array(
            'secret'    => $secret,
            'settings'  => $settings,
            'state'     => isset($before['state']) && is_array($before['state']) ? $before['state'] : array(),
            'connected' => isset($before['connected']) ? (int) $before['connected'] : time(),
        );
        update_option(self::OPTION, $all, false);
        return true;
    }

    /**
     * Merge values into a connection's state (null removes a key).
     *
     * @param string              $source Source key.
     * @param array<string,mixed> $values Values.
     */
    public static function update_state($source, array $values) {
        $all = self::all_fresh();
        if (!isset($all[$source]) || !is_array($all[$source])) {
            return;
        }
        $state = isset($all[$source]['state']) && is_array($all[$source]['state']) ? $all[$source]['state'] : array();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }
        $all[$source]['state'] = $state;
        update_option(self::OPTION, $all, false);
    }

    /**
     * Forget a connection and its credentials.
     *
     * @param string $source Source key.
     */
    public static function remove($source) {
        $all = self::all_fresh();
        unset($all[$source]);
        if ($all) {
            update_option(self::OPTION, $all, false);
        } else {
            delete_option(self::OPTION);
        }
    }

    /**
     * Forget every connection (uninstall).
     */
    public static function forget() {
        delete_option(self::OPTION);
    }

    /**
     * Encrypt a text: version, then base64 of nonce and ciphertext.
     *
     * @param string $plain Text.
     * @return string|WP_Error
     */
    public static function encrypt($plain) {
        if (!function_exists('sodium_crypto_secretbox')) {
            return new WP_Error('seoprostats_no_sodium', __('PHP\'s sodium functions, which WordPress normally provides, are missing, so credentials cannot be stored safely.', 'seoprostats'));
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::CIPHER . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key())); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stores binary ciphertext as text.
    }

    /**
     * Decrypt a text made by encrypt().
     *
     * @param string $stored Stored value.
     * @return string|false False when it cannot be read (another key, or damaged).
     */
    public static function decrypt($stored) {
        if (!function_exists('sodium_crypto_secretbox_open') || strpos($stored, self::CIPHER) !== 0) {
            return false;
        }
        $raw = base64_decode(substr($stored, strlen(self::CIPHER)), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads what encrypt() stored.
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        try {
            return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The 32-byte key: from SEOPROSTATS_ENCRYPTION_KEY when wp-config.php
     * defines it, else from the site's AUTH_KEY and AUTH_SALT.
     *
     * @return string
     */
    private static function key() {
        $secret = defined('SEOPROSTATS_ENCRYPTION_KEY') && is_string(SEOPROSTATS_ENCRYPTION_KEY) && SEOPROSTATS_ENCRYPTION_KEY !== '' ? SEOPROSTATS_ENCRYPTION_KEY : wp_salt('auth');
        return hash_hmac('sha256', 'seoprostats-connections', $secret, true);
    }
}
