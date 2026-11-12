#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || die` is intended: die whenever any test fails.
# VASEY.AUDIO staging backup: MySQL dump + private storage tree + runtime .env (APP_KEY), with an
# isolated restore proof, then an optional off-host copy (rsync, or age + rclone) that runs AFTER the site is back up.
#
# Installed by provision.sh as /usr/local/sbin/vasey-staging-backup (root:root 0755) and run as root:
#   vasey-staging-backup nightly            quiesce, serialized snapshot/proof, resume, start the detached off-host ship, prune (cron, 03:17 UTC)
#   vasey-staging-backup predeploy          snapshot + restore-check + "pending ship" marker on an already-quiesced host (deploy, via ctl); never ships
#   vasey-staging-backup space-check [--preverify]  refuse (exit 1) unless the disk has room for a pre-deploy snapshot; run before
#                                           any quiesce. --preverify first re-proves the files the next snapshot will share (site up)
#   vasey-staging-backup prune              apply local retention now (newest VASEY_BACKUP_KEEP, or tiered); never the newest set
#   vasey-staging-backup restore-check DIR  re-prove an existing backup directory (re-hashes every private file)
#   vasey-staging-backup ship DIR           copy one backup directory off-host now (manual; clears its pending marker on success)
#   vasey-staging-backup ship-pending       ship every pending set, newest first, with the site up (foreground; own lock; records dashboard evidence)
#   vasey-staging-backup ship-pending-start start ship-pending detached from the caller's locks (systemd-run, else setsid) and return at once
#   vasey-staging-backup pending            list the sets still waiting for an off-host copy, with their last result
#   vasey-staging-backup pending-clear SET  abandon one pending set (it is then pruned by ordinary retention)
#   vasey-staging-backup check-offhost      validate the opt-in age/rclone/retention settings (no network, ships nothing)
#
# The dump and its verification follow docs/ops/backup-restore-proof.md ("Equivalent MySQL 8.4 procedure") step for step.
# Since 2026-11-11 the private store is a directory tree in the set (private/) that shares unchanged files with the previous
# verified set by hard link, never with the live store (private-snapshot.py says why that is safe); sets taken before that
# hold private.tar and are still restore-checked, shipped and pruned as they always were. The restore target is a disposable mysqld started here on a private socket
# with its own datadir (never the staging server) and a fresh private directory; both are removed after
# the proof. Encrypted columns are readable only with the same APP_KEY, so the .env is backed up with them.
#
# Configuration: /etc/vasey-staging/staging.conf (root-owned, no secrets) and the backup-only MySQL account
# in /etc/vasey-staging/backup.my.cnf (0600). Nothing here prints a credential, row or private path content.
# Optional hardening (age encryption, an rclone remote, tiered retention) is off unless /etc/vasey-staging/backup.conf
# configures it: docs/ops/backup-offhost-hardening.md. The default behaviour is unchanged.
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
# Optional, default-off hardening (docs/ops/backup-offhost-hardening.md): age encryption, an rclone remote and tiered
# retention are configured in this separate root-owned file, because provision.sh rewrites staging.conf on every run and
# would silently drop them. No file means the behaviour this script always had.
: "${VASEY_BACKUP_CONF:=/etc/vasey-staging/backup.conf}"
if [ -e "$VASEY_BACKUP_CONF" ] || [ -L "$VASEY_BACKUP_CONF" ]; then
  [ -f "$VASEY_BACKUP_CONF" ] && [ ! -L "$VASEY_BACKUP_CONF" ] && [ "$(stat -c %u "$VASEY_BACKUP_CONF")" = 0 ] \
    && case "$(stat -c %a "$VASEY_BACKUP_CONF")" in 600|640|644|400|440|444) true ;; *) false ;; esac \
    || die "untrusted $VASEY_BACKUP_CONF (must be a root-owned regular file, not group/world writable)"
  # shellcheck source=/dev/null
  . "$VASEY_BACKUP_CONF"
fi
: "${VASEY_BACKUP_DIR:=$VASEY_ROOT/backups}" "${VASEY_BACKUP_KEEP:=7}" "${VASEY_BACKUP_MYCNF:=/etc/vasey-staging/backup.my.cnf}"
: "${VASEY_RESTORE_CHECK_DIR:=/var/lib/mysql-restore-check}" "${VASEY_RESTORE_STRICT_MODES:=1}"
: "${VASEY_BACKUP_DEST:=}" "${VASEY_BACKUP_SSH_KEY:=/root/.ssh/vasey_staging_backup}"
: "${VASEY_MYSQLD:=/usr/sbin/mysqld}"
# Opt-in off-host hardening. Every variable below is unset or off by default, and then changes nothing.
: "${VASEY_BACKUP_AGE_RECIPIENTS_FILE:=}" "${VASEY_BACKUP_AGE_BIN:=/usr/bin/age}" "${VASEY_BACKUP_REQUIRE_ENCRYPTION:=0}"
: "${VASEY_BACKUP_REMOTE:=}" "${VASEY_BACKUP_RCLONE_BIN:=/usr/bin/rclone}" "${VASEY_BACKUP_RCLONE_CONFIG:=/root/.config/rclone/rclone.conf}"
: "${VASEY_BACKUP_RETENTION:=}" "${VASEY_BACKUP_KEEP_DAILY:=7}" "${VASEY_BACKUP_KEEP_WEEKLY:=4}" "${VASEY_BACKUP_KEEP_MONTHLY:=6}"
: "${VASEY_BACKUP_REMOTE_PRUNE:=0}" "${VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE:=0}"
# Sharing unchanged private files with the set re-hashed before the quiesce (1, the default) or copying the whole store every
# time (0). docs/ops/staging-runbook.md section 8.
: "${VASEY_BACKUP_LINK:=1}"
[ "$(id -u "$VASEY_APP_USER")" -gt 0 ] || die "application account must be nonroot"

PRIVATE=$VASEY_ROOT/private
CTL=/usr/local/sbin/vasey-staging-ctl
NORMALIZER=/usr/local/libexec/vasey-staging/normalize-mysql-dump.py
PRIVATE_SNAPSHOT=/usr/local/libexec/vasey-staging/private-snapshot.py
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
    // This backup profile supports one assignment per line. Reject multiline values before
    // identifying APP_KEY, so key-looking text inside another value cannot establish custody.
    $keys = [];
    foreach (explode("\n", $data) as $line) {
      $line = trim($line, " \t\r");
      if ($line === "" || $line[0] === "#") continue;
      if (str_contains($line, "\r") || preg_match("~^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$~D", $line, $assignment) !== 1) exit(1);
      $candidate = ltrim($assignment[2], " \t");
      if ($candidate !== "" && ($candidate[0] === chr(34) || $candidate[0] === chr(39))) {
        $quote = $candidate[0];
        $closed = false;
        for ($i = 1, $length = strlen($candidate); $i < $length; $i++) {
          if ($quote === chr(34) && $candidate[$i] === chr(92)) { $i++; continue; }
          if ($candidate[$i] === $quote) { $closed = true; break; }
        }
        if (!$closed) exit(1);
        $suffix = ltrim(substr($candidate, $i + 1), " \t");
        if ($suffix !== "" && $suffix[0] !== "#") exit(1);
      } elseif (strpbrk(explode("#", $candidate, 2)[0], chr(34).chr(39)) !== false) exit(1);
      if ($assignment[1] === "APP_KEY") $keys[] = trim($candidate);
    }
    if (count($keys) !== 1) exit(1);
    $value = $keys[0];
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

  # 2. Private files: a root-only tree with manifests (private.sha256, private.dirs, private.index). Files unchanged since the
  #    newest verified set are hard links to THAT SET's verified copies; everything else is copied from the live store, which is
  #    never linked. Symlinks, hard links and special files in the live store are refused, as before.
  [ -f "$PRIVATE_SNAPSHOT" ] && [ ! -L "$PRIVATE_SNAPSHOT" ] \
    || { mv -- "$out" "$out.FAILED"; die "missing $PRIVATE_SNAPSHOT; re-run provision.sh"; }
  #    Only the set this operation re-hashed before its quiesce may be shared (its token, consumed here: one use).
  local src private_summary in_use
  local -a sharing=()
  in_use=${VASEY_BACKUP_DIR:?}/.link-token.in-use.$$
  src=''
  if [ "${VASEY_BACKUP_LINK:-1}" = 1 ] && src=$(link_token_set) && [ -n "$src" ] \
     && mv -T -- "${VASEY_BACKUP_DIR:?}/.link-token" "$in_use" 2>/dev/null; then
    sharing=(--link-from "$src" --link-token "$in_use" --link-max-age-hours "$LINK_MAX_AGE_HOURS")
  else
    src=''
    rm -f -- "${VASEY_BACKUP_DIR:?}/.link-token"
  fi
  if ! private_summary=$(python3 -I "$PRIVATE_SNAPSHOT" snapshot --live "$PRIVATE" --set "$out" "${sharing[@]}"); then
    rm -f -- "${in_use:?}"
    mv -- "$out" "$out.FAILED"; die "private snapshot failed (reason above); kept as $out.FAILED for inspection"
  fi
  rm -f -- "${in_use:?}"
  # A re-verified source that shares nothing (every file copied) usually means the live store's device number changed (a reboot or
  # a remount): still correct, but a full copy. Say so.
  if [ -n "$src" ] && [ "$(printf '%s\n' "$private_summary" | sed -n 's/^linked_files=//p')" = 0 ] \
     && [ "$(printf '%s\n' "$private_summary" | sed -n 's/^files=//p')" != 0 ]; then
    log "WARNING: nothing was shared with $(basename -- "$src") although it was re-verified: every private file was copied ($(printf '%s\n' "$private_summary" | grep '^link_refused=' || echo 'every live file changed identity; a remount or reboot renumbers devices'))"
  fi

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
    echo "private_format=tree"
    printf '%s\n' "$private_summary" | grep -E '^(copied_files|copied_bytes|linked_files|linked_bytes|link_source)=' | sed 's/^/private_/'
  } > "$out/MANIFEST"
  log "snapshot written: $(basename "$out") (private: $(printf '%s' "$private_summary" | grep -E '^(copied|linked)_(files|bytes)=|^link_source=|^link_refused=' | tr '\n' ' '))"
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
# The private part is either private.tar (sets taken before 2026-11-11: extracted as the application user and verified) or the
# private/ tree, verified in place by private-snapshot.py: exact names, directories, modes and owner, and the SHA-256 of every
# file. `incremental` (the predeploy proof, right after its snapshot) re-hashes every file the snapshot copied and requires every
# shared file to be the exact inode it linked from the set re-hashed before the quiesce; without it (a manual `restore-check
# DIR`, rollback case B, a set moved or extracted from an off-host copy) every file is re-hashed.
restore_check() {  # restore_check DIR [incremental]
  local bk=${1:?backup directory} depth=--full format
  [ -d "$bk" ] && [ ! -L "$bk" ] || die "not a backup directory"
  case "${2:-}" in '') ;; incremental) depth='' ;; *) die "restore-check: unknown proof depth" ;; esac
  # A success marker describes this proof attempt, never an earlier attempt that now fails; nor may a token keep naming it.
  rm -f -- "$bk/RESTORE_CHECK" || die "cannot invalidate previous restore proof"
  if [ "$(link_token_name)" = "$(basename -- "$bk")" ]; then
    rm -f -- "${VASEY_BACKUP_DIR:?}/.link-token" || die "cannot invalidate the link token"
  fi
  if [ -f "$bk/private.tar" ] && [ ! -L "$bk/private.tar" ] && [ ! -e "$bk/private" ] && [ ! -L "$bk/private" ]; then format=tar
  elif [ -d "$bk/private" ] && [ ! -L "$bk/private" ] && [ -f "$bk/private.index" ] && [ ! -e "$bk/private.tar" ] && [ ! -L "$bk/private.tar" ]; then format=tree
  else die "not a backup directory"; fi
  [ -d "$bk" ] && [ -f "$bk/database.sql" ] || die "not a backup directory"
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
    # Check the exact recorded file, never another pathname supplied by the hash manifest.
    (cd "$bk" && sha256sum env.backup | cmp -s - env.backup.sha256) || die "env.backup hash mismatch"
  fi
  if [ "$format" = tar ]; then
    (cd "$bk" && sha256sum --check --quiet database.sql.sha256 private.tar.sha256) || die "backup files do not match their recorded hashes"
  else
    (cd "$bk" && sha256sum --check --quiet database.sql.sha256) || die "backup files do not match their recorded hashes"
  fi

  install -d -m 0711 -o root -g root -- "$VASEY_RESTORE_CHECK_DIR"
  # Globals, so the EXIT trap still sees them when die() exits from inside this function.
  RC_WORK=$(mktemp -d "$VASEY_RESTORE_CHECK_DIR/check.XXXXXX"); chmod 0711 "$RC_WORK"
  RC_SOCK=$RC_WORK/run/mysqld.sock
  RC_PID=""
  trap cleanup_restore EXIT
  local data=$RC_WORK/data run=$RC_WORK/run sock=$RC_SOCK rpriv=$RC_WORK/private private_proof=''
  # A tree set is verified in place first: it is the cheaper failure, and nothing of it is extracted.
  if [ "$format" = tree ]; then
    [ -f "$PRIVATE_SNAPSHOT" ] && [ ! -L "$PRIVATE_SNAPSHOT" ] || die "missing $PRIVATE_SNAPSHOT; re-run provision.sh"
    local proof_rc=0
    private_proof=$(python3 -I "$PRIVATE_SNAPSHOT" verify "$bk" ${depth:+"$depth"} --backups "$VASEY_BACKUP_DIR" \
      --strict-modes "$([ "$VASEY_RESTORE_STRICT_MODES" = 1 ] && echo 1 || echo 0)") || proof_rc=$?
    if [ "$proof_rc" != 0 ]; then
      local -a withdrawn=()
      mapfile -t withdrawn < <(printf '%s\n' "$private_proof" | sed -n 's/^withdraw=//p')
      # As in preverify_link_source: a finding is exit 1 WITH the sets it affects; anything else says nothing about content.
      if [ "$proof_rc" = 1 ] && [ "${#withdrawn[@]}" -gt 0 ]; then
        withdraw_proofs "${withdrawn[@]}"
        die "private tree verification failed (reason above); no restore proof published"
      fi
      die "private tree verification could not run (an operational error, not a corruption finding; reason above); no other set's proof was withdrawn and no restore proof was published. Fix the cause (dmesg, free space), then run restore-check $bk again"
    fi
  fi

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

  # 3. Load the dump unchanged; restore private files as the application user into a fresh directory (archive sets only).
  mysql "${R[@]}" < "$bk/database.sql" || die "loading the dump into the disposable server failed"
  local widened foreign mode_result=pass
  if [ "$format" = tar ]; then
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
    widened=$(cd "$rpriv" && find . \( -type f ! -path ./.gitignore ! -perm 0600 ! -perm 0400 \) -o \( -type d ! -perm 0700 \)) || die "find failed"
    foreign=$(cd "$rpriv" && find . \( ! -user "$VASEY_APP_USER" -o ! -group "$VASEY_APP_GROUP" \)) || die "find failed"
    if [ -n "$widened" ] || [ -n "$foreign" ] \
       || ! (cd "$rpriv" && { [ ! -e .gitignore ] || [ "$(stat -c %a .gitignore)" = 644 ]; }); then
      mode_result="fail ($(printf '%s\n' "$widened" "$foreign" | grep -c . || true) entries outside 0600/0400 files, 0700 dirs or $VASEY_APP_USER ownership)"
      [ "$VASEY_RESTORE_STRICT_MODES" = 1 ] && die "restored tree $mode_result; inspect with: cd $PRIVATE && find . \\( -type f ! -path ./.gitignore ! -perm 0600 ! -perm 0400 \\) -o \\( -type d ! -perm 0700 \\)"
    fi
  else
    # 4. (tree) The in-place verification above proved names, directories, modes and owner and the hashes it reports; a restore
    #    streams the tree to the application user (runbook section 9, case B), which gives it that user's ownership.
    mode_result=$(printf '%s\n' "$private_proof" | sed -n 's/^modes_and_ownership=//p')
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
  local -a proof_fields=()
  mapfile -t proof_fields < <(printf '%s\n' "$private_proof" | grep -E '^(proof|rehashed_files|rehashed_bytes|trusted_linked_files)=' | sed 's/^/private_/' || true)
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
    "private_format=$format" \
    "${proof_fields[@]}" \
    > "$marker" || ! mv -T -- "$marker" "$bk/RESTORE_CHECK"; then
    rm -f -- "$marker"
    die "cannot publish restore proof"
  fi
  log "restore check passed for $(basename "$bk"): $tables tables, $triggers triggers"
}

# ---- opt-in off-host hardening (docs/ops/backup-offhost-hardening.md) ----
# Everything between here and `ship()` is inert unless VASEY_BACKUP_CONF (or staging.conf) sets one of:
#   VASEY_BACKUP_AGE_RECIPIENTS_FILE   age PUBLIC recipients; the set is tarred and encrypted before it leaves the host
#   VASEY_BACKUP_REQUIRE_ENCRYPTION=1  refuse to ship at all unless recipients are configured
#   VASEY_BACKUP_REMOTE                a named rclone remote (remote:path), an alternative to VASEY_BACKUP_DEST; needs age recipients,
#                                      or VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1 (the set would otherwise leave this host in plaintext)
#   VASEY_BACKUP_RETENTION=tiered      keep newest VASEY_BACKUP_KEEP sets plus daily/weekly/monthly representatives
#   VASEY_BACKUP_REMOTE_PRUNE=1        apply the same policy on the rclone remote (nightly only; never for rsync)
# No private key is ever read or needed here: decryption happens elsewhere, with a key this host never holds.
SET_NAME_RE='^20[0-9]{6}T[0-9]{6}Z-([0-9a-f]{12}|none)$'
# At most this many kept retry copies (backups/.offhost-retry.<set>, each about one set's size): the oldest go first.
RETRY_COPIES_MAX=2
# Seconds a hardened ship waits for another one to finish; 0 refuses at once. ship-pending waits (SHIP_PENDING_LOCK_WAIT); predeploy never ships.
SHIP_LOCK_WAIT=0
ALREADY_SHIPPED=0
# Off-host shipping never runs while the site is quiesced. predeploy and the nightly snapshot only queue a "pending ship"
# marker (backups/.offhost-pending.<set>); ship-pending sends the queued sets later, detached from the deploy and ctl locks.
BACKUP_SELF=/usr/local/sbin/vasey-staging-backup
SHIP_PENDING_LOG=/var/log/vasey-staging/ship-pending.log
SHIP_PENDING_UNIT=vasey-staging-ship-pending
# Seconds ship-pending waits for a manual `ship` (a 38 GB one takes ~15 minutes) to release the off-host lock; the site is up,
# so waiting delays nothing. A timeout is "busy, retry later", never a recorded failure.
SHIP_PENDING_LOCK_WAIT=3600
# More pending sets than this means the remote has been failing for days; each one is about a set of disk (ship_pending logs a warning).
PENDING_WARN=3

# ---- the private tree: which earlier set a snapshot may share unchanged files with ----
# A snapshot shares files only with the set that this operation's pre-quiesce re-verification hashed in full: preverify writes
# its token (backups/.link-token, outside every set: the identity of each verified inode) and the next snapshot consumes it. The
# token is refused after this many hours (a build between prepare's re-verification and activate's snapshot fits well inside).
LINK_MAX_AGE_HOURS=6

# The newest tree set with a restore proof: the one preverify re-hashes. Prints nothing when sharing is off or none qualifies.
link_source() {
  case "${VASEY_BACKUP_LINK:-1}" in
    1) ;;
    0) return 0 ;;
    *) log "WARNING: VASEY_BACKUP_LINK must be 0 or 1; copying the whole private store"; return 0 ;;
  esac
  local d bk
  while IFS= read -r d; do
    [[ "$d" =~ $SET_NAME_RE ]] || continue
    bk=$VASEY_BACKUP_DIR/$d
    grep -qx 'result=RESTORE_VERIFIED' "$bk/RESTORE_CHECK" 2>/dev/null || continue
    [ -d "$bk/private" ] && [ ! -L "$bk/private" ] && [ -f "$bk/private.index" ] || continue
    printf '%s\n' "$bk"
    return 0
  done < <(find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' 2>/dev/null | LC_ALL=C sort -r)
  return 0
}

# The set name the current token's header records (nothing when there is no token).
link_token_name() {
  local token=$VASEY_BACKUP_DIR/.link-token
  [ -f "$token" ] && [ ! -L "$token" ] || return 0
  sed -n '1s/.*"set": "\([^"]*\)".*/\1/p' -- "$token"
}

# True while the current token's re-verification is younger than LINK_MAX_AGE_HOURS (and not more than five minutes in the
# future), the window private-snapshot.py accepts. A token an aborted deploy left behind ages out here, so the next operation
# re-hashes instead of reusing it (the engine would refuse it and copy everything).
link_token_fresh() {
  local token=$VASEY_BACKUP_DIR/.link-token verified now age
  [ -f "$token" ] && [ ! -L "$token" ] || return 1
  verified=$(sed -n '1s/.*"verified_ns": \([0-9]\{1,19\}\)[,}].*/\1/p' -- "$token")
  [[ "$verified" =~ ^[0-9]+$ ]] || return 1
  now=$(date +%s%N)
  [[ "$now" =~ ^[0-9]+$ ]] || return 1
  age=$((now - verified))
  [ "$age" -ge $((-300 * 1000000000)) ] && [ "$age" -le $((LINK_MAX_AGE_HOURS * 3600 * 1000000000)) ]
}

# The set the current token names, while the token is fresh and that set still holds its restore proof; else nothing.
link_token_set() {
  local name
  name=$(link_token_name)
  [[ "$name" =~ $SET_NAME_RE ]] && link_token_fresh && grep -qx 'result=RESTORE_VERIFIED' "$VASEY_BACKUP_DIR/$name/RESTORE_CHECK" 2>/dev/null \
    && [ -d "$VASEY_BACKUP_DIR/$name/private" ] && [ ! -L "$VASEY_BACKUP_DIR/$name/private" ] || return 0
  printf '%s\n' "$VASEY_BACKUP_DIR/$name"
}

# Withdraws the restore proof of each named set (a failed re-proof, or a set sharing an inode with what failed), and the link
# token when it names one of them. A withdrawn set is never shared, is skipped by ship-pending and is proven again only by an
# explicit `restore-check`.
withdraw_proofs() {  # withdraw_proofs NAME...
  local name
  for name in "$@"; do
    [[ "$name" =~ $SET_NAME_RE ]] || continue
    if [ "$(link_token_name)" = "$name" ]; then rm -f -- "${VASEY_BACKUP_DIR:?}/.link-token"; fi
    if [ -e "$VASEY_BACKUP_DIR/$name/RESTORE_CHECK" ]; then
      rm -f -- "${VASEY_BACKUP_DIR:?}/${name:?}/RESTORE_CHECK"
      log "WARNING: withdrew the restore proof of $name: it holds or shares a private file that failed verification"
    fi
  done
  return 0
}

# Re-proves, with the site still up, every file of the set the next snapshot will share, and writes the token that lets the
# snapshot share it. Returns 1 after a failed re-proof (the engine exits 1 and names the sets): those sets lose their restore
# proof. Returns 2 after an operational error (the engine exits 3, or anything else: no space, I/O, a token it cannot write):
# nothing is withdrawn, because it says nothing about a backup's content. Either way no token exists and the caller decides (a
# deploy refuses before its quiesce; the nightly run copies everything).
preverify_link_source() {
  [ -n "${PRIVATE_SNAPSHOT:-}" ] && [ -f "$PRIVATE_SNAPSHOT" ] || return 0
  local src out rc=0
  local -a withdrawn=()
  rm -f -- "${VASEY_BACKUP_DIR:?}/.link-token"
  src=$(link_source) || src=''
  if [ -z "$src" ]; then
    log "no verified tree set to share files with: the next snapshot copies the whole private store"
    return 0
  fi
  out=$(python3 -I "$PRIVATE_SNAPSHOT" preverify "$src" --token "$VASEY_BACKUP_DIR/.link-token" --backups "$VASEY_BACKUP_DIR" \
          --strict-modes "$([ "${VASEY_RESTORE_STRICT_MODES:-1}" = 0 ] && echo 0 || echo 1)" 2>&1) || rc=$?
  if [ "$rc" = 0 ]; then
    log "re-verified the private files of $(basename -- "$src") before the quiesce: $(printf '%s' "$out" | tr '\n' ' ')"
    return 0
  fi
  rm -f -- "${VASEY_BACKUP_DIR:?}/.link-token"
  mapfile -t withdrawn < <(printf '%s\n' "$out" | sed -n 's/^withdraw=//p')
  # A finding is exit 1 WITH the sets it affects; anything else (exit 3, a crash, exit 1 without them) says nothing about content.
  if [ "$rc" != 1 ] || [ "${#withdrawn[@]}" = 0 ]; then
    log "WARNING: the re-verification of $(basename -- "$src") could not run: an operational error, not a corruption finding ($(printf '%s\n' "$out" | grep -v '^withdraw=' | tr '\n' ' ' | cut -c1-300)). No restore proof was withdrawn; nothing is shared until a re-verification succeeds. If it repeats, run: vasey-staging-backup restore-check $src"
    return 2
  fi
  withdraw_proofs "${withdrawn[@]}"
  log "WARNING: $(basename -- "$src") failed its private file re-verification ($(printf '%s\n' "$out" | grep -v '^withdraw=' | tr '\n' ' ' | cut -c1-300)); withdrew the proof of: ${withdrawn[*]:-none}. Nothing is shared: the next snapshot copies the whole private store. Inspect with: vasey-staging-backup restore-check $src"
  return 1
}

hardening_requested() {
  [ -n "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" ] || [ -n "$VASEY_BACKUP_REMOTE" ] || [ "$VASEY_BACKUP_REQUIRE_ENCRYPTION" = 1 ]
}

# Validates the opt-in configuration without any network access. Prints the reason (never a value) and fails closed.
offhost_config_ok() {
  local f line recipients=0
  if [ -n "$VASEY_BACKUP_DEST" ] && [ -n "$VASEY_BACKUP_REMOTE" ]; then
    log "refused: VASEY_BACKUP_DEST (rsync) and VASEY_BACKUP_REMOTE (rclone) are alternatives; set one"; return 1
  fi
  case "$VASEY_BACKUP_REQUIRE_ENCRYPTION" in 0|1) ;; *) log "refused: VASEY_BACKUP_REQUIRE_ENCRYPTION must be 0 or 1"; return 1 ;; esac
  case "$VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE" in 0|1) ;; *) log "refused: VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE must be 0 or 1"; return 1 ;; esac
  if [ -n "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" ]; then
    f=$VASEY_BACKUP_AGE_RECIPIENTS_FILE
    [ -x "$VASEY_BACKUP_AGE_BIN" ] || { log "refused: age is not installed at the configured path (apt-get install age)"; return 1; }
    [ -f "$f" ] && [ ! -L "$f" ] && [ "$(stat -c %u -- "$f")" = 0 ] && [ "$(( 8#$(stat -c %a -- "$f") & 8#022 ))" = 0 ] \
      && [ "$(stat -c %s -- "$f")" -le 65536 ] \
      || { log "refused: the age recipients file must be a root-owned regular file, not group/world writable"; return 1; }
    while IFS= read -r line || [ -n "$line" ]; do
      line=${line%$'\r'}
      case "$line" in
        ''|'#'*) continue ;;
        AGE-SECRET-KEY-*) log "refused: the recipients file contains private key material; the host may hold public recipients only"; return 1 ;;
      esac
      [[ "$line" =~ ^age1[02-9ac-hj-np-z]{58}$ ]] || { log "refused: a recipients line is not a native age public key (age1...)"; return 1; }
      recipients=$((recipients + 1))
    done < "$f"
    [ "$recipients" -ge 1 ] || { log "refused: the recipients file names no recipient"; return 1; }
  elif [ "$VASEY_BACKUP_REQUIRE_ENCRYPTION" = 1 ]; then
    log "refused: VASEY_BACKUP_REQUIRE_ENCRYPTION=1 but VASEY_BACKUP_AGE_RECIPIENTS_FILE is not set"; return 1
  fi
  if [ -n "$VASEY_BACKUP_REMOTE" ]; then
    # A named remote only: a connection string (:backend,key=value:) could carry credentials onto a command line.
    [[ "$VASEY_BACKUP_REMOTE" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*:[A-Za-z0-9_./@=-]*$ ]] \
      || { log "refused: VASEY_BACKUP_REMOTE must be a named rclone remote such as vasey-backup:bucket/path"; return 1; }
    [ -x "$VASEY_BACKUP_RCLONE_BIN" ] || { log "refused: rclone is not installed at the configured path"; return 1; }
    [ -f "$VASEY_BACKUP_RCLONE_CONFIG" ] && [ ! -L "$VASEY_BACKUP_RCLONE_CONFIG" ] \
      && [ "$(stat -c '%u %a' -- "$VASEY_BACKUP_RCLONE_CONFIG")" = '0 600' ] \
      || { log "refused: the rclone config must be a root-owned 0600 regular file (it holds the remote's credentials)"; return 1; }
    # The set holds masters, contracts, customer data and env.backup (APP_KEY). Without age recipients an rclone ship would send
    # all of it as stored, so a remote without recipients is refused unless the operator states, in backup.conf, that the remote
    # itself encrypts (an rclone crypt remote, or a destination that encrypts at rest and that they accept as the only protection).
    if [ -z "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" ] && [ "$VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE" != 1 ]; then
      log "refused: VASEY_BACKUP_REMOTE is set without VASEY_BACKUP_AGE_RECIPIENTS_FILE, so the whole set (masters, contracts, customer data, env.backup with APP_KEY) would leave this host unencrypted; set the recipients, or set VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1 only for an rclone crypt remote or a destination you accept as the sole protection"
      return 1
    fi
  fi
  case "$VASEY_BACKUP_RETENTION" in
    '') ;;
    tiered)
      [[ "$VASEY_BACKUP_KEEP" =~ ^[0-9]{1,3}$ ]] && [ "$VASEY_BACKUP_KEEP" -ge 1 ] \
        && [[ "$VASEY_BACKUP_KEEP_DAILY" =~ ^[0-9]{1,3}$ ]] && [[ "$VASEY_BACKUP_KEEP_WEEKLY" =~ ^[0-9]{1,3}$ ]] \
        && [[ "$VASEY_BACKUP_KEEP_MONTHLY" =~ ^[0-9]{1,3}$ ]] \
        || { log "refused: tiered retention counts must be whole numbers (VASEY_BACKUP_KEEP at least 1)"; return 1; } ;;
    *) log "refused: VASEY_BACKUP_RETENTION must be empty or tiered"; return 1 ;;
  esac
  case "$VASEY_BACKUP_REMOTE_PRUNE" in
    0) ;;
    1) { [ -n "$VASEY_BACKUP_REMOTE" ] && [ "$VASEY_BACKUP_RETENTION" = tiered ]; } \
         || { log "refused: VASEY_BACKUP_REMOTE_PRUNE=1 needs VASEY_BACKUP_REMOTE and VASEY_BACKUP_RETENTION=tiered (rsync destinations are never pruned from this host)"; return 1; } ;;
    *) log "refused: VASEY_BACKUP_REMOTE_PRUNE must be 0 or 1"; return 1 ;;
  esac
}

rclone_run() { "$VASEY_BACKUP_RCLONE_BIN" --config "$VASEY_BACKUP_RCLONE_CONFIG" "$@"; }

remote_join() {
  case "$VASEY_BACKUP_REMOTE" in
    *:|*/) printf '%s%s' "$VASEY_BACKUP_REMOTE" "$1" ;;
    *) printf '%s/%s' "$VASEY_BACKUP_REMOTE" "$1" ;;
  esac
}

# Tars one verified set and encrypts it to every recipient. The plaintext never leaves the host and no key is needed here.
encrypt_set() {  # encrypt_set SET_DIR STAGE_DIR -> STAGE_DIR/<set>.tar.age and its .sha256
  local bk=$1 stage=$2 name need avail
  name=$(basename "$bk")
  need=$(du -sb -- "$bk" | cut -f1)
  avail=$(df --output=avail -B1 -- "$stage" | tail -n 1 | tr -d ' ')
  [[ "$need" =~ ^[0-9]+$ && "$avail" =~ ^[0-9]+$ ]] && [ "$avail" -gt $((need + need / 20 + 1048576)) ] \
    || { log "refused: not enough free space in the backup directory for the encrypted copy (needs about the size of the set)"; return 1; }
  if ! tar --create --file=- --directory="$(dirname "$bk")" -- "$name" \
       | "$VASEY_BACKUP_AGE_BIN" --encrypt -R "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" -o "$stage/$name.tar.age"; then
    log "age encryption of $name failed"; return 1
  fi
  [ -s "$stage/$name.tar.age" ] && [ "$(head -c 21 -- "$stage/$name.tar.age")" = 'age-encryption.org/v1' ] \
    || { log "the encrypted file is empty or not an age file"; return 1; }
  (cd "$stage" && sha256sum -- "$name.tar.age" > "$name.tar.age.sha256") || return 1
}

# True when DIR holds a complete kept copy of NAME's ciphertext that was encrypted to the recipients configured now.
retry_copy_ok() {  # retry_copy_ok DIR NAME
  local dir=$1 name=$2 f
  [ -d "$dir" ] && [ ! -L "$dir" ] || return 1
  for f in "$name.tar.age" "$name.tar.age.sha256" recipients.sha256; do
    [ -f "$dir/$f" ] && [ ! -L "$dir/$f" ] || return 1
  done
  [ "$(head -c 21 -- "$dir/$name.tar.age")" = 'age-encryption.org/v1' ] \
    && (cd "$dir" && sha256sum -- "$name.tar.age" | cmp -s - "$name.tar.age.sha256") \
    && sha256sum < "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" | cmp -s - "$dir/recipients.sha256"
}

# Prints which of the given names exist at the top of the remote, sorted. A path that does not exist yet (rclone exit 3) holds none.
# `lsf` lists names on every backend, with or without hashes.
remote_names() {  # remote_names NAME...
  local out n rc=0
  local -a filters=()
  for n in "$@"; do filters+=(--include "/$n"); done
  out=$(rclone_run lsf "${filters[@]}" "$VASEY_BACKUP_REMOTE") || rc=$?
  case "$rc" in
    0) printf '%s\n' "$out" | sed '/^$/d' | LC_ALL=C sort ;;
    3) ;;
    *) return 1 ;;
  esac
}

# Prints the size of NAME at the top of the remote, or nothing when it is absent or the remote cannot be listed.
remote_size() {  # remote_size NAME
  rclone_run lsf --format sp --include "/$1" "$VASEY_BACKUP_REMOTE" 2>/dev/null | awk -F';' -v n="$1" '$2 == n { print $1 }'
}

retry_copies_summary() {  # prints "N kept retry copies, B bytes" for this host
  local d count=0 bytes=0
  for d in "$VASEY_BACKUP_DIR"/.offhost-retry.*; do
    [ -d "$d" ] && [ ! -L "$d" ] || continue
    count=$((count + 1)); bytes=$((bytes + $(du -sb -- "$d" | cut -f1)))
  done
  printf '%s kept retry cop%s, %s bytes' "$count" "$([ "$count" = 1 ] && echo y || echo ies)" "$bytes"
}

# age to an rclone remote. Each file goes with `rclone copy --immutable` and one anchored `--include`: unlike `copyto`, which rclone
# 1.60.1 lets replace a differing object, `copy` refuses to change an existing remote object and accepts an identical one as present
# (docs/ops/backup-offhost-hardening.md). age never encrypts the same set to the same bytes twice, so an attempt that sent part of the
# set can only be completed by re-sending exactly those bytes. They wait, ciphertext and digests only, in a private per-set directory
# until the transfer is verified, and only while the remote holds the ciphertext they belong to. A copy encrypted to other recipients,
# one that no longer matches its digest and one that is not age ciphertext are never sent.
# Before a fresh encryption the remote must hold neither name: on a backend with no hash in common with the local disk rclone compares
# sizes only, and age output for one set always has the same size, so `--immutable` alone would take a different ciphertext for the one
# already there. The exception is a set this host recorded as shipped whose remote digest and ciphertext size still match the record:
# "already shipped" (a size check of the ciphertext, not a content check).
ship_encrypted_rclone() {  # ship_encrypted_rclone SET_DIR STAGE_DIR; returns nonzero without exiting
  local bk=$1 stage=$2 name retry shipped present listed recorded remote size rc=0
  name=$(basename "$bk")
  retry=$VASEY_BACKUP_DIR/.offhost-retry.$name
  shipped=$VASEY_BACKUP_DIR/.offhost-shipped.$name
  if retry_copy_ok "$retry" "$name"; then
    log "re-sending the encrypted copy of $name kept from an earlier attempt"
  else
    present=$(remote_names "$name.tar.age" "$name.tar.age.sha256") || { log "could not list the remote; nothing was sent"; return 1; }
    if [ -n "$present" ]; then
      # Both sizes must be plain digits and equal: a record with no size, and a listing that returned none, are not a match.
      if [ "$present" = "$name.tar.age"$'\n'"$name.tar.age.sha256" ] && [ -f "$shipped" ] && [ ! -L "$shipped" ] \
         && rclone_run cat "$(remote_join "$name.tar.age.sha256")" | cmp -s - <(sed -n 1p "$shipped") \
         && recorded=$(sed -n 's/^size=//p' "$shipped") && remote=$(remote_size "$name.tar.age") \
         && [[ "$recorded" =~ ^[0-9]+$ ]] && [[ "$remote" =~ ^[0-9]+$ ]] && [ "$recorded" = "$remote" ]; then
        ALREADY_SHIPPED=1
        return 0
      fi
      log "refused: the remote already holds ${present//$'\n'/ } and this host has no verified record or usable kept copy for $name; nothing was sent or overwritten. If it is a partial attempt whose kept copy is gone, it cannot be completed: remove that object first"
      return 1
    fi
    rm -rf --one-file-system -- "$retry" || return 1
    encrypt_set "$bk" "$stage" || return 1
    sha256sum < "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" > "$stage/recipients.sha256" || return 1
    mv -T -- "$stage" "$retry" || return 1
  fi
  # Verified when rclone check passes (hashes, or sizes where the backend has none) and the remote digest file says what ours says.
  if ! { rclone_run copy --checksum --immutable --include "/$name.tar.age" "$retry" "$VASEY_BACKUP_REMOTE" \
         && rclone_run copy --checksum --immutable --include "/$name.tar.age.sha256" "$retry" "$VASEY_BACKUP_REMOTE" \
         && rclone_run check --one-way --include "$name.tar.age" --include "$name.tar.age.sha256" "$retry" "$VASEY_BACKUP_REMOTE" \
         && rclone_run cat "$(remote_join "$name.tar.age.sha256")" | cmp -s - "$retry/$name.tar.age.sha256"; }; then
    # Drop the copy only when the remote was listed and holds no ciphertext of this set; if it could not be listed (an outage, say)
    # the ciphertext may have landed, so the copy stays for the retry (the cap bounds the disk it takes).
    listed=$(remote_names "$name.tar.age" 2>/dev/null) || rc=$?
    if [ "$rc" = 0 ] && [ -z "$listed" ]; then
      rm -rf --one-file-system -- "$retry"
      log "the remote holds no ciphertext of $name, so no copy is kept; a later \`ship\` encrypts the set again"
    else
      log "kept the encrypted copy of $name in $retry ($([ "$rc" = 0 ] && echo 'the remote holds its ciphertext' || echo 'the remote could not be listed')): a later \`ship\` of this set re-sends the same bytes; a remote object that differs is refused and never overwritten"
    fi
    return 1
  fi
  # The size comes first so a failed stat cannot leave a record that has a digest and an empty size.
  if size=$(stat -c %s -- "$retry/$name.tar.age") && [[ "$size" =~ ^[0-9]+$ ]] \
     && { cat -- "$retry/$name.tar.age.sha256" && echo "size=$size"; } > "$shipped.new" \
     && mv -T -- "$shipped.new" "$shipped"; then
    :
  else
    rm -f -- "$shipped.new" 2>/dev/null || true
    log "WARNING: could not record $name as shipped"
  fi
  rm -rf --one-file-system -- "$retry" || log "WARNING: could not remove $retry after a verified transfer"
}

ship_hardened() {
  local bk=$1 name stage retry kept n=0 rc=0
  local -a others=()
  name=$(basename "$bk")
  [[ "$name" =~ $SET_NAME_RE ]] && [ -d "$bk" ] && [ ! -L "$bk" ] || die "not a backup set directory: $name"
  grep -qx 'result=RESTORE_VERIFIED' "$bk/RESTORE_CHECK" 2>/dev/null \
    || die "$name has no verified restore proof; only a proven set is shipped off-host (run restore-check first)"
  offhost_config_ok || die "off-host configuration refused; nothing was shipped"
  if [ -z "$VASEY_BACKUP_DEST" ] && [ -z "$VASEY_BACKUP_REMOTE" ]; then
    log "WARNING: no off-host destination (VASEY_BACKUP_DEST or VASEY_BACKUP_REMOTE); $name exists on this host only (NOT an off-host backup)"
    return 0
  fi
  # One hardened ship at a time, whatever started it (nightly, a deploy or a manual `ship`): a second one refuses (or, from
  # predeploy, waits up to SHIP_LOCK_WAIT seconds) before it removes anything, so a live staging directory or a kept retry copy is
  # never deleted under a running transfer. Pruning (nightly, after the resume) runs outside this lock: it never touches staging
  # directories or kept copies, and a manual ship of a set that pruning removes at that moment fails rather than ship anything else.
  [ ! -L "$VASEY_BACKUP_DIR/.offhost.lock" ] || die "refusing a symlinked $VASEY_BACKUP_DIR/.offhost.lock"
  exec 6>>"$VASEY_BACKUP_DIR/.offhost.lock" || die "cannot open the off-host shipping lock"
  if [ "$SHIP_LOCK_WAIT" -gt 0 ]; then flock -w "$SHIP_LOCK_WAIT" 6; else flock -n 6; fi \
    || die "another off-host ship is running (it holds $VASEY_BACKUP_DIR/.offhost.lock; waited ${SHIP_LOCK_WAIT}s); nothing was removed or shipped, retry when it has finished"
  # A run killed mid-transfer leaves its private staging directory behind. Under the lock no other hardened ship is running, so
  # one is a leftover; even so, remove only stale ones (untouched for over a day) and only real directories of this exact name shape.
  find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '.ship.??????' -mmin +1440 -exec rm -rf --one-file-system -- {} + 2>/dev/null || true
  # A kept retry copy or shipped record is usable only while its set is here (shipping needs the set and its proof): drop those of
  # pruned sets.
  for retry in "$VASEY_BACKUP_DIR"/.offhost-retry.* "$VASEY_BACKUP_DIR"/.offhost-shipped.*; do
    kept=${retry##*/.offhost-retry.}; kept=${kept##*/.offhost-shipped.}
    if { [ -d "$retry" ] || [ -f "$retry" ]; } && [ ! -L "$retry" ] && [[ "$kept" =~ $SET_NAME_RE ]] \
       && [ ! -e "$VASEY_BACKUP_DIR/$kept" ] && [ ! -L "$VASEY_BACKUP_DIR/$kept" ]; then
      rm -rf --one-file-system -- "$retry"
    fi
  done
  # Cap the kept copies at RETRY_COPIES_MAX in all: keep the newest sets' copies, drop older ones, never the set being shipped.
  mapfile -t others < <(find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '.offhost-retry.20*' -printf '%f\n' | LC_ALL=C sort -r)
  for retry in "${others[@]}"; do
    kept=${retry#.offhost-retry.}
    [[ "$kept" =~ $SET_NAME_RE ]] && [ "$kept" != "$name" ] || continue
    n=$((n + 1))
    if [ "$n" -ge "$RETRY_COPIES_MAX" ]; then
      rm -rf --one-file-system -- "${VASEY_BACKUP_DIR:?}/$retry"
      log "dropped the kept copy of $kept (at most $RETRY_COPIES_MAX are kept); if the remote holds a partial $kept.tar.age, remove it before shipping that set again"
    fi
  done
  stage=$(mktemp -d "$VASEY_BACKUP_DIR/.ship.XXXXXX") || die "cannot create the private shipping directory"
  ship_hardened_transfer "$bk" "$stage" || rc=$?
  rm -rf --one-file-system -- "$stage"
  exec 6>&-
  [ -z "$VASEY_BACKUP_REMOTE" ] || log "off-host retry copies on this host: $(retry_copies_summary)"
  [ "$rc" = 0 ] || die "off-host transfer of $name failed; the local set is intact"
  if [ "$ALREADY_SHIPPED" = 1 ]; then log "$name is already shipped: this host recorded a verified transfer and the remote digest still matches; nothing was sent"; return 0; fi
  log "shipped $name ($([ -n "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" ] && echo 'age-encrypted' || echo 'unencrypted by this script') via $([ -n "$VASEY_BACKUP_REMOTE" ] && echo rclone || echo rsync))"
}

ship_hardened_transfer() {  # ship_hardened_transfer SET_DIR STAGE_DIR; returns nonzero without exiting so the caller cleans up
  local bk=$1 stage=$2 name payload
  name=$(basename "$bk")
  if [ -n "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" ]; then
    if [ -n "$VASEY_BACKUP_REMOTE" ]; then
      ship_encrypted_rclone "$bk" "$stage" || return 1
    else
      encrypt_set "$bk" "$stage" || return 1
      [ -f "$VASEY_BACKUP_SSH_KEY" ] || { log "missing $VASEY_BACKUP_SSH_KEY for rsync to the backup destination"; return 1; }
      rsync -a --chmod=D700,F600 -e "ssh -i $VASEY_BACKUP_SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=yes" \
        -- "$stage/$name.tar.age" "$stage/$name.tar.age.sha256" "$VASEY_BACKUP_DEST/" || return 1
    fi
  else
    # No recipients: only reachable with an rclone remote (the rsync-only default path never gets here). The operator may
    # use an rclone crypt remote; this script does not know, so it says so in the log.
    log "WARNING: VASEY_BACKUP_ALLOW_UNENCRYPTED_REMOTE=1 and no age recipients: $name is sent as stored; the rclone remote or the destination is the only encryption"
    payload=$(remote_join "$name")
    rclone_run copy --checksum --immutable "$bk" "$payload" || return 1
    rclone_run check --one-way "$bk" "$payload" || return 1
  fi
}

ship() {
  local bk=${1:?backup directory}
  if hardening_requested; then ship_hardened "$bk"; return; fi
  if [ -z "$VASEY_BACKUP_DEST" ]; then
    log "WARNING: VASEY_BACKUP_DEST is unset; $(basename "$bk") exists on this host only (NOT an off-host backup)"
    return 0
  fi
  [ -f "$VASEY_BACKUP_SSH_KEY" ] || die "missing $VASEY_BACKUP_SSH_KEY for rsync to the backup destination"
  # Modes travel unchanged: the full proof of the copy (restore-check, rollback case B) compares every private file's mode with
  # the one its index recorded (.gitignore 0644, contracts 0400), so rewriting them would make every copy fail its proof. The
  # copy is protected by its top directory instead: the set directory is root-only 0700 (snapshot creates it so) and rsync -a
  # gives the copy that same mode, so nothing inside it is reachable by anyone else on the destination.
  [ ! -L "$bk" ] && [ "$(stat -c '%u %a' -- "$bk")" = '0 700' ] \
    || die "refusing to ship $(basename "$bk"): the set directory must be root-owned and owner-only (0700), because its copy keeps that mode and it is what protects the copy"
  rsync -a -e "ssh -i $VASEY_BACKUP_SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=yes" \
    -- "$bk" "$VASEY_BACKUP_DEST/" || die "rsync to the backup destination failed"
  log "shipped $(basename "$bk")"
}

# ---- deferred off-host shipping: queue while quiesced, ship after the site is back up ----
# 2026-11-11: shipping the ~38 GB encrypted set inside the maintenance window added ~15 minutes to every deploy, and a remote
# failure (a B2 403 storage_cap_exceeded) failed the deploy before the switch and left the site down. A set that has its local
# snapshot and restore proof is already a usable rollback point; the off-host copy is a second line of defence and must never
# hold the site down or fail an operation whose local proof succeeded.
offhost_destination_configured() { [ -n "$VASEY_BACKUP_DEST" ] || [ -n "$VASEY_BACKUP_REMOTE" ]; }

pending_marker() { printf '%s/.offhost-pending.%s' "$VASEY_BACKUP_DIR" "$1"; }

is_pending() {  # is_pending SET_NAME
  local marker; marker=$(pending_marker "$1")
  [[ "$1" =~ $SET_NAME_RE ]] && [ -f "$marker" ] && [ ! -L "$marker" ]
}

# Names of the pending sets, newest first. Only well-formed regular marker files count, so a stray or linked file is ignored.
pending_names() {
  local f n
  while IFS= read -r f; do
    n=${f#.offhost-pending.}
    [[ "$n" =~ $SET_NAME_RE ]] && printf '%s\n' "$n"
  done < <(find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type f -name '.offhost-pending.20*' -printf '%f\n' 2>/dev/null | LC_ALL=C sort -r)
  return 0
}

pending_field() {  # pending_field SET_NAME KEY -> value (empty when absent)
  sed -n "s/^$2=//p" "$(pending_marker "$1")" 2>/dev/null | head -n 1
}

# Writes the whole marker atomically: pending_write SET_NAME QUEUED ATTEMPTS LAST_ATTEMPT LAST_RESULT LAST_REASON.
# The reason is sanitized to a short printable line: no newline, no value that could be parsed as another key.
pending_write() {
  local name=$1 marker tmp reason=${6:-}
  marker=$(pending_marker "$name")
  reason=$(printf '%s' "$reason" | tr '\n\r' '  ' | tr -c 'A-Za-z0-9 _.,:;()/=-' '?' | cut -c1-240)
  tmp=$(mktemp "$VASEY_BACKUP_DIR/.offhost-pending-new.XXXXXX") || return 1
  if printf '%s\n' "set=$name" "queued_utc=$2" "attempts=$3" "last_attempt_utc=${4:-}" "last_result=${5:-}" "last_reason=$reason" > "$tmp" \
     && mv -T -- "$tmp" "$marker"; then return 0; fi
  rm -f -- "$tmp"; return 1
}

# Queues one set for a later off-host copy. Never fails the caller's operation: the local set and its proof already stand.
mark_pending() {  # mark_pending SET_DIR
  local name; name=$(basename -- "${1:-}")
  if ! offhost_destination_configured; then
    log "no off-host destination (VASEY_BACKUP_DEST or VASEY_BACKUP_REMOTE); $name exists on this host only (NOT an off-host backup)"
    return 0
  fi
  [[ "$name" =~ $SET_NAME_RE ]] || { log "WARNING: not a backup set name, so it was not queued for the off-host copy: $name"; return 0; }
  # A bad configuration is reported now, while someone may be watching; the set is queued anyway and ship-pending records the failure.
  offhost_config_ok || log "WARNING: the off-host configuration is refused (reason above); $name is queued and its shipment will be recorded as failed until that is fixed"
  if pending_write "$name" "$(date -u +%FT%TZ)" 0 '' '' ''; then
    log "queued $name for the off-host copy; it ships after the site is back up (vasey-staging-backup pending)"
  else
    log "WARNING: could not queue $name for the off-host copy; run 'vasey-staging-backup ship $VASEY_BACKUP_DIR/$name' once the site is up"
  fi
  return 0
}

clear_pending() {  # clear_pending SET_NAME: forget a set that now has its off-host copy (or was abandoned)
  local marker; marker=$(pending_marker "$1")
  if [[ "$1" =~ $SET_NAME_RE ]] && [ -f "$marker" ] && [ ! -L "$marker" ]; then rm -f -- "$marker"; fi
  return 0
}

# True while a hardened ship holds the off-host lock (read-only probe: it never takes the lock and never creates the file).
offhost_ship_running() {
  local lock=$VASEY_BACKUP_DIR/.offhost.lock
  [ -f "$lock" ] && [ ! -L "$lock" ] || return 1
  ! ( flock -n 4 ) 4<"$lock" 2>/dev/null
}

# True while a ship-pending run is alive, including the moments between two sets when .offhost.lock is free.
ship_pending_running() {
  local lock=$VASEY_BACKUP_DIR/.ship-pending.lock
  [ -f "$lock" ] && [ ! -L "$lock" ] || return 1
  ! ( flock -n 4 ) 4<"$lock" 2>/dev/null
}

# A failed snapshot (or a refused space check) leaves a stamp; a later "daily ok" for an older set must not hide it.
note_snapshot_failed() {
  printf '%s\n' "$(date -u +%Y%m%dT%H%M%SZ)" > "$VASEY_BACKUP_DIR/.snapshot-failed" 2>/dev/null \
    || log "WARNING: could not record the snapshot failure stamp"
  return 0
}

# "daily ok" from a shipment is honest only for the newest proven set, with no snapshot failure after that set was taken.
# Otherwise an older set draining from the backlog would turn a failed nightly green.
evidence_ok_allowed() {  # evidence_ok_allowed SET_NAME
  local name=$1 newest='' d stamp
  while IFS= read -r d; do
    if grep -qx 'result=RESTORE_VERIFIED' "$VASEY_BACKUP_DIR/$d/RESTORE_CHECK" 2>/dev/null; then newest=$d; break; fi
  done < <(find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' | LC_ALL=C sort -r)
  if [ "$newest" != "$name" ]; then
    log "shipped $name, but it is not the newest proven set (${newest:-none}); no dashboard success recorded for it"; return 1
  fi
  stamp=$(head -c 16 -- "$VASEY_BACKUP_DIR/.snapshot-failed" 2>/dev/null || true)
  if [[ "$stamp" =~ ^20[0-9]{6}T[0-9]{6}Z$ ]] && [[ "$stamp" > "${name:0:16}" ]]; then
    log "shipped $name, but a snapshot failed after it was taken ($stamp); no dashboard success recorded"; return 1
  fi
  return 0
}

# Ships every pending set, newest first, with the site up. It holds its own run lock (a second start exits quietly), and each
# set goes through `ship`, which takes the existing off-host lock. Sets queued while it runs are picked up before it exits. The
# first failure stops the run (a remote problem fails every set, and a retry would only repeat it); that set and the rest stay
# pending for the next nightly or deploy. Returns nonzero after a failure, but its callers only start it detached.
ship_pending() {
  local lock=$VASEY_BACKUP_DIR/.ship-pending.lock name n bk out reason rc attempted=' ' ok=0 failed=0 busy=0 newest_ok='' count queued attempts
  [ -d "$VASEY_BACKUP_DIR" ] || die "no backup directory"
  [ ! -L "$lock" ] || die "refusing a symlinked $lock"
  exec 5>>"$lock" || die "cannot open the ship-pending lock"
  if ! flock -n 5; then log "another ship-pending run is active; it also picks up sets queued while it runs"; exec 5>&-; return 0; fi
  count=$(pending_names | grep -c . || true)
  if [ "$count" -gt "$PENDING_WARN" ]; then
    log "WARNING: $count sets wait for an off-host copy (each is about a set of disk on this host and none is pruned); fix the remote, or abandon one with 'pending-clear SET'"
  fi
  while :; do
    name=''
    while IFS= read -r n; do
      case "$attempted" in *" $n "*) continue ;; esac
      name=$n; break
    done < <(pending_names)
    [ -n "$name" ] || break
    attempted="$attempted$name "
    bk=$VASEY_BACKUP_DIR/$name
    if [ ! -d "$bk" ] || [ -L "$bk" ]; then
      log "WARNING: pending set $name is no longer on this host; its marker is dropped"
      clear_pending "$name"; continue
    fi
    queued=$(pending_field "$name" queued_utc || true); attempts=$(pending_field "$name" attempts || true)
    is_pending "$name" || { log "pending marker of $name vanished; skipping it"; continue; }
    [[ "$attempts" =~ ^[0-9]{1,6}$ ]] || attempts=0
    # A set whose restore proof was withdrawn (a failed re-verification) is never shipped as good. Skip it with its own reason and
    # go on with the others: it is not a remote failure. It stays pending until it is proven again or abandoned (pending-clear).
    if ! grep -qx 'result=RESTORE_VERIFIED' "$bk/RESTORE_CHECK" 2>/dev/null; then
      pending_write "$name" "${queued:-$(date -u +%FT%TZ)}" "$attempts" "$(date -u +%FT%TZ)" skipped \
        "no verified restore proof (withdrawn or never published); prove it with restore-check or abandon it with pending-clear" || true
      log "WARNING: pending set $name has no verified restore proof; skipped (restore-check it, or pending-clear it)"
      continue
    fi
    pending_write "$name" "${queued:-$(date -u +%FT%TZ)}" "$((attempts + 1))" "$(date -u +%FT%TZ)" started '' || true
    log "shipping pending set $name off-host (attempt $((attempts + 1)); the site stays up)"
    out=$(mktemp "$VASEY_BACKUP_DIR/.ship-pending-out.XXXXXX") || die "cannot create a scratch file in $VASEY_BACKUP_DIR"
    rc=0
    if ! offhost_destination_configured; then
      # ship would only warn and succeed, which must not clear a set that was never sent.
      echo "no off-host destination is configured any more (VASEY_BACKUP_DEST or VASEY_BACKUP_REMOTE)" | tee -- "$out" || true
      rc=1
    else
      ( SHIP_LOCK_WAIT=$SHIP_PENDING_LOCK_WAIT; ship "$bk" ) 2>&1 | tee -- "$out" || rc=$?
    fi
    if [ "$rc" = 0 ]; then
      clear_pending "$name"
      ok=$((ok + 1)); [ -n "$newest_ok" ] || newest_ok=$name
      rm -f -- "$out"
    elif grep -q 'another off-host ship is running' "$out"; then
      # A manual ship held the lock past the wait: busy, not broken. Nothing was removed or sent; the next run retries.
      pending_write "$name" "${queued:-$(date -u +%FT%TZ)}" "$((attempts + 1))" "$(date -u +%FT%TZ)" busy "another off-host ship held the lock" || true
      rm -f -- "$out"; busy=1
      log "the off-host lock stayed busy; $name stays pending and the next run retries (no failure recorded)"
      break
    else
      # The first lines that name an error carry the cause (an rclone 403, a refused configuration); the closing summary follows.
      reason=$(grep -v '^$' "$out" | grep -iE 'error|fail|denied|refus|forbidden|exceed|unable|cannot|no space|[45][0-9]{2}' | head -n 3 | tr '\n' ' ' || true)
      [ -n "$reason" ] || reason=$(grep -v '^$' "$out" | tail -n 2 | tr '\n' ' ' || true)
      pending_write "$name" "${queued:-$(date -u +%FT%TZ)}" "$((attempts + 1))" "$(date -u +%FT%TZ)" failed "$reason" || true
      rm -f -- "$out"
      failed=1
      log "WARNING: the off-host shipment of $name failed (exit $rc); it stays pending and the local set is intact. This never fails a deploy or the nightly run; the next nightly run retries."
      break
    fi
  done
  # Remote retention runs here, after the uploads and under the run lock, never beside a detached upload; a manual ship holding
  # .offhost.lock defers it to the next run.
  if [ "$failed" = 0 ] && [ "$busy" = 0 ]; then
    if offhost_ship_running; then log "remote retention deferred: a manual ship holds the off-host lock"
    else prune_remote || log "WARNING: remote retention did not complete; the remote keeps its sets until the next successful run"; fi
  fi
  exec 5>&-
  # Recorded last and outside the run lock. Never changes the outcome: a failure to record is a logged warning.
  if [ "$failed" = 1 ]; then record_evidence daily failed
  elif [ "$ok" -gt 0 ]; then
    if evidence_ok_allowed "$newest_ok"; then record_evidence daily ok "$VASEY_BACKUP_DIR/$newest_ok"; fi
  elif [ "$busy" = 1 ]; then :
  else log "no off-host shipments pending"; fi
  [ "$failed" = 0 ]
}

# Starts ship-pending detached from the caller (a deploy under Forge, the ctl lease, the nightly run): it must not hold the
# deploy lock or the helper lock, and it must outlive a runner that stops reading. A transient systemd unit has no inherited
# file descriptors; without systemd-run a setsid'd child gets every lock descriptor closed explicitly. Never fails the caller.
ship_pending_start() {
  offhost_destination_configured || return 0
  if [ -z "$(pending_names)" ]; then log "no off-host shipments pending"; return 0; fi
  if command -v systemd-run >/dev/null 2>&1 \
     && timeout 30 systemd-run --quiet --collect --unit="$SHIP_PENDING_UNIT" --property=Nice=10 \
          --description="VASEY.AUDIO off-host backup shipment (pending sets)" \
          /bin/bash -c 'exec "$0" ship-pending >>"$1" 2>&1' "$BACKUP_SELF" "$SHIP_PENDING_LOG" </dev/null >/dev/null 2>&1; then
    log "off-host shipment started as ${SHIP_PENDING_UNIT}.service; progress: $SHIP_PENDING_LOG"
    return 0
  fi
  if [ -x "$BACKUP_SELF" ] && command -v setsid >/dev/null 2>&1 \
     && ( setsid "$BACKUP_SELF" ship-pending >>"$SHIP_PENDING_LOG" 2>&1 </dev/null 5>&- 6>&- 7>&- 8>&- 9>&- & ) 2>/dev/null; then
    log "off-host shipment started detached (no systemd unit); progress: $SHIP_PENDING_LOG"
    return 0
  fi
  log "WARNING: could not start the off-host shipment; the set stays pending and the next nightly run retries (or run: vasey-staging-backup ship-pending)"
  return 0
}

pending_report() {
  local n any=0
  while IFS= read -r n; do
    any=1
    printf '%s queued=%s attempts=%s last_attempt=%s last_result=%s%s\n' "$n" "$(pending_field "$n" queued_utc)" "$(pending_field "$n" attempts)" \
      "$(pending_field "$n" last_attempt_utc)" "$(pending_field "$n" last_result)" \
      "$([ -z "$(pending_field "$n" last_reason)" ] || printf ' reason="%s"' "$(pending_field "$n" last_reason)")"
  done < <(pending_names)
  [ "$any" = 1 ] || echo "no sets are waiting for an off-host copy"
}

# Tiered retention planner. stdin: set names (any order, anything malformed is ignored and so never pruned).
# stdout: the names to prune. Keeps the union of the newest KEEP sets, the newest set of each of the last DAILY
# days / WEEKLY ISO weeks / MONTHLY months, and PROTECT (the newest set that carries a restore proof).
# It can only retain more than the flat `keep newest N` rule does, never less.
retention_plan() {  # retention_plan KEEP DAILY WEEKLY MONTHLY [PROTECT]
  local keep=$1 daily=$2 weekly=$3 monthly=$4 protect=${5:-} name day week month n=0 d=0 w=0 m=0
  local -A kept=() seen_day=() seen_week=() seen_month=()
  local -a names=()
  mapfile -t names < <(LC_ALL=C sort -r)
  for name in "${names[@]}"; do
    [[ "$name" =~ $SET_NAME_RE ]] || continue
    n=$((n + 1))
    [ "$n" -le "$keep" ] && kept[$name]=1
    day=${name:0:8}
    if [ -z "${seen_day[$day]:-}" ]; then
      seen_day[$day]=1; d=$((d + 1)); [ "$d" -le "$daily" ] && kept[$name]=1
    fi
    week=$(date -u -d "${name:0:4}-${name:4:2}-${name:6:2}" +%G-%V 2>/dev/null) || continue
    if [ -z "${seen_week[$week]:-}" ]; then
      seen_week[$week]=1; w=$((w + 1)); [ "$w" -le "$weekly" ] && kept[$name]=1
    fi
    month=${name:0:6}
    if [ -z "${seen_month[$month]:-}" ]; then
      seen_month[$month]=1; m=$((m + 1)); [ "$m" -le "$monthly" ] && kept[$name]=1
    fi
  done
  [ -z "$protect" ] || kept[$protect]=1
  for name in "${names[@]}"; do
    [[ "$name" =~ $SET_NAME_RE ]] || continue
    [ -n "${kept[$name]:-}" ] || printf '%s\n' "$name"
  done
}

prune_local_tiered() {  # prune_local_tiered [EXTRA_PROTECT...]: names never removed besides the newest verified set
  offhost_config_ok || die "retention configuration refused; nothing was pruned"
  local -a extra=("$@")
  local protect='' d
  while IFS= read -r d; do
    if grep -qx 'result=RESTORE_VERIFIED' "$VASEY_BACKUP_DIR/$d/RESTORE_CHECK" 2>/dev/null; then protect=$d; break; fi
  done < <(find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' | LC_ALL=C sort -r)
  find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' \
    | retention_plan "$VASEY_BACKUP_KEEP" "$VASEY_BACKUP_KEEP_DAILY" "$VASEY_BACKUP_KEEP_WEEKLY" "$VASEY_BACKUP_KEEP_MONTHLY" "$protect" \
    | while IFS= read -r d; do
      case " ${extra[*]} " in *" $d "*) continue ;; esac
      rm -rf --one-file-system -- "${VASEY_BACKUP_DIR:?}/$d"
    done
}

# rclone remotes only, and only when asked (VASEY_BACKUP_REMOTE_PRUNE=1). A host that can delete its own off-host
# backups can lose them to whoever compromises it: prefer retention enforced at the destination (lifecycle rules or
# append-only credentials) and leave this off. rsync-over-SSH destinations are never pruned from here.
prune_remote() {
  [ "$VASEY_BACKUP_REMOTE_PRUNE" = 1 ] || return 0
  offhost_config_ok || return 1
  local listing line entry name count=0
  local -a doomed=()
  listing=$(rclone_run lsf "$VASEY_BACKUP_REMOTE") || { log "WARNING: could not list the remote; nothing pruned"; return 1; }
  mapfile -t doomed < <(printf '%s\n' "$listing" | sed -e 's#/$##' -e 's#\.tar\.age\.sha256$##' -e 's#\.tar\.age$##' | LC_ALL=C sort -u \
    | retention_plan "$VASEY_BACKUP_KEEP" "$VASEY_BACKUP_KEEP_DAILY" "$VASEY_BACKUP_KEEP_WEEKLY" "$VASEY_BACKUP_KEEP_MONTHLY")
  for name in "${doomed[@]}"; do
    [ -n "$name" ] || continue
    while IFS= read -r line; do
      entry=${line%/}
      case "$entry" in
        "$name"|"$name.tar.age"|"$name.tar.age.sha256") ;;
        *) continue ;;
      esac
      if [ "$line" != "$entry" ]; then rclone_run purge "$(remote_join "$entry")" </dev/null || return 1
      else rclone_run deletefile "$(remote_join "$entry")" </dev/null || return 1; fi
    done <<< "$listing"
    count=$((count + 1))
  done
  log "remote retention: pruned $count set(s) beyond the tiered policy"
}

# prune_local [PROTECT]: local retention (flat newest-N, or tiered). PROTECT names one set that is never removed (the
# deploy's own snapshot); the newest set `.last` points at and every set still pending an off-host copy are always protected too
# (a pending set is the only copy that has not left this host; ship-pending clears the marker, or `pending-clear SET` abandons
# it, and only then does ordinary retention apply). Only well-formed set directories of
# VASEY_BACKUP_DIR are ever considered: .FAILED sets, staging directories, kept copies and anything outside it (such as a
# separately retained copy) are never touched. VASEY_BACKUP_KEEP below 1 is refused: it would remove every set.
prune_local() {
  local protect=${1:-} last='' d
  [ -z "$protect" ] || [[ "$protect" =~ $SET_NAME_RE ]] || die "prune: not a backup set name: $protect"
  if [ -f "$VASEY_BACKUP_DIR/.last" ] && [ ! -L "$VASEY_BACKUP_DIR/.last" ]; then
    last=$(basename -- "$(cat -- "$VASEY_BACKUP_DIR/.last" 2>/dev/null || true)")
    [[ "$last" =~ $SET_NAME_RE ]] || last=''
  fi
  local -a pending=()
  mapfile -t pending < <(pending_names)
  if [ "$VASEY_BACKUP_RETENTION" = tiered ]; then prune_local_tiered "$protect" "$last" "${pending[@]}"; return; fi
  local keep=$VASEY_BACKUP_KEEP
  [[ "$keep" =~ ^[0-9]{1,3}$ ]] && [ "$keep" -ge 1 ] || die "prune: VASEY_BACKUP_KEEP must be a whole number of at least 1; nothing was pruned"
  find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' \
    | sort -r | tail -n +"$((keep + 1))" | while IFS= read -r d; do
      [ "$d" != "$protect" ] && [ "$d" != "$last" ] || continue
      ! is_pending "$d" || continue
      rm -rf --one-file-system -- "${VASEY_BACKUP_DIR:?}/$d"
    done
}

# ---- free-space pre-check (the deploy runs it BEFORE any quiesce) ----
# A snapshot needs room in the backup directory for the dump and for the private files it COPIES (files it shares with the
# previous verified set take no space; private-snapshot.py estimate counts them exactly as the snapshot would decide, and without
# it the whole store is counted), and the restore proof's disposable datadir under VASEY_RESTORE_CHECK_DIR (often the same
# filesystem; a tree set is verified in place, nothing is extracted). An age/rclone shipment stages one more encrypted copy of
# the WHOLE set (shared files included) in the backup directory, but only AFTER the deploy (ship-pending), when the restore proof's
# scratch is already gone; so on a shared filesystem the peak is the new bytes plus the larger of the two (proof scratch, encrypted
# copy), not both. The encrypted copy is still counted: a deploy the disk could not ship afterwards would leave the new set
# pending. 2026-11-10: five deploys left five 14 GB sets, the disk reached 96% and tar died mid-snapshot with the site already
# stopped. Refusing here leaves the served release untouched.
SPACE_MARGIN_PCT=20
SPACE_MARGIN_BYTES=268435456

# Database size estimate: the schema's data plus index bytes (an upper bound of the dump), else the newest set's dump.
snapshot_db_bytes() {
  local bytes='' newest=''
  if [[ "$VASEY_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
    bytes=$(mysql --defaults-extra-file="$VASEY_BACKUP_MYCNF" --batch --skip-column-names \
      -e "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '$VASEY_DB_NAME'" 2>/dev/null) || bytes=''
    bytes=${bytes%%.*}
  fi
  if [[ "$bytes" =~ ^[0-9]{1,15}$ ]]; then printf '%s' "$bytes"; return 0; fi
  newest=$(find "$VASEY_BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*Z-*' ! -name '*.FAILED' -printf '%f\n' 2>/dev/null | LC_ALL=C sort -r | head -n 1)
  if [ -n "$newest" ] && [ -f "$VASEY_BACKUP_DIR/$newest/database.sql" ]; then
    stat -c %s -- "$VASEY_BACKUP_DIR/$newest/database.sql"; return 0
  fi
  return 1
}

nearest_existing() {  # nearest_existing PATH -> PATH or its closest existing ancestor
  local p=$1
  while [ ! -e "$p" ] && [ "$p" != / ] && [ "$p" != . ]; do p=$(dirname -- "$p"); done
  printf '%s' "$p"
}

# --preverify re-hashes the newest proven tree set first (always); --preverify-if-needed does so only when no usable token exists
# yet (ctl prepare and refresh: the deploy's own `ctl space-check --preverify` has normally just made one, and a second 3-minute
# re-hash would only lengthen the deploy).
space_check() {  # space_check [--preverify|--preverify-if-needed]
  local priv db copy full set_need need restore_need ship_need avail restore_avail backup_at restore_at margin src estimate basis shared_with estimate_total
  if [ "${1:-}" = --preverify-if-needed ]; then
    if [ -n "$(link_token_set)" ]; then set --; else set -- --preverify; fi
  fi
  # Re-proving the shared set first (site still up) decides what the snapshot may share, and so what it must copy. A deploy
  # refuses on a failed re-proof, before anything is stopped: a backup set's files changed on disk, which needs a person.
  # The re-hash runs before the free-space arithmetic on purpose: what the snapshot may share (and so must not copy) is decided by
  # it. Checking space first would count a full copy and refuse deploys that fit; the re-hash itself writes only the token.
  if [ "${1:-}" = --preverify ]; then
    local preverified=0
    preverify_link_source || preverified=$?
    case "$preverified" in
      0) ;;
      1) die "refused: a backup set's private files failed re-verification (reason above; its proof and those of the sets holding the failed file were withdrawn); nothing was stopped. Check the disk and the sets (docs/ops/staging-runbook.md section 8, corruption refusal), then deploy again: the next snapshot copies the whole private store" ;;
      *) echo "vasey-staging-backup: refused: the re-verification of the backup set could not run (an operational error such as no space or an I/O error, not corruption; reason above). No restore proof was withdrawn and nothing was stopped. Fix the cause (docs/ops/staging-runbook.md section 8, operational refusal), then deploy again" >&2; exit 3 ;;
    esac
  fi
  [ -d "$PRIVATE" ] && [ ! -L "$PRIVATE" ] || die "private root missing"
  priv=$(du -sb -- "$PRIVATE" | cut -f1)
  db=$(snapshot_db_bytes) || die "cannot estimate the database size (query and newest set both unavailable); no snapshot attempted, nothing stopped"
  [[ "$priv" =~ ^[0-9]+$ && "$db" =~ ^[0-9]+$ ]] || die "cannot size the snapshot; nothing stopped"
  copy=$priv
  basis="no re-verified set to share with: the whole private store is counted"
  if [ -n "${PRIVATE_SNAPSHOT:-}" ] && [ -f "$PRIVATE_SNAPSHOT" ]; then
    src=''
    [ "${VASEY_BACKUP_LINK:-1}" != 1 ] || src=$(link_token_set) || src=''
    if [ -n "$src" ] && estimate=$(python3 -I "$PRIVATE_SNAPSHOT" estimate --live "$PRIVATE" --link-from "$src" \
         --link-token "$VASEY_BACKUP_DIR/.link-token" --link-max-age-hours "$LINK_MAX_AGE_HOURS" 2>/dev/null); then
      shared_with=$(printf '%s\n' "$estimate" | sed -n 's/^link_source=//p')
      estimate_total=$(printf '%s\n' "$estimate" | sed -n 's/^total_bytes=//p')
      estimate=$(printf '%s\n' "$estimate" | sed -n 's/^copy_bytes=//p')
      if [[ "$estimate" =~ ^[0-9]+$ ]]; then
        copy=$estimate
        # Name the sharing basis only when the estimate really shares bytes; otherwise the count is a full copy.
        if [ "$shared_with" != "$(basename -- "$src")" ]; then
          basis="the re-verification token of $(basename -- "$src") was refused: the whole private store is counted"
        elif [[ "$estimate_total" =~ ^[0-9]+$ ]] && [ "$estimate" -lt "$estimate_total" ]; then
          basis="sharing with the re-verified set $shared_with"
        else
          basis="the re-verification token of $shared_with was accepted, but no file is unchanged since it: the whole private store is counted"
        fi
      fi
    fi
  fi
  full=$((priv + db))      # the whole set: what an encrypted copy of it holds
  set_need=$((copy + db))  # what the snapshot writes
  need=$set_need
  ship_need=0
  # The shipment of an earlier set may still be encrypting (its staging copy grows to one whole set while this snapshot runs).
  if hardening_requested; then ship_need=$full; fi
  if hardening_requested && { offhost_ship_running || ship_pending_running; }; then need=$((need + full)); fi
  restore_need=$db   # restore proof: the disposable datadir (a tree set is verified in place; nothing is extracted)
  backup_at=$(nearest_existing "$VASEY_BACKUP_DIR"); restore_at=$(nearest_existing "$VASEY_RESTORE_CHECK_DIR")
  if [ "$(stat -c %d -- "$backup_at")" = "$(stat -c %d -- "$restore_at")" ]; then
    # Sequential on one filesystem: proof scratch during the quiesce, encrypted copy after the resume.
    need=$((need + (restore_need > ship_need ? restore_need : ship_need))); restore_need=0
  else
    need=$((need + ship_need))
  fi
  margin=$((need * SPACE_MARGIN_PCT / 100 + SPACE_MARGIN_BYTES))
  need=$((need + margin))
  avail=$(df --output=avail -B1 -- "$backup_at" | tail -n 1 | tr -d ' ')
  [[ "$avail" =~ ^[0-9]+$ ]] && [ "$avail" -ge "$need" ] \
    || die "not enough free disk space for the pre-deploy snapshot: $avail bytes free, about $need bytes needed (estimate: $basis; $copy of $priv private bytes to copy, database $db$(hardening_requested && echo ", one encrypted copy of the whole set $full"), margin included) in $VASEY_BACKUP_DIR; nothing was stopped. Free space with 'sudo vasey-staging-ctl backup-prune' (docs/ops/staging-runbook.md section 9, out of disk during snapshot)"
  if [ "$restore_need" -gt 0 ]; then
    restore_need=$((restore_need + restore_need * SPACE_MARGIN_PCT / 100 + SPACE_MARGIN_BYTES))
    restore_avail=$(df --output=avail -B1 -- "$restore_at" | tail -n 1 | tr -d ' ')
    [[ "$restore_avail" =~ ^[0-9]+$ ]] && [ "$restore_avail" -ge "$restore_need" ] \
      || die "not enough free disk space for the restore proof: $restore_avail bytes free, about $restore_need bytes needed in $VASEY_RESTORE_CHECK_DIR; nothing was stopped (docs/ops/staging-runbook.md section 9, out of disk during snapshot)"
  fi
  log "free space ok for the pre-deploy snapshot: $avail bytes free, about $need needed ($copy of $priv private bytes to copy; estimate: $basis)"
}

# ---- dashboard evidence (FP-028, docs/staff-reporting.md) ----
# Best effort: appends one row through `artisan vasey:backup-record` as the application user on the served release. It never
# changes the backup's own outcome or exit status: a failure to record is a logged WARNING, and the dashboard then shows the
# backup as stale once its age passes REPORTING_BACKUP_STALE_HOURS. Sends a kind, a status, the set's byte count and the dump's
# SHA-256 only: never a path, host name, credential or row. Its output is discarded, so nothing the application prints reaches
# this log.
record_evidence() {  # record_evidence KIND ok|failed [SET_DIR]
  local kind=$1 status=$2 bk=${3:-} cur='' bytes='' sha=''
  local -a args=(--kind="$kind" --status="$status")
  if [ -L "$VASEY_ROOT/current" ]; then cur=$(readlink -f -- "$VASEY_ROOT/current" 2>/dev/null) || cur=''; fi
  if [ -z "$cur" ] || [ ! -f "$cur/artisan" ] || [ -z "${VASEY_PHP:-}" ]; then
    log "WARNING: no served release or PHP binary; the $kind $status result was not recorded on the dashboard"
    return 0
  fi
  if [ "$status" = ok ] && [ -n "$bk" ] && [ -d "$bk" ] && [ ! -L "$bk" ]; then
    # Every file of the set, the private tree included (shared files count in full: this is the set's own size).
    bytes=$(find "$bk" -type f -printf '%s\n' 2>/dev/null | awk '{ s += $1 } END { printf "%.0f", s }') || bytes=''
    sha=$(head -c 64 -- "$bk/database.sql.sha256" 2>/dev/null) || sha=''
    [[ "$bytes" =~ ^[1-9][0-9]{0,15}$ ]] && args+=(--bytes="$bytes")
    [[ "$sha" =~ ^[0-9a-f]{64}$ ]] && args+=(--sha256="$sha")
  fi
  if (cd -- "$cur" && timeout -k 10 120 runuser -u "$VASEY_APP_USER" -- env -i PATH=/usr/bin:/bin LC_ALL=C \
      "$VASEY_PHP" "$cur/artisan" vasey:backup-record "${args[@]}" --no-interaction --no-ansi) </dev/null >/dev/null 2>&1 9>&-; then
    log "recorded $kind $status on the dashboard"
  else
    log "WARNING: could not record $kind $status on the dashboard; the backup result stands"
  fi
}

case "${1:-}" in
  nightly)
    install -d -m 0700 -o root -g root -- "$VASEY_BACKUP_DIR"
    exec 9>>"$VASEY_ROOT/.deploy.lock"
    flock -n 9 || die "a deploy or another backup holds the lock; skipping this run"
    # No space-check, no snapshot: pending sets are never pruned, so a long remote outage fills the disk. Refuse before the
    # quiesce (the site was never stopped), record the failure, and still drain the backlog.
    # Re-prove the set the snapshot will share, with the site up. A failure withdraws the affected proofs and leaves no token, so
    # tonight's snapshot copies everything; the backup itself still runs.
    preverify_link_source || log "WARNING: re-verification failed (reason above); tonight's snapshot copies the whole private store"
    if ! ( space_check ); then
      note_snapshot_failed
      record_evidence daily failed
      ship_pending_start
      die "no room for tonight's snapshot (reason above); nothing was stopped, the off-host backlog is shipping"
    fi
    "$CTL" quiesce
    snapshot_status=0
    # The serialized root helper re-proves stopped writers and holds its action lock through the
    # snapshot and the restore verification. A direct ctl resume cannot race the copy. It does NOT ship:
    # the off-host copy is queued as a pending marker and sent after the resume, with the site up.
    "$CTL" snapshot || snapshot_status=$?
    # Resume even when the snapshot failed: a failed backup must not leave the site down overnight.
    if [ -L "$VASEY_ROOT/current" ]; then "$CTL" resume; fi
    # After the resume, so the application can take the row. A run that stops before the snapshot (lock held, quiesce
    # refused) or a failed resume records nothing; the dashboard then turns stale instead of showing a fresh success.
    # With an off-host destination configured the "daily ok" row means the off-host copy exists too, so ship-pending records
    # it (ok, or failed) once the shipment finishes; until then the dashboard ages towards stale. Without one it is recorded now.
    if [ "$snapshot_status" = 0 ]; then
      offhost_destination_configured || record_evidence daily ok "$(cat -- "$VASEY_BACKUP_DIR/.last" 2>/dev/null || true)"
    else note_snapshot_failed; record_evidence daily failed; fi
    # Site is up: start the detached shipment of every pending set (tonight's, and any an earlier failure left). Even after a
    # failed snapshot, so earlier sets are retried. It never fails this run, whatever the remote does.
    ship_pending_start
    [ "$snapshot_status" = 0 ] || die "snapshot or restore proof failed; resume completed if a release was served"
    # Local retention protects every pending set. Remote retention (VASEY_BACKUP_REMOTE_PRUNE) runs at the end of ship-pending.
    prune_local
    ;;
  predeploy)
    install -d -m 0700 -o root -g root -- "$VASEY_BACKUP_DIR"
    snapshot
    # The files this snapshot shared were re-hashed before the quiesce (space-check --preverify) and are checked to be those very
    # inodes; the proof re-hashes everything the snapshot copied.
    restore_check "$(cat "$VASEY_BACKUP_DIR/.last")" incremental
    # Runs with the site quiesced, so nothing here may touch the network or take time proportional to the set: no age, no rclone,
    # no rsync. The off-host copy is queued and sent after the release is resumed and healthy (ctl activate/refresh and the
    # nightly run start ship-pending). Queuing never fails the deploy.
    mark_pending "$(cat "$VASEY_BACKUP_DIR/.last")"
    # Retention for deploy snapshots: five deploys in a day once kept five full sets. The set just taken (the deploy's rollback
    # point) and every set still pending an off-host copy are protected, and a prune failure never fails a deploy whose snapshot
    # and proof succeeded.
    ( prune_local "$(basename -- "$(cat "$VASEY_BACKUP_DIR/.last")")" ) || log "WARNING: local retention after the pre-deploy snapshot did not complete; run 'vasey-staging-ctl backup-prune'"
    ;;
  space-check)
    case "${2:-}" in ''|--preverify|--preverify-if-needed) ;; *) die "usage: vasey-staging-backup space-check [--preverify|--preverify-if-needed]" ;; esac
    space_check "${2:-}"
    ;;
  prune)
    [ -d "$VASEY_BACKUP_DIR" ] || die "no backup directory"
    prune_local
    log "local retention applied (keep ${VASEY_BACKUP_KEEP}, ${VASEY_BACKUP_RETENTION:-flat})"
    ;;
  restore-check) restore_check "${2:?backup directory}" ;;
  ship)
    ship "${2:?backup directory}"
    # A manual ship that succeeded satisfies the pending marker of that set (ship refuses a set that is not proven or not shipped).
    if offhost_destination_configured; then clear_pending "$(basename -- "$2")"; fi
    ;;
  ship-pending) ship_pending ;;
  ship-pending-start) ship_pending_start ;;
  pending) pending_report ;;
  pending-clear)
    is_pending "$(basename -- "${2:?set name}")" || die "not a pending set: $2"
    clear_pending "$(basename -- "$2")"
    log "abandoned the pending off-host copy of $(basename -- "$2"); ordinary retention applies to it again"
    ;;
  check-offhost)
    offhost_config_ok || die "off-host configuration refused"
    log "off-host configuration ok (encryption: $([ -n "$VASEY_BACKUP_AGE_RECIPIENTS_FILE" ] && echo age || { [ -n "$VASEY_BACKUP_REMOTE" ] && echo 'none by this script, explicitly allowed' || echo none; }); transport: $([ -n "$VASEY_BACKUP_REMOTE" ] && echo rclone || { [ -n "$VASEY_BACKUP_DEST" ] && echo rsync || echo none; }); retention: ${VASEY_BACKUP_RETENTION:-flat}; $(retry_copies_summary))"
    ;;
  *) die "usage: vasey-staging-backup nightly | predeploy | space-check [--preverify] | prune | restore-check DIR | ship DIR | ship-pending | ship-pending-start | pending | pending-clear SET | check-offhost" ;;
esac
