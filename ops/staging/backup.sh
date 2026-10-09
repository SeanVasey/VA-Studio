#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || die` is intended: die whenever any test fails.
# VASEY.AUDIO staging backup: MySQL dump + private storage archive + runtime .env (APP_KEY), with an
# isolated restore proof, then an optional rsync to an off-host destination.
#
# Installed by provision.sh as /usr/local/sbin/vasey-staging-backup (root:root 0755) and run as root:
#   vasey-staging-backup nightly            quiesce, serialized snapshot/proof/ship, resume, prune (cron, 03:17 UTC)
#   vasey-staging-backup predeploy          snapshot + restore-check on an already-quiesced host (deploy, via ctl)
#   vasey-staging-backup restore-check DIR  re-prove an existing backup directory
#   vasey-staging-backup ship DIR           copy one backup directory to VASEY_BACKUP_DEST
#
# The dump, archive and verification follow docs/ops/backup-restore-proof.md ("Equivalent MySQL 8.4
# procedure") step for step. The restore target is a disposable mysqld started here on a private socket
# with its own datadir (never the staging server) and a fresh private directory; both are removed after
# the proof. Encrypted columns are readable only with the same APP_KEY, so the .env is backed up with them.
#
# Configuration: /etc/vasey-staging/staging.conf (root-owned, no secrets) and the backup-only MySQL account
# in /etc/vasey-staging/backup.my.cnf (0600). Nothing here prints a credential, row or private path content.
set -euo pipefail
umask 077
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

CONF=${VASEY_STAGING_CONF:-/etc/vasey-staging/staging.conf}
die() { echo "vasey-staging-backup: $*" >&2; exit 1; }
log() { echo "vasey-staging-backup: $(date -u +%FT%TZ) $*"; }

[ "$(id -u)" -eq 0 ] || die "must run as root"
[ -f "$CONF" ] && [ ! -L "$CONF" ] && [ "$(stat -c %u "$CONF")" = 0 ] || die "missing or untrusted $CONF"
# shellcheck source=/dev/null
. "$CONF"
: "${VASEY_APP_USER:?}" "${VASEY_APP_GROUP:?}" "${VASEY_ROOT:?}" "${VASEY_DB_NAME:?}"
: "${VASEY_BACKUP_DIR:=$VASEY_ROOT/backups}" "${VASEY_BACKUP_KEEP:=7}" "${VASEY_BACKUP_MYCNF:=/etc/vasey-staging/backup.my.cnf}"
: "${VASEY_RESTORE_CHECK_DIR:=/var/lib/mysql-restore-check}" "${VASEY_RESTORE_STRICT_MODES:=1}"
: "${VASEY_BACKUP_DEST:=}" "${VASEY_BACKUP_SSH_KEY:=/root/.ssh/vasey_staging_backup}"
: "${VASEY_MYSQLD:=/usr/sbin/mysqld}"

PRIVATE=$VASEY_ROOT/private
CTL=/usr/local/sbin/vasey-staging-ctl
NORMALIZER=/usr/local/libexec/vasey-staging/normalize-mysql-dump.py
DUMP_FLAGS=(--single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces
            --set-gtid-purged=OFF --skip-dump-date --skip-comments --databases "$VASEY_DB_NAME")

mycnf_ok() {
  [ -f "$VASEY_BACKUP_MYCNF" ] && [ ! -L "$VASEY_BACKUP_MYCNF" ] && [ "$(stat -c '%u %a' "$VASEY_BACKUP_MYCNF")" = "0 600" ]
}

valid_env_key() {
  [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %h -- "$1")" = 1 ] || return 1
  # Parse only one literal key, without loading application PHP or inheriting ini hooks.
  # Runtime admission separately proves the complete effective Laravel environment.
  # shellcheck disable=SC2016  # Embedded PHP variables must remain literal shell text.
  "$VASEY_PHP" -n -r '
    $data = file_get_contents($argv[1], false, null, 0, 1048577);
    if ($data === false || strlen($data) > 1048576 || str_contains($data, "\0")) exit(1);
    if (preg_match_all("/^[\t ]*(?:export[\t ]+)?APP_KEY[\t ]*=(.*)$/m", $data, $matches) !== 1) exit(1);
    $value = trim($matches[1][0]);
    $pattern = "~^(?:([\"\\x27])(base64:[A-Za-z0-9+/]{43}=)\\1|(base64:[A-Za-z0-9+/]{43}=))(?:[ \t]*(?:\\#.*)?)?$~";
    if (preg_match($pattern, $value, $key) !== 1) exit(1);
    $encoded = ($key[2] ?? "") !== "" ? $key[2] : ($key[3] ?? "");
    $bytes = base64_decode(substr($encoded, 7), true);
    exit($bytes !== false && strlen($bytes) === 32 ? 0 : 1);
  ' -- "$1" >/dev/null 2>&1
}

# ---- snapshot: assumes every writer is stopped (docs/ops/backup-restore-proof.md step 0b) ----
snapshot() {
  mycnf_ok || die "$VASEY_BACKUP_MYCNF must exist, root-owned, mode 0600"
  [ -d "$PRIVATE" ] && [ ! -L "$PRIVATE" ] || die "private root missing"
  rm -f -- "$VASEY_BACKUP_DIR/.last" || die "cannot invalidate previous snapshot pointer"
  local sha=none cur=''
  if [ -e "$VASEY_ROOT/current" ] || [ -L "$VASEY_ROOT/current" ]; then
    [ -L "$VASEY_ROOT/current" ] || die "current must be a served release symlink"
    cur=$(readlink -f -- "$VASEY_ROOT/current") || die "current is invalid"
    sha=$(basename "$cur")
    [[ "$sha" =~ ^[0-9a-f]{40}$ ]] && [ "$cur" = "$VASEY_ROOT/releases/$sha" ] && [ -d "$cur" ] \
      || die "current is not a canonical served release"
    valid_env_key "$cur/.env" || die "served release lacks a recoverable environment/key; no snapshot published"
  fi
  local out
  out="$VASEY_BACKUP_DIR/$(date -u +%Y%m%dT%H%M%SZ)-${sha:0:12}"
  mkdir -m 700 -- "$out" || die "cannot create backup directory"

  # 1. Consistent logical dump; a nonzero mysqldump never becomes a baseline.
  if ! mysqldump --defaults-extra-file="$VASEY_BACKUP_MYCNF" "${DUMP_FLAGS[@]}" > "$out/database.sql"; then
    mv -- "$out" "$out.FAILED"; die "mysqldump failed; kept as $out.FAILED for inspection"
  fi
  (cd "$out" && sha256sum database.sql > database.sql.sha256)

  # 2. Private files with a manifest. Refuse anything the manifest would not cover.
  local unmanifested dirs
  unmanifested=$(cd "$PRIVATE" && find . \( ! -type f ! -type d \) -o \( -type f -links +1 \)) || die "find failed"
  [ -z "$unmanifested" ] || { mv -- "$out" "$out.FAILED"; die "unmanifested entry (symlink, hard link or special file) in private storage"; }
  (cd "$PRIVATE" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum) > "$out/private.sha256"
  dirs=$(cd "$PRIVATE" && find . -type d) || die "find failed"
  printf '%s\n' "$dirs" | LC_ALL=C sort > "$out/private.dirs"
  tar --create --file="$out/private.tar" --directory="$PRIVATE" --numeric-owner . || { mv -- "$out" "$out.FAILED"; die "tar failed"; }
  (cd "$out" && sha256sum private.tar > private.tar.sha256)

  # 3. Key custody: the served release's .env carries APP_KEY. Without it encrypted columns are unreadable.
  if [ "$sha" != none ]; then
    install -m 0600 -o root -g root -- "$cur/.env" "$out/env.backup"
    (cd "$out" && sha256sum env.backup > env.backup.sha256)
  else
    log "WARNING: first-install snapshot with no served release/environment"
  fi
  {
    echo "created_utc=$(date -u +%FT%TZ)"
    echo "release_sha=$sha"
    echo "database=$VASEY_DB_NAME"
    echo "private_files=$(wc -l < "$out/private.sha256")"
    echo "private_dirs=$(wc -l < "$out/private.dirs")"
  } > "$out/MANIFEST"
  log "snapshot written: $(basename "$out")"
  printf '%s\n' "$out" > "$VASEY_BACKUP_DIR/.last"
}

RC_WORK=""; RC_PID=""; RC_SOCK=""
cleanup_restore() {
  if [ -n "$RC_PID" ] && kill -0 "$RC_PID" 2>/dev/null; then
    mysqladmin --no-defaults --socket="$RC_SOCK" -uroot shutdown >/dev/null 2>&1 || kill "$RC_PID" 2>/dev/null || true
    for _ in $(seq 1 60); do kill -0 "$RC_PID" 2>/dev/null || break; sleep 1; done
    ! kill -0 "$RC_PID" 2>/dev/null || return 1
  fi
  if [ -n "$RC_WORK" ] && [ -d "$RC_WORK" ]; then rm -rf --one-file-system -- "$RC_WORK" || return 1; fi
  RC_PID=""; RC_WORK=""
}

# ---- restore-check: docs/ops/backup-restore-proof.md steps 3-4 against isolated targets ----
restore_check() {
  local bk=${1:?backup directory}
  [ -d "$bk" ] && [ ! -L "$bk" ] || die "not a backup directory"
  # A success marker describes this proof attempt, never an earlier attempt that now fails.
  rm -f -- "$bk/RESTORE_CHECK" || die "cannot invalidate previous restore proof"
  [ -d "$bk" ] && [ -f "$bk/database.sql" ] && [ -f "$bk/private.tar" ] || die "not a backup directory"
  [ -f "$bk/MANIFEST" ] && [ ! -L "$bk/MANIFEST" ] \
    && [ "$(grep -c '^release_sha=' "$bk/MANIFEST")" = 1 ] || die "backup has no unambiguous release manifest"
  local release_sha
  release_sha=$(sed -n 's/^release_sha=//p' "$bk/MANIFEST")
  if [ "$release_sha" = none ]; then
    [ ! -e "$bk/env.backup" ] && [ ! -L "$bk/env.backup" ] \
      && [ ! -e "$bk/env.backup.sha256" ] && [ ! -L "$bk/env.backup.sha256" ] \
      || die "first-install manifest cannot carry a served environment"
  else
    [[ "$release_sha" =~ ^[0-9a-f]{40}$ ]] || die "invalid served release manifest"
    valid_env_key "$bk/env.backup" \
      && [ -f "$bk/env.backup.sha256" ] && [ ! -L "$bk/env.backup.sha256" ] \
      || die "served backup requires its environment/key and hash"
    (cd "$bk" && sha256sum --check --quiet env.backup.sha256) || die "env.backup hash mismatch"
  fi
  (cd "$bk" && sha256sum --check --quiet database.sql.sha256 private.tar.sha256) || die "backup files do not match their recorded hashes"

  install -d -m 0711 -o root -g root -- "$VASEY_RESTORE_CHECK_DIR"
  # Globals, so the EXIT trap still sees them when die() exits from inside this function.
  RC_WORK=$(mktemp -d "$VASEY_RESTORE_CHECK_DIR/check.XXXXXX"); chmod 0711 "$RC_WORK"
  RC_SOCK=$RC_WORK/run/mysqld.sock
  RC_PID=""
  trap cleanup_restore EXIT
  local data=$RC_WORK/data run=$RC_WORK/run sock=$RC_SOCK rpriv=$RC_WORK/private

  # Disposable server: its own datadir and socket, no TCP, no binlog, never the staging server.
  install -d -m 0700 -o mysql -g mysql -- "$data" "$run"
  "$VASEY_MYSQLD" --no-defaults --initialize-insecure --user=mysql --datadir="$data" 8>&- 7<&- >"$RC_WORK/init.log" 2>&1 \
    || die "disposable mysqld --initialize failed (AppArmor? see docs/ops/staging-runbook.md)"
  "$VASEY_MYSQLD" --no-defaults --user=mysql --datadir="$data" --socket="$sock" --pid-file="$run/mysqld.pid" \
    --skip-networking --mysqlx=OFF --disable-log-bin --log-error="$run/error.log" 8>&- 7<&- >/dev/null 2>&1 &
  RC_PID=$!
  local up=0
  for _ in $(seq 1 60); do
    if mysqladmin --no-defaults --socket="$sock" -uroot ping >/dev/null 2>&1; then up=1; break; fi
    sleep 1
  done
  [ "$up" = 1 ] || { tail -n 5 "$run/error.log" >&2 2>/dev/null || true; die "disposable mysqld did not start"; }
  local R=(--no-defaults --socket="$sock" -uroot)

  # 3. Load the dump unchanged; restore private files as the application user into a fresh directory.
  mysql "${R[@]}" < "$bk/database.sql" || die "loading the dump into the disposable server failed"
  install -d -m 0700 -o "$VASEY_APP_USER" -g "$VASEY_APP_GROUP" -- "$rpriv"
  runuser -u "$VASEY_APP_USER" -- tar --extract --file=- --directory="$rpriv" --no-same-owner --preserve-permissions \
    8>&- 7<&- < "$bk/private.tar" || die "private archive extraction failed"

  # 4. Verify: exact file set and hashes, exact directory set, owner-only modes and ownership, re-dump diff.
  if [ -s "$bk/private.sha256" ]; then
    (cd "$rpriv" && sha256sum --check --strict --quiet "$bk/private.sha256") || die "restored file hash mismatch"
  fi
  diff <(cd "$rpriv" && find . ! -type d | LC_ALL=C sort) <(cut -c67- "$bk/private.sha256" | LC_ALL=C sort) >/dev/null \
    || die "restored file names differ from the manifest"
  local restored_dirs; restored_dirs=$(cd "$rpriv" && find . -type d) || die "find failed"
  diff <(printf '%s\n' "$restored_dirs" | LC_ALL=C sort) "$bk/private.dirs" >/dev/null || die "restored directories differ"
  local widened foreign mode_result=pass
  widened=$(cd "$rpriv" && find . \( -type f ! -path ./.gitignore ! -perm 0600 ! -perm 0400 \) -o \( -type d ! -perm 0700 \)) || die "find failed"
  foreign=$(cd "$rpriv" && find . \( ! -user "$VASEY_APP_USER" -o ! -group "$VASEY_APP_GROUP" \)) || die "find failed"
  if [ -n "$widened" ] || [ -n "$foreign" ] \
     || ! (cd "$rpriv" && { [ ! -e .gitignore ] || [ "$(stat -c %a .gitignore)" = 644 ]; }); then
    mode_result="fail ($(printf '%s\n' "$widened" "$foreign" | grep -c . || true) entries outside 0600/0400 files, 0700 dirs or $VASEY_APP_USER ownership)"
    [ "$VASEY_RESTORE_STRICT_MODES" = 1 ] && die "restored tree $mode_result; inspect with: cd $PRIVATE && find . \\( -type f ! -path ./.gitignore ! -perm 0600 ! -perm 0400 \\) -o \\( -type d ! -perm 0700 \\)"
  fi
  ( umask 077; : > "$bk/redump.sql" )
  mysqldump "${R[@]}" "${DUMP_FLAGS[@]}" > "$bk/redump.sql" || die "re-dump from the disposable server failed"
  # MySQL 8.4 renders a column whose collation was given explicitly (as every column in a loaded dump is) with
  # `CHARACTER SET utf8mb4 COLLATE ...`, and an inherited one with `COLLATE ...` only, so a first-generation
  # dump and its re-dump can differ in that rendering alone (observed on this schema: 328 columns). Every
  # other byte, all row data included, must still be identical.
  local redump_result=identical
  if ! diff -q "$bk/redump.sql" "$bk/database.sql" >/dev/null; then
    local original_normalized redump_normalized
    original_normalized=$(mktemp "$bk/.original-normalized.XXXXXXXX")
    redump_normalized=$(mktemp "$bk/.redump-normalized.XXXXXXXX")
    if ! python3 -I "$NORMALIZER" "$bk/database.sql" > "$original_normalized" 2>/dev/null \
       || ! python3 -I "$NORMALIZER" "$bk/redump.sql" > "$redump_normalized" 2>/dev/null; then
      rm -f -- "$original_normalized" "$redump_normalized"
      die "schema rendering normalization failed; no restore proof published"
    fi
    if diff -q "$redump_normalized" "$original_normalized" >/dev/null; then
      redump_result="identical_except_column_charset_rendering"
    else
      rm -f -- "$original_normalized" "$redump_normalized"
      mv -f -- "$bk/redump.sql" "$bk/redump.sql.FAILED"
      die "re-dump differs from the backup (kept redump.sql.FAILED, 0600, for diff)"
    fi
    rm -f -- "$original_normalized" "$redump_normalized"
  fi
  rm -f -- "$bk/redump.sql"
  local tables triggers
  tables=$(mysql "${R[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$VASEY_DB_NAME'")
  triggers=$(mysql "${R[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$VASEY_DB_NAME'")

  cleanup_restore || die "disposable restore cleanup failed; no restore proof published"
  trap - EXIT
  local marker
  marker=$(mktemp "$bk/.RESTORE_CHECK.XXXXXXXX")
  if ! printf '%s\n' \
    "restore_checked_utc=$(date -u +%FT%TZ)" \
    "result=RESTORE_VERIFIED" \
    "database_redump=$redump_result" \
    "restored_tables=$tables" \
    "restored_triggers=$triggers" \
    "private_files_verified=$(grep -c . "$bk/private.sha256" || true)" \
    "private_dirs_verified=$(grep -c . "$bk/private.dirs" || true)" \
    "modes_and_ownership=$mode_result" \
    "app_key_present=$([ -f "$bk/env.backup" ] && echo yes || echo no)" \
    > "$marker" || ! mv -T -- "$marker" "$bk/RESTORE_CHECK"; then
    rm -f -- "$marker"
    die "cannot publish restore proof"
  fi
  log "restore check passed for $(basename "$bk"): $tables tables, $triggers triggers"
}

ship() {
  local bk=${1:?backup directory}
  if [ -z "$VASEY_BACKUP_DEST" ]; then
    log "WARNING: VASEY_BACKUP_DEST is unset; $(basename "$bk") exists on this host only (NOT an off-host backup)"
    return 0
  fi
  [ -f "$VASEY_BACKUP_SSH_KEY" ] || die "missing $VASEY_BACKUP_SSH_KEY for rsync to the backup destination"
  rsync -a --chmod=D700,F600 -e "ssh -i $VASEY_BACKUP_SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=yes" \
    -- "$bk" "$VASEY_BACKUP_DEST/" || die "rsync to the backup destination failed"
  log "shipped $(basename "$bk")"
}

prune_local() {
  local keep=$VASEY_BACKUP_KEEP
  find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' \
    | sort -r | tail -n +"$((keep + 1))" | while IFS= read -r d; do rm -rf --one-file-system -- "${VASEY_BACKUP_DIR:?}/$d"; done
}

case "${1:-}" in
  nightly)
    install -d -m 0700 -o root -g root -- "$VASEY_BACKUP_DIR"
    exec 9>>"$VASEY_ROOT/.deploy.lock"
    flock -n 9 || die "a deploy or another backup holds the lock; skipping this run"
    "$CTL" quiesce
    snapshot_status=0
    # The serialized root helper re-proves stopped writers and holds its action lock through
    # snapshot, restore verification and shipping. A direct ctl resume cannot race the copy.
    "$CTL" snapshot || snapshot_status=$?
    # Resume even when the snapshot failed: a failed backup must not leave the site down overnight.
    if [ -L "$VASEY_ROOT/current" ]; then "$CTL" resume; fi
    [ "$snapshot_status" = 0 ] || die "snapshot, restore proof or shipping failed; resume completed if a release was served"
    prune_local
    ;;
  predeploy)
    install -d -m 0700 -o root -g root -- "$VASEY_BACKUP_DIR"
    snapshot
    restore_check "$(cat "$VASEY_BACKUP_DIR/.last")"
    ship "$(cat "$VASEY_BACKUP_DIR/.last")"
    ;;
  restore-check) restore_check "${2:?backup directory}" ;;
  ship) ship "${2:?backup directory}" ;;
  *) die "usage: vasey-staging-backup nightly | predeploy | restore-check DIR | ship DIR" ;;
esac
