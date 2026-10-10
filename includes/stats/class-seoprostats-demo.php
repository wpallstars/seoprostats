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
 * last three months, as kept by default) clicks and form submits,
 * changes for the markers (SEOProStats_Changes), and Search Console days
 * (the gsc_* tables, final days only) for the search report. While it
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

    /** Files loaded when needed. */
    const GOALS_FILE   = '/class-seoprostats-goals.php';
    const CHANGES_FILE = '/class-seoprostats-changes.php';

    /** Where blog posts live. */
    const BLOG = '/blog/';

    /**
     * Clicks by page (path prefix; '' on every page): selector, label,
     * target, flags (1 dead, 4 affiliate), chance per pageview.
     */
    const CLICKS = array(
        ''           => array(
            array('a.custom-logo-link', 'Home', '/', 0, 0.04),
            array('a.wp-block-navigation-item__content', 'Pricing', '/pricing/', 0, 0.05), // NOSONAR: demo data: paths and labels are made-up content, not names.
            array('a.wp-block-navigation-item__content', 'Docs', '/docs/', 0, 0.03), // NOSONAR: demo data: paths and labels are made-up content, not names.
            array('button.wp-block-navigation__responsive-container-open', 'Open menu', '', 0, 0.04),
        ),
        '/blog/'     => array(
            array('a.wp-block-button__link', 'Try it free', '/pricing/', 0, 0.05),
            array('img.wp-image', 'Rankings before and after an update', '', 1, 0.03),
            array('a', 'Our recommended host', '/go/hosting/', 4, 0.02),
        ),
        '/pricing/'  => array(
            array('a.wp-block-button__link', 'Buy Pro', '/shop/pro-licence/', 0, 0.12), // NOSONAR: demo data: paths and labels are made-up content, not names.
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
        'google'     => array(30, 'https://www.google.com/', '', 'search'),
        'bing'       => array(4, 'https://www.bing.com/', '', 'search'),
        'duckduckgo' => array(2.5, 'https://duckduckgo.com/', '', 'search'),
        'ecosia'     => array(0.5, 'https://www.ecosia.org/', '', 'search'),
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
        'partner'    => array(2, 'https://example.org/best-wordpress-plugins/', '', 'product'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'wordpress'  => array(1.5, 'https://wordpress.org/support/', '', 'docs'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'github'     => array(1, 'https://github.com/', '', 'docs'),
    );

    /** Landing pages by kind: path => weight. Search follows where SEARCH_QUERIES' clicks go (Search → Content). */
    const LANDINGS = array(
        'home'     => array('/' => 10, '/about/' => 1),
        'search'   => array('/blog/core-web-vitals-explained/' => 6, '/blog/how-to-read-search-rankings/' => 5, '/blog/privacy-friendly-analytics/' => 5, '/blog/speed-up-wordpress/' => 4.5, '/blog/what-changed-after-an-update/' => 2.5, '/' => 6, '/features/' => 2, '/pricing/' => 1.6, '/docs/' => 0.8, '/docs/getting-started/' => 0.8, '/docs/faq/' => 1, '/shop/pro-licence/' => 0.3),
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
        // Last, so the other pages keep their post IDs: published lately, never shown in search (indexation).
        '/docs/indexing-checklist/'            => array('page', 9003, 0),
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

    /**
     * Search queries the demo site shows for (Search → Rankings): query =>
     * [page, impressions a day at the end of the period, position then,
     * places it has climbed over a year, and optionally a second page that
     * gets a fifth of the impressions, two places lower, and the days ago
     * from which the second page leads instead]. Queries with a second page
     * are Opportunities' overlapping pages; the one whose second page
     * takes over is shown as switched.
     */
    const SEARCH_QUERIES = array(
        'core web vitals'                  => array('/blog/core-web-vitals-explained/', 420, 7.8, 6, '/blog/speed-up-wordpress/'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'what are core web vitals'         => array('/blog/core-web-vitals-explained/', 160, 4.2, 3),
        'inp vs fid'                       => array('/blog/core-web-vitals-explained/', 70, 2.6, 1),
        'how to read search rankings'      => array('/blog/how-to-read-search-rankings/', 110, 2.1, 2), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'average position search console'  => array('/blog/how-to-read-search-rankings/', 240, 5.4, 4),
        'why did my rankings drop'         => array('/blog/what-changed-after-an-update/', 300, 9.6, 5, '/blog/how-to-read-search-rankings/'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'google core update traffic drop'  => array('/blog/what-changed-after-an-update/', 190, 12.4, 2),
        'privacy friendly analytics'       => array('/blog/privacy-friendly-analytics/', 260, 6.3, 4, '/'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'cookieless analytics wordpress'   => array('/blog/privacy-friendly-analytics/', 130, 3.9, 3),
        'gdpr analytics without consent'   => array('/blog/privacy-friendly-analytics/', 90, 11.2, 1),
        'speed up wordpress'               => array('/blog/speed-up-wordpress/', 520, 14.5, 7),
        'wordpress slow admin'             => array('/blog/speed-up-wordpress/', 140, 8.1, 2),
        'reduce ttfb wordpress'            => array('/blog/speed-up-wordpress/', 60, 6.7, 1, '/blog/core-web-vitals-explained/', 40),
        'seo pro stats'                    => array('/', 85, 1.1, 0, '/pricing/'),
        'seo pro stats pricing'            => array('/pricing/', 20, 1.3, 0),
        'wordpress analytics plugin'       => array('/', 380, 16.8, 9, '/features/'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'site statistics plugin'           => array('/features/', 150, 9.4, 4),
        'search console in wordpress'      => array('/features/', 110, 7.2, 3, '/docs/getting-started/'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        'analytics with rankings and traffic' => array('/features/', 45, 4.6, 2),
        'seo pro stats docs'               => array('/docs/', 12, 1.0, 0),
        'connect search console service account' => array('/docs/getting-started/', 55, 3.3, 2),
        'seo pro stats refund'             => array('/docs/faq/', 8, 1.4, 0),
        'analytics plugin licence'         => array('/shop/pro-licence/', 25, 8.8, 1),
    );

    /**
     * What happened to some pages' searches lately (Search →
     * Opportunities, losing clicks): path => [days back it starts, places
     * lower, impressions ×, CTR ×], eased in over four days. One ranks
     * lower after a large edit, one has fewer searches, one is chosen less
     * after its SEO title changed (SEARCH_CHANGES).
     */
    const SEARCH_EVENTS = array(
        '/blog/what-changed-after-an-update/' => array(24, 7.0, 1.0, 1.0),
        '/docs/getting-started/'              => array(21, 0.0, 0.45, 1.0),
        '/features/'                          => array(19, 0.0, 1.0, 0.45),
        '/pricing/'                           => array(23, 0.0, 1.0, 1.5),
    );

    /**
     * Experiments of the demo data (SEOProStats_Experiments), each on a
     * change of CHANGES or SEARCH_CHANGES (its page and kind): path, change
     * kind, name, days per window, measure, direction, threshold, result
     * decided ('' to leave it running), note. The pricing page's new meta
     * description is chosen more (SEARCH_EVENTS), the features page's new
     * SEO title less, the shortened post ranks lower, and the internal
     * links change nothing clear.
     */
    const EXPERIMENTS = array(
        array('/pricing/', 11, 'A meta description with the refund lifts CTR', 14, 'ctr', 'up', 10, 'keep', 'CTR rose well beyond the usual spread of unchanged pages.'),
        array('/features/', 10, 'A shorter SEO title lifts CTR on Features', 14, 'ctr', 'up', 10, 'undo', 'Fewer searchers chose the page; the old title goes back.'),
        array('/blog/what-changed-after-an-update/', 5, 'A tighter post ranks better', 14, 'position', 'up', 1, 'revise', 'It ranks lower without the removed sections; restore the ones searchers wanted.'),
        array('/blog/core-web-vitals-explained/', 6, 'Internal links lift the Core Web Vitals post', 14, 'position', 'up', 1, 'inconclusive', 'No change beyond the usual spread.'),
        array('/blog/how-to-read-search-rankings/', 4, 'A dated title lifts CTR on the rankings guide', 28, 'ctr', 'up', 10, '', ''),
    );

    /** Queries with a weak title or description all along: query => CTR × (Opportunities, low CTR). */
    const SEARCH_LOW_CTR = array(
        'what are core web vitals'    => 0.3,
        'how to read search rankings' => 0.35,
    );

    /**
     * What the demo pages say (query coverage, SEOProStats_Coverage):
     * path => [title, headings, text, focus keywords]. Some queries in
     * SEARCH_QUERIES are left out on purpose, so Missing from the page has
     * rows: inp vs fid, reduce ttfb, slow admin, why rankings drop, gdpr
     * consent, and the licence page's words.
     */
    const PAGE_TEXT = array(
        '/'                                   => array('SEO Pro Stats', array('Private site statistics for WordPress', 'Traffic, rankings and conversions on one timeline'), 'See which pages bring visitors, which searches find them and what changed when numbers move. No cookies.', array('seo pro stats')), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/blog/core-web-vitals-explained/'    => array('Core Web Vitals explained', array('What are Core Web Vitals?', 'Largest Contentful Paint', 'Cumulative Layout Shift'), 'Core Web Vitals measure how fast a page loads, how stable it is and how soon it responds. Here is what each one means and how to improve it.', array('core web vitals')), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/blog/how-to-read-search-rankings/'  => array('How to read search rankings', array('Average position in Search Console', 'Clicks and impressions'), 'Search Console shows an average position for every query and page. This guide explains how to read rankings without being misled by averages.', array('search rankings', 'average position')), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/blog/privacy-friendly-analytics/'   => array('Privacy-friendly analytics', array('Cookieless analytics for WordPress', 'What you can still measure'), 'Analytics without cookies or stored IP addresses still shows where visitors come from and what they read. GDPR friendly by design.', array('privacy friendly analytics')), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/blog/speed-up-wordpress/'           => array('Speed up WordPress', array('Caching', 'Images', 'Fewer plugins'), 'Seven practical steps to speed up WordPress: page caching, smaller images, fewer plugins and a faster host.', array('speed up wordpress')), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/blog/what-changed-after-an-update/' => array('What changed after an update', array('Reading the change log', 'Plugins, themes and settings'), 'When traffic moves after a plugin or theme update, the change log shows what changed and when, next to the chart.', array()), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/features/'                          => array('Features', array('Site statistics plugin', 'Search Console in WordPress', 'Rankings and traffic together'), 'Analytics with rankings and traffic in one place: Search Console data inside WordPress, goals, funnels and a change log.', array('site statistics plugin')),
        '/pricing/'                           => array('Pricing', array('Plans'), 'SEO Pro Stats pricing: one plan for one site, more for agencies.', array()),
        '/docs/'                              => array('Docs', array('Getting started', 'FAQ'), 'SEO Pro Stats docs: install, connect Search Console and read the reports.', array()), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/docs/getting-started/'              => array('Getting started', array('Install the plugin', 'Connect Search Console'), 'Connect Search Console with a service account key: create the service account, add it to the property and paste its key.', array('connect search console')),
        '/docs/faq/'                          => array('FAQ', array('Can I get a refund?'), 'Questions people ask about SEO Pro Stats, such as refunds within 30 days.', array()),
        '/shop/pro-licence/'                  => array('Pro', array('What you get'), 'One year of updates and support for one site.', array()),
        '/docs/indexing-checklist/'           => array('Indexing checklist', array('Before you publish', 'After you publish'), 'A short list to check before and after a page goes live, so search engines can find and show it.', array()),
    );

    /**
     * The demo pages' links to each other (SEOProStats_Links): path =>
     * [path => link text]. The texts are the pages' titles, so the queries
     * left out of PAGE_TEXT stay missing. Nothing links to the update post
     * or the FAQ (orphans); the pricing page, which converts, has few
     * links in; and pages that show for another page's search do
     * not link to it: the features page to the front page, the rankings
     * guide to the update post and the front page to the privacy post.
     */
    const PAGE_LINKS = array(
        '/'                                   => array('/features/' => 'Features', '/pricing/' => 'Pricing', '/blog/core-web-vitals-explained/' => 'Core Web Vitals explained', '/blog/how-to-read-search-rankings/' => 'How to read search rankings', '/docs/' => 'Docs'),
        '/blog/core-web-vitals-explained/'    => array('/blog/speed-up-wordpress/' => 'Speed up WordPress'),
        '/blog/how-to-read-search-rankings/'  => array('/features/' => 'Features'),
        '/blog/privacy-friendly-analytics/'   => array('/features/' => 'Features', '/pricing/' => 'Pricing'),
        '/blog/speed-up-wordpress/'           => array('/blog/core-web-vitals-explained/' => 'Core Web Vitals explained'),
        '/blog/what-changed-after-an-update/' => array('/blog/how-to-read-search-rankings/' => 'How to read search rankings'),
        '/features/'                          => array('/docs/getting-started/' => 'Getting started', '/blog/privacy-friendly-analytics/' => 'Privacy-friendly analytics'),
        '/pricing/'                           => array('/shop/pro-licence/' => 'Pro', '/' => 'SEO Pro Stats'),
        '/docs/'                              => array('/docs/getting-started/' => 'Getting started'),
        '/docs/getting-started/'              => array('/features/' => 'Features', '/docs/' => 'Docs'),
        '/docs/faq/'                          => array('/docs/' => 'Docs'),
        '/shop/pro-licence/'                  => array(),
        '/docs/indexing-checklist/'           => array('/docs/' => 'Docs'),
    );

    /**
     * The demo pages' SEO fields and more of their HTML, for the content
     * audit (SEOProStats_Audit): path => [SEO title, description, HTML
     * added, noindex, canonical]. Their words are the pages' own, so the
     * queries left out of PAGE_TEXT stay missing. Findings: no
     * description and an image without alt text (Core Web Vitals), a long
     * SEO title (rankings guide), an H1 in the text (privacy), a long
     * description (speed), the same SEO title (pricing and the licence)
     * and description (docs and getting started), noindex (docs) and a
     * canonical address elsewhere (FAQ).
     */
    const PAGE_SEO = array(
        '/'                                   => array('SEO Pro Stats', 'Private site statistics for WordPress: see which pages bring visitors and what changed when numbers move.', '', false, ''),
        '/blog/core-web-vitals-explained/'    => array('', '', '<figure><img src="/wp-content/uploads/lcp-chart.png" alt="Largest Contentful Paint chart"></figure><figure><img src="/wp-content/uploads/cls-example.png"></figure>', false, ''),
        '/blog/how-to-read-search-rankings/'  => array('How to read search rankings in Search Console: average position, clicks and impressions', 'This guide explains how to read rankings and average position in Search Console without being misled by averages.', '', false, ''),
        '/blog/privacy-friendly-analytics/'   => array('', 'Analytics without cookies or stored IP addresses still shows where visitors come from and what they read.', '<h1>Privacy-friendly analytics</h1>', false, ''),
        '/blog/speed-up-wordpress/'           => array('', 'Seven practical steps to speed up WordPress: page caching, smaller images, fewer plugins and a faster host, with what each step changes and how to check it on your own site.', '<img src="/wp-content/uploads/caching.png" alt="Page caching settings">', false, ''),
        '/blog/what-changed-after-an-update/' => array('', 'When traffic moves after a plugin or theme update, the change log shows what changed and when.', '', false, ''),
        '/features/'                          => array('Everything SEO Pro Stats does', 'Analytics with rankings and traffic in one place: Search Console data inside WordPress, goals, funnels and a change log.', '', false, ''),
        '/pricing/'                           => array('Pricing | SEO Pro Stats', 'SEO Pro Stats pricing: one plan for one site, more for agencies.', '', false, ''),
        '/docs/'                              => array('', 'SEO Pro Stats docs: install, connect Search Console and read the reports.', '', true, ''),
        '/docs/getting-started/'              => array('', 'SEO Pro Stats docs: install, connect Search Console and read the reports.', '', false, ''),
        '/docs/faq/'                          => array('', 'Questions people ask about SEO Pro Stats, such as refunds within 30 days.', '', false, '/docs/'),
        '/shop/pro-licence/'                  => array('Pricing | SEO Pro Stats', 'One year of updates and support for one site.', '', false, ''),
        '/docs/indexing-checklist/'           => array('Indexing checklist | SEO Pro Stats', 'What to check before and after publishing a page so search engines can find it and show it.', '', false, ''),
    );

    /**
     * When the demo pages were published, in days before the demo is made
     * (indexation, SEOProStats_Indexation): the indexing checklist lately,
     * with no search impressions since; the rest long ago, by their order.
     */
    const PUBLISHED_DAYS = array('/docs/indexing-checklist/' => 45);

    /**
     * The demo's sitemap addresses besides its pages (indexation): path =>
     * [source code (SEOProStats_Indexation::SOURCES), days listed]. None
     * has search impressions: an archive and an author page search never
     * showed, and one listed too lately to tell.
     */
    const SITEMAP = array(
        '/category/news/'         => array(1, 120),
        '/category/licences/'     => array(1, 120),
        '/author/jonas-weber/'    => array(2, 120),
        '/category/performance/'  => array(1, 10),
    );

    /**
     * The demo's search targets (SEOProStats_Targets): query, page meant
     * for it ('' for none chosen yet), priority, status. Two show with
     * another page (the plugin search shows the front page for, the TTFB
     * search the Core Web Vitals post has taken over), three high-priority
     * ones are in striking distance (one with no page chosen), one is won,
     * one retired, one ranks as meant, and one search is not shown yet.
     */
    const TARGETS = array(
        array('wordpress analytics plugin', '/features/', 85, 'targeted'),
        array('reduce ttfb wordpress', '/blog/speed-up-wordpress/', 75, 'targeted'),
        array('privacy friendly analytics', '/blog/privacy-friendly-analytics/', 90, 'targeted'),
        array('site statistics plugin', '', 80, 'candidate'),
        array('cookieless analytics wordpress', '/blog/privacy-friendly-analytics/', 70, 'live'),
        array('why did my rankings drop', '/blog/what-changed-after-an-update/', 60, 'live'),
        array('seo pro stats pricing', '/pricing/', 100, 'won'),
        array('gdpr analytics without consent', '/blog/privacy-friendly-analytics/', 30, 'retired'),
        array('wordpress uptime monitoring', '', 40, 'candidate'),
    );

    /** Search targets made by this version of the demo; older ones are made again. */
    const TARGETS_VERSION = 1;

    /**
     * Backlinks (SEOProStats_Backlinks), as the check would have found
     * them on the demo's referring pages (SOURCES): referring page, the
     * page it links to, anchor text, rel bits (1 nofollow, 2 sponsored,
     * 4 ugc), days since first found, days since lost (0: live). One is
     * new this week and one was lost lately.
     */
    const BACKLINKS = array(
        array('https://example.org/best-wordpress-plugins/', '/features/', 'SEO Pro Stats', 0, 140, 0),
        array('https://example.org/best-wordpress-plugins/', '/pricing/', 'see the pricing', 0, 140, 0),
        array('https://example.org/best-wordpress-plugins/', '/blog/core-web-vitals-explained/', 'Core Web Vitals explained', 0, 35, 0),
        array('https://wordpress.org/support/', '/docs/getting-started/', 'getting started guide', 5, 70, 0),
        array('https://wordpress.org/support/', '/docs/faq/', 'FAQ', 5, 45, 12),
        array('https://github.com/', '/docs/', 'Documentation', 1, 210, 0),
        array('https://news.ycombinator.com/', '/blog/privacy-friendly-analytics/', 'Privacy-friendly analytics without cookies', 1, 22, 0),
        array('https://example.org/speed-guide/', '/blog/speed-up-wordpress/', 'speed up WordPress', 0, 4, 0),
        array('https://example.org/speed-guide/', '/', '[image]', 0, 4, 0),
        array('https://spam-links.example.click/list/', '/pricing/', 'casino payday loans pills', 0, 3, 0),
        array('https://spam-links.example.click/list/', '/features/', 'buy cheap pills', 0, 3, 0),
        array('https://short-lived.example.tk/list/', '/pricing/', 'casino', 0, 5, 2),
    );

    /** Backlinks made by this version of the demo; older ones are made again. */
    const BACKLINKS_VERSION = 2;

    /**
     * Google's URL Inspection of demo pages (SEOProStats_Inspections), as
     * Search Console would give it: path => verdict, coverage state,
     * robots.txt state, indexing state, Google's canonical path ('' none,
     * else Google's choice), the page's own canonical path, rich result
     * type and its issues (message => ERROR or WARNING), days since
     * inspected, days since crawled.
     */
    const INSPECTIONS = array(
        '/'                                   => array('PASS', 'Submitted and indexed', 'ALLOWED', 'INDEXING_ALLOWED', '/', '/', 'Breadcrumbs', array(), 2, 3), // NOSONAR: demo data: paths and labels are made-up content, not names.
        '/features/'                          => array('PASS', 'Submitted and indexed', 'ALLOWED', 'INDEXING_ALLOWED', '/features/', '/features/', 'Breadcrumbs', array(), 3, 6),
        '/pricing/'                           => array('PASS', 'Submitted and indexed', 'ALLOWED', 'INDEXING_ALLOWED', '/pricing/', '/pricing/', 'Product snippets', array('Either "offers", "review", or "aggregateRating" should be specified' => 'ERROR', 'Missing field "brand"' => 'WARNING'), 3, 5),
        '/blog/core-web-vitals-explained/'    => array('PASS', 'Submitted and indexed', 'ALLOWED', 'INDEXING_ALLOWED', '/blog/core-web-vitals-explained/', '/blog/core-web-vitals-explained/', 'Breadcrumbs', array(), 5, 9),
        '/blog/how-to-read-search-rankings/'  => array('PASS', 'Submitted and indexed', 'ALLOWED', 'INDEXING_ALLOWED', '/blog/how-to-read-search-rankings/', '/blog/how-to-read-search-rankings/', '', array(), 6, 12),
        '/blog/what-changed-after-an-update/' => array('PARTIAL', 'Indexed, though blocked by robots.txt', 'DISALLOWED', 'INDEXING_ALLOWED', '', '', '', array(), 4, 0),
        '/docs/'                              => array('NEUTRAL', 'Excluded by ‘noindex’ tag', 'ALLOWED', 'BLOCKED_BY_META_TAG', '', '/docs/', '', array(), 7, 8),
        '/docs/faq/'                          => array('NEUTRAL', 'Alternate page with proper canonical tag', 'ALLOWED', 'INDEXING_ALLOWED', '/docs/', '/docs/', '', array(), 8, 15),
        '/shop/pro-licence/'                  => array('NEUTRAL', 'Duplicate, Google chose different canonical than user', 'ALLOWED', 'INDEXING_ALLOWED', '/pricing/', '/shop/pro-licence/', 'Product snippets', array(), 9, 10),
        '/docs/indexing-checklist/'           => array('NEUTRAL', 'Crawled - currently not indexed', 'ALLOWED', 'INDEXING_ALLOWED', '', '/docs/indexing-checklist/', '', array(), 1, 20),
        '/category/news/'                     => array('NEUTRAL', 'Discovered - currently not indexed', 'ALLOWED', 'INDEXING_ALLOWED', '', '', '', array(), 2, 0),
        '/author/jonas-weber/'                => array('NEUTRAL', 'Crawled - currently not indexed', 'ALLOWED', 'INDEXING_ALLOWED', '', '/author/jonas-weber/', '', array(), 10, 30),
    );

    /**
     * Search Console's sitemaps for the demo property: path under the home
     * address => type, whether an index, days since submitted, days since
     * downloaded, errors, warnings, addresses submitted. The site's own
     * index is read daily; a child has warnings; an old sitemap is stale
     * with an error.
     */
    const SEARCH_SITEMAPS = array(
        '/wp-sitemap.xml'              => array('sitemap', true, 210, 1, 0, 0, 0),
        '/wp-sitemap-posts-page-1.xml' => array('sitemap', false, 210, 1, 0, 2, 13),
        '/old-sitemap.xml'             => array('sitemap', false, 420, 34, 1, 0, 9),
    );

    /** Inspections and sitemaps made by this version of the demo; older ones are made again. */
    const INSPECTIONS_VERSION = 1;

    /** Content audit facts made by this version of the demo; older ones are made again. */
    const AUDIT_VERSION = 2;

    /** Search data made by this version of the demo; older demo search days are made again. */
    const SEARCH_VERSION = 6;

    /**
     * Demo search appearances: share of the site's impressions, CTR as a
     * multiple of the site's, and places from the site's average position.
     * TRANSLATED_RESULT has no name in the dashboard, so it shows how an
     * unknown appearance is labelled.
     */
    const SEARCH_APPEARANCES = array(
        'PRODUCT_SNIPPETS'  => array(0.35, 1.3, -1.6),
        'VIDEO'             => array(0.22, 0.6, 2.4),
        'REVIEW_SNIPPET'    => array(0.18, 1.6, -0.9),
        'FORUMS'            => array(0.08, 0.9, 4.2),
        'TRANSLATED_RESULT' => array(0.03, 0.5, 6.5),
    );

    /** Changes behind SEARCH_EVENTS, as CHANGES. */
    const SEARCH_CHANGES = array(
        array(25, 15, 5, '/blog/what-changed-after-an-update/', 'post', '2400', '1310', array('name' => 'What changed after an update', 'before' => 2400, 'after' => 1310, 'added' => 60, 'removed' => 1150)),
        array(20, 10, 10, '/features/', 'page', 'Features | SEO Pro Stats', 'Everything SEO Pro Stats does', array('name' => 'Features', 'field' => '_yoast_wpseo_title')),
    );

    /** Search Console countries (alpha-3) and devices of the demo's searches: weight. */
    const SEARCH_COUNTRIES = array('usa' => 36, 'gbr' => 17, 'ind' => 9, 'deu' => 8, 'can' => 6, 'aus' => 5, 'fra' => 5, 'nld' => 3, 'esp' => 3, 'bra' => 3, 'jpn' => 2, 'pol' => 2, 'ita' => 1);
    const SEARCH_DEVICES   = array(1 => 52, 2 => 44, 3 => 4);

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
        array('name' => 'Newsletter signup', 'kind' => 'event', 'match' => 'Newsletter signup'), // NOSONAR: demo data: paths and labels are made-up content, not names.
        array('name' => 'Contact form sent', 'kind' => 'event', 'match' => 'Contact form'), // NOSONAR: demo data: paths and labels are made-up content, not names.
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
        array(2, 9, 23, '/shop/pro-licence/', 'product', '99', '79', array('name' => 'Pro licence', 'currency' => 'USD', 'field' => 'regular')), // NOSONAR: demo data: paths and labels are made-up content, not names.
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

    /**
     * The demo's A/B tests (SEOProStats_AB_Tests): id, page, name, goal
     * (a GOALS name), days it has run, the page a variant can send more
     * visits on to, the click inside the test (a CLICKS label, shown as
     * the variant's label), and its variants: weight, slug, label, chance
     * of going on to that page. The headline has a clear winner, the
     * button no clear difference, and the price is too early to call.
     */
    const AB_TESTS = array(
        array('demohead01', '/', 'Home page headline', 'Viewed pricing', 42, '/pricing/', '', array(
            array(50, 'variant-a', 'Private site statistics', 0.0),
            array(50, 'variant-b', 'See what moved your traffic', 0.07),
        )),
        array('demobtn001', '/pricing/', 'Buy button text', 'Purchase', 28, '', 'Buy Pro', array(
            array(50, 'variant-a', 'Buy Pro', 0.0),
            array(50, 'variant-b', 'Start today', 0.0),
        )),
        array('demoprice1', '/shop/pro-licence/', 'Price per year or per month', 'Purchase', 3, '', '', array(
            array(50, 'variant-a', '$99 a year', 0.0),
            array(50, 'variant-b', '$8.25 a month', 0.0),
        )),
    );

    /** @var array<string,int> When each demo A/B test started (Unix), for the visits being made. */
    private static $ab = array();

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
            'made'     => 0,
            'changes'  => 1,
            'search_v' => self::SEARCH_VERSION,
        ), false);
        self::run(static function () {
            self::ab_tests();
        });
        return true;
    }

    /**
     * Write the demo's A/B tests (AB_TESTS) to its registry, with their
     * start markers, and keep when each started; on the demo tables
     * (called inside run()), once per demo data. A test starts its days
     * before now, but not before the visits still to be made (demo data
     * made before A/B tests gets them from now on).
     */
    private static function ab_tests() {
        global $wpdb;
        require_once __DIR__ . self::GOALS_FILE;
        require_once __DIR__ . self::CHANGES_FILE;
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return;
        }
        $state = self::state();
        $first = isset($state['upto']) ? (int) $state['upto'] : time();
        $goals = array();
        foreach (SEOProStats_Goals::goals() as $goal) {
            $goals[$goal['name']] = $goal['id'];
        }
        $starts = array();
        foreach (self::AB_TESTS as $test) {
            list($id, $path, $name, $goal, $days, , , $variants) = $test;
            $start = max($first, time() - (int) $days * DAY_IN_SECONDS);
            $list  = array();
            foreach ($variants as $variant) {
                $list[] = array('slug' => $variant[1], 'label' => $variant[2], 'weight' => $variant[0]);
            }
            $page = self::page($path);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the demo's own table by its primary key.
            $wpdb->replace(SEOProStats_Schema::table('ab_tests'), array(
                'test_id'  => $id,
                'post_id'  => $page ? $page['post_id'] : 0,
                'name'     => $name,
                'variants' => (string) wp_json_encode($list),
                'goals'    => (string) wp_json_encode(isset($goals[$goal]) ? array($goals[$goal]) : array()),
                'status'   => SEOProStats_AB_Tests::STATUSES['running'],
                'winner'   => '',
                'created'  => $start,
                'updated'  => time(),
                'started'  => $start,
                'ended'    => 0,
                'removed'  => 0,
            ), array('%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d'));
            SEOProStats_Changes::write(array(
                'ts'          => $start,
                'kind'        => SEOProStats_Changes::AB_STARTED,
                'path'        => $path,
                'object_type' => 'ab_test',
                'object_id'   => $page ? $page['post_id'] : 0,
                'old'         => '',
                'new'         => $id,
                'meta'        => array('name' => $name, 'test' => $id),
                'source'      => 1,
                'user_id'     => 0,
            ));
            $starts[$id] = $start;
        }
        $state       = self::state();
        $state['ab'] = $starts;
        update_option(self::OPTION, $state, false);
    }

    /**
     * Which demo A/B tests the pages of a visit show (a variant per page
     * load, by weight) and their effect: a variant can send the visit on
     * to its test's page next. Pages and their context are added to.
     *
     * @param string[]                       $paths   The visit's pages, in order.
     * @param array<int,array<string,mixed>> $context Their context, in the same order.
     * @param int                            $started When the visit started.
     * @return array<int,array<string,array{0:int,1:string,2:string,3:float}>> Page index => test id => variant.
     */
    private static function ab_shown(array &$paths, array &$context, $started) {
        $shown = array();
        // $paths grows as variants send visits on, so test each index as it comes.
        for ($i = 0; isset($paths[$i]) && self::$ab; $i++) {
            $shown[$i] = array();
            foreach (self::AB_TESTS as $test) {
                list($id, $path, , , , $next, , $variants) = $test;
                // Not on a search results or not found page at the same address.
                if ($paths[$i] !== $path || !empty($context[$i]['q']) || !empty($context[$i]['n']) || !isset(self::$ab[$id]) || $started < self::$ab[$id]) {
                    continue;
                }
                $variant        = $variants[self::pick_index($variants)];
                $shown[$i][$id] = $variant;
                if ($next !== '' && $variant[3] > 0 && (!isset($paths[$i + 1]) || $paths[$i + 1] !== $next) && self::chance($variant[3])) {
                    array_splice($paths, $i + 1, 0, array($next));
                    array_splice($context, $i + 1, 0, array(array()));
                }
            }
        }
        return $shown;
    }

    /**
     * The demo page a post ID stands for (page()'s IDs): its path and
     * title.
     *
     * @param int $post_id Demo post ID.
     * @return array{path:string,title:string}|null
     */
    public static function post($post_id) {
        $paths = array_keys(self::CONTENT);
        $i     = (int) $post_id - 1000;
        if ($i < 0 || !isset($paths[$i])) {
            return null;
        }
        $path = $paths[$i];
        return array(
            'path'  => $path,
            'title' => isset(self::PAGE_TEXT[$path]) ? self::PAGE_TEXT[$path][0] : ucfirst(trim(str_replace('-', ' ', $path), '/')),
        );
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
        if (self::ready()) {
            self::upgrade();
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
     * Bring demo data made by an older version up to date, once each:
     * example goals, A/B tests, changes and search days.
     */
    private static function upgrade() {
        // Demo data made before goals existed gets the examples once.
        require_once __DIR__ . self::GOALS_FILE;
        $none = self::run(static function () {
            return get_option(SEOProStats_Schema::option(SEOProStats_Goals::GOALS_OPTION)) === false;
        });
        if ($none) {
            self::examples();
        }
        // Demo data made before A/B tests gets them once, from now on.
        if (!isset(self::state()['ab'])) {
            self::run(static function () {
                self::ab_tests();
            });
        }
        // Demo data made before the change log gets its changes once.
        $state = self::state();
        $wrote = empty($state['changes']);
        if ($wrote) {
            $state['changes'] = 1;
            update_option(self::OPTION, $state, false);
            self::changes(self::from($state));
        }
        // Demo search days of an older version are made again (from
        // today, as the changes behind them), next time it catches up.
        $state   = self::state();
        $version = isset($state['search_v']) ? (int) $state['search_v'] : 1;
        if ($version >= self::SEARCH_VERSION) {
            return;
        }
        $state['search_v'] = self::SEARCH_VERSION;
        $state['search']   = '';
        $state['bing']     = '';
        update_option(self::OPTION, $state, false);
        // SEARCH_CHANGES came with version 2.
        if (!$wrote && $version < 2) {
            self::changes(self::from($state), true);
        }
    }

    /**
     * Start of the demo period, or now when none is stored.
     *
     * @param array<string,mixed> $state Progress.
     * @return int Unix time.
     */
    private static function from(array $state) {
        return isset($state['from']) ? (int) $state['from'] : time();
    }

    /**
     * Remove the demo tables and their progress; nothing of live data.
     */
    public static function remove() {
        require_once __DIR__ . self::GOALS_FILE;
        require_once __DIR__ . '/class-seoprostats-audit.php';
        require_once __DIR__ . '/class-seoprostats-indexation.php';
        require_once __DIR__ . '/class-seoprostats-targets.php';
        require_once __DIR__ . '/class-seoprostats-backlinks.php';
        require_once __DIR__ . '/class-seoprostats-inspections.php';
        self::run(static function () {
            SEOProStats_Schema::drop();
            SEOProStats_Goals::forget();
            SEOProStats_Audit::reset();
            SEOProStats_Indexation::reset();
            SEOProStats_Targets::reset();
            SEOProStats_Backlinks::reset();
            SEOProStats_Inspections::reset();
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
        require_once __DIR__ . self::GOALS_FILE;
        self::run(static function () {
            SEOProStats_Goals::replace(self::GOALS, self::FUNNELS);
        });
    }

    /**
     * Give the demo data its changes (the markers) over its period, on the
     * demo tables. Once per demo data: start(), or refresh() for demo data
     * made before the change log.
     *
     * @param int  $from   Start of the period.
     * @param bool $search Only SEARCH_CHANGES (demo data made before them).
     */
    private static function changes($from, $search = false) {
        require_once __DIR__ . self::CHANGES_FILE;
        self::run(static function () use ($from, $search) {
            if (!SEOProStats_Schema::maybe_upgrade()) {
                return;
            }
            foreach (self::change_rows((int) $from, time(), $search) as $row) {
                SEOProStats_Changes::write($row);
            }
        });
    }

    /**
     * The demo changes between two times: CHANGES, plus plugin updates
     * every 16 days, a WordPress update every 63 days and a post edited
     * every 13 days.
     *
     * @param int  $from   Start.
     * @param int  $to     End.
     * @param bool $search Only SEARCH_CHANGES.
     * @return array<int,array<string,mixed>> Rows for SEOProStats_Changes::write().
     */
    private static function change_rows($from, $to, $search = false) {
        $today = new DateTimeImmutable('today', wp_timezone());
        $list  = $search ? self::SEARCH_CHANGES : array_merge(self::CHANGES, self::SEARCH_CHANGES);
        if (!$search) {
            $span = (int) ceil(max(0, $to - $from) / DAY_IN_SECONDS);
            $list = array_merge($list, self::plugin_updates($span), self::core_updates($span), self::post_edits($span), self::search_updates($span, $today));
        }
        $rows = array();
        foreach ($list as $change) {
            $row = self::change_row($today, $change);
            if ($row['ts'] >= $from && $row['ts'] <= $to) {
                $rows[] = $row;
            }
        }
        if ($search) {
            return $rows;
        }
        usort($rows, static function ($x, $y) {
            return $x['ts'] - $y['ts'];
        });
        return $rows;
    }

    /**
     * One demo change as a row for SEOProStats_Changes::write().
     *
     * @param DateTimeImmutable  $today  Today (site time zone).
     * @param array<int,mixed>   $change Days ago, hour, kind, path, object type, old, new, meta (as CHANGES lists them).
     * @return array<string,mixed>
     */
    private static function change_row(DateTimeImmutable $today, array $change) {
        list($days, $hour, $kind, $path, $type, $old, $new, $meta) = $change;
        $page = $path !== '' ? self::page($path) : null;
        return array(
            'ts'          => self::add_days($today, -(int) $days)->setTime((int) $hour, ($kind * 7) % 60)->getTimestamp(),
            'kind'        => (int) $kind,
            'path'        => (string) $path,
            'object_type' => (string) $type,
            'object_id'   => $page ? $page['post_id'] : 0,
            'old'         => (string) $old,
            'new'         => (string) $new,
            'meta'        => $meta,
            'source'      => self::change_source((int) $kind),
            'user_id'     => 0,
        );
    }

    /**
     * Who logs a demo change: updates WordPress (4), notes people (6),
     * search updates the import (5); the rest the plugin (1).
     *
     * @param int $kind Change kind.
     * @return int Source.
     */
    private static function change_source($kind) {
        if (in_array($kind, array(41, 47), true)) {
            return 4;
        }
        if ($kind === SEOProStats_Changes::NOTE) {
            return 6;
        }
        return $kind === SEOProStats_Changes::SEARCH_UPDATE ? 5 : 1;
    }

    /**
     * A demo plugin updated every 16 days, the newest first.
     *
     * @param int $span Days of the period.
     * @return array<int,array<int,mixed>> Changes, as CHANGES lists them.
     */
    private static function plugin_updates($span) {
        $list = array();
        $n    = 0;
        for ($days = $span; $days >= 1; $days -= 16, $n++) {
            list($name, $file, $first) = self::DEMO_PLUGINS[$n % count(self::DEMO_PLUGINS)];
            $minor  = $first[1] + intdiv($n, count(self::DEMO_PLUGINS));
            $list[] = array($days, 3, 41, '', 'plugin', $first[0] . '.' . $minor . '.0', $first[0] . '.' . ($minor + 1) . '.0', array('name' => $name, 'file' => $file));
        }
        return $list;
    }

    /**
     * A WordPress update every 63 days.
     *
     * @param int $span Days of the period.
     * @return array<int,array<int,mixed>> Changes, as CHANGES lists them.
     */
    private static function core_updates($span) {
        $list = array();
        $n    = 0;
        for ($days = $span - 20; $days >= 1; $days -= 63, $n++) {
            $list[] = array($days, 4, 47, '', 'core', '6.' . (5 + $n), '6.' . (6 + $n), array('name' => 'WordPress'));
        }
        return $list;
    }

    /**
     * A blog post edited every 13 days, in turn.
     *
     * @param int $span Days of the period.
     * @return array<int,array<int,mixed>> Changes, as CHANGES lists them.
     */
    private static function post_edits($span) {
        $posts = array_values(array_filter(array_keys(self::CONTENT), static function ($path) {
            return strpos($path, self::BLOG) === 0;
        }));
        $list  = array();
        $n     = 0;
        for ($days = $span - 5; $days >= 1; $days -= 13, $n++) {
            $path   = $posts[$n % count($posts)];
            $before = 900 + ($n * 37) % 600;
            $added  = 40 + ($n * 53) % 260;
            $gone   = 10 + ($n * 29) % 90;
            $name   = ucfirst(str_replace(array('blog/', '-'), array('', ' '), trim($path, '/')));
            $list[] = array($days, 11, 5, $path, 'post', (string) $before, (string) ($before + $added - $gone), array('name' => $name, 'before' => $before, 'after' => $before + $added - $gone, 'added' => $added, 'removed' => $gone));
        }
        return $list;
    }

    /**
     * Made-up search engine updates: a core update every 95 days rolling
     * out over 13, a spam update every 70 over 2.
     *
     * @param int               $span  Days of the period.
     * @param DateTimeImmutable $today Today (site time zone).
     * @return array<int,array<int,mixed>> Changes, as CHANGES lists them.
     */
    private static function search_updates($span, DateTimeImmutable $today) {
        $list = array();
        foreach (array(array('core', 95, 30, 13), array('spam', 70, 12, 2)) as $update) {
            list($type, $every, $first, $length) = $update;
            $n = 0;
            for ($days = $span - $first; $days >= 1; $days -= $every, $n++) {
                $ended  = $days - $length;
                $list[] = array($days, 16, SEOProStats_Changes::SEARCH_UPDATE, '', 'google', $type, 'demo-' . $type . '-' . $n, array(
                    /* translators: %d: a made-up update's number, for the demo data */
                    'name'   => sprintf($type === 'core' ? __('Demo core update %d', 'seoprostats') : __('Demo spam update %d', 'seoprostats'), $n + 1),
                    'engine' => 'Google',
                    'url'    => 'https://status.search.google.com/',
                    'ended'  => $ended > 0 ? gmdate('c', self::add_days($today, -$ended)->setTime(12, 0)->getTimestamp()) : '',
                ));
            }
        }
        return $list;
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

        $state    = self::state();
        $upto     = isset($state['upto']) ? (int) $state['upto'] : time();
        $now      = time();
        self::$ab = isset($state['ab']) && is_array($state['ab']) ? array_map('intval', $state['ab']) : array();
        $done     = self::visits_until($now, $upto, $start, $budget, $state)
            && self::search_days($start, $budget, $state)
            && self::summaries($start, $budget);
        if ($done && $state['status'] !== 'ready') {
            $state['status'] = 'ready';
            $state['made']   = time();
            update_option(self::OPTION, $state, false);
        }
        // New data: cached answers for the demo data go.
        update_option(SEOProStats_Schema::option(SEOProStats_Collection::PROCESS_OPTION), array('last' => time()), false);
        if ($done) {
            self::extras($state);
        }
    }

    /**
     * Make visits in windows of up to a day, from where they were made up
     * to now, within the time budget.
     *
     * @param int                 $now    Unix time now.
     * @param int                 $upto   Where visits were made up to.
     * @param float               $start  microtime(true) when the work began.
     * @param int                 $budget Seconds.
     * @param array<string,mixed> $state  Progress (upto); updated.
     * @return bool Whether visits are made up to now.
     */
    private static function visits_until($now, $upto, $start, $budget, array &$state) {
        while ($upto < $now && SEOProStats_Feature::more_time($start, $budget)) {
            $to = min($now, $upto + DAY_IN_SECONDS);
            SEOProStats_Processor::ingest(self::lines($upto, $to));
            $upto          = $to;
            $state['upto'] = $upto;
            update_option(self::OPTION, $state, false);
        }
        return $upto >= $now;
    }

    /**
     * Summarise finished days within the time budget.
     *
     * @param float $start  microtime(true) when the work began.
     * @param int   $budget Seconds.
     * @return bool Whether every summary is made.
     */
    private static function summaries($start, $budget) {
        // The summaries' own budget is longer: end it with this one.
        $rollup_start = $start - max(0, SEOProStats_Rollup::BUDGET - $budget);
        while (SEOProStats_Rollup::due() !== null) {
            if (!SEOProStats_Feature::more_time($start, $budget) || !SEOProStats_Rollup::catch_up($rollup_start)) {
                return false;
            }
        }
        // Demo data made before search landings were summarised gets them here.
        return !SEOProStats_Feature::more_time($start, $budget) || SEOProStats_Rollup::refill($rollup_start);
    }

    /**
     * With every visit, search day and summary made: the experiments
     * (once), the audit, targets, backlinks and inspections (again when
     * their version changes), then the decision queue (once).
     *
     * @param array<string,mixed> $state Progress.
     */
    private static function extras(array $state) {
        // With every search day made, the experiments can be measured and decided (once).
        if (empty($state['experiments'])) {
            $state['experiments'] = 1;
            update_option(self::OPTION, $state, false);
            self::experiments();
        }
        // The content audit's facts of the demo pages.
        self::again('audit', self::AUDIT_VERSION, static function () {
            self::audit();
        });
        self::again('targets', self::TARGETS_VERSION, static function () {
            self::targets();
        });
        self::again('backlinks', self::BACKLINKS_VERSION, static function () {
            self::backlinks();
        });
        // Google's URL Inspection and Search Console's sitemaps.
        self::again('inspections', self::INSPECTIONS_VERSION, static function () {
            self::inspections();
        });
        // Then the decision queue: one item accepted, one done (once).
        $state = self::state();
        if (empty($state['queue'])) {
            $state['queue'] = 1;
            update_option(self::OPTION, $state, false);
            self::queue();
        }
    }

    /**
     * Make a part of the demo data again when its stored version is older.
     *
     * @param string   $key     Progress key.
     * @param int      $version Current version.
     * @param callable $make    Makes it.
     */
    private static function again($key, $version, callable $make) {
        $state = self::state();
        if (!empty($state[$key]) && (int) $state[$key] >= $version) {
            return;
        }
        $state[$key] = $version;
        update_option(self::OPTION, $state, false);
        $make();
    }

    /**
     * Write the content audit's facts of the demo pages (PAGE_TEXT and
     * PAGE_SEO), as the audit reads a live post, and the demo's sitemap
     * addresses (SITEMAP); on the demo tables (called inside run()).
     */
    private static function audit() {
        require_once __DIR__ . '/class-seoprostats-audit.php';
        require_once __DIR__ . '/class-seoprostats-indexation.php';
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return;
        }
        $facts = array();
        $n     = 0;
        $start = time();
        foreach (array_keys(self::PAGE_TEXT) as $path) {
            $text = self::text($path);
            $page = self::page($path);
            if ($text && $page) {
                $facts[$path] = SEOProStats_Audit::facts($text, $path) + array(
                    'post_id'   => $page['post_id'],
                    // Edited on different days over the last two months.
                    'modified'  => time() - (3 + 5 * $n) * DAY_IN_SECONDS,
                    // Published lately (PUBLISHED_DAYS), or over a year ago, by their order.
                    'published' => time() - (isset(self::PUBLISHED_DAYS[$path]) ? self::PUBLISHED_DAYS[$path] : 380 + 9 * $n) * DAY_IN_SECONDS,
                );
                ++$n;
            }
        }
        SEOProStats_Audit::write($facts);
        // Every demo page's links and published times are read at once.
        SEOProStats_Audit::touch($start);
        // The sitemap addresses, each first listed some days ago.
        $paths = array();
        $first = array();
        foreach (self::SITEMAP as $path => $info) {
            $paths[$path] = (int) $info[0];
            $first[$path] = time() - (int) $info[1] * DAY_IN_SECONDS;
        }
        SEOProStats_Indexation::write_sitemap($paths, true, true, $first);
    }

    /**
     * Write the demo's search targets (TARGETS), replacing any; on the demo
     * tables (called inside run()).
     */
    private static function targets() {
        require_once __DIR__ . '/class-seoprostats-targets.php';
        $rows = array();
        foreach (self::TARGETS as $target) {
            $rows[] = array('query' => $target[0], 'page' => $target[1], 'priority' => (string) $target[2], 'status' => $target[3]);
        }
        SEOProStats_Targets::import($rows, 'demo', true);
    }

    /**
     * Write the demo's backlinks (BACKLINKS); on the demo tables (called
     * inside run()).
     */
    private static function backlinks() {
        require_once __DIR__ . '/class-seoprostats-backlinks.php';
        $now   = time();
        $links = array();
        foreach (self::BACKLINKS as $link) {
            $lost    = $link[5] ? $now - (int) $link[5] * DAY_IN_SECONDS : 0;
            $links[] = array(
                'url'        => $link[0],
                'path'       => $link[1],
                'anchor'     => $link[2],
                'rel'        => (int) $link[3],
                'first_seen' => $now - (int) $link[4] * DAY_IN_SECONDS,
                'last_seen'  => $lost ? max($now - (int) $link[4] * DAY_IN_SECONDS, $lost - 7 * DAY_IN_SECONDS) : $now - DAY_IN_SECONDS,
                'lost'       => $lost,
                'facts'      => strpos($link[0], 'spam-links.example.click') !== false ? array('outbound' => 300, 'language' => 'zz', 'link_list' => true) : array(),
            );
        }
        SEOProStats_Backlinks::write_links($links);
        self::backlink_changes();
    }

    /**
     * The demo backlinks' changes on the timeline, as the daily check
     * writes them: one per referring site and day.
     */
    private static function backlink_changes() {
        require_once __DIR__ . self::CHANGES_FILE;
        $by = array();
        foreach (self::BACKLINKS as $link) {
            $host  = (string) wp_parse_url($link[0], PHP_URL_HOST);
            $lost  = (int) $link[5];
            $days  = $lost ? $lost : (int) $link[4];
            $kind  = $lost ? SEOProStats_Changes::BACKLINK_LOST : SEOProStats_Changes::BACKLINK_NEW;
            $key   = $kind . ' ' . $host . ' ' . $days;
            $by[$key] = isset($by[$key]) ? $by[$key] : array('kind' => $kind, 'host' => $host, 'days' => $days, 'links' => array());
            $by[$key]['links'][] = array('from' => $link[0], 'to' => $link[1], 'anchor' => $lost ? '' : $link[2]);
        }
        $today = new DateTimeImmutable('today', wp_timezone());
        foreach ($by as $change) {
            $paths = array_values(array_unique(array_column($change['links'], 'to')));
            SEOProStats_Changes::write(array(
                'ts'          => self::add_days($today, -(int) $change['days'])->setTime(6, 30)->getTimestamp(),
                'kind'        => $change['kind'],
                'path'        => count($paths) === 1 ? (string) $paths[0] : '',
                'object_type' => 'backlink',
                'object_id'   => 0,
                'old'         => '',
                'new'         => $change['host'],
                'meta'        => array('host' => $change['host'], 'count' => count($change['links']), 'links' => $change['links']),
                'source'      => 4,
                'user_id'     => 0,
            ));
        }
    }

    /**
     * Write the demo's URL inspections (INSPECTIONS) in Google's own
     * format, as the import would keep them, one verdict change on the
     * timeline, and Search Console's sitemaps (SEARCH_SITEMAPS); on the
     * demo tables (called inside run()).
     */
    private static function inspections() {
        require_once __DIR__ . '/class-seoprostats-inspections.php';
        require_once __DIR__ . self::CHANGES_FILE;
        if (!SEOProStats_Schema::maybe_upgrade()) {
            return;
        }
        $now = time();
        foreach (self::INSPECTIONS as $path => $one) {
            SEOProStats_Inspections::store(0, $path, self::inspection($path, $one, $now), $now - $one[8] * DAY_IN_SECONDS);
        }
        // The duplicate shop page was indexed until Google chose the pricing page as canonical.
        $today = new DateTimeImmutable('today', wp_timezone());
        SEOProStats_Changes::write(array(
            'ts'          => $today->modify('-9 days')->setTime(5, 10)->getTimestamp(),
            'kind'        => SEOProStats_Changes::INDEX_STATUS,
            'path'        => '/shop/pro-licence/',
            'object_type' => 'inspection',
            'object_id'   => 0,
            'old'         => 'Submitted and indexed',
            'new'         => 'Duplicate, Google chose different canonical than user',
            'meta'        => array('name' => '/shop/pro-licence/', 'verdict' => 'NEUTRAL', 'verdict_before' => 'PASS'),
            'source'      => 4,
            'user_id'     => 0,
        ));
        $sitemaps = array();
        foreach (self::SEARCH_SITEMAPS as $path => $one) {
            $sitemaps[] = array(
                'path'       => home_url($path),
                'type'       => $one[0],
                'index'      => $one[1],
                'pending'    => false,
                'submitted'  => $now - $one[2] * DAY_IN_SECONDS,
                'downloaded' => $now - $one[3] * DAY_IN_SECONDS,
                'errors'     => $one[4],
                'warnings'   => $one[5],
                'contents'   => $one[6] ? array(array('type' => 'web', 'submitted' => $one[6])) : array(),
            );
        }
        SEOProStats_Inspections::write_sitemaps($sitemaps, $now - HOUR_IN_SECONDS);
        SEOProStats_Inspections::touch();
    }

    /**
     * One demo URL inspection (INSPECTIONS) in Google's own format.
     *
     * @param string           $path Page path.
     * @param array<int,mixed> $one  Its INSPECTIONS entry.
     * @param int              $now  Unix time now.
     * @return array<string,mixed>
     */
    private static function inspection($path, array $one, $now) {
        list($verdict, $coverage, $robots, $indexing, $google, $user, $rich_type, $issues, , $crawled) = $one;
        $index = array(
            'verdict'        => $verdict,
            'coverageState'  => $coverage,
            'robotsTxtState' => $robots,
            'indexingState'  => $indexing,
            'pageFetchState' => self::fetch_state($robots, $crawled),
            'crawledAs'      => 'MOBILE',
            'sitemap'        => array(self::sitemap($path)),
            'referringUrls'  => $path === '/' ? array() : array(home_url('/')),
        );
        if ($crawled) {
            $index['lastCrawlTime'] = gmdate('Y-m-d\TH:i:s\Z', $now - $crawled * DAY_IN_SECONDS);
        }
        if ($google !== '') {
            $index['googleCanonical'] = home_url($google);
        }
        if ($user !== '') {
            $index['userCanonical'] = home_url($user);
        }
        $result = array(
            'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode(home_url('/')) . '&id=' . rawurlencode(md5($path)), // NOSONAR nosemgrep: a made-up ID in demo data, not security.
            'indexStatusResult'    => $index,
        );
        if ($rich_type !== '') {
            $result['richResultsResult'] = self::rich_results($rich_type, $issues);
        }
        return $result;
    }

    /**
     * The sitemap a demo inspection says lists the page: the index for
     * category and author archives, the posts and pages sitemap otherwise.
     *
     * @param string $path Page path.
     * @return string
     */
    private static function sitemap($path) {
        $archive = strpos($path, '/category/') === 0 || strpos($path, '/author/') === 0;
        return home_url($archive ? '/wp-sitemap.xml' : '/wp-sitemap-posts-page-1.xml');
    }

    /**
     * A demo inspection's rich results, in Google's own format: one item
     * of the type with its issues; failed when any is an error.
     *
     * @param string               $rich_type Rich result type.
     * @param array<string,string> $issues    Message => severity.
     * @return array<string,mixed>
     */
    private static function rich_results($rich_type, array $issues) {
        $items = array();
        foreach ($issues as $message => $severity) {
            $items[] = array('issueMessage' => $message, 'severity' => $severity);
        }
        return array(
            'verdict'       => in_array('ERROR', $issues, true) ? 'FAIL' : 'PASS',
            'detectedItems' => array(array('richResultType' => $rich_type, 'items' => array(array('name' => 'Unnamed item', 'issues' => $items)))),
        );
    }

    /**
     * Act on the demo's decision queue (SEOProStats_Queue): accept its top
     * item and mark the best one on another page done, which opens a
     * running experiment there; on the demo tables, once.
     */
    private static function queue() {
        require_once __DIR__ . '/class-seoprostats-queue.php';
        $req = SEOProStats_Query::request(array('range' => '90d', 'limit' => 20));
        if (is_wp_error($req)) {
            return;
        }
        $list = SEOProStats_Queue::report($req, 'google', 'new');
        if (is_wp_error($list) || !$list['items']) {
            return;
        }
        $top = $list['items'][0];
        SEOProStats_Queue::update($top['key'], array('action' => 'accept', 'note' => __('Next up: the title is written.', 'seoprostats')), $req);
        foreach (array_slice($list['items'], 1) as $item) {
            if ($item['path'] !== $top['path']) {
                SEOProStats_Queue::update($item['key'], array('action' => 'done'), $req);
                return;
            }
        }
    }

    /**
     * Record the demo's experiments (EXPERIMENTS) on its changes, and
     * decide those with a result; on the demo tables, once, when the
     * search days are made.
     */
    private static function experiments() {
        require_once __DIR__ . self::CHANGES_FILE;
        require_once __DIR__ . '/class-seoprostats-experiments.php';
        $state = self::state();
        $from  = isset($state['from']) ? (int) $state['from'] : time() - self::DAYS * DAY_IN_SECONDS;
        $found = array();
        foreach (SEOProStats_Changes::between($from, time() + 1) as $change) {
            $found[$change['path'] . "\t" . $change['kind'] . "\t" . $change['new']] = (int) $change['id'];
        }
        $rows = array_merge(self::CHANGES, self::SEARCH_CHANGES);
        foreach (self::EXPERIMENTS as $experiment) {
            list($path, $kind, $name, $days, $metric, $direction, $threshold, $result, $note) = $experiment;
            $id = 0;
            foreach ($rows as $row) {
                $key = $path . "\t" . SEOProStats_Changes::KINDS[$kind][0] . "\t" . $row[6];
                if ($row[3] === $path && (int) $row[2] === $kind && isset($found[$key])) {
                    $id = $found[$key];
                }
            }
            if (!$id) {
                continue;
            }
            $added = SEOProStats_Experiments::add(array(
                'name'      => $name,
                'change'    => $id,
                'days'      => $days,
                'metric'    => $metric,
                'direction' => $direction,
                'threshold' => $threshold,
            ));
            // Decided only once its data is in; else it stays running.
            if ($result !== '' && is_array($added) && is_array($added['measurement']) && $added['measurement']['state'] === 'ready') {
                SEOProStats_Experiments::update((int) $added['id'], array('action' => 'decide', 'result' => $result, 'note' => $note));
            }
        }
    }

    /** Search data is final this many days after the day, as from Google. */
    const SEARCH_LAG = 3;

    /**
     * Make the search days (the gsc_* tables, as an import writes them)
     * from the start of the period to the newest final day, within the
     * time budget. Demo data made before search data gets them here too.
     *
     * @param float               $start  microtime(true) when the work began.
     * @param int                 $budget Seconds.
     * @param array<string,mixed> $state  Progress (search: the last day made); updated.
     * @return bool Whether every final day is made.
     */
    private static function search_days($start, $budget, array &$state) {
        require_once SEOPROSTATS_DIR . 'includes/stats/class-seoprostats-search-import.php';
        $today  = new DateTimeImmutable('today', wp_timezone());
        $final  = self::add_days($today, -self::SEARCH_LAG)->format('Y-m-d');
        $ids    = null;
        $google = self::make_days('search', $final, $start, $budget, $state, $ids, static function (DateTimeImmutable $day, array $ids) use ($today) {
            return self::search_day($day, $today, $ids);
        });
        if (!$google) {
            return false;
        }

        // Bing's days, through the end of its newest week given (it comes
        // some days after the week, as from Bing); demo data made before
        // Bing gets them here too.
        $final = self::add_days($today, -(self::SEARCH_LAG + 6));
        $final = self::add_days($final, -((((int) $final->format('N') - self::BING_WEEK_END + 7) % 7)))->format('Y-m-d');
        return self::make_days('bing', $final, $start, $budget, $state, $ids, static function (DateTimeImmutable $day, array $ids) use ($today) {
            return self::bing_day($day, $today, $ids);
        });
    }

    /**
     * Make one engine's search days, from the day after the last one made
     * (or the period's first day) to the final day, within the time budget.
     *
     * @param string                                                        $key    Progress key (the last day made).
     * @param string                                                        $final  The last day to make (Y-m-d).
     * @param float                                                         $start  microtime(true) when the work began.
     * @param int                                                           $budget Seconds.
     * @param array<string,mixed>                                           $state  Progress; updated.
     * @param array{paths:array<string,int>,queries:array<string,int>}|null $ids    Dictionary IDs, looked up once when needed.
     * @param callable(DateTimeImmutable, array{paths:array<string,int>,queries:array<string,int>}): bool $make Writes a day; false when it could not.
     * @return bool Whether every day is made.
     */
    private static function make_days($key, $final, $start, $budget, array &$state, &$ids, callable $make) {
        $tz    = wp_timezone();
        $first = (new DateTimeImmutable('@' . self::from($state)))->setTimezone($tz)->format('Y-m-d');
        $day   = self::resume_day(isset($state[$key]) ? (string) $state[$key] : '', $first, $tz);
        while ($day->format('Y-m-d') <= $final) {
            if (!SEOProStats_Feature::more_time($start, $budget)) {
                return false;
            }
            if ($ids === null) {
                $ids = self::search_ids();
            }
            if (!$make($day, $ids)) {
                return false;
            }
            $state[$key] = $day->format('Y-m-d');
            update_option(self::OPTION, $state, false);
            $day = self::add_days($day, 1);
        }
        return true;
    }

    /**
     * The day to make next: the day after the last one made, or the first
     * day when none (or one before it) was made.
     *
     * @param string       $made  The last day made (Y-m-d), or ''.
     * @param string       $first The first day (Y-m-d).
     * @param DateTimeZone $tz    Site time zone.
     * @return DateTimeImmutable
     */
    private static function resume_day($made, $first, DateTimeZone $tz) {
        if ($made !== '' && $made >= $first) {
            return self::add_days(new DateTimeImmutable($made, $tz), 1);
        }
        return new DateTimeImmutable($first, $tz);
    }

    /**
     * A day some days later (or earlier, when negative).
     *
     * @param DateTimeImmutable $day  Day.
     * @param int               $days Days to add.
     * @return DateTimeImmutable
     */
    private static function add_days(DateTimeImmutable $day, $days) {
        return $day->modify(sprintf('%+d days', (int) $days));
    }

    /** The weekday Bing's demo weeks end on (ISO-8601: 4 is Thursday). */
    const BING_WEEK_END = 4;

    /**
     * Dictionary IDs of the demo's search pages and queries.
     *
     * @return array{paths:array<string,int>,queries:array<string,int>}
     */
    private static function search_ids() {
        return array(
            'paths'   => SEOProStats_Dict::ids(SEOProStats_Schema::DICT_PATH, array_unique(array_merge(array_column(self::SEARCH_QUERIES, 0), array_filter(array_column(self::SEARCH_QUERIES, 4))))),
            'queries' => SEOProStats_Dict::ids(SEOProStats_Schema::DICT_QUERY, array_keys(self::SEARCH_QUERIES)),
        );
    }

    /**
     * One demo Bing day, as the Bing import writes it: the site's clicks
     * and impressions (no device or country), and on the last day of a
     * week, the week's pages, queries and pages with their queries. Bing
     * is the demo's Google searches at about an eighth of the size, a
     * little lower down.
     *
     * @param DateTimeImmutable                                       $day   The day (site time zone).
     * @param DateTimeImmutable                                       $today Today.
     * @param array{paths:array<string,int>,queries:array<string,int>} $ids   Dictionary IDs.
     * @return bool Whether it was written.
     */
    private static function bing_day(DateTimeImmutable $day, DateTimeImmutable $today, array $ids) {
        $out  = array('totals' => array());
        $site = array(0, '', 0, 0, 0);
        foreach (self::search_rows($day, $today, $ids)['totals'] as $row) {
            $site[2] += $row[2];
            $site[3] += $row[3];
            $site[4] += $row[4];
        }
        $row = self::bing_row($site, 2);
        if ($row !== null) {
            $out['totals']["0\t"] = $row;
        }
        if ((int) $day->format('N') === self::BING_WEEK_END) {
            $out = array_merge($out, self::bing_week($day, $today, $ids));
        }
        return self::replace_day(SEOProStats_Schema::ENGINE_BING, $day->format('Y-m-d'), $out);
    }

    /**
     * One row's keys and figures at Bing's size: clicks, impressions,
     * pos_impr (+0.4 places); null when under one impression.
     *
     * @param array<int,mixed> $row Keys, then clicks, impressions, pos_impr.
     * @param int              $n   How many keys.
     * @return array<int,mixed>|null
     */
    private static function bing_row(array $row, $n) {
        $impr = (int) round($row[$n + 1] * 0.12);
        if ($impr < 1) {
            return null;
        }
        $position = $row[$n + 1] ? $row[$n + 2] / $row[$n + 1] : 0;
        return array_merge(array_slice($row, 0, $n), array(min($impr, (int) round($row[$n] * 0.11)), $impr, (int) round(($position + 40) * $impr)));
    }

    /**
     * A Bing week's pages, queries and pages with their queries: the
     * Google rows of the week to the day, summed, at Bing's size.
     *
     * @param DateTimeImmutable                                       $day   The week's last day.
     * @param DateTimeImmutable                                       $today Today.
     * @param array{paths:array<string,int>,queries:array<string,int>} $ids   Dictionary IDs.
     * @return array<string,array<string,array<int,int|string>>> Kind => key => row.
     */
    private static function bing_week(DateTimeImmutable $day, DateTimeImmutable $today, array $ids) {
        $week = array('pages' => array(), 'queries' => array(), 'pairs' => array());
        for ($n = 0; $n < 7; $n++) {
            $rows = self::search_rows($day->modify("-$n days"), $today, $ids);
            foreach ($week as $kind => $sums) {
                $week[$kind] = self::add_sums($sums, $rows[$kind], $kind === 'pairs' ? 2 : 1);
            }
        }
        $out = array();
        foreach ($week as $kind => $sums) {
            $out[$kind] = array();
            foreach ($sums as $id => $r) {
                $scaled = self::bing_row($r, $kind === 'pairs' ? 2 : 1);
                if ($scaled !== null) {
                    $out[$kind][$id] = $scaled;
                }
            }
        }
        return $out;
    }

    /**
     * Add rows' clicks, impressions and pos_impr to sums by key.
     *
     * @param array<string,array<int,mixed>> $sums Key => keys, then clicks, impressions, pos_impr.
     * @param array<string,array<int,mixed>> $rows The same, to add.
     * @param int                            $keys How many keys a row starts with.
     * @return array<string,array<int,mixed>>
     */
    private static function add_sums(array $sums, array $rows, $keys) {
        foreach ($rows as $id => $r) {
            if (!isset($sums[$id])) {
                $sums[$id] = array_merge(array_slice($r, 0, $keys), array(0, 0, 0));
            }
            for ($k = 0; $k < 3; $k++) {
                $sums[$id][$keys + $k] += $r[$keys + $k];
            }
        }
        return $sums;
    }

    /**
     * Replace one engine's day in the search tables, every kind together.
     *
     * @param int                                                $engine Engine.
     * @param string                                             $date   Day (Y-m-d).
     * @param array<string,array<string,array<int,int|string>>> $out    Kind (SEOProStats_Search_Import::TABLES) => rows.
     * @return bool Whether it was written.
     */
    private static function replace_day($engine, $date, array $out) {
        global $wpdb;
        $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one demo day's rows replaced together.
        foreach ($out as $kind => $rows) {
            $table = SEOProStats_Schema::table(SEOProStats_Search_Import::TABLES[$kind]);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own demo table, one day by its primary key.
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE engine = %d AND day = %s', $table, $engine, $date));
            if ($deleted === false || SEOProStats_Search_Import::insert($table, $kind, $engine, $date, 0, $rows) === false) {
                $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
                return false;
            }
        }
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ends the transaction above.
        return true;
    }

    /**
     * One demo search day: each query's impressions grow with the site
     * and drop at weekends, its position climbs over the year, and clicks
     * follow the position. Pages and the site's totals add searches too
     * rare to list, as Search Console's do. The same day always gets the
     * same numbers.
     *
     * @param DateTimeImmutable                                       $day   The day (site time zone).
     * @param DateTimeImmutable                                       $today Today.
     * @param array{paths:array<string,int>,queries:array<string,int>} $ids   Dictionary IDs.
     * @return bool Whether it was written.
     */
    private static function search_day(DateTimeImmutable $day, DateTimeImmutable $today, array $ids) {
        $date = $day->format('Y-m-d');
        $rows = self::search_rows($day, $today, $ids);
        $total = array(0, 0, 0);
        foreach ($rows['totals'] as $row) {
            for ($n = 0; $n < 3; $n++) {
                $total[$n] += (int) $row[$n + 2];
            }
        }
        // Each appearance's share of impressions, CTR against the site's and
        // places from the site's position, so they differ as real ones do.
        // Positions here are × 100, as pos_impr stores them.
        $site_ctr       = $total[1] ? $total[0] / $total[1] : 0;
        $site_position  = $total[1] ? $total[2] / $total[1] : 0;
        $appearance_ids = SEOProStats_Dict::ids(SEOProStats_Schema::DICT_APPEARANCE, array_keys(self::SEARCH_APPEARANCES));
        foreach (self::SEARCH_APPEARANCES as $value => $shape) {
            $impressions = (int) round($total[1] * $shape[0] * (0.8 + 0.4 * self::noise($date . $value)));
            if ($impressions < 1) {
                continue;
            }
            $clicks   = min($impressions, (int) round($impressions * $site_ctr * $shape[1] * (0.9 + 0.2 * self::noise($date . $value . 'ctr'))));
            $position = max(100.0, $site_position + 100 * $shape[2]);
            $rows['appearance'][$value] = array($appearance_ids[SEOProStats_Dict::clean($value)], $clicks, $impressions, (int) round($position * $impressions));
        }
        // Every table, in its order (search_rows() has one list per table).
        $out = array();
        foreach (array_keys(SEOProStats_Search_Import::TABLES) as $kind) {
            $out[$kind] = $rows[$kind];
        }
        return self::replace_day(SEOProStats_Schema::ENGINE_GOOGLE, $date, $out);
    }

    /**
     * One demo Google search day's rows, as search_day() writes them.
     *
     * @param DateTimeImmutable                                       $day   The day (site time zone).
     * @param DateTimeImmutable                                       $today Today.
     * @param array{paths:array<string,int>,queries:array<string,int>} $ids   Dictionary IDs.
     * @return array<string,array<string,array<int,int|string>>> Kind => key => [keys…, clicks, impressions, pos_impr].
     */
    private static function search_rows(DateTimeImmutable $day, DateTimeImmutable $today, array $ids) {
        $date  = $day->format('Y-m-d');
        $ago   = (int) $day->diff($today)->days;
        $scale = exp(-$ago / 420) * ((int) $day->format('N') >= 6 ? 0.7 : 1.0) * (1 + 0.08 * sin(2 * M_PI * ((int) $day->format('z') - 80) / 365));
        $rows  = array_fill_keys(array_keys(SEOProStats_Search_Import::TABLES), array());
        $sum   = array(0, 0, 0);
        foreach (self::SEARCH_QUERIES as $query => $info) {
            $query_id = isset($ids['queries'][SEOProStats_Dict::clean($query)]) ? (int) $ids['queries'][SEOProStats_Dict::clean($query)] : 0;
            if (!$query_id) {
                continue;
            }
            foreach (self::query_pages($info, $ago) as $page) {
                $figures = self::page_figures($query, $info, $page, $ids, $date, $ago, $scale);
                if ($figures === null) {
                    continue;
                }
                list($path_id, $clicks, $impr, $pos_impr) = $figures;
                self::add_row($rows, 'pairs', array($path_id, $query_id), array($clicks, $impr, $pos_impr));
                self::add_row($rows, 'queries', array($query_id), array($clicks, $impr, $pos_impr));
                self::add_row($rows, 'pages', array($path_id), array($clicks, $impr, $pos_impr));
                $sum[0] += $clicks;
                $sum[1] += $impr;
                $sum[2] += $pos_impr;
            }
        }
        // Searches too rare to list: about a sixth more on each page, a
        // little further down. Rows: path_id, clicks, impressions, pos_impr.
        foreach ($rows['pages'] as &$row) {
            $row[1] = (int) round($row[1] * 1.15);
            $row[2] = (int) round($row[2] * 1.18);
            $row[3] = (int) round($row[3] * 1.18 * 1.08);
        }
        unset($row);
        self::site_rows($rows, $sum, $date);
        return $rows;
    }

    /**
     * Add one search row's figures to a table's rows by its keys.
     *
     * @param array<string,array<string,array<int,mixed>>> $rows    Kind => key => [keys…, clicks, impressions, pos_impr]; added to.
     * @param string                                        $kind    Table kind.
     * @param array<int,int|string>                         $keys    The row's keys.
     * @param array{0:int,1:int,2:int}                      $figures Clicks, impressions, pos_impr.
     */
    private static function add_row(array &$rows, $kind, array $keys, array $figures) {
        $id = implode("\t", $keys);
        if (!isset($rows[$kind][$id])) {
            $rows[$kind][$id] = array_merge($keys, array(0, 0, 0));
        }
        $n                        = count($keys);
        $rows[$kind][$id][$n]     += $figures[0];
        $rows[$kind][$id][$n + 1] += $figures[1];
        $rows[$kind][$id][$n + 2] += $figures[2];
    }

    /**
     * The pages a demo search query shows: its page, and a second one
     * lower down (or leading, once it has taken over).
     *
     * @param array<int,mixed> $info The query's SEARCH_QUERIES entry.
     * @param int              $ago  Days before today.
     * @return array<int,array{0:string,1:float,2:float}> Path, share of impressions, places lower.
     */
    private static function query_pages(array $info, $ago) {
        if (empty($info[4])) {
            return array(array($info[0], 1.0, 0.0));
        }
        if (!empty($info[5]) && $ago < (int) $info[5]) {
            // The second page has taken over: it leads, the first follows lower.
            return array(array($info[0], 0.3, 2.0), array($info[4], 1.0, 0.0));
        }
        return array(array($info[0], 1.0, 0.0), array($info[4], 0.2, 2.0));
    }

    /**
     * One query's figures on one page for a day: impressions grow with
     * the site, the position climbs over the year, clicks follow the
     * position; null when the page is unknown or has no impressions.
     *
     * @param string                                                  $query Query.
     * @param array<int,mixed>                                        $info  Its SEARCH_QUERIES entry.
     * @param array{0:string,1:float,2:float}                         $page  From query_pages().
     * @param array{paths:array<string,int>,queries:array<string,int>} $ids   Dictionary IDs.
     * @param string                                                  $date  Day (Y-m-d).
     * @param int                                                     $ago   Days before today.
     * @param float                                                   $scale The day's size.
     * @return array{0:int,1:int,2:int,3:int}|null Path ID, clicks, impressions, pos_impr.
     */
    private static function page_figures($query, array $info, array $page, array $ids, $date, $ago, $scale) {
        list($path, $share, $lower) = $page;
        $path_id = isset($ids['paths'][SEOProStats_Dict::clean($path)]) ? (int) $ids['paths'][SEOProStats_Dict::clean($path)] : 0;
        $noise   = self::noise($date . $query . $path);
        // A recent event on the page (SEARCH_EVENTS), eased in over four days.
        $event   = isset(self::SEARCH_EVENTS[$path]) ? self::SEARCH_EVENTS[$path] : array(0, 0.0, 1.0, 1.0);
        $ease    = max(0.0, min(1.0, ($event[0] - $ago) / 4));
        $impr    = (int) round($info[1] * $share * $scale * (0.75 + 0.5 * $noise) * (1 + ($event[2] - 1) * $ease));
        if (!$path_id || $impr < 1) {
            return null;
        }
        $position = max(1.0, $info[2] + $lower + $info[3] * min(1.0, $ago / 365) + 1.6 * (self::noise($query . $date) - 0.5) + $event[1] * $ease);
        $weak     = $path === $info[0] && isset(self::SEARCH_LOW_CTR[$query]) ? self::SEARCH_LOW_CTR[$query] : 1.0;
        $ctr      = min(0.6, 0.32 / pow($position, 1.15)) * (0.85 + 0.3 * self::noise($date . $path . $query)) * (1 + ($event[3] - 1) * $ease) * $weak;
        return array($path_id, (int) round($impr * $ctr), $impr, (int) round($position * $impr * 100));
    }

    /**
     * The site by device and country, from the listed searches and a
     * quarter more unlisted, a little further down the results.
     *
     * @param array<string,array<string,array<int,mixed>>> $rows Kind => key => row; totals added.
     * @param array{0:int,1:int,2:int}                      $sum  The listed searches' clicks, impressions, pos_impr.
     * @param string                                        $date Day (Y-m-d).
     */
    private static function site_rows(array &$rows, array $sum, $date) {
        $weights = array_sum(self::SEARCH_COUNTRIES) * array_sum(self::SEARCH_DEVICES);
        // Average position × 100, as pos_impr holds it.
        $average = $sum[1] ? $sum[2] / $sum[1] : 0;
        foreach (self::SEARCH_DEVICES as $device => $device_weight) {
            foreach (self::SEARCH_COUNTRIES as $country => $country_weight) {
                $share  = $device_weight * $country_weight / $weights * (0.85 + 0.3 * self::noise($date . $country . $device));
                $impr   = (int) round($sum[1] * 1.25 * $share);
                if ($impr < 1) {
                    continue;
                }
                // Mobile searchers click a little less; desktop ranks a
                // little higher; each country ranks a little differently.
                $local    = self::noise($country);
                $position = $average * 1.08 * ($device === 1 ? 0.95 : 1.04) * (0.85 + 0.3 * $local);
                $clicks   = (int) round($sum[0] * 1.2 * $share * ($device === 2 ? 0.9 : 1.08) * (1.15 - 0.3 * $local));
                self::add_row($rows, 'totals', array($device, $country), array(min($clicks, $impr), $impr, (int) round($position * $impr)));
            }
        }
    }

    /**
     * A number from 0 up to 1 that is always the same for a text.
     *
     * @param string $text Text.
     * @return float
     */
    private static function noise($text) {
        return (crc32('seoprostats-demo-search-' . $text) % 10007) / 10007;
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
     * What a demo page says, as SEOProStats_Coverage::text_of_post() reads
     * a live one; null for pages without text.
     *
     * @param string $path Page path.
     * @return array<string,mixed>|null
     */
    public static function text($path) {
        if (!isset(self::PAGE_TEXT[$path])) {
            return null;
        }
        list($title, $headings, $body, $focus) = self::PAGE_TEXT[$path];
        // Every page with text has its SEO fields (PHPStan checks the keys match).
        list($seo_title, $description, $more, $noindex, $canonical) = self::PAGE_SEO[$path];
        $html = (string) $more;
        foreach ($headings as $heading) {
            $html .= '<h2>' . esc_html($heading) . '</h2>';
        }
        // Every page with text has its links (PHPStan checks the keys match).
        $links = array();
        foreach (self::PAGE_LINKS[$path] as $to => $label) {
            $links[] = '<a href="' . esc_attr((string) $to) . '">' . esc_html((string) $label) . '</a>';
        }
        return array(
            'source'         => 'demo',
            'title'          => $title,
            'content'        => $html . '<p>' . esc_html($body) . '</p>' . ($links ? '<p>' . implode(' · ', $links) . '</p>' : ''),
            'excerpt'        => '',
            'plugin'         => $focus ? 'demo' : '',
            'seo_title'      => (string) $seo_title,
            'seo_title_vars' => false,
            'description'    => (string) $description,
            'focus'          => array_map(static function ($keyword) {
                return array('keyword' => $keyword, 'source' => 'demo');
            }, $focus),
            'noindex'        => (bool) $noindex,
            'canonical'      => (string) $canonical,
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
            $time  = (new DateTimeImmutable('@' . $a))->setTimezone($tz);
            $lines = array_merge($lines, self::hour_lines($a, $b, $time, $now));
        }
        usort($lines, static function ($x, $y) {
            return $x['ts'] - $y['ts'];
        });
        return $lines;
    }

    /**
     * The lines of the visits that start in [a, b), within one hour: as
     * many as the day's shape and the hour give; hits after now left out.
     *
     * @param int               $a    Unix time.
     * @param int               $b    Unix time, at most an hour on.
     * @param DateTimeImmutable $time $a, site-local.
     * @param int               $now  Unix time now.
     * @return array<int,array<string,mixed>>
     */
    private static function hour_lines($a, $b, DateTimeImmutable $time, $now) {
        $day   = self::day_shape($time, $now);
        $rate  = $day['visits'] * self::HOURS[(int) $time->format('G')] / array_sum(self::HOURS);
        $want  = $rate * ($b - $a) / HOUR_IN_SECONDS;
        $n     = (int) floor($want) + (self::chance($want - floor($want)) ? 1 : 0);
        $lines = array();
        for ($i = 0; $i < $n; $i++) {
            $source = $day['spike'] !== '' && self::chance($day['share']) ? $day['spike'] : self::pick_key(self::SOURCES);
            foreach (self::visit(random_int($a, max($a, $b - 1)), $source, $time) as $line) {
                if ($line['ts'] <= $now) {
                    $lines[] = $line;
                }
            }
        }
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
        $who     = self::visitor($started);
        $paths   = self::visit_paths($landing);
        $context = self::visit_context($paths, $landing);
        $shown   = self::ab_shown($paths, $context, $started);

        $lines  = array();
        $ts     = $started;
        $id     = bin2hex(random_bytes(6));
        $clicks = $started >= time() - self::CLICK_DAYS * DAY_IN_SECONDS;
        foreach ($paths as $seq => $path) {
            $pkey  = bin2hex(random_bytes(8));
            $tests = isset($shown[$seq]) ? $shown[$seq] : array();
            $hit   = self::page_hit($path, $pkey, $who, $context[$seq], $tests);
            if ($seq === 0) {
                $hit['u'] .= strtr($query, array('{c}' => strtolower($time->format('F')) . '-update', '{id}' => $id));
                $hit['r']  = $referrer;
            } else {
                $hit['r'] = home_url($paths[$seq - 1]);
            }
            $lines[] = self::line($ts, $who, $hit);

            list($visible, $scrolled) = self::engagement(count($paths) === 1);
            $lines[] = self::line($ts + $visible, $who, array('t' => 'eng', 'p' => $pkey, 's' => $visible * 1000, 'sc' => $scrolled));
            foreach (self::page_events($path, $pkey, $who, $clicks, $tests) as $event) {
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
     * A visit's pages: a landing page of its kind, then pages at random,
     * or on to the checkout from the pricing and shop pages.
     *
     * @param string $landing Key of LANDINGS.
     * @return string[] Paths, in order.
     */
    private static function visit_paths($landing) {
        $paths = array(self::pick_key(self::LANDINGS[$landing]));
        $pages = self::chance(0.46) ? 1 : 2 + min(6, (int) floor(-log(max(1e-6, self::unit())) * 1.6));
        // Until the visit has $pages pages ($paths is a list).
        while (!isset($paths[$pages - 1])) {
            $last = end($paths);
            if (($last === '/pricing/' || $last === '/shop/pro-licence/') && self::chance(0.12)) {
                return self::checkout($paths);
            }
            $next = self::pick_key(self::PAGES);
            if ($next !== $last) {
                $paths[] = $next;
            }
        }
        return $paths;
    }

    /**
     * A visit's pages on to the cart, and some on to the checkout and the
     * order received page.
     *
     * @param string[] $paths Paths so far.
     * @return string[]
     */
    private static function checkout(array $paths) {
        $paths[] = '/cart/';
        if (self::chance(0.7)) {
            $paths[] = '/checkout/';
            if (self::chance(0.6)) {
                $paths[] = '/checkout/order-received/';
            }
        }
        return $paths;
    }

    /**
     * What WordPress would say about a visit's pages: some landings are
     * not found, some visits search the site, a few are logged in.
     *
     * @param string[] $paths   The visit's pages; a not found landing or a site search is put in.
     * @param string   $landing Key of LANDINGS.
     * @return array<int,array<string,mixed>> Context per page, in the same order.
     */
    private static function visit_context(array &$paths, $landing) {
        $context = array_fill(0, count($paths), array());
        if (($landing === 'content' || $landing === 'search') && self::chance(0.03)) {
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
        return $context;
    }

    /**
     * A page's pageview hit, without its referrer.
     *
     * @param string                                          $path    Page.
     * @param string                                          $pkey    Page load ID.
     * @param array<string,mixed>                             $who     From visitor().
     * @param array<string,mixed>                             $context From visit_context().
     * @param array<string,array{0:int,1:string,2:string,3:float}> $tests   Test id => variant shown.
     * @return array<string,mixed>
     */
    private static function page_hit($path, $pkey, array $who, array $context, array $tests) {
        $hit = array('t' => 'pv', 'p' => $pkey, 'u' => $path, 'w' => $who['screen'], 'tz' => $who['tz'], 'l' => $who['lang']);
        if ($context) {
            $hit['x'] = $context;
        }
        if ($tests) {
            $hit['ab'] = array();
            foreach ($tests as $test => $variant) {
                $hit['ab'][] = $test . ':' . $variant[1];
            }
        }
        return $hit;
    }

    /**
     * How long a page was seen and how far it was scrolled; a lone page
     * is now and then left at once.
     *
     * @param bool $single Whether it is the visit's only page.
     * @return array{0:int,1:int} Seconds visible, percent scrolled.
     */
    private static function engagement($single) {
        $quick   = $single && self::chance(0.4);
        $visible = $quick ? random_int(2, 10) : random_int(15, 170);
        return array($visible, $quick ? random_int(0, 30) : random_int(25, 100));
    }

    /**
     * A page's events, and its clicks when they are kept, with the page
     * load ID; a click inside a test shows its variant's label, and says
     * which.
     *
     * @param string                                          $path   Page.
     * @param string                                          $pkey   Page load ID.
     * @param array<string,mixed>                             $who    From visitor().
     * @param bool                                            $clicks Whether clicks are made.
     * @param array<string,array{0:int,1:string,2:string,3:float}> $tests  Test id => variant shown.
     * @return array<int,array<string,mixed>> Hits.
     */
    private static function page_events($path, $pkey, array $who, $clicks, array $tests) {
        $events = self::events($path, $who);
        if ($clicks) {
            $events = array_merge($events, self::clicks($path, $events));
        }
        foreach ($tests as $test => $variant) {
            $events = self::ab_labels($events, $test, $variant);
        }
        foreach ($events as $e => $event) {
            $events[$e]['p'] = $pkey;
            if ($event['t'] === 'e') {
                $events[$e]['u'] = $path;
            }
        }
        return $events;
    }

    /**
     * Clicks on a test's element show the variant's label, and say which.
     *
     * @param array<int,array<string,mixed>>     $events  Hits.
     * @param string                             $test    Test id.
     * @param array{0:int,1:string,2:string,3:float} $variant The variant shown.
     * @return array<int,array<string,mixed>>
     */
    private static function ab_labels(array $events, $test, array $variant) {
        foreach (self::AB_TESTS as $def) {
            if ($def[0] !== $test || $def[6] === '') {
                continue;
            }
            foreach ($events as $e => $event) {
                if ($event['t'] === 'c' && $event['l'] === $def[6]) {
                    $events[$e]['l']  = $variant[2];
                    $events[$e]['ab'] = $test . ':' . $variant[1];
                }
            }
        }
        return $events;
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
        if (strpos($path, self::BLOG) === 0 && $path !== self::BLOG && self::chance(0.025)) {
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
            $out[] = self::purchase($who['cc']);
        }
        return $out;
    }

    /**
     * A purchase event: a plan at random, in the visitor's currency.
     *
     * @param string $country Visitor's country code.
     * @return array<string,mixed> Event hit.
     */
    private static function purchase($country) {
        $plan     = self::PLANS[self::pick_index(self::PLANS)];
        $currency = 'USD';
        if ($country === 'GB') {
            $currency = 'GBP';
        } elseif (in_array($country, array('DE', 'FR', 'NL', 'ES'), true)) {
            $currency = 'EUR';
        }
        return array('t' => 'e', 'n' => 'Purchase', 'd' => array('plan' => $plan[1]), 'rv' => array('a' => $plan[2][$currency], 'c' => $currency));
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
            $click = self::event_click($event);
            if ($click !== null) {
                $out[] = $click;
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
     * The click or form submit behind an event, as autocapture sends it;
     * null for events without one.
     *
     * @param array<string,mixed> $event Event hit, from events().
     * @return array<string,mixed>|null Click or form hit.
     */
    private static function event_click(array $event) {
        switch ($event['n']) {
            case 'Outbound link':
                return array('t' => 'c', 's' => 'a', 'l' => $event['d']['url'] === 'https://wordpress.org/plugins/' ? 'WordPress plugins' : 'Developer resources', 'h' => rtrim($event['d']['url'], '/') . '/', 'f' => 2);
            case 'Download':
                return array('t' => 'c', 's' => 'a.wp-block-file__button', 'l' => 'Download', 'h' => '/wp-content/uploads/' . $event['d']['file'], 'f' => 8);
            case 'Newsletter signup':
                return array('t' => 'f', 's' => 'form.newsletter-form', 'l' => 'newsletter', 'h' => '/', 'n' => 1);
            case 'Contact form':
                return array('t' => 'f', 's' => 'form.wpcf7-form', 'l' => 'contact', 'h' => '/contact/', 'n' => 4);
            default:
                return null;
        }
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
     * A demo inspection's page fetch state.
     *
     * @param string $robots  robots.txt state.
     * @param mixed  $crawled When Google crawled the page (INSPECTIONS); empty when never.
     * @return string
     */
    private static function fetch_state($robots, $crawled) {
        if ($robots === 'DISALLOWED') {
            return 'BLOCKED_ROBOTS_TXT';
        }
        return $crawled ? 'SUCCESSFUL' : 'PAGE_FETCH_STATE_UNSPECIFIED';
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
