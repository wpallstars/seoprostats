=== SEO Pro Stats ===
Contributors: wpallstars
Donate link: https://buymeacoffee.com/marcusquinn
Tags: analytics, statistics, privacy, search console, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Actionable analytics: connect your content to the data that shows you how to grow. Private stats, search rankings and sales, no cookies.

== Description ==

**Actionable analytics for WordPress.** Most statistics plugins count what happened. SEO Pro Stats also shows why, and what to do next: it connects your content to the traffic, search rankings and sales it brings, marks every change made to the site on the same timeline, ranks the search work worth doing, and checks whether each change worked.

= Why SEO Pro Stats =

* **Cause and effect.** Posts edited, SEO titles rewritten, prices changed, plugins updated and Google's search updates are recorded and marked under every chart, so a rise or a drop comes with its likely causes.
* **Content joined to results.** Each page's search clicks and position sit beside the visits it started and the goals they reached.
* **A plan, not just a report.** Search → Plan ranks what to do next from your own data, each item with why, what to do and the clicks it could bring.
* **Proof of what worked.** Experiments compare changed pages with unchanged ones and suggest keep, revise or undo; A/B tests of blocks compare versions of a page live.
* **Private.** Counted in your own database, with no cookies, no stored IP addresses and no queries on visitors' pages.
* **For AI agents too.** Every report through the REST API, WP-CLI (`wp seoprostats`) and, on WordPress 6.9 and later, abilities.

= Statistics =

* **Overview**: visitors, visits, pageviews, bounce rate and time against the previous period, with sources, campaigns, pages, pages not found, site searches, countries on a map, devices and events. Choose any row to filter by it.
* **Goals, Funnels and Properties**: conversions, conversion rate and revenue per currency, steps within a visit, and what was sent with events.
* **Purchases**: paid orders from WooCommerce, Easy Digital Downloads, FluentCart and ThriveCart, with refunds and subscription renewals. No order numbers or customer details are kept.
* **Clicks**: what people click, dead clicks, links followed (affiliate links too), files and forms sent. Never what anyone types.
* **Changes**: a log of what changed on the site (posts, SEO titles, prices, plugins, settings), Google's search updates if you switch them on, and your own notes, marked under every chart.

= Search =

Connect Google Search Console and Bing Webmaster Tools (Settings → Connections) to import clicks, impressions and position by page and query.

* **Rankings**: queries, pages, countries, devices and search appearance, for Google, Bing or both combined.
* **Opportunities**: striking-distance queries, low CTR, pages losing clicks with the likely cause, queries missing from the page and overlapping pages.
* **Audit**: titles, descriptions, headings, thin content, internal links, pages search engines do not show, and Google's sitemaps and URL Inspection.
* **Backlinks**: visits and CSV imports, source facts, explained spam review and local Google disavow files. Nothing submitted.
* **Targets**: the queries you aim for, with research links on four search engines and Keyword Golden Ratio bands.
* **IndexNow**: tell search engines when pages change (opt-in).
* **Plan**: all of the above in one list, best first. Done starts an experiment.
* **Experiments**: before and after a change against unchanged pages, with a suggested keep, revise or undo.
* **A/B tests**: test blocks in the block editor, with results and a winner, without cookies.
* **Search queries panel** in the post editor: the queries a page shows for and which it does not cover yet.

= And =

* **Import** history from Burst Statistics, Koko Analytics, Statify, WP Statistics, Independent Analytics, Slimstat, Matomo for WordPress and Jetpack Stats, then remove what the old plugin left behind (Settings → Import).
* **Shared reports**: a private link for a client, with optional password, expiry and branding.
* **Demo data**: a year of made-up visits to try every report before your site has any.
* **Dark mode** for its screens: Light, Dark or System, for each person.

The full guide is in the Read Me tab (SEO Pro Stats → Settings → Read Me).

= Privacy =

No cookies or browser storage, no stored IP addresses and no visitor identity across days: visits are counted with a hash salted each day. Do Not Track and Global Privacy Control are respected. Clicks and site searches hide email addresses and long numbers, and form values are never recorded. Visitor pages run no database queries; reports use daily summaries.

= External services =

Off by default, except the first:

* **Pages that sent visitors** (Settings → Data, on): once a day, for up to 20 seconds, WP-Cron opens public pages that sent visits, to find their links to this site. The user agent names the plugin and the site; nothing about visitors is sent.
* **Google Search Status Dashboard** (status.search.google.com), when Show search engine updates is on: once a day, Google's public list of ranking updates. Nothing about the site is sent. Other feeds you add are read the same way. Google [terms](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **IndexNow** (api.indexnow.org), when on (Settings → Data): changed page addresses and the site's public key, from cron. Google does not take part. No visitor data. [Terms](https://indexnow.org/terms).
* **SEO Pro Stats sign-in relay** (gsc-oauth.wpallstars.com, run by the plugin's maker), only if you choose Sign in with Google: it passes Google's sign-in answer back to the site's wp-admin, and while imports run the site sends it the stored refresh token about once an hour for a new access token. It stores and logs nothing. [Privacy policy](https://www.wpallstars.com/privacy/).
* **Google Search Console API and sign-in** (searchconsole.googleapis.com, oauth2.googleapis.com), after you connect (disconnecting revokes a sign-in's token): search data by day, page, query, device and country, sitemaps, and URL Inspection of your own pages (200 a day by default). Google [terms](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **Bing Webmaster API** (ssl.bing.com), after you connect with your own API key: the site's search data by day and week. Microsoft [terms](https://www.microsoft.com/servicesagreement) and [privacy statement](https://privacy.microsoft.com/privacystatement).
* **WordPress.com** (public-api.wordpress.com), only while you import Jetpack Stats, through Jetpack's own connection: the site's daily statistics.

Search requests run in WP-Cron, WP-CLI or Import now, never on visitor pages, and send nothing about visitors.

= Built with AI =

Built with [aidevops](https://aidevops.sh), the developer's free, open-source AI harness. Ask it questions about SEO Pro Stats; it reads the plugin's docs and code.

Made from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding), the wpallstars starter plugin.

== Installation ==

1. Install from Plugins → Add New → Upload Plugin, or upload the `seoprostats` folder to `/wp-content/plugins/`, and activate it.
2. Open SEO Pro Stats in the admin menu, under Dashboard. Visits show within a minute or two: open the site logged out, or switch on Demo data on the Overview.
3. Add your goals, connect Google Search Console and Bing Webmaster Tools (Settings → Connections) and import your old statistics (Settings → Import). Then work through Search → Plan.

== Frequently Asked Questions ==

= Does it need a cookie banner? =

It sets no cookies and stores nothing in the browser, so it adds nothing a banner covers. One opt-in setting (show each visit one A/B test variant) uses session storage and may need consent.

= Can I bring my old statistics across? =

Yes. Settings → Import shows what each supported plugin's data would add, imports it in the background without counting a day twice, and Undo removes exactly what was added. Remove leftover data deletes the old plugin's tables and settings once it is inactive; back up first.

= Can AI agents use it? =

Yes, with an Application Password: the REST API, `wp seoprostats` and the abilities give every report, and `wp seoprostats loop` gives an agent one cycle of search work. The plugin proposes and measures; it never changes a page by itself.

= Where do updates come from? =

From where you installed it. GitHub gets new versions first; WordPress.org 30 days later, security fixes at once.

= Where do I get help? =

Use **Support** on the settings screen, or ask aidevops.

== Screenshots ==

1. The Overview: visits against the previous period, the visitors on the site now, and the site's changes under the chart.
2. Search → Rankings: Google Search Console and Bing Webmaster Tools clicks, impressions, CTR and position by query and page.
3. Search → Plan: search work from every report in one list, best first, with its score.
4. Changes: what changed on the site, when and on which page.
5. Goals: conversions, conversion rate and revenue per currency, against the previous period.
6. A shared report: the sections you chose, read-only without wp-admin, ready to print or save as PDF.

== Changelog ==

= 1.4.1 =
* Fixed: a links import no longer waits at "0 of N rows" when its background run is lost; the Import tab shows progress and keeps trying instead of stopping on "not a valid JSON response".
* Changed: the dashboard opens on Last 91 days; Search periods keep their full length.

Every change: changelog.txt.
