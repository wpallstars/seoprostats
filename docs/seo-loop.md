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
| 4 | Content audit | #77 | posts, SEO plugin fields, `gsc_pages` | `page_facts` | the queue |
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
- **The pages**: clicks, impressions, CTR and position from `gsc_pages` by
  `path_day` (one read per window); visits from search from `daily`
  (`SEARCH_LANDING`, by `dim_val_day`); conversions with
  `SEOProStats_Conversions::by_entry()` for the goal (its hits in the two
  windows only).
- **Comparison group**: pages with search data in both windows that are
  not in the experiment and have no change of their own (any `changes` row
  on their `path_id`, read by key `ts`) from the before window's start to
  the after window's end; the 200 with most impressions before. Their
  figures are summed the same way (two reads of `gsc_pages` by the primary
  key's `(engine, day)` prefix). Site-wide changes touch every page, so
  they do not exclude a page; they are listed as confounders.
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
- WP-CLI: `wp seoprostats experiments [list|add|show|decide|delete]`.
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
| later kinds | overlap, audit findings, orphans, not indexed, refresh, targets | each feature below | | |

Each item names its page (and query where it has one), the numbers behind
it and a sentence saying why.

### Score

```text
score = potential clicks per 28 days × value × confidence ÷ effort
```

- **Potential clicks** come from the opportunity (striking: potential
  clicks; low CTR: missed clicks; decay: clicks lost; missing: impressions
  × the site's expected CTR at its position × 0.3, as covering a query
  earns part of it), scaled to 28 days.
- **Value** is 1 plus how well visits from search to the page convert
  against the site (the Content report's goal; smoothed toward the site's
  rate with 20 visits), so a page that sells is worth more and a page with
  no goal data is worth 1. Without a goal every page is 1.
- **Confidence** is the kind's own (decay 0.8, CTR 0.7, striking 0.6,
  missing 0.5) times √(impressions per 28 days ÷ 1,000), at most 1.
- **Effort** is the kind's (above). A person can change an item's effort.
- Pages with a running experiment are left out of new items, as a second
  change would spoil the measurement.

The answer gives each part, not only the score, so an agent can rank by
its own rule.

### Data

`queue` (schema v8), one row per item someone acted on:

| Column | Meaning |
|---|---|
| `id` | |
| `ikey` | BINARY(8): hash of kind, engine, page and query; unique |
| `kind`, `engine`, `path_id`, `query_id` | the item |
| `status` | 1 accepted, 2 done, 3 dismissed |
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

### Interfaces and place in the app

- REST: `GET /queue` (`engine`, `status`, `limit`), `POST /queue/{key}`
  (accept, done, dismiss, restore, effort, note). WP-CLI: `wp seoprostats
  queue [list|accept|done|dismiss|restore]`. Ability: `seoprostats/queue`.
- Dashboard: Search → **Plan**: the ranked list with each item's why and
  score parts, and Accept, Done (opens the experiment), Dismiss.
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

## 4. Content audit from WordPress

Facts read from each published post and its SEO plugin's fields, never on
visitor pages: when a post is saved, and in daily cron batches of 200 for
the rest (and after an SEO plugin is switched). `page_facts` (schema v9),
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

## 5. Internal links

Links between the site's own posts, read from `post_content` when a post is
saved (the change log already parses them) and in the audit's cron batch:
`page_links` (schema v9), `(from_path, to_path)` primary key with the
anchor text's dictionary id and a count, key `to_path`. Reports: pages with
no contextual links in (orphans), pages that convert with few links in,
and pages that rank for a query whose intended page (a search target, or
the page with most clicks for it) they do not link to. Queue kind
`links`.

## 6. Indexation

Published pages (from `pages` and `page_facts`) with no impressions after
N days (default 28) since publishing, by `gsc_pages` `path_day`; addresses
in the site's own sitemaps (WordPress's sitemap providers, read in cron,
no fetch) that never had impressions. Engine URL inspection (Search
Console's has a daily quota) is a later opt-in step for chosen pages only.
Queue kind `index`.

## 7. Refresh planner

For pages losing clicks: content age (`page_facts.modified`), words,
conversions and the cause from losing clicks give a proposal: **update**
(lost position, content old), **leave** (lost demand: fewer people search),
**protect** (it converts; change carefully), or **merge** (an overlapping
page holds the query). Proposals, never actions; queue kind `refresh`
replaces `decay` for pages it covers.

## 8. Search targets

The site's chosen queries and the page meant for each: `targets` (schema
v9), `query_id` primary key, `path_id`, priority (0–100), status, source.
Imported from a simple list (query, address, priority) or the aidevops
search targets table (`phrase`, `target_url`, `priority`, `status`). The
report gives each target's position, clicks and the page that ranks; a
target ranking with another page is a queue item (`target`), as is a
high-priority target in striking distance.

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

## Dropped or deferred, and why

| Candidate | Decision |
|---|---|
| E-E-A-T scoring | Dropped: judging authorship, originality and effort is an agent's or person's job; the audit gives the facts (authors, dates, words) |
| AI answers (captures of AI search answers) | Deferred to Phase 5 with crawlers and AI bots; kept apart from search ranking |
| Engine URL inspection | Deferred: an opt-in follow-up to indexation, within each engine's quota |
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
