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

The full run is on `d3e1c39a`; the policy this PR changes is `main`'s at `89e3e61f`. The two comparisons are kept apart.

### 1. Full run against its own source policy (`d3e1c39a`, 174 pairs)

- **Listed but not skipped:** none.
- **Skipped but not listed:** 37 pairs.

### 2. Integrated policy (`main` at `89e3e61f`, 183 pairs; this PR makes it 216)

- **Between the two sources:** PR #54 (`d3e1c39a..89e3e61f`) added nine pairs to the policy.
  - Four of them are among the 37 above: three `MembershipSchemaPreparationTest` pairs and one `MemberOriginalSchemaPreparationTest` pair.
  - Five are billing pairs for test files that do not exist at `d3e1c39a`.
- **This PR appends the remaining 33 of the 37.** The existing entries and their order are unchanged.
- **Every appended pair:**
  - is a method that exists in the tree;
  - skips itself when the driver is not MySQL (in the method, or for the whole file in `setUp()`; checked for all 33), because it covers native dictionary admission, native row locks or observed contention.
- **What PR #54 changed, and the extra run that covers it:**
  - Test changes: only under `tests/Feature/ProductionMembership*` and `tests/Support/`.
  - Application changes: only `app/Domain/Memberships/`, one job and one console command.
  - No test outside those directories references the changed classes or the support fixtures (checked with grep).
  - So the integrated tree at this PR's head `c233eb62` was re-run for those directories on SQLite.

| Directory at `c233eb62` | Result |
| --- | --- |
| `ProductionMembership` | 44 tests, 3 skipped, OK |
| `ProductionMembershipBilling` | 180 tests, 5 skipped, OK |
| `ProductionMemberOriginals` | 24 tests, 1 skipped, OK |

In those classes, the run skipped 9 distinct pairs and the policy at the head lists 9. Listed but not skipped: none. Skipped but not listed: none.

Commands, in a detached worktree at `c233eb62` created with `scripts/dev/mkworktree.sh`, with `public/build` absent:

```sh
for d in ProductionMembership ProductionMembershipBilling ProductionMemberOriginals; do
  php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- \
    --colors=never --log-junit "$d.xml" "tests/Feature/$d"
done
```

## Evidence files (`evidence/`)

| File | Content |
| --- | --- |
| `skipped-pairs-d3e1c39a-full.txt` | The 211 distinct skipped `Class::method` pairs of the full `d3e1c39a` run, derived from its four JUnit reports |
| `integrated-<directory>.junit.xml` | The three JUnit reports of the `c233eb62` rerun; worktree path prefix removed |
| `integrated-<directory>.txt` | Each rerun's PHPUnit summary line |
| `skipped-pairs-c233eb62-membership.txt` | The 9 distinct skipped pairs of that rerun |

Both pair lists come from the same derivation:
- each `<testcase>` with a `<skipped>` child is keyed as (`class`, `name` without its ` with data set` suffix);
- the keys are de-duplicated and sorted.

The policy comparisons are set operations between those keys and `scripts/ci/database-sqlite-skips.json` at the stated commit. The four full-run JUnit reports (about 0.7 MB each) are not committed; the pair list is their identity-bearing content.

- **Combined:** for every other file, the `d3e1c39a` run applies unchanged. The result is that the 216-pair policy equals the SQLite skips of the integrated tree, up to the limits below.
- **Census self-test:** `python3 -I scripts/ci/test-database-receipts.py` gives 34 tests OK.

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
- A complete SQLite run of the integrated tree. The combination above rests on PR #54's diff being confined to the re-run directories.
