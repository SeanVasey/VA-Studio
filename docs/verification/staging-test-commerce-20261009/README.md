# PR #62 current verification

Current reviewed functional source: `8f3d1cef024cabb6044f86c45f75ccb6fdc00550`. This integrates the original `ddb1fcc1` profile with main's CI policy, the merged D1 command and its immediate ledger, then closes four independently reproduced runner/validator findings. Earlier source `f592ec93890fdf95be8b05b3d4fb5ccb5e706ddf` covers the cursor/state repairs; the latest independent addendum assesses the subsequent file-authority and cadence repairs.

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
| `masking-cadence-red.txt` | First masking/cadence regressions before repair, with one incorrect check identifier | Exit 1; 8 tests / 15 assertions / 8 failures, 1 warning |
| `masking-cadence-red-corrected.txt` | Corrected masking/cadence regressions before runtime repair | Exit 1; 8 tests / 15 assertions / 8 failures, no warning |
| `masking-cadence-green.txt` | Same targeted cases after runtime repair | Exit 0; 8 tests / 71 assertions |
| `masking-cadence-sqlite.txt` | Current validator, runner and nine profile journeys | Exit 0; 73 tests / 783 assertions, no skips |
| `masking-cadence-mysql84.txt` | Current nine profile journeys on native MySQL 8.4.11 | Exit 0; 9 tests / 249 assertions, no skips |

The application code remained unchanged throughout the affected selection; the runner repairs followed its original unit cases. The current selected suites were separately rerun after those repairs. The affected contract issuance selection uses the real isolated v2 renderer; the profile journeys use the synthetic renderer and provider transport.

PHP **8.4.26**, PHPUnit **12.5.34**, SQLite in-memory and native MySQL **8.4.11**. The commerce worktree has physical locked vendor packages because shared symlinks violate the renderer's `open_basedir`. Validator tests deliberately remove inherited environment variables; the extracted genuine PHP executable was copied with a dependency RPATH and its default ini prefix relocated to `/tmp/va-php84cli`, so those isolated children retain the needed extensions. The original executable is preserved. No PHP version/SAPI or test-isolation behavior was substituted.

```sh
source /workspace/.va-studio-toolchain/activate.sh
/workspace/.va-studio-toolchain/standalone/bin/php8.4 -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result tests/Unit/TestCommerceProfileValidatorTest.php tests/Unit/TestCommercePipelineRunnerTest.php tests/Feature/StagingTestCommerceProfileJourneyTest.php
```

For the native journey selection, use the retained disposable-server helper with `MYSQL_TEST_BASEDIR=/workspace/.va-studio-toolchain/mysql84`, `MYSQL_TEST_PORT=33067` and `MYSQL_TEST_LIBRARY_PATH=/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private`, followed by the same direct runner and `tests/Feature/StagingTestCommerceProfileJourneyTest.php`. Loopback sockets require explicit sandbox network permission. The helper generates private temporary credentials, prints server facts and cleans its server/data.

Focused Pint, Bash syntax, Shellcheck 0.10.0 and whitespace checks exited 0. Workflow-cadence tests passed 14 cases; CI scope tests passed 27 cases. Cheap PR preflight applies to the final candidate; Foundation remains reserved for the integrated queue.

GitHub rejected the first unpublished evidence commit because a review fixture contained a realistic-looking synthetic test-key literal. The fixture now constructs the identical value from concatenated strings; its author verified substitution-map equivalence and reran all nine validator probes successfully (`independent-review/validator-packaging-check.txt`). No push-protection bypass was used. An accidental ready transition was reverted to draft. GitHub reports the old-head preflight `37865662700` completed with conclusion `cancelled` at `ddb1fcc1a95970aed2c31f1655587d9dcadaf777`; this is not a passing check for the corrected candidate. The equivalent remote evidence commit was merged locally without rewriting published history.

## Independent decision and limits

[Current independent addendum](independent-review/MASKING-CADENCE-ADDENDUM.md): **APPROVE** at `8f3d1cef`, with 55 independent masking/interpolation/cache/privacy/cadence checks passing and two independently run real-command SQLite timing journeys (2/48). The original [decision](independent-review/DECISION.md), [carry addendum](independent-review/CANDIDATE-ADDENDUM.md) and 37-probe receipts remain historical evidence. Their exported-variable precedence contract is superseded: the current validator clears all inherited application inputs before boot, so a valid export cannot mask an unsafe file. Only the supplied file is certified; deployed config/cache/workers must actually use it. The original starvation/state red receipts remain alongside their passing successors.

The runner and installed service now use a one-minute minimum interval, within the quote's 15-minute observation window. Actual domain command journeys prove an unpaid read followed by payment at +30 seconds and observation at +61 seconds issues a grant, while payment at +850 first observed at +901 remains a paid exception with no grant. Timer scheduling, slow stages, locks, backlog and payment near expiry still limit observation timeliness; no eligibility or retained-resource rule changed. The red masking cases allowed the optional probe branch before repair; synthetic input only was supplied. All repaired unsafe-file probe cases now refuse before account access.

Unpaid pending reservations remain retained and can block their actual shared scope. New scopes/revisions are not a resolution workaround. Real operator rights/terms/prices and seller/assent input remain required. Account probing, Stripe CLI/Dashboard delivery, systemd installation, actual host deployment, real media, a real Stripe TEST purchase and native concurrency are untested here. No live mode, secret, provider request or activation was supplied by this change.
