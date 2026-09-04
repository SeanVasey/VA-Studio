# [WP-06] Server quotes, promotion calculations and exclusive reservations

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-06] Server quotes, promotion calculations and exclusive reservations`
- Phase: 2
- Dependencies: WP-03 and WP-04. U-08 governs live exclusive behavior.
- Suggested branch: `work/wp-06-quotes-promotions-and-exclusive-reservations`
- Implementation paths: app/Domain/Commerce quotes/pricing/reservations, app/Support/Money, database/migrations and MySQL concurrency tests.

## Problem

A buyer must accept one authoritative price/rights snapshot, and simultaneous offers must never sell the same exclusive twice.

## First reviewable increment

Quote one licensed track with frozen assets/terms/price/assent disclosure and reserve its shared exclusive scope.

## Scope

- Integer money, validated currency, effective-dated prices and promotion calculation trace; no client-authoritative totals.
- Frozen quote/hash/expiry and tax-policy envelope, exact disclosure versions and buyer/session ownership.
- Shared inventory row across all exclusive SKU variants; transactional reservation and deterministic lock ordering.
- Idempotent checkout intent with policy-based expiration; basic promotion limits/allocations under atomic guards.

## Acceptance criteria

- [ ] Price/terms/assets cannot drift after quote creation; expired or changed requests receive explicit restart errors.
- [ ] Same idempotency key/body returns the same result; different body returns conflict.
- [ ] Two distinct exclusive SKUs sharing a scope cannot both reserve/sell; repeated same-buyer requests remain safe.
- [ ] Earlier valid non-exclusive grants are unaffected; pending non-exclusive cutoff is explicit before live exclusives.
- [ ] Coupon limits and multi-line rounding remain correct under concurrency; no discount bypasses inventory/readiness.

## Verification

Domain pricing/canonicalization fixtures and MySQL independent-connection races for exclusive variants, coupon caps, expiry, retries and administrative hold. SQLite alone is insufficient. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Disable new quote/session creation and expire unstarted intents under policy. Do not release inventory with a potentially successful in-flight payment until provider reconciliation.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-06 with the data-contract algorithms. Keep money exact, snapshot every offered term and asset, and lock the shared underlying rights scope. Prove races on MySQL and expose paid-exception inputs for later finalization rather than assuming late payments cannot happen.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
