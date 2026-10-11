# Importer whole-schema pin: H1 handoff packet (PR #96)

PR #96 (`harness/followup-importer-20261011`, head `2aba5be`) is a **draft and must not merge** in its current
state. The independent review rejected it: `independent-review-2aba5be-DECISION.md` in this folder.

## What is wrong

`CatalogDatabaseEvidence::lockedStorage()` (MySQL branch) lists the schema's base tables, opens each one with
``SELECT 1 FROM `t` LIMIT 0`` to take a shared metadata lock, then lists them again and requires the same set,
all InnoDB. Inside the importer's REPEATABLE READ transaction, the first `information_schema.TABLES` query
creates the snapshot. The second listing therefore reads that snapshot and cannot see an engine change made
while an open was blocked. `race.out` shows HEAD admitting a MyISAM `migrations`. `race-epoch.out` shows HEAD
admitting a MyISAM `catalog_discovery_epoch` that the old pin (`2f1cacc`) refused, so for the epoch table this is a
regression.

## Files

| File | What it is | Status |
| --- | --- | --- |
| `independent-review-2aba5be-DECISION.md` | Full review: H1 (High), M1 (Medium), L1/L2 (Low), conditions for re-review | Reviewer's record; `$SCRATCH/` replaces the session scratchpad path |
| `race.php`, `race.out` | Deterministic race: `LOCK TABLES audit_events WRITE` blocks the first open, then `migrations` goes to MyISAM | Run by the reviewer on a private MySQL 8.0.46 |
| `race-epoch.php`, `race-epoch.out` | Same race on `catalog_discovery_epoch`, HEAD vs old pin | Same |
| `race-candidate.out` | The illustrative fix refuses all three races | Same |
| `probe.php`/`probe.out`, `probe2.php`/`probe2.out` | Metadata-lock behaviour, backtick escaping, queue stall behind a pending ALTER; snapshot behaviour of `information_schema` | Same |
| `run.sh` | Reviewer's MySQL PHPUnit runner (port 33177, hard-coded paths) | Adjust paths/port before use |
| `reviewer-illustrative-fix.diff` | Reviewer's sketch: second listing on a separate autocommit PDO after the opens | **Not production code.** It builds the PDO from host/port only and ignores `unix_socket`, SSL and other connection options. A real fix must reuse the configured connection's DSN/options or restructure the reads |
| `partial-race-test-wip.patch` | Implementer's unfinished tests (applies to `tests/Feature/PersistentCatalogDraftImportTest.php` at `2aba5be`): H1 race for an unlisted table and the epoch, a contention/lock-wait test (M1/L2), a temporary-shadow test (Codex P2) | **Never run to completion.** The implementer was stopped while fixing a known bug: an assertion message evaluated `stream_get_contents($pipes[2])` eagerly and blocked until the child exited. Treat it as a starting point, not as evidence |

`race.php`, `race-epoch.php` and `run.sh` use the old class at `2f1cacc` when given `old`. Recreate it with
`git show 2f1cacc:app/Domain/Migration/CatalogOnboarding/CatalogDatabaseEvidence.php > OldEvidence.php`.
They also hard-code `/home/user/wt-fu-importer`; change it to your checkout.

## Required before #96 can merge

1. **H1:** make the post-open set/engine check non-snapshotted. Options: a separate autocommit read that uses
   the same connection configuration, taken after all opens (authoritative because the shared metadata locks are
   held), or a restructure in which nothing consistent-reads before the opens. Explain the choice.
2. **A MySQL race test** that fails on `2aba5be`'s source and passes after the fix, for both an unlisted table and
   `catalog_discovery_epoch`. The existing PR test changes storage in `TransactionBeginning`, before the first
   listing, so it never reaches this window.
3. **M1:** set a short session `lock_wait_timeout` for the pin and restore it afterwards. Add a runbook/README
   note: do not run an import during migrations or deploys, because every table now holds a shared metadata lock
   for the segment.
4. **L1:** widen the residual text. Triggers on pinned tables the importer does not own (`tracks`, `users`,
   `audit_events` and others) are never compared, and a trigger or routine may write a MyISAM table outside
   `DATABASE()`.
5. **L2 (optional):** report 1205/1213 during the opens as contention instead of `catalog_target_invalid`, or
   document it.
6. **Deferred Codex P2 on #96** (`discussion_r4242704519`): run `shadowed()` over every name in `$pinned`.
7. Correct the docblock, README and CHANGELOG claim "a table created meanwhile changes the set and is refused".
8. Re-run the previously green MySQL cases (schema-drift #10–#12, the transaction pin, dry-run/resume, nested
   importers) and the whole SQLite file (56 tests at `2aba5be`). Update the shard count comments if the
   selection changes.
9. A fresh **independent review** of the new head (AGENTS.md: migration and data-integrity change), then merge at
   the exact head SHA.
