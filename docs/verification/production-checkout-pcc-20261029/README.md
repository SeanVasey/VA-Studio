# Lane PCC work package: production commerce composition, default-off

Status: **implemented on branch `harness/production-checkout-pcc-20261029`; not pushed; independent payment/auth review not yet
done.** The work-package statement (sections Split to Tests) was committed first (`071d9f80`). Cut
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
4. "Real mail" in production means `MAIL_MAILER=smtp` with a bounded host, port 465 or 587, a sender address and a username,
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
then the PCC-b docs and this results section.

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

RESULTS_PLACEHOLDER

### Mutation checks

Each mutation was applied to the file, the named tests run, and the file restored
(`/tmp/.../pcc-ev/mutate.py`, kept outside the repository):

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

No engine-skipping test method was added, so `scripts/ci/database-sqlite-skips.json` and the native selection are unchanged.
No locking or race claim is made: the operator command reuses `HostedCheckout::record()`/`uncertain()`, whose concurrency rests
on the existing unique keys (session per intent, payment per intent, observation sequence per intent). Native MySQL was not run.

### Named conflicts

- `config/production-identity-smtp.php` was in the template test's code-literal family ("reads no environment"). The brief asks
  for SMTP settings through configuration with documented variables; the file now reads `PRODUCTION_IDENTITY_SMTP_*` inside a
  closure that runs only when the literal `$enabled` is true, and the test pins that instead. Activation stays a code change.
- The reviewed mount pattern admits only local/testing; this gate also admits production (assumption 1). Reviewers should
  decide whether production admission belongs in the gate now or in the later activation change.

### Untested conditions

- No real Stripe account, live key, SMTP provider, CDN or host; every provider fact is the synthetic fixture.
- Native MySQL 8.4, browser, Foundation CI and GitLab were not run. The full PHP suite was run on SQLite only as recorded above.
- The production environment cases boot the application with `env=production` inside PHPUnit; a real production boot with a
  config cache and `route:cache` was not exercised (the runtime seam re-checks the gate on every request for a stale table).
- The operator command has no sweep mode; recovery is one order at a time.
- With a non-empty `TRUSTED_PROXIES`, global seams prepended by packages (Inertia) run before `ExplicitTrustProxies`; they do not
  read the client address.
