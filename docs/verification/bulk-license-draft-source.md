# Explicit bulk license draft source replacement

This isolated T12 / WP-02 child adds `BulkReplaceLicenseDraftSource`. An operator
supplies replacement prose and explicitly reviews between one and 25 editable
drafts. Applying that captured review changes only their authored source and
records the editing staff member. It does not approve, submit, publish, schedule,
change structured terms or dates, or rewrite a purchased original.

The child follows the policy-integrated parent
`9d39355424466a50f0982052898f0f681ab33117`. Sean's October 6 CI cost policy applies:
the evidence below is focused local component evidence. No hosted matrix,
workflow dispatch, repository setting, publication or account action was performed.
UI/actions, native browser evidence, shared focused selectors, database partition
and receipt registration, work-package integration and independent review belong
to the composing lanes. All existing participating writer bodies remain unchanged.

## Stable command contract

```php
review(array $versions, string $source, User $actor): array
applyReviewed(array $review, User $actor): array
```

`MAX_DRAFTS` is 25. `MAX_REVIEW_BYTES` is 1,048,576 bytes of canonical encoded
review, a fail-closed resource ceiling. Existing `LicenseContent` source and term
validation applies to every selected draft, including its retained structured
terms and dates. No normalization is persisted to retained fields. A caller must
retain the explicit Review result and send that exact result to Apply; the command
does not silently replace it with a new review.

Models supplied to Review are persisted identity/parent hints, not content or
authority. Review normalizes reversed selection to ascending version IDs. Apply
requires that captured order, exact key sets, strict integer identities/cursors,
distinct IDs and consistent repeated-parent projections. Unknown or malformed
keys, missing rows, parent substitution and selection substitution fail closed.

| Projection | Exact keys |
| --- | --- |
| Review | `schema_version`, `intent`, `actor_id`, `authored_source`, `drafts` |
| Draft | `template_id`, `version_id`, `version`, `template_hash`, `version_hash`, `template_audit_id`, `version_audit_id`, `template`, `before`, `after` |
| Before / after | `authored_source`, `structured_terms`, `effective_from`, `effective_until` |
| Template | `name`, `slug`, `type` |
| Result | `changed_ids`, `unchanged_ids` |

The schema is 1 and intent is `replace_license_draft_source`. Before/after dates
are UTC `Y-m-d H:i:s` or null. After contains the supplied source and identical
retained terms/dates. Template/version hashes cover all raw persisted attributes;
participating subject audit cursors detect intervening no-op audits and ABA edits.
Results contain all selected IDs in disjoint ascending lists. Invalid supplied
source is reported under `authored_source`; stale, selection, review-size and
retained-content failures use `licenses`. Unavailable current authority raises
`AuthorizationException`; ambient transactions raise `LogicException` before
private queries.

## Transaction and retention guarantees

Both entry points own standalone transactions. They lock the fresh actor and MFA
enrollment, every unique template in ascending ID order, then every selected
version in ascending ID order, before the first ordinary audit/baseline read.
They recheck current verified-email/admin/MFA authority. Every target must remain
a draft with no publication timestamp and must still belong to its captured
parent. Apply compares the entire locked current projection with the captured
review, including original raw hashes, cursors and display.

A true same-source no-op writes neither authorship nor audit. Changed drafts keep
their existing author IDs and original author in the content-author exclusion,
then add the current editor. That preserves the existing independent reviewer
rule. The only mutable persisted fields are `authored_source`, `author_id`,
`content_author_ids` and `updated_at`.

Each changed draft creates its own actual
`rights.license.draft_source_bulk_updated` event. Context contains schema/version,
`changed_fields`, canonicalization version and batch/before/after hashes, without
the authored prose or structured term values. The command retains each actual
created event ID and, after all writes/observers finish, verifies every selected
row (including no-ops and earlier changed rows), every template, audit cursor,
own event actor/action/subject/context and current authority. Any drift or failure
rolls back the entire batch.

The last proof uses raw locking database reads after every Eloquent retrieval and
authority callback. It compares the current raw actor row with the final checked
actor, all captured template rows, all expected version rows (including no-op
timestamps), subject cursors and actual own audit identities/decoded contexts.
No model retrieval callback or recapture runs after that proof. This closes the
case where a later retrieval callback changes an earlier individually checked row.

## Focused evidence

Evidence is retained under the authorized scratch directory
`/workspace/scratch/b527c7e94bd7/bulk-license-domain-evidence/`. All APP_KEY values
are isolated randomly generated nonproduction 32-byte fixtures; values are not
recorded. The recovered PHP runtime and synthetic database are explicit in each
command record.

The first SQLite run executed 75 cases: 74 passed, with one purchased-graph test
setup error from a stale Offer's null current revision. The next setup correction
also retained a 74/75 result because OfferRevision has no `licenseVersion`
relation. The final fixture loads the persisted offer revision and looks up its
actual `license_version_id`. Both original XML/log/command records remain; these
were fixture errors, not license application failures. No criterion was removed.

The corrected domain class passed 75 cases / 393 assertions. Focused SQLite
compatibility also passed 201 cases / 917 assertions across the new domain class
and unchanged `ReviewedLicenseDraftTest`, `LicenseTemplateAuthoringTest`,
`LicenseWriterAuthorityTest` and `LicenseEvidenceTest`.

A further adverse probe exposed an application gap in the new command's first
implementation: the last selected model's retrieval callback could mutate an
earlier row after its individual final proof. The original one-case/one-assertion
red XML, log and source snapshot remain. The raw final proof fixes that probe and
adds ten cases for late version, audit and actor retrieval callbacks, no-op rows,
template and own-event drift, capture drift and authority withdrawal. The new
domain class now passes 85 cases / 431 assertions. Final focused SQLite
compatibility passes 211 cases / 955 assertions on the current frozen PHP bytes;
scoped Pint, five-file PHP syntax checks and discovery of all 105 new cases pass.

The native class defines 16 mutation race cases (bulk, captured single edit,
legacy edit, submission, template edit, role withdrawal, email withdrawal and MFA
withdrawal, each in both commit orders), plus four explicit capture races against
legacy edits/submission. Every selection contains two versions of one template
and one of another, with reversed retained worker hints. Processes retain their
real models before the transactions start. The parent requires three distinct
PIDs/connections and an observed relevant InnoDB `PRIMARY` / `RECORD` / `WAITING`
identity for the exact requester, blocker, database, table and row before release.
The existing 15-second observation, 20-second worker-barrier and 40-second process
bounds are unchanged. SQLite skips these native definitions and does not prove
serialization.

The original native 95-case run passed 84 cases with 11 race assertion failures
and 2,095 assertions; it had no errors or skips. All 75 then-defined domain cases
passed. Every one of its 20 race cases reached the exact required InnoDB wait,
retained in JUnit `system-out` and extracted to
`native-first-observed-waits.json`. The failed expectation omitted repeated actor
fences: the command, existing verified catalog Gate and explicit MFA recheck each
lock current authority. The corrected test requires their exact three-user prefix
before all sorted template/version fences and the first audit baseline, and also
checks losing stale commands and the other bulk winner. No wait, timeout,
assertion, lifecycle or authority criterion was removed. This retained run did
not include the later raw final-proof correction or ten added cases.

At this reversible local checkpoint the corrected 105-case native selection
(85 domain and 20 races) is running against before/after recorded hashes of all
five PHP files. Its outcome is pending. A documentation-only follow-up will bind
its actual receipt to the unchanged committed PHP blobs; this checkpoint is not
native acceptance or independently approved sensitive source.

## Remaining acceptance

This domain child needs independent review of its actual tested commit and
coherent UI/browser/shared-registration composition. Focused results do not stand
for complete Foundation or native browser acceptance. Existing unresolved hosted
transport observations remain visible in their own records. Owner timing,
commercial policy, legal approval, launch and production migration remain outside
this child.
