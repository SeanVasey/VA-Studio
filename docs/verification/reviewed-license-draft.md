# Reviewed license-draft editing

This bounded T12 / WP-04 child protects an operator's saved license draft from a stale open editor. It adds a separate `ReviewedLicenseDraft` command; the existing `UpdateLicenseDraft::handle` and its caller-owned transaction behavior remain byte-identical. The Filament editor and native browser journey are a separate coordinated child. This increment does not complete T12, authorize license terms, change approval/publication, or enable sales.

## Reproduced behavior and contract

On base `a3cc2e45a31246f3020b5e476d6f3a51c9d143e4`, a disposable test opened two retained draft models, saved the newer operator's content through the existing command, then submitted the older model/form. The original command replaced the newer content. `license-draft-original-red.{xml,log}` records one case and one assertion with the intended failure: expected `NEWER SAVED SYNTHETIC DRAFT`, actual `STALE SYNTHETIC FORM`. This is a sequential stale-form reproduction, not native concurrency evidence.

`review(LicenseVersion, User)` captures the exact locked current draft and template. `updateReviewed(array $review, array $data, User)` applies only the four existing content fields: authored source, structured terms, and the two offer-availability dates. The UI must retain the original review in server-locked state, bind it to its actual actor/record/action, and consume it before submission validation. It must not recapture at submit time. A stale, invalid, failed or uncertain attempt requires explicit close/reopen; the service does not introduce a saved-request replay contract. Reopening after a lost response reads the durable current draft.

The exact review keys are `schema_version` (1), `intent` (`edit_license_draft`), `actor_id`, `template_id`, `version_id`, `template_hash`, `version_hash`, `template_audit_id`, `version_audit_id`, `template`, and `display`. `template` contains name/slug/type. `display` contains the template ID and four editable fields; dates are UTC `Y-m-d H:i:s` strings or null. Review identity is an internal server-owned contract, not a bearer authorization or a new public API.

Both entry points require their own transaction and fresh persisted operator role, verified email and the existing panel's required MFA. Lock order is actor → template → version, matching template edits and review submission. A retained model's parent ID is only a hint; the locked version must belong to that locked template. The version must remain an unpublished draft. The first ordinary snapshot read is the audit cursor after the current resource locks; an arbitrary caller's repeatable-read transaction is refused.

Full raw locked row hashes and the latest audit identity for each subject bind the original baseline. The audit cursors detect participating same-second A → B → A edits, including existing legacy draft updates and template identity writes. This is not a guarantee against privileged SQL that rewrites rows and bypasses participating audit commands. No unrelated audit rows are locked.

Existing `LicenseContent` validation and model/SQL lifecycle guards remain authoritative. Semantic comparison treats JSON object-key order as irrelevant while preserving list order and every value. A true no-op neither writes nor changes authorship/audits. Changed content retains the existing rule that every content editor is an author and cannot later approve that draft. The atomic audit contains changed field names and before/after hashes, not license prose. Current authority is checked again after projection and after audit work. The final persisted semantic row, template hash/audit and exact new version audit identity must still agree before commit. No offers, frozen versions, grants, contracts or provider calls are changed.

## Focused evidence

The first new SQLite run passed 26 of 30 cases; four MFA fixture cases errored because the isolated process lacked its synthetic application encryption key. No assertion or product gate was changed. Repeating with an explicit disposable key passed 30/30 and 201 assertions. Independent source review identified native MySQL JSON serialization and semantic object-key-order seams before native execution. Full persisted-row comparison now normalizes known model casts; captured raw baseline hashes remain unchanged. The no-op tests deliberately reverse structured-term object keys under a different operator, retaining exact database-row, authorship and audit invariance assertions.

A bounded native successful-save/no-op check passed 5/5 cases and 68 assertions, covering all four retained terms schemas and UTC date normalization. The final reordered-input source is bound separately by `reviewed-license-draft-tested-source.json`.

Final SQLite compatibility selection passed 77/77 cases and 417 assertions in 15.239 seconds: the new 30-case domain class plus existing license authority and actual administration classes. `license-draft-final-sqlite.{xml,log}` retains the result. The existing administration check here exercises the original editor; the new Filament child has its own composed tests.

The final native selection passed 42/42 cases and 1,086 assertions in 193.748 seconds with zero errors, failures or skips (`license-draft-final-mysql.{xml,log}`). It includes all 30 new domain cases and 12 genuine independent-process cases: competing reviewed editors, legacy updates, review submission, template identity changes, role withdrawal and MFA withdrawal, each in both commit orders. Workers retain old models, publish complete readiness JSON atomically, use separate connections/processes and repeatable-read isolation, and wait on the exact expected InnoDB PRIMARY record. Role/MFA cases assert the user row; distinct-actor cases assert the template or version row. The 15-second lock/parent wait, 20-second worker barrier and 40-second process limits are unchanged from the accepted fixture pattern. Normal native durability remains `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite ON and binary logging ON.

Commands use the restored PHP 8.4.26 runtime and an explicitly synthetic test `APP_KEY`:

```sh
php vendor/bin/phpunit tests/Feature/ReviewedLicenseDraftTest.php tests/Feature/LicenseWriterAuthorityTest.php tests/Feature/LicensingAdminTest.php --log-junit /workspace/scratch/0c039e9e0645/license-draft-final-sqlite.xml
python3 /workspace/scratch/0c039e9e0645/mysql-runtime/run-tests.py -- php vendor/bin/phpunit tests/Feature/ReviewedLicenseDraftTest.php tests/Feature/ReviewedLicenseDraftConcurrencyTest.php --log-junit /workspace/scratch/0c039e9e0645/license-draft-final-mysql.xml
php vendor/bin/pint --test app/Domain/Rights/ReviewedLicenseDraft.php tests/Feature/ReviewedLicenseDraftTest.php tests/Feature/ReviewedLicenseDraftConcurrencyTest.php tests/Support/reviewed-license-draft-worker.php
```

Root owns shared registration/census and inclusive acceptance. The native class's single dataset-expanded method requires registration of its twelve cases under the reviewed SQLite exclusion; SQLite does not prove its fences. Independent exact-source review, the separately composed editor/browser child and complete hosted acceptance remain required. No local genuine scanner/browser execution is claimed by these domain receipts.
