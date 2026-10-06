# CI throughput timing refresh

## October 6, 2026: eight-shard GitHub candidate

This separate follow-up starts after `ab3567c449ea9efe171cd2c7f0953411d04ddc3f` and expands GitHub MySQL from four to eight whole-file shards. SQLite remains two shards. All application cases, reviewed skips, test commands, deadlines, browser/quality jobs and the stable `backend` gate remain required; the strict aggregate now collects ten current-run database receipts. GitLab retains its independent four-plus-two policy. No receipt reuse is enabled. Independent review and final product discovery completed while the first PR #6 run required a dependency/browser correction, so this finished optimization joins that same necessary correction.

Using the unchanged partitioner and exactly the same accepted-main measurements below gives this offline comparison:

| MySQL assignment | Longest measured-weight estimate | Total rounded measured work | Expanded cases per shard |
| --- | ---: | ---: | --- |
| Four shards | 2,492.621 seconds | 9,970.424 seconds | 727 / 719 / 815 / 781 |
| Eight shards | 1,246.341 seconds | 9,970.424 seconds | 345 / 455 / 371 / 320 / 371 / 446 / 303 / 431 |

The estimated critical-path reduction is 1,246.280 seconds (20m46.280s), before provisioning, queueing and aggregation. This is a redistribution of recorded work, not an executed eight-shard speedup. It does not incorporate fabricated timings for later product features or the local payment cleanup change. Final-source discovery and the next ordinary hosted run remain authoritative.

The accepted run's four MySQL jobs spent 80 / 85 / 81 / 72 seconds outside their test steps. At that observed mean, four extra runners add approximately 318 runner-seconds (5m18s), about 3.1% to the modeled database runner-time total. The first ordinary run must measure actual overhead rather than treating this projection as guaranteed cost.

Accepted run `37098953844` and current PR #5 run `37397306676` both actually started eleven jobs concurrently; PR #5 reached eleven at 01:04:47 UTC on October 6. Eight MySQL plus two SQLite jobs require ten database slots. Total initial fanout with frontend, backend quality and three browser jobs rises to fifteen. Fifteen simultaneous slots have not been observed, and account capacity or competing work may cause queueing. No optional timing-only hosted run is introduced.

Before changing the MySQL display names from `/4` to `/8`, successful read-only GitHub branch metadata explicitly reported `protected: false`, protection disabled, and empty required check contexts/checks; the repository ruleset listing including parents returned an empty list. The separate administrative protection endpoint returned 403 and was not used to infer policy. No setting or required check was removed. The stable source-defined `backend` gate remains mandatory.

The fixed provider counts are enforced at every producer and collector call. Negative cases cover incomplete four-shard evidence, missing or duplicate late shards, old job denominators, mixed manifest cardinality, and unknown shard nine, alongside every existing provenance, runtime, skip, parser and no-reuse safeguard. Local verification passed 170 safeguards: 34 GitHub receipt, 24 GitLab receipt, 36 partition/timing, 24 scope, 28 focused, 12 GitLab setup, seven GitLab writer-feedback and five PHP-runtime tests. PHP 8.4.26 discovery independently proved all 3,042 cases in 185 files and every group exactly once across eight MySQL and two SQLite configurations; case/group digests remain those of the recorded source below. Generated discovery evidence totals 2,455,843 bytes for MySQL and 2,307,480 bytes for SQLite; the largest individual XML is 1,134,967 bytes, within the unchanged file/XML limits. Actual hosted JUnit, ten receipts and ZIP/collection bounds remain required from the next full run. Python compilation and whitespace checks pass. Independent source review is approved; fresh ten-receipt hosted acceptance is still pending.

## October 6, 2026: measured payment-fixture teardown

This follow-up starts from the timing-only commit `7735ccbcf175e6ba0c4c2b858ca5b72e10fb63f4`. It changes only the import and trait used by `TestPaymentProcessingTest`: the existing `FinalizationDatabaseMigrations` replaces Laravel's `DatabaseMigrations`. Every test body and data provider is byte-identical. Application code, migration code, test selection, deadlines, warnings and acceptance gates remain unchanged.

The existing fixture trait still calls `migrate:fresh` before every case, preserves Laravel's before/after refresh hooks, and adds no wrapping transaction or cross-case data reuse. Its checked `db:wipe` removes the disposable schema at teardown instead of invoking operational migration rollback. The trait preserves the selected connection and view/type cleanup options, clears the Artisan instance and resets refresh state. Payment tests retain real root commits, after-commit work and provider calls outside database transactions. Dedicated migration rollback tests remain in full CI.

### Native before/after evidence

Both complete executions used the exact locked dependencies, PHP 8.4.26 and separate fresh MySQL 8.4.11 instances on the native `/tmp` filesystem. Both reported repeatable-read isolation, `innodb_flush_log_at_trx_commit=1` and enabled performance schema. The MySQL binary, PHP binary/configuration, disposable runner and measurement helper were unchanged between runs. Synthetic credentials and application keys were isolated to each disposable instance.

| Measurement | PHP test-step elapsed | Expanded cases | Assertions | Errors / failures / skips |
| --- | ---: | ---: | ---: | ---: |
| Original `DatabaseMigrations` | 430.009 seconds | 75 | 520 | 0 / 0 / 0 |
| Existing disposable cleanup trait | 368.288 seconds | 75 | 520 | 0 / 0 / 0 |

The observed reduction is **61.722 seconds (14.35%) for this file**. Every expanded case identity and its assertion count agree across the pair. These are monotonic PHPUnit subprocess durations; server provisioning is excluded and was not separately timed. This single sequential pair shared its host with other development work. It establishes the recorded local outcome, not a guaranteed hosted speedup or final candidate acceptance. Earlier attempts that failed environment prerequisites were excluded entirely: trigger-creation privilege, a synthetic application key, and a temporary filesystem capable of repeated native MySQL DDL were required before the valid pair.

The retained case-identity digest is `bcaf9fa7d47b10e6ee92910f39f9e3a3aaadb0f17aba9b300c7a87b5ef0737e1`. Original/candidate test-file SHA-256 values are `de5ec2f4ad01c36c269db4393caf53e5879572530fd5604d5e9327bbf6e84bca` and `f634f20fb827d630912ec2c9a3db6316ffa40b00c149f824755aa157ee77c455`; original/candidate JUnit SHA-256 values are `95f693e0c2a545c3e1195f858cce1a1bcecbd27aa5a6803851c2c3d2a9103793` and `dd4ec4494ddf18bb89d9c3a056059420e255d4b75164b31a3921d83b82c86f9d`.

Reproduce the comparison on each exact test-file version with separately provisioned disposable databases and the same runtime, lockfile and settings:

```bash
php vendor/bin/phpunit tests/Feature/TestPaymentProcessingTest.php \
  --log-junit=/tmp/payment-measurement.xml --fail-on-phpunit-warning \
  --display-warnings --stop-on-error --stop-on-failure
php vendor/bin/phpunit tests/Feature/FinalizationDatabaseLifecycleTest.php \
  --fail-on-phpunit-warning --display-warnings
```

The initial pair's helper SHA-256 is `f5f84bec6013bd5f42234c936b133978bb2abf7a4a76edc51f8021a363eeab1b`; disposable runner SHA-256 is `a38352c6ab7dee73d738fbeb3579723da6a55463f73986f8a33116e3776438e6`. The helper verified the source file and native runtime before executing, used separate new JUnit paths, and retained the complete result and exit status. Independent comparison required all 75 identities, all 520 per-case assertions, no errors/failures/skips, successful exit and unchanged runtime files.

Additional validation passed: candidate payment plus the four existing lifecycle datasets on SQLite, **79 tests / 590 assertions**, 90.115 seconds; the existing lifecycle datasets on native MySQL, **4 tests / 70 assertions**, 24.159 seconds. Both had zero errors, failures or skips and retained the PHPUnit-warning exit flag. The lifecycle cases verify successful and deliberately throwing child tests, selected connections, view cleanup policy, callbacks after cleanup and fresh next-test schema state. The accepted-main timing manifests below remain original hosted measurements; this local result is not mixed into their weights. The next composed source requires its own complete Foundation gates and the committed provider policy's current-run receipts. No receipt reuse is activated.

## October 6, 2026: complete accepted-main timing refresh

This bounded T02 candidate starts from `636bc94b5688267edef1d34d8d1726402606da32`, tree `15de3311f7ba345b1e6821aa46e341f0e9d934c1`, while the separate private-alpha/storefront PR #5 runs its own acceptance. It changes only the two timing manifests and this guide. It does not change tests, PHPUnit configuration, partitioning, shard counts, workflows, runtime settings, warnings, audits, deadlines, receipt validation or the full-only reuse policy.

The measurement source is [accepted main Foundation run 37098953844](https://github.com/SeanVasey/VA-Studio/actions/runs/37098953844), attempt 1, ordinary push of `1095dd5f8fd7d016bc08a55dfe2d264557dfce67`, tree `69c87c3c8924af6423de73ec21524c94cc051b12`. All 13 applicable jobs and the strict six-receipt collector passed. Each engine discovered **185 files / 3,042 expanded cases**. MySQL executed all 3,042 with 55,396 assertions and no failures, errors or skips; SQLite executed 2,738 with 31,247 assertions and the 304 existing MySQL-only skips. The candidate has no differences from that source in application PHP, migrations, Feature/Unit/Support tests, Composer lock/configuration, PHPUnit configuration or either timing/partition algorithm.

### Measured bottleneck and bounded change

The four MySQL test steps took **2,178 / 2,719 / 2,498 / 2,577 seconds**. The slowest whole job took 2,804 seconds; only 85 seconds were outside its test step. Installation and startup are therefore not the main delay in this observation. The previous timing weights omit 38 current files and mix older source measurements. This refresh replaces them with exactly one current accepted sample for each of the 185 files, separately for each engine.

Both placements below sum the same per-file timing weights in seconds, with each file rounded to milliseconds by the existing generator. Refreshed figures are offline estimates, not measured execution of a changed assignment. Runner variability, execution order and fixture behavior can change actual durations.

| Engine | Original placement, rounded file seconds | Refreshed placement, same rounded file seconds | Refreshed cases per shard | Estimated reduction of longest shard |
| --- | --- | --- | --- | --- |
| MySQL | 2,177.980 / 2,718.717 / 2,497.894 / 2,575.833 | 2,492.600 / 2,492.603 / 2,492.600 / 2,492.621 | 727 / 719 / 815 / 781 | 226.096 seconds (8.3%) |
| SQLite | 841.090 / 881.397 | 861.243 / 861.244 | 1,398 / 1,644 | 20.153 seconds (2.3%) |

This removes the observed avoidable shard imbalance, but does not eliminate the underlying test workload. The heaviest MySQL file is `TestPaymentProcessingTest.php`: 75 expanded cases and 478.407 seconds. Its per-case schema lifecycle is a separate optimization candidate requiring native before/after proof. Replacing root-commit tests with transaction-wrapped fixtures is not part of this change. Post-merge reuse also remains a separate provenance/runtime design and requires explicit verification before activation.

### Evidence and reproduction

All six downloaded ZIPs matched their GitHub API size and SHA-256 digest. Each receipt names the exact source/run/attempt above; every retained file hash was recomputed, and engine manifests agree. No missing or later test file was silently dropped by the unchanged generator.

| Artifact | ID | Verified ZIP SHA-256 |
| --- | --- | --- |
| MySQL 1 | 11266415925 | `60175de9cfb21b8e521c81310b2eee26764bd9e7d700f40f4dbb1aa4fa72575e` |
| MySQL 2 | 11265967234 | `e4c842810b442b4f9bccd2005215ca4a4011468ff1563ce657e1e9caf57ced87` |
| MySQL 3 | 11266815760 | `72a7a44bc59fa18fcd1859469f8a4f97d9469a1ff73136de29cb68fa084a0bb1` |
| MySQL 4 | 11266980474 | `10da35ec261589d9ca20b7b369b465a21c30960019f518df763b765db6fee565` |
| SQLite 1 | 11265268735 | `a456ce4dc63559590eba9635a4392cbe24ba20f35f93ab4f921c51cd3377ca23` |
| SQLite 2 | 11265762815 | `ef0d4f33e62bc242e791d988f05040eb67aa331640eda74ce90555ba5ffeb0ae` |

Download these backend artifacts, then run the existing `scripts/ci/phpunit-timings.py` once per engine with `--driver`, the committed manifest's exact `source` string, `--output`, and that engine's four or two `phpunit-ci-*-results.xml` paths. Repeating generation produced byte-identical outputs: MySQL SHA-256 `79ba2e6e07cc1c02dcec8d42aa05a454c0c7c65f008cbf1def78520e27f47334`; SQLite `f4006db450f68309c63feb59653c40f719fe9c39c09f46838640bf8342cb41d9`.

All 35 existing partition/timing safeguards pass. Fresh PHP 8.4.26 discovery on the candidate, using the exact locked dependencies, proves all 3,042 cases in 185 whole files and every group exactly once across both four-shard and two-shard configurations. There are no untimed files. The source case digest remains `034f0a9218c82042da6cde1509710da31eb6283bdabf6ed4066af9e8a0d6429d`; the group digest remains `851d7d3192a4c2772c4b92ac6b9d7802d018adbd66ae3a8a218714523e28c829`.

This preparation does not report fresh application/MySQL execution, a demonstrated hosted speedup, reusable prior acceptance, or completion of T01/T02. Independent source review and the next composed candidate's complete Foundation gates remain required. The active PR #5 run is unchanged and continues independently.

## Historical integration boundary — October 1, 2026

The database-only T02 weighting candidate described below was prepared separately from active PR #89. Its two reviewed timing manifests are now composed, unchanged, with the inquiry/related-image migration recovery into checkpoint `b41f8f58845f25e686f8f11ca70b147a362af9ec`, tree `3b5aa741c0c9a1407514390ca7d740488704e814`. The historical measurement remains **146 files / 2,215 cases from run `36830305836`**, whose six database jobs passed but overall browser gates failed. Those measurements and offline redistribution estimates are not rewritten as current-source results or a demonstrated speedup.

The separate old-source [Foundation run `36835120458`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835120458) has now completed successfully: head `bb41dbd5b6fba22f4f213b93e041ba322276f14b`, actual merge `33cc70475a4bdb610a6aa04700f3cdb674e4e209`, tree `8ceb8393d66b0ea4c2c2f46e429b0106ac4f5402`. Its independent [six-receipt/collector proof](ci-database-receipts.md) establishes 146 files / 2,218 actual cases and complete runtime gates for that old source, which did not contain this timing refresh. Independent P2 migration review still prevents merging that old head. The newly composed recovery/weighting source needs its own full Foundation/receipt acceptance; neither an old green run nor historical timing samples provide it. No reuse, gate reduction, parent-task completion or controlled runtime improvement is claimed.

## Historical preparation record

Status: a separate T02 weighting-data candidate now uses the six successful database shards from PR #89 run `36830305836`, whose overall Foundation run failed its browser gates. See the [latest database-only measurement](#october-1-database-only-weight-refresh). Only the two timing JSON files and this guide change; algorithms, tests, workflows, gates and reuse policy retain their existing contracts. The PR #88 record below remains historical. This is not full T01/T02 completion or candidate runtime acceptance.

## Historical PR #88 accepted measurement source

The previous timing refresh used [PR #88 Foundation run 36817029405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36817029405), attempt 1. The reviewed head was `5e44354785c44e46a93dd97705ef050d62fc6ab5`; the actual checked-out synthetic merge was `c9dc8ad1f164bdbfac526a9c2c996fd36576dff3`. Both have tree `4161be856fad978394368a1708bcec8f9085b7d3`, also the tree of accepted main `cc591daa001f774626e574885cbc7b4904f6b2d2`. Checkout logs, commit/tree readback, completed jobs and independent full-census review agree. This source is separate from later application and informative browser candidates.

| Engine | Complete shards | Files / listed cases | Passed cases | Assertions | Skips | Errors / failures |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| MySQL | 4 | 125 / 1,903 | 1,903 | 22,594 | 0 | 0 |
| SQLite | 2 | 125 / 1,903 | 1,809 | 18,647 | 94 | 0 |

The 94 SQLite skips are the existing MySQL-only cases. Their SQLite timing entries do not establish concurrency behavior; the complete accepted MySQL execution supplies that separate evidence. Artifact ZIP SHA-256 values were checked against GitHub's published digests before extraction. Original extracted discovery, manifest and JUnit files were retained and individually fingerprinted again during preparation. Artifacts have seven-day retention; preserve provenance when refreshing.

| Artifact | ID | Verified ZIP SHA-256 |
| --- | --- | --- |
| MySQL 1 | 11142312624 | `348ce3aff07279cc6fc4de6fc034d75e3afba3074c6235910735da4184a329bb` |
| MySQL 2 | 11141824218 | `388953b4c1fbb1c8836ead6868d61884de9e63afb63a2b035224096b3d69019d` |
| MySQL 3 | 11142148662 | `93fa68bd093e002955362acb9c49a99a5140bcc8c5cf81698529726c84d1f902` |
| MySQL 4 | 11141849932 | `3e22a461b330061861d90f42c4f3c6f9b4c551910790985f233ebaccb0f4138f` |
| SQLite 1 | 11142042442 | `38dc4279fb2e185b5a57ce7f13bd00911ef18d8365450790338d3a90cdf21dfe` |
| SQLite 2 | 11142635755 | `6c8402383661901c0a8c0166d4df02dd0cdc6617fa26d07b7da9ebd6b1b96a59` |

## Generation and reconciliation

The unchanged [timing generator](../../scripts/ci/phpunit-timings.py) processed all four MySQL and both SQLite JUnit logs separately. It attributes cases to their enclosing test-class file, validates driver and finite nonnegative time, rounds each complete file's milliseconds and computes the fallback mean. Each measured file appears in exactly one shard: one accepted sample per file, without averaging different source versions or assigning invented durations to later tests. It adds real measurements for `HashByteGuardMigrationTest` and `CommerceGuardBytesTest`, which used fallback in PR88.

Some unrelated historical blobs were unavailable locally. Preparation materialized the 129 exact tracked blobs needed for accepted test-class and declaring-file paths, PHPUnit configuration and three unchanged CI tools from the complete 783-blob tree manifest. Every required blob was available and fingerprinted; no placeholder source was created. The timing generator ran normally against these real files using its existing runner-path suffix matching. For retained discovery XML only, copies replaced the exact `/home/runner/work/VASEYAUDIO/VASEYAUDIO/` file prefix with materialized paths; original evidence stayed unchanged. Unknown or escaping paths were rejected. Neither production tool was edited or monkey-patched.

All six original manifests agree within their engine. The unchanged inventory parser and proof reconcile actual JUnit identities and per-class counts to each executed shard, then preserve every accepted file, expanded case and PHPUnit group exactly once. Both engines have identical accepted source identities:

| Accepted census identity | SHA-256 |
| --- | --- |
| Expanded cases and owning files | `5b2446d549a3941853299f00f0a46915856b724a288f6d788efba43e0d99a39e` |
| PHPUnit groups and expanded cases | `17b1d6a5d2b66136af80a8ae013be373c712dcd6eae9876247fa67be216804ac` |

A full checkout of accepted tree `4161be8` can reproduce generation directly. Download the backend artifacts and run the following there with each JSON file's exact `source` string. Compare outputs before integration into newer source:

```bash
gh run download 36817029405 --dir /tmp/vaseyaudio-timings/36817029405 --pattern 'backend-mysql-*'
gh run download 36817029405 --dir /tmp/vaseyaudio-timings/36817029405 --pattern 'backend-sqlite-*'
python3 scripts/ci/phpunit-timings.py --driver mysql \
  --source "<exact source string from phpunit-timings-mysql.json>" \
  --output /tmp/phpunit-timings-mysql.json \
  /tmp/vaseyaudio-timings/36817029405/backend-mysql-*/phpunit-ci-mysql-*-results.xml
python3 scripts/ci/phpunit-timings.py --driver sqlite \
  --source "<exact source string from phpunit-timings-sqlite.json>" \
  --output /tmp/phpunit-timings-sqlite.json \
  /tmp/vaseyaudio-timings/36817029405/backend-sqlite-*/phpunit-ci-sqlite-*-results.xml
python3 scripts/ci/test-phpunit-shards.py
```

Generated JSON SHA-256: MySQL `8b8ff41d562b4413eef47084c59e6ae98af8622380e242fe973a4abadf65a103`; SQLite `803e8b4040443547e9ed65a92eb2262baa94231798e027788baab1a05fc4d5b3`.

## Retrospective estimates and current coverage

Previous weights came from PR87's 123-file / 1,870-case run. Applying those weights to the accepted PR88 census reproduces its exact original whole-file placement. Evaluating that placement and refreshed placement using the same retained PR88 measurements gives this offline comparison:

| Engine | Total rounded case time | New fallback ms/case | Original placement under PR88 measurements | Refreshed placement under PR88 measurements | Cases per refreshed shard |
| --- | ---: | ---: | --- | --- | --- |
| MySQL | 6,289.152s | 3,305 | 1,062.232 / 1,670.117 / 1,593.412 / 1,963.391s | 1,572.286 / 1,572.287 / 1,572.286 / 1,572.293s | 518 / 469 / 389 / 527 |
| SQLite | 1,376.316s | 723 | 691.369 / 684.968s | 688.169 / 688.168s | 887 / 1,016 |

The unchanged partitioner gives zero-time files a minimum one-millisecond weight, adding 0.021s to SQLite partition totals. JUnit suite elapsed time and summed case times may differ slightly. These are redistribution estimates, not newly executed shards or guaranteed durations. Placement, fixtures and runner variability can change costs.

Fresh PHP 8.4.26 discovery on isolated current source `04e4743301a47ca720d849e01bdeba9203708a23`, tree `6fbd125d143afead56bbb8dced0a30a1eef25595`, found **138 files / 2,072 expanded cases**. Both four-shard and two-shard CLI dry partitions independently rediscovered their assigned files and passed the unchanged exact-case/file/group proof. All 1,903 accepted cases retain their original owning files. The 13 new files contain all 169 additional cases and remain explicitly untimed; no existing measured file's case count changed. They use normal fallback weights and are included exactly once. The 35 unchanged partition/timing safeguards pass.

| Current source dry partition | Old-weight estimates | Refreshed-weight estimates | Refreshed shard case counts |
| --- | --- | --- | --- |
| MySQL, four shards | 2,347.936 / 2,347.996 / 2,347.938 / 2,347.934s | 1,711.922 / 1,711.924 / 1,711.922 / 1,711.929s | 535 / 538 / 538 / 461 |
| SQLite, two shards | 639.210 / 639.209s | 749.262 / 749.262s | 1,070 / 1,002 |

The current-source table uses weights from different accepted runs, not a controlled runtime comparison. SQLite's newer weights increase its estimate. Application tests, native browsers and MySQL were not executed during preparation; discovery does not establish their behavior. Independent source review and ordinary full CI remain required for integration. Later additions must be rediscovered rather than assuming this frozen census still applies.

Independent review approved the exact three-path refresh `db9bddbe20868adf62973cc771156e3dfee2b727`, tree `5bfe1f2cd2d83250f49ac0045dbecafd74209fdd`, independently reproduced both JSON files byte-for-byte with the unchanged generator, verified all required real source blobs and accepted evidence fingerprints, and passed all 35 safeguards. After integrating the reviewed bulk selection regression, fresh PHP 8.4.26 discovery at local `85f90250d4b8984560aa66520462e6941495303d`, tree `c1d0bba7e63e5d5c7e85a9a14448f3a714728f93`, proved **138 files / 2,073 expanded cases** exactly once through both ordinary four-shard and two-shard CLI dry partitions. The 13 new files contain 170 cases using the unchanged fallback. All 35 safeguards passed again. This later census is discovery evidence, not MySQL or application execution.

For historical context, accepted PR88's full wall time was 34m22s versus PR87's 62m06s; longest MySQL test steps were approximately 32m44s and 60m00s. These actual observations involve different censuses and runs and do not prove this refresh will reproduce improvement. Use subsequent ordinary accepted runs for measurements; no redundant cloud run was started. Fixture/bootstrap optimization and broader cadence changes remain separate reviewed work under [the development strategy](../ci-development-strategy.md).

## October 1 database-only weight refresh

This isolated candidate starts from local `d70a0edcfb402c67f3b66ac4a30489f2e7d1a3d7`, tree `8ceb8393d66b0ea4c2c2f46e429b0106ac4f5402`. It is a separate next-batch change, outside the active PR #89 acceptance source and the T15 notification child. The only changed paths are the MySQL/SQLite timing manifests and this guide. Scanner response, application behavior, the complete partition algorithm, discovery/configuration, shard counts, triggers, warnings, audits, timeouts, required jobs and full-only receipt/reuse policy are not modified.

The measurement is [Foundation run 36830305836](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36830305836), attempt 1: PR #89 head `f87332a566b4a618b0868805baf272cdab870d7d`, actual checked-out merge `27f592fee29addce95cba35ec3b2f3e51cea86b9`, tree `a3d80ed48b395111faf7c425b1b73bed07310e2b`, ordered parents `cc591daa001f774626e574885cbc7b4904f6b2d2` and `f87332a566b4a618b0868805baf272cdab870d7d`. All four MySQL and both SQLite database jobs, their source/partition/test/receipt/upload steps and their retained current-attempt evidence succeeded. Both browser jobs failed, so the overall Foundation result is **failure**. The six successful database measurements do not establish overall acceptance or reusable CI proof.

An independent replay ran the unchanged source, pagination and receipt collector checks against complete connector API snapshots and exact-ID archive bytes. The signed merge bytes recomputed to the actual checkout SHA; the clean checkout at its matching runner path recomputed the complete source identity without replacing `source_identity`. Current-run job/step provenance, hard archive digests, expiry, source/runtime/start/result identity, installed lock references, authoritative case ownership, exact JUnit results and complete cross-shard coverage all passed. A final live read retained attempt 1. Network transport was supplied by the GitHub connector; the failed aggregate did not run hosted collection. The evidence boundary remains [database receipt collection](ci-database-receipts.md), with reuse disabled.

| Engine | Successful shards | Files / listed cases | Executed cases | Assertions | Skips | Errors / failures |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| MySQL | 4 | 146 / 2,215 | 2,215 | 30,366 | 0 | 0 |
| SQLite | 2 | 146 / 2,215 | 2,113 | 25,465 | 102 | 0 |

SQLite's 102 identities match the 56 reviewed class/method pairs exactly; all policy methods exist in the retained source. Their counterparts executed on MySQL. Both engines share source case/owner digest `537db46f926371964ab02a69df489c0f94c17a5c36a3b5f9e5b59afc5fea2c33` and group digest `b624c0ab5382f25f0ae7390a3cf83ebba2bf77d2392e58d405974008fa440eac`.

| Artifact | ID | Verified ZIP SHA-256 |
| --- | --- | --- |
| MySQL 1 | 11148451604 | `bd6354c4264a00dd869ae9486c37a38e08064976f4cf567d87e51127c49c8a25` |
| MySQL 2 | 11147537895 | `92594d61f4183919771da830574a3397e0b8ec047657d5506666477227fb83b1` |
| MySQL 3 | 11147552196 | `e95f029f9286cfd8209ca8b5154966e974e9fc2274fecf245248ebd052fe9ea5` |
| MySQL 4 | 11148816330 | `b926af3f9751fe709f31a7df1316747eebb79caf296c208e59bcf2e65da11853` |
| SQLite 1 | 11147895110 | `fe03964764346b5c72628d87582b55022f66512e162da5574624495c3a83ab88` |
| SQLite 2 | 11147720279 | `36bd7a35f37cf90b50c0ebb39c22a89ac86313f5d17840fc7301f18cf141965c` |

### Actual duration and heavy files

These durations are actual GitHub job/step intervals, including installation and teardown in the full-job column. JUnit case sums exclude those costs and may differ slightly from a test step's interval.

| Database shard | Full job | Test step |
| --- | ---: | ---: |
| MySQL 1 | 28m53s | 27m31s |
| MySQL 2 | 34m37s | 33m11s |
| MySQL 3 | 27m58s | 26m13s |
| MySQL 4 | 46m05s | 44m50s |
| SQLite 1 | 15m09s | 14m03s |
| SQLite 2 | 15m03s | 13m59s |

The largest measured MySQL files are `TestPaymentProcessingTest.php` (75 cases, 646.193s), `TestContractIssuanceTest.php` (52, 631.859s), `TestOwnerDeliveryTest.php` (45, 587.039s), `TestPaymentExceptionInspectionTest.php` (41, 514.356s) and `TestOwnerDeliveryHttpTest.php` (48, 509.915s). Shard 4 includes two previously untimed files: exception inspection and `PublicCatalogRelatedLinksTest.php` (32, 290.660s). The prior mean assigned them fallback estimates; the refresh records their actual whole-file times. SQLite's largest files are owner delivery (110.598s), contract issuance (106.291s), payment processing (98.968s), finalization (91.274s) and exception inspection (77.623s).

### Reproducible generation and bounded estimates

The unchanged `scripts/ci/phpunit-timings.py` processed only this run's four MySQL or two SQLite original JUnit files. Each owning file contributes exactly one sample per engine; no other run is averaged in. Paths match real tracked source files, and inherited methods remain credited to their enclosing test class. Generation dropped no owning file. Both timing manifests retain exactly **146 measured files / 2,215 listed cases**, including the recorded SQLite skips. Their `source` fields explicitly state the actual merge/head/tree, successful database-only measurement and failed overall browser gates.

```bash
gh run download 36830305836 --dir /tmp/vaseyaudio-timings/36830305836 --pattern 'backend-mysql-*'
gh run download 36830305836 --dir /tmp/vaseyaudio-timings/36830305836 --pattern 'backend-sqlite-*'
python3 scripts/ci/phpunit-timings.py --driver mysql \
  --source "<exact source string from phpunit-timings-mysql.json>" \
  --output /tmp/phpunit-timings-mysql.json \
  /tmp/vaseyaudio-timings/36830305836/backend-mysql-*/phpunit-ci-mysql-*-results.xml
python3 scripts/ci/phpunit-timings.py --driver sqlite \
  --source "<exact source string from phpunit-timings-sqlite.json>" \
  --output /tmp/phpunit-timings-sqlite.json \
  /tmp/vaseyaudio-timings/36830305836/backend-sqlite-*/phpunit-ci-sqlite-*-results.xml
python3 scripts/ci/test-phpunit-shards.py
git diff --check
```

The generated manifest SHA-256 values are MySQL `45ea35a1de6f02ba9ee296b6b91308c617ac2123eebc449ace5e597f294b725b` and SQLite `36e57fae13eca42da35e659432e92f70fe9b2a7290d1329f3c9b2c5a58292f34`. A second invocation of the unchanged generator reproduced both files byte-for-byte; all 35 partition/timing safeguards and `git diff --check` passed. Complete file and expanded-case counts match each original retained source inventory exactly. The unchanged partition functions redistribute that retained 2,215-case inventory and preserve every complete case, owning file and group exactly once. The resulting offline estimates use the same measured file times for both placements:

| Engine | Total rounded case time | Fallback ms/case | Original placement under retained measurements | Refreshed placement under retained measurements | Refreshed listed case counts |
| --- | ---: | ---: | --- | --- | --- |
| MySQL | 7,903.568s | 3,568 | 1,650.245 / 1,990.714 / 1,573.257 / 2,689.352s | 1,975.892 / 1,975.892 / 1,975.893 / 1,975.891s | 585 / 528 / 605 / 497 |
| SQLite | 1,680.217s | 759 | 841.575 / 838.642s | 840.108 / 840.109s | 1,151 / 1,064 |

The unchanged partitioner applies a minimum one-millisecond weight to zero-time files, making SQLite's balanced weight estimates 840.120 / 840.120s. The table reports original rounded measurements rather than assigning execution time to those zero-time cases. These are retrospective redistribution estimates, **not newly executed shards, guaranteed durations or a controlled speedup**.

The candidate base differs from the measured source: `RelatedTrackBrowserFixtureGuardTest.php` adds three methods, and browser preparation/observation repairs change runtime behavior. Its timing entry intentionally remains the original six measured cases. Normal discovery and the existing per-case scaling rule determine the current whole-file weight; no measured runtime is fabricated for the later expected 2,218-case source. PHP is unavailable locally, so this preparation does not claim fresh current-source PHPUnit discovery, application execution, native-browser success or MySQL execution. Independent candidate review and its ordinary full CI remain required before adoption.

The older accepted PR #88 timing source had 125 files / 1,903 cases; this database measurement adds 21 files / 312 cases. Different application sources and hosted runtimes prevent causal runtime comparisons. Final PR #89 run `36835120458`, head `bb41dbd5b6fba22f4f213b93e041ba322276f14b`, actual merge `33cc70475a4bdb610a6aa04700f3cdb674e4e209`, tree `8ceb8393d66b0ea4c2c2f46e429b0106ac4f5402`, is separate and pending at preparation time. Its receipts and results must be verified afresh; the old run cannot be reused as final-source acceptance. No redundant full run was started to prepare this weighting-only candidate.

## Completed corrected-source measurement and font successor

This later refresh starts from local `58d1ad8458a1f21763eaa0b4ae05f093c6cbf594`, tree `005180401e36259460e875e09971971688009cf5`. It changes only the two timing manifests and this guide. The previous measurements and preparation-time statements above remain historical records. The partitioner, timing generator, discovery, configurations, shard counts, skip policy, tests, timeouts, required jobs and receipt/reuse gates are unchanged.

[Foundation run 36851992293](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36851992293), attempt 1, completed **SUCCESS** on PR #89 head `45050eb8f6a229ec6a115eeb584e3b2d08c5e161`, actual checkout merge `c43770c37df417e90a333e3f788b13fc6f881332`, tree `1c4a61767267d693b58a879e4d93a42d891ceed1`. Its ordered parents are `cc591daa001f774626e574885cbc7b4904f6b2d2` and `45050eb8f6a229ec6a115eeb584e3b2d08c5e161`. The subsequently confirmed [standalone preview font defect](public-track-embed-fonts.md) blocks that source from merging. These completed measurements are source-specific diagnostic evidence; they do not establish acceptance of the font successor or its changed timing inputs. The narrow font proof and fresh complete Foundation acceptance remain pending at this refresh checkpoint.

All four MySQL and both SQLite original archives passed hard SHA-256, full-member CRC, exact current-run source/runtime/discovery/JUnit ownership and unchanged strict collector checks. The locally collected result matches the actual hosted shadow member byte-for-byte: 63,293 bytes, SHA-256 `a0b2ad33310e107503d6c47299e776b52a029e475f23e9cd2f71206c080fdf32`. Its original artifact is `11159162420`, ZIP SHA-256 `b535bed6d71e0359c823c1434f7e7d868c2150b73da4e5df497dd49cbaa6fb8f`. [Receipt collection](ci-database-receipts.md) still authorizes no reuse.

| Engine | Successful shards | Files / listed cases | Executed cases | Assertions | Reviewed skips | Errors / failures |
| --- | ---: | --- | ---: | ---: | ---: | ---: |
| MySQL | 4 | 146 / 2,272 | 2,272 | 32,685 | 0 | 0 |
| SQLite | 2 | 146 / 2,272 | 2,167 | 25,996 | 105 | 0 |

The 105 SQLite skip identities exactly match the existing reviewed policy; their counterparts all executed on MySQL. Both engines have case/owner digest `51899bd7b6ef7b14a19a56c50e1346b2b59caf782b0fa02a79ddc2ac161ea5d0` and group digest `96d11df22c02b21cf99efbe51b889e108c0253432cf90a1e5f57c8c2a7c27587`. Retained runtime is PHP 8.4.26 / PHPUnit 12.5.34, MySQL 8.4.11 with `lower_case_table_names=0` and repeatable-read isolation, and SQLite 3.45.1.

| Artifact | ID | Verified ZIP SHA-256 | Full job | Test step |
| --- | --- | --- | ---: | ---: |
| MySQL 1 | 11158580476 | `f1e0a83a6ebab48a0c87c730fd7b0840398cd0989b2a73c394c2fcac617bd0aa` | 27m28s | 26m11s |
| MySQL 2 | 11158857178 | `40c43ed1247cd1cfe1ec8cebbd5ab30c55f8e97d7e8100deca805f659e82f21b` | 37m35s | 36m08s |
| MySQL 3 | 11158822571 | `75d9b9afc82a99ec8c143eedc55b18e3839f10fdd0b9c0aff4621c5de770afb2` | 39m33s | 38m05s |
| MySQL 4 | 11158827333 | `46e43d92ea961809991879571237c3ae4e74183748df20fff446ba33a9606685` | 37m44s | 36m28s |
| SQLite 1 | 11157260107 | `31293497cfdf1400e51b9711999243306536955ef438d92feec6b6fe408bafc9` | 14m48s | 13m56s |
| SQLite 2 | 11156870412 | `e75851c5fae87bcf50900d3b9ced8807e447a121ea5de0a7223141d2787c2521` | 13m34s | 12m39s |

Full-job and test-step durations are actual API intervals. JUnit testcase time sums are 8,210.374959s for MySQL and 1,593.123864s for SQLite; they exclude other process costs. The largest MySQL whole-file sums are contract issuance (611.165957s), order finalization (580.997631s), owner delivery (579.161209s), delivery HTTP (530.315997s) and exception inspection (472.579668s).

The unchanged generator used only the four MySQL or two SQLite original `phpunit-ci-<driver>-<shard>-results.xml` files from this run. Each of the 146 owning files contributes exactly one sample per engine. Every expanded case count matches its retained authoritative owning file; inherited methods stay credited to the enclosing test class, and no file is dropped. SQLite records include reviewed skipped cases and their recorded durations, rather than assigning them fabricated execution time. No older, partial, cancelled or separate follow-up measurements are mixed in.

```bash
gh run download 36851992293 --dir /tmp/vaseyaudio-timings/36851992293 --pattern 'backend-mysql-*'
gh run download 36851992293 --dir /tmp/vaseyaudio-timings/36851992293 --pattern 'backend-sqlite-*'
python3 scripts/ci/phpunit-timings.py --driver mysql \
  --source "<exact source string from phpunit-timings-mysql.json>" \
  --output /tmp/phpunit-timings-mysql.json \
  /tmp/vaseyaudio-timings/36851992293/backend-mysql-*/phpunit-ci-mysql-*-results.xml
python3 scripts/ci/phpunit-timings.py --driver sqlite \
  --source "<exact source string from phpunit-timings-sqlite.json>" \
  --output /tmp/phpunit-timings-sqlite.json \
  /tmp/vaseyaudio-timings/36851992293/backend-sqlite-*/phpunit-ci-sqlite-*-results.xml
python3 scripts/ci/test-phpunit-shards.py
git diff --check
```

The generated manifests have SHA-256 MySQL `7349629ce860c35fda2c44287804aae5295e28810d483c58a80821c989688447` and SQLite `361d8983f9eb1dad442186d4227483df34c7e8990cb2f8b363272c57bc9f0e05`. A second unchanged-generator invocation reproduced both byte-for-byte. All 35 unchanged partition/timing safeguards passed. Replaying the original retained inventories and projecting the refreshed whole-file assignments through the unchanged partition proof preserve exactly 146 files, 2,272 expanded cases and every group membership on each engine. This is an offline proof over retained discovery, not fresh local PHPUnit discovery or execution.

| Engine | Total rounded case time | Fallback ms/case | Original placement under these measurements | Refreshed placement under these measurements | Refreshed listed case counts |
| --- | ---: | ---: | --- | --- | --- |
| MySQL | 8,210.370s | 3,614 | 1,571.076 / 2,167.844 / 2,284.126 / 2,187.324s | 2,052.599 / 2,052.591 / 2,052.590 / 2,052.590s | 449 / 669 / 657 / 497 |
| SQLite | 1,593.124s | 701 | 834.358 / 758.766s | 796.563 / 796.561s | 1,173 / 1,099 |

The partitioner's unchanged one-millisecond minimum for 23 zero-time SQLite files makes its refreshed weight estimates 796.574 / 796.573s. The table retains their recorded zero times. These are offline redistribution estimates, not executed refreshed shards, guaranteed durations or a controlled speedup. Different sources and hosted runtimes also prevent causal comparison with the historical measurements above. PHP is unavailable locally; the font repair changes the candidate source, and the later five-file follow-up readiness data remain separate and unadopted. Independent source review and a fresh complete Foundation on the final composed candidate remain required.

## Integrated product discovery before corrected PR #6

The exact composition of `404e48a` and `f98c832` independently proved 3,215 expanded cases in 192 files across eight MySQL and two SQLite shards. MySQL case counts are 314 / 384 / 381 / 428 / 519 / 340 / 461 / 388; SQLite counts are 1,562 / 1,653. The same 313 reviewed SQLite skips imply 2,902 executed SQLite cases. All seven new untimed files retain the existing fallback weights. Census SHA-256 is `e9d5872b9754f80d6b0abd03c8a1225314f0fcde143ea0100213d4bec4d54755`; group SHA-256 is `9f5b662d817e90f3a4c244aa9cbd8e58588260cf88b59a9dd14e958d46dfdebf`. Generated evidence totals 2,592,437 bytes for MySQL and 2,438,662 bytes for SQLite; largest XML is 1,199,710 bytes, within unchanged parsing bounds. The composition passed 146 directly affected safeguards. Independent review approved the eight-shard source and sole source-map-js lockfile repair; these checks do not replace fresh hosted execution and ten actual receipts.
