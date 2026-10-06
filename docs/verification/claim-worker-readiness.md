# Claim race worker readiness publication

## Finding and historical limit

The original PR #8 run `37472116788`, native MySQL shard 8, printed an `E` before cancellation. Its retained default-order test listing maps progress ordinal 274 to `CustomerPurchaseClaimConcurrencyTest::test_simultaneous_exact_retry_returns_one_immutable_claim_and_one_audit`; ordinals 273 and 275 are the competing-account and withdrawal cases. The tested source was `75722ac914e550f1b9421f3ecfa81adb13e7ab62`, tree `dc0cc0c8edde352aa7eb26187e42500bfe4e3cea`, checked out by CI as `b677fbc7fbbb5d680f7652afec54a7f6af410b8c`.

The error-containing progress block was emitted at `2026-10-06T14:02:39.5849320Z`; cancellation was logged at `14:05:35.2694210Z`. The artifact has a zero-byte JUnit file and no final receipt or exception stack. Thus the case identity remains an ordinal inference and the original error's cause remains unknown. Cancellation did not precede the recorded error, and partial progress is not native acceptance.

Inspection found a separate, concrete readiness publication defect. The worker wrote JSON directly to `ready-N` using `file_put_contents()`. The parent waited only for `is_file(ready-N)` before decoding with `JSON_THROW_ON_ERROR`. A process switch after destination creation but before the complete write can expose empty or partial JSON and raise `JsonException` in the parent. This is a possible `E` path, not proof that the historical CI error followed it. Process timeout and database exceptions remain other possible error paths without the missing stack.

## Bounded correction

Only `tests/Support/customer-purchase-claim-worker.php` changes executable behavior. The worker encodes its connection/PID once, writes a sibling `ready-N.tmp`, requires the exact byte count, then renames it to `ready-N` in the same directory. The directory already has a per-test UUID and each worker has its own index. The parent's final path is exposed only after the complete write closes. Write/publication failures stop the worker; existing parent cleanup removes the disposable directory.

Production services, test identities and assertions are unchanged. The parent still requires distinct processes and connections, observed InnoDB waits on the exact mutex row, expected winners, one immutable claim/audit, withdrawal refusal and zero leftover transaction depth. The 15-second database/parent wait, 20-second worker start barrier and 40-second process limits are unchanged. No database durability setting or CI budget changes.

## Focused evidence

All database runs used the physical vendor copy in the isolated diagnostic worktree and a fresh native MySQL 8.4.11 instance through `mysql-runtime/run-tests.py`. The wrapper verified `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, `innodb_doublewrite=ON` and binary logging enabled. PHP was 8.4.26.

| Source/check | Result | Retained receipt |
| --- | --- | --- |
| Unchanged exact retry | 1 passed, 76 assertions, 9.495 s | `claim-race-diagnostic-baseline.xml` / `.log` |
| Unchanged full class, repetition 1 | 3 passed, 176 assertions, 27.608 s | `claim-race-diagnostic-unchanged-1.xml` / `.log` |
| Unchanged full class, repetition 2 | 3 passed, 175 assertions, 26.206 s | `claim-race-diagnostic-unchanged-2.xml` / `.log` |
| Unchanged full class, repetition 3 | 3 passed, 177 assertions, 25.586 s | `claim-race-diagnostic-unchanged-3.xml` / `.log` |
| Corrected full class | 3 passed, 190 assertions, 29.246 s | `claim-race-diagnostic-corrected.xml` / `.log` |
| Worker syntax / whitespace | `php -l` and `git diff --check` passed | Local command output |

Every database receipt has zero errors, failures and skips. Assertion totals vary because the existing polling loop asserts worker liveness on each iteration. The unchanged passes do not reproduce or explain the original error.

The exact baseline command was:

```sh
python3 ../mysql-runtime/run-tests.py -- php vendor/bin/phpunit \
  tests/Feature/CustomerPurchaseClaimConcurrencyTest.php \
  --filter test_simultaneous_exact_retry_returns_one_immutable_claim_and_one_audit \
  --log-junit ../claim-race-diagnostic-baseline.xml
```

Full-class runs use the same command without `--filter`, with the corresponding receipt filename. Three bounded unchanged repetitions ran sequentially against one disposable server, with the test's normal fresh-schema lifecycle each time. The corrected class ran against a separate fresh server.

A scratch-only deterministic protocol probe (`claim-ready-publication-probe.php` / `.json`) used independent PHP processes and explicit scheduler barriers. It paused a writer after creating its destination and before writing JSON, then applied the parent's existing `is_file`/decode sequence. Direct publication exposed the final path and produced `JsonException`; publishing through a sibling temporary path kept the final path absent until release, close and rename. Both modes decoded valid complete JSON after release. This deliberately controlled interleaving demonstrates the readiness defect and correction; it does not reproduce the unknown original CI exception or alter the native tests.

The original artifact/log are retained as `backend-mysql-8-37472116788-1.zip` (artifact `11418812758`) and `ci-run-37472116788-mysql8.log`; the separate diagnostic receipts are retained with this development session. Independent exact-commit review and integration remain required. These focused results do not substitute for full hosted acceptance of the eventual integrated source.
