<?php
/**
 * Bounded backlink export staging in non-autoloaded option chunks.
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

final class SEOProStats_Backlinks_Stage {
    /** Stage under the import lease. @param resource|array $input Input. @param string $source Provider. @return array<string,mixed>|WP_Error */
    public static function start($input, $source) {
        if (!SEOProStats_Schema::maybe_upgrade() || SEOProStats_Schema::set() !== 'live') {
            return new WP_Error('seoprostats_links_schema', __('Import links into the live data only.', 'seoprostats'), array('status' => 409));
        }
        if ($source !== '' && !in_array($source, SEOProStats_Backlinks_Import::SOURCES, true)) {
            return new WP_Error('seoprostats_links_source', __('Choose a supported export source.', 'seoprostats'), array('status' => 400));
        }
        if (!SEOProStats_Backlinks_Import::lock()) {
            return new WP_Error('seoprostats_links_busy', __('A links import is busy.', 'seoprostats'), array('status' => 409));
        }
        try {
            if (SEOProStats_Backlinks_Import::status()['status'] === 'running') {
                return new WP_Error('seoprostats_links_busy', __('A links import is running.', 'seoprostats'), array('status' => 409));
            }
            return self::stage($input, $source);
        } finally {
            SEOProStats_Backlinks_Import::unlock();
        }
    }

    /** Read and validate headers. @param resource|array $input Input. @return string[]|WP_Error */
    private static function headers($input) {
        require_once __DIR__ . '/class-seoprostats-backlinks-csv.php';
        if (is_resource($input)) {
            $headers = fgetcsv($input, 0, ',', '"', '');
        } else {
            $headers = isset($input[0]) && is_array($input[0]) ? array_keys($input[0]) : false;
        }
        if (!$headers) {
            return new WP_Error('seoprostats_links_header', __('The export needs a header row.', 'seoprostats'), array('status' => 400));
        }
        $headers = array_map(array('SEOProStats_Backlinks_CSV', 'header'), $headers);
        if (!array_intersect($headers, SEOProStats_Backlinks_CSV::HEADERS['url'])) {
            return new WP_Error('seoprostats_links_header', __('No referring page column was found.', 'seoprostats'), array('status' => 400));
        }
        return $headers;
    }

    /** Read the next CSV or JSON row. @param resource|array $input Input. @param int $at Index. @return array|false */
    private static function row($input, $at) {
        if (is_resource($input)) {
            return fgetcsv($input, 0, ',', '"', '');
        }
        if (!isset($input[$at])) {
            return false;
        }
        return is_array($input[$at]) ? $input[$at] : array();
    }

    /** Canonical CSV column map, preserving JSON object keys. @param array $row Row. @param string[] $headers Headers. @param bool $csv CSV. @return array */
    private static function map(array $row, array $headers, $csv) {
        if (!$csv) {
            return $row;
        }
        if (count($headers) !== count($row)) {
            return array();
        }
        return array_combine($headers, array_values($row));
    }

    /** Check row and byte caps before staging the next row. @param resource|array $input Input. @param int $total Count. @return bool */
    private static function too_large($input, $total) {
        if ($total >= SEOProStats_Backlinks_Import::MAX_ROWS) {
            return true;
        }
        return is_resource($input) && ftell($input) > SEOProStats_Backlinks_Import::MAX_BYTES;
    }

    /** Stage validated headers without building all rows in memory. @param resource|array $input Input. @param string $source Provider. @return array<string,mixed>|WP_Error */
    private static function stage($input, $source) {
        SEOProStats_Backlinks_Import::clear_chunks();
        $headers = self::headers($input);
        if (is_wp_error($headers)) {
            return $headers;
        }
        $source = $source !== '' ? $source : SEOProStats_Backlinks_CSV::detect($headers);
        $total = 0;
        $chunk = array();
        while (true) {
            $row = self::row($input, $total);
            if ($row === false) {
                break;
            }
            if (self::too_large($input, $total)) {
                SEOProStats_Backlinks_Import::clear_chunks();
                return new WP_Error('seoprostats_links_size', __('Use at most 100,000 rows and 50 MB per export.', 'seoprostats'), array('status' => 400));
            }
            $chunk[] = self::map($row, $headers, is_resource($input));
            ++$total;
            if (count($chunk) === SEOProStats_Backlinks_Import::BATCH) {
                self::save($chunk, (int) (($total - 1) / SEOProStats_Backlinks_Import::BATCH));
                $chunk = array();
            }
        }
        if ($chunk) {
            self::save($chunk, (int) ($total / SEOProStats_Backlinks_Import::BATCH));
        }
        return self::job($source, $total);
    }

    /** Save a non-autoloaded chunk. @param array $chunk Rows. @param int $index Index. */
    private static function save(array $chunk, $index) {
        update_option(SEOProStats_Schema::option(SEOProStats_Backlinks_Import::OPTION . '_' . $index), $chunk, false);
    }

    /** Publish the job after staging succeeds. @param string $source Provider. @param int $total Count. @return array<string,mixed> */
    private static function job($source, $total) {
        // started ties the job to its entry in the import history.
        $job = array('status' => $total ? 'running' : 'done', 'source' => $source, 'total' => $total, 'done' => 0, 'accepted' => 0, 'skipped' => 0, 'started' => time());
        update_option(SEOProStats_Schema::option(SEOProStats_Backlinks_Import::OPTION), $job, false);
        if ($total) {
            wp_schedule_single_event(time() + 5, SEOProStats_Collection::BACKLINK_IMPORT_HOOK);
        }
        return $job;
    }
}
