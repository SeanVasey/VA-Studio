# Staging runbook: private test-mode staging on Forge

Prepared October 8, 2026 for the Monday staging plan (Lane A: items M-01, M-02 and M-13). Use it with the
provisioning kit in [`ops/staging/`](../../ops/staging/README.md).

> **Boundary.** This is a private **test-mode** rehearsal host:
> - `APP_ENV=staging` and `APP_DEBUG=false` (the interim `APP_ENV=local` profile is still accepted, without required MFA);
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

**MFA enrollment (both operators, now).** Under `APP_ENV=staging` the panel requires TOTP before any protected
page, as in production (`AdminPanelProvider`, `TestEnvironment::requiresStaffMfa()`). Under the interim
`APP_ENV=local` profile it is optional, so enroll anyway:

1. Sign in at `https://<HOST>/admin`. The browser asks for the staging basic-auth credentials first.
2. Open the user menu, choose **Profile**, then set up the authenticator app.
3. Scan the QR code, confirm with a current code, and store the recovery codes offline.
4. Sign out and back in to confirm the code is asked for.

**After a switch from `APP_ENV=local` to `staging`**, confirm the panel pages carry the MFA middleware. The panel
compiles it into the route cache from `APP_ENV`; a deploy or `ctl refresh` rebuilds that cache, but a route cache
built under `local` would serve staging without page-level MFA:

```sh
art route:list --path=admin/tracks --json -v | grep -c EnsureMultiFactorAuthenticationIsEnabled   # expect 1 or more
```

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
configuration. It quiesces, installs `.env`, runs `config:cache` and `route:cache` and resumes, so the workers pick up the tag.
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

## 5a. Synthetic rehearsal catalog (optional, before real tracks)

**Blocked until the S-6 seller tag is installed (section 3), or until Sean decides about a synthetic tag.** Without
a configured tag, the command refuses before writing anything.

`vasey:staging-synthetic-catalog` creates six labelled synthetic tracks, so testers can rehearse the whole journey
(catalog, player, quote, Stripe test checkout, verified payment, contract PDF, download) before Sean's real tracks
(S-7) arrive. Sean approved it on 2026-10-17. Nothing it creates is product data:

- Track titles start with `[SYNTHETIC]` and the artist is "Synthetic Test Content".
- Every description, license name and license text says "Synthetic rehearsal content — not for sale; staging test only".
- There are two non-exclusive licenses, each offered on every track:
  - "[SYNTHETIC] TEST PRICE MP3 license" delivers `download_mp3` at 1.11 USD (stored as 111 cents);
  - "[SYNTHETIC] TEST PRICE WAV license" delivers `download_mp3` and `master_wav` at 2.22 USD (222 cents).
- The marker `synthetic-rehearsal` is the slug prefix of the tracks and license templates, a track tag, and (as
  `SYNTHETIC-REHEARSAL-NN:`) the prefix of each rights declaration's provenance. Offers, revisions and media inherit
  it through their track or license.

The command generates the audio (30–60 s tones, chords and noise, as WAV masters) with ffmpeg and the artwork (plain
colours with "SYNTHETIC TEST", no brand mark) with PHP GD, on the host, at run time. The files enter through the
ordinary intake service and the ordinary media pipeline. Nothing generated is committed. Every step uses the same
domain service as the admin, under the same staff authority, MFA, rights, media and readiness checks.

**Admission.** The command checks all of this before it writes anything. A failure prints `Refused: …` and exits 1:

1. `APP_ENV` is `local`, `testing` or `staging`. The command refuses when the configured environment (`APP_ENV`,
   including a cached configuration) or the running environment is `production`, even with the flag on. It also
   refuses when the two differ, for example through `artisan --env=`.
2. `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true`. The default is false. The key is not yet in
   `ops/staging/env.staging.example`; add it to the host's `.env` by hand.
3. Stripe is in test mode: `STRIPE_MODE=test`, an `sk_test_` key or none, and no live production-checkout setting.
4. The catalog is empty or synthetic-only. A track that is or was published, or an approved license, outside the
   synthetic definition refuses the run. This is a configuration and catalog check, not proof of host identity.
5. Two existing staff accounts named for this purpose:
   - `--operator` is the author, rights verifier and publisher;
   - `--reviewer` approves the licenses, because license approval refuses the author.

   Both names must start with `Synthetic `, so synthetic writes are never attributed to a real operator. Both need
   catalog authority and, on staging, an enrolled authenticator: the same panel rule every staff write checks.
   A write run then asks for both passwords at hidden prompts, in an interactive console. On staging it also needs
   `--attest`: it records one `staff.console_attestation` audit row per account before the first write.
6. The approved seller tag is configured and matches its SHA-256 (section 3). Without it the pipeline cannot make a
   tagged preview (`tag_not_configured`). The command never generates, replaces or overrides a tag.
7. ffmpeg, ffprobe, prlimit and PHP GD are available.

**Run.**

```sh
art vasey:create-admin   # name: Synthetic Catalog Operator; an email address you control
art vasey:create-admin   # name: Synthetic Catalog Reviewer
# Sign in at /admin as each account and enroll the authenticator app (section 2).
# Forge → Environment: VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true, then Deploy Now (configuration refresh).
art vasey:staging-synthetic-catalog --operator=OPERATOR_EMAIL --reviewer=REVIEWER_EMAIL --check            # admission only, no prompts
art vasey:staging-synthetic-catalog --operator=OPERATOR_EMAIL --reviewer=REVIEWER_EMAIL --attest           # asks both passwords
```

Expect several minutes. ClamAV scans each master, the tag and each artwork, at 15–25 s per scan (section 5). The
command processes each media run in its own process with the service the media worker uses. A claim token makes
the two exclusive: whichever claims a run first processes it, and the command waits for the result. It prints one
line per license and per track, then a table of offer revisions and prices. Exit 0 means every synthetic track is
published and passes publication readiness. Then set the flag back to `false` in Forge → Environment and click
Deploy Now, which refreshes the cached configuration as in section 3, so the running value is off too.

The command is a manual writer. Do not run it during a deploy, a backup or a quiesce. A cache lock refuses a second
concurrent run. If a run is killed, its lock expires after an hour.

**Verify.**

- The storefront lists six `[SYNTHETIC]` tracks. Each plays its tagged preview and shows the two TEST PRICE licenses.
- `art vasey:rights-scope list` shows the twelve new revisions. "Prepare order" needs each revision you buy linked to
  a rights scope first (purchase walkthrough, setup step 5). Use one scope per synthetic track, with a key starting
  `synthetic-rehearsal-`. The command does not link scopes. Each link is its own `vasey:rights-scope` write, with
  its own password confirmation and single-use attestation, and an operator decides which scope each revision
  belongs to.
- Then follow [the purchase walkthrough](staging-test-purchase.md). Run its drills on one synthetic track and buy
  from another, because a drill leaves its offer revision reserved.

**Re-running.**

- A synthetic track or license that already exists is reported `already present`. Nothing is duplicated.
- An interrupted run resumes the unfinished draft (`resumed and published`). After a failed media step, fix the
  cause and run the command again.
- A row that carries the marker but differs from its definition is refused and left unchanged. A published
  synthetic track whose metadata or offers staff changed is reported `present, metadata differs …` or
  `present, offers differ …` (exit 1). Nothing is reverted.
- A track withdrawn with the ordinary unpublish action is reported `withdrawn by staff; left unpublished`. The
  command never republishes it, and exits 1.
- The command never creates quotes, orders, payments, grants or contracts.

**Removing it before real content.** There is no purge command: published licenses, offer revisions, order
snapshots and contracts are immutable evidence. To hide the catalog, unpublish the synthetic tracks with the
ordinary staff action. Before Sean's real tracks are loaded, reset staging to an empty database and fresh private
storage, with Sean's go-ahead:

1. Keep a backup set if the rehearsal evidence matters (section 8).
2. Quiesce. As root, drop and recreate the `vasey_staging` schema as in section 9 B step 2, without loading a dump,
   and move the private storage entries aside as in its step 3, without extracting an archive.
3. Run the migrations on the empty schema: `art migrate --force`.
4. Reinstall the approved S-6 seller tag (section 3); it lived in the private storage you moved aside. If a
   synthetic tag was ever configured, remove it, and point `MEDIA_TAG_PATH`/`MEDIA_TAG_SHA256` at S-6 before any
   real master is processed.
5. Set `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=false`, resume, recreate the staff accounts and their MFA
   (section 2), and rerun `art vasey:doctor`.

Stripe keeps its own test-mode records of the rehearsal; they are harmless and stay in test mode.

**Limits.**

- This is rehearsal data only. It is not catalog content, a real-media acceptance, or a MySQL concurrency result.
- The S-6 seller tag is required. Until it arrives the command refuses. A synthetic tag would have to be configured
  host-wide and would tag every master processed while it is set, and `config/media.php` states that no synthetic
  seller tag exists outside the test suite. Using one is Sean's decision, not this command's.
- A console has no MFA challenge. The command confirms both passwords, records the staging attestations, and every
  domain service checks each account's current enrollment. One shell still drives both accounts, so the two-person
  rule for license approval holds between accounts, not between people. Shell access plus the two passwords remains
  the controlling credential.
- It has not yet been run on the hosted staging server.

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

## 6a. Test customer sign-in links

A tester who creates or recovers a test customer account at `/account/create` or `/account/recover` gets no email.
The test identity transport refuses SMTP and the log mailer and writes each message privately on the host
([test customer enrollment and recovery](../customer-test-self-service.md)). Relay the link with the read-only
`vasey:test-identity-links` command. Do not open the capture files by hand.

Before you start:

1. The test-commerce profile enables test identity: `VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED=true`,
   `VASEY_TEST_CUSTOMER_IDENTITY_ENABLED=true` and `VASEY_TEST_CUSTOMER_IDENTITY_TRANSPORT=private_capture`
   (`ops/staging/test-commerce/env.test-commerce.example`). Without all three the command refuses.
2. You relay as a staff account from section 2, with the authenticator app enrolled.
3. Testers sign up with a reserved test address: one ending in `.test` (for example `ana@example.test`), `.example`,
   `.invalid` or `.localhost`, or one at `example.com`, `example.net` or `example.org`. No mail is sent, so they
   need no real mailbox. The command never lists or prints a link for any other address, so it can never relay a
   link for a real person's address. A test account created earlier at any other address keeps signing in with its
   password, but cannot get links this way: if the password is lost, the tester signs up again at a reserved address, and test purchases already claimed by the old account stay with it.
   This reserved-address rule is new with the relay and is listed for Sean's confirmation in the evidence packet.

```sh
art vasey:test-identity-links list --operator=OPERATOR_EMAIL --attest
art vasey:test-identity-links print MESSAGE_ID --operator=OPERATOR_EMAIL --attest
```

- Both ask for the staff password at a hidden prompt. Staging requires `--attest`, which records one
  `staff.console_attestation` audit row before anything is read.
- `list` shows each message's id, recipient, kind (`create account` or `reset password`), creation and expiry
  times in UTC, and status (`pending`, `expired` or `used`), newest first. It only counts the captures it withholds:
  those for other addresses, and any that fail verification (changed, linked or misnamed files).
- `print` prints the link of one pending message on its own line. It refuses expired and used messages. A reserved
  address proves nothing about who typed it, so send a `create account` link only to the tester you are onboarding,
  and a `reset password` link only to the person you know owns that test account, never in reply to an unsolicited
  request. Use a private channel. The link works once, only on this host, and expires ten minutes after the request.
- Nothing is changed: the link is not used up, extended or re-signed. Each `list` and `print` adds one audit row
  (`customer.test_identity.links_listed` or `customer.test_identity.link_relayed`). The row names the operator and the
  challenge, never the link or the address.
- A link that fails for the tester: run `list` again. An expired or used link needs a new request from the tester.
  Each address allows four requests an hour. A pending link can still be refused at completion if another link for
  the same address completed first, or if the account changed.
- It has not yet been run on the hosted staging server.

## 7. Renderer children under PHP-FPM (M-16)

**Why.** Contract and grant PDFs render in a bounded child process. Free, production-free and paid grant documents
render inside the web request (for example `POST /free-grants/origins/{origin}/document`). Under PHP-FPM, `PHP_BINARY`
is the FPM daemon: on PHP 8.4.26 a `[PHP_BINARY, '-n', ...]` spawn exits 64 with php-fpm's usage text, so every such
render failed closed with `render_failed` (`docs/verification/php-cli-resolver-20261009/`).

**What runs now.**
- In the CLI (queue workers, Artisan, tests) every renderer still spawns its own `PHP_BINARY`, unchanged.
- Outside the CLI, children run the binary named by `VASEY_PHP_CLI_BINARY` (`config('app.php_cli_binary')`), after
  `App\Support\PhpCliBinary` proves it: an absolute path whose canonical target is a regular executable file, and a
  scrubbed-environment `-n` probe (10 s, at most 128 bytes, no stderr) that reports the `cli` SAPI, exactly the
  running `PHP_VERSION` and the same thread-safety and debug build. Success is cached per file identity; a replaced
  binary is probed again. Unset, invalid or mismatched fails closed with `render_failed`; the path is never shown.
- The pinned renderers (`FreeGrantRendererProcess`, `ProductionFreeGrantRendererProcess`, `PaidGrantRendererProcess`)
  are frozen by their contract profiles, so they are not edited. The container gives the free and paid renderers,
  outside the CLI only, a process factory (`App\Support\PhpCliProcess`). Family 256 (production free grants) ships
  unbound by design; the step that composes and mounts it must add the same binding. The factory replaces just the
  executable and re-derives the fixed sibling library directory from the CLI binary. Flags, script, working directory, scrubbed environment, input and the 60 s
  limit are unchanged, and each renderer still verifies its pinned profile and bounds its output.
- Do not point `VASEY_PHP_CLI_BINARY` at `/usr/bin/php`: it is an `update-alternatives` link that can move to another
  version. A move is caught by the probe, but then rendering stops until the setting is fixed.

**Host checks.**
1. `ops/staging/validate-runtime.php` (run by every deploy and `ctl refresh`) fails `runtime.php_cli_binary` unless the
   configured binary passes the same probe the FPM pool will run.
2. Before enabling free grants (`VASEY_TEST_FREE_GRANTS_ENABLED=true`), render one synthetic document through the real
   FPM pool and confirm a PDF, not `render_failed`. The harness used for the evidence is in
   `docs/verification/php-cli-resolver-20261009/`.

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
Fresh quiesce is safe when the previous phase already stopped FPM/workers: it proves stopped state,
retains the maintenance marker and closed gate, and does not require HTTP503 from an absent backend.

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
There is no standalone public `ctl configure` action; use the enclosing refresh operation. Standalone
quiesce/resume also retain interruption markers while their application children run. Recovery quiesce
preserves an existing marker until the explicit root recovery above has stopped surviving children.
Do not restore app write permission on code or `.env`. Runtime-generated PHP under `bootstrap/cache`
and `storage/framework` remains an explicit application trust exception, as described in the kit README.
Backups refuse missing/invalid served environments and invalid `current`; recovery needs the verified
manifest, `env.backup` and its hash. A first-install `release_sha=none` set has no historical encryption key.
