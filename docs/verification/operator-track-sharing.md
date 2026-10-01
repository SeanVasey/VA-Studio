# T17 operator public sharing candidate

Implemented against base `8f6a0c96774acc4c56005a4597458bf1fe2b86fc` (tree `fa4a7e4`). This is an isolated sharing child, not acceptance of T17, WP-09 or the release. The preceding inquiry-notification and native-player candidates are separate evidence boundaries.

## Owned implementation

- `app/Domain/Catalog/ReadTrackSharing.php`: fresh persisted staff/MFA and public catalog eligibility, canonical slug and root-origin validation, minimal read-only descriptor.
- `app/Filament/Resources/TrackResource.php`: replace the status-only open-link action with a freshly rendered sharing dialog.
- `resources/views/filament/catalog/track-sharing.blade.php` and `resources/js/admin/operator-sharing.js`: escaped public fields, fixed embed text, semantic copy/select controls and guarded clipboard lifecycle.
- `tests/Feature/ReadTrackSharingTest.php`, `tests/frontend/operator-sharing.test.mjs`, its Vitest wrapper, and `tests/browser-related/operator-sharing.spec.ts`: dedicated backend, client-state and isolated operator journey coverage.
- [Operator contract](../track-sharing.md#operator-copy-controls--t17-child-october-1-2026).

The core implementation owns the ten paths above. The separately owned native selection/discovery commit adds two paths (`playwright.related.config.ts` and `scripts/ci/test-related-browser-stage.py`), making twelve paths in the integrated candidate. No migration, customer payload, provider activation, inquiry schema, scanner preparer, native-player code, CI workflow, root task register or development-order change is part of this child. The copied iframe uses the existing public embed routes and their established CSP and media authorization.

## Executed local checks

On October 1, 2026, Node `v24.19.0` executed these checks successfully:

```sh
node --check resources/js/admin/operator-sharing.js
node --check tests/frontend/operator-sharing.test.ts
node --check tests/browser-related/operator-sharing.spec.ts
node --test tests/frontend/operator-sharing.test.mjs
git diff --check
```

The exact standalone Alpine state expression passed **15 tests**, with zero failures or skips. Cases cover pending/fulfilled/rejected/non-promise writes, unavailable/insecure/throwing APIs, exact readonly values, duplicate-click serialization, manual selection, destruction, modal closure and stale/disconnected callbacks. These tests evaluate the real helper against bounded DOM/API doubles; they do not execute Blade, Filament, Alpine integration, a browser or actual OS clipboard permissions. Node syntax checks do not establish TypeScript type checking or Playwright execution.

PHP, Composer/vendor dependencies and the project's frontend dependencies are unavailable locally. PHP syntax/Pint, feature/Livewire tests, Vitest integration, TypeScript/Vite and native browser execution have not been performed here. No prior CI receipt substitutes for this candidate's tests.

The separately owned native-stage guard was attempted locally. Its three standalone wrapper/installer refusal methods passed. The full command encountered five prerequisite failures (four marker subcases and one discovery case), each reporting `MODULE_NOT_FOUND` for the absent locked `node_modules/@playwright/test/cli.js`. Python compilation and whitespace checks passed. These prerequisite failures do not prove the intended runtime marker/discovery behavior, which still requires the locked dependencies.

## Required focused and integrating evidence

```sh
php vendor/bin/phpunit tests/Feature/ReadTrackSharingTest.php tests/Feature/PublicTrackEmbedTest.php tests/Feature/TrackMetadataTest.php --fail-on-phpunit-warning --display-warnings
php vendor/bin/pint --test app/Domain/Catalog/ReadTrackSharing.php app/Filament/Resources/TrackResource.php tests/Feature/ReadTrackSharingTest.php
npm run test -- tests/frontend/operator-sharing.test.ts
npm run typecheck
npm run build
```

Run the backend cases on **both MySQL and SQLite**. They cover minimal projection/escaping, hostile Host/query isolation, unsafe origins, malformed genuinely published legacy slugs, draft/unknown/stale identity, withdrawal, offers, rights, missing/corrupt media, superseded-media republication, sold exclusive scope, unchanged retained rows, revoked staff/email verification, removed MFA, default/secondary caller-owned transaction refusal and actual mounted-modal refresh.

The integrated native selector chooses exactly `editorial-related-tracks.spec.ts` and `operator-sharing.spec.ts`, with a strict discovery guard in the separately owned wiring commit `1df3427a501ccda2f64d986e998d271a93787434`. The wrapper, preparer, scanner, projects and resource limits remain unchanged; the new sharing case retains the existing 60-second default. The runtime-discovery/startup guard attempt stopped at the missing locked CLI prerequisite; both native journeys remain unexecuted locally. Independent review and actual exact-source native acceptance remain required; an unexecuted spec is not acceptance. No default empty-catalog fixture or scanner bypass is introduced.

That browser case opens the actual Filament action for a prepared currently public track, checks actual track/embed GETs, inert embed text, focus/reflow, native clipboard outcome and native manual text selection, then verifies retained ready-track evidence is unchanged. The no-external-request observation starts after the existing admin shell and server-confirmed table search have loaded, immediately before opening Share, and covers the sharing dialog/copy/manual flow. Page errors are observed throughout login and sharing. Chromium receives ordinary clipboard permissions and must round-trip the real public link and iframe text. WebKit retains its natural successful-write or manual-fallback outcome; no clipboard mock, probe, method replacement/removal or application-state injection is used. Pending, rejected and missing-API state cases are separately covered by the local helper tests. Real-device permissions and iOS/Safari hardware behavior remain device checks.

The existing editorial journey intentionally withdraws the first fixture. Before any fixture URL request, sharing validates both positive safe-integer track IDs, canonical slugs, exact fixed-route hrefs and bounded titles. It does not republish a fixture: it uses the long-title first track when still public, otherwise the unchanged second track, and invokes the strict existing fixture verifier with the observed `published`/`withdrawn` first-track state both before and after the sharing flow. A server-confirmed table-search response and visible Search indicator precede opening the modal, avoiding debounce races. The copy fields exercise long-text wrapping even when the selected second track has a shorter title. This case performs no publication, playback, messaging or external-provider action.

Record the integrating commit/tree, exact executed counts, independent source/privacy review, focused proof and full required CI results before promoting this child. Retain the provider, privacy/retention, support and launch boundaries in the existing decision register; this child supplies no new policy approval.
