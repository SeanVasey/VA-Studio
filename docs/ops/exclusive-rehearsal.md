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
- **Staging admission.** The code admits the test chain wherever `TestEnvironment` admits test commerce, staging included (`RefundDisputePolicy.php:112`), but the staging deploy gate holds `VASEY_TEST_REFUND_DISPUTE_ENABLED` false: it is one of the twelve `CAPABILITY_FLAGS` (`scripts/ops/capability-boundary.php:35`) checked by `runtime.flag_off_*` in `ops/staging/validate-runtime.php`. The same is true of `VASEY_TEST_REFUND_RESOLUTION_ENABLED` (`:34`), whose path releases pending resources of an ungranted `paid_exception` only and "does not relist a completed exclusive sale" (`docs/test-refunded-exception-resolution.md`).

### The owner rule this follows

SQ-13 (decision register): availability after a refund is "reviewed individually, never reopened while there is any sign of use". DD-17: an exclusive is offered again only after operator review. No code reopens one, in the test chain or in production; the production tax chain sells no exclusive at all (C4).

### Answer

There is no existing way to rehearse "buy Exclusive → track locked → refund → track sellable again". The refund half can be rehearsed only after the refund/dispute capability is admitted on staging, and even then it ends, truthfully, with the track still locked and a recorded reopening decision. The lock half can be rehearsed today, at the cost of one track for good.

## 2. Options

### A. Burn one track on purpose (recommended)

No new mechanism. Sean picks one staging track he is willing to lose from staging sale and buys its Exclusive in test mode. He sees checkout, the exclusive contract, delivery, and the lock (every other licence on that track refused). If Sean also wants the refund half, a follow-up lane admits `VASEY_TEST_REFUND_DISPUTE_ENABLED` on staging as an optional default-off capability, as PR #333 did for unpaid release: move it from `CAPABILITY_FLAGS` to `STAGING_OPTIONAL_FLAGS` with `RefundDisputePolicy` as its admitting class, add a production hold, state the four DD-16 values on the host, and test in `tests/ops`. The production tax chain's admission (`RefundDisputePolicy::productionAccount`, literal `local`/`testing`) stays as it is. The rehearsal then shows refund in Stripe → check → suspend downloads → record reopening decision → track still locked. That is the production behaviour, so it is true evidence. Staging is not production; the track is unaffected there.

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
3. Admit the refund/dispute lifecycle on staging as an optional, default-off capability so the refund half can be rehearsed? Recommended: yes, as its own reviewed lane.
4. Build the rehearsal reset (option B)? Recommended: no; it changes the shared double-sale guard to rehearse something production will not do.
