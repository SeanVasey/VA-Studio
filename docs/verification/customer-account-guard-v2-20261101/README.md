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

## Consolidated repair after independent source review

Source `ad864105` received REQUEST CHANGES with two confirmed P1 findings; the
byte-exact decision is copied under `design/independent-source-ad864105-decision.md`.
No P0 guard-removal finding was reported. One local consolidated repair addresses
both findings before another source review, push or native run.

The earlier paragraph describing full account classification on every MySQL token
is superseded: full stable account version/fingerprint is now captured ONLY while
a full proof is running after account-table pinning in the exact physical
transaction. Each remembered guard retains that captured state beside its existing
witness/session/counter token. Ordinary memo hits do not call the full account
classifier. They retain the original identity dependent scan and add one bounded
targeted UNION catalog statement for old/new reserved names on non-account tables,
foreign triggers, routines, events, indexes and constraints. Another connection
cannot alter/drop/create the account table's triggers, columns, indexes or FKs
under its held MDL. Same-connection persistent DDL implicitly commits and loses the
physical witness; temporary shadows move the existing counters. A moved token
causes full proof again, and terminal() clears both the memo and captured versions
and continues complete proofs. SQLite's full catalog token is unchanged.

The migration-only account dependent floor now scans actual triggers on BOTH
engines. MySQL reuses the existing precise selected-database qualifier and dynamic
SQL handling; unqualified same-named peer schemas do not become dependencies.
A reaching trigger is admitted only for the selected exact schema plus exact
source-backed name/table/BEFORE event/body/action-order tuple. Canonical names in
that selected schema are checked even if a mutation removes their account
reference altogether. NULL/unreadable definitions refuse. SQLite checks exact
canonical stored SQL and owner table in main; foreign main/temp account-reaching
triggers refuse. No ordinary runtime dependency contract was broadened.

Canonical dependency definitions come from fixed existing source factories:
identity schema guards; unchanged legacy challenge/purchase/notification and
consent migration definitions; preference suppression and production feature
trigger builders; production membership/billing/member-original/free-grant
builders; unchanged credit-foundation conditions reconstructed with its exact
DDL-emission format without invoking its writer; and the selected account guard
set. This is a fixed source allowlist, never a copy of installed trigger bodies.
All original source/fixture bytes remain unchanged. Native/SQLite actual stored
SQL agreement for these factories remains an execution gate.

Added focused test SOURCE (all DB legs still UNEXECUTED):

- Fresh legitimate composed trigger dependencies pass their migration-only floor;
  a new foreign-table account-reading trigger refuses; an altered canonical
  consent trigger with its account predicate removed still refuses (both engines).
- Native exact frame captures V2 once, compares actual Questions counters for full
  versus remembered proof, asserts hit<=15 and less than half the first proof,
  and proves terminal callback runs again. Original native inspection bound110
  remains unchanged and queued.
- Native independent-session table/routine/disabled-event/foreign-trigger/unique
  reserved-name insertions after a remembered proof refuse at the next boundary.
  Like existing frame dependency tests, that dictionary-visibility case explicitly
  uses READ COMMITTED as a test input; production isolation is untouched. It does
  not establish REPEATABLE READ catalogue visibility or actual-domain isolation.
- Native peer-schema trigger controls distinguish unqualified peer references,
  qualified similarly named peer database references, and explicit references to
  the actual target database. The test owns a newly absent synthetic peer schema
  and removes only that schema afterwards; no such DB test has run yet.
- Pure no-DB unreadable dependency definition refusal supplements the earlier
  definition tests. This is not a live privilege-restricted metadata test.

All earlier ledger/process interruption, metadata/advisory failure, composed
admission, native originalguard/collision/real enrollment/resource and row/contract
snapshot gates remain open. Added test source is not actual execution evidence.
No source review, native behavior or member headroom acceptance is inferred.

Repair static receipt: PHP8.4.27 lint5 affected PHP files PASS; Pint `--test`5
PASS; pure unit3tests/9assertions PASS (0.001s). `git diff --check` PASS.
Exact command for the pure run (explicit own source bootstrap, shared vendor
read-only, no application/DB boot):

```sh
docker exec --user 1000:1000 --workdir /workspace/VA-Studio-account-guard-v2 va-pcc-runtime-20261101 php /workspace/VA-Studio/vendor/bin/phpunit --no-configuration --bootstrap /workspace/account-lock-evidence/static-bootstrap.php tests/Unit/AccountAdmissionGuardDefinitionTest.php
```

Private final repair receipts: `/workspace/account-lock-evidence/guard-v2-repair-static/{lint,pint,unit}.log`.
Copied review decision SHA256 matches original:
`45f2e5fe6408ba2b36084d8885d3229209f74beba8f1d8728c09c99c84deda33`.
No DB lease has been received and no DB test or DDL has run on this batch.

### Integration test census manifest (source only)

New Unit class `AccountAdmissionGuardDefinitionTest`: all3 methods run without
engine skips: `test_mysql_v2_changes_only_the_named_insert_collision_expression`,
`test_sqlite_has_only_the_retained_v1_contract`,
`test_unreadable_stored_dependency_definition_refuses_closed`.

New Feature class `ProductionIdentity/AccountAdmissionGuardUpgradeTest`:9 methods:

| Method (without test_ prefix) | Engine condition / exact skip reason |
| --- | --- |
| fresh_ordering_and_populated_up_down_preserve_old_definitions_and_every_retained_row | Both; no skip |
| existing_transaction_is_refused_without_trigger_changes | Both; no skip |
| migration_only_dependency_floor_refuses_foreign_view_and_incoming_key_before_ddl | Both; no skip |
| migration_only_scanner_accepts_legitimate_triggers_and_refuses_foreign_or_altered_dependency | Both; no skip |
| exact_interrupted_overlap_is_migration_only_and_resumes_in_both_directions | driver!=mysql: `Only MySQL uses overlapping trigger DDL.` |
| native_account_frame_captures_once_reuses_cheaply_and_proves_terminal_in_full | driver!=mysql: `Physical account MDL and native query counters.` |
| native_memo_rechecks_reserved_names_created_without_account_metadata_lock | driver!=mysql: `Independent native namespace changes outside account MDL.` |
| native_trigger_dependency_qualification_refuses_target_and_allows_similar_named_peer | driver!=mysql: `Native cross-schema stored trigger resolution.` |
| sqlite_down_noop_still_refuses_a_missing_guard | driver!=sqlite: `SQLite-specific verified no-op.` |

Expected source counts: SQLite feature5run/4native skips; MySQL feature8run/1SQLite
skip; pure unit3run on either. No new environment key or execution flag. No shared
census, workflow or ledger edit; coordinator must union these new methods into the
final integrated candidate and regenerate native selection/counts. These source
counts are not executed test results.
