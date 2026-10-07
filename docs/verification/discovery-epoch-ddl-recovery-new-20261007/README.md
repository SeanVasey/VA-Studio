# New PR25 partial-DDL recovery repair

This is new work on published PR25 head
`f713ce641cb043c9171c4a3b35c2e666d2bf146e`, addressing
[finding discussion_r4203470722](https://github.com/SeanVasey/VA-Studio/pull/25#discussion_r4203470722).
No unpublished predecessor repair was recovered or used. The corrected executable
source is **`ed285b6a2b34bc1a2a2c91c42b64d68f317965af`**, tree
`0d22cdda957ab72b4430c7029c2f2b8a1fd990c8`. Following evidence commits change no
executable bytes. Root owns integration and independent migration review.

## Reproduced defects and retained feedback

On the unchanged published migration (blob
`7e91110c78ae038fcc2c071da5a993830708ce58`), real Laravel Migrator execution creates
the native table, singleton seed and first guard; a primary-PDO fault then throws
immediately before the second CREATE TRIGGER. Completed MySQL DDL/seed remain,
Laravel records no migration, and genuine Artisan retry errors
`Discovery table collision; installation refused before DDL.` The retained native
red is 1 case / 3 assertions / 1 error, 9,471 ms. This is a deliberate PHP
statement-boundary interruption on genuine MySQL8.0.46, not a server permissions
error or a claimed MySQL8.4 run. A separate SQLite red is 1 / 3 / 1 error,
398 ms, with `Discovery identity collision; installation refused before DDL.`
Both raw console/JUnit results remain; the native harness is preserved verbatim.

Independent review of predecessor `6a5da20d9cd2ab078d79b05288d583436cc731a0`
found two real identity blockers despite its ordinary selection passing:

- SQLite permits a foreign table and exact trigger under the same reserved name.
  `array_column(name)` hid the table; the canary failed 1 / 2 after 53 writes.
  The identical author reproduction failed 1 / 2 at 507 ms. The repair validates
  every reserved object's raw name/type and rejects duplicate folded identities
  before building the lookup. Table/index/view/trigger collisions in both orders
  and cases now refuse before writes.
- Native MySQL permits foreign `cde_own_insért`, then rejects ASCII
  `cde_own_insert` with error1359. PHP lowercase matching missed this dictionary
  alias; the independent canary failed 1 / 2 after table+seed and the third
  attempted write. Author reproduction on SQLite-corrected predecessor
  `4d0e739f668790baea1afc76910655b75f93cfe3` also failed 1 / 2, 9,435 ms. The final
  native preflight uses a prepared reserved-name join under the trigger
  dictionary's `utf8mb3_general_ci`, then requires exact raw ASCII identity.
  Accent aliases now refuse before creating either table or seed, and behind
  retained partial prefixes.

Original independent canary sources/results and the actual native alias probe
are retained. A separate native CHECK probe accepted `cde_id_chéck` beside ASCII
`cde_id_check`; CHECK identities therefore retain their existing rules rather
than assuming the trigger dictionary governs them.

## Recovery contract

Before any write, the migration proves relevant permanent identities, native
transactional dependency engines, temporary shadows, complete expected epoch
metadata and every surviving guard. SQLite compares original table/guard SQL and
validates reserved identities before indexing. MySQL checks case-sensitive raw
identities after folded discovery, table storage, ordered columns/defaults,
indexes including visibility/order/parts, named checks/enforcement, complete
constraints and exact guard bodies. Its trigger dictionary lookup also discovers
non-ASCII aliases before strict raw-name comparison. Unexpected epoch objects or
foreign expected names are never replaced.

Only the original contiguous guard creation prefix may resume. An absent seed
is allowed only before any guard exists; a partial retained seed must remain
exactly id1/epoch0/schema_version1. Advanced partial epochs, missing seeds behind
intact guards and interior gaps require investigation. Retry creates only missing
original statements and preserves every surviving object and retained row. It
never drops/replaces a guard or resets an epoch. A fully installed exact schema
can finish bookkeeping with its retained nonzero epoch after a protected parent
write. The original final `DiscoveryEpoch::assertInstalled`, table SQL, guard
predicates and application runtime are unchanged. Operational down still refuses
before reads and preserves migration records.

Installation/retry requires stopped application writers and competing migrators.
An exact prefix cannot establish historical writes made while protection was
incomplete; this adds neither an online deployment lock nor production restore
workflow.

## Actual selected evidence

| Source | Engine / selection | Cases / assertions | Result |
| --- | --- | ---: | --- |
| Initial uncommitted repair feedback | Native MySQL affected selection | 25 / 3,952 | Pass, 349,071 ms; diagnostic only |
| `6a5da20` predecessor | Native MySQL complete affected selection | 26 / 3,973 | Pass, 423,062 ms; both later identity findings remain distinct |
| `4d0e739` SQLite identity correction | SQLite affected selection + unchanged independent canary | 28 / 4,008 | Pass, 9,430 ms |
| `4d0e739` | Native corrected-seed + cross-namespace cases | 2 / 20 | Pass, 18,395 ms; native alias red still blocked it |
| **`ed285b6` corrected source** | **SQLite complete affected selection + original namespace canary** | **28 / 4,008** | **Pass, 9,678 ms** |
| **`ed285b6` corrected source** | **Native current recovery/log/seed/identity/final-assertion subset + original alias canary** | **8 / 118** | **Pass, 97,856 ms** |

All passing selections have zero failures/errors/skips. Pint on the three source
paths and `git diff --check` pass. The actual installed graph has 158 packages
with zero version/reference mismatches against the unchanged lock; own regenerated
Composer metadata resolves this checkout's application/tests. Package directories
are borrowed from the prepared locked installation. Runtime/graph evidence
records reflected application, test and framework origins.

The unchanged original nine migration identities retain rollback/forward-migrate,
actual INSERT/UPDATE/DELETE/upsert/replace/ABA/rollback, overflow and capture refusal.
The recovery file has 18 cases including provider datasets. Its 112 creation
boundaries interrupt all 56 original table/seed/guard statements before and after
execution, compare raw surviving guard metadata, every dependency/prior migration
row and repeated zero-write retries, and prove invalid reset/delete/seed refusal.
Other cases cover first/last final-assertion reads, real final-assertion table drift,
MigrationEnded and repository INSERT failures before/after commit, complete
schema/row/guard preservation on drift/shadow/gap/seed refusal, and installed
nonzero-epoch retention.

The new namespace case exercises 16 constructible SQLite object/type/case/order
collisions, 8 native cross-namespace collisions and 11 native accent aliases across
absent tables, seed-only tables and 1/7-guard prefixes. The missing-seed fixture
restores its exact delete guard after removing the synthetic seed, so a gap cannot
mask seed-specific refusal. MySQL permanent-row evidence uses an independent
native reader, since qualifying a name does not bypass a primary temporary shadow;
SQLite reads are `main` qualified.

`native-method-carry.json` proves the exact shared `up` creation order, original
DiscoveryEpoch/table/guard SQL, shadow/table checks, down, full112-boundary method
and relevant helpers remain byte-identical to executed predecessor6a. The old
MySQL preflight body is preserved around one added dictionary-check call. New
SQLite identity logic and the added native lookup have fresh focused evidence;
old green runs do not approve those corrections. No duplicate full112 native
sweep was launched after these additive identity repairs. Neither the native
subset nor the carried prefix proof is complete release acceptance.

## Runtime, commands and limits

Genuine PHP8.4.26 with pdo_mysql/pdo_sqlite. Native server is
`8.0.46-0ubuntu0.24.04.4` (Ubuntu), from signature-verified packages extracted
rootlessly. `lower_case_table_names=0`, flush-at-commit1, doublewriteON, binary
loggingOFF. This is distinct from earlier independently recorded MySQL8.4.11
source/durability/concurrency results. Package signing/version/hash provenance is
retained; private task authentication and synthetic database contents are excluded.

```sh
source /workspace/.va-studio-toolchain/activate.sh
# Native shells additionally source the toolchain owner's private task.env.
php vendor/bin/phpunit tests/Feature/DiscoveryEpochMigrationTest.php \
  tests/Feature/DiscoveryEpochRecoveryTest.php \
  docs/verification/discovery-epoch-ddl-recovery-new-20261007/SQLiteReservedIdentityCanaryTest.php \
  --log-junit=/tmp/pr25-final-corrected-sqlite.xml \
  --fail-on-empty-test-suite --fail-on-phpunit-warning --display-warnings
php vendor/bin/phpunit tests/Feature/DiscoveryEpochMigrationTest.php \
  tests/Feature/DiscoveryEpochRecoveryTest.php \
  docs/verification/discovery-epoch-ddl-recovery-new-20261007/NativeReservedAliasCanaryTest.php \
  --filter 'failed_trigger_creation|final_assertion|real_migrator|missing seed|reserved_objects|exact_installed|native_dictionary_alias' \
  --log-junit=/tmp/pr25-final-corrected-mysql.xml \
  --fail-on-empty-test-suite --fail-on-phpunit-warning --display-warnings
php vendor/bin/pint --test database/migrations/2026_10_07_240000_catalog_discovery_epoch.php \
  tests/Feature/DiscoveryEpochMigrationTest.php tests/Feature/DiscoveryEpochRecoveryTest.php
git diff --check
```

Native shells use task-scoped loopback network permissions. Existing disposable
FinalizationDatabaseMigrations cleanup wipes only the synthetic test database.
SQLite does not prove native DDL or concurrency. Independent review of exact `ed285b6` executed separate SQLite4/40 and native
MySQL5/82 successor checks, all green, and reported no remaining blocker. Its
receipt is retained by the root reviewer in the separate independent evidence
directory. Root composition remains a separate gate. No hosted CI, dependency upgrade,
push, merge, external data operation or production installation was performed.
This does not close WP02/WP12/T36, prove actual historical installation recovery
or authorize cutover.
