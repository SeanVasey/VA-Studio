#!/usr/bin/env bash
set -euo pipefail

prepare_gitlab_private_storage() {
    if [[ $# != 0 || ${GITLAB_CI:-} != true || ${CI_DISPOSABLE_ENVIRONMENT:-} != true || $EUID != 0 ]]; then
        echo 'Private storage setup requires a disposable GitLab container running as root.' >&2
        return 1
    fi
    python3 - <<'PY_STORAGE'
import os
from pathlib import Path
import stat
import sys
try:
    configured = os.environ.get('CI_PROJECT_DIR', '')
    root = Path.cwd()
    if not configured or str(root) != configured or str(root.resolve()) != configured or os.environ.get('LARAVEL_STORAGE_PATH'):
        raise ValueError('unexpected checkout or storage override')
    # Every existing ancestor must be a genuine directory, never a symlink.
    for path in (root, *root.parents):
        if not stat.S_ISDIR(path.lstat().st_mode):
            raise ValueError('linked checkout ancestor')
    paths = [root, root/'storage', root/'storage/app', root/'storage/app/private']
    for path in paths:
        entry = path.lstat()
        if not stat.S_ISDIR(entry.st_mode) or entry.st_uid != os.geteuid():
            raise ValueError('unowned or linked checkout storage')
    target = paths[-1]
    before = stat.S_IMODE(target.lstat().st_mode)
    # Change this exact directory only. Never normalize parents or descendants.
    target.chmod(0o700, follow_symlinks=False)
    if stat.S_IMODE(target.lstat().st_mode) != 0o700:
        raise ValueError('private root mode was not applied')
    print(f'CI private storage mode: {before:04o} -> 0700 (verified)')
except (OSError, ValueError):
    print('CI private storage boundary could not be verified.', file=sys.stderr)
    sys.exit(1)
PY_STORAGE
}
verify_gitlab_process_control() {
    php -r 'if (!function_exists("posix_setrlimit") || !function_exists("pcntl_signal") || !defined("SIGXFSZ")) { fwrite(STDERR, "Native physical-write tests require POSIX resource limits and PCNTL signals.\n"); exit(1); }'
}
if [[ ${BASH_SOURCE[0]} != "$0" ]]; then return; fi
if [[ $# != 0 || ${GITLAB_CI:-} != true || ${CI_DISPOSABLE_ENVIRONMENT:-} != true || $EUID != 0 ]]; then
    echo 'PHP setup requires a disposable GitLab container running as root.' >&2
    exit 1
fi
php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'
# The official CLI image defaults to 128M, which cannot hold the full Laravel
# test shards. Keep this disposable CI process bounded; application subprocess
# limits in existing media/scanner commands remain explicit and unchanged.
printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/vasey-ci-memory.ini
php -r 'if (ini_get("memory_limit") !== "512M") { fwrite(STDERR, "The bounded CI PHP memory limit was not loaded.\n"); exit(1); }'
apt-get update
apt-get install --yes --no-install-recommends git unzip python3 curl ca-certificates \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    libsqlite3-dev libcurl4-openssl-dev libxml2-dev ffmpeg util-linux qpdf poppler-utils
missing_extensions=()
for extension in pdo_mysql pdo_sqlite mbstring intl bcmath gd zip curl dom xml xmlwriter pcntl; do
    if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then
        missing_extensions+=("$extension")
    fi
done
if ! php -r 'exit(extension_loaded("gd") ? 0 : 1);'; then
    docker-php-ext-configure gd --with-freetype --with-jpeg
fi
if [[ ${#missing_extensions[@]} -gt 0 ]]; then
    docker-php-ext-install -j2 "${missing_extensions[@]}"
fi
verify_gitlab_process_control
composer_directory=$(mktemp -d)
trap 'rm -rf -- "$composer_directory"' EXIT
curl --fail --silent --show-error https://getcomposer.org/installer -o "$composer_directory/installer.php"
curl --fail --silent --show-error https://composer.github.io/installer.sig -o "$composer_directory/installer.sig"
php -r 'if (trim(file_get_contents($argv[2])) !== hash_file("sha384", $argv[1])) { fwrite(STDERR, "Composer installer checksum mismatch.\n"); exit(1); }' "$composer_directory/installer.php" "$composer_directory/installer.sig"
php "$composer_directory/installer.php" --2 --install-dir=/usr/local/bin --filename=composer
prepare_gitlab_private_storage
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate --force
