<?php
/**
 * Backlink exports: bounded staging and resumable, budgeted cron imports.
 * Staged rows live in non-autoloaded options, not public upload files.
 * Exports are samples: only page verification can mark a link lost.
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

final class SEOProStats_Backlinks_Import {

    const OPTION = 'seoprostats_backlinks_import';
    const MAX_ROWS = 100000;
    const MAX_BYTES = 52428800;
    const BATCH = 500;
    const SOURCES = array('gsc', 'ahrefs', 'semrush', 'majestic', 'moz', 'bing', 'generic');

    /** Header aliases (normalised: lowercase, punctuation removed). */
    const HEADERS = array(
        'url' => array('sourceurl', 'referringpageurl', 'referringpage', 'source', 'sourceurl', 'linkingpage', 'linkingurl', 'url', 'site', 'linkingsite'),
        'target' => array('targeturl', 'target', 'targetpage', 'linkedpage', 'destinationurl'),
        'anchor' => array('anchor', 'anchortext', 'linktext'),
        'rel' => array('rel', 'linkattributes'),
        'first' => array('firstseen', 'firstseendate', 'datefirstseen', 'firstindexeddate'),
        'last' => array('lastseen', 'lastseendate', 'datelastseen', 'lastcrawled', 'lastcrawldate'),
        'authority' => array('domainrating', 'dr', 'authorityscore', 'trustflow', 'domainauthority', 'pageauthority', 'domainascore', 'pageascore'),
    );

    /** Normalise a CSV header (including an optional UTF-8 BOM). @param string $value Header. @return string */
    private static function header($value) {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $value));
    }

    /** Detect the provider without guessing from the filename. @param string[] $headers Headers. @return string */
    public static function detect(array $headers) {
        $headers = array_map(array(__CLASS__, 'header'), $headers);
        $signatures = array(
            'gsc' => array('linkingpage', 'linkingsite', 'linkingpages', 'lastcrawled'),
            'ahrefs' => array('domainrating', 'dr', 'referringpagedomainrating'),
            'semrush' => array('authorityscore', 'pageascore', 'domainascore'),
            'majestic' => array('trustflow', 'citationflow', 'sourceurltrustflow'),
            'moz' => array('domainauthority', 'pageauthority', 'spam score'),
            'bing' => array('linkingurl', 'linkedpage'),
        );
        foreach ($signatures as $source => $signature) {
            if (array_intersect($headers, $signature)) {
                return $source;
            }
        }
        return 'generic';
    }

    /** The public job, never its staging contents. @return array<string,mixed> */
    public static function status() {
        $job = get_option(SEOProStats_Schema::option(self::OPTION), array());
        return is_array($job) && $job ? $job : array('status' => 'idle', 'source' => '', 'total' => 0, 'done' => 0, 'accepted' => 0, 'skipped' => 0);
    }

    /** Stage a stream (CSV) or JSON rows. @param resource|array $input Input. @param string $source Provider override. @return array<string,mixed>|WP_Error */
    public static function start($input, $source = '') {
        if (!SEOProStats_Schema::maybe_upgrade() || SEOProStats_Schema::set() !== 'live') {
            return new WP_Error('seoprostats_links_schema', __('Import links into the live data only.', 'seoprostats'), array('status' => 409));
        }
        if ($source !== '' && !in_array($source, self::SOURCES, true)) {
            return new WP_Error('seoprostats_links_source', __('Choose a supported export source.', 'seoprostats'), array('status' => 400));
        }
        $lock = SEOProStats_Schema::option(self::OPTION . '_lock');
        if (!add_option($lock, time(), '', false)) {
            return new WP_Error('seoprostats_links_busy', __('A links import is busy.', 'seoprostats'), array('status' => 409));
        }
        try {
            if (self::status()['status'] === 'running') {
                return new WP_Error('seoprostats_links_busy', __('A links import is running.', 'seoprostats'), array('status' => 409));
            }
            self::clear_chunks();
            $headers = is_resource($input) ? fgetcsv($input, 0, ',', '"', '') : (is_array($input) && isset($input[0]) && is_array($input[0]) ? array_keys($input[0]) : false);
            if (!$headers || !is_array($headers)) {
                return new WP_Error('seoprostats_links_header', __('The export needs a header row.', 'seoprostats'), array('status' => 400));
            }
            $headers = array_map(array(__CLASS__, 'header'), $headers);
            if (!array_intersect($headers, self::HEADERS['url'])) {
                return new WP_Error('seoprostats_links_header', __('No referring page column was found.', 'seoprostats'), array('status' => 400));
            }
            $source = $source !== '' ? $source : self::detect($headers);
            $total = 0;
            $chunk = array();
            while (true) {
                $row = is_resource($input) ? fgetcsv($input, 0, ',', '"', '') : (isset($input[$total]) ? $input[$total] : false);
                if ($row === false) {
                    break;
                }
                if ($total >= self::MAX_ROWS || (is_resource($input) && ftell($input) > self::MAX_BYTES)) {
                    self::clear_chunks();
                    return new WP_Error('seoprostats_links_size', __('Use at most 100,000 rows and 50 MB per export.', 'seoprostats'), array('status' => 400));
                }
                $values = is_array($row) ? array_values($row) : array();
                $chunk[] = count($headers) === count($values) ? array_combine($headers, $values) : array();
                ++$total;
                if (count($chunk) === self::BATCH) {
                    update_option(SEOProStats_Schema::option(self::OPTION . '_' . (int) (($total - 1) / self::BATCH)), $chunk, false);
                    $chunk = array();
                }
            }
            if ($chunk) {
                update_option(SEOProStats_Schema::option(self::OPTION . '_' . (int) ($total / self::BATCH)), $chunk, false);
            }
            $job = array('status' => $total ? 'running' : 'done', 'source' => $source, 'total' => $total, 'done' => 0, 'accepted' => 0, 'skipped' => 0);
            update_option(SEOProStats_Schema::option(self::OPTION), $job, false);
            if ($total) {
                wp_schedule_single_event(time() + 5, SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
            }
            return $job;
        } finally {
            delete_option($lock);
        }
    }

    /** Read one value by its aliases. @param array<string,mixed> $row Row. @param string $field Field. @return string */
    private static function value(array $row, $field) {
        foreach (self::HEADERS[$field] as $alias) {
            if (isset($row[$alias]) && is_scalar($row[$alias])) {
                return trim((string) $row[$alias]);
            }
        }
        return '';
    }

    /** Valid HTTP URL, no credentials, fragment or private address. @param string $url URL. @return string */
    private static function url($url) {
        $url = trim($url);
        if (!wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_USER) !== null || wp_parse_url($url, PHP_URL_PASS) !== null) {
            return '';
        }
        return explode('#', $url, 2)[0];
    }

    /** One row to a verified-site target or a referring-page candidate. @param array<string,mixed> $row Row. @param string $source Provider. @return array<string,mixed>|null */
    public static function normalise(array $row, $source) {
        $row = array_combine(array_map(array(__CLASS__, 'header'), array_keys($row)), array_values($row));
        $url = self::value($row, 'url');
        if ($source === 'gsc' && (isset($row['site']) || isset($row['linkingsite'])) && strpos($url, '://') === false) {
            $url = 'https://' . $url . '/';
        }
        $url = self::url($url);
        if ($url === '' || in_array(strtolower((string) wp_parse_url($url, PHP_URL_HOST)), SEOProStats_Collection::hosts(), true)) {
            return null;
        }
        $target = self::value($row, 'target');
        $path = '';
        if ($target !== '') {
            $target = self::url($target);
            if ($target === '' || !in_array(strtolower((string) wp_parse_url($target, PHP_URL_HOST)), SEOProStats_Collection::hosts(), true)) {
                return null;
            }
            $path = SEOProStats_Links::target(SEOProStats_Changes::path($target));
            if ($path === '') {
                return null;
            }
        } elseif ($source !== 'gsc') {
            return null;
        }
        $rel = 0;
        foreach (SEOProStats_Backlinks::REL as $name => $bit) {
            $flag = isset($row[$name]) ? strtolower((string) $row[$name]) : '';
            if (in_array($flag, array('true', '1', 'yes'), true) || preg_match('/\b' . $name . '\b/i', self::value($row, 'rel'))) {
                $rel |= $bit;
            }
        }
        $dates = array();
        foreach (array('first', 'last') as $field) {
            $value = self::value($row, $field);
            $ts = $value !== '' ? strtotime($value) : false;
            $dates[$field] = $ts !== false && $ts > 0 && $ts <= time() ? $ts : 0;
        }
        $score = self::value($row, 'authority');
        return array('url' => $url, 'path' => $path, 'anchor' => self::value($row, 'anchor'), 'rel' => $rel, 'first' => $dates['first'], 'last' => $dates['last'], 'authority' => is_numeric($score) ? max(0, min(100, (int) $score)) : null);
    }

    /** Cron or CLI: bounded batches, restartable at the last completed row. @param int $budget Seconds. @return array<string,mixed> */
    public static function run($budget = 20) {
        require_once __DIR__ . '/class-seoprostats-backlinks.php';
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-links.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        $lock = SEOProStats_Schema::option(self::OPTION . '_lock');
        // A crashed worker's lease expires; a normal worker removes it in finally.
        $held = (int) get_option($lock, 0);
        if ($held && $held < time() - 300) {
            delete_option($lock);
        }
        if (!add_option($lock, time(), '', false)) {
            return self::status();
        }
        try {
            $job = self::status();
            $start = microtime(true);
            while ($job['status'] === 'running' && SEOProStats_Feature::more_time($start, $budget)) {
                $index = (int) ($job['done'] / self::BATCH);
                $rows = get_option(SEOProStats_Schema::option(self::OPTION . '_' . $index), array());
                $offset = $job['done'] % self::BATCH;
                if (!is_array($rows) || !isset($rows[$offset])) {
                    $job['status'] = 'error';
                    break;
                }
                $link = self::normalise((array) $rows[$offset], $job['source']);
                if ($link !== null && !self::write($link, $job['source'])) {
                    $job['status'] = 'error';
                    break;
                }
                ++$job[$link === null ? 'skipped' : 'accepted'];
                ++$job['done'];
                update_option(SEOProStats_Schema::option(self::OPTION), $job, false);
                if ($job['done'] % self::BATCH === 0 || $job['done'] === $job['total']) {
                    delete_option(SEOProStats_Schema::option(self::OPTION . '_' . $index));
                }
                if ($job['done'] === $job['total']) {
                    $job['status'] = 'done';
                }
            }
            update_option(SEOProStats_Schema::option(self::OPTION), $job, false);
            // Bust report caches even when two batches finish within one second.
            $state = get_option(SEOProStats_Schema::option(SEOProStats_Backlinks::OPTION), array());
            $state = is_array($state) ? $state : array();
            $state['version'] = max(time(), isset($state['version']) ? (int) $state['version'] + 1 : 0);
            update_option(SEOProStats_Schema::option(SEOProStats_Backlinks::OPTION), $state, false);
            if ($job['status'] === 'running') {
                wp_schedule_single_event(time() + 60, SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
            }
            return $job;
        } finally {
            delete_option($lock);
        }
    }

    /** Keep a candidate and its link without changing verification state. @param array<string,mixed> $link Link. @param string $source Provider. @return bool */
    private static function write(array $link, $source) {
        global $wpdb;
        $url = $link['url'];
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $url_id = self::dict_id(SEOProStats_Schema::DICT_URL, $url);
        $host_id = self::dict_id(SEOProStats_Schema::DICT_HOST, $host);
        $path_id = $link['path'] !== '' ? self::dict_id(SEOProStats_Schema::DICT_PATH, $link['path']) : 0;
        $anchor = wp_strip_all_tags($link['anchor']);
        $anchor = function_exists('mb_substr') ? mb_substr($anchor, 0, SEOProStats_Backlinks::MAX_ANCHOR) : substr($anchor, 0, SEOProStats_Backlinks::MAX_ANCHOR);
        $anchor_id = self::dict_id(SEOProStats_Schema::DICT_LABEL, $anchor);
        if (!$url_id || !$host_id || ($link['path'] !== '' && !$path_id)) {
            return false;
        }
        foreach (array_unique(array(0, $path_id)) as $path) {
            $key = SEOProStats_Dict::hash($url . "\t" . $path);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table, unique lkey lookup.
            $known = $wpdb->get_row($wpdb->prepare('SELECT providers FROM %i WHERE lkey = UNHEX(%s)', SEOProStats_Schema::table('links'), $key), ARRAY_A);
            $providers = is_array($known) ? json_decode($known['providers'], true) : array();
            $providers = is_array($providers) ? $providers : array();
            $old = isset($providers[$source]) ? $providers[$source] : array();
            $providers[$source] = array('authority' => $link['authority'] !== null ? $link['authority'] : (isset($old['authority']) ? $old['authority'] : null), 'last_seen' => max($link['last'], isset($old['last_seen']) ? (int) $old['last_seen'] : 0));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table, unique lkey upsert; exports never reset lost, checked or misses.
            $ok = $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (lkey, source_host_id, source_url_id, path_id, anchor_id, rel, found, status, first_seen, last_seen, providers) VALUES (UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d, %d, %s) ON DUPLICATE KEY UPDATE found = found | VALUES(found), first_seen = IF(first_seen = 0, VALUES(first_seen), IF(VALUES(first_seen) = 0, first_seen, LEAST(first_seen, VALUES(first_seen)))), last_seen = GREATEST(last_seen, VALUES(last_seen)), providers = VALUES(providers)',
                SEOProStats_Schema::table('links'), $key, $host_id, $url_id, $path, $path ? $anchor_id : 0, $path ? $link['rel'] : 0, SEOProStats_Backlinks::FOUND[$source], $path ? SEOProStats_Backlinks::LINK_LIVE : SEOProStats_Backlinks::PAGE_NONE, $link['first'], $link['last'], (string) wp_json_encode($providers)
            ));
            if ($ok === false) {
                return false;
            }
        }
        return true;
    }

    /** One dictionary id. @param int $kind Kind. @param string $value Text. @return int */
    private static function dict_id($kind, $value) {
        $ids = SEOProStats_Dict::ids($kind, array($value));
        return isset($ids[SEOProStats_Dict::clean($value)]) ? (int) $ids[SEOProStats_Dict::clean($value)] : 0;
    }

    /** Remove bounded staging options. */
    private static function clear_chunks() {
        for ($i = 0; $i < self::MAX_ROWS / self::BATCH; ++$i) {
            delete_option(SEOProStats_Schema::option(self::OPTION . '_' . $i));
        }
    }

    /** Uninstall/demo cleanup for the current data set. */
    public static function reset() {
        self::clear_chunks();
        delete_option(SEOProStats_Schema::option(self::OPTION));
        delete_option(SEOProStats_Schema::option(self::OPTION . '_lock'));
        wp_clear_scheduled_hook(SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
    }
}
