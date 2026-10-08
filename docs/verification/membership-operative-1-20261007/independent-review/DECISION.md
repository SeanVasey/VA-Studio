# Independent review: membership operative lane 1 (F1, F2, Billing259 section 1, O3, D2)

- Reviewed SHA: `c689ffdcbc9fd67b59766e7cd524efda0a6eae94` (`origin/harness/membership-operative-1`).
- Base: `de6ae38a2064e5b06e5ed919c4d944500eab5bdb` (merge-base with `origin/main`).
- Code head: `9c1ca807`. `c689ffdc` changes docs only (`git diff --stat 9c1ca807 c689ffdc -- app database config tests routes bootstrap` is empty).
- Reviewer: independent reviewer agent. It did not author any lane commit, and it changed no app code, flag or registration.
- Completion: the first reviewer agent stopped mid-task (rate limit) after the native runs had finished. A second independent reviewer agent, which also authored no lane commit, completed the decision. It checked the evidence, filled the native table, corrected the inconsistencies listed under "Completion notes", and cleaned up the private instance. It started no new instance and re-ran no test.
- Date: 2026-10-07 (UTC).

## Environment

- A detached review worktree, `/home/user/VA-Studio-review-member`, made with `scripts/dev/mkworktree.sh` at `c689ffdc`. It has the main checkout's locked `vendor/` symlinked and its own Composer autoload.
- A second detached worktree at the base, `/home/user/VA-Studio-review-member-base`, used only for the guard comparison.
- PHP 8.4.26, PHPUnit 12.5.34, SQLite 3.45.1 (`:memory:` via `phpunit.xml`), and `stripe/stripe-php` v21.3.2 (`0d8b075e`).
- Native: a private `mysqld` 8.4.11 started with `--no-defaults` on 127.0.0.1:3461. Its datadir and socket were in the session scratchpad and its schema was `vaseyaudio_review_member`. The shared :3306 server and other lanes' instances were not touched. The full lifecycle is in `review-evidence/native/private-instance-lifecycle.txt`. The instance had no clean shutdown: it died with the first reviewer's shell after the last run. The completing reviewer confirmed that no mysqld process or listener remained, then removed its datadir.
- Synthetic `APP_KEY` from the lane README. Every key, account and amount is synthetic (`sk_test_SYNTHETICREHEARSAL`, `acct_SYNTHETICREHEARSAL`, `XTS`, 1234).

## Decision

**APPROVE WITH CONDITIONS.** This approves a development merge only, as default-off and unregistered preparation.

The invariants the lane claims hold on the reviewed commit:

- No path creates a grant, award, credit event, subscription state or reversal from a webhook signature or a provider response.
- Money is integer minor units with an explicit currency.
- Deduplication is durable.
- `BillingSettlement` is pure.
- The SDK gateway makes GET requests only, under the pinned API version and SDK.
- The three existing activation guards are byte-identical to base.
- The coupling guard refuses every mismatch I tried.

One lane claim does not hold in full:

- The F1 "running statement so later replacement is refused" can be released by destroying a clone of the frame (R-1). Its impact is limited to SQLite synthetic rehearsal, the same exposure as the documented residual R1.

The other findings above Info concern liveness and forward obligations. None of them is a money or authorization bypass.

This decision does **not** approve:

- activation;
- route, provider or gateway registration;
- live or test provider I/O against Stripe;
- the paid-invoice authority or the staff binding writer;
- any award, reserve, consume or activation writer;
- any price, currency, allowance or other policy fact.

## Findings

| ID | Severity | Area | Finding | Evidence |
| --- | --- | --- | --- | --- |
| R-1 | Low | F1 (SQLite) | `MembershipRows` keeps its pin as an object property and closes it in `__destruct`. There is no `__clone` guard. `clone $rows` followed by `unset($copy)` closes the **live** frame's pin. SQLite then admits `createCollation('BINARY', …)`. That callback ran **25,812** times inside the live frame's `assertCurrent()`, and the reader admitted. This contradicts the claim that a later built-in replacement is refused while captured. The impact equals residual R1 (SQLite serves synthetic rehearsal only; `verified_production` is refused on SQLite by the policy and the reader). | `review-evidence/probes/probe-clone-sqlite.txt` (`registered=true assertCurrent=admitted collation calls during reader=25812`) |
| R-2 | Low | Billing webhook (liveness) | `BillingWebhookIntake::receive` commits the hint and then dispatches `RetrieveMembershipInvoice`. A redelivery of the same event id returns `duplicate` **before** scheduling. If the dispatch fails after the commit, the retrieval hint is lost permanently and no sweep exists. A dispatch fails after the commit on a sync queue (no gateway is bound, so `BindingResolutionException`) or during an async queue outage. Fail-closed: nothing is awarded. | `review-evidence/probes/probes-sqlite.txt`: `first=threw BindingResolutionException events=1 redelivery duplicate=true scheduled=null observations=0` |
| R-3 | Low | Billing ledger (irreversible row) | `BillingReconciliation::retrieve` creates the immutable invoice-identity row (`ledger->invoice`) **before** the provider confirms that the invoice belongs to the binding. A first retrieval under the wrong binding records `refused/subscription` and permanently ties the invoice to that binding. The correct binding then always fails with `conflicting_invoice`, and only a retention migration can repair that. Webhook-driven retrieval selects the binding from the signed event's subscription, so this needs a manual or mistaken dispatch. Fail-closed: nothing is awarded. | `review-evidence/probes/probes-sqlite.txt`: `first outcome=refused reason=subscription then right binding=conflicting_invoice invoices=1` |
| R-4 | Low (forward) | F1 scope / 258 | `MemberGrantPolicy` still admits `verified_production` on SQLite: it passes the provenance gate and stops only at `capability_absent`. `MembershipRows`' `provenance_driver` refusal keys only on `production-memberships.provenance`. A 258 consumer with `member-grants.provenance = verified_production` and 257 rehearsal provenance would not be refused by driver. This is outside the lane's stated 0.1 scope, but C4 depends on it. | `review-evidence/probes/probes-sqlite-run3.txt`: `MemberGrantPolicy verified_production on sqlite: capability_absent` |
| R-5 | Low (performance, not measured here) | Billing reads | Every `BillingLedger::rows()` call, the webhook `event()` read and each schema use call `BillingSchema::assertOwned`. That calls `IdentityMigrationOwnership::inspect`, which the A2/B1 diagnosis found costs about 470 metadata statements per assertion on MySQL. A single retrieval performs several such reads, some inside the append transaction. | Code: `BillingLedger.php` lines 175-179 and `BillingSchema.php` line 45. Plan §3 A2 and B1 entries. |
| I-1 | Info | O3 | The reflection read accepts any closure whose scope is `Container`, whose `$this` is the container and whose only static variable is `value`. It does not check the body. A forged `Closure::bind(function () use ($value) { return 'production'; }, $app, Container::class)` with `$value = 'testing'` makes `MembershipPolicy` and `BillingPolicy` read `testing` while `app()->environment()` returns `production`. The read never invoked it (0 calls). Forging requires in-process code that could equally rewrite config, so this is a characterization, not a bypass of the stated O3 property ("read without invoking; refuse other bindings"). | `review-evidence/probes/probes-sqlite.txt`: `policy env=testing billing env=testing calls during policy reads=0 app()->environment()=production` |
| I-2 | Info | O3 consistency | `MembershipPolicy` and `MemberGrantPolicy` honour an `env` instance, consistent with the container. `BillingPolicy` refuses one with `changed_policy`. Both are safe; they differ. | `probes-sqlite.txt`: `membership env=testing … billing=changed_policy` |
| I-3 | Info | F1 | Thirteen extension names (`snippet`, `highlight`, `match` and others) are allowlisted with `builtin = 0`, so an application function under those names is admitted. Owned SQL in `Memberships/Production`, `Grants/Member`, `CurrentRows` and `IdentityMigrationOwnership` references none of them (grep). | `probes-sqlite.txt` (`reader=admitted calls=0`) |
| I-4 | Info | F2 | The coupling uses `c.created_at <= NEW.created_at`: an equal timestamp is admitted, and an activation one second before its consume is refused. Timestamps are caller-supplied text (`VARCHAR(19)`). | `probes-sqlite.txt`: `earlier=refused equal=admitted` |
| I-5 | Info | Settlement semantics | Settlement does not read `billing_reason`, `collection_method` or subscription `status`. A post-payment credit note is classified `refused` (`credit_note`), not `reversed`. A nonexistent invoice (provider error) is classified `unknown`, and repeated retries append up to `MAX_OBSERVATIONS` (1000). Section 2 policy must decide these. | Code review of `BillingSettlement.php` and `BillingReconciliation.php` |
| I-6 | Info | CI census | Confirms the lane's report: the four existing native-only skips are not in `scripts/ci/database-sqlite-skips.json`. | Lane README; skip names in `review-evidence/sqlite/*.xml` |

No finding is Medium or above.

## Verified claims (with evidence)

### Billing (task item 1)

- **No grant, award, reversal or subscription state from a signature or provider response.**
  - `BillingWebhookIntake` writes only to `production_membership_billing_events` and dispatches a retrieval job.
  - `BillingReconciliation` writes only invoice-identity and observation rows.
  - No Billing class or the job references `production_membership_credit_events` or `production_member_activations`, or performs an UPDATE or DELETE (`review-evidence/frozen-and-surface.txt`).
  - Probe: a validly signed `invoice.paid` body with `status=paid` gives 0 observations and 0 credit events.
  - `reversed` is only recorded. `currentSettled()` returns null once a newer non-settled observation exists, and nothing auto-reverses.
- **Money.**
  - `BillingExpectation` requires `int $amountMinor` in (0, 100000000] and a three-letter upper-case currency. It admits mode `test` only.
  - Settlement compares every amount with `!==` against that int and lowercases the currency for provider comparison.
  - The observation table's CHECK requires `amount_minor > 0` and a currency of length 3 for `settled`.
  - The amount and currency come only from the APP_KEY-encrypted binding payload.
- **Durable, replay-safe dedup.**
  - Events: `UNIQUE(provider_event_ref_hash)` plus a NOT EXISTS insert guard. The hash is domain-separated by account and mode. A concurrent loser re-reads.
  - Invoices: `UNIQUE(invoice_ref_hash)`, `UNIQUE(source_invoice_hash)` and a guard.
  - Observations: `UNIQUE(invoice_id, sequence)` plus a contiguous, hash-linked guard; append retries three times on contention.
  - Native two-process race: see the native table.
- **`BillingSettlement` is pure.** It is static and makes no I/O, clock, config or container call. `BillingValues::utc` (`gmdate`) and `ref` are deterministic.
- **GET only, pinned.**
  - The gateway calls only `accounts->retrieve`, `invoices->retrieve`, `invoices->allLines`, `invoicePayments->all`, `paymentIntents->retrieve`, `charges->retrieve`, `balanceTransactions->retrieve`, `subscriptions->retrieve` and `subscriptionItems->all`.
  - The lane's loopback transport throws on any non-GET request, and its test asserts `get` plus `Stripe-Version: 2026-08-26.dahlia` on every call.
  - Client options match `OwnAccountStripeGateway` and `StripeSdkCheckoutGateway`: `api_base` default, `stripe_account` and `stripe_context` null, `max_network_retries` 0, same API version.
  - Billing additionally pins the SDK version and reference, `ApiVersion::CURRENT` and nine model-file SHA256s. `composer.lock` has v21.3.2 at `0d8b075e…`.
  - Provider I/O refuses while any DB transaction is open.
- **Secrets.**
  - SDK and transport throwables become `provider_unavailable` without chaining.
  - `BillingException` carries only a reason code.
  - Secret parameters are `#[SensitiveParameter]`.
  - Observation payloads hold projections that drop `client_secret`, contacts, URLs and payment-method details.
  - The lane test injects `sk_test_SYNTHETICREHEARSAL` into a transport error and asserts that it is absent from the stored payload.
  - A secret-shape scan of the whole lane diff found only synthetic values.
- **Config.** `config/production-membership-billing.php` holds only literals (`false` and `null`), with no `env()` call. `mode` null gives provenance `none`, which refuses, and `live` refuses with `live_not_authorized`.

### Schema (task item 2)

- **259000.**
  - Owned-prefix installer, the same algorithm as 257/258.
  - Refuses `retained_unguarded_schema` when a data-bearing table lacks guards.
  - Idempotent rerun, and `down()` throws.
  - Lane tests `BillingSchemaPreparationTest` passed: 9 cases on SQLite here, and natively in the lane.
- **258100.**
  - It is `(new MemberGrantSchema)->up()`. It completes an empty pre-coupling install, refuses `retained_unguarded_schema` when an activation row exists (the guard stays absent and the row is retained), is idempotent, and its `down()` throws.
  - Lane `MemberActivationCouplingSchemaTest`: 10/10 on SQLite, and natively (see below).
- **Existing guards are byte-identical.**
  - I generated every `MemberGrantSchema` guard (`sql`, `body`, `event`) at base and at head for both drivers, by invoking the private `guards()` through reflection.
  - All 15 base guards per driver are IDENTICAL at head. The only addition is `production_member_activations_consume` (BEFORE INSERT).
  - Evidence: `review-evidence/guards/compare.txt` and the two JSON dumps.

### O3 (task item 3)

- `MembershipPolicyEnvironmentTest` passes at head (3/9).
- Mutation `old_instance_read` (instance-only read restored) gives 2 errors and 1 failure.
- Mutation `invoking_read` (`$this->container->make('env')`) gives 1 failure, because the binding was invoked.
- Mutation `drop_changed_policy` (shape check removed) gives 1 failure.
- Each mutation was applied to both policies and then reverted. After the revert, both files hash to the reviewed values (`9f37be77…`, `a57bcef5…`, as listed in `review-evidence/sha256-reviewed-files.txt`), and `git status` in the review worktree shows no change under `app/`. The `o3-mutations/` files hold no hash; the completing reviewer re-checked these two hashes.
- `app()->instance('env', …)` is honoured by Membership (consistent with `app()->environment()`) and refused by Billing.
- A forged container-scoped closure is characterized as I-1.
- No read invoked a binding (0 calls in every probe).

### F1 and F2 (task item 4)

- **F1, SQLite.**
  - A variadic `lower(-1)` registered after capture is **admitted by SQLite** despite the pin, because it is a different arity from built-in `lower(1)`. The per-call catalog read refuses it (`changed_primary_or_schema`), with 0 callback calls.
  - `max(2)` after capture is refused the same way on `one()`.
  - The pin still blocks `BINARY`, `NOCASE` and `lower(1)` after repeated savepoint cycles and reads.
  - Exception: R-1 (clone).
  - `verified_production` on SQLite is refused by `MembershipPolicy` (`provenance`) and by `MembershipRows` (`provenance_driver`), per the lane test.
- **F2, SQLite and native.** The coupling refuses each of these:
  - a `reservation_event_hash` equal to the award or consume seal (only the reserve seal admits);
  - a different receipt;
  - a different origin;
  - a different redemption;
  - a released reservation;
  - a consume created after the activation.
  - A consume that claims redemption R1 against a reserve of R2 is already refused by the 257 guard, and the activation is then refused.

### Lane selections and Pint (task item 5)

SQLite, this review, at `c689ffdc`:

| Selection | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- |
| `tests/Feature/ProductionMembership` | 43 | 125 | 0 | 0 | 3 |
| `tests/Feature/ProductionMemberOriginals` | 24 | 63 | 0 | 0 | 1 |
| `tests/Feature/ProductionMembershipBilling` | 88 | 376 | 0 | 0 | 0 |
| **Total** | **155** | **564** | **0** | **0** | **4** |

The four skips are exactly the named native-only cases:

- `MembershipSchemaPreparationTest::test_native_foreign_check_reserves_actual_global_symbol_before_any_owned_ddl`
- `MembershipSchemaPreparationTest::test_native_foreign_table_local_unique_does_not_pollute_owned_fk_dictionary`
- `MembershipSchemaPreparationTest::test_native_changed_enum_check_is_not_normalized_into_owned_provenance`
- `MemberOriginalSchemaPreparationTest::test_native_global_check_reservation_is_inspected_before_the_first_owned_ddl`

Pint `--test` over all 45 added or modified PHP files: `passed`.

Reviewer probes on SQLite were run three times:

- Run 1 (`probes-sqlite-run1-teardown-error.*`): 12 tests, 31 assertions, 1 harness error, retained.
- Run 2 (`probes-sqlite.*`): OK, 12 tests, 31 assertions. Most findings cite this run.
- Run 3 (`probes-sqlite-run3.*`, the final run): OK, 13 tests, 32 assertions. It adds the `MemberGrantPolicy` probe cited by R-4.
- The clone probe (`probe-clone-sqlite.*`) was a separate run: OK, 1 test, 1 assertion.

Run 1's teardown error: the forged-environment probe left the app in `production`, and the migration teardown then prompted.

Native MySQL 8.4.11, this review (private instance, port 3461):

Counts are from each selection's JUnit XML (top-level `testsuite` attributes, cross-checked per `testcase`). The text summaries agree. Wall time is the JUnit suite time; the PHPUnit `Time:` line and the ledger's start and end stamps agree to within a second.

| Selection (ledger label) | Command (`$P` as below, native environment) | Exit code | Tests | Assertions | Failures | Errors | Skipped | Wall time |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `f2-coupling` | `$P tests/Feature/ProductionMemberOriginals/MemberActivationCouplingSchemaTest.php` | not captured; result `OK (10 tests, 26 assertions)` | 10 | 26 | 0 | 0 | 0 | 947.7 s (15:47.732; 17:45:46Z to 18:01:34Z) |
| `probes-f2-billing` | `$P review-evidence/probes/ReviewerOperativeProbeTest.php --filter 'test_f2_\|test_billing_'` | not captured; result `OK (6 tests, 13 assertions)` | 6 | 13 | 0 | 0 | 0 | 803.7 s (13:23.657; 18:01:34Z to 18:14:58Z) |
| `billing-dedup-race` | `$P tests/Feature/ProductionMembershipBilling/BillingNativeDedupRaceTest.php` | not captured; result `OK (1 test, 14 assertions)` | 1 | 14 | 0 | 0 | 0 | 124.2 s (02:04.173; 18:14:58Z to 18:17:03Z) |
| **Total** | | | **17** | **53** | **0** | **0** | **0** | 1875.6 s |

Each run's JUnit is in `review-evidence/native/<label>.xml` and its text is in `<label>.txt`. The exact invocation is `review-evidence/native/runner.sh`, which is byte-identical to the script the first reviewer ran from the scratchpad. The ledger records `head c689ffdc… dirty=0` after every run.

What the native runs cover:

- `f2-coupling`: all 10 lane cases of `MemberActivationCouplingSchemaTest` on MySQL, including the 258100 successor install, refusal to adopt an uncoupled activation, and `down()` refusal.
- `probes-f2-billing`: the six reviewer F2 and Billing probes on MySQL. The observations match SQLite: seal mismatch refused; consume(R1) against reserve(R2) refused by 257, then activation refused; `earlier=refused equal=admitted`; and the R-2 and R-3 outcomes. A signed `invoice.paid` gives `observations=0 credit_events=0`.
- `billing-dedup-race`: the lane's two-process race, `test_two_retrievals_of_one_invoice_give_one_identity_and_two_ordered_observations`.

**Exit codes were not captured.** `runner.sh` writes `exit $?` after a `$(date …)` command substitution in the same string, so `$?` holds `date`'s status, not PHPUnit's. The ledger's `exit 0` entries are therefore not evidence of PHPUnit's exit status. The completing reviewer reproduced this: `bash -c 'false; echo "$(date -u +%F) exit $?"'` prints `exit 0`. Each run's own evidence is the plain green `OK (…)` summary, with zero failures, errors and skips in the JUnit and no warning, risky or deprecation notice in the text. PHPUnit emits that summary only for a successful result. No run was cut off, so none was repeated.

### Frozen surfaces (task item 6)

- `git diff --stat de6ae38a c689ffdc -- routes bootstrap app/Providers config/app.php composer.json composer.lock package.json package-lock.json .github` is **empty**.
- No class in `app/`, `routes/`, `bootstrap/` or `config/` binds or registers `BillingProviderGateway`, `StripeSdkBillingGateway`, `BillingWebhookIntake`, `BillingReconciliation` or `RetrieveMembershipInvoice`.
- The D2 fix (`9c1ca807`) touches one test helper only.

## Conditions

Conditions 1 to 3 apply before any operative consumer or mount that relies on the affected property. Conditions 4 and 5 attach to the steps named.

1. **R-1, before C2 builds on `MembershipRows`.**
   - Make the frame non-clonable: a `__clone` that throws, or a pin owned so that only the constructing frame can close it.
   - Add a SQLite regression: clone, unset, then replace `BINARY`. It must be refused or the frame must refuse.
   - Until then, do not cite the pin as preventing post-capture collation replacement.
2. **R-2, before root mounts webhook intake.**
   - Make retrieval scheduling recoverable. Options:
     - re-dispatch on a duplicate delivery when the invoice has no observation newer than the hint; or
     - an outbox or sweep for `retrieval_hint` rows without a later observation; or
     - an operator reconciliation command.
   - Mount intake with an async queue, as the lane already notes.
3. **R-3, before C3/C2 consumes Billing evidence.**
   - Do not create the immutable invoice-identity row until the retrieved invoice's own account, customer and parent subscription match the binding. Alternatively, record wrong-binding refusals without an identity row.
   - Add the wrong-binding-first regression.
4. **R-4, before C4 (258 operative).**
   - Refuse `verified_production` off MySQL in `MemberGrantPolicy` as well, or require the 258 writer to run only under a held `MembershipRows` frame whose 257 and 258 provenances are equal.
5. **R-5, before activation.**
   - Measure Billing retrieval and webhook latency natively, including `IdentityMigrationOwnership::inspect` per read, against the intake and job deadlines. Resolve this together with the A2/B1-C1 schema-isolation work.

The release boundaries carried forward unchanged:

- `enabled` and `provider_io_enabled` stay false.
- No gateway or route is bound.
- No live mode and no real Stripe I/O.
- Sean's §0.3 facts remain unsupplied.

## Not reviewed

- Real Stripe I/O: none was made. SDK behaviour was exercised only through the lane's loopback transport.
- The not-yet-built staff MFA binding writer and `StripeMembershipPaidInvoiceAuthority`.
- Native runs of the full lane directories. I ran only the selection in the native table.
- The cross-reader survey probe and the identity and checkout readers' F1 exposure. These are reported by the lane for root and out of scope here.
- `FinalizationDatabaseLifecycleTest` (a harness limitation in mkworktree worktrees, as the lane notes).
- The full repository suite, frontend and Foundation CI.
- Performance (R-5 is from code and plan evidence, not measured).
- `loadExtension` and other SQLite surfaces beyond functions, aggregates and collations.

## Commands and results

From `/home/user/VA-Studio-review-member`, with `APP_KEY=base64:U1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1NTU1M=`:

```
P='php -r '\''$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";'\'' --'
$P tests/Feature/ProductionMembership        --log-junit …/sqlite/ProductionMembership.xml          # OK, 43 tests, 125 assertions, 3 skipped
$P tests/Feature/ProductionMemberOriginals   --log-junit …/sqlite/ProductionMemberOriginals.xml     # OK, 24 tests, 63 assertions, 1 skipped
$P tests/Feature/ProductionMembershipBilling --log-junit …/sqlite/ProductionMembershipBilling.xml   # OK, 88 tests, 376 assertions
vendor/bin/pint --test <45 changed PHP files>                                                       # {"result":"passed"}
$P tests/Feature/ProductionMembership/MembershipPolicyEnvironmentTest.php   # baseline OK 3/9; mutations: old_instance_read E2 F1, invoking_read F1, drop_changed_policy F1
php …/scratchpad/review-member/dump-guards.php <worktree>                   # base vs head guard dump; 15/15 identical per driver, +1 consume guard
$P review-evidence/probes/ReviewerOperativeProbeTest.php                    # SQLite: OK 13/32 (run 3); clone probe OK 1/1 (observation above)
```

Native runs used the same command with `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3461 DB_DATABASE=vaseyaudio_review_member DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`, as scripted in `review-evidence/native/runner.sh`. The ledger is in `review-evidence/native/ledger.txt`; its `exit` field is not PHPUnit's status (see the note under the native table).

## SHA-256 of key files at `c689ffdc`

Every hash matches the lane README's recorded values where it lists them. The full list, including the remaining Billing classes, is in `review-evidence/sha256-reviewed-files.txt`.

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Memberships/Production/MembershipRows.php` | `ee4f4a949994bdcaffbc13411cb8529efe7eec3587e1aa1dd6136ba5d7437998` |
| `app/Domain/Memberships/Production/MembershipPolicy.php` | `9f37be772eb33737bfbf076f0005b306908e68b6fbd581fcd78d15928f485f8f` |
| `app/Domain/Grants/Member/MemberGrantPolicy.php` | `a57bcef5d91c56f47ba498c284e8cc8eb82346dd3554e141cae3a01f0c178929` |
| `app/Domain/Grants/Member/MemberGrantSchema.php` | `e520833125be2f8d382db2065e2714ee942f0e32dc339b3311e7745d53196ad4` |
| `database/migrations/2026_10_07_258100_couple_member_activation_consume.php` | `af89a0d59938a26898897bfecaf3b338fda4c8232b6e87079d6b1139f24923e0` |
| `database/migrations/2026_10_07_259000_production_membership_billing.php` | `5d01cc7fc6c7b866286d9e3c692981430543c1fd59f5807adda4463d5609efd8` |
| `app/Domain/Memberships/Billing/BillingSchema.php` | `8cd2ef7b4b7431ba59f042e3110127aa6bab6a0e2b6a46dc0f078381e8c76a68` |
| `app/Domain/Memberships/Billing/BillingSettlement.php` | `bf34878582b5d2a186706f0ca4e0df014b28c4a3ce8d54a1b372ecdcc4fd8ac4` |
| `app/Domain/Memberships/Billing/BillingLedger.php` | `f6ddf0ced4bb480f2f1424eccad8f2f478b93d53d99ab414f0b8f6040780981c` |
| `app/Domain/Memberships/Billing/BillingPolicy.php` | `974e44a65c868cf28456a24662f42c0202a919c16aa3688b001dde93a4d6991d` |
| `app/Domain/Memberships/Billing/BillingReconciliation.php` | `d02129f1c35447c433d6cfc45f2a3b0195abbeaa2028ec19241995265bc12701` |
| `app/Domain/Memberships/Billing/BillingWebhookIntake.php` | `e7f2f006a63440b634a091d618d37f9155a30f96ccc845f6dd4a6852185a9e3f` |
| `app/Domain/Memberships/Billing/StripeSdkBillingGateway.php` | `cf853c8a284f10c5dd54b419d5e5e631ab32147d9227b139fd50813bdf4f438f` |
| `app/Domain/Memberships/Billing/BillingProviderPin.php` | `a68dbca0eccc5ee5f217e269f094add3ecb37dbf9c7e514c9805e34b08f73456` |
| `app/Jobs/RetrieveMembershipInvoice.php` | `b2f0078b0a3cc4f05c0dc71ed0bace967bbc1d02fe248b8fabb185730866baf1` |
| `config/production-membership-billing.php` | `50e17d551cebc2dd096067a8c7676b6c427960911e2dfad323ea2274ef6a75cb` |

## Evidence index (`review-evidence/`)

- `sqlite/`: JUnit and text for the three lane directories.
- `pint.txt`: the Pint result.
- `frozen-and-surface.txt`: the frozen-path diff, the registration grep, the write-surface grep and the SDK call inventory. The first grep attempt's usage error is retained.
- `o3-mutations/`: the baseline and three mutation diffs, with JUnit and text, plus the `mutate.py` that applied them.
- `guards/`: the `dump-guards.php` script, guard dumps at base and head, `compare.txt`, and the dump runs' stderr (`base.err`, `head.err`, both empty).
- `probes/`: the reviewer probe source and the SQLite runs: run 1 (harness error retained), run 2 (`probes-sqlite.*`), run 3 and the clone probe.
- `native/`: the private-instance lifecycle (with the completing reviewer's cleanup entries), `runner.sh`, the ledger, and JUnit and text for `f2-coupling`, `probes-f2-billing` (the native probe run) and `billing-dedup-race`.
- `sha256-reviewed-files.txt`: 23 files. The completing reviewer re-checked every entry against both `git show c689ffdc:<path>` and the worktree; all match.

## Completion notes

The completing reviewer made these corrections to the first reviewer's draft. None changes a finding, the decision or a condition.

- **Native table filled** from the JUnit XML in `native/` in place of the placeholder. All three selections named in the ledger have JUnit and text, and the ledger ends `DONE`. No run was cut off or missing, so no instance was started and no test re-run.
- **Ledger exit codes** are `date`'s status, not PHPUnit's. This is now stated, and the table does not claim an exit code.
- **Lifecycle**: the file had no shutdown entry. Entries now record that the process was gone (`/proc/10138` absent, no process with a mysqld executable, `pgrep -x mysqld` exit 1, no LISTEN on 3306 or 3461) and that `$scratchpad/my` (218M) was removed.
- **Evidence index**: the native probe run is under `native/`, not `probes/`. `runner.sh`, `mutate.py`, `dump-guards.php` and the `.err` files are now listed. SQLite probe run 2, which most findings cite, is now described alongside runs 1 and 3.
- **O3 revert hashes**: the `o3-mutations/` files contain no hash, so the statement now points to `sha256-reviewed-files.txt` and the clean worktree, which the completing reviewer re-checked.


---

# Addendum 1: re-review of the R-2/R-3 delta (`3092880b..e26d4cb7`)

- Reviewed SHA: `e26d4cb74d464943178201133612e73b16945cf1` (`harness/membership-operative-1`, PR #51).
- Delta base: `3092880bc9a216ec095975a3ae15dfa07fee68b3`. Everything above this line (decision at `c689ffdc`, findings R-1 to R-5, I-1 to I-6) is unchanged.
- Reviewers: independent reviewer agents that authored no lane commit and changed no app code, flag or registration. The first Addendum 1 reviewer gathered the SQLite, red/green and most native evidence, then stopped (rate limit) before writing this addendum; the container then restarted, killing its private `mysqld` and its last native run. A completing reviewer, which also authored no lane commit, checked that evidence, re-ran the two incomplete native selections at `e26d4cb7` on a new private instance, reproduced red/green for the three later rounds, and wrote this addendum. Nothing was committed by either reviewer.
- Date: 2026-10-07 to 2026-10-08 (UTC).

## Scope (the delta, by commit)

Code changed only in `app/Domain/Memberships/Billing/{BillingLedger,BillingReconciliation,BillingWebhookIntake}.php`; tests changed in five `tests/Feature/ProductionMembershipBilling` classes; the rest is lane evidence and README.

| Commit | Change | Lane evidence |
| --- | --- | --- |
| `9c2f9928` | R-3: `BillingLedger::existingInvoice()` reads without inserting; `retrieve()` claims the identity row only after the verdict, and a first retrieval whose verdict is `refused` for `account`, `customer` or `subscription` throws `binding_refused_<reason>` with no row. R-2: a duplicate `retrieval_hint` delivery runs `recoverLostDispatch()`, which re-dispatches unless an observation newer than the hint exists. | `conditions/codex-r2-r3/` |
| `af089d40` | `invoice_payment.*` hints resolve the binding through the invoice's existing identity row (none: hint retained, no dispatch). The concurrent-insert loser runs the same recovery. | `conditions/codex-intake-2/` |
| `4716ae52` | Intake refuses `event.api_version` other than `BillingProviderPin::API_VERSION` before any row or dispatch. | `conditions/codex-intake-3/` |
| `e26d4cb7` | A first retrieval ending `unknown` (`provider_unavailable`, `provider_inconsistent`) or `refused/provider_incomplete` throws and writes nothing. Only a definitive observation (not `unknown`, not `refused/provider_incomplete`) created strictly after the hint covers it. | `conditions/codex-unknown-outcomes/` |

Unchanged in the delta (checked): `RetrieveMembershipInvoice.php` (`b2f0078b…`, `$tries = 1`), `BillingSettlement.php` (`bf348785…`), routes, bootstrap, providers, config, database, Composer and npm manifests and lockfiles, `.github` (`git diff --stat 3092880b e26d4cb7 -- routes bootstrap app/Providers config database composer.json composer.lock package.json package-lock.json .github` is empty). No added line in `app/` writes a credit, award or grant, or issues UPDATE/DELETE (the only grep hit is a docblock line). Nothing in `app/Providers`, `routes`, `bootstrap` or `config` binds or registers the gateway, intake, reconciliation or job (grep exit 1). No code reads `currentSettled()` outside `BillingLedger`.

## Environment

- Review worktree `/home/user/VA-Studio-review-member`: moved `c689ffdc` → `9c2f9928` (22:34Z) → `e26d4cb7` (23:40Z, reflog). Scratch worktrees in the session scratchpad: `review-member-head` (`4716ae52` from 22:52Z, `e26d4cb7` from 23:19Z) and `review-member-redgreen` (red/green). All have the main checkout's locked `vendor/` symlinked and their own Composer autoload.
- PHP 8.4.26, PHPUnit 12.5.34, SQLite `:memory:`, synthetic `APP_KEY` from the lane README; every value synthetic.
- Native: private `mysqld` 8.4.11, `--no-defaults`, 127.0.0.1:3531, datadir and server socket under `$scratchpad/review-member-mysql/`, X Protocol off; clients over TCP with `DB_SOCKET=` empty. The shared :3306 server and other lanes' instances were not touched. Two instance lifetimes, both recorded in `review-evidence/addendum1/native/private-instance-lifecycle.txt`: the first (pid 25136, 22:37Z) died with the container restart; the completing reviewer confirmed it gone, removed its datadir (229M), and started a second (pid 6342, 03:07Z) for the re-runs.
- Native runner: `review-evidence/addendum1/native-head/runner-head.sh` (unchanged), which records PHPUnit's own exit status: `rc=$?` on its own line straight after `php` returns, in both the run's text file and `ledger.txt` (`phpunit_rc=`). The exit-code capture defect of the original review's `review-evidence/native/runner.sh` (its ledger `exit` field held `date`'s status, not PHPUnit's) is documented above under the native table and in "Completion notes"; it does not affect any Addendum 1 run, and every exit code cited in this addendum is PHPUnit's own.

## Commands and results

`$P` = `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --`. Counts are from each run's JUnit XML; the text summaries agree.

### SQLite

| Evidence | SHA | Selection | Result | Exit |
| --- | --- | --- | --- | --- |
| `sqlite/ProductionMembershipBilling.*` | `9c2f9928` | `tests/Feature/ProductionMembershipBilling` | OK, 99 tests, 429 assertions, 0 skipped | 0 |
| `probes/probes-sqlite.*` | `9c2f9928` | reviewer `Addendum1ProbeTest` (earlier revision) | OK, 10 tests, 17 assertions, 3 skipped (native-only) | 0 |
| `sqlite/head-ProductionMembershipBilling.*` | `4716ae52` | lane directory | OK, 105 tests, 461 assertions | 0 |
| `sqlite/head-probes-sqlite.*` | `4716ae52` | reviewer probes | OK, 12 tests, 17 assertions, 5 skipped (native-only) | 0 |
| `sqlite/final-ProductionMembershipBilling.*` | **`e26d4cb7`** | lane directory | **OK, 115 tests, 520 assertions, 0 skipped** | **0** |
| `sqlite/final-probes-sqlite.*` | **`e26d4cb7`** | reviewer probes (current revision) | OK, 15 tests, 18 assertions, 6 skipped (the six native-only race probes) | 0 |
| `pint-e26d4cb7.txt` | `e26d4cb7` | `vendor/bin/pint --test` on the 3 app and 5 test files | `passed` | 0 |

SHA attribution: the `final-*` and `head-*` text files print `head <sha>` themselves; the two undated `9c2f9928` runs (22:38Z and 22:40Z) precede the worktree's next checkout (23:40Z) and the `af089d40` commit time (22:41:35Z).

Red/green (SQLite; tests at the round's head, the round's app files reverted to its base, then restored; `git status` clean after each restore):

| Round | Evidence | Red | Green |
| --- | --- | --- | --- |
| `9c2f9928` (R-2/R-3), first reviewer | `red-green/red.txt`, `green.txt` | `BillingObservationLedgerTest` 3 F of 11; `BillingWebhookRedeliveryTest` 4 F of 6; `BillingWebhookIntakeTest` 0 F of 5 (unchanged behaviour) | 11/38, 6/34, 5/38; all exit 0 |
| `af089d40`, completing reviewer | `red-green/rounds/round2-af089d40*` | `BillingWebhookRedeliveryTest` 3 F of 11, exit 1 | 11 tests, 56 assertions, exit 0 |
| `4716ae52`, completing reviewer | `red-green/rounds/round3-4716ae52*` | `BillingWebhookIntakeTest` 1 F of 6, exit 1 | 6 tests, 48 assertions, exit 0 |
| `e26d4cb7`, completing reviewer | `red-green/rounds/round4-e26d4cb7*` | `BillingUnknownOutcomeTest` 9 F of 11, `BillingWebhookRedeliveryTest` 4 F of 19, `StripeSdkBillingGatewayTest` 1 F of 6; each exit 1 | 11/90, 19/81, 6/40; each exit 0 |

Each reproduces the lane's recorded red count (3 + 4; 3; 1; 14 of 36). The script is `red-green/rounds/redgreen-rounds.sh`. The rewritten assertions in `BillingUnknownOutcomeTest` and `StripeSdkBillingGatewayTest` keep the old coverage under an owned identity (unknown still appended, chained and not settled) and add the no-row first-retrieval cases; no assertion was weakened.

### Native MySQL 8.4.11 (private instance, port 3531)

| Evidence (`native-head/`) | SHA | Selection | Result | PHPUnit exit | Status |
| --- | --- | --- | --- | --- | --- |
| `native/native-ledger.txt` | `9c2f9928` | lane directory | killed (rc 143) after a parallel run errored tests 6-8 | 143 | Discarded, not cited |
| `ledger.txt` first `probes-native-races` | `4716ae52` | race probes | killed (rc 143), same cause | 143 | Discarded |
| `probes-native-races-4716ae52.*` | `4716ae52` | race probes (5) | 3 pass; intake-race probe harness failure (`lock_waiters=0`); stale-race worker hit the 90 s process timeout | 2 | Superseded; both probes fixed and re-run at `e26d4cb7` |
| `final-probes-native-races.*` (= `-run1.*`) | `e26d4cb7` | race probes (6) | 5 pass; stale-race worker SIGKILLed (signal 9; the worker's comment records a memcg OOM kill of a php process at that time) | 2 | Superseded by `final2-` |
| `final-ProductionMembershipBilling.*` | `e26d4cb7` | lane directory | cut off at 95/115 by the container restart (no failure shown up to then; empty XML; no ledger `end`) | none | Superseded by `final2-` |
| **`final2-probes-native-races.*`** | **`e26d4cb7`** | `$P …/addendum1/probes/Addendum1ProbeTest.php --filter test_probe_native_` | **OK, 6 tests, 64 assertions, 0 F/E/S, 652.4 s** | **0** | Cited |
| `final2-ProductionMembershipBilling-sigterm.*` | `e26d4cb7` | `$P tests/Feature/ProductionMembershipBilling` | received SIGTERM after 7 tests (03:31:00Z); not sent by the reviewer (shared container with several other lanes' runs; the private `mysqld` stayed up) | 143 | Not cited; recorded in `ledger.txt` |
| **`final3-ProductionMembershipBilling.*`** | **`e26d4cb7`** | `$P tests/Feature/ProductionMembershipBilling` | **OK, 115 tests, 529 assertions, 0 F/E/S, 9944.9 s** (03:31:27Z to 06:17:12Z, under a load average near 8) | **0** | Cited |

The cited runs were sequential on one database (`vaseyaudio_review_member_final`, dropped and recreated before each chain; scripts `chain-final2.sh`, `chain-final3.sh`, output `chain-logs.txt`) from `/home/user/VA-Studio-review-member` with the ledger recording `head e26d4cb7… dirty=0`. The native directory has 9 more assertions than SQLite (529 vs 520) because `BillingNativeDedupRaceTest` asserts 14 natively and 5 on SQLite; there are no skips on either driver. A first launch of the chain was stopped by the completing reviewer within seconds (to relaunch it detached); it is recorded as `ABORTED` in `ledger.txt` and its partial files were discarded.

## Race probes (native, `e26d4cb7`, `final2-probes-native-races.txt`)

Two worker processes, distinct PIDs and connection ids, both outside any transaction, released together behind a file barrier placed after both have passed `existingInvoice()` (so the identity claim itself races).

| Probe | Observation | Reading |
| --- | --- | --- |
| Two bindings, invoice graph `livemode=true` (mode refusal for both) | one `saved refused` (reason `mode`), the other `denied conflicting_invoice`; invoices=1, observations=1 | The `mode` refusal claims the identity for whichever binding wins. Confirms A1-1 natively. |
| Two bindings, provider unavailable for both | both `denied provider_unavailable`; invoices=0, observations=0 | A first unknown pins nothing (R-3 part closed). At `4716ae52` the same probe saved an `unknown` identity. |
| Right binding (settled graph) vs wrong binding | right `saved settled seq=1`; wrong `denied binding_refused_subscription`; invoices=1, owner=right | R-3 closed for the binding-contradiction case under a real race. |
| Same binding twice (both settled) | both saved, seq 1 and 2; invoices=1 | One identity, contiguous chain under contention. |
| Two deliveries of one event id; every sync dispatch lost (no gateway bound) | before release both connections were in `INSERT` with `innodb_lock_wait=2`; w0 (winner, main path) threw `BindingResolutionException`; w1 (loser) threw the same from `recoverLostDispatch@BillingWebhookIntake.php:92`; events=1; a later sequential redelivery dispatched (pushed=1), observations=0 | The loser no longer returns a silent success when the winner's dispatch was lost: it re-attempts and fails loudly, so the provider retries. |
| Overlapping retrievals: stale (reads settled, stalls, appends late) vs fresh (reads refunded, appends first) | chain `1: reversed (retrieved 03:19:13)`, `2: settled (retrieved 03:19:10, created 03:19:19)`; `currentSettled()` = seq 2 settled | An older settled snapshot becomes current after a newer reversal. Confirms R-6 natively. |

## Findings (Addendum 1)

| ID | Severity | Finding | Recommendation |
| --- | --- | --- | --- |
| R-6 | Medium (forward; no consumer at `e26d4cb7`) | `BillingLedger::currentSettled()` names the sequence tail. Two overlapping retrievals of one invoice append in commit order, not provider-read order, so a settled snapshot read before a refund or dispute can be appended after the `reversed` observation and become current for up to `FRESHNESS_SECONDS` (600). The append's `clock` check orders `created_at` only. Reproduced natively (table above). Nothing reads `currentSettled()` today, so there is no award impact on this commit. | Condition R-6 below. |
| A1-1 (R-3 residual) | Low | Settlement refuses `account`, then `invoice_identity`, then `mode`, before it checks `customer` and `subscription`. `retrieve()` treats only `account`, `customer` and `subscription` refusals as binding refusals, so a first retrieval refused for `invoice_identity` or `mode` claims the immutable identity for a binding the provider graph never validated (SQLite probe `mode-first`: `identity_owner=wrong; right binding then=conflicting_invoice`; native two-bindings race). The lane's claim "claimed only after a retrieved provider graph validated the binding" therefore holds for three of the five pre-validation refusals. Practical exposure is small (a `test` key cannot read live objects, and a provider returning a different invoice id is implausible), and nothing is awarded. | Claim the identity only when the verdict passed the customer and subscription checks (for example a `bindingValidated` fact set by `BillingSettlement`), or add `invoice_identity` and `mode` to the non-claiming set. Add the wrong-binding-first `mode` regression. |
| A1-2 (R-2 remainder) | Low (liveness) | Recovery runs only on a provider redelivery, and the provider redelivers only when the delivery itself failed. With an async queue, dispatch succeeds and the webhook is acknowledged before the job runs; if the job then fails (a first `unknown` or `provider_incomplete` now throws, `$tries = 1`, no sweep), the hint is retained but never retrieved again until some other event for that invoice arrives. `invoice_payment.*` hints for an invoice with no identity are never dispatched. Fail-closed: nothing is awarded. | Condition R-2 (remaining) below. |
| A1-3 | Low (liveness) | Coverage compares the observation's `created_at` (append time) with the hint's `received_at`. A retrieval whose provider reads began before the hint but appended after it covers the hint (SQLite probe `stale-read`: invoice read open at T0, hint at T0+1 with dispatch lost, observation `not_settled/invoice_open` created at T0+2, redelivery at T0+3 `scheduled=null`). The paid state is then not retrieved until another event. Fail-closed. | Cover only with an observation whose retrieval started strictly after `received_at` (record the attempt start, `attemptedAt`, on the observation or compare against it). Fold into the R-2 remaining condition. |
| A1-4 | Low (evidence) | A first-retrieval refusal (`binding_refused_*`, `unknown`, `provider_incomplete`) writes no ledger row. Its evidence is the retained hint row (durable, sealed, encrypted) plus a failed job. The failed job's exception message is the generic "Production membership billing unavailable."; the reason code is only in the log context (`ctx_reason=…`); `failed_jobs` is prunable and absent on a sync queue; and the serialized job in `failed_jobs` holds the plaintext invoice ref and binding id (probes `failed-job`, `first-unknown failed-job`). | Judgement (a) below; condition before C3/C2. |
| A1-5 | Info | The retrieval job is not unique-guarded: two duplicates before the job runs give two dispatches and two chained observations (lane test `test_duplicates_before_the_retrieval_runs_each_schedule_and_only_append_valid_chained_observations`; probe `storm`: 25 duplicates, 25 pushes). Harmless per retrieval (GET-only, chained), but each redelivery whose retrieval ends `unknown` appends a row that never covers, and `MAX_OBSERVATIONS = 1000` then refuses every later append for that invoice (`technical_bound`). | Judgement (b) below. |
| A1-6 | Info | Same-second coverage: an observation with `created_at == received_at` does not cover (strict `>` on second-granularity text). Probe `same-second`: two extra retrievals before the hint is covered. | Accept (position (d)-1). |
| A1-7 | Info | (Code reading, `BillingSettlement.php` lines 165-166.) A first retrieval whose PaymentIntent names a foreign customer throws `binding_refused_customer` even though the invoice's customer and subscription matched; a mis-versioned event (`api_version`) is refused before any row, so no evidence of it is stored. Both are fail-closed and loud. | None required; note for operators. |

No finding is High. R-6 is the only Medium, and it is forward: it has no effect until something consumes `currentSettled()`.

## Judgements

**(a) First-retrieval wrong-binding or unknown outcome is a thrown refusal with no ledger row.** This is the right trade-off for the immutable, unique identity row: a row claimed under an unvalidated binding can only be repaired by a retention migration (the original R-3). Against the invariant "durable verified payment state is required before a grant … preserve reconciliation evidence": no grant can follow from a thrown refusal, and the hint that triggered it is preserved, so the invariant is not violated. The attempt's outcome, however, is preserved only in non-durable places (A1-4), and the hint is re-tried only on a provider redelivery (A1-2). Accept for a development merge; before C3/C2 rely on Billing evidence, either add a durable, non-identity attempt record (append-only, keyed by the event or hint hash, reason code only, no invoice-identity claim) or have root and Sean accept "retained hint + failed job + log" as the evidence of record, and put the reason code in the exception message (the reason is a code, not a secret).

**(b) The retrieval job is not unique-guarded.** Accept as Info. Every retrieval is read-only against the provider and appends one sealed, chained row; the native same-binding race shows one identity and a contiguous chain. The cost is duplicate rows and, under an `unknown` storm, eventual exhaustion of `MAX_OBSERVATIONS`. The fix that removes this also addresses R-6: `ShouldBeUnique` plus `WithoutOverlapping` keyed on the invoice hash (or one per-invoice serialization point), so overlapping retrievals of one invoice cannot run.

**(c) Race probes.** All six pass natively at `e26d4cb7` (exit 0). The identity claim is race-safe (one row; the loser gets `conflicting_invoice` or `binding_refused_*`; a first unknown from either side pins nothing). The concurrent-insert loser recovers the winner's lost dispatch. Two findings are confirmed under a real two-process race: A1-1 (`mode` refusal claims for the winner) and R-6 (stale settled becomes current).

**(d) Codex's three open design findings.**

1. *Same-second `created_at == received_at` does not cover.* Position: accept as designed. Timestamps are whole seconds, and an observation stamped in the hint's second may come from a read that began before the hint. Failing toward one more read-only retrieval is the safe direction. (A1-3 is the opposite gap, where coverage is too generous, and should be fixed.)
2. *Overlapping retrievals let an older settled snapshot become current via the sequence tail.* Position: real and confirmed natively. Recorded as **condition R-6** on any consumer of `currentSettled()` or of the observation tail. Recommendation: serialize retrievals per invoice (a job-level lock keyed on `invoice_ref_hash` that spans the provider reads and the append; a DB lock cannot span them because provider I/O is refused inside a transaction), or an append-time ordering rule with a named key: in the append transaction require the new row's provider-read start (`attemptedAt`, to be stored) to be at or after the tail's, keyed on `(invoice_id, retrieval_started_at)`, and record a late older snapshot as non-current instead of as the tail. Either way, add a native two-process regression like the reviewer's stale-race probe.
3. *`$tries = 1` on `RetrieveMembershipInvoice`.* Position: this is the acknowledged-webhook half of R-2 and stays open (A1-2). Retrying is safe (GET-only; each attempt appends at most one chained row; a first unknown writes nothing). Recommendation: bounded retries with backoff for `provider_unavailable` and `provider_inconsistent`, plus either a sweep over `retrieval_hint` rows with no covering observation or an operator reconciliation command, before root mounts intake with an async queue.

## Status of R-2 and R-3

- **R-2: partially closed.** Closed: a hint whose dispatch failed after commit is recovered by a provider redelivery, on both the early-duplicate branch and the concurrent-insert-loser branch (native intake race and SQLite redelivery tests); `invoice_payment.*` hints resolve through the owned identity. Carried: the acknowledged-webhook half (A1-2, `$tries = 1`, no sweep) and the coverage time base (A1-3). The condition now reads "R-2 (remaining)" below.
- **R-3: partially closed.** Closed: no identity row is created on a first retrieval refused for `account`, `customer` or `subscription`, or ending `unknown` or `provider_incomplete` (red/green, SQLite probes, native races). Carried: a first retrieval refused for `invoice_identity` or `mode` still claims the identity for an unvalidated binding (A1-1). The condition now reads "R-3 (remaining)" below.

## The seven Codex threads addressed in the delta

Taken from the lane README's four Codex rounds (`conditions/codex-*`). "Closed" means the thread's stated defect no longer reproduces at `e26d4cb7`, with red/green and the cited runs as evidence.

| # | Thread (commit) | Status | Evidence |
| --- | --- | --- | --- |
| 1 | R-3: identity claimed before the binding is validated (`9c2f9928`) | **Partially closed**: closed for `account`, `customer`, `subscription`; residual A1-1 (`mode`, `invoice_identity`) | red/green 3 F; SQLite `mode-first`; native races |
| 2 | R-2: duplicate delivery returns before scheduling, so a lost dispatch is permanent (`9c2f9928`) | **Closed** as raised (the duplicate branch re-dispatches); the acknowledged-webhook half is a separate remainder (A1-2) | red/green 4 F; lane redelivery tests |
| 3 | `invoice_payment.*` hints carry no subscription, so they never dispatch (`af089d40`) | **Closed** (resolved through the owned identity; none: retained, not dispatched) | round 2 red/green 3 F of 11 |
| 4 | Concurrent-insert loser returns success while the winner's dispatch may be lost (`af089d40`) | **Closed** | round 2 red/green; native intake race (both connections in lock wait; loser re-dispatches from line 92) |
| 5 | Events from another API version are stored but never scheduled (`4716ae52`) | **Closed** (refused before any row or dispatch) | round 3 red/green 1 F of 6 |
| 6 | Finding A: a first `unknown`/`provider_incomplete` retrieval pins the identity (`e26d4cb7`) | **Closed** | round 4 red/green (14 F of 36); native two-bindings-unknown race: invoices=0 |
| 7 | Finding C: an `unknown` (or incomplete) observation covers the hint (`e26d4cb7`) | **Closed** (only definitive observations cover) | round 4 red/green; SQLite `unknown-cover` probe: redelivery dispatched |

Six of the seven are closed as raised; thread 1 is partially closed (A1-1).

## Decision for the delta

**APPROVE WITH CONDITIONS**, development merge only, default-off and unregistered, on the same terms and exclusions as the decision above. The delta keeps every invariant verified at `c689ffdc`: no award, grant, reversal or subscription state from a signature or provider response; integer minor units; durable deduplication; GET-only pinned provider I/O; no registration. It improves R-2 and R-3 materially and introduces no regression in the lane directory on either driver.

Conditions after this addendum (R-1, R-4 and R-5 are unchanged and still apply):

- **R-2 (remaining), before root mounts webhook intake:** recover retrievals that fail after the webhook was acknowledged (bounded retries with backoff for unknown outcomes, and a sweep or operator command over uncovered `retrieval_hint` rows), and base coverage on the retrieval's start time (A1-3). Mount with an async queue.
- **R-3 (remaining), before C3/C2 consumes Billing evidence:** no identity claim on a first retrieval refused before the binding checks (`invoice_identity`, `mode`), with a regression (A1-1).
- **R-6, before any consumer of `currentSettled()` or the observation tail (paid-invoice authority, C3/C2, any award or activation writer):** per-invoice serialization of retrievals or an append-time read-order rule with a named key, plus a native two-process regression (judgement (d)-2).
- **A1-4, before C3/C2 consumes Billing evidence:** a durable non-identity attempt record, or an explicit root/Sean acceptance of hint + failed job + log as the evidence of record; reason code in the exception message.

## Not reviewed (Addendum 1)

- Real Stripe I/O, queue workers other than the test `database`/`sync` drivers, and webhook HTTP mounting (no route exists).
- Native runs of the other lane directories (`ProductionMembership`, `ProductionMemberOriginals`) at `e26d4cb7`; they are untouched by the delta.
- Performance (R-5 unchanged; the native directory's wall time is recorded above but is not a latency measurement).
- Full repository suite, frontend, Foundation CI.

## SHA-256 at `e26d4cb74d464943178201133612e73b16945cf1`

Each hash equals both `git show e26d4cb7:<path> | sha256sum` and the review worktree file.

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Memberships/Billing/BillingLedger.php` | `743ce28a527208ba57328b5e24d23055630327aa555475f42b067f03cedb8e9b` |
| `app/Domain/Memberships/Billing/BillingReconciliation.php` | `eae8fbc3ac365abcc6a606312e0f45170774a74992e0cac3fd641f44e8990fbb` |
| `app/Domain/Memberships/Billing/BillingWebhookIntake.php` | `fee44488c1bee38727fa2eae62b31d67529580c0a70155341483723a75238f12` |
| `tests/Feature/ProductionMembershipBilling/BillingObservationLedgerTest.php` | `15ebd5a657f0699a1e10bb84752ebb06cfb90582470d39872d4ba91dfd17ab72` |
| `tests/Feature/ProductionMembershipBilling/BillingWebhookIntakeTest.php` | `1446329b3a36ba0b915122d831b6940b05cb6a03c4876f3d4b30f230dfe6525e` |
| `tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php` | `df0ae7056102c17227320d590e7d2e34ea4b8ef0f1e7c9afb905b1857de57365` |
| `tests/Feature/ProductionMembershipBilling/BillingUnknownOutcomeTest.php` | `6927d50f993cd34e7fd0b5362b0770651dc90e9241110d86ae6e3b547d8fda92` |
| `tests/Feature/ProductionMembershipBilling/StripeSdkBillingGatewayTest.php` | `770bbcf7807dc3f12920e682ae96c238d07488ba156bb2417ba92e4a297d3ce8` |

Unchanged since `c689ffdc`: `app/Jobs/RetrieveMembershipInvoice.php` `b2f0078b…` and `BillingSettlement.php` `bf348785…` (same as the table above).

## Evidence index (`review-evidence/addendum1/`)

- `surface.txt`: frozen-surface and registration checks at `9c2f9928` (re-checked at `e26d4cb7` by the completing reviewer; results in "Scope").
- `sqlite/`: lane directory and reviewer probe runs at `9c2f9928`, `4716ae52` (`head-*`) and `e26d4cb7` (`final-*`).
- `probes/`: `Addendum1ProbeTest.php` and the three race workers (current revision, used for every `e26d4cb7` run), and the first SQLite probe run.
- `red-green/`: the `9c2f9928` red/green; `rounds/` the per-round red/green for `af089d40`, `4716ae52`, `e26d4cb7` and the script.
- `native/`: the private-instance lifecycle for both instances, the first runner and the discarded `9c2f9928` run.
- `native-head/`: `runner-head.sh`, the ledger, the chain scripts and their logs, the superseded `4716ae52` and earlier `e26d4cb7` runs (retained, not cited), and the cited `final2-probes-native-races.*` and `final3-ProductionMembershipBilling.*`.
- `pint-e26d4cb7.txt`.

## Cleanup

- Private instance: `mysqladmin shutdown` exit 0 at 06:17:41Z; pid 6342 exited; no LISTEN on 3531; no `mysqld` process referenced the datadir; `$scratchpad/review-member-mysql` (248M) removed (exit 0). The first instance's datadir (229M) had already been removed at 03:05:55Z after confirming pid 25136 was gone. Other lanes' `mysqld` instances (trigscan-fix, free256, supp254-a27, review-tax255) and the shared :3306 server were not touched. Full log: `native/private-instance-lifecycle.txt`.
- Scratch worktrees: `review-member-redgreen` (clean, at `e26d4cb7`) removed with `git worktree remove`; `review-member-head` (at `e26d4cb7`; its only untracked content was a byte-identical copy of `addendum1/probes/` scripts) removed with `git worktree remove --force`. The review worktree `/home/user/VA-Studio-review-member` and the base worktree are kept.
- `git status` in the review worktree shows only `independent-review/` changes (this `DECISION.md` and the untracked `review-evidence/addendum1/`). Nothing was committed or pushed.
