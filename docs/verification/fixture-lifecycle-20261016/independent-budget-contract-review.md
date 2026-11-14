# F2 native lifecycle fixture budget guidance

DECISION: **ACCEPT A FIXED NATIVE-ONLY FIXTURE-PROCESS BUDGET REPAIR, SUBJECT TO COMPLETED CALIBRATION AND ALL ORIGINAL SEMANTICS**. This is design advice, not implementation approval, execution evidence or a native pass.

Reviewed parent/child at `5ef21c28d82a56197469d1222bf119b93aac9c41` and the bounded admission contract. The current native parent **4/8/E4** fails because each unchanged child90-second process times out; the matching prior-source default-success **1/2/E1** also times out at90.468seconds. That establishes the prior local limit for one matching dataset only. SQLite whole route/lifecycle **12/181** passes. Neither failed native scope supplies completed lifecycle or throughput evidence.

## Proposed bound and invariant scope

A fixed **300-second MySQL child-process allowance** is a reasonable candidate to implement and evaluate, with **SQLite90seconds unchanged**. The child timeout currently supervises an entire PHPUnit process performing two full fresh schemas, bodies and actual cleanup; no requirement in these files makes90seconds a product deadline. Only that test supervision allowance should change. Application/domain/request/transfer/lock/provider deadlines, child cleanup semantics and frozen resources remain byte-identical. Document why MySQL has a separate fixed allowance, keep it bounded and avoid an environment switch that silently raises it or disables enforcement.

Select the allowance from the admitted actual driver (the existing selected connection is already checked against PDO), retaining alias support. The four original parent datasets must remain unchanged. Every invocation must continue running the **same two test methods in one child PHP process**, in the same order, with both full setup/cleanup cycles. Keep all migration-census and previous-row-absence assertions, view/default/alias behavior, transaction/static-state reset, after-cleanup callbacks and both receipts. No split-process replacement, schema reuse, reduced migrations, skipped assertions or new native skip.

Keep the child command's `--fail-on-phpunit-warning`, event log checks, zero failure/skip checks and exact expected exits. Success children must exit0; deliberate first-body-error children must still run the second method, contain exactly that expected error and exit2. A parent pass still means all those checks completed. An outer timeout or exception must remain a failure; preserve child/parent logs, XML and process exits separately without counting child cases as additional suite acceptance.

## Calibration and focused evidence

The old90-second timeouts justify testing a larger fixture allowance; they do **not** establish that300 is sufficient or explain every dataset's duration. On the repaired, isolated MySQL8.4.11 runtime, first run the existing default-success dataset with the candidate fixed bound, capturing the original command, source/loader/runner/environment, child events/XML/receipts, parent result and elapsed wall time. Completion of both schema cycles and all assertions is the useful calibration. Read those artifacts before expanding to the remaining existing cases. If it still times out, retain the failure and inspect where the fixture spends its time before proposing another bound; do not keep increasing it until green.

After successful calibration, complete the **whole existing four-case native file**, including both deliberate-body-error datasets and alias/view variants, under unchanged assertions. Preserve the original failed whole and older one-case timeout controls; a new pass applies only to its tested source/runtime. Re-run the existing SQLite compatibility scope after this driver branch to prove the90-second path and product route-boot/enrollment behavior remain intact. These are focused checks, with no whole-CI dispatch or claim to complete F2/counterpart census or performance acceptance.

## Propagation and outer ceilings

Explicitly retain/propagate the captured testing/database selection and schema-valued local marker (or actual CI marker pathway) to each child. Keep the same owned private database/connection/options through the two lifecycle methods; do not change product mode or admission merely to fit the budget.

The enclosing local command must outlive the bounded work. For four serialized native child invocations, allow **4 × 300 seconds plus a documented finite overhead/cleanup margin** (for example1320seconds); a one-dataset calibration may use300seconds plus60seconds margin. These are runner ceilings, not expected runtime or an acceptance benchmark. Record the actual outer selection and budget. Do not loosen Foundation workflow caps or launch it for calibration. On timeout, retain the original outcome and stop owned child processes using existing cleanup; do not report partial receipts as completion. Keep the test workload serialized against its owned schema and record normal owned-server shutdown.

Full native lifecycle stays failed/open until that whole file completes. Any resulting approval remains separate from exact-head source/evidence review, cheap preflight/current inventory/protections/expected-SHA merge, and all host/provider/activation/release gates.

Read-only review only: no application, DB/network/CI test or shared edit executed.

Immutable bindings:

- `tests/Feature/FinalizationDatabaseLifecycleTest.php`: `83fcde0b2f3ccbbe7b81978ca362281cdb79a69e7ef1347873c4484ec45cc522`
- `tests/Support/FinalizationLifecycleFixture.php`: `7ef29a58fae5d0ce0a81b5c381d9261824b773432bd6051de93b120d91f852f6`
- Bounded contract: `79e53b9934d5851dfe9fe2820c2c8458613b023e2a4233d4525a7026e2baef9c`
