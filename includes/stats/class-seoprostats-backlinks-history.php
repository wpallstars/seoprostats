<?php
/**
 * The history of link export imports (Settings → Import → Links), with a
 * copy of each uploaded file to download again.
 *
 * Entries live in one option per data set (autoload off), newest first,
 * at most MAX_ENTRIES. Files are kept in wp-content/seoprostats/links-{blog
 * id}/ (the folder the collector's deny-all .htaccess covers, with its
 * own), under random names, and only the owner's REST route serves them.
 * Older files go once the kept files pass MAX_BYTES; their entries stay.
 * No media-library attachment and nothing on visitor pages.
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

final class SEOProStats_Backlinks_History {

    /** The entries (per data set, autoload off). */
    const OPTION = 'seoprostats_backlinks_imports';

    /** Entries kept, and the most bytes of files kept (200 MB). */
    const MAX_ENTRIES = 20;
    const MAX_BYTES   = 209715200;

    /** A kept file's name: its entry's id, a random part, .csv. */
    const FILE_NAME = '/^\d+-[A-Za-z0-9]{32}\.csv$/';

    /**
     * The folder of the kept files.
     *
     * @return string
     */
    public static function dir() {
        return WP_CONTENT_DIR . '/seoprostats/links-' . get_current_blog_id();
    }

    /**
     * The entries, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function entries() {
        $entries = get_option(SEOProStats_Schema::option(self::OPTION), array());
        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : array();
    }

    /**
     * Record an import just staged, with a copy of its file.
     *
     * @param array<string,mixed> $job  SEOProStats_Backlinks_Import::start()'s job.
     * @param string              $file The uploaded or given file ('' for JSON rows).
     * @param string              $name Its name as the person chose it.
     * @param string              $via  upload, cli or rest.
     * @return array<string,mixed> The entry.
     */
    public static function add(array $job, $file, $name, $via) {
        $entries = self::entries();
        $ids     = array_map('intval', array_column($entries, 'id'));
        $name    = sanitize_file_name(wp_basename((string) $name));
        $entry   = array(
            'id'       => $ids ? max($ids) + 1 : 1,
            'job'      => isset($job['started']) ? (int) $job['started'] : time(),
            'started'  => time(),
            'finished' => $job['status'] === 'running' ? 0 : time(),
            'user'     => get_current_user_id(),
            'via'      => (string) $via,
            'name'     => $name !== '' ? $name : 'links.csv',
            'bytes'    => 0,
            'sha256'   => '',
            'file'     => '',
            'source'   => (string) $job['source'],
            'status'   => (string) $job['status'],
            'total'    => (int) $job['total'],
            'accepted' => (int) $job['accepted'],
            'skipped'  => (int) $job['skipped'],
        );
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $entry = self::keep_file($entry, $file);
        }
        array_unshift($entries, $entry);
        self::save(self::trim($entries));
        return $entry;
    }

    /**
     * Record how an import ended (its entry still running only).
     *
     * @param array<string,mixed> $job The finished job.
     */
    public static function finish(array $job) {
        if (empty($job['started'])) {
            return;
        }
        $entries = self::entries();
        foreach ($entries as $i => $entry) {
            if ((int) $entry['job'] === (int) $job['started'] && $entry['status'] === 'running') {
                $entries[$i] = array(
                    'status'   => (string) $job['status'],
                    'finished' => time(),
                    'total'    => (int) $job['total'],
                    'accepted' => (int) $job['accepted'],
                    'skipped'  => (int) $job['skipped'],
                ) + $entry;
                self::save($entries);
                return;
            }
        }
    }

    /**
     * The entries for the REST API, WP-CLI and the Import tab.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function out() {
        require_once __DIR__ . '/class-seoprostats-backlinks-import.php';
        $job = SEOProStats_Backlinks_Import::status();
        $out = array();
        foreach (self::entries() as $entry) {
            // The running entry's progress is the job's.
            if ($entry['status'] === 'running' && isset($job['started']) && (int) $job['started'] === (int) $entry['job']) {
                $entry = array('status' => $job['status'], 'accepted' => (int) $job['accepted'], 'skipped' => (int) $job['skipped'], 'done' => (int) $job['done']) + $entry;
            }
            $user  = $entry['user'] ? get_userdata((int) $entry['user']) : false;
            $out[] = array(
                'id'       => (int) $entry['id'],
                'started'  => (string) wp_date('c', (int) $entry['started']),
                'finished' => $entry['finished'] ? (string) wp_date('c', (int) $entry['finished']) : null,
                'user'     => $user ? $user->display_name : '',
                'via'      => (string) $entry['via'],
                'name'     => (string) $entry['name'],
                'bytes'    => (int) $entry['bytes'],
                'sha256'   => (string) $entry['sha256'],
                'file'     => self::path($entry) !== '',
                'source'   => (string) $entry['source'],
                'status'   => (string) $entry['status'],
                'total'    => (int) $entry['total'],
                'done'     => isset($entry['done']) ? (int) $entry['done'] : (int) $entry['total'],
                'accepted' => (int) $entry['accepted'],
                'skipped'  => (int) $entry['skipped'],
            );
        }
        return $out;
    }

    /**
     * An entry's kept file: its path and download name, or null.
     *
     * @param int $id Entry id.
     * @return array{path:string,name:string}|null
     */
    public static function file($id) {
        foreach (self::entries() as $entry) {
            if ((int) $entry['id'] === (int) $id) {
                $path = self::path($entry);
                return $path !== '' ? array('path' => $path, 'name' => (string) $entry['name']) : null;
            }
        }
        return null;
    }

    /**
     * Delete the entries and their files (demo removal, uninstall).
     *
     * A folder that cannot be removed stays; its warning is not an error here.
     * @SuppressWarnings("PHPMD.ErrorControlOperator")
     */
    public static function reset() {
        foreach (self::entries() as $entry) {
            self::delete_file($entry);
        }
        delete_option(SEOProStats_Schema::option(self::OPTION));
        if (SEOProStats_Schema::set() !== 'live') {
            return;
        }
        $dir = self::dir();
        foreach (array('index.php', '.htaccess') as $guard) {
            if (is_file($dir . '/' . $guard)) {
                wp_delete_file($dir . '/' . $guard);
            }
        }
        if (is_dir($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own folder; one that cannot be removed (someone added files) stays.
            @rmdir($dir);
        }
    }

    /**
     * Keep a copy of the file in the folder, with its size and SHA-256.
     *
     * @param array<string,mixed> $entry The entry.
     * @param string              $file  The file.
     * @return array<string,mixed> The entry, with file, bytes and sha256 when kept.
     */
    private static function keep_file(array $entry, $file) {
        if (!self::guard()) {
            return $entry;
        }
        $name = $entry['id'] . '-' . wp_generate_password(32, false) . '.csv';
        $path = self::dir() . '/' . $name;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- a copy into our own folder; WP_Filesystem may ask for FTP details.
        if (!copy($file, $path)) {
            return $entry;
        }
        $entry['file']   = $name;
        $entry['bytes']  = (int) filesize($path);
        $entry['sha256'] = (string) hash_file('sha256', $path);
        return $entry;
    }

    /**
     * Make the folder with its guard files.
     *
     * @return bool
     */
    private static function guard() {
        $dir = self::dir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        $guards = array(
            $dir . '/index.php' => "<?php\n// Silence is golden.\n",
            $dir . '/.htaccess' => "# SEO Pro Stats: no direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
        );
        foreach ($guards as $file => $content) {
            if (!is_file($file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small file in our own folder; WP_Filesystem may ask for FTP details.
                file_put_contents($file, $content);
            }
        }
        return true;
    }

    /**
     * Keep MAX_ENTRIES entries and at most MAX_BYTES of files, newest first.
     *
     * @param array<int,array<string,mixed>> $entries Entries, newest first.
     * @return array<int,array<string,mixed>>
     */
    private static function trim(array $entries) {
        foreach (array_slice($entries, self::MAX_ENTRIES) as $entry) {
            self::delete_file($entry);
        }
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);
        $bytes   = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['file'] === '') {
                continue;
            }
            $bytes += (int) $entry['bytes'];
            if ($bytes > self::MAX_BYTES && $i > 0) {
                self::delete_file($entry);
                $entries[$i]['file'] = '';
            }
        }
        return $entries;
    }

    /**
     * An entry's kept file, '' when it has none (any more).
     *
     * @param array<string,mixed> $entry The entry.
     * @return string
     */
    private static function path(array $entry) {
        $name = isset($entry['file']) ? (string) $entry['file'] : '';
        if ($name === '' || !preg_match(self::FILE_NAME, $name)) {
            return '';
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : '';
    }

    /**
     * Delete an entry's kept file.
     *
     * @param array<string,mixed> $entry The entry.
     */
    private static function delete_file(array $entry) {
        $path = self::path($entry);
        if ($path !== '') {
            wp_delete_file($path);
        }
    }

    /**
     * Save the entries.
     *
     * @param array<int,array<string,mixed>> $entries Entries, newest first.
     */
    private static function save(array $entries) {
        update_option(SEOProStats_Schema::option(self::OPTION), array_values($entries), false);
    }
}
