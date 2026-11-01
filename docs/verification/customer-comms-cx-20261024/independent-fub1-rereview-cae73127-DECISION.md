# Independent re-review: FUB-1 fixes for review c86ceb0c

- Branch `harness/followups-rep-cx-20261031`. HEAD `cae73127b4d0bfacc6dc288ce113ae6f2dbbc4b7` changes docs only on top of the code at `6dc045f6`.
- Delta since my review: `38a93b08` (F1, F2), `435e44e8` (F4 cap test), `043a61a0` (classifier by driver code), `6dc045f6` (tier note), and the `cae73127` docs.
- Reviewer: independent Claude subagent. I worked only in scratch worktrees under `/home/user/notes/fub1-review/` and on my own mysqld. `/home/user/wt/fub1` is unchanged.
- Date: 2026-11-01. Progress log: `/home/user/notes/fub1-review/PROGRESS.md`.

## Verdict: APPROVE

F1 and F2 are fixed, and both of my probes now pass. The contention classifier is exact against real driver errors on MySQL 8.4 and SQLite. F3 is documented as a bound and deferred, and F4 is done. Every gate item reproduces.

Condition C1 from my previous record is met. I found no P0, P1 or P2. Four P3 notes follow; none blocks the merge.

## 1. Can the mapping hide a real defect?

`verifyAuthority()` maps exactly `CustomerAccessException`, `QuoteException` and `DeliveryException` to `NotificationException('authority_withdrawn')`. Everything else propagates.

- **Per-intent and permanent (correct):**
  - user email no longer verified, or the user became staff (`CustomerAccess::eligible`);
  - a claim that no longer verifies;
  - `PurchaseAccess::assertOrder` (`QuoteException` `ORDER_NOT_FOUND`);
  - delivery evidence missing or changed (`DeliveryException` `not_found`).
- **Global and possibly transient, under the same three classes:**
  - `CustomerAccessPolicy::requireEnabled()` (test customer accounts off);
  - `CustomerAccess::stamp()` (no app key);
  - `ActivationPolicy` (`DeliveryException` `unavailable` / `changed`).
- **In the queue command these are harmless.** The intent is counted as `skipped`, writes nothing and stays pending. A global cause shows up as every intent skipped with `authority_withdrawn`.
- **In `deliver()` they end the claimed delivery as `failed / authority_withdrawn`.** That email is then never sent, because there is one delivery per notice and no re-queue. It matches the existing behaviour for `TransactionalNotificationPolicy` switched off, which already maps to `authority_withdrawn` there. The direction is safe: nothing is sent and nothing is left `submitting`. See P3-1.
- **Catch-all in the command** (`QueueTransactionalMail.php:67-71`):
  - `queueOrderReady()` writes inside one `DB::transaction`, so an error before commit leaves nothing behind. The intent stays pending and is retried on the next run, bounded by the 4×limit budget (F3); there is no tight re-queue loop.
  - The operator signal is the output line `reasons=…,error=N` and a warning log carrying only the exception class. The test asserts both, and no personal data appears.
  - Exit code 0 with `error > 0` is acceptable for a test-only, default-off batch, but a scheduler would not alert on it (P3-2).
  - One residual partial state, pre-existing from CX: if the after-commit job dispatch itself fails, the delivery row is already committed as `queued` with no job. It is then excluded from the pending set and is not covered by the stuck listing, which only shows `submitting` (P3-3).

## 2. F2: fixed

In `deliver()`, after the `submitting` claim, the three domain refusals now become `NotificationException`, so the delivery moves `submitting → failed` with `authority_withdrawn`. The existing trigger allows exactly that branch. `intent()` throws only `NotificationException`.

Anything else, such as a database error or a defect, still propagates and leaves `submitting`. That is the case the stuck listing exists for, so it is correct.

My probe `ReviewerDeliverAccessTest`, run alone, now prints: `deliver returned {"state":"failed","reason":"authority_withdrawn"}; state=failed reason='authority_withdrawn' sent=0`.

The new test also asserts that nothing is sent, that the stuck listing shows `stuck=0`, and the audit trail `queued, submitting, failed`.

## 3. Classifier: correct

`concurrencyError()` walks the `getPrevious()` chain and reads `errorInfo[1]` (driver code) and `[0]` (SQLSTATE) from each `QueryException` or `PDOException`.
- **MySQL:** SQLSTATE `40001`, or codes 1213 / 1205.
- **SQLite:** codes 5 / 6.
- Message text is never read.

**Real errors** through Laravel connections (`classifier-real.txt`):

| Error | errorInfo | Contention? |
| --- | --- | --- |
| MySQL lock-wait timeout | `["HY000",1205]` | YES |
| MySQL deadlock (this session the victim) | `["40001",1213]` | YES |
| MySQL duplicate key | `["23000",1062]` | no |
| MySQL syntax error with the lock-wait phrase as a bound value | `["42000",1064]` | no |
| MySQL missing table | `["42S02",1146]` | no |
| SQLite busy (another connection holds `EXCLUSIVE`) | `["HY000",5]` | YES |
| SQLite duplicate primary key | `["23000",19]` | no |
| SQLite missing table with "database is locked" bound | `["HY000",1]` | no |

There are no false positives or negatives for the errors these paths can raise.

Note: the label "with deadlock phrase bound" on the MySQL duplicate-key line in `classifier-real.txt` is my own mislabel. That statement bound only ids; the phrase-bound check is the syntax-error line.

The 12-case unit test passes natively and on SQLite.

## 4. Tests and gates

**My earlier probes:**
- `ReviewerQueueCrashTest`: `run1=completed run2=completed valid_intent_queued=yes`, `OK (1 test, 1 assertion)`.
- `ReviewerDeliverAccessTest`: `OK (1 test, 1 assertion)`.
- When both run in one process, the second errors in `ActivationFixtures` ("Synthetic contract did not issue"). That is fixture state in my probes, not a product defect; each passes alone.

**Mutants (SQLite):**
- **G1** (mapping removed): killed, `ERRORS! Tests: 2 … Errors: 1, Failures: 2`.
- **G2** (command catch-all removed): killed.
- **K1** (Laravel's message detector restored): killed, `Tests: 15 … Failures: 9`.
- My own **K3** (1205 dropped): killed.
- My own **K4** (chain not walked): killed.
- My own **G3** (only `CustomerAccessException` mapped): **survives** (P3-4).

**Gate items:**
- Census: 310 entries; main `8bb1ab24`'s 309 are an exact prefix.
- Selection: `Proved 2673 selected MySQL-native tests in 264 files (of 9945 discovered tests in 737 files)`. Both count comments match.
- `tests/ops`: all 20 files OK. `test-database-receipts.py`: 61 OK.
- CI safeguards: shards 52, scope 27, cadence 14, focused 53, all OK.
- `composer audit --locked`: "No security vulnerability advisories found."

**Suites:**
- SQLite `tests/Feature/CustomerComms` + `tests/Unit/CustomerDataRightsContentionTest.php` → `OK, but some tests were skipped! Tests: 120, Assertions: 905, Skipped: 6`.
- SQLite `tests/Feature/Reporting` + `ReportingCsvTest` → `OK, but some tests were skipped! Tests: 43, Assertions: 466, Skipped: 1`.
- Native MySQL 8.4.11 → `OK (17 tests, 81 assertions)`. These were both new mail tests, the stuck listing (including `--max`), the staff and service contention tests, and the contention unit test.

## P3 notes

- **P3-1. One reason for two kinds of refusal.** `authority_withdrawn` covers both per-intent refusals and global configuration refusals (customer access policy off, activation policy unavailable, missing key). In `deliver()` a global cause permanently fails a delivery whose email will then never be sent. This matches the existing policy-off behaviour and is safe. A distinct reason, or leaving such deliveries `queued`, would make them recoverable.
- **P3-2. Exit code.** The queue command exits 0 even when some intents hit `error`. Consider a non-zero exit, or at least a documented alert on `error=`, once the command is scheduled.
- **P3-3. Orphaned `queued` rows.** If the after-commit job dispatch fails, the delivery stays `queued` with no job. This is pre-existing and rare with the database queue. Consider listing `queued` rows older than N minutes in the stuck command.
- **P3-4. Two mapped exceptions untested.** No test exercises the `QuoteException` or `DeliveryException` mapping, so G3 survives. Also, the driver for the classifier is read only from the top-level `QueryException`. A SQLite busy error wrapped in Laravel's `DeadlockException` would therefore be checked against the MySQL codes. That is unreachable here, because every data-rights command refuses to run inside an outer transaction.

## Commands

- **mysqld:** own `/opt/mysql-8.4.11/bin/mysqld`, pid 15173, port 3495 (checked free with `ss -ltnp`), socket `/tmp/fubrv.sock`, datadir `/home/user/notes/fub1-review/mysql`. It ran with `--skip-log-bin` and relaxed flushing; isolation stayed at the default `REPEATABLE-READ`.
- **Worktrees:** `wt` and `mut` at `cae73127`, plus a short-lived `probewt` for the separate probe runs. Vendor hard-linked.
- **Scripts:** `probe/classifier-real.php`, `mutants2.py`, `sqlite.sh`, `mysql.sh`.
- **Logs:** `classifier-real.txt`, `mutants2.log`, `re-sqlite-cx.log`, `re-sqlite-rep.log`, `re-native.log`, `re-ops-tests.txt`.

## Not tested

- The full suites, browser specs and Foundation CI.
- A real queue worker, real SMTP, or a dispatch failure after commit (P3-3, reasoned only).
- F3 at scale.

## Cleanup

My mysqld is stopped. Its datadir and the worktrees `wt`, `mut` and `probewt` are removed.
