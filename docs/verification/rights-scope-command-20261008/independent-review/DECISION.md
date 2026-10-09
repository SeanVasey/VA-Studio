# D1 independent rights and authorization review

**Decision: APPROVE. No unresolved findings.**

Reviewed candidate: `3a3468b3a14c0ecd95f904d5cf2ba0828c622e8b`, against main `3aad7d15e83ebb25a25f85b4c819a67fcc52253a`.

The final sensitive source is `1d28114aa9b7066a96c5372631eb71127f59adec`. The later candidate adds verification documentation and retained output only. The reviewer inspected that delta and verified that `git diff --exit-code 1d28114aa9b7066a96c5372631eb71127f59adec..3a3468b3a14c0ecd95f904d5cf2ba0828c622e8b -- app tests composer.json composer.lock bootstrap database` exits 0 without a diff. Final probes exercised this sensitive source; the candidate did not change it during their execution.

Scope: `ManageRightsScopes`, `RightsScopeCommandTest`, the operator contract and CHANGELOG, and the unchanged `ManageRightsScope`, inventory environment policy, persisted catalog gate, publication readiness, retained models and SQL guards to which the command delegates. This review owns only this directory and made no runtime, canonical-test, branch, push or merge changes.

## Finding F1 — closed

The original password prompt used Laravel's `secret()` default, which enables Symfony's visible fallback when terminal hiding fails. That contradicted the required hidden confirmation. A real PTY probe disabled stty support, used an empty in-memory database and typed a deliberately public synthetic marker. The marker was visibly echoed before the later generic refusal. The probe did not read any real credentials or persisted actor.

`hidden-prompt-red.txt` retains that transcript. `hidden-prompt-regression-red.txt` retains an independent failing regression: exit 1, 1 test / 3 assertions / 1 failure, with the emitted question's `fallback` equal to true instead of false. The implementer copied the regression into the canonical suite and separately retained its red run in `../red-hidden-prompt.txt` before the fix.

Commit `1d28114aa9b7066a96c5372631eb71127f59adec` changes the prompt to `secret('Staff password (hidden)', false)`. The canonical and independent regressions now pass. The real PTY probe now returns immediately with a generic refusal before receiving any stdin response; `hidden-prompt-green.txt` retains the result. The missing-hide path leaves scope/link/audit evidence unchanged and prints no exception details. **F1 is resolved.**

## Sensitive behavior reviewed

- The command does not confer staff authority from an ID alone: it checks the current persisted password, and the domain reloads and locks the actor and rechecks persisted catalog authority and email verification inside the mutation transaction. Independent probes withdraw either authority field after a successful password check and prove both register and link refuse with unchanged rights/audit evidence.
- The command delegates register/link mutations to `ManageRightsScope`. It adds no direct rights-table write, migration, provider request or grant. Scope identities, original references and revision associations retain domain conflict checks and existing SQL immutability guards. Identical retries create no duplicate rows or audits.
- Link readiness remains enforced under the domain's track/offer/scope locks. A probe publishes an actual successor commercial revision, proves the old revision refuses, verifies `list` exposes only the current unlinked revision, and links only that successor. The canonical end-to-end regression proves an unlinked selection refuses order preparation with 409 / `INVENTORY_SCOPE_UNAVAILABLE`, then succeeds after audited linkage.
- Registration/linkage and their attributed audit remain in the same transaction. Injected audit failures roll back both actions and preserve the previous rights/audit rows exactly. Generic command errors hide synthetic private exception details. Scope/link references are excluded from success, conflict, list and invalid-input output.
- Environment admission remains local/testing only; production writes and listing refuse. The read-only list prints public identity and current revision IDs, without private evidence references. Numeric IDs are bounded positive decimal strings; irrelevant/missing arguments and domain-invalid references refuse. Operator documentation directs staff to supply non-secret references, use exact revision IDs, verify shared rights relationships and explicitly link successors.

## Execution evidence

All commands ran from `/workspace/VA-Studio-rights` with PHP 8.4.26 and PHPUnit 12.5.34. The retained Collision runner is unavailable, so the direct entry point was used:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --do-not-cache-result --colors=never docs/verification/rights-scope-command-20261008/independent-review/IndependentRightsScopeProbeTest.php
```

| Evidence | Source | Result |
| --- | --- | --- |
| Independent canonical command rerun, `command-suite.txt` | Original command; runtime unchanged through `2bfaed149f5b51ef0c3c01a84603b3588f567f90` | Exit 0; 8 tests / 192 assertions |
| Independent initial adversarial probes, `probe-suite.txt` | `2bfaed149f5b51ef0c3c01a84603b3588f567f90` | Exit 0; 8 tests / 60 assertions |
| Hidden-fallback regression, `hidden-prompt-regression-red.txt` | Same pre-fix sensitive source | Exit 1; 1 test / 3 assertions / 1 failure |
| Independent final SQLite probes, `probe-suite-final.txt` | Final sensitive source `1d28114aa9b7066a96c5372631eb71127f59adec` | Exit 0; 9 tests / 66 assertions; no skips |
| Independent final MySQL probes, `probe-mysql84-final.txt` | Same final sensitive source | Exit 0; 9 tests / 66 assertions; no skips |
| Inspected final canonical output, `../command-sqlite-final.txt` and `../command-mysql84-final.txt` | Same final sensitive source | Each exit 0; 9 tests / 198 assertions; no skips |
| Inspected affected selection, `../affected-sqlite.txt` | Pre-fix sensitive source, before the one-line prompt repair | Exit 0; 112 tests / 1,051 assertions; no skips |

The independent native probe selection used:

```sh
source /workspace/.va-studio-toolchain/activate.sh
export MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84
export MYSQL_TEST_PORT=33068
export MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private
bash scripts/dev/with-mysql-test-server.sh php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --do-not-cache-result --colors=never docs/verification/rights-scope-command-20261008/independent-review/IndependentRightsScopeProbeTest.php
```

The invocation used explicit sandbox network permission for the disposable loopback listener. Native output confirms MySQL **8.4.11**, `REPEATABLE-READ`, `performance_schema=1`, no Unix socket and a `127.0.0.1` listener. The helper generates temporary credentials and removes the server and scratch data. The earlier independent harness attempt failed before PHPUnit because the image's default file-import directory was absent; `probe-mysql84-harness-attempt1.txt` retains that failure, which is not test evidence.

Both PTY commands were `php docs/verification/rights-scope-command-20261008/independent-review/hidden-prompt-probe.php`, with a real PTY. The regression red selection adds `--filter=test_staff_password_prompt_disables_visible_fallback_and_refuses_when_hiding_fails` to the direct PHPUnit command. `git diff --check` of the reviewed candidate against main and syntax checking of the review-only probe returned exit 0. Pint formatted only the two review-owned PHP probes; it did not change sensitive source.

## Limits

This approves the reviewed development change and closes F1. It is not Foundation CI, staging deployment acceptance, real seller-rights verification, Stripe interoperability, payment-to-contract/delivery acceptance or production authorization. The final targeted command and adversarial selections ran on SQLite and native MySQL; the 112-case broader SQLite selection predates the one-line prompt repair and was not rerun after it. The native probes are single-process functional evidence, not independent-process concurrency proof. Existing domain lock ordering and SQL guards were unchanged. Cheap PR preflight and the user's remaining staging inputs remain separate integration gates.
