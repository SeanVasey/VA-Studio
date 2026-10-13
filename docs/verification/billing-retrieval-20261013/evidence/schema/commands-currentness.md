# Billing retrieval schema lane — in progress

Worktree `/workspace/VA-Studio-billing-retrieval-schema`, branch `harness/billing-retrieval-schema-20261013`, base `45d1299406821627f4ba02af186e324a70c6ade0`. Owned source: BillingSchema, new BillingRetrievalSchema, forward263 migration, and two new focused test files. No historical migration, seal helper, producer/consumer, shared fixture or workflow edits.

The first root-imposed C2 native-workload freeze was honored: only source preparation and three `php -l` checks ran before the root released test bodies. All three syntax checks exited0. The new worktree has copied local Composer metadata, symlinked immutable dependencies and a synthetic local environment from the root worktree. An initial direct `php vendor/phpunit/phpunit/phpunit ... --filter test_populated_upgrade` invocation failed before PHPUnit ran with duplicate Composer autoloader declaration because the dependency path was symlinked. Copying `vendor/bin` locally and using `php vendor/bin/phpunit` corrected that runner setup. It was not a test pass/failure.

All receipt-bearing runs use:

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/billing-retrieval-schema-evidence/run.py LABEL TEST_PATH [--filter EXACT_SELECTION]
```

The wrapper records exact argv, synthetic connection configuration and SHA256 inventory before each run, observed exit, JUnit XML, full output, and files changed during the run. Environment keys common to every run: `APP_ENV=testing APP_DEBUG=false DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. SQLite uses `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_SOCKET=`.

Observed initial SQLite runs:

- Unrecorded-in-file preliminary populated-upgrade canary: 1 test /63 assertions, exit0; tool output retained in conversation.
- `sqlite-upgrade-initial`: whole BillingRetrievalSchemaUpgradeTest, 23 tests /698 assertions, zero skips/errors/failures, exit0; source unchanged during run. Includes every interrupted prefix1–19.
- `sqlite-identity-initial`: initial seven-case BillingRetrievalIdentitySchemaTest, 7 tests /54 assertions, 1 native-timezone skip, zero errors/failures, exit0; source unchanged. Two later native snapshot cases were not present in that run.

Native instance: existing isolated `va-studio-private-mysql-policy-20261013`, datadir `/workspace/runtime/mysql-policy-data`, port33413, host socket `/workspace/runtime/mysql-policy-run/mysql.sock`. This lane created only new disposable schema `va_billing_retrieval_schema_20261013`. Synthetic local root account has no password; no external database/provider/account is used. `SELECT VERSION()` returned **8.4.11**. Creation/version command:

```sh
docker exec va-studio-private-mysql-policy-20261013 mysql --socket=/run/mysql-policy/mysql.sock -uroot -e 'SELECT VERSION(); CREATE DATABASE va_billing_retrieval_schema_20261013 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
```

Native connection adds `DB_CONNECTION=mysql DB_DATABASE=va_billing_retrieval_schema_20261013 DB_HOST=127.0.0.1 DB_PORT=33413 DB_SOCKET=/workspace/runtime/mysql-policy-run/mysql.sock DB_USERNAME=root DB_PASSWORD=`.

- `mysql-canary`: populated-upgrade filter, 1 test /1 assertion /1 error, exit2; source unchanged. Baseline and pre-DDL schema validation passed, but reinspection after the first ADD COLUMN refused CHECK metadata.
- `mysql-canary-diagnostic`: same case with temporary diagnostic output, same1/1/error/exit2; source unchanged. Native MySQL rewrites the existing known ASCII enum literals from `_utf8mb4\'retrieval\'` to `_ascii\'retrieval\'` after ADD COLUMN. Diagnostic output is retained; instrumentation removed afterward.
- Narrow repair: normalize only the same explicit, existing enum literal list with either of those two native character-set introducers. Unknown literals and the complete remaining SQL still compare exactly. No constraint dropped or weakened.

Final receipts and exact native subset are pending. No Foundation CI, provider call, host verification, production activation, final independent approval or merge is claimed.

Schema dependency commit: **57c9bf9fc3dc5e326248abc8baa3e1b14e4a5ece**. No push. `sqlite-final.commit-map.json` verifies all five committed blobs exactly match the final SQLite run's pre-run SHA256 inventory.

- `mysql-canary-fixed`: same populated-upgrade case, 1 test /63 assertions, zero errors/failures/skips, exit0; source unchanged. Application/schema/migration files are identical to the released commit. A later test-only addition exercises the retained future worker timestamp transition.
- `sqlite-final`: both complete new files, **33 tests /758 assertions /3 skips /zero errors/failures /exit0**, source unchanged. The three skips explicitly name native session-timezone behavior and two native REPEATABLE READ snapshot behaviors; no SQLite concurrency claim is made.
- Five final `php -l` invocations, `php vendor/bin/pint --test` with the five owned paths, and `git diff --cached --check` all exited0 before commit.

Final test counts by file: BillingRetrievalSchemaUpgradeTest24, BillingRetrievalIdentitySchemaTest9. Every interrupted prefix1–19 is exercised on SQLite. Selected native interrupted prefixes are10,12,13,15,17,19, covering completed column additions, both indexes and every dropped-guard interruption. Native remaining prefix identities1–9,11,14,16,18 are deliberately not separately executed; each actual DDL transition is also validated by the populated full native upgrade.

- `mysql-identity-final`: complete nine-case raw identity file at57c9bf9f, **9 tests /81 assertions /zero skips, errors or failures /exit0**, source unchanged. Duration691973ms. Covers native timezone restoration, both cross-ledger insertion directions under an old ordinary snapshot, and all four endpoint/ledger combinations after same-ID allocation replacement while the ordinary read still sees the old token. These are structural SQL fixtures, never authenticated invoice/provider claims.

The accepted native upgrade-prefix subset was paused after this receipt: independent source review identified required current guard/table definition binding as a potential blocker. The producer lane received the native slot while this lane prepared dedicated old-snapshot regressions. The original33/758 SQLite and9/81 native receipts remain bound to57c9bf9f; they do not prove the new current-definition correction.

### Current required definitions: reproduced regression

The independent review identified current required guard and table ownership as blockers. `mysql-currentness-red` ran the new two-method `BillingRetrievalGuardDefinitionTest` against unchanged application source57c9bf9f on the same isolated MySQL8.4.11 instance. Exact command:

```sh
source /workspace/.va-studio-toolchain/activate.sh
VA_BILLING_DDL_CAPTURE=/workspace/runtime/billing-retrieval-schema-evidence/native-owned-table-definitions.json python3 /workspace/runtime/billing-retrieval-schema-evidence/run.py mysql-currentness-red tests/Feature/ProductionMembershipBilling/BillingRetrievalGuardDefinitionTest.php
```

Observed **2 tests /28 assertions /2 failures /zero errors or skips /exit1**, source unchanged. The guard test accumulated both incorrect admissions (`missing`, `replaced`). The table test accumulated all four (`engine`, `collation`, `column_width`, `extra_column`). Each test first proved that ordinary metadata still returned its older snapshot and that named current SHOW reported the changed object. Exact pre-fix test source, including temporary optional DDL capture, is retained as `currentness-red-test-source.php.txt`; the captured six-table native CREATE definitions are `native-owned-table-definitions.json`. The optional capture was removed before fixed-source verification.

The correction binds ordered current columns, exact keys/foreign keys/CHECK inventory, current engine/charset/collation and table options, excluding only the volatile table AUTO_INCREMENT counter. Current CHECK comparison preserves internal parentheses, literal contents, case and backticks; only the demonstrated known enum charset/escaping differences and whitespace outside quoted tokens are normalized. The existing dictionary/source CHECK validation remains. Each required guard now also uses named current SHOW after its subject is pinned or migration table locks are held. This does not resolve the separately deferred unknown-extra-trigger inventory limitation.

Fixed-source receipts and the accepted native eleven-case upgrade subset are in progress; no result is inferred from the expected red.

Correction commit **71f545bde74b30dab5398d9e4b4848856358b487** changes BillingSchema and adds BillingRetrievalGuardDefinitionTest only; no push. `sqlite-currentness-final.commit-map.json` and `mysql-currentness-fixed.commit-map.json` bind all six owned files to the corresponding receipt source hashes.

- `mysql-currentness-fixed`: `BillingRetrievalGuardDefinitionTest.php --stop-on-error`, **2 tests /34 assertions /zero skips/errors/failures /exit0**, duration133313ms; source unchanged. Both missing/replaced known guards and all four changed current table shapes now refuse after proving the stale dictionary/current SHOW discrepancy. Each fixture restores the exact original guard/shape before its final complete ownership check.
- `sqlite-currentness-final`: complete BillingRetrievalSchemaUpgradeTest, BillingRetrievalIdentitySchemaTest and BillingRetrievalGuardDefinitionTest, **35 tests /758 assertions /5 skips /zero errors/failures /exit0**, duration84967ms; source unchanged. The two added skips explicitly require native dictionary snapshot behavior.
- Final two-file syntax checks, Pint (then Pint --test), and cached diff check exited0. The provisional comparator runtime draft was never loaded by the application.

Exact currently running native subset:

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/billing-retrieval-schema-evidence/run.py mysql-upgrade-final tests/Feature/ProductionMembershipBilling/BillingRetrievalSchemaUpgradeTest.php --filter 'test_populated_upgrade|prefix_(10|12|13|15|17|19)|test_late_table_drift|test_foreign_extension_prefix|test_old_interval_migration|test_database_timed_observation' --stop-on-error
```

`--list-tests` with the same file/filter discovered exactly eleven cases before execution. This is populated retention/idempotence/down refusal, six interrupted prefixes, late drift before any extension DDL, foreign partial shape/outer transaction refusal, old261 missing upgraded guard refusal, and the structural legacy-future-worker timestamp transition. Result remains pending until its receipt is complete.
