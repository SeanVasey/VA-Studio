# Durable plain-text contact inquiries

T15a adds a private database inbox and truthful saved receipt. It sends no email, marketing enrollment, attachment or external request. The existing published email link remains a contact fallback. Buyer/order support and retention deletion are separate work.

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

## Operator alert intent candidate — October 1, 2026

The [T15b engineering child](verification/inquiry-notification-intents.md) retains one minimal operator-alert intent with each newly saved inquiry. Queue wakeups are optional; a bounded scanner recovers missed dispatch. Original customer data stays encrypted in the inquiry and never enters the queue or transport projection. Existing intake remains disabled by default, and the separate notification flag is also false with no transport binding supplied.

`submitted` means only transport acceptance. Ambiguous or expired handoffs become `unknown` and are held for later operator resolution, with no automatic resend. This candidate does not send mail, choose a processor or change the truthful saved-inbox receipt. Final database/concurrency/source acceptance is pending.
