# Lane CX work package: customer communications and data rights mechanisms

Status: **implemented on branch `harness/customer-comms-cx-20261024`; independent review pending; not pushed.** Cut from `origin/main` `8f166c5a`. The work-package statement below was committed first (`d37a718f`); implementation and evidence follow it. Tested source: see "Evidence".

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

## Final results (2026-10-29)

The 2026-10-26 handoff to another harness was cancelled and lane CX resumed. The earlier "Status at handoff" section is **superseded** by this one (its text remains in git history at `21b41c3e`).

Status line: implemented and verified on branch `harness/customer-comms-cx-20261024`; **independent review of the three migrations and the privacy/identity code is pending**; not pushed by the lane.

### Commits

| Commit | Content |
| --- | --- |
| `d37a718f` | Work-package statement |
| `9f58bfbf` | FP-005 legal pages (site content schema v5), migration `2026_10_24_268100_site_release_images_schema_v5`, `PRIVACY.md` section 8 encryption wording corrected (deferred Codex P2 from PR #169: only database records are encrypted; file encryption at rest is a reviewer-appendix prerequisite) |
| `fa785f48` | FP-082 customer data export and deletion, migration `2026_10_24_268200_customer_data_requests` (forward-only) |
| `fa4352c2` | FP-075 order-ready email adapter, migration `2026_10_24_268300_transactional_mail_deliveries` (forward-only) |
| `70e909f0` | Staging capability boundary (thirteen flags), env templates, `forge-deploy.sh` scrub, census and selection, CHANGELOG, docs |
| `21b41c3e` | Handoff checkpoint (superseded) |
| `dce310cd` | Fixes found by the related-suite run (below) |
| `0bd4519c` | Merge of `origin/main` `a8f17e5c` (DEF-OPS #173, C4e #174, handoff docs) |

**Tested source:** `0bd4519c` (the merged head) for every result below, except the mutations, which ran on `70e909f0` (M1–M14) and `dce310cd` (M15–M16). Later commits on the branch change documentation only.

### Fixes after the first verification round (`dce310cd`)

1. **SQLite notification roundtrips** (`QuotePricingMigrationTest`, `OrderPreparationMigrationTest`, `HostedCheckoutMigrationTest` errored: `no such table: main.transactional_notices`). Cause: the delivery insert guard proves its notice in `transactional_notices`; those disposable fixtures roll the notification family back, and a later SQLite `ALTER TABLE` rebuild validates every trigger in the schema. Fix by intent, guard unchanged: every roundtrip fixture that rolls the notification family back (the nine that already dispose of the A5 tables: HostedCheckout, OrderPreparation, Promotion, QuotePricing, SharedInventory, TestContractIssuance, TestFulfillmentActivation, TestOrderFinalization, TestPaymentEvidence) now drops the verified-empty `transactional_mail_deliveries` table first (`TransactionalMailFixtures::dropEmptyDeliveries()`, which asserts it is empty) and reinstalls it with its guards after the family returns (`restoreDeliveries()`). The operational migration stays forward-only.
2. **Native trigger-order assertion** (`CustomerDataRequestMigrationTest`): MySQL's `information_schema` collation orders `_` differently from SQLite. The test now sorts the installed names in PHP (`SORT_STRING`); it still requires the exact nine guards.

Mutations on the fixes: **M15** (remove the notice proof from the delivery insert guard) is killed by `TransactionalMailTest` (database guards); **M16** (keep the mail table during a roundtrip) is killed by `QuotePricingMigrationTest` (reproduces the original error).

### Results on `0bd4519c` (summaries in `evidence/summary.md`)

- **Native MySQL 8.4.11**, private server, fresh datadir: **13 of 13** CX native tests pass: `LegalSiteSchemaMigrationTest` 5, `CustomerDataRequestMigrationTest` 2, `CustomerDataRightsConcurrencyTest` 4 (duplicate deletion request, approval racing a rejection, export racing an access withdrawal, double download), and the `database_guards` tests of `CustomerDataRightsTest` and `TransactionalMailTest`.
- **SQLite**: 55 files, 1,025 tests, 15,839 assertions, 82 skips (all census methods), no failures or errors. These cover `tests/Feature/CustomerComms` (96 tests), every `Site*` suite, the customer inquiry suites, the nine notification-family roundtrips, the `TransactionalNotification*` suites, the customer account, consent, listening and claim migrations, installation report, sitemap, storefront metadata, Foundation, and the staging, profile and production-template unit tests. Files first reached in this round (after the stop) and passing on `dce310cd`: `TransactionalNotificationTest` 52, `CustomerPurchaseClaimTest`, `CustomerPurchaseClaimConcurrencyTest`, and every `CustomerSuppression*` suite.
- **Frontend:** `npx tsc --noEmit` exits 0. `npx vitest run`: 70 files, 1,177 tests.
- **Safeguards:** `test-phpunit-shards.py` 52, `test-ci-scope.py` 27, `test-workflow-cadence.py` 14, `test-focused-tests.py` 53, `test-database-receipts.py` 61, all OK. Ops tests all OK: `test_staging_capability_profile.py` 18, `test_staging_code_drift.py` 10, `test_production_kit.py` 50, `test_production_runtime_validator.py` 28. `composer validate --strict` reports valid.
- **Census and selection:**
  - The SQLite skip census has 306 entries: main's 302 first, then the four CX race methods. The pinned first-150 prefix is unchanged.
  - The native selection is proved as 2,636 of 9,874 cases in 254 files.
  - The count comments in `.github/workflows/final-verification.yml` and `.gitlab-ci.yml` are updated, and the generated `phpunit-ci-mysql-*` files are deleted.
- **Mutations M1–M16 are all killed.**

### Untested conditions

- No real SMTP or provider, sender DNS, staging or production host, or live Stripe was used.
- No Foundation CI run; the browser specs did not run (the preinstalled Playwright browsers do not match the pinned version).
- Native MySQL covered the 13 CX native tests, not the whole native selection.
- Sean has approved no legal text and no retention schedule (U-13). Deletion approval was exercised only with a synthetic schedule.
- Sitemap entries for the legal pages (lane SEO), receipts for production orders (lane PCC) and the ESP suppression adapter (FP-081/FP-091, U-13) are not built.
- Pint was not applied to the nine existing roundtrip test files; they already fail Pint on main, and the repository has no enforced linter.

### Next

1. Independent review of the three migrations (`2026_10_24_268100`, `268200`, `268300`) and of the privacy/identity code (data rights, mail adapter, legal page projection).
2. Then cheap preflight, complete inventory and an expected-SHA merge, per AGENTS.md.

## Independent review and resolution (2026-10-29)

The independent review of `733b02f7` returned **APPROVE WITH CONDITIONS**, with no P0 or P1. The record is imported byte-original as [`independent-cx-review-733b02f7-DECISION.md`](independent-cx-review-733b02f7-DECISION.md). The re-review of `16687e9f` (APPROVE WITH CONDITIONS; F1, F2, F3 and F6 verified; new P2 F7) is imported as [`independent-cx-rereview-16687e9f-DECISION.md`](independent-cx-rereview-16687e9f-DECISION.md). The reviewer's mutants R1–R17 and the populated native migration and rollback check are described there. Every finding was fixed or recorded before the PR opened.

| Finding | Severity | Resolution | Commit | Evidence |
| --- | --- | --- | --- | --- |
| F1: deletion approval left a prepared export (with the pre-erasure profile) decryptable, and the request stayed `ready` | P2, condition C1 | Inside the approval transaction, after the customer user → account → request locks, `purgeOpenExports()` locks every open export of the account. Each one moves `ready → expired` and its ciphertext is purged, with an `export_expired` event (reason `deletion_approved`). The approval's `actions` records `pending_exports: purged`. The existing triggers already admit exactly this transition: export `ready → expired` without staff or hash, and a purge without consumption. No trigger was widened. | `105f37a2` | `CustomerDataRightsTest::test_approval_purges_a_prepared_export_in_the_same_transaction_and_records_it` turns the reviewer's probe into a test. A refused (stale) approval leaves the export intact; an already-downloaded export is unchanged; a replay is refused with no new events. Native race `test_an_export_committed_while_an_approval_waits_is_purged_by_that_approval`. Mutants M17 (no purge) and M18 (request expired, ciphertext kept) are killed. |
| F2: `PRIVACY.md` claimed encrypted backups | P2 (docs) | Sections 5, 7 and 8 no longer say backups are encrypted. The appendix B row states that age encryption in `ops/staging/backup.sh` is opt-in and off by default (`VASEY_BACKUP_REQUIRE_ENCRYPTION=0`) and that the production target is open. It lists enforced backup encryption as a second prerequisite. `backup.sh` is unchanged. | `105f37a2` | Text diff; evidence map line corrected. |
| F3: staff-account refusal untested (R11 survived) | P3 | New test: an account promoted to staff after its deletion request is refused with `staff_account`, and the user, account and request rows are unchanged. | `105f37a2` | `test_approval_never_erases_an_account_that_became_staff`; mutant M19 (R11) killed. |
| F4: expired export ciphertext stays until the next export or a manual prune | P3 | Follow-up recorded: schedule `vasey:customer-data-exports-prune` once a production host exists (U-02). Deletion approval now purges open exports (F1). | `105f37a2` (docs) | `docs/customer-data-rights.md`. |
| F5: a delivery stuck in `submitting` after a worker crash is not visible | P3 | Documented: the delivery is never resent, is not yet reported, and should be treated like `uncertain`. The operator query is given. A report in an operator listing is a follow-up, because it is not trivial to add with tests in this batch. | `105f37a2` (docs) | `docs/transactional-mail.md`. |
| F6: retention record omitted retained classes holding personal data | P3 | `RETAINED` now lists `transactional_notices` and `transactional_mail_deliveries` (correspondence) and `customer_data_exports` (security; reason `ledger_retained_contents_purged`), each with a count. | `105f37a2` | Asserted in the F1 test; mutant M20 killed. |
| F7 (re-review of `16687e9f`): range locking reads on shared indexes made unrelated customers wait (event `MAX() FOR UPDATE`) and deadlock (open-slot `FOR UPDATE`) | P2, condition C2 | (a) Each request keeps `last_event_sequence` (migration 268200 amended; it is not on main). The event insert guard requires `sequence = counter + 1`, and the counter may advance only by one to an event that exists, so no read over the event index is needed. (b) The open-slot checks are plain reads, serialized by the customer `users` row lock with the unique open-slot index as backstop, mapped to a clean 409. Open exports are discovered, then locked by primary key. (c) Every data-rights transaction runs at READ COMMITTED, set per attempt, with three attempts on a deadlock or lock-wait timeout. The prune command uses the same helper. Contention that outlives the retries answers 503 `try_again` with `Retry-After` instead of the generic customer error. | `2872c10e` | New native race `test_unrelated_customers_request_deletion_together_while_an_approval_is_in_flight_without_waits_or_deadlocks`: no wait on the other customers' connections, zero deadlocks, exact sequences and counters. SQLite tests for the counter guards, the unique backstop, retry and exhaustion, and the contention response. Mutants M22–M27 and M29 killed; M28 is equivalent. |

**Is there a real race between export preparation and approval?** Yes, but it is serialized. Export preparation (`requestExport`) is a single transaction that first takes the customer user and account rows (`CustomerAccess::lock`). Approval takes the staff row, then the same customer user and account rows, then the request and export rows. There is no intermediate export state visible to another transaction, so there is no "mid-preparation" export.

- If the export commits first, the approval waits on the user row and then locks and purges it with locking reads. A locking read sees the committed row even under REPEATABLE READ. The new native race test proves this.
- If the approval commits first, a waiting export is denied because access was withdrawn. The existing native race test `test_an_export_waiting_behind_an_access_withdrawal_is_denied_without_rows` covers this.

**The native race found a real defect in the first F1 fix.** On `3e0bd1aa` the new race test failed on MySQL 8.4.11: the worker's approval raised a `QueryException`. Under REPEATABLE READ, `event()` read `MAX(sequence)` without a lock. Its snapshot was taken by the approval's first unlocked read, before it waited on the customer user row, so it missed the event that the export transaction committed meanwhile and collided on the unique `(request_id, sequence)` key. The approval rolled back whole, so the database state stayed safe. `f553a10f` reads the sequence with a locking read (`FOR UPDATE`), and the race and every native test that writes events then pass. SQLite cannot show this defect, because it has no snapshot isolation between connections in these tests. The failing run on `3e0bd1aa` is mutant M21 (the locking read removed): it is killed natively.

**Census and selection after the fixes:** the SQLite skip census has 307 entries (main's 302 first, then the five CX race methods). The native selection is proved as 2,639 of 9,877 cases in 254 files, and both count comments are updated (`3e0bd1aa`).

### Results after the review fixes

All results are on `f553a10f` unless stated otherwise. Commits after `f553a10f` change documentation only.

- **Native MySQL 8.4.11** (private server):
  - **On `3e0bd1aa`** (identical to `f553a10f` apart from the event-sequence read): `LegalSiteSchemaMigrationTest` 5 OK, `CustomerDataRequestMigrationTest` 2 OK, the duplicate-request and rejection races OK.
  - **On `f553a10f`:** the new export/approval race and the rejection race OK (2 tests, 139 assertions); the access-withdrawal and double-download races (2 tests, 99 assertions) and both `database_guards` tests (2 tests, 27 assertions) OK.
  - **Total: 14 of 14** CX native tests.
- **SQLite:**
  - `tests/Feature/CustomerComms` on `f553a10f`: 99 tests, 788 assertions, 5 census skips.
  - On `105f37a2`, every suite the reviewer ran, all OK: the nine notification roundtrips, `SiteRelatedTrackImageMigrationTest`, `TransactionalNotificationMigrationTest`, `SiteImageMigrationTest`, `SiteContentTest`, `SiteEditorialHttpTest`, `SiteEditorialContentTest`, `InstallationReportTest`, `StagingRuntimeValidatorTest`, `ProductionEnvironmentTemplateTest`, `TestCommerceProfileValidatorTest`, `SiteImageReleaseTest`, `TransactionalNotificationTest` and `CustomerAccountMigrationTest`. That is 23 files with no failures.
- **Safeguards on `3e0bd1aa`:** `test-phpunit-shards.py` 52, `test-ci-scope.py` 27, `test-workflow-cadence.py` 14, `test-focused-tests.py` 53, `test-database-receipts.py` 61, `test_staging_capability_profile.py` 18, `test_staging_code_drift.py` 10, all OK. `composer validate --strict` reports valid. The census has 307 entries with main's 302 as an exact prefix.
- **Mutations:** M17–M20 killed on `105f37a2` (SQLite); M21 (no locking read) killed natively on `3e0bd1aa`.

### Final merged head (2026-10-30)

`a4a2dbd4` merges `origin/main` `76fb9338` (PR #179: `symfony/mime` 8.1.10 and `polyfill-mbstring` 1.43.3 for CVE-2026-45070, lockfile only). After `composer install`:

- `composer audit` reports "No security vulnerability advisories found". `composer validate --strict` reports valid.
- Safeguards all OK: `test-phpunit-shards.py` 52, `test-ci-scope.py` 27, `test-workflow-cadence.py` 14, `test-focused-tests.py` 53.
- The native selection is re-proved unchanged: 2,639 of 9,877 cases in 254 files.
- SQLite: `tests/Feature/CustomerComms` 99 tests, 788 assertions, 5 census skips. `TransactionalNotificationTest` 52 OK. `InquiryNotificationWorkTest` 15 OK.
- The native MySQL set was not rerun after this lockfile-only merge. Its last native run was the 14 of 14 above on `f553a10f`/`3e0bd1aa`.

## Re-review F7 resolution (2026-10-31)

The independent re-review of `16687e9f` returned APPROVE WITH CONDITIONS. It verified F1, F2, F3 and F6, raised no P0 or P1, and found one new P2:

- **F7a, event level (new in `f553a10f`):** the event sequence was read with a locking `MAX()`. That X-locked the next key, or the supremum, of the shared `(request_id, sequence)` index, so an unrelated customer's new request waited for a whole approval.
- **F7b, request level (since `733b02f7`):** the open-slot checks used `FOR UPDATE`. Two customers requesting deletion at once both locked the supremum of `customer_data_requests_open_unique`, inserted, and deadlocked.

The record is imported as `independent-cx-rereview-16687e9f-DECISION.md`.

### Fix (`2872c10e`, fixtures `a9123898`)

Every writer already takes the customer's `users` row first, and the staff and prune paths lock the request row by primary key. Those row locks are the serialization points, so the range locks were removed.

1. **Counter.** `customer_data_requests.last_event_sequence` was added. Migration `268200` is amended; it is not on main and stays forward-only. The guards are:
   - **insert guard:** the counter starts at 0;
   - **event insert guard:** an event's sequence must equal the parent counter plus one;
   - **counter update guard:** the counter may advance by exactly one, only to an event that exists, with every other column unchanged;
   - **state transition:** it must leave the counter unchanged.

   With the unique `(request_id, sequence)` key, sequences are gap-free. `event()` inserts the event and then advances the counter on the request row it already holds.
2. **No range locks.** The open-slot checks are plain reads. The unique open-slot index is the backstop. A violation on insert maps to a clean `409` (`deletion_pending` or `export_pending`), and a test proves that path. Open exports are discovered with a plain read and then locked one by one by primary key.
3. **READ COMMITTED with retries.** Every data-rights transaction is one READ COMMITTED transaction, set per attempt because `SET TRANSACTION` applies to the next transaction only, with three attempts on a deadlock or lock-wait timeout.
   - Plain reads after the user-row wait then see committed work. This is what lets approval still purge an export committed while it waited (F1).
   - InnoDB takes no gap locks at this level.
   - `pruneExpired` uses the same helper, so it retries deadlocks too.
4. **Contention response.** Contention that outlives the retries answers `503` with `reason: try_again` and `Retry-After: 5`. Before this fix, an unhandled exception on `/account/*` already became the generic customer-privacy `503` ("Sign-in could not be completed"), not a raw 500 as the re-review inferred, so the message was misleading rather than a crash. Any other database error keeps that generic response.

### Is a concurrent duplicate open request still impossible?

Yes. Two requests of the same kind for one account both take the customer `users` row FOR UPDATE first, so they serialize. The second then reads the first's committed row, because it runs at READ COMMITTED, and replays or refuses.

If a writer ever skipped the user lock, the unique `(customer_account_id, kind, open_slot)` index would refuse the insert. `open()` maps that to a `409`; see `test_the_unique_open_slot_index_refuses_a_second_open_request_cleanly`, which calls `open()` past the service's own check. The native race `test_a_deletion_request_committed_while_a_duplicate_waits_is_returned_instead_of_a_second_request` covers the serialized path.

### New native race

`test_unrelated_customers_request_deletion_together_while_an_approval_is_in_flight_without_waits_or_deadlocks`:

1. Staff approval of customer X is paused after its final audit write, so it holds every lock it takes, including X's purged export and the newest events.
2. Customers Y and Z, created after X so their rows land at both index tails, are each paused right after their open-slot check and then released together.
3. The test asserts:
   - Y and Z both finish while the approval is still paused;
   - `performance_schema.data_lock_waits` never shows Y's or Z's connection waiting;
   - InnoDB's `lock_deadlocks` counter does not move;
   - after the approval is released, every request's events are exactly 1..n and its counter equals n.

### Results on `a9123898` (the merged head with F7; main `a926f98b` merged in `27e14ee6`)

- **Native MySQL 8.4.11:** 15 of 15 CX native tests pass, one test per run on a private server with its own datadir and port 3467, every run at `HEAD=a9123898`. The `dirty` working-tree files were documentation only.
  - `race-unrelated` 1 test, 315 assertions
  - `race-duplicate` 1 test, 117 assertions
  - `race-rejection` 1 test, 82 assertions
  - `race-withdrawal` 1 test, 85 assertions
  - `race-download` 1 test, 62 assertions
  - `race-export` 1 test, 98 assertions
  - `guards-rights` 1 test, 27 assertions
  - `guards-mail` 1 test, 9 assertions
  - `rights-mig-schema` 1 test, 7 assertions
  - `rights-mig-populated` 1 test, 3 assertions
  - `legal-migrated_schema` 1 test, 17 assertions
  - `legal-late_image` 1 test, 5 assertions
  - `legal-down_refuses` 1 test, 4 assertions
  - `legal-round_trip` 1 test, 7 assertions
  - `legal-foreign_or_missing` 1 test, 4 assertions
- **SQLite:**
  - `tests/Feature/CustomerComms` on `a9123898`: 103 tests, 817 assertions, 6 census skips (the six native races).
  - On `27e14ee6`: `QuotePricing`, `OrderPreparation` and `HostedCheckout` migration roundtrips, `CustomerAccountMigrationTest`, `TransactionalNotificationTest`, and the `StagingRuntimeValidator`, `TestCommerceProfileValidator` and `ProductionEnvironmentTemplate` unit tests all OK.
- **All of `tests/ops`** pass on `a9123898`, 20 files including `test_staging_protected_configuration` (3 OK in this container).
- **Safeguards:** `test-phpunit-shards.py` 52, `test-ci-scope.py` 27, `test-workflow-cadence.py` 14, `test-focused-tests.py` 53, all OK. `test-database-receipts.py` 61 OK on `27e14ee6`.
- **Composer:** `composer audit` reports no advisories. `composer validate --strict` reports valid.
- **Census and selection:** the SQLite skip census has 308 entries (main's 302 first, then the six CX races). The native selection is proved as 2,657 of 9,912 cases in 262 files, and both count comments match.
- **Mutants:**

| Mutant | Engine | Commit | Result | Killed by |
| --- | --- | --- | --- | --- |
| M22: locking `MAX()` event sequence at REPEATABLE READ | native | `27e14ee6` | killed | the unrelated-customers race: "A new request waited for the approval in flight." |
| M23: locking open-slot checks at REPEATABLE READ | native | `27e14ee6` | killed | the unrelated-customers race |
| M24: REPEATABLE READ, counter and plain reads kept | native | `a9123898` | killed (`Failures: 1`) | the export-committed-while-approval-waits race (approval misses the export) |
| M25: no unique-index mapping in `open()` | SQLite | `27e14ee6` | killed | |
| M26: no retry on contention | SQLite | `27e14ee6` | killed | |
| M27: controller does not map lingering contention | SQLite | `27e14ee6` | killed | |
| M29: counter update does not require its event | SQLite | `27e14ee6` | killed | |
| M28: counter may advance by more than one | SQLite | `27e14ee6` | **equivalent** | survives because the `EXISTS` clause already forces `+1`: an event at counter+2 cannot exist before the counter reaches counter+1 |

  The first M24 run on `27e14ee6` errored only because that race's raw fixture lacked the new counter (fixed in `a9123898`), so it was rerun.
- **Process note:** while stopping my own native runner I once used `pkill -P <runner pid>`, which signals only that runner's child. I then stopped the remaining processes by exact PID. No other lane's process or server was signalled.

**Not tested:** concurrent HTTP load end to end, the full native selection, Foundation CI, browser specs and the frontend on this head. F7 changed no frontend file.

## Second re-review at `e6f46cdd` (F7 fix)

Decision: **APPROVE** ([`independent-cx-rereview2-e6f46cdd-DECISION.md`](independent-cx-rereview2-e6f46cdd-DECISION.md)),
F7 resolved, no new P0 to P2. Natively through the real service code: the request-level probe (two customers requesting
deletion) holds no row locks at the pause, waits for nothing and deadlocks 0 times; the event-level probe (an approval
paused with all its locks against another customer's deletion) shows only record locks on each side's own rows, no
wait and no deadlock. Every forbidden counter or event write tried in raw SQL was refused; on a populated database the
209 existing tables are unchanged and `migrate:rollback --step=3` refuses at `268300`. A second open request for one
account cannot coexist (user-row lock, then the unique index, error 1062 mapped to 409). READ COMMITTED is load-bearing
for the F1 purge (mutant C fails the purge race). Mutants A (locked MAX restored), C, A+C and B+C are killed; B alone
survives as expected (a locking read on an empty range takes no gap lock under READ COMMITTED).

Deferred P3 notes: N1 the export document is assembled under READ COMMITTED, so its sections can reflect slightly
different moments if the customer's own order changes mid-build; N2 retried lock-wait timeouts can hold a request up to
about 150 s before the 503; N3 the Filament staff actions show a generic error page for a database error that outlives
the retries; N4 record at the open-export lookup that the F1 purge depends on READ COMMITTED.

## Follow-up batch FUB-1 (2026-10-31)

Branch `harness/followups-rep-cx-20261031` from `origin/main` `8bb1ab24`. Deferred CX findings, each fixed with a regression test
that fails without the fix, or left deferred with a reason. Ledger: `docs/handoff/2026-10-07/codex-deferred-20261011.md`
(2026-10-31 FUB-1 entry).

| Finding | Resolution | Commit | Test |
| --- | --- | --- | --- |
| Codex P2 r4432808542: refused order-ready intents filled every `vasey:transactional-mail-queue` batch, so newer valid intents were never queued | Fixed without a migration. A terminal refusal row is not possible: the delivery insert guard admits only `queued`, `queued → failed` admits only `not_configured`/`configuration_changed`, and reaching `authority_withdrawn` would need a fabricated `submitting` claim; notices are immutable. Instead: (1) an intent whose customer access version has been superseded (the account guard allows only `+1` steps, so it can never verify again) is excluded from the pending set by the query and reported as `superseded=N`, with nothing written; (2) every other refusal stays pending, is counted under `skipped` with its reason, and the run keeps scanning past it, examining at most `4 × --limit` intents. `--limit` now counts emails queued per run. Transient refusals (intent policy off, recipient changed) stay pending and are queued once they clear. | `b9cdc005` | `TransactionalMailTest::test_refused_intents_ahead_of_a_valid_one_never_hold_the_batch_and_only_superseded_access_leaves_the_pending_set`; existing command expectations gain ` superseded=0` |
| N3: Filament staff data-rights actions showed a generic error page for database contention that outlived the retries | Fixed. A concurrency `QueryException` (Laravel's detector, as the service uses) becomes a persistent "Request not changed … Try again in a moment." notification and a warning log with the exception class only; the transaction rolled back, so nothing changed. Any other database error is rethrown. | `035c9a8d` | `CustomerDataRequestAdminTest::test_database_contention_that_outlives_the_retries_is_a_try_again_notification_and_other_database_errors_are_not_hidden` (three attempts observed) |
| N4: record at the open-export lookup that the F1 purge depends on READ COMMITTED | Done (comment naming the F1 race test). | `7f7086ec` | the existing native F1 race |
| F5: no operator view of deliveries stuck in `submitting` | Fixed. Read-only `vasey:transactional-mail-stuck {--minutes=15}`: `stuck=N`, then delivery id, notice id and claim time (UTC), oldest first, at most 100 (`more=N`). Writes nothing, prints no recipient. Replaces the hand-written SQL in `docs/transactional-mail.md`. | `68d840d8` | `TransactionalMailTest::test_the_stuck_listing_reports_only_deliveries_in_submitting_past_the_threshold_and_writes_nothing` |
| N1: the export document is assembled under READ COMMITTED | Still deferred: a consistency trade-off, not a defect; a single snapshot would bring back the gap-lock and F1 issues the re-reviews closed. | | |
| N2: retried lock-wait timeouts can hold a request up to about 150 s | Still deferred: the retry budget and lock-wait timeout are an owner judgement call. | | |
| F4: schedule the export prune | Still deferred: needs a host and scheduler. | | |

### Mutants (one at a time, SQLite, each killed)

| Mutant | Result |
| --- | --- |
| X1: no superseded exclusion | killed (the expected command output was not printed; 2 failures) |
| X2: no scan past refusals (`budget = limit`) | killed (the expected command output was not printed; 2 failures) |
| N3-M1: no `QueryException` mapping | killed (the exception escapes the action) |
| N3-M2: every `QueryException` mapped to "try again" | killed (the non-contention error is hidden) |
| F5-M1: listing ignores the state | killed (the finished delivery is listed) |
| F5-M2: listing ignores `--minutes` | killed |

### Results

Native MySQL 8.4.11 (private `mysqld`, own datadir, port 3491; stopped by `mysqladmin shutdown`, datadir removed), at `68d840d8`:
`TransactionalMailTest` (the eight methods other than the configuration data provider, including both new ones) OK (8 tests, 112 assertions);
`CustomerDataRequestAdminTest` OK (3, 62); the F1 race `test_an_export_committed_while_an_approval_waits_is_purged_by_that_approval` OK (1, 48).
SQLite: `tests/Feature/CustomerComms` 106 tests, 870 assertions, 6 census skips (the six native races). No CX native-only test was added.
Safeguards, `tests/ops`, `composer audit` and the selection counts: see the REP packet's FUB-1 results (same branch and run).

Not tested: a real worker dying mid-send (the stuck rows are written through the guard's own `queued → submitting` step), real SMTP,
real lock contention reaching the staff action (it is injected as a lock-wait timeout on every attempt), browser specs, Foundation CI.

### Review fixes (after `c86ceb0c`)

Independent review of `c86ceb0c`: APPROVE WITH CONDITIONS, record and resolution table in
[`independent-fub1-review-c86ceb0c-DECISION.md`](independent-fub1-review-c86ceb0c-DECISION.md). Fixed: F1 (P2) and F2 in `38a93b08`
(customer, purchase and delivery refusals become `authority_withdrawn` in `queueOrderReady()` and `deliver()`; any other per-intent
error is counted as `error` and the run continues), F4 in `435e44e8` (stuck-listing `--max` and cap test), `043a61a0` (contention judged
by driver error code: MySQL 1213, 1205, SQLSTATE 40001; SQLite 5 or 6) and `6dc045f6` (tier-versus-order totals note). F3 (the scan
restarts at the oldest intent each run) is documented as a bound and deferred.

Mutants, SQLite, each killed: G1 mapping removed (both new tests fail); G2 command catch-all removed; M13 cap raised to 100000; a
`more=` line against a fixed 100; K1 Laravel's message detector restored (unit test: 8 of 12 cases fail; the staff test fails on the
lock-wait phrase in a duplicate-key error); K2 SQLite code 19 accepted as contention.

Results, code at `6dc045f6`:
- SQLite: `tests/Feature/Reporting`, `tests/Unit/ReportingCsvTest.php` and `tests/Unit/CustomerDataRightsContentionTest.php` 55 tests,
  478 assertions, 1 skipped (the native-only method); `tests/Feature/CustomerComms` 108 tests, 893 assertions, 6 census skips.
- Native MySQL 8.4.11 (private `mysqld`, own datadir, port 3491, stopped by `mysqladmin shutdown`, datadir removed): `TransactionalMailTest`,
  every method except the configuration data provider, including both new ones, the stuck listing and the database guards: OK (5 tests,
  68 assertions) and OK (5, 67), run in two halves on two databases; `CustomerDataRequestAdminTest` OK (3, 62);
  `CustomerDataRightsTest::test_contention_that_outlives_the_retries_is_a_clean_try_again_response` OK (1, 11); the F1 race OK (1, 79);
  `CustomerDataRightsContentionTest` OK (12, 12). A container restart interrupted the first native mail run; it was rerun in full on a
  fresh server.
- Census 310 entries (main's 309 an exact prefix); native selection **2673 cases in 264 files of 9945 discovered**, both count comments
  updated. Safeguards 52, 27, 14, 53, 61 OK; all 20 `tests/ops` files OK; `composer audit` no advisories.

## FUB-1 re-review at `cae73127`

Decision: **APPROVE** ([`independent-fub1-rereview-cae73127-DECISION.md`](independent-fub1-rereview-cae73127-DECISION.md)),
no P0 to P2; condition C1 met. The contention classifier was checked against real driver errors on both engines (MySQL
1205 and 1213/40001 and SQLite 5 are contention; 1062, 1064 with a lock phrase bound, missing tables and SQLite 19 are not).
Deferred P3 notes: P3-1 `authority_withdrawn` also covers global, possibly transient configuration refusals, so in
`deliver()` a temporary configuration problem fails that delivery (the safe direction); P3-2 the queue command exits 0
when some intents hit `error`; P3-3 (pre-existing) a dispatch failure after commit leaves a delivery `queued` with no job,
which the stuck listing does not show; P3-4 no test covers the `QuoteException`/`DeliveryException` mapping.
