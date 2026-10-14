# C4c bounded native race trace source review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded read-only ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No implementation/test/configuration edits, application/DB workloads, provider actions or hosted checks were performed. Only this new runtime note was written.

## Exact source conclusion

Inspected clean `72dbd1b45d547d2be608cd77e7f3fe15528dead5` and the complete three-file delta from `8f62c7cb1725e9215f606e19db18fc16213e2c18`: 39 insertions/four deletions, confined to the same-redemption parent and both native worker scripts. **The metadata implementation conforms to the preceding bounded-trace recommendation, with no new P0/P1 or required source correction established.** One focused reproduction of the currently failing early same-redemption selection is appropriate to locate the exact refusal; no product fix or passing race is established by this review.

## Explicitly bounded and whitelisted exception metadata

Both workers preserve the existing exception-chain cap of 12. Each error now records its class/reason, throw file/line and at most 24 `getTrace()` frames. Each frame is explicitly reduced to file, line, class, function and call type by `array_intersect_key`; arguments, object instances and every other trace field are discarded. No exception message, authorization document, credential, configuration/environment or SQL payload is included. Repository-root file paths are made relative; outside paths become basenames. Native PHP trace values under those whitelisted keys are location/name scalars.

The metadata is collected after the actual operation throws through its existing cleanup, without a product overlay, new domain callback, producer substitution, registry mutation or copied authority. It adds no clock or skipped proof. Both original successful-empty-errors and reason-based refusal assertions remain valid and unchanged. Truncation at 24 frames is explicit, so an absent deeper site must not be described as proven absent.

## Effective deadline and raw post-worker observation

The final worker metadata also records `ticket_deadline_at_finish_ns` through the existing retained Budget's pure `deadlineNs()` getter. This distinguishes later min-shortening from the previously logged entry deadline; it neither renews the Budget nor reclaims the already consumed ticket. Release workers retain null ticket deadlines. Comparing final finish time with this effective value is a diagnostic, not a replacement for product source/frame/honor/physical admission.

After both worker processes finish, the same-redemption parent now reads its fixture's existing effect rows, records only their canonical raw hash/counts plus ordered credit event kinds, and persists that observation **before** the unchanged strict assertion that at least one actual worker returned its successful receipt. The comment accurately labels this diagnostic metadata as neither a complete nor authenticated graph assertion. It can establish observed durable row presence/counts when the strict success gate fails; it cannot establish row seal/decryption/bijection/conservation/original bytes or successful receipt semantics. The later unchanged authenticated graph/audit/file/idle checks still require reaching their normal success path.

No parent barrier, successful-writer/bounded-loser/terminal assertion, product source, capability, clock or cap is changed by this delta. The prior early-race failure remains open, and a narrower postcommit replay/readback case cannot silently replace it.

## Existing postcommit receipt and evidence carry

Independently read the small result record at `mysql-activation-postcommit-recovery/result.json`: exact 8f62c7cb, one test / 30 assertions, zero failures/errors/skips, process/verifier 0, 352.580662 seconds including fixture work, unchanged 2318 source inputs/runner/autoload/HEAD/status, digest `24fc06f4152c4fe263042411d4c7f6284023c4baa769d895bb3df50d3df11b95`. This is the recorded focused result, not reviewer-run or full archive evidence. It remains attributed to 8f62, whose relevant product/test source is preserved except the three newly changed race harness inputs above.

The next early-race receipt must record its actual 72db source and new captured input digest. Preserve original preparation-only and genuine-contention failures, prior native positive/denial/postcommit successes and their exact sources. The lead reports syntax3/Pint3/diff checks passed; this reviewer did not run them. Native race/terminal acceptance and eventual complete C4c source/evidence DECISION remain pending. No merge, production enablement or host/payment/legal approval is issued here; preserve C4b's independent boundary and C4c default-off/unmounted state.

## Exact three changed Git blobs

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationRaceTest.php` | 17185 | `a800106b5cef88a1b51e4ddbc53f1f3ad35dd569ba51f0f560c99da09e6559d8` |
| `tests/Support/member-activation-race-worker.php` | 7687 | `cd562fd3c95f5b76eca2f29989199961dd2119d33aad99511e0786ef64ec8fdc` |
| `tests/Support/member-activation-release-race-worker.php` | 9570 | `ff5d2de90fb3ad4ca2b7c828daab085b8da630b193b9e2620610cd2b9887bfbd` |

Hashes were calculated with Python standard library from the exact three Git blobs. Read-only commands used: `git rev-parse/status/diff/show`, `rg`, `cat`, and bounded Python hashing. Only this runtime note was written.
