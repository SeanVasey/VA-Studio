# Dependency follow-up — October 18, 2026

Lane M2 updates two Composer packages with their strict requirements and five npm packages. All of them are minor or patch updates. It also narrows two Dependabot groups.

- **Tested source:** the lock commit `64bcf41caf41c71c471b81460b1d3239b0858e0c` on branch `harness/dependency-followup-20261018`. Its parent is `9225df8c`, which is main after PR #151.
- **Evidence commit:** the commit that follows changes only this directory and `CHANGELOG.md`.
- **Toolchain:** PHP 8.4.26, Composer 2.8.12, Node 24.22.0 and npm 11.19.1.
- **Not done:** nothing was pushed, no PR was opened, commented on or closed, and no workflow was dispatched.

**Verdict: not merge-ready as committed.** Filament 5.10.2 moves flash notifications out of the session and into a cache-backed "mailbox". Eight test files read the old session keys:
- three fail, with 8 failing cases;
- five still pass, but their notification assertions no longer check anything.

The app code does not need to change. The fix belongs in the tests, which this lane was not authorized to adapt. See [Filament 5.10 notification storage](#filament-510-notification-storage-batch-caused). Everything else in the batch passed, apart from six errors that also occur on base.

## What changes

| Ecosystem | Package | Before | After | Manifest |
| --- | --- | --- | --- | --- |
| Composer | `filament/filament` and its nine `filament/*` packages | v5.8.4 | v5.10.2 | unchanged (`^5.0`) |
| Composer | `laravel/framework` | v13.36.0 (`e5626e38`) | v13.37.0 (`685f8d6c`) | unchanged (`^13.17`) |
| Composer | `livewire/livewire` (required by `filament/support` 5.10.2: `^4.4.7`) | v4.4.6 | v4.4.7 | not direct |
| Composer | `composer/class-map-generator` (new; required by `filament/support` 5.10.2: `^1.6`) | none | 1.8.0 | not direct |
| Composer | `composer/pcre` (new; required by class-map-generator) | none | 3.4.0 | not direct |
| npm | `react`, `react-dom` | 19.3.0 | 19.3.1 | `^19.3.0` to `^19.3.1` |
| npm | `@types/react-dom` | 19.2.7 | 19.3.0 | `^19.2.0` to `^19.3.0` |
| npm | `@testing-library/user-event` | 14.6.7 | 14.6.8 | `^14.6.1` to `^14.6.8` |
| npm | `vitest` | 5.0.3 | 5.0.4 | `5.0.3` to `5.0.4` (the exact pin is kept) |

**Composer: 12 of 158 records change and 2 are added, making 160** ([delta](metadata/composer-lock-delta.txt)).
- `content-hash` is unchanged. `composer validate --strict`, `composer check-platform-reqs` and `composer audit --locked` all exit 0, and the audit reports no advisories.
- These are unchanged: `tecnickcom/tc-lib-pdf` 8.76.3, `tecnickcom/tc-lib-pdf-font` 4.4.1, `stripe/stripe-php` v21.3.2, `phpunit/phpunit` 12.5.34 and `symfony/console` v7.4.20.
- No file under `app/`, `tests/`, `routes/`, `config/`, `database/` or `resources/` changed.
- All 160 installed versions match the lock (checked against `vendor/composer/installed.json`).

**npm: 12 records change, none are added or removed, and the lockfile stays at version 3** ([delta](metadata/npm-lock-delta.txt)).
- Two records are production-scope: `react` and `react-dom`. The rest are dev-only.
- Vitest 5.0.4 pulls in `@vitest/mocker` and `@vitest/spy` 5.0.4, `chai` 6.3.0, `std-env` 4.3.0, `tinybench` 6.2.2 and `tinyexec` 1.3.1.
- It also pulls in **`obug` 2.1.4 to 3.0.0**. That is a transitive major, required by Vitest 5.0.4 itself (`"obug": "^3.0.0"`), and it is dev-only.
- `npm ci` from the new lock: exit 0, 121 packages. `npm audit --audit-level=high`: exit 0, 0 vulnerabilities. `npm ls --all`: exit 0.
- These are unchanged: `@playwright/test` 1.63.0, `@testing-library/dom` 10.4.2 and `@testing-library/jest-dom` 7.0.1.

**Dependabot (`.github/dependabot.yml`):**
- `composer-minor-patch` gains `exclude-patterns: ["tecnickcom/tc-lib-pdf", "tecnickcom/tc-lib-pdf-font"]`.
- `npm-minor-patch` adds `@testing-library/dom` and `@testing-library/jest-dom` to its exclude-patterns (deferred Codex P2 r4305283401).
- Nothing else changed. The YAML parses (`yaml.safe_load`), and the three groups read back as intended.

### Deviation from the brief: no `--with-dependencies`

The brief said to run `composer update <packages> --with-dependencies --no-scripts`. A dry run of exactly that would have added 24 unrequested updates on top of the 14 required changes. One of them is **`symfony/console` v7.4.20 to v8.1.9, a major**, which the brief's no-majors rule forbids. The others were 15 Symfony 8.0 to 8.1 minors, `league/uri*` and `league/commonmark`, `laravel/prompts`, `guzzlehttp/psr7`, `spatie/laravel-package-tools` and `eloquent-power-joins` ([dry run from the new lock](install/composer-broad-with-dependencies-dry-run-not-applied.txt)). The M1 batch hit the same `symfony/console` major.

I applied the smallest set that resolves:

```
composer update 'filament/*' laravel/framework livewire/livewire --no-scripts \
  --with filament/filament:5.10.2 --with laravel/framework:13.37.0
```

- `composer update filament/filament laravel/framework` on its own fails: `filament/actions` is fixed at 5.8.4.
- Adding `filament/*` fails on the `livewire/livewire ^4.4.7` requirement.
- Adding Livewire as well resolves.

## Upstream review

I read the upstream source diffs from Composer's VCS cache (the exact locked commits), not release-note summaries.

**Laravel 13.36.0 to 13.37.0** (35 commits, 37 `src` files):
- `Connection::disconnect()` now suppresses teardown warnings with `@`.
- `HasAttributes::setAttribute()` now clears `attributeCastCache[$key]`. `IdentityMetadataAdmission::actorId` reads that cache and refuses when `id` is in it, so the change can only make the refusal rarer. It never admits more.
- `CacheEventMutex::exists()` uses `isLocked()` when the lock supports it. This affects `withoutOverlapping(5)` on `vasey:publish-scheduled-site-release`. `SiteScheduleTest` and `SiteScheduleRunnerTest` pass. The three `SiteScheduleConcurrencyTest` races skip on SQLite by census.
- `PendingDispatch` and the queued-listener path release unique locks when a push fails.
- `Migrator::shouldSkip()` is new.
- The rest is Eloquent relation fixes, the JSON:API resource fixes and new validation and queue-console options.
- No `ManagesTransactions`, `DatabaseTransactionsManager`, container or session class changed.

**Livewire 4.4.6 to 4.4.7:** one source change, in `ImplicitlyBoundMethod` (contextual attribute parameters are resolved from the container).

**Filament 5.8.4 to 5.10.2** (`panels`: 54 `src` files; `notifications`: 7; `support`: 22):
- **Notifications now go through a `NotificationMailbox`.** It is keyed per session and stored in the cache store `config('filament.cache_store')`, which is the default store when unset; `.env.example` has `CACHE_STORE=database`. They are no longer pushed to `session('filament.notifications')`. The details are under the next heading.
- **TOTP replay protection.** The last accepted time step is now stored with `forever` under `filament:auth:app_authentication:code:<sha256>`. The legacy `md5` key is still read and written, and the update runs under a 60-second cache lock that re-checks ownership. Recovery-code consumption also re-checks lock ownership.
- **MFA login.** The encrypted "user undertaking MFA" state now binds a guard-, class- and identifier-scoped hash. During a deployment, any challenge already in flight would restart.
- **New `filament:auth` rate limiter.** It allows 6 per minute per user and applies only to Filament's signed email-verification and email-change routes.
- **Parent-resource access check.** It moved out of `CanAuthorizeResourceAccess` into the resource page hydration hook. The app defines no parent resources.
- **New Livewire component hook.** It re-resolves scoped model properties on resource pages.

### Reflection targets

`tools/reflection-targets.php` lists every `Vendor\Class::class, 'member'` pair in `app/`, resolving the `use` imports, and checks that the installed class still declares that member ([output](metadata/reflection-targets.txt)).
- There are 73 distinct pairs.
- 56 resolve to an existing property, method or constant. These include all the `ReflectionProperty` and `ReflectionMethod` targets: `Connection::{transactionsManager, beforeStartingTransaction, pdo, transactions, events, config, database, tablePrefix, readPdo, directPdo}`, `Container::{instances, bindings, aliases, offsetSet, ...}`, `DatabaseTransactionsManager::currentTransaction`, `Facade::{app, resolvedInstance}`, `Model::{attributes, original, attributeCastCache, classCastCache, ...}`, `Repository::items`, `Collection::items` and `ApiRequestor::_httpClient`.
- The other 17 are exception reason codes that only look like pairs, such as `Exception::require($x::class === Application::class, 'disabled')`.

Separately, `tools/load-app-classes.php` autoloads all 1,117 class-like files under `app/` against the new vendor tree. There were 0 errors and no incompatible-signature fatals ([output](metadata/load-app-classes.txt)).

## Verification results

All the commands are listed in [Commands](#commands). Counts are parsed from the retained JUnit files by `tools/junit-summary.py` ([outcomes](php-sqlite/outcomes.json)).

| Check | Result |
| --- | --- |
| `npx tsc --noEmit` | exit 0 |
| `npx vitest run` (full) | **68 files, 1,172 tests: 0 failures, 0 errors, 0 skips**; exit 0 |
| `npm run build`, then `scan-client-bundle.py public/build`, then `public/build` deleted | build exit 0; scan of 14 files: no hits |
| `composer validate --strict` / `check-platform-reqs` / `audit --locked` | 0 / 0 / 0 (no advisories) |
| `npm ci` / `npm audit --audit-level=high` | 0 / 0 (0 vulnerabilities) |
| Four Python safeguards | `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK |
| `vendor/bin/pint --test` | not applicable: no PHP file changed |
| PHP on SQLite, 184 files (below) | **3,061 tests, 29,132 assertions: 8 failures, 6 errors, 335 skipped** |

`public/build` was absent for every PHP run.

### PHP on SQLite

The command is the brief's CI-env wrapper with `--fail-on-phpunit-warning`. It ran one process at a time while a background sweep used two CPUs.

| Selection | Files | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- | --- |
| [reflection and container](php-sqlite/sel-reflection.txt) | 20 | 191 | 1,448 | 0 | 0 | 13 |
| [Filament / `/admin` / Livewire, part 0](php-sqlite/sel-filament-00.txt) | 41 | 837 | 9,138 | 0 | **6** | 124 |
| [part 1](php-sqlite/sel-filament-01.txt) | 39 | 753 | 4,891 | **2** | 0 | 88 |
| [part 2](php-sqlite/sel-filament-02.txt) | 42 | 720 | 10,317 | **6** | 0 | 59 |
| [cross-section](php-sqlite/sel-cross.txt) (every 13th remaining file) | 42 | 560 | 3,338 | 0 | 0 | 51 |
| **Total** | **184** | **3,061** | **29,132** | **8** | **6** | **335** |

**Selections:**
- The 122 Filament files are every `tests/**/*Test.php` (fixtures excluded) that mentions `Filament`, `/admin` or `Livewire`.
- The reflection selection covers the app classes that reflect into `Connection`, `Container`, `DatabaseTransactionsManager`, `Facade` and `Model`: member originals and grants, membership reservation and award ownership, tax committed reads, feature seals, checkout write admission and observer exclusion, suppression seal and financial candidates. It also covers scheduler and unique-notification work.
- The M1 review's P3-1 case, `MemberGrantDefinitionsTest::test_remaining_config_resolution_hook_refuses_committed_authoring_receipt`, **passes** on this base (6 assertions). I assume `ee44f54e` on main fixed it, but I did not verify that.

**Skips:** every one of the 335 skipped cases is a method listed in the reviewed SQLite census, `scripts/ci/database-sqlite-skips.json`. They are native-only races, so SQLite proves nothing about them.

**Harness interruption:** the first cross-section attempt was killed at 369 of 560 tests, when the sequential runner reached the harness's two-hour background limit. It wrote no JUnit, and no result is claimed from it ([log](php-sqlite/cross-attempt1-killed-no-junit.txt)). The chunk was then re-run in full on its own, exit 0.

### Filament 5.10 notification storage (batch-caused)

The 8 failures all come from three files:
- `SiteContentDamagedPublicationTest::test_a_damaged_publication_record_refuses_publishing_and_sends_staff_to_a_backup`
- `SiteContentUnavailableHttpTest::test_new_content_draft_reports_unavailable_published_content_instead_of_opening`
- six cases in `SiteScheduleHttpTest`: the three `..._schedule_confirmation_reports_...` data sets, the two `..._cancel_confirmation_reports_...` data sets, and `..._publish_confirmation_reports_its_release_becoming_active_meanwhile`

Each one reads `collect(session('filament.claimed_notifications', session('filament.notifications', [])))->firstWhere('title', ...)` and gets `null`.

**They pass on base.** The [base control](php-sqlite/base-control/base-control.txt) ran on a scratch `git archive 9225df8c` with the base lock installed: Laravel 13.36.0, Filament 5.8.4 and Livewire 4.4.6 ([versions](php-sqlite/base-control/versions.txt)).

**Cause:**
- `Notification::send()` now calls `NotificationMailbox::push()` instead of `session()->push('filament.notifications', ...)`.
- The only session key Filament still writes is `filament.notifications.mailbox`.
- Laravel's dotted session keys mean `session('filament.notifications')` now returns `['mailbox' => '<id>']`. I checked this with a bare `Illuminate\Session\Store`.

**Five more files read the same keys and now pass without checking anything:**
- `LicenseTemplateAuthoringActionTest:289-291` and `LicenseDraftAuthoringActionTest:295-297`: `assertIsArray` and `assertCount(1)` now hold for the mailbox array, not the notification.
- `MembershipAdministrationActionTest:229`, `BulkLicenseDraftSourceAuthoringActionTest:383-385`, `OfferDraftAuthoringActionTest:307-309` and `RightsDeclarationWriterActionTest:317`: the "private failure detail is not in any notification" assertions now search `{"mailbox":"..."}`.

These tests still also call Filament's own `assertNotified`, which reads the mailbox. Their leak-negative checks, though, no longer inspect any notification body. That is a silent weakening of private-data assertions.

**The app still emits the notifications.** An [uncommitted diagnostic](php-sqlite/diagnostic/mailbox-diagnostic-not-committed.diff) changed only the session read in the three failing files to `NotificationMailbox::flush()` plus `pull()`, ran the 8 cases, and was then reverted (`git checkout`).
- 7 passed and 1 failed ([log](php-sqlite/diagnostic/mailbox-diagnostic.txt)).
- In the one failure, the notification was found with the expected title and body. The test failed afterwards at `SiteContentUnavailableHttpTest:166`, because its later `assertNotified()` found the mailbox already drained by the diagnostic's `pull()`. That is an artifact of the diagnostic, not of the app.

**Remedy** (needs the lead's decision; not done here):
- either a reviewed test-only commit that reads notifications through `NotificationMailbox` (or only through `assertNotified`/`assertNotNotified`) in all eight files, keeping the leak-negative checks meaningful;
- or remove Filament (and with it Livewire and class-map-generator) from this batch and keep Laravel 13.37.0. That set resolves on its own.

**Deployment note for any Filament 5.10 rollout:** flash notifications now depend on the cache store being shared and persistent between requests, which the `database` store is.

### Pre-existing errors (not from this batch)

There are six errors:
- two data sets of `OrderInquiryStaffContextTest::test_actual_retained_context_entry_rechecks_authority_even_after_filament_cached_its_state`;
- four data sets of `OrderInquiryTest::test_staff_context_rechecks_authority_after_reconstruction_and_audit`.

Each throws `AuthorizationException: Staff multi-factor authentication is required for this write.` at `StaffWriteAuthority.php:122`. The path is `ReviewLicense.php:59`, reached from the test's `LicenseFixtures::approved()` fixture after the test sets the panel to require MFA.

**The [base control](php-sqlite/base-control/base-control.txt) fails identically:** the same six cases, the same exception and the same throw site, on 13.36.0 and 5.8.4.

The likely cause is `11ab36d7` (2026-10-15, "Require current staff enrollment in catalog, license and exclusive writers"). It postdates these tests (2026-10-09), and their fixtures approve a license with an unenrolled reviewer. I did not prove that and did not change anything. It is recorded for the pre-Foundation sweep.

## Untested conditions

- **Coverage:** 184 of 682 PHP test files, and SQLite only. There was no native MySQL run, so the 335 census-skipped races and the M1 native case (`MemberGrantActivationIntegrationTest`) remain unproven on MySQL.
- **Not run:** browser, WebKit or Playwright; hosted CI; Foundation; the `persistent-content` Node tests. The Filament 5.10 Alpine changes (notification hover and focus pause, `submitOnCompletion` on the TOTP input) and the React 19.3.1 runtime were exercised only through jsdom and the build.
- **Filament assets:** `php artisan filament:upgrade` (the `post-autoload-dump` script) was not run because `--no-scripts` was used. The published Filament assets under `public/js|css|fonts/filament/` are gitignored and absent here. `php artisan package:discover` was run, and its manifest is identical to the one before the update.
- **No fresh 160-package Composer install.** The vendor tree was updated in place (see the next section).
- No upstream changes other than the diffs named above were audited.

## Failures and deviations retained

- The broad `--with-dependencies` resolution was not applied (above).
- **The first `composer update` exited 1** after writing the lock ([log](install/composer-update-attempt1-gitdir-missing.txt)):
  - `vendor/laravel/framework` had no `.git` directory, so Composer could not update the source install in place. This is the same condition M1 recorded.
  - Only the 12 gitignored vendor directories being replaced were removed, and `composer install --no-scripts` then installed exactly the new lock (exit 0, [log](install/composer-install.txt)).
  - The install log omits 69 pre-existing PSR-4 warnings for `tests/Fixtures/paid-development/*`.
- **Base control copy:** it was built in the scratch directory, never in another worktree. Its vendor tree was hard-linked from this worktree, with `vendor/composer`, `vendor/bin` and the top-level files copied so that the hard links were broken. The replaced packages were deleted from the copy before the base install, and checksums confirmed this worktree's `vendor/autoload.php` and `vendor/composer/*` were unchanged afterwards.

## Superseded Dependabot PRs

If the batch is accepted with Filament:

| PR | Covered by this batch |
| --- | --- |
| #142 | the Composer parts it asks for (Filament and Laravel), **not** the two PDF packages |
| #143 | all of it |
| #144 | all of it |

#145 (Playwright 1.64.1) is held for the WebKit backport. I did not check this PR-to-package mapping against GitHub.

## Rollback

Restore `composer.lock`, `package.json`, `package-lock.json` and `.github/dependabot.yml` from `9225df8c` and reinstall. No migration, configuration or app code changed.

## Commands

Run in `{worktree}` with `COMPOSER_ALLOW_SUPERUSER=1` and `. /opt/nvm/nvm.sh && nvm use 24`. Logs have their scratch and worktree paths replaced and their ANSI colour codes removed.

| Command | Exit | Output |
| --- | --- | --- |
| `composer update filament/filament laravel/framework --with-dependencies --no-scripts --dry-run --no-install --with ...` | 0 | `install/composer-broad-with-dependencies-dry-run-not-applied.txt` |
| `composer update 'filament/*' laravel/framework livewire/livewire --no-scripts --with filament/filament:5.10.2 --with laravel/framework:13.37.0` | 1 (lock written) | `install/composer-update-attempt1-gitdir-missing.txt` |
| remove the 12 vendor dirs; `composer install --no-scripts` | 0 | `install/composer-install.txt` |
| `php artisan package:discover` | 0 | manifest identical to before |
| `composer validate --strict` / `check-platform-reqs` / `audit --locked` / `outdated --direct --locked` | 0 / 0 / 0 / 0 | `install/`, `metadata/` |
| edit `package.json`; `npm install`; `npm ci`; `npm audit --audit-level=high`; `npm outdated` | 0 / 0 / 0 / 1 (updates remain) | `install/`, `metadata/` |
| `npx tsc --noEmit`; `npx vitest run --reporter=default --reporter=junit` | 0 / 0 | `frontend/` |
| `npm run build`; `python3 scripts/ci/scan-client-bundle.py public/build`; `rm -rf public/build` | 0 / 0 / 0 | `frontend/` |
| `python3 scripts/ci/{test-phpunit-shards,test-ci-scope,test-workflow-cadence,test-focused-tests}.py` | 0 each | `preflight/python-safeguards.txt` |
| `php tools/reflection-targets.php`; `php tools/load-app-classes.php` | 0 / 0 | `metadata/` |
| `tools/run-php.sh reflection filament-00 filament-01 filament-02 cross` | 0 / 2 / 1 / 1 / killed; re-run `cross`: 0 | `php-sqlite/` |
| base control (`--filter "$(cat php-sqlite/base-control/filter.txt)"` on the five files) | 2 | `php-sqlite/base-control/` |
| mailbox diagnostic (applied, run, reverted) | 1 | `php-sqlite/diagnostic/` |

The PHP wrapper:

```
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array \
  SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true php -d memory_limit=512M \
  -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --fail-on-phpunit-warning --log-junit <file>.xml <files>
```
