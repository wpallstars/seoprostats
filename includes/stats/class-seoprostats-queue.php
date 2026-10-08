<?php
/**
 * The decision queue (Search → Plan): one ranked list of things to do,
 * made from what Opportunities finds, each with why it is listed and how
 * its score is made:
 *
 *     score = potential clicks per 28 days × value × confidence ÷ effort
 *
 * - potential clicks: the opportunity's (striking: clicks it could gain
 *   in the top three; low CTR: clicks missed; decay: clicks lost;
 *   missing: impressions × the site's expected CTR at its position ×
 *   MISSING_SHARE; overlap: the clicks the query's pages would have with
 *   the best of their CTRs, so items where no page does better are left
 *   out; audit: the page's expected clicks at its position × the
 *   finding's share, SEOProStats_Audit::SHARE; links: the page's
 *   expected clicks at its position × LINKS_SHARE, for a missing link the
 *   impressions of the searches on the page that should link), scaled to
 *   28 days; index: a typical shown page's clicks per 28 days ×
 *   INDEX_SHARE (SEOProStats_Indexation);
 * - value: how well visits from search to the page convert against the
 *   site (the Content report's goal), smoothed toward the site's rate
 *   with SMOOTH visits; at least 1 (a page with no goal data is 1) and at
 *   most MAX_VALUE;
 * - confidence: the kind's own × √(impressions per 28 days ÷
 *   FULL_IMPRESSIONS), at most the kind's own (index: the kind's own);
 * - effort: the kind's (an audit finding's), unless a person set another.
 *
 * Audit items are one per page and finding (the key's query is the
 * finding), on the pages with most impressions (SEOProStats_Audit).
 * Links items (SEOProStats_Links) are one per page and list: orphan,
 * converting, or missing (on the page the link should go to; the key's
 * query names the page that should link). Index items
 * (SEOProStats_Indexation) are one per page or sitemap address search
 * has not shown lately (the key's query is the list). A losing page with
 * content facts gets a refresh item (SEOProStats_Refresh: update, leave,
 * protect or merge; the key's query is the proposal) in place of its
 * decay item: potential clicks those lost × the proposal's share; done on
 * leave opens no experiment, as nothing changes.
 *
 * Items are worked out when the list is read; only those a person or
 * agent acted on (accepted, done, dismissed, or given an effort or note)
 * are stored, by an 8-byte key of kind, engine, page and query. Pages
 * with a running experiment are left out of new items: a second change
 * would spoil its measurement. Done opens an experiment on the item's
 * page with the kind's measure, and the item shows its result. Dismissed
 * items stay hidden for HIDE_DAYS days, then come back if still found.
 *
 * Reads: the Opportunities and Content reports (cached), the running
 * experiments by key status_review, and the queue by its unique ikey and
 * by status_updated. Design: docs/seo-loop.md → Decision queue.
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

final class SEOProStats_Queue {

    /** Kinds of item: code (the kind column) => name. Codes never change meaning. */
    const KINDS = array(
        1 => 'ctr',
        2 => 'missing',
        3 => 'striking',
        4 => 'decay',
        5 => 'overlap',
        6 => 'audit',
        7 => 'links',
        8 => 'index',
        9 => 'refresh',
    );

    /** States stored: code => name. 0 (new) is stored only with an effort or note. */
    const STATUSES = array(
        0 => 'new',
        1 => 'accepted',
        2 => 'done',
        3 => 'dismissed',
    );

    /** States a list can ask for: open is new and accepted. */
    const FILTERS = array('open', 'new', 'accepted', 'done', 'dismissed', 'all');

    /** Actions on an item. */
    const ACTIONS = array('accept', 'done', 'dismiss', 'restore', 'effort', 'note');

    /** Effort by kind (1 least), and the most a person can set. */
    const EFFORT     = array('ctr' => 1, 'missing' => 2, 'striking' => 2, 'decay' => 3, 'overlap' => 3, 'audit' => 1, 'links' => 1, 'index' => 2, 'refresh' => 3);
    const MAX_EFFORT = 5;

    /** Effort of audit findings and links and indexation lists other than their kind's. */
    const AUDIT_EFFORT = array('thin' => 3);
    const LINKS_EFFORT = array('converting' => 2);
    const INDEX_EFFORT = array('sitemap' => 1);

    /**
     * The kind's own confidence, before the impressions are weighed;
     * indexation items have no impressions, so theirs is not weighed.
     */
    const CONFIDENCE = array('decay' => 0.8, 'ctr' => 0.7, 'striking' => 0.6, 'missing' => 0.5, 'overlap' => 0.4, 'audit' => 0.5, 'links' => 0.4, 'index' => 0.3, 'refresh' => 0.8);

    /** The measure of the experiment done opens, by kind. */
    const METRIC = array('ctr' => 'ctr', 'missing' => 'clicks', 'striking' => 'position', 'decay' => 'clicks', 'overlap' => 'clicks', 'audit' => 'clicks', 'links' => 'clicks', 'index' => 'impressions', 'refresh' => 'clicks');

    /** Refresh proposals done without an experiment: nothing on the page changes. */
    const NO_CHANGE = array('leave');

    /** Share of a page's expected clicks links put at stake, by links list. */
    const LINKS_SHARE = array('missing' => 0.2, 'orphans' => 0.1, 'converting' => 0.1);

    /**
     * Share of a typical page's clicks (SEOProStats_Indexation's typical)
     * a page search does not show could earn, by indexation list.
     */
    const INDEX_SHARE = array('pages' => 0.5, 'sitemap' => 0.2);

    /** Audit findings measured otherwise than the audit kind's. */
    const AUDIT_METRIC = array(
        'noindex'               => 'impressions',
        'canonical'             => 'impressions',
        'title_missing'         => 'ctr',
        'title_duplicate'       => 'ctr',
        'title_long'            => 'ctr',
        'description_missing'   => 'ctr',
        'description_duplicate' => 'ctr',
        'description_long'      => 'ctr',
    );

    /** Potential clicks are given per this many days. */
    const SCALE_DAYS = 28;

    /** Share of the expected clicks a page earns by covering a missing query. */
    const MISSING_SHARE = 0.3;

    /** Visits that smooth a page's conversion rate toward the site's. */
    const SMOOTH = 20;

    /** Most value a page's conversions give. */
    const MAX_VALUE = 5;

    /** Impressions per 28 days that give a kind its whole confidence. */
    const FULL_IMPRESSIONS = 1000;

    /** Days a dismissed item stays hidden. */
    const HIDE_DAYS = 90;

    /** Opportunities read per kind, best first. */
    const PER_KIND = 100;

    /** Pages whose visits and conversions are read (most visits from search first). */
    const VALUE_PAGES = 1000;

    /** Accepted and done items read each that are no longer found, newest first. */
    const KEPT = 200;

    /** Items listed: default and most. */
    const LIMIT     = 50;
    const MAX_LIMIT = 200;

    /** Most bytes of a note. */
    const NOTE_BYTES = 190;

    // ------------------------------------------------------------------
    // Reading.

    /**
     * The ranked list.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request() (range, filters, limit, offset).
     * @param string              $engine google or bing.
     * @param string              $status One of FILTERS.
     * @param string              $goal   Goal id for value; '' for the first goal.
     * @param string              $kind   Only items of this kind (one of KINDS); '' for all.
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $status = 'open', $goal = '', $kind = '') {
        $status = $status === '' ? 'open' : (string) $status;
        $kind   = (string) $kind;
        if (!in_array($status, self::FILTERS, true)) {
            /* translators: %s: list of states */
            return self::error('seoprostats_queue_status', sprintf(__('The status is one of: %s.', 'seoprostats'), implode(', ', self::FILTERS)));
        }
        if ($kind !== '' && !in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of kinds */
            return self::error('seoprostats_queue_kind', sprintf(__('The kind is one of: %s.', 'seoprostats'), implode(', ', self::KINDS)));
        }
        $built  = self::build($req, $engine, $goal);
        $left   = 0;
        $items  = self::with_states($built['items'], $built['answer']['engine'], $built['running'], $left);
        if ($kind !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($kind) {
                return $item['kind'] === $kind;
            }));
        }
        $built['answer']['left_out'] = $left;
        $built['answer']['kind']     = $kind === '' ? null : $kind;
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($items as $item) {
            ++$counts[$item['status']];
        }
        $items = array_values(array_filter($items, static function ($item) use ($status) {
            return $status === 'all' || $item['status'] === $status || ($status === 'open' && in_array($item['status'], array('new', 'accepted'), true));
        }));
        usort($items, static function ($a, $b) {
            return array($b['score'], $b['parts']['clicks'], $a['key']) <=> array($a['score'], $a['parts']['clicks'], $b['key']);
        });
        $limit  = max(1, min(self::MAX_LIMIT, isset($req['limit']) ? (int) $req['limit'] : self::LIMIT));
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $shown  = array_slice($items, $offset, $limit);
        foreach ($shown as &$item) {
            $item = self::finish($item);
        }
        unset($item);

        return $built['answer'] + array(
            'status' => $status,
            'counts' => $counts,
            'items'  => $shown,
            'total'  => count($items),
            'more'   => $offset + $limit < count($items),
        );
    }

    /**
     * The items found now, scored, with the answer's header; no states.
     *
     * @param array<string,mixed> $req    From SEOProStats_Query::request().
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id; '' for the first goal.
     * @return array{answer:array<string,mixed>,items:array<string,array<string,mixed>>,running:array<int,int>}
     */
    private static function build(array $req, $engine, $goal) {
        require_once __DIR__ . '/class-seoprostats-search.php';
        require_once __DIR__ . '/class-seoprostats-opportunities.php';
        require_once __DIR__ . '/class-seoprostats-content.php';
        require_once __DIR__ . '/class-seoprostats-experiments.php';
        require_once __DIR__ . '/class-seoprostats-dict.php';
        require_once __DIR__ . '/class-seoprostats-clicks.php';
        require_once __DIR__ . '/class-seoprostats-audit.php';
        require_once __DIR__ . '/class-seoprostats-links.php';
        require_once __DIR__ . '/class-seoprostats-indexation.php';
        require_once __DIR__ . '/class-seoprostats-refresh.php';
        $engine = SEOProStats_Search::engine_name($engine);
        $ask    = array_merge($req, array('limit' => self::PER_KIND, 'offset' => 0));

        $found = array();
        foreach (SEOProStats_Opportunities::KINDS as $kind) {
            $found[$kind] = SEOProStats_Opportunities::report($ask, $kind, $engine);
        }
        // Audit findings on the pages with most impressions.
        $audit = SEOProStats_Audit::report($ask, $engine);
        $audit = is_wp_error($audit) ? array() : $audit['rows'];
        // Internal links: each list's first rows (one cached report).
        $links = array();
        foreach (SEOProStats_Links::KINDS as $list) {
            $answer       = SEOProStats_Links::report($ask, $engine, $list, $goal);
            $links[$list] = is_wp_error($answer) ? array() : $answer['rows'];
        }
        // Indexation: each list's first rows (one cached report), with what a page shown in search earns here.
        $index   = array();
        $typical = 0.0;
        $through = '';
        foreach (SEOProStats_Indexation::KINDS as $list) {
            $answer = SEOProStats_Indexation::report($ask, $engine, $list);
            if (!is_wp_error($answer)) {
                $index[$list] = $answer['rows'];
                $typical      = (float) $answer['typical'];
                $through      = (string) $answer['through'];
            }
        }
        $head  = $found['striking'];
        $days  = (int) $head['days'];
        $curve = isset($head['curve']['ctr']) && is_array($head['curve']['ctr']) ? $head['curve']['ctr'] : SEOProStats_Opportunities::DEFAULT_CURVE;
        $value = self::values(array_merge($req, array('limit' => self::VALUE_PAGES, 'offset' => 0, 'compare' => 'none')), $engine, $goal);

        $running = SEOProStats_Experiments::running_pages();
        // Losing pages with content facts get a refresh proposal in place of their decay item.
        $facts   = SEOProStats_Refresh::facts(array_column($found['decay']['rows'], 'path_id'));
        $items   = array();
        if ($days > 0) {
            foreach ($found as $kind => $answer) {
                foreach ($answer['rows'] as $row) {
                    if ($kind === 'overlap' && (int) $row['potential'] < 1) {
                        // No page's CTR is better than the others': nothing to win by choosing one.
                        continue;
                    }
                    $item = $kind === 'decay' && isset($facts[(int) $row['path_id']])
                        ? self::refresh_item($engine, $row, $facts[(int) $row['path_id']], $days, $value)
                        : self::item($kind, $engine, $row, $days, $curve, $value);
                    if (!isset($items[$item['key']])) {
                        $items[$item['key']] = $item;
                    }
                }
            }
            foreach ($audit as $row) {
                foreach ((array) $row['findings'] as $finding) {
                    $item = self::audit_item($engine, $row, (string) $finding, $days, $curve, $value);
                    if ($item && !isset($items[$item['key']])) {
                        $items[$item['key']] = $item;
                    }
                }
            }
            foreach ($links as $list => $rows) {
                foreach ($rows as $row) {
                    $item = self::links_item($engine, $row, (string) $list, $days, $curve, $value);
                    if ($item && !isset($items[$item['key']])) {
                        $items[$item['key']] = $item;
                    }
                }
            }
            foreach ($index as $list => $rows) {
                foreach ($rows as $row) {
                    $item = self::index_item($engine, $row, (string) $list, $typical, $through, $value);
                    if ($item && !isset($items[$item['key']])) {
                        $items[$item['key']] = $item;
                    }
                }
            }
        }

        return array(
            'answer'  => array(
                'engine'    => $engine,
                'engines'   => $head['engines'],
                'range'     => $head['range'],
                'days'      => $days,
                'cut'       => (bool) $head['cut'],
                'through'   => $head['through'],
                'connected' => (bool) $head['connected'],
                'ignored'   => $head['ignored'],
                'goal'      => $value['goal'],
                'goals'     => $value['goals'],
                'site_rate' => $value['site'],
                'rules'     => array(
                    'scale_days'       => self::SCALE_DAYS,
                    'effort'           => self::EFFORT,
                    'audit_effort'     => self::AUDIT_EFFORT,
                    'links_effort'     => self::LINKS_EFFORT,
                    'links_share'      => self::LINKS_SHARE,
                    'index_effort'     => self::INDEX_EFFORT,
                    'index_share'      => self::INDEX_SHARE,
                    'refresh_effort'   => SEOProStats_Refresh::EFFORT,
                    'refresh_share'    => SEOProStats_Refresh::SHARE,
                    'refresh'          => array(
                        'old_days'            => SEOProStats_Refresh::OLD_DAYS,
                        'protect_value'       => SEOProStats_Refresh::PROTECT_VALUE,
                        'protect_conversions' => SEOProStats_Refresh::PROTECT_CONVERSIONS,
                    ),
                    'confidence'       => self::CONFIDENCE,
                    'full_impressions' => self::FULL_IMPRESSIONS,
                    'missing_share'    => self::MISSING_SHARE,
                    'smooth_visits'    => self::SMOOTH,
                    'max_value'        => self::MAX_VALUE,
                    'hide_days'        => self::HIDE_DAYS,
                    'per_kind'         => self::PER_KIND,
                ),
                'left_out'  => 0,
            ),
            'items'   => $items,
            'running' => $running,
        );
    }

    /**
     * Value by page: visits from search and conversions of the goal, from
     * the Content report (most visits first, VALUE_PAGES pages).
     *
     * @param array<string,mixed> $req    Request.
     * @param string              $engine Engine name.
     * @param string              $goal   Goal id; '' for the first goal.
     * @return array{goal:array{id:string,name:string}|null,goals:array<int,array{id:string,name:string}>,site:float|null,pages:array<int,array{visits:int,conversions:int}>}
     */
    private static function values(array $req, $engine, $goal) {
        $content = SEOProStats_Content::report($req, 'visits', $goal, $engine);
        $out     = array('goal' => null, 'goals' => array(), 'site' => null, 'pages' => array());
        foreach (isset($content['goals']) ? (array) $content['goals'] : array() as $one) {
            $out['goals'][] = array('id' => (string) $one['id'], 'name' => (string) $one['name']);
        }
        if (empty($content['goal'])) {
            return $out;
        }
        $out['goal'] = array('id' => (string) $content['goal']['id'], 'name' => (string) $content['goal']['name']);
        $visits      = (int) $content['totals']['visits'];
        $out['site'] = $visits ? round((int) $content['totals']['conversions'] / $visits, 4) : null;
        foreach ($content['rows'] as $row) {
            $out['pages'][(int) $row['path_id']] = array('visits' => (int) $row['visits'], 'conversions' => (int) $row['conversions']);
        }
        return $out;
    }

    /**
     * One item from an opportunity row: its key, page, query, figures, why
     * and score parts.
     *
     * @param string              $kind   Kind name.
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Opportunity row.
     * @param int                 $days   Days of the period.
     * @param array<int,float>    $curve  Expected CTR by position.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>
     */
    private static function item($kind, $engine, array $row, $days, array $curve, array $value) {
        $scale = self::SCALE_DAYS / max(1, (int) $days);
        $query = isset($row['query']) ? (string) $row['query'] : '';
        $impr  = (int) $row['impressions'];
        if ($kind === 'decay') {
            $impr    = max($impr, (int) $row['compare']['impressions']);
            $clicks  = (int) $row['lost'];
            $figures = array(
                'clicks'        => (int) $row['clicks'],
                'then_clicks'   => (int) $row['compare']['clicks'],
                'lost'          => (int) $row['lost'],
                'impressions'   => (int) $row['impressions'],
                'position'      => $row['impressions'] ? $row['position'] : null,
                'then_position' => $row['compare']['position'],
                'cause'         => (string) $row['cause'],
            );
        } else {
            $figures = array(
                'clicks'      => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'ctr'         => $row['ctr'],
                'position'    => $row['position'],
            );
            if ($kind === 'overlap') {
                $figures['potential'] = (int) $row['potential'];
                $figures['switched']  = (bool) $row['switched'];
                $figures['pages']     = array_map(static function ($page) {
                    return array(
                        'path_id'     => (int) $page['path_id'],
                        'path'        => (string) $page['path'],
                        'clicks'      => (int) $page['clicks'],
                        'impressions' => (int) $page['impressions'],
                        'position'    => $page['position'],
                        'share'       => $page['share'],
                    );
                }, (array) $row['pages']);
                $clicks = (int) $row['potential'];
            } elseif ($kind === 'missing') {
                $place                   = max(1, min(20, (int) round((float) $row['position'])));
                $figures['expected_ctr'] = round((float) $curve[$place], 4);
                $figures['match']        = (string) $row['match'];
                $figures['missing']      = array_values((array) $row['missing']);
                $figures['question']     = (bool) $row['question'];
                $clicks                  = $impr * (float) $curve[$place] * self::MISSING_SHARE;
            } else {
                $figures['expected_ctr'] = $row['expected_ctr'];
                $figures['potential']    = (int) $row['potential'];
                $clicks                  = (int) $row['potential'];
            }
        }
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE[$kind] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::EFFORT[$kind],
        );
        return array(
            'key'      => self::key($kind, $engine, (string) $row['path'], $query),
            'kind'     => $kind,
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => $kind === 'decay' ? null : $query,
            'finding'  => null,
            'why'      => self::why($kind, $row, $figures),
            'todo'     => self::todo($kind),
            'figures'  => $figures,
            'metric'   => self::METRIC[$kind],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from an audit finding on a page: the page's expected
     * clicks at its position (the site's curve) × the finding's share
     * (SEOProStats_Audit::SHARE). Null without potential clicks.
     *
     * @param string              $engine  Engine name.
     * @param array<string,mixed> $row     Audit row.
     * @param string              $finding Finding.
     * @param int                 $days    Days of the period.
     * @param array<int,float>    $curve   Expected CTR by position.
     * @param array<string,mixed> $value   From values().
     * @return array<string,mixed>|null
     */
    private static function audit_item($engine, array $row, $finding, $days, array $curve, array $value) {
        $impr = (int) $row['impressions'];
        if ($impr < 1 || $row['position'] === null) {
            return null;
        }
        $scale    = self::SCALE_DAYS / max(1, (int) $days);
        $place    = max(1, min(20, (int) round((float) $row['position'])));
        $expected = $impr * (float) $curve[$place];
        $clicks   = $expected * SEOProStats_Audit::share(array($finding));
        if ($clicks * $scale < 1) {
            return null;
        }
        $figures = array(
            'finding'      => $finding,
            'clicks'       => (int) $row['clicks'],
            'impressions'  => $impr,
            'ctr'          => $row['ctr'],
            'position'     => $row['position'],
            'expected_ctr' => round((float) $curve[$place], 4),
            'share'        => SEOProStats_Audit::share(array($finding)),
            'facts'        => (array) $row['facts'],
            // The other pages with the same title or description.
            'same'         => $finding === 'title_duplicate' ? array_values((array) $row['same_title']) : ($finding === 'description_duplicate' ? array_values((array) $row['same_description']) : array()),
        );
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE['audit'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::effort_of('audit', $finding),
        );
        /* translators: 1: what the content audit found, 2: impressions, 3: average position */
        $why = sprintf(__('The content audit found %1$s on a page with %2$s impressions at position %3$s.', 'seoprostats'), SEOProStats_Audit::phrase($finding, $row), number_format_i18n($impr), number_format_i18n((float) $row['position'], 1));
        return array(
            'key'      => self::key('audit', $engine, (string) $row['path'], $finding),
            'kind'     => 'audit',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => $finding,
            'why'      => $why,
            'todo'     => SEOProStats_Audit::todo(array($finding)),
            'figures'  => $figures,
            'metric'   => isset(self::AUDIT_METRIC[$finding]) ? self::AUDIT_METRIC[$finding] : self::METRIC['audit'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from an internal links row (SEOProStats_Links): an orphan
     * page, a converting page with few links in, or a missing link (on the
     * page it should go to). Potential clicks: the page's impressions (a
     * missing link: those of the searches on the page that should link) ×
     * the site's expected CTR at the page's position × LINKS_SHARE. Null
     * without potential clicks.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Links row.
     * @param string              $list   orphans, converting or missing.
     * @param int                 $days   Days of the period.
     * @param array<int,float>    $curve  Expected CTR by position.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>|null
     */
    private static function links_item($engine, array $row, $list, $days, array $curve, array $value) {
        $missing = $list === 'missing';
        $page    = $missing ? (array) $row['to'] : $row;
        $impr    = (int) $row['impressions'];
        if ($impr < 1 || (int) $page['impressions'] < 1 || !isset(self::LINKS_SHARE[$list])) {
            return null;
        }
        $scale  = self::SCALE_DAYS / max(1, (int) $days);
        $place  = max(1, min(20, (int) round((float) $page['position'])));
        $clicks = $impr * (float) $curve[$place] * self::LINKS_SHARE[$list];
        if ($clicks * $scale < 1) {
            return null;
        }
        $figures = array(
            'list'         => $list,
            'clicks'       => (int) $page['clicks'],
            'impressions'  => (int) $page['impressions'],
            'ctr'          => $page['ctr'],
            'position'     => $page['position'],
            'expected_ctr' => round((float) $curve[$place], 4),
            'share'        => self::LINKS_SHARE[$list],
        );
        $num = static function ($n) {
            return number_format_i18n((int) $n);
        };
        if ($missing) {
            $searches = array_map(static function ($query) {
                return '“' . (string) $query['query'] . '”';
            }, (array) $row['queries']);
            $figures += array(
                'link_from'        => array('path_id' => (int) $row['path_id'], 'path' => (string) $row['path'], 'url' => (string) $row['url']),
                'from_impressions' => $impr,
                'from_position'    => $row['position'],
                'queries'          => array_values((array) $row['queries']),
                'query_count'      => (int) $row['query_count'],
            );
            /* translators: 1: page path that should link, 2: impressions, 3: searches, 4: page path it should link to */
            $why  = sprintf(__('%1$s shows for searches this page gets most clicks for (%2$s impressions: %3$s), but does not link to %4$s.', 'seoprostats'), (string) $row['path'], $num($impr), implode(', ', $searches), (string) $page['path']);
            /* translators: 1: page path it should link to, 2: page path that should link */
            $todo = sprintf(__('Link to %1$s from %2$s, in the words of the searches.', 'seoprostats'), (string) $page['path'], (string) $row['path']);
        } else {
            $figures += array(
                'links_in'    => (int) $row['links_in'],
                'from'        => array_values((array) $row['from']),
                'visits'      => (int) $row['visits'],
                'conversions' => $row['conversions'],
            );
            if ($list === 'orphans') {
                /* translators: 1: impressions, 2: average position */
                $why  = sprintf(__('No other page links to it, though it has %1$s impressions at position %2$s.', 'seoprostats'), $num($impr), number_format_i18n((float) $page['position'], 1));
                $todo = __('Link to it from related pages, in the words of its searches.', 'seoprostats');
            } else {
                $links = (int) $row['links_in'];
                /* translators: 1: conversions, 2: number of pages linking to it */
                $why  = sprintf(_n('Its visits from search reached the goal %1$s times, but only %2$s page links to it.', 'Its visits from search reached the goal %1$s times, but only %2$s pages link to it.', $links, 'seoprostats'), $num($row['conversions']), $num($links));
                $todo = __('Link to it from more related pages.', 'seoprostats');
            }
        }
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round(self::worth((int) $page['path_id'], $value), 2),
            'confidence' => round(self::CONFIDENCE['links'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::effort_of('links', $list),
        );
        return array(
            'key'      => self::key('links', $engine, (string) $page['path'], $missing ? 'missing ' . (string) $row['path'] : $list),
            'kind'     => 'links',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $page['path_id'],
            'path'     => (string) $page['path'],
            'url'      => (string) $page['url'],
            'post_id'  => (int) $page['post_id'],
            'edit_url' => isset($page['edit_url']) ? $page['edit_url'] : null,
            'query'    => null,
            'finding'  => $list,
            'why'      => $why,
            'todo'     => $todo,
            'figures'  => $figures,
            'metric'   => self::METRIC['links'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One item from an indexation row (SEOProStats_Indexation): a page or
     * sitemap address search has not shown lately. Potential clicks: what
     * a page shown in search earns here per 28 days (the report's typical)
     * × INDEX_SHARE; confidence is the kind's own, as there are no
     * impressions to weigh. Null without potential clicks.
     *
     * @param string              $engine  Engine name.
     * @param array<string,mixed> $row     Indexation row.
     * @param string              $list    pages or sitemap.
     * @param float               $typical A shown page's clicks per 28 days.
     * @param string              $through The newest search day.
     * @param array<string,mixed> $value   From values().
     * @return array<string,mixed>|null
     */
    private static function index_item($engine, array $row, $list, $typical, $through, array $value) {
        if (!isset(self::INDEX_SHARE[$list])) {
            return null;
        }
        $clicks = (float) $typical * self::INDEX_SHARE[$list];
        if ($clicks < 1) {
            return null;
        }
        $figures = array(
            'list'            => $list,
            'state'           => (string) $row['state'],
            'last_impression' => $row['last_impression'],
            'age'             => (int) $row['age'],
            'typical'         => (float) $typical,
            'share'           => self::INDEX_SHARE[$list],
        );
        foreach (array('published', 'words', 'links_in', 'first_seen', 'source') as $field) {
            if (array_key_exists($field, $row)) {
                $figures[$field] = $row[$field];
            }
        }
        $parts = array(
            'clicks'     => round($clicks, 1),
            'value'      => round(self::worth((int) $row['path_id'], $value), 2),
            'confidence' => self::CONFIDENCE['index'],
            'effort'     => self::effort_of('index', $list),
        );
        return array(
            'key'      => self::key('index', $engine, (string) $row['path'], $list),
            'kind'     => 'index',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => (int) $row['path_id'],
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => $list,
            'why'      => SEOProStats_Indexation::why($list, $row, $through),
            'todo'     => SEOProStats_Indexation::todo($list),
            'figures'  => $figures,
            'metric'   => self::METRIC['index'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * One refresh item from a losing page with content facts
     * (SEOProStats_Refresh): update, leave, protect or merge. Potential
     * clicks: those lost × the proposal's share; confidence the kind's,
     * weighed by impressions as decay's.
     *
     * @param string              $engine Engine name.
     * @param array<string,mixed> $row    Decay row.
     * @param array{post_id:int,modified:int,published:int,words:int,links_in:int} $facts The page's content facts (SEOProStats_Refresh::facts()).
     * @param int                 $days   Days of the period.
     * @param array<string,mixed> $value  From values().
     * @return array<string,mixed>
     */
    private static function refresh_item($engine, array $row, array $facts, $days, array $value) {
        $path_id  = (int) $row['path_id'];
        $worth    = self::worth($path_id, $value);
        $page     = $value['site'] ? (isset($value['pages'][$path_id]) ? $value['pages'][$path_id] : array('visits' => 0, 'conversions' => 0)) : null;
        $made     = SEOProStats_Refresh::propose($row, $facts, $page, $worth, $days);
        $proposal = $made['proposal'];
        $figures  = $made['figures'];
        $scale    = self::SCALE_DAYS / max(1, (int) $days);
        $impr     = max((int) $row['impressions'], (int) $row['compare']['impressions']);
        $parts    = array(
            'clicks'     => round((int) $row['lost'] * SEOProStats_Refresh::SHARE[$proposal] * $scale, 1),
            'value'      => round($worth, 2),
            'confidence' => round(self::CONFIDENCE['refresh'] * min(1.0, sqrt($impr * $scale / self::FULL_IMPRESSIONS)), 2),
            'effort'     => self::effort_of('refresh', $proposal),
        );
        return array(
            'key'      => self::key('refresh', $engine, (string) $row['path'], $proposal),
            'kind'     => 'refresh',
            'engine'   => $engine,
            'status'   => 'new',
            'found'    => true,
            'path_id'  => $path_id,
            'path'     => (string) $row['path'],
            'url'      => (string) $row['url'],
            'post_id'  => (int) $row['post_id'],
            'edit_url' => isset($row['edit_url']) ? $row['edit_url'] : null,
            'query'    => null,
            'finding'  => $proposal,
            'why'      => SEOProStats_Refresh::why($row, $figures, $value['site']),
            'todo'     => SEOProStats_Refresh::todo($figures),
            'figures'  => $figures,
            'metric'   => self::METRIC['refresh'],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
    }

    /**
     * A page's value: how well its visits from search convert against the
     * site's, smoothed, 1 to MAX_VALUE (1 without a goal).
     *
     * @param int                 $path_id Path id.
     * @param array<string,mixed> $value   From values().
     * @return float
     */
    private static function worth($path_id, array $value) {
        $site = $value['site'];
        if (!$site) {
            return 1.0;
        }
        $page = isset($value['pages'][(int) $path_id]) ? $value['pages'][(int) $path_id] : array('visits' => 0, 'conversions' => 0);
        $rate = ($page['conversions'] + self::SMOOTH * $site) / ($page['visits'] + self::SMOOTH);
        return min((float) self::MAX_VALUE, max(1.0, $rate / $site));
    }

    /**
     * The effort of an item's kind (and audit finding or links list).
     *
     * @param string      $kind    Kind name.
     * @param string|null $finding Audit finding, or links list.
     * @return int
     */
    private static function effort_of($kind, $finding = null) {
        if ($kind === 'audit' && $finding !== null && isset(self::AUDIT_EFFORT[$finding])) {
            return self::AUDIT_EFFORT[$finding];
        }
        if ($kind === 'links' && $finding !== null && isset(self::LINKS_EFFORT[$finding])) {
            return self::LINKS_EFFORT[$finding];
        }
        if ($kind === 'index' && $finding !== null && isset(self::INDEX_EFFORT[$finding])) {
            return self::INDEX_EFFORT[$finding];
        }
        if ($kind === 'refresh' && $finding !== null && isset(SEOProStats_Refresh::EFFORT[$finding])) {
            return SEOProStats_Refresh::EFFORT[$finding];
        }
        return self::EFFORT[$kind];
    }

    /**
     * The score from its parts.
     *
     * @param array<string,int|float> $parts clicks, value, confidence, effort.
     * @return float
     */
    private static function score(array $parts) {
        return round((float) $parts['clicks'] * (float) $parts['value'] * (float) $parts['confidence'] / max(1, (int) $parts['effort']), 1);
    }

    /**
     * Join the stored states to the items found, add the stored ones no
     * longer found, and leave out new items on pages with a running
     * experiment and dismissed ones still hidden.
     *
     * @param array<string,array<string,mixed>> $items   From build().
     * @param string                            $engine  Engine name.
     * @param array<int,int>                    $running  Path id => experiment id.
     * @param int                               $left_out Set to the new items left out for a running experiment.
     * @return array<int,array<string,mixed>>
     */
    private static function with_states(array $items, $engine, array $running, &$left_out) {
        $left_out = 0;
        $stored = self::stored(array_keys($items));
        $hidden = time() - self::HIDE_DAYS * DAY_IN_SECONDS;
        $out    = array();
        foreach ($items as $key => $item) {
            if (isset($stored[$key])) {
                $item = self::apply($item, $stored[$key]);
                unset($stored[$key]);
            }
            if ($item['status'] === 'dismissed' && $item['updated_ts'] < $hidden) {
                // Back after HIDE_DAYS days, as new.
                $item['status'] = 'new';
            }
            if ($item['status'] === 'new' && array_intersect_key($running, array_flip(self::held_by($item)))) {
                ++$left_out;
                continue;
            }
            $out[] = $item;
        }
        // Accepted and done items no longer found: as they were when acted on.
        foreach ($stored as $row) {
            $item = self::from_row($row);
            if ($item && $item['engine'] === $engine) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Stored rows: those of the keys given (by ikey) and the newest KEPT
     * accepted and done ones (by status_updated).
     *
     * @param string[] $keys Item keys (hex).
     * @return array<string,array<string,mixed>> Key => row.
     */
    private static function stored(array $keys) {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        $table = SEOProStats_Schema::table('queue');
        $cols  = 'id, LOWER(HEX(ikey)) AS k, kind, engine, path_id, query_id, status, effort, experiment_id, created, updated, user_id, note, meta';
        $out   = array();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its unique key ikey or key status_updated; $cols is a fixed column list and $holders only placeholders.
        foreach (array_chunk($keys, 200) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i WHERE ikey IN ($holders)", array_merge(array($table), $chunk)), ARRAY_A) as $row) {
                $out[(string) $row['k']] = $row;
            }
        }
        foreach (array(1, 2) as $status) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT $cols FROM %i FORCE INDEX (`status_updated`) WHERE status = %d ORDER BY updated DESC LIMIT %d", $table, $status, self::KEPT), ARRAY_A) as $row) {
                $out[(string) $row['k']] = $row;
            }
        }
        // phpcs:enable
        return $out;
    }

    /**
     * One stored row by key, or null.
     *
     * @param string $key Item key (hex).
     * @return array<string,mixed>|null
     */
    private static function row($key) {
        global $wpdb;
        if (!self::valid_key($key) || !SEOProStats_Schema::is_current()) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its unique key ikey.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, LOWER(HEX(ikey)) AS k, kind, engine, path_id, query_id, status, effort, experiment_id, created, updated, user_id, note, meta FROM %i WHERE ikey = UNHEX(%s)', SEOProStats_Schema::table('queue'), $key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * An item with its stored state: status, effort, note, experiment.
     *
     * @param array<string,mixed> $item Item.
     * @param array<string,mixed> $row  Stored row.
     * @return array<string,mixed>
     */
    private static function apply(array $item, array $row) {
        $item['status']        = isset(self::STATUSES[(int) $row['status']]) ? self::STATUSES[(int) $row['status']] : 'new';
        $item['note']          = (string) $row['note'];
        $item['updated']       = (string) wp_date('c', (int) $row['updated']);
        $item['updated_ts']    = (int) $row['updated'];
        $item['user']          = self::user_name((int) $row['user_id']);
        $item['experiment_id'] = (int) $row['experiment_id'] ? (int) $row['experiment_id'] : null;
        if ((int) $row['effort']) {
            $item['parts']['effort'] = (int) $row['effort'];
            $item['effort_set']      = true;
            $item['score']           = self::score($item['parts']);
        }
        return $item;
    }

    /**
     * A stored item no longer found, from what was kept when it was acted on.
     *
     * @param array<string,mixed> $row Stored row.
     * @return array<string,mixed>|null
     */
    private static function from_row(array $row) {
        $meta = self::meta($row);
        if (empty($meta['item']) || !is_array($meta['item']) || !in_array((int) $row['status'], array(1, 2), true)) {
            return null;
        }
        $item          = $meta['item'];
        $item['found'] = false;
        $item['key']   = (string) $row['k'];
        $info          = SEOProStats_Clicks::page_info((string) $item['path']);
        if ($info) {
            $item = array_merge($item, SEOProStats_Clicks::with_edit_url($info));
        }
        return self::apply($item + self::blank(), $row);
    }

    /**
     * An item's fields that only a stored state fills.
     *
     * @return array<string,mixed>
     */
    private static function blank() {
        return array(
            'note'          => '',
            'updated'       => null,
            'updated_ts'    => 0,
            'user'          => null,
            'experiment_id' => null,
            'effort_set'    => false,
        );
    }

    /**
     * An item as the answer gives it: the stored fields filled, and a done
     * item's experiment with its result.
     *
     * @param array<string,mixed> $item Item.
     * @return array<string,mixed>
     */
    private static function finish(array $item) {
        $item += self::blank();
        unset($item['updated_ts']);
        $item['experiment'] = null;
        if ($item['experiment_id']) {
            $found = SEOProStats_Experiments::get((int) $item['experiment_id']);
            if (!is_wp_error($found)) {
                $m                  = is_array($found['measurement']) ? $found['measurement'] : array();
                $item['experiment'] = array(
                    'id'        => $found['id'],
                    'name'      => $found['name'],
                    'status'    => $found['status'],
                    'result'    => $found['result'],
                    'due'       => $found['due'],
                    'review'    => $found['review'],
                    'metric'    => $found['metric'],
                    'state'     => isset($m['state']) ? $m['state'] : null,
                    'effect'    => isset($m['effect']) ? $m['effect'] : null,
                    'unit'      => isset($m['unit']) ? $m['unit'] : null,
                    'suggested' => isset($m['suggested']) ? $m['suggested'] : null,
                    'summary'   => isset($m['summary']) ? $m['summary'] : null,
                );
            }
        }
        return $item;
    }

    // ------------------------------------------------------------------
    // Acting.

    /**
     * Act on an item: accept, done (opens an experiment), dismiss,
     * restore (forget its state), effort (1–5) or note. An item not yet
     * stored must be in the list for the request now.
     *
     * @param string              $key    Item key (16 hex characters).
     * @param array<string,mixed> $input  action; effort; note; for done: name, days, threshold.
     * @param array<string,mixed> $req    From SEOProStats_Query::request(), the list it was seen in.
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id for value.
     * @return array<string,mixed>|WP_Error The item.
     */
    public static function update($key, array $input, array $req, $engine = 'google', $goal = '') {
        global $wpdb;
        require_once __DIR__ . '/class-seoprostats-experiments.php';
        $key    = strtolower(trim((string) $key));
        $action = isset($input['action']) ? (string) $input['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            /* translators: %s: list of actions */
            return self::error('seoprostats_queue_action', sprintf(__('The action is one of: %s.', 'seoprostats'), implode(', ', self::ACTIONS)));
        }
        if (!self::valid_key($key)) {
            return self::error('seoprostats_queue_key', __('An item\'s key is 16 hexadecimal characters, as the list gives it.', 'seoprostats'));
        }
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return self::error('seoprostats_queue_failed', __('The item could not be saved.', 'seoprostats'), 500);
        }
        $row  = self::row($key);
        $item = self::current($key, $row, $req, $engine, $goal);
        if (!$item) {
            return self::error('seoprostats_not_found', __('This item is not in the plan for this period now. List the plan again and use a key from it.', 'seoprostats'), 404);
        }
        $table = SEOProStats_Schema::table('queue');

        if ($action === 'restore') {
            if ($row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
                $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
            }
            $item = array_merge($item, array('status' => 'new', 'note' => '', 'experiment_id' => null, 'effort_set' => false));
            $item['parts']['effort'] = self::effort_of($item['kind'], isset($item['finding']) ? (string) $item['finding'] : null);
            $item['score']           = self::score($item['parts']);
            return self::finish($item);
        }

        $status = $row ? (int) $row['status'] : 0;
        $effort = $row ? (int) $row['effort'] : 0;
        $note   = $row ? (string) $row['note'] : '';
        $exp_id = $row ? (int) $row['experiment_id'] : 0;
        // The note action sets the note (empty clears it); other actions keep it unless one is given.
        if ($action === 'note' || (isset($input['note']) && (string) $input['note'] !== '')) {
            $note = self::note(isset($input['note']) ? $input['note'] : '');
        }
        if ($action === 'effort') {
            $effort = isset($input['effort']) ? (int) $input['effort'] : 0;
            if ($effort < 1 || $effort > self::MAX_EFFORT) {
                /* translators: %d: most effort */
                return self::error('seoprostats_queue_effort', sprintf(__('Effort is 1 (least) to %d.', 'seoprostats'), self::MAX_EFFORT));
            }
        } elseif ($action === 'accept') {
            $status = 1;
        } elseif ($action === 'dismiss') {
            $status = 3;
        } elseif ($action === 'done') {
            if ($status === 2 && self::no_change($item)) {
                return self::error('seoprostats_queue_done', __('This item is done already.', 'seoprostats'), 409);
            }
            if ($status === 2 && $exp_id) {
                return self::error('seoprostats_queue_done', __('This item is done already; its experiment measures it.', 'seoprostats'), 409);
            }
            // A page left as it is changes nothing, so no experiment measures it.
            if (!self::no_change($item)) {
                $opened = SEOProStats_Experiments::add(array(
                    'name'       => isset($input['name']) && trim((string) $input['name']) !== '' ? (string) $input['name'] : self::experiment_name($item),
                    'pages'      => self::paths($item),
                    'engine'     => $item['engine'],
                    'metric'     => $item['metric'],
                    'direction'  => 'up',
                    'days'       => isset($input['days']) && (int) $input['days'] ? (int) $input['days'] : SEOProStats_Experiments::DAYS,
                    'threshold'  => isset($input['threshold']) ? $input['threshold'] : '',
                    'hypothesis' => $item['why'] . ' ' . $item['todo'],
                    'note'       => $note,
                ));
                if (is_wp_error($opened)) {
                    return $opened;
                }
                $exp_id = (int) $opened['id'];
            }
            $status = 2;
        }

        // What it was when acted on, so it can be shown if it is no longer found.
        // (Its parts have the kind's effort: a stored effort is applied when read.)
        $keep = array_diff_key($item, array_flip(array('status', 'note', 'updated', 'updated_ts', 'user', 'experiment_id', 'effort_set', 'experiment', 'edit_url', 'found', 'key')));
        $meta = array('item' => $keep);
        $set  = array(
            'status'        => $status,
            'effort'        => $effort,
            'experiment_id' => $exp_id,
            'updated'       => time(),
            'user_id'       => get_current_user_id(),
            'note'          => $note,
            'meta'          => (string) wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- writing our own table, by primary key or a new row.
        if ($row) {
            $wpdb->update($table, $set, array('id' => (int) $row['id']), array('%d', '%d', '%d', '%d', '%d', '%s', '%s'), array('%d'));
        } else {
            $kind  = (int) array_search($item['kind'], self::KINDS, true);
            $query = $item['query'] !== null && $item['query'] !== '' ? SEOProStats_Dict::find(SEOProStats_Schema::DICT_QUERY, array((string) $item['query'])) : array();
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (ikey, kind, engine, path_id, query_id, status, effort, experiment_id, created, updated, user_id, note, meta) VALUES (UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
                $table,
                $key,
                $kind,
                SEOProStats_Search::ENGINES[$item['engine']],
                (int) $item['path_id'],
                $query ? (int) $query[0] : 0,
                $set['status'],
                $set['effort'],
                $set['experiment_id'],
                $set['updated'],
                $set['updated'],
                $set['user_id'],
                $set['note'],
                $set['meta']
            ));
        }
        // phpcs:enable
        $saved = self::row($key);
        if (!$saved) {
            return self::error('seoprostats_queue_failed', __('The item could not be saved.', 'seoprostats'), 500);
        }
        return self::finish(self::apply(array_merge($item, self::blank()), $saved));
    }

    /**
     * The item of a key now: found in the list for the request, else as
     * stored when it was acted on.
     *
     * @param string                   $key    Item key.
     * @param array<string,mixed>|null $row    Its stored row.
     * @param array<string,mixed>      $req    Request.
     * @param string                   $engine Engine name.
     * @param string                   $goal   Goal id.
     * @return array<string,mixed>|null
     */
    private static function current($key, $row, array $req, $engine, $goal) {
        $engine = $row ? ((int) $row['engine'] === SEOProStats_Schema::ENGINE_BING ? 'bing' : 'google') : $engine;
        $built  = self::build($req, $engine, $goal);
        if (isset($built['items'][$key])) {
            return $built['items'][$key] + self::blank();
        }
        if (!$row) {
            return null;
        }
        $meta = self::meta($row);
        if (empty($meta['item']) || !is_array($meta['item'])) {
            return null;
        }
        $info = SEOProStats_Clicks::page_info((string) $meta['item']['path']);
        return array_merge($meta['item'], $info ? SEOProStats_Clicks::with_edit_url($info) : array(), array('key' => $key, 'found' => false, 'status' => 'new')) + self::blank();
    }

    /**
     * The name of the experiment done opens.
     *
     * @param array<string,mixed> $item Item.
     * @return string
     */
    private static function experiment_name(array $item) {
        $page  = (string) $item['path'];
        $query = (string) $item['query'];
        if ($item['kind'] === 'ctr') {
            /* translators: %s: page path */
            return sprintf(__('A new title and description lift CTR on %s', 'seoprostats'), $page);
        }
        if ($item['kind'] === 'missing') {
            /* translators: 1: search query, 2: page path */
            return sprintf(__('Answering “%1$s” lifts clicks on %2$s', 'seoprostats'), $query, $page);
        }
        if ($item['kind'] === 'striking') {
            /* translators: 1: page path, 2: search query */
            return sprintf(__('A better page and links lift %1$s for “%2$s”', 'seoprostats'), $page, $query);
        }
        if ($item['kind'] === 'overlap') {
            /* translators: %s: search query */
            return sprintf(__('One clear page for “%s” lifts its clicks', 'seoprostats'), $query);
        }
        if ($item['kind'] === 'audit') {
            /* translators: %s: page path */
            return sprintf(__('Fixing the content audit\'s finding lifts %s', 'seoprostats'), $page);
        }
        if ($item['kind'] === 'links') {
            /* translators: %s: page path */
            return sprintf(__('Internal links lift clicks on %s', 'seoprostats'), $page);
        }
        if ($item['kind'] === 'index') {
            /* translators: %s: page path */
            return sprintf(__('Search shows %s once it can find it', 'seoprostats'), $page);
        }
        if ($item['kind'] === 'refresh' && $item['finding'] === 'merge') {
            /* translators: 1: page path, 2: the other page's path */
            return sprintf(__('One clear page for %1$s and %2$s wins back clicks', 'seoprostats'), $page, (string) $item['figures']['rival']['path']);
        }
        if ($item['kind'] === 'refresh' && $item['finding'] === 'protect') {
            /* translators: %s: page path */
            return sprintf(__('A careful change to %s wins back clicks and keeps conversions', 'seoprostats'), $page);
        }
        /* translators: %s: page path */
        return sprintf(__('Updating %s wins back its clicks', 'seoprostats'), $page);
    }

    // ------------------------------------------------------------------
    // Words.

    /**
     * Why an item is listed, in one or two sentences.
     *
     * @param string              $kind    Kind name.
     * @param array<string,mixed> $row     Opportunity row.
     * @param array<string,mixed> $figures Figures.
     * @return string
     */
    private static function why($kind, array $row, array $figures) {
        $pos = static function ($value) {
            return number_format_i18n((float) $value, 1);
        };
        $pct = static function ($value) {
            return number_format_i18n(100 * (float) $value, 1) . '%';
        };
        $num = static function ($value) {
            return number_format_i18n((int) $value);
        };
        if ($kind === 'ctr') {
            /* translators: 1: CTR, 2: average position, 3: the site's own CTR at that position, 4: impressions */
            return sprintf(__('Chosen less than its place earns: CTR %1$s at position %2$s, where the site\'s own is %3$s (%4$s impressions).', 'seoprostats'), $pct($figures['ctr']), $pos($figures['position']), $pct($figures['expected_ctr']), $num($figures['impressions']));
        }
        if ($kind === 'missing') {
            $words = implode(', ', (array) $figures['missing']);
            if ($figures['match'] === 'partial') {
                /* translators: 1: words missing, 2: impressions, 3: average position */
                return sprintf(__('Searched for, but the page lacks some of the words: %1$s (%2$s impressions at position %3$s).', 'seoprostats'), $words, $num($figures['impressions']), $pos($figures['position']));
            }
            /* translators: 1: impressions, 2: average position */
            return sprintf(__('Searched for, but the page never uses its words (%1$s impressions at position %2$s).', 'seoprostats'), $num($figures['impressions']), $pos($figures['position']));
        }
        if ($kind === 'striking') {
            /* translators: 1: average position, 2: impressions, 3: clicks it could gain */
            return sprintf(__('Ranks %1$s with %2$s impressions: in the top three it could gain about %3$s clicks.', 'seoprostats'), $pos($figures['position']), $num($figures['impressions']), $num($figures['potential']));
        }
        if ($kind === 'overlap') {
            $parts = array();
            foreach ((array) $figures['pages'] as $page) {
                /* translators: 1: page path, 2: share of the query's impressions, 3: average position */
                $parts[] = sprintf(__('%1$s (%2$s, position %3$s)', 'seoprostats'), $page['path'], $pct($page['share']), $pos($page['position']));
            }
            /* translators: 1: number of pages, 2: the pages with their shares and positions, 3: clicks it could gain */
            $text = sprintf(__('%1$d pages share this search: %2$s. With the best of their CTRs it would have about %3$s more clicks.', 'seoprostats'), count($parts), implode('; ', $parts), $num($figures['potential']));
            if (!empty($figures['switched'])) {
                $text .= ' ' . __('The page with most impressions changed between the halves of the period.', 'seoprostats');
            }
            return $text;
        }
        /* translators: 1: clicks lost, 2: the likely cause in a sentence */
        return sprintf(__('Lost %1$s clicks against the period before. %2$s', 'seoprostats'), $num($figures['lost']), isset($row['why']) ? (string) $row['why'] : '');
    }

    /**
     * What to do about an item of a kind.
     *
     * @param string $kind Kind name.
     * @return string
     */
    private static function todo($kind) {
        if ($kind === 'ctr') {
            return __('Rewrite the title and description so searchers choose it.', 'seoprostats');
        }
        if ($kind === 'missing') {
            return __('Answer the search on the page, in its own words.', 'seoprostats');
        }
        if ($kind === 'striking') {
            return __('Improve the page for this search and link to it from related pages.', 'seoprostats');
        }
        if ($kind === 'overlap') {
            return __('A candidate to review: if the pages answer the same need, make one the clear answer and link to it from the others (or merge them); leave it if each serves a different need.', 'seoprostats');
        }
        return __('Find what changed (see the cause and the changes on the page), then update it.', 'seoprostats');
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * An item's key: 16 hex characters of a hash of kind, engine, page
     * and query.
     *
     * @param string $kind   Kind name.
     * @param string $engine Engine name.
     * @param string $path   Page path.
     * @param string $query  Query ('' for none).
     * @return string
     */
    public static function key($kind, $engine, $path, $query) {
        return SEOProStats_Dict::hash($kind . "\n" . $engine . "\n" . $path . "\n" . $query);
    }

    /**
     * Whether doing an item changes nothing on its pages (a refresh
     * proposal to leave the page as it is): done opens no experiment.
     *
     * @param array<string,mixed> $item Item.
     * @return bool
     */
    private static function no_change(array $item) {
        return $item['kind'] === 'refresh' && in_array((string) $item['finding'], self::NO_CHANGE, true);
    }

    /**
     * The pages an item is about: its page, and for overlap every page
     * sharing the query.
     *
     * @param array<string,mixed> $item Item.
     * @return int[]
     */
    private static function page_ids(array $item) {
        $ids = array((int) $item['path_id']);
        if (!empty($item['figures']['pages'])) {
            foreach ((array) $item['figures']['pages'] as $page) {
                $ids[] = isset($page['path_id']) ? (int) $page['path_id'] : 0;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The pages whose running experiment holds a new item back: those it
     * is about, except for a refresh item, which is about its losing page
     * only (a merge's other page running an experiment does not hide the
     * loss, as its decay item would not have been hidden).
     *
     * @param array<string,mixed> $item Item.
     * @return int[]
     */
    private static function held_by(array $item) {
        return $item['kind'] === 'refresh' ? array((int) $item['path_id']) : self::page_ids($item);
    }

    /**
     * The paths an item is about, as page_ids().
     *
     * @param array<string,mixed> $item Item.
     * @return string[]
     */
    private static function paths(array $item) {
        $paths = array((string) $item['path']);
        if (!empty($item['figures']['pages'])) {
            foreach ((array) $item['figures']['pages'] as $page) {
                $paths[] = isset($page['path']) ? (string) $page['path'] : '';
            }
        }
        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Whether a text is an item key.
     *
     * @param string $key Text.
     * @return bool
     */
    private static function valid_key($key) {
        return (bool) preg_match('/^[0-9a-f]{16}$/', (string) $key);
    }

    /**
     * A row's meta.
     *
     * @param array<string,mixed> $row Table row.
     * @return array<string,mixed>
     */
    private static function meta(array $row) {
        $meta = isset($row['meta']) && $row['meta'] !== '' ? json_decode((string) $row['meta'], true) : array();
        return is_array($meta) ? $meta : array();
    }

    /**
     * A note, cut to the column (190 bytes).
     *
     * @param mixed $text Text.
     * @return string
     */
    private static function note($text) {
        $text = trim(sanitize_text_field(is_scalar($text) ? (string) $text : ''));
        return function_exists('mb_strcut') ? mb_strcut($text, 0, self::NOTE_BYTES, 'UTF-8') : substr($text, 0, self::NOTE_BYTES);
    }

    /**
     * Who acted, for people who may list users (and WP-CLI).
     *
     * @param int $user_id User.
     * @return string|null
     */
    private static function user_name($user_id) {
        if (!$user_id || !((defined('WP_CLI') && WP_CLI) || current_user_can('list_users'))) {
            return null;
        }
        $user = get_userdata($user_id);
        return $user ? (string) $user->display_name : null;
    }

    /**
     * An error for a request.
     *
     * @param string $code    Code.
     * @param string $message Message.
     * @param int    $status  HTTP status.
     * @return WP_Error
     */
    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => (int) $status));
    }
}
