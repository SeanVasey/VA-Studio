# Independent re-review: lane CX fixes at merged head 16687e9f

- Branch `harness/customer-comms-cx-20261024`. HEAD `16687e9fbee477d17bb8e7606fd11f90acecc0d7`, which is lane `a6c153ac` merged with main `76fca3d7` (PR #178 REP, PR #179 symfony/mime 8.1.10).
- Code delta since my review of `733b02f7`: `105f37a2` (F1 purge, F3, F6) and `f553a10f` (event sequence read FOR UPDATE). Only `CustomerDataRights.php` and two test files changed. No migration or trigger changed.
- Reviewer: independent Claude subagent. I worked only in my own scratch worktrees and my own mysqld. `/home/user/wt/cx` is unchanged (clean, HEAD `16687e9f`).
- Dates: 2026-10-30 and 2026-10-31. The run was interrupted by a usage limit and a container restart. Progress: `/home/user/notes/cx-review/PROGRESS.md`; earlier lines are copied from the retired scratchpad.

## Verdict: APPROVE WITH CONDITIONS

F1, F2, F3 and F6 are resolved and verified. There is no P0 or P1.

There is one new P2 (F7, locking and contention), which can be deferred under the freeze-and-batch rule:

- **Condition C2:** fix F7 before customer data rights are enabled on any shared host (staging or later).
- Condition C1 (F1) is met.

The two ops-test failures on the merged head come from main (PR #178, which adds REPORTING_* keys). They are not a CX defect, and the lead is fixing them in PR #181. Re-run the ops tests once #181 lands.

## F7 (new): P2. Range locking reads on shared indexes make unrelated customers' data requests block, and deadlock, each other

All of these were reproduced natively through the real service code on my own MySQL 8.4.11 (REPEATABLE READ, the default).

The probe is `ReviewerDeadlockTest`, a scratch file, not committed. Two worker processes run the real `CustomerDataRights` operations. A query listener pauses each worker right after a chosen query, on its first attempt only, and then both are released together. Raw results are in `deadlock-forupdate2.txt` (head) and `deadlock-plain.txt` (head with the event `FOR UPDATE` removed). Probe sources are under `probe/`.

### a) Event level: new in f553a10f, observed as blocking

`CustomerDataRights::event()` (`CustomerDataRights.php:332`) runs `select max(sequence) as aggregate from customer_data_request_events where request_id = ? for update`. The optimizer resolves MAX with a min/max lookup on `customer_data_request_events_request_id_sequence_unique (request_id, sequence)`. The `FOR UPDATE` puts X next-key locks on the newest record of that request and on the next key; at the index tail, that is the supremum.

Scenario S2:
1. Staff approve customer X's deletion. The worker pauses after its second event MAX, the one for `deletion_completed` on X's request 2.
2. Customer Y, an unrelated customer, requests deletion at the same moment.
3. Y's MAX for its brand-new request then **waits**: it wants X on record `2, 1, 3` of that index, which the approval holds (captured from `performance_schema.data_lock_waits`).

Y stayed blocked until the approval ended. In the probe the approval was deliberately held and timed out after 45 s, and only then could Y proceed.

The control run with the `FOR UPDATE` removed shows no wait: Y paused alongside the approval and both saved.

So any open data-rights transaction that has written the newest events serializes every other customer's new request until it commits. In the approval, that window spans the rest of the transaction after the purge: the account update, the bcrypt `Hash::make`, the `retained()` counts and the audit write.

I did not observe a deadlock at this level through the application in S2. The second transaction waits before it holds anything the first needs. But the same statement pair deadlocks when both tail scans land on the supremum. My raw two-session run on 2026-10-30 issued the exact SQL Laravel emits, for two different customers' new requests with no events. Both took `X` on `supremum`, both then inserted, and one got `ERROR 1213`. The plain-MAX control run had no deadlock.

### b) Request level: pre-existing since 733b02f7 (I missed it in my first review), observed as a deadlock

The open-request checks run `... where customer_account_id = ? and kind = ? and open_slot = 1 for update`:

- `requestExport` at `:58`;
- `requestDeletion` at `:140`;
- `expireDue` at `:278`;
- the new `purgeOpenExports` at `:291`.

Scenario S1: two customers request deletion at the same time, both paused right after that check. Both held `X` on the `supremum pseudo-record` of `customer_data_requests_open_unique`. After release, both inserted their request row, and InnoDB detected **1 deadlock**. Laravel retried the victim (the second attempt shows in its query count), and both saved; there were no duplicate sequences. The result is identical on head and on the M21 mutant, so it does not depend on f553a10f.

### Effect

Integrity holds: the victim rolls back whole, the unique `(request_id, sequence)` key and the insert guard prevent duplicates, and no test showed a duplicate. The impact is availability:

- Unrelated customers serialize on the tail of two shared indexes.
- Deadlocks are retried up to 3 times on the customer and staff paths. After 3 deadlocks the `QueryException` reaches the controller, which catches only `CustomerAccessException` and `DataRightsException`, so the customer gets a 500.
- `pruneExpired` uses a single attempt, so one deadlock fails the operator command.

The feature is default-off and test-only, so the severity is P2.

### Proposed fix

Every writer already takes the customer's `users` row FOR UPDATE first. Prune and staff paths then take the request row FOR UPDATE. Those row locks are the correct serialization points, so the range locks are redundant.

1. **Event sequence.** Keep a per-request counter on the request row, which every event writer already holds FOR UPDATE.
   - Add `last_event_sequence` to `customer_data_requests`. Migration `268200` is not yet on main, so it can still be amended, followed by a new native review.
   - Set `sequence = last_event_sequence + 1` and update the counter in the same statement as the event.
   - Let the update guard admit exactly `NEW.last_event_sequence = OLD.last_event_sequence + 1` with nothing else changed, or fold it into the existing version bump.
   - Make the event insert guard compare against the parent's counter instead of MAX.

   This removes the MAX range read and is independent of isolation level. A lighter alternative is to keep MAX as a plain read but run these transactions at READ COMMITTED (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before each `DB::transaction`). The parent row lock then makes a fresh statement snapshot correct, and InnoDB takes no gap locks.
2. **Open checks.** Drop `lockForUpdate()` from the open-slot existence checks (`:58`, `:140`). Per account they are already serialized by the customer `users` row lock, and the unique `customer_data_requests_open_unique` index remains the backstop. Under READ COMMITTED they could stay as they are.
3. **Tests.** Add a native race with two different customers creating requests and an approval in flight, asserting no deadlock and no wait on another customer's rows. Two-customer variants of the existing `race()` helper suffice. Pause through a query listener as my probe does, rather than on the user-row wait.

## Focus items

### 1. F1: resolved

- **Same transaction, atomic.** `purgeOpenExports()` runs inside the approval transaction, after `staff()` and `lockForStaff()` (staff user, then customer user → account → deletion request). For each open export (`open_slot = 1`) it locks the export request and its export row, then calls `expire()`: `ready → expired`, ciphertext set to NULL, `purged_at` set, and an `export_expired` event with reason `deletion_approved`. The approval's actions record `pending_exports: purged`. Any later failure rolls the whole approval back.
- **Refusals leave everything intact.** A stale version refuses inside `lockForStaff` before the purge; the test asserts the ciphertext is still present. Unset retention or a disabled feature refuse before the transaction.
- **Replay** is refused with `request_changed` and adds no events (asserted).
- **A downloaded export is unchanged**, because it has `open_slot` NULL. This is asserted with an `assertEquals` on its row. Mutant R22 (filter removed) is killed: the downloaded export then trips `export_state_invalid`.
- **Triggers** are not changed (no migration diff since `733b02f7`). The existing guards admit only export `ready → expired` with resolver and hash NULL, and a one-time purge with `consumed_at` NULL, which is this path. Nothing was widened. My earlier adversarial native SQL on these triggers still applies.
- **Probe** (`ReviewerProbeTest`, SQLite) on `16687e9f`: `export ciphertext NULL, export request state=expired, open_slot=NULL`; `decrypted export still contains original email: no`; `OK (1 test, 1 assertion)`.
- **Native:** the new race `test_an_export_committed_while_an_approval_waits_is_purged_by_that_approval` passes, inside `CustomerDataRightsConcurrencyTest` with 5 of 5 OK.
- **Mutants (SQLite, my own):**
  - Killed: R20 (no purge), R22 (no open filter), R23 (purge reason dropped), R24 (export ledger count dropped).
  - M21 (event `FOR UPDATE` removed) **is killed natively**: the new race errors (`JsonException` decoding the worker output, `ERRORS! Tests: 1, Assertions: 78, Errors: 1`), which is consistent with the author's report of a `QueryException` in the approval worker.

### 2. Locking in f553a10f

- **Index:** `customer_data_request_events_request_id_sequence_unique (request_id, sequence)`. `EXPLAIN` shows a min/max lookup ("No matching min/max row" when the range is empty).
- **Locks taken:** X next-key on the newest record of the request and on the next key; at the tail, that is the supremum.
- **Lock order:** every customer path and the approval and rejection take the customer `users` row first, then the account, the request rows, the export rows and the event index. The approval takes the staff `users` row before the customer's. Prune takes request → export → events without the user row.
- **Same customer:** no cycle; the users row serializes.
- **Across customers:** the event-index tail and the open-unique tail are shared. That gives the blocking and deadlocks described in F7.

### 3. F2: resolved

- `PRIVACY.md` no longer claims encrypted backups anywhere (sections 5, 7 and 8). The appendix row at `:253` states that encryption is opt-in and off by default (`backup.sh:20`, `:51` `VASEY_BACKUP_REQUIRE_ENCRYPTION:=0`), and that the production target is open (U-02, S-10). That matches `ops/staging/backup.sh` and `ops/production/README.md:129-131` ("Production should set `VASEY_BACKUP_REQUIRE_ENCRYPTION=1`").
- Note, not a finding: `:145` ("up to 7 daily, 4 weekly and 6 monthly") describes tiered retention, which is also opt-in. The default keeps the newest 7 sets, so "up to" stays a true upper bound. It is pre-existing proposed policy text.

### 4. F3 and F6: resolved

- **F3:** R11 (staff-account refusal removed) is now killed by `test_approval_never_erases_an_account_that_became_staff` (`FAILURES! Tests: 24 … Failures: 1`). That test asserts the users and account rows are byte-identical, the request stays `received`, and no completion audit is written.
- **F6:** `RETAINED` adds `transactional_notices` and `transactional_mail_deliveries` (correspondence) and `customer_data_exports` (security, reason `ledger_retained_contents_purged`), each with a count that matches behaviour:
  - notices are counted by `account_id`;
  - deliveries are counted by joining their notice;
  - exports are counted by joining their request.

  The test asserts `count = 2` for exports (one downloaded, one purged) and 0 for notices and deliveries. R24 is killed.

### 5. The merge

- **Census:** 307 entries; main `76fca3d7`'s 302 are an exact prefix, followed by the 5 CX race methods including the new one.
- **Selection:** `Proved 2653 selected MySQL-native tests in 262 files (of 9908 discovered tests in 734 files)`. Both count comments match (`final-verification.yml:151`, `.gitlab-ci.yml:113`).
- **Lockfile:** vendor matches it (`symfony/mime` v8.1.10). `composer audit --locked`: "No security vulnerability advisories found."
- **REP interaction:** none.
  - CX code never calls `RefundDisputeCase`, and REP's `load(forUpdate: false)` is used only by `SalesReport`.
  - REP's `CustomerConsentStatus` reads `users.email`. For an erased customer that is `erased+…@erased.invalid`, so consent can only show `granted_unverified`. Their account is inactive, so `CustomerSegments` never marks them marketing-eligible. That is a safe interaction.
- **Safeguards on 16687e9f:**
  - Pass: `test-phpunit-shards` 52, `test-ci-scope` 27, `test-workflow-cadence` 14, `test-focused-tests` 53, `test-database-receipts` 61, `test_production_kit` 50, `test_production_runtime_validator` 28.
  - Fail: `test_staging_capability_profile` (1 failure) and `test_staging_code_drift` (2 failures), both on the keys `REPORTING_CUSTOMER_SEGMENTS_ENABLED`, `REPORTING_CUSTOMER_EXPORT_ENABLED` and `REPORTING_MARKETING_SEGMENT_ENABLED`. These are missing from the staging templates and the `forge-deploy.sh` scrub. **They fail identically on main `76fca3d7`.** This is PR #178's regression; the lead's PR #181 fixes it.
  - Not run by me: `test_staging_protected_configuration` (the lead reports it as environmental in this container).
- **Focused SQLite on 16687e9f:**
  - `tests/Feature/CustomerComms` → `OK, but some tests were skipped! Tests: 99, Assertions: 788, Skipped: 5` (the 5 native race tests).
  - `TransactionalNotificationTest` + `tests/Feature/Reporting` + `ReportingCsvTest` + `ProtectedCatalogReportTest` → `OK (95 tests, 775 assertions)`.

## Commands

Scratch worktrees at `16687e9f` with vendor hard-linked. Direct PHPUnit as in the brief (`sqlite.sh`, `mysql.sh` under `/home/user/notes/cx-review/`).

- **Native, first server:** own `/opt/mysql-8.4.11/bin/mysqld`, port 3492, socket `/tmp/cxrv2.sock` (before the restart).
  - `CustomerDataRightsConcurrencyTest` + `CustomerDataRequestMigrationTest` + `LegalSiteSchemaMigrationTest` → `OK (12 tests, 334 assertions)`.
  - `--filter 'test_database_guards|test_approval_purges|test_approval_never_erases'` on `CustomerDataRightsTest` and `TransactionalMailTest` → `OK (4 tests, 50 assertions)`.
- **Native, after the restart:** own mysqld pid 20459, port 3493 (checked free), socket `/tmp/cxrv3.sock`, datadir `/home/user/notes/cx-review/mysql`.
  - The M21 run (above).
  - `ReviewerDeadlockTest` S1 and S2 on head (`deadlock-forupdate2.txt`) and on the M21 mutant (`deadlock-plain.txt`).
- **Raw SQL experiment (2026-10-30, port 3492):** the two-session `gapA.sql` / `gapB.sql` (FOR UPDATE) gave `ERROR 1213`; the `plainA` / `plainB` variants gave no deadlock.
- **SQLite:** the probe, mutants R11, R20, R22, R23 and R24 (`mutants2.py`), and the focused passes above.
- **Python:** `scripts/ci/test-*.py` and `tests/ops/*.py` as listed. `composer audit --locked`. `phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`, with the generated files deleted.

## Not tested

- The full SQLite or native suites, browser specs, Foundation CI, frontend (vitest and tsc) on the merged head, and npm audit.
- The F7 deadlock under real concurrent HTTP load, and the exhaustion of 3 retries end to end; the 500 outcome is inferred from the controller's catch list.
- The ops tests after PR #181.

## Cleanup

I stopped my mysqld (pid 20459) and removed its datadir and my scratch worktrees: under `/home/user/notes/cx-review` and the old scratchpad's `cx-review/main`, `mut` and `wt`. I removed no other lane's files or servers.
