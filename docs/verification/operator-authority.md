# Persisted operator authority and MFA verification

T12a is a bounded authorization/MFA increment under the existing verified `is_admin` policy. It does not close T12's granular permissions, bulk/defaults or track-visibility requirements.

The `administer-catalog` gate now loads the current persisted User before deciding. Unsaved, deleted, revoked or unverified actors cannot keep authority through a retained or locally elevated model. The Filament panel delegates to that same gate. `AdminMultiFactor::satisfiedBy()` separately reloads persisted enrollment, so a retained secret cannot satisfy required enrollment after removal. Valid existing users keep the panel's optional/required enrollment semantics. Enrollment eligibility is distinct from the framework's actual login challenge.

Existing catalog, rights, offers, media queue/binding and inventory commands already invoke this gate; business rules and audit identities remain in their domain services. There is no new production role, default operator or commercial policy. Each evaluation reads current authority; it does not serialize role edits with a write that was already authorized.

## Regression evidence and commands

Against the original guards, nine new `OperatorAuthorityTest` cases failed on SQLite. Actual metadata create/edit and unpublish still succeeded after a different User instance revoked the persisted role or email verification. Local/unsaved/deleted actors and retained MFA enrollment also demonstrated the stale-object boundary. This is a reproduced domain guard defect; no HTTP exploit is claimed.

The corrected nine cases pass with 42 assertions. Five `OperatorMfaTest` cases pass with 95 assertions using the application's configured, locked Filament provider and actual Livewire login/profile forms:

- Production mode is selected before provider/route boot; required enrollment redirects the protected catalog and customer access remains denied.
- Profile enrollment requires the current password, persists the encrypted secret and hashed recovery codes, and excludes both from serialized User data.
- Password alone cannot authenticate an enrolled operator; accepted TOTP and consumed recovery codes cannot be replayed.
- Role withdrawal during a pending challenge denies login before consuming a valid code.

The production-mode test still uses PHPUnit's isolated configured database. Its migration override passes `--force` only because Laravel asks for production confirmation even on that test database. It uses no production host, account, source file or credential.

```sh
APP_DEBUG=false php vendor/bin/phpunit \
  tests/Feature/OperatorAuthorityTest.php tests/Feature/OperatorMfaTest.php \
  --fail-on-phpunit-warning --display-warnings

APP_DEBUG=false php vendor/bin/phpunit \
  tests/Feature/OperatorSetupTest.php tests/Feature/TrackMetadataTest.php \
  tests/Feature/FoundationTest.php tests/Feature/LicensingAdminTest.php \
  tests/Feature/SiteEditorialHttpTest.php tests/Feature/SiteScheduleRunnerTest.php \
  tests/Feature/TestCommerceOperationsTest.php tests/Feature/PromotionAdministrationTest.php \
  --fail-on-phpunit-warning --display-warnings
```

The eight related suites passed 92 tests and 1,398 assertions on local PHP 8.4.26/SQLite after the guard change. Final candidate CI and independent review belong in the integrating PR. Local tests do not establish MySQL concurrency, rendered MFA keyboard/mobile usability, physical authenticator/device recovery, outbound recovery mail, host configuration or actual production readiness. Those acceptance boundaries remain explicit.
