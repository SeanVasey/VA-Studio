# Test unpaid resource release

This is the bounded T20 local/testing workflow for releasing a prepared test order’s pending inventory and promotion resources. It requires the configured Stripe test account, an enabled release flag and the exact versioned `UnpaidReleasePolicy::CONTRACT`. It does not enable production checkout or establish production acceptance of the late-payment policy.

## Isolated configuration

The feature is off unless `VASEY_TEST_UNPAID_RELEASE_ENABLED=true` and `VASEY_TEST_UNPAID_RELEASE_POLICY` contains the exact JSON object declared by `UnpaidReleasePolicy::CONTRACT`. The environment must remain `local` or `testing`, with the configured Stripe account in test mode. Production is refused even if those values are supplied. This change does not enable the existing checkout, payment-processing, finalization or webhook switches.

Only an already-bound CheckoutIntent and Checkout Session can qualify. An order with an unbound or ambiguous creation request remains unresolved; a timeout or the end of an idempotency window does not establish unpaid status. The dedicated browser proof uses disposable fixtures and a private GET-only synthetic transport, with no provider credentials or external network calls. That override exists only in `tests/browser/server.php`, never an application provider or the public entry point.

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
| `stale` | The reviewed sequence or claim is no longer current; close and review again. |

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

Rendered Chromium/WebKit execution remains a final CI gate because the local browser binaries were unavailable. The production unpaid-release policy, live provider behavior and production purchase readiness remain separate acceptance decisions; this bounded local/testing implementation does not resolve them.
