# C4c bounded activation deadline review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. This is an ordinary application correctness and efficiency review under `AGENTS.md`, using the Code Verification skill. It is not a security scan, full application DECISION, deployment approval, legal approval or production activation authorization. No application/DB tests, native workload, dependency installation, hosted check, provider action or implementation edit was performed. Only read-only source/receipt inspection and this new runtime note were performed.

## Exact target and bounded conclusion

Initial clean source: `3598015defb2179a6a163f29d7f411815a94c892` in `/workspace/VA-Studio-member-activation`. Independently inspected the lead's subsequent exact correction `61bebdcfaf2fe4ca647dfd503f15e1a63bceadcc`; its complete delta from the initial source changes only `MemberActivationInspection::consumeGrantPlan`, six additions and one deletion. The working tree was clean at inspection.

**The correction is a safe source-level interpretation of contract §6. No new P0/P1 was established in that narrow delta.** It removes one full combined proof while a receipt is still privately provisional, retains exact one-use registry/state checks and callback-free configuration/frame/schema/deadline admission, and preserves the required full proof after record-building callbacks immediately before native insertion. This is not evidence that the corrected native workflow now fits its original caps. The lead's native rerun was still pending when this note was written; its result is deliberately not anticipated.

The original native positive case remains a failed acceptance result at 3598015d. Corrected SQLite positive evidence is relevant but cannot establish native performance or concurrency. Final C4c complete-source/evidence DECISION remains a separate requested review after the focused results and actual source are frozen.

Read first: repository `AGENTS.md`, `docs/verification/member-activation-20261013/api-contract.md`, and `/workspace/runtime/member-activation-operation-review-745ccddc-20261013.md`. Also inspected relevant README/architecture/WP-11/migration/brand instructions, the operation and its source/artifact/grant/authorization issuers, reservation context/issuer, facts/history/schema/frame owners, relevant existing phase/integration/retention tests, and the specific receipts below. The review carries the existing C4b independent approval boundary. This correction does not establish actual C4b preflight/merge/delta reconciliation or authorize C4c integration/publication. `config/member-grants.php` remains default-off with null provenance/approval hashes; no C4c activation/confirmation/grant route or provider binding was found in `routes/` or `app/Providers/`.

## Confirmed native refusal; corrected attribution

The actual `/workspace/runtime/member-activation-root-evidence/mysql-activation-first/{command.json,result.json,log.txt,junit.xml}` selects only `test_actual_confirmation_creates_one_conserved_graph_and_readback_preserves_exact_rows_and_bytes` at exact 3598015d, no source overlay. The receipts record PHP 8.4.26, native MySQL selection, a private local test database, 512 MiB PHP limit, 2,312 unchanged declared/inventory inputs and unchanged autoload/runner observations. The lead identifies the actual server as MySQL 8.4.11; this reviewer did not connect to the database to re-identify it.

Actual result: **1 test / 18 assertions / 1 error / 0 failures / 0 skips**, process exit 2; receipt verifier exit 1. The exception stack is:

`insertActivation:261 -> SealedMemberGrants::proveReadyCurrent:39 -> proveGrantPlan:247 -> proveCurrent:288 -> activationCurrentCallbacks:261 -> memberCurrentCallbacks:229 -> currentCallbacks:317 -> proveSources:728 -> admit:756`.

The precise line numbers here are at 3598015d. The guard at Context:756 reports `operation_deadline` when `hrtime(true) >= min($deadline, $authorityDeadline)`. This preserves the original frame and captured catalog/honor bounds. The trace establishes refusal **during the final preinsert proof**. The earlier `consumeGrantPlan:236` proof had already returned; the initial task description attributing this error to that earlier proof was inaccurate and is corrected here. No native INSERT appears earlier in this failing call path. Durable DB state after failure was not independently queried by this reviewer.

The 439.394479-second whole runner invocation and JUnit 439.243428-second case duration include schema setup and fixture/preparatory work. They are not final-frame measurements, proof-phase durations, or evidence that a 300-second workflow itself ran for 439 seconds. The receipt does not identify which of the source-frame, inherited budget/observation bound, or captured catalog/honor bound was the minimum at refusal. Redundant work is a source-established contributor candidate; its quantitative contribution is unmeasured.

The separate `sqlite-activation-environment-fixed/result.json` records the corrected actual positive case at 3598015d passing **1 / 57**, zero errors/failures/skips, process/verifier exit 0, same 2,312 unchanged inputs. This reviewer read that result; it is not a native substitute or a recomputation of the full source inventory.

Small native receipt integrity was independently checked with Python standard-library SHA-256/JUnit parsing, without importing the application:

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| command.json | 2014 | `c662ef334d9d683bf70bda9304f9e0baf98aea9cbf80f6391a617102925691b4` |
| result.json | 916 | `33dadf24e4f02ce85aaee67bbc898387bc1b68827d269ed8616392b9bebae735` |
| log.txt | 2730 | `497b2ad2739b9f68453aa9b8af21a8e3dcea0c3ffda1110f48adac3285f17fbb` |
| junit.xml | 1674 | `37c2945e588b34b8de09c1e1edf323da3d534e4a8ba7f47ad5a55221dee43a16` |

## Source-established repeated proofs

At 3598015d the final completion frame performs five full current proofs, followed by its distinct committed proof:

| Boundary | Exact source chain at 3598015d | Why it matters |
| --- | --- | --- |
| Artifact readiness | `ready:163 -> admitReady:70 -> readyInspection:225 -> proveCurrent` | Complete proof before issuing same-frame readiness; preserve it. |
| Private grant minting | `planActivation:223 -> prepare:26 -> consumeGrantPlan:236 -> proveCurrent` | Privately provisional receipt; subsequent preinsert proof covers all intervening planning callbacks. This is the removed duplicate. |
| Preinsert | `insertActivation:261 -> proveReadyCurrent:39 -> proveGrantPlan:247 -> proveCurrent` | Full fresh proof after record crypto, UUID/clock work, retained history construction and prospective audit; must remain immediately before native effects. Actual native refusal occurs here. |
| Postinsert | `proveInsertedActivation:267`, then `proveCurrent:269` | Exact new real snapshot/old-plus-plan comparison and full audit can invoke record authentication callbacks; current source closure follows them. Preserve both obligations. |
| Committing | `MemberActivationObserver::dispatch -> context->proveCurrent` | Ordinary Laravel committing delegates execute before this proof. Their possible mutation makes it a distinct mandatory final boundary. |
| Committed | `MemberActivationCommittedProof -> proveCommitted` | Distinct registered nonlocking frame, full persisted graph/sources, one finish, original-cap and postfinish admission; cannot reuse a prior live-frame proof. |

Correction 61bebdcf removes only the second row's full proof. The other four full current proofs and distinct committed proof remain. Initial review, confirmation and detached-source capture have their own committing/committed closures, and activation's retained-history probe and final read also spend the unchanged original budget.

Each full activation current proof executes authorization callbacks, facts callbacks, reservation callbacks, then the terminal finish/frame check and authorization/facts/reservation raw phases, followed by final admission/Rows/frame checks. Reservation callbacks are substantial: complete member history reread/authentication; invoice, policy and catalog producer proofs; billing snapshot; retained plan decode; full ledger audit; clock/honor checks; current owner proof. `MembershipRows::current()` and `assertCurrent()` repeatedly inspect the owned membership schema, and member facts/history raw phases separately inspect current member schema. This is concrete repeated work, not a measured claim that one subsystem dominates runtime.

## Why the exact correction preserves authority

At corrected Inspection:232–241, `consumeGrantPlan` still requires its one-use `grantMinting`, exact grant issuer, actual binding, exact intent/principal/actor/Rows and uninserted/uncommitted/unretired state; it consumes `grantMinting` before returning. It then calls `admit -> finish.assertFrame -> rows.assertCurrent -> admit`. These admissions use captured raw configuration/service maps, the original budget/deadline/materialization registry, native captured PDO/frame/schema checks, and no source refresh or callback execution.

`SealedMemberGrants::prepare` still obtains the actual source-owned active inspection and registers the returned provisional receipt to the exact inspection/binding/intent/principal/actor/Rows/PDO tuple. A directly constructed token or public binding is not registered authority. A foreign/repeated prepare cannot recreate `grantMinting`. The fixed completion path keeps that receipt inside the operation. Value collection, observer preparation and committed-proof registration do not publish a result or write rows.

Corrected `insertActivation:266` still invokes `proveReadyCurrent`, which checks the exact receipt registration and calls the unchanged `proveGrantPlan -> proveCurrent`. Only after that complete combined proof does it enter the exact planned-to-inserting state and execute the four already prepared captured-PDO INSERTs. Thus a record-building callback that withdraws owner, catalog, facts, policy or configuration remains subject to the fresh preinsert closure. Readiness's earlier proof is not reused as final write authority.

The fix changes neither callback/raw ordering nor the source-owned private registries/permits, prospective capacity/audit, four-row insertion order, exact inserted graph reread, consumed-state admission, normal committing observer, committed frame, one finish or postfinish checks. It does not change ordinary reserve/release/expire or C4b source-inspection implementation. It neither enlarges nor restarts the 60-second source/producer/physical-observation bounds, original 300-second confirmation workflow budget, or min-only honor/captured authority bounds.

## Findings and practical next steps

1. **C4C-DL-P2 — unresolved native positive activation cap refusal.** Medium severity, high confidence that the selected native workflow failed; medium confidence that removing repeated work resolves it. Scope is bounded development availability: C4c remains off/unmounted, and the guard failed closed before native insertion on the traced path. The focused positive native acceptance remains open until exact corrected-source receipts complete. Keep the original failed receipt and report any next-stage/postcommit refusal distinctly.
2. **No additional P0/P1 in 61bebdcf.** Source-level acceptance of the narrow correction is conditional on preserving its currently reviewed boundaries. This note does not grant full C4c acceptance from source reading.

Complete the already running serialized native canary at its exact source before expanding the fix. A later failure can move to inserted/committing/committed/postfinish or final historical read without making the earlier optimization unsafe. If the cause remains unclear, narrowly labeled instrumentation should capture monotonic phase entry/exit, original workflow deadline, final frame deadline, first physical-observation start/cap, and captured Context authority/honor/catalog/freshness bounds; use copied scalar timestamps only. Do not add logger/container/clock callbacks inside terminal raw phases, restart timers, or infer frame durations from whole setup time. Such instrumentation changes its tested source and must be recorded as instrumentation, not silently mixed into an uninstrumented receipt.

Existing render withdrawal, ordinary committing withdrawal, confirmation callbacks and postfinish cap tests cover distinct boundaries but do not target a source withdrawal specifically during activation record building. For the removed proof, the smallest additional behavioral check is an actual encryption/record-building callback after artifact readiness that withdraws catalog/owner/facts, followed by refusal through the retained preinsert closure, zero retained member effect/consume, idle handles and consumed confirmation ticket. Do not assert the number of private proof calls as the correctness oracle. The lead/acceptance author owns writing/running this case.

If further source work is necessary after measuring the remaining phases, three immediate constructor chains duplicate successful producer proofs without intervening caller work:

- `SealedMemberGrantFacts::lock:70 -> MemberActivationInspection:92 -> snapshot:88`.
- `SealedMembershipPaidInvoice::lock:48 -> MembershipReservationContext:134 -> freshnessWindow:77`.
- `CurrentEligibleLicenses::lock:41 -> MembershipReservationContext:150 -> snapshot:73`.

The public extractors correctly reprove independently callable inputs, so removing those public proofs wholesale would widen scope and change C4b/ordinary semantics. A separately reviewed exact activation-capture/issuer/Rows/PDO/deadline registration could export the already proved snapshot/window with callback-free registry and original-cap checks immediately after one successful lock proof. It must introduce no caller-selected defer boolean or shaped authority, leave public independent callers unchanged, and retain readiness/preinsert/committing/committed closures. These are follow-up candidates, not authorization to bypass proof or quantitative savings claims.

Do not collapse postinsert and committing checks merely because they both call `proveCurrent`: the inserted-history authentication and normal committing delegates are callback boundaries. Likewise, do not cache schema admission across source frames/callbacks. `MemberGrantCurrentSchema::inspect:248` deliberately acquires qualified relation metadata locks and uses current `SHOW CREATE TABLE/TRIGGER` for the owned definitions/required guards; the supplementary information_schema inventory has a separately documented RR limitation. `MembershipSchemaCatalog` already batches dictionary lookups within one inspection. Replacing current SHOW with RR dictionary values or carrying a schema result to the committed frame would need separate correctness/MDL/temporary-shadow evidence and is not supported by the current failure.

## Requirement/evidence disposition

| Requirement or risk | Evidence in this bounded review | Disposition |
| --- | --- | --- |
| Strict private confirmation/grant/write authority | Exact issuer/permit/receipt/capture source, including 61bebdcf | No new gap established; executable regression evidence remains for final review. |
| All participating callbacks before terminal raw source checks | Unchanged `proveCurrent`/`proveCommitted` call order | Preserved at source level. |
| Original 60s/300s/honor/observation/currentness bounds | Source constants/min-only captures and native deadline guard | Preserved; positive native fit unresolved. |
| Atomic coupled effect and final complete checks | Fixed insert/state transition/reread/observer/committed/postfinish source | Preserved at source level; native acceptance pending. |
| New positive activation/readback | SQLite 1/57, native 1/18/error at 3598015d | Partial across environments; native acceptance failed at baseline. |
| C4b approval and release boundaries; off/unmounted C4c | Prior independent note/contract and actual configuration/routes | Preserved; no publication, host, live credential, payment, legal assent or launch claim. |

Commands used were read-only `cat`, `sed`, `nl`, `rg`, `git rev-parse`, `git status --short`, `git diff`, `git show`, and a Python standard-library receipt hash/JUnit read. Two path/scan lookups reported ordinary nonmatching/missing paths; they were followed by the correct `config/member-grants.php` and actual route/provider scan. No check result is inferred from them. Only this runtime Markdown note was created. No Foundation CI, GitLab verification, DB query or full inventory/archive audit was run by this reviewer.
