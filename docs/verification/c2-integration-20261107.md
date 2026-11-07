# C2 integration — deferred-finding batch onto C1 (2026-11-07)

Publication record for the second C batch, merged onto C1 (`claude/c1-mig-a5-defops-20261107`) in this order. Each lane's packet
holds its contract, review records and exact-source results from the Claude harness (2026-11-04/05) or Codex:

| Lane | Branch head | Packet | Review | Evidence recorded in the packet |
| --- | --- | --- | --- | --- |
| ACC-IPV6 (account guard on kernels without IPv6) | `8236f570` | [`catalog-ipv6-absent-20261104/`](catalog-ipv6-absent-20261104/README.md) | APPROVE (`a2144d4a`) | unit 75/1653; native admission 7/42 on a no-IPv6 host |
| REP reporting indexes | `ec8a0578` | [`rep-indexes-20261103/`](rep-indexes-20261103/README.md) | repair review conditions fixed | SQLite 8/408, native 8/293, affected 56/1222, post-fix 2/113 on both engines |
| G1 N-1 result-charset proof | `7ddf2657` | [`g1-token-runbook-20261103/`](g1-token-runbook-20261103/README.md) | conditions fixed | original STOP kept; revised native 1/15; token mutant survives (no kill claimed) |
| CX recovery cursor (FUB-1 F3) | `e37caa35` | [`cx-recovery-20261104/`](cx-recovery-20261104/README.md) | re-review conditions fixed | SQLite 46/499; native 11/162 with real concurrent workers |
| T36 cache backoff | `e0a07604` | [`t36-cache-backoff-20261103/`](t36-cache-backoff-20261103/README.md) | Codex: existing conditions fulfilled | SQLite 43/253 (Codex) |

## Integration changes (this PR only)

- SQLite skip census: G1's and CX's appended entries unioned after C1's, 339 unique entries; the first 150 stay pinned.
- `ReportingIndexesTest` and `ReportingProductionIndexesTest` (REP) branch on the driver and were routed nowhere, which
  `test-phpunit-shards.py` refuses; both join the native include list.
- Native selection recount on the integrated source: 2786 of 10257 cases in 277 files; both count comments updated.

## Verification at the integrated head (Claude harness, PHP 8.4.27)

| Check | Result |
| --- | --- |
| The five CI safeguard suites | all OK |
| Every test file C2 changes, SQLite | SQLITE_RESULT |

Native runs for these lanes are in their packets (each on its lane head plus `a2144d4a`, which C2 now carries itself).
Not run here: the integrated native selection, browser, Foundation CI.
