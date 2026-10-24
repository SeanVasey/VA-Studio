# Domain continuity and search metadata (lane T36) — October 24, 2026

Branch `harness/domain-continuity-t36-20261024`, based on `786fb7c755291184f9e01b4faaab28dbd430d698` (origin/main). Parity rows: FP-100 (legacy URL redirects and domain continuity) and FP-014 (search engine and social metadata). Nothing in this lane pushes, deploys, changes DNS, activates redirects or claims cutover readiness.

## Work package (written before implementation)

### Files owned

- `app/Domain/Migration/LegacyRedirects/*` (new): map loader, validator and request resolver.
- `app/Http/Middleware/LegacyRedirects.php` (new): global middleware, inert unless the flag is the literal `true`.
- `app/Console/Commands/ValidateLegacyRedirects.php` (new): `vasey:redirects-validate`.
- `config/legacy-redirects.php` (new): the flag is a code literal, so no `.env.example` line was added.
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

## Result

Commits on the branch (base `786fb7c7`): `8e09067a` work package, `519b1db0` redirect layer, `dfc2ed63` redirect tests, `80086443` JSON-LD, `9453334f` docs and CI count comment, then this evidence commit. The PHP and frontend suites ran on `80086443`; `9453334f` changed only documentation and one workflow comment (no file under `app`, `config`, `resources`, `routes`, `bootstrap` or `tests`), and the mutations, route-cache check and MySQL selection proof ran on `9453334f`. The evidence commit adds only this README.

### Design decisions

- **Exact match only.** No inventory row needs a pattern, so the map has no wildcard syntax and the validator rejects `*`, `{}`, queries, fragments, dot segments, uppercase and trailing slashes in sources.
- **A real route always wins.** The middleware runs before routing but acts only when no route matches (a match on another method counts), so it never shadows the application, including routes that answer 404 themselves. The validator turns a shadowed `redirect`/`gone` source into an error instead of keeping dead configuration.
- **Targets.** Named GET routes without parameters, editorial sections/entries, published track slugs, and https external URLs only when the host is in the map's own reviewed `external_hosts` (empty today). Content targets are checked against current public content on every request; anything not public answers the ordinary 404, so a redirect never reveals a draft, unpublished, withdrawn or ineligible item. Chains (target is another entry's source), loops and external targets back at a legacy host are refused.
- **Query strings are dropped; `Location` uses the canonical `APP_URL` origin**, never the request Host: one hop to the canonical URL, no host-header poisoning, no legacy parameters forwarded into pages that validate their own queries.
- **Default-off as a code literal** (`config/legacy-redirects.php` `'enabled' => false`), following the repository's code-literal family precedent: activation is a reviewed change with the cutover authorization, not an environment switch, and no ops template or pre-launch hold list changes were needed. Only the literal boolean `true` activates.
- **301 caching is bounded** (`Cache-Control: public, max-age=3600`) because browsers otherwise keep a 301 indefinitely.
- **Fail closed.** An invalid or missing map logs a warning and serves no redirect; the map is never partially applied.
- **JSON-LD follows the sitemap rule** (production only), is attached only by the public `StorefrontController` and `EditorialController`, and is skipped for synthetic-marked tracks as defence in depth. No `Offer`, price or availability while public checkout is disabled; no duration (the stored one is the preview's); no structured data for about/contact/listings/videos. `MusicGroup` is used for the artist because schema.org defines it to include solo musicians.
- **Escaping.** `JSON_HEX_TAG | JSON_HEX_AMP` plus default Unicode escaping gives ASCII JSON with no `<`, `>` or `&`; the encoder throws if that ever fails, and the React head drops any value containing them before Inertia writes it raw.

### Inventory reconciliation

20 inventory rows, 20 map entries: **0 redirect, 0 gone, 5 retained, 15 unresolved.** Retained: URL-001 `/` (home), URL-009 `/videos`, URL-010 `/blog`, URL-011 `/about`, URL-012 `/contact` (editorial route). Unresolved, each with its reason in the map: URL-002 `/music/tracks` (proposed `/beats`), URL-003 `/music/albums` and URL-004 `/music/collections` (proposed `/collections`), URL-005 `/sound-kits` (proposed `/kits`), URL-006 `/services`, URL-007 `/merch`, URL-008 `/memberships`, URL-013 `/licensing-info` (proposed `/licensing`), URL-014 `/terms-of-use` (proposed `/terms`), URL-015 `/privacy-policy` (proposed `/privacy`), URL-016 `/account/purchases`, URL-017 `/account/offers`, URL-018 `/account/membership`, URL-019 `/account/faves` (proposed `/account/favorites`), URL-020 `/account/lyrics`. None of the 15 proposed targets has a route on this base, and every row is still an unverified recommendation; no target was guessed.

### Commands and results (worktree `/home/user/wt/t36`, SQLite in memory, `public/build` absent)

PHPUnit was run directly with the lane's command: `env APP_ENV=testing APP_DEBUG=false APP_KEY=base64:… DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync CI=true GITHUB_ACTIONS=true PAO_DISABLE=1 php -d memory_limit=512M -r '…require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --fail-on-phpunit-warning <file>` (PHP 8.4.26, PHPUnit 12.5.34). Vendor was a copy of a lock-identical install (160 packages, 0 version/reference differences from `composer.lock`).

| Check | Result |
| --- | --- |
| `tests/Feature/LegacyRedirectMapTest.php` | OK (26 tests, 94 assertions) |
| `tests/Feature/LegacyRedirectMiddlewareTest.php` | OK (7 tests, 71 assertions) |
| `tests/Feature/LegacyRedirectCommandTest.php` | OK (2 tests, 19 assertions) |
| `tests/Feature/PublicStructuredDataTest.php` | OK (6 tests, 165 assertions) |
| 32 existing files, one file per run: `StorefrontMetadataTest`, `PublicPagesSitemapTest`, `PublicDiscoveryIndexTest`, `PublicInstallMetadataTest`, `SiteContentHttpTest`, `SiteContentUnavailableHttpTest`, `SiteEditorialHttpTest`, `SiteEditorialContentTest`, `SiteRelatedTrackHttpTest`, `SiteImageHttpTest`, `SiteImagePublicTest`, `PublicTrackEmbedTest`, `PublicTrackEmbedRouteCacheTest`, `DiscoverySitemapRegistrationTest`, `DiscoverySitemapReviewRegressionTest`, `DiscoverySitemapWorkerTest`, `CheckoutReturnPresentationTest`, `FoundationTest`, `ServiceProjectRegistrationTest`, `FreeGrantRegistrationTest`, `FreeGrantHttpTest`, `PaidGrantRootRegistrationTest`, `PaidTaxRootHttpBoundaryTest`, `ProductionAccountFeaturesDefaultMountTest`, `ProductionAccountFeaturesHttpTest`, `ProductionFreeGrantHttpTest`, `ProductionSuppressionDefaultConfigurationTest`, `CustomerInquiryHttpTest`, `QuoteTest`, `TestCommerceOperationsTest`, `Unit/ProductionEnvironmentTemplateTest`, `Unit/BrowserLoginRateLimitFixtureTest` | all OK: 490 tests, 14,775 assertions, no failure, error or skip reported |
| `npx vitest run` (Node 24.22.0) | 69 files, 1174 tests passed |
| `npx vitest run tests/frontend/structured-data.test.tsx tests/frontend/metadata.test.tsx` | 2 files, 7 tests passed |
| `npx tsc --noEmit` | exit 0 |
| `python3 scripts/ci/test-phpunit-shards.py` / `test-ci-scope.py` / `test-workflow-cadence.py` / `test-focused-tests.py` | OK: 52 / 27 / 14 / 53 tests |
| `composer validate --strict` | `./composer.json is valid` |
| `php artisan vasey:redirects-validate` | exit 0; `Inventory rows: 20 · map entries: 20 · redirect: 0 · gone: 0 · retained: 5 · unresolved: 15`; `Map valid: no errors.` |
| `php artisan vasey:redirects-validate --require-resolved` | exit 1 (15 unresolved), as intended for the cutover gate |
| Same validation after `php artisan route:cache` (isolated copy, then `route:clear`) | exit 0, same summary |
| `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` | `Proved 2487 selected MySQL-native tests in 237 files (of 9583 discovered tests in 698 files)`; generated files deleted |

The new tests are ordinary (no MySQL-native or SQLite-skipped methods, no census change). The discovered count in the `final-verification.yml` comment moves from 9523 to 9583. Measured without the four new files the base discovers 9542 tests in 694 files, so the committed 9523 was already 19 behind main; the new files add 41 cases. `.gitlab-ci.yml` states only the selected count (2487 in 237 files), which is unchanged.

### Mutation testing

Each mutation was applied in a separate detached worktree of `9453334f` (`/home/user/wt/t36-mut`, hard-linked vendor), the named test file run, and the file restored; `git status` was clean afterwards. **23 applied, 23 killed, 0 survived.**

| ID | Mutation | Killed by |
| --- | --- | --- |
| M01, M01b | External host allowlist check removed | Map test (external targets) and middleware test (phish target redirected) |
| M02 | Track target treated as public without the catalog check | Middleware test (draft and withdrawn targets) |
| M03 | Editorial target treated as public without the release check | Middleware test (draft release) |
| M04 | Flag compared truthily instead of `=== true` | Middleware flag-off test (`'true'`, `1`, `'1'`, `'yes'`) |
| M05 | Flag check removed | Middleware flag-off test |
| M06 | Shipped config flag set to `true` | Middleware literal-false test |
| M07 | "Real route wins" check removed | Middleware test (`/about`, `/tracks/missing-track`, `/checkout`) |
| M08 | Chain detection removed | Map test (chain) |
| M09 | Loop detection removed | Map test (loop) |
| M10 | Rows absent from the inventory accepted | Map test (invented row) |
| M11 | Inventory hash pin ignored | Map test (drift) |
| M12 | Shadowed `redirect`/`gone` sources accepted | Map test (served sources) |
| M13 | `Location` built from the request Host | Middleware tests (attacker Host, canonical origin) |
| M14 | Query string forwarded | Middleware test (query dropped) |
| M15 | Escape flags removed (encoder guard left) | Escaping test (guard turns it into a 500) |
| M16 | Escape flags and encoder guard removed | Escaping test (an executable `<script>` appears in the parsed document) |
| M17 | Production gate removed | Outside-production test |
| M18 | Synthetic-track exclusion removed | Exclusion test |
| M19 | Structured data attached in the staff preview | Exclusion test (preview) |
| M20 | `Offer` with price added to `MusicRecording` | Track shape and no-price test |
| M21 | Client guard against unescaped JSON removed | `structured-data.test.tsx` (unsafe value rendered) |
| M22 | Client head key changed from the server key | `structured-data.test.tsx` (server block not adopted) |

### Untested conditions

- No MySQL run: the change has no schema, write or locking path; reads use the existing catalog and site-content services, which have their own MySQL coverage. Foundation CI and the browser (Playwright) specs were not run; preinstalled browsers do not match the pin.
- No deployed host: real web-server HEAD handling, CDN/shared-cache behaviour of the 301/410 headers, and the redirect with `config:cache` on a production host are unverified. The validator was checked with `route:cache`.
- No live legacy-site verification: every inventory row remains a recommendation needing live browser and catalog evidence and verified owner control (the reason 15 rows are unresolved and none redirect).
- No search-engine validation (Rich Results Test, Search Console) of the JSON-LD; schema shape follows schema.org definitions only.
- The `docs/remaining-parity-coverage.csv` status column (dated 2026-10-17) was not edited; FP-100 now has a default-off implementation with no active mapping and FP-014 has JSON-LD, which a later status refresh should record. README.md was not edited (Codex-shared file).

### Next dependency

Resolving the 15 rows needs: live-site URL verification and owner control (cutover runbook), the public pages they would target (catalog listing, collections, kits, services, merch, memberships, licensing, legal pages) and the production customer-account decision U-07 for `/account/*`. Activation needs Sean's cutover authorization, a map with `--require-resolved` passing, and a reviewed change of the code-literal flag.

## Independent review

[Decision record](independent-review-8d2dcf3b-DECISION.md): **APPROVE WITH CONDITIONS** at `8d2dcf3b`, no P0 to P2; ten reviewer mutations all killed.
Applied before the PR: P3-1 and P3-3 (guide notes on `/account/*` sources and public-only route targets), P3-5 (this
README's `.env.example` sentence) and P3-6: the Organization's `parentOrganization` is now Vasey Multimedia, the merchant of
record that does business as VASEY.AUDIO (Sean, 2026-10-24), instead of Sean Vasey Productions. Deferred: P3-2 (the map is
re-read on every eligible request when enabled; a broken map would warn on each) and P3-4 (a cached 301 can outlive an
unpublished target by up to an hour; nothing leaks).
