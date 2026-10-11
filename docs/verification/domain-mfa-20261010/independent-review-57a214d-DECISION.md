DECISION: APPROVE

# Independent delta review: lane mfa (#68 C2), PR #92, branch harness/domain-staff-mfa

The approval covers a development merge only. It is not Foundation, full-suite or release acceptance.

## Reviewed range
- `d806d880..57a214db43b0d08311b816eadf214b74be542fcd`: 52d0392, bdd7920, fb4dce8, 57a214d.
- Worktree HEAD `8423f7001e8eaac1a72f788c186858e70609983c` = 57a214d plus a merge of main 9856fa8.
- Merge check: `git diff 57a214d 8423f70 -- app tests` is byte-identical to `git diff f57e725 9856fa8 -- app tests`, which is main's own change since the merge base. The merge's only edit outside main is the workflow comment's case count (7783). The merge commit message explains that edit.

## Commands and results (SQLite in memory, PHP 8.4.26, PHPUnit 12.5.34, public/build absent, direct runner with the worktree autoload, `--colors=never --do-not-cache-result`)
- Lane suites `PromotionAdministrationStaffMfaTest`, `RightsScopeStaffMfaTest`, `RightsScopeCommandAttestationTest`: **OK (22 tests, 344 assertions)**, rc 0.
- `RightsScopeCommandTest` plus `StagingEnvironmentAdmissionTest`: **OK (25 tests, 436 assertions)**, rc 0. Both C2-encoding failures from the previous review are gone.
- Mutants. Each was applied in place to one file, run, restored with `git checkout HEAD --`, then checked by sha256 and `git diff --quiet` (all MATCH and 0):
  - M9 (removed `$this->action === $action` in `binds()`): **killed**. "another action" admitted. 10 tests, 1 failure.
  - M14 (`PromotionAdministration:187` `require($actor)`): **killed**. Stale-object test. 6 tests, 1 failure.
  - M16 (`recorded()` returns true after loading the row): **killed** at the "scope" case.
  - Also: M17 (action unchecked), M18 (revision unchecked) and M15 (console guard removed) were **killed**. A new M19 (scope key unchecked in `recorded()` only) was also **killed**.
  - M14b was not re-run. That result comes from the implementer's evidence.
- Not run: MySQL (none started, as instructed). Also not run: the other 11 existing suites the README lists, browser specs, Pint.
- End state: `git status` clean, HEAD 8423f70.

## Conditions from the d806d88 review
1. **Met.**
   - The test lines added in 52d0392 are identical to `evidence/integrator-existing-tests.patch`.
   - Runbook step 5 now uses `--attest` on `register` and `link`. This matches the command signature (`{--attest : ...}` in `ManageRightsScopes`). `list` with `--attest` is rejected as invalid input, as the runbook says.
   - The CHANGELOG entry sits under `[Unreleased]` / Security, with an upgrade note.
2. **Met.**
   - The "another action" case now attests `register` on `synthetic-existing` (revision null) and blocks that same scope. Only the action binding differs, and M9 is killed.
   - Both services now have stale-enrolled-object tests. Each asserts that the in-memory object is still enrolled and the persisted row is withdrawn. M14 is killed.
3. **Met.**
   - THREAT-NOTE has its own tinker row: not refused, and shell access is the control. The phishing row no longer lists tinker.
   - The README scope paragraph and the THREAT-NOTE limits say C2 is closed for `PromotionAdministration` and `ManageRightsScope` only. `PrepareExclusiveOffer` and the panel-only writers stay open.
4. **Implemented** (it was a recommendation). See below.

## fb4dce8 hardening
- **Console guard.**
  - `app()->runningInConsole()` uses `APP_RUNNING_IN_CONSOLE`, or else `PHP_SAPI` cli/phpdbg. Under php-fpm, mod_php or `artisan serve` (cli-server SAPI) it is false, so HTTP callers get `CONSOLE_ONLY`.
  - The guard runs before any read or write, so it adds no new bypass. It only narrows `attest()`.
  - Octane, FrankenPHP and RoadRunner are not in composer.json. A CLI-SAPI HTTP worker would need re-checking if one is adopted (INFO).
  - Queue workers and the scheduler still pass the guard. The THREAT-NOTE and the docblock say so honestly, and no job calls `attest()` today.
  - The test flips the application's cached flag through a closure bound to `Application`. That is acceptable and is disclosed as "not a real HTTP request".
- **`recorded()` binding.**
  - Values are compared through the `array` cast and keyed access, so JSON key order does not matter (MySQL `JSON` normalizes key order).
  - Revision IDs are written as PHP int and decode as JSON integers, so `===` int against int holds on SQLite and MySQL.
  - A null revision is stored as JSON null, and `?? null` maps it to null, so null against null holds.
  - A missing or non-string `action` or `scope_key` fails closed. The comparison uses `hash_equals` on the scope key.
  - The value-object `binds()` check already runs first, so this is defence in depth against an altered row.
  - Not yet run on native MySQL. The README states this, and I judge the risk low.
- **No regressions** in the suites run. The attestation count in the bound-elsewhere test changed from 3 to 4, consistent with the extra committed attestation.

## Findings
- **INFO** Native MySQL has not run for fb4dce8. That run belongs in the planned final integrated verification and is not a merge blocker.
- **INFO** README and CHANGELOG claims match what I reproduced: 22/344 on the lane suites, the mutant kills, and the command and runbook behaviour. One example: without `--attest` in staging the command refuses before `attest()` is called, so nothing is recorded, as the runbook says. The README's 158-test existing-suite figure was not re-run here beyond the two suites above.
- **INFO** The merge's workflow-comment count (7783) was not re-derived here. The merge message records sharder rc 0.

## Deferred Codex P2s (my assessment: neither blocks a development merge)
- **The attestation is not consumed and not bound to blocked state, version or reference.** The value object lives only in the process that recorded it, for up to 900 s. Reusing it means running code on the host (tinker or a crafted process), and the threat note already treats host access as the controlling credential. Each reuse still has to match the operator, action, scope key and revision, and has to pass the domain's own idempotency and conflict rules. It is worth closing before any production use of console attestations, but production refuses attestations outright today.
- **"No changes were made" is printed after an attestation was recorded.** `refuse()` appends that text even when an `attestation=recorded audit_event=N` line was printed just before. Domain rows are unchanged, which is the operator's main concern, and the runbook says the attestation stays on record after a refused write. The message is cosmetic and slightly misleading. Recommend wording such as "No catalog changes were made; the attestation remains on record." in a follow-up.

## Conditions
None blocking. Follow-ups, not required for this merge:
- Run native MySQL for the `recorded()` JSON path in the final integrated verification.
- Track the two Codex P2s.
- Re-check `runningInConsole()` if a CLI-SAPI HTTP server is ever adopted.
