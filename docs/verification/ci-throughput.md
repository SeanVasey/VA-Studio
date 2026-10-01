# CI throughput timing refresh

## Current integration boundary — October 1, 2026

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
