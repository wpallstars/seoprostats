# SEO Pro Stats: plan

The design is in `docs/architecture.md`. Each phase is one or more pull
requests; each ends with lint, the smoke test and a check on the preview
site. A phase may start once the phases it depends on are merged.

## Phase 0: groundwork

- [x] Starter: JavaScript build support for every plugin (sources and
      `package*.json` left out of zips, built files committed), then sync
      core files here.
- [x] `docs/architecture.md` and this plan.

## Phase 1: collect, store, show (depends on 0)

- [x] npm workspaces: `packages/core`, `packages/tracker`,
      `packages/charts`, `packages/wp-admin`; build into `assets/build/`
      (wp-scripts for the app, esbuild for the tracker).
- [x] Schema and migrations (`includes/stats/class-seoprostats-schema.php`),
      dictionary, uninstall.
- [x] Tracker: pageviews, SPA, engagement, events API, outbound and
      downloads, sessions; inline print; exclusions (editors, feeds,
      previews, customizer, automation; paths, roles and DNT/GPC in the
      settings below). Hash routes wait for a processor change: it
      drops the fragment from paths.
- [x] Collector (fast path and REST), salts, buffer files, loopback test.
- [x] Processor: user agents, channels, location (CDN header, time zone),
      sessions, pageviews, events, properties, engagement; minute cron.
      Bot hits are counted and dropped until the `bots` table (Phase 5).
Order from here: something to see first, then real data, then the
long-term record.

- [x] Report engine: ranges, comparison, filters, breakdowns, cache.
- [x] REST API (`stats`, `timeseries`, `breakdown`, `realtime`,
      `markers`), OpenAPI file, WP-CLI (`stats`, `timeseries`,
      `breakdown`, `realtime`, `process`, `doctor`).
- [x] Top-level menu at position 3 with the plugin icon; Overview:
      headline metrics, chart with comparison, Sources, Pages, Locations,
      Devices cards, filters, realtime; Dashboard widget.
- [x] Tracker (above).
- [x] Nightly summaries into `daily`; retention. Long ranges (year,
      12mo, all time) read `daily`: from the fact tables they read every
      visit in the range, which for all time is the whole `sessions`
      table (58 ms over 42,000 visits on the preview). Retention months
      are in the settings below.
- [x] Settings: tracking, privacy (DNT/GPC, excluded IPs and paths, roles),
      data retention, roles that may see the statistics.
- [x] Purge known page caches (WP-Optimize, LiteSpeed, WP Rocket, W3 Total
      Cache…) when the plugin version or tracker settings change: cached
      pages keep the inline tracker they were cached with (seen on the
      preview, PR #10).

## Phase 2: behaviour and conversions (depends on 1)

- [x] Goals (page and event, revenue per currency), funnels (2–12 steps,
      in order within one visit), custom properties; per data set, with
      demo examples; REST, WP-CLI and dashboard sections with editors
      (PR #21). Properties read `props` by period (schema v3 keys).
- [x] Click and form autocapture (never field values; masked labels,
      `data-sps-mask`); dead clicks; outbound, affiliate (paths and
      `rel="sponsored"`, with an Affiliate link event) and file flags;
      `clicks` table (schema v4) kept 3 months; REST, WP-CLI, demo data
      and a Clicks section (issue #22).
- [x] Enhanced: pages not found, site search (words optional, masked;
      no results), views by author, category and post type (`pages`
      table filled by the minute job), logged-in visits; schema v5;
      REST, WP-CLI, demo data and Overview cards (issue #27).
- [x] Purchases: WooCommerce, Easy Digital Downloads, FluentCart and
      ThriveCart orders as revenue events, once per order (server side, on
      payment), in each order's currency, on the visit that checked out
      (issue #30). Refunds and renewals later.
- [ ] A/B testing of blocks: variants in the editor, results by test and
      variant (issue #29).
- [x] Clicks by page with View and Edit links (issue #25).
- [ ] Journeys (visitor per day timeline), Flow (Sankey), segments.

## Phase 3: changes and causes (depends on 1)

- [x] Change log from WordPress hooks: posts (status, address, title,
      words, links), SEO plugin fields, products (prices, sales, stock,
      coupons), plugin, theme and core updates, key settings; `markers`,
      `changes`, `wp seoprostats changes` (GH#34).
- [ ] Page snapshots and word diffs; page detail.
- [x] Annotations (UI, API, CLI, abilities); markers lane under the
      Overview's chart; Changes section (GH#35).
- [x] Search engine update markers: Google's Search Status Dashboard and
      the owner's other feeds, opt-in, daily; rollout spans (GH#36).
- [ ] Movers and anomalies with possible causes; page detail.

## Phase 4: search (depends on 1, 3)

- [x] Search Console connection (service account, key stored encrypted),
      import of final days and the 16-month history, undo, retention;
      Settings → Connections, REST and WP-CLI (GH#43).
- [ ] Search Console by Sign in with Google, through a stateless relay
      we host (Cloudflare Worker) and a verified Google app; waits on the
      owner's domain, Cloudflare and Google setup (issue #50). An OAuth
      client each owner makes was dropped: as much Google Cloud work as the
      service account, plus an "unverified app" warning.
- [x] Rankings: a Search section with clicks, impressions, CTR and
      position against the previous period, queries, pages, countries
      and devices, page ↔ query drill-down; REST `search`, WP-CLI, the
      `seoprostats/search` ability and demo search data (GH#48).
- [x] Opportunities: striking distance, low CTR against the site's own
      CTR curve, and pages losing clicks with the likely cause (position,
      demand, CTR, gone), the queries that lost most and the page's
      changes; REST `opportunities`, WP-CLI, the
      `seoprostats/opportunities` ability and demo data (GH#53).
- [x] Content performance: search joined with each page's visits from
      search and conversions of a goal, by a daily summary of search
      landings (refilled backwards) and the goal's own hits; REST
      `content`, WP-CLI, the `seoprostats/content` ability and demo data
      (GH#59).
- [x] Query coverage: each page's search queries checked against its own
      words (title, headings, text), its questions, and its SEO plugin's
      focus keywords (Rank Math, Yoast SEO, SEOPress, All in One SEO, or
      none); Opportunities → Missing from the page, a block editor panel
      and classic meta box that re-check as people write; REST
      `coverage`, WP-CLI, the `seoprostats/coverage` ability and demo
      page text (GH#64).
- [x] Bing Webmaster Tools as a second search engine: API-key
      connection, daily site totals and weekly pages, queries and page
      queries (stored on each week's last day), an engine switch on
      Rankings, Opportunities and Content with whole-week periods and
      weekly points; REST `engine`, WP-CLI `bing` and `--engine`, the
      abilities' `engine` and demo Bing data (GH#62).
- [x] SEO decision loop: design (`docs/seo-loop.md`) and child issues in
      build order (GH#61, parent of the items below).
- [x] Experiments: a change, its expected effect and review date,
      measured against unchanged pages with confounders named (GH#73).
- [x] Decision queue: one ranked plan from the opportunities; done opens
      an experiment (GH#75, after GH#73).
- [x] Overlapping pages: queries shared by several pages, as candidates to
      review (GH#76, after GH#75).
- [x] Content audit from WordPress: page facts on save and in cron,
      findings weighed by search and conversions; Search → Audit and plan
      items (GH#77, after GH#75).
- [ ] Internal links: orphans, converting pages with few links in,
      missing links to a query's page (GH#78, after GH#77).
- [ ] Indexation: published pages and sitemap addresses without
      impressions (GH#79, after GH#77).
- [ ] Refresh planner: update, leave, protect or merge pages losing
      clicks (GH#80, after GH#77).
- [ ] Search targets: chosen queries and their pages, positions and wrong
      pages (GH#81, after GH#75).
- [ ] Loop export and agent recipes (GH#82, after GH#75).
- [ ] Backlinks from verified referrers; optional provider.
- [ ] Referral spikes and mentions.

## Phase 5: health (depends on 1)

- [ ] Speed: real-user vitals, experience score, attribution, percentiles.
- [ ] JavaScript errors grouped by fingerprint.
- [ ] Crawlers and AI bots: server-side capture, verification (reverse DNS,
      published ranges), purposes, cache exclusions.
- [ ] Collection gaps and uptime incidents.

## Phase 6: reach (depends on 1–5)

- [ ] Alerts and email reports, webhooks; the weekly email carries the
      top decision queue items and experiments due for review
      (`docs/seo-loop.md`).
- [x] Each section's view in the address (filters, period, tabs), so
      any view can be bookmarked and shared (issue #24; Search's report
      with GH#53, Changes' page with GH#57).
- [ ] Shared client reports: saved interactive views (private link,
      password, expiry) that clients keep watching, with the site's and
      an agency's branding (issue #26, after #24).
- [ ] Import (generic events JSON/CSV, daily aggregates) and export;
      undo by import id.
- [ ] Abilities for MCP clients (the reports; `seoprostats/markers` and
      `seoprostats/annotate` came with GH#35, `seoprostats/search` with
      GH#48); aidevops SEO loop recipes
      in `docs/` (GH#82).
- [ ] Optional location database (DB-IP Lite) with our own reader.

## Phase 7: move from other statistics plugins (depends on 6's import)

Bring a site's history across when it switches, on top of the Phase 6
import (import id, undo, daily aggregates where only totals exist).

- [ ] Jetpack Stats (from WordPress.com, by the site's Jetpack
      connection): daily views and visitors, top pages, referrers,
      search terms, countries.
- [ ] Plugins that keep their data in the site's own database (WP
      Statistics, Koko Analytics, Independent Analytics, Slimstat,
      Statify, Matomo for WordPress and other popular ones): read their
      tables directly, for each its version's table layout, checked
      against a real install first.
- [ ] Detection: offer the import when one of these is active or has
      left its tables; dry run with counts and date range before writing;
      imported days never overlap days we recorded ourselves.

## Later

- Experiments (A/B) with sample size and significance.
- Click heatmaps and scroll maps.
- The standalone app, mobile app and browser extension (new
  repositories, reusing `packages/` and the OpenAPI contract).
