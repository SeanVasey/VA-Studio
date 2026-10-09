#!/usr/bin/env bash
# Usage: run-fpm-smoke.sh <name> <app-root> <port> <cli-binary-or-empty> <family>...
# Starts a private php-fpm8.4 pool (system php.ini + conf.d, FastCGI on a private unix socket), requests the smoke script once
# per family through cgi-fcgi, prints each JSON reply, then stops the pool.
set -euo pipefail
dir=$(cd "$(dirname "$0")" && pwd); name=$1; root=$2; port=$3; cli=$4; shift 4
extra=""; [ -n "$cli" ] && extra="env[VASEY_PHP_CLI_BINARY] = $cli"
sed -e "s#__DIR__#$dir#g" -e "s#__NAME__#$name#g" -e "s#__PORT__#$port#g" -e "s#__ROOT__#$root#g" -e "s#__EXTRA__#$extra#" \
  "$dir/fpm.conf.tpl" > "$dir/fpm-$name.conf"
: > "$dir/fpm-$name-error.log"
/usr/sbin/php-fpm8.4 -y "$dir/fpm-$name.conf" -R -F &
fpm=$!
trap 'kill $fpm 2>/dev/null; wait $fpm 2>/dev/null || true' EXIT
for _ in $(seq 50); do [ -S "$dir/fpm-$name.sock" ] && break; sleep 0.1; done
echo "# pool $name: php-fpm8.4 $(/usr/sbin/php-fpm8.4 -v | head -1 | cut -d' ' -f2), root=$root, VASEY_PHP_CLI_BINARY=${cli:-<unset>}"
for family in "$@"; do
  echo "## family=$family"
  env -i SCRIPT_FILENAME="$dir/m16-fpm-smoke.php" REQUEST_METHOD=GET QUERY_STRING="family=$family" \
    cgi-fcgi -bind -connect "$dir/fpm-$name.sock" | tr -d '\r' | sed -n '/^{/p'
done
