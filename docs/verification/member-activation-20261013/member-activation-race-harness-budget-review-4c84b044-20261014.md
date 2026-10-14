# C4c native race harness budget review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded source/evidence review only under AGENTS and the previously read Code Verification instructions. No product/test/configuration/CI edits, application/DB tests, provider actions or hosted checks were performed. Only this new runtime note was written.

## Actual failure and scope

Read the same-redemption race's existing `command.json`, `result.json` and `log.txt` at clean `4c84b0445fc9ea3e56f4ef184bb9de2507904253`, and the two parent/worker source pairs. The receipt reports one test / 13 assertions / one failure, zero errors/skips, process/verifier 1, 305.717928 seconds including fixture work, and unchanged 2318 source inputs/runner/autoload/HEAD/status.

The log points to first `worker($fixture, 0)` at test line 85, its ready await at line 198 and the bounded-barrier failure at line 268. The worker must obtain actual principal, actual review and actual confirmation before writing ready. Parent's present ready await is only 60 seconds. It never returned to spawn worker 2. This run establishes a preparation harness failure, not a failed two-writer contention or a native row-wait outcome. The lead's transcript report additionally says the one worker was still running, stdout/stderr empty, then terminated by teardown with 143; that transcript was not independently located/read in this bounded review.

The failure must remain visible at its exact source. Increasing an external harness timeout is a correction to permit the real scenario to execute, not a successful race or product-cap exception.

## Recommended coherent outer bounds

The proposed **150-second parent ready await / 180-second worker start barrier / 600-second process timeout** is coherent for the next focused diagnostic in both race families. These are external test bounds, not product authority. I do not recommend a tighter ready cutoff based on the current evidence: this failed run provides only the lower bound that genuine full preparation exceeded 60 seconds. Earlier individual source capture timings around 30 seconds explain why principal plus review plus confirm cannot be bounded by one such frame.

The 180-second same-redemption first-worker start barrier allows the separately spawned sibling's 150-second maximum preparation plus approximately 30 seconds of parent assertions/raw setup before releasing worker 0. The parent should release immediately once ready/identity/pre-effect assertions and the users-row lock are established; no incidental workload belongs in this interval. Worker 1's release follows the unchanged bounded first-wait observation.

The terminal family keeps winner-first preparation. In release-wins order, the activation loser obtains its genuine ticket last and may then wait up to the unchanged 90-second winner-held await before release. Its 180-second start bound covers that harness interval. In consume-wins order, the activation winner waits for the release worker's full preparation; its original product ticket headroom still needs actual evidence. The unchanged held20/observe18 and exact real native users-row wait remain necessary; do not convert an unobserved contention into a passing case.

The outer 600-second process cap avoids killing an authentic worker merely because it spent separate preconfirmation preparation, sibling readiness and its original bounded workflow. Tightening that cap arbitrarily while preparation duration remains unmeasured would repeat the same harness failure at a later boundary. Once the next progress receipt establishes actual stage and operation durations, a smaller process bound may be selected with recorded headroom; no performance claim follows from the chosen outer cap.

## Original ticket and physical caps still govern

`MemberLicenseAuthorizations::confirm()` starts the real `MemberOriginalBudget` before confirmation callbacks and retains it in the ticket. `review()` has its own earlier budget; principal/review work before confirm is not part of that ticket's 300-second allowance. Time spent confirming and waiting after ready **is** part of the same ticket allowance used by activation. Synthetic business walls do not change native elapsed time.

For the first same-redemption worker, successful activation requires:

`confirmation elapsed + sibling preparation wait + parent start/row-wait overhead + actual activation/readback < original effective ticket budget`.

The maximum harness allowances alone do not prove that inequality. Even 60 seconds of confirmation plus 150 seconds sibling preparation plus 30 seconds parent overhead leaves only 60 seconds of the original nominal 300 for the actual activation, before any shorter honor cap. Raising start to 180 does not refresh the ticket. If a later run shows genuine insufficient headroom, correct scheduling by preparing/reviewing both workers before issuing the first confirmation, using explicit separate preconfirmation stage barriers; never mutate issuer registries, renew a ticket, bypass claim, shorten a proof or enlarge product caps. Such a scheduling change would itself require review and real execution.

Likewise, a terminal winner's 20-second delegate hold is a maximum harness wait inside the existing product source/physical admission window. Parent observes the loser then releases promptly; actual hold plus full post-delegate source closure, distinct committed proof and byte-bearing final read must still fit the original min-only physical60/workflow300/honor/producer caps. Prior unmodified positive success does not prove headroom for an arbitrary additional 20-second hold.

Keep the existing oracle requiring the actual retained graph writer's successful receipt, exact one graph/consume/conservation/winner A/R/D, bytes and explicit recovery. Preserve the explicit allowed bounded loser outcome in the same-redemption test; do not weaken it merely to accommodate a new unrelated failure. Terminal tests still require the winning actual receipt and losing `reservation_terminal`, not generic refusal.

## Progress evidence

Small worker-local progress JSON is appropriate for the connected/principal/review/confirm stages, captured between genuine producer operations. Use monotonic entry and completion timestamps plus PID/connection ID/stage/role and eventual ready/start/operation-end times. A stage entered but never completed must remain distinguishable from a completed producer. Preserve prior stages in the final transcript, including failure/teardown, rather than overwriting the evidence with the last stage only.

Record `confirm_started_ns`, `ready_ns`, parent release time, worker `entered_ns` and `finished_ns` in both families. A read-only observation of the genuine ticket's already registered Budget deadline at ready/start can identify its actual effective remaining allowance; that number is diagnostic only and must never become authority, a copied ticket, a replacement Budget or a caller deadline. Do not log credentials, private ticket internals, cookie/session values or full configuration. Parent can copy the public progress values into its receipt on failure. Stage file writes must not introduce a new domain callback or run inside a producer's terminal raw closure. For files read while being updated, use complete/atomic JSON publication or tolerate only diagnostically incomplete reads; a half-written progress file should not masquerade as a domain failure.

## Source/evidence carry and pending decision

Independently read the small result records for native positive at `a24979ae2ffa2814ee3cdc8ea5558384149230ff` (1/59, zero errors/failures/skips, process/verifier 0, 482.581819 seconds) and native write-denial rollback at 4c84b044 (four datasets / 940 assertions, zero errors/failures/skips, process/verifier 0, 1600.717899 seconds). Both record unchanged 2318 inputs/runner/autoload/source status and identical runtime-input digest `b7af014dd0864af1e37dd3b0871b63dcaa70d9bed090da22fbf152c7f384007e`. The exact 1142 SQL target and all application assertions were lead-reported; this note does not replace the final full receipt/source audit.

The recommended harness-only correction will intentionally change captured test/worker inputs, so new race receipts must retain their new exact commit/digest. The prior canary/denial results remain attributed to their original commits; carry requires explicit named product-input reconciliation, not an assertion that every new test byte was executed there.

Same-redemption contention, consume/release terminal orders, postcommit recovery and final archive remain outstanding. No complete C4c DECISION or production/host/payment/legal approval is issued here. C4b's independent boundary and C4c default-off/unmounted state remain preserved.

Read-only commands used: `git rev-parse/status`, `rg`, `cat`, and `sed` of the named parent/worker/product/result/log sources. No workload was run.
