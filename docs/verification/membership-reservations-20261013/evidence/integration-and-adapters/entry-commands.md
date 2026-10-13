# Owned reservation entry lane

Worktree `/workspace/VA-Studio-membership-reservation-entry`, base `670f9caa158e436746ed9aed85667c10b9905f35`. Three exact source hashes in `entry-sqlite.sources` and capsule `entry-source/` match commit `3b1f087a43e5dfec6fadf4d95b454d5bebc80054` unchanged. Own Composer metadata/bin/autoload were copied locally with dependency package symlinks, then `composer dump-autoload --no-scripts --quiet`; Reflection resolved the existing application source to this worktree. Local `.env` uses a freshly generated synthetic test key and is ignored, never committed or printed.

Activated toolchain `source /workspace/.va-studio-toolchain/activate.sh`; observed PHP 8.4.26 / PHPUnit 12.5.34. The first exact test invocation was:

```sh
TMPDIR=/tmp/va-studio-membership-reservation-entry APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/ProductionMembershipBilling/MembershipReservationEntryTest.php --log-junit /workspace/runtime/membership-reservation-evidence/entry-sqlite.xml > /workspace/runtime/membership-reservation-evidence/entry-sqlite.txt 2>&1
entry_status=$?
printf '%s\n' "$entry_status" > /workspace/runtime/membership-reservation-evidence/entry-sqlite.exit
cat /workspace/runtime/membership-reservation-evidence/entry-sqlite.txt
exit "$entry_status"
```

Initial result: **6 tests, 0 assertions, 6 setup errors, 0 failures/skips, exit 2**, 10.872 seconds. The selected TMPDIR did not exist, so identity fixture `tempnam` emitted a fallback warning which Laravel promoted to ErrorException. This is a local environment mistake, not a product regression. Full receipts remain retained.

After `mkdir -p /tmp/va-studio-membership-reservation-entry` and `sha256sum -c /workspace/runtime/membership-reservation-evidence/entry-sqlite.sources`, the identical command was repeated with every output basename changed from `entry-sqlite` to `entry-sqlite-v2`. No PHP source changed. Actual result: **6 tests, 135 assertions, 0 failures/errors/skips, exit 0**, 17.902 seconds. A second hash check after execution is retained as `entry-sqlite-v2-source-check.txt`.

The cases test all three explicit operations against every missing member of the four-capability gate, a preexisting primary transaction, a preexisting secondary transaction, a secondary transaction opened by the third capability-resolution callback, capability removal during capture and transient re-registration around the capture baseline. These are admission-only refusals using existing award fixtures; they claim no v2 eligible reservation or lifecycle success.

Final exact formatting command:

```sh
vendor/bin/pint --test app/Domain/Memberships/Production/MembershipReservationOperations.php app/Domain/Memberships/Production/MembershipReservationObserver.php tests/Feature/ProductionMembershipBilling/MembershipReservationEntryTest.php > /workspace/runtime/membership-reservation-evidence/entry-pint.txt 2>&1
entry_pint_status=$?
printf '%s\n' "$entry_pint_status" > /workspace/runtime/membership-reservation-evidence/entry-pint.exit
```

Pint passed, exit 0. Both new product files earlier passed `php -l`, and the three-file staged `git diff --cached --check` passed. The new observer is byte-for-byte the prior award observer after only substituting its observer/context type names. There were no MySQL commands, hosted dispatches or pushes. Root owns direct/reentrant context and commit/receipt integration tests; those remain untested in this lane until composition.
