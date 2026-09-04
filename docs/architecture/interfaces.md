# Proposed application, HTTP and event contracts

Status: **Interface skeleton, not an implemented API guarantee**, 2026-09-04.

Laravel application commands are the primary internal boundary. Inertia pages need typed props rather than a duplicate SPA API. JSON endpoints are introduced where browser uploads, waveform/player state, provider callbacks or external clients require them. Public routes remain server-authorized even when controls are hidden.

## Shared envelope

IDs are opaque, server-generated stable strings. Mutating client commands carry an Idempotency-Key scoped by actor and operation when retries could duplicate an effect. Store request hash and outcome; same key/different body returns 409 idempotency_conflict. Browser session commands require CSRF; only narrowly defined authenticated provider endpoints use their provider verification instead.

Validation returns a stable code, field errors where safe and a correlation ID. Proposed common codes: unauthorized, forbidden, not_found, validation_failed, not_ready, quote_expired, price_changed, inventory_unavailable, idempotency_conflict, payment_pending, paid_exception and rate_limited. Never return internal object keys, raw payment payloads or another buyer's existence.

## Commands and HTTP surfaces

| Proposed surface | Actor / input | Result and enforcement |
| --- | --- | --- |
| GET /tracks; GET /tracks/{slug} | Public; validated filter, cursor and sort | Published metadata, public artwork, preview descriptor and structured offer cards; no private asset paths. |
| POST /media/previews/{publicId}/session | Public or permitted member; public preview ID | Bounded preview authorization, derivative only, cache/rate limits; never a master URL. |
| POST /cart/quote | Buyer/session; offer IDs, quantities, coupon and billing region if needed | Immutable server-calculated QuoteV1 or explicit readiness/price/inventory error. |
| POST /checkout/sessions | Quote owner; quote ID/hash and assent action | Existing/new provider session with pending order; disabled until readiness gate; no client totals. |
| GET /orders/{publicId}/status | Order owner or scoped single-use claim session | Safe authoritative pending/paid/fulfilled/exception state; no fulfillment mutation. |
| POST /webhooks/stripe | Provider; exact raw body/signature | Durable InboxReceiptV1; verified account/environment; 2xx after persistence, retryable error otherwise. |
| POST /entitlements/{publicId}/exchange | Entitlement owner; request key | Short-lived DownloadAuthorizationV1 or denial; counter/claim consumption atomic. |
| POST /admin/uploads; POST /admin/uploads/{id}/complete | Authorized editor; product, role, expected size/type/hash | Private upload session and processing state; quota/type/role checks. |
| POST /admin/products/{id}/publish | Publisher; expected product revision | Publication result or named readiness blockers; optimistic revision and audit. |
| POST /admin/licenses/{id}/submit-review, /approve, /publish | Specific rights roles; expected revision and approval reference | Lifecycle transition; self-approval restrictions as policy; published body remains frozen. |
| POST /admin/site-releases/{id}/publish | Publisher; expected active release | Atomic active pointer switch and audit; rollback points to an intact prior release. |
| POST /admin/orders/{id}/refund-review | Finance permission + step-up; scope/reason/policy | Audited review/command; no arbitrary “mark paid” or delete-grant button. |
| POST /admin/import-runs/{id}/commit | Migration operator; dry-run manifest hash | Restartable batch with provenance and conflict report; source evidence preserved. |

Route names are candidate contracts. The initial application may expose a smaller set. Update this table when an endpoint is implemented; do not leave imaginary routes in user documentation.

## Typed payload skeletons

```ts
type MoneyV1 = { minor: number; currency: string };
type AssetRefV1 = {
  revisionId: string; role: string; sha256: string;
  filename: string; bytes: number; mediaType: string;
};
type QuoteLineV1 = {
  productId: string; offerRevisionId: string; title: string;
  licenseVersionId: string; licenseModelHash: string;
  termsSnapshot: Record<string, unknown>; assets: AssetRefV1[];
  unitPrice: MoneyV1; discount: MoneyV1; quantity: number;
  inventoryScopeId?: string;
};
type QuoteV1 = {
  schemaVersion: 1; quoteId: string; expiresAt: string;
  lines: QuoteLineV1[]; subtotal: MoneyV1; discount: MoneyV1;
  taxMode: "fixed" | "provider_calculated";
  taxPolicyVersion: string; tax?: MoneyV1; total?: MoneyV1;
  policyVersionIds: string[]; disclosureHash: string;
  canonicalizationVersion: string; snapshotHash: string;
};
type DownloadAuthorizationV1 = {
  issuanceId: string; url: string; expiresAt: string;
  filename: string; bytes: number; sha256: string;
};
```

Buyer/seller legal fields belong in private quote/order records, not public typed page props. Generated client types must use integer-safe serialization; if the supported money range could exceed JavaScript safe integers, use decimal strings at the transport boundary and reject float conversion. Unknown schema versions are rejected rather than partially interpreted.

## Provider interfaces

| Interface | Operations / bounded responsibilities |
| --- | --- |
| PaymentGateway | Create/reconcile hosted session; verify raw webhook; fetch authoritative payment; request approved refund. Input includes environment, account scope and idempotency. Output excludes raw secrets. |
| ObjectStore | Create private upload authorization, inspect/hash object, copy quarantine object to immutable role, sign exact revision, delete under retention policy. Never accept arbitrary public URL as a trusted asset. |
| MediaProcessor | Validate bounded input; produce tagged preview/peaks/technical metadata; return immutable output manifests with hashes and tool versions. |
| ContractRenderer | Render only validated FrozenContractInputV1 with pinned local assets; return bytes/hash/version and comparison metadata. |
| NotificationProvider | Send a template/version with minimal recipient data and outbox idempotency; distinguish transactional notices from consent-gated marketing. |
| MembershipBilling | Reconcile subscription/invoice identity, effective period, payment/cancellation and plan mapping; no balance authority in provider metadata. |
| MerchFulfillment | Quote shipment, submit a paid approved order and reconcile shipment/return states; deduplicate external order IDs. |

Test adapters must be unmistakably test-only and cannot be enabled by production fallback on missing credentials.

## Event and job catalog

Envelope: event_id, schema_version, occurred_at UTC, aggregate_type/id/version, correlation_id, causation_id, actor_ref, payload. Domain/audit events use minimized internal identifiers; customer contact details are resolved only inside authorized notification workers.

| Event/job v1 | Commit origin | Idempotency / retry / recovery |
| --- | --- | --- |
| media.upload.received → InspectUpload | Upload completion transaction | Upload ID + object version; bounded scan retries; terminal quarantine visible to editor. |
| media.asset.verified → ProcessMedia | Verification transaction | Source hash + processor profile/version; retry-safe immutable output keys; no unsafe promotion. |
| catalog.product.published | Publish transaction | Product ID + publication revision; search/site cache updates from outbox. |
| rights.license.published | Review/publish transaction | License version ID/hash; audit and cache refresh, never rewrite sold terms. |
| commerce.checkout.requested → CreateCheckoutSession | Quote/reservation transaction | Checkout intent ID; reconcile timed-out provider call before repeat. |
| provider.event.received → ProcessProviderEvent | Durable inbox insert | Provider/account/environment/event ID plus downstream payment-object uniqueness. |
| commerce.order.paid → RenderContract | Finalization transaction | Grant ID + frozen snapshot hash + renderer version; retries never create a new grant. |
| commerce.order.paid_exception | Confirmed payment cannot fulfill | Order + exception version; alert/operator resolution, no automatic delivery. |
| delivery.contract.ready → ActivateFulfillment | Document persistence transaction | Order/grant readiness compare; activate once. |
| delivery.order.fulfilled → SendReceipt | Fulfillment transaction | Order + notification purpose/version; outbox delivery ledger suppresses duplicate receipts. |
| memberships.invoice.applied | Reconciled billing transaction | Provider invoice ID + benefit period; no duplicate credits. |
| migration.batch.committed | Import transaction | Import manifest hash + batch ID; deterministic mapping and reconciliation. |

Workers have explicit queue, timeout, maximum attempts, exponential backoff with jitter, terminal failure reason, correlation and authorized replay action. Outbox dispatch is at least once; consumers enforce idempotency. Every new job includes poison-payload and retry-after-partial-failure evidence. Operational values are configured from measured file sizes and provider limits; unbounded retries or silent job discard are prohibited.
