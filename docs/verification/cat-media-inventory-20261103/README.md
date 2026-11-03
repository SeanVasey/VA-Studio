# CAT-A bounded staff media inventory — source preparation

Base: `b5d1467bbe00743d0d7298dc2a6d9687c9fec388`. This is advanced preparation for D after B/C. It does not complete CAT, change publication policy, or authorize deletion. Public mounts remain unchanged.

## Contract and owned files

Owned: new `app/Domain/Media/ReadStaffMediaInventory.php`, `tests/Feature/StaffMediaInventoryTest.php`, and this packet. Existing MediaAsset behavior, PF1 SoundKit/ProductDraft work, routes/providers, schemas/indexes, census and shared ledgers are untouched.

The report is an application-callable domain read, `handle(User $actor, int $afterId = 0, int $limit = 50): array`. It requires a standalone transaction, the existing current persisted `MediaWriterActor` catalog authority fence, and the same `AdminMultiFactor` check used by `ReadPrivateTrackReview`. This reuse introduces no new permission or MFA policy. Unsaved/customer/revoked staff are refused before media reads. Existing transaction views are refused rather than reused.

No HTTP/Filament entry point is added: extending the existing track-specific private-response middleware or shared routing is outside this owned slice. No browser/UI acceptance is claimed. A later UI caller must preserve the service fence and provide the existing private response and error behavior; it must not expose the report publicly.

## Exact reference map, established before editing

Direct stored reference witnesses (names identify stored columns, not authenticated entitlement validity):

| Source | Selected reference columns | Existing source contract |
| --- | --- | --- |
| `media_assets` | `parent_asset_id` | `Models/MediaAsset`, media-processing migration: older upload/revision parents remain evidence |
| `media_processing_runs` | `source_asset_id` | `QueueMediaProcessing`, `MediaProcessor`: processing input, including retained completed runs |
| `stems_recordings` | `stems_asset_id`, `master_asset_id`, `preview_asset_id`, `recording_source_id` | `BindStemsToRecording`, `RecordingAssociation`: permanent recording association |
| `media_upload_sessions` | `asset_id` | `ResumableMediaUploads`: completed upload association |
| `pending_entitlements` | `media_asset_id` | test-order-finalization migration, `ActivationEvidence`: retained grant-to-asset association, regardless of pending/active state |

Additional reference chains deliberately **not read or declared covered**:

- Mutable `offers.deliverable_asset_ids` and immutable `offer_revisions.snapshot` (`OfferSnapshot`, `PublishOffer`, `PublicationReadiness`). Superseding a current offer does not erase a purchased revision.
- `ReadTrackPublicationManifest` captures artwork/preview asset IDs, offer assets and recording bindings; `VerifyTrackPublicationFiles` consumes those IDs. This report neither captures nor authenticates publication manifests.
- `media_processing_runs.output_asset_ids` and evidence JSON; retained structured evidence can refer to additional outputs beyond direct relational columns.
- Order/line/revision chains and encrypted `orders.payload_ciphertext` (`ReadOrder`, `ActivateTestFulfillment`), plus encrypted `license_grants.render_input_ciphertext` and contract render-request/original-document chains (`ContractRenderRequest`, `GrantContract`). A current offer query cannot prove these complete.
- Audit/other retained opaque evidence and future reference schemas. This is a positive witness inventory, not an exhaustive reverse-reference registry.

No private media bytes, paths, filenames, technical metadata, hashes, URLs, customer identifiers, encrypted payloads or contract bodies are selected. Table names and reference-column labels are fixed in the service. Source row IDs are not returned.

## Bounds and interpretation

- Asset keyset page: ascending primary key, cursor strictly greater than `afterId`; 1–100 assets, default 50, plus one lookahead. Invalid bounds fail validation rather than silently clamping.
- Exactly five reference-source queries, each selecting the first 200 primary-key-ordered rows plus one lookahead. No full-history aggregate, count, JSON search, negative reverse lookup, or `WHERE asset_id IN (...)` scan. At most 1,106 metadata rows are fetched for a maximum-size page, excluding the small existing user/MFA authority reads (101 asset rows + 5 × 201 reference rows).
- Reference windows are independent fixed prefixes, **not** resumable coverage certification. Later source rows may refer to even the first asset. `rows_observed` and `has_more` describe only each prefix. A page cursor advances assets only. Concurrent changes can change later observations; pages are not an immutable whole-catalog snapshot.
- Each selected asset is `known_reference` if an observed direct reference exists; otherwise `unknown`. Both states retain `reference_coverage=incomplete`. Exhausting all five prefixes still cannot establish completeness because opaque/JSON/encrypted chains remain uninspected. Nothing is called unreferenced or safe to delete.
- Role/status totals cover only returned assets and recorded `size_bytes`; they are not catalog-wide usage, private-file verification, disk usage, quota or deduplicated storage. Missing/invalid/unrepresentable sizes or sum overflow produce `null`, never zero or a rounded total. Counts still reflect returned assets.
- The service does not change assets, entitlements, contracts, publication, scheduling, quotas, or retention; it does not confer download access or authenticate a recorded grant reference. User authority locks are held until the metadata observation completes.

## Focused source tests and the faults they catch

`StaffMediaInventoryTest` defines ten tests using existing test lifecycle and fixture helpers:

1. Independent page byte totals across roles/status, lookahead and private-field exclusion catch global-total mislabeling and metadata leakage.
2. Revoked persisted staff, customer and unsaved actor refusal before media SELECT catches stale actor/bypassed service authorization.
3. Actual MFA enrollment and persisted removal catch a report entry point that forgets required MFA or trusts stale enrollment.
4. Invalid limits/cursor and nonrepeating keyset continuation catch an unbounded/clamped query or duplicate page boundary.
5. Older asset referenced as a parent remains known while coverage stays incomplete; catches latest-only revision treatment.
6. A real existing paid-grant fixture provides a pending-entitlement reference; retained evidence remains byte-identical and the report does not query grant/revision payload tables. This catches missing grant retention witnesses and accidental private payload traversal. The synthetic gateway is fixture setup, not report acceptance through a mocked report.
7. A reference after the first 200 source rows leaves its asset unknown. Exactly six bounded metadata SELECTs catch a fallback unbounded search or invented absence proof.
8. No observed reference still means unknown, including with exhausted prefixes; catches safe-delete inference from partial evidence.
9. Missing recorded size remains unknown in asset and group bytes; catches null-to-zero coercion.
10. An enclosing transaction is refused; catches reuse of an older read view.

## Actual verification and ROOT execution handoff

Source-only preparation. **All PHP lint, Pint, PHPUnit, SQLite/MySQL, native, HTTP/browser and CI checks are UNRUN.** No runtime, Docker, vendor materialization, dependency installation or media tool invocation was performed by the author. `git diff --check` is a whitespace check only.

When ROOT schedules a physical/runtime slot against the exact committed source and approved dependencies:

1. Lint the two new PHP files and run focused Pint check using existing tooling; do not install new tooling.
2. Run `php artisan test tests/Feature/StaffMediaInventoryTest.php` on the admitted disposable SQLite profile. The existing `FinalizationDatabaseMigrations` lifecycle is required; do not replace it with a retained database or ambient transaction.
3. Run the same affected file on the admitted disposable MySQL profile, especially current staff/MFA and retained-grant behavior. SQLite is not proof of native locking/concurrency. No new concurrency or broad full-suite gate is proposed.
4. Review the actual new source/result and remaining limits before D integration. Runtime failure requires diagnosis, not weakening bounds/unknown classification or skipping cases. No publication before the existing B/C/D order.

Existing `MediaWriterAuthorityTest`, `PrivateMediaRevisionRootsTest` and `PrivateTrackReviewTest` are adjacent authority/privacy/retention seams; this slice edits none of them. There is no schema, env-key, dependency, mount or policy change, so no new Ops/environment matrix is introduced. No new Sean decision is required for this read-only source slice.
