# Editorial and contact content

Status: **Implemented bounded WP-09 increment; final acceptance evidence is maintained in the integrating PR and [issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9).** [D-23](architecture/D-23-editorial-content.md) defines schema compatibility, privacy and publication boundaries. The [ordered record](development-order.md) identifies accepted prerequisites and the next task.

About, contact, blog and video content share the existing complete site release. Saving creates a private immutable draft. Publishing selects that exact version of all pages and navigation together. Editing content later creates another release; it does not overwrite published text or earlier evidence.

## Staff workflow

1. Open **Publishing → Site content** at `/admin/site-releases` as a verified administrator. Choose **New content draft** or **Edit as new draft** on a retained version.
2. Open **About content**, **Contact content**, **Blog content** or **Videos content** and select its **Include … page** checkbox. Enter the bounded page copy and blog/video entries. Use a contact email only if it is intended to be public. New optional pages start disabled when copying an older v1 release.
3. Add enabled sections to navigation when appropriate. Blog and video entries receive detail URLs from their slugs. Save a descriptive release label; the saved draft is immutable.
4. Open **Preview** and follow its internal navigation to review each page and entry. Preview content links remain inside that saved release. Email and provider actions are disabled; preview does not send messages, fetch videos or expose purchase controls.
5. Use **Publish release** to select the saved release. If another operator published while confirmation was open, refresh and reassess before retrying. Publication validates the complete release and retains actor/history/audit.
6. Use **Restore previous release** to recover a previously activated snapshot. Restoring a v1 release also removes these new pages from the selected public site. History and the original baseline remain retained.

Preview is available only to currently authorized staff, with private no-store/noindex responses. It is not a public share link. Keep credentials and customer data out of copy, labels and drafts.

## Content fields

| Content | Fields and limits |
| --- | --- |
| About | Title 120; description 300; one to twelve paragraphs, 1,500 each. |
| Contact | Title 120; description 300; one to twelve paragraphs, 1,500 each; validated public email, at most 254 ASCII characters with no whitespace/control characters. |
| Blog | Listing title 120 and description 300; one to thirty entries with slug, title 120, description 300 and one to twelve paragraphs, 1,500 each. |
| Videos | Listing title 120 and description 300; one to thirty entries with slug, title 120, description 300, YouTube/Vimeo provider and video ID. |
| Navigation | One to eight unique targets from `/`, `/#catalog`, `/#licenses`, `/#studio`, `/about`, `/contact`, `/blog`, `/videos`; optional page targets require an enabled section. |

Text is plain text; HTML, scripts, styling and arbitrary URLs are not accepted. Entry slugs use lowercase ASCII letters/digits and internal single hyphens, at most 80 characters, unique within their section. Optional sections are represented by `null` when disabled. Enabled listings require entries. Blog dates, scheduling, per-entry publication, rich text and editable artwork are not included.

Public pages are `/about`, `/contact`, `/blog` and `/videos`; entries use `/blog/{slug}` and `/videos/{slug}`. Only the active release supplies public text and metadata. Disabled pages or missing entries return not found. Removing or changing a slug in a new release does not create a redirect; preserve intended public slugs deliberately until the later migration/redirect workflow exists.

A YouTube ID is exactly eleven letters, digits, underscores or hyphens. A Vimeo ID is one to twelve digits without a leading zero. These typed IDs create provider watch links. No embed, thumbnail or provider resource loads automatically. A format-valid ID does not verify availability or rights. Contact email creates an encoded mail link that opens the visitor's mail application; the website does not submit an inquiry or verify its delivery. Optional marketing consent is not inferred or collected.

## Existing releases and recovery

Retained schema-v1 home/studio/navigation/footer/SEO releases remain readable and previewable, with unchanged hashes. Copying one promotes only the new editing form to v2; saving creates a separate record. The first publication still retains the original v1 site baseline. Both schemas use the same publication pointer and monotonic revision checks.

Configured corrupt evidence is an error and does not silently fall back to defaults. Preserve records for diagnosis. An application rollback to v1-only code requires selecting a known-good v1 release first with v2-capable code; retain all content and audit history. Content rollback never changes purchased terms, contracts, grants, entitlements or provider objects.

## Verification and remaining scope

Use the repository [verification commands](../README.md#verify). Required checks include schema/link rejection, escaped rendering, active-only public projection and metadata, staff/MFA denial, private pinned previews, v1/v2 compatibility, stale confirmation and rollback, MySQL publication serialization and native Chromium/WebKit workflow. The integrating PR records the exact tested source, actual results, independent review and merge disposition. Test definitions or this guide alone are not acceptance evidence.

This does not import or publish BeatStars content, fabricate a biography/contact address, prove inquiry delivery, embed third-party media or complete WP-09. [Migration field M-041](migration/migration_field_map.csv) still requires authorized source acquisition and source slug/date provenance. The implemented `/blog` route does not activate the older `/updates` redirect proposal. Editable asset references and scheduling are next; inbound contact/spam handling, consent-aware embeds, related-track links, broader sharing/support and free-download licensing/consent remain open.
