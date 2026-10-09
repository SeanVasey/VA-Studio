#!/usr/bin/env bash
# shellcheck disable=SC2015  # `test && test || die` is intended: die whenever any test fails.
# VASEY.AUDIO private test-mode staging: one-time host provisioning for what Laravel Forge does not do.
#
# Run as root on the Forge-provisioned server, from the Forge site checkout (the deploy mirror):
#   sudo bash /home/forge/<STAGING_HOST>/ops/staging/provision.sh --host <STAGING_HOST>
# Options:
#   --host NAME               staging hostname (required; never the apex or www)
#   --app-user NAME           the one OS user for PHP-FPM, workers and private storage (default: forge)
#   --root DIR                kit root (default: /srv/vasey-staging)
#   --mirror DIR              Forge site checkout that holds the Forge-managed .env (default: /home/<app-user>/<host>)
#   --db-name NAME            MySQL schema (default: vasey_staging)
#   --mysql-admin-defaults F  0600 [client] option file for a MySQL admin account (default: root via the local socket)
#   --app-env NAME            APP_ENV the deploy insists on (default: local; the one switch for a later `staging`)
#   --basic-auth-user NAME    staging basic-auth user; the password is read from the terminal (never an argument)
#   --skip-packages           do not apt-get anything (re-runs that only refresh config)
#
# Idempotent: re-running converges files and grants and never rotates an existing password or deletes data.
# It never prints a secret. Generated credentials go to root-only files named in the summary.
#
# What it does (Forge does the rest: PHP 8.4, nginx, MySQL 8.4, TLS, the site and its .env):
#   1. packages: PHP 8.4 extensions the app and vasey:doctor need, ffmpeg/ffprobe, ClamAV + freshclam,
#      qpdf, poppler-utils, util-linux (prlimit), supervisor, rsync, Node 24 for the build
#   2. ClamAV: freshclam enabled and current; the unused clamd stopped (clamscan is the configured engine)
#   3. tool checks: required ffmpeg encoders, prlimit limits, signature age
#   4. private directories with modes; nginx FastCGI temp path (private, 0700)
#   5. MySQL: schema, least-privilege app account, backup-only account, binlog trigger setting
#   6. PHP-FPM pools (default + paid-delivery), supervisor workers + scheduler, sudoers, backup cron, logrotate
#   7. basic-auth file, probe credentials, rendered nginx site for Forge
set -euo pipefail
umask 022
export LC_ALL=C DEBIAN_FRONTEND=noninteractive

die()  { echo "provision: ERROR: $*" >&2; exit 1; }
info() { echo "provision: $*"; }
warn() { echo "provision: WARNING: $*" >&2; }

[ "$(id -u)" -eq 0 ] || die "run as root (sudo bash $0 ...)"

HOST="" APP_USER=forge ROOT=/srv/vasey-staging MIRROR="" DB_NAME=vasey_staging MYSQL_ADMIN_DEFAULTS="" APP_ENV_EXPECTED=local
BASIC_USER="" SKIP_PACKAGES=0
while [ $# -gt 0 ]; do
  case "$1" in
    --host) HOST=${2:?}; shift 2 ;;
    --app-user) APP_USER=${2:?}; shift 2 ;;
    --root) ROOT=${2:?}; shift 2 ;;
    --mirror) MIRROR=${2:?}; shift 2 ;;
    --db-name) DB_NAME=${2:?}; shift 2 ;;
    --mysql-admin-defaults) MYSQL_ADMIN_DEFAULTS=${2:?}; shift 2 ;;
    --app-env) APP_ENV_EXPECTED=${2:?}; shift 2 ;;
    --basic-auth-user) BASIC_USER=${2:?}; shift 2 ;;
    --skip-packages) SKIP_PACKAGES=1; shift ;;
    *) die "unknown option $1" ;;
  esac
done

[[ "$HOST" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "--host must be a lowercase DNS name"
[[ "$APP_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && id "$APP_USER" >/dev/null 2>&1 || die "app user '$APP_USER' does not exist"
[[ "$ROOT" =~ ^/[A-Za-z0-9/_.-]+$ ]] && [[ "$ROOT" != *..* ]] || die "--root must be a plain absolute path"
[[ "$DB_NAME" =~ ^[a-z][a-z0-9_]{0,40}$ ]] || die "--db-name must match ^[a-z][a-z0-9_]{0,40}$"
[[ "$APP_ENV_EXPECTED" =~ ^(local|staging)$ ]] || die "--app-env must be local or staging"
APP_GROUP=$(id -gn "$APP_USER")
APP_HOME=$(getent passwd "$APP_USER" | cut -d: -f6)
MIRROR=${MIRROR:-$APP_HOME/$HOST}
KIT=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
for f in nginx/vasey-staging.conf php-fpm/vasey-staging.conf php-fpm/vasey-paid-delivery.conf \
         workers/vasey-staging-workers.conf bin/vasey-staging-ctl backup.sh normalize-mysql-dump.py seal-release.py validate-runtime.php; do
  [ -f "$KIT/$f" ] || die "kit file missing: ops/staging/$f"
done

PHPV=8.4
PHP_BIN=/usr/bin/php$PHPV
FPM_BIN=/usr/sbin/php-fpm$PHPV
FPM_SERVICE=php$PHPV-fpm
NGINX_USER=$(awk '$1=="user"{gsub(";","",$2); print $2; exit}' /etc/nginx/nginx.conf 2>/dev/null || true)
NGINX_USER=${NGINX_USER:-www-data}
id "$NGINX_USER" >/dev/null 2>&1 || die "nginx user '$NGINX_USER' (from /etc/nginx/nginx.conf) does not exist"
NGINX_GROUP=$(id -gn "$NGINX_USER")

# ---------------------------------------------------------------- 0. host checks
. /etc/os-release
[ "${ID:-}" = ubuntu ] || warn "not Ubuntu (${ID:-unknown}); package names below assume Ubuntu 24.04 LTS"
[ "${VERSION_ID:-}" = 24.04 ] || warn "Ubuntu ${VERSION_ID:-?}; this kit was written for 24.04 LTS"
mem_kib=$(awk '/^MemTotal:/{print $2}' /proc/meminfo)
[ "$mem_kib" -ge 3700000 ] || die "RAM is $((mem_kib / 1024)) MiB; clamscan alone may take 3 GiB: use at least 4 GB (8 GB recommended)"
[ "$mem_kib" -ge 7500000 ] || warn "RAM is $((mem_kib / 1024)) MiB; 8 GB is recommended (MySQL + PHP-FPM + a 3 GiB clamscan + ffmpeg)"
swap_kib=$(awk '/^SwapTotal:/{print $2}' /proc/meminfo)
[ "$swap_kib" -gt 0 ] || warn "no swap configured; a clamscan peak can OOM-kill MySQL on a 4 GB host"

# ---------------------------------------------------------------- 1. packages
if [ "$SKIP_PACKAGES" = 0 ]; then
  apt-cache policy "php$PHPV-fpm" | grep -q 'Candidate: [0-9]' \
    || die "php$PHPV packages unavailable: create the Forge server with PHP 8.4 (it adds the ondrej/php repository)"
  apt-get update -q
  apt-get install -y -q --no-install-recommends \
    "php$PHPV-cli" "php$PHPV-fpm" "php$PHPV-mysql" "php$PHPV-sqlite3" "php$PHPV-mbstring" "php$PHPV-intl" \
    "php$PHPV-bcmath" "php$PHPV-gd" "php$PHPV-zip" "php$PHPV-curl" "php$PHPV-xml" python3 \
    ffmpeg clamav clamav-freshclam qpdf poppler-utils util-linux supervisor rsync curl ca-certificates gnupg openssl

  node_ok=0
  if command -v node >/dev/null 2>&1; then
    nv=$(node -v | sed 's/^v//')
    nmaj=${nv%%.*}; nrest=${nv#*.}; nmin=${nrest%%.*}
    { [ "$nmaj" = 24 ] && [ "$nmin" -ge 15 ]; } && node_ok=1
  fi
  if [ "$node_ok" = 0 ]; then
    info "installing Node.js 24 (package.json engines: >=24.15.0 <25) from the NodeSource apt repository"
    install -d -m 0755 /usr/share/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /usr/share/keyrings/nodesource.gpg
    echo "deb [signed-by=/usr/share/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" \
      > /etc/apt/sources.list.d/nodesource.list
    printf 'Package: nodejs\nPin: origin deb.nodesource.com\nPin-Priority: 1001\n' > /etc/apt/preferences.d/nodesource
    apt-get update -q
    apt-get install -y -q nodejs
  fi
fi

nv=$(node -v 2>/dev/null | sed 's/^v//') || die "node is not installed"
[[ "$nv" =~ ^24\.([0-9]+)\. ]] && [ "${BASH_REMATCH[1]}" -ge 15 ] || die "node $nv does not satisfy >=24.15.0 <25"
command -v npm >/dev/null || die "npm missing"
command -v composer >/dev/null || die "composer missing (Forge installs Composer 2)"
command -v python3 >/dev/null || die "python3 missing (restore schema comparison requires it)"
[ -x "$PHP_BIN" ] && [ -x "$FPM_BIN" ] || die "PHP $PHPV CLI or FPM missing"

# Extensions vasey:doctor requires (php_runtime) plus what CI installs (final-verification.yml) and the workers need.
for ext in pdo pdo_mysql pdo_sqlite mbstring intl bcmath gd fileinfo zip curl dom xml xmlwriter posix pcntl ctype iconv tokenizer openssl; do
  "$PHP_BIN" -m | grep -qix "$ext" || die "PHP CLI extension missing: $ext"
done
for ext in pdo_mysql pdo_sqlite mbstring intl bcmath gd fileinfo zip curl dom xml posix ctype iconv; do
  "$FPM_BIN" -m 2>/dev/null | grep -qix "$ext" || die "PHP-FPM extension missing: $ext"
done
info "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') with required extensions; node $nv"

# ---------------------------------------------------------------- 2-3. media tools and ClamAV
for t in /usr/bin/ffmpeg /usr/bin/ffprobe /usr/bin/prlimit /usr/bin/clamscan /usr/bin/freshclam /usr/bin/qpdf /usr/bin/pdftotext; do
  [ -x "$t" ] || die "missing executable $t"
done
encoders=$(/usr/bin/ffmpeg -hide_banner -encoders 2>/dev/null)
for e in libmp3lame pcm_s16le png mjpeg libwebp; do
  grep -Eq "^ [AVS][F.][S.][X.][B.][D.] +$e( |$)" <<<"$encoders" || die "ffmpeg lacks the $e encoder (vasey:doctor media_encoders)"
done
# The app runs clamscan under a 3 GiB address-space cap and media tools under 2 GiB (config/media.php).
/usr/bin/prlimit --as=3221225472 --cpu=180 --fsize=1342177280 -- /bin/true || die "prlimit cannot apply the scanner limits"
/usr/bin/prlimit --as=2147483648 --cpu=90 -- /usr/bin/ffprobe -version >/dev/null || die "ffprobe does not run under the media limits"
info "ffmpeg/ffprobe encoders, prlimit limits, qpdf and poppler present"

# freshclam: hourly checks (the app refuses signatures older than 48 h, config/media.php max_signature_age_seconds).
fc=/etc/clamav/freshclam.conf
[ -f "$fc" ] || die "missing $fc"
if grep -q '^Checks ' "$fc"; then sed -i 's/^Checks .*/Checks 24/' "$fc"; else echo 'Checks 24' >> "$fc"; fi
if [ ! -s /var/lib/clamav/daily.cvd ] && [ ! -s /var/lib/clamav/daily.cld ]; then
  info "downloading ClamAV signatures for the first time"
  systemctl stop clamav-freshclam 2>/dev/null || true
  freshclam --quiet || die "initial freshclam failed (outbound HTTPS to database.clamav.net needed)"
fi
systemctl enable --now clamav-freshclam >/dev/null
# clamd is not used (the configured engine is clamscan) and would hold ~1 GiB resident.
if systemctl list-unit-files clamav-daemon.service >/dev/null 2>&1; then systemctl disable --now clamav-daemon >/dev/null 2>&1 || true; fi
newest=$(find /var/lib/clamav -maxdepth 1 \( -name 'daily.c[lv]d' -o -name 'main.c[lv]d' \) -printf '%T@\n' | sort -rn | head -1)
age=$(( $(date +%s) - ${newest%.*} ))
[ "$age" -lt 172800 ] || warn "newest ClamAV daily signature file is $((age / 3600)) h old; the app refuses > 48 h"
runuser -u "$APP_USER" -- test -r /var/lib/clamav/daily.cvd -o -r /var/lib/clamav/daily.cld \
  || die "$APP_USER cannot read the ClamAV signature database"

# ---------------------------------------------------------------- 4. directories and modes
install -d -m 0755 -o root -g root "$ROOT"
install -d -m 0755 -o root -g root "$ROOT/releases"
install -d -m 0750 -o "$APP_USER" -g "$APP_GROUP" "$ROOT/evidence"
install -d -m 0700 -o root -g root "$ROOT/backups"
install -d -m 0700 -o "$APP_USER" -g "$APP_GROUP" "$ROOT/tmp" "$ROOT/tmp/php-upload" "$ROOT/tmp/php-sys"
# Persistent private storage: masters, stems, contracts, tag, site images, uploads. vasey:doctor and every
# private-file adapter require 0700, owned by the worker user, with no symlink anywhere in its path.
if [ ! -d "$ROOT/private" ]; then install -d -m 0700 -o "$APP_USER" -g "$APP_GROUP" "$ROOT/private"; fi
chown "$APP_USER:$APP_GROUP" "$ROOT/private"; chmod 0700 "$ROOT/private"
install -d -m 0700 -o "$APP_USER" -g "$APP_GROUP" "$ROOT/private/branding"   # seller tag (runbook section 3)
if [ ! -e "$ROOT/private/.gitignore" ]; then
  printf '*\n!.gitignore\n' > "$ROOT/private/.gitignore"   # same bytes as the tracked storage/app/private/.gitignore
  chown "$APP_USER:$APP_GROUP" "$ROOT/private/.gitignore"; chmod 0644 "$ROOT/private/.gitignore"
fi
[ "$(readlink -f "$ROOT/private")" = "$ROOT/private" ] || die "$ROOT/private resolves through a symlink; the app refuses that"
install -m 0600 -o "$APP_USER" -g "$APP_GROUP" /dev/null "$ROOT/.deploy.lock.new"
[ -e "$ROOT/.deploy.lock" ] || mv "$ROOT/.deploy.lock.new" "$ROOT/.deploy.lock"; rm -f "$ROOT/.deploy.lock.new"
install -d -m 0700 -o "$NGINX_USER" -g "$NGINX_GROUP" /var/lib/nginx/vasey-staging-fastcgi
install -d -m 0750 -o root -g "$APP_GROUP" /var/log/vasey-staging
install -d -m 0755 -o root -g root /etc/vasey-staging
# Preserve the lock inode on re-provision: replacing it would split concurrent helpers' authority.
if [ ! -e /etc/vasey-staging/ctl.lock ] && [ ! -L /etc/vasey-staging/ctl.lock ]; then
  (umask 077; set -C; : > /etc/vasey-staging/ctl.lock)
fi
[ -f /etc/vasey-staging/ctl.lock ] && [ ! -L /etc/vasey-staging/ctl.lock ] \
  && [ "$(stat -c '%u %a' /etc/vasey-staging/ctl.lock)" = '0 600' ] || die "untrusted staging control lock"
# A stable shared writer lock and a durable root-owned gate cover independent systemd/cron/daemon
# sweeps between quiesce and resume. Never replace the lock inode or reopen an existing closed gate.
if [ ! -e /etc/vasey-staging/writer.lock ] && [ ! -L /etc/vasey-staging/writer.lock ]; then
  (umask 022; set -C; : > /etc/vasey-staging/writer.lock)
fi
if [ ! -e /etc/vasey-staging/writer-admission ] && [ ! -L /etc/vasey-staging/writer-admission ]; then
  (umask 022; set -C; printf 'closed\n' > /etc/vasey-staging/writer-admission)
fi
for file in /etc/vasey-staging/writer.lock /etc/vasey-staging/writer-admission; do
  [ -f "$file" ] && [ ! -L "$file" ] && [ "$(stat -c '%u %a' "$file")" = '0 644' ] \
    || die "untrusted staging writer admission"
done
info "directories under $ROOT ready (private 0700 $APP_USER)"

# ---------------------------------------------------------------- 5. MySQL
MYSQL=(mysql --protocol=socket -uroot)
[ -n "$MYSQL_ADMIN_DEFAULTS" ] && MYSQL=(mysql --defaults-extra-file="$MYSQL_ADMIN_DEFAULTS")
"${MYSQL[@]}" -N -e 'SELECT 1' >/dev/null 2>&1 \
  || die "cannot reach MySQL as an admin; pass --mysql-admin-defaults with a 0600 [client] file (Forge shows the root password once)"
myver=$("${MYSQL[@]}" -N -e 'SELECT VERSION()')
[[ "$myver" == 8.4.* ]] || die "MySQL is $myver; create the Forge server with MySQL 8.4"
mysql_q() { "${MYSQL[@]}" -N -e "$1"; }
user_exists() { [ "$(mysql_q "SELECT COUNT(*) FROM mysql.user WHERE user='$1' AND host='127.0.0.1'")" = 1 ]; }
new_secret() { openssl rand -base64 33 | tr -d '\n/+=' | cut -c1-40; }
SECRETS=/root/vasey-staging-secrets
install -d -m 0700 -o root -g root "$SECRETS"

mysql_q "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Runtime and migration account. One account on purpose: triggers run as their DEFINER (the account that
# created them during migrate), and the application reads information_schema.TRIGGERS at runtime to verify
# its own schema, which only shows triggers on tables the reader holds TRIGGER on. Grants are schema-scoped;
# no global privilege, no SUPER, no CREATE ROUTINE, no FILE. Host is 127.0.0.1 only: set DB_HOST=127.0.0.1
# (not localhost) so every connection and every trigger DEFINER is this exact account.
if ! user_exists vasey_app; then
  pw=$(new_secret)
  mysql_q "CREATE USER 'vasey_app'@'127.0.0.1' IDENTIFIED BY '$pw'"
  umask 077; printf 'DB_USERNAME=vasey_app\nDB_PASSWORD=%s\n' "$pw" > "$SECRETS/db-app.env"; umask 022
  unset pw
  info "created MySQL account vasey_app@127.0.0.1; its password is in $SECRETS/db-app.env (root only)"
fi
mysql_q "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, CREATE TEMPORARY TABLES, LOCK TABLES ON \`$DB_NAME\`.* TO 'vasey_app'@'127.0.0.1'"

# Backup-only account (docs/ops/backup-restore-proof.md): read, triggers, events, routines; no writes.
BACKUP_CNF=/etc/vasey-staging/backup.my.cnf
if ! user_exists vasey_backup; then
  pw=$(new_secret)
  mysql_q "CREATE USER 'vasey_backup'@'127.0.0.1' IDENTIFIED BY '$pw'"
  umask 077; printf '[client]\nhost=127.0.0.1\nport=3306\nuser=vasey_backup\npassword=%s\n' "$pw" > "$BACKUP_CNF"; umask 022
  unset pw
fi
[ -f "$BACKUP_CNF" ] || die "vasey_backup exists but $BACKUP_CNF is missing; recreate the account or restore the file"
chown root:root "$BACKUP_CNF"; chmod 0600 "$BACKUP_CNF"
mysql_q "GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON \`$DB_NAME\`.* TO 'vasey_backup'@'127.0.0.1'"
mysql_q "GRANT SHOW_ROUTINE ON *.* TO 'vasey_backup'@'127.0.0.1'"

# Binary logging stays on (point-in-time recovery). With it on, MySQL refuses CREATE TRIGGER from an account
# without SUPER (error 1419). The migrations create ~567 triggers, so allow trusted trigger creators instead
# of granting SUPER: binlog_format is ROW, so the statement-replay risk this guard exists for does not apply,
# and only vasey_app holds TRIGGER. Deprecated in 8.4 (warning 1287) but still effective; persisted.
if [ "$(mysql_q 'SELECT @@log_bin')" = 1 ]; then
  [ "$(mysql_q 'SELECT @@binlog_format')" = ROW ] || warn "binlog_format is not ROW"
  mysql_q "SET PERSIST log_bin_trust_function_creators = ON" 2>/dev/null
  [ "$(mysql_q 'SELECT @@global.log_bin_trust_function_creators')" = 1 ] || die "could not set log_bin_trust_function_creators"
fi
mysql_q "SELECT @@bind_address" | grep -Eq '^(127\.0\.0\.1|localhost|::1)$' \
  || warn "MySQL bind_address is $(mysql_q 'SELECT @@bind_address'); keep port 3306 closed in the Forge firewall"
info "MySQL $myver: schema $DB_NAME, accounts vasey_app and vasey_backup (127.0.0.1 only)"

# Restore-proof scratch for the disposable mysqld (backup.sh restore-check). AppArmor, when it confines mysqld,
# must allow this directory.
install -d -m 0711 -o root -g root /var/lib/mysql-restore-check
if [ -f /etc/apparmor.d/usr.sbin.mysqld ]; then
  install -d -m 0755 /etc/apparmor.d/local
  local_rules=/etc/apparmor.d/local/usr.sbin.mysqld
  touch "$local_rules"
  grep -q 'mysql-restore-check' "$local_rules" || printf '%s\n' '/var/lib/mysql-restore-check/ r,' '/var/lib/mysql-restore-check/** rwk,' >> "$local_rules"
  apparmor_parser -r /etc/apparmor.d/usr.sbin.mysqld 2>/dev/null || warn "could not reload the mysqld AppArmor profile"
fi

# ---------------------------------------------------------------- 6. host configuration files
render() {  # render TEMPLATE DEST MODE
  sed -e "s#__APP_USER__#$APP_USER#g" -e "s#__APP_GROUP__#$APP_GROUP#g" \
      -e "s#__NGINX_USER__#$NGINX_USER#g" -e "s#__NGINX_GROUP__#$NGINX_GROUP#g" \
      -e "s#__VASEY_ROOT__#$ROOT#g" -e "s#__PHP_BIN__#$PHP_BIN#g" -e "s#__STAGING_HOST__#$HOST#g" "$1" > "$2.tmp"
  chmod "$3" "$2.tmp"; chown root:root "$2.tmp"; mv -f "$2.tmp" "$2"
}

cat > /etc/vasey-staging/staging.conf.tmp <<EOF
# Written by ops/staging/provision.sh. No secrets. Read by vasey-staging-ctl, vasey-staging-backup and forge-deploy.sh.
VASEY_STAGING_HOST=$HOST
VASEY_APP_USER=$APP_USER
VASEY_APP_GROUP=$APP_GROUP
VASEY_ROOT=$ROOT
VASEY_MIRROR=$MIRROR
VASEY_PHP=$PHP_BIN
VASEY_PHP_FPM_SERVICE=$FPM_SERVICE
VASEY_EXPECTED_APP_ENV=$APP_ENV_EXPECTED
VASEY_DB_NAME=$DB_NAME
VASEY_PROBE_NETRC=/etc/vasey-staging/probe.netrc
VASEY_KEEP_RELEASES=3
VASEY_BACKUP_KEEP=7
# Off-host backup target for rsync over SSH (user@host:/path), with the key below. Empty = local copies only.
VASEY_BACKUP_DEST=$(grep -s '^VASEY_BACKUP_DEST=' /etc/vasey-staging/staging.conf | cut -d= -f2- || true)
VASEY_BACKUP_SSH_KEY=/root/.ssh/vasey_staging_backup
VASEY_RESTORE_STRICT_MODES=1
EOF
chmod 0644 /etc/vasey-staging/staging.conf.tmp; mv -f /etc/vasey-staging/staging.conf.tmp /etc/vasey-staging/staging.conf

install -m 0755 -o root -g root "$KIT/bin/vasey-staging-ctl" /usr/local/sbin/vasey-staging-ctl
install -m 0755 -o root -g root "$KIT/backup.sh" /usr/local/sbin/vasey-staging-backup
install -d -m 0755 -o root -g root /usr/local/libexec/vasey-staging
install -m 0644 -o root -g root "$KIT/normalize-mysql-dump.py" /usr/local/libexec/vasey-staging/normalize-mysql-dump.py
install -m 0644 -o root -g root "$KIT/seal-release.py" /usr/local/libexec/vasey-staging/seal-release.py

render "$KIT/php-fpm/vasey-staging.conf" "/etc/php/$PHPV/fpm/pool.d/vasey-staging.conf" 0644
render "$KIT/php-fpm/vasey-paid-delivery.conf" "/etc/php/$PHPV/fpm/pool.d/vasey-paid-delivery.conf" 0644
"$FPM_BIN" -t >/dev/null 2>&1 || { "$FPM_BIN" -t; die "PHP-FPM configuration test failed"; }
systemctl reload "$FPM_SERVICE" || systemctl restart "$FPM_SERVICE"
[ -S /run/php/vasey-staging.sock ] && [ -S /run/php/vasey-paid-delivery.sock ] || die "FPM pool sockets missing after reload"

render "$KIT/workers/vasey-staging-workers.conf" /etc/supervisor/conf.d/vasey-staging.conf 0644
systemctl enable --now supervisor >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
[ -L "$ROOT/current" ] || supervisorctl stop 'vasey-staging:*' >/dev/null 2>&1 || true

cat > /etc/sudoers.d/vasey-staging.tmp <<EOF
# The deploy user may run exactly the staging control helper as root; it validates every argument.
$APP_USER ALL=(root) NOPASSWD: /usr/local/sbin/vasey-staging-ctl
EOF
chmod 0440 /etc/sudoers.d/vasey-staging.tmp
visudo -cf /etc/sudoers.d/vasey-staging.tmp >/dev/null || die "sudoers syntax check failed"
mv -f /etc/sudoers.d/vasey-staging.tmp /etc/sudoers.d/vasey-staging

cat > /etc/cron.d/vasey-staging-backup <<'EOF'
# Nightly staging backup and restore proof (ops/staging/backup.sh). Brief maintenance window.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 3 * * * root /usr/local/sbin/vasey-staging-backup nightly >> /var/log/vasey-staging/backup.log 2>&1
EOF
chmod 0644 /etc/cron.d/vasey-staging-backup

cat > /etc/logrotate.d/vasey-staging <<'EOF'
/var/log/php8.4-fpm-vasey-*.log /var/log/vasey-staging/backup.log /var/log/nginx/vasey-staging-*.log {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
}
EOF

# ---------------------------------------------------------------- 7. access credentials and nginx template
HTPASSWD=/etc/nginx/vasey-staging.htpasswd
if [ ! -s "$HTPASSWD" ]; then
  [ -t 0 ] || die "basic-auth file missing and no terminal to read a password; re-run interactively"
  [ -n "$BASIC_USER" ] || read -r -p "Staging basic-auth user name: " BASIC_USER
  [[ "$BASIC_USER" =~ ^[A-Za-z0-9._-]{3,32}$ ]] || die "basic-auth user must be 3-32 of [A-Za-z0-9._-]"
  read -r -s -p "Staging basic-auth password (16+ characters): " bpw; echo
  read -r -s -p "Repeat: " bpw2; echo
  [ "$bpw" = "$bpw2" ] && [ "${#bpw}" -ge 16 ] || die "passwords differ or are shorter than 16 characters"
  [[ "$bpw" != *[[:space:]]* ]] || die "the password must not contain whitespace (it is also stored in a netrc file)"
  umask 077
  printf '%s:%s\n' "$BASIC_USER" "$(printf '%s' "$bpw" | openssl passwd -6 -stdin)" > "$HTPASSWD.tmp"
  printf 'machine %s\nlogin %s\npassword %s\n' "$HOST" "$BASIC_USER" "$bpw" > /etc/vasey-staging/probe.netrc
  umask 022
  unset bpw bpw2
  chown root:"$NGINX_GROUP" "$HTPASSWD.tmp"; chmod 0640 "$HTPASSWD.tmp"; mv -f "$HTPASSWD.tmp" "$HTPASSWD"
fi
[ -s /etc/vasey-staging/probe.netrc ] || die "/etc/vasey-staging/probe.netrc missing: delete $HTPASSWD and re-run to set both"
chown root:root /etc/vasey-staging/probe.netrc; chmod 0600 /etc/vasey-staging/probe.netrc

render "$KIT/nginx/vasey-staging.conf" /etc/vasey-staging/nginx-site.conf 0644

# ---------------------------------------------------------------- summary
cat <<EOF

provision: done for $HOST
  kit root            $ROOT (releases/, private/ 0700 $APP_USER, backups/ root 0700)
  app/worker user     $APP_USER:$APP_GROUP   nginx user $NGINX_USER
  PHP-FPM pools       vasey-staging (/run/php/vasey-staging.sock), vasey-paid-delivery (/run/php/vasey-paid-delivery.sock)
  supervisor          vasey-staging:{media,payments,contracts,default,scheduler} (start on first deploy)
  helpers             /usr/local/sbin/vasey-staging-ctl (sudo for $APP_USER), /usr/local/sbin/vasey-staging-backup (cron 03:17 UTC)
  MySQL               schema $DB_NAME; DB_HOST=127.0.0.1 DB_USERNAME=vasey_app
  secrets (root only) $SECRETS/db-app.env (paste DB_PASSWORD into Forge > Environment), $BACKUP_CNF, $HTPASSWD, /etc/vasey-staging/probe.netrc
  nginx site          /etc/vasey-staging/nginx-site.conf: fill __SSL_CERT__, __SSL_KEY__, __FORGE_CONF__ from Forge's
                      generated config, then paste it into Forge > Site > Edit Nginx Configuration
Next: ops/staging/README.md step 8 onwards.
EOF
