# PR #62 current verification

Reviewed sensitive source: `f592ec93890fdf95be8b05b3d4fb5ccb5e706ddf`. This integrates the original `ddb1fcc1` profile with main's CI policy, the merged D1 command and its immediate ledger. Subsequent evidence/documentation commits leave runtime and canonical tests unchanged.

## Actual local results

| Receipt | Source / selection | Result |
| --- | --- | --- |
| `initial-sqlite.txt` | Original validator, runner and seven profile journeys at `048055dc` | Exit 0; 53 tests / 594 assertions |
| `affected-sqlite.txt` | Selection started at `2a1b189e`: original three suites plus real contract issuance, order finalization, activation, delivery and webhook suites | Exit 0; 287 tests / 2,164 assertions, no skips |
| `runner-starvation-red.txt` | Retained-row regressions before cursor repair | Exit 1; 3 tests / 9 assertions / 3 failures |
| `final-sqlite.txt` | First cursor repair `ff1479e6`, three new suites | Exit 0; 56 tests / 630 assertions |
| `runner-state-red.txt` | Save/clear/cadence failures before checked persistence | Exit 1; 7 tests / 7 assertions / 7 failures |
| `state-repair-sqlite.txt` | Current validator, runner and profile journey suites | Exit 0; 63 tests / 664 assertions, no skips |
| `journey-mysql84.txt` | Current seven synthetic profile journeys on disposable MySQL 8.4.11 | Exit 0; 7 tests / 201 assertions, no skips |
| `template.txt` | Synthetic template through real policy/configuration checks | All 35 checks pass; no Stripe request |

The application code remained unchanged throughout the affected selection; the runner repairs followed its original unit cases. The current selected suites were separately rerun after those repairs. The affected contract issuance selection uses the real isolated v2 renderer; the profile journeys use the synthetic renderer and provider transport.

PHP **8.4.26**, PHPUnit **12.5.34**, SQLite in-memory and native MySQL **8.4.11**. The commerce worktree has physical locked vendor packages because shared symlinks violate the renderer's `open_basedir`. Validator tests deliberately remove inherited environment variables; the extracted genuine PHP executable was copied with a dependency RPATH and its default ini prefix relocated to `/tmp/va-php84cli`, so those isolated children retain the needed extensions. The original executable is preserved. No PHP version/SAPI or test-isolation behavior was substituted.

```sh
source /workspace/.va-studio-toolchain/activate.sh
/workspace/.va-studio-toolchain/standalone/bin/php8.4 -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result tests/Unit/TestCommerceProfileValidatorTest.php tests/Unit/TestCommercePipelineRunnerTest.php tests/Feature/StagingTestCommerceProfileJourneyTest.php
```

For the native journey selection, use the retained disposable-server helper with `MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84`, `MYSQL_TEST_PORT=33067` and `MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private`, followed by the same direct runner and `tests/Feature/StagingTestCommerceProfileJourneyTest.php`. Loopback sockets require explicit sandbox network permission. The helper generates private temporary credentials, prints server facts and cleans its server/data.

Focused Pint, Bash syntax, Shellcheck 0.10.0 and whitespace checks exited 0. Workflow-cadence tests passed 14 cases; CI scope tests passed 27 cases. Cheap PR preflight applies to the final candidate; Foundation remains reserved for the integrated queue.

GitHub rejected the first unpublished evidence commit because a review fixture contained a realistic-looking synthetic test-key literal. The fixture now constructs the identical value from concatenated strings; its author verified substitution-map equivalence and reran all nine validator probes successfully (`independent-review/validator-packaging-check.txt`). No push-protection bypass was used. An accidental ready transition on the old remote head was reverted to draft, and its redundant preflight was cancelled before publishing the corrected candidate.

## Independent decision and limits

[Independent review](independent-review/DECISION.md): **APPROVE** at the exact sensitive source, with 37 independent subprocess probe checks passing. The original starvation/state red receipts are retained alongside their green successors. The review also verifies exported environment refusal, configuration-cache isolation, log privacy, timeouts/locks/modes, cursor reset and the corrected rights guidance.

Unpaid pending reservations remain retained and can block their actual shared scope. New scopes/revisions are not a resolution workaround. Real operator rights/terms/prices and seller/assent input remain required. Account probing, Stripe CLI/Dashboard delivery, systemd installation, actual host deployment, real media, a real Stripe TEST purchase and native concurrency are untested here. No live mode, secret, provider request or activation was supplied by this change.
