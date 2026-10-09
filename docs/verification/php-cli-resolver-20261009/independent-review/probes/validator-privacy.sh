#!/bin/bash
# Runs ops/staging/validate-runtime.php (dac1a79) with configured CLI binaries whose paths carry a unique marker, and
# checks the verdict line for runtime.php_cli_binary and that the marker never appears in stdout/stderr.
root=/home/user/rv-m16
dir=$(mktemp -d)
marker=ReviewPathMarker7f3a
mkdir -p "$dir/$marker"
ln -s /usr/bin/php8.4 "$dir/$marker/php-ok"
ln -s /usr/bin/php8.3 "$dir/$marker/php-83"
ln -s /usr/sbin/php-fpm8.4 "$dir/$marker/php-fpm"
for target in php-ok php-83 php-fpm missing; do
    cat > "$dir/candidate.env" <<EOF
APP_ENV=local
APP_DEBUG=false
APP_URL=https://staging.synthetic.invalid
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_MAINTENANCE_DRIVER=file
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1200
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=vasey_staging
DB_USERNAME=vasey_app
DB_PASSWORD=SyntheticPrivateRuntimeMarker
FILESYSTEM_DISK=local
MAIL_MAILER=log
STRIPE_MODE=test
VASEY_PHP_CLI_BINARY=$dir/$marker/$target
EOF
    out=$(cd "$root" && env -i PATH=/usr/bin:/bin LC_ALL=C /usr/bin/php8.4 ops/staging/validate-runtime.php "$dir/candidate.env" local staging.synthetic.invalid vasey_staging 2>&1)
    rc=$?
    echo "== configured=<dir>/$marker/$target rc=$rc"
    echo "$out" | grep -E 'php_cli_binary|FAIL' || echo "(no php_cli_binary line)"
    if echo "$out" | grep -q "$marker"; then echo "MARKER LEAKED"; else echo "marker absent from output"; fi
done
rm -rf "$dir"
