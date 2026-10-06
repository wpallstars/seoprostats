<?php
/**
 * The collector: checks a batch of hits from the tracker and appends it to
 * the buffer file. Nothing else; the processor does the rest.
 *
 * Plain PHP with no WordPress calls, so collect.php can run it without
 * loading WordPress, and the REST route runs the same code. Design:
 * docs/architecture.md → Collection → Collector.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * Only a class, so a direct request runs nothing (it has no ABSPATH guard:
 * collect.php loads it without WordPress).
 *
 * @package SEOProStats
 * @since 0.2.0
 */

final class SEOProStats_Collector {

    /** Largest request body, in bytes. */
    const MAX_BODY = 16384;

    /** Most hits in one request. */
    const MAX_HITS = 50;

    /** The buffer file new hits are appended to. */
    const BUFFER = 'buffer.php';

    /** First line of every buffer file, so a direct request shows nothing. */
    const GUARD = "<?php exit; ?>\n";

    /** Buffer size above which hits are dropped (cron is not running). */
    const MAX_BUFFER = 67108864;

    /**
     * Check a request and append its hits to the buffer.
     *
     * @param array<string,mixed>  $config Collector config (config.php in the folder).
     * @param string               $dir    The site's collector folder.
     * @param string               $body   Request body.
     * @param array<string,mixed>  $server Request environment ($_SERVER).
     * @param int                  $now    Unix time.
     * @return int HTTP status: 204 stored or ignored on purpose, 400 bad
     *             request, 403 wrong host, 413 too large, 503 cannot store.
     */
    public static function handle(array $config, $dir, $body, array $server, $now) {
        if (strlen($body) > self::MAX_BODY) {
            return 413;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['h'], $data['e']) || !is_string($data['h']) || !is_array($data['e'])) {
            return 400;
        }

        $hosts = isset($config['hosts']) && is_array($config['hosts']) ? $config['hosts'] : array();
        $host  = strtolower($data['h']);
        if (!in_array($host, $hosts, true)) {
            return 403;
        }
        $origin = isset($server['HTTP_ORIGIN']) ? (string) $server['HTTP_ORIGIN'] : '';
        if ($origin !== '' && !in_array(strtolower((string) parse_url($origin, PHP_URL_HOST)), $hosts, true)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs without WordPress; PHP 7.4+ parses scheme://host the same way.
            return 403;
        }

        $ua = isset($server['HTTP_USER_AGENT']) ? substr((string) $server['HTTP_USER_AGENT'], 0, 512) : '';
        $ip = self::client_ip($config, $server);
        if ($ua === '' || $ip === '') {
            return 204;
        }
        $exclude = isset($config['exclude_ips']) && is_array($config['exclude_ips']) ? $config['exclude_ips'] : array();
        foreach ($exclude as $range) {
            if (self::ip_in_range($ip, (string) $range)) {
                return 204;
            }
        }

        $salt = self::salt($config, $now);
        if ($salt === '') {
            return 204;
        }

        $hits = array();
        foreach (array_slice($data['e'], 0, self::MAX_HITS) as $hit) {
            if (is_array($hit) && isset($hit['t']) && is_string($hit['t'])) {
                $hits[] = $hit;
            }
        }
        if (!$hits) {
            return 400;
        }

        $line = array(
            'ts' => $now,
            'v'  => bin2hex(substr(hash_hmac('sha256', $ip . '|' . $ua . '|' . $host, $salt, true), 0, 8)),
            'ua' => $ua,
            'ip' => self::ip_prefix($ip),
            'cc' => self::country($config, $server),
            'h'  => $host,
            'e'  => $hits,
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs without WordPress.
        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return 400;
        }
        return self::append($dir, $json . "\n") ? 204 : 503;
    }

    /**
     * Answer for the loopback test: proves this collector read this site's
     * config (WordPress compares it with its own).
     *
     * @param array<string,mixed> $config Collector config.
     * @param string              $nonce  The test's random text.
     * @return string
     */
    public static function ping(array $config, $nonce) {
        $key = isset($config['ping_key']) ? (string) $config['ping_key'] : '';
        return $key === '' ? '' : hash_hmac('sha256', (string) $nonce, $key);
    }

    /**
     * The salt for the site-local day of $now, or the newest one when cron
     * has not made today's yet. Salts older than yesterday are not in the
     * config, so old visitor hashes cannot be made again.
     *
     * @param array<string,mixed> $config Collector config.
     * @param int                 $now    Unix time.
     * @return string
     */
    public static function salt(array $config, $now) {
        $salts = isset($config['salts']) && is_array($config['salts']) ? $config['salts'] : array();
        if (!$salts) {
            return '';
        }
        $day = self::local_day($config, $now);
        if (isset($salts[$day]) && is_string($salts[$day])) {
            return $salts[$day];
        }
        krsort($salts);
        $newest = reset($salts);
        return is_string($newest) ? $newest : '';
    }

    /**
     * Site-local date (Y-m-d) of a Unix time.
     *
     * @param array<string,mixed> $config Collector config ('tz': a time zone name or offset like +01:00).
     * @param int                 $now    Unix time.
     * @return string
     */
    public static function local_day(array $config, $now) {
        $tz = isset($config['tz']) && is_string($config['tz']) && $config['tz'] !== '' ? $config['tz'] : 'UTC';
        try {
            $zone = new DateTimeZone($tz);
        } catch (Exception $e) {
            $zone = new DateTimeZone('UTC');
        }
        $date = new DateTime('@' . (int) $now);
        $date->setTimezone($zone);
        return $date->format('Y-m-d');
    }

    /**
     * The visitor's address: the header the owner trusts (behind a proxy or
     * CDN), else REMOTE_ADDR.
     *
     * @param array<string,mixed> $config Collector config ('ip_header': a $_SERVER key).
     * @param array<string,mixed> $server Request environment.
     * @return string Valid IP address, or ''.
     */
    public static function client_ip(array $config, array $server) {
        $header = isset($config['ip_header']) ? (string) $config['ip_header'] : '';
        if ($header !== '' && isset($server[$header])) {
            // X-Forwarded-For holds a list; the first entry is the client.
            $first = trim(explode(',', (string) $server[$header])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    /**
     * The network part of an address, for the location lookup only: IPv4
     * to /24, IPv6 to /48. The processor discards it after the lookup.
     *
     * @param string $ip Valid IP address.
     * @return string
     */
    public static function ip_prefix($ip) {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $keep   = strlen($packed) === 4 ? 3 : 6;
        $masked = substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep);
        $out    = inet_ntop($masked);
        return $out === false ? '' : $out;
    }

    /**
     * Whether an address is in a range: one address or CIDR (v4 or v6).
     *
     * @param string $ip    Valid IP address.
     * @param string $range Address or CIDR, such as 203.0.113.0/24.
     * @return bool
     */
    public static function ip_in_range($ip, $range) {
        $parts = explode('/', trim($range), 2);
        $net   = inet_pton($parts[0]);
        $addr  = inet_pton($ip);
        if ($net === false || $addr === false || strlen($net) !== strlen($addr)) {
            return false;
        }
        $max  = strlen($addr) * 8;
        $bits = isset($parts[1]) && ctype_digit($parts[1]) ? min((int) $parts[1], $max) : $max;

        $bytes = intdiv($bits, 8);
        if (substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($addr[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * Two-letter country from a CDN header (Cloudflare's, or the one the
     * owner names), or ''.
     *
     * @param array<string,mixed> $config Collector config ('country_header': a $_SERVER key).
     * @param array<string,mixed> $server Request environment.
     * @return string
     */
    public static function country(array $config, array $server) {
        $headers = array('HTTP_CF_IPCOUNTRY');
        if (!empty($config['country_header'])) {
            array_unshift($headers, (string) $config['country_header']);
        }
        foreach ($headers as $header) {
            $code = isset($server[$header]) ? strtoupper(trim((string) $server[$header])) : '';
            // XX: unknown; T1: Tor (Cloudflare).
            if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX' && $code !== 'T1') {
                return $code;
            }
        }
        return '';
    }

    /**
     * Append one line to the buffer under an exclusive lock. A new (empty)
     * file gets its guard line first, under the same lock, so no other
     * request can write before it.
     *
     * @param string $dir  The site's collector folder.
     * @param string $line JSON and a newline.
     * @return bool
     */
    public static function append($dir, $line) {
        $file = rtrim($dir, '/') . '/' . self::BUFFER;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- runs without WordPress.
        $handle = @fopen($file, 'a');
        if ($handle === false) {
            return false;
        }
        $ok = false;
        if (flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;
            if ($size < self::MAX_BUFFER) {
                $data = $size === 0 ? self::GUARD . $line : $line;
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- runs without WordPress.
                $ok = fwrite($handle, $data) === strlen($data);
            }
            flock($handle, LOCK_UN);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- runs without WordPress.
        fclose($handle);
        return $ok;
    }
}
