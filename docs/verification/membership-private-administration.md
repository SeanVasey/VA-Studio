# Private membership preparation administration

This WP-11 child reconciles the preserved credit foundation
`e488f7adc0a44a43122283b550e06dec89cf8bb5` and the uncommitted private admin
preparation mounted on `47bdbb546c0a409d97e2d46f2aca42acf13024bc` onto integration
base `0f89340`. The original domain, models, restrictive migration, tests and
worker bytes remain unchanged. Its new source owns only
`MembershipAdministration`, the two membership resources/pages, three admin
views, two dedicated tests and this record. Shared registrations, migration
fixture ordering, root status and workflows belong to the integrator.

## Implemented preparation surface

Filament discovers `/admin/private-membership-plans` and
`/admin/private-membership-credits` through the existing resource registration.
Both require the current catalog operator, verified email and current enrolled
MFA. The admin service requires MFA even when the local panel makes enrollment
optional; it clones panel configuration instead of changing the shared panel.
All reads and authoring remain local/testing with explicit boolean
`memberships.test_mode_enabled=true`. Credit reads also require existing test
customer access. Production cannot be enabled by either flag.

An operator may create an explicit private synthetic plan, inspect immutable
version history, and revise a plan through a captured comparison. The domain
remains the policy validator and owns every creation/revision transaction.
The page protects its review, entered policy and action/table context with
locked Livewire properties. Apply uses only the captured domain review; it
does not capture a replacement baseline. Changed table/form/action context,
pagination, cancellation, actor substitution or stale evidence consumes the
comparison before framework validation or visibility can return early.
Direct callbacks cannot bypass the mounted lifecycle. Canonical no-ops append
neither a version nor an audit. Existing buckets retain their original policy.

Input survives validation, stale review and uncertain failures. A consumed
input mount can retain subsequent corrections without authorizing another
review; closing/reopening is required. A consumed comparison keeps a copyable
read-only policy and disables Apply. Back to policy retains the authored fields
and obtains a fresh explicit comparison. A deliberately simulated lost response
after a real committed revision cannot replay that comparison; reopening
inspects the durable new version and a fresh identical review is a no-op.
Error notifications make no success claim and logs include only exception class.

The credit list begins empty and requires an explicit eligible test-account
scope. Options disclose IDs and labels only. The read-only modal binds its
actual mounted action and record to that selected account, so an unmounted
callback or changed filter cannot delegate another inspection. No staff session
or authentication identity changes. The service delegates only the unchanged
ledger's read method to a freshly verified internal customer principal.
It proves the complete retained user/account/plan/version/bucket/event/audit
baseline before and after that standalone read through the captured primary
PDO. A staff withdrawal inside the delegated read or a deadline crossing is
refused before returning an admin projection. The minimized output contains
only account/bucket IDs, unit, captured balance/spendability, expiry/time and
event IDs, kinds, amounts and times; credentials, owner keys, source/resource
hashes, email and audit contexts are absent. Reading expired credits does not
write an expiry event or claim an active paid membership.

## Focused verification and actual repairs

Initial admin-domain feedback executed 19 SQLite cases: 14 passed, three
test-listener selectors failed to match a quoted SQL alias, and two fixture
mutations violated the existing account access-version guard. Those fixture
errors were corrected without changing retained-data or authorization rules.
Initial mounted feedback executed 21 cases: 16 passed, four assertions failed
and one test-adapter dependency errored. The failures distinguished ordinary
Filament partial/full-render and action-context assertion differences from an
actual recovery flaw: rejecting the consumed input context before retaining
the corrected form lost those entered corrections on reopening. Retention now
precedes that refusal, while the review/write fence remains intact.

The corrected intermediate 40-case admin selection passed 461 assertions.
Adding explicit mounted-account scope, operator withdrawal during delegated
read, malformed scopes and actual HTTP MFA boundaries produced 44 cases.
An initial strict array-order check incorrectly refused Filament's equivalent
action context. Semantic field/count checks preserve the exact supported
context without relying on associative insertion order; the two affected
credit-scope cases then passed 16 assertions. All intermediate failures and
JUnit/logs are preserved under `membership-admin-evidence/` outside Git.

The frozen affected command is:

```sh
php vendor/bin/phpunit --colors=never \
  --filter '(MembershipAdministration|MembershipPrimaryProofTest|MembershipGrantClockBoundaryTest|MembershipPlanTest::test_private_plan_versions|MembershipCreditLedgerTest::test_full_synthetic_renewal|MembershipCreditMigrationTest::test_partial_existing_schema|MembershipCreditMigrationTest::test_temporary_shadow)' \
  --log-junit=/workspace/scratch/2876616e88c3/membership-admin-evidence/admin-final.sqlite.xml \
  tests/Feature
```

`admin-final-receipt.json` records the exact executed commit/tree, command,
sanitized isolated runtime, source hashes before/after, exit status and parsed
JUnit totals. The disposable 32-byte test key is generated in memory and not
retained. Scoped Pint and PHP syntax cover the seven owned PHP files. The
affected selection checks all 44 new admin cases, every retained terminal
primary-proof regression, the clock boundary, original plan/ledger behavior and
restrictive migration admission/retention. Independent sensitive source review
is required on the frozen child before integration.

No hosted CI, new MySQL/native run, browser/device acceptance or full matrix is
claimed. Existing native evidence remains bound to its original source. The
unchanged native-only selectors are the two methods in
`MembershipCreditConcurrencyTest` and
`MembershipCreditEngineTest::test_an_altered_myisam_session_cannot_replace_membership_innodb_constraints_or_atomicity`
(13 total data sets). Final integrated acceptance and migration fixture ordering
remain the root's responsibility under the manual-only CI policy.

This surface does not award credits, enroll customers, bill or renew invoices,
mutate credit history, authorize music/download rights, activate memberships or
reconcile legacy paid obligations. T30/T31, source obligations and approved
membership/billing/rollover/grandfathering policies remain open dependencies.
The next membership implementation needs those explicit contracts; private
plan preparation is not completed membership commerce.
