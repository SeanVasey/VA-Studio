# Customer data export and deletion requests

Status: **built default-off (lane CX, FP-082); not acceptance.** Sean approved the retention periods on 2026-11-07 (PRIVACY section 7, U-13), but the values are set per host and never in Git, so until a host sets them staff approval of a deletion request and the retention report both refuse. A read-only retention dry run exists (FP-076, first increment); nothing purges retained records yet. Evidence: [lane CX packet](verification/customer-comms-cx-20261024/README.md).

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

The JSON is encrypted at rest with the application key (`customer_data_exports.ciphertext`) and its SHA-256 is kept. The token is shown once and stored only as a SHA-256 hash. Downloading purges the ciphertext in the same transaction; an export left unused past `export_ttl_minutes` (default 15, bounded 5 to 60) is purged on the next request or by `php artisan vasey:customer-data-exports-prune`, which is not scheduled automatically (U-02 host).

## Staff review of deletion requests

**Customers → Customer data requests** (`/admin/customer-data-requests`) lists requests. Deletion requests in `received` offer **Approve deletion** and **Reject**. Both go through `CustomerDataRights`, which re-reads the `administer-catalog` gate under a row lock, applies the admin panel MFA rule and also requires an enrolled authenticator even where the panel does not, takes the request version captured when the confirmation opened, and records an audit event.

**Approval refuses while any retention value is unset.** Once configured, approval:

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
| `VASEY_CUSTOMER_DATA_RETENTION_FINANCIAL_YEARS` | Orders, payment evidence, contracts, grants, delivery records (1–100). |
| `VASEY_CUSTOMER_DATA_RETENTION_CONSENT_YEARS` | Consent and suppression ledgers (1–100). |
| `VASEY_CUSTOMER_DATA_RETENTION_CORRESPONDENCE_YEARS` | Inquiries, replies, attachments (1–100). |
| `VASEY_CUSTOMER_DATA_RETENTION_SECURITY_YEARS` | Audit events, identity challenges, download authorizations (1–100). |

These values are recorded with each approval and drive the read-only retention report below; no code purges retained records when a period ends. Follow-up (independent CX review F4): schedule `vasey:customer-data-exports-prune` once a production host exists (U-02); until then the export lifetime is a download window, not a storage limit, except that deletion approval purges open exports. A purge of immutable records needs its own reviewed migration and Sean's schedule.

## Retention schedule and dry-run report (FP-076, first increment)

Sean approved the periods on 2026-11-07 (PRIVACY section 7; decision register "Register update — 2026-11-07"). The configuration reading of them is financial 7 years, consent 3, correspondence 2 and security 1, set per host in the variables above and never committed. `App\Domain\Customers\DataRights\RetentionSchedule` is the single source of truth: one entry per table, with:
- a retention class: `financial`, `consent`, `correspondence`, `security`, `indefinite` or `not_applicable`;
- the timestamp columns that start its clock;
- a rationale.

It covers every table of every `CustomerDataRights::RETAINED` record family, every table the export and deletion code reads, and the export sections' tables. `CustomerDataRetentionReportTest` fails when one of those tables is unclassified or classified twice, or when a table would loosen its family's class (it may only keep the family's period or be held as `indefinite`).

Every other migrated table is either listed in `RetentionSchedule::NOT_CUSTOMER_DATA` (reviewed, by reading its migration or schema class, as holding no customer data) or reported by the dry run as **unclassified**. A second test pins the exact unclassified set and checks that every migrated table is scheduled, reviewed or reported, exactly once, so a new migration fails it until the table is classified deliberately.

| Class | Tables | Clock |
| --- | --- | --- |
| `indefinite`, never purged by this mechanism | `orders`, `order_lines`, `order_finalizations`, `license_grants`, `grant_contracts`, `verified_payments`, `customer_purchase_claims`, the four production checkout record tables, `production_identity_origins`, the six `paid_*`/`paid_tax_*` order origins, grant origins and originals, `inquiry_order_contexts` | none: order snapshots, published licenses, original contracts, verified payments and other purchase evidence (AGENTS.md invariants). PRIVACY keeps them while the license can be used and at least 7 years. |
| `financial`, minimum period only | `test_delivery_authorizations` (`issued_at`, `expires_at`), `test_delivery_redemptions` (`redeemed_at`), `paid_authorizations`/`paid_tax_authorizations` (`created_at`, `expires_at`), `paid_redemptions`/`paid_tax_redemptions` (`created_at`) | Download records. Past-period rows are review candidates only, because the license lifetime still applies. |
| `consent`, held | consent events and states, the four `customer_suppression_*` tables | Not modeled. PRIVACY starts the clock when the email address is no longer held, and an opt-out must keep being honored. |
| `correspondence` | `customer_inquiries`, with `inquiry_messages` and `inquiry_notification_intents` following their conversation | 2 years after the last message. A conversation linked to an order is never counted. |
| `correspondence` | `transactional_notices` (`created_at`), `transactional_mail_deliveries` (`queued_at`, `finished_at`) | Never past while a delivery is unfinished. `support_attachments` is held: its source is polymorphic and no clock is modeled. |
| `security` | `customer_identity_challenges` (`created_at`, `expires_at`), `customer_purchase_challenges` (`created_at`, `expires_at`) | PRIVACY: verification and recovery code records, 12 months. A purchase challenge that a purchase claim references is never counted. |
| `security`, held until Sean decides | `audit_events`, `customer_data_requests`, `customer_data_request_events`, `customer_data_exports` | None. PRIVACY section 7 gives audit and data-request evidence no period, and these rows are the evidence that a deletion was honored, of role changes and of held orders. The report prints `held=clock_not_modeled` and never counts them. Deletion approval still keeps them (`CustomerDataRights::RETAINED` is unchanged). |
| `not_applicable` | `users`, `customer_accounts`, `customer_saved_tracks`, `sessions`, `customer_consent_policies` | Account lifecycle, handled by deletion approval, or not customer data. |

A row is past its period only when every clock column is set and at or before the cutoff (now minus the class's years).

`php artisan vasey:retention-report` is a dry run:
- It reads with plain selects only: no transaction, no locks and no writes. It scans each table in primary-key ranges of 10,000, so no single query is unbounded.
- It prints, per class and table, `past_period` (a count) and the oldest and newest due dates (date only), or `held=clock_not_modeled`.
- It lists the indefinite and not-applicable tables by name only. It never prints identifiers, names or addresses.
- It prints `unclassified=<names> count=<n> note=each_needs_owner_retention_decision`: tables of the connection's own schema (SQLite `main`, the configured MySQL database; other schemas and tables without the connection's prefix are excluded) that are neither scheduled nor reviewed as not customer data. Names only. Unclassified tables never fail the command; each needs Sean's retention decision before any purge design covers it.
- It refuses with a non-zero exit (`refused=retention_policy_unset`) while any retention value is unset or invalid, the same check deletion approval uses, so periods are never inferred.
- It also refuses (`refused=retention_schedule_table_missing`) when a scheduled table or clock column is missing.

None of the counted clock columns has an index (the report prints `clock_index=missing`). The primary-key chunks keep each query bounded, but each run still reads every row of the counted tables; an index belongs with the later purge migration.

**Purge or anonymization is a later, separately authorized step.** It needs its own reviewed migration, because most of these tables refuse `DELETE` through database triggers. It also needs Sean's explicit authorization, and the open questions below need answers first. This increment has no delete or anonymize code path.

### Reviewed as not customer data

`RetentionSchedule::NOT_CUSTOMER_DATA` (64 tables) holds framework, catalog, storefront, license-template and staff-authored definition tables; each entry carries a one-line reason. Staff actor ids can appear in them; no customer, buyer, account or recipient column does. They are: `migrations`; catalog `tracks`, `track_metadata_presets`, `media_assets`, `media_processing_runs`, `media_upload_sessions`, `stems_recordings`, `rights_declarations`, `rights_scopes`, `rights_scope_offers`, `exclusive_activations`, `offers`, `offer_revisions`, `catalog_import_batches`, `catalog_import_mappings`, `catalog_discovery_epoch`, the three `discovery_sitemap_*` tables; license `license_templates`, `license_versions`, `license_review_evidence`, `vasey_license_contract_texts` (template and renderer profile by hash, shared by every buyer); storefront `site_images`, `site_image_variants`, `site_releases`, `site_release_images`, `site_publications`, `site_publication_revisions`, `site_publication_schedules`; drafts `sound_kit_drafts`, `sound_kit_revisions`, `sound_kit_upload_sessions`, `product_drafts`, `product_draft_versions`, `product_draft_members`, `merch_drafts`, `merch_draft_versions`, `service_drafts`, `service_draft_versions`; plans and definitions `membership_plans`, `membership_plan_versions`, `production_membership_plan_versions`, `promotion_campaigns`, `promotion_availabilities`, `promotion_availability_revisions`, `free_definitions`, `free_reviews`, `free_availability`, `production_free_definitions`, `production_free_reviews`, `production_free_availability`, `production_consent_policies`; production track policy `production_track_policy_drafts`, `production_track_policy_versions`, `production_track_policy_source_reviews`, `production_track_capability_candidates`, `production_track_capability_approvals`, `production_track_capability_closures`, `production_track_preparation_packets`, `production_track_preparation_packet_lines`; `transactional_mail_scan_progress` (scanner cursors); and operational health `scheduler_heartbeats` and `backup_evidence` (FP-028: task names, times, backup kind, status, size and dump hash).

### Unclassified: each needs Sean's retention decision

The dry run reports these 111 tables by name. None is purged or counted. A table stays here when it holds or links to customer data, or when its payload could not be confirmed free of it. When Sean decides a family's retention, its tables move into `RetentionSchedule::ENTRIES` (with a class and clock) or, if reviewed as not customer data, into `NOT_CUSTOMER_DATA`, and the pinned list in the test changes in the same commit.

| Tables | Why unclassified |
| --- | --- |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Framework stores whose keys and payloads can hold anything, including rate-limit keys derived from IP addresses and serialized job payloads with customer data. |
| `password_reset_tokens` | Holds a customer email address with a reset token hash. |
| `legacy_history_batches`, `legacy_history_customers`, `legacy_history_orders`, `legacy_history_lines`, `legacy_history_contracts`, `legacy_history_contract_declarations`, `legacy_history_grant_reviews`, `legacy_exclusivity_authority_events` | Imported BeatStars customer, order and contract history (and its batch membership); its retention is part of the cutover decision. |
| `quotes`, `quote_lines`, `quote_owners`, `quote_pricings`, `inventory_reservations`, `inventory_claims`, `order_attempts`, `promotion_uses`, `exclusive_sales`, `checkout_intents`, `checkout_sessions`, `checkout_observations`, `pending_entitlements`, `fulfillment_outbox`, `contract_render_requests`, `contract_render_work` | Checkout and fulfillment state keyed to a buyer's owner key, order or grant. Some may be purchase evidence (exclusive sales); not decided. |
| `stripe_webhook_receipts`, `stripe_receipt_work`, `payment_observations` | Payment provider events and observations: payment evidence that can carry customer details. |
| `refund_dispute_objects`, `refund_dispute_observations`, `refund_dispute_statuses`, `refund_dispute_effects`, `refund_dispute_holds`, `refund_dispute_work`, `refund_line_allocations` | Refund and dispute evidence of an order. |
| `test_delivery_controls`, `test_fulfillment_activations`, `test_payment_exception_events`, `test_payment_exception_work`, `test_payment_financial_observations`, `test_refund_resolution_requests`, `test_refund_resolutions`, `test_unpaid_releases`, `test_unpaid_release_events`, `test_unpaid_release_work` | Test-commerce controls and exception records linked to orders; default-off, but they reference customer orders. |
| `free_origins`, `free_originals`, `free_document_work`, `free_authorizations`, `free_redemptions`, `paid_fulfillments`, `paid_document_work`, `paid_tax_fulfillments`, `paid_tax_document_work` | Free-grant records of a customer account, and paid-chain work rows linked to a buyer's grant. |
| `membership_credit_buckets`, `membership_credit_events`, `service_projects`, `service_project_events` | A customer's membership credits and service projects. |
| `customer_identity_addresses`, `transactional_notice_attempts` | Address keys derived from customer email addresses (identity challenge serialization); delivery attempts of a notice to a recipient. |
| the 46 `production_*` tables not listed above (`production_account_feature_bindings`, `production_buyer_assent_observations`, the remaining `production_checkout_*`, `production_consent_*` states and events, `production_free_*` origins to redemptions and revocations, the `production_identity_*` tables other than `production_identity_origins`, `production_listening_libraries`, `production_member_*`, `production_membership_*` billing, periods, redemptions and credit events, `production_suppression_*`, `production_tax_checkout_bindings`, `production_tax_checkout_requests`, `production_tax_checkout_reviewed_sessions`) | Production customer identity (U-07), consent, membership, free-grant and checkout records, default-off and unconfigured. `production_member_profiles` and `production_member_definitions` are probably renderer profiles and definitions with no account column, but their encrypted payloads were not confirmed, so they stay unclassified. |

Open questions for Sean:
- Retention of the audit and data-request evidence (`audit_events`, `customer_data_requests`, `customer_data_request_events`, `customer_data_exports`). PRIVACY section 7 gives it no period, and it holds the evidence that a deletion was honored, role changes and held paid orders; the report holds it until this is decided.
- Retention of each unclassified table family above.
- What ends "while we hold your email address" for consent and suppression records: account erasure, or the end of the order records that keep the email?
- Download records are bound to the license lifetime as well as the 7 years: is there a cutoff for perpetual licenses?
- Do support attachments follow their conversation?
- The production identity tables (U-07) beyond `production_identity_origins` are not scheduled yet.

Deferred follow-ups (independent review P3s, 2026-11-09):
- Download-record clock vs license lifetime: download records count from their own `issued_at`/`expires_at` or `created_at`, while PRIVACY counts 7 years from the last transaction on the order and keeps them while the license can be used. Align the clock with the order (or keep them indefinite) before any purge.
- `transactional_notices` class: order-ready notices are classed `correspondence` (2 years); PRIVACY could read them as order records (7 years and the license lifetime). Decide the class.
- `config/customer-data-rights.php` line 24 comment: it says the security period covers download authorizations, which are financial in the schedule, and the retention block's opening comment still says Sean has not approved a schedule. Correct both comments, and the `VASEY_CUSTOMER_DATA_RETENTION_SECURITY_YEARS` row in the configuration table above, which repeats them (the report applies that period only to identity and purchase challenges).

## Records

Concurrency (independent re-review F7): every command takes the customer's `users` row first (staff approval takes the staff row, then the same customer rows), then the request and export rows by primary key. Each command is one READ COMMITTED transaction, retried up to three times on a deadlock or lock-wait timeout (the prune command included). Contention is judged by the driver's error code only (MySQL 1213, 1205 or SQLSTATE 40001; SQLite busy or locked), never by message text, which carries the SQL and its bound values. No command locks a range of a shared index, so one customer's request never waits on another customer's. A request's next event sequence comes from its own `last_event_sequence`, never from a read over the event index. Contention that outlives the retries answers `503` with `reason: try_again` and `Retry-After: 5`. In the staff list, the same lasting contention shows a "Request not changed" notification asking staff to try again (the transaction rolled back, so nothing changed); any other database error is not hidden.

Migration `2026_10_24_268200_customer_data_requests` (additive, forward-only, `down()` refuses): `customer_data_requests` (one open request of each kind per account; one transition from open to terminal), `customer_data_request_events` (append-only; each event's sequence is the parent's `last_event_sequence` plus one, and the counter may only advance by one to an event that exists, so sequences are gap-free), `customer_data_exports` (ciphertext can only be purged). Database triggers enforce each rule on SQLite and MySQL.
