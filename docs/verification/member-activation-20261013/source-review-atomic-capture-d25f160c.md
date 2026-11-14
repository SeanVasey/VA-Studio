# C4c atomic producer-capture source review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. This is a bounded ordinary application source review under AGENTS and the Code Verification skill, following the accepted design in `member-activation-atomic-capture-design-review-61bebdcf-20261014.md`. No implementation, test, configuration, dependency or repository documentation was edited; no application/DB test, native workload, provider action or hosted check was run. Only read-only source inspection and this new runtime note were performed.

## Exact inspected implementation

Clean root `d25f160c0722ee2e07653fad40d2faeaa6c13029`, commit “Capture fresh activation source proofs and values in one complete issuer call.” Its immediate implementation delta is five files, 54 insertions and six deletions. Inspected the full delta and affected call paths/guards at that exact source. Comparing the broader range from 61bebdcf also shows three C4b handoff/merge documentation changes; those are not part of this atomic implementation assessment and are not treated as independently verified host/merge receipts here.

**The actual implementation matches the accepted atomic-capture design; no new P0/P1 was established in this bounded delta.** All initial complete lock proofs remain. Only the immediately adjacent second full extractor proofs are removed from C4c construction. This is a source conclusion, not full C4c acceptance or evidence that native timing now passes. The lead's focused SQLite selection and native timing diagnostic were pending at this review.

| Path | Bytes at d25f160c | SHA-256 |
| --- | ---: | --- |
| app/Domain/Grants/Member/MemberActivationInspection.php | 20566 | `ea1c3de76851ac54762ddd508bc754bf325a67e3b48ab5b84232a7a3835a342d` |
| app/Domain/Grants/Member/SealedMemberGrantFacts.php | 12749 | `00b19a7bbfe06b2a8ac4265387a37ddd3a6aaf2706840d8ef9a485dca5c07a5f` |
| app/Domain/Memberships/Production/SealedMembershipPaidInvoice.php | 5654 | `620501677139321617304175dbcb142d3dee682c58b07a1118957508c541fdd0` |
| app/Domain/Memberships/Production/CurrentEligibleLicenses.php | 4841 | `0e772752185bffb5efd2fb1705282e92533506d56ae665225832f29862f4b64b` |
| app/Domain/Memberships/Production/MembershipReservationContext.php | 44534 | `9c88ce832d857fe0504fdcea24008f0cbee6ab0490a1ff1262b52965bf076e77` |

These hashes were computed from exact `git show d25f160c:<path>` bytes, using Python standard library. They bind source inspection; they are not a full input inventory or executed test result.

## Authority and callback conclusions

- `SealedMemberGrantFacts::capture:87` calls unchanged complete `lock()`, obtains only that fresh proof's private WeakMap registration, checks exact principal/actor/Rows, asserts the original monotonic facts deadline, and exports the exact registered proof/snapshot. Exact Rows identity preserves its captured primary/schema association; no new source value or registration is constructed at export. Its `deadline:262` is `hrtime` only.
- `SealedMembershipPaidInvoice::capture:83` calls unchanged complete `lock()`, checks the exact principal/actor/Rows/captured PDO tuple, original producer deadline and the exact original freshness object's deadline, then exports that same proof/window. `BillingFreshnessWindow::assertDeadline` performs only monotonic state/deadline checks; it does not sample database time or create/renew a window.
- `CurrentEligibleLicenses::capture:79` calls unchanged complete `lock()`, checks exact principal/actor/Rows/PDO and original snapshot deadline, then exports that exact proof/snapshot. No Carbon/container/native-clock/crypto callback is inserted after the completed lock proof.

All three concrete classes remain final. The new methods accept fresh ordinary lock arguments, not an old proof, deferred flag, caller snapshot or authoritative array. The returned two-element array is an export only; no authority method accepts that array as a substitute for its genuine issuer registration. Existing `lock`, `proveCurrent`, `snapshot`, `freshnessWindow` and interfaces are unchanged in the delta. Existing public extractors still execute their full current proof when called independently with an old issued proof.

`MemberActivationInspection:91` now receives the facts pair and creates the existing exact registered facts closure from that proof. The constructor remains admitted by the source-owned one-use activation inspection tuple.

Context:132–141 selects invoice capture only when its admitted initialization is `MembershipActivationCapture`, explicitly requiring the concrete final `SealedMembershipPaidInvoice`. Context:156–161 selects catalog capture on that same branch, with the existing concrete `CurrentEligibleLicenses` check. The branch is protected by the unchanged issuer-owned activation inspection permit plus the constructor's complete Rows/PDO/configuration/principal/actor/deadline/operation/locator/arguments/capture tuple comparison. A shaped direct capture instance cannot opt into it. Ordinary operations and C4b `MembershipMemberCapture` retain the precise `lock -> binding -> freshnessWindow` and `lock -> snapshot` order.

The delta leaves all later source work intact: readiness, record/audit callback work, mandatory fresh preinsert combined proof, prospective history/capacity audit, fixed native insertion order, exact inserted-graph reread/audit, full postinsert closure, committing proof after ordinary delegates, distinct committed proof, single finish and postfinish original-cap checks. Callback/raw ordering is unchanged. `MemberOriginalInspection`, artifacts, original Budget, observer, authorization issuer, reservation issuer and capability interfaces have no delta from 61bebdcf.

No clock is restarted and no producer/source/freshness/observation/honor/workflow cap is enlarged. No schema ownership result is carried across another frame or callback interval. C4b's prior independent approval/publication boundary and C4c default-off/unmounted state remain separate; this source review does not amend those gates.

## The baseline budget refusal does not demonstrate 300 elapsed seconds

The earlier actual 61bebdcf native result remains **1 / 18 / one error**, process 2/verifier 1, with the raw receipt and independently checked hashes preserved in the preceding design note. It reached the final TransactionCommitting observer and refused in `MemberLicenseAuthorizations::claimData -> MemberOriginalBudget::assertCurrent`. It is not a committed graph or successful readback.

The same original confirmation Budget starts with `confirmationStart + 300s`, and `shortenTo` applies `min`, never replacement/renewal. Before final completion, actual materialization necessarily executes `MemberOriginalArtifacts::prepare:39`:

`budget.shortenTo(observation.observationStartedNs + 60_000_000_000)`.

Therefore the Budget at the failing committing callback was already bounded by **at most 60 seconds from the first actual physical file observation**, also subject to original confirmation and min-only honor caps. The later `verifyCurrentBytes` does not restart that observation. The final frame deadline is itself `min(budget.deadlineNs, frameStart + 60s)`.

The reason name `workflow_deadline` describes the Budget object's expired current minimum. It does not identify which constituent minimum expired, and it does not demonstrate that the original 300-second ceiling elapsed. The 421.290-second invocation includes setup and fixture work and cannot supply that missing measurement. Correct prose is “the original min-only Budget, already shortened to the first physical-observation 60-second cap, refused during committing; per-phase timing and the dominating minimum are not yet measured.”

The pending labeled native diagnostic can determine that minimum by recording original confirmation start/cap, first observation start/cap, actual current Budget/frame deadline, honor/authority/freshness bounds and monotonic phase start/end. Those observations must remain scalar and callback-free at terminal raw phases; their source and limits need explicit attribution.

## Evidence disposition

Source conformity of this narrow change is established by the exact diff/call-path inspection. Runtime acceptance remains **partial/pending**: the preceding SQLite positive 1/57 belongs to 3598015d, and the two native refusals belong to 3598015d and 61bebdcf. They are not passing d25f160c results. Retain both failures when the new selection completes; a timing diagnostic is not a full acceptance matrix or general performance guarantee.

The review found no additional required implementation correction. Complete the already scheduled focused results and inspect their exact tested source before the final C4c complete-source/evidence DECISION. No broad audit, host/provider verification, legal authorization or live activation is implied.

Read-only commands: `git rev-parse`, `git status --short`, `git show`, `git diff`, `nl`, `rg`, and Python hashing of Git blobs. Only this runtime Markdown note was written.
