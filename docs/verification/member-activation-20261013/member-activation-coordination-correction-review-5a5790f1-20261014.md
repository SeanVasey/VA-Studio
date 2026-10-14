# C4c coordination correction review — 5a5790f1

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded read-only ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No implementation/test/configuration edits or application/DB workloads were performed. Only this new runtime note was written.

## Exact source and conclusion

Inspected clean `5a5790f16bbc24939b770935d7c3ad8d15e7b2c8` and its complete nine-file delta from `67002abd1143413f06cbb4c5fc6dd2d6b280f85c`: 111 insertions/19 deletions. The prior combined design/source review carries for unchanged portions. **The two specifically identified 67002 defects are corrected at source; one remaining last-observation classification edge in activation-owned retained replay requires the narrow terminal-tail correction described below.** No additional native guard/proof/clock defect was established in this bounded pass.

Lead reports exact 5a focused SQLite passed 14/166, zero failures/errors/skips, process/verifier 0, 103.928s, all 2320 inputs/runner/autoload/HEAD/status unchanged. That is distinct from the earlier 67002 14/166 source. No new native acceptance is claimed, and no complete C4c DECISION is supplied. Preserve all previous failed receipts, default-off/unmounted C4c and the C4b independent boundary.

## Acquisition60 finding closed at source

The helper now captures `acquisitionDeadline = min(originalDeadline, native entry+60)` before idle/finish/native route SQL. It checks that same minimum before GET_LOCK, after acquisition, and after its last ownership query before returning. Native timeout uses an integral capped ceiling of the remaining allowance, at most60. No fractional MySQL behavior is assumed. A native call returning late cannot issue a successfully admitted guard even if original300 has time left; its acquired single session lock follows the existing refusal cleanup.

No source clock, ticket, original ordinary60 or physical/honor cap is reset. The addendum accurately distinguishes native timeout and strict acquisition admission. The lead reports two fractional API probes failed their criteria and were reverted; this note does not relabel those failures or certify their runtime beyond that report.

## Known committed classification and both causes corrected

Owner now tracks the primary failure and known committed state from actual returned completion, an existing `committed_activation_receipt_refused`, or a successfully returned retained winner. Its finally cleanup preserves that committed classification instead of using only the old completion-return flag. Cleanup still retires exact entries, completions, claim, guard Budget registry, Sources/artifacts and read permits regardless of cleanup refusal.

Both actual activation and retained replay native finish now pass through `Reads::finishCoordination`, which wraps native unlock/cached admission errors as `committed_activation_receipt_refused` after the full retained proof returned. This corrects the specific recursive/unknown cleanup path identified at 67002. Public read has no native guard and performs no unlock.

Ordinary wrapper retains the same original60, tracks actual successful physical commit plus an existing `committed_receipt_refused`, and wraps finally cleanup with the appropriate known phase. Its full Context current/committed/finish/postfinish/caps and new postunlock callback-free admission are unchanged. The helper acquisition catch also preserves a primary acquisition refusal if cleanup itself fails.

`MembershipWorkflowCleanupFailure` retains the actual primary exception as its previous and actual cleanup exception through its readonly private property/accessor. `MembershipException` gains an optional previous parameter while preserving its existing reason, private generic message and default code. No authority, receipt or business state is added to the aggregate.

Both workers now traverse previous and cleanup branches breadth-first, tracking seen Throwable identities, within their existing cap of 12 emitted errors and 24 whitelisted location/name frames per error. They retain no arguments, object instances, messages, credentials, configuration or application payload in exported metadata. This is bounded real causal evidence, not a mocked successful proof.

## Remaining private replay terminal-tail edge

At this exact source, private retained replay `execute()` runs the full final inspection/result comparison, then wrapped `finishCoordination()`, then `originals.assertCurrent()` **outside** the committed wrapper. If the effective original observation/Budget expires after successful unlock but before that last admission, it throws e.g. `workflow_deadline`. Owner's new effect flag is false and no successful winner returned; it therefore still lacks known committed status from that error and exposes the unprefixed refusal, despite the fully proved retained candidate. The newly completed activation path is covered by Owner's returned-completion flag; replay needs explicit terminal-tail handling.

Required narrow correction: classify failure of the complete private activation success tail, including unlock/cached admission **and the last original observation admission**, as a committed retained-receipt refusal after the full final proof/result comparison. Keep the observation last and do not add a callback, clock refresh, further file read or new source proof. Preserve standalone public read's guard-null scope/behavior. This is the remaining outcome-mapping obligation, not a reason to change guard serialization or any cap.

Direct execution of the owner/ordinary compound cleanup mapping and this deadline edge is not established by the SQLite selection or embedded native primitive ownership checks. Record that limit; subsequent source correction and actual native success/strict terminal outcomes remain necessary before a final candidate review.

## Exact changed-source hashes

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `app/Domain/Grants/Member/MemberActivationReads.php` | 12480 | `78ca392255f602f5ad66387581b74ffe4b6404973ed54a522c691017977ceec4` |
| `app/Domain/Grants/Member/MemberGrantActivations.php` | 11480 | `09afc114a5191d6ebfbb2924b61a5b5345d2605e4c4769dffedfeaf5eaddbbeb` |
| `app/Domain/Memberships/Production/MembershipException.php` | 604 | `d63cb0a6d92d52d3593dd951851de07ebbde6fbe8d48222427f8b1f5acdcc103` |
| `app/Domain/Memberships/Production/MembershipRedemptionWorkflowLock.php` | 6945 | `e925c459abe6366f2ab9ee3be33248e48dc1a9b47d11f2addcf3c1c7b930d107` |
| `app/Domain/Memberships/Production/MembershipReservationOperations.php` | 8918 | `0c5191af752ec1d28b01038365fe061dcb6103c923a426eb6fef0dab75a3efe6` |
| `app/Domain/Memberships/Production/MembershipWorkflowCleanupFailure.php` | 548 | `7cb56d63859ebe8825b36f92d80c05d5a89943014d009414fed6988984eeba8e` |
| `docs/verification/member-activation-20261013/native-coordination-addendum.md` | 4381 | `78e9a07939ac0bc24a0bdd3b414aa5ef45033cdb2e53829f09b517a56f5c8ded` |
| `tests/Support/member-activation-race-worker.php` | 8155 | `14f864f9d535ebd36bc31b4fa2821fd7d8e6e58fb4ec4de8c22bb9b53da635b2` |
| `tests/Support/member-activation-release-race-worker.php` | 10038 | `67648e69c9dcbf21612bfe03533eb04e426883513b2a9bcb56854a02a200cff0` |

Hashes came from exact 5a Git blobs, confined to its delta. Read-only commands used: `git rev-parse/status/diff/show`, `cat`, and bounded Python hashing. Only this runtime note was written. No release/host/provider/payment/legal claim follows.
