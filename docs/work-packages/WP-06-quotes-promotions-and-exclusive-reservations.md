# [WP-06] Server quotes, promotion calculations and exclusive reservations

Status: **Provisional selection reviews merged; owned full-license disclosure implemented for candidate verification. The broader work package remains open.** The server freezes and revalidates selected published offer revisions, with a provisional USD subtotal. It does not calculate payable totals, collect assent, create orders or reserve exclusives. This issue is complete only when the full acceptance evidence below exists.

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
