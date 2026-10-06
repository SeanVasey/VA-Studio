# Explicit membership transaction engine

This isolated corrective child starts at
e3bd23820cbae90ddd7beaaf38751ed07d592729 and preserves that source and its
receipts. Each of the four owned membership tables now explicitly requests
InnoDB instead of inheriting MySQL's session/default storage engine. No schema
column, guard body, FK, domain writer, account API or shared CI policy changes.
SQLite ignores the Blueprint engine option.

The genuine MySQL regression temporarily changes only its disposable session's
default to MyISAM, after checking all four previously migrated membership
tables are empty and dropping only those test tables in child-first order.
It does not disable foreign keys or erase populated evidence. The test restores
the original session default in finally.

Before the correction, the actual test failed: one case, seven assertions,
one failure, zero errors/skips, 5.940 seconds. The recreated table's actual
information_schema engine was MyISAM. All 17 original PHP source files,
source-before/source-after hashes, command, raw log and JUnit are retained in
membership-credit-engine-evidence/engine-altered-default-red-source/ and the
corresponding red records. The original red did not reach the later rollback
criteria; those are not claimed as observations of the old source.

After the four explicit engine declarations, the same actual MySQL 8.4.11
test passed: one case, 16 assertions, zero errors/failures/skips, 5.448 seconds.
It proves all four engines are InnoDB, all 11 restrictive FK bindings exist,
and genuine audit-callback exceptions roll back both plan identity/version
creation and a credit bucket/event/audit award. The session remains MyISAM
through those operations. The normal durability runner reports
innodb_flush_log_at_trx_commit=1, sync_binlog=1, doublewrite ON and log_bin true.

The narrow SQLite portability selection executes the captured-clock grant
regression and existing partial-schema/retained-down criterion: three listed
cases, two passed, eight assertions, zero failures/errors and one truthful
MySQL-only engine skip, 0.723 seconds. Scoped Pint and PHP syntax pass on the
two changed/new PHP files.

Commands, runtime arguments, all 17 before/after source hashes and raw receipts
are in membership-credit-engine-evidence/. tested-source-final.json binds the
frozen child commit and exact source. Earlier full checkpoint/focused grant
results remain bound to their original commits and are not backdated.

Independent root review separately found a late QueryExecuted authority
withdrawal accepted by the e3bd ledger. This engine correction does not repair
that finding or approve the foundation. A separate direct-primary final-proof
child and its independent review remain required. The private local/testing
synthetic boundary, production/provider/enrollment exclusions and October 6
manual-only final verification policy remain unchanged. No full CI or remote
action was performed.
