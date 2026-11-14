# Membership redemption policy lane

Worktree `/workspace/VA-Studio-membership-redemption-policy`, branch `harness/membership-redemption-policy-20261013`, base `670f9caa158e436746ed9aed85667c10b9905f35`. Owns exactly MembershipPolicyDocument.php, MembershipPolicyBinding.php, new MembershipRedemptionTerms.php, new Unit/MembershipRedemptionPolicyTest.php and new Feature/ProductionMembership/MembershipRedemptionPolicyFactsTest.php. No other source or ledger changes. Vendor package directories are symlinks with this worktree's own Composer autoload/bin metadata; synthetic environment copied privately from root. `composer dump-autoload --no-scripts` exited0 with existing PSR-4 fixture/browser warnings. PHP 8.4.26 and PHPUnit 12.5.34; exact version output retained.

The v2 document/API was agreed with root before implementation. V1 canonical document bytes/hash/terms hash and keyed record-seal bytes are pinned to observations from unchanged base source. V2 requires explicit eligibility rules, exact selected identities/hashes/cost, honor duration/cap choice and existing 257 release/expiry destinations. No consume or early/voluntary release authority is introduced. No schema or provider activity is needed to author/read these policy payloads.

All PHP commands first sourced `/workspace/.va-studio-toolchain/activate.sh`.

```sh
php vendor/bin/phpunit tests/Unit/MembershipRedemptionPolicyTest.php --log-junit /workspace/runtime/membership-reservation-policy-evidence/unit-initial.xml
```

Initial unit result:52 tests / 132 assertions,7 failures,0 errors/skips,exit 1. The seven new refusal tests mistakenly checked the minimized public exception message for the private reason; corrected assertions inspect `MembershipException::reason`. Product behavior unchanged for that correction.

```sh
php vendor/bin/pint app/Domain/Memberships/Production/MembershipPolicyDocument.php app/Domain/Memberships/Production/MembershipPolicyBinding.php app/Domain/Memberships/Production/MembershipRedemptionTerms.php tests/Unit/MembershipRedemptionPolicyTest.php tests/Feature/ProductionMembership/MembershipRedemptionPolicyFactsTest.php
```

Pint passed,exit 0. The five-file selection was then run with environment:
`APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync TMPDIR=/tmp/va-studio-membership-redemption-policy-20261013`.

```sh
php vendor/bin/phpunit tests/Unit/MembershipRedemptionPolicyTest.php tests/Feature/ProductionMembership/MembershipRedemptionPolicyFactsTest.php tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php tests/Feature/ProductionMembership/MembershipPolicyClockTest.php tests/Feature/ProductionMembership/MembershipPreparationTest.php --log-junit /workspace/runtime/membership-reservation-policy-evidence/sqlite-policy.xml
```

Initial combined result:78 tests / 245 assertions,1 failure,0 errors/skips,exit 1. New roundtrip test incorrectly required authored associative insertion order after canonical JSON object-key ordering. Fixed the assertion to compare canonical bytes, preserving types and list order. The only application delta after this run was an explanatory releaseDestination comment; no behavior change.

Final check:

```sh
php vendor/bin/pint --test app/Domain/Memberships/Production/MembershipPolicyDocument.php app/Domain/Memberships/Production/MembershipPolicyBinding.php app/Domain/Memberships/Production/MembershipRedemptionTerms.php tests/Unit/MembershipRedemptionPolicyTest.php tests/Feature/ProductionMembership/MembershipRedemptionPolicyFactsTest.php
php vendor/bin/phpunit tests/Unit/MembershipRedemptionPolicyTest.php tests/Feature/ProductionMembership/MembershipRedemptionPolicyFactsTest.php tests/Feature/ProductionMembership/MembershipPolicyFactsTest.php tests/Feature/ProductionMembership/MembershipPolicyClockTest.php tests/Feature/ProductionMembership/MembershipPreparationTest.php --log-junit /workspace/runtime/membership-reservation-policy-evidence/sqlite-policy-final.xml
git diff --check
```

Final Pint 5 files passed,exit 0. Final PHPUnit78 tests / 252 assertions,0 errors/failures/skips,exit 0. Diff checkexit 0. Exact arrays/selected files/environment/source hashes and retained failure artifacts are in the adjacent JSON/log/XML files. All five final source SHA-256 values were checked unchanged after the run. No native MySQL tests, providers, hosted dispatch, runtime activation or independent approval is claimed.

Source committed as `87e0dc42b1a046b4dc122d2bbaff1dd1cabdde5a` after root authorized the lane commit. Every owned source blob in that commit matches the final tested SHA-256. No push was performed.
