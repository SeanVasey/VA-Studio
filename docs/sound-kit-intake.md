# Private WAV sample-kit intake

This T27 child implements a usable staff journey for **WAV sample kits**: create a private draft, upload a ZIP, inspect technical verification and retained member evidence, and retry interrupted verification. It does not complete WP10 or sound-kit commerce. MIDI, synthesizer presets and plugin-specific bank formats still need their own explicit format and inspection contracts.

## Staff journey

Open **Sound kit drafts** in the authenticated admin. Create a title, optional description and required source/provenance reference. The reference records where the samples came from; it does not approve usage or redistribution rights. Edit uses an expected draft version, so an older open form cannot replace a newer edit or upload.

**Upload kit ZIP** accepts a fresh upload through private Livewire storage. It does not accept a stored-file path. The source is retained privately, the current description and provenance are captured, and a new numbered archive revision waits for verification. Each source has its own immutable identity; no track, track master, stems association, offer, price or entitlement is created.

**Inspect revision** shows retained descriptions, source identity, attempts, failure codes and the verified member manifest. **Technically verified** means the specific archive passed the checks below. It does not mean rights approval, publication, checkout or customer delivery. No private storage key or public file URL is projected by this screen. Different samples may have different durations; they do not need recording alignment.

**Retry verification** requests another attempt for a waiting revision or an expired processing lease. Scanner or tool outages remain retryable. A terminal file failure requires a new upload; a verified revision cannot be replaced. Queue dispatch failure leaves the durable waiting revision available. Refresh and inspect status after a request: a recorded retry request alone does not prove that a worker received it.

The **200 MiB value is a technical/application ceiling, not demonstrated production HTTP admission**. For larger archives, the [resumable kit upload](kit-resumable-upload.md) uses actual kit identities and sends sequential 8 MiB parts through a separate authenticated route. The original whole-file Livewire form remains available for small files: current guarded Playwright and private-alpha PHP servers use `upload_max_filesize=9M` and `post_max_size=9M`, making that whole-file limit **below 9 MiB once multipart overhead is included**. Source/revision retention and technical verification are the same for either transport. Focused protocol/domain tests do not establish rendered-browser or production ingress acceptance; native Chromium/WebKit and real host limits remain separate gates.

## Technical profile and private evidence

The profile is `wav-sample-kit-zip-v1`, separate from the stems product role. The shared WAV ZIP engine preserves the existing archive safety mechanisms. It accepts unencrypted ZIP stored/deflated WAV members, rejects traversal, ambiguous/case-colliding names, links, special files, unsupported attributes, nested archives and auxiliary formats, and validates CRC/expanded bytes before trusting them.

Technical defaults are bounded by existing operator settings: 200 MiB source, 128 entries, 128 MiB per member, 512 MiB total expanded bytes, expansion ratio 100, 1,200 seconds per member and 360 seconds archive work. Lower shared WAV-archive ceilings apply to kits too, so kits cannot exceed the configured malware-scanner capacity. WAVs must be PCM 16/24/32-bit or floating-point 32-bit, mono/stereo, 8–192 kHz. These are implementation limits, not musical, pricing or licensing policy.

The worker snapshots and checks the exact quarantined source, scans it, validates every member with a clean scan plus bounded FFprobe and full FFmpeg decode, rebuilds a deterministic sorted archive and scans that archive. The inherited media workflow deadline remains 840 seconds; job timeout is 900 seconds and the fenced lease is 960 seconds. An expired token cannot finalize after a newer retry takes ownership. Real environments require accepted ClamAV evidence; the synthetic scan engine is accepted only in the isolated testing environment.

Each retained revision pins the source hash/size/MIME, inspection profile and hash, descriptive snapshot, uploaded/requesting actors, and archive number. A successful manifest (`wav-sample-kit-manifest-v1`) binds that identity to the output archive hash/size and sorted member names, hashes, sizes, codec, sample rate, channel count, duration and scan evidence. Canonical JSON hashing remains stable across MySQL JSON normalization. Manifest inspection verifies retained evidence and physical output bytes. SQL and model guards protect source identity and terminal outcomes; direct replacement/upsert and deletion cannot silently replace retained rows. Operational rollback retains the schema and evidence; disposable tests use the explicit test database lifecycle.

All service operations re-read staff authority and required MFA under the actor lock. Upload/retry/worker filesystem commands own their root transactions. Processing locks actor, draft and revision in that order, releases locks during scanners/tools, then reacquires current authority and exact claim ownership before recording a verified outcome.

## Recovery and storage

An exact upload retry with the same actor, expected draft version, original name, bytes and profile returns the original revision. A changed command with an old draft version is refused. Private source identities are deterministic per command, and rebuilt output identities are deterministic per revision. Existing bytes must match exactly before reuse; they are never overwritten. An exclusive local file lock serializes copies, and a bounded wait fails safely if another copy is still busy.

Sources and output archives survive failed or uncertain database commits. Repeating a failed commit reuses the same canonical files rather than generating another full copy. A successful commit whose response was lost can be inspected/replayed without a duplicate revision or verified audit. Processing scratch directories are removed separately. Unreferenced retained sources/outputs may remain after a refused or rolled-back command; automated orphan deletion is deliberately not implemented because an uncertain commit is not proof that its file is unreferenced. Reconciliation/retention policy and non-local object storage remain separate work.

## Next product integration

The existing `Offer`, `OfferSnapshot` and `DeliveryAssets` are explicitly track-owned and use track `MediaAsset` identities and track license roles. A kit must not be disguised as a track or `stems_zip` to pass those gates.

The next coherent commerce child needs a versioned product-target/asset adapter for the existing quote → prepared order → verified payment → grant/contract → authorized delivery pipeline. It must bind a selected, technically verified kit revision and manifest to staff-entered integer prices/currency and explicitly authored, reviewed, versioned sample-use terms. Sample redistribution, permitted uses and source rights require actual decisions; a provenance note or existing track license cannot supply them implicitly. Purchased snapshots must retain the exact kit archive version, old track snapshots/contracts must remain byte-compatible, and existing customer ownership and short-lived authorized download controls should serve the purchased archive. Reuse the existing payment/finalization engine; do not introduce another charge or entitlement engine.

Public kit listings, approved demo/artwork, product-specific rights approval, those commerce adapters, actual preset/MIDI profiles and native browser acceptance remain open. This private technical intake neither changes launch gates nor drops the broader T27 requirement.

## Verification

The shared parser extraction passed the unchanged stems/archive/budget/private-root regressions: 58 tests, 589 assertions. Independent review approved its three-file commit `ad05d8cf32165cf200dc006e8842c41a4ae69e6a` before kit domain work.

Runtime source frozen at `9cd5db9f30461821d52c2f869dcc0bcb931d377f` passed:

- PHP 8.4.26/SQLite: `php vendor/bin/phpunit tests/Feature/SoundKitIntakeTest.php tests/Feature/SoundKitRecoveryTest.php tests/Feature/SoundKitMigrationTest.php tests/Feature/SoundKitDraftAdminTest.php --stop-on-error --stop-on-failure` — **41 tests, 298 assertions**, no failures/skips, 9.567 seconds.
- Native MySQL 8.4.11 with the same four files plus `tests/Feature/SoundKitConcurrencyTest.php` — **50 tests, 660 assertions**, no failures/skips, 176.318 seconds. The isolated local wrapper retained normal durability; the nine concurrency cases used independent PHP processes and observed exact InnoDB `PRIMARY` record waits before release. They cover duplicate intake/processing, six current-MFA admission operations and MFA withdrawal at finalization after real audio work.
- Pint passed for the 19 new PHP files; diff checks passed. Actual route discovery found only the authenticated, MFA-protected `admin/sound-kit-drafts` resource page.

The eight Filament tests invoke real create/edit/upload/inspect/retry actions and domain processing. Archive tests use real bounded audio probing/decoding and deterministic repacking with an explicitly synthetic scanner; they do not claim production ClamAV deployment, native browser acceptance or full-size HTTP admission. Independent domain/migration/processing review resolved the MySQL collation gap with byte-sensitive immutable comparisons, and separate UI review assessed its author-owned files. Shared CI registration/integration remains the integrating agent's responsibility.
