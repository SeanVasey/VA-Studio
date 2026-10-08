# D1 rights-scope operator command

The runtime source tested locally is `3506c75d0609a75f46809d394fea586f38462bc2` (WIP `732d640e` integrated with main `3aad7d15`). Later changes to this record and the operator documentation do not change runtime source.

Environment: PHP 8.4.26, PHPUnit 12.5.34, SQLite in-memory; no built frontend in the worktree. Locked vendor packages are shared by the worktree with its own Composer autoload metadata.

Run PHPUnit directly because the retained Collision runner is unavailable:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/RightsScopeCommandTest.php
```

- `red-main.txt`: the new regression copied onto main `3aad7d15`, without the command. Its four assertions prove the unlinked offer gives `INVENTORY_SCOPE_UNAVAILABLE` / 409 and creates no order, before the absent operator command errors. Exit 2; 1 test, 4 assertions, 1 error. This is retained baseline evidence, not a passing test.
- `initial-sqlite.txt`: the command suite on `3506c75d`; exit 0, 8 tests / 192 assertions. It proves linking allows order preparation, current staff/password/environment refusals, malformed/missing IDs, immutable conflicts, readiness refusal and idempotent repeats without duplicate audits. References and password values are absent from operator output.
- `pint.txt`: `php vendor/bin/pint app/Console/Commands/ManageRightsScopes.php tests/Feature/RightsScopeCommandTest.php`, exit 0, no runtime source diff.

`affected-sqlite.txt`: the direct PHPUnit command above with `tests/Feature/RightsScopeCommandTest.php tests/Feature/SharedInventoryTest.php tests/Feature/SharedInventoryMigrationTest.php tests/Feature/RightsScopeWriterAuthorityTest.php tests/Feature/OrderPreparationTest.php`; exit 0, **112 tests / 1,051 assertions**, no skips. An independent rights/authorization review must precede merge.

Native MySQL is unavailable locally. Attempts to retrieve the official MySQL 8.4.11 archive from `cdn.mysql.com` and the server package from `repo.mysql.com` returned HTTP 403. SQLite evidence does not prove native concurrency. The command delegates mutation to the existing reviewed domain service and changes no migration or lock ordering; no new concurrency claim, actual seller rights, real provider transaction or staging deployment is made.
