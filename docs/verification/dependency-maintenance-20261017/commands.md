# Commands and exit statuses

Worktree `<worktree>` (branch `harness/dependency-maintenance-20261017`, base `efa6eea5`). PHP 8.4.26, Composer
2.8.12 with `COMPOSER_ALLOW_SUPERUSER=1`, Node 24.22.0 and npm 11.19.1 from `. /opt/nvm/nvm.sh && nvm use 24`.
Output was captured into a session scratch directory and archived beside this file with the scratch and worktree
paths replaced by `<scratch>` and `<worktree>`, ANSI colour removed and trailing whitespace trimmed. Nothing else
in any output was edited.

## Metadata and resolution

| Command | Exit | Retained output |
| --- | --- | --- |
| `composer outdated --direct --format=json` (before / after) | 0 / 0 | `metadata/composer-outdated-*.json` |
| `composer audit --format=json` (before / after) | 0 / 0 | `metadata/composer-audit-*.json` |
| `npm outdated --json` (before / after) | 1 / 1 | `metadata/npm-outdated-*.json`; exit 1 means updates remain available |
| `npm audit --json --audit-level=high` (before / after) | 0 / 0 | `metadata/npm-audit-*.json` |
| `composer update laravel/framework --with laravel/framework:13.36.0 --with-dependencies --no-install` | 0 | `install/composer-update-lock-broad-not-applied.txt`; 19 package changes, discarded (see README) |
| `composer update laravel/framework --with laravel/framework:13.36.0 --no-install` | 0 | `install/composer-update-lock-applied.txt`; one package change, applied |
| `npm install --package-lock-only --ignore-scripts --save-dev vite@8.3.4 @types/node@24.19.2` | 0 | `install/npm-update-lock.txt` |
| `npm install --package-lock-only --ignore-scripts --save-dev --save-exact jsdom@30.1.2` | 0 | same file |
| `npm install --package-lock-only --ignore-scripts --save @inertiajs/react@3.9.1` | 0 | same file |
| `npm ci --include=dev` | 0 | `install/npm-ci.txt`; 121 packages, 0 vulnerabilities |
| `composer install --no-interaction --no-progress -vv`, first attempt | 1 | `install/composer-install-attempt1-gitdir-missing.txt` |
| `rm -rf vendor/laravel/framework`, then the same `composer install` | 0 | `install/composer-install.txt` |
| `composer validate --strict` / `composer check-platform-reqs` | 0 / 0 | `install/composer-validate.txt`, `install/composer-platform.txt` |

The first `composer install` could not update `vendor/laravel/framework` in place because that directory had been
installed from a git source and its `.git` directory had been deleted earlier to save disk. The lock was not at
fault. The directory is gitignored and reproducible, so only it was removed in this worktree and Composer
reinstalled it at the locked reference `e5626e38`. The git clone Composer left behind was then deleted, as the
brief asks. This was an in-place update of the existing vendor tree, not a fresh 158-package install.

## Frontend

| Command | Exit | Retained output |
| --- | --- | --- |
| `npm run typecheck` | 0 | `frontend/typecheck.txt` |
| `npm test -- --reporter=default --reporter=junit --outputFile=<scratch>/ev/frontend.xml` | 0 | `frontend/frontend.txt`, `frontend/frontend.xml` |
| `npm run build` | 0 | `frontend/build.txt`; the existing greater-than-500 kB chunk warning is retained |
| `python3 scripts/ci/scan-client-bundle.py` | 0 | `frontend/scan-production.txt` |
| `npm run build:preview` | 0 | `frontend/build-preview.txt` |
| `python3 scripts/ci/scan-client-bundle.py dist/design-preview` | 0 | `frontend/scan-preview.txt` |
| `rm -rf public/build dist` | 0 | `public/build` was absent for every PHP test run |

`public/build` was rebuilt once more (exit 0) because `scripts/dev/persistent-content.test.mjs` needs real built
assets, and deleted again before any PHPUnit run.

## Preflight backend-quality steps run locally

Taken from `.github/workflows/preflight.yml`.

| Command | Exit | Retained output |
| --- | --- | --- |
| `php scripts/ci/verify-php-test-runtime.php` (local default `memory_limit=-1`) | 1 | `preflight/php-runtime-first-default-memory.txt` |
| `php -d memory_limit=512M scripts/ci/verify-php-test-runtime.php` (CI's ini value) | 0 | `preflight/php-runtime.txt` |
| `node --test scripts/ci/apply-playwright-webkit-offline-backport.test.mjs` | 0 | `preflight/webkit-backport.txt`; 33 pass |
| `PRIVATE_ALPHA_REQUIRE_PHP=1 node --test scripts/dev/private-alpha.test.mjs` | 0 | `preflight/private-alpha.txt`; 13 pass |
| `PERSISTENT_CONTENT_REQUIRE_PHP=1 node --test scripts/dev/persistent-content.test.mjs` | 0 | `preflight/persistent-content.txt`; 18 pass |
| `vendor/bin/pint --version`, then the four `php vendor/bin/pint --test <fixed lists>` steps | 0 each | `preflight/pint.txt`; Pint 1.32.1, all four passed |
| `composer audit` | 0 | `metadata/composer-audit-after.txt` |
| ten `python3 scripts/ci/test-*.py` / `scripts/dev/test-bootstrap-macos.py` / `scripts/test-publish-github.py` | 0 each | `preflight/python-safeguards.txt` |
| `npm audit --audit-level=high` | 0 | `metadata/npm-audit-after.json` |

Not run: the two `workflow_dispatch`-only steps (stopped-copy upgrade and persistent catalog import), the
Foundation, GitLab and browser workflows, and any hosted CI.

## PHP on SQLite

Every file group ran sequentially in the one worktree with this wrapper (the brief's command, with
`--log-junit <file>` and the file list appended):

```
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --log-junit <file>.xml <files>
```

The groups are `php-sqlite/selection-probe.txt` (calibration), `selection-chunk-a.txt`, `-b.txt` and `-c.txt`.
Sequential execution was deliberate: the suite's `Storage::fake` disks share paths under `storage/`.

## PHP on native MySQL 8.4.11

```
mkdir -p /var/lib/va-mysql84-m1/run && /opt/mysql-8.4.11/bin/mysqld --no-defaults --initialize-insecure --user=root --datadir=/var/lib/va-mysql84-m1/data
/opt/mysql-8.4.11/bin/mysqld --no-defaults --user=root --datadir=/var/lib/va-mysql84-m1/data --port=33585 --bind-address=127.0.0.1 \
  --socket=/var/lib/va-mysql84-m1/run/mysqld.sock --mysqlx=OFF --pid-file=/var/lib/va-mysql84-m1/run/mysqld.pid --log-error=/var/lib/va-mysql84-m1/server.err
CREATE DATABASE vaseyaudio_catalog_import;
env TMPDIR=<scratch>/tmp-mysql APP_ENV=testing APP_DEBUG=false VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_catalog_import \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33585 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_catalog_import DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' \
  -- --log-junit <scratch>/ev/mysql.xml --filter "$(cat php-mysql/selection-filter.txt)" $(cat php-mysql/selection-files.txt)
```

`VA_LOCAL_DISPOSABLE_MYSQL` is the repository's own explicit declaration (see
`tests/Support/DisposableTestDatabase.php`) that the named `vaseyaudio_*` database is disposable. It is accurate
here: the server and database were created for this lane only. Without it the first attempt refused admission
for all 80 cases before any assertion (`php-mysql/attempt1-admission-refused.txt`, retained).
