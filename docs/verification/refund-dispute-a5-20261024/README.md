# Lane A5 work package: refund and dispute lifecycle, staff customer/order administration

Status: **implemented on branch `harness/refund-dispute-a5-20261024`, not pushed, not reviewed.** Cut from `origin/main` `e5d0d3f2`. The work-package statement below was committed first (`08b8b516`); results follow it. Tested source: `470a79f7bce978bb56f9527d8e964305f898e5a7`.

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
- `database/migrations/2026_10_24_264100_refund_dispute_lifecycle.php` (**schema change, additive and forward-only; needs independent review**)
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

## Results (2026-10-24)

All commands ran in `/home/user/wt/a5` on PHP 8.4.26 with a vendor tree copied from a worktree whose `composer.lock` is byte-identical (`diff` empty), `public/build` absent. SQLite runs used the direct-PHPUnit command from the lane brief (`APP_ENV=testing`, in-memory SQLite, `CI=true GITHUB_ACTIONS=true PAO_DISABLE=1`, `--fail-on-phpunit-warning`).

| Check | Source | Actual result |
| --- | --- | --- |
| Baseline before changes: `TestPaymentFinancialObservationTest`, `TestOwnerDeliveryTest` | `e5d0d3f2` | OK, 60 tests, 460 assertions |
| A5 focused SQLite: `tests/Feature/RefundDispute` (lifecycle 15, migration 2, administration 3, native 4 skipped), `tests/Unit/RefundDisputeStateTest.php`, `tests/Unit/ProductionEnvironmentTemplateTest.php` | `470a79f7` | OK, 33 tests, 2,153 assertions, 4 skipped (the four census-listed native methods), 3 min 35 s |
| Touched existing suites on SQLite: CustomerRefundResolution (HTTP + domain), StripeWebhook, TestCommerceOperations, TestFulfillmentActivation, TestOrderFinalization, TestOwnerDelivery (concurrency/HTTP/migration/projection/domain), TestPaymentExceptionInspection, TestPaymentExceptionOperations, TestPaymentFinancialObservation, TestRefundResolution (migration/resource/domain), TestUnpaidReleaseMigration, StripeFinancialInspectionGateway, ProductionTrackMachinePolicy, plus the A5 suites then present | `1496680c` (before the native test, Pint and two test-only strengthenings) | OK, 642 tests, 8,277 assertions, 3 skipped (existing `TestOwnerDeliveryConcurrencyTest` census skips), 58 min 32 s |
| Migration roundtrips and rollback suites: RefundDisputeMigration, OrderPreparation, QuotePricing, HostedCheckout, TestOrderFinalization, SharedInventory, TestPaymentEvidence, Promotion, CustomerConsent, CustomerListening, DiscoveryEpoch, MembershipCredit, ProductionBuyerAssentObservation, SiteImage migrations, `CommerceGuardBytesTest` | `1496680c` | OK, 149 tests, 1,251 assertions, 4 skipped (existing). The first attempt failed 7 roundtrips (`error in trigger refund_dispute_effects_insert: no such table: main.license_grants` during SQLite table rebuilds); fixed by dropping the empty A5 tables first, and the A5 migration became forward-only so `migrate:rollback --step 1` tests still refuse. |
| Native MySQL 8.4.11 (private `/opt/mysql-8.4.11/bin/mysqld`, port 3489, datadir under the scratchpad via `scripts/dev/with-mysql-test-server.sh`, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_test`): `RefundDisputeConcurrencyTest` | `171177bb` | OK, 4 tests, 50 assertions, 21 min 22 s |
| Native MySQL: `RefundDisputeMigrationTest` plus lifecycle `partial_refunds_summing`, `dispute_before_refund`, `exact_staff_allocation` | `69a5f124` | OK, 5 tests, 115 assertions, 23 min 2 s |
| Ops/env checks: `tests/ops/test_production_kit.py` (50), `test_production_runtime_validator.py` (27), `test_staging_capability_profile.py` (17), `test_staging_protected_configuration.py`, `test_staging_pipeline_admission.py`, `test_staging_build_admission.py`, `scripts/ops/test-private-server-preflight.py` | working tree after `1496680c` | all OK |
| Safeguards: `test-phpunit-shards.py` (52), `test-ci-scope.py` (27), `test-workflow-cadence.py` (14), `test-focused-tests.py` (53) | `470a79f7` | all OK |
| `composer validate --strict` | `470a79f7` | valid |
| Native selection: `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | `a1abbe8d` | Proved 2,520 selected MySQL-native tests in 242 files (of 9,581 discovered in 701) across 8 shards; generated `phpunit-ci-mysql-*` files deleted. Count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml` updated from 2,499/9,554/239. |

Every native run's server was stopped by the wrapper and its datadir removed (port 3489 free, scratch directory empty afterwards). No other lane's server was touched.

### Native writer latency (MySQL 8.4.11, 15 iterations each, CPU shared with three other lanes)

| Writer | p50 | max | Budget |
| --- | --- | --- | --- |
| Observation (claim, synthetic provider read, commit with effects) | 153.6 ms | 198.2 ms | 120 s lease; the test asserts < 5 s |
| Line suspension then hold release (two commands) | 122.9 ms | 151.3 ms | 60 s download authorization; the test asserts < 5 s |

### Races proven natively

1. A suspension holding the Order row makes a concurrent `IssueTestDelivery` wait on that exact `orders` PRIMARY record (observed in `performance_schema.data_lock_waits`); after commit the authorization is refused (`blocked`) and none is written.
2. An authorization holding the Order row makes a concurrent suspension wait on the same record; the authorization commits first, the suspension then commits, and a further authorization is refused. (Redemption refusal after a hold is proven on SQLite in `test_a_hold_refuses_redemption_of_an_authorization_issued_before_it`; the race worker keeps the raw token.)
3. A qualified exclusive reopening decision held open does not block, and does not admit, a competing exclusive quote/price for the same scope: the competing purchase is refused while the decision holds its locks, the exclusive sale count and reservation count are unchanged.

### Mutation checks (SQLite, isolated copy of the worktree, one mutation at a time, restored after each)

| Mutation | Result |
| --- | --- |
| M1 remove the refund total cap in the state machine | killed (unit failure; lifecycle error: the observation insert guard refused the over-paid row) |
| M2 remove the status-transition check | killed |
| M3 remove the currency check | killed |
| M4 remove the exact-sum allocation check | **survived first**, then killed after adding in-capacity sum-mismatch cases (`[0 => total, 1 => 99]`, `[0 => total, 1 => 101]`) |
| M5 remove the per-line allocation cap | killed |
| M6 make the delivery hold gate a no-op | killed |
| M7 remove the redemption hold check | killed |
| M8 accept unknown policy values | killed |
| M9 remove the locked MFA check on writes | killed |
| M10 remove the line-suspension qualification | killed |
| M11 allow releasing a reconciling hold while the dispute is open | killed |
| M12 drop the held-grant requirement for an exclusive reopening decision | **survived first**, then killed after adding a refusal before suspension |
| M13 drop the observation replay actor check | killed |
| M14 release the reconciling hold on a lost dispute | killed |

## Design decisions

- **No provider writes.** Following the established refund-resolution pattern, staff refund in the Stripe dashboard and the application observes. Initiation would need a new mutation capability and Sean's policy.
- **Snapshot state machine.** Complete lists make each observation authoritative for current state; arrival order cannot reorder effects. Prior snapshots constrain monotonicity only.
- **All four policy values required for any write**, even where a command uses only one, so a partial configuration never activates a partial policy. Reads (review, Filament lists and detail) work while disabled, for historical visibility.
- **Holds, not revocations.** `MachinePolicyV1` has no revocation value, so the mechanism suspends technical access and records evidence; legal effect stays with Sean.
- **Exclusive reopening is a recorded decision only.** `exclusive_sales` rows are immutable and the grant/selection guards treat any sale as sold; changing availability after a refund is U-08 and needs its own reviewed schema change.
- **Insert-guard existence checks instead of foreign keys to orders/lines/grants**, so earlier migrations' empty roundtrips still work; the parents are immutable and undeletable by their own guards. Foreign keys remain to `users` and among the new tables.
- **Forward-only migration** (`down()` always refuses), matching the newest migrations and keeping `migrate:rollback --step 1` tests meaningful.

## Schema change (needs independent review)

`database/migrations/2026_10_24_264100_refund_dispute_lifecycle.php`: seven new tables, 21 SQLite/MySQL triggers; no existing table, column, index or trigger is altered. SQLite and MySQL parity was exercised by the migration suite on both engines.

## Files changed

New: `config/refund-dispute.php`; the migration above; `app/Domain/Commerce/RefundDispute/` (`RefundDisputePolicy`, `RefundDisputeException`, `RefundDisputeState`, `RefundDisputeCase`, `RefundDisputeAuthority`, `RefundDisputeLedger`, `RefundDisputeEffects`, `RefundDisputeActions`, `RefundDisputeAccess`, `StaffCommerceDirectory`, seven models); `app/Filament/Resources/StaffOrderResource.php`, `StaffCustomerResource.php` and their `Pages/`; `resources/views/admin/staff-order-detail.blade.php`, `staff-customer-detail.blade.php`; `tests/Feature/RefundDispute/*` (4 files), `tests/Unit/RefundDisputeStateTest.php`, `tests/Support/RefundDisputeFixtures.php`, `RefundDisputeRace.php`, `refund-dispute-race-worker.php`; `docs/refund-dispute-lifecycle.md`; this packet.

Edited: `app/Domain/Delivery/IssueTestDelivery.php`, `RedeemTestDelivery.php`, `ReadTestOwnerDelivery.php` (one hold check each); seven existing migration roundtrip tests (drop the empty A5 tables first); `tests/Unit/ProductionEnvironmentTemplateTest.php` (`refund-dispute` family); `.env.example`, `ops/production/env.production.example`, `ops/staging/env.staging.example` (default-off keys); `scripts/ci/database-sqlite-skips.json` (four appended methods, first 150 unchanged), `scripts/ci/database-mysql-selection.json` (migration test in `pattern_files`, lifecycle test in `include_files`, two race helpers in `branching_helper_files`); count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml`; `CHANGELOG.md`; `docs/remaining-parity-coverage.csv` (FP-040, FP-068 notes).

## Untested conditions and remaining work

- No real Stripe account: every provider observation is synthetic through the existing gateway interface; real test-account interoperability (refund and dispute objects, statuses, pagination) is unproven.
- Policy values are unset; no supported value was chosen. Sean must decide owner question 10 (and U-08 for exclusive availability).
- The full lifecycle suite, administration suite and the touched existing suites ran on SQLite only; on MySQL only the races, the migration suite and three lifecycle cases ran. Browser rendering of the Filament pages was not exercised.
- The touched-suite sweep ran at `1496680c`; later commits changed only formatting (Pint, no logic), two test-only strengthenings, the native race test, CI census/selection files and docs. The A5 focused suites were rerun at `470a79f7`.
- Not wired: the production checkout chain (`FinancialReadPlan` output into this ledger), paid-grant/Tax/free/member delivery paths, per-grant holds in the owner download projection (enforced at issuance and redemption only), a console operator command, and the staging capability boundary (`scripts/ops/capability-boundary.php` does not yet pin `VASEY_TEST_REFUND_DISPUTE_ENABLED`; the staging template leaves it unset).
- Not implemented by design: provider refund initiation, license revocation, exclusive relisting, inventory restock, customer-facing refund messaging.
- Independent review of the schema change and the money/access paths has not happened.
