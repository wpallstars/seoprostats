<?php
/**
 * WP-CLI commands: wp seoprostats stats, timeseries, breakdown, realtime,
 * process and doctor. Reports come from the same engine as the REST API,
 * so the numbers match (docs/architecture.md → Interfaces).
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
        $answer = SEOProStats_Query::stats($req);
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
        $answer = SEOProStats_Query::timeseries($this->request($assoc));
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
        $answer             = SEOProStats_Query::breakdown($this->request($assoc));
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
     * Visitors on the site in the last 30 minutes.
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
    public function realtime($args, $assoc) {
        $answer = SEOProStats_Query::realtime();
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
            /* translators: 1: lines, 2: pageviews, 3: events, 4: bot hits, 5: skipped lines, 6: milliseconds */
            __('%1$d lines: %2$d pageviews, %3$d events, %4$d bot hits dropped, %5$d skipped, in %6$d ms.', 'seoprostats'),
            $totals['lines'],
            $totals['pageviews'],
            $totals['events'],
            $totals['bots'],
            $totals['skipped'],
            (int) round((microtime(true) - $start) * 1000)
        ));
    }

    /**
     * Check that statistics are collected and processed: tables, collector
     * folder and config, salts, endpoint, cron and waiting hits.
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
        return $out;
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
