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
and CRO work, then measure the result. How the plugin turns its data into
audits, a ranked plan and measured experiments is designed in
`docs/seo-loop.md`.

Principles, in order:

1. **Private by design.** No cookies, no stored IP addresses, no
   cross-day visitor identity, no typed form values. Visitors are counted
   with a daily, per-site hash whose salt is destroyed after two days.
2. **Costs nothing when idle, little when busy.** Visitor pages never wait
   on the plugin. A hit is appended to a file without loading WordPress;
   cron does the work in batches. Every query uses an index; reports read
   daily summaries where they can and cache answers.
3. **The owner's data, in the owner's database.** Outside services are
   opt-in and only add facts (Search Console, Bing Webmaster Tools, backlinks, mentions, a
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
Bing, backlinks,                                            │
mentions                                                    │
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

One script, under 4 KB compressed, printed inline in the footer of front-end
pages (no extra request, nothing for blockers to match by file name), or
served as a file when a page cache or CSP needs it. Speed measurements load
a second small script only on sampled page loads.

It sends, with `navigator.sendBeacon` (fallback `fetch` with `keepalive`),
batched and as `text/plain` so no CORS preflight:

| Type | When | What |
|---|---|---|
| `pv` pageview | load, SPA navigation (`pushState`, `replaceState`, `popstate`; hash routes when enabled) | path and allowed query parameters, referrer, UTM tags, screen width, time zone, language, page properties (`data-props`); the page as loaded also sends its context (`data-ctx`: not found, site search with its words and result count, the post or other single item shown, logged in) |
| `eng` engagement | page hidden or left | visible seconds, deepest scroll %, for the pageview it follows |
| `e` event | `seoprostats('Name', {props, revenue})`, outbound links, affiliate links, file downloads, `data-sps-event` attributes | name, up to 30 properties (300 characters each, scalars only), revenue as `{amount, currency}` |
| `c` click / `f` form | clicks on things made to be clicked (links, buttons, `role=button`…), images and elements shown with a pointer; form submits (autocapture, Settings → Tracking) | `tag#id.class` selector (names with three digits in a row left out), visible label (60 characters), destination (a path here, origin and path elsewhere, `mailto:`/`tel:` without the address), flags (dead, outbound, affiliate, file); a form's name, destination and field count. Never field values. |
| `v` vitals | sampled page loads, on leave | LCP, INP, CLS, FCP, TTFB and the element or script behind each |
| `x` error | uncaught errors and rejections | type, message, top 20 stack frames without query strings; at most 10 per page |

Autocapture never sends what someone types or picks: clicks inside form
fields (inputs, selects, labels, options, editable text, checkbox and
radio roles) are not sent at all, a form submit carries only how many
fields it has, labels have email addresses and long numbers masked (again
on the server), and an element under `data-sps-mask` sends no label. A
click is **dead** when the page shows no reaction within a second: no
change to the page (a `MutationObserver` while clicks wait), navigation,
hash change, scroll or focus change. Links that leave the page are never
dead. **Affiliate links** are links to the site's own forwarding paths
(Settings → Tracking, default `/go/*` and `/recommends/*`) or with
`rel="sponsored"`; they send an `Affiliate link` event (with the
destination as `url`) instead of `Outbound link`, so a goal can count them.

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

The page's context (`SEOProStats_Tracker::context()`, in `data-ctx`) comes
from the request's own main query only: `is_404()`, `is_search()` with the
search words and `found_posts` (first page of results only), the queried
object's ID on single items, and `is_user_logged_in()`. It names the item
shown but never its author or category: the processor looks those up, so
visitor pages pay nothing and a forged hit cannot set them. Search words
are left out when Settings → Tracking says so; page addresses never keep
the `s` parameter.

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

### Purchases

`SEOProStats_Purchases` turns paid orders into one `Purchase` event each:
revenue `{amount, currency}` (the order's total in its own currency) and
properties `source` (`woocommerce`, `edd`, `fluentcart`, `thrivecart`)
and `items`. No order number, customer name, email or address is stored.
Free orders are not purchases. Settings → Tracking → Record purchases
(on by default); the `seoprostats_purchase` filter can change or drop one.

- **Shops on the site** (WooCommerce, Easy Digital Downloads 3,
  FluentCart). When an order is placed in the customer's own checkout
  request (`woocommerce_checkout_order_processed`,
  `woocommerce_store_api_checkout_order_processed`, `edd_built_order`,
  `fluent_cart/order_created`), the visitor hash is worked out as the
  collector does (today's salt, address, user agent, host; the same
  exclusions: collection off, excluded addresses and roles, Do Not Track
  and Global Privacy Control when respected) and kept in the order's meta
  with the time, the user agent, the country header and the checkout page's
  path. When the order is paid, then or later (`woocommerce_payment_complete`,
  `woocommerce_order_status_processing|completed`, `edd_complete_purchase`,
  `fluent_cart/order_paid`), one line with that hash and the checkout time
  goes into the buffer, so the processor joins the event to the visit like
  any other; the meta is replaced by a recorded mark, so the order counts
  once. Orders made in wp-admin, by cron (subscription renewals) or through
  an API have no checkout visit and are left out.
- **ThriveCart** (checkout on its own domain). With the account's secret
  word saved, the tracker's config has `tc`, and a clicked link to
  `*.thrivecart.com` gets the page load's random ID as `passthrough[spst]`.
  ThriveCart's account webhook (`POST /wp-json/seoprostats/v1/thrivecart`,
  form-encoded; GET and HEAD answer 200 for its address check) is checked
  against the secret word (`hash_equals`), takes `order.success` (amounts
  in cents; test mode skipped unless `seoprostats_thrivecart_test_orders`),
  looks the page load up in `pageviews` by its unique `pkey`, and writes
  the line with that visit's hash and the page load's time. The last 500
  order IDs are kept (`seoprostats_purchases`, autoload off) so a retry
  counts once; orders without a known page load (embedded checkouts,
  checkouts on a custom domain, links shared elsewhere) are counted there,
  shown by `wp seoprostats doctor`, and not made into visits.

Lines written this way carry `s: 1` (the collector never sets it): one
without a user agent of its own (ThriveCart's) is not taken for a bot.

Refunds produce a separate `Refund` event with a positive amount, on the
purchase's original daily hash and checkout time. Recorded marks now keep
that visit, currency, path and event properties as JSON (still truthy); the
checkout's user agent and country are removed. Refunds keep the purchase's
properties so source and other property revenue agree. WooCommerce marks
each refund order once, EDD 3 marks each refund order once, and FluentCart
remembers refund transaction IDs in the original order's recorded mark.
Only successful buffer writes set these marks; filtered-out purchases and
failed writes can be retried. A per-site, connection-owned database advisory
lock serializes receipt checks, buffer appends and recorded marks across
concurrent shop callbacks; it is released on every exit and automatically
when the connection closes. State and meta are re-read under the lock.
The lock waits at most ten seconds; if unavailable, no event or mark is
written. This prevents overlapping callbacks from double-counting, but the
file buffer and shop meta are not one transaction: a crash after appending
and before saving the mark can still require reconciliation.
Legacy integer marks have no visit and cannot
be safely attributed, so refunds for those purchases are skipped.

ThriveCart retains private receipts for the last 500 order IDs in its
existing non-autoloaded state. `order.refund` uses `refund.amount` in cents
and the stable `webhook_id` (or legacy `event_id`) for deduplication;
`refund.id` identifies the product, not a refund. Unknown orders are
ignored, and refunds without a delivery identity fail rather than guessing.
Receipts and refund identities expire together when an order leaves the
bounded list. Order and refund identities never enter the statistics.

The processor rejoins historical server-side Refund events to the visit
containing the original timestamp, even if a later visit used that day's
hash. If the original visit has been pruned, it drops the refund instead
of inventing a visit. Refunds revise the original purchase period, not the
day money was returned. Purchase goal revenue and property revenue subtract
the positive Refund amounts per currency; purchase completions are unchanged.
Renewals are not recorded yet: subscription extension payloads still need
verification; ThriveCart's account webhook documents
`order.subscription_payment`, not `order.rebill_success`.

### Changes

`SEOProStats_Changes` keeps the change log (`changes`, schema v6): what
changed on the site, when, and on which page, for chart markers and to
explain why traffic, rankings or sales moved. Rows are written by WordPress
hooks inside the requests that make the change (saving a post, product,
coupon or setting; installing or updating), never on a visitor's page
view, and are kept for good. Changes are site facts: no visitor data is
involved, and the user is only kept for people who can edit posts (a
customer whose order sold the last item is not named).

| Group | Kinds | Hooks |
|---|---|---|
| content | `published`, `unpublished` (draft, private, pending, trash, deleted), `address` (old and new path), `title`, `content` (words added and removed, word count before and after), `links` (internal links added, removed, or with new anchor text), `external_links` (hosts gained and lost) | `pre_post_update` keeps the published address; `transition_post_status` (also scheduled posts going live); `post_updated` (before and after); `before_delete_post`. Links from `post_content` (`<a href>`; internal: no host or one of the collector's hosts) |
| seo | `seo_title`, `meta_description`, `robots`, `canonical` | Post meta of Yoast SEO, Rank Math, SEOPress and The SEO Framework (`SEOProStats_Changes::SEO_META`), before and after |
| product | `out_of_stock`, `back_in_stock`, `price_up`, `price_down` (regular, sale or active price, with the currency), `sale_started`, `sale_ended`, `coupon_published`, `coupon_changed`, `coupon_removed` | `woocommerce_before_product_object_save` and its variation hook (stored values from `get_data()`, new from `get_changes()`; a sale scheduled for later starts when WooCommerce's cron sets the price); `woocommerce_before_coupon_object_save` and coupon status; Easy Digital Downloads `edd_price` meta |
| site | `plugin_installed`, `plugin_updated`, `plugin_activated`, `plugin_deactivated`, `plugin_deleted`, `theme_switched`, `theme_updated`, `core_updated`, `search_visibility` (`blog_public`), `permalinks`, `site_address` (`home`, `siteurl`), `front_page` | `upgrader_pre_install` keeps the version before; `upgrader_process_complete`; `activated_plugin`, `deactivated_plugin`, `delete_plugin`/`deleted_plugin`; `switch_theme`; `_core_updated_successfully`; `update_option_{name}` |
| search | `search_update` (kind 60) | The daily job, when switched on (Search engine updates below) |

Changes only reach the page they affect (`path_id`, the post's permalink
path as the processor stores page paths); site-wide ones have 0. Imports
(`WP_IMPORTING`), autosaves, revisions, media and non-public post types are
skipped, as are new meta on a post published in the same request. A
change is written once per request; one post's changes in one save share
a time. The `seoprostats_record_change` filter can change or drop one.
Kind codes never change meaning: 60 and up are kept for search engine
updates (feeds) and 80 and up for notes (80 annotations, 81 an
experiment's start).

`GET /markers` answers the range's changes oldest first (at most 1,000) and
`GET /changes` newest first with paging; both take `page` (that page's
changes and the site-wide ones) and `kinds` (names or groups). Each
answer row carries a `label` made when read, in the site's language.
`wp seoprostats changes` lists them. FluentCart prices are kept in its own
tables without a save hook for products, so they are not recorded yet.

Notes (annotations) are rows of kind 80 (`note`, group `note`, source 6,
`object_type` `note`) with the text in `new` (190 characters) and the user
who added it: `POST /annotations`, `wp seoprostats annotate` and the
`seoprostats/annotate` ability write them; only they can be deleted
(`DELETE /annotations/{id}`, `annotate --delete`), as recorded changes are
the site's history. Both need `manage_options`.

Experiments (schema v8, `SEOProStats_Experiments`; design and measuring:
`docs/seo-loop.md` → Experiments) record a change and what it should do
before the result is known, then measure the pages before and after it
against unchanged pages, with the usual spread, the data needed and the
confounders, and suggest keep, revise, undo or inconclusive; a person or
agent decides, and the decision keeps the measurement it was made on.
Each writes a row of kind 81 (`experiment`, group `note`) at its start,
so it shows on the markers lane and in Changes; deleting the experiment
deletes that row. A measurement is cached like the reports. `GET
/experiments` and `GET /experiments/{id}`, `wp seoprostats experiments`
and the `seoprostats/experiments` ability read them; `POST /experiments`,
`POST` and `DELETE /experiments/{id}` and `seoprostats/experiment-record`
(`manage_options`) add, decide, note, cancel and delete them. The
dashboard has them under Search → Experiments, and each row of Changes
with a page can start one.

The decision queue (schema v9, `SEOProStats_Queue`; design:
`docs/seo-loop.md` → Decision queue) ranks what Opportunities finds (low
CTR, missing from the page, striking distance, losing clicks, overlapping
pages), the content audit's findings and internal links as one list:
potential clicks per 28 days × value (the page's conversion rate of visits
from search against the site's) × confidence ÷ effort, each part in the
answer. Items are worked out when the list is read from the cached
reports; only those someone accepted, did, dismissed or gave an effort
or note are stored in `queue`. New items on a page with a running
experiment are left out (an overlap item when any of its pages has one);
done opens an experiment on the item's page (an overlap's: all its pages)
with the kind's measure. `GET /queue`, `wp seoprostats queue` and the
`seoprostats/queue` ability read it; `POST /queue/{key}`, the queue
actions of the command and `seoprostats/queue-update` (`manage_options`)
act on an item. The dashboard has it under Search → Plan.

The content audit (schema v10, `SEOProStats_Audit`; design:
`docs/seo-loop.md` → Content audit from WordPress) reads facts about each
published post from WordPress (the title and description shown, from the
SEO plugin or the post, H1s, words, images without alt text, noindex and
a canonical address elsewhere) into `page_facts`: at the end of a request
that saved a post or changed its SEO fields, and in the daily cron
(`seoprostats_daily`, at most 200 posts in 20 seconds), never on visitor
pages. The report reads `page_facts` by its `flags` key (pages with a
finding of their own) and its hash keys (shared titles and descriptions),
adds each page's search figures from `gsc_pages`, and lists pages most
impressions first; each finding is also a queue item (kind `audit`).
`GET /audit`, `wp seoprostats audit` and the `seoprostats/audit` ability
read it; `wp seoprostats audit run` reads a batch now. The dashboard has
it under Search → Audit.

Internal links (schema v11, `SEOProStats_Links`; design:
`docs/seo-loop.md` → Internal links) are read with the audit's facts,
from the same text: `page_links` holds one row per page and page it
links to, replaced each time the page is read, and `page_facts.links_in`
counts the other pages linking in, recounted for the pages whose links
changed. Orphans and converting pages with few links in are read by the
`links_in` key; missing links come from the `gsc_pairs` pairs with most
impressions (as overlapping pages), checked against `page_links` by its
primary key; conversions come from the Content report. Each row is also
a queue item (kind `links`). `GET /links`, `wp seoprostats links` and
the `seoprostats/links` ability read it; the dashboard shows it under
Search → Audit, after the findings.

The dashboard reads `GET /markers` with the chart's range and, when the
reports are filtered to one page (`is`, `matches` or `contains` with one
value), that page. `packages/charts/src/markers.ts` draws the lane under the
plot: each change goes to the point (day, hour or month) it falls in;
markers closer than 18 px, or whose pills would touch, merge, showing
their count. Markers are buttons in one tab stop (arrow keys, Home and End
move), with the changes as their accessible name; the screen-reader table
has a Changes column. Choosing one opens its changes (already loaded) in a
modal (`ChangesModal`, the shared `ChangesTable`); **Open in Changes** goes
to `#/changes?range=custom&from=…&to=…`, with `page` when the chart is for
one page. Group colours are the `--spst-mark-*` variables (`common.css`).
The Changes section reads `GET /changes` 50 at a time.

### Search engine updates

`SEOProStats_Search_Updates` puts search engine updates on the timeline,
so a change in traffic can be weighed against them. It is **opt-in**
(Settings → Data → Show search engine updates, off by default): while it
is off, nothing is fetched. When on, the daily cron job
(`seoprostats_daily`, scheduled by an admin page, first run a minute after
the setting is switched on) makes one request to each source with
`wp_safe_remote_get` (public addresses only, 10-second timeout, 2 MB at
most, no cookies, a user agent naming the plugin but not the site).
Nothing about the site or its visitors is sent. Never on a visitor page.

| Source | Address | What |
|---|---|---|
| Google | `https://status.search.google.com/incidents.json`, the Search Status Dashboard's JSON history (an array, newest first; `begin` and `end` in RFC 3339; `end` missing while it rolls out) | Ranking updates (product `Ranking`) with their type from the title: `core`, `spam`, `link_spam`, `helpful_content`, `reviews`, `product_reviews`, `site_reputation`, `discover`, else `ranking`; and `crawling`, `indexing` and `serving` incidents |
| Other feeds | Settings → Data → Other feeds: up to 10 RSS 2.0, Atom or JSON Feed addresses, one per line, each followed by the name to show (else its domain) | Posts whose title names an update (update, algorithm, rollout…), with a type from the title or `announcement`. Bing's webmaster blog publishes no update rollouts, so it has no built-in source; add a trusted feed here |

Each update is one `changes` row: kind 60 (`search_update`, group `search`,
source 5 feed), `path_id` 0 (site-wide), `ts` its start, `object_type` the
engine (`google`, or a key made from the feed's name), `old` the type,
`new` its id at the source (Google's incident id; for feeds an MD5 of the
post's id or address), and `meta` `{name, engine, url, ended}` (`ended`, ISO
8601 UTC, `''` while rolling out, only for updates that roll out over a
span; feeds also keep `feed`). The engine and id find a stored update,
which a later fetch brings up to date (its end, a corrected start or title)
instead of adding it again. The first fetch adds what the source lists
from the last two years (Google's history holds the recent months). A
failed source is kept in `seoprostats_search_updates` (not autoloaded) with
its error, shown by `wp seoprostats doctor` and `wp seoprostats
search-updates status`, and asked again the next day. `wp seoprostats
search-updates fetch` fetches now, even with the setting off.

The label reads like "Google: May 2026 core update (2 weeks)" or "(rolling
out)". `GET /markers` and the `seoprostats/markers` ability also return
updates that began up to 60 days before the range and were still rolling
out in it (`SEOProStats_Changes::ROLLOUT_LOOKBACK`, by key `kind_ts`); the
lane shows those on its first point. The lane draws each rollout as a bar
from its start to its end (or now), and Changes links each update to its
source.

### Search Console

`SEOProStats_Connections` keeps the outside data sources the owner
connects (Settings → Connections, `POST /connections/{source}`, `wp
seoprostats connect`), each off until connected. The first is Google
Search Console (`SEOProStats_Source_Search_Console`), through a service
account: the owner makes one in Google Cloud with the Search Console API
on, gives it a JSON key and adds its address as a user of the property
(Restricted is enough). The Connections tab lists these as six steps,
each linking to the Google page it needs
(`SEOProStats_Connections_Tab::LINKS`, as Google's own documentation
links them). Connecting signs in and lists the properties it
can read before anything is stored; without a property asked for, the
site's own is chosen (a domain property before an address one). Sign in
with Google through a relay we host is planned (issue #50); an OAuth
client each owner makes is not.

The connection is one option, `seoprostats_connections` (autoload off):
per source the credentials, encrypted with libsodium's secretbox (a
random nonce; `v1:` then base64), the settings (property, service
account address) and the job's state. The key comes from
`SEOPROSTATS_ENCRYPTION_KEY` when `wp-config.php` defines it, else from
the site's `AUTH_KEY` and `AUTH_SALT`, so new security keys mean
connecting again (the status says so). Credentials are never returned by
the REST API, WP-CLI or the screen. Sign-in is a JWT signed with the key
(RS256, OpenSSL) for a one-hour token, kept for the request.

The import job (`SEOProStats_Search_Import`, hook
`seoprostats_search_import`) is scheduled hourly only while a source is
connected, and never runs on a visitor page; the classes load only in
cron, WP-CLI, the Connections tab and its routes. Each run has a
20-second budget and one lock (option `seoprostats_search_import_lock`),
and asks:

1. **New final days**, at most every six hours and only when the newest
   imported day is more than two days old: the last ten days by date
   (Search Console's default, final data only), and the newest day with
   data is the last final one. Preliminary days are never imported, so a
   day is imported once, about three days after it ends.
2. **History**: on the first run, one request finds the property's first
   day with searches in the 16 months Search Console keeps; then the
   days from the last final one back to it, newest first. While days
   remain, another run is queued a minute later.

Each day is four requests (paged at 25,000 rows): by page, query, page
and query, and device and country, up to 5,000 pages, 5,000 queries and
10,000 pairs, the top by clicks (`seoprostats_search_import_limits`).
Pages are stored as the processor stores page paths (the same `dict`
rows, so search joins visits by `path_id`); pages of other sites in a
domain property are left out, and the addresses of one page (http and
https, with and without www) are added together. Queries are their own
dictionary kind (16). Positions are stored as position × impressions ×
100, so `SUM(pos_impr) / SUM(impressions) / 100` is the weighted average
over any days. A day's rows are replaced in one transaction, by the
primary key `(engine, day, …)`; days are Search Console's (Pacific time).

Each run that imports writes an `imports` row, and its rows carry its
id: undoing it (`DELETE /imports/{id}`, `wp seoprostats search-console
undo`) deletes them by its days through the primary key. The job does
not import undone days again; `wp seoprostats search-console reimport
--from --to` does. Changing the property starts again from the
beginning. Disconnecting forgets the credentials and stops the job; the
data stays unless asked to delete it too. A failed run keeps Google's
message in the state, shown on the tab and by `doctor`, and the next run
tries again.

### Bing Webmaster Tools

The second source and engine (`SEOProStats_Source_Bing`, engine 2),
connected with the owner's API key from Bing Webmaster Tools (Settings →
API access): no OAuth app and no outside server. The owner verifies the
site in Bing first (Bing can import it from Search Console). Connecting
lists the key's verified sites before anything is stored and picks the
one for this site's address (`--property` or the tab's list chooses
another). The key is stored and kept out of answers as Search Console's
key is; it is sent in the request address, as Bing asks, and removed
from any error message.

Bing's API answers whole periods, not single days:

| Request | Gives | Stored as |
|---|---|---|
| `GetRankAndTrafficStats` | the site's clicks and impressions by day, no position | `gsc_totals`, one row a day (device 0, country `''`) |
| `GetPageStats`, `GetQueryStats` | the top pages and queries by week: clicks, impressions, average position | `gsc_pages`, `gsc_queries` on the week's last day |
| `GetPageQueryStats` (one page) | that page's queries by week | `gsc_pairs` on the week's last day |

A weekly row dated D covers the seven days before D (checked against
the daily figures over 16 months of a live site: the best match by far),
so it is stored on D − 1; weeks end on the same weekday, found from the
newest week. Positions are real ranks (`AvgImpressionPosition`; Bing's
click position is −1, unknown). A day's position is its week's: the
average over the week's queries weighted by impressions (pages' when a
week has no queries), else the nearest week's. Bing's days are UTC.

The same job (`SEOProStats_Search_Import`) imports it with the same
lock, budget, history, undo and reimport. The first request of a run
reads the three site-wide answers once; each day then takes its part. A
day is final once its week is in (Bing gives a week some days after it
ends; with no recent weeks, nine days after the week). After the days,
pages with their queries come page by page (`PAIRS_BY_PAGE`): the 300
pages with most clicks in the range, one request each, every week of the
range at once; the range reaches 21 days further back
(`PAIR_LAG_DAYS`), since a page's queries come later than its figures.
The queue is kept in the state, so a run that runs out of time carries
on in the next; each run is an import (undo deletes its rows by its
days and id). `wp seoprostats bing status|import|imports|undo|reimport`
mirrors `search-console`.

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
   Engagement updates the pageview it belongs to. A pageview's context
   sets its flags (not found, site search, no results) and search words
   (one line, lower case, emails and long numbers masked, 100 characters),
   and a visit with any logged-in pageview counts as logged in.
7. Pages: for each address in the batch that showed a single item, the
   item is looked up in WordPress (`_prime_post_caches()`, then its
   permalink, type, author and category: the primary one an SEO plugin set,
   else the first; filter `seoprostats_page_term`) and kept in `pages`
   only when its permalink is the page's address. An address checked in
   the last hour for the same item is skipped.

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
| `sessions` | visit | `id`, `skey` (unique), `visitor`, `day`, `started`, `ended`, `pageviews`, `events`, `engaged_ms`, `entry_id`, `exit_id`, `ref_host_id`, `ref_path_id`, `channel`, `utm_*_id` (5), `country`, `region_id`, `city_id`, `lang_id`, `browser_id`, `browser_ver`, `os_id`, `os_ver`, `device`, `screen`, `source`, `import_id`, `login` (1: logged in on any of its pages; schema v5) (revenue is per event, in its currency) |
| `pageviews` | page load | `id`, `pkey` (the tracker's page-load ID, unique), `session_id`, `ts`, `seq`, `path_id`, `engaged_ms`, `scroll`, `flags` (1 not found, 2 site search, 4 no results), `search_id` (the search words; 0 when none or not recorded; schema v5) |
| `events` | custom or automatic event | `id`, `session_id`, `ts`, `seq`, `path_id`, `name_id`, `revenue`, `currency` |
| `props` | property of a pageview or event | `owner`, `owner_id`, `key_id`, `value_id`, `ts` (reports by period, and retention) |
| `clicks` | click or form submit (schema v4) | `id`, `session_id`, `ts`, `seq`, `path_id` (the page load's: clicks join it by its ID and never start or extend a visit), `kind` (1 click, 2 form), `selector_id`, `label_id`, `target_id`, `flags` (1 dead, 2 outbound, 4 affiliate, 8 file), `fields` (a form's) |
| `vitals` | measured page load | `id`, `ts`, `path_id`, `device`, `lcp`, `inp`, `cls`, `fcp`, `ttfb`, attribution ids |
| `errors`, `error_groups` | error occurrence, distinct bug | fingerprint, message, sample stack; occurrence time, page, browser |
| `bots` | crawler request | `id`, `ts`, `bot_id`, `path_id`, `status`, `verified` |
| `daily` | day × dimension × value | `day`, `dim` (0 the site, else a code in `SEOProStats_Rollup::DIMS`, or `SEARCH_LANDING` (18): visits from organic search by entry page, for the content report), `val` (the visit column's value or dict id; a country's two letters as a number), `visitors`, `visits`, `pageviews`, `bounces`, `engaged_ms`, `events`, `scroll` (pages: sums over their views) (revenue summaries come with goals, per currency) |
| `daily_vitals`, `daily_bots` | day × page × device × metric; day × bot | percentiles from the raw rows; requests, verified |
| `gsc_pages`, `gsc_queries`, `gsc_pairs`, `gsc_totals` | engine × day × page / query / page and query / device and country (schema v7; Search Console and Bing Webmaster Tools above: Bing's pages, queries and pairs are weekly, on the week's last day, and its totals have no device or country) | `engine` (1 Google, 2 Bing), `day`, `path_id`, `query_id` (dict kind 16), `device` (1 desktop, 2 mobile, 3 tablet), `country` (ISO 3166-1 alpha-3, lower case), `clicks`, `impressions`, `pos_impr` (position × impressions × 100, for weighted averages), `import_id` |
| `changes` | change to the site, a marker on the timeline (schema v6; Changes below) | `id`, `ts`, `kind` (a code in `SEOProStats_Changes::KINDS`), `path_id` (0: site-wide), `object_type` (the post type, or `coupon`, `plugin`, `theme`, `core`, `option`), `object_id`, `old`, `new` (190 characters), `meta` (JSON), `source` (1 WordPress, 2 WP-CLI, 3 API, 4 cron, 5 feed, 6 note), `user_id` |
| `snapshots` | stored version of a page | `path_id`, `post_id`, `ts`, title, description, H1, word count, text hash, compressed text |
| `pages` | address that shows one item (schema v5) | `path_id` (primary key), `post_id`, `post_type`, `author_id`, `term_id`, `seen` (when last checked; the latest view wins); later `title`, `launched`, `removed`, `status` |
| `links` | backlink | source URL and host, target page, anchor, rel, first and last seen, lost, authority, how found |
| `incidents` | outage, slowdown or collection gap | `kind`, `started`, `ended`, `meta` |
| `imports` | import run of an outside source (schema v7) | `id`, `source`, `status` (1 running, 2 done, 3 failed, 4 undone), `started`, `finished`, `day_from`, `day_to`, `rows_added`, `meta` (property, days, error); imported rows carry its id so it can be undone |
| `experiments` | a change's hypothesis, measured before and after against unchanged pages (schema v8; `docs/seo-loop.md`) | `id`, `created`, `user_id`, `name`, `start`, `days`, `review` (the after window's last day), `engine`, `metric` (1 clicks, 2 impressions, 3 CTR, 4 position, 5 visits, 6 conversions), `direction`, `threshold` (percent, or tenths of a place), `change_id`, `path_id` (0: several pages, in `meta`), `status` (1 running, 2 decided, 3 cancelled), `result` (1 keep, 2 revise, 3 undo, 4 inconclusive), `decided`, `meta` (pages, goal, hypothesis, note, the change row it wrote, the measurement decided on) |
| `queue` | a decision queue item someone acted on (schema v9; `docs/seo-loop.md`) | `id`, `ikey` (8-byte hash of kind, engine, page and query; unique), `kind` (1 CTR, 2 missing, 3 striking, 4 decay, 5 overlap, 6 audit, 7 links; an audit item's key has its finding in place of a query, a links item's its list), `engine`, `path_id`, `query_id`, `status` (0 new with an effort or note, 1 accepted, 2 done, 3 dismissed), `effort` (0: the kind's), `experiment_id`, `created`, `updated`, `user_id`, `note`, `meta` (the item as it was when acted on) |
| `page_facts` | content audit facts of a published page (schema v10; `docs/seo-loop.md`) | `path_id` (primary key), `post_id`, `checked`, `modified`, `title_len`, `seo_title_len`, `desc_len`, `title_hash`, `desc_hash` (8-byte keys of the shown title and description; zeros for none), `h1`, `words`, `images`, `images_no_alt`, `noindex`, `canonical_away`, `flags` (the page's own findings as bits, `SEOProStats_Audit::FLAGS`), `links_in` (other pages whose text links to it; schema v11) |
| `page_links` | a link in a published page's text to another of the site's pages (schema v11; `docs/seo-loop.md`) | `from_path`, `to_path` (primary key), `text_id` (the first link's text, `DICT_LABEL`), `links` (how many links) |

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
`clicks` has `ts` and `(path_id, ts)` for one page's clicks (schema v4);
`pageviews` has `(search_id, ts)` for search filters, and `pages` a key
on each of `post_type`, `author_id` and `term_id` for content filters
(schema v5); `changes` has `ts`, `(path_id, ts)` and `(kind, ts)` (schema
v6); the `gsc_*` tables have `(path_id, day)` and `(query_id, day)` for
one page's or query's search data, and `imports` `(source, status)`
(schema v7); `experiments` has `(status, review)`, `path_id` and `start`
(schema v8); `queue` has unique `ikey` and `(status, updated)` (schema
v9); `page_facts` has `post_id`, `checked`, `flags`, `title_hash` and
`desc_hash` (schema v10), and `links_in`, and `page_links` `to_path`
(schema v11);
with the primary key both cover the reports, which read only the
period's index entries, never the table rows. Add one
only for a query that needs it, after `SHOW INDEX` (`STANDARDS.md` →
Performance). `dbDelta()` only adds keys, so a key a version replaces is
listed in `SEOProStats_Schema::OLD_KEYS` and dropped on upgrade.

### Retention

SEO Pro Stats → Settings → Data sets the months for visits, events, and
clicks and form submits (switched off: kept forever); each new kind of
data adds its own there. Clicks never outlast their visits.
The `seoprostats_retention` filter applies on top (0 keeps forever).
Once a day, after the daily
summaries are up to date, cron deletes old rows in batches of 5,000 with a
time budget, from site-local midnight back, and never from a day that is
not summarised. Search data by page, query and pair has its own setting
(25 months); the import job deletes older days once a day, by the
primary key's `(engine, day)` prefix, and keeps `gsc_totals`. `wp
seoprostats prune --dry-run` counts them.

| Data | Default | Why |
|---|---|---|
| Visits, pageviews and journeys | 75 months | Quarter-by-quarter comparisons with filters over six years |
| Events, goals and revenue | 120 months | Low volume, high value |
| Clicks and forms | 3 months | High volume; most useful while a page is new or changing |
| Speed measurements | 3 months | Daily percentiles kept |
| Errors | 3 months | Groups kept 13 months |
| Crawler requests | 3 months | Daily totals per crawler kept |
| Search pages, queries and pairs (Google and Bing) | 25 months | Each engine itself keeps 16 |
| Daily summaries, search totals, changes | forever | Small; the long-term record |
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
and social, AI answers, events with properties, purchases with revenue
in three currencies, pages not found (old addresses and typos), site
searches (some finding nothing), logged-in visits, authors, categories
and post types for its pages (`SEOProStats_Demo::CONTENT`, with names of
its own, as the IDs are not the site's), and for its last three months
clicks (some dead, some on affiliate links) and form submits, and changes
for the markers (posts published and edited, a price drop and a sale,
stock running out, plugin and WordPress updates, a theme switch, and
made-up core and spam updates with their rollouts;
`SEOProStats_Demo::CHANGES`), and Search Console days in the `gsc_*`
tables, written as an import writes them, up to three days ago (final
days only): made-up queries for its pages (`SEARCH_QUERIES`), some on two
pages, whose impressions follow the traffic and whose positions climb
over the year, clicks that follow the position, and the site's totals by
device and country, with more impressions than the listed queries, as
Search Console leaves out rare ones. In the last weeks a few pages lose
clicks in their own way (`SEARCH_EVENTS`: one ranks lower after a large
edit, one is searched less, one is chosen less after its SEO title
changed, with those changes in the change log), and a few queries have a
low CTR for their position (`SEARCH_LOW_CTR`), and a few queries have a
second page (one where the second page has led for the last 40 days), so
each Opportunities card lists something. Bing days follow as the Bing import writes them
(`bing_day()`): the site's clicks and impressions each day, and on each
Thursday the week's pages, queries and pairs, from the same Google days
at about an eighth of the size and a little lower down, through the
newest week Bing would have given. The same day always gets the same
numbers; demo data made before search or Bing data, or before a change
to it (`SEARCH_VERSION`), gets them on its next top-up.
Making it is done in slices of up to ten seconds per
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

### Shared reports

`SEOProStats_Shares` keeps up to 50 shares in `seoprostats_shares`, autoload
off. Each has at most 10 ordered saved `ViewState` objects, name, note,
locked filters, last-N-days boundary, expiry, visibility switches, branding
and open counters. Every dashboard tab can be a section, each once; Search
once per engine with data (Google, Bing), showing Rankings, Opportunities,
Audit and Content (not Plan or Experiments, the owner's work list and
notes). Search data and the change log have no visits, so a share with
Search or Changes can lock only pages. PHP validates against the report engine's range/filter rules and the UI's
section choices; core `shareView()` canonicalizes with the hash reader.
Tokens have 128 random bits; only SHA-256 hashes are stored and matched
with `hash_equals`. Creation and renewal return the link once. Passwords
use WordPress hashing. Renewal rotates the secret; revocation stops access.
Security-sensitive option mutations use a connection-owned site lock and
re-read state under it, so opening cannot undo a concurrent revocation.

The public shell at `/?seoprostats_share=…` runs before theme rendering.
The hook checks only for that query argument on normal pages, with no
plugin query, option write or remote call. It prints only the share entry's
local dependencies, never theme head/footer hooks or the tracker. It sends
noindex/nofollow, no-referrer and private/no-store headers, including on
failed public API answers, and is never registered in a sitemap.

Owner `/shares` routes and `wp seoprostats share` need `manage_options`.
Public `/share/{token}` unlocks with an optional password and returns a
one-hour HMAC grant bound to the current bearer hash and password hash.
It is kept in `sessionStorage` only. Public fetches omit cookies and never
carry an admin nonce. Unknown, expired, revoked and wrong-password links
fail with the same public message. Per-secret quotas allow five unlock
attempts and 120 report requests per minute, using atomic object-cache
counters or serialized transient windows; no visitor identity is stored.

Public GET routes are separate from ordinary reports and map a section to
an explicit report allow list. They reconstruct live requests from safe
query inputs checked against the read's own route arguments, keep a Search
section to its engine, AND locked filters with viewer filters, enforce the date
boundary on both current and comparison ranges, bound paging and strip
editor URLs after the shared report cache. Realtime is suppressed for
locked shares; sensitive breakdowns and realtime referrers can be hidden.
The markers lane and the Changes section are a minimal projection without
user identities, old/new values, private notes, or plugin, theme and
setting names; a page's or product's title and a search engine update's
name, announcement and span stay, as they are public. Page locks filter
its rows; visitor locks omit it because changes have no campaign/country
attribution.

Branding uses same-host raster Media Library attachments, with no remote
fetches. Defaults are settings; each share stores its overrides. The shell
reuses the dashboard sections and their accessible tables with separate
colour variables, AA-checked accents and print styles. Uninstall removes
the share option and quota transients with the existing prefix cleanup.

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
| Revenue | sum of event revenue per currency; an exact `Purchase` goal subtracts `Refund` amounts on the original visits and purchase dates without changing conversions or completions. Property revenue for all events or `Purchase` also subtracts refunds carrying those properties; an explicit `Refund` report shows positive returned amounts. Currencies are never added together. |
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

Some page dimensions are pageviews with a flag: `not_found` (paths that
answered 404), `search` and `no_results` (search words, by `(search_id,
ts)`). `author`, `category` and `post_type` go through `pages`: a
breakdown joins it by its primary key; a filter turns the values into the
path ids that show them (matching an ID or post type name, or a name,
which is looked up when the report is made, so renames show at once) and
then works as a page filter. These, unlike `login`, are not in `daily`:
they read the fact tables, so they reach back as far as visits are kept.

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

Clicks (`SEOProStats_Clicks`) read `clicks` by `ts` (or `(path_id, ts)`
for one page), joined to their visits, with the same ranges, filters,
comparison and cache: totals (clicks, dead clicks, link, outbound,
affiliate and file clicks, forms sent, visits with any) and rows of one
kind: clicked elements (by selector and label, with their dead clicks),
dead clicks only, link destinations, file links, or forms (by name,
selector and destination, with their field count). A page filter also
narrows the clicks to that page. They reach back as far as clicks are
kept (3 months by default). The `pages` kind groups by `clicks.path_id`,
ordered by clicks, with dead clicks, dead-click rate, link clicks, forms
sent and visits on each page; it uses the existing `ts` / `path_ts` keys.
Choosing a page row narrows only Clicks; choosing it again clears the page.
An exact page (not a `*` pattern) carries `page_info` with its local address
and `url_to_postid()` identity. Only the returned page rows are resolved,
inside the report cache. Editor addresses are added after the shared cache
on every request, only with `current_user_can('edit_post', post_id)`, so
cached administrator answers never leak links to another viewer. Future
shared read-only views must omit `edit_url`. All lookups run in reporting
requests, never on visitor pages. Unknown demo paths have no editor link.

Search (`SEOProStats_Search`) reads only the imported search days
(`gsc_*`, one engine at a time: `engine=google`, the default, or `bing`;
`engines` in the answer lists those with data or connected), never the
visit tables. Bing's pages and queries come by week (`WEEKLY`), so its
range is widened at the start to the whole weeks its days fall in (cut
instead where that would start before the first day with data; any seven
days then hold one week, and a period and its comparison as many), a page's or query's
points are by week (`grain` `week`, each point the week's last day,
lined up with the newest week), and it has no devices or countries. The range's days
are cut at the newest day with search data (`through`, about three days
ago, as only final days are imported), and the comparison takes the same
number of days, so days not imported yet never look like a drop. Totals
and the points (daily, monthly past 120 days) come from `gsc_totals` for
the site, `gsc_pages` by `path_day` for a page or pattern, `gsc_queries`
by `query_day` for a query, and `gsc_pairs` for both; each read names
its key (`FORCE INDEX`, the primary key without a page or query), so a
year over most of a table still reads only its days. Rows: queries
(`gsc_queries`, or pairs by `path_day` for a page), pages (`gsc_pages`,
or pairs by `query_day` for a query), countries and devices (`gsc_totals`,
the site only), ordered by clicks, each with clicks, impressions, CTR,
the weighted position and its share of clicks, and with a comparison the
rows shown get their figures then and the change. Position changes are
in places (now − then; lower is better). Page filters narrow it like the
page box; other filters select visits, which search data has not, so the
answer names them in `ignored`. The cache key adds the newest import and
the last one finished or undone, so new days show at once. Pages rows
and an exact page get the same addresses and editor links as Clicks.

Opportunities (`SEOProStats_Opportunities`) read the same days of one
engine, with the same cut, page filters, `ignored` and cache key, and say where search
effort pays. The period is also cut to its newest 91 days (`MAX_DAYS`;
the answer gives `days` and `cut`), so a year never reads every pair.
Thresholds are constants scaled by the days read (`rules` in the answer).

- **Expected CTR** is the site's own: clicks ÷ impressions of `gsc_pairs`
  by rounded position (1–20) in the period, one query by the primary key;
  positions with fewer than 500 impressions take a cautious default, and
  the curve is made never to rise with position (`curve.source`: `site`,
  `mixed` or `default`).
- **Striking distance** (`striking`): pairs (page and query) at average
  position 4–20 with enough impressions, grouped in one read of
  `gsc_pairs` (the 2,000 with most impressions), ranked by potential
  clicks = impressions × (expected CTR at position 3 − the pair's CTR).
- **Low CTR** (`ctr`): pairs in the top 10 whose CTR is under 0.6 × the
  expected CTR at their position; missed clicks = impressions × expected
  CTR − clicks.
- **Losing clicks** (`decay`): `gsc_pages` sums for the period and the
  earlier one of the same length (previous, or a year earlier with
  `compare=year`); pages that lost at least 20% of their clicks and a
  minimum, most lost first. For the rows shown, `gsc_pairs` (by
  `path_day`) gives the queries that lost most, and the cause: `gone` (no
  impressions now), `position` (a place and a tenth lower or more), else
  whichever fell more of impressions (`demand`) and CTR (`ctr`), with a
  sentence (`why`). The change log by `path_ts` (`SEOProStats_Changes::
  on_pages()`) adds what changed on each page in both periods, and
  `updates_between()` (`kind_ts`) the search engine updates, so cause and
  effect sit together. Changes and editor links are added after the
  shared cache, as people's names and editor links depend on the viewer.
- **Missing from the page** (`missing`): pairs in the top 20 with enough
  impressions (one read of `gsc_pairs`, the 2,000 with most impressions)
  whose query the page's words do not cover, or only partly, as query
  coverage checks them (below); most impressions first. Only the 50 pages
  with most impressions among them are read (`MISSING_PAGES`, `rules.
  pages`), each once.
- **Overlapping pages** (`overlap`): queries for which two or more pages
  each get at least 10% of the impressions of its pages read
  (`OVERLAP_SHARE`, `rules.min_share`), from the same grouped read of
  `gsc_pairs` (the 2,000 pairs with most impressions; pairs too small to
  hold that share of the smallest query listed are left out in SQL). A
  candidate to review, never a fault. The row is the leading page's, with
  the query's sums; up to five pages (`OVERLAP_PAGES`) with their figures
  and share; most impressions on pages other than the leading one first.
  For the rows shown, one more read of `gsc_pairs` by `query_day` gives
  each page's impressions in the first half of the period (whole weeks
  for Bing; `halves`), the second half being the rest, so `leaders` and
  `switched` say whether the leading page changed. `potential` is the
  clicks the query would get if all its pages' impressions had the best
  of their CTRs.

Query coverage (`SEOProStats_Coverage`) checks a page's search queries
against the page's own words. A query's terms are its words, lower case
and without accents, less common short English words, lightly stemmed
(plural s); `match` is `title` (every term in the post title or SEO
title), `heading`, `text` (anywhere: text, excerpt, image alt text, SEO
description), `partial` or `none`, with the words `missing`, whether the
words are there in order (`phrase`) and whether it is a `question` (it
starts with a question word or holds a question mark).
`packages/core/src/coverage.ts` does the same, so the editor re-checks as
people write; keep the two in step. The words come from the post
(`post_content` with shortcode tags left out, unrendered, so no filters
or shortcodes run; filter `seoprostats_coverage_text` adds a page
builder's text) or, for demo data, `SEOProStats_Demo::PAGE_TEXT`. A path
finds its post in the `pages` table (by its primary key), else with
`url_to_postid()`. SEO titles, descriptions and focus keywords come from
Rank Math, Yoast SEO, SEOPress and All in One SEO (its `aioseo_posts`
table, by `post_id`) when present, and titles and descriptions from The
SEO Framework (the same meta as the change log's `SEO_META`)
(`seoprostats_focus_keywords` adds others); without one the report is the
same less `focus`. The one-page report (`GET /coverage`, by `page` or
`post`) reads that page's queries by `path_day` (the 200 with most
impressions, newest 91 days) and its post once; the cache key adds the
post's modified time. Nothing runs on visitor pages.

Content (`SEOProStats_Content`) joins search with what its visits did, per
page, with the same cut, page filters, `ignored` and cache key (which
adds the oldest day with search landings). Three reads, each by an index
and none growing with all the visits:

- **Search**: `gsc_pages` sums per page, by the primary key (or
  `path_day` with page filters): clicks, impressions, CTR, position.
- **Visits from search**: the daily summaries' search landings
  (`SEOProStats_Rollup::SEARCH_LANDING`, by `dim_val_day`): visits from
  organic search (any engine) by entry page, with their visitors,
  pageviews, bounces, time and events. The rollup writes them with each
  day; days summarised before they existed are filled in, newest first,
  by `SEOProStats_Rollup::refill()` within the cron budget, down to the
  newest of the first search day, the first visit and the oldest visit
  kept (`landings_floor()`). The state's `landings` is the oldest day
  done; until the period's days are, the answer says `partial`.
- **Conversions**: one goal at a time (the first, or `goal`): its hits by
  `name_ts` or `path_ts`, joined to their visits by the primary key and
  kept to organic search, counted once per visit by entry page
  (`SEOProStats_Conversions::by_entry()`); so the cost is the goal's hits
  in the period. Only pages with visits from search count them.

Rows are every page with clicks or visits from search, ordered by
`sort` (clicks, visits or conversions), then clicks, visits and
impressions; with a comparison the rows shown get their figures then and
the change. Search figures are the chosen engine's (`engine`), visits
any engine's, so the two differ.

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
  `changes`, `goals`, `funnels`, `properties`, `clicks`, `search`,
  `opportunities`, `coverage`, `content`, `experiments`, `queue`, `demo`,
  `view`, and for settings administrators `connections` (`GET`; `/{source}` to
  read, connect or disconnect; `/{source}/import` to import now) and
  `imports/{id}` (`DELETE` undoes one); planned: `pages`,
  `page`, `flow`, `journeys`, `vitals`, `errors`, `bots`,
  `backlinks`, `anomalies`, `health`, `annotations`, `segments`,
  `export`, `import`, `collect`.
- **WP-CLI**, `wp seoprostats <command>` with `--format=json|csv|table`:
  `stats`, `breakdown`, `goals`, `funnels` (each `list`, `add`, `update`,
  `delete` too), `properties [<key>]`, `clicks [<kind>] [--page=<path>]`,
  `changes [--page=<path>] [--kind=<kinds>]`, `search [<kind>]
  [--page=<path>] [--query=<query>]`, `opportunities [<kind>]`, `coverage
  <page|post> [--missing] [--questions]`, `content [--sort=<sort>]
  [--goal=<id>]`, `experiments` (`list`, `add`, `show`, `decide`,
  `cancel`, `note`, `delete`), `queue` (`list`, `accept`, `done`,
  `dismiss`, `restore`, `effort`, `note`), `audit` (`list
  [--finding=<finding>]`, `run [--limit=<n>]`), `links [--kind=<kind>]
  [--goal=<id>]`, `pages`,
  `annotate`, `import`, `export`, `process`, `rollup`, `prune`, `doctor`,
  `demo` (`make`, `status`, `remove`), `connect <source>
  [--key-file=<file>] [--property=<property>]`, `disconnect <source>
  [--delete-data]`, `search-console` (`status`, `import`, `imports`,
  `undo --id`, `reimport --from --to`); reports and definitions take
  `--data=demo`.
- **Abilities** (WordPress 6.9+, guarded with `function_exists()`): the
  read reports and annotations as `seoprostats/*` abilities, so MCP
  clients reach them through the WordPress MCP adapter. So far
  `seoprostats/markers`, `seoprostats/annotate`, `seoprostats/search`,
  `seoprostats/opportunities`, `seoprostats/audit`, `seoprostats/links`,
  `seoprostats/coverage`,
  `seoprostats/content`, `seoprostats/experiments`,
  `seoprostats/experiment-record`, `seoprostats/queue` and
  `seoprostats/queue-update`.

## Dashboard app

A top-level **SEO Pro Stats** menu at position 3 (where site statistics
usually sit) opens one admin page,
`admin.php?page=seoprostats-dashboard#/overview`, holding the React app
(`includes/admin/class-seoprostats-dashboard.php`) under the settings
screen's header (`SEOProStats_Admin_Manager::render_header()`, with its
own small stylesheet, so the app's classes keep their styles). Submenus
link to its sections. The last one is **Settings**, the starter's settings screen
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

The post editor (`includes/admin/class-seoprostats-editor.php`, the
`editor` entry) shows a post's search queries to people who can read the
statistics, on post types with public pages (filter
`seoprostats_editor_coverage`): a **Search queries** panel in the block
editor's document sidebar (`wp.plugins` and `PluginDocumentSettingPanel`,
read from the page), or a meta box in the classic editor. It asks
`GET /coverage?post=<id>&range=90d` once, then re-checks the queries
against the words in the editor a moment after each change, so a query
turns covered as soon as its words are added: the summary, the focus
keywords, the queries not covered and the questions, with a link to the
page in Search.

Built entries are `assets/build/dashboard.js`, `widget.js`, `editor.js`
and `share.js` (the shared report page); their `*.asset.php` files list WordPress's scripts as
dependencies, so React and `@wordpress/components` are not bundled. WordPress before 6.6 has no
`react-jsx-runtime` script; the screen then adds a small stand-in built
on WordPress's React.

Sections: Overview · Behaviour (Flow, Journeys, Clicks, Funnels, Goals,
Properties) · Pages (All, New, Not found, Site search, page detail) ·
Search (Rankings, Opportunities, Content, Backlinks) · Health (Speed, Errors,
Crawlers, Uptime) · Changes (Changes, Anomalies, Annotations).

The Overview's cards: Sources; Pages (top, entry, exit, not found);
Content (authors, categories, post types); Site search (searches, no
results); Locations; Devices (devices, browsers, systems, logged in);
Events.

Built so far: Overview, Search (Rankings, Opportunities, Content), Goals,
Funnels, Properties, Clicks and Changes, as the settings screen's tabs
under the header (drawn by the server, `SEOProStats_Dashboard::render()`)
and as submenu items: links to the hash, which the app marks current and
whose tabs keep the period and filters. The period, comparison, Live/Demo switch and filters
are shared by every section; all but the filters sit on the tab bar's
right (the app renders them into `#spst-dashboard-controls`). Search has three tabs. Rankings shows clicks,
impressions, CTR and average position as tiles that pick the chart's
metric (the Overview's chart, with the markers lane), then queries,
pages, countries and devices; choosing a page shows its queries and
choosing a query its pages. Opportunities has five cards (striking
distance, low CTR, losing clicks, missing from the page, overlapping
pages), ten rows a page; choosing a row opens
it in Rankings with its page and query. Losing clicks is always against
an earlier period (the previous one unless the same period last year is
chosen). Content shows search clicks, visits from search, their bounce
rate and conversions of a goal (chosen in a list) as tiles, then the
pages with clicks, position, CTR, visits, bounce rate, time, conversions
and conversion rate, 25 a page; the Clicks, Visits and Conversions
headers sort; choosing a page opens it in Rankings. Before Search
Console is connected all three link to Settings → Connections. Choosing a breakdown row, goal or funnel step
filters every report by it; choosing it again takes the filter out.
Administrators add, change and delete goals and funnels in a modal; pages
and events seen in the last 90 days are offered as they type.

The view state lives in the URL hash (`#/clicks?kind=dead&page=%2Fshop%2F`),
so a bookmark, a reload, a copied address or the back button brings back
the same view. It holds the section; the shared values (period and custom
days, comparison, chart metric, filters); and the section's own choices:

| Section | Address | Default (left out) |
|---|---|---|
| Overview | `tab.sources`, `tab.pages`, `tab.content`, `tab.search`, `tab.locations`, `tab.devices`: the card's open tab | each card's first tab |
| Search | `report` (rankings, opportunities, content), `tab` (queries, pages, countries, devices), `chart` (clicks, impressions, ctr, position), `page`, `query`; with Content, `sort` (clicks, visits, conversions) and `goal` (a goal's ID) | rankings, queries, clicks, none; clicks, the first goal |
| Properties | `key` (the property listed), `event` | none |
| Clicks | `kind` (elements, dead, links, downloads, forms, pages), `page` | elements, none |
| Changes | `page` (else the page the reports are filtered to) | none |

Only applied choices count: text in a box is a draft until Apply or Enter.
Changing section keeps the shared values and leaves the other section's
choices behind (`switchView()`). Every value is checked against its list,
or for pages, queries, properties and events, as trimmed text without
control characters, at most 2048 characters; anything unknown or invalid
takes the default, and defaults are left out, so older addresses keep
working and addresses stay short.

This is also the format of a shared read-only dashboard (Phase 6 of
`todo/PLANS.md`): `ViewState` with the pure `parseHash()`, `buildHash()`
and `switchView()` in `packages/core/src/state.ts`, free of WordPress and
React, so the server can check a stored view with the same rules. It holds
only what draws the view: no user IDs, nonces or settings. The Live/Demo
switch is a per-user setting outside the address (a shared view always
shows live data).

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
| Search Console | Opt-in: a service account's key, stored encrypted (Sign in with Google through our relay later, issue #50); hourly job for new final days, 16-month history on connect, newest first; pages, queries, pairs and device × country totals (Search Console above) | `gsc_*`, `imports` |
| Bing Webmaster Tools | Opt-in: the owner's API key, stored encrypted; the same hourly job, each week once Bing gives it, 16-month history on connect; the site's clicks and impressions by day, pages and queries by week, then each top page's queries (Bing Webmaster Tools above) | `gsc_*` (engine 2), `imports` |
| Changes | WordPress, WooCommerce and Easy Digital Downloads hooks (Changes below); later page snapshots for word diffs and page detail | `changes`, `snapshots` |
| Search engine updates | Opt-in: Google Search Status Dashboard's JSON history and the owner's other feeds, daily (Search engine updates above) | `changes` |
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
