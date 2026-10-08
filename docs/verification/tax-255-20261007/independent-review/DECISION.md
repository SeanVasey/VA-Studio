# Independent review: Tax255 provider-calculated tax checkout (plan step A3)

- Reviewed SHA: `9ec94d8c9838e92311cb60f24fffdc0ec9af64de` (`origin/harness/tax-255`).
- Code head: `ae8c4c86`. `21c2d046` merges `origin/main` at `fad3ab44` with no conflict, and `9ec94d8c` changes docs only.
- Base for the diff: `origin/main` `fad3ab444e89ffb57dc915f61f7f788b626e330a` (unchanged while I worked). `git diff --name-only fad3ab44..9ec94d8c` lists only the 31 owned PHP files and `docs/verification/tax-255-20261007/`.
- Reviewer: independent reviewer agent. It authored no lane commit. It changed no app code, flag or registration in the review worktree, and it made no commit. The integration owner commits this record.
- Date: 2026-10-08 (UTC).

## Environment

- Review worktree `/home/user/VA-Studio-review-tax255`, made with `scripts/dev/mkworktree.sh` at `9ec94d8c`. The main checkout's locked `vendor/` is symlinked, and the worktree has its own Composer autoload. `public/build` is absent.
- Three auxiliary detached worktrees, made the same way and removed at the end (see "Cleanup"):
  - `-aux` at `9ec94d8c`, for mutations, so that a mutated tree never ran while a native run of the review worktree was loading classes;
  - `-main` at `fad3ab44` and `-base` at `73c898db`, for question 5.
- PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory (`phpunit.xml`), Pint from the locked vendor tree.
- Native: a private `mysqld` 8.4.11 started with `--no-defaults` on 127.0.0.1:3567. 3567 was free by `/proc/net/tcp`; listeners at the time were 2024, 2025, 3531, 3541, 3555, 37277 and 41441. The instance used `--socket=` (empty, TCP only), `--mysqlx=OFF`, and a datadir in `$scratchpad/review-tax255-mysql/`. One schema per class, created fresh by the runner, with every other `review_tax255_*` schema dropped first (see "Environment interruptions"). The shared :3306 server and other lanes' instances (3531, 3541, 3555) were not touched. Lifecycle: `review-evidence/native/private-instance-lifecycle.txt`.
- Native environment, as the lane README documents: `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3567 DB_DATABASE=<schema> DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. `ci-only-password` is a throwaway password on a loopback-only instance that has since been destroyed.
- Every account, origin, amount and tax figure is synthetic: `acct_SYNTHETIC`, `https://review.invalid`, 4999 minor units, and 437 or 337 tax per line. No provider was called.

## Decision

**APPROVE WITH CONDITIONS.** This approves a **development merge only** of a default-off, unregistered and unmounted family.

The invariants I was asked to test hold on `9ec94d8c`:

- No client total or tax figure reaches any record. Every HTTP body is checked against an exact key set, `items` carry only ids, and amounts come from the approved candidate, the selection and the provider.
- Money is integer minor units with an explicit `USD`/`usd` currency on the order, the request, every line item, the session, the PaymentIntent and every DB row.
- A buyer-reviewed session is retained only when all of these hold:
  - the session is `complete` and `paid`, with `automatic_tax.status = complete`;
  - the PaymentIntent has `succeeded` with `amount = amount_received = session total` and `amount_capturable = 0`, in `usd`, test mode, with this request's metadata and no connected-account fields;
  - the session subtotal equals the retained request;
  - the line, total and ceiling arithmetic holds for the single retained `tax_behavior`.
- A retained review is never replaced. Later provider reads are not even made.
- Every entry point refuses when off, outside local/testing, unbound or pinned differently.
- Nothing is registered on `main`.
- The schema guards and installer refuse on both drivers. One divergence is noted as R-3.
- The nine frozen V1 files are byte-identical to the composition record on this head and on `origin/main`.
- The 17 adjacent failures are pre-existing and unrelated to migration 255000.

One property is narrower than its name suggests (R-1). `ProductionTaxPaidLineAdapterV2::accept` is a consistency check, not authentication. A forged line, resealed with a consistent self-hash, is accepted, including a `verified_production`/`live` line that no producer in this lane can mint. Nothing consumes the adapter today, so there is no current exposure. It must be closed before any paid-family wiring.

This decision does **not** approve:

- activation (`enabled`, `provider`, `funds_mode`, `account_id`, `return_origin`);
- registration of `ProductionTaxCheckoutServiceProvider`, the privacy middleware, the routes or the response sanitization;
- any real transport, Stripe call or test charge;
- live funds;
- wiring the adapter or SourceV2 into Paid252 or any grant path;
- any policy, merchant or tax fact: tax behaviour, `maximum_rate_bps`, registrations, tax codes, account, return origin or currency.

The lane's own release blockers stand unchanged: a V2 `CheckoutCommitAdmission`, an A4 transport, unknown-outcome retention, and the missing MySQL race proof.

## Findings

| ID | Severity | Finding | Recommendation |
| --- | --- | --- | --- |
| R-1 | Medium (forward; no current consumer) | `ProductionTaxPaidLineAdapterV2::accept(array $line, string $provenance)` checks shape, self-hash, sub-hashes, arithmetic, execution-context agreement and the ceiling. `source_hash` is an unkeyed hash of the line, so anyone holding an array can edit it and reseal it. The probe accepted two such lines. The first moves a genuine line to a nonexistent order with **zero tax**. The second is a `verified_production` / `live` / `own_account_sdk` line with `cs_live_FORGED`, which no producer here can mint. The adapter also has no policy or environment gate. Authenticity exists only on the `ProductionTaxPaidOrderSourceV2::lockedRead()->line()` path: rows sealed with the APP_KEY, the held transaction, the T23 prelock and `proveRetainedCurrent`. The adapter cannot tell whether its input came from there. (`review-evidence/probes/money-probe-sqlite.*`, `test_q1_forged_lines_with_a_consistent_self_hash_are_accepted_by_the_adapter`.) | Before any Paid252/V2 wiring, bind acceptance to the held source. Either make `accept` take the `ProductionTaxPaidOrderSourceV2` and a position and require `Evidence::same($source->line($p), $line)` under the same held frame, or key `source_hash` (HMAC with the app key and a V2 domain tag). Refuse `verified_production`/`live` until a reviewed live producer exists. Add the two forgeries as regression cases. |
| R-2 | Low | Test gap in the money path. Four of my mutations survive the lane's suite: R1 (drop `amount_received === total`), R2 (drop PaymentIntent `amount === total`), R3 (drop PaymentIntent currency) and R4 (drop session currency). In each case all five affected lane classes stay green, and my money probe goes red. No lane test covers a PaymentIntent mismatch. (`review-evidence/mutations/mutation-ledger.txt`, `R1..R4.*`.) | Port the PaymentIntent and currency cases of `TaxReviewMoneyProbeTest::refusedProviderFacts` into `ProductionTaxCheckoutJourneyTest` before activation or A4. |
| R-3 | Low (defense in depth) | On MySQL the provider-identifier guards (`acct_`, `cs_<mode>_`, `pi_`) use `REGEXP '^...$'` with no length bound. ICU `$` also matches before a final line terminator. Natively, `account_id = "acct_SYNTHETIC\n"`, `provider_session_id = "cs_test_SYNTHETICTAX\n"` and `provider_payment_id = "pi_SYNTHETICTAX\n"` are all **accepted** by the triggers. SQLite refuses all three. The runtime regexes (`\z/D`) prevent the application from writing such values, so this is a driver divergence in the second line of defence, not a reachable bypass. (`review-evidence/native/native-mysql84-TaxReviewGuardAnchorProbeTest.txt`, `probes/guard-anchor-probe-sqlite.txt`.) | Anchor with `\\z` or add `CHAR_LENGTH`/`NOT LIKE '%\n%'` bounds in `providerId()` and `sessionId()`, and add the three cases to the Guard test. Check whether sibling families share the pattern. |
| R-4 | Low | Test gap on MySQL. By design, `ProductionTaxCheckoutMigrationTest` skips 8 of its 10 cases on MySQL: the install/rerun case and all seven installer refusals. A complete native run therefore proves only the driver-independent text checks and `down()`. The MySQL dictionary branches (`guard definition`, `indexes`, `columns`, `foreign key consumer`, `foreign or opaque routine reference`, `additional owned guard`, temporary shadow, foreign view and trigger) had no native test in the lane. I exercised 11 of them natively, plus the frozen V1 installer rerun and `down()`, and all refused before DDL with the dictionary unchanged (`native-mysql84-TaxReviewNativeInstallerProbeTest.*`, OK 12/29). | Add a native installer refusal class (the probe can be ported) to the suite and the native-only census. |
| R-5 | Low | `initiate()` validates the retrieved session against the retained request (metadata, client reference, amounts, expiry and URL), but not that `session.id` equals the locator `create` returned. It then binds the created id and returns the **retrieved** session's `checkoutUrl`. The probe returned `cs_test_OTHERSESSION`'s URL while binding `cs_test_SYNTHETICTAX`. `retain()` later refuses a paid session whose id differs from the binding (409, nothing retained), so no money is mis-recorded. A real provider would also have to return another session carrying this request's metadata. (`test_q1_initiate_does_not_compare_the_retrieved_session_id_with_the_created_locator`.) | Require `$safe['session_id'] === $sessionId` in `initiate` and in `reconcile` before `financial()`. |
| I-1 | Info | Mutation R8 (remove the `previewHash` comparison in `order()`) survives because `TaxCheckoutEvidence::order()` re-derives the commitment on read-back and refuses with the same reason `changed`. Mutation R13 (remove the session `client_reference_id` check) survives because the exact metadata comparison carries `order_id`. Both are redundancy, not gaps. | None. |
| I-2 | Info | `bind()` and `retain()` do not re-check `TaxCheckoutPolicy::requireEnabled()` after provider I/O. Turning the policy off between the entry check and the write still lets that one write land. | Optional: re-check inside the write frames. |
| I-3 | Info | Session and PaymentIntent metadata send `buyer_origin_id` (an opaque identity UUID) and the order and request ids to Stripe. | Confirm this is acceptable for the privacy notice before activation. |
| I-4 | Info | The SourceV2 locator and reader do not consult `enabled`: a disabled flag still reads in testing. They do refuse outside local/testing, because the retained execution context re-admits test funds only there (`unsupported`, 409). The adapter has no gate at all (folded into R-1). (`default-off-probe-sqlite.*`.) | Covered by R-1. |
| I-5 | Info | Confirms the lane's own blockers. The V2 writes run in V1 `CommandTransaction` frames without a `CheckoutCommitAdmission` observer. The three native-only methods are not in `scripts/ci/database-sqlite-skips.json`. No MySQL race proof exists for the unique keys. | Lane README "What root must do" items 2 to 4. |

No finding is High. R-1 is Medium because it concerns the authenticity of a money-bearing line. It has no consumer at this commit.

## Verified claims (with evidence)

### Question 1: binding, retention and accepted lines

- **Client totals.** `ProductionTaxCheckoutController` checks every body with `Evidence::keys` against an exact key set. Removing that check (R14) turns `HttpBoundary` red. `PreparationSelection::items` admits only four integer ids per item, and the lane's HTTP test refuses `totalMinor`, `taxMinor` and `amounts`. Amounts come only from `CurrentSelection` and the provider.
- **Tampered `previewHash`.** `order()` recomputes the commitment from current state and refuses `changed`, and `TaxCheckoutEvidence::order()` re-verifies it on every read (I-1).
- **Replayed `retrieve`.** Once a review is retained, `reconcile`, `initiate` and `status` return the retained amounts and make **no** provider call. A later provider read with different tax changes nothing (`test_q1_retained_review_is_never_replaced_by_a_later_retrieve`). The unique keys (`request_id`, `binding_id`, `order_id`, `(account, mode, payment)` and `(account, mode, session)`) and `intent()` allow at most one row.
- **`automatic_tax.status` not complete.**
  - A paid session with `failed`, null or `requires_location_inputs` is refused and nothing is retained.
  - Removing both completeness checks (R6) turns Journey red.
  - An open session may be `requires_location_inputs`; nothing is retained then.
- **PaymentIntent not `succeeded`.**
  - `processing` or `requires_capture` with a paid session is refused.
  - `processing` with a complete but unpaid session gives `pending`, and nothing is retained.
  - An `expired` session retains nothing.
- **Amount mismatches.** Each of these is refused and nothing is retained:
  - PaymentIntent `amount` differs from the session total;
  - `amount_received` is short;
  - the session subtotal differs from the request (lane case);
  - the line tax sum differs from `total_details.amount_tax` (lane case);
  - a line `unit_amount` is raised;
  - the line tax is negative;
  - the tax figure is a string;
  - `on_behalf_of` is set;
  - the PaymentIntent metadata names another request;
  - the session `livemode` or PaymentIntent `livemode` is true.
- **Inclusive and exclusive cannot be mixed.**
  - The behaviour comes only from the approved machine policy, retained in the order's execution context. The request sends that single value on every `price_data`.
  - Every session line must report it, with `line total = amount + tax` (exclusive) or `= amount` with `tax <= amount` (inclusive). The session total must follow the same rule.
  - The `reviewed` trigger requires `r.tax_behavior = NEW.tax_behavior` with the matching arithmetic.
  - An exclusive request with inclusive-shaped totals is refused. Removing the per-line behaviour check (R7) turns Journey red.
- **Ceiling.**
  - `maximum_rate_bps` is retained on the request row and in its execution context. It is enforced against `net = subtotal` (exclusive) or `subtotal - tax` (inclusive) when an open session is read at `initiate` (`test_q1_ceiling_bound_at_initiate_too`, 409, no binding) and when a paid session is reviewed.
  - The `reviewed` trigger enforces it independently against `r.maximum_rate_bps`.
  - Inclusive at 800 bps refuses 437 inside 4999 and accepts 337.
  - Removing the runtime check (R5) turns Journey red, because the DB guard then refuses with a raw error.
  - The adapter enforces it too; removing that check (R10) turns the adapter test red.
- **Currency.** It is explicit everywhere (see the decision). A session, line or PaymentIntent in `eur` is refused. Because the request is fixed at `usd`, "differs from the retained request" and "is not `usd`" are the same test (but see R-2 for test coverage).
- **Forged line.** See R-1. A line that is edited but not resealed is refused (`source_hash`), and removing that check (R11) turns the adapter test red.

### Question 2: default-off

- **Service.** `preview`, `order`, `initiate`, `reconcile` and `status` each refuse:
  - with `disabled/503` when `enabled` is `false`, `1` or `'true'`, or when the environment is `production` or `staging`;
  - with `unsupported/503` on SDK or API pin drift.
  - In each case no row is written and no transport call is made (`test_q2_every_service_entry_point_refuses_...`).
- **Transport admission.** These eight cases all give `provider_unbound` with zero calls: `null`, `''`, `'unbound'` with the Unbound transport, `'unbound'` with a real transport, the right name with the Unbound transport, no transport, the wrong case, and an array. Removing admission (R9) turns Journey and HttpBoundary red.
- **Provider.** Registered, it binds only `UnboundTaxCheckoutTransport`, and the container-resolved service refuses it.
- **Controller and middleware.** Both answer 503 when off (and the middleware also in `production`), with zero DB queries. Removing the environment clause of `enabled()` (R12) turns Policy red.
- **Registration.** `bootstrap/providers.php`, `bootstrap/app.php`, `routes/web.php`, `routes/console.php` and `config/app.php` have no reference, on the head or on `origin/main`. `route:list --path=tax-checkout` has no routes, and no Tax provider is loaded at runtime.
- **Configuration.** `config/production-tax-checkout.php` has no `env()`, `getenv`, `$_ENV` or `$_SERVER`. Neither has any owned runtime file, and `.env.example` has no tax entry (`review-evidence/registration-census.txt`).

### Question 3: schema

- **Native (MySQL 8.4.11, this review).**
  - `Guard` 6/118, `NativeSchema` 3/22 and `Migration` 10/191 (8 skipped by design, see R-4), each exit 0.
  - My installer probe, 12/29, exit 0: 11 dictionary refusals before DDL with an unchanged dictionary, the frozen V1 installer rerun, the Tax255 rerun and `assertComplete`, `down()` refusal, and all four tables empty after migrate.
  - **`ProductionTaxCheckoutMigrationTest` now completes natively.**
  - Journey 15/109, SourceV2 3/42, PaidLineAdapterV2 19/24, FrozenV1 2/27 and Policy 43/147 also pass natively, each exit 0. HttpBoundary is SQLite only.
- **Guards.** Both drivers refuse malformed, out-of-order, UPDATE and DELETE rows (lane Guard class on both drivers), except the R-3 trailing-LF divergence on MySQL.
- **`down()`.** It refuses before any query (lane test on both drivers, and my native probe).
- **Inert while off.** All four tables are empty after `migrate` (native probe). No code path writes while off (question 2).

### Question 4: frozen V1

- All nine files have the same SHA-256 in the composition record, the `9ec94d8c` blob, the review worktree and `origin/main` `fad3ab44`.
- `git diff --name-only fad3ab44 9ec94d8c -- app/Domain/Commerce/ProductionCheckout database/migrations/2026_10_07_246000_production_checkout.php` is empty: no V1 file differs at all (`review-evidence/frozen-v1-sha256.txt`).
- **Reachability.** The V2 family calls only these V1 symbols:
  - `CheckoutException`;
  - `CommandTransaction::run`;
  - `CurrentSelection::{load, proveBytes, proveCurrent}`;
  - `Evidence::{hash, key, keys, open, same, seal}`;
  - `HeldSourceTransaction::capture`;
  - `PrimaryBoundary::prove`;
  - `OwnAccountStripeGateway::sessionId`;
  - `ProductionCheckout::requireOwner`;
  - `Records` (`commandFrame`, `current`);
  - the constant `ExecutionContextV1::API_VERSION` (pin comparison);
  - `CheckoutSchema::TABLES` (only in the `referencesV1` test seam).
- It references no V1 paid source, locator, receipt, admission, `HostedCheckout`, `OrderEvidence`, `HostedEvidence`, `production_checkout_*` table or `PaidGrant*` (`review-evidence/v1-reachability.txt`). This matches the documented seam reuse. `ExecutionContextV1`, `Records` and `CheckoutException` are not in the README's list, but they are trivial.
- The lane test `test_unpaid_orders_have_no_source_and_the_v1_locator_never_reads_a_v2_order` passes on SQLite and natively (SourceV2 3/42, exit 0).

### Question 5: the 17 adjacent failures

`DiscoveryEpochMigrationTest`, `ProductionTrackCapabilitiesMigrationOwnershipTest` and `RightsEvidenceGuardMigrationTest` (65 tests) were run on SQLite in three worktrees. All three exit 2, each with 15 errors and 2 failures, and the **same 17 test ids** as the lane's baseline:

| Worktree | Commit | Contains 255000 |
| --- | --- | --- |
| head | `9ec94d8c` | yes |
| `origin/main` | `fad3ab44` | no |
| base | `73c898db` | no |

The messages are identical:

- 6 × `Unexpected production capability external foreign key reference`;
- 9 × `Unexpected rights evidence additional trigger for tracks`;
- 2 `DiscoveryEpochMigrationTest` rollback assertion failures.

The lane's claim holds, and it also holds on current `main`: these failures are pre-existing and independent of Tax255 (`review-evidence/adjacent/adjacent3-comparison.txt`). I did not diagnose them.

## Conditions

1. **R-1, before any Paid252 or other consumer wiring of V2 lines.** Bind adapter acceptance to the held `ProductionTaxPaidOrderSourceV2` (or key the hash), refuse `verified_production`/`live` until a reviewed live producer exists, and add the two forgery regressions.
2. **R-2, before activation or A4.** Add PaymentIntent amount, `amount_received`, currency, status and metadata cases, and session/line currency cases, to the lane suite.
3. **R-3 and R-4, before Foundation final verification of this family.** Fix the MySQL anchoring in `providerId()` and `sessionId()`. Add a native installer refusal class and the trailing-LF guard cases, and register the native-only methods in the census.
4. **R-5, before A4 binds a real transport.** Compare the retrieved session id with the locator in `initiate` and `reconcile`.
5. The lane's own blockers are carried forward unchanged. Root mounts, registers and censuses as its README says. Before activation: a V2 `CheckoutCommitAdmission`, a reviewed A4 own-account transport, unknown-outcome retention, paid-family wiring, a MySQL race proof, Foundation CI on the exact integrated SHA, and Sean's merchant and tax facts with explicit authorization.

## Not reviewed

- Any real Stripe behaviour (`automatic_tax` transitions, rounding, `liability`, expansions). Only the synthetic recording transport and my wrapper ran.
- MySQL concurrency of the unique keys (two concurrent `initiate`/`reconcile`).
- Mounted HTTP behaviour beyond the lane's `HttpBoundary` (CSRF, sessions, throttles under the real kernel).
- The full repository suite, frontend and Foundation CI. Only the owned directory, the three adjacent classes, my probes and the mutation selections ran.
- The diagnosis of the 17 pre-existing adjacent failures.
- Native runs of `TaxReviewMoneyProbeTest`, `TaxReviewDefaultOffProbeTest` and `ProductionTaxCheckoutHttpBoundaryTest` (SQLite only). The two probes exercise driver-independent PHP logic.
- Performance.

## Commands and results

From `/home/user/VA-Studio-review-tax255` unless stated, with `$P = php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit <file>`. Every exit code below is PHPUnit's own `$?`, captured immediately after the command (`echo $? > *.exit`; the native runner sets `rc=$?` on the next line).

SQLite:

| Selection | Exit | Result | Receipt |
| --- | --- | --- | --- |
| `$P tests/Feature/ProductionTaxCheckout` | 0 | 118 tests, 948 assertions, 3 skipped (native-only) | `owned-sqlite.*` |
| `$P probes/TaxReviewMoneyProbeTest.php` | 0 | 25 tests, 103 assertions | `probes/money-probe-sqlite.*` |
| `$P probes/TaxReviewDefaultOffProbeTest.php` | 0 | 5 tests, 30 assertions | `probes/default-off-probe-sqlite.*` |
| `$P probes/TaxReviewGuardAnchorProbeTest.php` | 0 | 1 test, 4 assertions (all three LF ids refused) | `probes/guard-anchor-probe-sqlite.*` |
| adjacent 3 classes, `-aux` (`9ec94d8c`) | 2 | 65 tests, 15 errors, 2 failures | `adjacent/adjacent3-aux.*` |
| adjacent 3 classes, `-main` (`fad3ab44`) | 2 | 65 tests, 15 errors, 2 failures | `adjacent/adjacent3-main.*` |
| adjacent 3 classes, `-base` (`73c898db`), repeated run | 2 | 65 tests, 15 errors, 2 failures | `adjacent/adjacent3-base.*` |
| `vendor/bin/pint --test <31 owned files>` | 0 | `passed` | `pint.*` |

Probe runs that were corrected (retained in the history, not cited as results):

- The money probe's first run failed 1 of 25 because of a probe artifact: my mutated completed session kept a URL. It was fixed and the run repeated.
- The default-off probe's first run errored 1 of 5 on a wrong expectation of mine: the SourceV2 read refuses in `production`. It was rewritten as I-4 and the run repeated.
- The first native anchor-probe run errored from an arrow-function capture bug in my probe (`native/anchor-probe-run1-probe-bug/`). Its duplicate-key error itself showed the LF `account_id` row had been stored. It was fixed and the run repeated.

Native MySQL 8.4.11 (counts from each JUnit top-level suite; times are JUnit seconds):

| Selection | Exit | Tests | Assertions | Failures | Errors | Skipped | Time |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `ProductionTaxCheckoutGuardTest` | 0 | 6 | 118 | 0 | 0 | 0 | 704.6 |
| `ProductionTaxCheckoutNativeSchemaTest` | 0 | 3 | 22 | 0 | 0 | 0 | 568.4 |
| `ProductionTaxCheckoutMigrationTest` | 0 | 10 | 191 | 0 | 0 | 8 (by design) | 1363.4 |
| `ProductionTaxCheckoutPolicyTest` | 0 | 43 | 147 | 0 | 0 | 0 | 1.3 (no DB migration) |
| `probes/TaxReviewNativeInstallerProbeTest` | 0 | 12 | 29 | 0 | 0 | 0 | 2151.0 |
| `probes/TaxReviewGuardAnchorProbeTest` (run 2; all three LF ids **accepted**) | 0 | 1 | 4 | 0 | 0 | 0 | 214.2 |
| `ProductionTaxCheckoutJourneyTest` | 0 | 15 | 109 | 0 | 0 | 0 | 3058.2 |
| `ProductionTaxSourceV2Test` | 0 | 3 | 42 | 0 | 0 | 0 | 502.8 |
| `ProductionTaxPaidLineAdapterV2Test` | 0 | 19 | 24 | 0 | 0 | 0 | 0.5 (no DB) |
| `ProductionTaxCheckoutFrozenV1Test` | 0 | 2 | 27 | 0 | 0 | 0 | 0.0 (no DB) |
| **Total** | | **114** | **713** | **0** | **0** | **8** | |

`ProductionTaxCheckoutHttpBoundaryTest` was not run natively. I stopped it 3 seconds after it started (pid 13829) to stay inside the 2-hour background limit. It was not requested, and its partial receipt is under `native/httpboundary-stopped-by-reviewer/`. It is cited on SQLite only.

Mutations: 14 mutations of owned runtime files in `-aux` at `9ec94d8c` (`mutations/mutations.py`, `mutations/run.py`). Each was applied by exact single-occurrence replacement and then reverted with `git checkout -- app database`, with `git diff --quiet -- app database` exit 0 recorded after **every** one (`mutations/mutation-ledger.txt`). The selection was Journey, SourceV2, PaidLineAdapterV2, Policy and HttpBoundary, plus the money probe where relevant.

| ID | Mutation | Lane suite | Money probe |
| --- | --- | --- | --- |
| R1 | drop `amount_received === total` | green (gap, R-2) | red |
| R2 | drop PaymentIntent `amount === total` | green (gap, R-2) | red |
| R3 | drop PaymentIntent currency | green (gap, R-2) | red |
| R4 | drop session currency | green (gap, R-2) | red |
| R5 | drop session ceiling | Journey red | n/a |
| R6 | drop both `automatic_tax` completeness checks | Journey red | n/a |
| R7 | drop line `tax_behavior` check | Journey red | n/a |
| R8 | drop `previewHash` comparison | green (redundant read-back, I-1) | n/a |
| R9 | bypass transport admission | Journey and HttpBoundary red | n/a |
| R10 | drop adapter ceiling | adapter red | n/a |
| R11 | drop adapter `source_hash` | adapter red | n/a |
| R12 | drop environment clause of `enabled()` | Policy red | n/a |
| R13 | drop session `client_reference_id` | green (redundant metadata, I-1) | red |
| R14 | drop controller exact keys | HttpBoundary red | n/a |

R1's first run lost its Journey result to an external SIGTERM (exit −15). Those receipts are retained under `mutations/R1-first-run-interrupted/`, and R1 was repeated in full.

## Environment interruptions (retained, not cited)

- **03:20:23Z.** I stopped my own first native Guard run (pid 28941) to split the queue under the 2-hour background limit. My `pkill -f review-tax255-native.sh` matched and ended my own shell; it killed nothing else. Recorded in `native/run-ledger.txt`.
- **03:31:00Z.** An external SIGTERM ended my native Guard run (exit 143, 4 of 6 passed) and my `-base` adjacent run (exit 144). R1's Journey run ended the same way soon after. The coordinator reported that another lane ran `pkill -f vendor/phpunit/phpunit/phpunit` at about 03:20–03:30Z. All of these runs were repeated. The receipts are kept under `native/interrupted-external-sigterm/` and `adjacent/interrupted-external-sigterm/`.
- **03:31–03:35Z.** The killed Guard run left schema `review_tax255_guard` populated. `CapabilityMigrationOwnership` scans `information_schema.TRIGGERS` across all schemas, so the next NativeSchema (3 errors) and Migration (10 errors) runs refused at migrate, before any Tax255 code ran. Receipts are in `native/env-error-leftover-schema/`. The runner now drops every other `review_tax255_*` schema before each class (`native/runner.sh`, the hardened version; the first runs used the same script without that line and without probe-path support). Both classes were repeated and passed.

## SHA-256 of every owned PHP file at `9ec94d8c`

All 31 equal the lane's `evidence/owned-source-sha256.txt` (compared programmatically; 0 differ). Source: `review-evidence/owned-source-sha256-9ec94d8c.txt`.

| Path | SHA-256 |
| --- | --- |
| `app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxCheckout.php` | `0b1fda86459acd38d4a58ba772860ca6f2e1e25e5d53d55db6981327cd8a3f1b` |
| `app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxPaidLineAdapterV2.php` | `3d2dd5f0d1079ed73a7771b5ed413b9305b8d576e6959e3c8d227c53d30934ca` |
| `app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxPaidOrderLocatorV2.php` | `b21e50a9ac8cc387b3f41ff9f300ce0430cd9ed7506727ad68e6565fcc0cf55f` |
| `app/Domain/Commerce/ProductionTaxCheckout/ProductionTaxPaidOrderSourceV2.php` | `d53bc9eea6a68e45741c36516bcaa61d7fafed69440ba4596ea016942acd4cb2` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCandidate.php` | `0c217d7a6905bde635f66e4d19656864ffbb9c81689ad7302816240ba69d8dac` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutEvidence.php` | `9af0d97c8464751a3cef6ea9ec36973b7da0560e507b477a652a4adbfc968e54` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutPolicy.php` | `2e32f6f11adce0832c3a5af5fcbebdde62641b86ef86bc58736d7fa64c436433` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutRecords.php` | `c7f479dce48e3ac9018f5eceededb394caf92128ff65bf0ac3fa98a99cad3d1a` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutSchema.php` | `d949f0b64efd4a72ac9ff136f3536dfd2c0273c423884d3f524f3d29f767f306` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutSchemaInstaller.php` | `ee8bf7174bf61b93ef784f6bbf98ba2e89d64518f56ec98e74260d6b75b340c9` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutTransport.php` | `71f0fa7b91c92b160749341755b857f7947aed2a2fca10a4178f46bab84a9cad` |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxExecutionContext.php` | `d3ce349993d4d3af0d577cd8600328c9db1ccaf080888760c346a53684c52636` |
| `app/Domain/Commerce/ProductionTaxCheckout/UnboundTaxCheckoutTransport.php` | `efc8420e46c7869ef42dc7213be8c6229ae78b7377e5216ca35924225c847cfc` |
| `app/Http/Controllers/ProductionTaxCheckoutController.php` | `8d6074808e985a7847142546e860d2a8556c6efe84f902d521996449e03c351e` |
| `app/Http/Middleware/ProductionTaxCheckoutPrivacy.php` | `bb1ee428dce1165d14b299884a2a833eb7f520914ea4a4e563f165e338188007` |
| `app/Http/Responses/ProductionTaxCheckoutResponse.php` | `5fb1fe41a4262e11cf23d516516ff38ac21e378b575c5ceaec890b1d4dd27128` |
| `app/Providers/ProductionTaxCheckoutServiceProvider.php` | `f04b7b883e0ff869a43785f5ce994d7f12df593efbf9ee528d7cd64f2fa8dd15` |
| `config/production-tax-checkout.php` | `4018f8c08cfd8813acb8784e1aaab3a67695fd2067f3404d300b6e0a8cbd9d2b` |
| `database/migrations/2026_10_07_255000_production_tax_checkout.php` | `befc6e9ff7c67de3566fc5b56747d5936ad3e95670a9eee0acb89c3ab6ab00e8` |
| `routes/production-tax-checkout.php` | `5d0569533a3d1e59f99c727130fe8c65c2fcd50f287a82bc52bd2f3180131a50` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutFrozenV1Test.php` | `c90bf16a1b4bab2c950f6160dd51bc072c059c078c76db00094de2d645f2df45` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutGuardTest.php` | `f5f5b346715987c30eea49fed53d82a8cd8cded0c86d7c7f4c527df9fbf82449` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutHttpBoundaryTest.php` | `44c8fe6fc63861cdfe60ebfb5c77abccd1a94de79c958cddbbef6159ae6287ed` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutJourneyTest.php` | `036d949b7a15f1633fad168144a7dafe1aba7c42058820569ff441d69091747f` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutMigrationTest.php` | `3b96b0d09ff8a6e287f65c1f6bc99291d68da6fdf7636cf1032523e690a9fdf0` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutNativeSchemaTest.php` | `6568b2d9772c948a7215840dd184f4cb1741656e94e21937c71f426fcb1ad4ca` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutPolicyTest.php` | `29e845b8e5e557c86d3e2c70032ed72ee58497e3432b7b894cdbd1de27744232` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxPaidLineAdapterV2Test.php` | `8cbf2ee5d7936bf11e0ca2c326de2d5815dfd5a5863e25c4899e23eda51ae810` |
| `tests/Feature/ProductionTaxCheckout/ProductionTaxSourceV2Test.php` | `ca6e715ea2f57d5a134c0146ad54b8d6e39ee5bb824ad4cc45403976c177b7b8` |
| `tests/Support/ProductionTaxCheckoutFixtures.php` | `bce1f19d183e46fa85cd791a48d41bc7a93825878cea2f40252b7eee4c8a83bc` |
| `tests/Support/RecordingTaxCheckoutTransport.php` | `6476383721173a66e7991eeaa3747a0e5c30e5e14adec9c2e3b3438d58c44a1a` |

## Evidence index (`independent-review/review-evidence/`)

- `reviewed-commit.txt`, `owned-files.txt` and `owned-source-sha256-9ec94d8c.txt`.
- `owned-sqlite.{txt,junit.xml,exit}` and `pint.{txt,exit}`.
- `frozen-v1-sha256.txt`, `v1-reachability.txt` and `registration-census.txt`.
- `probes/`:
  - the four probe sources: `TaxReviewMoneyProbeTest`, `TaxReviewDefaultOffProbeTest`, `TaxReviewGuardAnchorProbeTest` and `TaxReviewNativeInstallerProbeTest`;
  - their SQLite receipts.
- `native/`:
  - `private-instance-lifecycle.txt`, `runner.sh` and `run-ledger.txt`;
  - `native-mysql84-<class>.{txt,junit.xml,exit}`;
  - `interrupted-external-sigterm/`, `env-error-leftover-schema/` and `anchor-probe-run1-probe-bug/`.
- `adjacent/`: `adjacent3-{aux,main,base}.{txt,junit.xml,exit,commit,has255000}`, `adjacent3-comparison.txt` and `interrupted-external-sigterm/`.
- `mutations/`: `mutations.py`, `run.py`, `mutation-ledger.txt`, `R*.diff`, per-class JUnit, `R*.txt` and `R1-first-run-interrupted/`.

## Cleanup

- The private `mysqld` (pid 28307, 127.0.0.1:3567) was shut down with `mysqladmin shutdown` (exit 0) at 06:07:26Z. Its `err.log` ends `MySQL Server - end`. Afterwards `/proc/28307` was absent and there were 0 LISTEN sockets on 3567 (`/proc/net/tcp`).
- The datadir `$scratchpad/review-tax255-mysql/` (256M) was deleted, and `exists after=no` was recorded. Other lanes' `mysqld` processes and the shared 3306 server were not touched; `pgrep -x mysqld` afterwards lists only 823 and 6342. Details: `review-evidence/native/private-instance-lifecycle.txt`.
- The auxiliary worktrees `/home/user/VA-Studio-review-tax255-{aux,main,base}` were removed with `git worktree remove --force` after confirming 0 tracked changes in each (`--force` only because of the untracked `vendor/` symlinks and `.env`). `-aux` passed `git diff --quiet -- app database` after the last mutation.
- The review worktree `/home/user/VA-Studio-review-tax255` stays at `9ec94d8c`. `git status --short` shows only `?? docs/verification/tax-255-20261007/independent-review/`. Nothing was committed or pushed.
- The scratchpad runner and mutation scripts remain in the session scratchpad. Copies are in `review-evidence/native/runner.sh` and `review-evidence/mutations/`.
