# Read-only analysis of the approved d1 metadata STOP

Exact executed source d1c46bf246223eaf5526edfce97c48949090b99d. Raw evidence:
/workspace/account-guard-evidence-20261101/rrns-20261101T235256Z-6674bbf4d807.
This assessment used existing artifacts, frozen source and public exact-version
PHP/MySQL source tags only. No SQL, DB/runtime query, restart or replay occurred.
Application/probe/launcher source remained clean during this diagnosis. Later
source preparation requires root's separate diagnostic-only authorization.

## Actual observations

Root/independent receipt reports outerexit2/2.158850724s. launcher-result.json
records driverexit1, elapsed1.956174356s, no watchdog/transport error,
termination_proven=true, STOP_CLEANUP_VERIFIED, lease_returnable=true. Server audit
verifies0owned clients/transactions/metadata_locks/data_locks; schemas/objects are
retained. Successful bounded cleanup does not make this a mechanism pass.

partial.jsonl event indices below are zero-based, matching root's event353:

- Events314/322 retain actual helper native prepared six-branch UNION/DATABASE()
  SQL and29 bound names. App-shaped connection23 selected the intended scratch
  database under RR/root@%. Events315/324 both say admitted, before and after ONLY
  canonical reserved TABLE creation+anchor2. Event326 fresh publisher sees that
  TABLE; other-class absence and old anchor1/savepoint are checked. Event353 records
  admitted/admitted. Thus actual frozen primitive failure is reproduced outside
  framework, including negative SHOW priming; this is not just analogous SQL.
- Following publisher operations, all18 recorded warning retrievals produce the
  identical single row: Level Error, Code1295, Message “This command is not
  supported in the prepared statement protocol yet”, result.error=null.
- In particular event365 is real PAD producer error42000/1103, but its immediately
  following warning retrieval event367 reports1295 rather than1103. Successful
  unrelated DDL is likewise followed by1295 (e.g events356→358). This is strong
  warning-reader contamination evidence; it cannot certify prior DDL warning-free.
- Event456, first ordinary plain.tables after publication, returns[] under oldRR;
  fresh publisher event433 contains the canonical table+view. Event458 warning
  retrieval returns1295 and event459 correctly STOPs. No SHARE/SHARE_UNION/UPDATE/
  UPDATE_UNION/SHOW candidate operation executes. Post-stop plain witness remains
  preserved. Sampled stop resources have no data waits/PENDING MDL, which does not
  prove transient lock freedom.

Do not attribute1295 to plain.tables or infer locking view unsupported from this
receipt. No current-metadata candidate family qualified. Full classifier/terminal
behavior, native110/15 costs and actual-domain/ledger gates remain unexecuted.

## Source-supported diagnostic mechanism

Worker sets ATTR_EMULATE_PREPARES=false and uses generic PDO::query for every
fixed SQL id, including SHOW WARNINGS. “query” is not necessarily COM_QUERY.
Public exact-version upstream source was downloaded read-only into this evidence
directory (no compilation/execution), and supports the suspected chain:

1. php-src php-8.4.27 ext/pdo/pdo_dbh.c lines1226–1231: PDO::query calls the driver
   preparer, then executer, rather than bypassing prepare automatically.
2. ext/pdo_mysql/mysql_driver.c lines186–188/214–225: native mode attempts
   mysql_stmt_prepare; server error1295 closes native statement and falls back to
   emulation instead of necessarily throwing a PDOException.
3. mysql-server mysql-8.4.11 sql/parse_tree_nodes.cc lines3056–3061 marks SHOW
   WARNINGS keep_diagnostics=DA_KEEP_DIAGNOSTICS. sql/sql_prepare.cc lines1219–1223
   explicitly rejects KEEP_COUNTS/KEEP_DIAGNOSTICS with ER_UNSUPPORTED_PS.
4. php mysql_statement.c lines330ff executes mysql_real_query when the native
   statement is absent after fallback. MySQL sql_error.cc lines742ff/774ff shows
   SHOW WARNINGS reading the preceding condition list through a separate
   diagnostic area, including severity/code/message.

This explains a rejected native prepare for the diagnostic command replacing
what it was meant to observe, followed by a successful text-protocol retrieval
of that1295. It matches the repeated actual rows and PAD1103→1295 contrast.
However actual COM_STMT_PREPARE/COM_QUERY wire events were NOT captured. Upstream
exact-version source is corroboration, not a binary-build fingerprint or native
controlled proof of the exact installed sequence. Native provenance controls
are still required before relabeling any warning origin.

Sources (exact public tags):
https://github.com/php/php-src/blob/php-8.4.27/ext/pdo/pdo_dbh.c
https://github.com/php/php-src/blob/php-8.4.27/ext/pdo_mysql/mysql_driver.c
https://github.com/php/php-src/blob/php-8.4.27/ext/pdo_mysql/mysql_statement.c
https://github.com/mysql/mysql-server/blob/mysql-8.4.11/sql/parse_tree_nodes.cc
https://github.com/mysql/mysql-server/blob/mysql-8.4.11/sql/sql_prepare.cc
https://github.com/mysql/mysql-server/blob/mysql-8.4.11/sql/sql_error.cc

Downloaded mysql_driver.c SHA2569d63ea770010ed5f3837a64025a5885bfa3bc266bc20d1b1dbfd2c939b2734aa;
pdo_dbh.cb32de821d6fc514bb03f12c1d430684cd26bcf2f68b30b7919b4e6540b61b1a1;
sql_prepare.cc69fce8b432c0aa8dded49fdb0c10e04f3cbc476335b4682e80ebb102fe7645ea.

## Proposed diagnostic-only repair, pending exact review/lease

Use the SAME PDO/session. For the fixed SHOW WARNINGS id ONLY: read existing
ATTR_EMULATE_PREPARES (must be nativefalse); settrue; issue exactly SHOW WARNINGS,
fetch all rows/close cursor; restore exact saved attribute in finally; verify
nativefalse restored. Get/set are client flag operations in php mysql_driver.c
lines460ff/559ff, not SQL. Log before/during/after attribute values and command
mode. Any get/set/query/fetch/close/restore failure stops; no query retry. Actual
app helper, metadata SELECT/locking/UNION/SHOW arms remain native and unchanged.
No global/session SQLmode/isolation/privilege change or new connection authority.
PDO::exec cannot replace this getter because it discards result rows; a second
connection cannot read this session's condition area.

Before candidate qualification, add a dedicated diagnostic-only native control
cell, not namespace/authority data:

- Native SELECT1 known-clean followed IMMEDIATELY by COM_QUERY warning getter:
  expect[]. Then native SELECT CAST('rrns_warning_positive_v1' AS UNSIGNED):
  expect exact Warning1292 whose message includes the fixed unique marker.
- Native clean SELECT after positive followed by getter: expect[] (no retained
  positive, no invented1295). A fixed known-absent SELECT may additionally require
  actual42S02/1146 then getter Error1146 containing that exact control name,
  mirroring actual PAD error provenance without creating/deleting an object.
- Require same connection id, nativefalse restored and no intervening SQL on
  producer PDO between statement and getter. Pure fake tests must exercise exact
  restoration after success/query/fetch/close failure and failed restoration.

These control behaviors are proposals, NOT actual native receipts. Any1295,
unexpected warning/code/message/empty positive, mode mismatch or control failure
must STOP. Do not filter/ignore1295 or relax the strict warnings oracle for real
candidates. Prior d1STOP, raw schemas and admitted-helper reproduction stay intact;
a future approved one-shot run must use a fresh internally owned output/schema.
