<?php
/**
 * The opportunities report (Search → Opportunities): where search effort
 * pays, from Search Console's imported days.
 *
 * - striking: a page's query at average position 4–20 with enough
 *   impressions; potential clicks if it reached position 3.
 * - ctr: a page's query in the top 10 whose CTR is well under the site's
 *   own CTR at that position; clicks missed.
 * - decay: pages with fewer clicks than in the previous period of the
 *   same length, each with a likely cause (position, demand, CTR, gone),
 *   the queries that lost most and what changed on the page.
 * - missing: a page's query in the top 20 with enough impressions whose
 *   words the page does not have, or has only some of
 *   (SEOProStats_Coverage); the pages with most impressions, at most
 *   MISSING_PAGES, are read.
 * - overlap: a query for which two or more pages each get at least
 *   OVERLAP_SHARE of the impressions of its pages, with each page's
 *   figures and share and whether the page with most impressions changed
 *   between the halves of the period. A candidate to review, not a fault:
 *   a guide and a product page can both be right for one search.
 *
 * Expected CTR is the site's own: clicks ÷ impressions of its pages'
 * queries by rounded position in the period, never rising with position;
 * positions with too few impressions take a cautious default.
 *
 * As SEOProStats_Search: days are final search days only, the period is
 * cut at the newest one, page filters apply and visit filters do not.
 * The period is also cut to its newest MAX_DAYS days, so a year never
 * reads every pair. Every read is by the primary key (engine, day) or
 * path_day (and overlap's halves by query_day for the queries shown);
 * nothing reads the visit tables.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Opportunities {

    /** Kinds of opportunity. */
    const KINDS = array('striking', 'ctr', 'decay', 'missing', 'overlap');

    /** Days read at most: the newest of the period. */
    const MAX_DAYS = 91;

    /** Striking distance: positions (rounded) and the position aimed for. */
    const STRIKING_FROM = 4;
    const STRIKING_TO   = 20;
    const TARGET        = 3;

    /** Low CTR: in the top CTR_TOP, under CTR_GAP × the expected CTR. */
    const CTR_TOP = 10;
    const CTR_GAP = 0.6;

    /** Losing clicks: at least this share of the clicks lost. */
    const DECAY_SHARE = 0.2;

    /** Pairs considered at most, most impressions first. */
    const CANDIDATES = 2000;

    /** Missing from the page: pages whose text is read at most, most impressions first. */
    const MISSING_PAGES = 50;

    /** Overlapping pages: the share of a query's impressions each page needs, and pages listed per query. */
    const OVERLAP_SHARE = 0.1;
    const OVERLAP_PAGES = 5;

    /** Impressions a position needs for the site's own CTR there. */
    const CURVE_MIN = 500;

    /** Cautious CTR by position (1–20) where the site has too few impressions. */
    const DEFAULT_CURVE = array(1 => 0.28, 0.15, 0.10, 0.07, 0.055, 0.045, 0.035, 0.03, 0.025, 0.02, 0.015, 0.013, 0.012, 0.011, 0.01, 0.009, 0.008, 0.007, 0.006, 0.005);

    /** Queries and changes listed per losing page. */
    const PER_PAGE = 3;
    const CHANGES  = 5;

    /**
     * The opportunities report.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request().
     * @param string              $kind   One of KINDS.
     * @param string              $engine google or bing (SEOProStats_Search::ENGINES).
     * @return array<string,mixed>
     */
    public static function report(array $req, $kind = 'striking', $engine = 'google') {
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-changes.php';
        $kind   = in_array($kind, self::KINDS, true) ? (string) $kind : 'striking';
        $engine = SEOProStats_Search::engine_name($engine);
        $live   = SEOProStats_Schema::set() === 'live';

        $answer = SEOProStats_Query::cached('opportunities', $req + array('kind' => $kind, 'engine' => $engine, 'imports' => SEOProStats_Search::version()), static function () use ($req, $kind, $engine) {
            return self::build($req, $kind, $engine);
        });

        $answer['connected'] = !$live || SEOProStats_Search::connected($engine);
        // Editor links and people's names depend on the viewer, so they are added outside the shared cache.
        $span = isset($answer['span']) ? $answer['span'] : null;
        unset($answer['span']);
        $changes = $kind === 'decay' && $span && $answer['rows'] ? SEOProStats_Changes::on_pages(array_column($answer['rows'], 'path_id'), $span[0], $span[1], self::CHANGES) : array();
        foreach ($answer['rows'] as &$row) {
            $row = SEOProStats_Clicks::with_edit_url($row);
            if ($kind === 'decay') {
                $row['changes'] = isset($changes[$row['path_id']]) ? $changes[$row['path_id']] : array();
            } elseif ($kind === 'overlap') {
                $row['pages'] = array_map(array('SEOProStats_Clicks', 'with_edit_url'), $row['pages']);
            }
        }
        unset($row);
        return $answer;
    }

    /**
     * The shared part of the answer (cached).
     *
     * @param array<string,mixed> $req  From SEOProStats_Query::request().
     * @param string              $kind One of KINDS.
     * @param string              $name Engine name.
     * @return array<string,mixed>
     */
    private static function build(array $req, $kind, $name) {
        $engine  = SEOProStats_Search::ENGINES[$name];
        $bounds  = SEOProStats_Search::bounds($engine);
        $range   = SEOProStats_Query::range($req);
        $ignored = array();
        $pages   = SEOProStats_Search::page_ids($req['filters'], '', $ignored);
        // Without search days there is nothing to read (and nothing to cut).
        $weekly  = in_array($name, SEOProStats_Search::WEEKLY, true);
        $full    = $bounds['to'] !== '' ? SEOProStats_Search::days($range, $bounds, $weekly) : null;
        $now     = $full ? self::cut($full) : null;
        $days    = $now ? SEOProStats_Search::length($now) : 0;
        $limit   = (int) $req['limit'];
        $offset  = (int) $req['offset'];

        $answer = array(
            'engine'  => $name,
            'engines' => SEOProStats_Search::engines(),
            'kind'    => $kind,
            'range'   => SEOProStats_Query::range_out($now ? $now : $range),
            'days'    => $days,
            'cut'     => $full && $now && SEOProStats_Search::length($full) > $days,
            'through' => $bounds['to'],
            'first'   => $bounds['from'],
            'ignored' => array_values(array_unique($ignored)),
            'rules'   => self::rules($kind, $days),
            'rows'    => array(),
            'total'   => 0,
            'more'    => false,
        );
        if ($kind === 'decay') {
            $answer['compare'] = null;
            $answer['updates'] = array();
        } elseif ($kind === 'overlap') {
            $answer['halves'] = null;
        } elseif ($kind !== 'missing') {
            $answer['curve'] = null;
        }
        if (!$now || ($pages !== null && !$pages)) {
            return $answer;
        }

        if ($kind === 'decay') {
            // Always against an earlier period: the previous one unless a year ago is asked for.
            $other = SEOProStats_Query::compare_range(array('key' => 'custom') + $now, $req['compare'] === 'year' ? 'year' : 'prev');
            $then  = $other ? SEOProStats_Search::days($other, array('from' => '', 'to' => ''), $weekly) : null;
            if (!$then) {
                return $answer;
            }
            $list              = self::decay($engine, $now, $then, $pages, $answer['rules']);
            $answer['compare'] = array('range' => SEOProStats_Query::range_out($then));
            $answer['updates'] = SEOProStats_Changes::updates_between((int) $then['from'], (int) $now['to']);
            $answer['span']    = array((int) $then['from'], (int) $now['to']);
            $answer['rows']    = self::decay_rows($engine, $now, $then, array_slice($list, $offset, $limit));
        } elseif ($kind === 'missing') {
            $list           = self::missing_list($engine, $now, $pages, $answer['rules']);
            $answer['rows'] = self::missing_rows(array_slice($list, $offset, $limit));
        } elseif ($kind === 'overlap') {
            $list             = self::overlap_list($engine, $now, $pages, $answer['rules']);
            $halves           = self::halves($now, $weekly);
            $answer['halves'] = $halves ? array_map(array('SEOProStats_Query', 'range_out'), $halves) : null;
            $answer['rows']   = self::overlap_rows($engine, $halves, $pages, array_slice($list, $offset, $limit));
        } else {
            $curve           = self::curve($engine, $now);
            $answer['curve'] = $curve;
            $list            = self::pairs_list($kind, $engine, $now, $pages, $curve['ctr'], $answer['rules']);
            $answer['rows']  = self::pair_rows(array_slice($list, $offset, $limit));
        }
        $answer['total'] = count($list);
        $answer['more']  = $offset + $limit < count($list);
        return $answer;
    }

    /**
     * The period's newest MAX_DAYS days (all of it when shorter).
     *
     * @param array<string,mixed> $days From SEOProStats_Search::days().
     * @return array<string,mixed>
     */
    public static function cut(array $days) {
        if (SEOProStats_Search::length($days) <= self::MAX_DAYS) {
            return $days;
        }
        /** @var DateTimeImmutable $end */
        $end   = $days['end'];
        $start = $end->modify('-' . self::MAX_DAYS . ' days');
        return array(
            'key'      => 'custom',
            'start'    => $start,
            'from'     => $start->getTimestamp(),
            'day_from' => $start->format('Y-m-d'),
        ) + $days;
    }

    /**
     * The thresholds of a kind for a period of some days.
     *
     * @param string $kind One of KINDS.
     * @param int    $days Days in the period.
     * @return array<string,int|float>
     */
    private static function rules($kind, $days) {
        if ($kind === 'decay') {
            return array(
                'min_lost'   => max(5, (int) round($days / 4)),
                'min_share'  => self::DECAY_SHARE,
            );
        }
        if ($kind === 'missing') {
            return array(
                'min_impressions' => max(10, $days),
                'position_from'   => 1,
                'position_to'     => 20,
                'pages'           => self::MISSING_PAGES,
            );
        }
        if ($kind === 'overlap') {
            return array(
                'min_impressions' => max(20, $days),
                'min_share'       => self::OVERLAP_SHARE,
                'pages'           => self::OVERLAP_PAGES,
            );
        }
        if ($kind === 'ctr') {
            return array(
                'min_impressions' => max(20, $days),
                'position_from'   => 1,
                'position_to'     => self::CTR_TOP,
                'under'           => self::CTR_GAP,
            );
        }
        return array(
            'min_impressions' => max(10, $days),
            'position_from'   => self::STRIKING_FROM,
            'position_to'     => self::STRIKING_TO,
            'target'          => self::TARGET,
        );
    }

    /**
     * The WHERE of a period on a gsc_* table, with its key: path_day when
     * pages are filtered, else the primary key (engine, day).
     *
     * @param int                 $engine Engine.
     * @param array<string,mixed> $days   From SEOProStats_Search::days().
     * @param int[]|null          $pages  Path ids, or null for every page.
     * @return array{key:string,where:string,args:array<int,mixed>}
     */
    private static function where($engine, array $days, $pages) {
        $where = 'engine = %d AND day >= %s AND day <= %s';
        $args  = array((int) $engine, (string) $days['day_from'], (string) $days['day_to']);
        if ($pages !== null) {
            $where .= ' AND path_id IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
            $args   = array_merge($args, array_map('intval', $pages));
        }
        return array('key' => $pages === null ? 'PRIMARY' : 'path_day', 'where' => $where, 'args' => $args);
    }

    /**
     * The site's CTR by position (1–20) in a period: its own where a
     * position has CURVE_MIN impressions, else the default; then made
     * never to rise with position.
     *
     * @param int                 $engine Engine.
     * @param array<string,mixed> $days   From SEOProStats_Search::days().
     * @return array{source:string,ctr:array<int,float>}
     */
    private static function curve($engine, array $days) {
        global $wpdb;
        $on = self::where($engine, $days, null);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day); $on holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT GREATEST(1, ROUND(pos_impr / impressions / 100)) AS b, SUM(clicks) AS c, SUM(impressions) AS i FROM %i FORCE INDEX (`PRIMARY`) WHERE {$on['where']} AND impressions > 0 AND pos_impr < 2050 * impressions GROUP BY b ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $on['args'])), ARRAY_A);
        $own = array();
        foreach ($rows as $row) {
            if ((int) $row['i'] >= self::CURVE_MIN) {
                $own[(int) $row['b']] = (int) $row['c'] / (int) $row['i'];
            }
        }
        $ctr = array();
        for ($b = 1; $b <= 20; $b++) {
            $value   = isset($own[$b]) ? $own[$b] : self::DEFAULT_CURVE[$b];
            $ctr[$b] = round($b > 1 ? min($value, $ctr[$b - 1]) : $value, 4);
        }
        return array(
            'source' => !$own ? 'default' : (count($own) === 20 ? 'site' : 'mixed'),
            'ctr'    => $ctr,
        );
    }

    /**
     * Striking-distance or low-CTR pairs, best first: path_id, query_id,
     * metrics, expected_ctr and potential (clicks).
     *
     * @param string                  $kind   striking or ctr.
     * @param int                     $engine Engine.
     * @param array<string,mixed>     $days   From SEOProStats_Search::days().
     * @param int[]|null              $pages  Path ids, or null for every page.
     * @param array<int,float>        $curve  CTR by position.
     * @param array<string,int|float> $rules  From rules().
     * @return array<int,array<string,mixed>>
     */
    private static function pairs_list($kind, $engine, array $days, $pages, array $curve, array $rules) {
        global $wpdb;
        $on = self::where($engine, $days, $pages);
        // Positions as stored (× 100), from half a place above the first to half below the last.
        $low  = (int) round(((float) $rules['position_from'] - 0.5) * 100);
        $high = (int) round(((float) $rules['position_to'] + 0.5) * 100);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day; $on holds only placeholders and a fixed key name.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`{$on['key']}`) WHERE {$on['where']} GROUP BY path_id, query_id HAVING i >= %d AND p >= %d * i AND p < %d * i ORDER BY i DESC LIMIT %d", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $on['args'], array((int) $rules['min_impressions'], $low, $high, self::CANDIDATES))), ARRAY_A);

        $list = array();
        foreach ($rows as $row) {
            $m = SEOProStats_Search::metrics($row['c'], $row['i'], $row['p']);
            if ($kind === 'striking') {
                $expected = $curve[self::TARGET];
                $gain     = $m['impressions'] * max(0.0, $expected - $m['ctr']);
            } else {
                $expected = $curve[max(1, min(self::CTR_TOP, (int) round($m['position'])))];
                $gain     = $m['ctr'] < $expected * self::CTR_GAP ? $m['impressions'] * $expected - $m['clicks'] : 0;
            }
            $gain = (int) round($gain);
            if ($gain < 1) {
                continue;
            }
            $list[] = array('path_id' => (int) $row['pg'], 'query_id' => (int) $row['q']) + $m + array(
                'expected_ctr' => round($expected, 4),
                'potential'    => $gain,
            );
        }
        usort($list, static function ($a, $b) {
            return array($b['potential'], $b['impressions'], $a['path_id'], $a['query_id']) <=> array($a['potential'], $a['impressions'], $b['path_id'], $b['query_id']);
        });
        return $list;
    }

    /**
     * Pair rows as the answer gives them: page, query and figures.
     *
     * @param array<int,array<string,mixed>> $list From pairs_list(), the rows shown.
     * @return array<int,array<string,mixed>>
     */
    private static function pair_rows(array $list) {
        $text = SEOProStats_Query::texts(array_merge(array_column($list, 'path_id'), array_column($list, 'query_id')));
        $out  = array();
        foreach ($list as $row) {
            $out[] = self::page($row['path_id'], $text) + array(
                'query'        => isset($text[$row['query_id']]) ? $text[$row['query_id']] : '',
                'clicks'       => $row['clicks'],
                'impressions'  => $row['impressions'],
                'ctr'          => $row['ctr'],
                'position'     => $row['position'],
                'expected_ctr' => $row['expected_ctr'],
                'potential'    => $row['potential'],
            );
        }
        return $out;
    }

    /**
     * Queries missing from their page, most impressions first: path_id,
     * query_id, query, metrics and the match (SEOProStats_Coverage::match()).
     * Only the MISSING_PAGES pages with most impressions among the
     * candidates are read, each once.
     *
     * @param int                     $engine Engine.
     * @param array<string,mixed>     $days   From SEOProStats_Search::days().
     * @param int[]|null              $pages  Path ids, or null for every page.
     * @param array<string,int|float> $rules  From rules().
     * @return array<int,array<string,mixed>>
     */
    private static function missing_list($engine, array $days, $pages, array $rules) {
        global $wpdb;
        require_once __DIR__ . '/class-seoprostats-coverage.php';
        $on   = self::where($engine, $days, $pages);
        $high = (int) round(((float) $rules['position_to'] + 0.5) * 100);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day; $on holds only placeholders and a fixed key name.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`{$on['key']}`) WHERE {$on['where']} GROUP BY path_id, query_id HAVING i >= %d AND p < %d * i ORDER BY i DESC, path_id, query_id LIMIT %d", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $on['args'], array((int) $rules['min_impressions'], $high, self::CANDIDATES))), ARRAY_A);

        $read = array();
        foreach ($rows as $row) {
            if (count($read) >= (int) $rules['pages']) {
                break;
            }
            $read[(int) $row['pg']] = true;
        }
        $index = array();
        foreach (SEOProStats_Coverage::texts(SEOProStats_Query::texts(array_keys($read))) as $path_id => $text) {
            $index[$path_id] = SEOProStats_Coverage::index($text);
        }
        $rows  = array_values(array_filter($rows, static function ($row) use ($index) {
            return isset($index[(int) $row['pg']]);
        }));
        $words = SEOProStats_Query::texts(array_column($rows, 'q'));

        $list = array();
        foreach ($rows as $row) {
            $query = isset($words[(int) $row['q']]) ? $words[(int) $row['q']] : '';
            $match = $query !== '' ? SEOProStats_Coverage::match($query, $index[(int) $row['pg']]) : null;
            if ($match && in_array($match['match'], array('partial', 'none'), true)) {
                $list[] = array('path_id' => (int) $row['pg'], 'query' => $query) + SEOProStats_Search::metrics($row['c'], $row['i'], $row['p']) + $match;
            }
        }
        return $list;
    }

    /**
     * Missing-query rows as the answer gives them: page, query, figures
     * and match.
     *
     * @param array<int,array<string,mixed>> $list From missing_list(), the rows shown.
     * @return array<int,array<string,mixed>>
     */
    private static function missing_rows(array $list) {
        $text = SEOProStats_Query::texts(array_column($list, 'path_id'));
        $out  = array();
        foreach ($list as $row) {
            $out[] = self::page($row['path_id'], $text) + array_diff_key($row, array('path_id' => true));
        }
        return $out;
    }

    /**
     * Queries shared by pages, most impressions on pages other than the
     * leading one first: query_id, the query's sums and its pages (each
     * with OVERLAP_SHARE or more of the query's impressions, most first).
     * From the CANDIDATES pairs with most impressions.
     *
     * @param int                     $engine Engine.
     * @param array<string,mixed>     $days   From SEOProStats_Search::days().
     * @param int[]|null              $pages  Path ids, or null for every page.
     * @param array<string,int|float> $rules  From rules().
     * @return array<int,array<string,mixed>>
     */
    private static function overlap_list($engine, array $days, $pages, array $rules) {
        global $wpdb;
        $on    = self::where($engine, $days, $pages);
        $share = (float) $rules['min_share'];
        // A pair under this cannot hold its share of a query with the fewest impressions listed.
        $least = max(1, (int) ceil((int) $rules['min_impressions'] * $share));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day; $on holds only placeholders and a fixed key name.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, query_id AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`{$on['key']}`) WHERE {$on['where']} GROUP BY path_id, query_id HAVING i >= %d ORDER BY i DESC, path_id, query_id LIMIT %d", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $on['args'], array($least, self::CANDIDATES))), ARRAY_A);
        $by_query = array();
        foreach ($rows as $row) {
            $by_query[(int) $row['q']][] = array('path_id' => (int) $row['pg'], 'c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
        }

        $list = array();
        foreach ($by_query as $query_id => $pairs) {
            if (count($pairs) < 2) {
                continue;
            }
            $sum = array('c' => 0, 'i' => 0, 'p' => 0);
            foreach ($pairs as $pair) {
                $sum['c'] += $pair['c'];
                $sum['i'] += $pair['i'];
                $sum['p'] += $pair['p'];
            }
            $shared = array_values(array_filter($pairs, static function ($pair) use ($sum, $share) {
                return $pair['i'] >= $share * $sum['i'];
            }));
            if ($sum['i'] < (int) $rules['min_impressions'] || count($shared) < 2) {
                continue;
            }
            // Pairs come most impressions first, so the leading page is the first.
            $list[] = array(
                'query_id' => (int) $query_id,
                'sum'      => $sum,
                'pairs'    => $shared,
                'others'   => $sum['i'] - $shared[0]['i'],
            );
        }
        usort($list, static function ($a, $b) {
            return array($b['others'], $b['sum']['i'], $a['query_id']) <=> array($a['others'], $a['sum']['i'], $b['query_id']);
        });
        return $list;
    }

    /**
     * The two halves of a period (whole weeks for an engine that gives
     * pages by week), or null when it is too short to halve.
     *
     * @param array<string,mixed> $days   From SEOProStats_Search::days().
     * @param bool                $weekly Whether rows come by week.
     * @return array{0:array<string,mixed>,1:array<string,mixed>}|null
     */
    private static function halves(array $days, $weekly) {
        $length = SEOProStats_Search::length($days);
        $half   = $weekly ? 7 * (int) floor($length / 14) : (int) floor($length / 2);
        if ($half < 1) {
            return null;
        }
        /** @var DateTimeImmutable $start */
        $start = $days['start'];
        $mid   = $start->modify('+' . $half . ' days');
        $first = array(
            'key'    => 'custom',
            'end'    => $mid,
            'to'     => $mid->getTimestamp(),
            'day_to' => $mid->modify('-1 day')->format('Y-m-d'),
        ) + $days;
        $second = array(
            'key'      => 'custom',
            'start'    => $mid,
            'from'     => $mid->getTimestamp(),
            'day_from' => $mid->format('Y-m-d'),
        ) + $days;
        return array($first, $second);
    }

    /**
     * Shared queries as the answer gives them: the query, its sums, the
     * leading page (its fields head the row), each page's figures and
     * share, the leading page of each half and whether it changed, and
     * the clicks the query would have if all its pages' impressions had
     * the best of their CTRs.
     *
     * One read of gsc_pairs by key query_day for the shown queries' first
     * halves; the second half is the period less the first.
     *
     * @param int                                          $engine Engine.
     * @param array{0:array<string,mixed>,1:array<string,mixed>}|null $halves From halves().
     * @param int[]|null                                   $pages  Path ids, or null for every page.
     * @param array<int,array<string,mixed>>               $list   From overlap_list(), the rows shown.
     * @return array<int,array<string,mixed>>
     */
    private static function overlap_rows($engine, $halves, $pages, array $list) {
        global $wpdb;
        if (!$list) {
            return array();
        }
        $first = array();
        if ($halves) {
            $ids   = array_column($list, 'query_id');
            $where = 'query_id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ') AND day >= %s AND day <= %s AND engine = %d';
            $args  = array_merge($ids, array((string) $halves[0]['day_from'], (string) $halves[0]['day_to'], (int) $engine));
            if ($pages !== null) {
                $where .= ' AND path_id IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
                $args   = array_merge($args, array_map('intval', $pages));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by key query_day (query_id, day); $where holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT query_id AS q, path_id AS pg, SUM(impressions) AS i FROM %i FORCE INDEX (`query_day`) WHERE $where GROUP BY query_id, path_id ORDER BY NULL", array_merge(array(SEOProStats_Schema::table('gsc_pairs')), $args)), ARRAY_A);
            foreach ($rows as $row) {
                $first[(int) $row['q'] . ':' . (int) $row['pg']] = (int) $row['i'];
            }
        }

        $ids  = array_column($list, 'query_id');
        foreach ($list as $row) {
            $ids = array_merge($ids, array_column($row['pairs'], 'path_id'));
        }
        $text = SEOProStats_Query::texts($ids);
        $out  = array();
        foreach ($list as $row) {
            $q     = (int) $row['query_id'];
            $best  = 0.0;
            $lead  = array(null, null);
            $most  = array(0, 0);
            $items = array();
            foreach ($row['pairs'] as $pair) {
                $m    = SEOProStats_Search::metrics($pair['c'], $pair['i'], $pair['p']);
                $best = max($best, (float) $m['ctr']);
                if ($halves) {
                    $was  = isset($first[$q . ':' . $pair['path_id']]) ? min($pair['i'], $first[$q . ':' . $pair['path_id']]) : 0;
                    $half = array($was, $pair['i'] - $was);
                    foreach (array(0, 1) as $h) {
                        if ($half[$h] > $most[$h]) {
                            $most[$h] = $half[$h];
                            $lead[$h] = $pair['path_id'];
                        }
                    }
                }
                $items[] = self::page($pair['path_id'], $text) + $m + array('share' => round($pair['i'] / max(1, $row['sum']['i']), 4));
            }
            $shown     = array_slice($items, 0, self::OVERLAP_PAGES);
            $listed    = array_sum(array_column($items, 'impressions'));
            $clicks    = array_sum(array_column($items, 'clicks'));
            $path_of   = static function ($id) use ($text) {
                return $id !== null && isset($text[$id]) ? $text[$id] : null;
            };
            // The row is the leading page's, with the query's sums over all its pages read.
            $out[] = self::page($row['pairs'][0]['path_id'], $text) + array(
                'query' => isset($text[$q]) ? $text[$q] : '',
            ) + SEOProStats_Search::metrics($row['sum']['c'], $row['sum']['i'], $row['sum']['p']) + array(
                'pages'      => $shown,
                'page_count' => count($items),
                'leaders'    => array($path_of($lead[0]), $path_of($lead[1])),
                'switched'   => $lead[0] !== null && $lead[1] !== null && $lead[0] !== $lead[1],
                'potential'  => max(0, (int) round($listed * $best - $clicks)),
            );
        }
        return $out;
    }

    /**
     * A page's fields in a row: path_id, path, url, post_id, edit_url.
     *
     * @param int               $path_id Path id.
     * @param array<int,string> $text    Texts by id.
     * @return array<string,mixed>
     */
    private static function page($path_id, array $text) {
        $path = isset($text[$path_id]) ? $text[$path_id] : '';
        return array('path_id' => (int) $path_id) + (SEOProStats_Clicks::page_info($path) ?: array('path' => $path, 'url' => '', 'post_id' => 0, 'edit_url' => null));
    }

    /**
     * Sums by page or by page and query in a period.
     *
     * @param string              $table  gsc_pages or gsc_pairs.
     * @param int                 $engine Engine.
     * @param array<string,mixed> $days   From SEOProStats_Search::days().
     * @param int[]|null          $pages  Path ids, or null for every page.
     * @return array<string,array{c:int,i:int,p:int}> "path_id" or "path_id:query_id" => sums.
     */
    public static function sums($table, $engine, array $days, $pages) {
        global $wpdb;
        $on = self::where($engine, $days, $pages);
        $by = $table === 'gsc_pairs' ? 'path_id, query_id' : 'path_id';
        $q  = $table === 'gsc_pairs' ? 'query_id' : '0';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day; $by, $q and $on hold fixed SQL and placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id AS pg, $q AS q, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p FROM %i FORCE INDEX (`{$on['key']}`) WHERE {$on['where']} GROUP BY $by ORDER BY NULL", array_merge(array(SEOProStats_Schema::table($table)), $on['args'])), ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $key       = $table === 'gsc_pairs' ? $row['pg'] . ':' . $row['q'] : (string) $row['pg'];
            $out[$key] = array('c' => (int) $row['c'], 'i' => (int) $row['i'], 'p' => (int) $row['p']);
        }
        return $out;
    }

    /**
     * Pages losing clicks, most lost first: path_id, now and then sums.
     *
     * @param int                     $engine Engine.
     * @param array<string,mixed>     $now    From SEOProStats_Search::days().
     * @param array<string,mixed>     $then   The earlier period, the same way.
     * @param int[]|null              $pages  Path ids, or null for every page.
     * @param array<string,int|float> $rules  From rules().
     * @return array<int,array<string,mixed>>
     */
    private static function decay($engine, array $now, array $then, $pages, array $rules) {
        $after  = self::sums('gsc_pages', $engine, $now, $pages);
        $before = self::sums('gsc_pages', $engine, $then, $pages);
        $zero   = array('c' => 0, 'i' => 0, 'p' => 0);
        $list   = array();
        foreach ($before as $id => $was) {
            $is   = isset($after[$id]) ? $after[$id] : $zero;
            $lost = $was['c'] - $is['c'];
            if ($lost >= (int) $rules['min_lost'] && $lost >= $was['c'] * (float) $rules['min_share']) {
                $list[] = array('path_id' => (int) $id, 'lost' => $lost, 'now' => $is, 'then' => $was);
            }
        }
        usort($list, static function ($a, $b) {
            return array($b['lost'], $b['then']['c'], $a['path_id']) <=> array($a['lost'], $a['then']['c'], $b['path_id']);
        });
        return $list;
    }

    /**
     * Losing pages as the answer gives them, with the likely cause and
     * the queries that lost most.
     *
     * @param int                            $engine Engine.
     * @param array<string,mixed>            $now    From SEOProStats_Search::days().
     * @param array<string,mixed>            $then   The earlier period.
     * @param array<int,array<string,mixed>> $list   From decay(), the rows shown.
     * @return array<int,array<string,mixed>>
     */
    private static function decay_rows($engine, array $now, array $then, array $list) {
        if (!$list) {
            return array();
        }
        $ids    = array_column($list, 'path_id');
        $after  = self::sums('gsc_pairs', $engine, $now, $ids);
        $before = self::sums('gsc_pairs', $engine, $then, $ids);
        $zero   = array('c' => 0, 'i' => 0, 'p' => 0);

        // The queries each page lost most clicks on.
        $lost_by = array();
        foreach ($before as $key => $was) {
            list($path_id, $query_id) = array_map('intval', explode(':', $key));
            $is   = isset($after[$key]) ? $after[$key] : $zero;
            $lost = $was['c'] - $is['c'];
            if ($lost > 0) {
                $lost_by[$path_id][] = array('query_id' => $query_id, 'lost' => $lost, 'now' => $is, 'then' => $was);
            }
        }
        $query_ids = array();
        foreach ($lost_by as $path_id => $queries) {
            usort($queries, static function ($a, $b) {
                return array($b['lost'], $a['query_id']) <=> array($a['lost'], $b['query_id']);
            });
            $lost_by[$path_id] = array_slice($queries, 0, self::PER_PAGE);
            $query_ids         = array_merge($query_ids, array_column($lost_by[$path_id], 'query_id'));
        }
        $text = SEOProStats_Query::texts(array_merge($ids, $query_ids));

        $out = array();
        foreach ($list as $row) {
            $is    = SEOProStats_Search::metrics($row['now']['c'], $row['now']['i'], $row['now']['p']);
            $was   = SEOProStats_Search::metrics($row['then']['c'], $row['then']['i'], $row['then']['p']);
            $cause = self::cause($is, $was);
            $item  = self::page($row['path_id'], $text) + $is + array(
                'compare' => $was + array('change' => SEOProStats_Search::change($is, $was)),
                'lost'    => $row['lost'],
                'cause'   => $cause[0],
                'why'     => $cause[1],
                'queries' => array(),
                'changes' => array(),
            );
            foreach (isset($lost_by[$row['path_id']]) ? $lost_by[$row['path_id']] : array() as $q) {
                $q_now             = SEOProStats_Search::metrics($q['now']['c'], $q['now']['i'], $q['now']['p']);
                $q_then            = SEOProStats_Search::metrics($q['then']['c'], $q['then']['i'], $q['then']['p']);
                $item['queries'][] = array(
                    'query'         => isset($text[$q['query_id']]) ? $text[$q['query_id']] : '',
                    'lost'          => $q['lost'],
                    'clicks'        => $q_now['clicks'],
                    'then_clicks'   => $q_then['clicks'],
                    'position'      => $q_now['impressions'] ? $q_now['position'] : null,
                    'then_position' => $q_then['impressions'] ? $q_then['position'] : null,
                );
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * The likely cause of fewer clicks, and a sentence saying why:
     * gone (no impressions now), position (ranks at least a place and a
     * tenth lower), else whichever fell more of impressions (demand) and
     * CTR.
     *
     * @param array<string,int|float> $now  Metrics now.
     * @param array<string,int|float> $then Metrics then (with impressions).
     * @return array{0:string,1:string}
     */
    private static function cause(array $now, array $then) {
        $pos = static function ($value) {
            return number_format_i18n((float) $value, 1);
        };
        $pct = static function ($value) {
            return number_format_i18n(100 * (float) $value, 1) . '%';
        };
        if (!$now['impressions']) {
            return array('gone', __('No impressions in this period: the page no longer shows in search results.', 'seoprostats'));
        }
        $places = (float) $now['position'] - (float) $then['position'];
        if ($places >= max(1.0, 0.1 * (float) $then['position'])) {
            /* translators: 1: average position now, 2: average position before */
            return array('position', sprintf(__('It ranks lower: average position %1$s, was %2$s.', 'seoprostats'), $pos($now['position']), $pos($then['position'])));
        }
        // Clicks = impressions × CTR, so the larger fall (as a log ratio) is the cause.
        $demand = log((int) $now['impressions'] / max(1, (int) $then['impressions']));
        $rate   = (int) $now['clicks'] > 0 ? log(((int) $now['clicks'] / (int) $now['impressions']) / ((int) $then['clicks'] / (int) $then['impressions'])) : -INF;
        if ($demand <= $rate) {
            /* translators: 1: share fewer impressions, 2: average position now, 3: average position before */
            return array('demand', sprintf(__('Fewer searches: %1$s fewer impressions at about the same position (%2$s, was %3$s).', 'seoprostats'), $pct(1 - (int) $now['impressions'] / (int) $then['impressions']), $pos($now['position']), $pos($then['position'])));
        }
        /* translators: 1: CTR now, 2: CTR before, 3: average position now */
        return array('ctr', sprintf(__('Fewer searchers choose it: CTR %1$s, was %2$s, at about the same position (%3$s).', 'seoprostats'), $pct($now['ctr']), $pct($then['ctr']), $pos($now['position'])));
    }
}
