<?php
/**
 * WP-CLI commands: wp seoprostats stats, timeseries, breakdown, realtime,
 * goals, funnels, properties, clicks, process, rollup, prune, doctor, demo and
 * purge-caches. Reports come from
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
     * : channel, source, utm_source, utm_medium, utm_campaign, utm_term, utm_content, country, device, browser, os, language, entry, exit, page or event.
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
     * : elements, dead, links, downloads or forms.
     * ---
     * default: elements
     * options:
     *   - elements
     *   - dead
     *   - links
     *   - downloads
     *   - forms
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
        );
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $fields[$answer['kind']]);
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
     * and 120 months by default; SEO Pro Stats → Settings → Data). Daily
     * summaries are kept, and nothing newer than the last summarised day
     * goes.
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
        /* translators: 1: months visits are kept, 2: months events are kept (0: forever) */
        WP_CLI::log(sprintf(__('Retention: visits %1$d months, events %2$d months (0: forever; SEO Pro Stats → Settings → Data).', 'seoprostats'), $months['visits'], $months['events']));
        if (SEOProStats_Rollup::through() === '') {
            WP_CLI::success(__('Nothing to delete: no day is summarised yet.', 'seoprostats'));
            return;
        }
        if (!empty($assoc['dry-run'])) {
            $items = array();
            foreach (SEOProStats_Rollup::prune_counts() as $table => $rows) {
                $items[] = array('table' => $table, 'rows' => $rows);
            }
            if ($items) {
                WP_CLI\Utils\format_items('table', $items, array('table', 'rows'));
            }
            WP_CLI::success(__('Dry run: nothing deleted.', 'seoprostats'));
            return;
        }
        $deleted = 0;
        do {
            $done     = SEOProStats_Rollup::prune(microtime(true));
            $deleted += $done['deleted'];
        } while (!$done['done']);
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
        WP_CLI::success(__('Statistics are collected and processed.', 'seoprostats'));
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
