<?php
/**
 * The dictionary table: each distinct text of a kind (path, host, event
 * name…) stored once and referenced by a small number, so fact rows and
 * their indexes stay small.
 *
 * Lookups are in bulk: one INSERT IGNORE and one SELECT per kind per batch,
 * by an 8-byte hash of the text (the unique key), with a cache for the
 * request.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Dict {

    /** Longest text kept (the column's size). */
    const MAX_LENGTH = 2048;

    /** Rows per INSERT or SELECT. */
    const CHUNK = 500;

    /** @var array<int,array<string,int>> Kind => text => id. */
    private static $cache = array();

    /**
     * The 8-byte key of a text, as 16 hex digits. Binary values always go
     * to MySQL as UNHEX(%s): $wpdb may strip bytes that are not valid UTF-8.
     *
     * @param string $value Text.
     * @return string Hex.
     */
    public static function hash($value) {
        return substr(hash('sha256', $value), 0, 16);
    }

    /**
     * Normalise a text the way it is stored.
     *
     * @param string $value Text.
     * @return string
     */
    public static function clean($value) {
        $value = (string) $value;
        if (strlen($value) > self::MAX_LENGTH) {
            $value = function_exists('mb_strcut') ? mb_strcut($value, 0, self::MAX_LENGTH, 'UTF-8') : substr($value, 0, self::MAX_LENGTH);
        }
        return $value;
    }

    /**
     * IDs for texts of one kind, adding the new ones. '' is always 0.
     *
     * @param int      $kind   SEOProStats_Schema::DICT_* constant.
     * @param string[] $values Texts.
     * @return array<string,int> Text (cleaned) => id.
     */
    public static function ids($kind, array $values) {
        global $wpdb;
        $kind  = (int) $kind;
        $table = SEOProStats_Schema::table('dict');
        $known = isset(self::$cache[$kind]) ? self::$cache[$kind] : array();
        $out   = array('' => 0);
        $need  = array();

        foreach ($values as $value) {
            $value = self::clean($value);
            if ($value === '' || isset($out[$value])) {
                continue;
            }
            if (isset($known[$value])) {
                $out[$value] = $known[$value];
            } else {
                $need[self::hash($value)] = $value;
            }
        }

        foreach (array_chunk($need, self::CHUNK, true) as $chunk) {
            $rows = array();
            $args = array();
            foreach ($chunk as $hash => $value) {
                $rows[] = '(%d, UNHEX(%s), %s)';
                array_push($args, $kind, (string) $hash, $value);
            }
            $values = implode(', ', $rows);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; $values holds only fixed placeholder groups.
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (kind, hash, value) VALUES $values", array_merge(array($table), $args)));

            $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its unique key; $holders holds only fixed placeholders, one per value.
            $found = $wpdb->get_results($wpdb->prepare("SELECT id, LOWER(HEX(hash)) AS h FROM %i WHERE kind = %d AND hash IN ($holders)", array_merge(array($table, $kind), array_map('strval', array_keys($chunk)))));
            foreach ((array) $found as $row) {
                if (isset($chunk[$row->h])) {
                    $out[$chunk[$row->h]] = (int) $row->id;
                }
            }
        }

        foreach ($out as $value => $id) {
            if ($id > 0) {
                $known[(string) $value] = $id;
            }
        }
        // Keep the cache bounded for long batches.
        self::$cache[$kind] = count($known) > 20000 ? array() : $known;
        return $out;
    }

    /**
     * Texts for IDs (for reports).
     *
     * @param int[] $ids IDs.
     * @return array<int,string> Id => text.
     */
    public static function values(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, by primary key; placeholders built above.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, value FROM %i WHERE id IN ($holders)", array_merge(array(SEOProStats_Schema::table('dict')), $chunk)));
            foreach ((array) $rows as $row) {
                $out[(int) $row->id] = (string) $row->value;
            }
        }
        return $out;
    }
}
