# Lane CX work package: customer communications and data rights mechanisms

Status: **work-package statement, committed before implementation.** Branch `harness/customer-comms-cx-20261024`, cut from `origin/main` `8f166c5a`. Not pushed.

Scope rows (`docs/remaining-parity-coverage.csv`): FP-005 *License comparison and policy pages*, FP-075 *Receipt and fulfillment notifications*, FP-082 *Customer export deletion retention*, with FP-076, FP-081 and FP-091 recorded only where this lane touches them. Lane CX in `docs/handoff/2026-10-24/COMPLETION-PLAN.md`; legal index `docs/legal/README.md`.

Delivery order if the lane cannot finish in one pass: 1 (legal pages) → 3 (data rights) → 2 (mail adapter) → 4 (ESP suppression, documentation only unless trivially small).

## Boundary and invariants

- **Default-off.** Nothing is visible or active until staff publish approved content (legal pages) or an operator sets explicit configuration (data rights, transactional mail). Shipped configuration, `.env.example` and the ops templates keep every new key unset or false.
- **No live email.** No sender or provider is chosen (U-07; completion plan owner input #9). The mail adapter refuses unless an explicit mailer, sender and an operator preflight receipt from the existing `scripts/ops/email-operations-preflight.py` are configured and consistent. Tests use `Mail::fake()` only; in `local`/`testing` the adapter refuses to send unless the mail facade is faked.
- **Unapproved drafts are not published.** `docs/legal/PRIVACY.md`, `TERMS.md` and `LICENSE-TIERS.md` stay drafts. The mechanism lets staff paste approved text later; saving legal content requires an approval reference, version and effective date.
- **Immutable originals.** Orders, order snapshots, contracts, grants, verified payments, payment evidence, inquiries, consent and suppression ledgers and audit events are never deleted or rewritten. Retained site releases keep their hashes; no existing release is migrated.
- **Consent stays unknown** unless the customer chose it; nothing here writes consent.
- **Server-side authorization.** Staff actions use the existing `administer-catalog` gate plus `AdminMultiFactor`, re-read on every write, with an audit event. Customer actions use the existing signed-in customer principal (`CommerceRequestIdentity`) and read only the requester's own records.
- **No secrets, no PII in logs.** Logs carry identifiers and exception classes only.
- **Brand:** VASEY.AUDIO only; contact `support@vasey.audio`. No street address in any committed file.

## 1. FP-005 legal page publication (site content schema v5)

Design:
- `SiteContentSchema` accepts schema version 5 = version 4 plus a `legal` object with `privacy`, `terms` and `licenses` slots, each `null` or a document `{title, description, version, effective_date, approval_reference, sections[{heading, paragraphs[]}]}`. All text is bounded plain text (the existing rule rejecting `<`, `>` and control characters); `version` is a short token, `effective_date` a real `YYYY-MM-DD` date. A v5 release must carry at least one legal document; drafts without legal text keep the existing v2/v3/v4 choice.
- Public routes `/legal/privacy`, `/legal/terms`, `/legal/licenses` read only the active published release (same verified pointer as editorial pages). A disabled slot, an older-schema release or no publication answers 404. Drafts are visible only in the staff preview (`/admin/site-releases/{release}/preview/legal/{document}`), private and no-store.
- The public projection carries title, description, version, effective date and sections. The approval reference is staff evidence and is never projected publicly. Text renders through React text nodes (escaped), never as HTML.
- Navigation may target an enabled legal page; the footer lists the published legal pages.
- Migration (`2026_10_24_268100_site_release_images_schema_v5.php`, additive and forward-only, **needs independent review**): replaces the site-release image insert guard so image rows may reference v3, v4 or v5 releases (the v4 guard admits only 3 and 4). It follows the reviewed pattern of `2026_10_01_000032`: successor created before the predecessor is dropped, exact-definition preflight, temporary-shadow refusal, and a down migration that refuses while a v5 release exists.
- `docs/legal/PRIVACY.md` section 8 corrected (deferred Codex P2 from PR #169): only database records are encrypted; contract PDFs and support-attachment originals are stored privately but not encrypted at rest (`ContractFiles` writes `original.pdf`, `AttachmentFiles` writes `original.bin`). File encryption at rest becomes a reviewer-appendix prerequisite if the published text is to promise it.

Files: `app/Domain/SiteBuilder/{SiteContentSchema,SiteContent,EditorialContent,SiteImageReferences}.php`, `app/Filament/Resources/SiteReleaseResource.php`, `app/Http/Controllers/{LegalPageController,SiteReleasePreviewController}.php`, `routes/legal-pages.php` (one `require` line in `routes/web.php`), one preview-route pattern in `app/Providers/Filament/AdminPanelProvider.php`, `app/Support/Diagnostics/InstallationReport.php` (image check for v5), `resources/js/Pages/Editorial.tsx`, `resources/js/lib/site-content.ts`, `resources/js/components/SiteChrome.tsx`, docs.

Acceptance:
- Unpublished drafts, disabled slots, older releases and no publication: 404 on every legal route; preview needs current staff authorization and MFA.
- Markup or control characters in any legal field are refused at save; script-like text renders escaped; the approval reference never appears in public HTML or props.
- Missing version, invalid or impossible effective date, missing approval reference, empty sections or oversize text are refused.
- v1–v4 releases keep their hashes, stay readable and publishable; a v5 release with images saves, publishes and rolls back; the image guard refuses image rows for v1/v2 releases as before.

## 3. FP-082 customer data export and deletion (retention values unset)

Design:
- `config/customer-data-rights.php`: `enabled` (false), export link lifetime (bounded, default 15 minutes), export size cap, and retention values plus a retention policy reference that are **unset** (U-13; FP-076). Every endpoint answers 404 unless enabled and the existing customer-access policy admits the request.
- Customer endpoints under `/account/data-requests` (existing `CustomerPrivacy` middleware: JSON POST, same-origin, no query, no-store), each with its own named rate limit:
  - request an export: builds a bounded JSON document of the requester's own records (account, orders, license grants, consent ledger, order-linked inquiries), encrypts it at rest, and returns a one-time download token (stored only as a hash) valid for the configured minutes;
  - download the export: requires the same signed-in customer and the token; single use; the ciphertext is purged on download; expired exports refuse and are purged;
  - request account deletion: records a deletion request for staff review (one open request per account);
  - list own requests (state only).
- Staff workflow (Filament, read-mostly): approve or reject a deletion request through a domain service that re-reads `administer-catalog`, requires `AdminMultiFactor`, takes an expected version, records an audit event and **refuses while any retention value or the retention policy reference is unset**. Approval withdraws customer access and erases or pseudonymizes only mutable profile data that no retained record depends on; it retains orders, contracts, grants, payment evidence, inquiries, consent/suppression ledgers and audit, each with a recorded retention reason and the configured retention snapshot.
- Migration (`2026_10_24_268200_customer_data_requests.php`, additive, forward-only, MySQL/SQLite parity, **needs independent review**): request, event and export tables with database guards (append-only events, forward-only request transitions, no deletes, export purge only).

Acceptance:
- Disabled or unadmitted: 404 with no row written. Rate limits answer 429.
- Export contains only the requester's records; another customer's orders, grants, consent and inquiries never appear; tokens are single use, expire, are bound to the requesting account and are never stored in plaintext.
- Deletion approval refuses without MFA, without the gate, with a stale version, and while retention configuration is unset; after approval every immutable record is byte-identical and consent stays as it was (unknown stays unknown).
- Native MySQL: concurrent duplicate deletion requests and concurrent approval/rejection serialize to one outcome.

## 2. FP-075 transactional mail adapter (default-off)

Design:
- `config/transactional-mail.php`: `enabled` (false), `mailer` (null), `sender` (null), and the operator preflight binding (facts path, receipt path and their SHA-256 values; null). `TransactionalMailPolicy` refuses unless all are set, the mailer is an SMTP mailer in `config/mail.php`, the receipt is the existing preflight's `DECLARED_FACTS_VALID` operator report with every check passing, both files match their pinned hashes, and the declared planned sender, host, port and security match the configured mailer. In `local`/`testing` it also refuses unless `Mail` is faked.
- A delivery ledger (`2026_10_24_268300_transactional_mail_deliveries.php`, additive, **needs independent review**): one row per order-ready intent (unique notice), forward-only states `queued → submitting → accepted | uncertain | failed`, no deletes. A queued job (one try, after commit, carrying only the delivery ID) sends one text message with the order reference and an instruction to sign in; no download link, token or secret is in the message. A second queue call or job run for the same intent never sends again; an uncertain outcome is never retried automatically.
- An operator command queues pending order-ready intents in bounded batches and refuses (no rows) when unconfigured.

Acceptance: nothing sent or recorded without complete configuration; never twice per intent (replayed queue call, duplicated job, concurrent runs); withdrawn customer access refuses before sending; logs contain no email address or message content.

## 4. FP-081/FP-091 ESP suppression adapter

Not built unless trivially small: the suppression foundation (`config/customer-suppression.php`, family 254) is default-off and unbound, and the ESP choice is U-13. Recorded in this packet and the parity notes.

## Tests and evidence plan

- SQLite via direct PHPUnit (command in the lane brief) for new suites under `tests/Feature/CustomerComms/` plus the touched site-content, editorial, preview, inquiry, consent, customer and notification suites.
- Native MySQL 8.4.11 on a private server (own datadir under the scratchpad, socket under `/tmp`, free port) for the migration guard definitions and the deletion-request races; native-only tests go into the SQLite skip census (append-only, pinned first-150 hash unchanged) and the regenerated MySQL selection.
- Frontend: `npx vitest run`, `npx tsc --noEmit` on Node 24.
- Safeguards: `scripts/ci/test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py`; `composer validate --strict`.
- Mutation checks on the publication, export-ownership, token, retention and send-once guards, recorded below.

## Not touched

`bootstrap/app.php`, `config/production-memberships.php`, `app/Http/Membership/*` and the membership domain (C4e); `app/Filament/Widgets` and reporting resources (REP); `app/Domain/Migration/*` (MIG); public discovery and sitemap (SEO). `routes/web.php` receives one isolated `require` line.
