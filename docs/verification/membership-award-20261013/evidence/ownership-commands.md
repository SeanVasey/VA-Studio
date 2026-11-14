# Owned award entry regression commands

Working directory: `/workspace/VA-Studio-membership-award`.
Toolchain: `source /workspace/.va-studio-toolchain/activate.sh` (PHP 8.4.26).
All runs below use SQLite, synthetic retained fixtures, and no native database.

Each run uses this exact environment and executable prefix:

```bash
TMPDIR=/tmp/va-studio-membership-policy-20261013 APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' --
```

The selection/JUnit/output suffixes were:

```bash
tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php --log-junit /workspace/runtime/membership-award-evidence/ownership-entry-red.xml > /workspace/runtime/membership-award-evidence/ownership-entry-red.txt 2>&1
tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php --filter test_capability_resolution_cannot_remove_a_remaining_capability_before_capture --log-junit /workspace/runtime/membership-award-evidence/ownership-gate-red.xml > /workspace/runtime/membership-award-evidence/ownership-gate-red.txt 2>&1
tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php --filter test_transient_capability_rebinding_cannot_make_a_missing_capture_baseline_authoritative --log-junit /workspace/runtime/membership-award-evidence/ownership-transient-red.xml > /workspace/runtime/membership-award-evidence/ownership-transient-red.txt 2>&1
tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php --log-junit /workspace/runtime/membership-award-evidence/ownership-entry-green.xml > /workspace/runtime/membership-award-evidence/ownership-entry-green.txt 2>&1
```

Immediately after each PHP command, its shell exit status was captured with
`ownership_status=$?` (the later commands named this variable
`ownership_gate_status`, `ownership_transient_status`, and
`ownership_green_status`, respectively), then written using
`printf '%s\n' "$ownership_status" > <run>.exit`. The captured status was also
returned by the shell after printing the log. These are observed exit statuses,
not inferred from JUnit.

`ownership-entry-red` ran the initial two tests against unchanged application
commit `a1b91e139b1814413ff09196a2df44798bc1440e`; its `.head` records that commit.
The exact test file hash and both entry application file hashes are retained in
its `.sources`. It failed both assertions for the intended reason: direct
construction committed one period and one event without all four capabilities,
and the invoice callback could construct a second context on the owned rows.
Result: 2 tests, 10 assertions, 2 failures, zero errors/skips, exit 1.

`ownership-gate-red` used the same base with the entry permit correction already
present, but before gate-order correction. Its `.sources` identifies that
uncommitted intermediate source. The capability resolver removed the remaining
grant registration before configuration capture; the old order still committed
one period and event. Result: 1 test, 8 assertions, 1 failure, zero errors/skips,
exit 1.

`ownership-transient-red` used the entry permit plus an intentionally incomplete
second-`current()` correction, before moving registration capture ahead of
resolution. Its `.sources` identifies this uncommitted intermediate source.
Transient add/remove callbacks made both full-gate calls pass while the captured
registration baseline omitted the grant capability, and a period/event was
committed. Result: 1 test, 9 assertions, 1 failure, zero errors/skips, exit 1.
Neither incomplete gate-order intermediate was committed.

The green selection runs all six final ownership tests plus all nine existing
first-award tests. Its `.sources` records the application/test hashes before the
run; consult the completed `.txt`, `.xml`, and `.exit` for the observed outcome.
No MySQL acceptance or performance claim follows from these SQLite results.
The original ten-second operation deadline was not changed.

Completed green result: 15 tests, 141 assertions, zero failures/errors/skips,
observed exit 0, elapsed 01:15.973. All four recorded source hashes were checked
again after completion with `sha256sum -c ownership-entry-green.sources` and
matched. All four files also passed `php -l` and the following Pint command
with `--test` after the run.

Exact red source files are retained under each `<run>-source/` directory. The
earlier test/intermediate files were reconstructed after the final test was
expanded, then SHA256 checked against the receipts captured before their runs;
every archived file matches its original recorded hash. Original application
files for the first red remain independently recoverable from commit
`a1b91e139b1814413ff09196a2df44798bc1440e`.

Formatting command:

```bash
vendor/bin/pint app/Domain/Memberships/Production/MembershipAwards.php app/Domain/Memberships/Production/MembershipAwardContext.php app/Domain/Memberships/Production/MembershipAwardConfiguration.php tests/Feature/ProductionMembershipBilling/MembershipAwardOwnershipTest.php
```

It reported `{"tool":"pint","result":"passed"}` before the green selection.
