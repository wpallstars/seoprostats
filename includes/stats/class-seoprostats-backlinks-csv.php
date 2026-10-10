<?php
/**
 * Backlink export headers and rows, without storage or remote requests.
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

final class SEOProStats_Backlinks_CSV {
    const HEADERS = array(
        'url' => array('sourceurl', 'referringpageurl', 'referringpage', 'source', 'linkingpage', 'linkingurl', 'url', 'site', 'linkingsite'),
        'target' => array('targeturl', 'target', 'targetpage', 'linkedpage', 'destinationurl'),
        'anchor' => array('anchor', 'anchortext', 'linktext'),
        'rel' => array('rel', 'linkattributes'),
        'first' => array('firstseen', 'firstseendate', 'datefirstseen', 'firstindexeddate'),
        'last' => array('lastseen', 'lastseendate', 'datelastseen', 'lastcrawled', 'lastcrawldate'),
        'authority' => array('domainrating', 'dr', 'authorityscore', 'trustflow', 'domainauthority', 'pageauthority', 'domainascore', 'pageascore'),
    );

    /** Header normalisation includes a UTF-8 BOM. @param string $value Header. @return string */
    public static function header($value) {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $value));
    }

    /** Provider signatures; ambiguous headers stay generic. @param string[] $headers Headers. @return string */
    public static function detect(array $headers) {
        $headers = array_map(array(__CLASS__, 'header'), $headers);
        $signatures = array(
            'gsc' => array('linkingpage', 'linkingsite', 'linkingpages', 'lastcrawled'),
            'ahrefs' => array('domainrating', 'dr', 'referringpagedomainrating'),
            'semrush' => array('authorityscore', 'pageascore', 'domainascore'),
            'majestic' => array('trustflow', 'citationflow', 'sourceurltrustflow'),
            'moz' => array('domainauthority', 'pageauthority', 'spamscore'),
            'bing' => array('linkingurl', 'linkedpage'),
        );
        foreach ($signatures as $source => $signature) {
            if (array_intersect($headers, $signature)) {
                return $source;
            }
        }
        return 'generic';
    }

    /** One scalar field by header aliases. @param array<string,mixed> $row Row. @param string $field Field. @return string */
    private static function value(array $row, $field) {
        foreach (self::HEADERS[$field] as $alias) {
            if (isset($row[$alias]) && is_scalar($row[$alias])) {
                return trim((string) $row[$alias]);
            }
        }
        return '';
    }

    /** Normalise scalar cells. @param array<string,mixed> $row Row. @return array<string,mixed>|null */
    private static function cells(array $row) {
        $normal = array();
        foreach ($row as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                return null;
            }
            $normal[self::header((string) $key)] = $value;
        }
        return $normal;
    }

    /** HTTP URL without credentials or fragment; fetching is separately safe. @param string $url URL. @return string */
    private static function url($url) {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true) || wp_parse_url($url, PHP_URL_USER) !== null || wp_parse_url($url, PHP_URL_PASS) !== null) {
            return '';
        }
        return explode('#', $url, 2)[0];
    }

    /** Source page, excluding our own hosts. @param array<string,mixed> $row Row. @param string $source Provider. @return string */
    private static function source_url(array $row, $source) {
        $url = self::value($row, 'url');
        if ($source === 'gsc' && (isset($row['site']) || isset($row['linkingsite'])) && strpos($url, '://') === false) {
            $url = 'https://' . $url . '/';
        }
        $url = self::url($url);
        if (in_array(strtolower((string) wp_parse_url($url, PHP_URL_HOST)), SEOProStats_Collection::hosts(), true)) {
            return '';
        }
        return $url;
    }

    /** Target path, empty for GSC candidates, null for invalid targets. @param array<string,mixed> $row Row. @param string $source Provider. @return string|null */
    private static function target_path(array $row, $source) {
        $target = self::value($row, 'target');
        if ($target === '') {
            return $source === 'gsc' ? '' : null;
        }
        $target = self::url($target);
        if ($target === '' || !in_array(strtolower((string) wp_parse_url($target, PHP_URL_HOST)), SEOProStats_Collection::hosts(), true)) {
            return null;
        }
        $path = SEOProStats_Links::target(SEOProStats_Changes::path($target));
        return $path !== '' ? $path : null;
    }

    /** Export rel words and boolean flags. @param array<string,mixed> $row Row. @return int */
    private static function rel(array $row) {
        $rel = 0;
        foreach (SEOProStats_Backlinks::REL as $name => $bit) {
            $flag = isset($row[$name]) ? strtolower((string) $row[$name]) : '';
            if (in_array($flag, array('true', '1', 'yes'), true) || preg_match('/\b' . $name . '\b/i', self::value($row, 'rel'))) {
                $rel |= $bit;
            }
        }
        return $rel;
    }

    /** Absent, invalid or future dates stay unknown. @param array<string,mixed> $row Row. @param string $field Field. @return int */
    private static function date(array $row, $field) {
        $value = self::value($row, $field);
        $ts = $value !== '' ? strtotime($value) : false;
        return $ts !== false && $ts > 0 && $ts <= time() ? $ts : 0;
    }

    /** One valid backlink or source-only candidate. @param array<string,mixed> $row Row. @param string $source Provider. @return array<string,mixed>|null */
    public static function normalise(array $row, $source) {
        $row = self::cells($row);
        if ($row === null) {
            return null;
        }
        $url = self::source_url($row, $source);
        $path = self::target_path($row, $source);
        if ($url === '' || $path === null) {
            return null;
        }
        $score = self::value($row, 'authority');
        return array('url' => $url, 'path' => $path, 'anchor' => self::value($row, 'anchor'), 'rel' => self::rel($row), 'first' => self::date($row, 'first'), 'last' => self::date($row, 'last'), 'authority' => is_numeric($score) ? max(0, min(100, (int) $score)) : null);
    }
}
