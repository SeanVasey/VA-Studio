#!/usr/bin/env bash
# Two complete application schemas on one private mysqld (:3410), migrated one after the other.
# Usage: [DBS="db1 db2 db1"] two-db-repro.sh <checkout dir>
# Databases must already exist on the private daemon (see mysqld-up.sh).
#
# Exit status: 0 only when every migrate:fresh and the final catalog dump succeeded; 1 when any of them failed;
# 2 when the invocation itself is refused (no checkout, no clean commit to bind the evidence to, a runtime version that
# cannot be read, a database name outside identifier characters, cached configuration, or a Laravel connection that is
# not the disposable database on 127.0.0.1:3410). Nothing is migrated after a refusal.
#
# Scope: this is a reproduction aid for the evidence in this directory, not an acceptance gate. It binds the evidence to the
# committed application source (tracked, untracked and ignored files under app, bootstrap, config, database, routes,
# resources and lang). Third-party code under vendor/ is bound by the committed composer.lock and a frozen install
# (`composer install`), which this script does not re-verify.
set -uo pipefail
refuse() { echo "refused: $*" >&2; exit 2; }

# A missing or unreadable checkout stops here, so the evidence is never bound to whatever directory the caller is in.
cd "${1:?usage: two-db-repro.sh <checkout dir>}" 2>/dev/null || refuse "cannot enter checkout: $1"

# The evidence names exactly one commit: a full 40-hex SHA from a git checkout with no tracked or untracked changes.
source_sha=$(git rev-parse --verify --quiet 'HEAD^{commit}' 2>/dev/null) || refuse "no git commit in $PWD"
[[ "$source_sha" =~ ^[0-9a-f]{40}$ ]] || refuse "unexpected commit id: $source_sha"
# Untracked files and submodules are requested explicitly, so status.showUntrackedFiles or submodule settings in the
# caller's Git configuration cannot hide source that is absent from the commit.
dirty=$(git status --porcelain --untracked-files=all --ignore-submodules=none 2>/dev/null) || refuse "cannot read working tree status in $PWD"
[ -z "$dirty" ] || refuse "working tree has changes; commit or stash them so the evidence matches $source_sha"
# Ignore rules (.gitignore, .git/info/exclude, core.excludesFile) can also hide files from that check, so any ignored file
# under a directory Laravel loads application code or configuration from is refused too. bootstrap/cache holds generated
# caches only (cached configuration itself is refused below).
ignored=$(git ls-files --others --ignored --exclude-standard -- app bootstrap config database routes resources lang 2>/dev/null) \
  || refuse "cannot list ignored files in $PWD"
ignored=$(printf '%s\n' "$ignored" | grep -v '^bootstrap/cache/' | grep -v '^$' || true)
[ -z "$ignored" ] || refuse "ignored files under application source paths would run outside $source_sha: $(printf '%s' "$ignored" | head -3 | tr '\n' ' ')"

MYSQL=/opt/mysql84/mysql-8.4.11-linux-glibc2.28-x86_64/bin/mysql
php_version=$(php -r 'echo PHP_VERSION;' 2>/dev/null) && [ -n "$php_version" ] || refuse "cannot read the PHP version"
mysqld_version=$("$MYSQL" -N -uroot -h127.0.0.1 -P3410 -e 'SELECT VERSION()' 2>/dev/null) && [ -n "$mysqld_version" ] \
  || refuse "cannot reach the private mysqld on 127.0.0.1:3410"

# The selected database names drive both catalog predicates. Names are restricted to the identifier characters the
# predicates quote, so an override cannot widen the query or dump an unrelated schema.
DBS=${DBS:-rv256_1 rv256_2 rv256_1}
IN=""
for db in $DBS; do
  case "$db" in *[!A-Za-z0-9_]*|"") refuse "database name: $db";; esac
  case ",$IN," in *",'$db',"*) ;; *) IN="${IN:+$IN,}'$db'";; esac
done
[ -n "$IN" ] || refuse "no database selected"

# Per-run logs live in a private directory, never at a predictable shared path.
logs=$(mktemp -d "${TMPDIR:-/tmp}/two-db-repro.XXXXXX") || refuse "cannot create a private log directory"
echo "logs: $logs"

# migrate:fresh drops every table in its target, so Laravel must be pinned to the disposable daemon. A cached
# configuration ignores the exported values, and DB_URL or DB_SOCKET (for example from an ignored .env.testing) would
# take precedence over host and port, so cached configuration is refused and both are cleared (an empty DB_URL is
# ignored by Laravel's URL parser). The exported values win over .env files, which never overwrite set variables.
[ ! -e bootstrap/cache/config.php ] || refuse "cached configuration (bootstrap/cache/config.php) would bypass the pinned connection"
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3410 DB_USERNAME=root DB_PASSWORD= DB_URL= DB_SOCKET=
echo "source: $source_sha  php: $php_version  mysqld: $mysqld_version"

# Before the first destructive run, boot the application once per selected database and prove its effective default
# connection is mysql on 127.0.0.1:3410 with that database, with no URL or socket, and that the live server agrees.
for db in $(printf '%s\n' $DBS | sort -u); do
  DB_DATABASE=$db php -d display_errors=stderr <<'PHP' || refuse "Laravel's effective connection is not the disposable database $db on 127.0.0.1:3410"
<?php
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $want = getenv('DB_DATABASE');
    $name = config('database.default');
    $c = (array) config('database.connections.'.$name);
    $pinned = ! $app->configurationIsCached() && $name === 'mysql' && ($c['driver'] ?? null) === 'mysql'
        && empty($c['url']) && empty($c['unix_socket']) && ($c['host'] ?? null) === '127.0.0.1'
        && (string) ($c['port'] ?? '') === '3410' && ($c['database'] ?? null) === $want;
    $live = $pinned ? Illuminate\Support\Facades\DB::connection($name)->selectOne('SELECT @@port AS port, DATABASE() AS db') : null;
    if (! $pinned || (int) $live->port !== 3410 || $live->db !== $want) {
        fwrite(STDERR, 'effective connection: '.json_encode(['default' => $name, 'url' => $c['url'] ?? null, 'socket' => $c['unix_socket'] ?? null,
            'host' => $c['host'] ?? null, 'port' => $c['port'] ?? null, 'database' => $c['database'] ?? null, 'live' => $live])."\n");
        exit(3);
    }
    echo "connection pinned: 127.0.0.1:3410/{$want}\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(3);
}
PHP
done

# Every migrate:fresh status and the final catalog dump are accumulated; the script exits 1 when any one failed.
status=0
run=0
for db in $DBS; do
  run=$((run + 1))
  log="$logs/$run-$db.log"
  echo "== migrate:fresh --force on $db"
  DB_DATABASE=$db php artisan migrate:fresh --force > "$log" 2>&1; rc=$?; [ "$rc" -eq 0 ] || status=1
  grep -E "238000|FAIL|Unexpected" "$log" | head -5
  echo "exit=$rc migrations_done=$(grep -cE "^  [0-9]{4}_[0-9_]+_.* DONE" "$log")"
done
"$MYSQL" -uroot -h127.0.0.1 -P3410 -e "SELECT TRIGGER_SCHEMA, COUNT(*) triggers FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ($IN) GROUP BY TRIGGER_SCHEMA; SELECT TRIGGER_SCHEMA, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ($IN) AND TRIGGER_NAME='ptp_packet_insert';" || status=1
exit $status
