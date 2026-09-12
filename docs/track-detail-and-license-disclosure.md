# Track detail and published license disclosure

WP-05 adds an individual listening and licensing page at the existing `/tracks/{slug}` URL. Artwork, artist, tempo, key, duration, tags and waveform come from the eligible public catalog. License cards show the exact current published revision's name, price, currency and files. Usage summaries expand independently; **Read terms & choose** opens that offer with its full published source.

## Disclosure contract

`GET /tracks/{slug}/offers/{revision}/license` returns JSON with `offerId`, `offerRevisionId`, `licenseVersionId`, `name`, `version`, `type`, `features`, `deliverableRoles` and `termsText`. The response is private/no-store and rate limited. The endpoint reuses complete publication readiness before accepting a current active revision belonging to that track. Withdrawn tracks, inactive offers, expired licenses, changed evidence, superseded revisions and missing verified media fail with 404.

The selected immutable `OfferRevision.snapshot.license` supplies the source and structured terms. V1 literal sources and v2–v4 renderer semantics are retained. V4 includes every captured policy's full text, key, version and hash through the already reviewed `policy_texts` source variable. Editable offer drafts cannot change this response. The public allowlist omits internal review/approval fields, rights evidence, private asset hashes and storage paths. Bulk catalog and selection responses add only the disclosure URL; full policy bodies load on demand.

The client validates all three identities, accepts only same-origin addresses, cancels obsolete requests and ignores late responses after selection changes. Loading, failure and retry remain visible. React renders source as text, including markup-like characters. Missing terms never become a generic license. Saving a cart selection is not assent or a grant; buyer-specific contracts and accepted disclosure evidence follow in WP-07/WP-08.

## Navigation and playback

Detail links retain the submitted catalog filters and cursor. **Back to results** restores that result URL through Inertia. Heading focus follows navigation. The same module owns a single native audio element across pages; playback, seek position and user volume survive navigation. Selecting a new preview URL for the same track replaces the source instead of pausing the outdated recording. Missing previews remain unavailable; waveform data is never synthesized in production.

The layout uses the active theme tokens, typography and original identity assets. License disclosures have keyboard-accessible scroll regions, native dialog dismissal and restored opener focus. Reduced-motion styles apply to the added UI.

## Verification and limits

- `tests/Feature/PublicLicenseDisclosureTest.php`: current eligibility, exact v1–v4 text, complete v4 policy bodies, draft isolation, successor URLs, expiry, cross-track denial and private-field exclusion through the actual publication services.
- `tests/frontend/license-disclosure.test.tsx`, `track-detail.test.tsx` and `audio.test.tsx`: stale replies, mismatched identities, retry, inert text, selected offer/cart identity and native-source replacement. Media methods in unit tests are mocked; these are not decoding evidence.
- `tests/browser/storefront.spec.ts`: Chromium desktop and WebKit mobile use the real production bundle, Inertia, native dialog and native audio decoding/seeking. Test-owned HTTP fixtures supply synthetic public DTOs and a generated PCM WAV. They do not bypass application readiness or create production records. These scenarios establish browser UI behavior, not scanner acceptance, published media HTTP delivery, physical iOS playback or end-to-end commerce. Existing operator browser tests continue against a real isolated Laravel/SQLite application.

Local implementation checks passed 50 frontend tests and TypeScript/production build. PHP/Composer are unavailable in this workspace, and the interactive preview browser cannot reach its local server. The implementing PR records the actual final candidate's MySQL, SQLite and desktop/mobile CI outcomes and independent review; test definitions alone are not passes. Production media, physical device/accessibility and performance acceptance remain tracked in WP-03/WP-05/WP-13.

Revert this application change to remove the detail/disclosure UI; no schema or retained evidence changes are required. After this increment is verified, resume WP-06 authoritative pricing/tax-policy envelopes and exclusive inventory in the original order. Unknown policy remains unknown until supplied; provider connections do not supply those application rules.
