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

**Review delta.** The independent review of `09acd13d` (a merge of `main`) returned APPROVE WITH CONDITIONS, with no
P0 or P1. The conditions are closed by `c23718c4` (fix) and `a8f162c8` (test). Sections 1–5 below are the original
evidence at `5f7fe502`. The [review conditions closed](#review-conditions-closed) section records the delta
evidence at `a8f162c8`.

**What was built.** `vasey:staging-synthetic-catalog` seeds a labelled synthetic catalog for the hosted staging
rehearsal: six `[SYNTHETIC]` tracks and two TEST PRICE licenses. Its admission is default-off. It refuses when the
configured or running environment is production or the two differ, and on a catalog that already holds non-synthetic
published tracks or approved licenses (configuration and catalog checks, not host identity; see the review conditions
below). Every write goes through the existing domain services. The design and its reasoning
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

## Review conditions closed

All delta results are at `a8f162c8a03f087c0b6d56b3da5e0eb018c7d458`, with a clean working tree for code.

| Finding | Before (at `09acd13d`) | Now |
| --- | --- | --- |
| **P2-1** `--env` bypass | The production check read only `app()->environment()`, so `APP_ENV=production … --env=staging` passed it. | The command refuses if `config('app.env')` or `app()->environment()` is `production`, and refuses if the two differ. Two bounded reads against the exact synthetic slugs also refuse a catalog with a published or once-published real track, or an approved real license. |
| **P2-2** console staff identity | It acted as both accounts by email alone. | It requires an interactive console and both passwords at hidden prompts, with no visible fallback (the `vasey:rights-scope` pattern). On staging it requires `--attest`, which records one `staff.console_attestation` per account (command, role, both IDs, enrollment) before the first write. `--check` stays non-interactive. |
| **P3-1** blocked state | Not stated. | Runbook §5a opens with "Blocked until the S-6 seller tag is installed, or until Sean decides about a synthetic tag". Reset step 4 reinstalls S-6 and removes any synthetic tag before real masters. |
| **P3-2** staging write chain | Untested. | A test runs the whole chain under `staging` with enrolled accounts, `--attest`, both passwords and the repository's ClamAV stand-in scanner pattern. All six tracks publish with no readiness blockers. |
| **P3-3** unexpected errors | Uncaught. | A final `Throwable` catch prints "Stopped: the result is unconfirmed. Re-run the command to resume …". It logs only `exception_class`, following the Filament writer pattern. |
| **P3-4** environment resets | Reset inline. | Resets run in `finally`, through an `inEnvironment()` helper. A lock-contention refusal test was added. |
| **P3-5** marker wording, flag refresh, offer prices | Overstated, missing, unchecked. | The CHANGELOG now says offers, revisions, rights and media inherit the marker. The runbook adds a Deploy Now configuration refresh after the flag returns to `false`. A re-run compares a published track's active offers with the definition and reports `present, offers differ from the definition; left unchanged`. |

The overstated claims were corrected to the precise rule in six places:

- the command docblock;
- the config comment;
- `design.md` (purpose and admission);
- this packet's scope;
- runbook §5a admission;
- the CHANGELOG.

`design.md` and the runbook no longer say "like every staff-attributed console write". They also state that one
shell drives both accounts.

**Delta results**

- **Feature file, SQLite:** **19 tests and 806 assertions**, with 0 failures, 0 errors, 0 skips and 0 warnings, in
  2 min 55 s. Old: 12 tests and 589 or 585 assertions. The production test was renamed from
  `…refused_unconditionally…` to `test_production_is_refused_even_with_the_flag_and_test_mode`. The seven new cases:

  | Case | What it proves |
  | --- | --- |
  | `test_a_production_configuration_is_refused_when_the_running_environment_says_staging` | Detected `staging` with configured `production`: refused for the run and `--check`, no writes. |
  | `test_a_running_environment_that_differs_from_the_configuration_is_refused` | Detected/configured staging/testing, testing/staging and local/staging: each refused, no writes. |
  | `test_writes_need_an_interactive_console_both_passwords_and_the_lock` | Refused: `--no-interaction`, `--attest` with `--check`, a wrong operator password, a wrong reviewer password, the reviewer's password at the operator prompt, and a held lock. No writes and no attestation. |
  | `test_the_password_prompt_never_falls_back_to_visible_input_and_failures_stay_generic` | The prompt is hidden with no fallback. A failure to hide prints only the generic unconfirmed line (no message or path) and logs `exception_class` once. No writes. |
  | `test_staging_requires_an_attestation_and_then_runs_the_whole_chain_with_enrolled_accounts` | Covers P3-2, as in the table above. Without `--attest`, refused with no writes. With it, the two attestation rows are the first audits of the run, carry the expected context, and the license approvals belong to the reviewer. |
  | `test_a_catalog_with_a_published_or_once_published_real_track_is_refused` | A never-published real draft is admitted. Once its URL is reserved, the run and `--check` are refused. |
  | `test_a_catalog_with_an_approved_real_license_is_refused` | A real template without versions is admitted; an approved real license is refused. |

  The re-run case now also deactivates one offer through `DeactivateOffer` and gets the offers-differ report, with
  no writes.
- **Related suites, SQLite:** `tests/Unit/ProductionEnvironmentTemplateTest.php`,
  `tests/Feature/RightsScopeCommandAttestationTest.php` and `tests/Feature/RightsScopeCommandTest.php` passed **23
  tests and 2,071 assertions**.
- **Real process:** `artisan` on code identical to `a8f162c8` for `app/` and `config/`, in the scratch environment of
  section 3, with `--check`:

  | Invocation | Exit | Result |
  | --- | --- | --- |
  | `APP_ENV=production … --env=staging` | 1 | Refused in production. |
  | The same, with `config:cache` built under `APP_ENV=production` | 1 | Refused in production. The cache file was then deleted. |
  | `APP_ENV=local … --env=staging` | 1 | Refused: "configured environment (APP_ENV) and the running environment differ". |

  These refusals precede any database access.
- **Pint:** passed on every changed PHP file.
- **Native MySQL:** not re-run. The new guard uses `where`/`orWhereNotNull`/`whereNotIn`/`whereHas(...)->exists()`
  with no pattern matching, locking or engine-specific SQL. The remaining changes are console I/O and an existing
  attestation writer that `RightsScopeCommandAttestationTest` already covers. The MySQL evidence in section 2 is at
  `5f7fe502`.

**Still open after review.** P3-2 is covered by the test above, but not on the hosted staging server. The
decision-register entry for the S-6 tag is left to the lead.

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

## Independent review

The first independent review at `09acd13d` returned APPROVE WITH CONDITIONS ([record](independent-review-09acd13d-DECISION.md)). The re-review of the condition fixes at `cfc4d6a4` returned APPROVE with both conditions closed and no P0, P1 or P2 open ([record](independent-rereview-cfc4d6a4-DECISION.md)). Both records are imported verbatim. Its P3-A to P3-D are deferred to this lane's next batch and recorded in `docs/handoff/2026-10-07/codex-deferred-20261011.md`. Its P3-B qualifies this packet's "interactive console" wording: the command refuses `-n`, but hidden prompts also read piped standard input, and both passwords are still required.
