# Independent C4c application and evidence DECISION

**DECISION: APPROVE the focused development candidate, subject to the existing cheap exact-head preflight and repository merge protections.**

**Reviewed SHA: `bc708923c03e83ef092286cb437042e855a898e8`. No open C4c P0/P1 was established.** One adjacent pre-existing P2 remains explicitly below. This is development approval under Sean's October 6 CI cost policy, not complete release acceptance or production activation. The preflight has not yet run for the published final candidate; it remains a merge condition.

Reviewer: `/root/c4c_final_packet_review`, a separate non-author reviewer. Applied `/workspace/VA-Studio-member-activation/AGENTS.md`, Code Verification (`c39/code-verification`) and its verification matrix. The author held the candidate frozen and clean during final review. Only runtime review notes and offline verification outputs were written by this reviewer. No application/DB tests, dependency installation, hosted/Foundation/GitLab dispatch, network, source repair, commit, push or merge was performed.

## Retained finding: ordinary postcommit classification P2

P2/medium, high confidence in the source control flow; this reviewer did not execute a new reproduction. At `app/Domain/Memberships/Production/MembershipReservationOperations.php:130–132`, the ordinary runner records `$committed` after `Connection::commit()` returns. Installed Laravel commits native PDO first, lowers its framework level, executes afterCommit callbacks and then dispatches the committed event. A genuine throwing callback/delegate can therefore leave a durable ordinary effect while the operation retains generic refusal classification and minimizes an arbitrary primary cause.

Direct comparison with frozen C4b `a108aca947ae0d3f661e9feef8a19441d5c3a165` confirms that the same vulnerable inner catch predates C4c. The outcome is a refusal, without a false successful receipt, automatic retry, compensation or duplicate terminal introduced by this batch. Its bounded consequence and adjacent pre-existing scope justify P2 independently of schedule or test cost. It is not silently dismissed or represented as covered by the member-activation postcommit test.

The next related batch needs an exact original-owned physical-commit witness and a genuine throwing ordinary afterCommit regression, preserving primary and cleanup causes. An idle PDO is not commit proof: rollback or replacement can also leave it idle. The activation runner's conservative idle-on-error refusal classification likewise does not establish a durable graph; its successful completed carrier separately requires the full committed tail.

## Specification and engineering assessment

**Specification: Pass for the approved bounded C4c development contract and explicit addenda. Engineering quality: Pass for this focused development merge, with the retained P2 and execution limits. Complete release/host/provider acceptance remains Partial.**

Read the original API contract, private batching, completed-readback and native-coordination addenda; affected work-package, architecture, migration and brand boundaries; and preserved independent component/source/recovery notes. Independently inspected the operative owner/issuer/source runner, activation inspection/observer, native scheduling/cleanup, ordinary runner/Context, historical reads/reader/history/codecs, transaction ownership, ledger audit, retained observation and v3/legacy policy branches. Inspected meaningful public-behavior and negative oracles in the affected integration, retention, confirmation-phase and native tests.

| Requirement or material risk | Reconciled evidence and result |
| --- | --- |
| Genuine one-use owner confirmation | Private ticket/entry/claim registrations bind the actual assembly, exact locators, principal/actor and original Budget; failed attempts consume authority. The final six phase cases execute display refusal, actual source/delegate withdrawal, original-cap exhaustion and retirement. |
| Atomic coupled activation and conserved credit | Mandatory full callback/raw closure precedes fixed origin/artifact/consume/activation inserts; planned capacity and actual complete old-plus-planned graph audit remain; normal committing delegates precede another closure. Actual integration graph/bytes/conservation and native exact INSERT1142 denial at all four stages pass. |
| Complete authenticated history and exact winner | Fixed bounded current predicates close period/redemption/invoice/declared-origin/activation joins; table-separated seals/envelopes, winner A/S/M reconstruction and complete consume/origin/activation bijection remain. Native race checks actual winner identities, graph, original bytes, audit and explicit recovery. |
| Completed proof and historical recovery | Carrier registration follows distinct committed proof, one finish, postfinish and outer configuration/Budget/idle tail. Both carrier/read-permit registrations are consumed; final after-I/O owner/graph proof remains. Historical read avoids current honor/catalog/approval/renderer gates and does not repair a missing original. |
| Native coordination and refused outcomes | Original min-only acquisition/workflow/source/physical/honor bounds remain. Sole owned unlock follows full proof/single finish; captured route/idle/cap admission follows, with retained observation last. Actual early bounded loser, both terminal orders and primitive ownership/foreign-transaction checks support the bounded scheduling claims. |
| Compatibility/default-off/frozen originals | V1/V2 acceptance and caps, constructor-only empty operations and v3 detachment remain. Activation/confirmation stay unmounted with disabled flags and null approval hashes. Six profile manifests and44 member entries match actual frozen C4b bytes; API-contract hash remains exact. |

## Independent final evidence verification

At exact clean `bc708923c03e83ef092286cb437042e855a898e8`:

- `sha256sum --quiet -c docs/verification/member-activation-20261013/evidence/SHA256SUMS` exited0 for all563 entries.
- `python3 docs/verification/member-activation-20261013/evidence/packet.py verify --git --output /workspace/runtime/member-activation-separate-final-git-verification-bc708923-20261014.json` exited0. Reconstructed release bytes/archive hashes, allowlists, Git mappings, source/runner references, XML and exits reconcile. Its derived JSON equals committed `index.json` exactly.
- Verified51 releases,758 released members,557 distinct objects,63 source bundles and65 recorded invocations:64 PHPUnit plus one formatting. All69 source-map bindings and35 explicit path overrides resolve to reachable root history. Counts are a historical/current catalog, not one suite total. The index retains41 pass,21 failed, one aborted-before-counts and two Partial records; failures were not erased by later passes.
- Independently recaptured all2320 current application/test inputs against both captured hashes and current candidate Git blobs; no mismatch. All13 captured loader metadata hashes and43 final CI policy inputs match current files, and CI inputs match candidate Git. Recomputed the final-source-carry digest. The operative app/tests/bootstrap/config/database/scripts/resources/lock/PHPUnit delta from tested `3543b71fed3c8dd4b05aefb3b6fc9cf6d7bfd56d` is empty.
- Direct raw XML/process/result reconciliation confirms the following separate current-source receipts, each with zero failures/errors/skips and observed process/verifier exits0:

| Current source selection at `3543b71f` | Tests / assertions |
| --- | ---: |
| Native early same-redemption race |1 /78|
| Native consume/release, both orders |2 /129|
| Native four-stage denial plus member postcommit recovery |5 /970|
| Final affected SQLite integration/retention/confirmation files |14 /348|
| Pure ownership/durable recorder smoke |14 /27|

The three durable supervisor logs equal the completed recorder results, and launch PID/start-tick/source metadata reconcile. Native worker stdout equals actual result JSON; zero worker exits, empty stderr, idle state, distinct requester/blocker waits, owned committing holds, terminal/conservation and recovery markers reconcile. Earlier interrupted terminal2/129 and rollback/recovery5/970 retain null original process/verifier exits and remain Partial. Later supervised runs do not backfill them.

Independently checked the exact499→3543 delta contains only the two native parent test corrections and that selected ownership/integration/ordinary-lifecycle bytes remain equal. Its17/176 evidence retains executed head `4990315a31b2b7b87dd46282559ba9ebb5f75310`; this is exact carry, not a new execution. Older canaries, original failures and timing overlays retain their own sources and limits.

Local quality evidence records Pint94 operative PHP files and native list-only discovery at3543, both exits0, with actual source/metadata/runner/helper equality. Independently parsed the archived source/shard XML:8715 cases in647 files;2200 selected cases in219 files;24 nonempty disjoint shards. Recomputed49 residual files,56 untimed files and allowed MySQL skip census61 cases/17 methods (SQLite662/250). These are allowed/discovered counts, not executed skips. All50 generated bytes match archived hashes and their exact names are absent after cleanup. Discovery uses unreachable loopback port1 and executes no application database bodies.

Final local CI policy receipts at `75629871a75868b4e67299f0c5017350d302812c` independently reconcile52+50+61+32+14=209 Python checks, allfive actual command exits0, log hashes/counts and source/runner equality. Contract SHA-256 remains `fda01303672d5a901f48d43f6f3855c0137de3af455ca01eb5bc945cd86a6759`.

One evidence wording correction was required during this review: the initial frozen034 packet/ledgers called all65 records PHPUnit invocations. The author corrected this to64 PHPUnit plus one formatting in one docs-only commit. This reviewer verified the exact four-file correction, the sole README checksum change, clean diff and unchanged archives/index/source-map/application/CI inputs before reviewing finalbc708923. Minor read-only reviewer helper assumptions about archived XML naming and quality map composition were corrected without modifying any evidence or rerunning application tests.

## Limits and merge conditions

Passing focused receipts and source reading do not establish full Foundation/MySQL/SQLite/browser acceptance. Installed dependencies are identified by locks and loader/package metadata, not every installed byte. Older seven history/reader bundles omit the executed base test harness; older policy/consume captures lack a complete ignored/untracked or after-runtime inventory. These remain explicit Partial provenance limits. Diagnostic timing overlays remain diagnostic; historical unmodified failures are not relabelled.

Successful native concurrent retained replay is not claimed by the early bounded loser. Inserted-history-authentication withdrawal is source-reviewed; the executed encryption and final-delegate withdrawal cases are distinct. Comprehensive actual owner-finally primary-plus-unlock failure injection, native ownership-loss/late-admission application branches and representative contention/performance remain untested. Unknown-extra-trigger inventory, physical storage/clock, host lock support, legal/commercial/account/provider and cutover conditions remain open.

Before development merge, publish the reviewed candidate or an independently reconciled receipt-only successor; run required cheap preflight on that exact final published head and retain repository protections. Any product/test/config/CI behavior change requires renewed affected evidence and independent review. Appending this unchanged decision and minimal merge/preflight receipts can be handled by an explicit exact-diff/hash carry; it does not by itself require application reruns.

No production membership enablement, entitlement import, live charging, delivery authorization, legal assent, DNS/cutover or launch-readiness approval follows from this DECISION.
