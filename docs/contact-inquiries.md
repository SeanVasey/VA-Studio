# Durable plain-text contact inquiries

T15a adds a private database inbox and truthful saved receipt. It sends no email to the sender, no marketing enrollment, attachment or external request. The only outbound message is the optional operator alert below, which is off by default. The existing published email link remains a contact fallback. Buyer/order support and retention deletion are separate work.

## Setup remains disabled

`config/inquiries.php` defaults to disabled. Before enabling intake, choose and record the owner's contact purpose, public privacy notice, private retention policy reference and responsible operator. No production policy text, retention period or deletion schedule is supplied by this increment.

- `CONTACT_INQUIRIES_ENABLED=true` is only one of the required conditions.
- `CONTACT_INQUIRIES_PRIVACY_NOTICE` must be approved nonempty UTF-8 plain text, at most 3,000 characters. Render it as escaped text beside the form.
- `CONTACT_INQUIRIES_RETENTION_REFERENCE` must identify the chosen private policy, at most 120 plain-text characters. It is not a public page prop.
- `CONTACT_INQUIRIES_OPERATOR_ID` must identify an existing verified staff member satisfying the panel's required MFA enrollment rule.
- The currently published verified site release must contain a contact page. Draft/private previews cannot enable public submission. Withdrawal of any required condition stops new submissions and receipt replay with the same generic unavailable response.

The public contact controller calls `InquiryPolicy::publicSetup($verifiedCurrentContent)` on its verified snapshot. Expose only `contactInquiryEnabled`, `contactInquiryPrivacyNotice` and the opaque `contactInquiryNoticeToken`; internal operator, retention reference and hashes stay private. Private preview pages keep the form disabled and receive no token. The frontend consumes the same `/contact/inquiries` endpoint and existing CSRF cookie/token.

The token is a purpose-separated HMAC using the application key. It binds the displayed notice hash, selected release/hash/publication revision, private retention-reference hash and configured operator identity. Issuance fails closed if the verified content does not match the selected release or the key is unavailable. This binds the displayed context; it is not a consent record, delivery receipt or new policy. During admission, current publication and persisted operator authority are locked in that order. A caller's old repeatable-read content snapshot cannot issue a token for different current content.

## Submission and retry contract

`POST /contact/inquiries` accepts only JSON with exactly these string fields:

| Field | Bound |
| --- | --- |
| `name` | Required, 120 Unicode characters; no control characters |
| `email` | Required valid email, 254 characters; no control characters |
| `subject` | Required, 160 Unicode characters; no control characters |
| `message` | Required, 8,000 Unicode characters; ordinary line breaks allowed |
| `website` | Honeypot; must be exactly empty |
| `requestKey` | Fresh canonical lowercase UUIDv4 |
| `noticeToken` | Exact 64 lowercase hexadecimal characters issued with the displayed notice |

The complete raw body is capped at 16,384 bytes, including JSON escaping/envelope. Duplicate keys, extra/nested fields, query parameters, method overrides, range/encoding tricks and other media types fail before admission. Same-origin checks supplement the framework's CSRF/fetch-metadata protection. IP budgets are five requests per minute and twenty per hour; cache keys hash the IP and no IP is stored in the inquiry.

Accepted values are retained exactly, without trimming, case folding or Unicode normalization. JSON property order/escaping/whitespace is immaterial. A request key is reserved globally so a lost response followed by session/authentication rotation cannot create a second inquiry. Receipt replay still requires the exact owning session context and body, including its original notice token. A foreign owner, changed payload or replacement token gets the same generic conflict without a receipt, saved state or private data. Login/logout invalidates the separate inquiry-owner secret, including a login/logout round trip between submissions.

A first submission with a stale or forged token is rejected with 422 and the fixed `errors.noticeToken` message: “Refresh contact to review the current privacy notice before sending.” Nothing is retained. After refreshing and reviewing the current notice, a new first attempt may use the newly issued token. An exact already-saved replay with its original token returns the original receipt after a valid policy, operator or selected-release change, preserving the original encrypted notice, policy and association. Existing setup/authority withdrawal still denies replay before this lookup. An uncertain retry must retain the original token, UUID and body; it must not silently adopt new page props.

The canonical replay fingerprint includes the token but excludes `requestKey`. The encrypted payload remains exactly the five input fields `name`, `email`, `subject`, `message` and `website`. Raw notice tokens are not stored in payloads, audit contexts, queue jobs or error diagnostics. Historical fingerprints made before this mandatory seven-field contract cannot replay through it; this unmerged, disabled feature does not add a legacy bypass or rewrite retained rows.

| Status | Response |
| --- | --- |
| 201 new / 200 exact replay | `{state: "saved", receipt: "<opaque UUIDv4>"}` |
| 422 | `INQUIRY_VALIDATION_FAILED`, fixed public field/form errors |
| 409 | `INQUIRY_REQUEST_CONFLICT`; no receipt or saved state |
| 404 | `INQUIRY_UNAVAILABLE`; disabled, unpublished or unready setup |
| 403 / 405 / 413 / 415 / 419 / 429 | Fixed denial, method, size, media, session or rate-limit error |
| 503 | Generic inability to confirm persistence; retry the same attempt |

Errors have fixed public messages and never echo submitted values, SQL bindings or debug traces. All endpoint responses are private/no-store/noindex/nosniff/no-referrer and vary by Cookie. There is no public lookup endpoint. Preserve the original UUID and body through uncertain results; never automatically create another key or resend after a conflict.

## Private operator handling

`/admin/customer-inquiries` lists a bounded new inbox, with a state filter and exact receipt detail. Current persisted staff/MFA authority is checked for pages, reactive refresh and direct actions. Opening the inbox/detail is audited. Explicit **Mark read** and **Archive** actions use locked current records and expected versions; stale confirmations cannot overwrite a newer action. Archiving keeps original input and evidence and sends no reply. No create/edit/delete/bulk action is exposed.

Payload and the original privacy notice are encrypted in the private database. Every inquiry records its notice hash, retention reference, operator and exact active site release/hash. Receipt/input/evidence columns are immutable through model and SQL guards; state only advances `new → read → archived` or `new → archived`. Audit insertion and state changes commit together. No-op replay does not duplicate state/audit effects. An inbox/detail audit failure prevents returning private content.

Nonempty migration rollback is refused. This is protection against accidental data loss during code rollback, not an invented permanent retention policy. A later approved deletion/export workflow must implement the chosen policy explicitly. Disabling intake leaves authorized staff access to retained inquiries intact.

Before schema rollback, disable intake and drain in-flight submissions and operator mutations. MySQL DDL is not atomic with the empty-table check; the populated-table refusal does not lock out concurrent writers. Retain populated tables during application rollback.

## Operator alert email (FP-013, default-off)

Status: **built default-off; nothing is sent in any environment.** No sender domain or SMTP provider is chosen (SQ-08), the staging deploy gate refuses `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED=true` (`scripts/ops/capability-boundary.php`) and production `validate-runtime.php` holds it false (pre-launch hold, 2026-10-18). Lifting either is a reviewed change, not part of this work.

Every saved inquiry already retains one minimal alert intent (`inquiry_notification_intents`, one per inquiry). `MailInquiryAlertTransport` is the first `InquiryAlertTransport`: it turns an intent into at most one plain-text email telling the operator that an inquiry is waiting.

**When it is bound.** `AppServiceProvider` calls `MailInquiryAlertTransport::bindWhenConfigured()`, which binds the transport only when `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED` is exactly `true`, `CONTACT_INQUIRIES_OPERATOR_ID` is set and the environment is admitted. Otherwise nothing is bound, `InquiryNotificationWork::enabled()` is false, no job is dispatched and intents stay `pending`. No new environment key was added.

**Environment gate (defence in depth).** `MailInquiryAlertTransport::admitted()` admits `local`, `testing` and `staging` (through `TestEnvironment::admitsTestCommerce()`). In `production` it admits only when the code literal `inquiries.operator_alert_mail_production_admitted` in `config/inquiries.php` is exactly `true`; it ships `false` and is not an environment read. Every other environment name refuses. The gate is checked at binding and again inside every send, so production binds nothing and sends nothing even with every flag and mail setting present. The literal is set to `true` only in the same reviewed change that lifts the production pre-launch hold, and the production runtime validator refuses any other value (`holds.operator_alert_mail_production_admitted`).

**When it sends.** In addition, the order-ready mail configuration must be admitted by `TransactionalMailPolicy` ([transactional mail](transactional-mail.md)): `VASEY_TRANSACTIONAL_MAIL_ENABLED=true`, an SMTP mailer, the sender and the preflight facts. The alert uses that mailer and sender. In `local` and `testing` only the framework mail fake is accepted. Enabling operator alerts therefore requires enabling the transactional mail configuration as well; whether alerts should have their own switch for the mail side is Sean's call.

**Recipient.** The verified email address of the staff account that owns the inquiry (the operator recorded on the intent, whose current staff and MFA authority the outbox re-proves immediately before the handoff). There is no separate alert address setting and no fallback: an operator account with no verified, well-formed address gets no alert, and neither `MAIL_FROM_ADDRESS` nor any other address is used. A global recipient override would redirect the alert, so the transport refuses to send while `mail.to` is configured or the alert's mailer carries an `alwaysTo` address.

**Timeout.** The alert is sent through a mailer built for it alone from the configured mailer's settings, with a per-operation timeout of at most 10 seconds (`TRANSPORT_TIMEOUT_SECONDS`, or the configured timeout if shorter), below the job's 30-second timeout and the 120-second claim lease. The shared mailer used by order-ready mail is never resolved or changed by the alert, so its connection and timeout are unaffected. (Symfony applies a stream timeout only when a connection opens and keeps connections open across sends, which is why the timeout is not changed on the shared mailer.)

**Content.** Plain text only: the subject "A new inquiry is saved in your private inbox", the opaque inquiry reference (also in the `X-Vasey-Inquiry-Alert` header) and an instruction to sign in to the admin. It contains no inquiry subject or message, no customer name or email address, no link and no token.

**States, retry and stuck work.** The outbox is the delivery record; its states only move forward and the database guards refuse anything else (`pending → processing → submitted`, `retry`, `unknown` or `blocked`).

- A refusal before any transport call (configuration not admitted, flag or environment gate withdrawn, no usable operator address, a global recipient override) is reported as definitely not submitted: the intent becomes `retry` and is tried again after 30 and then 60 seconds, three attempts in all, then `blocked` (`retry_exhausted`).
- A transport error after the attempt began is `unknown` (`handoff_uncertain`) and is never retried automatically, like an `uncertain` order-ready delivery.
- A claim whose worker stopped stays `processing` until its 120-second lease expires; the next scan settles it as `unknown` (`lease_expired`). It is never resent.
- One intent is claimed at most once at a time, so duplicated jobs, repeated scans and an exact replay of the submission never send a second alert.

`php artisan vasey:process-inquiry-alerts --limit=25` recovers lost wake-ups and due retries. It is **not scheduled**: until it is, a `retry` waits for an operator to run it. `php artisan vasey:inquiry-alerts-stuck --minutes=15` is read-only and lists claims older than the threshold and every `unknown` intent (intent id, inquiry reference, state, outcome, attempts, last change; newest first, at most 100 or `--max`, with `more=N`). For those, check the mail provider's logs for the inquiry reference before acting.

**No backlog burst on first enable.** Every inquiry saved while alerts are off keeps a `pending` intent. An intent older than `inquiries.operator_alert_max_age_minutes` (a code value, 1,440 minutes; valid from 1 to 10,080) is never claimed: the scan leaves it out, so stale rows cannot hold the bounded batch, and a late job or a direct call is refused the same way. A `retry` that outlives the window stops retrying. An expired claim is still settled as `unknown` at any age, without a send. If the value is not a whole number in range, nothing is sent. Stale intents stay `pending` (or `retry`) for good: the database guards allow no "expired" outcome and a merged migration is not edited, so they are skipped, not rewritten.

Drain step when enabling alerts on a host:

1. Before setting the flag, run `php artisan vasey:inquiry-alerts-stuck`. The first line reports `stale=N`: the unsent intents already older than the window. None of them will ever send.
2. Read those inquiries in the admin inbox (`/admin/customer-inquiries`); the inbox, not the alert, is the record.
3. Enable the flag. Intents younger than the window (at most the last 24 hours) are still sent, at most `--limit` per run of `vasey:process-inquiry-alerts` (25 by default, 100 at most), so the first run after enabling can send up to that many alerts. To send none of them, wait until `vasey:inquiry-alerts-stuck` counts every pending intent as stale before enabling.

Logs carry the inquiry reference, an exception class and a refusal reason only; audit events carry states and outcomes. No address or message content is logged, and a reference that is not a well-formed receipt is not logged either.

**Not built or not evidenced.** Real delivery, DNS (SPF/DKIM/DMARC), TLS and provider behaviour; a schedule for the retry scan; an alert for replies in an existing conversation; any message to the customer.

### Open items (security review of PR #263; behaviour unchanged)

Each of these is settled before the production hold is lifted.

- **Alerts depend on the customer-mail switch.** Sending needs `VASEY_TRANSACTIONAL_MAIL_ENABLED=true`. The coupling is kept for now (delegated decision, 2026-11-10). Enabling alerts on a host therefore also arms order-ready mail wherever a producer of order-ready intents exists on that host. A dedicated sender and switch for operator alerts is a pre-activation item.
- **Recipient read after the terminal proof.** The outbox proves the operator row immediately before the handoff, and the transport then reads that account's address again. A change to the row between the two is not detected: the alert goes to the address stored at send time.
- **"Verified" is not re-verified on change.** The operator can change the account email in the Filament profile without a new verification, and `email_verified_at` stays set. The alert would follow the new address.
- **An SMTP outage is not retried.** A transport error after the attempt began is `unknown` and never resent, so alerts raised during an outage are lost; the inquiry itself is saved and listed by `vasey:inquiry-alerts-stuck`.
- **The age window and the retry scan.** The 24-hour window is a code value chosen in this change, and `vasey:process-inquiry-alerts` is not scheduled.
