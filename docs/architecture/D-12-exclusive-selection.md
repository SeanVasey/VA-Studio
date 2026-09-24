# D-12 — Connect exclusive selection to atomic pricing and inventory

Status: implementation candidate, 2026-09-23. Actual final CI and independent review are recorded in the integrating PR.

Merged PRs #48–#50 supply immutable exclusive preparation, atomic pricing/promotion/inventory orchestration and the reconciled dependency toolchain. The next WP-06 dependency is one coherent integration across activation, quote/disclosure versions, pricing, promotion eligibility and inventory-aware selection.

Retain offer v2 preparation unchanged and add immutable activation evidence under an explicit local/testing policy. Do not make an active flag sufficient authorization. Require exact frozen asset verification and explicit scope coverage for active siblings; block unlinked successors rather than inventing their relationship. Quote v2 captures the activation and scope/policy evidence; pricing v3 preserves historical v1/v2 algorithms and acquires inventory atomically with pricing and campaign capacity.

Anonymous browsing and owned review use advisory availability. Only sorted scope locks and current locking occupancy reads authorize acquisition. The exact owning quote can read its existing hold; a different quote cannot inherit that exemption. Pending inventory attempts retain occupancy until WP-07 verified resolution. A development cutoff serializes linked non-exclusive/exclusive attempts without asserting a production U-08 decision or changing historical grants.

The [implementation contract](../exclusive-selection.md) defines the schemas, policy, cutoff, lock order, tests and rollback. Next is WP-07 order/assent/hosted checkout and verified terminal effects, then WP-08 contracts/delivery. Live sales and full BeatStars parity remain separate acceptance gates.
