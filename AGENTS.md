# SEO Pro Stats — agent guide

The wpallstars starter plugin: what every wpallstars plugin is made from.
It has no features of its own, only the parts every plugin needs.

**Read `STANDARDS.md` before any change.** It holds the rules every plugin
made from the starter shares: structure and core files, code rules,
performance, Updates from GitHub, releases, front-end styling and dark mode, and testing. This
repository holds the master copy of it and of every core file
(`scripts/core-files.txt`). This file holds only what is the starter's own.

| Placeholder in `STANDARDS.md` | SEO Pro Stats |
|---|---|
| `{slug}` | `seoprostats` (main file `seoprostats.php`) |
| `{prefix}` | `seoprostats` |
| `{Prefix}` | `SEOProStats` |
| `{PREFIX}` | `SEOPROSTATS` |
| `{Name}` | SEO Pro Stats |
| `{css}` | `wps` |

User docs: `README.md` (developers, and the Read Me tab) and `readme.txt`.
Development: `DEVELOPMENT.md`. Releases: `RELEASING.md`; launch state:
`LAUNCH.md`. Keep this file a short map: guidance for one kind of task goes
in `docs/` (`STANDARDS.md` → Agent docs); the starter has none yet.

## What belongs here

- Only what every plugin needs. A part one plugin needs stays in that
  plugin (its `{Prefix}_Setup`, a feature or its own files). When a lesson
  from a plugin applies to all of them, change the core file here first,
  then run `scripts/sync-core.sh` in each plugin (`--check` lists what
  differs). Keep core files free of any one plugin's names, examples and
  paths: this repository is public.
- `includes/class-seoprostats-setup.php` stays empty of features, so the
  settings screen shows the empty General tab and the Read Me tab. Test a
  core change in a plugin that uses it, then here.
- `scripts/rename-plugin.sh` must keep working on a fresh copy: after a
  change to names in core files, try it on a throwaway clone (rename, lint,
  smoke test).
- The banner source is `.wordpress-org/banner.svg`: the wpallstars plugin
  stack picture in red only, on wpallstars branding. The icon source,
  `.wordpress-org/icon.svg`, is that picture alone. Rebuild both with
  `scripts/build-banner.sh`.
- This repository is public. Never name private repositories, their
  issues, or local paths in it (commits, docs, comments or examples).
- Every plugin made from the starter keeps its credits in `README.md` and
  `readme.txt`: **Built with AI** to aidevops (<https://aidevops.sh>), which
  is also where users take questions, and the "Made from" line crediting the
  starter (`STANDARDS.md` → Structure). `scripts/rename-plugin.sh` writes
  that line from split strings; keep them split.

## Test sites

Install the GitHub build on a local test site with
`scripts/preview-site.sh` (see `DEVELOPMENT.md`), or install the GitHub
zip from `scripts/build-release.sh` on any test site.

## Search targets

Search keywords, AI-answer questions and search entities live in `context/keywords.md` and `context/keywords/` (standard: `~/.aidevops/agents/seo/keywords-standard.md`). Read them, or run `aidevops keywords brief`, before naming, copy, metadata, schema, media, social or PR work. If the files are missing in a clone, run `aidevops keywords sync`.
