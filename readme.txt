=== SEO Pro Stats ===
Contributors: wpallstars
Donate link: https://buymeacoffee.com/marcusquinn
Tags: starter, boilerplate, settings, developer, template
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Privacy-friendly site statistics in WordPress: visits, pages, sources and goals, kept in your own database without cookies or outside services.

== Description ==

SEO Pro Stats is what wpallstars plugins are made from. It has no features of its own: it holds the parts every plugin needs, so a new plugin starts with them working.

* **A settings screen** (SEO Pro Stats → Settings) that features fill by declaring their settings, saved instantly, searchable, in tabs: Tracking (what is collected), Privacy (Do Not Track, excluded addresses and pages), Data (how long visits are kept, who can see the statistics), Connections (outside data, such as Google Search Console and Bing Webmaster Tools) and Import (history from another statistics plugin, such as Burst Statistics, and removing what it left behind).
* **A Read Me tab** that shows the plugin's README.md, banner included.
* **Features as classes**, off by default, with settings, hooks, one-off imports from the plugins they replace and clean uninstall.
* **Release and check scripts**: lint, smoke test, release build, preflight and Plugin Check.

Start a plugin from it on GitHub (wpallstars/seoprostats): the Read Me tab explains how.

= Built with AI =

SEO Pro Stats is built and maintained with aidevops (https://aidevops.sh), the same developer's open-source AI harness for creating and managing anything online with AI.

Questions about using or changing SEO Pro Stats: ask aidevops. It reads the plugin's docs and code to answer, and can report a problem for you.

Made from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding), the wpallstars starter plugin.

== Installation ==

1. Upload the `seoprostats` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate it, then open SEO Pro Stats in the admin menu, under Dashboard. Its last item is Settings.

== Frequently Asked Questions ==

= Can I share a report with a client? =

Yes. Administrators choose Share on any tab, or Shared reports in the menu. A report can hold every tab as a section (Search once for each engine, without the Plan or Experiments); a new one starts with every section that has data, in an order you can drag or set with the keyboard. A private link opens them, live, without a WordPress account, with optional password, expiry, locked filters, period limits and branding. Copy the link when shown; only its hash is stored. Revoke it or make a new link at any time. Readers are not tracked. Password unlocks use tab session storage for an hour, never cookies. Light, dark and print views are included; printing asks which sections to print and prints each with all of its tabs. Agency defaults live under Settings → Shared reports.

= Where are the statistics? =

SEO Pro Stats in the admin menu, under Dashboard, and a widget on the Dashboard. They need visits: open the site logged out, or in a private window, and they show within a minute or two.

= Can it count signups and sales? =

Yes. Under SEO Pro Stats → Goals, an administrator adds the pages or events that count as conversions; each shows the visits that reached it, the conversion rate and revenue, per currency. Funnels show where visits leave a series of steps, and Properties what was sent with events, such as a plan.

Paid orders from WooCommerce, Easy Digital Downloads and FluentCart are recorded by themselves as a Purchase event with the order's total in its currency, on the visit that placed it, once per order; ThriveCart orders too once its secret word and webhook are set under Settings → Tracking. No order numbers or customer details are kept, and Settings → Tracking can switch it off.

Refunds of newly recorded orders count once on the original visit and purchase date. Purchase goal and property revenue subtract them per currency without adding purchase completions. Older purchases without a saved visit cannot be adjusted. ThriveCart refunds need a delivery ID and a remembered order (last 500).

Paid subscription renewals from WooCommerce Subscriptions, FluentCart and ThriveCart count separately by reception day in the site's timezone, without making visits or increasing Purchase completions. GET /goals and wp seoprostats doctor show counts and amounts per currency, retained for 400 days. At 10,000 retained payment hashes, doctor warns and new counts stop rather than risking duplicates. Filtered, demo and sub-day reports do not expose these site-wide totals. EDD Recurring is not yet supported; its extension contract remains unverified.

= Does it record what people click? =

Yes, unless you switch it off under Settings → Tracking. Clicks shows what people click on the site or one page, clicks that did nothing (dead clicks), links followed, including affiliate links, files and the forms sent. It never records what anyone types or chooses in a form, hides email addresses and long numbers, and leaves out the text of anything marked with data-sps-mask. Clicks are kept 3 months.

= Does it show broken links and site searches? =

Yes. The Overview lists the addresses people reached that were not found, what they searched the site for and the searches that found nothing, and views by author, category and post type. Under Settings → Tracking you can leave search words out; searches are then counted without them. Email addresses and long numbers in searches are always hidden.

= Does it show what changed on the site? =

Yes. It keeps a change log, recorded as changes are saved: posts and pages published, unpublished, moved, retitled and edited (words, internal links and the sites linked to), SEO titles, descriptions and robots settings from the common SEO plugins, WooCommerce prices, sales, stock and coupons, plugin, theme and WordPress updates, and settings such as search engine visibility and permalinks. The Changes section lists it, and a lane under the Overview's chart marks each change on the day it happened. Administrators add notes for what the log cannot see, such as a newsletter sent. Switched on under Settings → Data, Google's search ranking updates and search incidents show there too, with how long each rolled out, along with update posts from other feeds you add. Scripts and AI agents read it and add notes through the REST API (markers, changes, annotations), wp seoprostats changes and annotate, and on WordPress 6.9 and later the abilities seoprostats/markers and seoprostats/annotate, so traffic and sales can be set against what changed.

= Can it show Google Search Console data? =

Yes, once you connect it under Settings → Connections with a Google Cloud service account's JSON key. The tab walks you through it in six steps, each with a link to the Google page it needs: make a Google Cloud project, turn on the Search Console API, make a service account, download its key, add the service account to your Search Console property as a Restricted user, and paste the key. The key is stored encrypted and never shown again. Clicks, impressions and average position for each page and search query are imported by day: the 16 months Search Console keeps on connecting, then each day once Search Console marks it final, about three days later. Imports run in the background, never while a visitor loads a page, and each can be undone. Search data by page and query is kept 25 months; daily totals are kept.

SEO Pro Stats → Search shows them: clicks, impressions, click-through rate and average position against the previous period, a chart with the site's changes under it, and the search queries, pages, countries and devices. The Days tab lists the chart's days (or weeks or months), newest first, for Google, Bing, Combined, a page or a query. Choose a page to see its queries, or a query to see its pages. Scripts and AI agents read the same report through the REST API (search), wp seoprostats search, and on WordPress 6.9 and later the ability seoprostats/search. Demo data has made-up search data, so you can try it before connecting.

= Can it show search result types? =

Yes. Search → Rankings → Appearance shows Google's result types, such as video, product snippets, review snippets and forums, with clicks, impressions, CTR and position against the previous period. It is for the whole site and Google only, not Bing. One search can show several appearances, so these figures do not add up to the site's totals. Scripts and AI agents read the same rows with REST kind=appearance, wp seoprostats search appearance and the seoprostats/search ability.

= Can it show Bing too? =

Yes. Connect Bing Webmaster Tools under Settings → Connections with the API key from Bing Webmaster Tools (Settings → API access); the site must be verified there, which importing it from Google Search Console does at once. The key is stored encrypted. Bing's clicks and impressions for the site are imported by day, and its top pages and search queries by week with their average position: the 16 months Bing keeps on connecting, then each week once Bing gives it, about a week after it ends. Google and Bing buttons switch every Search tab between the two. Bing has no countries or devices, and its periods are whole weeks. Scripts and AI agents choose it with engine=bing (REST API and abilities) or --engine=bing (WP-CLI). A Combined button (engine=all) adds Google and Bing up on Rankings, Opportunities and Content, with the period ending at the earlier of their newest days.

= What does Search → Opportunities show? =

Where search work pays, from the same search data (Google's, or Bing's). Striking distance lists a page's search queries at positions 4 to 20 with the clicks each could gain in the top three. Low CTR lists queries in the top 10 that searchers choose much less often than your site's own click-through rate at that position, where a clearer title and description may help. Losing clicks lists pages with at least a fifth fewer clicks than the earlier period, each with the likely cause (it ranks lower, it is searched for less, fewer searchers choose it, or it is no longer shown), the queries that lost most and what changed on the page. Missing from the page lists queries a page ranks for in the top 20 whose words the page does not have, or has only some of. Overlapping pages lists queries for which two or more pages each get at least a tenth of the impressions, with each page's share, position and clicks, and whether the leading page changed during the period: candidates to review, not faults, as a guide and a product page can both be right for one search. Choose a row to open it in Rankings. Scripts and AI agents read it through the REST API (opportunities), wp seoprostats opportunities, and on WordPress 6.9 and later the ability seoprostats/opportunities.

= Does it show which search queries a post does not cover? =

Yes. While you edit a post, a Search queries panel (in the block editor's sidebar, or a box in the classic editor) lists the queries Google showed the page for, which of them the page's words do not cover yet, the questions people searched, and your SEO plugin's focus keywords with their clicks and position. It re-checks as you write, so a query turns covered once its words are on the page. Focus keywords are read from Rank Math, Yoast SEO, SEOPress or All in One SEO; without an SEO plugin everything else works the same. It reads the Search Console data already imported, only in wp-admin. Scripts and AI agents read it through the REST API (coverage), wp seoprostats coverage, and on WordPress 6.9 and later the ability seoprostats/coverage.

= What does Search → Audit check? =

What WordPress says about each published page: its title and description (from Rank Math, Yoast SEO, SEOPress or All in One SEO, else the post's own title and excerpt), its headings, length and images, and whether it asks search engines not to index it or names another page as canonical. Pages with a finding are listed most search impressions first, so the fixes that matter most come first: a title or description that is missing, too long or the same as another page's, no main heading or several, images without alt text, thin content that is shown in search but never clicked, and noindex or a canonical address elsewhere on a page that still shows in search. Pages are read when they are saved and in a daily batch, never while visitors browse. Each finding is also in Search → Plan. Scripts and AI agents read it through the REST API (audit), wp seoprostats audit, and on WordPress 6.9 and later the ability seoprostats/audit.

= Does it check internal links? =

Yes, under Search → Audit. The links in each published page's text to your other pages are read with the audit, and three lists show what to fix: orphan pages that no other page links to, pages that convert from search but that few pages link to, and missing links, where a page shows for a search but does not link to the page that gets that search's clicks. Only links in the text count, not menus or widgets. Each row is also in Search → Plan. Scripts and AI agents read it through the REST API (links), wp seoprostats links, and on WordPress 6.9 and later the ability seoprostats/links.

= Does it show pages search engines are not showing? =

Yes, under Search → Audit → Indexation. It lists published pages with no search impressions in the newest 28 days of search data, published before them, and the other addresses in WordPress's own sitemap, such as category, tag and author archives, listed that long with none. Each says whether search never showed it or stopped showing it, and when. Pages set to noindex or with a canonical address elsewhere are left out. The sitemap is read from WordPress once a day, with no outside request. Each row is also in Search → Plan. Scripts and AI agents read it through the REST API (indexation), wp seoprostats indexation, and on WordPress 6.9 and later the ability seoprostats/indexation.

With Google Search Console connected, Search → Audit → Google's index also shows your sitemaps as Google read them (errors, warnings, last download, and whether your own sitemap is submitted), and Google's URL Inspection of your pages: whether each is in Google's index, Google's reason, its last crawl and the canonical Google chose. Up to 200 pages a day are inspected from the hourly import (Settings → Data → Google URL inspections, 0 to 2,000), each again after 14 days. Robots.txt blocks, pages crawled but not indexed, another canonical chosen by Google and rich result errors become audit findings; sitemap problems go to Plan. Scripts and AI agents read it through the REST API (inspections), wp seoprostats inspect, and on WordPress 6.9 and later the ability seoprostats/inspections.

= Does it show backlinks? =

Yes, under Search → Backlinks, without an outside service: once a day the site opens the pages of other sites that sent visitors and keeps their links to your pages, with the link text and whether they are nofollow, sponsored or ugc. It lists the links, the sites linking (with their visits), the pages they link to, and links lost; new and lost links show on the timeline. Links from sites that never sent a visitor are not found. Switch it off under Settings → Data. Scripts and AI agents read it through the REST API (backlinks), wp seoprostats backlinks, and on WordPress 6.9 and later the ability seoprostats/backlinks.

= What does Search → Content show? =

Which pages earn their search traffic. For each page: its search clicks, position and click-through rate, beside the visits from search that started on it, their bounce rate and time, and how many reached a goal you pick. A page that ranks but whose visitors leave needs better content or a clearer next step; one that converts but gets few clicks is worth ranking higher. Sort by clicks, visits or conversions. Scripts and AI agents read it through the REST API (content), wp seoprostats content, and on WordPress 6.9 and later the ability seoprostats/content.

= What should I work on next? =

Search → Plan puts what Opportunities, Audit, Internal links and Indexation find into one list, best first. Each item says why it is listed, what to do, and how its score is made: the clicks it could bring in 28 days, times how well the page's visits from search convert (with a goal), times how sure the estimate is, divided by the effort. Accept an item, mark it done once the change is live (that starts an experiment on the page, so the change is measured), or dismiss it for 90 days. Pages with an experiment running get no new items, so one change is measured at a time. For a page losing clicks it proposes what to do: update it, leave it (fewer people search), protect it (it converts well: change it carefully) or merge it (another of your pages overtook it), with the reason; it never changes a page by itself. Search → Targets keeps the searches you chose to win and the page meant for each, imported from a list; Plan adds those where another page ranks and high-priority ones close to the top three. Scripts and AI agents use it through the REST API (queue), wp seoprostats queue, and on WordPress 6.9 and later the abilities seoprostats/queue and seoprostats/queue-update.

= Can it tell whether a change worked? =

Search → Experiments checks it. Write down a change and what it should do, such as more clicks or a better position, from a row in Changes or with a start time and pages. It compares the same number of days before and after the change for those pages and for the pages that did not change, so a site-wide rise or a season does not count, and shows how far unchanged pages usually move, whether there was enough data, and the search engine updates and other changes in the same days. Once the data is in it suggests keep, revise, undo or inconclusive; you decide, and the figures are kept with the decision. A result is evidence about your site, not proof of cause. Scripts and AI agents use it through the REST API (experiments), wp seoprostats experiments, and on WordPress 6.9 and later the abilities seoprostats/experiments and seoprostats/experiment-record.

= Can I A/B test part of a page? =

Yes. In the block editor, select a block or several and choose A/B test in the block toolbar. Variant A holds your blocks and Variant B starts as a copy to change; pick the variant to edit from the test's toolbar, and set its name, status, goals and each variant's weight in the sidebar. Once running, each page load shows one variant, picked in the browser before the page is drawn, with no cookies or browser storage; search engines, visitors without JavaScript and page caches see Variant A. Tests start in posts and pages. Each pageview records the variants it showed, and clicks inside a variant count for it. SEO Pro Stats → A/B tests shows each test's variants side by side over its whole life: visits, conversions, uplift over Variant A with a 95% interval, the chance to beat it and a plain verdict, which says when it is too early to call (each side needs 100 visits, the two 10 conversions, and the test 7 days). Choosing a variant filters every report by it. The test's sidebar in the editor shows the same results in short; Pick a winner there replaces the test with the variant you choose (undo works), and saving the post ends the test with that winner while its results stay. To show a visitor the same variant on every page of a visit, turn on Settings → Tracking → Show each visit one variant of an A/B test (off by default): it keeps the variant in the browser tab's session storage until the tab closes, which privacy law treats like a cookie, so it may need consent.

= Can an AI agent run the SEO work week by week? =

Yes, with an Application Password. One answer, the loop (REST API loop, wp seoprostats loop, and on WordPress 6.9 and later the ability seoprostats/loop), gives the agent everything for a cycle: the open Plan items best first, the experiments due for a decision, those running and those decided lately with their results, and the period's search figures per search and page. The agent accepts an item, makes the change in WordPress, marks it done (which starts an experiment), and decides the experiment on its review day from the measurement; the results then shape the next plan. The plugin proposes and measures; it never changes a page by itself. The steps are in docs/seo-loop-recipes.md in the plugin's repository.

= Can I bring my statistics history from another plugin? =

Yes, from Burst Statistics, Koko Analytics, Statify, WP Statistics, Independent Analytics, Slimstat, Matomo for WordPress and Jetpack Stats. Open SEO Pro Stats → Settings → Import: the plugin is listed whenever its data is on the site, even after it is switched off or deleted. Check what would be imported (nothing changes), then Import: the days before SEO Pro Stats started are added to the reports in the background, with what that plugin kept. From Burst Statistics: visitors, visits, bounces and time, pages, referrers, channels, campaigns, countries, devices, browsers and operating systems. From Koko Analytics: visitors and pageviews of the site and its pages, and referrers. From Statify: pageviews of the site, its pages and referrers, for as long as Statify kept them (14 days unless you changed it). Deleting Statify removes its history, so import before deleting it. From WP Statistics: visitors, visits (each visitor's day), bounces, pages, entry and exit pages, referrers, channels, campaigns, countries, devices, browsers and operating systems, and for days it purged, the daily visitors and pageviews it kept. From Independent Analytics: visitors, visits, bounces and time on page, pages, entry and exit pages, referrers, channels, campaigns (Pro), countries, devices, browsers and operating systems. From Slimstat and Matomo for WordPress: visits (each counted as one visitor), bounces and time, pages, entry and exit pages, referrers, channels, campaigns (Matomo keeps only the campaign's name and keyword), countries, devices, browsers and operating systems, read from their own tables one day at a time, so it works after they are switched off; with large tables the check before importing estimates their counts from a few days. From Jetpack Stats: daily views and visitors (each visitor's day as one visit), and views of each post and page, referrer and country, fetched from WordPress.com in the background while Jetpack is active and connected (see below). Days SEO Pro Stats counted itself are never replaced, and a day is never counted twice, so importing again adds nothing; when two plugins have the same days, you choose which fills them. The finished import shows the other plugin's own counts beside the imported ones, and Undo removes exactly what it added. Settings with an equivalent here (Do Not Track, excluded roles and IP addresses, logged-in users not counted, how long visits are kept) are filled in only where you have not changed them. Only counts are read: no IP addresses or visitor IDs.

= How does the Jetpack Stats import work, and how do I report a problem? =

Jetpack Stats keeps its history on WordPress.com, not on your site, so keep Jetpack (or the Jetpack Stats plugin) active and connected, with Stats on, until the import is done: once Jetpack is gone, its history can no longer be brought across. SEO Pro Stats first looks up the daily views and visitors in the background (Settings → Import says when it is still looking, or when Jetpack needs connecting or updating), then imports a day at a time, waiting and trying again when WordPress.com is busy. Requests are made only in the background and in WP-CLI, never on visitor pages, and Jetpack's own caches are not filled. The Jetpack import is new and was built from Jetpack's published code, so reports from people who use it are welcome: run wp seoprostats migrate run jetpack --dry-run --requests and paste its output into your report. It lists each request to WordPress.com, its answer code and the shape and size of the answer, never passwords, tokens or the statistics themselves. After importing, switch off Stats in Jetpack → Settings → Traffic (Jetpack keeps its other features), or deactivate the Jetpack Stats plugin.

= How do I remove the old statistics plugin and its data? =

SEO Pro Stats shows the next step at the top of the Plugins screen, under the old plugin's row and on its own screens, from Import its history to Remove leftover data, until the plugin and its data are gone (Hide hides a step). Both plugins keep counting meanwhile. After importing, switch the old plugin off and delete it from the Plugins screen (the Import tab links there). Many statistics plugins leave their tables and settings behind when deleted. Once the plugin is not active, Settings → Import → Remove leftover data lists exactly what it left on this site (database tables, options, scheduled tasks, user roles it added and files) and deletes it when you confirm. It is refused while that plugin is active and cannot be undone, so back up the database first. The days already imported stay.

= Can I see what it shows before my site has visits? =

Yes. Switch on Demo data on the Overview: an administrator can make a little over a year of made-up visits there. They are kept in tables of their own, apart from your live statistics, and can be removed at any time.

= Where do I get help? =

Ask aidevops (https://aidevops.sh): open the plugin's repository, or your site, with it and ask. To report a problem, use the Support link on the settings screen.

= Does it contact other services? =

Only the pages that send you visitors, to find their links to you (Settings → Data → Check pages that send visitors for links; on by default, switch it off to stop it). Nothing else unless you switch on Show search engine updates under Settings → Data, or connect Google Search Console or Bing Webmaster Tools under Settings → Connections (all off by default). Backlinks check: once a day, for up to 20 seconds, it opens pages of other sites that sent visitors, as any browser would, and reads their links to your site; the request names the plugin and your site and sends nothing about your visitors. Search engine updates: once a day, it asks Google's Search Status Dashboard for its list of search updates, and any other feeds you add for theirs. Search Console: it signs in to Google with your service account and asks for your property's search data, its sitemaps and how Google indexed your pages. Bing Webmaster Tools: it asks Bing for your site's search data with your API key. Otherwise the WordPress.org build contacts nothing outside WordPress. See External services.

= When do versions reach WordPress.org? =

GitHub releases are the stable beta channel: each version comes out there first. WordPress.org gets it 30 days later, except security releases, which come out on both at once.

== External services ==

**Pages of other sites that sent visitors**, while Settings → Data → Check pages that send visitors for links is on (the default): once a day, for up to 20 seconds, WP-Cron opens pages that sent visits to the site (the address the visitor's browser gave as the referrer, often only the other site's home page), each again weekly, to read their links to the site for Search → Backlinks. Only public addresses are opened (WordPress's safe request), with a 5-second timeout and at most 1 MB read; the user agent names the plugin, its version and the site's address so the other site's owner can see who looked. Nothing about the site's visitors is sent, no cookies are sent or kept, and never on visitors' pages. Each site's own terms and privacy policy apply. Switching the setting off stops it.

**Google Search Status Dashboard** (status.search.google.com), only when Settings → Data → Show search engine updates is on: once a day the site downloads the dashboard's public list of Google Search ranking updates and incidents (https://status.search.google.com/incidents.json), to mark them on the charts. The request sends nothing about the site or its visitors: no cookies and no site address; the user agent names only the plugin and its version. Google's terms: https://policies.google.com/terms; privacy policy: https://policies.google.com/privacy.

**Other feeds** you add under Settings → Data → Other feeds are downloaded the same way, once a day each, and only while the setting is on; their own terms apply.

**Google Search Console API** (searchconsole.googleapis.com) and **Google's sign-in service** (oauth2.googleapis.com), only after you connect Search Console under Settings → Connections with your own service account's key: the site signs a sign-in request with the key and sends it to Google for an access token, lists the properties the service account can read, and asks for the chosen property's clicks, impressions and positions by day, page, query, device and country; once a day for the sitemaps submitted for it; and, up to the daily number set under Settings → Data → Google URL inspections (200 by default, 0 for none), for Google's URL Inspection of the site's own page addresses. This happens when you connect, when you choose Import now, and from WP-Cron (hourly, more often while the history is imported); never on visitors' pages, and nothing about the site's visitors is sent. The user agent names only the plugin and its version. Disconnecting stops it. Google's terms: https://policies.google.com/terms; privacy policy: https://policies.google.com/privacy.

**Bing Webmaster API** (ssl.bing.com), only after you connect Bing Webmaster Tools under Settings → Connections with your own API key: the site sends the key with each request, lists the key's verified sites, and asks for the chosen site's clicks and impressions by day, its top pages and search queries by week, and the search queries of its top pages (one request a page). This happens when you connect, when you choose Import now, and from WP-Cron (hourly at most, more often while the history is imported); never on visitors' pages, and nothing about the site's visitors is sent. The user agent names only the plugin and its version. Disconnecting stops it. Microsoft's terms: https://www.microsoft.com/servicesagreement; privacy statement: https://privacy.microsoft.com/privacystatement.

== Screenshots ==

1. The settings screen (SEO Pro Stats → Settings) on the Tracking tab.
2. The Read Me tab, showing the plugin's README.md inside WordPress.

== Changelog ==

= 0.1.0 =
* First version, made from WP Plugin Starter 1.0.24.

Every change: changelog.txt.
