<?php
/**
 * Referrer and campaign tags → traffic channel.
 *
 * UTM tags win over the referrer, as the site owner set them. Hosts are
 * matched by their registrable end (google.co.uk and www.google.com are
 * both Google), from the lists below.
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

final class SEOProStats_Channels {

    const DIRECT         = 0;
    const ORGANIC_SEARCH = 1;
    const PAID_SEARCH    = 2;
    const AI             = 3;
    const ORGANIC_SOCIAL = 4;
    const PAID_SOCIAL    = 5;
    const EMAIL          = 6;
    const REFERRAL       = 7;
    const PAID_OTHER     = 8;

    /**
     * Search engines: host pattern (on the host without www.) => name.
     */
    const SEARCH = array(
        '~(^|\.)google\.[a-z.]+$~'          => 'Google',
        '~(^|\.)bing\.com$~'                => 'Bing',
        '~(^|\.)duckduckgo\.com$~'          => 'DuckDuckGo',
        '~(^|\.)search\.yahoo\.[a-z.]+$~'   => 'Yahoo',
        '~(^|\.)yandex\.[a-z.]+$|^ya\.ru$~' => 'Yandex',
        '~(^|\.)baidu\.com$~'               => 'Baidu',
        '~(^|\.)ecosia\.org$~'              => 'Ecosia',
        '~^search\.brave\.com$~'            => 'Brave Search',
        '~(^|\.)startpage\.com$~'           => 'Startpage',
        '~(^|\.)qwant\.com$~'               => 'Qwant',
        '~(^|\.)naver\.com$~'               => 'Naver',
        '~(^|\.)seznam\.cz$~'               => 'Seznam',
        '~(^|\.)kagi\.com$~'                => 'Kagi',
        '~(^|\.)search\.aol\.com$~'         => 'AOL',
    );

    /**
     * AI assistants and answer engines.
     */
    const AI_HOSTS = array(
        '~^(chatgpt\.com|chat\.openai\.com)$~'  => 'ChatGPT',
        '~(^|\.)perplexity\.ai$~'               => 'Perplexity',
        '~^claude\.ai$~'                        => 'Claude',
        '~^gemini\.google\.com$~'               => 'Gemini',
        '~^(copilot\.microsoft\.com|copilot\.cloud\.microsoft)$~' => 'Copilot',
        '~(^|\.)you\.com$~'                     => 'You.com',
        '~(^|\.)phind\.com$~'                   => 'Phind',
        '~^(www\.)?meta\.ai$~'                  => 'Meta AI',
        '~^(grok\.com|x\.ai)$~'                 => 'Grok',
        '~(^|\.)deepseek\.com$~'                => 'DeepSeek',
        '~^poe\.com$~'                          => 'Poe',
        '~^chat\.mistral\.ai$~'                 => 'Mistral',
    );

    /**
     * Social networks and communities.
     */
    const SOCIAL = array(
        '~(^|\.)facebook\.com$|^fb\.me$~'           => 'Facebook',
        '~(^|\.)instagram\.com$~'                   => 'Instagram',
        '~^(t\.co|twitter\.com|x\.com)$~'           => 'X',
        '~(^|\.)linkedin\.com$|^lnkd\.in$~'         => 'LinkedIn',
        '~(^|\.)reddit\.com$~'                      => 'Reddit',
        '~(^|\.)youtube\.com$|^youtu\.be$~'         => 'YouTube',
        '~(^|\.)pinterest\.[a-z.]+$|^pin\.it$~'     => 'Pinterest',
        '~(^|\.)tiktok\.com$~'                      => 'TikTok',
        '~^bsky\.app$~'                             => 'Bluesky',
        '~(^|\.)threads\.(net|com)$~'               => 'Threads',
        '~^news\.ycombinator\.com$~'                => 'Hacker News',
        '~(^|\.)quora\.com$~'                       => 'Quora',
        '~(^|\.)vk\.com$~'                          => 'VK',
        '~(^|\.)whatsapp\.com$|^wa\.me$~'           => 'WhatsApp',
        '~^(t\.me|telegram\.org)$~'                 => 'Telegram',
        '~(^|\.)discord(app)?\.com$~'               => 'Discord',
        '~(^|\.)medium\.com$~'                      => 'Medium',
        '~(^|\.)mastodon\.[a-z]+$|^mstdn\.[a-z]+$~' => 'Mastodon',
        '~(^|\.)producthunt\.com$~'                 => 'Product Hunt',
    );

    /**
     * Webmail.
     */
    const MAIL = '~^(mail\.google\.com|outlook\.(live|office)\.com|mail\.yahoo\.com|mail\.proton\.me|mail\.aol\.com|webmail\.)~';

    /**
     * Ad click IDs: query parameter => the channel it proves. fbclid is
     * not here: Facebook adds it to every outbound link, paid or not.
     */
    const CLICK_IDS = array(
        'gclid'     => self::PAID_SEARCH,
        'gbraid'    => self::PAID_SEARCH,
        'wbraid'    => self::PAID_SEARCH,
        'msclkid'   => self::PAID_SEARCH,
        'yclid'     => self::PAID_SEARCH,
        'dclid'     => self::PAID_OTHER,
        'ttclid'    => self::PAID_SOCIAL,
        'twclid'    => self::PAID_SOCIAL,
        'li_fat_id' => self::PAID_SOCIAL,
    );

    /**
     * The channel of a visit.
     *
     * @param string               $ref_host Referrer host without www. ('' for none or the site itself).
     * @param array<string,string> $utm      utm_source, utm_medium… (lower-case values).
     * @param string               $click_id Ad click ID parameter on the landing URL (gclid, msclkid…), or ''.
     * @return int One of the channel constants.
     */
    public static function classify($ref_host, array $utm, $click_id = '') {
        $medium = isset($utm['utm_medium']) ? $utm['utm_medium'] : '';
        $source = isset($utm['utm_source']) ? $utm['utm_source'] : '';
        $kind   = self::kind($ref_host !== '' ? $ref_host : $source);

        // A medium the owner set wins; without one, an ad click ID decides.
        if ($medium === '' && isset(self::CLICK_IDS[$click_id])) {
            return self::CLICK_IDS[$click_id];
        }
        if (preg_match('~^(cpc|ppc|paid|paidsearch|paid[_-]?social|cpm|cpv|display|banner|retargeting)~', $medium)) {
            if ($kind === self::ORGANIC_SEARCH) {
                return self::PAID_SEARCH;
            }
            if ($kind === self::ORGANIC_SOCIAL || strpos($medium, 'social') !== false) {
                return self::PAID_SOCIAL;
            }
            return self::PAID_OTHER;
        }
        if ($medium === 'email' || $medium === 'e-mail' || $medium === 'newsletter' || ($ref_host !== '' && preg_match(self::MAIL, $ref_host))) {
            return self::EMAIL;
        }
        if ($medium === 'social' || $medium === 'social-network' || $medium === 'social-media') {
            return self::ORGANIC_SOCIAL;
        }
        if ($medium === 'organic') {
            return self::ORGANIC_SEARCH;
        }
        if ($kind !== self::DIRECT) {
            return $kind;
        }
        return ($ref_host !== '' || $source !== '') ? self::REFERRAL : self::DIRECT;
    }

    /**
     * Search, AI or social for a host (or a utm_source such as "google"),
     * else DIRECT.
     *
     * @param string $host Host without www., or a source name.
     * @return int
     */
    public static function kind($host) {
        if ($host === '') {
            return self::DIRECT;
        }
        $lists = array(
            self::AI             => self::AI_HOSTS,
            self::ORGANIC_SEARCH => self::SEARCH,
            self::ORGANIC_SOCIAL => self::SOCIAL,
        );
        foreach ($lists as $kind => $list) {
            if (self::name_in($host, $list) !== '') {
                return $kind;
            }
        }
        // utm_source names such as "google", "facebook", "chatgpt.com".
        if (strpos($host, '.') === false) {
            $known = array('google' => self::ORGANIC_SEARCH, 'bing' => self::ORGANIC_SEARCH, 'duckduckgo' => self::ORGANIC_SEARCH, 'facebook' => self::ORGANIC_SOCIAL, 'fb' => self::ORGANIC_SOCIAL, 'instagram' => self::ORGANIC_SOCIAL, 'ig' => self::ORGANIC_SOCIAL, 'twitter' => self::ORGANIC_SOCIAL, 'x' => self::ORGANIC_SOCIAL, 'linkedin' => self::ORGANIC_SOCIAL, 'reddit' => self::ORGANIC_SOCIAL, 'youtube' => self::ORGANIC_SOCIAL, 'tiktok' => self::ORGANIC_SOCIAL, 'pinterest' => self::ORGANIC_SOCIAL, 'chatgpt' => self::AI, 'openai' => self::AI, 'perplexity' => self::AI, 'claude' => self::AI, 'gemini' => self::AI, 'copilot' => self::AI);
            if (isset($known[$host])) {
                return $known[$host];
            }
        }
        return self::DIRECT;
    }

    /**
     * Friendly source name for a host ("Google" for www.google.co.uk), or
     * the host itself.
     *
     * @param string $host Host without www.
     * @return string
     */
    public static function source_name($host) {
        foreach (array(self::AI_HOSTS, self::SEARCH, self::SOCIAL) as $list) {
            $name = self::name_in($host, $list);
            if ($name !== '') {
                return $name;
            }
        }
        return $host;
    }

    /**
     * Name from one of the lists for a host, or ''.
     *
     * @param string               $host Host.
     * @param array<string,string> $list Pattern => name.
     * @return string
     */
    private static function name_in($host, array $list) {
        foreach ($list as $pattern => $name) {
            if (preg_match($pattern, $host)) {
                return $name;
            }
        }
        return '';
    }
}
