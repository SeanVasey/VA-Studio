# D-21 — Versioned site content and atomic publication

Status: **Implementation direction within Sean's continuous-development authorization, 2026-09-28; candidate pending integrated verification and independent review.** This is the bounded WP-09 handoff after D-20 acceptance, not a separately approved owner policy or a production-readiness claim. The [ordered record](../development-order.md) owns prerequisite and final acceptance evidence.

## Context and choice

The storefront currently supplies homepage, navigation, footer and home SEO copy from application code. Track publication, offers and purchased evidence already have separate domain boundaries. The first CMS increment gives staff a persisted copy workflow while preserving those boundaries and the [approved theme and identity assets](../brand/README.md).

Use `App\Domain\SiteBuilder\SiteContent` as the sole application command/read boundary. Save each draft as an immutable schema-v1 `SiteRelease`, preview it privately, then activate its exact content through one versioned singleton pointer. A subsequent edit saves another draft. A release's publication history determines whether it has been published; there is no mutable draft/published status on its content record.

| Option | Assessment |
| --- | --- |
| Continue code-only copy | Keeps the baseline simple, but staff cannot preview and publish without a code deployment. |
| Mutable settings row or independently published sections | Easier writes, but permits lost edits, mixed section versions and weak rollback evidence. |
| Immutable snapshots with one transactional pointer | Chosen: coherent reads, retained revisions and explicit stale-editor rejection using existing infrastructure. |
| External CMS or arbitrary HTML/page builder | Adds provider, asset, sanitization and release coordination work beyond this increment. |

## Content and persistence contract

`SiteContentSchema` accepts only the named plain-text hero, studio, footer, navigation and home SEO fields. Unknown keys, unsupported schema versions, markup/control characters, missing values and excessive lengths fail validation. Navigation has one to four unique targets drawn only from `/`, `/#catalog`, `/#licenses` and `/#studio`. The [operator guide](../site-content-releases.md) records exact field bounds.

Logo geometry, artwork paths, typography, theme, catalog/license presentation and checkout behavior remain application-owned. The copy schema contains no raw HTML, CSS, script, arbitrary URL, upload, secret or customer field. Home canonical/social URLs and imagery remain server-owned; track metadata continues through its existing publication checks.

| Table | Retained contract |
| --- | --- |
| `site_releases` | Immutable label, schema version, content, canonicalization version, SHA-256 content hash, creator and creation time. |
| `site_publications` | Pre-existing singleton `id=1`, monotonic revision and active release ID; initial revision zero has no release and reads code defaults. |
| `site_publication_revisions` | Immutable activation revision, target, previous target, `publish`/`rollback`, content hash, actor and time. |

`publish(releaseId, expectedVersion, actor)` and `rollback(...)` lock the singleton, reload the staff actor, verify the current pointer and candidate schema/hash, and compare the expected revision. They write activation history, advance the pointer and append an audit in one transaction. Revision comparison prevents an old editor from succeeding after an A → B → A sequence. A stale revision or already-active target is rejected. Rollback accepts only a previously activated release; an unpublished draft needs publication. The first publication also captures the exact code-default content as an immutable **Original site content** baseline with revision-zero history, in the same transaction. This makes the pre-CMS homepage available for an ordinary audited rollback after the first publication.

Model protections and MySQL/SQLite triggers retain release/history rows, reject snapshot updates/deletes and constrain pointer/history revision transitions. Restrictive foreign keys preserve referenced releases and actors. These controls do not replace the application authorization boundary or protect against a privileged database administrator disabling guards.

## Authorization, privacy and read behavior

Every create, publish, rollback and preview checks the freshly loaded actor against `administer-catalog` (currently verified administrators). Filament invokes the domain service; hiding an action is not authorization. Preview is an authenticated staff surface with private no-store and noindex treatment, never a public draft selector or bearer-share URL. Its storefront composition suppresses cart, selection and purchase actions. The public homepage reads only the active pointer, once per projection, then its immutable release. Unsaved editor text and other drafts do not enter public props or metadata.

Both preview and public reads verify schema/canonicalization/hash evidence. Missing or corrupt configured content fails closed; it must not silently replace an active release with code defaults. Revision-zero defaults are only the installation baseline. CMS records are copy/audit evidence, not encrypted storage for private business or buyer data. Do not enter credentials or customer details in copy or labels.

## Verification, migration and recovery

Required evidence covers domain/HTTP staff denial, draft isolation, rendered escaping and private headers, schema/link rejection, stale edits and A → B → A conflicts, snapshot/SQL guards, audit transaction rollback, public/SEO projection and recovery to a previously published release. Independent-process MySQL races must prove competing publish/rollback operations serialize; SQLite cannot establish that. Browser evidence must exercise the actual editor, private preview, publish, stale-state recovery and rollback. Test definitions alone do not satisfy these gates; executed commands and tested source belong in the integrating PR and ordered record.

Migration `2026_09_28_000024_site_content_releases.php` adds only CMS tables, guards and the empty singleton; it imports no content and changes no catalog, purchase, contract, grant, entitlement, inventory or provider state. Before the first publication, the baseline copy comes from code. Content recovery activates retained known-good content with a fresh expected revision and retains the faulty release/history. An integrity failure needs evidence diagnosis and exact restore; bypassing guards or deleting history is not recovery.

For application rollback, retain populated CMS tables and audit records. Reverting CMS-aware rendering may display the old code baseline rather than the selected release, so inspect the resulting homepage before deployment. Migration `down()` refuses populated CMS evidence; schema removal is available only while no release evidence exists. Site-content rollback has no payment, contract or customer-delivery effect. No DNS, BeatStars retirement, live payment enablement or provider change is part of this increment.

## Remaining scope and references

This implements only the bounded home/navigation/footer/SEO release direction. Promotion administration, blog/video/contact flows, scheduling, new asset management and broader WP-09 remain subsequent work. Guest recovery, the complete cross-order library, operational payment resolution, other product types, memberships, migration and production readiness remain open in their existing packages.

Framework references: [Laravel 13 pessimistic locking](https://laravel.com/docs/13.x/queries#pessimistic-locking) supports the transaction/row-lock implementation; [Filament 5 resources](https://filamentphp.com/docs/5.x/resources/overview) informs the staff interface. These references describe framework capabilities, not proof that this candidate passed its checks.
