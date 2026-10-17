# B4f account-feature follow-up: hook-list reindex and playlist-create guard

Lane B4f closes the two P2 review findings that PR #127 deferred to "the next related account-feature batch" under Sean's freeze-and-batch rule. Nothing else is changed. Nothing here was pushed, no PR was opened or commented on, no thread was replied to or resolved, and no workflow or `scripts/ci/*.json` census file was touched. Both threads therefore remain open on GitHub until the lead publishes and replies.

Work was done in the worktree `/home/user/wt/b4f` on branch `harness/account-features-followup-20261017`, based on `origin/main` at `efa6eea5`.

## Tested source

- Tested source SHA: `bd76bb52fb0772f1303d9bfbcbbdfc9cd19d004c` (`fix: keep the feature hook list gap-free and guard playlist creation in the UI`).
- Every green result below ran on the final contents of those four files, which the commit then captured unchanged. The one edit made after the first runs was the single existing frontend assertion described under Finding 2, made before the whole-suite run. Source files were also reverted to `HEAD` temporarily for the red, mutation and baseline runs and restored byte-for-byte from saved copies each time (checked with `diff` and `git diff --stat`). No source or test edit followed the commit.
- The commit changes four files: `app/Domain/Customers/ProductionFeatures/ProductionFeatureOperation.php`, `tests/Feature/ProductionFeatures/ProductionFeatureTransactionEventsTest.php`, `resources/js/Pages/ProductionCustomerFeatures.tsx` and `tests/frontend/production-account-features.test.tsx`.
- The documentation commit that carries this packet and the `CHANGELOG.md` entry changes no application or test file.

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

Two files do not pass in this container, and they fail identically on the unmodified source, so neither is caused by this change:

- `ProductionAccountFeaturesHttpTest`: 28 tests, 233 assertions, 16 failures, 1 error, 0 skips, both with and without the fix. A temporary exception trace (since reverted) showed the first 503 originates at `AccountFeatureRequestGuard.php:51`, the class-identity admission of the request, auth manager, session guard and session decorator. The original PR #127 evidence recorded the HTTP and default-mount files together at 29/406/0/0/0 with its own recorder; that passing result could not be reproduced here, with either the lane's command or the retained HTTP recorder's bootstrap (checked on one test), so the real HTTP stack is not verified by this lane. I did not investigate the container difference further.
- `ProductionFeatureSourceClosureTest`: 3 tests, 13 assertions, 1 failure and 1 error, 0 skips, both with and without the fix (`test_genuine_lazy_secondary_resolver_cannot_rebind_a_saved_private_projection` and `test_source_resolver_after_a_consent_save_cannot_withdraw_purpose_after_its_guard`).

Logs: `logs/php-http-test-with-fix.txt`, `logs/php-http-test-unmodified-source-baseline.txt`, `logs/php-source-closure-with-fix.txt`, `logs/php-source-closure-unmodified-source-baseline.txt`.

During diagnosis I temporarily added logging to `bootstrap/app.php` and to the catch block of `AccountFeatureController::execute`; both were restored (`git checkout` and a saved copy) and `git status` showed only the four intended files.

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
| After | 2432 | 233 | 9207 | 677 |

So the native selection grows by 0, because the added tests are in a residual file; the discovered total grows by 5. The comment at `.github/workflows/final-verification.yml:151` says "2432 of 9202 cases in 233 files", which becomes stale on the discovered figure only (9207); that file is owned by lane F2c and was not touched. Logs: `logs/mysql-native-new-tests.txt`, `logs/mysql-native-release-test.txt`, `logs/native-selection-after.txt` (the before figure, 2432/233 of 9202/677, was printed by the same script and is recorded here from that output).

## Untested conditions and adjacent problems

- No real browser, WebKit or Playwright run, and no screen reader. The frontend evidence is jsdom with Testing Library and `userEvent`. In particular, real-browser implicit submission with a disabled default button was not observed (jsdom's `requestSubmit` path does not honor it, which is why the submit handler repeats the guard), and the accessible description is computed by the DOM test library, not announced by assistive technology. The helper text reuses default paragraph styling and no contrast measurement was made.
- The real HTTP stack is unverified here (see the unmodified-baseline HTTP failures above). The hook fix is covered through direct service calls with real `ProductionListeningLibrary` operations on a real connection, not through the HTTP controller.
- The server's name rules beyond non-empty and trimmed are not checked in the client: control and format characters (`\p{Cc}`, `\p{Cf}`) and any length rule are server-only. A name containing such characters still reaches the server, is refused, and takes the uncertain-outcome path. Closing that would need the server to supply the rule in `limits`; it was not invented client-side.
- JavaScript `trim()` strips more Unicode whitespace than PHP `trim()`, so a name made only of, for example, a non-breaking space is blocked by the client although the server would accept it. This is the conservative direction and was left as is.
- Adjacent, not changed: the Rename playlist button shares the same `name` state and is enabled for a whitespace-only name (`disabled={locked || !name}`) and sends the name untrimmed, so it can still hit the same server refusal and uncertain-outcome reload.
- Adjacent, not changed: the other frozen findings from PR #127 and earlier (unwrapped V1/generic/`IdentityOriginalCommitWitness` metadata and ordinary membership throwing-`afterCommit`) are untouched.
- The hook bookkeeping is in-process and single-threaded; no concurrency, timing or performance claim is made. Only the one connection's `beforeStartingTransaction` property is addressed.
- No Foundation, GitLab, hosted CI or full-suite run. No publication, thread reply or resolution.

## Attribution note

The lane brief asked commits to end with `Co-Authored-By: Claude Opus 5.5`. The session's own commit-attribution instruction names the model that actually did the work, Claude Sonnet 5.5, with the same `Claude-Session` line, and that is what the commits carry.
