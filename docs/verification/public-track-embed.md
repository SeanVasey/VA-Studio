# Public track preview embed

This bounded T17 increment supplies a small first-party player for a currently public track. It does not complete the separate buyer messaging, order-aware support or attachment work in T17.

## Sharing a preview

Use the published track's existing slug in `/embed/tracks/{slug}`. For example, replace the example origin and slug below with the configured store origin and an actual published track:

```html
<iframe
  src="https://YOUR-STORE-ORIGIN/embed/tracks/YOUR-PUBLISHED-TRACK-SLUG"
  title="VASEY.AUDIO tagged track preview"
  loading="lazy"
  referrerpolicy="no-referrer"
  style="width:100%;max-width:720px;height:300px;border:0"
></iframe>
```

This is an example, not a published catalog item. The embedded page shows the track's title and artist, native audio controls and an **Open track on VASEY.AUDIO** link. That link opens a separate tab and uses `APP_URL`, not the visitor's Host header or query parameters. The view does not load the React storefront, cart, pricing, licenses, artwork, a third-party player, analytics or any JavaScript. Existing storefront share controls still copy the ordinary track link; a copy-embed interface is not claimed by this increment.

Audio starts only through the browser's controls: no autoplay attribute or script requests playback, the response disables the autoplay permission, and `preload="none"` requests no advance media loading. Preload is a browser hint, not a guarantee that every browser sends no metadata request. Keyboard, seeking, pause and volume use native platform controls. The store link remains available if playback is unsupported or fails. Very long titles may require the embedding host to increase the frame height.

## Eligibility and privacy boundary

Both page and audio routes use the same current `PublicCatalog` eligibility as track browsing: published status, metadata, current verified rights and license/offer evidence, matching verified media, and current inventory availability. The HTTP view receives only explicit title/artist/link values. It receives no raw model, private path, source evidence, license terms, price, order, grant or customer payload.

Audio URLs bind the slug to the exact current tagged-preview ID at `/embed/tracks/{slug}/preview/{assetId}`. Each GET, HEAD or range request re-evaluates eligibility and rejects masters, delivery MP3s, artwork, cross-track IDs and superseded previews. `VerifiedMedia` retains its existing private path/provenance and bounded integrity-cache checks. The byte hash cache lasts at most 60 seconds; this increment does not claim immediate detection of same-size on-disk tampering inside that existing verification window. Binary streaming and ranges use the existing Symfony file-response implementation; this is not a new range parser or download entitlement.

The new routes sit outside Laravel's web group: they neither require nor establish authentication/session state and do not set session/CSRF cookies or emit Inertia props. A browser may still send existing first-party cookies; the embed does not use them. Ordinary server access logs and IP-based rate limits remain. The page budget is 120 requests per minute per IP and the independent audio budget is 240. Both named limiters register in `AppServiceProvider::boot()`, so they remain available after `route:cache`; registering them only in a route file would leave cached requests without their limiter. No visitor analytics or stored player consent is added.

Responses are `no-store`, `noindex, nofollow`, `nosniff`, and `no-referrer`. The route-specific CSP denies content by default, allows only same-origin style/media resources, disables forms/base URLs, and explicitly allows HTTP(S) pages to frame this public surface. No X-Frame-Options restriction is applied here. Script execution, nested frames and external resources remain denied. This framing policy is limited to the public embed namespace; it does not authorize framing account, admin, purchase or private preview pages. Hosting must preserve these headers and avoid adding a conflicting frame restriction to these routes.

Withdrawing a track, removing an eligible offer, imposing a rights hold, selling its governed exclusive scope, or losing verified required media makes subsequent embed/audio requests unavailable. Missing, invalid and private content has a generic no-store response, including middleware/debug failures. Bytes already received or buffered by a visitor cannot be revoked. The existing general `/media/{asset}` route and its cache policy are unchanged.

## Verification

```sh
php vendor/bin/phpunit tests/Feature/PublicTrackEmbedTest.php tests/Feature/PublicTrackEmbedRouteCacheTest.php --fail-on-phpunit-warning --display-warnings
npm run typecheck
npm run test:browser -- tests/browser/public-track-embed.spec.ts
```

Feature coverage includes minimal escaped HTML, session/cookie absence, fixed origin and unsafe configuration rejection, exact bytes, HEAD/ranges, withdrawal/current eligibility, sold scopes, superseded/cross-track/private-role rejection, missing/corrupt bytes, throttling and generic debug errors. Historical purchase evidence remains unchanged.

The route-cache regression compiles the application's actual routes in one disposable PHP process and handles requests in a separate fresh process. It tests both cached and uncached boots with the real routes, throttle middleware and unknown-slug controller responses. It exhausts both budgets from the same IP, confirms a different IP has its own allowance, poisons authentication guard lookups and checks session/cookie absence and generic protected 429s. Its empty SQLite routing fixture is isolated from the suite's configured database, including in MySQL jobs; it does not replace publication or media tests. The worker refuses execution outside its disposable test workspace. On the recovered unfixed source, uncached mode passed but the first cached request returned 503 with missing rate-limit headers. Moving the registrations to the provider made both modes pass. The combined local SQLite run executed 17 tests and 1,709 assertions with no failures; final integrating source, MySQL and native results must be recorded separately.

The browser spec renders the actual Blade template in the isolated browser harness and serves synthetic audio transport in a cross-origin frame. It checks native controls, no autoplay, keyboard playback/pause, responsive width and a keyboard-operated store link, and captures a screenshot. It establishes browser behavior only; backend publication/integrity evidence comes from the feature suite. The helper is test-only and refuses execution outside the disposable browser workspace. Record actual executed counts and environments in the integrating candidate; listing the browser cases is not their execution.

Primary behavior references: [WHATWG media elements and preload](https://html.spec.whatwg.org/multipage/media.html) and [W3C Content Security Policy](https://www.w3.org/TR/CSP/). Deployment header acceptance and real-device native controls remain staging checks. No data migration, production catalog creation, payment activation or customer support delivery is included.
