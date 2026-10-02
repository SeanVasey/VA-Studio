# [WP-02] Persistent catalog administration and publication readiness

Status: **PR #28 is merged with audited metadata, stale-form protection and permanent URLs. PR #29 adds operator/browser verification, visible publication blockers and modal focus recovery. The PRs record exact-head results; independent review and broader production/device acceptance remain open.** See [ordered development status](../development-order.md).

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

### Remaining authoring: metadata presets

The [T12 named-preset increment](../verification/track-metadata-presets.md) adds persisted reusable noncommercial metadata, optimistic edits, archive and explicit copied-value review before private draft creation. Fresh staff/MFA locks, minimized transactional audits and ordinary `SaveTrackMetadata` creation retain the authorization/publication boundary. The integrating PR records actual database/native and independent review results; unexecuted definitions are not acceptance. This advances FP-031 without completing broader T12 roles/recovery, catalog/license bulk editing or track schedules/private review.

`SaveTrackMetadata` owns Filament create/edit persistence: verified staff authorization, metadata allowlist, validation, a locked current row, expected revision and transactional minimized audit evidence. A failed audit or invalid published edit rolls back the mutation. `published_slug` reserves the URL through unpublish/republish, with model and MySQL/SQLite triggers blocking rewrites and deletion/reuse. Historical backfill preserves known slugs without inventing dates or edit events. See [catalog administration](../catalog-administration.md).

Targeted tests cover actual Filament create/edit/error actions, changed request actors, direct command denials, stale forms, malformed/extra fields, readiness rollback, public/draft privacy, historical migration and raw SQL URL attacks. The MySQL test requires independent processes and an observed wait on the exact track row; it intentionally skips on SQLite. Definitions are not test results: the PR must record completed CI evidence at its actual head.

Next acceptance work: WP-01 clean boot/operator diagnostics and WP-02 real-browser save/error flows, followed by remaining WP-03 media and WP-04 rights dependencies. Fine-grained roles and production MFA/recovery remain explicit gates; the current gate is verified `is_admin` staff. No work package is closed by this increment.

### Verification record

[PR #28](https://github.com/VASEYDEV/VASEYAUDIO/pull/28) records the tested source SHA/tree and completed results for `php artisan test` on MySQL 8.4 and SQLite, `npm test`, `npm run build`, `composer validate --strict`, `composer audit --no-dev` and `npm audit --omit=dev --audit-level=high`. Local `git diff --check` passed; PHP/Composer and frontend dependencies were unavailable locally.

The first candidate (`2c5279faa291443d3bec97e101fa51266fde1b74`) passed 205 MySQL tests, including the observed lock race, and failed two admin assertions: relative command errors did not render under Filament's mounted form path. The correction maps domain validation keys to the actual schema path; the assertions remain intact. This first run is diagnostic evidence, not final acceptance. Consult the PR's final verification table for the completed candidate and remaining review/browser gates.

## Browser/operator follow-up — 2026-09-09

PR #28 is merged. [PR #29](https://github.com/VASEYDEV/VASEYAUDIO/pull/29) uses isolated Chromium and mobile-viewport WebKit to exercise actual login, CSRF, create/edit validation, keyboard modal focus, stale forms, retained URLs and named publication blockers over HTTP. The publish confirmation now renders domain blockers as a persistent notification because it has no metadata fields for validation messages. Metadata dialogs focus the modal window, recover initial focus after the opening transition when necessary, and preserve focus already inside the form. See [operator/browser verification](../operator-setup-and-verification.md) and the follow-up PR for actual results. This does not claim physical iPhone, production MFA/recovery or full media-to-publication browser acceptance. Continue WP-03 archive/stem safety after the foundation increment is verified and accepted.
