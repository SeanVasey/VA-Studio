# Member originals operation receipts

All commands below use `source /workspace/.va-studio-toolchain/activate.sh` and cwd `/workspace/VA-Studio-member-original-operation`, with its own Composer metadata. Reflection confirmed MemberOriginalBudget loads from this worktree. No shared autoload was regenerated.

## Complete budget/I/O dependency

Exact test invocation:

```sh
python3 /workspace/runtime/member-original-operation-evidence/run-focused.py budget-io-initial tests/Unit/MemberOriginalBudgetTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalIoFrameTest.php
```

Actual PHP argv is in `budget-io-initial.source.json`; direct runner sets `_composer_autoload_path` explicitly and retains JUnit, log, exit and source hashes. Result: 12 tests / 45 assertions, zero errors/failures/skips, exit 0, PHP 8.4.26. All 1,942 captured PHP/dependency inputs unchanged. Budget tests are pure; IoFrame tests bootstrap Laravel with independent SQLite in-memory handles and no migrations/application rows. MySQL behavior remains untested. Initial run was a four-file uncommitted overlay over 7edd6144; `budget-io-commit-binding.json` binds all four hashes to the complete dependency commit.

Pint four files: exact argv/source in `budget-io-pint-initial.command.json`, passed exit 0; retained log/exit. `git diff --cached --check` passed immediately before the four-file commit. No red baseline is claimed for wholly new types. An earlier toolchain discovery command found no php on the unactivated default PATH and exited 1 before invoking PHP/tests; subsequent commands explicitly activate the available toolchain.

## Review corrections E2/E3 and restoration defense

Added four focused cases against exact c7310cda MemberOriginalIoFrame source: direct handle native transaction/replacement/lazy closure; foreign facade manager; foreign facade application. Command:

```sh
python3 /workspace/runtime/member-original-operation-evidence/run-focused.py io-review-red tests/Feature/ProductionMemberOriginals/MemberOriginalIoFrameTest.php --filter 'test_direct_handle_|test_database_facade_|test_facade_application_'
```

Expected red: 4 tests / 8 assertions / 4 assertion failures, zero errors/skips, exit1. Raw source/test capsules are retained and hashes independently match that invocation. Fixed raw directPdo/directPdoConfig and Facade app/resolved manager checks: `budget-io-reviewed` passes16/61, zeroall, exit0. After explicit incoming object-restoration defenses/tests, `budget-io-final` passes18/65, zeroall, exit0. Both whole invocations use the same two test paths as the initial command. All1,956 captured inputs unchanged within each invocation. `budget-io-review-binding.json` maps final four owned files to d3aafa1b and records source boundaries; unrelated unexecuted operation drafts are not treated as committed product evidence.

Final `php vendor/bin/pint --test app/Domain/Grants/Member/MemberOriginalBudget.php app/Domain/Grants/Member/MemberOriginalIoFrame.php tests/Unit/MemberOriginalBudgetTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalIoFrameTest.php` returned `{"tool":"pint","result":"passed"}`, exit0, in the tool transcript (no separate final Pint log was retained). `git diff --check --` over those four paths passed before committing. Native MySQL counterparts remain unexecuted.

## Actual MySQL counterparts

After root granted the native slot, committed native-only file98ca4f0a (Pint pass, staged diff check pass) and ran:

```sh
python3 /workspace/runtime/member-original-operation-evidence/setup-private-native.py
python3 /workspace/runtime/member-original-operation-evidence/run-native-focused.py --name native-io-final --driver mysql tests/Feature/ProductionMemberOriginals/MemberOriginalNativeIoFrameTest.php
```

PASS 3 tests /24 assertions, zero errors/failures/skips, exit0,0.481s wall; native MySQL8.4.11 with independent write/read/direct connection IDs in log. Setup independently checks host/container socket,datadir,port identity and creates only owned schema vaseyaudio_member_original_operation. Test bodies run no migrations/schema DDL/application records. All captured inputs unchanged during invocation; selected executed classes/test/dependencies match98ca4f0a Git blobs, explicitly distinct from unrelated unexecuted composition drafts in broad source inventory. Setup result is in native-setup.json; PHP command/environment/runner hash/JUnit/log/exit/source are retained. Native slot released after completion.

## Detached source values

`run-focused.py source-values-initial tests/Unit/MemberOriginalSourceValuesTest.php`:5/15,one assertion failure,zero errors/skips,exit1. The positive test wrongly used PHP insertion-order equality for canonical objects; retained source canonicalizes object keys. Corrected only that test to compare canonical object bytes, preserving list order. `run-focused.py source-values-final tests/Unit/MemberOriginalSourceValuesTest.php`:5/20,zeroall,exit0. Pure value tests, no application or DB. All1,975 captured inputs unchanged. Two exact files mapped to commit in source-values-commit-binding.json. `php vendor/bin/pint --test app/Domain/Grants/Member/MemberOriginalSourceValues.php tests/Unit/MemberOriginalSourceValuesTest.php` passed exit0 in tool transcript; staged diff check passed.

## Committed-frame finish route

`source /workspace/.va-studio-toolchain/activate.sh; python3 /workspace/runtime/member-original-operation-evidence/run-focused.py finish-boundary-native-statements tests/Feature/ProductionMemberOriginals/MemberOriginalFinishBoundaryTest.php` passed 5 tests / 21 assertions, zero errors, failures or skips. This uses native SQLite handles with no schema migrations, not MySQL. The positive control observes shared `finish()` config hooks both before and after physical commit; guarded config/global hooks refuse before execution. Exact two-file commit binding: `3cc2a0d39ad21a98ca238cffcb76ff7939f18b95`. This helper receipt alone does not prove full composition.

## First complete composition source (migration-backed bodies pending)

The operation source, issuer registries, reservation phase split, artifact interface and eleven composed integration cases are delivered together. No existing test double implemented the replaced interface directly; existing preparation/policy fixtures resolve generic Mockery interfaces, so no signature-only edits were required.

Light command: `source /workspace/.va-studio-toolchain/activate.sh` then `python3 /workspace/runtime/member-original-operation-evidence/run-focused.py composition-frame-issuer-final tests/Feature/ProductionMemberOriginals/MemberOriginalFinishBoundaryTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalIssuerOwnershipTest.php`. PASS 10 tests / 45 assertions, zero errors/failures/skips, exit 0; no migrations. This extends the earlier five-case finish receipt with actual secondary read/direct native SQLite transaction refusal.

Prior `composition-issuer-initial` did not execute a test: test class incorrectly used a fixture class as a trait; PHP exited 255 and emitted empty JUnit. The runner then failed parsing that empty XML. Exact erroneous test capsule is retained against its captured hash, and `run-focused-before-empty-xml.py.txt` retains that runner. Corrected fixture static invocation passed `composition-issuer-fixed`, 4/15, zero all, exit 0. Runner now records malformed/empty XML diagnostically instead of losing its result metadata.

Initial Pint failed formatting in Observer and unused import in IssuerOwnershipTest. Applied Pint; subsequent composition-current static checks cover 22 files. `composition-pint-current` and `composition-diff-current` pass; `composition-syntax-current` passes all 22. Complete command file lists are retained in `composition-static-v2-files.json`.

At this checkpoint the eleven migration-backed composition cases are unexecuted. Known facts-lane terminal correction is pending; no complete-composition or native preparation claim follows from the lightweight results.

After later private restoration defenses and complete configuration-baseline admission were added, the lightweight selection was rerun as `composition-frame-issuer-5bbebd77` against committed `5bbebd776ca6cce2be4fd0d427f3baa926c2676d`: 10/45, zero all, exit 0. All 2,003 captured executable/config/test/dependency inputs are unchanged and match Git blobs. The preceding `composition-frame-issuer-final` remains an earlier overlay receipt; it is not relabelled as this commit.

## Complete composition and retained-cap corrections

The later complete composition source adds an issuer-owned entry/capture/claim/readiness registry, one current callback phase per sibling before either raw closure, one distinct committed proof with a single shared physical finish, and retirement before detached I/O. The original captured honor cap now shortens the same workflow budget before detached work. The original reservation Context receives a one-use post-finish freshness/authority check before retirement. Existing reservation wrappers still call their complete current/committed methods; they were not reduced to phase-only entry points.

Correction source is `9d8abd4501b75a2577dc6f72a6a2adb4b6afbf5a`; review-fix syntax/Pint/diff receipts are retained. The separate reviewer source assessment did not itself execute bodies. Actual migration-backed verification followed:

- `run-focused.py composition-canary-initial --stop-on-defect --filter test_real_authored_sources_prepare_an_exact_repeat_without_original_or_credit_effects tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationIntegrationTest.php` executed 1 test /16 assertions, 1 error, zero failures/skips, exit2. Real fixture authoring rejected the catalog's six-digit timestamp spelling before the composed operation. This was not an intended regression red. Facts owner fixed only strict timestamp compatibility without rewriting retained values/hashes (`a7265e8c`, cherry-picked here as `43e58632`).
- `run-focused.py reservation-wrappers-initial --stop-on-defect tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php tests/Feature/ProductionMembershipBilling/MembershipReservationOwnershipTest.php tests/Feature/ProductionMembershipBilling/MembershipReservationEntryTest.php` passed all17/345, zero errors/failures/skips, exit0,216.155s, at9d8abd45. Full2,006-input before/after inventory unchanged and matched Git. `reservation-wrapper-carry.json` preserves that source boundary and lists the three later changed inputs; the phase split, wrapper files and shared reservation fixture remain byte-identical.
- The expanded `run-composed.py` runner additionally inventories contract resources and the actual PDF child program. `composition-canary-timestamp-fixed` passed the selected real candidate/exact-repeat case1/48, zero all, exit0,35.558s, at43e58632. All2,034 inputs unchanged and Git-matched. Full exact argv/filter is retained in the source JSON.

`run-retained-deadline-overlay.py retained-deadline-old-bridge-red tests/Feature/ProductionMemberOriginals/MemberOriginalRetainedDeadlineTest.php` ran both cases without fail-fast; exact PHP argv and preload order are retained in its source JSON. It preloads only exact `MemberOriginalInspection.php` from5bbebd77 (SHA-256 `9e3190d4f4c93e23d2995d4ad58ea4ecfaaffb8c5a2acd991fe9dda1a64ea89f`) over the current supporting application, not a complete old checkout. The same-byte retained-deadline test produced2/43, two intended assertion failures, zero errors/skips, exit1,41.837s. First, detached work lost the original honor cap; second, the old bridge returned after the original invoice window was consumed during shared finish. Both fixtures deliberately narrow actual issued private caps and make no physical elapsed-time or native-MySQL claim. The external exact source capsule and original/fixed comparison are retained.

`run-composed.py composition-fifteen-initial --stop-on-defect tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationIntegrationTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalRetainedDeadlineTest.php` stopped after9/269 with one failure, zero errors/skips, exit1,208.684s. My facts-phase test incorrectly assumed a Carbon callback would run there; no catalog mutation occurred. The earlier eight passing leaves are retained inside the failed invocation, which is not reported as a green run.

The only ensuing source change is test-only `2cfd9a0db46a806c0a0bd0c83839b5b584fc3627`: use the real retained-record decrypt callback during `SealedMemberGrantFacts::currentCallbacks`, then assert refusal and rollback of its catalog withdrawal. Syntax, Pint and diff check passed in the tool transcript (no separate correction-only static logs). `run-composed.py composition-facts-callback-corrected --stop-on-defect --filter test_facts_callback_cannot_withdraw_catalog_after_reservation_admission tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php` passed1/25, zero all, exit0,18.757s. It was a precommit one-file overlay overa082; the complete captured input map matches subsequent2cfd Git bytes.

Final composed command:

```sh
source /workspace/.va-studio-toolchain/activate.sh
python3 /workspace/runtime/member-original-operation-evidence/run-composed.py composition-fifteen-final --stop-on-defect tests/Feature/ProductionMemberOriginals/MemberOriginalPreparationIntegrationTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalCompositionTest.php tests/Feature/ProductionMemberOriginals/MemberOriginalRetainedDeadlineTest.php
```

PASS **15 tests /432 assertions**, zero errors/failures/skips, exit0,314.484s wall (JUnit314.186498s), exact author `2cfd9a0db46a806c0a0bd0c83839b5b584fc3627`. Class breakdown: real integration4/148, composed boundaries9/228, retained deadlines2/56. All2,034 captured inputs stayed unchanged and match Git. Both retained-deadline tests are byte-identical to the intended old-Inspection red; the class overlay is the explicit differing bridge source.

Final existing portable clock wrapper command:

```sh
python3 /workspace/runtime/member-original-operation-evidence/run-composed.py reservation-clock-wrapper-final --stop-on-defect --filter test_terminal_clock_callback_cannot_withdraw_identity_after_the_last_precommit_owner_check tests/Feature/ProductionMembershipBilling/MembershipReservationClockClosureTest.php
```

PASS **1 test /18 assertions**, zero errors/failures/skips, exit0,13.044s wall (JUnit12.781017s), same2cfd source and all2,034 inputs unchanged/Git-matched. The native-only sibling in that file was not selected and is not counted as a skip or native verification.

`composition-source-bindings.json` verifies the full captured inventories, runner hashes, precise precommit test overlay, and exact old class capsule. `retained-deadline-red-green-comparison.json` preserves the two same-byte red/fixed cases. `composition-final-coverage.json` lists the15+17+1 migration-backed SQLite cases under their three separate invocations/source boundaries; this is not a single33-case execution or a full suite claim. The earlier17-case invocation carries only with the explicit source comparison, never relabelled as a rerun on2cfd. All prior parser, fixture, inert-callback, style and expected regression failures remain in the release.

The only native MySQL behavior executed in this operation lane is the earlier3/24 native I/O counterpart selection onMySQL8.4.11, with real write/read/direct handles and no migrations. All composed preparation/readiness and retained-cap cases above used SQLite. Root owns subsequent native composed canaries and final integrated-source review. No customer activation/credit consume implementation, runtime mounting, production host/provider verification, real billing, performance or exclusive-CPU claim is made. Root ran a separate pure-unit-only selection during the final composition invocation; no simultaneous migration/native workload was reported. The heavy slot was explicitly released after the clock check completed.
