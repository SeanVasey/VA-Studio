# Production operations kit (preparation only)

This directory prepares the VASEY.AUDIO production host the same way `ops/staging/` prepares staging: provisioning, web
server, PHP-FPM, workers, deploys and backups on a Laravel Forge VPS. **It is inert.** Nothing here has been run on a host,
no production host has been chosen (U-02 in [`docs/architecture/decision-register.md`](../../docs/architecture/decision-register.md)),
and nothing here authorizes a deploy, a DNS change, live payment collection or launch. The recommendation these files assume
is a separate Forge VPS with the same topology as staging and a local MySQL 8.4; Sean decides U-02.

The kit changes nothing until an operator runs `provision.sh` as root on a server. Everything else in this directory is a
template, a tool that writes to a new directory or to standard output, or a validator that only reads.

## How production differs from staging

| | Staging | Production |
| --- | --- | --- |
| Access | HTTP basic auth, with the Stripe webhook exempt | none: the site is public; no htpasswd, no probe credentials |
| Environment | `APP_ENV=staging` (or the interim `local`), Stripe test mode forced, `MAIL_MAILER=log` forced | `APP_ENV=production` only. The deploy forces neither `MAIL_MAILER` nor `STRIPE_MODE` (see the preflight note below) |
| Commerce units | the test-commerce overlay (`ops/staging/test-commerce/`) | none ship; the writer-admission gate stays, unused |
| Runtime validator | `ops/staging/validate-runtime.php`: test-mode requirements | `ops/production/validate-runtime.php`: the reviewed private-server preflight production profile, real configuration admission, production holds |
| Logs | rotated files under `/var/log/vasey-staging/` | workers and scheduler through supervisor's syslog handler (the journal); nginx errors to stderr; nginx access, PHP-FPM and backup logs are files with logrotate |
| Robots | `noindex` headers | none from nginx; the application decides |
| Paid delivery | dedicated FPM pool (7,800 s), 480 s and 3,900 s read timeouts, 1 GiB temp cap in a private 0700 directory | identical |
| Workers | `media`, `payments`, `contracts`, `default,inquiry-alerts` plus the scheduler | identical |
| Names | `/srv/vasey-staging`, `/etc/vasey-staging`, `vasey-staging-ctl`, DB `vasey_staging` | `/srv/vasey-production`, `/etc/vasey-production`, `vasey-production-ctl`, DB `vasey_production` (the account stays `vasey_app@127.0.0.1`) |

**The preflight note.** The validator reuses the reviewed `scripts/ops/private-server-preflight.php --profile=production`
instead of restating it. That profile currently requires `MAIL_MAILER=log`, `STRIPE_MODE=test` and every test-commerce switch
off, so a production environment that sets anything else is refused today, by the preflight and not by this kit. Relaxing
those two values (a real mail transport, live mode) is a reviewed change to the preflight; this kit then follows it without
an edit. What the validator adds on its own is listed in the header of `validate-runtime.php`: the real-configuration checks
and the holds (no live-looking Stripe credential anywhere, every `PRODUCTION_CHECKOUT_*` switch the literal `false`, funds
mode and credentials blank, and the four pre-launch capability flags false).

## Files

| File | What it is | How it is used |
| --- | --- | --- |
| `provision.sh` | Entry point. Assembles the kit (below), runs the assembled `provision.sh`, removes the assembly | `sudo bash ops/production/provision.sh --host <HOST>`; `--keep-build` keeps the assembly in `/root/vasey-production-kit.*` |
| `build-kit.py` | Derives the kit from `ops/staging/`: renames plus counted patches. `--delta` prints the differences, `--check` builds and discards | no root needed |
| `validate-runtime.php` | Production runtime admission | run by the deploy from each release; `--runtime` adds the preflight host checks for a served release |
| `nginx/vasey-production.conf` | Site template: TLS, limits, headers, the long paid routes, masked access log | pasted into Forge |
| `env.host-overlay.example` | The kit's own non-secret values (database host and name, tool paths) | layered by `compose-env.py` |
| `compose-env.py` | Composes the environment template from `ops/private-server/env.example`, `env.production.example` and the overlay | `python3 -I ops/production/compose-env.py --host <HOST>` |
| `env.production.example` | The reviewed feature-family switches, every one off (existing, unchanged) | never filled in place |

Derived from `ops/staging/` at build time, not committed here: `provision.sh`, `forge-deploy.sh`, `release-step.sh`,
`backup.sh`, `bin/vasey-production-ctl`, the default PHP-FPM pool and the worker programs. Copied byte for byte:
`seal-release.py`, `normalize-mysql-dump.py` and the paid-delivery pool `php-fpm/vasey-paid-delivery.conf`.

### Why the kit is derived and how to review it

The staging scripts are about 1,900 lines of reviewed root-level code, tested by `tests/ops/test_staging_*.py`. A copy would
need a second line-by-line review and would drift. Instead each derived file is the staging file with mechanical renames and a
short list of patches in `build-kit.py`, each with the number of times its anchor must match. Any other count stops the build,
so a change to `ops/staging/` that touches an anchor forces a re-review here. **Review `python3 -I ops/production/build-kit.py
--delta`** (about 220 lines, mostly the removed basic-auth block): it is the staging file after renames against the production
file, nothing else. `release-step.sh`, `backup.sh` and the default PHP-FPM pool have no delta at all beyond the renames. The
control helper has two small hunks: it no longer requires probe credentials, and the readiness probe sends none.
`build-kit.py` refuses to leave any staging-only name, basic-auth or netrc reference behind.

One consequence: the deploy script that Forge runs is the **root-installed** copy,
`/usr/local/libexec/vasey-production/forge-deploy.sh`, not a script from the checkout. A change to deploy logic reaches a host
when an operator re-runs `provision.sh`, which is idempotent.

## Provisioning, step by step

The **Who** column says whether Sean does the step in the Forge UI or a script does it. It follows
[`ops/staging/README.md`](../staging/README.md#provisioning-step-by-step); only the differences are spelled out.

| # | Who | Step |
| --- | --- | --- |
| 1 | **Sean, Forge** | **Create the server**, separate from staging: provider chosen under U-02, type *App server*, **Ubuntu 24.04 LTS**, **PHP 8.4**, **MySQL 8.4**. Staging's sizing (4 vCPU / 8 GB / at least 160 GB) is the floor; production sizing is part of U-02. Reserve 3 GiB for `clamd` if the daemon route is used (`ops/clamav/`). Leave the Forge firewall at 22/80/443. |
| 2 | **Sean, DNS** | **Not part of this kit.** Use a non-apex rehearsal hostname for the certificate and the checks. The apex and `www` change only at the authorized cutover (`docs/migration/cutover_runbook.md`). Moving to the final host name later is an idempotent re-run of `provision.sh --host` plus an environment refresh. |
| 3 | **Sean, Forge** | **Create the site** for that host: Laravel, PHP 8.4, no Forge-created database, no website isolation. |
| 4 | **Sean, Forge** | **Install the repository** (`SeanVasey/VA-Studio`, the branch carrying the SHA). **Untick "Install Composer dependencies"** and leave **Quick Deploy off**. |
| 5 | **Sean, Forge** | **Get the certificate** (Let's Encrypt) before step 7. The readiness probe checks the certificate against the host name, so use a publicly trusted one. |
| 6 | **Script** (Sean over SSH) | `sudo bash /home/forge/<HOST>/ops/production/provision.sh --host <HOST>`. There is no password prompt. Safe to re-run. |
| 7 | **Sean, Forge** | Fill `__SSL_CERT__`, `__SSL_KEY__` and `__FORGE_CONF__` in `/etc/vasey-production/nginx-site.conf` from Forge's config, check `grep -n '__[A-Z_]*__'` prints nothing, then paste it into Site → Edit Nginx Configuration. |
| 8 | **Sean, Forge** | **Environment.** On the host, `python3 -I ops/production/compose-env.py --host <HOST>` prints a template with no secrets. Paste it into Site → Environment and fill only `APP_KEY` (generate it once on the server: `php8.4 -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`), `DB_PASSWORD` (`sudo cat /root/vasey-production-secrets/db-app.env`), `MEDIA_TAG_PATH` and `MEDIA_TAG_SHA256`, in Forge. The template is BLOCKED by the private-server preflight until those four are set. The first deploy checks the filled environment twice: the cheap frozen-environment check, then the validator on the built candidate. The mirror checkout has no `vendor/`, so the preflight itself cannot run there; to check earlier, run it from any built checkout as the application user. |
| 9 | **Sean, Forge** | **Deployment script** (replace Forge's default with exactly this), then **Deploy Now**: `set -euo pipefail`, `cd "$FORGE_SITE_PATH"`, `git fetch --prune origin "$FORGE_SITE_BRANCH"`, `git checkout --detach --force FETCH_HEAD`, `bash /usr/local/libexec/vasey-production/forge-deploy.sh "$(git rev-parse HEAD)"`. |
| 10 | **Script** (Forge runs it) | First deploy, the same sequence as staging: frozen environment check, quiesce, protected build, the production validator, snapshot and restore proof, migrate, caches, `vasey:doctor`, switch, healthy resume. |
| 11 | **Sean, SSH** | First-run setup as in `docs/ops/staging-runbook.md` with production names: operators with TOTP, the seller tag, `vasey:doctor`, the backup destination. |

A failed first deploy leaves no served release and a closed writer gate, exactly as on staging; read the end of the output and
`vasey-production-ctl status`.

## Deploy sequence and limits

The sequence is staging's ([`ops/staging/README.md`](../staging/README.md#deploy-sequence)) with one difference: the runtime
gate before quiesce and again on the built candidate is `ops/production/validate-runtime.php`. The queue, timeout and nginx/PHP
limit tables there apply unchanged: `DB_QUEUE_RETRY_AFTER` at least 1200, `stopwaitsecs` 960/120/120/960/120, 210 MiB Livewire
uploads, 16 MiB elsewhere, 480 s and 3,900 s on the two paid routes with buffering on and a 1 GiB temp cap in
`/var/lib/nginx/vasey-production-fastcgi` (0700), and the dedicated 7,800 s pool with `listen.backlog = 8`. The paid routes are
not mounted (`config/paid-grants.php` ships `http_mounted` as a literal `false`); until that changes, those locations answer 404. The Tax V2 routes under `/paid-tax-grants/` are not covered by these locations; see the staging README's note on them.

## Cache housekeeping

The kit's scheduler is the same `schedule:work` program as staging (derived from the staging workers file), so it runs
`vasey:cache-prune-expired` hourly at :23 once a release containing it is served. That command deletes only expired rows from
the `cache` and `cache_locks` tables, so a plain-text Filament flash notification or stale throttle counter outlives its own TTL
by at most about an hour plus the run time (independent review R3 of the Filament 5.10.2 update; the TTL of an undelivered
notification is `SESSION_LIFETIME`). It is a production requirement that the scheduler is
running, `CACHE_STORE=database` is set, and the cache is one shared persistent store (not `array`, and not a `file` store that
differs between hosts): otherwise notifications that cross requests and TOTP replay records are lost or not shared. Never clear the
cache to tidy it; that resets the throttles and the TOTP replay records. Nothing has run on a host (above), and the production host
is undecided (U-02), so this is configuration evidence only: see
[`docs/verification/cache-prune-20261024/README.md`](../../docs/verification/cache-prune-20261024/README.md).

## Logging

- Workers and the scheduler: `stdout_syslog=true`, so supervisor sends each line to syslog prefixed with the program name
  (`vasey-production-scheduler ...`, checked in supervisor's source); `redirect_stderr=true` carries Laravel's `stderr`
  channel (`LOG_CHANNEL=stderr`, required by the preflight). Nothing to rotate; set retention in journald.
- PHP-FPM: `catch_workers_output = yes` forwards worker stderr to the FPM master log. To send that to the journal as well, set
  `error_log = syslog` in the `[global]` section of `/etc/php/8.4/fpm/php-fpm.conf` (Forge: Server → PHP → Edit FPM
  Configuration). That is a host setting outside this kit and was not exercised here. PHP engine errors and the slow log are
  files, rotated by the logrotate file `provision.sh` writes.
- nginx: `error_log stderr error;` (journal) and an access log in `/var/log/nginx/vasey-production-*.log`, rotated weekly, that
  masks the UUID of the two paid-delivery paths. The error log still quotes a request line when an upstream fails.

## Backups, scanner, monitoring

- Backups use the same script as staging (derived, so it carries the opt-in hardening): age encryption to a public recipient,
  an rclone remote, tiered retention. Production should set `VASEY_BACKUP_REQUIRE_ENCRYPTION=1`. See
  [`docs/ops/backup-offhost-hardening.md`](../../docs/ops/backup-offhost-hardening.md). Point-in-time restore from the binary
  log is a later step, and a rollback by restoring a snapshot discards every order taken since it: see Rollback.
- The malware scanner defaults to `clamscan`, as on staging. The daemon route is opt-in: [`ops/clamav/`](../clamav/README.md).
- Heartbeat and queue age, scheduler liveness, signature age, the firewall and unattended-upgrades baseline, and the CDN and
  proxy note: [`docs/ops/monitoring-and-host-baseline.md`](../../docs/ops/monitoring-and-host-baseline.md).

## Rollback

The procedures in the staging runbook's "Rollback" section (A to D) apply with production names: `vasey-production-ctl`,
`vasey-production-backup`, `/srv/vasey-production`, `/etc/vasey-production/production.conf` and the schema `vasey_production`.
`forge-deploy.sh` points here for them. **Two things are different in production and need a decision before anyone runs B.**
Restoring the pre-deploy snapshot drops and reloads the whole schema, so every order, payment record and grant created after the
snapshot is lost; with real customers that is an incident decision for Sean, not a script step. Binary-log replay to a
point in time would narrow the loss, but it is a later step and is not built. And a restore never regenerates `APP_KEY`: the
key comes back from the hash-verified `env.backup`.

## Open reviewed changes (not in this kit)

These are application or review items this kit deliberately does not do:

1. **`trustProxies`** is not configured in the application. Behind a CDN or reverse proxy the client address, scheme and rate
   limits are wrong until a reviewed change configures it against a reviewed address list. See the CDN note in the monitoring
   guide.
2. **Managed or remote MySQL.** The kit, the validator and the schema's trigger definers assume `DB_HOST=127.0.0.1` and the
   account `vasey_app@127.0.0.1`. A managed database needs a reviewed relaxation of that in the validator and the deploy,
   and a decision about trigger definers.
3. **Production identity mail.** The preflight production profile requires `MAIL_MAILER=log`; a real SMTP transport for production
   customer identity is a reviewed change to the preflight and the application.
4. **Preflight production profile:** `STRIPE_MODE` and `MAIL_MAILER` as above, and every activation in
   `docs/ops/production-activation-packet.md` (live checkout, the live webhook receiver and its nginx limits, paid delivery
   mounting, membership billing). Each needs Sean's separate authorization.
5. **Scheduler heartbeat.** The application leaves no trace when no release is due; a ping on schedule success is a reviewed change.
6. **Pre-launch capability hold (approved by Sean, 2026-10-18).** `ops/production/validate-runtime.php` refuses four flags unless they
   are false: `VASEY_OPERATIVE_FREE_GRANTS_ENABLED` and `CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED` (no environment gate; each admits
   once an adapter is bound by other code, and none ships today), `DISCOVERY_SITEMAP_ENABLED` (the public index serves track sitemaps in
   production once it is true) and `VASEY_LISTENING_V2_PROMOTION_ENABLED` (a one-way data promotion; its only consumer needs a customer session, which only the test
   customer access path creates, so this one is defence in depth). They are
   the `PRE_LAUNCH_HOLDS` entries in that file, and a refusal is `holds.<lowercase flag name>`. Lifting one is a reviewed change to the
   validator together with Sean's authorization for that activation; see `docs/ops/production-activation-packet.md` (section 1). The
   `VASEY_TEST_*` flags, the support fixture and the synthetic rehearsal catalog flag (`VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED`) are
   refused by their own environment gates or by the preflight, not by this hold.

## What was and was not verified

See [`docs/verification/production-ops-kit-20261017/README.md`](../../docs/verification/production-ops-kit-20261017/README.md).
In short: the derivation, patches, validator, nginx template (`nginx -t` and a live run of the log masking), supervisor and
PHP-FPM syntax, the env composition and the backup and monitoring tools were exercised locally. Nothing was run on a host: no
provisioning, no Forge, no systemd, no real deploy, backup, restore or off-host transfer.
