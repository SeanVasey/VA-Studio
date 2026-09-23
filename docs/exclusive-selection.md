# Exclusive selection and atomic test pricing

This WP-06 increment connects prepared exclusive revisions to activation, catalog selection, full disclosure, integer pricing, promotions and shared inventory. It runs only in `local`/`testing` with explicitly configured policies. Checkout remains disabled and every quote/price remains non-payable. Activation is an internal staff command, not a production sales switch.

## Activation and scope coverage

`ActivateExclusiveOffer::handle(offer, expectedRevisionId, actor)` requires catalog authorization and the exact current prepared revision. It locks track → offer → reviewed license/latest rights → shared scope, checks the frozen evidence, freshly hashes the revision's exact files and recording dependencies, and rechecks effective terms. Editable draft fields cannot change the activated promise.

Every active sibling offer on that track must already have an explicit immutable link to the same scope. The command never infers that association from a track ID or filename. An occupied scope rejects activation: a live held reservation or any pending inventory attempt cannot be displaced. Activation, its immutable evidence and audit commit together; matching retries reuse the activation record. Deactivation uses the existing audited command and retains evidence and reservations.

The additive `exclusive_activations` table retains revision/hash, scope, actor, timestamp and exact policy under restrictive foreign keys and SQL update/delete guards. Existing v2 preparation snapshots are unchanged. An `is_active` flag without intact activation evidence cannot make a preparation selectable.

Once a track has explicitly linked to an activated scope, unlinked commercial successors are unavailable to browsing and selection until staff explicitly link their exact revision. Activation rejects a different historically governed scope before writing new activation evidence. Ambiguous multiple scopes fail closed; this increment does not implement multi-right decomposition. Other track variants require explicit scope links and source evidence. This is not an authenticated audit proving that every historical product or obligation has been discovered.

## Explicit development policies

Set `VASEY_TEST_EXCLUSIVE_SELECTION_POLICY` to an object containing exactly:

```json
{"schema_version":1,"purpose":"test_exclusive_selection","non_exclusive_cutoff":"block_while_reserved","existing_pending":"retain_until_verified_resolution","discounts":"explicit_revision_only"}
```

The existing `VASEY_TEST_INVENTORY_POLICY` is also required when reviewing exclusive selections; it supplies an explicit test TTL and pending-retention rule. Neither configuration has a default. Missing/malformed configuration or production mode rejects the affected path. Tax configuration remains separately optional: absent tax still means unknown tax and total.

`block_while_reserved` is a conservative development rule. Linked non-exclusive and exclusive inventory exercises compete for the same scope. Unstarted expired holds can be retired by guarded acquisition; pending inventory attempts occupy their scopes indefinitely, including beyond quote expiry. Prior evidence is never rewritten or revoked. A standalone legacy promotion-only attempt is capacity evidence, not an inventory-backed purchase attempt or payment; it supplies no right to bypass the required aggregate order/intent binding in WP-07. Production U-08 cutoff, TTL, late-payment, refund and historical-grant decisions remain open.

## Versioned evidence

| Evidence | Retained behavior |
| --- | --- |
| Offer v1 | Existing non-exclusive commercial evidence remains intact. |
| Offer v2 + activation v1 | Exact prepared exclusive license/files/scope remain frozen; activation adds explicit selection-policy evidence. |
| Quote v1 | Non-exclusive review shape and original hash remain unchanged. Governed revisions still obey current scope availability. |
| Quote v2 | Any selection containing an exclusive freezes activation identity/hash, exact per-line scope bindings and both selection/inventory policies. Every line requires a link; duplicate scopes are rejected. |
| Disclosure v1/v2 | Existing v1 projection remains identical. V2 remains quote-owned, includes the exact full license text and `testOnly: true`, and hashes only public fields. No private scope or activation evidence is exposed. |
| Pricing v1/v2 | Original algorithms/readers remain unchanged. |
| Pricing v3 | Exclusive/mixed quote calculation preserves integer amounts, rounding, disclosure hashes, explicit policies and optional promotion allocation. Historical reproduction uses captured evidence rather than today's availability. |

Public quote creation still creates a selection review only. Its subtotal is provisional and it makes no inventory promise. Browsing and review availability are advisory reads with no early scope locks. An owner read excludes only its exact quote's reservation, never all reservations belonging to the same buyer. Another quote from the same owner still competes.

## Pricing, discounts and attempts

For quote v2, `PriceQuote::create`/`createWithPromotion` acquires shared inventory within the same transaction as first pricing and promotion capacity. All cart lines are reserved under the conservative development rule. Failure rolls back newly written pricing, campaign/use, claims and audits. The existing `ReservePricedQuote` coordinator supports these quotes and binds optional promotion/inventory resources to the same attempt.

Lock order remains quote → all sorted tracks/offers → pricing → campaign/use → all sorted scopes → reservation/claims. Nested reads operate on the same quote and already-retained earlier locks. Campaign locking serializes the later capacity read during attempt binding. Provider I/O remains outside this transaction and is not implemented by these commands.

Pricing GET verifies an existing reservation without creating a missing one or expiring/transitioning other reservations. Expired holds cannot be silently recreated for the old price. Policy drift, withdrawal, changed current revisions, blocked or competing inventory require restart; they do not rewrite historical pricing. Pricing expiry is checked again after scope acquisition. Quote, price and inventory retain their own frozen deadlines; none is extended by a retry.

V3 interprets existing campaign eligibility explicitly. `all_non_exclusive` excludes exclusive lines before minimum spend, percentage computation and allocation. An exclusive discount requires the exact revision in `offer_revisions`. Mixed carts retain deterministic allocations and tax-after-discount rounding. No default discount, zero tax or production campaign is inferred.

## Verification and rollback

Functional coverage includes exact activation, staff/environment/policy gates, unlinked siblings/successors, fresh file corruption and effective-time boundaries, safe owner HTTP projections, mixed-cart eligibility, duplicate scopes, policy drift, retained historical calculations, same-owner competition, both cutoff directions, unstarted expiry, pending retention and outer-transaction rollback. SQL guards and disposable empty-table migration roundtrips retain prior records.

Independent MySQL processes exercise duplicate exclusive pricing, variants sharing a scope, exclusive/non-exclusive competition, campaign caps, same/conflicting promoted attempts, duplicate activation, sibling publication and administrative blocking, including a deterministic barrier that commits a block before either activation can acquire its scope lock. SQLite explicitly skips these process races. Frontend tests cover supported and rejected disclosure versions and the visible test boundary; existing browser tests remain regression coverage, not physical-device or provider acceptance. The integrating PR records actual candidate/run counts and independent source review; test definitions alone are not executed evidence.

Withdraw new activation/selection configuration to stop this development path while retaining activation, quote, pricing, promotion and reservation history. Do not run migration `down` on useful evidence or release pending attempts based on a clock or code rollback. The empty-table roundtrip test is not a production removal procedure.

## Next dependency

WP-07 must bind buyer identity/assent, immutable order and attempt before hosted Stripe test checkout; verify authoritative provider/account/amount evidence; and implement durable idempotent sold/grant, paid-exception and verified unpaid release/redemption. WP-08 then supplies deterministic buyer contracts, exact entitlements and private downloads. Production policies and the broader migration, device, media, recovery and launch gates remain in the [ordered plan](development-order.md).
