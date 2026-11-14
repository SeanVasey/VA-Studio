# Reservation lifecycle fixture lane

Worktree `/workspace/VA-Studio-membership-redemption-policy`. Policy commit 87e0dc42 was followed by root provisional integration 09fd6df9 (merge e1224fdd) and eligibility 0c4bc2c4 (cherry-pick 69eb2739). This lane then added only `tests/Support/MembershipReservationFixtures.php` and `tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php`.

The trait creates real reviewed/published catalog and private media through QuoteFixtures, derives exact non-authority binding values through captured MembershipCatalogEvidence, authors an explicit synthetic v2 policy/real MFA approval/identity/settled billing observation, admits the actual CurrentEligibleLicenses producer and executes first award. The grant capability remains an intentionally unusable test-only double. It has no production binding/activation. A second synthetic invoice has distinct invoice/line/payment/intent/charge/transaction references and an adjacent nonoverlapping paid service period.

Exact static commands (toolchain sourced first):

```sh
source /workspace/.va-studio-toolchain/activate.sh
php vendor/bin/pint tests/Support/MembershipReservationFixtures.php tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php
php -l tests/Support/MembershipReservationFixtures.php
php -l tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php
```

All observed exits 0; records in initial-static-checks.json. These did not execute application behavior.

Exact focused integration command:

```sh
source /workspace/.va-studio-toolchain/activate.sh
APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync TMPDIR=/tmp/va-studio-membership-redemption-policy-20261013 php vendor/bin/phpunit tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php --log-junit /workspace/runtime/membership-reservation-lifecycle-evidence/sqlite-initial.xml
```

Executed through retained run-lifecycle.py wrapper, with complete source hash inventory and exact argv/env in sqlite-initial.source.json. Result: **6 tests / 122 assertions / zero errors, failures or skips / observed exit 0**. No prior functional attempt or failure in this lane. All inventoried source files were verified unchanged after the run. Cases prove reserve+exact retry+award replay, last-credit refusal without extra effects, changed-selection key refusal, changed-paid-period key refusal, honor-before/equality/idempotent release, available expiry preserving reserved credit and beyond-expiry release to expired without resurrection.

Final formatting and patch checks:

```sh
php vendor/bin/pint --test tests/Support/MembershipReservationFixtures.php tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php
git diff --cached --check
```

Both exited 0. Committed only the two test files as `0823230d742a44ecbf22c21a2e5246a29ed7dea3`; all tested source hashes match committed blobs. No push, native MySQL test/race, hosted dispatch, live provider/account interaction, host proof, runtime activation or final independent approval is claimed.
