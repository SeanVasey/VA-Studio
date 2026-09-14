# D-09 — Version promotion pricing and serialize campaign capacity

Status: **Proposed architecture, implemented for verification within Sean's continued-development authority.** Date: 2026-09-14. Owner: implementation lead for the WP-06 contract; Sean retains production commercial choices. This entry approves no production promotion, tax policy or payment workflow.

**Verified baseline:** PR #42 merged at main `4e1c845ecca3da92a76afd99f48954c6db020093`, tree `9cb693b31835c814ca005dadbd96c1eecaad299b`. Exact owned pricing/disclosure exists; it has no discount allocation or shared coupon capacity. Issue #6 explicitly places promotions/usage guards next, then shared exclusive inventory, WP-07 and WP-08.

## Choice and alternatives

**Recommendation in implementation:** preserve the original v1 reader and create an explicit v2 for one promotion, backed by immutable campaign policy and a shared locked usage budget. Keep unstarted expiry separate from durable pending-attempt binding. The detailed [promotion contract](../promotion-pricing.md) defines inputs, safe output, algorithms, effects and unresolved scope.

Continuing without promotions leaves the ordered requirement unimplemented. Rewriting v1 would change historical meaning; client discounts would abandon server authority. A mutable counter without retained use identity would make retries and expiry difficult to reconcile. The selected records retain every hold and use one existing MySQL transaction boundary, without a new provider/service. Terminal paid consumption and verified release remain dependent on WP-07's actual payment evidence.

## Effects and controls

| Surface | Change / preserved invariant | Evidence and reversal |
| --- | --- | --- |
| Domain | Versioned exact allocation before tax; one code per quote; retained v1 implementation | Boundary/reordering/historical tests; withdraw new caller, preserve readers |
| Data | Add immutable campaign evidence and constrained usage identities; held → pending only | MySQL/SQLite schema/rollback tests; retain tables after useful records exist |
| HTTP | Identifier-only promotion pricing POST; existing ownership/CSRF/private headers/throttles | Real HTTP/CSRF, foreign owner, malformed input, safe DTO tests |
| Concurrency | Quote → catalog → pricing → campaign → uses; locking capacity reads at initial hold and attempt handoff, plus idempotent retry | Independent MySQL processes at quote/campaign barriers, including clock skew after slot reuse; SQLite is not concurrency evidence |
| Audit | Single pricing/hold/pending effects, with safe IDs/hashes | Replay, last-slot race and nested transaction rollback assertions |
| Payment | Internal test attempt binding before future external request; pending capacity cannot expire | No provider calls; WP-07 supplies durable order linkage, paid verification and terminal reconciliation |
| UI/operations | No new visual surface or service; optional explicit local/test JSON list | Existing regression CI; production rejects test configuration; no live coupon seeded |

Policy identity is immutable for the campaign lifetime: changing a textual version cannot reset capacity. Current limits bound configuration to 64 KiB / 50 policies / 100 eligibility IDs and active capacity reads to 10000 use IDs. These are implementation resource bounds, not commercial approvals or measured production throughput. A busy campaign serializes its own quota decisions; benchmark before expanding limits or introducing counters. There is no new dependency or external fee.

For current-read behavior and integer representation, official references checked 2026-09-14 are [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) and [PHP integer binary conversion](https://www.php.net/manual/en/function.decbin.php). Runtime acceptance depends on the candidate's tests, not those references alone.

## Acceptance, recovery and handoff

Require final-candidate MySQL/SQLite, frontend/build, browser and dependency CI, plus independent commerce/authorization/migration review under AGENTS.md. The current environment has no PHP/Composer runtime; GitHub Actions supplies executed PHP/race evidence. Original v1 source was compared byte-for-byte, allowing only its class rename. Test fixtures are synthetic and excluded from application setup.

The migration is additive with no backfill. Roll back application creation/attempt callers while retaining every evidence record. Down/up is appropriate only for disposable empty new tables. Any future possibly successful provider attempt requires WP-07 reconciliation; code rollback is not permission to release its capacity. U-04/U-05/U-07/U-08 retain production tax, terms, identity and exclusive timing decisions.

After acceptance, continue shared exclusive inventory/reservations within WP-06. WP-07 must bind the attempt with an order before requesting payment, then add verified consumed/released terminal effects. WP-09 owns actual promotion authoring, successor administration and per-customer policies. The broad issue #6 remains open; this is not complete BeatStars parity.
