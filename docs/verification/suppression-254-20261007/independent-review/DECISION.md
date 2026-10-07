# Independent review: suppression 254 and 253 findings C2/C3

**Decision: APPROVE WITH CONDITIONS** for a reversible development merge of code head `452cdab1c16acba30123230195187221fcc99fc6` (tree `0d11a40caca56948246434b261001411cd1b9c7b`) on branch `harness/suppression-254`, base `8773b720f73d385f14d3480a3298a90638da02bb`. The docs commit on top, `2de41664780415839e158f9c109aee01df57e496` (tree `717f050a3711dfc59c0a2c26e1e53fb99c10124f`), changes only `docs/`, which this reviewer checked with `git diff --name-only`.

The approval does not cover activation, binding a provider, mounting a route, Foundation final verification, final acceptance or launch readiness. 253 condition C1 (native deadline exhaustion) still applies to 254. It blocks the native runtime evidence for 254, and so it blocks activation.

Reviewer: an independent harness (Claude), 2026-10-07. This reviewer did not author 253 or 254. No source file in the lane was edited. The reviewer's throwaway tests are stored as `.txt` under `review-evidence/` and were not committed.

| Commit | Content | Reviewed result |
| --- | --- | --- |
| `2580dc2a` | C2: `ProductionFeatureOperation::$live` registry plus `assertLive()`; `ProductionConsentWithdrawal::capture()` calls it first | C2 closed |
| `7c99720d` | C3: the capture is in a private static `WeakMap`, has no instance properties, uses a marker `__debugInfo` and refuses `__unserialize` | C3 closed within the stated model |
| `9423bc5d` | 254 schema, installer and migration `2026_10_07_254000_production_suppression` | Holds on SQLite and native 8.4.11 |
| `452cdab1` | 254 runtime, provider interface, refusing default adapter and default-off config | Holds on SQLite; native runtime blocked by C1 |

## Environment

- PHP 8.4.26 CLI (`zend.exception_ignore_args=On`) and PHPUnit 12.5.34. PHPUnit was run through the worktree autoloader (`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- …`).
- The review worktree `/home/user/VA-Studio-review-supp254` was detached at `452cdab1`.
- A second worktree `/home/user/VA-Studio-review-supp254-rg` was used for red/green runs at `8773b720`, `2580dc2a` and `7c99720d`, and for mutations at `452cdab1`. Each mutation was reverted with `git checkout -- app`, and `git status` then showed only the untracked throwaway directory.
- SQLite is the `phpunit.xml` default.
- Native runs used two private single-schema MySQL 8.4.11 servers. Each was started with `mysqld --no-defaults --initialize-insecure` and a scratchpad datadir.
  - `:3427`, socket `/tmp/claude-0/s3427.sock`, database `vaseyaudio_rev254`.
  - `:3447`, socket `/tmp/claude-0/s3447.sock`, database `vaseyaudio_rev254b`.
- Native env: `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`.
- The shared `:3306` server was never used.
- Host load average was about 6–10, with other agents' PHPUnit runs active.

## Review items

| # | Item | Verdict | Evidence |
| - | --- | --- | --- |
| 1 | **C2.** All four probes must 503: the reviewer's original probe (a public `locked()` mint in a caller-owned transaction), a context kept after `run()` returns or throws, a nested or re-entrant `run()`, and a second concurrent `run()` (fiber). A clone of a live context must also refuse. | **Closed** | `ProductionFeatureOperation.php:21-30,68-73`; `ProductionConsentWithdrawal.php:33-35`. Red at `8773b720`: 8 of 10 C2/C3 cases fail. The original probe *released* the capture there. The leaked/fiber cases failed only by accident (IdentityException, or `no such savepoint`). Green at `2580dc2a` for all C2 cases (C3 still red). Nested `run()` refuses through the existing level-0 precondition (`:40-44`). A second concurrent `run()` from a suspended fiber also refuses. The registry is `private static` with no setter: the only public methods are `assertLive` and `run`. |
| 2 | **C3.** The recipient must not appear via `var_export`, `print_r`, `var_dump`, `debug_zval_dump`, the Symfony VarDumper, `(array)`, `get_object_vars`, JSON, `serialize`, instance Reflection, `clone`, a crafted `unserialize` (with or without a `snapshot` property) or `newInstanceWithoutConstructor`. | **Closed** (static Reflection and `Closure::bind` are out of model; see R5) | `ProductionConsentWithdrawal.php:20-30,107-137`. Red at `8773b720` and still red at `2580dc2a` (3 failures). Green at `7c99720d`: 10/10 reviewer and author C2/C3 cases. `ProductionSuppressionRequest` is sealed the same way (`ProductionSuppressionRequest.php:13-87`). |
| 3 | **Schema.** FKs go only to `production_account_feature_bindings` and `production_consent_events` (plus internal 254 links). The unique keys hold. INSERT guards require an explicit withdrawn event for the same binding and recipient. UPDATE and DELETE always refuse. `down()` refuses. The installer follows the 253 pattern. | **Holds** (SQLite and native) | `ProductionSuppressionSchema.php:184-202` (FK/unique), `:361-389` (guards), `:112-115` (`down`), `:22-95` (namespace and 253 floor before DDL, prefix resume, post-DDL re-proof). The reviewer's raw probe `Suppression254NativeTriggerReviewTest` passes **1/1, 32 assertions on SQLite and on native 8.4.11**. It refuses each of these: a target from a *granted* event, a recipient-HMAC mismatch, a binding mismatch, a target timestamped before the withdrawal, a second target, an intent from a granted event, a second intent per withdrawal, a second attempt per target, a confirmation for another request hash and a second confirmation. UPDATE and DELETE refuse on all four tables. `migrate:rollback` throws the `down()` refusal, and the rows and migration record survive. |
| 4 | **Runtime.** <ul><li>`request()` commits before `suppress()`, and no transaction is open at the call.</li><li>`suppress()` runs exactly once per attempt and is never retried. A successful return does not confirm.</li><li>`reconcile()` only inspects and never resends.</li><li>Only an exact receipt confirms.</li><li>A later grant never reverses a suppression.</li><li>Refusal paths make no provider I/O, and no recipient appears in exceptions.</li><li>Every entry point requires a customer consent session.</li></ul> | **Holds**, with R1 (a test gap) and R2 (a product decision) | `ProductionSuppressionIntents.php:33-118,124-188,206-235`; `ProductionSuppressionReceipt.php:15-22`. Reviewer cases on SQLite pass 10/10 (79 assertions), covering seven more mis-scoped receipts: a wrong recipient HMAC, a wrong provider hash, another account's whole receipt, another account's operation id, status `Suppressed`, a zero-width character in the receipt id and an upper-case request hash. None confirmed, and `suppress` stayed at 1 call per target. With a non-throwing `suppress`, the status stays `unknown` and no confirmation row is written (level 0, `inTransaction=false`). Refusal paths make zero provider calls (`boundTo` included): version mismatch or negative version (ConsentException), wrong feature (IdentityException) and disabled config. Disabled config, with `provider` null or bound, refuses `request`, `reconcile` and `status` with 503 and writes no rows. Exception messages are generic. No `Log::`, `logger()` or `report()` call exists in `app/Domain/Customers/ProductionFeatures`. Identity comes only from `ProductionAccountFeatureIdentity::forRequest` (session), and `entry()` requires `consent_preferences`. |
| 5 | **Guards, deadlines and defaults** | **Holds** | The 253 10 s deadline is unchanged (`ProductionFeatureOperation.php:35`). Outside the new `Suppression/` directory, the only 253 source changes are the C2/C3 edits to `ProductionFeatureOperation.php` and `ProductionConsentWithdrawal.php`. No identity file changed (`git diff 8773b720..452cdab1 --stat -- app/Domain/Customers/ProductionIdentity`). `config/production-suppression.php` is `['enabled' => false, 'provider' => null]`. Nothing for suppression appears under `routes/`, `app/Providers` or `bootstrap/`. No flag was enabled. |
| 6 | **253 test changes** | **Only as stated** | Two `resetProduction()` helpers (`ProductionFeatureMigrationTest.php:184-191` and `ProductionFeatureNativeAdmissionTest.php:92-99`) now drop the empty 254 tables and their migration record first. The C3 change moves the `__debugInfo` expectation in `ProductionConsentWithdrawalReaderTest.php:73` to the marker. Two regression files were added (`ProductionFeatureSealedRunTest`, `ProductionConsentWithdrawalSealTest`). No 253 assertion was weakened. The owned 253+254 SQLite selection reproduces the author's receipt exactly: **138 tests / 1539 assertions, 9 native-only skips**. |
| 7 | **254 runtime natively** | **Inconclusive (C1)**; installer and guards pass natively | See *Native results* below. Every runtime case fails closed with the generic 503 at `ProductionFeatureOperation.php:119` (postcommit proof) inside the **253 fixture** `ProductionConsentPreferences::initialize`, before any 254 code runs. That matches C1. No 254 row was written by an unauthorised path, and no provider call happened. |
| 8 | **Mutations** | 4 of 6 killed by the author's suite; 2 survive it (R1) and the reviewer's case kills them | See *Mutations*. |

## Findings

| ID | Severity | Finding | Evidence | Recommendation |
| --- | --- | --- | --- | --- |
| C1 (carried) | **High (blocks activation and final acceptance)** | The 253 native deadline exhaustion (prior DECISION) still applies. On this host, no 254 runtime case can reach 254 code natively, so the claims below hold on SQLite only. 254 adds held-frame dictionary admission (`assertHeld`) on every `ProductionSuppressionRows` construction and on each committing and postcommit guard pass, which adds to the C1 statement volume. | `ProductionSuppressionRows.php:29-35,65-84`; native receipts | Fix C1 without extending the deadline, then run the full `tests/Feature/ProductionSuppression` selection natively on the target host class before activation. |
| R1 | Low (test gap) | Removing the `recipientHmac` or `providerHash` comparison from `ProductionSuppressionReceipt::confirms` leaves the author's whole 254 suite green (44 tests). The code is correct today, but the receipt-scope invariant is only half pinned. | Mutations M2/M2b; `ProductionSuppressionReceipt.php:17-18`; `ProductionSuppressionJourneyTest.php:62-68` (covers only request hash, status, operation id and a throw) | Add the reviewer's wrong-recipient-HMAC, wrong-provider-hash and other-account receipts to the journey test before or with the merge. |
| R2 | Low (product decision; activation gate) | After withdraw → re-grant, the *first* `request()` still records the target, intent and attempt and calls `suppress()` for the historical withdrawal. This happens because the 253 reader keeps returning the retained withdrawal while the current status is `granted`. With no unsuppress path, this permanently overrides the customer's current affirmative grant at the provider. It errs toward not mailing, so it is not consent inference. It is still a customer-visible semantic that no one has decided. | Reviewer case `test_runtime_withdraw_then_regrant_then_first_request_still_suppresses` (1 `suppress` call, status `unknown`); `ProductionConsentWithdrawal.php:75-104`; `ProductionSuppressionIntents.php:41-47` | Sean decides explicitly before activation. Either a new target or attempt requires the *current* status to be withdrawn, or the behaviour is documented with a defined re-subscribe path. |
| R3 | Info | With `enabled=true` and `provider=null`, entry points do not refuse. They durably record a `pending` target and intent, which is the designed behaviour. They also call `boundTo()` in process (6 calls per request/reconcile), including from the commit fence inside the held transaction. The shipped `enabled=false` refuses everything with zero provider calls. | `ProductionSuppressionIntents.php:237-256` | State in `ProductionSuppressionProvider::boundTo()` that it must be pure and configuration-only (no I/O), because it runs inside the held transaction. |
| R4 | Info | C2 liveness means "the minting callback frame has not returned". A fiber suspended inside the callback leaves the context live, and code outside the callback frame can read the withdrawal until the fiber resumes. This is the same trust boundary as the callback handing its context to other code. A second concurrent `run()` still refuses, and the context is dead once the fiber completes. | Reviewer case `test_c2_fiber_suspended_run_cannot_start_a_second_concurrent_run` (`released-while-suspended`) | None required. Keep fibers out of sealed callbacks. |
| R5 | Info | Private statics (`ProductionFeatureOperation::$live` and both `WeakMap`s) are reachable through Reflection and also through `Closure::bind` to the private scope, which is not the Reflection API. Both are deliberate scope violations, outside the stated model. | Reviewer case output: `reachable via Reflection: yes; via Closure::bind: yes` | Document both as out of model. Do not treat the seal as a hostile-code boundary. |
| R6 | Info | `ProductionSuppressionRequest::fromRecords` is public static (`@internal` in the docblock only). Server code can mint a transport request with any recipient. It cannot reach `reconcile()` confirmation, because the claim comes only from the sealed run. | `ProductionSuppressionRequest.php:23-27` | Consider restricting minting to `ProductionSuppressionRecords` before a real adapter is bound. |
| R7 | Info | `serverSnapshot()` on a `ProductionConsentWithdrawal` that a callback leaked out of `run()` still returns the recipient. The reader is sealed; the minted object is not tied to liveness. 254 uses it only inside the callback. | `ProductionConsentWithdrawal.php:108-116`; `ProductionSuppressionIntents.php:41-47` | Optional: make `serverSnapshot()` require the minting context to be live. |
| R8 | Info | The recipient travels as plain string arguments (for example `fromRecords(..., string $recipient, ...)`). This CLI has `zend.exception_ignore_args=On`, and the runtime's `catch (Throwable)` paths drop the original trace. A production php.ini with `ignore_args=Off` could still show truncated arguments in traces from deeper frames. | `ProductionSuppressionRequest.php:24`; `ProductionSuppressionRecords.php:93` | Mark recipient parameters `#[\SensitiveParameter]`. |
| R9 | Info (carried open item) | `unknown` attempts stay unknown forever in two cases: a failed postcommit proof (transport never ran), or rebinding to a different provider hash (`reconcile` builds no claim). | `ProductionSuppressionIntents.php:129-135`; journey `test_postcommit_authority_loss…` | An operator procedure is needed before activation (already listed as open in the lane README). |

No correctness or security defect was found in C2, C3 or the 254 schema and runtime as shipped (default-off and unbound).

## Mutations (each applied on its own at `452cdab1` in the red/green worktree, then reverted)

| ID | Mutation | Author suite | Reviewer suite |
| --- | --- | --- | --- |
| M1 | `suppress()` moved inside the run callback (before commit) | **Killed**: journey `…committed_before_transport…` ("Commit, then I/O.") and `…postcommit_authority_loss…` (2 failures / 7) | n/a |
| M2 | Recipient-HMAC and provider-hash comparisons removed from `Receipt::confirms` | **Survives**: `tests/Feature/ProductionSuppression` 44 tests / 556 assertions, OK (6 skips) | **Killed**: `wrong recipient hmac` |
| M2b | Only the provider-hash comparison removed | **Survives**: journey 7/7 OK | **Killed**: `wrong provider hash` |
| M3 | `assertLive()` removed from `ProductionConsentWithdrawal::capture` | **Killed**: `ProductionFeatureSealedRunTest` 2 failures / 3 | (also killed by the reviewer's C2 cases at `8773b720`) |
| M4 | `reconcile()` resends (`suppress()` before `inspect()`) | **Killed**: journey 2 failures / 7 | n/a |

## Native results (private MySQL 8.4.11)

| Selection | Server | Result | Receipt |
| --- | --- | --- | --- |
| `ProductionSuppressionNativeAdmissionTest` (6 native-only guard cases) | `:3447` | **6 / 96 OK** (matches the author) | `native-ProductionSuppressionNativeAdmissionTest.*` |
| Reviewer raw trigger probe (inserts, uniques, UPDATE/DELETE refusal, `down()` refusal) | `:3447` | **1 / 32 OK** | `trigger-probe-mysql84.txt` |
| `ProductionSuppressionDefaultConfigurationTest` | `:3427` | 2 tests: 1 pass, **1 error**, a fail-closed 503 at the 253 fixture `ProductionConsentPreferences::initialize` (C1) | `native-ProductionSuppressionDefaultConfigurationTest.*` |
| `ProductionSuppressionSealTest` | `:3427` | 2 tests: **2 errors**, same 503 at the 253 fixture `initialize` (C1) | `native-ProductionSuppressionSealTest.*` |
| `ProductionSuppressionJourneyTest` | `:3427` | 7 tests: **7 errors**, each the same fail-closed 503 (`ProductionFeatureOperation.php:119`, postcommit) inside the 253 fixture `initialize`, before any 254 code | `native-ProductionSuppressionJourneyTest.*` |
| `ProductionSuppressionMigrationTest` (27 cases) | `:3447` | **Interrupted** at the coordinator's request after **22 passes, 0 failures**; 5 cases not run; no JUnit | `native-ProductionSuppressionMigrationTest.txt` |

MySQL-only behaviour: on this host, each `migrate:fresh` takes about 3 minutes, and every 253 consent-preferences operation exhausts the 10 s budget in postcommit proof. That is C1, in 253/identity code. So **no 254 runtime claim is proven natively**. Native evidence covers only the installer, dictionary admission and the trigger guards. The earlier attempt to run the whole directory at once was stopped by the reviewer (output had not been flushed) and replaced by the per-class runs above; it is not a result.

## Non-scope

These were not reviewed or run:

- A real provider adapter, credentials, a provider account or scope, or transport.
- HTTP mount, routes, controllers and session capability.
- Email operations, DNS, queue or scheduler settings, and the operator procedure for `unknown` attempts.
- An email-change flow.
- Identity-less (background) reconciliation.
- MySQL 8.0, hosted CI or Foundation CI, the full store suite, and adjacent 250/251 or identity suites (the author ran these; the reviewer did not).
- MySQL concurrency or race proof of the 254 unique keys under parallel sessions.
- A repair of C1, and re-review of 253 beyond C2/C3, or of identity 101.

## Commands and results

| Command (from the worktree indicated) | Result | Receipt |
| --- | --- | --- |
| `PHPUNIT tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures tests/Feature/ProductionAccountFeatures tests/Feature/ProductionSuppression` (review, `452cdab1`, SQLite) | **138 / 1539, OK, 9 skips** (matches the author) | console |
| `PHPUNIT tests/Feature/ReviewSupp254` (review, `452cdab1`, SQLite): reviewer C2, C3 and runtime cases | **10 / 79 OK** | `review-evidence/Suppression254AdversarialReviewTest.php.txt` |
| C2/C3 subset plus the author's `ProductionFeatureSealedRunTest` and `ProductionConsentWithdrawalSealTest`, at `8773b720` | **red**: 10 tests, 3 errors, 5 failures | `rg-8773b720.txt` (recipient redacted) |
| Same, with the `app/` tree of `2580dc2a` | C2 green; C3 **red** (3 failures) | `rg-2580dc2a.txt` |
| Same, with the `app/` tree of `7c99720d` | **10 / 70 OK** | `rg-7c99720d.txt` |
| `PHPUNIT tests/Feature/ReviewSupp254/Suppression254NativeTriggerReviewTest.php` (SQLite) | **1 / 32 OK** | `trigger-probe-sqlite.txt` |
| The same on native `:3447` | **1 / 32 OK** (2 m 50 s) | `trigger-probe-mysql84.txt` |
| Mutations M1–M4 | see table | `mut-*.txt` |
| Native per-class runs (`native-a.sh` on `:3427`, `native-b.sh` on `:3447`) | see *Native results* | `native-*.txt`, `native-*.junit.xml` |

`PHPUNIT` = `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --`.

All receipt files named above are copied to `review-evidence/`. Recipients are redacted in the `rg-*` files. Runner scripts are saved as `native-a.sh.txt` and `native-b.sh.txt`.

Throwaway source sha256:

- `Suppression254AdversarialReviewTest.php.txt`: `6e88cbb9a50203862047a87333666738202843d2e92fb24778eb626a63ec93c3`
- `Suppression254NativeTriggerReviewTest.php.txt`: `f33634af8d7fc434eecedc596f1abd9b0a7826922c6397b2f7eba8aad414ef1c`

## Conditions

1. **C1** blocks activation, Foundation final verification and final acceptance, as before. Once C1 is repaired, the full `tests/Feature/ProductionSuppression` selection must pass natively on MySQL 8.4 on the target host class.
2. **R1**: add the missing receipt-scope cases (recipient HMAC, provider hash, cross-account) to the 254 suite with, or immediately after, the development merge.
3. **R2**: Sean decides the withdraw → re-grant → first-request semantics before any activation or provider binding.
4. Defaults stay as shipped (`enabled=false`, `provider=null`), with no route and no adapter. Binding a provider or enabling the parent needs a separately reviewed adapter, scope and operator procedure (R9), plus Sean's authorization.

## Cleanup

- Both private servers (`:3427` and `:3447`) were stopped with `mysqladmin shutdown`. Both ports were confirmed closed.
- Their datadirs (`scratchpad/mysql-rev254` and `scratchpad/mysql-rev254b`) and sockets (`/tmp/claude-0/s3427.sock` and `/tmp/claude-0/s3447.sock`) were removed.
- Both review worktrees were removed with `git worktree remove --force`.
- Nothing was committed or pushed.

## Addendum 1: delta `452cdab1..cd495445` (2026-10-07)

**Verdict: APPROVE WITH CONDITIONS carries to `cd4954455fd90fa20e37d01a5f1573b147e64ee4`** (tree `57a7a7e0568da0e88e55a6ffd01136c6d08988fa`). **R1 is closed.** R3 and R8 are addressed. C1, R2 and R9 remain as conditions. The reviewer did this in a fresh detached worktree `/home/user/VA-Studio-review-supp254b`, on SQLite only, with no MySQL.

### Scope of the delta

Scope was read with `git diff 452cdab1 cd495445 -- app tests config database`. Nothing under `config/` or `database/` changed.

| Commit | Change | Assessment |
| --- | --- | --- |
| `939f8160` (R1) | Adds `test_receipts_scoped_to_another_recipient_provider_or_account_never_confirm` to `ProductionSuppressionJourneyTest`, plus an optional `$email` on the `withdrawn()` helper. It covers five receipts: a wrong recipient HMAC, another account's recipient HMAC, a wrong provider hash, another account's whole receipt and another account's operation id. Each answer differs in only the named field. After each one it asserts no confirmation and no resend. A positive control then confirms, and the other account stays `unknown`. | Sound. The positive control rules out a broken fixture. |
| `f7a73aa6` (R3) | Docblock only: `boundTo()` must be pure and configuration-only, because it runs inside the held transaction at the commit fence. | Matches R3. No behaviour change. |
| `7d0027aa` (R8) | `#[\SensitiveParameter]` on `ProductionSuppressionRequest::fromRecords($recipient)`, the private constructor's `$values`, `ProductionSuppressionRecords::request($capture)` and `encrypt($plain)`. | Matches R8. A direct probe with `zend.exception_ignore_args=0` (a forced TypeError calling `fromRecords`) shows the recipient redacted from `getTrace()` and `getTraceAsString()`. A first probe through `ReflectionMethod::invoke` did show the address, but only because the `invoke` frame's own arguments are not marked sensitive. That is an artifact of the probe, not a runtime path. |
| `0ef61400`, `cd495445` | Docs only (README section and this DECISION). | No code impact. |

The 253 sources, the 254 runtime logic, the schema, the default-off configuration and the deadline are unchanged.

### Commands and results (SQLite, worktree at `cd495445`)

Each mutation was applied on its own, then reverted with `git checkout -- app`. `git status` was clean afterwards apart from the evidence directory.

| Command | Result | Receipt (`review-evidence/addendum-1/`) |
| --- | --- | --- |
| `PHPUNIT tests/Feature/ProductionSuppression/ProductionSuppressionJourneyTest.php` | **8 tests / 121 assertions OK** | `journey.txt` |
| M2: remove both the recipient-HMAC and provider-hash comparisons from `ProductionSuppressionReceipt::confirms` | **Killed**: 1 failure, `wrong recipient hmac` | `m2.txt` |
| M2b: remove only the provider-hash comparison | **Killed**: 1 failure, `wrong provider hash` | `m2b.txt` |
| M2c: remove only the recipient-HMAC comparison | **Killed**: 1 failure, `wrong recipient hmac` | `m2c.txt` |
| `PHPUNIT tests/Feature/ProductionSuppression` | **45 tests / 577 assertions OK, 6 native-only skips** | `suite254.txt` |

### Remaining conditions (unchanged)

1. **C1** blocks activation, Foundation final verification and final acceptance. After the fix, the full 254 selection must pass natively on MySQL 8.4.
2. **R2**: Sean decides the withdraw → re-grant → first-request semantics before any activation or provider binding.
3. **R9**: an operator procedure for stuck `unknown` attempts is needed before any provider binding.
4. Defaults stay shipped: `enabled=false`, `provider=null`, no route and no adapter.

R4–R7 remain informational and out of model.
