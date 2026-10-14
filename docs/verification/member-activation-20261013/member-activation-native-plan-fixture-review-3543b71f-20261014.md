# C4c native plan fixture correction review — 3543b71f

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded read-only ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No implementation/test/configuration edits or application/DB workloads were performed. Only this new runtime note was written.

## Exact correction and conclusion

Inspected clean `3543b71fed3c8dd4b05aefb3b6fc9cf6d7bfd56d` and its complete two-file delta from `4990315a31b2b7b87dd46282559ba9ebb5f75310`: eight insertions/two deletions. Both native parent tests now load the actual stored plan record from `MembershipRecords::TABLE` by the exact authored fixture ID, assert it exists, and pass the full sealed scalar row to unchanged `MembershipPolicyVersions::document()->binding()`.

**This is the correct test-only fixture repair; no new P0/P1 or required correction was established.** It authenticates the real retained plan instead of passing the authoring return summary `{id, policy_hash, provenance}` to a full sealed-record codec. It does not substitute policy authority, invent a graph, relax an assertion or alter the product. I missed this fixture-to-codec mismatch in the earlier static native-parent review; the recorded native execution identified it before the remaining parent proof assertions.

Exact Git comparison shows no changes to application/bootstrap/configuration/database/resources/dependencies/PHPUnit/CI scripts, Support workers, or the selected SQLite Ownership/positive/lifecycle files. The preceding bounded combined product source acceptance at 499 carries. The 499 SQLite 17/176 result remains its own exact source; byte-identical selected inputs carry, not an invented rerun at 3543. One precise rerun of the failed native selection at 3543 is appropriate; final C4c acceptance remains pending.

## Independently reconciled native 499 outcome

Read `mysql-activation-same-redemption-shared-coordination/result.json` and the preserved `parent-race-transcript.json`: transcript 15678 bytes, SHA-256 `e33cb6bd826e8bc2a7813f7dc01210a9c0ef370920ef87b25e6fc704289c4b9a`. Exact 499 receipt reports one test / 60 assertions / one error, zero failures/skips, process 2/verifier 1, 438.106313s including fixture work; all 2320 source inputs, runner/autoload/HEAD/status unchanged, digest `b0641a0c09e0d717022098a4b7c2005938b58495f976c53cfea7e35612b41bb8`.

The real first worker returns its actual activated result with empty errors in 95.028634s. The second returns null with actual `source_deadline -> operation_deadline` at the guard acquisition in 60.004790s. Both exit 0, native/framework idle and not stopped by teardown. Parent actually observes first's users.PRIMARY row wait against its held row and second's USER LEVEL LOCK wait against first's exact key/session. Strict successful-writer/bounded-loser, native primitive ownership checks and afterworker coordination lock absence are reached before the later codec error.

Raw postworker metadata reports one origin/artifact/activation and award/reserve/consume. Parent's count/event-kind checks passed before the codec error; this is observed complete cardinality metadata, not execution of the subsequent authenticated winner A/R/D/hash, full conservation/audit, original bytes and explicit recovery assertions. Those later assertions were **unrun**. Overall 499 is an errored native selection and must remain recorded as such even though it contains the first genuine successful coordinated writer.

The second terminal parent's equivalent summary-to-codec call is corrected proactively from the same source defect. That correction is not a passing terminal dataset result. Prior early/terminal failures and all their exact sources remain visible.

## Exact changed Git blobs

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationRaceTest.php` | 21829 | `6f317e8002f46804471c58c679c65f7427406c219487ef3d224cb6abd845f92b` |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationReleaseRaceTest.php` | 17409 | `310a59a353baa849303c33950a252916975554685d5e94d8a542c77274014b57` |

Hashes came from exact 3543 Git blobs. Lead reports syntax2/Pint2/diff checks passed; this reviewer did not run them. Read-only commands used: `git rev-parse/status/diff/show`, `cat`, and bounded Python parsing/hashing of the named public transcript/source blobs. Only this runtime note was written.

No complete C4c DECISION, merge, production enablement, provider/payment/host/legal claim is supplied. Preserve C4b's independent boundary and default-off/unmounted C4c state while actual native same-redemption, both terminal orders, final affected receipts and archive reconciliation remain pending.
