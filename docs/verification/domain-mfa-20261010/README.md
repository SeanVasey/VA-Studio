# Domain-level staff MFA for promotion and rights-scope writes (#68 review condition C2)

Lane `mfa`, branch `harness/domain-staff-mfa`, based on main `f57e7256891d0d3c117e151a36f7fb967c724ab7` (after PR #83).
Commits: `3399657` (plan, first), `db5add2` (product and tests), `de7202a` (PHPDoc on the three `ManageRightsScope`
writes; comments only), `f0cf472` (fix: the rule is evaluated on the locked actor row, no re-read; found by the
native MySQL run, see below), `f589a2f` (test only: the attestation-context assertion no longer depends on JSON key
order, found by the second native MySQL run). Read `PLAN.md` and `THREAT-NOTE.md` with this record.

Environment: PHP 8.4.26, PHPUnit 12.5.34, Pint 1.32.1, SQLite in memory, `public/build` absent, PHPUnit run directly
through the `_composer_autoload_path` invocation printed at the top of every evidence file (never `php artisan test`).
Native MySQL in this container is **8.0.46**, not CI's 8.4.11; see the MySQL rows below. The lane ran across four
sessions after three usage-limit cuts; each later session re-read the earlier commits and re-ran every cited check at
the final source. During the first two sessions the container was shared with other lanes' MySQL suites (load average
8 to 13 on four cores), which made each MySQL case slow and once killed a run from outside this lane; the third
session ran after a container reboot with no other lane active (load average 1.2 to 1.8) and produced the green
writer-concurrency row; its second MySQL run was cut off by the third usage limit and the fourth session re-ran that
selection from a fresh database. Every retained result is a complete PHPUnit run with its exit code.

## What changed

- **`app/Domain/Staff/StaffWriteAuthority.php` (new).** `require()` applies the admin panel's staff multi-factor
  rule, the same check `EnsureMultiFactorAuthenticationIsEnabled` makes on every panel request (rule required by the
  admin panel, which `TestEnvironment::requiresStaffMfa()` drives: staging and production; an enabled provider on the
  staff row), to the row the calling service has already read under its own lock, and reads nothing further.
  `attest()` records a console operator's explicit attestation as an append-only `audit_events` row
  (`staff.console_attestation`: operator, time, command, action, scope key, offer revision, environment, whether the
  rule was required and the operator enrolled) and returns an immutable value; `requireFor()` admits a write either
  by the panel rule or by an attestation that is recorded, current (900 s) and bound to the same operator, action,
  scope key and revision, and never outside the test-commerce environments. `attestationRequired()` answers the
  panel's rule for the console. Local and testing keep today's behaviour through the panel's optional rule; there is
  no new environment variable.
- **`PromotionAdministration::actor()`** calls `StaffWriteAuthority::require()` after the gate, on the row it read
  under its existing shared lock (an exclusive lock would let two panel requests of one operator deadlock). Every
  operation, reads included, goes through `actor()`.
- **`ManageRightsScope::authorize()`** calls `requireFor()` after the gate and the environment admission, on the row
  it read under its existing exclusive user lock. `register`, `link` and `block` take an optional trailing
  attestation (documented in `de7202a`); existing callers are unchanged. With an attestation, the scope key is read
  by id after the actor lock (keys are immutable identities; nothing is read without one), and the write's own audit
  event carries `attestation_audit_id`.
- **`vasey:rights-scope`** gains `--attest`. After the hidden password check (which now also requires
  `administer-catalog`, so a non-staff password holder leaves no attestation) and the environment admission (so
  production records nothing), a write where the panel requires the factor is refused without the flag and names the
  reason; with it, the attestation is recorded and printed (`attestation=recorded audit_event=N`) before the write.
  `--attest` is invalid for `list`. Local and testing keep today's behaviour without the flag and record an explicit
  attestation when it is given.

Why not a per-session "verified" flag: Filament 5 challenges the TOTP code inside its login page (a Livewire property
on `Auth\Pages\Login`, then `session()->regenerate()`) and, where the rule is required, `EnsureMultiFactorAuthenticationIsEnabled`
checks `hasEnabledProviders($user)` on every page request; nothing further is written to the session (the only session
writes under `Auth/MultiFactor` are the email provider's code cache). Enrollment under the required rule is the state
a verified panel session leaves behind; `AdminMultiFactor::satisfiedBy` is the repository's existing domain-level
reading of it (about forty domain services call it, including `TestPaymentExceptionOperations`, `FreeGrantStaff` and
`CatalogDraftImporter`). A new session artefact would be the parallel implementation the brief forbids.

Deviation from `PLAN.md`: the plan (and `db5add2`) had `require()` delegate to `AdminMultiFactor::satisfiedBy`, which
re-reads the user row, FOR UPDATE when the caller holds that lock. On native MySQL that re-read is a third `users`
lock inside the rights-scope writer, and `RightsScopeWriterConcurrencyTest` asserts the writer's exact sequence
(`users`, `users`, `tracks`, `offers`, `rights_scopes`): all four orderings failed. `f0cf472` evaluates the same panel
rule on the row the service has already locked and makes no further read, which is also the stronger reading (a
non-locking re-read could return an older REPEATABLE READ snapshot in which a withdrawn enrollment still appears).

## Results

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Baseline: the ten existing promotion, rights-scope, command, staging-admission and operator-MFA suites (first session) | `f57e725` untouched | OK, **124 tests, 1,028 assertions**, rc 0 | `evidence/baseline-existing-suites-f57e725.txt` |
| Red, first draft of the three new suites before any product edit (first session) | `3399657` (product = `f57e725`) | 18 tests: 11 errors (`StaffWriteAuthority` absent on every MFA case), 5 failures (command exit 0 without an attestation; four draft-harness artefacts, see the file header); rc 2 | `evidence/red-new-suites-before-change.txt` |
| Red, final suites against the base product files | `de7202a` with `PromotionAdministration.php`, `ManageRightsScope.php` and `ManageRightsScopes.php` restored from `f57e725` (`StaffWriteAuthority.php` present but unwired). Run at `de7202a`; `f0cf472` changes only `require()`/`requireFor()` internals and two comments in the services, none of which the base-restored services reach, so the run was not repeated | 18 tests, 130 assertions, **6 errors, 4 failures**, rc 2. Every case that exercises the new rule fails: staging refusal of an unenrolled admin (both services), attestation binding and linkage, the console refusal and every `--attest` case. The 8 passing cases are the invariance cases that must hold on both sides (production refusal, non-staff refusal, enrolled admin admitted in staging, local and testing unchanged) | `evidence/red-final-suites-on-base-product.txt` |
| Green: the three new suites | `f0cf472` | OK, **18 tests, 307 assertions**, rc 0 | `evidence/green-new-suites.txt` |
| The ten existing suites after the change | `f0cf472` | 124 tests, 1,014 assertions, **2 failures**, rc 1: `RightsScopeCommandTest::test_staging_admits_the_same_link_and_order_preparation` (runs the command under staging without `--attest`) and `StagingEnvironmentAdmissionTest::test_every_test_commerce_and_test_capability_gate_admits_local_testing_and_staging[staging]` (the `test promotion administration` gate uses an unenrolled admin). Both encode the C2 behaviour this change removes | `evidence/existing-suites-after-change.txt` |
| Scratch copies of those two suites with the one-line changes in `evidence/integrator-existing-tests.patch` | `f0cf472` + scratch copies (tracked files untouched) | OK, **25 tests, 436 assertions**, rc 0 | `evidence/integrator-scratch-copies.txt` |
| Pint on the changed files | `f0cf472` | passed for the new class, `ManageRightsScope.php`, the command and the three suites. `PromotionAdministration.php` fails with the same five pre-existing fixers at `f57e725` and after the change (this lane changed 9 of its lines; a reformat would touch 44 lines in 9 hunks). Not reformatted: CLAUDE.md section 1.1 forbids style churn and the lint gate is an open adoption gap | `evidence/pint.txt` |
| **MySQL 8.0.46**, first combined attempt (terminated) | `de7202a` | Four failures printed (the four lock-sequence cases below), then PHPUnit was SIGTERMed from outside this lane (rc 143) before any detail; retained only as the first red signal | `evidence/mysql8046-combined-run-terminated.txt` |
| **MySQL 8.0.46**, red: the four lock-sequence cases of `RightsScopeWriterConcurrencyTest` | `de7202a` (before the fix) | **4 tests, 710 assertions, 4 failures**, rc 1: the writer's lock sequence is `users, users, users, tracks, offers` instead of `users, users, tracks, offers, rights_scopes` (assertion diff in the file) | `evidence/mysql8046-red-lock-sequence.txt` |
| **MySQL 8.0.46**, green: the whole `RightsScopeWriterConcurrencyTest` suite after the fix (third session) | `f0cf472` | OK, **10 tests, 558 assertions**, rc 0, 19 minutes: the four lock-sequence cases that were red at `de7202a` pass with the writer's sequence `users, users, tracks, offers, rights_scopes` (and `users, users, rights_scopes` for block) restored, and the six old-snapshot authority denial cases are unchanged by the new check | `evidence/mysql8046-green-writer-concurrency.txt` |
| Re-check of the three new suites on SQLite at the branch head (third session) | `f0cf472` | OK, **18 tests, 307 assertions**, rc 0 | `evidence/green-new-suites-head-recheck.txt` |
| Re-run at the branch head (fourth session): the three new suites and the ten existing suites together | `f0cf472` | **142 tests, 1,321 assertions, 2 failures**, rc 1: the same two C2-encoding failures as above, nothing else | `evidence/sqlite-head-new-and-existing-suites.txt` |
| Suites that reach both services indirectly through shared fixtures or the staging profile journey (fourth session) | `f0cf472` | OK, **160 tests, 2,432 assertions**, rc 0 (local/testing unchanged) | `evidence/sqlite-head-fixture-callers.txt` |
| **MySQL 8.0.46**: `PromotionAdministrationMySqlConcurrencyTest`, `RightsScopeWriterAuthorityTest`, `RightsScopeCommandTest` and the three new suites (fourth session, fresh database) | `f0cf472` | **56 tests, 1,327 assertions, 2 failures**, rc 1, 57 minutes: (1) the expected `RightsScopeCommandTest` staging variant without `--attest`; (2) a MySQL-only test defect in `RightsScopeStaffMfaTest`, which compared the attestation's JSON context with key order significant (MySQL's JSON type reorders keys; every value matched). The promotion lock-ordering suite and the writer-authority suite pass with the new check | `evidence/mysql8046-authority-command-and-new-suites.txt` |
| **MySQL 8.0.46**: `RightsScopeStaffMfaTest` after the test-only fix in `f589a2f` (key-sorted strict comparison) | `f589a2f` | OK, **7 tests, 95 assertions**, rc 0 | `evidence/mysql8046-attestation-context-after-test-fix.txt` |
| The three new suites on SQLite after that fix | `f589a2f` | OK, **18 tests, 307 assertions**, rc 0 | `evidence/sqlite-new-suites-after-test-fix.txt` |

### Adversarial cases covered by the new suites

- Staging, administrator without the panel factor: `create`, `setAvailability`, `campaigns`, `detail`, `register`,
  `link`, `block` refused with `StaffWriteAuthority::REFUSAL`; no row or audit change; transaction level restored.
- Staging, enrolled administrator: all of the above allowed; audits attributed; no `attestation_audit_id`.
- Production: both services refuse every administrator exactly as before (`AuthorizationException` with the default
  message; `INVENTORY_UNAVAILABLE` 503), enrolled or not, with or without `--attest`; nothing is recorded.
- Non-staff user, even when enrolled: refused in local, testing, staging and production.
- Console: refused in staging without `--attest` (no write, no record); with it, register and link succeed and the
  attestation rows name operator, time, action, scope, revision, environment and enrollment state, and the write
  audits link to them; repeats stay idempotent with one attestation per attempt; a domain refusal after the
  attestation (unregistered scope, `SELECTION_CHANGED`) keeps the attempt on record without a write; wrong password,
  non-staff, unknown actor, non-interactive and production leave no record; `--attest` with `list` is invalid input;
  references and passwords never appear in output or in the attestation context.
- Attestation binding: another scope key, operator, action, scope id or revision; a record rolled back; an
  attestation older than 900 s. Each refused with no scope, link or control change.
- Local and testing: unchanged without an attestation; an explicit attestation is recorded and linked.

The check runs inside the two domain services (`actor()` and `authorize()`), so it is exercised by direct service
calls in `PromotionAdministrationStaffMfaTest` and `RightsScopeStaffMfaTest`, not only through the command or routes.
On native MySQL the existing `RightsScopeWriterConcurrencyTest` additionally proves that the rights-scope writer's
lock sequence and the old-snapshot authority denials are unchanged by the check.

## Existing tests and documents that must change (not owned by this lane)

`evidence/integrator-existing-tests.patch` holds the two one-line test changes, verified above:

- `tests/Feature/RightsScopeCommandTest.php`: the staging variant of
  `linkTurnsAnUnlinkedOrderFailureIntoAPreparedOrder` passes `--attest` for its two writes.
- `tests/Feature/StagingEnvironmentAdmissionTest.php`: the `test promotion administration` gate enrolls its admin
  with the same synthetic secret `QuoteFixtures::selection()` uses, so the gate proves only the environment dimension.

Neither file is edited on this branch (the brief's owned list excludes existing tests). Until the patch is applied,
the affected selection carries these two failures. `docs/ops/staging-test-purchase.md` (step 5) shows the register
and link commands for a staging host without `--attest`; after this change those two lines need the flag, and the
runbook should say that the attestation is recorded in the audit log. `CHANGELOG.md` receives the entry returned to
the integrator.

## Not tested, not claimed

- MySQL 8.4 (CI's version): the native runs here are 8.0.46 and are labelled as such; CI remains the 8.4 evidence.
- A real staging host, a real operator console session, browser specs, Stripe.
- Focused checks only; no full-suite, Foundation or release acceptance is claimed. Nothing here authorizes production
  deployment, live payments or any release-boundary change.
