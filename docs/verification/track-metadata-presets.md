# Reusable catalog metadata presets

T12 / WP-02 authoring child, advancing FP-031. Baseline: accepted PR #89, main `9ee1841c22b67de7fff44b651820b1a32084bab2`, tree `1467c873567e9510f4a2fd723c7edc9135021a31`. Final source/run-specific outcomes belong in the integrating PR; baseline CI cannot prove this increment.

## Behavior and boundaries

Staff save named reusable artist, BPM, musical key, genre, mood, tags and description values. A separate preset resource supports create, version-checked edit and archive; successful no-ops preserve timestamps, versions and audit counts. Archived rows remain retained and cannot start a new draft.

Tracks has an explicit preset selection step, then displays the copied values in its ordinary metadata form. Staff review and can edit those values, supply title/URL and submit **Create private draft**. The copied form is independent: subsequent preset edits/archive do not silently replace its values. Every resulting track goes through `SaveTrackMetadata` and starts as a private draft. There is no preset-to-track relationship that can propagate later changes.

Preset inputs accept only the seven reusable fields plus name and the expected edit version. Title/slug, media, waveform/duration, publication, rights, offers/prices and licensing are excluded. Ordinary metadata limits and exact ordered tags are preserved. Presets never establish publishability or saleable rights.

Domain reads and mutations use the current persisted operator under a user-row lock, the existing catalog Gate and current panel MFA rule. Mutations then lock the preset row; optimistic versions reject stale edits before any save. Persistence and minimized actor/subject/version/changed-field/hash audit evidence share a transaction. Private descriptions and tags are not copied into audit context.

## Schema and recovery

Migration `2026_10_02_000033_track_metadata_presets.php` adds a separate preset table. It does not modify tracks, URLs, offers, purchased revisions or retained orders/contracts. Deploy the additive migration before exposing the new resource/actions. Revert interface code while retaining preset rows and audits; a production recovery must not drop retained authoring data. No production migration or deployment is performed by this implementation checkpoint.

## Verification contract

Local PHP 8.4.26 / SQLite integration executed 32 preset cases: 26 passed with 296 assertions; six exact MySQL-only cases skipped. Existing track metadata passed nine cases / 115 assertions; operator authority/MFA and bulk tags passed 27 cases / 269 assertions. The initial preset run identified unordered object-key comparison and Filament's lazily rendered confirmation; corrections preserve exact metadata values/tag order and the confirmation heading, add mounted confirmation-state assertions and explicitly request its full render. No production code was weakened to satisfy those tests.

All twelve touched PHP files pass syntax checks. Frontend passed 364 tests across 22 files; TypeScript/build, client-secret scan, Composer validation/audit and npm audit passed. Applicable local safeguards passed 35 partition, 22 scope, 24 database-receipt and 22 focused-selection cases. Changed local documentation links and whitespace were checked.

MySQL and native-browser execution require the hosted final gate. The local Playwright browser download produced an invalid archive, so no local native pass is claimed. Independent source review accepted the repaired current-authority fence, schema recovery and copied-draft boundaries; final acceptance must bind that review to the actual hosted tested source. Run-specific results and any superseding source are recorded in the integrating PR.

The integrating source must execute focused and full PHPUnit on MySQL and SQLite, actual Filament actions, Chromium/mobile-WebKit authoring journeys, TypeScript/build, dependency audits and applicable gate safeguards. Independent review must assess the actual tested authorization/migration source. New MySQL-only concurrency cases must be accounted for in the strict SQLite skip policy and actually execute on MySQL.

Required adversarial cases include unknown/commercial fields, metadata bounds, stale/no-op edits, audit rollback, revoked/stale staff authority and MFA, independent copied drafts, preset archive, public draft denial and preserved existing tracks. Native tests use ordinary controls, visible errors/review and reload persistence rather than injected ready catalog records.

This child does not complete T12 or WP-02. Granular helper roles, physical MFA/recovery, broader bulk catalog/license operations, track scheduling/private review and full preset/default parity remain governed by the remaining plan. Production hosting/storage, owner policies, private-source reconciliation and cutover remain separate gates.
