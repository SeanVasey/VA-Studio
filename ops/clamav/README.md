# ClamAV: production scanner profile (clamd + clamdscan)

Opt-in templates for the scanner that `docs/media-processing.md` ("Production malware scanner") prefers over
per-call `clamscan`. They serve the staging kit and the production kit alike. **Nothing here runs unless an operator
runs it:** `ops/staging/provision.sh` and the production kit keep `clamscan` (the documented fallback), and neither
touches these files.

| File | What it is | Installed to |
| --- | --- | --- |
| `clamd.conf` | Complete daemon configuration with the documented limits | `/etc/clamav/clamd.conf` |
| `systemd/clamav-daemon.service.d/timezone.conf` | `TZ=UTC` for the daemon (its signature time is read as UTC) | `/etc/systemd/system/clamav-daemon.service.d/` |
| `systemd/clamav-daemon.socket.d/vasey-socket.conf` | Socket mode `0660` (the packaged socket unit leaves systemd's default `0666`) | `/etc/systemd/system/clamav-daemon.socket.d/` |
| `install-clamd.sh` | Installs the above, adds the worker to the group `clamav`, restarts, verifies. `--dry-run` shows every step | run in place, as root |
| `verify-clamd.sh` | Acceptance steps 1 to 4 of the guide, as the worker's user, against the running daemon | run in place |

```
sudo bash ops/clamav/install-clamd.sh --worker-user forge --dry-run     # review
sudo bash ops/clamav/install-clamd.sh --worker-user forge               # install, restart clamd, verify
sudo bash ops/clamav/verify-clamd.sh --as forge                         # any time later
```

## What `clamd.conf` sets, and why

Every value comes from the guide; the figures were measured with ClamAV 1.5.4 on Ubuntu 24.04.

| Setting | Value | Reason |
| --- | --- | --- |
| `MaxFileSize`, `MaxScanSize` | `1280M`, `1280M` (kept equal) | The worker rebuilds every stems ZIP and scans it too, so the largest admitted archive needs its expanded size twice. The application derives the figure in `MalwareScanner::limitMebibytes()`. |
| `AlertExceedsMax` | `yes` | A file over a limit, or a scan that outlasts `MaxScanTime`, is an error (`Heuristics.Limits.Exceeded`, exit 1) and not an `OK`. The application's canary refuses a daemon that does not do this. |
| `MaxScanTime` | `240000` ms | Keeps the daemon's give-up time under the client's 300-second wall limit. |
| `MaxThreads` | `4` | At least twice the media workers (the kit runs one): `clamd` keeps scanning for a client the application killed, and a retry would queue behind it. `install-clamd.sh --media-workers N` raises it to `2N` when larger. |
| `LocalSocket*` | `/run/clamav/clamd.ctl`, group `clamav`, mode `660` | Only the worker may scan. |
| `EnableVersionCommand` | `yes` | The application reads the signature date from the daemon's answer (packaged: `false`, which makes every scan `scanner_signatures_stale`). |
| `ScanArchive` and the other `Scan*` | `yes` | `ScanArchive no` passes the canary and reads `OK` for an EICAR member in a ZIP. |
| `ConcurrentDatabaseReload`, `SelfCheck` | `yes`, `600` | Scans continue through a reload; a changed database is noticed within 10 minutes. |
| `TemporaryDirectory` | `/var/tmp/clamd` | Disk-backed and owned by the daemon; up to `MaxThreads` x 1280 MiB of extraction. |
| Logging | syslog, no `LogFile` | The journal collects it; nothing to rotate. |

`clamconf -c <dir containing clamd.conf> -n` parses the file with the installed ClamAV and rejects an unknown option.

## The worker's membership of the group `clamav`

The daemon runs as `clamav` and can give its socket only a group that `clamav` belongs to, so the worker's user is added
to that group: `usermod -aG clamav <worker user>` (the installer does it). **Restart the worker afterwards**: a running
process keeps the groups it started with, and so does PHP-FPM. Supervisor gives a program the user's groups as they are
when it starts it. A user outside the group gets `Permission denied` from `clamdscan`. The worker must also be allowed to
write a file of 4 GiB (`ulimit -f`, `LimitFSIZE=`) for the application's daemon canary, and PHP's `posix` extension must
be loaded.

## Things the installer does that the guide could not know

Found while preparing this profile (Ubuntu 24.04, ClamAV 1.5.4); each is covered by `install-clamd.sh`.

- **`clamdscan` is a separate package.** `clamav-daemon` only recommends it, and the kit installs with
  `--no-install-recommends`: without `apt-get install clamdscan` there is no `/usr/bin/clamdscan` and every scan fails.
- **The packaged socket unit is world-accessible.** `/usr/lib/systemd/system/clamav-daemon.socket` sets `SocketUser` and
  `SocketGroup` to `clamav` and no `SocketMode`, so under socket activation (the Ubuntu default) the mode is systemd's
  `0666` whatever `clamd.conf` says. The socket drop-in sets `0660`; the installer refuses to continue unless
  `stat -c '%a %U:%G' /run/clamav/clamd.ctl` prints `660 clamav:clamav`.
- **AppArmor.** Ubuntu's `/etc/apparmor.d/usr.sbin.clamd` allows `/tmp`, `/var/lib/clamav` and a few spool directories, not
  `/var/tmp/clamd` and not the private storage tree. The installer adds a marked block to
  `/etc/apparmor.d/local/usr.sbin.clamd` and reloads the profile. Whether AppArmor re-checks a descriptor passed with
  `--fdpass` against the daemon's profile was not testable without an AppArmor host: if a scan fails with `Permission
  denied` or `scanner_unavailable` after the group is right, run `dmesg | grep DENIED`.
- **freshclam warns on every update:** `Clamd was NOT notified: The RELOAD command is disabled`. That is expected. The
  daemon reloads itself within `SelfCheck` of an update; enabling the socket's `RELOAD` command would let any group member
  trigger a 2 GiB reload peak.

## Signatures: schedule and alerting

`ops/staging/provision.sh` sets `Checks 24` in `freshclam.conf` (hourly) and enables `clamav-freshclam`. The application
refuses signatures older than 48 hours (`media.max_signature_age_seconds`) and measures the build time of the newest daily
database, not freshclam's last check, so alert well before the limit: `ops/monitoring/signature-age-check.sh` warns at
36 hours and goes critical at 44. These are starting points, not established thresholds (`docs/private-server-readiness.md`).

## What was and was not verified

Run against a real `clamd` 1.5.4 with the official signatures and this file's limits, started in the foreground as
`clamav` on a private socket (see `docs/verification/production-ops-kit-20261017/README.md`): the socket came up as
`srw-rw---- clamav clamav`, `verify-clamd.sh` steps 1 to 4 passed, a user outside the group was refused, and a daemon
started with `TZ=Asia/Tokyo` was caught by the time-zone comparison. **Not verified here:** the systemd drop-ins and
socket activation (no systemd in the test container), the AppArmor behaviour above, the installer end to end, and steps 5
to 8 of the acceptance list. The AppArmor block is syntax-checked with `apparmor_parser -Q -K`.
