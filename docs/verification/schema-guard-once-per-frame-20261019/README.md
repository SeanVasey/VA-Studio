# Schema guard once per owned frame (lane G1)

Lane G1 on branch `harness/schema-guard-once-per-frame-20261019`, from `origin/main` `e3e2a180`. It removes the repeated
schema-ownership proofs that made the existing membership reserve overrun its fixed 60-second budget on native MySQL
(measured by lane C4d, `docs/verification/member-domain-c4d-20261018/README.md`, "Native gap characterization").

This is a local development candidate. Nothing has been pushed, no PR is open and no workflow was dispatched. It
changes the currentness guards of payment-adjacent ledger writers, so it needs an independent review of the exact SHA
before merge. No deadline, cap or budget was raised, no guard was removed from any path, and no test was weakened.

## Result in one paragraph

On the same private MySQL 8.4.11 with the same configuration, the existing test
`MembershipReservationLifecycleTest::test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity`
fails on `main` (reserve 65.1 s, 90,255 statements, rolled back at its deadline) and passes on this branch (reserve
24.5 s, 36,676 statements, committed). The award falls from 48.0 s and 70,253 statements to 17.7 s and 28,075. The
award transaction's business statements (everything except schema checks and savepoints) are the identical multiset on
both sources. Details, method and caveats are under [Measurements](#measurements-before-and-after).

## What can change a proven schema inside one owned frame

An owned frame is one outer InnoDB transaction on one connection and one PDO, with the random savepoint marker of
`MembershipAwardTransaction` (`va_membership_award_<128-bit random>`). Every `admit()` releases and re-sets that marker,
so it fails after any commit, rollback or implicit commit. The question for a remembered proof is: what can make the
state it proved untrue while that marker still exists?

### Facts measured on the private 8.4.11 server

Probe scripts are synthetic (database `g1probe`, never application data). Scripts and outputs are in
`evidence/probes/` (`mdl.php` with `probe-mdl.txt`; `tmp.php` with `probe-temporary-ps-on.txt` and
`probe-temporary-ps-off.txt`; `tmp2.php` with `probe-temporary-rename.txt`; `comment.php`; `isnap.php` with
`probe-dictionary-snapshot.txt`).
Session A opened a transaction and ran one statement; session B, with `lock_wait_timeout=1`, then ran DDL.

| A ran, in its open transaction | B: ALTER, CREATE/DROP TRIGGER on the table, RENAME, DROP, CREATE INDEX, CREATE TABLE with a foreign key to it, ALTER its FK parent | B: CREATE VIEW over it | B: CREATE TRIGGER on another table whose body writes it |
| --- | --- | --- | --- |
| nothing | not blocked | not blocked | not blocked |
| `SELECT * FROM t LIMIT 0` | **blocked** (shared metadata lock held to transaction end) | not blocked | not blocked |
| a consistent read of `t` | **blocked** | not blocked | not blocked |
| `SHOW INDEX FROM t` | **blocked** | not blocked | not blocked |
| `SHOW CREATE TABLE t` | not blocked | not blocked | not blocked |
| `information_schema` reads of `t` | not blocked | not blocked | not blocked |

Same-session statements, inside a transaction holding a savepoint:

- `CREATE TEMPORARY TABLE` keeps the savepoint and the transaction. Every creation path probed increments the session
  counter `Com_create_table` by one, with `performance_schema` on and off: plain, `LIKE`, `AS SELECT`, schema-qualified,
  SQL `PREPARE`/`EXECUTE`, a PDO native prepared statement, `CALL` of a procedure created earlier, and a stored function.
- `ALTER TABLE`, `RENAME TABLE`, `TRUNCATE` and `CREATE INDEX` on a temporary table, `FLUSH STATUS`, `FLUSH LOCAL STATUS`
  (the only resets of the session counters) and `LOCK TABLES` each commit implicitly: the savepoint is lost and the
  earlier insert is durable.
- `DROP TEMPORARY TABLE`, `SET SESSION foreign_key_checks=0` and `USE` keep the savepoint.

Visibility of another session's later DDL inside an open `REPEATABLE READ` transaction (`isnap.php`,
`probe-dictionary-snapshot.txt`): once the transaction has a read view, established by its first consistent read **or
by its first `information_schema` read**, `information_schema.TABLES`, `VIEWS` and `TRIGGERS` keep showing that
snapshot; a view, table and trigger created afterwards stay invisible to them until the transaction ends.
`SHOW CREATE TABLE`, `SHOW CREATE VIEW` and `SHOW CREATE TRIGGER` see them at once. (The code base already relies on
this: `BillingAwardFence` and `BillingSchema` read current definitions with `SHOW CREATE` for this reason.) A
`READ COMMITTED` transaction, such as `IdentityCommittedFrame`, takes a new read view per statement and sees them.

### Consequences

1. **Another session's DDL on a table this transaction has opened waits** until the transaction ends. The existing full
   proofs do not hold these locks for the identity parents. In a debugging run in which both guards ran their
   complete proofs inside one transaction, `performance_schema.metadata_locks` showed shared locks on the seven
   identity tables (opened by the inspection's populated-table probe) and the four membership tables (opened by
   `MembershipSchema`), but none on `users`, `customer_accounts`, `quote_owners` or `audit_events`, which the identity
   inspection reads only through `SHOW CREATE TABLE` and `information_schema`. Another session's
   `ALTER TABLE quote_owners` then succeeded (`evidence/probes/probe-mdl-complete-proofs.txt`). A remembered proof is
   therefore taken only **after** the frame has opened every base table that proof inspects.
   On `main` such an `ALTER` would not even be noticed inside the frame: the identity proof reads the parents' columns,
   indexes and keys from `information_schema`, which keeps the frame's snapshot. Holding the lock is stronger.
2. **Opening does not cover everything the identity proof inspects.** A view, a routine, or a trigger on some other
   table that reaches the identity tables can be created by another session at any time. The identity proof finds
   these with its cross-schema dependents scan, which reads `information_schema`. Under `REPEATABLE READ` (the server
   default, and the configuration measured here) that scan sees the frame's snapshot, so neither the complete proof
   on `main` nor a reuse here can see a dependent created after the frame's first dictionary read; it is refused by
   the committed-read frame (`READ COMMITTED`) and by the next operation. Under `READ COMMITTED` the complete proof
   would see it at the next guard call, so the frame repeats this scan on **every** reuse, which keeps that behavior
   (tested with a `READ COMMITTED` frame below).
3. **Same-session DDL commits implicitly**, except `CREATE TEMPORARY TABLE` (which shadows a table by name) and
   `DROP TEMPORARY TABLE`. Both move the session's `Com_create_table`/`Com_drop_table` counters, which can only be reset
   by a statement that commits implicitly.
4. **Same-session `SET` and `USE` do not commit.** The guards read `foreign_key_checks` and the selected database, so
   both are part of the token (with `sql_mode` and the connection id, which no guard reads, as conservative extras).
5. **What remains outside both the locks and the per-reuse checks** is namespace-only state: a new table, view, index,
   routine or event whose name collides with a reserved name in another table or schema (for example a table called
   `pia_address_unique`, a reserved identity index name). Such an object cannot change any read or write the frame
   performs, because the frame names only its own owned tables and their already-locked triggers. Under
   `REPEATABLE READ`, the complete proof sees only the part of this that `SHOW CREATE TABLE` reveals: a table or view
   carrying a reserved name. That is refused at the terminal pre-commit proof, which is complete and never uses the
   memo (tested below), and on `main` it was refused at the next guard call. Under `READ COMMITTED`, the
   `information_schema` part also moves from the next guard call to the terminal proof. These are the only places
   where detection moves later; see [Residual differences](#residual-differences-and-untested-conditions).

### Is any table touched before its first proof?

The first statement of every owned frame after its marker is `MembershipRows`' constructor, whose first
`assertCurrent()` runs the first membership proof, so the pins happen before any identity or membership row is read in
that frame. `IdentityRows` takes its own first proof (with its own pins) when it is first constructed in the frame, and
each raw identity read still runs the unchanged per-read `assertTable()` check. The pinned set is exactly the set of
base tables whose definition the remembered proofs inspect: `users`, `customer_accounts`, `quote_owners`,
`audit_events`, the seven `production_identity_*` tables and, for the membership guard, the four membership tables.

### SQLite

SQLite has no metadata locks, and its DDL is transactional: the same connection can create, drop or alter any table,
index, trigger or view inside the open transaction without losing the savepoint. The SQLite token is therefore the
whole schema catalog: every row of `main.sqlite_master` and `sqlite_temp_master` (type, name, table and SQL text) plus
`PRAGMA database_list`. Every input of the SQLite proofs is derived from those catalogs (index lists and foreign-key
lists are derived from the stored SQL), except `PRAGMA foreign_keys`, which SQLite ignores inside a transaction, and the
populated-table probe, whose result the two callers do not use. The catalog reads carry no `WHERE` or `ORDER BY`, so they
call no application function or collation. Another connection cannot change what this transaction sees: in rollback
journal mode its write lock waits for this reader, and in WAL mode this transaction keeps its snapshot (the complete
proof would read the same snapshot). The repository's SQLite tests use one in-memory connection. SQLite remains
synthetic rehearsal only (`MembershipRows::context`).

## Design

`App\Domain\Customers\ProductionIdentity\SchemaProofFrame` (new, about 210 lines):

- **Opened** by `MembershipAwardTransaction` right after its marker exists. All six owned frames construct one: award,
  reservation, member activation, activation read, member original and member grant authoring.
- **Registry:** a static `WeakMap<PDO, WeakReference<SchemaProofFrame>>`. Nothing in it keeps a frame alive, and the
  memo itself is an instance property of the frame. The frame's owner witness holds its owner weakly too, so there is no
  reference cycle: when the observer that owns the frame is released, the frame and its memo go with it.
- **`prove(PDO, guard, tables, complete)`:** with no open frame for that exact PDO, it runs `complete` and nothing else.
  That is every caller outside the six owned frames, every committed frame and every migration.
- **Token,** computed before each decision:
  1. `DB::connection()` is the frame's connection, its raw PDO is the frame's PDO, the Laravel level is 1 and the PDO is
     in a transaction;
  2. the owner's witness passes: `MembershipAwardTransaction::admit()`, the existing check that releases and re-sets the
     marker and repeats the frame source checks;
  3. MySQL: `SELECT CONNECTION_ID(), DATABASE(), @@SESSION.foreign_key_checks, @@SESSION.sql_mode` and
     `SHOW SESSION STATUS WHERE Variable_name IN ('Com_create_table', 'Com_drop_table')`; SQLite: the catalogs above.

  If 1 or 2 fails, the frame closes for good and unregisters; if 3 cannot be read, that call is complete and not
  remembered.
- **Decision:** a guard whose stored token equals the current token is reused; on MySQL the identity cross-schema
  dependents scan (`IdentityMigrationOwnership::assertNoDependents`) still runs (meaningful under `READ COMMITTED`;
  under `REPEATABLE READ` it reads the same snapshot as the establishing proof, see consequence 2). Otherwise the frame opens every table
  the proof inspects (`SELECT * FROM <table> LIMIT 0`, the pattern `MembershipSchema` and `BillingSchema` already use),
  runs the unchanged complete proof, recomputes the token and remembers the proof only if every table opened and the
  token did not move.
- **Terminal phase:** each owned observer calls `beginTerminalProof()` at the start of its post-delegate committing
  block. From there to the commit nothing is reused or remembered: every guard proves in full, exactly as on `main`.
- **Rollback path:** `protectFailure()` closes the frame first.

Cost of one reuse on MySQL: 3 statements for the witness, 2 for the token, and 4 dictionary scans (plus at most one
`SELECT DATABASE()`) for the dependents. A complete membership proof issues well over 100 statements.

## Guards changed, and why each stays sound

| Guard | Where it reuses | Why the reuse is sound |
| --- | --- | --- |
| `MembershipSchema::assertOwned` (all `MembershipRows` reads, billing evidence, paid-invoice and eligible-license proofs, and `BillingSchema` owned and retrieval checks) | inside an owned frame, before its terminal phase | Its inputs are the identity proof and the four membership tables' definitions, triggers and namespace. Definitions and triggers of opened tables cannot change (MDL, consequence 1); temporary shadows and session inputs are in the token (3, 4); identity dependents are rescanned, with the same dictionary visibility the complete proof has under either isolation level (2); namespace collisions are refused at the terminal proof (5). |
| `IdentityRows::assertPermanent` (every `ProductionCustomerAccess` lock, proof, history and historical-binding read) | the same, and only when the reader has no committed frame | The same argument for the identity proof. Its connection, transaction-level, other-connection, selected-database and `audit_events` checks still run on every call. Committed frames always run the complete proof. |
| `IdentityMigrationOwnership::inspect` | never: unchanged statements and results | The dependents scan was moved verbatim into a private method that `inspect()` calls at the same point. `IdentityInspectionCostTest` still bounds one native inspection. |
| `BillingSchema` billing-table checks | never | Unchanged. Only its call to `MembershipSchema::assertOwned` can reuse. |

What did not change: the per-read raw checks (`IdentityRows::assertTable`, `MembershipRows::context` and the marker
`admit()`), the frame witness and deadline logic, `MembershipCommitWitness`, `IdentityCommittedFrame`, every migration
and DDL path (`up(true)`), unowned `MembershipRows` (for example `MembershipCustomerIndex`), and every guard outside
the membership frames. Nothing under D1's paths (`app/Domain/Grants/ProductionFree/*`, `app/Http/ProductionFree/*`,
`AppServiceProvider`, routes, bootstrap) was touched.

PR #115's R-4 source admission (`MemberGrantPolicy::currentFor`/`proveCurrent`) binds the policy issuer, rows, native
PDO, transaction and raw configuration; it does not read schema through these guards and is unchanged.
`MemberGrantSourceAdmissionTest`, `MembershipNativeCatalogCurrentnessTest` and `MembershipEligibleLicenseProofTest`,
which include its TEMPORARY-shadow cases, ran natively on this branch (below).

## Measurements before and after

- **Server:** private MySQL 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld`), its own datadir, port 3371,
  `innodb_flush_log_at_trx_commit=2`, `--skip-log-bin`, `sync_binlog=0`, 512M buffer pool, `performance_schema=OFF`,
  `REPEATABLE-READ`: C4d's "config B". No other mysqld ran on the host; the host's unrelated full-suite job did
  (one-minute load 3.1 at the `main` run and 3.6 at the branch run).
- **Sources:** *before* is this worktree at `e3e2a180` before any edit; *after* is `b192f0ef`, whose `app/` tree equals
  the final tested SHA's.
- **Method:** the general log during one run of the test above, summarized per owned transaction (from its first
  `SAVEPOINT va_membership_award_*` to its `COMMIT`/`ROLLBACK`) with C4d's script. "Statements" counts general-log
  entries, so a prepared statement counts three times (prepare, execute, close), as in C4d.

| Owned transaction | `main` `e3e2a180` | This branch `b192f0ef` |
| --- | --- | --- |
| Fixture award | 48.0 s, 70,253 statements (23,353 prepared executions), COMMIT | 17.7 s, 28,075 (9,211), COMMIT |
| Reserve under test | 65.1 s, 90,255 statements (30,002), **ROLLBACK at the 60 s deadline** | 24.5 s, 36,676 (12,018), COMMIT |
| Exact retry | not reached | 20.0 s, 32,941, COMMIT |
| Award replay | not reached | 15.1 s, 25,990, COMMIT |
| Test result | 1 error (reserve deadline), 13 assertions | OK, 21 assertions |
| Identity inspections (`SHOW CREATE TABLE users`), award / reserve | 314 / 412 | 74 / 94 |
| Membership catalog lookups, award / reserve | 945 / 1,107 | 315 / 351 |

The `main` figures reproduce C4d's config-B counts exactly (70,253 and 90,255). The remaining identity inspections are
the complete terminal-phase proofs plus one establishing proof per guard. C4d's "data rows" marker also counts the
membership `LIMIT 0` opens, so it is not a business-statement count; the business comparison is:

- **Award:** after removing schema checks, savepoint operations, `LIMIT 0` opens and session reads, both sources issue
  the **identical multiset** of 505 business statements.
- **Reserve:** `main`'s 804 business statements (it stopped at the deadline) are contained in this branch's 876.

Evidence: `evidence/measure/` (runner `g1-measure.sh`, per-transaction summaries `measure-*.glog.txt` from
`g1-glog.py`, PHPUnit output `measure-*.txt`, `measure-progress.txt`, and `business-sql-base-vs-head.txt` from
`g1-business-sql.py`). The compressed general logs (several MB each) stay in the lane scratchpad
(`g1-mysql/general-*.log.gz`).

## Tests

New: `tests/Feature/ProductionMembershipBilling/MembershipSchemaProofFrameTest.php`, eight cases. Every case runs on both
drivers, with no skips; where SQLite has only one connection, the MySQL leg uses a second session.

| Case | What it proves |
| --- | --- |
| `test_a_temporary_shadow_made_after_a_remembered_proof_is_refused_by_both_guards` | A TEMPORARY `customer_accounts` made after a reuse is refused by both guards; on MySQL a remembered pair costs under a quarter of the complete pair. |
| `test_a_dependent_view_created_after_a_remembered_proof_is_refused_at_the_next_guard` | A view over `production_identity_origins` created after a reuse is refused at the next guard. MySQL: created by another session (holding no lock) while the frame runs `READ COMMITTED`, where a complete proof would also see it. SQLite: same connection. |
| `test_a_session_change_after_a_remembered_proof_sends_the_guards_back_to_a_complete_proof` | MySQL: `SET foreign_key_checks=0` after a reuse is refused. SQLite ignores the pragma inside the transaction. |
| `test_a_remembered_proof_is_never_used_by_another_connection` | A second connection (SQLite: byte-identical catalogs, foreign keys off; MySQL: same database, `foreign_key_checks=0`) with its own frame proves in full and refuses. |
| `test_a_remembered_proof_ends_with_its_transaction` | After the frame's commit, a change only a complete proof sees (SQLite: foreign keys off; MySQL: another session's `pia_address_unique` table) is refused in the next transaction, although the old frame is still referenced. |
| `test_definitions_proven_in_the_frame_cannot_change_under_it` | MySQL: another session's `ALTER` and `CREATE TRIGGER` on `quote_owners` and `ALTER` on `audit_events` wait and time out (1205). SQLite: a same-connection `ALTER TABLE` is refused at the next guard. |
| `test_the_terminal_phase_proves_in_full_after_remembered_proofs` | A namespace collision added by a `TransactionCommitting` listener after the reserve remembered its proofs is refused before commit; nothing is retained. |
| `test_the_award_terminal_phase_proves_in_full_after_remembered_proofs` | The same for the award. |

The driver-branching file is added to `include_files` of `scripts/ci/database-mysql-selection.json`, as the selection
policy requires. Neither skip census changed.

## SQLite results

In-memory SQLite, 152 test files in 13 chunks run one process at a time with the direct PHPUnit command (`APP_ENV=testing
DB_CONNECTION=sqlite DB_DATABASE=:memory:` ..., `public/build` absent), at `848e7f8e`, whose `app/` and `tests/` equal
`092f7713` and the final tested SHA's except the formatting-only Pint change to the new test file noted below. Driver,
chunk list and per-chunk results: `evidence/sqlite/`.

| Chunk | Files | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- | --- |
| s01 `tests/Feature/ProductionMembership/` | 9 | 62 | 241 | 0 | 0 | 3 |
| s02 to s06 `tests/Feature/ProductionMembershipBilling/` (56 files, new file included) | 56 | 406 | 3,504 | 0 | 0 | 35 |
| s07 `ProductionMemberActivation/` and `ProductionMemberGrants/` | 8 | 53 | 520 | 0 | 0 | 7 |
| s08, s09 `ProductionMemberOriginals/` | 21 | 199 | 1,119 | 0 | 0 | 9 |
| s10, s11 every other test file naming `IdentityRows`, `ProductionCustomerAccess` or `IdentityMigrationOwnership` | 28 | 313 | 3,240 | 0 | 0 | 38 |
| s12 Unit member, billing and identity files, `tests/Unit/ProductionMemberGrants/`, `tests/Unit/ProductionMemberOriginals/` | 27 | 480 | 1,044 | 0 | 0 | 0 |
| s13 `MembershipCreditMigrationTest`, `StagingEnvironmentAdmissionTest`, `ProductionEnvironmentTemplateTest` | 3 | 59 | 1,997 | 0 | 0 | 0 |
| **Total** | **152** | **1,572** | **11,665** | **0** | **0** | **92** |

All 92 skips are methods in the reviewed SQLite census `scripts/ci/database-sqlite-skips.json` (data-set cases matched by
method; `evidence/sqlite/sqlite-census-check.txt`). The new file's eight cases ran in s06 with no skip.

## Native MySQL 8.4.11 results

PENDING

## Mutations

Each mutation was applied to a byte copy of the tested tree (lane scratchpad `g1-mut`, no `.git`), the new test file was run
on the stated driver (native runs used a separate disposable database, `vaseyaudio_mutg1`, on the same server), and the
copy was restored and compared byte for byte with the worktree after every run (`restore=clean` in
`evidence/mutations/mutations.log`). Tool: `g1-mutate.py`; runner: `g1-mut-run.sh` (both in `evidence/mutations/`).

| Mutation | Property it removes | SQLite | Native MySQL 8.4.11 |
| --- | --- | --- | --- |
| M1a: MySQL token without the session table counters | memo invalidated on a TEMPORARY shadow (MySQL) | survives (MySQL-only input) | **killed**: `test_a_temporary_shadow_...` |
| M1b: SQLite token without `sqlite_temp_master` | memo invalidated on a TEMPORARY shadow (SQLite) | **killed**: `test_a_temporary_shadow_...` | not run (SQLite-only input) |
| M2: one static memo shared by every frame | memo never shared across connections | **killed**: `test_a_remembered_proof_is_never_used_by_another_connection` | survives: the MySQL token itself is per session (connection id, session counters, `foreign_key_checks`), so the second connection's token never matches |
| M3: token without the owner witness | memo ends with its frame | **killed**: `test_a_remembered_proof_ends_with_its_transaction` | **killed**: same case |
| M4a: `terminal()` does nothing | terminal re-proof | survives (one connection: the listener's change moves the SQLite catalog token anyway) | **killed**: both terminal cases |
| M4b: no `beginTerminalProof()` in the reservation observer | terminal re-proof (reserve) | not run | **killed**: `test_the_terminal_phase_...` |
| M4c: no `beginTerminalProof()` in the award observer | terminal re-proof (award) | not run | **killed**: `test_the_award_terminal_phase_...` |
| M5: no table opening before the establishing proof | definitions frozen by metadata locks | survives (no metadata locks) | **killed**: `test_definitions_proven_...` (the `ALTER` passed) |
| M6: no dependents scan on reuse | new dependents seen at the next guard (`READ COMMITTED`) | survives (catalog token) | **killed**: `test_a_dependent_view_...` (rerun after the test fix below) |
| M7: MySQL token without the session variables | same-session `SET` detected | survives (MySQL-only input) | **killed**: `test_a_session_change_...` |

Every property has at least one killing test; each surviving cell is a driver that does not depend on the removed
mechanism (or, for M2 on MySQL, a second independent layer). One earlier native attempt of M1a, M5, M6 and M7 used a
database name outside the disposable-test admission rule and errored in setup; it is kept in the log and was rerun.

**Invalid first M6 result, kept in the log.** The first native M6 run "failed" in the same way the unmutated test then
failed in the native chunk run (`n23`): its first draft created the view while the frame ran `REPEATABLE READ`, where
neither a complete proof nor a reuse can see it (consequence 2). That was not a kill. The test now runs its MySQL leg
under `READ COMMITTED`; the unmutated test passes and M6 fails it (`mutations.log`, 05:16Z).

## CI safeguards

PENDING

## Residual differences and untested conditions

- **Namespace-only collisions are detected later.** A reserved-name object created by another session while a frame is
  open (consequence 5) is refused at the terminal proof instead of the next guard call (under `REPEATABLE READ`, the
  tables and views `SHOW CREATE TABLE` reveals; under `READ COMMITTED`, also the `information_schema` part). It cannot
  affect the frame's reads or writes, and nothing commits past it (`test_the_terminal_phase_...`,
  `test_the_award_terminal_phase_...`).
- **Pre-existing, not changed here:** under `REPEATABLE READ` every `information_schema`-based check of these guards,
  on `main` as on this branch and in the terminal phase, reads the snapshot taken at the frame's first dictionary read,
  so another session's later trigger, view, routine or definition change of a table the frame has not opened is seen
  only by the committed-read frame and the next operation. This lane's table opening removes the last part of that
  for every inspected table; the rest is recorded as a finding for the guards' owners, not fixed here.
- **New metadata locks.** The frame now holds shared metadata locks on `users`, `customer_accounts`, `quote_owners` and
  `audit_events` from its first proof to its end; on `main` the reserve already locked the first two rows by reads, and
  the identity and membership tables were already opened by the complete proofs. Another session's DDL on those tables
  now waits for an open frame instead of racing it. A migration running concurrently with a member operation therefore
  waits (up to that session's `lock_wait_timeout`). Production runs DDL only at deploy time.
- **The memo inherits the frame's trust boundary.** Like the existing frame, it relies on the random marker being known
  only to the frame. Code that knew it could `ROLLBACK TO` it, which also releases metadata locks taken after it. That
  is the same capability that already defeats the marker-based frame checks.
- **`Com_create_table` as the temporary-table witness** is a documented server status counter, verified on 8.4.11 for
  the paths listed above. Other server versions, forks or proxies that rewrite `SHOW STATUS` were not tested. If the
  counters cannot be read, the guard runs in full.
- **Terminal coverage by test:** the reservation and award observers are mutation-tested. The four member observers
  (activation, activation read, original, authoring) carry the identical one-line call but have no dedicated
  terminal-memo test.
- **Production concurrency:** the measurements are one test on a 4-CPU container with an unrelated load. Contention
  between concurrent members (P1B-1, L2-1, R-5) and a real host (U-02) are not measured here.
- **SQLite file databases** with a second connection were reasoned about, not tested; the repository uses in-memory
  SQLite only.
- Unchanged by this lane and still open: the member renderer under PHP-FPM, the customer index's own 60-second budget
  (unowned `MembershipRows`, no memo) and every activation input.
