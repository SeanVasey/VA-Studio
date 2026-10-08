# Shared rights inventory — WP-06 development foundation

This increment implements shared identity/linkage, atomic quote-bound inventory exercises, expiry, administrative holds and pending-attempt retention. It is **internal and local/testing only**. No HTTP route, admin UI, production policy, exclusive publication, payable order, sold state or grant is enabled. Existing published non-exclusive offers remain non-exclusive. Their verified quotes exercise the inventory mechanism without changing the retained license or asserting exclusive rights.

## Scope and evidence

`ManageRightsScope` requires verified catalog staff for every operation. Registration binds a lowercase scope key and private evidence reference for life. Related offer variants, including different tracks when explicitly attested, link their exact published revision to the same scope. The link command rechecks current publication/license/rights/media readiness under the track/offer locks. One revision has one immutable scope link in this first implementation. A new commercial revision needs a fresh explicit link; it never inherits a presumed ownership relationship. Scope creation/linkage is an operator assertion, not independent chain-of-title verification.

`rights_scopes` owns the shared identity. Only its administrative block and monotonically increasing control version can change. A stale control form/command conflicts; the audit records actor, state/version and a reference hash. Blocking does not release a held or pending reservation. `rights_scope_offers` retains exact associations, actor, private reference and time. Restrictive FKs retain staff, offers, quotes and scopes; SQL triggers reject identity rewrites and deletes, including bulk ORM writes.

`ReserveQuoteInventory::hold(quoteId, ownerKey)` reuses the exact quote owner, immutable license/offer/media evidence, current eligibility and expiry checks. Every selected revision must be linked. Selecting two variants of the same scope in one quote is rejected. One immutable reservation per quote records the quote hash, exact links/scopes, explicit policy, creation and capped expiry. Matching retries return the same effect, changed policy conflicts, and failed multi-line acquisition writes no partial claims or audits. No client chooses scope IDs or amounts through this command.

Configuration is an explicit JSON `VASEY_TEST_INVENTORY_POLICY` with exactly `schema_version: 1`, `purpose: "test_inventory"`, integer `ttl_seconds` from 30 through 3600, and `pending: "retain_until_verified_resolution"`. Setup leaves it empty. Those bounds limit the development exercise; they do not choose U-08's production TTL. Expiry is the earlier of the requested policy interval and the quote expiry.

## Operator command for test purchases

Run `php artisan vasey:rights-scope list` from a trusted console on a local/testing installation. It lists scope keys and the IDs of current, active revisions on published tracks that still need linking. It never prints private evidence references. A scope link is required even for a non-exclusive test offer: without it, `PrepareOrder` refuses the selection with HTTP 409 and `INVENTORY_SCOPE_UNAVAILABLE`.

For each actual rights identity, choose its scope key and a non-secret reference to its private evidence. Do not put credentials, contract text or the evidence itself in command arguments. Use the verified catalog staff account's numeric ID, then confirm that account's password at the hidden prompt:

```sh
php artisan vasey:rights-scope register --actor-id=STAFF_ID --scope=SCOPE_KEY --reference=EVIDENCE_REFERENCE
php artisan vasey:rights-scope link --actor-id=STAFF_ID --scope=SCOPE_KEY --revision=REVISION_ID --reference=LINK_REFERENCE
php artisan vasey:rights-scope list
```

Replace the placeholders before running. Scope keys use lowercase letters, digits and `. _ : -` (1–96 characters, starting with a letter or digit); references use letters, digits and `. _ : / -` (1–192 characters, starting with a letter or digit). Use the **revision** ID printed by `list`, not the offer ID. Link every offer revision that a buyer may select. Related variants of the same right share one scope only when the operator explicitly verifies that relationship; unrelated rights need separate scopes.

Writes refuse `--no-interaction`, wrong passwords, missing/unverified staff and withdrawn catalog authority. The domain rechecks persisted authority and current publication, license, rights and media readiness inside its transaction. Identical register/link retries succeed without new rows or audits; a different scope or reference conflicts. Links are immutable, and a successor offer revision requires its own explicit link. Exit codes are 0 for success, 1 for a refused/unavailable operation and 2 for invalid input. Production remains refused. This command makes no provider call, releases no reservation and does not establish legal ownership or activate exclusive sales.

## Transactions and lifecycle

Lock order is quote → all tracks sorted by ID → all offers sorted by ID → all scopes sorted by ID → reservation/claim occupancy. Scope/offer linkage takes track → offer → scope. Administrative blocking takes only the scope. A future combined checkout command must acquire pricing/campaign/use locks before inventory scopes and cannot call back into earlier locks afterward. Deadlocks retry the complete idempotent transaction at most five times; no provider call occurs inside it.

Occupancy is read with current `FOR UPDATE` reads after the shared scope lock. The original consistent read snapshot cannot hide a competing committed claim. See [MySQL's locking-read contract](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html); actual correctness requires the independent-process tests.

| State | Capacity | Permitted transition in this increment |
| --- | --- | --- |
| `held` before expiry | Occupied | `pending`, with a unique attempt UUID and timestamp before expiry |
| `held` at/after expiry | Reusable only through the guarded acquisition command | Irreversible `expired` transition, audit and new claim in one transaction |
| `pending` | Occupied regardless of expiry | None; later verified payment/reconciliation is required |
| `expired` | Not occupied | None; retries cannot revive it, including with a slower worker clock |

`beginAttempt` requires an existing owned, current reservation and rechecks scopes and conflicts. Its unique UUID cannot bind another reservation; repeated identical binding is idempotent. A caller's enclosing transaction can roll back the binding with a failed order/intent write. The current primitive is not a durable order, payment authorization or provider idempotency implementation. Pending capacity remains occupied if the quote subsequently expires, the catalog changes, or the application rolls back. Existing quote-facing retry authorization still requires a live valid quote; future internal reconciliation must use separately verified order/provider records.

## Verification and remaining integration

Functional tests cover owner isolation, readiness, missing/duplicate links, scope conflict, private projection, staff authorization, policy drift/bounds, exact replay, multi-line success/rollback, administrative version conflicts, attempt uniqueness, nested rollback and SQL immutability/terminal guards. The migration test exercises disposable empty-table down/up while retaining prior quote evidence. MySQL workers use separate processes/connections, committed fixtures and explicit lock barriers for shared variants, same quote, expired/pending holds, same/conflicting attempts, administrative block, opposing expiry/attempt clocks and multi-scope acquisition. SQLite skips these nine concurrency cases explicitly.

The integrating PR records actual tested commit, CI runs, counts, dependency audits and independent review. Tests and this document alone are not execution evidence. Physical-device, production workload, multi-right decomposition, real source rights and restore acceptance remain unverified.

Next: versioned exclusive offer and quote snapshots with full source disclosure, exact scope linkage frozen at publication, and current inventory availability in selection/readiness. Preserve v1 non-exclusive readers and terms. Integrate the guarded reservation primitive with that flow and pricing before enabling exclusive checkout, including the explicit pending non-exclusive cutoff policy. WP-07 then binds immutable order/assent/intent, promotion and inventory attempts atomically before hosted Checkout, verifies provider results, and implements sold/grant or paid-exception and verified unpaid release. WP-08 retains buyer contracts and entitlements. U-08 still governs production expiry, late payments, refunds and historical rights. This foundation completes none of those legal or payment decisions.

## Rollout and recovery

The migration is additive and performs no backfill or historical inference. Deploy schema before internal callers. Withdraw callers/configuration to stop new exercises while preserving scopes, links, reservations, claims and audits. Do not run `down` on useful evidence. Down/up is tested only for disposable empty new tables. There is no automatic pending release or refund restocking; a clock or code rollback cannot establish that payment failed.

## Subsequent exclusive integration

The [exclusive selection increment](exclusive-selection.md) connects this foundation to activated test revisions, versioned quotes/disclosure/pricing and explicit cutoff. It adds non-mutating reservation verification for pricing reads and freezes scope bindings/policy in exclusive quotes. Pending inventory remains retained; WP-07 terminal effects are still required. The initial foundation boundaries above describe the original PR #44 scope.


## Terminal-effect handoff — 2026-09-26

The original foundation above remains historical. Merged PRs #52/#62/#63 now supply immutable order attempts, hosted test sessions and authoritative test-payment evidence. The current [finalization candidate](test-payment-finalization.md) adds one atomic verified `pending` → `consumed` effect for inventory and any promotion use, with matching immutable finalization proof; consumed promotion usage continues to count against lifetime capacity. Paid exceptions retain pending resources, and exclusive sales persist under a unique shared-scope constraint. Replays and historical reads must verify the complete effect graph. Candidate runtime acceptance is separate from these foundation results. No automatic release, refund restock or production policy is introduced.
