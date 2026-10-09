# D1 rights-scope operator command

The initial runtime source tested locally is `3506c75d0609a75f46809d394fea586f38462bc2` (WIP `732d640e` integrated with main `3aad7d15`). `1d28114aa9b7066a96c5372631eb71127f59adec` closes independent review F1 by disabling visible password fallback. The latest sensitive source is `cae38c3051042e6c11729b7d869afd375c90ca98`, addressing Codex's uncertain-write finding below. Later changes to this record do not change runtime source.

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

## Review F1 and first candidate checks

The independent reviewer reproduced visible password echo with the original default fallback using a synthetic public marker. `red-hidden-prompt.txt` retains the canonical regression before the fix: exit 1, 1 test / 3 assertions / 1 failure. The emitted question allowed fallback. The fix passes `false` to Laravel's `secret()`; unsupported hiding now produces the generic refusal without a write. `command-sqlite-final.txt` is green at `1d28114a`: exit 0, **9 tests / 198 assertions**, no skips. `pint-final.txt` records a clean formatting check of the changed command and suite.

`command-mysql84-final.txt` is also green at `1d28114a`: exit 0, **9 tests / 198 assertions**, no skips, on the independently started disposable **MySQL 8.4.11** server. The server facts are printed before PHPUnit in the retained output.

## Native environment and limits

The Oracle download hosts returned HTTP 403, but the official `mysql:8.4.11` Docker image was accessible. `mysql-image-provenance.json` records its immutable linux/amd64 manifest digest and layer hashes; all downloaded layers were hash-verified. Its server binary and dependencies were extracted into the owned toolchain directory without a container daemon or system installation. The rootless distribution wrapper points to extracted plugin/messages paths and sets `secure-file-priv=NULL` (file import/export disabled). The repository helper uses a disposable schema, generated credentials, no Unix socket and a loopback listener with explicit sandbox network permission, and removes its server and scratch data afterward.

Four harness attempts did not reach PHPUnit: direct execution of the non-executable helper; a missing package-default `secure_file_priv` directory; plugin-path setup plus denied loopback sockets; then denied loopback sockets again. These errors are retained in `mysql84-harness-attempt1.txt` through `mysql84-harness-attempt4.txt`. `initial-command-mysql84.txt` subsequently passed **8 tests / 192 assertions**, no skips, at the original sensitive source, with MySQL **8.4.11**, `REPEATABLE-READ` and `performance_schema=1` confirmed in the output.

To reproduce a native selection with this retained toolchain:

```sh
source /workspace/.va-studio-toolchain/activate.sh
export MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84
export MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private
bash scripts/dev/with-mysql-test-server.sh php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/RightsScopeCommandTest.php
```

The command delegates mutation to the existing reviewed domain service and changes no migration or lock ordering. These focused checks make no new native concurrency claim and do not prove actual seller rights, a real provider transaction or staging deployment. Foundation CI remains reserved for the final integrated candidate.

## Codex P2: unconfirmed write outcome

Codex [review comment 4225350441](https://github.com/SeanVasey/VA-Studio/pull/65#discussion_r4225350441) found that a lost commit acknowledgement or failed success output could report "No changes were made" after the domain write had committed. The generic write catch now reports an **unconfirmed** result and instructs an exact original-request retry. Known input, authority and domain refusals retain their existing messages; a failed read-only listing reports listing unavailable.

`red-unconfirmed-output.txt` retains two actual regressions at the first PR candidate: each throws from success output **after** its register/link write and audit have committed, with transaction level zero, then fails on the false rollback claim. Exit 1, 2 tests / 18 assertions / 2 failures. The new suite uses `FinalizationDatabaseMigrations` rather than a wrapping test transaction so the post-commit assertion is meaningful.

At sensitive source `cae38c30`, the original command suite plus both post-commit output cases pass on SQLite: `command-sqlite-unconfirmed.txt`, exit 0, **11 tests / 236 assertions**, no skips. Each uncertain write retains its rows/audit, emits no private exception or reference, and an exact retry succeeds unchanged without duplicate evidence. The original hidden-password fallback and refusal/privacy checks remain.

`command-mysql84-unconfirmed.txt` records the same current canonical selection on disposable **MySQL 8.4.11**: exit 0, **11 tests / 236 assertions**, no skips. The independent [addendum 01](independent-review/ADDENDUM-01.md) approves `cae38c30` after separately injecting an exception after a real commit for both writes and proving exact retries preserve rows and audits. Its selected SQLite probes pass **9 tests / 110 assertions**, and its native post-commit probe passes **1 test / 38 assertions**. These outputs establish the exercised functional boundaries; they are not new concurrency or network-fault evidence.

The first PR preflight completed successfully at `7169348b03d0dfd3486eb576bcec3da0663d8dcf` (run [37862369707](https://github.com/SeanVasey/VA-Studio/actions/runs/37862369707)); this code repair requires a fresh preflight and Codex review before merge. The independent addendum is retained separately from the original approval.
