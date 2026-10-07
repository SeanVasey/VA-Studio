# Suppression cached-primary boundary correction

Exact source: `983b497edfbdb56ce9995530b83f890ac741ade5`. This isolated successor starts at `272c0ea1339805316be1f47f2aa93f3ee50c9ea9`, retaining corrected schema `aedab416a5099f958c11a5398870cfe4173241e5` and the original `50fb8d0442a4f53632e1f25785b49d3148161d20` runtime predecessor. Only `SuppressionEvidence`, `SuppressionDelivery` and a new focused test change. Approved consent evidence, account authority, schema, request grammar, routes, UI, configuration and dependencies remain byte-exact.

The unchanged canary's SHA256 is `85ac73175f4d2e6cef62c7311cd52fb0c27a2520d6d34cba3be44a372f480e78`. On root source `df0f77eee08417137a828047fa495860f499115c`, and on this child's predecessor, SQLite and actual native MySQL 8.0.46 both fail one case / two assertions with no harness errors. After a synthetic positive receipt, the final adapter binding hook installs a lazy primary resolver returning the same PDO. Old consent proof invokes it after suppression policy was checked. The response says confirmed while policy is false and the original physical transaction remains open at framework depth zero. Immutable probe bytes, root receipts and author predecessor receipts/snapshots are retained here. Root's originals are also retained in its evidence commit `ce7a9f91ad3db2025d61dfe9504ef47e63b0462f`.

The repair checks cached connection identity, raw PDO, database and the captured framework depth after all adapter/container/query hooks and before the unchanged consent proof and final pure policy checks. It rejects unresolved sources without invoking their resolver. A single random savepoint belongs to the original outer suppression transaction; nested consent evidence does not create competing anchors. The outer wrapper checks that physical marker before returning. Interrupted cleanup rolls back an original transaction only when its marker still exists. It leaves a foreign PDO or same-PDO replacement transaction intact and prevents Laravel's exception handler from rolling it back. A callback that already committed a positive receipt produces a refused/unknown response while durable history is retained.

A lost acknowledgement preserves the previously committed attempt. It never sends again; a later explicit inspection can confirm only an exact positive receipt. All adapter calls in these tests are synthetic in-process fixtures. No external recipient or provider was used.

Final author evidence uses PHP 8.4.26, SQLite and actual native MySQL 8.0.46 in isolated `vaseyaudio_suppress_fix`:

- `affected-final-sqlite`: 174 cases, 172 passed, 990 assertions, two declared native-only skips. This includes unchanged consent runtime/admission/migration, suppression runtime/schema/key admission, seven new source boundaries and the unchanged original canary.
- `source-boundaries-native`: seven cases / 90 assertions passed. Positive/ambiguous send and inspection prove the late resolver is actually installed yet never invoked; the committed attempt is retained and no resend occurs. Actual same-PDO commit/reopen, separate native PDO replacement and unowned raw transaction cases prove ownership of cleanup.
- `carry-unchanged-final-native`: six cases / 37 assertions passed. This carries successful single delivery/no resend, late credential, suppression policy, customer policy and QueryExecuted withdrawal cases plus the byte-exact original canary.
- Focused Pint and `git diff --check` pass. Source reflection resolves into this worktree; copied Composer wrappers/autoload are independent and locked package directories are shared read-only.

Intermediate receipts remain visible. The first repair incorrectly required every evidence object to be at depth one, then incorrectly gave every evidence scope a savepoint: SQLite releasing an earlier savepoint removes later ones. These introduced implementation regressions are preserved in `runtime-carry-sqlite`, `runtime-carry-depth-sqlite` and `source-boundaries-initial-sqlite`. Early `intermediate-unchanged-*` passes are excluded from final acceptance because refusal could occur before the transport. The final dedicated cases assert that the receipt and late binding hook were reached, verify retained attempt/confirmation rows and test recovery. One unrecorded initial setup invocation used the symlinked package binary and failed duplicate Composer bootstrap; the independent copied wrapper was used for every recorded predecessor/final run.

Commands (native environment files are sourced without printing credentials):

```sh
source /workspace/.va-studio-toolchain/activate.sh
php vendor/bin/phpunit tests/Feature/CustomerSuppressionTest.php tests/Feature/CustomerSuppressionSourceBoundaryTest.php tests/Feature/CustomerSuppressionMigrationTest.php tests/Feature/CustomerSuppressionKeyAdmissionTest.php tests/Feature/CustomerConsentPreferencesTest.php tests/Feature/CustomerConsentBoundaryTest.php tests/Feature/CustomerConsentAdmissionTest.php tests/Feature/CustomerConsentMigrationTest.php /tmp/CustomerSuppressionLazyBoundaryCanaryTest.php
source /workspace/.va-studio-toolchain/mysql/suppress_fix-task.env
# Native runs use only the known synthetic APP_KEY.
php vendor/bin/phpunit tests/Feature/CustomerSuppressionSourceBoundaryTest.php
php vendor/bin/phpunit tests/Feature/CustomerSuppressionTest.php /tmp/CustomerSuppressionLazyBoundaryCanaryTest.php --filter '/test_single_positive_attempt|test_terminal_adapter_policy|test_late_credential_withdrawal|test_terminal_framework_query|test_customer_policy_disabled|test_late_primary_resolver/'
php vendor/bin/pint --test app/Domain/Customers/Preferences/Suppression/SuppressionEvidence.php app/Domain/Customers/Preferences/Suppression/SuppressionDelivery.php tests/Feature/CustomerSuppressionSourceBoundaryTest.php
git diff --check
```

`source-evidence.json` maps the final exact source, preserved dependencies and individual JUnit cases. The copied original canary writes its predecessor label into snapshots even on the successor; that hardcoded label is not the tested source. The manifest supplies the actual source and leaf digests. Parent composition and independent unchanged-canary validation remain required before publication. Native MySQL 8.4, production provider delivery, full final-candidate verification and live activation are untested. Production policy/adapters remain off and unbound. Production feature consumers and typed suppression delivery are separate preparation batches; this repair does not close T32 or the full store replacement.
