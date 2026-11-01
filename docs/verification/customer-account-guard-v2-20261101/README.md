# Customer-account admission v2 prerequisite

Base main `a7bc63cc5c5785353f13e882f49d0ab9dcff55b3`, excluding unaccepted member
paging/history trial. Approved design and byte-exact independent decision are in
`design/`; all P0/P1 conditions are acceptance requirements.

Own new closed account parent classifier and trigger upgrade coordinator,
IdentityMigrationOwnership and ConsentMigrationAdmission consumers,
SchemaProofFrame exact version pin, one new late migration, focused tests and
this packet. Historical migrations and fixtures remain byte-identical. No
identity/consent/account/audit/contract data rewrite; no mount change.

Implementation must admit only whole stable V1/V2 at runtime; migration-only
exact ordered overlap supports crash resume. Add complete new guard before
removing old, reverse before down; same PDO advisory lock, no active transaction,
bounded metadata lock, reinspect each DDL. SQLite verifies exact V1 no-op.

Serial test slot belongs to MIG. Initially source/static checks only; DB/native
and heavy checks require coordinator release. Scratch A/B is causal evidence,
never actual-domain acceptance. Native tests and independent actual-SHA review
remain required before push/PR/merge. Original member trial remains failed.

## Prepared implementation and limits

Source adds `AccountAdmissionGuards` (closed pure V1/V2 definitions and whole-set
classifier), `AccountAdmissionUpgrade` and late migration269000 (unused id at base).
V1 definitions are copied byte-for-byte in emitted output, with a queued reflective
comparison against unchanged historical migration. They do not invoke facades or
migration callbacks. Both existing inspector families choose their entire account
set from this classifier and retain their other independent checks. Runtime rejects
ordered overlap; explicitly named migration-only entry points admit only its exact
shape. SQL mode requires the standard six strict MySQL8 modes; creation charset,
connection/database collation and a common nonempty definer are checked. Migration
requires current creation inputs/definer equal retained guard environment, rather
than creating a guard with changed evaluation inputs.

Every MySQL SchemaProofFrame token now includes a freshly validated account guard
version/fingerprint; existing witness, session counters, base-table metadata locks
and identity dependent rechecks remain. SQLite's existing full main/temp catalog
token already binds exact guard bytes and namespace. This increases inspection
work; the unchanged native `IdentityInspectionCostTest` bound110 and downstream
latency/proof boundaries require measurement, not a source-only performance claim.

The migration additionally calls a MIGRATION-ONLY account dependency scanner:
foreign views/routines reaching this account table are refused, and incoming FK
schema/table/constraint/column/key/update/delete tuples must match explicit current
source-backed child definitions. Known child absence grants no authority to create,
adopt or repair it. Existing runtime identity dependent floors remain scoped to
their owned identity tables; this new migration floor is not claimed as universal
runtime parent dependency coverage. The shared runtime version classifier does
validate whole account table/columns/outgoing FKs/constraint count/indexes/shadows
and trigger namespace at every selected version boundary.

MySQL coordinator captures one PDO, refuses an active transaction, takes a 5-second
same-database advisory lock and uses session `lock_wait_timeout=5` for bounded DDL.
It reinspects both existing independent inspector families, the account-dependent
floor and exact version fingerprint before each DDL. CREATE full successor precedes
DROP predecessor in both directions. Connection id/database/advisory owner are
checked at boundaries; finally restores timeout and releases the acquired lock.
Failure is never ignored or converted into success. SQLite up/down both validate
V1 and dependency floors; no blind no-op.

Laravel remains sole migration-ledger writer: successful up permits ledger insert;
failed up leaves it absent. Exact overlap resumes after CREATE, stableV2 verifies
without DDL after DROP/before ledger insert. Failed down throws before Laravel
removes its row. StableV1 verifies/no-ops after V1 restoration/before ledger delete.
No code manually marks a partial prefix migrated. Process death releases advisory
ownership; unknown objects are refused after restart. These are implemented state
paths, still awaiting actual interruption/ledger/process-restart tests.

Compatible code must precede migration. Fresh000040 createsV1; older identity and
consent migrations inspect observedV1 before late269000 runs. Code rollback must
run the new down to V1 before old code. Populated down swaps only triggers and
reintroduces V1's known contention. Historical000040 and every other old migration
and historical fixture have no source diff. Historical rollback/dependency tests
explicitly restore V1 before mutating their original guard names; this does not
change migration output or conceal V2 tests.

### Static evidence, before serial DB slot release

Container `va-pcc-runtime-20261101`, user1000, PHP8.4.27; shared installed vendor
used read-only with explicit new source bootstrap, no vendor copying or DB boot.
Pint explicit ten affected PHP files: PASS (two test import/style corrections).
Pure `AccountAdmissionGuardDefinitionTest`: PASS2tests/8assertions, PHPUnit12.5.34,
0.001s. It checks only pure definitions, not database execution or acceptance.
Private receipts: `/workspace/account-lock-evidence/guard-v2-static`.
Initial lint caught a quoting error in new migration-only scanner; corrected
before final lint. An initial Pint `--dirty` ran from the container's default
workdir and checked0files; the explicit affected-file invocation supersedes that
non-evidence. No DB/native/schema execution, build, hosted CI or push occurred.

### Serial queued verification manifest — all DB items unexecuted

1. New `AccountAdmissionGuardUpgradeTest` prepared methods: fresh ordering and
   populatedV1 up/down with account/identity/audit/consent row snapshots, V1 emitted
   definition equality, repeated up/down; native ordered overlap forward/reverse
   with both ordinary inspectors refusing; active-transaction refusal; SQLite
   missing-guard down refusal; migration-only foreign view/FK refusal before DDL.
2. Existing `CustomerAccountMigrationTest`,
   `ProductionIdentityDependencyAdmissionTest`, `ProductionIdentityMigrationTest`,
   `NativeSchemaIsolationTest`, `IdentityInspectionCostTest`; focused
   consent/suppression/feature/membership/member-original admission and historical
   proof/schema-frame mutation tests. Must map exact selected methods/commands
   before execution, serialize with coordinator, and retain all failures.
3. Additional P0/P1 cases NOT YET IMPLEMENTED/EXECUTED: process-kill/restart and
   actual Laravel ledger interruption at each DDL/ledger boundary; metadata-lock
   timeout and advisory loss; malformed version names/bodies/ownership/order/
   creation environments; cross-schema dependent cases and every canonical child
   tuple rejection; frame memo mutation around version transition; full old/new
   admission composition and original contract-byte snapshots.
4. Native real CompleteIdentity generated-id/FK account-only control, both owner
   orderings and tiny/moderate/skewed/highest/new origins; held eligibility-user
   and quote-owner parent resources; first actual lock/trigger/SQL receipt. None
   has been run on this implementation. Stop on unexpected foreign wait.
5. Individual four-key/collation collision; simultaneous conflicting enrollment;
   explicit id0/default/generatedid; malformed/NULL/shape/eligibility/FK refusal;
   REPLACE/IGNORE/multi-row/upsert atomicity; immutable UPDATE/DELETE and
   active/version/overflow/timestamp guards. Existing historical tests provide
   selected coverage but do not replace native V2 execution of these cases.

No native actual-domain acceptance, latency/headroom, member-trial acceptance,
merge or mount activation is claimed. Independent actual-source migration review
and all required focused evidence remain open. No original member sweep restart.
