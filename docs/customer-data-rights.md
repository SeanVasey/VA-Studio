# Customer data export and deletion requests

Status: **built default-off (lane CX, FP-082); not acceptance.** Sean approved the retention periods on 2026-11-07 (PRIVACY section 7, U-13), but the values are set per host and never in Git, so until a host sets them staff approval of a deletion request and the retention report both refuse. A read-only retention dry run exists (FP-076); nothing purges retained records yet. Evidence: [lane CX packet](verification/customer-comms-cx-20261024/README.md).

## What a signed-in customer can do

All routes sit under `/account/data-requests` and answer `404` until `customer-data-rights.enabled` is `true` **and** the existing customer access policy admits the request (test customer accounts in `local`, `testing` or the hosted `staging` rehearsal). The existing `CustomerPrivacy` middleware applies: JSON `POST` bodies only, same origin, no query string, `Cache-Control: private, no-store`.

| Route | Body | Result | Rate limit |
| --- | --- | --- | --- |
| `GET /account/data-requests` | none | The customer's last 20 requests: kind, state, times; an unused export past its window reads as `expired`. | 30 a minute |
| `POST /account/data-requests/export` | `{"confirm": true}` | Builds the export now and returns `requestId`, a one-time `downloadToken`, `expiresAt` and `sizeBytes`. `409` while another export is open. | 3 an hour |
| `POST /account/data-requests/export/download` | `{"requestId": "…", "token": "…"}` | The JSON file as an attachment, once. `404` for another account or a wrong token, `410` once downloaded or expired. | 10 per 10 minutes |
| `POST /account/data-requests/deletion` | `{"confirm": true}` | Records a deletion request for staff review (`201`); a repeat returns the open request (`200`, `replayed: true`). Nothing is deleted yet. | 3 an hour |

### The export

The document holds only the requester's own records, selected through the signed-in principal's account and owner key, never by an identifier in the request: the account (id, name, email, created), orders (the same projection as order history, original or explicitly saved purchases, each ownership-checked), the license grants of those orders, the marketing consent ledger, inquiries linked to those orders (subject and message), and production checkout records with the buyer declarations frozen into them (export schema 3, D-30):
- `productionCheckoutReviews`, `productionCheckoutOrders` and `productionTaxCheckoutOrders`;
- each holds an id, the creation time, `buyerDeclarations` and, for orders, plain amount and line-count columns;
- `productionCheckoutExemptionBases`: the buyer's qualified exemption attestation (reference, source SHA-256, effective dates) with the zero tax and total it approved. The reference is free text the qualifying staff member typed (up to 512 characters) and is exported as stored; the staff member themselves is not exported.

They are found through the account's production identity origin with plain, non-locking reads. Each row is opened with its hash checked and must carry a sealed buyer binding for the same origin and account (a basis seals it inside its qualification request). Otherwise, or if a production table is missing, the export refuses with `503 production_record_unavailable`.

Schema 3 also adds the paid-grant copies of those declarations (`PaidGrantExportSection`). The V1 paid and V2 paid-tax grant chains run only in `local` and `testing` today (`PaidGrantPolicy`, `PaidTaxPolicy`):
- `paidGrantOrders` and `paidTaxGrantOrders`: one entry per paid order (id, order id, creation time), each with its lines (id, position, title, the copied `buyerDeclarations`), once rendered the original contract's SHA-256, size and creation time (`contractOriginal`, otherwise `null`), the line's redeemed downloads (`downloads`: each redeemed authorization's kind, creation time and redemption time), and the number of download authorizations the buyer requested but never redeemed, per kind (`unusedAuthorizations`, for example `{"master_wav": 2}`). Unused authorizations are not capped by the download path, so they are counted rather than listed: however many a buyer requests, their export cannot grow past its limits because of them.
- Paid orders and download authorizations are found through the account (`account_id`) only; lines, originals and redemptions only through those, with plain, non-locking reads.
- Each payload is opened with its hash checked. The order's sealed original buyer must name the account's production origin and the account, and every line must repeat that binding. An original's manifest and every authorization must seal the hash of one of the account's exported lines, and the order's line count must match its retained lines. Otherwise, or if a paid table is missing, the export refuses with `503 production_record_unavailable`.
- Paid-tax provider session, payment and account identifiers, storage paths, claim and request identifiers and token hashes are deliberately not exported.

Document work and fulfillment records hold only states and hashes, so they are neither exported nor listed as retained. Nor is `vasey_license_contract_texts`: it holds the v2 license templates and renderer profiles, stored once each by SHA-256 and shared by every buyer of a license version, not customer data. A v2 grant origin names its template only by hash, so the export never reads that table and its stored payloads stay about the size of a gate-off origin's (the byte budget below is unchanged), and a deletion neither erases nor lists it.

Inquiries sent from a browser session without an order are not linked to an account and are not included. These exports refuse with `413`, and the customer contacts support@vasey.audio:
- over `export_max_bytes` (default 1 MiB);
- over 500 orders;
- over 500 records in any one production checkout table, counted before any is opened;
- over 500 paid orders, 5,000 paid lines or originals (ten lines per order), or 50,000 redeemed downloads, in either paid-grant chain, counted before any is opened (unused download authorizations are only counted, so they never refuse an export);
- over 64 MiB of stored payloads (orders, lines, originals and redeemed downloads) in either paid-grant chain, summed in the database before any is loaded. Stored payloads are much larger than what is exported (about 23 KiB per paid line against well under 1 KiB exported), so this is reached at about as many lines as a full 1 MiB export holds; a larger account refuses instead of exhausting memory.

Production-signed-in customers cannot reach these routes yet (D-30 step 3b).

The JSON is encrypted at rest with the application key (`customer_data_exports.ciphertext`) and its SHA-256 is kept. The token is shown once and stored only as a SHA-256 hash. Downloading purges the ciphertext in the same transaction; an export left unused past `export_ttl_minutes` (default 15, bounded 5 to 60) is purged on the next request or by `php artisan vasey:customer-data-exports-prune`, which the scheduler runs every 15 minutes (:07, :22, :37, :52; `routes/console.php`), so an unused export's ciphertext lives at most about one window plus 15 minutes while the scheduler runs.

## Staff review of deletion requests

**Customers → Customer data requests** (`/admin/customer-data-requests`) lists requests. Deletion requests in `received` offer **Approve deletion** and **Reject**. Both go through `CustomerDataRights`, which re-reads the `administer-catalog` gate under a row lock, applies the admin panel MFA rule and also requires an enrolled authenticator even where the panel does not, takes the request version captured when the confirmation opened, and records an audit event.

**Approval refuses while any retention value is unset**: the policy reference and all seven periods (financial, consent, correspondence, security, evidence, abandoned checkout and operational). Once configured, approval:

- expires every open export of the account and purges its encrypted file in the same transaction (the export holds the pre-erasure profile and the customer can no longer download it); the request moves to `expired` with an `export_expired` event whose reason is `deletion_approved`, and the approval records `pending_exports: purged` (independent CX review F1). Export preparation and approval both lock the customer user row first, so an export that commits while approval waits is purged by that approval, and an export requested after approval is refused because access is withdrawn;
- withdraws customer access (`customer_accounts.active` false, access version incremented) and ends database sessions;
- erases the listening library (`customer_saved_tracks`);
- replaces the sign-in profile: name `Erased customer`, email `erased+<account id>@erased.invalid`, a random password, no verification;
- **keeps** orders, order lines, finalizations, license grants, contracts, verified payments, purchase claims, delivery records, production checkout reviews and orders (V1), tax checkout orders (V2) and exemption bases, the paid-grant order origins, grant origins, originals, download authorizations and redemptions of both paid chains (`paid_*`, `paid_tax_*`), consent and suppression ledgers, inquiries, identity challenges, order-ready intents (`transactional_notices`, whose encrypted capture holds the recipient email) and their mail deliveries, data request records and export rows (contents purged) and audit events exactly as they are, and records each class with its retention class in the request's `deletion_completed` event together with the configured retention values and policy reference.

Name and email inside retained order snapshots and contracts stay there: they are part of the financial and license record. Consent stays as recorded; nothing infers or rewrites it. Staff accounts are never erased through this workflow. Rejection needs a plain-text reason (no customer details) and changes no customer data.

## Configuration

`config/customer-data-rights.php`; every value is unset or off in `.env.example` and the ops templates:

| Key | Meaning |
| --- | --- |
| `VASEY_CUSTOMER_DATA_RIGHTS_ENABLED` | Default `false`. Also listed in `scripts/ops/capability-boundary.php`, so the staging deploy gate holds it false. |
| `VASEY_CUSTOMER_DATA_RETENTION_POLICY_REFERENCE` | Reference to Sean's approved retention schedule (plain text, at most 200 characters). |
| `VASEY_CUSTOMER_DATA_RETENTION_FINANCIAL_YEARS` | Orders, payment evidence, contracts, grants, delivery records (1–100 years). Recorded with each approval; the report holds every such record with its order. |
| `VASEY_CUSTOMER_DATA_RETENTION_CONSENT_YEARS` | Consent and suppression ledgers (1–100 years). |
| `VASEY_CUSTOMER_DATA_RETENTION_CORRESPONDENCE_YEARS` | Inquiries, replies, attachments, order-ready notices (1–100 years). |
| `VASEY_CUSTOMER_DATA_RETENTION_SECURITY_YEARS` | Identity and purchase challenges, verification records, password reset tokens (1–100 years). |
| `VASEY_CUSTOMER_DATA_RETENTION_EVIDENCE_YEARS` | Privacy request records, their events and export metadata (1–100 years). |
| `VASEY_CUSTOMER_DATA_RETENTION_ABANDONED_CHECKOUT_DAYS` | Quotes and production checkout reviews that did not become an order, and unpaid production orders whose provider session was observed expired (1–100 days). |
| `VASEY_CUSTOMER_DATA_RETENTION_OPERATIONAL_DAYS` | Framework job queue and failed jobs (1–100 days). |

These values are recorded with each approval and drive the read-only retention report below; no code purges retained records when a period ends. Independent CX review F4 is addressed: `vasey:customer-data-exports-prune` is scheduled every 15 minutes, so the export lifetime is also a storage limit wherever the scheduler runs (its last outcome shows on the admin dashboard's scheduled-command metric); deletion approval still purges open exports at once. A purge of immutable records needs its own reviewed migration and Sean's schedule.

## Retention schedule and dry-run report (FP-076)

Sean approved the periods on 2026-11-07 (PRIVACY section 7; decision register "Register update — 2026-11-07"). On 2026-11-09 he delegated the questions the first increment left open, and the decision register records them ("Register update — 2026-11-09"). The configuration reading of the periods is:
- financial 7 years, consent 3, correspondence 2 and security 1;
- evidence 7 years;
- abandoned checkouts 30 days;
- operational 14 days.

They are set per host in the variables above and never committed.

`App\Domain\Customers\DataRights\RetentionSchedule` is the single source of truth: one entry per table, with:
- a retention class: `financial`, `consent`, `correspondence`, `security`, `evidence`, `abandoned_checkout`, `operational`, `indefinite` or `not_applicable`;
- the timestamp columns that start its clock, and their storage format (`datetime`, ISO-8601 text or Unix seconds);
- a rationale.

It covers:
- every table of every `CustomerDataRights::RETAINED` record family;
- every table the export and deletion code reads, and the export sections' tables;
- every table family the 2026-11-09 decisions classify.

`CustomerDataRetentionReportTest` fails when one of those tables is unclassified or classified twice, or when a table would loosen its family's class (it may only keep the family's period or be held as `indefinite`).

Every other migrated table is either listed in `RetentionSchedule::NOT_CUSTOMER_DATA` (reviewed, by reading its migration or schema class, as holding no customer data) or reported by the dry run as **unclassified**. A second test pins the exact unclassified set and checks that every migrated table is scheduled, reviewed or reported, exactly once, so a new migration fails it until the table is classified deliberately.

| Class | Tables | Clock |
| --- | --- | --- |
| `indefinite`, never purged by this mechanism | `orders`, `order_lines`, `order_finalizations`, `license_grants`, `grant_contracts`, `verified_payments`, `customer_purchase_claims`, the production tax orders, the production checkout exemption bases and authorities, `production_identity_origins`, the six `paid_*`/`paid_tax_*` order origins, grant origins and originals, `inquiry_order_contexts` | None. Order snapshots, published licenses, original contracts, verified payments and other purchase evidence (AGENTS.md invariants). PRIVACY keeps them while the license can be used and at least 7 years. |
| `indefinite`, held with the order (2026-11-09) | Download records: `test_delivery_authorizations`, `test_delivery_redemptions` and the four `paid_*`/`paid_tax_*` authorizations and redemptions. Checkout state bound to an order snapshot: `order_attempts`, `checkout_intents`, `checkout_sessions`, `checkout_observations`, and the tax checkout requests and bindings. Payment: `payment_observations`, `stripe_webhook_receipts`, `stripe_receipt_work`, `production_checkout_payments`, `production_tax_checkout_reviewed_sessions`. The seven `refund_*` tables. Fulfillment: `fulfillment_outbox`, `pending_entitlements`, `contract_render_*`, the paid and paid-tax fulfillments and document work, `service_projects`, `service_project_events`. Grants: `exclusive_sales`, the five `free_*` and six `production_free_*` tables, the five `production_member_*` tables. Membership billing: `membership_credit_*`, the seven `production_membership_billing_*` tables, `production_membership_paid_periods`, `production_membership_credit_events`, `production_membership_redemptions`. The eight `legacy_*` import tables. `audit_events`: they evidence license approvals, exclusive activations, payments, finalizations, refunds, role changes and deletions of records kept indefinitely. The production identity chain: `production_identity_verifications`, which every production read re-verifies from sequence 1 and which sealed order, grant, invoice and checkout bindings name. | None. PRIVACY's orders row: download records follow the order, never their own authorization timestamps. Every checkout intent, attempt and session row has a required order (or a parent with one), so none is a checkout without an order. |
| `consent`, held | consent events and states, the four `customer_suppression_*` tables, `production_consent_events`, `production_consent_states`, the four `production_suppression_*` tables | None. PRIVACY starts the clock when the email address is no longer held, and no column records that (see below). |
| `correspondence` | `customer_inquiries`; following their conversation: `inquiry_messages`, `inquiry_notification_intents` and the `support_attachments` whose source kind is `inquiry` | 2 years after the last message. A conversation linked to an order is never counted, and nor are its attachments. A service-project attachment never follows a conversation, so it is never counted. |
| `correspondence` | `transactional_notices` (`created_at`), `transactional_notice_attempts` (follows its notice), `transactional_mail_deliveries` (`queued_at`, `finished_at`) | Never past while a delivery is unfinished. |
| `security` | `customer_identity_challenges`, `customer_purchase_challenges`, `production_identity_challenges` (`created_at`, `expires_at`); `production_identity_notices`, `production_identity_outcomes` (`created_at`); `production_identity_attempts` (`started_at`, `lease_expires_at`) | PRIVACY: verification and recovery code records, 12 months. Never counted: a purchase challenge that a purchase claim references, and a production challenge that an identity origin (`initial_challenge_id`) or a verification (`challenge_id`) references, because that challenge is part of the verified chain. Notices, attempts and outcomes are not read by `ProductionCustomerAccess::read()`/`historical()`; outcomes bind to a send attempt, not to an order. |
| `security`, held | `customer_identity_addresses`, `production_identity_addresses`, `password_reset_tokens` | None. No integer primary key or no timestamp to scan. A production address that an identity origin binds is kept with the orders that origin is sealed into. |
| `evidence` | `customer_data_requests` (`resolved_at`); following their request: `customer_data_request_events` and `customer_data_exports` (metadata) | PRIVACY: records of privacy requests and of what we did about them, 7 years after the request closes. An open request has no `resolved_at` (the close transition sets it, and the database triggers enforce that), so it and its events are never counted. Export file contents are out of scope: they are purged on download, expiry or deletion approval. |
| `abandoned_checkout` | `quotes` (`created_at`, `expires_at`), with `quote_lines`, `quote_pricings`, `inventory_reservations`, `inventory_claims` and `promotion_uses` following it; `production_checkout_reviews` (`created_at`, ISO-8601 text) | PRIVACY: quotes and checkout reviews that did not become an order, 30 days after they end. A quote that an order references (`orders.quote_id`), or a review that a production order references (`production_checkout_orders.review_id`), is never counted. `quote_owners` is held: it has no integer key, and orders share it. |
| `abandoned_checkout`, unpaid production orders (2026-11-10) | `production_checkout_observations` (`created_at`, ISO-8601 text, only a row whose `kind` is `session_expired` and that is its intent's latest observation); following it: `production_checkout_provider_intents`, then `production_checkout_sessions` and `production_checkout_orders`, then `production_checkout_order_lines` and `production_checkout_attempts` | An unpaid production order whose provider session was observed expired: 30 days after that observation was recorded. Never counted once a later observation follows the expiry (a failed or refused read), or while the intent has a payment (`production_checkout_payments.intent_id`), so a paid, held, refunded or disputed order stays with the orders row; nor is an order with no observed expiry. See "Unpaid order snapshots" below. |
| `operational` | `jobs` (`created_at`, Unix seconds), `failed_jobs` (`failed_at`) | PRIVACY application error log row: 14 days. `job_batches` is held because its primary key is a string. |
| `not_applicable` | `users`, `customer_accounts`, `customer_saved_tracks`, `sessions`, `customer_consent_policies`; `cache`, `cache_locks` (expiring stores, the rate-limit row); the ten `test_*` test-commerce tables (they must stay empty in production) | Account lifecycle (handled by deletion approval), an expiring store, test commerce, or not customer data. |

A row is past its period only when every clock column is set and at or before the cutoff: now minus the class's years or days. The cutoff is compared in the column's own storage format, so ISO-8601 text and Unix-second columns compare exactly on SQLite and MySQL. A `via` row is past only when its parent is past. A `when` condition limits a clock to rows whose column holds one value (one attachment source kind, one observation kind); any other row is never past. A `latest_in` condition (group column, order column) counts a row only while it is the last row of its group; the query is the same correlated `NOT EXISTS` on SQLite and MySQL. Parents can chain, as in an inventory claim that follows its reservation, which follows its quote.

`php artisan vasey:retention-report` is a dry run:
- It reads with plain selects only: no transaction, no locks and no writes. It scans each table in primary-key ranges of 10,000, so no single query is unbounded.
- It prints, per class and table, `past_period` (a count) and the oldest and newest due dates (date only), or `held=clock_not_modeled`.
- It lists the indefinite and not-applicable tables by name only. It never prints identifiers, names or addresses.
- It prints `unclassified=<names> count=<n> note=each_needs_owner_retention_decision`: tables of the connection's own schema (SQLite `main`, the configured MySQL database; other schemas and tables without the connection's prefix are excluded) that are neither scheduled nor reviewed as not customer data. Names only. Unclassified tables never fail the command; each needs Sean's retention decision before any purge design covers it.
- It refuses with a non-zero exit (`refused=retention_policy_unset`) while any retention value is unset or invalid, the same check deletion approval uses, so periods are never inferred.
- It also refuses (`refused=retention_schedule_table_missing`) when a scheduled table or clock column is missing.

Most counted clock columns have no index (the report prints `clock_index=missing`; `quotes.created_at` is indexed). The primary-key chunks keep each query bounded, but each run still reads every row of the counted tables; an index belongs with the later purge migration.

Before any purge of abandoned quotes, the purge must confirm that each one's `inventory_reservations` and `inventory_claims` are terminal (expired or released), so that removing them cannot free or double-sell an exclusive scope.

**Purge or anonymization is a later, separately authorized step.** It needs its own reviewed migration, because most of these tables refuse `DELETE` through database triggers. It also needs Sean's explicit authorization. This increment has no delete or anonymize code path.

### Reviewed as not customer data

`RetentionSchedule::NOT_CUSTOMER_DATA` (64 tables) holds framework, catalog, storefront, license-template and staff-authored definition tables; each entry carries a one-line reason. Staff actor ids can appear in them; no customer, buyer, account or recipient column does. They are: `migrations`; catalog `tracks`, `track_metadata_presets`, `media_assets`, `media_processing_runs`, `media_upload_sessions`, `stems_recordings`, `rights_declarations`, `rights_scopes`, `rights_scope_offers`, `exclusive_activations`, `offers`, `offer_revisions`, `catalog_import_batches`, `catalog_import_mappings`, `catalog_discovery_epoch`, the three `discovery_sitemap_*` tables; license `license_templates`, `license_versions`, `license_review_evidence`, `vasey_license_contract_texts` (template and renderer profile by hash, shared by every buyer); storefront `site_images`, `site_image_variants`, `site_releases`, `site_release_images`, `site_publications`, `site_publication_revisions`, `site_publication_schedules`; drafts `sound_kit_drafts`, `sound_kit_revisions`, `sound_kit_upload_sessions`, `product_drafts`, `product_draft_versions`, `product_draft_members`, `merch_drafts`, `merch_draft_versions`, `service_drafts`, `service_draft_versions`; plans and definitions `membership_plans`, `membership_plan_versions`, `production_membership_plan_versions`, `promotion_campaigns`, `promotion_availabilities`, `promotion_availability_revisions`, `free_definitions`, `free_reviews`, `free_availability`, `production_free_definitions`, `production_free_reviews`, `production_free_availability`, `production_consent_policies`; production track policy `production_track_policy_drafts`, `production_track_policy_versions`, `production_track_policy_source_reviews`, `production_track_capability_candidates`, `production_track_capability_approvals`, `production_track_capability_closures`, `production_track_preparation_packets`, `production_track_preparation_packet_lines`; `transactional_mail_scan_progress` (scanner cursors); and operational health `scheduler_heartbeats` and `backup_evidence` (FP-028: task names, times, backup kind, status, size and dump hash).

### Unclassified

The dry run reports three tables by name. None of them fits a PRIVACY section 7 row, so none is counted.

| Table | Why unclassified |
| --- | --- |
| `production_account_feature_bindings` | Binds a production account to its customer features. This is account data, but the deletion workflow does not reach production accounts yet (D-30 step 3b), so `not_applicable` would claim a lifecycle that does not exist. |
| `production_listening_libraries` | Production listening library. PRIVACY's library row ends at account closure, and nothing closes a production account yet. |
| `production_buyer_assent_observations` | A staff report of the assent text a buyer saw. It is neither verified buyer identity nor proof of a buyer act, and it binds to no order. |

When one of them is decided, it moves into `RetentionSchedule::ENTRIES` (with a class and clock), and the pinned list in the test changes in the same commit.

Questions the schema leaves open:
- **Consent clock.** "While we hold your email address" has no signal:
  - account closure records no timestamp;
  - deletion approval erases the sign-in email, but the address stays inside the retained order snapshots and contracts (PRIVACY's account row), so the address is still held.

  Consent and suppression records therefore stay held.
- **Unpaid order snapshots (decided 2026-11-09; proven and applied for the production checkout chain 2026-11-10).** Both checkout chains create the order snapshot (`orders`, `production_checkout_orders`) before payment. The decision register ("Unpaid order snapshots (retention)") lets a snapshot with no verified payment, whose provider session has expired, fall under the 30-day row, but only where a late webhook or reconcile provably cannot attach payment any more. See the next section.

Deferred follow-ups (independent review P3s, 2026-11-09):
- `transactional_notices` class: order-ready notices are classed `correspondence` (2 years); PRIVACY could read them as order records (7 years and the license lifetime). Decide the class.

## Unpaid order snapshots (FP-076, BL-08)

What was asked: prove, per checkout chain, that a webhook or reconcile arriving after an unpaid snapshot's provider session expired either is recorded against an order that is still kept, or is refused with its evidence kept, and never grants. Then apply the 30-day rule only where a terminal state exists: columns and values after which no payment can attach.

### Production checkout (V1): proven, applied

**Terminal state.** The order's intent has a `production_checkout_observations` row with `kind = 'session_expired'`, that row is the intent's latest observation (no row with the same `intent_id` and a higher `sequence`), and no `production_checkout_payments` row has that `intent_id`. The clock starts at that latest observation's `created_at`. If a later read failed or was refused and the expiry was then read again, the clock starts at the newer expired observation; the earlier one is never counted again.

**Why no payment can attach afterwards.**
- `HostedCheckout::record()` is the only code that inserts a payment row. It refuses a provider read whose session status differs from a retained `complete` or `expired` status (`terminal_regression`), and a confirmed payment needs a `complete` session.
- `HostedEvidence::intent()` repeats that check over the stored observations on every read, including the read `record()` makes after its own inserts, in the same transaction. Either check alone refuses the late payment (mutation-checked: removing one leaves the tests green, removing both fails five).
- `record()` reads the intent row first, which takes a row lock on MySQL, and observations are unique per intent and sequence. So an expiry and a paid read that race are applied one after the other, and the second is refused.
- This chain has no webhook. Its late paths are the operator's `vasey:production-checkout-reconcile` (with or without `--session`), the buyer's reconcile and the buyer returning to the checkout.

**What a late paid read does.** It is refused as `provider_uncertain`. No payment row and no grant are written. One `uncertain` observation is appended after the expired one, and every earlier row is unchanged. This is outcome (ii): refused, with evidence kept. The same happens when the provider reports the session expired but its payment succeeded.

**A refused read holds the order.** The `uncertain` row records only that a read failed. It does not record what the provider said, so a contradiction (money may have moved at the provider with no local payment row) cannot be told from an outage. From that row on, the expired observation is no longer the intent's latest, and the order, its lines, attempt, intent and session are not counted, however old. An outage clears itself: the next successful read that still shows the session expired appends a new expired observation, and the 30 days start again from it. A real contradiction never clears, and stays with the order for staff to reconcile.

**What terminality rests on.** It rests on this store refusing contrary reads, not on the provider. Nothing here proves the provider took no money; the store only proves it recorded none. Any future purge must therefore re-read the provider session immediately before removing a snapshot, and keep the snapshot unless the session is still expired and unpaid.

**Never counted.**
- A paid order, however old. Held, refunded and disputed orders all have a payment row, so the same hold covers them.
- An order in flight: an open session, a provider outage (`uncertain`), or a canceled payment attempt on an open session (`canceled`), which can still be paid.
- An order whose expired observation was followed by any later observation (see above).
- The review the order was built from, its exemption basis and `production_checkout_payments`.

**Left with the order on purpose.**
- *Expired session with a canceled payment attempt.* A late paid read is refused here too, but the observation kind is `canceled` whether the session is open or expired, so the column cannot tell this state from the non-terminal one. Missing: a column or kind that records the session status.
- *Order that never reached the provider.* It has no intent, and none can be created once the attempt's `expires_at` has passed, but the decision's wording covers only a provider session that expired. Missing: Sean's decision to include it.
- *Intent with no bound session* (the create response was lost). An operator reconcile with `--session` can still bind and pay it.
- *Other observations of an abandoned intent* (`session_open`, `uncertain`, an earlier expired observation). Only the latest expired-session row is the clock row, so the report undercounts that table. A purge design must take them with the intent.
- *The review an abandoned order was built from.* `production_checkout_orders.review_id` holds it (`unless_referenced`), so it is not counted with the order. Note for the purge design: it would fall due the moment a purge removed the order, with no 30 days of its own left to run.

### Stripe-test chain: not applied, no terminal state

- **Expiry recorded, no staff release.** A late webhook that reads the session as paid is refused (`payment_changed`); its receipt and a `quarantined` work row are kept. A late reconcile returns `payment_changed` and writes nothing. No verified payment, no grant. Outcome (ii).
- **After a staff release** (`test_unpaid_releases`). A late confirmed payment, by webhook or reconcile, is recorded against the order: a `verified_payments` row, then an `order_finalizations` row with outcome `paid_exception` and reason `released_attempt`. Never a grant. Outcome (i). This is the release contract (`UnpaidReleasePolicy::CONTRACT`, `late_confirmation: paid_exception_preserve_released`).

A release can follow any recorded expiry, and a released order accepts late money for as long as it exists. So no column state means "no payment can attach any more", and every table of this chain stays `indefinite`. Missing, for Sean to decide if this chain should ever be scheduled: either a released order refuses late confirmed money after a stated time, or a purge re-reads the provider session before removing each snapshot. These tables hold test-commerce records only.

### Tax checkout (V2): not examined further

`production_tax_checkout_*` stores a request, a binding and a reviewed paid session, with no expiry observation. It stays `indefinite`.

### Proposed PRIVACY wording (for Sean; `docs/legal/PRIVACY.md` is unchanged)

Section 7 row today:

> | Quotes and checkout reviews that did not become an order | 30 days after the quote or checkout ends. |

Proposed:

> | Quotes, checkout reviews and unpaid orders that did not become a purchase | 30 days after the quote or checkout ends. For an order you started but did not pay, that is 30 days after we record that the payment page expired without payment. An order you paid for is kept as an order record, including one that was later refunded or disputed. |

Notes for the review:
- "Unpaid orders" is narrower in the schedule than in this sentence: only the production checkout chain, and only when the expiry was observed. Everything else is kept longer, under the orders row, which the sentence allows.
- Section 7's orders row may also need "paid" added ("Paid orders, receipts, ...") so the two rows do not overlap.
- The report still deletes nothing. The wording describes an intention until a purge is authorized.

### Evidence

`tests/Feature/CustomerComms/ProductionUnpaidSnapshotRetentionTest.php` and `StripeTestUnpaidSnapshotRetentionTest.php`, with synthetic provider evidence, on SQLite. Native MySQL 8.4: not yet run on this commit (recorded separately when it completes). Hosted Stripe behavior is not exercised.

## Records

Concurrency (independent re-review F7): every command takes the customer's `users` row first (staff approval takes the staff row, then the same customer rows), then the request and export rows by primary key. Each command is one READ COMMITTED transaction, retried up to three times on a deadlock or lock-wait timeout (the prune command included). Contention is judged by the driver's error code only (MySQL 1213, 1205 or SQLSTATE 40001; SQLite busy or locked), never by message text, which carries the SQL and its bound values. No command locks a range of a shared index, so one customer's request never waits on another customer's. A request's next event sequence comes from its own `last_event_sequence`, never from a read over the event index. Contention that outlives the retries answers `503` with `reason: try_again` and `Retry-After: 5`. In the staff list, the same lasting contention shows a "Request not changed" notification asking staff to try again (the transaction rolled back, so nothing changed); any other database error is not hidden.

Migration `2026_10_24_268200_customer_data_requests` (additive, forward-only, `down()` refuses): `customer_data_requests` (one open request of each kind per account; one transition from open to terminal), `customer_data_request_events` (append-only; each event's sequence is the parent's `last_event_sequence` plus one, and the counter may only advance by one to an event that exists, so sequences are gap-free), `customer_data_exports` (ciphertext can only be purged). Database triggers enforce each rule on SQLite and MySQL.
