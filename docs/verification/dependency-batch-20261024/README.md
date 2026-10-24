# Dependency batch — October 24, 2026

Lane M4 moves one Composer package and two exact-pinned npm packages (plus one transitive record). Every move is patch or minor.

| | |
| --- | --- |
| **Tested source** | `0989a5b8ca43bd0f51e90d43351c0ec6bc3d14f5` on branch `harness/dependency-batch-20261024`. Its tree is `origin/main` at `e3e2a180` (PR #159) plus this lane's lock and manifest change. |
| **Evidence commit** | The commit that follows changes only this directory and `CHANGELOG.md`. |
| **Toolchain** | PHP 8.4.26, Composer 2.8.12, Node 24.22.0, npm 11.19.1. |
| **Not done** | Nothing was pushed, no PR was opened, commented on or closed, and no workflow was dispatched. |

## What changes

| Ecosystem | Package | Before | After | Manifest |
| --- | --- | --- | --- | --- |
| Composer | `laravel/framework` | v13.37.0 (`685f8d6c`) | v13.38.0 (`c42f9abd`) | unchanged (`^13.17`) |
| npm | `vitest` | 5.0.4 | 5.0.5 | `5.0.4` to `5.0.5` (exact pin kept) |
| npm | `jsdom` | 30.1.2 | 30.1.3 | `30.1.2` to `30.1.3` (exact pin kept) |

### Composer: exactly one of 160 records changes ([delta](metadata/composer-lock-delta.txt))

- Only `laravel/framework` changes: its version, reference and release time. Its `require`, `require-dev`, `suggest`, `conflict` and `replace` are identical to v13.37.0. Nothing is added or removed, and `content-hash` is unchanged.
- **Checked unchanged against main:** `symfony/console` (v7.4.20, still 7.4.x), `livewire/livewire` (v4.4.7), `filament/filament` (v5.10.2), `tecnickcom/tc-lib-pdf` (8.76.3), `tecnickcom/tc-lib-pdf-font` (4.4.1), `stripe/stripe-php` (v21.3.2), `phpunit/phpunit` (12.5.34) and `inertiajs/inertia-laravel` (v3.5.1).
- **Installed versions match:** all 160 installed versions equal the lock (`vendor/composer/installed.json`), with no extras.
- **Code:** no file under `app/`, `tests/`, `routes/`, `config/`, `database/` or `resources/` changes.

**Commands.** `composer update laravel/framework --with laravel/framework:13.38.0 --no-scripts --no-install` ([dry run](install/composer-update-laravel-dry-run.txt), [lock run](install/composer-update-laravel-lock.txt)) reported "1 update". `composer install --no-scripts` then synced `vendor/` ([log](install/composer-install.txt)). `--with-dependencies` was not used. It is not needed, and it is the flag that pulls `symfony/console` 8.

### npm: 5 records change, none added or removed, lockfile version 3 ([delta](metadata/npm-lock-delta.txt))

- `vitest` 5.0.5 with `@vitest/mocker` and `@vitest/spy` 5.0.5 (vitest pins them exactly).
- `jsdom` 30.1.3.
- **`@asamuzakjp/dom-selector` 9.2.4 to 10.0.5, a transitive major.** jsdom 30.1.3 itself requires it (`"@asamuzakjp/dom-selector": "^10.0.3"`; 30.1.2 required `^9.2.4`). Both versions declare the same four dependencies and the same Node range (`^22.22.2 || ^24.15.0 || >=26.0.0`). It is dev-only, reached only through jsdom. It is the same kind of record as `obug` 3.0.0 in lane M2: a major that the requested patch pulls in, not one taken by hand.
- jsdom 30.1.3 also raises its `undici` range from `^8.11.2` to `^8.12.1`. The locked `undici` already satisfied it, so that record does not change.
- All five records are `dev: true`. Production scope (`react`, `react-dom`, Inertia and the rest) is untouched.

**Commands.** `npm install --save-exact --package-lock-only --ignore-scripts vitest@5.0.5 jsdom@30.1.3` ([log](install/npm-install.txt)), then a frozen `npm ci` ([log](install/npm-ci.txt)): 121 packages added, 0 vulnerabilities. `npm ls --all` exits 0 ([tree](metadata/npm-ls-all.txt)).

## Dependabot PRs this supersedes

Once this branch merges, these three Dependabot PRs are redundant and can be closed by the lead. This lane closed none of them.

| PR | Title | Covered by |
| --- | --- | --- |
| #160 | Bump `laravel/framework` 13.37.0 to 13.38.0 in the `composer-minor-patch` group | The `laravel/framework` record above. |
| #163 | Bump `vitest` 5.0.4 to 5.0.5 | The `vitest` record above, exact pin kept. |
| #156 | Bump `jsdom` 30.1.2 to 30.1.3 | The `jsdom` record above, exact pin kept. |

Dependabot titles #160 as the "composer-minor-patch group", but the group's only move in this lock is `laravel/framework`. Any other record Dependabot may have added to that PR is not taken here.

## Not in this batch

| PR | Package | Why it is not here |
| --- | --- | --- |
| #161 | `phpunit/phpunit` 12.5.34 to 13.4.3 | A major. It needs its own commit with the changelog reviewed and the breaking changes noted (CLAUDE.md section 6). `scripts/ci/phpunit-shards.py` builds shard configurations against PHPUnit's test-suite XML schema and CLI contract (its docstring cites the 12.5 docs), and the SQLite and MySQL skip censuses key on PHPUnit's reporting, so those must be shown to work on 13 first. |
| #162, #164 | `tecnickcom/tc-lib-pdf` (8.76.3 to 8.81.1) and `tecnickcom/tc-lib-pdf-font` (4.4.1 to 4.6.2) | A frozen-contract renderer lane. See below. |
| #71 | `stripe/stripe-php` 21.3.2 to 22.0.0 | A major SDK for the payment path, and it is pinned in code. `BillingProviderPin` (`SDK_VERSION = 'v21.3.2'` plus the exact `SDK_REFERENCE`) and `StripeCapabilityPreflight` (`EXPECTED_SDK_VERSION = '21.3.2'`) refuse a different installed version, so a bump needs its own reviewed pin change and adversarial payment tests. |
| #145 | `@playwright/test` 1.63.0 to 1.64.1 | Playwright must match the pinned WebKit backport (`scripts/ci/apply-playwright-webkit-offline-backport.mjs` rejects any version but 1.63.0). CI is the only evidence for browser specs. |
| #100, #101, #102 | `actions/setup-node` (to 7.1.0), `actions/download-artifact` (to 8.0.2), `actions/upload-artifact` (to 7.0.2) | Majors. The workflows pin these by commit SHA at v4. They need their own review of the new Node runtime and the artifact-format break, and CI is the only evidence. |

### What the tc-lib-pdf lane must prove

The renderer is bound to the exact installed versions. A bump therefore does not merely change output: it makes the frozen profiles refuse.

- `resources/contracts/test-v1/profile-assets.json` pins `tc-lib-pdf` 8.76.2 (`c383bd3a…`) and `tc-lib-pdf-font` 4.4.0 (`658e0456…`).
- `resources/contracts/test-v2/profile-assets.json` pins 8.76.3 (`d417129f…`) and 4.4.1 (`a78b8e0a…`), plus the other `tc-lib-*` packages and the font source (`tecnickcom/tc-font-mirror` 2.4.0).
- `ContractRenderProfile` compares each pinned name, version and reference with `Composer\InstalledVersions` and rejects a mismatch. `ContractRenderProfileRegistry` holds the explicit profiles `test-buyer-pdf-v1` and `test-buyer-pdf-v2` and a manifest SHA-256 for v2. `MemberGrantRenderProfileRegistry` repeats the pins for the member profiles.
- The grant renderers load the packages by `InstalledVersions` install path: `FreeGrantRendererProcess`, `PaidGrantRendererProcess`, `PaidTaxRendererProcess`, `ProductionFreeGrantRendererProcess` and `MemberOriginalRendererProcess`.
- `composer.json` constrains them as `^8.76.2` and `^4.4.0`.

The lane must therefore show, with evidence:

1. Whether to add a **successor profile** (a new version string, its own `profile-assets.json`, reviewed metadata, manifest hash and issuance policy). Frozen profiles and retained contracts must not be edited, and `resources/contracts/` must not change for existing versions.
2. That existing profiles keep verifying and issuing byte-for-byte what they did, or that the lane states exactly which retained originals it may re-render and which it must never touch.
3. Rendered-PDF comparison old against new for each frozen template (`frozen-grant-sections-v1`), including fonts (`dejavusans`, no subset, no compression, Latin/Greek/Cyrillic/Common only), page geometry and the plain-PDF conformance, with the font-conversion flags unchanged.
4. The contract profile and grant renderer suites pass: `ContractRenderProfileRegistryTest`, `TestContractProfileSuccessorTest`, `TestContractSuccessorActivationTest` and the Free, Paid, Paid Tax, Production Free and Member renderer tests.
5. The two packages are bumped together: `tc-lib-pdf` requires `tc-lib-pdf-font ^4.4`, and the font bump (4.6.x) may need a matching `tc-lib-pdf` range.
6. Dependabot keeps both excluded from the grouped PR (`exclude-patterns` in `.github/dependabot.yml`, from lane M2).

Note: #162 targets 8.81.1, but `composer outdated` now offers `tc-lib-pdf` 8.82.2 and `tc-lib-pdf-font` 4.6.2. The lane should choose its target version explicitly.

## Laravel 13.37.0 to 13.38.0 review

I read the source diff `685f8d6c..c42f9abd` (31 commits, 85 `src` files, 387 insertions and 209 deletions) from Composer's VCS cache. About 55 of the files change only docblocks, imports or `@var` nullability. These are the parts that touch features this app uses:

| Area | Change in 13.38.0 | Effect here |
| --- | --- | --- |
| **Database / locking** | New `Connection::usingWritePdo(Closure)` (and a `DB` facade docblock): runs a callback with reads on the write PDO and restores the previous setting in `finally`. | Additive. No transaction, `ManagesTransactions`, `DatabaseTransactionsManager`, lock or `lockForUpdate` code changes. The app does not call it. |
| **Validation** | `ValidatesAttributes`: the date comparison rules now return a failure instead of a `TypeError` on non-date values (arrays and so on). `Unique` and `DatabaseRule` accept floats. | The only change in behaviour: a non-string, non-numeric value fed to a date rule fails validation cleanly. It cannot make an invalid input pass. The app's `unique` uses are the Filament slug fields (`->unique(ignoreRecord: true)`), and its only date rule is the plain `date` rule in `LicenseContent` (no `after`, `before`, `date_equals` or other comparison rule). |
| **Request handling** | `TransformsRequest` (the base of `TrimStrings` and `ConvertEmptyStringsToNull`) now sums the byte length of every input key per request and throws `PostTooLargeException` (HTTP 413) past 20 MiB of keys. | A new, deliberate denial-of-service guard. Ordinary requests are nowhere near it. The app registers neither middleware by hand, so it takes Laravel's defaults. |
| **Health route** | The `/up` route body moves into `Illuminate\Foundation\Http\HealthCheck` (an invokable class). The logic is identical. | `bootstrap/app.php` sets `health: '/up'`. Same responses, and it is now friendlier to route caching. |
| **Scheduling / queue** | `Schedule::dispatchUniqueJobToQueue` releases the unique lock when dispatch throws. `CountCrashesAsExceptions` is supported on queued listeners, mailables and notifications (new `countCrashesAsExceptions` property, default `false`). `UniqueJobSkipped` is dispatched for a skipped unique broadcast. `NotificationSender` resets its failed-event flag before each channel send. | The app's one scheduled task, `vasey:publish-scheduled-site-release` with `withoutOverlapping(5)`, is not a unique job and does not change. No queued class uses the new attribute. The notification flag fix only removes a stale state between channels. |
| **Eloquent** | `Model::initializeModelAttributes` resolves the `#[Connection]` attribute differently when a parent class declares `$connection`. `Relation::clone()` is new. One-of-many relations fix aliasing and self-reference. `MorphOne` existence queries use the right names. | The app declares no `#[Connection]` attribute and no `$connection` property on a model, and uses no `ofMany` relation. Nothing to affect. |
| **Schema (SQLite)** | `BlueprintState` wraps the foreign table name when altering a SQLite table. | Fixes tables whose name needs quoting. App migrations that alter tables are unaffected. |
| **Collections / cache / lazy** | `mode()` handles float values. `lazy` and `lazyById` free the previous chunk (memory only). `until` is removed from the higher-order collection proxies. `MemoizedTaggedCache` and `DatabaseStore` change docblocks only. | The app calls neither `->until` nor `->mode(`. Its one `lazyById(20)` (`PublicSiteImageController`) gets the memory release and the same rows. |
| **Cloud** | `LaravelCloudJsonFormatter` is removed in favour of `Foundation\Cloud\JsonFormatter`. | Laravel Cloud only. The app does not reference either. |
| **Auth, session, encryption, cookies, hashing, filesystem, container** | Docblock and `@var` nullability only in `SessionGuard`, `AuthManager`, `Authorize` middleware, `Encrypter`, `CookieJar`, the hashers, `LocalFilesystemAdapter` and `Container`. | No behaviour change. Signed URLs and rate limiting are untouched. |

Nothing here changes behaviour for the app's ordinary traffic; the one deliberate new refusal is the 20 MiB key-size guard above. `PreventStaleDatabaseReads` is a new opt-in middleware and the app does not register it. The vendor tree's `Application::VERSION` is `13.38.0`.

**Reflection targets** ([output](metadata/reflection-targets.txt)): `tools/reflection-targets.php` finds the same 73 distinct `Vendor\Class::class, 'member'` pairs in `app/` as lane M2. 56 resolve to an existing member. The other 17 are exception reason codes, not member names.

**Class loading** ([output](metadata/load-app-classes.txt)): `tools/load-app-classes.php` loads 1,120 class-like files under `app/` with 0 errors.

## Verification results (tested source `0989a5b8`)

| Check | Result |
| --- | --- |
| `composer validate --strict` | 0 ([log](install/composer-validate.txt)) |
| `composer check-platform-reqs` | 0, every requirement success ([log](install/composer-platform.txt)) |
| `composer audit --locked` | **exit 1: one advisory, `livewire/livewire` GHSA-mhf7-xp6r-6qrc** (medium; affects `>=4.3.0,<4.4.8`; locked v4.4.7). It is **pre-existing on main**: the advisory was published on 2026-10-23, after lane M2, and main's lock fails identically ([baseline](metadata/composer-audit-main-baseline-livewire.txt), [this branch](metadata/composer-audit-locked.txt)). It is **fixed by the separate Livewire lane** (`harness/livewire-448-20261024`, which updates only `livewire/livewire`), not here. This batch adds no advisory. The app declares no `#[Authorize]` or `#[Json]` Livewire attribute. It uses only `#[Locked]`. |
| `npm ci` / `npm ls --all` / `npm audit --audit-level=high` | 0 (121 packages) / 0 / 0 (0 vulnerabilities) |
| `npx tsc --noEmit` | exit 0 ([log](frontend/typecheck.txt)) |
| `npx vitest run` | **68 files, 1,172 tests, all passed** (0 failures, errors or skips; [log](frontend/frontend.txt), [JUnit](frontend/frontend.xml)) |
| `npm run build` | exit 0 ([log](frontend/build.txt)) |
| `python3 scripts/ci/scan-client-bundle.py public/build` | 0: 14 bundle files, 9 secret names and Stripe key prefixes, no hits ([log](frontend/scan-production.txt)). `public/build` was then deleted and stayed absent for every PHP run. |
| Python safeguards | `test-phpunit-shards.py` 52 OK, `test-ci-scope.py` 27 OK, `test-workflow-cadence.py` 14 OK, `test-focused-tests.py` 53 OK ([log](preflight/python-safeguards.txt)) |
| `vendor/bin/pint --test` | not applicable: no PHP file changed |
| PHP on SQLite, 216 files | **2,984 tests, 32,367 assertions: 0 failures, 0 errors, 399 skipped** |

### PHP on SQLite

The selection is `tools/select.py` over the 216 `tests/Feature` and `tests/Unit` files that exist (213 and 3). For each of nine areas it takes up to 30 files whose path matches, then fills the quota with content matches. It takes every file in a small area (webhooks, 22 files). A file can sit in several areas, which gives 216 unique files ([list](php-sqlite/selection-all.txt)). Counting by the same patterns:

| Area | Files in the selection |
| --- | --- |
| Auth, MFA, sign-in, customer identity, passwords | 67 |
| Filament admin, `/admin`, Livewire | 57 |
| Queues, jobs, buses | 73 |
| Sessions | 45 |
| Transactions, locking, concurrency, `afterCommit` | 105 |
| Downloads, signed URLs | 30 |
| Webhooks | 22 |
| Checkout | 70 |
| Membership | 38 |

The files ran in 18 chunks of 12, interleaved so that heavy files spread, by two workers (two PHP processes at a time, under `nice -n 10`) with the CI-parity SQLite environment: `APP_ENV=testing`, in-memory SQLite, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `CI=true`, a random `APP_KEY`, and `--fail-on-phpunit-warning`. Chunks took 3 to 30 minutes each ([status](php-sqlite/chunk-status.txt), [progress log](php-sqlite/PROGRESS.md), [`run-chunks.sh`](tools/run-chunks.sh)). Every chunk exited 0. Per-file JUnit and console logs are `php-sqlite/cNN.xml` and `cNN.txt`, and [outcomes](php-sqlite/outcomes.json) summarises them.

- **Skips:** all 399 skipped cases are methods in the reviewed SQLite census `scripts/ci/database-sqlite-skips.json`. `skipped_outside_census` is empty in every chunk. These are native-only MySQL races, so SQLite proves nothing about them.
- **No base failures:** the six `OrderInquiry*` errors that lane M2 recorded on main did not recur. The selection includes three `OrderInquiry*` files, and they passed.
- `public/build` was absent throughout.

## Untested conditions

- **MySQL.** Every PHP result is SQLite. No MySQL 8.4 was available in this session, and the 399 skipped cases are exactly the ones SQLite cannot run. Laravel 13.38.0 does not change transactions or locking, but the MySQL race and lock tests have not run against it. The twenty-four MySQL CI shards are the evidence for those.
- **The rest of the PHP suite.** 216 of 688 test files ran. The other 472 did not.
- **Browser specs.** Not run. The installed Playwright browsers do not match the pinned version.
- **jsdom and vitest in CI.** They ran here on Node 24.22.0 only.
- **Hosted workflows.** None was dispatched. Foundation CI stays a manual final verification.
- **`composer audit` is red on main** until the Livewire lane merges. After that, `composer audit --locked` must be re-run on the merged result.

## Adjacent, not taken

`composer outdated` and `npm outdated` also report, outside this batch:

- Composer: `filament/filament` 5.10.2 to 5.10.3 and `inertiajs/inertia-laravel` 3.5.1 to 3.5.2 (patches). Livewire is the separate lane.
- npm: `vite` 8.3.4 to 8.3.5 (patch, `^8.3.4` already allows it). `@types/node` 26 is a major, and a Dependabot rule ignores it. `npm outdated` lists jsdom's "Latest" as 29.1.1, which looks like an artefact: the registry's `latest` dist-tag is 30.1.3, the version now locked.

## Merged-tree addendum (independent review)

Everything above was measured on the pre-merge tree (base `e3e2a180`, lock commit `0989a5b8`). The branch then merged `origin/main`
at `5b0a6606`, which brought Livewire 4.4.8 (GHSA-mhf7-xp6r-6qrc, through PR #165), the D1 production free-grant HTTP mount and the
`vasey:cache-prune-expired` schedule (PR #166). On the merged HEAD `30f12ed8`:

- The lock diff against main is exactly `laravel/framework` 13.37.0 to 13.38.0 in `composer.lock` and the five npm records above.
  `composer audit --locked` reports **no advisories**, so the Livewire rows above (audit exit 1, Livewire 4.4.7, "red on main") describe the
  pre-merge tree only.
- The app now schedules two commands: `vasey:publish-scheduled-site-release` (`withoutOverlapping(5)`) and `vasey:cache-prune-expired`
  (`hourlyAt(23)`, `withoutOverlapping(10)`). Neither is a scheduled unique job, so the 13.38 unique-lock release change does not apply.
- The PHP suite has 694 test files on the merged tree (688 before the merge).
- The [independent review](independent-review-30f12ed8-DECISION.md) (APPROVE WITH CONDITIONS, no P0 or P1) re-read every changed upstream
  line, probed the 413 key-size guard in-process (it counts key bytes only, never values or files; the resumable upload endpoints cap
  bodies far earlier) and ran 54 files on the merged tree: 671 tests, 8,512 assertions, 0 failures, 0 errors, 19 census skips, plus
  `tsc` and Vitest (1,172 tests). Its P2-1 (these stale statements) is fixed by this addendum and the CHANGELOG wording. P3-2 adds
  harmless upstream changes missing from the table above: `Collection::mode()` with floats, `whereBetween` with collection bounds,
  memory frees in `chunk`/`lazy`, `UniqueJobSkipped` for skipped unique broadcasts and the serialized `countCrashesAsExceptions` property.
- `npm run build` on the merged HEAD succeeds and `scripts/ci/scan-client-bundle.py` finds no secret names in its 14 files.
- Still untested: native MySQL against 13.38 with Livewire 4.4.8, the rest of the PHP suite, browser specs, reverse-proxy limits and
  queued-payload compatibility during a rolling deploy.
