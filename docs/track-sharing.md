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

## Operator copy controls — T17 child, October 1, 2026

The Tracks **Share** action opens a read-only **Share public track** dialog with the public track URL, preview embed URL and fixed iframe code for the existing [tagged-preview embed](verification/public-track-embed.md). **Copy link** and **Copy embed** announce success only after the Clipboard API promise fulfills. An unavailable API, insecure context or permission failure offers manual copying through the visible readonly fields; **Select link** and **Select embed code** focus and select their text without invoking the clipboard. A closed or replaced dialog ignores late clipboard callbacks. No outbound message or third-party request is sent.

Every server render asks `ReadTrackSharing` for a fresh descriptor. The service reloads the persisted operator and track, checks verified catalog administration and required MFA, and invokes the ordinary `PublicCatalog::preview()` boundary. A status badge alone cannot authorize sharing. Missing, draft, withdrawn, rights-held, unavailable-media or sold-scope tracks produce the same fixed unavailable dialog. Canonical lowercase ASCII slugs are mandatory even for legacy model-created records. No private review, release, order, customer, contract, asset path or download token is projected.

Copy destinations use fixed named-route paths and the configured `APP_URL`, never the request Host, query parameters or a table row's retained slug. This descriptor requires a root HTTP(S) origin: an ordinary local HTTP origin and one trailing slash are supported; credentials, controls, backslashes, query strings, fragments and path suffixes are refused. The iframe has a fixed title, lazy loading and no-referrer behavior. It does not render inside the dialog or introduce autoplay, scripts, analytics or a new media-serving policy.

The read owns its database transaction and refuses caller-owned transactions that could retain an older MySQL authority snapshot. Current operator and track identities are locked before the normal catalog reads. Rights, offer, inventory and media checks reuse the existing public observation; destinations recheck eligibility on subsequent requests. Copied text and previously buffered bytes cannot be revoked, and the existing media-integrity cache still applies. There is no claim of universal serialization across all public catalog changes.

This child creates no schema, publication, grant, retention decision, provider binding or production activation. Removing the UI/service removes these controls without changing retained business evidence. It advances FP-016/FP-039 within T17; buyer messaging, order-aware support, private attachments, draft-review sharing, real-device clipboard acceptance and broader WP-09 completion remain open. [Verification and unexecuted gates](verification/operator-track-sharing.md) belong to this candidate rather than the preceding inquiry or native-player proofs.
