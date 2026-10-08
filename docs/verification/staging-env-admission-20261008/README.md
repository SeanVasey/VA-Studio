# Staging environment admission (Lane B2), 2026-10-08

- **Branch:** `harness/staging-env-admission`, from `main` `89e3e61f`. Uncommitted when this record was written; the integration owner commits.
- **Sensitivity:** authentication (staff MFA) and payment/commerce admission. Independent review is required before merge (AGENTS.md).
- **Scope:** environment admission and staff MFA only. No receipt, guard, schema, money or evidence logic changed. Each gate keeps its default-off flag, Stripe `test` mode check, account and credential-shape checks.
- **Not done:** no commit, push, GitHub action, MySQL run (shared `:3306` untouched) or browser run.

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

## Results

RESULTS_PLACEHOLDER
