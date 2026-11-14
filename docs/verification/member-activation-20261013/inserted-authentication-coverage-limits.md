# C4c inserted-history authentication regression recommendation — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author final reviewer. Requested bounded read-only recommendation under AGENTS and Code Verification. No implementation/test/shared documentation edit, application/DB workload, provider action or hosted check was performed. Only source inspection and this runtime note were written. The lead's unmodified private native positive canary at 00867c74 was active; its result is not anticipated.

## Approval position

**I do not require an additional direct inserted-history-authentication withdrawal case as a new development-batch gate.** The existing real final TransactionCommitting catalog-withdrawal case mutates the same source at a later point, after complete insertion, and tests the unchanged mandatory final source closure and rollback. Combined with exact source review of the new graph/audit/provisional boundaries and the agreed positive/native/rollback/concurrency evidence, it can support this bounded development batch.

The claim limit must remain precise: withdrawal specifically during inserted-history authentication is **source-reviewed, not directly executed**. This is not broad callback coverage or full platform acceptance. The already agreed native positive and native cases remain their own evidence requirements; an explicit later read cannot conceal a failed immediate committed receipt.

**There is a concrete weakness in the existing callback-test oracles that must be corrected before counting them as final withdrawal evidence.** The lead agreed to that correction after the active source-frozen native run. This is repair of required evidence, not a demand for an additional direct-phase case.

## Minimum direct case if subsequently desired

One actual activation with a genuine pre-issued confirmation ticket is sufficient. Use the real forwarding crypto service; do not replace a facts/grant/source proof or encrypted result. Arm the first `decryptString` observation only when:

- The actual decrypted envelope has `schema_version = member-origin-record-v1`.
- The read-only call stack includes `MembershipReservationContext::proveInsertedActivation`, whose `MemberActivationHistoryReader::capturePeriod -> MemberActivationHistory::fromRows -> MemberActivationRecords::readOrigin` is authenticating the **new real inserted** origin. This distinguishes it from prospective planned-history authentication and later committing/committed history rereads.

The hook returns the real unchanged plaintext. At that point record observations of Laravel transaction level one, the captured PDO in a transaction, and the exact origin/artifact/consume/activation rows for that redemption all present. Actually update the selected real catalog track from published to draft, and record the UPDATE's affected-row count. Increment the successful-withdrawal count only after the update has completed. Keep all assertions about those observations outside the crypto/domain call, because codecs and operation owners intentionally catch arbitrary callback failures.

For precise attribution, one normal TransactionCommitting delegate can record that the withdrawal was already observed and the complete graph remains uncommitted when it runs. This proves the mutation survived inserted graph verification and callback-free admission, then reached the retained final source boundary. It is a phase observation, not another source issuer or replacement proof. Assert the delegate marker and its recorded draft status/graph/transaction facts afterward.

Expected operation outcome: actual `MemberGrantException:source_refused` before physical commit; no activation result. The complete period/redemption/event/member row sets, including ciphertext and seals, must equal the pre-activation snapshot after refusal. In particular there is no consume and no origin/artifact/activation; the selected track status is restored to published by the same rollback. Full conserved balances remain the original reserved-credit balances, and Laravel/native PDO handles are idle. No clock or lease is changed.

Rendering/materialization already occurred outside the final transaction. The detached candidate PDF may remain in private fixture storage, as existing rollback cases permit; require **zero activated graph/consume**, not file absence or deletion. If recording the candidate hash/inode, it must not become a grant or download credential. There is no postcommit recovery expectation because this test deliberately refuses before the physical commit.

A source refusal raised by the crypto spy's own assertion is not a passing reproduction. A correct test records actual observations and mutation counts, then checks the actual whole outcome outside every production catch. One SQLite case with genuine producers would directly cover this same-transaction callback sequence; it would not establish MySQL concurrency/locking, which belongs to the separate native cases.

## C4C-TEST-ORACLE-P2 — callback assertions can masquerade as successful withdrawal evidence

Medium severity, high confidence from source control flow. At the current RetentionTest final-committing and origin-encryption cases, `$calls` is incremented before PHPUnit assertions and before the SQL UPDATE. `MemberOriginalSourceCapture::inspectActivation` catches any Throwable and wraps it as `source_refused` while its captured PDO is still active. Decryption codecs additionally translate arbitrary Throwable into `tampered` before that outer wrapper.

Consequently an inner assertion failure can produce the exact expected outer refusal; `$calls === 1`, unchanged effects and original track status can all still hold even when the actual withdrawal did not complete. For example, an UPDATE returning zero raises an inner assertion, the domain wrapper supplies the expected refusal, and rollback leaves exactly the state the outer assertions expect. Reaching the callback is not the same evidence as completing its actual mutation.

Smallest necessary correction: capture the observed transaction/frame/table-state facts and actual affected-row count in scalars/arrays inside each hook; assert those facts and completed mutation after the operation's refusal, outside the domain catch. Alternatively, explicitly propagate the original PHPUnit assertion outside the operation wrapper; observation data is simpler. Apply this to the real origin-encryption, final-committing and rendering-withdrawal hooks the lead identified. Do not count a failed/no-op/unreached mutation as green simply because the domain failed closed.

The lead has agreed that this oracle correction is necessary before final independent approval. The added direct inserted-authentication case remains optional, with its precise direct-phase coverage limit retained. No application defect or new P0/P1 is claimed from this test-source finding.

## Lifecycle replay correction carry

Inspected exact `00867c742843bde196e492a25277c4e9e3d66783`, sole test-only four-line delta in `MemberGrantOrdinaryLifecycleTest`. It compares expiry replay with the original expiry identity/status **and the current full audit balances**. This matches `MembershipReservationContext::expire:648–649`, which returns the original expiry event ID and current audited balances after cleanup. The earlier whole-receipt equality incorrectly retained pre-release reserved/expired balances. I missed that replay-balance assertion in the earlier static lifecycle pass; the actual run exposed it.

The correction preserves the independently specified final balances 0 available / 0 reserved / 1 consumed / 2 expired, exact ledger/member/file comparisons, original release/award identities, conserved total and idle handles. It neither relaxes an economic invariant nor changes product behavior. A focused Git comparison confirms product/app/config/schema/resource/dependency bytes unchanged from 89cf40f0 through 00867c74.

Exact corrected lifecycle Git blob: 8,121 bytes, SHA-256 `37dff98e78ac6a552c3ca3220aecea6e1bc24fb480589971daf12262f67ddf0d`.

The lead reports the earlier a47 broad SQLite selection ended **15 / 407 / one failure / no errors/skips**, preserving the first lifecycle failure; its Integration 3, Retention 5 and Phase 6 cases passed. The corrected narrow lifecycle selection at 00867c74 then passed **2 / 90**, no errors/failures/skips, process/verifier zero, with unchanged 2,317 inputs/runner/autoload/HEAD/status. These are separate source-bound invocations, not one green 16-case run. This recommendation did not independently audit those receipt inventories and does not infer native success.

Final complete-source/evidence DECISION remains pending the actual agreed native results and corrected callback oracles. C4b's independent boundary, default-off/unmounted C4c and unresolved production/legal inputs remain unchanged. No host, credential, provider, live payment, Foundation CI or GitLab verification is claimed.

Read-only commands: `git rev-parse/status/show/diff`, `nl`, `rg`, and Python standard-library Git-blob hashing. Only this runtime note was created.
