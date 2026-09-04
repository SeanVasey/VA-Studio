# [WP-02] Persistent catalog administration and publication readiness

Status: **Planned work package**. Inspect the current implementation before starting; initial foundation code may already cover part of this scope. This issue is complete only when the acceptance evidence below exists.

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
