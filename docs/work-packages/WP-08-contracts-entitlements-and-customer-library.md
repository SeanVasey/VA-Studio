# [WP-08] Deterministic contracts, entitlements and customer re-downloads

Status: **Original contract issuance, complete-order test activation and owner HTTP/UI delivery are accepted through PR #71.** The [single-seller experience candidate](../verification/single-seller-experience-increment.md) adds current-session test-order history and a synthetic exact-original restore rehearsal. Guest recovery, the complete customer library, production archival/storage and fulfillment remain open.

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


## WP-07 prerequisite handoff — 2026-09-26

Merged [finalization](../test-payment-finalization.md) under [D-16](../architecture/D-16-test-payment-finalization.md) defines one immutable grant per original order line. Its encrypted render input freezes the original unverified guest buyer, seller policy, affirmative assent and review hash, exact selection/pricing/full disclosure, purchased asset references, inventory binding, grant/finalization identity, policy and explicit effective/confirmation timestamps. Existing license `renderer_version` is historical review-HTML provenance; it does not select the buyer PDF renderer. Do not substitute current customer, seller, catalog, license, preview or asset revisions.

Paid finalization creates only pending exact-asset entitlements and a pending outbox. Paid exceptions create neither grants nor entitlements and retain pending resources for later operator resolution. There is no rendering worker, PDF output, entitlement activation or download route in that increment. PR #64 accepted those prerequisites at main `69b28a9` after full CI and two independent source reviews; the [finalization contract](../test-payment-finalization.md#verification-and-operational-rollback) records the exact tested source and counts.

The D-17/D-18/D-19 increments below are now accepted in merged PRs #65/#67/#69: the pinned PDF profile and original-preservation interface, complete-order activation without pending-record mutation, and a separate internal access policy. Their ordered acceptance is the prerequisite for the D-20 owner HTTP/UI candidate. Guest/account recovery, production issuance limits/storage and legal/privacy policies still need their scoped decisions and evidence.

## Private original issuance — implemented 2026-09-26, accepted in #65

[Test-contract issuance](../test-contract-issuance.md), under [D-17](../architecture/D-17-test-contract-issuance.md), adds an explicit test-only policy, one immutable request/profile per grant, bounded durable claims and one immutable private original-document manifest. The fixed offline renderer uses original frozen input/full terms and local hashed fonts; unsupported text fails closed. Private writes and rendering occur outside database transactions, and publication rechecks the winning claim. Missing original bytes are restore-only; completed manifests/work cannot be replaced or relabeled to hide loss.

The UI shows pending contracts, unfinished issuance needing attention, or issued test contracts awaiting delivery. Existing owner authorization and verified-payment behavior remain. Ordinary status GETs inspect retained database evidence, not PDF bytes; issued status alone does not prove present filesystem durability. Entitlements and outbox remain pending; that original issuance increment itself adds no contract/asset download route. Production isolation, archival/legal acceptance, storage, backup/restore and buyer claim/recovery remain explicit limits.

PR #65 is accepted with the full CI and independent review retained in [development order](../development-order.md). The earlier local UI commit `b4f666f` passed 195 frontend tests and its TypeScript/Vite build; that historical component evidence does not substitute for integrated acceptance. Earlier Actions allowance/budget failures and their eventual resolution remain in the ordered record. These increments advance issue #8 without closing its broader criteria.

## Activation and internal delivery — implemented 2026-09-26, accepted in #67/#69

[D-18 activation](../test-fulfillment-activation.md) requires every first original and exact purchased file, fresh private SHA-256/length/provenance checks outside transactions, a bounded 300-second observation and complete graph comparison under locks. One atomic proof/audit records the whole order; partial or failed physical checks grant nothing. Historical activation does not assert present file health. Pending entitlements/outbox and licensed permissions remain unchanged.

[D-19 internal delivery](../test-owner-delivery.md) requires that proof plus original owner identity, an explicit enabled order control and separate default-off test access policy. It implements a 60-second hash-only authorization, three-per-order rolling technical issuance budget, one committed redemption and bounded exact private snapshots. These numerical limits are test abuse/concurrency policy, never a license's `copies_downloads` term. Lost secrets cannot be recovered; post-commit interruption consumes the stream attempt without claiming receipt.

The accepted internal foundation supplies no item projection, customer routes/controller, HTTP privacy boundary or attachment UI by itself. Its full CI includes independent MySQL races; the current D-20 candidate implements the separate owner boundary below. Customer purchase library, guest/account claims, refunds/disputes, production durable storage/restore and historical continuity remain open. No existing work-package criterion is checked off from a component test or draft PR.


## Current owner HTTP/UI candidate — 2026-09-27

[D-20](../architecture/D-20-test-owner-delivery-http.md) and the [owner delivery guide](../test-owner-delivery-http.md) define a versioned database-only projection, exact purchased-item metadata, newest-20 authorization/attempt history, strict owner/CSRF/privacy responses, explicit authorization POST and native attachment POST. The original owning session remains mandatory. Tokens stay ephemeral and never enter URLs or persistent browser storage. A committed attempt is not proof of completed receipt; history and failure/retry UI must preserve that distinction.

Keep the current D-19 policy/control, one-attempt and rolling technical budget rules unchanged. Pending entitlements/outbox and licensed permissions stay immutable. The candidate must independently demonstrate IDOR/session rotation, malformed/ambiguous input/method/range rejection, all middleware/debug/log privacy, exact frozen bytes/headers and cleanup, truthful interrupted delivery, Chromium/WebKit native attachments and complete integrated CI. Its actual tested commit/review and executed evidence belong in the integrating PR and guide; prerequisite CI is not candidate acceptance.

After this owner HTTP/UI increment is accepted, continue WP-09 versioned site content with private drafts, atomic publication and rollback. A bounded history for one owned order is not the complete cross-order customer library or U-07 account/guest recovery. Those scopes, production archival/storage/restore, refunds/disputes and historical preservation remain tracked here and in the full ordered plan.
