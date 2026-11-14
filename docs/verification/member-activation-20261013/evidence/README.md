# C4c component receipts

This checkpoint preserves explicitly released component and root packets. It is not a
final integrated suite, application approval, hosted preflight or publication
receipt. The completed-readback SQLite and unmodified private MySQL 8.4.11
canaries cover actual confirmation, atomic activation, conservation, original
readback and registry cleanup. Shared-coordination native races now have
complete receipts: early activation 1/78 and both consume/release orders 2/129
pass at `3543b71f`. Corrected SQLite passes 17/176 at `4990315a`; the two native
parent test changes at `3543b71f` leave those selected application/test bytes
unchanged. Earlier-source write-denial and postcommit recovery passes remain
separate. The current coordination-source regression selection passes 5/970
with actual process/verifier 0 and unchanged 2,320-input source scope.
The original interrupted terminal runner retains real green 2/129 JUnit/log
but unknown parent process/runner exits and remains Partial. A separate full
focused rerun records actual exits 0; it does not rewrite the interruption.
The first current-source rollback/recovery attempt similarly retains real
green 5/970 JUnit/log with unknown original exits and remains Partial. Its
additive wording correction accompanies the original reconciliation. A durable
launcher now runs the unchanged recorder in its own OS session; the pure
14/27 smoke completed with actual process/verifier 0 after launcher return.
The separately supervised native five-case run supplies the complete passing
receipt; it does not upgrade the prior Partial.
Independent component
review requested selection-join and policy/display corrections; their separate
pure regression receipts are now included. Original component receipts remain
historical after those corrections.

| Released selection | Author source | Tests / assertions | Disposition |
| --- | --- | --- | --- |
| V3 and prior policy | a2e3b5ad | 82 / 201 | Pure pass |
| Authorization records | 3646d50c | 37 / 49 | Pure pass after test correction |
| Historical invoice and prior ledger | 3af459ff | 64 / 124 | Pure pass after test correction |
| Consume and required-history ledger | 3202ad04 | 75 / 139 | Pure pass after test correction |
| Added consume/cleanup method | 558e4ec2 | 1 / 6 | One selected method only |
| Activation records | 433b84f3 | 7 / 79 | Pure codec pass |
| Activation history | d0e81c46 | 8 / 39 | Pure codec pass after helper correction |
| Reader handles | feb05d22 | 5 / 16 | Pure pass |
| Reader queries | 33beeb02 | 18 / 34 | SQLite pass; earlier 16 / 32 retained |
| Installed-schema empty reader | feb05d22 | 1 / 23 | SQLite pass |
| Retained-original values/files | c8c76f82 | 30 / 82 | Synthetic local file pass |
| Existing original files | c8c76f82 | 40 / 157 | Existing file regression pass |
| Selection join and complete consume/history | aef13ed4 | 43 / 129 | Pure pass after intended old-source failure |
| Policy/display vocabulary | 05e66dfb | 32 / 89 | Whole v3 file pass after intended old-source failure |
| Near-capacity history and cleanup | 81b71cfc | 1 / 17 | Pure complete codec/ledger pass; 1 GiB process limit |
| Ordinary award commit and replay | 745ccddc | 1 / 15 | Actual affected SQLite case |
| Ordinary reservation, retry and award replay | 26b873de | 1 / 21 | Actual affected SQLite case |
| Operation ownership | 9af2f8f0 | 7 / 11 | Historical pure ownership pass |
| Ordinary finish closure | 30f7fc53 | 2 / 44 | SQLite pass after two old-Context overlay failures |
| Read environment | 02cefb83 | 4 / 9 | Pure pass after setup errors and actual regression failures |
| Read-helper ownership | 745ccddc + five-file overlay | 7 / 11 | Pure ownership pass; later source binding at 766569fc |
| Initial full activation selection | c2523e98 | 1 / 18 | Error before activation write; exit 2 |
| Corrected full activation selection | 3598015d | 1 / 57 | Actual SQLite confirmation, conservation and readback |
| Integration preparation | 4bc0e54c, 8a474163 | Unrun at preparation source | Syntax/Pint/whitespace only |
| Initial unmodified native activation | 3598015d | 1 / 18 | Preinsert error; process 2/verifier 1 |
| Native private grant-plan correction | 61bebdcf | 1 / 18 | Final committing error; process 2/verifier 1 |
| SQLite atomic capture integration/retention/phase | d25f160c | 13 / 328 | Pass |
| Native phase diagnostic | d25f160c + eight timing classes/helper | 1 / 18 | Error; diagnostic only |
| Private-batching ownership setup | 89cf40f0 | 8 / 9 | Invalid manifest fixture error |
| Corrected private-batching ownership | a47e0d69 | 9 / 13 | Pure pass |
| Private-batching SQLite selection | a47e0d69 | 15 / 407 | Lifecycle stale-balance expectation failure; last case unrun |
| Corrected ordinary lifecycle/honor | 00867c74 | 2 / 90 | Pass |
| Native private-batching positive selection | 00867c74 | 1 / 18 | Second/final after-I/O readback error |
| Corrected actual withdrawal oracles | 91581edb | 3 / 66 | Pass; hook observations asserted outside domain catches |
| Completed-handoff ownership | a24979ae | 11 / 17 | Pure pass |
| Completed-handoff SQLite positive | a24979ae | 1 / 59 | Actual graph/conservation/bytes/readback/cleanup pass |
| Unmodified MySQL 8.4.11 positive | a24979ae | 1 / 59 | Actual graph/conservation/bytes/readback/cleanup pass |
| Native write-denial rollback | 4c84b044 | 4 / 940 | Earlier-source pass; current five-case regression passed |
| Original race preparation | 4c84b044 | 1 / 13 | Failure before second worker/contention |
| Genuine early contention | 8f62c7cb | 1 / 33 | Failure: neither worker returned a receipt |
| Early race with sanitized locations | 72dbd1b4 | 1 / 33 | Completion proof failure; raw effects absent |
| Original consume-wins contention | 72dbd1b4 | 1 / 36 | Writer receipt failure; release-wins unrun |
| Native postcommit recovery | 8f62c7cb | 1 / 30 | Earlier-source pass; current five-case regression passed |
| Initial coordination SQLite | 67002abd | 14 / 166 | Pass before cleanup/cap corrections |
| Corrected cleanup/caps SQLite | 5a5790f1 | 14 / 166 | Pass before final replay-tail correction |
| Corrected final-tail SQLite | 4990315a | 17 / 176 | Pass; includes three pure classification cases |
| Native parent codec error | 4990315a | 1 / 60 | Writer succeeded/loser bounded; later parent verification unrun |
| Complete native early activation race | 3543b71f | 1 / 78 | Graph/conservation/bytes/recovery and bounded loser pass |
| Interrupted native terminal runner | 3543b71f | 2 / 129 | Real provider bodies complete; original exits unknown, Partial |
| Complete native terminal runner | 3543b71f | 2 / 129 | Separate pass with actual process/verifier 0 and metadata gates |
| Interrupted current native rollback/recovery | 3543b71f | 5 / 970 | Green JUnit/log; original exits unknown, Partial |
| Durable recorder smoke | 3543b71f | 14 / 27 | Pure pass after launcher return; no native acceptance claim |
| Complete current native rollback/recovery | 3543b71f | 5 / 970 | Separate pass with actual process/verifier 0 and metadata gates |
| Final whole affected SQLite files | 3543b71f | 14 / 348 | Integration3/99, retention5/116, confirmation phase6/133; actual0 exits |

Every passing PHPUnit invocation above recorded zero errors, failures and skips.
The Partial terminal and rollback/recovery entries keep null original process/verifier exits and null
original source stability. Its later fresh metadata comparison confirms the
inputs, head/status, runner and autoload still match; it cannot recreate an
uninterrupted original source-after observation or process exit. The index
preserves that distinction.
Selections overlap and span different source commits; do not add them as a unique
suite total. The historical seven-case preparation release supplies no execution or skip count at its own source; later root runs are separately attributed below.
Full 40-character sources, per-invocation exits/counts, exact commands, runtime
identities, source scopes and durations are retained through [index.json](index.json)
and the original receipts.

The final application-source review identifies one adjacent pre-existing P2:
an ordinary reservation-operation afterCommit delegate can throw after durable
commit before the operation records its committed flag, leaving a generic
refusal classification. The next related batch needs an exact owned commit
witness and a genuine throwing ordinary callback regression. An idle PDO is
not sufficient commit proof. The native member-activation postcommit case
does not execute that ordinary callback branch. This finding does not create a
successful false receipt or authorize automatic retry or compensation.

The packet retains the authorization assertion failure (2 / 10), historical
canonical-order failure (1 / 1), consume no-op mutation failure (20 / 47), history
helper fatal (exit 255, empty JUnit, no counts inferred), and initial Pint path
error (exit 255). Fail-fast leaves later selected cases unrun. The subsequent
passes do not erase those invocations. Formatting and setup are separate from
behavioral evidence.

The selection-join and policy/display supplements each preserve their actual old
source 1 / 5 intended assertion failure (exit 1, zero errors/skips). New selection
inventories capture the base test class and reflected fixture dependency; they do
not retroactively repair the earlier captures. The policy supplement records
1,445 Git inputs plus two observed generated bootstrap/cache hashes per run,
with matching source/runtime/autoload/runner observations before and after.

The ordinary finish release separately filters award and reservation regressions
with two preloaded historical Context classes; each fails 1 / 14 with one intended
assertion failure. Their working-tree inventory is the fixed source, while exact
old executable capsules are bound separately. The read environment release keeps
its initial 4 / 0 invocation with four setup errors, then 4 / 7 with three actual
regression failures, before the unchanged final test bytes pass 4 / 9. Root's
first full activation selection instead reports a runtime/provenance error before
writing, not an intended red assertion. Its separately released corrected SQLite
run passes 1 / 57 with 2,312 captured inputs and the runner, autoload, HEAD and
status unchanged. That success comes from the new run, not from relabeling the
initial error.

The near-capacity pure case creates 169 complete authenticated synthetic graphs:
66,846,934 raw member bytes and 37,341,078 decoded bytes. The next planned graph
would total 67,239,589 raw bytes and is refused. Subsequent reserve/expiry/release
cleanup grows 339 events to 342 while preserving member rows. The raw cap binds
first; the decoded cap is not approached. This run grants a 1 GiB PHP process
limit and reports 168.50 MiB peak; it is not a 512 MiB invocation, database write,
rollback, native-reader or performance claim.

The retained lane's `handoff.md` corrects the generic overlap phrase in its
unchanged receipts: the existing Files regression and Pint overlapped around
10:29:01–02 UTC. No performance claim uses those durations. Pure history uses a
synthetic sealed graph, not an actual ledger producer or current database
authority; its decoded-budget test directly sets a private accumulator and does
not prove a near-cap live graph. Reader source deltas are retained in the owner's
`source-carry.json`; its cross-source catalog is not one final-head execution.

Two capture limitations remain explicit. The seven history-core/reader bundles
omit the executed Laravel base `tests/TestCase.php`, so their captured named
inputs match Git but complete harness binding is partial. The original policy/consume runners
observe tracked source before and after, excluding a complete ignored/untracked
inventory; autoload/runtime/runner identities are captured before execution only.
No retrospective read repairs either observation. Installed dependency identity
is the recorded lock plus local autoload/package metadata, not a hash inventory of
every installed dependency byte. The two separate root ordinary cases use a
strengthened runner and each captures the complete declared 2,301-path source scope.
They prove the selected affected ordinary behavior, not member activation.

Historical read-helper preparation ran at observed `745ccddc` with five untracked
new classes whose exact bytes were later committed at `766569fc`. Its initial
Pint failure and five source capsules are retained. The command block regenerates
autoload metadata before ownership testing, so its beginning/end autoload maps
differ; it does not prove per-test runtime stability. Three scripted entry
refusals are not PHPUnit counts or successful authority. Separate environment
correction runs record unchanged source/autoload before and after, but identify
the external runner only before execution. Static preparation remains a historical non-execution receipt. Its later root canaries do not retroactively execute that preparation source.

## Storage and reproduction

[release-plan.json](release-plan.json) records each owner's explicit immutable
allowlist, original runtime location and manifest digest. [releases.json](releases.json)
maps every original relative path to its SHA-256 object. Only named released UTF-8
receipts were admitted; no environment file, secret value, key, private payload,
generated PDF/audio or database bytes are archived. Synthetic fixture source is
bound through tracked Git paths rather than copied payload archives.

Objects store each distinct released byte sequence once. Small receipts use
deterministic gzip. Similar large JSON bundles can use a one-level line delta
against one full object; its base and exact copied/literal bytes reconstruct the
original, which is checked against the owner's digest and byte length. Archive
hashes are checked separately. This changes storage only, never receipt content.
Current member/object/byte counts are derived once in `index.json`'s `storage`
entry. The original six-release checkpoint remains reconstructible from Git.

From the repository root:

```sh
# Check the complete committed checkpoint, including tools and indexes.
sha256sum --quiet -c docs/verification/member-activation-20261013/evidence/SHA256SUMS

# Reconcile archived bytes, allowlists, source/runner references and JUnit.
python3 docs/verification/member-activation-20261013/evidence/packet.py verify --output /tmp/c4c-archive-index.json

# Also compare captured named source inputs to reachable Git versions.
python3 docs/verification/member-activation-20261013/evidence/packet.py verify --git --output /tmp/c4c-git-index.json

# Inspect original bytes, or reconstruct one release into a new directory.
python3 docs/verification/member-activation-20261013/evidence/packet.py cat authorization authorization-release.md
python3 docs/verification/member-activation-20261013/evidence/packet.py extract retained /tmp/c4c-retained-receipts
```

Without `--output`, `verify` rebuilds the single derived `index.json`. The commands
above leave tracked files unchanged. No command above starts PHP, Laravel, a database
or an application test. `ingest release-plan.json` is the maintainer-only copy
step and needs the explicitly released runtime files for newly added releases;
existing receipt bytes are not reread or overwritten. Maintainer scope/limit
annotations may be clarified without changing any released object.

[source-map.json](source-map.json) maps all captured source bundles and static path bindings to root history reachable from `75629871a75868b4e67299f0c5017350d302812c`, with base `a108aca947ae0d3f661e9feef8a19441d5c3a165`. Its 69 bindings use 35 explicit per-path overrides where captured dependency bytes differed. Generated metadata remains explicitly outside Git where marked. Git verification refuses a missing source-map entry. Earlier abbreviated-SHA and stale-index mapping attempts failed during packaging and were corrected before complete verification; neither is counted as successful verification.

This stage contains 51 releases, 758 released members and 557 distinct byte objects, including release manifests. Offline and Git verification reconcile 63 source bundles and 65 recorded invocations (64 PHPUnit and one formatting), preserving Partial/null-exit records. Original unique bytes total19,279,761; compressed archives total4,404,705bytes, with16one-level delta objects. The native phase diagnostic remains explicitly marked and its nine overlay/helper hashes, source base hashes and unchanged-after map are checked; it is not unmodified acceptance or representative performance.

The final local CI policy release verifies five Python unittest outputs:52,50,61,32,14 (209), allfive command exits0 at75629871, with43declared inputs/runner/HEAD/status unchanged. The earlier209-policy run retains its own source. Final local Pint94 and list-only native discovery2200/8715cases in219/647files are separately captured; all50generated outputs were archived and exact-hash cleaned. The49residual/56untimed files and61allowed MySQL skip cases/17methods are census facts, not executed skip counts. Discovery uses unreachable port1 and executes no application database body or hosted job. The corrected frozen-binding helper verifies6profiles/44memberentries and exact499-to3543 selected SQLite carry; its legacy-manifest setup failure and a no-write documentation replacement interruption are disclosed without rerunning application tests.

The native a249 positive returns successfully and checks actual durable rows and original bytes. The earlier 008 native failure instead occurs in the **second/final** historical inspection after the first inspection and both physical byte calls returned; no successful return or later graph assertions are inferred. All unmodified errors remain separate from the timing-only diagnostic. Original physical/source/workflow caps were retained through each source change.

These are path-byte and receipt checks across separately captured source commits, not execution of the final root candidate, evidence for omitted inputs, final source carry or final C4c approval. Current native rollback/race/recovery and affected SQLite receipts are complete; final integrated independent DECISION and exact-head preflight/merge remain pending. SHA256SUMS is rebuilt for this packet; the old six-release checkpoint remains historical in Git.
