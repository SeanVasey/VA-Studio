# Original admission deadline verification

Working directory: `/workspace/VA-Studio-membership-award`.
Toolchain command: `source /workspace/.va-studio-toolchain/activate.sh`.
Observed runtime: PHP 8.4.26, PHPUnit 12.5.34.
All time advances below are explicit synthetic test offsets. They are not
measurements of host performance or claims of a wall-clock timeout guarantee.
No MySQL commands were run by the owner of this change.

The single original operation admission deadline changes from ten to sixty
seconds, captured before connection callbacks, source resolution, waits, writes,
commit delegates, and receipt verification. Producer deadlines cannot renew it.
Blocking operations are not interrupted merely by this deadline; admission is
refused when a subsequent deadline check sees elapsed time. The one original
deadline continues into the committed read-only receipt.

Test-only `Tests\\Support\\MembershipAwardMonotonicClock` supplies a namespaced
`hrtime` function for `App\\Domain\\Memberships\\Production`. The test file loads
it at file scope before PHPUnit starts executing tests, and resets the offset in
setup/teardown. Other namespaces retain real monotonic time and are more
lenient; membership's own terminal checks must detect the synthetic expiry.
There is no production clock registration or hook.

Both commands used this exact environment/executable prefix:

```bash
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --
```

Red selection/output suffix:

```bash
tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php --filter test_twenty_seconds_before_proof_mint_remains_inside_the_original_admission_deadline --log-junit /workspace/runtime/membership-award-evidence/admission-budget-red.xml > /workspace/runtime/membership-award-evidence/admission-budget-red.txt 2>&1
```

Immediately afterwards, `admission_red_status=$?` captured the real shell status,
then `printf '%s\n' "$admission_red_status" > /workspace/runtime/membership-award-evidence/admission-budget-red.exit`
retained it. The log was printed and the shell returned that captured status.

Red application source: `36fc22d0d94585ba13724e14093ceabc725c762a`, unchanged
ten-second deadline; new test and shim were uncommitted. The `.head` and `.sources`
record this source, and `admission-budget-red-source/` contains the exact three
files, verified against the pre-run SHA256s before changing application source.
Result: 1 test, 6 assertions, 1 intended failure, zero errors/skips, exit 1,
elapsed 00:03.481. The positive twenty-second scenario refused with
`operation_deadline`, demonstrating the unpublished ten-second admission bound.

The two expiry scenarios are new preservation checks, not claimed regressions
against the old ten-second source, which can refuse sooner.

Green selection/output suffix:

```bash
tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php --log-junit /workspace/runtime/membership-award-evidence/admission-budget-green.xml > /workspace/runtime/membership-award-evidence/admission-budget-green.txt 2>&1
```

Immediately afterwards, `admission_green_status=$?` captures the real shell
status and writes it with the same printf command to `admission-budget-green.exit`;
the shell prints the log and returns that captured status. Source hashes are in
`.sources`, with the unchanged selected test hashes in `.existing-tests.sources`;
the `.head` records the base. Exact files are in `admission-budget-green-source/`.

The green selection includes three deadline cases, six ownership cases and nine
existing first-award cases. The deadline cases check: admission after twenty
synthetic seconds; refusal at forty plus twenty-one seconds even after explicitly
proving the newer invoice lease still valid, with both writes rolled back; and
a sixty-one-second advance after commit, yielding a refused receipt with one
durable period and event. Consult the completed log/JUnit/exit for its outcome.

The first `admission-budget-green` run completed with 18 tests, 176 assertions,
zero assertion failures/skips, one teardown error, observed exit 2, elapsed
01:29.074. The synthetic-time assertions completed, but the diagnostic invoice
wrapper retained its `MembershipRows`/SQLite pin and fixture teardown's VACUUM
refused with `SQL statements in progress`. This failed run and its original
source archive are retained. The correction changes only the new test: a finally
block clears its captured proof and Rows. Application and clock shim bytes did
not change.

The retry uses the identical green command/selection above with every output
basename changed from `admission-budget-green` to `admission-budget-green-v2`.
It captures the actual shell status in `admission_green_status` and writes that
to `admission-budget-green-v2.exit`. Its `.sources` includes all five selected
application/test/helper files, and its source archive preserves those bytes.

Completed retry result: **18 tests, 176 assertions, zero failures/errors/skips,
observed exit 0**, elapsed 01:28.899. All five `.sources` entries matched in a
post-run `sha256sum -c` check. The three owned PHP files passed syntax checks and
Pint `--test` again after completion.

Formatting was checked before the green run:

```bash
vendor/bin/pint --test app/Domain/Memberships/Production/MembershipAwards.php tests/Feature/ProductionMembershipBilling/MembershipAwardDeadlineTest.php tests/Support/MembershipAwardMonotonicClock.php
```

It reported `{"tool":"pint","result":"passed"}`.
