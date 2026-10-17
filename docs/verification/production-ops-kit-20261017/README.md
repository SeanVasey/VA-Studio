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
Every other file under `ops/staging/` is untouched. The slices the existing tests cut out of `backup.sh` (`mycnf_ok()` to
`RC_WORK=""`, and `restore_check()` to its first `install -d`) are unchanged.

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
