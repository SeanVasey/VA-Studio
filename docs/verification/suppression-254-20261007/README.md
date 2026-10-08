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
- The intents INSERT guard also requires the intent not to predate its withdrawal event (`e.created_at<=NEW.created_at`), the comparison `ProductionSuppressionRecords::intent()` applies at read time. Without it, a raw writer could commit an intent that every graph read then refuses and that the append-only guards leave unrepairable (Codex P2 on PR #49). The trigger text is built once for both drivers, so SQLite and MySQL stay equivalent. The migration `2026_10_07_254000_production_suppression` only delegates to the installer and carries no SQL of its own. It has never been applied anywhere (it is new in this PR), so no completion migration is needed. Evidence: `conditions/codex-intent-timestamp/` (SQLite only; the changed trigger text has not been run on MySQL).
- The `public_id` of targets, intents and attempts (the three the runtime validates with `Str::isUuid()`) must be a UUID shape at the trigger, not just 36 characters: 8-4-4-4-12 hex digits of either case, no version or variant constraint, which is exactly what `Str::isUuid()` accepts. One PHP helper emits a per-driver predicate: SQLite `length(NEW.public_id)=36 AND NEW.public_id GLOB '[0-9A-Fa-f]...-...'`, MySQL `CHAR_LENGTH(NEW.public_id)=36 AND NEW.public_id REGEXP '^[0-9A-Fa-f]...-...$'` (the same expanded character classes, no braces). Confirmations have no public id. Evidence: `conditions/codex-public-id-uuid/` (SQLite only; the MySQL predicate has not been run natively). The migration has never been applied, so no completion migration is needed.
- Timestamp shape: every INSERT guard requires `NEW.created_at` to be what `ProductionFeatureShape::timestamp()` accepts on read, exactly `YYYY-MM-DD HH:MM:SS` and a real calendar instant. Without it, a raw SQLite writer could commit `created_at = 'zzzz'` (the datetime affinity keeps any text and the lexical `parent.created_at <= NEW.created_at` check passes it), which every graph read then refuses and the append-only guards leave unrepairable (Codex P2 on PR #49). One PHP helper emits a per-driver condition for all four tables. SQLite: the digit `GLOB` for the shape, `substr(NEW.created_at,12,2)<'24'` (SQLite's `datetime()` returns an hour of 24 unchanged, which the runtime refuses) and `datetime(NEW.created_at)=NEW.created_at` (NULL for unparsable text, normalised for `2026-02-30`). MySQL: the column is `timestamp`, so strict `sql_mode` refuses unparsable and calendar-invalid strings before the trigger sees `NEW`, and coercion normalises accepted input such as a `T` separator, so the trigger can only judge the stored value; it requires the `CHAR` form to match the format, `YEAR()>0` (non-strict modes turn bad input into the zero date) and a `STR_TO_DATE` round trip, and a NULL fails closed through `COALESCE`. A 20,032-value SQLite parity check against `ProductionFeatureShape::timestamp()` found no difference. Evidence: `conditions/codex-timestamp-shape/` (SQLite only; the MySQL condition has not been run natively, so the MySQL half of the test asserts only the values reasoned to be refused under any `sql_mode`). The migration has never been applied, so no completion migration is needed.
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

## Independent review (APPROVE WITH CONDITIONS)

An independent harness reviewed code head `452cdab1` and decided APPROVE WITH CONDITIONS for a reversible development merge (`independent-review/DECISION.md`). The approval does not cover activation, binding a provider, mounting a route, Foundation final verification or launch readiness. Defaults stay as shipped: `enabled=false`, `provider=null`, no route, no adapter.

Conditions:

1. **C1, native deadline (open).** The 253 native deadline exhaustion still applies to 254. It blocks the native runtime evidence for 254 and so blocks activation. Once it is repaired, the full `tests/Feature/ProductionSuppression` selection must pass natively on MySQL 8.4 on the target host class. Nothing in this lane claims a native result for the 254 runtime.
2. **R1, receipt-scope test gap (closed in this lane, SQLite).** `ProductionSuppressionJourneyTest::test_receipts_scoped_to_another_recipient_provider_or_account_never_confirm` pins the recipient-HMAC, provider-hash and cross-account comparisons. With the comparisons removed it fails (both removed, provider hash only, recipient HMAC only), and it passes with the source restored. Receipts and JUnit: `conditions/R1/`.
3. **R2, Sean's decision (open).** After withdraw, re-grant, the first `request()` still records the target, intent and attempt and calls `suppress()` for the historical withdrawal, because the 253 reader keeps returning the retained withdrawal while the current status is `granted`. There is no unsuppress path, so this overrides the customer's current grant at the provider. It errs toward not mailing and does not infer consent, but it is a customer-visible semantic that is undecided. Sean decides before any activation or provider binding: either require the current status to be withdrawn for a new target or attempt, or document the behaviour with a defined re-subscribe path.
4. **Defaults stay shipped.** A provider binding or enabling the parent needs a separately reviewed adapter, scope and operator procedure, plus Sean's authorization.

Closed in this lane without a behaviour change: R3 (`ProductionSuppressionProvider::boundTo()` must be pure and configuration-only, because it runs inside the held transaction) and R8 (the recipient-carrying parameters of `ProductionSuppressionRequest::fromRecords()` and its constructor, and `ProductionSuppressionRecords::request()` and `encrypt()`, are `#[SensitiveParameter]`).

Documented as out of model or accepted (reviewer Info findings, no code change):

- **R4, fibers.** C2 liveness means the minting callback frame has not returned. A fiber suspended inside a sealed callback keeps the context live, and code outside the frame can read the withdrawal until the fiber resumes. A second concurrent `run()` still refuses. Keep fibers out of sealed callbacks.
- **R5, private statics.** `ProductionFeatureOperation::$live` and the two `WeakMap` seals are reachable through Reflection and through `Closure::bind` to the private scope. Both are deliberate scope violations outside the stated model. The seals are not a hostile-code boundary.
- **R6, `fromRecords` minting.** `ProductionSuppressionRequest::fromRecords()` is public static (`@internal` in its docblock only), so server code can mint a transport request with any recipient. It cannot reach `reconcile()` confirmation, because the claim comes only from the sealed run. Consider restricting minting to `ProductionSuppressionRecords` before a real adapter is bound.
- **R7, `serverSnapshot()` and liveness.** A `ProductionConsentWithdrawal` that a callback leaked out of `run()` still returns the recipient from `serverSnapshot()`. The reader is sealed but the minted object is not tied to liveness. 254 uses it only inside the callback. Optional hardening: require the minting context to be live.
- **R9, operator procedure (open).** An `unknown` attempt stays unknown in two cases: a failed postcommit proof (transport never ran) and rebinding to a different provider hash (`reconcile` builds no claim). An operator procedure is needed before activation.

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

## Independent review addendum 2 (`72d7ae29..e7593a9c`)

APPROVE WITH CONDITIONS carries to `e7593a9c` (`independent-review/DECISION.md`, addendum 2;
evidence `independent-review/review-evidence/addendum2/`). The three trigger-guard changes are
correct on SQLite and on native MySQL 8.4.11: the five malformed timestamps plus the 2038
overflow were inserted raw on all four tables under strict, `sql_mode=''`, `ALLOW_INVALID_DATES`
and strict-with-`INSERT IGNORE` (96 inserts, none left a row; strict refuses at the column,
the other modes at the guard); coerced inputs (`T` separator, fractional seconds, offsets,
whitespace) store values that pass `ProductionFeatureShape::timestamp()` on read; hour 24 and
second 60 are refused; `public_id` uppercase accepted, trailing space / NUL / newline / `g`
refused; an intent before its withdrawal refused, the same instant accepted. Mutations: hour
bound and intent-time removals killed by the lane tests; `YEAR()>0` removal survives the lane
suite and is killed natively only when the trigger is created under `sql_mode=''` with a
zero-dated parent (A2-1, test gap, recorded). The native whole-directory run (48 tests, 538
assertions, exit 2) has 14 errors that are the known C1 activation blocker in the 253
`initialize` fixture and one new Low, A2-7: `ProductionSuppressionMigrationTest::
test_recorded_gap_and_non_prefix_installation_refuse_without_repair` creates the intents table
without its targets parent, which MySQL refuses (error 1824) and SQLite allows; fixed below.
A2-4 (Low, deployment): MySQL sessions are not pinned to UTC (`config/database.php` has no
`timezone` key); DST fall-back instants are accepted and a spring-forward gap is refused or
moved by the column; recommendation for U-02: `'timezone' => '+00:00'` or a UTC server. A2-2
(Low, adjacent): the 253 consent-events insert guard has no `created_at` shape check; routed
to the 253 owner. R2 and R9 remain open activation gates for Sean.
