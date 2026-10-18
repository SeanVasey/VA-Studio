# Production operations kit: evidence packet (lane O1, 2026-10-17)

Branch `harness/production-ops-kit-20261017`, based on `origin/main` `34562862`. Nothing was pushed, no PR was opened and no
workflow was dispatched. No application code, route, config, census file or workflow was changed. Nothing here was run on a
real host: no Forge, no DigitalOcean, no systemd, no real deploy, backup, restore or off-host transfer.

## What was built

1. **Production kit** (`ops/production/`), derived from `ops/staging/` rather than copied: a builder with counted patches, a
   production runtime validator that reuses the reviewed private-server preflight production profile, a production nginx
   template, an environment composer, a provisioning entry point and a README. See `ops/production/README.md`.
2. **ClamAV profile** (`ops/clamav/`), opt-in, for staging and production: `clamd.conf` at the documented limits, the `TZ=UTC` and
   socket-mode drop-ins, an installer and a verifier that runs acceptance steps 1 to 4.
3. **Backup hardening**, opt-in and default-off, added to `ops/staging/backup.sh` (so the production copy has it): age encryption
   to public recipients, an rclone remote, tiered retention, `check-offhost`. Notes on binary-log shipping are documentation only.
4. **Monitoring and baseline** (`ops/monitoring/`): queue age with an optional heartbeat, scheduler liveness, signature age, and a
   firewall, unattended-upgrades and SSH baseline check with opt-in apply actions; plus the CDN and proxy note.
5. **Owner checklist** `docs/ops/staging-provisioning-checklist.md` (S-2 to S-8 and S-10).

## Files

Added: `ops/production/{README.md,build-kit.py,compose-env.py,provision.sh,validate-runtime.php,env.host-overlay.example,nginx/vasey-production.conf}`;
`ops/clamav/{README.md,clamd.conf,install-clamd.sh,verify-clamd.sh,systemd/clamav-daemon.service.d/timezone.conf,systemd/clamav-daemon.socket.d/vasey-socket.conf}`;
`ops/monitoring/{queue-age-check.sh,scheduler-liveness-check.sh,signature-age-check.sh,host-baseline.sh}`;
`docs/ops/{backup-offhost-hardening.md,monitoring-and-host-baseline.md,staging-provisioning-checklist.md}`;
`tests/ops/{test_production_kit.py,test_production_runtime_validator.py,test_clamav_profile.py,test_monitoring_scripts.py,test_staging_backup_offhost.py}`;
this packet and its `semantic-delta.diff` and `evidence/` transcripts.

Changed: `ops/staging/backup.sh` (opt-in additions only: a `backup.conf` loader that does nothing when the file is absent, new
default-empty variables, `ship_hardened` and helpers placed after `restore_check` and before `case`, a branch at the top of
`ship()` taken only when hardening is configured, `prune_local` taking a branch only for `VASEY_BACKUP_RETENTION=tiered`,
`prune_remote` and `check-offhost`), `ops/staging/README.md` (a pointer paragraph), `CHANGELOG.md`.
Every other file under `ops/staging/` was untouched by this batch (batches K1 and K2 below changed some of them). The slices the existing tests cut out of
`backup.sh` (`mycnf_ok()` to `RC_WORK=""`, and `restore_check()` to its first `install -d`) are unchanged.

## How the production kit stays honest to staging

`build-kit.py` writes each derived file as the staging file plus mechanical renames plus a short list of patches, each with the
number of times its anchor must match. `semantic-delta.diff` (223 lines, a snapshot at this commit; `python3 -I ops/production/build-kit.py --delta`
regenerates it) is the staging file after renames against the production file. Its content: the basic-auth and probe-credential
removal and the production environment name in `provision.sh`; the environment checks in `forge-deploy.sh` (no forced
`MAIL_MAILER`/`STRIPE_MODE`, no live-key or checkout-flag refusal, since the validator holds those once); two hunks in the
control helper (no probe credentials); the worker log lines. `release-step.sh`, `backup.sh` and the default PHP-FPM pool have no
delta at all. `seal-release.py`, `normalize-mysql-dump.py` and the paid-delivery pool are byte-identical copies.
Because the root helpers differ from the reviewed staging code only by that delta, the behaviours already covered by
`tests/ops/test_staging_*.py` apply by construction; they were not re-run against the derived files.

## Verification: commands and results

| Check | Command | Result |
| --- | --- | --- |
| Syntax | `bash -n` on every shell script; `php -l ops/production/validate-runtime.php`; Python compile of the builder, composer and tests | all pass |
| Lint | `shellcheck` 0.9.0 (`-S warning`) on the five derived scripts and the new scripts; default severity on `ops/staging/backup.sh` | clean (the new scripts carry the same `SC2015` directive the kit uses, or none) |
| Kit build | `python3 -I ops/production/build-kit.py --check` | ok, 13 files; deterministic across builds |
| Anchors | `AnchorsFailClosed` tests: every anchor mutated to 0 and to n+1 matches | each refuses the build and leaves no output |
| nginx | `nginx -t` on the rendered template; a live run for headers and log masking (`evidence/nginx-live-run.txt`) | `syntax is ok` and `test is successful` (the container has no IPv6, so only the `listen [::]` lines were removed for the second form) |
| PHP-FPM | `php-fpm8.4 -t -y` on both rendered pools | test is successful |
| supervisor | supervisor's own `ServerOptions` parser on the rendered worker file | one group, five programs, `stdout_logfile` none, `stdout_syslog` true, no warnings |
| ClamAV | `clamconf -c <dir> -n` on `clamd.conf`; a real `clamd` 1.5.4 with those limits; `verify-clamd.sh` (`evidence/clamd-real-run.txt`) | parses; limits became 1342177280 bytes; steps 1 to 4 pass; a user outside the group is refused; a daemon in `Asia/Tokyo` is caught |
| AppArmor | `apparmor_parser -Q -K` on the packaged profile plus the generated block, and with a bad rule | accepted; the bad rule is refused |
| Encryption | age key pair generated in a temporary directory and discarded; encrypt then decrypt through rsync and through rclone's local backend | round trip byte-identical; ciphertext holds no plaintext marker |
| Queue SQL | `queue-age-check.sh` against MySQL 8.4.11 with the real `jobs` and `failed_jobs` tables (`evidence/queue-check-real-mysql.txt`) | correct counts and ages; the `READ ONLY` session blocks a write even for root; a missing grant gives UNKNOWN |
| Validator | `tests/ops/test_production_runtime_validator.py` (real Laravel configuration, no database) | 18 tests pass |
| Env composition | compose, fill the four blanks with synthetic values, then the preflight and the validator | blocked until filled, then `FILE_CHECKS_PASSED` and `PASS runtime.profile (production ...)` |
| New ops tests | `python3 -m unittest tests/ops/test_production_kit.py` etc. | production kit 49, validator 18, ClamAV 26, monitoring 42, backup off-host 40: 175 tests, all pass |
| Existing tests | `tests/ops/test_staging_*.py` (13 files, 74 tests); `scripts/ops/test-private-server-preflight.py` (20); PHPUnit `StagingRuntimeValidatorTest` (17) and `ProductionEnvironmentTemplateTest` (6) | all pass; the backup, dump-normalization, deploy-refresh, preflight and staging-validator tests were run before the change as well |
| Test sensitivity | mutations: the preflight call removed, a live-key hold removed, `READ ONLY` removed, the served-release check removed, the anchor count check removed, the private-key refusal removed, plaintext shipped instead of ciphertext, the protected set ignored, the proof requirement removed | each mutation made at least one test fail; every file was restored and compared |

One transient error appeared once in one run of `tests/ops/test_staging_operation_lease.py` during a full sweep; the file and
`ctl` are untouched by this lane, and the same file then passed 11 further runs (three, six under load, and two more in full
sweeps). The cause was not found; it is recorded rather than explained away.

## What the work found that was not in the brief

- **`clamdscan` is a separate Ubuntu package** that `clamav-daemon` only recommends. The kit installs with
  `--no-install-recommends`, so without an explicit install every daemon-route scan fails with a missing binary.
- **The packaged clamd socket unit leaves systemd's default mode `0666`**, so `LocalSocketMode 660` in `clamd.conf` does not apply
  under socket activation. The drop-in sets `0660` and the installer refuses to continue unless the socket reads `660 clamav:clamav`.
- **Ubuntu's AppArmor profile for clamd** allows `/tmp`, `/var/lib/clamav` and spool directories but not `/var/tmp/clamd` nor the
  private storage tree. The installer adds a marked block to the local override. Whether AppArmor re-checks a descriptor passed
  with `--fdpass` was not testable here.
- **freshclam warns on every update** that clamd was not notified because `RELOAD` is disabled; clamd reloads itself within
  `SelfCheck` (10 minutes in this profile). Enabling `RELOAD` was rejected: any group member could trigger a 2 GiB reload peak.
- **supervisor `stdout_syslog`** prefixes each line with the program name (read in `supervisor/dispatchers.py`, 4.2.5), which is what
  the scheduler check greps. The journal's tagging itself was not observed.
- **nginx's error log quotes the request line**, UUID included, when an upstream fails; only the access log is masked.
- **A blank `PRODUCTION_CHECKOUT_*` assignment is not the same as an unset key**: the application's default does not apply to it, and
  the staging validator and this one refuse it. The template's own comment says so.
- **The preflight cannot run in the Forge mirror checkout** (no `vendor/`); the production README says to run it from a built checkout.
- **The Cloudflare figures in the brief were checked**: the proxy read timeout is 125 s, raisable only on Enterprise, the write
  timeout 30 s and the idle timeout 900 s (Cloudflare's error 524 and connection-limits pages, read on 2026-10-17). The
  per-plan request-body caps are from a community staff reply only and are marked unverified in the guide.

## Names the brief left in tension, and what was done

- **"Reuse staging by reference" against "production differs".** Reuse is real (derived at build time, byte-identical copies) but
  the root helpers could not be used unmodified: the control helper and `release-step.sh` hard-code `ops/staging/validate-runtime.php`,
  which refuses `APP_ENV=production` by design, and the helper requires probe credentials. Hence the derivation with counted patches
  rather than edits to the reviewed staging scripts.
- **"`STRIPE_MODE` and `MAIL_MAILER` are not forced" against "reuse the preflight production profile".** The preflight production
  profile still requires `MAIL_MAILER=log` and `STRIPE_MODE=test`. The kit forces neither itself, but the validator inherits them, so
  production remains refused for anything else until the reviewed preflight changes. Stated in the README and tested.
- **"Do not alter existing staging behavior" against editing `ops/staging/backup.sh`.** The brief asked for the backup options in the
  existing script; the default path is unchanged and tested to be so.
- **Production holds.** The brief did not list holds; the validator keeps the staging validator's refusal of live-looking Stripe
  credentials and of any `PRODUCTION_CHECKOUT_*` switch, because production commerce is a code and review gate. Lifting them is a
  reviewed change to `validate-runtime.php`.
- **Tests location.** The validator is tested from `tests/ops/` (Python driving the real PHP), not PHPUnit, so the PHPUnit census and
  timing files, which this lane must not edit, are not affected.

## Not verifiable without a host

Provisioning end to end (packages, MySQL accounts and grants, AppArmor for mysqld, sudoers, cron, logrotate), Forge and its nginx
reload, certificate issuance, systemd units and the socket drop-ins, supervisor starting the workers on a release, journald's
handling of supervisor and nginx output, the readiness probe against a real nginx with a trusted certificate, a first deploy,
a backup and restore proof on the host, an off-host transfer to a real rsync target or any rclone backend, encryption time inside
the maintenance window, the age restore drill with the operator's real key, clamd under systemd and AppArmor, `vasey:doctor`'s
scanner checks against the daemon, cron timing of the monitoring scripts, and Forge's ufw and unattended-upgrades defaults.

## Remaining reviewed application changes (not done here, by design)

1. **`trustProxies`** is not configured. Any CDN or proxy needs this reviewed change first: a reviewed address list, the forwarded
   headers to honour, and tests that a client outside the list cannot choose its own address. Documented in
   `docs/ops/monitoring-and-host-baseline.md`; not implemented.
2. **Managed or remote MySQL `DB_HOST` relaxation.** The kit, the validator and the schema's trigger definers require
   `DB_HOST=127.0.0.1` and `vasey_app@127.0.0.1`.
3. **Production identity SMTP.** The preflight production profile requires `MAIL_MAILER=log`; a real transport for production
   customer identity is a reviewed change.
4. Also open, outside this lane: relaxing the preflight's `STRIPE_MODE` and `MAIL_MAILER` requirements, a scheduler heartbeat in the
   application, and every activation in `docs/ops/production-activation-packet.md`.

---

# Batch K1: re-baseline of `ops/staging/` on `34562862`

The staging kit was last aligned with the application at `d8aba146` (PR #68, 2026-10-09). `main` has since gained PRs #69 to #137
(604 commits in the window, 29 of them PR merge commits). This batch audits the kit against the code as it is now, fixes the drift it found with the
smallest behavior-preserving changes, and proves that the schema migrates under the kit's own grants. Commit: see the lane report.
Nothing was pushed and no application file (`app/`, `routes/`, `bootstrap/`, `config/`, census files, workflows) was changed.

## Method

1. `git diff d8aba146 34562862` over `composer.json`/`composer.lock`/`package.json`, `config/`, `routes/`, `bootstrap/`, `database/migrations`,
   `app/Console`, `docs/ops` and `scripts/ops`, then a read of every new or changed runtime surface that touches a host (queues,
   storage, processes, upload and delivery routes, environment keys).
2. Each kit file against the current code, by evidence rather than by reading alone: a script over every `env()` call in `config/`,
   `composer check-platform-reqs`, a count of `onQueue()` sites, and the migrations run for real (below).
3. Fixes only where the kit would misbehave or its documentation states something now false. No refactor, no rename.

## Findings and what was done

| # | Kit file | Finding (evidence) | Action |
| --- | --- | --- | --- |
| 1 | `ops/staging/forge-deploy.sh` (inherited-environment scrub, lines 60 to 68) | The scrub unsets inherited variables by family before composer hooks and the cache and migration commands, so a value in Forge's deployment shell cannot override the candidate `.env` (Laravel's dotenv never overrides a variable already in the process environment). **43 of the 196 distinct `env()` keys in `config/*.php` were outside every pattern**: `LOG_*`, `AUTH_*`, `DISCOVERY_SITEMAP_ENABLED`, `MYSQL_ATTR_SSL_CA`, `MEMCACHED_*`, `SQS_*`, `DYNAMODB_*`, `BEANSTALKD_*`, `PAPERTRAIL_*`, `SLACK_*`, `POSTMARK_*`, `RESEND_*`, `VASEY_PHP_CLI_BINARY` (`config/app.php:139`, read by `app/Support/PhpCliBinary.php`), `VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED` (`config/support-attachments.php`) and `VASEY_LISTENING_V2_*` (`config/customer-listening.php`). The last three are flags and a renderer path: an inherited value would be baked into `config:cache`. The full key-to-file list is the first block of `evidence/k1-env-key-coverage.txt` | A second `case` arm lists those families. The arm only unsets more inherited variables; the candidate `.env` is unchanged, so a correctly configured host behaves identically. Guarded by `tests/ops/test_staging_code_drift.py` |
| 2 | `ops/staging/provision.sh` (PHP check, after line 223) | `composer.json:12` now requires `php ^8.4.1` (it was `^8.4` at `d8aba146`; `composer.json:93` platform `8.4.1`, `composer.lock` platform `^8.4.1`). The kit accepted any 8.4, and an 8.4.0 host would fail `composer install` in the middle of a deploy | A `version_compare` check with a message that names the Forge action. Guarded by the drift test, which reads the floor from `composer.json` |
| 3 | `ops/staging/provision.sh` (comment before `log_bin_trust_function_creators`), `ops/staging/README.md` (MySQL row, "Why one database account") | The kit said the migrations create about 567 triggers. Measured on MySQL 8.4.11 at `34562862`: **618 triggers, 200 tables, 0 routines, 0 events, 0 views, every definer `vasey_app@127.0.0.1`** (`evidence/k1-mysql-8.4.11-migrate-fresh.txt`). The restore proof's own check reports the same 618 | Figures corrected. No logic uses the number |
| 4 | `ops/staging/README.md` (paid-routes paragraph), `ops/production/README.md`, comment in both nginx templates | The documents said the paid routes are "not mounted on `main` (PR #56)". Now `config/paid-grants.php` ships `http_mounted` as a literal `false` with no environment switch (`docs/ops/paid-delivery-runtime.md`, "Root registration"); the locations answer 404 for that reason. The 3,900 s `fastcgi_read_timeout` comment called the figure "whole request plus margin"; the runtime guide now treats it as the application's reference value, not a measured guarantee | Wording corrected in the READMEs and the two nginx comments. Behavior (timeouts, locations, pool) unchanged |
| 5 | `ops/staging/nginx/vasey-staging.conf`, `ops/staging/workers/vasey-staging-workers.conf` | **Not fixed, recorded.** The Tax V2 consumer routes (`routes/paid-tax-grants.php`: `/paid-tax-grants/origins/{batch}/document` and `.../authorizations/{authorization}/redeem`) have the same long-running shape as the paid routes but their own paths, so nginx would send them to the 300 s default location and pool. They mount only when `paid-tax-grants.http_mounted` is a literal `true` in `local` or `testing` (`App\Http\Tax\TaxHttpMount`; `docs/ops/paid-tax-http-runtime.md`), so nothing on staging or production reaches them, and no reviewed runtime budget exists for them | A "Not covered here" note in `ops/staging/README.md` names it as a pre-mount condition. Adding locations and pool routing without a reviewed budget would invent one |

## Audited and found current (no change)

- **Queues** (`tests/ops/test_staging_code_drift.py` reads the code). Every `->onQueue('...')` site and `config/media.php` `queue`: `media`,
  `payments`, `contracts`, `inquiry-alerts`, plus the connection default. The workers listen on exactly those (`--queue=media`, `payments`,
  `contracts`, `default,inquiry-alerts`). No new queue and no job class with a queue the workers lack since `d8aba146`; job `$timeout`
  values (900, 90, 30) stay under the enforced `DB_QUEUE_RETRY_AFTER` floor of 1200 (`forge-deploy.sh:128`; `config/queue.php:43`).
- **MySQL privileges.** `migrate:fresh` under the exact `vasey_app` grants (below) runs all 86 migrations; the 1419 negative control
  shows `log_bin_trust_function_creators` is still required and still sufficient. No migration uses `SUPER`, `DEFINER`, `GRANT`,
  `SET GLOBAL`, `LOCK TABLES`, `GET_LOCK`, routines, events or views (greps over `database/migrations`). `SHOW CREATE TABLE`, `information_schema`
  and temporary tables are used and covered by the grants.
- **PHP extensions.** `composer check-platform-reqs --no-dev` passes with only the packages the kit installs. Every non-core `ext-*`
  that `composer.lock` requires (`ctype curl dom fileinfo gd iconv intl mbstring openssl tokenizer xml xmlwriter zip`, plus `pdo_mysql`
  and `pdo_sqlite` from the doctor) is in both provision lists. The ones not in the list (`filter hash json libxml pcre phar session
  xmlreader zlib`) are compiled into Ubuntu's `php8.4-common`/`-cli` and present (`php -m`). No new extension was needed since
  PR #68 (the application checks `gd`, `intl`, `mbstring`, `pdo_sqlite`, `pdo_mysql` with `extension_loaded()`).
- **Renderer binary.** `VASEY_PHP_CLI_BINARY=/usr/bin/php8.4` is in `ops/staging/env.staging.example:24`. Under FPM `PhpCliBinary` requires it
  and proves it (`cli` SAPI, same `PHP_VERSION`, same thread safety). The rendering children read it from `config('app.php_cli_binary')`
  and the Laravel renderers pass their own whitelist of variables, so no other variable is needed.
- **External binaries.** No line added to `app/`, `scripts/` or `config/` since PR #68 names a new executable (grep for the media, PDF and scanner
  tools and for `/usr/bin` and `/usr/local/bin` paths; only `/usr/bin/env` matched). `ffmpeg`, `ffprobe`, `prlimit`, `clamscan`,
  `qpdf` and `pdftotext` remain the set the provisioning step checks. `package.json` `engines.node` is still `>=24.15.0 <25`.
- **Private storage.** The private disk is `storage/app/private` (`config/filesystems.php`, `local`); the application creates new
  subdirectories (for example `customer-identity-capture/`, `app/Domain/Customers/CustomerIdentityCapture.php:29`) itself with private
  visibility, so the provisioning step needs no new directory. The doctor run below raised no storage finding, but its sandbox directory
  was not provisioned the way the kit does it, so that run is not evidence for the kit's 0700 mode.
- **Upload and body limits.** The chunk size is still 8 MiB (`SoundKitUploads::CHUNK_BYTES`), the contract and evidence caps 16 MiB,
  matching the nginx template's 16 MiB default and 210 MiB Livewire location.
- **Routes and scheduler.** The scheduler still runs one command (`routes/console.php`); the new HTTP surfaces (`/customer/features*`,
  `/paid-tax-grants/*`, support attachments) are either default-off or gated and need no host-level routing beyond the default location.
- **The backup path on the new schema.** Real `backup.sh predeploy` with the 8.4.11 tools produced `RESTORE_VERIFIED` and
  `database_redump=identic` at 200 tables and 618 triggers (`evidence/k1-mysql-8.4.11-migrate-fresh.txt`, item 4).

## Verification

| Check | Command | Result |
| --- | --- | --- |
| Migrations under the kit's grants | the exact statements of `provision.sh`, then `php artisan migrate:fresh --force` as `vasey_app` on a private MySQL **8.4.11**, binary log on | trust variable off: fails at `2026_09_04_000002_add_admin_mfa_and_immutable_evidence.php:21` with error 1419. On: 86 migrations, 0 failures, 200 tables, 618 triggers (5 min 35 s) |
| Backup and restore proof | `backup.sh predeploy` on that schema | `RESTORE_VERIFIED`, `database_redump=identic`, 200 tables, 618 triggers |
| Doctor | `php artisan vasey:doctor --json` as `vasey_app`, staging profile | 15 pass, 2 fail (`operator`, `frontend_build`: expected on a fresh install), 2 warn (`seller_tag`, `mail_transport`) |
| Activation caches | `config:cache`, `route:cache`, `view:cache`, `event:cache` | each exit 0 |
| Shutdown | `mysqladmin shutdown`; the server's log | `Shutdown complete (mysqld 8.4.11)`; no listener or process left |
| Shell | `bash -n` and `shellcheck -S warning` on `ops/staging/*.sh`, `bin/vasey-staging-ctl`, `ops/production/*.sh`, `ops/monitoring/*.sh`, `ops/clamav/*.sh` | clean. At the default severity `provision.sh` carries SC1091 (info) on `. /etc/os-release`, which this batch did not introduce |
| Kit build | `python3 -I ops/production/build-kit.py --check` | ok, 13 files. `semantic-delta.diff` regenerated; only hunk offsets changed (the staging files grew), not one content line |
| Drift tests | `python3 -m unittest tests.ops.test_staging_code_drift` | 9 tests pass; mutations (a scrub family removed, the PHP floor lowered, a queue renamed in the workers file) each fail it; files restored and compared |
| All ops tests | `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | 258 tests, all pass (the earlier 249 plus the 9 new) |
| Other tests | `scripts/ops/test-private-server-preflight.py` (20); PHPUnit `StagingRuntimeValidatorTest` (17), `ProductionEnvironmentTemplateTest` (6) | all pass |

## Changed in K1

`ops/staging/forge-deploy.sh` (one comment and one `case` arm), `ops/staging/provision.sh` (the PHP floor check; one comment),
`ops/staging/README.md` and the two nginx templates and `ops/production/README.md` (wording and figures), `tests/ops/test_staging_code_drift.py`
(new), `docs/verification/production-ops-kit-20261017/semantic-delta.diff` (regenerated), `evidence/k1-mysql-8.4.11-migrate-fresh.txt` and
`evidence/k1-env-key-coverage.txt` (new), `CHANGELOG.md`.

## Open after K1

- Tax V2 nginx locations and pool routing, once the Tax V2 routes have a reviewed runtime budget (finding 5).
- The template-coverage side of the same drift problem (every `config/*.php` key present in the staging or test-commerce template, with a
  framework-default allowlist) is batch K2, done there.
- Nothing in K1 was run on a real host; the limits under "Not verifiable without a host" still apply.
- Interruption record: the usage limit paused the lane and the container rebooted (reported around 00:36 UTC on 2026-10-18) after the K1
  MySQL proof had finished and its server had shut down cleanly (log time 19:47 UTC on 2026-10-17). No run in this packet was cut off by it;
  the uncommitted K1 files survived and were re-verified before the commit (below).

---

# Batch K2: the complete staging capability profile

Brief: put ten capability flags into both staging templates with their correct defaults, make the validator consistent with a staging
profile, and keep staging refusing Paid252, Tax255, production identity and production free grants. This touches payment-adjacent
configuration, so this section is written for an independent reviewer: what changed, why each value, and how to see it.

## What changed

| File | Change |
| --- | --- |
| `ops/staging/env.staging.example` | The ten flags as explicit `=false` lines with a one-line reason each (the five that were commented are now live lines; two `CONTACT_*` lines sit beside `CONTACT_INQUIRIES_ENABLED`). Also `DISCOVERY_SITEMAP_ENABLED=false`, `VASEY_LISTENING_V2_PROMOTION_ENABLED=false` and the review reference commented, so no config key is left undecided |
| `ops/staging/test-commerce/env.test-commerce.example` | Section 6 now carries the ten flags as explicit `false` (the old section said "do not add them"); section 7 keeps the genuinely absent keys. No flag value was changed to true; the overlay still enables exactly the ten guest-purchase `_ENABLED` flags it enabled before |
| `scripts/ops/validate-test-commerce-profile.php` | 36 checks became 38: `profile.capability_flags_explicit` (each of the ten present and exactly `false` or `(false)`) and `boundary.production_lanes_closed`. The out-of-profile list gained the other capability flags, `discovery-sitemap.enabled` and `customer-listening.v2_promotion_enabled`. Nothing was relaxed |
| `tests/Unit/TestCommerceProfileValidatorTest.php` | 47 to 63 cases: each flag absent and enabled (20 assertions of which checks fail, exactly), accepted and refused spellings, an older profile told exactly what to add, the lanes check, three more broken profiles |
| `tests/ops/test_staging_capability_profile.py` (new, 15 tests) | Template coverage of every `config/` env key, the ten flags and their defaults, the admission rules read from the policy source, the overlay's exact enabled set, no uncommented production key, test-mode Stripe keys only |
| `docs/ops/staging-test-purchase.md` | Check count 36 to 38; the copy step explains section 6 |
| `evidence/k2-admission-matrix.{php,txt}` | The measurement below |

No `app/`, `config/`, `routes/`, census, timing or workflow file changed. The SQLite timing file's case count for the validator test (47) is
now stale; `scripts/ci/phpunit-shards.py` scales a file that gained cases, so it was left alone.

## The ten flags: default, admission and the value chosen

Each default is `false` in `config/` (the test reads `env('KEY', false)` from the file). Admission is read from the policy class and measured
in `evidence/k2-admission-matrix.txt`. "Staging" below means `TestEnvironment::admitsTestCommerce()` (`local`, `testing` or `staging`).

| Flag | Admitted on staging when true? | Value in both templates | Reason |
| --- | --- | --- | --- |
| `VASEY_TEST_SERVICE_PROJECTS_ENABLED` | yes | `false` | not used by the guest purchase; synthetic preparation only |
| `VASEY_TEST_FREE_GRANTS_ENABLED` | yes | `false` | renders a PDF inside the web request; the runbook's FPM check comes first |
| `VASEY_TEST_CUSTOMER_CONSENT_ENABLED` | yes | `false` | no owner-reviewed consent copy exists |
| `VASEY_TEST_UNPAID_RELEASE_ENABLED` | yes, with its exact JSON policy | `false` | not part of this profile; its policy key stays absent |
| `VASEY_TEST_REFUND_RESOLUTION_ENABLED` | yes, with its exact JSON policy | `false` | same |
| `VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED` | yes, with accounts, `private_capture` and the policy version | `false` | not part of this profile |
| `CONTACT_TEST_ORDER_INQUIRIES_ENABLED` | yes, with the public inquiry setup | `false` | no owner-approved notice or retention reference |
| `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED` | **not environment-gated**; also needs a bound `InquiryAlertTransport`, none ships | `false` | inert today; never on without a reviewed transport |
| `VASEY_OPERATIVE_FREE_GRANTS_ENABLED` | **not environment-gated**; needs a bound `FreeGrantIdentity`, none is bound | `false` | production free-grant path: with an adapter bound it admits on production and staging alike (matrix 2). The explicit `false` is the only guard |
| `VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED` | **no** (`local` and `testing` only) | `false` | staging refuses it even when true; stated so nobody reads `true` as working |

Beyond the ten: `DISCOVERY_SITEMAP_ENABLED` (the build and store chain is not environment-gated; the public index serves it in production only)
and `VASEY_LISTENING_V2_PROMOTION_ENABLED` (one-way data promotion) are stated `false` in the base template, and the reference is commented
because a blank assignment is not an unset key. They are outside the brief's ten; they are there so the coverage guard has no undecided key.

## What stays refused

- **Paid252** (`config/paid-grants.php`), **Tax255** (`config/paid-tax-grants.php`, `config/production-tax-checkout.php`), **production customer
  identity** and **production free grants** ship as literals in `config/` with no `env()` call (asserted for the five files). No profile line
  reaches them. `boundary.production_lanes_closed` fails if a code change ever sets an `enabled` or `mounted` flag true in any `production*`, `paid-grants`,
  `paid-tax-grants` or `member-grants` file (16 files today), or renames one of the five named files without updating the validator. Mutating each
  flag and the rename in turn made it fail, naming the key (`evidence/k2-admission-matrix.txt`).
- Live payments: unchanged. `STRIPE_MODE=test` and the `sk_test_` only rules are untouched, `profile.no_live_keys` still runs, and the new
  test asserts neither template holds a live-key shape in any assignment. The overlay's `_ENABLED=true` set is pinned by a test to the ten flags the
  guest purchase already used.

## Verification

| Check | Command | Result |
| --- | --- | --- |
| Validator on the overlay | `php scripts/ops/validate-test-commerce-profile.php --template ops/staging/test-commerce/env.test-commerce.example` | `RESULT: PASS (0 of 38 checks failed ...)`, including the two new checks |
| Validator tests | `php vendor/bin/phpunit tests/Unit/TestCommerceProfileValidatorTest.php` | 63 tests, 578 assertions, pass (47 and 454 before) |
| Journey test on the edited overlay | `php vendor/bin/phpunit tests/Feature/StagingTestCommerceProfileJourneyTest.php` | 9 tests pass |
| Profile guards | `python3 -m unittest tests.ops.test_staging_capability_profile` | 15 tests pass |
| Neighbours | `StagingRuntimeValidatorTest` (17), `ProductionEnvironmentTemplateTest` (6), `test-private-server-preflight.py` (20) | pass |
| All ops tests | `python3 -m unittest discover -s tests/ops -p 'test_*.py'` | 273 tests pass (258 + 15) |
| Style | `vendor/bin/pint --test` on the two PHP files | passed |
| Sensitivity, templates and sources | a flag line commented, a flag set true, an undecided config key added, a default flipped, an admission rule changed (the fixture admitted on staging, an environment gate added to the operative arm), a flag dropped from the validator, an env switch added to a lane file | each failed the capability guards; files restored and compared |
| Sensitivity, validator | explicit check made to accept any value; boundary list reduced to the old one | 11 and 3 PHPUnit failures. The lanes check cannot be reached through the profile file, so it is shown by the config mutations above |
| Admission matrix | `php evidence/k2-admission-matrix.php ENV FLAG [alone\|complete\|bound]`, 3 environments by 10 flags | `evidence/k2-admission-matrix.txt` |

## Open after K2

- **The deploy gate does not check these flags.** `ops/staging/validate-runtime.php` (run by `forge-deploy.sh` before and after the build) still holds
  only the production-checkout flags. Only `validate-test-commerce-profile.php` checks the ten, and it is run by hand. A Forge `.env` with
  `VASEY_OPERATIVE_FREE_GRANTS_ENABLED=true` would deploy. Mirroring the ten into the deploy validator is a small reviewed change (the production
  validator copies that file's holds); it was not part of the brief.
- A `.env` copied from the overlay before this change lacks the ten lines and now fails `profile.capability_flags_explicit`, naming them.
  That is intended; the doc says so.
- The two ungated flags (`VASEY_OPERATIVE_FREE_GRANTS_ENABLED`, `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED`) are a code-level question for the
  application owner: an environment gate in the policy would make staging refuse them structurally. Not changed here (no application edits).
- Nothing in K2 ran against a real host or a real Stripe account; no `--probe` was run.

