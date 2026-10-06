# Account order-reference lookup — bounded T24 child

Implemented separately from the PR6 correction candidate, based on `2bc0d5`. This WP-08 child lets a signed-in test customer enter a complete order reference and open the existing status and original-download flow without paging through the account history. It remains under the existing default-off local/testing account policy.

The lookup calls only the existing owner-checked `GET /orders/{uuid}/status`; it adds no endpoint, ownership rule, database schema, payment action or entitlement engine. Whitespace and uppercase pasted UUIDs normalize to the canonical reference. The response must identify that exact order and match the existing minimal history-summary schema. Quote, pricing and review locators are discarded after validation. The existing bounded private JSON reader limits response consumption to 128 KiB, and requests time out after 20 seconds without automatic retries.

Unknown, foreign-account and guest-owned references have the same private failure. Revoked account access offers a fresh sign-in. The input/result remain in component memory, never URL state or browser storage. Editing, clearing, page departure and sign-out discard the result and cancel pending reads; late responses cannot restore it. Successful lookup focuses its heading; failures focus their message. The existing status and download components retain separate current-availability and explicit authorization checks.

## Executed evidence

- `npm run test -- tests/frontend/customer-order-reference.test.tsx tests/frontend/owned-order-history.test.tsx tests/frontend/customer-account.test.tsx`: 97 tests passed, including 48 new transport/UI cases.
- `php vendor/bin/phpunit tests/Feature/CustomerOrderReferenceTest.php`: SQLite 4 tests / 478 assertions / zero skips.
- The same PHP suite on disposable MySQL 8.4.11: 4 tests / 478 assertions / zero skips. It proves lookup beyond the newest 20 orders, identical foreign/guest/unknown denials, withdrawal, no SQL/provider/renderer writes, and unchanged original private-file hashes/evidence.
- TypeScript/Vite build, client-bundle secret scan, PHP formatting and diff checks passed.
- Playwright discovered the new journey for Chromium desktop and WebKit mobile. Its native checks use real status/delivery metadata HTTP, keyboard entry/focus, exact original contract metadata and download availability, clear/sign-out cleanup and viewport bounds. This new case makes no order POSTs, preserving fixture independence from the existing account download cases and their exact original-byte/hash and stream-attempt assertions.
- CI focused selection now includes the new PHP class for SQLite and MySQL, plus the frontend and browser files. Customer/frontend/browser selections contain 13/27/26 files; the cap remains 32. All previous selections and their relative order are preserved, and the SQLite exclusion policy is byte-identical to the pre-registration child.
- Focused-selector safeguards: 36 passed; shard safeguards: 36 passed; database-receipt safeguards: 34 passed. Complete PHPUnit discovery and partition proofs account for 3,570 expanded cases in 224 files exactly once across both the two-shard SQLite and eight-shard MySQL layouts. The inventories agree, with all 371 existing exact native exclusions retained and none for the four new cases. Existing measured timing inputs remain unchanged; 39 untimed files, including this new class, use the supported fallback weight. These are discovery/partition proofs, not execution of the full suites.

Native browser execution is pending because this workspace lacks ClamAV/signatures and browser engines. Discovery and component tests do not establish browser acceptance. Existing correction-candidate acceptance remains separate. The feature commits received independent source approval; CI registration receives a separate exact-source review.

The next acceptance dependency is composed hosted native/full CI. Full title search, guest-order claiming, production identity/notification policy, large-file range/resume and production enablement remain open; this child does not close T24 or WP-08.
