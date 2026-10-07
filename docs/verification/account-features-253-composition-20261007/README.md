# Account features 253: composition, review and root config (2026-10-07)

Branch `harness/account-features-253` on integrated base `d20d4394`.

| Commit | Content |
| --- | --- |
| `709fbd45` | `chore(features): compose frozen account features 253 onto integrated identity`: the 39 owned paths, byte-exact from `3cecea69`, plus `source-map.json` |
| `3946afda` | `feat(features): ship closed default-off production-account-features parent`: the reviewed composed commit |
| (this commit) | evidence, adversarial tests, `DECISION.md` |

Decision: **APPROVE WITH CONDITIONS** for a development merge. See [DECISION.md](DECISION.md). The native MySQL 8.4 deadline condition C1 blocks activation and final acceptance.

## Results

| Selection | Engine | Result |
| --- | --- | --- |
| Owned 253 (`tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures`) | SQLite | 86 tests / 766 assertions: 83 pass, 3 native-only skips (equal to the author's receipt) |
| Owned 253 Pint (packet selection) | n/a | passed |
| Owned 253 plus root config test | SQLite | 89 / 831: 86 pass, 3 skips |
| Identity adapters, identity, registration and SMTP binding | SQLite | 129 / 752: 120 pass, 9 native-only skips |
| Adversarial review (2 tests, 6 data sets) | SQLite | 6 / 63 pass |
| `ProductionFeatureNativeAdmissionTest` (the 3 native-only cases) | MySQL 8.4.11, isolated | 3 / 65 pass |
| Checkpoint filter plus final postcommit case (10 cases) | MySQL 8.4.11, isolated | 1 pass / 9 fail-closed 503 at `initialize` (deadline, C1) |
| Adversarial review | MySQL 8.4.11, isolated | inconclusive: 6 fail-closed 503 at fixture `initialize` (C1) |

Commands (SQLite):

```sh
php vendor/bin/phpunit tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures --log-junit composed-sqlite.junit.xml
php vendor/bin/pint --test app/Domain/Customers/ProductionFeatures config/production-customer-listening.php config/production-customer-preferences.php database/migrations/2026_10_07_253000_production_account_features.php tests/Feature/ProductionFeatures tests/Unit/ProductionFeatures tests/Support/ProductionFeatureFixtures.php
php vendor/bin/phpunit tests/Feature/ProductionAccountFeatures
php vendor/bin/phpunit docs/verification/account-features-253-composition-20261007/adversarial/AccountFeatures253AdversarialReviewTest.php
```

Native: use the same commands, with `APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3407 DB_DATABASE=vaseyaudio_features253 DB_USERNAME=root DB_PASSWORD=ci-only-password DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`. Run against a MySQL server that holds **only one** VA-Studio schema; root migration 238 scans triggers across the whole server (see `invalid-contention/README.md`). The native diagnosis receipts are in `native-diagnosis/`.

## Root configuration supplied

`config/production-account-features.php` ships this closed parent:

```php
['enabled' => false, 'provenance' => null, 'versions' => [
    'listening_library' => 'production-listening-library-identity-v1',
    'consent_preferences' => 'production-consent-preferences-identity-v1',
]]
```

The note config (`['v2_promotion_enabled'=>false,'v2_rollout_review_reference'=>null]`) and the purpose config (`['grants_enabled'=>false,'email_marketing'=>null]`) are the frozen 253 files, unchanged. `tests/Feature/ProductionAccountFeatures/ProductionAccountFeaturesDefaultConfigurationTest.php` proves the following:

- The booted parents equal the shipped files exactly and are plain arrays, with no objects or PHP references.
- The raw source reader admits the shipped parent.
- In a `production` environment, every feature refuses, both through the policy and through `forRequest`, even when a production identity is enabled. `service_projects` stays refused when the parent is bound.
- Through the real entrypoints, the shipped defaults refuse note promotion (503) and grants (422) and project `unknown`/`canGrant:false`. Restoring the shipped parent withdraws both features before any module work.

## Proposed HTTP grammar (design note only; root mounts it)

Nothing is mounted here. This is a proposal for root's integration step, built on the packet's API:

- Middleware: `web` and the `customer` guard, plus the mandatory production identity session marker (`_production_customer_identity`). Requests are JSON only. Throttles follow the existing `customer-pages`, `customer-listening`, `customer-listening-export` and `customer-preferences` buckets. Body limits are 16 KiB for listening commands and 4 KiB for everything else; the read/export client cap is 3 MiB.
- Each request resolves its identity with `ProductionAccountFeatureAccess::forRequest($request, '<server-fixed feature>')`. The feature is fixed per route and never taken from client input.

| Method and path (name) | Call |
| --- | --- |
| `GET /account/listening-library` (`customer.production.listening-library.show`) | `ProductionListeningLibrary::read` |
| `POST /account/listening-library/initialize` (body exactly `{}`) | `::initialize` |
| `POST /account/listening-library` (closed command grammar) | `::change` |
| `POST /account/listening-library/export` (`{"version": <int>}`) | `::export($identity, $version)` |
| `GET /account/communication-preferences` | `ProductionConsentPreferences::read` |
| `POST /account/communication-preferences/initialize` (body exactly `{}`) | `::initialize` |
| `POST /account/communication-preferences` (grant or withdraw grammar) | `::change` |

- Controllers catch `IdentityException`, `ListeningException`, `ConsentException` and `ProductionFeatureException`. They return only `{status, reload: true}`, with no internal IDs, messages or traces.
- A 503 after a write means the outcome is unknown. The client must GET before sending a fresh versioned intent. The server never retries or resets.
- Root decides whether these replace the existing test-account controllers on the same paths (`customer.test_accounts_enabled` is false in production) or use a distinct prefix. Do not let both grammars answer the same path.
- `ProductionConsentWithdrawalReader` is never routed. It is server-only and used inside `ProductionFeatureOperation::run` (DECISION condition C2).

## Open items

1. **C1, native deadline (blocker for activation and acceptance):** about 15.5k prepared statements per operation exhaust the unchanged 10 s budget on MySQL 8.4.11 on this host. The owners need to reduce dictionary-admission round-trips without weakening floors (part of the volume is in identity code), then rerun the native selection.
2. **254 production suppression:** fresh branch after reviewed 253. Readers run only inside the sealed operation (C2), and the evidence is never serialized or logged (C3). Never use 251 lineage.
3. **Email operations preparation:** canonical identity, SMTP and history composition `519170f9`. Provider bindings, credentials, DNS and deployment need Sean's explicit authorization.
4. **HTTP mount:** root integration per the grammar above, plus composed private HTTP tests and an integration review.
5. Native verification on MySQL 8.4 for the full owned successor selection, and the store-wide final matrix, remain open.
