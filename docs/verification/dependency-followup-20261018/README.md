# Dependency follow-up — October 18, 2026

Lane M2 moves one Composer package and five npm packages, and narrows two Dependabot groups. Every move is patch or minor.

| | |
| --- | --- |
| **Tested source** | `ad6ee2a915f72529b3c2d327ffdb2648e775cd47` on branch `harness/dependency-followup-20261018`. Its tree is `origin/main` at `97e865e9` (PRs #152 and #153) plus this lane's lock, manifest and `dependabot.yml` changes. |
| **Evidence commit** | The commit that follows changes only this directory and `CHANGELOG.md`. |
| **Toolchain** | PHP 8.4.26, Composer 2.8.12, Node 24.22.0, npm 11.19.1. |
| **Not done** | Nothing was pushed, no PR was opened, commented on or closed, and no workflow was dispatched. |

**Filament 5.10.2 is deferred to lane M3.** The lead decided this after the first round of evidence: Filament's new notification storage breaks or empties eight test files, which need a reviewed migration. See [Filament 5.10.2: deferred to lane M3](#filament-5102-deferred-to-lane-m3).

**History.** The branch history keeps that first round:

| Commit | What it is |
| --- | --- |
| `64bcf41c` | Lock with Filament |
| `07863d9d` | Its evidence |
| `d3a54c02` | Merge of `origin/main` |
| `ad6ee2a9` | Rebuilds `composer.lock` from main's lock with Laravel only |

**The final tree carries no Filament change.**

## What changes

| Ecosystem | Package | Before | After | Manifest |
| --- | --- | --- | --- | --- |
| Composer | `laravel/framework` | v13.36.0 (`e5626e38`) | v13.37.0 (`685f8d6c`) | unchanged (`^13.17`) |
| npm | `react`, `react-dom` | 19.3.0 | 19.3.1 | `^19.3.0` to `^19.3.1` |
| npm | `@types/react-dom` | 19.2.7 | 19.3.0 | `^19.2.0` to `^19.3.0` |
| npm | `@testing-library/user-event` | 14.6.7 | 14.6.8 | `^14.6.1` to `^14.6.8` |
| npm | `vitest` | 5.0.3 | 5.0.4 | `5.0.3` to `5.0.4` (exact pin kept) |

### Composer: exactly one of 158 records changes ([delta](metadata/composer-lock-delta.txt))

- **Only `laravel/framework` changes.** The record's own `require-dev` and `suggest` raise `intervention/image` from `^4.0` to `^4.2`, which is not installed. Nothing is added or removed, `content-hash` and every other top-level key are unchanged, and `git diff origin/main -- composer.lock` touches only that record.
- **Checked unchanged against main:**
  - all ten `filament/*` packages (v5.8.4)
  - `livewire/livewire` (v4.4.6)
  - `tecnickcom/tc-lib-pdf` (8.76.3) and `tecnickcom/tc-lib-pdf-font` (4.4.1)
  - `stripe/stripe-php` (v21.3.2)
  - `phpunit/phpunit` (12.5.34)
  - `symfony/console` (v7.4.20)
- **Not in either lock:** `composer/class-map-generator` and `composer/pcre`.
- **Installed versions match:** all 158 installed versions match the lock (`vendor/composer/installed.json`).
- **Code:** no file under `app/`, `tests/`, `routes/`, `config/`, `database/` or `resources/` differs from main.

**Commands.** `composer update laravel/framework --with laravel/framework:13.37.0 --no-scripts --no-install` ran on a copy of main's `composer.lock`. Its dry run shows "1 update" ([dry run](install/composer-update-laravel-dry-run.txt), [lock run](install/composer-update-laravel-lock.txt)). `composer install --no-scripts` then removed `composer/pcre` and `composer/class-map-generator` and took Livewire and the ten Filament packages back down to main's versions ([log](install/composer-install.txt)). `--with-dependencies` was not used. Here it is not needed, and it would pull `symfony/console` 8 ([first-round dry run](filament-deferred-m3/install/composer-broad-with-dependencies-dry-run-not-applied.txt)).

### npm: 12 records change, none added or removed, lockfile version 3 ([delta](metadata/npm-lock-delta.txt))

- **Production scope:** `react` and `react-dom` 19.3.1.
- **Dev only:**
  - `@types/react-dom` 19.3.0 and `@testing-library/user-event` 14.6.8
  - vitest 5.0.4, with `@vitest/mocker` and `@vitest/spy` 5.0.4, `chai` 6.3.0, `std-env` 4.3.0, `tinybench` 6.2.2 and `tinyexec` 1.3.1
  - **`obug` 2.1.4 to 3.0.0**, a transitive major. vitest 5.0.4 itself requires it (`"obug": "^3.0.0"`).
- **Unchanged:** `@playwright/test` 1.63.0, `@testing-library/dom` 10.4.2 and `@testing-library/jest-dom` 7.0.1.

### Dependabot (`.github/dependabot.yml`)

- `composer-minor-patch` gains `exclude-patterns: ["tecnickcom/tc-lib-pdf", "tecnickcom/tc-lib-pdf-font"]`. These two packages render frozen contract PDFs and fonts.
- `npm-minor-patch` adds `@testing-library/dom` and `@testing-library/jest-dom` to its exclude-patterns. This is deferred Codex P2 r4305283401.
- Nothing else changes, and the YAML parses.

## Laravel 13.37.0 review

I read the source diff `e5626e38..685f8d6c` (35 commits, 37 `src` files) from Composer's VCS cache. These are the parts that touch this app:

- `Connection::disconnect()` now suppresses teardown warnings with `@`.
- `HasAttributes::setAttribute()` clears `attributeCastCache[$key]`.
  - `IdentityMetadataAdmission::actorId` refuses when `id` is in that cache, so the change can only make that refusal rarer.
- `CacheEventMutex::exists()` uses `isLocked()` when the lock supports it.
  - This is reached through `withoutOverlapping(5)` on `vasey:publish-scheduled-site-release`.
- `PendingDispatch` and the queued-listener path now release unique locks when a push fails.
- `Migrator::shouldSkip()` is new.
- The rest is Eloquent relation fixes, JSON:API fixes and new validation and queue-console options.

No `ManagesTransactions`, `DatabaseTransactionsManager`, container or session class changed.

**Reflection targets** ([output](metadata/reflection-targets.txt)): `tools/reflection-targets.php` finds 73 distinct `Vendor\Class::class, 'member'` pairs in `app/`.

- 56 resolve to an existing member, identical to the first round. That includes every `ReflectionProperty`/`ReflectionMethod` target on `Connection`, `Container`, `DatabaseTransactionsManager`, `Facade`, `Model`, `Repository`, `Collection` and `ApiRequestor`.
- The other 17 are exception reason codes, not member names.

**Class loading** ([output](metadata/load-app-classes.txt)): `tools/load-app-classes.php` loads all 1,117 class-like files under `app/` with 0 errors.

## Verification results (tested source `ad6ee2a9`)

| Check | Result |
| --- | --- |
| `composer validate --strict` / `check-platform-reqs` / `audit --locked` | 0 / 0 / 0, no advisories |
| `npm ci` / `npm audit --audit-level=high` / `npm ls --all` | 0 (121 packages) / 0 (0 vulnerabilities) / 0 |
| `npx tsc --noEmit` | exit 0 |
| `npx vitest run` | **68 files, 1,172 tests, all passed (0 failures, errors or skips)** |
| `npm run build` → `scan-client-bundle.py public/build` → `rm -rf public/build` | 0 / 0 (14 files, no hits) / done |
| Python safeguards | `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK |
| `vendor/bin/pint --test` | not applicable: no PHP file changed |
| PHP on SQLite, the same 184 files | **3,061 tests, 29,168 assertions: 0 failures, 6 errors (base failures), 335 skipped** |

### PHP on SQLite

| Selection | Files | Tests | Assertions | Failures | Errors | Skipped |
| --- | --- | --- | --- | --- | --- | --- |
| reflection and container | 20 | 191 | 1,448 | 0 | 0 | 13 |
| Filament / `/admin` / Livewire, part 0 | 41 | 837 | 9,138 | 0 | **6** (base failures) | 124 |
| part 1 | 39 | 753 | 4,902 | 0 | 0 | 88 |
| part 2 | 42 | 720 | 10,342 | 0 | 0 | 59 |
| cross-section | 42 | 560 | 3,338 | 0 | 0 | 51 |
| **Total** | **184** | **3,061** | **29,168** | **0** | **6** | **335** |

Compared with the first round (with Filament):
- The 8 Filament failures are gone: part 1 went from 2 failures to 0, and part 2 from 6 to 0.
- Assertions rose by 36 because those cases now run to completion.
- The 6 errors are the base failures below.
- Every count comes from the retained JUnit files ([outcomes](php-sqlite/outcomes.json), [status](php-sqlite/chunk-status.txt)).

The selections are the same files as the first round:

- [reflection and container](php-sqlite/sel-reflection.txt)
- the 122 files mentioning `Filament`, `/admin` or `Livewire`, split [0](php-sqlite/sel-filament-00.txt), [1](php-sqlite/sel-filament-01.txt) and [2](php-sqlite/sel-filament-02.txt)
- every 13th remaining file ([cross-section](php-sqlite/sel-cross.txt))

Each part ran under `nice -n 10`, one PHPUnit process at a time, in two background invocations to stay under the two-hour limit. The command is the CI-env wrapper with `--fail-on-phpunit-warning`. `public/build` was absent throughout.

Every skipped case is a method in the reviewed SQLite census `scripts/ci/database-sqlite-skips.json`. These are native-only races, so SQLite proves nothing about them.

### Base failures: six `OrderInquiry*` errors (pre-existing, separate repair lane)

- **Cases:** two data sets of `OrderInquiryStaffContextTest::test_actual_retained_context_entry_rechecks_authority_even_after_filament_cached_its_state` and four of `OrderInquiryTest::test_staff_context_rechecks_authority_after_reconstruction_and_audit`.
- **Error:** each throws `AuthorizationException: Staff multi-factor authentication is required for this write.` at `StaffWriteAuthority.php:122`. The path runs `ReviewLicense.php:59` ← `LicenseFixtures::approved()` ← `OrderFixtures` ← `OrderInquiryFixtures`, after the test has made the panel require MFA.
- **Base control:** [base control](php-sqlite/base-control/base-control.txt) is a scratch `git archive 9225df8c` with main's lock installed: Laravel 13.36.0, Filament 5.8.4, Livewire 4.4.6 ([versions](php-sqlite/base-control/versions.txt)). It fails identically: same six cases, same exception, same throw site.
- **Base control still applies to main:** between `9225df8c` and `97e865e9`, main changed only `tests/ops/*.py`. No PHP under `app/` or `tests/` changed, so it is the same PHP source.
- **Likely cause (not proved):** `11ab36d7` (2026-10-15, "Require current staff enrollment in catalog, license and exclusive writers"). It came after these tests (2026-10-09), whose fixtures approve a license with an unenrolled reviewer.
- These belong to the separate repair lane.

## Filament 5.10.2: deferred to lane M3

The first round locked `filament/filament` 5.8.4 to 5.10.2 at commit `64bcf41c`. The evidence below is retained under [`filament-deferred-m3/`](filament-deferred-m3/).

### Transitive requirements

`filament/support` 5.10.2 requires:

- `livewire/livewire ^4.4.7`, which is 4.4.6 to 4.4.7. Its only source change is in `ImplicitlyBoundMethod`: contextual attribute parameters are now resolved from the container.
- the new `composer/class-map-generator ^1.6`, which resolves to 1.8.0. That in turn pulls `composer/pcre` 3.4.0.

The smallest set that resolves is:

```
composer update 'filament/*' laravel/framework livewire/livewire --with filament/filament:5.10.2 --with laravel/framework:13.37.0
```

It changes 12 records and adds 2 ([delta](filament-deferred-m3/metadata/composer-lock-delta-with-filament.txt)).

### The `NotificationMailbox` change

- **Old:** `Notification::send()` called `session()->push('filament.notifications', ...)`.
- **New:** it calls `NotificationMailbox::push()`.
  - The mailbox is scoped (one per request) and holds pending notifications.
  - It flushes them to the cache store `config('filament.cache_store')`, which is the default store when unset; `.env.example` has `CACHE_STORE=database`.
  - The cache key is `filament:notifications:<scope>`, it expires after the session lifetime, and access runs under a cache lock.
- **What stays in the session:** only `filament.notifications.mailbox`, which holds the scope ID.
- **The trap:** Laravel's dotted session keys mean `session('filament.notifications')` now returns `['mailbox' => '<id>']`. I confirmed this with a bare `Illuminate\Session\Store`.
- **Deployment consequence:** flash notifications now depend on a cache store that is shared and persistent between requests.

### Eight failing tests

They fail with Filament 5.10.2 and pass on base:

- `SiteContentDamagedPublicationTest::test_a_damaged_publication_record_refuses_publishing_and_sends_staff_to_a_backup`
- `SiteContentUnavailableHttpTest::test_new_content_draft_reports_unavailable_published_content_instead_of_opening`
- `SiteScheduleHttpTest::test_open_schedule_confirmation_reports_a_publication_or_schedule_made_meanwhile`, three data sets
- `SiteScheduleHttpTest::test_open_cancel_confirmation_reports_a_schedule_resolved_meanwhile_with_nothing_else_pending`, two data sets
- `SiteScheduleHttpTest::test_open_publish_confirmation_reports_its_release_becoming_active_meanwhile`

Each one calls `collect(session('filament.claimed_notifications', session('filament.notifications', [])))->firstWhere('title', ...)` and now gets `null`.

The logs are in [first-round chunks](filament-deferred-m3/php-sqlite/). That round ran 184 files and 3,061 tests: 8 failures, 6 errors (the base `OrderInquiry*` errors above) and 335 census skips. The [base control](php-sqlite/base-control/base-control.txt) shows all 8 passing on 5.8.4.

### Files whose assertions become vacuous

These read the same keys. They still pass on 5.10.2 but no longer inspect any notification:

- `LicenseTemplateAuthoringActionTest:289-291`: `assertIsArray` and `assertCount(1)` now hold for the mailbox array.
- `LicenseDraftAuthoringActionTest:295-297`: same.
- `MembershipAdministrationActionTest:229`: its private-detail and success-title "not contained" checks now search `{"mailbox":"..."}`.
- `BulkLicenseDraftSourceAuthoringActionTest:383-385`: same.
- `OfferDraftAuthoringActionTest:307-309`: same.
- `RightsDeclarationWriterActionTest:317`: same.

The leak-negative assertions for private failure details are silently weakened. M3 should migrate all eight files to read through `NotificationMailbox`, or only through Filament's `assertNotified` and `assertNotNotified`. The leak-negative checks must stay meaningful.

### Diagnostic: the app still sends the right notifications

An [uncommitted diagnostic](filament-deferred-m3/php-sqlite/diagnostic/mailbox-diagnostic-not-committed.diff) changed only the session read in the three failing files. The read became `NotificationMailbox::flush()` then `pull()`. It was run on 5.10.2 and reverted with `git checkout`.

- 7 of the 8 cases passed ([log](filament-deferred-m3/php-sqlite/diagnostic/mailbox-diagnostic.txt)).
- In the eighth, the notification was found with the expected title and body. The case then failed at `SiteContentUnavailableHttpTest:166`, because its later `assertNotified()` found the mailbox already drained by the diagnostic's `pull()`. That failure comes from the diagnostic, not from the app.

### Other Filament 5.10 changes M3 should review

Source diff `b5aad586..aaa2a4fc` in panels:

- **TOTP replay protection.** The last accepted time step is stored `forever` under `filament:auth:app_authentication:code:<sha256>`. The legacy key is still read and written. Verification runs under a 60-second cache lock with an ownership re-check, and recovery-code consumption also re-checks ownership.
- **MFA login state.** It now binds a guard-, class- and identifier-scoped hash, so a challenge already in flight during a deployment restarts.
- **New `filament:auth` limiter.** It allows 6 per minute per user, on the signed email-verification and email-change routes.
- **Parent-resource authorization** moved to a hydration hook. The app defines no parent resources.
- **New Livewire hook** for scoped model properties on resource pages.

App reflection targets and class loading were clean on 5.10.2.

## Dependabot PRs

I checked this read-only with the GitHub tools (`pull_request_read` `get` and `get_files`). Nothing was commented on or closed.

| PR | Dependabot's change | Status after M2 |
| --- | --- | --- |
| #143 | `package.json` and `package-lock.json`: react and react-dom 19.3.1, `@types/react-dom` 19.3.0, user-event 14.6.8 (4 lock records) | **Superseded in full.** Same versions and records as this batch. |
| #144 | vitest 5.0.3 to 5.0.4 with `@vitest/mocker` and `spy`, `chai`, `obug` 3.0.0, `std-env`, `tinybench`, `tinyexec` (8 lock records) | **Superseded in full.** Same records as this batch. |
| #142 | `composer.lock` only, 53 records, `content-hash` unchanged | **Superseded only for `laravel/framework` 13.37.0.** The rest stays open (below). |
| #145 | `@playwright/test` 1.63.0 to 1.64.1 | **Held** for the WebKit backport. |

#142's 53 records break down as:

- Filament (10) and Livewire, plus the new class-map-generator and pcre: lane M3.
- `tecnickcom/tc-lib-pdf` 8.80.1 and `tc-lib-pdf-font` 4.6.2, plus 11 other `tc-lib-*` records (barcode, color, file, encrypt, filter, graph, image, page, parser, sign, unicode, unicode-data): these need their own frozen-contract compatibility lane.
- **`symfony/console` 7.4.20 to 8.1.9, a major,** plus 15 Symfony 8.0 to 8.1 minors, `league/uri*` and `league/commonmark`, `laravel/prompts`, `guzzlehttp/psr7`, `spatie/laravel-package-tools` and `eloquent-power-joins`. Nothing requires these, and none was taken.

Closing #142 needs a lead decision. With the new `exclude-patterns`, Dependabot's next grouped Composer PR would leave out the PDF packages. #142 itself shows that a grouped "minor and patch" Composer PR can still carry a transitive major (`symfony/console` 8.1.9), so the next grouped PR may again include Filament and the Symfony moves.

## Untested conditions

- **Coverage.** 184 of 682 PHP test files ran, on SQLite only. There was no native MySQL run, so the census-skipped races and the M1 native case (`MemberGrantActivationIntegrationTest`) are unproven on MySQL.
- **Not run:** browser, WebKit or Playwright tests; hosted CI; Foundation; the `persistent-content` Node tests. React 19.3.1 was exercised only through jsdom and the build.
- **Composer install.** There was no fresh 158-package install. The vendor tree was moved in place and is checked against the lock. `php artisan package:discover` produced the same manifest as before.
- **Upstream review.** No upstream source other than the diffs named above was audited.

## Failures and deviations retained

- **First round, with Filament:**
  - The first `composer update` exited 1 after writing the lock, because `vendor/laravel/framework` had no `.git` directory ([log](filament-deferred-m3/install/composer-update-attempt1-gitdir-missing.txt)).
  - One cross-section attempt was killed by the harness's two-hour background limit before writing JUnit ([log](filament-deferred-m3/php-sqlite/cross-attempt1-killed-no-junit.txt)).
  - Both are retained.
- **History rework.** The lead asked for a clean history. A `git reset --hard` to main was refused by the session's safety classifier, so I took the lead's alternative: merge `origin/main` (`d3a54c02`), then commit the Laravel-only lock (`ad6ee2a9`). Nothing was rewritten.
- **Base control copy.** It was built in the scratch directory. Its vendor tree was hard-linked from this worktree, with `vendor/composer`, `vendor/bin` and the top-level files copied. Checksums confirmed this worktree's `vendor/autoload.php` and `vendor/composer/*` were unchanged, and the copy was deleted afterwards.

## Rollback

Restore `composer.lock`, `package.json`, `package-lock.json` and `.github/dependabot.yml` from `origin/main`, then reinstall. Nothing else needs undoing: no migration, configuration or application code changed.

## Commands

Commands ran in `{worktree}` with `COMPOSER_ALLOW_SUPERUSER=1` and `. /opt/nvm/nvm.sh && nvm use 24`. In the retained logs, scratch and worktree paths are replaced by placeholders and ANSI codes are removed.

| Command | Exit | Output |
| --- | --- | --- |
| `git merge --no-ff origin/main` | 0 | merge `d3a54c02` |
| `git show origin/main:composer.lock > composer.lock`; `composer update laravel/framework --with laravel/framework:13.37.0 --no-scripts --no-install` (dry run, then real) | 0 / 0 | `install/composer-update-laravel-*.txt` |
| `composer install --no-scripts` | 0 | `install/composer-install.txt` |
| `php artisan package:discover` | 0 | manifest identical |
| `composer validate --strict` / `check-platform-reqs` / `audit --locked` / `outdated --direct --locked` | 0 / 0 / 0 / 0 | `install/`, `metadata/` |
| `npm ci` / `npm audit --audit-level=high` / `npm ls --all` | 0 / 0 / 0 | `install/npm-ci.txt`, `metadata/npm-audit-high.txt` |
| `npx tsc --noEmit`; `npx vitest run --reporter=default --reporter=junit` | 0 / 0 | `frontend/` |
| `npm run build`; `python3 scripts/ci/scan-client-bundle.py public/build`; `rm -rf public/build` | 0 / 0 / 0 | `frontend/` |
| `python3 scripts/ci/{test-phpunit-shards,test-ci-scope,test-workflow-cadence,test-focused-tests}.py` | 0 each | `preflight/python-safeguards.txt` |
| `php tools/reflection-targets.php`; `php tools/load-app-classes.php` | 0 / 0 | `metadata/` |
| `tools/run-php.sh reflection filament-00 filament-01`, then `tools/run-php.sh filament-02 cross` | 0 / 2 / 0, then 0 / 0 | `php-sqlite/` |

The PHP wrapper:

```
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array \
  SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true nice -n 10 php -d memory_limit=512M \
  -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --fail-on-phpunit-warning --log-junit <file>.xml <files>
```
