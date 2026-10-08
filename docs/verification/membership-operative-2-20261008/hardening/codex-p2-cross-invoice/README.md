# Codex P2 on PR #54, `BillingLedger.php:173`: an unrelated invoice claim waited for another invoice's append lock

Status: complete for development (native MySQL focused files and the SQLite billing directory; see "Results" and "Not tested").

- Branch `harness/membership-operative-2`, worktree `/home/user/VA-Studio-member-ops2`, base head `2e08af42` (the read-interval fix
  `cbd5e5c5` + `4cb4cba8`, merged with main). Not committed by the lane agent: the integration owner commits these files.
- PHPUnit: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never <path>`,
  run from the worktree with `public/build` absent. Each result file ends with `rc=` written straight after PHPUnit returns.

## Finding (confirmed, reproduced red)

On MySQL, `BillingLedger::append()` takes `lockIdentity()` (`SELECT id … WHERE id = ? FOR UPDATE` on one invoice's identity row) for
its short append transaction. The invoices insert guard checked `NOT EXISTS (SELECT 1 FROM <invoices> WHERE invoice_ref_hash =
NEW.invoice_ref_hash OR source_invoice_hash = NEW.source_invoice_hash)`. A subquery inside an INSERT trigger is a locking read, and
MySQL plans the OR as a full table scan (`native/index-and-plan.txt`: `type ALL`, `key NULL`), so it reads, and waits for, an identity
row another invoice's append holds. A first claim of an unrelated invoice therefore waited for that lock (the original review
measured 8.67 s behind an 8 s lock: `../../independent-review/review-evidence/native/probes-native-2-cross-invoice.txt`).

## Fix

`BillingSchema` (migration 259000, changed in place; `migrate:fresh` already required, no hash or text pin elsewhere: the
owned-schema check compares the stored trigger with the text this class generates, on both drivers):

- Invoices insert guard: the OR clause is two index-specific checks, `NOT EXISTS (… WHERE invoice_ref_hash = NEW.invoice_ref_hash)
  AND NOT EXISTS (… WHERE source_invoice_hash = NEW.source_invoice_hash)`, logically identical. Both columns carry UNIQUE indexes
  (`…_invoices_u0`, `…_invoices_u1`), and each check plans as a const unique lookup (`native/index-and-plan.txt`). The same generated
  text serves SQLite and MySQL, so the definitions stay parallel.
- Scan of the other billing guards for the same shape:
  - subscriptions: `id = NEW.id`, `subscription_ref_hash = …` (unique), plan and account EXISTS on other tables: no OR. Unchanged.
  - observations: the two `retrieval_position IN (start, end)` / `retrieval_end_position IN (start, end)` checks (added by the
    read-interval fix `cbd5e5c5`) are a disjunction too; on the empty table MySQL planned them as a full index scan (`type index`).
    Split into four equalities, each a const unique lookup. The `(sequence = 1 AND zero seal) OR EXISTS (prior row)` clause is a
    boolean OR of two conditions, each a point lookup on the `(invoice_id, sequence)` unique index; not the shape, unchanged.
    `EXISTS invoices WHERE id = NEW.invoice_id` reads only the row's own invoice (which its append already locks).
  - events: `id`, `provider_event_ref_hash` (unique), hint `position`, `hint_position` (unique): no OR. Unchanged.
  - positions: `NEW.id < 1`, no subquery. Unchanged.
- `lockIdentity()` is kept unchanged. It is not load-bearing for correctness: the UNIQUE `(invoice_id, sequence)` index and the
  append retry serialize each chain. The follow-up review's storms showed no measurable deadlock difference with or without it
  (`../../independent-review/ADDENDUM-CODEX-P1B.md`, P1B-3). It is kept so same-invoice appenders queue, and it no longer
  delays unrelated invoices. An earlier version of this line said the storm data showed it reduces deadlocks; the data does
  not support that.

`observations()` under the lock: left as is. Inside the append transaction it proves the owned schema (information_schema reads)
and recomputes every seal of the chain before deciding the tail. Moving the schema proof before the lock would open a window in
which a DDL change could precede the append unnoticed; moving seal verification out would verify a chain that can grow before the
lock is taken, so the new rows would still need verifying under the lock, which changes the audit path rather than shortening it
safely. Neither is simple and clearly safe. After this fix only appends to the SAME invoice wait for that lock (verified by the new
native test, which also runs a complete first retrieval of an unrelated invoice while the lock is held), and those appends must
serialize anyway.

## Tests

- New `BillingNativeCrossInvoiceClaimTest` (native MySQL only; worker `tests/Support/membership-billing-cross-invoice-worker.php`):
  this process seeds invoice A, then holds exactly `lockIdentity()`'s `SELECT … FOR UPDATE` on A's identity row in an open
  transaction. A second process (its own connection, `innodb_lock_wait_timeout = 3`) claims unrelated invoice B
  (`BillingLedger::invoice()`) and then runs a complete first retrieval of unrelated invoice C (claim and append). Both must succeed,
  the claim in under 3 s, and both must finish while A is still locked (the parent holds for up to 20 s or until the worker is done).
  A's chain is unchanged and no credit is awarded.
- Census: `scripts/ci/database-sqlite-skips.json` gains that method (182 to 183 pairs).
- No SQLite-only test was added: SQLite has one writer and no row locks, so the behaviour cannot be observed there; the SQLite runs
  prove the split guards still refuse duplicates (`BillingSchemaPreparationTest::test_invoice_identity_is_unique_…`) and that every
  billing case still passes with the regenerated trigger text.

## Results

Native red at `2e08af42` (app unchanged; only the new test, worker and census entry added), MySQL 8.4.11 private on 127.0.0.1:3923:

- `native/red-cross-invoice-at-2e08af42.txt`: 1 test, 8 assertions, 1 failure, rc=1. Observation: claim of B `refused
  invoice_identity` after 4.26 s (lock wait timeout, then the re-read found no row), first retrieval of C `refused
  invoice_identity` after 5.55 s, both while A was locked.
- `native/red-cross-invoice-at-2e08af42-repeat.txt`: an accidental second red run (the source edit had not applied yet), same
  result (4.28 s / 5.66 s, rc=1). Kept as a repeat.

Green (`2e08af42` plus this change):

- After the invoices split only (intermediate, before the IN-list split): `claimed` in 1.46 s, first retrieval of C saved
  (sequence 1) in 3.66 s, done while A was locked, OK 1 test, 15 assertions, rc=0 (output file replaced by the final run below).
- Final source, MySQL (`native/ledger.txt`):

  - `native/mysql-BillingNativeCrossInvoiceClaimTest.txt` (+ `.xml`): OK, 1 test, 15 assertions, rc=0. Observation: claim of B
    `claimed` in 1.51 s, first retrieval of C saved (sequence 1) in 4.14 s with no single wait reaching the 3 s lock timeout, both
    done while A was locked (held 5.66 s, released when the worker finished).
  - `native/mysql-BillingSchemaPreparationTest.txt`: OK, 13 tests, 77 assertions, rc=0 (the regenerated MySQL triggers pass the
    owned-schema check).
  - `native/mysql-BillingNativeDedupRaceTest.txt`: OK, 1 test, 15 assertions, rc=0.
  - `native/mysql-BillingNativeStaleRetrievalRaceTest.txt`: OK, 2 tests, 27 assertions, rc=0.
  - `native/mysql-BillingNativeIntervalRetrievalRaceTest.txt`: OK, 2 tests, 28 assertions, rc=0.
  - Instance lifecycle: `native/private-instance-lifecycle.txt` (started 20:17:59Z; `mysqladmin shutdown` 21:03:23Z; pid 25012
    exited, confirmed through `/proc` and `ps`, before the datadir was deleted). Another agent's mysqld (port 3794) was not touched.

- SQLite: `sqlite-BillingSchemaPreparationTest.txt` OK, 13 tests, 77 assertions, rc=0; `sqlite-ProductionMembershipBilling.txt`
  (+ `.xml`) OK, 180 tests, 886 assertions, 5 skipped (the five native-only methods), rc=0.
- `pint.txt`: `pint --test` on the three changed or new PHP files, passed, rc=0.
- `receipts-self-test.txt`: `python3 -I scripts/ci/test-database-receipts.py`, 34 tests OK, rc=0.
- `native/index-and-plan.txt`: unique indexes on both invoice hash columns; EXPLAIN of each split check (const), of the old OR
  (`ALL`), of the observations IN lists (`index`) and of their equality replacements (const), on a scratch database migrated with
  `php artisan migrate --force`.

## Not tested / for Sean

- The full billing directory on MySQL (focused files only), Foundation CI, the review's 4-worker storm re-run.
- Plans were inspected on an empty table; MySQL may choose differently on large tables, but an equality on a unique index is a
  point lookup at any size, which is why the guards no longer use OR or IN.
- Same-invoice waits remain by design (the append lock serializes one invoice's chain), and `observations()` still runs the schema
  proof and the chain's seal verification while holding it (see "Fix").
