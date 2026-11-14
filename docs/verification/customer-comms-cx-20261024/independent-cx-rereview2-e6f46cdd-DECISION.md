# Independent re-review 2: lane CX fix for F7, at e6f46cdd

- Branch `harness/customer-comms-cx-20261024`. HEAD `e6f46cdda8e4780fa1fdc0a14473a77a2cce51d4` changes docs only on top of the tested code at `a9123898`.
- Main `a926f98b` (PR #181) is merged in at `27e14ee6`.
- Code delta since my last record (`16687e9f..a9123898`):
  - `CustomerDataRights.php`, `CustomerDataRequest` model casts, `CustomerDataRightsController.php`;
  - amended migration `2026_10_24_268200`;
  - `CustomerDataRightsConcurrencyTest`, `CustomerDataRightsTest`, the race worker.
- Reviewer: independent Claude subagent. I worked only in my own scratch worktrees under `/home/user/notes/cx-review/` and on my own mysqld. `/home/user/wt/cx` is unchanged (clean, HEAD `e6f46cdd`).
- Date: 2026-10-31. Progress log: `/home/user/notes/cx-review/PROGRESS.md`.

## Verdict: APPROVE

F7 is resolved. The request-level deadlock and the event-level cross-customer wait are both gone through the application's real paths, and READ COMMITTED is confirmed on the live transactions.

The triggers enforce the counter exactly on MySQL and SQLite. The amended migration is forward-only and safe on a populated database. Every gate item reproduces. I found no new P0, P1 or P2. Four P3 notes follow; none blocks the merge.

## 1. My S1 and S2 probes against the new head

The probe is `probe/ReviewerDeadlockTest.php` with `probe/reviewer-deadlock-worker.php`, a scratch file, not committed. Two worker processes run the real `CustomerDataRights` operations. A query listener pauses each one after a chosen query (first attempt only), and both are then released together. While they are paused, the probe records:

- `performance_schema.data_locks` on both data-request tables;
- `data_lock_waits` if the second worker stalls;
- each worker's live transaction isolation, from `events_transactions_current`;
- the InnoDB `lock_deadlocks` counter.

Raw results are in `probe-head.txt`. Pause points were adapted to the new code: the open check no longer says `FOR UPDATE`, and there is no MAX query.

| Scenario | Before (16687e9f) | Now (e6f46cdd) |
| --- | --- | --- |
| **S1:** two customers request deletion; both paused right after the open-slot check | Both held `X` on the `supremum` of `customer_data_requests_open_unique`. **1 deadlock.** | Isolation `READ COMMITTED` for both. No row locks held on either table at the pause. No wait, **0 deadlocks**, both saved, 0 duplicate sequences. |
| **S2a:** approval of X, paused at its final audit insert (holding everything), against a deletion by Y; Y's account created before X's; Y paused after its event insert | Not run in this form. | Both `READ COMMITTED`. Only `X,REC_NOT_GAP` record locks on each transaction's own request rows (approval: rows 1 and 2; Y: row 3). No wait, 0 deadlocks, both saved. |
| **S2b:** as S2a, with Y created after X, so Y's rows land at the index tails next to X's newest entries | Y's event MAX waited for the approval's X lock on record `2, 1, 3` of the `(request_id, sequence)` index. | Both `READ COMMITTED`. Own-row `REC_NOT_GAP` locks only. No wait, 0 deadlocks, both saved. |

Result: `head probes S1/S2a/S2b: OK (3 tests, 3 assertions)`. All three were run natively.

## 2. Triggers and migration

**Static reading of the guards.**
- Insert requires `last_event_sequence = 0`.
- An event insert requires `NEW.sequence = r.last_event_sequence + 1` on its parent. The unique `(request_id, sequence)` key refuses a second event at the same number.
- A request update must be exactly one of:
  - **(i)** the counter moves by +1 to an event that already exists, with every other column unchanged (including `version`, `updated_at` and the resolution columns);
  - **(ii)** the existing single open-to-terminal transition, with the counter unchanged.
- Delete stays refused.

**Native adversarial SQL** on a populated database migrated from main to the head (`adversarial.txt`). Refused, each with `ERROR 1644` or a unique violation:
- an insert with counter 1;
- a second open deletion request for the same account (`1062` on `customer_data_requests_open_unique`);
- event 2 before event 1;
- the counter advanced without its event;
- a duplicate event 1;
- event 2 before the counter moves;
- counter +2;
- counter +1 together with a state change;
- counter +1 together with an `updated_at` change;
- the counter moved backwards;
- a no-op update;
- a transition that also moves the counter;
- reopening a terminal request;
- deleting an event.

Accepted: the legitimate sequence of event 1 → counter 1 → terminal transition → event 2 → counter 2 after the terminal transition, and a new open request after the old one resolved. The final rows are exact.

**SQLite:** the extended `test_database_guards_keep_requests_forward_only_events_append_only_and_exports_purge_only` passes in the head's SQLite run. It has 7 new counter and sequence attempts.

**MySQL guard tests on the head:** `head guards+migration: OK (3 tests, 48 assertions)`. These are `test_database_guards…`, `test_the_unique_open_slot…` and `test_approval_purges…`.

**Migration safety.** On `vaseyaudio_cxmig`:
1. `migrate --force` at main `a926f98b` → exit 0.
2. Populated through the application: 4 users, 3 customer accounts, site releases v1 and v2, and a publication.
3. `migrate --force` at the head → the three CX migrations DONE.
4. `CHECKSUM TABLE` and `CREATE_TIME` are identical for all 209 pre-existing tables. The only additions are the 4 new tables.
5. `migrate:rollback --step=3` → `268300 … FAIL LogicException: … forward-only`, with no table changed.

`268200` is not on main (`git show a926f98b:…268200…` fails), so amending it is acceptable.

The column is `last_event_sequence int unsigned NOT NULL` with no default, on a table that the migration itself creates, so there is no rewrite.

Note: any developer database that ran the old `268200` would lack the column. Such a database needs `migrate:fresh`; no shared environment ran it.

## 3. Same-account serialization without FOR UPDATE on the open-slot checks

- **Inserts:** `open()` is the only path that inserts a request. It is reached only from `requestExport` and `requestDeletion`, after `lockCustomer()`, which takes the customer `users` row FOR UPDATE, then the account. Two writers for the same account therefore serialize on that row.
- **Visibility:** under READ COMMITTED, the second writer's plain open-slot read is a fresh statement read taken after it got the lock, so it sees the first writer's committed request. It then returns the replay (deletion) or a 409 (export).
- **Backstop:** the unique index `customer_data_requests_open_unique (customer_account_id, kind, open_slot)` exists on both engines. NULL slots for resolved requests don't collide, and two `1` slots do. Shown natively above (`1062`), and on SQLite by the guard test and `test_the_unique_open_slot_index_refuses_a_second_open_request_cleanly`, which maps the violation to 409 `deletion_pending` with nothing written.
- **Other writers:** prune and staff paths only update existing requests, and they lock them by primary key.

## 4. READ COMMITTED and retries

**Live isolation.** `transaction()` issues `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before every attempt. `outside()` guarantees no outer transaction, so the statement is legal and applies to the `START TRANSACTION` that follows immediately. Every paused worker showed `READ COMMITTED` in `events_transactions_current`, although the worker sets the session default to `REPEATABLE READ`.

**Reads the approval and purge rely on.**
- `lockForStaff` takes the user, account and request locks by primary key and checks state and version on the locked (current) row.
- `openExports()` makes a plain discovery read after the customer `users` lock, then locks each request by primary key and re-checks `open_slot = 1`. Under READ COMMITTED the discovery read sees every committed export. Any same-account export creator needs the user lock and so cannot be in flight. A concurrent prune (which takes no user lock) is handled by the primary-key lock and the re-check.
- **READ COMMITTED is load-bearing for F1:**
  - Mutant **C**, which drops the `SET TRANSACTION`, is **killed** by `test_an_export_committed_while_an_approval_waits_is_purged_by_that_approval` (`pending_exports` came back `'none'`, expected `'purged'`). The stale REPEATABLE READ snapshot misses the export, exactly as the comment says.
  - The same mutant passes the new no-wait race (`OK (1 test, 247 assertions)`). So READ COMMITTED protects correctness, and removing the range locks is what removes the waits.

**Retries.** Each attempt is one `DB::transaction` that is rolled back whole on a deadlock or lock-wait timeout. `ConcurrencyErrorDetector` covers both messages; InnoDB's statement-only rollback on a timeout is followed by Laravel's full rollback. Inside the callbacks there are only database writes (audit, events, counters, users and sessions updates), `Crypt`, `Hash::make` and random tokens. No mail, queue dispatch, file write or after-commit hook runs in any data-rights command. The token returned is the one generated in the committed attempt. The tests show 2 deadlocks then success, and 3 deadlocks giving up with nothing written. Prune now also gets 3 attempts, which resolves my earlier note.

## 5. 409 and 503 handling

- **409:** a unique violation in `open()` maps to `export_pending` or `deletion_pending`. The transaction rolls back, and the customer gets the existing 409 message.
- **503:** a deadlock or lock-wait timeout that outlives the retries maps to `CUSTOMER_DATA_REQUEST_UNAVAILABLE` / `try_again` with `Retry-After: 5` and `no-store, private`.
- **Other database errors** are not relabelled: they go to the existing generic customer-privacy 503. Tested with a substitute service.
- A download that hits the retry limit returns 503 with the export still intact, because it was rolled back, so the customer can retry with the same token.

## 6. Is the new race test meaningful? (native mutants, each in its own worktree)

| Mutant | Change | Test | Result |
| --- | --- | --- | --- |
| A | sequence from the locked `MAX() … FOR UPDATE` again (counter still advanced) | new race | **killed**: "A new request waited for the approval in flight." |
| B | `lockForUpdate()` restored on both open-slot checks | new race | survives (`OK`) |
| C | no `SET TRANSACTION … READ COMMITTED` | F1 race | **killed**: `'none'` instead of `'purged'` |
| C | (same) | new race | survives (`OK`): no range locking read is left |
| AC | A + C | new race | **killed**: the new request waited |
| BC | B + C (the pre-fix open checks under REPEATABLE READ) | new race | **killed**: the `data_lock_waits` assertion caught Z (connection 1371) waiting on Y (1370) |

Mutant B survives because under READ COMMITTED a `FOR UPDATE` on an empty range takes no gap lock. Restoring it alone is harmless while READ COMMITTED holds, and BC shows the test catches it as soon as the isolation regresses. The race test asserts no `data_lock_waits` for the three connections, no InnoDB deadlock, exact gap-free sequences equal to each counter, and both requests saved while the approval is still holding its locks. It is meaningful.

## 7. Gate items

- **Census:** 308 entries; main `a926f98b`'s 302 are an exact prefix, followed by 6 CX race methods.
- **Selection:** `Proved 2657 selected MySQL-native tests in 262 files (of 9912 discovered tests in 734 files)`. Both count comments match (`final-verification.yml:151`, `.gitlab-ci.yml:113`).
- **tests/ops:** all 20 files OK, including `test_staging_capability_profile` 18, `test_staging_code_drift` 10 and `test_staging_protected_configuration` 3. `test-database-receipts.py` 61 OK.
- **Python safeguards:** `test-phpunit-shards` 52, `test-ci-scope` 27, `test-workflow-cadence` 14, `test-focused-tests` 53, all OK.
- **Composer:** `composer audit --locked` reports "No security vulnerability advisories found."
- **Native on the head:**
  - the new race `OK (1 test, 402 assertions)`;
  - the other five races `OK (5 tests, 298 assertions)`;
  - guards, unique open slot and purge `OK (3 tests, 48 assertions)`;
  - `CustomerDataRequestMigrationTest` `OK (2 tests, 10 assertions)`.
- **SQLite on the head:** `tests/Feature/CustomerComms` → `OK, but some tests were skipped! Tests: 106, Assertions: 817, Skipped: 9`. The skips are the 6 native race tests plus my 3 native-only probe tests, which were present only in the scratch worktree.

## P3 notes (no action required before merge)

- **N1. Export document consistency.** The export document is now built at READ COMMITTED, so its sections (orders, grants, consent, inquiries) can reflect different commit points if the customer's own order changes during the build. Under REPEATABLE READ it was one snapshot. It is informational content for the customer only; order graphs are immutable.
- **N2. Wait time on lock-wait timeouts.** Retrying on a lock-wait timeout can hold an HTTP worker for up to 3 × `innodb_lock_wait_timeout` (50 s by default) before the 503. Consider a lower session `innodb_lock_wait_timeout` for these commands.
- **N3. Staff path.** The Filament staff actions (`CustomerDataRequestResource::run`) don't catch a `QueryException` that outlives the retries, so staff would see a generic error page rather than a notification.
- **N4. Guarding the isolation level.** F1 completeness now depends on `transaction()` applying READ COMMITTED (mutant C). A future call path that wraps these closures in a plain `DB::transaction` would silently reintroduce the stale-snapshot miss. A short comment at `openExports()` naming that dependency, or a debug assertion of the live isolation, would guard it. The F1 race test does catch it today.

## Environment note

The box was shared with the F9 and PCC lanes' heavy runs: load average around 8 on 4 CPUs, and about 25 minutes per native test at first. I stopped only my own processes, by explicit PID, and restarted my own mysqld with `--skip-log-bin --innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=OFF --innodb-flush-method=nosync`. These settings change durability only. The isolation default stayed `REPEATABLE-READ`, and locking semantics are unchanged. The runs before the restart were discarded.

## Commands

- **mysqld:** own `/opt/mysql-8.4.11/bin/mysqld`, port 3494 (checked free with `ss -ltnp`), socket `/tmp/cxrv4.sock`, datadir `/home/user/notes/cx-review/mysql`. First pid 4537, restarted as pid 9243.
- **PHPUnit:** direct, through `mysql.sh` / `sqlite.sh` (the brief's environment), with `VA_LOCAL_DISPOSABLE_MYSQL` set to a per-run `vaseyaudio_cx*` database.
- **Worktrees:** `wt` (head), `mA`, `mB`, `mC`, `mAC`, `mBC` (mutants applied by `apply-mutant.py`), and `main` (`a926f98b`).
- **Logs:** `head-race-new.log`, `head-race-rest.log`, `head-guards.log`, `head-migtest.log`, `head-sqlite.log`, `probe-head.log` / `probe-head.txt`, `mut-*.log`, `mig-*.log`, `checksum-*.txt`, `adversarial.txt`, `ops-tests.txt`.

## Not tested

- The full SQLite and native suites, browser specs, Foundation CI, and frontend on the merged head.
- Contention under real HTTP load, and Filament staff actions in a browser.

## Cleanup

My mysqld is stopped. Its datadir and all my scratch worktrees under `/home/user/notes/cx-review/` are removed. Logs, probe sources and this record remain.
