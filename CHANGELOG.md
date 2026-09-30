# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). There are no releases yet, so changes collect under Unreleased until the first version is tagged.

Work merged before this file existed is recorded PR by PR in [docs/development-order.md](docs/development-order.md).

## [Unreleased]

### Added

- Scheduled site publication (D-24): staff schedule one saved site release for a whole UTC minute, cancel it, and review the retained schedule history. A minute scheduler command publishes it through the same locked, audited path as Publish release, after rechecking the scheduling administrator's access and required MFA. Staff publication supersedes a pending schedule, and a schedule expires unpublished 60 minutes after its time. Production needs a `schedule:run` cron entry; `vasey:doctor` warns when a schedule is overdue.
- Vasey Multimedia Engineering Standard v3.1.0: `CLAUDE.md` with project notes, `LICENSE`, `CHANGELOG.md`, `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1) and `.editorconfig`.
- CI fails when the built client bundle contains a server secret name from `.env.example` or a Stripe secret, restricted or webhook key prefix.

### Changed

- Site Releases keeps **Preview** and, except on the active release, **Publish release** on each row and moves **Edit as new draft**, **Restore previous release** and **Schedule publication** into a **More** menu named for its release. Long labels wrap, even without spaces, the **Schedule** badge shows only the UTC time under its heading, and **Created at** shows minutes rather than seconds, so the table fits a 1440 px window without scrolling sideways. Narrower windows scroll the table rather than split words. Admin menu items now show a visible keyboard focus outline, and dismissing a dialog opened from a menu, or pressing Escape in the menu, returns focus to the menu's button.
- `SECURITY.md` names `support@vasey.audio` as the reporting channel and records supported versions and audit exceptions.
- `.gitignore` covers every `.env*` file except `.env.example`, and `CLAUDE.local.md`.
- CI balances the MySQL and SQLite test shards by measured duration instead of test count. MySQL shard 4 used to run 39 minutes while shards 1 to 3 ran 18 to 19 (CI run 36674655225), and that shard alone set the run's length. The per-file timings in `scripts/ci/phpunit-timings-mysql.json` and `phpunit-timings-sqlite.json` come from two full CI runs. Refresh them with `scripts/ci/phpunit-timings.py` from the shard JUnit artifacts when tests are added or the database speed changes; a test file without an entry still runs, at the suite's mean cost per case, and CI warns about it. The partition proof still requires every test to run in exactly one shard, so MySQL still runs every race test. `--fail-on-phpunit-warning` and every other gate are unchanged.
- The MySQL 8.4 CI service keeps its data directory on tmpfs. Test classes that rebuild the schema around each test made up 80 to 94 per cent of every MySQL shard's test time, and each rebuild is about 440 DDL statements and thousands of fsyncs on a default MySQL. Only the storage changes: the server version, its settings and `performance_schema`, which the lock-wait race tests read, are as before. Each MySQL job also prints the data directory's filesystem and usage after its tests, so a run shows whether the tmpfs applied and how full it got.

### Fixed

- Public storefront and editorial pages answer a generic, uncacheable 503 when the published site content fails its integrity check. They used to redirect to the site root in a loop, or back to the referring site. Missing content rows fail the same way instead of answering 404. The outage is logged with its reason (at most once a minute while the cache works), and New content draft reports it with the recovery that applies instead of breaking.

[Unreleased]: https://github.com/VASEYDEV/VASEYAUDIO/commits/main
