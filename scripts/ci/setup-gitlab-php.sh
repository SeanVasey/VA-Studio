#!/usr/bin/env bash
set -euo pipefail
apt-get update
apt-get install --yes --no-install-recommends git unzip python3 curl ca-certificates \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    libsqlite3-dev libcurl4-openssl-dev libxml2-dev ffmpeg util-linux qpdf poppler-utils
missing_extensions=()
for extension in pdo_mysql pdo_sqlite mbstring intl bcmath gd zip curl dom xml xmlwriter; do
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
composer_directory=$(mktemp -d)
trap 'rm -rf -- "$composer_directory"' EXIT
curl --fail --silent --show-error https://getcomposer.org/installer -o "$composer_directory/installer.php"
curl --fail --silent --show-error https://composer.github.io/installer.sig -o "$composer_directory/installer.sig"
php -r 'if (trim(file_get_contents($argv[2])) !== hash_file("sha384", $argv[1])) { fwrite(STDERR, "Composer installer checksum mismatch.\n"); exit(1); }' "$composer_directory/installer.php" "$composer_directory/installer.sig"
php "$composer_directory/installer.php" --2 --install-dir=/usr/local/bin --filename=composer
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate --force
