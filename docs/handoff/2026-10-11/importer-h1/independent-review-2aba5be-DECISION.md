# Independent review: whole-schema storage pin (harness/followup-importer-20261011)

- Reviewed: HEAD `2aba5bede538aacd8493b56585f0fbd3bcd20d0d` (merge of main `592241a` into `06c5b3e`); product diff `git diff 2f1cacc 06c5b3e`.
- Reviewer: Claude (independent reviewer subagent), 2026-10-11. No tracked file was edited, committed or pushed. Worktree is clean and `public/build` is absent.

## Decision: REJECT (for this SHA)

The change is meant to stop a foreign session from changing an unlisted table's engine between admission and the first write. In this form it adds a race window of its own. The second `information_schema` listing runs inside the importer's REPEATABLE READ transaction, and that read is a snapshot taken at the first listing. So the "listed again, must be unchanged and all InnoDB" check cannot see any engine change or table creation that happens between the first listing and a table's open. A deterministic reproduction admits a MyISAM table. That includes `catalog_discovery_epoch`, which is in the evidence set: the old pin refused that case, so the change is a regression there. The fix is small (see H1). Re-review once it lands with a regression test.

## Findings

### H1 (High): the second listing reads a stale snapshot, so the pin admits a table moved to MyISAM during the open loop. Regression for evidence-set tables.

- **Mechanism.** In MySQL 8.0, `information_schema.TABLES` reads the InnoDB data-dictionary tables as a consistent read. The first I_S query in a REPEATABLE READ transaction creates the read view. In the new code that query is the first listing. The `SELECT 1 ... LIMIT 0` opens create no read view. The old code opened the tables first and listed once afterwards, so its single listing was fresh and taken while the MDL was held. That ordering made the old pin correct for the tables it covered.
- **Probe** (`probe2.php`, private MySQL 8.0.46, plain PDO):
  1. A runs list1.
  2. B runs `ALTER TABLE m_t ENGINE=MyISAM` and creates `new_t`.
  3. A opens all tables and runs list2.
  4. Result: list2 shows `m_t` as InnoDB and no `new_t`. `SHOW TABLE STATUS` is also stale. `SHOW CREATE TABLE` shows MyISAM, and a fresh transaction's listing shows MyISAM.
  5. Control: an I_S read made after only a `LIMIT 0` open is fresh.
- **End-to-end reproduction** (`race.php` and `race-epoch.php`). These boot the app against the private DB, run `migrate:fresh` (191 base tables), and call the real `CatalogDatabaseEvidence::lockedStorage()` inside `DB::beginTransaction()`:
  - Session B holds `LOCK TABLES audit_events WRITE`. `audit_events` is the first pinned table in sort order, so the importer blocks on its open after list1.
  - Session C runs `ALTER TABLE migrations ENGINE=MyISAM`, then B unlocks.
  - HEAD: `lockedStorage ADMITTED; migrations is actually MyISAM`.
  - Same race on `catalog_discovery_epoch`:
    - HEAD: `ADMITTED; catalog_discovery_epoch is actually MyISAM`.
    - Old pin (`2f1cacc` class loaded in place of HEAD's): `refused: catalog_target_invalid`.
  - The window is wider than a few milliseconds. It runs from list1 until each table's own open, across about 190 opens, and a foreign session can hold it open at will by locking an earlier table.
- **The comment and README claim is false.** They say "a table created or dropped meanwhile changes the set and is refused". Drops are caught, because the open fails and the PDOException maps to the refusal. Creations are not caught.
- **Required fix.** Make the post-open check authoritative, not snapshotted. One option is to read engines and the base-table set after the opens from a separate autocommit connection. That read is authoritative because the importer's transaction holds a shared-read MDL on every listed table. I checked an illustrative version that built a fresh PDO from the connection config: both races then refuse with `catalog_target_invalid` (`race-candidate.out`). A real implementation must honour `unix_socket`, TLS and the other connection options. Another option is a per-table `SHOW CREATE TABLE`, which `shadowed()` already uses and which is not snapshotted, but parsing the engine out of it needs care because a table COMMENT can contain `ENGINE=`.
- **Required test.** Add a MySQL regression test for this race. Block the open of the first sorted table with `LOCK TABLES ... WRITE` from a foreign session, change a later table's engine, then unlock. Expect `catalog_target_invalid`. The new test cannot exercise this window, because it changes storage in `TransactionBeginning`, before list1.

### M1 (Medium): operational cost of holding MDL on every table, with no bound on the importer's own wait

- **Pending DDL stalls normal traffic.** Each segment now holds SHARED_READ MDL (transaction duration) on all 191 base tables; the probe confirmed this in `performance_schema.metadata_locks`. A DDL on any of those tables waits for the commit, and while its exclusive request is pending, MySQL queues every new shared request behind it. Probe: a plain `SELECT` from another session timed out (1205) behind a pending `ALTER` while the pin was held. In practice, `php artisan migrate` or any ALTER on `jobs`, `cache`, `sessions` or `failed_jobs` during a segment blocks workers and web requests on that table until the segment commits. A segment is at most 25 tracks.
- **The importer's own wait is unbounded.** The importer sets no `lock_wait_timeout`, and the server default is 31,536,000 s. If any table has a DDL holding or awaiting an exclusive lock, the open loop can hang while it holds shared locks on every table opened so far.
- **Deadlocks resolve safely.** MDL deadlock detection returns ER_LOCK_DEADLOCK, which the catch maps to a refusal before any write.
- **Recommendation.** Set a short session `lock_wait_timeout` for the pin, and document in the operator runbook that imports must not overlap migrations or deploys. The README's trade-off sentence states the DDL wait but not the stall of ordinary queries behind a queued DDL.

### L1 (Low): the documented residual is honest but narrower than the real gap

The README residual, a trigger naming a table created after the pin, is accurately described. Two further paths have the same root cause: triggers on the non-owned pinned tables (`tracks`, `users`, `audit_events`, …) are never compared. Both are also not tested:

- A trigger, or a stored routine called from one, on a pinned table that writes a non-transactional table in another schema. Neither the engine admission nor the pin looks outside `DATABASE()`.
- Under H1 as it stands, a table created between list1 and list2.

All of these are closed by comparing triggers on the pinned tables, the remedy the README already names. Recommend widening the residual text to say "any table not pinned, including other schemas".

### L2 (Low): misleading refusal code

The `catch (PDOException)` around the opens also maps lock-wait timeouts (1205) and deadlocks (1213) to `catalog_target_invalid`. That fails safe, but an operator sees "target invalid" for what is contention. Consider a distinct code, or at least document it. The inline comment says only "dropped or renamed".

### I1 (Info): items checked and found sound

- **`SELECT 1 FROM t LIMIT 0` takes the lock.** It acquires SHARED_READ MDL with TRANSACTION duration even though it reads no rows. From a foreign session, `ALTER ... ENGINE`, `CREATE TRIGGER`, `RENAME TABLE` and `DROP TABLE` all failed with 1205 against a pinned table (`probe.out`).
- **Escaping.** Doubling backticks is correct. A table named ``we`ird ta-ble`` was listed, opened, locked and protected. Identifiers cannot contain NUL.
- **Views.** These are correctly excluded, since they have no storage of their own. A view's base tables in this schema are pinned directly.
- **Case sensitivity.** With `lower_case_table_names=0`, names round-trip exactly. With 1, information_schema returns lowercase names that match Laravel's lowercase table names. I did not run lctn=1 or 2; the implementer did not either.
- **Shadowing.** A temporary table can shadow a non-evidence table only on the importer's own connection, so this is not a foreign-session vector.

### Test, red/green and SQLite counterpart

I ran on a private MySQL 8.0.46 (port 33177, own datadir and socket, shut down cleanly, datadir removed). This is not MySQL 8.4 evidence.

| Run | Result |
|---|---|
| New test, HEAD | `OK (1 test, 8 assertions)`, 2:07 |
| New test, `2f1cacc`'s class loaded in place of HEAD's | `Failed asserting that 81 is identical to 80` with "A write to the unlisted table survived the rollback." This matches `red-green.txt` |
| SQLite, whole file, HEAD (given runner) | `OK (56 tests, 324 assertions)` |

- The test genuinely proves red then green for the path it targets: storage changed before the transaction, on a resumed segment.
- The SQLite counterpart is honestly labelled. It turns the journal off, which the unchanged SQLite branch already refused, and `red-green.txt` says it passes on main and is not red evidence.
- **Not run by me:** the other MySQL cases (data sets #10 to #12, the earlier pin test, dry-run/resume, interleave), MySQL 8.4, and lctn=1 or 2.

### Docs, CHANGELOG and count comments

- I re-ran the sharder at HEAD (`--mysql-native-selection`, generated files deleted): `Proved 1821 selected MySQL-native tests in 164 files (of 7796 discovered tests in 562 files)`.
  - The final-verification.yml comment "1821 of 7796" is correct.
  - The `.gitlab-ci.yml` "1821 cases" is correct.
  - `red-green.txt`'s "of 7792" is correct for its base source.
- The CHANGELOG and README describe the code accurately, except for the claim that the second listing detects tables created meanwhile and enforces "set unchanged". The docblock and the inline comment make the same claim. Under H1 that claim is false. Correct it together with the fix.

## Conditions for a future approval

1. Make the post-open engine/set check non-snapshotted (H1) and add the `LOCK TABLES` race regression test. It must be red on HEAD and green after the fix, on MySQL.
2. Fix the docblock, inline comment, README and CHANGELOG wording to match.
3. Bound `lock_wait_timeout` for the pin, or document the stall risk in the runbook (M1).
4. Widen the residual text (L1).

## Evidence files (scratchpad, this folder)

`probe.php`/`probe.out` (MDL, escaping, queue stall), `probe2.php`/`probe2.out` (I_S snapshot), `race.php`/`race.out` (HEAD admits MyISAM `migrations`), `race-epoch.php`/`race-epoch.out` (HEAD admits, old pin refuses), `race-candidate.out` (illustrative fresh-connection recheck refuses both), `OldEvidence.php` (`2f1cacc` class), `run.sh` (MySQL runner).
