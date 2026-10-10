<?php
/**
 * User agent → browser, OS, device type, and whether it is a bot.
 *
 * A small parser of our own: the browsers and systems that make nearly
 * all visits, in the order that avoids false matches (Edge and Opera say
 * "Chrome", Chrome says "Safari"). Unknown agents are "Other". Results are
 * cached per agent for the request, as one batch repeats few agents.
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

final class SEOProStats_UA {

    const DEVICE_DESKTOP = 1;
    const DEVICE_MOBILE  = 2;
    const DEVICE_TABLET  = 3;

    /**
     * Crawlers, tools and automation. Matched case-insensitively against
     * the whole agent; visitors' browsers contain none of these.
     */
    const BOT_PATTERN = '~bot\b|bot/|crawl|spider|slurp|scraper|archiver|facebookexternalhit|meta-externalagent|headless|phantomjs|lighthouse|pagespeed|pingdom|uptime|monitor|statuscake|site24x7|curl/|wget/|python|go-http|httpclient|okhttp|axios|node-fetch|undici|java/|libwww|scrapy|feedfetcher|mediapartners|adsbot|google-inspectiontool|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|gptbot|chatgpt-user|oai-searchbot|claude|anthropic|perplexity|ccbot|amazonbot|applebot|yandex(?!browser)|baiduspider|duckduckbot|bingpreview|ia_archiver|preview|validator|wordpress/|wp-rocket|litespeed|cache-?warm|prerender~i';

    /**
     * Browsers: name => pattern capturing the major version. Order matters.
     */
    const BROWSERS = array(
        'Edge'             => '~Edg(?:e|A|iOS)?/(\d+)~',
        'Opera'            => '~(?:OPR|Opera|OPiOS)/(\d+)~',
        'Samsung Internet' => '~SamsungBrowser/(\d+)~',
        'Yandex Browser'   => '~YaBrowser/(\d+)~',
        'Vivaldi'          => '~Vivaldi/(\d+)~',
        'UC Browser'       => '~UCBrowser/(\d+)~',
        'DuckDuckGo'       => '~(?:DuckDuckGo|Ddg)/(\d+)~',
        'Firefox'          => '~(?:Firefox|FxiOS)/(\d+)~',
        'Chrome'           => '~(?:Chrome|CriOS)/(\d+)~',
        'Safari'           => '~Version/(\d+)[\d.]* (?:Mobile/\w+ )?Safari/~',
        'Internet Explorer' => '~(?:MSIE |Trident/.*rv:)(\d+)~',
    );

    /** @var array<string,array{bot:bool,browser:string,browser_ver:int,os:string,os_ver:int,device:int}> */
    private static $cache = array();

    /**
     * Parse an agent.
     *
     * @param string $ua User agent.
     * @return array{bot:bool,browser:string,browser_ver:int,os:string,os_ver:int,device:int}
     */
    public static function parse($ua) {
        $ua = (string) $ua;
        if (isset(self::$cache[$ua])) {
            return self::$cache[$ua];
        }
        if (count(self::$cache) > 2000) {
            self::$cache = array();
        }

        $result = array(
            'bot'         => $ua === '' || preg_match(self::BOT_PATTERN, $ua) === 1,
            'browser'     => 'Other',
            'browser_ver' => 0,
            'os'          => 'Other',
            'os_ver'      => 0,
            'device'      => self::DEVICE_DESKTOP,
        );

        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                $result['browser']     = $name;
                $result['browser_ver'] = (int) $m[1];
                break;
            }
        }

        list($result['os'], $result['os_ver']) = self::system($ua);
        $result['device'] = self::device($ua, $result['os']);

        self::$cache[$ua] = $result;
        return $result;
    }

    /**
     * Operating system and its major version.
     *
     * @param string $ua User agent.
     * @return array{0:string,1:int}
     */
    private static function system($ua) {
        if (preg_match('~(?:iPhone|iPad|iPod).*? OS (\d+)_~', $ua, $m)) {
            return array('iOS', (int) $m[1]);
        }
        if (preg_match('~Android (\d+)~', $ua, $m)) {
            return array('Android', (int) $m[1]);
        }
        if (strpos($ua, 'Android') !== false) {
            return array('Android', 0);
        }
        if (preg_match('~Windows NT (\d+)~', $ua, $m)) {
            // Windows 11 still says "Windows NT 10.0".
            return array('Windows', (int) $m[1] === 10 ? 10 : (int) $m[1]);
        }
        if (strpos($ua, 'CrOS') !== false) {
            return array('ChromeOS', 0);
        }
        if (preg_match('~Mac OS X (\d+)~', $ua, $m)) {
            return array('macOS', (int) $m[1]);
        }
        if (strpos($ua, 'Linux') !== false || strpos($ua, 'X11') !== false) {
            return array('Linux', 0);
        }
        return array('Other', 0);
    }

    /**
     * Device type.
     *
     * @param string $ua User agent.
     * @param string $os OS from os().
     * @return int One of the DEVICE_ constants.
     */
    private static function device($ua, $os) {
        if (preg_match('~iPad|Tablet|Kindle|Silk/|PlayBook~i', $ua)) {
            return self::DEVICE_TABLET;
        }
        if ($os === 'Android') {
            // Android phones say "Mobile"; tablets do not.
            return strpos($ua, 'Mobile') !== false ? self::DEVICE_MOBILE : self::DEVICE_TABLET;
        }
        if ($os === 'iOS' || preg_match('~Mobi|Opera Mini|IEMobile~', $ua)) {
            return self::DEVICE_MOBILE;
        }
        return self::DEVICE_DESKTOP;
    }
}
