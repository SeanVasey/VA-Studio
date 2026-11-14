# C3 focused coverage reconciliation

Final root source is `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`. Its two disjoint MySQL 8.4.11 invocations pass **11 tests / 215 assertions**, zero errors/failures/skips: three canaries (3 / 63) and eight race/postcommit cases (8 / 152). [Root summary](evidence/root-native-final-summary.json) verifies disjoint identities; the full leaf list remains in [JUnit summary](evidence/junit-summary.json).

## Mixed-source successful-case union

The explicit [precedence](evidence/coverage-plan.json) selects only passed leaves from named zero-exit invocations and deduplicates by driver plus full testcase/dataset identity. Four repeated SQLite Context cases are replaced by the later explicit restored-source comparison. Skips, intended reds, the original old-writer control and superseded component runs add no passing coverage.

| Driver | Evidence category | Distinct passed cases | Assertions in selected leaves |
| --- | --- | ---: | ---: |
| mysql | current263 runtime | 23 | 379 |
| mysql | current263 schema and upgrade | 22 | 390 |
| sqlite | adjacent membership preparation | 8 | 16 |
| sqlite | current263 genuine old writer replay | 1 | 103 |
| sqlite | current263 runtime | 99 | 1089 |
| sqlite | current263 schema and upgrade | 30 | 758 |
| sqlite | historical through260 schema | 1 | 8 |
| sqlite | historical through262 schema | 24 | 153 |
| sqlite | synthetic admission clock | 3 | 38 |
| sqlite | synthetic cap context propagation | 4 | 80 |
| sqlite | synthetic clock arithmetic | 9 | 17 |
| sqlite | technical clock and record checks | 34 | 57 |

This is **258 passed driver/case identities** across documented sources: 45 MySQL cases / 769 selected assertions and 213 SQLite cases / 2,319 selected assertions. It is a coverage ledger, not a single executed suite or a claim that all cases ran on final source. Seven native-only skipped leaves in the selected SQLite invocations remain excluded, even though each identity has separate native passing evidence.

The native schema group combines nine cases at `57c9bf9f` with two currentness and eleven upgrade cases at `71f545bd`. Twelve native producer cases are at `5dc11691`. Only root's eleven cases ran at the final integrated reference. [Final-source comparison](evidence/final-source-verification.json) lists captured input differences; it does not convert earlier passes into reruns.

The two root API/hint-job invocations exited 1. Their **103 passing leaves** are separately retained with parent exits in [supplemental evidence](evidence/passing-leaves-in-nonzero-invocations.json), excluded from this successful-invocation union. They overlap each other and successful follow-ups, so they are not 103 additional unique cases. The corrected whole cursor file is a separate passing SQLite invocation.

Historical through260 and through262 schema scopes remain separate from current263. The 21-case schema/fence invocation has exactly 20 historical cases and one current263 producer/immutability case. Genuine old-writer replay uses the original writer's persisted SQLite database and retained equality hashes; it is not replaced by a constructed legacy-row fixture.

## Observed interval race

Both exact candidate inserts independently satisfy the263 guards and roll back before workers start. Workers PID221136/connection193 and PID221137/connection192 each establish an empty older snapshot of both ledgers. Two actual PRIMARY start-row waits are observed against parent connection191/start1. One claim saves; the other refuses with **SQLSTATE40001 / MySQL1213 deadlock**. Both workers exit0, transaction level0 and no open PDO transaction. The raw transcript and [derived worker summary](evidence/native-worker-summary.json) are retained unchanged.

Both `snapshot_after` values are null. The test permits an alternative45000 refusal branch with a post-refusal snapshot, but that branch was not observed in this run. Separate schema older-snapshot cases supply their own evidence. Historical262 cross-phase pairs are never used as proof of this valid263 interval race.

## Limits and untested conditions

- No full application suite or hosted Foundation CI; no GitLab dispatch. Local discovery and policy tests do not establish hosted CI or PR preflight.
- Individual interrupted prefixes 1–9, 11, 14, 16 and 18 were tested only on SQLite; native coverage is the selected subset documented in the source map.
- No real provider credentials, payment network, money, customer imports or active entitlements. No production binding/activation, deploy, host verification, backup/restore/failover acceptance or C4 grant consume.
- Synthetic clock arithmetic, frozen admission clocks and private cap narrowing prove their labelled local checks, not physical elapsed-time interruption or database-host UTC guarantees. The arithmetic test's future anchor intentionally makes external real-clock checks lenient.
- Genuine old-writer continuity is a synthetic local SQLite test with a real public writer/paid producer and UI-free loopback identity flow. It does not prove native upgrade continuity or external provider behavior.
- Final native timings are observations, not a performance benchmark. A separate reported C4 SQLite `:memory:` case overlapped the last native phase without shared database state; it adds no C3 coverage.

All required root native identities have completed. [Changed behavior map](coverage-map.md), [source map](source-map.md) and [runbook](evidence/schema/runbook-facts-final.md) retain the remaining source and operating boundaries. This packet author makes no independent acceptance decision; root/reviewer own that decision and exact-head preflight.
