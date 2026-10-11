DECISION: APPROVE WITH CONDITIONS

# Review: harness/mysql-draft-importer 27453f0..7476fc7 (abcc85a, 7476fc7)

**Reviewed range:** `27453f06ddadcf3bb39f8ddaed900f12bee4c2bf..7476fc708071d51f3c6bea171796c3d742bf3b9d`. MySQL 8.0.46 (`lower_case_table_names=0`) on the private server at 127.0.0.1:33098; SQLite in memory. Direct PHPUnit with the worktree's autoload. HEAD is unchanged and `git status --short` is empty after every mutant.

The MySQL side of both Codex findings is closed and proven red→green. The previous review's conditions are met. The SQLite shadow check is case-sensitive, though SQLite identifiers are not, so the SQLite half of the P1 fix can be bypassed.

## Findings

- **MEDIUM: the SQLite shadow check can be bypassed with a different letter case.** The check is `CatalogDatabaseEvidence::shadowed()`, which runs `... WHERE name IN (...) OR tbl_name IN (...)`. `sqlite_temp_master` compares with BINARY collation, but SQLite resolves identifiers case-insensitively and searches `temp` first.
  - Probe (`sqlite-probe.txt`): each of these leaves the check at 0, and each one hijacks an unqualified statement:
    - `CREATE TEMP TABLE Catalog_Import_Mappings`: an unqualified INSERT lands in the temp table (main 0 rows, temp 1).
    - `CREATE TEMP VIEW TRACKS`
    - `CREATE TEMP TRIGGER ... ON main.TRACKS` (tbl_name `TRACKS`): it deleted the inserted track.
  - Through the importer: data set #11 with the shadow named `Catalog_Import_Mappings` fails on HEAD. `lockedStorage()` admits it ('Non-transactional storage pinned', `sqlite-mixedcase-shadow.txt`), and with the pin assertion skipped, `review()` admits it ('Foreign installed schema admitted: temporary-shadow review', `sqlite-mixedcase-shadow-review-apply.txt`).
  - The repository's own precedent folds case: `MembershipSchema.php:51` and `DiscoveryEpoch.php:63-64` use `strtolower`, and `ConsentMigrationAdmission.php:214` uses `COLLATE NOCASE`.
  - Impact: SQLite only. Production and CI correctness runs are MySQL, where a differently cased temp table does not shadow under `lctn=0` (`mysql-probe.txt`).
- **LOW: the docs are stale or overclaim.**
  - README:47-49 still says a same-session `CREATE TEMPORARY TABLE` over an owned table "is not detected" and that `sqlite_temp_master` "was not checked". That contradicts the new "Codex round on `abcc85a`" section.
  - That section says the SQLite check "also catches temporary triggers on durable tables". This holds only for an exact-case name (finding above).
  - `local-evidence/codex-p4/red-green.txt`:
    - Inside the first-attempt (setup-defect) block, the heading "## Green, MySQL 8.0.46, data set #11" sits over `Errors: 1`. It should be labeled as part of the defective attempt.
    - The "Red, SQLite, source abcc85a" heading has no captured output under it.
- **LOW: a missing participating table now fails with a raw PDOException.** On MySQL, `schema()` calls `shadowed()` before `mysqlObjects()`. With an owned table or `catalog_discovery_epoch` missing, `SHOW CREATE TABLE` throws PDOException 1146 (probe) where `catalog_target_schema_invalid` came before. It still fails closed, but the error contract changed. Return true when the table is absent, or catch 1146 and map it to the domain error.
- **INFO: SUB_PART is correct, and nothing else of equal harm is left uncompared.** All owned indexes are single-column, full-column indexes (PRIMARY, unique and plain), so `SUB_PART => null` is the right expectation. The MySQL happy path passed earlier (codex-p4 log). Probe results on the other STATISTICS columns:
  - A prefix index reports `SUB_PART=1`, and a prefix equal to the full length reports `NULL`, which is harmless.
  - `DESC` reports `COLLATION=D`, and uniqueness is unchanged.
  - An INVISIBLE unique index is still enforced (1062).
  - `INDEX_TYPE` is BTREE only on InnoDB, and a FULLTEXT index affects performance, not correctness.
  - A functional key part has `COLUMN_NAME` NULL plus `EXPRESSION`, and an extra key part adds a `SEQ_IN_INDEX` row. The exact-set comparison already refuses both.
  - Adding `COLLATION` and `IS_VISIBLE` would only make the comparison more exact; it is optional.
- **INFO: the MySQL shadow detection is sound.**
  - `SHOW CREATE TABLE` on an exact-case temporary table returns `CREATE TEMPORARY TABLE` (probe and #11).
  - Under `lctn=0`, a differently cased temp table neither shadows nor is reported (probe).
  - Under `lctn=1/2`, names are folded for both, so it should resolve the temp table. This is reasoned, not tested; this server is `lctn=0`.
  - MySQL has no temporary views or triggers.
  - Placement is right: in `schema()` the check precedes `mysqlObjects()`, and in `lockedStorage()` it precedes the `LIMIT 0` opens and the engine read.
- **INFO: a same-session shadow created by a callback is in the declined class, and the change improves it.** Probe: `CREATE TEMPORARY TABLE` inside an open transaction does not commit implicitly (the rollback removed the earlier row). A shadow that persists to the end-of-segment `schema()` (importer:135) is now refused and the whole segment rolls back. A create-then-drop inside callbacks loses its writes from the durable tables, so the durable `retained()` and `target()` proofs refuse. This is the README's "same-session DDL from callbacks" class, not a new one.
- **INFO: the tests are sound, with these notes.**
  - The MySQL #11 setup (`SHOW CREATE TABLE` without FOREIGN KEY lines, trailing comma fixed) faithfully reproduces Codex's scenario. The test asserts `CREATE TEMPORARY TABLE` before use, and `evidence()` reads through the same connection, so writes into the shadow would be seen.
  - Cleanup is not in `finally`. A failing #11 leaves the temp table on the connection, and the next `migrate:fresh` would drop the temp table instead of the durable one, which could cascade failures in a full-file run.
  - The post-cleanup `schema() === schema()` only proves that `schema()` no longer throws; it could compare against the pre-shadow digest.

## Previous review's conditions (abcc85a)

1. Met. `test_storage_changed_after_admission_but_before_the_first_write_is_refused_by_the_transaction_pin` drives `apply()` with a `TransactionBeginning` listener. It passes on MySQL at HEAD. Mutant p3 (the pin call removed from `apply()`) is killed on SQLite (reproduced: `catalog_target_schema_invalid` instead of `catalog_target_invalid`); the MySQL kill is in codex-p3 and was not re-run here.
2. Met. `catalog_discovery_epoch` is in the pinned set and must be an InnoDB base table (`count($engines) === count($pinned)`). No dedicated red case, as codex-p3 states honestly.
3. Met. The docblock and README wording on SQLite are corrected.

## Conditions

1. Make the SQLite shadow check case-insensitive: `lower(name) IN (...) OR lower(tbl_name) IN (...)`, or `COLLATE NOCASE`, as `DiscoveryEpoch`, `MembershipSchema` and `ConsentMigrationAdmission` already do. Add a mixed-case shadow case (for example `Catalog_Import_Mappings`, or a temp trigger on `main.TRACKS`) that is red on 7476fc7 and green after.
2. Update README:47-49 to the current behavior. Qualify or keep the "temporary triggers" claim consistent with condition 1. Fix the mislabeled "Green #11" heading in `codex-p4/red-green.txt`, or mark it as part of the setup-defect attempt.
3. (Recommended, not blocking) Map a missing participating table in `shadowed()` to the domain refusal, and move the #11 cleanup into `finally`.

## Commands and results

- SQLite whole file at HEAD: OK (55 tests, 317 assertions) (`sqlite-head.txt`).
- MySQL 8.0.46 at HEAD:
  - #11 OK (1 test, 9 assertions)
  - #12 OK (1 test, 6 assertions)
  - transaction-pin test OK (1 test, 5 assertions)
- MySQL with `CatalogDatabaseEvidence.php` from abcc85a and the HEAD tests (file restored with `git checkout`):
  - #11 FAIL: 'Non-transactional storage pinned', so the old pin admits the shadow.
  - #12 FAIL: 'Foreign installed schema admitted: prefix-unique-index review'.
  - Both are red.
- Mutants, each restored:
  - SQLite #11:
    - shadow check removed from SQLite `schema()`: killed (review admitted).
    - removed from SQLite `lockedStorage()`: killed.
  - SQLite pin test with the `lockedStorage()` call removed from `apply()`: killed.
  - MySQL #11 with the shadow check removed from MySQL `schema()`: killed (review admitted).
- SQLite mixed-case shadow through the test: FAIL on HEAD (see MEDIUM).
- Probes: `sqlite-probe.txt`, `mysql-probe.txt`. The MySQL probe used a scratch database `review_probe_7476`, which it dropped.
- `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`: "Proved 1820 selected MySQL-native tests in 164 files (of 7650 discovered tests in 557 files)". This matches both CI comments. The generated files were deleted.
  - The +4 since the old 1816 adds up: data set #10 (1af8c94, after the 0b4ff30 count), the pin test, and #11/#12.
  - The pre-existing warning remains: `PersistentCatalogDraftImportTest.php` has no timings entry.
- Not run: the full file on MySQL, MySQL with `lower_case_table_names=1`, MySQL 8.4, and a dedicated engine-change probe for `catalog_discovery_epoch`. The MySQL server was left running.
