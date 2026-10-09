# Private test-mode staging on Laravel Forge

This kit stands up a private, test-mode staging copy of the VASEY.AUDIO store on a VPS that Laravel Forge
manages (Hetzner or DigitalOcean). It covers provisioning, the web server, PHP-FPM, workers, deploys and
backups. Day-to-day operation (first deploy, operators, seller tag, `vasey:doctor`, post-purchase console
steps, rollback) is in [`docs/ops/staging-runbook.md`](../../docs/ops/staging-runbook.md).

> **Scope.** Staging runs Stripe **test mode** only. It is not production, and nothing here authorizes
> live payments, DNS changes to the apex or `www`, customer imports or launch. The environment is
> `APP_ENV=local` with `APP_DEBUG=false` (interim profile accepted by Sean). Every test-commerce policy only
> admits `local` or `testing`. `APP_ENV` is a single variable: switching to `staging` later means editing the
> Forge environment and re-running `provision.sh --app-env staging`.

## Access model

- The site is public at `https://<STAGING_HOST>` (for example `staging.<domain>`) with a Let's Encrypt
  certificate from Forge.
- HTTP basic auth protects everything except exactly `POST /webhooks/stripe`. Stripe test events reach that
  path directly, and the application verifies their signature. Every other path and method, including
  `GET /webhooks/stripe`, `/webhooks/stripe/` and `/index.php/webhooks/stripe`, needs the staging credentials.
- Search engines are kept out three ways:
  - the credentials;
  - `X-Robots-Tag: noindex` from nginx;
  - the application's own non-production behaviour: `robots.txt` answers `Disallow: /` and pages carry
    `noindex, nofollow`.
- **Fallback, documented only.** If Sean ever closes the public webhook, `stripe listen --forward-to
  https://<STAGING_HOST>/webhooks/stripe` from an operator machine delivers events instead. Lane B owns that path.

## Files

| File | What it is | Installed to |
| --- | --- | --- |
| `provision.sh` | One-time root provisioning for everything Forge does not do | run in place |
| `nginx/vasey-staging.conf` | Site template: TLS, basic auth with the webhook exemption, limits, headers, long paid routes | pasted into Forge |
| `php-fpm/vasey-staging.conf` | Default pool (300 s, 200 MiB uploads) | `/etc/php/8.4/fpm/pool.d/` |
| `php-fpm/vasey-paid-delivery.conf` | Dedicated paid-delivery pool (7,800 s, no CPU cap), per `docs/ops/paid-delivery-runtime.md` | `/etc/php/8.4/fpm/pool.d/` |
| `workers/vasey-staging-workers.conf` | Supervisor programs for the `media`, `payments`, `contracts` and `default`/`inquiry-alerts` workers, plus the scheduler | `/etc/supervisor/conf.d/vasey-staging.conf` |
| `bin/vasey-staging-ctl` | Root helper: allocate/seal release ancestry, attach/detach storage, switch, quiesce, resume, snapshot, prune. It is the only sudo grant. | `/usr/local/sbin/` |
| `backup.sh` | Nightly backup: dump, private archive, `.env`, an isolated restore proof, then an off-host copy | `/usr/local/sbin/vasey-staging-backup` |
| `normalize-mysql-dump.py` | Schema-only charset rendering comparison; preserves retained data bytes | `/usr/local/libexec/vasey-staging/` |
| `validate-runtime.php` | Isolated real Laravel configuration admission before quiesce; rejects key changes | run from the built release |
| `forge-deploy.sh` | Release and activation; Forge's deployment script calls it | run from the Forge checkout |
| `env.staging.example` | Every `.env.example` variable with staging-safe values and placeholders | pasted into Forge |

## Server layout

```
/home/forge/<STAGING_HOST>/          Forge site checkout: git mirror + Forge-managed .env. Never served.
/srv/vasey-staging/
  releases/<40-hex SHA>/             one immutable checkout per deploy (composer, npm build, .env 0600)
    storage/app/private  ──bind──►   /srv/vasey-staging/private (never a symlink: the app refuses symlinks)
  current -> releases/<SHA>          what nginx, PHP-FPM and the workers run
  private/                           masters, stems, contracts, seller tag, site images, uploads (forge 0700)
  tmp/php-upload, tmp/php-sys        PHP upload and temp directories (forge 0700)
  backups/                           local backup sets (root 0700)
  evidence/<UTC>-<sha12>/            per-deploy evidence: build hashes, migrate output, doctor and readiness JSON
/var/lib/nginx/vasey-staging-fastcgi nginx FastCGI temp (private, 0700; buffered downloads; never backed up)
/etc/vasey-staging/                  staging.conf (no secrets), backup.my.cnf and probe.netrc (root 0600), nginx-site.conf
/root/vasey-staging-secrets/         generated MySQL app password (root 0600)
```

One OS user, Forge's `forge`, runs PHP-FPM, every worker and the scheduler. It also owns
`private/`. `vasey:doctor` and every private-file adapter require this.

The root and `releases/` parents remain root-owned and unwritable by the app. `ctl allocate` creates a
checkout slot for the app; `ctl attach` seals the release, `storage/` and `storage/app/` parents as root-owned
before mounting. Framework/cache/log subdirectories and the private leaf remain writable by the app.
Only the root helper switches `current`. Custom roots must have canonical, root-owned ancestry without
group/world write permission. Re-provisioning an existing kit does not certify its previously built releases;
use a fresh reviewed release and prove sealing/attachment before activation.

Every independently callable helper action holds a separate root-owned `/etc/vasey-staging/ctl.lock`.
This serializes attach/detach/prune/switch/resume/snapshot across concurrent sudo callers, including the
entire pre-deploy backup. It is separate from the outer deploy lock, and application subprocesses do not
inherit its descriptor. Re-provisioning preserves its inode. Nightly snapshots use this same helper action
and fresh stopped-writer proof, so direct `ctl resume` cannot restart writers during a private-file copy.

## Provisioning, step by step

The **Who** column says whether Sean does the step in the Forge UI (or at his registrar) or a script does it.

| # | Who | Step |
| --- | --- | --- |
| 1 | **Sean, Forge** | **Create the server.** Provider Hetzner or DigitalOcean; type *App server*; **Ubuntu 24.04 LTS**; **PHP 8.4**; database **MySQL 8.4**. Size it at **4 vCPU / 8 GB RAM** (absolute minimum 4 GB: one `clamscan` may use 3 GiB of address space, and MySQL, PHP-FPM and ffmpeg run alongside it). Use at least 160 GB of disk: each master is stored with its derivatives, and stems ZIPs run up to 200 MiB. Save the sudo and database passwords Forge shows once. Leave the Forge firewall at 22/80/443. |
| 2 | **Sean, DNS** | **Add the staging record.** Create an `A` (and `AAAA`) record for `<STAGING_HOST>` pointing at the server. Never touch the apex or `www`. |
| 3 | **Sean, Forge** | **Create the site.** Root domain `<STAGING_HOST>`; project type Laravel; PHP 8.4; no Forge-created database (provisioning creates it); no website isolation (otherwise pass `--app-user` in step 6). |
| 4 | **Sean, Forge** | **Install the repository.** GitHub `SeanVasey/VA-Studio`, the branch carrying the SHA to stage. **Untick "Install Composer dependencies"**, and leave Quick Deploy **off**: deploys are deliberate. |
| 5 | **Sean, Forge** | **Get the certificate.** Site → SSL → Let's Encrypt for `<STAGING_HOST>`, then activate it. Do this before step 7: the template keeps Forge's HTTP-01 challenge handling. |
| 6 | **Script** (Sean runs it over SSH) | **Run provisioning:** `sudo bash /home/forge/<STAGING_HOST>/ops/staging/provision.sh --host <STAGING_HOST>`. It asks for a basic-auth user and password (16+ characters, no spaces) and prints where the generated credentials are. It is safe to re-run. Details are under [What provision.sh does](#what-provisionsh-does). |
| 7 | **Sean, Forge** | **Install the nginx site.** Open `/etc/vasey-staging/nginx-site.conf` (provisioning has filled in the host and root). Replace `__SSL_CERT__`, `__SSL_KEY__` and `__FORGE_CONF__` with the values from Forge's current config: copy its `ssl_certificate` lines and its `include forge-conf/...` lines. Check that `grep -n '__[A-Z_]*__'` prints nothing, then paste the file into Site → Edit Nginx Configuration. Forge validates it and reloads nginx. |
| 8 | **Sean, Forge** | **Set the environment.** Paste `ops/staging/env.staging.example` into Site → Environment and fill every `<...>`. For `APP_KEY`, run `php8.4 -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'` once on the server and keep the value. For `DB_PASSWORD`, run `sudo cat /root/vasey-staging-secrets/db-app.env`. Leave `MEDIA_TAG_*` until the runbook's seller-tag step. |
| 9 | **Sean, Forge** | **Set the deployment script.** Replace Forge's default with the five lines at the top of `forge-deploy.sh`, then click **Deploy Now**. |
| 10 | **Script** (Forge runs it) | **First deploy.** `forge-deploy.sh` runs the [deploy sequence](#deploy-sequence). On a first install, `vasey:doctor` may fail only on `operator`. The site then comes up empty, behind basic auth. |
| 11 | **Sean, SSH** | **Finish first-run setup** with [`docs/ops/staging-runbook.md`](../../docs/ops/staging-runbook.md): two operators with TOTP, the seller tag, `vasey:doctor` until it passes, and the backup destination. |

### Packages and versions

`provision.sh` installs or checks the following. Forge supplies PHP, nginx, MySQL, Composer and supervisor.

| Component | Requirement | Why |
| --- | --- | --- |
| PHP 8.4 CLI + FPM | `pdo_mysql`, `pdo_sqlite`, `mbstring`, `intl`, `bcmath`, `gd`, `fileinfo`, `zip`, `curl`, `dom`, `xml`, `xmlwriter`, `posix`, `pcntl`, `ctype`, `iconv`, `tokenizer`, `openssl` | `vasey:doctor` `php_runtime` (it requires `pdo_sqlite` even on MySQL), CI's extension list, renderer children, `pcntl` for worker timeouts |
| MySQL | 8.4, binary log on, `log_bin_trust_function_creators=ON` | about 567 triggers created by a non-root account (see below) |
| nginx | as Forge installs it (1.24+) | template uses `listen ... ssl http2` |
| Node.js | `>=24.15.0 <25` (`package.json` engines), installed from NodeSource if Forge's is older | `npm ci && npm run build` |
| ffmpeg / ffprobe | encoders `libmp3lame`, `pcm_s16le`, `png`, `mjpeg`, `libwebp` | `vasey:doctor` `media_encoders`, previews and artwork |
| util-linux `prlimit` | must apply 3 GiB/180 s scanner and 2 GiB/90 s media limits | `config/media.php` |
| ClamAV `clamscan` + `freshclam` | signatures under 48 h old; `clamav-daemon` disabled | `config/media.php` `max_signature_age_seconds`; `clamscan` is the configured engine |
| qpdf, poppler-utils | present | contract PDF checks (`scripts/contract-profile-smoke.php`) |
| supervisor, rsync, openssl, curl, Python 3 | present | workers, off-host backups, credentials, probes, schema-only dump comparison |

### What provision.sh does

1. **Checks:** Ubuntu 24.04; RAM (refuses under about 3.7 GB, warns under 8 GB); swap; PHP extensions in the CLI and in FPM; ffmpeg encoders; `prlimit` limits; tool paths; ClamAV signature age and readability by the app user.
2. **Directories:** as in [Server layout](#server-layout), with the stated owners and modes. Private storage gets the same `.gitignore` bytes the repository tracks, so a backup restores exactly the tree the release expects.
3. **MySQL** (as root over the local socket, or with `--mysql-admin-defaults FILE`):
   - schema `vasey_staging` (`utf8mb4_unicode_ci`);
   - `vasey_app@127.0.0.1`, the runtime and migration account, with schema-scoped `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, CREATE TEMPORARY TABLES, LOCK TABLES`;
   - `vasey_backup@127.0.0.1`, the backup account, with `SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT` plus global `SHOW_ROUTINE`;
   - `SET PERSIST log_bin_trust_function_creators = ON`.
   It never rotates an existing password.
4. **Host files:** the two FPM pools (it runs `php-fpm8.4 -t` and reloads), the supervisor programs, the `vasey-staging-ctl` and `vasey-staging-backup` helpers, `/etc/sudoers.d/vasey-staging` (checked with `visudo -c`), the backup cron (03:17 UTC) and logrotate.
5. **Access:** the basic-auth file (SHA-512 crypt) and a root-only netrc that the quiesce and resume probes use. It also renders the nginx site.

**Why one database account, and why `log_bin_trust_function_creators`.** On MySQL 8.4 with binary logging on,
`CREATE TRIGGER` from an account without `SUPER` fails with error 1419. This was verified on a private 8.4.11
server. The migrations create 567 triggers.

Granting `SUPER` is far broader than needed. Turning off binary logging would lose point-in-time recovery.
The variable relaxes only the binlog-safety check for stored-program creators. With `binlog_format=ROW`, the
default, row events replicate trigger effects, so the risk the check guards against does not arise. Only
`vasey_app` holds `TRIGGER`.

A separate DDL-only migration account would not work:
- triggers execute as their `DEFINER`;
- the application reads `information_schema.TRIGGERS` at runtime (17 classes) to verify its own schema;
- MySQL shows those rows only to accounts holding `TRIGGER` on the table.

Use `DB_HOST=127.0.0.1`, never `localhost`. Every trigger's definer is `vasey_app@127.0.0.1`, and dropping or
renaming that account breaks every guarded write.

### Deploy sequence

`forge-deploy.sh` runs as `forge` and follows `docs/ops/production-activation-packet.md` §4 (S1):

1. It freezes one private copy of the Forge `.env` and validates that candidate without printing values:
   - `APP_ENV` equals the configured value; `APP_DEBUG=false`; HTTPS `APP_URL`; secure cookies;
   - database cache, queue and sessions; `DB_HOST=127.0.0.1`; not `root`;
   - `MAIL_MAILER=log`; `STRIPE_MODE=test`; no live Stripe key; no production checkout flag.
   The built release then admits the same copy through real Laravel configuration with inherited application
   settings and foreign configuration caches excluded. Duplicate keys, DB URL/socket overrides, production
   credentials and an `APP_KEY` change from the served release refuse before quiesce. The temporary copy is
   removed on exit; a later Forge edit waits for the next deploy attempt.
2. It makes a fresh checkout of the exact SHA in `releases/<SHA>` and proves it clean (status and tree hash).
3. It runs `composer install --no-dev --classmap-authoritative` from `composer.lock`, then `npm ci && npm run build`. The Vite manifest must exist.
4. The validated `.env` is installed `0600`; `vasey-staging-ctl attach` seals privileged ancestry and bind-mounts private storage.
5. If a release is serving, `vasey-staging-ctl quiesce`:
   - `artisan down` in that release, proven by an exact 503;
   - workers and scheduler stopped;
   - PHP-FPM stopped, and no PHP process left.
6. `vasey-staging-ctl snapshot` takes a backup and **restore proof** before anything is migrated.
7. In the new release:
   - `artisan down`, then `migrate --pretend` (evidence only; see the runbook), then `migrate --force`;
   - `config:cache`, `route:cache`, `view:cache`, `event:cache` (all verified to work with this codebase);
   - `vasey:doctor`, plus redacted `commerce-readiness` and `stripe-preflight` JSON.
8. `vasey-staging-ctl switch` requires stopped web/workers, maintenance and attached private storage, then switches `current` atomically. `vasey-staging-ctl resume` then:
   - starts PHP-FPM and proves a 503;
   - starts the workers and proves each one runs in the new release (`/proc/<pid>/cwd`);
   - runs `artisan up` and requires `GET /` to answer 200, or re-enters maintenance.
9. It prunes to the newest 3 releases, detaching storage first. **Never `rm -rf` a release by hand**: while
   attached, its `storage/app/private` *is* the persistent store.

Deploying the served SHA again with a changed Forge `.env` refreshes configuration only:
real admission and unchanged key, quiesce, snapshot and restore proof, install the exact validated `.env`,
`config:cache`, resume. A failed quiesce or partial resume leaves writer/service state unconfirmed; inspect
`ctl status` and establish quiesce before recovery. The failure phase flag is not stopped-writer evidence.

`storage:link` is not run: no code uses the public disk. Previews, artwork and site images stream through
controllers from private storage. `queue:restart` is issued by the quiesce. The workers are then stopped
outright and started on the new release.

### Queues, timeouts and the scheduler

| Program | Queue(s) | Jobs | Worker flags | `stopwaitsecs` |
| --- | --- | --- | --- | --- |
| `vasey-staging-media` | `media` | `ProcessMedia`, `ProcessSiteImage` (900 s, 3 tries) | `--timeout=900 --tries=3` | 960 |
| `vasey-staging-payments` | `payments` | `ProcessStripeReceiptJob`, `FinalizeTestPaymentJob` (90 s, 1 try) | `--timeout=90 --tries=1` | 120 |
| `vasey-staging-contracts` | `contracts` | `IssueTestContractJob` (90 s, 1 try; renders in a PHP CLI child) | `--timeout=90 --tries=1` | 120 |
| `vasey-staging-default` | `default,inquiry-alerts` | `ProcessSoundKit` (900 s), `BuildDiscoverySitemapWindow` (30 s), `RetrieveMembershipInvoice`, `DeliverProductionIdentityNotice`, `NotifyInquiryOperatorJob` (30 s) | `--timeout=120 --tries=1` (jobs' own values win) | 960 |
| `vasey-staging-scheduler` | n/a | `schedule:work`, which equals the documented every-minute cron: `vasey:publish-scheduled-site-release` | n/a | 120 |

`DB_QUEUE_RETRY_AFTER=1200` exceeds the longest job (900 s) and the media claim lease (960 s). Don't add a
Forge Scheduler entry or Forge Daemons as well. Payment pipeline commands that need a schedule or a loop
(receipt processing, reconciliation, activation) belong to Lane B (`ops/staging/test-commerce/`).

### Limits in nginx and PHP

| Path | Body | Read timeout | PHP pool |
| --- | --- | --- | --- |
| `POST /webhooks/stripe` (no basic auth) | 1 MiB (`VerifyStripeWebhook::MAX_BODY_BYTES`) | 60 s | default |
| `/livewire-<hash>/upload-file` | 210 MiB (200 MiB masters and stems, `max:204800`) | 300 s | default (`upload_max_filesize=201M`, `post_max_size=210M`) |
| `/paid-grants/authorizations/{uuid}/redeem` | 1 MiB | 480 s, buffered, 1 GiB temp cap | `vasey-paid-delivery` (`request_terminate_timeout=7800s`, `max_execution_time=0`, `listen.backlog=8`) |
| `/paid-grants/origins/{uuid}/document` | 1 MiB | 3,900 s | `vasey-paid-delivery` |
| everything else | 16 MiB (8 MiB resumable chunks) | 300 s | default (`request_terminate_timeout=300s`) |

The paid routes are not mounted on `main` yet (PR #56). Until they are, these locations reach Laravel and
answer 404. Before mounting, run the verification list at the end of `docs/ops/paid-delivery-runtime.md`.

Security headers come from nginx:
- HSTS, `nosniff` and `Permissions-Policy` on every response.
- `Referrer-Policy` and `X-Robots-Tag` only when the application did not set its own, so its stricter
  per-response values survive.
- `X-Frame-Options: SAMEORIGIN` everywhere except `/embed/`, which the application deliberately allows to be
  framed.

There is no global CSP: the application sets CSP on the responses that need one.

### Backups

`vasey-staging-backup nightly` (cron, 03:17 UTC) runs these steps:

1. Quiesces the host. Maintenance lasts through the snapshot, restore proof and optional off-host transfer;
   duration depends on retained media and database size.
2. Writes a `mysqldump --single-transaction` of the schema, the private-storage archive with file and
   directory manifests, and the served `.env` (it holds `APP_KEY`).
3. Proves the restore inside the serialized `ctl snapshot` action. A disposable `mysqld` on a private socket loads the dump and re-dumps it, the archive is
   extracted as `forge` into a fresh directory, and hashes, names, directories, modes and ownership are checked.
   Only the immediate charset attribute of a string column in the dump's matching table-definition block
   may be normalized. All row/default/comment/routine bytes remain exact. Reproof invalidates an old success
   marker before checking hashes; a new marker is published atomically after verification and cleanup.
4. Copies the set off-host with `rsync` over SSH if `VASEY_BACKUP_DEST` is set in `/etc/vasey-staging/staging.conf`.
5. Resumes the site, including after a failed snapshot/proof/transfer, then reports any failure. Successful
   runs prune old local sets. A failed resume remains an explicit recovery condition.

`docs/ops/staging-runbook.md` covers setting the destination, retention, and restoring.
