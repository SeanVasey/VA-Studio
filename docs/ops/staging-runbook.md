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

  Run any command that writes private files (kit import, retag, legal release) with an owner-only umask, for example
  `sudo -u forge bash -c 'umask 077; php8.4 /srv/vasey-staging/current/artisan …'`. The queue worker already writes
  0600/0700, but a `sudo` shell inherits a looser umask; on 2026-11-11 two group-writable kit `.write-lock` files from a
  CLI kit import made the next deploy's snapshot proof refuse the private tree and left the site in maintenance until
  case-A recovery. The kit lock is now created 0600 whatever the umask (#317); the umask habit covers anything else.

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

The shortest path through sections 2, 3, 4 and 8 on a host whose first deploy has finished is
[`staging-step11-checklist.md`](staging-step11-checklist.md).

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

### Synthetic STAGING tag (until S-6 exists)

Sean decided on 2026-11-10 that staging uses a clearly marked staging tag until his approved tag is ready. The command
below generates one on the host and installs it; nothing is copied to the server and nothing is committed:

```sh
art vasey:staging-seller-tag          # add --check to verify without installing
```

- **What it makes.** A one-second chime of three sine tones (880, 1320, 1760 Hz), 16-bit mono PCM, from ffmpeg's `sine`
  source. No recording, sample, speech or brand mark. One ffmpeg build always produces the same bytes; the command reads
  the SHA-256 from the file it made and never assumes it.
- **Where.** `/srv/vasey-staging/private/branding/STAGING-synthetic-seller-tag.wav`, mode 0600. The file name, the RIFF
  `INFO` title and the marker `VASEY-STAGING-SYNTHETIC-SELLER-TAG` in the RIFF `INFO` comment mark it.
- **What it prints.** The two lines `MEDIA_TAG_PATH=…` and `MEDIA_TAG_SHA256=…` to set in Forge → Environment, then
  **Deploy Now** as above. Neither is a secret.
- **What it refuses.** Any environment other than `local`, `testing` or `staging`, or a configured `APP_ENV` that differs
  from the running one; a host where another seller tag is installed and configured; a different file already at its
  own path. It never replaces a tag and never edits the environment.
- **Production.** A tag whose path contains `staging` or `synthetic`, or whose bytes contain the marker, is refused outside
  `local`, `testing` and `staging`: masters fail with `staging_tag_refused`, production `vasey:doctor` fails the required
  check `production_seller_tag`, and `ops/production/validate-runtime.php` refuses the deploy with `holds.staging_seller_tag`.
- **After the tag changes.** Tracks processed under the chime keep chime previews until their masters are processed again
  (`MEDIA_TAG_SHA256` is part of the processing fingerprint, but an existing preview stays "valid" against its own run).
  After the approved tag is deployed and `art vasey:doctor` passes `seller_tag`, list and then queue the draft tracks to
  reprocess (no path is printed). The media queue worker must be running **on the new configuration**: the Deploy Now
  above restarts the workers, but if `MEDIA_TAG_PATH` / `MEDIA_TAG_SHA256` were changed any other way, run
  `art config:cache` and `art queue:restart` first, or a worker that still holds the old configuration can fail the new
  runs. Then:

  ```sh
  art vasey:media-retag-previews --check
  art vasey:media-retag-previews --actor=<staff email>    # asks for that staff password; queues fresh runs, never publishes
  ```

  Published or unlisted tracks are reported (`skipped_live`) and left alone; unpublish one, then run it again. A track
  whose master was replaced since its current preview was made is reported `skipped_superseded`: process the newer master
  instead. Repeat `--check` until `stale_draft=0`. **Offers:** a retagged draft gets a new current preview, so its active
  offer revisions (which snapshot the old preview) are publication-blocked until the offers are republished. Details: [`media-processing.md`](../media-processing.md#regenerate-previews-after-the-seller-tag-changes).
  The command refuses on production.
- **Cleanup.** Previews made with it are rehearsal output. The replacement and its verification are entry P-1 of
  [`staging-placeholders.md`](staging-placeholders.md). Sean still approves the audible mix of the real tag before anything
  real is published.

### Specimen licensor signature (DD-27, PCL-06)

The licence profile reads a signature PNG from private storage (`VASEY_LICENSE_SIGNATURE_PATH`, pinned by
`VASEY_LICENSE_SIGNATURE_SHA256`). Staging uses a labelled specimen until Sean installs his own PNG on the production host:

```sh
art vasey:staging-specimen-signature          # add --check to verify without installing
```

- **What it makes.** A 1200 x 160 opaque PNG that reads `STAGING SPECIMEN — NOT A SIGNATURE` in the bold face the
  contract documents already bundle (DejaVu Sans Bold, `resources/contracts/test-v2/fonts/source/`), drawn with PHP GD.
  Text only: no stroke, flourish or mark of the brand. The bytes are deterministic (one fixed canvas and one fixed
  `tEXt` comment; no timestamp or random value), and the command reads the SHA-256 from the file it made.
- **Where.** `private/contracts/STAGING-specimen-signature.png`, mode 0600, never under `public/`, never committed.
- **What it prints.** `VASEY_LICENSE_SIGNATURE_PATH=…` and `VASEY_LICENSE_SIGNATURE_SHA256=…` for Forge → Environment,
  then **Deploy Now**. Neither is a secret. Do this before any v2 original is rendered: the signature must not change while
  one is unrendered (section 7, deploy rule).
- **What it refuses.** Any environment other than `local`, `testing` or `staging`, or a configured `APP_ENV` that differs
  from the running one; another signature image installed and configured; a different file already at its own path. It
  never replaces a file and never edits the environment.
- **How it is named a specimen.** `vasey:doctor` adds a `license_signature_specimen` row only when the configured signature
  file is readable and its bytes hold the marker `STAGING SPECIMEN` (in the PNG's `tEXt` chunk; the words in the picture are
  pixels, which cannot be searched). It passes on staging and says the file is the staging specimen; on production it is a
  required check and fails. With no signature configured, or a file that is absent, unreadable or a link, the report is
  unchanged. `art vasey:cutover-check` row PCL-06 reports the same by path word and marker. The marker is a convention: a
  copy with it stripped is not detected.

### Staging legal pages (DD-33, PCL-07)

Staging shows the privacy policy and terms with marked stand-in values until Sean supplies the production ones. One
command saves them as a private draft site release; publishing stays the staff action:

```sh
art vasey:staging-legal-release --check                          # build and validate, print the plan, write nothing
art vasey:staging-legal-release --actor=<staff-account-email>    # asks the staff password at a hidden prompt, saves the draft
```

- **What it builds.** The active site content with the privacy and terms pages replaced from `docs/legal/PRIVACY.md` and
  `TERMS.md` (read, never written). The pages open with `STAGING PLACEHOLDER — not in effect`; the effective date, last
  updated date, postal address, backup provider and email provider read `set at production`; the venue reads Polk County,
  Iowa (SQ-07); hosting reads Laravel Forge on DigitalOcean, storage the server's private disk and the region the United
  States. The reviewer
  appendices, the change log, square brackets, the legal-review tags and the DMCA sentence (DD-35) are removed. It writes no
  street address, legal name or other value DD-33 did not fix; a bracket it has no value for stops the build. The page's
  schema date field holds the build date (the schema needs a real date), not an effective date.
- **What it does not do.** It does not publish, schedule or edit an existing release. Preview the draft under Admin ->
  Publishing -> Site content, then publish it there. A second run with unchanged drafts reports the existing draft instead of
  saving another.
- **What it refuses.** Any environment other than `local`, `testing` or `staging`, or a configured `APP_ENV` that differs
  from the running one; an unknown or non-staff `--actor`; a wrong password; `--no-interaction` without `--check`.
- **Production.** The guard refuses this text everywhere else: publishing it in production fails with
  `legal_unresolved_markers` (`staging_placeholder`), and `art vasey:cutover-check` row PCL-07 fails on `STAGING` and
  `set at production` text in a published legal page.

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
| `seller_tag` | pass after section 3 | It checks that the two settings are present and well formed, not the file. `art vasey:staging-seller-tag` reports whether the staging tag on disk matches them. |
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

**Needs a configured seller tag (section 3): the approved S-6 tag, or the synthetic STAGING tag Sean accepted for
staging on 2026-11-10.** Without a configured tag, the command refuses before writing anything.

`vasey:staging-synthetic-catalog` creates six labelled synthetic tracks, so testers can rehearse the whole journey
(catalog, player, quote, Stripe test checkout, verified payment, contract PDF, download) before Sean's real tracks
(S-7) arrive. Sean approved it on 2026-10-17. Nothing it creates is product data:

- Track titles start with `[SYNTHETIC]` and the artist is "Synthetic Test Content".
- Every description, license name and license text says "Synthetic rehearsal content — not for sale; staging test only".
- There are six licenses, one per tier shape of the six license templates (DD-19, DD-20), each offered on every track.
  A shape is only the deliverables and whether the template is exclusive, as `docs/legal/LICENSE-TIERS.md` lists them;
  no tier name, cap, duration, territory, royalty or price is carried over, and the terms hold features and deliverables
  only (schema 1). Each is named `[SYNTHETIC] TEST PRICE tier N shape: … license — …`, with slug
  `synthetic-rehearsal-tier-N-license`:

  | Tier shape | Template type | Deliverables | TEST PRICE (cents) | Offer on each track |
  | --- | --- | --- | --- | --- |
  | 1 | non-exclusive | `download_mp3` | 1.11 USD (111) | published |
  | 2 | non-exclusive | `download_mp3` | 2.22 USD (222) | published |
  | 3 | non-exclusive | `download_mp3`, `master_wav` | 3.33 USD (333) | published |
  | 4 | non-exclusive | `download_mp3`, `master_wav`, `stems_zip` | 4.44 USD (444) | published |
  | 5 | non-exclusive | `download_mp3`, `master_wav`, `stems_zip` | 5.55 USD (555) | published |
  | 6 | exclusive | `download_mp3`, `master_wav`, `stems_zip` | 6.66 USD (666) | inactive draft |

  Shapes 1 and 2, and 4 and 5, are the same here: the real tiers differ only in caps, which are left out. Shape 1 is
  the free tier's shape, sold here as a paid TEST PRICE offer; the free-claim flow is not part of this command.
- The exclusive-shape offer is a draft with its exact deliverables and no revision. Public offer publication admits
  only non-exclusive licenses; the test chain prepares an exclusive revision (`PrepareExclusiveOffer`) only against a
  rights scope an operator links, and activates it (`ActivateExclusiveOffer`) only under the configured test exclusive
  selection policy. Neither has an admin action or console command yet, so the exclusive contract cannot be rehearsed
  from the storefront on staging until one exists. The draft is ready for it: its own evidence has no preparation
  blocker.
- The marker `synthetic-rehearsal` is the slug prefix of the tracks and license templates, a track tag, and (as
  `SYNTHETIC-REHEARSAL-NN:`) the prefix of each rights declaration's provenance. Offers, revisions and media inherit
  it through their track or license.

The command generates the audio (30–60 s tones, chords and noise, as WAV masters), a stems ZIP per track (one mono WAV
per test source, associated with the master through the ordinary stems association) with ffmpeg and PHP ZIP, and the
artwork (plain colours with "SYNTHETIC TEST", no brand mark) with PHP GD, on the host, at run time. The files enter through the
ordinary intake service and the ordinary media pipeline. Nothing generated is committed. Every step uses the same
domain service as the admin, under the same staff authority, MFA, rights, media and readiness checks.

**Admission.** The command checks all of this before it writes anything. A failure prints `Refused: …` and exits 1:

1. `APP_ENV` is `local`, `testing` or `staging`. The command refuses when the configured environment (`APP_ENV`,
   including a cached configuration) or the running environment is `production`, even with the flag on. It also
   refuses when the two differ, for example through `artisan --env=`.
2. `VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true`. The default is false, and
   `ops/staging/env.staging.example` states it as `false`; set it to `true` on the host only for the seeding run.
3. Stripe is in test mode: `STRIPE_MODE=test`, an `sk_test_` key or none, and no live production-checkout setting.
4. The catalog is empty or synthetic-only. A track that is or was published, or an approved license, outside the
   synthetic definition refuses the run. This is a configuration and catalog check, not proof of host identity.
5. Two existing staff accounts named for this purpose:
   - `--operator` is the author, rights verifier and publisher;
   - `--reviewer` approves the licenses, because license approval refuses the author.

   Both names must start with `Synthetic `, so synthetic writes are never attributed to a real operator. Both need
   catalog authority and, on staging, an enrolled authenticator: the same panel rule every staff write checks.
   A write run then asks for both passwords at hidden prompts, in an interactive session (the command refuses `--no-interaction`; it
   does not require standard input to be a terminal, so the confirmed passwords, not the terminal, are the gate). On staging it also needs
   `--attest`: it records one `staff.console_attestation` audit row per account before the first write.
6. The approved seller tag is configured and matches its SHA-256 (section 3). Without it the pipeline cannot make a
   tagged preview (`tag_not_configured`). The command never generates, replaces or overrides a tag; the staging tag is
   made by its own command (section 3).
7. ffmpeg, ffprobe, prlimit, PHP GD and PHP ZIP are available.

**Run.**

```sh
art vasey:create-admin   # name: Synthetic Catalog Operator; an email address you control
art vasey:create-admin   # name: Synthetic Catalog Reviewer
# Sign in at /admin as each account and enroll the authenticator app (section 2).
# Forge → Environment: VASEY_STAGING_SYNTHETIC_CATALOG_ENABLED=true, then Deploy Now (configuration refresh).
art vasey:staging-synthetic-catalog --operator=OPERATOR_EMAIL --reviewer=REVIEWER_EMAIL --check            # admission only, no prompts
art vasey:staging-synthetic-catalog --operator=OPERATOR_EMAIL --reviewer=REVIEWER_EMAIL --attest           # asks both passwords
```

Expect several minutes. ClamAV scans each master, the tag, each artwork and each stems ZIP (once as uploaded, once per
member and once rebuilt), at 15–25 s per scan (section 5). The
command processes each media run in its own process with the service the media worker uses. A claim token makes
the two exclusive: whichever claims a run first processes it, and the command waits for the result. It prints one
line per license and per track, then a table of offer revisions and prices, with each exclusive-shape draft offer. Exit 0 means every synthetic track is
published and passes publication readiness. Then set the flag back to `false` in Forge → Environment and click
Deploy Now, which refreshes the cached configuration as in section 3, so the running value is off too.

The command is a manual writer. Do not run it during a deploy, a backup or a quiesce. A cache lock refuses a second
concurrent run. If a run is killed, its lock expires after an hour.

**Verify.**

- The storefront lists six `[SYNTHETIC]` tracks. Each plays its tagged preview and shows the five non-exclusive TEST
  PRICE licenses (1.11 to 5.55 USD).
- `art vasey:rights-scope list` shows the thirty new revisions. "Prepare order" needs each revision you buy linked to
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
  `present, offers differ …` (exit 1). Nothing is reverted. This includes an exclusive-shape draft whose price was
  changed; an operator who prepared or activated it at its TEST PRICE keeps a matching catalog.
- When a run resumes an interrupted draft, two more things must be exactly what the definition generates, or it refuses
  (exit 1, nothing changed): the rights declaration (both its provenance and its sample disclosure) and the media. A source
  an earlier run took in, or a ready preview or artwork, is accepted only when its source file has the SHA-256 of the file the
  definition generates now. Staff who replaced a draft's upload, or whose draft declaration was edited, get
  `… has a rights declaration that differs …` or `… has master_wav media that differs …` (or `stems_zip`). The master
  and the stems ZIP are bit-exact; the
  artwork PNG comes from GD, so a changed PHP GD or zlib build can trigger the same refusal for an interrupted draft. Nothing
  is edited back; the remedy is the reset described under "Removing it before real content" below.
- A track withdrawn with the ordinary unpublish action is reported `withdrawn by staff; left unpublished`. The
  command never republishes it, and exits 1.
- The command never creates quotes, orders, payments, grants, contracts, rights scopes or exclusive preparations.
- **A staging that holds the earlier two-license catalog** (`synthetic-rehearsal-mp3-license`,
  `synthetic-rehearsal-wav-license`) is refused at admission: those approved licenses are outside the six-tier
  definition. Reset it as below first; published licenses and revisions are never edited into the new shapes.

**Removing it before real content.** There is no purge command: published licenses, offer revisions, order
snapshots and contracts are immutable evidence. The reset below removes every record the command creates: six tracks,
their rights declarations, masters, previews, artwork and stems with their recording associations, the six license
templates and versions, thirty published offer revisions, six draft exclusive-shape offers, and anything a rehearsal
built on them (rights scopes, quotes, orders, grants, contracts). The production cleanup ledger's PCL-02 and PCL-03
queries (`docs/production-cleanup-ledger.md`) confirm none remains. To hide the catalog, unpublish the synthetic tracks with the
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

An abandoned, declined or expired test order keeps its offer reserved (`INVENTORY_UNAVAILABLE` for the next buyer). With the
optional unpaid-release capability switched on (off by default; two Forge lines, then Deploy Now), staff release it under
**Test commerce → Test unpaid release** once Stripe shows the session expired unpaid. The steps, the proof the application
requires, the results and what "uncertain" means are in
[Releasing an abandoned test order](staging-test-purchase.md#releasing-an-abandoned-test-order).

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
  those for other addresses, and any that fail verification (changed, linked or misnamed files). Captures are never
  deleted, so a listing examines only the newest 50 by file modification time (`--limit=N`, at most 200) and says how many
  older ones it did not examine; `print` still finds an older capture by its id.
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
  are frozen by their contract profiles, so they are not edited. The container gives the free, paid and family 256
  (production free grants) renderers, outside the CLI only, a process factory (`App\Support\PhpCliProcess`). Family 256
  gained the binding with its default-off HTTP mount (`docs/ops/production-free-http-runtime.md`). The factory replaces just the
  executable and re-derives the fixed sibling library directory from the CLI binary. Flags, script, working directory, scrubbed environment, input and the 60 s
  limit are unchanged, and each renderer still verifies its pinned profile and bounds its output.
- The `vasey-license-pdf-v2` child (`VaseyLicenseV2RendererProcess`, `scripts/render-vasey-license-v2.php`) gets the same
  factory and limits. It renders only originals a gated selection recorded; the selection gate
  (`contracts.vasey_license_v2_issuance`) is a code literal `false` that only `local` and `testing` admit, so staging renders
  no v2 original today.
- Do not point `VASEY_PHP_CLI_BINARY` at `/usr/bin/php`: it is an `update-alternatives` link that can move to another
  version. A move is caught by the probe, but then rendering stops until the setting is fixed.

**Deploy rule for v2 license originals.** Do not rotate the license signature (the file or
`VASEY_LICENSE_SIGNATURE_SHA256`) and do not deploy a change to any file the v2 manifest pins
(`resources/contracts/vasey-license-v2/profile-assets.json`: its implementation closure, its assets or the manifest
itself) while any v2 origin is unrendered, that is, while a paid or paid-tax document work row is not `complete` for an
origin whose recorded profile is a v2 selection. Such a line refuses `signature_changed` or `profile_changed`, and the
order appears under Fulfillment, Held paid orders ("Contract render"). Reset opcache on every deploy of pinned files
(`docs/production-contract-profile.md`).

**Host checks.**
1. `ops/staging/validate-runtime.php` (run by every deploy and `ctl refresh`) fails `runtime.php_cli_binary` unless the
   configured binary passes the same probe the FPM pool will run.
2. Free grants stay off on staging: `VASEY_TEST_FREE_GRANTS_ENABLED` must be `false`, and the deploy gate refuses any other value
   (as it does for the other staged flags; unpaid release, the test refund and dispute lifecycle and automatic test delivery enabling are the optional capabilities, each admitted only with its exact policy). Enabling them is a reviewed change to `scripts/ops/capability-boundary.php`,
   the templates and their tests. That change should include rendering one synthetic document through the real FPM pool and
   confirming a PDF, not `render_failed`, before the flag is true on any host. The harness used for the evidence is in
   `docs/verification/php-cli-resolver-20261009/`.

## 8. Backups and the restore proof

What runs:
- **Nightly, 03:17 UTC** (`/etc/cron.d/vasey-staging-backup`).
- **Before every deploy that replaces a served release** (`vasey-staging-ctl snapshot`).
- **After the site is back up, when an off-host destination is configured:** the detached off-host shipment of every set still
  *pending* (below). It is not part of the nightly run or of a deploy, and never holds the site down.

Each run produces `/srv/vasey-staging/backups/<UTC>-<sha12>/` containing:
- `database.sql` and `.sha256`;
- `private/`, a root-only copy of the private store (sets taken before 2026-11-11 hold `private.tar` and `.sha256` instead);
- `private.sha256` and `private.dirs` (manifests), and for a `private/` tree `private.index` (per-file identity and hash);
- `env.backup` (APP_KEY) and `.sha256`;
- `MANIFEST` (for a tree also `private_format=tree`, `private_link_source`, `private_copied_files/bytes`, `private_linked_files/bytes`);
- `RESTORE_CHECK`, the proof: a disposable `mysqld` loaded the dump and re-dumped it, and the private files were verified
  (a tree in place, see below; an older archive restored as `forge` and verified).

**The private store is a shared tree (2026-11-11).** Every deploy used to tar the whole ~38 GB private store and the proof
extracted and re-hashed all of it: about 21 of the 22 minutes the site was down. Now the snapshot copies only the private files
that are new or changed since the newest verified set and hard-links the unchanged ones to **that set's** copies
(`ops/staging/private-snapshot.py`). What makes it safe:
- **Nothing in a set is ever linked to the live store.** Every changed file is copied to a new root-owned inode in a root-only
  tree, so an in-place write to a live file (reservation counters, lock files and Flysystem `put` do rewrite private files in
  place) can never reach a snapshot. A live file with a second hard link is refused, as before.
- **"Unchanged" means the exact identity the previous set recorded**: same device, inode, size, mtime and ctime (nanoseconds).
  Any write, truncate, chmod, utime or replacement changes the ctime, which an unprivileged process cannot set back. A file
  changed within two seconds of the earlier snapshot's start is never trusted, and a file that changes while it is copied
  refuses the snapshot.
- **A file is shared only with the set this operation re-hashed in full, and only while it is still that inode.** Before every
  quiesce (the deploy's own early `ctl space-check --preverify-if-needed`, which `ctl prepare` and `ctl refresh` reuse; the nightly run),
  with the site still up, the helper re-hashes every file of the newest tree set that holds a restore proof (3m17s for 37.9 GB
  measured on staging on 2026-11-11). Success
  writes a token, `backups/.link-token`, outside every set: the identity of each verified inode, ctime included. The next snapshot
  consumes it (one use; refused after 6 hours or when it names another set) and shares only files whose inode still has exactly
  that identity. Any write to it since, by anyone, moves the ctime, so it is copied instead. Without a token nothing is shared.
  A token older than 6 hours (an aborted deploy leaves one) is ignored everywhere: `ctl prepare` and `ctl refresh` re-hash
  instead of reusing it, and the space check names "sharing with the re-verified set" only when the estimate actually shares
  bytes; otherwise it says the token was refused, or was accepted with no file unchanged since, and counts the whole store.
- **Token renewal (`vasey-staging-backup rearm`, since 2026-11-12).** A deploy re-hashed about 4 minutes, built about 5 and paused
  about 2.5: 11 to 12 minutes, past Forge's 10-minute output limit, so Forge marked deploys that completed as failed. Now the
  deploy's early gate re-hashes only when no token younger than 5 hours names the newest proven set. `ctl activate`, `ctl
  refresh` and the nightly run start a detached renewal after their resume (systemd unit `vasey-staging-rearm`, log
  `/var/log/vasey-staging/rearm.log`): it waits for the deploy lock, re-hashes the set just taken with the site up and writes a
  fresh token. Cron runs it hourly at :47 and it renews a token older than 3 hours, so a deploy normally finds one. It takes the
  deploy lock and then the helper's action lock, so it never deletes a token that a deploy, the nightly run or a manual `ctl`
  operation is about to consume; other `ctl` calls, `status` included, wait for a running renewal (about 4 minutes).
  Not covered: a deploy pushed within about 4 minutes of the previous one, or during an hourly renewal, waits for the renewal
  (the deploy lock, up to 15 minutes, nothing touched) and can still pass Forge's 10 minutes; read the host log
  (`$EVIDENCE/forge-deploy.log`, or the last-deploy-log recipe) before acting on Forge's label, as before.
  A renewal that finds a changed backup file withdraws proofs exactly like the deploy gate (exit 1 in `rearm.log`) and records
  `backups/.rearm-finding`: the next deploy's gate refuses once on it before anything stops (corruption refusal below) and clears
  it, so the following deploy proceeds, as a finding of the gate's own re-hash always did. Trade-off accepted: a shared inode is
  trusted on a re-hash up to 5 hours old (it was minutes) inside the unchanged 6-hour token limit; any write to it moves its
  ctime and makes the snapshot copy it, but silent media corruption since the re-hash would go unnoticed until the next
  restore-check. Renewals re-read the newest set (about 38 GB) every 3 to 4 hours even without deploys, at Nice 10.
  The nightly run now also waits (up to 30 minutes) for a deploy or renewal instead of skipping the night.
  The space check judges the token against 5 hours, not 6: it runs before the build and the pause, and the snapshot judges the
  token again afterwards, so a token the estimate trusted must still be fresh then. A token between 5 and 6 hours old is
  re-hashed by `ctl prepare` and `ctl refresh` (`--preverify-if-needed`), and a plain space check counts the whole store and
  says the token "expires within 1 hour(s), before the snapshot would use it" (fixed 2026-11-12; before, such a token could
  pass the estimate and then fail the snapshot, which copied every file the estimate had left out with the site stopped).
- **Two kinds of refusal before the quiesce.** The deploy log tells them apart; neither stops anything.
  - **Corruption refusal** ("a backup set's private files failed re-verification ... corruption refusal"; the backup log says
    "failed its private file re-verification" and "withdrew the restore proof of ..."). Two kinds, and they withdraw different
    proofs:
    - *A file is bad*: its bytes, identity, mode or owner no longer match the index, or reading its bytes failed with EIO,
      EBADMSG or EUCLEAN (ext4 reports a corrupt block as one of the last two). The `RESTORE_CHECK` of that set and of every
      tree set that holds that same file (inode) is removed; sets that do not hold it keep theirs.
    - *The set itself is malformed*: its own manifests (`private.index`, `private.sha256`, `private.dirs`) are missing, empty,
      truncated, malformed, disagree with each other or cannot be read (the same three errors); or its tree's names,
      directories or entry kinds differ from them; or a listed name is gone or is not the kind of object listed when it is
      opened or stat-ed (ENOENT, ENOTDIR, ELOOP). Only that set's `RESTORE_CHECK` is removed: no file was shown to be bad, so a
      set sharing its files keeps its proof (its own re-proof decides).
    - Both at once: a file that already failed is never hidden by a later set-level finding or by an operational error later in
      the same proof; every set holding that file still loses its proof.

    What to do: check `dmesg`
    and the disk; run `sudo /usr/local/sbin/vasey-staging-backup restore-check <set>` on the sets that still hold a proof; keep
    the withdrawn sets for inspection (do not delete them by hand); then deploy again. The next deploy re-verifies the newest set
    that still holds a proof, or copies everything. Rollback case B needs a set with `RESTORE_VERIFIED`: use the newest one that
    still has it.
  - **Operational refusal** ("the re-verification of the backup set could not run (an operational error ... not corruption) ...
    operational refusal", exit status 3; the backup log says "an operational error, not a corruption finding" and "No restore
    proof was withdrawn"). The re-hash could not run or its token could not be written: no space (the host has been at 96%),
    out of memory, a crash of the helper (exit 1 that names no set counts as one too), or an I/O error (anything but ENOENT,
    ENOTDIR or ELOOP) that is not about a file's bytes: opening the set's `private/`, opening or listing one of its directories,
    or opening or stat-ing a file (the block may read on the next attempt), provided no file had already failed. **Every proof is kept.** What to do: fix the cause (free space with
    `sudo /usr/local/sbin/vasey-staging-ctl backup-prune`; read `dmesg`), then deploy again. If the same operational refusal
    repeats for the same set, prove that set by hand: `sudo /usr/local/sbin/vasey-staging-backup restore-check <set>` (named in
    the log line); a set that fails it loses its proof as a corruption finding.
  - The nightly run refuses neither: it logs the WARNING (with the same two wordings) and its snapshot copies everything.
  - The re-hash runs before the free-space arithmetic on purpose: it decides what may be shared and so what must be copied;
    checking space first would count a full copy and refuse deploys that fit. It writes only the token (a few MB).
  - A withdrawn set that was still waiting for its off-host copy is skipped by `ship-pending` (`pending` shows
    `last_result=skipped` and why) until it is proven again or abandoned with `pending-clear`.
- **Inside the window** the proof re-hashes every file the snapshot copied and checks that every shared file is still the exact
  inode it linked (device, inode, size, mtime, mode, owner, ctime). `restore-check DIR` (manual, case B below, a set moved to
  another disk or extracted from an off-host copy) re-hashes every file and compares size, mode and the mtime in whole seconds,
  because a copy has new inodes and tar keeps whole seconds. The same rules decide what it withdraws; when it cannot run (an
  operational error) it says "could not run (an operational error, not a corruption finding ...)", withdraws no other set's
  proof and leaves the set unproven until `restore-check` succeeds.
- **Symlinks and FIFOs swapped in during a snapshot are refused**: every directory and file is opened relative to its parent's
  descriptor without following links and must be the inode the listing reported; a file is opened non-blocking and must be a
  regular file. An application process that kept running through the quiesce cannot point the snapshot outside the store.
- **Pruning a set never changes another.** Removing a set unlinks its own names; an inode shared with a newer set keeps its data.
- **Off-host copies are whole.** The shipment tars one set at a time, so the archive holds every file in full (no hard-link
  entries) and `du` sizes it fully; an rsync destination receives whole files. The plain rsync shipment (`VASEY_BACKUP_DEST`
  without age) keeps every mode as recorded (`.gitignore` 0644, contracts 0400), so a copy brought back with `rsync -a` passes
  `restore-check`; before 2026-11-12 it rewrote them to 0600 and such a copy failed its proof. The copy is protected by its
  top directory, which keeps the set's root-only 0700: a set directory with any other mode or owner is not shipped. That
  protection needs a destination filesystem that honours POSIX modes and owners (ext4, xfs, btrfs, ZFS); on one that does
  not (SMB, some FUSE mounts) use the encrypted shipment instead.
- Sharing needs the sets on one filesystem (they always are: one backup directory). If the previous set is elsewhere, or the
  kernel refuses a link, the file is copied. `VASEY_BACKUP_LINK=0` in `backup.conf` turns sharing off (a full copy every time).

What changed for an operator:
- The deploy (and the nightly run) re-hashes the previous set **before** it stops the site, then the maintenance window holds
  only the dump, the copy of new files, their re-hash and the database proof. The re-hash runs in the deploy's early
  `ctl space-check --preverify-if-needed` (when no fresh token exists; see token renewal above), so it holds the helper's action lock: other `vasey-staging-ctl` calls, `status` included, wait
  for it. Measured on staging, 2026-11-11 (the second deploy after re-provisioning): re-hash with the site up 3m17s; snapshot 4 s
  (copied 10 bytes / 1 file, linked 37,902,871,534 bytes / 1,048 files); restore proof 47 s; backup part of the window about 58 s;
  the whole maintenance window about 5.5 minutes, of which about 4m07s is the build that `ctl prepare` runs inside its quiesce;
  the whole deploy 8m54s. The new set used 25 MB on disk. Before the change the window was about 22 minutes.
  Measured again on the deploy of 2026-11-12 (deployment 82759032, main `ead7368d`): re-hash with the site up 3m07s; maintenance
  4m45s (quiesce 23:57:55, resumed about 00:02:40), of which about 3m18s ran from the quiesce to the activate step (the build); snapshot 7 s (copied 3
  files / 1.2 MB, linked 37.9 GB); restore check 48 s; the whole deploy 8m01s. Those deploys built inside the pause on purpose:
  `ctl prepare` built the candidate as the application user, so the site was stopped first (Codex finding 4226283468 on
  PR #63).
- **The build moves before the quiesce once the host is re-provisioned (2026-11-12).** `provision.sh` now creates a dedicated
  build account, `vasey-staging-build` (no login shell, no password, no sudo, denied cron and at, a group of its own that
  `forge` is not in). With it, `ctl prepare` runs entirely with the site up, under its operation marker:
  1. root captures the frozen `.env` into a root:`forge` 0440 file the build account cannot read;
  2. root creates `releases/<SHA>` as root:`vasey-staging-build` 0770: the build account can write it, `forge` (the running
     application) cannot even traverse it, and the build account cannot open it up because it does not own it;
  3. `forge` writes a git bundle of the mirror's `HEAD` into a pipe and the build account imports it: git hashes every object,
     so the checkout is exactly the requested SHA (proven clean, as before) whatever bytes arrived;
  4. the build account runs `composer install` and `npm ci && npm run build` with a fresh private home, caches and `TMPDIR`
     under `/srv/vasey-staging/build` (root:`vasey-staging-build` 0770) and **no `.env`** (the Composer hooks need none, and the
     front end reads no `VITE_*` variable); no `/tmp`, `forge` cache or environment is shared with it. The build runs as a
     transient systemd service (`vasey-staging-build-<sha>-<pid>`): it can write only its slot and `build/`
     (`ProtectSystem=strict`), has private `/tmp`, `/var/tmp` and `/dev`, sees no `/home`, `/root` or `/run/user`, cannot
     create set-id files (`RestrictSUIDSGID`) or gain privileges, can open only IPv4 and IPv6 sockets (no unix socket to
     logind, D-Bus, MySQL or the application; loopback TCP stays reachable, so no local service may listen on it without
     authentication), has a private IPC namespace removed with it, and is capped at 60% of memory,
     150% CPU, 1024 tasks and 30 minutes, because the site is live meanwhile. A build account with systemd lingering enabled
     refuses. A set-id file of the build account left in a shared directory would
     otherwise let `forge` act as the build account and write into a later candidate before sealing (independent review of
     PR #354, reproduced); a host without systemd as init and cgroup v2 refuses rather than build unconfined;
  5. when the unit stops, its whole cgroup is killed; root takes the slot back (root:root 0700), kills anything of the build
     account that still runs (`kill(-1)` as the account, which no forking process escapes), and refuses to seal if the build
     account owns any file outside its slot and `build/` on a local filesystem (the same check also runs before each build).
     Then it seals the release while it is still 0700: code onto fresh
     root-owned read-only inodes, the private mount target handed to `forge`, and `bootstrap/cache`, `storage/framework` and
     `storage/logs` **held** root-owned and read-only, so the live application cannot plant generated PHP in the candidate;
  6. root installs the captured `.env` (root:`forge` 0440), `forge` runs the real runtime admission and writes `build.sha256`,
     and root attaches private storage.
  `ctl activate` then verifies the seal (a changed candidate refuses before anything stops), quiesces, hands the held runtime
  directories to `forge` (`seal-release.py release-runtime`) and continues as before. **The pause is now the quiesce, the
  snapshot and its restore check, migrate/caches/doctor, the switch and the healthy resume.** Measured on the first isolated
  deploy (2026-11-12, `785f47e4`, evidence `20261112T123526Z-785f47e42752`, nothing to migrate): re-hash with the site up
  12:35:26 to 12:38:49 (3m23s); isolated build with the site up 12:38:49 to 12:42:15 (3m26s); **pause about 4m** (quiesce
  just after 12:42:17, snapshot written 12:44:32, restore check passed 12:45:48, switched and resumed 12:46:19); whole
  deploy 10m58s. The pause is shorter than the 4m45s before, but not the one and a half minutes estimated: the snapshot
  and its restore check took about 3.5 of the 4 minutes in this run (about 1 minute in the 2026-11-12 measurement above).
  The whole deploy is longer, so Forge now reports "failed" for a successful deploy (see section 9, Forge's ten-minute
  limit): judge every deploy by its `forge-deploy.log`.
- **Before the first isolated deploy, smoke the unit on the host** (the tests replace `systemd-run` with a stand-in; none has
  run the real unit). After re-provisioning, run as root
  `bash /home/forge/vasey-staging.on-forge.com/ops/staging/smoke-build-unit.sh` (a Forge recipe as root works; it exits 0
  only if every probe passes and removes its scratch directory). It runs each probe in a unit with exactly
  `build_in_unit`'s properties (a test fails if the two drift), a scratch directory in place of the slot, and checks
  (it refuses while a deploy holds the ctl lock or an operation marker exists, and validates the build account before it
  kills anything; if it is itself killed, remove `/dev/shm/vasey-smoke-setid` if present, or the next prepare refuses it
  as a leftover):
  (i) creating a set-id file fails (`python3 -c "import os; open('/dev/shm/x','w'); os.chmod('/dev/shm/x', 0o4755)"` must
  raise `PermissionError`; remove `/dev/shm/x`); (ii) `busctl call org.freedesktop.login1 /org/freedesktop/login1
  org.freedesktop.login1.Manager SetUserLinger ubb <build uid> true false` fails (no unix socket); (iii) `exit 3` makes
  `systemd-run --wait` return 3; (iv) a background `sleep 1000` is gone when `--wait` returns; (v) `composer --version`,
  `npm --version` and `git --version` run inside it, and so does a network operation that resolves a name
  (`git ls-remote https://github.com/laravel/framework HEAD`): the unit allows only IPv4 and IPv6 sockets. Better still,
  let the first isolated `ctl prepare` be the full proof and watch it. Then deploy. A failed first isolated build leaves the
  site up.
- **Two things that make the next prepare refuse until root acts.** A file the build account left anywhere outside its build
  area, even an innocent one (an apport crash report in `/var/crash` from a crashing `node` or `php`), refuses every later
  build until it is inspected and removed; see "A failed isolated build" in section 9. And a root `ctl prepare` killed while
  its build runs does not stop the build unit: it runs on, sandboxed, until it ends or reaches its 30-minute limit, and the
  next prepare refuses on the slot it left (recover as in section 9).
- **Without the build account** (staging.conf has no `VASEY_BUILD_USER`, or the account does not exist yet) `ctl prepare`
  behaves exactly as before: it quiesces, builds as `forge` inside the pause and seals. A build account that exists but is
  unsafe (in `forge`'s group, `forge` or any other account in its group, a shared uid, a login shell, a wrong `build/` mode, a
  kit root under `/home`, no systemd) refuses before anything starts; it
  never falls back to building early as `forge`. Re-provisioning is required to get the account: the deploy refuses on helper
  drift until `provision.sh` installs the changed `vasey-staging-ctl`, `release-step.sh` and `seal-release.py` anyway.
- The **first** snapshot after re-provisioning has nothing to share and copies the whole store inside the window. Measured on
  staging, 2026-11-11: 37.9 GB / 1,049 files copied in 4m52s, proof 4m33s, maintenance about 9.8 minutes. Let the 03:17 nightly
  run take it, or deploy at a quiet time; every later snapshot shares.
- The early free-space gate is the authoritative one: `forge-deploy.sh` runs `ctl space-check --preverify-if-needed` before anything is
  stopped, so the estimate counts only what the snapshot will really copy. (Until 2026-11-11 it ran a plain check that counted a
  full copy: it asked for 91,344,719,852 bytes with 88,657,883,136 free and refused a deploy that needed 45,861,274,012.) Its
  refusal names the estimate it used ("estimate: sharing with the re-verified set ..." or "no re-verified set to share with: the
  whole private store is counted") and ends with `FAILED ... before the quiesce: the served release was not touched`.
- `space-check` counts only the bytes the snapshot will copy, plus the database twice (dump and the proof's datadir); with age +
  rclone it still counts one encrypted copy of the whole set. A tree set's `du` is its full logical size; the disk use of the
  backup directory as a whole grows by the changed files only.
- Restoring private files from a tree set streams the tree instead of extracting `private.tar` (section 9, case B shows both).
- Re-provisioning is required: `provision.sh` installs the new `/usr/local/libexec/vasey-staging/private-snapshot.py` and the
  changed `vasey-staging-backup` and `vasey-staging-ctl`; until then every deploy refuses on helper drift.
- Directory mtimes are not kept in a tree set (the tar sets kept them); file mtimes, modes and contents are.
- Going back to a kit from before this change (re-provisioning from an older SHA) leaves tree sets that the older helper can
  neither prove, ship nor restore: it knows only `private.tar`. Restore such a set with this runbook's case B commands, or
  re-provision with a kit from this change or later first.
- A reboot or remount can renumber the store's device, which makes every live file look changed: that snapshot copies everything
  and logs "nothing was shared with <set> although it was re-verified". The next one shares again.

The log is `/var/log/vasey-staging/backup.log`. A set without `RESTORE_CHECK` is unproven. A `*.FAILED`
directory is kept for inspection and never pruned.

**Off-host shipping is deferred (2026-11-11).** The snapshot and the restore proof are the only backup work inside the
maintenance window. `predeploy` (every deploy, and the nightly run) leaves a marker `backups/.offhost-pending.<set>` and
returns; with age + rclone configured it no longer encrypts or uploads ~38 GB while the site is down (that had added ~15 minutes
to every deploy, and a B2 `403 storage_cap_exceeded` once failed the deploy before the switch). After `ctl activate` / `ctl refresh`
has resumed the site, and after the nightly run's `ctl resume`, a detached `vasey-staging-ship-pending` unit ships the pending
sets, newest first, with the site up.

What the operator sees:
- A deploy's `forge-deploy.log` ends with `deployed <sha>` as before; the line before it says the shipment was started detached.
  The deploy's exit status never depends on the remote.
- Progress and failures: `/var/log/vasey-staging/ship-pending.log` (and `systemctl status vasey-staging-ship-pending` while it runs).
- The dashboard's "last successful backup" turns green when a shipment is verified (`daily ok`), red when one fails (`daily failed`),
  and goes stale (26 h) if none completes. A nightly run with an off-host destination does not mark itself ok: the shipment does.
  Without an off-host destination nothing changes (ok is recorded by the nightly run).
- Pending sets: `sudo /usr/local/sbin/vasey-staging-backup pending` prints each set with when it was queued, how many attempts were
  made, the last attempt's time, result and a one-line reason (for example `403 storage_cap_exceeded`). Empty output
  ("no sets are waiting for an off-host copy") means every set has shipped.
- A set stays on disk while it is pending: local retention (`backup-prune`, the nightly prune, the post-deploy prune) never removes
  it. If the remote stays broken for days the disk fills with sets (the pre-deploy `space-check` refuses before it stops anything).

Re-running a shipment:

```sh
sudo /usr/local/sbin/vasey-staging-backup pending                              # what is waiting, and why the last attempt failed
sudo /usr/local/sbin/vasey-staging-backup ship-pending                         # retry all of them now (foreground; site up; safe to interrupt and re-run)
sudo /usr/local/sbin/vasey-staging-ctl ship-pending                            # the same, detached, as the deploy user via sudo
sudo /usr/local/sbin/vasey-staging-backup ship /srv/vasey-staging/backups/<set>   # one set; clears its marker on success
sudo /usr/local/sbin/vasey-staging-backup pending-clear <set>                  # abandon one set (never deletes it; retention applies again)
```

The next nightly run retries on its own. After fixing a remote problem (a bucket cap, expired credentials) run `ship-pending`
once and watch `ship-pending.log`; the failed row on the dashboard is replaced by the success.

**Disk space and retention.** Every deploy takes a snapshot (database dump + the private files that changed since the newest
verified set + `.env`; the first one after re-provisioning copies the whole store, about 14 GB on 2026-11-10 and 38 GB on
2026-11-11), and the restore proof needs a disposable datadir (about the database's size) under `/var/lib/mysql-restore-check`.
Before it stops anything, `forge-deploy.sh` runs `vasey-staging-ctl space-check --preverify-if-needed` (the backup helper's `space-check`): it
estimates the need from the bytes the snapshot will copy (`private-snapshot.py estimate`; the whole store when no set can be
shared), the schema's data and index bytes (else the newest set's `database.sql`), the encrypted copy of the whole set that age/rclone shipping stages in the backup directory after the deploy (counted as the larger
of that copy and the restore proof's scratch when they share a filesystem, because they never coexist; plus one more set while an
earlier shipment is still encrypting) and a 20% + 256 MiB margin. When the free bytes are below that,
the deploy ends with `FAILED ... before the quiesce: the served release was not touched`, naming the bytes free and
needed. Free space with `sudo /usr/local/sbin/vasey-staging-ctl backup-prune`, which applies the configured local
retention (`VASEY_BACKUP_KEEP` newest sets, or the tiered policy) and never removes the newest set, a `*.FAILED`
directory or anything outside `VASEY_BACKUP_DIR` (such as `/srv/vasey-staging-retained-backups`); if that is not
enough, lower `VASEY_BACKUP_KEEP` in `/etc/vasey-staging/backup.conf` or enlarge the volume, then deploy again.
The same retention runs automatically right after each successful deploy snapshot (protecting the set just
taken, which is the rollback point) and after each successful deploy; a prune failure is only a warning.

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

**Re-prove any set:** `sudo /usr/local/sbin/vasey-staging-backup restore-check /srv/vasey-staging/backups/<set>`. For a tree set
it re-hashes every private file; `RESTORE_CHECK` then reports `private_rehashed_files` equal to `private_files_verified`.

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

A Forge "failed" after exactly ten minutes is Forge's deployment time limit, not necessarily a failed deploy: Forge
stops reading the output but leaves the processes running, and a first install's migrations take longer than that. Forge
documents the limit as fixed ("Deployments are limited to 10 minutes"; there is no site setting for it, only Envoyer's own
limits replace it). Since the isolated build (2026-11-12) a normal deploy takes about 11 minutes (re-hash and build run with
the site up before the pause; section 8), so **Forge marks a successful deploy "failed"**: the first isolated deploy
(`785f47e4`, Forge deployment 82807668) did, and finished healthy at 12:46:24. Always read `forge-deploy.log`.
`forge-deploy.sh` keeps writing everything to `forge-deploy.log` in the deploy's evidence directory. Wait until no
`vasey-staging-ctl` or `release-step.sh` process remains, then read that log's last lines and `ctl status`: a log that
ends with `deployed <sha>` and a status without an operation marker is a completed deploy; anything else is a recovery case below.

Decide which case applies by comparing `migrate-status-before.txt` and `migrate-status-after.txt` in the
failed deploy's evidence directory.

**A. The deploy failed before migration and before replacing the served configuration.** Confirm `current`
still points at the previous release. Without the build account, new-release deployments quiesce before allocating/building
a candidate, so Composer/npm failure can leave the old site in maintenance with writer admission closed. With it, a failed
prepare ("FAILED ... while the build account prepared the candidate with the site up") stopped nothing: see "A failed
isolated build" below. Inspect service
state before recovery; downtime includes the build. Stop any uncontrolled manual/rogue app-account process
separately; controlled service quiescence is not a builder attestation. Confirm the old
environment is unchanged. A failed quiesce or partial resume
does not prove stopped writers. Inspect `ctl status` and establish quiesce before resuming. If any migration
ran, use B; if same-SHA configuration replacement began, use D.

An activation that stopped *before the switch* with nothing to migrate also belongs here: `current` still names the
previous release, the evidence's `migrate.txt` says `Nothing to migrate.` and `migrate-status-before.txt` and
`migrate-status-after.txt` are identical, so the schema is unchanged and there is nothing to restore. (Example: a
`doctor failed checks: operator` refusal on a second deploy before step 11.) `doctor.json` must also be complete JSON
and `/srv/vasey-staging/private/processing/` must hold no workspace the doctor left behind: its media-scanner check
writes there and cleans up only when it exits normally. The candidate release is built, sealed and attached but never
served; remove it, or a later deploy of the same SHA refuses "already exists but is not current".

**Out of disk during the snapshot** (`tar` or `mysqldump` reporting "Wrote only ..." or "No space left on device"). This
is case A: the snapshot runs before any migration, `current` still names the previous release and the schema is
unchanged, so there is nothing to restore. The failed set is renamed `*.FAILED` (or the snapshot pointer is left
invalid) and kept for inspection; the site is down until you resume it. Recover in this order: (1) `ctl status`
and the recovery script below, which establishes quiesce and resumes the previous release; (2) free space without
touching data you may need: `sudo /usr/local/sbin/vasey-staging-ctl backup-prune` (never delete the newest complete
set, the `RESTORE_VERIFIED` set a rollback would use, or `/srv/vasey-staging-retained-backups` by hand); delete a
`*.FAILED` directory only after reading it, and only with `rm -rf --one-file-system`; (3) run
`sudo /usr/local/sbin/vasey-staging-backup space-check` and deploy again. Deploys made with the helpers from the
2026-11-10 fix check this before they stop anything (see "Disk space and retention" in section 8); an older installed
helper must be refreshed first by re-running `ops/staging/provision.sh` as root.

Run the recovery as one non-interactive root script, never by pasting lines into a root shell: there, a failed guard
ends that shell and the remaining lines would run in your own shell with nothing set. The script stops at the first
failure, refuses while any release-step, Artisan, Composer or npm process remains, and keeps this order (`ctl detach`
refuses while the marker exists). It deletes the candidate only after the detach succeeded and its private directory is
provably not the shared store: `rm --one-file-system` does not stop at a bind mount of the same filesystem.

```sh
sudo NEW=<40-hex SHA of the failed candidate> bash -euo pipefail <<'RECOVER'
[[ "$NEW" =~ ^[0-9a-f]{40}$ ]] || { echo "NEW is not a SHA" >&2; exit 1; }
! pgrep -f 'release-step|artisan (migrate|config:|route:|view:|event:|vasey:doctor)|composer|npm' >/dev/null || { echo "a build or Artisan process remains; stop" >&2; exit 1; }
REL="/srv/vasey-staging/releases/$NEW"
[ "$(readlink -f /srv/vasey-staging/current)" != "$REL" ] || { echo "$NEW is the served release; stop" >&2; exit 1; }
/usr/local/sbin/vasey-staging-ctl quiesce                    # proves stopped writers; keeps the marker
rm -- /etc/vasey-staging/operation-in-progress               # explicit root recovery, after the checks above
/usr/local/sbin/vasey-staging-ctl detach "$NEW"
! mountpoint -q "$REL/storage/app/private" || { echo "still mounted; stop" >&2; exit 1; }
[ "$(stat -c %d:%i "$REL/storage/app/private" 2>/dev/null || echo none)" != "$(stat -c %d:%i /srv/vasey-staging/private)" ] \
  || { echo "private storage still visible in $REL; stop" >&2; exit 1; }
rm -rf --one-file-system -- "$REL"
/usr/local/sbin/vasey-staging-ctl resume                     # previous release: 503 proof, workers, up, 200
/usr/local/sbin/vasey-staging-ctl healthy "$(basename "$(readlink -f /srv/vasey-staging/current)")"
RECOVER
```

**A failed isolated build** (site up, `GET /` still 200, an operation marker, an unsealed `releases/<SHA>` owned by
root:`vasey-staging-build` 0770, or root:root 0700 when the build finished and the checks after it refused). Nothing was
stopped or attached, so no quiesce or resume is needed. The script refuses when the candidate was already attached (a failure
after `attach`): use the recovery script above for that case. A candidate from an isolated build is never resumed by hand:
`ctl attach` refuses it, sealed or not, before changing anything (only prepare attaches it, after the runtime admission), so
discard it with the script below and deploy again; the site stays up meanwhile. `ctl activate` also re-runs the runtime
admission (`release-step.sh revalidate`) before it stops anything, so a candidate refused at admission never reaches the
quiesce, the snapshot or its migrations through `activate`. `ctl switch` (the rollback in step 5) does not re-run it: switch
only to a release that was admitted and served before. On the legacy path the application account builds the candidate
itself, so the admission catches operator mistakes, not a hostile application account. **If prepare said the build account owns a file outside its
build area**, treat the host as possibly compromised by a build dependency: do not delete the file before inspecting it (who
created it, set-id bits, what `forge` could have run with it), then remove it and anything else the account owns outside
`/srv/vasey-staging/build` (`find / -xdev -user vasey-staging-build`, for each local filesystem) before the next deploy, which
refuses until it is gone.

```sh
sudo NEW=<40-hex SHA of the failed candidate> bash -euo pipefail <<'RECOVER'
[[ "$NEW" =~ ^[0-9a-f]{40}$ ]] || { echo "NEW is not a SHA" >&2; exit 1; }
REL="/srv/vasey-staging/releases/$NEW"
[ "$(readlink -f /srv/vasey-staging/current)" != "$REL" ] || { echo "$NEW is the served release; stop" >&2; exit 1; }
! pgrep -f 'vasey-staging-ctl|release-step' >/dev/null || { echo "a helper still runs; stop" >&2; exit 1; }
setpriv --reuid=vasey-staging-build --regid=vasey-staging-build --clear-groups -- kill -KILL -1 2>/dev/null || true
pkill -KILL -u vasey-staging-build || true; sleep 1
! pgrep -u vasey-staging-build >/dev/null || { echo "a build-account process survives; stop" >&2; exit 1; }
! mountpoint -q "$REL/storage/app/private" && ! grep -qF " $REL/storage/app/private " /etc/fstab \
  || { echo "the candidate is attached; use the recovery script above" >&2; exit 1; }
rm -f -- /etc/vasey-staging/operation-in-progress            # explicit root recovery, after the checks above
rm -rf --one-file-system -- "$REL"
[ ! -L /srv/vasey-staging/current ] || /usr/local/sbin/vasey-staging-ctl healthy "$(basename "$(readlink -f /srv/vasey-staging/current)")"
RECOVER
```

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

Run this as root in a `sudo bash -euo pipefail` script (a here-document or a saved file, not lines pasted into an interactive root shell, where a failed step ends the root shell and the rest runs in yours), so that any failed step stops the sequence. Use the MySQL admin account Forge created (root over the local socket, or the
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
(cd "$SET" && sha256sum --check --quiet --strict database.sql.sha256) || exit 1
[ ! -e "$SET/private.tar" ] || (cd "$SET" && sha256sum --check --quiet --strict private.tar.sha256) || exit 1
mysql -uroot -e 'DROP DATABASE vasey_staging; CREATE DATABASE vasey_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -uroot < "$SET/database.sql" || exit 1
# 3. Private files: keep the directory object (every release bind-mounts that inode); move its entries aside, then
#    restore the verified tree (or, for a set from before 2026-11-11, extract its archive) into it as forge and check it
#    against the manifest. Root reads the root-only set; forge writes, so every restored file belongs to forge.
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 0700 -o forge -g forge "/srv/vasey-staging/private.pre-rollback-$STAMP"
APP bash -c 'shopt -s dotglob nullglob; for e in /srv/vasey-staging/private/*; do mv -- "$e" "$1/"; done' _ "/srv/vasey-staging/private.pre-rollback-$STAMP"
if [ -d "$SET/private" ]; then
  tar --create --file=- --directory="$SET/private" --numeric-owner . \
    | APP tar --extract --file=- --directory=/srv/vasey-staging/private --no-same-owner --preserve-permissions || exit 1
else
  APP tar --extract --file=- --directory=/srv/vasey-staging/private --no-same-owner --preserve-permissions < "$SET/private.tar" || exit 1
fi
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
environment and key. As root in a non-interactive `sudo bash -euo pipefail` script (as in B), select that exact pre-refresh set and current
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
