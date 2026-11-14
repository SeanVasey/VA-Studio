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

## Integration selection map — November 3

The new sparse integration branch composes immutable application source
`a9b48dafb64dbf34e3ca0397d1b5b17d56bb5bb0`, main
`04e45a0ace13b8f58bde93581c88f2d3489a409c`, the complete f378/02 oracle
changes and the c962 N7 receipt. No conflict resolution changed executable bytes.
Account domain/migration source remains a9b; N7 remains main04; the physical test
and helper remain `02f18b391e74fd78626d22e7821192c8198e1320`.
This composition has **no runtime acceptance yet**. ROOT owns runtime, discovery
regeneration, focused checks and independent review of the actual tested source.

Completed component evidence is carried, not rerun for metadata: a9b typed
1/1,081, cost1/8 (108 total SQL), memo1/8 (first31/hit14/full2), populated
up/down1/15 and overlap1/12 passed, each with six clean cleanup checks. The 02
helper/unit selection passed16/163 and the physical method passed1/880 against
a9b application bytes. Original expected3/actual2, cost133/110 and f378 physical
1/104 failures remain. The new regression fails the old helper as expected;
that control is not a positive pass or extra mutant kill. Observer qualification
passed only its exact permission/capability contract; `lease_release` still
awaits a verified product result.

The following is a finite queue, not a request for a full suite. All method names
below have the literal prefix `test_`. `U`, `G`, `C` name classes under
`tests/Feature/ProductionIdentity/`; their current Git blob hashes are:

| Alias / class | Exact source blob |
| --- | --- |
| U — AccountAdmissionGuardUpgradeTest | `f17ae7fcf74c5907cd0433bb9c8bb9012645aaca` (a9b) |
| G — CatalogGroupDifferentialTest | `16bb95120db58ded1710a9d4b391337eee654e6c` (02) |
| C — CurrentCatalogAdmissionTest | `7d76183ff1f6094c82eacdf212f5953b46d43993` (a9b) |

| Dependency / disposition | Exact remaining selectors |
| --- | --- |
| Qualified observer; product result pending | C::native_nested_boundary_reuses_one_companion_and_last_close_releases_server_session |
| Existing eight-key native queue; do not duplicate | U::native_early_repeatable_read_snapshot_refuses_new_reserved_namespace_on_memo_hit; U::native_same_connection_implicit_ddl_invalidates_witness_before_memo_reuse; C::native_companion_statement_accounting_matches_server_including_constructor_setup_and_context; C::native_primary_attribute_changes_invalidate_retained_companion_without_reconnect; C::native_failed_memo_ends_companion_and_restoration_uses_complete_unframed_proof; C::native_unframed_complete_proofs_refuse_new_namespace_under_early_primary_snapshot; C::native_raw_config_and_resolver_callbacks_refuse_before_factory_or_callback_execution; G::native_grouped_and_original_parent_entries_both_refuse_partial_and_foreign_guard_sets |
| Remaining migration refusal methods; no exact-a9b actual carried | U::existing_transaction_is_refused_without_trigger_changes; U::migration_only_dependency_floor_refuses_foreign_view_and_incoming_key_before_ddl; U::migration_only_scanner_accepts_legitimate_triggers_and_refuses_foreign_or_altered_dependency |
| Remaining native namespace methods; exact peer-schema privilege/ownership requires qualification | U::native_memo_rechecks_reserved_names_created_without_account_metadata_lock; U::native_trigger_dependency_qualification_refuses_target_and_allows_similar_named_peer — the latter CREATE/DROPs the owned-name `-account-guard-peer` schema and its triggers. Owned-primary-schema grants do not qualify it; never infer a global grant. |
| Remaining native group adversity; old mechanism/pure results do not establish these product methods | G::native_view_functional_unordered_indexes_unicode_defaults_aliases_and_global_definitions_match_original; G::native_warning_count_observes_prepare_execute_full_eof_without_sql_and_rejects_warned_transport; G::native_explain_note_policy_rejects_real_conversion_warning_and_preserves_production_zero_guard; G::native_i2_second_gate_preserves_positive_facts_and_emits_all_reserved_invalid_headers; G::native_i2_first_and_second_prepare_failures_invalidate_without_partial_result_or_reuse; G::native_oversized_complete_catalog_refuses_and_releases_failed_companion_preserving_primary_savepoint |
| SQLite only | U::sqlite_down_noop_still_refuses_a_missing_guard |
| Separate actual endpoint/TLS profile; outside the empty-output menu | C::native_coherent_endpoint_and_feasible_tls_downgrade_refuse_before_constructor_attempt; plaintext-only execution cannot credit its TLS subcase. Owned04 has no selected public CA export or certificate-to-admitted-IP proof. Canonical MYSQL_ATTR_SSL_CA with verify_server_cert=true may be selected only after that proof, with no verify-off, hostname swap or server reload. Require the original SELECTED_PRIMARY_TLS line plus JUnit; UNEXECUTED_NO_PRIMARY_TLS_PROFILE remains endpoint-only. |

Minimal composed-domain selections use existing source, unchanged from main04
(full source reference above). Prefix each method below with `test_`; paths are
under `tests/Feature/`. These are meaningful consumers of the changed account
proof, not a repeat of N7's unchanged cache-only controls or six mutants:

| File / exact Git blob | Selected method and purpose |
| --- | --- |
| ProductionMembershipHttp/MembershipCustomerRateLimitTest.php — `9369cad66a5911484cf49adb63ddd2be8870d817` | read_bucket_survives_ip_session_and_recovery_changes_and_expires; cache_callbacks_cannot_change_identity_or_steal_the_admission_lease_before_domain_work — real identity/member schema composition and callback refusal. |
| ProductionIdentity/ProductionIdentityJourneyTest.php — `0771d846f66f99e09a24806851b005ceba788360` | recovery_preserves_original_origin_and_historical_act_and_revokes_old_principal — direct writer/current/historical behavior. |
| ProductionIdentity/ProductionIdentityRuntimeTest.php — `97230fd61d225833144d4135ad5714e1e4a7f9c9` | current_and_original_historical_authority_refuse_every_owned_temporary_source_shadow — five existing provider cases; each exact target is selected separately. |
| ProductionFeatures/ProductionFeatureJourneyTest.php — `9d7e462ad527f359951a9644580913d84d8e1a3f` | unknown_initialize_grant_withdraw_and_repeated_withdraw_retain_production_pointer — consent/feature parent composition. |
| ProductionSuppression/ProductionSuppressionJourneyTest.php — `bca8273f624fefa8803ce880b566a32eac7eb389` | withdraw_attempt_committed_before_transport_unknown_without_resend_until_inspect_confirms — downstream suppression and retained consent. |
| ProductionAccountFeatures/ProductionAccountFeaturesDefaultConfigurationTest.php — `c17d15188fff66d420067a2f6142a20dbab83c25` | production_environment_refuses_every_feature_with_shipped_defaults — production-mode default-off refusal only; not a production-host/bootstrap compatibility claim. |

The source-only inner invocation is `php -d memory_limit=512M vendor/bin/phpunit
--filter '^.*::test_EXACT_METHOD$' tests/Feature/EXACT_FILE.php`, with those literal
placeholders replaced from this map. ROOT must bind it into the existing bounded
admitted runner; this is not permission for an unwrapped invocation. Data-provider
methods require an exact single provider selection. Native catalog composition
needs Oracle8.4.11; SQLite is not its substitute. SMTP fixtures require the existing
private loopback sink, and sparse files/dependencies must be materialized through
ROOT's existing runtime preparation. Keep method-specific output contracts: the
physical and cost cases intentionally emit diagnostic STDERR. The oversized
catalog case requires the qualified threads observer and a profile supporting its
8,388,609-byte view body (including max_allowed_packet); it emits
`overflow companion release samples: <JSON integer array>` and cannot use an
unmodified empty-STDERR contract.

Three concrete later gates are distinct from these selected regressions:

- **Native race:** unchanged `ProductionIdentityNativeRaceTest.php`, blob
  `6fe9cbeb99c5747a727ed8350d52d25831e43962`, selects
  `test_competing_completion_waits_on_exact_address_and_retains_one_verification`
  (four existing enroll/recover × same/conflicting providers), then
  `test_committed_credential_withdrawal_wins_exact_user_fence_before_recovery`.
  Its worker blob is `b7432df17d7243786ea605fb7217f992d6a96392`. Existing 35-second
  workers and 15-second exact-wait barriers remain; performance_schema threads,
  data_locks and data_lock_waits visibility must be separately qualified. The
  single threads SELECT grant is not proof of the other two.
- **Real migration ledger/restart:** existing separate candidate
  `b0bf7fd3cc9e7f842817830a20a49551f01ca4d1` prepares
  `AccountAdmissionMigratorTest::test_normal_migrator_process_interruption_and_genuine_ledger_resume`,
  blob `c55df3c281305b52a29d0913a087bfd5cb97d195`, providers up-overlap,
  down-overlap, up-end, down-end. It has source-fitness evidence only and is based
  on7347. Rebind/review its exact app/framework/callsite pins and genuine current
  fixture once; use its one-provider supervisor, not the generic command above.
  Do not import it silently or create a replacement harness. Direct coordinator
  calls in U's passing methods do not prove normal Laravel ledger interruption.
- **Account-only owner progress:** original member candidate
  `29ce932b7279953a011dd18041ddd4c1df66f306` contains
  `docs/verification/member-mount-gates-20261101/IdentityHistoryLockProbeTest.php`,
  blob `7f54cb963403ac12218addc073ad6167eec2a551`, method
  `test_diagnostic_account_lock_only_new_origin`. It is historical failed
  diagnostic source, not a ready selection: it changes PFS instrumentation and
  writes private traces. A bounded current-source adaptation must preserve real
  CompleteIdentity and prove both owner orderings without those unapproved side
  effects. Generic address-lock races cannot replace this account-PK gate.

The census appends exactly22 new native-only methods (U6/G9/C7) after main's
complete ordered prefix; the existing cost entry is retained and the SQLite-only
method is excluded. ROOT still regenerates discovery/counts. No production env
or config keys were added: the factory/descriptor/socket code uses existing
canonical connection fields. Harness selectors are not new production keys.
Query groups, catalog transport and the authorization migration still require
independent review of the final tested candidate; source comparisons waive none
of those gates. No Format F source, host activation, mount or live action is added.
