# VA-Studio development on macOS

The development repository is [vaseydev/va-studio](https://gitlab.com/vaseydev/va-studio). The preserved handoff is branch `codex/rights-writers-20261002-08690e2` at `8fdc341`; the continuation is in [draft MR !1](https://gitlab.com/vaseydev/va-studio/-/merge_requests/1), initially published at `495d3b1386880ad7cd98c79cbc95c6985eba5e2e`. Check its current head before selecting a candidate. Do not switch an existing Mac checkout until its local changes and selected branch have been inspected.

## Install development dependencies

In the intended existing checkout:

```sh
git status --short --branch
git remote -v
bash scripts/dev/bootstrap-macos.sh
```

The bootstrap requires macOS, a full VA-Studio Git checkout and Homebrew. If Apple's command line tools are absent, run `xcode-select --install` and finish that installation. If Homebrew is absent, follow the official [Homebrew installation instructions](https://brew.sh/), then rerun the bootstrap. It does not fetch or execute a Homebrew installation script.

The script installs missing formulae for [PHP 8.4](https://formulae.brew.sh/formula/php@8.4), [Node 24](https://formulae.brew.sh/formula/node@24), [Composer](https://formulae.brew.sh/formula/composer), [MySQL 8.4](https://formulae.brew.sh/formula/mysql@8.4), [FFmpeg](https://formulae.brew.sh/formula/ffmpeg), [qpdf](https://formulae.brew.sh/formula/qpdf), [Poppler](https://formulae.brew.sh/formula/poppler) and [ClamAV](https://formulae.brew.sh/formula/clamav). These formula names were checked against official Homebrew pages on 2026-10-02. Homebrew supplies patched versions within the versioned PHP, Node and MySQL lines; the application's committed lockfiles pin its package dependencies. It does not automatically upgrade installed formulae; incompatible installed versions fail validation and need a deliberate upgrade.

Homebrew may install additional dependencies, including another PHP for its Composer formula. The bootstrap invokes Composer's PHAR with the selected PHP 8.4 and scopes versioned keg paths to its own process. It does not force-link tools or edit shell profiles. A first Homebrew MySQL installation initializes Homebrew's default data directory; the script refuses that installation if this location already exists. It neither starts a database service nor uses that directory for application tests.

Composer runs `install --no-scripts` and npm runs `ci --ignore-scripts`, preserving the lockfiles and avoiding application lifecycle commands against an existing environment. The frontend build runs after installation. Existing `vendor` and `node_modules` are dependency installation targets; `npm ci` replaces the dependency directory. Existing source edits, branches, `.env`, encryption keys and databases are preserved. A missing `.env` is created exclusively from the local template with permissions `0600` and a random application key; no credentials are printed.

Optional modes:

```sh
# Tools only; no package installation, environment creation or build.
bash scripts/dev/bootstrap-macos.sh --check
# Locked application dependencies without building.
bash scripts/dev/bootstrap-macos.sh --no-build
# Also install browsers from the locked Playwright package.
bash scripts/dev/bootstrap-macos.sh --with-browsers
```

The browser installation uses the local executable, avoiding `npx` fetching a different version. Chromium/WebKit installation is not a test pass or physical iPhone acceptance.

## Start an isolated local installation

Before invoking Artisan, inspect your `.env` privately and ensure it is a disposable local installation: `APP_ENV=local`, local URL, test Stripe mode and no production database/storage/mail credentials. Inspect whether `bootstrap/cache/config.php` exists; cached configuration can override `.env`. Do not run package discovery or clear a cache that belongs to a deployed installation. Use a separate checkout for development if this checkout serves real traffic.

After confirming the checkout is local, use the scoped runtime paths in your current terminal:

```sh
export PATH="$(brew --prefix php@8.4)/bin:$(brew --prefix node@24)/bin:$(brew --prefix mysql@8.4)/bin:$PATH"
php artisan config:clear
php artisan package:discover --ansi
php artisan filament:upgrade
```

The bootstrap intentionally leaves these Composer lifecycle steps explicit. For a fresh local SQLite installation, check that `DB_CONNECTION=sqlite` points to the intended disposable local database, then follow [README local setup](../README.md#run-locally) for database creation, migrations and interactive administrator provisioning. Never regenerate an existing encryption key. A bootstrap success alone does not mean the application has a database, administrator, media worker or launch configuration.

For genuine MySQL tests, use a disposable server instead of Homebrew's persistent service:

```sh
MYSQL_TEST_BASEDIR="$(brew --prefix mysql@8.4)" \
  bash scripts/dev/with-mysql-test-server.sh php vendor/bin/phpunit \
  tests/Feature/RightsDeclarationWriterConcurrencyTest.php
```

That launcher creates its own temporary database, testing environment and credentials, binds to localhost, then stops its server and removes its temporary directory. Its default test port is 33067; set `MYSQL_TEST_PORT` to another unused port if required. It does not migrate Homebrew's default database. Actual execution on the Mac remains to be verified; the launcher's prior MySQL evidence comes from Linux.

## Media processing boundary

The current worker requires Linux `prlimit` and checks resource limits before processing quarantined content. Installing FFmpeg and ClamAV on the Mac does not satisfy that requirement. The template's `/usr/bin` media paths also describe a Linux worker. Keep processing on a configured Linux development/production worker with the controls in [Media processing](media-processing.md). Do not substitute an unrestricted shell wrapper for `prlimit`, claim scanner readiness without signatures, or onboard real masters through an unverified worker.

Frontend, catalog administration and domain tests can still be developed on the Mac. Upload-to-publication readiness needs the Linux worker, approved seller tag, scanner signatures and the corresponding acceptance evidence.

## Verification status

This setup was authored in the Linux workspace. `bash -n scripts/dev/bootstrap-macos.sh` passed, and `python3 scripts/dev/test-bootstrap-macos.py` passed 13 checks with PHP 8.4 on the process PATH, including execution of the real PHP environment-generation snippet. The other checks use mocked command routing to verify platform rejection, dependency installation choices, existing-environment preservation and browser opt-in. They do not prove Homebrew installation, macOS binaries, native browser launch or application startup on Sean's Mac. No Mac connection was available, so no Mac packages were actually installed. Record the Mac tool versions, bootstrap result and selected commit after running it there; keep secrets and real media out of those reports.
