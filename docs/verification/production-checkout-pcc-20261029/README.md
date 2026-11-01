# Lane PCC work package: production commerce composition, default-off

Current status (2026-11-01): **default-off source independently approved at `db5e4b9342f08ede28d35c49524990d7777bfbcc`; original full SQLite sweep completed with failures, classified below; final evidence carry review and PR gates pending.** No full-suite pass is claimed.

Historical status before Codex continuation: **implemented on branch `harness/production-checkout-pcc-20261029`, main `8bb1ab24` merged; not pushed; independent
payment/auth review not yet done.** The work-package statement (sections Split to Tests) was committed first (`071d9f80`). Cut
from `origin/main` `a8f17e5cbd509a822e2c283bdd501177b43c11c0` (`dd587603` plus docs-only handoff files) on branch
`harness/production-checkout-pcc-20261029`.

Scope: lane PCC in `docs/handoff/2026-10-24/COMPLETION-PLAN.md` and the code gates in
`docs/ops/production-activation-packet.md` section 1. Nothing here deploys, calls a provider, uses a real credential, charges
money or changes DNS. Every new switch ships off, and no new path is reachable in production by default.

## Split

The branch is cut into two reviewable halves with separate commits, so the lead can merge either boundary:

- **PCC-a (operations):** the production preflight and runtime validator parametrization (real mail and live Stripe mode only
  behind two explicit flags), the two new flags in the capability boundary and its census, an explicit non-permissive
  `trustProxies`, and production identity SMTP settings through configuration with a literal activation switch.
- **PCC-b (composition):** the production checkout mount gate, provider registration and route mount through it, the early
  privacy middleware, the retrieval-only production reconciliation operator command, and the exclusivity-authority wiring.

The reconcile command moves from the suggested PCC-a to PCC-b because it needs the composition gate and the provider binding
that only PCC-b registers. PCC-a has no dependency on PCC-b.

## Files owned

New:
- `app/Http/ProductionCheckout/ProductionCheckoutMount.php` (mount gate)
- `app/Console/Commands/ReconcileProductionCheckout.php` (operator command)
- `app/Domain/Commerce/ProductionCheckout/ExclusiveInventoryGate.php`
- `app/Http/Middleware/ExplicitTrustProxies.php`, `app/Support/Http/TrustedProxyList.php`, `config/trusted-proxies.php`
- `config/production-runtime.php` (the two admission flags, read by the validators and the boundary only)
- tests under `tests/Feature/ProductionCheckoutComposition/`, `tests/Unit/TrustedProxyListTest.php`, `tests/Feature/ExplicitTrustProxiesTest.php`
- this packet

Edited:
- `app/Providers/AppServiceProvider.php` (one guarded `register()` line, the existing Tax precedent), `routes/web.php` (one guarded
  `require`), `bootstrap/app.php` (prepend the existing privacy middleware, one report and one respond branch, one middleware
  replacement). Shared mount files: each edit is self-contained.
- `config/production_checkout.php` (`mounted` literal false, `operator_reconciliation_enabled` default false),
  `config/production-identity-smtp.php`
- `app/Http/Middleware/ProductionCheckoutPrivacy.php`, `app/Domain/Commerce/ProductionCheckout/HostedCheckout.php` (one
  operator method reusing the existing private `record()`/`uncertain()`), `app/Domain/Commerce/ProductionCheckout/CurrentSelection.php`
- `scripts/ops/private-server-preflight.php`, `ops/production/validate-runtime.php`, `scripts/ops/capability-boundary.php`
- templates: `.env.example`, `ops/production/env.production.example`, `ops/staging/env.staging.example`
- their tests, `CHANGELOG.md`, `README.md` env table, `docs/ops/production-activation-packet.md`, `ops/production/README.md`

Not touched: `app/Http/Membership`, `app/Domain/Memberships` (F9), customer comms and `SiteContentSchema` (CX), reporting (REP),
`resources/contracts/*/profile-assets.json`, CLAUDE.md sections 1 to 12, the logo.

## Assumptions

1. The mount gate copies the reviewed `MembershipHttpMount` / `ProductionFreeHttpMount` pattern (plain loaded configuration
   storage, literal boolean `true` only, cached environment never a resolver) with one deliberate difference: when literally
   true it admits `local`, `testing` **and `production`**, because production is this chain's target (`ExecutionContextV1` admits
   live funds there). Staging never mounts: `ExecutionContextV1` refuses test funds outside local/testing and live funds on
   staging, so a staging mount could only fail, and the S2a/S2b decision is still open. The shipped value is literal `false` in
   `config/production_checkout.php`, so turning it on is a reviewed code change; the production validator also holds it false,
   so activation needs that hold lifted as well, together with Sean's authorization.
2. The production chain sells non-exclusive v1 inventory only (`PreparationSelection` and the rights-scope link check refuse
   everything else). The exclusivity authority is therefore wired as a precondition that is consulted first: exclusive or scoped
   inventory is refused with `exclusivity_authority` unless `storeMaySellExclusives()` is true, and still refused with
   `unsupported_inventory` when it is, because no production exclusive consumer exists. Any failure reading the ledger refuses.
3. "Live Stripe mode" in production means: `STRIPE_MODE=live`, and in the validator an `sk_live_` value admitted only as
   `PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY` and `PRODUCTION_CHECKOUT_FUNDS_MODE` admitted only as blank or `live`. Every
   `PRODUCTION_CHECKOUT_*_ENABLED` switch and the mount stay held false by the validator whatever the flag says: admitting live
   credentials is not activating checkout.
4. "Real mail" in production means `MAIL_MAILER=smtp` with a bounded host, SMTPS on port 465, a sender address and a username,
   checked by shape only. Without the flag the baseline stays `log`.
5. Production identity SMTP values come from `PRODUCTION_IDENTITY_SMTP_*`, but only a literal `$enabled = false;` in
   `config/production-identity-smtp.php` composes them into `settings`; the environment supplies values and never selects or
   enables a provider. This replaces the file's "reads no environment" test with "activation is literal" (named conflict below).
6. `trustProxies`: the Forge/nginx kit runs PHP-FPM behind nginx on the same host over FastCGI, so the right default is to trust
   no proxy. Laravel's own middleware trusts **every** proxy when no list is configured and the host ends in `.on-forge.com`;
   the replacement never does that, honours only `X-Forwarded-For`, `-Proto` and `-Port` (never `-Host`, `-Prefix` or the AWS
   ELB header), and trusts only an explicit, validated list of addresses or CIDR ranges.

## Not in this lane (pushed back, with reasons)

- **A5 wiring (`FinancialReadPlan` output into the refund/dispute ledger).** Not implemented. The A5 ledger keys every row by the
  test chain's `orders.id` (`refund_dispute_*.order_id`, no family discriminator) and applies its effects to test-chain
  `license_grants`; production checkout orders live in separate `production_checkout_*` tables and issue no grants.
  `FinancialReadPlan` is pinned to `payments.stripe.mode=test` with an `sk_test_` key and has no caller yet. Feeding its
  candidate into that ledger needs a schema decision (a family column or separate production ledger tables, an irreversible
  data change needing independent review) and a production financial read path. Writing production observations into the
  test ledger's `order_id` space would collide with test order IDs. Proposed design in the results section.
- **Paid252/Tax mounts admitted for production** (lane row, not in the lane brief): not done; their mounts stay local/testing.
- **Production webhook receiver:** the strategy stays retrieval-only (no receiver), as the brief states.

## Acceptance criteria

- The mount gate is closed for every value other than the literal boolean `true` (string, integer, null, array) and in staging
  and any unknown environment; with it closed no checkout route is registered, the provider is not registered, a stale route
  answers the private 404 before any SQL or identity work, and the operator command refuses.
- The operator command refuses unless the mount is admitted and `operator_reconciliation_enabled`, `reconciliation_enabled` and
  `provider_io_enabled` are all literally true; it only retrieves (no create), reuses the HTTP path's persistence, so a repeated
  run with unchanged provider state writes nothing, and a provider failure appends at most one `uncertain` observation.
- Exclusive or scoped inventory is refused with `exclusivity_authority` while the ledger is empty, `beatstars` or `frozen`, and
  with `unsupported_inventory` when the store is the authority.
- Preflight production profile: `MAIL_MAILER=log` and `STRIPE_MODE=test` still required unless the matching flag is exactly
  `true`; a flag other than `true`/`false` blocks; staging refuses either flag set to anything but false.
- Validator: live credentials refused unless the live flag is set, and then only in their one admitted key; every production
  checkout switch and the mount still held; the four pre-launch holds still refuse their keys.
- `trustProxies`: a client outside the list cannot choose its own address, scheme or host, including on an `.on-forge.com` host.

## Tests

- SQLite: new composition, command, exclusivity and trust-proxy suites; the existing production checkout suites
  (`ProductionCheckout*`, `ProductionCheckoutProviderTest`, `ProductionCheckoutConfigTest`), `StagingEnvironmentAdmissionTest`,
  `ProductionEnvironmentTemplateTest`, `StagingRuntimeValidatorTest`, `TestCommerceProfileValidatorTest`,
  `ProductionIdentitySmtpBindingTest`, `StripeCapabilityPreflightTest`.
- Python: every `tests/ops/test_*.py` and `scripts/ops/test-*.py`.
- Mutation checks on the mount gate and the exclusivity refusal.
- Native MySQL only if a locking claim is made (none is planned: the command reuses the HTTP path's persistence and constraints).

## Results

### Commits

PCC-a (operations; cut here to merge PCC-a alone): `c18efdc9` admissions in the preflight and validator, boundary and
templates; `228adbfc` explicit `trustProxies`; `9d99529d` identity SMTP through configuration with a literal activation;
`ab9e6336` PCC-a docs. PCC-b (composition): `1382f151` mount gate, provider registration, route mount and privacy seam;
`793efaa3` operator reconcile command; `7a39ac62` exclusivity authority wiring; `0515b15f` domain refusal test (mutation M10);
`6ee56088` PCC-b docs; `b28d4125` this results section. Merges of main: `0f93ed22` (`76fca3d7`, REP and symfony/mime),
`5110d74c` (`a926f98b`), `bcce3975` (`8bb1ab24`, F9 and CX); `93a5eefb` and `4f0ce701` refresh the native-selection comment.
Merge conflicts were resolved keeping both sides (CHANGELOG, the staging deploy scrub, the capability boundary's flag count and
comments, the staging validator comment, the two boundary tests).

### What is wired, and what stays inert

| Item | Shipped state | What turning it on needs |
| --- | --- | --- |
| Mount gate `production_checkout.mounted` | literal `false`; closed in every environment; staging never mounts even with `true` | reviewed code change to the literal **and** to `ops/production/validate-runtime.php` (`holds.production_checkout_mounted`), Sean's authorization |
| `ProductionCheckoutServiceProvider`, `routes/production-checkout.php` | registered/required only through the gate; not registered today | the gate |
| `ProductionCheckoutPrivacy` | prepended globally; unmounted or withdrawn path is the private 404 before any SQL; mounted but `http_enabled` off is the private 503 | the gate plus `PRODUCTION_CHECKOUT_HTTP_ENABLED` (held false in production) |
| `vasey:production-checkout-reconcile` | refuses (`not_mounted`/`disabled`) | the gate plus `PRODUCTION_CHECKOUT_OPERATOR_RECONCILIATION_ENABLED`, `..._RECONCILIATION_ENABLED`, `..._PROVIDER_IO_ENABLED` (all held false in production), provider configuration |
| Exclusivity authority | consulted for any exclusive or scoped selection; such a selection is refused in every case | a production exclusive consumer (not built) and U-08 |
| Real mail / live Stripe mode in the production profile | refused unless `VASEY_PRODUCTION_REAL_MAIL_ADMITTED` / `VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED` is exactly `true` | Sean's provider and mode decisions; the flags never lift a checkout switch |
| Identity SMTP settings | `settings` null (literal `$enabled = false;`) | reviewed literal flip, real-mail flag, `PRODUCTION_IDENTITY_SMTP_*` in the host secret store |
| `trustProxies` | active now: trusts no proxy (blank list); never the framework's `.on-forge.com` catch-all | a reviewed `TRUSTED_PROXIES` list only if a CDN is placed in front |

`trustProxies` is the one change that alters running behaviour by default: on an ordinary host it is the same (no proxy was
trusted), and on an `.on-forge.com`/`.on-vapor.com` host or Laravel Cloud forwarded headers are no longer trusted.

### A5 wiring: pushed back, proposed design

Not implemented (see "Not in this lane"). Proposed for a separate reviewed lane: (1) a production refund/dispute ledger keyed
by `production_checkout_orders.id` (new additive tables mirroring `refund_dispute_*`, or one `order_family` column with a
composite unique key, decided in review); (2) a production financial read path, since `FinancialReadPlan`/`RetrievalPolicy`
pin `payments.stripe.mode=test` and an `sk_test_` key, built on `ProductionPaidOrderSourceV1` plus the own-account gateway;
(3) the A5 state machine and allocation reused against the production order lines, with effects limited to recorded holds
until production grants exist; (4) replay safety by the same `(order_id, request_id)` and `(order_id, sequence)` keys A5 uses.
Policy values stay unset (owner question 10).

### Test results (SQLite direct PHPUnit, PHP 8.4.26; Python 3)

All runs use the direct PHPUnit invocation with an in-memory SQLite database (`APP_ENV=testing`, `CACHE_STORE=array`,
`QUEUE_CONNECTION=sync`, `public/build` absent) unless stated. Counts are exact; times are on a shared, heavily loaded host.

Targeted, at `0515b15f` (before the merges):

| Suite | Result |
| --- | --- |
| Production checkout: the 3 new composition files, 15 existing `ProductionCheckout*` feature files, `ProductionCommerceReadinessTest`, `StripeCapabilityPreflightTest`, `ProductionCheckoutConfigTest`, `ProductionCheckoutProviderTest`, `ProductionHostedEvidenceTest` | OK, 372 tests, 2690 assertions, 9 skipped (native-only census methods), 21 m 14 s |
| Admission, boundary and ops units: `StagingEnvironmentAdmissionTest`, `StagingRuntimeValidatorTest`, `TestCommerceProfileValidatorTest`, `ProductionEnvironmentTemplateTest`, `TrustedProxyListTest`, `ExplicitTrustProxiesTest`, `ProductionIdentitySmtpBindingTest`, `MembershipHttpMountTest`, `ProductionFreeGrantHttpTest`, `ExclusivityAuthorityTest` | OK, 297 tests, 5630 assertions |
| Mount gate alone (`ProductionCheckoutMountTest`) | OK, 24 tests, 766 assertions |
| Reconcile command (`ProductionCheckoutReconcileCommandTest`) | OK, 16 tests (13 before `0515b15f` added 3) |
| Exclusivity (`ProductionCheckoutExclusivityTest`) | OK, 14 tests, 41 assertions |

On the merged head `4f0ce701` (main `8bb1ab24` merged):

| Check | Result |
| --- | --- |
| Every `tests/ops/test_*.py` and `scripts/ops/test-*.py` (22 files) | all OK, including `test_staging_protected_configuration`, `test_staging_capability_profile` and `test_staging_code_drift` |
| Safeguards `test-phpunit-shards.py`, `test-ci-scope.py`, `test-workflow-cadence.py`, `test-focused-tests.py`, `test-database-receipts.py` | OK: 52, 27, 14, 53, 61 tests |
| `composer audit` (after reinstalling `symfony/mime` 8.1.10 and `polyfill-mbstring`) | no advisories |
| `composer validate --strict` | valid |
| Native selection (`phpunit-shards.py --shards=8 ... --mysql-native-selection`) | 2665 selected cases in 263 files of 10008 discovered (main's selection; PCC adds no native-only method); generated files deleted |
| Full SQLite suite (16 shards at `4f0ce701`) | incomplete: 9 of 16 shards finished; see "Status at handoff" |

Full-suite history, recorded honestly: four earlier full runs (at `6ee56088`, `5110d74c` twice, `4f0ce701` once) were killed by
container restarts before every shard finished. One shard that did finish (shard 3 of 4 at `5110d74c`, 2474 tests) had 1 error
and 1 failure: `RightsDeclarationWriterTest::test_new_pending_and_verified_successors_preserve_paid_history_contract_bytes_and_original_verification`
and `InquiryBrowserCatalogCleanupTest::test_failed_withdrawal_audit_keeps_the_ready_track_site_release_and_private_history_unchanged`.
They are not order-dependent: the same shard run alone in its exact order passed (2474 tests, 25057 assertions, 256 skipped,
2 h 0 m), and both classes pass alone (39 tests). Root cause, found and reproduced on main `8bb1ab24`: a pre-existing
second-boundary defect in test-contract issuance (`RenderTestContract` completion save vs `ReadGrantContract`'s
`issued_at == work.updated_at` check; Eloquent rewrites an unchanged `updated_at` when the clock crosses a second before the
save), which quarantines the work with `evidence_changed` and makes the fixtures throw "Synthetic contract did not issue".
A deterministic probe fails on main the same way (`contract-second-boundary.md`, `evidence/`). Both tests depend on it; neither touches PCC code. Handed to its own fix lane.

Native MySQL 8.4.11 (private `/opt/mysql-8.4.11/bin/mysqld`, own datadir under `/home/user/notes/pcc-ev`, socket
`/tmp/pcc-my/s`, port 3517 checked free with `ss -ltnp`; the server was stopped by its own PID and the datadir removed):
OK, 16 tests, 103 assertions, 1 h 39 m, at `4f0ce701` (code identical to the final head). Cases: the five ledger states,
the hosted re-check, the unreadable ledger and the non-exclusive path from `ProductionCheckoutExclusivityTest`; idempotent
retrieval, provider failure, lost-create locator, the three domain switch refusals and two pre-query refusals (unmounted,
operator off) from `ProductionCheckoutReconcileCommandTest`. The pure detection data sets were not repeated natively.

### Mutation checks

Each mutation was applied to the file, the named tests run, and the file restored
(the harness is kept as `evidence/mutate.py.txt`):

| Mutation | Tests | Result |
| --- | --- | --- |
| M1 gate admits truthy values (`== true`) | `ProductionCheckoutMountTest` | killed (6 failures) |
| M2 gate admits staging | `ProductionCheckoutMountTest` | killed (2) |
| M3 gate ignores the environment | `ProductionCheckoutMountTest` | killed (6) |
| M4 privacy seam skips the runtime mount check | `ProductionCheckoutMountTest` | killed (19) |
| M5 provider registered without the gate | `ProductionCheckoutMountTest` | killed (15) |
| M6 exclusivity authority not consulted | `ProductionCheckoutExclusivityTest` | killed (11) |
| M7 exclusivity authority inverted | `ProductionCheckoutExclusivityTest` | killed (5) |
| M8 unreadable ledger treated as "yes" | `ProductionCheckoutExclusivityTest` | killed (1) |
| M9 gate not called from `CurrentSelection::load` | `ProductionCheckoutExclusivityTest` | killed (5) |
| M10 domain operator path skips its operator switch | `ProductionCheckoutReconcileCommandTest` | survived at first (the command checks the same switch first); killed (2) after `0515b15f` added a direct domain test |

### Census and native MySQL

No engine-skipping test method was added, so `scripts/ci/database-sqlite-skips.json` is main's, unioned at every merge, and the
native selection is main's (2665 cases in 263 files). No new locking or race claim is made: the operator command reuses
`HostedCheckout::record()`/`uncertain()`, whose concurrency rests on the existing unique keys (session per intent, payment per
intent, observation sequence per intent), and the exclusivity gate adds one plain read of the authority ledger inside the
existing checkout frame, only for exclusive or scoped selections, which are refused either way. The native run above checks that
those paths and the moved rights-scope locking read work on MySQL; it is not race evidence.

### Named conflicts

- `config/production-identity-smtp.php` was in the template test's code-literal family ("reads no environment"). The brief asks
  for SMTP settings through configuration with documented variables; the file now reads `PRODUCTION_IDENTITY_SMTP_*` inside a
  closure that runs only when the literal `$enabled` is true, and the test pins that instead. Activation stays a code change.
- The reviewed mount pattern admits only local/testing; this gate also admits production (assumption 1). Reviewers should
  decide whether production admission belongs in the gate now or in the later activation change.

### Untested conditions

- No real Stripe account, live key, SMTP provider, CDN or host; every provider fact is the synthetic fixture.
- Native MySQL covered only the selected exclusivity and reconcile cases above; no race test was added or run. Browser,
  Foundation CI and GitLab were not run. The full PHP suite ran on SQLite only.
- The production environment cases boot the application with `env=production` inside PHPUnit; a real production boot with a
  config cache and `route:cache` was not exercised (the runtime seam re-checks the gate on every request for a stale table).
- The operator command has no sweep mode; recovery is one order at a time.
- With a non-empty `TRUSTED_PROXIES`, global seams prepended by packages (Inertia) run before `ExplicitTrustProxies`; they do not
  read the client address.

## Status at handoff (2026-11-01)

Head: branch `harness/production-checkout-pcc-20261029`, main `3e8a1fd7` merged (`31a90d7b`), selection comment refreshed
(`93e8d87c`), then this section. Not pushed, no PR, no review yet. Everything below is reproducible from the repository alone.

### Split

One branch, cut into two reviewable halves: **PCC-a** (operations) ends at `ab9e6336`; **PCC-b** (composition) is
`1382f151` to `0515b15f` plus docs. PCC-a has no dependency on PCC-b. The reconcile command sits in PCC-b because it needs the
composition gate and provider binding.

### Wired versus inert

See "What is wired, and what stays inert" above. In short: the mount gate (`production_checkout.mounted`, literal `false`,
held by the production validator) is the only way in; behind it sit the provider registration, the route mount, the privacy
seam's runtime re-check and the operator reconcile command, and every `PRODUCTION_CHECKOUT_*` switch stays as shipped. The
exclusivity authority is consulted for exclusive or scoped selections, which are refused either way. Real mail and live Stripe
mode are admitted in the production preflight only behind exact `true` flags that never lift a checkout switch. Identity SMTP
composes nothing until its code literal is flipped. `trustProxies` is the one default behaviour change: no proxy is trusted,
including on `.on-forge.com` hosts. Not done (pushed back): A5 `FinancialReadPlan` wiring, Paid252/Tax production mounts.

### Results so far

| Evidence | Head | Result |
| --- | --- | --- |
| Mutations M1 to M10 (gate, privacy seam, provider registration, exclusivity refusal, ledger failure, call site, domain operator switch) | `0515b15f` | all killed (M10 after `0515b15f`) |
| Targeted production checkout suites (22 files) | `0515b15f` | OK, 372 tests, 2690 assertions, 9 skipped |
| Admission, boundary and ops PHPUnit units (10 files) | `0515b15f` | OK, 297 tests, 5630 assertions |
| Native MySQL 8.4.11, exclusivity and reconcile cases | `4f0ce701` | OK, 16 tests, 103 assertions |
| All 22 `tests/ops/test_*.py` and `scripts/ops/test-*.py` | `93e8d87c` | all OK |
| Safeguards (shards, ci-scope, workflow cadence, focused tests, database receipts) | `93e8d87c` | OK: 52, 27, 14, 53, 61 |
| `composer audit` | `93e8d87c` | no advisories |
| Native selection | `93e8d87c` | 2673 cases in 264 files of 10037 discovered (main's selection) |

Full SQLite suite, 16 shards at `4f0ce701` (`phpunit-shards.py --shards=16 --prefix=phpunit-ci-local`); the run was stopped by
a container restart before it finished:

| Shard | Result |
| --- | --- |
| 1 | OK, 626 tests, 10306 assertions, 25 skipped |
| 2 | OK, 626 tests, 3963 assertions, 54 skipped |
| 3 | 626 tests, 8196 assertions, 2 errors, 2 failures, 30 skipped (classified below) |
| 4 | OK, 626 tests, 7646 assertions, 32 skipped |
| 5 | 626 tests, 5215 assertions, 1 failure, 56 skipped (classified below) |
| 6 | OK, 626 tests, 7191 assertions, 10 skipped |
| 7 | OK, 626 tests, 7504 assertions, 38 skipped |
| 8 | stopped at 567 of 626, no failure or error before the stop |
| 9 | OK, 625 tests, 5746 assertions, 66 skipped |
| 10, 11, 12 | stopped at 378, 189 and 252 of 625, no failure or error before the stop |
| 13 to 16 | not run |

Earlier 4-shard runs were also stopped by restarts; the one complete shard (3 of 4 at `5110d74c`) is covered in "Test results".

### Failure classification

| Failure | Class | Evidence |
| --- | --- | --- |
| `OfferDraftBrowserEvidenceTest` (2 cases): `ProcessTimedOutException` from a child PHP boot with a fixed timeout | load timeout | passes alone at `93e8d87c`: OK, 2 tests, 82 assertions |
| `ProductionTaxCommittedReadReceiptTest` `original-budget` / `expiry` | load timeout (a wall-clock budget) | passes alone: OK, 1 test, 11 assertions |
| `CustomerListeningNotesCapacityTest` `maximum ASCII characters` (503 instead of 422) | load timeout | passes alone: OK, 2 tests, 96 assertions |
| `StagingEnvironmentAdmissionTest::test_source_census_routes_every_admitting_gate_through_the_helper_and_leaves_local_only_gates_explicit` | pre-existing on main | the only unlisted gate file is `app/Domain/Notifications/Mail/TransactionalMailPolicy.php` (lane CX), which has a local/testing gate but is not in `LOCAL_ONLY_FILES` on main `8bb1ab24` or `3e8a1fd7`; it fails the same way alone on the merged head. `ProductionCheckoutMount` admits `local`, `testing` and `production`, so it does not match the census pattern and is not part of this failure. Fix belongs to CX/main (classify the file) |
| `RightsDeclarationWriterTest` one case and `InquiryBrowserCatalogCleanupTest` one case (4-shard run, `5110d74c`) | pre-existing on main, timing-dependent | contract second-boundary defect, reproduced deterministically on main; `contract-second-boundary.md`; handed to its own fix lane |

No failure is in PCC code or tests.

### What remains, with commands

1. Finish the full SQLite suite on the final head (the shards above were stopped). From the worktree root, with `public/build`
   absent and `.env` copied from `.env.example`:

   ```
   python3 scripts/ci/phpunit-shards.py --shards=4 --prefix=phpunit-ci-local
   for n in 1 2 3 4; do
     env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:$(head -c32 /dev/zero | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: \
       DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 \
       php -d memory_limit=1024M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
       -- --colors=never --fail-on-phpunit-warning --configuration=phpunit-ci-local-$n.xml
   done
   rm -f phpunit-ci-local-*
   ```

   Expect the census failure above until main classifies `TransactionalMailPolicy.php`; rerun any timeout alone before
   classifying it.
2. Independent payment/auth review of the actual head, focused on: the mount gate's production admission (assumption 1); the
   privacy seam's 404 ordering; `HostedCheckout::operatorReconcile` (buyer-independent persistence, locator rule); the
   validator's live-key admission and holds; `ExplicitTrustProxies` (header set, outermost position, `.on-forge.com`); the
   identity SMTP literal versus the old code-literal rule (named conflict); the exclusivity gate's detection rule.
3. Open the PR from `harness/production-checkout-pcc-20261029` with what/why/verified from this packet; merge only at the
   reviewed exact SHA under the CI cost policy (no automatic matrices; one Foundation run for the final integrated candidate).
4. Separate lanes: A5 production ledger (design above), Paid252/Tax production mounts, the contract second-boundary fix, and
   the `TransactionalMailPolicy` census classification.


## Codex resumption (2026-11-01)

Work continues in `/workspace/VA-Studio-pcc` on the existing PCC branch, from pushed
`cacc9fb0`, integrating main `2ab0c41e946e30cc402afc8ec107a3670e399abd`.
The prior handoff status above is historical. Both pre-existing defects are fixed
on this main (CON and the transactional-mail census classification). The PR #186
merge receipt rides in `CLAUDE-PLAN.md`.

Ownership and acceptance remain the work package above: keep every capability
default-off, finish the prescribed four-shard local SQLite suite, rerun timeouts
alone, run ops tests and safeguards, obtain independent payment/auth review of
the tested SHA, then publish one PCC PR and use the required preflight/review gates.
Later lanes may prepare isolated source while acceptance and merges retain the coordinated order. No Foundation dispatch is authorized here.

The new workspace lacked PHP and sudo. An isolated local Docker container
`va-pcc-runtime-20261101` supplies Debian 13 / PHP 8.4.27 and the required extensions,
Composer, Python, ffmpeg, qpdf and Poppler. It mounts `/workspace`; checks execute as
UID/GID 1000, with worktree-owned Composer autoload metadata and the existing locked
packages. All 160 installed package versions and dist references match composer.lock.
The worktree has `.env` copied from `.env.example` and no `public/build`.
This runtime differs from the prior PHP 8.4.26 evidence; prior native MySQL results
remain historical, not a new execution claim.

Merge resolution: only Foundation's native-selection count comment conflicted.
The SQLite skip census equals main byte-for-byte, preserving its pinned prefix.
Regenerated with `python3 scripts/ci/phpunit-shards.py --shards=8
--prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json
--mysql-native-selection`: 2678 selected cases in 265 files of 10042 discovered
cases in 743 files, exit 0; both workflow comments reflect that discovery.
This is discovery only, not native test execution.


## Codex verification closeout (2026-11-01)

Tested source: `db5e4b9342f08ede28d35c49524990d7777bfbcc`, main baseline
`2ab0c41e946e30cc402afc8ec107a3670e399abd`. The committed source remained
frozen throughout the four sequential SQLite shards. Later evidence/ledger
changes are documentation only; no application, test, workflow or lockfile change
is inferred from this record.

Runtime: isolated local Docker `va-pcc-runtime-20261101`, Debian 13, PHP 8.4.27,
SQLite 3.46.1, PHPUnit 12.5.34. All 160 Composer lock references match installed
packages; each worktree owns its Composer metadata. `.env` is the example and
`public/build` is absent. This is local verification, with no provider or host call.

| Check | Actual result |
| --- | --- |
| Original full SQLite sweep | completed 10042 cases / 105104 assertions / 732 exact census skips; 3 failures, 2 errors; exits 1/2/0/1; not a full-suite pass |
| Current mount test | exit 0, 24 tests / 766 assertions |
| Independent proxy/mount selection | exit 0, 59 tests / 829 assertions |
| `tests/ops`, equipped root runtime with official ClamAV 1.5.4 | exit 0, 334 tests, no skips |
| Private-server / email preflight Python checks | exit 0, 26 / 31 tests |
| Shards / CI-scope / cadence / focused / receipts safeguards | exit 0, 52 / 27 / 14 / 53 / 61 tests |
| Composer strict validation / locked audit | exit 0, valid / no advisories |
| Native MySQL selection discovery | exit 0, 2678 cases / 265 files of 10042 cases / 743 files; not execution |
| Focused Pint, 30 changed PHP files | 28 pass; 2 baseline findings reproduced on exact main bytes |

Initial setup failures remain retained: unprivileged ops run had 36 missing
account-lookup errors and 81 tool/root skips; equipped root with Debian ClamAV
1.4.3 had one unsupported `EnableVersionCommand` failure. Installing official
ClamAV 1.5.4 in the isolated runtime resolved that compatibility problem; no test
was weakened or skipped. Initial Composer audit exit 100 was a CA-chain setup
failure; existing workspace public CAs were installed and TLS verification stayed
on. The final locked audit exited 0 with no advisories.

Pint's two findings (`bootstrap/app.php` unary/not spacing and `routes/web.php`
import order) match exact main file bytes, outside preflight's formatting selection;
this is not a full Pint pass. Native MySQL 16/103 and mutation evidence above remain
historical source-specific evidence. No new native locking/race/latency claim is made.
No Foundation, GitLab, browser, real SMTP/Stripe account, staging host or production
activation was exercised; those acceptance boundaries remain open.

Independent source decision: `independent-pcc-review-db5e4b93-DECISION.md`, separate
non-author agent `/root/pcc_payment_auth_review`, approves default-off development
source with no P0-P2, conditional on full evidence completion. Named mount and
SMTP conflicts were accepted. P3-3 documentation is corrected to SMTPS/465; P3-1
buyer-withdrawal operator regression and P3-2 composed SMTP hold behavioral coverage
are deferred to the PCC follow-up batch. Final evidence carry review and remote
PR gates remain pending.

### Full sweep and failure disposition

The original runner completed on the unchanged source from 11:29:12Z to 17:36:56Z.
It was neither restarted nor duplicated. Selection: 10042 cases in 743 files.

| Shard | Exit | Cases | Assertions | Census skips | Failures / errors |
| --- | --- | --- | --- | --- | --- |
| 1 | 1 | 2511 | 22933 | 155 | 1 / 0 |
| 2 | 2 | 2511 | 26067 | 199 | 1 / 2 |
| 3 | 0 | 2510 | 31642 | 126 | 0 / 0 |
| 4 | 1 | 2510 | 24462 | 252 | 1 / 0 |

All original case identities, ownership, counters and exact engine-skip identities
match the generated selection and committed census. The repository's standard
receipt verifier correctly rejects shards 1, 2 and 4 because their reports retain
failures; shard 3 passes it. A separate inspection preserves failed results while
checking their identities and skips. It does not convert them into passing receipts.
Safe summaries and original artifact hashes: [resumption evidence](evidence/resumption-20261101.json).
Raw logs, JUnit, inventories and process exits remain outside Git in the local
`/workspace/pcc-evidence` directory.

Four original failures/errors are runtime setup failures in actual isolated PDF
rendering: `TestContractSuccessorActivationTest::test_exact_v2_policy_captures_real_request_and_worker_preserves_one_original`,
`IsolatedContractRendererAcceptanceTest::test_two_isolated_children_preserve_every_frozen_section_in_the_same_valid_multipage_pdf`,
`TestContractIssuanceTest::test_real_isolated_renderer_can_issue_one_private_pdf_from_an_actual_paid_grant`,
and `TestContractProfileSuccessorTest::test_real_scrubbed_child_dispatches_only_the_trusted_successor_implementation`.
The initial worktree-owned Composer metadata used shared package symlinks resolving
outside the project. `IsolatedContractRenderer` correctly holds `open_basedir` to
the project and its scratch directory, so the child refused that external autoload.
An independent no-database probe with the same project restriction exited 255
with the explicit `symfony/polyfill-mbstring` denial.

Only after every original shard finished, the original vendor layout was preserved
and all 41197 files were copied byte-identically inside PCC. No symlinks or
escaping vendor realpaths remain. After `composer dump-autoload --optimize
--no-scripts`, all 41184 package files remained byte-identical and all 160 versions
and dist references matched the lock. The identical restricted autoload probe
then exited 0. No source, package version, renderer limit or isolation boundary
changed. Each of the four failed renderer cases passed alone (4 tests / 52
assertions total), followed serially by their complete classes:

| Class | Exit | Tests | Assertions | Skips |
| --- | --- | --- | --- | --- |
| TestContractSuccessorActivationTest | 0 | 4 | 39 | 0 |
| IsolatedContractRendererAcceptanceTest | 0 | 1 | 25 | 0 |
| TestContractIssuanceTest | 0 | 53 | 504 | 0 |
| TestContractProfileSuccessorTest | 0 | 16 | 29 | 0 |

The corrected direct PHPUnit commands use the same CI environment above,
`--fail-on-phpunit-warning`, `--log-junit=<receipt>`, and the named file; an individual
case adds `--filter=<exact_method>`. Four initial class invocations accidentally
used an empty filter and executed no tests (exit 1); their original outputs are
retained separately. The corrected class commands omit the filter entirely.
Neither those empty selections nor the original full failures are counted as passes.

The fifth failure is pre-existing on exact main `2ab0c41e946e30cc402afc8ec107a3670e399abd`:
`PruneExpiredCacheRowsTest::test_expired_locks_go_and_held_locks_stay_held` line 156.
It reproduces alone on PCC and in a separate exact-main worktree with its own
Composer metadata and example environment (each exit 1, 1 test / 3 assertions).
Both the test and `PruneExpiredCacheRows` are unchanged from main. The query orders
raw keys before the test removes the configured prefix: with `APP_NAME=VASEY.AUDIO`,
`live-lock` sorts before `vaseyaudio-cache-held-synthetic`. The assertion expects
the same two retained keys in the opposite order. The observed result contains
both held/live locks; this failure is in the test's prefix-sensitive comparison.
No cache assertion or production pruning code was changed here. A deterministic
prefix-independent assertion repair is assigned to the DEF-OPS follow-up batch;
the failed full and focused baseline results remain visible. Later assertions in
that failing method are not claimed exercised by these failed invocations.

This is completed full-suite execution plus classified failures and focused runtime
correction evidence. It is **not** a fresh green full-suite receipt, native MySQL
proof, final-candidate acceptance or activation authorization. No additional full
sweep was started.
