# C2 retained ledger record and audit lane

Worktree `/workspace/VA-Studio-membership-ledger`, branch `harness/membership-ledger-20261013`, base `670f9caa158e436746ed9aed85667c10b9905f35`. Private Composer autoload/composer/bin metadata resolves App/Tests into this worktree; dependency package directories are symlinked. The private synthetic environment was copied without printing it. No native schema/session was used.

Dependencies cherry-picked before final tests: license binding `a1a492c2a40e2301147573d4d45fd9ab9840d01c` (local `46835a84`); policy v2 `87e0dc42b1a046b4dc122d2bbaff1dd1cabdde5a` (local `1e485b09`). These are separate owner commits. Integration should cherry-pick only ledger commit **9c38a4cb932307de27b795d802e08ce0dac6896d** after those dependencies.

Owned changes: `MembershipPeriodRecords.php`, new `MembershipLedgerAudit.php`, new `MembershipReservationRecords.php`, and `tests/Unit/MembershipLedgerAuditTest.php`. No operation entry, schema, activation, deadlines, or frozen resource definitions changed. The original `MembershipPeriodRecords::create` body is byte-identical to base, proven in `v1-create-byte-preservation.json`. Old record purpose/table seals and original award projection are retained; `verify` now delegates to complete audit with an optional final redemption-row list.

## Exact verification

Toolchain:

```sh
source /workspace/.va-studio-toolchain/activate.sh
```

PHP **8.4.26**. Initial pure record suite:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- tests/Unit/MembershipLedgerAuditTest.php --log-junit /workspace/runtime/membership-ledger-evidence/ledger-initial.xml > /workspace/runtime/membership-ledger-evidence/ledger-initial.txt 2>&1
```

Result **45 tests / 79 assertions**, zero errors/failures/skips, exit **0**, **1.023 seconds**. These pure authenticated record fixtures deliberately confer no live invoice, current catalog, reservation-operation, or provider authority.

Final exact command/environment is retained in `ledger-focused.sh`, invoked with:

```sh
bash /workspace/runtime/membership-ledger-evidence/ledger-focused.sh
```

It selects the complete new `tests/Unit/MembershipLedgerAuditTest.php` and complete existing `tests/Feature/ProductionMembershipBilling/MembershipFirstAwardTest.php`. Result **54 tests / 164 assertions**, zero errors/failures/skips, exit **0**, **48.939 seconds**. All nine bound source/dependency/test hashes matched before/after; all four committed owned blobs match that tested source (`committed-source-check.json`). The first-award SQLite file uses its actual retained producer/fixture setup; the new ledger cases remain pure records, not operative reservation proofs.

```sh
vendor/bin/pint --test app/Domain/Memberships/Production/MembershipPeriodRecords.php app/Domain/Memberships/Production/MembershipReservationRecords.php app/Domain/Memberships/Production/MembershipLedgerAudit.php tests/Unit/MembershipLedgerAuditTest.php
git diff --check
```

Both passed. Syntax checks for all three product files passed before the test run.

## Covered behavior and limits

Audits verify exact column sets and keyed seals, the versioned full redemption intent and original owner, derived authored rule/cost/honor, every sequence/prior seal/time, balance conservation, reserve/redemption joins, orphan and duplicate rows, and terminal uniqueness. Unsupported consume always refuses. Record tests include all redemption column mutations, resealed semantic and event-chain corruption, request-key conflict across periods, old projection/seal purpose, previous record keys, insufficient credit, legacy license values, finite/no-expiry boundaries and no credit resurrection.

Reservation request scope binds original buyer origin plus fixed purpose plus SHA-256 digest of a 1..128 printable-ASCII request key. Selected period remains in the authenticated full intent; original creation/honor cannot be renewed by retry. Raw keys are not retained. Release builders admit only `honor_expired` at/after retained honor; policy destination values do not imply voluntary cancellation permission.

Prospective reserve admission keeps one terminal slot for every live reservation and, conservatively, one expiry slot for every finite period even when no availability remains. Near-MAX_EVENTS tests use explicitly labeled pure prospective state counters, not a claimed retained 9,997-event database chain. Full audit refuses event counts beyond its bound.

Still untested here: owned reservation/release/expiry integration, callback/committed receipts, real current eligible-license composition, database insertion of new records, native MySQL lifecycle/concurrency, C4 consume/activation and all live/host/provider conditions. Root owns those integration checks and the independent final review. No native or hosted CI was dispatched by this lane.
