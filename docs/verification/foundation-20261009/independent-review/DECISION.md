# Independent review: harness/foundation-fixes-20261009

- Reviewed: `git diff 0f9b39ce5d59e2d579d11a8ca15153ced1f565b4..e2b3906b4a96af9c169f29e61071a34b535245b7` (2 commits: 9712e68 fix(migrations), e2b3906 test(browser)).
- Source for every run: a clean `git archive e2b3906` export at `../fnd-review-src`. Its vendor is hard-linked from /home/user/wt-fnd, with its own copy of `vendor/composer` and `vendor/autoload.php` and a fresh `composer dump-autoload`. The classmap resolves `App\Support\MigrationDefinitions` to the export. No `public/build` in the export or in wt-fnd. Nothing tracked in /home/user/wt-fnd was changed (`git status` clean, HEAD e2b3906). No commits, no pushes.
- Reviewer: Claude (independent reviewer subagent), 2026-10-09.

## Decision: APPROVE WITH CONDITIONS

The code change is semantically safe and correct on both engines, and the regression test is effective. The fixture change mirrors its two sibling fixtures exactly. The conditions apply to the committed documentation only.

### Conditions (before merge)

1. **(Medium) Fill or remove the placeholder in `docs/verification/foundation-20261009/README.md`.** Line 89 of the Results table reads `FIXED_PLACEHOLDER` and cites `evidence/sqlite-shard-*-fixed-512M.txt`, but no such file exists at e2b3906. As committed, this is a claim with no evidence behind it (CLAUDE.md §1.2 and §1.4). Record the real outcome of the two SQLite shard runs now in progress, with the exact source SHA, or drop the row. This review did not run full shards.
2. **(Low) Correct the scope statement in the README and CHANGELOG.** Both say the requires happened "on every migration run" and that "Production runs migrations once per process, so it is not affected in practice". At e2b3906 that is incomplete. `ProductionFeatureSchema::assertHeld()` is a runtime path: `ProductionFeatureContext::schema()` (ProductionFeatureContext.php:278) calls `$admission->dependencies()` and then `legacyDependencies()`, and both load these migration files. That context is used by ProductionConsentPreferences, ProductionConsentWithdrawal(Reader), ProductionListeningLibrary and the ProductionSuppression* services. Before the fix, a long-lived worker (queue worker, or Octane if ever used) recompiled three files on every such operation and leaked the same way. The fix covers this too. Under PHP-FPM the leak was request-scoped. One accurate sentence is enough. No code change is needed.

## Findings

| # | Severity | Finding |
|---|---|---|
| F1 | Medium (docs) | `FIXED_PLACEHOLDER` in the README Results table; the cited evidence file is missing (condition 1). |
| F2 | Low (docs) | The "migration runs only / production unaffected" framing misses the runtime `assertHeld()` path (condition 2). |
| F3 | Info | The CHANGELOG and docblock say the cache works "as Laravel's migrator does". Laravel's `Migrator::resolvePath()` re-requires a migration that defines `__construct`; `MigrationDefinitions::load()` always clones. None of the three target files has a constructor (verified), so behaviour matches today. If a future caller loads a migration with a stateful constructor, the clone would carry state from the first construction. Optional: add the same `method_exists(..., '__construct')` rule, or note it in the docblock. |
| F4 | Info | Many tests still `require` migration files directly (for example CustomerAccountMigrationTest, InquiryNotificationMigrationTest, OrderPreparationMigrationTest). Each such call leaks a little. They are bounded per test and out of scope here; listed, not fixed. |
| F5 | Info | The fixture's new helper is a global `epoch()`, while the siblings use prefixed names (`offerDraftEpoch`, `bulkLicenseSourceEpoch`). It is consistent with this file's existing unprefixed `rows()` and `guards()`, and each fixture runs as its own CLI process (`execFileSync('php', [...])` in license-template-authoring.spec.ts). No other file in tests/ declares a global `epoch()`. No collision. |

## Q1: Is the cached clone semantically safe at each call site?

Call sites, all four replaced. At e2b3906, `git grep` finds no runtime `require` of a migration left under `app/` or `database/migrations`.

| Site | File loaded | Reflected methods |
|---|---|---|
| ConsentMigrationAdmission::dependencies():48 | 2026_10_06_000040_customer_accounts.php | `guards()` |
| SuppressionSchema::dependencies():199 | 2026_10_07_250000_customer_consent.php | `triggers($driver)`, `definition($driver,$t)`, `owned($pdo,$driver,$type,$name,$sql)` |
| ProductionFeatureSchema::legacyDependencies():344 | 2026_10_07_250000_customer_consent.php | same as above |
| ProductionFeatureSchema::legacyDependencies():365 | 2026_10_07_242000_customer_saved_tracks.php | `owned($pdo,$driver)` (via `definition`) |

**State.** I read all four method families. None of the three anonymous classes declares a property (only class constants: `TABLES`, `TABLE`, `MARKER`), has a constructor, uses `static` locals, or assigns to `$this->*`. `guards()` reads `DB::getDriverName()` when it is called, and the others take `$pdo`/`$driver` as arguments. All are pure functions of their arguments, the facade, and live DB dictionary reads. The only inherited state is `Migration::$connection` (null) and `$withinTransaction` (true). Nothing mutates it, and `load()` hands out a clone, never the prototype. A shared or cloned prototype therefore has nothing to leak between calls.

Probe evidence (`probe-equivalence.txt`, rc=0): for each file, a fresh `require`, `load()` and a second `load()` give identical property state, the same class, and distinct instances. They also give byte-identical outputs for `guards()`, `triggers('sqlite'|'mysql')`, `definition(sqlite|mysql, each consent table)` and the saved-tracks `definition(sqlite|mysql)`. Result: `RESULT equivalent`.

**Staleness and proof weakening.** The ownership and admission proofs compare the live schema against definitions computed from approved, immutable migration code. They do not prove anything about the file on disk. No test or code path rewrites, chmods, copies or relocates these migration files mid-process. I searched tests/ and app/ for `file_put_contents`, `copy` or `put` targeting `migrations` and for `useDatabasePath`, and found none. The cache key is the full `database_path()` path, so a changed database path would simply load again. If `require` throws, nothing is cached and the next call retries; a non-object result fails closed at `clone`. The proofs are not weakened.

**PHP-FPM, queue workers, Octane.** The premise "migrations only" is wrong (F2), so I checked runtime use too.
- PHP-FPM: user-class static properties reset at the end of each request, so there is at most one compile per file per request, and nothing persists across requests. Equal to or better than before.
- Queue workers and Octane: the cache lives for the worker's lifetime and reflects the code present at worker start. That is the same staleness model as every autoloaded class, and Laravel already requires `queue:restart` on deploy. Before the fix, these workers leaked about 18 anonymous classes per production-feature operation; now they don't.

## Q2: MySQL vs SQLite behaviour

The change is driver-agnostic: the same objects, the same methods, and the same `$driver` argument as before. The MySQL branches of `owned()` call `$this->triggers('mysql')`, `$this->columns()`, `$this->indexes()` and `$this->foreign()`, which are pure, and `probe-equivalence` shows the mysql outputs are identical between a fresh require and a cached clone.

The brief assumed no MySQL was available. The container does have `mysqld` 8.0.46 (apt), not the 8.4 used in CI and production. I ran a **private** instance on its own datadir (`../fnd-review-mysql/data`), port 3419 and socket, and stopped it afterwards. I did not touch the existing pid 3684 instance.
- `probe-mysql-repeat.txt` (source e2b3906, rc=0): three `migrate:fresh --force` runs in one PHP process all succeeded (rc=0, 152.1 s, 143.7 s and 213.8 s on the shared container). All 80 migrations were recorded each time. Declared classes stayed at 1274 after runs 1, 2 and 3: `RESULT no new classes on run 3`. Runs 2 and 3 executed the MySQL admission and ownership proofs (consent 250000, suppression 251000, production features 253000, production suppression 254000) against cached clones.
- `mysql-post-state.txt`: 3 triggers each on customer_accounts and the three consent tables, and the migrations 000040, 242000 and 250000–254000 recorded.
- Not run on MySQL: the PHPUnit feature files themselves, including the MySQL-only `*NativeAdmissionTest`, which skip on SQLite. MySQL 8.4 was not exercised; CI remains the evidence for 8.4.

## Q3: Focused SQLite tests at e2b3906

Command per file, from the export: `run.sh`, which wraps `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress <file>`. PHPUnit 12.5.34, PHP 8.4.26. Files run one at a time.

| File | Result | rc |
|---|---|---|
| tests/Feature/MigrationRecompilationTest.php | OK (1 test, 5 assertions) | 0 |
| CustomerConsentMigrationTest | OK (20, 162) | 0 |
| CustomerConsentAdmissionTest | OK (22, 48) | 0 |
| CustomerSuppressionMigrationTest | OK (29, 132) | 0 |
| CustomerSuppressionSourceBoundaryTest | OK (7, 90) | 0 |
| CustomerSuppressionNativeAdmissionTest | 1 test, 1 skipped (MySQL-only) | 0 |
| CustomerIdentityMigrationTest | OK (14, 141) | 0 |
| CustomerAccountMigrationTest | OK (7, 106) | 0 |
| CustomerListeningMigrationTest (saved_tracks) | OK (9, 37) | 0 |
| ProductionFeatures/ProductionFeatureMigrationTest | OK (30, 427) | 0 |
| ProductionFeatures/ProductionFeatureNativeAdmissionTest | 3 tests, 3 skipped (MySQL-only) | 0 |
| ProductionFeatures/ProductionFeatureHeldFloorTest (assertHeld) | OK (3, 18) | 0 |
| ProductionFeatures/ProductionFeatureJourneyTest (runtime assertHeld) | OK (2, 30) | 0 |
| ProductionSuppression/ProductionSuppressionMigrationTest | OK (27, 248) | 0 |

Raw output: `t1-migration-recompilation.txt`, `t-<name>.txt`, summary `focused-summary.txt`. tests/Feature/ProductionAccountFeatures contains only a default-configuration test, so the ProductionFeatureSchema coverage is in tests/Feature/ProductionFeatures.

**Mutation (export only, restored afterwards).**
- All four call sites reverted to `require` (`mutation-revert-call-sites.patch`, `mutation-applied-grep.txt`): the regression test fails with `Failed asserting that 2462 is identical to 2426` (36 new classes over two runs), rc=1 (`t-mutation-red.txt`).
- One site at a time (`mutation-per-site.txt`): each reverted site alone fails the test.
  - Site 1, ConsentMigrationAdmission: 2425 vs 2409, rc=1.
  - Site 2, SuppressionSchema: 2402 vs 2398, rc=1.
  - Site 3, ProductionFeatureSchema consent: 2410 vs 2402, rc=1.
  - Site 4, ProductionFeatureSchema saved_tracks: 2409 vs 2401, rc=1.
- After restoring, `cmp` confirms all three app files are byte-identical to e2b3906.
- Removing `clone` (handing out the shared prototype) would not be detected by any test. Given that the classes are stateless (Q1), the clone is defensive only.

## Q4: License-draft fixture

- **Prepare phase.** The new lines are exactly those of `prepare-bulk-license-draft-source.php:50-60`, with this file's unprefixed helper names:
  - `epoch($after) >= epoch($before)`;
  - the `DiscoveryEpoch::TABLE` row skipped in the existing-row equality loop;
  - `guards()` still compared exactly.
- **Verify phase.** Also an exact mirror of `prepare-offer-draft.php:102-107` and `prepare-bulk-license-draft-source.php:168-172`:
  - `expectedCount === 0 ? epoch === original : epoch >= original`;
  - then the epoch table removed from both sides before the full `$actual === $original` comparison and the guards check.
- **No-op exactness.** For phase `prepared` (expectedCount 0), the epoch must equal the value captured at the end of prepare. Every other table must be identical, with no added audit events (count === 0). The epoch row's shape is still asserted on both sides in every phase: exactly one row, key `1`, columns exactly `id, epoch, schema_version`, `id === 1`, `schema_version === 1`, and an integer epoch. Only the epoch value is relaxed, and only forward, and only for phases that legitimately write.
- **Justification.** `DiscoveryEpoch::DEPENDENCIES` includes `license_templates` and `license_versions`, so their writes advance the epoch. The implementer's evidence locates the only changed row as `catalog_discovery_epoch key=1 cols=epoch`.
- **Not reproduced by me.** The fixture needs the browser harness (VASEY_BROWSER_* environment, unpaid-release fixtures, ClamAV signatures that cannot be fetched here). Assessed statically. The implementer's relaxed-harness run covers prepare and verify `prepared`; phases `winner`, `recovered` and `uncertain` and the real spec still need CI.

## Not tested by this review

- Full SQLite shards: in progress elsewhere, not run here (see condition 1).
- MySQL 8.4, and the MySQL PHPUnit feature files including the native admission tests.
- Browser specs.
- PHP-FPM, queue worker or Octane runtime behaviour: reasoned from PHP semantics, not measured.
