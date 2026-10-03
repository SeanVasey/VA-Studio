# GitHub CI compatibility preparation

> Historical import checkpoint. The active GitHub handoff and exact compatibility commit mapping are in [github-handoff-20261002.md](github-handoff-20261002.md). Source preservation does not establish CI acceptance.

Status: October 2, 2026. **Destination identity binding and activation remain pending.** This is a bounded local preparation on reviewed source `9df417c1a33cd777609ef7eacd5e4a2e237a207e`, alongside the separately recorded [migration status](github-migration-status.md). It is not a completed migration, a hosted CI pass or merge acceptance.

The receipt allowlist still deliberately names `VASEYDEV/VASEYAUDIO`, repository ID `1357536326` and Foundation workflow ID `350477270`. Those three values must be replaced only after the actual destination repository and workflow are observed through its authenticated API. No destination identity is inferred from a user-supplied name or a workflow environment variable. The default branch remains `main`.

## Prepared runtime compatibility

Every PHP setup in Foundation and focused feedback now requests the full required extensions, including PCNTL/POSIX and XML dependencies, and configures a `512M` memory limit. A read-only PHP preflight runs before Composer or application tests and verifies 64-bit PHP 8.4, the bound, required extensions, `posix_setrlimit`, `pcntl_signal` and `SIGXFSZ`. It does not load Laravel or install or alter host packages. The database start and finish producers also invoke that actual preflight.

GitHub receipts retain their extension-version map and add the observed PHP memory limit. Both engine validators require the complete extension set and exact bound. The MySQL probe now includes `performance_schema`; validation requires genuine MySQL 8.4, strict SQL mode, `utf8mb4` with a corresponding collation, Repeatable Read, InnoDB, case-sensitive table names, InnoDB strict mode and enabled performance instrumentation. Canonical integer or string PDO values remain supported for the existing two numeric settings; `performance_schema` must be integer `1`. GitHub's actual Docker container image digest remains required.

Before probing a database, the producer requires `APP_ENV=testing`, the selected driver and no URL/socket override. MySQL must use the existing loopback service on port 3306 and synthetic `vaseyaudio_test` database; SQLite must be in memory. No production database or application configuration is changed.

The Foundation quality job also carries the existing bounded rights-writer formatting command, shared/native receipt safeguards, writer-feedback safeguards and Mac bootstrap checks. GitLab's root-container installation/storage commands remain provider-specific; none are executed on the GitHub host. The new read-only PHP helper has dedicated capability and workflow-wiring safeguards.

## Preserved acceptance boundaries

- All thirteen applicable full Foundation jobs, four MySQL shards, two SQLite shards, both ordinary browser engines and genuine related-browser checks remain configured. Job names, matrices, timeouts, triggers, conservative documentation routing and aggregate requirements are unchanged.
- Complete source discovery, exact partitions, zero MySQL skips and exact reviewed SQLite skip equality are unchanged. The existing skip policy SHA-256 remains `ede57e3d0d605563f067f756b1f84cb991d2a20b536db360e7aea41bcef2737b`.
- Source commit/tree and ordered PR parent checks, workflow identity, current run/attempt, dependency identity, pre/post runtime equality, artifact digests and bounded archive parsing remain required. No old receipts are reused, and the collection still cannot certify its own eventual outer acceptance.
- Focused feedback remains informative with read-only permissions. Its safeguard formerly rejected the substring `write` inside the legitimate extension name `xmlwriter`. It now rejects standalone `write` and `write-all` tokens; explicit adverse permission mutations prove those refusals remain effective.
- The reviewed browser-fixture and disposable-database teardown repairs are unchanged. No application, migration or PHPUnit source census is modified by this preparation.

## Executed targeted verification

The following local checks passed with the existing PHP 8.4.26 runtime. No complete application suite, browser suite, hosted workflow or native CI restart was run.

| Command | Result |
| --- | --- |
| `python3 scripts/ci/test-database-receipts.py` | 28 passed |
| `python3 scripts/ci/test-gitlab-database-receipts.py` | 23 passed |
| `python3 scripts/ci/test-ci-scope.py` | 24 passed |
| `python3 scripts/ci/test-focused-tests.py` | 27 passed |
| `python3 scripts/ci/test-phpunit-shards.py` | 35 passed |
| `python3 scripts/ci/test-php-test-runtime.py` | 5 passed |
| `python3 scripts/dev/test-bootstrap-macos.py` | 13 passed |
| `python3 scripts/ci/test-gitlab-writer-feedback.py` | 7 passed |
| PHP syntax and Pint for the new preflight | Passed |
| `git diff --check` | Passed |

The real PHP negative cases disable `pcntl_signal` or `posix_setrlimit`, remove configured extensions, and change the memory limit; all fail before application startup. Receipt adverse cases alter both retained runtime records and recompute their internal and archive hashes. The actual collector still rejects missing required extensions, invalid memory/integer-size evidence, weakened MySQL settings and missing/invalid Docker identity. Synthetic targets outside the permitted testing database are rejected before any subprocess probe.

The initial focused workflow check failed solely because its broad substring rule matched `xmlwriter`; the final 27-case run includes the corrected guard and explicit permission-refusal cases. Synthetic receipt tests prove validation behavior, not an actual GitHub runner or MySQL service. Actual destination metadata, required-check settings, final-source independent review and fresh full hosted acceptance remain necessary before promotion.
