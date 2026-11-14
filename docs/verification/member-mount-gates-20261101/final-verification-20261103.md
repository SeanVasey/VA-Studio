# F9 verification results — 2026-11-03

**Selected development verification passed; mounting and release remain held.** These results supplement the historical, source-only [composition packet](main-composition-20261103.md). Original execution statements in that packet and the [independent source decision](F9-HISTORY-f0f55324-INDEPENDENT-20261103.md) describe their recorded review times. The four original final-source runtime conditions are now fulfilled by actual runs and independent validation of their original receipts. No product test was repeated for publication.

## Source attribution

- Product/regression source tested: `5b001577b0521cf472f14b8ae88fc13ad2567e3b`. Its App bytes are the independently reviewed locking-origin access-path fix, using the existing `piv_sequence_unique` index through `FORCE INDEX`; no physical index definition changed.
- Census source: `b2b116f9d16875def80677077593b6831619a60a`. It appends the recovered-owner native regression, increasing 335 entries to 336 while preserving the complete previous 335-entry prefix and first-150 hash.
- Final physical source: `f0f553243e31a128bc08d7f9624d1ffc3a564fd3`. Its only changes after b2 are the two workflow count comments. Product, tests, census and manual probe are carried unchanged. Independent final-source review reported no validated P0–P2 finding, with approval conditional on executable gates. The separate independent decision supplies source approval.

## Completed evidence

| Source | Recorded result | Scope and limit |
| --- | --- | --- |
| 5b001 | Native 5 tests / 109 assertions passed | Recovered-owner regression: 1/26; mandatory same-owner lock, writer-held refusal, writer-commit and release regressions: 4/83. Strict source origins and cleanup passed. |
| 5b001 | SQLite 68 tests / 2,454 assertions passed | Four recorded selections: 19/473, 7/196, 6/544, 36/1,241. Strict source origins and cleanup passed. These are SQLite results, not native physical-pool evidence. |
| b2 | 25 discovery listings completed | Measured 2,709 selected tests from 10,178 discovered tests, covering 269 selected files. Full discovery plus 24 shard listings are enumeration evidence, not test execution; no claim is made about individual PHP stderr streams. |
| f0 | Five CI safeguard suites, 207 HOST tests passed | Host-side safeguards only; this does not establish database or manual physical execution. |

## Completed final physical evidence

All four original final f0 pools passed: 4 tests / 482 assertions, with 36 actual award workers and 36 samples. Each original outer/native exit was 0, and aggregate capture and quiet cleanup completed.

| Recovered binding pool | Final f0 result | Acceptance |
| --- | --- | --- |
| 1 | 1 test / 110 assertions; 894.683657s; worst local sample 21.082234219s | Nine workers/samples, full 100-period traversal, exact 2/2/2 proofs, origins/cleanup/lease passed |
| 2 | 1 test / 112 assertions; 803.397157s; worst local sample 20.658891015s | Nine workers/samples, full 100-period traversal, exact 4/4/4 proofs, origins/cleanup/lease passed |
| 10 | 1 test / 128 assertions; 1131.192214s; worst local sample 39.50057313s | Nine workers/samples, full 100-period traversal, exact 20/20/20 proofs, origins/cleanup/lease passed |
| 12 | 1 test / 132 assertions; 1110.954043s; worst local sample 37.534627202s | Nine workers/samples, full 100-period traversal, exact 24/24/24 proofs, origins/cleanup/lease passed |

Each completed pool independently retained the original nine actual award samples/workers, full 100-period traversal and pre-bytes closure, exact retained-binding proof counts, original 60-second domain deadline, worker/observer checks, strict source origins and complete cleanup. The physical envelope remains 1,855 seconds outer / 1,800 seconds PHP. A completed process alone is insufficient.

## Reproduction entry points

Use an admitted, isolated native database environment and the source-bound original adapter; the commands below identify repository selectors, not a substitute for admission, observer, lease or cleanup controls.

```sh
php vendor/bin/phpunit --filter test_native_recovered_index_allows_foreign_award_without_cross_owner_history_locks tests/Feature/ProductionMembershipBilling/MembershipNativeCustomerIndexTest.php
python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection
```

For the owner regressions, invoke the admitted driver separately with `METHOD` set to each exact selector below; retain one method per process and all source, observer, lease and cleanup controls. Its PHPUnit selector is `--filter "$METHOD" tests/Feature/ProductionMembershipBilling/MembershipNativeCustomerIndexTest.php`:

```text
test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them
test_native_index_waits_at_a_writer_held_owner_lock_and_retains_no_lock_after_refusal
test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit
test_native_index_waiting_behind_a_same_owner_release_reads_the_released_history
```

Run the original manual method separately for each `B` in `1 2 10 12`, through its qualified adapter with these environment values and PHPUnit selector:

```sh
VA_MEMBER_INDEX_PHYSICAL_PROBE=1 VA_MEMBER_INDEX_BINDINGS="$B" php vendor/bin/phpunit --filter test_physical_pages_with_real_recovered_bindings docs/verification/member-mount-gates-20261101/MemberIndexLatencyProbeTest.php
```

## Limitations and release holds

Old 06c pool-1 evidence belongs to that earlier source and cannot qualify f0. Old 06c pool-2 failure remains failure evidence; later diagnosis identified the missing read-only statement-history permission, and the recovered-origin locking issue required the subsequent product fix. The separate “04” transport/observer attempt is unaccepted and supplies no physical acceptance. Preserve all failed or incomplete records without relabeling them as final-source passes.

The four final-source physical conditions, exact source/evidence attribution and cleanup are verified. Development publication and merge still require the recorded PR preflight and review gates; release retains its separate host, provider and activation gates. Any failure remains a failure; do not change assertion counts, reduce proof density, weaken ownership checks or extend domain deadlines. These diagnostics establish neither a target-host SLA nor product activation; mounting remains literal-false.

## Publication carry

The [source carry](publication-source-carry-20261103.json) records the unchanged 14 executable/test/source objects, manual probe/counter and original vendor controls. Only documentation was added after the tested f0 source; the PR identifies its exact later publication head. The [structured results](final-verification-results-20261103.json) contain public result fields only. Original JUnit, transport, observer and fixture evidence remains privately retained; the coordinating verifier and a second verifier independently checked the original four-pool records.
