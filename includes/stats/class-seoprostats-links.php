<?php
/**
 * Internal links (Search → Audit, Internal links): the links in the text
 * of the site's published pages to its own pages, and three lists from
 * them weighed by search and conversions:
 *
 * - orphans: published pages no other page's text links to (the front
 *   page is left out: menus link to it);
 * - converting: pages whose visits from search reach the goal
 *   (MIN_CONVERSIONS or more) with FEW or fewer pages linking to them;
 * - missing: a page that shows for a search, but does not link to the
 *   page meant for it: the page with most clicks for that search.
 *
 * Links are read with the content audit's facts (SEOProStats_Audit), from
 * the same text, when a post is saved and in its daily cron batch, never
 * on visitor pages: page_links, one row per page and page it links to,
 * replaced each time the page is read. page_facts.links_in counts the
 * other pages linking to each page and is kept current as links are
 * written, so orphans and pages with few links in are read by its key.
 *
 * Reads: page_facts by its links_in key, page_links by its primary key
 * (from_path) or to_path, each page's search figures from gsc_pages and
 * the shared searches from gsc_pairs by the primary key (engine, day), as
 * Opportunities, and conversions from the Content report. Design:
 * docs/seo-loop.md → Internal links.
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

final class SEOProStats_Links {

    /** Lists. */
    const KINDS = array('orphans', 'converting', 'missing');

    /** Pages a page's links are kept to, at most. */
    const MAX_LINKS = 300;

    /** Pages linking in at most for "few", and the conversions that make a page converting. */
    const FEW             = 2;
    const MIN_CONVERSIONS = 3;

    /** Facts rows read at most; rows kept per list; searches and pages named per row. */
    const MAX_ROWS = 5000;
    const KEEP     = 500;
    const QUERIES  = 5;
    const FROM     = 5;

    /** Pages read for conversions (most visits from search first). */
    const VALUE_PAGES = 1000;

    /** Rows listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 500;

    /** Longest link text kept (bytes). */
    const TEXT_BYTES = 100;

    /** Paths that are not pages: WordPress's own folders and feeds. */
    const NOT_PAGES = '#^/(?:wp-admin|wp-content|wp-includes|wp-json)(?:/|$)|/feed/?$|^/xmlrpc\.php$|^/wp-[a-z-]+\.php$#';

    // ------------------------------------------------------------------
    // Reading and writing links.

    /**
     * The links of a page's text to the site's pages: target path => the
     * first link's text and how many links. Links to WordPress's folders,
     * feeds and files are left out; paths get the permalinks' trailing
     * slash; at most MAX_LINKS pages.
     *
     * @param string $html Content.
     * @return array<string,array{text:string,links:int}>
     */
    public static function parse($html) {
        require_once __DIR__ . '/class-seoprostats-changes.php';
        $found = SEOProStats_Changes::links((string) $html);
        $out   = array();
        foreach ($found['internal'] as $to => $text) {
            $path = self::target((string) $to);
            if ($path === '') {
                continue;
            }
            $links = isset($found['counts'][$to]) ? (int) $found['counts'][$to] : 1;
            if (isset($out[$path])) {
                $out[$path]['links'] += $links;
                continue;
            }
            if (count($out) >= self::MAX_LINKS) {
                break;
            }
            $text       = (string) $text;
            $out[$path] = array(
                'text'  => function_exists('mb_strcut') ? mb_strcut($text, 0, self::TEXT_BYTES, 'UTF-8') : substr($text, 0, self::TEXT_BYTES),
                'links' => $links,
            );
        }
        return $out;
    }

    /**
     * A link's path as pages are stored, or '' when it is not a page.
     * Queries are kept only with plain permalinks (?p=1); otherwise they
     * are tracking or sorting, and the page is the path.
     *
     * @param string $path Path from SEOProStats_Changes::links().
     * @return string
     */
    private static function target($path) {
        $parts = explode('?', (string) $path, 2);
        $base  = $parts[0] === '' ? '/' : $parts[0];
        if (preg_match(self::NOT_PAGES, $base)) {
            return '';
        }
        $last = (string) substr($base, (int) strrpos($base, '/') + 1);
        $dot  = strrpos($last, '.');
        if ($dot !== false && !in_array(strtolower(substr($last, $dot + 1)), array('html', 'htm', 'php'), true)) {
            return '';
        }
        $plain = (string) get_option('permalink_structure') === '';
        if ($plain) {
            return isset($parts[1]) && $parts[1] !== '' ? $base . '?' . $parts[1] : $base;
        }
        if ($base !== '/' && $dot === false) {
            $base = user_trailingslashit(untrailingslashit($base));
        }
        return $base;
    }

    /**
     * Replace the links of pages and forget those of others (a page no
     * longer published, or at an old address). Call recount() with the
     * answer and the pages written.
     *
     * @param array<int,array<string,array{text:string,links:int}>> $from Path id => its links, from parse().
     * @param int[]                                                $gone Path ids whose links go.
     * @return int[] Path ids linked to before or now (their links_in may change).
     */
    public static function replace(array $from, array $gone = array()) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge(array_keys($from), $gone)))));
        if (!$ids) {
            return array();
        }
        require_once __DIR__ . '/class-seoprostats-dict.php';
        $table   = SEOProStats_Schema::table('page_links');
        $changed = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key (from_path); $holders and $groups hold only placeholders.
        foreach (array_chunk($ids, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            $args    = array_merge(array($table), $chunk);
            $changed = array_merge($changed, array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT to_path FROM %i WHERE from_path IN ($holders)", $args))));
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE from_path IN ($holders)", $args));
        }

        $paths = array();
        $texts = array();
        foreach ($from as $links) {
            foreach ((array) $links as $to => $link) {
                $paths[] = (string) $to;
                $texts[] = (string) $link['text'];
            }
        }
        $path_ids = $paths ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, $paths) : array();
        $text_ids = $texts ? SEOProStats_Dict::ids(SEOProStats_Schema::DICT_LABEL, $texts) : array();
        $rows     = array();
        foreach ($from as $from_id => $links) {
            foreach ((array) $links as $to => $link) {
                $to_id = isset($path_ids[SEOProStats_Dict::clean((string) $to)]) ? (int) $path_ids[SEOProStats_Dict::clean((string) $to)] : 0;
                if (!$to_id || $to_id === (int) $from_id) {
                    continue;
                }
                $text   = SEOProStats_Dict::clean((string) $link['text']);
                $rows[] = array((int) $from_id, $to_id, isset($text_ids[$text]) ? (int) $text_ids[$text] : 0, max(1, min(65535, (int) $link['links'])));
                $changed[] = $to_id;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $groups = implode(', ', array_fill(0, count($chunk), '(%d, %d, %d, %d)'));
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (from_path, to_path, text_id, links) VALUES $groups", array_merge(array($table), array_merge(...$chunk))));
        }
        // phpcs:enable
        return array_values(array_unique($changed));
    }

    /**
     * Count again the other pages linking to pages (page_facts.links_in).
     *
     * @param int[] $path_ids Path ids.
     */
    public static function recount(array $path_ids) {
        global $wpdb;
        $path_ids = array_values(array_unique(array_filter(array_map('intval', $path_ids))));
        $links    = SEOProStats_Schema::table('page_links');
        $facts    = SEOProStats_Schema::table('page_facts');
        foreach (array_chunk($path_ids, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            $by      = array_fill_keys($chunk, 0);
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own tables by the to_path key and the primary key; $holders and $ids hold only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT to_path AS t, COUNT(*) AS n FROM %i FORCE INDEX (`to_path`) WHERE to_path IN ($holders) GROUP BY to_path ORDER BY NULL", array_merge(array($links), $chunk)), ARRAY_A) as $row) {
                $by[(int) $row['t']] = min(65535, (int) $row['n']);
            }
            $groups = array();
            foreach ($by as $path_id => $n) {
                $groups[$n][] = (int) $path_id;
            }
            foreach ($groups as $n => $ids) {
                $holders = implode(', ', array_fill(0, count($ids), '%d'));
                $wpdb->query($wpdb->prepare("UPDATE %i SET links_in = %d WHERE path_id IN ($holders)", array_merge(array($facts, (int) $n), $ids)));
            }
            // phpcs:enable
        }
    }

    // ------------------------------------------------------------------
    // The report.

    /**
     * The internal links report: one of KINDS, with every list's count.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request() (range, filters, limit, offset).
     * @param string              $engine google or bing.
     * @param string              $kind   One of KINDS.
     * @param string              $goal   Goal id for conversions; '' for the first goal.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $kind = 'orphans', $goal = '') {
        self::load();
        $kind = $kind === '' ? 'orphans' : (string) $kind;
        if (!in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of kinds */
            return new WP_Error('seoprostats_links_kind', sprintf(__('The kind is one of: %s.', 'seoprostats'), implode(', ', self::KINDS)), array('status' => 400));
        }
        SEOProStats_Audit::first_read();
        $engine = SEOProStats_Search::engine_name($engine);
        $live   = SEOProStats_Schema::set() === 'live';
        $key    = array(
            'engine'   => $engine,
            'goal'     => (string) $goal,
            'goals'    => SEOProStats_Goals::goals(),
            'imports'  => SEOProStats_Search::version(),
            'landings' => SEOProStats_Rollup::landings_from(),
            'facts'    => SEOProStats_Audit::state()['version'],
        );
        // Every list is made once and paged from the cache.
        $all    = SEOProStats_Query::cached('links', array_diff_key($req, array('limit' => true, 'offset' => true)) + $key, static function () use ($req, $engine, $goal) {
            return self::build($req, $engine, (string) $goal);
        });
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $list   = (array) $all['lists'][$kind];
        unset($all['lists']);
        $rows = array_slice($list, $offset, $limit);
        // Editor links depend on the viewer, so they are added outside the shared cache.
        foreach ($rows as &$row) {
            $row = SEOProStats_Clicks::with_edit_url($row);
            if (isset($row['to']) && is_array($row['to'])) {
                $row['to'] = SEOProStats_Clicks::with_edit_url($row['to']);
            }
        }
        unset($row);
        return $all + array(
            'kind'      => $kind,
            'connected' => !$live || SEOProStats_Search::connected($engine),
            'rows'      => $rows,
            'total'     => (int) $all['counts'][$kind],
            'more'      => $offset + $limit < min((int) $all['counts'][$kind], count($list)),
        );
    }

    /**
     * The shared part of the answer, with every list (cached).
     *
     * @param array<string,mixed> $req  Request.
     * @param string              $name Engine name.
     * @param string              $goal Goal id, or ''.
     * @return array<string,mixed>
     */
    private static function build(array $req, $name, $goal) {
        $engine  = SEOProStats_Search::ENGINES[$name];
        $bounds  = SEOProStats_Search::bounds($engine);
        $range   = SEOProStats_Query::range($req);
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids($req['filters'], '', $ignored);
        $weekly  = in_array($name, SEOProStats_Search::WEEKLY, true);
        $full    = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds, $weekly) : null;
        $now     = $full ? SEOProStats_Opportunities::cut($full) : null;
        $days    = $now ? SEOProStats_Search::length($now) : 0;
        $rules   = array(
            'few_links'       => self::FEW,
            'min_conversions' => self::MIN_CONVERSIONS,
            'min_impressions' => max(10, $days),
            'queries'         => self::QUERIES,
            'max_links'       => self::MAX_LINKS,
        );
        $answer  = array(
            'engine'  => $name,
            'engines' => SEOProStats_Search::engines(),
            'range'   => SEOProStats_Query::range_out($now ? $now : $range),
            'days'    => $days,
            'cut'     => $full && $now && SEOProStats_Search::length($full) > $days,
            'through' => $bounds['to'],
            'first'   => $bounds['from'],
            'ignored' => array_values(array_unique($ignored)),
            'rules'   => $rules,
            'read'    => self::coverage(),
            'goal'    => null,
            'goals'   => array(),
            'counts'  => array_fill_keys(self::KINDS, 0),
            'lists'   => array_fill_keys(self::KINDS, array()),
        );
        if ($pages !== null && !$pages) {
            return $answer;
        }

        $value           = self::conversions($req, $name, $goal);
        $answer['goal']  = $value['goal'];
        $answer['goals'] = $value['goals'];
        $facts           = self::few($pages);
        $sums            = $now ? SEOProStats_Opportunities::sums('gsc_pages', $engine, $now, $pages) : array();
        // Demo pages' paths are the site's own from its root.
        $front           = SEOProStats_Dict::find(SEOProStats_Schema::DICT_PATH, array(SEOProStats_Schema::set() === 'demo' ? '/' : SEOProStats_Changes::path(home_url('/'))));
        $front           = $front ? (int) $front[0] : 0;
        $zero            = array('c' => 0, 'i' => 0, 'p' => 0);

        $orphans    = array();
        $converting = array();
        foreach ($facts as $path_id => $links_in) {
            $sum  = isset($sums[(string) $path_id]) ? $sums[(string) $path_id] : $zero;
            $page = isset($value['pages'][$path_id]) ? $value['pages'][$path_id] : array('visits' => 0, 'conversions' => 0);
            $item = array('path_id' => (int) $path_id, 'links_in' => (int) $links_in, 'sum' => $sum) + $page;
            if ($links_in === 0 && $path_id !== $front) {
                $orphans[] = $item;
            }
            if ($value['goal'] && $page['conversions'] >= self::MIN_CONVERSIONS) {
                $converting[] = $item;
            }
        }
        usort($orphans, static function ($a, $b) {
            return array($b['sum']['i'], $b['visits'], $b['sum']['c'], $a['path_id']) <=> array($a['sum']['i'], $a['visits'], $a['sum']['c'], $b['path_id']);
        });
        usort($converting, static function ($a, $b) {
            return array($b['conversions'], $a['links_in'], $b['visits'], $a['path_id']) <=> array($a['conversions'], $b['links_in'], $a['visits'], $b['path_id']);
        });
        $missing = $now ? self::missing($engine, $now, $pages, $rules) : array();

        $answer['counts'] = array(
            'orphans'    => count($orphans),
            'converting' => count($converting),
            'missing'    => count($missing),
        );
        $orphans    = array_slice($orphans, 0, self::KEEP);
        $converting = array_slice($converting, 0, self::KEEP);
        $missing    = array_slice($missing, 0, self::KEEP);
        $has_goal   = (bool) $value['goal'];
        $answer['lists'] = array(
            'orphans'    => self::page_rows($orphans, $has_goal),
            'converting' => self::page_rows($converting, $has_goal),
            'missing'    => self::missing_rows($missing),
        );
        return $answer;
    }

    /**
     * Published pages with FEW or fewer pages linking to them, by their
     * links_in key: path id => links in.
     *
     * @param int[]|null $pages Path ids, or null for every page.
     * @return array<int,int>
     */
    private static function few($pages) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its links_in key.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT path_id, links_in FROM %i FORCE INDEX (`links_in`) WHERE links_in <= %d AND post_id > 0 LIMIT %d', SEOProStats_Schema::table('page_facts'), self::FEW, self::MAX_ROWS), ARRAY_A);
        $only = $pages === null ? null : array_flip(array_map('intval', $pages));
        $out  = array();
        foreach ($rows as $row) {
            $path_id = (int) $row['path_id'];
            if ($only === null || isset($only[$path_id])) {
                $out[$path_id] = (int) $row['links_in'];
            }
        }
        return $out;
    }

    /**
     * Visits from search and conversions of the goal by page, from the
     * Content report (most visits first, VALUE_PAGES pages).
     *
     * @param array<string,mixed> $req  Request.
     * @param string              $name Engine name.
     * @param string              $goal Goal id; '' for the first goal.
     * @return array{goal:array{id:string,name:string}|null,goals:array<int,array{id:string,name:string}>,pages:array<int,array{visits:int,conversions:int}>}
     */
    private static function conversions(array $req, $name, $goal) {
        $content = SEOProStats_Content::report(array_merge($req, array('limit' => self::VALUE_PAGES, 'offset' => 0, 'compare' => 'none')), 'visits', $goal, $name);
        $out     = array('goal' => null, 'goals' => array(), 'pages' => array());
        foreach (isset($content['goals']) ? (array) $content['goals'] : array() as $one) {
            $out['goals'][] = array('id' => (string) $one['id'], 'name' => (string) $one['name']);
        }
        if (empty($content['goal'])) {
            return $out;
        }
        $out['goal'] = array('id' => (string) $content['goal']['id'], 'name' => (string) $content['goal']['name']);
        foreach ($content['rows'] as $row) {
            $out['pages'][(int) $row['path_id']] = array('visits' => (int) $row['visits'], 'conversions' => (int) $row['conversions']);
        }
        return $out;
    }

    /**
     * Searches whose page with most clicks another page showing for them
     * does not link to: one row per page and page it should link to, most
     * impressions first. From the CANDIDATES pairs with most impressions
     * (as Opportunities' overlapping pages); only pages whose links were
     * read.
     *
     * @param int                     $engine Engine.
     * @param array<string,mixed>     $days   From SEOProStats_Search::days().
     * @param int[]|null              $pages  Path ids, or null for every page.
     * @param array<string,int|float> $rules  Rules.
     * @return array<int,array<string,mixed>>
     */
    private static function missing($engine, array $days, $pages, array $rules) {
        global $wpdb;
        $on    = SEOProStats_Opportunities::where($engine, $days, $pages);
        $least = (int) $rules['min_impressions'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day; $on holds only placeholders and a fixed key name.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`{$on['key']}`) WHERE {$on['where']} GROUP BY path_id, query_id HAVING i >= 1 ORDER BY i DESC, path_id, query_id LIMIT %d", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $on['args'], array(SEOProStats_Opportunities::CANDIDATES))), ARRAY_A);
        $by_query = array();
        foreach ($rows as $row) {
            $by_query[(int) $row['q']][] = array('path_id' => (int) $row['pg'], 'c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
        }

        // Each search's page with most clicks (then impressions), and the other pages showing for it.
        $wanted = array();
        foreach ($by_query as $query_id => $pairs) {
            if (count($pairs) < 2) {
                continue;
            }
            usort($pairs, static function ($a, $b) {
                return array($b['c'], $b['i'], $a['path_id']) <=> array($a['c'], $a['i'], $b['path_id']);
            });
            $to = $pairs[0];
            if ($to['c'] < 1) {
                continue;
            }
            foreach (array_slice($pairs, 1) as $pair) {
                if ($pair['i'] >= $least) {
                    $wanted[] = array('from' => $pair['path_id'], 'to' => $to['path_id'], 'query_id' => (int) $query_id, 'pair' => $pair, 'target' => $to);
                }
            }
        }
        if (!$wanted) {
            return array();
        }

        // Only pages whose links were read, and links that are not there.
        $from    = array_values(array_unique(array_column($wanted, 'from')));
        $to      = array_values(array_unique(array_column($wanted, 'to')));
        $read    = array();
        $linked  = array();
        $facts   = SEOProStats_Schema::table('page_facts');
        $links   = SEOProStats_Schema::table('page_links');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own tables by their primary keys; $holders and $to_holders hold only placeholders.
        foreach (array_chunk($from, 500) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT path_id FROM %i WHERE path_id IN ($holders) AND post_id > 0", array_merge(array($facts), $chunk))) as $id) {
                $read[(int) $id] = true;
            }
            $to_holders = implode(', ', array_fill(0, count($to), '%d'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT from_path AS f, to_path AS t FROM %i WHERE from_path IN ($holders) AND to_path IN ($to_holders)", array_merge(array($links), $chunk, $to)), ARRAY_A) as $row) {
                $linked[(int) $row['f'] . ':' . (int) $row['t']] = true;
            }
        }
        // phpcs:enable

        $grouped = array();
        foreach ($wanted as $one) {
            $key = $one['from'] . ':' . $one['to'];
            if (!isset($read[$one['from']]) || isset($linked[$key])) {
                continue;
            }
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'from'    => $one['from'],
                    'to'      => $one['to'],
                    'sum'     => array('c' => 0, 'i' => 0, 'p' => 0),
                    'target'  => array('c' => 0, 'i' => 0, 'p' => 0),
                    'queries' => array(),
                );
            }
            foreach (array('c', 'i', 'p') as $k) {
                $grouped[$key]['sum'][$k]    += $one['pair'][$k];
                $grouped[$key]['target'][$k] += $one['target'][$k];
            }
            $grouped[$key]['queries'][] = array('query_id' => $one['query_id'], 'pair' => $one['pair'], 'target' => $one['target']);
        }
        $list = array_values($grouped);
        usort($list, static function ($a, $b) {
            return array($b['sum']['i'], $b['target']['c'], $a['from'], $a['to']) <=> array($a['sum']['i'], $a['target']['c'], $b['from'], $b['to']);
        });
        return $list;
    }

    /**
     * Orphan or converting rows as the answer gives them.
     *
     * @param array<int,array<string,mixed>> $list     The rows kept.
     * @param bool                           $has_goal Whether conversions were read.
     * @return array<int,array<string,mixed>>
     */
    private static function page_rows(array $list, $has_goal) {
        global $wpdb;
        $ids  = array_column($list, 'path_id');
        $from = array();
        $with = array_values(array_filter($list, static function ($item) {
            return $item['links_in'] > 0;
        }));
        if ($with) {
            $holders = implode(', ', array_fill(0, count($with), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its to_path key; $holders holds only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT to_path AS t, from_path AS f FROM %i FORCE INDEX (`to_path`) WHERE to_path IN ($holders)", array_merge(array(SEOProStats_Schema::table('page_links')), array_column($with, 'path_id'))), ARRAY_A) as $row) {
                if (!isset($from[(int) $row['t']]) || count($from[(int) $row['t']]) < self::FROM) {
                    $from[(int) $row['t']][] = (int) $row['f'];
                    $ids[]                   = (int) $row['f'];
                }
            }
        }
        $text = SEOProStats_Query::texts($ids);
        $out  = array();
        foreach ($list as $item) {
            $id    = (int) $item['path_id'];
            $out[] = self::page($id, $text) + SEOProStats_Search::metrics($item['sum']['c'], $item['sum']['i'], $item['sum']['p']) + array(
                'visits'      => (int) $item['visits'],
                'conversions' => $has_goal ? (int) $item['conversions'] : null,
                'links_in'    => (int) $item['links_in'],
                'from'        => array_values(array_filter(array_map(static function ($other) use ($text) {
                    return isset($text[$other]) ? (string) $text[$other] : '';
                }, isset($from[$id]) ? $from[$id] : array()))),
            );
        }
        return $out;
    }

    /**
     * Missing-link rows as the answer gives them: the page that shows for
     * the searches (its figures for them), the page it should link to (its
     * figures for them) and the searches, most impressions first.
     *
     * @param array<int,array<string,mixed>> $list The rows kept.
     * @return array<int,array<string,mixed>>
     */
    private static function missing_rows(array $list) {
        $ids = array();
        foreach ($list as $item) {
            $ids[] = (int) $item['from'];
            $ids[] = (int) $item['to'];
            foreach (array_slice($item['queries'], 0, self::QUERIES) as $query) {
                $ids[] = (int) $query['query_id'];
            }
        }
        $text = SEOProStats_Query::texts($ids);
        $out  = array();
        foreach ($list as $item) {
            $queries = array();
            foreach (array_slice($item['queries'], 0, self::QUERIES) as $query) {
                $mine      = SEOProStats_Search::metrics($query['pair']['c'], $query['pair']['i'], $query['pair']['p']);
                $theirs    = SEOProStats_Search::metrics($query['target']['c'], $query['target']['i'], $query['target']['p']);
                $queries[] = array(
                    'query'       => isset($text[$query['query_id']]) ? (string) $text[$query['query_id']] : '',
                    'clicks'      => $mine['clicks'],
                    'impressions' => $mine['impressions'],
                    'position'    => $mine['position'],
                    'to_clicks'   => $theirs['clicks'],
                    'to_position' => $theirs['position'],
                );
            }
            $out[] = self::page((int) $item['from'], $text) + SEOProStats_Search::metrics($item['sum']['c'], $item['sum']['i'], $item['sum']['p']) + array(
                'to'          => self::page((int) $item['to'], $text) + SEOProStats_Search::metrics($item['target']['c'], $item['target']['i'], $item['target']['p']),
                'queries'     => $queries,
                'query_count' => count($item['queries']),
            );
        }
        return $out;
    }

    /**
     * How many published pages have facts, and how many of them had their
     * links read (since links came; all of them once the batch went round).
     *
     * @return array{pages:int,read:int,complete:bool}
     */
    private static function coverage() {
        global $wpdb;
        $table = SEOProStats_Schema::table('page_facts');
        $from  = (int) SEOProStats_Audit::state()['links'];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, by its post_id and checked keys.
        $pages = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`post_id`) WHERE post_id > 0', $table));
        $read  = $from ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i FORCE INDEX (`checked`) WHERE checked >= %d', $table, $from)) : 0;
        // phpcs:enable
        $read = min($pages, $read);
        return array('pages' => $pages, 'read' => $read, 'complete' => $from > 0 && $read >= $pages);
    }

    /**
     * A page's fields in a row: path_id, path, url, post_id, edit_url.
     *
     * @param int               $path_id Path id.
     * @param array<int,string> $text    Texts by id.
     * @return array<string,mixed>
     */
    private static function page($path_id, array $text) {
        $path = isset($text[$path_id]) ? (string) $text[$path_id] : '';
        return array('path_id' => (int) $path_id) + (SEOProStats_Clicks::page_info($path) ?: array('path' => $path, 'url' => '', 'post_id' => 0, 'edit_url' => null));
    }

    /**
     * Load the classes the report uses.
     */
    private static function load() {
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-query.php';
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        require_once __DIR__ . '/class-seoprostats-opportunities.php';
        require_once __DIR__ . '/class-seoprostats-goals.php';
        require_once __DIR__ . '/class-seoprostats-conversions.php';
        require_once __DIR__ . '/class-seoprostats-rollup.php';
        require_once __DIR__ . '/class-seoprostats-content.php';
        require_once __DIR__ . '/class-seoprostats-audit.php';
    }
}
