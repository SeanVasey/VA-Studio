# [WP-06] Server quotes, promotion calculations and exclusive reservations

Status: **Provisional reviews, disclosure, pricing/test-tax, promotion holds and shared inventory are merged through PR #44. PR #48 merged inactive scope-bound exclusive preparation after CI and independent review. PR #49 implements atomic test pricing/promotion/inventory; its PR retains final acceptance evidence.** Published customer quotes remain non-exclusive and non-payable; exclusive activation/quote/pricing integration and the broad work package are not complete.

- Suggested issue title: `[WP-06] Server quotes, promotion calculations and exclusive reservations`
- Phase: 2
- Dependencies: WP-03 and WP-04. U-08 governs live exclusive behavior.
- Suggested branch: `work/wp-06-quotes-promotions-and-exclusive-reservations`
- Implementation paths: app/Domain/Commerce quotes/pricing/reservations, app/Support/Money, database/migrations and MySQL concurrency tests.

## Problem

A buyer must accept one authoritative price/rights snapshot, and simultaneous offers must never sell the same exclusive twice.

## First reviewable increment

Review 1–10 selected non-exclusive track offers against the server's current published revisions and retain immutable commercial/license/asset snapshots. Keep tax and total unknown and checkout disabled while production policy and the later transaction contracts are developed.

The original wider milestone—frozen assent disclosure plus reservation of the shared exclusive scope—remains dependent work. A provisional selection review does not satisfy that milestone.

## Scope

- Integer money, validated currency, effective-dated prices and promotion calculation trace; no client-authoritative totals.
- Frozen quote/hash/expiry and tax-policy envelope, exact disclosure versions and buyer/session ownership.
- Shared inventory row across all exclusive SKU variants; transactional reservation and deterministic lock ordering.
- Idempotent checkout intent with policy-based expiration; basic promotion limits/allocations under atomic guards.

## Current increment — 2026-09-06

- Strict identifier-only input pins each track, offer, published offer revision and license version; 1–10 unique tracks, quantity one, positive USD non-exclusive offers only. The server rejects client totals, unknown fields, duplicate tracks and stale selections.
- Immutable quote/line records retain the exact server-owned commercial revision, license/review/rights/media evidence, canonical hash, issue time and expiry. Later edits cannot rewrite the retained evidence.
- Integer line amounts and checked subtotal; `taxStatus: "unresolved"`, `taxMinor: null`, `totalMinor: null` and `payable: false`. No promotion, tax exemption or payable amount is inferred.
- Session/authentication-bound ownership and owner-scoped idempotency. Equivalent canonical selections reuse an eligible existing result; the same key with a different selection conflicts. Expiry or changed availability requires restart.
- `POST /quotes` and owner-checked `GET /quotes/{publicUUID}` project only safe customer fields, with CSRF, rate limits and private/no-store responses. Browser **Review selection** displays the server-reviewed subtotal while checkout remains disabled.
- A fixed 15-minute provisional lifetime is a development behavior, not U-08 reservation policy or a production price guarantee. No buyer legal identity, assent, order, payment, grant or entitlement is created.

See [Provisional selection reviews](../provisional-quotes.md) for the exact implemented HTTP contract and recovery behavior. The proposed architecture interfaces describe the fuller target and do not supersede this deliberately smaller schema.

## Remaining work within WP-06 and dependencies

### Frozen quote disclosure increment — 2026-09-12

The quote review exposes an on-demand full license source for its exact immutable line, with quote/offer/license identities, expiry and a fingerprint of the public disclosure. Owner checks, current availability, hashes, session locking, rate limiting and generic private failures are reused from the existing quote reader. New UI validates the quote envelope and discards obsolete text on selection/review changes. The [contract and verification scope](../quote-license-disclosure.md) distinguishes disclosure from assent, payable tax or an executed contract. This is the next bounded WP-06 prerequisite after WP-05's full public offer disclosure; it creates no new quote schema or live policy. Final candidate evidence belongs in its PR.

### Pricing/tax evidence increment — 2026-09-12

The [pricing contract](../quote-pricing.md) and [D-08](../architecture/D-08-quote-pricing-evidence.md) add one immutable calculation beside an owned quote. The server freezes exact amounts/disclosures, an explicit policy/hash, rounding trace and expiry; current policies are local/testing-only. Unknown tax stays null. Unique quote linkage and retained quote locks serialize first requests. The private API rejects client calculation inputs. An internal historical amount comparator produces mismatch evidence for a later WP-07 caller; it does not verify a provider or authorize a grant. No promotion, live tax choice or price guarantee is inferred. Tests include MySQL first-write races, immutable constraints, safe ownership, rounding, policy drift and settlement mismatches; the PR records actual final candidate results.

PR #42 merged this pricing increment with passing MySQL/SQLite/frontend/browser/build/audit CI and independent review. Its PR and issue #6 retain the exact source and run evidence.

### Promotion pricing and usage holds — 2026-09-14

The current [promotion contract](../promotion-pricing.md) and [D-09](../architecture/D-09-promotion-usage.md) add exact v2 allocation and campaign capacity while preserving v1 pricing. Explicit test policies select eligible offers, a fixed or capped-percentage discount, dates, no stacking and a lifetime use limit. One guarded hold per pricing is created atomically; current locking reads enforce the shared cap. Unstarted expiry releases capacity by captured rule; an internal attempt binding keeps pending capacity occupied indefinitely until WP-07 verified reconciliation. It does not count as paid redemption or provider verification.

PR #43 merged the owner-checked code-only API, immutable policy/hold identity and replay/conflict/expiry behavior after final CI and independent review. Its exact evidence is retained in the PR and issue #6. Bind these guards to WP-07 order/payment/terminal usage effects after the inventory dependencies. Operator promotion authoring and per-customer policy remain WP-09/U-07 requirements. The wider acceptance criteria stay open.

### Shared inventory foundation — 2026-09-17

The [inventory contract](../shared-rights-inventory.md) and [D-10](../architecture/D-10-shared-inventory.md) define immutable underlying scope identity, audited exact revision links and staff block/version controls. Internal local/testing commands validate quote ownership/current license/media evidence, acquire sorted scope locks, preserve one reservation per quote, expire reusable holds irreversibly and retain pending attempts. New MySQL process races and SQLite/schema cases accompany the candidate; executed results belong in its PR. These are inventory exercises on existing non-exclusive provisional quotes, not exclusive licenses or an enabled checkout.

Next: versioned exclusive offer/quote snapshots and inventory-aware publication/selection/pricing. Preserve original readers and freeze exact scope linkage, then integrate the primitives with orders/assent and provider reconciliation. Complete sold/grant, paid-exception and verified release paths in WP-07; keep U-08 production policy explicit.

### Scope-bound exclusive preparation — 2026-09-23

PR #44 merged the inventory foundation with the actual CI and source-review evidence recorded in that PR. The [next preparation increment](../exclusive-offer-preparation.md) freezes an explicitly reviewed exclusive license, exact assets, immutable underlying scope and private linkage reference hash in an inactive v2 commercial revision. The revision, link, pointer and audits commit together, and exact retries reuse the same evidence. Shared scopes may back several prepared variants without reserving or selling them. V1 non-exclusive publication/readers remain supported and public readers continue rejecting v2 preparations.

The command is internal and local/testing only; there is no production policy, customer route or activation toggle. The [D-11 boundary](../architecture/D-11-exclusive-offer-preparation.md) separates preparation from activation to avoid offering a license that quote/pricing cannot honor. Adversarial tests cover identity, authorized staff, blocked scopes, file digests, license expiry, rollback, SQL evidence guards, privacy and historical compatibility. Three independent-process MySQL cases cover duplicate preparation, shared variants and administrative blocks; executed results and review status belong in the PR.

Next: versioned exclusive activation, quote/disclosure and pricing with atomic inventory holds, discount eligibility and explicit pending non-exclusive cutoff. Complete WP-07 verified terminal effects and WP-08 delivery afterward. This preparation does not satisfy the package's sale/reservation acceptance criteria by itself.

### Atomic test pricing and inventory — 2026-09-23

[PR #49](https://github.com/VASEYDEV/VASEYAUDIO/pull/49) and the [coordinator contract](../atomic-priced-inventory.md) compose exact pricing, optional promotion usage and shared inventory in one outer transaction. Inventory failure cannot leave a newly created price or consumed promotion capacity, and a failed inventory attempt rolls back the promotion pending transition and audit. Both resources must share lifecycle and attempt identity; original snapshots and expiry remain intact. Scope waits are followed by a final pricing-expiry check. MySQL process tests exercise shared scopes, campaign caps and retries; actual results belong in the integrating PR.

This remains internal local/testing orchestration on existing provisional non-exclusive quotes. It adds no activation, buyer order, provider call or terminal rights effect. The next dependency remains versioned exclusive activation/quote/disclosure/pricing, promotion eligibility and pending non-exclusive cutoff, followed by WP-07/WP-08. Keep this package open and preserve all broader acceptance criteria.

### Remaining requirements

- Approved price/promotion policies, effective dates, allocation and rounding trace, stacking rules, redemption caps and independent-connection race tests.
- A complete tax-policy envelope with authoritative calculation and permitted finalization rules. Unknown tax cannot be treated as zero.
- Buyer/session purchase and legal-identity policy, seller/buyer snapshots, exact disclosure versions and explicit assent; integrate immutable order creation under WP-07.
- Shared underlying exclusive inventory, deterministic reservation locks, owner-approved TTL/late-payment/pending non-exclusive cutoff policy under U-08, and paid-exception handoff.
- MySQL independent-connection races for exclusive variants, coupon caps, retries, expiry, administrative holds and payment timing. Functional tests on both databases do not prove these unimplemented mechanisms.

## Acceptance criteria

- [ ] Price/terms/assets cannot drift after quote creation; expired or changed requests receive explicit restart errors.
- [ ] Same idempotency key/body returns the same result; different body returns conflict.
- [ ] Two distinct exclusive SKUs sharing a scope cannot both reserve/sell; repeated same-buyer requests remain safe.
- [ ] Earlier valid non-exclusive grants are unaffected; pending non-exclusive cutoff is explicit before live exclusives.
- [ ] Coupon limits and multi-line rounding remain correct under concurrency; no discount bypasses inventory/readiness.

## Verification

The first provisional-review increment is implemented in [PR #23](https://github.com/VASEYDEV/VASEYAUDIO/pull/23). Corrected candidate `24e7221bdd81aefdd087c336cf43dacf9394abcb` passed both GitHub CI runs: MySQL 127 tests / 964 assertions including all three independent-process quote races; SQLite 124 tests / 919 assertions with 3 explicit MySQL-only skips; 27 frontend tests; TypeScript/production build; and production dependency audits. Independent review found no unresolved blocker within this provisional scope. See [executed evidence](../verification/provisional-quotes.md) for the exact source tree, run links and correction history. These provisional records do not establish payable tax/disclosure/order behavior or exclusive reservations.

The integrating PR records the final head and any documentation-only follow-up. Current-increment evidence covers frozen amount/license/assets, malformed/client-price requests, canonical request identity, owner isolation, idempotent retries/conflicts, expiry, changed publication/readiness, immutable writes and the safe storefront/API projection. The recorded passes apply only to the implemented provisional increment.

The broader package still requires domain pricing/canonicalization fixtures and MySQL independent-connection races for exclusive variants, coupon caps, expiry, retries and administrative hold. SQLite alone is insufficient. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

For the provisional increment, withdraw review creation/read controls with a reviewed application release and retain immutable evidence; checkout stays disabled. Do not rewrite or delete old quote snapshots to fit new offers.

Once payment/reservation workflows exist, disable new quote/session creation and expire unstarted intents under policy. Do not release inventory with a potentially successful in-flight payment until provider reconciliation.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-06 with the data-contract algorithms. Keep money exact, snapshot every offered term and asset, and lock the shared underlying rights scope. Prove races on MySQL and expose paid-exception inputs for later finalization rather than assuming late payments cannot happen.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## Current sequencing — 2026-09-09

Sean requested returning to the pre-Stripe development order. Retain implemented prerequisites, but defer further payable checkout/order work until the earlier foundation and Phase 1 gaps are addressed. The [ordered status](../development-order.md) supersedes earlier “next increment” priority notes; it does not remove this package’s scope.
