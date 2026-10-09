# Staging runbook: private test-mode staging on Forge

Prepared October 8, 2026 for the Monday staging plan (Lane A: items M-01, M-02 and M-13). Use it with the
provisioning kit in [`ops/staging/`](../../ops/staging/README.md).

> **Boundary.** This is a private **test-mode** rehearsal host:
> - `APP_ENV=local` and `APP_DEBUG=false`;
> - Stripe test keys only;
> - outbound mail written to the log;
> - basic auth in front of everything except `POST /webhooks/stripe`.
>
> It is not production and grants no live-payment, DNS-cutover, import or launch authorization. Every command
> here runs on the staging host only. Record evidence as `docs/ops/production-activation-packet.md` §7 says:
> command, exit code, UTC time, SHA, operator, and never a secret value.

Conventions:
- `<HOST>` is the staging hostname.
- `art` is shorthand for running Artisan in the served release as the application user:

```sh
alias art='sudo -u forge php8.4 /srv/vasey-staging/current/artisan'
```

## 1. First deploy

Do steps 1 to 10 of [`ops/staging/README.md`](../../ops/staging/README.md#provisioning-step-by-step) in order.
Then check the result:

```sh
sudo /usr/local/sbin/vasey-staging-ctl status         # current=<SHA>, php-fpm active, 5 programs RUNNING, GET / 200
ls /srv/vasey-staging/evidence/                       # one directory per deploy
curl -s -o /dev/null -w '%{http_code}\n' https://<HOST>/                        # 401 without credentials
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://<HOST>/webhooks/stripe # not 401: the app answers (webhook off: 4xx/503 JSON)
curl -s -u '<user>:<password>' https://<HOST>/robots.txt                        # "User-agent: *" / "Disallow: /"
```

On a first install the deploy accepts exactly one failing doctor check, `operator`, because the schema has to
exist before an operator can be created. Continue with section 2.

## 2. Staff accounts and MFA

License approval and free-definition review refuse a reviewer who authored the item
(`app/Domain/Rights/ReviewLicense.php`, `app/Domain/Grants/Free/FreeGrantDefinitions.php`), so staging needs
**two** operators: Sean and an independent reviewer. The command is interactive by design. It refuses to run
without a terminal and never accepts credentials as options:

```sh
ssh forge@<HOST>
cd /srv/vasey-staging/current
php8.4 artisan vasey:create-admin      # asks: Operator name, Email, Password (16+ characters, letters and numbers)
php8.4 artisan vasey:create-admin      # second operator
```

Each account is created with `is_admin` and a console-attested verified email, and an
`access.operator.created` audit row is written.

**MFA enrollment (both operators, now).** Under `APP_ENV=local` the panel does not *require* TOTP: it is
required only in production (`AdminPanelProvider`, `isRequired: fn () => app()->isProduction()`). Staging is
publicly reachable behind basic auth, so enroll anyway:

1. Sign in at `https://<HOST>/admin`. The browser asks for the staging basic-auth credentials first.
2. Open the user menu, choose **Profile**, then set up the authenticator app.
3. Scan the QR code, confirm with a current code, and store the recovery codes offline.
4. Sign out and back in to confirm the code is asked for.

Rerun `art vasey:doctor`; the `operator` check now passes.

## 3. Seller tag

Without the approved tag no `preview_tagged` asset is produced and nothing can be published
(`app/Domain/Media/MediaProcessor.php`: `tag_not_configured`). The settings are `MEDIA_TAG_PATH`, relative to
the private disk, and `MEDIA_TAG_SHA256` (`config/media.php` `tag_path` / `tag_sha256`).

The file must be:
- a supported PCM WAV;
- at most 15 seconds and 16 MiB;
- audible above −50 dBFS;
- a regular file, not a symlink.

See `docs/media-processing.md`.

```sh
# From the operator machine:
scp approved-seller-tag.wav forge@<HOST>:/srv/vasey-staging/tmp/
# On the host, as forge:
install -m 0600 /srv/vasey-staging/tmp/approved-seller-tag.wav /srv/vasey-staging/private/branding/approved-seller-tag.wav
rm /srv/vasey-staging/tmp/approved-seller-tag.wav
sha256sum /srv/vasey-staging/private/branding/approved-seller-tag.wav
```

In Forge → Environment set:

```
MEDIA_TAG_PATH=branding/approved-seller-tag.wav
MEDIA_TAG_SHA256=<the 64 lowercase hex characters>
```

Then click **Deploy Now**. When the served SHA is unchanged and only `.env` differs, the deploy refreshes the
configuration. It quiesces, installs `.env`, runs `config:cache` and resumes, so the workers pick up the tag.
Then process one real master, listen to the tagged preview and confirm the mix with Sean before publishing
anything.

## 4. `vasey:doctor` until it passes

Run it as the user that owns private storage. Its scanner-limits check creates a 4 GiB sparse file in
`private/processing/` and removes it, and does so only for the owner:

```sh
art vasey:doctor            # human-readable
art vasey:doctor --json     # redacted; keep it as evidence
```

`foundation_ready: true` requires every *required* check to pass. Expected states on staging:

| Check | Expected | If not |
| --- | --- | --- |
| `php_runtime` | pass | Re-run `provision.sh`; it lists the missing extension. FPM and CLI are both checked. |
| `application_key` | pass | `APP_KEY` in Forge → Environment, then Deploy Now. |
| `database`, `migrations` | pass | `DB_HOST=127.0.0.1`, `DB_USERNAME=vasey_app`, password from `/root/vasey-staging-secrets/db-app.env`. Redeploy so migrations run. |
| `operator` | pass after section 2 | `vasey:create-admin`. |
| `runtime_directories`, `frontend_build` | pass | The release was not built completely: redeploy. |
| `private_storage` | pass | `stat -c '%U %a' /srv/vasey-staging/private` must print `forge 700`; `findmnt /srv/vasey-staging/current/storage/app/private` must show the bind mount. |
| `production_settings` | pass (not production) | |
| `media_tools`, `media_encoders` | pass | `provision.sh` checks the same encoders. |
| `media_scanner` | pass | `clamscan` installed. |
| `media_scanner_limits` | pass | Run as `forge`. The private filesystem must keep sparse files (ext4 does). |
| `seller_tag` | pass after section 3 | |
| `media_queue` | pass | `QUEUE_CONNECTION=database`. |
| `mail_transport` | **warn (intended)** | `MAIL_MAILER=log`: staging sends no email. |
| `scheduled_publication`, `site_images` | pass | Scheduler program running (`vasey-staging-ctl status`). |

Keep ClamAV signatures under 48 hours old. `freshclam` checks hourly (`systemctl status clamav-freshclam`).
A media run reports `scanner_stale` if they age out.

## 5. Media proof (one track)

Before entering catalog content:

1. Upload one real WAV master, its artwork and its stems ZIP. Each is at most 200 MiB through the whole-file
   upload; the resumable upload uses 8 MiB chunks.
2. Watch `tail -f /var/log/vasey-staging/worker-media.log`.
3. Confirm the tagged preview, the waveform and the stems association in the admin.
   - `clamscan` reloads its whole signature database for every scan: expect 15–25 s per scan.
   - A stems ZIP is scanned once as uploaded, once per member and once rebuilt.

If a job fails, open **Processing details**. `docs/media-processing.md` maps each failure code to its remedy.

## 6. Test purchase: manual post-purchase console steps

Lane B owns the test-commerce environment (`ops/staging/test-commerce/`) and the Stripe test webhook. With
those flags on, the automatic part of the pipeline is:

1. `POST /webhooks/stripe` stores a receipt and queues `ProcessStripeReceiptJob` on `payments`.
2. Verification queues `FinalizeTestPaymentJob` on `payments`.
3. Finalization queues `IssueTestContractJob` on `contracts`.

The bounded runner also reconciles missed observations, finalizes, issues contracts and activates ready
orders. Use the reviewed current-based timer/scheduler from [the purchase walkthrough](staging-test-purchase.md#4-start-the-pipeline-runner).
It participates in the root-owned writer gate, so quiesce blocks future sweeps until successful resume.
The commands below are manual inspection/recovery alternatives; per-order delivery enablement remains
manual. Run them as `forge` in the served release. `ORDER` is the opaque order UUID shown on the order page
and in the admin lists. Stop any manual writer before snapshot/migration; do not use a mirror or old runner.

```sh
# Inspect what arrived (no provider I/O):
art vasey:stripe-inbox --limit=20

# Recovery only, when a webhook or job was missed. Each is bounded; follow --after cursors until empty,
# never assume one page drained a backlog:
art vasey:process-stripe-receipts --limit=25
art vasey:reconcile-test-checkout --limit=25
art vasey:reconcile-test-payments --limit=25
art vasey:finalize-test-payments --limit=25
art vasey:issue-test-contracts --limit=25

# Always manual: record the complete fulfillment proof (does not authorize downloads by itself).
art vasey:activate-test-fulfillment ORDER

# Always manual: enable delivery for that order. --expected-version is the control's current version (0 the
# first time); --reference is 1-192 characters of [A-Za-z0-9._:/-], and only its digest is audited.
art vasey:control-test-delivery ORDER enable --expected-version=0 --reference=staging-rehearsal-001
```

The buyer then downloads **in the same browser session** that placed the order, or in a test customer
account:
- `GET /orders/ORDER/delivery`;
- `POST .../delivery/authorizations` (a token valid 60 s, at most 3 per minute);
- `POST .../delivery/download` (single attempt).

`SESSION_LIFETIME=480` gives a guest 8 hours. To block delivery again:
`art vasey:control-test-delivery ORDER block --expected-version=<current> --reference=...`.

Staff can see read-only state in the admin under **Test contract issuance** and **Test payment exceptions**.

## 7. Known blocker: free-grant documents under PHP-FPM

**Finding (verified).** Free-grant PDF rendering runs inside the web request
(`POST /free-grants/origins/{origin}/document`, `FreeGrantController.php:89`). It starts
`[PHP_BINARY, '-n', ..., scripts/render-free-grant.php]` (`app/Domain/Grants/Free/FreeGrantRendererProcess.php:81`).

Under PHP-FPM, `PHP_BINARY` is the FPM binary. On a scratch PHP 8.4.26 FPM pool behind nginx, a request
reported:
- `PHP_SAPI=fpm-fcgi`;
- `PHP_BINARY=/usr/sbin/php-fpm8.4`.

The same `[PHP_BINARY, '-n', '-d', ..., script]` spawn exited 64 and printed php-fpm's usage text instead of
running the script. The renderer therefore fails closed with `render_failed`. With `/usr/bin/php8.4` in place
of `PHP_BINARY`, the same spawn from the same FPM request ran the child under `cli` (exit 0).

The same pattern is in:
- `app/Domain/Grants/ProductionFree/ProductionFreeGrantRendererProcess.php:61,81` (unmounted);
- `app/Domain/Grants/Paid/PaidGrantRendererProcess.php:60,80` on the Paid252 branch. Its document route
  renders inside the HTTP request, so the same failure would hit paid delivery once mounted.

`IsolatedContractRenderer` (test contracts) is unaffected because it runs in the `contracts` queue worker,
which is the CLI.

**Required fix (product code, needs independent review):**
1. Resolve the child binary through one helper instead of `PHP_BINARY`:
   - under `PHP_SAPI === 'cli'`, keep `PHP_BINARY` (workers and tests are unchanged);
   - otherwise use an explicit configured absolute CLI path, for example `config('contracts.php_cli_binary')`
     from a new `VASEY_PHP_CLI_BINARY`, set to `/usr/bin/php8.4` on staging.
2. Validate it before use: absolute path, `realpath` equal to the configured path, a regular executable file,
   and a one-time `-n -r 'echo PHP_VERSION;'` probe that matches the parent's `PHP_VERSION`.
   - A mismatch or a missing value fails closed with `render_failed`.
   - Do not use Symfony's `PhpExecutableFinder`: it returns `/usr/bin/php`, an `update-alternatives` link that
     can point at another PHP version.
3. Derive the `LD_LIBRARY_PATH` sibling directory from the resolved CLI path, not `PHP_BINARY`.
4. Use the helper in `FreeGrantRendererProcess`, `ProductionFreeGrantRendererProcess`, Paid252's
   `PaidGrantRendererProcess` and, for consistency, `IsolatedContractRenderer`.
5. Add regression tests:
   - a resolver unit test with an injected SAPI and binary (FPM without a configured path refuses; FPM with a
     valid path returns it; a version mismatch refuses);
   - an ops check that renders one synthetic document through the real FPM pool before free grants are
     enabled on a host.

Until that lands, keep `VASEY_TEST_FREE_GRANTS_ENABLED=false` on staging and leave free downloads out of
Monday.

## 8. Backups and the restore proof

What runs:
- **Nightly, 03:17 UTC** (`/etc/cron.d/vasey-staging-backup`).
- **Before every deploy that replaces a served release** (`vasey-staging-ctl snapshot`).

Each run produces `/srv/vasey-staging/backups/<UTC>-<sha12>/` containing:
- `database.sql` and `.sha256`;
- `private.tar` and `.sha256`;
- `private.sha256` and `private.dirs` (manifests);
- `env.backup` (APP_KEY) and `.sha256`;
- `MANIFEST`;
- `RESTORE_CHECK`, the proof: a disposable `mysqld` loaded the dump and re-dumped it, and the archive was
  restored as `forge` and verified.

The log is `/var/log/vasey-staging/backup.log`. A set without `RESTORE_CHECK` is unproven. A `*.FAILED`
directory is kept for inspection and never pruned.

**Off-host destination (Sean).** The step is optional, but until it is done the backups sit on the same disk
as the data.

```sh
sudo ssh-keygen -t ed25519 -N '' -f /root/.ssh/vasey_staging_backup      # add the .pub to the destination account
sudo ssh -i /root/.ssh/vasey_staging_backup <user>@<backup-host> true      # accept and pin the host key once
sudoedit /etc/vasey-staging/staging.conf                                   # VASEY_BACKUP_DEST=<user>@<backup-host>:<path>
sudo /usr/local/sbin/vasey-staging-backup nightly                          # one supervised run; expect "shipped"
```

The destination receives the dump, the private files and the `.env`, which holds `APP_KEY` and the test
secrets. Use storage that Sean controls and that is encrypted at rest. Retention there is Sean's decision;
the host keeps 7 sets.

**Re-prove any set:** `sudo /usr/local/sbin/vasey-staging-backup restore-check /srv/vasey-staging/backups/<set>`.

Two observations from the proof run on MySQL 8.4.11:
- **Collation rendering.** A first-generation dump and its re-dump differ only in collation rendering: 328
  columns print `CHARACTER SET utf8mb4 COLLATE …` after a reload instead of `COLLATE …`. The proof accepts
  only the immediate charset attribute of string columns inside matching dump table definitions and reports
  `database_redump=identical_except_column_charset_rendering`. Every other byte, including rows, enum values,
  defaults, comments and routines, must match. The parser and checked commands are in
  `docs/ops/backup-restore-proof.md` step 4; global text substitution is unsafe.

A reproof invalidates the earlier `RESTORE_CHECK` first. A new `RESTORE_VERIFIED` marker appears only after
all checks and disposable-server cleanup succeed. A failed reproof cannot retain an earlier success claim.

## 9. Rollback

If `/etc/vasey-staging/operation-in-progress` exists, a prepare/activate/refresh operation is active or
interrupted. Helper actions serialize on the root control lock; after an interruption, mutating actions
refuse the marker. As root, inspect the process tree and stop/reap all surviving fixed release helpers,
Composer/npm, Artisan migrations/caches/doctor and their descendants. Establish quiesce, inspect the
snapshot and migration evidence, and choose the recovery below before removing the root-only marker.
Never clear it while a child might still write. Marker removal is an explicit root recovery action;
the application account cannot perform it and no script auto-clears a failed operation. A healthy resume
followed by a later marker-cleanup/evidence failure needs inspection, rather than an assumed closed gate.

Decide which case applies by comparing `migrate-status-before.txt` and `migrate-status-after.txt` in the
failed deploy's evidence directory.

**A. The deploy failed before migration and before replacing the served configuration.** Confirm `current`
still points at the previous release. New-release deployments quiesce before allocating/building a candidate,
so Composer/npm failure can leave the old site in maintenance with writer admission closed. Inspect service
state before recovery; downtime includes the build. Stop any uncontrolled manual/rogue app-account process
separately; controlled service quiescence is not a builder attestation. Confirm the old
environment is unchanged. A failed quiesce or partial resume
does not prove stopped writers. Inspect `ctl status` and establish quiesce before resuming. If any migration
ran, use B; if same-SHA configuration replacement began, use D.

An admitted test-commerce sweep can make quiesce refuse before stopping services. Its durable admission
marker stays closed, so new scheduled sweeps skip. Wait for the existing sweep to finish, then retry
quiesce and follow the applicable recovery below. Never edit the marker to bypass a failed operation.

Retrying a served SHA with unchanged environment requires the root `healthy <sha>` check. Maintenance,
closed admission, wrong worker path or failed HTTP200 refuses; inspect and recover rather than treating
unchanged files as success. Every migration attempt, including no-current first-install retries, takes
a snapshot/proof first. First-install sets do not contain a historical encryption key. Reattachment also
repairs a missing exact fstab bind entry before reporting success.

```sh
cd "$(readlink -f /srv/vasey-staging/current)"
sudo -n /usr/local/sbin/vasey-staging-ctl status
sudo -n /usr/local/sbin/vasey-staging-ctl quiesce
sudo -n /usr/local/sbin/vasey-staging-ctl resume       # starts PHP-FPM and workers on it, proves 503, then up, proves 200
```

**B. Migrations ran (or partly ran), or the new release misbehaves after the switch.** Restore the pre-deploy
backup, which is the newest set taken before the deploy. Re-prove it successfully now; its new
`RESTORE_CHECK` must say `RESTORE_VERIFIED` and its `env.backup` hash must match. Then
return to the previous release. This follows `docs/ops/production-activation-packet.md` §4 "Rollback".

Run this as root in a `sudo bash -euo pipefail` shell, so that any failed step stops the sequence. Use the MySQL admin account Forge created (root over the local socket, or the
database password Forge showed at server creation).

```sh
PREV=<40-hex SHA of the previous release>      # ls -t /srv/vasey-staging/releases
SET=/srv/vasey-staging/backups/<pre-deploy set>
APP() { runuser -u forge -- env -i PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C "$@"; }
# 1. Writers stopped, maintenance proven (503), PHP-FPM stopped:
/usr/local/sbin/vasey-staging-ctl quiesce
/usr/local/sbin/vasey-staging-backup restore-check "$SET"
grep -qx 'result=RESTORE_VERIFIED' "$SET/RESTORE_CHECK" || exit 1
(cd "$SET" && sha256sum --check --quiet --strict env.backup.sha256) || exit 1
# 2. Database: drop and recreate the staging schema (a plain load would leave tables the failed migration made),
#    then load the verified dump.
(cd "$SET" && sha256sum --check --quiet --strict database.sql.sha256 private.tar.sha256) || exit 1
mysql -uroot -e 'DROP DATABASE vasey_staging; CREATE DATABASE vasey_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -uroot < "$SET/database.sql" || exit 1
# 3. Private files: keep the directory object (every release bind-mounts that inode); move its entries aside, then
#    extract the verified archive into it as forge and check it against the manifest.
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 0700 -o forge -g forge "/srv/vasey-staging/private.pre-rollback-$STAMP"
APP bash -c 'shopt -s dotglob nullglob; for e in /srv/vasey-staging/private/*; do mv -- "$e" "$1/"; done' _ "/srv/vasey-staging/private.pre-rollback-$STAMP"
APP tar --extract --file=- --directory=/srv/vasey-staging/private --no-same-owner --preserve-permissions < "$SET/private.tar" || exit 1
(cd /srv/vasey-staging/private && sha256sum --check --strict --quiet "$SET/private.sha256") || exit 1
# 4. Recheck/seal its attachment. As root, restore the hash-verified key/env on a fresh protected inode.
/usr/local/sbin/vasey-staging-ctl attach "$PREV"
ENV_RESTORE=$(mktemp "/srv/vasey-staging/releases/$PREV/.env-restore.XXXXXXXX")
install -m 0440 -o root -g forge -- "$SET/env.backup" "$ENV_RESTORE"
mv -T -- "$ENV_RESTORE" "/srv/vasey-staging/releases/$PREV/.env"
cmp -s "$SET/env.backup" "/srv/vasey-staging/releases/$PREV/.env" || exit 1
# 5. Maintenance in the previous release BEFORE pointing current at it, then switch and resume:
APP php8.4 "/srv/vasey-staging/releases/$PREV/artisan" down
APP php8.4 "/srv/vasey-staging/releases/$PREV/artisan" config:cache
/usr/local/sbin/vasey-staging-ctl switch "$PREV"
/usr/local/sbin/vasey-staging-ctl resume
```

Then:
- Keep `private.pre-rollback-*`; it is never deleted automatically and never served.
- Record the incident and the evidence.
- Fix forward with a new SHA.

Never check another SHA out inside an existing release directory: it would keep that release's
`public/build` and serve the wrong Vite manifest.

**D. Same-SHA environment refresh failed after replacement.** Its pre-change snapshot includes the served
environment and key. As root in a `sudo bash -euo pipefail` shell, select that exact pre-refresh set and current
SHA; establish quiesce, re-prove the set and verify `env.backup.sha256` as in B. Use B's root-owned fresh-inode environment
restore, compare, `artisan down` and `config:cache` commands, then `ctl resume`; `current` stays on the same SHA.
Inspect application behavior before deciding whether a database/private-file restore is also needed. Do not
generate a new `APP_KEY` or overwrite the saved environment to make a failed refresh appear successful.

**C. Remove staging entirely** (only when Sean asks):
1. Stop the services with `vasey-staging-ctl quiesce`.
2. Delete the staging DNS record.
3. Disable the Stripe test webhook endpoint.
4. Roll the Stripe test key.

## 10. Troubleshooting

| Symptom | Check |
| --- | --- |
| 502 from nginx | `systemctl status php8.4-fpm`; sockets `/run/php/vasey-staging.sock`, `/run/php/vasey-paid-delivery.sock`; the site may be quiesced (`vasey-staging-ctl status`). |
| 503 everywhere | The site is in maintenance: a deploy or backup stopped part-way. Read the end of its output or `/var/log/vasey-staging/backup.log`, fix the cause, then `vasey-staging-ctl resume`. |
| 413 on upload | Whole-file uploads go through `/livewire-<hash>/upload-file` (210 MiB). Larger files are refused by the application too (200 MiB cap). |
| Upload larger than 200 MiB needed | Product decision B4 in the Monday audit (code and review), not a server setting. |
| Let's Encrypt renewal fails | The `include forge-conf/<site>/before/*;` line must stay in the site config (Forge's challenge handling). |
| `disposable mysqld --initialize failed` in a backup | AppArmor confinement: `provision.sh` adds `/var/lib/mysql-restore-check/` to `/etc/apparmor.d/local/usr.sbin.mysqld` when that profile exists. Check `dmesg \| grep DENIED`. |
| `restored tree fail (... outside 0600/0400 ...)` | A private file or directory has a wider mode than the backup proof allows. Inspect with the printed `find`. Set `VASEY_RESTORE_STRICT_MODES=0` in `staging.conf` only temporarily, with a recorded reason. |
| Workers not on the new release | `vasey-staging-ctl resume` proves `/proc/<pid>/cwd`; a failure there keeps the site in maintenance by design. |

Release source/vendor/build files are root-owned read-only after attachment; `.env` is root:app-group
0440. Ordinary configuration refresh uses one `ctl refresh <sha> <private evidence file>` operation.
Direct `ctl configure` is an inspected recovery primitive; it does not replace the enclosing refresh lease.
Do not restore app write permission on code or `.env`. Runtime-generated PHP under `bootstrap/cache`
and `storage/framework` remains an explicit application trust exception, as described in the kit README.
Backups refuse missing/invalid served environments and invalid `current`; recovery needs the verified
manifest, `env.backup` and its hash. A first-install `release_sha=none` set has no historical encryption key.
