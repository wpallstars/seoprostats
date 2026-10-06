# Architecture

Read this before changing how SEO Pro Stats collects, stores or reports
data, its REST API, WP-CLI commands, abilities or the dashboard app. The
roadmap is in `todo/PLANS.md`; the rules every wpallstars plugin shares are
in `STANDARDS.md`.

## What it is for

Site statistics that explain cause and effect: traffic, search rankings
and conversions on one timeline with the changes that moved them (page
edits, plugin and theme updates, search engine updates, backlinks,
mentions, outages). Owners see why numbers moved; AI agents (aidevops) read
the same data through the API, WP-CLI and abilities to plan and test SEO
and CRO work, then measure the result.

Principles, in order:

1. **Private by design.** No cookies, no stored IP addresses, no
   cross-day visitor identity, no typed form values. Visitors are counted
   with a daily, per-site hash whose salt is destroyed after two days.
2. **Costs nothing when idle, little when busy.** Visitor pages never wait
   on the plugin. A hit is appended to a file without loading WordPress;
   cron does the work in batches. Every query uses an index; reports read
   daily summaries where they can and cache answers.
3. **The owner's data, in the owner's database.** Outside services are
   opt-in and only add facts (Search Console, backlinks, mentions, a
   location database). Every table can be exported, imported and pruned by
   a retention setting.
4. **WordPress first in the admin, portable underneath.** The admin looks
   and behaves like WordPress. Data contracts, the tracker, the chart
   layer and the report logic are framework-independent so a standalone
   web app, mobile app or browser extension can reuse them.

## Layers

```text
browser tracker ──► collector (no WordPress) ──► buffer files
                                                    │ cron, every minute
                                                    ▼
WordPress hooks ──► change log          processor (sessions, UA, channel,
(posts, plugins,    (markers)           location, bots) ──► fact tables
 themes, options)                                           │ nightly
                                                            ▼
Search Console, ──► import jobs ──────────────────► daily summaries
backlinks, mentions                                         │
                                                            ▼
                     report engine (filters, compare, metrics, cache)
                       │            │             │            │
                    REST API     WP-CLI      abilities     dashboard app
```

## Repository layout

| Path | What |
|---|---|
| `includes/features/` | Starter features: each switch on the settings screen (`STANDARDS.md` → Structure). |
| `includes/stats/` | The engine: schema, collector, processor, report engine, API, CLI, jobs. Loaded from `SEOProStats_Setup::load()`. |
| `collect.php` | The collector endpoint for the fast path. Loads no WordPress. |
| `packages/core/` | `@seoprostats/core`: TypeScript types for the API (generated from `docs/api/openapi.yaml`), the filter and range model, metric definitions and formatting. No framework. |
| `packages/tracker/` | `@seoprostats/tracker`: the browser script (TypeScript, built with esbuild). No dependencies. |
| `packages/charts/` | `@seoprostats/charts`: time-series chart (uPlot), markers lane, sparklines, map, flow (SVG). No framework. |
| `packages/wp-admin/` | The dashboard app for wp-admin: React (WordPress's copy), `@wordpress/components`, TanStack Query and Table. |
| `assets/build/` | Built files that ship (committed: release zips are made from Git with no build step). |
| `docs/api/openapi.yaml` | The API contract, the source of truth for both PHP routes and TypeScript types. |

Build: `npm ci && npm run build` (wp-scripts for the app, esbuild for the
tracker). `npm run check` type-checks and lints. Commit `assets/build/`
with the source change; CI rebuilds and fails when they differ.

## Collection

### Tracker

One script, about 3 KB compressed, printed inline in the footer of front-end
pages (no extra request, nothing for blockers to match by file name), or
served as a file when a page cache or CSP needs it. Speed measurements load
a second small script only on sampled page loads.

It sends, with `navigator.sendBeacon` (fallback `fetch` with `keepalive`),
batched and as `text/plain` so no CORS preflight:

| Type | When | What |
|---|---|---|
| `pv` pageview | load, SPA navigation (`pushState`, `replaceState`, `popstate`; hash routes when enabled) | path and allowed query parameters, referrer, UTM tags, screen width, time zone, language, page properties (`data-props`) |
| `eng` engagement | page hidden or left | visible seconds, deepest scroll %, for the pageview it follows |
| `e` event | `seoprostats('Name', {props, revenue})`, outbound links, file downloads, `data-sps-event` attributes | name, up to 30 properties (300 characters each, scalars only), revenue as `{amount, currency}` |
| `c` click / `f` form | every click and form submit (autocapture) | `tag#id.class` selector, visible label (60 characters), link origin and path, dead click flag, form name and field count. Never field values. |
| `v` vitals | sampled page loads, on leave | LCP, INP, CLS, FCP, TTFB and the element or script behind each |
| `x` error | uncaught errors and rejections | type, message, top 20 stack frames without query strings; at most 10 per page |

The tracker stores nothing in the browser (no cookies, `localStorage` or
`sessionStorage`, which privacy law treats like cookies). It keeps a random
ID for each page load in memory and sends it with the pageview, its
engagement and its events, so they can be joined. Visits are made on the
server (Processing → Sessions).

The tracker skips: logged-in users with a role that is not counted (by
default every role that can edit posts), feeds, previews, the customizer,
localhost, Do Not Track and Global Privacy Control when the owner asks,
excluded paths, and visible automation (`navigator.webdriver`, headless
user agents). With collection off, it is not printed, and the collector
config's `off` flag makes the collector store nothing from pages cached
with it.

The settings (SEO Pro Stats → Settings: Tracking, Privacy, Data) are one
feature, `SEOProStats_Statistics`, in the autoloaded settings option, so
reading them on a visitor page costs no query. The engine reads them
through its helpers and its filters apply on top. Saving rewrites the
collector config file.

Cached pages keep the inline tracker they were cached with, so
`SEOProStats_Page_Cache` purges the page caches it knows (WP-Optimize,
LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround,
WP Engine, Breeze, Cache Enabler, Hummingbird, Nginx Helper, WP Fastest
Cache), each through its own public function or hook and only when
active:

- After the tracker build changes (its version, size and time): the
  first admin request schedules a one-off WP-Cron event
  (`seoprostats_page_cache_purge`) that purges. A cron request loads
  every plugin, where an admin screen may not: plugins that load other
  plugins only on the screens that need them can leave the cache plugin
  out of the Dashboard.
- After a setting that changes the printed tracker is saved (the save
  runs through admin-ajax, which loads every plugin).

Admin, WP-Cron and WP-CLI requests only; `wp seoprostats purge-caches`
does it by hand.

`SEOProStats_Tracker` prints it (a one-line stub in the head queues
`seoprostats()` calls made earlier) with its config as JSON in the script
element's `data-cfg` attribute: collector address, the site's hosts, kept
query parameters, the DNT/GPC switch and excluded paths. Printing costs no
query: the endpoint choice is an autoloaded option
(`seoprostats_endpoint`) written by the loopback test. Do Not Track and GPC
are checked in the browser, so a page cache can keep one copy of the page.

### Collector

`SEOProStats_Collector` is plain PHP with no WordPress calls, so it runs in
two places:

- **Fast path:** `collect.php` in the plugin folder loads only the
  collector and its config file. Used when the plugin's loopback test
  passes (some hosts block PHP files in plugin folders). Only in the
  GitHub build: WordPress.org asks every PHP file to stop when requested
  directly, so its build leaves `collect.php` out (`.distignore-wporg`)
  and uses the REST route.
- **Fallback:** a REST route, `POST /wp-json/seoprostats/v1/collect`.

Per request it checks the size (16 KB), the JSON, and that the `Origin` or
page host is the site or an allowed host; drops excluded IP addresses and
CIDR ranges; computes the visitor hash:

```text
visitor = first 8 bytes of HMAC-SHA256(daily salt, ip | user agent | host)
```

and appends one line per event to the current buffer file with the time,
the visitor hash, the user agent, the IP address cut to /24 (IPv4) or /48
(IPv6) for the location lookup, and the country header a CDN sends
(Cloudflare and others). It answers 204 before any slow work.

The daily salt is random, made by an hourly cron job for each site-local
day, and kept for today and tomorrow only (tomorrow's is made early, so
midnight needs no cron run); older salts are deleted, so old hashes cannot
be made again. Without a salt for today, the newest one is used until cron
runs. Without any salt the collector stores nothing.

Files live in `wp-content/seoprostats/site-{blog id}/`: `config.php`
(returns an array: salts, time zone, allowed hosts, excluded addresses, the
IP header to trust) and the buffer files. Not in `uploads/`, where security
scanners flag PHP files. Every file is PHP that exits before any output (the
buffer starts with `<?php exit; ?>`), so a direct request shows nothing
even on nginx, which ignores the `.htaccess` deny rules; an `index.php`
stops listings. The fast path finds the folder from its own location
(`wp-content/plugins/seoprostats/collect.php`); where that does not hold
(a moved or symlinked plugin folder), the loopback test fails and the
tracker uses the REST route.

### Server-side capture

Crawlers rarely run JavaScript. On front-end requests that reach PHP, a
`template_redirect` hook matches the user agent against the bot list (one
regular expression, no lookups) and appends a bot hit to the buffer. Page
caches hide repeat visits, so the plugin asks the caches it can (LiteSpeed,
WP Rocket) not to cache bot requests, and lists the bot user agents for
the others.

## Processing

A cron job (every minute while buffer files exist; the dashboard also
triggers it) takes the current buffer file by renaming it, so new hits go
to a fresh file, and processes it in batches within a time budget:

1. Bot check: user agent list, automation signs from the tracker, data
   centre ranges. Bot traffic is stored apart and never counted as visitors.
2. User agent → browser, browser major version, OS, OS major version,
   device type. Our own small parser (`SEOProStats_UA`), cached per string.
3. Referrer and UTM → source and channel (`SEOProStats_Channels`: search
   engines, social networks, AI assistants, email, paid, referral, direct).
4. Location: CDN country header, else the optional location database
   (DB-IP Lite, monthly download, opt-in), else the browser time zone's
   country. The IP prefix is discarded after the lookup.
5. Sessions: a hit joins the visitor's latest visit if that visit's last
   hit was under 30 minutes earlier, else starts a new one. The visitor
   hash changes at site-local midnight, so a visit across midnight counts
   as two. Hits are grouped per visit in memory, then written with one
   `INSERT … ON DUPLICATE KEY UPDATE` per visit.
6. Facts: pageviews, events, clicks, vitals and errors in bulk inserts.
   Engagement updates the pageview it belongs to.

Text values (paths, referrers, campaign names, event names, selectors…)
are stored once in a dictionary table and referenced by number, so fact
rows stay small and indexes short.

Nightly, the minute job rolls each finished site-local day into the daily
summaries (`SEOProStats_Rollup`), then runs retention. A day is rolled an
hour after it ends, once every hit received by then is processed (the
processor records when the last file it finished was taken). Each day's
rows are replaced in one transaction, so a day can be rebuilt (`wp
seoprostats rollup --from --to`); the last rolled day is rolled again with
the next, for engagement that arrived late. The first run rolls every day
since the first visit. The hourly job rotates the salt; import jobs run
when due. Heavy work uses `SEOProStats_Feature::more_time()` budgets.

## Storage

Tables use the `{$wpdb->prefix}seoprostats_` prefix, InnoDB, and are made
with `dbDelta()` per `SEOProStats_Schema::VERSION`. Times are Unix seconds
(UTC) in `INT UNSIGNED`; days are site-local `DATE`s. Text is in `dict`.

| Table | One row per | Key columns |
|---|---|---|
| `dict` | distinct text of a kind | `id`, `kind`, `hash` BINARY(8) (unique with kind), `value` |
| `sessions` | visit | `id`, `skey` (unique), `visitor`, `day`, `started`, `ended`, `pageviews`, `events`, `engaged_ms`, `entry_id`, `exit_id`, `ref_host_id`, `ref_path_id`, `channel`, `utm_*_id` (5), `country`, `region_id`, `city_id`, `lang_id`, `browser_id`, `browser_ver`, `os_id`, `os_ver`, `device`, `screen`, `source`, `import_id` (revenue is per event, in its currency) |
| `pageviews` | page load | `id`, `pkey` (the tracker's page-load ID, unique), `session_id`, `ts`, `seq`, `path_id`, `engaged_ms`, `scroll`, `flags` (404, site search) |
| `events` | custom or automatic event | `id`, `session_id`, `ts`, `seq`, `path_id`, `name_id`, `revenue`, `currency` |
| `props` | property of a pageview or event | `owner`, `owner_id`, `key_id`, `value_id`, `ts` (reports by period, and retention) |
| `clicks` | click or form submit | `id`, `session_id`, `ts`, `seq`, `path_id`, `kind`, `selector_id`, `label_id`, `target_id`, `flags` (dead, outbound, affiliate, download) |
| `vitals` | measured page load | `id`, `ts`, `path_id`, `device`, `lcp`, `inp`, `cls`, `fcp`, `ttfb`, attribution ids |
| `errors`, `error_groups` | error occurrence, distinct bug | fingerprint, message, sample stack; occurrence time, page, browser |
| `bots` | crawler request | `id`, `ts`, `bot_id`, `path_id`, `status`, `verified` |
| `daily` | day × dimension × value | `day`, `dim` (0 the site, else a code in `SEOProStats_Rollup::DIMS`), `val` (the visit column's value or dict id; a country's two letters as a number), `visitors`, `visits`, `pageviews`, `bounces`, `engaged_ms`, `events`, `scroll` (pages: sums over their views) (revenue summaries come with goals, per currency) |
| `daily_vitals`, `daily_bots` | day × page × device × metric; day × bot | percentiles from the raw rows; requests, verified |
| `gsc_pages`, `gsc_queries`, `gsc_pairs`, `gsc_totals` | day × page / query / page and query / device and country | `clicks`, `impressions`, `pos_impr` (position × impressions, for weighted averages) |
| `changes` | marker on the timeline | `id`, `ts`, `kind`, `path_id`, `object_type`, `object_id`, `old`, `new`, `meta` (JSON), `source`, `user_id` |
| `snapshots` | stored version of a page | `path_id`, `post_id`, `ts`, title, description, H1, word count, text hash, compressed text |
| `pages` | known URL | `path_id`, `post_id`, `title`, `launched`, `removed`, `status` |
| `links` | backlink | source URL and host, target page, anchor, rel, first and last seen, lost, authority, how found |
| `incidents` | outage, slowdown or collection gap | `kind`, `started`, `ended`, `meta` |
| `imports` | import run | source, status, rows, days covered; imported rows carry its id so it can be undone |

Goals, funnels, segments, alert rules and shared-dashboard tokens are small
option arrays with autoload off. Goals (`seoprostats_goals`, up to 50) and
funnels (`seoprostats_funnels`, up to 20, of 2 to 12 steps) belong to a
data set, so demo data has its own (`_demo` after the name;
`SEOProStats_Goals`). Each goal or step is a name, a kind (`page` viewed or
`event` sent) and a match (a path or event name; `*` is any text), with a
random id.

Indexes: `ts` on every fact table (ranges and retention), `(session_id,
seq)` for journeys and funnels, `(path_id, ts)` for page reports and
`(name_id, ts)` for events, `(started)` and `(day, visitor)` on sessions,
`(dim, val, day)` on `daily`. `props` has `(owner, ts)` for listing keys
and retention and `(key_id, ts, value_id)` for a key's values (schema v3);
with the primary key both cover the reports, which read no rows. Add one
only for a query that needs it, after `SHOW INDEX` (`STANDARDS.md` →
Performance). `dbDelta()` only adds keys, so a key a version replaces is
listed in `SEOProStats_Schema::OLD_KEYS` and dropped on upgrade.

### Retention

SEO Pro Stats → Settings → Data sets the months for visits and events
(switched off: kept forever); each new kind of data adds its own there.
The `seoprostats_retention` filter applies on top (0 keeps forever).
Once a day, after the daily
summaries are up to date, cron deletes old rows in batches of 5,000 with a
time budget, from site-local midnight back, and never from a day that is
not summarised. `wp seoprostats prune --dry-run` counts them.

| Data | Default | Why |
|---|---|---|
| Visits, pageviews and journeys | 75 months | Quarter-by-quarter comparisons with filters over six years |
| Events, goals and revenue | 120 months | Low volume, high value |
| Clicks and forms | 3 months | High volume; the summaries keep the totals |
| Speed measurements | 3 months | Daily percentiles kept |
| Errors | 3 months | Groups kept 13 months |
| Crawler requests | 3 months | Daily totals per crawler kept |
| Search Console page and query pairs | 25 months | Search Console itself keeps 16 |
| Daily summaries, Search Console totals, changes | forever | Small; the long-term record |
| Page snapshots | last 10 per page | Enough for before and after |

### Demo data

Demo data is made-up visits for training, screenshots and testing, in
tables of their own with the same layout: `{$wpdb->prefix}seoprostats_demo_*`.
`SEOProStats_Schema::use_set('demo')` points every table name, and the
options that belong to a data set (table version, processing and summary
progress: `SEOProStats_Schema::option()`, with `_demo` after the live
name), at them for the rest of the request; report caches and the
dictionary's request cache are kept per data set. Live and demo data never
meet, and cron, retention and visitor requests only ever use live data.

`SEOProStats_Demo` makes the visits as the collector would write them
(lines of hits, with real user agents, referrers, campaign tags, click
IDs, countries and languages) and hands them to the processor
(`SEOProStats_Processor::ingest()`); the daily summaries follow. So demo
reports go through the same processing, summaries and queries as live
ones, and making demo data tests them. By default it covers 400 days
(at most 800): traffic grows over the period, is quieter at weekends,
moves a little with the seasons, has the odd spike (a post shared on a
forum, a newsletter), and has a monthly newsletter campaign, paid search
and social, AI answers, events with properties, and purchases with revenue
in three currencies. Making it is done in slices of up to ten seconds per
request (`POST /demo`, which the screen repeats) or in one go
(`wp seoprostats demo make`); an option lock keeps two requests from
making the same visits. While someone looks at it, demo data is topped
up to the present at most every five minutes, so today and realtime have
visits. Removing it (`DELETE /demo`, `wp seoprostats demo remove`, and
uninstall) drops its tables and options.

Each person chooses what they see with the **Demo data** switch on the
Overview (user meta `seoprostats_data`, `POST /view`); the Dashboard
widget follows the same choice and says when it shows demo data.

## Reports

### Metric definitions

| Metric | Definition |
|---|---|
| Visitors | distinct visitor hashes per day, summed over the days of the range (a person returning on three days counts three times; there is no cross-day identity to count them once) |
| Visits | sessions: a new one after 30 minutes without activity |
| Pageviews | page loads and SPA navigations |
| Views per visit | pageviews ÷ visits |
| Bounce rate | visits with one pageview and no event ÷ visits |
| Visit duration | average of each visit's visible time (time accrues only while the tab is visible) |
| Time on page | median visible time of pageviews of the page (average until the daily summaries keep medians) |
| Scroll depth | median deepest scroll % of pageviews of the page (average until then) |
| Conversions | visits that reached the goal (viewed its page or sent its event) |
| Conversion rate | visits that reached the goal ÷ visits |
| Revenue | sum of event revenue in the goal's currency; currencies are never added together |
| Funnel step | visits that reached every step up to this one, in order, within the visit (other hits may come between) |
| Funnel completion rate | visits at the last step ÷ visits at the first |
| Drop-off | visits at the step before that did not reach this one |
| Search position | impression-weighted: Σ(position × impressions) ÷ Σ impressions |

### Report engine

`SEOProStats_Query` turns a request (range, comparison, grain, filters,
dimension, metrics, limit) into SQL with `$wpdb->prepare()` and `%i`
identifiers. Filters: `is`, `is_not`, `contains`, `matches` (glob, `*`);
comma means any of, separate filters mean all of. Visit-level filters
(source, channel, country…) select sessions; page and event filters select
the visits that have one, and a page filter also limits pageviews to that
page. Dictionary filters look up ids first (`SEOProStats_Dict::find()`,
`like()`), so the fact tables are only ever matched on integer ids.

Visits belong to a range by their start time. A range that starts at
midnight reads `daily` for its whole days up to the last rolled one, and
the fact tables for the rest (today, or the part of a day a comparison
cuts), added together; breakdowns add both per value in one query, so
order and paging cover the whole range. `daily` answers requests with no
filter, and headline metrics and time series with one `is` filter of one
visit value (a source, a country…); other filters, and hours, read the
fact tables, so they reach back only as far as retention keeps visits.
"All time" starts at the first day in `daily` or `sessions`. Answers are cached for five minutes (object cache, else
transients) by a hash of the request and data set, checked against the data version
(the processor's last run), with one entry per request so they never pile
up. Realtime is never cached.

Conversions (`SEOProStats_Conversions`) read the fact tables, with the
same ranges, filters, comparison and cache:

- **Goals**: per goal, one query over `events` by `(name_id, ts)` or
  `pageviews` by `(path_id, ts)`, joined to its visits, and for event
  goals one more for revenue per currency.
- **Funnels**: one query per funnel. Step 1 takes each visit's first hit
  of the step (its `seq`); each later step joins, by `(session_id, seq)`,
  the visit's first hit of that step after the previous one. Each visit's
  depth (steps reached in order) is counted once, so the steps never rise.
  `seq` counts pageviews and events together within a visit, so a step
  can be either.
- **Properties**: keys sent with events and pageviews, or one key's values
  (optionally of one event), by `props` keys `(owner, ts)` and `(key_id,
  ts, value_id)` (forced, so only the period is read), with the revenue of
  the events that carried each value.

Ranges resolve in the site time zone: realtime (last 30 minutes), today,
yesterday, 24h, 7d, 30d, 90d, this week, this month, this year, last 12
months, last year, all time, custom; comparison with the previous period
or the same period last year (custom comparison later). A range that ends
in the future meets the same length of the other period.

### Interfaces

- **REST API**, namespace `seoprostats/v1`, documented in
  `docs/api/openapi.yaml`. Read routes need the `view_seoprostats`
  capability (administrators, and the roles Settings → Data allows); write routes
  need `manage_options`. Agents authenticate with Application Passwords.
  Report routes take `data=live|demo` (Storage → Demo data); `demo`
  makes and removes the demo data and `view` saves each person's choice.
  `goals` and `funnels` answer reports on GET and add, change and delete
  definitions (`/goals/{id}`) for administrators, on the data set asked for.
  Routes so far: `stats`, `timeseries`, `breakdown`, `realtime`, `markers`,
  `goals`, `funnels`, `properties`, `demo`, `view`; planned: `pages`,
  `page`, `flow`, `journeys`, `clicks`, `vitals`, `errors`, `bots`, `search`, `opportunities`,
  `backlinks`, `changes`, `anomalies`, `health`, `annotations`, `segments`,
  `export`, `import`, `collect`.
- **WP-CLI**, `wp seoprostats <command>` with `--format=json|csv|table`:
  `stats`, `breakdown`, `goals`, `funnels` (each `list`, `add`, `update`,
  `delete` too), `properties [<key>]`, `pages`, `search`, `changes`,
  `annotate`, `import`, `export`, `process`, `rollup`, `prune`, `doctor`,
  `demo` (`make`, `status`, `remove`); reports and definitions take
  `--data=demo`.
- **Abilities** (WordPress 6.9+, guarded with `function_exists()`): the
  read reports and annotations as `seoprostats/*` abilities, so MCP
  clients reach them through the WordPress MCP adapter.

## Dashboard app

A top-level **SEO Pro Stats** menu at position 3 (where site statistics
usually sit) opens one admin page,
`admin.php?page=seoprostats-dashboard#/overview`, holding the React app
(`includes/admin/class-seoprostats-dashboard.php`). Submenus link to its
sections. The last one is **Settings**, the starter's settings screen
(`admin.php?page=seoprostats`), there and not under WordPress's Settings
because `SEOProStats_Setup::MENU_PARENT` names this menu; the old
`options-general.php?page=seoprostats` address redirects. When SEO Pro
Stack organises the admin menu into sections, the
`seoprostack_admin_menu_catalog` filter keeps this menu at the top with
the Dashboard, unless its catalog already places it. A Dashboard widget
(the `widget` entry, a smaller bundle) shows today so far against
yesterday at the same time and the last 7 days' visitors, with a link to
the Overview. It starts at the top of the right-most column for the
person's column count (one to four, by screen width and the Layout screen
option): PHP registers it at the top of `side`, and while no saved
arrangement in `meta-box-order_dashboard` includes it, its script measures
the columns and moves it there, again when the column count changes,
until the person moves a box (`placeWidget.ts`). WordPress then keeps
their arrangement. When SEO Pro Stack tidies the Dashboard, the
`seoprostack_dashboard_layout` filter puts it at the top of that layout's
visitors and SEO column (`column3`), unless the rules already place it.

Built entries are `assets/build/dashboard.js` and `widget.js`; their
`*.asset.php` files list WordPress's scripts as dependencies, so React
and `@wordpress/components` are not bundled. WordPress before 6.6 has no
`react-jsx-runtime` script; the screen then adds a small stand-in built
on WordPress's React.

Sections: Overview · Behaviour (Flow, Journeys, Clicks, Funnels, Goals,
Properties) · Pages (All, New, Not found, Site search, page detail) ·
Search (Rankings, Opportunities, Backlinks) · Health (Speed, Errors,
Crawlers, Uptime) · Changes (Changes, Anomalies, Annotations).

Built so far: Overview, Goals, Funnels and Properties, as WordPress tabs
at the top of the screen and as submenu items (links to the hash, marked
current by the app). The period, comparison, Live/Demo switch and filters
are shared by every section. Choosing a breakdown row, goal or funnel step
filters every report by it; choosing it again takes the filter out.
Administrators add, change and delete goals and funnels in a modal; pages
and events seen in the last 90 days are offered as they type.

The page state lives in the URL hash (`#/pages?range=30d&compare=prev&f=…`),
so every view can be bookmarked and shared with another admin, and the
browser's back button works.

Stack, and why:

| Part | Choice | Why |
|---|---|---|
| UI | React from WordPress (`wp-element`) and `@wordpress/components` | Native look, nothing extra to download, follows the admin colour scheme |
| Build | `@wordpress/scripts` (app), esbuild (tracker) | WordPress's standard; dependency extraction makes `*.asset.php` |
| Data | TanStack Query | Caching, deduplication, background refresh; same library in a standalone app |
| Tables | TanStack Table (headless) in WordPress table styles | Sorting and paging logic shared across apps |
| Charts | uPlot (time series), hand-made SVG (sparklines, bars, flow, map) | 20 KB, fast with thousands of points, framework-free for every future app |
| Schemas | Valibot in `@seoprostats/core` | Small; validates URL state and API answers in every app |
| Language | TypeScript, strict | One contract from the OpenAPI file to the screen |

Styles use CSS custom properties from `DESIGN.md` and
`--wp-admin-theme-color`, `{css}` = `spst` class prefix, no CSS framework.
Every chart has a table view for screen readers.

## Integrations

| Integration | How | Stored in |
|---|---|---|
| Search Console | Service-account key (or an OAuth client the owner makes); daily job for the day three days back, 16-month backfill on connect; pages, queries and pairs by impressions | `gsc_*` |
| Changes | WordPress hooks: post publish, update, unpublish, title, slug, content (word diff from snapshots), SEO title, description and robots of the common SEO plugins, plugin and theme updates and switches, core updates, key settings (`blog_public`, permalinks) | `changes`, `snapshots` |
| Search engine updates | Google Search Status Dashboard incidents feed, daily | `changes` |
| Backlinks | Referrers verified by fetching the referring page; optional provider (DataForSEO) with the owner's key | `links` |
| Mentions and spikes | Referral spike check every 3 hours (≥ 3× the 14-day daily average and ≥ 50 visits); search of the platform behind the referrer (Hacker News, Reddit, Bluesky, YouTube) | `changes` |
| Uptime and gaps | Hourly check that hits arrive at their usual rate; optional outside monitor webhook | `incidents` |
| Alerts | Email and webhook (Slack, Discord, any URL): weekly and monthly reports, spikes, drops, page moves, incidents | option |

## Reuse outside WordPress

The standalone app (later, its own repository) reuses, unchanged:

- `docs/api/openapi.yaml` and `@seoprostats/core`: the same routes,
  answers, filters, ranges and metric definitions, so dashboards, CLI and
  agents speak one language whether the data is in WordPress or not.
- `@seoprostats/tracker`: one script for every platform.
- `@seoprostats/charts`: the same charts in a web app, an extension popup
  or a mobile web view.
- The table layout above as the reference schema (the app may use
  Postgres or ClickHouse; the columns and meanings stay the same).

Only the UI layer changes per platform (WordPress components here, shadcn
or native elsewhere).

## Performance budgets

- Visitor pages: no database query, option write or remote request added;
  the inline tracker under 4 KB compressed.
- Collector: under 5 ms on the fast path; no WordPress load.
- Processor: 5,000 hits per batch, 20-second budget.
- Dashboard: first answer under 1 second for 30 days on 1 million
  pageviews a month, from summaries; filtered views under 3 seconds.
- The smoke test (`scripts/smoke-test.sh`) must show no full table scan in
  the plugin's queries.
