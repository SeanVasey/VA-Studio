# Evidence: labelled synthetic staging rehearsal catalog (lane C1)

Recorded 2026-10-17 by Claude, under Sean's approval of the same day: "build the labelled synthetic staging catalog".
Finished on 2026-10-18, after a usage pause and a container reboot at about 00:36 UTC. Every result below was
complete before the reboot, and nothing was re-run for unchanged source.

## Scope

**Branch.** `harness/staging-synthetic-catalog-20261017`, based on `origin/main` `34562862`.

**Tested source.** `5f7fe502570c05c6da40b9cacdfa6b0b8e5cc82c`, the three code commits:

- `d1cf5ed0` feat (command, policy, builder, media generator, config, environment templates);
- `bf4e63aa` test;
- `5f7fe502` fix (reports an edited published synthetic track).

Its working tree was clean for `app/`, `config/` and `tests/` during the final runs below. The later documentation
commit changes no code. Results recorded at an earlier commit say so.

**What was built.** `vasey:staging-synthetic-catalog` seeds a labelled synthetic catalog for the hosted staging
rehearsal: six `[SYNTHETIC]` tracks and two TEST PRICE licenses. Its admission is default-off, it refuses in
production unconditionally, and every write goes through the existing domain services. The design and its reasoning
are in [design.md](design.md). Operator use is in `docs/ops/staging-runbook.md` section 5a.

**New flag.** `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED`, default `false`. It is not yet in the staging template
`ops/staging/env.staging.example`, which lane O1 owns; the lead adds it there after O1 merges.

**Not done here.**

- No push, PR, GitHub comment or workflow dispatch.
- No hosted staging run.
- No schema migration, route, job or edit to an existing domain service, guard or policy.
- No change to the CI census files or workflows.

## Environment

PHP 8.4.26 (CLI), PHPUnit 12.5.34, ffmpeg 6.1.1, ClamAV 1.5.4 binary with no signature database.

Every PHPUnit run used the lane's direct invocation from `/home/user/wt/c1`, with `public/build` absent:

```sh
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M \
  -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <files>
```

The MySQL runs replaced the database settings with
`DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33589 DB_DATABASE=vaseyaudio_c1_synthetic DB_USERNAME=root DB_PASSWORD= VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_c1_synthetic`.

## Results

### 1. New feature file on SQLite (`tests/Feature/StagingSyntheticCatalogTest.php`)

At `5f7fe502`, this file ran together with `tests/Unit/ProductionEnvironmentTemplateTest.php`. They passed **18 tests
and 2,240 assertions**, with 0 failures, 0 errors, 0 skips, 0 warnings and 0 risky tests, in 3 min 35 s (real time).
The split between the two files was not recorded separately. Run alone on MySQL at the same commit, this file makes
589 assertions (section 2). At `bf4e63aa`, before the edited-track case, this file alone passed 12 tests and 585
assertions in 3 min 43 s.

The suite uses real ffmpeg for both generation and derivatives, and the real media pipeline. Two pieces are
test-only: the malware engine is the existing `TestOnlyMediaScanner`, and the seller tag is the existing
`MediaFixtures` test tag. Each full catalog run takes about 25 s.

| Case | What it proves |
| --- | --- |
| `test_production_is_refused_unconditionally_even_with_the_flag_and_test_mode` | Production refuses both the run and `--check`, with the flag on and Stripe in test mode. The census of 15 tables (users, audits, catalog, media, licenses, offers and commerce) is unchanged. |
| `test_the_default_off_flag_refuses_before_any_write` | `false`, `null`, the string `"true"` and `1` are all refused with no writes. The shipped config defaults to `false`. |
| `test_environments_outside_local_testing_and_staging_are_refused` | `development`, `Staging`, `staging-eu`, `prod` and the empty string are refused with no writes. |
| `test_stripe_must_be_in_test_mode` | Refused: live mode, no mode, an `sk_live_` key, an `rk_test_` key, live production funds and a live production key. Admitted: an `sk_test_` key. |
| `test_operators_must_be_named_synthetic_distinct_and_satisfy_the_panel_mfa_rule` | Refused: missing options, unknown accounts, a non-`Synthetic` name, no admin authority, the same account twice. Under `staging`, an unenrolled operator is refused, then an unenrolled reviewer. Once both enroll through the panel's own provider, `--check` passes. Every refusal leaves the census unchanged. |
| `test_a_missing_or_mismatched_seller_tag_is_refused_and_never_generated` | Refused with no writes: no tag (`tag_not_configured`), the wrong hash (`tag_hash_mismatch`), a missing file. |
| `test_a_run_creates_the_labelled_published_catalog_through_the_domain_services` | Six published tracks with no readiness blockers, each carrying the labels and markers. Verified rights. Ready artwork, `download_mp3`, `master_wav` and `preview_tagged` with the defined durations and peaks. Twelve active offers at 111 or 222 integer USD cents, whose snapshots carry TEST PRICE and the label. Two licenses authored by the operator and approved by the reviewer. Empty quote, order, grant, payment and webhook tables. The exact audit census (94 events, all from the two synthetic accounts, approvals only from the reviewer), including manifest hashes on publication. No leftover processing workspace. |
| `test_a_rerun_reports_already_present_and_duplicates_nothing_and_a_staff_withdrawal_is_kept` | A second run reports `already present` for six tracks and two licenses and leaves the census unchanged. After the ordinary unpublish, a third run reports the track withdrawn, exits 1 and writes nothing. After a staff edit removes a published track's `[SYNTHETIC]` title, a fourth run reports `present, metadata differs from the definition; left unchanged`, exits 1, and neither writes nor reverts. |
| `test_an_interrupted_run_resumes_and_a_marked_row_that_differs_is_refused_unchanged` | A pre-existing matching draft is resumed and published. A marked track with an edited title is refused and left untouched (no rights, media or offers). |
| `test_a_failed_media_step_stops_the_run_and_a_rerun_retries_the_same_source` | A rejected scan stops the run with `scan_not_clean`, exit 1 and no offers. The re-run retries the same quarantined source and run (one `media.processing.failed` audit) instead of re-uploading, then publishes all six tracks. |
| `test_the_storefront_player_and_a_quote_use_the_labelled_synthetic_offers` | `/api/catalog` lists six labelled tracks and TEST PRICE offers. The track page, the `audio/mpeg` preview stream and the license disclosure (features and terms text) work. `CreateQuote` against a synthetic offer freezes its labelled snapshot at the TEST PRICE in USD. No order is created. |
| `test_generated_sources_are_real_media_and_never_reuse_a_brand_asset` | Six distinct WAV masters (`audio/x-wav`). Sanitized 600×600 generated artwork. All rights verified by the synthetic operator. |

An earlier run of the same file, before the failed-media resume case, the source-reuse change and the edited-track
report were added, passed 11 tests and 564 assertions. Before that, it failed at the test-harness level, which is recorded here and not as
product failures:

- `RefreshDatabase` wraps the standalone-transaction services (`License template authoring requires a standalone
  transaction`). The file now uses the repository's `FinalizationDatabaseMigrations`.
- One `PendingCommand` was destructed late.
- Two output expectations were placed on the same line.
- A license-disclosure assertion did not decode JSON.
- The production-environment test left the teardown `db:wipe` asking for confirmation.

### 2. Native MySQL 8.4.11 (same feature file)

This used a private Oracle MySQL 8.4.11 server with its own datadir, `/var/lib/va-mysql84-c1/data`, initialized
with `--initialize-insecure`. It listened only on `127.0.0.1:33589` (socket `/var/lib/va-mysql84-c1/run/mysqld.sock`)
with the disposable schema `vaseyaudio_c1_synthetic`. Durability was ordinary: `innodb_flush_log_at_trx_commit=1`,
`sync_binlog=1`, doublewrite `ON`, binary log on, and `REPEATABLE-READ` isolation.

At `5f7fe502`, with a clean working tree for code, the file passed: **12 tests and 589 assertions**, with 0 failures,
0 errors, 0 skips and 0 warnings. The run took 38 min 30 s. Most of that is the per-test `migrate:fresh` and
`db:wipe` DDL under full durability; about 2 min was CPU.

This runs the command end to end through the console kernel on the production engine. That covers every domain
writer, the real ffmpeg pipeline, the reviewed manifest publication, the storefront and the quote. Only the malware
engine is test-only, as in section 1. The behaviour is not native-sensitive, and no concurrency claim is made.

**Interrupted runs.**

- A run at `bf4e63aa` was stopped after 2 of its 12 tests (both passed), because the code then gained the
  edited-track report. It is not counted.
- A first attempt, on the working tree before any commit, was stopped with no completed test. It is not counted.

**Server lifecycle.** Started 2026-10-17 19:08:25Z, after a clean initialization. At 21:08:23Z it received
`SHUTDOWN … via user signal`, when the harness's two-hour background limit ended, and logged `Shutdown complete
(mysqld 8.4.11)` at 21:08:25Z. That was after this run and the manual run had finished. The later container reboot
found the server already stopped. The disposable schemas remain in the lane-private datadir.

### 3. Real process on MySQL with the genuine scanner (manual)

This run used `artisan` at `5f7fe502`, with `APP_ENV=local` and the flag on. The database was a separate fresh
disposable schema, `vaseyaudio_c1_manual`, on the private server. Private storage was in a scratch directory outside the checkout, and
`QUEUE_CONNECTION=database` with no worker. The seller tag was a 0.4 s 1800 Hz tone generated for this scratch run
only; it is not committed and is not a staging tag. The operators came from piped `vasey:create-admin` input.
Migration took 5 min 9 s; the PHPUnit MySQL run shared the server at the same time.

| Invocation | Exit | Result |
| --- | --- | --- |
| Flag `false` | 1 | `Refused: Set VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true … Nothing was written.` |
| `APP_ENV=production` | 1 | `Refused: … refused in production, whatever VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED says. Nothing was written.` |
| `STRIPE_MODE=live` | 1 | `Refused: … requires Stripe test mode … Nothing was written.` |
| `MEDIA_TAG_PATH=` | 1 | `Refused: The approved seller tag is not configured … (tag_not_configured) … This command never generates a tag. Nothing was written.` |
| Census after these four refusals | — | Unchanged: 0 tracks, licenses, rights, assets, runs, offers and jobs. 2 audits, both from operator creation. |
| `--check` | 0 | Admission passed for operator #1 and reviewer #2. Prints the plan; nothing written. |
| Run 1 | 1 | Both licenses created. Then `Stopped: synthetic-rehearsal-01 media processing failed: scanner_signatures_stale.` |
| Run 2 | 1 | Licenses `already present`. The same track, verified rights and quarantined source are retried, then stop with the same code. |

After run 1 the database held:

- 1 track, 2 published licenses and 1 verified rights declaration;
- 1 media asset and 1 run, which failed with `scanner_signatures_stale`;
- 0 offers, 1 queued job and 18 audits.

After run 2 the asset and run counts were unchanged, with 2 queued jobs and 20 audits (the re-queue and the second
failure).

The same sequence was first run at `bf4e63aa`, without the no-tag case, and gave identical results.

This shows that the genuine ClamAV engine refuses as designed when it has no signature database, and that the
command stops cleanly and resumes without duplicating anything. It is **not** a successful genuine-scanner run: this
container has no ClamAV signatures, and downloading them would have changed shared system state.

### 4. Regression: existing suites the command exercises (SQLite)

These ran on the lane worktree, unchanged from `34562862` except for this lane's additions. None of these suites
loads the new classes; the only shared change is the new config file. All passed with 0 failures and 0 errors:

| File | Tests | Assertions |
| --- | --- | --- |
| `tests/Feature/TrackPublicationApplyTest.php` | 18 | 150 |
| `tests/Feature/TrackPublicationGuardTest.php` | 28 | 392 |
| `tests/Feature/TrackPublicationManifestTest.php` | 21 | 292 |
| `tests/Feature/LicensingAdminTest.php` | 6 | 97 |
| `tests/Feature/LicenseTemplateAuthoringTest.php` | 39 | 104 |
| `tests/Feature/MediaProcessingTest.php` | 26 | 221 |
| `tests/Feature/MediaUploadTest.php` | 7 | 33 |
| `tests/Feature/RightsDeclarationWriterTest.php` | 33 | 472 |
| `tests/Feature/OfferWriterAuthorityTest.php` | 9 | 34 |
| `tests/Feature/QuoteTest.php` | 15 | 138 |
| `tests/Feature/CatalogPaginationTest.php` | 7 | 107 |
| `tests/Feature/StagingTestCommerceProfileJourneyTest.php` | 9 | 249 |
| **Total** | **218** | **2,289** |

At `bf4e63aa`, `tests/Unit/ProductionEnvironmentTemplateTest.php`, `tests/Feature/OperatorSetupTest.php` and
`tests/Feature/InstallationReportTest.php` passed 44 tests and 1,845 assertions. The template test passed again at
`5f7fe502`, as section 1 records.

As a control, `ops/production/env.production.example` was reverted on its own and the template test re-run. It fails
`test_every_config_env_key_is_a_literal_and_covered_by_the_two_ops_templates` with
`env() keys missing from both ops templates: VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED`. That two-line template entry
is therefore required by an existing invariant. It is in `ops/production/`, not the O1-owned `ops/staging/`.

### 5. Static and CI-selection checks

- `php vendor/bin/pint --test` on every new PHP file: passed.
- `python3 scripts/ci/phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --mysql-native-selection`: exit 0. The
  partition proof holds, with 233 selected files, 2,432 selected cases and selection policy sha256 `995aa895…`. The
  new file is not selected. **Native-selection delta: none.** The generated files were deleted afterwards.
- No driver-branching test was added, and no census JSON or workflow was edited.

## Untested conditions

- No hosted staging run. The Forge host, its staging `.env`, workers, real ClamAV and real Stripe test account are
  untested with this command.
- No successful genuine-scanner run, because this container has no ClamAV signatures (section 3).
- The staging media worker racing the command for a claim is reasoned from `MediaProcessor`'s claim token. It has
  not been exercised with a live worker.
- The command's output under the staging `art` alias (`sudo -u forge`) has not been observed.
- Order preparation, checkout, payment, contract and download against synthetic offers were not run here. Those
  chains are covered by their existing suites on the existing quote fixtures. Synthetic offers still need rights
  scopes linked with `vasey:rights-scope` first.
- No concurrency claim is made for the cache lock against two simultaneous runs on MySQL.
- The S-6 seller tag is absent on staging today, so the command refuses there until a tag is configured.
