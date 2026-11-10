# BL-42 follow-up batch (review of PR #297)

Branch `claude/bl42-followups-20261110`, from main `b5c980a9`. This is the deferred batch from the independent review of PR #297 (BL-42, refund and dispute lifecycle on the production tax checkout chain, FP-068), under the freeze-and-batch rule (merge at the exact SHA, defer P2s and P3s to one per-lane follow-up). Focused SQLite runs only plus one native MySQL run; no Foundation CI, so none of this is full acceptance.

| Finding | Status | What |
| --- | --- | --- |
| P2-1 | Fixed here | `PaidTaxGrants::finalize` never re-read refund or dispute state. `ProductionTaxFinalizeGuard` now makes the authenticated own-account provider read before the locked frame and the locked frame proves the paid lines carry the facts it read. See `docs/refund-dispute-lifecycle.md`, "Finalize guard". |
| P2-2 | Fixed here | The `order_source` filters in `ReportingQueries`, `OperationalHealth` and `StaffCommerceDirectory` had no test. `SourceFilteredReadersTest` plants production rows under a test order's number; 13 single-filter mutations were each killed. |
| P3-3 | Checked, no change | `ProductionTaxRefundDisputeTest` and `SourceFilteredReadersTest` do not branch on the database driver (no `getDriverName`, no SQLite or MySQL condition), so by the census rule (`scripts/ci/phpunit-shards.py`, driver-branching files only) they do not belong in `scripts/ci/database-mysql-selection.json`. The native-only coverage stays in `RefundDisputeProductionNativeTest` and `refund-dispute-production-race-worker.php`, both already listed. Both CI self-tests pass. |
| P3-4 | Fixed here | The comment in `RefundDisputeAuthority::lock` said policy refused "before any row is locked or read"; `resolve()` makes plain reads first. It now says so. |
| P3-1 | Deferred, unchanged | Recorded deferral from the review. The text of this finding was not in the brief for this batch, so it is neither fixed nor re-assessed here. |
| P3-2 | Deferred, cannot be done here | Needs a schema change, and migration `2026_11_10_288200_refund_dispute_production_tax_chain` is already on main and must not be edited. If it is ever wanted it is a new forward-only migration. |
| P3-5 | Deferred, unchanged | As P3-1. |
| P3-6 | Deferred, unchanged | As P3-1. |

## Owner decisions recorded

Sean, 2026-11-10: C1 (a dispute ends download access while the license is kept, with the dispute noted on the record; a lost dispute keeps the hold; staff suspension ends access with the license record retained) and C4 (the exclusive acceptance items are not applicable on this chain because no production line is exclusive). Recorded in `docs/architecture/decision-register.md`, `docs/refund-dispute-lifecycle.md` and `CHANGELOG.md`. The code already behaved as C1 describes; the one path that returns access after a lost dispute is an explicit, audited staff `releaseHold`.

## Commands and results

Run from the worktree with `WT=<worktree> bash /home/user/notes/pu.sh <files>` (SQLite, CI environment, `public/build` absent).

- `tests/Feature/RefundDispute/ProductionTaxRefundDisputeTest.php`: the 18 pre-existing tests plus 7 new guard tests (refunded or disputed before finalization is held, clean states finalize, failed and foreign provider reads fail closed, replay is idempotent and makes no provider call, default-off makes none, the locked frame refuses facts that differ from the locked order).
- `tests/Feature/RefundDispute/SourceFilteredReadersTest.php`: 1 test, 17 assertions.
- Mutations of the new guard (each killed): dispute open or lost not held; refund coverage ignored; pending refunds ignored; invalid evaluation passes; provider failure passes; locked-frame check not called; provider read not made; payment binding dropped; gross binding dropped. A first run left two of these alive (invalid evaluation passes, locked-frame check not called); a test for refunds that individually fit but together exceed the payment, and a wiring test that binds a guard whose read differs from the locked order, killed them.
- Mutations of the source filters (each killed): `ReportingQueries::latestObserved` outer and subquery; `OperationalHealth` latest-observed outer and subquery, attention outer and subquery, holds, allocations, reversals; `StaffCommerceDirectory` orders latest, orders holds, customers holds; `RefundDisputeSource::scope`.
- `python3 scripts/ci/test-phpunit-shards.py` and `python3 scripts/ci/test-focused-tests.py`: green.
- `vendor/bin/pint --test --diff=origin/main`: passed.

Untested: the guard against real Stripe (only the read-only fixture gateway), concurrency of the guard (it holds no lock and changes none), browser flows.
