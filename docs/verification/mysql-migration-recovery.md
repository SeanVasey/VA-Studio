# Inquiry and related-image migration recovery

Bounded PR #89 repair based on `d70a0edcfb402c67f3b66ac4a30489f2e7d1a3d7`, October 1, 2026. This changes migrations 000031/000032, their existing regression files, and three exact SQLite skip-policy entries; it does not alter application behavior, retained evidence, guard predicates or the original inquiry table Blueprint.

MySQL [DDL commits independently](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html). A process can therefore stop after table/constraint/trigger creation but before Laravel records the migration, or between creating a replacement guard and removing its predecessor.

## Recovery contract

Migration 000031 checks the existing table identity and complete column definition, every existing index and foreign key, and all inquiry trigger definitions before mutation. SQLite compares the stored table/index SQL with the unchanged Blueprint's compiled SQL. MySQL checks InnoDB storage, table/column collations, ordered column types/nullability/defaults/generation, exact index columns/uniqueness and full ascending visible index parts, and named restrictive foreign keys. Extra columns, indexes, constraints or table triggers, wrong definitions, and schema-wide names owned by another table are refused. The original omitted `ON UPDATE` action is checked as `NO ACTION`; no new referential policy is introduced.

Only an empty expected partial table may receive missing expected constraints or guards. A nonempty table missing any expected protection is refused before DDL: installing a guard now cannot establish that retained inquiries were originally protected. A fully installed exact table can resume without DDL or changes to any retained value. Empty rollback can resume after each dropped guard or table; populated rollback still requires the approved retention workflow.

Migration 000032 validates both named insert guards before mutation, including the guard it would remove. It preserves an installed exact target, creates only a missing target, and removes only a verified predecessor after the target exists. Both guards absent is incompatible residue and is refused. SQLite accepts exactly the two original predecessor SQL spellings (`BEFORE insert` from 000028 and `BEFORE INSERT` from 000032 down); conditions and literals remain exact. Any retained schema-4 release, including an image-free release during an interrupted overlap, still prevents down before mutation.

SQLite resolves object names without regard to case while `sqlite_master` name comparisons ordinarily use binary collation. Metadata lookups therefore use `COLLATE NOCASE` to discover all collisions, then require the raw stored table/guard name and definition to match exactly. Uppercase foreign tables, database-wide indexes and guard names are refused before creation or rollback, with strict complete schema, foreign row and parent-evidence preservation tests. MySQL table/guard lookups collect every case-folded name match, refuse ambiguous results, and require any single result's raw identity and definition to be exact before DDL.

Temporary objects can also hide permanent evidence. Both migrations refuse relevant SQLite temporary table/index/trigger identities before any unqualified data read or DDL; 000032 checks every table its guard queries, including `site_releases` before the retained-v4 down check. MySQL temporary tables [hide same-name permanent tables](https://dev.mysql.com/doc/refman/8.4/en/create-temporary-table.html); `SHOW CREATE TABLE` is checked for `CREATE TEMPORARY TABLE` before proceeding. The prefix is supported by [MySQL's temporary-table worklog](https://dev.mysql.com/worklog/task/?id=8067); actual MySQL 8.4 execution remains required. Only exact missing-table SQLSTATE `42S02`/error `1146` is ignored by that identity probe. Tests exercise both empty and populated temporary inquiry tables over retained main inquiries, preserve complete permanent/temporary schema, rows and guards across refusals, and use explicit `temp.<name>` or `DROP TEMPORARY TABLE` cleanup so no permanent object is dropped by cleanup.

Run migrations with application writers stopped. This bounded recovery does not serialize deployment against active inquiry submissions, site publication or competing migration processes; it is not a production restore rehearsal or permission to erase retained records.

## Regression coverage and actual evidence

The existing two feature files now interrupt the real migration invocation immediately after each completed DDL event: all eight SQLite/ten MySQL inquiry installation statements, all four empty inquiry rollback statements, and each related-image create/drop step in both directions. They then retry and check unchanged surviving guards, complete constraints, invalid-write rejection, repeated no-DDL calls and strict whole-row retention. Foreign schema/guard names, altered definitions, partial retained inquiry protections, unguarded image residue, temporary shadows and schema-4 interrupted rollback are negative cases. Three standalone MySQL-only foreign-key/prefix-index methods are explicitly allowed in `scripts/ci/database-sqlite-skips.json`; no other policy entry or algorithm changes, and MySQL still requires zero skips. The case-only schema-wide name test exercises a SQLite global index and a MySQL global foreign-key name rather than skipping MySQL. Common temporary-table cases execute on both engines; extra SQLite temporary-index/trigger cases execute conditionally without adding skips.

Available local checks: `git diff --check` passed; source comparison preserved the original Blueprint and guard predicates; 128 source-extracted SQL probes passed using Python's SQLite 3.53.1, including all installation prefixes, unchanged installed guards, exact stored table/index SQL, invalid writes, valid inquiry transitions and both owned predecessor spellings during image guard overlap. Those limited probes use the inspected locked Laravel grammar rules; they do not execute PHP migrations or establish MySQL metadata.

A further 37 direct SQLite identifier-query/raw-name/preservation probes passed for original and uppercase table, index and inquiry/related-image guard names. They reproduce case-insensitive object resolution, show the original binary metadata miss, and check the new `COLLATE NOCASE` lookup plus exact raw-name rejection without changing schema or rows. They do not execute the PHP preflight.

Thirty direct SQLite temporary-shadow/retention/qualified-cleanup probes passed on SQLite 3.53.1. Source/order checks place temporary refusal before each migration's unqualified reads/DDL; skip-policy comparison confirms exactly the three standalone additions and preserves every prior entry. These remain limited checks, not actual PHP or MySQL acceptance.

No PHP executable is available locally. The actual hosted evidence below is distinct from the limited local SQL probes. Final integrating Foundation acceptance remains required. The hosted focused command is:

```sh
php vendor/bin/phpunit tests/Feature/CustomerInquiryMigrationTest.php tests/Feature/SiteRelatedTrackImageMigrationTest.php \
  --log-junit=migration-recovery-evidence/migration-results.xml \
  --fail-on-empty-test-suite --fail-on-phpunit-warning --display-warnings
```

Hosted receipts must bind the executed source SHA/tree, both engines, case identities, skips and assertions. No local PHP/MySQL/native pass or production migration acceptance is claimed.

## Initial hosted feedback and namespace correction

[Run 36839815516](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36839815516), attempt 1, executed remote `e33e17294dbb3f82fbdd22b4857bedfa7cdf2bce`, tree `7e0e1ae156d0cb861bc28f11c3756c7ee2ae5a28`. Its clean source differs from reviewed permanent composition `b41f8f58845f25e686f8f11ca70b147a362af9ec`, tree `3b5aa741c0c9a1407514390ca7d740488704e814`, only by the fixed temporary proof workflow. Both engines used PHP 8.4.26 / PHPUnit 12.5.34 and verified fresh disposable databases with zero tables before tests. Complete source/runtime/log/JUnit archives were independently checked against numeric artifact IDs, published SHA-256 digests and CRCs.

| Engine | Recorded / executed | Passed / failed / skipped | Assertions | Actual test time |
| --- | --- | --- | ---: | --- |
| SQLite 3.45.1 | 64 / 61 | 61 / 0 / 3 exact policy skips | 622 | 13.225s |
| MySQL 8.4.11, repeatable read | 64 / 64 | 63 / 1 / 0 | 617 | 9m54.684s |

All four changed PHP paths passed actual `pint --test -v` on SQLite. Artifact `11151241271` has ZIP SHA-256 `f4801de55a12c607b021a6423a6abe63f9760948c64aa7fb77723e2eb4eb5621`; MySQL artifact `11150839318` has `3f4fa5c6a1e15570bf7a1e3f4a35153c5709ef61cdff4c752829e0d2eb42200f`. The overall focused run failed: `test_differently_cased_foreign_table_is_neither_adopted_nor_dropped` found that a differently cased foreign table was accepted. The remaining 63 MySQL identities passed; this is diagnostic evidence, not two-engine acceptance.

MySQL documents [filesystem-dependent metadata comparison and explicit case-folded discovery](https://dev.mysql.com/doc/refman/8.4/en/charset-collation-information-schema.html), including coexistence of case-only table names. Its [identifier rules](https://dev.mysql.com/doc/refman/8.4/en/identifier-case-sensitivity.html) distinguish trigger identifiers from the table-name setting; that general description did not establish that case-only duplicate triggers could coexist in MySQL 8.4's data dictionary. The reviewed correction collects all relevant MySQL table/trigger matches instead of choosing `first()`, checks exact raw identity, and refuses foreign table siblings before DDL. Additive sibling checks stay within the existing methods/providers: the 64-case census, three SQLite allowances, original Blueprint and every guard body remain unchanged. The table sibling scenario executes when the actual `lower_case_table_names` setting is zero; the temporary runtime probe records that value. The original lone-uppercase scenario still executes on both engines without a new skip.

## Second hosted feedback and trigger-fixture correction

[Run 36842299633](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36842299633), attempt 1, executed remote `a3329db2da2dcacaf73139b075181152e405a535`, tree `628e7900f6aa136a68f4ae70dea11bf7cc5e78c9`; reviewed permanent composition `ea5a25b4cc10ee2f3e2656034448125ac0a70ac2`, tree `60fb6f525dd533e47c867a7c14f5bed76ec3ef15`, again differs only by the temporary proof workflow. Both engines used PHP 8.4.26 / PHPUnit 12.5.34 and fresh disposable databases. Complete original archives, source hashes and JUnit identities were independently checked against published digests and CRCs.

| Engine | Recorded / executed | Passed / failed / errors / skipped | Assertions | Actual test time |
| --- | --- | --- | ---: | --- |
| SQLite 3.45.1 | 64 / 61 | 61 / 0 / 0 / 3 exact policy skips | 622 | 13.838s |
| MySQL 8.4.11, repeatable read | 64 / 64 | 59 / 0 / 5 / 0 | 612 | 13m05.959s |

All four changed PHP paths again passed actual Pint on SQLite. Its artifact `11152405625` has ZIP SHA-256 `d53ad4d247e64fccb72fb04909c2d8ed83597d6f84c3c9adf82b5375574ccfe8`; MySQL artifact `11151819346` has `15ba8719a2ee419ed7c67da4086d2bfc806a1d02b911f8d3bc2c95a887b85ee3`. The MySQL runtime recorded `lower_case_table_names=0`. The previously failing foreign-table method passed all 12 assertions. The overall run nevertheless failed: the inquiry `global trigger` dataset and all four related-image `up source`, `up target`, `down source`, `down target` datasets raised SQLSTATE `HY000`, error `1359` while creating case-only duplicate trigger fixtures, before invoking their migration-refusal checks. No migration assertion failed in this run; the five fixture errors prevent runtime acceptance.

The exact [MySQL 8.4.11 trigger dictionary implementation](https://github.com/mysql/mysql-server/blob/mysql-8.4.11/sql/dd/impl/tables/triggers.cc#L47), blob `df7ac96ff322533954376160ab3564b1b153f907`, sets trigger-name collation to `utf8mb3_general_ci` and declares a unique `(schema_id, name)` key. This agrees with the observed engine rejection even when the proposed duplicate targets another table; case-sensitive table names do not make this duplicate trigger fixture constructible.

Reviewed test-only correction `55c88f8a0c5a571d3d15772b4ed4cd52f2715ca3`, integrated as `5c94f1939f3b61d97e87cba64f77277ed354482d`, changes only the two existing regression files. Those sibling fixtures now require the exact SQLSTATE `HY000` / integer error `1359`, then strictly compare complete raw schema, foreign/owned/parent rows and canonical guard definitions. Cleanup drops only the synthetic table, avoiding a case-folded trigger drop that could remove the canonical guard. The original lone-uppercase migration-refusal checks remain byte-identical, as do production migrations, guard bodies, the 64-case census and SQLite skip policy. This corrected test source has not yet executed at this checkpoint.

Final acceptance is pending the complete Foundation run on the final integrating candidate, which executes all 64 migration identities alongside the unchanged full gates. Both failed focused predecessors and the earlier successful PR #89 source remain separate evidence; neither establishes acceptance of the corrected composition. Temporary proof workflows are excluded from that candidate. No further duplicate focused 64-case run is required before publication, and no merge is authorized by these diagnostic records.
