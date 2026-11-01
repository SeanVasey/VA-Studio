# RR namespace current-metadata feasibility proposal — NOT APPROVED / NOT EXECUTED

2026-11-01, author Sol6.1. Frozen account worktree HEAD
c2b455a18b6d9817b118cc52b88f8fb968f084de; last actual PHP/test source32aea697.
This file is outside Git. No DB command, container operation, setup, restart or
acceptance test was executed for this proposal. MIG may own the serial DB slot.
Root must review this design and obtain independent review/exact probe-source
approval before a separate small mechanism-only scratch lease.

## Established failure and preserved contract

Actual native RR gate, clean3a984889, Oracle8.4.11/PHP8.4.27, unchanged native
REPEATABLE-READ: fresh full migrations completed; early users snapshot preceded
full account proof; independent PDO created reserved TABLE customer_accounts_insert;
next memo boundary returned successfully. FAILED1/4,155.987s. Finally cleanup
verified0 remaining escaped tables and exact stableV2 guard names/order. No live
held-state catalog dump or PFS trace was captured, so the direct evidence is the
executed source/oracles plus framework failure and cleanup receipts, not a claim
that a live SQL_TEXT was captured. The earlier512MiB OOM was a separate pre-body
environment failure. Neither is a pass.

The current targeted UNION reads I_S under the owned RR view. Repeating that
consistent catalog read is not proven current, and this executed case missed a
new object. More full I_S inspection is not a justified fix for this mechanism.
The failure also means a full terminal proof that repeats the same snapshot
catalogs cannot be assumed to repair the gap. Existing terminal proofs remain
mandatory; any design must make their namespace inputs current as well.

Keep whole stableV1/V2 account guards, all namespace classes, exact existing
comparison/ownership semantics, same principal/PDO/physical transaction witness,
account-table MDL pin, session/shadow counters, existing non-MDL dependent scans,
full terminal proofs, RR, deadline/proof density and ordinary account row locks.
Do not add an observer connection as runtime authority, privilege escalation,
DDL-lock-table registry, isolation switch, cache lifetime extension or fallback.
Original IdentityInspectionCost bound110 and native memo-hit<=15 stay unchanged.
No production repair is selected by this document.

## Complete namespace scope

The version-trigger names are customer_accounts_insert, customer_accounts_insert_v2,
customer_accounts_update, customer_accounts_delete; reserved dictionaries also
include customer_accounts itself as in the existing full classifier.

- TABLES covers both permanent tables and views under version-trigger names.
- TRIGGERS covers reserved names on other owning tables, including AFTER triggers.
  Exact account-attached guard definitions remain protected by account MDL.
- ROUTINES covers BOTH procedure and function namespaces; they can share names.
- EVENTS includes disabled events, regardless of whether their bodies run.
- STATISTICS covers table-local indexes on every owning table in selected schema.
- TABLE_CONSTRAINTS covers UNIQUE, CHECK and FK names independently of index name.
  A constraint can be a positive even when its supporting index has another name.

These are selected-database namespace checks. A same-named object in an unrelated
peer schema is not automatically a collision. Cross-schema triggers/views/routines
REACHING protected identity tables are a different existing dependency predicate:
retain its precise qualifier/dynamic-SQL semantics and its scans. Migration-only
account dependency checks remain migration-only. A local namespace mechanism
cannot be presented as proving all existing cross-schema dependency visibility.
The scratch protocol includes a peer/qualified-target metadata control; any
remaining RR visibility gap in dependent scans must be reported, not hidden by
calling the local namespace fix complete.

## Candidate assessment (hypotheses requiring native evidence)

| Family | Possible use | Limit / falsification condition |
| --- | --- | --- |
| SHOW CREATE TABLE db.exact_name | Explicit known-name table/view lookup, full definition | Does not enumerate table-local index/constraint names; exact lookup can miss aliases that native I_S column equality matches. Success/1146 is not proof of complete namespace visibility or privileges. |
| SHOW FULL TABLES / SHOW TRIGGERS | Bounded selected-schema listings with native WHERE filters | SHOW may be internally rewritten to catalog reads and remain stale. SHOW TRIGGERS LIKE matches table names, so use WHERE on Trigger, never assume LIKE filters trigger name. |
| SHOW PROCEDURE STATUS + SHOW FUNCTION STATUS + SHOW EVENTS | Cover routine kinds and disabled events | Separate namespaces/roundtrips, possibly snapshot-backed listings; hidden objects may yield empty output. |
| SHOW INDEX FROM known_table / SHOW CREATE TABLE known_table | Describe index/constraint definitions when owner table is already known | No SHOW CREATE INDEX and no schema-global SHOW index/constraint lookup. Cannot discover an unknown/new owner using a stale I_S owner list. Enumerating/opening every schema table is unbounded and may pin/block unrelated tables. Not an accepted completion of the SHOW family. |
| Exact I_S SELECT ... FOR SHARE | Closest predicate/column/comparison preservation; potentially current read | Could be unsupported, ignored, applied only to a nonlocking view/derived result, still stale or acquire broad catalog locks. Syntax success is insufficient. |
| Exact I_S SELECT ... FOR UPDATE | Separate current-read hypothesis if independently authorized | Exclusive locking may be broader; not a silent fallback after SHARE fails. Must prove currentness AND resource footprint. |
| Parenthesized UNION branches, each with its own lock clause | Potential one-statement targeted recheck under hit<=15 | A top-level clause after UNION is not assumed equivalent. Each branch must be independently validated, then exact combined statement validated. No optimizer hint/session workaround. |

A pure SHOW solution is currently INCOMPLETE because index/constraint discovery
has no bounded schema-global SHOW equivalent. A hybrid requires a proven current
index/constraint catalog read and measured combined cost. The first preferred
experiment is exact locking catalog reads because they preserve the original
native dictionary predicate, but there is no assumption that MySQL permits or
honors them for these system views. No candidate is selected for source edits.

## Privileges, ambiguity and races

Record CURRENT_USER(), USER(), CURRENT_ROLE(), database, server version,
lower_case_table_names, driver, charset/collation, SQL mode and RR. Readers and
publisher use the SAME existing synthetic principal. Do not create/grant users
or make a separate privileged observer an authorization source. A direct lookup
1146 or an empty listing may mean absent, case-sensitive exact-name mismatch or
metadata invisibility. Treat access denial, NULL/inaccessible definitions,
unsupported syntax and ambiguous absence as fail-closed/nonqualification, never
fallback success. This root-principal scratch experiment cannot prove restricted
production-principal visibility. Required rights and role-change behavior need
separate review; no new privilege requirement is silently introduced.

I_S comparisons use the actual dictionary column collation. SHOW result-column
collation and exact object resolution can differ. Preserve native dictionary
case/accent/PAD semantics: test an uppercase alias and an accented alias as well
as canonical name; do not substitute PHP lowercasing or BINARY equality. Capture
catalog COLUMN charset/collation metadata before snapshots as context. Alias
matching relation from a fresh plain I_S query is the comparison control; a
SHOW family that misses a collision admitted by that relation fails equivalence.

SHOW commands may acquire owner-table MDL. Locking I_S reads might lock internal
catalog rows/ranges or application metadata more broadly than intended. Queries
must not commit/end the owner transaction, lose its savepoint or change its
isolation. Observe actual PFS data_lock_waits/metadata_locks and active statements.
Any unexpected wait or mutation ends the experiment at its first trace. A
listing plus later owner lookup has a discovery race; an absent owner cache is
not authority. Even a current check permits later DDL after that boundary, so
retain the existing check-at-each-boundary/terminal semantics; do not claim
namespace serialization from read success alone.

## One proposed mechanism-only scratch protocol

No Laravel, migrations, membership fixture, production table/schema, real account
guard rewrite or actual-domain acceptance. Use own existing2GiB Oracle8.4.11 only
after root grants a lease. Unique scratch schema rr_ns_sol61_20261101 and peer
rr_ns_sol61_20261101-peer must be absent before CREATE; refuse existing objects.
Retain scratch schemas after receipt unless root separately authorizes disposal.
Base synthetic tables: snapshot_anchor(id PRIMARY KEY) row1, customer_accounts
(id PRIMARY KEY) row1 solely for MDL pin, foreign_target(id PRIMARY KEY,v,parent_id),
foreign_parent(id PRIMARY KEY) row1. The minimal account-named table has NO domain
columns/FKs/retention guards; all differences are explicit, this is metadata-only.
Base nonreserved visible controls (table, trigger, procedure, function, disabled
event, index, CHECK and FK) demonstrate the existing principal can see each class.
They are not substitutes for positive currentness controls.

Use four independent reader PDOs A_plain,A_show,A_share,A_update and publisher B,
plus read-only PFS observer if instrumentation is already available. Each reader:
assert RR, begin, SELECT COUNT(*) FROM snapshot_anchor to establish early data
snapshot, SELECT id FROM customer_accounts LIMIT0 to hold account MDL, SAVEPOINT,
then run exact original plain namespace catalogs to record reserved absence.
B additionally inserts anchor row2 after all reader snapshots: each A must still
read count1 while a fresh same-principal connection reads count2, before AND after
candidate metadata reads. This distinguishes retained early data view from an
implicit view/transaction reset; the savepoint witness must also survive.
No locking-catalog/SHOW candidate is run before publication (avoids candidate
locks changing publisher behavior before the first positive). B then commits the
fixed owned mutations below. A fresh autocommit same-principal control must
observe every actual mutation; if it cannot, stop for privilege/DDL diagnosis.

Read A_plain original predicates: require actual post-DDL invisibility/staleness
receipt where it reproduces; record any classes that are already current rather
than assume all are stale. Roll it back. Evaluate A_show on its untouched early
snapshot, record rows/errors/warnings/statement count and witness state, rollback.
Evaluate A_share similarly and rollback BEFORE A_update, so SHARE/UPDATE do not
block one another merely because the driver left prior-reader locks held.
A_update is a separately preapproved arm, never an automatic product fallback.
All readers' early snapshots are established BEFORE the one publisher batch.

Every arm has fixed SQL, no changing query after a failure to find a passing form.
A syntax/permission error classifies that arm as unsupported/fail-closed; it is
not a positive currentness result. The root-reviewed driver must explicitly say
whether other independent arms continue after anticipated capability failures.
First unexpected wait/error, transaction loss, missing fresh positive control or
wrong classification: capture trace, rollback owned readers, STOP, no retry.

After arms, rollback all readers. Fresh negative/positive controls distinguish
absent exact names from nonreserved visible controls. Cleanup drops ONLY objects
listed in this probe's manifest, then verifies catalog absence/initial row counts
and no active owned worker/transaction. Retain all first traces and failure state.
No DROP DATABASE, global instrumentation/optimizer/isolation change, account source
edit or full fixture. Any absent PFS instrumentation is an explicit limitation,
not evidence of no locks.

## Fixed mutation statements (owned schema, canonical spellings)

Use schema-qualified identifiers in the actual driver, never USE switching inside
an owner transaction. These statements are templates with the above fixed owned
schema substituted once before review, not caller-supplied SQL:

```sql
CREATE TABLE rr_ns_sol61_20261101.customer_accounts_insert (id INT PRIMARY KEY);
CREATE VIEW rr_ns_sol61_20261101.customer_accounts_delete AS SELECT id FROM rr_ns_sol61_20261101.snapshot_anchor;
CREATE TRIGGER rr_ns_sol61_20261101.customer_accounts_update AFTER INSERT ON rr_ns_sol61_20261101.foreign_target FOR EACH ROW SET @rr_ns_control=1;
CREATE PROCEDURE rr_ns_sol61_20261101.customer_accounts_insert_v2() SELECT 1;
CREATE FUNCTION rr_ns_sol61_20261101.customer_accounts_delete() RETURNS INT DETERMINISTIC NO SQL RETURN 1;
CREATE EVENT rr_ns_sol61_20261101.customer_accounts ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE DO SET @rr_ns_control=1;
CREATE INDEX customer_accounts_insert ON rr_ns_sol61_20261101.foreign_target(v);
ALTER TABLE rr_ns_sol61_20261101.foreign_target ADD CONSTRAINT customer_accounts_insert_v2 CHECK (v>=0);
-- A separately named preexisting parent_id index avoids an implicit reserved-name index masking FK-only detection.
ALTER TABLE rr_ns_sol61_20261101.foreign_target ADD CONSTRAINT customer_accounts_update FOREIGN KEY(parent_id) REFERENCES rr_ns_sol61_20261101.foreign_parent(id);
ALTER TABLE rr_ns_sol61_20261101.foreign_target ADD CONSTRAINT customer_accounts UNIQUE(v);
```

Add selected-schema uppercase and accented table aliases only under distinct names
that are legal for the actual lower_case_table_names setting; fresh dictionary
comparison decides whether each matches a reserved name. A case-insensitive
server may prohibit a case-only duplicate: then use a separate fixed alias cell,
not overwrite existing data. Peer same-named objects are a namespace negative;
a peer trigger explicitly referencing selected snapshot_anchor is a qualified
metadata/dependency visibility positive, paired with an unqualified peer-own
anchor negative. Dynamic SQL/inaccessible-definition privilege coverage remains
separate unless explicitly authorized in the exact driver.

## Fixed catalog SELECT family (each alone, then exact combined form)

The selected schema literal is fixed, not DATABASE() on a switched connection.
Parameter names/values are the exact full-classifier native comparison operands.
`<LOCK>` is selected ONCE per independent arm: empty, FOR SHARE, or FOR UPDATE.
No hint, LIMIT, casting, collation override or relaxed owner predicate.

```sql
SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES
 WHERE TABLE_SCHEMA='rr_ns_sol61_20261101' AND TABLE_NAME IN ('customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') <LOCK>;
SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA='rr_ns_sol61_20261101' AND TRIGGER_NAME IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') AND BINARY EVENT_OBJECT_TABLE<>BINARY 'customer_accounts' <LOCK>;
SELECT ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_DEFINITION FROM information_schema.ROUTINES
 WHERE ROUTINE_SCHEMA='rr_ns_sol61_20261101' AND ROUTINE_NAME IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') <LOCK>;
SELECT EVENT_NAME,STATUS,EVENT_DEFINITION FROM information_schema.EVENTS
 WHERE EVENT_SCHEMA='rr_ns_sol61_20261101' AND EVENT_NAME IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') <LOCK>;
SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA='rr_ns_sol61_20261101' AND INDEX_NAME IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') <LOCK>;
SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA='rr_ns_sol61_20261101' AND CONSTRAINT_NAME IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') <LOCK>;
```

If individual locking views prove supported/current, test the EXACT prospective
combined recheck in another already-prepared independent early-RR cell: each
branch is `(SELECT 1 FROM ...same predicate... FOR SHARE)` joined with UNION ALL
(or independently approved UPDATE variant). Do not assume UNION inherits locks
from a final clause or that single-view success proves compound behavior. This
extra cell must be in the reviewed fixed driver BEFORE execution; no adaptive
unapproved second probe. Row-resource/witness observations are required here too.

SHOW arm fixed listings (the actual driver must contain these complete literals,
not a caller-configurable SQL builder):

```sql
SHOW FULL TABLES FROM rr_ns_sol61_20261101 WHERE `Tables_in_rr_ns_sol61_20261101` IN ('customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete');
SHOW TRIGGERS FROM rr_ns_sol61_20261101 WHERE `Trigger` IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete');
SHOW PROCEDURE STATUS WHERE Db='rr_ns_sol61_20261101' AND Name IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete');
SHOW FUNCTION STATUS WHERE Db='rr_ns_sol61_20261101' AND Name IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete');
SHOW EVENTS FROM rr_ns_sol61_20261101 WHERE Name IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete');
``` Also exact SHOW CREATE TABLE lookups for
one present table, one present view and one known absent exact name, retaining
full error codes/result-column identity. SHOW INDEX/CREATE TABLE on the KNOWN
foreign_target is a mechanism observation ONLY; it cannot qualify global unknown-
owner index/constraint discovery. No SHOW family success alone closes that gap.

## Acceptance and next boundary

A family qualifies for a production design proposal only if ALL required classes
and comparison controls are correctly visible under EARLY RR, ordinary absence
is distinguished from unsupported/inaccessible metadata, owner witness stays
valid, no unexpected lock/block/mutation is observed and scope/privilege semantics
are preserved. Capture exact commands/source hash/version/principal/context,
object/row counts, warnings/errors/SQLSTATE, metadata rows, first wait resources,
Questions deltas, held MDL/data locks and cleanup in private JSON + sanitized memo.
Do not claim hit<=15 or inspection<=110 from standalone metadata counts; full
actual boundary counters remain future independent acceptance at repaired SHA.

Failure/unsupported result selects NO source implementation. If every family is
unsupported/incomplete, return for a new explicit design rather than broad scans,
new privileged connections, DDL sentinels or isolation workarounds. If one family
qualifies, propose the smallest closed helper change with exact source consumer
inventory (full classifier + memo + terminal + existing dependent floors), preserve
all old migrations/fixtures and obtain separate sensitive source review. Only then
resume the mandatory actual RR test, unchanged cost110, memo<=15, MDL/witness,
process/ledger and true account-only domain isolation/collision acceptance.

This probe is mechanism evidence only, not actual-domain/member acceptance. The
account prerequisite and original member trial stay failed/unaccepted; mountfalse.
