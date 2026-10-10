# Review: lane `importer`, condition commits `fc67774..6137ec9`

Reviewed head `6137ec93e93ed6cb2cb4a5becbebbacf1b11373c`. The worktree is untouched: `git status` is clean and no `phpunit-ci-*` files remain. My mysqld on port 33132 is shut down.

## Decision: APPROVE WITH CONDITIONS

## Scope: pass
- All 14 changed files are owned: the evidence class, the test file, and the lane's verification docs.
- No selection JSON, workflows, contracts, vendor, lockfiles, CHANGELOG, README or CLAUDE.md changed.
- No secrets, absolute paths or real data; all rows are labelled SYNTHETIC.
- The committed review DECISION is byte-identical to the original.

## Findings
1. **LOW: README probe row misattributes its waits** (`README.md:84`). The row now says none of the first-session probe's four waits depends on `FOR SHARE`. That probe's session A did only `FOR SHARE` reads, and the original finding was about the test case, where the importer had also inserted a track and called `lockForUpdate`. My read-only probe (`probe.txt`):
   - With `FOR SHARE`: the `tracks` insert, the `users` update and the DDL each returned 1205.
   - With a plain read: the insert and the update were admitted; only the DDL returned 1205.
2. **LOW: per-table `FOR SHARE` coverage is incomplete.** Mutant mC keeps `FOR SHARE` only on `audit_events` and survives the nested case (14 assertions). Condition 1 as worded is met: mB, with no `FOR SHARE`, is killed.
3. **INFO:** the README's "Not verified" section omits that the new drift case needs CREATE/DROP DATABASE on the CI MySQL user. CI connects as root (`final-verification.yml:183`).
4. **INFO:** if a run dies before teardown, the leftover `<db>_catalog_peer` schema makes the next run fail its absence assertion (fails closed; drop by hand).
5. **INFO:** with `lower_case_table_names` set to 1 or 2 and a mixed-case database name, `REFERENCED_TABLE_SCHEMA` may not equal `DATABASE()`, which refuses a valid schema (fails closed). Linux and CI use 0.

Delta correctness:
- The new field's position matches the SELECT (`:132`). Its expected value (`:204`) is `$session[5]`, already proved equal to the configured database (`:110`).
- The SQLite branch is unchanged.
- The SQLite counterpart is not vacuous: mE is killed only by data set #9.
- Teardown runs even when the case fails: no peer schema remained after my failing mA run.

## Conditions (owned files only)
1. In `docs/verification/mysql-draft-importer-20261010/README.md`, reword the probe row at line 84 so the reviewer's lock analysis applies to the test case, not the probe. Either say the probe's session-A setup was not recorded, or say a read-only session A blocks the `tracks` insert and the `users` update through `FOR SHARE`.
2. In the same README's "Not verified" section, add three facts about `foreign-referenced-table`:
   - It needs CREATE/DROP DATABASE on the CI MySQL user.
   - It has not run in hosted CI.
   - A killed run leaves a peer schema to drop by hand.

Optional: add a foreign-session `UPDATE` of a pre-existing `tracks` row that expects 1205; this kills mC.

## Verified by running
- SQLite, direct runner, whole file: OK (51 tests, 293 assertions), no skips. Case names and assertion counts match `sqlite-final-suite.junit.xml`.
- MySQL 8.0.46, private server:
  - All drift data sets: OK (10 tests, 63 assertions); per-case counts match the committed JUnit.
  - Nested case on the final code: OK (14 assertions).
- Mutants (poison confirmed the override was live):
  - mA (`fc67774` evidence class), drift #9: **killed**.
  - mB (no `FOR SHARE`), nested case: **killed**; only the `audit` outcome changed, to admitted.
  - mC (`FOR SHARE` only on `audit_events`): **survived**.
  - mE (SQLite branch ignores the repointed reference): **killed** by #9 only.
- Committed MySQL suite JUnit, recomputed: 51 distinct cases, 365 assertions, no failures, errors or skips.
- Self-tests: 52 OK and 50 OK. Sharder: 1816 tests in 164 files, with this file in shard 7; generated files deleted.
- Pint `--test`: passed.

Not reproduced: the full MySQL suite and MySQL 8.4.

## AGENTS.md invariants
The delta touches drafts and evidence only; no money, grant, consent or download paths. Immutability and drift refusals hold, now stricter. Source IDs and dry-run digests are preserved; the MySQL digest changes by design.
