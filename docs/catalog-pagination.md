# Catalog browsing and saved selections

WP-05 increment, 2026-09-09. See [development order](development-order.md) for the current handoff and all unfinished work packages.

## Public contract

`GET /`, `GET /api/catalog`, and `GET /tracks/{slug}` accept `q` (at most 100 characters), `genre` (80 characters), `sort` (`featured`, `tempo`, `title`) and an opaque `cursor`. `featured` retains the existing URL value but means newest publication; the UI names it “Newest releases.” Search terms match title, artist, genre, mood, key and tags on the server; SQL wildcard characters are literal. Search is submitted by Enter or Search; genre/sort changes start a new result sequence.

Each response contains at most 12 current eligible catalog tracks. `catalogPage` carries the normalized filters, previous/next URLs, current/restart URLs and whether a cursor was used. IDs are serialized as strings to match the typed frontend and survive saved-selection round trips. Money stays in integer minor units. No complete-catalog or hidden-record count is returned. Genre shortcuts reflect only the current public page; text search covers the published catalog.

The query hydrates at most 49 track candidates, checks at most 48 for existing publication readiness and stops when 12 eligible tracks have been found. A keyset boundary includes a unique ID tie-breaker. Previous pages reverse the seek direction and restore the display order. Cursor positions are authenticated and encrypted, bound to the normalized filters, and cannot disclose the identity of a skipped record. Forged/stale-key/cross-filter tokens produce a validation error. Restarting the query recovers. There is no page-size or offset override.

An eligibility hold may leave a short or empty page with a Next link. The interface explicitly offers continuation; it does not imply the entire catalog is empty. Mutation of metadata/sort fields between requests can move a track between pages; this is live browsing, not a transactional inventory snapshot. Index tuning, a scalable search service/facet index, high-volume load evidence and removal of repeated readiness queries remain performance follow-ups.

A direct track URL resolves the selected track separately through the same readiness check. Its safe public projection and license tiers are included even when it is absent from the current page. Existing canonical/share metadata remains query-free. The interim selected-record row is not completion of the dedicated track-detail layout.

## Cart and player behavior

`POST /catalog/selections` accepts up to ten distinct positive safe-integer `trackIds`, retains web CSRF protection, is rate-limited, and returns only current eligible public projections with `private, no-store`. It creates no quote or order and returns no private asset path, authored legal source or review evidence. Missing and private IDs return the same absence.

Browser storage retains only the four selected identities: track, offer, offer revision and license version. Reloading or changing catalog pages resolves saved track IDs separately instead of deleting off-page choices. Displayed price/files come from current server records. A confirmed withdrawal or revision change removes that selection with a notice; network or malformed-response failures preserve its identities and provide Retry. Old in-flight responses are ignored after navigation. Quote review waits for reconciliation. The ten-track cart limit matches the existing quote contract.

The shared audio owner is not recreated or stopped by catalog navigation. Its source/time survive a page change; the queue reflects the current page. Licensing a playing track outside the current page/cart navigates to its direct URL. Cross-page queue history, standalone detail composition and real device media behavior remain subsequent WP-05 work.

## Verification and limits

Regression coverage includes forward/back traversal with tied order values, direct links outside the page, bounded empty eligibility batches, literal search and server sorting, invalid/cross-filter cursors, private/withdrawn/missing-media selections, request bounds, off-page cart restoration/change, stale revision handling, retry and response races, URL/filter navigation, and persistent audio identity/time.

Run `php artisan test` against MySQL 8.4 and `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`; run `npm test` and `npm run build`. Existing dependency checks remain required. The implementation PR records actual run IDs, tested head and results. This workspace has no PHP/Composer or installed project frontend dependencies, so runtime acceptance is taken from GitHub CI, not inferred from source inspection. Browser/device visual checks, performance budgets, actual seller audio and production release acceptance are not established by DOM mocks.

No migration, dependency upgrade or provider configuration is included. Rollback restores the previous catalog/controller/frontend together; cart storage keeps the same version and identity-only shape. Reverting to the old full-catalog UI regresses bounded browsing but does not alter purchase evidence.

Implementation references: [Laravel pagination](https://laravel.com/docs/13.x/pagination) for cursor ordering and traversal constraints; [Inertia manual visits](https://inertiajs.com/docs/v3/the-basics/manual-visits) for preserved state/scroll and visit callbacks. Reviewed 2026-09-09.
