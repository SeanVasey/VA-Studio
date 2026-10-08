# SQLite skip census normalization (2026-10-08)

This is development evidence from the Claude Code harness, not Foundation or final acceptance. It brings
`scripts/ci/database-sqlite-skips.json` back into line with the methods the SQLite suite actually skips. Foundation's
SQLite jobs require the skipped cases to equal exactly the cases whose (class, method) pair is listed there.

## Source and run

- **Source:** `main` at `d3e1c39a` (PR #58 merge), run in a detached worktree.
- **Shards:** generated with `python3 scripts/ci/phpunit-shards.py --shards=4 --prefix=phpunit-ci-local`.
- **Command per shard**, with `public/build` absent:
  `php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --configuration=phpunit-ci-local-<n>.xml --log-junit shard-<n>.xml`

| Shard | Result |
| --- | --- |
| 1 | 1,818 tests, 243 skipped, 4 failures (see below) |
| 2 | 1,818 tests, 113 skipped, 1 error and 2 failures (see below) |
| 3 | 1,818 tests, 161 skipped, OK |
| 4 | 1,818 tests, 100 skipped, 1 error (see below) |

The JUnit logs give 211 distinct skipped (class, method) pairs.

## Result

- **Listed but not skipped:** none.
- **Skipped but not listed:** 33 pairs, appended in this change. The census grows from 183 to 216 pairs; the existing entries and their order are unchanged.
- **Every appended pair:**
  - is a method that exists in the tree;
  - skips itself when the driver is not MySQL (in the method, or for the whole file in `setUp()`; checked for all 33), because it covers native dictionary admission, native row locks or observed contention.
- **Census self-test:** `python3 -I scripts/ci/test-database-receipts.py` gives 34 tests OK.
- **Pairs already on `main`:** four further skipped pairs (three `MembershipSchemaPreparationTest`, one `MemberOriginalSchemaPreparationTest`) were added to the census by PR #54 after `d3e1c39a`. PR #54's five billing pairs are for test files that do not exist at `d3e1c39a`, so this run cannot confirm them; PR #54's own evidence covers them.

## Failures seen in this run (not census entries)

- **Contract renderer cases:**
  - `IsolatedContractRendererAcceptanceTest`, `TestContractIssuanceTest`, `TestContractSuccessorActivationTest` and `TestContractProfileSuccessorTest`, one case each;
  - they fail with "Test contract issuance is unavailable" only in the census worktree, whose `vendor/` packages are symlinks to another checkout;
  - the renderer child runs under `open_basedir` set to the project root, so it cannot load the symlinked autoloader;
  - the same case passes in a normal checkout: `TestContractIssuanceTest::test_real_isolated_renderer_can_issue_one_private_pdf_from_an_actual_paid_grant` gave 1 test and 9 assertions, OK;
  - this is a harness artifact, not a product failure.
- **`PublicTrackEmbedTest` (4 cases, shard 1):**
  - the file passes alone (18 tests, OK), so an earlier test in that shard leaks state into later responses;
  - this is investigated separately and is not a census issue.

## Not tested

- MySQL shards. MySQL runs every listed method instead of skipping it.
- The Foundation workflow itself, which stays manual for the final integrated commit.
