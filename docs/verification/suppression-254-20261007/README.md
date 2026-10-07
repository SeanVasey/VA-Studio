# Production suppression 254 and 253 findings C2/C3 (2026-10-07)

Branch `harness/suppression-254`, stacked on the reviewed composed 253 evidence commit `8773b720` (`harness/account-features-253`). Not pushed. Development evidence only: this is not a review, final acceptance, activation or launch claim.

| Commit | Content |
| --- | --- |
| `2580dc2a` | `fix(features)`: C2, the withdrawal reader accepts only a context minted by a live `ProductionFeatureOperation::run` callback |
| `7c99720d` | `fix(features)`: C3, the withdrawal recipient is unreachable through dumps, casts, JSON, serialize and instance Reflection |
| `9423bc5d` | `feat(suppression)`: the distinct 254 schema, installer and migration `2026_10_07_254000_production_suppression` |
| `452cdab1` | `feat(suppression)`: the default-off 254 runtime, unbound provider contract, config and tests |
| (this commit) | this README and its receipts |

## C2 (Medium): sealed reader

- Problem: `ProductionFeatureContext::locked` is public. A caller-owned level-1 transaction could mint a context that kept the identity floor but skipped the committing and postcommit proofs, and the reader released the recipient to it.
- Fix: `run()` registers the context it minted in a private static registry only while its callback executes, and removes it in `finally`. There is no setter. `ProductionFeatureOperation::assertLive()` is the read-only check. `ProductionConsentWithdrawal::capture()` (and so the reader) calls it first. 254's `ProductionSuppressionRows` calls it too. `locked()` stays public as a minimal diff, but a context minted there is never live.
- Regression: `tests/Feature/ProductionFeatures/ProductionFeatureSealedRunTest.php` covers the reviewer's probe, a context leaked from a completed run, and the unchanged in-callback capture. `tests/Feature/ProductionSuppression/ProductionSuppressionSealTest.php` applies the same probe to the 254 rows. Red receipt: `c2-red-sqlite.txt`. The PHPUnit exporter in that receipt printed the recipient, which also showed C3; it is redacted there.
- Out of model: deliberate Reflection writes to private static state.

## C3 (Low): recipient sealing

- `ProductionConsentWithdrawal` keeps its capture in a private static `WeakMap` keyed by the minted instance, so the object has no instance properties.
- `serverSnapshot()` is the only reader. It refuses clones and crafted unserialized instances.
- `__debugInfo()` returns only `['production_consent_withdrawal' => true]`.
- `__serialize`, `__unserialize` and `jsonSerialize` all refuse.
- `ProductionSuppressionRequest` (254 transport input) is sealed the same way.
- Regression: `ProductionConsentWithdrawalSealTest` checks `var_export`, `print_r`, `var_dump`, `(array)`, `get_object_vars`, `json_encode` and `serialize`. Red receipt: `c3-red-sqlite.txt`.

## 254 design

Schema (`app/Domain/Customers/ProductionFeatures/Suppression/ProductionSuppressionSchema.php`): four append-only `production_suppression_*` tables.

| Table | Unique | References |
| --- | --- | --- |
| targets | public id; (binding, purpose, recipient HMAC) | `production_account_feature_bindings`, `production_consent_events` (the withdrawal that created it) |
| intents | public id; withdrawal event | targets, `production_consent_events` |
| attempts | public id; target; intent | intents, targets |
| confirmations | attempt | attempts |

- No `customer_*` (250/251) reference exists.
- INSERT guards require a `consent_preferences` binding and an explicit withdrawn, non-affirmative event for the same recipient HMAC.
- UPDATE and DELETE always refuse.
- The installer follows the 253 pattern:
  - Before any DDL, it admits the owned namespace and reserved keys, then the complete 253 floor through `ProductionFeatureSchema::assertComplete`.
  - Only an exact owned prefix resumes.
  - A recorded gap or a non-prefix refuses.
  - The full graph is re-proved after the last DDL.
  - `down()` refuses before any query.

Runtime (`ProductionSuppressionIntents`): an explicit server caller only. Nothing is routed, queued or scheduled.

- `request(identity, expectedConsentVersion)` runs as one sealed 253 consent operation:
  1. Raw admission of the `production-suppression` parent and its leaves (a detached copy through Repository internals, after 253 admission). The default-off parent refuses before any lookup.
  2. The `ProductionConsentWithdrawalReader`.
  3. Target and intent rows.
  4. Only when the configured provider hash equals the adapter's `boundTo()`, the one attempt for the target. Raw prepared PDO inserts are re-observed and fenced with `context->expected`. Every 254 scope is re-read by the context's committing and postcommit guards. The adapter binding is a fence.
  5. After commit and postcommit proof, `suppress()` is invoked once. Its outcome never confirms anything: the status stays `unknown`.
- `reconcile(identity)` is inspect-only:
  1. A sealed read builds the request from the durable target ciphertext, which is the captured address.
  2. After commit, `inspect()` runs.
  3. A second sealed operation stores a confirmation only for a `ProductionSuppressionReceipt` that matches the operation id, request hash, recipient HMAC and provider hash, with status `suppressed`.
  Nothing is ever resent.
- Status values: `not_requested`, `pending` (an intent with no attempt, because the provider is unbound), `unknown` (an attempt with no positive inspection) and `confirmed`.
- A later grant cannot touch 254 rows. A second withdrawal records its own intent on the same target with no new attempt.
- Unknown attempts whose transport never ran stay `unknown` (for example after a failed postcommit proof). They need an operator procedure that is not designed here.
- Provider: the `ProductionSuppressionProvider` interface and the refusing `UnboundProductionSuppressionProvider`. There is no real adapter.
- Config: `config/production-suppression.php` = `['enabled' => false, 'provider' => null]`.

## Results

Environment: PHP 8.4.26 in worktree `/home/user/VA-Studio-supp254`, with `vendor/bin` proxies copied so the worktree autoloader is used. SQLite is the primary engine (`phpunit.xml` default).

| Selection | Engine | Result | Receipt |
| --- | --- | --- | --- |
| C2 probe before the fix | SQLite | red: 3 tests, 1 pass, 2 fail (the probe returned the withdrawal) | `c2-red-sqlite.txt` |
| 253 selection plus root config after C2 (`ProductionFeatures` feature and unit, `ProductionAccountFeatures`) | SQLite | 92 tests / 844 assertions: 89 pass, 3 native-only skips | `c2-green-253-selection-sqlite.txt` |
| C3 seal before the fix | SQLite | red: 2 tests, 2 fail (`var_export` exposed the capture; the clone exposed it) | `c3-red-sqlite.txt` |
| Same 253 selection after C3 | SQLite | 94 / 875: 91 pass, 3 native-only skips | `c3-green-253-selection-sqlite.txt` |
| Owned final: 253 selection, root config and `tests/Feature/ProductionSuppression` | SQLite | **138 / 1539: 129 pass, 9 native-only skips** (3 from 253, 6 from 254) | `owned-sqlite.*` |
| Adjacent: legacy consent/suppression 250/251, production identity, identity adapters, registration and SMTP binding | SQLite | **303 / 1738: 291 pass, 12 native-only skips** | `adjacent-sqlite.*` |
| `ProductionSuppressionNativeAdmissionTest` (6) plus the 253 `ProductionFeatureNativeAdmissionTest` (3, its reset helper now drops 254 first) | MySQL 8.4.11, private single-schema mysqld on :3417 | **9 / 173 pass** | `native-admission-mysql84-private.*` |
| Earlier run of the 6 native 254 cases alone | MySQL 8.4.11, same instance | 6 / 96 pass | quoted in commit `9423bc5d` |
| Pint `--test` on the owned 253/254 selection | n/a | passed | `pint.txt` |
| `git diff --check` | n/a | clean | n/a |

Per driver, the 254 tests are: SQLite 38 run (6 native-only cases skip); MySQL 6 native schema/namespace guard cases. The 254 journey and the 253 runtime were not run natively (see open items).

## Commands

```sh
# SQLite (primary)
php vendor/bin/phpunit tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures tests/Feature/ProductionAccountFeatures tests/Feature/ProductionSuppression --log-junit owned-sqlite.junit.xml
php vendor/bin/phpunit tests/Feature/CustomerConsent*Test.php tests/Feature/CustomerSuppression*Test.php tests/Feature/ProductionIdentity tests/Feature/ProductionIdentityAdapters tests/Feature/ProductionIdentityRegistrationTest.php tests/Feature/ProductionIdentitySmtpBindingTest.php   # adjacent, listed file by file in practice
php vendor/bin/pint --test <changed files>
git diff --check

# Native schema guards only: private mysqld 8.4.11, single schema
mysqld --no-defaults --user=root --initialize-insecure --datadir=$SCRATCH/mysql-supp254/data
mysqld --no-defaults --user=root --datadir=$SCRATCH/mysql-supp254/data --port=3417 --bind-address=127.0.0.1 --socket=/tmp/claude-0/s254.sock --mysqlx=OFF
# root password ci-only-password; CREATE DATABASE vaseyaudio_supp254
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3417 DB_DATABASE=vaseyaudio_supp254 DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php vendor/bin/phpunit tests/Feature/ProductionSuppression/ProductionSuppressionNativeAdmissionTest.php tests/Feature/ProductionFeatures/ProductionFeatureNativeAdmissionTest.php
```

The socket lives outside the scratch datadir because the scratch path exceeds mysqld's 107-byte socket limit. The first start attempt aborted on that limit; see `error.log` in the datadir before cleanup.

## Untested and open

- **Real provider**: no adapter, credential, provider account, scope or transport exists. Receipts come from the synthetic in-process `tests/Support/RecordingSuppressionProvider.php`. Binding one needs separate review and Sean's authorization.
- **HTTP mount**: no route, controller or session capability. The boot test asserts that no suppression route exists. Root owns any private mount.
- **Email operations**: there are no sender, DNS, queue or scheduler settings, and no operator procedure for `unknown` attempts whose transport never ran.
- **Email change**: production identity has no email-change flow. A direct address change fails the identity floor before any 254 lookup, and the test proves nothing is re-targeted. The positive path, where a supported email change is followed by inspection of the captured target, is enforced structurally (requests are built only from the target ciphertext) but is not exercised end to end.
- **Identity-less reconciliation** (a background job without a customer session) is not designed. Every entry point needs a sealed consent identity.
- **Native runtime**: the 254 journey was not run on MySQL. C1 (native deadline exhaustion, being fixed on another lane) still applies and 254 adds held-frame dictionary probes. No concurrency or race proof of the unique keys on MySQL exists.
- **Not run**: the full store suite, MySQL 8.0 and hosted CI.

## Private instance cleanup

The private instance was used only for the native admission selection above. It was stopped with `mysqladmin shutdown`, and a lingering process was terminated. Its datadir `$SCRATCH/mysql-supp254` and socket `/tmp/claude-0/s254.sock` were removed. A connection check confirmed port 3417 was closed. The shared :3306 server was not used.
