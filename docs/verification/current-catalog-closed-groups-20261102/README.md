# Closed current-catalog group design — work packet first

Owned DOCS-ONLY surface: this new packet. Frozen production/test source7347a2b8
remains unchanged; no DB/bootstrap/profiler/native execution is authorized.
Prepare exact straight-path materialization and same-stage closed group/return
contracts and differential acceptance plan. Existing full133>110 failure stays
OPEN; memo14<=15 and fullcallbacks2 stay separately accepted observations.
No implementation, lower validation density, removed primary facts, outside-window
factory, whole-proof/autocommit cache, caller SQL/schema/decoder or new capability
is authorized by this design preparation. Independent design review comes next.

Design artifacts: DESIGN.md supplies actual materialization schedule/barriers,
fixed binding ownership, ordered-cell transport/return/refusal contract and
unrun differential/adversarial plan. GROUPS.json pins all36 exact original SQL
materializations, columns/type/null/order/bind schemas to ten stage IDs. Source
hashes and Python-only static consistency receipt are committed; copied design
decision is byte exact. Gross26 saving could yield107 only with zero extra SQL.
NET>=23 remains conditional: header single-evaluation, ordinal equivalence and
warning-free lossless encoding need proof. Per-group warning queries would exceed
110. These missing proofs are explicit blockers, not assumed passing counts.
No implementation/test/migration source changed. Full110 remains OPEN/FAILED.

## Authorized implementation inventory BEFORE edits

Owned changes: new ProductionIdentity/CatalogGroup.php and CatalogGroupPlan.php
(fixed registry/recipes/compiler only), existing CurrentCatalog.php (private
streaming decoder/native Pdo\\Mysql warnings/group execution), CatalogLease.php
(closed group entry), AccountAdmissionGuards.php and IdentityMigrationOwnership.php
(same-stage routing only); new Unit/ProductionIdentity/CatalogGroupContractTest.php
and Feature/ProductionIdentity/CatalogGroupDifferentialTest.php. No Billing API,
MembershipSchemaCatalog, old test/migration/fixture, config or other app change.
New packet files carry review/result/source manifests. No native execution.
The P1 nullability fixes are applied to the design schema before generating fixed
source registry. Current110/15 windows/limits and memo dependents remain unchanged.

## Prepared implementation, actual evidence and unresolved gates

Source preparation only: fixed source registry/recipe/compiler and private decoder
have been added, with new standalone pure and native differential test SOURCE.
P1 native NULL declarations now include view ENGINE/TABLE_COLLATION/CREATE_OPTIONS
and functional index COLUMN_NAME/unordered index COLLATION. Oracle8.4.11
sql/dd/impl/system_views/tables.cc lines46–59 specifically returns NULL for view
ENGINE/CREATE_OPTIONS; TABLE_COLLATION is a LEFT JOIN. The full source copy is
private evidence, with its hash recorded in the final source receipt. Original
ownership consumers still reject invalid objects; decoder representability is
not permission to admit them.

SQL family relations contain ROW_NUMBER windows, preventing merge; their CTE
materializations are shared by header counts and row cells. The complete wire
UNION ALL is a second nonmergeable CTE; a same-statement aggregate gate accounts
ALL families/names/rows. Valid result charge = sum(payload octets+256perrecord)
+4096fixed protocol reserve <=16MiB; records<=131072, each family/name<=65536,
encodedrow<=8MiB. Oversized row becomes NULL and invalidates the gate. On any
overflow only EVERY expected header with invalid [null] count is emitted, and the
whole group refuses: no partial fact is returned or accepted. No SQL LIMIT,
namespace filter, best-effort repair or scope truncation is used for acceptance.
This bounds transmitted drain size, NOT server pages/materialization/work/time.
Exact MySQL legality/shared materialization/protocol reserve/native semantics
still require independent source review and native proof; not claimed observed.

Private companion is exact internal Pdo\\Mysql; primary is untouched. Group-only
unbuffered mode allows receiving/decoding caps before whole-result allocation;
fixed native attribute mode is verified/restored on success after complete EOF,
warning capture and close. Failure permanently invalidates/drops companion, never
reconnects/returns decoded partial facts. Buffered native mode remains for existing
context/legacy single-family reads. Failed unbuffered destruction may drain;
SQL-wide wire cap/gate is the explicit finite drain-bound proposal, rather than
pretending a PHP property drop cancels it. No KILL/socket/global/native privilege
or instrumentation change was added. Getter warning count is checked immediately
after native prepare, execute and full EOF, before close/nextSQL. Zero-SQL getter
and its exact internal method provenance are checked; no SHOW WARNINGS fallback.

Early preparation error retained: an external source generator's placeholder
replaced the substring IDENTITY inside registry IDs, producing a PHP parse error
caught by Pint/lint. Template replacement is now line-delimited; regenerated file
linted and focused pure4/548 passed. Later full pure selection38/902 passed while
source was still being prepared; final exact-byte receipts below supersede that
intermediate run. No DB/bootstrap/profiler/native execution occurred.

Native differential SOURCE currently contains fixed canonical V1/V2/ordered-overlap
original-vs-group typed comparisons; view/functional/fulltext/Unicode/default/
procedure/event aliases and NULLs; native warning clean→warned JSON→empty→clean
EOF/getter Questions controls; EXPLAIN shared-materialization assertions; and an
oversized original view definition requiring invalid-only bounded headers and
whole-group refusal with preserved primary savepoint. Every native method is
UNRUN. No counter/gate pass follows from source or pure tests. The warning JSON
control invokes the SAME private warning-refusal primitive, not a fake getter;
it does not pretend an organically warning-free catalog magically emitted one.

### Additive parent-entry scope correction BEFORE edit

Root approves two NEW closed entries identityParentStable and
identityParentMigrationState, selected ONLY by IdentityMigrationOwnership's
parentGuards. Existing stable/migrationState public signatures stay byte-equivalent
in parameters/default behavior and use their original single-family path. Shared
private inspector gets an INTERNAL bool grouped selector; no external group/
SQL/schema/list/proof/cache argument. Both new entries remain complete and safe
when called directly: same primary SHOW/FK/session/namespace/shape/version checks.
Reason: standalone first31→theoretical23 with hit14 would fail the unchanged
hit<intdiv(first,2) oracle. No dummySQL or oracle weakening is allowed. This
consumer distinction retains standalone first/memo/full-terminal density while
optimizing the composed full parent inspection. The unchanged110 actual gate,
not theoretical107, decides acceptance. WholeV1/V2 and migration-only overlap
admission/refusal equivalence need independent native proof and exact-source
review; all remain UNRUN for this source batch.

### Frozen-source verification and limits

Final exact-byte checks:30PHP files lint PASS; Pint--test30 PASS without edits;
external no-configuration pure selection40tests/912assertions PASS on PHP8.4.27,
1.334seconds,exit0. Commands/outputs/all30 SHA256 values are in
closed-group-final-{static,pure}.json and IMPLEMENTATION-SOURCE-SHA256.txt. Pure
checks include original guard definitions; no Laravel/bootstrap/PDO construction/
DB/network test occurred. Added7generic methods and6native-only methods are
listed in NEW-TEST-METHODS.json; all native differential methods remain UNRUN.
The earlier39/910 check preceded final group-tag/stronger EXPLAIN proof-source
completion and is superseded by the exact40/912 receipt. Group tag is now fixed
in every wire record; missing/crossgroup tags and JSON numeric-key OBJECT versus
ARRAY confusion refuse (JSON objects are never treated as ordered cell arrays).

COMPILED-PLANS.json contains30 PURE source plans (all10stages×three versions),
not executed SQL/results. Its commands require only own autoload and pure fixed
source methods, with no app bootstrap or PDO construction. NATIVE-QUEUE.md gives
exact selected method names/command shape and remaining acceptance gates.
Standalone account stable/migrationState default paths remain structurally7347;
only additive named parent entries select account groups. Their same complete
private inspection is safe on direct calls; native equivalence/refusal source
covers V1/V2/overlap/partial/foreign. No legacy public signature changed. Public
new entry/group consumers and all old stable/migrationState callers are in
IMPLEMENTATION-CALLER-INVENTORY.txt. Billing/Membership callers/helper and raw
billing I_S scope are unchanged. No old test, migration, fixture, SQL guard,
environment key, shared census/workflow/ledger or member trial source changed.
MIG source owner was notified; no untested migrator source was copied.

Exact runtime source checks (not executed DB proof): mysql8.4.11 sql_lex.cc3962–3969
rejects merging set operations/windows; sql_derived.cc70–95 describes single
materialization/shared TABLE_SHARE for multiple CTE references, and913–914 reuses
that temp table. PHP8.4.27 mysql_driver.c449–455/553–555 changes/reads the private
buffer flag without SQL. Runtime source file hashes are recorded separately.
Their semantics support this implementation proposal; exact SQL legality,
per-family plan sharing, native type/ordinal/null/collation equality, warning EOF,
wire/error drain bounds and actual110/15 remain UNEXECUTED for this candidate.
256byte row charge+4096fixed reserve is a conservative logical MySQL result
framing bound to review/prove; it is NOT a claimed bound on TLS ciphertext,
TCP/kernel buffers, server pages/work/time or target-host latency/headroom.
Large otherwise legitimate catalogs beyond complete wire caps are explicitly
unsupported/refused, not silently invisible. No budget/guard reduction funds caps.

Historical SOURCE-SHA256.json and STATIC-DESIGN-CHECK.json pin the prior docs-only
7347 baseline. The IMPLEMENTATION-prefixed manifest and final receipts pin THIS
candidate. Original full133FAIL and memo14PASS receipts are copied byte exact;
133>110 remains the binding failed gate until an actual reviewed candidate passes
unchanged acceptance. Theoretical107 is capacity arithmetic only, never a promise.

## Test/packet-only review repair BEFORE edit

Read complete independent source decision0542: one source-predicted test composition
P1 (canonical admission called after publishing forbidden objects), no confirmed
new product bypass. Own only CatalogGroupDifferentialTest.php and this packet.
Split direct canonical entry positives from raw family comparison; prove all four
entries before/after guard negatives, and original/grouped refusal after adverse
raw equivalence. Overflow proof must additionally show failed capability cannot
reuse/reconnect and its actual companion session releases without a retained PDO
reflection reference. Retain primary transaction/savepoint control explicitly;
this is NOT full SchemaProofFrame witness/MDL/terminal composition. Warning
reflection is getter/EOF/shared refusal-primitive proof only. All production/runtime
bytes stay EXACT0542; no native execution/profiler/PFS/global action authorized.

Final TEST/packet-only repair checks: target feature file lint/Pint--test PASS;
exact cheap pure selection40tests/912assertions PASS,PHP8.4.27,exit0. Actual
commands/results and all30 current source hashes are in
closed-group-test-repair-{static,pure}.json. All app/runtime/database/config/
Composer/old fixture bytes are IDENTICAL to0542 (empty scoped Git diff), so
production source assessment remains scoped there; only the one new feature
proof source changed. IMPLEMENTATION-SOURCE-SHA256.txt maps the current bytes;
prior closed-group-final receipts remain historical0542 evidence. Expected
LogicExceptions cannot mask never-working entries because complete direct
version/fingerprint positives now run for all four entries before and after
each negative mutation. Raw adverse differential never invokes canonical
admission first. Failed-capability/public registry reuse both refuse without
new construction; native PDO reflection references are unset before refusal,
then private property NULL and actual previously visible session disappearance
are required. Overflow is transaction/savepoint control only, not full witness
or MDL acceptance. All original strict typed/multiset/order/oversize/EXPLAIN
oracles remain. No new methods/skips; six native methods UNRUN. No native/DB/
bootstrap/profiler/PFS/global/CI/push/PR action occurred in this repair.

## Native3592 compiler repair — declared before source edit

ONE approved read-only diagnostic at frozen7cae reproduced fixed A1 PREPARE
PDOException HY000/3592 after actual canonical factory success. Template SHA256
36940bdd2038ed5d3f0cd3482a41d5660677d9d25d2910a0f24255d852a9fa66,30binds/7families.
Execute/EOF/column metadata/decoder and postprepare warning count were UNREACHED;
no SHOW WARNINGS/retry occurred. Restore succeeded; expected invalid-capability
lease-close refusal was followed by verified all-zero DB/PHP/ownCLI cleanup and
unchanged source. Original six proof bodies remain UNRUN; full133FAIL remains.

Root exact Oracle mysql-8.4.11 share/messages_to_clients.txt blob
fb157bb8b0ead3c78c9755803e548a49ac1dea48 line8213 maps3592 to
ER_WINDOW_ILLEGAL_ORDER_BY, legacy positional ORDER/PARTITION expression rejected.
The direct single-slot compiler emitted bare PARTITION BY0. Minimal owned repair:
ONLY compiler omits PARTITION for direct families; multi-slot retains qualified
s.identity_lookup. One partition/window, slot0/header/order/binds/guard facts are
preserved. Existing pure window-count assertion is normalized from the literal
PARTITION prefix to ROW_NUMBER OVER prefix (same assertion/count, still requires
a window per family); all native six proof oracles remain untouched. Regenerate
fixed plans and source-hash receipts; no other app/guard/limit/warning/factory/
primary-stage change, no native rerun until exact independent source approval.

## Named lookup join repair — scope declared before edit

Actual controlled321assert failure and retained-state3read reproduction establish
I2 family1/slot0 native header0/R0/decoded0 versus original target1, zero warnings.
Loss is upstream of decoder in that condition; no exact engine/optimizer cause
or OOM attribution is proved. Root authorizes private CatalogGroupPlan named
lookup compiler ONLY: one existing NO_MERGE(lookup) hint on one joined SELECT,
integer-only positions, CASE exact original per-name predicates, same IN/binds.
CurrentCatalog::identityPlan remains ORIGINAL differential oracle. No decoder,
factory, caller, guard, direct-family, native test, cap or validation-stage edit.
Regenerate plans/source hashes; run existing40/912/static; exact independent
review and root lease must precede any new pinned I2 control/native query.

Final named-join source checks: compiler lint/Pint--test PASS; existing pure
selection40tests/912assertions PASS,PHP8.4.27,1.367seconds,exit0. Commands/output
and30 final source hashes in named-join-repair-{static,pure}.json and refreshed
IMPLEMENTATION-SOURCE-SHA256.txt. All tests (including six native proof oracles),
CurrentCatalog original compiler/decoder/factory and all other app bytes are
UNCHANGEDcaa. Direct12templates byte-identical;18namedtemplates retain exact
binds/families and use integerCASEjoin. NAMED-LOOKUP-JOIN-REPAIR.md gives pointwise
NULL/duplicate/collation/type/order/parameter equivalence argument and unrun native
limits. Prior clone-compiler token-identity statements describe historical pre-
repair source; ORIGINAL oracle remains untouched while PRIVATE group compiler now
intentionally differs. No new test was added or assertion weakened.

I2-LOST-FACT-SAFE-RECEIPT.json retains actual oldnativewire absence versus positive
original, not a result for this source. Native query/EXPLAIN/test/bootstrap/restart/
new-pinned diagnostic have NOT run on this candidate. Conditional107/full110,
all group/version/alias/materialization/type/warning/resource/composition and
originalOOM fit/repeatability remain unaccepted. Next MUST be reviewed/re-pinned
three-read I2 control first under a new ROOT lease; no native queue advancement
or cost run is authorized by source/static success. App flags/member trial stay
defaultoff/unaccepted; no sourceoutsidecompiler/testmigrations/sharedledgers/env
keys/workflows/push/PR changes occurred.

## A2 repository oracle preparation — 2026-11-02

Prepared from `a9b48dafb64dbf34e3ca0397d1b5b17d56bb5bb0`: the test-only
`ExplainSharedMaterialization` helper pins the A2 v2 source SQL, bindings and
three family recipes. It proves six ordered header/row branches, unique shared
family definitions, distinct gate/wire/helper/family roles and the gate's actual
wire dependency. Families 0/1 still require their own actual window; only exact
family 2 (`QB894B1A4D034`, the known scalar COUNT recipe) may omit its window.
Every graph window must belong to a proven family. Synthetic positive/negative
graphs cover branch order/completeness, missing/crosswired consumers, unrelated
references, duplicate definitions, ordinary windows, IDs/aliases and graph caps.
No saved native graph or external grammar package is a runtime dependency.

Only the A2 assertion branch in the existing physical native method changes.
Other group assertions, complete family coverage, warning provenance, cursor/
lease cleanup, source compiler, production code and 110/15/resource limits stay
intact. Native08 qualified standalone A2 v2 grammar only; it does not accept this
repository candidate, all groups/families, v1/overlap, original execution context,
typed facts, currentness, or cost. The original expected-3/actual-2 failure and
133-over-110 result remain historical failures, not overwritten passes.

Source preparation only: PHP lint, formatter and all tests are **UNRUN**. ROOT
owns execution and admission. Queued pure selection is
`php vendor/bin/phpunit tests/Unit/ProductionIdentity/ExplainSharedMaterializationTest.php`
plus existing `ExplainWindowIdsTest`, `ExplainFailureObserverTest` and
`CatalogGroupContractTest`. After focused checks and independent review of the
actual tested candidate, ROOT can select the existing
`CatalogGroupDifferentialTest::test_native_window_relations_are_materialized_and_shared_between_headers_and_rows`
on private Oracle MySQL 8.4.11 within the original bounded runtime envelope.
This method emits minimized diagnostic STDERR; its runner needs an explicitly
selected diagnostic contract, not the typed-only adapter's empty-STDERR rule.
That result still does not close the remaining native correctness/currentness,
direct/Domain/migration/ledger/restart/race or cost gates.

## A2 internal anonymous-frame correction — 2026-11-02

The physical selection at `f3785b581028035e865985796367f858a9023e0a`
failed at 1 test/104 assertions on the global one-anonymous-definition rule.
ROOT's bounded observations found the selected wire plus one internal anonymous
definition in each of proven families 1 and 2; none were outside or nearest to
gate/header/family 0 roles. The extras had no windows or protected IDs/references.
That original failure remains; the observations do not accept a corrected source.

The helper now permits **zero or one extra per family 1/2**, classified by the
nearest strict ancestor among all seven proven roles. Every extra subtree must
exclude all windowing and every ID/reference belonging to those roles or the six
immediate wire operands. The selected wire remains unique at its exact path,
true-temporary and without its own window. Named-wire policy, SQL/recipe pins,
ordered header/row sharing, distinct roles, global windows and caps are unchanged.
Only helper/unit source and this note change; Feature, app and compiler do not.

The authored regression
`test_two_internal_anonymous_frames_preserve_the_proven_shared_wire_and_families`
contains both internal frames and is queued for ROOT to demonstrate old-helper
failure/new-helper success. Added negatives cover other locations, nearer header/
gate roles, protected role/branch IDs and references, windows, duplicate/nested
extras, named wires and selected-wire/crosswire boundaries. All earlier tests
remain. **PHP/lint/Pint/pure/native checks are UNRUN for this correction.** ROOT
owns those checks and the next bounded physical selection; no new runtime or
110/15/currentness/F9 acceptance follows from this source preparation.
