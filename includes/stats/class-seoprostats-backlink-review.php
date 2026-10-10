<?php
/**
 * Explained local backlink review and Google-format export. No submission.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Backlink_Review {
    const DECISIONS = array('keep', 'disavow', 'undecided');
    const MAX_BYTES = 2097152;
    const MAX_LINES = 100000;
    const MAX_URL = 2048;
    const SPAM_WORDS = array('casino', 'poker', 'viagra', 'cialis', 'pills', 'payday loans', 'porn', 'xxx');
    const TLD_PATTERNS = array('click', 'gq', 'tk', 'work');

    /** A stable full-length review key. @param string $scope Scope. @param string $target Target. @return string */
    private static function key($scope, $target) {
        return hash('sha256', $scope . "\t" . $target);
    }

    /** Validate without rewriting source URLs. @param string $scope Scope. @param string $target Target. @return bool */
    public static function valid($scope, $target) {
        if ($scope === 'domain') {
            return strlen($target) <= 253 && strpos($target, '.') !== false && !filter_var($target, FILTER_VALIDATE_IP) && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,57}[a-z0-9])?)$/D', $target);
        }
        if ($scope !== 'url' || preg_match('/[\x00-\x20\x7f]/', $target) || preg_match('//u', $target) !== 1) {
            return false;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($target, 'UTF-8') : preg_match_all('/./u', $target);
        $url = wp_parse_url($target);
        return $length <= self::MAX_URL && is_array($url) && isset($url['host'], $url['scheme']) && in_array($url['scheme'], array('http', 'https'), true) && !isset($url['user']) && !isset($url['pass']) && self::valid('domain', strtolower($url['host']));
    }

    /** Save an explicit local decision. @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
    public static function decide(array $input) {
        global $wpdb;
        $scope = isset($input['scope']) ? (string) $input['scope'] : '';
        $target = isset($input['target']) ? (string) $input['target'] : '';
        $decision = isset($input['decision']) ? (string) $input['decision'] : '';
        if (!self::valid($scope, $target) || !in_array($decision, self::DECISIONS, true)) {
            return self::error();
        }
        $row = array('scope' => $scope, 'target' => $target, 'decision' => $decision, 'imported' => false, 'user_id' => get_current_user_id(), 'reviewed' => time());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by unique rkey; no report cache contains decisions.
        $ok = $wpdb->query($wpdb->prepare('INSERT INTO %i (rkey, scope, target, decision, imported, user_id, reviewed) VALUES (UNHEX(%s), %s, %s, %s, 0, %d, %d) ON DUPLICATE KEY UPDATE decision = VALUES(decision), imported = 0, user_id = VALUES(user_id), reviewed = VALUES(reviewed)', SEOProStats_Schema::table('link_reviews'), self::key($scope, $target), $scope, $target, $decision, $row['user_id'], $row['reviewed']));
        return $ok === false ? self::error() : $row;
    }

    /** Read an existing list before any write; comments count toward limits. @param string $text Text. @return array<string,array{scope:string,target:string}>|WP_Error */
    public static function parse($text) {
        if (strlen($text) > self::MAX_BYTES || preg_match('//u', $text) !== 1) {
            return self::error();
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        if (!$lines || count($lines) - (end($lines) === '' ? 1 : 0) > self::MAX_LINES) {
            return self::error();
        }
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $scope = strpos($line, 'domain:') === 0 ? 'domain' : 'url';
            $target = $scope === 'domain' ? strtolower(substr($line, 7)) : $line;
            if (!self::valid($scope, $target)) {
                return self::error();
            }
            $out[self::key($scope, $target)] = array('scope' => $scope, 'target' => $target);
        }
        return $out;
    }

    /** Preserve an owner's old list locally, with provenance, atomically. @param string $text Text. @return array<string,int>|WP_Error */
    public static function merge($text) {
        global $wpdb;
        $entries = self::parse($text);
        if (is_wp_error($entries)) {
            return $entries;
        }
        // Verify combined output too, before retaining any entries.
        $export = self::export($text);
        if (is_wp_error($export)) {
            return $export;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, unique key writes within one transaction.
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::error();
        }
        foreach (array_chunk($entries, 500, true) as $chunk) {
            $args = array(SEOProStats_Schema::table('link_reviews'));
            foreach ($chunk as $key => $entry) {
                array_push($args, $key, $entry['scope'], $entry['target'], get_current_user_id(), time());
            }
            $holders = implode(', ', array_fill(0, count($chunk), "(UNHEX(%s), %s, %s, 'disavow', 1, %d, %d)"));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- holders contains only fixed placeholder groups, values are separately prepared.
            $ok = $wpdb->query($wpdb->prepare("INSERT INTO %i (rkey, scope, target, decision, imported, user_id, reviewed) VALUES $holders ON DUPLICATE KEY UPDATE rkey = VALUES(rkey)", $args));
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                return self::error();
            }
            // Insert/no-op has locked every key, including rows that raced an
            // absent-key read under READ COMMITTED. Validate the locked result
            // before commit, never silently omit a supplied entry.
            $keys = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- keys contains only placeholder groups; unique-key reads retain locks until commit.
            $existing = $wpdb->get_results($wpdb->prepare("SELECT decision FROM %i WHERE rkey IN ($keys) FOR UPDATE", array_merge(array(SEOProStats_Schema::table('link_reviews')), array_keys($chunk))), ARRAY_A);
            if ($existing === null || $wpdb->last_error !== '' || count($existing) !== count($chunk)) {
                $wpdb->query('ROLLBACK');
                return self::error();
            }
            foreach ($existing as $row) {
                if ($row['decision'] !== 'disavow') {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('seoprostats_disavow_conflict', __('A prior-list entry already has a keep or undecided decision. Change that decision explicitly, or remove the entry from the supplied list before merging. No entries were saved.', 'seoprostats'), array('status' => 400));
                }
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return self::error();
        }
        // phpcs:enable
        return array('entries' => count($entries));
    }

    /** Bounded indexed decisions, including ones without a known backlink. @param string $decision Filter. @return array<int,array<string,mixed>>|WP_Error */
    private static function decisions($decision) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- decision_id range in its order, bounded to detect oversized output.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT scope, target, decision, imported, user_id, reviewed FROM %i FORCE INDEX (`decision_id`) WHERE decision = %s ORDER BY id LIMIT %d', SEOProStats_Schema::table('link_reviews'), $decision, self::MAX_LINES + 1), ARRAY_A);
        return $rows === null || $wpdb->last_error !== '' ? self::error() : $rows;
    }

    /** Google text, never truncated and never uploaded. @param string $merge Existing text. @return string|WP_Error */
    public static function export($merge = '') {
        $entries = self::parse($merge);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $rows = self::decisions('disavow');
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (count($rows) > self::MAX_LINES - 3) {
            return self::error();
        }
        foreach ($rows as $row) {
            if (!self::valid((string) $row['scope'], (string) $row['target'])) {
                return self::error();
            }
            $entries[self::key($row['scope'], $row['target'])] = array('scope' => $row['scope'], 'target' => $row['target']);
        }
        $domains = array();
        $urls = array();
        foreach ($entries as $entry) {
            if ($entry['scope'] === 'domain') {
                $domains[] = 'domain:' . $entry['target'];
            } else {
                $urls[] = $entry['target'];
            }
        }
        sort($domains, SORT_STRING);
        sort($urls, SORT_STRING);
        $site = preg_replace('/[\r\n\x00-\x1f]/', '', home_url('/'));
        $lines = array_merge(array('# SEO Pro Stats — ' . $site, '# ' . gmdate('Y-m-d') . ' UTC; domains: ' . count($domains) . '; URLs: ' . count($urls), '# Upload replaces the previous list. URL-prefix properties only. Nothing has been submitted.'), $domains, $urls);
        $out = implode("\n", $lines) . "\n";
        return count($lines) > self::MAX_LINES || strlen($out) > self::MAX_BYTES ? self::error() : $out;
    }

    /** Review observed links (bounded like the backlinks report), with decisions even outside that sample. @param array<string,mixed> $req Request. @return array<string,mixed>|WP_Error */
    public static function report(array $req) {
        require_once __DIR__ . '/class-seoprostats-backlinks.php';
        $links = array();
        foreach (array('links', 'lost') as $kind) {
            for ($offset = 0; $offset < SEOProStats_Backlinks::MAX_ROWS; $offset += SEOProStats_Backlinks::MAX_LIMIT) {
                $answer = SEOProStats_Backlinks::report(array_merge($req, array('limit' => SEOProStats_Backlinks::MAX_LIMIT, 'offset' => $offset)), $kind);
                if (is_wp_error($answer)) {
                    return $answer;
                }
                $links = array_merge($links, $answer['rows']);
                if (!$answer['more']) {
                    break;
                }
            }
        }
        $decisions = array();
        foreach (self::DECISIONS as $decision) {
            $rows = self::decisions($decision);
            if (is_wp_error($rows) || count($rows) > self::MAX_LINES) {
                return self::error();
            }
            foreach ($rows as $row) {
                $row['imported'] = (bool) $row['imported'];
                $row['user_id'] = (int) $row['user_id'];
                $row['reviewed'] = (int) $row['reviewed'];
                $decisions[self::key($row['scope'], $row['target'])] = $row;
            }
        }
        $sites = array();
        foreach ($links as $link) {
            $host = $link['host'];
            if (!isset($sites[$host])) {
                $sites[$host] = array('host' => $host, 'score' => 0, 'reasons' => array(), 'links' => array(), 'decision' => self::decision($decisions, 'domain', $host));
            }
            $link['decision'] = self::decision($decisions, 'url', $link['source']);
            $link['reasons'] = self::signals($link);
            $link['score'] = min(100, array_sum(array_column($link['reasons'], 'weight')));
            if ($link['decision']['decision'] === 'keep' || self::kept_domain($decisions, $host)) {
                $link['reasons'] = array();
                $link['score'] = 0;
            }
            $sites[$host]['links'][] = $link;
        }
        // Decisions from a prior list remain visible even with no sampled links.
        $urls_by_host = array();
        foreach ($decisions as $row) {
            $host = $row['scope'] === 'domain' ? $row['target'] : strtolower((string) wp_parse_url($row['target'], PHP_URL_HOST));
            if ($row['scope'] === 'url') {
                $urls_by_host[$host][] = $row;
            }
            if (!isset($sites[$host])) {
                $sites[$host] = array('host' => $host, 'score' => 0, 'reasons' => array(), 'links' => array(), 'decision' => self::decision($decisions, 'domain', $host));
            }
        }
        foreach ($sites as &$site) {
            $reasons = array();
            foreach ($site['links'] as $link) {
                foreach ($link['reasons'] as $reason) {
                    $reasons[$reason['signal']] = $reason;
                }
            }
            $unkept = array_filter($site['links'], static function ($link) {
                return $link['decision']['decision'] !== 'keep';
            });
            if (!self::kept_domain($decisions, $site['host']) && count($unkept) >= 20 && count(array_unique(array_column($unkept, 'page'))) >= 5) {
                $reasons['many_pages'] = array('signal' => 'many_pages', 'weight' => 20, 'reason' => __('Many links from one site to many pages; check for sitewide placements.', 'seoprostats'));
            }
            $site['reasons'] = array_values($reasons);
            $site['score'] = min(100, array_sum(array_column($site['reasons'], 'weight')));
            $site['url_decisions'] = isset($urls_by_host[$site['host']]) ? $urls_by_host[$site['host']] : array();
        }
        unset($site);
        $sites = array_values($sites);
        usort($sites, static function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcmp($a['host'], $b['host']);
        });
        $offset = max(0, isset($req['offset']) ? (int) $req['offset'] : 0);
        $limit = max(1, min(100, isset($req['limit']) ? (int) $req['limit'] : 25));
        return array('rows' => array_slice($sites, $offset, $limit), 'total' => count($sites), 'more' => $offset + $limit < count($sites), 'max_rows' => SEOProStats_Backlinks::MAX_ROWS, 'signals_are_proof' => false);
    }

    /** Lookup without guessing a decision. @param array<string,array<string,mixed>> $rows Rows. @param string $scope Scope. @param string $target Target. @return array<string,mixed> */
    private static function decision(array $rows, $scope, $target) {
        $key = self::key($scope, $target);
        return isset($rows[$key]) ? $rows[$key] : array('scope' => $scope, 'target' => $target, 'decision' => 'undecided', 'imported' => false, 'user_id' => 0, 'reviewed' => 0);
    }

    /** Whole-domain keep, with dot-boundary ancestor matching. @param array<string,array<string,mixed>> $rows Decisions. @param string $host Host. @return bool */
    private static function kept_domain(array $rows, $host) {
        while (strpos($host, '.') !== false) {
            if (self::decision($rows, 'domain', $host)['decision'] === 'keep') {
                return true;
            }
            $host = substr($host, strpos($host, '.') + 1);
        }
        return false;
    }

    /** Each distinct observed signal adds its weight once. Missing facts add nothing. @param array<string,mixed> $link Link. @return array<int,array{signal:string,weight:int,reason:string}> */
    public static function signals(array $link) {
        $out = array();
        $add = static function ($signal, $weight, $reason) use (&$out) {
            $out[] = array('signal' => $signal, 'weight' => $weight, 'reason' => $reason);
        };
        $anchor = strtolower($link['anchor']);
        foreach (self::SPAM_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $anchor)) {
                $add('anchor_topic', 35, __('Anchor contains a commonly abused topic; verify relevance to this site.', 'seoprostats'));
                break;
            }
        }
        $target_words = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(basename($link['page']))));
        if ($target_words !== '' && $anchor === $target_words && preg_match('/\b(buy|cheap|best|price|loans|pills|casino)\b/', $anchor)) {
            $add('commercial_exact', 20, __('Commercial anchor exactly matches the target page slug.', 'seoprostats'));
        }
        $tld = substr((string) strrchr($link['host'], '.'), 1);
        if (in_array($tld, self::TLD_PATTERNS, true)) {
            $add('tld_pattern', 10, __('Referring domain has a commonly abused TLD; legitimate sites use it too.', 'seoprostats'));
        }
        $facts = isset($link['facts']) ? (array) $link['facts'] : array();
        if (isset($facts['outbound']) && $facts['outbound'] >= 100) {
            $add('outbound', 20, __('Checked page has at least 100 outgoing links; inspect the placement.', 'seoprostats'));
        }
        if (!empty($facts['link_list'])) {
            $add('link_list', 15, __('Checked page is mostly a list of outgoing links.', 'seoprostats'));
        }
        $language = strtolower((string) strtok(str_replace('_', '-', get_locale()), '-'));
        if (!empty($facts['language']) && $facts['language'] !== $language) {
            $add('language', 5, __('Checked page declares another language; this alone is not spam.', 'seoprostats'));
        }
        $scripts = array('Latin' => array('en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl', 'tr', 'vi'), 'Cyrillic' => array('ru', 'uk', 'bg'), 'Arabic' => array('ar', 'fa', 'ur'), 'Han' => array('zh', 'ja'), 'Greek' => array('el'), 'Hebrew' => array('he'), 'Hangul' => array('ko'), 'Thai' => array('th'), 'Devanagari' => array('hi'));
        foreach ($scripts as $script => $languages) {
            if (in_array($language, $languages, true) && !empty($facts['script']) && $facts['script'] !== $script) {
                $add('script', 5, __('Checked page predominantly uses a different script; verify audience relevance.', 'seoprostats'));
                break;
            }
        }
        if (!empty($facts['redirects'])) {
            $add('redirects', 10, __('Observed referring-page redirect chain; inspect its destination.', 'seoprostats'));
        }
        foreach ((array) $link['providers'] as $provider) {
            if (isset($provider['authority']) && is_numeric($provider['authority']) && $provider['authority'] >= 0 && $provider['authority'] <= 10) {
                $add('low_authority', 5, __('Provider reports authority of 10/100 or less. New legitimate sites can score low; this is not a spam score and providers differ.', 'seoprostats'));
                break;
            }
        }
        foreach ((array) $link['providers'] as $provider) {
            if (isset($provider['spam_score']) && is_numeric($provider['spam_score']) && $provider['spam_score'] >= 50 && $provider['spam_score'] <= 100) {
                $add('provider_spam', 25, __('Provider reports a spam score of at least 50/100; not a Google verdict.', 'seoprostats'));
                break;
            }
        }
        $first = !empty($link['first_seen']) ? strtotime($link['first_seen']) : false;
        $lost = !empty($link['lost']) ? strtotime($link['lost']) : false;
        if ($first !== false && $lost !== false && $lost >= $first && $lost - $first <= 7 * DAY_IN_SECONDS) {
            $add('short_lived', 10, __('Link was found and lost within seven days.', 'seoprostats'));
        }
        return $out;
    }

    /** Retain facts only from the page already fetched by the existing check. @param string $html HTML. @return array<string,mixed> */
    public static function page_facts($html) {
        $tags = new WP_HTML_Tag_Processor($html);
        $out = array('outbound' => 0, 'language' => '');
        $hosts = SEOProStats_Collection::hosts();
        while ($tags->next_tag()) {
            if ($tags->get_tag() === 'HTML') {
                $lang = $tags->get_attribute('lang');
                $out['language'] = is_string($lang) ? strtolower((string) strtok(str_replace('_', '-', $lang), '-')) : '';
            }
            if ($tags->get_tag() === 'A') {
                $href = $tags->get_attribute('href');
                $host = is_string($href) ? strtolower((string) wp_parse_url($href, PHP_URL_HOST)) : '';
                if ($host !== '' && !in_array($host, $hosts, true)) {
                    ++$out['outbound'];
                }
            }
        }
        $text = wp_strip_all_tags($html);
        $words = preg_match_all('/\p{L}+/u', $text);
        $out['link_list'] = $out['outbound'] >= 30 && $words !== false && $words < $out['outbound'] * 10;
        $counts = array();
        foreach (array('Latin', 'Cyrillic', 'Arabic', 'Han', 'Greek', 'Hebrew', 'Hangul', 'Thai', 'Devanagari') as $script) {
            $counts[$script] = (int) preg_match_all('/\p{' . $script . '}/u', $text);
        }
        arsort($counts);
        $script = (string) key($counts);
        $out['script'] = $counts[$script] >= 100 && $counts[$script] > array_sum($counts) * 0.6 ? $script : '';
        return $out;
    }

    /** Consistent validation/write error. @return WP_Error */
    private static function error() {
        return new WP_Error('seoprostats_disavow', __('Could not save or export the list. Use valid domain names or HTTP(S) URLs without credentials, UTF-8 text, URLs up to 2,048 characters, and at most 100,000 lines / 2 MB including the header.', 'seoprostats'), array('status' => 400));
    }
}
