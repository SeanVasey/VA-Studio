# Independent A2 timing repair review — b682e121

**DECISION: APPROVE the bounded timing repairs at `b682e1214235f2eba8736cf0298984f8d0ee5770`. Both previously identified P2 timing windows are closed in the reviewed source and covered by the completed named focused cases. No new P0/P1 or required repair was established in this bounded delta. This is not final coherent-batch application/merge approval.**

Reviewer: `/root/c4c_final_packet_review`, separate non-author reviewer applying AGENTS, Code Verification and its matrix. Read-only comparison against test-only old source `8aaa69996bdd9349e52fae9734628e156fc7f3c9` and previously reviewed implementation `627743d04a4184245f4d5e3009a42af29b550d02`. Six named changed app/test files match actual b682 Git blobs byte-for-byte. Only this runtime note was written. No application/DB/test/CI/host/network execution or source/test/docs edit was performed.

## P2-A: live min-only cap reaches terminal commit admission

`PaidGrantDeadline::forRequest()` retains the exact typed RequestBudget alongside the unchanged original60-second phase. `value()` is a pure minimum of its own decreasing deadline and that budget's current decreasing cap; `proveCurrent()` checks that same minimum. RequestBudget initial/commit factories retain this exact relationship and still apply original initial/overall bounds. Ordinary `start()` remains1–300; no raw clock or extended phase constructor is added.

ConsumerCommitAdmission now retains the exact original Deadline reference as well as the original scalar captured at mint. Before and after the existing receipt's raw admission, it checks both the captured scalar and live phase minimum. Later genuine final callbacks cannot bypass shortened request/phase authority through stale copies. All new terminal checks are field access, native monotonic time and domain comparison: no container, configuration, permission, wall-clock or provider refresh enters the raw closure.

The genuine normal TransactionCommitting regression observes the provisional attempt in its owned frame, shortens the actual request, then requires410, owned native/framework idle, zero durable attempts and an immediately closed prepared descriptor. A legitimate fresh HTTP request reuses the same unspent authorization and spool lease and delivers the exact original. Actual hook and outcome checks are outside wrapped refusal catches.

## P2-B: final pure first-chunk admission

Transfer retains its before/after ProjectionRead checks and existing size-derived transfer clock. A local first-chunk flag now places the pure request proof after `within()` and immediately before the actual consumer invocation, covering intervening stream setup/read and the existing clock callback. Subsequent chunks use the separate transfer cap, preserving valid streaming after first-byte admission. Finally still closes the exact unlinked descriptor/lease on every outcome.

The late-first-clock regression narrows the real budget in the third transfer tick, after the earlier outer checks and at the first physical chunk. It requires exactly three ticks, no emitted bytes, a closed prepared descriptor and one truthful already committed attempt. The independent positive uses a genuine physical three-chunk synthetic master: its consumer expires the request cap after chunk one, yet all three chunks and exact bytes complete inside the separate transfer allowance, with one attempt. This catches accidentally applying the first-byte cap to every chunk.

The new RequestBudget matching `__unserialize` refusal also addresses the earlier low factory-bypass observation. No new public customer authority follows from its timing methods. Original capture arithmetic/exact instant pairing/snapshot minimum, current/committed/producer/raw closure, legacy domain path and immediate preparation cleanup remain as reviewed at627.

## Direct completed receipt reconciliation

Independently read actual command/result/process markers and XML; no tests were rerun:

| Executed source / run | Tests / assertions | Actual result |
| --- | ---: | --- |
| `8aaa6999`, grouped old review selection |1 /14|One intended missing-refusal failure; process/verifier1, zero errors/skips. Stop-on-defect leaves the second selected case unrun. |
| `8aaa6999`, separately selected old first-chunk case |1 /14|One intended missing-refusal failure; process/verifier1, zero errors/skips. |
| `b682e121`, exact two repairs plus multichunk positive |3 /56|Pass; process marker/process/verifier0, zero failures/errors/skips. |

The old failure text is `Expected a paid request budget refusal.` It is not a setup error or three-case execution claim. Completed b682 XML names exactly the final-callback, late-first-chunk and later-chunk-positive methods; counts reconcile with its actual result. Source/HEAD/status/runner/autoload unchanged gates are all true. Old and new observations remain separate sources. This phase independently reconciled these bounded public receipts, not their entire declared input manifest or final portability packet.

## Remaining coherent-batch gates

The previous62712-method selection retains its own source/result; this three-case pass does not relabel it as a complete b682 suite. Run the current whole request-instant file and affected shared Deadline/consumer, snapshot/transfer/download and HTTP cases in the final coherent selection. Meaningful native wait proof, whole-order two-line failure/retry and the planned guarded genuine root bootstrap integration still follow. Existing tests for originally admitted expiry, source withdrawal, postcommit refusal and owner recheck remain material to those shared seams.

No additional broad suite is required merely for this repaired delta. No new blocker was found beyond the planned final affected evidence, exact candidate/source packet, separate final review and cheap preflight. Real first-read/DB/physical-commit blocking cannot be interrupted by PHP admission checks; proxy/FPM/queueing/metadata-lock/storage/slow-client and other C11/pre-mount conditions remain open. A committed attempt is never a completed-delivery receipt. No route mounting, live payment, customer entitlement or production acceptance is approved here.
