# Rehearsing an Exclusive on staging

Written 2026-11-12 (lane BXR) for the staging alpha, where 32 active Exclusive offers ($1,500, Stripe test mode) exist and none has been bought. It records what the code does today, why no existing path makes a track sellable again after an Exclusive test purchase, a design for a rehearsal reset that is **not built**, and the owner decisions it needs. Source-read on `eaa48877`; nothing here was run against the staging host.

## 1. What exists

### The lock

- Finalizing a paid test order whose line snapshot is `exclusive` writes one `exclusive_sales` row (`app/Domain/Commerce/Finalization/FinalizeTestPayment.php:174-177`), after a locked read of the scope's existing sales (`:97`).
- From then on order preparation refuses every offer linked to that rights scope with `INVENTORY_UNAVAILABLE` (`app/Domain/Commerce/Inventory/ReserveQuoteInventory.php:66-70`). Selection, offer preparation and activation, and the publication manifest read the same table (`SelectionInventory.php:36,82`, `app/Domain/Catalog/ExclusiveOfferScope.php:41`, `PrepareExclusiveOffer.php:58`, `ActivateExclusiveOffer.php:50`, `ReadTrackPublicationManifest.php:211`).
- The database enforces it (`database/migrations/2026_09_26_000020_test_order_finalization.php`): `exclusive_sales.rights_scope_id` is `UNIQUE` (`:65`); update and delete are refused by trigger (`:77-79`); a new `license_grants` row is refused on a scope that has a sale (`:106-107`); and a sale row is accepted only for a `paid`, `mode = 'test'` finalization whose offer snapshot is exclusive (`:133-139`). So a second Exclusive on the same scope is impossible even in SQL, and no code path deletes or voids a sale.

### The refund and dispute path (`VASEY_TEST_REFUND_DISPUTE_EXCLUSIVE_REOPENING`)

Source: `app/Domain/Commerce/RefundDispute/`, `docs/refund-dispute-lifecycle.md`.

- **Values.** `remain_sold` or `qualified_operator_review` (`RefundDisputePolicy.php:24`); DD-16 chose `qualified_operator_review`. Under `remain_sold` the reopening command refuses.
- **Evidence.** A real Stripe test-mode refund (or a lost dispute) that Sean issues in the Stripe dashboard. The application sends nothing to Stripe; staff run "Check refunds and disputes", which reads PaymentIntent, Charge and the complete refund and dispute lists by authenticated GET and stores an accepted observation.
- **What "reopening" does.** `qualifyExclusiveReopening` (`RefundDisputeActions.php:115-137`) records one append-only effect `exclusive_reopening_qualified`, and only when the exclusive line is fully refunded or the dispute lost, no dispute is open, **and** staff have already held that grant's downloads. The effect lists every other grant on the scope as retained and states `exclusive_sale: retained`, `catalog_availability_changed: false`, `availability_decision: u08_owner_decision_required` (`:131-133`). The fixed policy boundary says `inventory_restock: never`, `license_revocation: never`, `catalog_reopening: not_performed_u08` (`RefundDisputePolicy.php:39-41`).
- **Grant, contract, delivery, library.** The grant, order, contract and exclusive sale are never written. Downloads stop only through a hold (staff "suspend line downloads", or an open dispute under `temporarily_block_while_reconciling`), enforced in `IssueTestDelivery` and `RedeemTestDelivery`. The buyer keeps the licence record and contract; the owner delivery page (`ReadTestOwnerDelivery`) reports the order unavailable only for an order-wide hold, and a single-grant hold is enforced when a download is issued or redeemed.
- **Audit.** Every write records `commerce.refund_dispute.<kind>` (`RefundDisputeEffects.php:192`) plus the append-only effect row; staff need the administer role and MFA, rechecked under lock.
- **Staging admission.** The code admits the test chain wherever `TestEnvironment` admits test commerce, staging included (`RefundDisputePolicy::account()`). Until 2026-11-12 the staging deploy gate held `VASEY_TEST_REFUND_DISPUTE_ENABLED` false as one of the `CAPABILITY_FLAGS`; it is now an optional staging capability, off by default (section 5). `VASEY_TEST_REFUND_RESOLUTION_ENABLED` stays held false: its path releases pending resources of an ungranted `paid_exception` only and "does not relist a completed exclusive sale" (`docs/test-refunded-exception-resolution.md`).

### The owner rule this follows

SQ-13 (decision register): availability after a refund is "reviewed individually, never reopened while there is any sign of use". DD-17: an exclusive is offered again only after operator review. No code reopens one, in the test chain or in production; the production tax chain sells no exclusive at all (C4).

### Answer

There is no existing way to rehearse "buy Exclusive → track locked → refund → track sellable again". The refund half can be rehearsed once the refund/dispute capability is turned on for the staging host (optional since 2026-11-12, section 5), and it ends, truthfully, with the track still locked and a recorded reopening decision. The lock half can be rehearsed today, at the cost of one track for good.

## 2. Options

### A. Burn one track on purpose (recommended)

No new mechanism. Sean picks one staging track he is willing to lose from staging sale and buys its Exclusive in test mode. He sees checkout, the exclusive contract, delivery, and the lock (every other licence on that track refused). For the refund half, `VASEY_TEST_REFUND_DISPUTE_ENABLED` is now an optional default-off staging capability, as PR #333 did for unpaid release (section 5): it moved from `CAPABILITY_FLAGS` to `STAGING_OPTIONAL_FLAGS` with `RefundDisputePolicy` as its admitting class and the four DD-16 values required, production holds it false, and `tests/ops` covers it. The production tax chain's admission (`RefundDisputePolicy::productionAccount`, literal `local`/`testing`) stays as it is. The rehearsal then shows refund in Stripe → check → suspend downloads → record reopening decision → track still locked. That is the production behaviour, so it is true evidence. Staging is not production; the track is unaffected there.

### B. Rehearsal-only reset (designed here, not built; not recommended)

What it would take, so that the size is visible:

- **Schema.** `exclusive_sales` cannot be voided or re-inserted (unique scope, immutable rows). A reset needs a new append-only table, for example `test_exclusive_rehearsal_resets` (one row per sale, unique `exclusive_sale_id`, actor, request UUID, reason, evidence hash). Its insert trigger joins `exclusive_sales → order_finalizations` and requires `mode = 'test'` and `policy_version = 'test-order-finalization-v1'`, following the guarded-insert pattern of `2026_11_11_001000_test_unsent_order_releases.php`. Production-chain sales never reach `exclusive_sales` (its own insert trigger requires a test finalization), and legacy exclusivity lives in `legacy_exclusivity_authority_events` (`ExclusivityAuthority`), so both are out of reach by construction. A second sale on the same scope also needs the `UNIQUE (rights_scope_id)` replaced by a partial rule ("at most one unreset sale per scope"), which MySQL can only enforce by trigger, plus a matching change to the `license_grants` insert trigger. That is a new migration altering two guards created on main.
- **Readers.** Every reader listed in section 1 must change from "a sale exists" to "an unreset sale exists". That is the core double-sale guard, in code shared with production; a mistake there is the "double exclusive sale" that DD-59 makes a cutover rollback trigger.
- **Grant, contract, downloads.** The reset refuses while any grant on the scope is live: it requires the existing refund/dispute evidence (fully refunded line, no open dispute, downloads held, and a recorded `exclusive_reopening_qualified` effect). It then marks the buyer's grant superseded for rehearsal through the new row, keeps a permanent hold so downloads stay refused, and the library shows "Rehearsal reset: test licence void". The contract PDF, order snapshot, grant and sale rows stay byte-identical. Leaving a valid-looking Exclusive in the buyer's library while the track sells again would be false evidence, so the reset must not exist without these.
- **Admission.** Default-off flag plus an exact JSON policy; `local`/`testing`/`staging` through `TestEnvironment::admitsTestCommerce()`; production held false (`LAUNCH_OFF_HOLDS`) and refused in the policy class; staff with the administer role and MFA rechecked in the service under lock; an audit event and the evidence row.
- **Serialization.** Same lock order as finalization and order preparation: User → Order → sorted `rights_scopes` rows `FOR UPDATE` → the scope's `exclusive_sales` → reservations. Native MySQL race tests: reset against concurrent order preparation on the scope, reset against concurrent finalization, and two resets.

Estimate: one migration with four guards, about ten production-shared readers changed, a staff command and page, and native race tests. It rehearses a behaviour production will never have.

### C. A rehearsal Exclusive that locks only itself (rejected)

An extra offer on a scope of its own would lock nothing else, so it proves nothing about the lock Sean wants to see.

## 3. Validator alignment (built in this lane)

The operator profile validator called the Exclusive selection policy "outside this profile" (`commerce.exclusive_selection_off`) while the deploy gate did not look at it at all. Both now apply one rule from `CapabilityBoundary::STAGING_OPTIONAL_POLICIES`: `VASEY_TEST_EXCLUSIVE_SELECTION_POLICY` empty is off; set passes only when `ExclusiveSelectionPolicy::current()` accepts it.

- Profile validator: check `commerce.exclusive_selection`, with a PASS line that says whether Exclusive selection is off or ON (and that an Exclusive purchase locks its track), or a FAIL when the value is set but malformed.
- Deploy gate: `runtime.optional_vasey_test_exclusive_selection_policy`, so a malformed policy is refused at deploy instead of at the first exclusive quote.
- Production is unchanged: `scripts/ops/private-server-preflight.php` keeps the key blank (`inactive_vasey_test_exclusive_selection_policy`, now pinned in `tests/ops/test_production_runtime_validator.py`) and `InventoryPolicy::requireTestEnvironment()` refuses production in code.

## 4. Decisions for Sean

1. Accept that an Exclusive rehearsal permanently locks one staging track, with no reset? Recommended: yes (option A).
2. Which track, or none for now? Recommended: one real track you are content to stop selling on staging; production is unaffected.
3. Admit the refund/dispute lifecycle on staging as an optional, default-off capability so the refund half can be rehearsed? **Decided yes** (Sean, 2026-11-12). Built in its own lane: section 5. Turning it on for the staging host is a separate step after merge.
4. Build the rehearsal reset (option B)? Recommended: no; it changes the shared double-sale guard to rehearse something production will not do.

## 5. Rehearsing a refund on staging

Owner decision 3 (Sean, 2026-11-12): the test refund and dispute lifecycle is an **optional, default-off** staging capability. It is off until the operator turns it on for the host; both staging templates state it `false`.

### Turning it on

Set these five lines in the host's private `.env` (the test-commerce overlay, section 6, shows the four values commented), with Stripe in test mode, the own `acct_` account and test payment processing (`STRIPE_TEST_PAYMENT_PROCESSING_ENABLED=true`) already configured, then deploy. Without payment processing, "Check refunds and disputes" only records `unavailable`:

```
VASEY_TEST_REFUND_DISPUTE_ENABLED=true
VASEY_TEST_REFUND_DISPUTE_REFUND_AUTHORITY=authoritative_provider_operator_review
VASEY_TEST_REFUND_DISPUTE_PARTIAL_REFUNDS=exact_line_allocation
VASEY_TEST_REFUND_DISPUTE_DISPUTE_ACCESS=temporarily_block_while_reconciling
VASEY_TEST_REFUND_DISPUTE_EXCLUSIVE_REOPENING=qualified_operator_review
```

The staging deploy gate (`runtime.optional_vasey_test_refund_dispute_enabled` in `ops/staging/validate-runtime.php`) and the profile validator (`boundary.optional_capabilities_admitted` in `scripts/ops/validate-test-commerce-profile.php`) both apply `CapabilityBoundary::STAGING_OPTIONAL_FLAGS`: the flag passes as exactly `false`, or as exactly `true` when the real `RefundDisputePolicy::current()` accepts the configuration (test-commerce environment, Stripe test mode, `acct_` account, all four values set) **and** each value equals its DD-16 choice above. The class also supports `operator_review` and `remain_sold` for the test chain; staging refuses them, so the rehearsal runs the decided policy. A missing, blank, misspelled or other value refuses the deploy. Another spelling of the flag itself (`TRUE`, `True`) is refused by the profile validator's file check (`profile.optional_flags_explicit`) only: Laravel reads it as true, so the deploy gate and the application both treat it as on. Before turning it off, release any active download holds: a hold keeps blocking its downloads while the flag is off, and "Release a download hold" works only while the flag is on. To turn it off, set the flag back to `false` (the four values may stay; they do nothing while the flag is off).

Production is unchanged: `ops/production/validate-runtime.php` refuses the flag unless it is `false` (`holds.vasey_test_refund_dispute_enabled`, `LAUNCH_OFF_HOLDS`), and `RefundDisputePolicy` refuses production in code for both chains. `VASEY_TEST_REFUND_RESOLUTION_ENABLED` stays held false on staging.

### What staff see and need

- **Where.** Admin **Test commerce → Test orders and refunds** (`StaffOrderResource`). Commands on a paid test order: "Check refunds and disputes", "Allocate partial refund", "Suspend line downloads", "Hold order downloads for dispute" (refuses under DD-16, where an open dispute holds the order automatically), "Release a download hold" and "Record exclusive reopening decision". **Test customers** lists the same orders per customer. There is no console command for the test chain.
- **Who.** The administer role (`administer-catalog`) with MFA enrolled; staging requires staff MFA (`TestEnvironment::STAFF_MFA`). Every command rechecks the role and MFA in the domain service under lock, not only in the page.
- **Stripe.** Read only. "Check refunds and disputes" makes authenticated GETs to Stripe test mode (`GET /v1/account`, the Checkout Session, the PaymentIntent, the Charge, and the refund and dispute lists for that PaymentIntent). The application never creates, refunds or disputes anything in Stripe.

### The rehearsal

1. Buy a test order (any licence; an Exclusive only on the track Sean chose under decision 2) and let it reach "Delivery enabled".
2. **Sean** issues the refund in the **Stripe TEST dashboard** (Payments → the payment → Refund), full or partial. A test dispute uses Stripe's dispute test card at purchase instead.
3. Staff open **Test orders and refunds**, find the order and press **Check refunds and disputes**. The row shows the observed refund state (`partial` or `full`) and the dispute state. A full refund, or a partial refund on a single-line order, is allocated automatically; a partial refund across several lines needs **Allocate partial refund** with amounts that sum exactly to the refund.
4. For a fully refunded line, staff decide on downloads: **Suspend line downloads** holds that line's downloads. Nothing changes until they do.
5. For an Exclusive line, after it is fully refunded (or the dispute lost), no dispute is open and its downloads are held, staff may press **Record exclusive reopening decision**.

### What changes and what does not

- **Changes:** append-only ledger rows (observations, allocations, holds, effects) and an audit event per command (`commerce.refund_dispute.<kind>`); downloads of a held line or order are refused when issued or redeemed.
- **Does not change:** the order, payment, finalization, grant, licence record, contract and its PDF, and the buyer's library entry. No money moves in the application.
- **An Exclusive sale stays locked.** The reopening decision only records `exclusive_reopening_qualified` with `exclusive_sale: retained` and `catalog_availability_changed: false`. The `exclusive_sales` row is immutable, so every other licence on that track stays refused. Reopening a track is not built (option B, decision 4).
