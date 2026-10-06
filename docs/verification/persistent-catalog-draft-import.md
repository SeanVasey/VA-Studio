# Private normalized catalog draft import

Status: implemented private SQLite metadata staging with focused synthetic acceptance. Independent sensitive review approved runtime child `546475654af4d9de33498e59b4f2fe10b40a6853`. No source acquisition, actual BeatStars export/schema/count verification, imported production catalog or cutover is reported by this document. Integration records the final composed source separately.

The first actual adapter consumes an operator-prepared, canonical normalized catalog snapshot retained outside Git with protected raw artifacts, original identifiers, acquisition method/operator/time/watermark and SHA-256 bindings. It creates only private draft metadata through the existing `SaveTrackMetadata` command. It does not infer publication, rights, prices, customer identity, historical contracts, entitlements or consent. Original source visibility remains evidence; sold or unknown states conflict. Declared media is retained as an explicit dependent intake requirement; this batch does not promote or automatically accept it.

The unchanged synthetic `CatalogDryRun`/`DryRunFiles` remain planning tools. This adapter reuses `CanonicalJson` and the pure exact-prefix checkpoint/count operation, with a separate real source admission contract and actual database target reads. It does not disguise real values as synthetic fixtures. The existing persistent-workspace guard, isolation configuration and lease are reused by a new trusted CLI wrapper; no public import route is added.

Dry run reads the exact source/artifact bytes and current relevant target rows, source mappings and slug ownership. It writes no database row or audit. Its protected report binds the canonical source, acquisition, transform, actor, exact reviewed checkout and current target evidence, with source-ID results and minimized field/change hashes. Apply requires the explicit exact report digest, same source/reviewed checkout and current actor/target/mapping/slug evidence.

Each apply segment contains 1–25 normalized records and starts its own transaction. It fences current staff and MFA first, then its batch/mappings, then the existing metadata writer. Draft rows, immutable canonical mappings, progress evidence and minimized audits commit or roll back together. Retained mappings support exact same-input replay and a lost-success acknowledgment without another draft or audit. Conflicting source IDs/slugs, changed input, modified/public/sold targets, stale reviews and missing authority refuse; no existing track is overwritten.

Final preservation proof uses direct PDO/current reads after application and audit callbacks, covering current actor, complete draft rows and exact mappings without firing another `QueryExecuted` callback. Source snapshots and raw artifacts remain unchanged. Private path, ownership, ancestor, symlink/hardlink and byte-identity admission is mandatory; missing effective-UID inspection fails closed.

The live SQLite schema is checked through primary PDO at entry and after all
callbacks. Exact owned table, column/default/foreign-key SQL, unique indexes and
immutable triggers must match the reviewed migration grammar. Foreign keys must
be enabled; writable schema and ignored checks must be disabled. The live schema
digest is part of the reviewed binding. A migrations-history row or retained
source-file hash does not substitute for these live constraints. Missing,
foreign or extra owned schema is refused without repair. Conflict-rejecting
BEFORE INSERT triggers protect both primary and unique identities against
SQLite `INSERT OR REPLACE`, including when recursive delete triggers are off.

The reserved forward migration `2026_10_06_237000_catalog_import_progress.php` is for new durable import evidence only. It must not rewrite existing tracks, audits or commercial history. Private source data must remain out of committed fixtures and stdout; test records are explicitly labeled synthetic normalized input created in isolated temporary private directories.

Focused acceptance exercises actual draft persistence, read-only dry run, exact approval/source/target/actor drift, immutable mappings, lost-success replay, bounded resume, atomic audit/write failure, stale callbacks, duplicate source/slug conflicts, missing/revoked staff or MFA, private file/ancestor/link refusal and raw artifact drift. MySQL concurrency, production host/media/backup restore and final consolidated acceptance remain separate evidence.

This implements only the metadata/identity staging part of M-001, M-008/M-009/M-011 as supported by current target fields, and conservative M-012 disposition. M-002 route authority/redirects, M-003–M-007 exact media roles/processing, broader metadata/source fields, source acquisition and all historical commercial/customer obligations remain explicit dependent work. Unknown actual export availability/schema/counts remain operator-input dependencies, not guessed provider facts.

## Source and private command contract

`NormalizedSourceSnapshot::SCHEMA` is `vasey-private-catalog-drafts-v1`;
`TRANSFORM` is `vasey-private-catalog-metadata-v1`. The snapshot must be canonical
JSON with an optional trailing newline, at most 1 MiB, and contain exactly:
schema version/purpose, snapshot and source-system IDs, acquisition/watermark
timestamps, acquisition method and operator reference, raw artifact bindings and
normalized records. IDs retain string identity, including leading zeroes.
Each record retains original typed metadata, the normalized nine-field target
projection, original visibility, artifact identities and optional media
references. Its SHA-256 binds the payload excluding the separate source ID and
digest field. This format describes the operator's captured evidence; it is not
a claim about any uninspected provider's export schema or transform accuracy.

The source admits at most 1,000 records, 100 artifacts and 1 GiB of bound raw
artifact bytes. The source and report directories must be private `0700`, with
owned regular `0600` files, single links, canonical absolute paths and safe
ancestors outside Git. Raw artifacts are streamed for hashes; no private source
data is committed or printed. Reports are canonical, HMAC-authenticated and
exclusive-created `0600` files; the report contains private normalized metadata,
so its parent directory remains protected. Batch and mapping evidence in SQLite
is encrypted using the retained installation key. Audits contain identities and
hashes rather than private values.

Use a stopped, initialized private installation already bound to this exact
reviewed release and its full schema/build identity. A previous installation
requires the separately reviewed stopped copy-upgrade; these commands never
repair, reset or migrate an existing workspace automatically. Start from the
checkout containing the reviewed commit and set the variables below to the
actual private installation, authorized captured snapshot, unused protected
report path, current staff ID and exact 40-character reviewed commit.

```sh
node scripts/migration/persistent-catalog.mjs review \
  --directory "$INSTALLATION" \
  --source-directory "$SOURCE_DIRECTORY" \
  --report "$REPORT" \
  --actor-id "$STAFF_ID" \
  --expected-target-sha "$REVIEWED_COMMIT"
```

The command requires the staff password through a hidden terminal prompt;
password switches, piped secrets and hidden-input fallback are refused. Current
MFA configuration and enrollment are enforced; required app MFA also verifies
the current authenticator code. Missing/revoked authority or changed credentials
refuse. The external caller's configuration, provider secrets and transport are
not inherited by the retained installation.

The redacted stdout result supplies `review_sha256`, counts and
`database_writes=0`. The operator reviews the exact protected report and source
before supplying its digest explicitly:

```sh
node scripts/migration/persistent-catalog.mjs apply \
  --directory "$INSTALLATION" \
  --source-directory "$SOURCE_DIRECTORY" \
  --report "$REPORT" \
  --actor-id "$STAFF_ID" \
  --expected-target-sha "$REVIEWED_COMMIT" \
  --expected-review-sha256 "$REVIEW_SHA256" \
  --limit 25
```

Any conflict blocks the whole reviewed batch. Repeat that exact command to
advance another bounded segment; changed sources/targets/actors need a new
review and explicit disposition rather than an overwrite. An exact complete
replay creates no additional track, mapping or audit. A fresh identical review
records exact mapped skips. Applying a report does not approve commercial
terms, validate source media, publish a track or import a customer obligation.

The broker's live OS lease excludes simultaneous installation use; a retained
token alone does not grant ownership. Source consumption has a separate
nonblocking exclusive manifest lease. On interruption or worker timeout, the
wrapper waits for its owned PHP worker to stop before releasing the installation
lease. The next operation reacquires a real OS lock and rechecks the retained
identity; no other listener or lease owner is stopped.

## Focused evidence — October 6, 2026

The resumed lane preserved all ten original modified/untracked source files at
`16528a379ff89f65d269945c30cd4fd675950925` before copying them into an isolated
child on integration `0f89340`. The original unfinished workspace was not
modified. Normalized admission prerequisite `773b971` is carried by child
`39cd717`; implementation `f7aa6da` contains the completed adapter and CLI.
Native assertion correction `d494b3d` changes only the lease test's ownership
proof. Corrected runtime successor `5464756` adds installed-schema and immutable
replacement guards. The exact command source and assertions are retained in Git; final
integration/review records identify the chosen full SHA.

On PHP 8.4.26 and the actual SQLite/POSIX runtime, the affected command passed
**86 cases / 314 assertions**, zero failures/skips, in **7.202 seconds** on
`546475654af4d9de33498e59b4f2fe10b40a6853`:

```sh
php vendor/bin/phpunit \
  tests/Feature/PersistentCatalogDraftImportTest.php \
  tests/Unit/NormalizedCatalogSourceTest.php \
  tests/Unit/ProtectedCatalogReportTest.php
```

The feature suite explicitly chooses an isolated named in-memory SQLite
connection, including under a surrounding MySQL suite. This verifies this
private SQLite contract and never represents MySQL row-lock evidence.

The actual Node/PHP/PTY/SQLite command suite passed **10/10 native cases**, zero
failures/skips/cancellations, in **32.688 seconds** on that exact successor:

```sh
PERSISTENT_CATALOG_REQUIRE_PHP=1 node --test \
  scripts/migration/persistent-catalog.test.mjs
```

It creates synthetic private installations and clean Git-bound releases, uses
the real hidden-password terminal, and runs actual reviewed catalog commands.
Observed outcomes include a database-read-only review, committed one- and
two-record segments, process restart, lost-success replay, private file
preservation, wrong/nonterminal credentials, active OS lease refusal,
source/report/digest/target/schema drift, prompt interruption with worker cleanup and
subsequent real lease reacquisition, and changed-release/unknown-switch refusal.
The native fixture requires supported PHP/SQLite/POSIX, locked dependencies,
built assets and util-linux `script`; setting the require flag makes unavailable
prerequisites fail instead of reporting a skipped pass.

The first new native run failed six assertions that incorrectly assumed a
stopped shared broker always erased its lease token. It had completed the real
review, segmented application and replay. The corrected assertions prove
ownership through actual lock reacquisition and worker exit, without weakening
the live-lock admission guard. One earlier expanded PHP run exposed and
corrected test-only canonical-key ordering and an incorrect entitlement table
name. No passing result is inferred from those failed runs.

Independent review reproduced a missing delete trigger being admitted before
review, between review/apply and after the final mapping audit on predecessor
`d494b3d`: each canary created one track, batch, mapping and three audits. That
predecessor is blocked. The successor adds actual installed-schema admission,
post-callback schema/target proofs and replacement-safe insert guards. Its
expanded SQLite/source/report selection and corrected native command passed on
the exact successor as recorded above. Independent review reran the affected
selection (**86/314**, 7.118 seconds) and the retained external missing-trigger
and replacement canaries (**4/17**, 0.775 seconds), inspected the exact native
receipt/source binding, and approved
`546475654af4d9de33498e59b4f2fe10b40a6853`, tree
`fe435dfe211dab994f1b1b2fe32ac397b415740c`. No runtime source changed in the
subsequent documentation-only evidence completion. Predecessor passing cases
do not approve the corrected candidate.

Pint passed all affected PHP source, fixture and test files; Node syntax checks
passed for both command and native test. No routine MySQL/full-matrix runs or
hosted CI dispatch were performed. Migration's explicit InnoDB DDL and triggers
have not received new MySQL runtime verification; this command rejects non-SQLite
targets. The conditional required-MFA/TOTP CLI branch was source-reviewed;
the native local-workspace tests did not exercise a required authenticator-code
operation. Source acquisition, real normalization approval/count reconciliation,
private host/storage/scanner, restart/backup restore on the selected actual
installation, real media intake, redirects and historical commercial/customer
obligations remain outside this increment. Full acceptance belongs to the exact
final integrated candidate under the current CI cost policy.
