# Domain continuity and search metadata (lane T36) — October 24, 2026

Branch `harness/domain-continuity-t36-20261024`, based on `786fb7c755291184f9e01b4faaab28dbd430d698` (origin/main). Parity rows: FP-100 (legacy URL redirects and domain continuity) and FP-014 (search engine and social metadata). Nothing in this lane pushes, deploys, changes DNS, activates redirects or claims cutover readiness.

## Work package (written before implementation)

### Files owned

- `app/Domain/Migration/LegacyRedirects/*` (new): map loader, validator and request resolver.
- `app/Http/Middleware/LegacyRedirects.php` (new): global middleware, inert unless the flag is the literal `true`.
- `app/Console/Commands/ValidateLegacyRedirects.php` (new): `vasey:redirects-validate`.
- `config/legacy-redirects.php` (new) and one documented `.env.example` line.
- `resources/legacy-redirects/map.json` (new): the reviewed, versioned map, one entry per inventory row.
- `app/Support/PublicStructuredData.php` (new): schema.org JSON-LD for public pages.
- Small edits: `app/Http/Controllers/StorefrontController.php`, `app/Http/Controllers/EditorialController.php` (attach structured data), `resources/views/app.blade.php` (server fallback tag), `resources/js/components/MetadataHead.tsx` and `resources/js/lib/catalog.ts` (keep the tag through Inertia navigation).
- One isolated line pair in `bootstrap/app.php` (import plus `$middleware->append(...)`). `routes/web.php` is not touched.
- Tests: `tests/Feature/LegacyRedirectMapTest.php`, `tests/Feature/LegacyRedirectMiddlewareTest.php`, `tests/Feature/LegacyRedirectCommandTest.php`, `tests/Feature/PublicStructuredDataTest.php`, `tests/frontend/structured-data.test.tsx`.
- Docs: this packet, `CHANGELOG.md` (Unreleased), `docs/migration/legacy-redirects.md`.

Not touched: lane C4e files (`routes/web.php`, `config/production-memberships.php`, `app/Http/Membership/*`), lane G1 membership/identity domain files, `resources/contracts/*/profile-assets.json`, the logo and DNS.

### Assumptions

1. `docs/migration/legacy_route_inventory.csv` (20 rows, SHA-256 `b86dae62…0ebb13`) is the only authority for legacy URLs. Every row is a `Recommendation` with `needs_live_browser_and_catalog_export` and `owner_control_not_yet_verified`; no row has been verified against the live site. The map therefore must not invent paths, and a row whose proposed target has no current route is recorded as unresolved, not guessed.
2. Rows whose legacy path is already served by a current route (same path retained) need no redirect; the validator proves the route still matches.
3. The production host and the domain plan are undecided (U-02). Host-level continuity (apex/www, certificates, MX/SPF/DKIM/DMARC) stays in `docs/migration/cutover_runbook.md`; the application can only redirect paths that reach it.
4. JSON-LD follows the sitemap's exclusion rule: it is produced only in `production` (the synthetic rehearsal catalog is refused there), only for the public home, track and editorial routes, and never for staff previews, drafts or private pages. Track data comes only from the already-filtered public catalog projection.
5. Prices are not advertised in structured data while public checkout is disabled (`commerceEnabled` is `false` on every public page). The published offer data has prices, but an `Offer` would also assert availability for purchase, which is not true yet.

### Acceptance criteria

- The map validates structurally (version, inventory pin, one entry per inventory row, matching host and path, allowed dispositions and statuses) and semantically against the live route table: redirect and gone sources are not served by any route; retained sources are; internal targets resolve to an existing GET route or a published-content lookup; external targets are https and on the map's own reviewed allowlist; no target is another entry's source (no chains or loops); no wildcards, query strings or fragments.
- `vasey:redirects-validate` prints a reconciliation summary (inventory rows, mapped, retained, gone, unresolved with reasons, errors) and exits non-zero on any error, or on unresolved rows with `--require-resolved`.
- The middleware does nothing unless `legacy-redirects.enabled` is the literal `true`. When on, it acts only for GET/HEAD requests whose exact path is a map source and that no route matches; it answers 301 to the canonical `APP_URL` origin or 410; the query string is dropped; an invalid map yields no redirects; a content target that is not currently public yields 404, never a redirect that reveals it.
- Public home, track and blog-entry pages carry one `application/ld+json` block in production with truthful fields (Organization/WebSite; MusicRecording plus BreadcrumbList; BlogPosting plus BreadcrumbList). No JSON-LD outside production, on previews, drafts, unpublished or ineligible tracks, or synthetic-marked tracks. The JSON cannot terminate its script element (`<`, `>`, `&` are escaped) and the React head keeps, replaces and removes the tag during navigation.

### Tests planned

Map validation (bad targets, loops, chains, external targets, draft/content targets, shadowed sources, inventory drift), middleware behaviour (only unmatched paths, status codes, query handling, flag off), command output and exit codes, JSON-LD presence/shape/escaping per page type and exclusions, vitest coverage of the head tag, `npx tsc --noEmit`, the existing metadata/sitemap/editorial/track-sharing suites, the four CI safeguards and `composer validate --strict`. Key guards are mutation-tested (open-redirect allowlist, draft exclusion, flag-off, escaping).
