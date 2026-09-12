# Quote pricing and tax authorization

This WP-06 increment adds server-owned calculation evidence beside an existing owned selection review. It supports unknown tax, explicit fixed test tax and an envelope for provider-calculated test tax. It does not replace a historical quote, configure a live account or create an order. The [architecture decision](architecture/D-08-quote-pricing-evidence.md) records why the new evidence is additive.

## Commands and HTTP

`PriceQuote::create(quoteId, ownerKey)` and `read(quoteId, ownerKey)` reuse `ReadQuote` before accessing pricing or configuration. The creation transaction holds the quote lock through insertion and audit. Unique `quote_id` makes the quote itself the idempotency boundary; there is no second caller-selected price or idempotency body. Reads have no creation side effect.

| Route | Contract |
| --- | --- |
| `POST /quotes/{quote}/pricing` | Same-origin session + CSRF; `Content-Type: application/json`; body `{}` only, at most 1 KiB, no query inputs. Client prices, policies, accounts, owners and arrays are rejected. Create or return the same pricing. |
| `GET /quotes/{quote}/pricing` | Owning session/authentication context only. Return an already created pricing after current quote, policy, hash and expiry checks. |

Both return `{pricing: ...}` with `pricingSchema: 1`, opaque `id`, `quoteId`, `expiresAt`, `currency`, `subtotalMinor`, `discountMinor`, `taxBasisMinor`, `taxMinor`, `totalMinor`, `taxStatus`, `payable: false`, `testOnly`, optional policy identity (`key`, `version`, `hash`) or null, and `items`. Each item contains exact string `offerRevisionId`, quantity one, base/discount/tax-basis/tax/total minor units and the full quote-license `disclosureHash`. `pricingHash` is the canonical fingerprint of these safe public fields, excluding itself. It is not the private pricing/quote snapshot hash or assent.

Responses use private/no-store, Cookie-varying and nosniff headers. POST shares the existing 10/min quote-create budget; GET shares the 60/min quote-read budget. Both use the existing session lock. Debug, CSRF and throttle failures retain the generic quote error boundary. Foreign and unknown quotes return the same `QUOTE_NOT_FOUND` 404 before configuration or pricing existence is disclosed.

An owned quote without pricing returns `PRICING_NOT_FOUND` 404. Pricing expiry returns `PRICING_EXPIRED` 410, configuration changes return `PRICING_CHANGED` 409, and invalid/unsupported configuration returns `PRICING_UNAVAILABLE` 503. Existing quote expiry/selection-change errors remain 410/409. Request a new quote with a fresh selection-review idempotency key when pricing changes; never overwrite the old record. GET/POST quote responses and the prior full-license URL are unchanged.

## Policy configuration

`VASEY_TEST_PRICING_POLICY` is an explicitly configured JSON object in local/testing environments only. It defaults to absent; no synthetic policy is installed by setup, migration, seeding or production fallback. Production rejects configured test pricing even when its policy declares test mode. Empty/missing configuration records `unresolved` tax with null policy, tax and total; zero tax requires an explicit policy/calculation.

The bounded schema contains exactly: `schema_version: 1`, `scope: "test"`, a lowercase safe `key`, positive integer `version`, `currency: "USD"`, `provider: "stripe"`, explicit `account` reference, exact UTC second `effective_from`/`effective_until`, and `tax`. The interval is half-open. The account string is an identity binding, not proof of account access. Configuration never accepts credentials or an approval inference.

| Tax mode | Required tax fields | Result |
| --- | --- | --- |
| `fixed_test` | `behavior: "exclusive"`, `rounding: "line_half_up"`, integer `rate_bps` from 0–10000 | Round each line independently using integer division/remainders, retain the trace, then sum. `taxStatus: "fixed_test"`; tax/total known for this test calculation. |
| `provider_calculated` | `behavior: "exclusive"`, integer `max_rate_bps` from 0–10000 | Pin provider/account/policy and each taxable basis. `taxStatus: "provider_pending"`; tax/total remain null until separate authoritative evidence exists. The cap is a test authorization bound, not a tax rate or registration decision. |

Policy bytes are validated and frozen with a canonical hash. Changing any semantic value invalidates reuse, including under the same textual key/version. Key order is irrelevant. Pricing expires no later than either its quote or the captured policy end. A future policy, malformed JSON, string/float rate, unsupported currency or implicit rounding cannot become a calculation.

This version prices the current positive USD, quantity-one, non-exclusive track offers. It captures an explicit `discount_policy: "none"`, zero per-line discounts and the unchanged taxable basis. Promotion authoring, eligibility, allocations and redemption caps remain the next WP-06 work; zero discount here is not a promotion engine. `MinorUnits` checks nonnegative integer inputs, safe transport bounds and overflow before summing. Splitting a fraction before multiplication avoids intermediate 64-bit overflow even at the maximum exact JavaScript integer.

## Immutable evidence and comparison

`quote_pricings` stores a unique quote FK and public ID, snapshot/hash, canonicalization version, issue and expiry. Model guards and MySQL/SQLite triggers reject updates/deletes; the quote FK restricts deletion. The snapshot pins the quote hash, algorithm, commercial revision IDs, disclosure hashes, policy and complete calculation trace. Public responses omit private quote/evidence fields and account identity. Historical schema-v1 quotes and their unresolved tax remain untouched.

`ComparePricingSettlement` is an internal amount-only check with no HTTP route, provider call or grant side effect. It verifies the retained pricing and quote hash, reproduces the captured arithmetic, and accepts a strict normalized observation. The observation must bind schema, quote/pricing/policy, provider/account/test mode, USD currency, subtotal/discount/tax/total and exact unique lines. No fees, shipping, substituted revisions, numeric strings or extra fields are silently ignored. Line order may differ.

Fixed-test amounts must match exactly. Provider-calculated amounts additionally need a separate complete tax result with the same identity, policy, calculation ID, currency, exclusive basis and exact per-line amounts. Each tax line must stay within the captured ceiling. Missing/incomplete/cross-account calculation evidence or mismatching totals produces an explicit negative report. This interface expects WP-07 to retrieve and normalize evidence independently; a matching pair of caller-supplied arrays is not proof of provider authenticity or correct tax registration.

The result contains `amounts_match`, a reason and canonical observation/tax-result fingerprints for later durable reconciliation. It does not persist a payment or report yet. The WP-07 caller must retain the report alongside private verified provider evidence, enforce amount/paid-state/order binding, buyer assent, expiry/payment-timing and inventory rules, and route failures to paid-exception handling. Historical arithmetic can still match after expiry; that must never be interpreted as current sale eligibility. Production cannot use this test comparator.

## Verification, recovery and next work

Tests cover fractional-cent and large-integer boundaries, configuration schemas/effective dates, missing versus explicit zero tax, safe projections, exact license bindings, owner/auth/CSRF isolation, tampering, policy changes, expiry during lock waits, immutable SQL/model constraints and an empty-table migration roundtrip preserving an earlier quote. Two MySQL processes meet at the exact quote-row lock to prove one record/audit under matching or conflicting policies. SQLite skips those process races explicitly. Settlement cases reject altered identities, amounts, lines and provider calculation evidence.

PHP/Composer are unavailable locally; record actual final MySQL/SQLite, frontend/build, browser and audit results in the PR. No new visual flow was introduced. For application rollback, withdraw the pricing callers/routes while preserving the new evidence table and every existing quote. Use migration down only in disposable databases with no useful pricing evidence.

After this batch passes CI/review, continue WP-06 promotion policy/allocations/atomic redemption and shared exclusive inventory/reservation lifecycle. Then integrate pricing selection and explicit assent with WP-07 immutable orders, hosted sessions, authoritative provider retrieval and paid-exception/finalization. Keep actual U-04/U-05/U-08 decisions explicit; downstream contracts/entitlements remain WP-08.
