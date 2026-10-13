# C4a source and invocation map

Root candidate: `0095e46aefa85463268027d89c829a4886537ad6`, on actual C3 merge `7b467c3824e2da81174a5445b6ee639cf0a59d93`. The integrated native reservation canary passes **1 test / 21 assertions**, zero errors, failures or skips, at this frozen source. Independent acceptance and exact-head preflight remain separate root/reviewer gates.

R4 component source is `15a65e0761382a9ad50c4b279605a644a4eeb285`; product bytes are unchanged from the terminal correction in `3424190f`. Catalog component source is `982f4ea69ed8d2191345cd8c752377da19780a89`; product/helper bytes remain those of `a58d53ef`. Component checks are not relabelled as an integrated root suite.

## Completed invocations

Counts overlap. “none” means a pure PHPUnit test with no application bootstrap or database. SQLite identifies the configured driver; the initial configuration-only selection did not run migrations or Rows bodies. The source column is the actual matched component/capsule or explicit comparison reference, not necessarily the original precommit HEAD label.

| Invocation | Driver | Tests / assertions | Errors | Failures | Skips | Exit | Source binding |
| --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| [r4/configuration](evidence/r4/configuration.xml) | sqlite | 22 / 56 | 0 | 0 | 0 | 0 | a4e37cdc |
| [r4/staging-configuration](evidence/r4/staging-configuration.xml) | sqlite | 1 / 4 | 0 | 0 | 0 | 0 | a4e37cdc |
| [r4/frame-initial](evidence/r4/frame-initial.xml) | sqlite | 12 / 51 | 1 | 0 | 1 | 2 | 3424190f |
| [r4/sqlite-final](evidence/r4/sqlite-final.xml) | sqlite | 50 / 322 | 0 | 0 | 1 | 0 | 15a65e07 |
| [r4/baseline-fail](evidence/r4/baseline/fail-0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371/phpunit.xml) | none | 1 / 10 | 0 | 1 | 0 | 1 | 0d2f06c7 |
| [r4/baseline-pass](evidence/r4/baseline/pass-a4e37cdc222ccea13ae65fb983c6d5fd89a25e29/phpunit.xml) | none | 1 / 10 | 0 | 0 | 0 | 0 | a4e37cdc |
| [catalog/pure-initial](evidence/catalog/pure-initial.xml) | none | 60 / 70 | 0 | 0 | 0 | 0 | a58d53ef (historical partial) |
| [catalog/pure-quote-boundary](evidence/catalog/pure-quote-boundary.xml) | none | 61 / 71 | 0 | 0 | 0 | 0 | a58d53ef (historical partial) |
| [catalog/pure-engine-boundary-red](evidence/catalog/pure-engine-boundary-red.xml) | none | 62 / 72 | 0 | 1 | 0 | 1 | a58d53ef + retained draft capsule |
| [catalog/pure-engine-boundary-green](evidence/catalog/pure-engine-boundary-green.xml) | none | 62 / 71 | 1 | 0 | 0 | 2 | a58d53ef + retained draft capsule |
| [catalog/pure-final](evidence/catalog/pure-final.xml) | none | 63 / 73 | 0 | 0 | 0 | 0 | a58d53ef |
| [catalog/native-old-source-red](evidence/catalog/native-old-source-red.xml) | mysql | 1 / 4 | 1 | 0 | 0 | 2 | 822a8481 + old 0d Evidence class |
| [r4/native-four](evidence/r4/native-four.xml) | mysql | 4 / 14 | 0 | 0 | 0 | 0 | 15a65e07 |
| [catalog/native-old-source-red-v2](evidence/catalog/native-old-source-red-v2.xml) | mysql | 1 / 12 | 1 | 0 | 0 | 2 | 982f4ea6 + old 0d Evidence class |
| [catalog/native-fixed-five](evidence/catalog/native-fixed-five.xml) | mysql | 5 / 46 | 0 | 0 | 0 | 0 | 982f4ea6 |
| [catalog/sqlite-eligibility-whole](evidence/catalog/sqlite-eligibility-whole.xml) | sqlite | 16 / 123 | 0 | 0 | 0 | 0 | 982f4ea6 |
| [root/native-reservation-integration](evidence/root/native-reservation-integration.xml) | mysql | 1 / 21 | 0 | 0 | 0 | 0 | 0095e46a; 1,275 captured inputs exact |

Exact argv, XML, logs, exits and captured source are named in [runs.json](evidence/runs.json). [JUnit summary](evidence/junit-summary.json) independently reconciles each leaf with suite totals and expected counts/exits.

## Source verification limits

The archive contains 23 source comparisons: 21 match every selected input against actual Git blobs or explicitly retained draft capsules; two early catalog parser inventories only partially match the later helper reference. The initial and quote-boundary parser runs each differ in `MembershipCatalogDefinition.php` and `MembershipCatalogDefinitionTest.php`. Their old hashes remain retained, with no reconstruction of unarchived draft bytes.

Compact projections include every captured application, bootstrap, configuration, migration, public/resource, routes, scripts, tests and workflow input plus named root dependency/test configuration files. R4's original 10,632/10,633-file inventories yield 2,327/2,328 selected inputs; all omitted documentation hashes remain available in compressed originals. Catalog native/SQLite maps contain 1,961 captured inputs and match the named component source. Root quality maps contain 2,287 paths, of which 2,284 are selected inputs; discovery contains 2,115 captured inputs, all matched. This is explicitly a selected-input Git comparison, not an all-tracked-file claim.

The original baseline and pure parser runs have independently comparable before/after maps. R4 final SQLite/native and catalog native/SQLite stability use the retained owner sidecars where no separate raw after-map exists. Those methods stay distinct in [stability-and-programs.json](evidence/stability-and-programs.json). Baseline probe/runner, pure/native/SQLite runners and the actual old Evidence class bytes are independently hash-checked. Both component release manifests are reconciled: R4 63 members and catalog 109 members.

The R4 configuration source was captured with a precommit `0d2f06c7` HEAD label; all five captured hashes match the later `a4e37cdc` commit. It is an exact precommit overlay binding, not execution of clean old source. Its external old/fixed baseline remains a separate probe with identical program bytes. Final preparation coverage independently contains the same configured-driver refusal after the later terminal-container correction.

## Original failures and corrections

- R4 baseline: original `0d2f06c7` admits verified 258 with rehearsal 257 on SQLite and invokes all three fixture factories. The desired-refusal assertion fails, 1 / 10. At `a4e37cdc`, the same pure probe passes 1 / 10, refusing `provenance_driver` before any factory. No application bootstrap or database runs in this probe.
- R4's first frame invocation at `3424190f` has 12 / 51, one MySQL-only skip and one teardown error. A test factory retained a Rows pin, so SQLite VACUUM refused after assertions. Test-only cleanup at `15a65e07` produces the passing whole selection 50 / 322 with one native-only skip. The selected native four then pass 4 / 14 at the same source.
- Catalog parser engine-boundary red has 62 / 72 and one intended assertion failure. The next file is named “green” but actually has 62 / 71, one error and exit 2: tightening the helper exposed a wrongly positive ambiguous fixture. Exact helper/test capsules are retained for both. Final parser coverage passes 63 / 73.
- Initial catalog native “red” has 1 / 4, one MySQL 1205 error, exit 2, before counter mutation/reproof. This is a setup error, not the intended counter regression.
- Corrected old-source native comparison has 1 / 12 and one uncaught `changed_catalog_source` error after a real independent committed counter change from 2 to 3. It is the intended regression, but its JUnit status remains error, not assertion failure. Fixed native source then passes 5 / 46 with the same 1,961 captured candidate hashes and the old-class preload removed.
- Initial Pint failures are retained: R4 formatting in two test files and catalog single-quote formatting in its unit file. R4 Pint results have JSON outcome receipts without standalone exit files; no missing exit file is invented.
- Root's initial shard-policy self-test ran 52 tests with one failure because the expanded `MemberOriginalPreparationTest` was neither included nor residual. Adding the explicit include yields all 154 policy tests passing. The other original policy suites and Pint already passed.

The early staging method was mistakenly selected as configuration-only, but its class inherits migrations. Its 1 / 4 pass is forced SQLite `:memory:` evidence, with a reported 2.931-second overlap during C3 native work, no native connection and no shared database state. It is not counted as pure policy evidence. The separate quote-ambiguity diagnostic has no standalone retained command; its author records the command in the tool transcript, and the diagnostic is not counted as a test.

## Native catalog comparison

Corrected red uses candidate `982f4ea6` and preloads exactly the original `0d2f06c7` Evidence class. Primary connection 224 and independent connection 225 produce counter 2→3; raw SHOW bytes differ only in that table option, and the executed test asserts unchanged selected plans. Original-snapshot postcommit reproof refuses `changed_catalog_source`.

Fixed native source uses connections 226 and 227 and the same counter/selected-plan facts. It retains the original committed snapshot, then separately refuses selected-row drift in a fresh current frame, actual table COMMENT drift and an identical-row temporary shadow. Two existing private-byte/deadline and committed-snapshot cases also pass. This is postcommit snapshot currentness, not an unrelated insertion while the original locking transaction remains open. It is not a simultaneous two-writer award race.

## Skips and local check source boundaries

The two SQLite frame invocations each skip `MemberGrantSourceAdmissionTest::test_native_verified_member_provenance_requires_and_binds_actual_mysql_despite_rehearsal_membership`. JUnit has no skip message. That identity passes in the separate native four-case selection; the SQLite invocations remain skipped. Eight other frame methods were not run natively in this batch.

Initial root quality at `cc612650` retains shard 52/one failure, policy 61/27/14 passes and Pint over eight changed PHP files. Corrected policy source `ca0fe30b` passes 52/61/27/14 (154). Body-free discovery on that source verifies 2,103 selected cases in 202 files from 8,245 cases in 602 files, across 24 complete disjoint shards. The configured endpoint was deliberately unreachable `127.0.0.1:1`.

The exact later `ca0fe30b`→`0095e46a` diff changes only comments. Eight Pint files remain byte-identical from their executed source to the candidate. This is explicit carry, not a rerun. The 39 untimed files use historical fallback weights, not newly measured performance. Root's cleanup receipt accounts for 26 archived listings/manifest and 24 intentionally omitted generated configurations.


## Frozen-source comparison

[Final-source verification](evidence/final-source-verification.json) compares every selected captured input in the successful coverage precedence with actual `0095e46a` Git blobs. The original full public inventories remain available; this is not a claim about unlisted inputs or a rerun of earlier component cases.

| Invocation | Selected captured inputs | Differences from frozen root |
| --- | ---: | --- |
| `r4/sqlite-final` | 2328 | 5 tooling/config/resource, 1 application |
| `catalog/pure-final` | 5 | All selected inputs match |
| `r4/native-four` | 2328 | 5 tooling/config/resource, 1 application |
| `catalog/native-fixed-five` | 1961 | 1 application, 2 test or fixture |
| `catalog/sqlite-eligibility-whole` | 1961 | 1 application, 2 test or fixture |
| `root/native-reservation-integration` | 1275 | All selected inputs match |

R4 receipts precede the catalog Evidence change and five workflow/selection-policy changes. Catalog receipts precede the MemberGrantPolicy change and the two affected preparation/staging test changes. Their selected functional source carries are therefore limited exactly as recorded, not silently promoted into final-root runs. The parser's five captured inputs match final source. Root's reservation canary is the actual integrated execution: all 1,275 captured hashes match frozen Git source, the owner reports unchanged disk source, and the retained runner bytes verify independently.

The native canary uses real billing, policy, catalog, period and reservation sources to reserve once, return the exact retry and preserve original award replay. It reports 1 / 21, exit 0, wall 279.991 seconds and JUnit 279.744427 seconds, on private MySQL 8.4.11. This is focused integrated evidence, not a full application suite or an operative 258 grant/consume claim.
