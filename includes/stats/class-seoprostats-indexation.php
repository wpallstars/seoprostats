<?php
/**
 * Indexation (Search → Audit, Indexation): pages search engines do not
 * seem to show, in two lists:
 *
 * - pages: published pages (the content audit's page_facts, with when
 *   each was published) with no search impressions in the engine's newest
 *   DAYS days, published at least DAYS days before them; never seen, or
 *   seen before and not since (the last day is given);
 * - sitemap: addresses in the site's own sitemaps other than its posts
 *   (term and author archives, and other providers'), listed at least
 *   DAYS days, with no impressions in those days.
 *
 * Pages that ask search engines not to index them, or name another page
 * as canonical, are left out: no impressions is what they ask for.
 *
 * The sitemap addresses come from WordPress's sitemap providers, read by
 * the daily cron (no outside request), at most MAX_URLS addresses within
 * BUDGET seconds: one sitemap row per address, with when it was first
 * listed. The posts provider is not read: its pages are the published
 * pages the audit reads. When WordPress's sitemaps are off (an SEO plugin
 * makes its own), the list is empty and the answer says so.
 *
 * Reads: page_facts by its published key and sitemap by its first_seen
 * key (the newest MAX_ROWS of each), then gsc_pages by path_day for those
 * pages only: which had impressions in the window, then the last day of
 * the others'. Google's URL Inspection of the rows listed (their reason
 * and last crawl) and Search Console's sitemaps come from
 * SEOProStats_Inspections, outside the cache. Design: docs/seo-loop.md →
 * Indexation.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Indexation {

    /** Lists. */
    const KINDS = array('pages', 'sitemap');

    /** Days without impressions (and since publishing): default, least and most. */
    const DAYS     = 28;
    const MIN_DAYS = 7;
    const MAX_DAYS = 365;

    /** Where a sitemap address comes from: code (the source column) => WordPress's provider name. Codes never change meaning. */
    const SOURCES = array(
        1 => 'taxonomies',
        2 => 'users',
        3 => 'other',
    );

    /** Progress, per data set (autoload off): read (time), enabled, complete, addresses, version. */
    const OPTION = 'seoprostats_indexation';

    /** Sitemap addresses kept at most, and the seconds a read may take. */
    const MAX_URLS = 5000;
    const BUDGET   = 20;

    /** Pages and addresses read at most per list (newest first); rows kept per list; ids per read. */
    const MAX_ROWS = 5000;
    const KEEP     = 500;
    const CHUNK    = 500;

    /** Rows listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    // ------------------------------------------------------------------
    // The sitemap addresses.

    /**
     * Daily cron (and WP-CLI, and the first report on live data): read the
     * addresses of WordPress's sitemap providers other than posts, at most
     * MAX_URLS within the time budget, and keep them. Addresses no longer
     * listed go when the read went through every provider. Live data only.
     *
     * @param int $budget Seconds.
     * @return array{enabled:bool,addresses:int,complete:bool}
     */
    public static function read_sitemaps($budget = self::BUDGET) {
        $out = array('enabled' => false, 'addresses' => 0, 'complete' => false);
        if (SEOProStats_Schema::set() !== 'live' || !SEOProStats_Schema::is_current()) {
            return $out;
        }
        self::load();
        $start    = microtime(true);
        $server   = function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server() : null;
        $enabled  = $server instanceof WP_Sitemaps && $server->sitemaps_enabled();
        $codes    = array_flip(self::SOURCES);
        $paths    = array();
        $complete = true;
        if ($enabled) {
            foreach ($server->registry->get_providers() as $name => $provider) {
                // Published posts are the audit's pages.
                if ($name === 'posts' || !$provider instanceof WP_Sitemaps_Provider) {
                    continue;
                }
                $code     = isset($codes[$name]) ? (int) $codes[$name] : 3;
                $subtypes = array_keys((array) $provider->get_object_subtypes());
                foreach ($subtypes ? $subtypes : array('') as $subtype) {
                    $last = (int) $provider->get_max_num_pages((string) $subtype);
                    for ($page = 1; $page <= $last; $page++) {
                        if (count($paths) >= self::MAX_URLS || !SEOProStats_Feature::more_time($start, $budget)) {
                            $complete = false;
                            break 3;
                        }
                        foreach ((array) $provider->get_url_list($page, (string) $subtype) as $entry) {
                            $path = is_array($entry) && isset($entry['loc']) ? self::path((string) $entry['loc']) : '';
                            if ($path !== '' && !isset($paths[$path])) {
                                $paths[$path] = $code;
                            }
                        }
                    }
                }
            }
        }
        $paths = array_slice($paths, 0, self::MAX_URLS, true);
        $kept  = self::write_sitemap($paths, $enabled, $complete);
        return array('enabled' => $enabled, 'addresses' => $kept, 'complete' => $complete);
    }

    /**
     * Keep sitemap addresses: new ones are first seen now (or as
     * $first_seen gives), known ones are seen again; when the read was
     * complete, those not listed go. Records the progress.
     *
     * @param array<string,int> $paths      Path => source code (SOURCES).
     * @param bool              $enabled    Whether the sitemaps are on.
     * @param bool              $complete   Whether every provider was read.
     * @param array<string,int> $first_seen Path => when it was first listed (the demo); others now.
     * @return int Addresses kept.
     */
    public static function write_sitemap(array $paths, $enabled, $complete, array $first_seen = array()) {
        global $wpdb;
        require_once __DIR__ . '/class-seoprostats-dict.php';
        $now   = time();
        $table = SEOProStats_Schema::table('sitemap');
        $ids   = $paths ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_keys($paths)) : array();
        $rows  = array();
        foreach ($paths as $path => $code) {
            $clean = SEOProStats_Dict::clean((string) $path);
            $first = isset($first_seen[$path]) ? (int) $first_seen[$path] : $now;
            if (!empty($ids[$clean])) {
                $rows[(int) $ids[$clean]] = array((int) $ids[$clean], isset(self::SOURCES[(int) $code]) ? (int) $code : 3, $first, $now);
            }
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key and seen key; $groups holds only placeholder groups.
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            $groups = implode(', ', array_fill(0, count($chunk), '(%d, %d, %d, %d)'));
            $wpdb->query($wpdb->prepare("INSERT INTO %i (path_id, source, first_seen, seen) VALUES $groups ON DUPLICATE KEY UPDATE source = VALUES(source), seen = VALUES(seen)", array_merge(array($table), array_merge(...$chunk))));
        }
        if ($complete) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE seen < %d', $table, $now));
        }
        // phpcs:enable
        update_option(SEOProStats_Schema::option(self::OPTION), array(
            'read'      => $now,
            'enabled'   => (bool) $enabled,
            'complete'  => (bool) $complete,
            'addresses' => count($rows),
            'version'   => $now,
        ), false);
        return count($rows);
    }

    /**
     * The sitemaps not read yet on live data (the daily cron has not run
     * since the update): read them now, briefly.
     */
    private static function first_read() {
        if (SEOProStats_Schema::set() === 'live' && !self::state()['read']) {
            self::read_sitemaps(5);
        }
    }

    // ------------------------------------------------------------------
    // The report.

    /**
     * The indexation report: one of KINDS, with every list's count.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request() (filters, limit, offset; the period is the engine's newest days).
     * @param string              $engine google or bing.
     * @param string              $kind   One of KINDS.
     * @param int                 $days   Days without impressions, MIN_DAYS to MAX_DAYS.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $kind = 'pages', $days = self::DAYS) {
        self::load();
        $kind = $kind === '' ? 'pages' : (string) $kind;
        if (!in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of kinds */
            return new WP_Error('seoprostats_indexation_kind', sprintf(__('The kind is one of: %s.', 'seoprostats'), implode(', ', self::KINDS)), array('status' => 400));
        }
        $days = (int) $days;
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            /* translators: 1: fewest days, 2: most days */
            return new WP_Error('seoprostats_indexation_days', sprintf(__('Days is %1$d to %2$d.', 'seoprostats'), self::MIN_DAYS, self::MAX_DAYS), array('status' => 400));
        }
        SEOProStats_Audit::first_read();
        self::first_read();
        $engine = SEOProStats_Search::engine_name($engine);
        $live   = SEOProStats_Schema::set() === 'live';
        $key    = array(
            'filters' => isset($req['filters']) ? $req['filters'] : array(),
            'engine'  => $engine,
            'days'    => $days,
            'imports' => SEOProStats_Search::version(),
            'facts'   => SEOProStats_Audit::state()['version'],
            'sitemap' => self::state()['version'],
        );
        // Both lists are made once and paged from the cache; the period is the engine's newest days, whatever range is asked.
        $all    = SEOProStats_Query::cached('indexation', $key, static function () use ($req, $engine, $days) {
            return self::build($req, $engine, $days);
        });
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $list   = (array) $all['lists'][$kind];
        unset($all['lists']);
        $rows = array_slice($list, $offset, $limit);
        // Editor links depend on the viewer, ages on today and Google's view on the latest inspection, so they are added outside the shared cache.
        $now    = time();
        $google = $engine === 'google' ? SEOProStats_Inspections::of_pages(array_column($rows, 'path_id')) : array();
        foreach ($rows as &$row) {
            $since      = strtotime((string) ($kind === 'pages' ? $row['published'] : $row['first_seen']));
            $row['age'] = $since ? max(0, (int) floor(($now - $since) / DAY_IN_SECONDS)) : 0;
            // Google's reason (coverage state) and last crawl, from URL Inspection; null until inspected.
            $row['google'] = isset($google[(int) $row['path_id']]) ? $google[(int) $row['path_id']] : null;
            if ((int) $row['post_id']) {
                $row = SEOProStats_Clicks::with_edit_url($row);
            }
        }
        unset($row);
        return $all + array(
            'kind'        => $kind,
            'connected'   => !$live || SEOProStats_Search::connected($engine),
            // Search Console's sitemaps and the URL Inspection run (Google only).
            'sitemaps'    => $engine === 'google' ? SEOProStats_Inspections::sitemaps() : null,
            'inspections' => $engine === 'google' ? SEOProStats_Inspections::progress() + array('inspected' => SEOProStats_Inspections::inspected()) : null,
            'rows'        => $rows,
            'total'       => (int) $all['counts'][$kind],
            'more'        => $offset + $limit < min((int) $all['counts'][$kind], count($list)),
        );
    }

    /**
     * The shared part of the answer, with both lists (cached).
     *
     * @param array<string,mixed> $req  Request.
     * @param string              $name Engine name.
     * @param int                 $days Days without impressions.
     * @return array<string,mixed>
     */
    private static function build(array $req, $name, $days) {
        $engine  = SEOProStats_Search::ENGINES[$name];
        $bounds  = SEOProStats_Search::bounds($engine);
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids(isset($req['filters']) ? (array) $req['filters'] : array(), '', $ignored);
        $tz      = wp_timezone();
        $answer  = array(
            'engine'  => $name,
            'engines' => SEOProStats_Search::engines(),
            'range'   => SEOProStats_Query::range_out(SEOProStats_Query::range($req)),
            'days'    => $days,
            'through' => $bounds['to'],
            'first'   => $bounds['from'],
            'ignored' => array_values(array_unique($ignored)),
            'rules'   => array(
                'days'     => $days,
                'max_rows' => self::MAX_ROWS,
                'max_urls' => self::MAX_URLS,
            ),
            'read'    => self::coverage(),
            'typical' => 0.0,
            'skipped' => array('noindex' => 0, 'canonical' => 0),
            'counts'  => array_fill_keys(self::KINDS, 0),
            'lists'   => array_fill_keys(self::KINDS, array()),
        );
        if ($bounds['to'] === '' || ($pages !== null && !$pages)) {
            return $answer;
        }
        // The window: the engine's newest $days days; pages and addresses older than it.
        $end    = (new DateTimeImmutable($bounds['to'], $tz))->modify('+1 day');
        $begin  = $end->modify('-' . $days . ' days');
        $cutoff = $begin->getTimestamp();
        $answer['range'] = SEOProStats_Query::range_out(array('key' => 'custom', 'start' => $begin, 'to' => $end->getTimestamp()));

        $facts  = self::published($cutoff, $pages);
        $listed = self::listed($cutoff, $pages);
        foreach ($facts as $path_id => $row) {
            if ((int) $row['noindex'] || (int) $row['canonical_away']) {
                ++$answer['skipped'][(int) $row['noindex'] ? 'noindex' : 'canonical'];
                unset($facts[$path_id]);
            }
        }
        $ids    = array_values(array_unique(array_merge(array_keys($facts), array_keys($listed))));
        $seen   = self::seen($engine, $ids, $begin->format('Y-m-d'), $bounds['to']);
        $unseen = array_values(array_diff($ids, $seen));
        $last   = self::last_seen($engine, $unseen, $begin->format('Y-m-d'));
        $answer['typical'] = self::typical($engine, $begin->format('Y-m-d'), $bounds['to'], $days);

        $lists = array_fill_keys(self::KINDS, array());
        foreach ($unseen as $path_id) {
            $item = array('path_id' => (int) $path_id, 'last' => isset($last[$path_id]) ? $last[$path_id] : null);
            if (isset($facts[$path_id])) {
                $lists['pages'][] = $item + array('since' => (int) $facts[$path_id]['published'], 'row' => $facts[$path_id]);
            } elseif (isset($listed[$path_id])) {
                $lists['sitemap'][] = $item + array('since' => (int) $listed[$path_id]['first_seen'], 'row' => $listed[$path_id]);
            }
        }
        $answer['counts'] = array_map('count', $lists);
        foreach ($lists as $kind => $list) {
            // Never seen first, then the newest.
            usort($list, static function ($a, $b) {
                return array($a['last'] !== null, $b['since'], $a['path_id']) <=> array($b['last'] !== null, $a['since'], $b['path_id']);
            });
            $answer['lists'][$kind] = self::rows(array_slice($list, 0, self::KEEP), $kind);
        }
        return $answer;
    }

    /**
     * Published pages read by the audit, published before a time, by its
     * published key (the newest MAX_ROWS).
     *
     * @param int        $before Unix seconds.
     * @param int[]|null $pages  Path ids, or null for every page.
     * @return array<int,array<string,string>> Path id => row.
     */
    private static function published($before, $pages) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its published key.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, post_id, published, words, links_in, noindex, canonical_away FROM %i FORCE INDEX (`published`) WHERE published > 0 AND published <= %d ORDER BY published DESC LIMIT %d', SEOProStats_Schema::table('page_facts'), (int) $before, self::MAX_ROWS), ARRAY_A);
        return self::only($rows, $pages);
    }

    /**
     * Sitemap addresses first listed before a time, by the first_seen key
     * (the newest MAX_ROWS).
     *
     * @param int        $before Unix seconds.
     * @param int[]|null $pages  Path ids, or null for every page.
     * @return array<int,array<string,string>> Path id => row.
     */
    private static function listed($before, $pages) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its first_seen key.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, source, first_seen FROM %i FORCE INDEX (`first_seen`) WHERE first_seen <= %d ORDER BY first_seen DESC LIMIT %d', SEOProStats_Schema::table('sitemap'), (int) $before, self::MAX_ROWS), ARRAY_A);
        return self::only($rows, $pages);
    }

    /**
     * Rows by path id, only of the pages filtered on.
     *
     * @param array<int,array<string,string>> $rows  Rows with path_id.
     * @param int[]|null                      $pages Path ids, or null for every page.
     * @return array<int,array<string,string>>
     */
    private static function only(array $rows, $pages) {
        $only = $pages === null ? null : array_flip(array_map('intval', $pages));
        $out  = array();
        foreach ($rows as $row) {
            $path_id = (int) $row['path_id'];
            if ($only === null || isset($only[$path_id])) {
                $out[$path_id] = $row;
            }
        }
        return $out;
    }

    /**
     * The pages with impressions in a window, by path_day.
     *
     * @param int    $engine Engine.
     * @param int[]  $ids    Path ids.
     * @param string $from   First day (Y-m-d).
     * @param string $to     Last day.
     * @return int[]
     */
    private static function seen($engine, array $ids, $from, $to) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its path_day key, the pages listed only; $holders holds only placeholders.
            $found = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT path_id FROM %i FORCE INDEX (`path_day`) WHERE path_id IN ($holders) AND day >= %s AND day <= %s AND engine = %d AND impressions > 0", array_merge(array(SEOProStats_Schema::table('gsc_pages')), $chunk, array((string) $from, (string) $to, (int) $engine))));
            $out   = array_merge($out, array_map('intval', $found));
        }
        return $out;
    }

    /**
     * The last day with impressions before a day, of pages, by path_day.
     *
     * @param int    $engine Engine.
     * @param int[]  $ids    Path ids.
     * @param string $before Day (Y-m-d).
     * @return array<int,string> Path id => day; pages never seen are not in it.
     */
    private static function last_seen($engine, array $ids, $before) {
        global $wpdb;
        $out = array();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its path_day key, pages without impressions in the window only; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id, MAX(day) AS d FROM %i FORCE INDEX (`path_day`) WHERE path_id IN ($holders) AND day < %s AND engine = %d AND impressions > 0 GROUP BY path_id ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('gsc_pages')), $chunk, array((string) $before, (int) $engine))), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['path_id']] = (string) $row['d'];
            }
        }
        return $out;
    }

    /**
     * A page's clicks per 28 days, on average, of the pages with
     * impressions in the window: what a page shown in search earns here
     * (the decision queue's potential clicks for a page not shown).
     *
     * @param int    $engine Engine.
     * @param string $from   First day.
     * @param string $to     Last day.
     * @param int    $days   Days in the window.
     * @return float
     */
    private static function typical($engine, $from, $to, $days) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key's (engine, day) prefix.
        $row    = (array) $wpdb->get_row($wpdb->prepare('SELECT SUM(clicks) AS c, COUNT(DISTINCT path_id) AS n FROM %i FORCE INDEX (`PRIMARY`) WHERE engine = %d AND day >= %s AND day <= %s AND impressions > 0', SEOProStats_Schema::table('gsc_pages'), (int) $engine, (string) $from, (string) $to), ARRAY_A);
        $clicks = isset($row['c']) ? (int) $row['c'] : 0;
        $pages  = isset($row['n']) ? (int) $row['n'] : 0;
        return $pages ? round($clicks / $pages * 28 / max(1, (int) $days), 1) : 0.0;
    }

    /**
     * Rows as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $list The rows kept.
     * @param string                         $kind pages or sitemap.
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $list, $kind) {
        $text = SEOProStats_Query::texts(array_column($list, 'path_id'));
        $live = SEOProStats_Schema::set() === 'live';
        $out  = array();
        foreach ($list as $item) {
            $id   = (int) $item['path_id'];
            $path = isset($text[$id]) ? (string) $text[$id] : '';
            $row  = $item['row'];
            $line = array(
                'path_id'         => $id,
                'path'            => $path,
                'url'             => self::url($path),
                // Demo posts are not the site's.
                'post_id'         => $live && $kind === 'pages' ? (int) $row['post_id'] : 0,
                'edit_url'        => null,
                'state'           => $item['last'] === null ? 'never' : 'lost',
                'last_impression' => $item['last'],
                // Days since, set by report() outside the cache.
                'age'             => 0,
            );
            if ($kind === 'pages') {
                $line += array(
                    // In the site time zone, as report times are.
                    'published' => wp_date('c', (int) $item['since']),
                    'words'     => (int) $row['words'],
                    'links_in'  => (int) $row['links_in'],
                );
            } else {
                $line += array(
                    'first_seen' => wp_date('c', (int) $item['since']),
                    'source'     => isset(self::SOURCES[(int) $row['source']]) ? self::SOURCES[(int) $row['source']] : 'other',
                );
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * How many published pages the audit read, how many have their
     * published time, and the sitemaps' read.
     *
     * @return array<string,mixed>
     */
    private static function coverage() {
        global $wpdb;
        $table = SEOProStats_Schema::table('page_facts');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, by its post_id and published keys.
        $pages     = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`post_id`) WHERE post_id > 0', $table));
        $published = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`published`) WHERE published > 0', $table));
        // phpcs:enable
        $published = min($pages, $published);
        $state     = self::state();
        return array(
            'pages'     => $pages,
            'published' => $published,
            'complete'  => $published >= $pages,
            'sitemap'   => array(
                'read'      => $state['read'] ? gmdate('c', $state['read']) : null,
                'enabled'   => $state['enabled'],
                'complete'  => $state['complete'],
                'addresses' => $state['addresses'],
            ),
        );
    }

    // ------------------------------------------------------------------
    // The decision queue's words.

    /**
     * Why a row is listed, in a sentence.
     *
     * @param string              $kind    pages or sitemap.
     * @param array<string,mixed> $row     The report's row.
     * @param string              $through The newest search day.
     * @return string
     */
    public static function why($kind, array $row, $through) {
        $days = number_format_i18n((int) $row['age']);
        if ($row['state'] === 'lost') {
            /* translators: 1: the last day with impressions, 2: the newest search day */
            return sprintf(__('Search showed it until %1$s, but not since (search data through %2$s).', 'seoprostats'), (string) $row['last_impression'], (string) $through);
        }
        if ($kind === 'sitemap') {
            /* translators: 1: number of days, 2: what the address is, e.g. "a term archive" */
            return sprintf(_n('In the site’s sitemap for %1$s day as %2$s, but search has never shown it.', 'In the site’s sitemap for %1$s days as %2$s, but search has never shown it.', (int) $row['age'], 'seoprostats'), $days, self::source_phrase((string) $row['source']));
        }
        /* translators: %s: number of days */
        $why = sprintf(_n('Published %s day ago, but search has never shown it.', 'Published %s days ago, but search has never shown it.', (int) $row['age'], 'seoprostats'), $days);
        if (isset($row['links_in']) && (int) $row['links_in'] === 0) {
            $why .= ' ' . __('No other page links to it.', 'seoprostats');
        }
        return $why;
    }

    /**
     * What to do about a row.
     *
     * @param string $kind pages or sitemap.
     * @return string
     */
    public static function todo($kind) {
        if ($kind === 'sitemap') {
            return __('Make the archive worth showing (a description and enough posts), or leave it out of the sitemap.', 'seoprostats');
        }
        return __('Check that search engines may index it and can find it (sitemap, links from related pages), then ask them to crawl it; if it adds little, improve it or merge it into another page.', 'seoprostats');
    }

    /**
     * What a sitemap address is, for a sentence.
     *
     * @param string $source One of SOURCES.
     * @return string
     */
    private static function source_phrase($source) {
        if ($source === 'taxonomies') {
            return __('a category or tag archive', 'seoprostats');
        }
        if ($source === 'users') {
            return __('an author archive', 'seoprostats');
        }
        return __('an address of another plugin', 'seoprostats');
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * Load the classes the report uses.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-audit.php';
        require_once __DIR__ . '/class-seoprostats-inspections.php';
    }

    /**
     * Progress of the current data set.
     *
     * @return array{read:int,enabled:bool,complete:bool,addresses:int,version:int}
     */
    public static function state() {
        $state = get_option(SEOProStats_Schema::option(self::OPTION), array());
        $state = is_array($state) ? $state : array();
        return array(
            'read'      => isset($state['read']) ? (int) $state['read'] : 0,
            'enabled'   => !empty($state['enabled']),
            'complete'  => !empty($state['complete']),
            'addresses' => isset($state['addresses']) ? (int) $state['addresses'] : 0,
            'version'   => isset($state['version']) ? (int) $state['version'] : 0,
        );
    }

    /**
     * Delete the current data set's progress (demo removal, uninstall).
     */
    public static function reset() {
        delete_option(SEOProStats_Schema::option(self::OPTION));
    }

    /**
     * A sitemap address's path as pages are stored, or '' when it is on
     * another host.
     *
     * @param string $url Address.
     * @return string
     */
    private static function path($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host !== '' && $host !== $home) {
            return '';
        }
        return SEOProStats_Changes::path($url);
    }

    /**
     * A page's address on this site.
     *
     * @param string $path Path.
     * @return string
     */
    private static function url($path) {
        $info = $path !== '' && $path[0] === '/' ? wp_parse_url(home_url('/')) : null;
        if (!is_array($info) || !isset($info['scheme'], $info['host'])) {
            return '';
        }
        return esc_url_raw($info['scheme'] . '://' . $info['host'] . (isset($info['port']) ? ':' . $info['port'] : '') . $path);
    }
}
