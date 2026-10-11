# Domain-level staff MFA for promotion and rights-scope writes (#68 review condition C2)

Lane `mfa`, branch `harness/domain-staff-mfa`, based on main `f57e7256891d0d3c117e151a36f7fb967c724ab7` (after PR #83).
Commits: `3399657` (plan, first), `db5add2` (product and tests), `de7202a` (PHPDoc on the three `ManageRightsScope`
writes; comments only), `f0cf472` (fix: the rule is evaluated on the locked actor row, no re-read; found by the
native MySQL run, see below), `f589a2f` (test only: the attestation-context assertion no longer depends on JSON key
order, found by the second native MySQL run). Read `PLAN.md` and `THREAT-NOTE.md` with this record.
Review conditions (see the last section): `52d0392`, `bdd7920`, `fb4dce8` and the docs commit that adds this text.

**Scope of the result.** #68 C2 is closed for the two services named here, `PromotionAdministration` and
`ManageRightsScope`: in staging an unenrolled administrator is refused on every path (panel, any route, a direct
service call), except through an explicit console attestation that is written to the audit log. The wider class of
problem is only narrowed. `app/Domain/Catalog/PrepareExclusiveOffer.php` still links a rights scope with only the
gate and environment checks (no caller in `app/` or `routes/` today), and the panel-only catalog writers still rely on
the panel's page middleware. Both remain open.

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

## Existing tests and documents that must change (applied in `52d0392` at the review's request)

`evidence/integrator-existing-tests.patch` holds the two one-line test changes, verified above:

- `tests/Feature/RightsScopeCommandTest.php`: the staging variant of
  `linkTurnsAnUnlinkedOrderFailureIntoAPreparedOrder` passes `--attest` for its two writes.
- `tests/Feature/StagingEnvironmentAdmissionTest.php`: the `test promotion administration` gate enrolls its admin
  with the same synthetic secret `QuoteFixtures::selection()` uses, so the gate proves only the environment dimension.

The brief's owned list excluded existing tests, so the lane first left both files untouched; the independent review
made applying the patch condition 1, and `52d0392` applies it unchanged (`git apply`). `docs/ops/staging-test-purchase.md` (step 5) shows the register
and link commands for a staging host without `--attest`; after this change those two lines need the flag, and the
runbook should say that the attestation is recorded in the audit log; `52d0392` does both. `CHANGELOG.md` has its
Unreleased Security entry since the docs commit.

## Not tested, not claimed

- MySQL 8.4 (CI's version): the native runs here are 8.0.46 and are labelled as such; CI remains the 8.4 evidence.
- A real staging host, a real operator console session, browser specs, Stripe.
- Focused checks only; no full-suite, Foundation or release acceptance is claimed. Nothing here authorizes production
  deployment, live payments or any release-boundary change.

## Independent review of `d806d88` and its conditions

The review (`independent-review-d806d88-DECISION.md`, copied verbatim) returned **APPROVE WITH CONDITIONS
(development merge only)**. Evidence for every condition: `evidence/review-conditions-red-green.txt`, all at
`fb4dce8` on SQLite (PHP 8.4.26, PHPUnit 12.5.34, `public/build` absent, direct runner with the worktree autoload).

| Condition | Change | Evidence |
| --- | --- | --- |
| 1. Integrator patch, runbook `--attest`, CHANGELOG | `52d0392` applies `evidence/integrator-existing-tests.patch` and adds `--attest` with a note on the `staff.console_attestation` record to step 5 of `docs/ops/staging-test-purchase.md`; the docs commit adds the Unreleased Security entry | `RightsScopeCommandTest` and `StagingEnvironmentAdmissionTest` pass inside the existing-suites run below |
| 2. Action binding isolated; stale enrolled `User` objects | `bdd7920`: the "another action" case attests `register` on `synthetic-existing` and then blocks that same scope, so only the action binding can refuse it. New cases in both services hold the enrolled object after `saveAppAuthenticationSecret(null)` on the persisted row | M9 (action binding removed) and M14 (`require($actor)` in `PromotionAdministration`) are **killed**; the analogous M14b in `ManageRightsScope::authorize()` is killed too. Controls: M9 and M14 still **survive** against the `d806d88` test files, as the reviewer found |
| 3. THREAT-NOTE tinker row; C2 scope in this README | Tinker now has its own row: not refused, because anyone with tinker can call `attest()` or set an MFA secret, so shell access is the real control. The scope paragraph above states that C2 is closed for the two services, with `PrepareExclusiveOffer` and the panel-only writers open | Documentation only |
| 4. Recommended hardening | `fb4dce8`: `attest()` throws `StaffWriteAuthority::CONSOLE_ONLY` unless `app()->runningInConsole()`; `recorded()` requires the audit row's `action`, `scope_key` and `offer_revision_id` to match the value being admitted | **Red** before (fb4dce8 tests, bdd7920 class): 10 tests, 2 failures, rc 1, both new cases. **Green** after. Mutants M15 (guard removed), M16 (context unchecked), M17 (action unchecked) and M18 (revision unchecked) are killed |

| Run at `fb4dce8` | Result |
| --- | --- |
| The three lane suites | OK, **22 tests, 344 assertions**, rc 0 |
| Reviewer-listed existing suites: `RightsScopeCommandTest`, `RightsScopeCommandUnknownOutcomeTest`, `RightsScopeWriterAuthorityTest`, `SharedInventoryTest`, `PromotionAdministrationTest`, `PromotionAdminHttpTest`, `PromotionAdministrationSettlementTest`, `StagingEnvironmentAdmissionTest`, `StagingOperatorMfaTest`, `OperatorMfaTest`, `PromotionPricingTest`, `PromotionPricingHttpTest`, `ExclusiveWriterAuthorityTest` | OK, **158 tests, 1,327 assertions**, rc 0. The two C2-encoding failures are gone |
| MySQL-only `PromotionAdministrationMySqlConcurrencyTest`, `RightsScopeWriterConcurrencyTest` on SQLite | 17 tests, **17 skipped** (MySQL only; no MySQL server was started for this round) |
| Mutants M9, M14, M14b, M15, M16, M17, M18 | All **killed**; each file restored, sha256 match and clean `git diff` |
| Pint `--test` on the five changed PHP files; `git diff --check d806d88` | passed; clean |
| `phpunit-shards.py --shards=8 ... --mysql-native-selection` | rc 0: selection unchanged at 1,765 cases in 163 files; discovered total now 7,664 in 560 files, so the comment in `.github/workflows/final-verification.yml` moves from 7642 to 7664. `.gitlab-ci.yml` cites only the selection. Census files are unchanged because no new method skips on either driver |

Not claimed for this round: native MySQL of `fb4dce8` (the `recorded()` JSON comparison has not run on MySQL), a real
HTTP request against the console guard (the test flips the application's console flag), a job that records an
attestation (queue workers count as console), and anything beyond focused checks.

## Delta review of the review conditions

The independent delta review of `d806d880..57a214d` returned APPROVE (`independent-review-57a214d-DECISION.md`). All
four conditions of the `d806d88` review are met. It re-ran the three lane suites (22 tests) and the rights-scope
command and staging-admission suites (25 tests) on SQLite, and re-killed mutants M9, M14 to M18 and a new M19 (scope
key not checked in `recorded()`). Not run: native MySQL for the new `recorded()` path (left for the final integrated
verification) and the other eleven existing suites. Two Codex P2s on the reviewed head are deferred to the lane's
follow-up batch under the owner's freeze-and-batch rule: bind or consume the console attestation, and reword the
"No changes were made" output after an attestation. The review judged neither blocking for a development merge.

## Follow-up batch (2026-10-11)

Branch `harness/followup-mfa-20261011` from main `2f1cacc`, product and tests in `772fff1`. It fixes the two Codex P2s
deferred above (`docs/handoff/2026-10-07/codex-deferred-20261011.md`, #92). Evidence:
`evidence/followup-20261011-red-green.txt` (SQLite only; no MySQL server was started).

- **r4240117094, bind and spend the attestation.** Both remedies, because neither alone is complete without a schema
  change. `attest()` now records a keyed hash (HMAC-SHA256 under the application key; the reference itself never
  appears) of the evidence reference and, for `block`, the requested blocked state and expected control version
  (context `schema_version` 2). `binds()` checks them against the write and `recorded()` checks them against the
  audit row. `requireFor()` then spends the attestation: inside the write's transaction it refuses with `REFUSAL`
  when a `staff.console_attestation_consumed` audit row already names it (subject: the attestation's own row) and
  otherwise appends one. Audit rows stay append-only (`AuditEvent` forbids update and delete), so the marker is a
  new row, not a change to the attestation, and there is no new table or migration. Every use of one attestation
  first locks the same operator row FOR UPDATE in `ManageRightsScope::authorize()`, which serializes the
  already-used check against a concurrent use. A write refused after admission (for example `SELECTION_CHANGED`)
  rolls the marker back with it, so the attestation may then retry only the identical operation it binds.
  `register`, `link` and `block` all go through this path. `PromotionAdministration` accepts no attestation (it
  calls `require()` only), so it is unchanged.
- **r4240117095, refusal wording.** Once `--attest` has recorded an attestation, `refuse()` ends with "No
  rights-scope write occurred; the attestation audit record (audit_event=N) remains." instead of "No changes were
  made."; without an attestation the original wording is kept. Runbook step 5 says so.

| Run | Source | Result |
| --- | --- | --- |
| Red: new and changed cases against the product files of `2f1cacc` | tests of `772fff1`, product `2f1cacc` | 20 tests, 296 assertions, **6 failures**, rc 1: register replay admitted; retry with another reference admitted; another blocked state admitted; tampered `reference_hmac` admitted; attestation context lacks the new fields; command still says "No changes were made" after an attestation |
| Green: the three lane suites | `772fff1` | OK, **26 tests, 419 assertions**, rc 0 |
| The thirteen existing suites of the previous round | same tree | OK, **158 tests, 1,327 assertions**, rc 0 |
| The six fixture-caller suites | same tree | OK, **160 tests, 2,432 assertions**, rc 0 |
| MySQL-only suites on SQLite | same tree | 17 tests, **17 skipped** |
| Mutants M20 to M31 | `772fff1` | M20 (already-used check removed), M21 (consume not called), M22 to M24 (reference, state, version unbound), M25 to M27 (`recorded()` ignores reference, state, version), M28 (`block` does not pass state and version), M29 and M30 (command wording fixed either way) are **killed**. M31 (the `transactionLevel() === 0` guard removed) **survives**: every test runs inside RefreshDatabase's transaction and the only caller always opens one, so the guard is defence in depth that no test reaches |
| Pint `--test` on the five changed PHP files; `git diff --check` | `772fff1` | passed; clean |
| `phpunit-shards.py --shards=8 ... --mysql-native-selection` | `772fff1` | rc 0: selection unchanged at 1,820 cases in 164 files; discovered total 7,791 to 7,795, so the comment in `.github/workflows/final-verification.yml` moves to 7795 |

Not tested, not claimed: native MySQL of the new path (the already-used check is a plain read under the operator's
row lock; see the THREAT-NOTE residual on an older REPEATABLE READ snapshot), MySQL's JSON round trip of the new
`blocked` and `expected_version` fields in `recorded()`, a real staging console, and anything beyond focused checks.
