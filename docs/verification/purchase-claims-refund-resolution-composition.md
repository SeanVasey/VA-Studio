# Guest purchase claims and refunded test-exception resolution

October 6, 2026 UTC. This candidate follows the collection refresh and original order-detail source in [PR #7](https://github.com/SeanVasey/VA-Studio/pull/7). At this checkpoint, that preceding PR has successful frontend, quality, both ordinary browser jobs, genuine related journeys and two independently verified SQLite receipts; its eight native MySQL receipts and aggregate acceptance remain pending. Its results do not accept this changed source. Terminal results and published commit mappings belong on the respective PRs.

## Completed feature boundaries

The [guest purchase claim](guest-purchase-claim.md) lets the original guest browser explicitly select one completed paid test purchase before sign-in, then confirm saving it to that account. A bounded server-session challenge and immutable per-order claim support later history, reference lookup, original item details and original hash-checked downloads. The flow never infers ownership from email, rewrites the original owner, links sibling orders or broadens quote/checkout access. Current account, credential, policy and entitlement checks continue to govern access.

The [refunded exception workflow](../test-refunded-exception-resolution.md) lets authorized staff review an unfulfilled test paid exception and release only its original still-pending inventory/promotion resources after fresh, complete successful-refund observations. It preserves the original payment, purchased snapshots and rights evidence. Original-request and observation deadlines are checked after resource locks and again after audit; an exact completed retry confirms the retained result without new provider reads. It performs no refund or other provider mutation, does not release consumed resources, and does not relist a completed exclusive sale.

Both features default off and are restricted to local/testing. Production identity/email, lost-browser recovery, provider interoperability, the wider refund/dispute/access policy and live commerce remain separate work.

## Source and independent review

| Component | Local source identity | Exact tree |
| --- | --- | --- |
| Preceding PR #7 source | `7067a95bbead0354d945994cbdc6a27a98ba101d` maps to published `f959635452f62e23617eadb83cabe0c984309a0b` | `b3919e551405dfb9103686c1f8e8518afad86327` |
| Guest purchase claim | `fb4a93e441e3a795d1333fab274aceeeb242e218` | `486e5fc9953e3e13582765504d43c0827486c850` |
| Refunded exception resolution | `820440b85cc539ac30ed30abf88fd948a61afcb2` | `8d55da482efc3821eb4db05ed836bca2dcd9aaef` |
| Integrated application/tests before this ledger update | `80aa34faa0022d0cba44f8585c8b19181324acf8` | `7f74795edc5b9c400f68e94c0e59423e1bdeebef` |

Local restored identities are not published Git identities. Publication verifies exact trees and preserves mapped ordered parents, including both independently reviewed feature branches and the preceding PR ancestry.

Independent composition review verified the exact 70-path union relative to the earlier application composition `eec22b7`: 33 refund paths, 37 claim paths, four preceding documentation paths and four integration registry paths with overlaps. All 62 nonshared blobs exactly match their approved owners. The ordinary browser runner is exactly the approved refund version plus the explicit claim test flag; its ordinary refund flags remain false. The only seven manual merge resolutions are legacy migration roundtrips. Each removes claim migration 000048 before refund migration 000047 and restores them in the opposite order. Independent token comparison confirms that stripping only these additive child steps leaves every original code token and assertion unchanged. Existing parent migration assertions, selection logic, file cap, workflow gates, timing weights, test time limits and durability remain intact.

## Composed verification

The checks below apply to the integrated application/test source above. This checkpoint's subsequent edits are documentation only.

| Check | Actual outcome |
| --- | --- |
| `npm test` | 618 tests passed in 31 files |
| `npx tsc --noEmit` | Passed |
| Production/preview builds and both client scans | Both builds passed in a separate detached worktree; production scan covered six text files and preview four, with no secret-name/Stripe-prefix hits. Existing production chunk advisory: 505.07 kB. |
| Both-project Playwright discovery | 120 cases in 34 files, 60 per configured browser, including both new journeys in both projects; discovery only, no rendering |
| All seven new PHP classes on SQLite | 114 reported, 106 executed, 849 assertions, exactly eight registered native-only skips; no errors/failures; 198.930 seconds |
| Native MySQL composition selection | 26 passed, 567 assertions, no errors/failures/skips; 199.170 seconds. Includes all eight real concurrency cases, affected legacy parent/child empty migration roundtrips, original guest cross-session access and both full-refund resource shapes. |
| Focused-selector safeguards | 40 passed |
| Partition safeguards | 36 passed |
| Database-receipt safeguards | 34 passed |

The exact SQLite selection was:

```sh
php vendor/bin/phpunit tests/Feature/CustomerPurchaseClaimTest.php tests/Feature/CustomerPurchaseClaimMigrationTest.php tests/Feature/CustomerPurchaseClaimConcurrencyTest.php tests/Feature/TestRefundResolutionTest.php tests/Feature/TestRefundResolutionMigrationTest.php tests/Feature/TestRefundResolutionResourceTest.php tests/Feature/TestRefundResolutionConcurrencyTest.php
```

The native selection used the same locked dependencies on a fresh disposable MySQL database:

```sh
php vendor/bin/phpunit tests/Feature/CustomerAccountMigrationTest.php tests/Feature/HostedCheckoutMigrationTest.php tests/Feature/OrderPreparationMigrationTest.php tests/Feature/PromotionMigrationTest.php tests/Feature/QuotePricingMigrationTest.php tests/Feature/SharedInventoryMigrationTest.php tests/Feature/TestOrderFinalizationMigrationTest.php tests/Feature/TestPaymentEvidenceMigrationTest.php tests/Feature/CustomerPurchaseClaimTest.php tests/Feature/CustomerPurchaseClaimMigrationTest.php tests/Feature/CustomerPurchaseClaimConcurrencyTest.php tests/Feature/TestRefundResolutionTest.php tests/Feature/TestRefundResolutionMigrationTest.php tests/Feature/TestRefundResolutionConcurrencyTest.php --filter 'ConcurrencyTest|test_.*(empty|roundtrip)|test_original_guest_purchase_is_explicitly_saved|test_full_refund_releases_only_pending_resources'
```

Build checks used `npm run build`, `python3 scripts/ci/scan-client-bundle.py`, `npm run build:preview` and `python3 scripts/ci/scan-client-bundle.py dist/design-preview`. Safeguards used `python3 scripts/ci/test-focused-tests.py`, `python3 scripts/ci/test-phpunit-shards.py` and `python3 scripts/ci/test-database-receipts.py`. Discovery used actual PHPUnit listings and the committed partition generator, plus `npx playwright test --list` for both configured projects with a disposable fixture-discovery directory.

Component records retain the earlier actual failures and their corrections: the guest test's Inertia asset-version 409 was fixed by supplying the current protocol version without relaxing middleware or payload/privacy assertions; migration dependency ordering was repaired without weakening old assertions. A scratch-backed native datadir produced tablespace errors, while normal-durability `/tmp` runs passed; the underlying storage cause is unproven. The composed native wrapper uses a fresh process-local `/tmp` database, MySQL 8.4.11, actual PDO/process/row-lock waits and unchanged durability (`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite and binary logging enabled).

## Complete discovery and acceptance

Actual discovery on both MySQL and SQLite found **3,742 cases in 235 files**, preserving all 3,628 preceding identities. Every case, file and group is assigned exactly once across eight MySQL and two SQLite partitions. The additions are 41 claim cases (30 domain/HTTP, eight migration, three concurrency) and 73 refund cases (43 domain, ten migration, 15 operator-resource, five concurrency).

The exact SQLite native exclusion policy is **388 expanded cases across 127 methods**. All prior 380 cases/120 methods remain; only seven new native methods/eight expanded cases are added. A complete SQLite run is expected to execute 3,354 cases, which fresh receipts must establish. Focused customer/financial/frontend/browser/seller/commerce selections contain **17 / 13 / 29 / 30 / 29 / 30 files**, within the unchanged 32-file cap. Both timing files are byte-identical to the preceding source. Discovery is not behavioral acceptance.

Before merge, require fresh full hosted execution of this final source, native Chromium/WebKit rendering, both genuine related journeys, dependency/build/client checks, ten independently verified source-bound database receipts and successful aggregation. Use the reviewed expected head for merge, then perform fresh main verification. Earlier component or PR #7 receipts cannot substitute for these gates. Six parent groups remain accepted and 34 open; this bounded batch does not complete the broader customer, financial or product requirements.
