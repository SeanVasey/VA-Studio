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
| `seal-release.py` | Parent-first protected code sealing and safe private environment capture | `/usr/local/libexec/vasey-staging/` |
| `release-step.sh` | Fixed unprivileged build and migration/cache steps under the enclosing root lease | `/usr/local/libexec/vasey-staging/` |
| `normalize-mysql-dump.py` | Schema-only charset rendering comparison; preserves retained data bytes | `/usr/local/libexec/vasey-staging/` |
| `validate-runtime.php` | Isolated real Laravel admission: protected current release before quiesce, built candidate before attachment; rejects key changes | run from each selected release |
| `forge-deploy.sh` | Release and activation; Forge's deployment script calls it | run from the Forge checkout |
| `env.staging.example` | Every `.env.example` variable with staging-safe values and placeholders | pasted into Forge |

## Server layout

```
/home/forge/<STAGING_HOST>/          Forge site checkout: git mirror + Forge-managed .env. Never served.
/srv/vasey-staging/
  releases/<40-hex SHA>/             protected checkout per deploy (composer, npm build, .env root:app 0440)
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
before mounting. The sealer walks through no-follow directory descriptors, closes each parent before
opening children and copies regular code/vendor/build files onto fresh root-owned read-only inodes.
Hard links, external/runtime code symlinks and special files refuse; an old writable descriptor cannot
change the newly installed inode. Executable bits remain executable and nginx can read public build files.
The configured application account must be nonroot; provisioning and privileged entry points refuse UID 0. The protected `.env` is root:app-group 0440. Only `ctl refresh` performs ordinary environment refresh,
under closed writer admission, after real runtime admission and unchanged-key validation.

Explicit runtime trust exceptions are `bootstrap/cache`, `storage/framework`, `storage/logs` and
`storage/app/private`: application-owned 0700 directories. Generated PHP caches, compiled views and
maintenance files remain application writable. This protects deployed source/vendor/build/environment
bytes after sealing; it does not certify an uncompromised builder or immutable runtime PHP execution.
Private bind-mounted contents are never traversed or changed by sealing.
Links to the protected `.env` refuse, and every link in `public` must resolve inside
`public`; friendly static aliases cannot expose private release files or directories.
Only the root helper switches `current`. Custom roots must have canonical, root-owned ancestry without
group/world write permission before provisioning; nonexistent descendants are created only under that
protected prefix. App-owned ancestors, symlinks and noncanonical components refuse before host changes.
Re-provisioning an existing kit does not certify its previously built releases;
use a fresh reviewed release and prove sealing/attachment before activation.

Every independently callable helper action holds a separate root-owned `/etc/vasey-staging/ctl.lock`.
This serializes attach/detach/prune/switch/resume/snapshot across concurrent sudo callers, including the
entire pre-deploy backup. It is separate from the outer deploy lock, and application subprocesses do not
inherit its descriptor. Re-provisioning preserves its inode. Nightly snapshots use this same helper action
and fresh stopped-writer proof, so direct `ctl resume` cannot restart writers during a private-file copy.

Forge uses `ctl prepare <sha> <private-env> <evidence-dir>` for the entire writable build and sealing,
then `ctl activate <sha> <evidence-dir>` for a fresh quiesce, snapshot/proof, migration/cache/doctor,
switch and healthy resume. Each invocation retains the root control lock and writer barrier throughout
its critical child. `ctl refresh <sha> <private-env>` similarly encloses the configuration refresh.
The fixed root-owned `release-step.sh` runs only as the application user with a scrubbed environment;
application children receive neither privileged lock descriptor. Root never writes application evidence paths.
Standalone quiesce/resume also retain interruption markers while their application children run;
recovery quiesce preserves any existing marker. Configuration is internal to refresh, with no standalone
public `configure` action.

Each operation creates root-only `/etc/vasey-staging/operation-in-progress` before quiesce and removes it
only after its child has completed and sealing or healthy activation succeeds. A failure or killed root
parent leaves it present, even if an application child survives. Public mutating helper actions, including
`resume`, refuse that state; only `quiesce` and `status` remain available after obtaining the control lock.
Root recovery must stop/reap every surviving build/migration/helper process and inspect/restore state
before clearing the marker. There is no application-user reset or automatic failed-operation resume.

The independent test-commerce runner shares `/etc/vasey-staging/writer.lock` and the root-owned
`writer-admission` marker. Both start with admission closed; re-provision preserves their state and the
lock inode. Every sweep checks the protected files and holds a shared lock through its artisan children,
including an orphan after runner termination. Quiesce atomically closes the marker before taking the
exclusive lock; an active sweep makes quiesce fail and leave admission closed for a later retry.
Snapshot and switch require closed admission and hold the exclusive barrier. Only resume after GET `/`
returns 200 opens admission again. The timer may remain scheduled: future invocations skip without private
state changes. Use only the reviewed oneshot runner from `current`; remove mirror/old-copy jobs first.
This does not fence arbitrary manual SQL or an unreviewed script: operators must stop those separately.

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
   The optional admin file must be a canonical absolute, root-owned0600 regular single-link
   file with no symlinks and root-owned parent directories that are not group/world writable.
   Unsafe custody refuses before packages, host changes or the first MySQL query. Do not
   place the file in the application checkout; restore it privately without printing credentials.
   - schema `vasey_staging` (`utf8mb4_unicode_ci`);
   - `vasey_app@127.0.0.1`, the runtime and migration account, with schema-scoped `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, CREATE TEMPORARY TABLES, LOCK TABLES`;
   - `vasey_backup@127.0.0.1`, the backup account, with `SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT` plus global `SHOW_ROUTINE`;
   - `SET PERSIST log_bin_trust_function_creators = ON`.
   It never rotates an existing password.
   `/root/vasey-staging-secrets` must have canonical protected ancestry. An existing app account requires
   a regular root-owned 0600 single-link `db-app.env` containing exactly the generated username/password
   format. Missing, linked, writable or incomplete custody refuses with private recovery instructions.
   A scrubbed client with login paths disabled must authenticate exactly `vasey_app@127.0.0.1` before
   grants. A private temporary client file under the secrets directory keeps its password out of argv
   and environment; completion/refusal cleans it before returning. Stale passwords require recovery.
   If the account is absent but its credential file exists, provisioning refuses rather than overwriting
   it or following a link. Do not paste credential contents into chat or the repository.
   The backup account likewise requires a finite root-owned0600 single-link literal generated
   `backup.my.cnf` profile. Before grants, a scrubbed client with login paths disabled must authenticate
   exactly `vasey_backup@127.0.0.1`. Missing, truncated, unsafe or stale credentials require private
   restore or explicit account rotation; an orphan profile is never overwritten automatically.
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
   The protected current release, when present, admits the same copy through real Laravel configuration with inherited application
   settings and foreign configuration caches excluded. Duplicate keys, DB URL/socket overrides, production
   credentials and an `APP_KEY` change from the served release refuse before quiesce. The temporary copy is
   removed on exit; a later Forge edit waits for the next deploy attempt.
2. `ctl prepare` holds one root lease while it quiesces controlled web, workers, scheduler and gated pipeline writers **before allocating any
   app-writable candidate**. It then makes a fresh checkout of the exact SHA in `releases/<SHA>` and proves
   it clean (status and tree hash). Downtime includes dependency installation and asset compilation.
3. It installs the captured private environment as `.env` mode0600 before Composer can boot Laravel package discovery or Filament upgrade. It then runs `composer install --no-dev --classmap-authoritative` from `composer.lock`, followed by `npm ci && npm run build`. The Vite manifest must exist.
4. The frozen `.env` starts as 0600. The built candidate repeats real Laravel admission before
   `vasey-staging-ctl attach` seals code and environment onto protected inodes, verifies the explicit runtime
   exceptions and bind-mounts private storage.
5. `ctl activate` obtains one root lease and repeats fresh quiesce/stopped proof immediately before snapshot. It establishes:
   - `artisan down` in that release, proven by an exact 503;
   - workers and scheduler stopped;
   - PHP-FPM stopped, and no PHP process left.
   If prepare already stopped FPM, quiesce proves that stopped state instead of requiring HTTP503
   from an absent backend. Already-stopped workers are proved rather than redundantly stopped again.
6. Still inside that invocation, snapshot takes a backup and **restore proof** before anything is migrated,
   including a first-install retry. A no-current set explicitly has no historical environment/key.
7. The fixed helper runs these steps as the application user in the new release while root retains both locks:
   - `artisan down`, then `migrate --pretend` (evidence only; see the runbook), then `migrate --force`;
   - `config:cache`, `route:cache`, `view:cache`, `event:cache` (all verified to work with this codebase);
   - `vasey:doctor`, plus redacted `commerce-readiness` and `stripe-preflight` JSON.
8. The same root invocation verifies stopped web/workers, maintenance and attached private storage, then switches `current` atomically. Its internal resume then:
   - starts PHP-FPM and proves a 503;
   - starts the workers and proves each one runs in the new release (`/proc/<pid>/cwd`);
   - runs `artisan up` and requires `GET /` to answer 200, or re-enters maintenance.
9. It prunes to the newest 3 releases, detaching storage first. **Never `rm -rf` a release by hand**: while
   attached, its `storage/app/private` *is* the persistent store.

A failure inside prepare, activate or refresh retains the operation marker and closed writer admission
before healthy resume. Between successful phases, another authorized helper can run; activate always
establishes fresh quiesce and a new verified snapshot under its own retained lease. Inspect state after
failures outside those phases or after a healthy resume. Already-running rogue processes or manual scripts
outside the controlled census must be stopped separately. This does not attest an uncompromised builder.

Deploying the served SHA again with a changed Forge `.env` refreshes configuration only:
real admission and unchanged key, then one `ctl refresh` invocation holds quiesce, snapshot and restore proof, protected capture of the private
evidence file through anchored no-follow descriptors, revalidates it against real Laravel configuration,
atomically installs root:app-group 0440 `.env`, then builds the runtime configuration cache as the app.
A cache failure leaves admission closed for recovery; a healthy `resume` reopens it. A failed quiesce or partial resume leaves writer/service state unconfirmed; inspect
`ctl status` and establish quiesce before recovery. The failure phase flag is not stopped-writer evidence.

An unchanged served SHA is a successful no-op only after `ctl healthy <sha>` proves the sealed current
release, persistent private bind, open admission, no maintenance, active FPM, all supervised workers on
that release and HTTP200. It refuses partial state and leaves recovery explicit. An already-mounted bind
still reconciles its exact fstab entry before reporting attachment success.

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
   Delimiter directives count only outside SQL strings/comments. An explicit `NO_BACKSLASH_ESCAPES` mode
   refuses normalization rather than guessing literal boundaries; retain the failed proof for review.
4. Copies the set off-host with `rsync` over SSH if `VASEY_BACKUP_DEST` is set in `/etc/vasey-staging/staging.conf`.
5. Resumes the site, including after a failed snapshot/proof/transfer, then reports any failure. Successful
   runs prune old local sets. A failed resume remains an explicit recovery condition.

`docs/ops/staging-runbook.md` covers setting the destination, retention, and restoring.

A served release must have a singly linked regular environment with one valid literal 32-byte APP_KEY
before any snapshot is published. The backup profile requires one assignment per line; multiline quoted
values are refused so apparent key lines inside another value cannot establish custody. Restore admission
requires exactly the recorded checksum for `env.backup`. Invalid/dangling/outside `current` never becomes a first install.
Restore reproof invalidates old success first, requires an unambiguous release manifest, and requires
both `env.backup` and its hash for a served release. Only an explicit `release_sha=none` bootstrap set
may omit the key; such a set cannot claim recovery of previously encrypted columns.
