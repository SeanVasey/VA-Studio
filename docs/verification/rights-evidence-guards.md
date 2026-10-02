# Verified rights evidence guards

October 2, 2026. This bounded T12 / WP-02 prerequisite supports WP-04's existing rule that verified rights evidence is immutable and corrections use a new declaration. It is an implemented [PR #105 candidate](https://github.com/VASEYDEV/VASEYAUDIO/pull/105), pending fresh full SQLite, MySQL and native browser acceptance. Parent requirements, reviewed publication apply, track scheduling and production acceptance remain open.

## Reproduced problem

`RightsDeclaration`'s existing model hook checks its retained original status. An instance loaded while pending can therefore save after another verifier has committed verified status. Direct query/SQL writes and deletion also bypass that model hook. A fresh verified instance already refuses edits; the separately mounted administration control also refuses saving an edit after verification. The reproduced gap does not establish a mounted HTTP exploit.

The original source-bound probe executed nine cases / 48 assertions, with a strengthened mounted refusal control of one case / nine assertions. Six selected regression cases subsequently fail on frozen accepted source `460a86a576ea6fbe41ad4cc30ec41c68478a66ff`, tree `a3ed238f4c8dfc1a9b5759c419f02061349d6f58`: stale provenance/disclosure changes, retargeting, deletion, pending-row identity replacement and a sequential parent deletion with foreign-key checks disabled. Their original red report records 17 assertions. This feedback is distinct from final candidate acceptance.

## Stored-evidence floor

Forward migration `2026_10_02_000035_rights_evidence_guards.php` adds seven owned SQLite/MySQL triggers without rewriting a row, audit, verifier or verification time:

| Boundary | Retained protection |
| --- | --- |
| Rights UPDATE | Refuse every change when the stored old status is exactly `verified`; also refuse displacement of a different verified primary identity |
| Rights DELETE | Retain verified evidence, including unreferenced declarations |
| Rights INSERT | Refuse an existing verified identity collision before replacement/upsert can erase it |
| Rights AFTER INSERT | Validate actual persisted positive verified declaration/track identities after an automatic ID is assigned |
| Track DELETE | Refuse deleting a parent of verified evidence |
| Track UPDATE | Refuse parent identity displacement and actual unique-key collisions with a different protected parent |
| Track INSERT | Refuse replacement through a protected parent's ID or unique slug |

Status comparison is byte-exact (`BLOB` on SQLite, `BINARY` on MySQL), matching the existing application rule. A collation-equivalent label does not acquire verification. Pending edits, pending-to-verified service transitions and genuinely new declarations remain available. A newer pending declaration continues to block new readiness under existing semantics; it never changes earlier verified rows or purchased snapshots.

The persisted verified identity check avoids ambiguity between SQLite's unassigned `BEFORE INSERT` row-ID sentinel and an explicit replacement ID. Existing incompatible verified identities cause preflight refusal without data repair; unverified legacy identities remain editable. No verification or legal approval is fabricated.

Both insert and update collision boundaries matter: SQLite `INSERT OR REPLACE` and `UPDATE OR REPLACE` can remove a conflicting row without executing its delete trigger when recursive triggers are disabled. Tests exercise the actual conflicting identity and require the owned refusal message, rather than accepting an unrelated foreign-key error as guard proof.

Engine reference: [SQLite conflict handling](https://www.sqlite.org/lang_conflict.html).

## Installation and recovery

Before its first DDL statement, the migration validates the actual rights/track tables, columns, index keys and collations, restrictive rights foreign keys, temporary shadows, retained references and every owned trigger's name/table/timing/operation/body. It refuses foreign definitions, drifted cascade constraints and an unrecognized installation sequence.

MySQL commits trigger DDL independently. Reapplication resumes only an exact surviving creation prefix, creating its missing suffix without replacing another object. A complete retry and operational `down()` retain all seven guards and historical rows. Explicit disposal of a complete development database is separate from operational rollback.

Four existing synthetic tests previously downgraded verified rows to simulate readiness loss. `BulkTrackTagsTest`, `BulkUpdateTrackMetadataTest`, `TrackPublicationGuardTest` and `ExclusiveOfferTest` now append a newer pending declaration. Their original refusal, stale-review and atomicity assertions remain intact.

## Currentness and verification limits

No new locking read, actor policy or writer command is introduced. MySQL's current stored `OLD` values protect a rights-row mutation after its write-lock wait. Concurrent parent retention also relies on the validated existing RESTRICT/NO ACTION foreign keys. Supplemental parent status lookups can inherit a MySQL Repeatable Read snapshot; sequential foreign-key-disabled tests prove guard causality, not concurrent protection with disabled foreign keys or arbitrary cascades.

Engine references: [MySQL consistent reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html) and [restrictive foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html).

The dedicated MySQL race method has two exact datasets, stale model update and stale model delete. Each requires an independently retained pending instance, verification holding the exact rights primary row, an observed `performance_schema` lock wait, verifier commit, and an owned refusal with unchanged verified row/audit. SQLite intentionally skips those two identities. Their acceptance requires positive assertions and actual execution in the current hosted MySQL receipts.

The guards retain existing evidence. They do not supply fresh transactional actor/MFA checks, review identity, compatible exclusive-scope/writer fencing or fresh physical media proof. The direct-verifier current-authority/MFA gaps remain the next writer-boundary work before reviewed manifest comparison/apply. Track timing choices and production rights/reviewer/recovery policy remain separate decisions. The [accepted PR #104 record](../development-order.md#pr-104-staff-authoring-acceptance) and its separately verified postmerge run are preserved; neither supplies acceptance for this new runtime source.

## Implementation feedback and acceptance gate

Local SQLite feedback uses PHP 8.4.26 and the locked dependencies, with an explicit synthetic testing key. The final assembled rights-family source is `fec38b27c665b1633ec55e4c2919d3fbd52bb4d5`, tree `90571adf5c0fa9ff912a007fd2c586a21ff74155`: 43 behavior cases, 33 migration cases and the two skipped MySQL race datasets. `php artisan test --filter=RightsEvidenceGuard` reports 76 executed cases / 348 assertions, zero failures or errors and exactly two skips. Independent review matched all 78 discovered identities with their original JUnit cases. The three mixed-statement rollback cases separately passed 12 assertions.

The four adapted fixture classes previously passed 96 cases / 1,070 assertions against their unchanged final PHP blobs and the same guard migration. Their original assertions are retained. No passed fixture, browser or previous PR #104 suite was restarted for prose changes. The first local missing-key fixture error and an earlier assertion-helper error remain recorded as unsuccessful implementation feedback; their corrected source/key runs passed.

New migration/test/worker files and three adapted test files pass Pint. `ExclusiveOfferTest.php` retains the same four pre-existing Pint fixer findings as its accepted baseline; no repository-wide formatting pass is claimed. Syntax and whitespace checks pass. The 24 database-receipt safeguards pass with the exact new policy entry; the receipt collector, workflow, classifiers and complete-suite gates are unchanged.

Fresh full discovery proves 2,601 cases in 162 files, retaining all 2,523 accepted source case/file/method identities and adding exactly the 78 rights cases. The reviewed SQLite policy grows from 77 methods / 185 expanded identities to 78 / 187, solely for the two new MySQL races. Measured partitioning retains every complete file and expanded case exactly once; missing timing samples use the existing fallback without losing coverage.

These checks are implementation feedback. The published PR head and exact composed tree require fresh complete hosted SQLite/MySQL, dependency/frontend, native browser, independent source review and current-run receipt acceptance before merge. Positive execution of both new MySQL races is required. Earlier documentation-only PR #105 CI, accepted PR #104 originals and source-bound screenshots cannot accept this runtime change.
