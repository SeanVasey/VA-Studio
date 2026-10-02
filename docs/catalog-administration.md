# Catalog metadata and published URLs

WP-02 increment, 2026-09-09. [PR #28](https://github.com/VASEYDEV/VASEYAUDIO/pull/28) records executed checks against the actual candidate; this document describes implemented behavior and retained acceptance gates.

## Operator workflow

Create and edit tracks in **Admin → Tracks**. Saving creates a private draft or changes the permitted metadata: title, slug, artist, BPM, musical key, genre, mood, tags and description. The measured duration/waveform and publication fields belong to their existing media/publication commands. Up to 20 tags of 80 characters are accepted.

The [T12 bulk-tag child](verification/bulk-track-tags.md) adds an explicit before/after review for tag additions to up to 25 selected tracks on the current page. It preserves existing tag order and uses the same metadata command under one atomic batch transaction. Back or a changed selection/input requires fresh review; a stale target rejects the whole selection. Existing per-track audits suffice, and a fresh already-applied review is a no-op. Actual MySQL/native and integration acceptance remain recorded gates in the child evidence; this does not complete broader catalog bulk editing or T12.

The edit form carries the metadata revision loaded when it opened. If another save wins first, the current save shows a conflict on the title field and preserves the winner. Close and reopen the editor, then reapply the desired changes. A successful no-op does not increment the revision, update timestamps or add an audit event. Publication readiness is checked against the current locked track before changing public metadata; incomplete edits require unpublishing first.

A slug remains editable until the track is first published. After that, its URL stays reserved even when it is unpublished. The form explains the restriction, the command/model reject bypass attempts and the database blocks raw SQL rewrites, reservation clearing and deletion/reuse. Unpublishing removes the public page; republishing restores the same URL. Published track records must be retained. A future rename workflow must preserve redirects before this rule can be relaxed.

## Reusable metadata presets

**Admin → Track metadata presets** manages named artist, BPM, musical key, genre, mood, ordered tags and description defaults. Create/edit saves use an expected version and transactional minimized audit; unchanged saves are no-ops. Archive retains a preset and removes it from new-draft selection. Presets do not carry a title, URL, publication state, media, rights, offer or price.

In **Tracks → Create from preset**, choose an active preset, then review its copied values in the ordinary metadata form and supply the new title and URL. **Create private draft** invokes the existing metadata command under a fresh locked staff/MFA check. Values are copied once: changes or archival after the form opens do not silently replace the reviewed values, and later preset changes never mutate saved tracks. This is explicit reusable authoring, not automatic publication or a commercial license preset.

The preset child is accepted in PR #95; [its evidence](verification/track-metadata-presets.md) records exact source and full verification. Reviewed bulk metadata, protected staff review and manual publication guards below form the next combined candidate. Broader license operations, track scheduling, granular permissions and production recovery remain tracked work.

## Reviewed bulk metadata edits

Tracks also offers **Edit metadata** for 1–25 explicit tracks on the current filtered page. Artist, BPM, musical key, genre and mood start at Keep; Set supplies an ordinary valid value and Clear is available for nullable fields. Review displays exact current/proposed values before **Save reviewed metadata**. A changed selection/table view or stale target requires a fresh review. The command checks current locked authority/MFA and applies the entire reviewed batch atomically through `SaveTrackMetadata`, retaining all other fields and per-track audit evidence. See [the bulk metadata evidence](verification/bulk-track-metadata.md) for actual checks, recovery and hosted acceptance gates.

## Protected operator track review

**Review track** opens a close-only private staff modal with current descriptive metadata, ordinary publication blockers and verified artwork/tagged preview or explicit unavailable states. It uses fresh persisted authority/MFA and exact protected derivative URLs; it cannot edit or publish a track and grants no anonymous access. Reopening reads current values. Signed Tracks updates on the actual server Livewire route remain private when CSRF or another early middleware failure precedes component boot; CSRF failures retain HTTP 419. Unrelated routes and unsigned client markers cannot establish this boundary. See [protected review evidence](verification/private-track-review.md) and the [PR #104 repair record](verification/private-track-review-repairs.md) for read-only guarantees, actual checks and pending hosted acceptance.

## Reviewed manual publication

**Publish** and **Unpublish** confirmations capture the current track, intent, metadata revision and monotonic publication revision when opened. A metadata edit or intervening publication cycle rejects the old confirmation even if status and timestamps return to their previous values. Close and reopen to review current state; Cancel, another action or a changed table context consumes the confirmation. Publication still requires ordinary current readiness, and every supported publication command increments the counter with its audit in one transaction.

Interactive actions use `PublishTrack::review`, `publishReviewed` and `unpublishReviewed`. These APIs require their own transaction, lock the current actor/Gate/MFA before the track and verify both revisions and state before writing. The retained immediate `handle`/`unpublish` compatibility commands use a supplied Track only as identity and retain caller transaction semantics; a caller's old MySQL Repeatable Read snapshot can still affect ordinary readiness children. They are trusted server compatibility APIs, not an interactive or future scheduling boundary. New such callers must use the strict reviewed APIs and their separate scheduling evidence contract.

Migration `2026_10_02_000034_track_publication_version.php` adds a signed integer counter starting at zero for retained tracks without inventing history. Range/nondecrease triggers retain published URL and audit evidence; `down()` preserves the column, counter values and guards. For interface rollback, disable reviewed actions until compatible application code is restored; never reset counters or remove retained audits. Arbitrary privileged SQL that changes status without advancing the counter is outside the supported publication command contract. See [publication-guard evidence](verification/track-publication-guards.md) for schema recovery, executed development checks and full acceptance gates. No track schedule, publication window or production deployment is supplied by this child.

## Command and audit boundary

`SaveTrackMetadata::handle(?Track, array, User)` authorizes verified `is_admin` staff before reading or writing the target. Existing records require `metadata_version`; creation cannot supply one. Only the listed metadata plus that expected revision is accepted. The command reads/locks the current row before merging omitted fields and comparing the expected revision. The unique slug index arbitrates conflicting URL claims.

Track persistence and `catalog.track.created` / `catalog.track.metadata_updated` audit insertion share one transaction. Context includes schema version, new metadata revision, changed field names, canonicalization version and before/after hashes. The audit stores the explicit actor, subject and timestamp through the existing audit model; it does not copy draft descriptions into event context. These hashes detect differences; they are not a recoverable copy of earlier metadata. Published commercial/quote evidence retains its separate immutable snapshots.

Filament create/edit actions invoke this command, including direct submitted actions. The resource adapter maps relative validation errors into the actual mounted form path so conflicts and readiness failures render beside the visible field. New import/editor integrations must use it. Media-derived measurements and publish/unpublish remain separate commands. Metadata revision/audit coverage is for the supported command path; arbitrary privileged SQL is not a supported metadata editor. Database URL constraints independently cover bulk writes. Fine-grained RBAC, broader audit storage hardening and production MFA/recovery remain tracked acceptance work.

## Migration and recovery

`2026_09_09_000008_track_metadata_and_public_urls.php` adds nullable `published_slug` and an integer `metadata_version` starting at zero for existing records. It reserves the current slug when a row is published or has a publication timestamp, including previously unpublished records and published legacy rows without a timestamp. It does not synthesize dates, audit events or prior revisions. Undocumented historic URLs still require the WP-12 source/redirect reconciliation; this migration cannot reconstruct unknown history.

Both MySQL and SQLite enforce reservation consistency on inserts/updates and prevent deletion of reserved rows. Case-only SQL rewrites are rejected on the MySQL target even with a case-insensitive collation. The current model supplies the reservation on publication. Bulk source fixtures explicitly provide their synthetic reservation; this is not a bypass of media/rights readiness on public reads.

Apply migrations before deploying this code, with catalog writers paused during the additive backfill/trigger installation. The MySQL migration requires the existing trigger privilege. Preserve the columns, reservations and audit rows when rolling back interface code. Reverting to a pre-increment metadata/publication writer would lose auditing and cannot satisfy the new publication constraint; pause affected actions until a compatible forward fix is deployed. The migration `down()` exists for disposable development databases and is not a production recovery procedure.

## Verification and remaining evidence

`TrackMetadataTest` exercises real Filament component actions and service/model/SQL boundaries: persistent audit actor/diffs, draft privacy, no-op, authorization after mounting, invalid fields, stale saves, audit failure rollback, published readiness and retained URLs. `TrackMetadataMigrationTest` uses an isolated old-schema SQLite fixture to verify historical field preservation. These are synthetic records with no saleable rights.

`TrackMetadataConcurrencyTest` runs two independent PHP/MySQL workers. Both reach the lock boundary with the same expected revision; the winner holds its lock until an independent observer proves the loser is waiting on that exact primary-key row. One save commits and the other reports a revision conflict. SQLite intentionally skips this MySQL-only acceptance test.

The original metadata increment's PR records its accepted checks; the dated initial editing-runtime limitation is historical. New child evidence records actual local PHP/SQLite and Chromium feedback and the accepted preset run. The three-child authoring candidate still requires final current-source full MySQL/SQLite, frontend/build/audits, Chromium/mobile-WebKit, genuine media and independent review. Follow the [ordered record](development-order.md) for exact source/run acceptance; broader production/device acceptance remains open.

Implementation references: [Filament custom edit persistence](https://filamentphp.com/docs/5.x/actions/edit), [Filament action testing](https://filamentphp.com/docs/5.x/testing/testing-actions), [Laravel validation](https://laravel.com/framework/docs/validation). Runtime tests against committed lockfiles provide compatibility evidence.
