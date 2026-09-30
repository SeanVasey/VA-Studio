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

The `media.stems` limits may be lowered; code enforces the documented ceilings. Each policy is included in the processing fingerprint, so a changed bound gets a new run without rewriting earlier evidence. The archive stage has a 360-second elapsed budget. Every malware scan, FFprobe call and FFmpeg call in it is given at most the seconds that remain, and no more than its own limit (300 seconds for a scan, which all its processes share, and 120 for a tool), and none starts after the deadline; copying a member is checked against the deadline as it streams. So the stage ends within a second or two of its deadline, plus the time to write the rebuilt archive, and a call the budget cuts off is recorded as `archive_timeout`, not `processor_timeout`. The worst case of a whole run is then the original-archive scan, which precedes the budget and has its own 300 seconds, plus the budget of 360, plus the two calls that print a tool's version after the build, 15 seconds each: 300 + 360 + 30 = 690 seconds of subprocess time, and a few more for rounding, against the job's 900. That leaves about 200 seconds for what no limit bounds, which is copying the upload, hashing and writing the files and the database work: the whole job on the largest upload, run through `MediaProcessor` with the real scanner, took 221 seconds here and 13 of them were outside its scans (copying, decoding, packaging, promotion and the database), and hashing 512 MiB took half a second. A ZIP is scanned once as uploaded, before the budget begins, and then once per member and once rebuilt, inside it, so a scanner that takes 14.5 to 22.8 seconds per call, as `clamscan` does, fits 14 to 23 members in the budget: see [Production malware scanner](#production-malware-scanner). Queue timeout/claim settings remain unchanged. The PHP/libzip parser itself still requires production worker memory/filesystem/process isolation; application bounds are not proof of deployed isolation.

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

The media process wrapper limits each subprocess to 120 seconds wall time, 90 CPU seconds, 2 GiB address space, 1 GiB file output, 64 open files and 256 KiB captured output, and starts it with `TZ=UTC`. Only the malware scanner has larger limits (`media.scanner` in `config/media.php`): 300 seconds of wall time, which all the processes of one scan share, and, for `clamscan` alone, 180 CPU seconds, 3 GiB of address space and a file-size limit of 1280 MiB, because `clamscan` loads every signature on each call. `clamdscan` only relays the file to its daemon and keeps the shared limits. The two calls that print a tool's version after a build get 15 seconds each. The malware scan's standard error is counted separately, up to 4 MiB, so scanner warnings do not end a scan. Those are code bounds, not evidence of production worker isolation or measured catalog throughput. FFmpeg receives explicit input formats and a `file,pipe` protocol allowlist; production still needs a dedicated restricted worker and network policy.

The admin's temporary uploads use authenticated, authorized private storage, with a 200 MiB temporary-file limit and 15-minute upload allowance. Set PHP `upload_max_filesize` and web-server/proxy request limits to admit the intended upload size; `post_max_size` needs room for multipart overhead. A lower upstream limit can reject a request before the application reports its own validation error. Temporary-upload settings do not implement resumable or multipart object-store uploads.

## Production malware scanner

Run `clamd` on the media worker's host and set `MEDIA_CLAMSCAN=/usr/bin/clamdscan`. Per-call `clamscan`, the default, works but is slow; it is the fallback. The figures below were measured with ClamAV 1.5.4 (Ubuntu 24.04 packages, official signatures) on a 4-vCPU, 16 GiB virtual machine without systemd, with other jobs running. They size a deployment and give it a checklist; they are not acceptance evidence for your host.

| | `clamdscan --fdpass` with `clamd` | `clamscan` per call |
| --- | --- | --- |
| Time for a small file | 0.01 to 0.07 s | 14.5 to 22.8 s, about 11 s on an idle host: each call loads every signature, about 1 GiB resident |
| Where the size limits live | `clamd.conf`, below | the options the application passes, below |
| A stems ZIP of 24 members | built in 5.2 s (120 members: 23.6 s) | `archive_timeout` after 23 scans |

A stems ZIP is scanned once as uploaded, before the 360-second archive budget begins, and then once per member and once rebuilt, inside it. So at 14.5 to 22.8 seconds a scan, `clamscan` fits about 14 to 23 members, and fewer in a large archive, whose rebuilt ZIP takes longer to scan: 79 to 87 seconds at 512 MiB. The 24-member figures above come from an earlier measurement on the same machine.

The application calls a binary whose file name is exactly `clamdscan` with `--fdpass` and without `clamscan`'s size options, which `clamdscan` ignores with a warning. `--fdpass` hands the daemon an open file. Without it the daemon opens the path as its own user, which cannot read the worker's private files: `Permission denied`, exit 2, and the media waits as `scanner_unavailable`. `clamdscan` finds the daemon through `/etc/clamav/clamd.conf`. If yours is elsewhere, point `MEDIA_CLAMSCAN` at a wrapper that adds `--config-file=...` and is itself named `clamdscan`.

### The `clamscan` fallback

The application runs `clamscan --no-summary --stdout --max-filesize=1280M --max-scansize=1280M --max-scantime=300000 --alert-exceeds-max=yes` under `prlimit` with 3 GiB of address space, 1280 MiB as the largest file the process may write, 180 CPU seconds and 300 seconds of wall time (`media.scanner` in `config/media.php`). Its version call runs under the same limits, and shares the 300 seconds with the scan: one scan takes 300 seconds and a second or so at most. ffmpeg and the other tools keep the shared limits (2 GiB, 90 CPU seconds, 1 GiB, 120 seconds of wall time). A worker whose own hard address-space limit is below 3 GiB, or its hard file-size limit below 1280 MiB, fails every `clamscan` scan as `scanner_unavailable`: `prlimit` cannot raise a hard limit, and the worker log says so. `clamdscan` only relays the file to its daemon, so it keeps the shared 2 GiB, 90 CPU seconds and 1 GiB, and such a worker can use it; it gets the same 300 seconds of wall time, which its daemon may need.

- **1280 MiB for both sizes.** ClamAV counts an archive plus everything extracted from it against `--max-scansize`. The worker rebuilds every stems ZIP as a stored ZIP and scans that too, so the largest archive the application admits, four members of 128 MiB (512 MiB, in a container of 512 MiB and 406 bytes), needs its total twice. With 1024M ClamAV ended that scan as `Heuristics.Limits.Exceeded.MaxScanSize`, after 75 seconds; with 1025M it scanned to the end and reported `OK`. The application derives the size, twice `media.stems.max_total_bytes` plus 256 MiB of headroom, in `MalwareScanner::limitMebibytes()`, and a test fails while the `clamd.conf` values and the command line in this guide differ from it. 768M, the size first tried, scans the largest upload (196 MiB, deflated) but not the ZIP the worker rebuilds from it: the largest stems were then refused as `scan_not_clean`. Keep the two sizes equal. ClamAV cuts an oversize archive member down to `--max-filesize` without an alert, and only a spent scan budget turns the cut into one: with 512M and 1G, a deflate bomb of 1 GiB of zeros in a 1 MiB ZIP was reported `OK`. `--alert-exceeds-max=yes` makes a file over a limit an error, not a pass.
- **3 GiB of address space.** Loading the signatures takes 1.08 GiB. The ZIP the worker rebuilds from the largest stems (512 MiB) took 1.7 GiB, the upload it came from (196 MiB, deflated) 1.4 GiB, a deflate bomb of 1 GiB of zeros in a 1 MiB ZIP 2.1 GiB, and one of 1270 MiB, the largest the limit lets through, 2.3 GiB. Below about 1.1 GiB the scanner cannot load its database at all.
- **180 CPU seconds and 300 seconds.** The rebuilt ZIP took 77 to 85 seconds of CPU and 79 to 87 of wall time, the upload 52 to 54 seconds of CPU and each 128 MiB member 17 to 20, against the 90 seconds every other tool gets. That leaves a little over twice the headroom for the largest legitimate scan on this host, less on a slower or busier one. Hostile files use more: the 1 GiB bomb took 132 seconds of CPU and the 1270 MiB one 167, 13 under the limit, so on a slower host such a file is killed by the CPU limit and fails as `scanner_unavailable`, not as a detection. ClamAV's own time limit for `clamscan` is 120 seconds and it reports a scan that takes longer as a detection (`Heuristics.Limits.Exceeded.MaxScanTime`): the 1 GiB bomb ended that way after 138 seconds. The application therefore gives `--max-scantime` the value of the wall-clock limit, so that a scan that is only slow waits for a retry and does not fail for good.

### `clamd.conf`

The packaged `/etc/clamav/clamd.conf` of Ubuntu 24.04 must not be used as it is. It **fails open**: with `MaxFileSize 25M`, `MaxScanSize 100M`, `MaxScanTime 120000` and no `AlertExceedsMax`, a file over a limit, and a scan that outlasts the time limit, is skipped and answered `OK`. A 30 MiB ZIP with an EICAR member and an 800 MiB file both came back `OK` from it. So did a 400 MiB ZIP with its EICAR member last, once the scan ran past `MaxScanTime` (set to 2 seconds for the test): with `AlertExceedsMax yes` the same scan reports `Heuristics.Limits.Exceeded.MaxScanTime` and exits 1, and with the recommended 240 seconds it finishes and reports `Eicar-Test-Signature`. It also sets `EnableVersionCommand false`, so `clamdscan --version` prints only `ClamAV <version>` and the application refuses every scan as `scanner_signatures_stale`, and `LocalSocketMode 666`, which lets any local user scan through the daemon. Set these:

| Setting | Value | Why |
| --- | --- | --- |
| `LocalSocket` | `/run/clamav/clamd.ctl` | where `clamdscan` looks by default |
| `LocalSocketGroup`, `LocalSocketMode` | `clamav`, the daemon's own group, and `660`, with the worker's user in that group | only the worker may use the daemon; packaged: mode `666`. The worker's own group does not work: see below |
| `EnableVersionCommand` | `yes` | the application reads the signature date from the daemon's answer; packaged: `false` |
| `MaxFileSize`, `MaxScanSize` | `1280M` and `1280M` | as for `clamscan` above; packaged: `25M` and `100M`. Keep them equal |
| `AlertExceedsMax` | `yes` | a file over a limit, or a scan that outlasts `MaxScanTime`, is reported as `Heuristics.Limits.Exceeded` (exit 1, so `scan_not_clean`), not skipped; packaged: not set |
| `MaxScanTime` | `240000` | milliseconds; keeps the daemon's give-up time under the 300-second wall limit of the client |
| `MaxThreads` | at least twice the number of media workers: see below | each scan of a large archive added about 250 MiB resident and can extract up to 1280 MiB to disk |
| `TemporaryDirectory` | a disk-backed directory the daemon owns, such as `/var/tmp/clamd` | recommended, not measured |
| `ConcurrentDatabaseReload` | `yes` (the default) | scans keep running during a reload, at the memory cost under host sizing |

The daemon runs as `clamav` and can give its socket only a group that `clamav` belongs to. With `LocalSocketGroup daemon`, a group it is not in, `clamd` started as root stopped at once with `Failed to change socket ownership to group daemon` and left a socket file without permissions behind, which `FixStaleSocket true` removes at the next start; the worker's group fails the same way. With `LocalSocketGroup clamav` and `LocalSocketMode 660` the socket was `srw-rw---- clamav clamav`, and a user with no groups got `Permission denied` from `clamdscan` where the same user with `clamav` as a supplementary group scanned a file with `--fdpass` and got `OK`. So add the worker's user to the group `clamav` (`usermod -aG clamav <worker user>`, which was not run here: the membership was tested with `setpriv --groups=clamav`) and restart the worker, because a running process keeps the groups it started with. Adding `clamav` to the worker's group instead would also let the daemon use that group; that was not tried. Under systemd socket activation, as Ubuntu's `clamav-daemon.socket` provides, the socket is made by the socket unit and not by `clamd`, so its group and mode presumably come from `SocketGroup=` and `SocketMode=` there and not from these two lines. That was not tried either, because the test machine has no running systemd: check `ls -l /run/clamav/clamd.ctl` on the deployed host.

`clamd` keeps scanning for a client that has gone. A `clamdscan` that the application ended with SIGTERM, then SIGKILL, which is how it ends a scan when the archive budget or the wall-clock limit runs out (Symfony Process's `stop(0)`), left its scan running. Letting `BoundedMediaProcess` end a `clamdscan --fdpass` after 1 second, the daemon's CPU kept rising by a second each second for the 27 seconds that followed, and stopped only when the scan, of a ZIP with one 400 MiB member (350 MiB of random bytes, then 50 MiB of zeros), had taken 28 CPU seconds in all. A retry after an `archive_timeout` then competes with the scan it replaces. With `MaxThreads 1`, a tiny scan started right after the kill waited 27 seconds behind it; with 2, it took 0.04. A scan that outlives its client can go on for up to `MaxScanTime` (240 seconds), and each media worker can leave one behind while it starts the next, so set `MaxThreads` to at least twice the number of media workers, and size the host for that many scans at once.

### Whether the daemon refuses what it cannot scan

Nothing in a daemon's answer to a clean file says whether `AlertExceedsMax` is set, and without it the limits above turn a file the daemon cannot scan into `OK`. So before the application trusts any answer from `clamdscan` it asks the daemon to scan a canary: a sparse file of 4 GiB and a byte, made in the scan's own workspace and removed afterwards. It holds no data and, on a filesystem that keeps holes in a file, takes no disk; one that does not is refused, as described below. A daemon that alerts refuses it whatever its limits are, with `Heuristics.Limits.Exceeded.MaxFileSize FOUND` and exit status 1 in 0.01 second. ClamAV 1.5.4 did so with the limits at `25M`, `1280M`, `4000M`, `4096M`, `8192M` and `0` (no limit), because the engine's own ceiling is below 2 GiB; without `AlertExceedsMax` it answered `OK` at each of them. Anything else, an `OK`, another line, an error, a signal or no answer within 30 seconds, fails the scan as `scanner_unavailable` before the upload is handed to the daemon, and the worker log says what the daemon did and names the fix: `MaxFileSize` and `MaxScanSize` at 1280M and `AlertExceedsMax yes`.

The canary runs on every scan, right after the version call, and nothing is remembered: it took a median of 6.9 to 7.3 ms (p90 8.7 to 9.3, p99 10.9 to 14.3) over two runs of 300 calls against the tuned daemon here, next to 6.6 to 6.8 ms for the version call, so a daemon that is reconfigured or replaced is caught by the next scan. `php artisan vasey:doctor` runs the same check as `media_scanner_limits`, with its canary made where a scan makes its own, in a scratch directory of private storage, and not in the system's temporary directory, which is often tmpfs and keeps holes where the disk under private storage may not. It removes that directory when it is done, and `processing/` too if it had to make it. It makes nothing where private storage does not exist, or for a user who does not own it, so run it as the worker's user (`sudo -u <worker user> php artisan vasey:doctor`): a run that a signal interrupts skips its cleanup, and the `processing/` that root left behind would stop a worker of another user from making its own workspace, which fails every media run until someone deletes it. It shows that the daemon alerts, not that its limits are large enough: a daemon with `AlertExceedsMax yes` and the packaged 25M refuses every large upload as a detection, which step 6 of the acceptance list finds. A canary of one byte over the application's own limit would not do, because a daemon with a higher limit scans it, for minutes. The worker itself must be allowed to write a file of 4 GiB (`ulimit -f`, `LimitFSIZE=`), and PHP's `posix` extension must be loaded, which is how the application reads that limit: a worker under a lower one would be ended by SIGXFSZ when it extends the file, so when the limit is too low or cannot be read the check fails without making the file.

Private storage must keep holes in a file. On a filesystem that does not, making the canary would write all 4 GiB of zeros for every scan, which takes time and, on a small disk, fails for want of room. So the file is grown in two steps, to 64 MiB and then to its whole size, and after each the application reads how much the filesystem has allocated to it (the count of 512-byte blocks that `fstat` reports). More than 1 MiB allocated refuses the canary before the daemon is asked: the scan fails as `scanner_unavailable` after at most 64 MiB has been written instead of 4 GiB, and the worker log says what is wrong with the filesystem. A file whose allocation cannot be read counts as allocated in full. ext4, ext2 (on a loop device), tmpfs and ramfs allocated nothing at either step here: 0 bytes at 64 MiB and at 4 GiB and a byte. The limit is about 250 times one 4 KiB block, so the few blocks that a filesystem may keep for a file's metadata pass, and it is a sixty-fourth of what a filesystem without holes shows at the first step. No such filesystem was at hand to measure, so that refusal is tested with a stand-in that reports the file as allocated in full, and not on a real one. On ext4, the tuned `clamd` refused a canary made in two steps as before, and `vasey:doctor` passed and left nothing behind, with `processing/` there and without it.

The canary shows that the daemon alerts on a file over its limits, and nothing else about what it scans. A daemon that does not look inside archives passes it. With `AlertExceedsMax yes` and `ScanArchive no`, the canary and `vasey:doctor` passed, an EICAR file was detected, and the same EICAR in a ZIP read `OK` from `clamdscan` and from the application's scan alike. So keep `ScanArchive` and the other `Scan*` options at their defaults of `yes` (`ScanPE`, `ScanELF`, `ScanMail`, `ScanHTML`, `ScanOLE2`, `ScanPDF`, `ScanSWF`, `ScanXMLDOCS`, `ScanHWP3`, `ScanOneNote` and `ScanImage`; the packaged file has them on); the application does not check them. Step 3 of the acceptance list does catch `ScanArchive no`: the ZIP with an EICAR member then reads `OK` where it must report `FOUND`.

Keep `freshclam` running as a daemon and confirm that the daemon reloads after an update: step 1 of the acceptance list shows the signature date the daemon reports. `media.max_signature_age_seconds` (48 hours) measures the build time of the newest daily database, not the time of freshclam's last check, so alert well before the limit. The upstream publishing cadence was not measured; 24 to 36 hours of age is a starting point.

### Time zone of `clamd`

`clamdscan --version` prints the signature time in the time zone of the daemon, and the application reads it as UTC. The application starts every tool it runs, `clamscan` included, with `TZ=UTC`, but it cannot change the zone of a daemon that is already running. A daemon in `Asia/Tokyo` printed `15:24:18` at 13:24 UTC for signatures built at 06:24 UTC, and the application refused them as dated in the future until they were eight hours old; a daemon west of UTC reports them older than they are. Both directions matter. East of UTC, the application also accepts signatures too long: it reads the time it is given as UTC, so it sees them as younger by the zone's offset, and at `Asia/Tokyo` accepts signatures up to 57 hours old under its 48-hour limit, silently (worked out from the two readings, not run with signatures that old). Step 1 of the acceptance list compares the two. Give the daemon's service a zone of UTC with a drop-in:

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
- Leave disk for extraction: up to `MaxThreads` times 1280 MiB in `TemporaryDirectory`. That is an upper bound from the limits, not a measurement.

### Acceptance list

Run these on the deployed host as the worker's user, with the configured binary and its options (`--fdpass` for `clamdscan`), before relying on the scanner. Assemble the EICAR test string at run time from its published text, in a scratch directory outside the repository. Check that a test ZIP is really scanned before trusting its result: ClamAV did not scan the members of ZIPs written with Python's `ZipFile.open(..., force_zip64=True)` and answered `OK`, which passed the EICAR member and the bomb below. The same archives written without `force_zip64` were scanned and detected.

1. `clamdscan --version` prints `ClamAV <version>/<number>/<date>`; the date is within 48 hours of `date -u`, and it is the date that `TZ=UTC clamscan --version` prints for the same signature files. A daemon in `Asia/Tokyo` printed 15:24:18 where `clamscan` printed 06:24:18, and a daemon east of UTC makes the application accept signatures that many hours past its 48-hour limit: see [Time zone of `clamd`](#time-zone-of-clamd). Straight after a `freshclam` update, before the daemon has reloaded, the two can also differ for that reason.
2. The EICAR test file reports `Eicar-Test-Signature FOUND` and exits 1.
3. A ZIP over 25 MiB with an EICAR member reports `FOUND`. This catches the packaged limits, and `ScanArchive no`, which the canary cannot.
4. A sparse file over the limit (`truncate -s 1300M big.bin`) reports `Heuristics.Limits.Exceeded.MaxFileSize FOUND`, at once. With `clamscan` it prints that line, then `Virus(es) detected ERROR`, and exits 2, which the application treats as a scanner problem, not a detection.
5. A deflate bomb over the limit, 1400 MiB of zeros in a ZIP of about 1.4 MiB, reports `Heuristics.Limits.Exceeded.MaxScanSize FOUND`: after 10 seconds from the daemon, 34 from `clamscan`. A bomb under the limit is scanned, not refused (1 GiB of zeros reported `OK` after 120 seconds from the daemon and 135 from `clamscan`), so it is not a test of the limits.
6. The largest stems the application admits, processed end to end. Build a ZIP of four members of 128 MiB each (512 MiB expanded), each with about 49 MiB of random data and zeros for the rest, which deflates to just under 200 MiB. Upload it as a stems ZIP on a draft track and process it. The run must complete: the upload, each member and the stored ZIP of 512 MiB that the worker rebuilds from them and scans again must all scan clean. Scanning only the upload, as this step once did, does not test the limits: that passes at 768M, where the rebuilt ZIP is refused as `scan_not_clean`. On the host of the figures above the run took 215 to 231 seconds with `clamscan`, 79 to 87 of them for the rebuilt ZIP, and 92 with `clamdscan`, 37 of them for the rebuilt ZIP. Scanned alone, the rebuilt ZIP needs 1025M of ClamAV 1.5.4; with 1024M it ends as `Heuristics.Limits.Exceeded.MaxScanSize`.
7. Stop the daemon. The application must report `scanner_signatures_stale` or `scan_not_clean` for both a clean file and the EICAR file, never a clean result: `clamdscan --version` then prints an error and only `ClamAV <version>`.
8. `php artisan vasey:doctor` as the worker's user reports `media_scanner_limits` as `pass`. With the packaged limits it is a warning, and the worker log names the fix.

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
| `scanner_unavailable` or `scanner_signatures_stale` | The scanner is missing, reported an error, crashed, is not ClamAV, or has out-of-date signatures; it exited 0 without the exact clean line (for example with a warning of its own, such as a deprecated option after an upgrade, in its output); or its standard output ran past the 256 KiB limit, or its warnings on standard error past 4 MiB. Fewer warnings than that do not affect the result. A scan that runs out of time reads `processor_timeout`. None of these says anything about the file. Fix the scanner, its configuration or its signatures, then retry. `scanner_unavailable` is also what a `clamd` that does not refuse an oversize test file reads, before the upload reaches it. `scanner_signatures_stale` is also what a signature date more than an hour in the future reads, which a `clamd` in a zone east of UTC prints, and what `clamdscan` produces when the daemon is not running or has `EnableVersionCommand false`. The worker log says which of these it was, and why a scan came to nothing: see [What the worker log says about the scanner](#what-the-worker-log-says-about-the-scanner). |
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

A scan that comes to nothing writes one warning to the worker log, and the message says which of six things happened. A line quoted from the scanner has the upload's directory replaced by `<dir>`, is printable ASCII and at most 200 characters, and only its first non-empty line is kept. None of it reaches the message shown in the admin.

- `The malware scan did not finish.` The scanner, or its version call, failed, was killed or ran out of time. `reason` is the failure code. `exit_code` is the tool's status, 128 plus the signal for a tool a signal ended, so 137 is SIGKILL, which is how the CPU limit ends a scan. `error_line` and `output_line` are the first line it wrote to standard error and to standard output. The scan runs with `--stdout`, so `clamscan` and `clamdscan` report their own errors on standard output and libclamav's on standard error.
- `The malware scanner signatures were refused.` This is `scanner_signatures_stale`. `version` is the first line `--version` printed, `signature_time` the time the application read from it (UTC), and `age_seconds` how old that makes the signatures, negative for a date in the future. `error_line` is the first line `--version` wrote to standard error.
  - `signature_time` and `age_seconds` are `null`, and `version` is `ClamAV <version>` alone: the scanner printed no date. `error_line` says why, and it is the only thing that does: `Could not connect to clamd` for a daemon that is not running, `VERSION command disabled` for `EnableVersionCommand false`.
  - `age_seconds` is negative, by hours: the scanner prints its date in a zone east of UTC. For `clamd` that is the `TZ` of its service (see "Time zone of `clamd`" above).
  - `age_seconds` is over `media.max_signature_age_seconds`: the signatures really are old. Check `freshclam` and that the daemon reloaded.
- `The malware daemon was not shown to refuse a file over its size limits, so none of its answers is trusted.` (with the fix appended) The `clamd` behind `clamdscan` did not pass the canary check, so the upload was never handed to it: see [Whether the daemon refuses what it cannot scan](#whether-the-daemon-refuses-what-it-cannot-scan). The failure is `scanner_unavailable`. `reason` says what happened instead of a refusal: `daemon_answered`, an `OK` (the packaged limits, or no `AlertExceedsMax`), `processor_failed` with an `exit_code` of 1 (another verdict) or 2 (an error), `processor_timeout` or `processor_signaled`. `output_line` is the first line the daemon wrote about it, `<dir>/.limit-canary-<random>: OK` for `daemon_answered`.
- `The malware daemon could not be checked: <why>.` The canary could not be made, so the daemon was never asked and no `clamd` setting is blamed. The failure is `scanner_unavailable` and `reason` is `storage_failed`. `<why>` says which of these it was: the worker's file-size limit is lower than 4 GiB, or cannot be read without PHP's `posix` extension (`ulimit -f`, `LimitFSIZE=`); the file could not be created, or protected, in the workspace (the worker may not write there, or the disk is full); the filesystem does not take a file of 4 GiB, or has no room for it; or it does not keep holes in one, and more than 1 MiB of it was allocated (keep private storage on ext4, XFS or btrfs).
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
