# Public track sharing

WP-05 increment, 2026-09-09. The existing home and `/tracks/{slug}` routes now supply public page metadata in the initial HTML and during Inertia navigation. No new route, table, provider, publication policy or checkout capability is introduced.

## Result

- Home identifies VASEY.AUDIO and uses the existing approved studio hero artwork.
- A track URL identifies that exact eligible recording by title and artist, with a bounded description built from public genre, BPM and key fields. Its image is the current verified artwork served through `/media/{id}`.
- Canonical and Open Graph URLs use `APP_URL` plus the named route path. Tracking parameters and the request Host do not supply canonical identity. Configure `APP_URL` to the actual root site origin, including the port for local development, before sharing or deploying.
- Open Graph and large-image card tags include titles, descriptions and image alternatives. No price, completed sale, legal agreement, private audio URL or download entitlement is advertised by this metadata.
- Non-production environments emit `noindex, nofollow`; production emits `index, follow`. This is a crawler instruction, not access control or an assertion of launch readiness.

Publication eligibility remains the existing catalog check: a track must be published with current rights, reviewed offers and verified media. Missing, draft, withdrawn or newly ineligible tracks return 404 before metadata is produced. The public artwork route rechecks eligibility when the image is requested. External services may retain their own cached previews; withdrawing a track cannot erase an image already cached by a third party.

## Rendering contract

`StorefrontMetadata` consumes only the already filtered public catalog projection. It returns `title`, `description`, `canonicalUrl`, `imageUrl`, `imageAlt`, `type` (`website` or `music.song`) and `robots`. Those fields are an additive `metadata` page prop; `/api/catalog` retains its existing schema. No user-supplied HTML, query override or new network request is needed to create metadata.

The controller passes the same values to the Blade root and React. Blade escapes every value in `<x-inertia::head>` fallback content, so crawlers receive metadata without JavaScript or a new SSR service. React's `MetadataHead` uses corresponding `head-key` values; the existing Inertia head manager adopts the server's `data-inertia` tags and replaces them during navigation, including returning home. The title is already branded and is not suffixed a second time. The isolated design preview does not install production metadata.

This uses the repository's pinned Inertia Laravel 3.3.3 and React/core 3.7.0 behavior, verified against [the head component](https://github.com/inertiajs/inertia-laravel/blob/42dccee77d7df6b2e152965434a89cafc35a2961/src/View/Components/Head.php), [the head manager](https://github.com/inertiajs/inertia/blob/v3.7.0/packages/core/src/head.ts) and [Inertia's server-head documentation](https://inertiajs.com/docs/v3/the-basics/title-and-meta#server-side-head-elements). The initial page still sends the full eligible catalog; this change does not claim pagination, body SSR or a standalone track-detail page.

## Verification and operations

`StorefrontMetadataTest` checks actual HTML and Inertia responses, selected-track identity, retrievable artwork, private-data exclusion, Host/query isolation, escaping, draft/withdrawal/rights-hold rejection and missing artwork. `metadata.test.tsx` uses the real Inertia app/head manager and client-side navigation to check fallback adoption, track changes, return home, duplicate tags and inert text. Existing catalog, player, license and quote tests remain required regressions.

The implementing PR records the tested commit and actual CI results. Local PHP and package caches were unavailable after workspace maintenance; no local PHP, frontend or browser pass is claimed. The local diff/metadata-key checks were performed. Social-platform cache refresh and native mobile share-sheet previews require a deployed candidate and remain untested.

No database migration, data backfill, credentials, recurring service or external write is needed. Revert the application/view changes to remove the metadata; retained catalog, licenses, quotes and private media remain intact. Existing rate limits and catalog integrity-cache behavior apply. Checkout remains disabled.

Next within WP-05: bounded server-side discovery/pagination with selection reconciliation, then a dedicated track-detail view and device playback/navigation verification. Payment work still needs the payable quote, tax, assent, order and fulfillment contracts described in WP-06 through WP-08.

## Published URL continuity

The [catalog metadata command](catalog-administration.md) permits slug changes only before first publication. The saved `published_slug` and database guards keep that identity after unpublishing, including records with older publication evidence. Unpublished links return 404; republishing restores the same URL. Renames with redirects require a future explicit continuity workflow.

## Structured data (JSON-LD)

Added 2026-10-24 (lane T36, FP-014). `PublicStructuredData` adds one `<script type="application/ld+json" data-inertia="structured-data">` block, carried as `metadata.structuredData`, to three public page kinds:

| Page | schema.org types | Source |
| --- | --- | --- |
| Home | `Organization` (VASEY.AUDIO, parent organization Vasey Multimedia, the existing `/brand/vasey-audio-logo.png`) and `WebSite` | Brand constants and the page metadata description |
| Public track | `MusicRecording` (title, artist as `MusicGroup`, genre, canonical URL, verified artwork) and a two-item `BreadcrumbList` | The filtered public catalog projection and the page metadata |
| Blog entry | `BlogPosting` (headline, description, canonical URL, publisher) and a three-item `BreadcrumbList` | The active verified site release |

The exclusion rule is the sitemap's: nothing is emitted outside `production`, where the synthetic rehearsal catalog is refused; drafts, unpublished, withdrawn and ineligible tracks already answer 404 before metadata is built; tracks carrying the synthetic rehearsal marker, title prefix or artist are skipped as well; and the staff release preview never asks for structured data. About, contact, the blog and video listings and video entries carry none, because no truthful type with the required fields (for example a video upload date) is available. No `Offer`, price or availability is emitted: public checkout is disabled, so an offer would claim something that is not true. Durations are omitted because the stored duration is the tagged preview's, not the recording's.

The JSON is encoded with `JSON_HEX_TAG | JSON_HEX_AMP` and default Unicode escaping, so it is ASCII and every `<`, `>` and `&` is a `\u` escape; the encoder refuses output that breaks this. Blade prints it raw inside the script element and React's `MetadataHead` passes the same string through the Inertia head manager (which writes script children raw), dropping any value that contains `<`, `>` or `&`. Inertia adopts the server block on load, replaces it during navigation and removes it on pages without one. `PublicStructuredDataTest` and `tests/frontend/structured-data.test.tsx` cover shape, escaping, navigation and the exclusions.
