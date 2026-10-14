# C4c early same-redemption contention analysis — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded read-only ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No implementation/test/configuration edits, application/DB workloads, provider actions or hosted checks were run. Only this new runtime note was written.

## Actual revised result

Inspected exact clean `8f62c7cb1725e9215f606e19db18fc16213e2c18`, the existing `mysql-activation-same-redemption-race-preparation-fixed/result.json`, and public transcript `storage/framework/testing/member-activation-race-evidence/test_independently_confirmed_same_redemption_workers_retain_the_exact_winner_graph-5e657135-31f4-447d-a6ac-72880f1c30b3.json`. The revised receipt reports one test / 33 assertions / one failure, zero errors/skips, process/verifier 1, 462.150264 seconds including fixture work; 2318 inputs, runner/autoload, HEAD and status unchanged. Captured digest is `24fc06f4152c4fe263042411d4c7f6284023c4baa769d895bb3df50d3df11b95`.

This run genuinely reached two independent users.PRIMARY row waits: connections 34 and 35 blocked on parent connection 33, exact fixture primary key 4. Both authentic workers completed preparation and returned their actual null/refusal outcome with exit 0, idle framework/native PDO, empty stderr and no teardown termination. The strict test correctly fails because **neither actual worker returned a successful activation receipt**. Do not relabel it as successful concurrent replay or merely another preparation timeout.

| Measured phase | Worker 0 | Worker 1 |
| --- | ---: | ---: |
| Connected to ready | 65.449798s | 66.458117s |
| Actual review | 32.333706s | 32.502312s |
| Actual confirmation | 31.956318s | 32.836477s |
| Ready to activation entry | 66.722311s | 1.428170s |
| Activation entry to actual refusal | 105.389297s | 72.770988s |
| Entry-sampled ticket deadline remaining at entry | 201.321508s | 265.735783s |
| Same entry-sampled deadline remaining at finish | 95.932211s | 192.964795s |

Parent released its row 2.903621 seconds after first worker activation entry. Thus the repaired external ready barrier is no longer the observed blocker, and the deliberately held parent lock was brief. Worker 0's measured preparation independently confirms why the old whole-preparation 60-second harness timeout was insufficient.

The final row above is calculated from each worker's **entry-sampled** deadline. It establishes that the original nominal 300-second confirmation allowance had not elapsed at refusal. It does not exclude later min-shortening of the shared Budget to first materialization+60/honor, or an independently earlier source/producer deadline. Neither source-frame phase timestamps nor a final effective Budget deadline were recorded.

## Present reasons do not locate the failing proof

Worker 0 returned `MemberGrantException source_refused -> MembershipException operation_deadline`. In the inspected Context, that reason admits against its original source/authority minimum. Worker 1 returned `committed_activation_receipt_refused -> IdentityException committed_frame_required`.

`IdentityCommittedFrame` uses the second reason for expired original frame deadline **and** invalid native/route/session/frame state, begin/finish failures and closed/used frame. A reason without a throw site/stack cannot distinguish those. The committed wrapper is also used by read-only probe/source inspections after their normal read transaction commits; its presence does not by itself establish a durable activation effect. The strict winner assertion failed before the test's later persisted graph queries, so no verified graph/count outcome is claimed from those unexecuted assertions.

The source sequence starts each bounded read/source frame before it obtains current owner rows. After the short parent barrier, the workers can interleave initial historical probes, live source captures, detached materialization and final completion captures. Each owner-row acquisition and callback/graph/schema proof can hold that row while another worker's already started frame waits. The other workflow's original 60-second frame allowance includes that wait. Native preparation proofs around 32 seconds make exhaustion through such overlapping captures plausible, even with substantial nominal 300-second workflow headroom. This is a source-established possible explanation, not the demonstrated exact failed frame or a product correction diagnosis.

## One bounded sanitized diagnostic is justified

Adding worker-only exception location/trace metadata is appropriate before changing the product. Preserve the existing capped previous-exception chain/class/reason fields. For each error, record its throw file/line plus a bounded number, for example 24, of trace frames containing **only** file, line, class, function and optional call type. Omit absent fields or use null; prefer repository-relative known paths where convenient. Explicitly whitelist these keys rather than serializing `getTrace()` wholesale.

Never record arguments, object instances, exception messages, authorization documents, cookies/session values, credentials, configuration/environment or SQL payloads. The metadata getter/whitelist adds no application callback, producer replacement, registry mutation, deadline renewal or product overlay. Keep the exact writer-success, bounded-loser/terminal, row-wait, graph/conservation/bytes/idle assertions unchanged. A new worker-only commit gets its own captured input digest; preserve both previous failures and passing native product evidence at their exact source.

One rerun of this precise selection can then identify whether the failed path was initial historical probe, live capture, preinsert/final source proof, or committed read frame. If it demonstrates a frame or authority minimum expiry, determine original phase start and lock/proof cost before proposing any batching. Do not begin source clocks after an already consumed wait, replace a closed frame, refresh a ticket, extend 60/300/honor/physical bounds, or remove final raw/committed admission to make it pass. The current evidence does not justify an immediate product edit or an automatic repeated matrix.

## A final readback overlap would be a separate narrower case

A controlled second activation starting while the winner is in its **final after-I/O** `MemberActivationReadObserver` normal committing delegate is useful separate acceptance for independently confirmed replay concurrent with the winner's receipt completion. At that boundary the activation has already passed its distinct committed source closure; the winner is completing its private final historical receipt. The loser should wait on the real owner row, then inspect the already committed winner graph and take the unchanged retained replay path, preserving the winner's original confirmation/readiness/activation evidence and bytes.

The hook must identify this exact boundary, not merely any `TransactionCommitting` with an activation row. The completion writer uses `MemberActivationObserver`; initial replay probes also use `MemberActivationReadObserver`. Require the actual ReadObserver plus the actual `MemberActivationReads::verify` call path, or equally exact private test observation, and record full retained origin/artifact/consume/activation graph, owned native transaction, held marker and actual independent loser users-row wait. At this source, `verify` has only its final inspection after `observe/verify`, so that path distinction locates the intended after-I/O boundary. Keep the ordinary delegate and all later product current/committed/finish/postfinish checks genuine.

Preprepare both genuine independent authorizations, start the designated winner, reach its exact readback hold, start the loser, observe the actual native wait, and release promptly within unchanged product headroom. Require **both actual successful receipts** for a successful concurrent-replay claim, exact winner ID/hash/A/R/D reuse, exactly one consume/graph, conserved audit, immutable rows and original bytes/inode, registry cleanup/idle, and separate explicit recovery if part of its stated scope. If the bounded loser refuses, record that precise narrower outcome rather than claiming a successful replay. The hold itself spends original physical/source minima; source review cannot promise enough runtime headroom.

This case is explicitly **postcommit replay/readback overlap**, not two live new-effect writers. It may reduce queuing cost and clarify a separate behavior, but cannot silently replace, erase or satisfy the currently failing early two-writer case. A passing narrower test leaves early-source contention availability unresolved.

## Acceptance recommendation

Proceed with the one sanitized worker trace and focused reproduction; preserve the strict failed outcome. No unsafe authority acceptance or corruption was established here, but the agreed successful-writer requirement for this early overlap is unmet. Final approval must either include successful evidence for that promised behavior within all original bounds or explicitly limit the development batch's claims/acceptance after reviewing a concrete alternative scope. A source-reviewed narrower readback case alone is insufficient to close the existing early-race requirement. The root author owns any product design/fix; further implementation needs exact source/evidence review.

Final C4c application/evidence DECISION, terminal orders, postcommit recovery and archive remain pending. No merge or production/host/payment/legal approval is issued by this note.

Read-only commands used: `git rev-parse`, `rg`, `cat`, `sed`, and Python arithmetic over the named public transcript. Only this runtime note was written.
