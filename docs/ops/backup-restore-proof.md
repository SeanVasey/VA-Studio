# Backup and restore proof

Prepared October 7, 2026 by the production-preparation-1 lane. This page describes a
**synthetic** proof that runs now and a **MySQL** procedure that is written down but has
not been run. Nothing here backs up or restores a real installation, production
database, customer file or credential, and none of it chooses a host (U-02 is still open).

## What the synthetic proof does

`scripts/ops/backup-restore-proof.php` makes its own data. It does not boot Laravel, read
`.env`, connect to a network service or write inside the repository.

```sh
mkdir -m 700 /tmp/va-backup-proof            # any empty 0700 directory outside the repo
php scripts/ops/backup-restore-proof.php --synthetic-proof --workdir /tmp/va-backup-proof
php scripts/ops/backup-restore-proof.php --verify \
  --backup /tmp/va-backup-proof/backup --restore /tmp/va-backup-proof/restore
```

1. **Source:** a SQLite database with two synthetic tables (`synthetic_orders`, 24 rows after
   one deletion; `synthetic_receipts`, 25 rows with binary payloads) and a private-storage
   sample tree (`masters/`, `stems/`, `contracts/`, an empty directory, a zero-length file).
   The bytes are deterministic hash output, labelled SYNTHETIC. They aren't audio, contracts or
   customer data, and the amounts and the `XXX` currency code aren't prices.
2. **Backup:** `VACUUM INTO` takes a transactionally consistent copy of the database, the
   SQLite counterpart of `mysqldump --single-transaction`. The private files are copied one at
   a time with a hash check on each. `manifest.json` records every file's relative path, size
   and SHA-256, the database's byte hash, a logical hash (schema plus every row in rowid order)
   and the row counts. `manifest.json.sha256` records the hash of the manifest itself.
3. **Restore:** creates a new, isolated `restore/` directory and restores only the files the
   authenticated manifest lists, hash-checking each one before and after it's written.
4. **Verify:** checks the manifest digest; that the backup database and tree match the
   manifest; that the restored database is byte-identical to the backup, passes
   `PRAGMA integrity_check` and has the same logical hash as the manifest and the source; and
   that the restored tree has exactly the manifest's files and directories, byte-identical, at
   modes 0600/0700, with no symlinks, hard links or special files.

The JSON report's `result` is `RESTORE_VERIFIED` (exit 0) or `BLOCKED` (exit 1). It always
reports `synthetic: true`, `production_data_used: false` and
`mysql_procedure: documented_not_executed`, and it never prints a path or exception text.
`tests/Unit/BackupRestoreProofTest.php` runs it end to end and checks that eight kinds of
tampering block verification: a flipped restored byte, a removed file, an unlisted extra
file, a planted symlink, widened permissions, a changed database byte, a backup file changed
after the manifest was written, and an edited manifest.

## Equivalent MySQL 8.4 procedure (documented, not executed)

Run these fragments in bash (step 4 uses process substitution). Use this only on the host Sean selects, against the reviewed release, with credentials from
the host secret store. Each `<...>` value is an input the operator supplies; none is known
here. Pass credentials through a mode-0600 option file, never on the command line.

```sh
# 0. Inputs (operator-supplied; none are defaults):
#    <OPTION_FILE>   mode-0600 [client] file with host/port/user/password for a backup-only account
#    <DATABASE>      production schema name
#    <RESTORE_OPTION_FILE>  a mode-0600 [client] file for an isolated MySQL server (never the
#                           production server) on which schema <DATABASE> does not exist yet; the
#                           restore keeps the original schema name, so nothing in the dump is rewritten
#    <PRIVATE_ROOT>  the application's storage/app/private directory
#    <RESTORE_PRIVATE_ROOT>  a new, isolated directory that does not exist yet (never the live root)
#    <BACKUP_DIR>    a new mode-0700 directory on separate storage

# 0b. Quiesce every writer first. --single-transaction snapshots the database, not the private
#    files: an HTTP upload (ResumableMediaUploadController writes PrivateUploadParts while it
#    updates upload/media rows) would land between steps 1 and 2. Maintenance mode plus stopped
#    workers and scheduler, or a coordinated database/storage snapshot, is the only way both
#    halves describe the same moment.
php artisan down   # then stop the queue workers and the scheduler; confirm no php process is serving requests

# 1. Consistent logical dump. InnoDB only; --single-transaction gives one snapshot without
#    locking writers. --no-tablespaces avoids needing PROCESS. --skip-comments drops the host,
#    version and date header so the step 4 diff compares content only. Keep binary data exact.
mysqldump --defaults-extra-file=<OPTION_FILE> --single-transaction --quick \
  --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF \
  --skip-dump-date --skip-comments --databases <DATABASE> > <BACKUP_DIR>/database.sql || exit 1
#    A nonzero mysqldump (for example an unreadable routine or event) leaves a partial file;
#    it must never become the baseline that step 4 compares against.
sha256sum <BACKUP_DIR>/database.sql > <BACKUP_DIR>/database.sql.sha256

# 2. Private files with a manifest, taken while the application is still down (step 0b).
#    The manifest lists regular files only, so refuse any entry it would not cover (symlinks,
#    hard links, devices, sockets): tar would archive and restore them unverified, and a
#    symlink can point outside the restored root.
#    If this refusal fires the application stays down (fail safe): investigate the entry,
#    then run `php artisan up` by hand before resuming service.
(cd <PRIVATE_ROOT> && ! find . \( ! -type f ! -type d \) -o \( -type f -links +1 \) | grep -q .) \
  || { echo 'unmanifested entry in <PRIVATE_ROOT>'; exit 1; }
(cd <PRIVATE_ROOT> && find . -type f -print0 | sort -z | xargs -0 -r sha256sum) > <BACKUP_DIR>/private.sha256   # -r: an empty tree gives an empty manifest, not a hash of stdin
tar --create --file=<BACKUP_DIR>/private.tar --directory=<PRIVATE_ROOT> --numeric-owner .
sha256sum <BACKUP_DIR>/private.tar > <BACKUP_DIR>/private.tar.sha256
php artisan up   # lifts maintenance mode only; it does not restart anything stopped in step 0b
# Restart every writer stopped in step 0b and verify it is back before treating the live
# installation as resumed (the queue workers carry payment, contract and media work; the
# scheduler carries recovery jobs). Service names are the host's; the private-server
# runbook names them. Everything below touches only the isolated targets.
systemctl start <QUEUE_WORKER_SERVICE> <SCHEDULER_SERVICE> && systemctl is-active <QUEUE_WORKER_SERVICE> <SCHEDULER_SERVICE> || exit 1

# 3. Restore into the isolated targets only. The dump is loaded unchanged (its CREATE DATABASE
#    and USE name <DATABASE>), so no stored value can be rewritten by a rename.
sha256sum --check <BACKUP_DIR>/database.sql.sha256 || exit 1
mysql --defaults-extra-file=<RESTORE_OPTION_FILE> < <BACKUP_DIR>/database.sql
sha256sum --check <BACKUP_DIR>/private.tar.sha256 || exit 1   # the archive itself, not only its members, must be the one step 2 wrote
mkdir -m 700 <RESTORE_PRIVATE_ROOT>
tar --extract --file=<BACKUP_DIR>/private.tar --directory=<RESTORE_PRIVATE_ROOT> --no-same-owner

# 4. Verify. The restored tree must hold exactly the manifest's files and nothing else.
#    --strict rejects an empty manifest, so an empty private store is proven by the name diff alone.
if [ -s <BACKUP_DIR>/private.sha256 ]; then
  (cd <RESTORE_PRIVATE_ROOT> && sha256sum --check --strict <BACKUP_DIR>/private.sha256) || exit 1
fi
diff <(cd <RESTORE_PRIVATE_ROOT> && find . ! -type d | LC_ALL=C sort) \
     <(cut -c67- <BACKUP_DIR>/private.sha256 | LC_ALL=C sort) || exit 1
#    Hashes and names say nothing about modes: masters and contracts must stay owner-only.
#    Owner-only means 0600, or 0400 for sealed originals (issued contracts, immutable media
#    revisions and sound-kit originals are written as 0400). The tracked root
#    storage/app/private/.gitignore is the one 0644 file the checkout itself places there;
#    a .gitignore anywhere deeper is not exempt.
(cd <RESTORE_PRIVATE_ROOT> && ! find . \( -type f ! -path ./.gitignore ! -perm 0600 ! -perm 0400 \) -o \( -type d ! -perm 0700 \) | grep -q .) \
  || { echo 'restored entry outside owner-only modes (0600/0400 files, 0700 directories)'; exit 1; }
#    The exempted root .gitignore must itself be exactly 0644 when present.
(cd <RESTORE_PRIVATE_ROOT> && { [ ! -e .gitignore ] || [ "$(stat -c %a .gitignore)" = 644 ]; }) \
  || { echo 'root .gitignore is not 0644'; exit 1; }
mysqldump --defaults-extra-file=<RESTORE_OPTION_FILE> --single-transaction --quick \
  --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF \
  --skip-dump-date --skip-comments --databases <DATABASE> | diff - <BACKUP_DIR>/database.sql || exit 1
mysql --defaults-extra-file=<RESTORE_OPTION_FILE> -e 'CHECKSUM TABLE <DATABASE>.orders EXTENDED'  # repeat per table and compare with the source
```

Treat these limits as open:

- **Key custody.** Encrypted columns, such as webhook payload ciphertext, can only be read
  with the same `APP_KEY` (and any `APP_PREVIOUS_KEYS`). A restore without the paired key
  bytes is incomplete. Key escrow and rotation are host decisions.
- **Server isolation.** The restore keeps the schema name, so the restore server must be a
  separate instance that the application cannot reach; a second schema on the production
  server is not an option for this procedure.
- **Live-state boundaries.** A restore must never become the live database over accepted
  payments. The cutover runbook (§10) governs rollback, and a restored database alone is not
  a rollback.
- **Not yet observed:** retention period, off-host copies, encryption at rest, restore-time
  objective, and a restore on the chosen host. These stay in
  `ProductionCommerceReadiness::EVIDENCE['backup_monitoring_and_rollback']` until someone
  actually observes them.
