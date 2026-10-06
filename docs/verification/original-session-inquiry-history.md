# Original-session inquiry history

This T17 child lets a visitor explicitly list and reopen inquiries from the browser session that sent them. The existing receipt-only opener remains available. Summaries show only the original subject, creation time, receipt and retained state; conversation reads and follow-ups continue through the existing services.

Prepared separately from PR8 on `codex/original-session-inquiry-history-20261006`, based on `75722ac914e550f1b9421f3ecfa81adb13e7ab62`. Root owns composition, shared registrations and full hosted acceptance. This is not account recovery, order-aware support, email delivery, private attachments or production enablement.

## Contract and retained authority

`GET /contact/inquiries/history` returns the newest page. `GET /contact/inquiries/history/before/{receipt}` requests an older page. Both retain the existing inquiry read throttle bucket, session lock, same-origin checks and private/no-store/noindex/no-referrer response protection. Query strings, GET bodies, ranges, method overrides and other methods remain refused. No receipt cursor appears in a query string.

The exact response is `{history: {inquiryHistorySchema: 1, inquiries, limit: 20, nextCursor}}`. Each summary contains exactly `receipt`, `subject`, `state` and `createdAt`. Subjects remain unnormalized original text, with the existing 160-Unicode-code-point and control-character bounds. Dates are canonical UTC seconds. States remain `new`, `read` or `archived`; they do not assert unread replies or successful delivery.

The service filters by `InquiryOwner` before resolving the cursor or selecting rows, then compares owner hashes and public receipts byte-for-byte. Foreign and unknown cursor references return the same generic 422. It selects at most 21 rows in descending immutable insertion-ID order, projects 20 and does not decrypt the sentinel or load messages. No inquiry, message, audit or commerce record is mutated. Owner history remains readable after intake withdrawal or archive, as the existing conversation does. The interface appears only through the current non-preview contact opener; this does not publish a withdrawn contact page.

Reads are fresh ordinary HTTP/autocommit database observations, under the existing browser-session request lock. This service does not promise a locking current read when embedded in an unrelated pre-existing repeatable-read transaction. Inquiry ownership is immutable. Account sign-in, matching email and a known receipt do not adopt prior inquiries.

The interface makes no initial request. Older, Newer and Newest fetch fresh pages; only cursor anchors stay in component memory. Errors clear summaries and anchors. Each history request has a 20-second deadline and generation/controller ownership. Page departure clears pending work and selection; opening a conversation unmounts the list. The existing conversation's pending deadline is now also disposed immediately on unmount. Late completions cannot restore abandoned history or cancel a newer request's deadline.

The history reader caps streamed JSON at 64 KiB, checks fatal UTF-8 decoding and exact DTO keys, and rejects redirects, duplicate receipts, invalid dates, oversized subjects and unpaired surrogates. Twenty maximally escaped 160-code-point supplementary-Unicode subjects fit beneath that bound. No private rows or cursor history enter persistent browser storage.

## Native query assessment

An isolated scratch probe installed the unchanged full schema on MySQL 8.4.11, with `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite and binary logging enabled. It inserted 5,001 guarded synthetic inquiry rows: 501 for one owner and 4,500 for other owners. No index or table definition changed.

| Query | Returned rows | Actual examined index rows | EXPLAIN ANALYZE time |
| --- | ---: | ---: | --- |
| Newest | 21 | 471 | 0.605–0.620 ms |
| Before owned cursor | 21 | 21 | 0.023–0.044 ms |

MySQL chose a reverse primary-key scan/range without a sort. The measured result does not justify a migration. `LIMIT 21` bounds returned/projection rows, not total index work; the newest query demonstrates that distinction. Different owner distributions and production volumes may change the plan. Receipt: `/workspace/scratch/0c039e9e0645/inquiry-history-native-plan.json`; probe/log: `InquiryHistoryPlanProbeTest.php` and `inquiry-history-native-plan.log` in the same scratch directory. Probe passed one case / four assertions in 7.297 seconds.

## Executed verification — October 6, 2026

Runtime used PHP 8.4.26 from `/workspace/scratch/0c039e9e0645/runtime-recovery/bin`, physical Composer dependencies with refreshed autoloading, Node 24.19.0 and the existing locked frontend dependencies. Backend worktree contains no generated Vite manifest.

- SQLite command: `php vendor/bin/phpunit tests/Feature/InquiryHistoryTest.php tests/Feature/InquiryHistoryHttpTest.php tests/Feature/InquiryConversationTest.php tests/Feature/InquiryConversationHttpTest.php tests/Feature/CustomerInquiryHttpTest.php --log-junit /workspace/scratch/0c039e9e0645/inquiry-history-final-sqlite.xml`. Passed **146 cases / 3,894 assertions**, zero failures/skips, in 8.898 seconds.
- Native command: `python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/InquiryHistoryTest.php tests/Feature/InquiryHistoryHttpTest.php tests/Feature/InquiryConversationTest.php tests/Feature/InquiryConversationHttpTest.php --log-junit /workspace/scratch/0c039e9e0645/inquiry-history-native.xml`. Passed **47 cases / 701 assertions**, zero failures/skips, in 13.105 seconds, with the normal durability settings above. No new writer or concurrency claim is introduced.
- Focused frontend command: `npm test -- tests/frontend/inquiry-history.test.tsx tests/frontend/inquiry-conversation.test.tsx tests/frontend/contact-inquiry.test.tsx`. Passed **76 cases across three files**.
- Final full frontend `npm test`: **668 cases across 32 files**, all passed, in 15.42 seconds. Receipt: `inquiry-history-full-frontend.log`.
- `npm run typecheck` passed. `npm exec vite -- build --outDir /tmp/va-inquiry-history-build --emptyOutDir` passed, keeping artifacts outside the backend worktree. Main chunk is 515.71 kB; the existing 500 kB advisory remains visible and its budget was not raised.
- Direct Playwright `--list` discovered the two existing `inquiry-conversation.spec.ts` cases, one per Chromium/WebKit project. The extended journey reloads contact, explicitly loads the real minimal history response, reopens the archived conversation without entering a receipt, verifies foreign empty history and indistinguishable cursor refusal, then independently repeats the original manual-receipt path. Existing reply, exact uncertain retry, archive, storage, responsive and page-error assertions remain.
- `git diff --check` passed.

The two new PHP files add **21 shared-engine cases**, with no native-only exclusion. The new frontend file adds **27 cases**. The browser extension adds no file or test identity. Integration must register the two PHP files and frontend selection; existing inquiry browser registration remains sufficient.

## Failures retained and limits

The first SQLite run failed all 21 cases before behavior assertions because the new isolated worktree lacked an application key; its ordinary local environment was then initialized. The second run passed 19 cases: a direct fixture attempt to clear the publication pointer correctly hit the immutable publication guard, and a manually supplied `QUERY_STRING` was overwritten by the test request factory. The fixture now withdraws contact through normal `SiteContent` create/publish services, and the HTTP case sends the actual discarded `?&&` URI. No application guard or expected refusal was weakened. The next focused run passed 21 / 406. These receipts remain `inquiry-history-first-sqlite.log`, `inquiry-history-second-sqlite.log` and `inquiry-history-third-sqlite.log`.

The first two focused frontend runs passed 74 / 76. Exact timer counts also included jsdom's zero-delay `selectionchange` callback after intentional focus. `inquiry-history-timer-diagnostic.log` records the callback and its zero delay alongside the real 20-second application deadline. Tests now drain only zero-delay events before checking the same exact deadline counts; application timeout budgets are unchanged. The corrected focused and complete frontend runs passed.

The normal browser wrapper's initial discovery attempt refused the absent backend build manifest, as designed. Direct discovery used a temporary discovery-directory environment with `--list` only; it did not start the server, prepare fixtures or execute a browser. The initial direct command without that required environment also refused. Those failures remain in the working-session receipts; final direct discovery is `inquiry-history-browser-direct-discovery.log`.

Local pinned browser binaries and genuine ClamAV are unavailable. No browser rendering, fixture execution/timing, scanner acceptance or hosted acceptance is claimed. The final integrated candidate still requires actual Chromium/WebKit execution, the shared CI checks and independent review. This child does not close T17, T24, T32 or WP11.
