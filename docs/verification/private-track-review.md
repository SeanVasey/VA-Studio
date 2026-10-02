# T12-PRIVATE-TRACK-REVIEW-01: protected operator review

October 2, 2026. Bounded read-only authoring child under T12 / WP-02, following reviewed bulk metadata edits. Integrating PR source identities, full hosted execution and independent acceptance remain required. This child does not complete T12, anonymous unlisted sharing or production launch.

## Operator contract

In **Admin → Tracks**, choose **Review track**. The close-only **Private track review** modal shows the current stored track ID, metadata revision, publication state, title, URL slug, artist, BPM, key, genre, mood, ordered tags and plain-text description. It shows the exact ordinary publication blockers, then verified artwork and tagged preview or honest unavailable placeholders. It has no editing, publication, sharing, offer or license controls. Close and reopen to read current persisted values.

Artwork loads through the existing authenticated operator media route. The native tagged-audio control uses `preload="none"` without autoplay. A media error after opening replaces its control with an unavailable message. No anonymous preview URL, master, delivery MP3, stems, original filename, private storage path, scan payload, commercial draft or raw evidence is projected.

## Read and privacy boundary

`ReadPrivateTrackReview::handle(trackId, actor)` requires its own transaction. It rejects ambient transactions before reading because a caller's old MySQL Repeatable Read snapshot could supply stale ordinary readiness children. It locks the persisted actor, uses the current locking catalog Gate and MFA reads, then locks the fresh track. All projection reads finish before the authority lock is released. It adds no source/media locks after the track, preserving the media worker's source-before-track order.

The response contains only the explicit descriptive track keys, ordinary readiness result and separate artwork/tagged-preview descriptors. Each descriptor selects the highest ready ID for its role, exactly as readiness does, and checks `VerifiedMedia`. Missing or unverifiable current assets have no URL; a damaged current revision cannot fall back to older bytes. Unexpected query/readiness failures abort rather than producing a manufactured ready result.

An available media URL names the exact selected revision. The existing operator route intentionally supports historical verified derivatives for Media Assets review; it remains unchanged. A later replacement does not retarget an already-open URL. Reopening refreshes the projection. The existing integrity cache's 60-second bound remains; this screen does not establish instantaneous tamper detection or a continuously synchronized snapshot.

Track administration, marked Livewire reads and protected operator media responses receive private/no-store, noindex/nofollow, no-referrer, nosniff and Cookie variation. The track component marks requests before authority/MFA checks. Debug, binding, middleware and query failures return a generic private error; reporting stores only exception class, and logger failure preserves that boundary. Other routes and client-supplied marker fields cannot enter this scope.

There are no catalog, media, rights, offer, publication, metadata-revision or audit writes. Existing purchased evidence remains untouched. Reverting this interface/service/privacy increment removes the review without deleting stored records; no migration or provider change is needed.

## Executed development evidence

- PHP 8.4.26 / SQLite privacy HTTP cases: 7 passed, 124 assertions. Actual authentication/authority, malformed and missing media, debug middleware failure, logger failure, rate headers, retained Livewire revocation and unrelated-route boundaries are exercised.
- Existing metadata, bulk-tag, preset and bulk-metadata authoring plus privacy cases on the integrated UI: 81 passed, 1,089 assertions with zero skips, failures or errors. Ordinary validation, actions and review lifecycles remain intact under the response protection.
- New projection/media/Filament plus privacy cases: 23 passed, 339 assertions, zero skips/failures/errors. They exercise exact projection keys, standalone transactions, current/stale/forged/deleted authority and MFA, real derived-media roles and damage, escaped/fresh mounted actions and retained catalog/commercial/file evidence. Real FFmpeg processing with the explicitly testing-only scanner proves application handling; it is not production ClamAV acceptance.
- The unexpected-readiness case uses a valid mounted snapshot and actual Livewire HTTP update to check the generic private 503 and unchanged evidence. Livewire's unit helper deliberately rethrows unexpected exceptions and disables middleware, so its thrown test error was diagnostic rather than an HTTP privacy failure.
- Complete fixed operator feedback passed on clean `4582a1b284c28028d568b9e2d4e976b3ba019b77`, tree `61939efe8987c29e8f5ba80ad61339fbd88ba4b3`: 166 reported, 151 executed, 15 exact MySQL-only skips, 1,694 assertions and zero failures/errors. Every skipped method matches the strict policy; this is focused feedback, not full merge acceptance. Later inherited preset repairs change only native paginated-record lookup and aligned suite/runner budgets, with no PHP application or test drift.
- TypeScript, Blade compilation and whitespace checks passed. Focused-selection safeguards pass 24 cases; the operator/browser registries include the new cases. No workflow or SQLite skip policy is weakened.

## Retained hosted acceptance

The ordinary native journey creates a draft through real controls, checks exact escaped metadata/ordered tags, missing-media blockers, response privacy, modal focus/layout, Close and a fresh read after ordinary editing. Its two configured engine instances remain unexecuted locally.

The existing isolated related-track journey adds private review after normal unpublication. It reuses genuine ClamAV processing evidence, verifies protected artwork/audio bytes against exact manifest hashes and sizes, decodes artwork, plays through actual native controls, checks guest redirects/public draft denial and reruns the unchanged retained-graph verifier. Its original two engine cases, fixture preparation, scanner checks and time budgets are retained. Discovery and transport mocks supply no native-media acceptance.

Full current-source MySQL/SQLite, frontend, ordinary Chromium/mobile-WebKit, genuine related-browser and aggregate checks plus independent tested-source review remain gates. Broader private/unlisted policy, track scheduling, license bulk operations, granular helper permissions/recovery and production/device acceptance remain open.
