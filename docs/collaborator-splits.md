# Collaborator splits

BL-05 / FP-035, 2026-11-10. Staff can record who is credited on a track and how each split type is shared. This is catalog metadata only. There are no payouts, and nothing here has been accepted as complete FP-035 parity.

## What is recorded

- **Collaborator** (`collaborators`): a display name, one role from a closed set (`producer`, `writer`, `performer`; `App\Domain\Rights\CollaboratorRole`) and an optional source reference such as an agreement or export reference. A person credited in two roles is two records.
- **Split** (`track_collaborator_splits`): one collaborator's share of one split type on one track, in whole basis points (10000 is 100%). Split types are a closed set (`publishing`, `master`; `App\Domain\Rights\SplitType`). Each type is its own set per track; the two never add together.

Neither table has a payment, tax, email, phone or address column, and the service refuses an email address in the name or the source reference. Do not record payment details, tax ids or contact data in these fields.

## Complete and incomplete

For each track and split type, the set is **complete** only when its shares total exactly 10000 basis points. Any other total is **incomplete**, including a track with no recorded shares. Completeness is computed from the rows on every read (`CollaboratorSplitReport`); there is no stored flag that could go stale.

## Metadata only

Splits feed no pricing, accounting, contract, grant, delivery or payout code. `CollaboratorSplitsTest` scans `app/`, `routes/`, `config/` and `resources/` and fails when any file outside a reviewed list names the model, its tables or its report. The only readers outside the model and its editor are the two report-only places below.

## Database integrity

The migration `2026_11_10_280300_collaborator_splits.php` enforces, on SQLite and MySQL, even when the application service is bypassed:

| Rule | Enforced by |
| --- | --- |
| A share is 1 to 10000 basis points | trigger (insert and update); the column is an unsigned small integer |
| A collaborator appears once per track and split type | unique key `track_collaborator_splits_member_unique` |
| Shares of one track and split type never total more than 10000 | trigger (insert and update) |
| Role and split type are in their closed sets | triggers |
| A split's track, collaborator and type never change | trigger (remove and re-add instead) |
| A track or collaborator with shares cannot be deleted | restricting foreign keys |

On MySQL the total trigger first locks the track row and then reads the other shares with a locking read. Two writers of the same track therefore run one after the other, and an earlier Repeatable Read snapshot cannot hide a committed share. Rollback of the migration is refused while either table holds a row.

## Writing

Every write goes through `App\Domain\Rights\CollaboratorSplits`: `saveCollaborator`, `addSplit`, `updateShare` and `removeSplit`. Each runs in one transaction that locks, in this order, the acting staff user, the track, then the track's shares of that split type. Each checks the current `administer-catalog` gate and MFA enrollment under that lock, validates against the locked rows, and records exactly one audit event. If the audit event cannot be written, the change rolls back. An unchanged save writes nothing and records nothing.

`updateShare` and `removeSplit` take the share the editor saw. If someone else changed that share in the meantime, the command refuses and the editor must reload.

Audit actions: `rights.collaborator.created`, `rights.collaborator.updated`, `rights.collaborator_split.added`, `rights.collaborator_split.updated`, `rights.collaborator_split.removed`. Split events carry the track, collaborator, split type, the share before and after, and the set's new total. Collaborator events carry the changed field names, the role and before/after hashes, not the name.

## Staff editor

- **Admin → Collaborators**: add and edit collaborators. There is no delete.
- **Admin → Collaborator splits**: add a share, edit a share, remove a share. Each row shows its set's total and a Complete or Incomplete badge.

Both pages require the staff gate and enrolled MFA on every request, and the service checks both again under lock. The pages hold no rules of their own.

## Publication: reported, not enforced

Incomplete splits are reported in two places and block nothing:

- `TrackPublicationManifest::advisories()['collaborator_splits']` carries the report. Advisories sit beside the manifest and are not part of its payload or hash, so a split change can never invalidate or alter a reviewed publication.
- **Tracks → Check readiness** lists one advisory line per incomplete split type under the unchanged verdict.

**Open decision for Sean:** should an incomplete split set block publication, for which split types, and from what date? Until he decides, publication is unaffected.

## Other open points

- The role and split type sets are the minimum named in the task. Adding a role or type (for example a publisher entity or a featured-artist role) needs a new migration, because the triggers hold the closed sets.
- Collaborators cannot be deleted or merged yet.
- Splits are current state with an audit trail. There are no effective-dated revisions and no approval status, which FP-035's acceptance wording asks for.
- Nothing imports BeatStars splits. The 2026-11-07 export check found 19 titles crediting featured artists and 2 recorded splits; entering real values is Sean's.
- Retention: both tables are classified in `RetentionSchedule::NOT_CUSTOMER_DATA` as staff catalog data. A collaborator's display name can be a real person's name; whether collaborators need their own retention or data-rights treatment is not decided.

## Verification

Focused development checks on branch `claude/collaborator-splits-20261110`, not acceptance. All names, references and shares in the tests are synthetic.

- SQLite, `bash pu.sh tests/Feature/CollaboratorSplitsTest.php tests/Feature/CollaboratorSplitsConcurrencyTest.php`: 13 run, 11 passed, 2 skipped (the MySQL-only concurrency class, by design).
- SQLite, neighbouring suites (`CustomerDataRetentionReportTest`, `TestCommerceEvidenceCommandTest`, `TrackPublicationManifestTest`, `TrackPublicationApplyTest`, `PrivateTrackReviewTest`, `RightsDeclarationWriterActionTest`, `RightsEvidenceGuardTest`, `TrackMetadataPresetsTest`, `TrackMetadataPresetsMigrationTest`, `TrackPublicationGuardTest`): all passed.
- Mutation checks: each service guard (total, duplicate, share range, stale share, staff gate, MFA, both audit writes, email refusal), each database guard (total trigger, share range, unique key, split-type set, identity immutability, role set), the page MFA check and an advisory moved into the hashed payload were each broken in turn; every one made `CollaboratorSplitsTest` fail. Breaking the page's own staff-gate line was not caught, because `OperatorResource::canAccess()` and the service gate still refuse.
- Native MySQL 8.4.11: see the commit that follows this one for the run of `CollaboratorSplitsConcurrencyTest` and the database-refusal test.
