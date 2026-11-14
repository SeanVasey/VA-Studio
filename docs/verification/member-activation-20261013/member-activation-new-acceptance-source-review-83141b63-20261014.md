# C4c new lifecycle and terminal-race acceptance source review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. This is a bounded ordinary application test-source review under AGENTS and the previously read Code Verification instructions. No application/DB tests, native workloads, hosted checks, provider actions, implementation edits or test edits were performed. The lead's labeled native phase diagnostic was active; this reviewer inspected source only and wrote only this new runtime note.

## Exact reviewed source and result boundary

Clean acceptance worktree `/workspace/VA-Studio-member-activation-acceptance`, exact `83141b6398cab0f9162d3523ed82ee61a1264659`. New test commits: native terminal orders `43c3eba0dbf737468988fc30ca2c2a27aa5fb987`; ordinary lifecycle/equality `8aa788fd807c43a8516e48b1e20f1a747957f933`; import ordering `ab49d341d61f474906d002cde7cacd8e55c298f0`. The two later product carries reproduce the reviewed provisional-grant and atomic-capture corrections. A bounded Git comparison found no difference from root d25f160c in `app`, `bootstrap`, `config`, `database`, `resources`, `composer.json` and `composer.lock`.

Reviewed four affected files and their actual fixture/product call paths. They supply two portable authored cases and two native data-provider cases. These new cases are **static source only**, not green runtime evidence. The lead separately reports root d25f160c Integration 3 / Retention 4 / Phase 6 passing 13 / 328; that selection does not include these two new lifecycle/equality cases or the new native orders. This reviewer did not independently audit that new SQLite receipt here.

| File at 83141b63 | Bytes | SHA-256 |
| --- | ---: | --- |
| tests/Feature/ProductionMemberGrants/MemberGrantOrdinaryLifecycleTest.php | 7977 | `0e7ff6ad56292c911cb07a36c6c0e418bc1132133beb2578b5a7dbcd6c173fe9` |
| tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationReleaseRaceTest.php | 16476 | `405fa1c9f9d09adb760f24ffe2446dcac40c6896f0d13a12c4e049404c82ee92` |
| tests/Support/member-activation-release-race-worker.php | 7584 | `03b542beb2ef977f9c138d9790971265f03eab51dca32e1af7e939df4a1c015a` |
| tests/Feature/ProductionMemberGrants/MemberGrantActivationIntegrationTest.php | 7804 | `ae923d6cfea7c04b9efbc86888a4008b2a801611858b70df4fc08eabb1a7870d` |

Hashes were computed from exact Git blobs with Python standard library. No application imports or DB reads were involved.

## Source conclusions

No concrete product P0/P1 or incorrect lifecycle/race oracle was established in these new sources. The new assertions meaningfully cover the earlier acceptance gaps. Native timing reachability remains unresolved and deserves explicit attention before interpreting a harness failure as a domain failure.

The lifecycle case uses actual `MembershipAwards`, ordinary reservation/expiry/release services and the real retained history reader/full ledger audit. It first obtains a real activation, then proves award replay, a distinct-key second reservation, available-credit expiry at the authored expiry and release of that second reservation. The default honor mode caps at credit expiry, and line 63 correctly requires second honor <= the advanced expiry wall, so release is authored-valid. Independent expected balances are 2/0/1/0 -> 1/1/1/0 -> 0/1/1/1 -> 0/0/1/2, total three credits. Complete member rows including ciphertext/seals, original consume row, PDF hash/inode and idle handles are compared throughout. Cleanup replay, award replay and explicit original read preserve the final audit/effect sets. Award replay correctly expects the original award projection, not current balances: `MembershipPeriodRecords::verify` returns the audit's authenticated first award. Separate billing refreshes are actual synthetic observations before fresh ordinary work; no existing producer token/frame is renewed. The final group shares one fresh observation, whose retained provider freshness is 600 seconds; this is distinct from each operation's original 60-second admission cap.

The equality case holds a genuine pre-honor issuer ticket, proves that neither terminal existed, advances only synthetic business time to exact honor, and obtains a fresh synthetic billing observation. It requires `source_refused` with actual underlying `MembershipException:reservation_terminal`, zero effect/file growth and idle handles. This isolates honor equality without a prior release or consume, unlike the native release-first case. It neither fabricates confirmation authority nor modifies the ticket's original monotonic Budget.

The native case creates independent PHP processes and MySQL connections using actual concrete identity/invoice/policy/catalog/facts/reservation/confirmation/activation services. The activation worker really reviews/confirms before ready; the release worker has no ticket. Worker reflection only records the actual issued authorization ID/hash/time. Both report idle readiness. The selected winner performs its real terminal operation and pauses in an ordinary `TransactionCommitting` delegate only after its actual consume graph or release event exists in that transaction. Thus initial read-only confirmation/source commits do not satisfy the held marker.

The parent releases the loser only after that marker and requires an observed native `users` PRIMARY record wait for the exact buyer row, exact requester/blocker process-list IDs and schema. It then releases the winner's delegate. The test requires a successful actual winner receipt, a null loser result containing `reservation_terminal`, no loser hold, and both idle workers. A bounded refusal from the scheduled winner cannot masquerade as a successful concurrent case.

Post-run checks require one terminal, the exact `award,reserve,consume` or `award,reserve,release` chain, winner receipt/terminal ID equality, a complete origin/artifact/activation graph iff consume, unchanged old member supports/period/redemption, full history audit and conservation. Consume checks retain the real winning authorization and actual activation hash, validate file hash against the artifact, then require exact readback and unchanged rows/hash/inode. Release requires no original file. All of these are independent semantic/state oracles, not proof-call-count assertions.

The disclosed wall-time limits are accurate. Consume-wins runs activation at a pre-honor synthetic wall while release runs at exact honor on its own process-local clock. Release-wins runs both at exact honor but the losing activation sees an already committed release, so its terminal refusal does not independently prove equality. The separate portable case supplies that equality proof. These cases are controlled terminal orders with a real row wait, not two simultaneous equally admissible effects at one global wall or a measured physical-honor crossing.

The IntegrationTest comment is corrected: `ledgerSnapshot` is a real reader plus pure audit, while the actual ordinary writer lifecycle is covered in the new class.

## Timing reachability finding and limits

**C4C-TEST-P2 — start-barrier schedule can outlive its own bound.** Medium severity as a native acceptance-harness reachability gap, high confidence in the source schedule; triggering duration remains unmeasured.

At RaceTest:96–97, the activation worker is always started/readied first. Its worker start wait then begins at Worker:82 with a 120-second cap. In the release-wins dataset, the parent can spend up to 100 seconds obtaining release readiness (RaceTest:201), then start release and spend up to 120 seconds awaiting the release held marker (RaceTest:107), before starting activation at RaceTest:111. Those permitted stages can total 220 seconds after activation readiness, exceeding its 120-second barrier. The total child Process cap is 300 seconds from spawn. The old ticket Budget also continues to run. Thus individually in-bound parent stages do not guarantee the waiting worker can survive the intended schedule.

Smallest correction direction: prepare the worker that will spend the longest time waiting later, or start activation/observe its block as soon as the scheduled winner has acquired a suitable real lock. If retaining the current order, explicitly define consistent harness bounds from measured setup/operation times and the ticket's original remaining allowance; do not restart/extend the product Budget. A harness-only longer start wait is not proof that the old ticket remains live and should not hide product expiry. Record each actual barrier duration and preserve timeout outcomes.

**Remaining hold/headroom uncertainty.** RaceTest:112 allows 18 seconds to observe the loser after Worker:108 starts a 20-second committing-delegate hold. Source proof/record construction/insertion verification have already spent part of the winner's original 60-second source or first-observation allowance. The hold plus subsequent final current/committed/read checks spend its remaining allowance, and observation/receipt I/O also spends the two-second harness margin. A valid product can fail this deliberately delayed test if the remaining original headroom is insufficient; conversely the test correctly refuses to call such a run successful.

This is not a measured impossibility claim. The active labeled diagnostic may establish sufficient headroom or expose the exact failing phase. Prefer promptly staging/observing the losing row wait while the winner owns the appropriate lock, then releasing the committing delegate, without weakening winner-success assertions or enlarging product caps. Keep the harness 20/18/100/120/300 bounds distinct from product 60/300/honor/physical-observation bounds in receipts. A trace-assisted diagnostic with overlays remains distinct from unmodified acceptance.

## Integration and final review boundary

Carry these exact new-test commits into the integrated source, not the entire acceptance worktree as a replacement. Its focused tree comparison also shows absent root-owned PhaseTest/CapacityCleanupTest and a separately changed pure audit fixture test; those are other owners' branch composition, not deletions authorized by this review. Preserve the root's existing tests/evidence and reconcile the selected new files explicitly.

No full C4c DECISION is issued. Native positive and each new case still need exact-source results, observed row-wait receipts, actual winner/loser outcomes and recorded timing limits. Preserve prior failed native results, the independent C4b boundary, default-off/unmounted C4c and unanswered production/legal inputs. No host, provider, payment, credential, Foundation CI or GitLab claim is implied.

Read-only work used `cat`, `git rev-parse/status/log/show/diff`, `rg`, `nl`, and Python Git-blob hashing. A guessed fixture path and a dependency path were absent; the actual fixture inheritance was resolved and inspected. No dependencies were installed, no checks were executed, and only this runtime note was written.
