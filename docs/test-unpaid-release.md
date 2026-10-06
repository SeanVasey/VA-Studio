# Test unpaid resource release

This is the bounded T20 local/testing workflow for releasing a prepared test order’s pending inventory and promotion resources. It requires the configured Stripe test account, an enabled release flag and the exact versioned `UnpaidReleasePolicy::CONTRACT`. It does not enable production checkout or establish production acceptance of the late-payment policy.

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

## Verification scope

The operator lane adds `TestUnpaidOrderResourceTest`: real Filament/Livewire requests and real prepared-order fixtures, with only the release service doubled. Its checks cover staff/MFA page access, account-query delegation, minimized history, absence of generic mutations, request identity retained across an uncertain retry, every declared non-success outcome, unsupported replies and invalid form data. These tests verify the UI contract; they do not prove provider calls, resource transitions, SQL guards, late-payment behavior or concurrency.

The focused SQLite UI run passed 14 cases / 172 assertions; targeted Pint passed. Independent source review is pending. The separately owned release domain, migration, payment integration and native MySQL race evidence must be composed and verified before reporting integrated acceptance. Browser rendering has not been executed. T20 and production purchase readiness remain open until their recorded acceptance gates are met.
