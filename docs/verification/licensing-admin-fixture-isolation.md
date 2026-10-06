# Licensing administration fixture isolation

The composed candidate starts from local `b80e6cfbba67693a283a2939e383283393b23a9f`, tree `93129848423c8a21ec044e8a5cad8d178bc85b06`. Its licensing application bytes are those of PR #13 head `53efb369`, tested by [Foundation run 37507742206](https://github.com/SeanVasey/VA-Studio/actions/runs/37507742206), attempt 1. This correction changes test isolation and observation, not application transactions or saved license terms.

## Observed failure and diagnosis

SQLite shard 2, job `112420686381`, reported 2,118 cases: 1,881 passed, one failed, 236 reviewed native-only skips, zero errors and 20,109 assertions. `LicensingAdminTest::test_operator_review_actions_bind_the_actual_submission_and_preserve_a_successor_history` failed at the original comparison-modal assertion on line 65: the modal contained an empty diff rather than the changed synthetic source. The failed test step correctly prevented a terminal receipt; independent verification rejects this artifact as positive acceptance. MySQL outcomes must be observed separately.

The unchanged local case reproduces that failure: one case, 32 assertions. A separate independent diagnostic measures the original `RefreshDatabase` fixture's transaction level as one. `ReviewedLicenseDraft::review` refuses caller-owned transactions before capturing a draft. The UI catches that refusal, closes the attempted editor and tells the operator to reopen. Filament's `callTableAction` test helper returns early when mounting fails; `assertHasNoTableActionErrors` alone therefore does not prove a save. The persisted source and draft-update audit count remain unchanged.

Changing only the diagnostic's isolation to the existing `FinalizationDatabaseMigrations` measures transaction level zero, captures the editor, saves the new source and one audit, and makes the original comparison assertion pass. The same supplied successor model remains stale in both probes; the successful comparison resolves the saved record. This excludes a table-cache refresh correction as the cause addressed here. Diagnostic copies and observations are retained outside the source tree in `licensing-mount-diagnosis/`; initial encryption-key setup errors are retained separately.

## Correction and executed checks

`LicensingAdminTest` now uses the existing isolated migration/wipe lifecycle already used by the license authoring and authority suites. The existing journey explicitly verifies its record-specific mounted editor and captured version, then requires a saved notification and the changed persisted source before opening the original comparison modal. Every original assertion, including immutable predecessor history, remains. The production standalone transaction guard remains unchanged.

With the recovered PHP 8.4.26 runtime, a disposable 32-byte test encryption key and SQLite `:memory:`:

- All six licensing administration cases passed with 97 assertions, zero failures/errors/skips (`licensing-admin-final.xml` and log).
- The administration, authoring-action, reviewed-draft and writer-authority selection passed 93 cases / 726 assertions, zero failures/errors/skips (`licensing-admin-compatibility.xml` and log).
- Scoped Pint, PHP syntax and `git diff --check` passed.

Two intermediate added mounted-action assertions used an obsolete table-helper context and failed before editing. Their output remains retained; the final record-specific assertion uses the locked framework's supported `TestAction` contract. No original criterion was removed to obtain the pass.

No test identity, native exclusion, partition, workflow, timing, timeout, retry or dependency changes. This is local SQLite feedback; fresh inclusive hosted MySQL/SQLite and native browser acceptance remain required for the final composed candidate.
