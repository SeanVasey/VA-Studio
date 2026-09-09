# Durable records and transaction contracts

Status: **Proposed schema contract**, 2026-09-04. Table names below describe target concepts; migrations and models are authoritative for the implemented schema.

## Aggregate map

| Aggregate / records | Required fields and constraints |
| --- | --- |
| Product and track metadata | Stable internal/public IDs, type, slug and slug history, title, contributors, BPM/key/duration, visibility and publication timestamps. Public reads include only published, visible records. |
| Media asset revision | Immutable object key, role, SHA-256, byte length, sniffed MIME, revision, processing status, duration/format and source provenance. No overwrite of a purchased revision. |
| Rights declaration | Product/right scope, owner/contributors, sample disclosure, evidence reference, reviewer and effective status. Private source details stay out of public props. |
| License template/version | Stable template ID, monotonic version, authored source, structured schema and hashes, renderer reference, approval evidence and effective interval. Published substantive fields never mutate. |
| Product-license commercial revision | Product, immutable license version, integer price/currency, effective window, deliverable manifest and shared exclusive-scope ID if applicable. |
| Quote | Opaque ID, buyer/session ownership, line snapshots, exact asset revisions, prices/discount trace, currency, taxes or pinned tax-authorization envelope, disclosure/assent versions, expiration and canonical hash. |
| Order/line | Unique quote/session binding; private buyer/seller legal snapshots; immutable offered terms, resolved assets and amount calculation; independent order and payment status. |
| Provider inbox/payment | Environment/provider/account scope, event ID, payment-object ID, payload hash/private retention reference, verification and processing state. Unique event key plus unique confirmed-payment effect. |
| Rights inventory/reservation | One row per underlying exclusivity scope; state, holder, expiration, version and sold-order reference. All mutually exclusive SKUs share this row. |
| Grant/render input/contract | One grant per paid order line; snapshot hash, finalization time, frozen renderer input/version, private original document object/hash. Retain historic originals. |
| Entitlement/download issuance | Unique order-line + asset revision + role; owner/grant, active/pending/restricted status, policy and optional cap/expiry. Append-only issuance records and atomic limit enforcement. |
| Outbox/audit | Stable event ID, schema version, aggregate/version, correlation, causal command, actor, timestamp, safe payload, retry state. Audit omits unnecessary PII and secrets. |
| Consent/legacy mapping | Purpose-specific consent status/source/time/policy; source-system + source-ID uniqueness, import run, source hash, exact historical evidence and reconciliation state. |
| Membership credit ledger | Immutable movements keyed by source invoice/redemption; plan version, benefit scope, effective period, reservation/consumption/expiry/reversal; balance derived from authoritative movements. |
| Site release | Versioned navigation/content/media/SEO snapshot, preview evidence, published pointer and rollback reference. |

Use foreign keys, restrictive delete behavior and unique indexes for evidence records. Model immutability must cover bulk updates/imports and ordinary ORM saves; application guards alone cannot substitute for documented database/write-path enforcement. Avoid cascading deletes through quotes, orders, grants, contracts and asset revisions.

## Money and canonical snapshots

Amounts are integer minor units with an explicit ISO currency; never floats. Validate the currency exponent, bounds and rounding policy. A multi-currency cart is rejected or split explicitly. Store subtotal, promotion allocations, taxable base, tax, shipping where applicable and final amount with their rule versions.

JSON canonicalization is a versioned project function: recursively sort object keys, preserve array order, encode UTF-8 with an explicit normalization policy, normalize timestamps to UTC, distinguish null from absence, and serialize monetary numbers as integers. Store the canonicalization version with SHA-256 hashes; test repeated hashing and meaningful-difference cases.

Checkout creation freezes offered product/license/deliverable/price/disclosure data. If the hosted provider determines tax from the buyer address later, freeze the approved tax calculation policy and allowed basis in the quote; finalization appends an immutable authoritative tax/payment snapshot that the original quote references. Do not mutate an already frozen quote or accept arbitrary provider totals. Verify tax calculation, expected subtotal/discounts/currency and total against the configured policy. A mismatch enters paid_exception.

## License lifecycle

Draft → legal_review → approved → published → superseded. A published version is retained and can only be superseded by a new reviewed version. Lifecycle metadata such as supersession linkage is separately append-only; it must not rewrite the published legal content or approval evidence.

The version binds authored legal source, normalized fields, allowed variables, buyer-facing bullets, deliverable roles and renderer. Representative test documents must agree with all of those. An approved source with conflicting UI claims cannot pass publication. Unreviewed local fixtures remain visibly nonbinding and cannot enable a live offer.

## Shared exclusive inventory

The lock scope is the underlying composition/recording/product right, never an individual price or license SKU. Related variants that cannot legally be sold twice reference one inventory row.

Proposed reservation algorithm:

1. Begin a MySQL transaction and acquire the relevant inventory row with FOR UPDATE.
2. Recheck active offer, cleared rights, deliverables, quote ownership and current inventory.
3. Resolve an expired reservation under the recorded policy. Reject a live reservation owned by another quote or any sold scope.
4. Create or return the same reservation for the same idempotent command; record expiration and quote.
5. Persist pending-checkout intent/outbox, then commit. Create the hosted session outside the database lock with a stable idempotency key.
6. On finalization, lock inventory again and require a valid reservation for this order under the approved time/payment policy. Mark sold atomically with grant creation.

A timed-out provider request is reconciled before creating another session. Reservation extension, provider-pending grace, release, late success and overrides require explicit policy. All lock acquisition follows a stable sorted scope order for multi-line carts to reduce deadlocks; retry deadlocks only with idempotent commands.

If a paid event arrives after expiry or another sale, persist payment and paid_exception, creating no grant or entitlement. Earlier valid non-exclusive grants survive a successful exclusive sale. A non-exclusive order still pending at that sale requires a defined cutoff rule; absent such a rule, it must not silently issue new rights after the exclusive sold state.

Concurrency acceptance is on MySQL, with independent connections/processes and barriers: two different exclusive SKUs for the same scope, simultaneous checkout, retries, expired reservation, delayed successful payment and administrative hold. At most one valid exclusive grant results. SQLite tests cannot establish MySQL lock correctness. [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).

## Payment finalization and fulfillment

Independent states:

- Inbox: received → processing → processed, with retryable or terminal failure.
- Payment: pending/confirmed/partially_refunded/refunded/disputed, preserving every confirmed effect.
- Order: payment_pending → paid → fulfilled, or paid_exception/payment_failed/cancelled.
- Contract: pending → rendered → verified, or failed.
- Entitlement: pending → active, optionally restricted/expired/revoked under policy.

The webhook receiver checks raw-body signature, environment/account scope and event validity, then stores a unique durable inbox row. It returns success only after persistence succeeds. Duplicate delivery returns the original receipt result without a second business effect.

The processor obtains authoritative payment state through the adapter outside long database locks. Inside a transaction it locks order/inventory, verifies payment-object identity, environment, amount/currency, quote binding and state, then inserts or finds the unique confirmed payment. Multiple event IDs about one payment cannot create multiple grants.

If all rights/assets/inventory checks pass, the same transaction marks the order paid, creates one grant per line, freezes rendering input, creates pending entitlements and outbox rows. If any safety precondition fails after confirmed payment, the same transaction records paid_exception and an operator outbox event without grant/document/entitlement creation. Do not partly fulfill a mixed cart unless a separately reviewed policy supports that behavior.

An idempotent worker renders the contract from the frozen input. Another guarded command activates fulfillment only once every required contract and asset entitlement is durable. Notifications publish after commit from the outbox. The success page merely reads order status. Out-of-order refunds/disputes are processed through their own policy transitions rather than overwriting settled payment history. Stripe documents signature verification and duplicate/unordered delivery; application business idempotency remains our responsibility. [Stripe webhooks](https://docs.stripe.com/webhooks).

## Contract and delivery evidence

Pin renderer, template/clause version, locale/timezone, fonts/styles/artwork and input. No live external fetches during rendering. Store original PDF bytes privately with their hash. If renderer metadata prevents byte-identical rerenders, define a normalized comparison while preserving the original exact bytes/hash as evidence.

A download request authenticates/claims the buyer, authorizes the entitlement, checks policy/expiry and atomically reserves an issuance within any cap. It then signs the exact asset revision using a short-lived URL; proposed default maximum is 60 seconds, configurable after provider verification. Signed URLs are bearer capabilities until expiry and must not enter logs, analytics or HTML caches. If signing fails after reservation, record failure and reconcile the slot under the issuance policy; repeated requests do not bypass limits.

Historical purchased assets and original contracts are never regenerated from current settings. A refund does not automatically erase a grant or restore an exclusive. Payment, rights and download effects require separate reviewed transitions, reasons and notices.

## Stems recording evidence

`media_assets.parent_asset_id` continues to identify the processing source. A ready stems ZIP points to its quarantined ZIP source, never to a WAV. The additive `stems_recordings` contract supplies the distinct recording relationship: unique stems asset ID, same-track master and retained preview IDs, WAV source ID, verified operator/time/reference, canonicalization version, canonical evidence and SHA-256. Model guards and MySQL/SQLite triggers reject updates/deletes; foreign keys retain referenced records. No migration infers musical identity.

Binding requires an explicit operator attestation against the current preview/master processing run, while holding the shared track lock. Non-stems offer asset snapshots retain their existing shape. Stems entries add `recording_binding` containing association/stems/master/preview/source IDs and evidence hash; no private object path or operator note enters the offer. New offer/quote promises freshly verify the retained master and preview bytes. A tag-only revision of the same source may reuse the attestation, but a different preview still changes the offer snapshot; a new WAV source requires new association evidence.
