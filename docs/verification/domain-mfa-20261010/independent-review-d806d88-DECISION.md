# Independent review: lane mfa (#68 C2), f57e7256..d806d880

## Decision: APPROVE WITH CONDITIONS (development merge only)

## C2 status
C2 is **closed for the two services it names**. In staging, an administrator who has not enrolled is refused by `PromotionAdministration` (create, setAvailability, campaigns, detail) and `ManageRightsScope` (register, link, block). That holds on every path: panel, any route, or a direct service call. The only exception is an explicit console attestation, which is written to the audit log. The wider class of problem is only **narrowed**:
- `PrepareExclusiveOffer.php:85` still links a rights scope with only the gate and environment checks. Nothing in app/ or routes calls it today. Panel-only catalog writers are also still open.
- The check confirms enrollment, not a code entered for this request. For HTTP callers, enrollment implies a TOTP challenge at login, because Filament's login is the only login path. For console, tinker or queue callers, shell access is the real credential. The attestation records who did the write; it is not a second factor.

## Findings
- **LOW** `tests/Feature/RightsScopeStaffMfaTest.php:235-238`: the "another action" case attests a different scope key from the one it blocks, so the scope check refuses it, not the action check. Mutant M9 (action binding removed) survives. The README's claim that action binding is tested is unproven.
- **LOW** No test for a caller holding an old enrolled `User` object after enrollment is withdrawn. Mutant M14 (`require($actor)` at `PromotionAdministration.php:187`) survives. My scratch test passes on HEAD and kills M14, so the code is correct and only the test is missing.
- **LOW** `StaffWriteAuthority.php:63,110-118`: `attest()` works from any context. A future HTTP or job caller could create an attestation and skip the panel rule in staging. Recommend allowing it only from the console (`runningInConsole()`).
- **LOW** `StaffWriteAuthority.php:146-154`: `recorded()` checks that the row exists and the actor matches, not its scope, action or revision. The constructor is private, so this is hardening only.
- **LOW (docs)** The first threat row in THREAT-NOTE lists "artisan tinker" as refused. Anyone with tinker can call `attest()` or set a secret on a user.
- **INFO** The attestation is written before the write transaction and outside it, not in the same transaction. It is re-checked inside the write transaction (`ManageRightsScope.php:134-135`) and linked through `attestation_audit_id`. Refused attempts stay on record. Acceptable.
- **INFO** Re-reading under lock is correct: the `FOR UPDATE` row in rights scopes, the shared-lock row in promotions. `enrolled()` copies the loop from `AdminMultiFactor` instead of calling it, to keep the lock sequence; the two could drift. Mutant M4 is equivalent.
- Nothing that was refused before is now allowed. Local and testing behave as before, there is no new environment variable, and production refuses before any attestation is written.

## Conditions
1. Apply `evidence/integrator-existing-tests.patch` in the same merge. Add `--attest` to step 5 of `docs/ops/staging-test-purchase.md` and add the CHANGELOG entry.
2. Rewrite the "another action" case so it isolates the action check (attest `register` on `synthetic-existing`, then `block` that scope). Add old-object regression tests for both services. Show that M9 and M14 are killed.
3. Fix THREAT-NOTE (tinker) and the README so they say C2 is closed for the two named services, with `PrepareExclusiveOffer` and the panel-only writers left open.
4. Recommended, not blocking: allow `attest()` only from the console; bind `recorded()` to the attestation's context.

## Commands and results
- SQLite, the 3 new suites at HEAD: **OK 18/307**.
- SQLite, existing suites at HEAD: 10 suites pass (98 tests in total), and the 3 MySQL-only suites skip. **Two fail:** `RightsScopeCommandTest::test_staging_admits_the_same_link_and_order_preparation` (exit 1) and the `StagingEnvironmentAdmissionTest` gate in staging. These are exactly the two the implementer named.
- Scratch copy (copy-local Composer autoload) with the integrator patch: RightsScopeCommandTest **OK 10/223**, StagingEnvironmentAdmissionTest **OK 15/213**.
- 14 mutants on the new suites: 11 killed. M4 survives (equivalent); M9 and M14 survive (test gaps).
- Native **MySQL 8.0.46** on my own server (not CI's 8.4), the 3 new suites plus RightsScopeWriterAuthorityTest: **OK 39/370**. I did not re-run the lock-sequence suite; that result comes from the implementer's evidence.
- The evidence is labelled as focused checks. The only overclaims are the action-binding claim and the tinker row.
