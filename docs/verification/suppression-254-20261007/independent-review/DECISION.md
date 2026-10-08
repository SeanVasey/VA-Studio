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

## Addendum 2: delta `72d7ae29..e7593a9c` (2026-10-08)

**Verdict: APPROVE WITH CONDITIONS carries to `e7593a9c8efb8e2e617fcb26acdefd69b35c3286`** (tree `7b4506dc4b18b69098c1be913f517b48f799e18f`) for a reversible development merge. The three trigger-guard changes are correct on SQLite and on native MySQL 8.4.11. No malformed `created_at` or `public_id` value committed in any `sql_mode`. Every value MySQL coerced and committed reads back valid under `ProductionFeatureShape::timestamp()` and `Str::isUuid()`. No new blocking condition for the development merge. C1, R2 and R9 remain. A2-7 (a pre-existing native-only test setup error) must be fixed before C1's native exit run can pass.

Authorship: an independent reviewer harness (Claude) that authored no lane commit wrote this addendum. A previous reviewer agent for this addendum ran the native work and probes below, then stopped (rate limit) before writing the addendum. This reviewer re-read every receipt, checked every claim against the receipts and source, ran the three new tests again on SQLite with a saved receipt, computed the hashes and did the cleanup. An earlier agent's leftover worktree `/home/user/VA-Studio-review-supp254c` (at `9bc6c4b1`) was not used or modified. No app code was changed and nothing was committed.

### Scope of the delta

`git diff --stat 72d7ae29 e7593a9c` touches exactly one source file, `app/Domain/Customers/ProductionFeatures/Suppression/ProductionSuppressionSchema.php` (26 insertions, 5 deletions). The rest is three new tests, the lane README and the lane's `conditions/{codex-intent-timestamp,codex-public-id-uuid,codex-timestamp-shape}/` evidence.

| Commit | Change | Assessment |
| --- | --- | --- |
| `9bc6c4b1` | The intents BEFORE INSERT guard also requires `e.created_at<=NEW.created_at` for the referenced withdrawal event (`ProductionSuppressionIntentGuardTest`) | Correct. It matches the read-time ordering. Killed on SQLite and natively when removed (M6). |
| `94fefb7e` | The targets, intents and attempts guards require the exact `Str::isUuid()` shape of `public_id`. SQLite uses `length()=36 AND GLOB`; MySQL uses `CHAR_LENGTH()=36 AND REGEXP` on the `ascii_bin` column (`ProductionSuppressionPublicIdGuardTest`). | Correct on both engines. Every value MySQL committed satisfies `Str::isUuid()` on read. |
| `e7593a9c` | All four insert guards require the exact `ProductionFeatureShape::timestamp()` shape of `created_at`. SQLite: `GLOB`, plus an hour bound, plus a `datetime()` round trip. MySQL: `CHAR` `REGEXP`, plus `YEAR()>0`, plus an `STR_TO_DATE` round trip (`ProductionSuppressionTimestampGuardTest`). | Correct on both engines (see the MySQL statement below and A2-3). |

### Environment

- Review worktree `/home/user/VA-Studio-review-supp254d`, detached at `e7593a9c`, with vendor symlinked and its own autoload. `git diff --quiet HEAD` exits 0, so the tracked tree is clean. SQLite mutations ran in a throwaway worktree, `/home/user/VA-Studio-review-supp254d-mut`, at the same SHA. That worktree has been removed (`git worktree list` no longer shows it).
- PHP 8.4.26 and PHPUnit 12.5.34, run through `PHPUNIT` as defined above. `public/build` was absent. `APP_KEY` was the synthetic key from the lane README (`base64:` of 32 × `I`).
- Two private MySQL 8.4.11 servers, each started with `mysqld --no-defaults --initialize-insecure`. Both were loopback-only, single-schema, with the synthetic root password `review-supp254d-only` and time zone tables loaded from `mysql_tzinfo_to_sql /usr/share/zoneinfo`:
  - `:3493`, datadir `$scratchpad/review-supp254d-mysql/data`, database `vaseyaudio_review_supp254d`. Used for the native directory run.
  - `:3494`, datadir `…/probe-data`, database `vaseyaudio_review_supp254d_probe`. Used for the probes and native mutations. The schema was migrated by the real installer (`migrate:fresh`). The 253 parents were seeded through raw inserts that pass the 253 guards (`probes/seed.txt`, `probes/parents.json`).
- Server defaults were Laravel's strict `sql_mode`, `time_zone=SYSTEM` and `system_time_zone=UTC`.
- The shared `:3306` server was never listening and was never touched. Other lanes' private servers were not touched. At cleanup that included pid 3875 on `:3541` (`trigscan-fix-mysql`).
- Host load average was about 6–9, with other lanes' native runs active.
- One reviewer error is recorded in `native/private-instance-lifecycle.txt`. A second schema briefly existed on `:3493` (about 22:18:40Z to 22:19:13Z). The native directory run that overlapped it was killed and its output discarded. The run was then restarted from scratch at 22:19:42Z on the single-schema server, and that restarted run is the result below.

### Commands and results

Receipts are under `review-evidence/addendum2/`. Every row covers `e7593a9c8efb8e2e617fcb26acdefd69b35c3286`.

| Selection | Engine | Tests | Assertions | Failures | Errors | Skips | Exit | Receipt |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `PHPUNIT tests/Feature/ProductionSuppression` (whole directory, 2 h 13 m 48 s, 22:19:42Z to 00:33:30Z) | MySQL 8.4.11 `:3493` | **48** | 538 | 0 | **15** | **0** | **2** | `native/production-suppression-dir.{txt,junit.xml,exit.txt}`, runner `native/run-native.sh` |
| `PHPUNIT tests/Feature/ProductionSuppression` (unmutated baseline in the mutation worktree) | SQLite | 48 | 695 | 0 | 0 | 6 (native-only) | 0 | `mutations/baseline-sqlite-dir.txt` |
| `PHPUNIT` on the three new guard tests (`…IntentGuardTest`, `…PublicIdGuardTest`, `…TimestampGuardTest`), rerun by this reviewer | SQLite | 3 | 118 | 0 | 0 | 0 | 0 | `sqlite-new-guard-tests.{txt,junit.xml}` |
| `php tests/Feature/ReviewSupp254d/probe-254.php`: 307 raw PDO probes, each in its own rolled-back transaction | MySQL 8.4.11 `:3494` | 307 probes | n/a | 0 refusals left a row | n/a | n/a | n/a | `probes/probe-results.{txt,json}`, source `probes/probe-254.php.txt` |
| `php tests/Feature/ReviewSupp254d/mutate-native-254.php`, with mutated triggers created under strict and under `sql_mode=''` | MySQL 8.4.11 `:3494` | see *Mutations* | | | | | 0 and 0 | `mutations/native-mutations-mysql84{,-nonstrict-created}.txt`, source `probes/mutate-native-254.php.txt` |
| SQLite mutations, each reverted with `git checkout -- app` and checked with `git diff --quiet -- app` (exit 0 in each receipt) | SQLite | see *Mutations* | | | | | | `mutations/M-*.txt` |

`probes/superseded-run1-probe-bug.txt` is a first probe run that was discarded because of a bug in the probe: native prepares broke `SHOW WARNINGS` and `lastInsertId`, so the read-backs were empty. It is kept for the record and is not a result.

**Native directory errors, classified.** The run had 33 passes: the default-off configuration check, 26 of the 27 migration cases and all 6 native admission cases. It had 0 skips. Of the 15 errors:

- **14 are C1.** Each fails closed with the generic `ProductionFeatureException` inside the **253 fixture** `ProductionConsentPreferences::initialize` (`ProductionConsentPreferences.php:31,47`), before any 254 code runs. 12 errors come from the postcommit proof at `ProductionFeatureOperation.php:119`. The other 2 come from `ProductionFeatureContext.php:338`, `assertSource`, whose conditions include the 10 s deadline. They are consistent with C1 but not attributed to a single condition. The cases took 197–373 s each. These 14 cases are the default-configuration production refusal, all 7 journey cases, both seal cases, and the lane's three new guard tests. So **the lane's own MySQL halves of the three new tests did not run natively.** The raw probes below cover the MySQL guard text instead.
- **1 is A2-7.** It is not C1 and not in this delta.

### Native probes (MySQL 8.4.11, private `:3494`)

Across all 307 probes, every refusal left zero rows. All 156 accepted rows pass `ProductionFeatureShape::timestamp()` on a UTC read, and every accepted `public_id` passes `Str::isUuid()`. The 34 confirmations have no `public_id`.

| Probe | Result |
| --- | --- |
| **(a) Malformed timestamps.** The five lane values (`zzzz`, `2026-02-30 00:00:00`, `2026-11-31 00:00:00`, `2026-13-01 00:00:00`, `0000-00-00 00:00:00`) plus `2038-01-19 03:14:08` (TIMESTAMP overflow). Each was inserted raw on each of the four tables under four modes. | **96/96 refused, 0 rows left.** The refusing layer depended on the mode:<ul><li>**Strict** (Laravel's mode): the **column** refused all 24 (`1292 Incorrect datetime value`) before the trigger ran.</li><li>**`sql_mode=''`**: the **guard** refused all 24 (`1644`/`45000`). The value reached the trigger as the zero date, with a warning.</li><li>**`ALLOW_INVALID_DATES`**: the guard refused all 24. That mode does not apply to TIMESTAMP.</li><li>**Strict with `INSERT IGNORE`**: the guard refused all 24. `IGNORE` downgrades the column error to a warning but does not suppress the trigger's `SIGNAL`.</li></ul> |
| **(b) Inputs MySQL coerces.** A `T` separator, the fractions `.5` and `23:59:59.6`, `20261007120000`, missing seconds, `/` delimiters, a `+02:00` offset, leading, trailing and newline whitespace, hour 24 and second 60. Each was inserted on four tables under strict and non-strict modes. | **80 of 96 accepted.** Every accepted value is stored as `YYYY-MM-DD HH:MM:SS`, and **every stored value satisfies `ProductionFeatureShape::timestamp()` on read**:<ul><li>The `T` form stores as `2026-10-07 12:00:00`.</li><li>`.5` rounds up to `:01`.</li><li>`23:59:59.6` rolls over to `2026-10-08 00:00:00`.</li><li>The offset form stores the shifted instant: `12:00:00+02:00` becomes `10:00:00`.</li></ul>Hour 24 and second 60 were refused: by the column in strict mode and by the guard in non-strict mode. Coercion cannot commit a row that later reads refuse. |
| **(c) `public_id` shapes**, on targets, intents and attempts, under strict and non-strict modes | Uppercase and lowercase UUIDs are accepted, and the stored value passes `Str::isUuid()`.<br>The guard refuses each of these: the last character replaced by a space (36 characters), 35 characters plus NUL (36), 35 characters plus a newline (36), and a non-hex `g`.<br>A non-ASCII `é` is refused by the column (`1366`) in strict mode and by the guard in non-strict mode.<br>A valid UUID plus a trailing space (37 characters) commits as the valid 36-character UUID (A2-6). |
| **(d) Intent before its withdrawal.** An intent references a withdrawal event dated 1 s after the intent; the target and event chain are otherwise valid. Strict session. | **Refused by the guard.** The same instant is accepted (control). `00:00:09.6` rounds to `00:00:10` and is accepted, consistent with the stored value. The native mutation runs also refuse D1 with the installed text. |
| **DST.** Session `time_zone` set to `Europe/London` and to `America/New_York`, under strict and non-strict modes, on all four tables | **No valid instant was refused.**<ul><li>**Fall-back hour:** both occurrences, written as offset literals (`+01:00`/`+00:00` and `-04:00`/`-05:00`), are accepted. They store different UTC instants, and both pass the runtime check on a UTC read. A plain ambiguous literal resolves to the first occurrence.</li><li>**Spring-forward gap** (`2026-03-29 01:30:00` in London, `2026-03-08 02:30:00` in New York): these times are not instants. Strict mode refuses them at the column (`1292`). Non-strict mode moves them to the end of the gap with warning `1299`, and the guard accepts that valid instant.</li></ul>The guard's `CAST`/`STR_TO_DATE` comparison runs on the session-local DATETIME, so it does not depend on the time zone. |

**Does the MySQL timestamp guard behave as the implementer reasoned? Yes, under both strict and non-strict modes.**

- In strict mode, the TIMESTAMP column refuses every unparsable or calendar-invalid value before the trigger runs, and normalises the input it accepts.
- In non-strict mode, the same input reaches the trigger as the zero date, and the guard refuses it.
- No mode committed a malformed row. Every coerced value that was committed reads back valid.

There is one precision (A2-3). A trigger body runs under its **creation** `sql_mode`, not the writing session's. Under the installer's strict creation mode, `STR_TO_DATE` of the zero date returns NULL, and the guard fails closed through `COALESCE` even without `YEAR()>0`. `YEAR()>0` is the only term that carries the refusal when the trigger was created non-strict and the parent 253 event is itself zero-dated.

### Session time zone

The application does **not** pin MySQL sessions to UTC:

- `config/app.php` sets `'timezone' => 'UTC'` for PHP. `ProductionFeatureConfiguration` (`:61-64`) only requires `app.timezone` to be a non-empty string.
- The `mysql` connection in `config/database.php` has no `timezone` key. Laravel's `MySqlConnector` (`vendor/…/MySqlConnector.php:110-112`) issues `SET time_zone` only when that key is set.
- Nothing under `app/`, `database/`, `routes/`, `bootstrap/` or `config/` sets or asserts `@@time_zone`.

So sessions inherit the server's `default_time_zone`. That was UTC on this host and is UTC in CI's server, so the probes found no problem there. On a non-UTC server, the effects are:

- Stored instants shift, but app strings still round-trip.
- In strict mode, a UTC string that falls in the local spring-forward gap refuses the write. That fails closed, with no bad row, but a withdrawal or suppression cannot be recorded during that hour.
- In non-strict mode, such a string is moved by an hour.

The 254 guard itself refused no valid instant under either zone. The deployment fix is recorded as A2-4.

### Mutations

| ID | Mutation | Lane tests | Reviewer native probes |
| --- | --- | --- | --- |
| M5 | SQLite hour bound `substr(NEW.created_at,12,2)<'24'` removed | **Killed** on SQLite: `ProductionSuppressionTimestampGuardTest`, "targets accepted a created_at of hour 24" (48 tests, 1 failure, exit 1) | n/a. The MySQL text has no hour bound. Natively, hour 24 is refused by the column (strict) or the guard (non-strict). |
| M6 | `e.created_at<=NEW.created_at` removed from the intents guard | **Killed** on SQLite: `ProductionSuppressionIntentGuardTest` (48 tests, 1 failure, exit 1) | **Killed** natively under both creation modes: D1 (intent 1 s before `e2`) is accepted and stores `00:00:09` |
| M7 | MySQL `YEAR(NEW.created_at)>0` removed (targets and confirmations) | **Survives** on SQLite (48 tests, 695 assertions, OK, exit 0), where the clause does not exist. It is not exercised natively by the lane: those cases error on C1, and in strict mode the column refuses first. | Trigger recreated under the installer's strict mode: **survives**. The stored-mode `STR_TO_DATE` of the zero date is NULL, and the parent ordering also refuses. Trigger recreated under **`sql_mode=''`**: **killed** by Y1 and Y2. A zero-dated target whose parent is a zero-dated 253 event commits as `0000-00-00 00:00:00`. The unmutated text recreated under `sql_mode=''` still refuses Y1 and Y2, so `YEAR()>0` is the term that carries the refusal there. |
| M7b | MySQL `REGEXP` and `STR_TO_DATE` removed, `YEAR()>0` kept | n/a | Survives every native probe under either creation mode. On a `timestamp(0)` column, any non-zero stored value already matches both terms, so they are defence in depth. |

Each native mutation recreated only its own trigger on `:3494`. Every restore was proved by checking that `information_schema.TRIGGERS.ACTION_STATEMENT` equals the installer's body. The final check in both runs confirms that all 12 installed 254 triggers equal the installer text and carry the installer's strict `SQL_MODE`.

### Findings

| ID | Severity | Finding | Evidence | Recommendation |
| --- | --- | --- | --- | --- |
| A2-7 | **Low (test defect; blocks C1's native exit criterion)** | `ProductionSuppressionMigrationTest::test_recorded_gap_and_non_prefix_installation_refuse_without_repair` errors natively in its own setup. Its first half (recorded gap) passes, 7 assertions. The test then runs `DB::unprepared($this->steps()[1][2])` (`:119`), creating `production_suppression_intents` alone. MySQL refuses that with `1824 Failed to open the referenced table 'production_suppression_targets'`, because the FK target does not exist; SQLite does not check FKs at `CREATE`. So the non-prefix refusal is unproven natively. The defect has existed since `9423bc5d`; the original review's per-class native run was interrupted before reaching this case. It is independent of C1 and will remain after C1 is fixed. | `native/production-suppression-dir.txt` error 11; JUnit case at `:113` | Before the post-C1 native run, make the non-prefix fixture MySQL-valid: either pick a non-prefix step without an unmet FK, or create it with `FOREIGN_KEY_CHECKS=0` scoped to the fixture. Then rerun the case natively. |
| A2-1 | Low (test gap) | No lane test pins `YEAR(NEW.created_at)>0` (M7). The term carries the refusal only when the trigger was created under a non-strict `sql_mode` *and* the target's parent 253 event is itself zero-dated. | `mutations/native-mutations-mysql84-nonstrict-created.txt` | Keep the clause. Add a native case once the 254 suite runs natively after C1. |
| A2-2 | Low (adjacent to this delta, in 253) | The 253 `production_consent_events` insert guard has no `created_at` condition (`ProductionFeatureSchema.php:445-449`). In a non-strict session, a raw writer committed a withdrawn event with `created_at = 0000-00-00 00:00:00` (from `zzzz`). That row cannot be read or repaired: this is the same class as the Codex finding this delta fixes, on the 253 side. | `probes/seed-zero-date-parent.txt`, `probes/Supp254dSeedZeroDateParentTest.php.txt` | Route to the 253 owner: mirror the 254 timestamp condition on the 253 insert guards, with separate review. Not a 254 blocker. |
| A2-4 | Low (deployment configuration; pre-existing, outside this delta) | MySQL sessions are not pinned to UTC (see *Session time zone*). On a non-UTC server, stored instants shift. In strict mode, writes during the local spring-forward hour fail closed, so withdrawals and suppressions cannot be recorded in that hour. | DST probes; `config/database.php` `mysql` block; `MySqlConnector.php:110-112` | Before production, set `'timezone' => '+00:00'` on the `mysql` connection, or require a UTC server default in the deploy runbook (U-02). |
| A2-3 | Info | The implementer's comment says the `CHAR` pattern and the `STR_TO_DATE` round trip hold "under any sql_mode". A trigger body runs under its *creation* `sql_mode`. The installer's `owned()` check (`ProductionSuppressionSchema.php:443-446`) compares `ACTION_STATEMENT` but not `information_schema.TRIGGERS.SQL_MODE`. The guard is correct under either creation mode. | M7 and M7b receipts | Optional: reword the comment, or also pin `SQL_MODE` in `owned()`. |
| A2-5 | Info | On MySQL, `ProductionSuppressionTimestampGuardTest` counts any `QueryException` as a refusal, so it does not tell a column refusal from a guard refusal. The native probes above make that attribution. | `ProductionSuppressionTimestampGuardTest.php:106-110` | None required. |
| A2-6 | Info | A `public_id` that is a valid UUID plus a trailing space (37 characters) commits as the valid UUID, because `CHAR` drops trailing spaces before the trigger. The stored value is exactly `Str::isUuid()`-valid, so no unreadable row results. | Probe (c) | None. |

No correctness or security defect was found in the three guard changes.

### Residual

- **C1 is unchanged and was re-observed.** On this host, 14 of the 48 native cases fail closed in the 253 fixture `initialize`. This includes all 254 runtime cases and the lane's three new guard tests, so no 254 runtime claim, and no lane-test claim about the new MySQL guard text, is proven natively. The native evidence for this delta comes from the raw probes and native trigger mutations on a schema the real installer built.
- A2-7 keeps one migration case from passing natively, even after C1 is fixed.
- Not run: MySQL 8.0, concurrency between sessions, non-strict intent-before-withdrawal, hosted CI and Foundation CI. Intent-before-withdrawal was probed in strict sessions only; ordering compares stored values, which are the same in either mode for valid input.

### Decision and conditions

APPROVE WITH CONDITIONS carries to **`e7593a9c8efb8e2e617fcb26acdefd69b35c3286`** for a reversible development merge. No new blocking condition for that merge.

1. **C1** blocks activation, Foundation final verification and final acceptance. After it is fixed, the full `tests/Feature/ProductionSuppression` selection must pass natively on MySQL 8.4 on the target host class.
2. **A2-7** must be fixed before that native run, or condition 1 cannot be met.
3. **R2** remains an open activation gate for Sean: the withdraw → re-grant → first-request semantics. It is not re-decided here.
4. **R9** remains an open activation gate for Sean: an operator procedure for stuck `unknown` attempts, needed before any provider binding. It is not re-decided here.
5. Defaults stay as shipped: `enabled=false`, `provider=null`, no route and no adapter.

A2-2 (the 253 timestamp guard) and A2-4 (MySQL session time zone) are recommended follow-ups for their owners. They do not gate this development merge.

### Hashes at `e7593a9c` (SHA-256 of the committed blobs, equal to the worktree files)

- `app/Domain/Customers/ProductionFeatures/Suppression/ProductionSuppressionSchema.php`: `25843834cf85acef11d7e3c9284ac7eb94c11f4fceb13a3c5402e5b043f814ac`
- `tests/Feature/ProductionSuppression/ProductionSuppressionIntentGuardTest.php`: `6c40daacb23cd1ea442201e3c9461fbcda18a18cdf0c0d8cf534c7b964ece619`
- `tests/Feature/ProductionSuppression/ProductionSuppressionPublicIdGuardTest.php`: `cb3a6411ff3f93952ef4846b32ca203d6f3825a7bb7cb0a5b9d43cc191a88b8b`
- `tests/Feature/ProductionSuppression/ProductionSuppressionTimestampGuardTest.php`: `b1b801ab760a034d54bac5b5cf9bea25fe6ffe51d73ddb91f5f406f849f5b566`

### Evidence index (`review-evidence/addendum2/`)

- `native/`:
  - `production-suppression-dir.txt`, `.junit.xml` and `.exit.txt` (the directory run).
  - `run-native.sh` (the runner).
  - `private-instance-lifecycle.txt` (start, the reviewer error, shutdown, and the cleanup proof).
- `mutations/`:
  - `baseline-sqlite-dir.txt`, `M-hour-bound-removed.txt`, `M-intent-withdrawal-time-removed.txt` and `M-year-removed-sqlite.txt`.
  - `native-mutations-mysql84.txt` and `native-mutations-mysql84-nonstrict-created.txt`.
- `probes/`:
  - Results: `probe-results.txt` and `probe-results.json`.
  - Seeds: `seed.txt`, `parents.json` and `seed-zero-date-parent.txt`.
  - Sources as `.txt`: `probe-254.php.txt`, `mutate-native-254.php.txt`, `Supp254dSeedProbeParentsTest.php.txt` and `Supp254dSeedZeroDateParentTest.php.txt`.
  - `superseded-run1-probe-bug.txt` (not a result).
- `sqlite-new-guard-tests.txt` and `.junit.xml`: this reviewer's rerun of the three new tests.
- **Uncommitted, outside `docs/`:** `tests/Feature/ReviewSupp254d/` holds `probe-254.php`, `mutate-native-254.php`, `Supp254dSeedProbeParentsTest.php` and `Supp254dSeedZeroDateParentTest.php`. Each is byte-identical to its `.txt` copy under `probes/` (checked with `cmp`). The two `*Test.php` seeders write raw rows and must not run against a shared database. The integration owner decides whether to commit only the `.txt` copies, which is enough for the record, and delete this directory.

### Cleanup

- `:3494` was stopped with `mysqladmin shutdown` at 22:37:24Z. Its datadir, socket, pid file and logs were removed.
- `:3493` (pid 10325) was not shut down by the previous agent. It was absent after the container restart, and `err.log` holds no shutdown record.
- At 2026-10-08T03:07:54Z:
  - Neither pid 10325 nor pid 26432 was in `/proc`.
  - `/proc/net/tcp` showed no LISTEN entry on `:3493`, `:3494` or `:3306`.
  - No process held a cwd or fd under the datadir.
  - The only running `mysqld` was another lane's pid 3875 (`:3541`), which was left untouched.
- `$scratchpad/review-supp254d-mysql/` (236 MB, including `data/`, the socket and the logs) was then deleted with `rm -rf`, and `ls` confirms it is gone.
- The mutation worktree `/home/user/VA-Studio-review-supp254d-mut` was removed.
- No app code was changed. Nothing was committed or pushed.
