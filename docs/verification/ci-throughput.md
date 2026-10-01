# CI throughput timing refresh

Status: T02 timing refresh prepared for integrated CI validation; October 1, 2026 (UTC). This change updates measured weighting data only. No test, partitioner, warning/audit setting, workflow trigger, timeout, shard count or required check is changed. Full candidate CI and independent review remain acceptance gates. Measured fixture/bootstrap optimization remains future work.

## Source evidence

Both timing files use the complete successful [PR #87 CI run 36795680362](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36795680362), attempt 1. Its head was `e27001edde722815f65bc2f0588840078471bf2f`, base `383354729e506ef93a4c458552c6087cb717fa9c`. Checkout logs identify the actual synthetic merge commit `2a87cdad2f9a5fb017cdd04198870c25164343d6`. GitHub commit metadata confirms its tree is `c389602f8acb43287a8d8125b482c4c0dd2b3dba`, also the tree of accepted main `8be3bd7271595f2c21a3c5017b3b19ceb821a842`.

| Engine | Artifact IDs, in shard order | Files | Listed cases | Assertions | Skips | Errors/failures |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| MySQL, four shards | 11135369039, 11134995732, 11134996456, 11135000294 | 123 | 1,870 | 21,998 | 0 | 0 |
| SQLite, two shards | 11133887481, 11133937557 | 123 | 1,870 | 18,288 | 94 | 0 |

SQLite executed 1,776 of its 1,870 listed cases. The 94 SQLite skips are the existing MySQL-only cases; SQLite does not establish their concurrency behavior. The complete MySQL run supplies that separate execution evidence. Each downloaded ZIP's SHA-256 was checked against GitHub's artifact digest before reading it. Artifacts have the workflow's existing seven-day retention; preserve provenance when refreshing again.

The prior timing files covered 121 files and 1,744 cases from PR #82. The new files include the previously untimed `MalwareScannerTest` and `MediaWorkflowBudgetTest`, plus current counts for expanded existing classes. Unknown future files continue to receive the partitioner's fallback weight and are never omitted.

## Generation and census reconciliation

The unchanged [timing tool](../../scripts/ci/phpunit-timings.py) credits every JUnit case to its enclosing test-class file, validates finite nonnegative times and engine-specific filenames, aggregates complete file timings, and computes the fallback mean. Each current file appears in exactly one shard, so this refresh is one accepted-run sample rather than an average of different suite versions. This preserves the present 1,870-case census. Runner variability remains a limitation; use later comparable ordinary full runs for subsequent averages.

The preparation workspace contains a partial source materialization rather than a full checkout. A temporary adapter therefore replaced **only source-path reconciliation** with a read-only authoritative Git-tree lookup for tree `c389602f8acb43287a8d8125b482c4c0dd2b3dba`. The complete recursive tree was verified nontruncated. Only exact tracked blob paths under the known runner repository prefix, or exact repository-relative paths, were accepted; unknown/escaping paths failed. No placeholder test files or fabricated test contents were created, and neither production Python tool was edited.

The adapter called the existing timing tool's driver validation, JUnit parser, combination, rendering and entry point. It also used the existing partitioner's XML inventory parser and proof with the same authoritative path lookup to validate all original source/shard lists and group identities. JUnit per-file case counts had to match the discovered inventory for each executed shard. Both engines had to describe the same complete source case/group census.

| Source identity | SHA-256 |
| --- | --- |
| Expanded cases and owning files | `e9cfcdfb8a4a3ef2ac8ec04f4c702d0408a121a0cbf9e059af5f4711afe59319` |
| Groups and expanded cases | `bc5b5ad81c0571e278ad75ebf7ca6df06276c1d3e631e80a2f9345e4421f569a` |

A full source checkout can reproduce the outputs directly, without this adapter:

```bash
gh run download 36795680362 --dir /tmp/vaseyaudio-timings/36795680362 --pattern 'backend-mysql-*'
gh run download 36795680362 --dir /tmp/vaseyaudio-timings/36795680362 --pattern 'backend-sqlite-*'
python3 scripts/ci/phpunit-timings.py --driver mysql \
  --source "<source string from phpunit-timings-mysql.json>" \
  --output scripts/ci/phpunit-timings-mysql.json \
  /tmp/vaseyaudio-timings/36795680362/backend-mysql-*/phpunit-ci-mysql-*-results.xml
python3 scripts/ci/phpunit-timings.py --driver sqlite \
  --source "<source string from phpunit-timings-sqlite.json>" \
  --output scripts/ci/phpunit-timings-sqlite.json \
  /tmp/vaseyaudio-timings/36795680362/backend-sqlite-*/phpunit-ci-sqlite-*-results.xml
python3 scripts/ci/test-phpunit-shards.py
```

## Offline results and next acceptance

The 35 unchanged partition/timing safeguards pass. Both refreshed files parse through the partitioner, contain exactly the current discovered files/case counts, and leave no current file untimed. Offline redistribution of the retained inventory through the unchanged partition/proof functions preserves every expanded case, owning file and group exactly once. This is a static redistribution proof of retained CI evidence, not a new PHP discovery or runtime pass; the integrated candidate must run the actual CI discovery and execution.

| Engine | Recorded total case time | New fallback milliseconds/case | Retrospective shard estimates | Cases per new shard |
| --- | ---: | ---: | --- | --- |
| MySQL | 8,521.911s after per-file rounding | 4,557 | 2,130.479 / 2,130.479 / 2,130.477 / 2,130.476s | 481 / 448 / 396 / 545 |
| SQLite | 1,157.342s after per-file rounding | 619 | 578.682 / 578.681s | 927 / 943 |

Current JSON SHA-256: MySQL `5cdd96623afaafa4c8240af29bdf36940025a7c631529c1c965eb390e81b4e66`; SQLite `8089709e515a005c5c7bccd50ed422bacfbffabd76892469773f3cbbb1c3d1f4`.

The prior accepted run's MySQL test steps were 60m00s, 26m51s, 31m35s and 23m37s; full wall time was 62m06s. These new weights estimate approximately 35m30s per MySQL shard and 9m39s per SQLite shard **under the retained measurements**. Changed placement and hosted-runner variability may change real costs. No improvement is claimed until executed candidate CI supplies it.

Record the next ordinary full run's slowest shard, total runner-minutes, full wall time and per-engine counts in the PR evidence. The following ordinary passing run provides a second measurement without launching a redundant suite solely to improve the average. Continue profiling the measured heavy files in a separate reviewed change if timing variance or fixture costs remain significant. See [the development strategy](../ci-development-strategy.md) for the proposed broader CI cadence; this timing refresh does not implement it.

The integrated T03–T08 candidate adds two test files and 33 cases beyond this measured baseline (125 files / 1,903 cases). Those new files use the existing explicit fallback weights until actual accepted-run timing is available; the partition census still discovers the entire current suite and refuses omitted or duplicated cases. No fabricated timings are assigned to the new tests.
