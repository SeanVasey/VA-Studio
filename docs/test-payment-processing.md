# Durable test-payment processing

Status: WP-07 implementation candidate, 2026-09-26, following merged [PR #62](https://github.com/VASEYDEV/VASEYAUDIO/pull/62). Final source, executed CI and independent reviews belong in the integrating PR. This document describes the candidate contract; it is not evidence of an actual Stripe transaction, production deployment or completed work package.

The increment consumes the [immutable test webhook inbox](stripe-webhook-inbox.md) and reconciles known [hosted test sessions](hosted-test-checkout.md). It records authoritative test-payment evidence and stops at `awaiting_finalization`. It does not mark an order paid, consume or release inventory/promotion capacity, grant rights, render contracts, create entitlements, deliver files, issue refunds or enable live payments.

## Configuration and trust boundary

`STRIPE_TEST_PAYMENT_PROCESSING_ENABLED=true` enables `payments.stripe.processing_enabled`. It defaults to false. Processing requires `local` or `testing`, Stripe test mode, the configured own-account identity and a valid test API credential. Staging may still receive signed test receipts under the earlier receiver contract; it cannot process them through this path. The application retrieves account context from the credential instead of trusting a webhook's claimed commercial outcome. Current `checkout_enabled` and `test_checkout_policy` may be withdrawn without removing this scoped financial recovery: processing uses retained validated order/checkout evidence, its own processing flag and valid account/credential context.

Receipt signature verification proves message origin under the configured endpoint-secret scope. Its event object identifier supplies a locator only; current provider retrieval and immutable local order/checkout evidence determine whether a payment confirmation can exist. Browser return/query parameters, elapsed time, a stored session status and a signed success event are not substitutes for verification. Raw payloads, private checkout URLs, credentials, client secrets, billing details and SDK exception bodies stay out of console/job output.

The processor accepts `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and `checkout.session.expired`. Every other retained event type is explicitly classified as unsupported. An event type supplies a retrieval reason, never the final payment decision; even a failure/expiry snapshot can be stale relative to the current provider object.

## Durable receipt work

The receipt remains immutable. A separate work record tracks `pending`, `processing`, `retry`, `processed`, `quarantined` or `unsupported`, together with attempt and recovery metadata. The scanner can discover a receipt lacking work, retry missed dispatch, and reclaim an expired claim. Eligibility is applied before the scan limit so unready or quarantined work cannot fill the batch.

A claim uses a fresh UUID and a 120-second lease. Provider I/O runs outside database transactions. A short transaction rechecks the current claim and its deadline before saving any observation, confirmation or result. A worker whose token is superseded or expired cannot commit business evidence or update failure bookkeeping. Retries do not revive that token. This is fencing of local effects, not exactly-once provider retrieval.

Each work item has at most eight attempts before quarantine. If its eighth worker crashes, scheduler reclamation locks the expired, exhausted work row and quarantines it directly; it does not use or revive the expired worker token. Worker completion and failure bookkeeping still require the current unexpired claim. Retry delay is `30 × 2^(attempts − 1)` seconds, capped at 3,600 seconds. Explicit operator replay is separately audited; it is not an automatic route around quarantine or a replacement for retained evidence validation.

`ProcessStripeReceipt::handle(int $receiptId, bool $replay = false): string` is the internal processor entry point. The queued `ProcessStripeReceiptJob` carries only a receipt ID, uses the `payments` queue, has one queue attempt and a 90-second timeout. Durable work owns retries. After `ReceiveStripeWebhook` returns its durable receipt, the HTTP controller invokes the dispatch hook. Direct use of that domain receiver remains persistence-only. Dispatch requires processing to be enabled and an allowed asynchronous driver (`database`, `redis`, `sqs` or `beanstalkd`); it waits for any outer transaction to commit. `sync` and `null` drivers do not run processing in the HTTP request. A dispatch failure preserves the successful receipt acknowledgment; the scanner recovers it. A `200` response continues to mean durable receipt, not completed verification.

## Authoritative verification

The processor retrieves the current Checkout Session and its PaymentIntent under the configured test credential and account, then validates their relationship to the retained order, attempt and checkout intent. Provider network calls use the existing guarded SDK boundary, outside application transactions. Confirmation requires all of the following, not merely one provider status field:

- The exact retained Checkout Session is complete and paid, with the expected PaymentIntent locator and unchanged commercial mapping.
- The current PaymentIntent is a test object under the expected own-account context and has `status: succeeded`.
- Integer `amount` and `amount_received` each equal the frozen order total; `amount_capturable` is zero; currency is USD.
- Order/attempt/intent metadata and the authoritative session/payment relationship agree with immutable local evidence. Connect and incompatible account contexts are rejected.
- Session currency, amounts, line mapping and fixed zero test-tax requirements still satisfy the hosted-checkout contract.

The Session and PaymentIntent GETs are not an atomic provider snapshot. If their otherwise valid payment statuses disagree in either direction, verification retries because payment can finish between calls. Actual amount, identity or frozen commercial-evidence mismatches quarantine receipt work. No inconsistent status pair creates a confirmation.

The verifier supports Stripe `automatic_async` capture semantics. Its accepted payment evidence does not depend on a synchronously available balance transaction or expand Charge accounting details. This is verification of the supported test purchase mapping, not implementation of payouts, refunds, disputes, production tax or every Stripe payment method.

## Immutable evidence and outcomes

Payment observations are append-only and identify receipt processing or explicit reconciliation as their source. Payment confirmations are immutable, with uniqueness for the account/test-mode/PaymentIntent and for each order, attempt, checkout intent and session binding. Separate webhook event IDs for the same payment cannot create a second confirmation. Replaying a receipt or reconciling the same session must converge on the same retained result. Reading an existing confirmation decrypts and revalidates its complete normalized Session/PaymentIntent evidence against the frozen purchase: amounts, metadata, session/payment identity, order/attempt/intent bindings, account/mode, API version and evidence source. An existing row alone is not sufficient payment proof.

| Outcome | Work disposition and meaning |
| --- | --- |
| `awaiting_finalization` | Verified immutable test-payment confirmation exists. Receipt work is processed; no terminal commerce or fulfillment effect occurs. |
| `pending` / `authorized` | Current evidence is not a completed supported payment. Retry under the durable policy; do not grant or infer failure. |
| `canceled` / `expired` | Retain a processed observation. Resources remain pending; this increment does not implement terminal release. |
| `unsupported` | Retain classification of an unsupported event. It is not payment success. |
| `receipt_changed` / `payment_changed` | Quarantine evidence mismatch for investigation; preserve prior evidence. |
| Unmatched order/session or provider unavailability | Retry without inventing a binding or successful payment. |
| `retry_exhausted` | Quarantine; further replay requires an explicit audited operator action. |
| `stale` | The claim no longer owns the work; commit no evidence or failure update. |

The existing owner checkout/order GET projections remain unchanged and may still report `paymentStatus: not_verified` after an internal confirmation exists. This is a deliberately retained historical API contract, not a read model for new payment confirmations. The future customer-status increment must expose confirmation, finalization, exception and fulfillment separately. No new public processing endpoint or browser write is introduced here.

## Console recovery and missing webhooks

```sh
php artisan vasey:process-stripe-receipts --limit=25
php artisan vasey:process-stripe-receipts RECEIPT_ID
php artisan vasey:process-stripe-receipts RECEIPT_ID --replay
php artisan vasey:reconcile-test-payments --limit=25
php artisan vasey:reconcile-test-payments --limit=25 --after=LAST_ATTEMPTED_INTENT_UUID
php artisan vasey:reconcile-test-payments INTENT_UUID
```

The receipt selector is a numeric internal ID available only to the trusted console. `--replay` requires a specific receipt; it cannot reset a whole scanned batch. The default receipt scan handles eligible durable work and missing work records within a limit from 1 to 100 (default 25). Queue retries and receipt HTTP redelivery are not required for this recovery path.

Payment reconciliation takes an explicit opaque checkout-intent UUID or a bounded page of known session bindings without a confirmation. Scanned pages are ordered by immutable internal intent ID and limited to 1–100 records (default 25). `--after` accepts the opaque UUID of an intent in the configured account/test scope and starts after that intent; an invalid or wrong-account cursor is rejected. An explicit intent cannot be combined with `--after`.

Every nonempty scanned page emits `NEXT_AFTER=<last attempted intent UUID>`, advancing past failures as well as successes. Pass that value to the next invocation and continue until the page is empty. Omit the cursor to begin a later new sweep and retry earlier unresolved sessions. Repeatedly invoking the default first page alone does not automatically rotate through the backlog.

Reconciliation performs provider retrieval only; it cannot create a new Checkout Session, charge/capture a payment or bypass frozen commercial evidence. This handles a missing webhook for a known session. It does not discover every unknown Stripe session or invent a session binding; use the existing verified session-locator recovery first when creation is uncertain.

Commands emit bounded identifiers and outcome/error codes. Preserve their actual outcomes as evidence without publishing private provider payloads. Operating these commands with synthetic fixtures is not proof of a test event delivered from Vasey Multimedia's real Stripe test account.

## Verification and recovery boundaries

The integrating PR must record the actual final source and MySQL/SQLite outcomes, including independent-process lease takeover and competing confirmation tests; SDK fixtures for correct and wrong account/mode/metadata/amount/currency/payment states; missed dispatch/work creation and missing-webhook recovery; poison messages and retry exhaustion; read-only provider behavior; and rollback safety. SQLite cannot prove MySQL concurrency. New test definitions or a reviewer reading source do not establish executed acceptance. PHP/Composer runtime limits must be reported instead of represented as local passes.

Stop fresh checkout if necessary while retaining receipt, evidence and recovery data. Disabling processing pauses new verification; it does not undo a confirmation. Do not delete receipts, work history, observations, confirmations or encryption keys during operational rollback, and do not reclassify paid evidence as unpaid. Schema `down` operations are for disposable development databases.

Next extend/version the historical order verifier, which currently requires pending inventory/promotion bindings, so original order evidence remains readable after valid terminal transitions. Agree the WP-04/WP-08 grant/render contract before implementing atomic terminal inventory/promotion/order effects, idempotent grants/pending entitlements/outbox or `paid_exception`. Then complete WP-08 deterministic buyer contracts and secure delivery. All 14 work packages, 103 parity requirements and source/migration/cutover obligations remain in the [ordered development record](development-order.md).

## Official references

Inspected 2026-09-26. These establish provider semantics, not executed application interoperability:

- [Stripe webhooks](https://docs.stripe.com/webhooks): asynchronous delivery, duplicates and retry behavior motivate durable receipt/work separation.
- [Retrieve PaymentIntent](https://docs.stripe.com/api/payment_intents/retrieve): retrieve the current object rather than treating an event snapshot as current payment state.
- [PaymentIntent object](https://docs.stripe.com/api/payment_intents/object): status, currency and received/capturable amount fields used in validation.
- [Asynchronous capture](https://docs.stripe.com/payments/payment-intents/asynchronous-capture): `automatic_async` can complete accounting fields asynchronously; this application's confirmation does not require those Charge accounting fields.
