# Inquiry operator admission locking

The admission transaction previously locked the site publication but read the configured operator without a lock. A concurrent admin, email-verification or required MFA revocation could commit after that read and before the private inquiry and receipt audit were inserted. A late user lock followed by ordinary Gate/MFA reloads also remains unsafe under an already established MySQL repeatable-read view.

`SubmitInquiry` retains the publication-first lock order and requests the server-controlled current-lock mode of `InquiryPolicy`. That mode requires an active transaction and uses `users.PRIMARY` locking reads for the configured user, the existing `administer-catalog` Gate and the existing configured panel/provider MFA rule. Every authority reload is current and the user lock is retained through transaction completion. The typed option defaults to false; all ordinary callers and public contact projection keep their existing read behavior. No client field chooses this mode, no authority predicate is duplicated in the UI, and no retained model is trusted as the current operator.

The underlying contracts are documented by [Laravel 13 additional Gate context](https://laravel.com/framework/docs/13.x/authorization#supplying-additional-context) and MySQL 8.4's [locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) and [consistent nonlocking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html). Current locking reads use current row data and block conflicting updates until transaction completion; ordinary repeatable reads can retain their earlier view. This change addresses operator authority, not a general redesign of site-publication snapshot reads.

The original three inquiry race identities, method bodies and publication-mutex helper/worker stay unchanged. A separate helper and PHP worker add six source-defined cases in `CustomerInquiryConcurrencyTest`:

| Revocation | Admission locks first | Revocation locks first |
| --- | --- | --- |
| `is_admin` removed | One inquiry and receipt audit commit before revocation | Generic unavailable; no inquiry or receipt audit |
| `email_verified_at` removed | Same saved-before-revocation boundary | Same denial boundary |
| Required `app_authentication_secret` removed | Same saved-before-revocation boundary | Same denial boundary |

These cases require committed synthetic fixtures, a real encrypted MFA secret and the configured MFA provider. The admission PHP process deliberately establishes an eligible ordinary repeatable-read snapshot before either user lock. Two independent PHP workers use independent MySQL connections; the parent observes a real `performance_schema.data_lock_waits` waiter/blocker on the exact configured `users.PRIMARY` row before releasing the first worker. The revocation worker does not take a publication lock. No mocked authority or same-process transaction is offered as MySQL proof.

All unrelated raw user columns must remain exact. Rejected admission preserves every raw inquiry, audit, publication, release and publication-history row. Successful admission retains the exact payload, owner/key/hash, notice, retention reference, operator association and release/hash, adds one receipt audit, and preserves the prior raw audit and site evidence. After revocation, both new submissions and exact replay are denied without changing the retained raw evidence. The existing process/barrier limits remain 50/30/20 seconds; no retry, bypass, provider send or new production activation is added.

The existing SQLite policy keeps its class/base-method schema and adds one precise method pair. Its six provider identities must expand from actual discovery; no dataset suffix, duplicate or whole-class allowance is introduced. The three selected files are `CustomerInquiryHttpTest.php`, `CustomerInquiryAdminTest.php` and `CustomerInquiryConcurrencyTest.php`. The source-defined expectation is 96 cases: 78 HTTP, 9 admin and 9 concurrency. Actual focused discovery must confirm this before any census claim. Expected engine behavior is 96 executed/no skips on MySQL and 87 executed/9 exact MySQL-only skips on SQLite.

Available local checks:

```sh
python3 scripts/ci/test-phpunit-shards.py
python3 scripts/ci/test-database-receipts.py
git diff --check
```

The unchanged Python safeguards passed 35 partition and 24 receipt tests. There is no local PHP executable: PHP syntax, Pint, genuine current discovery and PHPUnit/MySQL execution remain pending. The source-defined cases and static checks do not establish concurrency, fresh complete Foundation acceptance, expected-head merge, provider delivery, production enablement or parent/package completion. Earlier source-specific or failed runs do not accept this successor.

The local-only status above records the 2026-10-01 13:11:50 UTC source-freeze checkpoint, `49a362aeb53390b155ba2d662af37da801fd0d3a` / tree `9d0d770b5bbb225f341ba85147052fa08ac3cf12`. The hosted proof below completes its previously pending focused PHP checks; no local PHP runtime is claimed.

Actual isolated run [36868381455](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36868381455), attempt 1, completed successfully on 2026-10-01. Its published push head `ff37f1463d2d79c4351556a6a668ac9e470d2bf6` / tree `4a3d322e2a1a003c9c9fc7090a5d1e99a440f7cb` has the sole parent `1635101e646825f293c25459fa79cd09b11e9825` / tree `447044c33badbd6eeb61630b051bfb2911f30ba4`. Removing only the temporary workflow reconstructs the exact reviewed permanent tree `9d0d770b5bbb225f341ba85147052fa08ac3cf12`. The workflow-only review pin is `5e08b8fe6a10b18756a5440d69a46b8ab4263ce4`; all 907 tracked source hashes match the reviewed proof, and its complete source patch matches the reviewed eleven permanent deltas plus that workflow.

Both genuine live discoveries contain the same 96 unique identities: 78 HTTP, 9 admin and 9 concurrency. The unchanged strict inventory/JUnit readers verify every owning file/class, complete suite counters, positive executed assertions and exact SQLite policy expansion.

| Engine / actual job | Recorded / executed / skipped | Assertions | CLI seconds | JUnit suite seconds |
| --- | --- | --- | --- | --- |
| SQLite / `110389541673` | 96 / 87 / 9 exact MySQL-only identities | 2,722 | 3.769 | 3.659280 |
| MySQL / `110389541843` | 96 / 96 / 0 | 3,525 | 99.341 | 99.330252 |

There are zero failures/errors on either engine. The SQLite command explicitly overrides only the skip exit condition implied by PHPUnit's `--fail-on-all-issues`; its receipt still requires exactly the nine discovered allowlisted identities, including all six new race cases. MySQL retains `--fail-on-skipped` and executes all nine concurrency cases. All other strict issue flags remain active.

All six new MySQL datasets passed, measuring 574 assertions in this run; the unchanged original three measured 229. These are observed counts, not fixed expectations: genuine barrier polling can change assertion totals. Each new passing case requires distinct PHP processes/connections, an eligible old repeatable-read view, the exact configured `users.PRIMARY` waiter/blocker before release, both commit orderings and full retained-evidence assertions. The six cases cover admin, email verification and required MFA enrollment removal; SQLite's skips provide no concurrency proof.

Actual runtimes are PHP 8.4.26, PHPUnit 12.5.34, Pint 1.32.1, Composer 2.10.3, SQLite 3.45.1 and MySQL 8.4.11 with `REPEATABLE-READ` / `lower_case_table_names=0`; both outer databases contain zero tables before the tests. The locked backend install reports 158 packages. Composer strict validation and audit, syntax of all seven changed PHP files, and Pint on those seven files pass in both jobs. The genuine Foundation media/PDF prerequisites are installed; array mail and provider/notification flags remain disabled outside bounded synthetic test configuration.

| Original artifact | Bytes / unique CRC-valid members | API, upload and downloaded SHA-256 |
| --- | --- | --- |
| `11164961845` — SQLite | 95,956 / 20 | `c767d18018e4eb3e6a560a043d012054d836c27454d0bc74714f8572cd5e3efc` |
| `11166805422` — MySQL | 95,820 / 20 | `efb7ecb0d00488133d260bb7706dfb52a696d55ceed682f95cb42448978d625d` |

The originals retain complete source/runtime/quality logs, live discovery XML, JUnit, source patch and strict receipts. MySQL JUnit SHA-256 is `ec00a1d04c89be3a174af691c1b6020d6de095c22fff135f33e90958454170e1`; its receipt SHA-256 is `2e533f15e26299a4d7a21657e99fb69b65991214b899f737fc436174e5dedbd1`. Receipt member hashes are replayed exactly; Pint runs after the receipt and its log is separately checked against the original archive. No new real transport/provider send, frontend/native proof, controlled performance comparison or general publication-snapshot claim is inferred.

This is focused acceptance of the exact operator-authority source. Fresh complete Foundation on the final combined candidate and an expected-head merge remain pending. The temporary proof workflow is excluded from the permanent candidate. Earlier full-run failures and source-specific diagnostics remain separate; task/parent/package completion and production intake enablement are not established.
