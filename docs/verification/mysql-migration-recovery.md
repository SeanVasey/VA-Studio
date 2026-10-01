# Inquiry and related-image migration recovery

Bounded PR #89 repair based on `d70a0edcfb402c67f3b66ac4a30489f2e7d1a3d7`, October 1, 2026. This changes migrations 000031/000032, their existing regression files, and three exact SQLite skip-policy entries; it does not alter application behavior, retained evidence, guard predicates or the original inquiry table Blueprint.

MySQL [DDL commits independently](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html). A process can therefore stop after table/constraint/trigger creation but before Laravel records the migration, or between creating a replacement guard and removing its predecessor.

## Recovery contract

Migration 000031 checks the existing table identity and complete column definition, every existing index and foreign key, and all inquiry trigger definitions before mutation. SQLite compares the stored table/index SQL with the unchanged Blueprint's compiled SQL. MySQL checks InnoDB storage, table/column collations, ordered column types/nullability/defaults/generation, exact index columns/uniqueness and full ascending visible index parts, and named restrictive foreign keys. Extra columns, indexes, constraints or table triggers, wrong definitions, and schema-wide names owned by another table are refused. The original omitted `ON UPDATE` action is checked as `NO ACTION`; no new referential policy is introduced.

Only an empty expected partial table may receive missing expected constraints or guards. A nonempty table missing any expected protection is refused before DDL: installing a guard now cannot establish that retained inquiries were originally protected. A fully installed exact table can resume without DDL or changes to any retained value. Empty rollback can resume after each dropped guard or table; populated rollback still requires the approved retention workflow.

Migration 000032 validates both named insert guards before mutation, including the guard it would remove. It preserves an installed exact target, creates only a missing target, and removes only a verified predecessor after the target exists. Both guards absent is incompatible residue and is refused. SQLite accepts exactly the two original predecessor SQL spellings (`BEFORE insert` from 000028 and `BEFORE INSERT` from 000032 down); conditions and literals remain exact. Any retained schema-4 release, including an image-free release during an interrupted overlap, still prevents down before mutation.

SQLite resolves object names without regard to case while `sqlite_master` name comparisons ordinarily use binary collation. Metadata lookups therefore use `COLLATE NOCASE` to discover all collisions, then require the raw stored table/guard name and definition to match exactly. Uppercase foreign tables, database-wide indexes and guard names are refused before creation or rollback, with strict complete schema, foreign row and parent-evidence preservation tests. MySQL metadata matches also require raw table/trigger names to be exact.

Temporary objects can also hide permanent evidence. Both migrations refuse relevant SQLite temporary table/index/trigger identities before any unqualified data read or DDL; 000032 checks every table its guard queries, including `site_releases` before the retained-v4 down check. MySQL temporary tables [hide same-name permanent tables](https://dev.mysql.com/doc/refman/8.4/en/create-temporary-table.html); `SHOW CREATE TABLE` is checked for `CREATE TEMPORARY TABLE` before proceeding. The prefix is supported by [MySQL's temporary-table worklog](https://dev.mysql.com/worklog/task/?id=8067); actual MySQL 8.4 execution remains required. Only exact missing-table SQLSTATE `42S02`/error `1146` is ignored by that identity probe. Tests exercise both empty and populated temporary inquiry tables over retained main inquiries, preserve complete permanent/temporary schema, rows and guards across refusals, and use explicit `temp.<name>` or `DROP TEMPORARY TABLE` cleanup so no permanent object is dropped by cleanup.

Run migrations with application writers stopped. This bounded recovery does not serialize deployment against active inquiry submissions, site publication or competing migration processes; it is not a production restore rehearsal or permission to erase retained records.

## Regression coverage and actual evidence

The existing two feature files now interrupt the real migration invocation immediately after each completed DDL event: all eight SQLite/ten MySQL inquiry installation statements, all four empty inquiry rollback statements, and each related-image create/drop step in both directions. They then retry and check unchanged surviving guards, complete constraints, invalid-write rejection, repeated no-DDL calls and strict whole-row retention. Foreign schema/guard names, altered definitions, partial retained inquiry protections, unguarded image residue, temporary shadows and schema-4 interrupted rollback are negative cases. Three standalone MySQL-only foreign-key/prefix-index methods are explicitly allowed in `scripts/ci/database-sqlite-skips.json`; no other policy entry or algorithm changes, and MySQL still requires zero skips. The case-only schema-wide name test exercises a SQLite global index and a MySQL global foreign-key name rather than skipping MySQL. Common temporary-table cases execute on both engines; extra SQLite temporary-index/trigger cases execute conditionally without adding skips.

Available local checks: `git diff --check` passed; source comparison preserved the original Blueprint and guard predicates; 128 source-extracted SQL probes passed using Python's SQLite 3.53.1, including all installation prefixes, unchanged installed guards, exact stored table/index SQL, invalid writes, valid inquiry transitions and both owned predecessor spellings during image guard overlap. Those limited probes use the inspected locked Laravel grammar rules; they do not execute PHP migrations or establish MySQL metadata.

A further 37 direct SQLite identifier-query/raw-name/preservation probes passed for original and uppercase table, index and inquiry/related-image guard names. They reproduce case-insensitive object resolution, show the original binary metadata miss, and check the new `COLLATE NOCASE` lookup plus exact raw-name rejection without changing schema or rows. They do not execute the PHP preflight.

Thirty direct SQLite temporary-shadow/retention/qualified-cleanup probes passed on SQLite 3.53.1. Source/order checks place temporary refusal before each migration's unqualified reads/DDL; skip-policy comparison confirms exactly the three standalone additions and preserves every prior entry. These remain limited checks, not actual PHP or MySQL acceptance.

No PHP executable is available locally. PHP syntax, Pint and the actual two-file Laravel/PHPUnit SQLite/MySQL 8.4 proof remain pending, followed by final integrating Foundation acceptance. The focused command is:

```sh
vendor/bin/phpunit tests/Feature/CustomerInquiryMigrationTest.php tests/Feature/SiteRelatedTrackImageMigrationTest.php --fail-on-warning --fail-on-empty-test-suite
```

Hosted receipts must bind the executed source SHA/tree, both engines, case identities, skips and assertions. No local PHP/MySQL/native pass or production migration acceptance is claimed.
