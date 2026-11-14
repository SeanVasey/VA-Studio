# Pre-C3 baseline: retained integer allocations and worker clock regressions

This is runtime-only regression evidence against **45d1299406821627f4ba02af186e324a70c6ade0**, in isolated detached worktree `/workspace/VA-Studio-billing-c3-baseline`. No tracked repository files changed. The probe uses the baseline's real `BillingLedger`, `BillingReconciliation`, `BillingSettlement`, `BillingStripeFixtures`, and `FinalizationDatabaseMigrations`; the gateway is the existing in-process synthetic rehearsal fixture. No network provider call, MySQL workload, hosted CI, actual restore, or actual failover was performed.

Exact invocation:

```sh
bash /workspace/runtime/billing-retrieval-records-evidence/baseline/run.sh
```

`run.sh` retains the complete environment setup and command. Its effective PHPUnit invocation, from the baseline worktree, is:

```sh
source /workspace/.va-studio-toolchain/activate.sh
export APP_BASE_PATH=/workspace/VA-Studio-billing-c3-baseline
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
export XDEBUG_MODE=off
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --colors=never --log-junit /workspace/runtime/billing-retrieval-records-evidence/baseline/phpunit.xml /workspace/runtime/billing-retrieval-records-evidence/baseline/BillingPreC3RegressionTest.php
```

Observed result: **4 tests, 53 assertions, 4 intended assertion failures, 0 errors, 0 skips, exit 1**. XML test time is **7.624981 seconds**; the runtime reporter rounds duration to **7.626 seconds**. PHP **8.4.26**, SQLite **3.46.1**. This is deliberately red evidence, not a passing test suite. Every failure is the final desired-behavior assertion after successful setup and explicit baseline-behavior assertions; none is a missing class, changed signature, migration error, or autoload error.

| Probe | Baseline result established before desired assertion | Desired fixed behavior / correspondence |
| --- | --- | --- |
| `test_replaced_start_allocation_must_not_admit_the_retained_old_integer` | Old start ID **1** was deleted and allocated again as ID **1**, with a different diagnostic `created_at`. Using the retained original integer for `endRetrieval()` and `append()` admitted **1 settled observation**. | The fixed start record must identify the original allocation, so end/append reject it after replacement. |
| `test_replaced_end_allocation_must_not_admit_the_retained_old_integer` | Old end ID **2** was deleted and allocated again as ID **2**, with a different diagnostic `created_at`. Appending with the retained original integer admitted **1 settled observation**. | The fixed end record must identify the original allocation, so append rejects it after replacement. |
| `test_a_later_reversal_must_not_be_rejected_because_the_previous_worker_clock_was_ahead` | A worker clock **7,200 seconds ahead** stored a settled tail. A subsequent nonoverlapping retrieval at the actual database sample time completed another start/end pair but refused its reversed graph with **`clock`**, leaving only `settled`. | Database-issued observation chronology permits the later reversed graph despite Carbon worker skew. Provider `paid_at` remains unchanged. |
| `test_worker_clock_ahead_must_not_extend_freshness_beyond_the_original_database_window` | Database bracket **05:43:21 UTC**; ahead worker `retrieved_at` **07:43:21** and stored deadline **07:53:21**, two hours above database-after +600 seconds. The original `currentSettled(invoice, now)` also returned the tail for synthetic caller time **06:03:21** (+1,200 seconds). | The immutable stored deadline must be no later than database read-end/after +600 seconds. This stored-value assertion corresponds directly to fixed issuance without depending on the removed caller-time signature. |

All timestamps above are **2026-10-13**. Full scalar diagnostics are `cp2-start.json`, `cp2-end.json`, `cp3-reversal.json`, and `cp3-freshness.json`.

The allocation simulations replace only the newest unreferenced position row: temporarily remove its delete guard, delete that one row, reset that table's SQLite allocation counter to the preceding ID, restore the exact captured delete-trigger SQL, verify the original schema with `BillingSchema::assertOwned()`, and only then call the original public allocation API. The replacement has a different `created_at` to demonstrate it is a different allocation, while the invoice graph is unchanged. This is **simulated allocation loss**, not evidence about a host's backup, replication, failover, or restore process. All owned guards are restored before the API under test.

The clock probes sample real SQLite database UTC and alter only Carbon's worker test clock. The fourth probe's caller-now value is a **synthetic future input** to the old API: no twenty-minute sleep and no database-clock advance occurred. Its failing assertion compares the immutable stored deadline with the actual database-after-retrieval bound. Fixed consumer expiry and monotonic deadline checks need their own tests; these baseline probes do not claim those pass.

The baseline harness intentionally uses the old integer API and asserts the old observed setup behavior before each final red assertion. The fixed suite uses the new API and must independently prove corresponding behavior. This external file is not claimed to run unchanged against a changed API.

`runtime.txt` records PHP/SQLite versions and reflection-verified baseline paths. Composer's `autoload.php`, `composer/`, and `bin/` were copied privately to the baseline, with dependency directories symlinked to existing installations. The synthetic environment was copied without printing its contents. `source-before.json` and `source-after.json` match exactly across **10,288 tracked files**, the external probe, and the exact command script; Git status remained clean. `artifacts.json` binds the retained evidence files. No green result is fabricated, and no native concurrency, provider, or host condition is claimed.
