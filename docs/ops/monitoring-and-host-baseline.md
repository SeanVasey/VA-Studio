# Monitoring checks, host baseline, and the CDN and proxy note

Scripts and notes for the signals `docs/private-server-readiness.md` ("Observability and operations") says a host must have.
They serve the staging and production kits alike (`--kit staging|production` picks `/etc/vasey-<kit>/<kit>.conf`). **Nothing
installs or schedules them**: an operator copies them to a host and decides who is alerted. That document also says no
threshold, uptime target or alert destination is established, so every number below is a starting point for the responsible
operator to confirm, not a policy.

## The checks

All exit like a Nagios plugin: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN (an unreadable answer is never reported as OK). They print
counts, ages and queue names only: no payloads, job names, paths of private files, credentials or URLs.

| Script | Reads | Alerts when |
| --- | --- | --- |
| `ops/monitoring/queue-age-check.sh` | `jobs` and `failed_jobs`, over a `READ ONLY` MySQL session, `SELECT` only, never the `payload` column | the oldest ready job of a queue has waited 300 s (WARN) or 900 s (CRIT); a job has been reserved over 1,200 s (the required `DB_QUEUE_RETRY_AFTER`) or 2,400 s; `failed_jobs` gained 1 (WARN) or 10 (CRIT) rows in 24 h |
| `ops/monitoring/scheduler-liveness-check.sh` | supervisor status, `/proc/<pid>/cwd`, the journal (or a log file) | the scheduler program is not RUNNING; it runs from a release other than `current`; no `vasey:publish-scheduled-site-release` tick appears within 180 s. `--workers` adds the four queue workers |
| `ops/monitoring/signature-age-check.sh` | the scanner's `--version` line, run with `TZ=UTC` as the application does | signatures are 36 h (WARN) or 44 h (CRIT) old, or dated more than an hour in the future (a daemon in a time zone east of UTC). The application refuses them at 48 h |
| `ops/monitoring/host-baseline.sh` | `ufw`, `ss`, apt configuration, `sshd -T` | see "Host baseline" below |

Options and overrides are in each script's header (`--warn`, `--crit`, `--queue-threshold media=900:3600`, `--failed-hours`,
`--max-silence`, `--warn-hours`). The media job may legitimately hold a reservation for 900 s, so raise its queue's thresholds
rather than the global ones if it is noisy.

### Heartbeat

`queue-age-check.sh --heartbeat-url-file FILE` requests one URL when the result is OK or WARN and **stays silent on CRIT**, so a
dead cron, a dead host and a critical result all end in the silence a dead-man's-switch service alerts on. The file must be a
root-owned `0600` file holding one `https://` URL; the URL is a secret and is never printed or logged. Which service receives it
is Sean's choice; none is selected and none is configured.

### Scheduler: what can and cannot be known

The application has **no scheduler heartbeat**. `routes/console.php` runs `vasey:publish-scheduled-site-release` every minute
with a short overlap lock, and nothing it does leaves a durable record when no release is due. The check therefore combines
three signals that need no application change: supervisor state, the process's working directory against `current`, and the line
`schedule:work` prints each minute (`Running ['artisan' vasey:publish-scheduled-site-release]`, observed locally). Production
workers log through supervisor's `stdout_syslog`, which prefixes each line with the program name; staging writes
`/var/log/vasey-staging/scheduler.log`, so use `--log-file` there. A real dead-man's-switch for the scheduler would be a reviewed
application change (a ping on schedule success).

### Installing and scheduling (example, not installed by the kit)

```sh
sudo install -d -m 0755 /usr/local/libexec/vasey-production/monitoring
sudo install -m 0755 -o root -g root ops/monitoring/*.sh /usr/local/libexec/vasey-production/monitoring/
# /etc/cron.d/vasey-production-monitoring  (an example; choose the alert path first)
*/5 * * * * root /usr/local/libexec/vasey-production/monitoring/queue-age-check.sh --kit production --heartbeat-url-file /etc/vasey-production/heartbeat.url 2>&1 | logger -t vasey-queue-age
* * * * *   root /usr/local/libexec/vasey-production/monitoring/scheduler-liveness-check.sh --kit production --workers 2>&1 | logger -t vasey-scheduler-liveness
17 * * * *  root /usr/local/libexec/vasey-production/monitoring/signature-age-check.sh --scanner /usr/bin/clamscan --as forge 2>&1 | logger -t vasey-signature-age
```

The queue check reuses the backup account's defaults file (`SELECT` on the whole schema, read only). If that is more than a
monitor should hold, create `vasey_monitor@127.0.0.1` with `SELECT` on `jobs` and `failed_jobs` only and pass it with
`--defaults-file`.

### Not covered here

Web error rate and latency, paid-to-fulfilled delay, document and delivery failures, private-disk capacity and integrity,
database health and backup freshness (the restore proof's `RESTORE_CHECK` age is the obvious signal). They are listed in the
readiness guide and have no script yet.

## Host baseline

`host-baseline.sh check` verifies, read-only: `ufw` is active, denies incoming and allows outgoing by default, and allows only
the SSH port, 80 and 443; nothing but those ports listens beyond loopback and MySQL (3306) never does; `unattended-upgrades` is
installed, enabled and covers the security pocket; `apt-daily.timer` and `apt-daily-upgrade.timer` are both enabled and active
(`systemctl is-enabled` says `enabled` and `is-active` says `active`; without them the periodic settings never run, so either one
missing is a WARN); no reboot is pending; `sshd` refuses password logins and root password logins.

Two actions change the host and each only prints its plan unless given `--yes`:

- `apply-unattended-upgrades` installs the package and writes `/etc/apt/apt.conf.d/52vasey-unattended-upgrades`: security origins
  only, `Automatic-Reboot "false"`, and a blacklist for `mysql-.*` and `php8\.4-.*`. Those packages restart services the queues and
  deploys depend on, so upgrade them in a window after `vasey-<kit>-ctl quiesce`. The file is an operator decision: remove
  the blacklist lines to let them upgrade automatically. Kernel and libc updates then need a reboot, which the check reports.
  It then runs `systemctl enable --now apt-daily.timer apt-daily-upgrade.timer`; if that fails, the action fails (exit 2, no
  "applied"), because the drop-in does nothing without the timers.
- `apply-firewall --ssh-port N` sets default deny incoming, allow outgoing, allows `N`, 80 and 443 and enables ufw. It refuses
  without an explicit port, and refuses when run over an SSH session that connected on a different port.

A Forge server already manages `ufw` from Forge's Firewall tab (the provisioning step leaves it at 22/80/443), so on Forge `check`
is the normal use; rules added by `apply-firewall` do not appear in Forge's UI. Whether Forge configures unattended upgrades by
default was not verified here: run `check` on the real server.

## CDN and proxy

A CDN or reverse proxy in front of the store must not carry the paid-delivery or upload paths.

- **Timeouts.** Cloudflare's documented proxy read timeout is **125 seconds** (error 524), raisable only on Enterprise plans, up
  to 6,000 s; the proxy write timeout is 30 s and fixed; the idle timeout is 900 s
  ([error 524](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-5xx-errors/error-524/),
  [connection limits](https://developers.cloudflare.com/fundamentals/reference/connection-limits/), read on 2026-10-17).
  The paid routes need 480 s to first byte (`/paid-grants/authorizations/{uuid}/redeem`) and up to 3,900 s for the whole request
  (`/paid-grants/origins/{uuid}/document`). A proxy that gives up first leaves the server free to commit a redemption the buyer
  never receives (`docs/ops/paid-delivery-runtime.md`, requirement 2). **Paid download routes must bypass the proxy.**
- **Uploads.** Cloudflare also caps the request body per plan; a staff reply in its community lists 100 MB on Free and Pro, 200 MB
  on Business and 500 MB on Enterprise (not on the official connection-limits page: verify before relying on it). The
  200 MiB master and stems uploads (`/livewire-<hash>/upload-file`) exceed the lower caps, so operator uploads must bypass it too.
- **What "bypass" means in practice.** Cloudflare proxies per hostname, not per path. Either leave the store hostname unproxied
  (DNS only) until a reviewed change exists, or serve paid delivery and operator uploads from a separate unproxied hostname. The
  second needs an application change (routes, cookies and `APP_URL` assume one origin), so it is a reviewed change, not a setting.
- **`trustProxies` is not configured in the application.** Behind any proxy, Laravel would see the proxy's address and scheme:
  rate limits would key on the proxy, `HTTPS` detection and secure-cookie behaviour depend on forwarded headers, and the access
  log would name the proxy. Configuring it is **a required, reviewed application change, and it is not implemented here**: a
  reviewed list of proxy addresses (never "trust all"), the forwarded headers to honour, and tests that a client outside the list
  cannot choose its own address. The nginx template carries only a commented illustration of `set_real_ip_from`; do not enable it
  before that change.
- **Order of work if a CDN is wanted:** first the reviewed `trustProxies` change, then a decision on which hostnames are proxied,
  then a live check with a synthetic 1 GiB redemption through the real path (the verification list in
  `docs/ops/paid-delivery-runtime.md`) before any real buyer depends on it.

## What was verified, and what was not

Each script was run for real in bash against scripted stand-ins for `mysql`, `supervisorctl`, `journalctl`, `ufw`, `ss`,
`apt-config`, `sshd` and `systemctl` (`tests/ops/test_monitoring_scripts.py`, 44 tests, including that the queue session is `READ ONLY`,
that no secret or URL reaches the output, that the real `apt-config` parses the unattended-upgrades drop-in, and that a timer that
cannot be enabled fails the apply). The queue SQL
was run against a real MySQL 8.4.11 (evidence packet). Not verified: any real host, a real supervisor on the host, the journal's
tagging of supervisor's syslog lines, Forge's ufw and unattended-upgrades defaults, cron timing, or any alert destination.
