# Dependency maintenance batch — October 17, 2026

One Composer package and four npm packages move, all patch or minor. The tested dependency source is
`1a3844f6be5c205115ca6908684f734d29d663e2` (branch `harness/dependency-maintenance-20261017`, parent
`efa6eea5b007f1f2623c4009c1e9bef737f06bee`, which is main after PR #132). The evidence commit that follows it changes only
this directory and `CHANGELOG.md`. Every check below ran against exactly that `composer.lock`, `package.json` and
`package-lock.json` content; nothing executable changed after the lock commit. PHP 8.4.26, Composer 2.8.12,
Node 24.22.0, npm 11.19.1 and Oracle MySQL 8.4.11 (private, port 33585). The earlier batches used Node 24.19.0 and
npm 11.9.0, so the toolchain here is newer than theirs.

This batch is meant to supersede the five Dependabot PRs named in the brief (#133, #107, #105, #104, #106). Their
mapping to packages was not checked against GitHub, which this lane did not contact. Nothing was pushed, no PR was opened or
closed, and no workflow was dispatched.

## What changes

| Ecosystem | Package | Before | After | `package.json` / `composer.json` |
| --- | --- | --- | --- | --- |
| Composer | `laravel/framework` | v13.35.0 (`a413a9da`) | v13.36.0 (`e5626e38`) | unchanged (`^13.17`) |
| npm | `vite` | 8.3.3 | 8.3.4 | `^8.3.3` to `^8.3.4` |
| npm | `@types/node` | 24.13.3 | 24.19.2 | `^24.10.0` to `^24.19.2` |
| npm | `@inertiajs/react` (with `@inertiajs/core`) | 3.8.0 | 3.9.1 | `^3.8.0` to `^3.9.1` |
| npm | `jsdom` | 30.0.1 | 30.1.2 | `30.0.1` to `30.1.2` (exact pin kept) |

**Composer changes exactly one of 158 package records.** `composer.json` and the lock's content hash are unchanged, and
there are no additions or removals ([graph delta](metadata/composer-graph-delta.json)). Version 13.36.0 raises its
`laravel/prompts` floor from `^0.3.11` to `^0.3.25`, which the locked 0.3.25 already satisfies, so nothing else had to move.
Its dev-only constraints (`orchestra/testbench-core`, `phpstan/phpstan`) also move but are not installed.
Stripe 21.3.2, the PDF 8.76.3 and font 4.4.1 records, PHPUnit 12.5.34, Filament 5.8.4 and every frozen
`resources/contracts/*` file are byte-identical to main ([receipt](metadata/composer-unchanged-pins.json),
[diff check](metadata/unchanged-pins.txt)). No file under `app/`, `tests/`, `routes/`, `config/` or `database/` changed.

**npm changes 24 package records and removes two** ([graph delta](metadata/npm-graph-delta.json)). Only three
are production-scope: Inertia core and react, and `laravel-precognition` 2.1.0 to 2.1.1, which Inertia pulls in. The rest are
dev-only: Vite, `@types/node`, and jsdom 30.1.2 with its own newer dependencies (`@asamuzakjp/css-color` 7,
`@asamuzakjp/dom-selector` 9, `data-urls` 8, `html-encoding-sniffer` 7, `tr46` 7, `w3c-xmlserializer` 6, `whatwg-url` 17).
Removed: `symbol-tree` and a nested `whatwg-url`. The lockfile format stays at version 3.
Installed versions were read from the actual `node_modules` manifests and from `composer show`: all 158 installed
Composer package versions match the lock, and the five npm targets match ([installed-npm](install/installed-npm.txt),
[installed-composer](install/installed-composer.txt)).

### Deviation from the brief: the Composer resolution

The brief named `composer update laravel/framework --with-dependencies`. That command was run first, pinned with
`--with laravel/framework:13.36.0` because 13.37.0 already exists. It rewrote **19** records, including `symfony/console`
7.4.20 to 8.1.9 (a major) and eighteen other Symfony, `league/uri` and `laravel/prompts` bumps
([list](metadata/composer-broad-update-not-applied.json), [output](install/composer-update-lock-broad-not-applied.txt)).
None of those is required by 13.36.0; `--with-dependencies` simply lets Composer take the latest of every dependency of the
named package. That contradicts the brief's own instruction to keep only the transitive changes Composer requires, so the result was
discarded and the update was re-run without `--with-dependencies`
(`composer update laravel/framework --with laravel/framework:13.36.0 --no-install`), which changes one record
([output](install/composer-update-lock-applied.txt)). If the broader move is wanted, it should be its own reviewed batch,
especially the `symfony/console` 7 to 8 major.

Laravel 13.37.0 (released 2026-10-16) and the other updates listed in `metadata/*-outdated-after.json` were
deliberately left out of scope.

## Reviewed upstream notes

[Release-note summaries](release-notes.md) for Laravel 13.36.0 (49 entries), Vite 8.3.4, Inertia 3.9.1 and jsdom 30.1.x.
They came from the GitHub release pages through a fetch tool that summarised them, so they are a read of titles and not an audit of
upstream diffs. None was reported as breaking by the summaries. The Laravel changes that matter most to this app are
unique queued-listener locks released on transaction rollback (#61974), no recursive reconnect while reconnecting
(#61995), model casts on increment and decrement (#61991), `Schema::getColumn()` case-insensitivity (#61967), the console
kernel and scheduled-callback fixes (#61931, #61973) and the validation rule builders (#61833). The selections below
were chosen to exercise those areas. Inertia 3.9.1 (same-origin-only XSRF header; a unique IV per encrypted history entry)
is not exercised by app code: a text search found no `encryptHistory` or `clearHistory` use and the app builds its own
`X-XSRF-TOKEN` headers.

## Verification results

Commands, exit statuses and file names for everything below are in [commands.md](commands.md).
Counts are parsed from the retained JUnit files, not from console summaries ([outcomes.json](outcomes.json)).

| Check | Actual result |
| --- | --- |
| `npm ci --include=dev` from the new lock | Exit 0; 121 packages added; 0 vulnerabilities |
| `composer validate --strict`, `composer check-platform-reqs` | Both exit 0 |
| `composer install` from the new lock | Exit 0 after one environmental first failure (below); updates the existing vendor tree, not a fresh 158-package install |
| `npm run typecheck` (TypeScript 7.0.2) | Exit 0 |
| Frontend suite (`npm test`) | **66 files, 1,141 tests**; 0 failures, 0 errors, 0 skips; exit 0 |
| Production build, then bundle secret scan | Build exit 0, existing greater-than-500 kB chunk warning retained (app chunk 617.66 kB); scan of 6 files for 9 secret names and Stripe key prefixes: no hits |
| Preview build, then preview bundle scan | Build exit 0; scan of 4 files: no hits |
| Preflight Node tests that boot PHP | `private-alpha` 13 pass; `persistent-content` 18 pass (real HTTP and Livewire on 13.36.0); WebKit-backport safeguards 33 pass; zero failures or skips |
| Pint `--test` on the four fixed lists | All four passed (Pint 1.32.1) |
| Ten Python safeguard scripts | All exit 0 |
| `verify-php-test-runtime.php` | Exit 1 with the local default `memory_limit=-1`; exit 0 with CI's `memory_limit=512M` (see failures) |
| `composer audit` before and after | Both exit 0: no advisories, no abandoned packages |
| `npm audit --audit-level=high` before and after | Both exit 0: zero vulnerabilities of any severity (165 dependencies before, 163 after) |
| `composer outdated --direct` before and after | Both exit 0. After: 13.37.0, Filament 5.10.2, PDF 8.81.1, font 4.6.2, Stripe 22 and PHPUnit 13 remain available |
| `npm outdated` before and after | Both exit 1 because updates remain, including Playwright 1.64.1, vitest 5.0.5, jsdom 30.1.3 and `@types/node` 26 (a major, out of scope for the Node 24 runtime) |
| PHP on SQLite, 55 files | **1,330 tests, 14,702 assertions; 0 failures, 0 errors, 23 skipped** across four sequential runs |
| PHP on native MySQL 8.4.11, 7 files, 10 cases | **9 passed, 1 error**, 662 assertions, 0 failures, 0 skips; the error is identical on the old framework (below) |

`public/build` was absent for every PHP test run (it was rebuilt once for the `persistent-content` Node test, then deleted again).

### PHP on SQLite

The 55 files are in [selection-probe](php-sqlite/selection-probe.txt) and chunks [a](php-sqlite/selection-chunk-a.txt),
[b](php-sqlite/selection-chunk-b.txt) and [c](php-sqlite/selection-chunk-c.txt) (two, 15, 21 and 17 files). They were chosen
from the 13.36.0 notes and the app's own use of the framework: queue and notification work and the unique and locking
behaviour around it, scheduler and console commands, DB transactions and row locks, validation, Inertia and session
HTTP responses, streamed downloads, Filament/Livewire staff pages with MFA, mail and the billing webhook intake. Chunk a
ran 345 tests (606 s), b 629 (867 s) and c 294 (1,083 s); the two-file probe ran 62 tests. The first
three ran sequentially after the lock commit; the probe ran on the identical uncommitted tree before it.

All 23 skipped cases (12 methods: `OrderPreparationConcurrencyTest`, `TransactionalNotificationConcurrencyTest`,
`SiteScheduleConcurrencyTest`, `ExclusiveOfferConcurrencyTest` and one `HostedCheckoutTest` method) are in the reviewed SQLite skip
census `scripts/ci/database-sqlite-skips.json`. They are native-only race tests that skip on SQLite by design, so SQLite
proves nothing about them. They are not unexplained skips.

### PHP on native MySQL

The private server started from a fresh datadir (`SELECT VERSION()` gave 8.4.11 with `innodb_flush_log_at_trx_commit=1`,
`sync_binlog=1`, doublewrite ON, binary log ON), listened only on 127.0.0.1:33585 with its own socket and `mysqlx` off,
and was shut down normally with `mysqladmin shutdown` (exit 0, "Shutdown complete", [log](php-mysql/shutdown.txt)).
Ten cases ran from seven files, listed in [selection-files](php-mysql/selection-files.txt) and
[selection-filter](php-mysql/selection-filter.txt): four files from the `include_files` list in
`scripts/ci/database-mysql-selection.json` (the billing dedup race, the billing interval-ownership race, the member-grant
activation integration and the membership reservation ownership file) and three files whose native-only methods
skip on SQLite (`TransactionalNotificationConcurrencyTest`, `SiteScheduleConcurrencyTest`, `OrderPreparationConcurrencyTest`).
By their test names, they cover concurrent identity dedup, unique claim-once dispatch, scheduler overlap,
owner-mutex waits, and transaction commit and rollback.

**Nine cases passed with 653 assertions between them; one errored after 9 more (662 in total).** The error is
`MemberGrantActivationIntegrationTest::test_independent_confirmations_of_the_same_intent_return_the_first_retained_effect`,
which throws `MembershipException: Production membership preparation unavailable.` after 9 assertions.

It is **not attributable to this batch.** I re-ran just that case on a quieter machine after a container reboot (same
lock, [run 2](php-mysql/run2-rerun-failing-case.txt)) and it failed again. I then built a throwaway copy of the worktree whose
`composer.lock` is byte-identical to main's (checked with `cmp`) and whose `vendor/laravel/framework` is 13.35.0
(`Application::VERSION` read as `13.35.0`). The same case on the same server and database failed identically there
([baseline](php-mysql/baseline-13.35.0-failing-case.txt)): same exception, same throw sites
(`MembershipReservationOperations.php` lines 172, 63 and 38), same 9 assertions, same fixture line. The copy was deleted afterwards.

The cause was **not isolated.** What the evidence supports: `MembershipReservationOperations` runs under one hard
60-second wall-clock deadline (`hrtime`, covering callbacks, waits, commit and receipt), and the first run's stack ends in
the `operation_deadline` check (`MembershipReservationContext.php:786`). In this container every native case took 3 to
9 minutes of wall time while the re-run used only about 13 seconds of user CPU, so the process was waiting on the database. The re-runs
throw from a catch-all that rewrites any inner error as `committed_receipt_refused` and drops the original, so the inner
cause was not captured. That is consistent with the deadline expiring on a slow native database, but I did not
prove it, and I did not change or run any application code to find out. Treat this case as unproven on MySQL here, not as
a pass and not as a regression from 13.36.0. It needs a run on the hosted MySQL runner or a faster private server.

## Failures and deviations retained

The original logs are kept; no test, assertion, audit setting, guard or frozen resource was edited.

- **Broad Composer resolution** (above): 19 records, discarded for one.
- **First `composer install` exited 1** ([log](install/composer-install-attempt1-gitdir-missing.txt)): Composer cannot
  update `vendor/laravel/framework` in place because its `.git` directory had been deleted earlier to save disk. The lock was not at fault.
  Only that gitignored directory was removed, Composer reinstalled it at the locked reference and exited 0, and the
  clone's `.git` was deleted again.
- **`verify-php-test-runtime.php` exited 1** ([log](preflight/php-runtime-first-default-memory.txt)): this machine's PHP defaults
  to `memory_limit=-1` and the script requires CI's 512M. It passed with `-d memory_limit=512M`.
- **First native MySQL attempt: 80 errors, 0 assertions** ([log](php-mysql/attempt1-admission-refused.txt)): the repository's
  `DisposableTestDatabase` refused admission because the declaration that the database is disposable was missing. The
  repository's own `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_catalog_import` was then set. That is accurate, as the server and database
  exist only for this lane, and the guard itself is unchanged.
- **Second native attempt (80 cases) stopped by me after about 43 minutes** ([note](php-mysql/attempt2-note.txt)):
  roughly one schema build every three minutes while the host was running about 7 lanes on 4 cores (load average 9 to 10). It
  wrote no JUnit and no result is claimed from it. The selection was cut to the ten cases above. Two data-provider cases
  (`ExclusiveOfferConcurrencyTest#0` and `ProductionCheckoutNativeRaceTest#0`) were dropped when their filter did not match.
- **Account pause and container reboot.** After the ten-case run had finished (its results were already written), the account
  limit paused this lane and the container rebooted, killing the private `mysqld` without a shutdown. No test run was in progress, so
  nothing is recorded as interrupted. Stale `mysqld.pid`, `mysqld.sock` and `mysqld.sock.lock` were removed, the server
  crash-recovered ([log](php-mysql/restart-after-reboot.txt)) and was used for the re-run and baseline above.
- **Test-time budget exceeded.** SQLite used about 43 minutes of PHPUnit time in the three chunks (plus the short probe, whose timing was not retained), the ten-case native run 58 minutes, the re-run and baseline 12 minutes
  and the abandoned attempt about 43 minutes, well past the 60 to 90 minute target, almost all of it native-database waiting
  under CPU contention. A planned fourth SQLite group (migration, cast and console tests) was not run.

## Untested conditions

- **Not the full suite.** 55 of 677 test files on SQLite and 10 cases in 7 files on MySQL, out of the 1,816 cases in the
  MySQL native selection. Only 5 of the 12 SQLite-skipped native methods ran natively here; the other 7 (one
  `ExclusiveOfferConcurrencyTest`, one `HostedCheckoutTest`, two `TransactionalNotificationConcurrencyTest`, two
  `SiteScheduleConcurrencyTest` and one `OrderPreparationConcurrencyTest` method) did not run on any engine.
- **One native case errors** and is unproven on MySQL, as above.
- **No browser, WebKit or Playwright run, no hosted CI, Foundation or GitLab run.** Inertia client behaviour is covered only
  by jsdom tests: asset-version revalidation, history restore and logout-then-Back are not exercised.
- **No fresh 158-package Composer install**, and no `persistent-content-upgrade` or `persistent-catalog` run (they are
  `workflow_dispatch`-only steps in the preflight workflow).
- **The Vite 8.3.4 and jsdom 30.1.x changes** were exercised by the build, the bundle scans and the 1,141 jsdom-based tests, not by a
  browser or the dev server.
- **Upstream notes are summaries**, not diffs, and no upstream source was audited.
- Node 24.22.0 here against CI's `node-version: '24'`; both are on the Node 24 line the engines field requires.

## Rollback

Restore `composer.lock`, `package.json` and `package-lock.json` from `efa6eea5` and reinstall. No migration, configuration or
application code changes, so there is nothing to unwind in the database.

## Merge gates

Per `AGENTS.md`, the cheap PR preflight on the exact head and a source-bound review of this packet are still needed. In particular the
reviewer should decide whether the one native error, which is identical on the old and new framework, blocks the merge, and
whether the broader move the brief's literal command produces (the `symfony/console` 7 to 8 major and the Symfony 8.1 minors) should become its own batch.
