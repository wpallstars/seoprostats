<?php
/**
 * WP-CLI commands: wp seoprostats stats, timeseries, breakdown, realtime,
 * goals, funnels, properties, clicks, search, changes, annotate, experiments, queue, loop, search-updates,
 * process, rollup, prune, doctor, demo and purge-caches. Reports come from
 * the same engine as the REST API, so the numbers match, on live data or
 * with --data=demo the demo data (docs/architecture.md → Interfaces).
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

/**
 * Read SEO Pro Stats statistics and look after collection.
 *
 * ## EXAMPLES
 *
 *     wp seoprostats stats --range=30d --compare=prev
 *     wp seoprostats breakdown page --range=7d --filter=channel:is:organic_search
 *     wp seoprostats doctor
 */
final class SEOProStats_CLI {

    /**
     * WP-CLI makes this only to run one of these commands.
     */
    public function __construct() {
        SEOProStats_API::short_floats();
    }

    /**
     * Manage private report links (use --user with an administrator).
     *
     * ## OPTIONS
     *
     * <action>
     * : list, create, revoke or renew.
     *
     * [<id>]
     * : Share ID for revoke or renew.
     *
     * [--name=<name>]
     * : Name of the new share.
     *
     * [--views=<json>]
     * : JSON list of saved ViewState objects; default: Overview.
     *
     * [--locked-filters=<json>]
     * : JSON list of filters enforced on every report.
     *
     * [--max-days=<days>]
     * : Only the last N days; 0 means any period.
     *
     * [--expires=<timestamp>]
     * : Unix expiry timestamp; 0 means no expiry.
     *
     * @param string[] $args Positional arguments.
     * @param array    $assoc Options.
     */
    public function share($args, $assoc) {
        if (!current_user_can('manage_options')) {
            WP_CLI::error('Use --user with an administrator to manage shared reports.');
        }
        $action = $args[0] ?? 'list';
        if ($action === 'list') {
            $answer = array_values(array_map(array('SEOProStats_Shares', 'summary'), SEOProStats_Shares::all()));
        } elseif ($action === 'create') {
            $answer = SEOProStats_Shares::save(array(
                'name' => $assoc['name'] ?? 'Shared report',
                'views' => isset($assoc['views']) ? json_decode($assoc['views'], true) : array(array('view' => 'overview', 'range' => '30d', 'compare' => 'none')),
                'locked_filters' => isset($assoc['locked-filters']) ? json_decode($assoc['locked-filters'], true) : array(),
                'max_days' => $assoc['max-days'] ?? 0,
                'expires' => $assoc['expires'] ?? 0,
                'hide_realtime' => true, 'hide_sensitive' => true,
            ));
        } elseif (in_array($action, array('revoke', 'renew'), true)) {
            $answer = SEOProStats_Shares::revoke($args[1] ?? '', $action === 'renew');
        } else {
            WP_CLI::error('Use list, create, revoke or renew.');
            return;
        }
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Headline metrics: visitors, visits, pageviews, views per visit,
     * bounce rate, visit duration (seconds) and events.
     *
     * ## OPTIONS
     *
     * [--range=<range>]
     * : realtime, today, yesterday, 24h, 7d, 30d, 90d, week, month, year, 12mo, lastyear, all or custom.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range (YYYY-MM-DD).
     *
     * [--to=<date>]
     * : Last day of a custom range (YYYY-MM-DD).
     *
     * [--compare=<compare>]
     * : none, prev (the period before) or year (the same period last year).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : dimension:operator:value, several separated by ";" (comma means any of), or a JSON list.
     *
     * [--data=<data>]
     * : live, or demo for the demo data (wp seoprostats demo make).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats stats --range=30d --compare=prev
     *     wp seoprostats stats --filter="page:matches:/blog/*" --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function stats($args, $assoc) {
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProStats_Query::stats($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $items = array();
        foreach ($answer['metrics'] as $metric => $value) {
            $item = array('metric' => $metric, 'value' => $value);
            if (isset($answer['compare'])) {
                $item['compare'] = $answer['compare']['metrics'][$metric];
                $change          = $answer['compare']['change'][$metric];
                $item['change']  = $change === null ? '' : sprintf('%+.1f%%', $change * 100);
            }
            $items[] = $item;
        }
        $this->range_line($answer['range']);
        WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
    }

    /**
     * Metrics per hour, day or month.
     *
     * ## OPTIONS
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--grain=<grain>]
     * : auto, hour, day or month.
     * ---
     * default: auto
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function timeseries($args, $assoc) {
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProStats_Query::timeseries($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        WP_CLI\Utils\format_items($this->format($assoc), $answer['points'], array('t', 'visitors', 'visits', 'pageviews', 'bounce_rate', 'visit_duration', 'events'));
    }

    /**
     * Top values of a dimension.
     *
     * ## OPTIONS
     *
     * <dimension>
     * : channel, source, utm_source, utm_medium, utm_campaign, utm_term, utm_content, country, device, browser, os, language, login, entry, exit, page, not_found, search, no_results, author, category, post_type or event.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--offset=<offset>]
     * : Rows to skip.
     * ---
     * default: 0
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats breakdown source --range=30d
     *     wp seoprostats breakdown page --filter="channel:is:organic_search,ai" --limit=20
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function breakdown($args, $assoc) {
        $assoc['dimension'] = $args[0];
        $req                = $this->request($assoc);
        $answer             = $this->on_data($assoc, static function () use ($req) {
            return SEOProStats_Query::breakdown($req);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
            return;
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['rows']) {
            WP_CLI::line(__('No visits in this range.', 'seoprostats'));
            return;
        }
        $fields = array_values(array_diff(array_keys($answer['rows'][0]), array('value')));
        WP_CLI\Utils\format_items($this->format($assoc), $answer['rows'], $fields);
    }

    /**
     * Goals: their conversions and revenue (report), or add, change, list
     * and delete them (administrators' work; goals are per data set).
     *
     * A goal is a page viewed (a path; * for any text) or an event sent (a
     * name). Conversion rate: visits that reached it ÷ visits. Revenue is
     * per currency.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : report, list, add, update or delete.
     * ---
     * default: report
     * options:
     *   - report
     *   - list
     *   - add
     *   - update
     *   - delete
     * ---
     *
     * [<id>]
     * : Goal id, for update and delete.
     *
     * [--name=<name>]
     * : Name, for add and update.
     *
     * [--kind=<kind>]
     * : page or event, for add and update.
     *
     * [--match=<match>]
     * : Page path (/pricing/, /blog/*) or event name (Purchase), for add and update.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev or year.
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats goals --range=30d --compare=prev
     *     wp seoprostats goals add --name="Purchase" --kind=event --match=Purchase
     *     wp seoprostats goals add --name="Viewed pricing" --kind=page --match=/pricing/
     *     wp seoprostats goals delete ab12cd34
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function goals($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'report';
        if ($action !== 'report') {
            $this->define('goals', $action, isset($args[1]) ? (string) $args[1] : '', $assoc, static function ($id) use ($assoc) {
                return SEOProStats_Goals::save_goal(array_intersect_key($assoc, array_flip(array('name', 'kind', 'match'))), $id);
            });
            return;
        }
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProStats_Conversions::goals($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['goals']) {
            WP_CLI::line(__('No goals yet. Add one: wp seoprostats goals add --name="Purchase" --kind=event --match=Purchase', 'seoprostats'));
            return;
        }
        $items = array();
        foreach ($answer['goals'] as $goal) {
            $item = array(
                'id'              => $goal['id'],
                'name'            => $goal['name'],
                'kind'            => $goal['kind'],
                'match'           => $goal['match'],
                'visitors'        => $goal['visitors'],
                'visits'          => $goal['visits'],
                'completions'     => $goal['completions'],
                'conversion_rate' => $goal['conversion_rate'],
                'revenue'         => self::money_text($goal['revenue']),
            );
            if (isset($goal['change'])) {
                $item['change'] = $goal['change']['visits'] === null ? '' : sprintf('%+.1f%%', $goal['change']['visits'] * 100);
            }
            $items[] = $item;
        }
        /* translators: %d: visits */
        WP_CLI::log(sprintf(__('%d visits in this range.', 'seoprostats'), $answer['visits']));
        WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
    }

    /**
     * Funnels: visits at each step, in order within one visit (report), or
     * add, change, list and delete them.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : report, list, add, update or delete.
     * ---
     * default: report
     * options:
     *   - report
     *   - list
     *   - add
     *   - update
     *   - delete
     * ---
     *
     * [<id>]
     * : Funnel id, for update and delete.
     *
     * [--name=<name>]
     * : Name, for add and update.
     *
     * [--steps=<steps>]
     * : 2 to 12 steps as kind:match separated by ";" (page:/pricing/;page:/cart/;event:Purchase), or a JSON list of {name, kind, match}.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev or year.
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats funnels --range=30d
     *     wp seoprostats funnels add --name=Checkout --steps="page:/pricing/;page:/cart/;page:/checkout/;event:Purchase"
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function funnels($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'report';
        if ($action !== 'report') {
            $this->define('funnels', $action, isset($args[1]) ? (string) $args[1] : '', $assoc, static function ($id) use ($assoc) {
                $input = array_intersect_key($assoc, array('name' => true));
                if (isset($assoc['steps'])) {
                    $input['steps'] = self::steps((string) $assoc['steps']);
                }
                return SEOProStats_Goals::save_funnel($input, $id);
            });
            return;
        }
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProStats_Conversions::funnels($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['funnels']) {
            WP_CLI::line(__('No funnels yet. Add one: wp seoprostats funnels add --name=Checkout --steps="page:/pricing/;page:/cart/;event:Purchase"', 'seoprostats'));
            return;
        }
        foreach ($answer['funnels'] as $funnel) {
            WP_CLI::log('');
            /* translators: 1: funnel name, 2: id, 3: completed visits, 4: visits that started it, 5: percentage */
            WP_CLI::log(sprintf(__('%1$s (%2$s): %3$d of %4$d visits completed it (%5$s).', 'seoprostats'), $funnel['name'], $funnel['id'], $funnel['completed'], $funnel['entered'], sprintf('%.1f%%', $funnel['completion_rate'] * 100)));
            WP_CLI\Utils\format_items($this->format($assoc), $funnel['steps'], array('name', 'kind', 'match', 'visits', 'rate', 'step_rate', 'dropped'));
        }
    }

    /**
     * Custom properties sent with events and pages: the keys, or the
     * values of one key, with counts, visits and event revenue.
     *
     * ## OPTIONS
     *
     * [<key>]
     * : Property whose values to list; without it, the keys are listed.
     *
     * [--event=<event>]
     * : Only properties sent with this event.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats properties --range=30d
     *     wp seoprostats properties plan --event=Purchase --range=12mo
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function properties($args, $assoc) {
        $key    = isset($args[0]) ? (string) $args[0] : '';
        $event  = isset($assoc['event']) ? (string) $assoc['event'] : '';
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req, $key, $event) {
            return SEOProStats_Conversions::properties($req, $key, $event);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['rows']) {
            WP_CLI::line(__('No properties in this range.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $row['revenue'] = self::money_text($row['revenue']);
            unset($row['value']);
            $rows[] = $row;
        }
        $fields = $key === '' ? array('label', 'count', 'visits', 'share') : array('label', 'count', 'visits', 'share', 'revenue');
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $fields);
    }

    /**
     * Clicks and form submits (autocapture): totals, then clicked
     * elements, dead clicks, link destinations, file links or forms sent.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : elements, dead, links, downloads, forms or pages.
     * ---
     * default: elements
     * options:
     *   - elements
     *   - dead
     *   - links
     *   - downloads
     *   - forms
     *   - pages
     * ---
     *
     * [--page=<path>]
     * : Only clicks on this page (* for any text).
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev or year (the totals).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats clicks --range=30d
     *     wp seoprostats clicks dead --page=/pricing/
     *     wp seoprostats clicks links --range=30d --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function clicks($args, $assoc) {
        $kind   = isset($args[0]) ? (string) $args[0] : 'elements';
        $page   = isset($assoc['page']) ? (string) $assoc['page'] : '';
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req, $kind, $page) {
            return SEOProStats_Clicks::report($req, $kind, $page);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        $totals = $answer['totals'];
        /* translators: 1: clicks, 2: dead clicks, 3: percentage, 4: outbound, 5: affiliate, 6: file links, 7: form submits, 8: visits */
        WP_CLI::log(sprintf(__('%1$d clicks, %2$d dead (%3$s); %4$d outbound, %5$d affiliate, %6$d file links; %7$d forms sent; in %8$d visits.', 'seoprostats'), $totals['clicks'], $totals['dead'], sprintf('%.1f%%', $totals['dead_rate'] * 100), $totals['outbound'], $totals['affiliate'], $totals['downloads'], $totals['forms'], $totals['visits']));
        if ($page !== '') {
            $info = $answer['page_info'];
            /* translators: %s: page path. */
            WP_CLI::log(sprintf(__('Page: %s', 'seoprostats'), $page));
            if ($info !== null) {
                WP_CLI::log($info['url']);
                if ($info['post_id']) {
                    /* translators: %d: post ID. */
                    WP_CLI::log(sprintf(__('Post ID: %d', 'seoprostats'), $info['post_id']));
                }
                if ($info['edit_url'] !== null) {
                    WP_CLI::log($info['edit_url']);
                }
            }
        }
        if (isset($answer['compare'])) {
            $change = $answer['compare']['change']['clicks'];
            /* translators: 1: clicks in the other period, 2: change */
            WP_CLI::log(sprintf(__('Compared: %1$d clicks (%2$s).', 'seoprostats'), $answer['compare']['totals']['clicks'], $change === null ? '–' : sprintf('%+.1f%%', $change * 100)));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No clicks of this kind in this range.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $row['flags'] = implode(' ', array_keys(array_filter(array(
                'outbound'  => $row['outbound'],
                'affiliate' => $row['affiliate'],
                'download'  => $row['download'],
            ))));
            $rows[] = $row;
        }
        $fields = array(
            'elements'  => array('selector', 'label', 'count', 'visits', 'share', 'dead', 'dead_rate'),
            'dead'      => array('selector', 'label', 'target', 'count', 'visits'),
            'links'     => array('target', 'label', 'flags', 'count', 'visits', 'share'),
            'downloads' => array('target', 'label', 'count', 'visits', 'share'),
            'forms'     => array('label', 'selector', 'target', 'fields', 'count', 'visits'),
            'pages'     => array('path', 'count', 'dead', 'dead_rate', 'links', 'forms', 'visits'),
        );
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $fields[$answer['kind']]);
    }

    /**
     * Search (Google Search Console's imported days): clicks, impressions,
     * CTR and average position, then search queries, pages, countries,
     * devices, search appearances or the chart's days (weeks or months).
     * The period is cut at the newest day with search data (about three
     * days ago); visit filters do not apply, page filters do.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : queries, pages, countries, devices, appearance (countries, devices and appearance for the whole site, Google only) or days (the chart's points by day, week or month, newest first).
     * ---
     * default: queries
     * options:
     *   - queries
     *   - pages
     *   - countries
     *   - devices
     *   - appearance
     *   - days
     * ---
     *
     * [--page=<path>]
     * : Only searches that showed this page (* for any text).
     *
     * [--query=<query>]
     * : Only this search query (* for any text).
     *
     * [--engine=<engine>]
     * : google (Search Console), bing (Bing Webmaster Tools; no countries or devices) or all (Combined: every engine with data added up; no countries or devices).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev or year.
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats (others do not apply to search data).
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--offset=<offset>]
     * : Rows skipped (for the next rows).
     * ---
     * default: 0
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats search --range=90d --compare=prev
     *     wp seoprostats search days --range=30d --limit=100
     *     wp seoprostats search queries --page=/pricing/
     *     wp seoprostats search pages --query="seo pro stats" --format=json
     *     wp seoprostats search --engine=bing
     *     wp seoprostats search --engine=all
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function search($args, $assoc) {
        $kind   = isset($args[0]) ? (string) $args[0] : 'queries';
        $page   = isset($assoc['page']) ? (string) $assoc['page'] : '';
        $query  = isset($assoc['query']) ? (string) $assoc['query'] : '';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $req    = $this->request($assoc + array('range' => '30d'));
        $answer = $this->on_data($assoc, static function () use ($req, $kind, $page, $query, $engine) {
            return SEOProStats_Search::report($req, $kind, $page, $query, $engine);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        if ($answer['through'] !== '') {
            /* translators: %s: a day (YYYY-MM-DD). */
            WP_CLI::log(sprintf(__('Search data through %s (final days only).', 'seoprostats'), $answer['through']));
        }
        if ($answer['ignored']) {
            /* translators: %s: filter dimensions. */
            WP_CLI::log(sprintf(__('Not applied to search data: %s.', 'seoprostats'), implode(', ', $answer['ignored'])));
        }
        $totals = $answer['totals'];
        /* translators: 1: clicks, 2: impressions, 3: CTR, 4: average position */
        WP_CLI::log(sprintf(__('%1$d clicks, %2$d impressions, CTR %3$s, average position %4$s.', 'seoprostats'), $totals['clicks'], $totals['impressions'], sprintf('%.1f%%', $totals['ctr'] * 100), $totals['impressions'] ? sprintf('%.1f', $totals['position']) : '–'));
        if (isset($answer['compare'])) {
            $then   = $answer['compare']['totals'];
            $change = $answer['compare']['change'];
            /* translators: 1: clicks, 2: change, 3: impressions, 4: change, 5: position, 6: change in places (lower is better) */
            WP_CLI::log(sprintf(__('Compared: %1$d clicks (%2$s), %3$d impressions (%4$s), position %5$s (%6$s places).', 'seoprostats'), $then['clicks'], $change['clicks'] === null ? '–' : sprintf('%+.1f%%', $change['clicks'] * 100), $then['impressions'], $change['impressions'] === null ? '–' : sprintf('%+.1f%%', $change['impressions'] * 100), $then['impressions'] ? sprintf('%.1f', $then['position']) : '–', $change['position'] === null ? '–' : sprintf('%+.1f', $change['position'])));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No search data of this kind in this range.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $row['ctr']   = sprintf('%.1f%%', $row['ctr'] * 100);
            $row['share'] = sprintf('%.1f%%', $row['share'] * 100);
            $rows[]       = $row;
        }
        $first = array(
            'queries'    => 'value',
            'pages'      => 'path',
            'countries'  => 'label',
            'devices'    => 'label',
            'appearance' => 'label',
            'days'       => 'label',
        );
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array($first[$answer['kind']], 'clicks', 'impressions', 'ctr', 'position', 'share'));
    }

    /**
     * Warn when a search report's engine is not connected (live data).
     *
     * @param array<string,mixed> $answer A search, opportunities or content answer.
     */
    private function search_connected(array $answer) {
        if (!empty($answer['connected'])) {
            return;
        }
        if (isset($answer['engine']) && $answer['engine'] === 'bing') {
            WP_CLI::warning(__('Bing Webmaster Tools is not connected: wp seoprostats connect bing --key-file=<file>.', 'seoprostats'));
            return;
        }
        WP_CLI::warning(__('Google Search Console is not connected: wp seoprostats connect search-console --key-file=<file>.', 'seoprostats'));
    }

    /**
     * Search opportunities from Google Search Console's imported days:
     * striking distance (a page's query at position 4–20 that could reach
     * the top three: potential clicks), low CTR (a top-10 query with a CTR
     * well under the site's own at that position: clicks missed) or decay
     * (pages losing clicks against the previous period, with the likely
     * cause: position, demand, ctr or gone), missing (a top-20 query
     * whose words its page does not have, or has only some of) or overlap
     * (a query for which two or more pages each get at least 10% of the
     * impressions: a candidate to review, with each page's share and
     * whether the page with most impressions changed between the halves
     * of the period). The period is cut at the newest day with search data
     * and to its newest 91 days.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : striking, ctr, decay, missing or overlap.
     * ---
     * default: striking
     * options:
     *   - striking
     *   - ctr
     *   - decay
     *   - missing
     *   - overlap
     * ---
     *
     * [--engine=<engine>]
     * : google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : For decay: prev (the default) or year.
     * ---
     * default: prev
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats (others do not apply to search data).
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats opportunities
     *     wp seoprostats opportunities ctr --range=90d
     *     wp seoprostats opportunities decay --format=json
     *     wp seoprostats opportunities overlap --range=90d
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function opportunities($args, $assoc) {
        $kind   = isset($args[0]) ? (string) $args[0] : 'striking';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $req    = $this->request($assoc + array('range' => '30d', 'compare' => 'prev'));
        $answer = $this->on_data($assoc, static function () use ($req, $kind, $engine) {
            return SEOProStats_Opportunities::report($req, $kind, $engine);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        if ($answer['cut']) {
            /* translators: %d: days */
            WP_CLI::log(sprintf(__('Only the newest %d days of the period are read.', 'seoprostats'), $answer['days']));
        }
        if (!empty($answer['compare'])) {
            /* translators: 1: start, 2: end */
            WP_CLI::log(sprintf(__('Against %1$s to %2$s.', 'seoprostats'), substr($answer['compare']['range']['from'], 0, 10), substr($answer['compare']['range']['to'], 0, 10)));
        }
        if ($answer['ignored']) {
            /* translators: %s: filter dimensions. */
            WP_CLI::log(sprintf(__('Not applied to search data: %s.', 'seoprostats'), implode(', ', $answer['ignored'])));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No opportunities of this kind in this range.', 'seoprostats'));
            return;
        }
        $pct  = static function ($value) {
            return sprintf('%.1f%%', (float) $value * 100);
        };
        $rows = array();
        foreach ($answer['rows'] as $row) {
            if ($answer['kind'] === 'decay') {
                $rows[] = array(
                    'path'     => $row['path'],
                    'clicks'   => $row['clicks'],
                    'was'      => $row['compare']['clicks'],
                    'lost'     => $row['lost'],
                    'position' => $row['impressions'] ? sprintf('%.1f', $row['position']) : '–',
                    'was_pos'  => sprintf('%.1f', $row['compare']['position']),
                    'cause'    => $row['cause'],
                    'queries'  => implode('; ', array_column($row['queries'], 'query')),
                    'changes'  => implode('; ', array_map(static function ($c) {
                        return substr((string) $c['t'], 0, 10) . ' ' . $c['label'];
                    }, $row['changes'])),
                );
                continue;
            }
            if ($answer['kind'] === 'overlap') {
                $rows[] = array(
                    'query'       => $row['query'],
                    'impressions' => $row['impressions'],
                    'clicks'      => $row['clicks'],
                    'pages'       => implode('; ', array_map(static function ($page) use ($pct) {
                        return sprintf('%s %s pos %.1f', $page['path'], $pct($page['share']), $page['position']);
                    }, $row['pages'])) . ($row['page_count'] > count($row['pages']) ? sprintf('; +%d', $row['page_count'] - count($row['pages'])) : ''),
                    'switched'    => $row['switched'] ? implode(' → ', $row['leaders']) : '',
                    'potential'   => $row['potential'],
                );
                continue;
            }
            if ($answer['kind'] === 'missing') {
                $rows[] = array(
                    'path'        => $row['path'],
                    'query'       => $row['query'],
                    'impressions' => $row['impressions'],
                    'clicks'      => $row['clicks'],
                    'position'    => sprintf('%.1f', $row['position']),
                    'match'       => $row['match'],
                    'missing'     => implode(' ', $row['missing']),
                    'question'    => $row['question'] ? 'yes' : '',
                );
                continue;
            }
            $rows[] = array(
                'path'         => $row['path'],
                'query'        => $row['query'],
                'clicks'       => $row['clicks'],
                'impressions'  => $row['impressions'],
                'ctr'          => $pct($row['ctr']),
                'expected_ctr' => $pct($row['expected_ctr']),
                'position'     => sprintf('%.1f', $row['position']),
                'potential'    => $row['potential'],
            );
        }
        if ($answer['kind'] === 'overlap') {
            WP_CLI::log(__('Candidates to review, not faults: two pages can both be right for one search. switched: the page with most impressions in each half of the period; potential: clicks with the best of the pages\' CTRs.', 'seoprostats'));
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
        if ($answer['kind'] === 'decay' && $answer['updates']) {
            /* translators: %s: search engine updates */
            WP_CLI::log(sprintf(__('Search engine updates in these periods: %s.', 'seoprostats'), implode('; ', array_column($answer['updates'], 'label'))));
        }
    }

    /**
     * The content audit: published pages with findings, by search impressions.
     *
     * Findings come from each page's WordPress content and SEO plugin
     * fields: title or description missing, long or the same as another
     * page's; no H1 or several; images without alt text; a thin page with
     * impressions but no clicks; noindex or a canonical address elsewhere
     * on a page with impressions. Facts are read when a post is saved and
     * by the daily cron, 200 posts a day; run reads the next posts now.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list (the pages with findings) or run (read the next posts' facts now).
     * ---
     * default: list
     * options:
     *   - list
     *   - run
     * ---
     *
     * [--finding=<finding>]
     * : Only pages with this finding: noindex, canonical, thin, title_missing, title_duplicate, title_long, description_missing, description_duplicate, description_long, h1_none, h1_several or images_alt.
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools), for the search figures.
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most rows (list; 20 when left out), or most posts read (run; a daily batch, 200, when left out).
     *
     * [--data=<data>]
     * : live or demo (list).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats audit
     *     wp seoprostats audit --finding=description_missing --limit=50
     *     wp seoprostats audit run --limit=1000
     *     wp seoprostats audit --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function audit($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-audit.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        if ($action === 'run') {
            if (!SEOProStats_Schema::maybe_upgrade()) {
                WP_CLI::error(__('The tables could not be made.', 'seoprostats'));
            }
            $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : SEOProStats_Audit::BATCH;
            $done  = SEOProStats_Audit::batch($limit, 600);
            /* translators: 1: posts read, 2: posts looked at */
            WP_CLI::success(sprintf(__('Read %1$d posts of %2$d looked at.', 'seoprostats'), $done['read'], $done['looked']) . ($done['done'] ? ' ' . __('Every post has been looked at; the next run starts from the first.', 'seoprostats') : ''));
            return;
        }
        $engine  = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $finding = isset($assoc['finding']) ? (string) $assoc['finding'] : '';
        $req     = $this->request($assoc + array('range' => '30d', 'limit' => '20'));
        $answer  = $this->on_data($assoc, static function () use ($req, $engine, $finding) {
            return SEOProStats_Audit::report($req, $engine, $finding);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        /* translators: 1: pages with facts, 2: date the oldest was read, 3: pages with findings */
        WP_CLI::log(sprintf(__('%1$d pages read (the oldest on %2$s); %3$d with findings.', 'seoprostats'), $answer['checked']['pages'], $answer['checked']['oldest'] ? substr((string) $answer['checked']['oldest'], 0, 10) : '–', $answer['pages']));
        $counts = array_filter($answer['counts']);
        if ($counts) {
            WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
                return $name . ' ' . $n;
            }, array_keys($counts), $counts)));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages with findings.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = array(
                'path'        => $row['path'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
                'position'    => $row['impressions'] ? sprintf('%.1f', $row['position']) : '–',
                'words'       => $row['facts']['words'],
                'findings'    => implode(', ', $row['findings']),
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Internal links: orphan pages, converting pages with few links in, and
     * links missing between pages that share a search.
     *
     * Links are read from the text of published pages with the content
     * audit's facts (when a post is saved and by the daily cron; run
     * `wp seoprostats audit run` to read more now). Orphans: no other
     * page's text links to them. Converting: pages whose visits from search
     * reach the goal 3 times or more with 2 or fewer pages linking in. The
     * front page is in neither list (menus link to it). Missing: a page shows for a search but
     * does not link to the page that gets most of its clicks.
     *
     * ## OPTIONS
     *
     * [--kind=<kind>]
     * : orphans, converting or missing.
     * ---
     * default: orphans
     * options:
     *   - orphans
     *   - converting
     *   - missing
     * ---
     *
     * [--goal=<goal>]
     * : ID of the goal whose conversions are counted; the first goal when left out.
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools), for the search figures.
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats links
     *     wp seoprostats links --kind=missing --limit=50
     *     wp seoprostats links --kind=converting --data=demo
     *     wp seoprostats links --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function links($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-links.php';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $kind   = isset($assoc['kind']) ? (string) $assoc['kind'] : 'orphans';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $req    = $this->request($assoc + array('range' => '30d', 'limit' => '20'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $kind, $goal) {
            return SEOProStats_Links::report($req, $engine, $kind, $goal);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        /* translators: 1: pages whose links were read, 2: published pages read by the audit */
        WP_CLI::log(sprintf(__('Links read on %1$d of %2$d pages.', 'seoprostats'), $answer['read']['read'], $answer['read']['pages']) . ($answer['goal'] ? ' ' . sprintf(/* translators: %s: goal name */ __('Goal: %s.', 'seoprostats'), $answer['goal']['name']) : ''));
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $line = array(
                'path'        => $row['path'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
            );
            if ($answer['kind'] === 'missing') {
                $line += array(
                    'link_to'  => $row['to']['path'],
                    'searches' => implode(', ', array_column($row['queries'], 'query')),
                );
            } else {
                $line += array(
                    'visits'      => $row['visits'],
                    'conversions' => $row['conversions'] === null ? '–' : $row['conversions'],
                    'links_in'    => $row['links_in'],
                    'linked_from' => implode(', ', $row['from']),
                );
            }
            $rows[] = $line;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Indexation: pages search engines do not seem to show.
     *
     * Pages: published pages (read by the content audit) with no search
     * impressions in the engine's newest days, published before them;
     * never shown, or shown before and not since. Sitemap: other
     * addresses in the site's own sitemaps (category, tag and author
     * archives, and other plugins'), listed that long, with none. Pages
     * that ask not to be indexed or name another page as canonical are
     * left out. The sitemaps are read by the daily cron; `run` reads them
     * now. Engine URL inspection is not used.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list (the pages) or run (read the site's sitemaps now).
     * ---
     * default: list
     * options:
     *   - list
     *   - run
     * ---
     *
     * [--kind=<kind>]
     * : pages or sitemap.
     * ---
     * default: pages
     * options:
     *   - pages
     *   - sitemap
     * ---
     *
     * [--days=<days>]
     * : Days without search impressions, and since publishing or first listing (7 to 365).
     * ---
     * default: 28
     * ---
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo (list).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats indexation
     *     wp seoprostats indexation --kind=sitemap
     *     wp seoprostats indexation --days=56 --limit=100
     *     wp seoprostats indexation run
     *     wp seoprostats indexation --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function indexation($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-indexation.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        if ($action === 'run') {
            if (!SEOProStats_Schema::maybe_upgrade()) {
                WP_CLI::error(__('The tables could not be made.', 'seoprostats'));
            }
            $done = SEOProStats_Indexation::read_sitemaps(120);
            if (!$done['enabled']) {
                WP_CLI::warning(__('WordPress’s sitemaps are off (an SEO plugin may make its own); no addresses were read.', 'seoprostats'));
                return;
            }
            /* translators: %d: sitemap addresses */
            WP_CLI::success(sprintf(__('Read %d sitemap addresses besides the posts.', 'seoprostats'), $done['addresses']) . ($done['complete'] ? '' : ' ' . __('Not every sitemap was read (time or address limit).', 'seoprostats')));
            return;
        }
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $kind   = isset($assoc['kind']) ? (string) $assoc['kind'] : 'pages';
        $days   = isset($assoc['days']) ? (int) $assoc['days'] : SEOProStats_Indexation::DAYS;
        $req    = $this->request(array_diff_key($assoc, array('days' => 1)) + array('limit' => '20'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $kind, $days) {
            return SEOProStats_Indexation::report($req, $engine, $kind, $days);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        $read = $answer['read'];
        /* translators: 1: pages with their published time, 2: published pages read by the audit, 3: sitemap addresses */
        WP_CLI::log(sprintf(__('Published times read on %1$d of %2$d pages; %3$d other sitemap addresses.', 'seoprostats'), $read['published'], $read['pages'], $read['sitemap']['addresses']) . ($read['sitemap']['read'] && !$read['sitemap']['enabled'] ? ' ' . __('WordPress’s sitemaps are off.', 'seoprostats') : ''));
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $line = array(
                'path'            => $row['path'],
                'state'           => $row['state'],
                'last_impression' => $row['last_impression'] === null ? '–' : $row['last_impression'],
                'days'            => $row['age'],
            );
            if ($answer['kind'] === 'pages') {
                $line += array(
                    'published' => substr((string) $row['published'], 0, 10),
                    'words'     => $row['words'],
                    'links_in'  => $row['links_in'],
                );
            } else {
                $line += array(
                    'first_seen' => substr((string) $row['first_seen'], 0, 10),
                    'source'     => $row['source'],
                );
            }
            $rows[] = $line;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Search targets: the searches the site chose to win and the page
     * meant for each, with how search treats them now.
     *
     * List gives each target's clicks, impressions and position on any
     * page, the page search shows most for it, and its state: ranking (the
     * page meant for it), wrong_page (another page), no_page (none chosen)
     * or not_shown (no impressions). The period is cut at the newest day
     * with search data and to its newest 91 days.
     *
     * Import reads a list from a file (or - for standard input): CSV or
     * tab-separated text (a header row naming query, page, priority and
     * status, or those columns in that order), JSON, or the aidevops search
     * targets table (TOON: phrase, target_url, priority, status). Targets
     * are added or updated by query; rows without search text, with an
     * address that is not on this site, or with a priority or status that
     * cannot be read are skipped and listed.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, import or delete.
     * ---
     * default: list
     * options:
     *   - list
     *   - import
     *   - delete
     * ---
     *
     * [<what>...]
     * : For import: the file (- for standard input). For delete: the searches.
     *
     * [--replace]
     * : For import: delete the targets that are not in the list.
     *
     * [--all]
     * : For delete: delete every target.
     *
     * [--yes]
     * : For delete --all: do not ask.
     *
     * [--status=<status>]
     * : For list: all, open (candidate, targeted and live), candidate, targeted, live, won or retired.
     * ---
     * default: all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--compare=<compare>]
     * : As for stats: previous or year adds the position and clicks then.
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--limit=<limit>]
     * : Most rows (100 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats targets
     *     wp seoprostats targets --status=open --range=90d
     *     wp seoprostats targets import targets.csv
     *     wp seoprostats targets import - < keywords.toon
     *     wp seoprostats targets delete "privacy friendly analytics"
     *     wp seoprostats targets --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function targets($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-targets.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        $what   = array_slice($args, 1);
        if ($action === 'import') {
            $this->targets_import($what, $assoc);
            return;
        }
        if ($action === 'delete') {
            $all = !empty($assoc['all']);
            if ($all) {
                WP_CLI::confirm(__('Delete every search target?', 'seoprostats'), $assoc);
            }
            $done = $this->on_data($assoc, static function () use ($what, $all) {
                return SEOProStats_Targets::delete($what, $all);
            });
            if (is_wp_error($done)) {
                WP_CLI::error($done->get_error_message());
            }
            /* translators: 1: targets deleted, 2: targets left */
            WP_CLI::success(sprintf(__('Deleted %1$d targets; %2$d left.', 'seoprostats'), $done['deleted'], $done['total']));
            return;
        }
        if ($action !== 'list') {
            WP_CLI::error(__('The action is list, import or delete.', 'seoprostats'));
        }
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $status = isset($assoc['status']) ? (string) $assoc['status'] : 'all';
        $req    = $this->request(array_diff_key($assoc, array('status' => 1)) + array('range' => '30d', 'limit' => (string) SEOProStats_Targets::LIMIT));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $status) {
            return SEOProStats_Targets::report($req, $engine, $status);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No targets. Import a list with: wp seoprostats targets import <file>', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = array(
                'query'       => $row['query'],
                'priority'    => $row['priority'],
                'status'      => $row['status'],
                'state'       => $row['state'],
                'page'        => $row['page'] ? $row['page']['path'] : '–',
                'shown'       => $row['shown'] ? $row['shown']['path'] : '–',
                'position'    => $row['position'] === null ? '–' : $row['position'],
                'clicks'      => $row['clicks'],
                'impressions' => $row['impressions'],
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * wp seoprostats targets import: read the list and import it.
     *
     * @param string[]             $what  The file (- for standard input).
     * @param array<string,string> $assoc Options.
     */
    private function targets_import(array $what, array $assoc) {
        $file = isset($what[0]) ? (string) $what[0] : '';
        if ($file === '') {
            WP_CLI::error(__('Name the file to import, or - for standard input.', 'seoprostats'));
        }
        $text = '';
        if ($file === '-') {
            $text = (string) stream_get_contents(STDIN); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reads the list piped in.
        } elseif (is_readable($file) && is_file($file)) {
            $text = (string) file_get_contents($file, false, null, 0, SEOProStats_Targets::MAX_BYTES + 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the operator names.
        } else {
            /* translators: %s: file name */
            WP_CLI::error(sprintf(__('Cannot read %s.', 'seoprostats'), $file));
        }
        $replace = !empty($assoc['replace']);
        $done    = $this->on_data($assoc, static function () use ($text, $replace) {
            $parsed = SEOProStats_Targets::parse($text);
            if (is_wp_error($parsed)) {
                return $parsed;
            }
            $done = SEOProStats_Targets::import($parsed['rows'], $parsed['format'] === 'toon' ? 'aidevops' : 'list', $replace);
            return is_wp_error($done) ? $done : array('format' => $parsed['format']) + $done;
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($done, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        foreach ($done['skipped'] as $skip) {
            /* translators: 1: row number, 2: search query, 3: why it is skipped */
            WP_CLI::warning(sprintf(__('Row %1$d (%2$s) skipped: %3$s', 'seoprostats'), $skip['row'], $skip['query'] !== '' ? $skip['query'] : '–', $skip['message']));
        }
        /* translators: 1: format, 2: added, 3: updated, 4: deleted, 5: skipped, 6: total */
        WP_CLI::success(sprintf(__('Read %1$s: %2$d added, %3$d updated, %4$d deleted, %5$d skipped; %6$d targets.', 'seoprostats'), $done['format'], $done['added'], $done['updated'], $done['removed'], count($done['skipped']), $done['total']));
    }

    /**
     * Query coverage of one page: the Google Search Console queries it
     * shows for, each with how far the page's own words cover it (title,
     * heading, text, partial or none), the words it lacks and whether it
     * is a question; and the focus keywords of Rank Math, Yoast SEO,
     * SEOPress or All in One SEO when one is active. The period is cut at
     * the newest day with search data and to its newest 91 days.
     *
     * ## OPTIONS
     *
     * <page>
     * : A path such as /pricing/, or a post ID.
     *
     * [--missing]
     * : Only queries the page does not cover (partial or none).
     *
     * [--questions]
     * : Only questions.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 90d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats coverage /blog/speed-up-wordpress/
     *     wp seoprostats coverage 42 --missing
     *     wp seoprostats coverage /docs/faq/ --questions --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function coverage($args, $assoc) {
        $target = isset($args[0]) ? trim((string) $args[0]) : '';
        $post   = preg_match('/^[0-9]+$/', $target) ? (int) $target : 0;
        $page   = $post ? '' : $target;
        $req    = $this->request($assoc + array('range' => '90d', 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $page, $post) {
            return SEOProStats_Coverage::report($req, $page, $post);
        });
        $rows = array_values(array_filter($answer['rows'], static function ($row) use ($assoc) {
            return (empty($assoc['missing']) || in_array($row['match'], array('partial', 'none'), true)) && (empty($assoc['questions']) || $row['question']);
        }));
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode(array('rows' => $rows) + $answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$answer['connected']) {
            WP_CLI::warning(__('Google Search Console is not connected: wp seoprostats connect search-console --key-file=<file>.', 'seoprostats'));
        }
        $this->range_line($answer['range']);
        if (!$answer['text']) {
            WP_CLI::log(__('This page is not one post, so its words are not read: every query shows as not covered.', 'seoprostats'));
        }
        $totals = $answer['totals'];
        /* translators: 1: queries, 2: share of impressions covered, 3: queries not covered, 4: questions */
        WP_CLI::log(sprintf(__('%1$d queries; %2$s of impressions on queries the page covers; %3$d not covered; %4$d questions.', 'seoprostats'), $totals['queries'], sprintf('%.1f%%', $totals['covered'] * 100), $totals['missing'], $totals['questions']));
        foreach ($answer['focus'] as $focus) {
            /* translators: 1: focus keyword, 2: SEO plugin, 3: match, 4: impressions */
            WP_CLI::log(sprintf(__('Focus keyword "%1$s" (%2$s): %3$s on the page, %4$d impressions.', 'seoprostats'), $focus['keyword'], $focus['source'], $focus['match'], $focus['impressions']));
        }
        if (!$rows) {
            WP_CLI::line(__('No queries of this kind in this range.', 'seoprostats'));
            return;
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'query'       => $row['query'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
                'position'    => sprintf('%.1f', $row['position']),
                'match'       => $row['match'],
                'missing'     => implode(' ', $row['missing']),
                'question'    => $row['question'] ? 'yes' : '',
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $out, array_keys($out[0]));
    }

    /**
     * Content performance: per page, Google Search Console's clicks,
     * impressions, CTR and position with the visits from search that
     * landed on it (bounce rate, views per visit, time) and how many of
     * them reached a goal. The period is cut at the newest day with
     * search data.
     *
     * ## OPTIONS
     *
     * [--sort=<sort>]
     * : Order, most first.
     * ---
     * default: clicks
     * options:
     *   - clicks
     *   - visits
     *   - conversions
     * ---
     *
     * [--goal=<id>]
     * : ID of the goal counted (wp seoprostats goals); the first when left out.
     *
     * [--engine=<engine>]
     * : google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up) for the search figures.
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev or year.
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats (others do not apply to search data).
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats content
     *     wp seoprostats content --sort=conversions --range=90d
     *     wp seoprostats content --filter=page:/blog/* --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function content($args, $assoc) {
        $sort   = isset($assoc['sort']) ? (string) $assoc['sort'] : 'clicks';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $req    = $this->request($assoc + array('range' => '30d', 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $sort, $goal, $engine) {
            return SEOProStats_Content::report($req, $sort, $goal, $engine);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        if ($answer['partial']) {
            WP_CLI::log($answer['landings_from'] === ''
                ? __('Visits from search are still being summarised.', 'seoprostats')
                /* translators: %s: date */
                : sprintf(__('Visits from search are summarised from %s; older days are still being summarised.', 'seoprostats'), $answer['landings_from']));
        }
        if ($answer['ignored']) {
            /* translators: %s: filter dimensions. */
            WP_CLI::log(sprintf(__('Not applied to search data: %s.', 'seoprostats'), implode(', ', $answer['ignored'])));
        }
        $goal_name = $answer['goal'] ? $answer['goal']['name'] : '';
        if ($goal_name !== '') {
            /* translators: %s: goal name */
            WP_CLI::log(sprintf(__('Conversions: %s.', 'seoprostats'), $goal_name));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages with search clicks or visits from search in this range.', 'seoprostats'));
            return;
        }
        $pct  = static function ($value) {
            return sprintf('%.1f%%', (float) $value * 100);
        };
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $item = array(
                'path'        => $row['path'],
                'clicks'      => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr'         => $pct($row['ctr']),
                'position'    => $row['impressions'] ? sprintf('%.1f', $row['position']) : '–',
                'visits'      => $row['visits'],
                'bounce_rate' => $pct($row['bounce_rate']),
                'duration'    => $row['visit_duration'] . 's',
            );
            if ($goal_name !== '') {
                $item['conversions']     = $row['conversions'];
                $item['conversion_rate'] = $pct($row['conversion_rate']);
            }
            $rows[] = $item;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * The change log: posts published, unpublished and edited, SEO fields,
     * prices, stock and coupons, plugins, themes, WordPress, settings,
     * search engine updates and notes, newest first.
     *
     * ## OPTIONS
     *
     * [--page=<path>]
     * : Only changes to this page (* for any text), and the site-wide ones.
     *
     * [--kind=<kinds>]
     * : Only these kinds or groups (content, seo, product, site, search, note), comma-separated.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 50
     * ---
     *
     * [--offset=<offset>]
     * : Rows to skip.
     * ---
     * default: 0
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats changes
     *     wp seoprostats changes --page=/pricing/ --range=90d
     *     wp seoprostats changes --kind=product,plugin_updated --format=json
     *     wp seoprostats changes --kind=search --range=12mo
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function changes($args, $assoc) {
        unset($args);
        $req    = $this->request($assoc + array('range' => '30d'));
        $opts   = array(
            'page'   => isset($assoc['page']) ? (string) $assoc['page'] : '',
            'kinds'  => isset($assoc['kind']) ? (string) $assoc['kind'] : '',
            'limit'  => isset($assoc['limit']) ? (int) $assoc['limit'] : 50,
            'offset' => isset($assoc['offset']) ? (int) $assoc['offset'] : 0,
        );
        $answer = $this->on_data($assoc, static function () use ($req, $opts) {
            return SEOProStats_Changes::list_changes($req, $opts);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['changes']) {
            WP_CLI::line(__('No changes in this range.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($answer['changes'] as $change) {
            $rows[] = array(
                't'      => $change['t'],
                'kind'   => $change['kind'],
                'path'   => (string) $change['path'],
                'label'  => $change['label'],
                'source' => $change['source'],
                'user'   => (string) $change['user'],
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array('t', 'kind', 'path', 'label', 'source', 'user'));
        if ($answer['total'] > count($rows) + $opts['offset']) {
            /* translators: 1: changes shown, 2: changes in the range */
            WP_CLI::log(sprintf(__('%1$d of %2$d changes; see more with --offset.', 'seoprostats'), count($rows), $answer['total']));
        }
    }

    /**
     * Add a note to the timeline (something the change log cannot see,
     * such as a newsletter sent or a sale), or delete one.
     *
     * Notes show as markers on the charts and in the Changes section, and
     * in `wp seoprostats changes --kind=note`.
     *
     * ## OPTIONS
     *
     * [<note>]
     * : What happened (up to 190 characters).
     *
     * [--page=<path>]
     * : The page it is about; without it, the whole site.
     *
     * [--time=<when>]
     * : When, in the site time zone (2026-10-05 or "2026-10-05 14:30"); without it, now.
     *
     * [--delete=<id>]
     * : Delete this note instead (its id from `changes --kind=note --format=json`).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--porcelain]
     * : Print only the new note's id.
     *
     * ## EXAMPLES
     *
     *     wp seoprostats annotate "Newsletter sent"
     *     wp seoprostats annotate "Sale started" --page=/shop/ --time="2026-10-05 09:00"
     *     wp seoprostats annotate --delete=42
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function annotate($args, $assoc) {
        if (isset($assoc['delete'])) {
            $id      = (int) $assoc['delete'];
            $deleted = $this->on_data($assoc, static function () use ($id) {
                return SEOProStats_Changes::delete_note($id);
            });
            if (!$deleted) {
                /* translators: %d: note id */
                WP_CLI::error(sprintf(__('There is no note %d.', 'seoprostats'), $id));
            }
            /* translators: %d: note id */
            WP_CLI::success(sprintf(__('Note %d deleted.', 'seoprostats'), $id));
            return;
        }
        $note   = isset($args[0]) ? (string) $args[0] : '';
        $page   = isset($assoc['page']) ? (string) $assoc['page'] : '';
        $when   = isset($assoc['time']) ? (string) $assoc['time'] : '';
        $answer = $this->on_data($assoc, static function () use ($note, $page, $when) {
            return SEOProStats_Changes::annotate($note, $page, $when);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if (isset($assoc['porcelain'])) {
            WP_CLI::line((string) $answer['id']);
            return;
        }
        /* translators: 1: note id, 2: time, 3: page path or "the whole site" */
        WP_CLI::success(sprintf(__('Note %1$d added at %2$s, on %3$s.', 'seoprostats'), $answer['id'], $answer['t'], $answer['path'] !== null ? $answer['path'] : __('the whole site', 'seoprostats')));
    }

    /**
     * Experiments: a change and what it should do, measured before and
     * after against pages that were left alone, with the search engine
     * updates and other changes of the time named. The plugin suggests
     * keep, revise, undo or inconclusive; you decide.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, add, show, decide, cancel, note or delete.
     * ---
     * default: list
     * options:
     *   - list
     *   - add
     *   - show
     *   - decide
     *   - cancel
     *   - note
     *   - delete
     * ---
     *
     * [<id-or-name>]
     * : The experiment's id (show, decide, cancel, note, delete) or, for add, its name: the change and what it should do, in one line.
     *
     * [<result>]
     * : For decide: keep, revise, undo or inconclusive.
     *
     * [--change=<id>]
     * : For add: the change it measures (its id from `changes --format=json`); its time is the start and its page the page.
     *
     * [--start=<when>]
     * : For add without --change: when the change was made (2026-10-05 or "2026-10-05 14:30"); without it, now.
     *
     * [--page=<paths>]
     * : For add: its page, or several, comma-separated (up to 50). For list: only experiments on this page.
     *
     * [--days=<days>]
     * : For add: days in each window: 7, 14, 28, 56 or 84.
     * ---
     * default: 28
     * ---
     *
     * [--engine=<engine>]
     * : For add: google or bing.
     * ---
     * default: google
     * ---
     *
     * [--metric=<metric>]
     * : For add: clicks, impressions, ctr, position, visits or conversions.
     * ---
     * default: clicks
     * ---
     *
     * [--direction=<direction>]
     * : For add: up or down (for position, up means a better place).
     * ---
     * default: up
     * ---
     *
     * [--threshold=<n>]
     * : For add: the smallest change that counts: percent (default 10), or places for position (default 1).
     *
     * [--goal=<id>]
     * : For add with --metric=conversions: the goal's id.
     *
     * [--hypothesis=<text>]
     * : For add: the reasoning, in more words.
     *
     * [--note=<text>]
     * : A note: for add, decide, cancel and note.
     *
     * [--status=<status>]
     * : For list: running, due, decided or cancelled.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * [--porcelain]
     * : For add: print only the new experiment's id.
     *
     * ## EXAMPLES
     *
     *     wp seoprostats experiments
     *     wp seoprostats experiments add "Shorter title lifts CTR" --change=812 --metric=ctr
     *     wp seoprostats experiments add "Internal links lift the guide" --page=/guide/ --metric=position --start=2026-09-01
     *     wp seoprostats experiments show 3 --format=json
     *     wp seoprostats experiments decide 3 keep --note="CTR up 18% against 120 unchanged pages"
     *     wp seoprostats experiments list --status=due
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function experiments($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        $second = isset($args[1]) ? (string) $args[1] : '';
        $id     = (int) $second;
        if (in_array($action, array('show', 'decide', 'cancel', 'note', 'delete'), true) && $id <= 0) {
            WP_CLI::error(__('Give the experiment\'s id: wp seoprostats experiments list shows them.', 'seoprostats'));
        }
        if ($action === 'delete') {
            $deleted = $this->on_data($assoc, static function () use ($id) {
                return SEOProStats_Experiments::delete($id);
            });
            if (!$deleted) {
                /* translators: %d: experiment id */
                WP_CLI::error(sprintf(__('There is no experiment %d.', 'seoprostats'), $id));
            }
            /* translators: %d: experiment id */
            WP_CLI::success(sprintf(__('Experiment %d deleted.', 'seoprostats'), $id));
            return;
        }
        if ($action === 'list') {
            $opts   = array(
                'status' => isset($assoc['status']) ? (string) $assoc['status'] : '',
                'page'   => isset($assoc['page']) ? (string) $assoc['page'] : '',
            );
            $answer = $this->on_data($assoc, static function () use ($opts) {
                return SEOProStats_Experiments::list_experiments($opts);
            });
            if (is_wp_error($answer)) {
                WP_CLI::error($answer->get_error_message());
            }
            if ($this->format($assoc) === 'json') {
                WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            if (!$answer['experiments']) {
                WP_CLI::line(__('No experiments yet: wp seoprostats experiments add records one.', 'seoprostats'));
                return;
            }
            $rows = array_map(array($this, 'experiment_row'), $answer['experiments']);
            WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
            return;
        }

        $answer = $this->on_data($assoc, static function () use ($action, $second, $id, $args, $assoc) {
            if ($action === 'add') {
                return SEOProStats_Experiments::add(array(
                    'name'       => $second,
                    'change'     => isset($assoc['change']) ? (int) $assoc['change'] : 0,
                    'start'      => isset($assoc['start']) ? (string) $assoc['start'] : '',
                    'pages'      => isset($assoc['page']) ? (string) $assoc['page'] : '',
                    'days'       => isset($assoc['days']) ? (int) $assoc['days'] : SEOProStats_Experiments::DAYS,
                    'engine'     => isset($assoc['engine']) ? (string) $assoc['engine'] : 'google',
                    'metric'     => isset($assoc['metric']) ? (string) $assoc['metric'] : 'clicks',
                    'direction'  => isset($assoc['direction']) ? (string) $assoc['direction'] : 'up',
                    'threshold'  => isset($assoc['threshold']) ? (string) $assoc['threshold'] : '',
                    'goal'       => isset($assoc['goal']) ? (string) $assoc['goal'] : '',
                    'hypothesis' => isset($assoc['hypothesis']) ? (string) $assoc['hypothesis'] : '',
                    'note'       => isset($assoc['note']) ? (string) $assoc['note'] : '',
                ));
            }
            if ($action === 'show') {
                return SEOProStats_Experiments::get($id);
            }
            return SEOProStats_Experiments::update($id, array(
                'action' => $action,
                'result' => isset($args[2]) ? (string) $args[2] : '',
                'note'   => isset($assoc['note']) ? (string) $assoc['note'] : '',
            ));
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($action === 'add' && isset($assoc['porcelain'])) {
            WP_CLI::line((string) $answer['id']);
            return;
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->experiment_detail($answer);
        if ($action !== 'show') {
            /* translators: 1: experiment id, 2: its state */
            WP_CLI::success(sprintf(__('Experiment %1$d: %2$s.', 'seoprostats'), $answer['id'], $answer['result'] !== null ? $answer['status'] . ' (' . $answer['result'] . ')' : $answer['status']));
        }
    }

    /**
     * The decision queue: one ranked list of search work, made from
     * Opportunities (low CTR, missing from the page, striking distance,
     * losing clicks, overlapping pages), the content audit, internal links,
     * indexation, the refresh planner (update, leave, protect or merge a
     * page losing clicks) and search targets (shown with another page, or
     * high priority in striking distance). Each item says why it is listed and how its score is
     * made: potential clicks per 28 days × value (how well the page's
     * visits from search convert) × confidence ÷ effort. Pages with a
     * running experiment are left out. Done opens an experiment on the
     * page (not for a page left as it is); dismissed items stay hidden for 90 days.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, accept, done, dismiss, restore, effort or note.
     * ---
     * default: list
     * options:
     *   - list
     *   - accept
     *   - done
     *   - dismiss
     *   - restore
     *   - effort
     *   - note
     * ---
     *
     * [<key>]
     * : The item's key (from the list), for every action but list.
     *
     * [<effort>]
     * : For effort: 1 (least) to 5.
     *
     * [--status=<status>]
     * : For list: open (new and accepted), new, accepted, done, dismissed or all.
     * ---
     * default: open
     * ---
     *
     * [--kind=<kind>]
     * : For list: only items of this kind: ctr, missing, striking, decay, overlap, audit, links, index, refresh or target.
     *
     * [--engine=<engine>]
     * : google or bing.
     * ---
     * default: google
     * ---
     *
     * [--goal=<id>]
     * : The goal whose conversions give a page its value; the first goal when left out.
     *
     * [--range=<range>]
     * : The period the list is made from, as for stats (at most its newest 91 days are read).
     * ---
     * default: 90d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most items.
     * ---
     * default: 20
     * ---
     *
     * [--note=<text>]
     * : A note (up to 190 characters), with any action.
     *
     * [--name=<name>]
     * : For done: the experiment's name; without it, one made from the item.
     *
     * [--days=<days>]
     * : For done: days in each window of the experiment: 7, 14, 28, 56 or 84.
     * ---
     * default: 28
     * ---
     *
     * [--threshold=<n>]
     * : For done: the smallest change that counts: percent (default 10), or places for position (default 1).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats queue
     *     wp seoprostats queue --status=done --format=json
     *     wp seoprostats queue --kind=refresh --range=30d
     *     wp seoprostats queue accept 3f9c0a1b2d4e5f60
     *     wp seoprostats queue done 3f9c0a1b2d4e5f60 --note="New title and description"
     *     wp seoprostats queue effort 3f9c0a1b2d4e5f60 3
     *     wp seoprostats queue dismiss 3f9c0a1b2d4e5f60 --note="Brand query; not worth it"
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function queue($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        $key    = isset($args[1]) ? (string) $args[1] : '';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $req    = $this->request($assoc + array('range' => '90d', 'limit' => '20', 'compare' => 'none'));
        if ($action !== 'list') {
            if ($key === '') {
                WP_CLI::error(__('Give the item\'s key: wp seoprostats queue lists them.', 'seoprostats'));
            }
            $input  = array(
                'action'    => $action,
                'effort'    => isset($args[2]) ? (int) $args[2] : 0,
                'note'      => isset($assoc['note']) ? (string) $assoc['note'] : '',
                'name'      => isset($assoc['name']) ? (string) $assoc['name'] : '',
                'days'      => isset($assoc['days']) ? (int) $assoc['days'] : SEOProStats_Experiments::DAYS,
                'threshold' => isset($assoc['threshold']) ? (string) $assoc['threshold'] : '',
            );
            $answer = $this->on_data($assoc, static function () use ($key, $input, $req, $engine, $goal) {
                return SEOProStats_Queue::update($key, $input, $req, $engine, $goal);
            });
            if (is_wp_error($answer)) {
                WP_CLI::error($answer->get_error_message());
            }
            if ($this->format($assoc) === 'json') {
                WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            WP_CLI\Utils\format_items('table', array($this->queue_row($answer)), array_keys($this->queue_row($answer)));
            if ($answer['experiment']) {
                /* translators: 1: experiment id, 2: its name, 3: review day */
                WP_CLI::log(sprintf(__('Experiment %1$d: %2$s (review %3$s).', 'seoprostats'), $answer['experiment']['id'], $answer['experiment']['name'], $answer['experiment']['review']));
            }
            /* translators: 1: item key, 2: its state */
            WP_CLI::success(sprintf(__('Item %1$s: %2$s.', 'seoprostats'), $answer['key'], $answer['status']));
            return;
        }
        $status = isset($assoc['status']) ? (string) $assoc['status'] : 'open';
        $kind   = isset($assoc['kind']) ? (string) $assoc['kind'] : '';
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $status, $goal, $kind) {
            return SEOProStats_Queue::report($req, $engine, $status, $goal, $kind);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        /* translators: 1: new, 2: accepted, 3: done, 4: dismissed */
        WP_CLI::log(sprintf(__('New %1$d, accepted %2$d, done %3$d, dismissed %4$d.', 'seoprostats'), $answer['counts']['new'], $answer['counts']['accepted'], $answer['counts']['done'], $answer['counts']['dismissed']));
        if ($answer['left_out']) {
            /* translators: %d: items */
            WP_CLI::log(sprintf(_n('%d item left out: its page has a running experiment.', '%d items left out: their pages have a running experiment.', $answer['left_out'], 'seoprostats'), $answer['left_out']));
        }
        if (!$answer['items']) {
            WP_CLI::line(__('Nothing to do in this state for this period.', 'seoprostats'));
            return;
        }
        $rows = array_map(array($this, 'queue_row'), $answer['items']);
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
        WP_CLI::log(__('Score = potential clicks per 28 days × value × confidence ÷ effort. --format=json gives each item\'s why and figures.', 'seoprostats'));
    }

    /**
     * One queue item as a table row.
     *
     * @param array<string,mixed> $item Item.
     * @return array<string,string|int|float>
     */
    private function queue_row(array $item) {
        $exp = is_array($item['experiment']) ? $item['experiment'] : null;
        return array(
            'key'        => $item['key'],
            'status'     => $item['status'] . ($item['found'] ? '' : ' *'),
            // With the audit finding, links or indexation list, or refresh proposal.
            'kind'       => $item['kind'] . (isset($item['finding']) && $item['finding'] !== '' ? ': ' . $item['finding'] : ''),
            'page'       => $item['path'],
            'query'      => (string) $item['query'],
            'score'      => $item['score'],
            'clicks'     => $item['parts']['clicks'],
            'value'      => $item['parts']['value'],
            'confidence' => $item['parts']['confidence'],
            'effort'     => $item['parts']['effort'],
            'experiment' => $exp ? '#' . $exp['id'] . ' ' . ($exp['result'] !== null ? (string) $exp['result'] : ($exp['due'] ? 'due' : (string) $exp['status'])) . ($exp['suggested'] !== null && $exp['result'] === null ? ' (' . $exp['suggested'] . ')' : '') : '',
        );
    }

    /**
     * The loop export: one answer per cycle for an agent. The decision
     * queue's open items, the experiments due for review, running and
     * decided in the last 90 days with their results, and the period's
     * search figures per query and page in the aidevops export layout
     * (query, page, clicks, impressions, CTR, position). Steps for an
     * agent: docs/seo-loop-recipes.md.
     *
     * ## OPTIONS
     *
     * [--engine=<engine>]
     * : google or bing.
     * ---
     * default: google
     * ---
     *
     * [--goal=<id>]
     * : The goal whose conversions give a page its value in the queue; the first goal when left out.
     *
     * [--range=<range>]
     * : The period, as for stats (at most its newest 91 days are read).
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most queue items.
     * ---
     * default: 20
     * ---
     *
     * [--rows=<rows>]
     * : Most export rows (a search and a page each), most impressions first.
     * ---
     * default: 1000
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table (a summary), json (the whole answer, as GET /loop) or toon (only the export, as an aidevops export file).
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats loop
     *     wp seoprostats loop --format=json
     *     wp seoprostats loop --data=demo --format=json
     *     wp seoprostats loop --range=90d --rows=5000 --format=toon > gsc-export.toon
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function loop($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-loop.php';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $rows   = isset($assoc['rows']) ? (int) $assoc['rows'] : SEOProStats_Loop::ROWS;
        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        $req    = $this->request(array_diff_key($assoc, array('rows' => 1, 'format' => 1)) + array('range' => SEOProStats_Loop::RANGE, 'limit' => (string) SEOProStats_Loop::ITEMS, 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $goal, $rows) {
            return SEOProStats_Loop::report($req, $engine, $goal, $rows);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($format === 'toon') {
            WP_CLI::line(rtrim(SEOProStats_Loop::toon($answer['export']), "\n"));
            return;
        }
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        $sum = $answer['summary'];
        /* translators: 1: new, 2: accepted, 3: experiments due, 4: running, 5: decided lately, 6: days, 7: export rows */
        WP_CLI::log(sprintf(__('Queue: %1$d new, %2$d accepted. Experiments: %3$d due, %4$d running, %5$d decided in the last %6$d days. Export: %7$d rows.', 'seoprostats'), $sum['new'], $sum['accepted'], $sum['due'], $sum['running'], $sum['decided'], $answer['experiments']['recent_days'], $sum['rows']));
        if ($answer['experiments']['due']) {
            WP_CLI::log(__('Due for review (decide with: wp seoprostats experiments decide <id> <result>):', 'seoprostats'));
            $due = array_map(array($this, 'experiment_row'), $answer['experiments']['due']);
            WP_CLI\Utils\format_items('table', $due, array_keys($due[0]));
        }
        if ($answer['queue']['items']) {
            WP_CLI::log(__('Open queue items, best first:', 'seoprostats'));
            $items = array_map(array($this, 'queue_row'), $answer['queue']['items']);
            WP_CLI\Utils\format_items('table', $items, array_keys($items[0]));
        }
        WP_CLI::log(__('--format=json gives the whole answer (as GET /loop); --format=toon the export rows as an aidevops export file.', 'seoprostats'));
    }

    /**
     * One experiment as a table row.
     *
     * @param array<string,mixed> $item Experiment.
     * @return array<string,string|int>
     */
    private function experiment_row(array $item) {
        $m = is_array($item['measurement']) ? $item['measurement'] : array();
        return array(
            'id'        => $item['id'],
            'status'    => $item['due'] ? 'due' : $item['status'],
            'name'      => $item['name'],
            'pages'     => implode(', ', $item['pages']),
            'start'     => substr((string) $item['start'], 0, 10),
            'review'    => $item['review'],
            'metric'    => $item['metric'] . ' ' . $item['direction'],
            'effect'    => isset($m['effect']) ? self::effect_text($item['metric'], $m['effect']) : '',
            'suggested' => isset($m['suggested']) ? (string) $m['suggested'] : '',
            'result'    => (string) $item['result'],
        );
    }

    /**
     * Print one experiment and its measurement.
     *
     * @param array<string,mixed> $item Experiment.
     */
    private function experiment_detail(array $item) {
        $m = is_array($item['measurement']) ? $item['measurement'] : array();
        /* translators: 1: id, 2: name */
        WP_CLI::log(sprintf(__('Experiment %1$d: %2$s', 'seoprostats'), $item['id'], $item['name']));
        /* translators: 1: pages, 2: start, 3: measure, 4: direction, 5: threshold */
        WP_CLI::log(sprintf(__('Pages: %1$s. Start: %2$s. Expected: %3$s %4$s by at least %5$s.', 'seoprostats'), implode(', ', $item['pages']), $item['start'], $item['metric'], $item['direction'], $item['metric'] === 'position' ? sprintf(/* translators: %s: places, such as 1 or 1.5 */ _n('%s place', '%s places', (int) ceil((float) $item['threshold']), 'seoprostats'), $item['threshold']) : $item['threshold'] . '%'));
        if (!$m) {
            /* translators: %s: state */
            WP_CLI::log(sprintf(__('Status: %s.', 'seoprostats'), $item['status']));
            return;
        }
        /* translators: 1: before from, 2: before to, 3: after from, 4: after to, 5: review day */
        WP_CLI::log(sprintf(__('Before %1$s to %2$s; after %3$s to %4$s; review %5$s.', 'seoprostats'), $m['windows']['before']['from'], $m['windows']['before']['to'], $m['windows']['after']['from'], $m['windows']['after']['to'], $m['review']));
        if ($m['state'] === 'running') {
            /* translators: 1: days of data so far, 2: days needed, 3: newest day with data */
            WP_CLI::log(sprintf(__('Running: %1$d of %2$d days of data after the change (data through %3$s).', 'seoprostats'), $m['so_far'], $m['days'], (string) $m['through']));
            return;
        }
        $rows = array();
        foreach (array('pages', 'group') as $side) {
            foreach (array('before', 'after') as $when) {
                $rows[] = array('who' => $side, 'when' => $when, 'pages' => $m[$side]['count']) + array_map(static function ($v) {
                    return $v === null ? '–' : $v;
                }, $m[$side][$when]);
            }
        }
        WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
        WP_CLI::log((string) $m['summary']);
        if ($m['noise']) {
            /* translators: 1: low, 2: high, 3: pages */
            WP_CLI::log(sprintf(__('Usual spread of unchanged pages: %1$s to %2$s (%3$d pages).', 'seoprostats'), self::effect_text($item['metric'], $m['noise']['low']), self::effect_text($item['metric'], $m['noise']['high']), $m['noise']['pages']));
        }
        foreach (array('updates' => __('Search engine updates', 'seoprostats'), 'site' => __('Site-wide changes', 'seoprostats'), 'pages' => __('Other changes on its pages', 'seoprostats')) as $key => $label) {
            if ($m['confounders'][$key]) {
                WP_CLI::log($label . ': ' . implode('; ', array_map(static function ($c) {
                    return substr((string) $c['t'], 0, 10) . ' ' . $c['label'];
                }, $m['confounders'][$key])));
            }
        }
        /* translators: 1: suggested result, 2: reasons */
        WP_CLI::log(sprintf(__('Suggested: %1$s (%2$s).', 'seoprostats'), $m['suggested'], implode(', ', $m['reasons'])));
        if ($item['note'] !== '') {
            /* translators: %s: note */
            WP_CLI::log(sprintf(__('Note: %s', 'seoprostats'), $item['note']));
        }
    }

    /**
     * An effect as text: places up or down for position (the JSON keeps
     * the change in position, lower is better), else percent.
     *
     * @param string     $metric Metric name.
     * @param float|null $effect Effect.
     * @return string
     */
    private static function effect_text($metric, $effect) {
        if ($effect === null) {
            return '–';
        }
        return $metric === 'position' ? SEOProStats_Experiments::places_text($effect) : sprintf('%+.1f%%', $effect * 100);
    }

    /**
     * Run a definition action (list, add, update, delete) for goals or
     * funnels on the --data set; exits on an error.
     *
     * @param string               $type   goals or funnels.
     * @param string               $action list, add, update or delete.
     * @param string               $id     Id, for update and delete.
     * @param array<string,string> $assoc  Options.
     * @param callable             $save   Takes the id ('' to add); saves.
     */
    private function define($type, $action, $id, array $assoc, callable $save) {
        if (in_array($action, array('update', 'delete'), true) && $id === '') {
            /* translators: %s: goals or funnels */
            WP_CLI::error(sprintf(__('Give the id: wp seoprostats %s list shows them.', 'seoprostats'), $type));
        }
        $answer = $this->on_data($assoc, static function () use ($type, $action, $id, $save) {
            if ($action === 'list') {
                return $type === 'funnels' ? SEOProStats_Goals::funnels() : SEOProStats_Goals::goals();
            }
            if ($action === 'delete') {
                return SEOProStats_Goals::delete($type, $id) ? true : new WP_Error('seoprostats_not_found', __('There is no such goal or funnel.', 'seoprostats'));
            }
            return $save($action === 'update' ? $id : '');
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($action === 'delete') {
            WP_CLI::success(__('Deleted.', 'seoprostats'));
            return;
        }
        if ($action === 'list') {
            if ($this->format($assoc) === 'json') {
                WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }
            $items = array();
            foreach ((array) $answer as $item) {
                if (isset($item['steps'])) {
                    $item['steps'] = implode(' → ', array_map(static function ($step) {
                        return $step['kind'] . ':' . $step['match'];
                    }, $item['steps']));
                }
                $items[] = $item;
            }
            if (!$items) {
                WP_CLI::line(__('None yet.', 'seoprostats'));
                return;
            }
            WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
            return;
        }
        WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success($action === 'add' ? __('Added.', 'seoprostats') : __('Updated.', 'seoprostats'));
    }

    /**
     * Funnel steps from "kind:match;kind:match" or a JSON list.
     *
     * @param string $text Steps.
     * @return array<int,array<string,string>>
     */
    private static function steps($text) {
        $text = trim($text);
        if ($text !== '' && $text[0] === '[') {
            $json = json_decode($text, true);
            return is_array($json) ? $json : array();
        }
        $steps = array();
        foreach (array_filter(array_map('trim', explode(';', $text))) as $part) {
            $pair    = explode(':', $part, 2);
            $steps[] = array('kind' => $pair[0], 'match' => isset($pair[1]) ? $pair[1] : '');
        }
        return $steps;
    }

    /**
     * Revenue per currency as text: "USD 1234.00; GBP 99.00".
     *
     * @param array<int,array{currency:string,amount:float}> $money Revenue.
     * @return string
     */
    private static function money_text(array $money) {
        return implode('; ', array_map(static function ($row) {
            return sprintf('%s %.2f', $row['currency'], $row['amount']);
        }, $money));
    }

    /**
     * Visitors on the site in the last 30 minutes.
     *
     * ## OPTIONS
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function realtime($args, $assoc) {
        $answer = $this->on_data($assoc, array('SEOProStats_Query', 'realtime'));
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        /* translators: 1: visitors, 2: pageviews */
        WP_CLI::line(sprintf(__('%1$d visitors and %2$d pageviews in the last 30 minutes.', 'seoprostats'), $answer['visitors'], $answer['pageviews']));
        if ($answer['pages']) {
            WP_CLI\Utils\format_items('table', $answer['pages'], array('label', 'count'));
        }
    }

    /**
     * Process buffered hits now, instead of waiting for the minute cron.
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function process($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-processor.php';
        $start  = microtime(true);
        $totals = SEOProStats_Processor::run();
        WP_CLI::success(sprintf(
            /* translators: 1: lines, 2: pageviews, 3: events, 4: clicks and form submits, 5: bot hits, 6: skipped hits, 7: milliseconds */
            __('%1$d lines: %2$d pageviews, %3$d events, %4$d clicks, %5$d bot hits dropped, %6$d skipped, in %7$d ms.', 'seoprostats'),
            $totals['lines'],
            $totals['pageviews'],
            $totals['events'],
            $totals['clicks'],
            $totals['bots'],
            $totals['skipped'],
            (int) round((microtime(true) - $start) * 1000)
        ));
    }

    /**
     * Summarise finished days into the daily summaries now, instead of
     * waiting for the minute cron, or rebuild given days.
     *
     * A day is summarised an hour after it ends, once every hit received
     * by then is processed. Reports read the summaries for whole days.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : Rebuild days from this one (YYYY-MM-DD), with --to.
     *
     * [--to=<date>]
     * : Last day to rebuild. Days must be over, and not past their retention.
     *
     * ## EXAMPLES
     *
     *     wp seoprostats rollup
     *     wp seoprostats rollup --from=2026-10-01 --to=2026-10-05
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function rollup($args, $assoc) {
        $this->need_tables();
        $from = isset($assoc['from']) ? (string) $assoc['from'] : '';
        $to   = isset($assoc['to']) ? (string) $assoc['to'] : '';
        if ($from !== '' || $to !== '') {
            $tz    = wp_timezone();
            $first = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
            $last  = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
            if (!$first || !$last || $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to || $first > $last) {
                WP_CLI::error(__('Give --from and --to as dates (YYYY-MM-DD), from not after to.', 'seoprostats'));
                return;
            }
            if ($last >= new DateTimeImmutable('today', $tz)) {
                WP_CLI::error(__('Only days that are over can be summarised.', 'seoprostats'));
                return;
            }
            $built = 0;
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                if (SEOProStats_Rollup::summarise($day)) {
                    $built++;
                } else {
                    /* translators: %s: a date */
                    WP_CLI::warning(sprintf(__('%s not rebuilt: its visits are past their retention, or a query failed.', 'seoprostats'), $day->format('Y-m-d')));
                }
            }
            /* translators: %d: number of days */
            WP_CLI::success(sprintf(_n('%d day rebuilt.', '%d days rebuilt.', $built, 'seoprostats'), $built));
            return;
        }

        $days = 0;
        do {
            $done  = SEOProStats_Rollup::catch_up(microtime(true));
            $days += $done;
        } while ($done > 0);
        $through = SEOProStats_Rollup::through();
        WP_CLI::success(sprintf(
            /* translators: 1: number of days, 2: a date or "none yet" */
            _n('%1$d day summarised; summaries through %2$s.', '%1$d days summarised; summaries through %2$s.', $days, 'seoprostats'),
            $days,
            $through !== '' ? $through : __('none yet', 'seoprostats')
        ));
    }

    /**
     * Delete visits, pageviews and events past their retention now (75
     * and 120 months by default; SEO Pro Stats → Settings → Data), and
     * imported search data by page and query past its own (25 months).
     * Daily summaries and search totals are kept, and no visit newer than
     * the last summarised day goes.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Count the rows that would be deleted, and delete nothing.
     *
     * ## EXAMPLES
     *
     *     wp seoprostats prune --dry-run
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function prune($args, $assoc) {
        $this->need_tables();
        $months = SEOProStats_Rollup::retention();
        /* translators: 1: months visits are kept, 2: months events are kept, 3: months search data by page and query is kept (0: forever) */
        WP_CLI::log(sprintf(__('Retention: visits %1$d months, events %2$d months, search data %3$d months (0: forever; SEO Pro Stats → Settings → Data).', 'seoprostats'), $months['visits'], $months['events'], $months['search']));
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-connections.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        // Visits and events go only once their days are summarised; search data by its own retention.
        $summarised = SEOProStats_Rollup::through() !== '';
        if (!$summarised) {
            WP_CLI::log(__('No day is summarised yet, so no visits or events are deleted.', 'seoprostats'));
        }
        if (!empty($assoc['dry-run'])) {
            $items = array();
            foreach (($summarised ? SEOProStats_Rollup::prune_counts() : array()) + SEOProStats_Search_Import::prune_counts() as $table => $rows) {
                $items[] = array('table' => $table, 'rows' => $rows);
            }
            if ($items) {
                WP_CLI\Utils\format_items('table', $items, array('table', 'rows'));
            }
            WP_CLI::success(__('Dry run: nothing deleted.', 'seoprostats'));
            return;
        }
        $deleted = 0;
        while ($summarised) {
            $done     = SEOProStats_Rollup::prune(microtime(true));
            $deleted += $done['deleted'];
            if ($done['done']) {
                break;
            }
        }
        delete_option(SEOProStats_Search_Import::PRUNED_OPTION);
        $deleted += SEOProStats_Search_Import::prune(microtime(true));
        /* translators: %d: number of rows */
        WP_CLI::success(sprintf(_n('%d row deleted.', '%d rows deleted.', $deleted, 'seoprostats'), $deleted));
    }

    /**
     * Stop when the tables are older than the plugin (an admin page
     * upgrades them).
     */
    private function need_tables() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        if (!SEOProStats_Schema::is_current()) {
            WP_CLI::error(__('The tables need an upgrade: open any admin page, then try again.', 'seoprostats'));
        }
    }

    /**
     * Search engine updates: fetch them now, or show the last fetch.
     *
     * Fetching asks Google's Search Status Dashboard and the other feeds
     * set under Settings → Data, once each, and adds what is new to the
     * change log; the daily job does the same while the setting is on.
     *
     * ## OPTIONS
     *
     * <action>
     * : fetch (now, even with the setting off) or status.
     * ---
     * options:
     *   - fetch
     *   - status
     * ---
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats search-updates fetch
     *     wp seoprostats changes --kind=search_update --range=12mo
     *
     * @subcommand search-updates
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function search_updates($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-updates.php';
        $state = $args[0] === 'fetch' ? SEOProStats_Search_Updates::run(true) : SEOProStats_Search_Updates::state();
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($state + array('enabled' => SEOProStats_Statistics::search_updates()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if (!SEOProStats_Statistics::search_updates()) {
            WP_CLI::log(__('Search engine updates are off (SEO Pro Stats → Settings → Data): the daily job fetches nothing.', 'seoprostats'));
        }
        if (!$state['sources']) {
            WP_CLI::log(__('Not fetched yet.', 'seoprostats'));
            return;
        }
        $rows = array();
        foreach ($state['sources'] as $source) {
            $rows[] = array(
                'source'  => (string) $source['name'],
                'status'  => !empty($source['ok']) ? 'ok' : 'fail',
                'entries' => (int) $source['entries'],
                'added'   => (int) $source['added'],
                'updated' => (int) $source['updated'],
                'when'    => human_time_diff((int) $source['at']) . ' ago',
                'detail'  => !empty($source['ok']) ? (string) $source['url'] : (string) $source['error'],
            );
        }
        WP_CLI\Utils\format_items('table', $rows, array('source', 'status', 'entries', 'added', 'updated', 'when', 'detail'));
        $failed = array_filter($rows, static function ($row) {
            return $row['status'] === 'fail';
        });
        if ($args[0] === 'fetch' && $failed) {
            /* translators: %d: number of sources */
            WP_CLI::warning(sprintf(_n('%d source failed; the daily job asks again tomorrow.', '%d sources failed; the daily job asks again tomorrow.', count($failed), 'seoprostats'), count($failed)));
        }
    }

    /**
     * Connect an outside data source, or change its property.
     *
     * Search Console: make a service account in Google Cloud, give it a
     * JSON key, and add its address as a user of the Search Console
     * property (Settings → Users and permissions; Restricted is enough).
     *
     * Bing Webmaster Tools: verify the site there, then copy the API key
     * from Settings → API access.
     *
     * The key is stored encrypted; the import of the 16 months each keeps
     * starts in cron straight after.
     *
     * ## OPTIONS
     *
     * <source>
     * : The source.
     * ---
     * options:
     *   - search-console
     *   - bing
     * ---
     *
     * [--key-file=<file>]
     * : A file with the key (Search Console: the service account's JSON
     * key; Bing: the API key); - reads it from standard input. Without
     * it, the saved key is kept (to change the property or site).
     *
     * [--property=<property>]
     * : Search Console: the property to import (https://example.com/ or
     * sc-domain:example.com); Bing: the site (https://example.com/).
     * Without it, the one for this site.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats connect search-console --key-file=service-account.json
     *     wp seoprostats connect search-console --property=sc-domain:example.com
     *     wp seoprostats connect bing --key-file=- < bing-api-key.txt
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function connect($args, $assoc) {
        $this->load_connections();
        $key = '';
        if (isset($assoc['key-file'])) {
            $file = (string) $assoc['key-file'];
            if ($file === '-') {
                $key = (string) stream_get_contents(STDIN); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reads the key piped in.
            } elseif (is_readable($file)) {
                $key = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the operator names.
            } else {
                /* translators: %s: file name */
                WP_CLI::error(sprintf(__('Cannot read %s.', 'seoprostats'), $file));
            }
        }
        $status = SEOProStats_Connections::connect($args[0], array(
            'key'      => $key,
            'property' => isset($assoc['property']) ? (string) $assoc['property'] : '',
        ));
        if (is_wp_error($status)) {
            $data = $status->get_error_data();
            if (is_array($data) && !empty($data['account'])) {
                /* translators: %s: service account address */
                WP_CLI::log(sprintf(__('Service account: %s', 'seoprostats'), $data['account']));
            }
            if (is_array($data) && !empty($data['properties'])) {
                WP_CLI::log($args[0] === 'bing' ? __('Verified sites of the key:', 'seoprostats') : __('Properties it can read:', 'seoprostats'));
                foreach ($data['properties'] as $property) {
                    WP_CLI::log('  ' . $property);
                }
            }
            WP_CLI::error($status->get_error_message());
            return;
        }
        $this->connection_status($status, $assoc);
        if ($this->format($assoc) !== 'json') {
            /* translators: 1: source name, 2: source key */
            WP_CLI::success(sprintf(__('%1$s is connected. The import runs in cron; wp seoprostats %2$s import runs it now.', 'seoprostats'), $status['name'], $args[0]));
        }
    }

    /**
     * Disconnect an outside data source: forget its credentials. Its
     * imported data stays unless --delete-data is given.
     *
     * ## OPTIONS
     *
     * <source>
     * : The source.
     * ---
     * options:
     *   - search-console
     *   - bing
     * ---
     *
     * [--delete-data]
     * : Also delete the data imported from it.
     *
     * [--yes]
     * : Do not ask before deleting data.
     *
     * ## EXAMPLES
     *
     *     wp seoprostats disconnect search-console
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function disconnect($args, $assoc) {
        $this->load_connections();
        $delete = !empty($assoc['delete-data']);
        if ($delete) {
            WP_CLI::confirm(__('Delete the search data imported from it?', 'seoprostats'), $assoc);
        }
        $status = SEOProStats_Connections::disconnect($args[0], $delete);
        if (is_wp_error($status)) {
            WP_CLI::error($status->get_error_message());
            return;
        }
        /* translators: 1: source name, 2: number of rows */
        WP_CLI::success($delete ? sprintf(__('%1$s is disconnected; %2$d rows deleted.', 'seoprostats'), $status['name'], $status['deleted']) : sprintf(__('%s is disconnected; its imported data stays.', 'seoprostats'), $status['name']));
    }

    /**
     * Search Console imports: show the status, import now, list imports,
     * undo one, or import days again.
     *
     * The job imports each day once Search Console marks it final (about
     * three days later), and on connecting the 16 months it keeps, newest
     * first, a minute apart in cron. `import` does the same now, until it
     * is done.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, import, imports (the last 20), undo (one import, by --id)
     * or reimport (--from and --to).
     * ---
     * options:
     *   - status
     *   - import
     *   - imports
     *   - undo
     *   - reimport
     * ---
     *
     * [--id=<id>]
     * : The import to undo.
     *
     * [--from=<day>]
     * : First day to import again (Y-m-d).
     *
     * [--to=<day>]
     * : Last day to import again (Y-m-d).
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats search-console status
     *     wp seoprostats search-console import
     *     wp seoprostats search-console undo --id=12
     *     wp seoprostats search-console reimport --from=2026-09-01 --to=2026-09-07
     *
     * @subcommand search-console
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function search_console($args, $assoc) {
        $this->imports_command('search-console', $args, $assoc);
    }

    /**
     * Bing Webmaster Tools imports: show the status, import now, list
     * imports, undo one, or import days again.
     *
     * Bing gives the site's clicks and impressions by day, and its top
     * pages and queries by week (each week's figures are stored on its
     * last day). A day is imported once its week is in, about a week
     * later; on connecting, the 16 months Bing keeps. Then each page's
     * queries, one request a page. `import` does all of it now.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, import, imports (the last 20), undo (one import, by --id)
     * or reimport (--from and --to).
     * ---
     * options:
     *   - status
     *   - import
     *   - imports
     *   - undo
     *   - reimport
     * ---
     *
     * [--id=<id>]
     * : The import to undo.
     *
     * [--from=<day>]
     * : First day to import again (Y-m-d).
     *
     * [--to=<day>]
     * : Last day to import again (Y-m-d).
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats bing status
     *     wp seoprostats bing import
     *     wp seoprostats bing undo --id=12
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function bing($args, $assoc) {
        $this->imports_command('bing', $args, $assoc);
    }

    /**
     * Move from another statistics plugin: list the plugins found, import
     * one's history (or see what an import would do), list imports, undo
     * one, or remove what the plugin left behind.
     *
     * An import fills only days before SEO Pro Stats's own first day and
     * not filled by another import, so running it again adds nothing. It
     * also carries over the plugin's settings that are still at our
     * default. cleanup lists exactly what the plugin left on this site
     * and, with --yes, deletes it; it is refused while the plugin is
     * active, and cannot be undone.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, run, imports (the last 20), undo (by --id) or cleanup.
     * ---
     * options:
     *   - list
     *   - run
     *   - imports
     *   - undo
     *   - cleanup
     * ---
     *
     * [<source>]
     * : The plugin, for run and cleanup: burst-statistics.
     *
     * [--dry-run]
     * : run: only say what it would do (days, rows, overlap, settings). cleanup: only list (the default without --yes).
     *
     * [--prefer=<source>]
     * : run: when another plugin not imported yet has statistics on the same days, the one whose counts fill them (it imports first).
     *
     * [--from=<day>]
     * : run: first day (Y-m-d).
     *
     * [--to=<day>]
     * : run: last day (Y-m-d).
     *
     * [--id=<id>]
     * : undo: the import.
     *
     * [--yes]
     * : cleanup: delete the list without asking.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats migrate list
     *     wp seoprostats migrate run burst-statistics --dry-run
     *     wp seoprostats migrate run burst-statistics
     *     wp seoprostats migrate undo --id=12
     *     wp seoprostats migrate cleanup burst-statistics --dry-run
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function migrate($args, $assoc) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-migrate.php';
        $this->need_tables();
        $action = $args[0];
        $source = isset($args[1]) ? (string) $args[1] : '';
        $json   = $this->format($assoc) === 'json';
        $print  = function ($data) {
            WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        };
        if (in_array($action, array('run', 'cleanup'), true) && $source === '') {
            WP_CLI::error(__('Name the plugin: wp seoprostats migrate list shows them.', 'seoprostats'));
        }

        if ($action === 'list') {
            $status = SEOProStats_Migrate::status(true);
            if ($json) {
                $print($status);
                return;
            }
            if (!$status['sources']) {
                WP_CLI::log(__('No statistics plugin\'s data found on this site.', 'seoprostats'));
                return;
            }
            $rows = array();
            foreach ($status['sources'] as $found) {
                $rows[] = array(
                    'source'    => $found['key'],
                    'name'      => $found['name'],
                    'version'   => $found['version'],
                    'plugin'    => $found['plugin']['state'],
                    'from'      => $found['from'],
                    'to'        => $found['to'],
                    'days'      => $found['days'],
                    'leftovers' => $found['leftovers'] ? 'yes' : ($found['plugin']['state'] === 'active' || $found['plugin']['state'] === 'network' ? 'while active: no' : 'no'),
                );
            }
            WP_CLI\Utils\format_items('table', $rows, array('source', 'name', 'version', 'plugin', 'from', 'to', 'days', 'leftovers'));
            /* translators: %s: day */
            WP_CLI::log(sprintf(__('SEO Pro Stats\'s own days start %s; imports fill only days before it.', 'seoprostats'), $status['own_from']));
            return;
        }

        if ($action === 'imports') {
            $rows = SEOProStats_Migrate::imports();
            if ($json) {
                $print($rows);
                return;
            }
            if (!$rows) {
                WP_CLI::log(__('No imports yet.', 'seoprostats'));
                return;
            }
            foreach ($rows as &$row) {
                $row['check'] = isset($row['check']['source']['pageviews']) ? sprintf('%d / %d pageviews', $row['check']['imported']['pageviews'], $row['check']['source']['pageviews']) : '';
            }
            unset($row);
            WP_CLI\Utils\format_items('table', $rows, array('id', 'source', 'status', 'from', 'to', 'days', 'rows', 'check', 'error'));
            return;
        }

        if ($action === 'undo') {
            if (empty($assoc['id'])) {
                WP_CLI::error(__('Give the import with --id (wp seoprostats migrate imports lists them).', 'seoprostats'));
            }
            $deleted = SEOProStats_Migrate::undo((int) $assoc['id']);
            if (is_wp_error($deleted)) {
                WP_CLI::error($deleted->get_error_message());
                return;
            }
            /* translators: 1: import ID, 2: number of rows */
            WP_CLI::success(sprintf(__('Import %1$d undone: %2$d rows deleted. Settings it carried over stay; run the import again to bring the days back.', 'seoprostats'), (int) $assoc['id'], $deleted));
            return;
        }

        if ($action === 'cleanup') {
            $dry    = !empty($assoc['dry-run']) || empty($assoc['yes']);
            $result = SEOProStats_Migrate::cleanup($source, true);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
                return;
            }
            if ($json && $dry) {
                $print($result);
                return;
            }
            $this->leftovers_table($result['leftovers']);
            if ($dry) {
                if (empty($assoc['dry-run'])) {
                    /* translators: %s: source key */
                    WP_CLI::log(sprintf(__('Nothing deleted. Back up the database, then: wp seoprostats migrate cleanup %s --yes', 'seoprostats'), $source));
                }
                return;
            }
            $result = SEOProStats_Migrate::cleanup($source, false);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
                return;
            }
            if ($json) {
                $print($result);
                return;
            }
            $removed = array();
            foreach ($result['removed'] as $kind => $count) {
                $removed[] = $kind . ': ' . $count;
            }
            /* translators: 1: plugin name, 2: counts */
            WP_CLI::success(sprintf(__('Leftover data of %1$s removed (%2$s). Imported days stay.', 'seoprostats'), $result['name'], implode(', ', $removed)));
            return;
        }

        $run = array(
            'from'   => isset($assoc['from']) ? (string) $assoc['from'] : '',
            'to'     => isset($assoc['to']) ? (string) $assoc['to'] : '',
            'prefer' => isset($assoc['prefer']) ? (string) $assoc['prefer'] : '',
        );
        if (!empty($assoc['dry-run'])) {
            $plan = SEOProStats_Migrate::plan($source, $run);
            if (is_wp_error($plan)) {
                WP_CLI::error($plan->get_error_message());
                return;
            }
            if ($json) {
                $print($plan);
                return;
            }
            $this->migrate_plan($plan);
            return;
        }
        $job = SEOProStats_Migrate::run($source, $run, function ($job) use ($json) {
            if (!$json) {
                foreach ($job['queue'] as $item) {
                    if ($item['total'] && $item['done'] < $item['total']) {
                        /* translators: 1: plugin name, 2: days done, 3: days */
                        WP_CLI::log(sprintf(__('%1$s: %2$d of %3$d days', 'seoprostats'), $item['name'], $item['done'], $item['total']));
                    }
                }
            }
        });
        if (is_wp_error($job)) {
            WP_CLI::error($job->get_error_message());
            return;
        }
        if (!empty($job['locked'])) {
            WP_CLI::error(__('Another request is running this import. Check with wp seoprostats migrate imports.', 'seoprostats'));
        }
        $ids     = wp_list_pluck($job['queue'], 'id');
        $imports = array_values(array_filter(SEOProStats_Migrate::imports(), function ($row) use ($ids) {
            return in_array($row['id'], $ids, true);
        }));
        if ($json) {
            $print(array('job' => $job, 'imports' => $imports));
            return;
        }
        foreach (array_reverse($imports) as $row) {
            /* translators: 1: plugin name, 2: days, 3: rows, 4: import ID */
            WP_CLI::success(sprintf(__('%1$s: %2$d days imported, %3$d rows (import %4$d).', 'seoprostats'), $row['name'], $row['days'], $row['rows'], $row['id']));
            if (!empty($row['check']['source'])) {
                $check = array();
                foreach (array('pageviews', 'visits', 'visitors') as $metric) {
                    $check[] = array('metric' => $metric, 'plugin' => $row['check']['source'][$metric], 'imported' => $row['check']['imported'][$metric]);
                }
                WP_CLI\Utils\format_items('table', $check, array('metric', 'plugin', 'imported'));
            }
            foreach ((array) $row['settings'] as $key => $change) {
                /* translators: 1: setting key, 2: before, 3: after */
                WP_CLI::log(sprintf(__('Setting %1$s: %2$s → %3$s', 'seoprostats'), $key, $change[0], $change[1]));
            }
            if ($row['error'] !== '') {
                WP_CLI::warning($row['error']);
            }
        }
    }

    /**
     * Print a dry run.
     *
     * @param array<string,mixed> $plan SEOProStats_Migrate::plan().
     */
    private function migrate_plan(array $plan) {
        $skipped = array();
        foreach ($plan['skipped']['imported'] as $by => $count) {
            /* translators: 1: days, 2: source key */
            $skipped[] = sprintf(__('%1$d already imported from %2$s', 'seoprostats'), $count, $by);
        }
        $rows = array(
            array('field' => 'plugin', 'value' => $plan['name'] . ' ' . $plan['version'] . ' (' . $plan['plugin']['state'] . ')'),
            array('field' => 'statistics', 'value' => sprintf('%s – %s, %d days', $plan['from'], $plan['to'], $plan['days'])),
            array('field' => 'own days from', 'value' => $plan['own_from'] . sprintf(' (%d days skipped)', $plan['skipped']['own'])),
            array('field' => 'imported before', 'value' => $skipped ? implode('; ', $skipped) : 'none'),
            array('field' => 'would import', 'value' => $plan['import']['days'] ? sprintf('%s – %s, %d days', $plan['import']['from'], $plan['import']['to'], $plan['import']['days']) : 'nothing'),
            array('field' => 'its counts', 'value' => sprintf('%d pageviews, %d visits, %d visitors', $plan['totals']['pageviews'], $plan['totals']['visits'], $plan['totals']['visitors'])),
        );
        foreach ($plan['rows'] as $dimension => $count) {
            $rows[] = array('field' => 'rows: ' . $dimension, 'value' => '~' . $count);
        }
        foreach ($plan['overlap'] as $overlap) {
            $rows[] = array('field' => 'shares days with', 'value' => sprintf('%s: %s – %s, %d days; suggested --prefer=%s', $overlap['name'], $overlap['from'], $overlap['to'], $overlap['days'], $overlap['suggested']));
        }
        foreach ($plan['settings'] as $setting) {
            $rows[] = array('field' => 'setting ' . $setting['key'], 'value' => sprintf('%s → %s (%s)', $setting['now'], $setting['to'], $setting['change'] ? 'would change' : ($setting['reason'] === 'same' ? 'already so' : 'kept: changed from the default')));
        }
        WP_CLI\Utils\format_items('table', $rows, array('field', 'value'));
        WP_CLI::log(__('Dry run: nothing written.', 'seoprostats'));
    }

    /**
     * Print a leftovers list.
     *
     * @param array<string,mixed> $list SEOProStats_Migrate_Source::leftovers().
     */
    private function leftovers_table(array $list) {
        $rows = array();
        foreach ($list as $kind => $items) {
            if ($kind === 'network') {
                foreach ((array) $items as $network_kind => $names) {
                    foreach ((array) $names as $name) {
                        $rows[] = array('kind' => 'network ' . $network_kind . ' (kept)', 'name' => $name);
                    }
                }
                continue;
            }
            foreach ((array) $items as $name) {
                $rows[] = array('kind' => $kind, 'name' => $name);
            }
        }
        if (!$rows) {
            WP_CLI::log(__('Nothing left behind.', 'seoprostats'));
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, array('kind', 'name'));
    }

    /**
     * A source's imports subcommand.
     *
     * @param string               $source Source key.
     * @param string[]             $args   Positional arguments.
     * @param array<string,string> $assoc  Options.
     */
    private function imports_command($source, $args, $assoc) {
        $this->load_connections();
        $this->need_tables();
        $action = $args[0];
        if ($action === 'imports') {
            $rows = SEOProStats_Search_Import::imports($source, 20);
            if ($this->format($assoc) === 'json') {
                WP_CLI::line((string) wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            if (!$rows) {
                WP_CLI::log(__('No imports yet.', 'seoprostats'));
                return;
            }
            WP_CLI\Utils\format_items('table', $rows, array('id', 'status', 'from', 'to', 'days', 'rows', 'error'));
            return;
        }
        if ($action === 'undo') {
            if (empty($assoc['id'])) {
                /* translators: %s: source key */
                WP_CLI::error(sprintf(__('Give the import with --id (wp seoprostats %s imports lists them).', 'seoprostats'), $source));
            }
            $deleted = SEOProStats_Search_Import::undo((int) $assoc['id']);
            if (is_wp_error($deleted)) {
                WP_CLI::error($deleted->get_error_message());
                return;
            }
            /* translators: 1: import ID, 2: number of rows, 3: source key */
            WP_CLI::success(sprintf(__('Import %1$d undone: %2$d rows deleted. wp seoprostats %3$s reimport brings its days back.', 'seoprostats'), (int) $assoc['id'], $deleted, $source));
            return;
        }
        if (!SEOProStats_Connections::get($source)) {
            /* translators: 1: source name, 2: source key */
            WP_CLI::error(sprintf(__('%1$s is not connected: wp seoprostats connect %2$s --key-file=<file>.', 'seoprostats'), SEOProStats_Connections::status($source)['name'], $source));
        }
        if ($action === 'reimport') {
            $from = isset($assoc['from']) ? (string) $assoc['from'] : '';
            $to   = isset($assoc['to']) ? (string) $assoc['to'] : $from;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
                WP_CLI::error(__('Give the days with --from and --to (Y-m-d).', 'seoprostats'));
            }
            $result = SEOProStats_Search_Import::reimport($source, $from, $to);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
                return;
            }
            /* translators: 1: days, 2: rows, 3: import ID */
            WP_CLI::success(sprintf(__('%1$d days imported again: %2$d rows (import %3$d).', 'seoprostats'), $result['days'], $result['rows'], $result['import']));
            return;
        }
        if ($action === 'import') {
            $check = true;
            do {
                $result = SEOProStats_Search_Import::run($source, SEOProStats_Search_Import::BUDGET, $check);
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                    return;
                }
                $check = false;
                if ($result['days']) {
                    /* translators: 1: days, 2: rows, 3: import ID */
                    WP_CLI::log(sprintf(__('%1$d days imported: %2$d rows (import %3$d).', 'seoprostats'), $result['days'], $result['rows'], $result['import']));
                }
                if (!empty($result['pages'])) {
                    /* translators: %d: pages */
                    WP_CLI::log(sprintf(__('Search queries of %d pages imported.', 'seoprostats'), $result['pages']));
                }
            } while (!$result['done']);
        }
        $this->connection_status(SEOProStats_Connections::status($source), $assoc);
    }

    /**
     * Print a connection's status.
     *
     * @param array<string,mixed>  $status SEOProStats_Connections::status().
     * @param array<string,string> $assoc  Options.
     */
    private function connection_status(array $status, $assoc) {
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if (empty($status['connected'])) {
            /* translators: %s: source name */
            WP_CLI::log(sprintf(__('%s is not connected.', 'seoprostats'), $status['name']));
            return;
        }
        $imported = $status['imported'];
        $rows     = array(
            array('field' => 'account', 'value' => $status['account'] !== '' ? $status['account'] : 'API key (stored encrypted)'),
            array('field' => 'property', 'value' => $status['property']),
            array('field' => 'imported', 'value' => $imported['from'] !== '' ? $imported['from'] . ' – ' . $imported['to'] : 'nothing yet'),
            array('field' => 'history', 'value' => $imported['complete'] ? 'complete' : sprintf('%d of %d days', $imported['days'], $imported['of'])),
            array('field' => 'pages\' queries', 'value' => $status['pages_left'] === 0 ? 'in' : ($status['pages_left'] === null ? 'due' : sprintf('%d pages left', $status['pages_left']))),
            array('field' => 'final through', 'value' => $status['final_through'] !== '' ? $status['final_through'] : 'not asked yet'),
            array('field' => 'last run', 'value' => $status['last_run'] ? human_time_diff($status['last_run']) . ' ago' : 'never'),
            array('field' => 'next run', 'value' => $status['next_run'] ? 'in ' . human_time_diff($status['next_run']) : 'not scheduled'),
        );
        if ($status['error'] !== '') {
            $rows[] = array('field' => 'last error', 'value' => $status['error'] . ' (' . human_time_diff($status['error_at']) . ' ago)');
        }
        WP_CLI\Utils\format_items('table', $rows, array('field', 'value'));
    }

    /**
     * Load the connection classes.
     */
    private function load_connections() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-connections.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
    }

    /**
     * Check that statistics are collected and processed: tables, collector
     * folder and config, salts, endpoint, cron, waiting hits and daily
     * summaries.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function doctor($args, $assoc) {
        $checks = self::checks();
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            WP_CLI\Utils\format_items('table', $checks, array('check', 'status', 'detail'));
        }
        $failed = count(array_filter($checks, static function ($check) {
            return $check['status'] === 'fail';
        }));
        if ($failed) {
            /* translators: %d: number of failed checks */
            WP_CLI::error(sprintf(_n('%d check failed.', '%d checks failed.', $failed, 'seoprostats'), $failed));
        }
        // JSON stays parseable: no line after it.
        if ($this->format($assoc) !== 'json') {
            WP_CLI::success(__('Statistics are collected and processed.', 'seoprostats'));
        }
    }

    /**
     * The doctor's checks: check, status (ok, warn, fail), detail.
     *
     * @return array<int,array{check:string,status:string,detail:string}>
     */
    public static function checks() {
        global $wpdb;
        $out = array();
        $add = static function ($check, $ok, $detail, $fail = 'fail') use (&$out) {
            $out[] = array('check' => $check, 'status' => $ok ? 'ok' : $fail, 'detail' => $detail);
        };

        $version = (int) get_option(SEOProStats_Schema::OPTION, 0);
        $add('tables', SEOProStats_Schema::is_current(), sprintf('version %d of %d', $version, SEOProStats_Schema::VERSION));
        $missing = array();
        foreach (SEOProStats_Schema::names() as $name) {
            $table = SEOProStats_Schema::table($name);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a health check on our own tables.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                $missing[] = $name;
            }
        }
        $add('table rows', !$missing, $missing ? 'missing: ' . implode(', ', $missing) : 'all ' . count(SEOProStats_Schema::names()) . ' present');

        $dir = SEOProStats_Collection::dir();
        $add('collector folder', is_dir($dir) && wp_is_writable($dir), $dir);
        $add('collector config', is_file($dir . '/config.php'), is_file($dir . '/config.php') ? 'written ' . human_time_diff((int) filemtime($dir . '/config.php')) . ' ago' : 'not written yet (an admin page or the hourly job writes it)');

        $salts = get_option(SEOProStats_Collection::SALTS_OPTION, array());
        $today = wp_date('Y-m-d');
        $add('daily salt', is_array($salts) && isset($salts[$today]), 'for ' . $today);

        $state = SEOProStats_Collection::state();
        $fast  = !empty($state['fast']);
        $add('endpoint', true, ($fast ? 'collect.php (fast)' : 'REST route') . ': ' . SEOProStats_Collection::endpoint());

        foreach (array(SEOProStats_Collection::CRON_HOOK, SEOProStats_Collection::PROCESS_HOOK) as $hook) {
            $next = wp_next_scheduled($hook);
            $add('cron ' . $hook, (bool) $next, $next ? 'next in ' . human_time_diff($next) : 'not scheduled (an admin page schedules it)');
        }
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-updates.php';
        $updates = SEOProStats_Search_Updates::state();
        if (!SEOProStats_Statistics::search_updates()) {
            $add('search engine updates', true, 'off (SEO Pro Stats → Settings → Data)');
        } else {
            $next   = wp_next_scheduled(SEOProStats_Collection::DAILY_HOOK);
            $failed = array();
            foreach ($updates['sources'] as $source) {
                if (empty($source['ok'])) {
                    $failed[] = $source['name'] . ': ' . $source['error'];
                }
            }
            $when = $updates['last'] ? 'fetched ' . human_time_diff($updates['last']) . ' ago' : 'not fetched yet';
            $add('cron ' . SEOProStats_Collection::DAILY_HOOK, (bool) $next, $next ? 'next in ' . human_time_diff($next) : 'not scheduled (an admin page schedules it)');
            $add('search engine updates', !$failed, $failed ? $when . '; asked again tomorrow: ' . implode('; ', $failed) : $when . ($updates['last'] ? ' from ' . count($updates['sources']) . ' source(s)' : ''), 'warn');
        }
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-connections.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        foreach (SEOProStats_Connections::statuses() as $source) {
            if (empty($source['connected'])) {
                $add(strtolower($source['name']), true, 'not connected (SEO Pro Stats → Settings → Connections)');
                continue;
            }
            $imported = $source['imported'];
            $detail   = $source['property'] . ': ' . ($imported['from'] !== '' ? 'imported ' . $imported['from'] . ' to ' . $imported['to'] : 'nothing imported yet') . ($imported['complete'] ? '' : sprintf('; history %d of %d days', $imported['days'], $imported['of']));
            $ok       = $source['error'] === '' && $source['next_run'];
            if ($source['error'] !== '') {
                $detail .= '; last error ' . human_time_diff($source['error_at']) . ' ago: ' . $source['error'];
            } elseif (!$source['next_run']) {
                $detail .= '; the import job is not scheduled (reconnect, or open the Connections tab)';
            }
            $add(strtolower($source['name']), (bool) $ok, $detail, 'warn');
        }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $add('WP-Cron', false, 'DISABLE_WP_CRON is set: run wp cron event run --due-now every minute from the system cron', 'warn');
        }

        $waiting = 0;
        foreach (array_merge(array($dir . '/buffer.php'), (array) glob($dir . '/processing-*.php')) as $file) {
            $waiting += is_string($file) && is_file($file) ? (int) filesize($file) : 0;
        }
        $processed = get_option(SEOProStats_Collection::PROCESS_OPTION, array());
        $last      = is_array($processed) && isset($processed['last']) ? (int) $processed['last'] : 0;
        $stale     = $waiting > 0 && $last > 0 && $last < time() - 10 * MINUTE_IN_SECONDS;
        $add('processing', !$stale, sprintf('%s waiting; last run %s', size_format($waiting), $last ? human_time_diff($last) . ' ago' : 'never'), 'warn');

        // A day is summarised from 01:00 the next day; a day later is behind.
        $through = SEOProStats_Rollup::through();
        $behind  = $through !== '' ? $through < wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS) : SEOProStats_Rollup::due() !== null;
        $state   = SEOProStats_Rollup::state();
        $add('daily summaries', !$behind, sprintf('through %s; pruned %s', $through !== '' ? $through : 'none yet', isset($state['pruned']) ? (string) $state['pruned'] : 'never'), 'warn');

        if (class_exists('SEOProStats_Page_Cache')) {
            $cache  = SEOProStats_Page_Cache::state();
            $purged = isset($cache['purged']) ? (int) $cache['purged'] : 0;
            $caches = isset($cache['caches']) && is_array($cache['caches']) && $cache['caches'] ? implode(', ', $cache['caches']) : 'none known active';
            $detail = $purged ? sprintf('purged %s ago (%s): %s', human_time_diff($purged), isset($cache['why']) ? (string) $cache['why'] : '', $caches) : 'not purged yet (an admin page schedules it after an update)';
            if (wp_next_scheduled(SEOProStats_Page_Cache::PURGE_HOOK)) {
                $detail .= '; a purge for the new tracker is scheduled for the next WP-Cron run';
            }
            $add('page caches', true, $detail);
        }

        $shops = array_keys(array_filter(array(
            'WooCommerce' => class_exists('WooCommerce'),
            'Easy Digital Downloads' => function_exists('edd_get_order'),
            'FluentCart' => defined('FLUENTCART_VERSION'),
            'ThriveCart' => SEOProStats_Purchases::thrivecart_secret() !== '',
        )));
        if (!SEOProStats_Purchases::enabled()) {
            $add('purchases', true, 'off (SEO Pro Stats → Settings → Tracking)');
        } else {
            $purchases = SEOProStats_Purchases::status();
            $joined = $purchases['thrivecart_not_joined'];
            $add('purchases', true, ($shops ? 'recorded from ' . implode(', ', $shops) : 'no supported shop active') . ($joined ? sprintf('; %d ThriveCart orders without a known page load', $joined) : ''));
            $renewals = array();
            foreach ($purchases['renewals'] as $row) {
                $renewals[] = sprintf('%d / %.2f %s', $row['count'], $row['amount'], $row['currency']);
            }
            $full = $purchases['renewal_receipts'] >= SEOProStats_Purchases::KEEP_RENEWAL_IDS;
            $add('renewals', !$full, ($renewals ? implode('; ', $renewals) : 'none recorded') . '; last 400 days; EDD Recurring not verified' . ($full ? '; receipt capacity reached: new renewals are not counted' : ''), 'warn');
        }
        return $out;
    }

    /**
     * Purge the page caches SEO Pro Stats knows (WP-Optimize, LiteSpeed
     * Cache, WP Rocket and others), so cached pages print the current
     * tracker. Done by itself after an update and after a tracker setting
     * changes.
     *
     * ## EXAMPLES
     *
     *     wp seoprostats purge-caches
     *
     * @subcommand purge-caches
     */
    public function purge_caches() {
        $done = SEOProStats_Page_Cache::purge('manual');
        if ($done) {
            /* translators: %s: names of page cache plugins */
            WP_CLI::success(sprintf(__('Purged: %s.', 'seoprostats'), implode(', ', $done)));
            return;
        }
        WP_CLI::success(__('No known page cache is active.', 'seoprostats'));
    }

    /**
     * Make, show or remove the demo data: made-up visits in tables of
     * their own, for training, screenshots and testing. Live data is
     * never touched. Reports show it with --data=demo, and the screens
     * with the Demo data switch.
     *
     * ## OPTIONS
     *
     * <action>
     * : make (remove any demo data, then make it again), status or remove.
     * ---
     * options:
     *   - make
     *   - status
     *   - remove
     * ---
     *
     * [--days=<days>]
     * : Days of visits back from today, for make.
     * ---
     * default: 400
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoprostats demo make
     *     wp seoprostats demo make --days=90
     *     wp seoprostats stats --data=demo --range=30d --compare=prev
     *     wp seoprostats demo remove
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function demo($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'status';
        if ($action === 'remove') {
            SEOProStats_Demo::remove();
            WP_CLI::success(__('Demo data removed. Live data is unchanged.', 'seoprostats'));
            return;
        }
        if ($action === 'make') {
            $start = microtime(true);
            if (!SEOProStats_Demo::start(isset($assoc['days']) ? (int) $assoc['days'] : SEOProStats_Demo::DAYS)) {
                WP_CLI::error(__('The demo tables could not be made.', 'seoprostats'));
            }
            $last = -1.0;
            do {
                $status = SEOProStats_Demo::step(60);
                if ($status['progress'] > $last) {
                    WP_CLI::log(sprintf('%d%%', (int) round($status['progress'] * 100)));
                    $last = $status['progress'];
                } else {
                    sleep(2); // Another request is making it.
                }
            } while ($status['status'] === 'making');
            $totals = SEOProStats_Demo::run(static function () {
                return SEOProStats_Query::stats((array) SEOProStats_Query::request(array('range' => 'all')));
            });
            WP_CLI::success(sprintf(
                /* translators: 1: days, 2: visits, 3: pageviews, 4: events, 5: seconds */
                __('Demo data made: %1$d days, %2$d visits, %3$d pageviews, %4$d events, in %5$d s.', 'seoprostats'),
                $status['days'],
                $totals['metrics']['visits'],
                $totals['metrics']['pageviews'],
                $totals['metrics']['events'],
                (int) round(microtime(true) - $start)
            ));
            return;
        }
        $status = SEOProStats_Demo::status();
        WP_CLI\Utils\format_items('table', array($status), array_keys($status));
    }

    /**
     * Run a report on the data set of the --data option; exits on an error.
     *
     * @param array<string,string> $assoc Options.
     * @param callable             $work  Makes the answer.
     * @return mixed
     */
    private function on_data(array $assoc, callable $work) {
        $answer = SEOProStats_API::on_data(isset($assoc['data']) ? (string) $assoc['data'] : 'live', $work);
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        return $answer;
    }

    /**
     * A report request from command options; exits on a bad one.
     *
     * @param array<string,string> $assoc Options.
     * @return array<string,mixed>
     */
    private function request(array $assoc) {
        if (isset($assoc['filter'])) {
            $filter           = trim((string) $assoc['filter']);
            $assoc['filters'] = $filter !== '' && $filter[0] === '[' ? $filter : array_filter(array_map('trim', explode(';', $filter)));
        }
        $req = SEOProStats_Query::request($assoc);
        if (is_wp_error($req)) {
            WP_CLI::error($req->get_error_message());
        }
        return (array) $req;
    }

    /**
     * Output format.
     *
     * @param array<string,string> $assoc Options.
     * @return string
     */
    private function format(array $assoc) {
        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        return in_array($format, array('table', 'json', 'csv', 'yaml'), true) ? $format : 'table';
    }

    /**
     * Print the range above a table.
     *
     * @param array<string,string> $range Range from an answer.
     */
    private function range_line(array $range) {
        WP_CLI::log(sprintf('%s: %s to %s (%s)', $range['key'], $range['from'], $range['to'], $range['timezone']));
    }
}

WP_CLI::add_command('seoprostats', 'SEOProStats_CLI');
