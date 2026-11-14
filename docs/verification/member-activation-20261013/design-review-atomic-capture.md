# C4c atomic producer-capture design review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. This is the requested source-design follow-up to `member-activation-deadline-review-61bebdcf-20261014.md`, under repository AGENTS and the Code Verification skill. No implementation/tests/configuration were edited and no application/DB test, native workload, provider call or hosted check was run. Only read-only source/small-receipt inspection and this new runtime note were performed.

## Source-design conclusion

**Accept the proposed additive concrete atomic capture APIs at design level.** No P0/P1 is established in the described design, provided every helper calls its unchanged complete concrete `lock()` and immediately exports that fresh call's exact private-registry value without callbacks, new windows or caller-supplied old proofs. The concrete classes are final. This is a design assessment before implementation; it is not approval of code that does not yet exist or a passing native result.

The inspected product basis is `61bebdcfaf2fe4ca647dfd503f15e1a63bceadcc`. A later observed workspace HEAD was `4da50ac0d57784b41fb8f562c0e57e17b9427f0a`; the subsequent read-only whole-tree diff against 61bebdcf and focused five-file product diff returned no changes. The design assessment concerns the currently unchanged producer and constructor implementation, not an executed atomic-capture implementation. The eventual exact implementation SHA needs its own focused source inspection and receipts.

Preserve C4b's independent approval/publication boundary, original 60-second producer/source/observation and 300-second workflow caps, min-only honor carry, strict private authority, and default-off/unmounted C4c. This note makes no host, GitLab, Foundation CI, live credential/payment, legal-assent or launch claim.

## Second native result and its actual limit

Independently read `/workspace/runtime/member-activation-root-evidence/mysql-activation-private-plan-fixed/{command.json,result.json,log.txt,junit.xml}`. The exact same positive selection at 61bebdcf, no overlay, reports **1 test / 18 assertions / 1 error / 0 failures / 0 skips**, process exit 2/verifier exit 1. All 2,312 source/inventory inputs, loaders/runner, HEAD and status observations remained unchanged according to the source-bound receipt.

Actual failing call chain:

`MemberOriginalSourceCapture::inspectActivation:404 -> Laravel commit/TransactionCommitting -> MemberActivationObserver::dispatch:97 -> MemberActivationInspection::proveCurrent:290 -> MemberLicenseAuthorizations::currentCallbacks:160 -> callbacks:205 -> claimData:231 -> MemberOriginalBudget::assertCurrent:31`.

The native preinsert proof, four INSERTs, exact inserted-graph verification, and postinsert proof had returned along this source path before the final committing observer began. The failure occurs before Laravel's physical PDO commit on this trace. This is not a completed durable activation or readback; no committed graph is claimed, and the reviewer did not query state after rollback.

The reported reason is `workflow_deadline` from the **original min-only MemberOriginalBudget**. That object can already have shortened to honor and the first physical-observation start plus 60 seconds. The reason does not establish that the original 300-second ceiling itself elapsed. Whole invocation 421.290184 seconds and JUnit 421.058146 seconds remain setup/fixture-inclusive; neither is a phase or individual frame measurement. The source improvement moved the observed failure later, but these two single runs do not measure its duration savings or resolve which original bound dominates.

Receipt hash/size and JUnit totals were independently checked with Python standard library only:

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| command.json | 2027 | `da67032138248750afeb88439e04a80afaef0d91737d597179707c679b435fc6` |
| result.json | 912 | `436d0ea997e7cda33b5eb519886f9a466fd13f78b3938d8e5adf1281999e5b5d` |
| log.txt | 2589 | `ad17209ea800b60dffa6ece1fa67b5bc53dd5f9093ff2060fa5c053d5b5994bd` |
| junit.xml | 1674 | `b4ea4f06a45e5a4571ece712664b054e1dc46f507ddca5be1c15ac61f858acba` |

## Exact proposed API obligations

The new concrete methods may return `[proof, value]` with a precise PHPDoc array shape. Each accepts the same locator/selection/definition, current principal/actor and Rows arguments as the ordinary `lock`. It must:

1. Invoke the existing complete final concrete `lock()` unchanged. No replacement partial loader, shared pending flag, optional defer parameter or caller-provided proof enters this path.
2. Obtain only the proof returned by that call and read only its same-issuer private registration. Return the exact registered `MemberGrantFactsSnapshot`, `MembershipCatalogSnapshot`, or original `BillingFreshnessWindow` alongside that fresh proof. Never construct a new window, transfer another registration or mint replacement deadline evidence.
3. Export using callback-free operations. Private WeakMap lookup, exact tuple/type checks and monotonic `hrtime`/existing callback-free deadline assertions are suitable. Do not execute Carbon callbacks, encrypt/decrypt, container/framework resolution, native database clock sampling, producer refresh or another `lock` between completed proof and export. If adding final export deadline checks, use the already captured bounds; their sole effect must be refusal/shortening.
4. Leave public `lock`, `snapshot`, `freshnessWindow` and `proveCurrent` signatures and behavior unchanged. A caller presenting a previously issued proof to an old extractor still needs fresh full current proof; no old-proof shortcut is introduced.

A public additive fresh-capture method needs no extra caller-supplied activation permit to be safe at the producer boundary: its unchanged full `lock` supplies exactly the authority already available to authenticated callers. It exports no stronger authority than the existing `lock` plus immediate extractor. The important restriction is that it cannot accept an old proof and cannot split capture/export across a callback-capable caller interval. It supplies one proved pair within one synchronous PHP call, not a transaction/clock lease beyond existing registrations.

`BillingFreshnessWindow` is a technical lifetime object, not paid authority. Returning the exact existing object preserves its immutable receipt identity, original monotonic deadline and min-only checkpoint. Calling its database-time sampling APIs at export would add work and cross the specified callback-free boundary; a scalar monotonic deadline assertion is sufficient if an extra assertion is desired.

## Restrict integration to the admitted C4c construction branch

`MemberActivationInspection` is already admitted by the exact source-owner activation inspection permit. It may replace its adjacent facts `lock -> snapshot` with the new facts capture pair, then create the unchanged closure from the returned registered proof.

Within `MembershipReservationContext`, the atomic invoice and catalog pairs must be selected only for `$memberInitialization instanceof MembershipActivationCapture`. Before that branch, `inspectMember` consumes the exact issuer's activation inspection permit; `inspectAt` registers the complete Rows/PDO/configuration/principal/actor/deadline/operation/locator/arguments/capture tuple; the constructor consumes and compares that tuple. A directly constructed `MembershipActivationCapture::value()` cannot reach the branch with a valid tuple. This is the existing private ownership boundary, not a new public pending-initialization flag.

Because the configured invoice capability has an interface type, this new branch should explicitly require the actual final `SealedMembershipPaidInvoice` before calling its additive concrete method. The existing catalog check already requires actual final `CurrentEligibleLicenses`. Keep ordinary reserve/release/expire/public inspect and `MembershipMemberCapture` C4b construction on their existing `lock -> extractor` paths. Keep all interfaces unchanged rather than requiring arbitrary implementations to provide a new shortcut.

The saved work is concrete but unmeasured: each C4c frame avoids the immediately adjacent second full facts snapshot proof, second invoice freshness-window proof, and second catalog snapshot proof. Source frames still perform their real initial complete source locks; ongoing callbacks and final closures still reprove those exact issued registrations. These savings apply across confirmation/source/completion construction without reusing invoice/catalog authority across frames or refreshing the original confirmation budget.

## Obligations that must remain separate

Retain the complete combined readiness proof; fresh preinsert proof after all planned record/audit callbacks; prospective full history/capacity audit; exact planned-to-inserting and inserted graph transition; normal final committing proof after ordinary delegates; distinct committed proof; single finish; and postfinish configuration/original-cap checks. Keep all participating callback phases before terminal raw closures. The atomic APIs only remove immediate duplicate extractor work after initial lock, not any of these later phases.

The public standalone source APIs still need their complete original currentness and expiry behavior. Do not treat successful `capture()` as a permanent already-proved marker, do not share a current schema result with another frame, and do not replace current SHOW/qualified native predicates with an RR metadata inventory.

Focused verification should demonstrate the actual positive operation on the exact changed source and retain the negative source/owner/cap cases. Useful acceptance conditions are: withdrawal in the original lock's last callback still refuses; withdrawal after a returned pair is caught by later current proof; and an old public extractor still reproves after source withdrawal. Use actual genuine issuers and state/deadline outcomes, not a private proof-call-count assertion. Existing ordinary/C4b evidence can be carried only when their executed implementation/test inputs remain unchanged or the changed shared classes are separately reconciled as behavior-compatible.

The native positive remains an unresolved development availability/acceptance blocker (prior C4C-DL-P2). The additive design is a justified next correction, but its runtime sufficiency cannot be inferred. If another native run fails, distinguish source/observation/honor/workflow bounds using narrowly labeled monotonic phase instrumentation, copied scalars and exact source attribution; do not extend caps or repeat unmeasured broad proof deletion. Final C4c complete-source/evidence DECISION remains pending implementation and focused results.

Read-only commands in this follow-up: `cat`, `nl`, `rg`, `git rev-parse`, `git status --short`, `git diff`, and Python receipt hashing/JUnit parsing. No application imports, DB reads or test execution were performed. Only this runtime Markdown note was created.
