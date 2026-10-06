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

- [ ] npm workspaces: `packages/core`, `packages/tracker`,
      `packages/charts`, `packages/wp-admin`; build into `assets/build/`.
- [x] Schema and migrations (`includes/stats/class-seoprostats-schema.php`),
      dictionary, uninstall.
- [ ] Tracker: pageviews, SPA, engagement, events API, outbound and
      downloads, sessions; inline print; exclusions.
- [x] Collector (fast path and REST), salts, buffer files, loopback test.
- [x] Processor: user agents, channels, location (CDN header, time zone),
      sessions, pageviews, events, properties, engagement; minute cron.
      Bot hits are counted and dropped until the `bots` table (Phase 5).
- [ ] Nightly summaries into `daily`; retention.
- [ ] Report engine: ranges, comparison, filters, breakdowns, cache.
- [ ] REST API (`stats`, `timeseries`, `breakdown`, `realtime`,
      `markers`), OpenAPI file, WP-CLI (`stats`, `breakdown`, `process`,
      `doctor`).
- [ ] Top-level menu at position 3 with the plugin icon; Overview:
      headline metrics, chart with comparison, Sources, Pages, Locations,
      Devices cards, filters, realtime; Dashboard widget.
- [ ] Settings: tracking, privacy (DNT/GPC, excluded IPs and paths, roles),
      data retention.

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

## Later

- Experiments (A/B) with sample size and significance.
- Click heatmaps and scroll maps.
- The standalone app, mobile app and browser extension (new
  repositories, reusing `packages/` and the OpenAPI contract).
