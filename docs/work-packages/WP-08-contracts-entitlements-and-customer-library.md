# [WP-08] Deterministic contracts, entitlements and customer re-downloads

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

- Suggested issue title: `[WP-08] Deterministic contracts, entitlements and customer re-downloads`
- Phase: 2
- Dependencies: WP-03, WP-04 and WP-07. Renderer and guest-claim choices are U-06/U-07.
- Suggested branch: `work/wp-08-contracts-entitlements-and-customer-library`
- Implementation paths: app/Domain/Delivery, contract templates/renderer adapter, customer account pages, private storage and delivery tests.

## Problem

Every paid buyer needs the exact agreement and files purchased, securely available again under the applicable license policy.

## First reviewable increment

One frozen paid grant through contract generation, verified fulfillment and authenticated exact-asset download.

## Scope

- Frozen buyer/seller/product/license/price/asset render input, pinned local fonts/assets/renderer, private PDF and hashes.
- Idempotent document generation and all-required-evidence fulfillment activation.
- Customer purchase history, contract download and entitlement exchange with ownership, policy, expiry and atomic cap enforcement.
- Single-use guest claim interface if chosen; signing failures, expired links and failed-document operator recovery.

## Acceptance criteria

- [ ] Catalog/customer/license edits cannot change existing contract input or original output.
- [ ] Renderer rejects missing/untrusted input and cannot fetch arbitrary network resources.
- [ ] No entitlement becomes active before required documents/assets are durable; retries create one logical result.
- [ ] One buyer cannot enumerate or download another buyer's order; signed URL TTL and counters are enforced server-side.
- [ ] Historic contracts remain byte-preserved; a refund or dispute does not silently erase rights or re-list an exclusive.

## Verification

Golden synthetic contract input/hash fixtures, retry after partial render, authorization/IDOR, concurrent download cap/claim requests, signer failure and expired/revoked entitlement tests. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Pause new issuance/fulfillment if unsafe; preserve original documents and immutable assets. Restore a renderer adapter without regenerating historical originals.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-08 using frozen grant snapshots, private storage and explicit entitlement policies. Make rendering and URL issuance retry-safe, and verify ownership and concurrent caps. Do not infer unlimited download rights or guest identity beyond the recorded policy.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
