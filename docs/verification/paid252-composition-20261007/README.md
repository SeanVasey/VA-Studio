# Paid252 composition onto the integrated producer — 2026-10-07

Development evidence for the Claude Code harness. This is **not** paid-fulfillment,
registration or production acceptance. Default flags stay off; no payment, provider,
mail or deployment action was taken. No deadline, authorization lifetime, policy or
receipt budget was changed.

## Source

| Item | Value |
| --- | --- |
| Branch | `harness/paid252-composition` (local, not pushed) |
| Base | `e86381bf139b4351bba534f3fe0b26f4f8efbdd0` (reviewed checkout APPROVE on `claude/stoic-archimedes-xza96q`; rebased from `d20d4394` at the coordinator's request — the diff is only `CommandTransaction.php`, its regression, `scripts/dev/mkworktree.sh` and docs) |
| Paid source | frozen `origin/codex/paid-grant-fixture-portability-20261007` head `972a76c5e04da0f61d409d6caf9eb84281fef2a3` (executable `e27ca2dd`) |
| Path selection | `git diff --name-only 4150b858d05c837cf85801cf0ee1537a3c98464f 972a76c5` minus `docs/`: 120 paths, see `source-map.json` (path → blob SHA) |

The diff contained no producer/identity runtime paths outside the attributed fixture
snapshot, so none were taken. Four owned runtime dependencies sit outside the named
filter and were taken because the family cannot run without them; they are listed
separately in `source-map.json` (`resources/contracts/paid-v1/profile-assets.json`,
`resources/css/paid-grants.css`, `scripts/render-paid-grant.php`,
`tests/frontend/paid-grant-journey.test.tsx`).

Commits on the branch (oldest first): composition `054ab782`, conjunct probe
`5cb42829`, refused-frame fix `72fb2301`, then this documentation commit.

## Environment and exact commands

Worktree vendor is symlinked from `/home/user/VA-Studio` with its own Composer
autoload. The worktree lacked `vendor/composer/InstalledVersions.php` (Artisan
`package:discover` failed); it was copied from the main checkout and autoload was
regenerated. The PHPUnit binary resolves to the main checkout's autoload, so tests run
through a small runner that sets `$GLOBALS['_composer_autoload_path']` to the
worktree's `vendor/autoload.php` before including PHPUnit (the same mechanism
Composer's bin proxies use). PHP 8.4.26.

```sh
# SQLite (phpunit.xml defaults, normal Composer bootstrap, no VA_PAID_DEVELOPMENT_DEPENDENCY)
php phpunit-wt.php -c phpunit.xml tests/Feature/<PaidGrant…Test>.php

# Native MySQL 8.4.11
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3408 \
  DB_DATABASE=vaseyaudio_paid252 DB_USERNAME=root DB_PASSWORD=ci-only-password \
  DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
php phpunit-wt.php -c phpunit.xml --filter <case> tests/Feature/<file>.php
```

**Native server deviation.** The assigned shared daemon (`127.0.0.1:3306`) could not
run any paid native case: `FinalizationDatabaseMigrations` failed at migration
`2026_10_06_238000_production_track_preparation_packets` with
`Unexpected production capability external or additional table guard; rollback refused
before schema changes.` (`evidence/native-http-original.*`, 1 error, 35.6 s).
`CapabilityMigrationOwnership` scans `information_schema.TRIGGERS` across every schema,
and the other lanes' migrated schemas on that daemon (`vaseyaudio_features253`,
`_review`, `_p2`, `_member257`) carry triggers that reference the same table names.
Nothing on the shared daemon was changed. Native runs instead used a private,
disposable `mysqld` 8.4.11 (`--no-defaults --initialize-insecure`, loopback port 3408,
datadir in the session scratchpad, 512M buffer pool, statement history enabled and the
general log on for runs r2 onward). A sibling lane already uses the same pattern
(port 3407). A second private instance (3409) was used only for the discarded
experiment below and then removed.

Every native case runs full migrations per test; a single case takes 8–13 minutes.

## Step 2 — composition on SQLite (canonical producer)

Before any change (base `d20d4394`, composition only): **62 tests, 61 passed,
1 skipped, 0 failures, 2,579 assertions.** No API drift needed fixing; the canonical
producer classes were used (`PaidGrantDependencyFixtures` asserts that the canonical
files own the reflected classes).

| File | Tests | Assertions | Duration |
| --- | --- | --- | --- |
| PaidGrantCommitCapsuleTest | 11/11 | 241 | 51.7 s |
| PaidGrantCommittingAdmissionTest | 2/2 | 36 | 8.1 s |
| PaidGrantDocumentJourneyTest | 4/4 | 87 | 32.8 s |
| PaidGrantDownloadJourneyTest | 5/5 | 112 | 63.9 s |
| PaidGrantHttpJourneyTest | 18/18 | 534 | 75.7 s |
| PaidGrantSchemaRecoveryTest | 9/10, 1 skipped | 1,373 | 1.8 s |
| PaidGrantSourceJourneyTest | 12/12 | 196 | 71.6 s |

The skip is `test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl`
(`markTestSkipped('Native schema-global identifier collision evidence.')`), native-only
by design. JUnit: `evidence/step2-sqlite-*.xml`.

Final state (`72fb2301`, rebased, with the probe and refused-frame regression):
**65 tests, 64 passed, 1 skipped (same case), 0 failures, 3,216 assertions**
(`evidence/final-*.xml`). Frontend: `vitest run tests/frontend/paid-grant-journey.test.tsx`
9/9; `tsc --noEmit` exit 0; scoped Pint passed on changed PHP. `vite build` was not run
(a Vite manifest makes seven HTTP tests fail with 409, per project notes).

## Step 3 — native refusal diagnosis

### Retained original result

The original e8 native selection (MySQL 8.0.46, shared cloud daemon) recorded 6 cases:
4 passed, 1 failure, 1 error, 86 assertions — HTTP master redemption **409**, recovery
**410**. The staged diagnostics recorded a **403** (292.066 s) and a **409** with fixed
reason `committed_read_frame` at `OriginalCommitDispatcher::requireCurrent` (line 95 on
that source, line 96 here) via `historicalReceipt()` in the second redemption
`PaidGrantCommands::run` (`PaidGrantDownloads.php:132`), authorization-return to
refusal 40.251 s. Those remain the recorded results; nothing here replaces them
(`docs/verification/paid-grant-handoff-20261007/` on the frozen branch).

### Native results on this composition (MySQL 8.4.11, private daemon)

| Run | Selection | Result | Assertions | Wall |
| --- | --- | --- | --- | --- |
| r1 | `PaidGrantHttpJourneyTest::test_actual_typed_buyer_http_finalization_paid_pdf_and_native_file_delivery_preserve_originals` (unchanged) | 1 failure: master redemption **410** (line 121) | 31 | 662.5 s |
| probe r1 | `PaidGrantRedeemFrameConjunctTest` | 1 failure: **403** at finalize | 17 | 657.3 s |
| probe r2 | same, with trace and general log | 1 failure: redemption **410**, refused 1.962 s after the authorization expired; every per-sample conjunct assertion passed | 29,859 | 664.9 s |

Run r2 is the analyzed one (`evidence/native-trace-r2-summary.json`,
`evidence/native-r2-statement-profile.txt`).

### Isolated conjunct: the original deadline

`tests/Feature/PaidGrantRedeemFrameConjunctTest.php` with
`tests/Support/PaidGrantCommitFrameProbe.php` (test-only, read-only reflection; an
asynchronous SIGUSR1 sampler plus transaction-event listeners) evaluates each
`requireCurrent` conjunct separately for every observer found on the stack.

* **phase !== 'invalid'** — true in every guarded sample (`historicalReceipt`,
  `requireCurrent`, `register`, `rows`, `requireClosed`) natively and on SQLite.
* **connection dispatcher === observer** — true in every guarded sample.
* **hrtime < deadlineNs** — the only conjunct that the measured timeline exhausts.

Hypotheses checked:

* (a) left-over observer: refuted. No `OriginalCommitDispatcher` was on the stack at
  any of 25 native (12 SQLite) `TransactionBeginning` events, so no stale observer met a
  new frame and the second `capture` never received a foreign `self` delegate.
* (b) restore only when current: refuted. Each of the 7 observer-held commits restored
  the original dispatcher (later beginnings see none); `dispatcher_ok` never failed.
* (d) nested `TransactionBeginning` flips phase: refuted. No beginning occurred while an
  observer was installed.
* (c) time: confirmed, but not from the 300 s `CommittedReadContext` cap. The
  operative deadline is the redemption budget shortened to the authorization's own
  expiry (`PaidGrantDownloads::redeem` → `shortenTo(deadline($auth))`), which is
  `authorization_seconds` = 60 s after the authorization row was created.

r2 timeline (UTC; Δ is the connection's executed prepared statements):

| Phase | Start → end | Wall | Statements |
| --- | --- | --- | --- |
| finalize | 14:16:05 → 14:17:12 | 67.3 s | 64,701 |
| document | 14:17:13 → 14:20:43 | 210.8 s | 239,033 |
| authorize | 14:20:43 → 14:21:34 | 50.8 s | 70,205 |
| redeem | 14:21:35 → 14:22:03 | 29.8 s | 38,591 |

The authorization was created at 14:21:02 (expires 14:22:02), about 19 s into a 51 s
authorize command; it returned 32 s after creation, and the redemption then needed
another 29.8 s. The original 40.251 s (authorization return → refusal) fits the same
arithmetic on a slower shared daemon.

Which refusal appears depends only on which check is evaluated first after the
instant passes, and all of them are the same exhausted budget:

| Crossing point | Raised as | HTTP |
| --- | --- | --- |
| `PaidGrantDeadline::proveCurrent`, `live()`, `deadline()` | `PaidGrantException(410)` | 410 (r1, r2) |
| `IdentityOriginalCommitWitness` / identity frame and seal deadline checks | `IdentityException` → `PaidGrantCommands` maps to 403 | 403 (staged, probe r1) |
| `CommittedReadContext::deadline` | `CheckoutException('committed_read_expired')` | 409 |
| `OriginalCommitDispatcher::requireCurrent`, second conjunct | `CheckoutException('committed_read_frame')` | 409 (original trace) |

### Root cause: identity schema-ownership inspection volume on MySQL

About 98% of each phase's statements are metadata reads, not paid/producer data:

| Phase | Total | `SELECT DATABASE()` | information_schema | `SHOW CREATE` | data/tx | full identity inspections* |
| --- | --- | --- | --- | --- | --- | --- |
| finalize | 65,452 | 35,787 | 22,276 | 6,183 | 1,206 | 140 |
| document | 240,066 | 130,850 | 82,021 | 22,754 | 4,441 | 512 |
| authorize | 70,198 | 38,332 | 24,010 | 6,588 | 1,268 | 150 |
| redeem | 37,464 | 20,827 | 12,955 | 3,140 | 542 | 82 |

\* counted by the one unfiltered `SELECT * FROM information_schema.TRIGGERS` that
`IdentityMigrationOwnership::inspect` issues per call.

`IdentityRows` and `IdentityHistoricalPlainRows::assertPermanent` call
`IdentityMigrationOwnership::inspect()` on every assertion. On SQLite that is two
`sqlite_master` reads. On MySQL it is roughly 470 statements: per-name
`LOWER(...)=?` lookups across `TABLES`, `TRIGGERS`, `STATISTICS` and
`TABLE_CONSTRAINTS`, `SHOW CREATE TABLE` per parent/reserved name, server-wide
`TRIGGERS`/`VIEWS`/`ROUTINES` scans, and one `SELECT DATABASE()` per examined trigger
and foreign key row (`$this->database($pdo)` inside the loops). At about 0.7–1 ms per
round trip, one paid command (≈27 inspections) costs 15–25 s. Authorize plus redeem
then exceeds the fixed 60 s authorization. The consumer's own metadata
(`PaidGrantRows::qualifiedTable`) and its data/transaction statements are under 2%, so
no consumer-only change can bring the journey inside the budget without dropping
identity/producer proofs.

**This is a producer/identity performance defect, so the fix was stopped and not
applied** (task rule). No producer or identity runtime file is changed on this branch.

### Proposed identity change (for the identity/producer owner)

In `app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php`
(MySQL branch of `inspect`), keeping the same refusal semantics:

1. Read `DATABASE()` once per inspection instead of per trigger/key row.
2. Batch the per-name case-insensitive lookups into one query per catalog
   (`LOWER(name) IN (...)`), keeping the collision checks.
3. Decide whether a full inspection is needed on every `IdentityRows` /
   `IdentityHistoricalPlainRows` assertion inside one held original frame, or whether
   one inspection per frame plus the existing temporary-alias proof (`SHOW CREATE
   TABLE` per used table, as `PrimaryBoundary` does) is sufficient. The current comment
   ("callbacks may introduce temporary aliases after commit") makes this a security
   decision for the owner.
4. The server-wide `TRIGGERS`/`VIEWS`/`ROUTINES` scans also reject another schema's
   objects that merely mention identity table names. On a shared daemon with other
   lanes' identity schemas, that would refuse every identity read (the same behavior
   that blocked migrations on 3306). This is an environment observation, not reproduced
   for identity here.

Bounded experiment, **not committed**: item 1 alone, applied in a disposable worktree
on a second private daemon while another native run was loading the host. Both runs
still failed: unchanged HTTP journey 403 at authorize (line 116, 30 assertions,
618.4 s), and the probe 403 at finalize after 79 s (14 assertions, 496.0 s). Item 1 is
not sufficient by itself, and these runs don't prove that items 1–3 would be.
Evidence: `evidence/exp-*.{txt,xml}`.

## Step 4 status

The 409 root cause sits outside paid252's owned source. Following the stop rule there is
no runtime fix for it here, no deadline or authorization was extended, and no second
observer was added. The recovery test's deliberate fresh re-authorization is unchanged.
`PaidGrantRedeemFrameConjunctTest` is the regression that would have caught it. It
passes on SQLite (1/1, 595 assertions) and fails natively until the identity cost
fits inside the original 60 s authorization.

## Refused-frame transaction record (review condition on `e86381bf`)

Laravel's commit-exception path lowers the depth without telling the
`DatabaseTransactionsManager`. A commit-time refusal of a paid frame therefore left its
pending record, and any `DB::afterCommit` work registered inside it, to run on the next
unrelated commit. `PaidGrantRows::abort()` is called from every paid `DB::transaction`
catch (`PaidGrants::finalize`, `PaidGrantCommands::run`, `PaidGrantReads`,
`PaidGrantDownloads::locate`). It now clears this connection's records in a `finally`
once the framework depth is 0, using the same reflection and `rollback($name, 0)` as
`CommandTransaction` at `e86381bf`. Paid commands start outside every transaction, so
no caller's record can be discarded.

Regression `tests/Feature/PaidGrantRefusedFrameCallbackTest.php` (probe-B style). Its
cases are the finalize transaction and `PaidGrantCommands::run` via
`PaidGrantDownloads::status`. The listener arms only on the commit held by the
producer's `OriginalCommitDispatcher`, registers `DB::afterCommit` work and withdraws
the paid policy. The test asserts a 403 refusal, unchanged rows, depth 0, and no
callback after a later unrelated commit.

| Run | Result | Assertions | Wall |
| --- | --- | --- | --- |
| SQLite, without fix | 2 failures (callback leaked into the next commit) | 40 | 9.4 s |
| SQLite, with fix | 2/2 | 42 | 8.6 s |
| native 8.4.11, without fix | 2 failures (callback leaked) | 42 | 812.3 s |
| native 8.4.11, with fix | 2/2 | 44 | 610.1 s |

## Not tested / open

* The full native paid family. Every native journey case currently exhausts its budget
  because of the identity cost above, so native results for the other cases would only
  repeat that refusal.
* HTTP whole-order two-line partial failure and retry.
* Actual root route mounting and registration. Tests mount `routes/paid-grants.php`
  explicitly.
* The shared 3306 daemon: blocked by cross-schema trigger guards in migrations.
* MySQL 8.0.46 was not rerun. No concurrency or contention claims are made.
* `vite build`, and hosted CI per the cost policy.
