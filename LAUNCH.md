# SEO Pro Stats — launch state

Public since 9 October 2026, at version 1.0.0 (the first GitHub release).

Before it went public, the whole Git history was scanned for secrets
(`DEVELOPMENT.md` → Secrets in history), and issues, pull requests, commit
messages and the release were checked for private names and paths. The
scan's only findings, four `curl-auth-user` matches in `README.md` (commits
`ff0bfd0`, `c2f2253`, `065960b` and `560c58c`), are the REST API example's
placeholder `admin:APPLICATION PASSWORD`, not a password; the example now
uses environment variables. Expect those four in later history scans.

On now:

- GitHub Actions run (CI, Release, Scorecard, Starter sync, Repository
  metrics). CI checks are required on `main` by a branch ruleset (Lint,
  Release build, both Smoke tests), without "branch must be up to date".
- CodeQL default setup (JavaScript and TypeScript, GitHub Actions; it has
  no PHP support), secret scanning with push protection, Dependabot alerts
  and security updates, private vulnerability reporting (`SECURITY.md`),
  and OpenSSF Scorecard (badge in `README.md`).
- Socket and CodeRabbit review pull requests.
- SonarCloud (`wpallstars_seoprostats`, Automatic Analysis off, the scan
  runs from `.github/workflows/sonarcloud.yml` with the repository's own
  `SONAR_TOKEN`; the organization's older `SONAR_TOKEN` is rejected), Codacy
  and CodeFactor, with their badges in `README.md`.

Not yet (owner's accounts, `DEVELOPMENT.md` → Services setup):

- `SYNC_PAT` is not set, so Repository metrics cannot commit to the
  protected `main`; regenerate `docs/metrics/` locally until it is.
- The rest of `DEVELOPMENT.md` → At public launch: fix the reviewers'
  findings by area, then raise the PHPStan level.

Version 1.0.0 was published by hand while Actions could not run, so its
zip has no signed build provenance; releases from 1.0.1 on get it from the
Release workflow (`gh attestation verify`).

WordPress.org: planned (`readme.txt` → FAQ: versions reach WordPress.org 30
days after GitHub). Not submitted; follow `RELEASING.md` → WordPress.org
when the owner says, and record the submission here.
