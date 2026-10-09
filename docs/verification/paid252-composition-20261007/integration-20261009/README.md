# Paid252 integration with main, 2026-10-09

Development evidence from the Claude Code harness for PR #56. Not Foundation or final acceptance.

## What was integrated

`b99b5dd4` merges the PR head `27a151b6` (round 23) with `harness/claude-handoff-20261009` `d4b11262`: main `49489697`
(D1 #65, test-commerce #62, Forge staging kit #63) plus three docs-only handoff commits carrying the #63 ledger and
status rows. The merge had no conflicts. `CHANGELOG.md` auto-merged as the union of both sides;
`scripts/ci/database-sqlite-skips.json` is unchanged by main, so it keeps #56's one native-only pair.

The independent reviewer found the diff from `27a151b6` to `b99b5dd4` over every paid path to be 0 bytes (Addendum 15 §7).
The only later change on this branch is documentation: Addendum 15 and its evidence, this record, and the C11 (k) text in
`docs/ops/paid-delivery-runtime.md` corrected per review finding A15-L1.

## Focused run on the integrated commit

PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory, `public/build` absent, from the checkout root:
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result --filter PaidGrant tests/Feature`

| Source | Result | Evidence |
| --- | --- | --- |
| `b99b5dd4` | 136 tests, 4,954 assertions, 1 skipped (the pre-existing native-only `PaidGrantSchemaRecoveryTest` case, in the census), rc 0, 17 min | `family-sqlite-b99b5dd4.txt` |
| `b99b5dd4`, `PaidGrantRequestInstantTest` alone | 7 tests, 129 assertions, rc 0 | run before the family; the reviewer's own run is in Addendum 15 |

The assertion count differs from round 23's 4,540 (`55976099` plus its working tree). The paid source is identical and the test count is
the same (136). The difference is most likely main's added schema and fixtures reached through shared setup and
schema-wide assertions; this was not traced assertion by assertion.

## Not tested

Native MySQL (no MySQL 8.4 in this container), a real FPM request through the paid routes, the frontend suite. The
family stays default-off and unmounted; conditions C2, C4–C8 and C11 (including (k)) remain open for the pre-mount host
verification.
