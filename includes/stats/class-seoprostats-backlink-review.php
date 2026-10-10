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
            $failed = self::merge_chunk($chunk);
            if ($failed) {
                $wpdb->query('ROLLBACK');
                return $failed;
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return self::error();
        }
        // phpcs:enable
        return array('entries' => count($entries));
    }

    /** Insert one chunk of a prior list inside merge()'s transaction, and check the locked result. @param array<string,array{scope:string,target:string}> $chunk Entries by key. @return WP_Error|null The error to roll back for, or null. */
    private static function merge_chunk(array $chunk) {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- our own table, unique key writes within merge()'s transaction.
        $args = array(SEOProStats_Schema::table('link_reviews'));
        foreach ($chunk as $key => $entry) {
            array_push($args, $key, $entry['scope'], $entry['target'], get_current_user_id(), time());
        }
        $holders = implode(', ', array_fill(0, count($chunk), "(UNHEX(%s), %s, %s, 'disavow', 1, %d, %d)"));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- holders contains only fixed placeholder groups, values are separately prepared.
        $ok = $wpdb->query($wpdb->prepare("INSERT INTO %i (rkey, scope, target, decision, imported, user_id, reviewed) VALUES $holders ON DUPLICATE KEY UPDATE rkey = VALUES(rkey)", $args));
        if ($ok === false) {
            return self::error();
        }
        // Insert/no-op has locked every key, including rows that raced an
        // absent-key read under READ COMMITTED. Validate the locked result
        // before commit, never silently omit a supplied entry.
        $keys = implode(', ', array_fill(0, count($chunk), 'UNHEX(%s)'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- keys contains only placeholder groups; unique-key reads retain locks until commit.
        $existing = $wpdb->get_results($wpdb->prepare("SELECT decision FROM %i WHERE rkey IN ($keys) FOR UPDATE", array_merge(array(SEOProStats_Schema::table('link_reviews')), array_keys($chunk))), ARRAY_A);
        // phpcs:enable
        if ($existing === null || $wpdb->last_error !== '' || count($existing) !== count($chunk)) {
            return self::error();
        }
        foreach ($existing as $row) {
            if ($row['decision'] !== 'disavow') {
                return new WP_Error('seoprostats_disavow_conflict', __('A prior-list entry already has a keep or undecided decision. Change that decision explicitly, or remove the entry from the supplied list before merging. No entries were saved.', 'seoprostats'), array('status' => 400));
            }
        }
        return null;
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
        $links = self::sampled_links($req);
        if (is_wp_error($links)) {
            return $links;
        }
        $decisions = self::all_decisions();
        if (is_wp_error($decisions)) {
            return $decisions;
        }
        $sites = array();
        foreach ($links as $link) {
            $host = $link['host'];
            if (!isset($sites[$host])) {
                $sites[$host] = self::site($decisions, $host);
            }
            $sites[$host]['links'][] = self::review_link($link, $decisions);
        }
        $urls_by_host = self::decided_sites($sites, $decisions);
        foreach ($sites as &$site) {
            $site['reasons']       = self::site_reasons($site, $decisions);
            $site['score']         = min(100, array_sum(array_column($site['reasons'], 'weight')));
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

    /** Live and lost links of the backlinks report, page by page, up to its bound. @param array<string,mixed> $req Request. @return array<int,array<string,mixed>>|WP_Error */
    private static function sampled_links(array $req) {
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
        return $links;
    }

    /** Every decision by review key, typed. @return array<string,array<string,mixed>>|WP_Error */
    private static function all_decisions() {
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
        return $decisions;
    }

    /** Decisions from a prior list remain visible even with no sampled links: add their sites. @param array<string,array<string,mixed>> $sites Sites by host; added to. @param array<string,array<string,mixed>> $decisions Decisions. @return array<string,array<int,array<string,mixed>>> URL decisions by host. */
    private static function decided_sites(array &$sites, array $decisions) {
        $urls_by_host = array();
        foreach ($decisions as $row) {
            $host = $row['scope'] === 'domain' ? $row['target'] : strtolower((string) wp_parse_url($row['target'], PHP_URL_HOST));
            if ($row['scope'] === 'url') {
                $urls_by_host[$host][] = $row;
            }
            if (!isset($sites[$host])) {
                $sites[$host] = self::site($decisions, $host);
            }
        }
        return $urls_by_host;
    }

    /** A referring site before its links are added. @param array<string,array<string,mixed>> $decisions Decisions. @param string $host Host. @return array<string,mixed> */
    private static function site(array $decisions, $host) {
        return array('host' => $host, 'score' => 0, 'reasons' => array(), 'links' => array(), 'decision' => self::decision($decisions, 'domain', $host));
    }

    /** A link with its decision, signals and score; none for a kept link or domain. @param array<string,mixed> $link Link. @param array<string,array<string,mixed>> $decisions Decisions. @return array<string,mixed> */
    private static function review_link(array $link, array $decisions) {
        $link['decision'] = self::decision($decisions, 'url', $link['source']);
        $link['reasons'] = self::signals($link);
        $link['score'] = min(100, array_sum(array_column($link['reasons'], 'weight')));
        if ($link['decision']['decision'] === 'keep' || self::kept_domain($decisions, $link['host'])) {
            $link['reasons'] = array();
            $link['score'] = 0;
        }
        return $link;
    }

    /** A site's distinct link signals, and many_pages for many links to many pages. @param array<string,mixed> $site Site with its links. @param array<string,array<string,mixed>> $decisions Decisions. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function site_reasons(array $site, array $decisions) {
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
        return array_values($reasons);
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
        $facts    = isset($link['facts']) ? (array) $link['facts'] : array();
        $language = strtolower((string) strtok(str_replace('_', '-', get_locale()), '-'));
        return array_merge(
            self::anchor_signals(strtolower($link['anchor']), $link['page']),
            self::host_signals($link['host']),
            self::page_signals($facts),
            self::language_signals($facts, $language),
            self::redirect_signals($facts),
            self::provider_signals((array) $link['providers']),
            self::age_signals($link)
        );
    }

    /** One signal. @param string $signal Name. @param int $weight Weight. @param string $reason Why. @return array{signal:string,weight:int,reason:string} */
    private static function signal($signal, $weight, $reason) {
        return array('signal' => $signal, 'weight' => $weight, 'reason' => $reason);
    }

    /** Signals of the link text. @param string $anchor Link text, lower case. @param string $page Linked page. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function anchor_signals($anchor, $page) {
        $out = array();
        foreach (self::SPAM_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $anchor)) {
                $out[] = self::signal('anchor_topic', 35, __('Anchor contains a commonly abused topic; verify relevance to this site.', 'seoprostats'));
                break;
            }
        }
        $target_words = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(basename($page))));
        if ($target_words !== '' && $anchor === $target_words && preg_match('/\b(buy|cheap|best|price|loans|pills|casino)\b/', $anchor)) {
            $out[] = self::signal('commercial_exact', 20, __('Commercial anchor exactly matches the target page slug.', 'seoprostats'));
        }
        return $out;
    }

    /** Signals of the referring host. @param string $host Host. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function host_signals($host) {
        $tld = substr((string) strrchr($host, '.'), 1);
        return in_array($tld, self::TLD_PATTERNS, true) ? array(self::signal('tld_pattern', 10, __('Referring domain has a commonly abused TLD; legitimate sites use it too.', 'seoprostats'))) : array();
    }

    /** Signals of the checked page's links and redirects. @param array<string,mixed> $facts Page facts. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function page_signals(array $facts) {
        $out = array();
        if (isset($facts['outbound']) && $facts['outbound'] >= 100) {
            $out[] = self::signal('outbound', 20, __('Checked page has at least 100 outgoing links; inspect the placement.', 'seoprostats'));
        }
        if (!empty($facts['link_list'])) {
            $out[] = self::signal('link_list', 15, __('Checked page is mostly a list of outgoing links.', 'seoprostats'));
        }
        return $out;
    }

    /** Signals of the checked page's language and script, against the site's. @param array<string,mixed> $facts Page facts. @param string $language The site's language code. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function language_signals(array $facts, $language) {
        $out = array();
        if (!empty($facts['language']) && $facts['language'] !== $language) {
            $out[] = self::signal('language', 5, __('Checked page declares another language; this alone is not spam.', 'seoprostats'));
        }
        $scripts = array('Latin' => array('en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl', 'tr', 'vi'), 'Cyrillic' => array('ru', 'uk', 'bg'), 'Arabic' => array('ar', 'fa', 'ur'), 'Han' => array('zh', 'ja'), 'Greek' => array('el'), 'Hebrew' => array('he'), 'Hangul' => array('ko'), 'Thai' => array('th'), 'Devanagari' => array('hi'));
        foreach ($scripts as $script => $languages) {
            if (in_array($language, $languages, true) && !empty($facts['script']) && $facts['script'] !== $script) {
                $out[] = self::signal('script', 5, __('Checked page predominantly uses a different script; verify audience relevance.', 'seoprostats'));
                break;
            }
        }
        return $out;
    }

    /** A redirect chain before the checked page. @param array<string,mixed> $facts Page facts. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function redirect_signals(array $facts) {
        return !empty($facts['redirects']) ? array(self::signal('redirects', 10, __('Observed referring-page redirect chain; inspect its destination.', 'seoprostats'))) : array();
    }

    /** Signals of the backlink providers' figures. @param array<int|string,mixed> $providers Providers. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function provider_signals(array $providers) {
        $out = array();
        if (self::any_figure($providers, 'authority', 0, 10)) {
            $out[] = self::signal('low_authority', 5, __('Provider reports authority of 10/100 or less. New legitimate sites can score low; this is not a spam score and providers differ.', 'seoprostats'));
        }
        if (self::any_figure($providers, 'spam_score', 50, 100)) {
            $out[] = self::signal('provider_spam', 25, __('Provider reports a spam score of at least 50/100; not a Google verdict.', 'seoprostats'));
        }
        return $out;
    }

    /** Whether any provider reports a figure within a range. @param array<int|string,mixed> $providers Providers. @param string $figure Figure. @param int $min Lowest. @param int $max Highest. @return bool */
    private static function any_figure(array $providers, $figure, $min, $max) {
        foreach ($providers as $provider) {
            if (isset($provider[$figure]) && is_numeric($provider[$figure]) && $provider[$figure] >= $min && $provider[$figure] <= $max) {
                return true;
            }
        }
        return false;
    }

    /** A link found and lost within seven days. @param array<string,mixed> $link Link. @return array<int,array{signal:string,weight:int,reason:string}> */
    private static function age_signals(array $link) {
        $first = !empty($link['first_seen']) ? strtotime($link['first_seen']) : false;
        $lost = !empty($link['lost']) ? strtotime($link['lost']) : false;
        if ($first !== false && $lost !== false && $lost >= $first && $lost - $first <= 7 * DAY_IN_SECONDS) {
            return array(self::signal('short_lived', 10, __('Link was found and lost within seven days.', 'seoprostats')));
        }
        return array();
    }

    /** Retain facts only from the page already fetched by the existing check. @param string $html HTML. @return array<string,mixed> */
    public static function page_facts($html) {
        $out = self::tag_facts($html);
        $text = wp_strip_all_tags($html);
        $words = preg_match_all('/\p{L}+/u', $text);
        $out['link_list'] = $out['outbound'] >= 30 && $words !== false && $words < $out['outbound'] * 10;
        $out['script'] = self::main_script($text);
        return $out;
    }

    /** Outgoing links and the declared language, from the page's tags. @param string $html HTML. @return array{outbound:int,language:string} */
    private static function tag_facts($html) {
        $tags = new WP_HTML_Tag_Processor($html);
        $out = array('outbound' => 0, 'language' => '');
        $hosts = SEOProStats_Collection::hosts();
        while ($tags->next_tag()) {
            if ($tags->get_tag() === 'HTML') {
                $lang = $tags->get_attribute('lang');
                $out['language'] = is_string($lang) ? strtolower((string) strtok(str_replace('_', '-', $lang), '-')) : '';
            }
            if ($tags->get_tag() === 'A' && self::outbound($tags->get_attribute('href'), $hosts)) {
                ++$out['outbound'];
            }
        }
        return $out;
    }

    /** A link to another site. @param string|bool|null $href The href attribute. @param string[] $hosts The site's hosts. @return bool */
    private static function outbound($href, array $hosts) {
        $host = is_string($href) ? strtolower((string) wp_parse_url($href, PHP_URL_HOST)) : '';
        return $host !== '' && !in_array($host, $hosts, true);
    }

    /** The script of most of the text: at least 100 letters and over 60% of the counted ones, else ''. @param string $text Text. @return string */
    private static function main_script($text) {
        $counts = array();
        foreach (array('Latin', 'Cyrillic', 'Arabic', 'Han', 'Greek', 'Hebrew', 'Hangul', 'Thai', 'Devanagari') as $script) {
            $counts[$script] = (int) preg_match_all('/\p{' . $script . '}/u', $text);
        }
        arsort($counts);
        $script = (string) key($counts);
        return $counts[$script] >= 100 && $counts[$script] > array_sum($counts) * 0.6 ? $script : '';
    }

    /** Consistent validation/write error. @return WP_Error */
    private static function error() {
        return new WP_Error('seoprostats_disavow', __('Could not save or export the list. Use valid domain names or HTTP(S) URLs without credentials, UTF-8 text, URLs up to 2,048 characters, and at most 100,000 lines / 2 MB including the header.', 'seoprostats'), array('status' => 400));
    }
}
