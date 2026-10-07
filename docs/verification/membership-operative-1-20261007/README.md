# Membership operative lane 1: steps 0.1, 0.2 and Billing259 section 1

- Branch: `harness/membership-operative-1` (local only, not pushed), from `de6ae38a` (`harness/membership-257-258`).
- Commits:
  - `75787f50` — step 0.1 (F1).
  - `960bc42c` — step 0.2 (F2).
  - `fe58fe91` — Billing259 section 1.
  - `46b1b66a` — the first version of this file.
  - `2dcfe136` — the O3 environment-read fix (coordinator follow-up).
  - `9c1ca807` — test-only drift D2 fix.
  - The docs commit that updates this file.
- Status: implemented and tested on SQLite and native MySQL 8.4.11, with the selections listed below. Independently reviewed at `c689ffdc`: APPROVE WITH CONDITIONS for a development merge only (`independent-review/DECISION.md`, conditions R-1 to R-5 are forward obligations before C2/C3/C4, webhook intake mounting and activation; the review's native runner did not capture PHPUnit exit codes, so its table cites the green summaries). Nothing is registered, bound or enabled. Pushed as `origin/harness/membership-operative-1`.

## What changed

| Step | Change |
| --- | --- |
| 0.1 (F1) | `MembershipRows` reads `pragma_function_list` and `PRAGMA collation_list` (no WHERE clause; filtered in PHP) before any other SQL and at every `context()`. It refuses application functions, aggregates, built-in overrides and new collations, then holds a table-less running statement so SQLite itself refuses a later replacement of a built-in function or collation (`createFunction`/`createCollation` return false). It pins the default fetch mode (O1). `MembershipPolicy` and `MembershipRows` refuse `verified_production` unless the driver is `mysql`. |
| 0.2 (F2) | `MemberGrantSchema` adds a fourth activation guard, `production_member_activations_consume` (BEFORE INSERT). It requires the 257 consume event for the same redemption and origin, with the same readiness receipt and purpose, created no later than the activation, whose reserve event's seal is `reservation_event_hash`. The three existing guards are byte-identical. New migration `2026_10_07_258100_couple_member_activation_consume.php` completes an older install through the same installer. It refuses `retained_unguarded_schema` if the activation table already holds rows, and its `down()` refuses. |
| 1 (Billing259) | `App\Domain\Memberships\Billing`: `BillingSchema` (migration 259000, four tables), `BillingProviderPin`, `BillingPolicy`, `BillingProviderGateway`, `StripeSdkBillingGateway` (GET only), `BillingProjection`, `BillingSettlement` (pure), `BillingLedger`, `BillingReconciliation`, `BillingWebhookIntake`, plus the job `App\Jobs\RetrieveMembershipInvoice` and `config/production-membership-billing.php` (literal default-off). It has no provider writes, no award and no auto-reversal. |

Not in this lane (open): the `BillingSubscriptionApprovals` staff MFA writer, and `StripeMembershipPaidInvoiceAuthority` (`lock`/`proveCurrent`) with its `BillingPaidInvoiceAuthorityTest`. Both wait on plan step 0.4 (root confirming the T23 committed APIs). `BillingLedger::currentSettled()` is the read side that authority will use, and it is tested. The approved amount and currency in a binding come from its sealed payload. Section 2 must check them against Sean's policy facts.

## Commands

From the worktree, with the synthetic key `APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M=`:

```
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/<dir> --log-junit <file>
vendor/bin/pint --test <changed paths>
```

Native runs used the same command with `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3318 DB_DATABASE=vaseyaudio_member_ops DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`, from a separate clean worktree checked out at the exact commit.

## Results

### SQLite (in-memory, commit `9c1ca807`; JUnit in `sqlite-9c1ca807/`)

| Selection | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| `tests/Feature/ProductionMembership` | 43 | 125 | 0 | 0 | 3 |
| `tests/Feature/ProductionMemberOriginals` | 24 | 63 | 0 | 0 | 1 |
| `tests/Feature/ProductionMembershipBilling` | 88 | 376 | 0 | 0 | 0 |
| **Total** | **155** | **564** | **0** | **0** | **4** |

### SQLite, earlier run (commit `fe58fe91`; JUnit in `sqlite/`)

| Selection | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| `tests/Feature/ProductionMembership` | 40 | 116 | 0 | 0 | 3 |
| `tests/Feature/ProductionMemberOriginals` | 24 | 63 | 0 | 0 | 1 |
| `tests/Feature/ProductionMembershipBilling` | 88 | 376 | 0 | 0 | 0 |
| **Total** | **152** | **555** | **0** | **0** | **4** |

The four skips are the existing named native-only cases: 257 foreign CHECK, foreign UNIQUE and changed enum, and 258 global CHECK. The baseline at `de6ae38a` was 38 tests, 94 assertions and 4 skips for the first two directories.

Red-before-fix evidence:

- `f1/regression-against-3cd-sqlite.xml`: the F1 regression against the unchanged 3cd Rows/Policy gives 15 failures out of 16. The one passing case is the residual characterization, which passes on both versions by design.
- `f2/regression-against-c4-sqlite.xml`: the coupling test against the uncoupled c4 schema gives 7 failures and 2 errors out of 10. The one passing case is `down()` refusing.

The SQLite-only F1 cases do not skip on MySQL. There they assert that `Pdo\Mysql` has no callback-registration methods and that the reader is admitted, because CI requires zero MySQL skips. `BillingNativeDedupRaceTest` runs two sequential retrievals on SQLite and two processes behind a barrier on MySQL, so it also has no skip.

Pint over all changed paths: passed.

### Native MySQL 8.4.11 (second private instance, port 3322; JUnit in `native/`, ledger in `native/ledger.jsonl`)

Each selection ran as its own PHPUnit process from a clean detached worktree at the listed commit (`dirty: false` in the ledger).

| Selection (label) | Commit | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- | --- |
| `o3-policy-environment` (`MembershipPolicyEnvironmentTest`) | `2dcfe136` | 3 | 9 | 0 | 0 | 0 |
| `f1-rows-function-closure` (`MembershipRowsFunctionClosureTest`, all 16) | `2dcfe136` | 16 | 114 | 0 | 0 | 0 |
| `f2-activation-coupling` (`MemberActivationCouplingSchemaTest`) | `2dcfe136` | 10 | 26 | 0 | 0 | 0 |
| `258-changed-and-native-only` (two changed author cases + global CHECK) | `2dcfe136` | 3 | 14 | 0 | 0 | 0 |
| `257-native-only` (foreign CHECK, foreign UNIQUE, changed enum) | `2dcfe136` | 3 | 4 | 0 | **1** | 0 |
| `257-statement-closure` (`MembershipNativeStatementClosureTest`) | `2dcfe136` | 1 | 3 | 0 | 0 | 0 |
| `billing-schema` (`BillingSchemaPreparationTest`) | `2dcfe136` | 9 | 43 | 0 | 0 | 0 |
| `billing-dedup-race` (two processes, one barrier) | `2dcfe136` | 1 | 14 | 0 | 0 | 0 |
| `billing-unknown-outcome` (ambiguous response only, see note) | `2dcfe136` | 1 | 2 | 0 | 0 | 0 |
| `billing-ledger` (newer non-settled hides settled; forged seal) | `2dcfe136` | 2 | 8 | 0 | 0 | 0 |
| `billing-webhook-replay` | `2dcfe136` | 1 | 6 | 0 | 0 | 0 |
| `billing-sdk-gateway` (complete graph through the SDK) | `2dcfe136` | 1 | 18 | 0 | 0 | 0 |
| `257-native-only-after-d2-fix` (the three native-only cases + data-bearing prefix) | `9c1ca807` | 4 | 10 | 0 | 0 | 0 |
| `billing-unknown-outcome-charge-stage` (timeout at charge, `#4`) | `9c1ca807` | 1 | 8 | 0 | 0 | 0 |
| **All recorded runs** | | **56** | **279** | **0** | **1** | **0** |

Notes on the native runs:

- The one error is composition drift **D2**, the same class as D1. On MySQL, `test_native_foreign_check_reserves_actual_global_symbol_before_any_owned_ddl` failed with error 3730: the empty `production_membership_billing_subscriptions` table has an FK to 257 `production_membership_plan_versions`, and the 257 test helper dropped that parent after removing only the empty 258 tables. SQLite allows the drop, so only the native run caught it. Commit `9c1ca807` fixes the helper (test only, no product change). The red run stays recorded; the rerun at `9c1ca807` passes, including the D1 data-bearing case.
- In the first unknown-outcome selection, the filter `…*charge` matched nothing: PHPUnit names unnamed data sets by index, not by value. So that run executed only the ambiguous-response case. The charge-stage case then ran separately with the index filter (`#4`).
- MySQL ran with zero skips.
- The earlier interrupted F1 run on port 3318 (12 of 16 progress dots, no JUnit) is kept as `native/f1-rows-function-closure-interrupted.txt`. The full F1 file above supersedes it.

Not run natively: the remaining Billing cases (6 of the 7 timeout stages, the other ledger, webhook and SDK-gateway cases, policy, pin and boot tests), the other 257/258 cases that are not native-only, and the cross-reader survey probe. The race case is the only proof of concurrency. SQLite proves none.

## Cross-reader survey (F1 pattern, report only)

Probe: `cross-reader/MembershipCrossReaderCallbackSurveyTest.php` (evidence only, not in the suite). Observation: `cross-reader/observation-sqlite.json`, SQLite 3.45.1. Each callback was registered before the reader ran, and it counted calls without acting.

| Reader | `lower()` override | `length()` override | `BINARY` collation override |
| --- | --- | --- | --- |
| `IdentityRows` construct (identity) | admitted, 966 calls | admitted, 0 | admitted, 19,333 |
| `CurrentRows::rows('users','id = ?')` (checkout/policy) | admitted, 0 | admitted, 0 | admitted, 0 (integer-only predicate) |
| `MemberGrantSchema::assertOwned` called directly | admitted, 69,552 | admitted, 0 | admitted, 32,392 |
| `MembershipRows` construct (this lane) | refused | refused | admitted, 25,137 (residual R1) |

Findings, reported for root (identity and checkout were not edited):

- `IdentityRows`, `CurrentRows`, `CommittedReadContext`, `CheckoutCommandFrame` and `IdentityOriginalCommitWitness` check only the PDO class and statement class. None checks SQLite functions or collations.
- `IdentityRows` uses `LOWER(name)`. `CapabilityMigrationOwnership` uses `COLLATE NOCASE` and `LOWER(TABLE_NAME)`. Checkout trigger guards use `GLOB`/`LENGTH` on SQLite.
- Any text comparison runs a replaced `BINARY` collation.
- `MemberGrantSchema` has no runtime reader yet. Step 5 must call it only while a `MembershipRows` frame is held.

Residual R1: SQLite does not show a built-in collation (`BINARY`, `NOCASE`, `RTRIM`) that was replaced **before** capture in any catalog, and PHP exposes nothing either. The pin blocks replacement after capture only. This is why `verified_production` is now refused on SQLite. A characterization test pins this residual.

## Other findings

- **O3 (fixed in `2dcfe136`):** `MembershipPolicy` and `MemberGrantPolicy` read the environment from `Container::$instances['env']`. Laravel binds `env` through `offsetSet` (a closure), so rehearsal was refused even in `testing`. Both now read it the way `BillingPolicy` does:
  - An instance keeps container precedence.
  - Otherwise only the container's own `offsetSet` closure is accepted, and its captured value is read by reflection.
  - Any other `env` binding refuses with `changed_policy` without being invoked.
  - The production refusal is unchanged.
  - `MembershipPolicyEnvironmentTest` was red before the fix (2 errors, 1 failure; `o3/red-before-fix-sqlite.xml`) and now passes on both drivers.
  - The existing nullable-environment baseline test now removes the real binding as well as the instance.
- **D2 (fixed in `9c1ca807`, test only):** see the native notes above.
- **Provider schema:** the locked SDK's `OPENAPI_VERSION` is `v2442`. The public `stripe/openapi` tags v2442 to v2450 serve `latest/openapi.spec3.sdk.json` with `info.version` `2026-07-29.dahlia`, not `2026-08-26.dahlia`. That spec is recorded as not adopted (`../membership-billing-259/provider-schema/manifest.json`). `BillingProviderPin` pins the locked SDK model files by SHA256 instead. An official `2026-08-26.dahlia` schema artifact is still open.
- **CI skip census:** `scripts/ci/database-sqlite-skips.json` does not list the four existing 257/258 native-only skips. Root's census normalization must add them. This lane added no new skipping case.

- **Codex P2 on PR #51, review conditions R-2 and R-3 (addressed in the working tree on top of `3092880b`, pending re-review; not committed here):** evidence is in `conditions/codex-r2-r3/`.
  - R-3: `BillingReconciliation::retrieve` no longer inserts the immutable invoice-identity row before the provider answers. A retrieved account, customer or parent subscription that contradicts the binding, with no identity this binding already owns, claims nothing and raises `binding_refused_<account|customer|subscription>`. Because observations hang off the identity row, that refusal is not stored as an observation; it is the thrown refusal only. An identity the binding already owns still receives binding refusals as observations. Unknown and provider-incomplete outcomes still claim the identity, because there is no retrieved graph to validate (residual: a first timeout under a wrong binding still pins).
  - R-2: a duplicate webhook delivery of a `retrieval_hint` event dispatches `RetrieveMembershipInvoice` again when the invoice has no observation row (any outcome) created strictly after the hint's `received_at`; the result's `scheduled` is then non-null. The event row is untouched and no table was added. A redundant dispatch appends one more chained observation, because the job itself is not unique-guarded.
  - Red before the fix: 3 failures in the R-3 cases, 4 failures in the R-2 cases. After: `ProductionMembershipBilling` directory 99 tests, 429 assertions, 0 failures on SQLite. Native MySQL was not re-run.

## Section 0.3 facts that remain Sean's

Every item 1 to 16 in `IMPLEMENTATION-PLAN.md` §0.3 remains unsupplied:

1. plans and versions
2. price per period in minor units, currency, tax-inclusive or Stripe Tax
3. intervals, anchors, trials
4. allowance per period
5. credit cost and eligibility
6. rollover and expiry
7. late invoices
8. cancellation
9. refunds and disputes
10. dunning
11. upgrades, downgrades, proration
12. coupons, discounts, zero and partial invoices
13. grandfathering and BeatStars migration
14. reservation honor window
15. member terms, profile and retention
16. Stripe account and mode to bind, and policy authors

Billing refuses every unapproved shape until a typed fact enables it: discount, tax, credit balance, credit note, off-Stripe, FX, zero, proration, multi-line and mixed payment. Live mode is refused (`live_not_authorized`). The production environment is refused. All fixture values are synthetic: currency `XTS` (ISO testing code), 1234 minor units, `acct_SYNTHETICREHEARSAL` and similar ids.

## Untested

- The native gaps listed under Native above.
- Real Stripe I/O: none was made. The SDK path was exercised only through a loopback `ClientInterface` fixture.
- The staff approval writer and the paid-invoice authority (not built).
- A real queue: `RetrieveMembershipInvoice` fails closed because no gateway is bound. With a sync queue, a dispatch inside webhook intake would raise after the hint commits, so root should mount intake with an async queue.
- `FinalizationDatabaseLifecycleTest`: it spawns `vendor/bin/phpunit`, which in an `mkworktree` worktree is a symlink to the main checkout, so Composer's autoloader loads twice and the process dies with a fatal error. This is a harness artifact; the test was not run in this lane.
- The full repository suite.

## Private instance lifecycle and cleanup

Full log: `private-instance-lifecycle.txt`.

First instance:

- mysqld 8.4.11, `--no-defaults`, port 3318, datadir in the session scratchpad.
- The first start failed because the socket path exceeded 107 bytes. The second start succeeded at 14:45:47Z.
- At the stop (15:18:59Z) the native batches were killed and the only user schema was `vaseyaudio_member_ops`.
- The TCP shutdown did not stop the process, so it was stopped with SIGTERM.
- Post-check: no mysqld on 3318, datadir removed, temporary native worktree removed.
- Nothing was created, changed or dropped on the shared 3306 server.

Second instance:

- mysqld 8.4.11, `--no-defaults`, port 3322, datadir `mysql-mo2/` in the scratchpad, pid 4006.
- Started at 15:27:31Z.
- All queued selections finished by 17:32:19Z. The only user schema was `vaseyaudio_member_ops`.
- It stopped through TCP shutdown and the process was verified gone by PID. No mysqld remains on 3322.
- Its datadir and the native worktree (`/home/user/VA-Studio-member-ops-native`) were removed.

## Hashes at `fe58fe91`

| Path | SHA256 |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipRows.php` | `ee4f4a949994bdcaffbc13411cb8529efe7eec3587e1aa1dd6136ba5d7437998` |
| `app/Domain/Memberships/Production/MembershipPolicy.php` | `92c9b28b1ccd31f3b31c726fa2133d6d47ccd94705456309cf68533206cc0dbb` |
| `app/Domain/Grants/Member/MemberGrantSchema.php` | `e520833125be2f8d382db2065e2714ee942f0e32dc339b3311e7745d53196ad4` |
| `database/migrations/2026_10_07_258100_couple_member_activation_consume.php` | `af89a0d59938a26898897bfecaf3b338fda4c8232b6e87079d6b1139f24923e0` |
| `database/migrations/2026_10_07_259000_production_membership_billing.php` | `5d01cc7fc6c7b866286d9e3c692981430543c1fd59f5807adda4463d5609efd8` |
| `app/Domain/Memberships/Billing/BillingSchema.php` | `8cd2ef7b4b7431ba59f042e3110127aa6bab6a0e2b6a46dc0f078381e8c76a68` |
| `app/Domain/Memberships/Billing/BillingSettlement.php` | `bf34878582b5d2a186706f0ca4e0df014b28c4a3ce8d54a1b372ecdcc4fd8ac4` |
| `app/Domain/Memberships/Billing/StripeSdkBillingGateway.php` | `cf853c8a284f10c5dd54b419d5e5e631ab32147d9227b139fd50813bdf4f438f` |
| `app/Domain/Memberships/Billing/BillingPolicy.php` | `974e44a65c868cf28456a24662f42c0202a919c16aa3688b001dde93a4d6991d` |
| `app/Domain/Memberships/Billing/BillingLedger.php` | `f6ddf0ced4bb480f2f1424eccad8f2f478b93d53d99ab414f0b8f6040780981c` |
| `config/production-membership-billing.php` | `50e17d551cebc2dd096067a8c7676b6c427960911e2dfad323ea2274ef6a75cb` |

Changed after `fe58fe91` (hashes at `9c1ca807`); `MembershipPolicy.php` supersedes the row above.

| Path | SHA256 |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipPolicy.php` | `9f37be772eb33737bfbf076f0005b306908e68b6fbd581fcd78d15928f485f8f` |
| `app/Domain/Grants/Member/MemberGrantPolicy.php` | `a57bcef5d91c56f47ba498c284e8cc8eb82346dd3554e141cae3a01f0c178929` |
| `tests/Feature/ProductionMembership/MembershipSchemaPreparationTest.php` | `8b944f62f0c0a2d5a5d71f03b9c24288761c94887867dd032f8ee20381c1358f` |
