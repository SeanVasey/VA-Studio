# C3 shared fixture adaptation

Worktree `/workspace/VA-Studio-billing-retrieval-fixtures`, initial base
`47125fbf8ed99f1f1bcefaaf8c6144e555819a00`. Synthetic fixtures only.
No database test, provider request, host verification, or native workload ran in
this lane before integration. Product issuer/schema dependencies were pending.

## Scope

- Membership observations finish a same-ledger issued retrieval interval before
  snapshot settlement and use its native end timestamp. Synthetic membership
  business wall times remain separate.
- The adjacent future synthetic service period is explicitly advance-paid;
  original elapsed provider payment/created/available timestamps and all pure
  settlement constants remain unchanged.
- The raw historical interval helper returns migration options omitting only
  exact `2026_10_13_263000_membership_billing_retrieval_identity.php`. Root owns the
  three explicit legacy test-class overrides; no historical arbitrary endpoint
  graph is relabeled as modern263 arbitration proof.
- Webhook redelivery's direct diagnostic observation uses the typed interval.
- Shared interval process output adds actual snapshot counts before the barrier,
  an optional post-refusal snapshot only while a SQLSTATE45000 transaction remains
  active, and physical transaction state after cleanup. Existing ready format and
  15-second lock/30-second barrier caps stay unchanged. This supports the separate
  modern263 native race test; no native behavior pass is claimed from static checks.

## Environment setup

The isolated worktree copied root's dependency directory with `cp -a` into its own
vendor directory; its Composer metadata is independent. Exact commands:

```sh
cp -a /workspace/VA-Studio-membership-reservations/vendor ./vendor
mkdir -p /workspace/runtime/billing-retrieval-fixture-evidence storage/framework/cache storage/framework/sessions storage/framework/views
source /workspace/.va-studio-toolchain/activate.sh
composer dump-autoload --no-scripts > /workspace/runtime/billing-retrieval-fixture-evidence/autoload.log 2>&1
php -r 'require "vendor/autoload.php"; echo (new ReflectionClass(Tests\Support\MembershipAwardFixtures::class))->getFileName(), "\n";'
```

Reflection resolved the helper to this worktree. No shared `.env` was copied.

## Static checks

`bash /workspace/runtime/billing-retrieval-fixture-evidence/static.sh` checked the
initial four changed PHP files: syntax0, Pint0, diff-check0. Before/after SHA256s
matched. The script, patch, hashes, and logs are retained.

`bash /workspace/runtime/billing-retrieval-fixture-evidence/static-v2.sh` checked
all five changed PHP files including the additive process transcript fields:
syntax0, Pint0, diff-check0; all five before/after hashes matched. The complete
script, patch, hashes, and logs are retained. Neither check executed PHPUnit;
there are no test counts or skips to report yet.

## Integrated four-file SQLite selection

Merged root integration `bea13e9cf4f6d50fe4c51023186afd6e846ccefc` into the clean
fixture worktree as `a063e38067193562593a6e560db01c9daf4cf8c6`. The entire tracked
Git tree was identical (`git diff --quiet bea13e9cf4f6d50fe4c51023186afd6e846ccefc HEAD`, exit0).
Rebuilt only the isolated worktree's Composer metadata with `composer dump-autoload --no-scripts`.

Exact retained invocation, after toolchain activation:

```sh
python3 /workspace/runtime/billing-retrieval-fixture-evidence/run-fixture-sqlite.py sqlite-fixtures-initial tests/Feature/ProductionMembershipBilling/BillingWebhookRedeliveryTest.php tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php tests/Feature/ProductionMembershipBilling/MembershipReservationOwnershipTest.php tests/Feature/ProductionMembershipBilling/MembershipEligibleLicenseProofTest.php
```

The retained script records actual PHP argv/environment, uses its own `/tmp/va-c3fix`
TMPDIR, SQLite `:memory:`, `--stop-on-error`, and `--stop-on-failure`.
Completed exit0 in346.143 wall seconds: **47 tests /429 assertions, zero skips,
errors, or failures**. Actual leaves: redelivery20/96, lifecycle6/122,
reservation ownership5/88, eligibility16/123. No defect/retry occurred.
All1,956 captured source hashes stayed unchanged; a separate post-run verification
read all1,956 committed Git blobs at a063e380 and found zero mismatches. The47leaf
identities are distinct and their assertions sum429. See
`sqlite-fixtures-initial.{source.json,log,xml,exit,result.json,verification.json}`.
No native execution or new native claim in this lane.

## Historical schema fixture correction, current263 coverage

After the frozen four-file run completed, root assigned and approved a test-only
split in BillingSchemaPreparationTest and BillingAwardFenceTest. These historical
raw rows now explicitly exclude only the263 migration in setup; no current graph
is silently treated as legacy and no future unrelated migration is filtered.
The legacy six-table immutability case uses an explicitly structural raw attempt;
it does not invoke the current producer on a pre-upgrade graph.
A separate case runs the real263 migration, then the modern producer's failed
retrieval, successful settlement and webhook intake. It checks attemptv2,
observationv3, hintv2, current settled admission, real allocation phases and
positive row counts in all six tables before update/delete refusal checks, then
compares all raw retained rows unchanged.
The two exact changed files and diff are retained under
`schema-preparation-source/` and `schema-preparation.patch`.
No pre-correction execution was performed; the initial fixture incompatibility
was identified from source. A focused two-file run follows, without repeating
unaffected47cases. Its `integration_tree_identical` field describes committedHEAD
versus the integration; its explicit nonempty `worktree_status` and hashes/capsule
identify the uncommitted two-test-file overlay actually executed.

```sh
python3 /workspace/runtime/billing-retrieval-fixture-evidence/run-fixture-sqlite.py sqlite-schema-preparation tests/Feature/ProductionMembershipBilling/BillingSchemaPreparationTest.php tests/Feature/ProductionMembershipBilling/BillingAwardFenceTest.php
```

The two-file focused run completed exit0 in39.476 wall seconds: **21 tests /142
assertions, zero skips/errors/failures**. All1,956 source hashes stayed unchanged.
Exact test-only overlay committed as `822408d2a840a1de2154f5adcd63671f2b839e34`;
all1,956 captured run hashes were then independently compared to that commit's
Git blobs and matched. Actual21leaf identities are distinct and sum142assertions.
`sqlite-schema-preparation.verification.json` records the two-class breakdown and
explicit precommit→committed source binding. `schema-static.sh` checked both changed
PHP files after the test: syntax0, Pint0, diff-check0; before/after hashes matched.
All receipts/source capsules for this completed run are released. No native run
or full-suite acceptance is implied; no untested condition is described as passed.
The only new method is
`BillingSchemaPreparationTest::test_current_database_interval_producers_retain_immutable_versioned_rows_in_every_billing_table`.
No method names/skips were changed in BillingAwardFenceTest.

## Hint cursor pagination fixture correction

Root reported its initial integrated cursor failure in a separate selection at
`8631279671e65396255b5876036daa9abbb2e130`: that invocation stopped at54tests/353
assertions with one failure and zero errors/skips. The fourth cursor case expected
specific timestamp-ordered identities within an overall bound, but actual native
receipt timestamps shared one second and UUID tie order selected different rows.
Those original nonzero receipts remain in root's evidence lane, not overwritten
here. Earlier passing leaves in that invocation do not make its exit green.

Only `BillingSweepHintsCursorTest.php` changes in this correction. Its eight
original method names remain. A local pagination-offset value replaces Carbon
clock changes. Explicit authenticated synthetic pagination rows have controlled
sort timestamps and real `BillingLedger::hintPosition()` allocations inside the
insert transaction. The hints' exact v2 row binding and seal are constructed at
insertion; there are no retained-row rewrites, sleeps, native clock changes or
fake allocation identities. Real reconciliation still covers preceding hints by
allocation order. The forgery case proves a valid v2 envelope first, then changes
only the seal before its initial insertion. The class/header/helper comments
explicitly separate pagination coverage from native intake-clock and lost-dispatch
proofs, which remain in the dedicated Intake/Redelivery feature files.

Exact completed static/test commands (after toolchain activation):

```sh
php -l tests/Feature/ProductionMembershipBilling/BillingSweepHintsCursorTest.php > /workspace/runtime/billing-retrieval-fixture-evidence/cursor-syntax.log 2>&1
vendor/bin/pint --test tests/Feature/ProductionMembershipBilling/BillingSweepHintsCursorTest.php > /workspace/runtime/billing-retrieval-fixture-evidence/cursor-pint.log 2>&1
git diff --check > /workspace/runtime/billing-retrieval-fixture-evidence/cursor-diff-check.log 2>&1
python3 /workspace/runtime/billing-retrieval-fixture-evidence/run-fixture-sqlite.py sqlite-cursor tests/Feature/ProductionMembershipBilling/BillingSweepHintsCursorTest.php
```

The retained outer tool call used `set -e`; each static command succeeded and its
recorded exit file contains0. The whole cursor file completed **8tests/123assertions,
zero errors/failures/skips, exit0**,29.528 wall seconds. No retry occurred in this
lane. All1,956 captured source hashes stayed unchanged. The exact one-test-file
overlay was committed as `a4c68a506ef6ef8a73533d342ca9d8c8c15a064a`; all1,956 run hashes
were independently compared with its committed Git blobs and matched. Eight leaf
identities are distinct and sum123assertions. Capsules `cursor-source/`, `cursor.patch`,
`cursor-{syntax,pint,diff-check}.{log,exit}`, and `sqlite-cursor.*` are released.
No native execution is claimed. `integration_tree_identical` is false for this run
because HEAD already contains the preceding two schema-test corrections; the
actual commit/overlay and full captured source inventory identify this test source.
