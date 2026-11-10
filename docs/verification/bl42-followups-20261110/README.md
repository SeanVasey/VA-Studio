# BL-42 follow-up batch (review of PR #297)

Branch `claude/bl42-followups-20261110`, from main `b5c980a9`. This is the deferred batch from the independent review of PR #297 (BL-42, refund and dispute lifecycle on the production tax checkout chain, FP-068), under the freeze-and-batch rule (merge at the exact SHA, defer P2s and P3s to one per-lane follow-up). Focused SQLite runs only plus one native MySQL run; no Foundation CI, so none of this is full acceptance.

| Finding | Status | What |
| --- | --- | --- |
| P2-1 | Fixed here | `PaidTaxGrants::finalize` never re-read refund or dispute state. `ProductionTaxFinalizeGuard` now makes the authenticated own-account provider read before the locked frame and the locked frame proves the paid lines carry the facts it read. See `docs/refund-dispute-lifecycle.md`, "Finalize guard". |
| P2-2 | Fixed here | The `order_source` filters in `ReportingQueries`, `OperationalHealth` and `StaffCommerceDirectory` had no test. `SourceFilteredReadersTest` plants production rows under a test order's number; 13 single-filter mutations were each killed. |
| Review 2 P2-1 | Fixed here | The guard ran before buyer ownership was proven: another enrolled buyer saw 409 versus 404 (a status oracle) and triggered about six provider GETs. Now an unlocked buyer pre-check (`sameOwner`, the locked frame's own comparison) precedes any provider call, a non-owner gets the unchanged 404 with zero provider calls, and the pre-lock read's refusal is deferred into the locked frame after the lock and buyer binding. |
| Review 2 P3-1 | Fixed here | Tests added for reviewed account not the own account and a line of another producer; the reviewed-currency check was dropped as unreachable (USD by the insert trigger, row immutable). |
| Review 2 P3-2 | Fixed here | `docs/refund-dispute-lifecycle.md` corrected: generic HTTP 409, admission scope, lost dispute before finalization, partial refund grants every line. |
| Review 2 P3-3 | Fixed here | The guard also holds when the charge `amount_refunded` aggregate (or the listed refunds in flight) covers the gross. The larger of the aggregate and the listed succeeded plus pending is used, not their sum, because Stripe's aggregate already includes the refunds listed. |
| Review 2 P3-4 | Documented | Owner-only timing difference (see the lifecycle document). |
| P3-3 | Checked, no change | `ProductionTaxRefundDisputeTest` and `SourceFilteredReadersTest` do not branch on the database driver (no `getDriverName`, no SQLite or MySQL condition), so by the census rule (`scripts/ci/phpunit-shards.py`, driver-branching files only) they do not belong in `scripts/ci/database-mysql-selection.json`. The native-only coverage stays in `RefundDisputeProductionNativeTest` and `refund-dispute-production-race-worker.php`, both already listed. Both CI self-tests pass. |
| P3-4 | Fixed here | The comment in `RefundDisputeAuthority::lock` said policy refused "before any row is locked or read"; `resolve()` makes plain reads first. It now says so. |
| P3-1 | Deferred, unchanged | Production rows are not bound to the parent batch or line in the database (the PaidTax schema forbids external references); the binding is enforced by the locked verify instead. |
| P3-2 | Deferred, cannot be done here | Migration `2026_11_10_288200_refund_dispute_production_tax_chain` drops guards and unique keys before creating the new ones, leaving a non-transactional MySQL window during deploy (create-first, drop-last would close it). It is already on main and must not be edited; any change would be a new forward-only migration. |
| P3-5 | Deferred, unchanged | No test runs the migration over populated pre-migration data. |
| P3-6 | Deferred, unchanged | Production `attention` states and holds are not surfaced to staff. Together with the new finalize guard, a refused finalization also leaves no staff-visible row (see Remaining). |

## Owner decisions recorded

Sean, 2026-11-10: C1 (a dispute ends download access while the license is kept, with the dispute noted on the record; a lost dispute keeps the hold, and staff may lift any dispute hold, a lost one included, through the existing audited `hold_released_by_staff` command with no automatic restore (amends DD-16; an unfinalized order has no release path); staff suspension ends access with the license record retained) and C4 (the exclusive acceptance items are not applicable on this chain because no production line is exclusive). Recorded in `docs/architecture/decision-register.md`, `docs/refund-dispute-lifecycle.md` and `CHANGELOG.md`. The code already behaved as C1 describes; the one path that returns access after a lost dispute is an explicit, audited staff `releaseHold`.

## Commands and results

Run from the worktree with `WT=<worktree> bash /home/user/notes/pu.sh <files>` (SQLite, CI environment, `public/build` absent).

- `tests/Feature/RefundDispute/ProductionTaxRefundDisputeTest.php`: the 18 pre-existing tests plus 7 new guard tests (refunded or disputed before finalization is held, clean states finalize, failed and foreign provider reads fail closed, replay is idempotent and makes no provider call, default-off makes none, the locked frame refuses facts that differ from the locked order).
- `tests/Feature/RefundDispute/SourceFilteredReadersTest.php`: 1 test, 17 assertions.
- Mutations of the new guard (each killed): dispute open or lost not held; refund coverage ignored; pending refunds ignored; invalid evaluation passes; provider failure passes; locked-frame check not called; provider read not made; payment binding dropped; gross binding dropped. A first run left two of these alive (invalid evaluation passes, locked-frame check not called); a test for refunds that individually fit but together exceed the payment, and a wiring test that binds a guard whose read differs from the locked order, killed them.
- Mutations of the source filters (each killed): `ReportingQueries::latestObserved` outer and subquery; `OperationalHealth` latest-observed outer and subquery, attention outer and subquery, holds, allocations, reversals; `StaffCommerceDirectory` orders latest, orders holds, customers holds; `RefundDisputeSource::scope`.
- `python3 scripts/ci/test-phpunit-shards.py` and `python3 scripts/ci/test-focused-tests.py`: green.
- `vendor/bin/pint --test --diff=origin/main`: passed.

Untested: the guard against real Stripe (only the read-only fixture gateway), concurrency of the guard (it holds no lock and changes none), browser flows.
