# Bounded account-admission diagnostic/design

Base member source: `29ce932b7279953a011dd18041ddd4c1df66f306`.
Own only this packet and its scratch probe. No application repair is authorized.
Original member trial remains failed/unaccepted; mount remains literal false.

One serial A/B scratch experiment on the already owned Oracle MySQL 8.4.11
server isolates original BEFORE INSERT collision expression against four separate
unique-index equality EXISTS expressions. Both arms use identical synthetic rows,
account primary record owner lock, session isolation, complete original insert
trigger predicates and account columns/indexes. Scratch account foreign keys are
omitted and users is an eligibility-only table: this isolates the collision query,
not FK/domain acceptance. No existing schema, trigger or global setting is changed.
Capture first PFS wait and trigger event context, exact source/version/counts and
valid insert plus collision/REPLACE refusal controls. Stop after this batch.

Acceptance is diagnostic attribution only. A split-query pass cannot accept the
actual domain/member frame, prove race freedom, authorize guard weakening, or
resolve the separate history supremum blocker. Final forward migration design
must receive independent sensitive review before any application/actual-domain DDL.
Private raw receipts: `/workspace/account-lock-evidence`.

Initial probe invocation at `470f9d82` failed before DDL: Docker exec lacked
`-i`, so mysql received no stdin and SHOW CREATE output was empty. No native
control ran and no scratch database was created. Corrected stdin transport only.

## Actual bounded receipt

Executed clean probe SHA `db14259302286681ed12b129a012efb772426528`:

```sh
python docs/verification/account-admission-locks-20261101/probe.py > /workspace/account-lock-evidence/run.log 2>&1
```

Exit 0. Oracle MySQL `8.4.11`, default `REPEATABLE-READ`; unchanged session
SQL mode `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`.
No global/session optimizer, isolation or instrumentation changes. Docker mysql
client connects by local socket as synthetic root, no password or external key.
Host load sampled immediately afterwards: `0.12 0.04 0.01`. No PHP involved.
Each arm starts with one account and three eligibility users; each valid control
ends with two accounts; cleanup leaves one synthetic account in the owned scratch
DB `account_admission_ab_20261101_sol61`. Scratch DB retained for review; no DROP.

| Arm | First actual resource | Distinct insert while owner lock held | Positive/refusal controls |
| --- | --- | --- | --- |
| Original exact installed insert body | PRIMARY record1: requested S versus granted X,REC_NOT_GAP | 1205 timeout, 3.082677s | Valid insert succeeds; id/public_id/user_id/owner_key collision and REPLACE each 1644/45000 |
| Same body, collision only split into four FORCE INDEX equality EXISTS | No wait sampled | Succeeds, observed 0.106496s | Same valid success and all five 1644/45000 refusals |

PFS captures original worker thread2029 actual top-level INSERT SQL and nested
`statement/sp/jump_if_not`, `OBJECT_TYPE=TRIGGER`, scratch
`customer_accounts_insert`, event6 nesting5. Inner SQL_TEXT is NULL. Owner
thread2027 holds exactly PRIMARY1 X,REC_NOT_GAP; worker waits S on PRIMARY1.
This strengthens causality beyond EXPLAIN: only collision expression changes,
all other insert predicates remain identical, no FKs exist in either arm, and
both owner1 and worker2 have distinct keys. The original self-table expression
therefore introduces this synthetic cross-account read dependency; split indexed
seeks avoid it in this bounded case. The timing includes client/poll overhead.

The DDL is read from actual installed source table, retaining engine, columns,
nullability, timestamp types, collation and all four unique indexes; source
AUTO_INCREMENT counter is removed and both FKs omitted. Users is a minimal
three-column eligibility table without its real triggers. Scratch omits account
UPDATE/DELETE guards to allow synthetic cleanup; they are never candidates for
product removal. Inserts use explicit positive ids rather than domain-generated
auto ids. No Laravel/PDO, domain admission, real quote-owner dependency,
registration fixture, identity history or member proof frame is exercised.
Source guard and both exact tested bodies, source DDL, all captured locks/events,
commands and refusal messages are in the private JSON. No EXPLAIN was required
or used as acceptance. This is a single two-arm run; no cardinality sweep/retry.

Receipt SHA256 `8c865094aa70036573efb81d0b798526cc46479bab6567dac3b7bef0bce8fc73`.
Run log SHA256 `f2448b54b4ace3394ae4d62d2302530b833cb5af52f6877ebd3750e9f8ba34f0`.
The failed transport-only invocation's traceback is described above; its log path
was reused for the actual run, so no preserved raw traceback artifact is claimed.
Native slot released to coordinator after this batch. No extra DB run authorized.

## Proposed forward repair contract — design only

Add a new, later migration and a closed account-parent guard definition/admission
class, e.g. `AccountAdmissionGuards`, with immutable explicit v1/v2 definitions.
Never edit migration `2026_10_06_000040_customer_accounts.php` or historical paid
fixtures. V1 retains exact original bytes. On MySQL only, V2 changes the INSERT
collision predicate to the four separate EXISTS tested in `probe.py`, each using
its exact existing unique index (PRIMARY, customer_accounts_public_id_unique,
customer_accounts_user_id_unique, customer_accounts_owner_key_unique). Missing or
altered index is refusal, never a substitute plan. V2's trigger name is
`customer_accounts_insert_v2`; UPDATE and DELETE stay at their existing names and
bytes. SQLite can remain V1 (no measured SQLite locking defect); the new migration
strictly verifies V1 and is a verified no-op there, with matching no-op down.
Avoid creating an unneeded cross-engine trigger rewrite contract.

The four equality predicates preserve original collation/equality/NULL semantics:
EXISTS over a disjunction is true iff any of the four EXISTS is true, including
NULL comparisons producing no matching row. Keep both native unique constraints
and explicit pre-REPLACE collision refusal. No LIMIT, lowercasing, binary cast,
IGNORE, alias-dependent fallback or omission of id=NEW.id. Keep exact shape,
active=1, access_version=1, nonnull timestamps and verified nonadmin user
eligibility predicates; keep immutable identity/created_at update checks,
access-version progression/overflow refusal, timestamps and delete retention.
No FK/index/table rebuild, row rewrite, audit deletion, reenrollment or contract
regeneration. All original identity rows and old contracts remain valid.

### MySQL DDL safety and crash-resume

MySQL DDL auto-commits. Do not pretend a wrapping transaction makes a trigger
swap atomic. Use additive overlap so a full guard is always present. Serialize
this migration with a connection-scoped named advisory migration lock derived
from the exact database (no runtime/env key). This serializes cooperating
migrators; it does not replace table metadata validation or claim protection
against an unrelated privileged DDL actor. Refuse active application transactions
on the migration connection. Retain metadata-lock timeout failure; never kill
another session to force progress.

Recognize only these byte-exact states, including canonical namespace, permanent
InnoDB table/columns/indexes/FKs, no temporary shadow, unchanged other parent
guards, and no foreign triggers/routines/views/dependencies:

1. V1: old INSERT plus unchanged UPDATE/DELETE.
2. Transition: both complete old INSERT and exact new INSERT_v2, ordered
   INSERT_v2 FOLLOWS old INSERT, with no other account trigger. Only the migration
   state classifier accepts transition; ordinary runtime admission rejects it.
3. V2: INSERT_v2 plus unchanged UPDATE/DELETE (INSERT action order now1).

Up from V1 creates full INSERT_v2 FOLLOWS old INSERT, verifies exact transition,
then drops only the verified old trigger, verifies exact V2. No insert can see
zero complete guards. Old broad lock remains until its removal; do not count
transition as repaired operation. Crash after CREATE resumes from transition;
crash after DROP resumes from V2 and verifies without recreation. Crash before
CREATE leaves V1. A v2 name on another table, mismatched body, extra/partial
objects or absent parent guard refuses before DDL. Reinspect before each DDL;
never adopt/repair a near-match or silently drop foreign objects. If DROP fails,
leave both guards and refuse runtime admission until controlled resume.

Down must preserve populated identities, so reverse the trigger transition only:
from V2, create the exact original INSERT PRECEDES INSERT_v2; verify transition;
then drop only exact INSERT_v2 and verify V1. From transition it can verify and
remove v2. From V1 it verifies/no-ops. At every interruption a complete guard
remains. Down changes no rows or other triggers and reintroduces the known broad
locking behavior explicitly; it does not claim a performance fix. Old migration
down remains byte-exact and refuses V2 until the new down restores V1; populated
identities continue to refuse destructive historical rollback. Old binary runtime
will refuse V2, so application-code rollback requires restoring V1 first. If newer
code requires V2 later, its rollback order must be specified in that release.

Install compatible admission code before invoking migration: admit only complete
stable V1 or complete stable V2 (SQLite V1), reject mixed sets in runtime, expose
selected version internally to focused tests. Migration resume uses the separate
strict transition classifier. Do not rely solely on migration ledger rows,
`class_exists`, a loose OR of trigger bodies, or a new configurable setting.
Fresh installs run old history unchanged then the new forward migration. Existing
populated installs gain only the new guard; unrelated schemas are never adopted.
Any current schema proof caching/frame must bind the exact accepted guard-set
version and namespace; no memoized V1 proof may authorize V2/transition DDL.

### Ownership and compatibility inventory

Repository search at base SHA covers `customer_accounts`, exact trigger names,
`IdentityMigrationOwnership`, `ConsentMigrationAdmission`, and reflective loading
of migration000040, excluding immutable `tests/Fixtures/paid-development` copies.
The following consumers must be reviewed together; changing one inspector is
insufficient:

| Consumer | Required treatment |
| --- | --- |
| Old migration000040 private guards/installedGuards/down | Keep bytes unchanged. New down restores V1 before old down; old up still owns initial V1 creation. |
| IdentityMigrationOwnership::parentGuards/dependencies | Use one closed version classifier for exact complete stable V1/V2 parent sets, preserving namespace/table/FK/index/dependent checks. |
| ConsentMigrationAdmission::dependencies (reflects old migration guards) | Replace fixed reflected account insert selection with the same closed version classifier; preserve all user/quote guards and exact trigger-count checks. Reflection alone would continue requiring V1 and reject V2. |
| Production identity migration247000; consent migration250000 | Their runtime-loaded inspectors must admit V1 during fresh sequential migration BEFORE new migration runs; admit V2 on existing populated dependency inspection. No history migration source rewrite. |
| IdentityRows, IdentityHistoricalPlainRows, SchemaProofFrame | Inspect via IdentityMigrationOwnership; preserve table/permanent/read-only proof and no-dependents contracts, exact-version frame admission and invalidation on DDL boundary. |
| ProductionFeatureSchema, ProductionFeatures/Suppression/ProductionSuppressionSchema | Consume ConsentMigrationAdmission; feature schema also consumes IdentityMigrationOwnership. Stable V1/V2 agreement is required, never inspector disagreement. |
| Preferences/Suppression/SuppressionSchema | Loads consent migration reflectively; verify nested dependency admission reaches version-aware parent selection without rewriting consent history. |
| MembershipSchema, MemberActivationHistoryInspection | Direct IdentityMigrationOwnership consumers; both stable versions allowed by compatible code, transition fails. BillingSchema/MemberGrantSchema and member raw/committed proofs must preserve their upstream dependency chains. |
| ProductionFreeGrantSchema::parentFloor | Column/permanent-table floor only, not exact account trigger authority. Keep its actual contract; no invented relaxed guard bypass. Check grant composed paths against identity admission. |
| Paid/PaidTax schemas, delivery schemas, read receipts; FreeGrantSchema; service project schema | Account column/FK or external dependency ownership checks; do not broaden their namespaces, dependency allowlists or snapshot hashes just to accept a new trigger. Test composed acceptance; any concrete incompatibility needs scoped review. |
| Saved-tracks242000, identity-challenges000045, purchase-claims000048, membership-credit230000, test-notifications231000, data-requests268200 migrations and associated readers | Account FK/data consumers; preserve old source and retained row semantics. Verify they do not gain parent-object repair authority through the shared classifier. |
| ProductionCustomerAccess, request/complete/notice workers, consent/listening evidence, checkout admission/raw plans, membership/grant rows, customer reporting | Runtime account data/identity proof consumers; no caller bypass or changed account serialization. Exercise affected composition through upstream version admission. |
| MigrationDefinitions + immutable paid-development source fixtures | Generic reflection loader and frozen old contracts remain unchanged; historical fixtures keep their V1 definitions and are not used to manufacture V2 acceptance. |

Fresh migration sequencing is explicit: old000040 creates V1, later identity247000
and consent250000 load the compatible inspector and accept observed exact V1,
then new late forward migration changes to V2. An existing database with identity,
consent and retained evidence accepts its observed exact V1 before upgrade and
exact V2 after. Version is selected from observed whole guard-set bytes, never
from whether the new migration file/class exists. Runtime overlap is rejected;
only the exact recognized migration prefix resumes. A missing old guard plus an
arbitrary v2-looking body is not a stable version, nor is a partial parent schema.

### Required separate review and future verification

No source implementation or actual-domain DDL has occurred. Separate non-author
sensitive design review must approve the version classifier, overlap resume/down
state machine, namespace/dependency inventory and preserved trigger predicates
before implementation. The scratch evidence does not authorize implementation.
Then commit scoped implementation and run proportionate focused checks:

- Fresh full migration ordering on SQLite/MySQL; populated V1 forward/down;
  resume after each DDL boundary; V1/V2/transition exact sets; foreign case/name,
  temporary shadow, altered body/owner/order/index/FK/collation and missing guards
  fail closed. Inspect old migration rollback before/after version restoration.
- Both exact inspector families and composed identity/consent/feature/suppression/
  membership/member-history paths at each stable version; retained historic paid,
  original contracts and audits unchanged. No unconditional trigger-count bypass.
- Native real CompleteIdentity account-only owner-lock control at actual repaired
  source, then both-owner orderings, generated ids, collision on each key,
  REPLACE and concurrent conflicting insert refusal, update/delete retention,
  malformed shape/eligibility/version/timestamp refusal and native FK checks.
  Stop on first actual resource wait, retaining SQL and nested trigger context.
- Owner-held user/quote-owner resources, full real schema and moderate/skewed
  cardinality must be checked separately; this two-row scratch experiment does
  not prove them safe. A split guard still legitimately serializes same-key
  collisions; native unique constraints/FKs remain authoritative race defenses.
- History supremum repair is independently blocked. Do not rerun the original
  complete member sweep or accept page/margin claims before both account and
  history blockers are independently resolved. Original timing, budget, locks,
  isolation, history density, enrollment rules and literal-false mount remain.

Current outcome: bounded causal diagnostic established; forward design concrete
but unreviewed/unimplemented. Original member trial and both actual-domain
controls remain FAILED; no actual-domain repair or member acceptance is claimed.
