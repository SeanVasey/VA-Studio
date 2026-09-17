# D-10 — Shared inventory before exclusive publication

Status: implementation candidate for independent review and CI, 2026-09-17.

Recovered baseline: PR #43 is merged on `main` at `4c08c0caf9239230cb1b6e5caec3e6b59301a0fe`, tree `be1c97027c55cdd7377785813fd768c1628eb807`, with successful final CI and documented review. No open PR or uncommitted work was found in the recovered registered worktrees. A retired cleanup-review directory has an obsolete worktree pointer and is not an active development branch; it was preserved.

The next ordered package is WP-06 shared exclusive inventory. Code inspection shows the current commercial snapshot, publication/readiness and pricing reader are deliberately v1 non-exclusive. Enabling exclusive sale by removing those guards would mislabel historical contracts and bypass unimplemented policy. Split this dependency into an inventory foundation, then explicit versioned exclusive offer/quote integration before checkout.

The [inventory contract](../shared-rights-inventory.md) records the foundation's exact boundaries. It adds immutable shared scope identity and revision linkage, staff controls, owner-verified quote-bound test exercises, deterministic scope locking, irreversible expiry and non-expiring pending claims. No existing snapshot or production offer changes. Per-SKU inventory was rejected because distinct variants could sell the same right twice; implicit links were rejected because neither a matching filename nor a new revision proves rights identity. A mutable stock counter was rejected because it would discard reservation/retry and uncertain-payment evidence.

The new internal commands are local/testing only, require explicit TTL policy, and expose no routes. Additive tables and SQL guards retain evidence on MySQL/SQLite. Independent-process MySQL races supply concurrency evidence; frontend/browser CI remains regression coverage. Integration with actual exclusive commercial snapshots, inventory-aware publication, multi-right decomposition, sold/finalization and U-08 decisions remains open. Production scope/TTL choices, ownership transfers and payment outcomes are not inferred.

Next dependency: exclusive offer/quote versioning and inventory-aware selection/pricing, then the existing WP-07/WP-08 sequence. Preserve all broader work-package acceptance gates. Record candidate results in the PR and current handoff.
