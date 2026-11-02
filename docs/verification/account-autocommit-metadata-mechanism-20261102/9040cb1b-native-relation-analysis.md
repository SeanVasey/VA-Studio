# 9040cb1b STOP: native dictionary relation versus row-count oracle

READ-ONLY actual/source analysis,2026-11-02. Clean source remains
9040cb1b60185e1afe2ba061f5954aa1d0264cc5. No source edits, DB/container/tests,
retry or replay. All original STOPs remain retained. Root reports outer exit2,
1.7949s and final verified zero owned clients/trx/MDL/data, lease returnable.
Raw acmeta-20261102T003414Z-4dd39a388a97 is INCOMPLETE, not a mechanism pass.

## Exact observations (zero-based partial.jsonl indices)

- Context: lower_case_table_names0; session utf8mb4/unicode_ci, canonical strict
  SQL mode, autocommit1. Companion stayed connection64 in recorded contexts.
-233: actual frozen prepared helper admits before publication;318 refuses
  epoch1;531 refuses epoch2. It is the same traced prepared primitive.
-273: native I_S COLUMNS reports TABLES.TABLE_NAME utf8mb3_bin, unlike
  TRIGGERS.TRIGGER_NAME/ROUTINES.ROUTINE_NAME/EVENTS.EVENT_NAME general_ci;
  STATISTICS.INDEX_NAME/TABLE_CONSTRAINTS.CONSTRAINT_NAME tolower_ci.
-423: successful publisher CREATE TABLE CUSTOMER_ACCOUNTS_UPDATE.
-430: successful publisher CREATE TABLE custómer_accounts_insert.
  Both publication commands passed their immediate no-warning checks.
-436: predeclared trailing-space CREATE TABLE rejection42000/1103, exact matching
  Error1103 diagnostic, retained as the intended negative; no object assumed.
-536/541: publisher and companion plain.tables both return EXACT two rows:
  customer_accounts_delete/VIEW, customer_accounts_insert/BASE TABLE.
-552/562/572/582/592/602: companion trigger1,routines2,event1,indexes2,
  constraints3,peer3 each exactly match publisher, no open transaction.
-606: STOP from source predicate VIEW-present AND count(tables)>=3; VIEW was
  present, but count2 failed. No returned catalog mismatch preceded this STOP.

Epoch1 completed its witness/context/currentness phase. In epoch2, all seven
catalog comparisons ran, but subtype assertions after the failing count,
peer qualifier and case/accent/PAD relation calls, AFTER context/primary witness,
primary temp control, Questions and complete aggregate totals remain UNRUN.
Generic STOP cleanup may contain diagnostic witness/close activity; it is not
substitute completion of the missing scheduled epoch2 acceptance phases.

## Contradiction

plain.tables is the ORIGINAL fixed native relation:

SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES
WHERE TABLE_SCHEMA='<owned>' AND TABLE_NAME IN
('customer_accounts_insert','customer_accounts_insert_v2',
 'customer_accounts_update','customer_accounts_delete')

None of those literals spells uppercase CUSTOMER_ACCOUNTS_UPDATE or accented
custómer_accounts_insert. The native TABLE_NAME column's binary collation, plus
lower_case_table_names0, is consistent with both aliases being excluded. The
connection's unicode_ci collation does not turn this dictionary column predicate
into a general case/accent-insensitive match. The observed two canonical rows
are exactly compatible with original relation semantics and successful alias
DDL. It was an author-added assertion error to require >=3 rows under this
specific IN predicate. Both publisher and companion agree on the relation;
this STOP supplies no evidence that the companion missed an in-relation row.

The later relation.case query itself uses TABLE_NAME='customer_accounts_update',
which may legitimately return EMPTY under the same native relation even though
the exact uppercase table exists. Existing expected!==[] there is another
unreached assumption with the same defect. relation.accent is equality to the
canonical unaccented name, so it is not standalone proof of accented-object
existence. relation.pad compares a trailing-space literal; its native result
is UNRUN and must not be guessed from the failed trailing-space CREATE. Schema
identifier legality and comparison padding are different properties.

Do not replace the original predicate with LOWER/COLLATE/LIKE or require PHP
case/accent folding. That would change the hypothesis/guard semantics. Nor does
this small primitive result establish full ownership, runtime version classifier,
namespace repair, costs, factory/TLS security or actual account isolation.

## Proposed fixed native-dictionary oracle (NOT implemented/authorized)

Keep original helper and all seven catalog predicates unchanged. Replace the
unsupported >=3 claim with two independently checked facts:

1. Exact positive existence/type of ALL four intended permanent objects,
   obtained with a separate closed metadata-only query over BINARY exact object
   names: customer_accounts_insert/BASE TABLE,
   customer_accounts_delete/VIEW,CUSTOMER_ACCOUNTS_UPDATE/BASE TABLE,
   custómer_accounts_insert/BASE TABLE. This distinguishes alias DDL success from
   membership in the original reserved-name predicate. Assert exact rows/types
   and no extras/missing objects, and compare publisher versus companion.
2. In that same fixed query project native truth values for ORIGINAL IN and
   each existing relation.case/accent/pad predicate. Derive each expected result
   by those server-evaluated flags, then compare original plain.tables and the
   existing relation queries exactly to their projected relation. Zero rows is
   legitimate for a native negative relation; it is never used as sole proof an
   alias object exists. Require exactly the two canonical rows for original IN
   on this pinned native context, with the projected flags corroborating them.

Example fixed template, selected schema internally owned (values can be bound):

SELECT TABLE_NAME,TABLE_TYPE,
 TABLE_NAME IN ('customer_accounts_insert','customer_accounts_insert_v2',
 'customer_accounts_update','customer_accounts_delete') AS reserved_match,
 TABLE_NAME='customer_accounts_update' AS case_match,
 TABLE_NAME='customer_accounts_insert' AS accent_match,
 TABLE_NAME='customer_accounts_insert ' AS pad_match
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='<owned>' AND BINARY TABLE_NAME IN
 (BINARY 'customer_accounts_insert',BINARY 'customer_accounts_delete',
  BINARY 'CUSTOMER_ACCOUNTS_UPDATE',BINARY 'custómer_accounts_insert')

The BINARY WHERE is solely exact existence control; projected predicates retain
native column relation semantics. No LOWER/COLLATE or PHP text normalization.
Run this fixed control on both existing publisher and SAME companion in the
future source plan; assert exact set equality and native flags, then existing
relation queries against derived sets. Keep strict errors/warnings/waits,
identity/autocommit/old primary witness and every remaining acceptance phase.
A query failure, missing exact alias, mismatched flags/rows or unknown shape
must STOP. Pure regression should include successful uppercase/accented
existence with FALSE original-membership, and genuine missing alias refusing.
This is a small explicit diagnostic oracle correction requiring exact review,
NOT authorization to execute, suppress a warning, or weaken production guards.

All matched rows are PARTIAL synthetic feasibility evidence only. No green
mechanism/current repair, complete epoch2,110/15 or product acceptance claim.
