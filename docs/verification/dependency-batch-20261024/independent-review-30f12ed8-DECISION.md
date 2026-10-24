# Independent review: dependency batch 2026-10-24 (laravel/framework 13.38.0, vitest 5.0.5, jsdom 30.1.3)

**Verdict: APPROVE WITH CONDITIONS** (one P2 documentation condition, no code or lock defects)

**Reviewed HEAD:** `30f12ed873c3102f8b6c2026617fa09d08af19da` (merge of `origin/main` 5b0a6606 into `harness/dependency-batch-20261024`; parents `0e6b2082` docs, `5b0a6606` main). Lock commit `0989a5b8`, docs commit `0e6b2082`.
Worktree `/home/user/wt/m4`. Nothing was pushed, committed, commented or dispatched. `git status --short` is clean and `public/build` is absent.
Toolchain: PHP 8.4.26, Composer 2.8.12, Node 24.22.0 (nvm; the default PATH Node is 22.22.0, which is below the repo's `>=24.15` and below dom-selector 10's `^22.22.2` floor, so it was not used), PHPUnit 12.5.34.

## Findings

### P2-1. CHANGELOG entry and packet README state facts that are false on the merged HEAD (condition)
The packet describes the tested base `e3e2a180`, but the branch now merges main, which already contains Livewire 4.4.8 (`1b1fb444`, PR #165) and the cache-prune lane. On HEAD:
- CHANGELOG line 75 says "`composer audit` reports the Livewire advisory GHSA-mhf7-xp6r-6qrc, which is already present on main and is fixed by a separate lane." Verified on HEAD: `composer audit --locked` prints "No security vulnerability advisories found." (Livewire v4.4.8). The sentence is unqualified, so the CHANGELOG will be wrong the moment this merges. It also says Livewire is "unchanged"; relative to the old base that is true, relative to main it is not.
- README rows "composer audit ... exit 1" and "red on main until the Livewire lane merges", the delta line `livewire/livewire v4.4.7 unchanged`, and "The app's one scheduled task" (HEAD schedules two: `vasey:publish-scheduled-site-release` everyMinute `withoutOverlapping(5)` and `vasey:cache-prune-expired` hourlyAt(23) `withoutOverlapping(10)`) describe the old base. The README does label the tested source as `0989a5b8`, so it is a historically accurate record, but nothing in it says these items changed on merge, and "216 of 688 test files" is 694 on HEAD.
- CLAUDE.md section 8 treats README statements as claims. Failure path: a later reader trusts the CHANGELOG and believes main still carries an open advisory or that the batch was verified against Livewire 4.4.8 (it was not by the author).
- **Condition:** before or at merge, edit the CHANGELOG sentence (drop "already present on main / separate lane"; say the advisory was cleared by Livewire 4.4.8 already on main and that `composer audit --locked` is clean on the merged tree) and add a short "merged-tree addendum" to the packet README. The addendum can cite the evidence below. This is a text-only change, but it changes the pushed SHA, so any exact-SHA approval must be re-bound to the final SHA (the lock files are byte-identical either way).

### P3-1. The author never ran Laravel 13.38 with Livewire 4.4.8, the D1 mount or the cache-prune command
The author's PHP evidence is from the pre-merge tree. This review closes that gap on SQLite (below). MySQL evidence still does not exist (see untested conditions).

### P3-2. The upstream table omits a few harmless 13.38 changes
`Collection::mode()` floats, `chunk`/`chunkById` `unset($results)` (memory), `whereBetween`/`whereNotBetween` with collection bounds (`EnumeratesValues` `getArrayableItems`), `UniqueJobSkipped` for skipped unique broadcasts, and new public `$countCrashesAsExceptions` on `SendQueuedMailable`, `SendQueuedNotifications` and `CallQueuedListener` (a new serialized property on queued payloads; old payloads keep the default `false`, so a rolling deploy is safe). The app uses none of these paths in a way that changes behaviour (grep of `app/ bootstrap/ routes/ config/`: no `->mode(`, `whereBetween`, `ofMany`, `#[Connection]`, `$connection`, `PreventStaleDatabaseReads`, `usingWritePdo`, `ShouldBeUnique`, `Schedule::job`). Informational only.

No P0 or P1 findings.

## 1. Lock hygiene (HEAD vs origin/main 5b0a6606)
| Check | Result |
| --- | --- |
| `git diff origin/main HEAD --stat` outside the packet and CHANGELOG | only `composer.lock` (10 lines), `package.json` (2 pins), `package-lock.json` (52 lines) |
| `composer.lock` diff | only `laravel/framework`: version v13.37.0 to v13.38.0, source/dist reference `685f8d6c` to `c42f9abd`, `time`. No `content-hash` change. |
| Reference matches upstream tag | `git -C vendor/laravel/framework rev-parse v13.38.0^{commit}` = `c42f9abd4a3d2f9a3cd6be109255ddce8092ceb8` |
| Lock vs installed | 160 lock records equal the 160 in `vendor/composer/installed.json`; `composer install --dry-run`: nothing to install |
| `composer validate --strict` | exit 0 ("./composer.json is valid") |
| `COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked` | "No security vulnerability advisories found." (exit 0) |
| `composer check-platform-reqs` | all requirements success |
| `npm audit --audit-level=high` | "found 0 vulnerabilities", exit 0 |
| `npm ls --all` | exit 0 |
| `package-lock.json` diff | exactly 5 records plus the manifest pins: vitest, `@vitest/mocker`, `@vitest/spy` 5.0.4 to 5.0.5; jsdom 30.1.2 to 30.1.3; `@asamuzakjp/dom-selector` 9.2.4 to 10.0.5 (transitive major required by jsdom 30.1.3, `^10.0.3`; engines `^22.22.2 || ^24.15.0 || >=26.0.0`, satisfied by Node 24.22). jsdom's `undici` range moved to `^8.12.1`; locked undici is 8.12.2, unchanged. Exact pins preserved in `package.json`. |
| npm integrity | `npm view <pkg> dist.integrity` for vitest@5.0.5, jsdom@30.1.3, dom-selector@10.0.5, @vitest/mocker@5.0.5, @vitest/spy@5.0.5 equals the sha512 in the lock for all five |
| Installed versions | laravel/framework 13.38.0, livewire/livewire 4.4.8, filament/filament 5.10.2, symfony/console 7.4.20 (still 7.x), vitest 5.0.5, jsdom 30.1.3 |

## 2. Upstream review, laravel/framework v13.37.0..v13.38.0
Method: `git -C vendor/laravel/framework log/diff v13.37.0 v13.38.0` (31 commits, 85 `src` files, 387+/209-, matching the author's counts). I filtered out comment-only lines and read every remaining changed line (274 lines in 29 files), then grepped the app for each touched API.

**Claim: the only behaviour change relevant to this app is the TransformsRequest 413 guard. Confirmed**, with the caveats in P3-2 (all non-behavioural for this app).

- Auth, session, cookie, encryption, hashing, filesystem, `Http\Request`, cache stores, container, `Authorize` middleware, `SessionGuard`, signed URLs, rate limiting, CSRF: only docblock or `@var` changes. None of these files appear in the non-comment diff.
- Scheduler/queue: `Schedule::dispatchUniqueJobToQueue` now releases the unique lock if dispatch throws. The app has two `Schedule::command(...)` tasks, which do not use that path; `CallbackEvent` and `ManagesAttributes` (where `withoutOverlapping` lives) changed docblocks only. `countCrashesAsExceptions` is additive.
- Database: `Connection::usingWritePdo()` is new (restores in `finally`); no change to transactions, `DatabaseTransactionsManager`, `DatabaseTransactionRecord` (docblock) or locking. `Model::initializeModelAttributes`: for models that declare no `$connection` and no `#[Connection]` the `else` branch is the old code; no app model declares either. One-of-many, `MorphOne` and SQLite `BlueprintState` quoting changes are unused or harmless.
- Validation: date-comparison helpers now return null instead of a `TypeError` for non-string/non-numeric values. The app uses only the plain `date` rule (`LicenseContent`) and Filament `DateTimePicker` with no min/max; `Rule::unique()->ignore()` and `unique:users` are not affected by float support.
- Health route: `/up` closure moved to the invokable `Foundation\Http\HealthCheck`. Body is line-for-line the same; the view path `__DIR__.'/../resources/health-up.blade.php'` resolves to the same file (checked it exists).
- `PreventStaleDatabaseReads` is new and opt-in; the app does not register it. `TrimStrings`/`ConvertEmptyStringsToNull` do not override `cleanArray`, so both inherit the guard; the app registers neither by hand.

### The 413 guard versus uploads and large bodies
Code (`TransformsRequest`): for each request it resets a counter, then walks `$request->query` and either `$request->json()` (JSON requests) or `$request->request` (form/multipart), adding `strlen(key) + strlen(dotted parent prefix)` per array element; above 20 MiB (`20*1024*1024`) it throws `PostTooLargeException` (HTTP 413). It never counts values and never touches `$request->files`.

Empirical probe (throwaway PHPUnit file in the scratchpad, booting the real `bootstrap/app.php` with the app's global middleware stack and a temporary POST route; not in the repo):
| Case | Result |
| --- | --- |
| JSON, one key of 21 MiB | 413 |
| JSON, one key of 19 MiB | 200 |
| JSON, 30 MiB value under a short key | 200 |
| JSON, 100,000 rows with 2 nested keys each | 200 |
| Multipart, 12 MiB uploaded file plus 2 small fields | 200, file received |
| Form field name of 21 MiB | 413 |

Application analysis: the resumable media and sound-kit endpoints (`ResumableUploadPrivacy`) already cap bodies before this middleware runs (prepended): JSON bodies at 4096 bytes, chunk multipart at 8 MiB + 64 KiB with a handful of form fields, so the key budget (even at maximum JSON nesting) cannot be reached by legitimate or attacker bodies on those routes. Chunk bytes are in `files`, not counted. Support-attachment uploads are multipart with one file. Livewire/Filament update requests are JSON whose snapshot is a string value; keys are property paths, orders of magnitude below 20 MiB. A 413 on these endpoints is not a new status class (the app already renders 413 for oversize bodies) and the guard only adds refusal, never admission.

## 3. Test results on merged HEAD
Environment as specified: `APP_ENV=testing`, in-memory SQLite, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `CI=true`, `GITHUB_ACTIONS=true`, `PAO_DISABLE=1`, zero-byte `APP_KEY`, `--fail-on-phpunit-warning`, `--log-junit`, `public/build` absent, direct PHPUnit with worktree autoload, two parallel workers under `nice`.

Selection: 54 files (list kept in the reviewer's session scratch area, not in the repo), 5 chunks of 10-11 files, interleaved. Coverage: LivewireStateIsolation, OperatorMfa, StagingOperatorMfa, five staff-MFA suites incl. ProductionFreeGrantStaffMfa, Licensing/Membership/Promotion/FreeGrant/Inquiry admin, Product/Site/TrackPublication editors, SoundKitDraftAdmin, MediaUpload*, ResumableMediaUpload* (Http, unit, concurrency), SoundKitUpload* (Http, unit, concurrency), SupportAttachment (Http and service), CustomerSessionHttp, CustomerIdentity*, ProductionIdentity (Http, Journey, Notice), SiteScheduleRunner, PruneExpiredCacheRows, ProductionFreeGrantHttp/HttpBinding/HttpConfigurationAdmission, LicenseTemplateAuthoring, BulkUpdateTrackMetadata, PublicTrackEmbedRouteCache, and six unit guard tests (FileUploadPathGuard, SiteImageGuardBytes, CommerceGuardBytes, ServiceProjectJson/Input, BrowserLoginRateLimitFixture).

| Chunk | Files | Tests | Assertions | Failures | Errors | Skipped | Exit |
| --- | --- | --- | --- | --- | --- | --- | --- |
| c0 | 11 | 150 | 1,951 | 0 | 0 | 9 | 0 |
| c1 | 11 | 136 | 1,872 | 0 | 0 | 0 | 0 |
| c2 | 11 | 115 | 1,706 | 0 | 0 | 0 | 0 |
| c3 | 11 | 140 | 1,717 | 0 | 0 | 10 | 0 |
| c4 | 10 | 130 | 1,266 | 0 | 0 | 0 | 0 |
| **Total** | **54** | **671** | **8,512** | **0** | **0** | **19** | all 0 |

Skips: 19, all in `ResumableMediaUploadsConcurrencyTest` (9) and `SoundKitUploadsConcurrencyTest` (10). I wrote my own JUnit summariser (not the author's) and checked every skipped case against `scripts/ci/database-sqlite-skips.json`: 0 outside the census. No PHPUnit warnings or deprecations were printed.

Other checks, all on HEAD:
- `npx tsc --noEmit`: exit 0.
- `npx vitest run`: 68 files, 1,172 tests, 0 failures, 0 skipped (JUnit counted independently). stderr shows two "Not implemented: navigation to another Document" jsdom messages, the same two as in the author's log.
- `python3 scripts/ci/test-phpunit-shards.py` 52 OK; `test-ci-scope.py` 27 OK; `test-workflow-cadence.py` 14 OK; `test-focused-tests.py` 53 OK.
- The author's summary figures (216 files, 2,984 tests, 32,367 assertions, 399 skips, 0 failures) re-add exactly from their committed JUnit files, but that run was on the pre-merge tree.

## 4. Packet and CHANGELOG accuracy and hygiene
- Accurate against my checks: lock deltas, audit and install results, vitest counts, upstream change inventory and the "no behaviour change except the 20 MiB key cap" conclusion, superseded Dependabot PRs and exclusions (not re-checked against GitHub), commit trailers.
- Stale on the merged HEAD: see P2-1.
- Secrets scan of the packet directory (README, tools, metadata, logs, JUnit): no tokens, keys, emails or `session_` ids; no scratch-directory paths and no model identifiers. `tools/run-chunks.sh` has the scratch path replaced by `<scratch directory>` and a mention of "m4-ev". Absolute `/home/user/wt/m4` worktree paths appear in logs and JUnit; 112 files in other committed `docs/verification` packets already do this, so it matches precedent. Only commit trailers carry "Claude Opus 5.5" and the session URL, as the repository's attribution rule requires.

## Untested conditions
- **MySQL 8.4:** none run. The Laravel diff does not touch transactions or locking, but the native-only race tests (the census methods, including the 19 skipped here) have not run against 13.38 + Livewire 4.4.8. CI/native MySQL is the only evidence.
- **Remainder of the PHP suite:** 54 of 694 files on HEAD here (plus the author's 216 of 688 pre-merge); union not computed. The full suite and shard census normalisation were not run.
- **Browser specs (Playwright) and `npm run build`/bundle scan on HEAD:** not run by me; the build and bundle scan exist only in the author's packet on the pre-merge tree. `public/build` was kept absent.
- **Hosted workflows:** none dispatched, per the instructions.
- **Real multi-megabyte production traffic and reverse-proxy limits:** the 413 probe is in-process; nginx/PHP `post_max_size` interaction was not tested.
- **Queued-job rolling deploy** (13.37 worker reading 13.38 payloads): reasoned from the code, not run.
- **GitHub state** (open Dependabot PRs, branch protections): not queried.
