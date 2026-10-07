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
