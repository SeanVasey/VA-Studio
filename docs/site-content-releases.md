# Site content releases

Status: **Accepted WP-09 increment, merged in PR #72.** [D-21](architecture/D-21-site-content-releases.md) defines the decision and security boundary. The [ordered development record](development-order.md) records the accepted source, full CI, prerequisite acceptance and next handoff. This bounded increment does not complete WP-09.

The CMS saves homepage copy as private immutable drafts. Publishing selects one complete saved version for the public homepage. Saving or previewing a draft does not change the live selection. Track/catalog publication, license offers, payments and customer rights are separate workflows.

## Staff workflow

1. Sign in to the existing seller panel as a verified administrator and open **Publishing → Site content** at `/admin/site-releases`.
2. Choose **New content draft** to start from the current content, or **Edit as new draft** on an existing release. Edit the allowed text and navigation fields, then save a new draft with a descriptive release label. A save creates a retained snapshot; it does not overwrite its source or publish it.
3. Choose **Preview** on the saved release. `/admin/site-releases/{release}/preview` uses the storefront composition with cart, selection and purchase actions suppressed. It remains private. Verify copy, responsive layout and home metadata before publishing.
4. Choose **Publish release** and confirm the exact saved release. The action submits the publication revision captured when the confirmation opened. If another operator changed the selected release, refresh and reassess before retrying; a stale operation cannot overwrite the newer selection.
5. To restore content, choose **Restore previous release** on a previously published inactive snapshot. The restored release becomes active at a new revision; both its original publication and the superseded release remain in history.

Rows distinguish **Active**, **Previously published** and **Private draft**. An active release cannot be activated again. A draft never published cannot be used as a rollback target. First publication retains **Original site content**, an immutable capture of the previous code-default homepage with revision-zero history, so it can be restored like another previous release. Restoration always selects retained content; it never resets the publication revision.

## Editable schema

All values are bounded plain text, with no markup or unsupported keys. Label length is at most 120 characters; the content schema version is integer `1`.

| Content | Fields and maximum lengths |
| --- | --- |
| Hero | Eyebrow 120; title and second line 80 each; description 600. |
| Studio | Eyebrow 120; title and second line 80 each; lead 240; one to four paragraphs, 1,500 each. |
| Footer | Description 300. |
| Navigation | One to four items; label 48; unique destination selected from `/`, `/#catalog`, `/#licenses`, `/#studio`. |
| Home SEO | Title 120; description 300. |

The CMS does not replace official logo geometry, approved artwork, theme or font assets. It cannot add arbitrary links, rich HTML, scripts, price/license changes or new page types. Use copy suitable for publication and keep secrets and customer information out of labels and drafts. A private draft is access-controlled content, not a confidential-record vault.

## Failure handling and retained evidence

Create and activation operations reload staff authorization. Losing the administrator role or email verification invalidates an already-open editor's ability to act. Public requests receive only the active content; the preview requires current staff authorization and sends private no-store/noindex headers.

Each release retains its schema, canonicalization version and content hash. Publication retains the actor, selected/previous release, increasing revision and hash, together with an audit event. A failed activation transaction must leave all three unchanged. A configured release or pointer that fails integrity validation is an error, not permission to fall back silently to code copy. Preserve evidence and restore verified data before retrying.

Content rollback changes only the site pointer and its history/audit. It does not refund, cancel, reprice or alter any purchased license, original contract, entitlement or provider object. Application rollback must retain these tables. The migration refuses rollback once releases exist; old application code may display its own baseline copy instead of the retained active release.

## Verification and remaining work

The accepted candidate passed full CI and independent review in [PR #72](https://github.com/VASEYDEV/VASEYAUDIO/pull/72), including schema/authorization/privacy, immutable database evidence, atomic audit failures, stale revision conflicts, independent MySQL publish/rollback races and browser editing/preview/publication/rollback. The [ordered acceptance record](development-order.md#accepted-downloads-and-cms--september-28-2026) retains exact source, run and results. Follow the repository [verification commands](../README.md#verify) when changing these boundaries.

Test promotion administration is implemented as a bounded WP-09 addition, with acceptance evidence in the integrating PR and issue #9. After its acceptance, persisted contact/about/blog/video flows, scheduling and additional asset controls follow. This increment does not complete WP-09 or the BeatStars replacement; [development order](development-order.md) retains customer recovery/library, payment operations, additional products, memberships, migration and production-readiness work.

## September 28 candidate verification history

Integrated source `89d40c3` passed `npm test` (250 tests) and `npm run build` (TypeScript/Vite). The focused `SiteContent*` suite passed 24 cases /229 assertions with two deliberate MySQL-only skips on PHP 8.4.26 /SQLite. This focused suite is also rerun with built assets: its simulated Inertia requests now send the current asset version, preserving the runtime reload contract. Final CI remains authoritative for full MySQL/SQLite, race, native-browser and audit gates.

Independent source review corrected cross-page licensing navigation that could restart the audio player; navigation now uses Inertia while keeping query/cursor/hash state. The new browser scenario includes a competing editor changing the publication while confirmation is open, denial of the stale confirmation, deliberate recovery and exact prior/baseline restoration. Browser test definitions and local frontend checks do not establish execution of that native flow. The integrating PR records the final source/tree, actual CI and merge disposition; no production content is published by this development run.


## Final acceptance — September 28, 2026

[PR #72](https://github.com/VASEYDEV/VASEYAUDIO/pull/72) merged accepted head `ea212f464bbe0795f4cc844a88e4c2994a584384`, tree `1282b862f0b94500ea5113f0e422fecf1d9bd349`, as `ede206545ab050517bd403e1042ee8c066f5c5b3`. [CI 36471117144](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36471117144) passed all 10 jobs, including both independent-process CMS MySQL races and all 24 Chromium/WebKit cases; full backend, frontend, build and audit results are in the [ordered record](development-order.md#accepted-downloads-and-cms--september-28-2026). Independent review accepted the final source. Post-merge [CI 36475321405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36475321405) also passed. This supersedes the pending status at the earlier component checkpoint without claiming production publication or full WP-09 completion.
