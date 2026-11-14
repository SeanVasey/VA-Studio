# C4c native race harness correction source review — 2026-10-14

Reviewer: `/root/activation_deadline_independent_review`, separate non-author reviewer. Bounded ordinary application correctness review under AGENTS and the previously read Code Verification instructions. No product/test/configuration/CI edits, application/DB workloads, provider actions or hosted checks were performed. Only this new runtime note was written.

## Exact target and source conclusion

Inspected clean `8f62c7cb1725e9215f606e19db18fc16213e2c18` and its complete four-file delta from `4c84b0445fc9ea3e56f4ef184bb9de2507904253`: 62 insertions/16 deletions, limited to the two native parent tests and two worker scripts. Exact Git comparison shows no delta in application/bootstrap/configuration/database/resources/dependency/PHPUnit/script inputs.

**The correction conforms to the preceding harness-budget recommendation. No new P0/P1 or required source correction was established.** The first-ready preparation failure is corrected at source level; actual revised same-redemption contention and terminal-order results remain unexecuted/pending at review time. This is not a complete C4c DECISION, successful race, product deadline extension or host/payment/legal claim. Preserve C4b's independent boundary and C4c default-off/unmounted state.

## Bounds and unchanged scheduling/oracles

Both parent ready awaits are now 150 seconds, both worker start barriers 180 seconds, and both Process timeouts 600 seconds. The same-redemption parent still prepares workers serially, verifies distinct authentic confirmation identities and unchanged pre-effect rows, holds the real users PRIMARY row, observes actual independent native row waits, then releases and requires the actual graph writer's successful receipt. Its bounded-loser reason/elapsed oracle, one graph/consume, winner A/R/D/hash, conservation, original file and explicit recovery assertions are unchanged.

The terminal family still prepares the scheduled winner first, uses the unchanged 90-second held-marker await, observes a genuine losing users row wait within 18 seconds, and releases the unchanged maximum 20-second normal committing hold. Both terminal dataset oracles remain unchanged: actual winner receipt, loser `reservation_terminal`, exactly one terminal, graph iff consume, exact original/source/audit/conservation/idle results.

These are external harness ceilings. Original product 300-second workflow, 60-second source/physical and min-only honor/producer bounds remain byte-identical. The first same-redemption ticket still spends genuine confirmation time and serial sibling preparation wait inside its original Budget. Increasing the worker start ceiling cannot establish sufficient product headroom or refresh that ticket. The preceding runtime note's required timing inequality and possible future preconfirmation scheduling correction still apply.

## Atomic progress and failure preservation

Each worker now maintains a scalar timestamp map and atomically publishes its PID/latest phase/all prior preparation timestamps plus memory-limit string by writing a worker-specific temporary JSON file and renaming it. Phases distinguish connected, principal ready, review started/ready, confirmation started/ready and operation entered. The connected timestamp brackets the subsequent actor/principal work; a producer start without its ready timestamp accurately identifies incomplete work. No producer call is replaced or moved.

These file writes execute between the genuine producer methods and before activation/release entry, rather than inside their terminal callback/raw phases. They add no domain callback or transferable proof. Actual ready and final worker values include the timestamp map. Parent teardown first stops any remaining process, then copies its stable atomically published public progress into the transcript before removing the worker directory. Earlier preparation stages survive a failure. A worker interrupted between temporary write and rename leaves the previous complete progress record, rather than malformed published JSON.

The newly recorded values are diagnostic stage times, PID, memory limit and the existing public authorization identifiers/hash; no credential/configuration payload is added to these progress files.

## Ticket deadline observation supplies no authority

Workers read the genuine issuer's already registered ticket entry after successful confirmation, copy its existing authorization observation and retain a reference to the existing Budget. They remove the copied ticket entry/authorization variable and call only `deadlineNs()` at ready and immediately before actual operation entry. The inspected Budget getter simply returns the original stored integer; no `start`, `shortenTo`, claim call, registry mutation, copied ticket, permit minting or authority bypass is added. Terminal release workers retain null confirmation/Budget observations, as appropriate.

Ready metadata contains actual `confirm_started_ns` and `ticket_deadline_at_ready_ns`; final metadata contains `ticket_deadline_at_enter_ns`, operation `entered_ns`, finish time and preparation history. Parent already retains its release/barrier observations. The new values can distinguish preparation delay, genuine ticket age and native operation elapsed time; they never replace the product's own admission. If an operation fails before final output, ready metadata and copied operation-entered progress still retain the original ready deadline and entry timestamp.

## Source/evidence carry

The completed native positive at a24979ae and four native 1142 denial datasets at 4c84b044 remain attributed to their original recorded commits/receipts. This new four-file harness delta preserves named product/dependency/CI inputs, so no product change requires repeating those completed selections. New race receipts must record 8f62c7cb and its intentionally changed captured test/worker digest. The failed original 4c84 preparation receipt remains visible and is not relabelled contention evidence.

The lead reports syntax/Pint/diff checks passed; this reviewer did not run them. The next authorized root run is the failed same-redemption selection only. Same-redemption contention, consume/release terminal orders, postcommit recovery and final source/evidence archive remain pending for the eventual independent C4c DECISION.

## Exact four changed Git blobs

| Path | Bytes | SHA-256 |
| --- | ---: | --- |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationRaceTest.php` | 16616 | `fa1c466f16d4a362882a9fe6b0db734c7ab705498515ed3ba2e3849fac767eb2` |
| `tests/Feature/ProductionMemberGrants/MemberGrantNativeActivationReleaseRaceTest.php` | 16914 | `ae15c9e4ce0339a7783a7a7c3ee7da28df7279df9bda5b638f2a4dc398cf7286` |
| `tests/Support/member-activation-race-worker.php` | 6807 | `5e98f5a29e8e2d07a661b888a496937629ecaee73a03b94e9ab9950341a05aee` |
| `tests/Support/member-activation-release-race-worker.php` | 8690 | `ade72f0ea0649b7de5bea76f3a183280a96fcd9e97dd08adb79021b0dc715765` |

Hashes were calculated with Python standard library from the exact Git blobs, confined to the four-file delta. Read-only commands used: `git rev-parse/status/diff/show`, `cat`, and bounded Python hashing. No workload was run.
