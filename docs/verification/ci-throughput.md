# CI throughput timing refresh

Status: bounded T02 weighting-data refresh prepared for review on October 1, 2026 (UTC). Only the two timing JSON files and this guide change. The partitioner, timing generator, tests, workflows, warning/audit settings, timeouts, shard counts and required checks remain unchanged. This is not full T01/T02 completion or candidate runtime acceptance.

## Accepted measurement source

Both timing files use [PR #88 Foundation run 36817029405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36817029405), attempt 1. The reviewed head was `5e44354785c44e46a93dd97705ef050d62fc6ab5`; the actual checked-out synthetic merge was `c9dc8ad1f164bdbfac526a9c2c996fd36576dff3`. Both have tree `4161be856fad978394368a1708bcec8f9085b7d3`, also the tree of accepted main `cc591daa001f774626e574885cbc7b4904f6b2d2`. Checkout logs, commit/tree readback, completed jobs and independent full-census review agree. This source is separate from later application and informative browser candidates.

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

For historical context, accepted PR88's full wall time was 34m22s versus PR87's 62m06s; longest MySQL test steps were approximately 32m44s and 60m00s. These actual observations involve different censuses and runs and do not prove this refresh will reproduce improvement. Use subsequent ordinary accepted runs for measurements; no redundant cloud run was started. Fixture/bootstrap optimization and broader cadence changes remain separate reviewed work under [the development strategy](../ci-development-strategy.md).
