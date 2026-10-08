# SEO loop recipes for agents

How an AI agent, or a script, works the SEO decision loop (`docs/seo-loop.md`)
on a site with SEO Pro Stats: read one answer, choose work, make the
change, record it, measure it and decide. Every step uses public routes,
WP-CLI commands or abilities; nothing here needs access to the database.

The plugin proposes and measures. It never changes a page by itself: the
agent (or a person) makes the change in WordPress.

## Access

- **REST API**: `https://example.com/wp-json/seoprostats/v1`, signed in with
  an Application Password (Users → Profile → Application Passwords).
  Reading needs the `view_seoprostats` capability; accepting, marking done
  and deciding need `manage_options`, so use an administrator's password
  for the whole loop, or a reader's to only report.
- **WP-CLI**: `wp seoprostats loop`, `queue` and `experiments` on the server.
- **Abilities** (WordPress 6.9 and later, for MCP clients through the
  WordPress MCP adapter): `seoprostats/loop`, `seoprostats/queue-update`
  and `seoprostats/experiment-record`.

Add `data=demo` (`--data=demo`) to any step to try the loop on the demo
data first; live statistics are never changed by it.

```sh
SITE="https://example.com/wp-json/seoprostats/v1"
AUTH="admin:APPLICATION PASSWORD"
```

## The cycle

Run it once a week: search data arrives two or three days late, and
experiments are measured in whole windows of days.

### 1. Read the loop

```sh
curl -s -u "$AUTH" "$SITE/loop?range=30d"
wp seoprostats loop --format=json
```

One answer holds everything for the cycle:

| Field | What it is |
|---|---|
| `summary` | Counts: open items (`new`, `accepted`), `done`, `dismissed`, `left_out` (items held back by a running experiment), experiments `due`, `running` and `decided`, export `rows`. |
| `params` | The period, engine, goal (and page filters) the queue was made with. Pass the same to every queue action in this cycle. |
| `queue.items` | Open decision queue items, best first: `key`, `kind`, `page`, `query`, `why`, `todo`, `figures` and the score with its `parts` (potential clicks, value, confidence, effort). `queue.rules` has the numbers the scores use. |
| `experiments.due` | Running experiments with search data through their review day: decide them now (step 5). |
| `experiments.running` | Running experiments not yet due, with their measurement so far. |
| `experiments.decided` | Decided in the last `experiments.recent_days` days, with the result, note and the measurement the decision was made on, including null and negative results. |
| `export` | The period's search figures per query and page in the aidevops export layout: `domain`, `source`, `start_date`, `end_date`, `columns` and `rows` (`query`, `page`, `clicks`, `impressions`, `ctr`, `position`), most impressions first; `more` when `rows` cut it short. |

`connected` false means no search engine is connected yet: there is no
queue or export to work from. `through` is the newest day with search data.

### 2. Decide what to do first

Work the experiments `due` before new items: the result of a change
decides whether to repeat it elsewhere.

Then choose from `queue.items`. The score is potential clicks per 28 days
× value × confidence ÷ effort, and every part is given, so an agent can
rank by its own rule (for example, only items of one `kind`, or value
first for a shop). Read `why` and `figures` before acting: an overlap is a
candidate to review, not a fault, and a refresh item is a proposal
(`update`, `leave`, `protect` or `merge`) with its reason.

Before proposing a change, look at `experiments.decided`: a kind of change
that was undone or inconclusive on similar pages is weaker evidence than
one that was kept.

### 3. Accept, or dismiss

```sh
curl -s -u "$AUTH" -X POST "$SITE/queue/3f9c0a1b2d4e5f60?range=30d&engine=google" \
  -H "Content-Type: application/json" -d '{"action":"accept","note":"New title and description"}'
wp seoprostats queue accept 3f9c0a1b2d4e5f60 --range=30d
```

Use the period, engine and goal from `params`: an item no one has acted
on yet must still be in the list they make. `dismiss` hides an item for
`queue.rules.hide_days` days (say why in `note`); `effort` (1–5) sets
your own estimate of the work, which changes its score; `restore` forgets
what was done with it.

With the ability: `seoprostats/queue-update` with `key`, `action`, `note`
and the `range`, `engine` and `goal` from `params`.

### 4. Make the change, then mark it done

Make the change in WordPress: edit the title or description, add the
missing words or links, update the page. The change log records it as it
is saved. Once it is live, mark the item done:

```sh
curl -s -u "$AUTH" -X POST "$SITE/queue/3f9c0a1b2d4e5f60?range=30d&engine=google" \
  -H "Content-Type: application/json" \
  -d '{"action":"done","note":"Title now leads with the query","days":28,"threshold":10}'
wp seoprostats queue done 3f9c0a1b2d4e5f60 --range=30d --note="Title now leads with the query"
```

Done opens an experiment on the item's page, starting now, with the
kind's measure (CTR for low CTR, clicks for missing words, losing clicks
and refreshes, position for striking distance) expected to improve. That
is the pre-registration: what was changed, on which pages, what should
happen and by how much, written before the result is known. `days` is
the length of each window (7, 14, 28, 56 or 84; 28 by default) and
`threshold` the smallest change that counts (percent, or places for
position). The item then shows the experiment's id and result.

Change one thing per page at a time: pages with an experiment running get
no new items until it is decided, so its measurement stays clean.

### 5. Decide on the review day

An experiment is due when search data covers its review day. Read its
`measurement`:

- `suggested` (`keep`, `revise`, `undo` or `inconclusive`) with its
  `reasons`, and a one-line `summary`;
- `effect` and `improvement`: the change on the pages against the
  unchanged pages in the same days, so a season or a site-wide rise does
  not count;
- `noise`, `beyond_noise`: how far unchanged pages usually move;
- `enough`: whether there was enough data;
- `confounders`: search engine updates and other changes in the same
  days.

Then decide, with what was learnt in the note:

```sh
curl -s -u "$AUTH" -X POST "$SITE/experiments/12" \
  -H "Content-Type: application/json" \
  -d '{"action":"decide","result":"keep","note":"CTR +18% against 120 unchanged pages"}'
wp seoprostats experiments decide 12 keep --note="CTR +18% against 120 unchanged pages"
```

With the ability: `seoprostats/experiment-record` with `action` `decide`,
`id`, `result` and `note`.

The decision keeps the measurement it was made on. `undo` means put the
page back as it was; the plugin does not. A result is evidence about the
site, not proof of cause: record null and negative results too, as they
stop the same change being tried again.

### 6. Carry the results into the next plan

The next cycle's `experiments.decided` lists this cycle's results. Repeat
what was kept on similar items, avoid what was undone, and write down the
lesson where the team keeps its notes.

## Search data for other tools

`wp seoprostats loop --format=toon` writes only the export rows as an
aidevops export file (header lines, `---`, then tab-separated columns), so
tools that read that layout take the plugin's search data as a tracking
run without connecting to the search engines themselves:

```sh
wp seoprostats loop --range=90d --rows=5000 --format=toon > gsc-2026-07-01-2026-09-28.toon
```

`--engine=bing` writes Bing's (`source` `bing`). Over REST, the same rows
are in `export` of `GET /loop`.

## Search targets

Search targets (Search → Targets) are the searches the site chose to win
and the page meant for each. Import them from the aidevops search targets
table (`phrase`, `target_url`, `priority`, `status`):

```sh
jq -Rs '{text: .}' targets.toon | curl -s -u "$AUTH" -X POST "$SITE/targets" \
  -H "Content-Type: application/json" --data-binary @-
wp seoprostats targets import targets.toon
```

Rows that cannot be read are skipped and listed with their reason. The
queue then adds `target` items where another page ranks for a target, and
high-priority targets close to the top three. Import again when the
targets change: a search already kept is updated, and `"replace": true`
deletes the targets not in the import.

## Cost

`GET /loop` reads what the queue and the experiments list already read
(their answers are cached), and one grouped read of the search data by
query and page for the period, by its primary key, cached until the next
search import. Nothing is stored and no visitor page is touched.
