# Independent review — M2 dependency follow-up at 1464ebfc

| | |
| --- | --- |
| **Reviewed SHA** | `1464ebfcfddabf4f80d8c07ceb8d61316991a05e` (branch `harness/dependency-followup-20261018`, worktree `/home/user/wt/m2`) |
| **Base** | `origin/main` `97e865e95bb2a55a805f5da5a9baf6a03c136c5f` (merge-base confirmed equal) |
| **Tested source per packet** | `ad6ee2a9`; the delta `ad6ee2a9..1464ebfc` touches only `docs/` and `CHANGELOG.md` (verified) |
| **Reviewer** | Independent Claude reviewer; did not author the change. No commit, push or GitHub comment was made. |
| **Date** | 2026-10-18 |

## Decision: APPROVE WITH CONDITIONS

The dependency change itself is clean. The lock moves exactly what it claims. Every npm record is accounted for, and the integrity hashes match Dependabot PRs #143 and #144 byte for byte. Audits are clean. The tests reproduce: vitest 1,172/1,172, tsc 0, build OK, and an independent 40-file PHP subset with 518 tests, 0 failures and 0 errors. The six `OrderInquiry*` errors are proven independent of the Laravel bump: they are identical on the same source with framework 13.36.0.

**Condition (R1, P2, docs only, no retest needed):** correct the M3 file count in `CHANGELOG.md:69` and in the packet README at lines 12 and 192. Nine test files read the old Filament session keys, not eight: three fail (8 cases) and six become vacuous. The CHANGELOG also says "five no longer check anything", which should be six. The packet lists all six vacuous files correctly, so the miscount is internal and could under-scope M3.

The P3s may be deferred.

## Findings

| ID | Sev | File:line | Finding | Evidence | Fix |
| --- | --- | --- | --- | --- | --- |
| R1 | P2 | `CHANGELOG.md:69`; `docs/verification/dependency-followup-20261018/README.md:12`, `:192` | The M3 scope is miscounted. CHANGELOG: "eight test files read the old session keys: three fail (8 cases) and five no longer check anything". README: "breaks or empties eight test files" and "migrate all eight files". The real figure is 3 failing + 6 vacuous = **9 files**. The README's own vacuous list has six entries. RESUMPTION row M3 ("eight tests … fail and six … pass vacuously") is correct if "tests" means cases. | `grep -rnE "filament\.(claimed_)?notifications" tests app` → 9 files: MembershipAdministrationAction, SiteContentUnavailableHttp, SiteScheduleHttp, LicenseTemplateAuthoringAction, BulkLicenseDraftSourceAuthoringAction, SiteContentDamagedPublication, OfferDraftAuthoringAction, LicenseDraftAuthoringAction, RightsDeclarationWriterAction. | Say "nine test files: three fail (8 cases) and six no longer check anything" in CHANGELOG and README. |
| R2 | P3 | `docs/verification/dependency-followup-20261018/README.md:133` | "between `9225df8c` and `97e865e9`, main changed only `tests/ops/*.py`" is inaccurate. Main also changed `ops/` scripts (including `ops/production/validate-runtime.php` and `ops/staging/*.sh`), docs and CHANGELOG. The conclusion still holds: no PHP under `app/` or `tests/` changed, and the OrderInquiry tests don't load `ops/`. The reviewer's control on the exact reviewed source also makes it moot. | `git diff --name-only 9225df8c 97e865e9` | Reword: "no file under `app/`, `tests/` (other than `tests/ops/*.py`), `routes/`, `config/` or `database/` changed". |
| R3 | P3 | `.github/dependabot.yml:13` | The exclusion names only the two direct PDF packages. Eleven transitive `tecnickcom/tc-lib-*` renderers (`pdf-image`, `pdf-page`, `pdf-graph`, `pdf-parser`, `pdf-filter`, `pdf-encrypt`, `pdf-sign`, `barcode`, `color`, `file`, `unicode*`) are not named. Normally they move only with `tc-lib-pdf`, which is now excluded, so the intent is met. A wildcard would be robust against a grouped PR resolving them through another path. | `composer.json` requires only `tc-lib-pdf` and `tc-lib-pdf-font`; lock graph shown by `composer why`. | Optional: `exclude-patterns: ["tecnickcom/tc-lib-*"]`. |
| R4 | P3 (info) | upstream `laravel/framework` 13.37.0 | The packet's upstream summary is accurate: 35 commits and 37 `src` files, both verified, and no `ManagesTransactions`, `DatabaseTransactionsManager`, container or session change. It folds some behaviour changes into "the rest". None reaches this app's code paths. (a) `Eloquent\Builder::latest()/oldest()` with no argument now qualifies `created_at` when joins exist; the app has no no-arg `latest()`/`oldest()`. (b) HasOne/HasOneThrough one-of-many existence-query changes; the app has no `ofMany`. (c) `RedirectResponse implements Responsable`; no `Responsable` checks in `app/`. (d) `encrypted:object`/`encrypted:collection` dirty comparison; the app uses only `encrypted` and `encrypted:array`. (e) Cache and Redis `ConcurrencyLimiter`/`DurationLimiter::block()` now time out on `Carbon::now()`, so frozen test time could spin forever; the app uses no `funnel`/`throttle`. (f) The unique-lock release on a failed dispatch only affects `ShouldBeUnique`, which `app/` doesn't use. (g) `MySqlGrammar::compileForeignKeys` adds an `rc.constraint_schema`/`rc.table_name` filter, which only narrows schema introspection. | `diff -ru` of `/home/user/wt/t1/vendor/laravel/framework` (v13.36.0, `e5626e38`) against m2's copy (v13.37.0, `685f8d6c`), plus `grep` over `app/`. | None required. M3 and Foundation should keep (e) in mind if a limiter is ever introduced. |

No P0 or P1 findings.

## Assessment by question

### 1. Lock integrity: PASS

- **composer.lock vs main.** A JSON comparison shows exactly one changed record (`laravel/framework`) across `packages` and `packages-dev`. Nothing was added or removed, and every top-level key, including `content-hash` `1bfc9574…`, is unchanged.
  - `composer.json` is unchanged (`git diff --stat` is empty), so the unchanged `content-hash` is valid.
  - The record's `require-dev`/`suggest` `intervention/image` ^4.2 is upstream metadata only; the package is not installed.
- **References.** `source.reference` = `dist.reference` = `685f8d6c2ab4547287500bc80ccdf2ca12635109`.
  - `git ls-remote https://github.com/laravel/framework.git` returns `685f8d6c…` for `refs/tags/v13.37.0` and `e5626e38…` for `v13.36.0`.
  - `shasum` is `""`, as for every GitHub-zipball record.
  - `vendor/composer/installed.json` shows v13.37.0 `685f8d6c`, and all 158 installed refs equal the lock.
  - `composer show -l` and `Application::VERSION` both give 13.37.0.
  - Filament stays v5.8.4 and Livewire v4.4.6, and no `composer/*` package was added.
- **Composer commands.** `composer validate --strict` → valid, exit 0. `composer audit --locked` → no advisories, exit 0.
- **package-lock.json.** 12 package records change, plus the root manifest echo. Each one traces to a `package.json` bump:
  - Root bumps: react, react-dom, @types/react-dom, @testing-library/user-event, vitest.
  - Required by vitest 5.0.4: `@vitest/mocker` 5.0.4, `chai ^6.3.0`, `obug ^3.0.0`, `std-env ^4.3.0`, `tinybench ^6.2.2`, `tinyexec ^1.3.1`, and `magic-string ^1.4.3`, which the already-locked 1.4.3 satisfies.
  - `@vitest/spy` 5.0.4 comes from `@vitest/mocker`.
  - `@types/react-dom` 19.3.0 peers `@types/react ^19.3.0`, which the locked 19.3.0 satisfies.
  - Every package record's `resolved` URL is on `https://registry.npmjs.org/` and every record has an `integrity` (script check, zero exceptions).
  - Integrity hashes are identical to Dependabot PRs #143 and #144 (read-only `pull_request_read`).
- **npm commands.**
  - `npm ci --ignore-scripts` in a scratch copy → 121 packages, exit 0.
  - `npm ls --all` → exit 0, both in the scratch copy and in m2.
  - `npm audit --audit-level=high` → 0 vulnerabilities.

### 2. Upstream changes: no blocking behaviour change

- **Laravel.** See R4. The app's touchpoints in the diff are `Connection::disconnect` (`@` suppression), the `HasAttributes` `attributeCastCache` unset, and `CacheEventMutex::exists` → `isLocked()`.
  - `IdentityMetadataAdmission` refuses if `id` is in `attributeCastCache`; the change only empties that cache.
  - `withoutOverlapping(5)` is on `vasey:publish-scheduled-site-release` (`routes/console.php:13`).
  - The auth, session, encryption-key, validation-core, signed-URL and transaction-manager classes are unchanged.
- **React 19.3.1.** Diffed against the 19.3.0 copy in `/home/user/wt/t1/node_modules`.
  - `react` and every `react-dom` client and profiling build differ only in the version string.
  - Real changes are confined to `react-dom/server` (Fizz stream chunking and the detached-buffer guard). The app doesn't use SSR: no `react-dom/server` import and no `ssr` in the Vite config.
- **vitest 5.0.4 and chai 6.3.0.** The changes are type annotations, a message-template refactor, worker dependency tracking and a suite-skip brace fix. No assertion-semantic change was seen. The full vitest pass is the evidence.
- **obug 3.0.0.** It is dev-only:
  - lock `dev: true`
  - `npm ls obug` → `vitest@5.0.4 └ obug@3.0.0` only
  - `npm ls --omit=dev obug` → empty
  - after `npx vite build`, `grep -rl obug public/build` gave 0 hits, and the bundle carries React `19.3.1`
  - `public/build` was then removed

### 3. Test evidence: reproduced

| Check (reviewer, on 1464ebfc) | Result |
| --- | --- |
| `npx tsc --noEmit` (Node 24.22.0) | exit 0, no output |
| `npx vitest run` | 68 files, **1,172 passed**, exit 0 |
| `npx vite build` | exit 0. No `obug` in the bundle. `public/build` removed afterwards. |
| PHP SQLite, 40 files: 27 not in the author's 184-file selection plus 13 overlapping. Covers auth/MFA, Filament admin, webhooks and queues, sessions, downloads and concurrency. | **518 tests, 2,968 assertions, 0 failures, 0 errors, 24 skipped.** Every skip is in `scripts/ci/database-sqlite-skips.json`. |
| `OrderInquiryTest` + `OrderInquiryStaffContextTest` on 1464ebfc (13.37.0) | 30 tests, 105 assertions, **6 errors** |
| Same two files on `git archive 1464ebfc` + vendor with `laravel/framework` swapped to 13.36.0 (`e5626e38`, from `/home/user/wt/t1`) | 30 tests, 105 assertions, **6 errors**. Same cases, exception and throw sites (diff of signatures identical). |

**Packet arithmetic.** I re-parsed the retained JUnit:

- Final round: 3,061 tests, 29,168 assertions, 0 failures, 6 errors, 335 skipped.
- First round: 3,061 tests, 29,132 assertions, 8 failures, 6 errors, 335 skipped.
- vitest JUnit: 1,172 tests, 0 failures.
- The final-round XMLs are distinct reruns, not copies of the first round.

**OrderInquiry root cause, from the stack trace.** Line 61 of `OrderInquiryStaffContextTest` makes the admin panel require MFA (`multiFactorAuthentication(..., isRequired: true)`) and then builds the order fixture. That path runs `OrderInquiryFixtures` → `OrderFixtures` → `LicenseFixtures::approved()` → `ReviewLicense::approve` with a reviewer who has no MFA enrollment, so `StaffWriteAuthority.php:122` throws. This is a test-fixture ordering defect, independent of the framework version. It is already assigned to F4.

### 4. Dependabot config: PASS (R3 optional)

- The YAML parses (`yaml.safe_load`).
- `exclude-patterns` is a valid `groups.<name>` key alongside `update-types`.
- The diff adds only the composer `exclude-patterns` line plus its comment, and appends two names to the npm `exclude-patterns`. Nothing else changed.
- Excluded packages get individual PRs, which is the intended effect.

### 5. Docs and ledger accuracy

- **PR #153.** `97e865e9` has parents `6c28d3a3` and `94e84810`, its subject is "Merge pull request #153 …", and `94e84810` is on `origin/harness/production-capability-hold-20261018`. Correct.
- **S1 phase 2.** `p2b1` gives 3,673 tests, 35,014 assertions, 4 failures, 6 errors and 257 files. `p2b2` gives 3,453 tests, 33,998 assertions, 2 failures, 0 errors and 256 files.
  - Totals: **7,126 / 69,012 / 6 / 6 / 513 files**, run in `/home/user/wt/s1` at `4c0854fd`.
  - Twelve cases in six classes: OrderInquiryTest ×4, OrderInquiryStaffContextTest ×2, OrderInquiryMigrationTest ×1, ProductionFreeGrantContentionTest ×3, ProductionFreeGrantStalledSourceTest ×1 and ProductionAccountFeaturesDefaultConfigurationTest ×1.
  - `analyze.py` reports no unlisted skips. Correct.
- **M3 findings.**
  - `NotificationMailbox` mechanism: consistent with the retained diagnostic.
  - 8 failing cases in 3 files: verified in `filament-deferred-m3/php-sqlite/outcomes.json`.
  - Vacuous assertions in 6 files: spot-checked `LicenseTemplateAuthoringActionTest:289-291`, `MembershipAdministrationActionTest:229` and `RightsDeclarationWriterActionTest:317`, all as described.
  - The file total is miscounted; see R1.
- **Laravel review claims.** 35 commits (`git log e5626e38..685f8d6c` in the vendor checkout) and 37 changed `src` files are both verified.
- **Evidence commit scope.** `940c4d42` changes only the packet and CHANGELOG, and `1464ebfc` changes only the two ledger files. Correct.
- **Secrets.** I grepped the packet for `sk_live/test`, `pk_*`, `rk_*`, `whsec_`, `APP_KEY=base64`, `base64:` keys, `password=`/`:`, `Bearer`, `ghp_`, `github_pat_`, `AKIA`, private-key headers, `xox*`, `api_key=` and `secret=`.
  - No credential hits. The only `sk_`/`pk_` substrings are inside test names such as `disk_usage` and `mask_permanent`.
  - Paths are replaced by the placeholders `{worktree}`, `{scratch}` and `{base-copy}`, with no `/home/user` or `/tmp/claude` paths.

### 6. Scope: PASS

The diff is limited to `.github/dependabot.yml`, `CHANGELOG.md`, `composer.lock`, `package.json`, `package-lock.json`, the two ledger files and the new packet directory. The packet includes small helper scripts under `tools/`. No application, test, config or migration file changed.

## Commands run (reviewer)

| Command | Result |
| --- | --- |
| `git diff --stat origin/main...1464ebfc`; `git merge-base origin/main 1464ebfc` | 75 files, all within scope; merge-base `97e865e9` |
| `composer validate --strict` / `composer audit --locked` / `composer show -l laravel/framework` | valid / no advisories / v13.37.0 `685f8d6c` |
| `git ls-remote https://github.com/laravel/framework.git 'refs/tags/v13.3[67].0*'` | `e5626e38` v13.36.0; `685f8d6c` v13.37.0 |
| Python JSON comparison: lock vs main, installed.json vs lock | only `laravel/framework` changed; 158/158 match |
| Python requirer and URL trace over `package-lock.json` | all changes explained; registry-only; all integrity present |
| `npm ci --ignore-scripts` (scratch), `npm ls --all`, `npm ls obug`, `npm ls --omit=dev obug`, `npm audit --audit-level=high` | 0 / 0 / vitest→obug@3.0.0 / empty / 0 vulnerabilities |
| `diff -rq`/`diff -ru` of the 13.36.0 and 13.37.0 framework trees; React and React DOM, vitest and chai vs the t1 copies | see §2 and R4 |
| `npx tsc --noEmit`; `npx vitest run`; `npx vite build`; `grep -rl obug public/build`; `rm -rf public/build` | 0; 1,172 passed; 0; 0 hits; removed |
| Direct PHPUnit (SQLite CI env) on 40 files | 518 tests, 2,968 assertions, 0 F, 0 E, 24 S (all in census) |
| Direct PHPUnit on the two OrderInquiry files, at 13.37.0 and at 13.36.0 | 6 errors each, identical signatures |
| `python3 …/s1/analyze.py p2b1.junit.xml` / `p2b2.junit.xml` | totals as in §5 |
| `mcp__github__pull_request_read` `get_files`/`get_diff` on #144 and #143 (read-only) | records and integrity identical to this branch |
| Secret-pattern grep over the packet | no credentials |
| Final `git status --short`; `git rev-parse HEAD`; `ls public/build` | clean; `1464ebfc…`; absent |

Scratch copies (`npmci`, and `basefw` with its hard-linked vendor) were deleted. The m2 vendor still reports 13.37.0, and no tracked file changed.

## Untested conditions

- **Native MySQL.** No MySQL run. Census-skipped races and the M1 native case remain unproven for 13.37.0, including the `@` disconnect change and the MySQL `compileForeignKeys` filter.
- **PHP coverage.** I ran 40 of 682 PHP files. The author's 184-file run was verified from its retained JUnit, not re-executed in full.
- **Not run.** Browser, Playwright and WebKit tests, hosted CI and Foundation. React 19.3.1 was exercised only via jsdom and the build.
- **Fresh install.** No fresh `composer install` from the lock into a clean vendor. The vendor was checked reference-for-reference against the lock instead.
- **Upstream changelogs.** The vitest 5.0.4 and chai 6.3.0 release notes were not read. I reviewed the installed dist diffs only.
- **M3 claims.** The Livewire 4.4.7 claim ("only `ImplicitlyBoundMethod` changes") and the Filament 5.10 panel-change list were not independently verified; they are M3 material, not part of this tree.
- **Dependabot.** Runtime behaviour of the new exclusions is unproven until the next scheduled Dependabot run.
