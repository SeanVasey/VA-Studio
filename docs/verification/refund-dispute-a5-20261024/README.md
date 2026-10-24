# Lane A5 work package: refund and dispute lifecycle, staff customer/order administration

Status: **in progress** on branch `harness/refund-dispute-a5-20261024`, cut from `origin/main` `e5d0d3f2`. This statement was committed before any implementation. Results, commands and untested conditions are added at the end as they are produced.

Scope rows: FP-068 *Refund and dispute lifecycle* (T21) and FP-040 *Orders customers contracts and refunds* (T19) in `docs/remaining-parity-coverage.csv`; lane A5 in `docs/handoff/2026-10-24/COMPLETION-PLAN.md`.

## Boundary

- Test commerce only: the existing Stripe-test chain (`orders` → `verified_payments` → `order_finalizations` → `license_grants`, test owner delivery). The feature is **default-off** and admitted only where `TestEnvironment::admitsTestCommerce()` holds, with `payments.stripe.mode = test` and a valid own account.
- **No provider writes.** The application never sends a refund, accepts a dispute or submits dispute evidence. Staff issue refunds in the Stripe dashboard; the application observes them. This follows `docs/test-refunded-exception-resolution.md` (`provider_writes: forbidden`) and `docs/test-payment-financial-observation.md`.
- Provider facts come only from the existing authenticated GET path: `VerifyTestPayment::inspect` (account, Checkout Session, PaymentIntent binding) followed by `StripeFinancialInspectionGateway::financialState` (PaymentIntent, Charge, complete refund and dispute lists, repeated Charge) normalized by `PaymentFinancialEvidence::capture`. The pinned SDK (21.3.2, API `2026-08-26.dahlia`) and transport are unchanged. Provider I/O never runs inside an application transaction. Webhooks are not consumed.
- **Policy values are unset.** Sean has not approved a refund or dispute policy; the proposal in `docs/legal/TERMS.md` section 10 is not approved and is not encoded. Every write refuses unless all four `MachinePolicyV1` `refunds_and_disputes` values are configured with a supported value: `refund_authority` (`authoritative_provider_operator_review`), `partial_refunds` (`exact_line_allocation`), `dispute_access` (`operator_review` | `temporarily_block_while_reconciling`), `exclusive_reopening` (`remain_sold` | `qualified_operator_review`). Unset, empty or unknown values refuse.
- Licenses, order snapshots, verified payments, finalizations, grants, exclusive sales and original contracts are never rewritten or deleted. A refund or dispute is recorded as new evidence. Effects are **technical access holds** on test delivery, never a license revocation: no policy value authorizes legal revocation.
- Exclusive sales stay recorded. `remain_sold` keeps them sold. `qualified_operator_review` lets staff record a qualified, audited reopening decision; changing catalog availability after a refund is U-08 (completion plan owner decision 11) and is not performed here. Inventory reservations and promotion uses are not restocked.

## Files owned

New:
- `config/refund-dispute.php`
- `database/migrations/2026_10_24_264000_refund_dispute_lifecycle.php` (**schema change, additive and forward-only; needs independent review**)
- `app/Domain/Commerce/RefundDispute/*` (policy, state machine, provider observation ledger, allocation, effects, access holds, staff read model)
- `app/Filament/Resources/StaffCustomerResource.php` and its `Pages/`, plus `resources/views/admin/staff-customer-*.blade.php`
- `tests/Feature/RefundDispute/*`, `tests/Support/RefundDisputeFixtures.php`
- `docs/refund-dispute-lifecycle.md`, this packet

Edited (narrow):
- `app/Domain/Delivery/IssueTestDelivery.php`, `app/Domain/Delivery/RedeemTestDelivery.php`, `app/Domain/Delivery/ReadTestOwnerDelivery.php`: one hold check each inside the existing Order-locked transaction or projection.
- `.env.example` (new default-off variables), `CHANGELOG.md` (Unreleased).
- Census/selection files only if native-only tests are added: `scripts/ci/database-sqlite-skips.json` (append-only), the regenerated MySQL selection, and count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml`.

Not touched: `routes/web.php`, `bootstrap/app.php`, `config/production-memberships.php`, `app/Http/Membership/*`, membership domain (C4e); redirects/metadata/JSON-LD (T36); `app/Domain/Migration/*` (MIG). No route or provider registration is needed (Filament discovers resources).

## Assumptions

1. A complete provider observation is a snapshot, so the state machine is evaluated against the latest complete observation plus the deduplicated history of provider objects, not against event arrival order. Out-of-order arrival therefore cannot reorder effects; an impossible regression (amount change, vanished object, disallowed status transition) is `attention` and applies no effect.
2. Line totals come from the verified original order payload (`ReadOrder::verify`, `lines[].pricing.total_minor`, tax included). Their sum must equal the verified payment amount and currency, otherwise allocation refuses.
3. A full refund (succeeded refunds equal the paid amount) and a single-line order each have exactly one exact allocation, so those are allocated automatically. Any other partial refund needs an explicit staff allocation that sums exactly to the refund.
4. Staff authority is the existing `administer-catalog` gate plus the admin panel MFA rule (`AdminMultiFactor`), re-read under a row lock on every write; there is no new role system.

## Acceptance criteria

- Disabled flag, non-test environment or mode, or any unset/unknown policy value: every write refuses, with no provider call and no row written.
- Observation: append-only, encrypted, hashed ledger row per staff request, bound to actor, order, request UUID, ledger sequence, policy snapshot and observation time; exact replay returns the retained result without a provider call; a changed request conflicts; stale sequence refuses.
- Provider refunds and disputes deduplicated by object and status; replayed or duplicated observations add no events or effects.
- Partial refunds over the paid amount, currency mismatch, amount drift, vanished objects and disallowed transitions are `attention` with no effect.
- Allocation: sums exactly, never exceeds a line, never reallocates; automatic only where unique.
- Disputes: `temporarily_block_while_reconciling` holds the whole order's downloads while a dispute is open, releases on won/closed and keeps the hold on lost; `operator_review` only records review-required effects and lets staff place a hold.
- Refunded lines: staff may suspend downloads only for a fully refunded line or after a lost dispute; never automatic under `authoritative_provider_operator_review`.
- Exclusive lines: `remain_sold` records the retained sale; `qualified_operator_review` permits a recorded decision only when the line is fully refunded or the dispute lost, no dispute is open and the grant's downloads are held. Other grants on the same rights scope are listed as retained, never revoked.
- Delivery: an active hold refuses new authorizations and redemption of already-issued ones, serialized by the existing Order row lock.
- Staff without authority or required MFA are refused on reads and writes; every write has an audit event; originals are byte-identical after every path.
- Filament: read-mostly customer list and detail (orders, grants, refunds/disputes, holds, effects, download evidence), with actions only through the domain services.

## Tests

- SQLite: new feature suites under `tests/Feature/RefundDispute/`, plus the touched existing suites (owner delivery, financial observation, refund resolution, exception operations, test commerce operations, order finalization migration roundtrips).
- Native MySQL 8.4.11 (private server): refund hold racing a delivery authorization and a redemption, and an exclusive reopening decision racing a competing exclusive purchase; writer latency measured.
- Safeguards: `scripts/ci/test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py`; `composer validate --strict`.
- Mutation checks on the money and access guards, recorded below.
