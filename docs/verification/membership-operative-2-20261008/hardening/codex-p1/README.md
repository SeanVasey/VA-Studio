# Codex P1 on PR #54: sweep cursor (L2-2) and database-issued retrieval order (L2-3)

Status: complete for development. The lane agent wrote this record; it was stopped before the native runs finished, and the integration owner completed them and committed the lane.

- Branch `harness/membership-operative-2`, worktree `/home/user/VA-Studio-member-ops2`, base head `83821fc0`. Committed by the integration owner (see the PR).
- Sources: Codex P1 review comments on PR #54 (`BillingHintSweep.php:29`, `BillingLedger.php:120`), which re-raise lane 2 review
  findings L2-2 and L2-3 (`../../independent-review/DECISION.md`).
- PHPUnit: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <path>`.
  Each run file ends with `rc=` written straight after PHPUnit returns.

## P1-B (L2-3): ordering by a database-issued position, not a wall clock

What was wrong: the R-6 append rule and the A1-3 hint-coverage rule compared `retrieval_started_at` (each worker's clock) with
the tail's `retrieval_started_at` (another worker's clock) and with the hint's `received_at` (the intake host's clock). With clock
skew an older retrieval on a clock-ahead worker could append after a newer reversal and become the stale `settled` tail, a newer
retrieval on a clock-behind worker was refused, and a retrieval that began before a hint could "cover" it.

Design (migration 259000's `BillingSchema`, changed in place):

- New table `production_membership_billing_positions` (`TABLES[4]`): `id` is `BIGINT UNSIGNED AUTO_INCREMENT` (MySQL) /
  `INTEGER PRIMARY KEY AUTOINCREMENT` (SQLite), `kind` in (`retrieval`, `hint`), `created_at` (information only). No update or
  delete; the insert guard admits only a database-allocated id (`NEW.id < 1`: an unassigned auto id reads 0 on MySQL and -1 on
  SQLite before the insert), so a position cannot be back-dated into a gap. It names no invoice, binding or provider reference.
- `BillingLedger::startRetrieval()` inserts a `retrieval` position in its own short committed transaction (no transaction may be
  open) at the start of `BillingReconciliation::retrieve()`, before any provider read. The id is carried through the retrieval and
  stored on the observation as `retrieval_position` (UNIQUE, CHECK `> 0`, sealed with the row).
- `append()` refuses `superseded_retrieval` unless the tail's `retrieval_position` is strictly below the new one (R-6 behaviour
  kept: refuse, never append a superseded row). The observations insert guard enforces the same: the referenced position must
  exist with kind `retrieval`, be unused, and exceed the tail's position.
- The webhook intake allocates a `hint` position inside the same transaction as the event row (`hint_position`, UNIQUE, CHECK
  `> 0`, sealed); the events guard requires an unused `hint` position. Coverage (`BillingHintRecovery::observedAfter`) is now
  `o.retrieval_position > hint_position` (still: definitive outcome only; `unknown` and `provider_incomplete` never cover).
- Why this is correct: AUTO_INCREMENT/AUTOINCREMENT hands out ids in strictly increasing allocation order on one server and never
  reuses a committed one. A retrieval's position is committed before its provider reads begin; a hint's position is allocated
  after the hint arrived. So "position larger" means "began later", decided by one counter on the database, with no comparison
  of any two hosts' clocks. Positions are unique, so ties no longer exist (the old rule admitted equal microseconds) and A1-6's
  whole-second coverage margin is no longer needed.
- `retrieval_started_at` and `received_at` remain as information only (operator timeline, skew diagnosis); neither orders
  anything. `received_at` still keys the sweep's page order (below), which affects only paging, not coverage.
- Job `$tries`/backoff/release and the `superseded_retrieval` normal end are unchanged.

Alternatives rejected:

- Database clock (`UTC_TIMESTAMP(6)` read before provider I/O, and at intake): one clock, but not monotonic (NTP steps, failover
  to another primary), ties at equal microseconds still possible, and SQLite only offers milliseconds.
- Single-row counter updated with `LAST_INSERT_ID(value + 1)`: needs an UPDATE on billing evidence (every billing table forbids
  updates) and a hot row lock.
- Insert-and-roll-back to consume an auto-increment value without keeping a row: SQLite rolls the sequence back (reused values,
  ties), and the MySQL guarantee depends on counter persistence details. A retained row is simpler and is evidence that a
  retrieval began.
- `MAX(id)` at intake as the hint's position: misses an allocated-but-uncommitted retrieval position, which would then wrongly
  cover the hint (unsafe direction).
- Foreign keys from observations/events to positions: the table is appended as `TABLES[4]` so existing `TABLES[n]` indices stay
  stable; a MySQL FK to a later-created table cannot be declared, so existence and kind are enforced by the insert guards, and
  positions are immutable (no update or delete), which gives the same guarantee as `ON DELETE/UPDATE RESTRICT`.

Behaviour notes:

- A first retrieval that throws (unknown, provider-incomplete, binding refusal) still writes no invoice identity and no
  observation; it leaves only an anonymous `retrieval` position row. Existing tests that assert no identity/observation rows are
  unchanged and pass.
- The append's existing `created_at` monotonic check (`clock`) and trigger clause `o.created_at <= NEW.created_at` are kept
  unchanged (not weakened). They still compare application clocks across workers, but fail closed (a refusal, retried by the job),
  never stale. Recorded below as a remaining condition.
- `migrate:fresh` is required for any local or CI database migrated from an earlier 259000 (new table and columns; the owned-schema
  check refuses the old shape and billing fails closed). 259000 has not been applied in production or any shared environment.

## P1-A (L2-2): the sweep advances

What was wrong: `scan()` always read the oldest `--limit` (at most 1000) `retrieval_hint` rows with no cursor; hints are retained
forever, so newer uncovered hints became unreachable.

Design:

- `BillingHintSweep::scan($limit, $configuration, ?$after)` is a keyset over the existing order `(received_at, id)`:
  `received_at > ? OR (received_at = ? AND id > ?)`. A page that stops short of the end returns `next`, a cursor that continues
  after its last examined hint.
- `scanAll()` follows the cursors itself until no hint follows or `MAX_SWEEP_HINTS` (10,000) were examined, then reports
  `truncated` with the continuing cursor. `--dispatch` queues one retrieval per distinct uncovered invoice across all pages
  examined in that run.
- Command: `membership-billing:sweep-hints [--limit=1..1000] [--after=<cursor>] [--all] [--dispatch]`. Prints
  `Next cursor: <cursor>` (with a warning naming `--after` / `--all`) or `Scan complete: N hint(s) examined in P page(s).`
- Cursor format: `v1.<received_at as YYYYMMDDHHMMSS>.<hint row uuid>.<HMAC-SHA256 hex>`, the HMAC under `APP_KEY` over a
  domain-separated string with the configured provider account and mode. It carries no provider reference, hash or secret.
  Invalid shape, edited fields, a wrong MAC, another app key or another provider account: `Refused (cursor).`, exit 1, nothing
  listed or dispatched.
- Kept: dry-run default; the billing policy is read before any ledger read and before the cursor is opened (policy off refuses
  `disabled` even with a garbage cursor); output prints hash prefixes and internal ids only; every row's seal is verified on every
  page (a forged hint on a later page refuses `tampered_ledger` and dispatches nothing).
- A cursor continues one pass. Hints whose intake clock placed them behind an old cursor are reached by the next pass started
  without `--after` (documented in the command).

## Tests

New (written first, red against the unchanged application code):

- `BillingClockSkewOrderingTest` (8, SQLite and MySQL): older retrieval on a clock-ahead worker refused as superseded, newer
  reversal stays the tail (existing identity and first-retrieval variants); newer retrieval on a clock-behind worker admitted;
  identical clock readings still ordered; hint coverage with a worker clock ahead of the intake clock (not covered) and behind it
  (covered); same clock instant (covered); position committed before provider I/O with no open transaction.
- `BillingSweepHintsCursorTest` (7): uncovered hint beyond the first window reached by `--after`; reached by `--all`; `--all
  --dispatch` dedupes one invoice across pages; overall bound stops and reports the continuing cursor, which then finishes;
  invalid/tampered/foreign-key/foreign-account cursors refused with nothing listed or dispatched; policy before cursor; forged hint
  on a later page fails closed.
- `BillingNativeStaleRetrievalRaceTest::test_a_stale_retrieval_whose_worker_clock_runs_ahead_is_still_refused_after_a_newer_reversal`
  (native only; two processes, the stale worker's clock frozen 120 s ahead). Added to `scripts/ci/database-sqlite-skips.json`.

Adapted existing tests (no assertion weakened):

- `BillingSchemaPreparationTest`: row helpers allocate positions; the immutability test now covers the positions table too;
  the started-at guard test is replaced by a position guard test (earlier and reused positions refused; an earlier worker clock with
  a later position admitted); new test for database-only position ids and kind/uniqueness guards; the "empty tail restarts" test
  drops the last guard of the new last table (`positions_delete`) so it is still a contiguous tail.
- `BillingObservationLedgerTest` forged-seal case and `BillingWebhookRedeliveryTest::appendVerdict` pass a fresh retrieval position.

## Results

Recorded files (this directory):

- `red-p1b-clock-skew-sqlite.txt`: 8 tests, 10 assertions, 6 failures, 2 errors, rc=2.
- `red-p1a-sweep-cursor-sqlite.txt`: 7 tests, 6 assertions, 3 failures, 4 errors, rc=2.

- `green-p1b-clock-skew-sqlite.txt`: OK, 8 tests, 26 assertions, rc=0.
- `green-p1a-sweep-cursor-sqlite.txt`: OK, 7 tests, 112 assertions, rc=0.
- `sqlite-ProductionMembershipBilling.txt` (+ `.xml`): OK, 161 tests, 782 assertions, 2 skipped (both native-only
  `BillingNativeStaleRetrievalRaceTest` methods), rc=0. Baseline at `83821fc0`: 144 / 628 / 1 skipped.
- `sqlite-ProductionMembership.txt`: OK, 44 tests, 130 assertions, 3 skipped (existing native-only), rc=0.
- `sqlite-ProductionMemberOriginals.txt`: OK, 24 tests, 63 assertions, 1 skipped (existing native-only), rc=0.
- `pint.txt`: passed on all 16 changed/new PHP files, rc=0 (one whitespace fix applied first).
- `receipts-self-test.txt`: `python3 -I scripts/ci/test-database-receipts.py`, 34 tests OK, rc=0.

Native MySQL 8.4.11 (private `mysqld --no-defaults` on 127.0.0.1, datadir in the session scratchpad, deleted afterwards;
`native/private-instance-lifecycle.txt`, `native/ledger.txt`). All runs were on the final lane tree (no source file changed
after 16:05 UTC).

- `native/red-clock-ahead-at-base.txt`: the new clock-ahead native case at base `83821fc0` (scratch worktree with only the
  new test and worker): 1 failure, rc=1.
- `native/BillingNativeStaleRetrievalRaceTest.txt`: OK, 2 tests, 27 assertions (two processes), rc=0.
- `native/mysql-BillingSchemaPreparationTest.txt`: OK, 12 tests, 63 assertions, rc=0 (positions table guards, database-only
  ids, kind and uniqueness guards on MySQL triggers).
- `native/mysql-BillingClockSkewOrderingTest.txt`: OK, 8 tests, 26 assertions, rc=0.
- `native/mysql-BillingSweepHintsCursorTest.txt`: OK, 7 tests, 112 assertions, rc=0.
- `native/mysql-ProductionMembershipBilling-stopped-partial.txt`: a full-directory MySQL run was stopped by the
  integration owner after 24 of 161 tests (about one minute per test on MySQL); it was replaced by the focused set above.
  The full directory is green on SQLite (161 tests). The remaining native-only coverage of the directory is the census
  method set, which runs in Foundation CI.

Not tested: the full 161-test directory on MySQL, Foundation CI, a real provider.

## After merging main `72045620` (identity key rotation)

`0225c504` merges `origin/main` into the branch. `scripts/ci/database-sqlite-skips.json` is the union of main and this
lane's additions (180 methods); `python3 -I scripts/ci/test-database-receipts.py` passes. SQLite on the merged tree
(`merged-0225c504/`): `ProductionMembershipBilling` 161 tests, 782 assertions, 2 skipped (native-only); `ProductionMembership`
44 tests, 130 assertions, 3 skipped; `ProductionMemberOriginals` 24 tests, 63 assertions, 1 skipped; all rc 0.
