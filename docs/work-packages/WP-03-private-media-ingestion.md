# [WP-03] Private media ingestion, quarantine and preview processing

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

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

## Acceptance criteria

- [ ] Masters and stems have no public URLs; previews/artwork are explicit separate assets.
- [ ] Corrupt, wrong-type, oversized and unsafe-archive files never become ready or publishable.
- [ ] Retrying the same source/profile creates one logical output set; replacement creates a new revision.
- [ ] An editor can identify and recover a failed job without marking an unverified file ready.
- [ ] The player contract contains derivative URLs/peaks and safe metadata, with no private object key leakage.

## Verification

Media integration fixtures for valid audio, corrupt input, MIME mismatch and malicious archive names; resource timeout/retry and private-storage policy checks. Record worker/tool versions. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Pause new media jobs and retain original/private objects. Reprocess derivatives under a new profile version; do not overwrite assets already sold.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-03 beginning with one bounded WAV pipeline. Read media trust boundaries and data contracts. Use immutable revision IDs and isolated processors, preserve private masters, and make processing failures visible. Never fabricate scan/hash readiness from form fields.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.
