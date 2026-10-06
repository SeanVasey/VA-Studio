# License-template authoring controls

October 6, 2026. Bounded T12 / WP-04 follow-up to `75722ac`: author the existing template name, URL slug and type through explicit domain commands and a captured administration form. The source finding was a controls/UX gap, not a reproduced authorization exploit: the previous generic create/edit actions had no template-specific actor audit or displayed edit baseline, and edit remained visible for legal-review/approved versions despite the existing model/SQL identity freeze.

## Command and administration behavior

`SaveLicenseTemplate::create` accepts only the three existing identity fields. `review` captures the exact current template for editing; `updateReviewed` requires that capture unchanged. All entry points reject ambient transactions and lock fresh persisted actor → current catalog authority/required panel MFA enrollment → template → ordered current versions. This matches version creation and review submission. Any version outside draft, or any retained publication timestamp, freezes identity. Existing model and SQL guards remain intact. Creating a successor template does not revise, publish, replace or revoke any existing version, offer, contract or purchase.

Mutation and minimized explicit-actor audit commit together. The audit stores changed-field names, schema/canonicalization versions and before/after hashes, excluding the name/slug text. No-op edits make no extra write or audit; audit failure rolls back the identity write. Invalid types, duplicate slugs and all extra fields fail closed.

The baseline binds actor, edit intent, template ID, canonical complete row hash, displayed name/slug/type and the latest template audit identity. The audit cursor also rejects a participating A→B→A edit within the database timestamp precision. Its query is the first consistent read in the standalone transaction, after the current template lock; preceding authority/template/version reads are locking reads. This observes committed participating edits without acquiring broad locks on unrelated append-only audit rows. It is not a claim to detect unaudited privileged SQL history or to provide universal deadlock freedom.

Filament create/edit invoke the commands without an outer action transaction. A server-locked form capture binds the actual actor/action/record and table context. Public helper calls cannot bypass the real mount/submit lifecycle. Changes to action metadata, record, arguments or table context invalidate the capture. An edit consumes it before form validation; a failed edit must close/reopen and review current data. Creation may correct invalid input because it has no mutable-record baseline. A template submitted for review after an editor opens is refused with explicit frozen/successor guidance. Frozen rows show that guidance and have no edit action.

Unexpected save failures close the form, show that the outcome could not be confirmed and request a reload/inspection. Logs retain only the exception class. They do not claim a rollback or success when the outcome is unknown. Required MFA here verifies current enrollment under the existing configured panel policy; it does not add a new challenge or role policy. Capture consumption applies to the current component lifecycle, not a durable nonce for old authentic Livewire snapshots.

## Local verification

Dedicated coverage:

- `LicenseTemplateAuthoringTest`: ordinary create/edit/no-op, explicit actor/minimized audit, input allowlist, duplicate slug, exact baseline/ABA, stale role/email/deleted/unsaved/customer authority, required MFA, all review freeze states, audit rollback and first-consistent-read/standalone transaction behavior.
- `LicenseTemplateAuthoringActionTest`: actual mounted Filament create/edit and validation recovery, stale competing edits, mounted-before-submission freeze, current authorization/MFA withdrawal, locked review/client retarget/direct-method refusal, unchanged retained graphs and uncertain-save feedback.
- `LicenseTemplateAuthoringConcurrencyTest`: three exact native methods/eight expanded cases. Independent MySQL sessions observe the exact `performance_schema` user/template row wait in both edit/submission orders, both competing-edit orders and both create/edit authority-withdrawal orders. Retained published version, template, approval evidence, submission hashes and actor audit attribution are verified.
- `license-template-authoring.spec.ts`: one actual two-engine administration journey covering keyboard/form-error recovery, creation, two-tab stale refusal, reopening and saved reload, and frozen templates created by the existing genuine customer-fixture submit/approve/publish path. No transport mocks, fabricated publication state or fixture changes.

Native race command, using the disposable local MySQL wrapper with normal durability:

```bash
python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/LicenseTemplateAuthoringConcurrencyTest.php --log-junit /workspace/scratch/0c039e9e0645/license-template-authoring-races-junit.xml
```

Result: MySQL 8.4.11, eight tests / 746 assertions, zero failures/errors/skips, 38.799 seconds. The first development run exposed four test-only comparisons of native JSON ordering/repeated user lock reads; the corrected tests compare database-loaded historical rows and allow repeated current actor locks while still forbidding premature resource locks. No application or concurrency requirement was relaxed.

The final frozen test source passes SQLite: 65 reported cases, 57 executed cases, 302 assertions and exactly eight native-only skips, 11.563 seconds (`license-template-checked-sqlite.xml`). SQLite remains shared-engine behavior evidence, not row-lock evidence.

The initial native combined run used `php vendor/bin/phpunit tests/Feature/LicenseTemplateAuthoringTest.php tests/Feature/LicenseTemplateAuthoringActionTest.php tests/Feature/LicenseWriterAuthorityTest.php tests/Feature/LicenseEvidenceTest.php` through the same disposable MySQL wrapper and executed 114 cases. Both unchanged neighboring suites passed: `LicenseWriterAuthorityTest` 41 cases / 125 assertions and `LicenseEvidenceTest` 16 cases / 94 assertions. Two new test-observation failures exposed MySQL JSON object key ordering and reading the notification session after Filament had consumed it. The audit comparison now canonicalizes associative objects while preserving changed-field list order; the frozen refusal asserts the exact emitted notification configuration.

A subsequent dedicated native authoring run was already executing when the stricter uncertain-result notification check was corrected to read Filament's actual `claimed_notifications` response queue before the notification helper drains it. That process used the two new shared-engine classes (`LicenseTemplateAuthoringTest` and `LicenseTemplateAuthoringActionTest`) and loaded the older observation tests: 57 cases, 55 passed, 283 assertions, exactly those two notification-observation failures, 269.265 seconds (`license-template-final-mysql.xml`). Its result is retained separately and is not called a clean final-source run. The final targeted native rerun of the two corrected create/edit uncertain-result cases passes: two cases / 30 assertions / zero failures, errors or skips, 10.195 seconds (`license-template-notification-mysql.xml`). It verifies exactly one emitted danger notification, full reload guidance, no private exception/success text, unchanged database rows and class-only logging. Application behavior and the other test bodies are unchanged between these runs.

Commands for the final shared-engine and corrected native observation checks:

```bash
php vendor/bin/phpunit tests/Feature/LicenseTemplateAuthoringTest.php tests/Feature/LicenseTemplateAuthoringActionTest.php tests/Feature/LicenseTemplateAuthoringConcurrencyTest.php --log-junit /workspace/scratch/0c039e9e0645/license-template-checked-sqlite.xml
python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/LicenseTemplateAuthoringActionTest.php --filter test_uncertain_audit_failure_closes_form_without_success_and_logs_only_exception_class --log-junit /workspace/scratch/0c039e9e0645/license-template-notification-mysql.xml
npm run typecheck
VASEY_BROWSER_DIRECTORY=/tmp/license-template-discovery node_modules/.bin/playwright test tests/browser/license-template-authoring.spec.ts --list
```

The test processes use a generated temporary `APP_KEY`; parallel runs use separate compiled-view directories. All seven owned PHP files pass syntax and scoped Pint checks; TypeScript, two-engine discovery and `git diff --check` pass. No backend-worktree build was created.

No local genuine scanner or native browser runtime is available. Browser discovery and source review do not accept native rendering, accessibility, race timing or final browser errors; both hosted engines and the complete required CI gates remain necessary. Shared focused/native/browser registration is owned by the integrating change, including only these three exact native methods/eight SQLite exclusions. No schema, dependency lockfile, role, commercial terms, production configuration, scanner behavior or gate budget changes are part of this patch.
