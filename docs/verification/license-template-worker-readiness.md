# License-template worker readiness publication

October 6, 2026. Bounded test-harness correction based on reviewed template-authoring source `5bc7cbd` (published `9bea39d`).

The template concurrency worker wrote JSON directly to `ready-N`; its parent waits for that path to exist and immediately decodes it with `JSON_THROW_ON_ERROR`. File creation can become visible before its complete contents, so the parent can observe an empty or partial payload. This is a concrete protocol defect found by source inspection. No observed hosted template failure is attributed to it.

The worker now serializes the same four readiness fields, writes a sibling temporary file, checks the exact byte count, and renames it to `ready-N` only when complete. Each worker owns its numbered path. The parent, process barriers, InnoDB waits, lock assertions, 15/20/40-second limits, durability and test identities are unchanged. The separate `locked-N` presence marker is unchanged: the parent does not decode its payload and verifies the actual database wait separately. No application or vendor code changes.

## Verification

- Fresh disposable native MySQL 8.4.11, full unchanged `LicenseTemplateAuthoringConcurrencyTest`: **8 cases / 740 assertions**, zero errors, failures or skips; JUnit time **39.771184 seconds**. Normal durability: `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite and binary logging enabled. Raw evidence: `template-worker-readiness-native.xml` and `.log`.
- Command: `python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/LicenseTemplateAuthoringConcurrencyTest.php --log-junit /workspace/scratch/0c039e9e0645/template-worker-readiness-native.xml`.
- PHP syntax, scoped Pint and `git diff --check` passed.
- The earlier independently reviewed claim-worker controlled interleaving demonstrates the same direct-publication versus temporary-file/rename protocol: the direct path is visible before completion and throws `JsonException`; the atomic path remains absent until its complete valid JSON is published. That retained `claim-ready-publication-probe.json` is supporting protocol evidence, not an additional execution of this template worker or a reproduction of a hosted error.

Assertion totals include actual concurrency observations and are recorded exactly for this run. This focused native result is not full candidate acceptance. All source-bound hosted gates and complete database receipts remain required after integration.
