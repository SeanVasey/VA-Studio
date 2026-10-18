# Filament 5.10.2 and Livewire 4.4.7 — October 18, 2026

Lane M3 updates the ten `filament/*` packages from 5.8.4 to 5.10.2 and `livewire/livewire` from 4.4.6 to 4.4.7, after moving every test that read Filament's notification internals onto one helper that works on both versions.

| | |
| --- | --- |
| **Tested source** | `e5b482c498f0ea59c7c24af92ec4043213071d91` on branch `harness/filament-510-20261018` (tree: `origin/main` at `5007a906` plus the three commits below). |
| **Commits** | `ab2b001e` test: one version-agnostic notification helper and nine migrated files, on Filament 5.8.4 · `5ffe45e2` test: the one other test the update breaks · `e5b482c4` chore(deps): the lock · the commit after these changes only this directory and `CHANGELOG.md`. The commit order was changed once before this packet, to put the second test fix ahead of the lock; the tree is the same. |
| **Toolchain** | PHP 8.4.26, Composer 2.8.12, Node 24.22.0. |
| **Not done** | Nothing was pushed, no PR was opened or commented on, no workflow was dispatched. M2's worktree was only read. |

## Result in one paragraph

All nine notification readers migrated. Four of the six "vacuous" privacy checks were already vacuous on Filament 5.8.4 (they read the session after `assertNotified()` had drained it), so main's own tests did not guard what they claimed to; all six now fail when a private detail is leaked, on both versions. The update also breaks a tenth test that M2 never ran, because Filament 5.10 appends a session-conditional middleware to every matched route (see [the second break](#the-second-break-a-route-middleware-assertion)). With both fixed, the 147-file Filament-touching set produces identical results on 5.8.4 and 5.10.2: 2,616 tests, 27,355 assertions, 0 failures, 6 errors (the known `OrderInquiry*` base errors) and 299 census skips. Run in full, all 682 PHPUnit test files pass on 5.10.2 except six cases in four unrelated files that fail identically on 5.8.4 (see [failures that predate this lane](#failures-that-predate-this-lane)).

## Lock delta

`composer update 'filament/*' livewire/livewire --no-scripts --no-install` (no `--with-dependencies`; [dry run](install/composer-update-dry-run.txt)) changes **13 of 160 lock records** ([delta](metadata/composer-lock-delta.txt)):

| Package | Before | After |
| --- | --- | --- |
| `filament/actions` `filament/filament` `filament/forms` `filament/infolists` `filament/notifications` `filament/query-builder` `filament/schemas` `filament/support` `filament/tables` `filament/widgets` | v5.8.4 | **v5.10.2** |
| `livewire/livewire` | v4.4.6 | **v4.4.7** |
| `composer/class-map-generator` | — | **1.8.0** (new, required by `filament/support`) |
| `composer/pcre` | — | **3.4.0** (new, required by the class-map-generator) |

- **Unchanged:** `composer.json`, the `content-hash`, every other top-level key and all other 147 records, including `symfony/console` v7.4.20, `laravel/framework` v13.37.0, `stripe/stripe-php` v21.3.2 and `phpunit/phpunit` 12.5.34. The plain `composer update 'filament/*' livewire/livewire` already resolves to the latest Filament (5.10.2 is the newest release), so no `--with` pin was needed. The form in the lane brief (`--with composer/class-map-generator`) is rejected by Composer without a version constraint.
- **Why the two new packages are harmless at runtime:** `Composer\ClassMapGenerator` is used only by `Filament\Support\discover_app_classes()`, which the `make:*` generator commands call. The panel's own `discoverResources/Pages/Widgets` do not use it. Both packages are in `require`, so `composer install --no-dev` still installs them.
- **Install:** `composer install` from the lock with scripts, in a testing environment, took 8 s from a warm cache and ran `package:discover` and `filament:upgrade` ([log, asset list trimmed](install/composer-install-5.10.2.txt)). `composer install --dry-run` afterwards: nothing to install.
- **Assets:** nothing to commit. `public/js/filament`, `public/css/filament` and `public/fonts/filament` are in `.gitignore`, `git ls-files public` lists no Filament file, and the existing `post-autoload-dump` script runs `php artisan filament:upgrade`, which published 41 files here. The staging release step (`composer install --no-dev --classmap-authoritative`) therefore publishes them the same way as for 5.8.4. I deleted the generated files right after the install, so every test run matched the 5.8.4 environment (none was published there).
- **Hygiene:** `composer validate --strict` valid; `composer audit --locked` "No security vulnerability advisories found"; `composer outdated --direct` no longer lists Filament or Livewire (it still lists `phpunit` 13, `stripe-php` 22 and the two `tc-lib-pdf` packages, none in this lane).

## The helper

`tests/Support/FilamentNotifications.php` has one read path, three methods and no session key.

**Is there a public read API?** No. Filament offers only `assertNotified()` and `assertNotNotified()`, which match by title or by a complete `Notification` and cannot search a body for a string. So the helper follows the read path of Filament's own `Notification::assertNotified()`, which is the same on both versions apart from one call:

| Step | 5.8.4 | 5.10.2 |
| --- | --- | --- |
| Where a notification waits | session key `filament.notifications`, moved to `filament.claimed_notifications` by Livewire's dehydrate hook | `NotificationMailbox`: in memory until published, then the cache entry `filament:notifications:<scope>` |
| Publish what this process queued | not needed | `app(NotificationMailbox::class)->flush()` |
| Read it | `(new Notifications)->mount()` pulls | `mount()` does not pull; `pullNotificationsFromSession()` does |
| Serialize | `Notification::toArray()` | same |

The helper does `flush()` only when `class_exists(NotificationMailbox::class)`, then `mount()` and `pullNotificationsFromSession()` (on 5.8.4 the second call finds nothing), then `toArray()`. **The one internal it touches is `NotificationMailbox::flush()`**, the call `assertNotified()` itself makes first. Everything else is the public `Notifications` Livewire component and `Notification`. If a later Filament renames the mailbox, `class_exists` goes false, `flush()` is skipped, and the notification checks fail loudly (`sole()` and `assertNoneContain()` refuse an empty queue); they cannot pass vacuously.

| Method | Use |
| --- | --- |
| `flashed(): array` | Every queued notification as `toArray()` (id, title, body, status, color, icon, duration, actions, view, view data). Pulling is destructive, so it sends them again, and a later `assertNotified()` still finds them. |
| `sole(string $title, ?string $status = null): array` | Exactly one notification with that title (and status); fails on none or several. |
| `assertNoneContain(array $texts): void` | Fails first if the queue is empty, then fails if any text appears in the serialized notifications (title, body, actions and view data). |

## Migration, file by file

Counts are tests / assertions and are identical on 5.8.4 (commit `ab2b001e`) and 5.10.2.

| File | Old read | New read and what is now asserted | Tests / assertions |
| --- | --- | --- | --- |
| `SiteScheduleHttpTest` (6 failing cases on 5.10.2) | `collect(session('filament.claimed_notifications', session('filament.notifications', [])))->firstWhere('title', $t)` | `FilamentNotifications::sole($t)['body']`. Exactly one notification with that title must exist (was: the first); every body assertion is unchanged. | 18 / 220 |
| `SiteContentUnavailableHttpTest` (1 failing) | same | `sole('The published site content is unavailable')`; the two body assertions are unchanged. | 7 / 364 |
| `SiteContentDamagedPublicationTest` (1 failing) | same | same title; the body assertion is unchanged. | 4 / 59 |
| `LicenseTemplateAuthoringActionTest` (vacuous on 5.10.2) | `$sent = session(...)`, `assertIsArray`, `assertCount(1)` | `$sent = flashed()`; `assertCount(1)`; title is `The save result could not be confirmed.` and status `danger`; the later `json_encode($sent)` checks for the exception text and the `Saved`/`Created` titles are kept. | 18 / 237 |
| `LicenseDraftAuthoringActionTest` (vacuous) | same | same shape, same title and status; `Synthetic private failure detail` and `Saved` checks kept. | 16 / 303 |
| `MembershipAdministrationActionTest` (vacuous; **already vacuous on 5.8.4**) | read the session after `assertNotified()` had drained it, so it searched `null` | `sole('The private plan save could not be confirmed', 'danger')`, then `assertNoneContain(['PRIVATE_SYNTHETIC_FAILURE_DETAIL', 'Private plan revision saved'])`, both before `assertNotified()`. | 24 / 425 |
| `BulkLicenseDraftSourceAuthoringActionTest` (vacuous; **already vacuous**) | same | `sole('The bulk save result could not be confirmed.', 'danger')`, then `assertNoneContain(['Synthetic private license failure details', 'Source saved for'])`. | 39 / 682 |
| `OfferDraftAuthoringActionTest` (vacuous; **already vacuous**) | same | `sole('The offer save could not be confirmed', 'danger')`, then `assertNoneContain([... detail, '"title":"Saved"'])`. | 13 / 262 |
| `RightsDeclarationWriterActionTest` (vacuous; **already vacuous**) | read `session('filament.notifications')` after `assertNotified()` | `sole('The save result could not be confirmed.', 'danger')`, then `assertNoneContain([$data['provenance_reference']])`, for the `edit` and `verify` data sets. | 25 / 232 |

No other test reads Filament notification internals: `grep -rn 'filament\.notifications\|claimed_notifications' tests` finds nothing outside `tests/Fixtures/paid-development` (a frozen source snapshot). The tests that call Filament's own `assertNotified()` / `assertNotNotified()` (for example `TrackPublicationManifestEditorTest`, `ProductDraftEditorTest`) pass unchanged on both versions.

### The privacy checks were weaker than they looked, on main

I ran the original tests on 5.8.4 with a leak mutation (below). **Membership, Bulk, Offer and Rights pass the leak.** They read the session after `assertNotified()` had pulled it, so on main they searched `null` (probe: `var_export(session(...))` printed `NULL` in the Offer and Rights tests). LicenseTemplate fails the leak only through its pre-existing exact `assertNotified(Notification::make()...)`, and LicenseDraft is the only one whose privacy check really worked. The migrated tests guard all six.

## Mutations

Each mutation appends `$exception->getMessage()` to the body of the uncertain-failure notification in app code (`tools/mutate.py`), runs the file's uncertain-failure tests (`--filter uncertain`) and is reverted with `git checkout -- app`. Logs: [mutations/](mutations/).

| Test (app site) | Original test, 5.8.4 | Original test, 5.10.2 | Migrated, 5.8.4 | Migrated, 5.10.2 |
| --- | --- | --- | --- | --- |
| `MembershipAdministrationActionTest` (`ManageMembershipPlans`) | passes (vacuous) | passes (vacuous) | **fails** at `assertNoneContain` | **fails** |
| `LicenseTemplateAuthoringActionTest` (`ManageLicenseTemplates::uncertain`) | fails (exact `assertNotified`) | fails (same) | **fails** (exact `assertNotified`); fails at the privacy check too when that exact assertion is disabled | same |
| `LicenseDraftAuthoringActionTest` (`ManageLicenseVersions::uncertainNotification`) | fails | passes (vacuous) | **fails** | **fails** |
| `BulkLicenseDraftSourceAuthoringActionTest` (`ManageLicenseVersions`, bulk) | passes (vacuous) | passes (vacuous) | **fails** | **fails** |
| `OfferDraftAuthoringActionTest` (`ManageOffers`) | passes (vacuous) | passes (vacuous) | **fails** | **fails** |
| `RightsDeclarationWriterActionTest` (`ManageRightsDeclarations`), `edit` and `verify` | passes (vacuous) | passes (vacuous) | **fails** (2 cases) | **fails** (2 cases) |

The 8 originally failing cases fail on 5.10.2 with the original tests (SiteSchedule 6, SiteContentUnavailable 1, SiteContentDamaged 1; [logs](mutations/original-tests-5.10.2-no-mutation/)) and pass migrated.

## The second break: a route-middleware assertion

`DiscoverySitemapRegistrationTest::test_default_off_keeps_existing_site_index_and_public_routes_have_no_session_stack` fails on 5.10.2 and passes on 5.8.4 (vendor swapped, same test). M2's selections evidently did not include it.

- **Cause:** `NotificationsServiceProvider` listens for `RouteMatched` and appends `Filament\Notifications\Http\Middleware\InitializeNotificationMailbox` to **every** matched route that lacks it. The route `discovery.tracks`, built to have only `throttle:128,1,public-discovery-tracks`, now lists the middleware.
- **Effect:** none at runtime. The middleware calls `initializeScope()` only when `$request->hasSession()`, and these routes have no session middleware, so no session opens and no cookie is set. The 404 and 200 responses carry no `Set-Cookie`.
- **Fix (`5ffe45e2`, test only):** remove exactly that class name from the middleware list, keep the exact comparison for everything else, and add `getCookies() === []` assertions on both responses, so a session opened by any means still fails. A mutation that adds `StartSession` to the route fails the test. The fix passes on 5.8.4 (nothing to remove) and 5.10.2. It is the one place where an existing assertion was relaxed, so it deserves the reviewer's eye.
- **Other session-less routes:** `PublicTrackEmbedTest` and `FreeGrantRegistrationTest` only assert that `web` is absent or a throttle is present, and pass unchanged.

## Filament 5.8.4 → 5.10.2 review

Source: `diff -ruN` of each `filament/*` package and Livewire between the two installed trees (about 41,000 lines, mostly views, docs and generators). The GitHub release notes were not reachable from this session, and the split package repositories squash history, so nothing below comes from a changelog. Counts of changed files per package (without `dist`, `lang` and `.git`): [source-diff-file-counts.txt](metadata/source-diff-file-counts.txt).

| Area | Change | Relevance to this app |
| --- | --- | --- |
| Notifications | Flash notifications leave the session for a cache-backed `NotificationMailbox`; a global `PublishNotifications` middleware is prepended to the HTTP kernel, and `InitializeNotificationMailbox` is added to every matched route; the mailbox scope rotates on `Login` and `Logout` and keeps unread notifications | The reason for this lane. See [the cache analysis](#mailbox-cache-and-privacy). |
| TOTP (`AppAuthentication`) | The replay record becomes `filament:auth:app_authentication:code:<sha256(secret)>`, stored **forever**, with the old `filament.app_authentication_codes.<md5>` key still read and written during the transition; verification runs under a 60 s cache lock with an ownership re-check (10 s wait), and falls back to no lock when the store has none; recovery-code consumption also re-checks ownership; a failed save of recovery codes now throws | Used: staff MFA is TOTP with recovery codes (`AdminPanelProvider`). Verification uses the **default** cache store (`Cache::store()`), not `filament.cache_store`; here both are the default (`database` in production and staging, which has `cache_locks`). The lock path on a MySQL-backed `database` store is untested here. |
| MFA login state | The in-flight challenge is an encrypted `{identifier, userKey}` bound to guard, class and identifier, and re-checked when credentials are replayed | A challenge started before the deploy fails once and the operator signs in again. Staff MFA tests pass (`CatalogLicenseStaffMfaTest`, `StagingEnvironmentAdmissionTest`, others in the 147-file set). |
| Rate-limit keys | `filament-multi-factor-challenge:…`, `filament-set-up-app-authentication:…` and the disable/regenerate keys become `filament:auth:…:rate_limit:<hash>` | Counters restart at the deploy; no policy change. |
| `filament:auth` limiter | 6 per minute per user on the signed email-verification and email-change routes; the email-change token handling also checks removal | Not used: the panel enables `login()` and `profile()` only (no registration, password reset, email verification or change). |
| Authorization | Parent-resource access moved from `authorizeResourceAccess()` to a hydration hook; new `ScopedModelPropertiesComponentHook` re-resolves scoped model properties on resource-page hydrate and on every call (a client call to `resolveScopedModelProperties` returns 404); relation managers re-check access on a non-lazy hydrate | The app defines no parent resources (`grep -rn parentResource app` finds nothing). Resource-page authorization tests pass. |
| Actions | URL-driven default actions via `actionComponent`, `#[Locked]` mounted flags; `getDefaultActionUrlIdentifier()` returns `null`, so nothing mounts | Opt-in; no page here opts in. |
| Tables and query builder | Search and the text constraints escape `!`, `[`, `%`, `_` and add `escape '!'` to the `LIKE` | The 26 `searchable`/search call sites here behave the same on SQLite (tests pass). The SQL form is valid MySQL but **MySQL was not run**. |
| File upload | The default stored name is `$file->hashName()` (was ULID plus client extension), `storeAs` passes the MIME type, a moved file gets its visibility set | The three upload fields use `storeFiles(false)`, so the stored-name callback is not reached; the upload tests pass (`MediaAsset`, `SoundKit`, `SiteImage`). Browser upload is CI evidence only. |
| Livewire 4.4.7 | `ImplicitlyBoundMethod` leaves contextual-attribute parameters to the container | No effect seen. |
| `config/filament.php` | New `cache_store` key (default `null`, the default store) | The app does not publish the Filament config, so the default store is used. |

Nothing in the diff changed authorization of a resource, an upload path, a policy hook or the panel's own middleware list in a way this app depends on, and nothing weakened a check. The two behavioural surprises are the mailbox and the global route middleware.

## Mailbox, cache and privacy

How it behaves, measured with a throwaway script against a real `database` cache store ([probe](probe/mailbox-probe.php), [output](probe/mailbox-probe.txt)):

| Question | Answer |
| --- | --- |
| Where is the text persisted? | `send()` only queues in memory. At the end of the request (`PublishNotifications`, or `assertNotified()` in tests) `flush()` writes **one cache row** holding PHP-serialized `toArray()` data: title, body, status, actions and view data, **in plain text** (the `cache.value` column), up to 50 notifications per mailbox. |
| Keyed by what? | `<cache prefix>filament:notifications:<scope>`; the scope is `hash_hmac('sha256', session id + CSRF token, app.key)` stored in the session as `filament.notifications.mailbox`. Rotating on login or logout uses a random 40-character scope. It is not derivable from the cookie alone. |
| For how long? | `session.lifetime` minutes: 120 by default (`.env.example`), 480 on staging. |
| When is it removed? | On the next render of a page with the notification host (`pull()` deletes the row, under a cache lock). |
| If it is never rendered? | The row **stays**. Laravel's `DatabaseStore` deletes an expired row only when that exact key is read, the key is never read again, and this repository schedules no cache pruning (`routes/console.php` has one command). The probe shows an expired, unread row still present. Sessions, by contrast, are collected by the session garbage-collection lottery. |
| Which cache? | `config('filament.cache_store')`, `null` meaning the default store: `database` in `.env.example` and staging, so the `cache` and `cache_locks` tables. An `array` store, or a `file` store not shared by every host, would lose a notification that crosses requests. |

**Does it matter?** The text is operator-facing and staff-only, and before the change it sat in the `sessions` payload, which is also plain text in the same database, so the sensitivity class is unchanged. What changes is retention and place: an undelivered notification can outlive its session in the `cache` table, and it is included in database backups. I looked at all 82 `Notification::make()` lines in `app/`. No body interpolates an exception message (`grep -rn getMessage app/Filament` finds none in a notification), a customer field or a secret. Bodies carry fixed text, validation messages, counts, ids, versions and statuses. The widest is `MediaAssetResource` "Processing details", which prints the run's stored `failure_message`; that string is already in the database and staff-only. The six uncertain-failure tests above are what guarantee exception text never enters a notification, and they now do so on both versions.

Recommendations, not done (outside the lane): schedule a deletion of expired `cache` rows (`cache:clear` would also reset the throttles that live in the same store), and say in `docs/ops` that Filament flash messages and TOTP replay records need a shared, persistent cache store.

## Verification

The 147-, 72- and 2-file runs were made at `29bc5add`, the tree of `e5b482c4` before the commit reorder, which differs only in `DiscoverySitemapRegistrationTest.php`; that file was re-run at the tip on both versions. The 5.8.4 runs of the migrated files and of the 147-file set are at `ab2b001e`.

Environment: `APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true`, `php -d memory_limit=512M` calling PHPUnit through the worktree's `vendor/autoload.php`, `--fail-on-phpunit-warning`, one process per file, three at a time, `public/build` absent, a real `vendor` copy (not symlinks). Tools: [tools/](tools/).

| Check | Result |
| --- | --- |
| 9 migrated files, Filament 5.8.4, at `ab2b001e` | 9/9 OK: 164 tests, 2,784 assertions |
| 9 migrated files, Filament 5.10.2 | 9/9 OK, the same counts |
| Filament-touching set: 147 files ([list](metadata/selection-filament-147.txt): every test file mentioning Filament, every `*ActionTest` and `Site*` test, and every file mentioning MFA, TOTP or app authentication), 5.8.4 | **2,616 tests, 27,355 assertions: 0 failures, 6 errors, 299 skipped** ([summary](metadata/junit-5.8.4-filament-set.json)) |
| the same 147 files, 5.10.2 | **identical per file: 2,616 / 27,355 / 0 / 6 / 299** ([summary](metadata/junit-5.10.2-filament-set.json)) |
| Skips outside `scripts/ci/database-sqlite-skips.json` | none, on both |
| The 6 errors | the known `OrderInquiryTest` (4) and `OrderInquiryStaffContextTest` (2) "Staff multi-factor authentication is required" errors, the same on both versions; lane F4 owns them |
| 72 further files that touch session, cookies or cache headers, 5.10.2 | 1,246 tests, 19,248 assertions, 2 skipped; the one failure is the sitemap test above (now fixed) ([summary](metadata/junit-5.10.2-session-set.json)) |
| `PublicTrackEmbedRouteCacheTest`, `BrowserLoginRateLimitFixtureTest` (route cache and middleware), 5.10.2 | 4 tests, 761 assertions, OK |
| `DiscoverySitemapRegistrationTest` after the fix | OK on 5.8.4 (5 tests, 263 assertions) and 5.10.2; StartSession mutation fails it |
| The remaining 461 PHPUnit files (every other `tests/Unit` and `tests/Feature` file), 5.10.2, at `e5b482c4` | 5,524 tests, 48,334 assertions, 402 skipped (all in the census), **6 failures in 4 files**, all identical on 5.8.4 ([summary](metadata/junit-5.10.2-remaining-461-files.json), [5.8.4 control](metadata/junit-5.8.4-the-four-failing-files.json)) |
| **All 682 PHPUnit files on 5.10.2** | 9,390 tests, 95,698 assertions, 703 skipped (all in the census), 6 errors (known `OrderInquiry*`), 6 failures (pre-existing, below), plus the sitemap failure that `5ffe45e2` fixes |
| `composer validate --strict` | `./composer.json is valid` |
| `composer audit --locked` | No security vulnerability advisories found |
| `npx tsc --noEmit` | exit 0 |
| `npm test` | 68 files, 1,172 tests passed |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `npm run build` → `scan-client-bundle.py public/build` → `rm -rf public/build` | 0 / 0 (14 files, no secret names or Stripe key prefixes) / done. Nothing in the frontend depends on Filament, so this only shows the lock change leaves it untouched. |
| `test-phpunit-shards.py` / `test-ci-scope.py` / `test-workflow-cadence.py` / `test-focused-tests.py` | 52 OK / 27 OK / 14 OK / 53 OK |
| `git diff --check origin/main...HEAD` | exit 0, no output |

### Failures that predate this lane

The remaining-files run found six failing cases that are **not caused by Filament**. I re-ran the four files on 5.8.4 (vendor swapped back, same tree): the same six cases fail with the same messages ([control](metadata/junit-5.8.4-the-four-failing-files.json)).

| File | Failing cases | Message |
| --- | --- | --- |
| `OrderInquiryMigrationTest::test_empty_owned_roundtrip_and_repeat_install_preserve_unrelated_schema` | 1 | SQLite `rootpage` numbers differ in a schema comparison |
| `ProductionAccountFeaturesDefaultConfigurationTest::test_booted_parents_are_the_exact_closed_default_off_plain_arrays` | 1 | the config now has an `http_mounted => false` key the closed-default assertion does not list |
| `ProductionFreeGrantContentionTest::test_a_deadlock_after_the_customer_write_is_a_fixed_refusal_and_nothing_is_written` | 3 | `identity_refused` where `contention` is expected |
| `ProductionFreeGrantStalledSourceTest::test_the_deadline_is_checked_before_the_first_read` | 1 | `artifact_unavailable` where `expired` is expected |

They were not investigated or touched (scope); the lead may want an owner. The six `OrderInquiry*` errors of lane F4 are separate and also unchanged.

## Not tested

- **MySQL.** Every PHP result is SQLite with array cache and session. The `LIKE ... escape '!'` search form, the cache-lock path of TOTP verification on a `database` store and the mailbox on a real `database` cache store inside a request are unproven here (the probe covers the store only, in one process, on SQLite). MySQL-only races are skipped by census on both versions.
- **A browser or HTTP round trip.** `Livewire::test` and the probe do not exercise the redirect case `PublishNotifications` handles (flash, redirect, render) or the real session cookie. Filament assets were not rebuilt in a browser; the Playwright specs are CI evidence.
- **Release notes.** The upstream changelog was not reachable; the review is from source.
- **Production host behaviour:** a shared cache across several web hosts, the `cache_locks` table under load and the first sign-in after deploy (one restarted MFA challenge, reset limiter counters) are not exercised.
- **Pint** is not enforced (open standard gap); no PHP style tool was run.
