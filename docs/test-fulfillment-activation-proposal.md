# Proposed test fulfillment activation

Status: **Design proposal only, 2026-09-26. No activation schema, command, entitlement authority or download route is implemented by this document.** Prepared against candidate `ee18e0431326b18385949adde8692f143010c9ce`, after inspecting the existing payment, contract, media and order readers. Accept D-17 and its actual CI before integrating this dependent increment.

The next bounded change should prove that one complete paid test order has all its original contracts and exact purchased assets available in protected storage, and record that decision once. Preserve the first document, frozen grant, pending-entitlement and finalization-outbox rows. Downloads require a later access decision and remain unavailable in this increment.

## Existing interfaces and limits

| Existing implementation | Reuse or constraint |
| --- | --- |
| `ReadOrder::verify` and `ReadFinalization::verify` | Verify the entire paid order, exact grants, retained input, original inventory effects, pending entitlements and render outbox. Keep their historical reconstruction intact. |
| `ReadGrantContract::forRequest` | Verify request/profile/work/result linkage for each grant. It verifies retained metadata; it does not inspect the original PDF bytes. |
| `ContractFiles::verify` | Read and hash the exact first PDF outside transactions; reject missing/corrupt originals and unsafe storage without rendering or replacing anything. |
| `PendingEntitlement` / migration 20 | Every row is immutable and has `state = pending`; both application and SQL guards reject an in-place activation update. |
| `FinalizationAssets::inspect` | Already compares frozen asset descriptors and exact hashes before finalization. It does not provide an immutable activation proof or descriptor-based delivery handle. |
| `VerifiedMedia::path` | Verifies historical processing/scan/output provenance. Its integrity result can be cached for 60 seconds, so it is insufficient by itself for a new activation proof. |
| `PrivateMediaFiles` | Private media path helper; it does not implement all of `ContractFiles`' checks for other served disks, public links, overlapping roots and hardlinks. Do not treat it as delivery authorization. |
| `PublicMediaController` | Only public artwork/tagged previews. Preserve that role boundary; do not expose contracts, masters, MP3 delivery or stems through this route. |
| Current routes and domain services | No customer delivery issuer, private contract endpoint, signer, download counter, guest-claim service or fulfillment-activation aggregate exists. |

Ready media revisions and completed processing evidence already have ORM and SQL immutability guards. A fresh durability check should use those historical records, not current catalog publication, latest offer, latest preview, or new legal terms. Withdrawing a track from new sales does not itself cancel a retained grant.

## Recommended smallest data contract

Use **one immutable `test_fulfillment_activations` row per complete order**, rather than mutating pending entitlements or adding per-grant partial activation. Proposed fields are:

| Field | Binding |
| --- | --- |
| `id`, unique UUID `public_id` | Internal locator and safe command/audit locator. |
| unique `order_id`, unique `order_finalization_id` | Restrictive foreign keys; finalization must belong to this order and be `paid` / `test`. |
| `policy_version` | Exact version of the explicit test activation policy, also retained inside evidence. |
| `evidence_ciphertext`, `evidence_hash`, `canonicalization_version` | Canonical encrypted evidence; SHA-256 of the stored ciphertext, matching existing private evidence conventions. |
| `verified_from`, `verified_through`, `activated_at` | UTC whole-second observation window and commit decision time. Every file check occurs within the retained window. |

The evidence binds the order/finalization IDs and original evidence hashes, test payment/account scope, the exact policy, every grant in original order-line position, its render-input hash, the first original document's complete manifest identity, and every pending entitlement's ID/role/asset/hash/size. Include the purchased asset descriptor hash and exact immutable private storage key/provenance identifiers needed to reconstruct the same check. Keep all paths, customer data and internal provider identifiers private. Do not copy current customer/catalog/license values or infer any new rights.

A separate child table is unnecessary for the first increment: each pending entitlement already has an immutable identity, while a reconstructed canonical activation payload proves exact set equality and completeness. A future issuer can locate a purchased item by its opaque locator, validate the complete activation proof, and match the retained entitlement/document entry. If later query volume justifies indexed activation members, add them with exact-set verification rather than changing original evidence.

Install ORM and SQL update/delete rejection, unique order/finalization constraints, canonical/hash shape checks, `paid` / `test` parent binding, and monotonic timestamp guards. Cryptographic/full-set verification remains in the domain service and read path; SQL parent checks alone are not activation authority. A populated rollback must refuse before removing any guard or table. Empty disposable migration roundtrips must include this new dependency before migration 21 is dropped.

## Explicit technical test policy

Use a separate strict boolean flag and exact JSON policy, disabled by default and allowed only in `local` / `testing` for the configured own Stripe test account. Do not depend on the older order, checkout, finalization or contract-issuance enablement flags to read or activate retained evidence. Proposed policy identity is `test-fulfillment-activation-v1`, with these fixed decisions:

- Scope is the complete paid order; all original contracts and all purchased assets are required.
- Existing pending rows are preserved. The additive proof records technical activation only.
- Buyer identity stays `unverified_guest`; no account claim, email verification or marketing consent is created.
- Storage profile is the private local adapter; file checks bypass the integrity cache and compare exact SHA-256 and byte length.
- File verification is outside every open database transaction. The proposed maximum age of the earliest check at activation is **300 seconds**, including lock waits; stale or backwards-clock observations require a new check.
- Download access remains disabled. The policy contains no license-use limit, download cap, expiry term or invented production entitlement.

The 300-second window is an implementation choice for the test proof, not a download entitlement or production retention commitment. Root can select this conservative fixed bound without a new business-policy decision. Verification should stream bounded chunks and deduplicate shared asset IDs without dropping per-grant entitlement membership; existing order preparation permits at most ten distinct tracks.

## Service and transaction sequence

Proposed entry point: `ActivateTestFulfillment::handle(int $orderId): string`. Its public inputs are trusted internal IDs only. A scoped console command resolves opaque order UUIDs, limits batches to 1–100 (default 25), and advances a UUID cursor past failures. A synchronous bounded command is sufficient for this increment; automatic after-contract dispatch can be added once the proof is accepted.

1. Enforce the current activation policy, own account/test scope, and zero transaction depth on every open connection. Load the order and verify its complete historical finalization graph. An unpaid order or paid exception is ineligible and creates no proof.
2. If an activation already exists, validate and return that immutable decision without replacing timestamps, files or policy. The result name must distinguish a retained proof from a fresh storage-health check. The proposed idempotent result is `activated`, meaning recorded activation, never “download available.”
3. Capture the exact expected graph. Require one intact completed original manifest for every grant and exact pending-entitlement membership for every purchased role. If a required document is not yet issued, return `pending_contracts`; malformed/missing work or contradictory result evidence returns `changed`.
4. Outside all database transactions, verify every original through `ContractFiles::verify` and every purchased asset through a new strict uncached delivery-file verifier. The asset verifier checks the frozen descriptor and retained processing/scan/stems provenance, a protected unserved private root, all path components, a regular single-link sealed file, descriptor/path identity before and after hashing, byte length, and exact digest. It must not create missing directories or repair files. Test-only scan provenance remains restricted to the testing environment as in `VerifiedMedia`.
5. Begin one short transaction. Lock the order first, then grant IDs in stable ascending order, then their contract request/work rows as needed, and media revision IDs in ascending order. Immutable rows need no state transitions; stable locking and the order mutex serialize competing activation creators. Use the same order → grant ordering as contract publication to avoid inversion.
6. Reload and revalidate the entire order/contract/entitlement/provenance graph using database evidence only. Compare the newly reconstructed graph exactly with the one whose files were checked. Reject changed storage descriptors, missing/extra bindings, stale observation windows or backwards clocks. No hashing, path lookup, provider call or file I/O occurs under these locks.
7. Recheck for a concurrent committed activation. Verify and return the winner if present. Otherwise encrypt and insert the single activation proof, record one bounded audit, verify it against the locked graph, and commit atomically. On failure, no activation is retained and every existing payment/grant/original/entitlement record remains unchanged.

No independent work table or lease is needed yet: parallel contenders can repeat read-only hashing and then converge under the order mutex/unique key. There is no external irreversible side effect before commit and no mutable rendering state to recover. A crash simply leaves no activation and the scanner can retry. Add leased work only if measured operational cost justifies it.

Suggested bounded command outcomes are `activated`, `pending_contracts`, `ineligible`, `unavailable`, `changed`, `original_unavailable`, `asset_unavailable`, and `retry`. Missing/corrupt files never create an irreversible rights exception; restore the exact retained revision/original and retry. Generic infrastructure failures produce a retry outcome without raw paths, SQL, buyer data or provider identifiers.

## What the proof means and what it cannot mean

Filesystem hashing and a database commit are not one atomic storage transaction. The proof records that exact protected local objects were verified immediately before the activation decision, under a bounded observation window. It does not establish replicated object durability, a successful backup, an object-store retention lock, or continuing availability forever. Production object storage and restore acceptance remain U-03 gates.

A future delivery command must validate both the immutable activation graph and current physical/access conditions. If an original or purchased asset is subsequently missing, corrupt or exposed through unsafe storage, deny access and preserve the historic activation and rights evidence. Do not flip pending records, regenerate the PDF, silently select a successor file, or overwrite the activation to hide loss. If needed, append separate bounded health observations; they must never redefine purchased rights.

Keep the current finalization verifier pending-only. A dedicated `ReadTestFulfillmentActivation` composes it with the complete contract graph and exact activation evidence. Add it at the authorized order projection and future delivery boundary; do not recurse from the low-level finalization verifier back into a reader that calls `ReadOrder::verify`.

Metadata-only owner status can distinguish a recorded activation from pending activation, but must not label a download as available before the delivery interface is accepted. Suggested next wire state is `contractStatus: issued`, `fulfillmentStatus: activated`, with product copy such as “Test delivery prepared.” No links, storage keys or private evidence are exposed. Ordinary status GETs remain non-mutating and do not read original/media bytes.

## Separate follow-on: access, identity and restrictions

Activation is one prerequisite, not the authorization decision. The next download increment must introduce an explicit bounded **test access policy**; absence of that policy denies every download. Source licenses' `copies_downloads` fields describe the buyer's licensed exploitation limits and must never be reused as website download-count caps.

For local/testing delivery, the existing order-owning session can support a bounded first interface without claiming verified identity. Every issuance must authorize the current owning session, verify the complete activation, match the exact original document or entitlement revision, and apply the separate current access policy and any effective restriction. Foreign and nonexistent locators must be indistinguishable. Session loss cannot be solved by trusting the supplied checkout email; guest/account recovery remains a separate U-07 decision.

A later mutable delivery-control state or append-only restriction ledger should serialize with issuance under a defined lock order. Missing/unrecognized controls fail closed. Withdrawing the test access flag blocks new issuance while preserving historical activation. Expiring a short authorization affects that authorization; it does not expire the license. Revoking access, recording a refund/dispute and revoking underlying rights are distinct actions with different evidence and authority. None may silently erase a grant, regenerate a contract or re-list exclusive inventory.

Prefer an application-authorized opaque delivery token or protected stream for the first local adapter; do not expose filesystem paths. Any future signed URL must enforce its TTL at redemption, bind the exact revision, omit secrets from logs/caches, and account atomically for caps. A direct object-store bearer URL cannot be recalled before its own expiry; any revocation claim must state that actual boundary. Choose numerical test TTL/caps only in that delivery increment with an explicit test policy and adversarial issuance/redemption tests.

## Acceptance evidence for the activation increment

| Case | Required result |
| --- | --- |
| Complete single and mixed-cart paid orders | Exactly one activation covers every original and exact entitlement; prior evidence stays byte-for-byte unchanged. |
| One pending or quarantined contract in a mixed cart | No activation for any line. No document generation as a side effect. |
| Missing/corrupt original or purchased asset | No activation; restore-only recovery accepts only the same original/revision bytes. |
| Warm integrity cache followed by same-size corruption | Fresh activation hash detects the change; cached success cannot grant eligibility. |
| Public roots/links, symlink components, hardlinks, unsafe modes, path/descriptor replacement during hashing | Verification fails without publication or repair. |
| Changed grant/profile/result/entitlement/provenance/storage descriptors between preflight and locked reread | No activation and no mutation of original evidence. |
| Stale verification window or clock reversal | No activation; a fresh verification attempt is required. |
| Concurrent independent MySQL activation creators | One immutable proof and one audit; both callers can read the same winner. |
| Failure before commit, duplicate command, process interruption | Atomic absence or the same retained activation; no partial entitlement authority. |
| Policy withdrawal / wrong account / production mode | New activation denied. Historical metadata stays readable without current enablement. |
| Later catalog/license/customer edits | Retained graph and original output remain authoritative; no successor substitution. |
| Later file loss after activation | Metadata preserves the historical decision; future physical access fails closed. |
| Owner status and command output | Read-only/private projections and opaque bounded outcomes; no filesystem paths or download links. |
| ORM/direct SQL mutation and populated migration down | Retained proof cannot be updated/deleted or silently rolled back. |

Run focused domain/schema cases during implementation, then the actual full MySQL/SQLite suites, independent MySQL races, frontend/browser checks if status changes, and independent review of the final candidate. Record counts only after execution. Completing this proof advances WP-08; authenticated delivery, recovery, restrictions, production policy and the rest of the 14-package plan remain open.
