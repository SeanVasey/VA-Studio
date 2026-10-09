# Review of f5fea78 (parent dcf983e): scripts/ci/database-receipts.py junit() skip-assertion rule

DECISION: APPROVE WITH CONDITIONS. The gate change is sound. The conditions are two small test and documentation corrections.

## Findings

1. **(Info) The gate's integrity holds.** In PHPUnit 12.5, a test that skips inside setUp is never marked prepared, so it is logged with 0 assertions (`JunitXmlLogger.php:380-393`). A test that skips in its body is logged with `numberOfAssertionsPerformed`. That counter is reset at `TestCase.php:495` and already includes assertions made in setUp (`:513-519`, skip caught at `:535-540`, logger `:225-232, 273-286`). Other failure modes are still rejected:
   - Failures and errors, including a setUp that fails or errors, or a tearDown that errors after a skip, appear as `<failure>`/`<error>` nodes (`database-receipts.py:398`).
   - A skip in beforeClass writes no `<testcase>` nodes, so the census and suite-counter checks reject it (`:417, 422-429`).
   - Any skip outside the census fails the equality check (`:419-420`).
   - Any MySQL skip still fails (`:418`).
   - Risky tests and warnings never appear in JUnit, so this change does not affect them. Incomplete tests already looked the same as skips (`<skipped/>`).

   **Residual risk:** a method already in the census could now do real work, or swallow a failed assertion, and then skip on SQLite. The old rule caught that because PHPUnit counts an assertion even when it fails. The impact is negligible: those methods are MySQL-only by policy, and MySQL must run every one of them with zero skips. A narrower rule, such as per-identity assertion bounds, would tie the verifier to setUp implementation details and buy nothing real. Not recommended.

2. **(Info) The real JUnit is confirmed.** Exactly 8 of 623 skipped cases carry assertions, and all 8 are in the census:
   - Shard 1: `PaidGrantSchemaRecoveryTest::…routine_identities…` (2) and `ProductionIdentityCommittedFrameTest::…repeatable_read_snapshot` (1).
   - Shard 2: `CustomerSuppressionKeyAdmissionTest` ×2, `ProductionFreeGrantSchemaTest` ×2, `ProductionIdentityMigrationTest` ×1 and `IdentityHistoricalCommittedReceiptTest` ×1 (1 each).

   Each one's first statement is a `DB::getDriverName() !== 'mysql'` → `markTestSkipped` guard (e.g. `PaidGrantSchemaRecoveryTest.php:252-253`, `CustomerSuppressionKeyAdmissionTest.php:58-59`). The assertions come from setUp:
   - `PaidGrantSchemaRecoveryTest.php:26,30` (`assertTrue` and `assertSame`, which gives the 2)
   - `CustomerSuppressionKeyAdmissionTest.php:20`
   - `ProductionIdentityFixture.php:27` (`migrate:fresh` `assertExitCode(0)`), reached through `identitySetup()` and `freeSetup()` (`ProductionFreeGrantFixtures.php:29`)

   The harness accepts both shards (3,924/388 and 3,710/235).

3. **(Info) The GitLab path inherits the change and keeps no copy of the rule.** `gitlab-database-receipts.py:19-21,150` calls `proof.database_evidence`, which calls `junit()` (`database-receipts.py:492`). `gitlab-writer-feedback.py:66` calls `proof.junit`. `focused-tests.py:415-431` never had the rule. `docs/verification/ci-database-receipts.md:68-70` does not mention zero assertions and is still accurate.

4. **(Info) Self-tests pass except the known browser-stage failures.** Every `scripts/ci/test-*.py` exits 0 except `test-related-browser-stage.py` (rc=1). Its 4 failures are identical on parent dcf983e, run from a scratch mirror (database-receipts 34/34 OK there too).

5. **(Minor) The duplicate-skip self-test catches nothing.**
   - **Mutation A** (re-add the zero-assertion clause) is caught: `test_exact_reviewed_sqlite_skips_and_no_mysql_skip` errors, and the real JUnit is rejected.
   - **Mutation B** (remove the duplicate-node check) survives all 34 tests. The cause is `test-database-receipts.py:206`, which sets suite `skipped="2"`. The counter check (`:427`) counts cases, not nodes, so it rejects the input on its own. With `skipped="1"`, Mutation B **accepts** a duplicate `<skipped/>`, while the reviewed commit rejects it (scratch `dupprobe.py`). The duplicate-skip rule itself works, but no test protects it.

6. **(Minor) Wording in README F.**
   - "A self-test covers both" overstates coverage; see finding 5.
   - "The same applies to MySQL once a reviewed MySQL skip census exists (the native-selection change)" describes code that does not exist. MySQL currently requires zero skips.
   - The evidence line "zero-assertion clause removed in memory: both accepted" is not produced by the harness embedded in `evidence/sqlite-receipt-junit-check.txt`. It is equivalent to the "after" run, so this is informational only.
   - Nothing else overclaims. Neither README F nor CHANGELOG claims a CI run, and "No Foundation run reached that step" is honest. CHANGELOG line 60 is accurate ("eight … one or two", census-bounded).

## Conditions

1. In `test-database-receipts.py:206`, keep the suite `skipped="1"` (or rebuild the counter from cases) so that only the duplicate-node rule can reject that input. Re-run Mutation B and confirm the test kills it.
2. In README F, change "A self-test covers both" to match what the test actually covers after condition 1. Then either drop the forward-looking MySQL sentence or mark it as not yet implemented and untested.
3. Keep the claim local-only until a Foundation run on the exact SHA produces accepted SQLite receipts.
