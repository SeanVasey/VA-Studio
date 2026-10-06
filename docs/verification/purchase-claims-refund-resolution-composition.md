# Guest purchase saving, library browsing and refunded test exceptions

October 6, 2026 UTC. This candidate follows the collection refresh and original order-detail source in [PR #7](https://github.com/SeanVasey/VA-Studio/pull/7). At this checkpoint, that preceding PR has successful frontend, quality, both ordinary browser jobs, genuine related journeys and two independently verified SQLite receipts; its complete native MySQL receipt set and aggregate acceptance remain pending. Its results do not accept this changed source. Terminal results and published commit mappings belong on the respective PRs.

## Completed feature boundaries

The [guest purchase claim](guest-purchase-claim.md) lets the original guest browser explicitly select one completed paid test purchase before sign-in, then confirm saving it to that account. A bounded server-session challenge and immutable per-order claim support later history, reference lookup, original item details and original hash-checked downloads. The flow never infers ownership from email, rewrites the original owner, links sibling orders or broadens quote/checkout access. Current account, credential, policy and entitlement checks continue to govern access.

The [refunded exception workflow](../test-refunded-exception-resolution.md) lets authorized staff review an unfulfilled test paid exception and release only its original still-pending inventory/promotion resources after fresh, complete successful-refund observations. It preserves the original payment, purchased snapshots and rights evidence. Original-request and observation deadlines are checked after resource locks and again after audit; an exact completed retry confirms the retained result without new provider reads. It performs no refund or other provider mutation, does not release consumed resources, and does not relist a completed exclusive sale.

The [library increment](customer-library-browsing.md) adds recognizable first-original-item title/license previews and fresh Older, Newer and Newest navigation. Each card uses one verified original projection; current catalog data never replaces purchased evidence. A bounded streaming reader validates aligned summaries/previews, and late requests, page departure and access withdrawal clear private state. It adds no database schema, provider calls or access grants.

The claim and refund write features default off in local/testing; library browsing retains the existing test-account and owner/claim gates. Production identity/email, lost-browser recovery, provider interoperability, the wider refund/dispute/access policy and live commerce remain separate work.

## Source and independent review

| Component | Local source identity | Exact tree |
| --- | --- | --- |
| Preceding PR #7 source | `7067a95bbead0354d945994cbdc6a27a98ba101d` maps to published `f959635452f62e23617eadb83cabe0c984309a0b` | `b3919e551405dfb9103686c1f8e8518afad86327` |
| Guest purchase claim | `fb4a93e441e3a795d1333fab274aceeeb242e218` | `486e5fc9953e3e13582765504d43c0827486c850` |
| Refunded exception resolution | `820440b85cc539ac30ed30abf88fd948a61afcb2` | `8d55da482efc3821eb4db05ed836bca2dcd9aaef` |
| Integrated features before ledger and observation repairs | `80aa34faa0022d0cba44f8585c8b19181324acf8` | `7f74795edc5b9c400f68e94c0e59423e1bdeebef` |
| Existing focus-observation repair after the first ledger checkpoint | `0764b71fd7204b23671ea47cbd95893b4aedb5db` | `1dad4260b9fc55e72192e98a8f85c3c47e9e4990` |
| Original purchase previews and library navigation | `6d99542418d876e3a52171bd677ecd8ce05d7bf0` | `be58c8383a1028f3a41464e3dae6827e9cb3bd95` |
| Final application/test/registration composition | `9a9ac09242bc059b0186b336762a12a463010dca` | `9777f6e42f218e83c61af6b8de57217478b6f397` |

Local restored identities are not published Git identities. Publication verifies exact trees and preserves mapped ordered parents, including all independently reviewed feature branches and the preceding PR ancestry.

Independent review of the initial claim/refund composition verified the exact 70-path union relative to the earlier application composition `eec22b7`: 33 refund paths, 37 claim paths, four preceding documentation paths and four integration registry paths with overlaps. All 62 nonshared blobs exactly match their approved owners. The ordinary browser runner is exactly the approved refund version plus the explicit claim test flag; its ordinary refund flags remain false. The only seven manual merge resolutions are legacy migration roundtrips. Each removes claim migration 000048 before refund migration 000047 and restores them in the opposite order. Independent token comparison confirms that stripping only these additive child steps leaves every original code token and assertion unchanged. Existing parent migration assertions, selection logic, file cap, workflow gates, timing weights, test time limits and durability remain intact.

The final library composition preserves all 15 independently approved feature blobs exactly, the prior root documentation and ordered merge parents. Its only additional executable change is one browser-selector registration. All previous PHP, frontend and browser case identities remain, with exactly two PHP, 23 frontend and two browser cases added. No merge conflict or further application edit was needed. The full candidate changes 83 paths relative to the preceding PR #7 source.

## Composed verification

The first rows preserve checks on the initial `80aa34f` feature composition and isolated focus repair. The library rows apply to independently approved `6d99542`; all its feature blobs and all other executable application/test source are preserved in final composition `9a9ac09`, apart from the one explicit browser registry addition. Final integration selections are identified separately. Subsequent ledger edits are documentation only.

| Check | Actual outcome |
| --- | --- |
| Initial frontend and focus-repair `npm test` | Feature composition: 618 tests passed in 31 files. Complete isolated focus-observation repair rerun: 618/618 in 31 files, 14.02 seconds, zero failures. |
| `npx tsc --noEmit` | Passed |
| Production/preview builds and both client scans | Both builds passed in a separate detached worktree; production scan covered six text files and preview four, with no secret-name/Stripe-prefix hits. Existing production chunk advisory: 505.07 kB. |
| Initial both-project Playwright discovery | 120 cases in 34 files, 60 per configured browser; discovery only, no rendering |
| Initial seven new PHP classes on SQLite | 114 reported, 106 executed, 849 assertions, exactly eight registered native-only skips; no errors/failures; 198.930 seconds |
| Initial native MySQL composition selection | 26 passed, 567 assertions, no errors/failures/skips; 199.170 seconds. Includes all eight real concurrency cases, affected legacy parent/child empty migration roundtrips, original guest cross-session access and both full-refund resource shapes. |
| Focused-selector safeguards | 40 passed |
| Partition safeguards | 36 passed |
| Database-receipt safeguards | 34 passed |
| Final library frontend and build | 641/641 cases in 31 files, 15.07 seconds; TypeScript and production build passed. Existing production chunk advisory: 508.92 kB. Integration scanned all six generated text bundle files with no secret-name/Stripe-prefix hits. |
| Library SQLite overlap | History, original items and claim suites: 59 passed, 1,227 assertions, zero errors/failures/skips, 177.65 seconds |
| Library native MySQL overlap | 28 passed, 676 assertions, zero errors/failures/skips, 220.11 seconds, including the final claim-withdrawal preview fence |
| Final all-feature SQLite selection | On `9a9ac09`: 130 reported, 122 passed, 997 assertions, exactly eight registered native-only skips, zero errors/failures; 263.905 seconds. The seven new feature classes plus the complete history suite. |
| Final native MySQL integration selection | On `9a9ac09`: 42/42 passed, 738 assertions, zero errors/failures/skips; 292.913 seconds. Initial integration selection plus all history cases and the added preview-withdrawal fence. |
| Final both-project Playwright discovery | 122 cases in 35 files, 61 per browser; every earlier identity retained and both new library cases present; no rendering claimed |
| Final selector safeguards | All 40 passed; existing assertions and 32-file cap unchanged |

The initial SQLite selection was:

```sh
php vendor/bin/phpunit tests/Feature/CustomerPurchaseClaimTest.php tests/Feature/CustomerPurchaseClaimMigrationTest.php tests/Feature/CustomerPurchaseClaimConcurrencyTest.php tests/Feature/TestRefundResolutionTest.php tests/Feature/TestRefundResolutionMigrationTest.php tests/Feature/TestRefundResolutionResourceTest.php tests/Feature/TestRefundResolutionConcurrencyTest.php
```

The initial native selection used the same locked dependencies on a fresh disposable MySQL database:

```sh
php vendor/bin/phpunit tests/Feature/CustomerAccountMigrationTest.php tests/Feature/HostedCheckoutMigrationTest.php tests/Feature/OrderPreparationMigrationTest.php tests/Feature/PromotionMigrationTest.php tests/Feature/QuotePricingMigrationTest.php tests/Feature/SharedInventoryMigrationTest.php tests/Feature/TestOrderFinalizationMigrationTest.php tests/Feature/TestPaymentEvidenceMigrationTest.php tests/Feature/CustomerPurchaseClaimTest.php tests/Feature/CustomerPurchaseClaimMigrationTest.php tests/Feature/CustomerPurchaseClaimConcurrencyTest.php tests/Feature/TestRefundResolutionTest.php tests/Feature/TestRefundResolutionMigrationTest.php tests/Feature/TestRefundResolutionConcurrencyTest.php --filter 'ConcurrencyTest|test_.*(empty|roundtrip)|test_original_guest_purchase_is_explicitly_saved|test_full_refund_releases_only_pending_resources'
```

Build checks used `npm run build`, `python3 scripts/ci/scan-client-bundle.py`, `npm run build:preview` and `python3 scripts/ci/scan-client-bundle.py dist/design-preview`. Safeguards used `python3 scripts/ci/test-focused-tests.py`, `python3 scripts/ci/test-phpunit-shards.py` and `python3 scripts/ci/test-database-receipts.py`. Discovery used actual PHPUnit listings and the committed partition generator, plus `npx playwright test --list` for both configured projects with a disposable fixture-discovery directory.

The final integration SQLite command adds `tests/Feature/OwnedTestOrderHistoryTest.php` to the initial seven-class command. The final native command adds that same file and `OwnedTestOrderHistoryTest|test_claim_withdrawal_during_history_entry` to its existing filter. The library record contains its exact separate 59-case/28-case commands and source-bound receipts.

Component records retain the earlier actual failures and their corrections: the guest test's Inertia asset-version 409 was fixed by supplying the current protocol version without relaxing middleware or payload/privacy assertions; migration dependency ordering was repaired without weakening old assertions. A scratch-backed native datadir produced tablespace errors, while normal-durability `/tmp` runs passed; the underlying storage cause is unproven. The composed native wrapper uses a fresh process-local `/tmp` database, MySQL 8.4.11, actual PDO/process/row-lock waits and unchanged durability (`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite and binary logging enabled).

## Existing focus observations

Separate library-browsing development exposed two pre-existing frontend assertions that checked focus immediately after finding rendered content. Its first full run passed 639/640 and failed the customer credential-completion status focus; an isolated unchanged identity-file rerun passed 72/72. After repairing that observation and adding a library cleanup regression, the next full run passed 640/641 and failed the existing original-item lost-access alert focus. In both cases the correct status/alert had rendered while focus was still on the document body; the unchanged components transfer focus in a passive effect. These failures occurred in that separate development composition, not a reproduced failure of this frozen feature source, and are retained as such.

The isolated `0764b71` commit imports the existing testing-library `waitFor` helper in each file and waits for the exact original focus target using its ordinary default limit. All surrounding assertions remain. It does not change application effects, suppress errors, relax accessibility criteria or increase a test/application/workflow budget. The earlier claim/refund checkpoint independently reran its full suite on that isolated repair: 618/618 cases across 31 files passed in 14.02 seconds. The library composition subsequently passed all 641 frontend cases and is now included in final `9a9ac09`, with its reviewed frontend source preserved exactly. These are source-bound component results; full final hosted acceptance remains required.

## Complete discovery and acceptance

Actual discovery on both MySQL and SQLite found **3,744 cases in 235 files**, preserving all 3,742 identities from the first combined batch (and all 3,628 preceding PR #7 identities). Every case, file and group is assigned exactly once across eight MySQL and two SQLite partitions. The initial additions were 41 claim cases (30 domain/HTTP, eight migration, three concurrency) and 73 refund cases (43 domain, ten migration, 15 operator-resource, five concurrency). Library browsing adds exactly one history-original-preview case and one claim-withdrawal-during-history-projection case; both run on both engines.

The exact SQLite native exclusion policy is **388 expanded cases across 127 methods**. All prior 380 cases/120 methods remain; only seven new native methods/eight expanded cases are added. A complete SQLite run is expected to execute 3,356 cases, which fresh receipts must establish. Focused customer/financial/frontend/browser/seller/commerce selections contain **17 / 13 / 29 / 31 / 29 / 30 files**, within the unchanged 32-file cap. Both timing files are byte-identical to the preceding source. Discovery is not behavioral acceptance.

Before merge, require fresh full hosted execution of this final source, native Chromium/WebKit rendering, both genuine related journeys, dependency/build/client checks, ten independently verified source-bound database receipts and successful aggregation. Use the reviewed expected head for merge, then perform fresh main verification. Earlier component or PR #7 receipts cannot substitute for these gates. Six parent groups remain accepted and 34 open; this bounded batch does not complete the broader customer, financial or product requirements.
