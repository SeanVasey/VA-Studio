# Tax255: provider-calculated tax checkout (plan step A3), 2026-10-07

Branch `harness/tax-255`, based on reviewed `main` `73c898dba15bae80e6205565bcde510ee28b536d` (PR #47). Development evidence only. This is not a review, an acceptance, an activation or a launch claim. Nothing here is registered, mounted or bound to a provider, and no provider was called.

`origin/main` has since moved to `fad3ab44` (PR #48, lane A1b, among others). This branch is **not** rebased or merged onto it; root integrates. I checked on `fad3ab44`: the nine frozen V1 files listed below have the same SHA-256, no `2026_10_07_255*` migration exists, and the V1 helpers Tax255 calls into changed in only two ways. `CheckoutCommandFrame::register` and `CheckoutCommandCommitDispatcher` now take the new `CheckoutCommitAdmission` interface (Tax255 never registers an observer), and `MachinePolicyV1::origin()` became public. I read these diffs. I did not test them together with Tax255. No test was run against `fad3ab44`.

Specification: `docs/handoff/2026-10-07/owners/checkout.md` item 4 ("Tax255 queued") and `CLAUDE-PLAN.md` Phase A row A3.

## What this lane adds

A new, separate family, `App\Domain\Commerce\ProductionTaxCheckout`. It never changes the V1 checkout.

1. **Buyer preview, then assent.** `preview()` shows the license terms and the **pre-tax** prices from the current approved candidate and catalog selection. It returns a `previewHash`. `order()` needs that exact hash and literal `accepted: true`. Any change in terms, prices, candidate or execution context between preview and order refuses (`changed`). The order keeps the assent, pre-tax lines and the full selection.
2. **Provider request retained before any I/O.** `initiate()` first writes one immutable request. It holds the exact Stripe Checkout Session parameters, including `automatic_tax: {enabled: true}` and `tax_behavior` from the approved machine policy, plus the idempotency key `va-production-tax-checkout-v2-<request uuid>`. It has no `payment_intent_data.tax` and no `calculation` key; the pinned `2026-08-26.dahlia` schema exposes no such linkage. Only after that write does the transport check run. With `provider => null` the call refuses `provider_unbound` (503) and no transport method runs (fail closed). The retained request is kept.
3. **Hosted session binding.** With an admitted transport, `create` returns only a locator. An authoritative `retrieve` must match the retained request exactly before the provider session id is bound (one row).
4. **Buyer-reviewed session retained once.** `reconcile()` does authoritative GETs only. It retains a session only when it is `complete`, `paid`, `automatic_tax.status = complete`, and the PaymentIntent has `succeeded` with `amount_received` equal to the provider total. The record keeps the provider's subtotal, tax and total, the per-line tax, `automatic_tax {enabled, status, provider}`, and the safe session and payment projections. Customer details, address, email, tax ids and `client_secret` are never retained.
5. **SourceV2.** `ProductionTaxPaidOrderLocatorV2` and `ProductionTaxPaidOrderSourceV2` follow the V1 seam shape: locate without a lock, prelock T23 historical identity, `lockedRead` under a held transaction (reusing V1 `HeldSourceTransaction`), `proveRetainedCurrent`, and refusal to serialize. The line format is a **new purpose**: `schema_version: 2`, `producer: production_tax_checkout_v2`. Tax facts sit in a separate `tax` block next to `pre_tax`. The V1 top-level field names `line_tax_minor`, `line_amount_minor`, `amounts` and `payment_id` are absent at the top level. Tax figures appear only nested inside `pre_tax` and `tax`.
6. **Separate V2 paid consumer adapter.** `ProductionTaxPaidLineAdapterV2::accept($line, $provenance)` returns an authenticated V2 line unchanged, or refuses with 409. It refuses any V1-shaped line outright (`source_version`), including a V2 line with a grafted V1 tax field. It recomputes `source_hash` and every sub-hash and checks the tax arithmetic and provenance. Since follow-up commit `39e3da68` it also requires the line's own retained `execution_context` to agree with the line's funds mode, provider account, provenance and tax behavior, and refuses an order tax above that context's `maximum_rate_bps` (`tax_ceiling`, 409). `source_hash` is an unkeyed self-hash, so the adapter checks consistency. Authenticity comes from `ProductionTaxPaidOrderSourceV2::lockedRead` reading the retained rows. The V1 reader (`PaidGrantPolicy::source` on the paid252 lane) is untouched.

### Tax is never computed locally

The server only compares provider figures with each other: line sums, `total = subtotal + tax` (exclusive) or `total = subtotal` (inclusive), and subtotal equal to the retained request. It also enforces the approved machine-policy ceiling `maximum_rate_bps`. A provider tax above the ceiling is refused (`tax_ceiling`, 409) and is never adjusted. No request field can carry a total or a tax figure: every HTTP body is checked against an exact key set.

## Default-off and refusals

- `config/production-tax-checkout.php` is all literals, with no `env()`: `enabled => false`, `provider => null`, `funds_mode/account_id/return_origin => null`, `stripe_sdk_version => '21.3.2'`, `stripe_api_version => '2026-08-26.dahlia'`.
- `TaxCheckoutPolicy::enabled()` is `app()->environment('local', 'testing') && config(...enabled) === true`, in the predicate style of `CheckoutPolicy` and `ExecutionContextV1`. Every service entry point and the privacy middleware refuse otherwise. Pin drift also refuses.
- `TaxExecutionContext` admits only: `funds_mode = test` (in local/testing), the configured account and return origin equal to the approved machine policy, API `2026-08-26.dahlia`, commercial target `live`, automatic or automatic_async capture, a verified-account buyer, USD with exponent 2, `strategy = provider_calculated`, `rounding = provider_exact`, `behavior` exclusive or inclusive, a 0..10000 bps ceiling, `provider_lifetime >= 1800 + retry`, and `application_verified_observation_time`. This lane has no live-funds path.
- Transport admission: the configured `provider` must be a non-empty string equal to the bound transport's pure `boundTo()`. The shipped `UnboundTaxCheckoutTransport` never qualifies, even if configuration names `unbound`.
- HTTP: `ProductionTaxCheckoutController`, `ProductionTaxCheckoutPrivacy` and `routes/production-tax-checkout.php` (prefix `production/tax-checkout`) exist but are **not mounted**. `ProductionTaxCheckoutServiceProvider` (binds only the refusing transport) is **not registered**. The principal comes only from `ProductionCustomerSessions::principal(Request)` (T23 session floor). A cached `customer` guard user without the session marker gets 403. Reconcile accepts no browser locator. Return is a read.

## Schema: migration `2026_10_07_255000_production_tax_checkout`

The migration only delegates to `TaxCheckoutSchemaInstaller`. Its `down()` refuses before any query.

| Table | Unique | Own-family FKs |
| --- | --- | --- |
| `production_tax_checkout_orders` | public id; (buyer origin, request key) | none |
| `production_tax_checkout_requests` | public id; order; idempotency key | orders |
| `production_tax_checkout_bindings` | public id; request; (account, funds mode, session) | requests |
| `production_tax_checkout_reviewed_sessions` | public id; binding; request; order; (account, mode, payment); (account, mode, session) | bindings, requests, orders |

- No FK, trigger or view references a V1 `production_checkout_*` table, an identity table or a catalog table. The packet asks for producer UUID/hash values without external checkout-table FKs. It also keeps the frozen V1 installer's foreign-reference refusal satisfied; the test reruns `CheckoutSchemaInstaller::up()` on a database containing Tax255.
- BEFORE INSERT guards (text generated per driver by one pure function) require:
  - **Shapes:** lowercase v4 UUID public ids; `YYYY-MM-DDTHH:MM:SSZ` real UTC instants (SQLite: GLOB + hour < 24 + `STRFTIME` round trip; MySQL: REGEXP + `STR_TO_DATE`/`DATE_FORMAT` round trip); 64-hex hashes; non-empty ciphertext ≤ 2 MiB; `vasey-json-v1` canonicalization; integer money with explicit `currency = 'USD'` (SQLite `TYPEOF = 'integer'`); `acct_`/`cs_<mode>_`/`pi_` provider ids; `idempotency_key = prefix || public_id`.
  - **Ordering:** each child's `created_at` is at or after its parent's; `observed_at` is at or after binding creation and at or before recording.
  - **Retained provider arithmetic:** the subtotal equals the request; `total = subtotal + tax` (exclusive) or `total = subtotal` and `tax <= subtotal` (inclusive); tax is within the approved ceiling against the net.
- BEFORE UPDATE and DELETE always refuse.
- The installer resumes only an exact, contiguous, empty prefix. It refuses drift, gaps, populated partial installs, temporary shadows, extra owned objects, foreign views, triggers, routines and FK consumers, and MySQL case/accent dictionary aliases. This mirrors `CheckoutSchemaInstaller` and `ProductionSuppressionSchema`.

## Files owned (31)

Runtime: `app/Domain/Commerce/ProductionTaxCheckout/{ProductionTaxCheckout, ProductionTaxPaidLineAdapterV2, ProductionTaxPaidOrderLocatorV2, ProductionTaxPaidOrderSourceV2, TaxCandidate, TaxCheckoutEvidence, TaxCheckoutPolicy, TaxCheckoutRecords, TaxCheckoutSchema, TaxCheckoutSchemaInstaller, TaxCheckoutTransport, TaxExecutionContext, UnboundTaxCheckoutTransport}.php`, `app/Http/Controllers/ProductionTaxCheckoutController.php`, `app/Http/Middleware/ProductionTaxCheckoutPrivacy.php`, `app/Http/Responses/ProductionTaxCheckoutResponse.php`, `app/Providers/ProductionTaxCheckoutServiceProvider.php`, `config/production-tax-checkout.php`, `database/migrations/2026_10_07_255000_production_tax_checkout.php`, `routes/production-tax-checkout.php`.

Tests: `tests/Feature/ProductionTaxCheckout/{Journey, Guard, Migration, NativeSchema, Policy, HttpBoundary, FrozenV1}` (prefixed `ProductionTaxCheckout…Test`), `ProductionTaxSourceV2Test`, `ProductionTaxPaidLineAdapterV2Test`, `tests/Support/ProductionTaxCheckoutFixtures.php` (trait), `tests/Support/RecordingTaxCheckoutTransport.php`.

SHA-256 of every owned file as tested: `evidence/owned-source-sha256.txt`.

No shared file was edited: `README.md`, `CHANGELOG.md`, `routes/web.php`, `bootstrap/providers.php`, `config/app.php`, `phpunit.xml` and the CI census are unchanged.

## Frozen V1 bytes

`ProductionTaxCheckoutFrozenV1Test` asserts the SHA-256 of these files and that each equals `checkout-composition-20261007/independent-review/source-sha256.txt`: `ProductionPaidOrderSourceV1.php`, `ProductionPaidOrderLocatorV1.php`, `ProductionPaidOrderCommittedReadReceiptV1.php`, `ProductionPaidOrderConsumerCommitAdmissionV1.php`, `OrderEvidence.php`, `HostedEvidence.php`, `CheckoutSchema.php`, `CheckoutSchemaInstaller.php` and `2026_10_07_246000_production_checkout.php`. `HostedCheckout.php` and the V1 write-admission files are excluded on purpose, because lane A1b (`harness/checkout-write-admission-all`) changes them under review. Tax255 reuses V1 helpers by calling them (`CommandTransaction`, `CurrentSelection`, `Evidence`, `HeldSourceTransaction`, `PrimaryBoundary`, `ProductionCheckout::requireOwner`, `OwnAccountStripeGateway::sessionId`) and changes none of their bytes.

## Assumptions and deviations from the brief (flagged)

1. **Brief vs packet: no separate review table.** The packet names no review/assent pipeline. I used a read-only preview hash plus an assent-bearing order, not a V1-style review row. The buyer's *financial* review happens on Stripe's hosted page, and that is what the reviewed-session record retains.
2. **Test funds only.** The brief limits the policy to local/testing, so `TaxExecutionContext` refuses `live`. A live path is A4 work and needs Sean's authorization.
3. **No uncertainty/observation log.** Unlike V1 there is no observations table. A provider failure returns `provider_uncertain` (503) and writes nothing beyond the request and binding. Unknown-outcome retention and reconciliation belong to A4 ("unknown outcomes").
4. **`tax_behavior` comes from the approved machine policy, and no product `tax_code` is sent.** Stripe then uses the account's preset tax code. Whether the account has Stripe Tax registrations, a head-office address and a preset tax code is a merchant fact this lane cannot know.
5. **No physical-commit write observer for V2 writes yet.** V2 NEW writes (order, request, binding, reviewed) run inside V1 `CommandTransaction` frames, which give the original savepoint, depth and primary proofs. They do **not** register a c6-style commit observer. A1b has now merged to `main` (#48) with the `CheckoutCommitAdmission` interface. This branch predates it, and a V2 implementation is required before activation. This is a release blocker, the same class as A1b.
6. **Paid252 is not changed.** The adapter is the reader the paid family can call for V2. Wiring it into `PaidGrantCommands`/`PaidGrantPolicy` (a V2 origin producer value, `paid_order_origins.producer` guard text) is the paid lane's or root's change.

## Commits on this branch

| Commit | Subject |
| --- | --- |
| `a3469c0e` | feat(tax): default-off Tax255 provider-calculated tax checkout and SourceV2 |
| `47b4c59e` | test(tax): cover Tax255 guards, policy, journey, SourceV2, adapter and HTTP |
| `39e3da68` | fix(tax): V2 paid line adapter checks the retained execution context and ceiling |
| `ae8c4c86` | test(tax): service with no transport retains the automatic_tax request and fails closed |
| (this commit) | docs(tax): Tax255 verification record |

## Results (SQLite unless stated; PHP 8.4.26, PHPUnit 12.5.34)

Command form, in `/home/user/VA-Studio-tax255` with `public/build` absent: `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --log-junit <file> <paths>`. Native runs add `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3455 DB_DATABASE=<schema> DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. Every receipt below is plain text without ANSI escapes (`--colors=never`), with JUnit beside it.

| Selection | Source | Engine | Result | Receipt (`evidence/`) |
| --- | --- | --- | --- | --- |
| **Owned: `tests/Feature/ProductionTaxCheckout`** | **`ae8c4c86`** (`tested-commit.txt`, `owned-source-sha256.txt`) | SQLite | **118 tests / 948 assertions, 0 failures, 0 errors, 3 skipped (native-only)**, exit 0 | `owned-sqlite-head.*` |
| Same directory, before the follow-up commits | `47b4c59e` (`owned-source-sha256-47b4c59e.txt`) | SQLite | 113 tests / 932 assertions, 0 failures, 3 skipped, exit 0 | `owned-sqlite-47b4c59e.*` |
| Guard mutations M1 (request before order), M2 (ceiling removed), M3 (calendar check removed), M4b (v4 UUID shape removed), M5 (UPDATE guard removed) | `47b4c59e` schema plus one mutation each | SQLite | each **red**: 1, 1, 1, 3 and 1 failures of 6 Guard tests, exit 1 | `guard-mutations/M*-red.*`, `M*.diff` |
| Restored schema (SHA-256 in `schema-source-sha256.txt`, equal to the final file) | `47b4c59e` | SQLite | Guard class 6 tests / 228 assertions green | `guard-mutations/restored-green.*` |
| M4 first attempt | n/a | SQLite | **harness error, retained**: the mutation did not apply (the pattern matched twice), so the run was unmutated and green. Not red evidence. | `superseded-ptc-prefix/guard-mutations/M4-HARNESS-ERROR-*` |
| Adjacent: 61 files (every test scanning `sqlite_master`/`information_schema`, all `ProductionCheckout*` feature and unit tests) | `47b4c59e` | SQLite | 792 tests / 11520 assertions: 15 errors, 2 failures, 38 skipped, exit 2 | `adjacent-sqlite.*` (file list: `adjacent-sqlite.files`) |
| Same failing classes **without** the 255000 migration | base `73c898db` | SQLite | identical 15 errors and 2 failures (65 tests / 241 assertions), exit 2 | `baseline-without-255000-preexisting.*` |
| Native: `ProductionTaxCheckoutGuardTest` | `47b4c59e` (schema unchanged at `ae8c4c86`) | MySQL 8.4.11, private instance | **6 tests / 118 assertions, 0 skipped**, exit 0 | `native-mysql84-Guard.*` |
| Native: `ProductionTaxCheckoutNativeSchemaTest` | `47b4c59e` (schema unchanged at `ae8c4c86`) | MySQL 8.4.11, private instance | **3 tests / 22 assertions, 0 skipped**, exit 0 | `native-mysql84-NativeSchema.*` |
| Native: `ProductionTaxCheckoutMigrationTest` | n/a | MySQL | **not run** (see "Native run") | none |
| Pint `--test` on all 31 owned PHP files | `ae8c4c86` | n/a | passed, exit 0 | `pint-head.*` |

Per class at `ae8c4c86` (SQLite, tests / assertions / failures / errors / skipped): FrozenV1 2/27/0/0/0 · Guard 6/228/0/0/0 · HttpBoundary 17/131/0/0/0 · Journey 15/109/0/0/0 · Migration 10/240/0/0/0 · NativeSchema 3/0/0/0/3 · Policy 43/147/0/0/0 · PaidLineAdapterV2 19/24/0/0/0 · SourceV2 3/42/0/0/0. No unit tests were added (`tests/Unit` is unchanged).

The follow-up commits change only `ProductionTaxPaidLineAdapterV2.php` and two test files. They touch no schema, guard, installer or migration, so the native and mutation receipts above still describe the schema at `ae8c4c86`. The adjacent run was not repeated.

The 17 adjacent failures are **pre-existing on base `73c898db`**: they reproduce with the Tax255 migration removed. They are in `DiscoveryEpochMigrationTest` (2, "Operational teardown admitted"), `ProductionTrackCapabilitiesMigrationOwnershipTest` (6, "Unexpected production capability external foreign key reference") and `RightsEvidenceGuardMigrationTest` (9, "Unexpected rights evidence additional trigger"). I did not investigate or fix them (outside scope). Root should check them against the 257/258 merges.

Native-only methods that skip on SQLite (for root's `scripts/ci/database-sqlite-skips.json` census): `Tests\Feature\ProductionTaxCheckout\ProductionTaxCheckoutNativeSchemaTest::{test_native_dictionary_matches_the_generated_guards_and_a_rerun_is_a_no_op, test_native_timestamp_and_uuid_guards_refuse_malformed_text, test_native_case_alias_of_a_reserved_constraint_name_is_refused_before_ddl}`. On MySQL, the SQLite-dictionary cases of `ProductionTaxCheckoutMigrationTest` skip (one install test plus seven injection cases).

### Native run

A private `mysqld` 8.4.11 (`--no-defaults`, port 3455, datadir in the session scratchpad, one schema per run, runs serialized) ran the Guard class and then the NativeSchema class at `47b4c59e` source. Both passed with zero skips. That proves they ran on MySQL, because NativeSchema skips on any other driver. The datadir is deleted (see below), so these runs cannot be inspected again.

What is **not** native evidence, retained under its own name:

- `native-env-error-shared-server/`: an environment error. Three classes ran concurrently on one server. `CapabilityMigrationOwnership` scans `information_schema.TRIGGERS` across all schemas, so `migrate:fresh` refused before any Tax255 code ran. The NativeSchema and Migration runs were stopped. Their JUnit files are empty.
- `superseded-ptc-prefix/`: receipts from before the identifier prefix was renamed `ptc_` to `ptx_` (`ptc_` belongs to the production-track capabilities family). That includes a native run interrupted at the 30-minute limit after 11 of 20 cases, whose first failure was a test bug (LIKE `ptc_%` matched 9 capability triggers). This is superseded source and not evidence about the final tree.

`ProductionTaxCheckoutMigrationTest` was **never completed natively**. Its MySQL dictionary and refusal cases (drift, gaps, populated partials, shadows, foreign references and the V1 installer rerun) are untested on MySQL. The other DB classes (Journey, SourceV2, HttpBoundary, Policy) were not run natively.

## Untested

- Lane-authored native runs beyond Guard and NativeSchema. The independent reviewer has since run Migration, Policy, Journey, SourceV2, PaidLineAdapterV2 and FrozenV1 natively (114 / 713 with its probes, 0 failures; see the review section below); `ProductionTaxCheckoutMigrationTest` still skips 8 of its 10 cases on MySQL by design, and HttpBoundary ran on SQLite only. No MySQL concurrency or race proof of the unique keys (for example two concurrent `initiate` calls racing on one request).
- Foundation CI (manual final verification only, per the cost policy). No hosted workflow was dispatched for this branch.
- HTTP mounting: the routes, privacy middleware, response sanitization and provider registration are not mounted, so no real request path was exercised. `ProductionTaxCheckoutHttpBoundaryTest` drives the controller and middleware directly.
- The migration lives in `database/migrations/`, so an ordinary `php artisan migrate` installs the four tables wherever it runs. The tables stay empty and inert while the policy is off.
- Any real Stripe call. All provider responses come from `tests/Support/RecordingTaxCheckoutTransport.php`, a synthetic in-process fixture restricted to testing and test funds. Its tax figures (437 minor units per line) stand in for a provider calculation and are not one. Real Stripe Tax behavior is unverified: `automatic_tax.status` transitions, line `amount_tax` with `tax_behavior`, rounding, `liability`, and the expanded `line_items.data.price.tax_behavior`.
- `requires_location_inputs`/`failed` sessions that the buyer abandons, and session expiry handling beyond refusal.

## Facts only Sean can supply (activation blockers, never fixture-derived)

Stripe account id and mode for this flow. Whether Stripe Tax is enabled, with registrations per jurisdiction and the origin/head-office address. The preset product tax code (or an explicit per-product code). The tax behavior (exclusive or inclusive) and the rate ceiling `maximum_rate_bps` in an approved machine policy. Currency confirmation (USD only today, set by the catalog). Return origin. Explicit authorization to enable, to bind a real transport and to move to live funds.

## What root must do

1. Mount: register `ProductionTaxCheckoutServiceProvider`, prepend `ProductionTaxCheckoutPrivacy` globally, include `routes/production-tax-checkout.php` under `web`, and add the exception/response sanitization for the `production/tax-checkout` prefix (as for V1).
2. Census: add the three native-only methods above to `scripts/ci/database-sqlite-skips.json`, and include `tests/Feature/ProductionTaxCheckout` in shard timing and the native-only method census normalization.
3. Integrate: merge or rebase onto current `main` (A1b #48 is merged), then rerun this directory, the adjacent selection and the native classes, including `ProductionTaxCheckoutMigrationTest`, which has never completed on MySQL.
4. Before any activation: a V2 `CheckoutCommitAdmission` (the A1b interface is now on `main`), a reviewed real own-account transport (A4), unknown-outcome retention (A4), paid-family wiring for V2 origins, and independent review of this lane (payment and migration are sensitive).

## Private instance cleanup

On 2026-10-08 the Tax255 `mysqld` (pid 14577, port 3455, socket `/tmp/claude-0/t255.sock`) was no longer running: `kill -0` reported no such process and nothing was listening on 3455. Its datadir `scratchpad/mysql-tax255/` (232 MB, schema `vaseyaudio_tax255`) and the stale socket were deleted. Two other `mysqld` processes on that host (`trigscan-fix-mysql`, port 3541, and `review-member-mysql`, port 3531) belong to other lanes and were left untouched. No database, dump or credential from these runs is committed. `ci-only-password` is a throwaway local root password for a loopback-only instance that has now been destroyed.

## Integration onto current `main`

`21c2d046` merges `origin/main` at `fad3ab44` (PRs #46 and #48 included) into the branch with
no conflicts. `tests/Feature/ProductionTaxCheckout` on SQLite afterwards: 118 tests / 948
assertions, 0 failures, 0 errors, 3 skipped (native-only), exit 0
(`evidence/owned-sqlite-after-main-merge.*`). The nine frozen V1 files keep their SHA-256 on
`fad3ab44`; the A1b `CheckoutCommitAdmission` interface and the now-public
`MachinePolicyV1::origin()` are the only changes to V1 helpers Tax255 calls, and Tax255
registers no observer.

## Independent review (`9ec94d8c`)

`independent-review/DECISION.md`: **APPROVE WITH CONDITIONS for a development merge
only** of a default-off, unregistered and unmounted family. It does not approve
activation, registering the provider/middleware/routes, any real transport, Stripe call or
test charge, live funds, wiring the adapter or SourceV2 into Paid252 or any grant path, or
any merchant or tax fact. The lane's own release blockers stand (V2
`CheckoutCommitAdmission`, the A4 transport, unknown-outcome retention, MySQL race proof).

Native MySQL 8.4.11 (reviewer, private instance): Guard 6/118, NativeSchema 3/22,
Migration 10/191 (8 skipped by design), Policy 43/147, Journey 15/109, SourceV2 3/42,
PaidLineAdapterV2 19/24, FrozenV1 2/27, installer probe 12/29, line-feed probe 1/4;
114 / 713, 0 failures. Frozen V1: all nine files byte-identical on the composition record,
`9ec94d8c` and `fad3ab44`. The 17 adjacent failures reproduce identically on `fad3ab44`
and `73c898db`. 14 mutations applied and reverted.

Conditions:

1. **R-1 Medium (no consumer yet):** `ProductionTaxPaidLineAdapterV2::accept()` checks
   only an unkeyed self-hash, so a resealed forged line (moved to another order with zero
   tax, or a `verified_production`/`live` line with an id no producer can mint) is
   accepted. Before any Paid252/V2 wiring: bind `accept` to the held source line
   (`Evidence::same($source->line($p), $line)`) or key the hash, refuse
   `verified_production`/`live` until a reviewed live producer exists, and add both
   forgeries as regressions.
2. **R-2 Low:** the lane suite misses PaymentIntent amount/`amount_received` and
   currency mismatches (four mutations survive it). Port the reviewer's money-probe cases
   into the Journey test before activation or A4.
3. **R-3 Low:** the MySQL provider-id guards' `REGEXP '...$'` accepts a trailing line
   feed (`acct_…\n`, `cs_test_…\n`, `pi_…\n`); SQLite refuses. Runtime checks stop the
   app writing them. Bound the pattern and add the three cases to the Guard test.
4. **R-4 Low:** the installer refusal matrix is untested on MySQL by design. Port the
   reviewer's 11-case native installer probe into the suite and the census.
5. **R-5 Low:** `initiate()` (and `reconcile()`) never compare the retrieved `session.id`
   with the created one; `retain()` refuses the mismatch later. Require equality at both.

The three native-only `ProductionTaxCheckoutNativeSchemaTest` methods were added to the
exact SQLite census in `75d3ea85`.
