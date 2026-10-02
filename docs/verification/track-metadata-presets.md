# Reusable catalog metadata presets

T12 / WP-02 authoring child, advancing FP-031. Accepted in [PR #95](https://github.com/VASEYDEV/VASEYAUDIO/pull/95), squash merge `d57c3bb266890bc32adc1974fb84c050d848e90b`, exact tree `3561b2e416360e01eeb2340b0c8557a6df6dc785` from tested head `903d89fa1b6f64c723751000fa1ab42ef0259107`. PR #89 is historical foundation evidence. This child does not close parent T12 or establish production readiness.

## Behavior and boundaries

Staff save named reusable artist, BPM, musical key, genre, mood, tags and description values. A separate preset resource supports create, version-checked edit and archive; successful no-ops preserve timestamps, versions and audit counts. Archived rows remain retained and cannot start a new draft.

Tracks has an explicit preset selection step, then displays the copied values in its ordinary metadata form. Staff review and can edit those values, supply title/URL and submit **Create private draft**. The copied form is independent: subsequent preset edits/archive do not silently replace its values. Every resulting track goes through `SaveTrackMetadata` and starts as a private draft. There is no preset-to-track relationship that can propagate later changes.

Preset inputs accept only the seven reusable fields plus name and the expected edit version. Title/slug, media, waveform/duration, publication, rights, offers/prices and licensing are excluded. Ordinary metadata limits and exact ordered tags are preserved. Presets never establish publishability or saleable rights.

Domain reads and mutations use the current persisted operator under a user-row lock, the existing catalog Gate and current panel MFA rule. Mutations then lock the preset row; optimistic versions reject stale edits before any save. Persistence and minimized actor/subject/version/changed-field/hash audit evidence share a transaction. Private descriptions and tags are not copied into audit context.

## Schema and recovery

Migration `2026_10_02_000033_track_metadata_presets.php` adds a separate preset table. It does not modify tracks, URLs, offers, purchased revisions or retained orders/contracts. Deploy the additive migration before exposing the new resource/actions. Revert interface code while retaining preset rows and audits; a production recovery must not drop retained authoring data. No production migration or deployment is performed by this implementation checkpoint.

## Accepted current-source verification

[Foundation 36973007214](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36973007214), run 367 / attempt 1, completed successfully on actual merge `69f989ac2703098c921f250611829f5b4f500900`, retaining the exact head tree above. All twelve applicable jobs passed and documentation was correctly skipped. MySQL executed 2,379 cases / 38,606 assertions without skips; SQLite executed 2,221 cases / 27,130 assertions plus 158 exact MySQL-only policy skips. Frontend passed 364 cases across 22 files. Ordinary Chromium/mobile-WebKit passed 81 cases with the one unchanged WebKit keyboard skip; genuine related-media browsers passed two cases. Independent source review, original source-bound database artifacts, byte-exact collector output and final outer-run success were verified before expected-head squash merge. Main-push proof is separate; receipt reuse remains disabled.

Earlier native findings repaired guarded-login destinations and option whitespace, newly created rows outside the first page, and navigation racing notification delivery/dismissal. The final notification helper waits for exact component/call payloads, actual completed HTTP responses and ID-bound native Close controls while retaining all page-error assertions. The accepted run supersedes earlier diagnostic failures; no skipped/not-run case is presented as a pass.

## Historical development feedback

Local PHP 8.4.26 / SQLite integration executed 32 preset cases: 26 passed with 296 assertions; six exact MySQL-only cases skipped. Existing track metadata passed nine cases / 115 assertions; operator authority/MFA and bulk tags passed 27 cases / 269 assertions. The initial preset run identified unordered object-key comparison and Filament's lazily rendered confirmation; corrections preserve exact metadata values/tag order and the confirmation heading, add mounted confirmation-state assertions and explicitly request its full render. No production code was weakened to satisfy those tests.

All twelve touched PHP files pass syntax checks. Frontend passed 364 tests across 22 files; TypeScript/build, client-secret scan, Composer validation/audit and npm audit passed. Applicable local safeguards passed 35 partition, 22 scope, 24 database-receipt and 22 focused-selection cases. Changed local documentation links and whitespace were checked.

The initial browser-download limitation was later resolved using the exact locked official Chromium runtime. Five focused local Chromium journeys passed on the final preset tree before hosted acceptance; these local results were partial feedback. Independent review accepted the current-authority fence, schema recovery and copied-draft boundaries, then verified the actual hosted tested source above. Earlier runs and superseded source remain in PR #95 as diagnostics.

The accepted integrating source completed full PHPUnit on MySQL/SQLite, actual Filament Chromium/mobile-WebKit journeys, frontend/build/audits and applicable safeguards. Its MySQL-only concurrency cases were accounted for in the strict SQLite policy and actually executed on MySQL. Every future runtime change still needs fresh source-bound full acceptance and independent review; this accepted run cannot prove a successor.

Required adversarial cases include unknown/commercial fields, metadata bounds, stale/no-op edits, audit rollback, revoked/stale staff authority and MFA, independent copied drafts, preset archive, public draft denial and preserved existing tracks. Native tests use ordinary controls, visible errors/review and reload persistence rather than injected ready catalog records.

This child does not complete T12 or WP-02. Granular helper roles, physical MFA/recovery, broader bulk catalog/license operations, track scheduling/private review and full preset/default parity remain governed by the remaining plan. Production hosting/storage, owner policies, private-source reconciliation and cutover remain separate gates.
