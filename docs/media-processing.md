# Private media processing

Status: local WAV/artwork pipeline, bounded private WAV-stems ZIP ingestion and explicit recording association. This guide describes the code in this increment; it is not production acceptance evidence. Checkout and purchased downloads remain disabled, and the existing BeatStars site remains authoritative for sales.

## What the pipeline accepts

| Input | Supported limits | Result after successful processing |
| --- | --- | --- |
| WAV master | Admin upload up to 200 MiB; complete RIFF/WAVE with one PCM audio stream; 16/24/32-bit integer or 32-bit float; mono/stereo; 8–192 kHz; duration up to 20 minutes | Immutable WAV master preserving input bytes, untagged 320 kbps delivery MP3, full-length 192 kbps tagged MP3 preview, duration and 200 waveform peaks measured from that preview |
| PNG/JPEG artwork | Up to 20 MiB; each dimension 1–6,000 pixels | Re-encoded PNG with format/dimension verification and metadata stripped |
| Stems ZIP | Admin upload up to 200 MiB; up to 128 entries including folders; each WAV up to 128 MiB; total expanded bytes up to 512 MiB; maximum 100:1 expansion per file | One rebuilt immutable private ZIP, exact per-member hashes/audio metadata/scanner evidence; no preview or recording association is inferred |
| Other roles/formats | Not supported by this increment | No promotion to ready assets; no uploaded-preview bypass |

The processor's internal source bound is 512 MiB; this does not increase the 200 MiB admin upload limit. Stems archive processing does not establish which exact master recording it belongs to. Offer publication requires the explicit association below, intact media evidence and the separately reviewed license/rights requirements.

Every upload is private. Only explicit ready preview/artwork derivatives of a publication-ready, published track can be served through the public media route. WAV masters, untagged MP3 deliverables, original artwork, scanner evidence and private object keys are not public storefront fields. A ready database label alone is insufficient: public availability requires completed processing provenance and file integrity checks.

## Associate stems with a recording

1. Keep the track in draft and process its WAV master and stems ZIP successfully. In Media, select **Associate recording** on the ready stems revision.
2. Select the verified master for the current preview. Check the seller's source export/session and audible recording correspondence; enter a short verification note and confirm that the stems belong to this master. The software cannot determine musical correspondence or aligned start points for you.
3. Save. The Media table displays the associated master ID. The immutable association preserves exact revision IDs, hashes, operator and time. Reopen the action if the current preview changed before submission.
4. Review and publish an offer selecting the exact assets required by its approved license. This remains a separate action. A published track must first be unpublished before adding an association.

The association never changes processing ancestry or rewrites media. An identical direct retry returns the original record; a correction needs a new stems upload/revision and confirmation. Regenerating a tag on the same WAV source preserves the association, but the new preview still needs a newly published offer snapshot. A different WAV source cannot reuse the prior association. Missing or altered associated master/preview files block new offers and provisional quotes, including stems-only offers.

The additive `stems_recordings` migration has no inferred backfill. Retain its populated table, all media and frozen offers during code rollback. Migration `down()` is for disposable test/development databases only. Operator verification notes stay private and are excluded from storefront responses and offer snapshots; snapshots retain association identity and evidence hash.

## Prepare a stems archive

Export only complete supported RIFF/WAVE audio stems. Stored and deflated ZIP members are supported; encrypted ZIPs, nested archives, MP3s, documents, hidden macOS metadata, links, special files and unsupported compression are rejected. Each member uses the master WAV format checks and its frozen archive-profile duration ceiling (at most 20 minutes) and a full bounded FFmpeg decode. This verifies technical validity, not audible correctness, aligned start points, rights clearance or association with a master.

Use relative portable names starting with a letter or digit. Each component may contain ASCII letters, numbers, spaces, underscores, hyphens and dots (up to 100 characters). No leading hidden names, trailing spaces/dots, Windows reserved names, backslashes, absolute paths or traversal components. Names must be unique ignoring case. Up to four folder levels plus a filename and 240 bytes per full name are supported. Unsupported names must be renamed in the seller's source export; the worker never silently renames members.

The worker validates the entire archive directory first, streams each member by index into a generated private scratch filename, and checks actual bytes and CRC against the declared member size. It scans and decodes each member, then rebuilds a stored ZIP with sorted safe names, fixed timestamps, read-only file attributes and no copied archive comments/extra metadata. It scans that rebuilt ZIP before immutable promotion. Archive member names never become extraction paths. Input and output ZIP hashes can differ while WAV bytes remain exact.

The `media.stems` limits may be lowered; code enforces the documented ceilings. Each policy is included in the processing fingerprint, so a changed bound gets a new run without rewriting earlier evidence. The archive stage has a 360-second elapsed budget checked during streaming and between bounded subprocesses. An in-flight subprocess can take up to its own cap (120 seconds; 300 for a malware scan) before the elapsed-budget failure is recorded. Original-archive scanning precedes this budget. A ZIP is scanned once as uploaded, once per member and once rebuilt, so a scanner that takes 15 to 20 seconds per call limits how many members fit in the budget: see [Production malware scanner](#production-malware-scanner). Queue timeout/claim settings remain unchanged. The PHP/libzip parser itself still requires production worker memory/filesystem/process isolation; application bounds are not proof of deployed isolation.

A ZIP run produces only `stems_zip`, preserves its quarantined source and records a canonical integer-valued member manifest. It does not change track duration/waveform, generate a public preview, or automatically attach to a license. **Recording-revision binding is the next WP-03 dependency.** Both the public media route and the operator preview route deny stems archives. Future buyer access must go through WP-08 entitlements.

## Public integrity checks

Each request rechecks private path safety, immutable completed processing provenance and fresh file identity/size. Successful full SHA-256 checks are shared for at most 60 seconds, keyed by asset ID, expected hash, path and device/inode/size/modification/change timestamps. Cache hits do not extend that window. The worker prewarms these checks after promotion to avoid reading whole private masters repeatedly for catalog and player range requests. A privileged same-size write preserving the observable file timestamps may take up to 60 seconds to detect; this is a bounded integrity cache, not an instantaneous tamper monitor. Only trusted application processes may write the private disk or cache.

A cold large catalog still requires full digest verification. Catalog pagination/indexing, dedicated integrity reconciliation and realistic cold-cache load testing remain release-scale work; the catalog now uses bounded pagination (PR #27). Public catalog, track and media routes are throttled.

## Prepare the local worker

Complete the application setup in [README](../README.md). The application and worker need access to the same private local disk, which defaults to `storage/app/private`; the web document root must remain `public/`. Do not expose the private disk through static serving, symlinks or object URLs.

The worker needs the PHP ZIP extension and patched, compatible FFmpeg/ffprobe binaries with the `libmp3lame` encoder, Linux `prlimit`, and ClamAV with usable signatures: `clamscan`, or `clamdscan` with a running `clamd`, which production should prefer (see [Production malware scanner](#production-malware-scanner)). These tools are external dependencies; Composer does not install them. Configure their absolute executable paths in `.env`:

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1200
REDIS_QUEUE_RETRY_AFTER=1200
MEDIA_FFMPEG=/usr/bin/ffmpeg
MEDIA_FFPROBE=/usr/bin/ffprobe
MEDIA_PRLIMIT=/usr/bin/prlimit
MEDIA_CLAMSCAN=/usr/bin/clamscan
MEDIA_TAG_PATH=branding/approved-seller-tag.wav
MEDIA_TAG_SHA256=
```

Use `REDIS_QUEUE_RETRY_AFTER` only if the deployment selects the Redis connection. Both values deliberately exceed the media job's 900-second timeout and 960-second claim lease. Changing this ordering can allow a second worker to receive a job while its first attempt is still running.

Place the seller-approved WAV tag at the configured path relative to the private disk. For the example above, that is `storage/app/private/branding/approved-seller-tag.wav`. Use a regular file, not a symbolic link. The tag must meet the supported WAV requirements, be no longer than 15 seconds and no larger than 16 MiB, with an audible peak above the configured −50 dBFS minimum. Record the SHA-256 of the exact approved file:

```sh
sha256sum storage/app/private/branding/approved-seller-tag.wav
```

Copy the 64-character lowercase hash into `MEDIA_TAG_SHA256`. Keep the tag out of Git. The processor verifies and scans its private snapshot before mixing it into the preview at the start and every 30 seconds. A changed tag or hash is a changed processing profile; confirm its audible result before publication. There is no runtime synthetic tag fallback.

Apply database/configuration changes and run the queue worker in a separate terminal:

```sh
php artisan migrate
php artisan config:clear
php artisan queue:work --queue=media --timeout=900 --tries=3 --sleep=1
```

For a deployment that caches configuration, rebuild its config cache and restart long-running workers after a configuration change. Do not assume an already-running worker has read an edited `.env`.

The same `media` worker, scanner and FFmpeg tools prepare [site images](architecture/D-25-editable-site-images.md). Back up `storage/app/private/site-images/` together with the database: each image's manifest and hashes pin its stored bytes, so restore both from the same point.

A site-image commit that reports an error can leave files that no row names, as can a process killed before its cleanup: the prepared files of an image that never became ready under `site-images/revisions/<uuid>/`, or an intake upload under `site-images/quarantine/<uuid>/`. They are private and unreferenced. Remove such a directory only after confirming that no `site_image_variants.storage_path` row, or `site_images.source_path` row for quarantine, names anything in it, and only when nothing is in flight: the directory is older than the 16-minute processing lease and no upload is in progress, or the media workers and admin uploads are stopped. A run between promoting its files and its ready commit, or an upload between storing and committing, has files that no row names yet. Nothing sweeps them automatically.

The media process wrapper limits each subprocess to 120 seconds wall time, 90 CPU seconds, 2 GiB address space, 1 GiB file output, 64 open files and 256 KiB captured output, and starts it with `TZ=UTC`. Only the malware scanner has larger limits, 300 seconds wall time, 180 CPU seconds and 3 GiB address space (`media.scanner` in `config/media.php`), because `clamscan` loads every signature on each call. The malware scan's standard error is counted separately, up to 4 MiB, so scanner warnings do not end a scan. Those are code bounds, not evidence of production worker isolation or measured catalog throughput. FFmpeg receives explicit input formats and a `file,pipe` protocol allowlist; production still needs a dedicated restricted worker and network policy.

The admin's temporary uploads use authenticated, authorized private storage, with a 200 MiB temporary-file limit and 15-minute upload allowance. Set PHP `upload_max_filesize` and web-server/proxy request limits to admit the intended upload size; `post_max_size` needs room for multipart overhead. A lower upstream limit can reject a request before the application reports its own validation error. Temporary-upload settings do not implement resumable or multipart object-store uploads.

## Production malware scanner

Run `clamd` on the media worker's host and set `MEDIA_CLAMSCAN=/usr/bin/clamdscan`. Per-call `clamscan`, the default, works but is slow; it is the fallback. The figures below were measured with ClamAV 1.5.4 (Ubuntu 24.04 packages, official signatures) on a 4-vCPU, 16 GiB virtual machine without systemd, with other jobs running. They size a deployment and give it a checklist; they are not acceptance evidence for your host.

| | `clamdscan --fdpass` with `clamd` | `clamscan` per call |
| --- | --- | --- |
| Time for a small file | 0.01 to 0.03 s | 11 to 20 s: each call loads every signature, about 1 GiB resident |
| Where the size limits live | `clamd.conf`, below | the options the application passes, below |
| A stems ZIP of 24 members | built in 5.2 s (120 members: 23.6 s) | `archive_timeout` after 23 scans |

A stems ZIP is scanned once as uploaded, once per member and once rebuilt, all inside the 360-second archive budget, so at 15 to 20 seconds a scan `clamscan` fits about 17 to 21 members. The 24-member figures above come from an earlier measurement on the same machine.

The application calls a binary whose file name is exactly `clamdscan` with `--fdpass` and without `clamscan`'s size options, which `clamdscan` ignores with a warning. `--fdpass` hands the daemon an open file. Without it the daemon opens the path as its own user, which cannot read the worker's private files: `Permission denied`, exit 2, and the media waits as `scanner_unavailable`. `clamdscan` finds the daemon through `/etc/clamav/clamd.conf`. If yours is elsewhere, point `MEDIA_CLAMSCAN` at a wrapper that adds `--config-file=...` and is itself named `clamdscan`.

### The `clamscan` fallback

The application runs `clamscan --no-summary --stdout --max-filesize=768M --max-scansize=768M --alert-exceeds-max=yes` under `prlimit` with 3 GiB of address space, 180 CPU seconds and 300 seconds of wall time (`media.scanner` in `config/media.php`). Its version call runs under the same limits. ffmpeg and the other tools keep the shared limits. A worker whose own hard address-space limit is below 3 GiB fails every scan as `scanner_unavailable`: `prlimit` cannot raise it, and the worker log says so.

- **768 MiB for both sizes.** ClamAV counts an archive plus everything extracted from it against `--max-scansize`, so a stems ZIP of 200 MiB that expands to 512 MiB needs 708 MiB. At 512M, ClamAV rejected the largest archive the application admits (196 MiB, four members of 128 MiB) with `Heuristics.Limits.Exceeded.MaxScanSize`; at 768M it scans clean. Keep the two equal. ClamAV cuts an oversize archive member down to `--max-filesize` without an alert, and only a spent scan budget turns the cut into one: with 512M and 1G, a deflate bomb of 1 GiB of zeros in a 1 MiB ZIP was reported `OK`, and with 768M twice it is reported as over the limit. `--alert-exceeds-max=yes` makes a file over a limit an error, not a pass.
- **3 GiB of address space.** Loading the signatures takes 1.08 GiB, that 196 MiB archive 1.4 GiB, and the deflate bomb, whose cut member is mapped, 1.85 GiB. Below about 1.1 GiB the scanner cannot load its database at all.
- **180 CPU seconds and 300 seconds.** That archive took 53 seconds of CPU here (54 of wall time), against the 90 seconds every other tool gets, and up to 82 seconds of CPU (85 of wall time) in earlier runs on a busier machine.

### `clamd.conf`

The packaged `/etc/clamav/clamd.conf` of Ubuntu 24.04 must not be used as it is. It **fails open**: with `MaxFileSize 25M`, `MaxScanSize 100M` and no `AlertExceedsMax`, a file over a limit is skipped and answered `OK`. A 30 MiB ZIP with an EICAR member and an 800 MiB file both came back `OK` from it. It also sets `EnableVersionCommand false`, so `clamdscan --version` prints only `ClamAV <version>` and the application refuses every scan as `scanner_signatures_stale`, and `LocalSocketMode 666`, which lets any local user scan through the daemon. Set these:

| Setting | Value | Why |
| --- | --- | --- |
| `LocalSocket` | `/run/clamav/clamd.ctl` | where `clamdscan` looks by default |
| `LocalSocketGroup`, `LocalSocketMode` | the worker's group, `660` | only the worker may use the daemon; packaged: mode `666` |
| `EnableVersionCommand` | `yes` | the application reads the signature date from the daemon's answer; packaged: `false` |
| `MaxFileSize`, `MaxScanSize` | `768M` and `768M` | as for `clamscan` above; packaged: `25M` and `100M`. Keep them equal |
| `AlertExceedsMax` | `yes` | a file over a limit is reported as `Heuristics.Limits.Exceeded` (exit 1, so `scan_not_clean`), not skipped; packaged: not set |
| `MaxScanTime` | `240000` | milliseconds; keeps the daemon's give-up time under the 300-second wall limit of the client |
| `MaxThreads` | the number of media workers | each scan of a large archive added about 250 MiB resident and can extract up to 768 MiB to disk |
| `TemporaryDirectory` | a disk-backed directory the daemon owns, such as `/var/tmp/clamd` | recommended, not measured |
| `ConcurrentDatabaseReload` | `yes` (the default) | scans keep running during a reload, at the memory cost under host sizing |

Keep `freshclam` running as a daemon and confirm that the daemon reloads after an update: step 1 of the acceptance list shows the signature date the daemon reports. `media.max_signature_age_seconds` (48 hours) measures the build time of the newest daily database, not the time of freshclam's last check, so alert well before the limit. The upstream publishing cadence was not measured; 24 to 36 hours of age is a starting point.

### Time zone of `clamd`

`clamdscan --version` prints the signature time in the time zone of the daemon, and the application reads it as UTC. The application starts every tool it runs, `clamscan` included, with `TZ=UTC`, but it cannot change the zone of a daemon that is already running. A daemon in `Asia/Tokyo` printed `15:24:18` at 13:24 UTC for signatures built at 06:24 UTC, and the application refused them as dated in the future until they were eight hours old; a daemon west of UTC reports them older than they are. Give the daemon's service a zone of UTC with a drop-in:

```ini
# /etc/systemd/system/clamav-daemon.service.d/timezone.conf
[Service]
Environment=TZ=UTC
```

Run `systemctl daemon-reload` and restart `clamav-daemon`. The drop-in itself was not exercised here, because the test machine has no running systemd. The effect was: a daemon started with `TZ=UTC` in its environment printed the UTC time, and one started with `TZ=Asia/Tokyo` did not.

### Host sizing

- `clamd` holds about 1.0 GiB resident when idle, and reached 1.2 GiB after scanning archives that expand to 512 MiB.
- A reload with `ConcurrentDatabaseReload yes` peaked at 2.0 GiB resident, twice the idle size. An earlier run timed it at about 17 seconds, with scans going on. Starting the daemon took 11 to 18 seconds, nearly all of it CPU.
- Reserve 3 GiB for `clamd`, plus the media workers, each of which may use 2 GiB of address space for FFmpeg. A practical minimum is a 4 GiB host. A `MemoryMax` below about 3 GiB can kill a reload.
- On a smaller host set `ConcurrentDatabaseReload no`. The peak stays near 1.0 GiB, but a scan submitted during a reload waited 55 seconds in the one run of it.
- Leave disk for extraction: up to `MaxThreads` times 768 MiB in `TemporaryDirectory`. That is an upper bound from the limits, not a measurement.

### Acceptance list

Run these on the deployed host as the worker's user, with the configured binary and its options (`--fdpass` for `clamdscan`), before relying on the scanner. Assemble the EICAR test string at run time from its published text, in a scratch directory outside the repository. Check that a test ZIP is really scanned before trusting its result: ClamAV did not scan the members of ZIPs written with Python's `ZipFile.open(..., force_zip64=True)` and answered `OK`, which passed the EICAR member and the bomb below. The same archives written without `force_zip64` were scanned and detected.

1. `clamdscan --version` prints `ClamAV <version>/<number>/<date>`, and the date, which is UTC, is within 48 hours of `date -u`.
2. The EICAR test file reports `Eicar-Test-Signature FOUND` and exits 1.
3. A ZIP over 25 MiB with an EICAR member reports `FOUND`. This catches the packaged limits.
4. An 800 MiB file (`truncate -s 800M big.bin`) reports `Heuristics.Limits.Exceeded.MaxFileSize FOUND`. With `clamscan` it exits 2, which the application treats as a scanner problem, not a detection.
5. A deflate bomb, 1 GiB of zeros in a ZIP of about 1 MiB, reports `Heuristics.Limits.Exceeded.MaxScanSize FOUND`.
6. A ZIP just under 200 MiB with four members of 128 MiB each (512 MiB expanded), the largest the application admits, reports `OK`. Give each member about 49 MiB of random data and zeros for the rest.
7. Stop the daemon. The application must report `scanner_signatures_stale` or `scan_not_clean` for both a clean file and the EICAR file, never a clean result: `clamdscan --version` then prints an error and only `ClamAV <version>`.

## Publish a track through the admin

1. Create or edit a draft track and complete its title, slug, artist, genre, BPM and key. Keep rights review and approved/published license versions current.
2. Open **Media Assets**, choose the track and **WAV master**, **Artwork (PNG or JPEG)** or **Stems (WAV-only ZIP)**, then upload the actual file. The server records its MIME, byte count and SHA-256 into a quarantined source record. The form cannot provide a trusted ready status or private object path.
3. Choose **Process / retry** on the source row. Uploading alone does not start processing. The worker changes the run from `queued` to `processing`; the table refreshes its processing status, and **Processing details** shows progress or a failure message.
4. Wait for `completed`. The source becomes `processed`; its derived asset rows become `ready` together. A WAV run produces three distinct immutable asset records, linked to the source and the completed run. Repeated requests with the same source/profile return that existing result.
5. Choose **Review preview** on the ready preview/artwork rows to listen and inspect before release. This route requires an authenticated operator and never serves a master or delivery MP3. Select the exact ready deliverable revisions required by each license offer; uploading or generating media does not automatically approve rights, license text or offers.
6. Run the track's readiness check. Resolve every blocker, publish the track, then check its share link. Duration and waveform come from the verified preview. Media processing does not enable checkout or buyer downloads.

For a replacement, unpublish the track before requesting processing. Upload changed bytes as a new source rather than editing a stored revision. The worker also checks publication state at completion, so a track published during processing cannot silently receive replacement assets. Previous ready revisions remain retained; a new preview does not rewrite an offer's selected deliverable IDs.

## Recover a failed or stalled run

Start with the source row's **Processing details** and the application's protected worker logs. Processing failures are recorded with a code/message and audit event. Do not set `status=ready` or replace stored hashes manually to make a track pass readiness.

| Observation | Operator action |
| --- | --- |
| `queued` remains unchanged | Verify a worker is listening to `media` on the same queue connection/database and private disk. Restart/recover the worker, not the stored media records. |
| `scanner_unavailable` or `scanner_signatures_stale` | The scanner is missing, reported an error, crashed, is not ClamAV, or has out-of-date signatures; it exited 0 without the exact clean line (for example with a warning of its own, such as a deprecated option after an upgrade, in its output); or its standard output ran past the 256 KiB limit, or its warnings on standard error past 4 MiB. Fewer warnings than that do not affect the result. A scan that runs out of time reads `processor_timeout`. None of these says anything about the file. Fix the scanner, its configuration or its signatures, then retry. `scanner_signatures_stale` is also what a signature date more than an hour in the future reads, which a `clamd` in a zone east of UTC prints, and what `clamdscan` produces when the daemon is not running or has `EnableVersionCommand false`. The worker log says which of these it was, and why a scan came to nothing: see [What the worker log says about the scanner](#what-the-worker-log-says-about-the-scanner). |
| `scan_not_clean` | The scanner reported a detection (clamscan exit 1). `clamscan` and `clamd` also report an archive over a scan limit this way (`Heuristics.Limits.Exceeded`). Investigate the original file; retry only after the cause is understood. |
| `tag_not_configured`, `tag_hash_mismatch` or `invalid_tag` | Correct the private approved tag/path/hash or supported format. Reload worker configuration and request processing again; a changed profile gets its own run. |
| `invalid_wav`, `unsupported_wav`, `invalid_audio` or `invalid_artwork` | Export a supported complete source and upload it as a new revision. Renaming an extension does not convert the file. |
| `source_changed`, intake integrity mismatch or `unsafe_path` | Investigate storage changes, or a symbolic link in place of the stored file itself. Preserve the existing evidence and upload a new valid source; do not rewrite the recorded hash. |
| `unsafe_storage` | The private disk is served or public, or one of its directories is a symbolic link. Fix the storage layout, then request processing again; the stored source is unchanged. |
| `profile_changed` | The worker configuration changed after this run was queued. Reload consistent configuration and request processing to create/use the current profile. |
| `tool_unavailable`, `processor_failed`, `processor_timeout` or `processor_output_limit` | Check executable availability, encoder support, source validity and resource usage. Correct the cause before retrying; review a profile/tool change as a new revision rather than weakening validation. |
| `invalid_archive`, `unsafe_archive`, `unsupported_archive`, `archive_limit` or `invalid_wav` for stems | Inspect the source export and re-create a supported WAV-only ZIP. Preserve the rejected source; upload corrected bytes as a new revision. |
| `zip_unavailable` or `archive_timeout` | Install PHP ZIP or investigate worker capacity and archive size. Lower source complexity or split the source export; do not raise hard safety ceilings to force acceptance. |
| `track_published` | Unpublish the track and request processing again. |
| `processing` after a worker crash | Confirm the old worker is stopped. A fresh request can reclaim the run after its 960-second lease expires; the queue connection retries an abandoned reservation after 1,200 seconds. Inspect failed jobs if the queue has exhausted attempts. |

### What the worker log says about the scanner

A scan that comes to nothing writes one warning to the worker log, and the message says which of four things happened. A line quoted from the scanner has the upload's directory replaced by `<dir>`, is printable ASCII and at most 200 characters, and only its first non-empty line is kept. None of it reaches the message shown in the admin.

- `The malware scan did not finish.` The scanner, or its version call, failed, was killed or ran out of time. `reason` is the failure code. `exit_code` is the tool's status, 128 plus the signal for a tool a signal ended, so 137 is SIGKILL, which is how the CPU limit ends a scan. `error_line` and `output_line` are the first line it wrote to standard error and to standard output. The scan runs with `--stdout`, so `clamscan` and `clamdscan` report their own errors on standard output and libclamav's on standard error.
- `The malware scanner signatures were refused.` This is `scanner_signatures_stale`. `version` is the first line `--version` printed, `signature_time` the time the application read from it (UTC), and `age_seconds` how old that makes the signatures, negative for a date in the future. `error_line` is the first line `--version` wrote to standard error.
  - `signature_time` and `age_seconds` are `null`, and `version` is `ClamAV <version>` alone: the scanner printed no date. `error_line` says why, and it is the only thing that does: `Could not connect to clamd` for a daemon that is not running, `VERSION command disabled` for `EnableVersionCommand false`.
  - `age_seconds` is negative, by hours: the scanner prints its date in a zone east of UTC. For `clamd` that is the `TZ` of its service (see "Time zone of `clamd`" above).
  - `age_seconds` is over `media.max_signature_age_seconds`: the signatures really are old. Check `freshclam` and that the daemon reloaded.
- `The malware scanner is not ClamAV.` `version` is the first line `--version` printed and `error_line` its first line on standard error. `MEDIA_CLAMSCAN` names another program.
- `The malware scan finished without confirming a clean result.` The scan exited 0 without the exact clean line. `output_line` is the first line it printed, often a warning of its own such as a deprecated option, and `error_line` its first line on standard error.

Normal queue failures permit up to three attempts with 30/120-second backoff. The explicit admin retry reuses a failed run for the same source/profile, increasing its attempt history; it does not overwrite completed output. An active claim or completed run is returned without starting duplicate work.

Ordinary failure cleanup removes an attempt's scratch files and unreferenced newly promoted files. A forcibly killed process cannot guarantee that cleanup ran. Preserve quarantined originals, durable revisions and audit records; remove abandoned scratch files only after confirming no active worker owns them. Automated orphan reconciliation and retention policy are future work.

New stems jobs emit archive policy v2, including the duration ceiling in the profile fingerprint. A changed ceiling after queueing requires a new profile revision; validation consumes the accepted ceiling. Historical v1 and v2 evidence remains supported independently of the current builder version. Unknown versions fail verification.

## Verification and release boundary

The PHP integration suite exercises real FFmpeg work on runtime-generated synthetic WAV/artwork fixtures. Its scanner double is accepted only in the testing environment. That proves application handling of scanner results; it does not prove real malware detection, signature freshness or a production ClamAV installation. There is no scanner bypass switch for local or production operation.

The integrating PR records exact commands, runtime versions, tested commit and observed results. Run the suite with `php artisan test` and inspect those results rather than treating this guide as a passing test report.

Before production media acceptance, record:

- A real ClamAV clean/detection/error exercise, maintained signature-update procedure and verification of scanner permissions/resource requirements: the [acceptance list](#acceptance-list) above. The code rejects an absent/unparseable, future-dated, or older-than-48-hours signature timestamp; actual detection and signature-update operations still require deployment evidence.
- Isolated worker permissions/network policy, supported patched tool versions, full-duration seller catalog timings, available disk capacity, queue failure alerting, restart behavior and crash recovery.
- Seller approval of the audible tag mix and real browser/device preview playback, seeking and waveform behavior.
- Storage-provider privacy/retention/restore evidence, real seller ZIP/stem export compatibility and recording association, and resumable uploads if required by the seller's actual source files.
- Publication/rights/license evidence and the separate payment, contract, entitlement, migration and cutover gates.

To roll back the increment, stop accepting new processing requests and drain or stop media workers while retaining original/private objects and evidence. Restore the reviewed application release only after checking its schema compatibility. Corrected derivatives should use a new profile/revision; never overwrite assets referenced by an offer or a future purchase.
