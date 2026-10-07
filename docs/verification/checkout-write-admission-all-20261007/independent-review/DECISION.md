# Independent review: checkout NEW-write admission for intent, basis and authority

Lane `harness/checkout-write-admission-all`. Release blocker A1b. Closes Codex P1 r4208264406, P2 r4208264416 and P2 r4208109348.

Reviewer: independent security/architecture subagent (Claude), 2026-10-07. This is development review evidence. It is not Foundation CI, not release acceptance and not authorization for any live path. I did not commit or push anything.

## 1. Reviewed source

| Item | Value |
| --- | --- |
| Branch head reviewed | `c70aae5781a07375f2cfa79a2bd375976d69b320` (docs-only on top of the fix) |
| Executable source under test | `2c3efc4e06f0019bbd1eadca664bab79f81cbf51`. `git diff 2c3efc4e c70aae57 -- app tests config routes bootstrap` is empty. |
| Red canary commit | `6dc890f4e15f323ad020e5c9b480f6611b2a073a`. `git diff ece5a9ee 6dc890f4 -- app tests` is empty. |
| Base | `ece5a9ee48877f0686f9ca1230f8cafe5a77d6a7` (on main) |
| Frozen files | `CommandTransaction.php`: blob `3d18099a`, sha256 `fd8fccde…94be8`. `OriginalCommitDispatcher.php`: blob `8761f7d1`, sha256 `ed529e8f…dcd372`. Both identical at `ece5a9ee` and `2c3efc4e`. |

## 2. Environment

- Two detached worktrees, both made with `scripts/dev/mkworktree.sh` (vendor symlinked, worktree-local autoload):
  - `/home/user/VA-Studio-review-admission` at `c70aae57`;
  - `/home/user/VA-Studio-review-admission-red`, first at `6dc890f4` for the red runs, then moved to `c70aae57` for mutation M5.
- PHP 8.4.26, PHPUnit 12.5.34.
- SQLite `:memory:` from `phpunit.xml`.
- Native runs used a **private** MySQL 8.4.11 Community server:
  - `--no-defaults`, `127.0.0.1:3437`, `--skip-log-bin`, `--innodb-flush-log-at-trx-commit=2`;
  - datadir in the session scratchpad;
  - schema `vaseyaudio_review` held 0 tables after the run;
  - the server was shut down with `mysqladmin shutdown` and its datadir deleted.
- The shared :3306 server was never touched.
- Every provider contact went to the synthetic `ProductionCheckoutGatewayFixture`. No configuration flag was changed outside the tests' own in-process `config()` calls.

## 3. Decision

**APPROVE WITH CONDITIONS.**

The fix is correct for the three findings, and I found no withdrawal that still slips through at physical commit within the stated design. The c6 invariants hold:

- there is exactly one observer per frame;
- the frozen files are unchanged;
- replays, reads, retries, reconcile, record and uncertain install no observer;
- the copied plan machinery is faithful.

`proveCreatable()` is read-only, sits outside the provider `try`, runs on first calls and on retries, and uses existing reason codes. The red canaries fail on unchanged source for the stated reasons and pass on the fix. They also pass natively, and I closed the native gap for the new test file: 10/10 passed on MySQL.

Conditions before merge:

- **C1 (F-1).** Move the staff role/MFA withdrawal cases, for both the NEW basis and the NEW authority, into `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php`. Do the same for the NEW-intent committing capability-closure case.
  - Today these regressions are guarded only by canaries under `docs/`, which no suite or CI runs.
  - Mutations M1 (staff plan removed) and M3 (intent history plan removed) both leave the permanent suite green.
- **C2 (F-2).** Stop overwriting the boolean `$created` with a timestamp string in `TaxExemptions::qualify()` and `ApproveExemptionAuthority::approve()`. Use a distinct variable, for example `$isNew`, and an explicit `=== true` check.

Neither condition changes the reviewed admission semantics. Re-review can be limited to the test and variable diff, and must assess the actual new commit.

## 4. Findings

| ID | Severity | Finding | Evidence | Recommendation |
| --- | --- | --- | --- | --- |
| F-1 | Medium | The permanent suite does not guard the central comparisons for two of the three findings. Removing `$plans->staff(...)` (M1) turns all four staff canaries red, but `ProductionCheckoutWriteAdmissionAllTest` stays green (10/10). Removing `$plans->history(...)` from the intent capsule (M3) turns the intent capability canary red, and the feature file again stays green. The canaries live in `docs/verification/.../canaries/`, and `phpunit.xml` runs only `tests/Unit` and `tests/Feature`. | `CheckoutStaffWriteAdmission.php:221`, `CheckoutIntentAdmission.php:72`, `phpunit.xml:7-13`, `review-evidence/M1/`, `review-evidence/M3/` | Condition C1: port the 4 staff role/MFA cases and the intent committing-closure case into the Feature test, asserting reason `write_source_changed`. |
| F-2 | Low | `$created = $existing === []` is overwritten with the `created_at` string inside the insert branch. `if ($created)` still works only because a non-empty date string is truthy. A refactor to `=== true` would silently stop installing the observer. The canaries would catch it, but the Feature suite would not (F-1). `HostedCheckout::prepare()` resets the variable to `true` and is correct. | `TaxExemptions.php:51,59,77`, `ApproveExemptionAuthority.php:26,37,50` | Condition C2: use a separate boolean. |
| F-3 | Low | Four refusal tests use `assertRefused(null, …)`, so they cannot tell which layer refused. Removing both `exemption_authoring_enabled` and the owner-delegation check from `CheckoutStaffWriteAdmission::proveFresh()` (M5) leaves those tests green, because the frame's per-`prove()` snapshot of the `production_checkout` config parent refuses first with `write_frame`. That is acceptable defence in depth, but the capsule's own `proveFresh()` check is unproven. | `ProductionCheckoutWriteAdmissionAllTest.php:68,107,144,181`; `CheckoutCommandFrame.php:84-86,205-218`; `review-evidence/M5/` | Pin the expected reason code where it is deterministic. Optionally add a unit test that calls `proveFresh()` directly against a mutated retained `Repository`. |
| F-4 | Info | README residual 3 says `app.env` is compared through the frame's captured `app` config parent. MFA requirement is actually `app()->isProduction()`, which reads the container `env` instance (`$this['env']`), not `config('app.env')`, and the frame's `rawBindings()` tracks only `config` and `db`. The raw MFA enrollment columns are compared regardless of policy, so this does not open a hole for the reviewed cases. | `app/Providers/Filament/*:77`; `vendor/laravel/framework/src/Illuminate/Foundation/Application.php:784-787`; `CheckoutCommandFrame.php:189-195`; lane `README.md:91` | Correct the README wording. Keep it as a stated residual. |
| F-5 | Info | For a NEW basis, only the qualifier's staff row is compared. The authority owner's staff row is not, and a qualifier revoked after the basis committed does not invalidate later intents (`TaxExemptions::basis()` checks only the listed qualifier ID). Both behaviours predate this lane and are outside the three findings. | `CheckoutStaffWriteAdmission.php:170-183`, `TaxExemptions.php:101-125` | Record a policy decision: should later checkout require the qualifier's and owner's current staff state? |
| F-6 | Info | `CheckoutRawPlans` faithfully copies c6. `set`, `plan`, `read`, `definition`, `qualified` and `strings` are byte-identical to the `CheckoutWriteAdmission` privates. The `identity`, `selection` and `history` selectors equal `captureIdentity`, `captureSelection` and `captureHistory`, apart from inlining one temporary variable. `proveCurrent` generalizes the single actor to a list. The added `staff()` selector matches `PacketAuthority::raw` (exact `users` row via `CurrentRows::one`, `User` audits at limit 257). | Mechanical method diff (command 6 below) | Consolidate in a separate, separately reviewed refactor, as the lane already notes. |
| F-7 | Info | The native coverage gap is closed for this file. The lane ran only 2/10 Feature tests on MySQL; my run on the same source ran all 10/10 (63 assertions) on a private MySQL 8.4.11. MySQL 8.0, hosted Foundation CI, and composition with Paid252/receipt consumers beyond the lane's receipt pair remain untested. | `review-evidence/native/` | No action for this lane. Keep the final Foundation CI as the integrated gate. |

### Checks with no finding

1. **c6 drift.** `CheckoutWriteAdmission` changed only its `implements` line. `CheckoutCommandCommitDispatcher` changed only its constructor and `capture()` parameter types. `CheckoutCommandFrame::register()` changed only its parameter type and docblock. The `observer === null` single-observer refusal, the `OriginalCommitDispatcher` exclusion, dispatch order and `abort()` are unchanged. The diff removes no refusal anywhere under `app/`.
2. **Observer placement.** Each capsule is captured as the last statement inside its closure (`HostedCheckout.php:142-146`, `TaxExemptions.php:77-81`, `ApproveExemptionAuthority.php:50-53`), and only on the NEW-insert branch. `reconcile()` (create=false), intent retry (`$intents !== []`), `record()`, `uncertain()`, `status()`, `proveCreatable()` and exact staff replays install none. The Feature test observes `[true,false,true,false]` for authority/basis new and replay, and no observer in any retry, record or reconcile frame.
3. **Commit-time reads.** All commit reads use `frame->primary()`. `frame->prove(1)` checks that this PDO is the connection's raw PDO, internal, with a default statement class and at transaction depth 1. Reads are `SELECT *` raw rows compared with `===` against rows captured on the same PDO, with no cache, model, resolver or decryptor. On MySQL the planned rows were already read `FOR UPDATE` by `CurrentRows`, so only same-connection writes (the threat model) can change them before commit, and the snapshot read sees those.
4. **Staff row.** The full raw `users` row is compared, which covers `is_admin`, `email_verified_at`, `app_authentication_secret` and recovery codes. The `administer-catalog` gate reads only `is_admin` and `email_verified_at` (`AppServiceProvider.php:53-63`). Filament app MFA `isEnabled()` reads only the secret.
5. **Intent plans.**
   - buyer identity: users, account, origin, verifications, challenges;
   - the full selection graph, including offers, revisions, licenses and audits;
   - capability history, including approvals, closures and audits;
   - authority, basis, review, order and attempt by id;
   - lines and attempt by `order_id`;
   - the intent by id and by `order_id`;
   - empty session, observation (limit 129) and payment sets;
   - `FreshCheckoutPolicy` last.
6. **`proveCreatable()`.**
   - It is called after `requireGateway()` and before the provider `try`, whenever `intent.session === null`, which covers the first call and lost-response retries.
   - It is read-only. `ProductionCustomerAccess::lock`, `proveCurrent` and `current` perform no writes, and it registers no observer.
   - It uses the existing codes `changed`, `disabled`/503 and `write_source_changed`. A refusal leaves 0 observations, so there is no false `uncertain` (race canary, and `test_fresh_withdrawal_after_intent_commit_refuses_before_first_create_with_disabled`).
   - M4 (call removed) turns the race canary red and fails 3 Feature tests, including the retry case.
7. **Deadlines and flags.**
   - `capDeadline()` only takes the minimum, so the new capsules can only shorten the 300 s frame budget.
   - The diff touches no `config/`, `routes/`, `bootstrap/`, `app/Providers`, `database/` or `.github/` file.
   - `provider_io_enabled` is untouched, and no flag default changed.
8. **Secrets and PII.** Exceptions carry fixed reason codes only. `__debugInfo` and `__serialize` hide or refuse the capsules, and no new logging was added.
9. **Residuals.** I agree that a withdrawal committing after `proveCreatable()` returns, crossing the external `create`, stays open and belongs to reconciliation and refund. So does c6 F-5, a listener committing PDO directly. The lane does not claim to close either.

## 5. Not reviewed (non-scope)

- MySQL 8.0.
- Hosted Foundation CI.
- The full PHP suite. I ran only the canaries and `ProductionCheckoutWriteAdmissionAllTest`, and relied on the lane's family evidence for the other 17 ProductionCheckout files.
- Route and provider registration, which is root's later step.
- Live Stripe or any real provider.
- Composition with Paid252/receipt consumers beyond the lane's receipt pair.
- A Pint rerun.
- Browser specs.

## 6. Commands and results

Runner used for every run below, with `<wt>` set to the worktree. `TMPDIR` was set per run so the canary snapshots did not collide.

```sh
TMPDIR=<tmp> php -d memory_limit=1G -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <file> --log-junit <out>.xml --do-not-cache-result [--filter <f>]
```

`C=docs/verification/checkout-write-admission-all-20261007/canaries`

1. **Worktrees.**
   - `VA_STUDIO_MAIN=/home/user/VA-Studio scripts/dev/mkworktree.sh /home/user/VA-Studio-review-admission c70aae57`
   - the same for `-red` at `6dc890f4`.
2. **Source integrity.**
   - `git diff ece5a9ee..2c3efc4e -- app/Domain/Commerce/ProductionCheckout/{CheckoutWriteAdmission,CheckoutCommandCommitDispatcher,CheckoutCommandFrame,HostedCheckout,TaxExemptions,ApproveExemptionAuthority}.php`: type, interface and admission lines only, as summarized in §4.
   - `git diff --stat ece5a9ee..2c3efc4e -- …/CommandTransaction.php …/OriginalCommitDispatcher.php`: empty.
   - `sha256sum`: `fd8fccde…` and `ed529e8f…`.
   - `git diff ece5a9ee 6dc890f4 -- app tests`, `git diff 6dc890f4 2c3efc4e -- docs` and `git diff 2c3efc4e c70aae57 -- app tests config routes bootstrap`: all empty.
3. **Red (SQLite), worktree at `6dc890f4`.**

   | Canary | Exit | Tests | Assertions | Failures |
   | --- | --- | --- | --- | --- |
   | HostedIntent | 1 | 3 | 9 | 3 |
   | ExemptionBasis | 1 | 2 | 6 | 2 |
   | ExemptionAuthority | 1 | 2 | 4 | 2 |

   The failures are for the stated reasons:
   - offer and race: `provider_creates` 1, with intent, session and observation each 1;
   - capability: an intent committed and a provider create made, then a non-checkout `ValidationException`;
   - basis and authority: not refused, with rows = 1.

   The snapshots are in `review-evidence/red/`.
4. **Green (SQLite), worktree at `c70aae57`.**

   | Canary | Result |
   | --- | --- |
   | HostedIntent | OK, 3 tests, 20 assertions |
   | ExemptionBasis | OK, 2 tests, 10 assertions |
   | ExemptionAuthority | OK, 2 tests, 8 assertions |

   Every case refused with `CheckoutException` and 0 provider creates. The race case kept 1 durable intent with 0 sessions and 0 observations.
5. **Feature file.** `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` gave `OK (10 tests, 63 assertions)` on SQLite in 35.8 s. On native MySQL (`DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3437 DB_DATABASE=vaseyaudio_review DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`) it gave `OK (10 tests, 63 assertions)`, with 0 errors, 0 failures and 0 skips, in 1447.9 s.
6. **Copy fidelity.** I extracted each method body with awk and diffed it: `set`, `plan`, `read`, `definition`, `qualified` and `strings` showed no difference. `captureIdentity` against `identity` differs only by inlining one temporary variable. `captureSelection` against `selection` and `captureHistory` against `history` showed no difference. `proveCurrent` differs only by the actor list.
7. **Mutations.** Each was applied with `sed`, run, then reverted with `git checkout -- <file>`. The diffs are in `review-evidence/M*/mutation.diff.txt`.

   | ID | Mutation | Canary result | Feature file result |
   | --- | --- | --- | --- |
   | M1 | `CheckoutStaffWriteAdmission::open()` without `$plans->staff()` | Basis 2/2 and Authority 2/2 fail (exit 1) | **green**, exit 0 |
   | M2 | Intent capsule without `$plans->selection()` | HostedIntent offer case fails: intent committed, then caught by `proveCreatable` | `test_committing_offer_withdrawal_…` fails |
   | M3 | Intent capsule without `$plans->history()` | HostedIntent capability case fails: intent committed | **green**, 10/63 |
   | M4 | `initiate()` without `proveCreatable()` | Race case fails: `provider_creates` 1, session 1 | 2 failures and 1 error: capability-after-commit, fresh-after-commit, offer-before-retry |
   | M5 | Staff `proveFresh()` without the authoring and owner checks | — | Filtered to the 2 config-withdrawal tests: **green**, 2/10 (the frame's config snapshot refuses first) |

   All mutations were reverted. `git status --short` is clean in both review worktrees.
8. **Cleanup.**
   - `mysqladmin --protocol=tcp -h127.0.0.1 -P3437 -uroot shutdown` completed (error log: "MySQL Server - end"), and the datadir was deleted.
   - Both review worktrees were removed with `git -C /home/user/VA-Studio worktree remove --force`.

Raw outputs (ANSI stripped), JUnit and JSON snapshots are in `review-evidence/{red,green,base,native,M1..M5}/`. I wrote no throwaway test files.

## Addendum 1: re-review of the condition commits

Reviewer: the same independent security/architecture subagent (Claude), 2026-10-07. This is development review evidence. It is not Foundation CI, not release acceptance and not authorization for any live path. I did not commit or push anything.

### A1.1 Reviewed source

| Item | Value |
| --- | --- |
| Branch head reviewed | `8c30ce5b6bfc518c8e52b2016bdaf9b87e0c9561` (adds only this review record on top of `8917f305`) |
| Delta assessed | `c70aae57..8c30ce5b`: `f4eb4a40` (C1), `5eb82c3f` (C2), `fe027fe3` (F-3), `8917f305` (F-4), `8c30ce5b` (review record) |
| Executable change | `git diff --stat c70aae57 8c30ce5b -- app tests config routes bootstrap database` lists 4 files: `ApproveExemptionAuthority.php`, `HostedCheckout.php`, `TaxExemptions.php` (6 lines each) and `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` (+147/−21). |
| Untouched | `config/`, `routes/`, `bootstrap/`, `database/`, `.github/`, `app/Providers`, `composer.*`, `package.json` and `phpunit.xml`: `git diff --stat` is empty. |
| Frozen files | `CommandTransaction.php` sha256 `fd8fccde…394be8` and `OriginalCommitDispatcher.php` sha256 `ed529e8f…dcd372` are unchanged. `git diff --quiet ece5a9ee 8c30ce5b` passes for both. |
| Implementer's evidence source | `fe027fe3`. `git diff fe027fe3 8c30ce5b -- app tests config routes bootstrap database .github` is empty, so their SQLite-family and native runs apply to this head's executable source. |

### A1.2 Environment

- One detached worktree: `VA_STUDIO_MAIN=/home/user/VA-Studio scripts/dev/mkworktree.sh /home/user/VA-Studio-review-admission2 8c30ce5b`.
- PHP 8.4.26 and PHPUnit 12.5.34.
- SQLite `:memory:`.
- `public/build` was absent.
- I used the same runner as §6, with a per-run `TMPDIR` in the session scratchpad.
- I did **not** run native MySQL in this addendum (see A1.6).

### A1.3 Decision

**APPROVE WITH CONDITIONS carries to `8c30ce5b`, and C1 and C2 are closed.** F-3 and F-4 are closed as well. No condition remains open for this lane. The admission semantics did not change: the `app/` delta only renames a flag and changes its check. I found no new finding above Info.

| Item | Status | Basis |
| --- | --- | --- |
| C1 (F-1, Medium) | **Closed** | The permanent Feature file now holds these cases, each asserting `write_source_changed`: basis role and MFA, authority role and MFA (data providers), and the NEW-intent committing capability closure. M1 turns 4 tests red and M3 turns 1 test red (A1.4). |
| C2 (F-2, Low) | **Closed** | `TaxExemptions::qualify()` and `ApproveExemptionAuthority::approve()` use a dedicated `$inserted = $existing === []`. It is never reassigned, and the guard is `if ($inserted === true)`. `$created` stays the timestamp string. `HostedCheckout::prepare()` got the same rename and was already correct, so its semantics are unchanged. M6 (A1.4) shows the permanent suite now catches the F-2 failure mode, a NEW basis that installs no observer. |
| F-3 (Low) | **Closed** | Every refusal pins its reason, and `assertRefused()` now takes a non-null `string`. A new test calls the staff capsule's `proveFresh()` directly inside the commit and requires `['authority','authority']` for the authoring and owner withdrawals. M5, which left the suite green at `c70aae57`, now gives 1 failure. Splitting the combined basis test also removes a latent by-reference `$active` interaction that I had not flagged. |
| F-4 (Info) | **Closed** | README residual 3 now says the MFA requirement is `app()->isProduction()`, read from the container's `env` instance, which the frame does not track. I checked this against `AdminPanelProvider.php:77` and `CheckoutCommandFrame::rawBindings()/configuration()` (`config` and `db` instances, aliases, and the `app`/`database`/`production_checkout`/`production-customer-identity` parents). The wording is accurate. |
| F-5, F-6, F-7 | Unchanged | Recorded as open items in README §4a. They are not widened here, and none is a condition of this lane. |

### A1.4 Commands and results (SQLite, worktree at `8c30ce5b`)

1. **Green.** The three files ran concurrently in separate processes:

   | File | Exit | Result | Time |
   | --- | --- | --- | --- |
   | `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` | 0 | OK, 17 tests, 96 assertions | 74.3 s |
   | `tests/Feature/ProductionCheckoutExemptionAuthorityTest.php` | 0 | OK, 11 tests, 50 assertions | 46.9 s |
   | `tests/Feature/ProductionCheckoutJourneyTest.php` | 0 | OK, 7 tests, 81 assertions | 57.1 s |

   These counts match the implementer's `conditions/sqlite-family` files. I summed that family's JUnit: 195 tests, 947 assertions, 0 errors, 0 failures and 8 skips, which matches their claim. I did not rerun the other 15 family files myself.
2. **Mutations.** Each was applied with `sed` and run against the whole permanent Feature file only (no canaries). Each was reverted with `git checkout -- app`, and `git diff --quiet -- app` succeeded after every one.

   | ID | Mutation | Result |
   | --- | --- | --- |
   | M1 | `CheckoutStaffWriteAdmission::open()` without `$plans->staff($actor->id, $staff)` | **exit 1, 4 failures** (17 tests / 84 assertions): basis@role, basis@mfa, authority@role, authority@mfa. Each failed with "A withdrawn NEW checkout write was admitted." At `c70aae57` the same mutation left the file green. |
   | M3 | `CheckoutIntentAdmission::capture()` without `$plans->history($current['raw'])` | **exit 1, 1 failure** (17/92): `test_committing_capability_closure_refuses_new_intent_and_provider_io`. Expected `write_source_changed`, got `changed`: the intent committed and only `proveCreatable()` stopped provider I/O. Pinning the reason is what distinguishes the commit-time guard from that defence in depth. Green at `c70aae57`. |
   | M5 | Staff `proveFresh()` without the authoring and owner checks | **exit 1, 1 failure** (17/95): the direct `proveFresh()` test got `admitted` instead of `authority`. Green at `c70aae57`. |
   | M6 (new) | `TaxExemptions::qualify()` guard changed to `if ($inserted === "M6-never")`, so no NEW-basis observer is installed. This is the F-2 regression class. | **exit 1, 5 failures** (17/80): basis owner-delegation, basis offer, basis@role, basis@mfa, and the observer-pattern test. |

   After the mutations, `git status --short` in the worktree showed only the untracked evidence folder and this file.

Raw outputs (ANSI stripped), JUnit and the mutation diffs are in `review-evidence/addendum1/{green,M1,M3,M5,M6}/`.

### A1.5 New findings

| ID | Severity | Finding | Recommendation |
| --- | --- | --- | --- |
| A1-1 | Info | README §4a says `independent-review/DECISION.md` was "written by the reviewer and left untracked". It is tracked from `8c30ce5b`. | Correct the wording when this addendum is committed. |
| A1-2 | Info | The direct `proveFresh()` test reads the private `CheckoutCommandCommitDispatcher::$admission` through `ReflectionProperty`. That is test-only coupling to a private name. It cannot pass vacuously: a rename throws `ReflectionException`, the test asserts `assertInstanceOf(CheckoutStaffWriteAdmission::class, …)`, and a frame without the observer leaves `$refusals` empty, which fails `assertSame(['authority','authority'], …)`. | None required. Revisit if F-6's consolidation renames the dispatcher fields. |
| A1-3 | Info | Pinning `write_frame` for the owner-delegation and authoring withdrawals records that the frame's config snapshot pre-empts the capsule. M6 shows those cases still depend on the observer being installed, so the pin does not hide a missing capsule. | None. |

### A1.6 Not reviewed in this addendum

- **Native MySQL rerun.** I relied on the implementer's `conditions/native/` run (MySQL 8.4.11 on a private port 3422: 8 tests, 37 assertions, green on `fe027fe3`, which is executable-identical to this head). I checked that its JUnit names the 8 new or changed cases. I did not start a server, and the shared :3306 server was not touched.
- **Everything in §5 still applies:** MySQL 8.0, hosted Foundation CI, the full PHP suite, route and provider registration, live providers, Paid252 composition, Pint and browser specs.

### A1.7 Cleanup

All mutations were reverted (`git diff --quiet -- app` succeeded), and no flag, registration or `app/` file differs from `8c30ce5b`. The review worktree `/home/user/VA-Studio-review-admission2` holds two uncommitted items for the caller to commit: this file and the untracked `review-evidence/addendum1/`.

## Addendum 2: re-review of the Codex P2 r4209950553 fix

Reviewer: the same independent security/architecture subagent (Claude), 2026-10-07. This is development review evidence. It is not Foundation CI, not release acceptance and not authorization for any live path. I did not commit or push anything.

### A2.1 Reviewed source

| Item | Value |
| --- | --- |
| Head reviewed | `0cb13ca58f0e4b8784ee3df7cb0679bc8ddf94e0` (one commit on top of `9f3f1115`) |
| Executable change | `git diff --stat 9f3f1115 0cb13ca5 -- app tests config routes bootstrap database .github composer.json composer.lock package.json phpunit.xml` lists 3 files: `CheckoutStaffWriteAdmission.php` (+34/−1), `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` (+27) and `tests/Support/ProductionCheckoutFixtures.php` (+2/−2). Nothing under `config/`, `routes/`, `bootstrap/`, `database/`, `.github/`, the Composer or npm manifests, or `phpunit.xml` changed. |
| Frozen files | `CommandTransaction.php` sha256 `fd8fccde…394be8` and `OriginalCommitDispatcher.php` sha256 `ed529e8f…dcd372` are unchanged. `git diff --quiet` passes for both from `ece5a9ee` and from `9f3f1115`. |
| Other admission files | `CheckoutIntentAdmission.php`, `CheckoutWriteAdmission.php`, `CheckoutCommandCommitDispatcher.php`, `CheckoutCommandFrame.php` and `CheckoutRawPlans.php` are byte-identical between `9f3f1115` and `0cb13ca5`. Their differences from `ece5a9ee` are the lane changes reviewed in §3 and Addendum 1. |
| Evidence | `review-evidence/addendum2/source-checks.txt`, `delta-9f3f1115..0cb13ca5.diff` |

### A2.2 Environment

- One detached worktree: `scripts/dev/mkworktree.sh /home/user/VA-Studio-review-admission3 0cb13ca5`.
- PHP 8.4.26 and PHPUnit 12.5.34.
- SQLite `:memory:`.
- `public/build` was absent.
- I used the worktree-local autoload runner with a per-run `TMPDIR` in the session scratchpad.
- I did not run native MySQL (see A2.7).

### A2.3 Decision

**APPROVE WITH CONDITIONS carries to `0cb13ca5`, and Codex P2 r4209950553 is closed.** No condition is open for this lane. I found nothing above Info.

### A2.4 Semantics

1. **Helper equivalence.** `licenseUntil()` and its `one()` are textually identical to the loop and `one()` in `CheckoutIntentAdmission::capture()`. I extracted both with `sed` and ran `diff`, which exited 0 (`loop-equivalence.diff`). The helper:
   - skips offers whose `(string) is_active !== '1'`, so only active offers count;
   - resolves the current revision, then its license version, through `one()`. `one()` compares `id` strictly and throws `CheckoutException('changed', 409)` when a row is missing, so a missing row is refused and never silently skipped;
   - keeps only non-null `effective_until`, so a license with no expiry adds no cap.

   This is also the set `PreparationSelection::effective()` checks: every active offer in the selection graph, with `(int) is_active === 1`. That method is what `qualify()` runs through `CurrentSelection::proveCurrent()`. The cap therefore matches exactly the predicate that the frozen clock had last proven true. The two `is_active` casts agree for every value a tinyint column returns.
2. **Cap application.** `open()` takes the minimum of the policy, attestation and license bounds. It converts that minimum once against wall-clock `now()` into an `hrtime` deadline, refuses `expired` when no time remains, and calls `capDeadline()`. At `TransactionCommitting`, `CheckoutCommandFrame::prove(1)` checks `hrtime(true) < deadlineNs` and refuses `write_frame`. That check runs after ordinary delegates and before `proveCurrent()`. A license that expires during the commit therefore rolls back the whole physical transaction, the same way an expiring attestation does.
3. **Ordering (Info A2-3).** In `basis()`, `licenseUntil()` runs before `open()` and therefore before `proveAnchor()`. In the intent admission, the loop runs after `proveAnchor()`. The helper only reads the already-loaded `$selection` array: no database access, resolver or callback. A throw from it propagates inside `CommandTransaction::run()` and rolls back. The order makes no difference.
4. **`authority()` needs no further cap.** I looked for any other bound it omits. `approve()` has one temporal check, `ExemptionPolicyV1::effective($policy, $at)`. It loads no selection, license or order. `StaffProof`, `CurrentPolicy` and `ExemptionPolicyV1::validate()` contain no `now()`, `until` or `expires` comparison (grep). An authority is a per-candidate delegation: licenses are re-checked when a basis is qualified, which this fix now caps, and again when an intent is created. Capping `authority()` at license expiries would add a bound that no authority check requires. `[$policy['effective_until']]` is the complete cap.
5. **Other `basis()` bounds.** `qualify()` time-checks the policy, the attestation and, through `PreparationSelection::license()` and `effective()`, the license versions. The customer-access challenge windows compare stored `created_at` and `expires_at` values with each other, not with `now()`, so time passing does not expire them. The reservation-window requirement in `TaxExemptions::basis()` applies when a basis is used, not when it is created. The policy bound is redundant with the attestation bound, because `qualify()` requires attestation `effective_until` ≤ policy `effective_until`, but it is harmless. After this fix, every bound that `qualify()` evaluates against the clock is folded into the cap.

### A2.5 Commands and results (SQLite, worktree at `0cb13ca5`)

1. **Green.** The three files ran concurrently in separate processes:

   | File | Exit | Result | Wall time |
   | --- | --- | --- | --- |
   | `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` | 0 | OK, 18 tests, 102 assertions | 53.5 s |
   | `tests/Feature/ProductionCheckoutExemptionAuthorityTest.php` | 0 | OK, 11 tests, 50 assertions | 24.5 s |
   | `tests/Feature/ProductionCheckoutJourneyTest.php` | 0 | OK, 7 tests, 81 assertions | 35.1 s |

   These counts match the implementer's `conditions/codex-license-expiry/green/` claims. In the JUnit, the new test made 6 assertions in 4.66 s.
2. **Mutation M7.** I removed `, ...self::licenseUntil($selection)` from `basis()` with `sed`. The result was **exit 1, 1 failure** (18 tests, 98 assertions): `test_new_basis_admission_is_capped_at_the_selected_license_expiry` failed with "A withdrawn NEW checkout write was admitted.", meaning the basis committed. The other 17 tests stayed green. M7's `app/` tree differs from `9f3f1115` only by the unused private helper, so it reproduces the implementer's red run. I reverted with `git checkout -- app`, and `git diff --quiet -- app` succeeded.
3. **Pint.** `vendor/bin/pint --test` on the three changed PHP files passed (`pint.txt`).

### A2.6 Robustness of the real-time regression

**Mechanism.** The test freezes Carbon at `until − 1.5 s`. `open()` therefore always computes exactly 1.5 s of budget and adds it to the `hrtime` taken at `open()`. Fixture setup, enrollment, `approve()`, and the part of `qualify()` before `open()` all happen before the deadline is anchored, so their speed cannot shorten it. Every check of policy, attestation or license effectiveness reads the frozen clock. A slow machine can therefore never produce `expired` or `changed`. The only clock-sensitive refusal is the frame's `hrtime` comparison, which always refuses with `write_frame`. The 2.5 s sleep is a floor added after the anchor, so load only makes the expiry more certain. Mutation M7 cannot go green under any timing, because nothing caps a 300 s frame deadline.

**The one failure mode.** The deadline passes **before** the commit only if the stretch from `open()` to `TransactionCommitting` takes at least 1.5 s on its own. That stretch covers the rest of `open()`, raw-plan capture, `seal()`'s `proveCurrent()` and `proveFresh()`, `register()`/`capture()`, and the commit call.

- **Probe P1.** I temporarily inserted `usleep(2_000_000)` after `open()` (`P1-slow-capture/probe.diff`). The refusal still had reason `write_frame` and the basis still rolled back. It came from `CheckoutCommandCommitDispatcher::capture()`'s `prove(1)`, before the commit, so the listener never slept and the test failed at `assertTrue($slept)` ("Failed asserting that false is true").
- **Result.** Under extreme stall the test fails loudly, with the same refusal reason and no false green. It does not flake toward a pass.
- **Probe P2.** I temporarily instrumented the window from `open()` to `TransactionCommitting` (`P2-load/probe.diff`). Each run records two windows, the authority frame and then the basis frame.

  | Condition | Runs | Windows | Result |
  | --- | --- | --- | --- |
  | Idle | 3 sequential | 4.7–12.1 ms (basis 11.7–12.1 ms) | all OK |
  | Loaded: 8 busy-loop burners plus 4 concurrent test processes on 4 vCPUs (3× oversubscribed) | 4 | 20.9–134.7 ms | all OK |

  That leaves at least an 11× margin. Both probes were reverted, and `git diff --quiet -- app` succeeded after each.

**Judgement.** The test is robust enough for CI. A false pass is impossible. A false failure needs a stall of 1.5 s or more inside a few-millisecond span, such as a frozen VM or swap storm, and it would show up as the `$slept` assertion, not as a wrong reason. I did not measure MySQL, where plan capture adds network round trips and `SHOW CREATE TABLE`. I expect that window to stay far below 1.5 s, but this is unmeasured.

### A2.7 New findings

| ID | Severity | Finding | Recommendation |
| --- | --- | --- | --- |
| A2-1 | Info | The regression depends on real time: 1.5 s of budget against a 2.5 s sleep. If `open()` to commit stalls for 1.5 s or more, the test fails at `assertTrue($slept)` with a generic message. Measured margin is at least 11× (A2.6). | Optional: give `assertTrue($slept, …)` a message saying the frame expired before the commit, or widen both offsets (for example, freeze 5 s before and sleep 6 s) at a cost of +3.5 s per run. Not a condition. |
| A2-2 | Info | The license loop and `one()` now exist verbatim in both `CheckoutIntentAdmission` and `CheckoutStaffWriteAdmission`. If a future temporal bound is added to one copy, the other will drift. | Fold into the F-6 consolidation, for example a shared `CheckoutRawPlans::licenseUntil()`, under its own review. Not a condition. |
| A2-3 | Info | `licenseUntil()` runs before `proveAnchor()` in `basis()`, unlike the intent admission. It is pure and in-memory, and a throw rolls back (A2.4 item 3). | None. |

F-5, F-6 and F-7 remain open items as before. A2-2 extends F-6.

### A2.8 Not reviewed in this addendum

- **Native MySQL.** The implementer made no native run for this item, and neither did I. The change is pure PHP arithmetic over rows that the reviewed raw plans already capture. The deadline check is `hrtime`-based and independent of the driver.
- **Everything in §5 still applies:** MySQL 8.0, hosted Foundation CI, the full PHP suite, route and provider registration, live providers, Paid252 composition and browser specs.

### A2.9 Cleanup

- M7, P1 and P2 were each reverted with `git checkout -- app`, and `git diff --quiet -- app` succeeded after each.
- No flag, registration, configuration or `app/` file differs from `0cb13ca5`.
- The review worktree `/home/user/VA-Studio-review-admission3` holds two uncommitted items for the caller to commit: this file and the untracked `review-evidence/addendum2/`.
- Raw outputs (ANSI stripped), JUnit, probe and mutation diffs, and window timings are in `review-evidence/addendum2/{green,M7,P1-slow-capture,P2-load}/`.

## Addendum 3: re-review of the Codex P1 r4210033214 fix (pre-create re-proof commit admission) and the Codex P1 r4210180698 fix (terminal re-proof)

Reviewers: the same independent security/architecture subagent (Claude) as Addenda 1 and 2, 2026-10-07, for `019bc37d`; after it stopped mid-task (rate limit), a second independent reviewer subagent (Claude) resumed the addendum and extended it to `b8886440`. Neither reviewer authored a lane commit. This is development review evidence. It is not Foundation CI, not release acceptance and not authorization for any live path. Nothing was committed or pushed by either reviewer.

### A3.1 Reviewed source

| Item | Value |
| --- | --- |
| Head reviewed | `b8886440c59bd1f001d0278e6d545a570f813f11` (`git rev-parse b8886440`; equal to `origin/harness/checkout-write-admission-all` when fetched). A3.4 items 1–4 and A3.5 items 1–3 were first recorded at `43ae65e6daa668101cd9bd72a5b9464147de6106`, whose `app/` tree equals `019bc37d`. |
| Delta assessed | `0cb13ca5..b8886440`: `019bc37d` (the r4210033214 fix), `43ae65e6` (Addendum 2 record) and `b8886440` (the r4210180698 fix, "keep the admitted re-proof terminal before the provider call") |
| `43ae65e6` | Docs-only. `git diff --name-only 019bc37d 43ae65e6` lists only paths under `independent-review/`. |
| `b8886440` | `git diff --numstat 43ae65e6 b8886440 -- app tests`: `HostedCheckout.php` +6/−1 (one statement moved, five docblock lines) and `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` +93/−2. All other paths are the lane README (§4 bullet, residual 1, new §4e) and new evidence under `conditions/codex-terminal-reproof/`. |
| Executable change, whole delta | `git diff --numstat 0cb13ca5 b8886440 -- app tests config routes bootstrap database` lists 3 files: `CheckoutIntentAdmission.php` (+21/−2), `HostedCheckout.php` (+17/−2) and `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` (+144/−4). |
| Untouched | `routes/`, `bootstrap/`, `config/`, `database/`, `.github/`, `app/Providers`, the Composer and npm manifests and `phpunit.xml`: `git diff --stat 0cb13ca5 b8886440` over those paths is empty. |
| Frozen files | `CommandTransaction.php` sha256 `fd8fccde…394be8` and `OriginalCommitDispatcher.php` sha256 `ed529e8f…dcd372`. `git diff --quiet` passes for both from `ece5a9ee` and from `0cb13ca5` to `b8886440`. |
| Other named files | `CheckoutWriteAdmission`, `CheckoutStaffWriteAdmission`, `CheckoutCommandFrame`, `CheckoutCommandCommitDispatcher`, `CheckoutRawPlans`, `CheckoutCommitAdmission`, `FreshCheckoutPolicy`, `Records`, `TaxExemptions` and `ApproveExemptionAuthority` are byte-identical between `0cb13ca5` and `b8886440`. `CheckoutIntentAdmission.php` and `ProductionCustomerAccess.php` are byte-identical between `43ae65e6` and `b8886440`. |
| SHA-256 at `b8886440` | `HostedCheckout.php` `79681bdde327f7c8f9ae24184e038af2097fa78204df75c3ccfa0d93375a48d3`; `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` `92e48fe7e3a7b8d7e1d979ba8534845e353bd8ad8dcd1d3fc895e1863a2cdcd4`; `CheckoutIntentAdmission.php` `13324e490e705ba745161cb391fb865d9bbb3a9a2e46c4c7bebb92a90f221138`; `conditions/codex-terminal-reproof/canary/TerminalReproofCanaryTest.php` `7887f431140e6965927c4ff2dd408fd6e5b20c92013609a7bde38fa5cc826b70`; `conditions/codex-reprove-admission/canary/ReproveCommitAdmissionCanaryTest.php` `8b596d769c5c77c6e1cbc6da994b1b84a46a78e2da11ae985f95f14e97b5335c` (unchanged since `019bc37d`). |
| Evidence | `review-evidence/addendum3/source-checks.txt`, `delta-0cb13ca5..019bc37d.diff`, `terminal-b8886440/source-checks.txt`, `terminal-b8886440/delta-43ae65e6..b8886440-app-tests.diff` |

### A3.2 Environment

- One detached worktree, `/home/user/VA-Studio-review-admission4`, made with `scripts/dev/mkworktree.sh` at `43ae65e6` and moved with `git fetch origin harness/checkout-write-admission-all` and `git checkout --detach b8886440`. The uncommitted review files did not conflict.
- PHP 8.4.26 and PHPUnit 12.5.34. SQLite `:memory:`. `public/build` was absent.
- The worktree-local autoload runner (`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- …`), with a per-run `TMPDIR` in the session scratchpad and `--colors=never`. No `php artisan test`.
- Native MySQL: see A3.6. The shared :3306 server and other lanes' servers were not touched.

### A3.3 Decision

**APPROVE WITH CONDITIONS carries to `b8886440c59bd1f001d0278e6d545a570f813f11`. Codex P1 r4210033214 and Codex P1 r4210180698 are closed for the scope stated in A3.7.** No condition is open for this lane, and no new condition is added. I found nothing above Info. The new Info items are A3-5 to A3-8.

### A3.4 Design assessment

1. **c6 invariants.**
   - *One observer per frame.* `proveCreatable()` registers exactly one capsule. `CheckoutCommandFrame::register()` still refuses a second (`observer === null`), and `prepare()` on a retry still registers none (`$inserted === false`). The observer test counts frames whose committing dispatcher is a `CheckoutCommandCommitDispatcher`: 2 on the first initiate (NEW intent, re-proof), 1 on the retry (re-proof) and 0 on reconcile.
   - *Replays, reads, retries, reconcile, record and uncertain install no observer.* This is now deliberately narrowed by one exception: the `proveCreatable()` read frame. `record()`, `uncertain()`, `status()`, `reconcile()` and the retry `prepare()` still install none. What my earlier reviews relied on was that no frame **that writes provider or financial evidence** (record, uncertain) or that serves projections (status, reconcile) can be refused by a capsule. That still holds: `proveCreatable()` writes nothing of its own, so the observer can only refuse writes a committing listener added to that frame. Nothing else in c6 depends on read frames being observer-free. The dispatcher, frame and `CommandTransaction` treat a read frame exactly like a write frame.
   - *Refusal cleanup proves the original marker.* `admit()` calls `proveAnchor()` (release and reset of the original marker) before planning. At `TransactionCommitting` the dispatcher still ends with `proveAnchor()`. On refusal, `CommandTransaction` calls `abort()`, which releases the original marker and rolls back on the captured internal PDO, then clears the transactions manager at depth 0 and restores the dispatcher. The four new cases assert the dispatcher is restored and that the raw PDO has no open transaction.
   - *Placement.* The capsule is the last statement of the closure. `FreshCheckoutPolicy::capture()` follows `access->lock()` and the first flag check, and precedes the selection and policy proofs, the same "before extensible media checks" order as `prepare()`. `proveCreatable()` still runs before the provider `try`, so a refusal records no `uncertain` observation.
2. **`reprove()` plan.** `capture()` and `reprove()` share `admit()`. The only differences are the relaxed precondition `(! $new || observations === [])` and the observation selector, which now expects `$intent['observations']` instead of `[]` (for `capture()` the two are equal, because its precondition requires empty observations). Session and payment are still planned as exactly `[]` with limits 2. While `session === null`, `HostedEvidence::intent()` only accepts `uncertain` observations, so a retry can only carry `uncertain` rows. Result:
   - a retry after an `uncertain` create is admitted (the observer test's successful retry, 2 creates, 1 payment);
   - a session, payment or extra observation that appears in the commit is refused, because the selector sets are compared exactly;
   - M9 and M10 (A3.5) show that both changes are needed and that the suite catches either one being reverted.
3. **False-refusal analysis.** Every planned row set in `reprove()` is already compared, on the same frame and immediately before, by an existing proof, except four:

   | Planned set | Already proven by | Can a legitimate change land between `prepare()`'s commit and the re-proof? |
   | --- | --- | --- |
   | identity (`users`, account, origin, verifications, challenges) | Built from `proveCreatable()`'s **own** `access->lock()`, not from `prepare()` | No. It is captured fresh in the same frame. |
   | actor (`$buyer` attributes) | In-memory snapshot taken inside `admit()` | Only if code in the frame mutated `$buyer` after `admit()`. None does. |
   | `attempt` by `order_id` (exactly 1) and `intent` by `order_id` (exactly 1) | `OrderEvidence::proveRetained` and `HostedEvidence::proveRetained` check these rows by id | No. `CheckoutSchema` declares unique indexes on `attempt.order_id` and `intent.order_id`, so neither set can grow. |
   | selection graph, links | `CurrentSelection::proveCurrent` (`Evidence::same` on the full `PreparationSelection::load` graph and links) | Already refused with `changed` before the fix. |
   | policy and capability history | `CurrentPolicy::proveCurrent` (`PacketEvidence::historyRaw`, the same sets) | Already refused with `changed` before the fix. |
   | authority, basis, review, order, attempt, lines | `OrderEvidence::proveRetained`, `TaxExemptions::proveRetained` | Already refused with `changed` before the fix. |
   | intent row, session, observations, payment | `HostedEvidence::proveRetained` | Already refused with `changed` before the fix. |

   The intent's own observation from `record()` cannot fall in the window: `record()` runs only after `create`, which follows `proveCreatable()`. A concurrent request's `uncertain()`/`record()`, policy-history growth or an order row change in that window is caught first by the existing proofs with `changed`, exactly as at `0cb13ca5`. The deadline cap cannot create a new refusal for a valid retry either: the attempt, attestation and authority-policy bounds all lie after `retry_before`, which `prepare()` and `initiate()` already require to be in the future. A license `effective_until` falling inside the frame is a correct `write_frame` refusal, not a false one.

   **Probe P3** confirms this (`P3-false-refusal/`). A throwaway test in the scratchpad (copied as `.txt`; it was never placed in the repository tree) ran 16 scenarios on both the `0cb13ca5` app tree and the fix. Each scenario writes one row, either in the **window** (at the gateway's `provenance()`, after `prepare()`'s commit and before `proveCreatable()`) or **inside** `proveCreatable()`'s commit (a `TransactionCommitting` listener), on a first initiate and on a retry after `uncertain`:

   | Write | Window, before fix → after | In the re-proof commit, before fix → after |
   | --- | --- | --- |
   | unrelated `audit_events` row | ok → ok | ok → ok |
   | new `users` row (another account) | ok → ok | ok → ok |
   | capability-history audit (the draft's `ProductionTrackCapabilities` subject) | `changed` → `changed` | create made, then `ValidationException` → `write_source_changed`, 0 creates |
   | `Track` audit on the selected track | `changed` → `changed` | ok, create made → `write_source_changed`, 0 creates |

   The window column is identical before and after the fix, for both first initiate and retry, so the new observer adds no refusal to legitimate concurrent activity. The only new refusals are writes that a listener makes **inside** the re-proof's own physical transaction to a planned set, which is the intended fail-closed semantics of the NEW-intent capsule (Info A3-2). No refused scenario recorded an extra `uncertain` observation.
4. **The P1 is closed.** At `43ae65e6`, the four permanent cases {offer, capability} × {first initiate, retry} and the canary refuse with `write_source_changed`, with no new create, no session, the closure absent and the offer still active. `assertSame(1, $acted)` proves the listener ran in the re-proof's commit, so a refusal at capture time (before the commit) cannot satisfy the test by accident (M9 shows that it fails). For the canary at `b8886440`, see item 6.
5. **`b8886440`: the admitted re-proof is now terminal (Codex P1 r4210180698).** The change moves `$this->access->current($principal, $buyer)` from after `CommandTransaction::run(...)` to the first statement after the precondition in `proveCreatable()`. I read the delta against the code it calls:
   - *The P1 was real.* `ProductionCustomerAccess::current()` calls `source()`, which runs `$connection->transaction(fn () => $this->read(...))`: an ordinary framework transaction with no checkout observer. Before `b8886440` that commit sat between the admitted frame and `gateway->create()`, so a `TransactionCommitting` listener on it could withdraw the offer or close the capability and create still ran. My mutation M11 (A3.5) reproduces this: the terminal canary records `after: 1`, `acted: 1`, `at_create: 0` (offer inactive at create) and `provider_creates: 1`.
   - *No commit is left between the admitted frame and `create`.* After the frame's `$connection->transaction()` returns, `CommandTransaction::run()` only restores the dispatcher, compares the resolved connection and raw PDO, and calls `PrimaryBoundary::prove()`, which issues raw PDO reads (`SELECT DATABASE()` and `SHOW CREATE TABLE` on MySQL, `sqlite_master` reads on SQLite) and fires no framework events. `initiate()` then enters the provider `try`, compares the clock with `retry_before` and calls `create`. No framework transaction runs in that span. The only code that runs after the physical commit and before `create` is the frame's own after-commit callbacks and `TransactionCommitted` listeners (Laravel's order is: committing, PDO commit, after-commit callbacks, committed). First-party code registers neither on this frame: a `grep` for `afterCommit`, `TransactionCommitted` and `TransactionCommitting` in `app/`, `bootstrap/`, `config/` and `routes/` finds only the two checkout dispatchers and unrelated flows (jobs, inquiries, sound kits, site images, service attachments), none of which run inside `proveCreatable()`.
   - *Probe P4* (`P4-post-commit/`, a throwaway SQLite test kept as `.txt`; never placed in the repository tree) writes from those two post-commit hooks of the admitted frame on a first initiate:

     | Hook → write | Outcome at `b8886440` |
     | --- | --- |
     | after-commit callback or `TransactionCommitted` listener → autocommit offer deactivation | ok; create made with the offer inactive (`offer_active_at_create: 0`). This is README residual 1. |
     | either hook → offer deactivation inside a new `DB::transaction()` | refused `write_frame`, 0 creates, 0 sessions. The observer is still installed while these hooks run and refuses the new frame (`TransactionBeginning` marks it invalid). The refused nested transaction stays open on the raw PDO (Info A3-8). |
     | either hook → autocommit credential revocation (`customer_accounts.active = 0`, `access_version + 1`) | create made (`access_version_at_create: 2`); `initiate()` then throws `IdentityException` from its post-create `current()`, after `record()` stored the session. See A3-5 and the M11-tree comparison in A3.5. |

   - *Refusal semantics are unchanged.* The leading `current()` throws `IdentityException`, the same class the frame's first statement (`access->lock()` + `match()`) throws, so a credential that is already withdrawn refuses with the same exception as before, only earlier. `CheckoutIntentAdmission::reprove()` is byte-identical to `43ae65e6`, so the `write_source_changed` reason and the planned sets are unchanged. The leading call is still outside the provider `try`, so its refusal records no `uncertain` observation.
   - *Observer count is unchanged.* `current()` installs no observer. The observer test still counts 2 observed frames on the first initiate, 1 on the retry and 0 on reconcile (green at `b8886440`).
   - *Credentials.* The frame still re-locks and re-proves the same credential rows (`access->lock()` and `proveCurrent()`), and `reprove()` admits the identity rows at commit. What moved is the **post-frame** credential read: before `b8886440` a revocation landing after the frame's commit but before `current()`'s terminal read was refused before `create`; now it is caught only after `create` (Info A3-5).
   - *The leading `current()` is not pinned by a test.* Deleting it (M12) leaves `ProductionCheckoutWriteAdmissionAllTest` (26/187) and `ProductionCheckoutJourneyTest` (7/81) green. The frame's own credential proof and `initiate()`'s `current()` after `prepare()` cover the same rows, so this is defence in depth (Info A3-6).
   - *Tests.* The §4d permanent cases now act only on a commit whose connection dispatcher is a `CheckoutCommandCommitDispatcher`, i.e. explicitly on the admitted frame. After arming at `provenance()` the first such commit is the re-proof frame on both a first initiate and a retry, so the targeting is unchanged in substance and is now robust to the moved `current()`. The four new terminal cases count framework `TransactionCommitted` events between the admitted commit and the fixture's `create`, withdraw on any unobserved commit in that span, and assert `after: 0`, `acted: 0`, the offer active with 0 closures at create, and success with one session. They cannot see autocommit writes (no framework event), which is the documented residual. M11 turns all four red (2 failures, 2 errors) while the §4d cases and the observer test stay green; M8 turns all nine red.
6. **The archived §4d canary drift is benign.** The lane README §4e says the unchanged `conditions/codex-reprove-admission/canary/ReproveCommitAdmissionCanaryTest.php` now fails. I reproduced it at `b8886440` on SQLite (A3.5 item 4) and natively (A3.6): exit 1, 1 test, 4 assertions, 1 failure, `write_source_changed` expected and `changed` received; snapshot `acted: 1`, `refused: CheckoutException:changed`, `provider_creates: 0`, `sessions: 0`, `inactive_offers: 1`. Why this is benign:
   - The canary acts on "the first commit after `provenance()`". At `b8886440` that commit is the leading `current()`'s own transaction, which commits durably **before** the re-proof frame begins. The scenario has therefore become P3's "window" case (a withdrawal durable before the frame), which the existing raw proofs refuse with `changed`, exactly as at `0cb13ca5`.
   - The safety property is identical: no provider create and no session. `inactive_offers: 1` is correct for a withdrawal that committed independently; nothing should roll it back.
   - M11 (moving `current()` back after the frame) turns the same file green again (1 test, 6 assertions; `write_source_changed`, `inactive_offers: 0`). The drift is caused exactly by the move, not by a change in the admission.
   - The §4d property itself (a withdrawal inside the admitted frame's commit refuses with `write_source_changed` and is rolled back) is still pinned by the four permanent cases, which target the admitted frame explicitly. They are green at `b8886440` on SQLite and natively and red under M8.
   - The other archived canary, `checkout-write-commit-admission-20261007/CheckoutPhysicalCommitFreshPolicyCanaryTest.php`, targets `prepare()`'s NEW-intent frame and is unaffected: green, 1 test, 5 assertions.

   The canary file should stay immutable as historical evidence. README §4e already records the drift honestly. Info A3-7 recommends that the evidence index mark its pinned assertions as superseded by the permanent cases.

### A3.5 Commands and results (SQLite)

**At `43ae65e6` (first reviewer; `app/` equal to `019bc37d`).**

1. **Green.** Five runs in separate concurrent processes:

   | Run | Exit | Result | Wall time |
   | --- | --- | --- | --- |
   | `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` | 0 | OK, 22 tests, 151 assertions | 66.2 s |
   | `tests/Feature/ProductionCheckoutJourneyTest.php` | 0 | OK, 7 tests, 81 assertions | 34.6 s |
   | `tests/Feature/ProductionCheckoutExemptionAuthorityTest.php` | 0 | OK, 11 tests, 50 assertions | 26.0 s |
   | canary `conditions/codex-reprove-admission/canary/ReproveCommitAdmissionCanaryTest.php` | 0 | OK, 1 test, 6 assertions; snapshot `refused: CheckoutException:write_source_changed`, `provider_creates: 0`, `sessions: 0`, `inactive_offers: 0` | 6.4 s |
   | archived c6 physical canary (`checkout-write-commit-admission-20261007/CheckoutPhysicalCommitFreshPolicyCanaryTest.php`, sha256 `ce114992…`, `--filter test_physical_committing_policy_withdrawal_prevents_new_order_rows`) | 0 | OK, 1 test, 5 assertions | 7.3 s |

   In the JUnit, the four new cases made 11, 11, 13 and 13 assertions, and the observer test made 12. These counts match the implementer's `green/` claims. I summed their `green/sqlite-family/` JUnit: 18 files, 200 tests, 1002 assertions, 0 errors, 0 failures and 8 skips, which matches the claim. I did not rerun the other 15 family files.
2. **Mutations.** Each was applied, run, and reverted with `git checkout -- app`. `git diff --quiet -- app` succeeded after each.

   | ID | Mutation | Result |
   | --- | --- | --- |
   | M8 (requested) | `proveCreatable()` without the `CheckoutIntentAdmission::reprove(...)` call | New cases plus observer test: **exit 2, 3 failures and 2 errors** (5 tests, 15 assertions). Offer on first initiate and on retry: "A withdrawn NEW checkout write was admitted." Capability on first initiate and on retry: the closure committed, create ran, then a history load threw `ValidationException`. Observer test: "Failed asserting that 1 is identical to 2." Canary: **exit 1**, snapshot `refused: null`, `provider_creates: 1`, `sessions: 1`, `inactive_offers: 1`. All four withdrawal cases went red. |
   | M9 (false-refusal guard) | Observation selector expects `[]` again | Whole file: **exit 2, 1 error and 2 failures** (22/130). The successful retry in the observer test is falsely refused. Both retry withdrawal cases fail at `assertSame(1, $acted)`: the capsule refused at capture, before the listener could run. |
   | M10 (false-refusal guard) | `admit()` requires empty observations for `reprove()` too | Whole file: **exit 2, 1 error and 2 failures** (22/126). The observer test's retry is refused, and both retry withdrawal cases see `write_frame` instead of `write_source_changed`. |
3. **Pint.** `vendor/bin/pint --test` on the three changed PHP files passed (`pint.txt`).

**At `b8886440` (second reviewer).** Evidence is in `review-evidence/addendum3/terminal-b8886440/`. `source-sha.txt` there records `b8886440c59bd1f001d0278e6d545a570f813f11`, and `git diff --quiet -- app` held before every green run.

4. **Green re-run.** Six runs in separate concurrent processes:

   | Run | Exit | Result | Wall time |
   | --- | --- | --- | --- |
   | `tests/Feature/ProductionCheckoutWriteAdmissionAllTest.php` | 0 | OK, 26 tests, 187 assertions | 89.6 s |
   | `tests/Feature/ProductionCheckoutJourneyTest.php` | 0 | OK, 7 tests, 81 assertions | 41.6 s |
   | `tests/Feature/ProductionCheckoutExemptionAuthorityTest.php` | 0 | OK, 11 tests, 50 assertions | 33.2 s |
   | terminal canary `conditions/codex-terminal-reproof/canary/TerminalReproofCanaryTest.php` | 0 | OK, 1 test, 5 assertions; snapshot `admitted: true`, `after: 0`, `acted: 0`, `at_create: 1`, `provider_creates: 1` | 17.9 s |
   | archived §4d canary `conditions/codex-reprove-admission/canary/ReproveCommitAdmissionCanaryTest.php` | **1** | 1 test, 4 assertions, **1 failure** (expected `write_source_changed`, got `changed`); snapshot `acted: 1`, `provider_creates: 0`, `sessions: 0`, `inactive_offers: 1`. Judged benign in A3.4 item 6. | 18.1 s |
   | archived c6 physical canary (`--filter test_physical_committing_policy_withdrawal_prevents_new_order_rows`) | 0 | OK, 1 test, 5 assertions | 16.9 s |

   These match the lane's `conditions/codex-terminal-reproof/green/` claims (26/187, 7/81, 11/50, canary 1/5, the archived §4d canary failing with 1 test, 4 assertions, 1 failure, the c6 canary 1/5). I did not rerun the lane's 18-file SQLite family (204/1038).
5. **Mutations at `b8886440`.** Each was applied, run and reverted with `git checkout -- app`; `git diff --quiet -- app` succeeded after each (`revert.txt`). Diffs are in `M8/`, `M11/` and `M12/`.

   | ID | Mutation | Result |
   | --- | --- | --- |
   | M8 (re-run) | `proveCreatable()` without the `CheckoutIntentAdmission::reprove(...)` call | `--filter` §4d cases, terminal cases and observer test: **exit 1, 9 tests, 33 assertions, 9 failures**. Observer test: "Failed asserting that 1 is identical to 2." All four §4d cases: "A withdrawn NEW checkout write was admitted." All four terminal cases: `assertTrue($state['admitted'])` fails, because no observed frame exists. Terminal canary: **exit 1**, 1 test, 2 assertions, 1 failure. |
   | M11 (new) | Reverse the `b8886440` app delta: `current()` after the frame again | Same filter: **exit 2, 9 tests, 74 assertions, 2 failures and 2 errors**, all four in the terminal cases (offer: failures; capability: errors). The §4d cases and the observer test pass. Terminal canary: **exit 1**, 1 test, 3 assertions, 1 failure; snapshot `after: 1`, `acted: 1`, `at_create: 0`, `provider_creates: 1`. Archived §4d canary: **exit 0**, 1 test, 6 assertions; snapshot `refused: write_source_changed`, `inactive_offers: 0`. This independently reproduces the lane's red evidence and the cause of the canary drift. |
   | M12 (new) | Delete the leading `current()` from `proveCreatable()` | `ProductionCheckoutWriteAdmissionAllTest`: **exit 0**, 26/187. `ProductionCheckoutJourneyTest`: **exit 0**, 7/81. The call is not pinned (Info A3-6). |
6. **Probe P4** (`P4-post-commit/`). Six scenarios on a first initiate, SQLite. `probe4-fix`: exit 2, 6 tests, 12 assertions, 2 errors. Both errors are the two `offer_framework_tx` scenarios failing in teardown (`db:wipe`: "cannot VACUUM from within a transaction"), after the probe had written its row, because the refused nested transaction was left open (A3-8). The rows are in `b8886440.jsonl`; A3.4 item 5 summarises them. For comparison I ran the same probe on the M11 tree (`current()` after the frame again; `M11-tree-mutation.diff`, reverted): `probe4-M11-tree` exit 2, 6 tests, 12 assertions, 2 errors (the same two teardown errors). `M11-tree.jsonl` matches `b8886440.jsonl` in the four offer scenarios. The two credential scenarios differ: on the M11 tree they are refused by the trailing `current()` with `IdentityException` and **0 creates**, while at `b8886440` the create is made. This is A3-5. The `offer_framework_tx` rows (stray open transaction) are identical on both trees, so A3-8 predates `b8886440`.
7. **Pint.** `vendor/bin/pint --test` on `HostedCheckout.php` and `ProductionCheckoutWriteAdmissionAllTest.php` passed (`terminal-b8886440/pint.txt`).

### A3.6 Native MySQL

Evidence: `review-evidence/addendum3/native/` (`43ae65e6/`, `b8886440/`, `b8886440-M8/`, `private-instance-lifecycle.txt`). Every run used the worktree-local autoload runner with `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=<db> DB_USERNAME=root DB_PASSWORD= DB_URL=` in the environment, which overrides `phpunit.xml`'s non-forced SQLite `<env>` entries (the same approach as the lane's `conditions/native/`). The exact command line of each run is in `commands.txt`, and the runner and chain scripts are copied next to the results. Both canary snapshots record `"driver": "mysql"`, and `server-status-after.txt` shows each instance's `Com_create_table` counter (3204, 1367 and 1367).

**At `43ae65e6` (first reviewer).** One private MySQL 8.4.11 (`--no-defaults`, `127.0.0.1:3447`, no socket, `--skip-log-bin`, `--innodb-flush-log-at-trx-commit=2`, datadir in the session scratchpad; `native/43ae65e6/server-log-excerpt.txt`):

| Run | Exit | Result | Wall time |
| --- | --- | --- | --- |
| the four §4d withdrawal cases and the observer test | 0 | OK, 5 tests, 60 assertions (11, 11, 13, 13 and 12, as on SQLite) | 856.6 s |
| §4d canary `ReproveCommitAdmissionCanaryTest` | 0 | OK, 1 test, 6 assertions | 164.9 s |

That instance was not shut down by its reviewer. Its process was gone when I resumed, because the container had restarted, and I deleted its stale datadir (lifecycle file).

**At `b8886440c59bd1f001d0278e6d545a570f813f11` (second reviewer).** Three private MySQL 8.4.11 instances, each started with `mysqld --no-defaults --user=root --initialize-insecure` and then `mysqld --no-defaults --user=root --port=<3471|3472|3473> --bind-address=127.0.0.1 --socket= --mysqlx=OFF --skip-log-bin --innodb-flush-log-at-trx-commit=2 --innodb-buffer-pool-size=1G --sync-binlog=0 --max-connections=60`, with datadirs under `scratchpad/review-admission4-mysql/`. The socket is disabled because the requested socket path in that directory is 108 bytes, which exceeds the Unix socket path limit. Ports were checked free in `/proc/net/tcp` before starting. Each instance held **one** test schema, `va_rev_a3`, and ran its chain of PHPUnit processes one after another. `git diff --quiet -- app` held before every green run (`commands.txt`: `app_clean=yes`).

| Run | Instance | Exit | Result | Wall time |
| --- | --- | --- | --- | --- |
| `ProductionCheckoutWriteAdmissionAllTest`, shard g1 (4 terminal cases, observer test, offer-withdrawal NEW-intent test) | :3471 | 0 | OK, 6 tests, 57 assertions | 1402.8 s |
| shard g2 (4 §4d cases, three fresh/capability NEW-intent tests) | :3472 | 0 | OK, 7 tests, 64 assertions | 2554.1 s |
| shard g3 (retried-create, authority, basis, staff capsule, provider I/O tests) | :3473 | 0 | OK, 7 tests, 34 assertions | 1758.0 s |
| shard g4 (qualifier staff withdrawals) | :3472 | 0 | OK, 2 tests, 10 assertions | 446.9 s |
| shard g5 (license-expiry cap, staff replays) | :3471 | 0 | OK, 2 tests, 14 assertions | 542.9 s |
| shard g6 (owner staff withdrawals) | :3473 | 0 | OK, 2 tests, 8 assertions | 493.4 s |
| **whole file, summed** | | **0** | **26 tests, 187 assertions, 0 errors, 0 failures, 0 skips** | |
| terminal canary `TerminalReproofCanaryTest` | :3471 | 0 | OK, 1 test, 5 assertions; snapshot `admitted: true`, `after: 0`, `acted: 0`, `at_create: 1`, `provider_creates: 1`, `driver: mysql` | 281.3 s |
| archived §4d canary `ReproveCommitAdmissionCanaryTest` | :3471 | **1** | 1 test, 4 assertions, **1 failure**: expected `write_source_changed`, got `changed`; snapshot `acted: 1`, `provider_creates: 0`, `sessions: 0`, `inactive_offers: 1`, `driver: mysql`. This is the same benign drift as on SQLite (A3.4 item 6). | 237.5 s |

A script compared the testcase names: the six shards' JUnit files contain exactly the 26 testcases of the SQLite run, each once.

**Native M8 at `b8886440`** (`native/b8886440-M8/`; the same `reprove()` removal as the SQLite M8, applied after all green runs had finished and reverted with `git checkout -- app` at 23:32:59Z):

| Run | Instance | Exit | Result |
| --- | --- | --- | --- |
| the four §4d cases | :3471 | 1 | 4 tests, 12 assertions, **4 failures**, each "A withdrawn NEW checkout write was admitted." |
| the four terminal cases | :3472 | 1 | 4 tests, 16 assertions, **4 failures**, each `assertTrue($state['admitted'])` |
| the observer test | :3473 | 1 | 1 test, 5 assertions, **1 failure**, "Failed asserting that 1 is identical to 2." |

So the §4d and terminal properties are proven red-to-green natively as well as on SQLite.

**Aborted first attempt (harness error, not a product result).** I first ran six processes against six schemas on the single :3471 instance. The migrations' ownership guards scan `information_schema.TRIGGERS` server-wide (`IdentityMigrationOwnership.php:179`, and the capability-table guard does the same), so the concurrent schemas refused each other before DDL. The two canaries exited 2 with `LogicException` ("Unexpected production identity schema…", "Unexpected production capability external or additional table guard…"). I stopped the four shard processes and dropped the six schemas, then reran with one schema per instance as above. The outputs are kept in `native/b8886440/harness-error-parallel-schemas/` with a `NOTE.txt`. This is the designed one-schema-per-server behaviour of those guards, not a checkout finding.

**Lifecycle.** All three instances were stopped with `mysqladmin --no-defaults -h127.0.0.1 -P<port> -uroot shutdown`, and each pid was confirmed exited: :3473 at 23:21:32Z, :3471 at 23:31:19Z and :3472 at 23:33:02Z. Afterwards `/proc/net/tcp` showed no listener on 3471–3473, and the three datadirs plus the first reviewer's stale datadir were deleted and confirmed absent at 23:33:38Z. The shared :3306 server was not touched; it was not listening during this work, and I did not start it. Other lanes' instances (:3493, :3497 and later others) were not touched.

### A3.7 Residual

At `b8886440` the admitted re-proof frame is the terminal **framework** commit before `create`. What remains open is exactly a withdrawal that becomes durable **after that frame's physical commit** and before or during the external `gateway->create`:

- other connections. On MySQL their writes to the planned rows wait on the frame's `FOR UPDATE` locks until it commits, so they land after it;
- the frame's own after-commit callbacks and `TransactionCommitted` listeners, **when they write in autocommit mode** (P4: create runs with the offer already inactive). If such a hook opens a new framework transaction on the connection instead, the still-installed observer refuses it with `write_frame` and no create runs (P4), with the side effect recorded as A3-8;
- a buyer credential revoked in that span. Before `b8886440` the trailing `current()` refused this before `create` when the revocation landed before its terminal read. Now it is refused only after `create`, by `initiate()`'s post-create `current()` (A3-5);
- c6 F-5, a privileged listener that commits PDO directly, unchanged.

The item at `43ae65e6` "anything committed by `access->current()` or later code in `initiate()` before `create`" is **closed** by `b8886440`: `current()` now runs before the frame, and nothing between the frame and `create` opens a framework transaction (A3.4 item 5; M11 shows the suite catches a regression).

No database transaction can be held across the provider I/O, so these sessions belong to reconciliation and refund handling. README residual 1 at `b8886440` says: "A withdrawal inside `proveCreatable()`'s own commit is refused (§4d), and no other framework transaction commits between that admitted frame and `create` (§4e). A concurrent writer, or a post-commit `TransactionCommitted` callback, that lands after the admitted commit and before or during the external `create` call still crosses the provider boundary." That is accurate and matches the code and P4. It does not name after-commit callbacks or the credential case explicitly (A3-3, A3-5).

### A3.8 New findings

| ID | Severity | Finding | Recommendation |
| --- | --- | --- | --- |
| A3-1 | Info | Four comments describe the pre-`019bc37d` rule: `CheckoutCommandFrame::register()` ("Install only for NEW checkout writes. Replay/read/reconcile frames have no observer."), `CheckoutCommandCommitDispatcher` ("ONE local new-write observer"), `FreshCheckoutPolicy` ("Pure terminal admission for NEW checkout writes") and `HostedCheckout::prepare()` line 144 ("retries, reads and reconciliation do not"), still present at `b8886440`. The first two are in frozen c6 files, which correctly were not edited. The README and the docblocks on `CheckoutIntentAdmission` and `proveCreatable()` document the exception. | Update the wording with the F-6 consolidation, under its own review. Not a condition. |
| A3-2 | Info | The re-proof frame refuses any write a committing listener makes inside it to a planned set, including possibly harmless writes such as a `Track` audit row (P3). Legitimate concurrent writers commit on their own transactions and are handled identically before and after the fix. | None. |
| A3-3 | Info | README residual 1 at `b8886440` now names post-commit `TransactionCommitted` callbacks (the gap I noted at `43ae65e6`). It still does not name the frame's own after-commit callbacks, which behave the same way (P4). | Optional wording. |
| A3-4 | Info | On a retry, the re-proof frame needs a real connection event dispatcher (`CheckoutCommandCommitDispatcher::capture()` refuses `write_frame` otherwise). Laravel always sets one. | None. |
| A3-5 | Info | **Credential re-check moved before the frame.** A credential revoked after the admitted frame's commit (another connection, or an autocommit post-commit hook) is no longer refused before `create`. P4: create made with `access_version` 2 at create, then `IdentityException` from `initiate()`'s post-create `current()`, after `record()` stored the session; the buyer receives no session URL from that call. On the M11 tree the same revocation is refused before `create` (0 creates; A3.5 item 6). This is the same residual class as an offer withdrawn in that span, and the trade-off is sound: the alternative left an unobserved framework commit in front of `create`. README §4e says moving `current()` "keeps everything it proves"; that is true of what is proven, not of when. | Optional: say in README §4e that the post-frame credential read is gone and that the frame's admitted identity rows are now the last pre-create credential proof. Not a condition. |
| A3-6 | Info | The leading `current()` in `proveCreatable()` is not pinned by any test I ran (M12 survives: 26/187 and 7/81). The frame's `lock()`/`proveCurrent()` and `initiate()`'s `current()` after `prepare()` cover the same rows; the leading call adds `source()`'s outside-transaction terminal read and `IdentityPolicy::outsideTransactions()`/`requireEnabled()` immediately before the frame. | If that outside-transaction read is meant to be required here, add a test that pins it; otherwise accept it as defence in depth. |
| A3-7 | Info | The archived §4d canary `ReproveCommitAdmissionCanaryTest.php` (unchanged, sha256 `8b596d76…b5335c`) now fails its pinned `write_source_changed`/`inactive_offers: 0` assertions by design (A3.4 item 6). The safe outcome holds and the property is pinned by the permanent cases. README §4e records this. | Keep the file immutable. Mark its pinned assertions as superseded in the evidence index so that a future reader does not take the red run as a regression. |
| A3-8 | Info (pre-existing, frozen c6 code) | When a post-commit hook of an admitted frame opens its own framework transaction, the observer refuses it (`write_frame`, fail-closed, no create), but the refused transaction stays open on the raw PDO: `inTransaction()` is still true after `initiate()` returns, and the SQLite teardown then fails with "cannot VACUUM from within a transaction" (P4). Laravel's `handleCommitTransactionException()` lowers the depth without rolling back, and `CheckoutCommandFrame::abort()` tries `RELEASE SAVEPOINT` on the original marker, which no longer exists, so it skips the rollback. The stray write is uncommitted, and the next `CommandTransaction` refuses with `outer_transaction`. The same code path serves `prepare()`'s NEW-intent frame since c6 (not probed); `019bc37d` extended it to the re-proof frame. Reaching it needs an in-process privileged listener, the F-5 class. | Track it with F-5/F-6: consider rolling back an unowned open transaction at depth 0 on the refusal path. Not a condition. |

F-5, F-6 and F-7 remain open items as before. A2-2 still extends F-6.

### A3.9 Not reviewed in this addendum

- MySQL 8.0, hosted Foundation CI, the full PHP suite, the lane's 18-file SQLite family (I relied on its JUnit for `019bc37d` and did not rerun it at `b8886440`), route and provider registration, live providers, Paid252 composition and browser specs (§5 still applies).
- Native MySQL beyond the selection in A3.6. P3, P4, M9, M10, M11 and M12 ran on SQLite only.
- Whether Codex accepts the replies to r4210033214 and r4210180698.

### A3.10 Cleanup and what is uncommitted

- M8, M9, M10 and the P3 pre-fix tree swap (at `43ae65e6`), and M8, M11, M12, the P4 M11-tree run and the native M8 (A3.6) at `b8886440` were each reverted with `git checkout -- app` (`git checkout HEAD -- app` for P3). `git diff --quiet -- app` succeeded after each and at the end.
- No flag, registration, configuration or `app/`, `config/`, `routes/` or `database/` file differs from `b8886440`.
- The probes P3 and P4 ran from the session scratchpad. Their sources are kept only as `.txt` copies under `review-evidence/addendum3/`.
- The three private mysqld instances (:3471, :3472, :3473) were shut down, and their datadirs and the first reviewer's stale datadir were deleted (A3.6; `native/private-instance-lifecycle.txt`). No reviewer mysqld is running.
- The review worktree `/home/user/VA-Studio-review-admission4` (detached at `b8886440`) holds exactly two uncommitted items for the caller to commit:
  - modified: `docs/verification/checkout-write-admission-all-20261007/independent-review/DECISION.md` (this addendum);
  - untracked: `docs/verification/checkout-write-admission-all-20261007/independent-review/review-evidence/addendum3/` (`source-checks.txt`, `delta-0cb13ca5..019bc37d.diff`, `pint.txt`, `green/`, `M8/`, `M9/`, `M10/`, `P3-false-refusal/`, `P4-post-commit/`, `terminal-b8886440/`, `native/`).
- Raw outputs are ANSI-free. JUnit, exit codes, mutation diffs, probe sources and probe JSONL are next to them.
