# C4c shared native coordination source review — 67002abd

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No implementation/test/configuration edits or application/DB workloads were performed. Only read-only source inspection, bounded Git-blob hashing and this new runtime note were performed.

## Exact checkpoint and outcome

Inspected the immutable complete nine-file delta `72dbd1b45d547d2be608cd77e7f3fe15528dead5` -> `67002abd1143413f06cbb4c5fc6dd2d6b280f85c`: 386 insertions/45 deletions. The tree was clean at the initial observed 67002 head. After findings were delivered, the author began the separate correction; subsequent live-file reads are not silently treated as 67002 source or as covered by its focused run.

**Two corrections are required before accepting this implementation checkpoint: committed cleanup error classification/causal preservation, and the explicit acquisition60 admission deadline.** The main scheduling/authority/terminal ordering conforms to the accepted design; these are bounded source findings, not tested failures or the final C4c DECISION.

The lead reports exact 67002 SQLite focused 14/166 passed, zero failures/errors/skips, process/verifier 0, 104.064s, all 2319 source inputs/runner/autoload/HEAD/status unchanged. That selection is Ownership11 + positive1 + lifecycle2. It exercises the unchanged SQLite scheduling branch, not the new native SQL/cleanup failure paths. No native 67002 race result was supplied at this review point. Those passing results cannot be assigned to the author's later cleanup/cap changes.

## Finding C4C-COORD-CLEANUP-P1 — known committed failure can be overwritten

At exact 67002, Owner's finally maps `closeAfterRefusal()` failure using only `$effectCommitted`, set only after `completeActivation()` returns. A Source completion may already commit and throw its existing `committed_activation_receipt_refused` postcommit tail before returning; `$effectCommitted` remains false. If native lock cleanup also refuses, finally replaces that known committed prefix with `source_refused` and discards the primary exception.

The retained activation replay path has the same classification gap. Its complete final proof can succeed, then `lock.finish()` throw a MembershipException. No new effect flag was set; `Reads::execute()` does not classify the terminal unlock failure as a committed retained-receipt refusal. Its finally cleanup can subsequently override that error as source refusal. The accepted protocol expressly requires a successful retained candidate's terminal cleanup failure to refuse its committed receipt.

Ordinary `MembershipReservationOperations::run()` has an unguarded finally `closeAfterRefusal()`. If `runOwned()` already produces `committed_receipt_refused` for final native finish/Context admission failure, outer finally can throw `coordination_refused` instead, dropping the known committed classification and primary cause. A recursive native acquisition, for example, makes finish decrement once, detect continued same-session ownership and throw; finally must not decrement again, so it throws again and demonstrates this overwrite path at source level.

Required correction: preserve explicit known committed/candidate states and existing committed prefixes in both owners; classify terminal unlock failures after a successful retained final proof appropriately; retain both primary and cleanup failures in a reviewable bounded chain/outcome. Always retire exact entry/claim/workflow/read/guard registries despite a cleanup exception. Do not infer rollback just because a completion call failed to return. The helper's acquisition catch must likewise preserve its primary refusal if cleanup itself fails. No repeated unlock or compensation is authorized by this correction.

## Finding C4C-COORD-WAIT-P2 — native timeout is not an admission deadline

Exact 67002 computes `GET_LOCK` timeout as min60/floored remaining original deadline, but after the call it checks only `$originalDeadline`. It never captures or checks `acquisitionStarted +60` as a separate monotonic admission deadline. Under an original300 ticket, a native acquisition returning after its intended60 window can therefore proceed into fresh source frames as long as the ticket itself is still current.

Required correction: capture an explicit acquisition deadline min(original effective deadline, native start+60), pass a bounded native timeout derived from it and check that same deadline before accepting acquisition. Preserve the original caller deadline and every later source/producer cap; this is a fail-closed admission check, not a promise to interrupt a blocked syscall or an extension of any product allowance. Native owner/route admission must not silently create another fresh wait window.

## Conforming source portions

The new fixed helper uses the captured plain native PDO, complete idle snapshot, finish route, actual schema/session and schema/redemption name. SQLite returns null and adds no advisory scheduling. Same-session preexisting ownership refuses before GET_LOCK; errors after acquisition-start attempt only captured same-session ownership cleanup. One RELEASE_LOCK is attempted, a successful response is required, and continued ownership by this session refuses; another legitimate waiting session may immediately acquire. Cleanup never uses RELEASE_ALL_LOCKS, repeated recursive decrements, a replacement handle, transaction commit/rollback or business-row SQL. Original schema is enforced for success; error cleanup may release its own original name/session after a route/schema change without adopting that route.

The helper protects same-key recursion. Different redemption keys remain distinct, with unrelated owner-row contention explicitly outside the addendum's claimed guarantee. It is scheduling state only; every identity/source/grant/history/audit gate remains necessary.

Activation consumes the real claim before acquisition and opens its first history/source frame afterwards under the same original Budget. Its private Budget-keyed registry binds owner/Reads/exact helper; the already consumed exact read permit and source carrier return that helper to the private consumer. The initial absent probe keeps coordination; new completion and retained replay each invoke native finish after the unchanged complete final after-I/O proof/result comparison and before the original observation assertion last. Public read has no guard. No current invoice/catalog/honor gate is added to historical reads.

Ordinary release starts its original60 at the same `run()` entry and carries it into acquisition, Context and committed proof, without reset. Reserve/expire and SQLite keep their previous scheduling. After the full Context committed proof, native finish is followed by `admitAfterCoordination()` preserving captured finish/configuration, original/authority deadlines and invoiceFreshness through the existing callback-free `admit()`. Frame finish already closes its native transaction; subsequent frame close is a no-op. No closed Rows current proof or producer renewal was introduced.

Exact critical activation/current/committed/history/original byte proof files and original `api-contract.md` are unchanged by this delta. The explicit addendum correctly discloses native cleanup as a separate final phase, same-redemption scope, unchanged original bounds, observed prior failures and pending runtime/host evidence. The two findings above must be repaired for its cleanup/bound claims to hold.

## Native test source

The same-redemption test retains a real first parent users.PRIMARY wait, asserts first's actual advisory ownership, then requires second's actual pending USER LEVEL LOCK with exact name/independent pending+granted thread IDs. Both actual worker exits/idle/retained graph/winner A/R/D/conservation/bytes/recovery oracles remain; the successful-writer requirement is unchanged. After workers, the lock must be unowned. Raw postworker counts remain diagnostic only.

The terminal provider still reaches the scheduled winner's genuine owned normal committing hold, but correctly observes the loser's actual advisory wait rather than forcing an owner-row wait that coordination prevents. Exact pending/granted key/thread predicates and afterworker lock absence are asserted; winner receipt, loser reservation_terminal, one terminal, graph iff consume, complete audit/rows/originals/idle remain unchanged. Prior failed row-wait results are not relabelled.

The added native helper checks are real SQL and assertions outside domain catches: preexisting caller-owned key preserved, one-use successful finish, caller-created raw PDO transaction preserved during error cleanup, and injected recursion decremented at most once with explicit test-owned remainder cleanup. These are embedded in the existing case, not newly classified census methods. They do not directly execute the owner-level committed error-mapping branches described above, which require source repair and meaningful evidence/claim limitation.

## Exact nine changed Git blobs

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `app/Domain/Grants/Member/MemberActivationReads.php` | 12026 | `8b2c840b27cc4a0124e0b6505542f73d825c535e4f205088bae861657a04db63` |
| `app/Domain/Grants/Member/MemberGrantActivations.php` | 10928 | `4a56af67a7754ca5e835cbfdcebe9bfe5b18a0613759905175c114806eb6f937` |
| `app/Domain/Grants/Member/MemberOriginalSourceCapture.php` | 37525 | `b36eabaa1c30e483b5fda84ced15da1117ba9a2a55bff016b780a3b4eea5a87c` |
| `app/Domain/Memberships/Production/MembershipRedemptionWorkflowLock.php` | 6473 | `f66c6e7de82e296581c15a6eb8ad6a70bb90e3e3e1fd2bf31fdbb0050ad284b8` |
| `app/Domain/Memberships/Production/MembershipReservationContext.php` | 45329 | `3c89ebc7e51551b2eb11c1bc38e745e9e69bcb340c4a602da9e17f25482f7c6f` |
| `app/Domain/Memberships/Production/MembershipReservationOperations.php` | 8234 | `b735e8fc7f0481c2c35be1baa82b489fafd3236f07202b083fd2a5551e5acae9` |
| `docs/verification/member-activation-20261013/native-coordination-addendum.md` | 3761 | `10d9b4ba6c1c5bd91687ea7c7e89db87d270a6eb2dcc225c0d464456ce9424d3` |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationRaceTest.php` | 21621 | `9961ddb668ff341e4f52f552389781b6eed8a2cc96d627bea9bd3bf2b727fdc7` |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationReleaseRaceTest.php` | 17193 | `ef0c623fab840fe8d0edb3123db4b1789ce7fc3f8491d5e284ee73a78dc68451` |

Hashes came from the exact 67002 Git blobs, not the author's in-progress working correction. Read-only commands used: `git rev-parse/status/diff/show`, `rg`, `cat`, `sed`, `nl`, and bounded Python hashing. Final C4c source/evidence DECISION remains pending; no release/host/payment/legal claim follows. Only this runtime note was written.
