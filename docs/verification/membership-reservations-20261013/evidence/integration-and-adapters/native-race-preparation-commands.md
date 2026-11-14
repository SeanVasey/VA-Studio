# Native reservation race preparation only

Commit `2e26a6ba8686bc707eb6c5f0e7828d4e99c0b070` adds only the five-case native reservation race file and its independent worker. Prepared in `/workspace/VA-Studio-membership-reservation-entry` on the entry lane. Recorded SHA256s in `native-race-preparation.sources` match both committed files.

Executed commands with activated toolchain:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php -l tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php
php -l tests/Support/membership-reservation-race-worker.php
vendor/bin/pint --test tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php tests/Support/membership-reservation-race-worker.php
git diff --cached --check
```

Both syntax checks reported no errors; Pint reported `{"tool":"pint","result":"passed"}`; staged whitespace check passed. These outputs were returned by execution tools; no standalone lint/Pint logs were saved. No PHPUnit discovery, functional tests, MySQL connection, native schema command or hosted CI was run for these two files. The shared reservation fixture and integration dependencies were absent in this lane at preparation; all five behavioral cases remain **untested** until root integrates and executes them.

Cases cover different request keys and last credit, same key/intent, duplicate honor release, duplicate post-credit-expiry release with no resurrection, and available expiry versus reserve at synthetic wall equality. Every worker enters the public typed operation using real retained invoice/policy and CurrentEligibleLicenses. The unused C4 grant gate is the same explicitly unusable fixture double used by the shared fixture and establishes no grant readiness.

The parent holds BillingAwardFence, waits for worker one on its exact native PRIMARY record, then proves worker two waiting on the owner's T23 users PRIMARY record. This establishes earlier identity/fence serialization; it does not attribute ordering solely to the paid-period lock. Every completed case calls the shared real ledgerSnapshot audit. Worker commands, PIDs, connection IDs, readiness, start release, exact wait barriers, exit codes, stdout/stderr and parsed results are retained per case in `VA_MEMBERSHIP_RESERVATION_RACE_EVIDENCE_DIRECTORY` when set, otherwise `storage/framework/testing/membership-reservation-evidence`. Only temporary barrier files are deleted.

Carbon wall timestamps are explicit synthetic fixture input for equality/expiry boundaries. Monotonic clocks, process concurrency, admission bounds and database waits remain real. Any actual operation_deadline or other refusal will remain an exact failing result, never silently reclassified as success. Root will run an integrated native canary before the broad race selection. No push was performed.
