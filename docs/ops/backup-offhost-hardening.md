# Off-host backup hardening (opt-in)

`ops/staging/backup.sh` (and the production kit's derived copy) takes a nightly or pre-deploy backup, proves it restores on a
disposable MySQL, and optionally copies the set off the host with rsync over SSH. This guide covers four **opt-in** additions.
Every one is off unless `backup.conf` (below) turns it on. With no `backup.conf` the script behaves exactly as before: the same
restore proof, and a plain `rsync` of the whole set to `VASEY_BACKUP_DEST` when that is set. The existing tests in
`tests/ops/test_staging_backup_*.py` still pass unchanged.

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
- **One ship at a time.** Each hardened ship holds a non-blocking lock on `backups/.offhost.lock` while it cleans up, encrypts and
  transfers. A second one, whatever started it (the nightly run, a deploy or a manual `ship`), refuses with "another off-host ship is
  running" before it removes or sends anything. The default path takes no such lock.
- **Cost.** Encryption streams `tar | age` into a temporary file under `backups/.ship.*` (0700), so it needs free space of about
  one more copy of the set; the script checks that first. A run killed mid-transfer can leave its `.ship.*` directory behind; the next
  hardened ship (under the lock, so no transfer is running) removes any that has been untouched for more than a day, and only real
  directories named exactly `.ship.` plus six characters (never a link). Shipping runs inside the same maintenance window as the snapshot
  (`nightly` and `predeploy` call `ship` before the site resumes), so encryption lengthens that window by the time to encrypt and
  upload. Moving the transfer after the resume is a possible later change.
- **Restore drill (on a machine that has the private key):**

  ```sh
  mkdir restore && age -d -i key.txt <set>.tar.age | tar -x -C restore
  ( cd restore/<set> && sha256sum --check --strict database.sql.sha256 private.tar.sha256 env.backup.sha256 )
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
- **What it does not guarantee.** It is a check this script asks rclone to make before uploading (size and hash; on a backend with no
  hash in common with the local disk, rclone compares sizes only, as `rclone check` does; not verified here). It does not stop
  anyone who holds the credential, an attacker on this host included, from deleting or replacing objects with other commands, and
  it cannot serialize writers on different hosts. **The no-overwrite credential below remains the primary control.**
- Shipping a set again after it shipped completely is refused, because the new encryption differs; the remote copy stays exactly as
  it was, and the set is already off-host. The refused attempt leaves a kept copy (next item) until the set is pruned.
- **Retrying a failed rclone transfer.** age never produces the same ciphertext twice, so a retry that encrypted the set again
  would be refused under the name the first attempt already used, and the set could never ship. A failed rclone transfer therefore
  keeps the exact ciphertext, its digest and a digest of the recipients file in `backups/.offhost-retry.<set>` (0700, ciphertext
  and digests only). `vasey-<kit>-backup ship <set directory>` re-sends those same bytes and removes the copy once `rclone check`
  has passed. Nothing retries on its own: the nightly run ships its own new set. A kept copy encrypted to other recipients (after
  a rotation), one that no longer matches its digest, and one that is not age ciphertext are never sent; the set is encrypted
  again, and if the remote already holds a different `<set>.tar.age` the ship stops, leaves that object untouched and says to
  remove it first (unless an earlier ship of this set completed, in which case the set is already off-host). The
  next hardened ship removes a kept copy whose set has been pruned. Until then it takes about one encrypted copy of disk space.
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
which then decrypt). `tests/ops/test_staging_backup_offhost.py` holds them (53 tests).
**Not verified:** any real remote (S3-compatible, B2, SFTP, another server), rsync over SSH to a real host, the host's cron
and maintenance-window behaviour with encryption, a restore of a decrypted production-sized set, and the time encryption adds
to the window.

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
