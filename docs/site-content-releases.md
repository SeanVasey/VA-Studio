# Site content releases

Status: **Accepted WP-09 increment, merged in PR #72.** [D-21](architecture/D-21-site-content-releases.md) defines the decision and security boundary. The [ordered development record](development-order.md) records the accepted source, full CI, prerequisite acceptance and next handoff. [Scheduled publication](#scheduled-publication) follows under [D-24](architecture/D-24-scheduled-site-publication.md); its integrating PR records acceptance. These bounded increments do not complete WP-09.

The accepted original CMS saves homepage copy as private immutable drafts. [Editorial/contact content](editorial-content.md) extends new schema-v2 drafts to optional about, contact, blog and video pages while preserving stored v1 releases. Publishing selects one complete saved version for the public homepage. Saving or previewing a draft does not change the live selection. Track/catalog publication, license offers, payments and customer rights are separate workflows.

## Staff workflow

1. Sign in to the existing seller panel as a verified administrator and open **Publishing → Site content** at `/admin/site-releases`.
2. Choose **New content draft** to start from the current content, or **More → Edit as new draft** on an existing release. Each row shows **Preview** and **Publish release** directly; the other actions are in its **More** menu. Edit the allowed text and navigation fields, then save a new draft with a descriptive release label. A save creates a retained snapshot; it does not overwrite its source or publish it.
3. Choose **Preview** on the saved release. `/admin/site-releases/{release}/preview` uses the storefront composition with cart, selection and purchase actions suppressed. It remains private. Verify copy, responsive layout and home metadata before publishing.
4. Choose **Publish release** and confirm the exact saved release. The action submits the publication revision captured when the confirmation opened. If another operator changed the selected release, refresh and reassess before retrying; a stale operation cannot overwrite the newer selection.
5. To restore content, choose **More → Restore previous release** on a previously published inactive snapshot. The restored release becomes active at a new revision; both its original publication and the superseded release remain in history.

Rows distinguish **Active**, **Previously published** and **Private draft**. An active release cannot be activated again. A draft never published cannot be used as a rollback target. First publication retains **Original site content**, an immutable capture of the previous code-default homepage with revision-zero history, so it can be restored like another previous release. Restoration always selects retained content; it never resets the publication revision.

## Site images

[D-25](architecture/D-25-editable-site-images.md) makes four images editable: the home hero (desktop and mobile), the studio image and the share image.

1. Upload the image under **Publishing → Site images**, choosing its place and giving a credit line and a rights confirmation. It is scanned and prepared in the sizes that place needs. Only **Ready** images can be used.
2. In a content draft, the **Images** section offers the ready images for each place, or the built-in image. Set both hero images or neither, and describe each image for people who cannot see it. The editor warns when a hero image is bright where the heading sits; check the preview.
3. The share image is what social platforms show when a page is shared. Without one, pages share the hero in use.
4. Preview the release. Its images come from the private preview route and are not public yet.
5. Publishing checks every stored file of the release's images first.

Once a release that uses an image is published, the image is public at its own content-hashed address, and browsers may keep it for a year. Restoring another release stops the site using it but does not withdraw copies that browsers or platforms already hold.

A release without images is saved exactly as before, so code that predates site images can still read it.

## Scheduled publication

[D-24](architecture/D-24-scheduled-site-publication.md) lets staff choose one saved release and a future time. Times are UTC.

1. Choose **More → Schedule publication** on a saved release that is not active. Enter a whole minute at least one minute and at most 365 days ahead; the form shows the current UTC time. The page heading and the release's **Schedule** badge then show the pending schedule. Saving the schedule does not change the live site.
2. At that time the scheduler publishes the exact saved release with the same checks as **Publish release**. It is recorded as a publication by the administrator who scheduled it.
3. **Cancel scheduled publication** cancels only the schedule named in its confirmation. The live site does not change.
4. Publishing or restoring any release while a schedule is pending replaces the schedule. The confirmation says so first, and the history records which staff action replaced it.
5. **Schedule history** lists the last 20 schedules with their outcome, actors and times.

Only one schedule can be pending. A schedule never publishes, and records why, when:

- no scheduler run reached it within 60 minutes of its time (the next run records it as expired);
- the scheduling account is no longer a verified administrator, or has no MFA enrolled where the admin panel requires it;
- the release or publication fails its integrity checks;
- the live site changed through a path that did not replace the schedule.

Every outcome is retained. Schedule again if the change is still wanted.

The scheduler must run every minute on the production host, which is not chosen yet (U-02):

```sh
* * * * * cd /path/to/vaseyaudio && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` confirms the registration. `php artisan vasey:publish-scheduled-site-release` runs one pass by hand; D-24 lists its output and exit codes. `php artisan vasey:doctor` warns when a pending schedule is more than two minutes overdue, which usually means cron is not running; runner errors are written to the application log. Scheduled tasks do not run while the application is in maintenance mode.

## Original schema-v1 fields

All values are bounded plain text, with no markup or unsupported keys. Label length is at most 120 characters. These are the original integer schema-version `1` fields; new editor copies use schema version `2` and its additional bounded fields.

| Content | Fields and maximum lengths |
| --- | --- |
| Hero | Eyebrow 120; title and second line 80 each; description 600. |
| Studio | Eyebrow 120; title and second line 80 each; lead 240; one to four paragraphs, 1,500 each. |
| Footer | Description 300. |
| Navigation | One to four items; label 48; unique destination selected from `/`, `/#catalog`, `/#licenses`, `/#studio`. |
| Home SEO | Title 120; description 300. |

The CMS does not replace official logo geometry, approved artwork, theme or font assets. It cannot add arbitrary links, rich HTML, scripts or price/license changes. V2 adds only the named editorial/contact page types and validates their navigation targets. Use copy suitable for publication and keep secrets and customer information out of labels and drafts. A private draft is access-controlled content, not a confidential-record vault.

## Failure handling and retained evidence

Create and activation operations reload staff authorization. Losing the administrator role or email verification invalidates an already-open editor's ability to act. Public requests receive only the active content; the preview requires current staff authorization and sends private no-store/noindex headers.

Each release retains its schema, canonicalization version and content hash. Publication retains the actor, selected/previous release, increasing revision and hash, together with an audit event. A failed activation transaction must leave all three unchanged. A configured release or pointer that fails integrity validation is an error, not permission to fall back silently to code copy. Preserve evidence and restore verified data before retrying.

While that is the case, pages that read site content answer a generic, uncacheable `503` with `Retry-After: 60`. The failure never becomes a redirect and never shows code defaults. The application log records `Published site content is unavailable.` at critical level with the reason, revision and release id: at most once a minute while the cache works, and on every request when it does not. **New content draft** reports the failure instead of opening, and says which of these recoveries applies:

- `release`: the active release failed its checks, or an image it uses did. Publish or restore an intact release; **More → Edit as new draft** on a saved release gives you one to publish.
- `publication`: the publication record or its history failed its checks, so publishing and restoring are refused with *The retained site publication failed its integrity check*. Restore verified database data from a backup before retrying.
- `missing`: the publication record is gone. The guards refuse a `DELETE`, but a MySQL `TRUNCATE` skips them. Staff cannot recover it in the panel, where the Site Releases page may answer Not Found: restore verified data from a backup. Re-creating the empty record by hand does not help. Once the site has been published, an empty record fails its check too and reports `publication`; the original copy never comes back.

A damaged stored file of a site image breaks only that image: the page still renders and shows the image's description, and `vasey:doctor` warns (`site_images`). Restore `storage/app/private/site-images/` from the same backup as the database, or publish a release that uses another image.

Content rollback changes only the site pointer and its history/audit. It does not refund, cancel, reprice or alter any purchased license, original contract, entitlement or provider object. Application rollback must retain these tables. The migration refuses rollback once releases exist; old application code may display its own baseline copy instead of the retained active release.

## Verification and remaining work

The accepted candidate passed full CI and independent review in [PR #72](https://github.com/VASEYDEV/VASEYAUDIO/pull/72), including schema/authorization/privacy, immutable database evidence, atomic audit failures, stale revision conflicts, independent MySQL publish/rollback races and browser editing/preview/publication/rollback. The [ordered acceptance record](development-order.md#accepted-downloads-and-cms--september-28-2026) retains exact source, run and results. Follow the repository [verification commands](../README.md#verify) when changing these boundaries.

Test promotion administration is accepted in PR #73. [D-23](architecture/D-23-editorial-content.md) persisted contact/about/blog/video content is accepted in PR #74. [D-24](architecture/D-24-scheduled-site-publication.md) adds scheduled publication, with acceptance evidence in its integrating PR and issue #9. Editable asset references follow; contact delivery, consent-aware embeds and migration remain open. This increment does not complete WP-09 or the BeatStars replacement; [development order](development-order.md) retains customer recovery/library, payment operations, additional products, memberships, migration and production-readiness work.

## September 28 candidate verification history

Integrated source `89d40c3` passed `npm test` (250 tests) and `npm run build` (TypeScript/Vite). The focused `SiteContent*` suite passed 24 cases /229 assertions with two deliberate MySQL-only skips on PHP 8.4.26 /SQLite. This focused suite is also rerun with built assets: its simulated Inertia requests now send the current asset version, preserving the runtime reload contract. Final CI remains authoritative for full MySQL/SQLite, race, native-browser and audit gates.

Independent source review corrected cross-page licensing navigation that could restart the audio player; navigation now uses Inertia while keeping query/cursor/hash state. The new browser scenario includes a competing editor changing the publication while confirmation is open, denial of the stale confirmation, deliberate recovery and exact prior/baseline restoration. Browser test definitions and local frontend checks do not establish execution of that native flow. The integrating PR records the final source/tree, actual CI and merge disposition; no production content is published by this development run.


## Final acceptance — September 28, 2026

[PR #72](https://github.com/VASEYDEV/VASEYAUDIO/pull/72) merged accepted head `ea212f464bbe0795f4cc844a88e4c2994a584384`, tree `1282b862f0b94500ea5113f0e422fecf1d9bd349`, as `ede206545ab050517bd403e1042ee8c066f5c5b3`. [CI 36471117144](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36471117144) passed all 10 jobs, including both independent-process CMS MySQL races and all 24 Chromium/WebKit cases; full backend, frontend, build and audit results are in the [ordered record](development-order.md#accepted-downloads-and-cms--september-28-2026). Independent review accepted the final source. Post-merge [CI 36475321405](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36475321405) also passed. This supersedes the pending status at the earlier component checkpoint without claiming production publication or full WP-09 completion.
