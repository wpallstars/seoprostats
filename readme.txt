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

* **A settings screen** (SEO Pro Stats → Settings) that features fill by declaring their settings, saved instantly, searchable, in tabs: Tracking (what is collected), Privacy (Do Not Track, excluded addresses and pages), Data (how long visits are kept, who can see the statistics) and Connections (outside data, such as Google Search Console).
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

= Where are the statistics? =

SEO Pro Stats in the admin menu, under Dashboard, and a widget on the Dashboard. They need visits: open the site logged out, or in a private window, and they show within a minute or two.

= Can it count signups and sales? =

Yes. Under SEO Pro Stats → Goals, an administrator adds the pages or events that count as conversions; each shows the visits that reached it, the conversion rate and revenue, per currency. Funnels show where visits leave a series of steps, and Properties what was sent with events, such as a plan.

Paid orders from WooCommerce, Easy Digital Downloads and FluentCart are recorded by themselves as a Purchase event with the order's total in its currency, on the visit that placed it, once per order; ThriveCart orders too once its secret word and webhook are set under Settings → Tracking. No order numbers or customer details are kept, and Settings → Tracking can switch it off.

Refunds of newly recorded orders count once on the original visit and purchase date. Purchase goal and property revenue subtract them per currency without adding purchase completions. Older purchases without a saved visit cannot be adjusted; subscription renewals are still left out. ThriveCart refunds need a delivery ID and a remembered order (last 500).

= Does it record what people click? =

Yes, unless you switch it off under Settings → Tracking. Clicks shows what people click on the site or one page, clicks that did nothing (dead clicks), links followed, including affiliate links, files and the forms sent. It never records what anyone types or chooses in a form, hides email addresses and long numbers, and leaves out the text of anything marked with data-sps-mask. Clicks are kept 3 months.

= Does it show broken links and site searches? =

Yes. The Overview lists the addresses people reached that were not found, what they searched the site for and the searches that found nothing, and views by author, category and post type. Under Settings → Tracking you can leave search words out; searches are then counted without them. Email addresses and long numbers in searches are always hidden.

= Does it show what changed on the site? =

Yes. It keeps a change log, recorded as changes are saved: posts and pages published, unpublished, moved, retitled and edited (words, internal links and the sites linked to), SEO titles, descriptions and robots settings from the common SEO plugins, WooCommerce prices, sales, stock and coupons, plugin, theme and WordPress updates, and settings such as search engine visibility and permalinks. The Changes section lists it, and a lane under the Overview's chart marks each change on the day it happened. Administrators add notes for what the log cannot see, such as a newsletter sent. Switched on under Settings → Data, Google's search ranking updates and search incidents show there too, with how long each rolled out, along with update posts from other feeds you add. Scripts and AI agents read it and add notes through the REST API (markers, changes, annotations), wp seoprostats changes and annotate, and on WordPress 6.9 and later the abilities seoprostats/markers and seoprostats/annotate, so traffic and sales can be set against what changed.

= Can it show Google Search Console data? =

Yes, once you connect it under Settings → Connections with a Google Cloud service account's JSON key (the tab lists the steps). The key is stored encrypted and never shown again. Clicks, impressions and average position for each page and search query are imported by day: the 16 months Search Console keeps on connecting, then each day once Search Console marks it final, about three days later. Imports run in the background, never while a visitor loads a page, and each can be undone. Search data by page and query is kept 25 months; daily totals are kept.

SEO Pro Stats → Search shows them: clicks, impressions, click-through rate and average position against the previous period, a chart with the site's changes under it, and the search queries, pages, countries and devices. Choose a page to see its queries, or a query to see its pages. Scripts and AI agents read the same report through the REST API (search), wp seoprostats search, and on WordPress 6.9 and later the ability seoprostats/search. Demo data has made-up search data, so you can try it before connecting.

= Can I see what it shows before my site has visits? =

Yes. Switch on Demo data on the Overview: an administrator can make a little over a year of made-up visits there. They are kept in tables of their own, apart from your live statistics, and can be removed at any time.

= Where do I get help? =

Ask aidevops (https://aidevops.sh): open the plugin's repository, or your site, with it and ask. To report a problem, use the Support link on the settings screen.

= Does it contact other services? =

Not unless you switch on Show search engine updates under Settings → Data, or connect Google Search Console under Settings → Connections (both off by default). Search engine updates: once a day, it asks Google's Search Status Dashboard for its list of search updates, and any other feeds you add for theirs. Search Console: it signs in to Google with your service account and asks for your property's search data. Otherwise the WordPress.org build contacts nothing outside WordPress. See External services.

= When do versions reach WordPress.org? =

GitHub releases are the stable beta channel: each version comes out there first. WordPress.org gets it 30 days later, except security releases, which come out on both at once.

== External services ==

**Google Search Status Dashboard** (status.search.google.com), only when Settings → Data → Show search engine updates is on: once a day the site downloads the dashboard's public list of Google Search ranking updates and incidents (https://status.search.google.com/incidents.json), to mark them on the charts. The request sends nothing about the site or its visitors: no cookies and no site address; the user agent names only the plugin and its version. Google's terms: https://policies.google.com/terms; privacy policy: https://policies.google.com/privacy.

**Other feeds** you add under Settings → Data → Other feeds are downloaded the same way, once a day each, and only while the setting is on; their own terms apply.

**Google Search Console API** (searchconsole.googleapis.com) and **Google's sign-in service** (oauth2.googleapis.com), only after you connect Search Console under Settings → Connections with your own service account's key: the site signs a sign-in request with the key and sends it to Google for an access token, lists the properties the service account can read, and asks for the chosen property's clicks, impressions and positions by day, page, query, device and country. This happens when you connect, when you choose Import now, and from WP-Cron (hourly, more often while the history is imported); never on visitors' pages, and nothing about the site's visitors is sent. The user agent names only the plugin and its version. Disconnecting stops it. Google's terms: https://policies.google.com/terms; privacy policy: https://policies.google.com/privacy.

== Screenshots ==

1. The settings screen (SEO Pro Stats → Settings) on the Tracking tab.
2. The Read Me tab, showing the plugin's README.md inside WordPress.

== Changelog ==

= 0.1.0 =
* First version, made from WP Plugin Starter 1.0.24.

Every change: changelog.txt.
