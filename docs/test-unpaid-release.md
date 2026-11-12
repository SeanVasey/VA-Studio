# Test unpaid resource release

This is the bounded T20 workflow, admitted in `local`, `testing` and (as an optional, default-off capability since PR #333; operator steps in [Releasing an abandoned test order](ops/staging-test-purchase.md#releasing-an-abandoned-test-order)) the hosted `staging` rehearsal, for releasing a prepared test order’s pending inventory and promotion resources. It requires the configured Stripe test account, an enabled release flag and the exact versioned `UnpaidReleasePolicy::CONTRACT`. It does not enable production checkout or establish production acceptance of the late-payment policy.

## Isolated configuration

The feature is off unless `VASEY_TEST_UNPAID_RELEASE_ENABLED=true` and `VASEY_TEST_UNPAID_RELEASE_POLICY` contains the exact JSON object declared by `UnpaidReleasePolicy::CONTRACT`. The environment must be one that `TestEnvironment::admitsTestCommerce()` admits (`local`, `testing` or the hosted `staging` rehearsal), with the configured Stripe account in test mode. Production is refused even if those values are supplied. This change does not enable the existing checkout, payment-processing, finalization or webhook switches.

**Staging (2026-11-11).** Sean authorized the capability for the staging alpha, so abandoned test checkouts stop locking offers. It is an optional staging capability (`CapabilityBoundary::STAGING_OPTIONAL_FLAGS`), off in both staging templates: the staging deploy gate and the profile validator accept the flag only as exactly `false`, or as exactly `true` when this policy class accepts the effective configuration (staging or local, Stripe test mode, an `acct_` account, the exact contract). The production validator holds it false (`LAUNCH_OFF_HOLDS`). Before this the K2/K3 capability profile held it false with the reason "not part of this profile", not for a safety finding. Operator steps: [Releasing an abandoned test order](ops/staging-test-purchase.md#releasing-an-abandoned-test-order). The staging review of the release path found no change needed in it; the orders it cannot release by design are listed below and there.

**Staging use (2026-11-11).** The capability was deployed to staging with the flag on and the exact policy (main `c9a114cf`). Staff released three abandoned orders (`cf6504d0`, `a2e955f2`, `e44f06a0`) through `ReleaseTestOrderResources` as an administrator with MFA enrolled; each ended `released` with `testOnly` true, no reservation was left pending, and Wide Awake was then bought again (order `900b628f`). The independent review accepted the merge with conditions, and the native MySQL `TestUnpaidReleaseConcurrencyTest` passed (4 tests, 112 assertions) before it was switched on. The Filament page itself has not been recorded in use with a TOTP sign-in.

**Known limits.** Each is enforced in `ReleaseTestOrderResources::release()` and checked against the code on 2026-11-11.

<<<<<<< HEAD
- An order that was prepared but never sent to Stripe (no checkout intent at all) is released by a separate, local proof (below, since 2026-11-11). Before that it was not even listed, and its reservation stayed `pending` with no expiry: `pending` never expires in `ReserveQuoteInventory`, `InventoryPolicy` and the exclusive selection policy both say `retain_until_verified_resolution`, and no scheduled job touches reservations.
- It cannot release an order whose checkout started but has no recorded Checkout Session (the create request failed, timed out or crashed before the session was recorded). The result is `unavailable`: the provider may hold a session for it, and nothing local can show otherwise. If the session exists at Stripe, `php artisan vasey:reconcile-test-checkout <intent> --session=cs_test_…` binds it (`HostedCheckout::recover`), after which the provider proof applies. If Stripe has no session for it, the order stays held (owner decision pending).
=======
- It cannot release an order that was prepared but never sent to Stripe. With no recorded Checkout Session the result is `unavailable`, because there is nothing to prove unpaid. Such an order keeps its reservation. A separate staff release for that case (PR #341) is open and not merged or deployed as of 2026-11-12.
>>>>>>> origin/main
- It cannot release a late-paid order. Any recorded verified payment or finalization, including a paid exception, gives `payment_recorded` and the reservation is left as it is.
- A held order blocks the whole rights scope of its track, which means every linked tier of that track, not only the offer in the cart (inventory claims are per rights scope, `ReserveQuoteInventory`). Releasing the one order frees them all.

Only an already-bound CheckoutIntent and Checkout Session can qualify. An order with an unbound or ambiguous creation request remains unresolved; a timeout or the end of an idempotency window does not establish unpaid status. The dedicated browser proof uses disposable fixtures and a private GET-only synthetic transport, with no provider credentials or external network calls. That override exists only in `tests/browser/server.php`, never an application provider or the public entry point.

## Never-sent orders (2026-11-11)

**Proof.** `HostedCheckout::start()` commits a `CheckoutIntent` row under the Order lock before `dispatch()` can issue the only Checkout Session create for an `orders` row (`StripeCheckoutGateway::create`, with the intent's idempotency key). The intent is the write-ahead record of every provider request, and has been since the hosted checkout migration created both tables together; no other code path creates a session for an `orders` row (sound kits and the production chains use their own tables). So an order with no intent was never sent. `start()` also refuses a new intent once the attempt window (`order_attempts.expires_at`, which ends with the quote) has closed. The release therefore requires, under User → Order → (absent) CheckoutIntent → release work → promotion campaign/use → sorted rights scopes → reservation/claims locks:

- no checkout intent (and so no session), no verified payment, no finalization and no provider-proof release for the order;
- the attempt window closed (`expires_at` ≤ now); before that the result is `checkout_open` and nothing is recorded;
- the original pending reservation (and pending promotion use) bound to the attempt.

No provider call is made. A `requested`/`observed` event pair, a `test_unsent_order_releases` row (policy `test-unsent-release-v1`, proof `no_checkout_intent_closed_window_v1`, encrypted evidence of the facts above), the resource transition and an audit event `commerce.unpaid_release.unsent_released` (actor, order, release, proof, request ID) commit in one transaction. The admission is the same as for the provider proof: `UnpaidReleasePolicy` (flag exactly `true`, the exact `CONTRACT` JSON, Stripe test mode and `acct_` account in `local`, `testing` or `staging`), staff permission and admin MFA, checked inside the service.

**Database guards** (migration `2026_11_11_001000`): the proof row is refused unless the order has no checkout intent, verified payment, finalization or provider-proof release, the reservation is still the attempt's pending one, `released_at` ≥ the attempt's `expires_at` and the paired `observed`/`released` event matches; the row is immutable. The resource guards accept `pending → released` only with such a row (or the existing release and refund-resolution rows). A new guard on `checkout_intents` refuses an intent for a released never-sent order, so no provider create, payment or grant can follow it by any code path. `HostedCheckout` also reports such an order as `expired` and refuses to start it.

**Deploying the migration.** Like the earlier guard migrations, `2026_11_11_001000` replaces `inventory_reservations_transition` and `promotion_uses_guard_update` by dropping and recreating each trigger, and MySQL commits DDL implicitly, so for a moment each table has no update guard. Run it only while the site is quiesced: the staging deploy migrates inside its maintenance pause; never run it with `php artisan migrate` against a live site.

**Known limits of this path.** A release reported for the second the window closes is refused by a second (`expires_at` must be ≤ the whole-second clock). Two checkout starts and a release on adjacent orders can, in theory, contend for the same InnoDB gap lock on `checkout_intents.order_id`; MySQL resolves that as a deadlock and the transaction retries (five attempts). `HostedCheckout::start()` reads the reservation state under the Order lock (`ReadUnsentRelease::for(..., lock: true)`), and if the database still refuses the intent for a released never-sent order the buyer gets `CHECKOUT_EXPIRED` (410), not a server error.

**Pinning the proof.** The page's **Release never-sent order** action passes `ReleaseTestOrderResources::NEVER_SENT`; if checkout started after the review, the result is `stale` and nothing changes. Without the pin the service picks the proof from the facts it locks.

**Orders the buyer cancelled (2026-11-12).** With the separate, default-off buyer-cancel capability (`BuyerCancelPolicy`, `VASEY_TEST_BUYER_CANCEL_ENABLED`) the order's owner can cancel an order that has no checkout intent (`App\Domain\Commerce\BuyerCancel\CancelTestOrder`, `POST /orders/{order}/cancel`). It records a `test_buyer_order_cancellations` row and releases the pending resources in one transaction under the order lock, never calls the provider, and the checkout_intents guard of migration `2026_11_12_001000` refuses an intent for the order afterwards. This page lists such an order as "Cancelled by buyer", its review status is `buyer_cancelled` with the retained cancellation, and **Release never-sent order** returns `buyer_cancelled` without recording anything. See `docs/ops/staging-test-purchase.md`, "Cancelling a never-sent order as the buyer".

**Verification (2026-11-11, lane commit).** SQLite: `TestUnsentOrderReleaseTest` with the unpaid-release, refund-resolution and seven checkout/order migration-chain suites and the page test, 100 passed, 1,272 assertions; related checkout, order, inventory, finalization, payment and retention suites, 390 run with 5 failures, all in `MediaReferenceRegistryTest` and present on `main` before this change (unregistered `sound_kit_orders` columns). Native MySQL 8.4.11 (private server, REPEATABLE READ): the four race cases of `TestUnsentOrderReleaseConcurrencyTest` (checkout start vs release with either winner, second staff release, new hold on the shared scope; exact InnoDB `WAITING` evidence on `orders` PRIMARY, `orders_public_id_unique` and `rights_scopes` PRIMARY) and the database-guard, rollback, terminal-order and happy-path tests of `TestUnsentOrderReleaseTest`, 12 passed. The three race method identities are appended to `scripts/ci/database-sqlite-skips.json`.

## Operator workflow

Verified administrators use **Test commerce → Test unpaid release** (`/admin/test-unpaid-orders`). The list delegates account scoping to `ReleaseTestOrderResources::query()` and displays only the public order identifier and preparation time. An order appearing here is not evidence that it is unpaid.

**Release history** displays the public order identifier, current sequence, bounded event sequence/kind/outcome/timestamp projection and any retained release identifier/time. Buyer identity, ciphertext, provider payloads, account identifiers, payment identifiers and private files are not displayed.

**Verify unpaid status and release** reviews the current sequence and generates one request UUID when its dialog opens. Submission sends that original pair to the domain service. An uncertain retry preserves both values; submission never silently reviews a newer sequence or generates a replacement UUID. Close and reopen the dialog to begin a new review. Page requests enforce staff and required MFA access; the domain service must freshly enforce authority, MFA, account and policy within its write fences, including after provider reads.

| Result | Operator meaning |
| --- | --- |
| `released` | A retained release is confirmed, including an idempotent replay. |
| `not_unpaid` | The provider observations did not establish eligible terminal unpaid status. |
| `attention` | Conflicting, incomplete or unsupported evidence prevents release. |
| `unavailable` | Verification could not establish a release; inspect history before a new review. |
| `payment_recorded` | Recorded payment/finalization prevents unpaid release. |
| `busy` | A release check is already active; retry the same request or inspect history. |
| `stale` | The reviewed sequence or claim is no longer current, or a never-sent order has started checkout since the review; close and review again. |
| `checkout_open` | A never-sent order's checkout window is still open; the buyer can still start checkout. Retry after it closes. |

Only `released` with `testOnly: true` produces a success notification. Exceptions and unsupported responses say that release was not confirmed, without exposing raw errors or asserting that an uncertain commit did not occur. Non-success dialogs retain their original request. There are no generic create, edit, delete, detail or bulk actions.

## Approved proof and resource boundary

The domain contract uses provider GETs outside database transactions: inspect the authenticated test account, retrieve the exact bound Checkout Session, optionally retrieve its PaymentIntent, then retrieve the same Session again. Both Session observations must be expired and unpaid with explicit `after_expiration: null` and `recovered_from: null`; their original order/attempt/intent metadata, amount, currency, card and line-item evidence must match, and the PaymentIntent identifier must remain unchanged. The PaymentIntent must be explicitly absent or be canceled with zero received and capturable amounts and the exact retained binding.

Expiry time alone, an open/complete Session, processing or capturable payment, enabled recovery, missing fields, malformed responses, unavailable provider data or a mismatched account cannot authorize release. The operation does not expire a Session, cancel a PaymentIntent, capture a payment or refund money.

Stripe documents that an expired Checkout Session cannot be completed and that a canceled PaymentIntent cannot create additional charges: [Session expiry](https://docs.stripe.com/api/checkout/sessions/expire), [PaymentIntent cancellation](https://docs.stripe.com/api/payment_intents/cancel). The release contract is the application’s conservative inference from these terminal states and bound observations; separate GETs do not form an atomic provider snapshot. The linked write endpoints document state semantics; this workflow does not call them.

The release commit uses fresh User → Order → CheckoutIntent → release work → promotion campaign/use → sorted rights scopes → reservation/claims fences. Only the original pending resource pair may transition to released, atomically with retained release proof and audit evidence. Existing paid resources and grants are not revoked. Original order, attempt and legal evidence are preserved, and released attempts cannot be reused or restored.

A later confirmed payment must still be retained as verified money. Its versioned finalization becomes a `paid_exception` with reason `released_attempt`, without grants, sales or resource restoration. Later payment does not invalidate historical release proof. The release/payment race must be checked under the same order/intent fences; a previously inspected payment is not made safe by comparing only its observation timestamp.

## Verification evidence

The four new PHP suites cover domain proof validation, provider failures and ambiguity, current staff/MFA/policy withdrawal, immutable release/replay evidence, migration ownership and rollback guards, late confirmed money, resource capacity, and the operator workflow. `TestUnpaidOrderResourceTest` isolates the UI contract with a service double; `TestUnpaidReleaseTest` additionally drives the real Filament action through the real release service and a synthetic GET-only provider.

The independent-connection MySQL races require exact InnoDB `WAITING` evidence for the requesting and blocking sessions, table, primary-key record and target ID. They prove both payment/release winners on the Order fence and new shared-scope/last-promotion-use capacity users waiting behind the release commit. An activated exclusive quote is correctly refused by the existing advisory check while the old order remains pending; a separate regression proves that a new quote may reserve it after release. No advisory, timing or SQL assertion is disabled to force a wait.

The verified 2026-10-06 receipts are:

| Check | Actual result |
| --- | --- |
| New domain, migration and operator suites on SQLite | 66 passed; 539 assertions; 101.778 seconds |
| All four new suites on isolated MySQL 8.4.11 | 70 passed; 651 assertions; 396.187 seconds; REPEATABLE READ and default durability |
| Existing checkout, payment, finalization, operations and seven migration suites on SQLite | 259 passed and one existing skip; 2,526 assertions; 372.895 seconds |
| Native HTTP operator proof for both isolated project fixtures | Two passed; 14.2 seconds; real sign-in, CSRF, signed Livewire actions, domain release, retained history and replay |
| Frontend/tooling | TypeScript, production build, targeted PHP formatting and diff checks passed |
| Retained browser journey discovery | One case selected for each of Chromium desktop and WebKit mobile |

Run the new database suites against the selected disposable connection:

```sh
php vendor/bin/phpunit tests/Feature/TestUnpaidReleaseTest.php tests/Feature/TestUnpaidReleaseMigrationTest.php tests/Feature/TestUnpaidReleaseConcurrencyTest.php tests/Feature/TestUnpaidOrderResourceTest.php
```

The native MySQL receipt used an isolated server/database, independently connected workers and fresh synthetic credentials. SQLite does not prove those races. The two MySQL-only method identities are `test_exact_order_fence_preserves_money_and_the_winning_resource_disposition` and `test_release_serializes_new_capacity_users_without_reviving_old_bindings`; each expands to two datasets.

The HTTP proof used Playwright's request client without a browser binary. It verified the actual authenticated component identity, rejected tokenless updates with HTTP 419 and an invalid fixture capability with HTTP 503, then checked exactly four transaction-free provider GETs, one retained release, two history events, unchanged original evidence/SQL guards and no rights or money effects. A newly reviewed replay made no further provider request. This is application integration evidence, not a rendered-browser pass.

The retained browser case is `tests/browser/test-unpaid-release.spec.ts`. After the existing isolated wrapper has built its ordinary fixtures, this case creates a real service-bound pending order, logs in through the page, confirms release, inspects history and repeats the review. It checks the same private persisted evidence and captures the visible history. No routed API response is mocked. Run it with:

```sh
npm run test:browser -- test-unpaid-release.spec.ts
```

Rendered Chromium/WebKit execution remains a final CI gate because the local browser binaries were unavailable. The production unpaid-release policy, live provider behavior and production purchase readiness remain separate acceptance decisions; this bounded implementation does not resolve them.
