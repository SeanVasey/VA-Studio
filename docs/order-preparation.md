# Test order preparation and retained assent

Status: merged and verified in PR #52, main `5e53c2cc57938bfc1b55bfdff4d8b22c1c70fb08`; updated 2026-09-26. All gates passed: 797 MySQL tests / 6,396 assertions; 745 SQLite tests / 5,303 assertions with 52 intentional skips; 91 frontend tests; 10 browser cases; build/audits and independent review. This advances issue #7; authoritative payment and fulfillment acceptance remain open.

## Boundary and configuration

An explicit local/testing policy enables a separate **Test order preparation** flow after selection review. It captures the displayed terms, synthetic seller policy, supplied buyer identity and affirmative assent in an immutable private order, while binding inventory and optional promotion capacity to one attempt. Every response remains `testOnly: true` and `payable: false`. Legacy `/checkout` still returns `503 COMMERCE_NOT_ENABLED`. This preparation increment creates no provider session, payment, grant, executed contract or entitlement. Merged [hosted test checkout](hosted-test-checkout.md) adds owned session routes, and merged [payment processing](test-payment-processing.md) adds authoritative confirmation. The separate [finalization candidate](test-payment-finalization.md) extends owned reads with verified-payment and terminal-effect status while keeping original preparation evidence immutable.

`VASEY_TEST_ORDER_POLICY` maps to `commerce.test_order_policy` and has no installed default. Fresh reviews and orders require `APP_ENV=local` or `testing`, a valid JSON policy of at most 8 KiB, existing current pricing with `tax_status=fixed_test`, and complete scoped inventory. Unknown or provider-calculated tax is not accepted by this increment. Existing [pricing](quote-pricing.md), [promotion](promotion-pricing.md), [inventory](shared-rights-inventory.md) and, where applicable, [exclusive selection](exclusive-selection.md) policies still apply. Setting the order policy alone cannot make an unprepared catalog eligible.

The following is a synthetic schema example only; setup and seeders do not install it:

```json
{
  "schema_version": 1,
  "purpose": "test_order_preparation",
  "version": "SYNTHETIC-ORDER-1",
  "seller": { "legal_name": "Synthetic Seller" },
  "assent": { "version": "SYNTHETIC-ASSENT-1", "text": "Synthetic test assent only." },
  "buyer_identity": "unverified_guest"
}
```

The schema is closed: unknown keys, missing fields or other identity modes fail. Seller name is bounded to 160 characters, assent text to 4,000 and version identifiers to 80. Nothing in this configuration settles production seller identity, legal terms, customer verification/recovery, tax or exclusive timing policy (U-04/U-05/U-07/U-08).

## Review and browser contract

| Surface | Behavior |
| --- | --- |
| `GET /quotes/{quote}/order-review` | Owner-checked, read-only review of existing current pricing and every full frozen license disclosure. It does not create a quote, price or hold. |
| `POST /orders` | Requires CSRF, an `Idempotency-Key` and bounded JSON containing only `quoteId`, `reviewHash`, `buyer: {legalName, email}` and `accepted: true`. Atomically prepares or returns the identical order. |
| `GET /orders/{order}/status` | Owner-checked retained evidence, with a safe `prepared` / `not_started` status projection. |
| `GET /quotes/{quote}/order` | Recovers that owner's existing order without creating another one. |

Routes retain browser-session ownership, session blocking and rate limits. JSON preparation input is at most 4 KiB; client totals, owner IDs and provider data are not accepted. The safe review hash binds the exact pricing presentation, all line disclosures, seller name, policy/assent versions and text, expiry and test boundary. A changed hash requires a new review. The server validates name/email bounds and syntax; that does not verify the person, contact address, account ownership or marketing consent.

The storefront shows fresh preparation only when the server reports a valid development policy, and never in the design preview. It recovers an existing order first, obtains existing pricing or explicitly requests pricing if absent, displays complete license source and amount allocation, and requires an initially unchecked assent checkbox. Submitted identity and the request key stay fixed in component memory while an outcome is uncertain; retry sends the same captured body/key. Recognized validation errors unlock details and reset assent, and recognized stale-review/expiry errors require a current review and fresh assent. Unknown codes, malformed replies, transport failures and server errors preserve the uncertain request; an existing-order/key conflict checks recovery first.

Before submission, optional `sessionStorage` key `vaseyaudio-order-recovery-v1` retains at most ten opaque quote IDs. It stores no buyer details, order request body/key or review. The cart's separate read-only recovery surface uses those locators even after quote expiry, missing current cart/catalog lines or withdrawal of the preparation policy. Closing/reopening the cart or reloading the same tab can therefore recover safe server status without reconstructing a private request. Buyer details are not written into the saved cart or browser storage; successful preparation clears the input. Storage failure or loss of the owning session limits recovery, and the locator confers no authorization. Broader customer account/claim recovery remains future work.

## Immutable private records

| Record | Retained evidence |
| --- | --- |
| `orders` | Unique public ID, owner/key idempotency, unique quote/pricing binding, encrypted canonical payload, ciphertext integrity hash and creation time. The payload contains normalized request, identity/assent, exact review, captured policy, quote/pricing snapshots, line evidence and attempt binding. |
| `order_lines` | Exact quote-line and offer-revision FKs, position and line hash. Full terms, selected asset evidence and allocated amounts are inside the encrypted order payload. |
| `order_attempts` | One immutable attempt per order, unique inventory reservation, optional unique promotion use, captured binding/hash and original creation/expiry. |

Model guards and MySQL/SQLite triggers reject updates and deletes. Restrictive FKs retain source records. The additive migration does not backfill orders from legacy test attempts, modify their UUIDs, or change older snapshot formats.

Canonical plaintext is capped at **16 MiB before encryption** and checked again when read. Laravel authenticated encryption protects the private payload; it is decrypted only by the evidence service and then compared against exact canonical reconstruction. `payload_hash` is SHA-256 of the randomized authenticated **ciphertext**, not the plaintext aggregate. No standalone digest of buyer identity, request or private plaintext is stored, avoiding an unkeyed identity-guessing oracle. Model serialization hides ciphertext, owner/key and private hash/binding fields; HTTP uses an explicit allowlist. Audits contain safe IDs and the test marker. Error responses do not reflect private requests, decrypted data or SQL bindings. Preserve encryption keys and encrypted records together during future backup/rotation work.

## Atomic preparation and durable retries

Fresh preparation locks the owner mutex, quote and existing order identity, then freshly verifies frozen media and current offer/license evidence. It reproduces pricing and the complete review, acquires the existing coordinator's pricing → promotion → sorted inventory locks, and moves inventory plus any promotion use to `pending` with the same server-generated attempt UUID. The encrypted order, line references, attempt and audit commit in that enclosing transaction. A late error, excessive capture size, failed encryption/insert or crossed deadline rolls back all new effects. No provider I/O occurs inside or after this operation.

Same owner/key and identical normalized request returns the original verified record. Different input for that key conflicts; another key cannot create another order for the same quote. Existing evidence is checked before current policy, publication or expiry eligibility, so identical retries and owned status reads survive ordinary expiry and later configuration/publication changes. Ownership still applies, including the existing rotation across authentication changes. Reading neither renews nor releases a reservation.

Original historical verification uses retained quote/pricing/policy evidence, line and claim identities, encrypted request/assent and exact pending attempt links. The dependent [finalization candidate](test-payment-finalization.md#historical-evidence-and-replay) preserves those original bytes and hashes: a narrow immutable resource-disposition proof permits reconstruction of the original pending snapshot, then a separate full verifier requires the complete current terminal effect graph to agree. It accepts valid consumed resources only with matching paid finalization, and requires pending resources for a paid exception. Missing, extra or corrupt effects fail closed. Current policy withdrawal, ordinary expiry or catalog edits do not rewrite the purchase or prevent valid historical reads. This does not add arbitrary release/redemption commands.

## Verification and next dependency

Coverage belongs to `OrderPreparationTest`, `OrderPreparationMigrationTest`, `OrderPreparationConcurrencyTest` and the frontend preparation/review tests. Required evidence includes privacy, wrong ownership, malformed/changed assent, stale pricing/media, idempotent recovery, partial-write rollback, immutability, older migration compatibility and independent-process MySQL races. SQLite cannot establish MySQL lock behavior. Test definitions are not passing results; record executed commands and exact candidate outcomes in the integrating PR.

Merged PRs #62/#63 provide [hosted sessions](hosted-test-checkout.md) and [authoritative payment verification](test-payment-processing.md). Accept the current [finalization candidate](test-payment-finalization.md) against its own CI/reviews, then implement WP-08 deterministic buyer PDFs, verified fulfillment activation and secure downloads using frozen grant input. Keep production checkout disabled; pending fulfillment records do not authorize file delivery.

For operational rollback, disable new preparation and retain existing encrypted orders, attempts and pending resources. Migration `down()` is for empty disposable development databases, not operational erasure. Production policies, media/scanner/worker/device evidence, source obligations and the launch gates remain open.
