# The SEO decision loop

SEO Pro Stats already keeps visits and conversions, the site's changes,
search engine updates and search engine data on one timeline. This design
adds the step from data to decisions, for an owner in wp-admin or an AI
agent through the REST API, WP-CLI and abilities:

1. **Find**: what is wrong or could be better (Opportunities, audits).
2. **Decide**: what to do first (a ranked plan, each item with its reasons).
3. **Record**: what was changed, when, and what it was meant to do.
4. **Measure**: whether it worked, against pages that were left alone, with
   the other things that happened at the time named.
5. **Learn**: results, including null and negative ones, are kept and
   weigh the next round.

Rules from `docs/architecture.md` and `AGENTS.md` hold throughout: nothing
runs on visitor pages; every new read uses an index (checked by `EXPLAIN`
in `scripts/smoke-test.sh`); new stored facts have a retention setting or
are small and kept for good like changes; outside services are opt-in;
`packages/core` and the API contract stay free of WordPress and React.

Wording rule for every answer and screen: a result is **associated with** a
change unless a comparison group supports more; show counts beside rates,
how much of the search data is covered, and what is missing. An
inconclusive result stays inconclusive.

## What is kept, in build order

| # | Feature | Issue | Reads | Adds | Feeds |
|---|---|---|---|---|---|
| 1 | Experiments | #73 | `gsc_pages`, `daily` (search landings), goal hits, `changes` | `experiments`; change kind 81 | the timeline, the queue |
| 2 | Decision queue | #75 | Opportunities, Content, `experiments` | `queue` | experiments |
| 3 | Overlapping pages | #76 | `gsc_pairs` | nothing | Opportunities, the queue |
| 4 | Content audit | #77 | posts, SEO plugin fields, `gsc_pages` | `page_facts` | Search → Audit, the queue |
| 5 | Internal links | #78 | `post_content`, `page_facts` | `page_links` | the audit, the queue |
| 6 | Indexation | #79 | `pages`, `page_facts`, `gsc_pages`, the site's sitemap list | nothing | the queue |
| 7 | Refresh planner | #80 | losing clicks, `page_facts`, conversions | nothing | the queue |
| 8 | Search targets | #81 | `gsc_queries`, `gsc_pairs` | `targets` | the queue |
| 9 | Loop export and agent recipes | #82 | the above | nothing | aidevops |

Each issue after the first waits for the one it builds on: #75 for 3, 4,
8 and 9; #77 for 5, 6 and 7.

Experiments come first because they need nothing new to measure: the
change log already gives the intervention and its time, and the search
and visit tables give the outcome. The queue comes second because it
ranks what already exists (Opportunities) and hands finished work to
experiments. Every later feature is one more source of queue items.

Already planned elsewhere and joined to the loop, not repeated here: page
detail with snapshots and word diffs (Phase 3; it will list a page's
experiments and queue items), movers and anomalies (Phase 3; a later queue
source), crawlers and AI bots (Phase 5), and alerts and email reports
(Phase 6; the weekly email carries the top queue items and experiments due
for review).

## 1. Experiments

An experiment is a hypothesis about one page or a group of pages, written
before the result is known: the change, the primary measure, the expected
direction and size, and when to review it.

### Data

`experiments` (schema v8), one row per experiment, kept for good (small):

| Column | Meaning |
|---|---|
| `id` | |
| `created`, `user_id` | when and by whom it was recorded |
| `name` | the hypothesis in one line (190 characters) |
| `start` | the time of the change (Unix); from a change (`change_id`) or given |
| `days` | each window's length: 7, 14, 28 (default), 56 or 84 days, so both windows hold each weekday equally often |
| `review` | the day the after window's data is complete (site date) |
| `engine` | 1 Google, 2 Bing |
| `metric` | 1 clicks, 2 impressions, 3 CTR, 4 position, 5 visits from search, 6 conversions of a goal |
| `direction` | 1 up, 2 down (for position, up means a better, lower number) |
| `threshold` | the smallest change that counts: percent for counts and CTR, tenths of a place for position |
| `change_id` | the change it measures (0: none) |
| `path_id` | its page (0: several, listed in `meta.pages`, up to 50) |
| `status` | 1 running, 2 decided, 3 cancelled |
| `result` | 0 none, 1 keep, 2 revise, 3 undo, 4 inconclusive (the person's or agent's decision) |
| `decided` | when |
| `meta` | JSON: `pages`, `goal`, `note`, `hypothesis` (longer text), and at decision the measurement it was decided on (`measured`), so later data never rewrites a decision |

Keys: primary `id`; `status_review (status, review)` for due ones;
`path_id` for a page's experiments; `start` for the timeline. Listing reads
the newest 50 by the primary key.

Each experiment also writes a change of kind 81 (`experiment`, group
`note`) at its start, on its page (or site-wide for a group), so it shows
on the markers lane and in Changes with no new marker code; deleting the
experiment deletes it.

### Measuring

`SEOProStats_Experiments::measure()` runs when an experiment is read (and
is cached like the reports, keyed by the newest import):

- **Windows**: let S be the start's site day. Google: before S−days …
  S−1, after S+1 … S+days (the change day itself is left out). Bing's
  page rows are weekly, on the week's last day: before takes rows dated
  S−days … S−1, after takes rows dated S+7 … S+days+6, so every after
  week begins after the change. The review day is the after window's last
  day; until search data reaches it (`through`), the answer is `running`
  with the days so far.
- **The pages**: clicks, impressions, CTR and position from `gsc_pages`,
  one read per window by the primary key's `(engine, day)` prefix, grouped
  by page, which gives the experiment's pages and the comparison group
  together; visits from search from `daily` (`SEARCH_LANDING`, the same
  read as the Content report); conversions with
  `SEOProStats_Conversions::by_entry()` for the goal (its hits in the two
  windows only).
- **Comparison group**: pages with data in both windows that are not in
  the experiment and have no change of their own (any `changes` row on
  their `path_id`, read by key `ts` with `SEOProStats_Changes::between()`,
  or the pages of another experiment started then) from the before
  window's start to the after window's end; the 200 with most impressions
  (visits, for visits and conversions) before. Site-wide changes touch
  every page, so they do not exclude a page; they are listed as
  confounders.
- **Effect**: for counts and CTR, the pages' ratio (after ÷ before) over
  the group's ratio, less 1; for position, the pages' change in places
  less the group's. Without a group (fewer than 5 pages) the effect is
  the raw change and the answer says `compared: false`.
- **Noise**: each page of the group is also measured on its own as if it
  had been changed. The experiment's effect is beyond noise when it lies
  outside the group's 10th to 90th percentile. A group of pages is
  measured as one, which is less noisy than one page, so this test is a
  little lenient for groups; the answer says how many pages each side has.
- **Too little data**: fewer than 20 clicks (clicks, CTR, position: 200
  impressions) in either window on the experiment's pages, fewer than 20
  visits from search, or fewer than 5 conversions.
- **Confounders**: search engine updates rolling out in either window
  (`SEOProStats_Changes::updates_between()`), site-wide changes in the
  windows, and other changes on the experiment's own pages after the start.

### Suggested result

The plugin suggests; a person or agent decides and can note why.

| Suggested | When |
|---|---|
| inconclusive | too little data; or the effect is within noise; or there is no comparison group and a search update rolled out in a window |
| keep | the expected direction, beyond noise, at least the threshold |
| revise | the expected direction, beyond noise, under the threshold |
| undo | the opposite direction, beyond noise |

Decided experiments keep their numbers. Null and negative results stay in
the list and in the export.

### Interfaces and place in the app

- REST: `GET /experiments` (list; `status`, `page`), `POST /experiments`
  (add; from `change` or `start`), `GET /experiments/{id}` (with the
  measurement), `POST /experiments/{id}` (decide, note, cancel),
  `DELETE /experiments/{id}`. Read: `view_seoprostats`; write:
  `manage_options`.
- WP-CLI: `wp seoprostats experiments [list|add|show|decide|cancel|note|delete]`.
- Abilities: `seoprostats/experiments` (read) and
  `seoprostats/experiment-record` (add and decide).
- Dashboard: Search → **Experiments**: due ones first, then running, then
  decided; each shows its windows, the pages' and the group's figures, the
  effect, the suggestion and the confounders. "Start an experiment" from a
  row in Changes (the change and its page filled in) or from a queue item.
- Demo data: a few experiments on the demo's own changes
  (`SEARCH_CHANGES` and the CTR fix in `CHANGES`), one of each result.

## 2. Decision queue

One ranked list of things to do, built from what the reports already
find. Items are worked out when the list is read; only the ones a person
or agent has acted on are stored.

### Items

| Kind | From | Why it is listed | Metric if done | Effort |
|---|---|---|---|---|
| `ctr` | Opportunities → low CTR | chosen less than its place earns: rewrite the title and description | CTR | 1 |
| `missing` | Opportunities → missing from the page | searched words the page never uses: answer them | clicks | 2 |
| `striking` | Opportunities → striking distance | ranks 4–20 with demand: improve the page and its links | position | 2 |
| `decay` | Opportunities → losing clicks | lost clicks, with the cause: investigate, then update | clicks | 3 |
| `overlap` | Opportunities → overlapping pages | pages share a query: make one the clear answer, or leave it | clicks (all its pages) | 3 |
| `audit` | Audit (one item per page and finding) | what the content audit found: fix it | impressions (noindex, canonical), CTR (title, description), else clicks | 1 (thin 3) |
| `links` | Internal links (one item per page and list) | orphans, converting pages with few links in, missing links | clicks | 1 (converting 2) |
| `index` | Indexation (one item per page and list) | published or in the sitemap, never or no longer shown by search | impressions | 2 (sitemap 1) |
| `refresh` | Refresh planner (one item per losing page with content facts, in place of `decay`) | update, leave, protect or merge, with the reason | clicks (merge: both pages); leave: none | 3 (leave 1) |
| `sitemap` | Search Console sitemaps (one item per sitemap and problem; Google) | errors, not downloaded lately, warnings, or the site's sitemap not submitted | none | 1 (errors, stale 2) |
| later kinds | targets | each feature below | | |

Each item names its page (and query where it has one), the numbers behind
it and a sentence saying why.

### Score

```text
score = potential clicks per 28 days × value × confidence ÷ effort
```

- **Potential clicks** come from the opportunity (striking: potential
  clicks; low CTR: missed clicks; decay: clicks lost; missing: impressions
  × the site's expected CTR at its position × 0.3, as covering a query
  earns part of it; overlap: the clicks the query would get with the best
  of its pages' CTRs, so an overlap no page does better on is no item;
  audit: the page's impressions × the site's expected CTR at its position
  × the finding's share, from 1 for noindex and canonical to 0.02 for
  several H1s or images without alt text), scaled to 28 days. The queue
  reads the chosen period, up to a year (seasonal sites see the year's
  searches); decay and refresh items are scaled by the days losing
  clicks compared, which are fewer when the earlier period would start
  before the first search day (the answer's `decay`).
- **Value** is how well visits from search to the page convert against
  the site: the page's conversion rate of the Content report's goal
  (smoothed toward the site's rate with 20 visits) ÷ the site's rate, from
  1 to 5. A page that sells is worth more; a page that converts no better
  than the site, or has no goal data, is worth 1. Without a goal every
  page is 1. The goal can be chosen (`goal`); the first is the default.
- **Confidence** is the kind's own (decay 0.8, CTR 0.7, striking 0.6,
  missing 0.5, audit 0.5, overlap 0.4) times √(impressions per 28 days ÷ 1,000), that root at
  most 1.
- **Effort** is the kind's (above). A person can set an item's effort
  (1–5).
- Pages with a running experiment are left out of new items, as a second
  change would spoil the measurement (an overlap item when any of its
  pages has one).

The answer gives each part, not only the score, so an agent can rank by
its own rule.

### Queue data

`queue` (schema v9), one row per item someone acted on:

| Column | Meaning |
|---|---|
| `id` | |
| `ikey` | BINARY(8): hash of kind, engine, page and query; unique |
| `kind`, `engine`, `path_id`, `query_id` | the item |
| `status` | 0 new (stored only with an effort or note), 1 accepted, 2 done, 3 dismissed |
| `effort` | as set (0: the kind's) |
| `experiment_id` | the experiment opened when done |
| `created`, `updated`, `user_id` | |
| `note` | 190 characters |
| `meta` | JSON: the numbers and reason when it was accepted |

Keys: primary `id`; unique `ikey`; `status_updated (status, updated)`.
Reading the list: the opportunities (cached), then one read of `queue` by
`ikey IN (…)` for their states, and one by `status_updated` for accepted
and done items that are no longer found. Dismissed items stay hidden for 90
days, then come back if they are still found.

**Done** opens an experiment on the item's page, starting now, with the
item's metric, expected up (position: better), 28 days and a threshold of
10% (position: one place), unless one is given. When that experiment is
decided, the item shows the result.

### Queue interfaces and place in the app

- REST: `GET /queue` (`range`, page filters, `engine`, `goal`, `status`,
  `limit`, `offset`), `POST /queue/{key}` (accept, done, dismiss,
  restore, effort, note; the same period, engine and goal as the list).
  WP-CLI: `wp seoprostats queue
  [list|accept|done|dismiss|restore|effort|note]`. Abilities:
  `seoprostats/queue` (read) and `seoprostats/queue-update`.
- Dashboard: Search → **Plan**: the ranked list with each item's why and
  score parts, and Accept, Done (opens the experiment), Dismiss, Restore,
  and the effort and a note.
- Demo data: the demo's opportunities make the items; one is accepted and
  one is done with its experiment running.

## 3. Overlapping pages

Queries where two or more pages each get at least 10% of the query's
impressions, from one grouped read of `gsc_pairs` (the 2,000 pairs with
most impressions, as the other Opportunities), with each page's clicks,
position and share, and whether the page with most impressions changed
between the halves of the period. Shown in Opportunities as a candidate
to review, never as a fault: two pages for one query can be right (a
guide and a product page). Queue kind `overlap`, effort 3, confidence 0.4.

Built (GH#76):

- The share is of the impressions of the query's pages read, and a query
  needs `rules.min_impressions` in all. Rows are the leading page's with
  the query's sums, up to five pages with clicks, impressions, position
  and share, most impressions on pages other than the leading one first.
- Halves: the period's first half (whole weeks for Bing, whose rows are
  weekly) is read for the rows shown, by `query_day`; the second half is
  the rest. `leaders` gives each half's leading page, `switched` whether
  it changed; `halves` is null when the period is too short.
- `potential`: the clicks the query would get if all its pages'
  impressions had the best of their CTRs, less its clicks. The queue item
  is on the leading page with every page in `figures.pages`; it is left
  out while any of them has an experiment running, and done opens one on
  all of them, measuring clicks.
- REST and abilities `kind=overlap`; WP-CLI `wp seoprostats opportunities
  overlap`. Demo data: queries with a second page, and one whose second
  page has led for the last 40 days.

## 4. Content audit from WordPress

Facts read from each published post and its SEO plugin's fields, never on
visitor pages: when a post is saved, and in daily cron batches of 200 for
the rest (and after an SEO plugin is switched). `page_facts` (schema v10),
one row per page (`path_id` primary key): post, checked time, title and
SEO title length, description length, hashes of the SEO title and
description (keys, for duplicates), H1 count, words, images and images
without alt text, modified time, noindex, canonical to another address.
Findings: missing or long title or description, duplicate title or
description, no or several H1, thin page (few words with impressions but
no clicks), images without alt text, noindex or canonical away on a page
with search impressions. Each finding is a queue item weighed by the
page's search and conversions, so the ones that matter come first.
Demo data: `PAGE_TEXT` gains the facts. Retention: the row goes when the
post does.

Built (GH#77), schema v10:

- `page_facts`: `path_id` primary key; keys `post_id`, `checked`,
  `title_hash`, `desc_hash` and `flags` (the page's own findings as bits,
  so the report reads only pages with one). Thin needs search figures, so
  the flag is `short` (under 300 words) and the report adds "impressions
  but no clicks".
- Reading: `save_post`, `deleted_post` and changes to the SEO plugins'
  meta keys mark a post (not while importing); it is read once at the end of that
  request (`shutdown`), when every field is saved. The daily cron reads at
  most 200 posts in 20 seconds, by the posts table's primary key from
  where it stopped: posts with no facts, changed since, or read more than
  30 days ago; all again after the SEO plugin changes. A post no longer
  published loses its row.
- Facts: the title shown (the SEO plugin's title, else the post title;
  a title made only of the plugin's variables counts as the post's), the
  description (the SEO plugin's, else the excerpt), H1s in the text,
  words, images and those without alt text, noindex and a canonical
  address on another page (Rank Math, Yoast SEO, SEOPress, All in One SEO).
- Findings and thresholds: title over 60 characters, description over
  160, thin under 300 words with at least max(10, days) impressions and no
  clicks; noindex and canonical only on pages with impressions. Duplicates
  come from the hash keys (groups of two or more, up to five pages named).
  Pages are listed most impressions first.
- Queue kind `audit`, one item per page and finding (key: kind, engine,
  page and finding), confidence 0.5, effort 1 (thin 3); done measures
  impressions for noindex and canonical, CTR for title and description,
  clicks otherwise.
- REST `GET /audit` (`finding`, `engine`, period, page filters, `limit`,
  `offset`); WP-CLI `wp seoprostats audit [list|run]` (`run` reads a
  batch now); ability `seoprostats/audit`. Dashboard: Search → **Audit**,
  with a finding filter that counts pages per finding; Plan shows the
  items as `Content audit: <finding>`.
- Demo data: `PAGE_SEO` gives demo pages SEO titles, descriptions and
  extra markup: a missing and a long description, a long and a shared
  title, a shared description, an H1 in the text, an image without alt
  text, noindex and a canonical address elsewhere.

## 5. Internal links

Links between the site's own posts, read from `post_content` when a post is
saved (the change log already parses them) and in the audit's cron batch:
`page_links` (a later schema), `(from_path, to_path)` primary key with the
anchor text's dictionary id and a count, key `to_path`. Reports: pages with
no contextual links in (orphans), pages that convert with few links in,
and pages that rank for a query whose intended page (a search target, or
the page with most clicks for it) they do not link to. Queue kind
`links`.

Built (GH#78), schema v11:

- `page_links`: `(from_path, to_path)` primary key, key `to_path`; the
  first link's text (`DICT_LABEL` id) and how many links. `page_facts`
  gains `links_in` (other pages linking in, key `links_in`), kept current
  as links are written, so orphans and pages with few links in are read by
  that key alone.
- Reading: with the content audit's facts, from the same text, on save
  and in its daily batch; a page's links are replaced each time it is
  read. Links to WordPress's folders, feeds and files are left out, paths
  get the permalinks' trailing slash, queries are kept only with plain
  permalinks, and at most 300 pages per page. A page no longer published,
  or at an old address (a changed slug or parent), loses its links. After
  the update every page is read again (`state()['links']` holds when); the
  report says how many were (`read`), and the first read on live data
  reads a few pages at once.
- Lists: `orphans` (no other page links in), most impressions first;
  `converting` (visits from search reached the goal 3 or more times, 2 or
  fewer pages link in), most conversions first (the front page is in
  neither, as menus link to it); `missing` (a page with at least max(10, days)
  impressions on a search does not link to the search's page with most
  clicks), one row per page pair with up to 5 searches, from the 2,000
  pairs with most impressions. Menus and widgets are not read, so a page
  linked only from a menu is an orphan; that is the point.
- Queue kind `links` (code 7), one item per page and list (its key has the
  list in place of a query; a missing link's item sits on the page to link
  to, with the page that should link in `figures.link_from`): potential
  clicks the page's impressions (missing: those of the searches on the
  linking page) × the site's CTR at its position × 0.1 (missing 0.2),
  confidence 0.4, effort 1 (converting 2); done measures clicks.
- REST `GET /links` (`kind`, `goal`, `engine`, period, page filters,
  `limit`, `offset`); WP-CLI `wp seoprostats links [--kind=<kind>]
  [--goal=<id>]`; ability `seoprostats/links`. Dashboard: Search → Audit,
  **Internal links** under the findings, with a list switch that counts
  each list; Plan shows the items as `Internal links: <list>`.
- Demo data: `PAGE_LINKS` links the demo pages: the update post and the
  FAQ are orphans, the pricing page converts (Purchase) with two links
  in, and the features page, the rankings guide and the front page miss a
  link to a page that gets their search's clicks.

## 6. Indexation

Published pages (from `pages` and `page_facts`) with no impressions after
N days (default 28) since publishing, by `gsc_pages` `path_day`; addresses
in the site's own sitemaps (WordPress's sitemap providers, read in cron,
no fetch) that never had impressions. Google's URL Inspection, within a
daily cap, adds Google's reason for each (GH#144, below). Queue kind
`index`.

Built (GH#79), schema v12:

- `page_facts` gains `published` (when the post was published, key
  `published`), written with the audit's facts; after the update every
  page is read again (`state()['published']` holds when). `sitemap`:
  `path_id` primary key, `source` (1 category and tag archives, 2 author
  archives, 3 another plugin's provider), `first_seen` and `seen`, keys
  `first_seen` and `seen`.
- Reading the sitemaps: the daily cron asks WordPress's sitemap providers
  for their addresses (no request), all but posts, which are the audit's
  pages: at most 5,000 addresses in 20 seconds. New addresses are first
  seen now; after a complete read, addresses no longer listed go. With
  WordPress's sitemaps off (an SEO plugin makes its own) nothing is read
  and the report says so (`read.sitemap.enabled`). The first report on
  live data reads them briefly when the cron has not run yet.
- Lists, over the engine's newest N days of imported data (`days`, 7 to
  365, default 28): `pages`, published at least N days before the end
  with no impressions in them, by `page_facts`'s `published` key (the
  newest 5,000); noindex pages and canonicals elsewhere are left out and
  counted (`skipped`); `sitemap`, addresses first listed at least N days
  before the end, by the `first_seen` key (the newest 5,000), with none.
  `gsc_pages` is read by `path_day` for those pages only: which had
  impressions in the window, then the last day of the others' (`state`
  `lost` with `last_impression`, or `never`). Never shown first, then the
  newest; the lists are cached and paged from the cache.
- Queue kind `index` (code 8), one item per page and list (the list in
  place of a query): potential clicks are a shown page's clicks per 28
  days in the window (`typical`) × 0.5 (sitemap 0.2), so no items without
  search data; confidence 0.3, not weighed by impressions (there are
  none); effort 2 (sitemap 1); done measures impressions.
- REST `GET /indexation` (`kind`, `days`, `engine`, page filters, `limit`,
  `offset`); WP-CLI `wp seoprostats indexation [run] [--kind=<kind>]
  [--days=<n>]` (`run` reads the sitemaps now); ability
  `seoprostats/indexation`. Dashboard: Search → Audit, **Indexation**
  under Internal links, with a list switch that counts each list; Plan
  shows the items as `Indexation: <list>`. Shared Search reports carry it.
- Demo data: the indexing checklist page, published 45 days ago, has no
  search data; the sitemap has two category archives and an author page
  listed for 120 days with none, and a category listed 10 days ago, too
  new to list.

### Search Console sitemaps and URL Inspection

Built (GH#144), schema v19. While Google Search Console is connected the
hourly import, after the search data and with its own budget (20
seconds a run):

- Reads the property's sitemaps once a day (`sitemaps.list`): path, type,
  index or not, pending, when submitted and last downloaded, errors,
  warnings and addresses submitted per type. Google's indexed count is
  deprecated and not shown. Problems: `errors`, `stale` (not downloaded
  for over 7 days), `warnings`, and `missing` when the site's own sitemap
  index (the SEO plugin's, else WordPress's `wp-sitemap.xml`) is not
  submitted and no other index on the site is. Kept in an option per
  data set.
- Inspects pages with the URL Inspection API (`index:inspect`, language
  `en-US` so coverage states are Google's English words): at most the
  `inspections` setting a day (Settings → Data, default 200, 0 to 2,000,
  Google's own limit per property; counted per Google day, Pacific time),
  first the pages Indexation lists, then those with search impressions
  in the newest 28 days (never inspected first, then the oldest), each
  again after 14 days. Never on visitor pages or report requests; `wp
  seoprostats inspect --run` runs it now. Google's quota or permission
  errors stop the run and are shown.
- `inspections` table: one row per page (`path_id` primary key) with
  `checked`, codes of the verdict, indexing, robots.txt, page fetch,
  crawler and rich result verdict, the coverage state (dictionary),
  Google's and the page's canonical (dictionary), the last crawl,
  finding `flags` and `details` (rich result types and issues, up to five
  sitemaps and referring pages, the Search Console link); keys `checked`,
  `verdict_checked`, `coverage_checked`, `flags`.
- Findings: `robots_blocked` (robots.txt disallows, or the page or fetch
  is blocked by it), `not_indexed` (coverage "Crawled - currently not
  indexed"), `google_canonical` (Google chose another canonical than the
  page's own, or the page when it declares none) and `rich_errors` (a
  rich result type with errors). They are content audit findings, so
  queue kind `audit` items, with the audit's effort and shares.
- A verdict change on live data is a timeline change (`index_status`,
  old and new coverage state).
- Sitemap problems are queue kind `sitemap` (code 11): potential clicks a
  shown page's clicks per 28 days × the problem's share (errors and
  missing 1, stale 0.5, warnings 0.2); confidence 0.4; effort 1 (errors
  and stale 2); done opens no experiment.
- REST `GET /inspections` (`verdict`, `coverage`, `finding`, page filters,
  `limit`, `offset`); `/indexation` and `/audit` rows carry `google`;
  WP-CLI `wp seoprostats inspect [<page>] [--run] [--sitemaps]
  [--verdict=<verdict>] [--coverage=<state>] [--finding=<finding>]`;
  `wp seoprostats doctor` shows the run; ability
  `seoprostats/inspections`. Dashboard: Search → Audit, **Google's
  index** under Indexation (sitemaps and pages inspected, with a verdict
  switch), a Google column in Indexation, and the four findings in the
  audit.
- Demo data: twelve pages inspected (indexed, a page blocked by
  robots.txt, noindex, an alternate, a duplicate where Google chose the
  pricing page, three crawled or discovered but not indexed, product
  snippet errors), a verdict change on the timeline, and three sitemaps:
  the site's index, a child with warnings and an old one with an error
  that Google has not downloaded for a month.

## 7. Refresh planner

For pages losing clicks: content age (`page_facts.modified`), words,
conversions and the cause from losing clicks give a proposal: **update**
(lost position, content old), **leave** (lost demand: fewer people search),
**protect** (it converts; change carefully), or **merge** (an overlapping
page holds the query). Proposals, never actions; queue kind `refresh`
replaces `decay` for pages it covers.

Built (GH#80), no schema change (`SEOProStats_Refresh`):

- Losing clicks (`decay`) gives each of its queries that lost most a
  `rival`: another page of the site with at least 10% of the query's
  impressions now that ranks better than the losing page now and did not
  before (or was not shown then), the one with most clicks. `gsc_pairs`
  is read by `query_day` for those queries only, every page, both periods.
- A losing page whose content facts the audit has (`page_facts`, read by
  its primary key) gets a queue item of kind `refresh` (code 9) in place
  of its `decay` item, its proposal in `finding` (the key's query), the
  first that holds of: `leave` (the cause is demand: fewer people search,
  the position held); `protect` (its visits from search convert at 2× the
  site's rate or more, smoothed as the queue's value, with at least 3
  conversions); `merge` (a query it lost has a rival; both pages in
  `figures.pages`, so done measures both); else `update`, with what to do
  from the cause and
  the content's age: changed within the periods compared (see what the
  change removed), old (over 365 days), or neither.
- `why` gives the clicks lost and the cause, then the conversions
  (protect), the rival with its positions (merge) and the content's age
  and words; `figures` has the content facts (`modified`, `age`, `old`,
  `changed`, `published`, `words`, `links_in`), `visits` and
  `conversions` (null without goals), `lost_queries` with their rivals and
  for merge `rival`. The thresholds are in `rules.refresh`.
- Score: potential clicks are those lost × the proposal's share (leave
  0.2, as fewer searches come back with demand, not a change; else 1),
  scaled to 28 days; confidence 0.8, weighed by impressions as decay's;
  effort 3 (leave 1). Done measures clicks, except leave: the page stays
  as it is, so done opens no experiment. Only a running experiment on the
  losing page itself holds a refresh item back (as its decay item), not
  one on a merge's other page: the loss stays in view.
- Nothing changes content. `GET /queue`, `wp seoprostats queue` and the
  ability `seoprostats/queue` take `kind` (e.g. `refresh`); the CLI shows
  the kind with its proposal. Dashboard: Plan shows `Refresh: <proposal>`
  with the facts behind it; Opportunities → losing clicks shows which
  page overtook each query.
- Demo data: the three losing pages get three proposals: the getting
  started guide (fewer searches) leave, the features page (chosen less)
  update, and the update post (ranks lower, the rankings guide overtook
  it for "why did my rankings drop") merge.

## 8. Search targets

The site's chosen queries and the page meant for each: `targets` (schema
v13), `query_id` primary key, `path_id`, priority (0–100), status, source.
Imported from a simple list (query, address, priority) or the aidevops
search targets table (`phrase`, `target_url`, `priority`, `status`). The
report gives each target's position, clicks and the page that ranks; a
target ranking with another page is a queue item (`target`), as is a
high-priority target in striking distance.

Built (GH#81), schema v13 (`SEOProStats_Targets`):

- `targets`: `query_id` (the dictionary's query, lower case; primary
  key), `path_id` (0: no page chosen yet), `priority` (0–100, 50 when
  left out; high 80, medium 50, low 20), `status` (candidate, targeted,
  live, won, retired; `active` imports as targeted), `source` (list,
  aidevops, demo), `created`, `updated`, `user_id`. At most 1,000
  targets; read whole by its primary key.
- Import: CSV or tab-separated text (a header row naming query, page,
  priority and status under any of their usual names, or those columns
  in that order), JSON (a list, or an object with `targets`), or the
  aidevops search targets table (TOON, its first table with a `phrase`
  or `query` column). Each row is checked: no query text, an address not
  on this site, or a priority or status that cannot be read skips the row
  with its number and reason, never a guess. Searches already listed are
  updated; `replace` deletes those not in the import. At most 5,000 rows
  and 1 MB.
- Report: per target, for the period (cut at the newest search day, to
  its newest 366 days), the query's clicks, impressions, CTR and position
  on any page, the page search shows most for it (`shown`, with its share
  of the impressions), and the page meant for it with its own figures, as
  a state: `ranking` (the page meant for it is the one shown most, or
  search gave no page), `wrong_page`, `no_page` (none chosen) or
  `not_shown` (no impressions). Highest priority first. Reads
  `gsc_queries` and `gsc_pairs` by `query_day` for the targets' queries
  only.
- Queue kind `target` (code 10), one item per target and finding, the
  key's query the finding and the query: `wrong_page` for an open target
  (candidate, targeted, live) shown with another page (potential clicks:
  impressions × the site's CTR at its position × 0.5; done measures both
  pages' clicks), and `striking` for an open target of priority 70 or
  more at positions 4–20 with its page or none chosen (potential clicks
  as striking distance's; measured by position), in place of its plain
  striking item. Both × priority ÷ 50; confidence 0.6, weighed by
  impressions; effort 2.
- REST `GET /targets` (`status`: all, open or one status; `engine`,
  period, `compare`, `limit`, `offset`), `POST /targets` (`targets` as a
  list or `text`, `replace`) and `DELETE /targets` (`queries` or `all`);
  WP-CLI `wp seoprostats targets [list|import <file|->|delete <query>...]`;
  abilities `seoprostats/targets` (read) and `seoprostats/targets-import`.
  Writes need `manage_options`. Dashboard: Search → **Targets**, with a
  status switch, an import form and delete for administrators; Plan shows
  `Search target: <finding>`. Shared reports leave Targets out.
- Demo data: nine targets: two shown with another page, three
  high-priority ones in striking distance (one with no page chosen), one
  won, one retired, one ranking as meant, one not shown yet.

## 9. Loop export and agent recipes

`GET /loop` (and `wp seoprostats loop --format=json`, ability
`seoprostats/loop`) answers the queue, due and recent experiments with
their results, and per-query figures for the period in the aidevops export
layout (query, page, clicks, impressions, CTR, position), so an agent reads
one answer per cycle. `docs/seo-loop-recipes.md` gives the steps for an
agent: read `/loop`; propose and accept items; make the change in
WordPress; mark the item done (which records the experiment); on the
review day decide it from the measurement; feed results into the next
plan.

As built (`SEOProStats_Loop`):

- `queue`: the open items (new and accepted) of `/queue`, best first
  (`limit`, 20 by default, at most 200), with its counts in `summary`.
- `experiments`: `due` (running, with search data through the review
  day), `running`, and `decided` in the last 90 days
  (`experiments.recent_days`) with the measurement each was decided on,
  null and negative results included.
- `export`: per query and page for the period, most impressions first
  (`rows`, 1,000 by default, at most 5,000; `more` when cut), with the
  file header (`domain`, `source` `gsc` or `bing`, `exported`,
  `start_date`, `end_date`). `wp seoprostats loop --format=toon` writes it
  as an aidevops export file.
- `params`: the period (30 days by default, cut at the newest search day
  and to its newest 366 days, as the queue), engine, goal, page filters and
  data set, to pass back with `POST /queue/{key}`.

Nothing is stored. The export is one grouped read of `gsc_pairs` by its
primary key (`path_day` with page filters), cached like the reports until
the next search import; the queue and experiments are read as their own
routes read them.

## Dropped or deferred, and why

| Candidate | Decision |
|---|---|
| E-E-A-T scoring | Dropped: judging authorship, originality and effort is an agent's or person's job; the audit gives the facts (authors, dates, words) |
| AI answers (captures of AI search answers) | Deferred to Phase 5 with crawlers and AI bots; kept apart from search ranking |
| Engine URL inspection | Built for Google (GH#144), within a daily cap below Google's quota; Bing's is not used |
| A full crawl of the site | Dropped: WordPress already knows its pages, titles, links and fields; reading them in cron costs less and needs no outside request |
| Statistical significance tests | Deferred: daily search data is noisy and pages are few; the comparison group's spread is the honest bar for now. A/B tests of blocks (issue #29) are where sample sizes belong |
| Separate digest work | Joined to Phase 6 alerts and email reports |

## Cost

- Visitor pages: nothing. Experiments, the queue and their reads run in
  reporting requests and cron.
- Experiments: per experiment and window, one indexed read of `gsc_pages`
  for its pages, one of `daily`, one for its goal's hits; the comparison
  group two primary-key range reads of `gsc_pages` and one `changes` read
  by `ts`, shared by the experiments of one list (same windows) and cached.
- Queue: the four Opportunities answers (already cached) plus two indexed
  reads of `queue`.
- New tables are small: experiments and queue rows are made by people and
  agents, not visitors.

## Changes aidevops would need

For the owner to file in aidevops:

1. An SEO loop recipe that reads the plugin's REST (`/loop`, `/queue`,
   `/experiments`) with an Application Password and runs the weekly cycle
   above.
2. `aidevops keywords track --source seoprostats`: take the plugin's loop
   export (or its search rows in the export layout) as a tracking run.
3. The search targets standard: name the plugin's `targets` import as a
   reader of `targets.toon` (`phrase`, `target_url`, `priority`, `status`).
4. The experiment design guide: name the plugin as a place where an
   experiment's pre-registration and outcome are recorded and measured.
