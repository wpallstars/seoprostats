<?php
/**
 * Resumable backlink export jobs; parsing, staging and storage stay separate.
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

    /** Public progress, never staged rows. @return array<string,mixed> */
    public static function status() {
        $job = get_option(SEOProStats_Schema::option(self::OPTION), array());
        return is_array($job) && $job ? $job : array('status' => 'idle', 'source' => '', 'total' => 0, 'done' => 0, 'accepted' => 0, 'skipped' => 0);
    }

    /** Stage CSV or JSON. @param resource|array $input Input. @param string $source Provider. @return array<string,mixed>|WP_Error */
    public static function start($input, $source = '') {
        require_once __DIR__ . '/class-seoprostats-backlinks-stage.php';
        return SEOProStats_Backlinks_Stage::start($input, $source);
    }

    /** Detect export headers. @param string[] $headers Headers. @return string */
    public static function detect(array $headers) {
        require_once __DIR__ . '/class-seoprostats-backlinks-csv.php';
        return SEOProStats_Backlinks_CSV::detect($headers);
    }

    /** Parse an export row. @param array<string,mixed> $row Row. @param string $source Provider. @return array<string,mixed>|null */
    public static function normalise(array $row, $source) {
        require_once __DIR__ . '/class-seoprostats-backlinks-csv.php';
        return SEOProStats_Backlinks_CSV::normalise($row, $source);
    }

    /** Acquire a five-minute crash-recovery lease. @return bool */
    public static function lock() {
        $lock = SEOProStats_Schema::option(self::OPTION . '_lock');
        $held = (int) get_option($lock, 0);
        if ($held && $held < time() - 300) {
            delete_option($lock);
        }
        return add_option($lock, time(), '', false);
    }

    /** Release this job's lease. */
    public static function unlock() {
        delete_option(SEOProStats_Schema::option(self::OPTION . '_lock'));
    }

    /** Cron/CLI budgeted processing. @param int $budget Seconds. @return array<string,mixed> */
    public static function run($budget = 20) {
        $job = self::status();
        if ($job['status'] === 'running' && !wp_next_scheduled(SEOProStats_Collection::BACKLINK_IMPORT_HOOK)) {
            // Persist recovery before processing or a busy-lock return.
            wp_schedule_single_event(time() + 60, SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
        }
        if (!self::lock()) {
            return self::status();
        }
        try {
            return self::work($budget);
        } finally {
            self::unlock();
        }
    }

    /** Work under the lease. @param int $budget Seconds. @return array<string,mixed> */
    private static function work($budget) {
        require_once __DIR__ . '/class-seoprostats-backlinks.php';
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-links.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-backlinks-store.php';
        $job = self::status();
        if ($job['status'] === 'running' && $job['done'] >= $job['total']) {
            $job['status'] = 'done';
        }
        $start = microtime(true);
        while ($job['status'] === 'running' && SEOProStats_Feature::more_time($start, $budget)) {
            self::step($job);
        }
        update_option(SEOProStats_Schema::option(self::OPTION), $job, false);
        self::invalidate();
        if ($job['status'] !== 'running') {
            wp_clear_scheduled_hook(SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
            self::clear_chunks();
        }
        return $job;
    }

    /** Process one row, preserving a restartable checkpoint. @param array<string,mixed> $job Job, updated. */
    private static function step(array &$job) {
        $index = (int) ($job['done'] / self::BATCH);
        $rows = get_option(SEOProStats_Schema::option(self::OPTION . '_' . $index), array());
        $offset = $job['done'] % self::BATCH;
        if (!is_array($rows) || !isset($rows[$offset])) {
            $job['status'] = 'error';
            return;
        }
        $link = self::normalise((array) $rows[$offset], $job['source']);
        if ($link !== null && !SEOProStats_Backlinks_Store::write($link, $job['source'])) {
            $job['status'] = 'error';
            return;
        }
        ++$job[$link === null ? 'skipped' : 'accepted'];
        ++$job['done'];
        self::checkpoint($job, $index);
    }

    /** Final status precedes final-chunk deletion. @param array<string,mixed> $job Job. @param int $index Chunk. */
    private static function checkpoint(array &$job, $index) {
        if ($job['done'] === $job['total']) {
            $job['status'] = 'done';
        }
        if ($job['done'] % self::BATCH === 0 || $job['status'] === 'done') {
            update_option(SEOProStats_Schema::option(self::OPTION), $job, false);
            delete_option(SEOProStats_Schema::option(self::OPTION . '_' . $index));
        }
    }

    /** Monotonic report cache version even within one second. */
    private static function invalidate() {
        $state = get_option(SEOProStats_Schema::option(SEOProStats_Backlinks::OPTION), array());
        $state = is_array($state) ? $state : array();
        $state['version'] = max(time(), isset($state['version']) ? (int) $state['version'] + 1 : 0);
        update_option(SEOProStats_Schema::option(SEOProStats_Backlinks::OPTION), $state, false);
    }

    /** Delete the bounded staging options. */
    public static function clear_chunks() {
        for ($i = 0; $i < self::MAX_ROWS / self::BATCH; ++$i) {
            delete_option(SEOProStats_Schema::option(self::OPTION . '_' . $i));
        }
    }

    /** Cleanup this data set without cancelling another set's live job. */
    public static function reset() {
        self::clear_chunks();
        delete_option(SEOProStats_Schema::option(self::OPTION));
        self::unlock();
        if (SEOProStats_Schema::set() === 'live') {
            wp_clear_scheduled_hook(SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
        }
    }
}
