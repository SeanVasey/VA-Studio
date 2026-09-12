# Provisional selection reviews

Status: first WP-06 increment. The storefront can ask the server to review selected published offers and retain an immutable snapshot of their commercial evidence. This is a provisional selection review, not an offer of payable checkout or a completed order. The code uses the term `Quote` for its retained record; the customer flow uses **Review selection**.

## What this increment establishes

| Boundary | Implemented behavior | Remaining work |
| --- | --- | --- |
| Selected products | Resolve exact current published track, offer, offer revision and license version identities | Product types beyond the current non-exclusive track offers |
| Commercial evidence | Freeze server-owned price, license, reviewed terms, rights identity and exact file revision evidence | Buyer-specific order and contract snapshots |
| Amounts | Positive integer USD line prices and a checked integer subtotal | Promotions, tax policy, tax calculation and a payable total |
| Ownership | Bind the record to its browser session and authentication context | Production guest/account purchase and recovery policy |
| Retries | Same owner/key/canonical selection returns the retained eligible result; another selection conflicts | Provider request reconciliation and order/payment idempotency |
| Availability | Revalidate before creating or returning a selection review; enforce expiry | Exclusive inventory, reservations and payment finalization |

The server sets `payable` to `false`. Tax and total remain `null`; this does not mean zero tax, tax exemption or a total equal to the subtotal. Reviewing a selection does not record agreement to legal terms, reserve a recording, initiate payment or authorize downloads. No production tax, legal-identity or exclusive policy is inferred from this development feature.

## Customer flow

1. Select a license for each intended track in the storefront cart.
2. Choose **Review selection**. The browser submits identifiers for the advertised revisions, not prices, totals, legal text or file paths.
3. Inspect the server-reviewed selection and its provisional subtotal. The checkout control remains disabled.
4. If a selected offer changes, becomes unavailable or the review expires, refresh the catalog, choose the desired current offer again and request a new review. A changed price, license or recording is never substituted silently.

The first increment accepts 1–10 selected tracks, with at most one offer per track. Quantity is one per selection; repeated rows are rejected rather than interpreted as extra copies. Only currently supported positive USD non-exclusive offers are eligible. Publication bounds each line at 2,147,483,647 minor units; ten such lines remain within the exact integer range used by PHP and JavaScript. Coupon, address, identity and acceptance fields are not part of this request.

The review lifetime is fixed at 15 minutes by `CreateQuote::LIFETIME_MINUTES`. This is an implementation limit for provisional reviews, not an approved live quote guarantee, exclusive reservation TTL or provider-payment grace period. Expiry does not erase the retained evidence or reserve availability for its duration.

## Frozen evidence and later changes

The server resolves and verifies each selection against its current published commercial revision. The record retains that exact revision's product, price/currency, license source and structured terms, review hashes, rights identity, preview lineage and deliverable asset hashes/roles. A canonical SHA-256 binds the snapshot; the record carries its canonicalization/schema version.

The browser receives a purpose-built projection of the review. Private storage keys, internal rights evidence, approval references, ownership tokens and raw legal-review payloads do not belong in the response. A quote identifier is not an authorization capability.

Creation freshly hashes the selected preview and deliverable bytes. Reads and identical retries recheck publication and evidence using the existing media verifier, whose successful digest cache lasts at most 60 seconds and does not extend on a hit. This bounded verification is not a payment-time integrity guarantee; later payment/fulfillment commands need their own finalization contract.

Editing an offer draft leaves the published commercial revision unchanged. Publishing a different revision does not rewrite an existing selection review. Instead, returning that review fails the current-availability check and requires a fresh selection. The same applies when a track or offer is withdrawn, a license becomes unavailable or verified media/rights evidence no longer satisfies readiness. Preserving the snapshot is distinct from keeping it eligible for continued use.

Both ordinary model writes and database guards protect retained quote evidence against update/delete. These guards support the application's integrity boundary; they do not replace production database privileges, backups or a reviewed retention policy. This increment supplies no quote-editing or history-deletion control.

## Ownership and retry rules

The server derives ownership from the active session and authentication context. The client cannot nominate another owner. The persisted ownership representation is an HMAC of a server-generated session secret and authentication context, not a raw session identifier; this increment collects no buyer name, email, address or legal identity. The same signed-in account in a separate session does not inherit a review. A new session or change in authentication context requires a new review; ordinary session-ID regeneration that preserves the same ownership secret and context is not a new owner.

Idempotency is scoped to that owner and operation. The browser keeps a key when retrying the same selection. The server normalizes identifier representations and sorts the selected items before hashing, so harmless item order changes do not create a different request. A unique owner/key constraint handles competing retries. The same key with another canonical selection returns a conflict; it never updates the original record.

An identical retry can return the original result only while that review remains unexpired and currently eligible. Expired or changed reviews return a restart error. Create a fresh review with a new key after resolving the underlying selection. Neither a retry nor a read refreshes the old expiry or rewrites its snapshot.

## HTTP contract

Both routes use the ordinary same-origin web session. POST requires CSRF and a case-sensitive `Idempotency-Key` header: 1–128 characters matching `[A-Za-z0-9][A-Za-z0-9._:-]{0,127}`. Unknown request fields are rejected, including client prices or totals. The server stores a hash of the idempotency key scoped to the owner.

| Route | Input | Result |
| --- | --- | --- |
| `POST /quotes` | JSON object with only `items`, a list of 1–10 objects containing exactly `trackId`, `offerId`, `licenseVersionId` and `offerRevisionId` | `200` and the created or eligible existing `{quote: ...}` projection |
| `GET /quotes/{publicUUID}` | Opaque quote UUID and the original session/authentication context | `200` and the same retained projection after ownership, expiry and current-readiness checks |

Each identifier must be a positive integer or its canonical decimal string, no larger than 9,007,199,254,740,991; leading zeros, decimal points, exponent notation, floats and booleans are rejected. POST requires a JSON body no larger than 32,768 bytes and accepts no query parameters or client-owned snapshot. POST is limited to 10 requests per minute and GET to 60 per minute. Responses, including route error responses, use private/no-store caching, `Vary: Cookie` and `X-Content-Type-Options: nosniff`.

The `quote` projection contains `id`, `expiresAt` (UTC ISO timestamp), `currency`, `subtotalMinor`, `taxMinor: null`, `totalMinor: null`, `taxStatus: "unresolved"`, `payable: false` and `items`. Each item contains the four selected IDs, `title`, `artist`, `licenseName`, `priceMinor`, `currency`, `deliverableRoles`, `features` and `licenseUrl`. All display values come from the frozen server snapshot; internal evidence and storage references remain private. Full terms load through the [owned quote disclosure endpoint](quote-license-disclosure.md), with the same expiry/current-evidence checks and an explicit safe disclosure fingerprint. Reading a disclosure does not record assent.

| Code | HTTP status | Meaning and recovery |
| --- | --- | --- |
| `INVALID_QUOTE_REQUEST` | 422 | Invalid selection shape, identifiers, bounds or idempotency key; correct the request |
| `IDEMPOTENCY_CONFLICT` | 409 | The same owner/key already identifies a different selection; use a new key for the new selection |
| `QUOTE_EXPIRED` | 410 | The original review expired; request a fresh review |
| `SELECTION_CHANGED` | 409 | A selected revision or its current availability changed; refresh and choose again |
| `QUOTE_NOT_FOUND` | 404 | Missing quote or wrong owner; another session's record is not disclosed |
| `SESSION_EXPIRED` | 419 | CSRF/session verification failed; refresh the page before retrying |
| `RATE_LIMITED` | 429 | Wait before retrying |
| `QUOTE_UNAVAILABLE` | 500 / 503 | Unexpected failure or session-lock timeout; retry without changing the key after a transient failure |

The broader [architecture interface skeleton](architecture/interfaces.md) remains a target contract. Its proposed `/cart/quote` route, `QuoteV1` payable fields and checkout API are not the schema implemented by these provisional endpoints.

## Operational boundary and verification

Apply the additive quote migration with the reviewed application release using `php artisan migrate`. No existing offers, licenses or customer records need to be rewritten, and no BeatStars data is imported by this migration.

The integrating PR records actual commands, candidate commit, databases and results. Necessary coverage includes price injection, stale revision selection, changed readiness, integer bounds, expiry, owner isolation, canonical retries, conflicting retries, persistence immutability and safe response projection. MySQL results are required to assess MySQL behavior; SQLite success does not establish database concurrency safety. No test pass count is implied by this guide.

See [Quote verification](verification/provisional-quotes.md) for executed evidence. Quote routes use session blocking; production requires a shared lock-capable cache and persistent session store. Other routes retain their existing session behavior. Session loss, including a concurrent nonquote request overwriting session state, can make an earlier review unreachable. Access fails closed; immutable evidence remains retained. This increment does not establish global session-write concurrency or account recovery for provisional reviews.

For a rollback, withdraw the review endpoint/control with a reviewed release while retaining quote evidence. Keep checkout disabled. Do not roll back evidence tables or mutate historical snapshots to make a newer offer appear compatible.

WP-06 remains open for promotion eligibility/allocation/caps, a complete tax-policy envelope, approved disclosure and assent versions, buyer/session purchase policy, shared exclusive inventory and reservations, and independent-connection race evidence for those mechanisms. WP-07/WP-08 must add orders, verified payment finalization, contracts, grants and entitled delivery. A provisional review is not sufficient input to turn on live checkout without those contracts and gates.
