<?php
/**
 * The statistics' settings: what is collected (Tracking), who and what is
 * left out (Privacy), and how long visits are kept and who reads them
 * (Data). The engine in includes/stats/ reads them through the helpers
 * below; its filters (README.md → Developers) still apply on top.
 *
 * Saving rewrites the collector's config file, which collect.php reads
 * without WordPress. Visitor pages read the settings from the autoloaded
 * option, with no query. Design: docs/architecture.md.
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

final class SEOProStats_Statistics extends SEOProStats_Feature {

    /** Collect statistics: the tracker is printed and hits are stored. */
    const KEY = 'tracking';

    /** Headers that may hold the visitor's address behind a proxy ($_SERVER keys). */
    const IP_HEADERS = array(
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
        'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP (Akamai, Cloudflare Enterprise)',
        'HTTP_X_REAL_IP'        => 'X-Real-IP (nginx)',
        'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
    );

    /** Headers that may hold the visitor's country ($_SERVER keys). */
    const COUNTRY_HEADERS = array(
        'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'CloudFront-Viewer-Country (Amazon CloudFront)',
        'HTTP_X_COUNTRY_CODE'            => 'X-Country-Code',
        'GEOIP_COUNTRY_CODE'             => 'GEOIP_COUNTRY_CODE (the web server\'s GeoIP module)',
    );

    /**
     * Settings schema entries.
     *
     * @return array<string,array>
     */
    public static function settings() {
        return array(
            // Shared reports: defaults for new reports (SEOProStats_Shares::defaults()).
            // Only switches and panels have controls on a card, so the fields
            // sit in the panels of the Agency branding and Report colours cards.
            'share_brand'             => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'shared-reports',
                'label'       => __('Agency branding', 'seoprostats'),
                'description' => __('Put your agency\'s logo, name, website and a byline at the foot of new shared reports. Off: reports carry only the site\'s own name and logo.', 'seoprostats'),
            ),
            'share_agency'            => array('type' => 'text', 'default' => '', 'parent' => 'share_brand', 'label' => __('Agency name', 'seoprostats')),
            'share_website'           => array('type' => 'url', 'default' => '', 'parent' => 'share_brand', 'label' => __('Agency website', 'seoprostats'), 'placeholder' => 'https://'),
            'share_byline'            => array('type' => 'text', 'default' => '', 'parent' => 'share_brand', 'label' => __('Byline', 'seoprostats'), 'description' => __('Words before the agency\'s name, such as “Prepared by”.', 'seoprostats')),
            'share_agency_logo'       => array('type' => 'media', 'default' => 0, 'parent' => 'share_brand', 'label' => __('Agency logo', 'seoprostats'), 'description' => __('A PNG, JPEG, WebP or GIF picture from this site\'s Media Library.', 'seoprostats')),
            'share_accent'            => array(
                'type'        => 'text',
                'default'     => '#2271b1',
                'tab'         => 'shared-reports',
                // The colour picker is drawn in the panel (SEOProStats_Share_Settings).
                'panel'       => true,
                'label'       => __('Report colours', 'seoprostats'),
                'description' => __('The colour of the report\'s chart, links and buttons, and whether it opens light or dark.', 'seoprostats'),
            ),
            'share_mode'              => array(
                'type'        => 'select',
                'default'     => 'system',
                'parent'      => 'share_accent',
                'label'       => __('Light or dark', 'seoprostats'),
                'description' => __('How the report opens. Readers can switch between light and dark on the report.', 'seoprostats'),
                'options'     => array(
                    'system' => __('Follow the reader\'s device', 'seoprostats'),
                    'light'  => __('Light', 'seoprostats'),
                    'dark'   => __('Dark', 'seoprostats'),
                ),
            ),
            'share_credit'            => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'shared-reports',
                'label'       => __('Show “Statistics logged by SEO Pro Stats for WordPress”', 'seoprostats'),
                'description' => __('A short line at the foot of new shared reports.', 'seoprostats'),
            ),
            self::KEY                 => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'tracking',
                'label'       => __('Collect statistics', 'seoprostats'),
                'description' => __('Count visits, pages and events with a small script printed in the site\'s pages. It sets no cookies and stores no IP addresses. Off: no script is printed and new hits are not stored; the statistics collected so far stay.', 'seoprostats'),
            ),
            'tracking_skip_roles'     => array(
                'type'        => 'multi',
                'default'     => self::editor_roles(),
                'parent'      => self::KEY,
                'options'     => array('SEOProStats_Feature', 'role_options'),
                'label'       => __('Not counted when logged in', 'seoprostats'),
                'description' => __('People with these roles see the site without being counted. Visitors who are not logged in are always counted.', 'seoprostats'),
            ),
            'tracking_params'         => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'rows'        => 3,
                'label'       => __('Query parameters to keep', 'seoprostats'),
                'description' => __('Page addresses keep UTM tags and WordPress\'s own page parameters, and drop the rest. Add others that pick out a page, one per line (for example lang). Letters, digits, dots, dashes and underscores only.', 'seoprostats'),
                'placeholder' => 'lang',
            ),
            'tracking_hosts'          => array(
                'type'        => 'domains',
                'default'     => '',
                'parent'      => self::KEY,
                'rows'        => 3,
                'label'       => __('Other domains of this site', 'seoprostats'),
                'description' => __('Domains that show this same site, one per line. Their pages are counted, and links to them are not outbound. With and without www. are both included.', 'seoprostats'),
                'placeholder' => 'example.org',
            ),
            'tracking_clicks'         => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Count clicks and form submits', 'seoprostats'),
                'description' => __('What people click (links, buttons, images), clicks that do nothing, and forms sent. Never what is typed or chosen in a form; emails and long numbers in labels are hidden. Add data-sps-mask to an element to leave out its text.', 'seoprostats'),
            ),
            'tracking_search'         => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Record site search words', 'seoprostats'),
                'description' => __('What people search for on the site, to show what they look for and what it does not have. Emails and long numbers are hidden. Off: searches and searches with no results are still counted, without their words.', 'seoprostats'),
            ),
            'tracking_ab_visit'       => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Show each visit one variant of an A/B test', 'seoprostats'),
                'description' => __('Off: each page load picks a variant again and nothing is stored in the browser. On: the variant a visitor saw first is kept in the browser tab\'s session storage (spst-ab- and the test) until the tab is closed, so pages seen again show the same one. Privacy law treats browser storage like cookies, so this may need the visitor\'s consent.', 'seoprostats'),
            ),
            'tracking_affiliate'      => array(
                'type'        => 'lines',
                'default'     => "/go/*\n/recommends/*",
                'parent'      => self::KEY,
                'rows'        => 3,
                'label'       => __('Affiliate link paths', 'seoprostats'),
                'description' => __('Paths of this site that forward to affiliate offers, one per line; * matches any characters. Links to them, and links marked rel="sponsored", send an Affiliate link event, which a goal can count.', 'seoprostats'),
                'placeholder' => '/go/*',
            ),
            'purchases'               => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Record purchases', 'seoprostats'),
                'description' => __('Paid orders from WooCommerce, Easy Digital Downloads, FluentCart and ThriveCart send a Purchase event with the order\'s total in its currency, on the visit that placed it, once per order, which goals and funnels can count. Only orders placed on the site by a counted visitor; no order numbers or customer details are kept.', 'seoprostats'),
            ),
            'purchases_thrivecart'    => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('ThriveCart secret word', 'seoprostats'),
                'description' => sprintf(
                    /* translators: %s: webhook address. */
                    __('To record ThriveCart orders: paste the secret word from ThriveCart\'s Settings → API & Webhooks here, and add a webhook there with this address: %s. Links to ThriveCart checkouts then carry the page\'s random ID, so each order joins its visit.', 'seoprostats'),
                    SEOProStats_Purchases::thrivecart_url()
                ),
                'placeholder' => '',
            ),
            'tracking_file'           => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Load the script as a file', 'seoprostats'),
                'description' => __('For a Content Security Policy that blocks inline scripts. Costs one more request per page view.', 'seoprostats'),
            ),
            'tracking_ip_header'      => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'options'     => array(__CLASS__, 'ip_header_options'),
                'label'       => __('Visitor address from', 'seoprostats'),
                'description' => __('Behind a proxy or CDN every visit comes from its address. Choose the header it puts the visitor\'s address in, and only one it always sets: visitors can send any header themselves. The address is used for the daily visitor count and excluded addresses, then dropped.', 'seoprostats'),
            ),
            'tracking_country_header' => array(
                'type'        => 'select',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'options'     => array(__CLASS__, 'country_header_options'),
                'label'       => __('Visitor country from', 'seoprostats'),
                'description' => __('Cloudflare\'s country header is read when present. Choose another header that a CDN or the web server sets; without one, the country comes from the browser\'s time zone.', 'seoprostats'),
            ),
            'privacy_signals'         => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'privacy',
                'label'       => __('Respect Do Not Track and Global Privacy Control', 'seoprostats'),
                'description' => __('Leave out browsers that send either signal. The statistics already use no cookies and keep no IP addresses or cross-day identity, so most sites do not need this; it lowers the counts. Checked in the browser, so cached pages work.', 'seoprostats'),
            ),
            'exclusions'              => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'privacy',
                'label'       => __('Leave out addresses and pages', 'seoprostats'),
                'description' => __('Do not count visits from some IP addresses (an office, a monitoring service) or views of some pages.', 'seoprostats'),
            ),
            'exclude_ips'             => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'exclusions',
                'rows'        => 4,
                'label'       => __('IP addresses', 'seoprostats'),
                'description' => __('One address or range per line, IPv4 or IPv6, such as 203.0.113.7 or 203.0.113.0/24. Lines that are not an address are ignored.', 'seoprostats'),
                'placeholder' => '203.0.113.0/24',
            ),
            'exclude_paths'           => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'exclusions',
                'rows'        => 4,
                'label'       => __('Pages', 'seoprostats'),
                'description' => __('One path per line; * matches any characters, so /checkout/* leaves out every page under /checkout/.', 'seoprostats'),
                'placeholder' => '/checkout/*',
            ),
            'retention'               => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'data',
                'label'       => __('Delete old visits', 'seoprostats'),
                'description' => __('Keep the database small: once a day, visits, events and clicks older than the months below are deleted. Daily totals are kept, so charts and totals still reach back; filters and visit details reach back as far as visits are kept. Off: nothing is deleted.', 'seoprostats'),
            ),
            'retention_visits'        => array(
                'type'        => 'int',
                'default'     => 75,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoprostats'),
                'parent'      => 'retention',
                'label'       => __('Visits and page views', 'seoprostats'),
                'description' => __('75 months allows quarter-by-quarter comparisons, with filters, going back six years. 13 months is enough for a comparison with the same month last year.', 'seoprostats'),
            ),
            'retention_events'        => array(
                'type'        => 'int',
                'default'     => 120,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoprostats'),
                'parent'      => 'retention',
                'label'       => __('Events and revenue', 'seoprostats'),
                'description' => __('Fewer rows and more value, so kept longer: 120 months is ten years.', 'seoprostats'),
            ),
            'retention_clicks'        => array(
                'type'        => 'int',
                'default'     => 3,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoprostats'),
                'parent'      => 'retention',
                'label'       => __('Clicks and form submits', 'seoprostats'),
                'description' => __('Many rows, most useful while a page is new or changing. Never kept longer than their visits.', 'seoprostats'),
            ),
            'retention_search'        => array(
                'type'        => 'int',
                'default'     => 25,
                'min'         => 1,
                'max'         => 120,
                'unit'        => __('months', 'seoprostats'),
                'parent'      => 'retention',
                'label'       => __('Search data by page and query', 'seoprostats'),
                'description' => __('Clicks, impressions and positions imported from a connected search engine (Settings → Connections). Search Console itself keeps 16 months; 25 allows a comparison with two years before. Daily search totals are kept.', 'seoprostats'),
            ),
            'search_updates'          => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'data',
                'label'       => __('Show search engine updates', 'seoprostats'),
                'description' => __('Mark Google\'s ranking updates (core, spam, reviews and others) and its crawling, indexing and serving incidents on the charts and in Changes, with how long each rolled out, so a change in traffic can be weighed against them. Once a day the site asks Google\'s Search Status Dashboard (status.search.google.com) for them: one request, sending nothing about the site or its visitors. The last two years it lists are added the first time.', 'seoprostats'),
            ),
            'search_updates_feeds'    => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => 'search_updates',
                'rows'        => 3,
                'label'       => __('Other feeds', 'seoprostats'),
                'description' => __('RSS, Atom or JSON Feed addresses of other sources you trust, such as a search engine\'s blog, one per line; after a space, the name to show (without one, the domain). Their posts whose title names an update are added once a day, one request to each. For Bing, add its Search blog: https://blogs.bing.com/search/feed/ Bing (Bing announces few updates there; its product news is left out).', 'seoprostats'),
                'placeholder' => 'https://blogs.bing.com/search/feed/ Bing',
            ),
            'backlinks'               => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'data',
                'label'       => __('Check pages that send visitors for links', 'seoprostats'),
                'description' => __('Once a day the site opens the pages of other sites that sent visitors (the address the browser gave, often only the other site\'s home page), at most for 20 seconds, and keeps the links on them to this site, with their text, so Search → Backlinks lists them and new and lost links show on the timeline. Each page is checked again weekly. The request names SEO Pro Stats and this site, and sends nothing about visitors. Off: no page is opened.', 'seoprostats'),
            ),
            'inspections'             => array(
                'type'        => 'int',
                'default'     => 200,
                'min'         => 0,
                'max'         => 2000,
                'unit'        => __('a day', 'seoprostats'),
                'tab'         => 'data',
                'label'       => __('Google URL inspections', 'seoprostats'),
                'description' => __('While Search Console is connected, the hourly import asks Google how it indexed this many pages a day at most (Google allows 2,000 a day per property): first the pages Search → Audit, Indexation lists, then pages with search traffic, each again after 14 days. Each answer gives Google\'s reason a page is or is not indexed, its last crawl, the canonical Google chose and rich result issues. Only from cron, never on a visitor page. 0: none.', 'seoprostats'),
            ),
            'viewers'                 => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'data',
                'label'       => __('Let other roles see the statistics', 'seoprostats'),
                'description' => __('Administrators always see them. People with the roles below see the SEO Pro Stats menu, the Dashboard widget and the read API, but not these settings.', 'seoprostats'),
            ),
            'viewer_roles'            => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => 'viewers',
                'options'     => array('SEOProStats_Feature', 'restrictable_role_options'),
                'label'       => __('Roles', 'seoprostats'),
            ),
        );
    }

    /**
     * Saving any setting rewrites the collector's config file.
     */
    public static function boot() {
        add_action('add_option_' . SEOProStats_Settings::OPTION, array(__CLASS__, 'write_config'));
        add_action('update_option_' . SEOProStats_Settings::OPTION, array(__CLASS__, 'write_config'));
    }

    /**
     * Rewrite the collector's config file after a save. Not on visitor
     * pages (the first request may store the settings' defaults); the
     * hourly job rewrites it anyway.
     */
    public static function write_config() {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            SEOProStats_Collection::write_config();
        }
    }

    /**
     * Roles that can edit posts, the default for people not counted.
     *
     * @return string[]
     */
    private static function editor_roles() {
        $roles = array();
        foreach (wp_roles()->roles as $role => $data) {
            if (!empty($data['capabilities']['edit_posts'])) {
                $roles[] = (string) $role;
            }
        }
        return $roles;
    }

    /**
     * Options for the address header.
     *
     * @return array<string,string>
     */
    public static function ip_header_options() {
        return array('' => __('The connection (no proxy)', 'seoprostats')) + self::IP_HEADERS;
    }

    /**
     * Options for the country header.
     *
     * @return array<string,string>
     */
    public static function country_header_options() {
        return array('' => __('Cloudflare\'s, when present', 'seoprostats')) + self::COUNTRY_HEADERS;
    }

    /**
     * Whether statistics are collected.
     *
     * @return bool
     */
    public static function collecting() {
        return (bool) SEOProStats_Settings::get(self::KEY);
    }

    /**
     * Roles not counted when logged in.
     *
     * @return string[]
     */
    public static function skip_roles() {
        return array_map('strval', (array) SEOProStats_Settings::get('tracking_skip_roles'));
    }

    /**
     * Query parameters kept in page addresses, besides UTM tags and
     * WordPress's own: lower case, valid names only.
     *
     * @return string[]
     */
    public static function params() {
        $out = array();
        foreach (self::lines('tracking_params') as $line) {
            $line = strtolower($line);
            if (preg_match('/^[a-z0-9_.-]{1,40}$/', $line)) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The site's other hosts, with and without www.
     *
     * @return string[]
     */
    public static function hosts() {
        $out = array();
        foreach (SEOProStats_Settings::parse_domains(SEOProStats_Settings::get('tracking_hosts')) as $host) {
            $out[] = $host;
            $out[] = 'www.' . $host;
        }
        return $out;
    }

    /**
     * Whether the tracker is loaded as a file.
     *
     * @return bool
     */
    public static function as_file() {
        return (bool) SEOProStats_Settings::get('tracking_file');
    }

    /**
     * The trusted address header, or '' for the connection's address.
     *
     * @return string
     */
    public static function ip_header() {
        $header = (string) SEOProStats_Settings::get('tracking_ip_header');
        return isset(self::IP_HEADERS[$header]) ? $header : '';
    }

    /**
     * The country header besides Cloudflare's, or ''.
     *
     * @return string
     */
    public static function country_header() {
        $header = (string) SEOProStats_Settings::get('tracking_country_header');
        return isset(self::COUNTRY_HEADERS[$header]) ? $header : '';
    }

    /**
     * Whether Do Not Track and Global Privacy Control are respected.
     *
     * @return bool
     */
    public static function respect_signals() {
        return (bool) SEOProStats_Settings::get('privacy_signals');
    }

    /**
     * Excluded addresses and ranges: valid ones only.
     *
     * @return string[]
     */
    public static function excluded_ips() {
        if (!SEOProStats_Settings::get('exclusions')) {
            return array();
        }
        $out = array();
        foreach (self::lines('exclude_ips') as $line) {
            $parts = explode('/', $line, 2);
            if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
                continue;
            }
            $max = strpos($parts[0], ':') === false ? 32 : 128;
            if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $max)) {
                continue;
            }
            $out[] = $line;
        }
        return array_values(array_unique($out));
    }

    /**
     * Excluded path patterns, each starting with / or *.
     *
     * @return string[]
     */
    public static function excluded_paths() {
        if (!SEOProStats_Settings::get('exclusions')) {
            return array();
        }
        $out = array();
        foreach (self::lines('exclude_paths') as $line) {
            $line  = preg_replace('/\s+/', '', $line);
            $out[] = $line[0] === '/' || $line[0] === '*' ? $line : '/' . $line;
        }
        return array_values(array_unique($out));
    }

    /**
     * Months visits, events, clicks and search data by page and query are
     * kept; 0 keeps them forever.
     *
     * @return array{visits:int,events:int,clicks:int,search:int}
     */
    public static function retention() {
        if (!SEOProStats_Settings::get('retention')) {
            return array('visits' => 0, 'events' => 0, 'clicks' => 0, 'search' => 0);
        }
        return array(
            'visits' => max(1, (int) SEOProStats_Settings::get('retention_visits')),
            'events' => max(1, (int) SEOProStats_Settings::get('retention_events')),
            'clicks' => max(1, (int) SEOProStats_Settings::get('retention_clicks')),
            'search' => max(1, (int) SEOProStats_Settings::get('retention_search')),
        );
    }

    /**
     * Whether clicks and form submits are captured.
     *
     * @return bool
     */
    public static function autocapture() {
        return (bool) SEOProStats_Settings::get('tracking_clicks');
    }

    /**
     * Whether the words of site searches are recorded.
     *
     * @return bool
     */
    public static function search_terms() {
        return (bool) SEOProStats_Settings::get('tracking_search');
    }

    /**
     * Whether a running A/B test keeps showing a visit the variant it
     * showed first, by keeping it in the tab's sessionStorage (opt-in).
     *
     * @return bool
     */
    public static function ab_one_variant() {
        return (bool) SEOProStats_Settings::get('tracking_ab_visit');
    }

    /**
     * The site's affiliate link paths, each starting with / or *.
     *
     * @return string[]
     */
    public static function affiliate_paths() {
        $out = array();
        foreach (self::lines('tracking_affiliate') as $line) {
            $line = (string) preg_replace('/\s+/', '', $line);
            if ($line !== '') {
                $out[] = $line[0] === '/' || $line[0] === '*' ? $line : '/' . $line;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Whether search engine updates are fetched.
     *
     * @return bool
     */
    public static function search_updates() {
        return (bool) SEOProStats_Settings::get('search_updates');
    }

    /**
     * Whether pages that send visitors are checked for links (backlinks).
     *
     * @return bool
     */
    public static function backlinks() {
        return (bool) SEOProStats_Settings::get('backlinks');
    }

    /**
     * Google URL inspections a day at most (0: none).
     *
     * @return int
     */
    public static function inspections() {
        return max(0, (int) SEOProStats_Settings::get('inspections'));
    }

    /**
     * The other search update feeds: address, engine key and name, each
     * address once, at most SEOProStats_Search_Updates::MAX_FEEDS.
     *
     * @return array<int,array{url:string,engine:string,name:string}>
     */
    public static function search_feeds() {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-updates.php';
        $out = array();
        foreach (self::lines('search_updates_feeds') as $line) {
            $feed = SEOProStats_Search_Updates::parse_feed_line($line);
            if ($feed && !isset($out[$feed['url']])) {
                $out[$feed['url']] = $feed;
            }
        }
        return array_slice(array_values($out), 0, SEOProStats_Search_Updates::MAX_FEEDS);
    }

    /**
     * Roles that may read the statistics, besides people who can manage
     * options.
     *
     * @return string[]
     */
    public static function viewer_roles() {
        if (!SEOProStats_Settings::get('viewers')) {
            return array();
        }
        return array_map('strval', (array) SEOProStats_Settings::get('viewer_roles'));
    }

    /**
     * A lines setting's non-empty lines.
     *
     * @param string $key Setting key.
     * @return string[]
     */
    private static function lines($key) {
        $lines = preg_split('/[\r\n]+/', (string) SEOProStats_Settings::get($key)) ?: array();
        return array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));
    }
}
