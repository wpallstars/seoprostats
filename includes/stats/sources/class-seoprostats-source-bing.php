<?php
/**
 * Bing Webmaster Tools, connected with the owner's API key (Bing
 * Webmaster Tools → Settings → API access). No OAuth app or outside
 * server.
 *
 * Bing's API answers whole periods at once, not one day at a time:
 *
 * - the site's clicks and impressions by day (no position);
 * - the top queries and the top pages by week, each with clicks,
 *   impressions and average position; a week dated D holds the seven
 *   days before D, so its figures are stored on its last day (D − 1);
 * - one page's queries by week, one request per page.
 *
 * So the first request of a run reads the three site-wide answers, and
 * day() hands the import one day of them, as Search Console's source
 * does. A day is final once its week is in (Bing gives the weeks a few
 * days after they end). Bing gives no position per day: a day's is its
 * week's, the average over the week's queries weighted by impressions.
 * No devices or countries. Requests come only from cron, WP-CLI or an
 * administrator's action. Design: docs/architecture.md → Integrations →
 * Bing Webmaster Tools.
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

final class SEOProStats_Source_Bing {

    /** Source key (SEOProStats_Connections::SOURCES). */
    const KEY = 'bing';

    /** Name shown. */
    const NAME = 'Bing Webmaster Tools';

    /** Search engine of its rows. */
    const ENGINE = SEOProStats_Schema::ENGINE_BING;

    /** The Bing Webmaster API (JSON). */
    const API = 'https://ssl.bing.com/webmaster/api.svc/json/';

    /** Months of history Bing gives, imported on connecting. */
    const MONTHS = 16;

    /** Bing's days are UTC. */
    const TIMEZONE = 'UTC';

    /** The weekday a week ends on (ISO-8601: 4 is Thursday) before any week is seen. */
    const WEEK_END = 4;

    /** Pages whose queries are imported at most (one request each), most clicks first. */
    const PAIR_PAGES = 300;

    /** Late weeks: a page's queries come some days after its own figures, so the last weeks are asked again. */
    const PAIR_LAG_DAYS = 21;

    /** Seconds a request may take. */
    const TIMEOUT = 30;

    /** Whether pages' queries are imported page by page after the days (SEOProStats_Search_Import). */
    const PAIRS_BY_PAGE = true;

    /** @var array<string,array<string,mixed>> The site-wide answers by site, for this request. */
    private static $data = array();

    /**
     * Check what the owner gave (an API key, and maybe a site) by listing
     * the key's verified sites, and say what to store.
     *
     * @param array<string,mixed>      $input    key (the API key; '' keeps the saved one), property (site; '' to choose).
     * @param array<string,mixed>|null $existing Saved credentials, or null.
     * @return array{credentials:array<string,mixed>,settings:array<string,mixed>,properties:array<string,string>}|WP_Error
     */
    public static function connect(array $input, $existing) {
        $key = isset($input['key']) ? trim((string) $input['key']) : '';
        if ($key === '' && is_array($existing) && !empty($existing['key'])) {
            $key = (string) $existing['key'];
        }
        if ($key === '') {
            return new WP_Error('seoprostats_key_missing', __('Paste the API key from Bing Webmaster Tools (Settings → API access).', 'seoprostats'));
        }
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
            return new WP_Error('seoprostats_key_format', __('That is not a Bing Webmaster Tools API key: it is a line of letters and numbers, from Settings → API access.', 'seoprostats'));
        }
        $sites = self::sites($key);
        if (is_wp_error($sites)) {
            return $sites;
        }
        $site = self::pick_site($sites, isset($input['property']) ? (string) $input['property'] : '');
        if (is_wp_error($site)) {
            $site->add_data(array('status' => 400, 'properties' => array_keys($sites)));
            return $site;
        }
        self::$data = array();
        return array(
            'credentials' => array('key' => $key),
            'settings'    => array(
                'property' => $site,
                'account'  => '',
            ),
            'properties'  => $sites,
        );
    }

    /**
     * The key itself is what signs requests (SEOProStats_Search_Import
     * asks every source for a token).
     *
     * @param array<string,mixed> $credentials Saved credentials.
     * @return string|WP_Error
     */
    public static function token(array $credentials) {
        $key = isset($credentials['key']) ? (string) $credentials['key'] : '';
        return $key !== '' ? $key : new WP_Error('seoprostats_key_missing', __('The saved API key is missing. Connect Bing Webmaster Tools again.', 'seoprostats'));
    }

    /**
     * The key's verified sites: address => "verified".
     *
     * @param string $key API key.
     * @return array<string,string>|WP_Error
     */
    public static function sites($key) {
        $answer = self::request('GetUserSites', $key);
        if (is_wp_error($answer)) {
            return $answer;
        }
        $out = array();
        foreach ($answer as $site) {
            if (is_array($site) && !empty($site['Url']) && !empty($site['IsVerified'])) {
                $out[(string) $site['Url']] = 'verified';
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The site to import: the one asked for when the key has it verified,
     * else the one for this site's address (www or not; the longest
     * matching address).
     *
     * @param array<string,string> $sites  From sites().
     * @param string               $wanted Site asked for, or '' to choose.
     * @return string|WP_Error
     */
    public static function pick_site(array $sites, $wanted = '') {
        $wanted = trim((string) $wanted);
        if ($wanted !== '') {
            foreach (array_keys($sites) as $site) {
                if (untrailingslashit(strtolower($site)) === untrailingslashit(strtolower($wanted))) {
                    return $site;
                }
            }
            /* translators: %s: a site's address */
            return new WP_Error('seoprostats_property_access', sprintf(__('%s is not a verified site of this API key in Bing Webmaster Tools.', 'seoprostats'), $wanted));
        }
        $bare = static function ($url) {
            return strtolower((string) preg_replace('#^https?://(www\.)?#i', '', (string) $url));
        };
        $home = $bare(home_url('/'));
        $best = '';
        foreach (array_keys($sites) as $site) {
            $prefix = rtrim($bare($site), '/') . '/';
            if ($prefix !== '/' && strpos($home, $prefix) === 0 && strlen($site) > strlen($best)) {
                $best = $site;
            }
        }
        if ($best !== '') {
            return $best;
        }
        if (!$sites) {
            return new WP_Error('seoprostats_property_none', __('This API key has no verified sites in Bing Webmaster Tools yet. Add and verify this site there (it can import it from Google Search Console), then connect again.', 'seoprostats'));
        }
        /* translators: %s: the site's address */
        return new WP_Error('seoprostats_property_choose', sprintf(__('None of this key\'s verified sites in Bing Webmaster Tools is %s. Choose one, or add this site there.', 'seoprostats'), home_url('/')));
    }

    /**
     * Today in Bing's time zone (Y-m-d).
     *
     * @return string
     */
    public static function today() {
        return gmdate('Y-m-d');
    }

    /**
     * The last final day: the end of the newest week Bing has given, but
     * not past its newest day. A site with no recent weeks (few searches)
     * waits for a week to be nine days old, by then surely given.
     *
     * @param string $key  API key.
     * @param string $site Site.
     * @return string|WP_Error Y-m-d, or '' when Bing has nothing.
     */
    public static function final_through($key, $site) {
        $data = self::load($key, $site);
        if (is_wp_error($data)) {
            return $data;
        }
        $days = array_map('strval', array_keys($data['totals']));
        if (!$days) {
            return '';
        }
        $last  = max($days);
        $ends  = array_keys($data['ends']);
        $newest = $ends ? (string) max($ends) : '';
        if ($newest !== '' && $newest >= self::shift($last, -13)) {
            return min($last, $newest);
        }
        $end = self::week_end(self::shift(self::today(), -9), $data['weekday']);
        return min($last, $end);
    }

    /**
     * The days in a range with any impression.
     *
     * @param string $key  API key.
     * @param string $site Site.
     * @param string $from Y-m-d.
     * @param string $to   Y-m-d.
     * @return string[]|WP_Error Y-m-d, oldest first.
     */
    public static function days($key, $site, $from, $to) {
        $data = self::load($key, $site);
        if (is_wp_error($data)) {
            return $data;
        }
        $out = array();
        foreach ($data['totals'] as $day => $row) {
            if ($day >= $from && $day <= $to && $row[1] > 0) {
                $out[] = (string) $day;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * One day's rows: the site's (no device or country), and on the last
     * day of a week, the week's pages and queries. Pages with their
     * queries come page by page (page_pairs()).
     *
     * @param string            $key    API key.
     * @param string            $site   Site.
     * @param string            $day    Y-m-d.
     * @param array<string,int> $limits Most rows per kind (pages, queries).
     * @return array<string,array<int,array<string,mixed>>>|WP_Error Kind => rows (keys, clicks, impressions, position).
     */
    public static function day($key, $site, $day, array $limits) {
        $data = self::load($key, $site);
        if (is_wp_error($data)) {
            return $data;
        }
        $out = array('totals' => array(), 'pages' => array(), 'queries' => array());
        if (isset($data['totals'][$day]) && ($data['totals'][$day][0] || $data['totals'][$day][1])) {
            $out['totals'][] = array(
                'keys'        => array('', ''),
                'clicks'      => $data['totals'][$day][0],
                'impressions' => $data['totals'][$day][1],
                'position'    => self::day_position($data, $day),
            );
        }
        foreach (array('pages', 'queries') as $kind) {
            if (isset($data[$kind][$day])) {
                $limit      = isset($limits[$kind]) ? max(1, (int) $limits[$kind]) : 1000;
                $out[$kind] = array_slice($data[$kind][$day], 0, $limit);
            }
        }
        return $out;
    }

    /**
     * The pages whose queries to import for weeks ending in a range, most
     * clicks (then impressions) first: their addresses.
     *
     * @param string $key   API key.
     * @param string $site  Site.
     * @param string $from  Y-m-d.
     * @param string $to    Y-m-d.
     * @return string[]|WP_Error
     */
    public static function pair_pages($key, $site, $from, $to) {
        $data = self::load($key, $site);
        if (is_wp_error($data)) {
            return $data;
        }
        $sums = array();
        foreach ($data['pages'] as $day => $rows) {
            if ($day < $from || $day > $to) {
                continue;
            }
            foreach ($rows as $row) {
                $url = (string) $row['keys'][0];
                if (!isset($sums[$url])) {
                    $sums[$url] = array(0, 0);
                }
                $sums[$url][0] += (int) $row['clicks'];
                $sums[$url][1] += (int) $row['impressions'];
            }
        }
        uksort($sums, static function ($a, $b) use ($sums) {
            return array($sums[$b][0], $sums[$b][1], $a) <=> array($sums[$a][0], $sums[$a][1], $b);
        });
        return array_slice(array_keys($sums), 0, self::PAIR_PAGES);
    }

    /**
     * One page's queries for weeks ending in a range, by the week's last
     * day (one request).
     *
     * @param string $key  API key.
     * @param string $site Site.
     * @param string $url  The page's address, as Bing gave it.
     * @param string $from Y-m-d.
     * @param string $to   Y-m-d.
     * @return array<string,array<int,array<string,mixed>>>|WP_Error Day => rows (keys: page, query).
     */
    public static function page_pairs($key, $site, $url, $from, $to) {
        $answer = self::request('GetPageQueryStats', $key, array('siteUrl' => $site, 'page' => $url));
        if (is_wp_error($answer)) {
            return $answer;
        }
        $out = array();
        foreach (self::weekly($answer) as $day => $rows) {
            if ($day < $from || $day > $to) {
                continue;
            }
            foreach ($rows as $row) {
                $row['keys'] = array($url, $row['keys'][0]);
                $out[$day][] = $row;
            }
        }
        return $out;
    }

    /**
     * The site-wide answers (three requests, once per request): totals
     * by day, pages and queries by the last day of their week, each
     * week's position and the weekday weeks end on.
     *
     * @param string $key  API key.
     * @param string $site Site.
     * @return array<string,mixed>|WP_Error
     */
    private static function load($key, $site) {
        if (isset(self::$data[$site])) {
            return self::$data[$site];
        }
        $answers = array();
        foreach (array('totals' => 'GetRankAndTrafficStats', 'queries' => 'GetQueryStats', 'pages' => 'GetPageStats') as $kind => $method) {
            $answers[$kind] = self::request($method, $key, array('siteUrl' => $site));
            if (is_wp_error($answers[$kind])) {
                return $answers[$kind];
            }
        }
        $data = array(
            'totals'  => self::daily_totals($answers['totals']),
            'queries' => self::weekly($answers['queries']),
            'pages'   => self::weekly($answers['pages']),
            'ends'    => array(),
            'weekday' => self::WEEK_END,
        );
        $data['ends'] = self::week_positions($data['pages'], $data['queries']);
        ksort($data['ends']);
        if ($data['ends']) {
            $data['weekday'] = (int) (new DateTimeImmutable((string) array_key_last($data['ends']), new DateTimeZone('UTC')))->format('N');
        }
        self::$data[$site] = $data;
        return $data;
    }

    /**
     * Clicks and impressions by day from Bing's traffic rows.
     *
     * @param array<int,mixed> $rows Bing's rows.
     * @return array<string,array{0:int,1:int}> Y-m-d => clicks, impressions.
     */
    private static function daily_totals(array $rows) {
        $totals = array();
        foreach ($rows as $row) {
            $day = is_array($row) && isset($row['Date']) ? self::date((string) $row['Date']) : '';
            if ($day !== '') {
                $totals[$day] = array(max(0, (int) ($row['Clicks'] ?? 0)), max(0, (int) ($row['Impressions'] ?? 0)));
            }
        }
        return $totals;
    }

    /**
     * Each week's position: its queries', else its pages', else 0.
     *
     * @param array<string,array<int,array<string,mixed>>> $pages   From weekly().
     * @param array<string,array<int,array<string,mixed>>> $queries From weekly().
     * @return array<string,float> The week's last day => position.
     */
    private static function week_positions(array $pages, array $queries) {
        $ends = array();
        foreach (array($pages, $queries) as $weeks) {
            foreach ($weeks as $day => $rows) {
                $position = self::week_position($rows);
                if ($position !== null) {
                    $ends[$day] = $position;
                } elseif (!isset($ends[$day])) {
                    $ends[$day] = 0.0;
                }
            }
        }
        return $ends;
    }

    /**
     * A week's rows' position, weighted by impressions; null when none
     * has a position.
     *
     * @param array<int,array<string,mixed>> $rows From weekly().
     * @return float|null
     */
    private static function week_position(array $rows) {
        $sum = 0.0;
        $imp = 0;
        foreach ($rows as $row) {
            if ($row['position'] > 0) {
                $sum += $row['position'] * $row['impressions'];
                $imp += $row['impressions'];
            }
        }
        return $imp > 0 ? $sum / $imp : null;
    }

    /**
     * Weekly rows (pages, queries, or a page's queries) by the last day of
     * their week: keys (the page or query), clicks, impressions, position.
     *
     * @param array<int,mixed> $rows Bing's rows.
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function weekly(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['Date'], $row['Query']) || (string) $row['Query'] === '') {
                continue;
            }
            $day = self::date((string) $row['Date']);
            if ($day === '') {
                continue;
            }
            $out[self::shift($day, -1)][] = array(
                'keys'        => array((string) $row['Query']),
                'clicks'      => max(0, (int) ($row['Clicks'] ?? 0)),
                'impressions' => max(0, (int) ($row['Impressions'] ?? 0)),
                'position'    => max(0.0, (float) ($row['AvgImpressionPosition'] ?? 0)),
            );
        }
        foreach ($out as &$list) {
            usort($list, static function ($a, $b) {
                return array($b['clicks'], $b['impressions']) <=> array($a['clicks'], $a['impressions']);
            });
        }
        unset($list);
        ksort($out);
        return $out;
    }

    /**
     * A day's position: its week's; a week without one takes the nearest
     * week's that has one; 0 when no week has.
     *
     * @param array<string,mixed> $data From load().
     * @param string              $day  Y-m-d.
     * @return float
     */
    private static function day_position(array $data, $day) {
        $end = self::week_end($day, (int) $data['weekday'], true);
        if (!empty($data['ends'][$end])) {
            return (float) $data['ends'][$end];
        }
        $best = 0.0;
        $gap  = PHP_INT_MAX;
        $at   = strtotime($day . ' UTC');
        foreach ($data['ends'] as $other => $position) {
            $apart = abs(strtotime($other . ' UTC') - $at);
            if ($position > 0 && $apart < $gap) {
                $best = (float) $position;
                $gap  = $apart;
            }
        }
        return $best;
    }

    /**
     * The last day of a week: the latest weekday $weekday on or before a
     * day, or (with $after) on or after it.
     *
     * @param string $day     Y-m-d.
     * @param int    $weekday ISO-8601 weekday (1 Monday … 7 Sunday).
     * @param bool   $after   On or after the day.
     * @return string Y-m-d.
     */
    public static function week_end($day, $weekday, $after = false) {
        $n    = (int) (new DateTimeImmutable($day, new DateTimeZone('UTC')))->format('N');
        $diff = $after ? ($weekday - $n + 7) % 7 : -(($n - $weekday + 7) % 7);
        return self::shift($day, $diff);
    }

    /**
     * A Bing date (/Date(1789689600000)/, maybe with an offset) as Y-m-d.
     *
     * @param string $value Bing's date.
     * @return string Y-m-d, or ''.
     */
    private static function date($value) {
        if (!preg_match('/\((-?\d+)/', $value, $m)) {
            return '';
        }
        return gmdate('Y-m-d', (int) floor((int) $m[1] / 1000));
    }

    /**
     * A day moved by days.
     *
     * @param string $day  Y-m-d.
     * @param int    $days Days.
     * @return string Y-m-d.
     */
    private static function shift($day, $days) {
        return (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * A request to the Bing Webmaster API: its list (the answer's d).
     * The key goes in the address, as Bing asks; it is never in an error.
     *
     * @param string               $method The API's method.
     * @param string               $key    API key.
     * @param array<string,string> $args   Parameters.
     * @return array<int,mixed>|WP_Error
     */
    private static function request($method, $key, array $args = array()) {
        $url      = add_query_arg(array_map('rawurlencode', array('apikey' => $key) + $args), self::API . $method);
        $response = wp_remote_get($url, array(
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'SEO Pro Stats/' . SEOPROSTATS_VERSION . ' (WordPress plugin)',
            'headers'    => array('Accept' => 'application/json'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('seoprostats_bing_request', str_replace($key, '…', $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($data) && array_key_exists('d', $data)) {
            return is_array($data['d']) ? $data['d'] : array();
        }
        $error = is_array($data) && isset($data['ErrorCode']) ? (int) $data['ErrorCode'] : 0;
        if ($error === 3) {
            return new WP_Error('seoprostats_bing_key', __('Bing refused the API key. Copy it again from Bing Webmaster Tools → Settings → API access.', 'seoprostats'), array('status' => 400));
        }
        if ($error === 14) {
            return new WP_Error('seoprostats_bing_site', __('Bing says this API key may not read that site. Check it is verified in the same Bing Webmaster Tools account.', 'seoprostats'), array('status' => 400));
        }
        $message = is_array($data) && isset($data['Message']) ? trim(str_replace('ERROR!!!', '', (string) $data['Message'])) : '';
        /* translators: 1: HTTP status code, 2: Bing's message */
        return new WP_Error('seoprostats_bing_' . $code, sprintf(__('HTTP %1$d: %2$s', 'seoprostats'), $code, $message !== '' ? sanitize_text_field(str_replace($key, '…', $message)) : __('no message', 'seoprostats')), array('status' => $code));
    }
}
