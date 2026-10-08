# Paid252 composition onto the integrated producer — 2026-10-07

Development evidence for the Claude Code harness. This is **not** paid-fulfillment,
registration or production acceptance. Default flags stay off; no payment, provider,
mail or deployment action was taken. No deadline, authorization lifetime, policy or
receipt budget was changed.

## Source

| Item | Value |
| --- | --- |
| Branch | `harness/paid252-composition` (pushed to `origin`; later merges of `origin/main` at `df8126a2` and `705a712e` are described in Step 5) |
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

## Step 5 — after PR #50 (main df8126a2)

Development evidence only; same boundary as above (no payment/provider/mail/deploy
action, no deadline, authorization lifetime, policy or receipt budget changed, no
runtime or test source changed). Evidence: `evidence/after-50/` and `evidence/after-52/`.
Step 5 was started after #50 (main `df8126a2`) and repeated after main moved to `705a712e`
(Tax255 #52, Membership #51).

### Merges

Two clean merges, nothing resolved or regenerated by hand:

| Step | Command | Result |
| --- | --- | --- |
| 1 | `git fetch origin main` → `df8126a2e3a0984817f93807c81a367bdc6bb112`; `git merge --no-edit origin/main` into `a3a9f5d2` | clean, merge commit `cb0c9759` (83 `app/` files from main) |
| 2 | `git fetch origin main` → `705a712e6a3d22ea8e4483c44d2cc47a2d9fa52b` (Tax255 #52, Membership #51); `git merge origin/main --no-edit` into `cb0c9759` | clean, merge commit `21e24e8c` |

Both runs below are labelled with the merge they ran on: `evidence/after-50/` is
`cb0c9759`, `evidence/after-52/` is `21e24e8c` (current head, uncommitted evidence).
Branch `harness/paid252-composition` is pushed (see the Source table).

Step 2 touched `scripts/ci/database-sqlite-skips.json` on both sides (main added 5
methods; the branch added none beyond the first merge), and Git merged it without a
conflict. I verified the result is the union required (main ∪ ours − merge-base): 169
entries = main's 169, `symmetric difference = []`, valid JSON. The branch adds no
entries of its own. Census checks after the merge: `python3 -I
scripts/ci/test-database-receipts.py` 34/34 OK, `test-focused-tests.py` 50/50 OK,
`test-phpunit-shards.py` 36/36 OK. These self-tests do not scan the paid test files, so
they cannot say whether the census needs a paid entry. The file lists MySQL-only methods
that skip on SQLite; `PaidGrantSchemaRecoveryTest::test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl`
skips on SQLite by design and has no entry, so the exact native-only census needs one
when the paid family joins the sharded gate (integration owner's call; not edited here).

### Environment and commands

* PHP 8.4.26. The worktree vendor is symlinked to `/home/user/VA-Studio/vendor` with its
  own `vendor/composer` (`InstalledVersions.php` present, `autoload.php` resolves the
  worktree). The autoload was not regenerated: PSR-4 resolved the classes that arrived
  with #50 (`SchemaQualifierDelimiterTest` is green). The README's `phpunit-wt.php` is not
  in the tree; the equivalent runner (saved as
  `evidence/after-50/helper-phpunit-wt2.php.txt`) sets
  `$GLOBALS['_composer_autoload_path']` to the worktree's `vendor/autoload.php` and then
  includes PHPUnit, exactly as in the Environment section.
* SQLite: `php phpunit-wt2.php -c phpunit.xml <file>` (phpunit.xml defaults, no `public/build`).
* Native: a fresh private daemon, MySQL 8.4.11
  (`mysqld --no-defaults --initialize-insecure`, `--port=3711 --bind-address=127.0.0.1
  --mysqlx=OFF --innodb-buffer-pool-size=512M --user=root`, datadir in the session
  scratchpad, a private socket path instead of the default socket, general log and
  statement history off). Databases `vaseyaudio_paid252b` (test runs) and
  `vaseyaudio_paid252c` (the two measurement helpers). Env as in the native block above
  with `DB_PORT=3711`, `DB_PASSWORD=` (empty). Port 3306 and the other lanes' daemons were
  not touched. The host has 4 cores and carried other lanes' daemons and PHPUnit runs
  (load average 3.5 at the start, 6–8 later), so wall times are noisy.
* Each native run was one PHPUnit process, one at a time, `rc=$?` captured on its own
  line in `evidence/after-50/native-*.txt` (JUnit `*.xml` beside it).

### SQLite (merge `cb0c9759`, `evidence/after-50/`)

| File | Tests | Assertions | rc | Wall |
| --- | --- | --- | --- | --- |
| PaidGrantCommitCapsuleTest | 11/11 | 241 | 0 | 55 s |
| PaidGrantCommittingAdmissionTest | 2/2 | 36 | 0 | 9 s |
| PaidGrantDocumentJourneyTest | 4/4 | 87 | 0 | 34 s |
| PaidGrantDownloadJourneyTest | 5/5 | 112 | 0 | 51 s |
| PaidGrantHttpJourneyTest | 18/18 | 534 | 0 | 54 s |
| PaidGrantRedeemFrameConjunctTest | 1/1 | 661 | 0 | 12 s |
| PaidGrantRefusedFrameCallbackTest | 2/2 | 42 | 0 | 9 s |
| PaidGrantSchemaRecoveryTest | 9/10, 1 skipped | 1,373 | 0 | 2 s |
| PaidGrantSourceJourneyTest | 12/12 | 196 | 0 | 51 s |
| `tests/Unit/SchemaQualifierDelimiterTest.php` | 5/5 | 26 | 0 | 1 s |

Paid family (the nine `PaidGrant*Test.php` files): 65 tests, 64 passed, 1 skipped (the same native-only
case as before), 0 failures, 3,282 assertions. The conjunct probe now has 661
assertions on SQLite (595 before the merge) because it samples more frames.

### Native MySQL 8.4.11 — the three requested runs (merge `cb0c9759`)

| Run | Selection | Result | Assertions | rc | Wall |
| --- | --- | --- | --- | --- | --- |
| (a) | `PaidGrantRedeemFrameConjunctTest` | 1/1 pass | 19,538 | 0 | 406 s |
| (b) | `PaidGrantHttpJourneyTest::test_actual_typed_buyer_http_finalization_paid_pdf_and_native_file_delivery_preserve_originals` | 1/1 pass | 98 | 0 | 410 s |
| (c) | `PaidGrantRefusedFrameCallbackTest` | 2/2 pass | 44 | 0 | 317 s |

Before the merge the same (a)/(b) failed on this composition with 410/403 (r1, r2, probe
r1 above), and (c) passed (610 s). The master redemption now completes with 200 and
the exact master bytes natively. This is the first native pass of the HTTP journey
case on this lane.

### Statement volume and wall per phase (merge `cb0c9759`)

The conjunct test's phases (deterministic statement counts, MySQL `Questions`, from the
two untraced margin runs; `stmt_execute` from the traced run a2 is within 1% of these).
Before = r2 above (executed prepared statements, general log on).

| Phase | Before (r2) | After (m1 / m2) | Wall before | Wall after (m1 / m2) |
| --- | --- | --- | --- | --- |
| finalize | 64,701 | 10,034 | 67.3 s | 18.3 s / 17.9 s |
| document | 239,033 | 37,014 | 210.8 s | 76.3 s / 65.4 s |
| authorize | 70,205 | 10,782 | 50.8 s | 20.4 s / 19.5 s |
| redeem | 38,591 | 18,696 (+594 streaming) | 29.8 s | 38.5 s / 43.0 s (+1.2 s / 0.8 s) |

The statement count fell about 6.5× in finalize, document and authorize and about 2×
in redeem, so the three earlier phases now fit comfortably. Redeem's wall time did not
fall: 18.7k statements in 38–43 s is about 2.1 ms per statement, against about 0.8 ms
in r2. The host was more heavily loaded and I have not measured why per-statement cost
rose, so the redeem wall comparison is not conclusive in either direction.

### Margin inside the original 60 s authorization (merge `cb0c9759`)

Measured with a measurement-only copy of the conjunct test without the sampling probe
(`evidence/after-50/helper-PaidGrantMarginMeasureTest.php.txt`, run from the
scratchpad, the repository was not edited; output `native-m1-margin.json`,
`native-m2-margin.json`). Remaining time is `expiresAt` minus the instant the last
streamed byte was written. `expiresAt` has one-second resolution, so ±1 s.

| Run | Selection | Result | rc | Wall | Authorize returned → expiry | Redeem + stream | Margin |
| --- | --- | --- | --- | --- | --- | --- | --- |
| m1 | margin copy | pass (20 assertions) | 0 | 262 s | 06:30:44 → 06:31:30 (46 s) | 38.5 + 1.2 s | **6.2 s** |
| m2 | margin copy | pass (20 assertions) | 0 | 328 s | 06:36:06 → 06:36:54 (48 s) | 43.0 + 0.8 s | **3.2 s** |

So the paid journey now fits inside the original 60 s window, but only by 3–6 s
(5–10% of the lifetime) on this loaded host. It is not robust.

A traced repeat of the conjunct test (a2, same test as (a) with `VA_PAID_FRAME_TRACE`
set so it writes its trace file) **failed**: rc 1, 17,779 assertions, 372 s. All phase,
dispatcher and deadline conjunct assertions passed and the redemption returned 200 at
06:25:55, but the streamed body was empty. The last mark is 0.19 s after the
observer-held original deadline (`deadline_ns` 12261018305750 vs mark 12261211183817),
so the byte-equality assertion failed. The only difference from (a) is the trace write at
the end, so (a) and a2 pass and fail on the same edge with load as the only varying input I
know of. The failure is consistent with the same time budget, not a logic regression: the
probe's sampler adds overhead and redeem took 42.2 s there. (Evidence:
`native-a2-conjunct-traced.*`, `native-a2-trace.json`; the binary WAV diff in the log and
JUnit was elided to a one-line note.)

### Rest of the native paid family (merge `cb0c9759`)

| File | Result | Assertions | rc | Wall |
| --- | --- | --- | --- | --- |
| PaidGrantDownloadJourneyTest | 4 pass, **1 failure** | 102 | 1 | 1,723 s |
| PaidGrantDocumentJourneyTest | 4/4 pass | 91 | 0 | 1,080 s |
| PaidGrantCommittingAdmissionTest | 2/2 pass | 38 | 0 | 525 s |
| PaidGrantCommitCapsuleTest | 11/11 pass | 250 | 0 | 2,824 s |
| PaidGrantSourceJourneyTest | 12/12 pass | 208 | 0 | 2,927 s |
| PaidGrantSchemaRecoveryTest | 7 pass, **1 failure**, 1 skipped (of 10) | 574 | 1 | 21 s |
| PaidGrantHttpJourneyTest, other 17 cases (excluding (b)) | not run: a run was interrupted by a container reboot and was not repeated | | | |

**DownloadJourney failure** —
`test_exact_paid_master_and_original_pdf_are_one_attempt_each_with_token_free_bounded_status_and_consumed_replay_refusal`
fails at line 158 (`Failed asserting that 410 is identical to 409`): the replay of a
spent authorization returns 410 where the test expects 409. A timing copy of that test
(`helper-PaidGrantReplayTimingTest.php.txt`; `native-replay-timing.jsonl`, same failure,
rc 1, 18 assertions, 327 s) shows the cause is the same 60 s lifetime: the authorization
expires at 07:11:52; `authorize` took 11.9 s, the idempotent second `authorize` 12.6 s,
`redeem` 33.0 s and streaming 1.0 s (bytes done 07:11:46, about 54 s after creation), and
the spent-replay `redeem` then took 9.7 s, returning at 07:11:56, four seconds after
expiry, with `Paid grant request unavailable.` (410) instead of the consumed 409. I did
not read the source to confirm that expiry is evaluated before the consumed check; the
observed order is consistent with it. The test's replay step needs the whole
authorize-authorize-redeem-replay sequence inside 60 s, which this host cannot do.
Nothing was changed (no deadline, lifetime or test).

**SchemaRecovery failure** —
`test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl`
fails at line 255: `assertStringStartsWith('8.0.46', VERSION())` against `8.4.11`. The
case pins the server version, so it cannot pass on this daemon; it needs an 8.0.46
server, which this host does not have. The skip is the SQLite-only composite-key case.
Nothing else in the file failed.

### Re-run on main `705a712e` (merge `21e24e8c`, `evidence/after-52/`)

A container reboot killed the daemon between the two merges, so a fresh private
MySQL 8.4.11 was initialized (same options, port 3711, `vaseyaudio_paid252b` and `_c`)
and everything below ran once on it, one process at a time. The new merge adds three
migrations (`255000` tax checkout, `258100` member activation, `259000` membership
billing), which run in every native test's migration step.

SQLite, `21e24e8c`: all green, rc 0 — CommitCapsule 11/11 (241 assertions), CommittingAdmission
2/2 (36), DocumentJourney 4/4 (87), DownloadJourney 5/5 (112), HttpJourney 18/18 (534),
RedeemFrameConjunct 1/1 (751), RefusedFrameCallback 2/2 (42), SchemaRecovery 9/10 with
1 skipped (1,373), SourceJourney 12/12 (196), `SchemaQualifierDelimiterTest` 5/5 (26).
Walls 1–65 s each (`after-52/sqlite-summary.txt`).

Native, `21e24e8c`:

| Run | Selection | Result | Assertions | rc | Wall |
| --- | --- | --- | --- | --- | --- |
| (a) | `PaidGrantRedeemFrameConjunctTest` | PHP abort `zend_mm_heap corrupted`, no result | n/a | 134 | 161 s |
| (a2) | same, repeated | 1/1 pass | 20,180 | 0 | 332 s |
| (b) | HTTP journey case | **fail**, line 124: streamed body differs (empty) after a 200 | 47 | 1 | 352 s |
| (b2) | same, repeated | **fail**, line 125: replay of the spent authorization returns 410, expected 409 | 48 | 1 | 354 s |
| (c) | `PaidGrantRefusedFrameCallbackTest` | 2/2 pass | 44 | 0 | 370 s |
| m1 | measurement copy (no probe) | pass | 20 | 0 | 376 s |
| m2 | measurement copy (no probe) | **fail**: 403 at redeem, 0.37 s past expiry | 18 | 1 | 453 s |
| — | `PaidGrantDownloadJourneyTest` | 4 pass, **1 failure** (same replay 410 vs 409, line 158) | 102 | 1 | 1,722 s |

Margin measurement, same method as above, statement counts identical to `cb0c9759`
(10,034 / 37,014 / 10,782 / 18,696 + 594), so the new migrations do not change the paid
path's statement volume:

| Run | finalize | document | authorize | redeem + stream | Remaining at last byte |
| --- | --- | --- | --- | --- | --- |
| m1 | 18.9 s | 77.6 s | 25.6 s | 34.4 + 0.8 s | **+7.2 s** (expiry 11:22:12) |
| m2 | 24.4 s | 100.2 s | 30.2 s | 39.5 s, refused | **−0.4 s** (expiry 11:29:37) |

(`native-m1-margin.json`, `native-m2-margin.json`; ±1 s on expiry.) The host load
average was 4–7 on 4 cores during these runs (3.5 earlier at `cb0c9759`); m2's phases
ran about 25–30% slower than m1's and crossed the window. The `cb0c9759` runs (a), (b),
m1, m2 passed with 3.2–6.2 s remaining; (a2 traced) failed 0.19 s over; so across both
merges the same journey passes or fails on host load, and the margin is smaller than the
run-to-run variation. The binary WAV diffs in the (b)/(b2) logs and JUnit were elided
to one line. I did not investigate the one `zend_mm_heap corrupted` abort: it did
not recur on repeat, and the only unusual component in that test is the probe's
asynchronous SIGUSR1 sampler. Treat it as unexplained.

### Tax255 A1-1 dependency

Paid252 wiring does not touch it. `git diff cb0c9759 21e24e8c` has no change under
`app/Domain/Grants/Paid`, `app/Domain/Commerce/ProductionCheckout` or
`tests/Fixtures/paid-development`. The paid consumer is V1-only:
`PaidGrants` and `PaidGrantCommands` call `ProductionPaidOrderSourceV1::lockedRead(...)`
and keep `$source->proveRetainedCurrent(...)` after use (`PaidGrants.php:205`), and
nothing in `app/Domain/Grants/Paid` references `ProductionTaxPaidLineAdapterV2` or calls
its `accept()`. So there is no V2 consumer here to satisfy "accept() must call
`proveRetainedCurrent`" or "the consumer takes its target from the line". For reference,
`ProductionTaxPaidLineAdapterV2::accept($source, $position, $line, $provenance)`
authenticates by byte-comparing `$line` with the held `$source->line($position)`; it does
not itself call `proveRetainedCurrent`. Its docblock leaves that to the caller, which
must keep its own `proveRetainedCurrent` after use. Wiring a V2 paid consumer would need
`PaidGrants`/`PaidGrantCommands` (type-hinted to the V1 source) to take a V2 source,
call `accept`, and keep the post-use `proveRetainedCurrent`. That is not done here.

### What passed, what still fails

* SQLite: everything green at both merges.
* Native at `cb0c9759`: the conjunct probe (a), HTTP journey case (b), refused-frame
  regression (c), DocumentJourney, CommittingAdmission, CommitCapsule and SourceJourney
  passed. DownloadJourney's replay case, the traced repeat a2 and SchemaRecovery's
  version-pinned case failed.
* Native at `21e24e8c`: (a2), (c), m1 pass; (b), (b2), m2 and the DownloadJourney replay
  case fail on the 60 s authorization (Tax255/Membership do not change statement
  volume; host load decides), and (a) aborted once. The journey fits inside the
  window only with a 3–7 s margin on a lighter host and does not hold reliably.
  No deadline, authorization lifetime, policy or receipt budget was changed (a change is
  a policy decision for the owner; the alternative is more statement reduction in the
  identity/producer path, where redeem still runs 18.7k statements).
* Not re-run natively at `21e24e8c`: DocumentJourney, CommittingAdmission, CommitCapsule,
  SourceJourney, SchemaRecovery and the other 17 HttpJourney cases (they passed or
  failed as above at `cb0c9759`; the `21e24e8c` merge changes no paid source).

### Cleanup

The private daemon was shut down with `mysqladmin -h127.0.0.1 -P3711 -uroot shutdown`,
nothing listens on port 3711 and the datadir and socket were deleted. Helper runner and
copies are in `evidence/after-50/helper-*.txt`.


## Not tested / open

* The full native paid family at the current head `21e24e8c`. At `cb0c9759` the native
  Document, CommittingAdmission, CommitCapsule and SourceJourney files passed; at
  `21e24e8c` only (a2), (c), the margin copy and DownloadJourney were run (Step 5).
* The native journey still depends on host load: after #50 the paid HTTP journey
  completes inside the original 60 s authorization with a measured 3–7 s margin on a
  lightly loaded host and fails (0.4 s over, or 410 on the spent-replay check) on a loaded
  one. `PaidGrantDownloadJourneyTest` and `PaidGrantHttpJourneyTest` assert a 409 on
  replaying a spent authorization after the whole sequence and fail with 410 once the
  60 s lifetime has passed. Not resolved; no deadline or lifetime changed.
* One unexplained `zend_mm_heap corrupted` abort of the conjunct probe (rc 134) that did
  not recur.
* `PaidGrantSchemaRecoveryTest`'s native case pins MySQL 8.0.46 and was not run on an
  8.0.46 server. The paid family has no entry in `scripts/ci/database-sqlite-skips.json`
  for that SQLite skip.
* HTTP whole-order two-line partial failure and retry; the other 17 native
  `PaidGrantHttpJourneyTest` cases.
* A V2 (Tax255) paid consumer: this lane's consumer is V1-only (Step 5, Tax255 A1-1).
* Actual root route mounting and registration. Tests mount `routes/paid-grants.php`
  explicitly.
* The shared 3306 daemon: blocked by cross-schema trigger guards in migrations (the
  scoping fix in #50 was not re-tried there).
* MySQL 8.0.46 was not rerun. No concurrency or contention claims are made.
* `vite build`, and hosted CI per the cost policy.
