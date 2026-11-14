# Independent review: lane T36 (legacy URL redirects + JSON-LD), HEAD 8d2dcf3b

Reviewer: independent (Claude). Worktree /home/user/wt/t36, branch harness/domain-continuity-t36-20261024.
Nothing was pushed, committed, commented or dispatched. `git status --short` is empty and `public/build` is absent at the end. Scratch copies are under the scratchpad directory only (`mut/`, `base/`).

## Verdict: APPROVE WITH CONDITIONS

No P0, P1 or P2 finding. Open-redirect, draft-leak and XSS paths all fail closed, and I could not construct a failing path. The conditions are documentation/latent items (P3) that should be recorded in the deferred batch or in the guide before the flag is ever flipped. None blocks a development merge.

Conditions (all P3, none a merge blocker):
1. Document (or fix before activation) that `/account/*` legacy sources cannot get a clean redirect: see F1.
2. Record F2 (per-request map read and log amplification when enabled) in the deferred batch.
3. Correct the stale work-package line about `.env.example` (F5).

## Findings

### P3-F1 (latent, no effect today): CustomerPrivacy wraps any future `/account/*` redirect
Failure path: `CustomerPrivacy` is prepended globally and runs before `LegacyRedirects` (appended). `matches()` is `account`, `account/*`. If a future map entry redirects `/account/purchases` (URL-016..020 are the only such rows; all are `unresolved` today):
- `GET /account/x?utm=1` returns 422 JSON from CustomerPrivacy (it refuses any query string) instead of the 301.
- A plain GET reaches the redirect, then `CustomerPrivacy::protect()` overwrites `Cache-Control` to `private, no-store`, so the documented `public, max-age=3600` does not apply.
Reproduced in the scratch copy (flag on, fixture source `/account/legacy-fixture`): `GET /account/legacy-fixture` gave `301 ... no-store, private`; `GET /account/legacy-fixture?utm=1` gave `422`.
Impact now: none (flag false, no active redirect, all 5 account rows unresolved). `docs/migration/legacy-redirects.md` states "Query strings are dropped" and `max-age=3600` without this caveat. Suggest one sentence in the guide, and a decision on `/account/*` when U-07 and the C4e mount land. The same ordering logic applies to the other prepended privacy middlewares, but only `/account` overlaps the legacy inventory.

### P3-F2 (only when enabled): map is re-read and re-validated on every shape-valid GET/HEAD; log amplification on a broken map
`LegacyRedirectResolver::respond` calls `LegacyRedirectMap::fromFile()` for every GET/HEAD whose path matches the strict lowercase pattern (that includes `/`, `/tracks/x`, `/blog/x`). Measured 99.7 us per load for the shipped 20-entry map (5000 iterations). With the flag off the cost is one `config()` read, as claimed. If the map is ever invalid while enabled, `Log::warning` fires on every such request. Not a correctness problem; consider a per-process memo (static, keyed by mtime) later. The 100 us figure is acceptable for the cutover window.

### P3-F3: `route` targets are not restricted to public routes
`describe()` accepts any named GET route without required parameters (for example a Filament or other authenticated route). The map is reviewed code, the flag is a code literal, and the validator lists targets, so this is not exploitable by a request. Worth a reviewer checklist line in the guide ("route targets must be public pages"). Content targets (track, editorial) are correctly live-checked.

### P3-F4: 301 cached up to an hour after a target is unpublished
`Cache-Control: public, max-age=3600` on a 301 whose target eligibility is live-checked means a browser or shared cache can keep sending users to a track that has since gone 404. This leaks nothing (the redirect itself was issued while the target was public) and is documented as bounded. Informational.

### P3-F5: stale text in the work-package section of the evidence README
"Files owned" lists "one documented `.env.example` line", but the diff does not touch `.env.example`, and the design decisions section correctly says the flag is a code literal with no ops template change. Fix the sentence.

### P3-F6: Organization hierarchy is asserted, not sourced
`parentOrganization: Sean Vasey Productions` encodes "VASEY.AUDIO is a unit of Sean Vasey Productions". CLAUDE.md says "Brand: VASEY.AUDIO (Sean Vasey Productions)", which supports ownership but does not name the structural relationship, and lists both as sibling audio brands under Vasey Multimedia. Low risk; confirm wording with Sean. VASEY/AI never appears (asserted by the test and by grep; see Brand below).

## Focus areas, checked

1. Open redirects: no path found.
   - Location is `PublicPagesSitemap::canonicalOrigin()` (validated APP_URL: scheme http/https, no credentials, bare host) plus a path produced by `route()`; the request Host and query string are never used. Verified on both cached and uncached routes with `Host: evil.test`.
   - Map sources must match `PATH_PATTERN` (lowercase hyphenated segments, no dot, `%`, `;`, backslash, trailing slash, or `//`), and matching is exact (`===`). Request handling: `/x/` collapses to `/x` (intended), `//x` collapses and redirects to the canonical origin (harmless), `%2F`, `%6c` encodings, `;x`, uppercase, and longer paths all 404 (checked live).
   - External targets: https only, parse_url must give a host with no user, pass, port or fragment, `filter_var(FILTER_VALIDATE_URL)` must pass, host must be in the map's own lowercase `external_hosts` (shipped list is empty), and the host may not equal a legacy source host. I ran 20 tricky strings through the same predicate (backslash-at, tab/newline in host, `https:\\evil`, `https:/\evil`, `%2f@`, `#@`, `?@`, uppercase host, trailing dot, userinfo tricks). Everything that lands on a non-allowlisted host is refused. The four that pass (`https://good/x`, `https://good?@evil.com`, `https://good/\evil.com`, `https://good/%0d%0a...`) all resolve to the allowlisted host in a browser, and raw CR/LF cannot pass the URL filter. Protocol-relative `//evil` fails the scheme check.
   - Chains and loops are refused by `describe()`; 410/301 only for GET/HEAD, only when no route matches (including a route for another method).
2. Draft/private leakage: none.
   - Track and editorial targets re-run `PublicCatalog::track()` / `EditorialContent::page()` on every request, with `HttpExceptionInterface` and any `Throwable` returning false (report, 404). Test covers draft, never-existed, published then unpublished, and draft release versus published release.
   - JSON-LD: gated by `app()->environment('production')`, called only from `StorefrontController::index` and `EditorialController::__invoke`; `SiteReleasePreviewController` never sets `structuredData`; synthetic marker, title prefix and artist skip; no Offer/price (grep and test); blog entries only; builds only from the already-filtered projection.
3. Escaping/XSS: no path found.
   - Encoder: `JSON_HEX_TAG | JSON_HEX_AMP`, no `JSON_UNESCAPED_UNICODE` (so U+2028/2029 become ` `), then a throwing guard `/[<>&]|[^\x20-\x7E]/`. Blade prints the string raw only when it is a string; the React head drops any value containing `< > &`. The feature test feeds `</script><script>`, `<!--`, `&`, quotes, U+2028, and `</SCRIPT ><img onerror>` in title and artist, parses the document with DOM, and asserts no executable inline script; the vitest checks navigation, adoption, replacement and removal.
   - Residual: invalid UTF-8 in a title would make `json_encode` throw (HTTP 500), but catalog and release content is validated before storage; informational.
4. Flag gating: `config/legacy-redirects.php` ships `'enabled' => false`; middleware tests `=== true`; test proves `'true'`, `1`, `'1'`, `'yes'` do not enable; a missing/invalid map logs a warning and yields the normal 404 (test with `Log::spy`). Real routes win (test with `/about`, `/tracks/missing-track`, `/checkout` 405). Non-GET/HEAD untouched (POST, PUT checked; mutation killed).
5. Registration and interactions: `append` places it after all prepended privacy middlewares and after the framework's global stack, so it runs before routing and sees privacy rejections first (see F1). It starts no session and sets no cookie (asserted `getCookies() === []`). It calls `Route::getRoutes()->match($request)` itself only after an exact source hit; with no map hit the cost is the map read (F2). A real `Route::fallback` would disable redirects, but none exists (grep). Works with a cached route table: I ran `php artisan route:cache` in the scratch copy and replayed 301, external 301, 410 and 404 cases with identical results (`routesAreCached` true), and the validator command gave the same summary.
6. Validator and shipped map: command output on HEAD is `Inventory rows: 20 · map entries: 20 · redirect: 0 · gone: 0 · retained: 5 · unresolved: 15`, `Map valid: no errors`, exit 0; `--require-resolved` exits 1. The pinned SHA-256 `b86dae62...3ebb13` matches `sha256sum` of the CSV, rows 20. Retained rows (/, /videos, /blog, /about, /contact) are really served (home, editorial.show); the 15 unresolved rows have no serving route (no validator warning), and each proposed target has no route. Checks for invented rows, host/path drift, shadowed sources, chains, loops and external-host pins are all present and tested.
7. Brand: constants are `VASEY.AUDIO` and `Sean Vasey Productions`; `VASEY/AI` appears nowhere in the new code and the test asserts its absence; logo path `/brand/vasey-audio-logo.png` is the existing asset (`public/brand/vasey-audio-logo.png`, added long before this lane, also used by `SiteChrome.tsx`); nothing in `public/` changed.

## Commands and results

Direct PHPUnit (SQLite, the specified env) on HEAD 8d2dcf3b:
- Four new files (LegacyRedirectCommandTest, LegacyRedirectMapTest, LegacyRedirectMiddlewareTest, PublicStructuredDataTest): OK, 41 tests, 349 assertions.
- StorefrontMetadataTest, PublicPagesSitemapTest, SiteEditorialHttpTest, ProductionFreeGrants/ProductionFreeGrantHttpTest: OK, 75 tests, 2063 assertions.
- `npx vitest run tests/frontend/structured-data.test.tsx` (Node 24.22.0): 2 passed. `npx tsc --noEmit`: exit 0. `composer validate --strict`: valid.
- Safeguards: test-ci-scope 27 OK; test-database-receipts 61 OK; test-focused-tests 53 OK; test-gitlab-database-receipts 32 OK; test-gitlab-setup 12 OK; test-gitlab-writer-feedback 7 OK; test-php-test-runtime 5 OK; test-phpunit-shards 52 OK; test-workflow-cadence 14 OK.
- `scripts/ci/test-related-browser-stage.py`: 4 failures in `BrowserBootstrapFixtures` (bootstrap dies at phase `customer_provisioning`, `CustomerAccessException`). I ran the same script on a clean `git archive e5d0d3f2` (origin/main, no T36 code) with the same vendor and got the same 4 failures, so this is pre-existing/environmental and not introduced by T36.
- Count comment check: `python3 scripts/ci/phpunit-shards.py --shards=8 --prefix=phpunit-ci-mysql --timings=scripts/ci/phpunit-timings-mysql.json --mysql-native-selection` printed `Proved 2499 selected MySQL-native tests in 239 files (of 9595 discovered tests in 700 files)`, matching the `final-verification.yml` and `.gitlab-ci.yml` text after the merge. Generated files deleted.

## Mutations (scratch copy of HEAD under the scratchpad `mut/`; 10 applied, 10 killed)

| Mutation | Killed by |
| --- | --- |
| External host allowlist check disabled | MapTest external targets, MiddlewareTest external targets, CommandTest (3 failures) |
| Location built from `$request->getSchemeAndHttpHost()` | MiddlewareTest canonical-origin and content-target tests |
| Eligibility re-check skipped (`currentlyPublic` bypass) | MiddlewareTest content-target test |
| JSON-LD production gate removed (`admitted()` true) | PublicStructuredDataTest outside-production test |
| Flag compared with `==` instead of `=== true` | MiddlewareTest literal-flag test |
| "Real route wins" check removed | MiddlewareTest route-wins test |
| `JSON_HEX_TAG` and `JSON_HEX_AMP` removed | PublicStructuredDataTest escaping test |
| Query string forwarded on Location | MiddlewareTest query-drop test |
| `entryForPath` made a prefix match | MiddlewareTest exact-match test |
| Method restriction (GET/HEAD) removed | MiddlewareTest method test |

I did not repeat the author's preview-emission mutation; the code path (`SiteReleasePreviewController` never sets `structuredData`) was read directly and the author's test covers it.

## Untested conditions

- No MySQL, Foundation CI or browser/Playwright run (none relevant to this lane's SQL-free changes; browsers do not match the pin).
- No real web server: HEAD handling, CDN/shared-cache behaviour of 301/410, `config:cache` on a production host are unverified. Route cache was exercised in-process only.
- Behaviour when the flag is on was exercised only against fixture maps and a bootstrapped kernel, never the production host, and no live legacy-site verification exists (the reason all 15 rows stay unresolved).
- Schema.org shape was not validated against Google's Rich Results Test.
- The `/account/*` interaction (F1) was demonstrated with a fixture source, not a real inventory row.
