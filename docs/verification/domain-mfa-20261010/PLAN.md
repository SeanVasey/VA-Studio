# Domain-level staff MFA for promotion and rights-scope writes (#68 review condition C2)

Lane `mfa`, branch `harness/domain-staff-mfa`, based on `f57e7256891d0d3c117e151a36f7fb967c724ab7` (main after PR #83).
Written before any product edit, per the AGENTS.md work protocol.

## Condition being closed

`docs/verification/staging-env-admission-20261008/independent-review/DECISION.md`, C2 (Low, follow-up): when
`PromotionAdministration` (`create`, `setAvailability`) and `ManageRightsScope` (`register`, `link`, `block`,
reached by `vasey:rights-scope`) are invoked below the Filament panel in staging, they complete for an administrator
who has not enrolled in the panel's TOTP factor. Both services rely on the panel's page middleware for MFA, while
`TestPaymentExceptionOperations`, `FreeGrantStaff` and `CatalogDraftImporter` already call `AdminMultiFactor`
at the domain level. AGENTS.md: staff permissions are enforced on the server; production administration needs MFA
and audited role changes; a hidden navigation item is not authorization.

## Files owned

- `app/Domain/Staff/StaffWriteAuthority.php` (new): the server-side staff-authority check and the recorded console
  attestation.
- `app/Domain/Commerce/PromotionAdministration.php`: `actor()` calls the check.
- `app/Domain/Commerce/Inventory/ManageRightsScope.php`: `authorize()` calls the check; `register`, `link` and
  `block` accept an optional console attestation as a trailing parameter (existing callers unchanged).
- `app/Console/Commands/ManageRightsScopes.php`: `--attest` flag, attestation recording, refusal text.
- New tests: `tests/Feature/PromotionAdministrationStaffMfaTest.php`, `tests/Feature/RightsScopeStaffMfaTest.php`,
  `tests/Feature/RightsScopeCommandAttestationTest.php`.
- `docs/verification/domain-mfa-20261010/**`.

Not edited: CHANGELOG.md (entry returned to the integrator), README.md, CLAUDE.md, AGENTS.md, `resources/contracts/`,
`.env*`, `vendor/`, lockfiles, CI files, every existing test. Where an existing test encodes the C2 behaviour, the
needed one-line change is recorded for the integrator (see "Existing tests that encode C2" below) and is not applied.

## Design

1. **What the panel records.** Filament 5 challenges the TOTP code inside the login page (`Auth\Pages\Login`) and
   again when an unenrolled user is forced through `SetUpRequiredMultiFactorAuthentication`. It stores no per-request
   or per-session "MFA verified" flag; the state an authenticated panel session leaves behind is *enrollment under a
   required rule* (`Panel::isMultiFactorAuthenticationRequired()`, which this app derives from
   `TestEnvironment::requiresStaffMfa()`: staging and production). `App\Support\Access\AdminMultiFactor::satisfiedBy`
   is the repository's existing domain-level reuse of exactly that rule. The new check reuses it; it does not add a
   parallel TOTP implementation or session flag.
2. **`StaffWriteAuthority::require(User $current, bool $lockForUpdate)`** throws `AuthorizationException` unless the
   current, persisted staff user satisfies `AdminMultiFactor`. In local and testing the panel rule is optional, so
   today's behaviour is kept through the existing policy pattern and no new environment variable.
3. **Console attestation.** A trusted interactive console has no TOTP challenge. `vasey:rights-scope` writes in an
   environment that requires staff MFA proceed only with an explicit `--attest` flag. After the hidden password check
   and the environment admission the command calls `StaffWriteAuthority::attest(...)`, which immediately records an
   append-only `audit_events` row (`staff.console_attestation`: actor id, created time, command, action, scope key,
   offer revision, environment, whether the operator is enrolled) and returns an immutable value that carries the
   audit id. The domain service verifies, inside its write transaction, that the attestation is bound to the same
   operator, action, scope key and revision and that its audit row exists, then links the write's own audit event to
   it. Without `--attest` the command refuses before any write and names the reason. There is no unrecorded console
   path and no environment variable.
4. **Production** is unchanged: both services refuse before the MFA check (`TestEnvironment::admitsTestCommerce()`),
   so nothing previously refused becomes allowed.

## Assumptions

- "A verified MFA step in the current session" maps, in this codebase, to enrollment under the panel's required rule
  (point 1). Inventing a session flag would be the parallel implementation the brief forbids.
- The `--attest` flag is the explicit attestation; its content is composed from authenticated facts (actor id, scope,
  action, revision, time) so no free text reaches the audit log.
- `QuoteFixtures::selection()` enrolls its admin (`saveAppAuthenticationSecret`), so staging tests that need an
  enrolled staff user can use it; `LicenseFixtures::admin()` is unenrolled.

## Acceptance criteria

- Staging: an unenrolled admin is refused on `PromotionAdministration::create`/`setAvailability` and on
  `ManageRightsScope::register`/`link`/`block` with no row or audit change; an enrolled admin is allowed.
- Production: both services still refuse (unenrolled and enrolled), with the same exceptions as before.
- A non-staff user is refused in every environment.
- `vasey:rights-scope` in staging: refused without `--attest` (no write, no attestation row); with it, the write
  succeeds and the attestation row records who, when and which scope; an attestation bound to another operator,
  scope, action or revision, or whose record is gone, is refused by the domain service.
- Local and testing: unchanged for callers that pass no attestation.
- The check runs inside the domain services, not only in routes or the command.
- Red before green: the new adversarial tests fail on the untouched tree and their trimmed output is retained.
- Existing suites: promotions, rights scopes and the command, plus the staging admission and operator MFA suites,
  are run before and after; see the next section for the two cases that encode C2.

## Existing tests that encode C2 (not owned; recorded for the integrator)

- `tests/Feature/StagingEnvironmentAdmissionTest.php`, gate `test promotion administration`: invokes
  `PromotionAdministration::actor` with an unenrolled `LicenseFixtures::admin()` under staging and expects admission.
  Once C2 is closed it must use an enrolled admin to keep proving only the environment dimension.
- `tests/Feature/RightsScopeCommandTest.php::test_staging_admits_the_same_link_and_order_preparation`: runs the
  command under staging without an attestation and expects the writes to succeed. Once C2 is closed it must pass
  `--attest` for the staging variant.

Both expected failures will be shown in the evidence, together with a run of scratch copies carrying the one-line
changes, so the integrator can apply them with confidence. Neither file is edited on this branch.

## Tests to add

- `PromotionAdministrationStaffMfaTest`: environment x enrollment x actor matrix for `create` and `setAvailability`,
  reads gated by the same `actor()`, row/audit invariance on refusal.
- `RightsScopeStaffMfaTest`: the same matrix for `register`, `link` and `block`; attestation binding and record
  checks; audit linkage.
- `RightsScopeCommandAttestationTest`: `--attest` required in staging, optional and recorded in testing, invalid for
  `list`, never recorded before the password is confirmed, production refusal unchanged, output never prints references
  or passwords.

## What cannot be proven in this container

- MySQL races: this container has MySQL 8.0.46 only (CI uses 8.4.11). The MySQL-only suites touched by lock-order
  reasoning (`RightsScopeWriterConcurrencyTest`, `PromotionAdministrationMySqlConcurrencyTest`, the command suite on
  MySQL as #65 did) will be run against a private disposable 8.0.46 server and labelled as such; CI remains the
  8.4 evidence.
- Browser specs, a real staging host, a real operator console session, Stripe: not exercised.
- Focused checks only; no full-suite or Foundation acceptance is claimed.
