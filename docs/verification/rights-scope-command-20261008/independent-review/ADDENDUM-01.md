# D1 addendum 01 — uncertain write outcomes

**Decision: APPROVE. F2 is closed; no unresolved findings in the reviewed repair.**

Reviewed sensitive source: `cae38c3051042e6c11729b7d869afd375c90ca98`, against the pre-repair PR head `7169348b03d0dfd3486eb576bcec3da0663d8dcf`. The original `DECISION.md` and execution artifacts remain unchanged. This append-only addendum supplies the current decision for the uncertainty repair.

## Finding and repair

[Codex finding F2 / P2](https://github.com/SeanVasey/VA-Studio/pull/65#discussion_r4225350441) correctly identified that the blanket `Throwable` catch appended “No changes were made.” after an unknown outcome. A service commit can succeed before an acknowledgement or console-output failure interrupts the command. The original independent review exercised pre-commit audit failure but missed this boundary.

The retained canonical red regression, `../red-unconfirmed-output.txt`, failed twice at `7169348b`: 2 tests / 18 assertions / 2 failures. Each case first established actual transaction completion, retained scope/link rows and an added audit, then rejected the false rollback claim. There was no enclosing test transaction hiding the real commit.

The repair handles generic write errors with “The result is unconfirmed,” instructs the operator to inspect and retry the exact original request with the same scope/revision/reference, and prints no private exception detail. Generic listing errors use a read-only unavailable message. Known input, authority and domain refusals remain unchanged. The operator contract explicitly states that a nonzero exit code does not establish rollback and forbids replacement references as an uncertainty-recovery strategy.

This preserves the existing transactional rights service, fresh authorization, hidden-input refusal, immutable identities/references, readiness checks and idempotent replay behavior. The repair changes no service mutation, lock order, migration, provider call or grant behavior.

## Independent boundary probes

`UnknownOutcomeAddendumProbeTest.php` uses `FinalizationDatabaseMigrations` without an enclosing test transaction. Its first case arms a one-shot `TransactionCommitted` exception after fixture setup. For register and link, the callback records transaction level 0 after the actual PDO commit, then interrupts the return path. Each command reports uncertainty while its new rows and attributed audit remain present. An exact retry returns `status=unchanged`, preserving the entire retained scope/link/audit row image without duplicates. Both observed interruption levels are `[0, 0]`.

The other new cases prove that a pre-commit audit failure leaves the rows unchanged while using conservative uncertainty wording, and that its exact retry can safely create the original requested effect. A listing-output interruption leaves every retained row unchanged and prints only the read-only unavailable error. All error projections exclude the synthetic private exception marker and reference.

The selected existing probes also reverify post-password role/verification withdrawal for both writes, actual successor revision readiness and invalid link-reference rejection. The historical probe source and logs were preserved; its three old exact-message cases were not selected because they describe the superseded generic wording. The new probes and current canonical hidden-input regression verify the revised error behavior with their state/privacy assertions intact.

## Actual execution

Environment: PHP 8.4.26, PHPUnit 12.5.34, source `cae38c3051042e6c11729b7d869afd375c90ca98`.

| Evidence | Execution | Result |
| --- | --- | --- |
| `addendum-01-sqlite.txt` | Independent new 3 cases plus existing 6 authority/readiness/reference cases | Exit 0; 9 tests / 110 assertions; no skips |
| `addendum-01-mysql84.txt` | Independent post-real-commit interruption/replay case for both register and link | Exit 0; 1 test / 38 assertions; no skips |
| `../command-sqlite-unconfirmed.txt` | Inspected implementer canonical command plus output-failure suites | Exit 0; 11 tests / 236 assertions; no skips |
| `../command-mysql84-unconfirmed.txt` | Inspected implementer native canonical suites | Exit 0; 11 tests / 236 assertions; no skips |

Independent SQLite invocation, from `/workspace/VA-Studio-rights`:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --do-not-cache-result --colors=never --filter='test_post_commit_interruptions|test_pre_commit_audit_failure|test_list_output_failure|test_post_password_authority_withdrawal|test_a_real_successor_revision|test_link_reference_format' docs/verification/rights-scope-command-20261008/independent-review/UnknownOutcomeAddendumProbeTest.php docs/verification/rights-scope-command-20261008/independent-review/IndependentRightsScopeProbeTest.php
```

Independent native invocation, with explicit sandbox network permission for the disposable loopback listener:

```sh
source /workspace/.va-studio-toolchain/activate.sh
export MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84
export MYSQL_TEST_PORT=33068
export MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private
bash scripts/dev/with-mysql-test-server.sh php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --do-not-cache-result --colors=never --filter=test_post_commit_interruptions docs/verification/rights-scope-command-20261008/independent-review/UnknownOutcomeAddendumProbeTest.php
```

Native output confirms MySQL **8.4.11**, `REPEATABLE-READ`, `performance_schema=1`, no Unix socket and a `127.0.0.1` listener. The helper owns generated temporary credentials and removes its server/data. Syntax and focused Pint checks of the new review probe passed. `git diff --check` passed, and a separate diff check confirmed the original review decision/probe/transcript artifacts were unchanged.

## Limits

The post-commit probe injects an exception after a real commit; it does not claim to reproduce an actual network acknowledgement loss. Its native execution is functional evidence, not concurrency or power-loss durability proof. This approval is for the exact reviewed development source. Cheap preflight and any documentation-only successor review remain integration steps; Foundation CI, real rights/provider interoperability, staging deployment and production acceptance remain outside this addendum.
