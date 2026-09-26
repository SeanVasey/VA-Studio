# [WP-08] Deterministic contracts, entitlements and customer re-downloads

Status: **Merged PR #64 supplies frozen grants and pending entitlements/outbox. Draft #65 implements D-17 private originals/recovery; draft #67 implements D-18 whole-order activation. D-19 internal controls, authorization and private streams are a further dependent candidate. Customer HTTP/UI downloads, library and recovery remain unimplemented.** Each candidate needs its final integrated tests/review and prerequisite acceptance; this issue is complete only when the acceptance evidence below exists.

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

The dependent D-17 candidate below chooses and pins the local/testing PDF profile and original-preservation interface. D-18 then adds complete-order activation evidence without mutating pending records or weakening finalization verification; D-19 adds a separate internal access policy. Accept these candidates in order before owner HTTP/UI downloads. Guest/account recovery, production issuance limits/storage and legal/privacy policies still need their scoped decisions and evidence.

## Private original issuance candidate — 2026-09-26

[Test-contract issuance](../test-contract-issuance.md), under [D-17](../architecture/D-17-test-contract-issuance.md), adds an explicit test-only policy, one immutable request/profile per grant, bounded durable claims and one immutable private original-document manifest. The fixed offline renderer uses original frozen input/full terms and local hashed fonts; unsupported text fails closed. Private writes and rendering occur outside database transactions, and publication rechecks the winning claim. Missing original bytes are restore-only; completed manifests/work cannot be replaced or relabeled to hide loss.

The UI shows pending contracts, unfinished issuance needing attention, or issued test contracts awaiting delivery. Existing owner authorization and verified-payment behavior remain. Ordinary status GETs inspect retained database evidence, not PDF bytes; issued status alone does not prove present filesystem durability. Entitlements and outbox remain pending and there is no contract/asset download route. Production isolation, archival/legal acceptance, storage, backup/restore and buyer claim/recovery remain explicit limits.

With PR #64 accepted, record this dependent candidate's real renderer/queue/storage/concurrency tests, full CI and independent reviews. The local UI commit `b4f666f` passed 195 frontend tests and its TypeScript/Vite build; browser/runtime acceptance belongs to the integrated candidate. This advances issue #8 without closing the broad criteria above. Current final candidate status, including the Actions allowance/budget block for #66/#67, is recorded in [development order](../development-order.md).

## Activation and internal delivery candidates — 2026-09-26

[D-18 activation](../test-fulfillment-activation.md) requires every first original and exact purchased file, fresh private SHA-256/length/provenance checks outside transactions, a bounded 300-second observation and complete graph comparison under locks. One atomic proof/audit records the whole order; partial or failed physical checks grant nothing. Historical activation does not assert present file health. Pending entitlements/outbox and licensed permissions remain unchanged.

[D-19 internal delivery](../test-owner-delivery.md) requires that proof plus original owner identity, an explicit enabled order control and separate default-off test access policy. It implements a 60-second hash-only authorization, three-per-order rolling technical issuance budget, one committed redemption and bounded exact private snapshots. These numerical limits are test abuse/concurrency policy, never a license's `copies_downloads` term. Lost secrets cannot be recovered; post-commit interruption consumes the stream attempt without claiming receipt.

The internal foundation has no item projection, customer routes/controller, CSRF/privacy response boundary, attachment UI or browser delivery evidence. Implement and verify those next after prerequisite and internal acceptance, including independent MySQL races, foreign/session-bound access, native Chromium/WebKit attachment handling and accurate failure states. Customer purchase library, guest/account claims, re-download history, refunds/disputes, production durable storage/restore and historical continuity remain open. No existing work-package criterion is checked off from a component test or draft PR.
