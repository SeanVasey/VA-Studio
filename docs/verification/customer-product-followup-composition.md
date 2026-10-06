# Collection refresh and original customer order details

October 6, 2026 UTC. This bounded candidate follows accepted main `387fdeb95e72e96e5cd17c0b54f38c849fc37adb`, tree `63b832dd0d40eab70654e8e0a6aaea53bdd4a73c`. Its complete prior PR acceptance and fresh [main run 37425292250](https://github.com/SeanVasey/VA-Studio/actions/runs/37425292250) are separate from this candidate's required acceptance.

## Product behavior

Staff can compare retained collection/album members with their current source metadata and publication revision, explicitly review the difference, and refresh the retained snapshots into a new immutable draft version. Fresh actor/MFA, draft and member-source fences reject stale review or withdrawn authority. An unchanged selection remains a no-op; changed replay cannot duplicate a version or audit. Pricing, licensing, public commerce and fulfillment are unchanged. [Feature evidence](product-member-refresh.md) records the component checks.

Customers can choose **View original test-order items** from the existing order history or reference lookup. The owner-checked read projects the original item metadata, license and prepared price evidence, with bounded payloads and private stale-response handling. It does not recalculate the purchase, issue access or initiate a download. [Feature evidence](customer-order-items.md) preserves component checks and their actual limitations.

## Source composition

| Source | Published identity | Verified tree |
| --- | --- | --- |
| Accepted main | `387fdeb95e72e96e5cd17c0b54f38c849fc37adb` | `63b832dd0d40eab70654e8e0a6aaea53bdd4a73c` |
| Collection refresh | `14b1fdc24b36b964808a9ac49d9a04d499329401` | `96220addedd64a2afc46ceb96c9b1641ef7c0070` |
| Original order items | `ec4766b365a9fdbd46dce7a5ff90aea2b732792c` | `fd7436f989ce509b3d6d2f03b20230ad100c8d33` |

All 1,252 main blobs and their modes were restored and verified against the published tree before composition. Local restoration commits are source mappings, not the published commit identities. Publication preserves the actual ordered main/feature parents through that mapping.

The application/test composition before this documentation checkpoint is local `eec22b7cc1c7ece243398dec28c65c5dc24ba1f5`, tree `e7f52a6c54ed6e52f02c43c4f28d9fdad8d76122`. Independent review verified the exact 23-file union: all 21 nonshared files match their reviewed feature blobs, and the two shared focused-selector files preserve both additions and every preceding safeguard. The unchanged cap remains 32. No application behavior, assertion, budget, timing weight or workflow was changed during integration.

## Executed checks

Checks below apply to that composed application source. Subsequent changes in this checkpoint are documentation only.

| Check | Actual outcome |
| --- | --- |
| `npm test` | 601 passed in 30 files |
| `npx tsc --noEmit` | Passed |
| `npm run build` and `npm run build:preview` | Both passed in a separate worktree at the identical application/test commit; production reports the unchanged advisory 501.11 kB chunk warning |
| `python3 scripts/ci/scan-client-bundle.py` for each build | Both passed, no secret-name or Stripe key-prefix hits |
| Playwright `--list` for both configured projects | 116 cases discovered in 32 files; listing only, no rendering |
| Four feature classes on SQLite | 58 reported, 49 executed, 1,049 assertions; exactly nine registered native-only skips; no errors/failures |
| Same four classes on native MySQL 8.4.11 | Initial run: 42 passed, 16 setup errors, 591 assertions. Database resets reported MySQL 1813 tablespace errors; investigation/reproduction pending. This run is not accepted. |
| `python3 scripts/ci/test-focused-tests.py` | 38 safeguards passed |
| `python3 scripts/ci/test-phpunit-shards.py` | 36 safeguards passed |
| `python3 scripts/ci/test-database-receipts.py` | 34 safeguards passed |

The four feature classes are `ProductMemberRefreshTest`, `ProductMemberRefreshEditorTest`, `ProductMemberRefreshConcurrencyTest` and `CustomerOrderItemsTest`. The local MySQL wrapper provisions a fresh disposable database, uses real MySQL/PDO and normal durability (`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite and binary logging enabled). Source assertions, migrations and durability are not relaxed to obtain a local pass.

## Complete discovery and acceptance boundary

Independent actual PHPUnit discovery on both engines found **3,628 unique cases in 228 files**, preserving all 3,570 preceding identities. Every case, file and group is assigned exactly once across eight MySQL and two SQLite partitions. The committed SQLite policy contains **380 expanded native-only cases across 120 exact methods**: the preceding 371/117 plus the nine refresh race cases in three methods. Full SQLite execution is expected to run 3,248 cases; actual hosted receipts must establish it.

Focused customer/seller/frontend/browser selections are **14 / 29 / 28 / 28 files**, below the unchanged 32-file limit. Both timing files are byte-identical to main; five untimed files use the existing fallback. Discovery proves selection and identity, not successful behavior execution.

Full hosted execution, native Chromium/WebKit rendering, both genuine related journeys, dependency/build/client checks and all ten source-bound database receipts remain required for this candidate. The local environment has no native browser executables or ClamAV, so no native browser acceptance is claimed here. Final published SHA/tree, run IDs and terminal results belong on the integrating PR, avoiding status-only source commits that restart acceptance. Merge requires the expected reviewed head and is followed by fresh main verification.

Guest-order account claims and fully refunded paid-exception resolution are separately owned next children and are absent from this candidate. No parent group closes from these two bounded features: six remain accepted and 34 open. Production identity/email, public product commerce, live financial mutations, imports and cutover retain their existing gates.
