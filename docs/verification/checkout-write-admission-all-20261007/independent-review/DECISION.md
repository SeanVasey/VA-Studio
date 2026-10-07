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
