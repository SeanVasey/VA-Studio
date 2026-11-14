# C3 changed behavior and evidence map

Frozen root native source: `0d2f06c716c6eb21d1ccbb9af6c8e0d7b1924371`. Final focused integration passes **11 / 215**, zero errors/failures/skips, on private MySQL 8.4.11. This map connects changed behavior with actual observations and their remaining limits; [source-map.md](source-map.md) provides full invocation/source details.

| Behavior | Completed evidence | Boundary |
| --- | --- | --- |
| Allocation replacement and worker-clock regressions | Original API baseline at `45d12994`: 4 intended failures / 53 assertions; fixed producer SQLite and native cases | Disposable local simulation; no host restore/failover claim |
| Native UTC clock, parsing and exact versioned record shape | Helpers 34 / 57; native producer 12 / 164 at `5dc11691` | Component source; helper arithmetic and native clock checks remain distinguishable |
| Issued endpoint tokens, provider-read order and immutable paid facts | Producer SQLite 10 / 67 with one native-only skip; native 12 / 164; final root current263 producer/immutability canary | All producer cases were not rerun after the final schema repair |
| Forward263 retained history and interrupted recovery | Whole fixed SQLite 35 / 758 with five native-only skips; native upgrade 11 / 275 at `71f545bd` | Native prefixes 10, 12, 13, 15, 17, 19 only; other individual prefixes remain SQLite-only |
| Current endpoint and guard/table definition admission after older snapshots | Native identity 9 / 81 at `57c9bf9f`; currentness red 2 / 28 and fixed 2 / 34 at `71f545bd` | Distinct source boundaries retained; final integration contains corrected schema |
| Actual263 same-valid-pair cross-ledger arbitration | Final root native independent-process case: 34 assertions, both candidate positive controls and two observed waits | Actual loser was 40001 / 1213 deadlock; alternative 45000 branch not observed |
| Stale and overlapping retrievals | Four final root native stale/interval cases pass | Controlled synthetic provider facts; no real provider network |
| Legacy seals, future old tail and new database-time record | Legacy transition datasets 2 / 88 on SQLite and within native producer selection | Constructed authentic legacy formats are separate from genuine old-writer replay |
| Original paid period/event and public award receipt survive263 | Genuine old writer `45d12994` 1 / 25, same persisted SQLite upgraded/replayed at `5dc11691` 1 / 103; exact equality hashes | No native continuity or host backup/restore claim; private rows/database excluded |
| Freshness arithmetic and original-cap propagation | Synthetic math 9 / 17; integrated award/proof 32 / 320; Context omission 4 expected failures and restored 4 / 80 | Omission tests target direct terminal refusal; other public checks still refused the mutant |
| Award/reservation terminal and committed closure | Final root award/reservation canaries, two hint/award native cases and committed owner-withdrawal case pass | No real elapsed-time guarantee from synthetic cap narrowing; no C4 consume |
| Reservation lifecycle, ownership and current catalog neighbors | Successful 47-case fixture selection; final real-source reservation retry/replay canary | Synthetic explicit development policy, no commercial terms or activation claim |
| Historical fixture compatibility | 21-case schema/fence selection: 20 through262 cases plus one explicit current263 case; separate through260/262 compatibility selection 5 / 46 | Historical cases never substitute for current263 arbitration |
| Existing billing API, hints, jobs and sweep | Passing full-file leaves preserved inside the two failed root invocations; corrected cursor whole file 8 / 123 | Parent failures remain visible; controlled synthetic same-second pagination on SQLite, no native cursor claim |
| Preparation and entry gates | SQLite 16 / 169 with one native-only skip; that native committed clock case passes in final root selection | Component and final runs retain separate identities |
| Selection and local quality | Discovery 2,068 / 199 of 8,151 / 599 across 24 shards; policy 154; corrected Pint56 | Discovery has no bodies; policy carried across style correction, not rerun at frozen root |

No required final native identity remains pending in the [reconciled list](evidence/uncovered-changed-behaviors.json). Component evidence is focused and mixed-source; this does not establish a complete integrated suite. Independent review and exact-head PR preflight are separate gates owned by root/reviewer.

The [public migration runbook](evidence/schema/runbook-facts-final.md) records drained workers, the exact263 migration, interrupted-prefix recovery, non-destructive down and native DDL limits. Regression peer `lock_wait_timeout=5` is a fixture bound, not a host operating policy.
