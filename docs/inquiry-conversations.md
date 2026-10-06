# Private inquiry conversations

This bounded T15/T17 child extends the existing saved-contact inquiry with in-app replies. On the published contact page, a visitor can submit an inquiry, choose **Read replies and follow up**, read a staff reply and send a follow-up. **Read an existing inquiry** accepts the saved receipt in the original browser session. In the existing Filament inbox, staff open the inquiry, review its conversation and choose **Reply in app**. **Archive** stops new messages while retaining readable history.

Replies are not emailed. A receipt is a locator, not an access credential. The original `InquiryOwner` session binding is required for every owner read and write; a matching email, known receipt, different browser session or later account sign-in does not recover access. Losing or rotating that binding can make the conversation unavailable. No customer account linkage, order support, attachment, notification provider or recovery promise is introduced.

## Retained content and authority

The original inquiry, notice and retention reference remain unchanged. Each reply is an encrypted plain-text message with an immutable inquiry reference, sender kind, staff actor where applicable, request UUID, canonical body digest and timestamp. The service permits at most 100 messages per inquiry and 4,000 Unicode characters per message. These are technical bounds, not a new retention policy. Reads eagerly return that bounded history; there is no polling, export or browser storage of private content.

Every owner operation checks the exact receipt and original owner hash under the inquiry lock. Staff operations reload and lock current staff authority and required MFA before locking the inquiry. The same inquiry fence serializes replies with the existing archive action. New messages require enabled inquiry intake configuration and a non-archived conversation with remaining capacity. Existing messages remain readable by their authorized owner or staff when intake is disabled; the receipt-only opener remains on an existing published contact page. This does not publish a withdrawn contact page.

The request key belongs to one inquiry and sender kind. An exact authorized replay returns the retained message identity, including after archive or intake withdrawal. A changed body or different staff actor conflicts. Message and minimized audit evidence commit together; an audit failure rolls back the message. Owner audits are explicitly anonymous, independent of ambient staff authentication. An uncertain visitor or staff save retains its exact request key and text for retry, instead of silently creating another message.

`GET /contact/inquiries/{receipt}/conversation` accepts no query or body. `POST` accepts exactly two string fields, `message` and `requestKey`; duplicate JSON members, additional fields, invalid UUIDs, controls and oversized bodies are refused. Both routes retain session serialization, rate limits, same-origin checks and private/no-store, noindex, no-referrer responses. The write also requires the existing web forgery protection. Foreign or missing inquiries share the same generic response. Private message values and request bodies are not logged. The owner projections omit email addresses, internal actors, owner hashes, request keys, digests and private locations.

## Schema and operational boundary

Migration `2026_10_06_000043_inquiry_messages.php` adds a restrictive child foreign key to the existing inquiries. ORM and SQL guards forbid updates/deletes, prevent identity replacement even when SQLite recursive triggers are disabled, and refuse new messages against archived parents. Empty interrupted installations may resume only when every existing table, index, foreign key and trigger matches the expected definition. Retained messages with missing guards, unknown objects and temporary shadows fail closed for inspection. Rollback refuses populated evidence; an empty matching child must roll back before its parent. No purge or production retention change is supplied.

The browser fixture has separate `conversation-prepare` and `conversation-restore` modes and retained metadata, so it can coexist with the original inquiry persistence journey. It uses the same guarded disposable installation and real contact submission, staff login and reply services. The retry journey drops only an acknowledgement after the real server has committed, then verifies one retained message after exact retry.

## Verification boundary

Focused domain, HTTP, actual Livewire, schema and existing inbox/parent migration checks are recorded with the integrating source. Native races use independent processes and MySQL connections, an intentionally older repeatable-read view, and the observed exact waiting inquiry/user record before committing duplicate, archive, role or MFA changes. SQLite is not concurrency evidence. Frontend checks cover private state clearing, disabled-intake receipt access, exact uncertain retries, escaping and session expiry. The real Chromium/WebKit journey must still execute in the final hosted composition; fixture setup and discovery alone do not establish browser acceptance.

Recorded on October 6, 2026 with PHP 8.4.26:

- SQLite: the four new shared-engine files plus `CustomerInquiryAdminTest` and `CustomerInquiryMigrationTest` passed **65 of 68 cases / 745 assertions**, with only the three existing MySQL schema exceptions skipped, in 6.674 seconds.
- Native MySQL 8.4.11: the same selection plus `InquiryConversationConcurrencyTest` passed **76 cases / 1,041 assertions, zero failures/skips**, in 152.968 seconds. Invocation used the session's disposable `mysql-runtime/run-tests.py` wrapper with normal durability and `--stop-on-error --stop-on-failure`. Its eight new race datasets share one exact native method identity; shared CI registration belongs to integration.
- The interface author reports **390 frontend cases passed**, production build and TypeScript passed. After composition, the backend author ran `node tests/browser/run.mjs tests/browser/inquiry-conversation.spec.ts --list` with recovered PHP on `PATH`: fresh SQLite migrations, interactive operator setup and installation diagnostics passed, followed by discovery of the two conversation cases in Chromium/WebKit. This wrapper result is distinct from the interface author's earlier direct Playwright configuration discovery. Local browser binaries were unavailable; no native browser execution is claimed.

The backend and interface authors independently reviewed each other's source. Final combined commit review and the required complete acceptance gates remain with the integrating lead.

This is a usable in-app conversation child. Full T15/T17 support, order-aware messaging, private attachments, account recovery, production notice/retention approval and transactional email remain separate requirements.
