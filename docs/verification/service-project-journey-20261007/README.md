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
Partial installation remains unlogged and cannot be adopted on retry; existing
service revision bytes remain unchanged. No assertion, dependency or hosted CI
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
