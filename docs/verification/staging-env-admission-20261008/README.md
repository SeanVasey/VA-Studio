# Staging environment admission (Lane B2), 2026-10-08

- **Branch:** `harness/staging-env-admission`, WIP `2ec1c559` from `main` `89e3e61f`; integrated with `main` `49489697` (`cc4e62b`) and `e5e500ca` (`5e78202`) and completed by the Claude Code harness on 2026-10-09.
- **Sensitivity:** authentication (staff MFA) and payment/commerce admission. Independent review is required before merge (AGENTS.md).
- **Scope:** environment admission and staff MFA only. No receipt, guard, schema, money or evidence logic changed. Each gate keeps its default-off flag, Stripe `test` mode check, account and credential-shape checks.
- **Not done:** no MySQL or browser run; no host.

## What changed

1. `App\Support\Environment\TestEnvironment` is the single admission point.
   - `admitsTestCommerce()` is true for exactly `local`, `testing` and `staging`. It uses an exact, case-sensitive `in_array`, so `staging-eu` and `Staging` are refused.
   - `requiresStaffMfa()` is true for `production` and `staging`.
   - `refusesProductionOnly()` is true in `staging`.
   - `isStaging($captured)` compares a captured string without resolving the container. The reflection-reading membership policies use it.
2. Every class (a) gate below calls `admitsTestCommerce()` in place of `environment('local', 'testing')`. The webhook's former `('local', 'testing', 'staging')` list also goes through the helper, with no change in behaviour.
3. The admin panel requires MFA when `requiresStaffMfa()` is true (`AdminPanelProvider`). Before this change it required MFA only when `isProduction()` was true.
4. Staging refuses production-only paths that it previously admitted because it is "not local/testing":
   - production customer identity (`IdentityPolicy`) and the production SMTP capability (`SmtpIdentitySettings::make`);
   - live production-checkout funds (`ExecutionContextV1`), mirrored by the read-only `StripeCapabilityPreflight` `funds_mode_environment` check;
   - verified-production provenance in `Production\MembershipPolicy` and `MemberGrantPolicy`.
5. `vasey:doctor` (`InstallationReport`):
   - reports `profile` (`production`, `staging` or `development`);
   - applies the HTTPS, debug-off and secure-cookie check (`production_settings`) to staging as well as production;
   - adds a required `staging_test_mode_only` row in staging only. That row requires `STRIPE_MODE=test`, a blank or `sk_test_` key, no live production-checkout funds or live key, and no production customer identity.
   - Production output is unchanged apart from the added `profile` key.
6. The private-server preflight gains `--profile=staging`; `--profile=production` is the default and is unchanged. The staging profile:
   - requires `APP_ENV=staging` and every hosted baseline;
   - lets the seven test switches be `true` or `false` and nothing else;
   - admits populated test policies and credentials;
   - checks credential shapes and refuses any `sk_live_` or `rk_live_` value anywhere in the file;
   - requires a blank `PRODUCTION_CHECKOUT_FUNDS_MODE`.
7. Docs updated: `CHANGELOG.md`, `docs/hosted-test-checkout.md`, `docs/test-payment-processing.md`, `docs/private-server-readiness.md` and `docs/private-server-preflight.md`.

## Gate table

Locations are `file:line` on `main` `89e3e61f`. Searched patterns: `environment(`, `isLocal`, `isProduction`, `App::environment`, `env('APP_ENV'`, `config('app.env'`, and captured-environment `in_array(..., ['local', 'testing'])`. Searched trees: `app/`, `config/`, `routes/`, `bootstrap/`, `resources/js`, `database/seeders`, `scripts/dev` and `scripts/ops`.

- `routes/`, `bootstrap/`, `resources/js` and `database/seeders` contain no gate.
- `config/app.php:29` is only the `APP_ENV` source.

### (a) Test-commerce or test-capability admission: now `local`, `testing` or `staging` through the helper

| Gate | Reason |
| --- | --- |
| `app/Domain/Commerce/Checkout/CheckoutPolicy.php:14`, `:35` | Hosted test checkout and its account. Also requires Stripe `test` mode. |
| `app/Domain/Commerce/Orders/OrderPolicy.php:13` | Nonbinding test order policy, part of the chain. |
| `app/Domain/Commerce/PricingPolicy.php:20` | Test pricing policy (`scope: test`), part of the chain. |
| `app/Domain/Commerce/Inventory/InventoryPolicy.php:12` | Test inventory reservations, part of the chain. |
| `app/Domain/Commerce/ComparePricingSettlement.php:51` | Settlement comparison for the test checkout. The chain cannot complete without it. |
| `app/Domain/Commerce/PromotionPolicy.php:20`, `PromotionUsage.php:165`, `PromotionAdministration.php:173`, `app/Filament/Resources/TestPromotionResource.php:38`, `:48` | Test promotion campaigns used inside test quotes, and their staff administration. |
| `app/Domain/Commerce/Payments/StripeSdkCheckoutGateway.php:131` | The real Stripe SDK client for the test chain. It still requires `mode=test` and an exact `sk_test_` key, and refuses `rk_`, `pk_` and `sk_live_` keys. |
| `app/Domain/Commerce/Payments/VerifyStripeWebhook.php:20` | Already admitted staging. Now routed through the helper with no change in behaviour. Still requires `mode=test` and `livemode:false`. |
| `app/Domain/Commerce/Payments/PaymentProcessingPolicy.php:12` | Test payment processing. Also requires `mode=test`. |
| `app/Domain/Commerce/Finalization/FinalizationPolicy.php:19` | Test order finalization. Also requires `mode=test`. |
| `app/Domain/Commerce/UnpaidRelease/UnpaidReleasePolicy.php:21` | Test unpaid-resource release, a recovery step in the chain. Also requires `mode=test`. |
| `app/Domain/Commerce/Operations/TestPaymentExceptionOperations.php:171` | Staff review of test payment exceptions. Also requires `mode=test` and MFA, which is now required in staging. |
| `app/Domain/Contracts/ContractIssuancePolicy.php:35` | Test contract issuance. Also requires `mode=test`. |
| `app/Domain/Delivery/ActivationPolicy.php:21` | Test fulfillment activation. Also requires `mode=test`. |
| `app/Domain/Delivery/TestAccessPolicy.php:23` | Test owner delivery access. Also requires `mode=test`. |
| `app/Domain/Delivery/PrepareTestDeliveryStream.php:18` | Test owner delivery stream. Its `test-only` scan scope stays testing-only (`:84`). |
| `app/Domain/Customers/CustomerAccessPolicy.php:9` | Synthetic test customer accounts. |
| `app/Domain/Customers/Preferences/LocalConsentRuntime.php:9` | Test consent grants for synthetic customers. |
| `app/Domain/Notifications/TransactionalNotificationPolicy.php:22` | Test order-ready notices, private capture only. |
| `app/Domain/Inquiries/OrderInquiry.php:196` | Test customer order inquiries. |
| `app/Domain/Services/Projects/ServiceProjectPolicy.php:9` | Test service projects. |
| `app/Domain/Memberships/MembershipPolicy.php:27` | Synthetic memberships ("no production enablement path"). |
| `app/Domain/Grants/Free/FreeGrantPolicy.php:9`, `:30`, `TestFreeGrantIdentity.php:77`, `FreeGrantDefinitions.php:18`, `app/Http/Controllers/FreeGrantController.php:146` | Test free grants for synthetic customers. |

### (b) Development-only conveniences and production-lane rehearsals: still explicitly local/testing, refused in staging

| Gate | Reason |
| --- | --- |
| `app/Support/SupportAttachmentUi.php:10`, `app/Domain/SupportAttachments/FixtureAttachmentPolicy.php:20` | Support-attachment fixtures. |
| `scripts/dev/private-alpha-bootstrap.php:33`, `:81`, `scripts/dev/persistent-content-bootstrap.php:130`, `:205`, `scripts/ops/persistent-content-upgrade.php:110`, `:286` | Private alpha and persistent-content launchers (`local` only). |
| `app/Domain/Customers/ProductionIdentity/IdentityPolicy.php:29` (rehearsal branch), `Notifications/SmtpIdentitySettings.php:50`, `:67` (rehearsal branch), `Notifications/LoopbackSmtp.php:34` | Production-identity synthetic rehearsal over loopback SMTP. |
| `app/Domain/Commerce/ProductionCheckout/ExecutionContextV1.php:59` (test funds), `Readiness/StripeCapabilityPreflight.php:59` (test funds) | Production-checkout synthetic rehearsal. |
| `app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutPolicy.php:22`, `TaxExecutionContext.php:56` | Production tax-checkout rehearsal. |
| `app/Domain/Memberships/Billing/BillingPolicy.php:41` (captured env, read at `:84`/`:107`) | Production membership billing rehearsal. |
| `app/Domain/Memberships/Production/MembershipPolicy.php:42`, `app/Domain/Grants/Member/MemberGrantPolicy.php:38` (rehearsal branch) | Production membership and member-grant rehearsal. |
| `app/Domain/Grants/ProductionFree/ProductionFreeGrantPolicy.php:32` | Production free-grant rehearsal. |

#### Testing-only fixture transports and synthetic scanners (unchanged; never staging)

- `StripeSdkBillingGateway.php:33`, `:129`
- `OwnAccountStripeGateway.php:24`
- `StripeCapabilityProbe.php:50`
- `StripeCapabilityPreflight.php:99`
- `FixtureAttachmentPolicy.php:46`
- `DeliveryAssets.php:97`
- `PrepareTestDeliveryStream.php:84`
- `FreeGrantSources.php:38`
- `ScanEngines.php:11`

### (c) Production-only: unchanged, except staging is now refused or treated as hosted

| Gate | Reason |
| --- | --- |
| `app/Providers/Filament/AdminPanelProvider.php:77` | MFA required in production **and staging** (changed). |
| `app/Support/Diagnostics/InstallationReport.php:81` | Hosted settings check for production **and staging** (changed; stricter). |
| `IdentityPolicy.php:29` and `SmtpIdentitySettings.php:67` (production branch) | Production identity is no longer admitted in staging (changed). |
| `ExecutionContextV1.php:59` and `StripeCapabilityPreflight.php:59` (live branch) | Live funds are no longer admitted in staging (changed). |
| `Production\MembershipPolicy.php:42` and `MemberGrantPolicy.php:38` (verified-production branch) | Refused in staging (changed). |
| `app/Domain/Commerce/Readiness/ProductionCommerceReadiness.php:63` | Production readiness report (unchanged; staging reports not production). |
| `app/Support/StorefrontMetadata.php:52`, `:69`; `app/Http/Controllers/PublicDiscoveryController.php:16`, `:30`, `:42`, `:67`; `PublicPagesSitemapController.php:21`; `DiscoveryTrackSitemapController.php:19`; `app/Domain/SiteBuilder/PublicPagesSitemap.php:12` | Search indexing, sitemaps and `robots.txt` are production-only. Staging stays `noindex` and `Disallow: /` (unchanged). |

### (d) Not admission gates: the environment name is bound into evidence

These are unchanged:

- `CurrentEligibleTrackSnapshot.php:251`
- `SitemapConfiguration.php:25`
- `ListeningEvidence.php:123`
- `ServiceProjectAttachmentSourceV1.php:148`, `:183`
- the reflection readers `BillingPolicy.php:107`, `Production\MembershipPolicy.php:94`, `MemberGrantPolicy.php:84` and `ProductionFreeGrantPolicy.php:64`

## Completion on 2026-10-09 (after integrating #62, #63, #65 and #56)

- **Profile validator** (`scripts/ops/validate-test-commerce-profile.php`): `runtime.app_env_local` became
  `runtime.app_env_admitted`, exactly `staging` or `local` (never `testing` or `production`). A complete staging profile
  passes every real policy check; `Staging`, `staging-eu` and `stage` are refused.
- **Staging kit:** `ops/staging/env.staging.example` and `test-commerce/env.test-commerce.example` default to
  `APP_ENV=staging`; `provision.sh --app-env` defaults to `staging` (`local` remains the accepted interim profile);
  `ops/staging/README.md` and `docs/ops/staging-runbook.md` describe required staff TOTP in staging.
- **D1 under staging:** `vasey:rights-scope` writes are gated by `InventoryPolicy`, which now admits staging; its refusal
  text names staging. A new staging variant of the D1 link test proves register → link → `PrepareOrder` under
  `staging`. Staging only accepts ClamAV scan evidence (`ScanEngines`), so the fixture media there carry a synthetic
  ClamAV-engine stand-in; under the test-only scanner, staging correctly reports the selection unpublishable.
- **Free256 frozen pin:** `ActivationPolicy.php` is pinned by `ProductionFreeGrantFrozenBytesTest`. Only `account()`'s
  environment gate changed; family 256 (via `PreparedDeliveryStream` and `DeliveryAssetFiles`) uses only
  `outsideTransactions()`, which is unchanged. The pin is updated with that note; this needs the independent reviewer's
  re-review against family 256 (approved; see below).
- **Paid252 (#56):** `PaidGrantPolicy` enabled its operative lane in every environment except local/testing, so staging
  would have admitted it when configured. `enabled()` and `provePure()` now refuse it in staging (red recorded first).
- **Census made exhaustive:** the source census now requires every `local`/`testing` gate in `app/` to be classified;
  this surfaced the paid gate and `ServiceProjectAttachmentSourceV1` (kept local/testing only).

- **Route cache across the `local` → `staging` switch (review C1):** the panel compiles its MFA page middleware into
  the route cache from `APP_ENV`. A same-SHA deploy after a Forge `.env` change runs `ctl refresh`, whose configure step
  rebuilt only the configuration cache, so a route cache built under `local` kept serving staging without page-level
  MFA. `cmd_configure` now also runs `route:cache` as the app after `config:cache`; the runbook gains a host check
  (`route:list --path=admin/tracks`). Shown with the ops fixture (red, then green) and against the real application
  (`evidence/configure-route-cache-real.txt`).

## Independent review

`independent-review/DECISION.md`: **APPROVE WITH CONDITIONS** for a development merge of `56efbb7a`. It authorizes no
production deployment, live payments or keys, Paid252 operative activation, production memberships or member grants,
production customer identity, or DNS/cutover.

- **C1 (Medium):** fixed as above.
- **C2 (Low, follow-up, not taken here):** `PromotionAdministration` and `ManageRightsScope` writes complete for an
  unenrolled admin when called below the panel in staging. Most production catalog writes (`PublishOffer`,
  `PublishLicense`, `SaveTrackMetadata`, ...) rely on the panel middleware the same way; adding the domain-level
  `AdminMultiFactor` check belongs in its own reviewed change.
- **Inherited failure:** `ProductionFreeGrantFrozenBytesTest::test_no_paid_lane_or_old_free_family_file_is_copied_or_imported`
  failed on `main` since #56 and identically at `56efbb7`; it is fixed by M-16 (#67, `af232a8`), not by B2.
- Confirmed: exact-match helper semantics, the exhaustive gate census, staging refusals (live mode and keys, livemode
  webhooks, production-checkout funds, production identity, verified membership/member-grant provenance, the Paid252
  operative lane), the Free256 `ActivationPolicy` re-pin, and the D1 stand-in scanner.

## Results

PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory, `public/build` absent.

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Red baseline: B2's staging tests on `main` | `main` `e5e500ca` product + B2's `RightsScopeCommandTest`, `StagingOperatorMfaTest`, `TestCommerceProfileValidatorTest` | D1 staging variant fails (`ORDER_POLICY_UNAVAILABLE`); staging MFA not required (2 failures); staging profile refused; rc 1 each | `evidence/red-on-main-e5e500ca.txt` |
| Red: paid operative lane in staging | `5e78202` + new test, `PaidGrantPolicy` unchanged | staging `enabled()` true; rc 1 | `evidence/paid-operative-staging-red.txt` |
| B2 affected selection (96 files by class reference), 3 shards | `cc4e62b` + working tree, before the validator, template, D1 and paid changes | 1,838 tests; only failure the Free256 `ActivationPolicy` pin (since re-pinned); skips are MySQL-only | see final run below |
| Ops kit tests (`python3 tests/ops/test_*.py`, 13 files) | after the `provision.sh` default change | 74 tests OK | — |
| Private-server preflight self-test | after integration | 20 tests OK | — |
| Reviewer: affected selection, 115 files, 4 shards | `56efbb7` | 1,848 tests, 33 MySQL/POSIX skips, 1 failure (the inherited frozen-bytes one above) | `independent-review/evidence/affected-shard-*.txt` |
| Red: configure rebuilds the route cache (C1) | `56efbb7` + updated ops test | 1 failure: only `config:cache` ran; rc 1 | `evidence/configure-route-cache-red.txt` |
| Green: all ops kit tests after the C1 fix | working tree | 13 files, 74 tests OK | `evidence/configure-route-cache-green.txt` |
| Real route cache across `APP_ENV` | working tree | built under `local`, served under `staging`: no MFA middleware; rebuilt under `staging`: present | `evidence/configure-route-cache-real.txt` |
| Ops fixture after merging main `b9cb7b67` (#67): `test_staging_protected_configuration` red on main, its candidate env lacking the newly required `VASEY_PHP_CLI_BINARY` | `e07da62` (#67 head) | 1 failure, rc 1 | `evidence/ops-protected-configuration-red-main.txt` |
| Same with the fixture supplying the running CLI PHP | integrated head + fix | 3 tests OK | `evidence/ops-protected-configuration-green.txt` |
| All ops kit tests + private-server preflight self-test | integrated head + fix | 13 files, 74 tests OK; preflight 20 OK | `evidence/final-ops-tests.txt` |
| Final affected selection | FINAL_PLACEHOLDER | FINAL_PLACEHOLDER | `evidence/final-*.txt` |

## Not tested

MySQL (race and native schema cases skip on SQLite by design), browser, a real staging host, Stripe.
