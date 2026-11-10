# Staging placeholders: what must be replaced before production

Prepared 2026-11-10. Sean's instruction: where staging needs a real asset that does not exist yet, use a clearly marked
**staging** stand-in and record it for production cleanup. This file is that record for the staging host
(`ops/staging/`, [`staging-runbook.md`](staging-runbook.md)). Each entry says what the placeholder is, where it lives, what
replaces it and how the replacement is verified. Nothing here is on a production host, and nothing here authorizes one.

Add an entry in the same change that introduces a placeholder. Remove an entry only with the evidence its last column asks for.

## P-1. Synthetic STAGING seller tag

| | |
| --- | --- |
| **What it is** | A one-second, three-tone chime (880, 1320 and 1760 Hz sine tones; 16-bit mono PCM WAV, about 88 kB) that stands in for Sean's approved seller preview tag (S-6). It is made on the host by ffmpeg's built-in `sine` source. It is no recording, sample or speech and carries no brand mark. It is not committed to Git. |
| **Why** | Without a configured tag the media pipeline produces no tagged preview (`tag_not_configured`), so nothing can be published and the synthetic rehearsal catalog refuses to run. |
| **How it is made** | `php8.4 artisan vasey:staging-seller-tag` in the served release, as the user that owns private storage (`forge`). It refuses unless `APP_ENV` is `local`, `testing` or `staging` and matches the running environment. It never replaces an installed tag and never edits the environment. |
| **Where it lives** | On the staging host only: `/srv/vasey-staging/private/branding/STAGING-synthetic-seller-tag.wav` (mode 0600), selected by `MEDIA_TAG_PATH=branding/STAGING-synthetic-seller-tag.wav` and `MEDIA_TAG_SHA256=<printed by the command>` in Forge → Site → Environment. It is also inside every staging backup set (`private.tar`), on the host and at any off-host destination. |
| **What marks it** | (1) The file name. (2) The RIFF `INFO` title `STAGING synthetic seller tag`. (3) The marker `VASEY-STAGING-SYNTHETIC-SELLER-TAG` in the RIFF `INFO` comment. Every preview made with it records the tag's SHA-256 in its `technical_metadata.tag_sha256`. |
| **What follows from it** | Every `preview_tagged` asset processed while it is configured was mixed with the chime, not the approved tag. Those previews are rehearsal output. |
| **What replaces it** | Sean's approved seller tag WAV (S-6), installed as [`staging-runbook.md` section 3](staging-runbook.md#3-seller-tag) describes, with its own `MEDIA_TAG_PATH` and `MEDIA_TAG_SHA256`. A changed tag is a changed processing profile: each master needs a new processing run, and each new preview needs a newly published offer snapshot (`docs/media-processing.md`). Staging content made with the chime is reset, not migrated ([runbook section 5a](staging-runbook.md#5a-synthetic-rehearsal-catalog-optional-before-real-tracks), "Removing it before real content"). Then delete the chime file. |
| **What stops it reaching production** | Three guards, none of which production configuration can turn off: the media pipeline fails a master with `staging_tag_refused` when the tag's path or content carries a marker outside `local`, `testing` and `staging`; `vasey:doctor` in production adds the required check `production_seller_tag`; and `ops/production/validate-runtime.php` refuses the deploy with `holds.staging_seller_tag` for a marked path or, when private storage holds the file, marked content. The guards catch mistakes. They do not claim to stop someone who strips the marker from a copy on purpose. |
| **How the replacement is verified** | On staging after the swap: `sha256sum` of the approved file equals `MEDIA_TAG_SHA256`; `art vasey:doctor` passes `seller_tag`; `ls /srv/vasey-staging/private/branding/` no longer lists the STAGING file; one real master is processed and Sean listens to the tagged preview and approves the mix. Before production: `grep -c -i -E 'staging\|synthetic' <<<"$MEDIA_TAG_PATH"` prints 0 for the production environment, `validate-runtime.php` passes without `holds.staging_seller_tag`, production `vasey:doctor` passes `production_seller_tag`, and no production preview's `technical_metadata.tag_sha256` equals the staging tag's SHA-256 (the value the command printed; keep it with the staging evidence). |

## P-2. Off-host backup destination: no placeholder

No stand-in destination was added, and none is needed: the off-host copy is optional for a deploy. Until Sean configures a
destination, every backup set is on the staging host's own disk and the backup log says so at every run:
`WARNING: … exists on this host only (NOT an off-host backup)`. That state is recorded here so it is not mistaken for done.

| | |
| --- | --- |
| **What is missing** | `VASEY_BACKUP_DEST` (rsync over SSH) in `/etc/vasey-staging/staging.conf`, or the age and rclone settings in `/etc/vasey-staging/backup.conf` ([`backup-offhost-hardening.md`](backup-offhost-hardening.md)). |
| **Why no placeholder** | A local directory posing as a remote would make the log say `shipped` for a copy that shares the disk it protects. The kit has no such option, and adding one would hide the gap the warning shows. |
| **What replaces it** | A destination Sean controls, entered on the host only ([checklist](staging-step11-checklist.md), step 7). Production needs its own destination, encryption and retention decision (U-02); the staging destination is not it. |
| **How it is verified** | `sudo /usr/local/sbin/vasey-staging-backup nightly` ends with `shipped` in `/var/log/vasey-staging/backup.log`, the set is present at the destination, and one restore drill from the off-host copy is done and recorded (`backup-offhost-hardening.md`, "Restore drill"). |

## P-3. Synthetic staff accounts and the synthetic rehearsal catalog (existing; listed for completeness)

Not introduced by this change. `vasey:staging-synthetic-catalog` and its two `Synthetic …` staff accounts are described in
[runbook section 5a](staging-runbook.md#5a-synthetic-rehearsal-catalog-optional-before-real-tracks). Their replacement is the
reset under "Removing it before real content" there; their verification is an empty catalog before Sean's real tracks load.
