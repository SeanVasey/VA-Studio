# Deadline-case census update

Working directory `/workspace/VA-Studio-membership-award`; HEAD at final discovery `bf498055a5917172f930c877ffcff5f220dc4d4d`. The new deadline test/helper and concurrent application changes were uncommitted. Exact policy/test/helper SHA-256 values are retained in `selection-deadline-census.source.json` and `selection-deadline-summary.json`; those inputs were checked unchanged after final discovery. This selection task edited only the MySQL selection JSON and the workflow census comment. No product/test source was edited.

Exact commands (combined stdout/stderr and actual shell exits saved in matching `selection-deadline-*` files):

```sh
python3 scripts/ci/test-focused-tests.py
python3 scripts/ci/test-phpunit-shards.py
source /workspace/.va-studio-toolchain/activate.sh
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_DATABASE=selection_discovery_only DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync python3 scripts/ci/phpunit-shards.py --shards 8 --prefix phpunit-ci-mysql --mysql-native-selection --timings scripts/ci/phpunit-timings-mysql.json
git diff --check -- scripts/ci/database-mysql-selection.json .github/workflows/final-verification.yml
```

Observed exits: all 0. Policy suites: 50 and 52 tests, no failures/errors/skips. Actual PHPUnit discovery: 1930 selected cases in 180 files from 7900 total cases in 577 files; eight nonempty local listing shards. The three `MembershipAwardDeadlineTest` cases were explicitly verified in the source inventory and exactly once across the selected shard inventories. This uses PHPUnit `--list-tests-xml`; no functional test or migration was executed by the census. No application pass is claimed by this task.

The first census also exited 0 with these counts, but its post-discovery SHA check detected an owner cleanup amendment to the new deadline test. Its logs, source binding and generated files are preserved in `selection-deadline-initial/` as superseded source-changed evidence. The owner confirmed final bytes, and the same discovery command was then repeated successfully. The final file hash is `e8df808fa1b4a5a3767cc4f8554127e10102a3f4a859504a87af04a754dfdb5b`.

Seventeen untimed files use existing fallback weights; no timings were fabricated or regenerated. Skip policies are unchanged: the new deadline test/helper introduces no driver-dependent skip or helper branch. Original final generated listing/configuration/manifest files were moved into `selection-deadline-census-files`; their hashes are retained in `selection-deadline-summary.json`. The workflow change is a comment only. Hosted 24-shard setting, triggers, checks and cost policy are unchanged. No commit, push or hosted dispatch was performed.
