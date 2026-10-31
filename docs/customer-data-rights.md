# Customer data export and deletion requests

Status: **built default-off (lane CX, FP-082); not acceptance.** Retention values are unset because Sean has not approved a retention schedule (U-13, FP-076), so staff approval of a deletion request refuses. Evidence: [lane CX packet](verification/customer-comms-cx-20261024/README.md).

## What a signed-in customer can do

All routes sit under `/account/data-requests` and answer `404` until `customer-data-rights.enabled` is `true` **and** the existing customer access policy admits the request (test customer accounts in `local`, `testing` or the hosted `staging` rehearsal). The existing `CustomerPrivacy` middleware applies: JSON `POST` bodies only, same origin, no query string, `Cache-Control: private, no-store`.

| Route | Body | Result | Rate limit |
| --- | --- | --- | --- |
| `GET /account/data-requests` | none | The customer's last 20 requests: kind, state, times; an unused export past its window reads as `expired`. | 30 a minute |
| `POST /account/data-requests/export` | `{"confirm": true}` | Builds the export now and returns `requestId`, a one-time `downloadToken`, `expiresAt` and `sizeBytes`. `409` while another export is open. | 3 an hour |
| `POST /account/data-requests/export/download` | `{"requestId": "…", "token": "…"}` | The JSON file as an attachment, once. `404` for another account or a wrong token, `410` once downloaded or expired. | 10 per 10 minutes |
| `POST /account/data-requests/deletion` | `{"confirm": true}` | Records a deletion request for staff review (`201`); a repeat returns the open request (`200`, `replayed: true`). Nothing is deleted yet. | 3 an hour |

### The export

The document holds only the requester's own records, selected through the signed-in principal's account and owner key, never by an identifier in the request: the account (id, name, email, created), orders (the same projection as order history, original or explicitly saved purchases, each ownership-checked), the license grants of those orders, the marketing consent ledger, and inquiries linked to those orders (subject and message). Inquiries sent from a browser session without an order are not linked to an account and are not included. Exports over `export_max_bytes` (default 1 MiB) or over 500 orders refuse with `413`; the customer contacts support@vasey.audio.

The JSON is encrypted at rest with the application key (`customer_data_exports.ciphertext`) and its SHA-256 is kept. The token is shown once and stored only as a SHA-256 hash. Downloading purges the ciphertext in the same transaction; an export left unused past `export_ttl_minutes` (default 15, bounded 5 to 60) is purged on the next request or by `php artisan vasey:customer-data-exports-prune`, which is not scheduled automatically (U-02 host).

## Staff review of deletion requests

**Customers → Customer data requests** (`/admin/customer-data-requests`) lists requests. Deletion requests in `received` offer **Approve deletion** and **Reject**. Both go through `CustomerDataRights`, which re-reads the `administer-catalog` gate under a row lock, applies the admin panel MFA rule and also requires an enrolled authenticator even where the panel does not, takes the request version captured when the confirmation opened, and records an audit event.

**Approval refuses while any retention value is unset.** Once configured, approval:

- expires every open export of the account and purges its encrypted file in the same transaction (the export holds the pre-erasure profile and the customer can no longer download it); the request moves to `expired` with an `export_expired` event whose reason is `deletion_approved`, and the approval records `pending_exports: purged` (independent CX review F1). Export preparation and approval both lock the customer user row first, so an export that commits while approval waits is purged by that approval, and an export requested after approval is refused because access is withdrawn;
- withdraws customer access (`customer_accounts.active` false, access version incremented) and ends database sessions;
- erases the listening library (`customer_saved_tracks`);
- replaces the sign-in profile: name `Erased customer`, email `erased+<account id>@erased.invalid`, a random password, no verification;
- **keeps** orders, order lines, finalizations, license grants, contracts, verified payments, purchase claims, delivery records, consent and suppression ledgers, inquiries, identity challenges, order-ready intents (`transactional_notices`, whose encrypted capture holds the recipient email) and their mail deliveries, data request records and export rows (contents purged) and audit events exactly as they are, and records each class with its retention class in the request's `deletion_completed` event together with the configured retention values and policy reference.

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

These values are recorded with each approval; no code yet purges retained records when a period ends. Follow-up (independent CX review F4): schedule `vasey:customer-data-exports-prune` once a production host exists (U-02); until then the export lifetime is a download window, not a storage limit, except that deletion approval purges open exports. A purge of immutable records needs its own reviewed migration and Sean's schedule.

## Records

Concurrency (independent re-review F7): every command takes the customer's `users` row first (staff approval takes the staff row, then the same customer rows), then the request and export rows by primary key. Each command is one READ COMMITTED transaction, retried up to three times on a deadlock or lock-wait timeout (the prune command included). No command locks a range of a shared index, so one customer's request never waits on another customer's. A request's next event sequence comes from its own `last_event_sequence`, never from a read over the event index. Contention that outlives the retries answers `503` with `reason: try_again` and `Retry-After: 5`. In the staff list, the same lasting contention shows a "Request not changed" notification asking staff to try again (the transaction rolled back, so nothing changed); any other database error is not hidden.

Migration `2026_10_24_268200_customer_data_requests` (additive, forward-only, `down()` refuses): `customer_data_requests` (one open request of each kind per account; one transition from open to terminal), `customer_data_request_events` (append-only; each event's sequence is the parent's `last_event_sequence` plus one, and the counter may only advance by one to an event that exists, so sequences are gap-free), `customer_data_exports` (ciphertext can only be purged). Database triggers enforce each rule on SQLite and MySQL.
