# Exact staged closed groups — design only

Frozen implementation7347a2b8. Actual133=90primary43companion remains FAIL;
memo first31/hit14/fullcallbacks2 remains separate PASS. Proposed straight-path
catalog reduction36→10=saving26, hypothetical133−26=107 with3statement margin.
This arithmetic is SOURCE CONDITIONAL, NOT a passing count/native trace. It
requires zero additional SQL for envelopes/headers/decoding and unchanged4/7
inferred primary/companion lifecycle overhead. Any extra SQL reduces the margin.
No construction/setup/context/witness/primary query moves outside its old window.

## Straight path and barriers

Each family below is ONE existing first materialization, not one query per name.
Current IdentityMigrationOwnership::named caches by enum SQL; lookups already
batch all names. A family reused later in its scope contributes ZERO extra savings.
Different inspector/classifier scopes are NOT merged because they share enums.
GROUPS.json is the exact SQL/column/bind/order inventory, grouped in actual call
order. A0 is unchanged singleton; the other nine IDs are proposed closed groups.

1. Outer inspectState lease constructs fresh companion (Questions/setup/native
   context/TLS) INSIDE measured inspect, then current entry checks. dependencies
   primary foreign_key_checks stays first. parentGuards enters account stable.
2. Account primary SELECT DATABASE remains; A0 account TABLE floor remains one
   query. Validate floor BEFORE its primary qualified SHOW CREATE table and four
   reserved-name negative SHOWs. Do not prefetch A1 across these primary facts.
3. A1 materializes AccountTables,four namespace classes,trigger definition,index
   at first AccountTables call AFTER SHOW loop. Seven→one saves6. Consumers still
   validate namespace, exact whole V1/V2 trigger environment/order/body and indexes
   in original order, no intermediate primary SQL. No classifier proof cache.
4. Account mysqlFloor primary foreign_key_checks remains a barrier. A2 columns,
   outgoingFK,count materialize AFTER it. Three→one saves2; exact column/default/
   index/FK/constraint semantics remain. Complete stable version/fingerprint
   returns normally, then parent namespace selection uses that exact version.
5. P0 six parent namespace families materialize before original namespace-name
   SHOW loop. Six→one saves5. Original per-name table/trigger/routine/event/index/
   constraint checks and all primary positive/negative SHOWs remain in their
   original order. No P1 data is prefetched before that loop.
6. P1 first table/columns/index/FK/constraint-list materializations occur for FIRST
   parent `users`, after all namespace SHOWs. Five→one saves4. `users` foreign
   expectations are empty, so no database() call occurs in this first iteration.
   FK and constraint families already batch ALL three parents at that point in
   existing code. The FIRST uncached database() is only in second iteration
   customer_accounts foreign expectations, AFTER all five families materialized.
   Preserve that primary SELECT DATABASE at its exact position, not eagerly at
   group build. Existing later customer_accounts/quote_owners reads were already
   batch-cache hits. Original type/index/shape validation order remains.
7. P2 required-trigger bodies and table-trigger-name lists first materialize AFTER
   all parent shape validation and that primary database() call. Two→one saves1.
   Do not merge P2 into P1 across its primary database authority barrier.
8. I0 identity table+trigger namespaces materialize adjacent BEFORE first identity
   primary SHOW; two→one saves1. Loop parent+identity SHOWs stay at original place.
9. I1 columns/index/FK/CHECK families first materialize after FIRST identity table's
   successful primary SHOW/floor, with all identity-table names exactly as existing
   named() batching. Four→one saves3. Their intervening operations are pure
   validation; database() used by FKs is ALREADY cached by P1. Subsequent identity
   primary SHOWs remain at old loop positions; no extra cross-SHOW reuse beyond
   the already-existing per-family name batches is introduced.
10. Original negative SHOWs for identity guards/reserved names and trigger-body
    checks remain. I2 reserved index/constraint names starts AFTER those validations.
    Two→one saves1; no merge with I0 before SHOW/owned-table validation.
11. D0 global trigger/incomingFK/view/routine scans at end: four→one saves3 ONLY
    in full inspect, where database() is already bound by P1. Keep exact schema
    qualification/dynamic-SQL/definition-null refusal. DO NOT use D0 in memo's
    assertNoDependents: it resets database=null and can have a real primary
    database read between first trigger and later families. Memo14 density and
    existing four statements+unpinned six-branch UNION remain UNCHANGED.
12. Primary populated-row probes, missing-prefix consistency and return stay.
    Outer current exit checks/last-holder transport closure stay inside measured
    window. Frame full+terminal callers still run complete path; no moved warmup.

Capacity: A1=6,A2=2,P0=5,P1=4,P2=1,I0=1,I1=3,I2=1,D0=3 =>26.
Accounting actual133 only establishes oldTOTAL; static schedule's36catalog and
7companion lifecycle is consistent with43, not independently traced attribution.
Refusal branches can stop before complete groups; no alternative partial schema
is adopted. Missing objects and malformed result retain original fail-closed
checks; no attempt to get a passing number by removing negative paths.

## Fixed binding/consumer ownership

No caller-supplied SQL/family list/schema/decoder/expressions. Private source-built
plan selected by fixed group ID and fixed source-known version V1/V2/overlap where
existing migration-only classifier permits it. Group authorization does not allow
runtime overlap. Original single CatalogRead APIs stay closed. Constructor,
CatalogLease/context lifetime and native admission stay unchanged.

A1 names=[oldinsert,newinsert,update,delete]; reserved=[customer_accounts,...names].
AccountTables binds names4; namespace each binds reserved5; trigger binds
[customer_accounts,...names]5; index binds customer_accounts1. A2 each binds
customer_accounts1. A0 likewise1. P0 names are EXACT existing namespace keys:
users,customer_accounts,quote_owners then selected source guard keys in their
existing insertion order. P1 names=[users,customer_accounts,quote_owners]. P2
bodies names=existing selected guard keys; trigger-list names=those three parents.
I0 namedTables names=[parents,...IdentitySchema definition keys,...identity guard
keys,...reserved unique/FK keys]; namedTriggers uses existing
[...reserved,...identity guard keys,...identity table keys]. I1 names=IdentitySchema
definition keys, unchanged source order. I2 names=original flattened unique/FK
reserved array. D0 has no binds, same global scope. Existing identityPlan duplicates
name bindings exactly as before (initial lookup name filter then per-name relation
projection); original GROUPS.json bind arity is single-family precompile arity,
NOT the final batch placeholder count. All compiled bind arrays must be golden
compared against existing identityPlan for each family before wrapping.

Public-signature inventory remains MembershipSchemaCatalog named/rows in exactly
two consumers MembershipSchema/BillingSchema (nine calls each), as retained in
previous packet CALLER-CONTRACT-AUDIT.txt. This design DOES NOT change those APIs,
Billing batching, Billing raw I_S scope, installer or DDL lease. New grouping is
only inside account/IdentityMigrationOwnership scopes; all other CatalogLease and
CurrentCatalog consumers retain existing enum semantics/lifecycle. Any future
signature change requires another complete transitive audit, not inherited trust.

## Ordered-cell wire contract (proposed, unimplemented/unproved)

Single native prepared statement per group; fixed UNION ALL of identically typed
TRANSPORT columns only. Original heterogeneous columns are NEVER directly UNIONed.
Each original compiled SELECT is a derived source with unchanged predicates,
comparison operands/collations/bind order; original ORDER BY becomes explicit
row ordinal ordering over the exact original order keys. Required hidden order
keys may be selected privately but are not added to returned PHP rows. For any
nonunique original order, no extra tie-breaker is invented: equal-key multiplicity
is preserved, and tests assert only order guaranteed by original SQL. Consumers
with strict ordered-array assertions require proven unique ordinal keys or the
group is NOT supported until resolved. No JSON_ARRAYAGG input-order assumption.

Wire has fixed group/family/name ordinal, kind H(header)/R(row), row ordinal,
and JSON ordered cell array. Each cell is [type-tag,value]: S,string; I,canonical
base10 integer text; N,JSON null. All values, including numeric-looking defaults,
Unicode/definition text/empty string remain exact. Numeric metadata is converted
on server to canonical integer TEXT only for fixed schema-known integral cells;
no numeric JSON float, scientific notation, leading+ or inferred numeric string.
PHP accepts I only with exact canonical decimal grammar and native integer range,
then verifies round-trip representation after conversion; S never casts. No
unsigned overflow silently becomes float/string/int. Null only where explicitly
allowed; strict NULL stays different from empty string/absent cell. Column keys
are rebuilt from the FIXED per-family ordered projection in GROUPS.json, never
JSON object order. Native PHP keys/insertion order/cell types must strictly match
original PDO::FETCH_ASSOC. JSON cells must be exact arity2; outer ordered array
must have exact column arity. No unknown tags/keys/options accepted.

One mandatory H record per expected family/name contains exact row count; zero
rows is H(count0), not absence. Header is produced in SAME statement from the
same materialized family relation as rows. Requires demonstrated MySQL8.4
materialization once: no header-vs-row duplicate subquery evaluation ambiguity.
If optimizer cannot guarantee that, this proposed transport is BLOCKED (no extra
silent per-group header query). R ordinals must be exactly1..headercount with no
duplicates/gaps/unknown family or name. Fixed outer ORDER BY group/family/name/
headerbeforeRows/ordinal reconstructs required order; unordered-family transport
ordinal is bookkeeping, not invented original semantics. Exact header counts,
family/name inventory and returned-field grammar reject missing/duplicate rows.

Reject invalid UTF8/JSON/depth/oversized/truncated envelopes, unknown group,
duplicateheader, wrongfields/types/nulls/ranges/counts/ordinals. Do not repair,
normalize, skip rows, fallback to old stale I_S or truncate. SQL warnings (including
JSON/conversion/packet warnings) must not be hidden; design needs exact warning
provenance compatible with no added SQL allowance. PDO exceptions fail closed.
If warning detection requires an extra statement per group, theoretical107+9=116
FAILS and this design is insufficient; that unresolved cost must be reviewed,
not assumed away. SQL strict-mode JSON guarantees may cover some conversions but
need exact Oracle8.4.11 source/native evidence before claiming no warning query.

Proposed finite support caps: group<=16MiB total encoded bytes, each cell<=8MiB,
rows<=65536perfamily/name and <=131072group; existing caller bind<=1024 and
stringbind<=4096 remain. Caps MUST refuse complete result, never SQL LIMIT/scope
filter/truncated checks. These caps newly reject legitimate very large global
catalogs/routine bodies; support limitation explicit. Neither old complete scope
nor namespace safety can be reduced to satisfy caps. Server max_allowed_packet,
JSON string limits/connection charset and memory bounds must be proven compatible
or lower deployment support stated. Group budget cannot justify invisibility.

GROUPS.json types/nullability are exact DESIGN schema declarations inferred from
original projections/native I_S contracts and existing strict consumers. They are
NOT newly executed PDO observations. Any schema-native discrepancy requires
reviewed schema-contract correction and original-vs-batch differential proof,
not permissive decoder widening. In particular column defaults/nulls, SUB_PART,
ordinal/count/nonunique integers and definition-null visibility need native proof.

## Meaningful future differential and adversarial plan (UNRUN)

Separate disposable scratch schema, exact7347 original enum/compiler versus new
fixed group at identical stable fixture state. Both use SAME supported principal,
roles/server/schema/session and native PDO semantics. Compare strict typed arrays
with associative key insertion order for ordered/singleton families; for unordered
families compare exact typed-row multiplicity without discarding duplicates.
Capture original and group Questions/setup overhead separately. No business auth
on companion; no production data, grants, global settings or old migrations edit.

Positive original-vs-group cells: all ten group schedules; V1/V2/exact migration
overlap (runtime rejects overlap); empty/nonempty namespaces; numeric-looking,
leading-zero/empty/default text; NULL default/definition; Unicode/accent/case/PAD
native alias projections; composite indexes/FKs and ordinal>9; int count/type,
text/newline/quote/backslash/routine bodies; legitimate bounded large rows. Native
original result types are baseline, not guessed PHP fixture types.
Negative pure transport cases: missing/duplicate headers or family/name, count0
vsmissing, duplicate/gap ordinal, extra/truncated/corrupt JSON, invalidUTF8, null
inrequired cell, string-in-int/int-in-string, overflow/negativewhereforbidden,
unknown tag/arity/column/case/key order, caps+1, crossgroup/mixedversion packet.
No caller callback/descriptor/SQL/expression can enter compiler or decoder.

Timing/adversarial native stages: independent publisher before/after each retained
SHOW/FK/database barrier; primary TEMP aliases; external reserved trigger/view/
routine/event/index/CHECK/FK changes; held account MDL with witness/session
mutation/implicitcommit; full/memo/terminal and unknown metadata privilege/error.
Prove later-stage groups never answer from earlier bookend. Failure/noSQL/release
checks and real DDL wait resources remain distinct from scratch equivalence.

Only after exact-source review+these native differentials may unchanged full110
run at fresh cold capability window, all constructor/setup/context accounted;
unchanged memo15/terminal and early-RR must remain green. No native run/profiler
or production/test code is authorized or executed by this design packet.

## Remaining blockers to implementation choice

>=23 gross capacity is structurally plausible (26) with parent-stage barrier
resolved on source. NET>=23 is NOT established until transport encoding/header
single-evaluation/warning/ordinal correctness and zero-extra-SQL claims are proven.
Three-statement margin cannot absorb pergroup warning checks. If that cannot be
shown safely, report design insufficient rather than lift110/remove primary facts.
