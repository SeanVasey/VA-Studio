# License-template authoring and original-session inquiry history

October 6, 2026 UTC. This separately reviewed follow-up advances T12 and T17 after the customer library/test-commerce candidate in [PR #8](https://github.com/SeanVasey/VA-Studio/pull/8). It does not close either parent. The customer and financial candidate retains its own full acceptance boundary; its earlier receipts cannot accept this changed source.

## Usable changes

Staff can create a license template or review and edit its existing name, slug and type through audited domain commands. A captured identity and audit cursor reject stale or retargeted edits, including changes that return to the displayed values. Current authority and required MFA are checked under the existing compatible locks. Any non-draft version freezes template identity; the interface explains when a successor template is needed. Existing versions, published terms and original purchases remain intact. See [the authoring record](license-template-authoring.md).

A visitor can explicitly load and reopen inquiries sent from the original browser session. Fresh pages show the original subject, creation time, receipt and retained state. Owner filtering, strict bounded response validation and departure/request cleanup protect the private list. The existing manual receipt path remains. This adds no account/email adoption, order-aware support, attachment delivery or notification claim. See [the inquiry-history record](original-session-inquiry-history.md).

## Exact composition and review

| Component | Local commit | Tree |
| --- | --- | --- |
| Independently reviewed inquiry history, 12 paths | `795772bb045388bb6c0ffe379c8f68027cb68de2` | `9dad6fb83703ede6cacc277c862feb693dc02929` |
| Independently reviewed template authoring, 9 paths | `67f53e1b0f8f0a497698590c525b3fac3b7a08d5` | `7477d970a36d63b648d6f38705822de8155bd82a` |
| Exact two-feature composition | `febb4b101da8683765c6a686233ea10124d168b7` | `0185971f0e7cc1e164cc207c3d8a49ea8a1919ef` |
| Reviewed shared registration | `2846e343b8e25857689394555d64653cbf5362fa` | `7a81152f67e83e56a956b75326017a1abd17faf3` |
| Incorporate preceding claim-worker readiness correction | `03dec472cec754e5c52bd3fac3c66224dc6bb3bc` | `420b2af236b30183da4a8044986fe8874b1989dd` |
| Incorporate reviewed durable refund browser observation | `df1c83edaa207ec54542644a91b2c1771c56ced1` | `cde2bb92ab3dc00a5d7c00a9dff5f40aa705d021` |

The two-feature composition changes exactly the disjoint 21-path union over `840a3e7`; every feature blob matches its independently approved owner. Shared registration changes only the focused workflow's allowed suite list, the corresponding selector tuples and three exact native-only method rows. Independent review verified those registry bytes and the unchanged selector logic, gates and 32-file cap. Later claim-worker integration carries exactly its reviewed worker and [evidence document](claim-worker-readiness.md); it changes no feature, test identity or selection. The final preceding refund-browser correction changes only its response observation and evidence document: it verifies the committed release before deliberately dropping the response and compares the full proof afterward, preserving every existing retry/privacy assertion and budget. Publication records mapped Git identities and preserves ordered ancestry; these local IDs are not presented as published IDs.

## Executed coverage proof

Actual PHPUnit discovery at `2846e34` found **3,832 cases in 241 files** on both engine configurations. Every case, file and group occurs exactly once across eight MySQL and two SQLite partitions. All 3,746 preceding identities and their file mappings remain. The additions are 21 inquiry-history cases and 65 template-authoring cases, including eight native cases.

SQLite's exact exclusion policy contains **130 methods / 396 expanded identities**, preserving all preceding 127 methods / 388 identities. Only the three new template-concurrency methods are added, expanding to 2 + 2 + 4 cases. A complete SQLite run must execute 3,436 cases; this expected count is not an execution receipt. Its partitions contain 1,879 / 1,953 cases and 180 / 216 exact exclusions. Timing files remain unchanged, including ordinary fallback warnings for 18 untimed files.

Focused selections contain seller 31, licensing 8, frontend 30 and browser 32 files. Operator 32, unit 20 and all other selections retain their existing registrations. The new licensing selection includes the three authoring classes and five affected existing licensing suites. Selector safeguards passed 40 cases; partition safeguards passed 36. No limit, assertion or workflow gate was raised or removed.

Direct browser listing found **124 cases in 36 files**, 62 per engine, preserving all 122 preceding identities. Inquiry history extends an existing journey; template authoring adds one case per engine. The first listing failed because its import-only fixture manifest was absent. The successful `--list` used an empty scratch manifest solely for imports; no test function, server or fixture preparation executed. Listing does not prove rendered behavior. The preceding baseline worktree advanced during discovery, but all 63 compared browser discovery dependencies remained byte-identical.

The retained proof is `ci-authoring-support-census-2846e34.json`, SHA-256 `c92b3af6b689e4140602ec856ce0bb530a5a3bcb6a3b95d1d2d8e10cd9b87ec4`. All 1,324 tracked files were hashed unchanged throughout discovery. Commands used the committed `scripts/ci/phpunit-shards.py` and actual PHPUnit listings, followed by direct Playwright `--list`; this is coverage preservation, not behavior acceptance.

## Component behavior evidence and limits

Inquiry history passed SQLite 146 cases / 3,894 assertions, native MySQL 47 / 701, and the full frontend 668 cases in 32 files. TypeScript and an external-directory production build passed. Native query assessment preserved the schema and found no sort; the newest query examined 471 index rows to return 21. The limit bounds returned/projection rows, not all database work. Its record retains earlier fixture/request/timer failures and the exact corrections.

Template authoring's final SQLite result is 65 reported / 57 executed / 302 assertions with eight exact native skips. The eight genuine concurrency cases passed 746 assertions. The original combined native selection had 112 passes and two test-observation failures; all 57 neighboring licensing cases passed. A later dedicated 57-case process had loaded older notification assertions and retained those two failures. The corrected two notification targets subsequently passed 30 assertions. Those earlier results are not a clean final 57-case native pass; the authoring record retains source and timing distinctions.

The integrated `03dec472` source then executed the final corrected `LicenseTemplateAuthoringTest.php` and `LicenseTemplateAuthoringActionTest.php` together on a fresh MySQL 8.4.11 instance: **57 passed / 307 assertions**, zero errors, failures or skips, JUnit 287.364165 seconds. Normal durability was verified (`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite and binary logging enabled). The command was `python3 ../mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/LicenseTemplateAuthoringTest.php tests/Feature/LicenseTemplateAuthoringActionTest.php --log-junit ../authoring-support-final-native.xml`. The process exited zero; its console file contains the runtime record, while the complete 57-case result is in the JUnit. No test/application source changed during execution. This fresh result supplements rather than replaces the earlier failed observations.

On the same integration source, `npm test` passed **668 / 668 in 32 files**, 15.82 seconds (`authoring-support-final-frontend.log`). The subsequent refund-browser observation correction leaves both tested PHP files and all frontend/application source unchanged. The new composition and ledger edits are documentation only.

Genuine local browser/scanner execution remains unavailable. Require fresh full hosted checks, both native browser engines, all ten source-bound database receipts and exact aggregation on the final published candidate. Independent composition review and focused results do not replace those gates. Expected-head merge and fresh main verification follow acceptance. Production terms, roles/recovery policy, provider interoperability, deployments, imports and cutover retain their existing boundaries; six parent groups remain accepted and 34 open.
