# B4f account-feature follow-up: hook-list reindex, playlist-create guard and encrypted-session admission

Lane B4f closes the two P2 review findings that PR #127 deferred to "the next related account-feature batch" under Sean's freeze-and-batch rule. Nothing else is changed. Nothing here was pushed, no PR was opened or commented on, no thread was replied to or resolved, and no workflow or `scripts/ci/*.json` census file was touched. Both threads therefore remain open on GitHub until the lead publishes and replies.

Work was done in the worktree `/home/user/wt/b4f` on branch `harness/account-features-followup-20261017`, based on `origin/main` at `efa6eea5`.

A third change was added at the coordinator's direction after the first report: the account-feature request guard refused `Illuminate\Session\EncryptedStore`, which made `ProductionAccountFeaturesHttpTest` fail wherever `SESSION_ENCRYPT=true` (the `.env.example` default). I had reported those failures as pre-existing and unexplained; the lead found the cause, and it is fixed here in its own commit. It is described in its own section below and is not one of the two deferred findings.

## Tested source

- Tested source SHAs: `bd76bb52fb0772f1303d9bfbcbbdfc9cd19d004c` (`fix: keep the feature hook list gap-free and guard playlist creation in the UI`, the two deferred findings) and `3c2a0f8965e39f4d619861d64420f4831938cb16` (`fix: admit the encrypted session store in the account-feature request guard`, built on the first). The second commit contains the first, so its tree is the final tested source; the PHP and frontend reruns under "Rerun of the earlier selections" ran on it. The first-batch native MySQL runs ran on `bd76bb52`, whose application and test files for those runs are unchanged by the second commit.
- Every green result below ran on the final contents of those four files, which the commit then captured unchanged. The one edit made after the first runs was the single existing frontend assertion described under Finding 2, made before the whole-suite run. Source files were also reverted to `HEAD` temporarily for the red, mutation and baseline runs and restored byte-for-byte from saved copies each time (checked with `diff` and `git diff --stat`). No source or test edit followed the commit.
- `bd76bb52` changes four files: `app/Domain/Customers/ProductionFeatures/ProductionFeatureOperation.php`, `tests/Feature/ProductionFeatures/ProductionFeatureTransactionEventsTest.php`, `resources/js/Pages/ProductionCustomerFeatures.tsx` and `tests/frontend/production-account-features.test.tsx`. `3c2a0f89` changes two: `app/Http/AccountFeatures/AccountFeatureRequestGuard.php` and `tests/Feature/ProductionFeatures/ProductionAccountFeaturesHttpTest.php`.
- The documentation commits that carry this packet and the `CHANGELOG.md` entries change no application or test file.

## Finding 1: r4287886519, hook-list tail removal leaves numeric holes

Source: `app/Domain/Customers/ProductionFeatures/ProductionFeatureOperation.php`, the `finally` block of `run()`.

The operation installs its own `beforeStartingTransaction` tail as `[...$hooks, $tail]`, so the stored value is a list. When a `TransactionCommitting` listener, an `afterCommit` callback or a `TransactionCommitted` listener appends another hook during the operation, the list becomes `[earlier, tail, appended]`. The cleanup used `array_filter`, which keeps keys, leaving `[0 => earlier, 2 => appended]`. The next operation then refuses that value at its `array_is_list($hooks)` admission, so every later listening and preferences operation on that connection is unavailable until the worker is reset.

Fix: the filtered hooks are reindexed with `array_values` only when the stored value was a list. An associative replacement array is callback-owned and keeps its own keys and closures, exactly as before. The comparison that decides whether to write at all is unchanged.

Agreed triage, followed as written: a list-extension regression plus conditional reindexing that preserves associative replacements.

### Regression tests

Both live in `tests/Feature/ProductionFeatures/ProductionFeatureTransactionEventsTest.php`, beside the existing commit-phase event tests.

- `test_hook_appended_during_the_commit_phase_keeps_the_list_and_later_operations_available` runs once for each of the three channels (`TransactionCommitting` listener, `afterCommit` callback, `TransactionCommitted` listener). It registers an earlier hook, runs a real `initialize`, has the channel append a hook once, then runs a real `change` and a real `read` on the same connection. It asserts that the next operation is not refused (the failure message prints the stored hook keys), that the stored hooks are exactly the earlier hook followed by the appended hook as a gap-free list, that the appended hook keeps running at each later transaction start, and that no transaction is left open.
- `test_commit_phase_associative_hook_replacement_keeps_its_keys_and_closures` covers the associative case in two shapes: a callback-owned replacement that holds the owned tail under a string key beside a foreign hook, and a foreign associative replacement without the tail. In both, the stored value afterwards is exactly `['foreign' => $closure]`.

### Red, then green (SQLite)

Red was run against the unmodified `ProductionFeatureOperation.php` with only the new tests added, filtering to the five new cases.

| Run | Tests | Assertions | Failures | Errors | Skips |
| --- | --- | --- | --- | --- | --- |
| Red, unmodified source | 5 | 29 | 3 | 0 | 0 |
| Green, with the fix | 5 | 53 | 0 | 0 | 0 |

The three red failures are the three list-extension cases, one per channel. Each reports `Stored hook keys: [0,2]` and `Failed asserting that 'App\Domain\Customers\ProductionFeatures\ProductionFeatureException' is null`, which is the finding's symptom and mechanism. The two associative cases pass on both sides, as intended: they pin existing behavior.

Mutation check of the controls: with the fix temporarily changed to an unconditional `array_values($remainingHooks)`, the run was 5 tests, 51 assertions, 1 failure, 0 errors, 0 skips. The failing case is the associative one that keeps the owned tail under a string key, where `'foreign'` became `0`. So the controls do reject the naive fix the finding warns against. The file was restored from a saved copy and the committed diff is the conditional form.

Logs: `logs/php-hook-regression-red-unmodified-source.txt`, `logs/php-hook-regression-green.txt`, `logs/php-associative-control-vs-unconditional-reindex.txt`.

## Finding 2: r4287886535, playlist-create stays enabled when it cannot succeed

Source: `resources/js/Pages/ProductionCustomerFeatures.tsx`, the create-playlist form.

The button was enabled for an empty name, a whitespace-only name and a library already at the playlist limit. `ProductionListeningState::validateCommand` refuses the first two and `apply` refuses the third, but the client treats every failed mutation as uncertain, clears the library and forces a reload.

Fix, all of it a UX guard with the server still authoritative:

- The Create playlist button uses the native `disabled` attribute (no `aria-disabled`) unless the trimmed name is non-empty and `data.playlists.length` is below `data.limits.playlists`.
- When submission is unavailable, a visible paragraph explains why and is referenced by `aria-describedby` on both the input and the button. The input is the focusable one, so that is where a keyboard or screen-reader user meets the text; the button reference covers pointer and virtual-cursor users. When submission is available the paragraph and both references are absent, so there is no dangling reference. If both conditions apply, the capacity message wins.
- The form's submit handler repeats the check, so Enter in the field or a programmatic submit cannot send an invalid write even where a browser or test harness does not honor a disabled default button.
- The name is sent trimmed. The server refuses padded names (`trim($value) !== $value`), so sending the field untrimmed would reproduce the same uncertain-outcome path for a name like `" mix "` that the new guard would otherwise let through.
- Keyboard and focus behavior is unchanged: the input stays enabled and focusable, no focus is moved, and the existing status paragraph still receives focus on outcome messages.

Limits come from the server. `limits.playlists` is already in the library state the page receives and is validated by `validListeningLibrary`; the page reads it and hard-codes no capacity. The server does not provide a name-length rule (`limits` carries favorites, playlists, playlistTracks and, for schema 2, notes, noteCharacters, noteBytes). As instructed, no name-length or character-class check was added: only a non-empty trimmed name and the supplied playlist limit gate the button. The input's existing `maxLength={80}` is a pre-existing hard-coded attribute and was left alone.

### Frontend tests

Ten cases were added to `tests/frontend/production-account-features.test.tsx`, covering the boundaries asked for:

- empty, whitespace-only, and tab-and-space-only names: button disabled, explanation visible and exposed as the accessible description of both input and button, no request sent, library heading kept, no uncertain-outcome message;
- a valid name, and a valid name padded with spaces: button enabled, no hint, no `aria-describedby`, exactly one request, body `{ action: 'create-playlist', name, version }` with the trimmed name;
- a blank name becoming valid and back again, enabling and disabling the button and adding and removing the explanation;
- playlist count limit minus one (9): enabled, one request sent, and the library that comes back at the limit shows the capacity explanation;
- playlist count at the limit (10) with a valid name: disabled, capacity explanation, no request;
- at the limit with a blank name: the capacity explanation is the one shown;
- keyboard path with `userEvent`: Enter on an empty field and on a spaces-only field sends nothing and focus stays on the input; clearing, typing a valid name and pressing Enter sends exactly one request.

One existing assertion changed. In `requires fresh read after an uncertain version conflict and never resends the mutation`, after the fresh reload the old test expected the Create playlist button to be enabled with an empty name, which is the behavior the finding reports. It now expects the button disabled with the input enabled, then types a name and expects the button enabled. The rest of that test is untouched. The first full run after the source change caught this one failure, and it is the only existing test that needed changing.

### Red, then green (frontend)

| Run | Tests in file | Passed | Failed |
| --- | --- | --- | --- |
| New tests against the original page source (conflict test not yet updated) | 21 | 12 | 9 |
| With the fix | 21 | 21 | 0 |

The 12 that pass on the original source are the 11 existing tests (including the not-yet-updated conflict test, which still expected enabled) plus the plain valid-name case, a deliberate positive control. The nine failures are the three blank-name cases, the padded-name case, the blank-to-valid case, the limit-minus-one case, the limit case, the limit-and-blank case and the keyboard case. The red run temporarily restored the original page file from `HEAD`; the fixed file was copied back and verified identical.

Whole frontend suite with the fix: 66 files, 1151 tests, all passed (1,141 existing plus the 10 new, by arithmetic from the file count; the pre-change suite was not run separately). `npm run typecheck` exited 0. `npm run build` exited 0 (the existing large-chunk warning is unchanged). `public/build` was deleted afterwards and confirmed absent.

Logs: `logs/frontend-red-original-page.txt`, `logs/frontend-green-file.txt`, `logs/frontend-full-suite.txt`, `logs/typecheck.txt`, `logs/build.txt`.

## Commands

All from `/home/user/wt/b4f`. No composer or npm install or update was run.

PHP on SQLite, as specified for this lane (`<files>` was one test file per run, with `--filter` for the focused runs):

```
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <files>
```

For the session work the same command was prefixed with `SESSION_ENCRYPT=true` or `SESSION_ENCRYPT=false` (an environment variable overrides `.env`). Isolated-process HTTP tests inherit it.

Frontend, with `. /opt/nvm/nvm.sh && nvm use 24` (Node v24.22.0): `npx vitest run tests/frontend/production-account-features.test.tsx`, `npm test`, `npm run typecheck`, `npm run build`, then `rm -rf public/build`.

Style: `vendor/bin/pint --test` on the two touched PHP files passed.

Selection census: `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection`, run before and after the test additions with the generated `phpunit-ci-mysql-*` files deleted each time (they are gitignored; no committed file was written).

## Existing PHP tests run with the fix (SQLite)

Each file is one run; all passed with 0 failures, 0 errors and 0 skips unless stated.

| File | Tests | Assertions |
| --- | --- | --- |
| `ProductionFeatureTransactionEventsTest` | 13 (8 existing, 5 new) | 125 |
| `ProductionFeatureReleaseTest` | 12 | 91 |
| `ProductionFeatureSealedRunTest` | 3 | 13 |
| `ProductionFeatureTransactionOwnershipTest` | 2 | 24 |
| `ProductionFeatureJourneyTest` | 2 | 30 |
| `ProductionFeatureStateTest` (unit) | 4 | 21 |
| `ProductionAccountFeaturesDefaultMountTest` | 1 | 6 |
| `ProductionConsentGraphTest` | 5 | 51 |
| `ProductionConsentKeyRotationTest` | 2 | 18 |
| `ProductionConsentWithdrawalReaderTest` | 4 | 36 |
| `ProductionConsentWithdrawalSealTest` | 2 | 31 |
| `ProductionFeatureBoundaryTest` | 13 | 100 |
| `ProductionFeatureHeldFloorTest` | 3 | 18 |
| `ProductionFeatureMigrationTest` | 30 | 427 |
| `ProductionFeaturePlainConfigurationTest` | 7 | 58 |
| `ProductionListeningNotesCapacityTest` | 2 | 21 |
| `ProductionFeatureNativeAdmissionTest` | 3 | 0, all 3 skipped (native-only by design) |

Two files did not pass when this lane first reported. They failed identically on the unmodified source, so the hook-list and playlist changes did not cause either. Their later disposition is:

- `ProductionAccountFeaturesHttpTest`: 28 tests, 233 assertions, 16 failures, 1 error, 0 skips, both with and without the hook fix, under the container's `.env` (`SESSION_ENCRYPT=true`). Root-caused and fixed in `3c2a0f89`; see the next section. It now passes under both settings.
- `ProductionFeatureSourceClosureTest`: 3 tests, 13 assertions, 1 failure and 1 error, 0 skips, with and without either fix and under both `SESSION_ENCRYPT` settings. It does not share the session cause; see "SourceClosureTest has a different cause" below. It is not fixed here.

Logs: `logs/php-source-closure-with-fix.txt`, `logs/php-source-closure-unmodified-source-baseline.txt` (default environment, hook fix absent and present).

During diagnosis I temporarily added logging to `bootstrap/app.php`, to the catch block of `AccountFeatureController::execute` and to the catch block of `ProductionFeatureOperation::run`; each was restored (`git checkout` or a saved copy) and `git status` showed only intended files each time.

## Request guard refuses `EncryptedStore`: root cause and fix

Cause, as found by the lead and confirmed here: `.env.example` sets `SESSION_ENCRYPT=true`, `phpunit.xml` overrides only `SESSION_DRIVER`, and `config/session.php` reads `env('SESSION_ENCRYPT', false)`, so the session store is `Illuminate\Session\EncryptedStore`. The `AccountFeatureRequestGuard` constructor required `$decorator->store::class === Store::class` exactly, so it threw `ProductionFeatureException` and every account-feature request returned 503. Both Foundation workflow jobs and the focused-feedback workflow run `cp .env.example .env` (`.github/workflows/final-verification.yml` lines 215 and 285, `focused-feedback.yml` line 114) and set no `SESSION_ENCRYPT`, so the final integrated run would have failed on this file, and a production host that follows the template would refuse every request. The last Foundation pass predates PR #127.

Confirmation here, before touching the guard, with the setting passed as an environment variable (which overrides `.env`): the whole HTTP file passed with `SESSION_ENCRYPT=false` (28 tests, 400 assertions) and failed with `SESSION_ENCRYPT=true` (28 tests, 233 assertions, 16 failures, 1 error). The 400 plus the default-mount file's 6 assertions equal the 406 recorded for PR #127.

Fix: the single-class check is now `in_array($decorator->store::class, [Store::class, EncryptedStore::class], true)`. It stays an exact-class allowlist: no `instanceof`, and any other subclass is still refused. No other check in the guard was changed. The `scope()` reflection reads name `Store::class` as the declaring class; `attributes`, `id` and `name` are declared there and `EncryptedStore` only adds an `encrypter` property, so the reads work on the subclass. The new tests exercise that path (`requireCurrent()` calls `scope()`), and the real controller read returns 200 on an `EncryptedStore`.

Regression test: `test_request_guard_admits_exactly_the_two_framework_session_store_classes` in `ProductionAccountFeaturesHttpTest`, four cases. Each signs in through the real web stack, then builds the guard in a probe route, then performs the real controller read.

- Plain `Store` (`session.encrypt` false): admitted, read returns 200.
- `EncryptedStore` (`session.encrypt` true): admitted, read returns 200.
- Control: a plain `Store` built by a replaced session manager: admitted. This proves that swapping the manager is not itself a reason for refusal, so the next case is attributable to the store check.
- A custom `Store` subclass built by the same replaced manager: refused. The test asserts the exception is a `ProductionFeatureException` whose first trace frame is `AccountFeatureRequestGuard::__construct`, so the constructor's own store check refused it and no other layer did; the controller read returns 503 and no binding row exists.

The tests set `session.encrypt` themselves, so they do not depend on the environment variable; the 28 older tests do, which is why the whole file was run under both settings.

| Run | `SESSION_ENCRYPT` | Tests | Assertions | Failures | Errors | Skips |
| --- | --- | --- | --- | --- | --- | --- |
| Whole HTTP file, before the guard fix (28 tests) | false | 28 | 400 | 0 | 0 | 0 |
| Whole HTTP file, before the guard fix (28 tests) | true | 28 | 233 | 16 | 1 | 0 |
| New cases on the old guard (red) | false | 4 | 57 | 1 | 0 | 0 |
| New cases with the fix (green) | false | 4 | 65 | 0 | 0 | 0 |
| New cases with the fix (green) | true | 4 | 65 | 0 | 0 | 0 |
| Whole HTTP file with the fix (28 + 4) | false | 32 | 465 | 0 | 0 | 0 |
| Whole HTTP file with the fix (28 + 4) | true | 32 | 465 | 0 | 0 | 0 |

The one red failure is the `EncryptedStore` case: `ProductionFeatureException at AccountFeatureRequestGuard.php:51` (the old check). The plain, control and custom-subclass cases pass on the old guard, as intended: they pin existing behavior that must not widen. Mutation check: with the allowlist temporarily replaced by `! $decorator->store instanceof Store`, the run was 4 tests, 61 assertions, 1 failure, and the failing case is the custom subclass, so the exact-class intent is enforced by a test. The guard was restored from a saved copy and verified byte-identical before the commit.

Logs: `logs/php-http-test-session-encrypt-false-before-guard-fix.txt`, `logs/php-http-test-session-encrypt-true-before-guard-fix.txt`, `logs/php-guard-store-tests-red-old-guard.txt`, `logs/php-guard-store-tests-green-session-encrypt-false.txt`, `logs/php-guard-store-tests-green-session-encrypt-true.txt`, `logs/php-guard-store-tests-instanceof-mutation.txt`, `logs/php-http-test-session-encrypt-false-after-guard-fix.txt`, `logs/php-http-test-session-encrypt-true-after-guard-fix.txt`.

## SourceClosureTest has a different cause

`ProductionFeatureSourceClosureTest` did not share the session cause: it fails identically (3 tests, 13 assertions, 1 failure, 1 error, 0 skips) under `SESSION_ENCRYPT=true` and `SESSION_ENCRYPT=false`, before and after both fixes (`logs/php-source-closure-session-encrypt-true.txt`, `logs/php-source-closure-session-encrypt-false.txt`). A temporary trace inside `ProductionFeatureOperation::run` (restored afterwards; the trace is in `logs/php-source-closure-root-cause-trace.txt`) shows both failing tests end in an `IdentityException` thrown at `IdentityMetadataAdmission.php:148`, reached from `ProductionFeatureContext::expected()` and `assertSource()`.

That line is the first connection-shape check in `inspect()`. Its condition includes `! $primary instanceof PDO`, where `$primary` is the raw `pdo` property of every registered connection; by reading the code and the test's setup, that is the only sub-condition the lazy connection can fail (I did not instrument which sub-condition fired). The two tests register a lazily resolved secondary SQLite connection inside a model `saved` callback, so the connection's raw `pdo` is still a closure when the metadata is re-inspected, and it is refused without being invoked. The two tests assert that the resolver was exercised (`$fired` true in one, a `ConsentException` of status 503 in the other), but the current code refuses an unresolved connection before invoking it. That ordering was introduced by the PR #127 hardening commits `cbe111ec`, `58f93c83` and `c978a63a` (2026-10-15), "admit reached identity metadata before feature source callbacks"; the test file was last changed on 2026-10-09 in PR #60 and was not part of PR #127's focused selections. The third test in the file, which asserts the callback is not invoked after commit, passes.

So these two tests appear stale relative to intentional hardening, not to reveal a product defect. I did not change them or the production code: deciding whether they should now assert refusal-without-invocation, and whether the generic `ProductionFeatureException` that `abort()` produces here is the intended type, is outside this batch. It is still a red file in any full SQLite run and should be dispositioned by whoever owns the metadata-admission change.

## Rerun of the earlier selections on the committed tree

After `3c2a0f89`, the earlier B4f PHP selections were rerun on SQLite with `SESSION_ENCRYPT=true` (the `.env` default, matching CI). All gave the same counts as before, with 0 failures, 0 errors and the same 3 skips (all in `ProductionFeatureNativeAdmissionTest`):

`ProductionFeatureTransactionEventsTest` 13 tests/125 assertions, `ProductionFeatureReleaseTest` 12/91, `ProductionFeatureSealedRunTest` 3/13, `ProductionFeatureTransactionOwnershipTest` 2/24, `ProductionFeatureJourneyTest` 2/30, `ProductionFeatureStateTest` 4/21, `ProductionAccountFeaturesDefaultMountTest` 1/6, `ProductionConsentGraphTest` 5/51, `ProductionConsentKeyRotationTest` 2/18, `ProductionConsentWithdrawalReaderTest` 4/36, `ProductionConsentWithdrawalSealTest` 2/31, `ProductionFeatureBoundaryTest` 13/100, `ProductionFeatureHeldFloorTest` 3/18, `ProductionFeatureMigrationTest` 30/427, `ProductionFeaturePlainConfigurationTest` 7/58, `ProductionListeningNotesCapacityTest` 2/21, and `ProductionFeatureNativeAdmissionTest` 3 tests, 0 assertions, 3 skipped (native-only by design). The whole HTTP file ran under both settings (32/465 each, above). `ProductionFeatureSourceClosureTest` is unchanged at 3/13 with 1 failure and 1 error (above).

Frontend on the same tree: 66 files, 1151 tests, all passed; `npm run typecheck` exit 0. `vendor/bin/pint --test` passed on the four touched PHP files across both fix commits. `public/build` is absent. One early attempt to start three of the reruns in parallel launched only the first group, because shell variables were defined inside a backgrounded command list; the other groups were started again with the variables set, and the logs of the failed launches do not exist because nothing ran.

## Native MySQL and selection census

Native MySQL ran on this lane's own private Oracle MySQL 8.4.11 (`SELECT VERSION()` returned `8.4.11`): a new datadir at `/var/lib/va-mysql84-b4f/data`, `127.0.0.1:33586`, socket `/var/lib/va-mysql84-b4f/run/mysqld.sock`, X protocol off. It was initialized with `--initialize-insecure` and started with the commands in the lane brief; databases `vaseyaudio_catalog_import` and a second, `vaseyaudio_b4f_release`, were created so the two runs could proceed side by side without sharing tables. Each run used `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33586 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=<that database> DB_URL= DB_SOCKET=` plus `VA_LOCAL_DISPOSABLE_MYSQL=<that database>`, the marker `tests/Support/DisposableTestDatabase.php` requires. The first attempt without the marker was refused by that admission check before any test ran (5 errors, 0 assertions); it is a harness refusal, not a result. The server was stopped with `mysqladmin shutdown` (exit 0, "Shutdown complete" in the server error log, no process left on the port); the datadir is retained.

| Native run | Tests | Assertions | Failures | Errors | Skips |
| --- | --- | --- | --- | --- | --- |
| The five new regression cases (`--filter`) in `ProductionFeatureTransactionEventsTest` | 5 | 53 | 0 | 0 | 0 |
| Whole `ProductionFeatureReleaseTest` (the native-selected file that exercises the same operation) | 12 | 91 | 0 | 0 | 0 |

The five-case run took 20 minutes 56 seconds and the whole-file run 37 minutes 9 seconds, partly overlapping, so no timing claim is made. The new cases were not run red on MySQL: a pass there already shows the fix was loaded, because the three list-extension cases fail without it. During the five-case run the application file was briefly reverted for the unrelated `ProductionFeatureSourceClosureTest` baseline; PHP had already loaded the class in that process, and the pass is consistent with the fix having been in effect throughout.

`ProductionFeatureTransactionEventsTest` is in `scripts/ci/database-mysql-selection.json` as a reviewed residual file (driver-branching, deliberately not part of the native selection), so only the five new cases were run natively here, not the whole file. The fix itself is in-process property bookkeeping around PDO begin and commit and is not engine-sensitive.

Selection-count delta, from `phpunit-shards.py --mysql-native-selection` before and after the change (generated files deleted, no census file edited):

| | Selected native tests | Selected files | Discovered tests | Discovered files |
| --- | --- | --- | --- | --- |
| Before (base `efa6eea5`) | 2432 | 233 | 9202 | 677 |
| After the hook-list tests (`bd76bb52`) | 2432 | 233 | 9207 | 677 |
| After the guard tests (`3c2a0f89`) | 2432 | 233 | 9211 | 677 |

So the native selection grows by 0, because the added tests are in a residual file; the discovered total grows by 5. The guard commit adds four more cases to `ProductionAccountFeaturesHttpTest`, which is in none of the selection lists (not an include, pattern or residual file, and not in the SQLite or MySQL skip census), and the policy proof still passes: selected 2432 tests in 233 files of 9211 discovered in 677 files, so selected grows by 0 and discovered by 9 against the base in total (5 hook cases, 4 guard cases). The comment at `.github/workflows/final-verification.yml:151` says "2432 of 9202 cases in 233 files", which becomes stale on the discovered figure only (now 9211); that file is owned by lane F2c and was not touched. Guard follow-up, native: the same private server (8.4.11, same datadir and port) was started again. The four new `test_request_guard_admits_exactly_the_two_framework_session_store_classes` cases ran on `vaseyaudio_catalog_import` with `SESSION_ENCRYPT=true` and the same disposable-database marker: 4 tests, 65 assertions, 0 failures, 0 errors, 0 skips, in 14 minutes 17 seconds. `ProductionAccountFeaturesHttpTest` is not native-selected, so the other 28 tests were not run on MySQL here and the file as a whole has SQLite evidence only. The server was stopped with `mysqladmin shutdown` (exit 0, "Shutdown complete" in the server log) and port 33586 was confirmed free; other lanes' `mysqld` processes were not touched. Neither touched file of the guard commit is in the native selection.

Logs: `logs/mysql-native-new-tests.txt`, `logs/mysql-native-release-test.txt`, `logs/mysql-native-guard-store-tests.txt`, `logs/native-selection-after.txt`, `logs/native-selection-after-guard-fix.txt` (the before figure, 2432/233 of 9202/677, was printed by the same script and is recorded here from that output).

## Untested conditions and adjacent problems

- No real browser, WebKit or Playwright run, and no screen reader. The frontend evidence is jsdom with Testing Library and `userEvent`. In particular, real-browser implicit submission with a disabled default button was not observed (jsdom's `requestSubmit` path does not honor it, which is why the submit handler repeats the guard), and the accessible description is computed by the DOM test library, not announced by assistive technology. The helper text reuses default paragraph styling and no contrast measurement was made.
- The hook fix is covered through direct service calls with real `ProductionListeningLibrary` operations on a real connection, not through the HTTP controller. The HTTP file now passes under both session settings on SQLite, but it is not in the native MySQL selection and was not run as a whole on MySQL; only the four new guard cases were (see below). The SQLite HTTP run exercises the framework test client in isolated PHP CLI children, not a physical SAPI or host.
- `ProductionFeatureSourceClosureTest` still fails two tests (see above); the unresolved-connection ordering it exposes is neither fixed nor ruled on here.
- Only the two exact framework session store classes were considered. Other session drivers (database, file, cookie) build the same two store classes via `SessionManager::buildSession`; only the array driver was run here.
- The server's name rules beyond non-empty and trimmed are not checked in the client: control and format characters (`\p{Cc}`, `\p{Cf}`) and any length rule are server-only. A name containing such characters still reaches the server, is refused, and takes the uncertain-outcome path. Closing that would need the server to supply the rule in `limits`; it was not invented client-side.
- JavaScript `trim()` strips more Unicode whitespace than PHP `trim()`, so a name made only of, for example, a non-breaking space is blocked by the client although the server would accept it. This is the conservative direction and was left as is.
- Adjacent, not changed: the Rename playlist button shares the same `name` state and is enabled for a whitespace-only name (`disabled={locked || !name}`) and sends the name untrimmed, so it can still hit the same server refusal and uncertain-outcome reload.
- Adjacent, not changed: the other frozen findings from PR #127 and earlier (unwrapped V1/generic/`IdentityOriginalCommitWitness` metadata and ordinary membership throwing-`afterCommit`) are untouched.
- The hook bookkeeping is in-process and single-threaded; no concurrency, timing or performance claim is made. Only the one connection's `beforeStartingTransaction` property is addressed.
- No Foundation, GitLab, hosted CI or full-suite run. No publication, thread reply or resolution.

## Attribution note

The lane brief asked commits to end with `Co-Authored-By: Claude Opus 5.5`. The session's own commit-attribution instruction names the model that actually did the work, Claude Sonnet 5.5, with the same `Claude-Session` line, and that is what the commits carry.
