<?php
/**
 * The search report (Search → Rankings): clicks, impressions, CTR and
 * average position from Search Console's imported days (the gsc_*
 * tables), for the site, a page or a query, by day, with tables of
 * queries, pages, countries, devices, search appearances and the chart's
 * days (weeks or months), and the comparison.
 *
 * Search days are the engine's (Pacific time for Google) and final only,
 * so the newest is about three days old. A period is cut at the newest
 * day with search data and the comparison has the same number of days,
 * so days not in yet never look like a drop. Visit filters (source,
 * country…) do not apply to search data; page filters do. Every read is
 * by a primary key (engine, day) or the path_day / query_day keys, and
 * nothing reads the visit tables.
 *
 * Combined (engine all) adds up every engine with data: clicks,
 * impressions and position × impressions, so CTR and position are over
 * all of them, and a query or page shared by engines is one row. Its
 * period ends at the earliest of the engines' newest days; with an engine
 * that gives pages and queries by week, it is whole weeks. Each engine's
 * days are its own (Pacific time for Google, UTC for Bing).
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

final class SEOProStats_Search {

    /** Kinds of report rows. */
    const KINDS = array('queries', 'pages', 'countries', 'devices', 'appearance', 'days');

    /** Kinds every engine, page and query has (the others are Google's whole site only). */
    const ANY_KINDS = array('queries', 'pages', 'days');

    /**
     * Orders of the rows; the first is the default. Days also sort by day
     * (their default, newest first).
     */
    const SORTS = array('impressions', 'clicks', 'ctr', 'position');

    /** Sorts whose natural order is lowest first (a lower position is better); the others are most first. */
    const ASCENDING = array('position');

    /** Each sort's figure in SQL, from the sums of a group. */
    const SORT_SQL = array(
        'impressions' => 'SUM(impressions)',
        'clicks'      => 'SUM(clicks)',
        'ctr'         => 'LEAST(1, SUM(clicks) / NULLIF(SUM(impressions), 0))',
        'position'    => 'SUM(pos_impr) / NULLIF(SUM(impressions), 0)',
    );

    /** Daily points up to this many days, else monthly. */
    const DAILY_DAYS = 120;

    /** Search engines by name (SEOProStats_Schema::ENGINE_*). */
    const ENGINES = array(
        'google' => SEOProStats_Schema::ENGINE_GOOGLE,
        'bing'   => SEOProStats_Schema::ENGINE_BING,
    );

    /** The source (SEOProStats_Connections::SOURCES) each engine's data comes from. */
    const ENGINE_SOURCES = array(
        'google' => 'search-console',
        'bing'   => 'bing',
    );

    /**
     * Engines whose pages and queries come by week, stored on the week's
     * last day (Bing); their charts of a page or query are by week.
     */
    const WEEKLY = array('bing');

    /**
     * Combined: every engine with data, added up (Rankings, Opportunities
     * and Content only). Not a stored engine code.
     */
    const ALL = 'all';

    /** Device codes of gsc_totals, by name (SEOProStats_Schema::GSC_DEVICES). */
    const DEVICES = array(
        1 => 'desktop',
        2 => 'mobile',
        3 => 'tablet',
    );

    /**
     * ISO 3166-1 alpha-3 (as Search Console gives countries) to alpha-2
     * (as visits store them and browsers name them), lower case.
     */
    const COUNTRIES = 'abw:aw,afg:af,ago:ao,aia:ai,ala:ax,alb:al,and:ad,are:ae,arg:ar,arm:am,asm:as,ata:aq,atf:tf,atg:ag,aus:au,aut:at,aze:az,bdi:bi,bel:be,ben:bj,bes:bq,bfa:bf,bgd:bd,bgr:bg,bhr:bh,bhs:bs,bih:ba,blm:bl,blr:by,blz:bz,bmu:bm,bol:bo,bra:br,brb:bb,brn:bn,btn:bt,bvt:bv,bwa:bw,caf:cf,can:ca,cck:cc,che:ch,chl:cl,chn:cn,civ:ci,cmr:cm,cod:cd,cog:cg,cok:ck,col:co,com:km,cpv:cv,cri:cr,cub:cu,cuw:cw,cxr:cx,cym:ky,cyp:cy,cze:cz,deu:de,dji:dj,dma:dm,dnk:dk,dom:do,dza:dz,ecu:ec,egy:eg,eri:er,esh:eh,esp:es,est:ee,eth:et,fin:fi,fji:fj,flk:fk,fra:fr,fro:fo,fsm:fm,gab:ga,gbr:gb,geo:ge,ggy:gg,gha:gh,gib:gi,gin:gn,glp:gp,gmb:gm,gnb:gw,gnq:gq,grc:gr,grd:gd,grl:gl,gtm:gt,guf:gf,gum:gu,guy:gy,hkg:hk,hmd:hm,hnd:hn,hrv:hr,hti:ht,hun:hu,idn:id,imn:im,ind:in,iot:io,irl:ie,irn:ir,irq:iq,isl:is,isr:il,ita:it,jam:jm,jey:je,jor:jo,jpn:jp,kaz:kz,ken:ke,kgz:kg,khm:kh,kir:ki,kna:kn,kor:kr,kwt:kw,lao:la,lbn:lb,lbr:lr,lby:ly,lca:lc,lie:li,lka:lk,lso:ls,ltu:lt,lux:lu,lva:lv,mac:mo,maf:mf,mar:ma,mco:mc,mda:md,mdg:mg,mdv:mv,mex:mx,mhl:mh,mkd:mk,mli:ml,mlt:mt,mmr:mm,mne:me,mng:mn,mnp:mp,moz:mz,mrt:mr,msr:ms,mtq:mq,mus:mu,mwi:mw,mys:my,myt:yt,nam:na,ncl:nc,ner:ne,nfk:nf,nga:ng,nic:ni,niu:nu,nld:nl,nor:no,npl:np,nru:nr,nzl:nz,omn:om,pak:pk,pan:pa,pcn:pn,per:pe,phl:ph,plw:pw,png:pg,pol:pl,pri:pr,prk:kp,prt:pt,pry:py,pse:ps,pyf:pf,qat:qa,reu:re,rou:ro,rus:ru,rwa:rw,sau:sa,sdn:sd,sen:sn,sgp:sg,sgs:gs,shn:sh,sjm:sj,slb:sb,sle:sl,slv:sv,smr:sm,som:so,spm:pm,srb:rs,ssd:ss,stp:st,sur:sr,svk:sk,svn:si,swe:se,swz:sz,sxm:sx,syc:sc,syr:sy,tca:tc,tcd:td,tgo:tg,tha:th,tjk:tj,tkl:tk,tkm:tm,tls:tl,ton:to,tto:tt,tun:tn,tur:tr,tuv:tv,twn:tw,tza:tz,uga:ug,ukr:ua,umi:um,ury:uy,usa:us,uzb:uz,vat:va,vct:vc,ven:ve,vgb:vg,vir:vi,vnm:vn,vut:vu,wlf:wf,wsm:ws,xkk:xk,yem:ye,zaf:za,zmb:zm,zwe:zw';

    /**
     * The search report.
     *
     * @param array<string,mixed> $req   From SEOProStats_Query::request().
     * @param string              $kind  One of KINDS.
     * @param string              $page   Only this page (path; * for any text); '' for all.
     * @param string              $query  Only this query (* for any text); '' for all.
     * @param string              $engine google, bing (ENGINES) or all (ALL).
     * @param string              $sort   One of SORTS, or day for days; '' for the kind's default (impressions; days: day).
     * @param string              $order  asc or desc; '' for the sort's natural order (sort_order()).
     * @return array<string,mixed>
     */
    public static function report(array $req, $kind = 'queries', $page = '', $query = '', $engine = 'google', $sort = '', $order = '') {
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        $kind   = in_array($kind, self::KINDS, true) ? (string) $kind : 'queries';
        $page   = trim((string) $page);
        $query  = SEOProStats_Dict::clean(trim((string) preg_replace('/\s+/u', ' ', (string) $query)));
        $engine = self::report_engine($engine);
        $live   = SEOProStats_Schema::set() === 'live';
        $sorts  = $kind === 'days' ? array_merge(array('day'), self::SORTS) : self::SORTS;
        $sort   = in_array($sort, $sorts, true) ? (string) $sort : $sorts[0];
        $order  = self::sort_order($sort, $order);

        $answer = SEOProStats_Query::cached('search', $req + array('kind' => $kind, 'page' => $page, 'query' => $query, 'engine' => $engine, 'sort' => $sort, 'order' => $order, 'imports' => self::version()), static function () use ($req, $kind, $page, $query, $engine, $sort, $order) {
            $code    = self::codes($engine);
            $bounds  = self::span($engine);
            $range   = SEOProStats_Query::range($req);
            $ignored = array();
            $pages   = self::page_ids($req['filters'], $page, $ignored);
            $queries = $query === '' ? null : self::query_ids($query);
            $weekly  = self::weekly($engine);
            $now     = self::days($range, $bounds, $weekly);
            $scope   = self::scope($code, $now, $pages, $queries);
            $grain   = self::grain($engine, $now, $scope);
            // Combined weeks end on the period's last day, so each holds one week of every engine.
            $anchor  = $grain !== 'week' || !$now ? '' : ($engine === self::ALL ? (string) $now['day_to'] : self::week_end($code, $bounds));
            $totals  = self::totals($scope);
            $points  = $now ? self::series($scope, $now, $grain, $anchor) : array();
            $by      = array('sort' => $sort, 'order' => $order);
            if ($kind !== 'days') {
                $rows = self::rows($scope, $kind, (int) $req['limit'], (int) $req['offset'], $totals, $by);
            } else {
                // No search data yet: no rows of zeros (as the chart, which is not drawn then).
                $rows = $bounds['to'] !== '' ? self::day_rows($points, $now, $grain, $anchor, (int) $req['limit'], (int) $req['offset'], $totals, $by) : array();
            }
            $more    = count($rows) > (int) $req['limit'];
            $rows    = array_slice($rows, 0, (int) $req['limit']);

            $answer = array(
                'engine'    => $engine,
                'engines'   => self::engines(),
                'combined'  => self::combinable(),
                'range'     => $now ? self::range_out($now) : SEOProStats_Query::range_out($range),
                'through'   => $bounds['to'],
                'first'     => $bounds['from'],
                'kind'      => $kind,
                'sort'      => $sort,
                'order'     => $order,
                'page'      => $page,
                'query'     => $query,
                'page_info' => SEOProStats_Clicks::page_info($page),
                'ignored'   => array_values(array_unique($ignored)),
                'totals'    => $totals,
                'grain'     => $grain,
                'points'    => $points,
                'rows'      => $rows,
                'more'      => $more,
            );
            $other = $now ? SEOProStats_Query::compare_range($now, $req['compare']) : null;
            if ($other) {
                $then_days = self::days($other, array('from' => '', 'to' => ''), $weekly);
                $then      = self::scope($code, $then_days, $pages, $queries);
                $before    = self::totals($then);
                $answer['rows']    = self::with_compare($then, $kind, $answer['rows']);
                $answer['compare'] = array(
                    'range'  => SEOProStats_Query::range_out($other),
                    'totals' => $before,
                    'change' => self::change($totals, $before),
                    'points' => $then_days ? self::series($then, $then_days, $grain, $engine === self::ALL && $anchor !== '' ? (string) $then_days['day_to'] : $anchor) : array(),
                );
            }
            return $answer;
        });

        $answer['connected'] = !$live || self::connected($engine);
        // Editor links depend on the viewer, so they are added outside the shared cache.
        if ($answer['page_info'] !== null) {
            $answer['page_info'] = SEOProStats_Clicks::with_edit_url($answer['page_info']);
        }
        if ($kind === 'pages') {
            foreach ($answer['rows'] as &$row) {
                $row = SEOProStats_Clicks::with_edit_url($row);
            }
            unset($row);
        }
        return $answer;
    }

    /**
     * Whether an engine's source is connected (live data): Search Console
     * for Google, Bing Webmaster Tools for Bing; for Combined, any of
     * the engines it adds up.
     *
     * @param string $engine google, bing or all.
     * @return bool
     */
    public static function connected($engine = 'google') {
        require_once __DIR__ . '/class-seoprostats-connections.php';
        if ((string) $engine === self::ALL) {
            foreach (self::with_data() as $name) {
                if (self::connected($name)) {
                    return true;
                }
            }
            return false;
        }
        return SEOProStats_Connections::get(self::ENGINE_SOURCES[self::engine_name($engine)]) !== null;
    }

    /**
     * An engine's name as the reports take it: google unless bing.
     *
     * @param string $engine Engine name.
     * @return string
     */
    public static function engine_name($engine) {
        return isset(self::ENGINES[(string) $engine]) ? (string) $engine : 'google';
    }

    /**
     * An engine's name as Rankings, Opportunities and Content take it:
     * as engine_name(), or all (Combined) while two or more engines have
     * data; with fewer, all is the one engine with data (else google).
     *
     * @param string $engine google, bing or all.
     * @return string
     */
    public static function report_engine($engine) {
        if ((string) $engine !== self::ALL) {
            return self::engine_name($engine);
        }
        $with = self::with_data();
        if (count($with) > 1) {
            return self::ALL;
        }
        return $with ? $with[0] : 'google';
    }

    /**
     * The engines with search data, Google first.
     *
     * @return string[]
     */
    public static function with_data() {
        $out = array();
        foreach (self::ENGINES as $name => $code) {
            if (self::bounds($code)['to'] !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Whether Combined adds anything up: two or more engines have data.
     *
     * @return bool
     */
    public static function combinable() {
        return count(self::with_data()) > 1;
    }

    /**
     * The engine codes a report reads: the engine's, or for Combined
     * every engine's with data.
     *
     * @param string $engine From report_engine().
     * @return int[]
     */
    public static function codes($engine) {
        if ((string) $engine !== self::ALL) {
            return array(self::ENGINES[self::engine_name($engine)]);
        }
        $out = array();
        foreach (self::with_data() as $name) {
            $out[] = self::ENGINES[$name];
        }
        return $out ? $out : array(SEOProStats_Schema::ENGINE_GOOGLE);
    }

    /**
     * Whether an engine's pages and queries come by week (WEEKLY); for
     * Combined, whether any of its engines' do.
     *
     * @param string $engine From report_engine().
     * @return bool
     */
    public static function weekly($engine) {
        if ((string) $engine !== self::ALL) {
            return in_array((string) $engine, self::WEEKLY, true);
        }
        return (bool) array_intersect(self::with_data(), self::WEEKLY);
    }

    /**
     * First and newest day of a report's engine (bounds()). For Combined:
     * the first day of any engine and the earliest of their newest days,
     * so one engine's lag never looks like a drop.
     *
     * @param string $engine From report_engine().
     * @return array{from:string,to:string}
     */
    public static function span($engine) {
        $from = '';
        $to   = '';
        foreach (self::codes($engine) as $n => $code) {
            $one  = self::bounds($code);
            if ($one['from'] !== '' && ($from === '' || $one['from'] < $from)) {
                $from = $one['from'];
            }
            $to = $n === 0 || $one['to'] < $to ? $one['to'] : $to;
        }
        return array('from' => $from, 'to' => $to);
    }

    /**
     * The engine condition of a read: `engine = %d`, or `engine IN (…)`
     * for Combined; a range read of the primary key (engine, day, …) for
     * each engine either way.
     *
     * @param int|int[] $engine Engine code or codes.
     * @return array{sql:string,args:int[]}
     */
    public static function engine_where($engine) {
        $codes = array_values(array_map('intval', (array) $engine));
        if (count($codes) === 1) {
            return array('sql' => 'engine = %d', 'args' => $codes);
        }
        return array('sql' => 'engine IN (' . implode(', ', array_fill(0, count($codes), '%d')) . ')', 'args' => $codes);
    }

    /**
     * The engines with search data, or whose source is connected (live
     * data), Google first; at least Google.
     *
     * @return string[]
     */
    public static function engines() {
        $live = SEOProStats_Schema::set() === 'live';
        $out  = array();
        foreach (self::ENGINES as $name => $code) {
            if ($name === 'google' || self::bounds($code)['to'] !== '' || ($live && self::connected($name))) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Points by day; by month past DAILY_DAYS; by week for a page or
     * query of an engine whose pages and queries come by week (or of
     * Combined with such an engine).
     *
     * @param string                   $engine Engine name, or all.
     * @param array<string,mixed>|null $days   From days().
     * @param array<string,mixed>|null $scope  From scope().
     * @return string day, week or month.
     */
    public static function grain($engine, $days, $scope) {
        if ($days && self::length($days) > self::DAILY_DAYS) {
            return 'month';
        }
        return self::weekly($engine) && $scope && $scope['table'] !== 'gsc_totals' ? 'week' : 'day';
    }

    /**
     * The newest week's last day of an engine whose pages come by week
     * (the newest day of its pages), so weekly points line up with the
     * weeks; the newest day with data without pages.
     *
     * @param int[]                        $engine Engine code (one).
     * @param array{from:string,to:string} $bounds From bounds().
     * @return string Y-m-d, or ''.
     */
    private static function week_end(array $engine, array $bounds) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, MAX of the primary key's (engine, day) prefix.
        $day = (string) $wpdb->get_var($wpdb->prepare('SELECT MAX(day) FROM %i WHERE engine = %d', SEOProStats_Schema::table('gsc_pages'), (int) $engine[0]));
        return $day !== '' ? $day : $bounds['to'];
    }

    /**
     * What the answer depends on besides the visits: the newest import and
     * when one last finished or was undone, so new days show at once.
     *
     * @return string
     */
    public static function version() {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return '';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, a short list of import runs.
        $row = $wpdb->get_row($wpdb->prepare('SELECT MAX(id) AS i, MAX(finished) AS f, COUNT(*) AS n FROM %i', SEOProStats_Schema::table('imports')), ARRAY_A);
        return is_array($row) ? (int) $row['i'] . '.' . (int) $row['f'] . '.' . (int) $row['n'] : '';
    }

    /**
     * First and newest day with search data of an engine.
     *
     * @param int $engine Engine.
     * @return array{from:string,to:string}
     */
    public static function bounds($engine) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, MIN and MAX of the primary key's (engine, day) prefix.
        $row = $wpdb->get_row($wpdb->prepare('SELECT MIN(day) AS f, MAX(day) AS t FROM %i WHERE engine = %d', SEOProStats_Schema::table('gsc_totals'), (int) $engine), ARRAY_A);
        return array(
            'from' => is_array($row) && $row['f'] ? (string) $row['f'] : '',
            'to'   => is_array($row) && $row['t'] ? (string) $row['t'] : '',
        );
    }

    /**
     * A range's days, cut to the days with search data (all time: from
     * the first), as a range SEOProStats_Query can compare: start and end
     * (the day after the last) in the site time zone.
     *
     * An engine whose pages and queries come by week ($weekly) has its
     * range widened at the start to whole weeks, the weeks the range's
     * days fall in, so no chosen day with data is left out: any run of
     * seven days holds one week's figures, so a period and the one it is
     * compared with hold as many weeks, and a period ending on the newest
     * week (as it does at `through`) holds whole weeks. Where widening would
     * start before the first day with data (all time), it is cut instead.
     *
     * @param array<string,mixed>      $range  From SEOProStats_Query::range() or compare_range().
     * @param array{from:string,to:string} $bounds From bounds(); '' for no cut.
     * @param bool                     $weekly Make whole weeks.
     * @return array<string,mixed>|null Null when no day is left.
     */
    public static function days(array $range, array $bounds, $weekly = false) {
        $tz = wp_timezone();
        /** @var DateTimeImmutable $start */
        $start = $range['start'];
        $first = $start->setTimezone($tz)->format('Y-m-d');
        $last  = (new DateTimeImmutable('@' . max((int) $range['from'], (int) $range['to'] - 1)))->setTimezone($tz)->format('Y-m-d');
        if ($bounds['to'] !== '') {
            $last = min($last, $bounds['to']);
        }
        // All time starts at the first visit; search data may start earlier.
        if ($range['key'] === 'all' && $bounds['from'] !== '') {
            $first = $bounds['from'];
        }
        if ($last < $first) {
            return null;
        }
        $begin = new DateTimeImmutable($first, $tz);
        $end   = (new DateTimeImmutable($last, $tz))->modify('+1 day');
        $count = (int) $begin->diff($end)->days;
        if ($weekly && $count % 7) {
            $wide = $end->modify('-' . ($count + 7 - $count % 7) . ' days');
            if ($bounds['from'] === '' || $wide->format('Y-m-d') >= $bounds['from']) {
                $begin = $wide;
            } elseif ($count >= 7) {
                $begin = $end->modify('-' . ($count - $count % 7) . ' days');
            }
            $first = $begin->format('Y-m-d');
        }
        return array(
            'key'   => (string) $range['key'],
            'start' => $begin,
            'end'   => $end,
            'from'  => $begin->getTimestamp(),
            'to'    => $end->getTimestamp(),
            'day_from' => $first,
            'day_to'   => $last,
        );
    }

    /**
     * Days in a range from days().
     *
     * @param array<string,mixed> $days From days().
     * @return int
     */
    public static function length(array $days) {
        /** @var DateTimeImmutable $start */
        $start = $days['start'];
        return (int) $start->diff($days['end'])->days;
    }

    /**
     * A range from days() for answers: the first day's start to the end
     * of the last.
     *
     * @param array<string,mixed> $days From days().
     * @return array{key:string,from:string,to:string,timezone:string}
     */
    private static function range_out(array $days) {
        return SEOProStats_Query::range_out($days);
    }

    /**
     * Path ids the page box and the page filters select: null for every
     * page, else the ids (none: nothing matches). Other filters are named
     * in $ignored: they select visits, which search data has not.
     *
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters.
     * @param string                                                        $page    Page box.
     * @param string[]                                                      $ignored Filters left out (out).
     * @return int[]|null
     */
    public static function page_ids(array $filters, $page, array &$ignored) {
        $ids = null;
        foreach ($filters as $filter) {
            if ($filter['dimension'] !== 'page' || $filter['op'] === 'is_not') {
                $ignored[] = $filter['dimension'];
                continue;
            }
            $found = array_map('intval', SEOProStats_Query::dict_ids(SEOProStats_Schema::DICT_PATH, $filter));
            $ids   = $ids === null ? $found : array_values(array_intersect($ids, $found));
        }
        if ($page !== '') {
            $found = array_map('intval', SEOProStats_Query::dict_ids(SEOProStats_Schema::DICT_PATH, array(
                'dimension' => 'page',
                'op'        => strpos($page, '*') !== false ? 'matches' : 'is',
                'values'    => array($page),
            )));
            $ids = $ids === null ? $found : array_values(array_intersect($ids, $found));
        }
        return $ids === null ? null : array_values(array_filter(array_unique($ids)));
    }

    /**
     * Query ids a query box selects (* for any text).
     *
     * @param string $query Query.
     * @return int[]
     */
    private static function query_ids($query) {
        return array_values(array_filter(array_map('intval', SEOProStats_Query::dict_ids(SEOProStats_Schema::DICT_QUERY, array(
            'dimension' => 'search',
            'op'        => strpos($query, '*') !== false ? 'matches' : 'is',
            'values'    => array($query),
        )))));
    }

    /**
     * What a period reads: the table that answers it and its WHERE.
     * The site: gsc_totals; pages: gsc_pages by path_day; queries:
     * gsc_queries by query_day; both: gsc_pairs.
     *
     * @param int[]                    $engine  Engine codes (codes()).
     * @param array<string,mixed>|null $days    From days().
     * @param int[]|null               $pages   Path ids, or null for every page.
     * @param int[]|null               $queries Query ids, or null for every query.
     * @return array<string,mixed>|null Null when nothing can match.
     */
    private static function scope(array $engine, $days, $pages, $queries) {
        if (!$days || ($pages !== null && !$pages) || ($queries !== null && !$queries)) {
            return null;
        }
        $table = $pages === null ? ($queries === null ? 'gsc_totals' : 'gsc_queries') : ($queries === null ? 'gsc_pages' : 'gsc_pairs');
        $where = '';
        $args  = array();
        if ($pages !== null) {
            $where .= ' AND path_id IN (' . implode(', ', array_fill(0, count($pages), '%d')) . ')';
            $args   = array_merge($args, $pages);
        }
        if ($queries !== null) {
            $where .= ' AND query_id IN (' . implode(', ', array_fill(0, count($queries), '%d')) . ')';
            $args   = array_merge($args, $queries);
        }
        $key = self::key($table, $pages, $queries);
        $on  = self::engine_where($engine);
        return array(
            'engine'  => $on['args'],
            'table'   => $table,
            'pages'   => $pages,
            'queries' => $queries,
            'from'    => (string) $days['day_from'],
            'to'      => (string) $days['day_to'],
            // Placeholders and a fixed key name only; values are in args.
            'sql'     => "FROM %i FORCE INDEX (`$key`) WHERE {$on['sql']} AND day >= %s AND day <= %s$where",
            'args'    => array_merge($on['args'], array((string) $days['day_from'], (string) $days['day_to']), $args),
        );
    }

    /**
     * The key a read uses, so only the period's rows are read: by page
     * (path_day), by query (query_day), else the primary key (engine,
     * day, …). Without it, a long range over most of a table, such as
     * gsc_totals for a year, may be read in full.
     *
     * @param string     $table   Table name (gsc_*).
     * @param int[]|null $pages   Path ids, or null for every page.
     * @param int[]|null $queries Query ids, or null for every query.
     * @return string A key name of the table.
     */
    private static function key($table, $pages, $queries) {
        if ($table === 'gsc_totals') {
            return 'PRIMARY';
        }
        if ($pages !== null && $table !== 'gsc_queries') {
            return 'path_day';
        }
        if ($queries !== null && $table !== 'gsc_pages') {
            return 'query_day';
        }
        return 'PRIMARY';
    }

    /**
     * Clicks, impressions, CTR and position from summed columns.
     *
     * @param int|string $clicks      Clicks.
     * @param int|string $impressions Impressions.
     * @param int|string $pos_impr    Position × impressions × 100.
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    public static function metrics($clicks, $impressions, $pos_impr) {
        $clicks      = (int) $clicks;
        $impressions = (int) $impressions;
        return array(
            'clicks'      => $clicks,
            'impressions' => $impressions,
            'ctr'         => round(self::ctr($clicks, $impressions), 4),
            'position'    => $impressions ? round((float) $pos_impr / $impressions / 100, 1) : 0.0,
        );
    }

    /**
     * CTR from clicks and impressions, at most 1: Bing sometimes counts
     * more clicks than impressions for a rare query. Clicks and
     * impressions stay as imported; only the rate is capped.
     *
     * @param int|float|string $clicks      Clicks.
     * @param int|float|string $impressions Impressions.
     * @return float 0 without impressions.
     */
    public static function ctr($clicks, $impressions) {
        $impressions = (float) $impressions;
        return $impressions > 0 ? min(1.0, (float) $clicks / $impressions) : 0.0;
    }

    /**
     * The order of a sort: asc or desc as asked, else the sort's natural
     * one: lowest first for position (ASCENDING), most first (and newest
     * first for days) for the others. Search, Content and Audit share it.
     *
     * @param string $sort  The column sorted.
     * @param string $order asc, desc, or '' for the natural order.
     * @return string asc or desc.
     */
    public static function sort_order($sort, $order = '') {
        $order = strtolower((string) $order);
        if ($order === 'asc' || $order === 'desc') {
            return $order;
        }
        return in_array((string) $sort, self::ASCENDING, true) ? 'asc' : 'desc';
    }

    /**
     * Compare two rows' figures in an order, for usort(): null (a
     * position or CTR without impressions) last whichever the order.
     *
     * @param int|float|string|null $a     A's figure.
     * @param int|float|string|null $b     B's figure.
     * @param string                $order asc or desc.
     * @return int
     */
    public static function compare($a, $b, $order) {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }
        return $order === 'asc' ? $a <=> $b : $b <=> $a;
    }

    /**
     * A row's figure for a sort, from its sums (c clicks, i impressions,
     * p position × impressions × 100): null for CTR and position without
     * impressions, so they sort last.
     *
     * @param array<string,int|string> $sums c, i and p.
     * @param string                   $sort One of SORTS.
     * @return int|float|null
     */
    public static function sort_value(array $sums, $sort) {
        $clicks      = isset($sums['c']) ? (int) $sums['c'] : 0;
        $impressions = isset($sums['i']) ? (int) $sums['i'] : 0;
        if ($sort === 'clicks') {
            return $clicks;
        }
        if ($sort === 'ctr') {
            return $impressions ? self::ctr($clicks, $impressions) : null;
        }
        if ($sort === 'position') {
            return $impressions ? (float) (isset($sums['p']) ? $sums['p'] : 0) / $impressions : null;
        }
        return $impressions;
    }

    /**
     * Totals of a period.
     *
     * @param array<string,mixed>|null $scope From scope().
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    private static function totals($scope) {
        global $wpdb;
        if ($scope === null) {
            return self::metrics(0, 0, 0);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key (engine, day) or path_day / query_day; the scope holds only placeholders.
        $row = (array) $wpdb->get_row($wpdb->prepare("SELECT SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p {$scope['sql']}", array_merge(array(SEOProStats_Schema::table($scope['table'])), $scope['args'])), ARRAY_A);
        return self::metrics(isset($row['c']) ? $row['c'] : 0, isset($row['i']) ? $row['i'] : 0, isset($row['p']) ? $row['p'] : 0);
    }

    /**
     * Change from the comparison: relative for clicks, impressions and
     * CTR (null when then is 0); for position, now − then in places
     * (lower is better; null when either has no impressions).
     *
     * @param array<string,int|float> $now  From totals().
     * @param array<string,int|float> $then From totals().
     * @return array<string,float|null>
     */
    public static function change(array $now, array $then) {
        $keys            = array_flip(array('clicks', 'impressions', 'ctr', 'position'));
        $out             = SEOProStats_Query::change(array_intersect_key($now, $keys), array_intersect_key($then, $keys));
        $out['position'] = $now['impressions'] && $then['impressions'] ? round((float) $now['position'] - (float) $then['position'], 1) : null;
        return $out;
    }

    /**
     * Metrics per day, week or month of a period, every one present. A
     * week's figures are on its last day (weeks end on the weekday of
     * $anchor, the newest day with data); its point starts six days
     * before, or at the period's start.
     *
     * @param array<string,mixed>|null $scope  From scope().
     * @param array<string,mixed>      $days   From days().
     * @param string                   $grain  day, week or month.
     * @param string                   $anchor The last day of a week (Y-m-d), for weeks.
     * @return array<int,array<string,mixed>>
     */
    private static function series($scope, array $days, $grain, $anchor = '') {
        global $wpdb;
        $by = array();
        if ($scope !== null) {
            $bucket = $grain === 'month' ? "DATE_FORMAT(day, '%%Y-%%m-01')" : 'day';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as in totals(); $bucket is fixed SQL.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $bucket AS b, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p {$scope['sql']} GROUP BY b ORDER BY NULL", array_merge(array(SEOProStats_Schema::table($scope['table'])), $scope['args'])), ARRAY_A);
            $last = $grain === 'week' && $anchor !== '' ? (int) gmdate('w', (int) strtotime($anchor . ' 00:00:00 UTC')) : null;
            foreach ($rows as $row) {
                $key = (string) $row['b'];
                if ($last !== null) {
                    // A day goes to the week it is in (Combined adds daily engines to weekly ones).
                    $at  = (int) strtotime($key . ' 00:00:00 UTC');
                    $key = gmdate('Y-m-d', $at + ((($last - (int) gmdate('w', $at)) + 7) % 7) * DAY_IN_SECONDS);
                }
                foreach (array('c', 'i', 'p') as $col) {
                    $by[$key][$col] = (isset($by[$key][$col]) ? $by[$key][$col] : 0) + (int) $row[$col];
                }
            }
        }
        $out = array();
        /** @var DateTimeImmutable $at */
        $at = $days['start'];
        if ($grain === 'week') {
            // The first week's last day in the period: the anchor's weekday.
            $gap = $anchor !== '' ? (int) round(((new DateTimeImmutable($anchor, $at->getTimezone()))->getTimestamp() - $at->getTimestamp()) / DAY_IN_SECONDS) : 6;
            $at  = $at->modify('+' . ((($gap % 7) + 7) % 7) . ' days');
            for ($n = 0; $at < $days['end'] && $n < 1000; $at = $at->modify('+7 days'), $n++) {
                $key   = $at->format('Y-m-d');
                $row   = isset($by[$key]) ? $by[$key] : array('c' => 0, 'i' => 0, 'p' => 0);
                $from  = max($days['start'], $at->modify('-6 days'));
                $out[] = array('t' => $from->format('c')) + self::metrics($row['c'], $row['i'], $row['p']);
            }
            return $out;
        }
        if ($grain === 'month') {
            $at = $at->modify('first day of this month');
        }
        $step = $grain === 'month' ? '+1 month' : '+1 day';
        for ($n = 0; $at < $days['end'] && $n < 1000; $at = $at->modify($step), $n++) {
            $key   = $at->format('Y-m-d');
            $row   = isset($by[$key]) ? $by[$key] : array('c' => 0, 'i' => 0, 'p' => 0);
            $out[] = array('t' => ($n === 0 ? $days['start'] : $at)->format('c')) + self::metrics($row['c'], $row['i'], $row['p']);
        }
        return $out;
    }

    /**
     * Rows of one kind in the order asked, one more than the limit (to
     * tell whether there are more). Ties go most impressions, then most
     * clicks, first; CTR and position without impressions go last.
     *
     * @param array<string,mixed>|null $scope  From scope().
     * @param string                   $kind   One of KINDS.
     * @param int                      $limit  Rows.
     * @param int                      $offset Rows skipped.
     * @param array<string,int|float>  $totals From totals(), for shares.
     * @param array{sort:string,order:string} $by The sort (SORTS) and its order (asc or desc).
     * @return array<int,array<string,mixed>>
     */
    private static function rows($scope, $kind, $limit, $offset, array $totals, array $by) {
        global $wpdb;
        $read = self::row_source($scope, $kind);
        if ($read === null) {
            return array();
        }
        // A fixed expression and direction from SORT_SQL, never the request's text.
        $sort  = isset(self::SORT_SQL[$by['sort']]) ? $by['sort'] : self::SORTS[0];
        $dir   = $by['order'] === 'asc' ? 'ASC' : 'DESC';
        $order = (in_array($sort, array('ctr', 'position'), true) ? 'SUM(impressions) = 0, ' : '') . self::SORT_SQL[$sort] . ' ' . $dir;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key or path_day / query_day; $read and $order hold fixed SQL and placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT {$read['by']} AS v, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p {$read['sql']} GROUP BY {$read['by']} ORDER BY $order, i DESC, c DESC, v LIMIT %d OFFSET %d", array_merge($read['args'], array($limit + 1, $offset))), ARRAY_A);
        return self::label_rows($kind, $rows, $totals);
    }

    /**
     * Rows of the days kind: the chart's points (day, week or month, as
     * its grain), newest first unless sorted by a figure or oldest first,
     * so the table always agrees with the chart. Each has the days it
     * covers (from and to, both included, Y-m-d); one more than the limit,
     * to tell whether there are more.
     *
     * @param array<int,array<string,mixed>> $points From series().
     * @param array<string,mixed>|null       $days   From days().
     * @param string                         $grain  day, week or month.
     * @param string                         $anchor The last day of a week (Y-m-d), for weeks.
     * @param int                            $limit  Rows.
     * @param int                            $offset Rows skipped.
     * @param array<string,int|float>        $totals From totals(), for shares.
     * @param array{sort:string,order:string} $by    day or one of SORTS, and its order (asc or desc).
     * @return array<int,array<string,mixed>>
     */
    private static function day_rows(array $points, $days, $grain, $anchor, $limit, $offset, array $totals, array $by) {
        $last    = $days ? (string) $days['day_to'] : '';
        $weekday = static function ($day) {
            return (int) gmdate('w', (int) strtotime($day . ' 00:00:00 UTC'));
        };
        // As series(): without an anchor, weeks end six days after the period's start.
        $end     = $anchor !== '' ? $weekday($anchor) : ($days ? ($weekday((string) $days['day_from']) + 6) % 7 : 6);
        $out     = array();
        foreach ($points as $n => $point) {
            $from = substr((string) $point['t'], 0, 10);
            if ($grain === 'day') {
                $to = $from;
            } elseif ($grain === 'week') {
                // A week ends on the anchor's weekday (the first may start late, cut at the period's start).
                $to = gmdate('Y-m-d', (int) strtotime($from . ' 00:00:00 UTC') + (($end - $weekday($from) + 7) % 7) * DAY_IN_SECONDS);
            } elseif (isset($points[$n + 1])) {
                $to = gmdate('Y-m-d', (int) strtotime(substr((string) $points[$n + 1]['t'], 0, 10) . ' 00:00:00 UTC') - DAY_IN_SECONDS);
            } else {
                $to = $last;
            }
            if ($last !== '' && $to > $last) {
                $to = $last;
            }
            $row = $point;
            unset($row['t']);
            $row['share'] = $totals['clicks'] ? round($row['clicks'] / $totals['clicks'], 4) : 0.0;
            $out[]        = array(
                'id'    => $from,
                'value' => $from,
                'label' => $from === $to ? $from : $from . ' – ' . $to,
                'from'  => $from,
                'to'    => $to,
            ) + $row;
        }
        // Newest first; ties of a figure stay newest first.
        $out = array_reverse($out);
        if (isset(self::SORT_SQL[$by['sort']])) {
            $sort  = $by['sort'];
            $value = static function (array $row) use ($sort) {
                return $sort === 'ctr' || $sort === 'position' ? ($row['impressions'] ? $row[$sort] : null) : $row[$sort];
            };
            $at = array_flip(array_column($out, 'id'));
            usort($out, static function ($a, $b) use ($value, $by, $at) {
                return self::compare($value($a), $value($b), $by['order']) ?: $at[$a['id']] <=> $at[$b['id']];
            });
        } elseif ($by['order'] === 'asc') {
            $out = array_reverse($out);
        }
        return array_slice($out, max(0, $offset), $limit + 1);
    }

    /**
     * Where rows of a kind are read: the table, the column grouped by, and
     * the WHERE. Queries of pages come from pairs (path_day), pages of
     * queries from pairs (query_day); countries and devices exist only
     * for the site.
     *
     * @param array<string,mixed>|null $scope From scope().
     * @param string                   $kind  One of KINDS.
     * @return array{by:string,sql:string,args:array<int,mixed>}|null
     */
    private static function row_source($scope, $kind) {
        if ($scope === null) {
            return null;
        }
        $pages   = $scope['pages'];
        $queries = $scope['queries'];
        if (in_array($kind, array('countries', 'devices', 'appearance'), true)) {
            // The site only, and Google only: Bing gives no countries, devices or search appearances.
            if ($pages !== null || $queries !== null || $scope['engine'] !== array(SEOProStats_Schema::ENGINE_GOOGLE)) {
                return null;
            }
            $table = $kind === 'appearance' ? 'gsc_appearance' : 'gsc_totals';
            $by    = $kind === 'appearance' ? 'appearance_id' : ($kind === 'countries' ? 'country' : 'device');
        } elseif ($kind === 'queries') {
            $table = $pages === null ? 'gsc_queries' : 'gsc_pairs';
            $by    = 'query_id';
        } else {
            $table = $queries === null ? 'gsc_pages' : 'gsc_pairs';
            $by    = 'path_id';
        }
        $re = self::scope_on($scope, $table);
        return array('by' => $by, 'sql' => $re['sql'], 'args' => $re['args']);
    }

    /**
     * The scope's WHERE on another table (same days, pages and queries).
     *
     * @param array<string,mixed> $scope From scope().
     * @param string              $table Table name.
     * @return array{sql:string,args:array<int,mixed>}
     */
    private static function scope_on(array $scope, $table) {
        $where = '';
        $on    = self::engine_where($scope['engine']);
        $args  = array_merge(array(SEOProStats_Schema::table($table)), $on['args'], array($scope['from'], $scope['to']));
        if ($scope['pages'] !== null) {
            $where .= ' AND path_id IN (' . implode(', ', array_fill(0, count($scope['pages']), '%d')) . ')';
            $args   = array_merge($args, $scope['pages']);
        }
        if ($scope['queries'] !== null) {
            $where .= ' AND query_id IN (' . implode(', ', array_fill(0, count($scope['queries']), '%d')) . ')';
            $args   = array_merge($args, $scope['queries']);
        }
        $key = self::key($table, $scope['pages'], $scope['queries']);
        return array('sql' => "FROM %i FORCE INDEX (`$key`) WHERE {$on['sql']} AND day >= %s AND day <= %s$where", 'args' => $args);
    }

    /**
     * Rows as the answer gives them: value, label, metrics and share of
     * clicks; pages with their address and post.
     *
     * @param string                          $kind   One of KINDS.
     * @param array<int,array<string,mixed>>  $rows   v, c, i, p.
     * @param array<string,int|float>         $totals For shares.
     * @return array<int,array<string,mixed>>
     */
    private static function label_rows($kind, array $rows, array $totals) {
        $text = in_array($kind, array('queries', 'pages', 'appearance'), true) ? SEOProStats_Query::texts(array_map('intval', array_column($rows, 'v'))) : array();
        $out  = array();
        foreach ($rows as $row) {
            $id = (string) $row['v'];
            if ($kind === 'countries') {
                $value = self::country($id);
                $label = $value === '' ? __('Unknown', 'seoprostats') : $value;
            } elseif ($kind === 'devices') {
                $value = isset(self::DEVICES[(int) $id]) ? self::DEVICES[(int) $id] : 'unknown';
                $label = SEOProStats_Query::device_labels()[$value];
            } else {
                $value = isset($text[(int) $id]) ? $text[(int) $id] : '';
                $label = $value;
            }
            $item = array('id' => $id, 'value' => $value, 'label' => $label) + self::metrics($row['c'], $row['i'], $row['p']);
            $item['share'] = $totals['clicks'] ? round($item['clicks'] / $totals['clicks'], 4) : 0.0;
            if ($kind === 'pages') {
                $item += SEOProStats_Clicks::page_info($value) ?: array('path' => $value, 'url' => '', 'post_id' => 0, 'edit_url' => null);
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * A Search Console country (alpha-3) as visits store it (alpha-2,
     * upper case); '' when unknown.
     *
     * @param string $code Alpha-3.
     * @return string
     */
    public static function country($code) {
        static $map = null;
        if ($map === null) {
            $map = array();
            foreach (explode(',', self::COUNTRIES) as $pair) {
                $map[substr($pair, 0, 3)] = strtoupper(substr($pair, 4, 2));
            }
        }
        $code = strtolower((string) $code);
        return isset($map[$code]) ? $map[$code] : '';
    }

    /**
     * Give the rows shown their figures in the comparison period and the
     * change: relative for clicks and impressions, places for position.
     *
     * @param array<string,mixed>|null       $scope From scope() for the other period.
     * @param string                         $kind  One of KINDS.
     * @param array<int,array<string,mixed>> $rows  Rows.
     * @return array<int,array<string,mixed>>
     */
    private static function with_compare($scope, $kind, array $rows) {
        global $wpdb;
        // Days do not pair one to one across periods; the totals carry the comparison.
        if (!$rows || $kind === 'days') {
            return $rows;
        }
        $before = array();
        $read   = self::row_source($scope, $kind);
        if ($read !== null) {
            $ids  = array_column($rows, 'id');
            $in   = implode(', ', array_fill(0, count($ids), $kind === 'countries' ? '%s' : '%d'));
            $args = array_merge($read['args'], $ids);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as in rows(), for the rows shown only.
            $found = (array) $wpdb->get_results($wpdb->prepare("SELECT {$read['by']} AS v, SUM(clicks) AS c, SUM(impressions) AS i, SUM(pos_impr) AS p {$read['sql']} AND {$read['by']} IN ($in) GROUP BY {$read['by']} ORDER BY NULL", $args), ARRAY_A);
            foreach ($found as $row) {
                $before[(string) $row['v']] = self::metrics($row['c'], $row['i'], $row['p']);
            }
        }
        foreach ($rows as &$row) {
            $then           = isset($before[$row['id']]) ? $before[$row['id']] : self::metrics(0, 0, 0);
            $row['compare'] = $then + array('change' => self::change($row, $then));
        }
        unset($row);
        return $rows;
    }
}
