# [WP-03] Private media ingestion, quarantine and preview processing

Status: **First WAV/artwork increment implemented in PR #21; broader work package remains open.** The implementation below is a bounded local-storage pipeline. This issue is complete only when the acceptance evidence and remaining production work below exist.

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

## Remaining work within WP-03

- Real ClamAV installation, signature-update operations and known clean/detection/error acceptance evidence in the intended deployment.
- Production media-worker isolation, denied network access, resource sizing, queue monitoring and crash/restore drills.
- Stems/ZIP ingestion, archive traversal/symlink/bomb rejection fixtures and safe extraction. Unsupported archives currently remain unavailable; no extraction pipeline is implemented.
- Resumable/multipart uploads and an explicitly configured private object-store adapter with retention and recovery evidence.
- Full-duration seller catalog processing, audible tag approval, real browser seek/playback and production performance evidence.

## Acceptance criteria

- [ ] Masters and stems have no public URLs; previews/artwork are explicit separate assets.
- [ ] Corrupt, wrong-type, oversized and unsafe-archive files never become ready or publishable.
- [ ] Retrying the same source/profile creates one logical output set; replacement creates a new revision.
- [ ] An editor can identify and recover a failed job without marking an unverified file ready.
- [ ] The player contract contains derivative URLs/peaks and safe metadata, with no private object key leakage.

## Verification

The first increment adds synthetic WAV/artwork integration fixtures and failure/privacy cases. See [media verification](../verification/media-pipeline.md) for exact source identity, commands, runtime versions, observed local/CI results and independent-review corrections. Archive extraction, real scanner detection, production worker isolation and browser/device evidence remain pending. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Pause new media jobs and retain original/private objects. Reprocess derivatives under a new profile version; do not overwrite assets already sold.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-03 beginning with one bounded WAV pipeline. Read media trust boundaries and data contracts. Use immutable revision IDs and isolated processors, preserve private masters, and make processing failures visible. Never fabricate scan/hash readiness from form fields.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
