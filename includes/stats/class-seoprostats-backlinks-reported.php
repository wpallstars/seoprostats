<?php
/**
 * Search → Backlinks → Reported: the referring pages link exports named
 * (SEOProStats_Backlinks_Import), checked or not. Search Console names
 * the linking page but not the page it links to, so each is a referring
 * page's own row (path_id 0) until the check opens it and keeps its links.
 *
 * Reads at most MAX_ROWS referring pages by the path_checked key, never
 * checked first; the live links per page come from the report's own read.
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

final class SEOProStats_Backlinks_Reported {

    /** A reported page's check: not opened yet, links to the site, none seen, could not be opened, or gone (404, 410). */
    const STATES = array('unchecked', 'links', 'none', 'error', 'gone');

    /**
     * The reported pages, newest report first, with their counts.
     *
     * @param array<int,array<string,string>> $live   The report's live link rows (of the source, when given).
     * @param string                          $source One of SEOProStats_Backlinks::FOUND, or '' for all.
     * @return array{rows:array<int,array<string,mixed>>,domains:int,checked:int}
     */
    public static function read(array $live, $source) {
        $pages = self::pages($source);
        $texts = self::texts($pages);
        $links = array_count_values(array_map('intval', array_column($live, 'source_url_id')));
        $rows  = array();
        $hosts = array();
        $done  = 0;
        foreach ($pages as $page) {
            $row     = self::row($page, $texts, $links);
            $rows[]  = $row;
            $done   += $row['state'] === 'unchecked' ? 0 : 1;
            $hosts[$row['host']] = true;
        }
        usort($rows, static function ($a, $b) {
            return array((string) $b['reported'], $a['host'], $a['source']) <=> array((string) $a['reported'], $b['host'], $b['source']);
        });
        return array('rows' => $rows, 'domains' => count($hosts), 'checked' => $done);
    }

    /**
     * Referring pages' own rows that an export named (and of the source).
     *
     * @param string $source One of SEOProStats_Backlinks::FOUND, or ''.
     * @return array<int,array<string,string>>
     */
    private static function pages($source) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, the path_id = 0 range of its path_checked key in its order.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT source_host_id, source_url_id, found, status, checked, misses, first_seen, last_seen, providers FROM %i FORCE INDEX (`path_checked`) WHERE path_id = 0 ORDER BY checked LIMIT %d', SEOProStats_Schema::table('links'), SEOProStats_Backlinks::MAX_ROWS), ARRAY_A);
        $bit  = $source !== '' ? SEOProStats_Backlinks::FOUND[$source] : 0;
        return array_values(array_filter($rows, static function ($row) use ($bit) {
            return ((int) $row['found'] & SEOProStats_Backlinks::EXPORTS) && (!$bit || ((int) $row['found'] & $bit));
        }));
    }

    /**
     * The hosts' and addresses' texts.
     *
     * @param array<int,array<string,string>> $pages Rows.
     * @return array<int,string>
     */
    private static function texts(array $pages) {
        $ids = array();
        foreach ($pages as $page) {
            array_push($ids, (int) $page['source_host_id'], (int) $page['source_url_id']);
        }
        return SEOProStats_Dict::values($ids);
    }

    /**
     * One reported page as the report gives it.
     *
     * @param array<string,string> $page  Its row.
     * @param array<int,string>    $texts Id => text.
     * @param array<int,int>       $links Live links by referring page.
     * @return array<string,mixed>
     */
    private static function row(array $page, array $texts, array $links) {
        $url       = isset($texts[(int) $page['source_url_id']]) ? (string) $texts[(int) $page['source_url_id']] : '';
        $host      = isset($texts[(int) $page['source_host_id']]) ? (string) $texts[(int) $page['source_host_id']] : (string) wp_parse_url($url, PHP_URL_HOST);
        $providers = json_decode((string) $page['providers'], true);
        $providers = is_array($providers) ? $providers : array();
        $reported  = (int) $page['last_seen'];
        $out       = array();
        foreach ($providers as $name => $facts) {
            $seen         = isset($facts['last_seen']) ? (int) $facts['last_seen'] : 0;
            $reported     = max($reported, $seen);
            $out[$name]   = array(
                'authority' => isset($facts['authority']) ? $facts['authority'] : null,
                'last_seen' => self::date($seen),
            );
        }
        $found = array();
        foreach (SEOProStats_Backlinks::FOUND as $name => $bit) {
            if ((int) $page['found'] & $bit) {
                $found[] = $name;
            }
        }
        return array(
            'source'    => $url,
            'host'      => $host,
            'found'     => $found,
            'state'     => self::state($page),
            'links'     => isset($links[(int) $page['source_url_id']]) ? (int) $links[(int) $page['source_url_id']] : 0,
            'reported'  => self::date($reported),
            'checked'   => self::date((int) $page['checked']),
            'providers' => (object) $out,
        );
    }

    /**
     * A reported page's check, in one word (STATES).
     *
     * @param array<string,string> $page Its row (checked, status, misses).
     * @return string
     */
    private static function state(array $page) {
        if (!(int) $page['checked']) {
            return 'unchecked';
        }
        if ((int) $page['status'] === SEOProStats_Backlinks::PAGE_LINKS) {
            return 'links';
        }
        if ((int) $page['status'] === SEOProStats_Backlinks::PAGE_GONE) {
            return 'gone';
        }
        return (int) $page['misses'] > 0 ? 'error' : 'none';
    }

    /**
     * A time for the answer (ISO 8601 in the site's time zone), null for 0.
     *
     * @param int $ts Unix seconds.
     * @return string|null
     */
    private static function date($ts) {
        return $ts > 0 ? (string) wp_date('c', $ts) : null;
    }
}
