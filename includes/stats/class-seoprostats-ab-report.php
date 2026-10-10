<?php
/**
 * A/B test reports: every test with its leader, and one test's variants
 * side by side, for the dashboard, the REST API and WP-CLI. Loaded with
 * the report engine (SEOProStats_API::load()), never on visitor pages.
 *
 * A test's numbers cover its life: from when it first ran (or was made)
 * to when it ended, or now. Each visit that saw the test counts for the
 * variant it saw; a visit that saw two or more of a test's variants (each
 * page load picks again unless one variant per visit is on) is a mixed
 * visit, counted apart and left out of every variant. A conversion counts
 * when the visit reached the goal at or after it first saw the test.
 *
 * Variants are compared with the control (the first variant) on the
 * primary metric: the test's first goal, or, with no goals, visits that
 * clicked inside the variant. Each comparison has the uplift (relative
 * change in the rate) with a 95% interval (the log of the rate ratio),
 * and the probability that the variant's true rate beats the control's:
 * Bayesian, with a uniform Beta(1, 1) prior on each rate (beta-binomial),
 * worked out exactly (prob_beat()). Below the minimum sample (MIN_VISITS
 * per variant, MIN_CONVERSIONS between the two, MIN_DAYS of running) a
 * comparison is too early to call.
 *
 * Not to be confused with Experiments (SEOProStats_Experiments): a change
 * and its expected effect on search.
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

/**
 * A/B test reports: per-variant numbers, comparisons and verdicts.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") The registry, measurement and statistics share one test's numbers; each step is a small private helper.
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") The measurement's reads and the statistics belong to one report.
 * @SuppressWarnings("PHPMD.TooManyMethods") Named private steps keep each read, comparison and verdict readable.
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") REST, WP-CLI, abilities and the query engine call these entry points; the statistics are public for checking.
 */
final class SEOProStats_AB_Report { // NOSONAR: one A/B report for REST, WP-CLI, abilities and the dashboard; private helpers decompose its measurement.

    /** Fewest visits each side of a comparison needs before a call. */
    const MIN_VISITS = 100;

    /** Fewest conversions the two sides of a comparison need together. */
    const MIN_CONVERSIONS = 10;

    /** Fewest days a test runs before a call (a whole week of weekdays and weekends). */
    const MIN_DAYS = 7;

    /** Probability to beat the control that calls a variant better (1 − this: worse). */
    const CONFIDENCE = 0.95;

    /** Most tests read at once (the registry is small: one row per test block). */
    const MAX_TESTS = 500;

    /** Longest exact sum in prob_beat(); past it, the normal approximation (equal to many decimals there). */
    const MAX_EXACT = 50000;

    /** Verdict codes. */
    const VERDICTS = array('no_data', 'too_early', 'winner', 'control', 'unclear');

    // ------------------------------------------------------------------
    // Reports.

    /**
     * Every test with its status, variants, visits per variant and leader;
     * running tests first, then the newest.
     *
     * @param array<string,mixed> $args filters (as SEOProStats_Query::request()), status (a status name, or '' for all).
     * @return array<string,mixed>|WP_Error
     */
    public static function list_tests(array $args = array()) {
        $filters = SEOProStats_Query::parse_filters(isset($args['filters']) ? $args['filters'] : array());
        if (is_wp_error($filters)) {
            return $filters;
        }
        $status = isset($args['status']) ? (string) $args['status'] : '';
        if ($status !== '' && !isset(SEOProStats_AB_Tests::STATUSES[$status])) {
            return new WP_Error('seoprostats_status', sprintf(/* translators: %s: list of statuses */ __('Status must be one of: %s.', 'seoprostats'), implode(', ', array_keys(SEOProStats_AB_Tests::STATUSES))), array('status' => 400));
        }
        $tests = self::registry();
        $key   = array('filters' => $filters, 'status' => $status, 'tests' => self::fingerprint($tests));
        return SEOProStats_Query::cached('ab_tests', $key, static function () use ($tests, $filters, $status) {
            $rows = array();
            foreach ($tests as $test) {
                if ($status !== '' && $test['status'] !== $status) {
                    continue;
                }
                $report = self::measure($test, $filters);
                $rows[] = array(
                    'id'       => $test['id'],
                    'name'     => $test['name'],
                    'status'   => $test['status'],
                    'post'     => $test['post'],
                    'started'  => $test['started'],
                    'ended'    => $test['ended'],
                    'removed'  => $test['removed'],
                    'winner'   => $test['winner'],
                    'primary'  => $report['primary'],
                    'visits'   => $report['visits'],
                    'mixed'    => $report['mixed'],
                    'variants' => array_map(static function ($v) {
                        return array(
                            'slug'        => $v['slug'],
                            'label'       => $v['label'],
                            'weight'      => $v['weight'],
                            'control'     => $v['control'],
                            'visits'      => $v['visits'],
                            'conversions' => $v['primary']['conversions'],
                            'rate'        => $v['primary']['rate'],
                            'probability' => $v['primary']['probability'],
                            'verdict'     => $v['primary']['verdict'],
                        );
                    }, $report['variants']),
                    'leader'   => $report['leader'],
                    'verdict'  => $report['verdict'],
                );
            }
            usort($rows, static function ($a, $b) {
                $run = (int) ($b['status'] === 'running') - (int) ($a['status'] === 'running');
                return $run !== 0 ? $run : strcmp((string) $b['started'], (string) $a['started']);
            });
            return array(
                'thresholds' => self::thresholds(),
                'tests'      => $rows,
            );
        });
    }

    /**
     * One test: its variants side by side with every goal, revenue per
     * currency, bounce rate, engaged time and clicks, the comparisons with
     * the control and the verdict.
     *
     * @param string              $id   Test id.
     * @param array<string,mixed> $args filters (as SEOProStats_Query::request()).
     * @return array<string,mixed>|WP_Error
     */
    public static function get($id, array $args = array()) {
        $id = (string) $id;
        if (!SEOProStats_AB_Tests::valid_id($id)) {
            return self::not_found();
        }
        $filters = SEOProStats_Query::parse_filters(isset($args['filters']) ? $args['filters'] : array());
        if (is_wp_error($filters)) {
            return $filters;
        }
        $tests = self::registry($id);
        if (!$tests) {
            return self::not_found();
        }
        $test = $tests[0];
        return SEOProStats_Query::cached('ab_test', array('filters' => $filters, 'tests' => self::fingerprint($tests)), static function () use ($test, $filters) {
            $report = self::measure($test, $filters, true);
            unset($test['from'], $test['to']);
            return array(
                'test'       => $test,
                'thresholds' => self::thresholds(),
            ) + $report;
        });
    }

    /**
     * The error for an unknown test.
     *
     * @return WP_Error
     */
    private static function not_found() {
        return new WP_Error('seoprostats_not_found', __('There is no such A/B test.', 'seoprostats'), array('status' => 404));
    }

    /**
     * The minimum sample and confidence, for readers of the verdicts.
     *
     * @return array{visits:int,conversions:int,days:int,confidence:float}
     */
    public static function thresholds() {
        return array(
            'visits'      => self::MIN_VISITS,
            'conversions' => self::MIN_CONVERSIONS,
            'days'        => self::MIN_DAYS,
            'confidence'  => self::CONFIDENCE,
        );
    }

    /**
     * What makes cached answers about these tests stale: each one's last
     * save.
     *
     * @param array<int,array<string,mixed>> $tests From registry().
     * @return string
     */
    private static function fingerprint(array $tests) {
        $parts = array();
        foreach ($tests as $test) {
            $parts[] = $test['id'] . ':' . $test['updated'];
        }
        return md5(implode(',', $parts)); // NOSONAR nosemgrep: a change fingerprint, not security.
    }

    // ------------------------------------------------------------------
    // The registry.

    /**
     * Tests in the current data set's ab_tests, shaped for answers: one
     * by its id, or every one (MAX_TESTS).
     *
     * @param string $id Test id, or '' for all.
     * @return array<int,array<string,mixed>>
     */
    public static function registry($id = '') {
        global $wpdb;
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return array();
        }
        $table = SEOProStats_Schema::table('ab_tests');
        if ($id !== '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key; reports are cached by the caller.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE test_id = %s', $table, $id), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own small table (one row per test block), bounded.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY started DESC, created DESC LIMIT %d', $table, self::MAX_TESTS), ARRAY_A);
        }
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = self::shape((array) $row);
        }
        return $out;
    }

    /**
     * A registry row as answers show it.
     *
     * @param array<string,mixed> $row ab_tests row.
     * @return array<string,mixed>
     */
    private static function shape(array $row) {
        $list   = self::shape_variants((string) $row['variants']);
        $goals  = json_decode((string) $row['goals'], true);
        $status = array_search((int) $row['status'], SEOProStats_AB_Tests::STATUSES, true);
        $time   = static function ($ts) {
            return (int) $ts ? (string) wp_date('c', (int) $ts) : null;
        };
        return array(
            'id'       => (string) $row['test_id'],
            'name'     => (string) $row['name'],
            'status'   => is_string($status) ? $status : 'draft',
            'post'     => self::post((int) $row['post_id']),
            'variants' => $list,
            'goals'    => is_array($goals) ? array_values(array_map('strval', $goals)) : array(),
            'winner'   => (string) $row['winner'],
            'created'  => $time($row['created']),
            'updated'  => (int) $row['updated'],
            'started'  => $time($row['started']),
            'ended'    => $time($row['ended']),
            'removed'  => $time($row['removed']),
            // Unix times, for measuring (not in answers).
            'from'     => (int) $row['started'] ? (int) $row['started'] : (int) $row['created'],
            'to'       => (int) $row['ended'] ? (int) $row['ended'] : 0,
        );
    }

    /**
     * A registry row's variants as answers show them: those with a slug,
     * labelled by their slug and weighted WEIGHT when not given.
     *
     * @param string $json The variants column.
     * @return array<int,array{slug:string,label:string,weight:int}>
     */
    private static function shape_variants($json) {
        $variants = json_decode($json, true);
        $list     = array();
        foreach (is_array($variants) ? $variants : array() as $variant) {
            if (is_array($variant) && isset($variant['slug']) && is_string($variant['slug'])) {
                $list[] = array(
                    'slug'   => $variant['slug'],
                    'label'  => isset($variant['label']) ? (string) $variant['label'] : $variant['slug'],
                    'weight' => isset($variant['weight']) ? (int) $variant['weight'] : SEOProStats_AB_Tests::WEIGHT,
                );
            }
        }
        return $list;
    }

    /**
     * The post a test is in: id, title, path and edit link (for people
     * who may edit it). Demo tests are in the demo's pages.
     *
     * @param int $post_id Post.
     * @return array{id:int,title:string,path:string|null,edit_url:string|null}
     */
    private static function post($post_id) {
        if (SEOProStats_Schema::set() === 'demo' && class_exists('SEOProStats_Demo')) {
            $demo = SEOProStats_Demo::post($post_id);
            return array(
                'id'       => $post_id,
                'title'    => $demo ? $demo['title'] : '',
                'path'     => $demo ? $demo['path'] : null,
                'edit_url' => null,
            );
        }
        $post = $post_id ? get_post($post_id) : null;
        if (!$post instanceof WP_Post) {
            return array('id' => $post_id, 'title' => '', 'path' => null, 'edit_url' => null);
        }
        $link = $post->post_status === 'publish' ? get_permalink($post) : false;
        $edit = current_user_can('edit_post', $post_id) ? get_edit_post_link($post_id, 'raw') : null;
        return array(
            'id'       => $post_id,
            'title'    => (string) get_the_title($post),
            'path'     => is_string($link) ? SEOProStats_Changes::path($link) : null,
            'edit_url' => is_string($edit) ? $edit : null,
        );
    }

    /**
     * Pages with a running test (for the Pages breakdown's mark).
     *
     * @return array<string,bool> Path => true.
     */
    public static function running_paths() {
        global $wpdb;
        if (!SEOProStats_Schema::is_current()) {
            return array();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by index `status`; a report request.
        $posts = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT post_id FROM %i WHERE status = %d AND removed = 0 LIMIT %d', SEOProStats_Schema::table('ab_tests'), SEOProStats_AB_Tests::STATUSES['running'], self::MAX_TESTS));
        $out   = array();
        foreach ((array) $posts as $post_id) {
            $path = self::post((int) $post_id)['path'];
            if ($path !== null) {
                $out[$path] = true;
            }
        }
        return $out;
    }

    /**
     * Every test's variants as the variant dimension shows them: value
     * "test-id:variant-slug" and label "Test name: Variant B".
     *
     * @return array<string,string> Value => label.
     */
    public static function variant_labels() {
        static $cache = array();
        $set = SEOProStats_Schema::set();
        if (isset($cache[$set])) {
            return $cache[$set];
        }
        $out = array();
        foreach (self::registry() as $test) {
            foreach ($test['variants'] as $variant) {
                /* translators: 1: A/B test name, 2: variant label */
                $out[$test['id'] . ':' . $variant['slug']] = sprintf(__('%1$s: %2$s', 'seoprostats'), $test['name'] !== '' ? $test['name'] : $test['id'], $variant['label']);
            }
        }
        $cache[$set] = $out;
        return $out;
    }

    // ------------------------------------------------------------------
    // Measuring.

    /**
     * A test's numbers per variant, its comparisons and verdict.
     *
     * @param array<string,mixed>                                           $test    From registry().
     * @param array<int,array{dimension:string,op:string,values:string[]}> $filters Filters on the visits.
     * @param bool                                                          $full    Every goal, revenue and the visit metrics (one test's view); else the primary metric only.
     * @return array<string,mixed>
     */
    private static function measure(array $test, array $filters, $full = false) {
        $range    = self::test_range($test);
        $from     = $range['from'];
        $to       = $range['to'];
        $days     = $test['started'] !== null ? max(0, (int) floor(($to - $from) / DAY_IN_SECONDS)) : 0;
        $compiled = SEOProStats_Query::compile($filters, $range);
        $goals    = self::goals($test['goals']);
        $control  = $test['variants'] ? $test['variants'][0]['slug'] : '';
        $primary  = self::primary_metric($goals);

        $mixed = array('visits' => 0, 'share' => 0);
        $exp   = self::exposures_sql($test['id'], $range);
        $by    = $exp !== null ? self::visit_rows($exp, $compiled) : array();
        $slugs = self::slugs(array_keys($by));
        $total = 0;
        foreach ($by as $row) {
            $total += (int) $row['visits'];
        }
        if (isset($by[0])) {
            $mixed = array('visits' => (int) $by[0]['visits'], 'share' => $total ? round((int) $by[0]['visits'] / $total, 4) : 0);
        }

        // Goals reached after the test was seen, per variant.
        $reached = self::goals_reached($full ? $goals : array_slice($goals, 0, 1), $exp, $compiled, $full);

        $variants = array();
        foreach ($test['variants'] as $variant) {
            $id         = array_search($variant['slug'], $slugs, true);
            $row        = $id !== false && isset($by[$id]) ? $by[$id] : array();
            $variant   += array('control' => $variant['slug'] === $control, 'winner' => $variant['slug'] === $test['winner']);
            $variants[] = self::variant_numbers($variant, $row, $id, $total, $goals, $reached, $full);
        }
        $variants = self::compared($variants, $days);
        list($verdict, $leader) = self::verdict($variants, $total, $primary);

        return array(
            'period'   => array('from' => (string) wp_date('c', $from), 'to' => (string) wp_date('c', $to), 'days' => $days),
            'primary'  => $primary,
            'goals'    => $goals,
            'visits'   => $total,
            'mixed'    => $mixed,
            'variants' => $variants,
            'leader'   => $leader,
            'verdict'  => $verdict,
        );
    }

    /**
     * A test's period as a custom range: from its start (or creation) to
     * its end, or now while it runs.
     *
     * @param array<string,mixed> $test From registry().
     * @return array{key:string,start:DateTimeImmutable,end:DateTimeImmutable,from:int,to:int}
     */
    private static function test_range(array $test) {
        $from = (int) $test['from'];
        $to   = $test['to'] ? (int) $test['to'] : time();
        return array(
            'key'   => 'custom',
            'start' => (new DateTimeImmutable('@' . $from))->setTimezone(wp_timezone()),
            'end'   => (new DateTimeImmutable('@' . $to))->setTimezone(wp_timezone()),
            'from'  => $from,
            'to'    => $to,
        );
    }

    /**
     * The primary metric: the test's first goal, else clicks inside the
     * variant.
     *
     * @param array<int,array{id:string,name:string,kind:string,match:string}> $goals From goals().
     * @return array{kind:string,id:string,name:string}
     */
    private static function primary_metric(array $goals) {
        return $goals
            ? array('kind' => 'goal', 'id' => $goals[0]['id'], 'name' => $goals[0]['name'])
            : array('kind' => 'clicks', 'id' => '', 'name' => __('Clicked inside the variant', 'seoprostats'));
    }

    /**
     * The visits that saw a test, by variant's dictionary id (0: saw more
     * than one): visits, visitors, loads, clicks, clicked, bounces and
     * engaged time.
     *
     * @param array{0:string,1:array<int,mixed>} $exp      From exposures_sql().
     * @param array<string,mixed>                $compiled From SEOProStats_Query::compile().
     * @return array<int,array<string,mixed>>
     */
    private static function visit_rows(array $exp, array $compiled) {
        global $wpdb;
        list($sql, $args) = $exp;
        $where            = $compiled['where'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: ab_exposures by index `test_day`, visits by primary key; $sql and $where hold only placeholders and fixed SQL.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT IF(a.n > 1, 0, a.v) AS bucket, COUNT(*) AS visits, COUNT(DISTINCT s.day, s.visitor) AS visitors, COALESCE(SUM(a.loads), 0) AS loads, COALESCE(SUM(a.clicks), 0) AS clicks, COALESCE(SUM(a.clicks > 0), 0) AS clicked, COALESCE(SUM(s.pageviews <= 1 AND s.events = 0), 0) AS bounces, COALESCE(SUM(s.engaged_ms), 0) AS engaged_ms FROM ($sql) a INNER JOIN %i s ON s.id = a.sid WHERE 1 = 1$where GROUP BY bucket", array_merge($args, array(SEOProStats_Schema::table('sessions')), $compiled['args'])), ARRAY_A);
        $by   = array();
        foreach ((array) $rows as $row) {
            $by[(int) $row['bucket']] = $row;
        }
        return $by;
    }

    /**
     * Each goal's conversions and revenue per variant (none without
     * exposures).
     *
     * @param array<int,array<string,mixed>>          $goals    The goals measured.
     * @param array{0:string,1:array<int,mixed>}|null $exp      From exposures_sql().
     * @param array<string,mixed>                     $compiled From SEOProStats_Query::compile().
     * @param bool                                    $full     Whether to add revenue.
     * @return array{0:array<string,array<int,array{visits:int,completions:int}>>,1:array<string,array<int,array<int,array<string,mixed>>>>} By goal id: counts; revenue.
     */
    private static function goals_reached(array $goals, $exp, array $compiled, $full) {
        $reached = array();
        $money   = array();
        foreach ($goals as $goal) {
            $counts               = $exp !== null ? self::goal_counts($exp, $goal, $compiled, $full) : array(array(), array());
            $reached[$goal['id']] = $counts[0];
            $money[$goal['id']]   = $counts[1];
        }
        return array($reached, $money);
    }

    /**
     * One variant's numbers: visits and share, with $full the visit
     * metrics and every goal, and the primary metric's conversions.
     *
     * @param array{slug:string,label:string,weight:int,control:bool,winner:bool} $variant The variant, and whether it is the control and the winner.
     * @param array<string,mixed>                          $row     Its visits (from visit_rows(); empty for none).
     * @param int|false                                    $id      Its dictionary id (false: never seen).
     * @param int                                          $total   Visits that saw the test.
     * @param array<int,array<string,mixed>>               $goals   From goals().
     * @param array{0:array<string,mixed>,1:array<string,mixed>} $reached From goals_reached().
     * @param bool                                         $full    Every goal and the visit metrics.
     * @return array<string,mixed>
     */
    private static function variant_numbers(array $variant, array $row, $id, $total, array $goals, array $reached, $full) {
        $n   = isset($row['visits']) ? (int) $row['visits'] : 0;
        $out = array(
            'slug'    => $variant['slug'],
            'label'   => $variant['label'],
            'weight'  => $variant['weight'],
            'control' => $variant['control'],
            'winner'  => $variant['winner'],
            'visits'  => $n,
            'share'   => $total ? round($n / $total, 4) : 0,
        );
        if ($full) {
            $out         += self::visit_metrics($row, $n);
            $out['goals'] = self::variant_goals($goals, $reached, $id, $n);
        }
        $first          = self::primary_conversions($goals, $reached[0], $row, $id);
        $out['primary'] = array('conversions' => $first, 'rate' => $n ? round($first / $n, 4) : 0);
        return $out;
    }

    /**
     * A variant's visit metrics: visitors, page views, clicks, visits with
     * a click, and click, bounce and engaged rates.
     *
     * @param array<string,mixed> $row Its visits (empty for none).
     * @param int                 $n   Its visits.
     * @return array<string,int|float>
     */
    private static function visit_metrics(array $row, $n) {
        $clicked = isset($row['clicked']) ? (int) $row['clicked'] : 0;
        return array(
            'visitors'     => isset($row['visitors']) ? (int) $row['visitors'] : 0,
            'pageviews'    => isset($row['loads']) ? (int) $row['loads'] : 0,
            'clicks'       => isset($row['clicks']) ? (int) $row['clicks'] : 0,
            'clicked'      => $clicked,
            'click_rate'   => $n ? round($clicked / $n, 4) : 0,
            'bounce_rate'  => $n ? round((int) $row['bounces'] / $n, 4) : 0,
            'engaged_time' => $n ? (int) round((int) $row['engaged_ms'] / $n / 1000) : 0,
        );
    }

    /**
     * A variant's conversions, completions, rate and revenue for every goal.
     *
     * @param array<int,array<string,mixed>>                     $goals   From goals().
     * @param array{0:array<string,mixed>,1:array<string,mixed>} $reached From goals_reached().
     * @param int|false                                          $id      Its dictionary id (false: never seen).
     * @param int                                                $n       Its visits.
     * @return array<int,array<string,mixed>>
     */
    private static function variant_goals(array $goals, array $reached, $id, $n) {
        list($counts, $money) = $reached;
        $out = array();
        foreach ($goals as $goal) {
            $hit   = $id !== false && isset($counts[$goal['id']][$id]) ? $counts[$goal['id']][$id] : array('visits' => 0, 'completions' => 0);
            $out[] = array(
                'id'          => $goal['id'],
                'name'        => $goal['name'],
                'conversions' => (int) $hit['visits'],
                'completions' => (int) $hit['completions'],
                'rate'        => $n ? round($hit['visits'] / $n, 4) : 0,
                'revenue'     => $id !== false && isset($money[$goal['id']][$id]) ? $money[$goal['id']][$id] : array(),
            );
        }
        return $out;
    }

    /**
     * A variant's conversions on the primary metric: visits that reached
     * the first goal, else visits with a click inside it.
     *
     * @param array<int,array<string,mixed>> $goals  From goals().
     * @param array<string,mixed>            $counts Conversions by goal id, then variant id.
     * @param array<string,mixed>            $row    Its visits (empty for none).
     * @param int|false                      $id     Its dictionary id (false: never seen).
     * @return int
     */
    private static function primary_conversions(array $goals, array $counts, array $row, $id) {
        if (!$goals) {
            return isset($row['clicked']) ? (int) $row['clicked'] : 0;
        }
        return $id !== false && isset($counts[$goals[0]['id']][$id]) ? (int) $counts[$goals[0]['id']][$id]['visits'] : 0;
    }

    /**
     * The variants with each compared with the control: on the primary
     * metric, and (full view) on every goal.
     *
     * @param array<int,array<string,mixed>> $variants From variant_numbers().
     * @param int                            $days     Days the test has run.
     * @return array<int,array<string,mixed>>
     */
    private static function compared(array $variants, $days) {
        $base = null;
        foreach ($variants as $v) {
            if ($v['control']) {
                $base = $v;
            }
        }
        foreach ($variants as $i => $v) {
            $v['primary'] += self::compare($base, $v, $days);
            if ($base !== null && isset($v['goals'], $base['goals'])) {
                $v['goals'] = self::goal_comparisons($base, $v);
            }
            $variants[$i] = $v;
        }
        return $variants;
    }

    /**
     * A variant's goals each compared with the control's: uplift, its
     * interval and the probability to beat it (none for the control).
     *
     * @param array<string,mixed> $base The control's numbers.
     * @param array<string,mixed> $v    The variant's.
     * @return array<int,array<string,mixed>>
     */
    private static function goal_comparisons(array $base, array $v) {
        $goal_rows = array();
        foreach ($v['goals'] as $g => $goal) {
            $c = $base['goals'][$g];
            if ($v['control']) {
                $goal_rows[] = $goal + array('uplift' => null, 'interval' => null, 'probability' => null);
                continue;
            }
            $probability = null;
            if ($base['visits'] && $v['visits']) {
                $probability = round(self::prob_beat($c['conversions'], $base['visits'], $goal['conversions'], $v['visits']), 4);
            }
            $goal_rows[] = $goal + array(
                'uplift'      => self::uplift($c['conversions'], $base['visits'], $goal['conversions'], $v['visits']),
                'interval'    => self::interval($c['conversions'], $base['visits'], $goal['conversions'], $v['visits']),
                'probability' => $probability,
            );
        }
        return $goal_rows;
    }

    /**
     * The derived table of a test's visits: sid (the visit), v (its
     * variant's dictionary id; the smallest when it saw more), n (how many
     * of the test's variants it saw), first (when it first saw the test),
     * loads (page loads that showed it), clicks (inside the variant). Read
     * through index `test_day`. Null when the test has no exposures.
     *
     * @param string              $test  Test id.
     * @param array<string,mixed> $range from and to (Unix).
     * @return array{0:string,1:array<int,mixed>}|null SQL with placeholders, and its arguments.
     */
    private static function exposures_sql($test, array $range) {
        $ids = SEOProStats_Dict::find(SEOProStats_Schema::DICT_AB_TEST, array($test));
        if (!$ids) {
            return null;
        }
        return array(
            'SELECT x.session_id AS sid, MIN(x.variant_id) AS v, COUNT(DISTINCT x.variant_id) AS n, MIN(x.ts) AS first, COUNT(*) AS loads, SUM(x.clicks) AS clicks FROM %i x WHERE x.test_id = %d AND x.day >= %s AND x.day <= %s GROUP BY x.session_id',
            array(SEOProStats_Schema::table('ab_exposures'), (int) $ids[0], (string) wp_date('Y-m-d', (int) $range['from']), (string) wp_date('Y-m-d', (int) $range['to'] + DAY_IN_SECONDS)),
        );
    }

    /**
     * One goal's conversions per variant: visits that reached it at or
     * after first seeing the test, completions, and (event goals, full
     * view) revenue per currency.
     *
     * @param array{0:string,1:array<int,mixed>} $exp      From exposures_sql().
     * @param array<string,mixed>                $goal     Goal.
     * @param array<string,mixed>                $compiled From SEOProStats_Query::compile().
     * @param bool                               $money    Whether to add revenue.
     * @return array{0:array<int,array{visits:int,completions:int}>,1:array<int,array<int,array<string,mixed>>>} Variant id (0: mixed) => counts; => revenue.
     */
    private static function goal_counts(array $exp, array $goal, array $compiled, $money) {
        global $wpdb;
        list($table, $column, $ids) = SEOProStats_Conversions::target($goal['kind'], $goal['match']);
        if (!$ids) {
            return array(array(), array());
        }
        list($sql, $args) = $exp;
        $where            = $compiled['where'];
        $holders          = implode(', ', array_fill(0, count($ids), '%d'));
        $from             = "FROM ($sql) a INNER JOIN %i s ON s.id = a.sid INNER JOIN %i f ON f.session_id = a.sid AND f.ts >= a.first WHERE f.%i IN ($holders)$where";
        $base             = array_merge($args, array(SEOProStats_Schema::table('sessions'), $table, $column), $ids, $compiled['args']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: ab_exposures by `test_day`, visits by primary key, the goal's hits by `session_seq`; $from holds only placeholders and fixed SQL.
        $rows   = $wpdb->get_results($wpdb->prepare("SELECT IF(a.n > 1, 0, a.v) AS bucket, COUNT(DISTINCT a.sid) AS visits, COUNT(*) AS completions $from GROUP BY bucket", $base), ARRAY_A);
        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row['bucket']] = array('visits' => (int) $row['visits'], 'completions' => (int) $row['completions']);
        }
        $revenue = array();
        if ($money && $goal['kind'] === 'event' && $counts) {
            $sign = 'f.revenue';
            if ($goal['match'] === SEOProStats_Purchases::EVENT) {
                $refunds = SEOProStats_Dict::find(SEOProStats_Schema::DICT_EVENT, array(SEOProStats_Purchases::REFUND));
                if ($refunds) {
                    $refund_id = (int) $refunds[0];
                    $ids[]     = $refund_id;
                    $holders   = implode(', ', array_fill(0, count($ids), '%d'));
                    $from      = "FROM ($sql) a INNER JOIN %i s ON s.id = a.sid INNER JOIN %i f ON f.session_id = a.sid AND f.ts >= a.first WHERE f.%i IN ($holders)$where";
                    $base      = array_merge($args, array(SEOProStats_Schema::table('sessions'), $table, $column), $ids, $compiled['args']);
                    // The ID is an integer from our dictionary, not request SQL.
                    $sign = "CASE WHEN f.name_id = $refund_id THEN -f.revenue ELSE f.revenue END";
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT IF(a.n > 1, 0, a.v) AS bucket, f.currency AS c, SUM($sign) AS r, COUNT(*) AS n $from AND f.revenue <> 0 GROUP BY bucket, f.currency ORDER BY r DESC", $base), ARRAY_A);
            $by   = array();
            foreach ((array) $rows as $row) {
                $by[(int) $row['bucket']][] = $row;
            }
            foreach ($by as $v => $list) {
                $revenue[$v] = SEOProStats_Conversions::money($list);
            }
        }
        return array($counts, $revenue);
    }

    /**
     * Variant slugs by their dictionary ids.
     *
     * @param array<int,int> $ids Dictionary ids (0, the mixed visits, is left out).
     * @return array<int,string>
     */
    private static function slugs(array $ids) {
        return SEOProStats_Dict::values(array_filter(array_map('intval', $ids)));
    }

    /**
     * The test's goals that still exist, in its order.
     *
     * @param string[] $ids Goal ids.
     * @return array<int,array{id:string,name:string,kind:string,match:string}>
     */
    private static function goals(array $ids) {
        $all = array();
        foreach (SEOProStats_Goals::goals() as $goal) {
            $all[$goal['id']] = $goal;
        }
        $out = array();
        foreach ($ids as $id) {
            if (isset($all[$id])) {
                $out[] = array(
                    'id'    => (string) $all[$id]['id'],
                    'name'  => (string) $all[$id]['name'],
                    'kind'  => (string) $all[$id]['kind'],
                    'match' => (string) $all[$id]['match'],
                );
            }
        }
        return $out;
    }

    /**
     * A variant against the control on the primary metric: uplift, its
     * interval, the probability to beat the control, and the variant's
     * verdict (control, too_early, better, worse or unclear).
     *
     * @param array<string,mixed>|null $base The control's numbers.
     * @param array<string,mixed>      $v    The variant's.
     * @param int                      $days Days the test has run.
     * @return array{uplift:float|null,interval:array{0:float,1:float}|null,probability:float|null,verdict:string}
     */
    private static function compare($base, array $v, $days) {
        if ($base === null || $v['control']) {
            return array('uplift' => null, 'interval' => null, 'probability' => null, 'verdict' => 'control');
        }
        $xc = (int) $base['primary']['conversions'];
        $nc = (int) $base['visits'];
        $xv = (int) $v['primary']['conversions'];
        $nv = (int) $v['visits'];
        $p  = $nc && $nv ? self::prob_beat($xc, $nc, $xv, $nv) : null;
        if ($nc < self::MIN_VISITS || $nv < self::MIN_VISITS || $xc + $xv < self::MIN_CONVERSIONS || $days < self::MIN_DAYS || $p === null) {
            $verdict = 'too_early';
        } elseif ($p >= self::CONFIDENCE) {
            $verdict = 'better';
        } elseif ($p <= 1 - self::CONFIDENCE) {
            $verdict = 'worse';
        } else {
            $verdict = 'unclear';
        }
        return array(
            'uplift'      => self::uplift($xc, $nc, $xv, $nv),
            'interval'    => self::interval($xc, $nc, $xv, $nv),
            'probability' => $p === null ? null : round($p, 4),
            'verdict'     => $verdict,
        );
    }

    /**
     * The test's verdict and its leader: the variant with the highest
     * probability to beat the control among those better, else the one
     * with the highest rate.
     *
     * @param array<int,array<string,mixed>> $variants Variants with their comparisons.
     * @param int                            $visits   Visits that saw the test.
     * @param array<string,string>           $primary  The primary metric.
     * @return array{0:array{code:string,text:string},1:array{slug:string,label:string}|null}
     */
    private static function verdict(array $variants, $visits, array $primary) {
        $others = array_values(array_filter($variants, static function ($v) {
            return !$v['control'];
        }));
        $better = array_values(array_filter($others, static function ($v) {
            return $v['primary']['verdict'] === 'better';
        }));
        usort($better, static function ($a, $b) {
            return (float) $b['primary']['probability'] <=> (float) $a['primary']['probability'];
        });
        $code   = self::verdict_code($visits, $others, $better);
        $leader = self::verdict_leader($code, $variants, $better);
        $text   = self::verdict_text($code, $others, $leader, $primary);
        return array(
            array('code' => $code, 'text' => $text),
            $leader && $code !== 'no_data' ? array('slug' => (string) $leader['slug'], 'label' => (string) $leader['label']) : null,
        );
    }

    /**
     * The test's verdict code: no_data (no visits, or one variant), winner
     * (some variant better), too_early (every other too early), control
     * (every other worse), else unclear.
     *
     * @param int                            $visits Visits that saw the test.
     * @param array<int,array<string,mixed>> $others The variants but the control.
     * @param array<int,array<string,mixed>> $better Those better than the control.
     * @return string
     */
    private static function verdict_code($visits, array $others, array $better) {
        if (!$visits || !$others) {
            return 'no_data';
        }
        if ($better) {
            return 'winner';
        }
        $count = static function ($verdict) use ($others) {
            return count(array_filter($others, static function ($v) use ($verdict) {
                return $v['primary']['verdict'] === $verdict;
            }));
        };
        if ($count('too_early') === count($others)) {
            return 'too_early';
        }
        return $count('worse') === count($others) ? 'control' : 'unclear';
    }

    /**
     * The test's leader for a verdict: the most likely better variant for
     * winner, the control for control, else the one with the highest rate
     * (with visits).
     *
     * @param string                         $code     From verdict_code().
     * @param array<int,array<string,mixed>> $variants Variants with their comparisons.
     * @param array<int,array<string,mixed>> $better   Those better than the control, most likely first.
     * @return array<string,mixed>|null
     */
    private static function verdict_leader($code, array $variants, array $better) {
        if ($code === 'winner') {
            return $better[0];
        }
        $leader = null;
        $best   = -1.0;
        foreach ($variants as $v) {
            if ($v['visits'] && (float) $v['primary']['rate'] > $best) {
                $best   = (float) $v['primary']['rate'];
                $leader = $v;
            }
        }
        if ($code === 'control') {
            foreach ($variants as $v) {
                if ($v['control']) {
                    $leader = $v;
                }
            }
        }
        return $leader;
    }

    /**
     * The verdict in words.
     *
     * @param string                         $code    From verdict_code().
     * @param array<int,array<string,mixed>> $others  The variants but the control.
     * @param array<string,mixed>|null       $leader  From verdict_leader().
     * @param array<string,string>           $primary The primary metric.
     * @return string
     */
    private static function verdict_text($code, array $others, $leader, array $primary) {
        switch ($code) {
            case 'no_data':
                return $others ? __('No visits have seen this test yet.', 'seoprostats') : __('This test has only one variant.', 'seoprostats');
            case 'winner':
                /* translators: 1: variant label, 2: probability to beat the control, such as 97%, 3: the metric, such as Purchase */
                return sprintf(__('%1$s does better: a %2$s chance to beat the control on %3$s.', 'seoprostats'), $leader['label'], self::percent((float) $leader['primary']['probability']), $primary['name']);
            case 'too_early':
                /* translators: 1: visits each variant needs, 2: conversions needed, 3: days */
                return sprintf(__('Too early to call: each variant needs %1$d visits, %2$d conversions between it and the control, and the test %3$d days.', 'seoprostats'), self::MIN_VISITS, self::MIN_CONVERSIONS, self::MIN_DAYS);
            case 'control':
                /* translators: %s: the metric, such as Purchase */
                return sprintf(__('The control does best on %s: every other variant does worse.', 'seoprostats'), $primary['name']);
            default:
                /* translators: %s: the metric, such as Purchase */
                return sprintf(__('No clear difference on %s yet: keep the test running, or end it if the difference is too small to matter.', 'seoprostats'), $primary['name']);
        }
    }

    /**
     * A probability as a whole percentage, kept off 0% and 100%: never
     * certain, even when rounding to four places gives 0 or 1.
     *
     * @param float $p Probability.
     * @return string
     */
    private static function percent($p) {
        $n = (int) round($p * 100);
        if ($n >= 100) {
            return '>99%';
        }
        if ($n <= 0) {
            return '<1%';
        }
        return $n . '%';
    }

    // ------------------------------------------------------------------
    // Statistics.

    /**
     * Relative change of the variant's rate from the control's, or null
     * when the control's rate is 0.
     *
     * @param int $xc Control conversions.
     * @param int $nc Control visits.
     * @param int $xv Variant conversions.
     * @param int $nv Variant visits.
     * @return float|null
     */
    public static function uplift($xc, $nc, $xv, $nv) {
        if (!$nc || !$nv || !$xc) {
            return null;
        }
        return round(($xv / $nv) / ($xc / $nc) - 1, 4);
    }

    /**
     * 95% interval of the uplift: the log of the rate ratio, normal
     * (Katz). Null without a conversion on either side.
     *
     * @param int $xc Control conversions.
     * @param int $nc Control visits.
     * @param int $xv Variant conversions.
     * @param int $nv Variant visits.
     * @return array{0:float,1:float}|null
     */
    public static function interval($xc, $nc, $xv, $nv) {
        if (!$xc || !$xv || !$nc || !$nv) {
            return null;
        }
        $log = log(($xv / $nv) / ($xc / $nc));
        $se  = sqrt(max(0.0, 1 / $xv - 1 / $nv + 1 / $xc - 1 / $nc));
        return array(round(exp($log - 1.959964 * $se) - 1, 4), round(exp($log + 1.959964 * $se) - 1, 4));
    }

    /**
     * Probability that the variant's true rate is above the control's,
     * with a uniform prior on each (posteriors Beta(1 + x, 1 + n − x)):
     *
     *   P(B > A) = Σ_{i=0}^{αB−1} B(αA + i, βA + βB) / ((βB + i) B(1 + i, βB) B(αA, βA))
     *
     * summed over the side with fewer conversions (P(B > A) = 1 − P(A > B)),
     * in logs. Past MAX_EXACT terms, the normal approximation of the two
     * posteriors.
     *
     * @param int $xc Control conversions.
     * @param int $nc Control visits.
     * @param int $xv Variant conversions.
     * @param int $nv Variant visits.
     * @return float
     */
    public static function prob_beat($xc, $nc, $xv, $nv) {
        $xc = max(0, min((int) $xc, (int) $nc));
        $xv = max(0, min((int) $xv, (int) $nv));
        $aa = 1 + $xc;
        $ba = 1 + (int) $nc - $xc;
        $ab = 1 + $xv;
        $bb = 1 + (int) $nv - $xv;
        if (min($aa, $ab) > self::MAX_EXACT) {
            $ma = $aa / ($aa + $ba);
            $mb = $ab / ($ab + $bb);
            $va = $aa * $ba / (($aa + $ba) ** 2 * ($aa + $ba + 1));
            $vb = $ab * $bb / (($ab + $bb) ** 2 * ($ab + $bb + 1));
            return self::normal_cdf(($mb - $ma) / sqrt(max(1e-300, $va + $vb)));
        }
        if ($ab <= $aa) {
            return self::clamp(self::beta_sum($aa, $ba, $ab, $bb));
        }
        return self::clamp(1 - self::beta_sum($ab, $bb, $aa, $ba)); // NOSONAR: swapped on purpose, P(B > A) = 1 - P(A > B).
    }

    /**
     * P(B > A) for A ~ Beta(aa, ba) and B ~ Beta(ab, bb), summed over ab
     * terms.
     *
     * @param int $aa A's alpha.
     * @param int $ba A's beta.
     * @param int $ab B's alpha.
     * @param int $bb B's beta.
     * @return float
     */
    private static function beta_sum($aa, $ba, $ab, $bb) {
        $sum   = 0.0;
        $fixed = self::log_beta($aa, $ba);
        for ($i = 0; $i < $ab; $i++) {
            $sum += exp(self::log_beta($aa + $i, $ba + $bb) - log($bb + $i) - self::log_beta(1 + $i, $bb) - $fixed);
        }
        return $sum;
    }

    /**
     * A probability kept in [0, 1] (rounding in long sums).
     *
     * @param float $p Value.
     * @return float
     */
    private static function clamp($p) {
        return max(0.0, min(1.0, (float) $p));
    }

    /**
     * log B(a, b).
     *
     * @param float $a Positive.
     * @param float $b Positive.
     * @return float
     */
    public static function log_beta($a, $b) {
        return self::log_gamma($a) + self::log_gamma($b) - self::log_gamma($a + $b);
    }

    /**
     * log Γ(x) for x > 0 (Lanczos, g = 7, nine terms: about 15 digits).
     *
     * @param float $x Positive.
     * @return float
     */
    public static function log_gamma($x) {
        static $c = array(
            0.99999999999980993,
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716e-6,
            1.5056327351493116e-7,
        );
        $x = (float) $x;
        if ($x < 0.5) {
            // Reflection: Γ(x) Γ(1 − x) = π / sin(πx).
            return log(M_PI / abs(sin(M_PI * $x))) - self::log_gamma(1 - $x);
        }
        $x  -= 1;
        $sum = $c[0];
        for ($i = 1; $i < 9; $i++) {
            $sum += $c[$i] / ($x + $i);
        }
        $t = $x + 7.5;
        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($sum);
    }

    /**
     * The standard normal distribution function (Abramowitz and Stegun
     * 7.1.26 for erf; error under 1.5e-7).
     *
     * @param float $z Value.
     * @return float
     */
    public static function normal_cdf($z) {
        $x    = abs($z) / M_SQRT2;
        $t    = 1 / (1 + 0.3275911 * $x);
        $erf  = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);
        return $z >= 0 ? 0.5 * (1 + $erf) : 0.5 * (1 - $erf);
    }
}
