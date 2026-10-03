# Browser fixtures, disposable database teardown and process control

October 2, 2026. This bounded follow-up composes the reviewed browser and lifecycle repairs plus the subsequently diagnosed process-control configuration repair on local baseline `d953b93711f4093f5c033e3047972bff983888a5`, tree `fc61aabc0e5bc8c9903ef98f8398d91bddab272a`. That baseline corresponds to the native candidate abbreviated `cd0cf7d3` in [pipeline 2908229729](https://gitlab.com/vaseydev/va-studio/-/pipelines/2908229729). The earlier [acceptance corrections](gitlab-acceptance-corrections.md) remain historical evidence. This document does not claim full acceptance, a merge to main, or production readiness.

## Standalone image fixture configuration

The native Chromium job reported 47 passes and one failure: the standalone PHP process used by `tests/browser/site-images.spec.ts` loaded Composer but not the Laravel configuration binding. The deterministic JPEG fixture now uses configured encoder budgets, so that process must bootstrap the console kernel before calling the fixture.

Reviewed component `294b74575e24746b03152197d3cb3a8490ce0bc4` adds that bootstrap. It preserves the original dimensions, timeout, output budget and browser assertions. The PHP image fixture and production image processing are unchanged by this follow-up.

Focused evidence:

- The generated CLI image probes as MJPEG, **1440 × 630, `yuvj444p`**.
- The complete targeted WebKit mobile image-upload journey passed **one case**, with zero skips, unexpected results, flaky results or runner errors. Total runner duration was **21.429 seconds**; the case itself took 19.884 seconds.
- This journey deliberately exercises the missing-scanner retry state. It does not establish production scanner readiness or a successful full Chromium/WebKit suite on the repaired candidate.

## Measured database lifecycle change

`FinalizationDatabaseMigrations` previously ran full `migrate:fresh` both before and after each test. Fifty existing test classes use that trait. Profiling nine original cases across order finalization, customer delivery projection and independent-process inquiry races found that teardown's second migration run took 20.863 seconds out of a 55.578-second measured lifecycle total.

Reviewed component `2511b7a5936dc3fda53d28d39428a670cd3a6152`, tree `dca30f9f95da08d513d999b12e168e77abe7fdfe`, replaces only that teardown rebuild with `db:wipe`. Every setup still performs full migrations. Cleanup retains the existing migration connection, view and type options, refuses a nonzero cleanup result, and resets Artisan and `RefreshDatabaseState` in `finally`. It never invokes guarded operational rollback. Production migrations, test bodies, lock barriers, shard counts, timeouts, skip policy and acceptance gates are unchanged.

The comparison used external diagnostic subclasses wrapping the original lifecycle methods on a fresh disposable MySQL 8.4.11 server per variant, with repeatable-read isolation and performance schema enabled. All three original test-class files were byte-identical to the baseline; all nine case identities matched. Instrumentation stayed outside the committed source.

| Measured phase | Baseline | Changed teardown |
| --- | ---: | ---: |
| Setup | 20.888 s | 28.208 s |
| Test bodies | 13.816 s | 17.733 s |
| Teardown | 20.874 s | 2.686 s |
| Sum of measured phases | 55.578 s | 48.627 s |

Both variants passed all nine cases with zero errors, failures or skips. Assertions were 389 and 457: the six non-race cases retained exactly 188 assertions, while independent-process polling legitimately varies its assertion count. JUnit elapsed totals were 55.580 and 48.629 seconds; they include small costs outside the measured phase boundaries.

Observed teardown time fell **87.13%**; the nine-case measured total fell **12.51%**. There is one sample per variant, and setup/body costs varied with the shared runtime. These are focused measurements, **not a demonstrated full-suite or hosted-CI speedup**, and must not be extrapolated into a launch estimate.

## Isolation and warning safeguards

Four new parent cases run genuine child PHPUnit lifecycles. They verify successful and deliberately throwing bodies, complete schema recreation before the next body, absence of prior fixture rows, default and named-default connections, migration-state reset, view options and callbacks that execute after cleanup. SQLite's complete reset removes views; MySQL retains views when dropping them was not requested. Explicit `--database` overrides and PostgreSQL type behavior are preserved by source review, not separately runtime-proven.

The parent requires exactly the two expected child case identities and exactly two matching cleanup receipts. It also reads the child event log: PHPUnit's expected error exit `2` can otherwise mask warning exit `1`, and JUnit omits warning events. Runner/PHPUnit warnings are rejected unconditionally; PHP/user warnings retain PHPUnit's explicit suppression semantics.

An isolated negative proof added two public `expectOutputString('')` calls to an external copy of the child fixture. The resulting real PHPUnit warning was rejected at the warning guard after the child error, cleanup and identity checks had passed. That one expected failing probe is preserved as negative evidence, not counted as a candidate failure or a passing test.

| Final focused scope | MySQL 8.4.11 | SQLite |
| --- | --- | --- |
| Four existing immutable-history migration classes | 43 passed / 620 assertions | 43 passed / 631 assertions |
| Final lifecycle safeguards, including warning rejection | 4 passed / 70 assertions | 4 passed / 70 assertions |

Every row has zero errors, failures or skips. The earlier combined reports each contain 47 cases, including preliminary versions of the same four lifecycle safeguards: MySQL 674 assertions and SQLite 685. Those reports overlap the final safeguard runs and must not be added together. Early failing fixture expectations and the warning-injection failure remain separate retained diagnostics.

Pint passed for the three PHP files; diff formatting and all 35 partition safeguards passed. Candidate discovery contains **2,956 expanded cases in 178 classes**, up from 2,952 in 177 classes solely because of the four parent safeguards. The child fixture is excluded from that census and runs in a separate process. No SQLite skip registration changes are required.

## Exact SQLite skip rejection and process-control repair

The completed native SQLite partitions reported zero test failures/errors: shard 1 had 1,438 cases, 140 skips and 14,292 assertions; shard 2 had 1,514 cases, 143 skips and 16,200 assertions. Shard 1's receipt passed. Shard 2's receipt correctly rejected two unexpected skips, so its successful test exit was not acceptance.

The unchanged source discovery and timing weights reproduce the exact original partition: shard 1 expects 140 reviewed MySQL-only skips and shard 2 expects 141. Mapping all 1,514 native progress marks to the second partition's unchanged execution order aligns every one of those 141 reviewed skips. The only extra skips are:

| Native position | Unexpected skipped test |
| --- | --- |
| 1,163 | `TestContractStorageTest::test_partial_physical_write_is_not_published_or_overwritten_on_retry` |
| 1,435 | `TestPreparedDeliveryStreamTest::test_a_real_short_physical_write_fails_without_consuming_a_slot_or_retaining_partial_bytes` |

Both existing tests require `posix_setrlimit`, `pcntl_signal` and `SIGXFSZ` for real physical short-write injection. The native setup omitted PCNTL, and runtime validation required POSIX without requiring PCNTL. These tests are valid on both database engines; adding them to the MySQL-only skip policy would misclassify missing runtime capability.

The repair installs `pcntl` when missing, verifies the actual signal/resource-limit functions and signal constant before dependency/test work, and requires PCNTL in both native database receipts. **The skip-policy file and exact skip equality are unchanged.** Setup now fails early if either required function is disabled; collected evidence with either process-control extension absent is rejected.

Focused reproduction disabled only `pcntl_signal`: the two identified cases both skipped. With process control available, the same cases passed with 10 assertions. A three-case run including one ordinary storage control reproduces the exact receipt error when signals are disabled; with signals enabled, the unchanged receipt parser accepts all three executed cases, zero skips and 18 assertions. These overlapping focused runs are not additive test totals. No full suite or native CI was rerun for this repair.

All **12 setup safeguards**, **23 native collector safeguards** and **24 shared receipt safeguards** passed, including actual PHP probes with individually disabled signal/resource-limit functions and native receipt rejection for missing PCNTL/POSIX under both engines. Shell syntax and diff checks passed. Native skip-order proof and focused original JUnit/parser evidence are retained under `ci-skip-diagnosis/` in the execution checkpoint.

## Exact composed source and acceptance boundary

Composition preserves these independently reviewed executable bytes:

| File | SHA-256 |
| --- | --- |
| `tests/browser/site-images.spec.ts` | `d06be164a788ae3ce272742710224a48653d6cf01ff5e47e32f5dfddf89ef6bb` |
| `tests/Support/FinalizationDatabaseMigrations.php` | `38d248d182fffb7aa6c0583ebb8235c5b60b03fe8865d679b9eb804728a792ff` |
| `tests/Support/FinalizationLifecycleFixture.php` | `7ef29a58fae5d0ce0a81b5c381d9261824b773432bd6051de93b120d91f852f6` |
| `tests/Feature/FinalizationDatabaseLifecycleTest.php` | `83fcde0b2f3ccbbe7b81978ca362281cdb79a69e7ef1347873c4484ec45cc522` |

Original evidence includes `image-fixture-browser.json`, `site-image-cli-after.jpg`, and `ci-profiling/{baseline,optimized,lifecycle-guards-mysql,lifecycle-guards-sqlite,lifecycle-final-mysql,lifecycle-final-sqlite-v3,warning-rejection-final-red}.xml`, plus `ci-profiling/{ab-comparison,final-verification}.json`, retained under the execution checkpoint `/workspace/scratch/2c1d12ebc985/`. The integration owner records the final composed commit/tree and durable evidence location with the merge request.

No native pipeline was restarted or canceled to prepare this composition. All four native MySQL jobs subsequently terminated with `ci_quota_exceeded`; those incomplete executions cannot supply full MySQL acceptance. Restored runner capacity, independent composition review, exact native tree verification, complete applicable GitLab acceptance and separate main verification after an expected-head merge remain required. Runtime proof reuse remains disabled.
