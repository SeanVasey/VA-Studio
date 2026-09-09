# Catalog metadata and published URLs

WP-02 increment, 2026-09-09. The PR records executed checks against the actual candidate; this document describes implemented behavior and retained acceptance gates.

## Operator workflow

Create and edit tracks in **Admin → Tracks**. Saving creates a private draft or changes the permitted metadata: title, slug, artist, BPM, musical key, genre, mood, tags and description. The measured duration/waveform and publication fields belong to their existing media/publication commands. Up to 20 tags of 80 characters are accepted.

The edit form carries the metadata revision loaded when it opened. If another save wins first, the current save shows a conflict on the title field and preserves the winner. Close and reopen the editor, then reapply the desired changes. A successful no-op does not increment the revision, update timestamps or add an audit event. Publication readiness is checked against the current locked track before changing public metadata; incomplete edits require unpublishing first.

A slug remains editable until the track is first published. After that, its URL stays reserved even when it is unpublished. The form explains the restriction, the command/model reject bypass attempts and the database blocks raw SQL rewrites, reservation clearing and deletion/reuse. Unpublishing removes the public page; republishing restores the same URL. Published track records must be retained. A future rename workflow must preserve redirects before this rule can be relaxed.

## Command and audit boundary

`SaveTrackMetadata::handle(?Track, array, User)` authorizes verified `is_admin` staff before reading or writing the target. Existing records require `metadata_version`; creation cannot supply one. Only the listed metadata plus that expected revision is accepted. The command reads/locks the current row before merging omitted fields and comparing the expected revision. The unique slug index arbitrates conflicting URL claims.

Track persistence and `catalog.track.created` / `catalog.track.metadata_updated` audit insertion share one transaction. Context includes schema version, new metadata revision, changed field names, canonicalization version and before/after hashes. The audit stores the explicit actor, subject and timestamp through the existing audit model; it does not copy draft descriptions into event context. These hashes detect differences; they are not a recoverable copy of earlier metadata. Published commercial/quote evidence retains its separate immutable snapshots.

Filament create/edit actions invoke this command, including direct submitted actions. New import/editor integrations must use it. Media-derived measurements and publish/unpublish remain separate commands. Metadata revision/audit coverage is for the supported command path; arbitrary privileged SQL is not a supported metadata editor. Database URL constraints independently cover bulk writes. Fine-grained RBAC, broader audit storage hardening and production MFA/recovery remain tracked acceptance work.

## Migration and recovery

`2026_09_09_000008_track_metadata_and_public_urls.php` adds nullable `published_slug` and an integer `metadata_version` starting at zero for existing records. It reserves the current slug when a row is published or has a publication timestamp, including previously unpublished records and published legacy rows without a timestamp. It does not synthesize dates, audit events or prior revisions. Undocumented historic URLs still require the WP-12 source/redirect reconciliation; this migration cannot reconstruct unknown history.

Both MySQL and SQLite enforce reservation consistency on inserts/updates and prevent deletion of reserved rows. Case-only SQL rewrites are rejected on the MySQL target even with a case-insensitive collation. The current model supplies the reservation on publication. Bulk source fixtures explicitly provide their synthetic reservation; this is not a bypass of media/rights readiness on public reads.

Apply migrations before deploying this code, with catalog writers paused during the additive backfill/trigger installation. The MySQL migration requires the existing trigger privilege. Preserve the columns, reservations and audit rows when rolling back interface code. Reverting to a pre-increment metadata/publication writer would lose auditing and cannot satisfy the new publication constraint; pause affected actions until a compatible forward fix is deployed. The migration `down()` exists for disposable development databases and is not a production recovery procedure.

## Verification and remaining evidence

`TrackMetadataTest` exercises real Filament component actions and service/model/SQL boundaries: persistent audit actor/diffs, draft privacy, no-op, authorization after mounting, invalid fields, stale saves, audit failure rollback, published readiness and retained URLs. `TrackMetadataMigrationTest` uses an isolated old-schema SQLite fixture to verify historical field preservation. These are synthetic records with no saleable rights.

`TrackMetadataConcurrencyTest` runs two independent PHP/MySQL workers. Both reach the lock boundary with the same expected revision; the winner holds its lock until an independent observer proves the loser is waiting on that exact primary-key row. One save commits and the other reports a revision conflict. SQLite intentionally skips this MySQL-only acceptance test.

The PR must record completed PHP/MySQL, PHP/SQLite, frontend/build and dependency audit results. PHP/Composer and frontend dependencies are absent from the current editing workspace, so no local application execution is claimed. Real-browser modal/focus/error acceptance, operator boot/diagnostics and independent authorization/migration review remain open. Continue them in the [ordered handoff](development-order.md), then the retained Phase 1 media/rights work.

Implementation references: [Filament custom edit persistence](https://filamentphp.com/docs/5.x/actions/edit), [Filament action testing](https://filamentphp.com/docs/5.x/testing/testing-actions), [Laravel validation](https://laravel.com/framework/docs/validation). Runtime tests against committed lockfiles provide compatibility evidence.
