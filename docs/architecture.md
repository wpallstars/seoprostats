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

### Research facts

Schema v21 adds nullable `allintitle` and `volume` to the bounded targets table,
with separate YYYY-MM-DD measurement dates. Missing is not zero. Imports validate
whole non-negative counts and real, non-future dates before writing; omitted
research fields preserve earlier values. Existing primary-key reads cover them;
no new scan or visitor-page work is added. The targets report derives unrounded
KGR and its band from these facts, shared by REST, CLI and abilities. The dashboard
sorts/filters the complete bounded list before paging. Providers are not called
automatically; future provider integrations must supply actual observations,
never infer monthly volume from impressions.

`packages/core/src/research.ts` builds allowlisted HTTPS search URLs and describes
the operator templates without WordPress or React. Research menus navigate only
when clicked, in a new tab without an opener or referrer. Unsupported operators
are disabled; allintitle measurements always refer to Google, regardless of the
engine chosen for the report. Site-restricted links use the home URL's hostname,
not the wp-admin origin. See README → Research and the Keyword Golden Ratio.

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

## IndexNow notifications

`SEOProStats_IndexNow` is opt-in (`indexnow`, Settings → Data, false).
`SEOProStats_Changes::record()` passes successfully inserted live page-change
rows and their primary keys to the queue; demo writes and imports do not send.
Kinds 1–7 and 10–13 cover publication, removal, moves, titles, content, links
and SEO fields. Moves include the old path; removals already carry it.
Addresses retain the site's subdirectory and are validated against its exact
host. WP-CLI accepts only same-host HTTP(S) addresses without credentials or
fragments. A subdirectory key covers only that directory's addresses.

The non-autoloaded `seoprostats_indexnow` option keeps deduplicated pending
URLs with change IDs, recent attempts and the last 100 receipts. Atomic
compare-and-swap writes preserve concurrent edits; contention retries use
`seoprostats_indexnow_enqueue` single events. The minute processor calls
`run()`, which refuses ordinary requests and takes an atomic sender lock.
Before its single POST (up to 10,000 URLs, three-second timeout, no redirects)
it reserves each URL's hourly allowance. Failures remain queued; successful
submissions remove only the change IDs in their snapshot, preserving new edits.
Receipts update live change metadata by primary key (`meta.indexnow`), never
a scan. The public status route requires settings permission and reads only.

`seoprostats_indexnow_key` holds a random 32-character key generated on a save
or first submission. A generic rewrite serves its virtual `.txt` file; normal
visitor requests never read this option or the queue. Rules are flushed only
on an opted-in settings save if missing. Opt-out clears the queue/retry events
and makes the key return 404, retaining receipts. Uninstall deletes all three
options and retry events. No filesystem write or visitor information is involved.
200/202 mean receipt, not indexing; Google does not participate.

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
| `pv` pageview | load, SPA navigation (`pushState`, `replaceState`, `popstate`; hash routes when enabled) | path and allowed query parameters, referrer, UTM tags, screen width, time zone, language, page properties (`data-props`); the page as loaded also sends its context (`data-ctx`: not found, site search with its words and result count, the post or other single item shown, logged in), and the A/B test variants it shows (`ab`: up to 20 `test:variant` from `data-spst-ab`) |
| `eng` engagement | page hidden or left | visible seconds, deepest scroll %, for the pageview it follows |
| `e` event | `seoprostats('Name', {props, revenue})`, outbound links, affiliate links, file downloads, `data-sps-event` attributes | name, up to 30 properties (300 characters each, scalars only), revenue as `{amount, currency}` |
| `c` click / `f` form | clicks on things made to be clicked (links, buttons, `role=button`…), images and elements shown with a pointer; form submits (autocapture, Settings → Tracking) | `tag#id.class` selector (names with three digits in a row left out), visible label (60 characters), destination (a path here, origin and path elsewhere, `mailto:`/`tel:` without the address), flags (dead, outbound, affiliate, file), the A/B test variant it is inside (`ab`); a form's name, destination and field count. Never field values. |
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
server (Processing → Sessions). The one opt-in exception is not the
tracker's: Settings → Tracking → one A/B test variant per visit (off by
default) lets the A/B test swap script keep the variant shown in the
tab's `sessionStorage` (A/B tests below).

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
Paid renewals from WooCommerce Subscriptions (the paid renewal order in
`woocommerce_subscription_renewal_payment_complete`), FluentCart's
`fluent_cart/order_paid_done` and ThriveCart's `order.subscription_payment`
are daily counters, **not events or visits**. EDD Recurring is not supported
until its extension's dispatch contract can be verified. Initial purchases,
failed payments and disabled collection do not increment counters. Existing
ThriveCart authentication and test-mode opt-in apply to renewal webhooks too.

The existing non-autoloaded state holds `renewals[day][currency]` with
`count` and integer-cent `amount`. Days are the site-local **reception day**:
delayed callbacks are not backdated, since not every provider supplies a
confirmed payment timestamp. A provider-namespaced payment identity is
SHA-256 hashed into private `renewal_ids`; raw order/invoice/customer details
are not retained in counters or exposed in reports. WooCommerce and
FluentCart use unique renewal order IDs; ThriveCart requires its account,
order and renewal-specific invoice identity, never its product/subscription
ID. Receipt and increment share one option update under the existing lock.
At most the current day and preceding 399 days survive the next accepted
renewal; reads exclude older days even while idle. At 10,000 unexpired
receipts new payments fail closed rather than evicting live identities and
double-counting retries; doctor warns about that capacity. After the 400-day
receipt horizon a replay can count again. Uninstall deletes the state.

`GET /goals` adds `renewals = {scope, days, totals}` with main-unit amounts
and separate currency totals for the requested full days. `range=all`
includes retained renewal days even before the first recorded visit.
Visit-filtered, demo, realtime and 24-hour requests return
`scope: unavailable` and empty arrays, never unfilterable live amounts.
Renewals do not join comparison goals or Purchase revenue/completions.
Doctor reports retained counts and amounts per currency independently of
the goal definitions. No visitor-page queries or option writes are added.

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
with the kind's measure. The refresh planner (`SEOProStats_Refresh`;
design: `docs/seo-loop.md` → Refresh planner) turns a losing page whose
content facts the audit has into a `refresh` item in place of its `decay`
item: update, leave, protect or merge, from the cause, the content's age
and words (`page_facts` by its primary key), its conversions and the page
that overtook it for a query (losing clicks' `rival`); proposals only,
and done on leave opens no experiment. Search targets (schema v13,
`SEOProStats_Targets`; design: `docs/seo-loop.md` → Search targets) add
`target` items: an open target shown with another page than the one
meant for it, or a high-priority one in striking distance in place of its
striking item, weighted by priority. The targets are imported lists
(`targets`, read whole by its primary key, at most 1,000); their report
reads `gsc_queries` and `gsc_pairs` by `query_day` for their queries
only. Besides a pasted list, they come from the SEO plugin's focus
keywords (`SEOProStats_Target_Sources`: suggested, then imported as
targeted with their page; postmeta by its `meta_key` index, at most
1,000 posts, admin requests only) and Add as target on search report
rows (candidates; `only_new`, so a target already set never changes).
`GET/POST/DELETE /targets`, `GET /targets/queries`, `GET/POST
/targets/suggestions`, `wp seoprostats targets` and the
`seoprostats/targets`, `seoprostats/targets-suggest` and
`seoprostats/targets-import` abilities serve them (writes and
suggestions `manage_options`); the dashboard has them under Search →
Targets. `GET /queue`, `wp seoprostats
queue` and the `seoprostats/queue` ability read it (`kind` for one kind);
`POST /queue/{key}`, the queue
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

Indexation (schema v12, `SEOProStats_Indexation`; design:
`docs/seo-loop.md` → Indexation) lists published pages and sitemap
addresses search has not shown in the engine's newest days (28 by
default). The audit's facts keep when each post was published
(`page_facts.published`); the daily cron reads WordPress's sitemap
providers other than posts (no request, at most 5,000 addresses) into
`sitemap`, with when each address was first listed. The report reads
`page_facts` by its `published` key and `sitemap` by `first_seen` (the
newest 5,000 of each), then `gsc_pages` by `path_day` for those pages
only. Each row is also a queue item (kind `index`). `GET /indexation`,
`wp seoprostats indexation` and the `seoprostats/indexation` ability read
it; `wp seoprostats indexation run` reads the sitemaps now. The dashboard
shows it under Search → Audit, after internal links. With Google, each
row carries Google's URL Inspection of the page (below).

Search Console sitemaps and URL Inspection (schema v19,
`SEOProStats_Inspections`, GH#144; design: `docs/seo-loop.md` →
Indexation) run only while Search Console is connected, from the hourly
search import after its own work, with a 20-second budget and their own
lock; never on visitor pages or report requests. Once a day the import
reads the property's sitemaps (errors, warnings, last download, addresses
submitted; Google's deprecated indexed count is left out) into an option
per data set. Then it inspects pages with the URL Inspection API, at most
the `inspections` setting a day (Settings → Data, default 200, up to
Google's 2,000 per property, counted per Google day in Pacific time):
first the pages Indexation lists, then those with search impressions in
the newest 28 days (`gsc_pages` by its primary key), each again after 14
days. Each answer is one row of the new `inspections` table (`path_id`
primary key; Google's values as codes, the coverage state and canonicals
in the dictionary, finding flags, details as JSON). Findings
(`robots_blocked`, `not_indexed`, `google_canonical`, `rich_errors`) are
content audit findings; sitemap problems (errors, not downloaded for
over 7 days, warnings, the site's own sitemap index not submitted) are
queue items (kind `sitemap`; done opens no experiment). A verdict change
on live data is a timeline change (`index_status`). Reports read
`inspections` by its keys (`checked`, `verdict_checked`,
`coverage_checked`, `flags`) and by primary key for the pages shown.
`GET /inspections`, `wp seoprostats inspect [<page>] [--run]
[--sitemaps]` and the `seoprostats/inspections` ability read it; the
dashboard shows Google's index under Search → Audit, after Indexation.

Backlinks (schema v18, `SEOProStats_Backlinks`, GH#142) lists pages of
other sites that link to the site's pages, found without an outside
service. On by default (Settings → Data → Check pages that send visitors
for links); off, no page is opened. The daily cron reads the Referral
channel's visits since its last run from `sessions` by `started` (each
referring host and path once a day, at most 2,000), and keeps each as a
referring page in the new `links` table (`path_id` 0; its address in the
dictionary as `DICT_URL`). Browsers usually send only the other site's
origin, so most referring pages are home pages. Within 20 seconds it
opens the pages due, oldest check first (`path_checked` key):
`wp_safe_remote_get()` (public addresses only, 5 seconds, 1 MB, three
redirects), a user agent naming the plugin and the site, nothing about
visitors. Absolute `<a href>` links to the site's hosts (the collector's
host list) become one row each per linked page (`path_id`), with their
text (`DICT_LABEL`, 100 characters) and rel bits (nofollow, sponsored,
ugc), at most 50 per page. A page is checked again after a week; one
that showed no link only after it sends another visit. A link missing on
two checks in a row, or on a page answering 404 or 410, is lost; failed
requests change nothing. New and lost links become changes
(`backlink_new`, `backlink_lost`, group seo; one per referring site and
day, with the links in `meta`). The report reads live links by
`status_first` and lost ones by `lost` (each at most 5,000), and each
referring site's visits from `daily` (dim source) by `dim_val_day`.
`found` and `authority` leave room for a provider (GH#141). `GET
/backlinks`, `wp seoprostats backlinks [links|domains|pages|lost|reported|check|import|imports]`
and the `seoprostats/backlinks` ability read it; `check` runs the check
now for two minutes, even when the setting is off. The dashboard shows it
under Search → Backlinks (not in shared reports).

CSV exports (GH#146, schema v20) use `SEOProStats_Backlinks_Import`:
the job/lease facade delegates header and row parsing to `Backlinks_CSV`,
bounded staging to `Backlinks_Stage` and idempotent upserts to `Backlinks_Store`
(all classes have the `SEOProStats_` prefix). Each component has one responsibility.
UTF-8 comma-separated header detection, or explicit source; multipart and JSON
`POST /backlinks/import`, progress `GET /backlinks/import`, CLI `backlinks import
<file>`. Settings → Import has a Links card. Staging uses non-autoloaded options,
at most 200 chunks of 500 rows (100,000 rows, 50 MB), with a single job and a
five-minute crash-recovery lease. Cron `seoprostats_backlinks_import` runs within
20 seconds, checkpoints completed batches and the final partial batch, removes
consumed chunks, and schedules recovery before processing, including busy-lock
returns. Terminal status is checkpointed before final-chunk removal. Replaying a
batch after a crash is idempotent. Uninstall/reset removes staging, state, lease
and the hook. No public upload attachment or visitor-page work.

`found` is now smallint: referrer 1 and dataforseo 2 retain their meanings;
gsc 4, ahrefs 8, semrush 16, majestic 32, moz 64, bing 128, generic 256,
verified 512 (a page check). Only source-only candidate provenance is inherited
by newly verified links; target-specific provider bits stay on their exact rows.
`providers` JSON stores authority (0–100 or null) and last_seen per export
source, distinct from the existing provider score and verification times.
The own-host target restriction uses the collector's host list. Source-only
Search Console rows create `path_id=0` candidates, not invented backlinks.
Imported pages join the existing safe HTTP verifier only while checking is on;
unlike visit-only pages, a first miss does not prevent a second weekly check.
Exports never reset checked/misses/lost/status, never infer lost from absence,
and merge first/last dates and source bits. Source filtering is in memory over
the existing indexed 5,000-row reads, before aggregation and pagination;
cache keys include source and import progress invalidates the version.

Reported pages and catch-up (GH#213). Search Console's exports name the
linking page only, so an import of hundreds of them added nothing visible
until the daily 20-second run had opened each page, which took weeks. Now:

- `kind=reported` (`SEOProStats_Backlinks_Reported`) lists the `path_id=0`
  rows with an export bit (`EXPORTS`, dataforseo to generic), read by
  `path_checked` (at most 5,000, never checked first), with each page's
  check state (`unchecked`, `links`, `none`, `error`, `gone`), the live
  links found on it (`links`, and `targets`: each one's page of this
  site, text, rel and first seen, taken from the report's own read of
  live links, no extra query), the export's date and the last check.
  Totals add `reported`, `reported_domains` and `reported_checked`. In
  wp-admin a page's link count opens a row with its targets.
- When an import ends, and after every locked run, cron
  `seoprostats_backlinks_check` is scheduled a minute ahead while the
  check is on and an export-named page was never opened (`waiting()`,
  the `path_id=0, checked=0` range of `path_checked`); it reschedules
  itself until none waits. `read.next` gives its next run.
- The daily run, the catch-up and Check now (`POST /backlinks/check`,
  owner, live data, runs though the setting is off) share a five-minute
  option lease (`run_locked()`), so no page is opened twice at once.
- Links found on the first check of an export-named page take the
  export's first (else last) seen date and raise no `backlink_new`
  change: the export said they were there already.
- A failed open of a referring page counts in its `misses` (reset on a
  successful open), so `reported` can tell an error from no link.

Import history (`SEOProStats_Backlinks_History`, option
`seoprostats_backlinks_imports` per data set, autoload off): each staged
import (upload, WP-CLI or REST rows) records who, how, the file's name,
size and SHA-256, the source and the row counts, updated when the job
ends (matched by the job's `started`). The last 20 are kept. Uploaded and
CLI files are copied to `wp-content/seoprostats/links-{blog id}/` under
random names, with deny-all `.htaccess` and `index.php`; at most 200 MB of
files are kept, oldest dropped first (the entry stays). `GET
/backlinks/imports` lists them and `GET /backlinks/imports/{id}/file`
(administrators, `no-store`, `nosniff`) sends a file back as it was
uploaded; `wp seoprostats backlinks imports` lists them. Settings → Import
→ Links shows the table with a Download button and a link to Search →
Backlinks → Reported for the import's source (`#/search?report=backlinks&
backlinks=reported&found=<source>`). Rows that name the same link again
are merged, so results are by source, not by file. Reset and uninstall
remove the entries, files and folder.

Backlink review (GH#147, schema v22) uses `SEOProStats_Backlink_Review`.
`links.facts` retains outgoing-link counts, link-list density, declared language,
predominant script and transport-provided redirect history from the existing
weekly verifier, without extra requests. Only observed
facts contribute; authority is not a spam score. Explained signals have fixed
weights, counted once per site and capped at 100, with no automatic decision.
The existing bounded live/lost reports supply link rows; lost uses the selected
period. Missing provider facts are never inferred.

`link_reviews` stores scope (domain/source URL), exact target, decision,
imported provenance, user ID and Unix review time. SHA-256 rkey identifies the
full target; decision_id indexes bounded reads and exports. Decisions outlive
link loss, keep suppresses future flags, and exports ignore the current report
period/sample. The schema registry creates/removes live and demo tables; no new
option or cron job. Demo decisions are isolated from live decisions.

Owner-only REST review GET/POST, merge POST (paste/upload), plain-text export GET,
CLI review/disavow and backlink-review ability use the same helper. HTTP export
uses a narrowly scoped rest_pre_serve_request filter so the attachment is not a
JSON string; errors remain JSON. Validation precedes merge writes, combined
output limits are checked, and transactional unique-key reads lock existing
decisions. A conflicting Keep/Undecided entry rolls back the entire merge rather
than silently dropping it or changing the decision; successful merges preserve
all entries. CLI --merge preserves supplied entries in that export without
writing decisions. Header lines count toward limits; no silent truncation or
external submission. Google guidance and replacement-upload warnings are part of
the dashboard. Weak signals never assert that a site is spam.

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
site's own is chosen (a domain property before an address one). An OAuth
client each owner makes is not offered (as much Google Cloud work as the
service account, with an "unverified app" warning on top).

**Sign in with Google** (issue #50) is the first choice on the tab. A
Google OAuth app has a fixed list of redirect addresses and a client
secret, so a plugin on many sites goes through one address we run: the
relay in `relay/gsc-oauth` (a Cloudflare Worker at
`gsc-oauth.wpallstars.com`, left out of the zips; `SEOPROSTATS_GOOGLE_RELAY`
points a test site at another). It is stateless:

1. The button (admin-post `seoprostats_google_start`, nonce and
   `can_change()` checked) stores a one-time nonce for the admin (its
   hash, in the `seoprostats_google_signin` transient, 15 minutes) and
   sends the browser to the relay's `/start` with the site's return
   address (admin-post `seoprostats_google_signin`) and the nonce. The
   relay names the site, signs both into OAuth `state` (HMAC) and sends
   the browser to Google's consent screen (`webmasters.readonly`,
   offline, `prompt=consent`).
2. Google returns to the relay's `/callback`, which checks `state`,
   swaps the code for tokens (with the client secret) and posts them to
   the return address in a self-submitting form, never in an address.
   The post is cross-site, so it may come without the admin's cookies
   (both the `admin_post_` and `admin_post_nopriv_` hooks take it): the
   nonce says whose sign-in it is, and is used once. The refresh token
   waits there, encrypted, for that admin.
3. The browser lands on the tab, whose script connects at once
   (`POST /connections/search-console` with `google: true`): the
   properties are listed with the new token and the site's own chosen,
   or the account's offered when it is not found. Only then is the
   refresh token stored, as the credentials (`type: google`).
4. Imports ask the relay's `/refresh` for an access token; the answer is
   kept encrypted in the `seoprostats_google_access` transient until two
   minutes before it expires, so the relay sees about one request an
   hour per site while imports run. `invalid_grant` (access removed in
   the Google account, or a test-mode token's seven days) says to sign in
   again.
5. Disconnecting revokes the refresh token with Google directly
   (`oauth2.googleapis.com/revoke`, no secret needed), then forgets it.

The relay keeps nothing and logs nothing (Workers observability off);
its client ID, secret and state key are Worker secrets. The connection's
settings say `method` (`google` or `key`); `account` is empty for a
sign-in.

The connection is one option, `seoprostats_connections` (autoload off):
per source the credentials, encrypted with libsodium's secretbox (a
random nonce; `v1:` then base64), the settings (property, service
account address) and the job's state. The key comes from
`SEOPROSTATS_ENCRYPTION_KEY` when `wp-config.php` defines it, else from
the site's `AUTH_KEY` and `AUTH_SALT`, so new security keys mean
connecting again (the status says so). Credentials are never returned by
the REST API, WP-CLI or the screen. With a key, sign-in is a JWT signed
with it (RS256, OpenSSL) for a one-hour token, kept for the request.

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

Search appearances use the API's two-step query: discover values grouped
by `searchAppearance` alone for the imported range, then group by `date`
with an `equals` appearance filter, using `byPage` aggregation and final
data. The connection keeps `appearance_from`, `appearance_to` and
`appearance_queue` so requests resume under the same lock and time budget.
Each appearance's range is replaced atomically and recorded as an import;
undo deletes its rows by `import_id`. Reimport discovers both returned and
previously stored values, so disappeared values are removed too.
`gsc_appearance` (schema v14) has primary key `(engine, day, appearance_id)`;
values use dictionary kind 17 without a fixed allowed list. The search
retention applies, and uninstall drops live and demo tables with the others.
The `appearance` report kind reads both periods through the primary key's
engine/day prefix, for the whole site and Google only (empty for Bing or a
page/query scope). Totals and chart points remain the site's figures, not
appearance sums: one search can show several appearances. The admin names
known values and uses sentence case for unknown ones. Demo search version
6 makes five appearances (`SEARCH_APPEARANCES`) from its Google days, each
with its own share of impressions, CTR against the site's and places from
the site's position; `TRANSLATED_RESULT` has no name in the admin, so it
shows the sentence-case label.

The `days` report kind (`SEOProStats_Search::day_rows()`) turns the chart's
`series()` points into rows, newest first, so it runs no query of its own and
always agrees with the chart's `grain`: a day, a week ending on the anchor's
weekday (the first may start late, cut at the period's start) or a month (the
first and last cut at the period). Each row has `from` and `to` (both
included); `limit` and `offset` slice the reversed points. It exists for every
engine, page and query (`ANY_KINDS`, with queries and pages); rows have no
comparison because the periods' days do not pair one to one, so the totals
carry it.

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
Bing sometimes counts more clicks than impressions for a rare query;
clicks and impressions are kept as Bing gives them, so totals add up,
and CTR is capped at 100% (`SEOProStats_Search::ctr()`).

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

### A/B tests

`SEOProStats_AB_Tests` (schema v16, exposures v17) shows each visitor one version of a
part of a page, to learn which does better. They are **A/B tests**
everywhere (blocks, table, routes, commands); **Experiments** are another
feature (a change and its expected effect, above).

- **Blocks.** `seoprostats/ab-test` holds two to ten
  `seoprostats/ab-variant` blocks, each any blocks. The test's attributes
  are `testId` (6 to 32 lower-case letters and digits, random), `name`,
  `status` (draft, running, paused, ended), `goals` (goal ids) and
  `winner` (a variant slug); a variant's are `slug`
  (`variant-a`… kept once set), `label` and `weight` (0–100, relative; 0
  never shows it). Both are registered in PHP with render callbacks and
  saved with their inner blocks' markup, so the editor's own parser keeps
  them.
- **Editor** (`packages/wp-admin/src/ab-test/`, its own `ab-test` bundle,
  enqueued with the post sidebar by `SEOProStats_Editor`). An A/B test
  toolbar button (and the block's More menu) wraps the selected block or
  blocks in a test: Variant A holds them, Variant B a copy. The test's
  toolbar has a variant dropdown; only the chosen variant shows in the
  canvas (the others are hidden in the editor only). The sidebar edits the
  name (the post title by default), status, goals and each variant's
  label, weight and order, and adds (blank or a copy) and removes
  variants. In the site editor, template parts and synced patterns the
  button only says tests start in posts and pages for now.
- **Results and the winner in the editor** (`ab-test/results.tsx`). For
  people who may read statistics, the sidebar's Results panel reads
  `GET /ab-tests/{id}` (live data) when it opens, never on the site, and
  keeps the answer for the session (Refresh reads again): per variant
  visits, the primary metric's rate and the chance to beat the control,
  the verdict in one line (too early says what is still needed) and a
  link to the test in the dashboard. **Pick a winner** (defaulting to the
  leader of a called test) replaces the test block with copies of the
  chosen variant's inner blocks by `replaceBlocks`, one undo step, with an
  Undo snackbar (removed once the blocks are gone or the post is saved).
  After a save (not an autosave) succeeds with the test gone and those
  blocks still there, the editor sends `POST /ab-tests/{id}/winner`;
  undone first, nothing is sent, and a test deleted by hand is only
  marked removed. `SEOProStats_AB_Tests::pick_winner()` checks the test
  ran, the person may edit its post and the post no longer holds it, then
  sets it ended with that winner (keeping `removed` at 0, so later saves
  leave it) and adds the timeline changes; sending it again changes
  nothing. A draft test, which never ran, just keeps the chosen variant.
- **Registry.** `wp_insert_post_data` gives each test without an id, or
  with one another test in the post or another post has, a new id derived
  from the old one and the post (saving again gives the same id), by
  rewriting only those tests' opening comments. `save_post` parses the
  post and upserts its tests into `ab_tests` (name, variants as JSON,
  goals, status, winner, when it first ran and ended); tests no longer in
  the post, or in a deleted post, are marked `removed`, not deleted. This
  runs only when a post is saved.
- **On the site.** The test prints the control (the first variant, or the
  winner) as normal markup inside the test's wrapper (`data-spst-test`).
  While it is running, on front-end pages only (not feeds, embeds, REST
  or wp-admin; filter `seoprostats_ab_tests_swap`), the other variants
  with a weight follow in `<template>` elements with an inline script
  (`SEOProStats_AB_Tests::script()`, under 1 KB). As the page is
  parsed, before paint, it picks a variant by weight for that page load,
  swaps it in for the control and sets `data-spst-ab="<test>:<variant>"` on
  the wrapper for collection. Crawlers, visitors without JavaScript,
  feeds and page caches see one variant; by default nothing is stored in
  the browser, and no query, option or remote request runs. Draft, paused
  and ended tests print only the control or winner.
- **One variant per visit** (Settings → Tracking, `tracking_ab_visit`,
  off by default). Off, each page load picks again. On, the script first
  reads `sessionStorage` key `spst-ab-<test>` and shows that variant if it
  is still offered (a control weighted 0 is not), else picks by weight;
  it then stores the variant shown. The value lasts until the tab closes
  and holds only the variant slug. The setting is disclosed as browser
  storage, which privacy law treats like cookies; it is in the page
  cache's tracker settings, so cached pages are cleared when it changes.
- **Exposures** (`ab_exposures`, schema v17). Each pageview sends the
  pairs of the variants it shows (`ab`); each click inside a variant
  sends its own pair. The processor keeps a pair only when its test and
  variant are in `ab_tests` (one primary-key query per batch, so a forged
  hit cannot add either), then writes one row per page load and test
  (primary key `(pkey, test_id)`, `INSERT IGNORE`, so a resumed batch adds
  nothing) with the visit, day and time, and adds clicks inside the
  variant to that row. Visits come from the visits table, so reports
  count visitors and conversions per variant by joining `session_id`.
  Rows are pruned with pageviews (visit retention) and dropped with the
  other tables. Nothing new runs on visitor pages.
- **Reports** (`SEOProStats_AB_Report`, loaded with the report engine).
  A test's numbers cover its life: from when it first ran (or was made)
  to when it ended, or now, whatever the period chosen; visit filters
  apply. Its visits are read from `ab_exposures` through `test_day`,
  grouped by visit (one derived row: the variant, how many of the test's
  variants it saw, when it first saw the test, page loads, clicks), then
  joined to the visits by primary key. A visit that saw two or more of a
  test's variants (possible while one variant per visit is off) is
  **mixed**: counted apart and in no variant. A conversion is a visit
  that reached the goal at or after first seeing the test (the goal's
  pageviews or events by `session_seq`); event goals add revenue per
  currency, purchases net of refunds. Per variant: visits, visitors, page
  loads, bounce rate, engaged time, clicks and visits that clicked, and
  each goal's conversions, rate and revenue. Each variant is compared
  with the control (the first variant) on the primary metric, the test's
  first goal (or, without goals, visits that clicked inside the variant):
  uplift (relative change in rate) with a 95% interval (the log of the
  rate ratio, Katz), and the probability that its true rate beats the
  control's, with a uniform Beta(1, 1) prior on each rate, summed exactly
  (`prob_beat()`; the normal approximation only past 50,000 conversions).
  Below 100 visits a side, 10 conversions between the two or 7 days, a
  comparison is **too early**; at 95% it is better (5%: worse), else
  unclear. The test's verdict (`no_data`, `too_early`, `winner`,
  `control`, `unclear`) comes with one plain sentence and its leader.
  Answers are cached like other reports, keyed on each test's last save.
- **The `variant` dimension** (value `test-id:variant-slug`, label "Test
  name: Variant B") filters visits that saw a variant (`test-id:*`: any
  of a test's) by `ab_exposures` (`test_day`), so every report (Overview,
  Goals, Funnels, Clicks, Properties) can be narrowed to a variant; as a
  breakdown (Overview → Events and A/B tests) it counts visits, page
  loads and clicks per variant by `ts`. The Pages breakdown marks pages
  with a running test (`ab_test`). Shared reports leave A/B tests out.
- **Timeline.** Saving a post that starts, resumes, pauses or ends a
  test, or picks its winner (in the block's attributes or by Pick a
  winner), adds a change (`ab_test_started`, `ab_test_paused`,
  `ab_test_ended`, `ab_test_winner`, group content) on its page; never on
  visitor pages.
- **Where to read them**: the dashboard's A/B tests section (a list, and
  one test's variants side by side), the test's sidebar in the editor,
  `GET /ab-tests` and
  `GET /ab-tests/{id}`, `wp seoprostats ab-tests [<id>]`, and the
  `seoprostats/ab-tests` ability. The demo data has three running: a
  headline test with a clear winner, a button test with no clear
  difference and a price test too early to call.
- **Without the plugin**, the saved markup has every variant one after
  the other, so a page shows them all while SEO Pro Stats is inactive.
  Picking a winner replaces the test with its blocks, so the saved markup
  no longer depends on the plugin.

### Moving from other statistics plugins

SEO Pro Stats → Settings → Import brings another statistics plugin's
history across as whole days in `daily`, so the charts start before SEO
Pro Stats was installed (`SEOProStats_Migrate`, loaded only on the tab,
its routes, WP-CLI, its cron hooks, and the notices below for a plugin
past its import step, without a query). One adapter per plugin extends
`SEOProStats_Migrate_Source` (`includes/stats/migrate/`); the
`seoprostats_migrate_sources` filter adds more. Built in: Burst
Statistics (`burst-statistics`, read from version 3.7.2), Koko Analytics
(`koko-analytics`, read from 2.5.3 and its older table layouts),
Statify (`statify`, read from 2.0.3), WP Statistics (`wp-statistics`,
read from 14.16.15 and its older layouts), Independent Analytics
(`independent`, read from 2.15.5; the key fits `imports.source`'s 20
characters), Slimstat (`slimstat`, read from 5.5.0), Matomo for
WordPress (`matomo`, read from 5.13.1) and Jetpack Stats (`jetpack`,
built from Jetpack 16.3's source, its stats package 0.22.1, and tested by
people who use it). Device, system and browser names, IP address lists, and
sources, channels and campaign tags from referrers and first pages are
worked out in the base class, the same for every adapter.

What each plugin keeps sets what can be imported:

| Plugin | Keeps | Imported | Not kept, so empty for its days |
|---|---|---|---|
| Burst Statistics | A row per pageview and per visit | Visitors, visits, pageviews, bounces, time on page, scroll; pages, entry and exit pages, sources, channels, campaign tags, search landing pages, countries, devices, browsers, systems | Events |
| Koko Analytics | Counts per day: the site, each page, each referrer | Visitors and pageviews of the site and of each page; referrers as sources and channels (its unique hits as visitors, its hits as pageviews) | Visits, bounces, time, entry and exit pages, campaigns, countries, devices, browsers, systems |
| Statify | A row per pageview: day, page, referrer | Pageviews of the site, each page and each referring host and its channel | Visitors, visits and everything else |
| WP Statistics | A row per visitor and day (its pageviews, referrer, browser, system, device, country, first and last page), pageviews per page and day, and the day's totals | Each visitor row as one visit: visitors, visits, pageviews, bounces; pages, entry and exit pages, sources, channels, campaign tags, search landing pages, countries, devices, browsers, systems. Days it purged: the site's visitors and pageviews, and its pages' pageviews | Time, scroll, events |
| Independent Analytics | A row per visit and per pageview (UTC), with the page, referrer, country, device, browser and system in tables of their own; UTM tags with its Pro version | Visitors, visits, pageviews, bounces, time on page; pages, entry and exit pages, sources, channels, campaign tags (Pro), search landing pages, countries, devices, browsers, systems | Scroll, events |
| Slimstat | A row per pageview (local time), with its visit, page, referrer, browser, system, device type and country; rows its retention moved to an archive table | Visits (each one visitor), pageviews, bounces, time on page; pages, entry and exit pages, sources, channels, campaign tags, search landing pages, countries, devices, browsers, systems | Visitors across a day's visits, scroll, events |
| Matomo for WordPress | A row per visit (UTC) with its referrer, campaign name, browser, system, device and country codes, a row per action, and report archives | Visits (each one visitor), pageviews, bounces, visit time and time on page; pages, entry and exit pages, sources, channels, campaign name and keyword, search landing pages, countries, devices, browsers, systems | Visitors across a day's visits, campaign source and medium, scroll, events |
| Jetpack Stats | Nothing on the site: WordPress.com keeps views and visitors per day, and per day its top posts and pages, referrers and countries | Pageviews and visitors (and visits = visitors) of the site; pageviews of each post and page, each referring host and its channel, and each country | Visits, bounces, time, entry and exit pages, campaigns, devices, browsers, systems; search terms (mostly hidden) |

Koko Analytics counts a visitor once a day, as SEO Pro Stats does, by a
cookie or a fingerprint that changes daily (its setting), so its visitors
compare directly. Its page and referrer counts are per day and not tied
to each other, so it has no search landing pages. Its older layouts are
read too: page counts by post ID (before its 1.9.991 schema, or rows its
own command had not moved yet), whose address is looked up from the post
as it did, and referrers in `referrer_urls` with `visitors` and
`pageviews` columns (before its 2.2.5 schema). Statify keeps only what
its "Period of data saving" allows (14 days unless changed), and its
referrers are whole addresses, counted here by host. Visits stay 0 for
both rather than an estimate, so visit-based rates leave their days out
instead of showing made-up numbers: bounce rate and visit duration have
nothing from them to divide, and pages per visit counts only the
pageviews of daily rows with visits (`visit_pageviews` in
`SEOProStats_Query`), so a range that reaches into those days is not
inflated.

WP Statistics has no visits: a visitor row is one visitor's day, so it
is imported as one visit (a bounce when it has one pageview), and its
first and last page (since 14.12.6) as the entry and exit. Its "Purge
Old Data Daily" deletes visitor and page rows but keeps the day's
totals: a day is read from its visitor rows when it has them, else from
`summary_totals`, else from the `visit` table of layouts before 14.15,
so purged days still bring their visitors and pageviews (layouts
without a column read, such as `device`, or without first and last page
are read for what they have). Its `historical` totals are not per day and
are not read. Independent Analytics times are UTC and are read by the
site's days; time on page is from each view to the next of the visit,
as it measures it (a visit's last page has none). Its ad referrers
(Google Ads by gclid) count as paid search; its Facebook Ads (fbclid on
a Facebook referrer) as organic social, as the collector counts fbclid.

Slimstat and Matomo for WordPress keep raw rows, often millions, so
each day is one set of grouped queries on the time index (one day a
step of the run), and the dry run estimates from table statistics (see
Dry run). Slimstat's `dt` is WordPress's legacy local timestamp (Unix
time plus the site's offset then), so a site day is `dt`'s UTC day; it
reads `slim_stats` and `slim_stats_archive` (its "Archive Mode" moves
old rows there), leaves out crawlers (`browser_type` 1) and wp-admin
pageviews, and counts each `visit_id` as a visit (a row without one as a
visit of its own); time on page is to `dt_out`, at most 30 minutes.
Matomo is read from its raw log tables, not its reporting API: that
loads only while the plugin is active (an import must also work after
it is switched off or deleted), boots all of Matomo inside the request,
and starts archiving for a day not archived yet; its report archives
are not decoded. Its visits are filed by their last action's time
(UTC), as Matomo files them, with pageviews from their page actions.
Matomo takes campaign tags out of page addresses and keeps the
campaign's name and keyword, which become `utm_campaign` and
`utm_term`; a campaign without a referrer or medium counts as direct, as
the collector counts it. Neither keeps a visitor across visits that is
read here (only IP addresses, fingerprints and visitor IDs, which are
never read), so each visit counts as one visitor.

Jetpack Stats keeps its history on WordPress.com, so it is the one
remote source (`SEOProStats_Migrate_Jetpack`; its docblock cites each
endpoint, parameter and field from Jetpack's and the WordPress.com Stats
screens' source). It is built from that source without a connected test
site, and people who use it test it with `wp seoprostats migrate run
jetpack --dry-run --requests` (each request, HTTP code, the answer's
top-level keys and counts; never tokens or answers; not `--debug`, which
WP-CLI keeps for itself). It is read only
while Jetpack (or the standalone Jetpack Stats) is active, connected to
WordPress.com and has Stats on, Jetpack's own two checks; otherwise the
adapter's `unavailable()` says why ("connect Jetpack first", "update
Jetpack") on the Import tab and in `wp seoprostats migrate list`, and the
dry run and import stop with it without a request. Requests are made
only in cron and WP-CLI, never on visitor pages, screen loads or REST
requests, through Jetpack's connection client as Jetpack makes them but
without its `jetpack_restapi_stats_cache_*` transients. The daily views
and visitors are looked up once in the background (`stats/visits`, 90
days a request, back until 180 days without views) and kept as counts in
the `seoprostats_migrate_jetpack` option, refreshed daily; that list is
its days, its totals for the check and its overlap with other plugins.
The import fetches a day's `top-posts`, `referrers` and
`country-views` (100 items each) per step. Its visitors are counted per
day, so they compare directly, and it has no visits: each visitor's day
is imported as one visit, as for WP Statistics. Posts are imported at
their current address, and posts deleted since are left out; referrers
from this site's own address are left out.

- **Detection** comes from the plugin's data, not only from the plugin:
  its tables and options are looked for whether it is active, inactive
  or deleted with its data left. A plugin is listed while it has
  statistics or leftovers; the list is cached for ten minutes and
  forgotten when any plugin is activated, deactivated or deleted.
- **Reading** is aggregate only: per day, the site's totals and the top
  1,000 values of each dimension the plugin has (pages, entry and exit
  pages, referrer hosts, channels, campaign tags, search landing pages,
  countries, devices, browsers and operating systems), in SQL that
  returns counts and names. IP addresses (raw or hashed), visitor IDs,
  user agent strings and form values never leave its tables. Texts go
  through the dictionary as the processor stores them. A visit counts on
  the day it began, and a bounce is a visit of one pageview, as SEO Pro
  Stats's own do (the plugin's own bounce flag is not read: Burst sets it
  later from its cron, by its own rule).
- **No double counting.** Each day is filled by one source. Only days
  before SEO Pro Stats's own first day (its first own `daily` day, the
  first stored visit, or today) are imported; that first day counts only
  from install time. A day another import filled (of this plugin or
  another) is skipped, so a second run adds nothing. When plugins not
  imported yet share days, the dry run shows the shared days and both
  plugins' pageviews, and asks which fills them (default: the one with
  more); that one imports first and the other fills only the days left.
  To change the choice, undo and import again.
- **Dry run** (`POST /migrate/{source}` with `dry_run`): the days, what
  is skipped and why, the plugin's own counts, rows per dimension
  (estimated from its first, middle and last day), the overlap and the
  settings it would fill in. It writes nothing. A plugin whose tables
  hold over 250,000 rows (`SEOProStats_Migrate::LARGE`, from the
  database's table statistics through the adapter's `size()`, without
  counting) has its counts estimated from three days a quarter, half and
  three quarters through (`estimated` in the answer), so the dry run
  answers in seconds; the check after import still uses its exact counts.
- **Runs**: the tab and REST start a job (`seoprostats_migrate` option)
  that the `seoprostats_migrate` cron hook moves on in 20-second
  budgets under a lock, a day per step; each read of `GET /migrate` while
  the tab polls moves it on for 10 seconds too. WP-CLI runs to the end.
  Each day is written in one transaction. Each plugin's run is an
  `imports` row (`source` = the adapter key; `meta`: version, days,
  skipped days with reasons, the check, settings filled in, the
  timeline note), and its rows carry its id in `daily.import_id`.
  When a remote source (Jetpack) cannot answer, its `days()` returns a
  `WP_Error` with `retry` seconds: the day is put back and the job waits
  (longer after each try; the job's `notice` and `wait` say why and until
  when), and after six tries, or an error trying again cannot help (not
  connected), that plugin's import stops with the error. Days already
  imported stay, and running it again carries on from the days left.
  The dry run in a web request leaves a remote source's rows estimate
  out (WP-CLI reads its sample days).
- **Reports** read imported days like summarised ones: the
  `seoprostats_imported` option holds the last imported day and when
  imports last changed (report caches start again then), and the daily
  summaries delete and rewrite only rows with `import_id = 0`.
- **Check**: a finished import shows the plugin's own pageviews and
  visits for its days (visitors too in WP-CLI and REST, each day's added
  up, since no visitor is known across days) beside the imported ones, a link to the Overview
  for those days, and a note on the timeline on its last day.
- **Settings** it has an equivalent for are filled in only where ours
  are still at their default, and only those chosen in the dry run (the
  import's `settings` keys, kept in the job; a key's related settings
  follow it; none given: all): Burst's Do Not Track, excluded roles and
  excluded IP addresses; Koko Analytics's excluded user roles and IP
  addresses (only roles it leaves out: its default counts everyone);
  Statify's "Logged in users" (skip all: every role; skip
  administrators: the administrator role; track all is not carried
  over); WP Statistics's excluded roles, excluded IP addresses (its
  netmask ranges as prefix lengths), Do Not Track and "Purge Old Data
  Daily" when not its default 180 days (off: our visits are kept; days:
  months of visits); Independent Analytics's "Track logged-in users"
  (off: every role; on: its ignored roles), ignored IP addresses and
  "Automatically Delete Old Data" (keep forever: ours off; else its
  months); Slimstat's "WP Users" (on: every role) or excluded
  capabilities (the roles named, or holding a capability named, with its
  `*` wildcard), excluded IP addresses, Do Not Track and "Retention
  Period" when not its default 420 days; Matomo's roles excluded from
  tracking and excluded IP addresses (its site's and global list). Their
  own options are never written.
- **Undo** (`DELETE /imports/{id}`, `wp seoprostats migrate undo`)
  deletes the import's rows by its days through the primary key and its
  id, in batches, and its timeline note. Settings it filled in stay.

**Remove leftover data** (`POST /migrate/{source}/cleanup`, `wp
seoprostats migrate cleanup`) is the owner's one exception to leaving
other plugins' data alone (`AGENTS.md`). It lists the adapter's
`leftovers()`: exactly what the plugin leaves on this site now (tables
with this site's prefix, options, transients, cron hooks, user meta keys,
post meta keys, roles it added, files and folders in wp-content). It deletes exactly that list after
confirmation (`--yes` in WP-CLI), one table at a time with `DROP TABLE IF
EXISTS`, and is refused while the plugin is active on the site or the
network, or for people who cannot delete plugins and manage options. On
multisite it acts on this site only and lists what the network shares
without deleting it. It cannot be undone; imported days stay, and one
timeline note records it. WP Statistics, Slimstat and Matomo have a
setting to remove their data when deleted:

- **Burst Statistics** keeps its tables, options and upload folder.
- **Koko Analytics** removes some options and keeps its tables, its
  migration option, its widget option, its upload folder (a buffer of
  hits not yet counted) and a user meta key for its review notice. On
  deactivation it removes its cron hooks and its endpoint file beside
  `wp-config.php`, which is outside wp-content and so not in the list.
  The capabilities it gave the administrator role stay in the roles.
- **Statify** removes its option and table when deleted through
  WordPress, so its history goes with it; leftovers remain only when its
  files were removed another way, or while it is just deactivated.
- **WP Statistics** keeps everything when deactivated. When deleted it
  removes its options, transients, cron hooks, `wp_statistics` user and
  post meta and its tables only when "Delete All Data on Plugin
  Deletion" (Settings → Advanced Options → Danger Zone) is on, which is
  off unless changed; its `uploads/wp-statistics/` folder (GeoIP
  database) stays either way. Leftovers listed: its tables in every
  layout (with `visit`, `useronline`, `search`, `historical` and its
  add-ons'), its `wp_statistics` options and widget option, `wps_robotlist`,
  its transients (its own, its caches' and its background jobs'), its
  `wp_statistics_` cron hooks, user and post meta, the upload folder and
  copies of its script an older version put in uploads.
- **Independent Analytics** has no such setting: deleting it keeps
  everything. Deactivation unschedules its `iawp_` cron hooks and deletes
  its GeoIP database and its must-use plugin file. Its own "Delete all
  data & deactivate plugin" removes its `iawp_` options and user meta,
  all its tables, its `iawp_total_views` post meta and
  `uploads/iawp-favicons/`. Leftovers listed: the same, its `iawp_`
  transients, and its click-tracking files and GeoIP database in uploads
  if any are left (on a network the GeoIP database is the main site's,
  listed as shared). The capabilities it gave roles stay in the roles.
- **Slimstat** keeps everything when deactivated. When deleted it
  removes its tables (and the network's shared `slim_browsers`,
  `slim_screenres` and `slim_content_info`), its options and transients,
  its `wp_slimstat_` cron hooks, its screen-layout user meta and
  `uploads/wp-slimstat/` (GeoIP database) unless "Delete
  Data on Uninstall" (Slimstat → Settings → Maintenance) is off; it is
  on until changed. Leftovers listed: its tables of every layout, its
  `slimstat_` and `wp_slimstat_` options, widget option, transients,
  cron hooks, user meta and the upload folder (on a network the shared
  tables, user meta and folder are listed as shared).
- **Matomo for WordPress** unschedules most of its cron hooks when
  deactivated and keeps the rest. When deleted it removes its scheduled
  tasks, its four `matomo_` roles and its dashboard user meta, and, with
  "Delete all data on uninstall" (Matomo Analytics → Settings →
  Advanced, or the `MATOMO_REMOVE_ALL_DATA` constant; on until changed),
  every table with its prefix (`matomo_` after the site's), its
  `matomo-` and `matomo_global-` options and `uploads/matomo/`.
  Leftovers listed: the same, its `matomo_` and settings-tab transients
  and its remaining `matomo_` cron hooks (on a network its site options
  and user meta are listed as shared).
- **Jetpack Stats** keeps its history on WordPress.com, not here; its
  step after the import is to switch off Stats in Jetpack → Settings →
  Traffic (Jetpack does other jobs), or to deactivate the standalone
  Jetpack Stats plugin. Leftovers listed: only its statistics caches,
  the `jetpack_restapi_stats_cache_*` transients and the
  `_jetpack_restapi_stats_cache_` post meta; never Jetpack's connection,
  modules or other options.

**Notices** (`SEOProStats_Migrate_Notices`) give one next step per plugin
found, with its link, from finding its data until the plugin and its data
are gone:

| Step | When | Links |
|---|---|---|
| `import` | Its data has days not imported yet (before SEO Pro Stats's own first day, not filled by an import) | Import its history (the Import tab, at its card) |
| `check` | Nothing left to import, and it still records statistics | Check the import, Deactivate (or the adapter's `removal_step()`) |
| `remove` | It no longer records statistics; its data is on the site | Delete it (its own uninstall; single sites), Remove leftover data |
| none | Plugin and data gone | — |

A plugin that started after SEO Pro Stats has nothing to import and goes
straight to `check`. In `check`, its own delete-data setting
(`uninstall_setting()`) is named when it has one. An adapter whose plugin
does other jobs (Jetpack) returns `removal_step()`, how to stop its
statistics and whether that is done, instead of deactivating the plugin.

They run side by side: SEO Pro Stats keeps counting the whole time, and
no statistics plugin is in a setting's `replaces` list (that pauses
collection, `SEOProStats_Replaced_Plugins`). Shown to people who can
activate plugins: a note under the plugin's row on the Plugins screen,
and a notice at the top of the Plugins screen (also for deleted plugins
whose data is left) and of SEO Pro Stats's own screens (not the Import
tab). Screens never read other plugins' tables: each `found()` that looks
saves the facts the notices need (`seoprostats_migrate_notices`, autoload
off: file, days, days still to import, whether imported and whether data
is left), and the notices add only whether the plugin is installed and
active. Plugins activated, deactivated or deleted, and a list over a day
old, schedule a look in the background (`seoprostats_migrate_scan`); a
finished import, an undo and a cleanup look again at once
(`forget_found()`).
**Hide** hides the listed steps for that person (user meta
`seoprostats_migrate_notices_hidden`, `key:step`); a plugin shows again
when its step changes.

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

All of this needs something to run WordPress's scheduled jobs: page loads
(WP-Cron), or a server cron job when `DISABLE_WP_CRON` is set. A plugin
cannot add a server cron job, so it watches for a missing one:
`SEOProStats_Collection::jobs_late()` is how long the minute job has waited
past its time, read from the autoloaded schedule (no query). With
`DISABLE_WP_CRON` set and the job over 15 minutes late (an hour: missed;
Site Health's thresholds), `SEOProStats_Schedule_Notice` shows the commands
for this site on the plugin's screens (`php …/wp-cron.php`, `wp cron event
run --due-now`, the `wp-cron.php` address) until the jobs run again; the
`seoprostats_schedule_notice` filter hides it for a plugin that shows the
same advice site-wide. With WP-Cron on, lateness only means no recent page
loads and the screen's own load starts the jobs, so nothing is shown. `wp
seoprostats doctor` reports the same (WP-Cron), and calls waiting hits
stale only when the minute job is over 10 minutes late, not when the last
run that found hits was long ago on a quiet site.

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
| `daily` | day × dimension × value | `day`, `dim` (0 the site, else a code in `SEOProStats_Rollup::DIMS`, or `SEARCH_LANDING` (18): visits from organic search by entry page, for the content report), `val` (the visit column's value or dict id; a country's two letters as a number), `visitors`, `visits`, `pageviews`, `bounces`, `engaged_ms`, `events`, `scroll` (pages: sums over their views), `import_id` (schema v15: the `imports` row of a day imported from another statistics plugin, 0 for the site's own; a day is one or the other, and the daily summaries never touch imported rows) (revenue summaries come with goals, per currency) |
| `daily_vitals`, `daily_bots` | day × page × device × metric; day × bot | percentiles from the raw rows; requests, verified |
| `gsc_pages`, `gsc_queries`, `gsc_pairs`, `gsc_totals` | engine × day × page / query / page and query / device and country (schema v7; Search Console and Bing Webmaster Tools above: Bing's pages, queries and pairs are weekly, on the week's last day, and its totals have no device or country) | `engine` (1 Google, 2 Bing), `day`, `path_id`, `query_id` (dict kind 16), `device` (1 desktop, 2 mobile, 3 tablet), `country` (ISO 3166-1 alpha-3, lower case), `clicks`, `impressions`, `pos_impr` (position × impressions × 100, for weighted averages), `import_id` |
| `changes` | change to the site, a marker on the timeline (schema v6; Changes below) | `id`, `ts`, `kind` (a code in `SEOProStats_Changes::KINDS`), `path_id` (0: site-wide), `object_type` (the post type, or `coupon`, `plugin`, `theme`, `core`, `option`), `object_id`, `old`, `new` (190 characters), `meta` (JSON), `source` (1 WordPress, 2 WP-CLI, 3 API, 4 cron, 5 feed, 6 note), `user_id` |
| `snapshots` | stored version of a page | `path_id`, `post_id`, `ts`, title, description, H1, word count, text hash, compressed text |
| `pages` | address that shows one item (schema v5) | `path_id` (primary key), `post_id`, `post_type`, `author_id`, `term_id`, `seen` (when last checked; the latest view wins); later `title`, `launched`, `removed`, `status` |
| `links` | backlink | source URL and host, target page, anchor, rel, first and last seen, lost, authority, how found |
| `incidents` | outage, slowdown or collection gap | `kind`, `started`, `ended`, `meta` |
| `imports` | import run of an outside source (schema v7) | `id`, `source`, `status` (1 running, 2 done, 3 failed, 4 undone), `started`, `finished`, `day_from`, `day_to`, `rows_added`, `meta` (property, days, error); imported rows carry its id so it can be undone |
| `experiments` | a change's hypothesis, measured before and after against unchanged pages (schema v8; `docs/seo-loop.md`) | `id`, `created`, `user_id`, `name`, `start`, `days`, `review` (the after window's last day), `engine`, `metric` (1 clicks, 2 impressions, 3 CTR, 4 position, 5 visits, 6 conversions), `direction`, `threshold` (percent, or tenths of a place), `change_id`, `path_id` (0: several pages, in `meta`), `status` (1 running, 2 decided, 3 cancelled), `result` (1 keep, 2 revise, 3 undo, 4 inconclusive), `decided`, `meta` (pages, goal, hypothesis, note, the change row it wrote, the measurement decided on) |
| `queue` | a decision queue item someone acted on (schema v9; `docs/seo-loop.md`) | `id`, `ikey` (8-byte hash of kind, engine, page and query; unique), `kind` (1 CTR, 2 missing, 3 striking, 4 decay, 5 overlap, 6 audit, 7 links, 8 index, 9 refresh, 10 target; an audit item's key has its finding in place of a query, a links or index item's its list, a refresh item's its proposal, a target item's its finding before the query), `engine`, `path_id`, `query_id`, `status` (0 new with an effort or note, 1 accepted, 2 done, 3 dismissed), `effort` (0: the kind's), `experiment_id`, `created`, `updated`, `user_id`, `note`, `meta` (the item as it was when acted on) |
| `page_facts` | content audit facts of a published page (schema v10; `docs/seo-loop.md`) | `path_id` (primary key), `post_id`, `checked`, `modified`, `title_len`, `seo_title_len`, `desc_len`, `title_hash`, `desc_hash` (8-byte keys of the shown title and description; zeros for none), `h1`, `words`, `images`, `images_no_alt`, `noindex`, `canonical_away`, `flags` (the page's own findings as bits, `SEOProStats_Audit::FLAGS`), `links_in` (other pages whose text links to it; schema v11), `published` (when the post was published; 0 not read yet; schema v12) |
| `page_links` | a link in a published page's text to another of the site's pages (schema v11; `docs/seo-loop.md`) | `from_path`, `to_path` (primary key), `text_id` (the first link's text, `DICT_LABEL`), `links` (how many links) |
| `sitemap` | an address in the site's own sitemaps other than its posts (schema v12; `docs/seo-loop.md`) | `path_id` (primary key), `source` (1 category and tag archives, 2 author archives, 3 another provider), `first_seen` (when first listed), `seen` (the read that last listed it) |
| `targets` | a search the site chose to win and the page meant for it (schema v13; `docs/seo-loop.md`) | `query_id` (primary key, `DICT_QUERY`), `path_id` (0: none chosen), `priority` (0–100), `status` (1 candidate, 2 targeted, 3 live, 4 won, 5 retired), `source` (1 list, 2 aidevops, 3 demo), `created`, `updated`, `user_id` |
| `ab_tests` | an A/B test in a post, read when the post is saved (schema v16; A/B tests above) | `test_id` (primary key, the block's `testId`), `post_id`, `name`, `variants` (JSON: slug, label, weight), `goals` (JSON goal ids), `status` (0 draft, 1 running, 2 paused, 3 ended), `winner` (a variant slug), `created`, `updated`, `started` (first ran), `ended`, `removed` (when it left its post; 0 while in it) |
| `ab_exposures` | a page load × A/B test it showed (schema v17; A/B tests above) | `pkey` (the pageview's page-load ID), `test_id` (`DICT_AB_TEST`), `variant_id` (`DICT_AB_VARIANT`; primary key `(pkey, test_id)`), `session_id`, `day`, `ts`, `clicks` (clicks inside the variant) |

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
(schema v11), and `published`, and `sitemap` `first_seen` and `seen`
(schema v12); `ab_tests` has `post_id` and `status` (schema v16);
`ab_exposures` has `(test_id, day, variant_id, session_id)` for a test's
results and `ts` for retention (schema v17);
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
once per engine with data (Google, Bing; not Combined), showing Rankings, Opportunities,
Audit and Content (not Targets, Plan or Experiments, the owner's chosen
searches, work list and notes). Search data and the change log have no visits, so a share with
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
and post identity (`SEOProStats_Clicks::page_info()`): `url_to_postid()`,
else a published post of any viewable post type with the path's last part
as its slug (`post_name` index) whose own permalink has the path, which
finds custom post types whose permalinks have no base. A path that is no
post may be a term archive: a term of a viewable taxonomy with that slug
(`slug` index) whose term link has the path adds `term_id` and `taxonomy`.
Only the returned page rows are resolved, inside the report cache. Editor
addresses are added after the shared cache on every request, only with
`current_user_can('edit_post', post_id)` (or `edit_term` for a term
archive), so cached administrator answers never leak links to another
viewer. Future
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
number of days, so days not imported yet never look like a drop. Ranges
counted back from today (7d, 28d, 30d, 90d, 91d, 182d, 364d and 12mo,
`SEOProStats_Query::TRAILING_RANGES`) keep their length instead, ending at
`through`, as Search Console's own periods do, so the last 30 days hold 30
days and whole weeks are compared with the same weekdays. Calendar ranges
(this week, month and year) and today and yesterday are cut.
Rankings, Opportunities and Content also take `engine=all`, **Combined**
(`SEOProStats_Search::ALL`, not a stored engine code): every engine with
data (`with_data()`), read with `engine IN (…)` (`engine_where()`; each
engine is still a range of the primary key `(engine, day, …)`), clicks,
impressions and `pos_impr` added up, so CTR and position are over all of
them and a query or page shared by engines (one dictionary id) is one
row. Its period ends at the earliest of the engines' newest days
(`span()`), so one engine's lag never looks like a drop; with a weekly
engine it is whole weeks, and a page's or query's points are by week,
each day added to the week it falls in, the weeks ending on the period's
last day. It has no countries or devices. Each engine's days are its own
(Google's in Pacific time, Bing's in UTC) and are added as they are.
While fewer than two engines have data, `all` answers as the one with
data (`report_engine()`), and the dashboard offers Combined only with two
or more. The other Search reports (Audit, Targets, Plan, Experiments)
read one engine: Google when Combined is chosen. Totals
and the points (daily, monthly past 120 days) come from `gsc_totals` for
the site, `gsc_pages` by `path_day` for a page or pattern, `gsc_queries`
by `query_day` for a query, and `gsc_pairs` for both; each read names
its key (`FORCE INDEX`, the primary key without a page or query), so a
year over most of a table still reads only its days. Rows: queries
(`gsc_queries`, or pairs by `path_day` for a page), pages (`gsc_pages`,
or pairs by `query_day` for a query), countries and devices (`gsc_totals`,
the site only), ordered by `sort` (impressions by default, or clicks,
CTR or position) in `order` (the column's natural one when left out:
lowest first for position, most first for the rest; CTR and position
without impressions last), ties most impressions then clicks first; the
days rows newest first unless `sort` is a figure or `order` is asc. Each
row has clicks, impressions, CTR,
the weighted position and its share of clicks, and with a comparison the
rows shown get their figures then and the change. Position changes are
in places (now − then; lower is better). Page filters narrow it like the
page box; other filters select visits, which search data has not, so the
answer names them in `ignored`. The cache key adds the newest import and
the last one finished or undone, so new days show at once. Pages rows
and an exact page get the same addresses and editor links as Clicks.

Opportunities (`SEOProStats_Opportunities`) read the same days of one
engine, with the same cut, page filters, `ignored` and cache key, and say where search
effort pays. The period is also cut to its newest 366 days (`MAX_DAYS`;
the answer gives `days` and `cut`): every period up to a year is read
whole, as search demand is seasonal, and all time never reads every pair.
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
  minimum, most lost first. The earlier period holds only days with
  search data (`decay_periods()`): where it would start before the first,
  both are shortened from the newest end to the longest pair that fits
  (at least `DECAY_MIN_DAYS`; `compare.cut`, and the queue's `decay`), as
  Search Console keeps 16 months, so a year's previous year is mostly
  empty and would read as no loss. For the rows shown, `gsc_pairs` (by
  `path_day`) gives the queries that lost most, and the cause: `gone` (no
  impressions now), `position` (a place and a tenth lower or more), else
  whichever fell more of impressions (`demand`) and CTR (`ctr`), with a
  sentence (`why`). For those queries, `gsc_pairs` by `query_day` (every
  page, both periods) gives each its `rival`: another page with at least
  10% of its impressions that ranks better now and did not before. The
  change log by `path_ts` (`SEOProStats_Changes::
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
finds its post in the `pages` table (by its primary key), else as page
rows do (`SEOProStats_Clicks::page_info()`). SEO titles, descriptions and focus keywords come from
Rank Math, Yoast SEO, SEOPress and All in One SEO (its `aioseo_posts`
table, by `post_id`) when present, and titles and descriptions from The
SEO Framework (the same meta as the change log's `SEO_META`)
(`seoprostats_focus_keywords` adds others); without one the report is the
same less `focus`. The one-page report (`GET /coverage`, by `page` or
`post`) reads that page's queries by `path_day` (the 200 with most
impressions, newest 366 days) and its post once; the cache key adds the
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
`sort` (impressions by default; any figure of the row, conversions and
their rate only with a goal) in `order` (the column's natural one when
left out: lowest first for position, most first for the rest; rows
without the figure last), then impressions, clicks and visits; with a comparison the rows shown get their figures then and
the change. Search figures are the chosen engine's (`engine`), visits
any engine's, so the two differ.

Ranges resolve in the site time zone: realtime (last 30 minutes), today,
yesterday, 24h, 7d, 28d, 30d, 90d, 91d, 182d, 364d, this week, this
month, this year, last 12 months, last year, all time, custom; comparison
with the previous period or the same period last year (custom comparison
later). The previous period has as many whole days, just before; 7d, 28d,
91d, 182d and 364d are whole weeks, so each day meets the same weekday.
A range that ends in the future meets the same length of the other period.
The dashboard opens on 91d against the previous period
(`DEFAULT_STATE` in `packages/core/src/state.ts`). Its period menu
(`rangeMenu()` in `packages/wp-admin/src/labels.ts`) groups them: Days
(today, yesterday, 24h, 7d), Weeks (28d, 91d, 182d and 364d, read as 4,
13, 26 and 52 weeks), Calendar (this week, this month, this year, last 12
months, last year), then all time and custom. 30d, 90d and realtime stay
in the API and in links; the menu lists one only while it is chosen.

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
  `opportunities`, `audit`, `links`, `indexation`, `inspections`, `backlinks`, `coverage`, `content`,
  `experiments`, `ab-tests` (and `ab-tests/{id}`; A/B tests above),
  `queue`, `targets`, `loop` (one answer per cycle for an
  agent: open queue items, experiments due and recently decided, and the
  period's search rows in the aidevops export layout;
  `docs/seo-loop-recipes.md`), `demo`,
  `view`, and for settings administrators `connections` (`GET`; `/{source}` to
  read, connect or disconnect; `/{source}/import` to import now) and
  `imports/{id}` (`DELETE` undoes one, a search data import or one from
  another statistics plugin), `migrate` (`GET`: plugins found, the job,
  the imports; `/{source}` `POST` for the dry run or to start an import;
  `/{source}/cleanup` `POST` to list, or remove, its leftovers; Moving
  from other statistics plugins above); planned: `pages`,
  `page`, `flow`, `journeys`, `vitals`, `errors`, `bots`,
  `anomalies`, `health`, `annotations`, `segments`,
  `export`, `import`, `collect`.
- **WP-CLI**, `wp seoprostats <command>` with `--format=json|csv|table`:
  `stats`, `breakdown`, `goals`, `funnels` (each `list`, `add`, `update`,
  `delete` too), `properties [<key>]`, `clicks [<kind>] [--page=<path>]`,
  `changes [--page=<path>] [--kind=<kinds>]`, `search [<kind>]
  [--page=<path>] [--query=<query>] [--sort=<sort>] [--order=<order>]`, `opportunities [<kind>]`, `coverage
  <page|post> [--missing] [--questions]`, `content [--sort=<sort>]
  [--order=<order>] [--goal=<id>]`, `experiments` (`list`, `add`, `show`, `decide`,
  `cancel`, `note`, `delete`), `ab-tests [<id>] [--status=<status>]
  [--filter=<filters>]`, `queue` (`list`, `accept`, `done`,
  `dismiss`, `restore`, `effort`, `note`), `audit` (`list
  [--finding=<finding>]`, `run [--limit=<n>]`), `links [--kind=<kind>]
  [--goal=<id>]`, `indexation` (`list [--kind=<kind>] [--days=<n>]`,
  `run`), `inspect [<page>] [--run] [--sitemaps] [--verdict=<verdict>]
  [--coverage=<state>] [--finding=<finding>]`, `backlinks [links|domains|pages|lost|reported|check|import|imports] [--all] [--source=<source>]`, `targets` (`list`, `import <file|->`, `delete <query>...`),
  `loop [--rows=<n>]` (`--format=toon` writes the export rows as an
  aidevops export file), `pages`,
  `annotate`, `import`, `export`, `process`, `rollup`, `prune`, `doctor`,
  `demo` (`make`, `status`, `remove`), `connect <source>
  [--key-file=<file>] [--property=<property>]`, `disconnect <source>
  [--delete-data]`, `search-console` (`status`, `import`, `imports`,
  `undo --id`, `reimport --from --to`), `migrate` (`list`, `run <source>
  [--dry-run] [--prefer=<source>] [--from] [--to] [--settings=<keys>|none]`, `imports`, `undo
  --id`, `cleanup <source> [--dry-run] [--yes]`); reports and definitions take
  `--data=demo`.
- **Abilities** (WordPress 6.9+, guarded with `function_exists()`): the
  read reports and annotations as `seoprostats/*` abilities, so MCP
  clients reach them through the WordPress MCP adapter. So far
  `seoprostats/markers`, `seoprostats/annotate`, `seoprostats/search`,
  `seoprostats/opportunities`, `seoprostats/audit`, `seoprostats/links`,
  `seoprostats/indexation`, `seoprostats/inspections`, `seoprostats/backlinks`, `seoprostats/coverage`,
  `seoprostats/content`, `seoprostats/experiments`,
  `seoprostats/experiment-record`, `seoprostats/ab-tests`, `seoprostats/queue`,
  `seoprostats/queue-update`, `seoprostats/targets`,
  `seoprostats/targets-suggest`, `seoprostats/targets-import`,
  `seoprostats/loop`,
  `seoprostats/migrate` (plugins found, dry run and leftovers, read
  only) and `seoprostats/migrate-import`. Removing leftovers is for
  people only: no ability does it.

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
`GET /coverage?post=<id>&range=91d` once, then re-checks the queries
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
Properties, A/B tests) · Pages (All, New, Not found, Site search, page detail) ·
Search (Rankings, Opportunities, Content, Backlinks) · Health (Speed, Errors,
Crawlers, Uptime) · Changes (Changes, Anomalies, Annotations).

The Overview's cards: Sources; Pages (top, entry, exit, not found);
Content (authors, categories, post types); Site search (searches, no
results); Locations; Map (visits by country, the `country` breakdown
shaded on a world map, `WORLD_SHAPES` and `mapShades()` in
`packages/charts`); Devices (devices, browsers, systems, logged in);
Events and A/B tests (events, A/B variants; shared reports show events
only). Two cards a row on wide screens. Pages with a running A/B test
are marked A/B in the Pages card.

Built so far: Overview, Search (Rankings, Opportunities, Content), Goals,
Funnels, Properties, Clicks, A/B tests and Changes, as the settings screen's tabs
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

A/B tests lists every test (page, state, start and end, visits per
variant, the leader and its verdict); choosing one shows its variants
side by side: the verdict, the primary metric's conversions, rate,
uplift with its interval, chance to beat the control and each variant's
verdict; every goal by variant with revenue per currency; and visitors,
page loads, bounce rate, engaged time and clicks. Choosing a variant
filters every report by it. Mixed visits and the minimum sample are
explained under the tables. Shared reports have no A/B tests section.

The view state lives in the URL hash (`#/clicks?kind=dead&page=%2Fshop%2F`),
so a bookmark, a reload, a copied address or the back button brings back
the same view. It holds the section; the shared values (period and custom
days, comparison, chart metric, filters); and the section's own choices:

| Section | Address | Default (left out) |
|---|---|---|
| Overview | `tab.sources`, `tab.pages`, `tab.content`, `tab.search`, `tab.locations`, `tab.devices`, `tab.events`: the card's open tab | each card's first tab |
| Search | `report` (rankings, opportunities, content), `tab` (queries, pages, countries, devices), `chart` (clicks, impressions, ctr, position), `page`, `query`; with Rankings, Content and Audit, `sort` (a column of the table: impressions, clicks, ctr, position; Rankings' days also day; Content also visits, bounce_rate, visit_duration, conversions, conversion_rate) and `order` (desc, asc); with Content, `goal` (a goal's ID) | rankings, queries, clicks, none; impressions (days: day), the column's natural order (position asc, the rest desc), the first goal |
| Properties | `key` (the property listed), `event` | none |
| Clicks | `kind` (elements, dead, links, downloads, forms, pages), `page` | elements, none |
| A/B tests | `test` (a test's id: that test's view) | the list |
| Changes | `page` (else the page the reports are filtered to), `group` (content, seo, product, site, search, note) | none, all changes |

Only applied choices count: text in a box is a draft until Apply or Enter.
Changing section keeps the shared values and leaves the other section's
choices behind (`switchView()`). Every value is checked against its list,
or for pages, queries, properties and events, as trimmed text without
control characters, at most 2048 characters; anything unknown or invalid
takes the default, and defaults are left out, so older addresses keep
working and addresses stay short.

In Changes, a valid `page` in the address wins over the report's page
filter; without it, the page filter is the fallback, as in older addresses.
Any page (or applying an empty box) removes both `page` and the report's
page filters, keeping the other filters. There is no sentinel value.
The box follows the applied page on Back and Forward, including no page;
the group follows the address too, with unknown groups showing all changes.

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
| Search Console | Opt-in: Sign in with Google through our relay (a refresh token) or a service account's key, stored encrypted; hourly job for new final days, 16-month history on connect, newest first; pages, queries, pairs and device × country totals (Search Console above) | `gsc_*`, `imports` |
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
