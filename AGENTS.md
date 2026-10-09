# SEO Pro Stats — agent guide

Private, fast site statistics for WordPress that explain cause and effect:
traffic, search rankings and conversions on one timeline with the changes
that moved them, readable by people in wp-admin and by AI agents through
the REST API, WP-CLI and abilities.

**Read `STANDARDS.md` before any change.** It holds the rules every plugin
made from the starter shares: structure and core files, code rules,
performance, Updates from GitHub, releases, front-end styling and dark mode,
and testing. Core files (`scripts/core-files.txt`) change in the starter
first, then `scripts/sync-core.sh` copies them here. This file holds only
what is SEO Pro Stats's own.

| Placeholder in `STANDARDS.md` | SEO Pro Stats |
|---|---|
| `{slug}` | `seoprostats` (main file `seoprostats.php`) |
| `{prefix}` | `seoprostats` |
| `{Prefix}` | `SEOProStats` |
| `{PREFIX}` | `SEOPROSTATS` |
| `{Name}` | SEO Pro Stats |
| `{css}` | `spst` |

User docs: `README.md` (developers, and the Read Me tab) and `readme.txt`.
Development: `DEVELOPMENT.md`. Releases: `RELEASING.md`; launch state:
`LAUNCH.md`. Plan: `todo/PLANS.md`.

| Doc | Read before |
|---|---|
| `docs/architecture.md` | Any change to collection, storage, reports, the REST API, WP-CLI, abilities or the dashboard app. |
| `docs/seo-loop.md` | Any change to Search → Plan, Experiments, Targets or the loop export (the SEO decision loop's design). |
| `docs/seo-loop-recipes.md` | Any change to the routes, commands or abilities an agent uses to work the loop. |

## Rules for any change

- **Private by design.** No cookies, no stored IP addresses, no cross-day
  visitor identity, no typed form values (`docs/architecture.md` →
  Collection). Outside services are opt-in and only add facts.
- **Visitor pages pay nothing.** No database query, option write or remote
  request on a visitor page; the collector loads no WordPress. Every
  report query uses an index; check with the smoke test.
- **WordPress first in wp-admin.** WordPress's components, colours, admin
  colour scheme and patterns. The top-level menu sits at position 3, where
  site statistics usually are.
- **Portable underneath.** The API contract (`docs/api/openapi.yaml`) and
  `packages/core`, `packages/tracker` and `packages/charts` stay free of
  WordPress and React so later apps reuse them.
- **Everything here is public** (`LAUNCH.md`). Never name private
  repositories, their issues, private sites, local paths, or other
  analytics products used as research in it (commits, docs, issues,
  comments or examples). Describe features in our own words.
- **Other plugins' data is read only.** The one exception (the owner's, to
  `STANDARDS.md` → Structure): Settings → Import → Remove leftover data
  (`SEOProStats_Migrate::cleanup()`) deletes another statistics plugin's
  leftovers, exactly its adapter's list, after confirmation, and only
  while that plugin is inactive.
- Keep the credits in `README.md` and `readme.txt`: **Built with AI** to
  aidevops (<https://aidevops.sh>) and the "Made from" line crediting the
  starter (`STANDARDS.md` → Structure).

## Test sites

Install the GitHub build on a local test site with
`scripts/preview-site.sh` (see `DEVELOPMENT.md`), or install the GitHub
zip from `scripts/build-release.sh` on any test site. Statistics need
traffic: open front-end pages logged out (or in a private window) to make
hits.

## Search targets

Search keywords, AI-answer questions and search entities live in `context/keywords.md` and `context/keywords/` (standard: `~/.aidevops/agents/seo/keywords-standard.md`). Read them, or run `aidevops keywords brief`, before naming, copy, metadata, schema, media, social or PR work. If the files are missing in a clone, run `aidevops keywords sync`.
