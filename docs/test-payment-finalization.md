# Test-payment finalization and pending fulfillment

Status: WP-07 implementation candidate, 2026-09-26, following merged [PR #63](https://github.com/VASEYDEV/VASEYAUDIO/pull/63). Its final source, executed CI and independent reviews must be recorded in the integrating PR. This contract describes the candidate; it does not claim a real Stripe transaction, accepted production policy, generated buyer PDF, active download or completed work package.

The increment builds on [authoritative test-payment processing](test-payment-processing.md). It atomically records either a paid purchase with frozen grants and pending fulfillment, or a paid exception that preserves occupied resources for later resolution. Payment verification remains a prerequisite. A signed receipt, checkout redirect, browser input or stored provider status alone cannot finalize a purchase.

## Explicit local/testing policy

`STRIPE_TEST_FINALIZATION_ENABLED` maps to `payments.stripe.finalization_enabled` and defaults to false. `STRIPE_TEST_FINALIZATION_POLICY` maps to `payments.stripe.finalization_policy` and is blank by default. Enabling the flag alone is insufficient. Fresh finalization requires `local` or `testing`, Stripe test mode, a valid configured own-account ID and the exact JSON contract below, with no additional or changed policy values:

```json
{
  "schema_version": 1,
  "purpose": "test_order_finalization",
  "version": "test-order-finalization-v1",
  "eligibility": "confirmation_observed_before_attempt_expiry",
  "exception_resources": "retain_pending",
  "grant_effective_time": "finalization_time",
  "buyer_identity": "unverified_guest"
}
```

`FinalizationPolicy::CONTRACT` is the source contract. It is a nonbinding development policy, not approval of production legal terms, identity verification, exclusive timing, refunds or customer recovery. The policy envelope is bounded to 4 KiB. Processing and finalization have independent enablement; withdrawing fresh checkout or the processing flag does not invalidate retained confirmed evidence. Finalization performs no provider request and requires no new payment creation or capture. Historical reads validate retained policy and evidence without requiring current flags or configuration.

A payment is eligible only if its retained `confirmed_at` is **strictly before the original order attempt's `expires_at`**. Equality is late. This timestamp is when the application observed and retained verified confirmation, not the provider's payment time. An early actual provider payment discovered only after the cutoff therefore becomes a paid exception under this explicit test rule. A timely confirmation may be finalized after expiry, including after a worker delay. Neither provider Session expiry nor finalizer execution time replaces or extends the original cutoff. Proved late confirmations skip storage inspection and retain `assets_available: null`; historical verification requires that explicit not-inspected value exactly for late confirmations. Unrelated storage failure cannot delay recording this exception.

## Atomic effects and failure classification

Finalization revalidates the encrypted original order, attempt, checkout and authoritative payment evidence, acquires the coordinated resource locks and checks the supported frozen purchase. It validates purchased revisions and exact asset identity rather than substituting current published offers, license choices or the latest preview. Administrative scope blocks and durable exclusive sales still govern whether the retained purchase can safely finalize. The retained rights declaration must remain verified, match its frozen identity and still be the latest declaration for that track; a newer declaration blocks fresh finalization as `rights_unavailable`. Finalization retains the observed declaration IDs and per-scope control versions/blocked state. Ordinary metadata, price, license or preview successors do not substitute for the frozen purchase or invoke broad current publication readiness. Partial fulfillment of a mixed order is unsupported.

| Result | Committed effects | Resources and fulfillment |
| --- | --- | --- |
| `paid` | One immutable finalization; one grant per original line; required exclusive sales; pending exact-asset entitlements; one pending outbox entry per grant; audit | Inventory reservation and any promotion use become `consumed` atomically. Fulfillment is `pending_contracts`. |
| `paid_exception` | One immutable finalization with a bounded safety reason; one pending exception outbox entry; audit | Resources remain `pending`. No grant, exclusive sale or entitlement is created. Fulfillment is `blocked`. |
| `changed` | No new terminal effects | Corrupt/mismatched frozen evidence fails closed. Existing evidence is preserved for investigation. |
| `retry` | No new terminal effects | Transient database/storage or other infrastructure failure remains retryable; it does not manufacture a permanent exception. |
| No authoritative confirmation or disabled/invalid policy | No new terminal effects | No grant or release; retain evidence and occupied pending resources. |

Paid-exception reasons are `late_confirmation`, `inventory_blocked`, `inventory_unavailable`, `asset_unavailable` and `rights_unavailable`. They describe explicit observed safety failures after valid payment evidence. A known unavailable asset or fresh digest mismatch may establish `asset_unavailable`; a temporarily unreadable storage/provenance path instead requires retry. A missing or corrupt frozen binding is `changed`. None of these paths issues a refund or releases resources automatically.

All paid effects commit together. A failed grant, entitlement, outbox or resource update rolls back the entire candidate finalization. The already committed verified payment remains available for recovery. Repeated processing converges on the retained result only after verifying the full effect graph; finding an existing finalization row is not sufficient proof of completeness.

Consumed promotion usage continues to occupy its campaign's lifetime capacity. A durable exclusive sale has unique shared-scope ownership and prevents new claims across sibling/successor offers for that scope. Previously valid grants are preserved. No refund restock, grant revocation or silent release is implemented.

## Immutable schema and render input

Migration `2026_09_26_000020_test_order_finalization.php` is additive. The new graph has restrictive foreign keys and SQL/ORM append-only guards:

| Record | Binding and uniqueness | Boundary |
| --- | --- | --- |
| `order_finalizations` / `OrderFinalization` | Unique public UUID, order, verified payment and order attempt; test mode, explicit policy version, frozen result/reason and encrypted evidence | One terminal decision per supported confirmed order; confirmation and effective timestamps remain explicit. |
| `license_grants` / `LicenseGrant` | Unique public UUID and original order line; paid finalization and exact offer/license/scope references; encrypted render input | One grant per purchased line. The original commercial promise cannot be rewritten from current settings. |
| `pending_entitlements` / `PendingEntitlement` | Unique grant/asset/role; exact manifest asset, hash and size | Pending only. No URL, download permission or active entitlement is created. |
| `fulfillment_outbox` / `FulfillmentOutbox` | Unique public UUID and finalization/effect key | Pending only; one `grant:<position>` entry per paid line or one `exception` entry. No worker dispatches PDFs or notifications yet. |
| `exclusive_sales` / `ExclusiveSale` | Unique scope, grant and order line | Permanent recorded scope cutoff for this finalization; earlier valid grants remain intact. |

Inventory reservations and promotion uses gain `consumed_at`. Only a matching paid attempt/finalization can move `pending` to `consumed`, with the finalization timestamp and original identities retained. Paid exceptions leave them pending. Child effects use the finalization time. Outbox payloads contain only `schema_version`, opaque `finalization_id`, nullable `grant_id` and `evidence_hash`; buyer details, private paths and provider payloads are excluded.

Each grant's encrypted canonical render input is bounded by the 16 MiB evidence envelope and freezes:

- Original order, order-line, attempt, payment, finalization and grant identities, plus original order payload hash.
- Original supplied buyer legal name/email with `identity: unverified_guest`, and original seller policy.
- Original affirmative assent text/version, policy version, review hash and acceptance time.
- Exact original line selection, pricing, full license disclosure and inventory/scope binding, including purchased asset references.
- The exact finalization policy, local confirmation observation time and finalization/grant-effective time.

The full original buyer/seller/assent and commercial evidence stays encrypted and out of public projections, logs and outbox payloads. No new buyer identity claim is inferred. Existing license `renderer_version` describes historical review HTML provenance; WP-08 must separately pin the buyer PDF renderer, template, fonts/assets and archival output contract.

## Historical evidence and replay

Original prepared-order, attempt, line and commercial snapshots are not rewritten or rehashed. The original pending resource snapshot remains part of that evidence even after valid consumption. A narrow immutable finalization proof verifies the allowed resource disposition so the historical reader can reconstruct the original pending-state representation. A separate complete-graph verification then establishes that the current outcome actually matches all required effects.

The verifier checks authoritative retained payment, finalization policy and evidence, exact resource state/time, expected grant count and line identities, every encrypted render input, expected entitlement manifest/count, exclusive sales and outbox identities/payload/count. Missing, extra, corrupted or cross-bound effects fail closed. For paid exceptions, grants and exclusive sales must be absent and resources must still be pending. This separation avoids circular validation and prevents treating a consumed resource row alone as proof of valid payment or a grant.

Valid retained reads and identical owner-scoped preparation retries remain possible after ordinary expiry, current policy withdrawal and later catalog/customer settings changes. They use original evidence, not current configuration to recreate a historical agreement. Future fulfillment activation must deliberately extend this complete graph contract; pending rows cannot be arbitrarily changed to active or processed without a reviewed transition and verifier.

## Asynchronous dispatch and console recovery

The payment verification transaction commits immutable confirmation before finalization dispatch. `FinalizeTestPaymentJob` carries only an internal payment ID and runs through the same finalization boundary. Dispatch uses allowed asynchronous queue drivers and waits for transaction commit; a rolled-back transaction cannot enqueue valid work. Synchronous request execution does not finalize a purchase. Queue availability is not the only recovery mechanism: a failed/missed dispatch leaves the retained confirmation discoverable by the bounded scanner.

```sh
php artisan vasey:finalize-test-payments --limit=25
php artisan vasey:finalize-test-payments --limit=25 --after=LAST_ATTEMPTED_ORDER_UUID
php artisan vasey:finalize-test-payments ORDER_UUID
```

An explicit order selector is its opaque public UUID in the configured account/test scope. The default scan covers confirmed orders lacking a finalization, ordered by immutable internal order ID, with a limit of 1–100 (default 25). An explicit selector cannot be combined with `--after`. The cursor is also an opaque order UUID in the configured account/test scope; invalid or wrong-scope cursors are rejected.

Every nonempty scanned page emits `NEXT_AFTER=<last attempted order UUID>`, including when that order fails. Pass the cursor until a page is empty. Omit it for a later new sweep so earlier retryable failures can be attempted again. Repeatedly running only the first page does not automatically rotate through a backlog. Explicit retries of a finalized order verify the retained graph rather than duplicating effects. Commands emit bounded identifiers/outcomes, not private commercial or provider evidence.

## Customer status

Existing owner-checked order and checkout reads gain distinct payment, finalization and fulfillment fields. Order `status` is `prepared`, `paid` or `paid_exception`; checkout Session `status` keeps its existing contract. Coherent combinations are:

| Payment status | Finalization status | Fulfillment status | Order status and meaning |
| --- | --- | --- | --- |
| `not_started` / `not_verified` | `not_started` | `not_started` | `prepared`; no authoritative confirmation is exposed. Checkout uses `not_verified`, while an order without an intent may use `not_started`. |
| `verified` | `awaiting_finalization` | `not_started` | `prepared`; confirmation is verified, terminal purchase effects are pending. |
| `verified` | `paid` | `pending_contracts` | `paid`; grants/pending records exist, buyer contracts and downloads are not ready. |
| `verified` | `paid_exception` | `blocked` | `paid_exception`; payment is verified but fulfillment is blocked, with no automatic refund claim. |

The browser hides checkout/retry/reconcile actions and external payment links once it has a coherent verified result. A verified checkout requires a non-null intent ID and `url: null`. **Refresh test order status** performs owner-checked GETs only. A verified projection remains visible if a later refresh fails or regresses; recovered verified order status can preserve that safe display when the checkout GET is unavailable. Missing, unknown or incoherent fields produce a recoverable status error and cannot enable another payment or imply downloads.

Return/query parameters confer no authority. Reads validate retained evidence and do not call Stripe, create a grant, finalize an order or activate fulfillment. No payment evidence or buyer details are added to browser storage. Public projections expose no private contract input, asset paths, raw provider evidence or exception internals. The browser describes pending contracts/blocked fulfillment without presenting a downloadable license or completed delivery.

## Verification and operational rollback

The integrating PR must record its exact source and actual focused/full MySQL/SQLite results, frontend/build/browser outcomes, audits and independent reviews. Required cases include exact replay, missing/extra/tampered effect graphs, strict cutoff equality and timely-confirmation/late-worker behavior, policy withdrawal, late/exclusive/resource contention, promotion capacity after consumption, commit failure rollback, after-commit/missed dispatch, cursor progress past failures, no provider I/O, owner isolation and truthful read-only customer states. Independent-process MySQL tests establish concurrency; SQLite cannot substitute for them. Authored tests and source review are not executed acceptance.

Operational rollback disables new finalization while retaining receipts, payment evidence, original order records, grants, pending entitlements/outbox, exclusive sales, resource state and encryption keys. Do not erase financial history, reclassify a confirmed payment as unpaid or manually free consumed/pending capacity. Migration `down()` refuses populated finalization graph tables or consumed resources **before any schema change**. Empty down/up is for disposable development databases; migration tests explicitly recreate their disposable test database, never an operational environment.

Next is WP-08 offline deterministic buyer PDF generation, private original document/hash retention, guarded activation and owner-authorized downloads. Operator paid-exception resolution, verified unpaid release, refunds/disputes, production identity/legal/tax/timing policy, actual test-account interoperability, source migration and cutover remain pending. All 14 work packages and 103 parity requirements remain in the [ordered development record](development-order.md); this candidate completes none of those wider release obligations by implication.
