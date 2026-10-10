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

## C. Chromium inquiry retry timeout — fixed here (test-only); passed on Chromium in focused CI

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
spec are on responses the page reads with `response.json()` or on direct `page.request` calls. Typecheck passes. The
spec passed on Chromium in focused browser run `37951869517` at `5a8f6e2` (Results); one pass is not proof the
intermittent stall cannot recur, and WebKit and the full Foundation browser jobs have not run it yet.

## D. WebKit preview playback and offline reload — diagnosed and fixed in the second run (below)

`public-install.spec.ts` and `test-checkout.spec.ts` poll `window.__nativePreviews[0].currentTime > 0` after Play and it
stays 0 for 10 s; other WebKit specs that play previews passed in the same run (`player-controls`, `storefront`).
`storefront-offline.spec.ts` fails on `page.reload()` after `context.setOffline(true)` with "WebKit encountered an
internal error". No WebKit is available here.

## E. MySQL matrix cannot finish within its budget — Sean chose option 1 (native selection, #82); budget revised in the second run (below)

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
SQLite skip to have executed on MySQL. A self-test accepts a reviewed skip with a setUp assertion and refuses a case with
two skip nodes; removing either rule fails it. The real verifier rejects the local JUnit before the change and accepts both
shards after it (`evidence/sqlite-receipt-junit-check.txt`). Local evidence only: no Foundation run has produced accepted
SQLite receipts on this tree yet. MySQL still allows no skips at all; a reviewed MySQL skip census is separate, unmerged work.

## Second run: Foundation `37967128232` on `f57e725` (after #70, #82 and #83)

Dispatched manually on main `f57e7256891d0d3c117e151a36f7fb967c724ab7`, the first run with the memory fix (A), the browser
fixes (B, C), the receipt rule (F) and the narrowed MySQL selection (E, #82). Development evidence only; the run failed.

| Job | Result | Notes |
| --- | --- | --- |
| scope, backend-quality, frontend, related-browser | success | — |
| operator-browser chromium-desktop | success | B and C confirmed in Foundation |
| operator-browser webkit-mobile | 64 passed, 3 failed, 2 skipped | B confirmed on WebKit; the 3 failures are D (below) |
| backend-sqlite 1/2 | success, 47.5 min, 3,917 tests, peak 332.6 MB | A confirmed on CI; receipt accepted |
| backend-sqlite 2/2 | cancelled by the 60-minute job limit at 3,294 of 3,725 tests (88%), no failure or error | limit sized from stale 37-minute estimates; about 67 min projected |
| backend-mysql 2/8 | success, 185.6 min, 208 tests (166 rebuild the schema) | first accepted native-selection receipt on hosted MySQL 8.4 |
| backend-mysql 3/8 | 209 tests in 186.5 min, 8 errors | all `CustomerInquiryMigrationTest` (G1) |
| backend-mysql 1, 4–8 | cancelled at the 210-minute limit, about half done | shard 1 completed at least 148 of 257; shard 8 reached 126 of 211 including one error (G3); timing in G2 |

### D. WebKit — fixed here (test-only), awaiting a hosted run

Both causes were reproduced locally on Playwright's WebKit 2359 (`run2/webkit-*-repro.cjs`) and reviewed
(`run2/independent-review-2fd7ccb-DECISION.md`, APPROVE WITH CONDITIONS, conditions applied):

- `public-install` and `test-checkout`: the store's real service worker controlled the page, and WebKit does not send
  requests that a controlling worker passes through to `page.route`, so the synthetic preview never loaded
  (`NotSupportedError`, `currentTime` 0). Every passing audio spec already blocks workers. Both files now block service
  workers on WebKit only; Chromium keeps the real worker. Neither spec asserts worker behaviour.
- `storefront-offline`: WebKit's offline emulation fails every navigation before a controlling service worker can answer,
  with the original and the backported SDK alike. On WebKit the outage is now real: the page loads through a loopback
  forwarder to the test server (port from the project's `baseURL`) that the test unplugs and re-plugs. Every assertion is
  unchanged; Chromium still uses `context.setOffline`. The reviewer ran the forwarder against WebKit 2359 5/5 and a
  byte-integrity stress test 15/15.

### SQLite job limit — fixed here (CI)

GitHub and GitLab SQLite shards now have 100 minutes (about 45% headroom over the projected 67). Regenerating
`scripts/ci/phpunit-timings-sqlite.json` from the complete shard 1 JUnit to rebalance the two shards is a follow-up.

### G1. `CustomerInquiryMigrationTest` on MySQL — fixed here (test-only)

The test drops `customer_inquiries` to simulate partial installs. Its `setUp` rolls back the child tables first "as a
parent rollback must do on MySQL", but `inquiry_notification_intents` (migration `2026_10_07_243000`, Oct 7) was never
added; its restricting foreign key made MySQL refuse every drop (SQLSTATE 3730). SQLite does not enforce the drop, so
only the native selection could show it. `setUp` now rolls back 243000 first. On a fresh private MySQL 8.0.46 database the old test file gives 27 tests with
the same 8 errors as CI and the fixed one passes 27 tests / 302 assertions (`run2/inquiry-migration-mysql80-{red,green}.txt`);
CI's MySQL 8.4 has not run it yet.

### G3. Three more native-only test defects — found by independent review and a foreign-key scan

The review of the shard and inquiry commits found two more MySQL-only failures, and the implementer's scan of the real foreign-key graph (360 constraints, 191 tables attributed to their migrations) found a third. `ProductionFeatureMigrationTest::test_recorded_gap_and_non_prefix_installation_refuse_without_repair` errored in shard 8 of this run (SQLSTATE 1824, a table created before its foreign-key parent). The cancelled shard's progress line shows it, and the first write-up of this run missed it. `CustomerAccountMigrationTest` drops `customer_accounts` while 46 newer dependents (not only `customer_saved_tracks`, migration 242000, but service projects, grant origins, consent, suppression and the production families) still reference it (SQLSTATE 3730); it was in a cancelled shard and never reached. `SharedInventoryMigrationTest` rolls back `rights_scopes` while `free_definitions` (245000, whose rollback always refuses) still references it. All three are fixed on this branch: the two account and inventory tests now dispose of every empty dependent leaves-first from the live catalog (`CapabilityRollbackFixture::dropEmptyLeavesFirst`), and the production-feature test simulates its non-prefix gap with tables MySQL can create. MySQL 8.0.46 red/green evidence for each is under `run2/`. No other selected test rolls back a parent with an unhandled newer child.

### G2. MySQL budget — 24 shards (CI)

Hosted MySQL 8.4 took about 67–70 s per schema-rebuilding test (shard 2: 166 in 185.6 min; shard 3: 159 in 186.5 min),
about twice the 35 s the selection's estimates assumed, and the unmeasured files ran slower still (shards 1 and 8). The
selection is about 41–47 compute-hours. MySQL now runs in 24 shards on GitHub and GitLab; the timings file was regenerated
from the two complete shards; the receipt archive bound was raised to fit 24-shard archives (the old 32-member bound would
have refused a 16- or 24-shard archive). Projected busiest shard: shard 1 (`BulkReplaceLicenseDraftSourceTest` alone)
about 141–166 minutes, under GitHub's 210 and GitLab's 175 (tight once GitLab job setup is counted). Details, partition and
cost: `docs/verification/mysql-native-selection-20261009/`. Nothing has run at 24 shards yet.

## Third run: Foundation `38037183233` on `bd84978e` (after #84; #85, docs only, merged nine minutes after the dispatch)

Dispatched manually on main `bd84978e294ec31ff0b78285deb786d443a5e283` at 08:14 UTC on 2026-10-10, the first run with the
second-run fixes (D, the SQLite limit, G1–G3) and the 24-shard MySQL partition. Development evidence only; the run failed
on four of its 24 MySQL shards and passed everything else. Job table and per-shard JUnit summary: `run3/jobs.tsv`,
`run3/junit-summary.txt`.

| Job | Result | Notes |
| --- | --- | --- |
| scope, backend-quality, frontend, related-browser | success | — |
| operator-browser chromium-desktop (13.8 min), webkit-mobile (18.7 min) | success | D confirmed on hosted WebKit: both audio specs and the offline spec pass |
| backend-sqlite 1/2 | success, 80.2 min, 3,917 tests, 345 skipped | receipt accepted; the stale timings put 79 min of test time here |
| backend-sqlite 2/2 | success, 43.4 min, 3,725 tests, 278 skipped | receipt accepted; the 100-minute limit was sized right |
| backend-mysql 1/24 | success, 124.5 min, 85 tests | `BulkReplaceLicenseDraftSourceTest` alone; no partition can shorten it |
| backend-mysql 2–9, 11–14, 16–21, 24 | success, 58–109 min | 19 more accepted native-selection receipts on hosted MySQL 8.4.11 (20 with shard 1); G1 and G3 confirmed |
| backend-mysql 10/24 | 73 tests, 1 error, 67.4 min | `MembershipSchemaPreparationTest` (H1) |
| backend-mysql 15/24 | 73 tests, 1 error, 2 skipped, 102.8 min | `CustomerSuppressionMigrationTest` (H2) |
| backend-mysql 22/24 | 73 tests OK, receipt refused, 108.0 min | "Probe failed: clean tracked checkout" (H4) |
| backend-mysql 23/24 | 69 tests, 12 errors, 101.1 min | `CustomerConsentMigrationTest`, all 12 interruption cases (H3) |

All four are test-side defects that only a native engine can show; no application code changes. The SQLite-only census,
the MySQL skip census and the selection are unchanged, and every selected test executed on exactly one engine as before.

### H1. `MembershipSchemaPreparationTest` shadow-floor case — fixed here (test-only)

`test_raw_reader_refuses_shadow_floor_and_preserves_owned_rows` creates a temporary table inside `DB::transaction` and
drops it in a `finally` with plain `DROP TABLE`. On MySQL `DROP TABLE` commits implicitly even for a temporary table
(only `DROP TEMPORARY TABLE` does not), so the surrounding transaction had already ended when the closure returned and
`commit()` raised `PDOException: There is no active transaction` (`run3/mysql-shard-10-errors.txt`). SQLite has no implicit
commit, so the test passed there. The drop now uses `DROP TEMPORARY TABLE` on MySQL and the unchanged `DROP TABLE temp.…`
on SQLite; the assertions are unchanged.

### H2. `CustomerSuppressionMigrationTest` non-prefix gap — fixed here (test-only)

`test_non_prefix_or_recorded_missing_guard_is_refused_without_repair` simulated a non-prefix installation by creating the
second owned table alone. `customer_suppression_intents` keys on `customer_suppression_targets` (and
`customer_consent_events`), so MySQL refused to create it before its parent (SQLSTATE 1824,
`run3/mysql-shard-15-errors.txt`); SQLite accepts a dangling reference. Like `ProductionFeatureMigrationTest` in the second
run, the case now installs the first and third tables without the second: every created table's parent exists, the set is
still not a prefix, and admission must still refuse before its first DDL.

### H3. `CustomerConsentMigrationTest` interruption cases — fixed here (test-only)

All 12 `test_actual_migrator_restarts_every_owned_table_trigger_prefix_and_preserves_history` cases drop the three
consent tables to replay an interrupted install. `customer_suppression_intents` (migration `2026_10_07_251000`) references
`customer_consent_events` with a restricting key, so MySQL refused the drop (SQLSTATE 3730,
`run3/mysql-shard-23-errors.txt`); SQLite does not enforce it. The test now disposes of every empty dependent of the owned
tables leaves-first from the live foreign-key catalog (`CapabilityRollbackFixture::dropEmptyLeavesFirst`, the second run's
G3 pattern) and asserts that `customer_suppression_intents` is among them, so a later dependent neither re-breaks the
fixture nor passes unnoticed. The migration under test, its data-preservation assertions and the retained-snapshot check
are unchanged.

### H4. `SupportAttachmentNativeConcurrencyTest` rewrote a tracked file — fixed here (test-only)

Shard 22 passed all 73 tests, then the receipt verifier refused the shard because `git diff --quiet HEAD --` found the
checkout dirty (`run3/mysql-shard-22-receipt-refused.txt`). The native attachment race test wrote its receipt into
`docs/verification/support-attachments-20261007/native-reservation-receipt.json`, a tracked evidence file; on hosted
MySQL 8.4.11 the `mysql` field differs from the committed 8.0.46 receipt, so the file changed. The test is MySQL-only,
which is why no SQLite receipt ever saw it. The receipt is now written under the ignored `storage/framework/testing/`
tree, and the committed evidence file stays as it was: an operator refreshes it by copying the new file in, never a test
run. This is a separate defect from the "attachment-consumer blocker" of `docs/verification/mysql-native-selection-20261009/`
(`SupportAttachmentsTest` and `ServiceSupportAttachmentsTest` on the job's disposable database): those two files ran with
0 skips and passed in shards 18 and 7, whose receipts were accepted, and that blocker closes only with a passing exact-SHA
run. With H4, 24 of 24 native receipts are expected to be accepted; shards 10, 15 and 23 stopped at the test failure before
the verifier reached its clean-checkout probe, so the expectation for them rests on inspection, not a run.

### Timings regenerated from the complete run (CI)

With all 24 MySQL and both SQLite JUnit logs, `scripts/ci/phpunit-timings-mysql.json` now measures every one of the 163
selected files (124 used the fallback weight before) and `scripts/ci/phpunit-timings-sqlite.json` every one of the 557
files. The partitioner's estimate is 123 minutes for shard 1 and about 87 minutes for each of the other 23 MySQL shards
(the run's spread was 58–109), and about 61 minutes for each SQLite shard (the run's 80 and 43). The job limits stay at
210 and 100 minutes on GitHub (175 and 100 on GitLab); the workflow comments carry the measurements. Splitting
`BulkReplaceLicenseDraftSourceTest` remains a follow-up only if GitLab needs it.

## Fourth run: Foundation `38077166247` on `0f23e63f` — complete pass

Dispatched manually on main `0f23e63f62da9c4ea5b1a8651094c88cafe849bf` (the #86 merge, carrying H1–H4 and the regenerated
timings) at 18:46 UTC on 2026-10-10 with that exact `expected_sha`. **Every job passed, the aggregate gate passed, and the
run concluded `success` at 21:06 UTC.** This is the first complete Foundation pass on an exact integrated SHA.

| Job | Result | Minutes |
| --- | --- | --- |
| scope, backend-quality, frontend, related-browser | success | 0.1, 7.4, 1.3, 7.5 |
| operator-browser chromium-desktop, webkit-mobile | success (D confirmed a second time) | 12.4, 20.8 |
| backend-sqlite 1/2, 2/2 | success: 3,717 + 3,302 executed, 268 + 355 census skips, 0 errors or failures | 73.8, 60.6 |
| backend-mysql 1–24 on hosted MySQL 8.4.11 | success: 1,707 executed, 58 census skips, 0 errors or failures | 56.1–131.8 (shard 1: 123.3; shard 23: 131.8) |
| backend (aggregate) | success: "26 current-run database receipts verified" | 0.4 |

- Totals from the 26 verified receipts (`run4/receipts-summary.txt`): SQLite ran all 7,642 discovered cases in 557 files
  except exactly the 623 reviewed MySQL-only skips; MySQL ran exactly the 1,765-case native selection except exactly the
  58 reviewed SQLite-only skips; every skipped case executed on the other engine. Every receipt records a clean tracked
  checkout, so H4 holds on hosted CI.
- H1–H3 passed on MySQL 8.4 inside the selection, and the receipt verifier accepted every shard.
- The attachment-consumer blocker of `docs/verification/mysql-native-selection-20261009/` closes for Foundation:
  `SupportAttachmentsTest` and `ServiceSupportAttachmentsTest` ran their native paths with no unlisted skip in an
  exact-SHA run that passed as a whole.
- Partition: shard 23 (10 files, 102 cases) took 131.8 minutes against the partitioner's 87-minute estimate, and shard 4
  took 56.1. The shard spread is wider than the regenerated timings predicted, but all 24 stayed well inside the
  210-minute limit; refreshing the timings from this run is optional.
- Evidence: `run4/jobs.tsv`, `run4/receipts-summary.txt`, `run4/aggregate-and-shadow.txt`.

What this pass does not establish: anything about a host, live payments, production configuration or DNS (no such step
runs in Foundation); MySQL behaviour of the 394 files outside the native selection, including the 47 driver-branching
residual files that run on SQLite only (Sean's decision); GitLab (not dispatched).

## Results

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Foundation `37921309772` | `0f9b39ce` | failed as above | GitHub run |
| Local SQLite shards, CI partition, 512M | `0f9b39ce` | both fatal, same place as CI | `evidence/sqlite-shard-*-main-512M.txt` |
| Regression test red / green | `0f9b39ce` + test / + fix | 1 failure / OK | `evidence/migration-recompilation-*.txt` |
| License-draft fixture red / green (relaxed harness) | `0f9b39ce` / + fix | refused / prepared and verified | `evidence/license-draft-fixture-local.txt` |
| Focused browser feedback `37938110858` (informative) | `e2b3906` | stopped by the workflow's own 720 s command budget before WebKit; Chromium: license-draft spec produced all three recovery screenshots with no failure capture (B fixed in CI); inquiry-conversation failed again (C) | GitHub run artifact |
| Focused browser feedback `37951869517` (informative) | `5a8f6e2` | all 59 Chromium tests ran with no failure capture, including `inquiry-conversation` (42 s; its server proof records `exactRetry`, one inquiry, originals unchanged); stopped by the workflow's 720 s budget at WebKit test 60 of 118 | GitHub run artifact `focused-browser-37951869517-1` |
| Chromium stream-response reproduction | pinned Playwright 1.63 / Chromium 1243 | 16/85 stream-reader runs hung, 0/22 `response.json()` | `evidence/chromium-stream-response-repro.*` |
| Foundation `37967128232` | `f57e725` | failed: see the second-run section | GitHub run |
| `CustomerInquiryMigrationTest`, private MySQL 8.0.46, red / green | `f57e725` test / `5ffcc97` | 27 tests, 8 errors / 27 tests, 302 assertions OK | `run2/inquiry-migration-mysql80-*.txt` |
| Local SQLite shards with the fix, CI's 2-shard partition, 512M | `e2b3906` PHP tree | both pass: 3,924 cases (388 skipped) and 3,710 (235 skipped), 0 failures or errors, 7,634 in total | `evidence/sqlite-shard-*-fixed-512M.txt` |
| Receipt verifier `junit()` on that JUnit | `dcf983e` / this branch | rejected ("Skipped case has assertions") / both accepted, skips exactly the reviewed census | `evidence/sqlite-receipt-junit-check.txt` |
| Foundation `38037183233` | `bd84978e` | failed on 4 of 24 MySQL shards, everything else passed: see the third-run section | GitHub run; `run3/jobs.tsv`, `run3/junit-summary.txt`, `run3/mysql-shard-*` |
| Foundation `38077166247` | `0f23e63f` | **success**: every job and the aggregate gate passed; 26 receipts verified | GitHub run; `run4/` |
| `MembershipSchemaPreparationTest` shadow-floor case, private MySQL 8.0.46, red | `590ed36` test | 1 test, 1 error: "There is no active transaction" at line 186, as in CI | `run3/membership-schema-mysql80-red.txt` |
| `CustomerSuppressionMigrationTest` non-prefix case, private MySQL 8.0.46, red | `590ed36` test | 1 test, 1 error: SQLSTATE 1824 at line 128, as in CI | `run3/customer-suppression-mysql80-red.txt` |
| `MembershipSchemaPreparationTest`, whole file, private MySQL 8.0.46, green | `914d757` | 13 tests, 40 assertions OK | `run3/membership-schema-mysql80-green.txt` |
| `CustomerSuppressionMigrationTest`, whole file, private MySQL 8.0.46, green | `914d757` | 29 tests, 122 assertions OK, 2 reviewed SQLite-only skips | `run3/customer-suppression-mysql80-green.txt` |
| Independent review of `5e15e78` | `5e15e78` | APPROVE WITH CONDITIONS; the reviewer re-ran the four touched files on SQLite (63/363/4) and the four cases on MySQL 8.0.46, regenerated both timing files byte-identically and reproduced the partition; the five conditions (all documentation and comment corrections) are applied in the commit after it | `run3/independent-review-5e15e78-DECISION.md` |
| `CustomerConsentMigrationTest` interruption cases, private MySQL 8.0.46, red / green | `590ed36` test (steps 1 and 12) / `914d757` (all 12) | 2 tests, 2 errors, SQLSTATE 3730 as in CI / 12 tests, 168 assertions OK | `run3/customer-consent-mysql80-{red,green}.txt` |
| `SupportAttachmentNativeConcurrencyTest`, private MySQL 8.0.46, red / green | `590ed36` test / `914d757` | 1 test OK and the tracked receipt rewritten (byte-identical in that one run, 8.0.46 to 8.0.46 with the same worker order; the reviewer's 8.0.46 run wrote the workers in the other order, and the 8.4.11 run differed in the version field) / 1 test, 19 assertions OK, tracked evidence directory unchanged, receipt under `storage/framework/testing/` | `run3/support-attachments-mysql80-{red,green}.txt` |
| The four touched files on SQLite in-memory | `914d757` | 63 tests, 363 assertions, 4 census skips, no change in behaviour | `run3/touched-files-sqlite.txt` |
| Partition balance on the regenerated timings | `4934987` | MySQL: shard 1 at 123 min, shards 2–24 at about 87; SQLite: both shards at about 61; no file uses the fallback weight | `run3/partition-balance-after-timings.txt` |
| scripts/ci self-tests (`test-phpunit-shards`, `test-database-receipts`, `test-gitlab-database-receipts`, `test-workflow-cadence`, `test-ci-scope`, `test-gitlab-setup`) | `4934987` | 52, 61 and the rest OK | local |

## Not tested

A host, live payments, production configuration and DNS; the 394 files outside the MySQL native selection on MySQL
(they run on SQLite); GitLab. The third-run fixes were confirmed by the fourth run, and the second-run fixes by the third.
