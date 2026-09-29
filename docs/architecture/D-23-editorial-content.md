# D-23 — Persisted editorial and contact content

Status: **Implemented WP-09 contract within Sean's continuous-development authorization, 2026-09-29; acceptance is recorded against the final tested commit in the integrating PR and [issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9).** This increment follows accepted promotion administration [PR #73](https://github.com/VASEYDEV/VASEYAUDIO/pull/73). It does not establish production publication, completed content migration or full WP-09 acceptance.

## Context and choice

[D-21](D-21-site-content-releases.md) supplies immutable private content releases and one versioned publication pointer. Extend that same release with bounded about, contact, blog and video content. Staff can prepare and preview a coherent set of pages, publish it atomically and restore a previous whole-site version. `App\Domain\SiteBuilder\SiteContent` remains the command/read boundary; Filament and React do not implement independent publication rules.

Use schema version `2` for newly authored releases. Preserve stored schema-v1 content and its original hash without rewriting it. `SiteContentSchema::forEditing` validates and promotes an in-memory v1 copy to v2 and adds disabled editorial sections; saving produces a new immutable release. A v1 release remains readable, previewable, publishable and eligible for ordinary history-based rollback. Restoring v1 removes the new editorial routes from the selected public site.

| Choice | Consequence |
| --- | --- |
| Extend the complete immutable release | Navigation, page text and entry content activate together under the existing expected-revision check and audit transaction. |
| Independent mutable articles or per-entry publication | Deferred: separate statuses, mixed versions and publication scheduling need their own lifecycle contract. |
| Plain text and typed provider IDs | No stored HTML, arbitrary embed code, remote fetch or new sanitization dependency. |
| Retain v1 rather than rewrite old snapshots | Existing hashes, original baseline, publication history and rollback evidence retain their original meaning. |

## Schema and route contract

Schema v2 keeps the existing hero, studio, footer and home SEO fields. It adds the required keys `about`, `contact`, `blog` and `videos`. Each section is either `null` or a fully valid object; `null` disables its routes. Empty objects are not substitutes for disabled sections.

| Section | Bounded content |
| --- | --- |
| About | Title, description and one to twelve paragraphs. |
| Contact | Title, description, one to twelve paragraphs and a publication-ready email address. |
| Blog | Title, description and one to thirty entries. Each entry has a unique slug, title, description and one to twelve paragraphs. |
| Videos | Title, description and one to thirty entries. Each entry has a unique slug, title, description, provider and provider-specific video ID. |

Titles are at most 120 characters, descriptions 300 and each paragraph 1,500. Slugs are at most 80 characters, using lowercase ASCII letters/digits with single internal hyphens. Entry identity is unique within its section. The schema rejects unknown fields, unsupported versions, markup/control characters and malformed list shapes. Blog entries have no publication date or individual publish status in this increment. A URL or externally supplied timestamp is not imported provenance.

V2 navigation permits one to eight unique destinations from the four existing home targets plus `/about`, `/contact`, `/blog` and `/videos`. A new destination requires its section to be enabled. Detail routes come from validated entry slugs; they are not arbitrary navigation URLs.

| Public route | Active content |
| --- | --- |
| `/about`, `/contact` | The selected release's enabled page. |
| `/blog`, `/videos` | The selected release's enabled entry listing. |
| `/blog/{slug}`, `/videos/{slug}` | One exact entry from the selected release. |

Disabled sections, unknown slugs and absent entries return not found. Public requests resolve one current release and project only the requested page or list plus shared navigation/footer chrome. They do not serialize private releases, unrelated entry bodies or the complete site snapshot. Canonical/Open Graph URLs are derived by the server from the active public route; copy supplies only the permitted title and description. Existing theme, logo, artwork and font assets remain fixed.

The implemented blog root is `/blog`. The migration ledger's earlier `/blog` → `/updates` row remains a historical route proposal, not a deployed redirect or evidence of source acquisition. No redirect or legacy slug claim is introduced here.

## Contact and video boundaries

Contact email is at most 254 ASCII characters, contains no whitespace/control characters and must pass email validation. Published contact content produces a `mailto:` link by percent-encoding the complete validated address, preventing its interpretation as query/header input. It does not accept, store or send an inquiry, verify an inbox, supply spam prevention or capture marketing consent. Operators must use an address they intend to publish. Development fixtures do not establish Sean's production contact details.

Videos accept only the typed providers `youtube` and `vimeo`. YouTube IDs contain exactly eleven ASCII letters, digits, underscores or hyphens. Vimeo IDs contain one to twelve digits, start with a nonzero digit and have no leading zero. The server derives a canonical provider watch URL. Rendering uses an ordinary outbound link, with no iframe, remote thumbnail, provider script, autoplay, server fetch or assumed tracking consent. A valid ID format does not establish that a video exists, is owned by Sean or may be embedded. Consent-aware embeds, related-track associations and media asset references remain later work.

Private previews disable both email and external video actions. Public navigation remains deliberate user navigation; saving or previewing content never contacts a provider or sends a message.

## Preview, authorization and publication

The existing staff preview gains the corresponding page/list/detail paths beneath `/admin/site-releases/{release}/preview`. Every request rechecks current staff authorization and panel MFA, uses private no-store/noindex treatment and resolves that exact immutable release. Preview content navigation, entry links and back links stay pinned to the same release, even if another operator publishes a different release. Public routes have no draft/version selector. Preview composition continues to suppress catalog purchase actions.

Creation, publication and rollback retain D-21's freshly loaded verified-administrator checks, singleton lock, monotonic expected revision, A → B → A stale rejection, immutable hashes, publication history and atomic audit. No separate editor path may write a published record. A configured release that fails schema/hash verification fails closed; code defaults are only the unconfigured installation baseline. Commerce, licenses, grants, contracts, entitlements and provider state are outside these commands.

## Compatibility, recovery and acceptance

There is no new migration or content import. The existing release table already retains schema version, canonical JSON and hash; application validation supports exactly v1 and v2 and checks that row and payload versions agree. Existing retention guards and restrictive references remain in force. First publication still captures the original schema-v1 code baseline. Copy promotion does not alter it.

Content recovery uses ordinary audited publication or rollback with a fresh expected revision. Restoring a retained v1 release hides editorial routes and restores its original navigation/copy. Removing an entry in a new active snapshot makes that detail route unavailable; automatic redirects and permanent slug reservation are not supplied. Retained old releases remain private unless explicitly selected through the authorized publication workflow.

For application rollback to code that understands only v1, first restore a verified v1 release while the v2-capable application is running. Preserve every release, publication and audit record. Older readers fail closed on v2; reverting code is not a content migration or permission to delete retained evidence.

Required verification covers strict schema/provider/email/navigation validation, v1 compatibility and hash preservation, active-only public routes and metadata, direct private-route denial, preview pinning/privacy, rendered escaping, fresh authorization, stale publication, rollback across versions and unchanged commerce records. Existing independent-process MySQL publication races remain required; SQLite alone cannot establish serialization. Chromium/WebKit must exercise real authoring, preview, publication and rollback. Full CI and independent review must assess the final integrated source; this document does not claim unexecuted checks passed.

After acceptance, continue editable asset references and scheduling. Inbound contact delivery/spam protection, consent-aware embeds, source content/slug/date migration, broader sharing/support, free-download licensing/consent and the rest of WP-09 remain open. Guest recovery, complete customer library, operational payment resolution, other products, memberships and production readiness remain in their existing packages.
