# Review finding F1: the policy-engine MyISAM case uses the derived capability fixture

The independent review (`../independent-review/DECISION.md`, F1) found that
`ProductionTrackPolicyEngineTest::test_owned_policy_tables_require_innodb_when_session_defaults_to_myisam` had the same stale
capability-rollback setup as group A. It dropped only migration 239's table and the 238 packets, then called 236 `down()`. The
case is MySQL-only (SQLite-skipped and listed in `scripts/ci/database-sqlite-skips.json`), so this lane's SQLite census could
not see it.

The fix replaces that setup with `CapabilityRollbackFixture::isolateCapabilityTables()`, as the other capability files already
do. The helper derives the empty later dependents from the catalog, asserts them empty, drops them leaves first and runs the 238
`down()`, with foreign-key enforcement unchanged. No assertion in the test body changed. The reviewer verified the same
replacement on MySQL 8.4.11 (`../independent-review/review-evidence/mysql84-probe-PolicyEngine-with-helper.txt`).

| Run | Source | Result |
| --- | --- | --- |
| MySQL 8.4.11, private instance | `eca1bbc9`, unmodified test | `LogicException: Unexpected production capability external foreign key reference`; 1 test, 1 error, rc 2 (`mysql84-ProductionTrackPolicyEngineTest-red.txt`) |
| MySQL 8.4.11, private instance | `eca1bbc9` + this change | OK, 1 test, 34 assertions, rc 0 (`mysql84-ProductionTrackPolicyEngineTest-green.txt`) |
| Pint `--test` | this change | passed |

On SQLite the case stays skipped by design, so the census is unchanged. The private instance was shut down with `mysqladmin`
and its datadir deleted. A full MySQL census remains with the final integrated Foundation run.
