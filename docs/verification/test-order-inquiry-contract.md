# Test-order inquiry implementation contract

Prepared from inclusive source `f480c196` on October 6, 2026. This is a bounded T17 implementation contract, not acceptance evidence.

Customers can explicitly ask about one order they currently own through the existing guest, direct-account or exact claimed-purchase rules. The new capability requires local/testing and `inquiries.test_order_inquiries_enabled=true` (`CONTACT_TEST_ORDER_INQUIRIES_ENABLED`, default false), plus the existing published contact notice, retention reference and responsible operator. No new recovery, terms, refund, attachment, notification or production policy is introduced.

## Parallel ownership

- Domain lane: `OrderInquiry`, immutable context model/migration, `SubmitInquiry` generic replay isolation, new controller, existing inquiry routes/privacy, `config/inquiries.php`, focused domain/HTTP/migration/native race tests and this evidence contract.
- UI lane: `OrderInquiry.tsx`, bounded client parsing, the existing contact form's order-bound submission option, order-summary entry point, conversation's retained context display, `CustomerInquiryResource` display, frontend tests and an extension of the existing inquiry browser journey/helpers.
- Root: shared focused/native registration, exact census, composition, source publication and full acceptance.

## HTTP contract

All routes retain inquiry private/no-store/noindex/no-referrer responses, same-origin and no-query/body/method-override rules, ordinary CSRF, bounded JSON, existing throttle buckets and browser-session locking.

| Request | Success body |
| --- | --- |
| `GET /contact/inquiries/for-order/{order}` | `{orderInquiry:{orderInquirySchema:1,orderId,testOnly:true,privacyNotice,noticeToken}}` |
| `POST /contact/inquiries/for-order/{order}` | Existing `{state:'saved',receipt}`; 201 for creation, 200 for exact retry |
| `GET /contact/inquiries/{receipt}/order-context` | `{context:{orderInquiryContextSchema:1,order:null\|{id,testOnly:true}}}` |

POST accepts exactly the existing seven inquiry fields: `name`, `email`, `subject`, `message`, `website`, `requestKey`, `noticeToken`. The selected order is route-bound and never accepted from a client owner/account/body field. Disabled/foreign/unknown order admission fails generically; validation, stale notice, retry conflict and uncertain failure retain the existing inquiry error contract. Generic conversation/history DTOs and original inquiry hashes remain unchanged.

The retained context reader uses original-session `InquiryOwner` ownership, does not adopt an inquiry through an account/email/order association, and remains readable after the new write flag is withdrawn. It returns only the deliberately associated order reference and its test-only nature, without status, payment, items, identity or entitlement data. Staff rendering uses `OrderInquiry::staffContext(int $inquiryId, User $actor)` and the same inner context DTO after fresh existing staff/MFA authorization.

## Admission and persistence

Creation atomically retains the existing inquiry, immutable order-context association and minimized audit evidence. Exact retry requires the same inquiry owner, body, selected order and original order identity; ordinary contact retry cannot repurpose an order-linked request key. Contact publication/operator and customer credential/account/claim admission are checked transactionally in a reviewed lock order. All original commerce records, owners, grants, contracts and inquiry payload/hash evidence remain unchanged; no provider or financial operation occurs.

## Acceptance boundary

Required evidence includes actual shared-engine domain/HTTP/migration behavior; native retry and authority/publication withdrawal races; original/foreign/claimed owner cases; no context injection or cross-inquiry disclosure; rollback and append-only association guards; focused frontend and two-engine native customer/operator journeys. UI must preserve uncertain requests, retain the exact request key/body, clear abandoned private state and show truthful original-browser-only support access. Independent exact-source review and full integrated hosted acceptance remain required.

## Implementation and focused evidence

The backend child owns 18 executable paths plus this document. UI child `a360e0b7e18a569c110b2a15a5aa012db47037eb` owns the customer form, strict response readers, retained staff display and existing inquiry browser extension. The staff PHP tests deliberately require the composed UI resource. Testing uses an isolated composed checkout of that UI commit plus byte-identical backend paths; `order-inquiry-tested-source.json` records each executable SHA-256 and the exact resource hash.

The new immutable `inquiry_order_contexts` table is additive. It keeps a unique inquiry association, restrictive inquiry/order foreign keys, original inquiry/order hashes, an explicit versioned binding hash and the inquiry creation time. Model and SQLite/MySQL guards refuse update, delete, replacement, invalid shape, missing parents and mismatched parent hashes. Empty rollback verifies exact schema ownership first and refuses retained rows, altered or temporary objects, foreign guards/indexes and incoming references. Existing order/inquiry migration round trips must remove `000049` first; root owns that integration.

Admission uses publication → responsible operator → customer user/account → order → inquiry/context locks. Current identity is rechecked after retained-order reconstruction and after audit; current staff role/MFA is rechecked after context reconstruction/audit. Late in-transaction withdrawal therefore rolls back instead of returning a retained private reference. Context reading independently verifies the original encrypted order graph and its test-only purpose, while projecting only the order ID. Exact body hashes, original inquiry ciphertext and all commerce evidence remain intact.

New native coverage has 11 cases: exact duplicate and both commit orders for contact publication, responsible-operator role/MFA, customer credential and customer-account withdrawal. Each case starts separate PHP processes, verifies distinct PIDs/connections and REPEATABLE READ, observes the exact expected InnoDB PRIMARY record wait through `performance_schema`, then verifies outcome, retained originals, one-or-zero context/audit rows and zero leaked transaction levels. Readiness JSON is written completely to a sibling file and atomically renamed before the parent can observe it.

The narrow browser fixture router requires the existing private disposable directory/database/session/cache markers, explicit loopback host/address, selected published inquiry release, exact 64-hex capability and default-off inquiry flag. It enables existing synthetic order-preparation policy only on exact quote/pricing/review/order routes. Actual application requests create the guest-owned order; it never rewrites an owner, intercepts inquiry results or binds a payment provider. `run.mjs` enables the flag only in its disposable environment. Root will preserve the newer independent runner diagnostics when composing that single-line change.

Recorded development runs:

- HTTP contract: 16/16 cases, 251 assertions after correcting session-store setup and comparing freshly loaded raw attributes.
- Expanded SQLite affected selection: 157/157 cases, 3,617 assertions, including all generic inquiry HTTP and inquiry conversation regressions (before the final five late-read regressions).
- Native MySQL before the final read-fence addition: 19/19 cases, 874 assertions (11 real process races and eight migration cases), MySQL 8.4.11 with `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite ON and binary logging ON.
- Exact UI resource composition: five PHP/Livewire cases, 37 assertions (three new staff context cases plus two existing conversation administration cases), before the final read-fence addition.
- Scoped Pint, changed-source whitespace check, PHP router lint and runner JavaScript syntax passed.

Initial failed attempts are retained, not counted as acceptance: one production-gate test failed teardown because its synthetic environment was not restored; HTTP setup first supplied SessionManager instead of the session store, compared different raw attribute key order, and attempted a guarded account change without incrementing its version. One later version test attempted a version-only update instead of the real withdraw/reactivate sequence. The first native run passed seven of 11 cases; the remaining four failed fixture setup because removed contact content still had a contact navigation entry, or a model `tap` call forwarded to a builder. These fixture errors were corrected while keeping product behavior and acceptance assertions. A mistyped affected-test filename was rejected before execution and then corrected. Independent review identified and closed the separate real post-projection staff-authority seam described above.

Final composed SQLite passed 54/54 cases and 403 assertions in 85.872 seconds after all product review changes. The complete native run exercised 65 cases and 1,256 assertions in 465.280 seconds: 64 passed and one HTTP test reported a teardown error. All 49 cases outside the HTTP class passed, including 27 domain (105 assertions), eight migration (33), three actual staff rendering (14), and 11 real contention cases (853). The remaining test had already passed its response/privacy assertions; its deliberate `site_publications` query fault also fired on MySQL cleanup metadata SQL. A one-shot injection correction now disarms immediately before throwing, preserving every HTTP assertion and all product code. The complete 16-case HTTP class was rerun on both engines: SQLite passed 16/16 and 251 assertions in 23.519 seconds; native MySQL 8.4.11 with the same normal durability passed 16/16 and 251 assertions in 105.810 seconds. Both corrected runs have zero failures, errors or skips.

Final focused receipts are `order-inquiry-composed-sqlite.{xml,log}`, the candid full `order-inquiry-composed-mysql.{xml,log}` failure receipt, and `order-inquiry-http-corrected-{sqlite,mysql}.{xml,log}`. The tested-source manifest retains both original and corrected HTTP test hashes. The earlier `order-inquiry-sqlite-final`, `native-second`, `staff-first`, `http-third` and other development receipts are supporting iterations, not substitutes for the final product-source checks. The 49 unchanged native cases were not repeated solely for this test-only cleanup correction. Exact independent source review approved backend commit `1f85e964b98fef4ce5e3364d33d7f07081e66198`; the two-path HTTP-test/results correction is reviewed separately.

A further Pint check of the small shared-file set reported only the pre-existing `tests/browser/server.php` formatting debt. Extracted baseline `f480c196` router bytes report the identical six fixer names. The bounded router insertion preserves its existing formatting; new PHP files and changed domain/routes/config/privacy pass scoped Pint. No genuine local ClamAV/native browser runtime is available for this feature. Native browser preparation, lost-response retry journey, two-engine layout/focus and the final inclusive CI remain hosted acceptance gates. No SQLite/jsdom/HTTP fixture result substitutes for those gates.

## Remaining boundary

This is local/testing support for explicit test-order questions. Production privacy/retention/operator choices, recovery across browser sessions, outbound notifications, attachments, approved payment/refund/license policy, live payment credentials, real imports and cutover remain separate work. This slice grants no payment, refund, delivery or usage authority and does not change DNS or launch status.

Final focused commands (from the disposable composed checkout, using the recovered PHP runtime and a generated disposable application key):

```sh
php vendor/bin/phpunit tests/Feature/OrderInquiryTest.php tests/Feature/OrderInquiryHttpTest.php tests/Feature/OrderInquiryMigrationTest.php tests/Feature/OrderInquiryStaffContextTest.php --log-junit /workspace/scratch/0c039e9e0645/order-inquiry-composed-sqlite.xml
python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/OrderInquiryTest.php tests/Feature/OrderInquiryHttpTest.php tests/Feature/OrderInquiryMigrationTest.php tests/Feature/OrderInquiryStaffContextTest.php tests/Feature/OrderInquiryConcurrencyTest.php --log-junit /workspace/scratch/0c039e9e0645/order-inquiry-composed-mysql.xml
```

Shared integration must add the four non-native classes (54 cases), the native class (11 cases), its SQLite exclusion and duration/census registrations without changing their existing method identities or CI budgets. Root owns those edits, legacy migration chains and inclusive acceptance. No bootstrap edit is part of this child.

Corrected-class commands (the only executable change after `1f85e964` is this one-shot test injection):

```sh
php vendor/bin/phpunit tests/Feature/OrderInquiryHttpTest.php --log-junit /workspace/scratch/0c039e9e0645/order-inquiry-http-corrected-sqlite.xml
python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/OrderInquiryHttpTest.php --log-junit /workspace/scratch/0c039e9e0645/order-inquiry-http-corrected-mysql.xml
```
