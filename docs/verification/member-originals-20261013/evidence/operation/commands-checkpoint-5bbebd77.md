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
