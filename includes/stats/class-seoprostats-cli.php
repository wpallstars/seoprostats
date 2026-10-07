<?php
/**
 * WP-CLI commands: wp seoprostats stats, timeseries, breakdown, realtime,
 * goals, funnels, properties, clicks, search, changes, annotate, search-updates,
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
     * CTR and average position, then search queries, pages, countries or
     * devices. The period is cut at the newest day with search data
     * (about three days ago); visit filters do not apply, page filters do.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : queries, pages, countries or devices (countries and devices for the whole site only).
     * ---
     * default: queries
     * options:
     *   - queries
     *   - pages
     *   - countries
     *   - devices
     * ---
     *
     * [--page=<path>]
     * : Only searches that showed this page (* for any text).
     *
     * [--query=<query>]
     * : Only this search query (* for any text).
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools; no countries or devices).
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
     *     wp seoprostats search --range=90d --compare=prev
     *     wp seoprostats search queries --page=/pricing/
     *     wp seoprostats search pages --query="seo pro stats" --format=json
     *     wp seoprostats search --engine=bing
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
            'queries'   => 'value',
            'pages'     => 'path',
            'countries' => 'label',
            'devices'   => 'label',
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
     * cause: position, demand, ctr or gone). The period is cut at the
     * newest day with search data and to its newest 91 days.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : striking, ctr or decay.
     * ---
     * default: striking
     * options:
     *   - striking
     *   - ctr
     *   - decay
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
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
        if ($answer['kind'] === 'decay' && $answer['updates']) {
            /* translators: %s: search engine updates */
            WP_CLI::log(sprintf(__('Search engine updates in these periods: %s.', 'seoprostats'), implode('; ', array_column($answer['updates'], 'label'))));
        }
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
     * : google (Search Console) or bing (Bing Webmaster Tools) for the search figures.
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
            $joined = SEOProStats_Purchases::status()['thrivecart_not_joined'];
            $add('purchases', true, ($shops ? 'recorded from ' . implode(', ', $shops) : 'no supported shop active') . ($joined ? sprintf('; %d ThriveCart orders without a known page load', $joined) : ''));
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
