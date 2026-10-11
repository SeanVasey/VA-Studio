# Review: harness/mysql-draft-importer 0b4ff30..76486ff (docs to 27453f0)

**Decision: APPROVE WITH CONDITIONS.** The Codex P1 path is closed. Apply refuses a non-InnoDB table before any write: `standalone()` calls `schema()` (CatalogDraftImporter.php:52/430), and the check is at CatalogDatabaseEvidence.php:117-121. The P2 pin (importer:67) holds on MySQL for the nine evidence tables.

## Findings

- **MEDIUM: no test reaches the pin through `apply()`.** Data set #10 calls `lockedStorage()` directly (test:475-484), and apply is refused earlier by `standalone()`. Mutant p3 deletes the call at importer:67 and survives the whole SQLite file. The P2 "red" result is just "undefined method".
- **LOW: `catalog_discovery_epoch` is written but not pinned.** The tracks trigger `cde_1_insert` (AFTER INSERT) updates it, and it is not in `TABLES` (Evidence.php:22). Probe: with tracks pinned, another session's `ENGINE=MyISAM` on it succeeds, and an epoch increment then survives ROLLBACK. The `schema()` check at the end of the segment (importer:135) refuses the segment, but only after the write. Impact: a spurious bump of the invalidation counter; no evidence rows. README:304 understates this residual.
- **LOW: the SQLite claim is wrong** (Evidence.php:323, README:300). Probe: `PRAGMA journal_mode=OFF` succeeds inside an open deferred transaction until its first write, and in memory a later insert then survives ROLLBACK. Another connection cannot exploit this, because non-WAL modes belong to the connection, and no code between the pin and the first write sets a PRAGMA.
- **INFO: the whole-schema check is sound and not too broad.** `engine => null` (config/database.php:61,83). Migrations set or require InnoDB, and the migrated schema has no non-InnoDB tables. Views are excluded by `TABLE_TYPE`. `lockedStorage()` requires nine InnoDB base-table rows (line 347), which closes a view swap of an evidence table.
- **INFO: no lock-order risk.** `LIMIT 0` opens no InnoDB read view (probe) and takes only shared metadata locks (SR), which DML does not conflict with, and no row locks. The nested/foreign-session case passed on MySQL.
- **INFO: the drift and the tests hold up.** The MySQL drift is faithful, and the per-case `migrate:fresh` rebuilds `audit_events`. On SQLite, the persistent file uses `delete`, `:memory:` uses `memory`, reading the PRAGMA changes nothing, and only `off` loses rollback (probe). The evidence was run on MySQL 8.0.46, not 8.4, and the docs say so.

## Conditions

1. Add an `apply()`-level regression test that drifts storage after admission but before the first write (for example a `TransactionBeginning` listener). On SQLite it sets `journal_mode=OFF`; on MySQL a foreign session makes `audit_events` MyISAM. Assert `catalog_target_invalid` and unchanged evidence, and show that mutant p3 is killed.
2. Pin `DiscoveryEpoch::TABLE` too and require it to be an InnoDB base table, or record it in the README as an accepted residual.
3. Correct the SQLite wording in the docblock and the README.

## Commands and results

- SQLite whole file: 52 tests OK at 08d693c and at 76486ff.
- SQLite drift with mutants: the schema journal check removed is killed; the engine query limited to owned tables survives (MySQL-only); the call removed from apply survives; the `LIMIT 0` pins removed survives; the pin's journal check removed is killed.
- MySQL 8.0.46 on a private port: #10 green at 08d693c and at 76486ff. The owned-only mutant was killed on review. The nested case was OK. Server stopped and datadir removed.
- Probes of journal modes, read-view opening and trigger-table metadata locks were saved as scratch `*.txt` files.
