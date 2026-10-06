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

- [ ] Goals (page and event, revenue), funnels (2–12 steps, same visit),
      custom properties.
- [ ] Click and form autocapture; Clicks view; dead clicks; affiliate
      paths.
- [ ] Enhanced: 404s, site search, author and categories, logged-in,
      WooCommerce and Easy Digital Downloads purchases (once per order).
- [ ] Journeys (visitor per day timeline), Flow (Sankey), segments.

## Phase 3: changes and causes (depends on 1)

- [ ] Change log from WordPress hooks, snapshots and word diffs; SEO
      plugin fields; plugin, theme and core updates.
- [ ] Annotations (UI, API, CLI); markers lane on every chart.
- [ ] Search engine update markers.
- [ ] Movers and anomalies with possible causes; page detail.

## Phase 4: search (depends on 1, 3)

- [ ] Search Console connection (service account; OAuth client),
      daily import and backfill, retention.
- [ ] Rankings, content performance, striking distance, CTR gaps, decay,
      likely causes for drops (position, demand, CTR).
- [ ] Backlinks from verified referrers; optional provider.
- [ ] Referral spikes and mentions.

## Phase 5: health (depends on 1)

- [ ] Speed: real-user vitals, experience score, attribution, percentiles.
- [ ] JavaScript errors grouped by fingerprint.
- [ ] Crawlers and AI bots: server-side capture, verification (reverse DNS,
      published ranges), purposes, cache exclusions.
- [ ] Collection gaps and uptime incidents.

## Phase 6: reach (depends on 1–5)

- [ ] Alerts and email reports, webhooks.
- [ ] Shared read-only dashboards (private link, password, expiry).
- [ ] Import (generic events JSON/CSV, daily aggregates) and export;
      undo by import id.
- [ ] Abilities for MCP clients; aidevops SEO loop recipes in `docs/`.
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
