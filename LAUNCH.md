# SEO Pro Stats — launch state

In development, version 0.1.0, with no release yet. The repository is
private; making it public needs the owner's say.

While it is private (`DEVELOPMENT.md` → While private):

- GitHub Actions do not start on this repository (account billing), so the
  checks run locally before each merge (`AGENTS.md` → Rules for any
  change). Qlty is out of minutes; Socket and CodeRabbit run.
- No branch protection, rulesets, CodeQL, secret scanning or Scorecard:
  they need a public repository or a paid GitHub plan.
- Codacy, CodeFactor and SonarCloud are not connected; they are set up at
  public launch.
- Repository metrics (`docs/metrics/`) are not refreshed by Actions;
  regenerate them locally when they matter. `SYNC_PAT` is needed only once
  `main` is protected (`DEVELOPMENT.md` → Services setup, step 4).

At public launch: scan the whole Git history first (`DEVELOPMENT.md` →
Secrets in history), then run `DEVELOPMENT.md` → At public launch, and
update this file with what is on.

WordPress.org: planned (`readme.txt` → FAQ: versions reach WordPress.org 30
days after GitHub). Not submitted; follow `RELEASING.md` → WordPress.org
when the owner says, and record the submission here.
