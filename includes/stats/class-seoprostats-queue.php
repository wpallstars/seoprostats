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
 *   MISSING_SHARE), scaled to 28 days;
 * - value: how well visits from search to the page convert against the
 *   site (the Content report's goal), smoothed toward the site's rate
 *   with SMOOTH visits; at least 1 (a page with no goal data is 1) and at
 *   most MAX_VALUE;
 * - confidence: the kind's own × √(impressions per 28 days ÷
 *   FULL_IMPRESSIONS), at most the kind's own;
 * - effort: the kind's, unless a person set another.
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
    const EFFORT     = array('ctr' => 1, 'missing' => 2, 'striking' => 2, 'decay' => 3);
    const MAX_EFFORT = 5;

    /** The kind's own confidence, before the impressions are weighed. */
    const CONFIDENCE = array('decay' => 0.8, 'ctr' => 0.7, 'striking' => 0.6, 'missing' => 0.5);

    /** The measure of the experiment done opens, by kind. */
    const METRIC = array('ctr' => 'ctr', 'missing' => 'clicks', 'striking' => 'position', 'decay' => 'clicks');

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
     * @return array<string,mixed>|WP_Error
     */
    public static function report(array $req, $engine = 'google', $status = 'open', $goal = '') {
        $status = $status === '' ? 'open' : (string) $status;
        if (!in_array($status, self::FILTERS, true)) {
            /* translators: %s: list of states */
            return self::error('seoprostats_queue_status', sprintf(__('The status is one of: %s.', 'seoprostats'), implode(', ', self::FILTERS)));
        }
        $built  = self::build($req, $engine, $goal);
        $left   = 0;
        $items  = self::with_states($built['items'], $built['answer']['engine'], $built['running'], $left);
        $built['answer']['left_out'] = $left;
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
        $engine = SEOProStats_Search::engine_name($engine);
        $ask    = array_merge($req, array('limit' => self::PER_KIND, 'offset' => 0));

        $found = array();
        foreach (array('striking', 'ctr', 'decay', 'missing') as $kind) {
            $found[$kind] = SEOProStats_Opportunities::report($ask, $kind, $engine);
        }
        $head  = $found['striking'];
        $days  = (int) $head['days'];
        $curve = isset($head['curve']['ctr']) && is_array($head['curve']['ctr']) ? $head['curve']['ctr'] : SEOProStats_Opportunities::DEFAULT_CURVE;
        $value = self::values(array_merge($req, array('limit' => self::VALUE_PAGES, 'offset' => 0, 'compare' => 'none')), $engine, $goal);

        $running = SEOProStats_Experiments::running_pages();
        $items   = array();
        if ($days > 0) {
            foreach ($found as $kind => $answer) {
                foreach ($answer['rows'] as $row) {
                    $item = self::item($kind, $engine, $row, $days, $curve, $value);
                    if (!isset($items[$item['key']])) {
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
            if ($kind === 'missing') {
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
        $site  = $value['site'];
        $worth = 1.0;
        if ($site) {
            $page  = isset($value['pages'][(int) $row['path_id']]) ? $value['pages'][(int) $row['path_id']] : array('visits' => 0, 'conversions' => 0);
            $rate  = ($page['conversions'] + self::SMOOTH * $site) / ($page['visits'] + self::SMOOTH);
            $worth = min((float) self::MAX_VALUE, max(1.0, $rate / $site));
        }
        $parts = array(
            'clicks'     => round($clicks * $scale, 1),
            'value'      => round($worth, 2),
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
            'why'      => self::why($kind, $row, $figures),
            'todo'     => self::todo($kind),
            'figures'  => $figures,
            'metric'   => self::METRIC[$kind],
            'parts'    => $parts,
            'score'    => self::score($parts),
        );
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
            if ($item['status'] === 'new' && isset($running[$item['path_id']])) {
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
            $item['parts']['effort'] = self::EFFORT[$item['kind']];
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
            if ($status === 2 && $exp_id) {
                return self::error('seoprostats_queue_done', __('This item is done already; its experiment measures it.', 'seoprostats'), 409);
            }
            $opened = SEOProStats_Experiments::add(array(
                'name'       => isset($input['name']) && trim((string) $input['name']) !== '' ? (string) $input['name'] : self::experiment_name($item),
                'page'       => $item['path'],
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
            $status = 2;
            $exp_id = (int) $opened['id'];
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
