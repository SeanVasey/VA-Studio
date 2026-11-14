# Independent review: lane FUB-1 (REP and CX follow-up batch)

- Branch `harness/followups-rep-cx-20261031`, HEAD `c86ceb0c014b87433344d0625e0711725b789368`. Base: main `8bb1ab24`.
- Diff: `git diff 8bb1ab24...c86ceb0c`, 24 files, +670/−55.
- Reviewer: independent Claude subagent. I worked only in scratch worktrees under `/home/user/notes/fub1-review/` and on my own mysqld. `/home/user/wt/fub1` is unchanged.
- Date: 2026-11-01. Progress log: `/home/user/notes/fub1-review/PROGRESS.md`.

## Verdict: APPROVE WITH CONDITIONS

Every listed item is implemented correctly and covered by tests that fail without it. The money paths are consistent, and every gate item reproduces.

There is one P2, which is the adjacent issue the author flagged, in a broader form than flagged:

- **Condition C1:** fix F1 before order-ready email is enabled on any host. It can go in this batch or the next.

F1 is pre-existing (CX #183) and not introduced here, but it defeats this batch's own claim that refused intents never block newer ones. There are also three P3s.

## Findings

### F1: P2. A non-notification refusal on one pending intent crashes every queue run and blocks all newer intents

**Where:** `app/Console/Commands/QueueTransactionalMail.php:58-66`. The loop catches only `NotificationException`.

**Cause:** `TransactionalMailAdapter::queueOrderReady()` calls `TestTransactionalNotifications::status()`. Its `authority()` path (`TestTransactionalNotifications.php:338-360`) throws other domain exceptions:

- `CustomerAccessException` from `CustomerAccess::lock()`, when the user is no longer eligible: `email_verified_at` cleared, `is_admin` set, or a purchase claim that no longer verifies;
- `QuoteException` from `PurchaseAccess::assertOrder()`;
- `DeliveryException` from `DeliveryAccessEvidence` or `ActivationPolicy`.

None of these change the account's `access_version`, so the new superseded filter does not exclude the intent.

**Failure path:**
1. The oldest pending intent's customer loses email verification.
2. Every run of `vasey:transactional-mail-queue` throws `CustomerAccessException` at that intent.
3. The run aborts, and the next run starts from the same oldest intent.
4. Every newer valid intent is starved indefinitely. No output line is printed.

**Reproduced** with probe `ReviewerQueueCrashTest` (SQLite, scratch only): `PROBE run1=App\Domain\Customers\CustomerAccessException run2=App\Domain\Customers\CustomerAccessException valid_intent_queued=no`.

**Severity:** the author judged only a "non-notification database error" here. That case also crashes the run, but it is mostly transient. The domain refusals above are per-intent and can be permanent, so they reproduce exactly the starvation the batch set out to remove. The feature is default-off and limited to test orders, and no mail is sent wrongly, so this is P2 rather than P1.

**Fix:**
- In `TransactionalMailAdapter`, map the domain refusals from `status()` (`CustomerAccessException`, `QuoteException`, `DeliveryException`) to `NotificationException('authority_withdrawn')`. Do this in both `queueOrderReady()` and `deliver()`; see F2.
- In the command, also count an unexpected `Throwable` per intent as `skipped` with a fixed reason (logging only the exception class), so one bad row can't stop the batch.
- Add a test with an intent whose user lost email verification, ahead of a valid one.

### F2: P3. The same refusal leaves a claimed delivery in `submitting`, and the new stuck listing then misreports it

**Where:** `app/Domain/Notifications/Mail/TransactionalMailAdapter.php`, `deliver()`. The `try` around `status()` and `intent()` catches only `NotificationException`.

**Failure path:**
1. A delivery is queued.
2. The customer then loses email verification.
3. The job claims the delivery (`queued → submitting`), and `status()` throws `CustomerAccessException`.
4. The job fails (it has one try), and the row stays `submitting` for good.
5. `vasey:transactional-mail-stuck` will list it with the advice to check the provider's logs, although the transport was never reached.

**Reproduced** with probe `ReviewerDeliverAccessTest`: `PROBE deliver threw App\Domain\Customers\CustomerAccessException; state=submitting reason=NULL sent=0`.

This is the safe direction: nothing is sent, and the row is never resent. It is pre-existing from CX #183. The F1 mapping fixes it, after which the row becomes `failed/authority_withdrawn`.

### F3: P3. The 4×limit scan always restarts at the oldest intent

**Where:** `QueueTransactionalMail.php:47-50`. `$after = 0` on every run, with a budget of `limit * SCAN_FACTOR`.

Refusals that are neither superseded nor transient stay in the pending set for good. Examples are a changed recipient email (the author's own test calls it "reversible") and a changed intent policy version. Once at least 4×limit of these accumulate (200 at the default limit), every run spends its whole budget on them, and newer intents are never examined.

So `docs/transactional-mail.md:11` ("does not stop the run from reaching newer intents") holds only below that bound. Fix: state the bound in the docs, or start each run after a stored cursor, or alternate between oldest-first and newest-first.

### F4: P3. Smaller notes

- **Classifier precision (N3).** `CustomerDataRights::concurrencyError()` uses Laravel's `ConcurrencyErrorDetector`:
  - It matches SQLSTATE `40001` only for a raw `PDOException`.
  - Otherwise it matches message substrings: MySQL 1213 "Deadlock found…" and 1205 "Lock wait timeout exceeded; try restarting transaction", and SQLite "database is locked" / "database table is locked".
  - A `QueryException` message includes the SQL with its bindings. In theory, a non-contention error on a statement whose bound text contains such a phrase would be classified as contention. One example is a staff rejection reason that reads "deadlock detected".
  - The outcome would only be a rolled-back "try again"; nothing is hidden permanently. The same detector drives Laravel's own transaction retries, so I accept it.
- **Stuck-listing cap untested.** `ListStuckTransactionalMail::MAX_LISTED` (100) and the `more=` line have no test. Mutant M13 (cap raised to 100000) survives.
- **Tier totals can be newer than order-level totals.** With the per-order snapshot, the tier refunded and unattributed totals can now exceed the order-level refunded total on the same page or CSV, when a refund lands between the two reads. This is never negative and always counted. Before, the sum always equalled the older aggregate, sometimes through a negative remainder. Optionally note this on the page.

## Focus answers

### 1. REP money correctness

- **Right total.** `$case->latestObserved` is the latest `outcome = 'observed'` observation (`RefundDisputeCase::load`). That is the same selection as `ReportingQueries::latestObserved` in the aggregate. Its `succeeded_refund_minor` is computed only for accepted observations, from the evaluated snapshot's succeeded refunds (`RefundDisputeLedger.php:101`, `RefundDisputeState`), so it is status-aware. `refundedByLine()` uses the statuses of that same observation's snapshot. Both figures come from one REPEATABLE READ snapshot: one `DB::transaction` with plain reads, on the session default.
- **Unreadable path.** An unreadable or inconsistent case (`array_sum($byLine) > $succeeded`) adds the aggregate's `succeeded` to the unattributed amount and counts the order. The refund is never dropped, and nothing is attributed to a tier for that order. The `> 0` remainder cannot hide a negative, because the guard rules one out; mutant M3 is equivalent.
- **Page and CSV agree.** Both render the same `testTiers()` array (`SalesReport::csvRows` lines 120-134). The view now shows the unattributed, unverified and unreadable lines whenever the tier section is available, including with no rows. That matches what the CSV already emitted.
- **queue_stalled** matches the framework exactly: `DatabaseQueue::isReservedButExpired` uses `reserved_at <= now − retry_after`, and the connector defaults to 60.
- **CSV:** the whitespace set is now `" \t\n\r\0\x0B\f"`.

### 2. Superseded classification

- **Permanent.** The `customer_accounts` update guard (`2026_10_06_000040…:47`) requires `NEW.access_version = OLD.access_version + 1` and a change of `active` on every update, so the version only increases. Notice verification requires equality (`TestTransactionalNotifications.php:318, 392`), and the notice insert guard requires equality at insert. An account version above the notice's is therefore permanent; reactivation bumps it again.
- **No valid intent excluded.** The pending filter keeps `a.id IS NULL OR a.access_version <= n.access_version`. Mutant M8 (`<`) is killed.
- **Bounded:** the scan examines at most 4×limit intents, in pages; it is not fair across runs (F3).
- **Transient refusals stay pending:** nothing is written, and the test covers the policy switched off.
- **`--limit`:** no scheduler entry, ops script or caller uses it outside the tests. The docs and CHANGELOG are updated.

### 3. N3 (staff data-rights actions)

Only `concurrencyError()` errors become the "try again" notification, with a warning log that holds only the exception class. Every other `QueryException` is rethrown. The test asserts that an integrity error propagates, and mutant M11 (swallow everything) is killed. Native: `test_database_contention…` passes on MySQL.

### 4. Stuck-deliveries command

- It is read-only: only `count()` and `select`.
- `--minutes` is bounded to 1..10080.
- At most 100 rows are listed, then `more=N`.
- It prints only the delivery id, the notice public id and `started_at` UTC: no recipient or content.
- Mutant M12 (threshold ignored) is killed. The cap is untested (F4).

### 5. Tests

**Mutants, all mine:**

| Mutant | Change | Result |
| --- | --- | --- |
| M1 | aggregate total instead of the snapshot total | killed |
| M2 | inconsistent-case guard removed | killed |
| M3 | negative remainder allowed | survives; equivalent, the guard prevents it |
| M4 | view hides warnings when there are no rows | killed |
| M5 | stalled boundary `<` instead of `<=` | killed |
| M6 | retry window hardcoded to 60 | killed |
| M7 | CSV whitespace set reverted | killed |
| M8 | equal version treated as superseded | killed |
| M9 | superseded intents not excluded | killed |
| M10 | `SCAN_FACTOR = 1` | killed |
| M11 | staff action swallows every `QueryException` | killed |
| M12 | stuck threshold ignored | killed |
| M13 | stuck cap raised | survives (F4) |
| M14 | per-order snapshot transaction removed (native) | killed by `test_native_the_order_snapshot_does_not_see_an_allocation_committed_while_it_reads` |

**Trigger-dropping fixture.** `test_a_case_that_attributes_more_than_its_succeeded_total_is_unreadable_not_a_negative_figure` drops `refund_dispute_observations_update` inside a `FinalizationDatabaseMigrations` test. It only runs after `TestCase` admits the connection through `DisposableTestDatabase`: SQLite `:memory:`, or MySQL whose database name starts with `vaseyaudio_` and carries the local marker or the CI marker. The schema is rebuilt for each test, so the fixture can only ever touch a disposable database.

**Gate items:**
- Census: 310 entries; main's 309 are an exact prefix, followed by the native snapshot test.
- Selection: `Proved 2671 selected MySQL-native tests in 264 files (of 9931 discovered tests in 736 files)`. Both count comments match.
- `tests/ops`: all 20 files OK, plus `test-database-receipts.py` 61 OK.
- CI safeguards: shards 52, scope 27, cadence 14, focused 53, all OK.
- `composer audit --locked`: "No security vulnerability advisories found."

### 6. The adjacent issue the author flagged

It is real, and wider than flagged: F1 above, P2.

## Commands and results

- **mysqld:** own `/opt/mysql-8.4.11/bin/mysqld`, pid 20127, port 3495 (checked free with `ss -ltnp`), socket `/tmp/fubrv.sock`, datadir `/home/user/notes/fub1-review/mysql`. It ran with `--skip-log-bin`, relaxed flushing and doublewrite off. Those settings affect durability only; isolation stayed at the default `REPEATABLE-READ`.
- **PHPUnit:** direct, through `mysql.sh` and `sqlite.sh`.
- **Native:**
  - `SalesReportRefundSnapshotTest` + `OperationalHealthTest` → `OK (6 tests, 107 assertions)`, including the native snapshot test and `queue_stalled` on MySQL.
  - The `TransactionalMailTest` queue, refused-intents, stuck-listing and database-guard tests, plus the `CustomerDataRequestAdminTest` contention test → `OK (5 tests, 78 assertions)`.
  - M14 → `FAILURES! Tests: 1, Assertions: 3, Failures: 1`.
- **SQLite:**
  - `tests/Feature/Reporting` + `tests/Unit/ReportingCsvTest.php` → `OK, but some tests were skipped! Tests: 43, Assertions: 466, Skipped: 1`.
  - `tests/Feature/CustomerComms` → `OK, but some tests were skipped! Tests: 106, Assertions: 870, Skipped: 6`.
  - Mutants M1–M13: `mutants.log`.
  - Probes: `probe/ReviewerQueueCrashTest.php` and `probe/ReviewerDeliverAccessTest.php`, both failing as described in F1 and F2.
- **Selection, census, audit, ops:** as listed in item 5 (`ops-tests.txt`).

## Not tested

- The full SQLite and native suites, browser specs and Foundation CI.
- The Filament sales-report page in a browser; only the Livewire and view tests ran.
- A real queue worker, or real SMTP.
- F3 starvation at scale (reasoned, not run).
- The non-contention database error path in `queueOrderReady()`; it is covered by reasoning and F1's probe.

## Cleanup

My mysqld is stopped. Its datadir and the scratch worktrees `wt` and `mut` are removed. Logs, probes and this record remain.

---

## Resolution (author, 2026-11-01)

All four findings were addressed on the same branch before the PR, after `c86ceb0c`. Every fix has a test that fails without it.

| Finding | Resolution | Commit | Test / mutant |
| --- | --- | --- | --- |
| F1 (P2): a `CustomerAccessException`, `QuoteException` or `DeliveryException` from intent re-verification crashed every queue run at the same oldest intent | Fixed. `TransactionalMailAdapter::verifyAuthority()` maps exactly those three to `NotificationException('authority_withdrawn')`, in `queueOrderReady()` and `deliver()`; nothing else is relabelled. The command also counts any other per-intent error as `skipped` with reason `error` and logs only the exception class, then keeps going. | `38a93b08` | `TransactionalMailTest::test_a_customer_or_purchase_refusal_on_an_older_intent_is_skipped_and_never_stops_the_batch` (the reviewer's probe: an unverified-email intent and an intent with an injected database error ahead of a valid one; output `queued=1 skipped=2 reasons=authority_withdrawn=1,error=1 superseded=0`). Mutants: mapping removed (killed); command catch-all removed (killed). |
| F2 (P3): the same refusal left a claimed delivery in `submitting` | Fixed by the F1 mapping. `deliver()` now ends in the guarded `submitting → failed` transition with reason `authority_withdrawn`, which the update trigger already allows from `submitting` (no migration change). | `38a93b08` | `TransactionalMailTest::test_a_customer_refusal_after_the_claim_fails_the_delivery_instead_of_leaving_it_submitting` (the reviewer's second probe; nothing sent, `stuck=0`, audit `queued`, `submitting`, `failed`). Killed by the mapping-removed mutant. |
| F3 (P3): the 4 × limit scan restarts at the oldest intent each run | Option (b). No existing storage records a per-command cursor, and a cache cursor could skip intents after a cache loss, so the bound is stated in `docs/transactional-mail.md` and the CHANGELOG, and a stored cursor stays a deferred follow-up in the ledger. | `38a93b08` (docs), ledger | n/a |
| F4 (P3): stuck-listing cap untested (M13) | Fixed. `--max` (1 to 100, default 100) lowers the cap; the test lists one of two stuck deliveries with `more=1` and refuses `--max=101`. | `435e44e8` | M13 (cap raised to 100000) killed; a mutant that prints `more=` against a fixed 100 is killed. |
| F4 (P3): contention classified by message text | Fixed. `CustomerDataRights::concurrencyError()` reads `errorInfo` along the exception chain: MySQL 1213, 1205 or SQLSTATE 40001; on a SQLite connection 5 (busy) or 6 (locked). The existing contention fixtures now carry driver codes, as real PDO errors do. | `043a61a0` | `CustomerDataRightsContentionTest` (12 cases, including bound values and error text naming a deadlock or lock wait); the staff test's non-contention error names a lock wait in its text. Mutants: Laravel's message detector restored (killed by both); SQLite code 19 accepted (killed). |
| F4 (P3): tier totals can be newer than order totals | Documented in `docs/staff-reporting.md`. | `6dc045f6` | n/a |

Results after the fixes are in the CX packet's "Follow-up batch FUB-1" section ("Review fixes").
