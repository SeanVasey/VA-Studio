# C1 integration — MIG, A5, DEF-OPS, C4e and its census onto main (2026-11-07)

Publication record for the first C batch. It contains no new product code: it publishes four lanes Codex prepared, reviewed and
tested, composed in their recorded order (MIG → A5 → DEF-OPS → C4e test gaps → C4 census), merged with `main` `dfc932ad`.
Each lane's own packet holds its contract, independent review records and exact-source results:

| Lane | Source head | Packet | Codex's recorded evidence (its runtime, not re-run here) |
| --- | --- | --- | --- |
| MIG verified-grant gates | `6f441ac7` | [`migration-verified-grant-gates-20261101/`](migration-verified-grant-gates-20261101/README.md) | SQLite 4/34; private Oracle 2/103, including the real two-child authority race |
| A5 display follow-up | `bfbe6a28` | [`a5-display-followup-20261101/`](a5-display-followup-20261101/README.md) | SQLite 8/116 through the original UI protocol |
| DEF-OPS follow-up | `b5d1467b` | [`deferred-ops-followup-20261101/`](deferred-ops-followup-20261101/README.md) | SQLite 5/40; Oracle 7/332; backup 76, 0 skips |
| C4e test gaps | `50aabd2a` | [`c4e-test-gaps-20261103/`](c4e-test-gaps-20261103/README.md) | SQLite 5/93; native 2/53. **N4 (idle-PDO) stays open** |
| C4 census | `6d96042f`, comments `abbd8a9b` | [`c4e-integration-census-20261103/`](c4e-integration-census-20261103/README.md) | 25 listings; 2761 of 10221 cases in 272 files |

The merge with `main` adds only main's own changes since those heads: PR #191's documentation, PR #192's handoff documentation and
its Symfony security lock bump. No lane file conflicted.

## Verification at the merged head (Claude harness, PHP 8.4.27)

| Check | Result |
| --- | --- |
| `test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py`, `test-database-receipts.py` | all OK |
| Native selection recount | 2761 of 10221 cases in 272 files, identical to the committed comments |
| `composer audit` with the vendor rebuilt to the merged lock (`symfony/http-kernel` 8.1.11) | no advisories |
| Every test file C1 changes, plus all `LegacyHistory*` and `History*` migration suites, SQLite | see the result line below |

SQLite, all 15 C1-changed test files plus the history suites at the merged head `aaf58386`: **OK, 188 tests, 3448 assertions**. That includes `PruneExpiredCacheRowsTest`, which fails on `main` `dfc932ad` (lock key order) and is repaired by the DEF-OPS change to it.

Not re-run here: native MySQL for these lanes (Codex's private Oracle results above stand for their exact sources; the merge
changes no lane file), browser, Foundation CI. The lanes' deferred items stay in their packets and the deferred ledger.
