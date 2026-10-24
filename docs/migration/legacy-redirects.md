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

## Current reconciliation (2026-10-24, base 786fb7c7)

20 inventory rows, 20 entries: 0 redirect, 0 gone, 5 retained (URL-001 `/`, URL-009 `/videos`, URL-010 `/blog`, URL-011 `/about`, URL-012 `/contact`) and 15 unresolved (URL-002 to URL-008 and URL-013 to URL-020). Every unresolved row's proposed target has no current route, and every inventory row is still a `Recommendation` that needs live browser and catalog verification and verified owner control. No target was guessed. URL-010 keeps `/blog` because the current implementation serves it; the older `/updates` proposal is not implemented.

To resolve a row: verify the legacy URL against the live site, build or choose the target route, change the entry, run the validator, and record the evidence in the PR. After any inventory change, review every row and update the pinned hash and row count.
