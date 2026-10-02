# T15a backend verification boundary

The backend increment uses synthetic private inquiry fixtures only. Production intake remains disabled until the explicit setup in [the operator contract](../contact-inquiries.md) is supplied and its public notice rendered.

```sh
APP_DEBUG=false php vendor/bin/phpunit \
  tests/Feature/CustomerInquiryHttpTest.php \
  tests/Feature/CustomerInquiryMigrationTest.php \
  tests/Feature/CustomerInquiryAdminTest.php \
  --fail-on-phpunit-warning --display-warnings
```

The transport/migration tests exercise raw JSON/body streams, duplicate/escaped/nested fields, exact bounds including Unicode, actual token-based CSRF fallback and same-origin denial, independent minute/hour throttles, session/auth/login-logout loss, global-key uniqueness and owner-matched replay, publication/config/operator/MFA withdrawal, audit/insert/lock/logger faults, redaction under debug, encrypted exact snapshots, SQL immutable/state/version guards and rollback refusal. The parser scans bounded valid JSON string tokens rather than applying a recursive regex to long messages.

Admin tests use actual Filament pages/actions plus direct domain calls: private list/detail, escaped message display, persisted read/archive state and audit identity, guest/customer/unverified/stale staff and MFA denial, bounded pagination, stale/no-op transitions, audit rollback and absent create/edit/delete/bulk abilities.

The initial local SQLite run on 2026-10-01 passed all 89 new inquiry cases with 2,713 assertions: HTTP 75/2,542, migration 6/88 and admin 8/83. The same candidate passed the operator authority/MFA, editorial HTTP, owner-delivery HTTP and track metadata regressions: 171 tests and 5,687 assertions altogether, no skips, failures, errors or PHPUnit warnings. Changed PHP files passed Pint. These counts describe local synthetic verification, not production or browser acceptance.

Independent review found that MySQL's `ascii_bin` state column still uses PAD SPACE comparison. The follow-up uses binary operands for every old/new state predicate and tests padded archive targets from both new and read states. SQLite execution verifies its guard path; the MySQL candidate must run those same adversarial cases before acceptance.

Final test counts and source identity belong in the integrating PR. SQLite results do not prove MySQL locking; both engines must execute the final candidate, including lost-response/rotated-owner cases. Rendered customer/operator browser acceptance, real-device accessibility, deployment/private backups and owner policy selection remain separately recorded. The frontend child is independently developed and reviewed; its passing tests do not establish backend acceptance or production configuration.

Three independent-process MySQL cases now cover two owners competing for one global request key and both lock orders of admission versus contact withdrawal. The harness requires distinct process/connection identities and an observed InnoDB wait on the publication singleton; a sequential run cannot pass as concurrency proof. They explicitly skip on SQLite. Local syntax/Pint/listing and SQLite skip behavior passed; actual MySQL execution remains pending. The shared publication lock preserves the active release/notice boundary without a stale admission after withdrawal wins.

Three additional actual editorial HTTP cases verify public enablement/notice projection, persisted authority withdrawal and private-preview suppression; a subsequent actual Filament detail journey verifies immediate read/archive state and action visibility without a reload. The final integrating run must include these deltas; the earlier 89-case result above is historical evidence.
