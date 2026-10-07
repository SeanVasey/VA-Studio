# Service scope journey focused verification — October 7, 2026

Prepared against `5b58e2e` in isolated branch
`codex/service-project-journey-20261007`. The source contains only new service
project files, its additive migration, buyer/operator UI, focused fixtures/tests
and this evidence. Shared root route/privacy/navigation integration and independent
review apply to the eventual exact composed source, separately.

Commands, using the retained rootless PHP toolchain:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php vendor/bin/phpunit tests/Unit/ServiceProjectInputTest.php \
  tests/Unit/ServiceProjectJsonTest.php tests/Feature/ServiceProjectJourneyTest.php \
  tests/Feature/ServiceProjectHttpAndOperatorTest.php \
  tests/Feature/ServiceProjectSchemaTest.php
npm test -- --maxWorkers=2 tests/frontend/service-project-journey.test.tsx
npm run build
```

The HTTP tests explicitly register the dedicated route file and privacy middleware
until shared integration. They submit through the real customer sign-in and HTTP
controller, mount actual Filament quote/milestone forms, and join operator
progress to buyer quote acceptance and milestone approval. React tests mount the
actual buyer component and inspect its submitted bodies, retry key/body retention,
revision limit and private-data scrubbing. These do not substitute for rendered
browser/device acceptance or final integrated app boot.

The native selection additionally runs:

```sh
source /workspace/.va-studio-toolchain/mysql/service_projects-task.env
php vendor/bin/phpunit tests/Unit/ServiceProjectInputTest.php \
  tests/Unit/ServiceProjectJsonTest.php tests/Feature/ServiceProjectJourneyTest.php \
  tests/Feature/ServiceProjectHttpAndOperatorTest.php \
  tests/Feature/ServiceProjectSchemaTest.php tests/Feature/ServiceProjectConcurrencyTest.php
```

The native MySQL environment is the existing `/workspace` cloud workspace,
loopback-only disposable MySQL `8.0.46-0ubuntu0.24.04.4` (Ubuntu), authenticated
through signed Ubuntu packages via Debian-signed archive keyring. Its separate
service fixture database/account cannot mutate other agents' schemas. Local
private environment files are not committed or printed. MySQL clients/child
processes require command network additional permissions even for loopback.

The native case starts two independent PHP workers with distinct operators,
observes the exact project RECORD lock wait with requesting/blocking connection,
database/table/index and record identity, and proves that the losing operator's
pre-fence repeatable-read snapshot cannot overwrite the newly committed quote.
SQLite skips that case explicitly and is not presented as concurrency evidence.

The schema cases execute SQL update/delete/IGNORE/REPLACE refusal, actual migration
rollback with preserved ledger, and interrupted DDL through the actual migrator.
The original handoff deliberately refused partial installation retry. Independent
review found that behavior to be a development blocker; the correction below
supersedes that behavior. Earlier receipts remain evidence of their original
source, rather than acceptance of recovery. No assertion, dependency or hosted CI
policy was weakened. No workflow dispatch, push, merge, new environment, real
message, payment, supplier action or production configuration occurred.

Executed receipt files beside this record bind the focused results. Initial
implementation runs exposed fixture table naming, the existing account withdrawal
version rule, Filament's numeric UI dehydration, private GET test-body negotiation,
form error paths, generic exception rendering and a malformed synthetic JSON
fixture; these were corrected before recording the passing candidate. No passing
claim is made for those earlier failed attempts.

## Recorded source and outcomes

Implementation commit: `e561ce821feaa9d85c4a07b4bf6c882f8d989377`.
Native test-only comparator correction: `ea659b714e789a274f0c97542ee75522ff7707e0`.
The correction compares fresh trigger records by their exact scalar names; the
production implementation is unchanged. Final SQLite/frontend and focused native
schema receipts apply to that corrected source. This evidence commit changes
only receipts and documentation.

- Final SQLite: **35 cases, 144 assertions, no skips**.
- Mounted React buyer journey: **4 cases, all passed**.
- Scoped Pint, TypeScript and Vite production build: **passed**. The build retains
  its bundle-size advisory; no full performance acceptance is claimed.
- Initial native MySQL selection: **35 of 36 passed, 214 assertions**. All domain,
  mounted HTTP/operator and exact independent-worker/MVCC contention cases passed.
  The one failure compared two distinct `stdClass` instances despite identical
  retained trigger names; the failing receipt is preserved.
- Corrected native schema selection on `ea659b7`: **3 cases, 19 assertions, all
  passed**, including actual interrupted-DDL retry refusal/preservation and actual
  rollback bookkeeping. No assertion was removed or condition skipped.

These focused receipts do not claim a fully passing final composed MySQL matrix,
rendered browser/device acceptance, independent approval, full service parity or
production readiness. Root owns shared integration and exact composed review.


## Reviewed recovery and private-input correction

Runtime/test correction: `38105a6d1cd8c5c8f2654fd81e28bff3d6ca2acb`.
Parent's independently reviewed composition: `dccd15e` (correction runtime/test
blobs exact). This evidence commit changes documentation/receipts only.

The installation now recognizes only an exact contiguous owned prefix across
all generated table, foreign-key, index and trigger statements. Full metadata,
namespace/alias, dependency and temporary-shadow checks precede writes; partial
schemas with rows, changed metadata or gaps remain untouched. A retry appends
only the missing suffix. Exact complete retained history can be readmitted after
final-guard/log uncertainty; operational rollback still refuses. Every generated
statement is interrupted through the real Migrator (13 SQLite /18 MySQL),
including MySQL's independently committed foreign-key/index statements.

Read/save authority denial and departure now erase private selection, brief,
answers and reason, preventing a later account refresh from reviving withdrawn
account input. The reviewer retained its original red receipts separately.

- Final SQLite schema/recovery: **10 cases,122 assertions,all passed**.
- Final MySQL-connected selection: **10 cases,156 assertions,all passed** on
  native MySQL `8.0.46-0ubuntu0.24.04.4`. **9 cases use MySQL; one explicit case
  creates a separate in-memory SQLite composite dependency-key probe.** It is not
  presented as a MySQL dependency-key case.
- Mounted frontend: **7 cases,all passed**, including403/404/419 denial followed
  by another account refresh. TypeScript and scoped Pint passed.
- Initial correction MySQL attempt: **7 of8 passed,139 assertions**; the failing
  test used native-invalid `CREATE TEMPORARY TABLE users LIKE users` (1066). It
  was corrected to an explicit synthetic temp schema; raw red receipt is retained.
- Independent exact composed review: SQLite **2 cases/19 assertions** (recovery
  and composite PK), native MySQL **1case/10 assertions** (actual retry with exact
  retained table/guard definitions,old rows and ledger), mounted React **1/1**.
  Reviewer reported no remaining runtime blocker in this bounded correction.

Commands: `php vendor/bin/phpunit tests/Feature/ServiceProjectRecoveryTest.php
 tests/Feature/ServiceProjectSchemaTest.php`; native invocation additionally
sources the private isolated task env and command loopback network permission.
Frontend: `npm test -- tests/frontend/service-project-journey.test.tsx`.
TypeScript: `npm run typecheck`; scoped `vendor/bin/pint --test` on changed PHP.
No full matrix, rendered browser/device, full service parity or production
readiness is claimed. Shared integration and independent receipts belong to root.
