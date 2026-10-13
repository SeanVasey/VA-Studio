# Independent C3 review decision

**DECISION: APPROVE for the development merge, subject to the merge gates and activation conditions below.**

- Reviewed candidate: `526e6f5b12068ca3d9571dfcb47dc25194e24598`.
- Actual main/base: `6c871b9c5b510c1926437bbdf6e96d661e2c2f30` (PR #113).
- Integrated application/test source exercised by the final native selections: `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`.
- Reviewer: separate session `/root/membership_award_independent_review`, October 13, 2026. The reviewer authored no application, migration, fixture or test changes. This decision is the reviewer's only repository edit for C3. The reviewer inspected source and performed offline Git/hash/XML/JSON comparisons; no application tests, database queries or hosted jobs were executed by the reviewer.

No unresolved P0/P1 remains in the reviewed C3 scope. Source approval at `67d85d6e` carries through the unused-import cleanup and documentation/comment changes to the tested `0d2f06c7`, and through the evidence/ledger-only changes to the reviewed candidate. The application, tests, migrations and configuration are unchanged between that native reference and this candidate. This is focused development approval, not production activation or complete Foundation acceptance.

## Source findings and disposition

The review covered forward263 preparation/recovery, original allocation identity at both retrieval endpoints, native database UTC, versioned legacy/current evidence, first invoice identity and append ordering, attempts/hint coverage, and the original freshness window through current and committed award/reservation closure. Generic seal behavior, provider payment facts, SDK/API pins, frozen contract assets and operation budgets remain unchanged.

Both blocking findings raised by this reviewer are closed:

1. **Required guard currentness.** An earlier REPEATABLE READ dictionary snapshot admitted a missing or replaced required interval guard. The correction pins the subjects and compares every required named `SHOW CREATE TRIGGER` definition, retaining the existing namespace checks. It does not claim a fully current inventory of unexpected additional triggers.
2. **Required table currentness.** The same snapshot admitted positions-table changes to MyISAM, default collation, allocation-token width and additional columns. The correction compares the current opened table's full structural definition. It excludes only the volatile table-option `AUTO_INCREMENT` counter; column identity/order, engine, collation, keys, constraints and CHECK grouping remain significant.

The native comparison at old application source `57c9bf9f` plus the retained new test reached both intended final assertions: **2 tests / 28 assertions, two failures, zero errors/skips, exit 1**. Both missing/replaced guards and all four table changes were admitted. Fixed component `71f545bd` passed **2 / 34, zero errors/failures/skips, exit 0**. The corrected source and mapped schema/test bytes are present in the integrated candidate. These are demonstrated before/after results, not presumed behavior from metadata documentation.

No additional P2 is introduced by this decision. Existing deferred work stays in its coherent batches; source freeze is not reopened for unrelated cleanup.

## Evidence independently checked

The final integrated MySQL **8.4.11** invocations at `0d2f06c7` are disjoint:

| Selection | Tests / assertions | Errors / failures / skips | Exit |
| --- | ---: | ---: | ---: |
| Public award/replay, real-source reservation/retry/replay, current263 producer immutability | 3 / 63 | 0 / 0 / 0 | 0 |
| Valid263 interval arbitration, hint/award ordering, stale/overlapping retrievals, committed owner withdrawal | 8 / 152 | 0 / 0 / 0 | 0 |
| Combined integrated native evidence | **11 / 215** | **0 / 0 / 0** | Both zero |

All **1,276** canary and **1,278** race captured hashes match their stated integrated Git source. Runner sidecars report no changed sources. Exact commands, filters, version and private datadir/port/socket setup are retained in [commands](commands.md) and the [run registry](evidence/runs.json).

The upgraded interval transcript contains distinct parent/worker PIDs and connections, two observed PRIMARY waits on start position 1, and two empty older snapshots. Each exact contender first passed an independent insert/rollback control. One worker saved; the other was an InnoDB **40001 / 1213 deadlock victim**. Both exited zero with transaction level zero and no open PDO transaction. The alternative `45000` branch and its post-refusal snapshot were **not observed** in that run. The test separately checks one retained authenticated claim and no paid-period/credit effect.

The final schema native subset at `71f545bd` passed **11 / 275**, zero errors/failures/skips, exit zero: prefixes **10, 12, 13, 15, 17, 19** plus five retained-upgrade/refusal cases. All **1,914** captured hashes match that component commit, and the six schema-owned paths match the integrated source. The fixed whole SQLite schema selection passed **35 / 758 with five native-only skips**. Other endpoint/timezone and producer native receipts remain attached to their earlier component sources.

The genuine old-writer probe uses public writer `45d12994` (**1 / 25**) and the same persisted SQLite database at `5dc11691` (**1 / 103**). Its recorded old-after/new-before file hashes agree. The retained probe source checks legacy-authority refusal, fresh real reconciliation, exact original public award receipt and retained-row equality. External probe/helper/runner bytes match captured before/after hashes. This is synthetic local SQLite continuity, distinct from constructed legacy-format fixtures and from native or host continuity.

Offline review independently verified:

- **483** digest members against local bytes and committed Git blobs, with complete tracked coverage of the packet-owned files; **451** archived artifacts, including **23** explicitly projected environment JSON receipts.
- **35** completed JUnit records against leaf counts, suite totals, expected results and actual exits.
- **42** source inventories against named Git blobs or the explicitly retained overlay/test capsule: **34 exact** and **eight historical partial**. The comparison covered **88,443** captured file entries; partial histories remain partial.
- The declared successful-invocation precedence yields **45 MySQL cases / 769 assertions** and **213 SQLite cases / 2,319 assertions**. These **258 driver/case identities are mixed-source coverage**, not one final-source suite. Seven selected SQLite skipped leaves add no passing coverage. The **103 passing leaves inside two failed invocations** remain separate supplemental observations, including overlap.

Original baseline/currentness/Context-omission failures, schema/setup errors, timestamp/cursor failures, discovery failure and initial Pint failure remain visible. The Context omission comparison detects loss of the direct terminal refusal; other public checks still refused the altered operation, so it does not prove an unauthorized durable effect. Historical through260/262, current263 and synthetic timing scopes are distinguished in the [coverage reconciliation](coverage-review.md).

Local quality records report **154 policy self-tests** and corrected Pint over **56 PHP files**; their exact source/carry limits remain explicit. Discovery enumerated **2,068 selected cases in 199 files** from **8,151 in 599**, over 24 nonempty disjoint shards. Discovery executed no test bodies, and 36 newer files retain fallback timing weights. None of these receipts substitutes for PR preflight or hosted Foundation verification.

## Merge gates and continuing conditions

The final receipt-only head must retain this application/test/evidence state and receive an explicit review carry. Before merge, require green cheap Development preflight on that exact head, no open P0/P1, and an expected-head-SHA merge. A cancelled first ready-transition run is not a green check; use the subsequent run on the same head. This decision does not assert that preflight has already run.

Keep the application default-off and unmounted. The forward263 [runbook](evidence/schema/runbook-facts-final.md) requires drained writers, no surrounding transaction and exact-prefix recovery; native DDL is not transactional, and destructive rollback refuses. Native prefixes **1–9, 11, 14, 16, 18** remain individually untested; their coverage is SQLite-only. The regression peer's five-second metadata-lock timeout is a fixture bound, not a tested host timeout policy.

Carry representative contention/latency, queue coalescing, unexpected-trigger inventory, database/host time-service facts and Sean's outstanding commercial/legal/account/host inputs forward. R-4 member-original native-driver admission and the catalog allocation-counter false conflict stay with C4/currentness; consumption remains coupled to C4 activation. Synthetic clock arithmetic/private cap narrowing do not prove physical elapsed-time interruption, and allocation replacement is not real failover verification. Native durations include setup/teardown; the disclosed separate C4 SQLite in-memory test overlap prevents an exclusive-CPU claim.

No live provider activity, real money, active customer imports, production deployment, host verification or cutover is approved. No Foundation or GitLab dispatch occurred for this batch. Full Foundation remains manual, exact-SHA final verification under the existing cost policy; GitLab requires Sean's cost go-ahead.
