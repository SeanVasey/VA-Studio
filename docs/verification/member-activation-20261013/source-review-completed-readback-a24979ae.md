# C4c completed readback implementation source review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No application/test/configuration/CI files were edited and no application/DB workloads, provider actions or hosted checks were run. Only read-only source inspection, bounded Git-blob hashing and this new runtime note were performed.

## Exact target and source conclusion

Inspected clean `a24979ae2ffa2814ee3cdc8ea5558384149230ff`, its complete 11-file delta from `91581edb48ac430fd9e059c6d1592114d386b821` (209 insertions/20 deletions), named unchanged final proof consumers, and the explicit `completed-readback-addendum.md`. Also inspected the earlier five-file test/CI delta from `00867c742843bde196e492a25277c4e9e3d66783` to 91581edb.

**The a24979ae implementation conforms to the accepted completed-only handoff design. No new product P0/P1 or required product correction was established by this bounded source review.** The 915 callback-oracle correction closes the previously reported test-oracle P2 at source level. Its CI classification correction matches the actual test/selection rules. Exact native positive/readback runtime acceptance remains pending.

This is not the final complete-source/evidence C4c DECISION. Preserve C4b's independent approval boundary, the default-off/unmounted C4c state, all original cap/current/committed requirements and the failed native evidence. No release, legal, host, credential, provider, payment, Foundation CI or GitLab claim follows.

## Genuine completed tail and actual retained baseline

`MemberActivationInspection::$completedTail` is initially false and is set only after unchanged committed authorization/facts/reservation callbacks, all raw checks, the single frame finish, finish admission, reservation and authorization postfinish, and final `admit()` return. It cannot be inferred from the earlier `committed` or Reservations `closed` state. The getter additionally requires exact Sources identity, committed and actually inserted state, a real result, and unretired inspection. Actual inserted state can arise only through the existing completion-entry/effect permit and planned-to-inserted path; review/confirmation/live inspections cannot select it.

`completedReadback()` runs finish/configuration/Budget/materialization/deadline admission, reads the captured native PDO through the pure `MembershipRows::identity()` accessor, and calls the exact Reservations getter. It never invokes closed Rows current proof. Reservations requires its registered capture state `closed`, same native PDO, no active PDO transaction and original deadline; Context requires the actual `inserted` transition and its captured finish/configuration/authority/freshness admission.

The returned values come from the actual fresh inserted `memberHistory` snapshot. That snapshot was already compared against complete old+planned member rows and old ledger scope+one consume, then used by unchanged observer and distinct committed callback/raw closures. The exported `history.rows()`, scalar/sorted snapshot `scope()` and normalized stored plan match `HistoryInspection::state()` exactly. `history.original(result.redemptionId)` uses the existing bounded pure retained value decoder; it adds no crypto, filesystem, clock, container resolution or frame renewal.

The owned Source runner extracts this data after the committed frame is closed and before its inspection/frame registrations are retired. `completeActivation()` receives it only after `inspectActivation()`'s outer configuration/Budget/idle tail succeeds, then creates and privately registers the new carrier. Failure anywhere before that return cannot issue a handoff. The carrier contains only the immutable public result; neither it nor its registry retains Rows, a frame or an inspection. Actual original identities/source/artifact are later compared with the newly captured final original, and the final result must equal the stored completion result; no public result alone supplies historical authority.

## Exact registrations, two one-use consumers and cleanup

The owner's completion registration now binds its exact Reads instance in addition to owner/Sources/materialization/locators/principal/actor/claim/original Budget. `consumeCompletion()` removes that registration before checking the exact complete tuple and `owner.reads === reads`.

The Sources-owned completed-readback tuple has exactly 13 entries: Sources, Owner, Reads, captured PDO, definition, redemption, principal, actor, original Budget, exact carrier, exact result object, actual retained original, and complete retained state. The sole factory is the genuine completed path above. The opaque carrier's public value factory cannot populate that map; clone/serialization/restoration/JSON serialization refuse.

`consumeCompletedReadback()` reads and removes the source entry before calling the existing owner's `consumeReadPermit()`. That existing consumer removes the owner permit before exact tuple/Budget admission; the verify permit now binds the exact carrier identity. Only after both operations does Sources accept its exact owner/consumer/locators/principal/actor/Budget/carrier/result tuple. A source mismatch, owner mismatch, expired Budget or unregistered carrier cannot leave a consumed authority reusable. Its registered active workflow was required at completion, and `retireWorkflow()` removes every pending handoff belonging to the original Budget at index 8, on the owner's success/failure finally path.

Fixed owner assembly supplies the same Sources instance to Reads. A foreign Reads object has neither matching completion registration nor owner read permit. The added pure shaped-carrier case correctly refuses at the existing owner read-permit gate before uninitialized dependencies can work. The added successful integration assertions require both completed-readback and owner read-permit registries empty after return. These are useful denial/cleanup checks; they do not directly execute a second use of a genuine carrier, and should not be described as comprehensive runtime single-use proof.

## Captured PDO is actively enforced; final proof is retained

`MemberOriginalIoFrame::identityPrimary()` asserts its already captured complete idle snapshot and returns the native primary stored under its captured database default. It performs no facade getter, SQL, lazy connection resolution or producer callback. The source consumer compares this actual idle primary to its tuple PDO. Reads compares again before configuration/physical I/O. The final private inspection requires the real `DB::connection()->getRawPdo()` to be that same captured PDO before installing its transaction observer. Thus PDO identity is an enforced gate rather than an unused registry value.

Only `Reads::verify()` changes the read sequence. It consumes the genuine handoff, retains original Budget and idle capture, constructs the real `MemberActivationReadConfiguration`, checks idle/Budget after capability resolution, performs the actual retained original `observe()` and `verify()`, rechecks configuration/idle, and runs the existing full final inspection with exact handed-off state/original and expected PDO. The final result must equal the exact expected result, then terminal-safe original observation admission remains last.

The unchanged final inspection still authenticates current and historical owner, full retained graph/257 scope/plan/audit, exact state and original, all callbacks before all raw checks, normal committing delegates before its complete current closure, distinct committed proof, one finish and postfinish/configuration/Budget/observation checks. Its new optional expected-PDO parameter is private and adds only an identity refusal. Public explicit `read()` and replay `probe()` retain the original `execute()` double-inspection sequence byte-for-byte apart from the shared private signature's optional PDO parameter.

No current invoice/catalog/honor/approval gate is added to the history lane. The earlier completion-only getter continues to check its original existing source bounds. The same original confirmation Budget carries workflow/source/honor/first materialization physical and retained-observation minima; no clock is restarted or cap extended. There is no new generic defer mode, schema/owner cache, caller baseline setter or writer retry.

## Addendum and existing failed native evidence

The addendum describes the actual 00867c74 failure at the **second/final after-I/O inspection**, the earlier first inspection and both physical reads having returned, and the missing successful native receipt/post-return graph assertions. It does not silently transfer the d25 diagnostic timings or claim demonstrated 300s elapsed. Its timing/concurrency disclosure is accurate: the private baseline now freezes at completion, so a legitimate later period event can conservatively refuse the immediate receipt while preserving the durable activation and allowing an explicitly invoked fresh historical read. No rebase, retry or compensation was added.

The earlier source-only design note and failed unmodified receipt remain applicable evidence. Removing the first historical inspection saves work before the final one that actually failed. This source review supplies no measured time saving or native performance assurance.

## Callback-oracle correction at 91581edb

The three changed tests now collect actual transaction level, native PDO transaction state and affected track UPDATE rows as scalar/array observations, and assert those observations outside the domain catch. The genuine origin-encryption case also collects the actual pre-insert origin count. Reaching a hook without reaching/completing its mutation leaves missing or wrong observations and fails; an arbitrary wrapped callback assertion can no longer satisfy the intended withdrawal outcome. Forwarded real crypto and full unchanged-effects/rollback assertions remain in place.

This closes **C4C-TEST-ORACLE-P2 at source level** for rendering withdrawal, final ordinary committing withdrawal, and origin-encryption withdrawal. The lead reports their exact 915 run passed 3/66 with zero errors/failures/skips; that report is not converted here into reviewer-run evidence. Withdrawal during the separate inserted-history authentication phase remains explicitly source-reviewed and not directly executed; the previous minimum regression recommendation and claim limitation remain unchanged.

## CI classifications at 91581edb

The ordinary lifecycle file is now explicitly included in native MySQL selection. The two activation worker scripts are added to the driver-branching helper inventory. The new native terminal-order provider method is added to SQLite's reviewed skip census, which selects its entire file/all expanded provider cases for MySQL under `select_native()`; it does not need a second include entry.

`MemberActivationHistoryReaderTest` remains explicitly in `residual_files`, deliberately discovered but unselected on MySQL; its setup remains SQLite-only. Removing its entry from the MySQL expected-skip census is therefore correct: the partitioner requires MySQL skip entries to belong to selected files and otherwise rejects them. This change does not enable the reader test on MySQL or claim that it ran there. No matrix/receipt verification command was run by this reviewer during the active native canary.

## Current focused runtime status and pending acceptance

During this review the lead reports exact frozen a24979ae pure Ownership passed 11/17 and real SQLite positive passed 1/59, zero errors/failures/skips and process/verifier 0; 2318 inputs, runner/autoload/HEAD/status unchanged. The positive includes empty handoff/read-permit registries after return, actual complete graph/conservation/original inode/hash, and a separate explicit historical read. These are lead-reported results; no full new receipt audit was performed here. The same unmodified native positive is active and remains pending.

Native acceptance must still reach the unchanged final closure and actual persisted graph/conservation/row/original-byte/idle assertions on the final frozen product source. Prior failures remain visible; SQLite success does not prove native races or broad acceptance. The final complete-source/evidence C4c DECISION remains a later independent review.

## Exact affected-source hashes

Computed from the exact a24979ae Git blobs with Python standard library, confined to the 11-file delta. These bind this source review, not a complete runtime-input inventory or test execution.

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `app/Domain/Grants/Member/MemberActivationCompletedReadback.php` | 1039 | `06f82d4d747e37b04c14f1035b4d185f3a8899a96fbe13f908c4b5fc6410c9e4` |
| `app/Domain/Grants/Member/MemberActivationInspection.php` | 22562 | `5d6e4c24bd4ad32538152b300332411fbea414ddcdd8f06249ea0d201ed5b9c9` |
| `app/Domain/Grants/Member/MemberActivationReads.php` | 11782 | `ec7d844458ec6048c6dcd1b0c3a8e0319a154a4be1c176efd4b8f0a154ffcb50` |
| `app/Domain/Grants/Member/MemberGrantActivations.php` | 9425 | `e45bb84b3c40accfa8d89019f8f164304375d1f2c4ffe138f8f072f5945d8b7b` |
| `app/Domain/Grants/Member/MemberOriginalIoFrame.php` | 7401 | `f7be7d0ad8f9f5ccc96f7d7a58ae710fc45547978c15763da8cc0bb5e3778079` |
| `app/Domain/Grants/Member/MemberOriginalSourceCapture.php` | 37510 | `ae7b573f2c07ad790e3d9c88a90a393ac2bc8bb84e62acfdf5b4cce3a4b38f15` |
| `app/Domain/Memberships/Production/MembershipReservationContext.php` | 45107 | `da2f1528975b6634837145c532425bb7d7e024ed74e8481aee5eb17c6d2096e1` |
| `app/Domain/Memberships/Production/MembershipReservations.php` | 18792 | `e66f972d2240ae8f6201fe9106440c4661c7f3d622250d55ee3fff5c373974f5` |
| `docs/verification/member-activation-20261013/completed-readback-addendum.md` | 3534 | `1b2f64afe4a81146aade190b39e128b7ce4dca8ee35e70c03c0bca9d94496a67` |
| `tests/Feature/ProductionMemberGrants/MemberGrantActivationIntegrationTest.php` | 8362 | `b26f5de149b4718ab52a4f40093f5bf18acd6d813ff1ee73238e874264a2b2ca` |
| `tests/Unit/MemberActivationOwnershipTest.php` | 9467 | `9e11d5ba3d1398722aefafde862a57a7ef1697be38748d42e245f9de745b5ec0` |

Read-only commands used: `git rev-parse/status/diff/show`, `rg`, `cat`, `sed`, and bounded Python hashing of Git blobs. Only this runtime note was written.
