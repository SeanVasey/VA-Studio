# Sean's 15-minute staging checklist: finish step 11

Prepared 2026-11-10. This finishes step 11 of [`ops/staging/README.md`](../../ops/staging/README.md#provisioning-step-by-step)
on the private staging host, in order, with as little typing as the kit allows. The full explanations are in
[`staging-runbook.md`](staging-runbook.md); this page is only the path through them.

**What only you can do:** choose two operator passwords, enroll two authenticator entries, click **Deploy Now** twice, paste
two non-secret lines into Forge, and (step 7) enter the off-host backup destination. Everything else is a command to paste.

**Rules.** Stripe stays in test mode. Type no password, key or recovery code into chat, an issue or Git: only into the
terminal prompt, the browser or Forge. Nothing here touches DNS, production or live payments.

Replace `<HOST>` with the staging hostname everywhere. Steps 1 to 6 take about 15 minutes of your attention; the two deploys
run for several minutes each on their own (each takes a backup and proves it restores first).

## 0. Connect

```sh
ssh forge@<HOST>
alias art='php8.4 /srv/vasey-staging/current/artisan'
```

You are signed in as `forge`, the application user, so the alias runs Artisan in the served release as the user that owns
private storage. It lasts for this SSH session. `sudo` asks for the sudo password Forge showed when the server was created.

## 1. Update the kit and reprovision (required)

The root helpers changed on `main` (PR #259 changed the backup helper, and later changes may too). A deploy compares the
installed helpers with the kit and refuses with `installed kit helpers differ … re-run provision.sh` until this is done.

```sh
cd /home/forge/<HOST>
git fetch --prune origin main                 # the same two git lines the Forge deployment script runs;
git checkout --detach --force FETCH_HEAD      # use the site's branch instead of main if it tracks another
git rev-parse HEAD                            # note the SHA: it is what the next deploy will serve
sudo bash /home/forge/<HOST>/ops/staging/provision.sh --host <HOST>
```

- The first three lines bring the Forge checkout (a git mirror, never served) to the SHA to stage.
- `provision.sh` reinstalls the root helpers, pools, workers and sudo grant from that checkout. It is safe to re-run. It does
  not ask for the basic-auth password again and it keeps `VASEY_BACKUP_DEST`. If you first ran it with extra options (for
  example `--app-env local` or `--app-user`), pass the same options again.
- Expect it to end without `ERROR`. Do not deploy yet.

## 2. Create the two operators

License approval refuses the account that authored the license, so staging needs two staff accounts. The command is
interactive on purpose: it asks for a name, an email address and a password (16 or more characters, letters and numbers) at
the terminal and accepts none of them as an argument.

```sh
cd /srv/vasey-staging/current
php8.4 artisan vasey:create-admin      # operator 1: you
php8.4 artisan vasey:create-admin      # operator 2: the independent reviewer (a different email address)
```

Each run creates one administrator with a verified email and writes an `access.operator.created` audit row. Keep both
passwords in your password manager.

## 3. Enroll the authenticator for both accounts

Staging requires TOTP before any admin page, as production will. For **each** account:

1. Open `https://<HOST>/admin`. The browser asks for the staging basic-auth user and password first, then the operator's
   email and password.
2. Open the user menu, choose **Profile**, and set up the authenticator app.
3. Scan the QR code, confirm with a current code, and store the recovery codes offline.
4. Sign out and back in to confirm the code is asked for.

Then check that the operator gate is now clear:

```sh
art vasey:doctor | grep -w operator     # expect the operator row to say pass
```

## 4. Deploy the new SHA

In Forge → Site → **Deploy Now**. This is the first deploy that needs the operators to exist; it serves the SHA from step 1.

```sh
sudo /usr/local/sbin/vasey-staging-ctl status     # expect current=<that SHA>, php-fpm active, 5 programs RUNNING, GET / 200
```

Forge may show "failed" after exactly ten minutes while the deploy is still running: that is Forge's output limit, not the
deploy. Wait until `ctl status` shows the new SHA and no operation marker
([runbook section 9](staging-runbook.md#9-rollback) explains what to read if it does not).

## 5. Generate the STAGING seller tag and select it

No preview can be made without a seller tag. Until your approved tag (S-6) exists, staging uses a synthetic chime that is
marked STAGING and that production refuses ([`staging-placeholders.md`](staging-placeholders.md), P-1).

```sh
art vasey:staging-seller-tag
```

It generates a one-second three-tone chime with ffmpeg, checks it against the pipeline's tag rules, installs it at
`/srv/vasey-staging/private/branding/STAGING-synthetic-seller-tag.wav` (owner-only) and prints two lines like these:

```
MEDIA_TAG_PATH=branding/STAGING-synthetic-seller-tag.wav
MEDIA_TAG_SHA256=<64 hex characters>
```

Neither line is a secret. In Forge → Site → **Environment**, replace the existing `MEDIA_TAG_PATH` and `MEDIA_TAG_SHA256`
lines with the two printed lines, save, and click **Deploy Now** again. The SHA is unchanged, so this deploy only refreshes
the configuration and restarts the workers. Running the command again is harmless: it reports `Already installed and unchanged`.

## 6. Confirm

```sh
art vasey:doctor                                  # every check passes; mail_transport warns on purpose (MAIL_MAILER=log)
art vasey:staging-seller-tag | tail -n 1          # expect: The environment already selects this tag.
sudo tail -n 5 /var/log/vasey-staging/backup.log  # the deploy's backup: expect "restore check passed"
```

The backup log will still say `NOT an off-host backup` until step 7. Staging is now ready for the media proof and the
synthetic rehearsal catalog ([runbook sections 5 and 5a](staging-runbook.md#5-media-proof-one-track)).

## 7. Off-host backup destination (does not block the deploy; do it this week)

Until this is done every backup sits on the same disk as the data. No placeholder destination exists, on purpose
([`staging-placeholders.md`](staging-placeholders.md), P-2). You need an account you control on a host that accepts rsync
over SSH; its address is entered on the staging host only.

```sh
sudo ssh-keygen -t ed25519 -N '' -f /root/.ssh/vasey_staging_backup     # makes the backup key pair on the staging host
sudo cat /root/.ssh/vasey_staging_backup.pub                            # the PUBLIC half: authorize it at the destination account
sudo ssh -i /root/.ssh/vasey_staging_backup <user>@<backup-host> true   # answer yes once to pin the destination's host key
sudoedit /etc/vasey-staging/staging.conf                                # set the line VASEY_BACKUP_DEST=<user>@<backup-host>:<path>
sudo /usr/local/sbin/vasey-staging-backup nightly                       # one supervised run: brief maintenance, then expect "shipped"
```

The destination receives the database dump, the private files and the `.env` (which holds `APP_KEY` and the Stripe test
secrets), so it must be storage you control and that is encrypted at rest. To encrypt on the host before anything leaves it
(age), or to use an rclone remote instead, follow [`backup-offhost-hardening.md`](backup-offhost-hardening.md). Retention at the
destination is your decision; the host keeps 7 sets.

## Done when

- `ctl status` shows the new SHA and `GET / 200`;
- `art vasey:doctor` passes except the intended `mail_transport` warning;
- both operators sign in with a code;
- the backup log says `shipped` (after step 7).

## To undo later

The synthetic tag, and anything else that stands in for a real asset on staging, is listed with its replacement and
verification in [`staging-placeholders.md`](staging-placeholders.md). Replace the tag with your approved one before any real
master is processed for publication ([runbook section 3](staging-runbook.md#3-seller-tag)).
