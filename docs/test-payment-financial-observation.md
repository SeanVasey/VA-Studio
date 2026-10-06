# Retained test-payment refund and dispute observations

T19-FINANCIAL-OBS-01 extends the existing [leased operational check](test-payment-exception-operations.md) with current provider observations. It closes the previous `refundDisputeState=not_inspected` gap for newly checked, confirmed test payments. **It does not resolve a paid exception or complete T19–T22.** Original payment/finalization evidence, pending inventory and promotion resources, grants and blocked fulfillment remain unchanged. U-05 refund/access policy and U-08 late-payment/exclusive availability remain owner decisions.

## Provider contract

The existing account, Checkout Session and PaymentIntent inspection must first verify the original retained payment. A separate GET-only interface then retrieves the credential's own account, the original PaymentIntent, its latest Charge, explicit refund and dispute lists filtered by that PaymentIntent (limit 100), and the same Charge again. All calls use the existing pinned `2026-08-26.dahlia` API contract, own-account test credentials, TLS, no Connect routing and zero SDK retries. No provider request may run while any application database connection has an open transaction. Financial calls have a five-second per-request limit; the six added requests have a 30-second transport budget inside the existing 120-second lease. Existing verification can use up to six ten-second requests; a late result still loses its claim and appends nothing.

The adapter exposes no refund, capture, cancel, dispute submission or other financial mutation. Fixture execution proves this response contract, not interoperability with the actual configured Stripe account.

- [Stripe refund lists](https://docs.stripe.com/api/refunds/list) support a PaymentIntent filter and a limit up to 100. The embedded Charge refund collection is only a first page, so it is not used as completeness evidence.
- [Stripe dispute lists](https://docs.stripe.com/api/disputes/list) likewise support a PaymentIntent filter, bounded pages and `has_more`.
- [Charge fields](https://docs.stripe.com/api/charges/object), [refund fields](https://docs.stripe.com/api/refunds/object) and [dispute fields](https://docs.stripe.com/api/disputes/object) define the retained facts. Refund objects do not consistently expose `livemode`; test mode is established by their exact linked test PaymentIntent and Charge. An explicit conflicting mode is refused.

Every Charge, Refund and Dispute must match the original payment and charge, USD currency and supported field types. The PaymentIntent is compared to the verified payment fields, including amount and metadata. Charge amount/captured amount must match the original collection. Refund and dispute statuses remain separate facts: no invented net-settlement equation, access consequence, refund policy or post-refund inventory rule is inferred. Inquiries and prevented disputes may be observed without inferring a formal chargeback from their existence.

## Operator meaning and retained evidence

The existing payment check and history modal show one of these financial observation states:

| State | Meaning |
| --- | --- |
| `observed` | Both bounded lists were complete and the supported identity/shape checks passed. Display provider refunded minor units, counts and observed status names. Zero counts mean none observed in those reads. |
| `incomplete` | A provider list has more results. No absence of refunds or disputes is established. |
| `attention` | Identity, amounts, fields or the repeated Charge observation conflict, or a status is unsupported. |
| `unavailable` | Retrieval failed. No absence of refunds or disputes is established. |
| `not_inspected` | Historical entry has no financial sidecar, or the current payment check did not establish a confirmed original payment. |

These separate GETs are **not an atomic provider snapshot**. Repeated Charge checks detect some intervening changes but cannot prove all refund/dispute status reads happened at one instant. No state is named clear, settled or resolved. A successful PaymentIntent can coexist with pending, partial or full refunds and active or closed disputes.

Each new financial observation is an encrypted, hashed, canonical immutable sidecar bound to the exact operational event, sequence, original finalization, verified payment and observation time. Replay reconstructs the expected payment fields from the original verified-payment ciphertext; the sidecar cannot nominate its own validation baseline. Only supported financial fields survive normalization. Buyer details, dispute evidence text, provider IDs, secrets, ciphertext and hashes are absent from operator projections and audits.

The sidecar, observed event, minimized audit and claim clear commit together under the existing actor→order→work/event fence. Fresh authority/account/policy checks and exact claim-token/expiry checks still apply after all I/O. Identical completed requests replay without provider calls. Expired/replaced workers append nothing. Old events and guard definitions are not rewritten; absence of a sidecar retains the honest `not_inspected` meaning.

The additive migration retains its parent event, prohibits mutation/deletion/replacement and rejects populated rollback. Empty migration roundtrips remove the sidecar before its parents and restore it afterward. These guards protect ordinary DML, not privileged schema destruction.

## Verification

Prepared on isolated branch `codex/commerce-next-20261006` from `636bc94b5688267edef1d34d8d1726402606da32`. No provider was activated and no real account or customer data was used.

Focused suites cover exact SDK GETs and guards; complete/partial/foreign/malformed observations; each supported refund/dispute status; partial/full refunds; encrypted identity and original-payment tampering; same-request replay; authority/account/policy withdrawal during financial retrieval; sidecar/audit/lease rollback; legacy history; SQL mutation/replacement/retention; actual Filament history messages; and the existing independent-process MySQL order/claim races with financial sidecar assertions.

```sh
php vendor/bin/phpunit tests/Unit/StripeFinancialInspectionGatewayTest.php tests/Unit/StripePaymentGatewayTest.php tests/Feature/TestPaymentFinancialObservationTest.php tests/Feature/TestPaymentExceptionOperationsTest.php tests/Feature/TestPaymentExceptionInspectionTest.php tests/Feature/TestCommerceOperationsTest.php tests/Feature/TestPaymentExceptionOperationsConcurrencyTest.php
bash scripts/dev/with-mysql-test-server.sh php vendor/bin/phpunit tests/Feature/TestPaymentFinancialObservationTest.php tests/Feature/TestPaymentExceptionOperationsTest.php tests/Feature/TestPaymentExceptionOperationsConcurrencyTest.php
```

Native results and final source identity are recorded in the integrating PR. SQLite does not establish lock behavior. A real provider test-account purchase/refund/dispute rehearsal, policy-approved financial effects, verified unpaid release, customer recovery/library, full native CI and independent exact-source review remain separate acceptance gates.

Local PHP 8.4.26 feedback on the frozen implementation passed **196 reported /193 executed /3 existing MySQL-only skips /1,591 assertions** across the listed focused suites. The seven adjusted migration roundtrips separately passed **7 cases /164 assertions**. Targeted formatting and whitespace checks passed. Earlier feedback passed 94 cases; initial local setup attempts lacked the application encryption key or disposable MySQL trigger privileges and reached no behavior assertions. MySQL runtime receipts and composed acceptance belong in the integrating PR, not in an inference from these SQLite results.
