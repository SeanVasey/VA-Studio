# Foundation CI on the integrated handoff candidate: failures, diagnosis and fixes

Development evidence from the Claude Code harness, 2026-10-09. Not Foundation acceptance.

## The run

Foundation CI run `37921309772`, dispatched manually on `main` with `expected_sha`
`0f9b39ce5d59e2d579d11a8ca15153ced1f565b4` (after #67, #68 and the ledger PR #69). It is the first full matrix since the
handoff; no earlier Foundation baseline exists for this tree.

| Job | Result | Cause |
| --- | --- | --- |
| scope, frontend, backend-quality, related-browser | success | — |
| backend-sqlite 1/2 and 2/2 | failure | PHP `memory_limit` 512M exhausted ~1,060 and ~1,107 tests in (A) |
| operator-browser chromium-desktop | failure (66 passed, 2 failed, 1 skipped) | license-draft fixture refused (B); inquiry retry test timed out (C) |
| operator-browser webkit-mobile | failure (63 passed, 4 failed, 2 skipped) | license-draft fixture refused (B); two preview `currentTime` polls stay 0; offline `page.reload` WebKit internal error (D) |
| backend-mysql 1/8 … 8/8 | cancelled at the 90-minute job limit, each about a quarter through | per-test full migrations on MySQL (E) |
| backend (aggregate) | failure | missing evidence from the jobs above |

## A. SQLite shards: memory leak from recompiled migration files — fixed here

- Reproduced locally with the CI-identical partition (`scripts/ci/phpunit-shards.py --shards=2`, same 3,882/3,751
  split) and `memory_limit=512M`: shard 1 dies at exactly CI's test, shard 2 in the same file
  (`evidence/sqlite-shard-{1,2}-main-512M.txt`).
- A per-test trace (`evidence/sqlite-shard-1-memory-trace-main.tsv`) shows steady growth of about 1 MB and about 18
  newly declared classes per feature test. The new classes are anonymous classes from three migrations
  (`2026_10_06_000040_customer_accounts`, `2026_10_07_250000_customer_consent`, `2026_10_07_242000_customer_saved_tracks`).
- Cause: `ConsentMigrationAdmission`, `SuppressionSchema` and `ProductionFeatureSchema` read definitions from those
  approved migrations with a plain `require`, on every migration run. Each `require` recompiles the file and declares
  its anonymous classes again; PHP never frees declared classes. Test suites that refresh an in-memory database for every
  test therefore grow without bound. The same checks also run at runtime: `ProductionFeatureContext::schema()` calls
  `ProductionFeatureSchema::assertHeld()` for the consent, withdrawal, listening-library and production-suppression
  services, so a long-lived queue worker leaked on each such call; under PHP-FPM the growth lasted one request
  (independent review condition 2). The fix covers that path too.
- Fix: `App\Support\MigrationDefinitions::load()` compiles each such file at most once per process and returns a clone,
  the same rule Laravel's migrator applies to anonymous migrations. The four call sites use it; behaviour is otherwise
  unchanged (the objects are only read through reflection).
- Regression test `tests/Feature/MigrationRecompilationTest.php`: red on main (36 new classes over two extra
  `migrate:fresh` runs, `evidence/migration-recompilation-red.txt`), green with the fix
  (`evidence/migration-recompilation-green.txt`). With the fix, `CatalogWriterAuthorityTest` declares no new classes
  after warm-up (previously 18 per test).
- Full SQLite shards with the fix pass locally under 512M with CI's 2-shard partition: 3,924 and 3,710 cases, 0 failures or
  errors (`evidence/sqlite-shard-*-fixed-512M.txt`). Their receipts still failed until F below.

## B. License-draft browser fixture — fixed here (test-only)

`tests/browser/prepare-license-draft.php` refuses any change to a pre-existing row while it creates a synthetic license
draft. Since migration 240 (`7ecaa7e`, Oct 7) every license write advances `catalog_discovery_epoch.epoch` through its
own triggers, so the fixture refused itself on both browsers ("guard-or-evidence"). The offer-draft and bulk-license
fixtures already allow the epoch to advance; this one was missed. It now does the same: the epoch may only move forward
(exactly unchanged for a no-op verify phase) and that row is excluded from the exact comparison.

Local proof (`evidence/license-draft-fixture-local.txt`): the browser harness's own environment and `bootstrap.php`,
without Playwright (no matching Chromium, no WebKit, and ClamAV signatures cannot be downloaded here, so bootstrap stops
at `installation_diagnostics` after migrations, operator and catalog fixtures). Scratch copies of the fixture with only
the harness identity checks skipped: unfixed → refused at the row check (the only changed row is the epoch); fixed →
`prepare` succeeds and `verify prepared` reports 0 updates with originals and guards unchanged. The real browser spec
still needs CI.

## C. Chromium inquiry retry timeout — patched here (test-only), awaiting CI

`inquiry-conversation.spec.ts` ran 120.8 s against its 120 s budget. The report's step timeline shows the retried POST
answered at 36.4 s with 200 and the test then waiting in `await linkedReplay.finished()` (line 164) until the timeout.
It failed the same way in the focused browser run `37938110858` on this branch; it passed on WebKit (30.8 s).

The order-linked form reads its response through the bounded stream reader `privateInquiryJson`. A standalone
reproduction with the pinned Playwright 1.63 and its Chromium 1243 (`evidence/chromium-stream-response-repro.{mjs,txt}`)
shows that when a page consumes a response through a stream reader, Playwright intermittently never observes it finish
(`finished()` unresolved) and cannot read its body ("No data found for resource"): 16 of 85 runs, with `route.continue()`
and with `route.fetch()` + `route.fulfill()` alike, against 0 of 22 when the page uses `response.json()`. The page itself
always received the JSON. This is a tooling limitation, not an application defect.

The spec now keeps the status and `cache-control` checks (available as soon as the response arrives) and takes the
replayed receipt from the page, which renders it only after validating exactly `{ state: 'saved', receipt }` with status
200/201 and a JSON content type; the existing server proof (`conversation-order-verify`: one inquiry, one context,
originals unchanged) and the identical-body/same-receipt checks are unchanged. The other Playwright body reads in the
spec are on responses the page reads with `response.json()` or on direct `page.request` calls. Typecheck passes; the
spec itself still needs CI (the harness bootstrap needs ClamAV signatures, which cannot be downloaded here).

## D. WebKit preview playback and offline reload — open

`public-install.spec.ts` and `test-checkout.spec.ts` poll `window.__nativePreviews[0].currentTime > 0` after Play and it
stays 0 for 10 s; other WebKit specs that play previews passed in the same run (`player-controls`, `storefront`).
`storefront-offline.spec.ts` fails on `page.reload()` after `context.setOffline(true)` with "WebKit encountered an
internal error". No WebKit is available here.

## E. MySQL matrix cannot finish within its budget — open, needs Sean's decision

Shard 1 ran its first 126 (unit) tests in 7 s, then 63 tests in 36 minutes and 63 more in 39 minutes before the limit.
307 test files (at least 2,036 test methods before data-provider expansion) use `FinalizationDatabaseMigrations`, which
runs a full `migrate:fresh` before each test and `db:wipe` after it. On a private local MySQL 8.0.46 (CI uses 8.4.11) one
`migrate:fresh` took 130–208 s wall-clock on this shared container, 63 s of it reported by the migrations themselves
(`free_grant_origins` 13 s, `service_projects` 5 s, …; `evidence/mysql80-migrate-fresh-timing.txt`); CI measured about
35 s per test. At that rate the MySQL matrix needs on the order of 20 compute-hours, far beyond 8 × 90 minutes. The
timings file also lacks 322 test files, so the partition's 61-minute estimate was wrong. This is not caused by the PRs
merged today; migrations and per-test-migrating suites have grown across the batch since the timings were last measured.

## F. Receipt verifier refused reviewed skips that report setUp assertions — fixed here (CI scripts)

Found while checking the fixed shards against Foundation's receipt verifier (`scripts/ci/database-receipts.py`). It required
every skipped case to report 0 assertions, but PHPUnit 12.5 counts assertions made in `setUp` before a test skips itself
(for example `ProductionIdentityFixture::identitySetup()` asserting `migrate:fresh` succeeded). Eight reviewed SQLite skips
report 1–2 such assertions (`evidence/sqlite-shard-*-fixed-512M.txt`), so both SQLite receipts would have been rejected even
after A. No Foundation run reached that step on this tree (the shards ran out of memory first).

The verifier now accepts setUp assertions on a skipped case and still refuses duplicate skip nodes. Which cases may skip is
unchanged: the skipped identities must equal the reviewed SQLite skip census exactly, and the collector still requires every
SQLite skip to have executed on MySQL. A self-test covers both. The real verifier rejects the local JUnit before the change and
accepts both shards after it (`evidence/sqlite-receipt-junit-check.txt`). The same applies to MySQL once a reviewed MySQL skip
census exists (the native-selection change).

## Results

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Foundation `37921309772` | `0f9b39ce` | failed as above | GitHub run |
| Local SQLite shards, CI partition, 512M | `0f9b39ce` | both fatal, same place as CI | `evidence/sqlite-shard-*-main-512M.txt` |
| Regression test red / green | `0f9b39ce` + test / + fix | 1 failure / OK | `evidence/migration-recompilation-*.txt` |
| License-draft fixture red / green (relaxed harness) | `0f9b39ce` / + fix | refused / prepared and verified | `evidence/license-draft-fixture-local.txt` |
| Focused browser feedback `37938110858` (informative) | `e2b3906` | stopped by the workflow's own 720 s command budget before WebKit; Chromium: license-draft spec produced all three recovery screenshots with no failure capture (B fixed in CI); inquiry-conversation failed again (C) | GitHub run artifact |
| Chromium stream-response reproduction | pinned Playwright 1.63 / Chromium 1243 | 16/85 stream-reader runs hung, 0/22 `response.json()` | `evidence/chromium-stream-response-repro.*` |
| Local SQLite shards with the fix, CI's 2-shard partition, 512M | `e2b3906` PHP tree | both pass: 3,924 cases (388 skipped) and 3,710 (235 skipped), 0 failures or errors, 7,634 in total | `evidence/sqlite-shard-*-fixed-512M.txt` |
| Receipt verifier `junit()` on that JUnit | `dcf983e` / this branch | rejected ("Skipped case has assertions") / both accepted, skips exactly the reviewed census | `evidence/sqlite-receipt-junit-check.txt` |

## Not tested

Browser specs (CI only), MySQL matrix, a host.
