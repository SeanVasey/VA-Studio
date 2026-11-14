# Off-host backup hardening (opt-in)

`ops/staging/backup.sh` (and the production kit's derived copy) takes a nightly or pre-deploy backup, proves it restores on a
disposable MySQL, and optionally copies the set off the host with rsync over SSH. This guide covers four **opt-in** additions.
Every one is off unless `backup.conf` (below) turns it on. With no `backup.conf` the script behaves as before: the same
restore proof, and a plain `rsync` of the whole set to `VASEY_BACKUP_DEST` when that is set.

**Shipping happens after the site is back up (2026-11-11).** `predeploy` (every deploy) and the nightly snapshot no longer copy
anything off the host. They take the snapshot and the restore proof, queue the set as *pending* and return; the shipment then runs
detached with the site serving. See "Deferred shipping" below. This replaces the older behaviour in which `nightly` and `predeploy`
called `ship` inside the maintenance window.

| Addition | Setting | What it does |
| --- | --- | --- |
| Client-side encryption | `VASEY_BACKUP_AGE_RECIPIENTS_FILE` | Tars the verified set and encrypts it with [age](https://age-encryption.org) to public recipients before anything leaves the host |
| Encryption required | `VASEY_BACKUP_REQUIRE_ENCRYPTION=1` | Refuses to ship at all without recipients |
| rclone remote | `VASEY_BACKUP_REMOTE` | A named rclone remote as an alternative to rsync over SSH. **Refused without age recipients** unless `VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1` |
| Retention | `VASEY_BACKUP_RETENTION=tiered` | Keeps the newest sets plus daily, weekly and monthly representatives; optional pruning on the rclone remote |

Binary-log shipping for point-in-time restore is **not built**: it is a later step, described at the end.

## Configuration: `backup.conf`

The settings live in `/etc/vasey-staging/backup.conf` (production: `/etc/vasey-production/backup.conf`), not in `staging.conf`,
because `provision.sh` rewrites `staging.conf` on every run and would silently drop them, and a dropped `REQUIRE_ENCRYPTION`
would mean plaintext. The file is sourced as root, so it must be a root-owned regular file that is not group- or world-writable
and not a symlink; the script refuses otherwise. It holds no secrets (the rclone credentials are in the rclone config).

```sh
# /etc/vasey-production/backup.conf  (placeholders only; nothing here is a real recipient, remote or path)
VASEY_BACKUP_AGE_RECIPIENTS_FILE=/etc/vasey-production/backup-recipients.txt
VASEY_BACKUP_REQUIRE_ENCRYPTION=1
VASEY_BACKUP_REMOTE=vasey-backup:<bucket-or-path>      # or leave unset and use VASEY_BACKUP_DEST (rsync over SSH)
# VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=0              # leave at 0 (see "rclone remote"); 1 only for an rclone crypt remote
VASEY_BACKUP_RETENTION=tiered
VASEY_BACKUP_KEEP=7              # newest sets always kept (also the flat default)
VASEY_BACKUP_KEEP_DAILY=7
VASEY_BACKUP_KEEP_WEEKLY=4
VASEY_BACKUP_KEEP_MONTHLY=6
VASEY_BACKUP_REMOTE_PRUNE=0      # see "Retention"
```

Check it without sending anything: `sudo /usr/local/sbin/vasey-production-backup check-offhost` prints the effective encryption,
transport and retention, or the reason it refuses. It never prints a value.

## Encryption with age

- **Public key only on the host.** Generate the key pair on Sean's own machine (`age-keygen -o key.txt`), keep `key.txt` offline
  and backed up (if it is lost, every encrypted backup is unreadable), and put only the printed public key (`age1...`) in
  `backup-recipients.txt`, one per line, root-owned. Add a second recipient for a recovery key held separately. The script
  accepts native age public keys only, and **refuses a recipients file that contains `AGE-SECRET-KEY-` material**, so a private
  key cannot be left on the host by mistake.
- **What is encrypted.** The whole set directory as one `<set>.tar.age`: the dump, the private archive (masters, stems,
  contracts, seller tag), `env.backup` (which holds `APP_KEY`) and the manifests. A `<set>.tar.age.sha256` of the ciphertext
  travels beside it for transfer checks. Plaintext never leaves the host.
- **Only proven sets leave.** In the hardened path a set is shipped only if its `RESTORE_CHECK` says `RESTORE_VERIFIED`, and the
  name must be a well-formed set name. Without recipients, the default path is unchanged and does not require this.
- **Fail closed.** A missing `age`, a malformed recipient, an empty or non-age output, a failed transfer or too little free space
  stops the ship; nothing is sent as plaintext instead and the local set is untouched. The temporary encrypted copy is removed,
  except after a failed rclone transfer, where it is kept for the retry ("Retrying a failed rclone transfer", below).
- **One ship at a time.** Each hardened ship holds a lock on `backups/.offhost.lock` (a symlinked lock file is refused) while it
  cleans up, encrypts and transfers. A manual `ship` that finds it held refuses at once with "another off-host ship is running",
  before it removes or sends anything. `ship-pending` (below) runs with the site up, so it waits up to 3600 seconds for a manual ship
  and, if the lock is still held, records the set as `busy` (no failure, no dashboard row) for the next run, and the nightly run and deploys never wait for any ship at all: they only queue and start it. The nightly run
  and deploys never compete with each other: `.deploy.lock` already serializes them. Pruning (`nightly`, after the resume) runs outside
  this lock: it never touches staging directories or kept copies, and a manual ship of a set that pruning removes at that moment fails
  rather than ship anything else. The default path takes no such lock.
- **Cost.** Encryption streams `tar | age` into a temporary file under `backups/.ship.*` (0700), so it needs free space of about
  one more copy of the set; the script checks that first. A run killed mid-transfer can leave its `.ship.*` directory behind; the next
  hardened ship (under the lock, so no transfer is running) removes any that has been untouched for more than a day, and only real
  directories named exactly `.ship.` plus six characters (never a link). Encryption and upload no longer lengthen the maintenance
  window: they run after the resume (below). On staging (2026-11-11) the window had grown from the snapshot (8 minutes) and the
  restore proof (9) by a 14-minute ship, about 32 minutes of downtime, and a B2 `403 storage_cap_exceeded` failed the deploy before
  the switch and left the site down.

## Deferred shipping: pending sets, `ship-pending`, and what never fails

| Step | Where | What it does |
| --- | --- | --- |
| Snapshot, restore proof, queue | `predeploy` (every deploy, and the nightly run through `ctl snapshot`), site quiesced | After the proof it writes `backups/.offhost-pending.<set>` (0600, a few lines of text) and returns. **No age, no rclone, no rsync, no network.** A bad off-host configuration is only a logged warning here; a marker that cannot be written is a warning too |
| Start | after the healthy resume: `ctl activate` and `ctl refresh` (after the recovery marker is cleared), and the nightly run after `ctl resume` | `ship-pending-start` starts `ship-pending` as a transient systemd unit (`vasey-staging-ship-pending.service`, `systemd-run --collect`, nice 10), or without systemd-run as a `setsid` child with every inherited lock descriptor (`.deploy.lock`, the ctl and writer locks) closed. It returns at once and **never fails the deploy or the nightly run**: a start that fails is a warning, and the set simply stays pending |
| Ship | `ship-pending`, site up | Takes its own run lock (`backups/.ship-pending.lock`; a second start exits quietly and the running one also picks up sets queued meanwhile), then ships pending sets **newest first** through the same `ship`, so every guarantee below is the one that already held: the off-host lock, `RESTORE_VERIFIED` required in the hardened path, `REQUIRE_ENCRYPTION`, no plaintext to a remote, the retry copies, the already-shipped check. The first failure stops the run. Output goes to `/var/log/vasey-staging/ship-pending.log` (rotated weekly) |
| Evidence | end of `ship-pending` | `vasey:backup-record --kind=daily` as the application user: **failed** when a shipment failed; **ok** (the shipped set's size and dump hash) only when that set is the newest `RESTORE_VERIFIED` set and no snapshot failure (`backups/.snapshot-failed`, written by the nightly run on a failed snapshot or a refused space check) is newer than it, so an older set draining from the backlog cannot turn a failed nightly green. A deploy's set that is the newest proven set does record ok. The dashboard's accepted kinds are fixed (no free text), so the reason is **not** on the dashboard: it is in the log and in the marker (`pending` below) |
| Retry | the next nightly run (and any later deploy) | Every start picks up whatever is still pending, including sets an earlier failure left. A failed snapshot in the nightly run still starts the retry |

- **Nightly space check.** The nightly run now runs `space_check` before it quiesces. On refusal it skips the snapshot (the site was never stopped), writes the failure stamp, records `daily failed`, logs the reason, still starts `ship-pending` so the backlog can drain, and exits nonzero.
- **Remote retention** (`VASEY_BACKUP_REMOTE_PRUNE=1`) now runs at the end of a successful `ship-pending`, under its run lock and not while a manual ship holds `.offhost.lock`; the nightly run no longer calls it.
- **Dashboard semantics.** With an off-host destination configured, "last successful backup" now means *the set has its off-host copy*:
  the nightly run records `daily failed` right away if its own snapshot or proof failed, but it **does not** record `daily ok` itself;
  `ship-pending` does, when the transfer is verified. Until then the dashboard ages towards stale (`REPORTING_BACKUP_STALE_HOURS`, 26),
  and a failed shipment turns it red (latest daily run failed) until a later shipment succeeds. A deploy's shipment records the same
  way, so a failed deploy-set shipment is visible at once rather than at the next nightly run. Without a destination (the host
  keeps its sets locally only) the nightly run records `daily ok` immediately, as before.
- **A ship failure never fails an operation whose local proof succeeded.** The unit's failure, a 403 from the remote, a refused
  configuration and a full disk during encryption are logged, kept in the marker and recorded as failed evidence, and nothing else
  changes: the deploy has already finished, the nightly run has already resumed the site and returned.
- **Pruning never removes a pending set.** `prune_local` (flat and tiered; the nightly run, every deploy's retention and
  `backup-prune`) protects every set that still has a pending marker, as it protects the newest set and the newest
  `RESTORE_VERIFIED` set. Disk consequence: while the remote keeps failing, one set per deploy and per night stays beyond the
  retention count (each is about a set of disk; `ship-pending` warns when more than three wait). Fix the remote, or abandon a set
  deliberately with `pending-clear SET`, after which ordinary retention applies to it again.
- **Free-space accounting.** `space-check` (before any quiesce) no longer adds the encrypted-copy term on top of the restore proof.
  The two are sequential: the proof's scratch exists during the maintenance window and is removed before the resume, and the
  encrypted copy is created afterwards, so on one filesystem the peak is the set plus the larger of the two (two sets in all, where it
  used to be three). The term is kept, as a *later* need: on a separate
  filesystem for the restore scratch, the backup filesystem still needs room for the set and its encrypted copy, and a deploy the
  disk could not ship afterwards is refused before it stops anything. When a ship is still running (the off-host lock is held, so an
  earlier set's encrypted copy is still growing in `backups/.ship.*`), the check adds one more set, because that copy would otherwise
  be filling the disk while the next snapshot runs with the site down.
- **The rsync path** is deferred in exactly the same way (the marker, the start, the evidence). `rsync -a` of the plain set runs
  after the resume instead of inside the window. Hardened rsync (age to `VASEY_BACKUP_DEST`) is the same.
- **Operator commands** (as root; the deploy user may start a shipment with `sudo vasey-staging-ctl ship-pending`):

  ```sh
  sudo /usr/local/sbin/vasey-staging-backup pending                    # sets still waiting: queued, attempts, last result and reason
  sudo /usr/local/sbin/vasey-staging-backup ship-pending               # ship them now, in the foreground, site up (own run lock; waits for a manual ship)
  sudo /usr/local/sbin/vasey-staging-backup ship-pending-start         # the same, detached (what deploys and the nightly run use)
  sudo /usr/local/sbin/vasey-staging-backup ship /srv/vasey-staging/backups/<set>   # one set, as before; clears its pending marker when it succeeds
  sudo /usr/local/sbin/vasey-staging-backup pending-clear <set>        # give up on one set (it is not deleted; retention applies to it again)
  systemctl status vasey-staging-ship-pending; tail -n 50 /var/log/vasey-staging/ship-pending.log
  ```

- **Restore drill (on a machine that has the private key):**

  ```sh
  mkdir restore && age -d -i key.txt <set>.tar.age | tar -x -C restore
  ( cd restore/<set> && sha256sum --check --strict database.sql.sha256 env.backup.sha256 )
  ( cd restore/<set> && if [ -d private ]; then (cd private && sha256sum --check --strict ../private.sha256); else sha256sum --check --strict private.tar.sha256; fi )
  sudo /usr/local/sbin/vasey-production-backup restore-check <path to the extracted set>   # on a host with MySQL
  ```

  A backup is only proven when this drill has been done once with the real key: the host cannot decrypt, so the host's own
  restore proof covers the plaintext set before encryption, not the ciphertext.

## rclone remote instead of rsync over SSH

- Set `VASEY_BACKUP_REMOTE` to a **named** remote (`name:path`) defined in an rclone config that is a root-owned `0600` file
  (default `/root/.config/rclone/rclone.conf`, override with `VASEY_BACKUP_RCLONE_CONFIG`). Connection strings that carry
  options or keys on the command line (`:backend,key=value:`) are refused. Create the remote on the host with `rclone config`,
  entering credentials there and nowhere else.
- `VASEY_BACKUP_DEST` (rsync) and `VASEY_BACKUP_REMOTE` (rclone) are alternatives; setting both is refused.
- Encrypted sets go as two files, ciphertext first: `rclone copy --checksum --immutable --include /<set>.tar.age` from the kept copy
  to the remote, then the same for `<set>.tar.age.sha256`, then `rclone check --one-way` compares what arrived.
- **What `--immutable` guarantees with this invocation.** This script's upload never changes an object that already exists on the
  remote. An existing object whose content differs is refused (rclone exits 6, "immutable file modified") and left as it was. An
  identical one is accepted as already present ("Unchanged skipping") and not rewritten. Both were measured with rclone 1.60.1, the
  version in the test container, against a local-filesystem remote; the host's version was not checked. The script uses `copy` with
  an anchored filter, not `copyto`, because 1.60.1 does not enforce `--immutable` on `copyto`: there it replaced a differing object
  ("Copied (replaced existing)").
- **What it does not guarantee.** It is a check this script asks rclone to make before uploading: size and hash, or size only on a
  backend with no hash in common with the local disk (measured with a `chunker` remote set to `hash_type = none`, which logs
  "falling back to --size-only"). Every age encryption of one set has the same size, so on such a backend `--immutable` alone would
  take a different ciphertext for the one already there. The script therefore never relies on it for that:
  - **Presence check.** Before a fresh encryption it lists the remote with `rclone lsf --include /<set>.tar.age --include
    /<set>.tar.age.sha256`, which works on every backend, and refuses ("the remote already holds …", nothing sent or encrypted) if
    either name exists.
  - **Verification.** After the upload it requires `rclone check --one-way` to pass and the remote `<set>.tar.age.sha256`, read back
    with `rclone cat`, to equal the local digest. On a backend without hashes the ciphertext itself is still compared by size only;
    `rclone check --download` on the pair compares content when that matters.

  It does not stop anyone who holds the credential, an attacker on this host included, from deleting or replacing objects with other
  commands, and it cannot serialize writers on different hosts. **The no-overwrite credential below remains the primary control.**
- **Shipping a set again.** After a verified transfer the host records the digest and the ciphertext's size in
  `backups/.offhost-shipped.<set>`. A later `ship` of that set exits 0 with "<set> is already shipped" when both remote objects exist,
  the remote digest still equals the record and the remote ciphertext's size (`rclone lsf --format sp`) equals the recorded size; it
  encrypts and sends nothing and leaves no copy. Without the record, with a linked record, or when the digest or the size differs or
  the ciphertext is missing, it refuses before encrypting. The ciphertext itself is not re-read: on every backend this is a size
  check, not a content check, so a same-size replacement would still read as "already shipped". A record whose set has been pruned is
  removed by the next hardened ship.
- **Retrying a failed rclone transfer.** age never produces the same ciphertext twice, so once part of a set has reached the remote
  only its exact bytes can complete it. A failed rclone transfer therefore keeps the ciphertext, its digest and a digest of the
  recipients file in `backups/.offhost-retry.<set>` (0700, ciphertext and digests only). The copy is dropped only when the remote is
  listed afterwards and holds no `<set>.tar.age`; then a retry encrypts the set again. If the remote cannot be listed (an outage that
  interrupted the transfer, say), the ciphertext may have landed, so the copy is kept and the retry after the outage re-sends it.
  `vasey-<kit>-backup ship <set directory>` re-sends the kept bytes and removes the copy once the transfer is verified. A pending
  set is retried by every `ship-pending` run (the next nightly run, the next deploy, or by hand), which goes through the same `ship`. A kept copy encrypted to other recipients (after a rotation), one that
  no longer matches its digest, one that is not age ciphertext and one that is or contains a link are never sent. If the remote then
  holds either name the ship stops before encrypting, leaves the objects untouched and says to remove them first; the unusable copy
  stays, so reverting the recipients can still complete the set.
- **Disk sizing for kept copies.** Each kept copy is about the size of its set. At most two are kept (`RETRY_COPIES_MAX` in
  `backup.sh`): before each hardened ship the copies of the oldest sets are dropped first, never the copy for the set being shipped,
  with a log line naming the set (that set then needs its partial remote object removed before it can ship again). The next hardened
  ship also removes the copy of a pruned set. Every rclone ship logs, and `check-offhost` prints, "N kept retry copies, B bytes".
  Size the backup filesystem for the retained sets plus two kept copies plus one more set-sized copy for the next encryption (the
  pre-deploy free-space check covers that last one, as described under "Deferred shipping"). The nightly `snapshot` has no free-space
  check, and the backups may share a filesystem with MySQL, so watch it.
- **A remote without age recipients is refused.** The set holds the masters, stems, contracts, customer data and `env.backup` (which
  holds `APP_KEY`), so the script will not send it as stored: `check-offhost` and every ship stop with "would leave this host
  unencrypted" before anything is transferred, and the local set is untouched. The one exception is an explicit
  `VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1` in `backup.conf` (default `0`; only the literal `1` counts). Set it only when the remote
  is an rclone `crypt` remote, or a destination that encrypts at rest and that you accept as the sole protection; this script cannot
  tell which, so it logs a warning on every such ship and prints `none by this script, explicitly allowed` in `check-offhost`.
  `VASEY_BACKUP_REQUIRE_ENCRYPTION=1` takes precedence: it refuses without recipients whatever the opt-in says. The opt-in has no effect
  on the rsync path or on encrypted shipping.
- Give the host's credential the least it needs: write and list on the backup path, **no delete and no overwrite** (an
  object-lock, versioning or append-only credential at the destination). A host that can delete its own off-host backups can
  lose them to whoever compromises it.

## Retention

- **Default (unchanged):** `VASEY_BACKUP_KEEP` newest local sets (7), pruned after each successful nightly run.
- **`tiered`:** local sets are kept if they are any of: the newest `VASEY_BACKUP_KEEP`; the newest of each of the last
  `VASEY_BACKUP_KEEP_DAILY` days, `_WEEKLY` ISO weeks and `_MONTHLY` months; or the newest set carrying a verified restore
  proof. It can only keep more than the flat rule, never less, and it never prunes `*.FAILED` directories or anything whose name
  is not a well-formed set name. Invalid numbers prune nothing.
- **One remote path per host.** Pruning decides by set name only (a name is a timestamp plus a release hash, with no host in it), so two
  hosts that share one `VASEY_BACKUP_REMOTE` path would prune each other's sets. Give each host its own path (staging and production
  must never share one) before turning pruning on.
- **Remote pruning is separate and off:** `VASEY_BACKUP_REMOTE_PRUNE=1` applies the same plan to the rclone remote, once a night
  after the site has resumed; it needs `VASEY_BACKUP_REMOTE` and `tiered`, and it is never available for an rsync destination.
  Prefer retention enforced at the destination (lifecycle rules, object lock) and leave this off: a host that prunes its own
  off-host backups has the delete credential an attacker would want.
- Retention is Sean's decision; the numbers above are starting points, not an established policy.

## What was verified, and what was not

Run locally with real `age`, `rsync` and `rclone` (local backend) against a throwaway directory and a generated test key pair
kept in a temporary directory and never committed: the encrypt-then-decrypt round trip through both transports (the
decrypted tree matches byte for byte, the ciphertext contains none of the plaintext markers), the refusals above, the planner
(including a randomized check that it never keeps fewer sets than the flat rule), remote pruning on a local rclone remote, and
the unchanged default path, the refusal of a remote without recipients and its explicit opt-in, the cleanup of stale staging
directories, the shipping lock, the real rclone 1.60.1 refusing a differing remote object and accepting an identical one, and a
retry after an interrupted checksum upload (only the interruption is simulated; the retry re-sends the first attempt's exact bytes,
which then decrypt), the presence check and digest read-back on a real `chunker` remote with `hash_type = none`, "already shipped",
the kept-copy rule and cap, the bounded lock wait, a retry after an outage that also stopped listing, and the size check behind
"already shipped". `tests/ops/test_staging_backup_offhost.py` holds them (94 tests). `tests/ops/test_staging_deferred_ship.py` (38 tests) covers the deferral: the
shipped dispatcher with the snapshot and proof stubbed, real `age` and `rclone` against a local-directory remote, a recording
`rclone` that fails like B2 (`403 storage_cap_exceeded`) or with exit 1, and a recording `systemd-run`. It shows that `predeploy`
never calls `age`, `rclone` or `rsync` and queues a marker, that the nightly run resumes before anything reaches the remote, that a
failing remote fails neither `predeploy` nor the nightly run nor `ctl activate`/`refresh` (`test_staging_operation_lease.py`),
that a failed shipment stays pending, is recorded as failed and is completed and cleared by the next run, that pruning never removes
a pending set, and that the detached start leaks no lock descriptor and returns without waiting.
**Not verified:** any real remote (S3-compatible, B2, SFTP, another server), rsync over SSH to a real host, the host's cron
behaviour, a real `systemd-run` transient unit on the staging host (the tests use a recording stand-in; the `setsid` fallback is
exercised for real), a restore of a decrypted production-sized set, and the wall-clock time of a 38 GB shipment (it no longer
affects the maintenance window).

## Binary-log retention and shipping for point-in-time restore (later step, not built)

Notes for a later step; none of it is implemented or part of this kit's acceptance.

- MySQL 8.4 writes a binary log by default and `provision.sh` keeps it on (and sets `log_bin_trust_function_creators` so the
  migrations can create triggers). Closed log files expire after `binlog_expire_logs_seconds` (30 days by default), on the same
  disk as the data.
- A point-in-time restore needs the log position of each dump. The dump does not record one today (`mysqldump` runs
  `--single-transaction` without `--source-data`). Recording it, with `--source-data=2` or `SHOW BINARY LOG STATUS` under the
  quiesce, needs privileges the backup account does not have (`RELOAD`, `REPLICATION CLIENT`: check the 8.4 manual), so it is a
  privilege review first.
- Shipping would be: `FLUSH BINARY LOGS`, then copy each **closed** `binlog.*` file as root, encrypt it with the same age
  recipients, ship it with the same transport, and keep `binlog_expire_logs_seconds` longer than the shipping interval plus a
  margin. Retention would follow the dump retention above.
- The proof would be extended: replay the shipped logs with `mysqlbinlog` into the disposable `mysqld` up to a chosen time and
  re-run the schema and row checks. Until then, restoring a snapshot loses every order taken after it
  (see the production README, "Rollback").
