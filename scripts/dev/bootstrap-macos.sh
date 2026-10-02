#!/usr/bin/env bash
# Install locked development dependencies in this checkout without touching its DB.
set -euo pipefail

usage() {
    cat <<'TEXT'
Usage: bash scripts/dev/bootstrap-macos.sh [--check] [--no-build] [--with-browsers]
  --check          Validate existing tools only; do not install or modify files.
  --no-build       Install application dependencies without building assets.
  --with-browsers  Also install the locked Playwright Chromium and WebKit engines.
TEXT
}
check_only=false
build_assets=true
with_browsers=false
for argument in "$@"; do
    case "$argument" in
        --check) check_only=true ;;
        --no-build) build_assets=false ;;
        --with-browsers) with_browsers=true ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done
[[ "$(uname -s)" == Darwin ]] || { echo 'This bootstrap requires macOS; no files changed.' >&2; exit 2; }
command -v git >/dev/null || { echo 'Install Apple command line tools: xcode-select --install' >&2; exit 2; }
script_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
checkout_directory=$(cd "$script_directory/../.." && pwd -P)
[[ "$(git -C "$checkout_directory" rev-parse --show-toplevel)" == "$checkout_directory" ]] || {
    echo 'Run from a complete VA-Studio Git checkout; nested or copied scripts are refused.' >&2; exit 2;
}
cd "$checkout_directory"
[[ -f composer.lock && -f package-lock.json && -f artisan && -f .env.example ]] || {
    echo 'The VA-Studio lockfiles, artisan and environment template are required.' >&2; exit 2;
}
grep -Eq '"name"[[:space:]]*:[[:space:]]*"vaseydev/vaseyaudio"' composer.json &&
grep -Eq '"name"[[:space:]]*:[[:space:]]*"vaseyaudio"' package.json || {
    echo 'Unexpected project identity; no dependencies installed.' >&2; exit 2;
}
brew_command=$(command -v brew || true)
if [[ -z "$brew_command" ]]; then
    for candidate in /opt/homebrew/bin/brew /usr/local/bin/brew; do
        if [[ -x "$candidate" ]]; then brew_command="$candidate"; break; fi
    done
fi
[[ -n "$brew_command" ]] || {
    echo 'Homebrew is missing. Follow https://brew.sh/ installation instructions, then rerun.' >&2; exit 2;
}
brew_prefix=$("$brew_command" --prefix)
# Homebrew initializes its default MySQL directory on a first installation.
# Refuse that installation if any directory/file already occupies this location.
if ! "$brew_command" list --versions mysql@8.4 >/dev/null 2>&1 && [[ -e "$brew_prefix/var/mysql" || -L "$brew_prefix/var/mysql" ]]; then
    echo 'Existing Homebrew MySQL data found. Have its owner choose the compatible installation; this script will not initialize or replace it.' >&2
    exit 2
fi
formulae=(php@8.4 node@24 composer mysql@8.4 ffmpeg qpdf poppler clamav)
for formula in "${formulae[@]}"; do
    if ! "$brew_command" list --versions "$formula" >/dev/null 2>&1; then
        if "$check_only"; then echo "Missing Homebrew formula: $formula" >&2; exit 2; fi
        "$brew_command" install "$formula"
    fi
done
php_prefix=$("$brew_command" --prefix php@8.4)
node_prefix=$("$brew_command" --prefix node@24)
mysql_prefix=$("$brew_command" --prefix mysql@8.4)
composer_prefix=$("$brew_command" --prefix composer)
export PATH="$php_prefix/bin:$node_prefix/bin:$mysql_prefix/bin:$brew_prefix/bin:$PATH"
php_command="$php_prefix/bin/php"
composer_phar="$composer_prefix/libexec/composer.phar"
[[ -x "$php_command" && -f "$composer_phar" ]] || { echo 'Homebrew PHP/Composer installation is incomplete.' >&2; exit 2; }
"$php_command" -r '
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) { fwrite(STDERR, "PHP 8.4 required.\n"); exit(2); }
    foreach (["pdo_sqlite", "pdo_mysql", "mbstring", "intl", "bcmath", "gd", "fileinfo", "zip", "curl", "posix"] as $extension) {
        if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension."\n"); exit(2); }
    }
    $project = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
    if (($project["name"] ?? null) !== "vaseydev/vaseyaudio") { fwrite(STDERR, "Unexpected Composer project.\n"); exit(2); }
'
node -e 'const p=JSON.parse(require("fs").readFileSync("package.json", "utf8")); const [a,b]=process.versions.node.split(".").map(Number); if(p.name!=="vaseyaudio" || a!==24 || b<15) { console.error("VA-Studio requires Node >=24.15.0 <25."); process.exit(2); }'
composer_version=$("$php_command" "$composer_phar" --version --no-ansi)
[[ "$composer_version" == 'Composer version 2.'* ]] || { echo 'Composer 2 is required.' >&2; exit 2; }
mysql_version=$(mysqld --version)
[[ "$mysql_version" == *'Ver 8.4.'* ]] || { echo 'MySQL 8.4 is required.' >&2; exit 2; }
for executable in npm ffmpeg ffprobe qpdf pdftotext clamscan; do
    command -v "$executable" >/dev/null || { echo "Missing executable: $executable" >&2; exit 2; }
done
if "$check_only"; then echo 'macOS development tool checks passed. Application dependencies and media readiness were not tested.'; exit 0; fi
"$php_command" "$composer_phar" validate --strict
# No Artisan/package lifecycle scripts: existing cached config may point at real data.
"$php_command" "$composer_phar" install --no-interaction --prefer-dist --no-scripts
"$php_command" "$composer_phar" check-platform-reqs
npm ci --ignore-scripts
if [[ ! -e .env && ! -L .env ]]; then
    # Exclusive creation also prevents replacing an .env created concurrently.
    (umask 077; "$php_command" -r '
        $contents = file_get_contents(".env.example");
        $contents = preg_replace("/^APP_KEY=$/m", "APP_KEY=base64:".base64_encode(random_bytes(32)), $contents, 1, $count);
        if ($count !== 1) { fwrite(STDERR, "Expected one empty APP_KEY in template.\n"); exit(2); }
        $handle = fopen(".env", "x");
        if ($handle === false) { fwrite(STDERR, "Could not exclusively create .env.\n"); exit(2); }
        chmod(".env", 0600); fwrite($handle, $contents); fclose($handle);
    ')
    echo 'Created a local .env from the template with a new application key.'
else
    echo 'Kept the existing .env and application key.'
fi
if "$build_assets"; then npm run build; fi
if "$with_browsers"; then
    [[ -x node_modules/.bin/playwright ]] || { echo 'Locked Playwright installation is missing.' >&2; exit 2; }
    node_modules/.bin/playwright install chromium webkit
fi
echo 'Dependencies installed. No database migration, service start, branch switch or administrator provisioning was performed.'
echo 'Read docs/development-macos.md before application discovery, local database setup or media processing.'
