<?php
/**
 * Demo data: made-up visits in tables of their own (seoprostats_demo_*),
 * for training, screenshots and testing. Never mixed with live data.
 *
 * Visits are made as the collector would write them (lines of hits) and
 * go through the processor and the daily summaries, so demo reports use
 * the same code and queries as live ones. Traffic grows over the period,
 * with weekends, seasons, the odd spike, campaigns, paid visits, AI
 * answers, events with properties, purchases with revenue, and (for the
 * last three months, as kept by default) clicks and form submits, and
 * changes for the markers (SEOProStats_Changes). While it
 * is shown, demo data is topped up to the present, so today and realtime
 * have visits too. Design: docs/architecture.md → Demo data.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 *
 * @package SEOProStats
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStats_Demo {

    /** Progress (autoload off): status (making, ready), days, from, upto (made through), made. */
    const OPTION = 'seoprostats_demo';

    /** Lock against two requests making the same visits. */
    const LOCK_OPTION = 'seoprostats_demo_lock';

    /** Per-user choice of the data shown (user meta): live or demo. */
    const USER_META = 'seoprostats_data';

    /** Days made by default: over a year, for year-on-year and 12-month views. */
    const DAYS = 400;

    /** Most days. */
    const MAX_DAYS = 800;

    /** Seconds of work per request. */
    const BUDGET = 10;

    /** Top up when the newest visit made is older than this (seconds). */
    const FRESH = 300;

    /** Seconds a lock is honoured. */
    const LOCK_TTL = 120;

    /** Visits a day at the end of the period, before weekends and seasons. */
    const PEAK = 230;

    /** Days back from now whose visits have clicks: clicks are kept 3 months by default. */
    const CLICK_DAYS = 92;

    /**
     * Clicks by page (path prefix; '' on every page): selector, label,
     * target, flags (1 dead, 4 affiliate), chance per pageview.
     */
    const CLICKS = array(
        ''           => array(
            array('a.custom-logo-link', 'Home', '/', 0, 0.04),
            array('a.wp-block-navigation-item__content', 'Pricing', '/pricing/', 0, 0.05),
            array('a.wp-block-navigation-item__content', 'Docs', '/docs/', 0, 0.03),
            array('button.wp-block-navigation__responsive-container-open', 'Open menu', '', 0, 0.04),
        ),
        '/blog/'     => array(
            array('a.wp-block-button__link', 'Try it free', '/pricing/', 0, 0.05),
            array('img.wp-image', 'Rankings before and after an update', '', 1, 0.03),
            array('a', 'Our recommended host', '/go/hosting/', 4, 0.02),
        ),
        '/pricing/'  => array(
            array('a.wp-block-button__link', 'Buy Pro', '/shop/pro-licence/', 0, 0.12),
            array('span.plan-badge', 'Most popular', '', 1, 0.05),
            array('summary', 'Can I cancel at any time?', '', 0, 0.06),
        ),
        '/features/' => array(
            array('div.feature-card', 'Changes on the timeline', '', 1, 0.05),
            array('a.wp-block-button__link', 'See pricing', '/pricing/', 0, 0.1),
        ),
        '/shop/'     => array(
            array('button.single_add_to_cart_button', 'Add to cart', '', 0, 0.15),
        ),
    );

    /**
     * Where visits come from: weight, referrer, landing query ({c}: the
     * month's campaign, {id}: a click ID), landing pages.
     */
    const SOURCES = array(
        'direct'     => array(24, '', '', 'home'),
        'google'     => array(30, 'https://www.google.com/', '', 'content'),
        'bing'       => array(4, 'https://www.bing.com/', '', 'content'),
        'duckduckgo' => array(2.5, 'https://duckduckgo.com/', '', 'content'),
        'ecosia'     => array(0.5, 'https://www.ecosia.org/', '', 'content'),
        'chatgpt'    => array(3, 'https://chatgpt.com/', '?utm_source=chatgpt.com', 'content'),
        'perplexity' => array(1.2, 'https://www.perplexity.ai/', '', 'content'),
        'claude'     => array(0.6, 'https://claude.ai/', '', 'content'),
        'gemini'     => array(0.5, 'https://gemini.google.com/', '', 'content'),
        'reddit'     => array(3, 'https://www.reddit.com/r/Wordpress/', '', 'content'),
        'linkedin'   => array(2, 'https://www.linkedin.com/', '', 'home'),
        'x'          => array(1.5, 'https://t.co/', '', 'content'),
        'youtube'    => array(1, 'https://www.youtube.com/', '', 'product'),
        'facebook'   => array(1.5, 'https://www.facebook.com/', '', 'home'),
        'hn'         => array(0.6, 'https://news.ycombinator.com/', '', 'content'),
        'newsletter' => array(3, '', '?utm_source=newsletter&utm_medium=email&utm_campaign={c}', 'campaign'),
        'gmail'      => array(1, 'https://mail.google.com/', '', 'home'),
        'google_ads' => array(3, 'https://www.google.com/', '?utm_source=google&utm_medium=cpc&utm_campaign=brand&gclid={id}', 'product'),
        'meta_ads'   => array(1.2, 'https://m.facebook.com/', '?utm_source=facebook&utm_medium=paid_social&utm_campaign=autumn-sale&fbclid={id}', 'shop'),
        'partner'    => array(2, 'https://example.org/best-wordpress-plugins/', '', 'product'),
        'wordpress'  => array(1.5, 'https://wordpress.org/support/', '', 'docs'),
        'github'     => array(1, 'https://github.com/', '', 'docs'),
    );

    /** Landing pages by kind: path => weight. */
    const LANDINGS = array(
        'home'     => array('/' => 10, '/about/' => 1),
        'content'  => array('/blog/core-web-vitals-explained/' => 6, '/blog/how-to-read-search-rankings/' => 6, '/blog/privacy-friendly-analytics/' => 5, '/blog/speed-up-wordpress/' => 5, '/blog/what-changed-after-an-update/' => 3, '/docs/faq/' => 2, '/' => 3),
        'product'  => array('/features/' => 5, '/pricing/' => 4, '/' => 3),
        'campaign' => array('/blog/what-changed-after-an-update/' => 5, '/pricing/' => 3),
        'shop'     => array('/shop/' => 3, '/shop/pro-licence/' => 4),
        'docs'     => array('/docs/' => 4, '/docs/getting-started/' => 5, '/docs/faq/' => 3),
    );

    /** Pages visited next: path => weight. */
    const PAGES = array(
        '/'                                    => 18,
        '/blog/'                               => 8,
        '/blog/core-web-vitals-explained/'     => 6,
        '/blog/how-to-read-search-rankings/'   => 6,
        '/blog/privacy-friendly-analytics/'    => 5,
        '/blog/speed-up-wordpress/'            => 5,
        '/blog/what-changed-after-an-update/'  => 3,
        '/features/'                           => 7,
        '/pricing/'                            => 8,
        '/docs/'                               => 5,
        '/docs/getting-started/'               => 4,
        '/docs/faq/'                           => 3,
        '/about/'                              => 2,
        '/contact/'                            => 3,
        '/shop/'                               => 4,
        '/shop/pro-licence/'                   => 4,
    );

    /** What the demo pages show (SEOProStats_Processor::write_pages()): path => post type, author, category. */
    const CONTENT = array(
        '/'                                    => array('page', 9001, 0),
        '/blog/core-web-vitals-explained/'     => array('post', 9002, 9102),
        '/blog/how-to-read-search-rankings/'   => array('post', 9001, 9101),
        '/blog/privacy-friendly-analytics/'    => array('post', 9003, 9103),
        '/blog/speed-up-wordpress/'            => array('post', 9002, 9102),
        '/blog/what-changed-after-an-update/'  => array('post', 9001, 9104),
        '/features/'                           => array('page', 9001, 0),
        '/pricing/'                            => array('page', 9001, 0),
        '/docs/'                               => array('page', 9003, 0),
        '/docs/getting-started/'               => array('page', 9003, 0),
        '/docs/faq/'                           => array('page', 9003, 0),
        '/about/'                              => array('page', 9001, 0),
        '/contact/'                            => array('page', 9001, 0),
        '/shop/pro-licence/'                   => array('product', 9001, 9105),
        '/cart/'                               => array('page', 9001, 0),
        '/checkout/'                           => array('page', 9001, 0),
    );

    /** Names of the demo authors, categories and post types, by ID or name. */
    const NAMES = array(
        'author'    => array(9001 => 'Sam Rivera', 9002 => 'Priya Shah', 9003 => 'Jonas Weber'),
        'category'  => array(9101 => 'Guides', 9102 => 'Performance', 9103 => 'Privacy', 9104 => 'News', 9105 => 'Licences'),
        'post_type' => array('post' => 'Post', 'page' => 'Page', 'product' => 'Product'),
    );

    /** Addresses that are not found: old ones still linked from elsewhere, and typos. path => weight. */
    const NOT_FOUND = array(
        '/blog/seo-checklist-2024/'     => 5,
        '/blog/core-web-vitals/'        => 3,
        '/prcing/'                      => 2,
        '/docs/instal/'                 => 1,
        '/wp-content/uploads/guide.pdf' => 1,
    );

    /** Site searches: words => [weight, results]. */
    const SEARCHES = array(
        'core web vitals' => array(5, 3),
        'pricing'         => array(3, 1),
        'speed'           => array(3, 4),
        'gdpr'            => array(3, 2),
        'refund'          => array(2, 0),
        'woocommerce'     => array(2, 0),
        'import from csv' => array(1, 0),
        'dark mode'       => array(1, 0),
    );

    /** Browsers and devices: weight, user agent, screen width. */
    const DEVICES = array(
        array(30, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 1920),
        array(9, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 1512),
        array(7, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15', 1440),
        array(7, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 1920),
        array(4, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:143.0) Gecko/20100101 Firefox/143.0', 1920),
        array(2, 'Mozilla/5.0 (X11; Linux x86_64; rv:143.0) Gecko/20100101 Firefox/143.0', 1920),
        array(18, 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 412),
        array(3, 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36', 384),
        array(16, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1', 393),
        array(2, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1', 393),
        array(3, 'Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1', 820),
    );

    /** Where visitors are: weight, country, language, time zone. */
    const PLACES = array(
        array(30, 'US', 'en-US', 'America/New_York'),
        array(8, 'US', 'en-US', 'America/Los_Angeles'),
        array(18, 'GB', 'en-GB', 'Europe/London'),
        array(8, 'DE', 'de-DE', 'Europe/Berlin'),
        array(5, 'FR', 'fr-FR', 'Europe/Paris'),
        array(3, 'NL', 'nl-NL', 'Europe/Amsterdam'),
        array(3, 'ES', 'es-ES', 'Europe/Madrid'),
        array(7, 'IN', 'en-IN', 'Asia/Kolkata'),
        array(5, 'CA', 'en-CA', 'America/Toronto'),
        array(4, 'AU', 'en-AU', 'Australia/Sydney'),
        array(3, 'BR', 'pt-BR', 'America/Sao_Paulo'),
        array(2, 'JP', 'ja-JP', 'Asia/Tokyo'),
        array(2, '', '', ''),
    );

    /** Share of a day's visits by site-local hour. */
    const HOURS = array(2, 1.5, 1, 1, 1, 1.5, 2.5, 4, 5.5, 6.5, 7, 7, 6.5, 6.5, 7, 7, 6.5, 6, 5.5, 5, 4.5, 4, 3.5, 2.5);

    /** Plans bought: weight, name, price by currency. */
    const PLANS = array(
        array(6, 'Personal', array('USD' => 49, 'GBP' => 39, 'EUR' => 45)),
        array(3, 'Business', array('USD' => 99, 'GBP' => 79, 'EUR' => 89)),
        array(1, 'Agency', array('USD' => 199, 'GBP' => 159, 'EUR' => 179)),
    );

    /** Example goals of the demo data (SEOProStats_Goals), from its pages and events. */
    const GOALS = array(
        array('name' => 'Purchase', 'kind' => 'event', 'match' => 'Purchase'),
        array('name' => 'Newsletter signup', 'kind' => 'event', 'match' => 'Newsletter signup'),
        array('name' => 'Contact form sent', 'kind' => 'event', 'match' => 'Contact form'),
        array('name' => 'Download', 'kind' => 'event', 'match' => 'Download'),
        array('name' => 'Affiliate click', 'kind' => 'event', 'match' => 'Affiliate link'),
        array('name' => 'Viewed pricing', 'kind' => 'page', 'match' => '/pricing/'),
        array('name' => 'Read the docs', 'kind' => 'page', 'match' => '/docs/*'),
    );

    /** Example funnels of the demo data. */
    const FUNNELS = array(
        array(
            'name'  => 'Checkout',
            'steps' => array(
                array('name' => 'Pricing', 'kind' => 'page', 'match' => '/pricing/'),
                array('name' => 'Cart', 'kind' => 'page', 'match' => '/cart/'),
                array('name' => 'Checkout', 'kind' => 'page', 'match' => '/checkout/'),
                array('name' => 'Purchase', 'kind' => 'event', 'match' => 'Purchase'),
            ),
        ),
        array(
            'name'  => 'Blog to newsletter',
            'steps' => array(
                array('name' => 'Blog post', 'kind' => 'page', 'match' => '/blog/*/'),
                array('name' => 'Newsletter signup', 'kind' => 'event', 'match' => 'Newsletter signup'),
            ),
        ),
    );

    /**
     * Changes of the demo data (SEOProStats_Changes), for the markers:
     * days back from today, hour, kind code, path, object type, old, new,
     * details. Repeating ones (updates, edits) come from change_rows().
     */
    const CHANGES = array(
        array(2, 9, 23, '/shop/pro-licence/', 'product', '99', '79', array('name' => 'Pro licence', 'currency' => 'USD', 'field' => 'regular')),
        array(6, 16, 4, '/blog/how-to-read-search-rankings/', 'post', 'How to read search rankings', 'How to read your search rankings in 2026', array('name' => 'How to read your search rankings in 2026')),
        array(11, 11, 20, '/shop/pro-licence/', 'product', 'instock', 'outofstock', array('name' => 'Pro licence', 'currency' => 'USD')),
        array(9, 8, 21, '/shop/pro-licence/', 'product', 'outofstock', 'instock', array('name' => 'Pro licence', 'currency' => 'USD')),
        array(17, 14, 6, '/blog/core-web-vitals-explained/', 'post', '4', '7', array('name' => 'Core Web Vitals explained', 'added' => array(array('to' => '/blog/speed-up-wordpress/', 'text' => 'speed up WordPress'), array('to' => '/pricing/', 'text' => 'see the plans'), array('to' => '/docs/getting-started/', 'text' => 'getting started')), 'removed' => array(), 'changed' => array())),
        array(24, 10, 11, '/pricing/', 'page', '', 'Plans for every site, with a 30-day refund.', array('name' => 'Pricing', 'field' => '_yoast_wpseo_metadesc')),
        array(38, 15, 24, '/shop/pro-licence/', 'product', '99', '69', array('name' => 'Pro licence', 'currency' => 'USD', 'field' => 'sale', 'regular' => '99')),
        array(31, 9, 25, '/shop/pro-licence/', 'product', '69', '99', array('name' => 'Pro licence', 'currency' => 'USD', 'field' => 'sale', 'regular' => '99')),
        array(45, 13, 1, '/blog/what-changed-after-an-update/', 'post', 'draft', 'publish', array('name' => 'What changed after an update')),
        array(74, 12, 26, '', 'coupon', '', 'SPRING20', array('name' => 'SPRING20', 'status' => 'publish')),
        array(60, 12, 28, '', 'coupon', '', 'SPRING20', array('name' => 'SPRING20', 'status' => 'trash')),
        array(88, 10, 3, '/docs/getting-started/', 'page', '/docs/start/', '/docs/getting-started/', array('name' => 'Getting started')),
        array(120, 17, 45, '', 'theme', 'Twenty Twenty-Four', 'Twenty Twenty-Five', array('name' => 'Twenty Twenty-Five', 'version' => '1.2')),
        array(150, 11, 1, '/blog/privacy-friendly-analytics/', 'post', 'draft', 'publish', array('name' => 'Privacy-friendly analytics')),
        array(170, 10, 22, '/shop/pro-licence/', 'product', '89', '99', array('name' => 'Pro licence', 'currency' => 'USD', 'field' => 'regular')),
        array(200, 16, 42, '', 'plugin', '', '2.4.0', array('name' => 'Cache Enabler', 'file' => 'cache-enabler/cache-enabler.php')),
        array(240, 9, 1, '/blog/speed-up-wordpress/', 'post', 'draft', 'publish', array('name' => 'Speed up WordPress')),
        array(300, 14, 1, '/blog/core-web-vitals-explained/', 'post', 'draft', 'publish', array('name' => 'Core Web Vitals explained')),
        array(330, 10, 1, '/blog/how-to-read-search-rankings/', 'post', 'draft', 'publish', array('name' => 'How to read search rankings')),
        array(4, 10, 80, '', 'note', '', 'Newsletter sent: October tips', array()),
        array(38, 9, 80, '/shop/pro-licence/', 'note', '', 'Autumn sale emailed to customers', array()),
        array(100, 13, 80, '', 'note', '', 'Moved to a faster host', array()),
    );

    /** Plugins updated now and then in the demo data: name, file, first version. */
    const DEMO_PLUGINS = array(
        array('WooCommerce', 'woocommerce/woocommerce.php', array(8, 9)),
        array('Yoast SEO', 'wordpress-seo/wp-seo.php', array(22, 1)),
        array('Contact Form 7', 'contact-form-7/wp-contact-form-7.php', array(5, 9)),
    );

    /** @var array<int,array<string,mixed>> Recent visitors of this run, to come back the same day. */
    private static $recent = array();

    /** @var string The site's host, for the lines. */
    private static $host = '';

    /**
     * Run something on the demo tables, then go back to the data set before.
     *
     * @param callable $work Work.
     * @return mixed What it returns.
     */
    public static function run(callable $work) {
        $before = SEOProStats_Schema::use_set('demo');
        try {
            return $work();
        } finally {
            SEOProStats_Schema::use_set($before);
        }
    }

    /**
     * Stored progress.
     *
     * @return array<string,mixed>
     */
    public static function state() {
        $state = get_option(self::OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * Whether demo data is made and can be shown.
     *
     * @return bool
     */
    public static function ready() {
        $state = self::state();
        return isset($state['status']) && $state['status'] === 'ready';
    }

    /**
     * Status for the API and the screen.
     *
     * @return array{status:string,days:int,progress:float,from:string|null,made:string|null}
     */
    public static function status() {
        $state  = self::state();
        $status = isset($state['status']) && in_array($state['status'], array('making', 'ready'), true) ? (string) $state['status'] : 'none';
        $from   = isset($state['from']) ? (int) $state['from'] : 0;
        $upto   = isset($state['upto']) ? (int) $state['upto'] : 0;
        $end    = isset($state['end']) ? (int) $state['end'] : 0;
        $progress = 0.0;
        if ($status === 'ready') {
            $progress = 1.0;
        } elseif ($status === 'making' && $end > $from) {
            // Making visits is most of the work; summaries the last bit.
            $progress = round(min(0.99, 0.95 * ($upto - $from) / ($end - $from)), 3);
        }
        return array(
            'status'   => $status,
            'days'     => isset($state['days']) ? (int) $state['days'] : 0,
            'progress' => $progress,
            'from'     => $from ? gmdate('c', $from) : null,
            'made'     => !empty($state['made']) ? gmdate('c', (int) $state['made']) : null,
        );
    }

    /**
     * Start again: remove any demo data, make empty demo tables, and set
     * the period. step() then makes the visits.
     *
     * @param int $days Days back from today.
     * @return bool Whether the tables were made.
     */
    public static function start($days = self::DAYS) {
        $days = max(1, min(self::MAX_DAYS, (int) $days));
        self::remove();
        $ok = self::run(static function () {
            return SEOProStats_Schema::install();
        });
        if (!$ok) {
            return false;
        }
        self::examples();
        $from = (new DateTimeImmutable('today', wp_timezone()))->modify("-$days days")->getTimestamp();
        self::changes($from);
        update_option(self::OPTION, array(
            'status'  => 'making',
            'days'    => $days,
            'from'    => $from,
            'upto'    => $from,
            'end'     => time(),
            'made'    => 0,
            'changes' => 1,
        ), false);
        return true;
    }

    /**
     * Make visits up to now within a time budget, then the daily
     * summaries; ready when both are done. Safe to call again and again.
     *
     * @param int $budget Seconds.
     * @return array<string,mixed> status().
     */
    public static function step($budget = self::BUDGET) {
        $start = microtime(true);
        $state = self::state();
        if (!isset($state['status']) || !self::lock()) {
            return self::status();
        }
        try {
            self::run(static function () use ($start, $budget) {
                self::catch_up($start, $budget);
            });
        } finally {
            self::unlock();
        }
        return self::status();
    }

    /**
     * While demo data is shown: make the visits since it was last topped
     * up, so today and realtime have visits. Skipped when another request
     * is at it.
     */
    public static function refresh() {
        // Demo data made before goals existed gets the examples once.
        if (self::ready()) {
            require_once __DIR__ . '/class-seoprostats-goals.php';
            $none = self::run(static function () {
                return get_option(SEOProStats_Schema::option(SEOProStats_Goals::GOALS_OPTION)) === false;
            });
            if ($none) {
                self::examples();
            }
            // Demo data made before the change log gets its changes once.
            $state = self::state();
            if (empty($state['changes'])) {
                $state['changes'] = 1;
                update_option(self::OPTION, $state, false);
                self::changes(isset($state['from']) ? (int) $state['from'] : time());
            }
        }
        $state = self::state();
        if (!self::ready() || (isset($state['upto']) && (int) $state['upto'] > time() - self::FRESH) || !self::lock()) {
            return;
        }
        try {
            self::run(static function () {
                SEOProStats_Schema::maybe_upgrade();
                self::catch_up(microtime(true), 5);
            });
        } finally {
            self::unlock();
        }
    }

    /**
     * Remove the demo tables and their progress; nothing of live data.
     */
    public static function remove() {
        require_once __DIR__ . '/class-seoprostats-goals.php';
        self::run(static function () {
            SEOProStats_Schema::drop();
            SEOProStats_Goals::forget();
            delete_option(SEOProStats_Schema::option(SEOProStats_Collection::PROCESS_OPTION));
            delete_option(SEOProStats_Schema::option(SEOProStats_Collection::ROLLUP_OPTION));
        });
        delete_option(self::OPTION);
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Give the demo data its example goals and funnels, replacing any.
     */
    public static function examples() {
        require_once __DIR__ . '/class-seoprostats-goals.php';
        self::run(static function () {
            SEOProStats_Goals::replace(self::GOALS, self::FUNNELS);
        });
    }

    /**
     * Give the demo data its changes (the markers) over its period, on the
     * demo tables. Once per demo data: start(), or refresh() for demo data
     * made before the change log.
     *
     * @param int $from Start of the period.
     */
    private static function changes($from) {
        require_once __DIR__ . '/class-seoprostats-changes.php';
        self::run(static function () use ($from) {
            if (!SEOProStats_Schema::maybe_upgrade()) {
                return;
            }
            foreach (self::change_rows((int) $from, time()) as $row) {
                SEOProStats_Changes::write($row);
            }
        });
    }

    /**
     * The demo changes between two times: CHANGES, plus plugin updates
     * every 16 days, a WordPress update every 63 days and a post edited
     * every 13 days.
     *
     * @param int $from Start.
     * @param int $to   End.
     * @return array<int,array<string,mixed>> Rows for SEOProStats_Changes::write().
     */
    private static function change_rows($from, $to) {
        $today = new DateTimeImmutable('today', wp_timezone());
        $rows  = array();
        $add   = static function ($days, $hour, $kind, $path, $type, $old, $new, array $meta) use (&$rows, $today, $from, $to) {
            $ts = $today->modify('-' . (int) $days . ' days')->setTime((int) $hour, ($kind * 7) % 60)->getTimestamp();
            if ($ts >= $from && $ts <= $to) {
                $rows[] = array(
                    'ts'          => $ts,
                    'kind'        => (int) $kind,
                    'path'        => (string) $path,
                    'object_type' => (string) $type,
                    'object_id'   => $path !== '' && self::page($path) ? self::page($path)['post_id'] : 0,
                    'old'         => (string) $old,
                    'new'         => (string) $new,
                    'meta'        => $meta,
                    'source'      => in_array((int) $kind, array(41, 47), true) ? 4 : ((int) $kind === SEOProStats_Changes::NOTE ? 6 : 1),
                    'user_id'     => 0,
                );
            }
        };
        foreach (self::CHANGES as $change) {
            $add($change[0], $change[1], $change[2], $change[3], $change[4], $change[5], $change[6], $change[7]);
        }
        $span = (int) ceil(max(0, $to - $from) / DAY_IN_SECONDS);
        $n    = 0;
        for ($days = $span; $days >= 1; $days -= 16, $n++) {
            list($name, $file, $first) = self::DEMO_PLUGINS[$n % count(self::DEMO_PLUGINS)];
            $minor = $first[1] + intdiv($n, count(self::DEMO_PLUGINS));
            $add($days, 3, 41, '', 'plugin', $first[0] . '.' . $minor . '.0', $first[0] . '.' . ($minor + 1) . '.0', array('name' => $name, 'file' => $file));
        }
        $n = 0;
        for ($days = $span - 20; $days >= 1; $days -= 63, $n++) {
            $add($days, 4, 47, '', 'core', '6.' . (5 + $n), '6.' . (6 + $n), array('name' => 'WordPress'));
        }
        $posts = array_values(array_filter(array_keys(self::CONTENT), static function ($path) {
            return strpos($path, '/blog/') === 0;
        }));
        $n     = 0;
        for ($days = $span - 5; $days >= 1; $days -= 13, $n++) {
            $path   = $posts[$n % count($posts)];
            $before = 900 + ($n * 37) % 600;
            $added  = 40 + ($n * 53) % 260;
            $gone   = 10 + ($n * 29) % 90;
            $name   = ucfirst(str_replace(array('blog/', '-'), array('', ' '), trim($path, '/')));
            $add($days, 11, 5, $path, 'post', (string) $before, (string) ($before + $added - $gone), array('name' => $name, 'before' => $before, 'after' => $before + $added - $gone, 'added' => $added, 'removed' => $gone));
        }
        usort($rows, static function ($x, $y) {
            return $x['ts'] - $y['ts'];
        });
        return $rows;
    }

    /**
     * The data set a user looks at: demo only when chosen and made.
     *
     * @param int $user_id User.
     * @return string live or demo.
     */
    public static function viewing($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        return $user_id && get_user_meta($user_id, self::USER_META, true) === 'demo' ? 'demo' : 'live';
    }

    /**
     * Make visits from where they were made up to now (in windows of up
     * to a day), then summarise finished days, on the demo tables.
     *
     * @param float $start  microtime(true) when the work began.
     * @param int   $budget Seconds.
     */
    private static function catch_up($start, $budget) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-processor.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-rollup.php';
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-query.php';
        self::$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        $state = self::state();
        $upto  = isset($state['upto']) ? (int) $state['upto'] : time();
        $now   = time();
        while ($upto < $now && SEOProStats_Feature::more_time($start, $budget)) {
            $to = min($now, $upto + DAY_IN_SECONDS);
            SEOProStats_Processor::ingest(self::lines($upto, $to));
            $upto          = $to;
            $state['upto'] = $upto;
            update_option(self::OPTION, $state, false);
        }
        $more = $upto < $now;
        // The summaries' own budget is longer: end it with this one.
        $rollup_start = $start - max(0, SEOProStats_Rollup::BUDGET - $budget);
        while (!$more && SEOProStats_Rollup::due() !== null) {
            if (!SEOProStats_Feature::more_time($start, $budget) || !SEOProStats_Rollup::catch_up($rollup_start)) {
                $more = true;
            }
        }
        if (!$more && $state['status'] !== 'ready') {
            $state['status'] = 'ready';
            $state['made']   = time();
            update_option(self::OPTION, $state, false);
        }
        // New data: cached answers for the demo data go.
        update_option(SEOProStats_Schema::option(SEOProStats_Collection::PROCESS_OPTION), array('last' => time()), false);
    }

    /**
     * What a demo page shows, as SEOProStats_Processor::page() finds it for
     * a live one; null for lists and pages not found.
     *
     * @param string $path Page path.
     * @return array{post_id:int,post_type:string,author_id:int,term_id:int}|null
     */
    public static function page($path) {
        if (!isset(self::CONTENT[$path])) {
            return null;
        }
        list($type, $author, $term) = self::CONTENT[$path];
        return array(
            'post_id'   => 1000 + (int) array_search($path, array_keys(self::CONTENT), true),
            'post_type' => $type,
            'author_id' => $author,
            'term_id'   => $term,
        );
    }

    /**
     * Name of a demo author, category or post type (SEOProStats_Query
     * labels demo rows with these, as the IDs are not the site's).
     *
     * @param string $dimension author, category or post_type.
     * @param string $value     ID or post type name.
     * @return string '' when unknown.
     */
    public static function name($dimension, $value) {
        return isset(self::NAMES[$dimension][$value]) ? self::NAMES[$dimension][$value] : '';
    }

    /**
     * The collector's lines for the visits that start in [from, to), in
     * time order. Hits after now are left out.
     *
     * @param int $from Unix time.
     * @param int $to   Unix time.
     * @return array<int,array<string,mixed>>
     */
    private static function lines($from, $to) {
        $tz    = wp_timezone();
        $now   = time();
        $lines = array();
        for ($hour = (int) (floor($from / HOUR_IN_SECONDS) * HOUR_IN_SECONDS); $hour < $to; $hour += HOUR_IN_SECONDS) {
            $a    = max($from, $hour);
            $b    = min($to, $hour + HOUR_IN_SECONDS);
            $time = (new DateTimeImmutable('@' . $a))->setTimezone($tz);
            $day  = self::day_shape($time, $now);
            $rate = $day['visits'] * self::HOURS[(int) $time->format('G')] / array_sum(self::HOURS);
            $want = $rate * ($b - $a) / HOUR_IN_SECONDS;
            $n    = (int) floor($want) + (self::chance($want - floor($want)) ? 1 : 0);
            for ($i = 0; $i < $n; $i++) {
                $source = $day['spike'] !== '' && self::chance($day['share']) ? $day['spike'] : self::pick_key(self::SOURCES);
                foreach (self::visit(random_int($a, max($a, $b - 1)), $source, $time) as $line) {
                    if ($line['ts'] <= $now) {
                        $lines[] = $line;
                    }
                }
            }
        }
        usort($lines, static function ($x, $y) {
            return $x['ts'] - $y['ts'];
        });
        return $lines;
    }

    /**
     * How busy a day is: visits (growing over the period, quieter at
     * weekends, a little seasonal), and on the odd day a spike from one
     * source (a post that took off, a newsletter).
     *
     * @param DateTimeImmutable $time A time in the day (site-local).
     * @param int               $now  Unix time now.
     * @return array{visits:float,spike:string,share:float}
     */
    private static function day_shape(DateTimeImmutable $time, $now) {
        static $days = array();
        $date = $time->format('Y-m-d');
        if (isset($days[$date])) {
            return $days[$date];
        }
        $ago    = max(0, ($now - $time->getTimestamp()) / DAY_IN_SECONDS);
        $visits = self::PEAK * exp(-$ago / 420);
        $visits *= (int) $time->format('N') >= 6 ? 0.62 : 1.0;
        $visits *= 1 + 0.08 * sin(2 * M_PI * ((int) $time->format('z') - 80) / 365);
        $spike  = '';
        $share  = 0.0;
        $hash   = crc32('seoprostats-demo-' . $date);
        if ($hash % 41 === 0) {
            $spike   = 'hn';
            $share   = 0.45;
            $visits *= 1.8;
        } elseif ($hash % 23 === 0) {
            $spike   = 'newsletter';
            $share   = 0.3;
            $visits *= 1.4;
        }
        $days[$date] = array('visits' => $visits, 'spike' => $spike, 'share' => $share);
        return $days[$date];
    }

    /**
     * One visit's lines: a pageview line per page with its engagement,
     * and events.
     *
     * @param int               $started Unix time of the first page.
     * @param string            $source  Key of SOURCES.
     * @param DateTimeImmutable $time    Site-local time in the visit's hour.
     * @return array<int,array<string,mixed>>
     */
    private static function visit($started, $source, DateTimeImmutable $time) {
        list(, $referrer, $query, $landing) = self::SOURCES[$source];
        $who = self::visitor($started);

        $paths = array(self::pick_key(self::LANDINGS[$landing]));
        $pages = self::chance(0.46) ? 1 : 2 + min(6, (int) floor(-log(max(1e-6, self::unit())) * 1.6));
        while (count($paths) < $pages) {
            $last = end($paths);
            if (($last === '/pricing/' || $last === '/shop/pro-licence/') && self::chance(0.12)) {
                $paths[] = '/cart/';
                if (self::chance(0.7)) {
                    $paths[] = '/checkout/';
                    if (self::chance(0.6)) {
                        $paths[] = '/checkout/order-received/';
                    }
                }
                break;
            }
            $next = self::pick_key(self::PAGES);
            if ($next !== $last) {
                $paths[] = $next;
            }
        }

        // What WordPress would say about the pages: some landings are not
        // found, some visits search the site, a few are logged in.
        $context = array_fill(0, count($paths), array());
        if ($landing === 'content' && self::chance(0.03)) {
            $paths[0]   = self::pick_key(self::NOT_FOUND);
            $context[0] = array('n' => 1);
        }
        if (count($paths) > 1 && self::chance(0.12)) {
            $words = self::pick_key(self::SEARCHES);
            array_splice($paths, 1, 0, array('/'));
            array_splice($context, 1, 0, array(array('q' => 1, 's' => $words, 'r' => self::SEARCHES[$words][1])));
        }
        if (self::chance(0.05)) {
            foreach (array_keys($context) as $i) {
                $context[$i]['l'] = 1;
            }
        }

        $lines  = array();
        $ts     = $started;
        $id     = bin2hex(random_bytes(6));
        $clicks = $started >= time() - self::CLICK_DAYS * DAY_IN_SECONDS;
        foreach ($paths as $seq => $path) {
            $pkey = bin2hex(random_bytes(8));
            $hit  = array('t' => 'pv', 'p' => $pkey, 'u' => $path, 'w' => $who['screen'], 'tz' => $who['tz'], 'l' => $who['lang']);
            if ($context[$seq]) {
                $hit['x'] = $context[$seq];
            }
            if ($seq === 0) {
                $hit['u'] .= strtr($query, array('{c}' => strtolower($time->format('F')) . '-update', '{id}' => $id));
                $hit['r']  = $referrer;
            } else {
                $hit['r'] = home_url($paths[$seq - 1]);
            }
            $lines[] = self::line($ts, $who, $hit);

            $quick   = count($paths) === 1 && self::chance(0.4);
            $visible = $quick ? random_int(2, 10) : random_int(15, 170);
            $lines[] = self::line($ts + $visible, $who, array('t' => 'eng', 'p' => $pkey, 's' => $visible * 1000, 'sc' => $quick ? random_int(0, 30) : random_int(25, 100)));

            $events = self::events($path, $who);
            if ($clicks) {
                $events = array_merge($events, self::clicks($path, $events));
            }
            foreach ($events as $event) {
                $event['p'] = $pkey;
                if ($event['t'] === 'e') {
                    $event['u'] = $path;
                }
                $lines[] = self::line($ts + (int) ($visible / 2), $who, $event);
            }
            $ts += $visible + random_int(2, 20);
        }
        $who['ended']    = $ts;
        self::$recent[] = $who;
        if (count(self::$recent) > 200) {
            array_shift(self::$recent);
        }
        return $lines;
    }

    /**
     * Who makes a visit: now and then someone back from earlier the same
     * day, else someone new.
     *
     * @param int $started Unix time.
     * @return array<string,mixed>
     */
    private static function visitor($started) {
        if (self::$recent && self::chance(0.12)) {
            $back = self::$recent[array_rand(self::$recent)];
            // Over 30 minutes later the same day: a new visit by the same visitor.
            if ($started - (int) $back['ended'] > 1900 && wp_date('Y-m-d', $started) === wp_date('Y-m-d', (int) $back['ended'])) {
                return $back;
            }
        }
        $device = self::DEVICES[self::pick_index(self::DEVICES)];
        $place  = self::PLACES[self::pick_index(self::PLACES)];
        return array(
            'v'      => bin2hex(random_bytes(8)),
            'ua'     => $device[1],
            'screen' => $device[2],
            'cc'     => $place[1],
            'lang'   => $place[2],
            'tz'     => $place[3],
            'ended'  => $started,
        );
    }

    /**
     * Events on a page.
     *
     * @param string              $path Page.
     * @param array<string,mixed> $who  From visitor().
     * @return array<int,array<string,mixed>> Event hits without page id and path.
     */
    private static function events($path, array $who) {
        $out = array();
        if (strpos($path, '/blog/') === 0 && $path !== '/blog/' && self::chance(0.025)) {
            $out[] = array('t' => 'e', 'n' => 'Newsletter signup', 'd' => array('form' => self::chance(0.6) ? 'inline' : 'footer'));
        }
        if (strpos($path, '/docs/') === 0 && self::chance(0.06)) {
            $out[] = array('t' => 'e', 'n' => 'Download', 'd' => array('file' => self::chance(0.5) ? 'quick-start.pdf' : 'seo-checklist.pdf'));
        }
        if (self::chance(0.04)) {
            $out[] = array('t' => 'e', 'n' => 'Outbound link', 'd' => array('url' => self::chance(0.6) ? 'https://wordpress.org/plugins/' : 'https://developer.wordpress.org/'));
        }
        if ($path === '/contact/' && self::chance(0.25)) {
            $out[] = array('t' => 'e', 'n' => 'Contact form');
        }
        if ($path === '/checkout/order-received/') {
            $plan     = self::PLANS[self::pick_index(self::PLANS)];
            $currency = $who['cc'] === 'GB' ? 'GBP' : (in_array($who['cc'], array('DE', 'FR', 'NL', 'ES'), true) ? 'EUR' : 'USD');
            $out[]    = array('t' => 'e', 'n' => 'Purchase', 'd' => array('plan' => $plan[1]), 'rv' => array('a' => $plan[2][$currency], 'c' => $currency));
        }
        return $out;
    }

    /**
     * Clicks and form submits on a page, as autocapture sends them: the
     * links and forms behind its events, and clicks on the page's
     * elements (some dead, some on affiliate links, with their event).
     *
     * @param string                         $path   Page.
     * @param array<int,array<string,mixed>> $events The page's events, from events().
     * @return array<int,array<string,mixed>> Click, form and event hits without page id.
     */
    private static function clicks($path, array $events) {
        $out = array();
        foreach ($events as $event) {
            if ($event['n'] === 'Outbound link') {
                $out[] = array('t' => 'c', 's' => 'a', 'l' => $event['d']['url'] === 'https://wordpress.org/plugins/' ? 'WordPress plugins' : 'Developer resources', 'h' => rtrim($event['d']['url'], '/') . '/', 'f' => 2);
            } elseif ($event['n'] === 'Download') {
                $out[] = array('t' => 'c', 's' => 'a.wp-block-file__button', 'l' => 'Download', 'h' => '/wp-content/uploads/' . $event['d']['file'], 'f' => 8);
            } elseif ($event['n'] === 'Newsletter signup') {
                $out[] = array('t' => 'f', 's' => 'form.newsletter-form', 'l' => 'newsletter', 'h' => '/', 'n' => 1);
            } elseif ($event['n'] === 'Contact form') {
                $out[] = array('t' => 'f', 's' => 'form.wpcf7-form', 'l' => 'contact', 'h' => '/contact/', 'n' => 4);
            }
        }
        foreach (self::CLICKS as $prefix => $items) {
            if ($prefix !== '' && strpos($path, $prefix) !== 0) {
                continue;
            }
            foreach ($items as $item) {
                if (!self::chance($item[4])) {
                    continue;
                }
                $out[] = array('t' => 'c', 's' => $item[0], 'l' => $item[1], 'h' => $item[2], 'f' => $item[3]);
                if ($item[3] & 4) {
                    $out[] = array('t' => 'e', 'n' => 'Affiliate link', 'd' => array('url' => $item[2]));
                }
            }
        }
        return $out;
    }

    /**
     * A line as the collector writes it.
     *
     * @param int                 $ts  Unix time.
     * @param array<string,mixed> $who From visitor().
     * @param array<string,mixed> $hit Hit.
     * @return array<string,mixed>
     */
    private static function line($ts, array $who, array $hit) {
        return array(
            'ts' => (int) $ts,
            'v'  => $who['v'],
            'ua' => $who['ua'],
            'ip' => '',
            'cc' => $who['cc'],
            'h'  => self::$host,
            'e'  => array($hit),
        );
    }

    /**
     * True with a probability.
     *
     * @param float $p 0 to 1.
     * @return bool
     */
    private static function chance($p) {
        return self::unit() < $p;
    }

    /**
     * A random number from 0 up to 1.
     *
     * @return float
     */
    private static function unit() {
        return random_int(0, 999999) / 1000000;
    }

    /**
     * A key of a list of weights (key => weight, or key => [weight, …]).
     *
     * @param array<int|string,mixed> $items Items.
     * @return string
     */
    private static function pick_key(array $items) {
        $total = 0.0;
        foreach ($items as $item) {
            $total += is_array($item) ? $item[0] : $item;
        }
        $r = self::unit() * $total;
        foreach ($items as $key => $item) {
            $r -= is_array($item) ? $item[0] : $item;
            if ($r <= 0) {
                return (string) $key;
            }
        }
        return (string) array_key_last($items);
    }

    /**
     * An index of a list of [weight, …].
     *
     * @param array<int,array<int,mixed>> $items Items.
     * @return int
     */
    private static function pick_index(array $items) {
        return (int) self::pick_key($items);
    }

    /**
     * Take the lock; false when another request holds it.
     *
     * @return bool
     */
    private static function lock() {
        // add_option() inserts only when the option is missing: one winner.
        if (add_option(self::LOCK_OPTION, time(), '', false)) {
            return true;
        }
        $held = (int) get_option(self::LOCK_OPTION, 0);
        if ($held < time() - self::LOCK_TTL) {
            delete_option(self::LOCK_OPTION);
            return add_option(self::LOCK_OPTION, time(), '', false);
        }
        return false;
    }

    /**
     * Give the lock back.
     */
    private static function unlock() {
        delete_option(self::LOCK_OPTION);
    }
}
