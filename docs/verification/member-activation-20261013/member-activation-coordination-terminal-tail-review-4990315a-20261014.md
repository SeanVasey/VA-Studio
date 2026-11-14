# C4c coordination terminal-tail review — 4990315a

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded read-only ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No implementation/test/configuration edits or application/DB workloads were performed. Only this new runtime note was written.

## Exact source conclusion

Inspected clean `4990315a31b2b7b87dd46282559ba9ebb5f75310` and its complete two-file delta from `5a5790f16bbc24939b770935d7c3ad8d15e7b2c8`: 68 insertions/seven deletions. The independently reviewed combined source/design carries for unchanged portions.

**The remaining private retained replay last-admission edge is corrected. The 67002 cleanup/cap findings and the 5a terminal-tail obligation are now closed at source. No remaining product P0/P1 or required correction was established in the bounded combined source review.** Focused native early-race reproduction is an appropriate next step after the exact-source focused SQLite selection completes. This is source acceptance only, not a passing native race or the final C4c DECISION.

## Private terminal tail and unchanged public scope

The new private `finishRetainedTail()` covers both the native scheduling finish/cached admission and the **last** retained-original `assertCurrent()` in one committed-refusal wrapper. It executes only after the unchanged full final inspection and exact result comparison returned. If either operation fails, actual cause is preserved under `committed_activation_receipt_refused`, including independently confirmed retained replay with no newly committed effect flag.

The operation distinction is confined to private code: genuine owner-permitted `probe()` passes true to its private `execute()`, and genuine completed-carrier `verify()` calls the true tail directly. The original absent probe returns before the tail and keeps its coordination for the live branch. Public historical read uses the false default; it performs the same unwrapped final original admission and no native unlock. No public defer/release flag, caller callback or shaped guard can select this tail; exact existing read permit/source carrier/owner Budget guard registrations remain mandatory.

The final original observation assertion remains last in either branch. No subsequent file I/O, source/producer refresh, config/facade getter, frame query or new clock is added. All original Budget, source/producer, honor and physical minima stay unchanged. The native guard, ordinary release Context admission and both compound-cleanup owners are unchanged from the separately reviewed 5a correction.

## New pure tests accurately limited

The three added unit cases verify: an actual shortened original Budget expiry produces private activation replay committed classification with its actual `workflow_deadline` cause; the public historical-read tail retains its existing unprefixed reason/no previous cause; and the actual primary and cleanup Throwable identities remain separately available under the minimized MembershipException message.

The expiry tests explicitly populate a local retained-original adapter's private observation map with shaped values solely to reach its pure Budget/terminal classification branch. The Budget starts genuinely and is shortened into the past; it is never renewed. The original is uninitialized, observation is a publicly constructible value, and no issuer, graph, file, PDO, SQL, successful unlock or actual complete read is claimed. The inspected observation constructor performs no validating admission, so `synthetic` reaches the intended pure tail rather than failing fixture construction. These are meaningful isolated outcome tests, not comprehensive owner cleanup/replay authority evidence and not a claim of old-source behavioral red execution.

The lead reports syntax2/Pint2/diff checks passed and will run the new 17-case SQLite selection at 499; no result from that selection was available at this review point. Earlier exact 67002 and 5a 14/166 results remain attached to their original source and do not cover these new tests. Native owned-session/cleanup primitive checks, same-redemption and both terminal orders still require their actual frozen-source receipts.

## Prior source review notes retained

The relevant source conclusions are separately recorded in:

- `member-activation-shared-coordination-source-review-67002abd-20261014.md`: exact initial implementation, required cleanup/cap corrections and nine hashes.
- `member-activation-coordination-correction-review-5a5790f1-20261014.md`: closed two initial findings, bounded causal metadata, exact nine correction hashes and the last-admission edge.
- This exact 499 note: closure of that remaining edge and the two new hashes below.

No failed source/run is relabelled passing by this sequence. Preserve the explicit addendum/original-contract bytes, C4b independent boundary and C4c default-off/unmounted state. Final complete-source/evidence DECISION, actual native availability/terminal/readback outcomes and archive reconciliation remain pending; no merge, production, provider, payment, host or legal claim follows.

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `app/Domain/Grants/Member/MemberActivationReads.php` | 12778 | `a166de77216dcc37956c161c5746f2339ba034135d5919b04c2b17734344767e` |
| `tests/Unit/MemberActivationOwnershipTest.php` | 12561 | `7d411fb8a1f59d2ffa1691c2ac3f5fce01dad2d569409e43f16eb839e3deb114` |

Hashes came from exact 499 Git blobs. Read-only commands used: `git rev-parse/status/diff/show`, `cat`, and bounded Python hashing. Only this runtime note was written.
