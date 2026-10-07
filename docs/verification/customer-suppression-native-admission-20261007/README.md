# Reserved suppression key admission correction

Exact application/test source: `aedab416a5099f958c11a5398870cfe4173241e5`, isolated successor of `904cdad33f70ee5a92d29f0b79db62a4acb24f44` / runtime `50fb8d0442a4f53632e1f25785b49d3148161d20`.

The actual native predecessor accepted a foreign FK named `customer_suppression_intents_consent_event_id_fk`, created its first owned table, then failed MySQL1826 while creating intents. The preserved original canary has SHA256 `e8cb8638616ac4f96a34319c9a236b041b29344681d75621f1e5126aab36eb5f`; the red raw/JUnit/catalog snapshot and exact original source are retained here.

The successor derives all13 FK/index/unique names from the existing schema descriptors. Before any DDL, it checks every dictionary row and owner using actual native collation, without PHP normalization or first-row masking. Foreign or aliased names, collisions across tables/triggers/routines/events, and temporary table shadows refuse. Retained exact keys on their actual owned table still pass the existing complete ownership proof. SQLite additionally refuses named objects/indexes in main and temporary namespaces. The same admission runs after the final framework DDL callback, before migration bookkeeping. Approved250 admission/migration/evidence and existing suppression runtime, commands, UI and default-off policy are unchanged.

Author verification uses PHP8.4.26, SQLite and actual native MySQL8.0.46 in the isolated synthetic `vaseyaudio_suppress_fix` database. Carried SQLite105/553 passes; final newSQLite17/133 passes with2 declared native-only skips. The native initial selection passes18 product cases/140 assertions and has one author fixture failure: an accented FK name was not equal in this actual TABLE_CONSTRAINTS dictionary. That receipt is preserved, not called a product failure or hidden. The repaired fixture first proves uppercase native dictionary equality, then passes1/9. Native owned-prefix4 and real-Migrator interrupted/retried installation pass2/13. The byte-exact original native canary passes1/9, proving no owned DDL/catalog change and retained foreign row. This covers all19 distinct native key cases across the initial selection and corrected fixture; it is not a claimed single full19 green run.

Commands (private native env files are sourced, never printed):

```sh
PATH=/workspace/.va-studio-toolchain/bin:$PATH php vendor/bin/phpunit tests/Feature/CustomerSuppressionKeyAdmissionTest.php
PATH=/workspace/.va-studio-toolchain/bin:$PATH php vendor/bin/phpunit tests/Feature/CustomerSuppressionMigrationTest.php tests/Feature/CustomerSuppressionTest.php tests/Feature/CustomerConsentPreferencesTest.php tests/Feature/CustomerConsentBoundaryTest.php
source /workspace/.va-studio-toolchain/activate.sh
source /workspace/.va-studio-toolchain/mysql/suppress_fix-task.env
# Synthetic APP_KEY only; no production key.
php vendor/bin/phpunit tests/Feature/CustomerSuppressionKeyAdmissionTest.php
php vendor/bin/phpunit tests/Feature/CustomerSuppressionKeyAdmissionTest.php tests/Feature/CustomerSuppressionMigrationTest.php --filter '/test_native_dictionary_collation_alias|test_every_known_installation_prefix.*#4$|test_real_migrator/'
php vendor/bin/phpunit /tmp/va-suppression-native-admission-green/CustomerSuppressionNativeAdmissionTest.php
php vendor/bin/pint --dirty
```

Native8.4/final-candidate concurrency, parent HTTP composition and independent review remain separate acceptance. No external provider, real recipient, customer history or live rollout is used. The schema and all retained suppression/consent history remain rollback-refused. Source and evidence digests are in `source-evidence.json` and `artifact-sha256.json`.
