#!/bin/bash
# Serves review-fpm-smoke.php with PHP's built-in server (SAPI cli-server, as `artisan serve` and the Playwright
# webServer do) against an app root, optionally with VASEY_PHP_CLI_BINARY, and requests the given families.
# Usage: cli-server.sh <app-root> <port> <cli-binary-or-empty> <family>...
probes=$(cd "$(dirname "$0")" && pwd)
root=$1; port=$2; cli=$3; shift 3
extra=()
[ -n "$cli" ] && extra=(VASEY_PHP_CLI_BINARY="$cli")
env -i PATH=/usr/bin:/bin REVIEW_ROOT="$root" REVIEW_CAPTURE_DIR="$probes/../capture" "${extra[@]}" \
    /usr/bin/php8.4 -S 127.0.0.1:"$port" -t "$probes" > /dev/null 2>&1 &
server=$!
trap 'kill $server 2>/dev/null; wait $server 2>/dev/null' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.1; done
echo "# cli-server root=$root ($(git -C "$root" rev-parse --short=12 HEAD)) VASEY_PHP_CLI_BINARY=${cli:-<unset>}"
for family in "$@"; do
    curl -s "http://127.0.0.1:$port/review-fpm-smoke.php?family=$family" | sed -E 's/"root":"[^"]*",//'
done
