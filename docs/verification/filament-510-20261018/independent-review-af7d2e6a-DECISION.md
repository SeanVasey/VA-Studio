# Independent review — M3 Filament 5.10.2 at af7d2e6a

| | |
| --- | --- |
| **Reviewed commit** | `af7d2e6a1f357fdad3e82d30ddf622562bfc9b1a` on `harness/filament-510-20261018` (worktree `/home/user/wt/m3`) |
| **Base** | `origin/main` at `7cd95b73969ba0927e634a2753712239d7db3226` (merge-base equals main; `git diff 7cd95b73 af7d2e6a` and `git diff origin/main...af7d2e6a` are the same 74 files) |
| **Reviewer** | Independent adversarial reviewer (Claude). I did not write this change. Nothing was committed, pushed or posted. |
| **Decision** | **APPROVE WITH CONDITIONS.** Two doc-only conditions (R1, R2) must be corrected before merge. No P0 or P1. |

## Decision summary

The lock is exact, and the helper is sound. It reads notifications only through Filament's own `assertNotified()` read path, and it fails closed: the mutations below prove it cannot pass vacuously. Each migrated test keeps its intent or gets stricter. The sitemap relaxation is safe and narrowly scoped. Every test I ran on 5.10.2 passes, and every author claim I checked reproduced. That includes the "already vacuous on 5.8.4" claim, which I checked with a genuine 5.8.4 + Livewire 4.4.6 tree.

One factual claim in the evidence packet is wrong, and it is a security rationale. The packet says flash notifications were previously "in the `sessions` payload, which is also plain text". That is false for this project. `SESSION_ENCRYPT=true` is set in `.env.example`, the staging profile and the private-server profile, and `ProductionCommerceReadiness` requires `session.encrypt === true`. So 5.10 moves staff notification text from encrypted-at-rest session payloads into plaintext `cache` rows. Those rows also outlive the session. The content is low-sensitivity, so this is P2, not a blocker. The packet must stop calling it "unchanged" (R1). The pruning or retention follow-up can be deferred (R3).

## Findings

| ID | Sev | File:line | Finding | Evidence | Fix |
| --- | --- | --- | --- | --- | --- |
| R1 | P2 (merge condition: doc) | `docs/verification/filament-510-20261018/README.md:133` | The packet says notifications previously sat in the sessions payload, "which is also plain text … so the sensitivity class is unchanged". Sessions here are encrypted at rest (`SESSION_ENCRYPT=true` at `.env.example:46`, `ops/staging/env.staging.example:61`, `ops/private-server/env.example:27`; enforced by `app/Domain/Commerce/Readiness/ProductionCommerceReadiness.php:68` and the private-server preflight). `NotificationMailbox::flush()` writes PHP-serialized `toArray()` data, including title, body and actions, in plaintext to the `cache.value` column (`vendor/filament/notifications/src/NotificationMailbox.php:79-87`). So the change removes an at-rest control the project deliberately requires, for staff flash text. | Author's own probe output (`probe/mailbox-probe.txt`: `value=a:1:{…"PROBE-BODY operator text"…}`); config greps above. | Correct the sentence. State that notification text leaves encrypted session storage for plaintext cache rows with longer retention, and record it as an accepted, tracked risk with R3 as the follow-up. |
| R2 | P3 (merge condition: doc) | `docs/verification/filament-510-20261018/README.md:150`, `:165-176`; `CHANGELOG.md:71` | Stale after the main merge (`2f47a64f`). The packet and CHANGELOG still say the six `OrderInquiry*` errors are open ("lane F4 owns them"). They also say six cases in four files fail the same way on 5.8.4. PR #157 repaired all of these, and at `af7d2e6a` all six files pass on 5.10.2. The packet header also still names `e5b482c4` as the tested source. | I ran the six F4 files at `af7d2e6a` on 5.10.2: `OrderInquiryTest` OK (27 tests / 105 assertions), `OrderInquiryStaffContextTest` OK (3/14), `OrderInquiryMigrationTest` OK (8/33), `ProductionFreeGrantContentionTest` OK (7/44), `ProductionFreeGrantStalledSourceTest` OK (5/33), `ProductionAccountFeaturesDefaultConfigurationTest` OK (3/69). | Add a post-merge note: those failures were fixed by #157 and pass at `af7d2e6a`. Reword the CHANGELOG clause to match. |
| R3 | P2 (deferrable; before production, ideally before long staging use) | `routes/console.php` (no pruning); `NotificationMailbox.php:83` TTL = `session.lifetime` | An expired, unread mailbox row is never deleted. `DatabaseStore` removes an expired row only when that exact key is read again, and no cache-pruning task is scheduled. The sessions table, by contrast, is GC'd by the lottery (`config/session.php:117`). This regresses retention for the R1 data, and the rows also land in backups. Growth is small: at most one row per staff session that ends with an unread notification. Unbounded expired rows already exist for the public rate-limiter keys (one per IP and budget) in the same table, so volume is not new. Retention of staff text beyond its session is new. | Author's probe: "expired-but-unread rows still present: 1". Laravel 13 ships no database cache prune command (`vendor/laravel/framework/src/Illuminate/Cache/Console/` has only Clear, Forget, PruneStaleTags and CacheTable). | Follow-up batch: a scheduled `DELETE FROM cache WHERE expiration < now()` command (not `cache:clear`, which resets throttles and TOTP replay records), plus an ops note that mailbox and TOTP state need a shared, persistent store. |
| R4 | P3 | `vendor/filament/filament/src/Auth/MultiFactor/Concerns/HasCacheStore.php:44-50` | The TOTP verification lock TTL rises from 10 s to 60 s, still with a 10 s `block()`. A request that dies while holding the lock blocks that staff member's TOTP for up to 60 s. Each attempt then ends in a `LockTimeoutException` (HTTP 500) after about 10 s. Replay protection itself works on the `database` store, and the `forever` keys are bounded (two rows per TOTP secret ever used: the new key plus the legacy key). | Reviewer probe on a SQLite-backed `DatabaseStore`: first verify `true`; replay `false`; replay against a legacy-key-only state `false`; two rows with ~10-year expiry; 0 lock rows left; a held lock threw `LockTimeoutException` after 9.8 s. | Ops note only. No code change needed in this lane. |
| R5 | P3 | `vendor/filament/notifications/src/NotificationsServiceProvider.php:60-66` | The `Login` and `Logout` listeners fire for every guard, including the `customer` guard (`CustomerSessionController.php:50`, `CustomerIdentityController.php:73`). Each customer sign-in now runs `rotateScope()`: a cache lock plus a read and forget on the default (database) store, and a `filament.notifications.mailbox` key added to the customer session. Every `web`-group session also gets that key from `InitializeNotificationMailbox`. No data crosses between users, and no customer text enters the mailbox. This adds per-sign-in DB writes and one session key. | Source reading; customer, identity and privacy tests in the Filament set pass (array cache, so the DB writes are not observed by tests). | Record it in the packet's behaviour table. No change needed. |
| R6 | P3 | 5.8.4 `vendor/filament/notifications/src/Livewire/Notifications.php:37-42` | Rollback hazard for code-only rollbacks. After 5.10 runs, a staff session holds `filament.notifications = ['mailbox' => '<hash>']`. 5.8.4 code would pull that array and call `Notification::fromArray('<hash>')`, a `TypeError`, so each touched staff session gets one 500 on its first panel page. The documented rollback path B restores the pre-deploy database, sessions included, so this is not reachable through the runbook. | Source reading of both versions. | One line in the packet's "Not tested / operational" list. |
| R7 | P3 (info) | `tests/Support/FilamentNotifications.php:37-50` | The helper's one internal is `NotificationMailbox::flush()`, the same first call that Filament's `assertNotified()` makes (`Notification.php:255`). That dependency is fragile by nature, but it fails closed (see M-H1). The re-send in `flashed()` does not hide a double notification. Filament keys notifications by id (`Notifications.php:79`), so a same-id re-send collapses exactly as it does in the UI, while a second distinct notification is caught (M-dup2). | Mutations below. | None. |

Not findings (verified safe): lock integrity, deploy asset publishing, `InitializeNotificationMailbox` on session-less routes including the Stripe webhook, the `LIKE … escape '!'` change, file-upload naming, Livewire 4.4.7, and authorization hooks. Details follow.

## 1. Lock integrity

- `composer.json` is unchanged, as are `content-hash` and every other top-level key. Exactly **13 of 160** records change (`packages` 125 → 127, `packages-dev` 33 → 33): ten `filament/*` packages v5.8.4 → v5.10.2, `livewire/livewire` v4.4.6 → v4.4.7, and two new packages, `composer/class-map-generator` 1.8.0 and `composer/pcre` 3.4.0. Changed keys per record: `version`, `source`, `dist`, `time`, plus `require` (support), `suggest` (forms) and `support` (livewire). `symfony/console` stays `v7.4.20`.
- `git ls-remote` against upstream: every peeled `v5.10.2^{}` in the ten split repositories matches the lock's source and dist reference. Those repositories are actions, panels (`filament/filament`), forms, infolists, notifications, query-builder, schemas, support, tables and widgets, with references such as notifications `814a681a…` and panels `aaa2a4fc…`. `livewire/livewire` `refs/tags/v4.4.7` = `5976d18b…`, `composer/class-map-generator` `1.8.0` = `c0efbeb2…` and `composer/pcre` `3.4.0` = `d5a341b3…`, all equal to the lock. v5.10.2 is the newest `v5.*` tag.
- The installed vendor matches the lock: each `vendor/filament/*/.git` HEAD equals the lock reference, and each tree is clean. Livewire HEAD is `5976d18b`. `installed.json` lists class-map-generator 1.8.0, pcre 3.4.0 and console v7.4.20.
- `composer validate --strict` reports `./composer.json is valid`. `composer audit --locked` reports "No security vulnerability advisories found."
- **Assets and deploy:** `.gitignore:43-45` ignores `public/{js,css,fonts}/filament/`, and `git ls-files public` lists no Filament file. `post-autoload-dump` runs `@php artisan filament:upgrade`. `ops/staging/release-step.sh:33` runs `composer install --no-dev … --classmap-authoritative` without `--no-scripts`, after installing `.env` (`:31`), in a fresh per-SHA clone. So each release publishes 5.10.2 assets into its own tree, and stale 5.8.4 assets cannot linger. Both new packages are in `require`, so `--no-dev` installs them. `forge-deploy.sh` delegates to the same step, and `scripts/dev/private-alpha-bootstrap.php:114` publishes explicitly with `filament:assets`. Deploys stay safe. `discover_app_classes()`, the only user of `ClassMapGenerator`, is called only from `make:*` commands (grep of the vendor tree).

## 2. The helper (`tests/Support/FilamentNotifications.php`)

- **Read path:** `flush()`, then `new Notifications`, `mount()`, `pullNotificationsFromSession()` and `toArray()`. This is line for line Filament's own `Notification::assertNotified()` (`vendor/filament/notifications/src/Notification.php:253-260`). It does not read session or cache keys directly.
- **Fragility of `flush()`:** it is the one internal call, and it is guarded by `class_exists`. If the class is renamed, nothing is published, the pull returns nothing, and every consumer fails. Mutation M-H1 proves this.
- **Cannot pass vacuously:** `sole()` asserts `count === 1` and `assertNoneContain()` asserts non-empty first. M-nosend proves it for all six privacy tests, which fail at `FilamentNotifications.php:65` (sole) or at the test's own `assertCount(1)`.
- **Re-send semantics:** the original tests read the session without draining it, and the re-send preserves that non-destructive read. In every migrated test, either `assertNotified()` follows the helper call and drains the re-sent copy, or the helper call is the test's last notification step (the Site* tests). No later `assertNotified()` of the same title can be satisfied by a stale copy. The JSON flags (`UNESCAPED_SLASHES|UNICODE`) make the substring checks stricter than the old default `json_encode`, not looser.

## 3. Each migrated test

| File | Before → after | Verdict |
| --- | --- | --- |
| SiteScheduleHttpTest | `firstWhere(title)` + `assertNotNull` → `sole(title)['body']`; body assertions unchanged | Same intent; stricter (exactly one) |
| SiteContentUnavailableHttpTest | same pattern | Same intent; stricter |
| SiteContentDamagedPublicationTest | same pattern | Same intent; stricter |
| LicenseTemplateAuthoringActionTest | `session(...)` + `assertIsArray` + `assertCount(1)` → `flashed()` + `assertCount(1)` + exact title/status; exact `assertNotified(Notification…)` and the later `json_encode($sent)` checks kept | Same intent; stricter |
| LicenseDraftAuthoringActionTest | same as Template | Same intent; stricter |
| MembershipAdministrationActionTest | post-drain session read (vacuous) → `sole(title,'danger')` + `assertNoneContain([detail, success title])` before `assertNotified` | Restores the intended guard |
| BulkLicenseDraftSourceAuthoringActionTest | same as Membership | Restores the guard |
| OfferDraftAuthoringActionTest | same; `'"title":"Saved"'` substring kept (same JSON shape) | Restores the guard |
| RightsDeclarationWriterActionTest | `session('filament.notifications')` after `assertNotified` → `sole` + `assertNoneContain([$data['provenance_reference']])` | Restores the guard; values are ASCII |

Nothing was loosened. No other test reads the notification internals (`grep -rn 'filament\.notifications\|claimed_notifications\|NotificationMailbox' tests`, outside `tests/Fixtures`, finds only the helper and the sitemap constant).

## 4. DiscoverySitemapRegistrationTest

- **Vendor source:** `InitializeNotificationMailbox::handle()` calls `initializeScope()` only `if ($request->hasSession())`, before and after `$next`. It is a no-op without a session (`vendor/filament/notifications/src/Http/Middleware/InitializeNotificationMailbox.php:14-24`). The `RouteMatched` listener adds only this class (`NotificationsServiceProvider.php:53-58`).
- **Exclusion is narrow:** only that exact class name is removed before the exact `assertSame`. Route-middleware mutations that add `StartSession`, `AddQueuedCookiesToResponse` or the `web` group each fail the test at `DiscoverySitemapRegistrationTest.php:56`. The new `getCookies() === []` assertions add a second independent guard.
- **Forged cookie probe** (scratch test, not committed): I sent GET `/track-sitemaps/{32a}/1.xml`, GET `/embed/tracks/no-such-track`, GET `/sitemap.xml` and POST `/webhooks/stripe` (JSON) with a forged `laravel_session`/`synthetic_session`/`XSRF-TOKEN` cookie plus `X-Livewire: 1`. Statuses were 404, 404, 404 and 503 (the webhook route matched; there is no test secret). Results: zero `Set-Cookie`; `RouteMatched` saw `hasSession() === false` for all four (`discovery.tracks`, `embeds.show`, `discovery.index`, `webhooks.stripe`). Cache events covered only throttle keys, with no `filament` key, and the store held no locks. `PublishNotifications` only flushes pending notifications, and it marks a Livewire JSON response that has a `components` array, so it is a no-op on these routes.
- **Other session-less routes:** the full route census shows `webhooks.stripe` `[]`, `embeds.show`/`embeds.preview` throttle only, `discovery.tracks` throttle only, and `up` `[]`. After matching, each gains only `InitializeNotificationMailbox`, never `StartSession` or `web`. The Stripe webhook gains no session and no cache side effect. Global middleware gains `PublishNotifications` (outermost) and nothing else.

## 5. Security, privacy and behaviour

- **Mailbox** (R1 and R3): verdict P2. Merge-blocking only as a doc correction. Pruning is deferrable to a follow-up batch before production.
- **82 `Notification::make()` calls** (count confirmed): spot-checked every call that interpolates. Bodies carry fixed text, validation messages (`$exception->errors()`, staff-facing domain messages), record labels and versions, counts, Stripe-test outcome words (`TestPaymentExceptionResource.php:75,90,138`, `TestUnpaidOrderResource.php:79`), and the media run's stored `failure_message` (`MediaAssetResource.php:88-90`). No body interpolates `getMessage()`, a secret, a token or a customer field. The author's conclusion holds.
- **TOTP** (R4): staff MFA is `AppAuthentication::make()->recoverable()` with `isRequired: TestEnvironment::requiresStaffMfa()` (`app/Providers/Filament/AdminPanelProvider.php:78`). It uses `Cache::store()`, the default store (`HasCacheStore.php:16`), not `filament.cache_store`. Replay protection works on `DatabaseStore` (probe). The legacy key is still read and written, so replay protection survives mixed old/new workers during a deploy. An in-flight MFA challenge from 5.8.4 (an encrypted scalar id) decrypts to a non-array, so `getUserUndertakingMultiFactorAuthenticationData()` returns null and the user is re-challenged: graceful. Rate-limit counters restart. `OperatorMfaTest` (TOTP reuse, recovery replay, role withdrawal) passes.
- **Other upstream changes this app uses:**
  - `authorizeResourceAccess()` drops the parent check, which moves to a hydrate hook. The app has no parent resources (`grep parentResource app` = 0).
  - Login now binds the challenge to `getUserScopedAuthIdentifier` and re-checks it on the replayed credentials. This is a hardening, and the app does not override `Login`.
  - The table search `LIKE ? escape '!'` (`support/src/helpers.php`, `apply_search_constraint`) is valid on MySQL. It was not run on MySQL (P3 untested).
  - FileUpload's default name becomes `hashName()`. All three app uploads use `storeFiles(false)` (`MediaAssetResource.php:33`, `SiteImageResource.php:123`, `SoundKitDraftResource.php:112`).
  - Livewire 4.4.7 changes only `src/ImplicitlyBoundMethod.php` (from the composer VCS mirror `git diff v4.4.6 v4.4.7 -- src`). Parameters resolved by a contextual attribute can no longer be bound from client input, which is a hardening. The app has no such parameters.
  - No weakening found.
- **Compiled-view hygiene:** vendor swaps share compiled-view paths, so I checked that the worktree's cached Filament views were not compiled from 5.8.4. Recompiling every cached Filament view from the current source, with UUIDs normalized, gives 41 exact matches. The 7 differences are only Livewire `@script` keys (`'2862312464-0'` vs `'0-0'`), and none match a 5.8.4 compilation.

## 6. Test evidence (reviewer runs, all at `af7d2e6a`, Filament 5.10.2, SQLite `:memory:`, array cache and session, `public/build` absent)

I ran direct PHPUnit (the brief's command, `--colors=never --fail-on-phpunit-warning`) with one process per file and at most two of my processes at once, under `nice`. Runner: `scratchpad/m3rv/pt.sh`, `worker.sh`. Results: `scratchpad/m3rv/set5102/*.{txt,xml}`.

| Check | Result |
| --- | --- |
| **Filament set, 151 files** (`scratchpad/m3rv/sel.txt`): every `tests/**` file mentioning `Filament`, every `*ActionTest`, every `Site*` test, every file mentioning TOTP, MFA or AppAuthentication. It is a superset of the author's 147, with 4 extra files: `DiscoverySitemapRegistrationTest`, `ProductionFreeGrantFrozenBytesTest`, `BulkLicenseDraftSourceBrowserEvidenceTest`, `OfferDraftBrowserEvidenceTest`. | **2,630 tests: 0 failures, 0 errors, 299 skipped.** All 299 skips are in `scripts/ci/database-sqlite-skips.json` (0 outside the census). One first-pass error, `BulkLicenseDraftSourceBrowserEvidenceTest`, was a `ProcessTimedOutException` from its own 30 s child-process limit at load average about 6 on 4 CPUs. The file is unchanged from main, and it passed when rerun alone: OK (4 tests, 119 assertions). The author's 147-file count (2,616) plus my 4 extra files (14 tests) reconciles to 2,630. |
| Six F4-repaired files | all OK (see R2) |
| 9 migrated files plus the sitemap test | all OK on 5.10.2; all OK on 5.8.4 + LW 4.4.6 in the copy (mutation baselines) |
| `scripts/ci/test-phpunit-shards.py` / `test-ci-scope.py` / `test-workflow-cadence.py` / `test-focused-tests.py` | 52 OK / 27 OK / 14 OK / 53 OK (matches the packet) |
| `composer validate --strict`; `composer audit --locked` | valid; no advisories |
| TOTP probe (`scratchpad/m3rv/totp-probe.php`), mailbox route probe (`scratchpad/m3rv/scratchtests/ReviewerSessionlessRouteProbeTest.php`) and compiled-view check (`viewcheck.php`) | results in §4 and §5 |

Not rerun: vitest, tsc and the build. The frontend is unaffected by a Composer-only change, and the author's results are recorded. I also did not rerun the author's other 531 files.

## 7. Docs and ledger

- **PR #157:** `git cat-file -p 7cd95b73…` shows parents `5007a906…` and `7babebe32439bb26223a0a42bd9afc439816eeef`, message "Merge pull request #157 from SeanVasey/harness/s1-phase2-repairs-20261018". The ledger is correct.
- **C4 wording** (CLAUDE-PLAN.md last entry and the RESUMPTION C4d row): "`explicit_owner_confirmation_v1` … neither legal assent nor write authority. Display wording, consumption and retention terms and every policy, definition, profile and terms hash remain unapproved inputs; everything stays default-off." This matches the brief.
- **RESUMPTION M3 row** ("tested at `e5b482c4`; in independent review"), the F4 row ("Merged as PR #157 (`7cd95b73`)") and the D1/C4d/C4e rows are accurate as status. The D1/C4d/C4e rows are unrelated ledger content carried by this PR by convention (scope note, not a finding).
- **CHANGELOG** is accurate except the stale clause (R2). The mailbox sentence there is accurate, because it does not repeat the "plain text sessions" claim.
- **Secrets:** across all 74 changed files, `grep -E 'base64:[A-Za-z0-9+/]{20,}|APP_KEY=[^ ]|sk_(test|live)_|whsec_|password='` finds only `$(head -c32 /dev/urandom | base64)` generators in `tools/pt.sh` and `tools/runset.sh`. There are no literal keys, and no `base64:` literal appears in any evidence log.
- `git diff --check origin/main...af7d2e6a`: exit 0.

## 8. Scope

The lane touches `composer.lock`, ten test files plus one support helper, the evidence directory, `CHANGELOG.md` and two ledger files. It makes no app, config, route or workflow change. That is in scope. The evidence tools hard-code `/home/user/wt/m3` (cosmetic).

## Mutations

All mutations were applied in an isolated rsync copy of the worktree (`scratchpad/m3rv/wtcopy`, without `.git`), never in `/home/user/wt/m3`. App files were restored from the worktree after each run (`scratchpad/m3rv/mut.py`, `runmut.sh`). Each run uses `--filter uncertain` on the six privacy files.

| ID | Mutation (app or helper) | 5.10.2, migrated tests | 5.8.4 + Livewire 4.4.6, migrated | 5.8.4 + LW 4.4.6, **main's original tests** | 5.10.2, **main's original tests** |
| --- | --- | --- | --- | --- | --- |
| baseline | none | 6/6 OK | 6/6 OK | 6/6 OK | 6/6 OK |
| M-body | append `.$exception->getMessage()` to the uncertain body | **6/6 fail** (Membership, Bulk, Offer, Rights at `FilamentNotifications.php:88`; Template at its exact `assertNotified` `:293`; Draft at `:305`) | **6/6 fail** (same lines) | Membership **OK**, Bulk **OK**, Offer **OK**, Rights **OK** (vacuous); Template fails only at exact `assertNotified` `:292`; Draft fails `:303` | Membership, Draft, Bulk, Offer and Rights **OK** (vacuous); Template fails only at `:292` |
| M-body, Template exact assert replaced by title-only | — | Template fails at its privacy check `:298` | — | — | — |
| M-second | send a separate `Diagnostic` notification whose body is the exception message | **6/6 fail** | — | — | — |
| M-dup | `->send()->send()` (same id) | 6/6 OK (Filament collapses same-id notifications, as the UI does; not a user-visible duplicate) | — | — | — |
| M-dup2 | second send with a new id (a real duplicate) | **6/6 fail** (`sole` `:65`; Template and Draft `assertCount(1)`) | — | — | — |
| M-nosend | drop `->send()` (empty queue) | **6/6 fail** | — | — | — |
| M-H1 | helper `class_exists(NotificationMailbox::class.'Renamed')` (mailbox renamed) | **all fail**: six privacy files; SiteSchedule 6/18, SiteContentUnavailable 1/7, SiteContentDamaged 1/4 | — | — | — |
| Route | add `StartSession` / `AddQueuedCookiesToResponse` / `'web'` to `discovery.tracks` | sitemap test **fails** at `:56` each time | — | — | — |

Also confirmed:

- Main's original Site* tests fail on 5.10.2 (29 tests, 8 failures) and pass on 5.8.4 (29/643 OK).
- Main's original `DiscoverySitemapRegistrationTest` fails on 5.10.2 (5 tests, 1 failure, "two arrays are identical") and passes on 5.8.4 (5/261).
- The migrated sitemap test passes on 5.8.4 (5/263).

The author's claim that four checks were already vacuous on 5.8.4 is **verified**. On 5.10.2, five of the six original checks pass the leak, which is the author's "vacuous on 5.10.2" set.

**5.8.4 tree construction:** `vendor/filament` was replaced by the scratch copy, which I first verified as 5.8.4. Its ten `.git` HEADs equal main's lock references (for example notifications `1e4c9d6a`), and `diff -rq` against `/home/user/VA-Studio/vendor/filament` (5.8.4) differs only in `.git`. Livewire `src/ImplicitlyBoundMethod.php` and `dist/manifest.json` were taken from `v4.4.6` in the composer VCS mirror; those are the only two files that differ between the tags. Then `composer dump-autoload -o --no-scripts` ran, and compiled views were cleared. Without clearing them, stale 5.10 compiled views error with "Undefined variable $getFiltersResetAction". The copy was then restored to 5.10.2: the vendor trees from the worktree and the backed-up `vendor/composer`, with compiled views cleared again.

## Untested conditions

- **MySQL:** the `LIKE … escape '!'` search, the TOTP lock path on a MySQL `cache_locks` table, and mailbox `flush`/`pull` under concurrent requests on MySQL. My probes used a SQLite-backed `DatabaseStore` in one process.
- **Browser and real HTTP round trip:** the flash → redirect → render path through `PublishNotifications`, the `filamentNotifications` flag handled by the new JS, and published 5.10.2 assets in a browser. Playwright specs are CI evidence only.
- **Multi-host cache sharing,** first staff sign-in after a real deploy, and a code-only rollback (R6, reasoned from source, not executed).
- **Upstream release notes** (reviewed from source and tags only).
- I did not rerun the author's remaining 531 PHPUnit files (the full 682-file claim). My PHP evidence is the 151-file Filament set below, the six F4 files and the mutation runs.

## End state (verified)

- `/home/user/wt/m3` HEAD is `af7d2e6a1f357fdad3e82d30ddf622562bfc9b1a`, and `git status --porcelain --untracked-files=all` is empty.
- `vendor/filament` is 5.10.2: `installed.json` lists `v5.10.2`, the notifications HEAD is `814a681a`, and `NotificationMailbox.php` is present. Livewire is v4.4.7.
- `public/build` is absent, and no Filament assets are published under `public/`.
- No mutation or vendor swap was ever made in the worktree. All of them ran in an rsync copy, which is now deleted.
- The 5.8.4 scratch copy is untouched (HEAD `1e4c9d6a`, clean).
- Nothing was committed, pushed or posted.
