# Stripe test webhook inbox

WP-07 receipt prerequisite merged in PR #26, originally documented 2026-09-09. The application verifies and retains Stripe test snapshot events. Receipt is evidence that an authenticated message arrived; it is not proof that an order is payable, paid or fulfilled. Merged [PR #63 payment processing](test-payment-processing.md), accepted 2026-09-26, adds durable work and authoritative test-payment retrieval; the separate [finalization candidate](test-payment-finalization.md) requires its own CI/reviews.

## Implemented boundary

`POST /webhooks/stripe` is a stateless route, outside the browser session/CSRF/Inertia group. Other browser routes retain their existing protections. The receiver accepts `application/json` and an uncompressed body of at most 1 MiB. Its global body guard runs before Laravel's JSON transformations, reads at most the limit plus one byte, and retains the exact bytes for signature verification. Configure the reverse proxy's request-body limit as well; application limits do not prevent an upstream server from buffering a request.

The official `stripe/stripe-php` SDK verifies the raw bytes and `Stripe-Signature` using a fixed 300-second tolerance. Both expired and future signatures fail; an old event with a newly signed retry can succeed. Multiple `v1` signatures support Stripe's overlapping endpoint-secret rotation. SDK exceptions and signature headers are never retained or returned.

The envelope must be an own-account v1 snapshot event with `livemode: false`. The application accepts only `local`, `testing` or `staging` environments, explicitly enabled test mode, an account ID and an endpoint signing secret. Live payloads, live nested objects, Connect account payloads, organization contexts and thin events are rejected. Unknown snapshot event types may be retained for later classification; the receiver treats no event type as successful payment. The separate processor accepts only the four Checkout Session event types listed in its contract and classifies other retained types as unsupported.

Normal own-account events omit an account ID. Their account binding comes from configuring **that account's own endpoint secret**. `STRIPE_ACCOUNT_ID` labels the receipt scope; it cannot independently prove which account owns a secret. Verify that mapping in Stripe Workbench or the authenticated CLI before enabling receipt. A signing secret from another account is not a substitute. Connect/organization support requires a separate reviewed adapter.

## Durable evidence and retry behavior

- The database uniquely keys receipts by account, test/live mode and event ID. MySQL uses case-sensitive ASCII collation for provider IDs. Each receipt retains its type, object reference, provider/API version, creation and verification timestamps, raw-body SHA-256 and an encrypted copy of the first verified body.
- Receipt comparison uses `stripe-event-v1`: recursively byte-sort object keys, preserve list order and JSON numeric types, and omit only the top-level mutable `pending_webhooks` counter. This comparison is separate from the integer-only commercial snapshot canonicalizer. Provider numeric fields are not approved money calculations.
- Equivalent redelivery returns the same acknowledgment without overwriting or adding a receipt. A validly signed event ID with different substantive data returns `409 STRIPE_EVENT_CONFLICT`; retain the original evidence and investigate the event/destination in Stripe. The conflicting body is not stored or logged.
- Distinct event IDs referencing one payment remain separate receipts. Future payment-object/order-line uniqueness must prevent duplicate financial and fulfillment effects; event deduplication alone is insufficient.
- A single atomic insert completes before acknowledgment on this HTTP route. Persistence or encryption failure returns a retryable `503`, so Stripe can redeliver. The original receipt increment did not dispatch work. In merged PR #63, the HTTP controller invokes an ID-only dispatch hook after the domain receiver returns a durable receipt; direct use of `ReceiveStripeWebhook` remains persistence-only. Dispatch waits for any outer transaction to commit and requires its own flag and an allowed asynchronous queue driver; `sync`/`null` never trigger provider work in the request. Dispatch failure leaves acknowledgment successful because the durable scanner recovers missed work. Receipt HTTP behavior does not wait for payment verification.
- ORM guards and database triggers reject updates and deletes. Raw payloads are encrypted with Laravel's application encrypter and hidden from model serialization. The webhook's exception boundary returns generic, private/no-store JSON and logs only a fixed message plus exception class, excluding SQL bindings, provider payloads and signature material.

| HTTP result | Meaning |
| --- | --- |
| `200 {"received":true}` | The verified first receipt exists durably, or an equivalent receipt already exists. |
| `400 STRIPE_WEBHOOK_INVALID` | Invalid signature, stale signature, malformed envelope or unsupported account/mode context. |
| `409 STRIPE_EVENT_CONFLICT` | Event ID matches retained evidence but substantive payload differs. |
| `413 STRIPE_WEBHOOK_TOO_LARGE` | Declared or measured body exceeds 1 MiB. |
| `415 STRIPE_WEBHOOK_MEDIA_TYPE` | Body type or content encoding is unsupported. |
| `503 STRIPE_WEBHOOK_UNAVAILABLE` | Receiver configuration, environment, encryption, storage or another dependency is unavailable; retry later. |

## Configure a development receiver

1. Install committed dependencies with `composer install` and apply the additive migration with `php artisan migrate` in the test environment. `ext-curl` is now required by the Stripe SDK. Retain the application encryption key with the database backup; plan and verify old-key access before key rotation.
2. Supply `STRIPE_ACCOUNT_ID` for Vasey Multimedia, `STRIPE_MODE=test` and `STRIPE_WEBHOOK_ENABLED=true` through the development host configuration. Supply `STRIPE_WEBHOOK_SECRET` through the host's secret store. The checked-in example contains no credentials. An API key is not needed for signature receipt; a scoped test API credential is a separate requirement for the merged hosted Checkout adapter and payment processing.
3. Use an own-account **snapshot** destination with a recorded API version. For a local listener, authenticate the Stripe CLI to the intended test account and run `stripe listen --forward-to localhost:8000/webhooks/stripe`. Use the signing secret emitted by that listener. A Dashboard endpoint has a different secret; do not interchange them. For a hosted test endpoint, use HTTPS and its own Workbench signing secret.
4. Send a Stripe test event, confirm the HTTP response, then inspect receipt metadata with `php artisan vasey:stripe-inbox --limit=20`. The command reads only the configured account's test receipts, permits limits 1–100, and exposes no raw payload or secret. It can inspect retained metadata after receipt is disabled.

Do not put API keys or webhook secrets into chat, Git, PRs, shared command transcripts or diagnostic output. ChatGPT's connected Stripe account does not install application credentials or expose a local server to Stripe. This increment does not register an external webhook destination or change a provider account.

## Verification and limits

`php artisan test --filter=StripeWebhook` exercises the HTTP/domain behavior on the selected database. `StripeWebhookConcurrencyTest` uses two independent PHP processes, distinct MySQL connections and a barrier before their competing inserts, then reads the committed result from the parent connection. SQLite intentionally skips those two races. The full CI also runs the existing quote races, media/licensing tests, frontend tests/build and dependency audits. The integrating PR records actual results and the tested commit; these test definitions alone are not execution evidence.

Fixtures are synthetic and nonbinding. An actual event delivered from Vasey Multimedia's Stripe test account to a deployed/CLI-connected receiver is a separate check. Private order/assent and hosted test-session creation/recovery have since merged in PRs #52/#62. Durable processing and authoritative payment confirmation merged in PR #63, stopping at awaiting finalization. The dependent finalization candidate adds terminal effects and grants with pending fulfillment. Actual test-account interoperability, production policy, buyer PDFs and active downloads remain incomplete. Receipt flags must never activate those behaviors implicitly.

## Recovery

Return a retryable error while repairing a failed receiver, then resend the relevant Stripe test events. Preserve the receipt table, application encryption key and original ciphertext during code rollback. The migration's `down` method is for disposable development databases; do not run schema rollback against retained payment evidence. Merged PR #63 places worker state in separate records so processing cannot rewrite the receipt. Stopping new checkout must preserve receipt/reconciliation for payments already in flight. See [processing recovery](test-payment-processing.md#console-recovery-and-missing-webhooks) for explicit replay, missing dispatch, expired leases and missing-webhook handling.

Sources inspected: [Stripe webhook delivery and retries](https://docs.stripe.com/webhooks), [signature verification](https://docs.stripe.com/webhooks/signature), [API and webhook keys](https://docs.stripe.com/keys), and [Stripe PHP v21.3.1 signature implementation](https://github.com/stripe/stripe-php/blob/v21.3.1/lib/WebhookSignature.php). The SDK lockfile was generated by Composer in the repository's PHP 8.4 CI runtime; it was not resolved by hand.


## Accepted processing and current finalization handoff — 2026-09-26

[PR #63](https://github.com/VASEYDEV/VASEYAUDIO/pull/63) accepted durable receipt work and authoritative test-payment verification after its own full CI and two independent reviews. The separate [finalization candidate](test-payment-finalization.md) adds default-off local/testing paid/exception effects and pending fulfillment records. Receipt acknowledgment still means durable receipt, never payment, a grant or completed fulfillment. Each later effect requires its own retained-evidence validation; browser return remains read-only.
