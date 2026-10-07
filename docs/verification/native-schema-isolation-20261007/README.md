# Native schema isolation and identity inspection cost (2026-10-07)

Development evidence from the Claude Code harness. This is not final or Foundation
acceptance. No deadline, authorization lifetime, policy, refusal floor or audit setting
was changed. Nothing was pushed.

| Item | Value |
| --- | --- |
| Branch | `harness/native-schema-isolation` (local) |
| Base | `e86381bf139b4351bba534f3fe0b26f4f8efbdd0` |
| Isolation fix | `5dd4e35b` `fix(migrations): isolate server-wide dependency scans to the selected schema` |
| Identity cost | `2bbc8f8a`, `58baafbb`, `489369c7` (`perf(identity): ...`) |
| 253 source (coordinator request, cherry-picked unchanged) | `49b64262` (from `709fbd45`), `581ac049` (from `3946afda`), `c7f9987b` (from `8773b720`) |
| Tested head | `c7f9987b` (this README is the next commit; documentation only) |

Runtime: PHP 8.4.26 through a runner that points PHPUnit at the worktree's own
Composer autoload (`$GLOBALS['_composer_autoload_path']`; `vendor/bin` is a symlink
into the main checkout). MySQL 8.4.11 in two places: the shared daemon
`127.0.0.1:3306` (schema `vaseyaudio_trigscan`, with other lanes' schemas present), and
a private disposable daemon `127.0.0.1:3410` (`mysqld --no-defaults
--initialize-insecure`, scratch datadir, `performance_schema` history and, for profiles
only, the general log). The host had 4 cores at load average 5–9 from other lanes
throughout, so durations are noisy; statement counts are not.

## 1. The failing check

Native `migrate:fresh` stopped at
`2026_10_06_238000_production_track_preparation_packets`:

```
Unexpected production capability external or additional table guard; rollback refused before schema changes.
```

`CapabilityMigrationOwnership::mysql()` (used by migrations 236 `down`, 238 `up`/`down`,
239 `up`) ran

```sql
select * from `information_schema`.`TRIGGERS`
```

with no schema filter. It then refused any trigger whose `ACTION_STATEMENT` named an
owned table, wherever that trigger lived. Another lane's schema holds the same 238
guards (`ptp_packet_insert`, `ptp_line_insert`, ...), whose unqualified bodies name
`production_track_preparation_packets`, so the check refused. The same method also ran
`select * from information_schema.VIEWS` and `... ROUTINES` server-wide.

Reproduced deterministically at the base: migrate the selected schema, copy its
structure with `mysqldump --no-data --triggers --routines` into a throwaway
`vaseyaudio_trigscan_other`, then run `php artisan migrate:fresh --force` on
`vaseyaudio_trigscan` → `238 ... FAIL` with the message above (`evidence/repro-base.txt`).

### Every dictionary scan audited

All `information_schema` reads in `app/` and `database/` were inventoried, including
dynamically built catalog names. Three read triggers, views or routines across the
whole server:

| Owner | Server-wide reads | Runs in |
| --- | --- | --- |
| `app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php` | `TRIGGERS`, `VIEWS`, `ROUTINES` | 236 down, 238 up/down, 239 up |
| `database/migrations/2026_10_07_243000_inquiry_notification_intents.php` (`preflightExternalDependencies`) | `TRIGGERS`, `VIEWS`, `ROUTINES` | 243 up/down |
| `app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php` (`inspect`) | `SELECT * FROM information_schema.TRIGGERS`, `SELECT VIEW_DEFINITION FROM information_schema.VIEWS`, `SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES` | 247 up/down **and every `IdentityRows` / `IdentityHistoricalPlainRows` assertion at runtime** |

Every other read filters `TABLE_SCHEMA`/`TRIGGER_SCHEMA`/`ROUTINE_SCHEMA`/
`CONSTRAINT_SCHEMA`/`EVENT_SCHEMA` to `DATABASE()` or the bound database
(`DiscoveryEpoch`, `SitemapSchema`, `ConsentMigrationAdmission`, `SuppressionSchema`,
`CheckoutSchemaInstaller`, `ServiceProjectSchema`, `FreeGrantSchema`,
`AttachmentSchema`, `PrivateDraftSchema` and the migrations). The
`KEY_COLUMN_USAGE ... WHERE REFERENCED_TABLE_SCHEMA = <selected>` scans already
looked only at foreign keys pointing at the selected schema, which is their purpose.
They were left unchanged.

## 2. Fix (`5dd4e35b`)

The three scans still read the whole server, so a dependency from another schema is
still seen. Each row is now classified by its own schema:

- **Object in the selected schema:** the original rule. Any trigger, view or routine
  body that names an owned table is refused, and so is any extra trigger on an owned
  table.
- **Object in another schema:** MySQL resolves unqualified names in a trigger, routine
  or view to that object's own schema. Another schema can therefore reach the owned
  tables only by naming the selected database, or through dynamic SQL. Such an object
  is refused when its body names an owned table **and** either names the selected
  database (`vaseyaudio_x.table`, `` `vaseyaudio_x`.`table` ``; view definitions are
  always stored qualified) or contains `PREPARE`. Triggers and stored functions cannot
  use dynamic SQL. The `PREPARE` rule keeps a procedure that builds the qualified name at
  run time refused, as it was before.
- Unreadable (`NULL`) definitions are still refused, in every schema.
- Foreign-key scans are unchanged. That includes the identity and membership floors
  that refuse an owned table's FK into a foreign database's same-named `users(id)`.
- SQLite paths are untouched.

`dependsOn()` was added once per owner: the shared `CapabilityMigrationOwnership`,
`IdentityMigrationOwnership` and the self-contained 243 migration.

### Regression test

`tests/Feature/NativeSchemaIsolationTest.php` is native-only and listed in
`scripts/ci/database-sqlite-skips.json`. It has two parts.

1. `test_same_named_objects_in_a_parallel_schema_do_not_block_a_fresh_migration`
   migrates, then clones every table (`CREATE TABLE ... LIKE`) and all ~458 guards, with
   their unqualified bodies, into `<db>_other`. It adds a same-named procedure and view
   per guarded table and a same-named FK, then runs `migrate:fresh` again. Both catalogs
   must be unchanged afterwards. The peer schema is created only if absent, and is
   dropped in teardown.
2. `test_dependencies_reaching_the_selected_schema_are_still_refused` covers 24 cases:
   the capability helper (via 239, which has no later dependent), the 243 inquiry
   migration and the 247 identity guard, crossed with a peer trigger, routine or view
   naming `<db>.<owned table>`, a peer dynamic-SQL procedure, a peer FK into the owned
   table, and a local trigger, routine or view. Each first proves that the unchanged
   graph is admitted. It then asserts that `up()` (and `down()` where one exists)
   refuses with the **original exact message**, and that the selected catalog is
   unchanged.

Results:

| Run | Result |
| --- | --- |
| Base `e86381bf` code, native shared 3306 | 25 tests, 233 assertions, **1 error**: the isolation case, `Unexpected production capability external or additional table guard` at migrate. All 24 refusal cases pass, which proves the base refuses them too. |
| Fix, native shared 3306 (`5dd4e35b`, then head `c7f9987b`) | **25 / 235 OK** (2:19, then 123 s) |
| Mutation: drop the `PREPARE` rule | 1 failure (`capability: peer dynamic routine`) |
| Mutation: classify by schema only (no qualified-name rule) | 4 failures: peer trigger, routine, view and dynamic routine |
| SQLite | 25 skipped (census) |

### Archived membership canary

The canary is `docs/verification/membership-257-258-composition-20261007/MembershipForeignSchemaCanaryTest.php`
on `origin/harness/membership-257-258` (`de6ae38a`). It is byte-identical to the
`cloud-membership-preparation-independent-20261007` copy, SHA256
`72648803519da41d025222e000ecfeceef051ca3e0cdcd0dd01c4dbdbcdaca8a` before and after each
run. It ran unchanged in a throwaway worktree of that branch, with this branch's commits
applied uncommitted (`cherry-pick -n`; the three guard files are byte-identical to the
branch). It ran on the **shared** 3306 daemon while `vaseyaudio_features253`, `_paid252`,
`_member257`, `_review`, `_test` and `_trigscan` were present. The canary hard-codes
`vaseyaudio_service_review`. A script created that schema and
`vaseyaudio_trigscan_canary_foreign` (with `users(id BIGINT UNSIGNED PRIMARY KEY)`), then
dropped both in a `trap`.

| Tree | Result |
| --- | --- |
| Patched | **1 test / 7 assertions OK.** `refused: true`, reason `Production membership preparation unavailable.`, foreign reference `vaseyaudio_trigscan_canary_foreign.users(id)` recorded, before equals after. This matches the recorded successor result (1/7/0/0/0). |
| Unpatched, same server | 1 error at setup migration 238: `Unexpected production capability external or additional table guard`. This is the reason the earlier lanes needed private daemons. |

After cleanup: 0 matching schemata, 0 `KEY_COLUMN_USAGE` rows referencing the foreign
schema, 0 `mysql.db` grants. Root was used, so no user or GRANT was created
(`evidence/canary-*`).

## 3. Identity inspection cost (coordinator addition)

`IdentityMigrationOwnership::inspect()` runs on every identity assertion. Changes,
with every refusal floor kept:

- `2bbc8f8a`: `DATABASE()` is read once per inspection. It was read per examined
  trigger, key, view and routine row. `5dd4e35b` had added it per view and routine row,
  raising the count from 876 to 1,463; this commit removes that regression. Each per-name
  lookup class is one statement: the schema's rows are materialized once as a `NO_MERGE`
  CTE, and each `UNION ALL` branch applies the **unchanged** single-name predicate,
  parameter and collation. Only the read columns are projected.
- `58baafbb`: the per-table structural reads (`COLUMNS`, `STATISTICS`, FK join,
  constraint list, parent trigger lists) and the exact-name namespace probes are batched
  per inspection through the same helper, keeping each `ORDER BY`. The constraint count
  comes from the same rows.
- `489369c7`: the CTE is prefiltered with `<lookup expression> IN (<names>)`. That is the
  disjunction of the branch equalities, so it keeps the same rows, including `LOWER()`
  case and accent aliases.

The per-name `SHOW CREATE TABLE` temporary-alias proofs (about 80 per inspection) are
unchanged. They are now most of the remaining statements.

`tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php` is native-only and in
the census. It migrates, checks that one inspection returns exactly the same admission
as the ordinary connection, and bounds the statements sent by a counting `PDO`
(`query`/`prepare`/`exec`) at **110**.

| Identity source | Statements per inspection (counting PDO, private 8.4.11) | Wall |
| --- | --- | --- |
| `e86381bf` (base) | 876 (red) | 705 ms |
| `5dd4e35b` (isolation only) | 1,463 (red; 2,579 on shared 3306) | 527 ms |
| `2bbc8f8a` | 140 (red at bound 110) | 292 ms |
| `58baafbb` / `489369c7` | **104 (green)** | 282 ms (236 ms on shared) |

The native alias guard (`test_native_dictionary_unicode_guard_alias_on_foreign_table_is_refused`,
trigger `pi_addresses_insért`) and every other identity migration and admission case
pass at the head (section 5).

### 253 account features (acceptance: nine-case filter within the unchanged 10 s)

The 253 owner's checkpoint selection is the nine cases in DECISION.md plus the final
postcommit case: 10 cases in `tests/Feature/ProductionFeatures`, using the exact `--filter`
in `evidence/f253-run.sh`. It ran on the private 3410 daemon (schema
`vaseyaudio_trigscan_f253`). The 10 s feature deadline is unchanged.

| Source | Result |
| --- | --- |
| Reviewer receipt (base identity, isolated 8.4.11) | 1 pass / 9 fail-closed 503 at `initialize` |
| `58baafbb` composed with 253 (throwaway worktree) | **10 / 115 OK**, 1,581 s |
| Head `c7f9987b` (253 cherry-picked onto this branch) | **10 / 115 OK**, 1,051 s |
| SQLite, owned 253 at head | 86 tests (82 + 4 unit) / 766 assertions, 3 native-only skips, equal to the reviewer receipt |

One `ProductionListeningLibrary::initialize` was measured with temporary session-status
counters (throwaway worktree only), on case
`test_regular_begin_commit_and_aftercommit_callback_order_is_preserved`:

| Identity source | Prepared statements | Wall | Result |
| --- | --- | --- | --- |
| Reviewer receipt (base) | 15,525 | 6.1–6.6 s by commit, then deadline | 503 |
| `5dd4e35b` | 23,150 | 11.0 s | `ProductionFeatureException` (deadline) |
| `58baafbb` | 3,225 | 9.1 s | pass |
| `489369c7` | 3,225 | **8.2 s** | pass |

The margin is under 2 s on this loaded host. In the remaining 8.2 s, 18 identity
inspections per initialize account for most of the time, about 1.2 s of it in the
`SHOW CREATE TABLE` alias proofs (`evidence/f253-initialize-profile.txt`).

**Per-frame cache in `ProductionFeatureContext`: not implemented.** Acceptance was met
without it, and it would weaken a refusal. The proposed reasoning was "on MySQL any DDL
already breaks the held savepoint". That holds only for DDL in **this** session. DDL
from another session commits independently and never touches our savepoint. Metadata
locks protect only the tables this transaction has already read. A concurrent session
can still create a trigger, view or routine on another table, or change an untouched
dependency, between construction and the pre-commit proof. After commit nothing is
locked before the post-commit proof. Today the repeated `assertHeld` catches those
changes. A per-frame memo would not. The proposal at the end describes a cache that
keeps this check.

### paid252 (acceptance: download journey inside the unchanged 60 s authorization)

`origin/harness/paid252-composition` (`054ab782`, `5cb42829`, `72fb2301`, `a3a9f5d2`) was
applied with `cherry-pick -n` onto `2bbc8f8a` in a throwaway worktree, with each later
identity version copied in. It ran on the private 3410 daemon (schema
`vaseyaudio_trigscan_paid`). Timings come from temporary `fwrite(STDERR)` marks in
`PaidGrantCommands::run` (throwaway only). Statement counts come from the general log.

| Identity source | authorize #1 | authorize #2 | redeem #1 | redeem #2 | Result |
| --- | --- | --- | --- | --- | --- |
| paid252 lane receipt (base identity, their private 8.4.11) | 50.8 s / 70,205 stmts | | 29.8 s / 38,591 | | 410 |
| `2bbc8f8a` | 16.7 s / 8,088 | 17.9 s | 15.1 s / 8,038 | refused at 8.5 s | 403 |
| `58baafbb` | 15.5 s / 6,468 | 16.5 s | 15.6 s / 6,472 | refused at 9.5 s | 403 |
| `489369c7` | 15.3 s | 13.8 s | 12.6 s | refused at 18.1 s | 403 |

**Acceptance is not met.** `PaidGrantDownloadJourneyTest::test_exact_paid_master_and_original_pdf_...`
still errors with `Paid grant request unavailable.` (403). The cause is
`IdentityException` at `IdentityHistoricalPlainRows.php:41` and
`IdentityOriginalCommitWitness.php:178` once the 60 s authorization, which starts about
5 s into the first authorize, has run out. The test spends authorize, idempotent
authorize, redeem and redeem-replay inside that window. Each paid command still issues
about 6,500 statements, and about 44 of them are full identity inspections. In one
authorize, 3,951 of 6,468 statements are the identity `SHOW CREATE TABLE` alias proofs,
and 417 are `SELECT DATABASE()` from non-identity code (`evidence/paid-authorize-profile.txt`).
The paid commands need roughly another 1.5–2× reduction, and inside the floors the only
remaining lever is how often a full inspection runs. Under the stop rule this goes to
the identity owner as the proposal below rather than a wider change here. The full
5-case file was not rerun after the first case kept failing on budget.

## 4. Proposal for the identity and 253 owners (not implemented)

A cache that keeps the cross-session check, for `IdentityRows`,
`IdentityHistoricalPlainRows` and `ProductionFeatureContext::schema()`:

1. Keep one full inspection per physical frame, captured on the frame's first
   assertion.
2. On each later assertion in the same frame, run a short **revalidation proof** instead
   of the full inspection:
   - **Same-session DDL** (which includes every temporary table): read the session
     counters `Com_create_table`, `Com_drop_table`, `Com_alter_table`,
     `Com_rename_table`, `Com_create_trigger`, `Com_drop_trigger`, `Com_create_view`,
     `Com_create_procedure`, `Com_create_function` and `Com_create_index` in one
     `SHOW SESSION STATUS` and require them unchanged. A session-private temporary
     alias can only be created by this session. First prove natively that these
     counters also count statements run inside stored programs and through prepared
     statements; if either is false, keep the per-name `SHOW CREATE TABLE`.
   - **Cross-session DDL:** one `information_schema` digest of the selected schema's
     `TABLES.CREATE_TIME/UPDATE_TIME`, `TRIGGERS.CREATED` and `ROUTINES.LAST_ALTERED`,
     plus the server-wide qualified-dependency scan from section 2. Require it equal to
     the digest at capture.
3. Always repeat the full inspection after commit, or at least the cross-session digest
   and the qualified-dependency scan, because commit releases every metadata lock.

This would take a paid command from about 44 full inspections to one plus about 43
proofs of 2–3 statements each, and turns per-call cost into per-frame cost. It changes
what each proof establishes, so it needs an owner decision, its own adversarial tests
(concurrent-session DDL between proofs, and a temporary table created by a callback
inside a stored procedure) and identity review.

## 5. Commands and results at the head `c7f9987b`

Native (`evidence/final-native-run.sh`):

Recorded by `evidence/final-native-summary.txt` (tree `c7f9987b`, dirty=0, start
2026-10-07T17:22:47Z, `done` line 18:52:01Z). Every selection ran in its own PHPUnit
process against the shared daemon `127.0.0.1:3306` (MySQL 8.4.11), schema
`vaseyaudio_trigscan` (`APP_ENV=testing DB_CONNECTION=mysql`), with the worktree's own
autoload. No private daemon was used for this run. The shared daemon later disappeared in
a worker restart, so the run was reused and not repeated. Per-selection output is
`evidence/final-native-<selection>.txt` (ANSI removed) and `.junit.xml`. Tests,
assertions, failures, errors and skips come from the JUnit files.

| Selection | Exit | Tests / assertions | Failures / errors / skips | Wall (s) |
| --- | --- | --- | --- | --- |
| `tests/Feature/NativeSchemaIsolationTest.php` | 0 | 25 / 235 | 0 / 0 / 0 | 123 |
| `tests/Feature/ProductionIdentity/IdentityInspectionCostTest.php` (104 statements, bound 110) | 0 | 1 / 6 | 0 / 0 / 0 | 94 |
| `tests/Feature/ProductionIdentity/ProductionIdentityMigrationTest.php` | 0 | 5 / 19 | 0 / 0 / 0 | 299 |
| `tests/Feature/ProductionIdentity/ProductionIdentityDependencyAdmissionTest.php` | 0 | 9 / 48 | 0 / 0 / 0 | 548 |
| `tests/Feature/ProductionIdentity/ProductionIdentityRuntimeTest.php` | 0 | 11 / 35 | 0 / 0 / 0 | 843 |
| `tests/Feature/ProductionIdentityAdapters/ProductionIdentityCommittedFrameTest.php` | 0 | 14 / 81 | 0 / 0 / 0 | 1,281 |
| `tests/Feature/ProductionIdentityAdapters/IdentityHistoricalCommittedReceiptTest.php` | 0 | 17 / 80 | 0 / 0 / 0 | 1,262 |
| `tests/Feature/ProductionBuyerAssentObservationMigrationTest.php` | 0 | 4 / 24 | 0 / 0 / 0 | 233 |
| `tests/Feature/ProductionFeatures/ProductionFeatureNativeAdmissionTest.php` | 0 | 3 / 65 | 0 / 0 / 0 | 156 |
| `tests/Feature/InquiryNotificationMigrationTest.php` with the four-method `--filter` in the script | 0 | 9 / 137 | 0 / 0 / 0 | 515 |
| **Total** | all 0 | **98 / 730** | **0 / 0 / 0** | 5,354 (about 89 min) |

All ten selections passed with no skips. The script runs them in sequence on a host at
load average 5-9, so the wall times are noisy. The inquiry selection is the four named
methods only (nine test cases after data providers), not the whole file.

SQLite (`phpunit.xml` defaults):

| Selection | Result |
| --- | --- |
| `tests/Feature/NativeSchemaIsolationTest.php` | 25 skipped (native-only, census) |
| `tests/Feature/ProductionIdentity` | 56 tests / 257 assertions, 8 skipped |
| `tests/Feature/ProductionIdentityAdapters` | 53 / 316, 2 skipped |
| `tests/Feature/ProductionFeatures` + `tests/Unit/ProductionFeatures` | 82 / 745, 3 skipped + 4 / 21 |
| `tests/Feature/ProductionAccountFeatures` | 3 / 65 |
| `ProductionTrackPreparationPacketMigrationTest` | 23 / 326 |
| `ProductionBuyerAssentObservationMigrationTest`, `ProductionBuyerAssentObservationsTest` | 4 / 24, 22 / 82 |
| `InquiryNotificationMigrationTest` | 37 / 546, 4 skipped |
| `ProductionTrackCapabilitiesMigrationOwnershipTest` | 23 / 126, **6 errors, pre-existing**: the same 6 errors at base `e86381bf` with this branch's code stashed (`evidence/sqlite-base-capabilities.txt`). The 236 rollback cases refuse with `external foreign key reference` because 238's lines table already references the 236 tables in a full migrate. That is outside this change. |

Other checks: Pint `--test` on every changed PHP file passed. `scripts/ci/test-database-receipts.py`
(34 OK), `test-focused-tests.py` (50 OK) and `test-phpunit-shards.py` (36 OK) passed.
`scripts/ci/phpunit-shards.py` itself could not run in this worktree: its PHPUnit
discovery loads the main checkout's autoloader, which is a harness limitation and not a
result.

## 6. Cleanup

- `vaseyaudio_trigscan_other` was created for the reproduction and dropped. The test's
  own peer schema is created and dropped per case, and none remains.
- `vaseyaudio_service_review` and `vaseyaudio_trigscan_canary_foreign` were dropped by the
  canary script's `trap`, with the post-cleanup checks above.
- No other lane's schema was dropped or altered.
- The final native run (section 5) used the shared 3306 daemon, which this lane did not
  start and did not stop. At the time of the README it was no longer running (a worker
  restart ended it; `pgrep -af mysqld` is empty and nothing listens on 3306 or on the
  lane's private 3410). The lane's schema on the shared daemon (`vaseyaudio_trigscan`)
  is therefore not reachable, and its state could not be queried afterwards. The private
  3410 daemon, which held `vaseyaudio_trigscan_f253` and `vaseyaudio_trigscan_paid`, is
  also gone. The tests create `<db>_other` only transiently and drop it in teardown.
  This README does not claim a post-run `SHOW DATABASES` for either daemon. The last
  such check is the canary's post-cleanup block above.
- No private daemon was started to finish this README, so there is no new datadir to
  remove. Other lanes' stopped-daemon datadirs under the session scratchpad were left
  alone.

## 7. What remains

- The paid252 60 s journey: blocked on the section 4 owner decision. No deadline was
  changed.
- The 253 initialize margin is under 2 s on this host. Treat 253 native acceptance as
  passing but fragile until the section 4 cache or a quieter host proves more margin.
- `CheckoutSchemaInstaller`, `ServiceProjectSchema` and `FreeGrantSchema` scan only the
  selected schema for views, triggers and routines, so a qualified dependency from
  another schema is not seen there. That predates this change and was left alone (scope).
- The existing native-only identity skips (for example
  `test_native_dictionary_unicode_guard_alias_on_foreign_table_is_refused`) are still
  not in the SQLite census. This branch adds only its three new methods.
- Full native directories and Foundation CI were not run (cost policy).
