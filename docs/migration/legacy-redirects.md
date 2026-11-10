# Legacy URL redirects (FP-100)

Operator and reviewer guide for the default-off legacy redirect layer added on 2026-10-24 (lane T36). It covers paths that reach this application. Host-level continuity (DNS records, apex/www, certificates, MX/SPF/DKIM/DMARC) stays in the [cutover runbook](cutover_runbook.md) and is not changed here.

## Pieces

| Piece | Location | Role |
| --- | --- | --- |
| Map | `resources/legacy-redirects/map.json` | Reviewed, versioned decisions: one entry per row of `legacy_route_inventory.csv`, keyed by its `route_id` |
| Flag | `config/legacy-redirects.php` `enabled` | Code literal `false`. Turning it on is a reviewed change made with Sean's cutover authorization, never an environment switch |
| Validator | `php artisan vasey:redirects-validate [--require-resolved]` | Read-only reconciliation against the inventory and the live route table |
| Middleware | `App\Http\Middleware\LegacyRedirects` (global, appended in `bootstrap/app.php`) | Returns immediately unless the flag is the literal `true` |

## Map format

```json
{"schema": "vasey.legacy-redirects", "version": 1, "reviewed_on": "YYYY-MM-DD",
 "inventory": {"path": "docs/migration/legacy_route_inventory.csv", "sha256": "<hex>", "rows": 20},
 "query_string": "drop", "external_hosts": [], "entries": [ ... ]}
```

Each entry has `route_id`, `source_host` and `source_path` copied exactly from its inventory row, and one `disposition`:

- `retained`: the legacy path is served unchanged by a current route. The validator fails if no route serves it.
- `redirect`: `status` 301 and a `target`. Allowed targets are `{"type": "route", "name": "<GET route without parameters>"}`, `{"type": "editorial", "section": "about|contact|blog|videos", "slug": null|"<entry>"}` (an entry slug only for blog or videos), `{"type": "track", "slug": "<published slug>"}` and `{"type": "external", "url": "https://..."}`. External hosts must be listed in the map's own `external_hosts`; the shipped list is empty.
- `gone`: `status` 410 for retired content with no successor.
- `unresolved`: a `reason`. No runtime effect; listed by the validator until someone decides it.

There is no wildcard or pattern syntax, because no inventory row needs one. Source paths are exact lowercase paths without a trailing slash, query or fragment; a request with a trailing slash matches the same entry, and any other case or longer path does not.

## Request behaviour when enabled

1. Only GET and HEAD are considered, and only for an exact map source whose disposition is `redirect` or `gone`.
2. If any route matches the request (including a route for another method, or a route that itself answers 404), the middleware does nothing: a real route always wins.
3. `gone` answers 410. `redirect` answers 301 with `Cache-Control: public, max-age=3600` so a corrected map takes effect within the hour.
4. The `Location` uses the canonical `APP_URL` origin, never the request Host, so the redirect is a single hop to the canonical host.
5. Query strings are dropped. Legacy tracking or paging parameters are not forwarded into the new pages, matching the canonical-URL rule in [track sharing](../track-sharing.md).
6. Track and editorial targets are re-checked against current public content on every request. A draft, unpublished, withdrawn or otherwise ineligible track, or an editorial page that is not in the active published site release, gets the ordinary 404 instead of a redirect, so a redirect never confirms or exposes private content.
7. An unreadable or invalid map, a refused target (not allowlisted, a chain, a loop, a missing route) or anything uncertain falls through to the ordinary 404 and logs a warning. The map is never partially applied.

Redirects run before the web middleware group, so they start no session and set no cookie.

Two cautions for future map entries (independent review, 2026-10-24):

- An `/account/*` source would not behave as described above: the prepended `CustomerPrivacy` middleware runs first, answers
  any query string with 422 and replaces `Cache-Control` with `private, no-store`. All five account rows are unresolved today.
- A `redirect` to a named route is checked for GET and no parameters only, not for being public. A reviewer adding one must
  confirm the target is a public page.

## Validation rules

`vasey:redirects-validate` fails (exit 1) when the inventory hash or row count differs from the pin, an inventory row has no entry, an entry is not an inventory row or its host/path differs, a `retained` path is not served, a `redirect` or `gone` path is served by a route (dead configuration), or a target is invalid: a missing or non-GET route, a route needing parameters, a non-https or non-allowlisted external URL, an external URL back to a legacy host, or a target that is another entry's source (chain) or its own source (loop). A content target that is not public right now is a warning, because the request path answers 404 until it is published. `--require-resolved` also fails while any row is unresolved; that is the cutover gate.

## Services fallback (URL-006)

`/services` is a real route (BL-64), so a map redirect could never cover it: a real route always wins. Sean approved a fallback on 2026-11-10: while the service catalog flag (`services-projects.catalog_enabled`) is off, `ServiceCatalogController::index` asks `LegacyRedirectResolver::contactFallback()` for a 301 to the canonical contact page instead of answering 404.

- It follows the redirect layer's own switch: nothing happens unless `legacy-redirects.enabled` is the literal `true`, so it ships default-off and flips with the DNS switch (PCL-24). The shipped behaviour is still the plain 404.
- It uses the layer's rules: the `Location` is the canonical `APP_URL` origin plus `/contact` (never the request Host), the query string is dropped, and it answers 404 when the contact page is not public in the active site release.
- It covers `/services` only. `/services/{slug}` and `/services/projects*` keep their own 404 and privacy behaviour.
- The route runs inside the web group, so unlike a map redirect the response carries session cookies. It is therefore `Cache-Control: private, max-age=3600` (browser only), never `public`.
- When the catalog flag is on, the page is served and nothing redirects. The map records URL-006 as `retained` because the services route serves the path in both states.

## Live-site crawl (2026-11-10, BL-51 follow-up)

A read-only crawl of `https://www.vasey.audio` (the BeatStars-hosted store) fed this reconciliation. Raw results stay outside Git; `legacy_crawl_20261110.csv` holds the path, status, final URL, inferred page type and title of each URL, and `legacy_track_url_mapping_20261110.csv` holds the proposed per-track mapping.

- **Scope and politeness.** `robots.txt` (200) allows `/` for generic agents and disallows only audio and stream file extensions; it lists no sitemap. The crawler identified itself as `VA-Studio-migration-crawler`, sent HTTPS GET only, waited at least 1.2 seconds between requests, never logged in, submitted a form or touched cart, checkout or logout paths, and fetched 33 pages plus `robots.txt`, `/sitemap.xml` and one control path (36 requests of the 600 allowed).
- **Status codes mean nothing here.** The site is a client-rendered single-page shell. Every path answers 200 with the same shell, including a path that cannot exist (the control `/zz-crawl-control-nonexistent-path` is also 200, `/sitemap.xml` is the shell, not a sitemap). A 200 therefore proves neither that a page exists nor that it has content. Only the server-rendered head is distinct per URL: the canonical link, and on `/beat/*` pages the title.
- **What the plain fetch did yield.** The shell's navigation (ABOUT, BEATS, COLLECTIONS, VIDEOS, MEMBERSHIPS, SOUND KITS, SERVICES, UPDATES, CONTACT, MERCH; footer Terms of Use, Privacy Policy, Licensing Info; buyer menu Account, Lyrics, Faves, Offers, Purchases, Membership) and a JSON-LD `ItemList` of 12 beats at `/music/tracks` with their `/beat/<slug>-<id>` URLs. No section's content (kits, services, blog, videos, collections) is in the served HTML, and no headless browser or private API was used.
- **Per-track URLs.** The 12 `/beat/<slug>-<id>` URLs are not inventory rows and are not added to the map (that would be 12 rows against an empty staging catalog). The proposal is in `legacy_track_url_mapping_20261110.csv`: the new slug is the legacy slug without its trailing BeatStars ID (generated with the application's own `Str::slug` and equal to it for all 12), the target is `/tracks/<slug>`. Slugs are fixed when a track is first published and never change, so each proposal is confirmed against the published catalog. Resolution is then: add one inventory row per confirmed track (a reviewed change with the pinned hash and row count updated), add a `{"type": "track", "slug": ...}` redirect entry for it, and the resolver answers it only while that track is public. The catalog could hold more than 12 beats: the list carries `numberOfItems: 12` but the crawl cannot prove it is complete, and Sean's URL export is still the authority.

## Current reconciliation (2026-11-10, BL-51 and the crawl; first reviewed 2026-10-24 at base 786fb7c7)

20 inventory rows, 20 entries: 4 redirect, 0 gone, 6 retained (URL-001 `/`, URL-006 `/services`, URL-009 `/videos`, URL-010 `/blog`, URL-011 `/about`, URL-012 `/contact`) and 10 unresolved. The inventory CSV is unchanged, so its pinned hash and row count are unchanged.

Reviewed dispositions for rows that point into a family with no live public page, each to a page that exists and never to one that does not:

| Row | Legacy path | Target | Why |
| --- | --- | --- | --- |
| URL-002 | `/music/tracks` | catalog (`home`) | Crawl: the live BEATS listing. The catalog is the home page; the proposed `/beats` route does not exist. |
| URL-005 | `/sound-kits` | catalog (`home`) | No kit storefront page exists (FP-007). |
| URL-007 | `/merch` | catalog (`home`) | No merchandise page exists and none is sold. |
| URL-008 | `/memberships` | contact (`editorial` section `contact`) | No membership is sold at launch (DD-50); the contact page reaches the owner. |

URL-006 `/services` is `retained`: the route serves it, with the contact fallback above while the catalog is off. All redirects are 301 and every entry is revisited when the family's own page is enabled; the validator then fails the entry as dead configuration, because a served path may not be redirected. Nothing here activates a redirect: `config/legacy-redirects.php` `enabled` stays a literal `false`, and no DNS, customer data or BeatStars state is touched. The contact target resolves only while the active site release has a contact page; otherwise the request answers 404 and the validator warns.

Rows left unresolved on purpose, with the crawl evidence for each:

- URL-003 `/music/albums` and URL-004 `/music/collections`: the live navigation links COLLECTIONS but nothing links `/music/albums`, and the content could not be read. `/collections` has no route (Q-2) and `/albums` is default-off (BL-67).
- URL-013 `/licensing-info`, URL-014 `/terms-of-use`, URL-015 `/privacy-policy`: the live footer links. The equivalents are `/legal/licenses`, `/legal/terms` and `/legal/privacy`, parameterized routes that the target grammar cannot express (the editorial target covers only about, contact, blog and videos), and each answers 404 until owner-reviewed text is in the active site release. Resolving them needs a small `legal` target type (reviewed validator change) or a decision to serve the legacy paths directly; this branch does not extend the grammar.
- URL-016 `/account/purchases`, URL-017 `/account/offers`, URL-018 `/account/membership`, URL-019 `/account/faves`, URL-020 `/account/lyrics`: signed-in buyer pages that the crawl could see only as menu links. Each is an `/account/*` source, so the customer-privacy middleware would change its behaviour (see the cautions above), and the targets depend on the production identity decision (U-07, open question Q-1). URL-019 and URL-020 would map to the private listening library (FP-079), which is not a public page; URL-017 has no equivalent feature.

Every inventory row is still a `Recommendation`; the crawl is evidence for the dispositions above, not verified owner control of the legacy host.

To resolve a row: verify the legacy URL against the live site, build or choose the target route, change the entry, run the validator, and record the evidence in the PR. After any inventory change, review every row and update the pinned hash and row count.
