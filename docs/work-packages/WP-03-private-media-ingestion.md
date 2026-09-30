# [WP-03] Private media ingestion, quarantine and preview processing

Status: **WAV/artwork implemented in PR #21; private stems archive increment merged in [PR #30](https://github.com/VASEYDEV/VASEYAUDIO/pull/30); the production malware scanner fit is the current increment; broader work package remains open.** The implementation below is a bounded local-storage pipeline. This issue is complete only when the acceptance evidence and remaining production work below exist.

- Suggested issue title: `[WP-03] Private media ingestion, quarantine and preview processing`
- Phase: 1
- Dependencies: WP-01 and WP-02. Storage-provider choice blocks only production adapter configuration.
- Suggested branch: `work/wp-03-private-media-ingestion`
- Implementation paths: app/Domain/Media, app/Jobs, config/filesystems.php, database/migrations, media worker configuration and media tests.

## Problem

Uploading masters must create safe, traceable revisions and usable previews without exposing the deliverable files.

## First reviewable increment

One WAV upload through quarantine to an immutable master revision, tagged preview and waveform manifest; support archive/stem roles in a follow-up within the same contract.

## Scope

- Private upload sessions with role/type/size/hash validation and bounded retry/resume behavior.
- Quarantine, sniffing/scanning, immutable promotion and isolated ffprobe/FFmpeg jobs; reject archive traversal, symlinks, bombs and unsupported inputs.
- Technical metadata, tagged preview derivative, waveform peaks, immutable output hashes and processing progress/retry UI.
- Private local adapter for tests and replaceable S3-compatible adapter; production provider must be configured explicitly.

## Current increment — 2026-09-04

- Admin intake accepts actual WAV or PNG/JPEG uploads into private quarantine, derives size/MIME/SHA-256 on the server, and exposes processing state and explicit retry controls.
- The queued local worker verifies source identity and clean scanner evidence, validates supported audio/raster formats, and creates immutable, parent-linked revision records. A WAV run produces a preserved WAV master, an untagged delivery MP3, a full-length tagged MP3 preview and measured waveform metadata. An artwork run creates a sanitized PNG.
- A configured seller WAV tag and its expected SHA-256 are required for audio processing. An unavailable or non-clean ClamAV result prevents promotion; the runtime has no scanner bypass or synthetic tag fallback.
- Repeated requests for one source/profile reuse the same logical run and output set. Changed inputs use new uploads; changed profiles create new revisions. A published track must be unpublished before processing a replacement.
- Storefront/publication integration uses verified processing provenance and byte-integrity checks with a bounded 60-second success cache. Private masters and delivery MP3s remain unavailable through the public preview/artwork route.

See [Media operations](../media-processing.md) for setup, supported limits, failure recovery and evidence boundaries. The test suite uses synthetic media and a test-only scanner double; those tests do not establish that a deployed ClamAV installation detects malware.

## Stems archive increment — 2026-09-09

Private WAV-only ZIP intake now follows the same quarantine, processing, failure/retry and immutable revision contract. The archive validator rejects unsafe paths, links/special files, duplicates/collisions, encryption, nested/unsupported formats, CRC/length corruption and bounded expansion violations. Extracted members use generated private paths; every member is scanned and fully decoded before a rebuilt archive is scanned and promoted. Per-member hashes, integer audio metadata and clean evidence form a canonical manifest. No public URL or master association is inferred.

The regression fixtures are in `StemsArchiveTest` and `StemsFixtures`; [archive verification](../verification/stems-archives.md) records the evidence boundary. Existing WAV/artwork fingerprints and offer recording checks remain intact. No schema migration is required.

## Recording association increment — 2026-09-09

The admin Media action now requires a selected current master, an explicit same-recording confirmation and a source/session verification note. A separate immutable `stems_recordings` row records the operator, exact stems/master/preview/source IDs and canonical evidence hash. Processing parent IDs and media bytes are preserved. Draft/current-preview guards, fresh role authorization, fresh file digests, a unique stems revision and the shared track lock reject stale, cross-track, altered and conflicting submissions. Identical repeats retain the original evidence.

Offer readiness now checks this association and freezes its identity/hash in stems deliverables; publication and provisional quote selection freshly hash the associated master and retained preview even for stems-only offers. Tag-only regeneration of the same WAV source remains associated, while offers still require a new snapshot for the new preview. A new WAV source requires a new stems revision and explicit association.

Archive v2 fingerprints and enforces its duration ceiling. Explicit historical v1 validation preserves existing immutable archive evidence; unsupported versions fail closed. See [association verification](../verification/stems-recordings.md). Next ordered code work after acceptance: WP-04 typed rights and consistency fixtures.

## Remaining work within WP-03

- Real ClamAV installation, signature-update operations and known clean/detection/error acceptance evidence in the intended deployment. The [production scanner guide](../media-processing.md#production-malware-scanner) gives the configuration, measurements from ClamAV 1.5.4 and the acceptance list; the deployed host still has to pass it.
- Production media-worker isolation, denied network access, resource sizing, queue monitoring and crash/restore drills.
- Accept the association increment with CI and independent review, then record real seller-export compatibility/alignment acceptance. Archive ingestion is a bounded increment, not complete stems commerce.
- Resumable/multipart uploads and an explicitly configured private object-store adapter with retention and recovery evidence.
- Full-duration seller catalog processing, audible tag approval, real browser seek/playback and production performance evidence.

## Acceptance criteria

- [ ] Masters and stems have no public URLs; previews/artwork are explicit separate assets.
- [ ] Corrupt, wrong-type, oversized and unsafe-archive files never become ready or publishable.
- [ ] Retrying the same source/profile creates one logical output set; replacement creates a new revision.
- [ ] An editor can identify and recover a failed job without marking an unverified file ready.
- [ ] The player contract contains derivative URLs/peaks and safe metadata, with no private object key leakage.

## Verification

The first increment adds synthetic WAV/artwork integration fixtures and failure/privacy cases. See [media verification](../verification/media-pipeline.md) for exact source identity, commands, runtime versions, observed local/CI results and independent-review corrections. Synthetic archive processing/rejection checks are added by the current increment. Association regression and MySQL race cases are added by this increment. Real scanner detection, production worker isolation, seller alignment and browser/device evidence remain pending. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Pause new media jobs and retain original/private objects. Apply the additive `stems_recordings` migration; no inferred backfill occurs. For code rollback retain this table and all associated assets/offers. Its destructive `down()` is only for disposable databases, not production recovery. A correction requires a new stems revision and attestation, never an UPDATE/DELETE of recorded evidence. Reprocess derivatives under a new profile version; do not overwrite assets already sold.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-03 beginning with one bounded WAV pipeline. Read media trust boundaries and data contracts. Use immutable revision IDs and isolated processors, preserve private masters, and make processing failures visible. Never fabricate scan/hash readiness from form fields.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
