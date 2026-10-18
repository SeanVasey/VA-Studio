# F3 foundation repairs: seven stale tests on main after PRs #117 to #131

Lane F3 repairs the seven test failures that the pre-Foundation SQLite sweep found on `main`. Each failure was introduced by a recent merge, and each reproduces in isolation. **No production code changed:** every commit touches only `tests/`, this packet and `CHANGELOG.md`. Nothing was pushed, no PR was opened or commented on, and no workflow was dispatched (CI cost policy, AGENTS.md).

- Worktree `/home/user/wt/f3`, branch `harness/foundation-repairs-20261018`, based on `origin/main` at `efb22df3850ba8500b6d424ceedef64649896fe8`.
- Tested source: `30cca9b6d444755ee106221bcc4b56638e4a783d` (the last test commit). The docs commit that carries this packet and the `CHANGELOG.md` line changes no application or test file.
- Commits: `ee44f54e` (group 1, #127 identity admission), `24ea3543` (group 2, #131 connection admission), `d7e73798` (group 3, Tax `http_mounted` pin), `30cca9b6` (group 4, staging census), all `test:` and local only.
- Sweep logs that found the failures: the lead's scratchpad `s1/` (`p1b1.*`, `p1b2.*`, `solo/`, `hist/`). Dating there: group 1 passes at `9cfe3a61` and fails at `81eb44b0` (#127); group 2 passes at `f3b00476` and fails at `3b90ce47` (#131).
- Runtime: PHP 8.4.26, PHPUnit 12.5.34. SQLite runs used the lane command (`APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CI=true`, `GITHUB_ACTIONS=true`, `--fail-on-phpunit-warning`, `memory_limit=512M`) with `.env` copied from `.env.example` and `public/build` absent. The native runs used a private Oracle MySQL 8.4.11 (details below).

## Summary

| # | Test | Introduced by | Cause | Decision | Change |
| --- | --- | --- | --- | --- | --- |
| 1a | `MemberOriginalFinishBoundaryTest` positive control | #127 (`cbe111ec`) | The shared `IdentityCommittedFrame` now runs `IdentityMetadataAdmission::requireCurrent`, which refuses any `db`/`config`/`env` container hook before it can run | #127 is intended; re-pin | Positive control now runs in a configuration the admission accepts; a new sibling test pins the frame's own refusal of the hook |
| 1b | `MemberGrantFactsCallbackClosureTest` "committed identity_configuration" | #127 | The admission captures `production-customer-identity`, so the frame refuses the change at `SealedMemberGrantFacts.php:154` with `IdentityException` before the member owner-input check | Intended; re-pin | That one case expects the earlier `IdentityException` (reason `unavailable`) and proves the raw phase cannot follow; all six cases now also assert that no member or membership row is written |
| 1c | `MemberGrantDefinitionsTest` remaining config hook | #127 | The frame refuses the hook at birth, so it never executes (`$calls` 0) | Intended; re-pin | Asserts zero executions during the refused receipt, then proves the hook was live (the next config resolution runs it once) |
| 2 | `MemberOriginalIssuerOwnershipTest` (two tests) | #131 | `TestCase::setUpTraits` opens and admits the default connection before every test | Test harness change; keep the property | Purge every open connection first, assert none, then keep the exact original `[] === DB::getConnections()` assertion |
| 3 | `ProductionTaxCheckoutPolicyTest` shipped pin | #124 (`a565e54a`) | `config/production-tax-checkout.php` gained `'http_mounted' => false` | Pin the shipped literal | Add the key to the pinned array |
| 4 | `StagingEnvironmentAdmissionTest` source census | #117, #120, #122, #123, #124, #125 | Seven new local/testing gates were not classified | All seven are local-only and never admit staging | Add them to `LOCAL_ONLY_FILES` |

## Group 1: PR #127 identity metadata admission in the shared committed frame

### Cause

`cbe111ec` (merged in #127 as `81eb44b0`) made `IdentityCommittedFrame` capture an `IdentityMetadataAdmission` at birth (`IdentityCommittedFrame.php:36`) and call `requireCurrent($primary)` at the top of every `connectionCurrent()` (`:175`), which runs from `begin`, `assertActive` and `finish`. `IdentityMetadataAdmission::inspect` refuses:

- any global before/resolving/after callback (`IdentityMetadataAdmission.php:95-99`);
- any `aliases`, `beforeResolvingCallbacks`, `resolvingCallbacks`, `afterResolvingCallbacks` or `extenders` entry keyed `db`, `config` or `env` (`:100-107`);
- any change since capture to the plain `app`, `database` or `production-customer-identity` configuration, the connection cohort or the native primaries (`:45`, `:115-161`).

Before #127 the frame resolved `DB::connection()` (and with it `config`) without that admission, so a config `beforeResolving` hook executed inside and after the frame's native commit. The three tests were written against that older frame.

### Is the stricter refusal intended?

Yes.

- #127's design, `docs/verification/account-features-http-20261015/design.md:42-44`: "Capture original manager/cohort/connection/native identities for source/frame/feature-operation lifetimes and recheck after callbacks", "no lazy resolver in terminal proof", and "Apply the helper before reached metadata access in ... `IdentityCommittedFrame` birth/current proof."
- The component review of `cbe111ec` (`metadata-review-cbe111ec-original.md`) asked for more of this closure, not less.
- Precedent: `ProductionFeatureSourceClosureTest` was re-pinned to the same hardening in `docs/verification/account-features-followup-20261017/`, and the independent review (`independent-review-89f0340d-DECISION.md`) confirmed the intent against `design.md:42`.

### Does any real production path rely on config hooks?

No. Evidence is in `logs/hook-census.txt`.

- `grep` of `app/`, `config/`, `bootstrap/` and `routes/` finds no `beforeResolving`, `resolving`, `afterResolving`, `extend` or `alias` registration; the only matches are guards that inspect those maps.
- The booted application, both after console bootstrap and after a real HTTP kernel request to `/up` (200), has no global callbacks, no `db`/`config`/`env` key in any callback, alias or extender map, no contextual binding and no deferred `db`/`config`/`env` service. `bootstrap/providers.php` registers only `AppServiceProvider`, `AdminPanelProvider` and `SupportAttachmentServiceProvider`.

So #127 broke no legitimate production flow. The member, membership and account-feature callers of `IdentityCommittedFrame::begin` run in an application that never registers such hooks; only adversarial tests do.

### 1a `MemberOriginalFinishBoundaryTest`

The old `test_positive_control_shared_finish_reaches_unchanged_config_hooks_without_the_member_boundary` was the control for `test_config_and_global_hooks_cannot_execute_between_raw_proof_and_finish`. It showed that the shared finish, without the member boundary, executed a config hook (in-transaction and after commit) that could withdraw `member-grants.enabled`. Since #127 no configuration the identity admission accepts can carry such a hook: Laravel normalizes aliases to `config` before storing a `beforeResolving` callback, and the admission refuses every `config` key and every global callback. The control therefore cannot keep its old claim. It is replaced by two tests:

- `test_positive_control_shared_finish_completes_member_configuration_changes_outside_identity_admission` is the positive control in an admitted configuration. With no container hooks, a callback-free `member-grants.enabled` withdrawal after frame birth still reaches a completed shared finish (transaction closed, level 0, the withdrawal visible). The identity admission captures only `app`, `database` and `production-customer-identity`, so member configuration freshness stays the member route's own proof, which `MemberOriginalFinishBoundary` closes callbacks around.
- `test_shared_finish_refuses_unchanged_config_hooks_before_execution_without_the_member_boundary` pins the old control's scenario under #127. Same hook, no member boundary: `finish()` throws `IdentityException` with reason `unavailable`. Only the admission raises a reason-less refusal; the frame's own checks use `committed_frame_required`. The hook never runs (`$states === []`), `member-grants.enabled` stays true, the frame stays open, and `close()` rolls it back.

The member boundary's own refusal (`finish_callbacks`, zero hook calls) is unchanged and still passes.

### 1b `MemberGrantFactsCallbackClosureTest`

For phase `committed` with change `identity_configuration`, the fake decrypt withdraws `production-customer-identity.enabled` inside `authenticate`. The trailing `$frame->assertActive()` in `committedCallbacks` (`SealedMemberGrantFacts.php:154`) now refuses with `IdentityException`, before `committedRaw` would reach the member `changed_owner` comparison. The property under test ("changed owner input is not admitted after the record decrypt callback") still holds; it is enforced one layer earlier. The re-pin:

- accepts `IdentityException` for that single case and requires `MemberGrantException` for the other five (exact class compared, so neither layer can substitute for the other);
- asserts reason `unavailable` for the frame refusal;
- proves the refused callback phase recorded no committed authority: `committedRaw` then refuses with `facts_phase_required`;
- in every case, snapshots all member-grant tables (`MemberGrantSchema::TABLES`) plus `production_membership_redemptions`, `production_membership_paid_periods` and `production_membership_credit_events` immediately before the act, and asserts them unchanged before any rollback (inside the caller's transaction or the read-only frame). The old test made no write assertion, so this is additive.

### 1c `MemberGrantDefinitionsTest`

A `TransactionCommitted` listener registers a config `beforeResolving` hook after the authoring commit. Before #127, receipt work after frame birth (staff and policy resolution) executed the hook, and the member boundary then refused the receipt. Now `IdentityCommittedFrame::begin` captures the admission first, so the frame refuses at birth and the hook never executes. The refusal still surfaces as `committed_receipt_refused`, with both immutable rows durable and no transaction open. The re-pin:

- replaces `assertGreaterThan(0, $calls)` with `assertSame(0, $calls)`, which is stronger: no forbidden callback runs during the refused receipt;
- proves the hook was registered and live, so the refusal is attributable to it: the next ordinary `$this->app->make('config')` runs it exactly once;
- keeps the existing assertions (one profile and one definition row retained, no open transaction). `test_real_enrolled_staff_authors_and_deduplicates_without_customer_gate_or_grant_writes` remains the hook-free positive.

### Sensitivity: mutation M1

`IdentityCommittedFrame.php` was temporarily replaced with its pre-#127 version (`git show 9cfe3a61:app/Domain/Customers/ProductionIdentity/IdentityCommittedFrame.php`; on `main` the file has changed only in #127 since then) and restored afterwards with `git checkout`. Run on the four group-1 methods: 9 tests, 36 assertions, **3 failures**, which are exactly the three refusal re-pins:

- the new frame refusal test (no `IdentityException`);
- facts "committed identity_configuration" (got `MemberGrantException`, expected `IdentityException`);
- definitions (`Failed asserting that 13 is identical to 0`: the hook ran 13 times).

The positive control passes under both frames, as a control should. So the re-pins fail if #127's frame admission regresses. Log: `logs/mutation-m1-pre127-frame.txt`.

## Group 2: PR #131 opens the default connection in `TestCase::setUpTraits`

### Cause

#131 (`3b90ce47`) added `TestCase::setUpTraits`, which calls `requireFixtureDatabase()` and therefore `$this->app['db']->connection()` before every test (`tests/TestCase.php:37-57`). The two issuer tests assert `[] === DB::getConnections()` after their refusals, which can no longer hold.

### Decision and change

The property is "the refusal opens no database connection and starts no inspection". A keys-before/after snapshot would weaken it: an issuer that ran work on the already-open default connection would pass. The re-pin keeps the original assertion exactly. A small helper, `releaseAdmittedConnections()`, purges every open connection after #131's admission has run, asserts `[] === DB::getConnections()` as a precondition, and the tests then run their unchanged act and final assertion. The test objects hold no PDO (`MembershipRows` is built without its constructor, the assembly is fresh), so any database work would reconnect through the manager and show up.

### Sensitivity: mutations M2 and M3

`MemberOriginals::consumeEntry` and `MemberOriginalSourceCapture::consumeReservationPermit` were temporarily made to call `DB::connection()->getPdo()` before refusing, then restored. The file ran 5 tests, 23 assertions, **2 failures**: both re-pinned tests caught the reopened connection. A snapshot-only re-pin would have missed M2, because it touches only the default connection that #131 already opened. Log: `logs/mutation-m2-m3-issuer-opens-connection.txt`.

### Other `getConnections()` uses in `tests/`

`grep -rn "getConnections()" tests/` finds two other test files; the remaining matches are fixture copies of application code under `tests/Fixtures/`.

- `ProductionFeatureSourceClosureTest` indexes the named `production_feature_secondary` peer.
- `InquiryNotificationWorkTest` iterates connections to check transaction levels.

Neither asserts an empty map. Both files pass whole on unmodified `main`: 19 tests, 214 assertions, OK (`logs/candidates-baseline-sqlite.txt`). No further failure.

## Group 3: PR #124 `http_mounted` in the shipped Tax checkout config

`a565e54a` (#124) added `'http_mounted' => false` (with its comment) between `enabled` and `committed_read_receipts_enabled`, and changed nothing else in `config/production-tax-checkout.php` (`git show a565e54a -- config/production-tax-checkout.php`). The pin uses `assertSame`, which is order-sensitive, so the key is inserted at the same position. The ten pinned values now equal the shipped file exactly, and the file still contains no `env(` call.

## Group 4: staging census, seven new local-only gates

`StagingEnvironmentAdmissionTest` requires every file that matches the local/testing gate pattern to be classified in `LOCAL_ONLY_FILES`, to contain no `admitsTestCommerce`, and no file other than `TestEnvironment` to name `'staging'`. Each of the seven was read in full.

| File | Introduced | Admitting paths and their environment check | Staging |
| --- | --- | --- | --- |
| `app/Domain/Grants/Member/MemberActivationReadConfiguration.php` | #117 | `gate()` (constructor and every `admit()`): provenance must be `synthetic_rehearsal` or `verified_production`; rehearsal requires `local`/`testing` (`:94`); verified production requires `! TestEnvironment::isStaging` and MySQL (`:95-97`). The same gate as `MemberGrantPolicy` and `MembershipPolicy`, already local-only. | Refused for both provenances |
| `app/Domain/Commerce/ProductionTaxCheckout/FreshTaxCheckoutPolicy.php` | #120 | `capture()` first calls `TaxCheckoutPolicy::requireEnabled()` (local-only), then `environment()` requires `local`/`testing` (`:87`); `prove()` rechecks (`:58`) | Refused |
| `app/Domain/Grants/PaidTax/PaidTaxPolicy.php` | #122 | `capture()` requires `app()->environment('local', 'testing')` (`:13`); `provePure()` requires `local`/`testing` (`:40`). `retained()` and `source()` admit nothing; they only check shape and provenance consistency. | Refused |
| `app/Domain/Grants/PaidTax/PaidTaxRuntime.php` | #122 | Constructor and `prove()` read the env binding without resolving it; `environment()` requires `local`/`testing` (`:201`) | Refused |
| `app/Domain/Grants/PaidTax/PaidTaxDeliveryPolicy.php` | #123 | `capture()`/`provePure()`/`snapshotSeconds()` all go through `validate()`, which requires `local`/`testing` and literal `downloads_enabled === true` (`:37-38`) | Refused |
| `app/Http/Tax/TaxHttpMount.php` | #124 | `admitted()` requires `local`/`testing` (`:64`) and a literal `true` flag; any error returns false | Refused |
| `app/Domain/Commerce/ProductionFinancialInspection/FinancialInspectionPolicy.php` | #125 | `capture()` and `prove()` both run `read()`, which requires `local`/`testing` (`:72`) plus the exact pins | Refused (also covered by `FinancialCandidateTest.php:434`) |

None calls `TestEnvironment::admitsTestCommerce()`; the census asserts this for each. No gate admits staging through another path, so nothing is reported as misclassified. All seven were added to `LOCAL_ONLY_FILES`, with comments naming the PRs.

Runtime corroboration (scratch probe, not committed; source and output in `logs/staging-gate-probe.*`): with otherwise valid inputs, each gate refuses `staging` and `production` and admits a `testing` positive control. The one exception is the member activation read gate, which admits `verified_production` on MySQL in `production`, the production lane shared with `MemberGrantPolicy`. Result: 6 tests, 34 assertions, OK. `FreshTaxCheckoutPolicy` and `PaidTaxRuntime` were probed through their private `environment()` checks, because the public entry of the first is already refused by `TaxCheckoutPolicy`.

## Red and green (SQLite)

| Run | Source | Tests | Assertions | Errors | Failures | Skips |
| --- | --- | --- | --- | --- | --- | --- |
| Seven failing methods, red | `efb22df3` unmodified | 12 | 113 | 2 | 5 | 0 |
| Repaired methods, green (includes the new sibling test) | working tree, the final test contents | 13 | 164 | 0 | 0 | 0 |
| Six touched files whole, red | `efb22df3` unmodified (edits stashed) | 83 | 448 | 2 | 5 | 0 |
| Six touched files whole, green | `30cca9b6` | 84 | 499 | 0 | 0 | 0 |

The 12 red cases include the facts method's six data sets (five passed). The red errors and failures are exactly the sweep's seven.

Per file, green (red in brackets):

| File | Tests | Assertions | Result |
| --- | --- | --- | --- |
| `MemberOriginalFinishBoundaryTest` | 7 (6) | 35 (26) | OK (1 error) |
| `MemberGrantFactsCallbackClosureTest` | 6 (6) | 32 (16) | OK (1 error) |
| `MemberGrantDefinitionsTest` | 8 (8) | 35 (31) | OK (1 failure) |
| `MemberOriginalIssuerOwnershipTest` | 5 (5) | 23 (21) | OK (2 failures) |
| `ProductionTaxCheckoutPolicyTest` | 43 (43) | 147 (142) | OK (1 failure) |
| `StagingEnvironmentAdmissionTest` | 15 (15) | 227 (212) | OK (1 failure) |

The six files ran together in one PHPUnit process (92 s green at `30cca9b6`, 94 s red) with `--fail-on-phpunit-warning`; no warnings and no skips. Logs: `logs/red-seven-methods-sqlite.txt`, `logs/green-repaired-methods-sqlite.txt`, `logs/red-whole-files-sqlite.txt`, `logs/green-whole-files-sqlite.txt`, per-file counts in `logs/red-whole-per-file.txt` and `logs/green-whole-per-file.txt`.

## MySQL native

`python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` places two touched files in the native selection: `MemberGrantDefinitionsTest` and `MemberGrantFactsCallbackClosureTest` (the manifest lists only these two of the six). The generated `phpunit-ci-mysql-*` files were deleted.

Server: private Oracle MySQL 8.4.11 (`/opt/mysql-8.4.11/bin/mysqld --no-defaults`), initialized with `--initialize-insecure` into `/var/lib/va-mysql84-f3`. It listened on 127.0.0.1:33595 with its own socket (`/var/lib/va-mysql84-f3-run/mysqld.sock`) and `--mysqlx=OFF`, and the root password was set to a random value through an init file. Defaults: `REPEATABLE-READ` and strict `sql_mode`. Database `vaseyaudio_test` (utf8mb4_unicode_ci). Environment as in the Foundation MySQL job: `DB_CONNECTION=mysql`, `VA_CI_DISPOSABLE_MYSQL=1`, `ATTACHMENT_NATIVE_ISOLATED=1`, `CI=true`, `GITHUB_ACTIONS=true`, `--fail-on-phpunit-warning --display-warnings`.

| Run | Source | Tests | Assertions | Errors | Failures | Skips | Time |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Touched native methods, red | `efb22df3` unmodified (edits stashed) | 7 | 18 | 1 | 1 | 0 | 1343 s |
| Touched native methods, green | `30cca9b6` (clean tree) | 7 | 38 | 0 | 0 | 0 | 1354 s |

The methods are the facts closure method (six data sets) and the definitions receipt method. The red errors are the same two as on SQLite: facts "committed identity_configuration" (`IdentityException`) and definitions (`Failed asserting that 0 is greater than 0`). Green includes the "committed identity_configuration" case, with 7 assertions on the `IdentityException` path, and the other five member-refusal cases. Only the touched methods ran natively, as briefed; the whole files did not (about 2 to 5 minutes per case on this shared 4-CPU host). Logs: `logs/mysql-native-red-touched.txt`, `logs/mysql-native-green-touched.txt`, `logs/mysql-server-lifecycle.txt`.

Container reboot: the coordinator reported a usage-limit reset and container reboot at 05:30 UTC. **No test run was in progress then.** The native green run had already finished at 04:38:48 with rc 0, and its JUnit file is complete (7 test cases, 0 failures). The reboot killed the private mysqld (pid 24587) without a shutdown. Afterwards the stale `mysqld.pid`, `mysqld.sock` and `mysqld.sock.lock` were removed only once that pid was confirmed not to be a running mysqld. The server was then started once on the same datadir (XA crash recovery finished at 05:32:30) and shut down cleanly with `SHUTDOWN` ("Shutdown complete" at 05:32:31). No pid or socket file remains. The datadir `/var/lib/va-mysql84-f3` is kept.

## CI scripts

No skip was added or removed, so `scripts/ci/database-sqlite-skips.json` and `database-mysql-skips.json` are untouched. None of the touched methods appear in either census. As a consistency check after the rename and the added test:

- `python3 scripts/ci/test-focused-tests.py`: 53 tests, OK.
- `python3 scripts/ci/test-phpunit-shards.py`: 52 tests, OK.
- `python3 scripts/ci/phpunit-shards.py --shards=4 --prefix=phpunit-ci-local --timings=scripts/ci/phpunit-timings-sqlite.json`: proved 9,368 expanded tests in 680 files across 4 shards. The generated `phpunit-ci-local-*` files were deleted. Its warning that 123 files have no SQLite timing predates this lane.

`git diff efb22df3 30cca9b6 -- scripts app config routes database bootstrap .github` is empty. Log: `logs/ci-scripts.txt`.

## Untested conditions and limits

- Only the six touched files (SQLite) and the touched native-selected methods (MySQL) were run. The full suite and Foundation CI were not run (cost policy); the final integrated candidate still needs its one Foundation run.
- The hook census covers console bootstrap and one HTTP request to `/up` in `testing`, not every route or a production `.env`. The grep, though, covers all registration sites in `app/`, `config/`, `bootstrap/` and `routes/`.
- The staging probe is scratch evidence. The committed protection for the seven gates is the source census plus each gate's existing suites.
- The positive control in 1a pins that the shared frame's identity admission does not capture member-grants configuration. If a later change widens that admission, the control should be revisited rather than read as a regression.

## Independent review

A separate reviewer approved `7b290b8b` (tested source `30cca9b6`) with no P0, P1 or P2 ([decision record](independent-review-7b290b8b-DECISION.md), imported verbatim). P3-2 (a direct committed-phase `ownerInputs` test) and P3-3 (assert the cause of the receipt refusal) go to the next member-grant batch. P3-4 (the unchanged-file sweep) is tracked as S1 phase 2. P3-1 and P3-5 need no change.
