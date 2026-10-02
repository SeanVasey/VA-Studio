# [WP-02] Persistent catalog administration and publication readiness

Status: **Original metadata/URL and operator-browser increments are merged; named metadata presets are accepted in PR #95. Reviewed bulk metadata, protected operator review, reviewed manual publication guards and the read-only publication manifest foundation are accepted in PR #104. The seven-trigger SQL rights-evidence floor is verified and merged in PR #105; separate main verification is externally blocked. Transactional rights writers are the active candidate, tracked in the current execution queue. Parent WP-02/T12 and broader production/device acceptance remain open.** See [accepted PR #104 evidence](../development-order.md#pr-104-staff-authoring-acceptance) and the [SQL rights-evidence acceptance](../verification/rights-evidence-guards.md).

- Suggested issue title: `[WP-02] Persistent catalog administration and publication readiness`
- Phase: 0
- Dependencies: WP-01.
- Suggested branch: `work/wp-02-catalog-and-admin-authorization`
- Implementation paths: app/Models, app/Policies, app/Domain/Catalog, app/Filament, database/migrations, database/seeders, routes and catalog tests.

## Problem

Sean must be able to manage real music metadata through a protected admin panel, with only publishable public records exposed.

## First reviewable increment

Complete authorized CRUD for track metadata and structured readiness, using the initial implementation as the starting point.

## Scope

- Track/product identity, stable slug, description, BPM/key, artwork metadata, categories/tags, contributor display and draft/published states.
- Real admin authentication and server action policies, including direct-resource access; production MFA remains a required WP-13 gate.
- Readiness result object that reports missing rights, media, license and price requirements. Missing downstream features produce blockers rather than fake verification flags.

## Acceptance criteria

- [ ] Authorized editor changes persist; unauthorized users cannot browse or mutate the panel/resources through direct routes.
- [ ] Draft/private records cannot appear in catalog queries or be reached through public detail IDs/slugs.
- [ ] Publishing invokes one application command and returns specific blockers; audit captures edits and publication state changes.
- [ ] Deletion cannot cascade into purchased records; published slug changes preserve a redirect entry or are explicitly blocked until supported.
- [ ] Seed data is synthetic and never creates public default admin credentials or claims actual inventory.

## Verification

Feature tests for role denials, persistent CRUD, draft leakage, readiness failures and publish/unpublish. Exercise Filament save/error flows in a browser. Record exact commit, environment and results. A checklist or unexecuted test definition is not completion evidence.

## Rollback and boundaries

Additive metadata migrations; revert interface without removing persisted catalog. Unpublish faulty new content through an audited command.

Do not add secrets, private masters, unredacted orders, customer PII or real contract documents to this issue/PR. Do not change an external provider, charge a customer, publish marketing or alter the live domain unless that action is within the recorded authorization and applicable readiness gates.

## Agent prompt

> Implement WP-02 as a protected persistent catalog increment. Inspect existing models/resources first. Centralize readiness and authorization server-side, preserve draft privacy and audit changes. Add meaningful permission/readiness tests and document what still depends on media/licensing work.

Read [architecture index](../architecture/README.md), [decision register](../architecture/decision-register.md) and relevant source/brand evidence. Complete the first increment in a small PR, then split any remaining implementation into explicitly dependent issues. Preserve prior approvals and invariants; report unresolved provider/policy decisions without blocking unrelated reversible work.

## Metadata and URL increment — 2026-09-09

### Accepted presets and four staff-authoring children

The [T12 named-preset increment](../verification/track-metadata-presets.md) is accepted in PR #95 at merge `d57c3bb266890bc32adc1974fb84c050d848e90b`, tree `3561b2e416360e01eeb2340b0c8557a6df6dc785`, after all twelve applicable jobs of Foundation 36973007214 and independent tested-source review passed. Persisted reusable noncommercial metadata, optimistic edits, retained archive and copied-value private draft creation advance FP-031. Broader T12 roles/recovery, licensing/default parity and track scheduling remain open.

The four accepted T12 children combine [reviewed bulk metadata](../verification/bulk-track-metadata.md), [protected operator review](../verification/private-track-review.md), [reviewed manual publication guards](../verification/track-publication-guards.md) and the [read-only publication manifest foundation](../verification/track-publication-manifest-foundation.md). Bulk Keep/Set/Clear before/after review advances FP033; protected metadata/readiness/media inspection grants no anonymous access; monotonic manual confirmation identity is the T12-PUBLICATION-GUARD-01 prerequisite for FP032 scheduling. The manifest adds immutable internal current-evidence identity with no UI consumer, persistence, reviewed compare/apply boundary, future fence or schedule. These related Tracks children passed final integrating PR #104 at head `0c7dbd8c7ddf41ea97fcae873d1587faec3b24a1`, tree `a3ed238f4c8dfc1a9b5759c419f02061349d6f58`, and squash merge `23d1e5c85936ecf8b37e0f2cf7d7372d1b0303ec`. The [canonical acceptance record](../development-order.md#pr-104-staff-authoring-acceptance) preserves run 373 and distinct successful postmerge run 374. The [seven-trigger SQL rights-evidence guard](../verification/rights-evidence-guards.md) is verified and merged in PR #105; its distinct main execution is blocked by payment authorization. See the [current queue](../development-control.md). It introduces no fresh transactional actor/MFA checks or writer fence. Next is transactional rights authority and compatible writers before reviewed manifest compare/apply. No schedule/window, broader license bulk operation, granular helper permission/recovery or production readiness is supplied by these children.

`SaveTrackMetadata` owns Filament create/edit persistence: verified staff authorization, metadata allowlist, validation, a locked current row, expected revision and transactional minimized audit evidence. A failed audit or invalid published edit rolls back the mutation. `published_slug` reserves the URL through unpublish/republish, with model and MySQL/SQLite triggers blocking rewrites and deletion/reuse. Historical backfill preserves known slugs without inventing dates or edit events. See [catalog administration](../catalog-administration.md).

Targeted tests cover actual Filament create/edit/error actions, changed request actors, direct command denials, stale forms, malformed/extra fields, readiness rollback, public/draft privacy, historical migration and raw SQL URL attacks. The MySQL test requires independent processes and an observed wait on the exact track row; it intentionally skips on SQLite. Definitions are not test results: the PR must record completed CI evidence at its actual head.

At the September 9 checkpoint, next acceptance work was WP-01 clean boot/operator diagnostics and WP-02 real-browser save/error flows, followed by remaining WP-03 media and WP-04 rights dependencies. Fine-grained roles and production MFA/recovery remain explicit gates; the current gate is verified `is_admin` staff. No work package is closed by this increment.

### Verification record

[PR #28](https://github.com/VASEYDEV/VASEYAUDIO/pull/28) records the tested source SHA/tree and completed results for `php artisan test` on MySQL 8.4 and SQLite, `npm test`, `npm run build`, `composer validate --strict`, `composer audit --no-dev` and `npm audit --omit=dev --audit-level=high`. Local `git diff --check` passed; PHP/Composer and frontend dependencies were unavailable locally.

The first candidate (`2c5279faa291443d3bec97e101fa51266fde1b74`) passed 205 MySQL tests, including the observed lock race, and failed two admin assertions: relative command errors did not render under Filament's mounted form path. The correction maps domain validation keys to the actual schema path; the assertions remain intact. This first run is diagnostic evidence, not final acceptance. Consult the PR's final verification table for the completed candidate and remaining review/browser gates.

## Protected operator track review — 2026-10-02

Historical October 2 development evidence before PR #104 acceptance: preceding two-child source `d970ce6`, tree `569ad68401bfef5f901947b08bc8f79b6dc27182`, passed eight targeted Chromium journeys. Publication-guard test source `2d3426c` executed 38 cases / 423 assertions with 14 exact MySQL-only skips (52 reported) and no failures/errors. These source-specific feedback runs are separate from final four-child integration acceptance. At that checkpoint, fresh full current-source MySQL/SQLite, frontend/audits, ordinary Chromium/mobile-WebKit, genuine media/retained-graph browsers, strict receipts/aggregate and independent review were still required; final run 373 and separate postmerge run 374 subsequently passed them as recorded above. Strict reviewed publication APIs own their transaction; legacy immediate APIs deliberately retain caller nesting and can inherit an old MySQL Repeatable Read readiness snapshot. New interactive/scheduling callers must use the strict reviewed boundary.

## Browser/operator follow-up — 2026-09-09

PR #28 is merged. [PR #29](https://github.com/VASEYDEV/VASEYAUDIO/pull/29) uses isolated Chromium and mobile-viewport WebKit to exercise actual login, CSRF, create/edit validation, keyboard modal focus, stale forms, retained URLs and named publication blockers over HTTP. The publish confirmation now renders domain blockers as a persistent notification because it has no metadata fields for validation messages. Metadata dialogs focus the modal window, recover initial focus after the opening transition when necessary, and preserve focus already inside the form. See [operator/browser verification](../operator-setup-and-verification.md) and the follow-up PR for actual results. This does not claim physical iPhone, production MFA/recovery or full media-to-publication browser acceptance. Continue WP-03 archive/stem safety after the foundation increment is verified and accepted.

Historical integrated local feedback at `a85bdc54d93cc47773ecbbbf7052917f7e393fa5`, tree `ee97955bb3e116134ac84329a0ca12ee69e62b9a`: 218 fixed operator cases reported, 189 executed / 2,117 assertions, 29 exact MySQL-only skips, zero failures/errors. TypeScript and applicable Python safeguards passed; all nine targeted Chromium journeys passed with zero skips/retries/flaky cases or test/runner errors (207,303.542 ms), including the new publication guard. Original report SHA-256 is `41d74cbc888996c7dec746e2fea24f5ebc062ac1ffe6e43bfb2e62f3f62d67ea`. This does not replace fresh full hosted source-bound acceptance.
