# Membership rollback preserves Laravel bookkeeping

This corrective child starts at
`9a5feca718b91df6df8e77b0deabde11c56b6257` and addresses the retained-schema
finding in [PR #16](https://github.com/SeanVasey/VA-Studio/pull/16#discussion_r4201166306).
It owns only migration `2026_10_06_230000`, `MembershipCreditMigrationTest`
and this verification record. The root separately owns empty parent-fixture
disposal and the affected manual test selection. Earlier membership/admin
worktrees, checks and failed predecessors remain preserved.

The predecessor's empty `down()` retained all membership tables and guards but
returned success. Laravel's actual `Migrator::runDown()` then deleted the
corresponding migration repository row. The next ordinary migration run could
treat that retained installation as pending, while this migration's protective
`up()` refused existing objects. Retaining schema alone was insufficient.

`down()` now throws the explicit `LogicException` before any read, DDL or data
mutation: `Membership schema rollback is refused; retain its migration record,
data and guards.` Laravel does not reach its repository deletion on that
exception. The same refusal covers an empty, populated, partial or absent
retained schema without attempting to infer ownership or destructively repair
inconsistency. `up()`, every table/index/foreign-key/trigger definition, source
retention rules and membership domain behavior remain unchanged. Operational
removal still needs a separately reviewed retention change.

## Genuine command regressions

The existing migration test class adds empty and populated datasets. Each
isolated fixture puts exactly the membership repository record into a dedicated
latest batch, verifies Laravel selects it, and invokes real
`Artisan::call('migrate:rollback', ...)` with the explicit real migration path
and one step. It checks the exact propagated refusal and compares every
migration repository row, all four membership table definitions and indexes,
all twelve triggers, all membership rows, users, customer accounts and audits.
The populated case retains an actual synthetic grant and reservation.

The ordinary `migrate` command must then succeed without reinstalling or
changing that retained evidence. A separate generated migration in unique
disposable test storage must actually create its probe table and repository
row; its marker insertion and the remaining exact retained evidence are
checked. This proves useful forward migration, rather than only a command
that reports nothing pending. The fixture lifecycle wipes only the disposable
test database and removes the generated migration directory.

The two genuine regressions were run against the unchanged predecessor
migration plus the newly added working test bytes. Both SQLite and native
MySQL failed two cases/18 assertions with two failures and zero errors/skips:
their exact repository snapshot lost the membership record. The complete
executed migration/test bytes, before/after source hashes, commands, raw JUnit
and logs are retained under `membership-rollback-evidence/predecessor-red-source/`
and the separate `rollback-sqlite-red` / `rollback-mysql-red` receipts. These
are new tests against old application bytes, not a claim the old committed
test suite contained the regressions.

The existing partial-schema test now requires explicit rollback refusal and
retains all its original data/schema/guard equality assertions. Every existing
raw immutability, balance floor, ownership, predecessor, malformed movement,
collision and temporary-shadow assertion remains unchanged.

## Frozen focused verification

```sh
php vendor/bin/phpunit tests/Feature/MembershipCreditMigrationTest.php \
  --colors=never --log-junit=<evidence>/rollback-sqlite-green.xml

python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- \
  php vendor/bin/phpunit tests/Feature/MembershipCreditMigrationTest.php \
  --colors=never --log-junit=<evidence>/rollback-mysql-green.xml
```

`<evidence>` is `/workspace/scratch/2876616e88c3/membership-rollback-evidence`.
Frozen green receipts bind the exact commit/tree, executable source hashes,
autoload paths, commands, selected cases, exit/JUnit totals and raw log hashes.
Both commands select only the 38-case membership migration class. PHP syntax
and scoped Pint cover the two owned PHP files. The local native runner uses a
fresh disposable MySQL 8.4.11 instance and database over a unique private
loopback TCP port, with flush-at-commit 1, sync-binlog 1, doublewrite ON and
binary logging enabled. Test connection and generated key values are not
retained in source or receipts. The original red native run has its own
connection/engine metadata; it is separate from frozen green evidence.

This refusal stops reverse-order rollback when it reaches the membership
migration. It does not make an earlier/larger rollback batch globally atomic
or certify direct out-of-order parent-account removal. Parent migration tests
must dispose their freshly created, proven-empty child tables through their
explicit test lifecycle; they must not reuse operational `down()` for that
purpose or suppress retained-data guards. Independent review assesses the
exact frozen corrective source before publication. No hosted CI, routine
matrix, live database mutation, payment, enrollment or remote publication is
performed by this child.
