# Commands and observed outcomes

Dependency source `30c4f7caeac91ad198e33e3088d46c6ff376155f`. Primary worktree
`/workspace/VA-Studio-dependency-adapters`; separate SQLite/build/upgrade worktree
`/workspace/VA-Studio-dependency-adapters-sqlite`, detached at that same source. The latter has its own Composer
autoload files pointing at the primary's newly installed package directories. Its node_modules link points at
the primary's clean npm installation. The main integration adds only the tooling review receipt.

All command output was redirected to `/workspace/runtime/dependency-evidence/adapters/` and archived here.
Use `source /workspace/.va-studio-toolchain/activate.sh` for the recorded PHP/Composer runtime. Installation,
registry and real PHP/native/loopback checks used authorized sandbox escalation; the normal sandbox synthesizes
directory ownership and cannot reach the private socket. No file admission guard was relaxed.

| Command | Exit / retained output |
| --- | --- |
| `composer outdated --locked --direct --format=json` | 0, `outdated-before.json` / `.stderr`; lists available versions |
| `composer audit --locked --format=json` | 0, `audit-before.json` / `.stderr` |
| `composer update laravel/framework:13.35.0 inertiajs/inertia-laravel:3.5.1 laravel/prompts:0.3.25 --minimal-changes --no-install --no-scripts --no-interaction` | 0, `update-composer-lock.txt` |
| `npm install --package-lock-only --ignore-scripts @inertiajs/react@3.8.0 --cache /workspace/runtime/npm-cache` | 0, `update-npm-lock.txt` |
| `COMPOSER_CACHE_DIR=/workspace/runtime/composer-cache composer install --no-interaction --prefer-dist` | 0, `composer-install.txt`; fresh vendor, normal post-autoload scripts |
| `npm ci --include=dev --cache /workspace/runtime/npm-cache` | 0, `npm-ci.txt` |
| `composer validate --strict` | 0, `composer-validate.txt` |
| `composer check-platform-reqs` | 0, `composer-platform.txt` |
| `composer outdated --direct --format=json` | 0, `outdated-after.json` / `.stderr`; available versions remain |
| `composer audit --format=json` | 0, `audit-after.json` / `.stderr` |
| `npm audit --json --audit-level=high --include=dev --cache /workspace/runtime/npm-cache` | 0, `npm-audit-after.json` / `.stderr` |
| `npm outdated --json --include=dev --cache /workspace/runtime/npm-cache` | 1, `npm-outdated-after.json` / `.stderr` |
| `npm test -- --reporter=default --reporter=junit --outputFile=/workspace/runtime/dependency-evidence/adapters/frontend.xml` | 0, `frontend.txt` / `.xml`; 65 files / 1,130 cases |
| `npm run build` | 0, `build.txt`; SQLite worktree after its PHP suite completed |
| `python3 scripts/ci/scan-client-bundle.py` | 0, `scan-production.txt` |
| `npm run build:preview` | 0, `build-preview.txt` |
| `python3 scripts/ci/scan-client-bundle.py dist/design-preview` | 0, `scan-preview.txt` |

The four build/scan commands were one `&&` chain with exit 0; none was skipped. PHP runs kept public/build absent
in their own worktrees. All JUnit files were parsed, not just their console summaries.

SQLite, fifteen whole files, exit **0**, `sqlite.txt` / `.xml`, **260 / 3,474 / zero skips**:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/OperatorAuthorityTest.php tests/Feature/OperatorMfaTest.php \
  tests/Feature/StagingOperatorMfaTest.php tests/Feature/RightsScopeStaffMfaTest.php \
  tests/Feature/RightsScopeCommandAttestationTest.php tests/Feature/ProductionCommerceReadinessTest.php \
  tests/Feature/PersistentCatalogDraftImportTest.php tests/Feature/CatalogPaginationTest.php \
  tests/Feature/PublicCatalogRelatedLinksTest.php tests/Feature/StorefrontMetadataTest.php \
  tests/Feature/PublicTrackEmbedRouteCacheTest.php tests/Feature/PublicTrackEmbedTest.php \
  tests/Feature/SiteScheduleRunnerTest.php tests/Unit/TcpdfContractRendererTest.php \
  tests/Unit/ContractRenderProfileRegistryTest.php \
  --log-junit /workspace/runtime/dependency-evidence/adapters/sqlite.xml
```

Native MySQL, primary worktree, exit **0**, `mysql.txt` / `.xml`, **23 / 399 / zero skips**:

```sh
env TMPDIR=/tmp/va-studio-importer-h1-20261012 APP_ENV=testing APP_DEBUG=false \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33396 DB_USERNAME=root DB_PASSWORD= \
  DB_DATABASE=vaseyaudio_catalog_import DB_URL= DB_SOCKET=/workspace/runtime/mysql-run/mysql.sock \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --no-progress \
  tests/Feature/RightsScopeWriterConcurrencyTest.php tests/Feature/RightsScopeStaffMfaTest.php \
  tests/Feature/RightsScopeCommandAttestationTest.php tests/Feature/PersistentCatalogDraftImportTest.php \
  --filter '/(RightsScopeStaffMfaTest|RightsScopeCommandAttestationTest|test_nested_scope_write_refuses_attestation_consumed_after_its_repeatable_read_snapshot|test_engine_change_racing_the_pin_while_an_earlier_open_waits_is_refused)/' \
  --log-junit /workspace/runtime/dependency-evidence/adapters/mysql.xml
```

Socket-local root is for this disposable server only. `SELECT VERSION()` returned **8.4.11**. The server uses
`/workspace/runtime/mysql-data`, its own socket and port 33396, with mysqlx disabled. No native result is inferred
from SQLite. Container image digest and isolation recipe are retained in the earlier importer H1 packet.

Stopped-copy upgrade (SQLite worktree after builds), exit **0**, `stopped-copy-upgrade.txt`: **14 passed**,
zero failures/cancellations/skips/todos:

```sh
TMPDIR=/tmp/va-studio-importer-h1-20261012 PERSISTENT_UPGRADE_REQUIRE_PHP=1 \
  node --test scripts/ops/persistent-content-upgrade.test.mjs
```

This explicitly requires real PHP/SQLite/POSIX and built assets. The fixtures use private temporary directories
and local HTTP; no host/provider operation is represented by their result.

Changed-lock probe, exit **0**, `changed-lock-identity.txt`:

```sh
TMPDIR=/tmp/va-studio-importer-h1-20261012 node \
  /workspace/runtime/dependency-evidence/adapters/changed-lock-probe.mjs \
  /workspace/VA-Studio-dependency-adapters-sqlite
```

The exact probe source is retained beside this file. It uses actual prior main `0b4a2e76` and candidate lock bytes
with unchanged migrations/build manifest. It runs the genuine Node identity functions against a disposable
initializing workspace; it executes no PHP and makes no completed prior-runtime installation claim.
