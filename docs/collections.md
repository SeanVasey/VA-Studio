# Collections

BL-54 (FP-006), 2026-11-11. Public collections are **browse groupings of individually licensed tracks**: a staff-ordered list of existing tracks with a title and a description. They are not products. There is no collection price, offer, license, cart line, grant or download, and no bundle (automatic bundles, FP-066, stay a separate default-off lane). Every track is still licensed, sold and delivered through its own track page. This is a foundation increment, not accepted parity.

Not to be confused with Sean's own released music (`/albums`, `docs/albums.md`), which has no tracks in the licensable catalog, nor with the private collection and album drafts (`product_drafts`, immutable composition evidence that never authorizes publication). Collections do not read or write those drafts.

## What FP-006 already had, and what this added

| Piece | Before | Now |
| --- | --- | --- |
| Private collection/album drafts with immutable ordered track snapshots (`ProductDrafts`, `ProductDraftResource`) | exists | unchanged |
| Albums page for Sean's own releases (`app/Domain/Albums`, `AlbumReleaseResource`, `/albums`) | exists (BL-67); different concept | unchanged |
| Public collection page, index, sitemap, navigation | missing | `/collections`, `/collections/{slug}` |
| Staff ordering and publication of a public grouping | missing | `TrackCollections` writer and `TrackCollectionResource` |
| Hiding unpublished members | missing | read-time filter through `PublicCatalog::browseCards` |
| Catalog filter, per-track "in collections" link, collection artwork, structured data | missing | still missing (listed under Open) |

## Off by default

`config/collections.php` holds `enabled` as a code literal `false`, not an environment key. Everything public goes through `App\Domain\Collections\CollectionsPolicy::enabled()`, true only for the exact boolean `true`. While false, `/collections` and `/collections/{slug}` answer 404 (even for published collections), and the navigation item and sitemap entries are absent. Staff can prepare and publish collections while it is off. Publication of the member tracks is a separate, stronger gate: switching the pages on never exposes a track that is not already public.

## Data model

Migration `2026_11_11_000200_track_collections.php`, both tables `NOT_CUSTOMER_DATA` in `RetentionSchedule`:

| Table | Holds |
| --- | --- |
| `track_collections` | title, slug (unique, ASCII), optional plain-text description, published flag, revision counter, publication time and publisher |
| `track_collection_items` | ordered references to `tracks` (position; unique per collection and per track; foreign keys restrict) |

Collections are never deleted (unpublish instead). Items reference tracks, never copy their titles, so a renamed track shows its current title.

## Publication rules

`App\Domain\Collections\TrackCollections` is the only writer. Each command runs in one transaction that locks the acting staff user (`administer-catalog` and enrolled MFA) and then the collection, takes ascending locks on the referenced tracks, checks the expected revision and records one audit event (`collections.collection.created`, `.updated`, `.published`, `.unpublished`) with before/after hashes. A collection can be published only with at least one track, and a published collection cannot be saved empty. Up to 60 tracks, each once; plain-text title (160) and description (2000); slug lowercase letters, numbers and single hyphens.

A collection may reference draft or private tracks while it is being assembled. That is never visible to the public.

## What the public sees

`CollectionPresentation` asks `PublicCatalog::browseCards()` for the members. A track is shown only if it is **published** (not draft, scheduled, private or unlisted) and passes the same eligibility check as the catalog (publication readiness plus at least one available offer). The card has title, artist, tempo, key, genre, length, artwork and the path of the track's own page; it carries no offer, price or license. Order is the staff order.

- `/collections`: published collections that still have at least one listed track, newest first, at most 50.
- `/collections/{slug}`: title, description, the listed tracks with a "License options" link to each track page. Unpublished, unknown, and empty (no listed track) collections answer 404, as does the flag being off.
- If every member is later withdrawn the collection silently disappears (page, index and sitemap) without being unpublished; republishing the tracks brings it back.
- Navigation item "Collections" is appended to public pages only while the flag is on (`EditorialContent::chrome`, like Albums); staff previews of a saved site release never show it.
- Sitemap: `/collections` and each visible collection, production only, while the flag is on and at least one is visible.
- Metadata: canonical URL from the configured site URL, `index, follow` only in production.
- Visual rules (docs/brand/storefront-visual-review.md): Bebas uppercase signage for the collection names and section title, Noto poster for the page `h1`, collection cards alternating canvas and surface, tracks on a surface band, tokens only.

## Open

- Sean's go-ahead to switch `collections.enabled` on, after he has built and published real collections.
- A catalog filter by collection, a "part of" back-link on the track page, collection artwork and `CollectionPage` structured data were not built (they touch the shared storefront and need a design decision).
- Automatic bundles and any collection-level price remain FP-066 and Sean's decision.

## Tests

`tests/Feature/TrackCollectionsTest.php` (SQLite): flag off and exact-true gating, order, publication filtering (draft, withdrawn, unlisted, no-offer tracks), 404 for unpublished and empty collections, no price/offer/bundle keys or amounts in the payload, sitemap, metadata, writer rules and revisions, MFA, the Filament editor and retention classification. `tests/frontend/collections.test.tsx` (vitest): index, empty state and detail, plain-text rendering, no purchase control.
