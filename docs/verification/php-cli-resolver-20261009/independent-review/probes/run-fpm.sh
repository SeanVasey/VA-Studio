#!/usr/bin/env bash
# Usage: run-fpm.sh <name> <app-root> <cli-binary-or-empty> <query>...
# Starts a private php-fpm8.4 pool (system php.ini + conf.d, clear_env, hostile env[] values, private unix socket),
# sends one FastCGI request per query string through cgi-fcgi (FastCGI params passed with `env -i` so the request
# stays small, plus one hostile HTTP_ header), prints each JSON reply, then stops the pool.
set -euo pipefail
probes=$(cd "$(dirname "$0")" && pwd)
name=$1; root=$2; cli=$3; shift 3
run="${REVIEW_RUN_BASE:?}/$name"
mkdir -p "$run"
capture="$probes/../capture"
extra=""
[ -n "$cli" ] && extra="env[VASEY_PHP_CLI_BINARY] = $cli"
sed -e "s#__RUN__#$run#g" -e "s#__ROOT__#$root#g" -e "s#__CAPTURE__#$capture#g" -e "s#__EXTRA__#$extra#" -e "s#__MAXC__#${REVIEW_MAX_CHILDREN:-2}#" \
    "$probes/fpm.conf.tpl" > "$run/fpm.conf"
: > "$run/fpm-error.log"
/usr/sbin/php-fpm8.4 -y "$run/fpm.conf" -R -F &
fpm=$!
trap 'kill $fpm 2>/dev/null; wait $fpm 2>/dev/null || true' EXIT
for _ in $(seq 100); do [ -S "$run/fpm.sock" ] && break; sleep 0.1; done
echo "# pool $name: $(/usr/sbin/php-fpm8.4 -v | head -1 | cut -d' ' -f1-3), root=$root ($(git -C "$root" rev-parse --short=12 HEAD)), VASEY_PHP_CLI_BINARY=${cli:-<unset>}"
for query in "$@"; do
    echo "## $query"
    env -i SCRIPT_FILENAME="$probes/review-fpm-smoke.php" REQUEST_METHOD=GET QUERY_STRING="$query" \
        HTTP_X_REVIEW_HEADER=hostile-header-value \
        cgi-fcgi -bind -connect "$run/fpm.sock" | tr -d '\r' | sed -n '/^{/p'
done
