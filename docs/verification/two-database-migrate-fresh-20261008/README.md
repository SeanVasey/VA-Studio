# Two application schemas on one mysqld: `migrate:fresh` (2026-10-08)

Development evidence from the Claude Code harness. This is not final or Foundation
acceptance. No source, test, migration, policy or audit setting was changed; this
directory is the only addition.

## Question

The Free256 independent review (2026-10-08) saw `php artisan migrate:fresh --force`
fail on the second of two databases (`rv256_1`, `rv256_2`) on one private mysqld 8.4.11:

```
2026_10_06_238000_production_track_preparation_packets ... FAIL
Unexpected production capability external or additional table guard; rollback refused before schema changes.
```

The cause is the unfiltered `information_schema.TRIGGERS` / `VIEWS` / `ROUTINES` scans
in `CapabilityMigrationOwnership::mysql()`. They refused another schema's own guards
because their unqualified bodies name `production_track_*` tables.

## Finding

`main` already carries the fix. It came in through PR #50 (`df8126a2`, merged
2026-10-08 05:34 UTC): `5dd4e35b` (classify each dictionary row by its own schema),
`b47de6b6` (complete-identifier qualifier match) and `25a00840` (refuse delimiter-bearing
database names). The full design, audit of every dictionary scan and independent
decision are in `../native-schema-isolation-20261007/`. A foreign-schema trigger, view
or routine now counts as a dependent only when it lives in the selected schema, names
the selected schema as a qualifier (case-insensitive), or uses `PREPARE`. The same rule
covers all three server-wide scanners: `CapabilityMigrationOwnership`, migration 243
`preflightExternalDependencies` and `IdentityMigrationOwnership::inspect`. Every other
`information_schema` trigger/view/routine read in `app/` and `database/` is filtered to
`DATABASE()` or the bound database. This was re-checked with grep at `705a712e`.

The regression the issue asks for already exists as `tests/Feature/NativeSchemaIsolationTest.php`
(native-only; skipped on SQLite):

- `test_same_named_objects_in_a_parallel_schema_do_not_block_a_fresh_migration` copies
  every base table and every trigger (including `ptp_packet_insert`, the 238 guard) into a
  second schema, plus routines, views and a peer-local foreign key. It then runs
  `migrate:fresh` on the selected schema and asserts both catalogs are unchanged.
- `test_a_peer_schema_whose_name_extends_the_selected_name_...` (`-2`, `$x`, `é`).
- `test_dependencies_reaching_the_selected_schema_are_still_refused` covers 30 cases.
  A peer trigger, routine or view naming `` `<selected>`.`<owned table>` `` is refused,
  in lower and upper case, as are a peer `PREPARE` routine, a peer foreign key and local
  objects. Each case keeps its original refusal message and leaves the catalog unchanged.

## Runtime

PHP 8.4.26 (ondrej PPA), PHPUnit 12.5 from the locked Composer graph, and MySQL 8.4.11
(`mysql-8.4.11-linux-glibc2.28-x86_64` from cdn.mysql.com). The MySQL server was a
private disposable daemon on `127.0.0.1:3410` (`evidence/mysqld-up.sh`), never `:3306`.
`public/build` was absent.

## Results

| Check | Source | Outcome |
| --- | --- | --- |
| Two-database reproduction (`evidence/two-db-repro.sh`): `rv256_1`, then `rv256_2`, then `rv256_1` again | `705a712e` (HEAD) | all three exit 0, all 78 migrations DONE. Afterwards both schemas hold 537 triggers each, including `ptp_packet_insert` (`evidence/repro-head.txt`) |
| Same reproduction on `rv256_3`, then `rv256_4` | `9593fcdf` (`main` before PR #50) | `rv256_3` exit 0. `rv256_4` exit 1 at `238000 ... FAIL` with the reported message (`evidence/repro-prefix-9593fcd.txt`). The issue reproduces exactly |
| `NativeSchemaIsolationTest`, native | `705a712e` | 34 tests, 34 passed, 0 skipped. Database `rv_native`; the server also held `rv256_3` (a full pre-fix schema) and the partial `rv256_4` (`evidence/native-NativeSchemaIsolationTest.*`) |
| `NativeSchemaIsolationTest`, SQLite | `705a712e` | 34 skipped, by design (`evidence/sqlite-head-NativeSchemaIsolationTest.txt`) |
| `ProductionTrackCapabilitiesMigrationOwnershipTest`, SQLite | `705a712e` | 23 tests, 17 passed, **6 errors** (`evidence/sqlite-head-*`) |
| Same, with `DB_CONNECTION=mysql` | `705a712e` | 23 tests, 17 passed, **6 errors**, the same set. The test pins its own in-memory SQLite fixture connection (`evidence/mysqlenv-*`) |
| Same, SQLite | `9593fcdf` | 23 tests, **6 errors**, the same set (`evidence/sqlite-prefix-*`) |

The reproduction outputs were written by an earlier revision of `two-db-repro.sh`. In
`repro-head.txt`, the `migrations_ran` count also includes the "Creating migration
table" and "Dropping all tables" lines. In `repro-prefix-9593fcd.txt`, the
`migrations_done` count leaves out the three `0001_01_01_*` migrations. The committed
script counts every migration line. Checked against the raw logs: HEAD ran all 78 of
78 migrations on each database. The pre-fix `rv256_3` ran 75 of 75, and `rv256_4`
stopped after 58.

## Pre-existing, out of scope: the 6 `ProductionTrackCapabilitiesMigrationOwnershipTest` errors

These were not introduced by PR #50: they are identical at `9593fcdf`. They are already
recorded as pre-existing in `../tax-255-20261007/README.md`. Each one is
`Unexpected production capability external foreign key reference` raised by migration
236 `down()`.

Cause, checked against a migrated SQLite database: the test's `setUp()` removes only the
239 and 238 dependents before exercising 236 `down()`. Since the checkout schema
merged, `production_checkout_exemption_authorities`, `production_checkout_exemption_bases`
and `production_checkout_reviews` also hold foreign keys to
`production_track_capability_candidates`. The refusal is therefore correct behaviour,
and the test fixture is stale. Making the fixture account for the checkout dependents
is a separate test change that needs its own review. It was not made here.

## Not tested

- More than two complete schemas, or concurrent (rather than sequential) `migrate:fresh`
  runs against one daemon.
- `lower_case_table_names` 1 or 2 servers. This daemon used the Linux default 0; the
  upper-case qualifier cases still exercise the case-insensitive match.
- The Free256 branch itself. These results apply to `705a712e` and `9593fcdf` only.
  A Free256 branch based before `df8126a2` needs `main` merged in to pick up the fix.
