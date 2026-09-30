# D-25 — Editable site images

Status: **Implemented WP-09 contract in two PRs within Sean's continuous-development authorization, 2026-09-30; acceptance is recorded against the final tested commit of each integrating PR and [issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9).** Sean chose the slots and approved the defaults below on 2026-09-30. Part 1 adds the private image library. Part 2 adds image slots to site releases, serves live images publicly and renders them. Nothing here configures a production scanner, queue worker or host (U-02), publishes an image or completes WP-09.

## Context and choice

The storefront's editable copy lives in site releases ([D-21](D-21-site-content-releases.md), [D-23](D-23-editorial-content.md)), but its imagery is fixed files in `public/images`:

| Slot | Current file | Used in |
| --- | --- | --- |
| Home hero, desktop | `storefront-hero.jpg`, 2400 × 890 | the storefront hero `<picture>` |
| Home hero, mobile | `storefront-hero-mobile.jpg`, 960 × 890 | the same `<picture>`, below 700 px |
| Studio image | `video-studio.jpg`, 1440 × 630 | the storefront studio section |
| Share image | the desktop hero | `og:image` and `twitter:image` |

The track media pipeline cannot hold these images: `media_assets.track_id` is required, and intake, processing, verification and public serving all assume a published track.

| Option | Assessment |
| --- | --- |
| Reuse `media_assets` with an optional track | Widens a guarded commerce table, its triggers and every track-bound check. |
| Files committed to the repository or a public disk | No scan, provenance or staff workflow, and public before any release uses them. |
| A separate site-image library, with slot-specific variants pinned by a manifest in site releases | Chosen: reuses the scanner, bounded FFmpeg runner and private storage, and keeps images private until a live release references them. |
| Image fields throughout content | Deferred: blog and video thumbnails are out of scope. |

## Approved defaults

1. **Image lifetime.** Once a release that uses an image has been live, that image stays reachable at an unguessable, content-hashed URL cached for a year. Rolling back stops the site linking to it, but cannot recall copies that browsers, CDNs or social platforms saved. An audited withdrawal action can come later.
2. **Size and shape.** Each slot has a fixed shape. An upload must match it within 3% and be at least as large as the largest size shown, so nothing is enlarged. Staff crop hero and studio images themselves; only the share image is trimmed automatically. The two hero images are set or cleared together.
3. **Upload checks.** Transparency, CMYK, more than 8 bits per channel, rotation tags, formats other than JPEG and PNG, and files over 20 MiB are refused. Every file is scanned, re-encoded and stripped of metadata. The original upload is never served, not even to staff.
4. **Provenance.** Every upload records a source or credit line and a confirmation that we hold the rights. Both are required and kept permanently with the uploader.
5. **Hero contrast warning.** The editor warns when a hero image is bright where the white heading sits, which is printed straight onto it. The preview is where staff confirm it.
6. **Built-in images stay selectable.** A slot shows the built-in image until a release sets one; without a share image, the share image follows the hero in use.

## Slots and prepared sizes

| Slot | Shape | Accepted upload | Prepared files |
| --- | --- | --- | --- |
| `hero_desktop` | 2400 × 890, about 2.70:1 | at least 2400 px wide | JPEG and WebP, 1200, 1800 and 2400 px wide |
| `hero_mobile` | 960 × 890, about 1.08:1 | at least 960 px wide | JPEG and WebP, 480, 720 and 960 px wide |
| `studio` | 1440 × 630, about 2.29:1 | at least 1440 px wide | JPEG and WebP, 720, 1080 and 1440 px wide |
| `share` | 1200 × 630, about 1.90:1 | at least 1200 × 630 | one JPEG, trimmed from the centre to exactly 1200 × 630 |

Heights keep the upload's own shape: each height is the width times the upload's height divided by its width, rounded. Uploads are at most 6000 px on a side (`media.max_artwork_dimension`).

Profile `site-image-v1` fixes the encoding. The upload is first re-encoded to a sanitized PNG by the track artwork step. Each output then drops all frame side data (which carries an ICC profile), scales with Lanczos and writes bitexact output with no encoder comment:

- JPEG: `mjpeg`, `-q:v 3`, full-range 4:2:0 (`yuvj420p`);
- WebP: `libwebp`, quality 80, compression level 4.

The profile's fingerprint is recorded with each image. It covers the profile version, the slot definitions, the shape tolerance and the encoder settings recorded in `SiteImageSlot::ENCODING`. The per-format settings above (codec, quality, pixel format, compression level) build the encoder arguments, so changing one changes the fingerprint. The recorded `scaler`, `metadata` and `bitexact` entries only describe choices fixed in code, and the fingerprint does not cover that code: the rest of the FFmpeg command in `SiteImageDerivatives`, including its filter chain (side-data removal, Lanczos scaling and the share image's crop), `-map_metadata -1` and the bitexact flags; the mapping from each setting to its FFmpeg option (`ENCODER_OPTIONS`); and the sanitizing PNG step. Changing any of them can change the bytes without changing the fingerprint, so it needs a new profile version, which does change it. The FFmpeg and ffprobe versions are recorded in the evidence, not in the fingerprint. There is no colour management: pixel values are kept and any colour profile is dropped, so staff should export sRGB.

## Persistence contract

Migration `2026_09_30_000027_site_images.php` adds two tables and changes no existing table, trigger or row.

| `site_images` column | Contract |
| --- | --- |
| `slot` | One of the four slots. |
| `original_name` | The client's file name: last path segment, control characters removed, at most 240 characters. Display only. |
| `source_path`, `source_sha256`, `size_bytes`, `mime_type`, `width`, `height` | The quarantined upload under `site-images/quarantine/<uuid>/source.upload`, as measured at intake. |
| `credit`, `rights_confirmed_at`, `uploaded_by` | Provenance: 1–200 characters of plain text, the confirmation time and the uploader. |
| `status`, `attempts`, `claim_token`, `claimed_until`, `failure_code` | `quarantined` (shown as Waiting), `processing`, `ready` or `failed`; the processing lease and the last failure. |
| `profile_version`, `profile_fingerprint`, `evidence`, `manifest_sha256`, `processed_at` | Set when processing ends. Evidence holds the scan result, decoded pixel format and tool versions. |

`site_image_variants` holds each prepared file: format, width, height, private `storage_path` under `site-images/revisions/<uuid>/`, SHA-256 and size.

SQLite and MySQL triggers forbid deleting either table's rows. A new image must be quarantined, with no processing fields, an allowed slot and type, a quarantine path and a non-blank credit. The identity and provenance columns never change. On MySQL the triggers compare text byte for byte, as SQLite does. A change of case, accents or trailing spaces, which MySQL's default collation ignores, therefore counts as a change, and `Studio` is not a slot. The only allowed moves are:

- claim: from Waiting, or from Processing with a new token, adding one attempt and clearing the last failure;
- return to Waiting with a failure code;
- fail with a failure code and a processing time;
- become ready with a manifest, profile and evidence, and exactly the slot's variant count (6, or 1 for the share image).

Ready and failed rows are immutable. Variants can be added only while their image is processing, only under `site-images/revisions/`, and never change. `down()` refuses to drop populated tables. These guards back up the application boundary; they do not stop a privileged database administrator.

The ready trigger hard-codes today's variant counts. A profile that changes a slot's sizes therefore needs a new profile version and a new migration that replaces the trigger. Images that are already ready keep the variant set their manifest pins; verification does not compare it with the slot's current sizes.

## Manifest

`manifest_sha256` is the canonical-JSON SHA-256 (the site-release canonicalization) of:

```
{ "profile": <profile fingerprint>, "slot": <slot>,
  "variants": [ { "format", "width", "height", "sha256", "size_bytes" }, … sorted by format, then width ] }
```

It is recomputed from the variant rows whenever an image is used or served. Only those rows count, so a later profile leaves existing ready images valid. Release references pin `{id, manifest}`, so a release can only show the exact bytes it was reviewed with.

## Intake

`App\Application\SiteBuilder\IngestSiteImage`:

1. Checks `administer-catalog` and the admin MFA rule.
2. Validates the slot, the credit (trimmed, 1–200 characters, no control characters or angle brackets) and the rights confirmation.
3. Requires a freshly uploaded file. A path to an existing object is never accepted. The file must be 12 bytes to 20 MiB, not a symlink, a JPEG or PNG by content, readable by `getimagesize` with a matching type, and within 6000 px.
4. Checks the slot's shape and minimum size.
5. Checks the headers: JPEG component count, bit depth and the orientation in each EXIF block before the image data; PNG bit depth, alpha or a transparency chunk before the image data, and the orientation in every eXIf chunk up to IEND, including any after the image data. Readers disagree about which of several blocks counts (FFmpeg keeps the last), so any block that rotates or flips the image refuses it. The JPEG reader skips stray bytes before a marker, as libjpeg does. It reads only APP1 segments starting `Exif\0\0` among the markers before the first scan. EXIF placed after the scan data, inside the payload of a segment FFmpeg does not parse (such as DNL), or behind `Exif` followed by anything but two zero bytes passes intake. FFmpeg reads those, so the prober reports the orientation at processing, which fails the image as `rotated_image`. FFmpeg does not read PNG eXIf at all, wherever it sits, so a PNG eXIf chunk over 64 KiB cannot be checked and is refused as `oversized_metadata`, and a file cut off inside an eXIf chunk is refused. A PNG that ends at a chunk boundary after its image data, without IEND, is accepted, as FFmpeg decodes it; one that reaches IEND before any image data is refused, as FFmpeg reads no frame from it. One that runs off its end is refused, as the prober and the sanitizer would refuse it: a chunk cut short, one claiming more bytes than remain, or stray bytes too few for a chunk header. Every chunk is at least 12 bytes, so the 20 MiB limit bounds the walk. A refused upload leaves nothing behind, while a failed row is kept for good, so problems visible in the headers are caught here.
6. Stores the quarantined copy and verifies its size and hash. It then creates the row and the `site.image.uploaded` audit in one transaction. If that fails before its commit, the transaction rolls back and the copy is removed. Once the commit has started, the copy is kept even if the commit reports an error, because the commit may have been applied; at worst it is an orphan. After the commit it queues `ProcessSiteImage` on the `media` queue. If the queue is unavailable, the error is reported, the upload is kept, and the image stays Waiting for **Retry processing**.

## Processing and failures

`SiteImageProcessor`:

1. Claims the image under a 16-minute lease, longer than the 15-minute job timeout. Taking over an expired lease first records `site.image.retry_pending` with `processing_interrupted`, the interrupted attempt and the lease's end.
2. Snapshots the upload and rechecks its hash, size and type.
3. Scans it. Only ClamAV evidence is accepted outside tests, and only a completed scan is a verdict on the file: a detection (clamscan exit 1) is `scan_not_clean`. A scanner error, crash or time-out, a missing tool, a binary that is not ClamAV, standard output cut off at the 256 KiB limit or warnings on standard error past 4 MiB, or an exit 0 without the exact clean line (for example with a warning of clamscan's own in the output) says nothing about the file, so the image waits. Fewer warnings than that are diagnostics and do not affect the verdict. Without that exact line the image never becomes ready.
4. Repeats the header checks on the scanned snapshot, then reads the decoded frame with bounded `ffprobe`. The pixel format and dimensions must match intake, and a JPEG must not carry a rotation. FFmpeg 6.1 does not read PNG eXIf, so for PNG the header check is the only orientation check. The accepted 8-bit formats include planar RGB (`gbrp`), which an RGB JPEG marked with Adobe transform 0 decodes to.
5. Re-encodes it to a sanitized PNG and refuses palette transparency found there.
6. Prepares and verifies every variant.
7. Promotes the variants to private storage.
8. In one locked transaction checks that its claim is still held, inserts the variants, records the manifest and marks the image ready.

It never throws for a processing outcome, so an upload handled by a synchronous queue still completes. A run that lost its claim writes nothing and removes the files it promoted. Cleanup follows the ready transaction rather than a lookup. Until that transaction reaches its commit, no row references the run's promoted files, so an error before it (a failed promotion) or inside it (a lost claim, a refused insert or transition, which roll it back) removes them. Once the commit has started, the files are kept even if the commit reports an error. The commit may have been applied, and after a dropped connection a lookup from the reconnected session can run before the server has finished applying it. Orphaned files are the accepted cost. An error during cleanup is reported.

Problems with the environment return the image to Waiting for an audited retry; problems with the file fail it for good.

| Kind | Codes | Result |
| --- | --- | --- |
| Temporary | `scanner_unavailable` (the scanner is missing, fails, crashes or is not ClamAV, its output runs past its limits, or it exits 0 without the exact clean line), `scanner_signatures_stale`, `tool_unavailable`, `processor_timeout` (including a scan that runs out of time), `storage_failed`, `unsafe_storage` (a served or public disk, or a storage directory that is a symbolic link), `missing_source`, `processing_interrupted` (an unexpected error, reported to the log, or a worker that stopped while holding its claim) | Back to Waiting. The job retries after 30 seconds and again after 2 minutes; after that, staff choose **Retry processing**. |
| Permanent | `scan_not_clean` (a detection), `source_changed`, `invalid_image`, `invalid_artwork`, `rotated_image`, `oversized_metadata`, `transparent_image`, `unsupported_depth`, `unsupported_pixel_format`, `processor_failed`, `processor_output_limit`, `unsafe_path` (an unsafe key, or a symbolic link in place of the upload itself), `invalid_size` | Failed and kept. Staff export the image again and upload a new copy. |

A worker that dies holding a claim leaves the image in Processing until its lease expires. After that, the list shows it as Interrupted with the `processing_interrupted` explanation, **Retry processing** is offered, and any worker may take it over. The takeover records the interrupted attempt as `site.image.retry_pending` before its own `site.image.processing`. Audit events: `site.image.uploaded`, `site.image.processing`, `site.image.processed`, `site.image.failed`, `site.image.retry_pending` and `site.image.retry_requested`.

## Staff interface

**Publishing → Site images** lists every upload:

- preview thumbnail and id;
- slot;
- status (Waiting, Processing, Interrupted, Ready or Failed) and the number of attempts;
- size, file name and credit;
- a plain-language problem;
- uploader and upload time (UTC).

It refreshes every 5 seconds. **Upload site image** asks for the slot (showing its size requirement), the file, the credit and the rights confirmation. **Retry processing** appears only for waiting images and expired claims. There is no edit or delete. The page and every Livewire request recheck the role and MFA.

`GET /admin/site-images/{variant}/preview` requires the panel's authentication and MFA middleware and `administer-catalog`, and is throttled to 240 requests a minute. It serves a variant only when its image is ready and its manifest matches, hashing the file's bytes on every request and sending exactly the bytes it hashed. Every response, including authentication failures and 404s, is `no-store, private`, `nosniff`, `noindex, nofollow` and `no-referrer`.

## Image slots in site releases (part 2)

**Schema version 3** is version 2 plus `images`:

```
images: {
  hero:   { desktop: { id, manifest }, mobile: { id, manifest }, alt } | null,
  studio: { id, manifest, alt } | null,
  share:  { id, manifest, alt } | null
}
```

`null` keeps the built-in image. Descriptions are 1–200 characters of plain text and required with an image. Ids are integers. The release hash covers the references, so a release pins its exact images.

The editor writes version 3 **only when a release uses an image**. An image-free draft stays version 2, so code that predates site images can still read it if the application is rolled back. Retained version 1 and 2 releases never change. `forEditing()` still upgrades only version 1 to 2.

**Creating a release.** `SiteContent::create()` pins each reference inside its transaction:

- It locks the image row, which must be a ready image of the slot with an intact manifest and accepted scan evidence.
- It reads the variants with a locking read and records the image's manifest. A manifest the caller supplied must match.
- It writes one `site_release_images` row per slot.

A MySQL race test found that a plain read of the variants could use a snapshot taken before the lock waited for the image's completion. It then saw a ready image without its variants and refused it. The locking read always sees the latest commit. A release created while its image completes either pins the whole manifest or is refused; it never pins part of one.

**The release image index.** `site_release_images` (release, slot, image) is insert-only. Triggers on both engines accept a row only when:

- the image is ready and of the same slot;
- the release is version 3;
- the release has never been scheduled or published.

Every read of a version 3 release checks that the index lists exactly the release's references.

**Reads.** `content()` accepts versions 1–3. For version 3 it checks, from the database only, that every referenced image is ready and of its slot. Its recomputed manifest must equal the pinned one, and its scan evidence must be accepted. A failure is a `release` integrity failure, so public pages answer 503 and staff restore another release. The pointer check never looks at images, so restoring away from a broken release always works.

**Publishing.** Publish and restore check the stored files of the target release's images before taking the publication lock, hashing every variant. The scheduler does the same in its unlocked probe and records a failure as its existing `integrity` outcome.

**Public serving.** `GET /site-images/{sha256}.{jpg|webp}` serves a variant only when:

- its image is referenced by a release that has publication history;
- its manifest still matches;
- the bytes read now match its hash.

It answers with `public, max-age=31536000, immutable` and `nosniff`. Anything else, missing, private or never live, gets the same empty 404 with `no-store`. The route runs without the web middleware: no session, cookies, CSRF or Inertia, so a cached response never carries a visitor's state. It is throttled to 600 requests a minute.

Identical outputs can share a hash, so the route serves any live candidate whose bytes verify. Once an image has been live it stays reachable after the site moves on; withdrawal is out of scope.

A damaged file fails only that image: the page still renders, the image URL answers 404 and the browser shows the description. The built-in file is never swapped in.

**Rendering.** A `siteImages` prop is built separately from the page chrome:

- The storefront hero is a `<picture>` with mobile WebP and JPEG sources below 700 px, then desktop WebP, then a JPEG `<img>` carrying every width in `srcset`.
- The studio image is the same without the mobile sources.
- Each `sizes` is the width the image is painted at, not its box's width. Both boxes use `object-fit: cover`, which paints the image at the larger of the box's width and its height times the image's own aspect ratio, often far wider than the box. The box sizes follow the storefront CSS at each breakpoint. The hero is as tall as its minimum height or its copy, whichever is taller, so its `sizes` takes copy as tall as the built-in copy at its tallest: a three-line heading and a two-line description. Longer copy is painted wider than that, and a two-line heading can get the next larger file on standard-density screens.
- Every source carries the release's description and the intrinsic size of its largest JPEG.
- A staff preview links the private preview route instead of the public one.

**Sharing metadata** uses the share image's JPEG. Without one it uses the desktop hero's 1200 px JPEG, and without that the built-in hero. `og:image:width`, `og:image:height` and `og:image:type` are added where the size is known: always for site images, never for track artwork. Both the server's first render and the client's head management emit them.

**Editor.** An **Images** section offers, for each place, the ready images of that slot, newest first, or the built-in image. Option labels are escaped. The form holds ids and descriptions only; saving pins manifests as above. The section:

- warns when a hero image's mean relative luminance under the heading exceeds 0.3, roughly the 3:1 large-text limit for white text;
- notes that platforms keep their own copy of a share image.

**Doctor.** `vasey:doctor` adds `site_images`, which warns when a stored file of an image in the active release fails its hash.

## Operations

- A worker must consume the `media` queue, as for track media: `php artisan queue:work --queue=media --timeout=900 --tries=3 --sleep=1`. Without one, uploads stay Waiting.
- Production needs ClamAV with current signatures ([Media operations](../media-processing.md)). Without it, images wait with `scanner_unavailable`; there is no bypass. A scanner error or time-out, output past its limits, or a scan without the exact clean line also leaves images waiting. Only a detection fails one, and only while its warnings stay under the 4 MiB bound.
- Back up `storage/app/private/site-images/` together with the database. The manifest and hashes pin the stored bytes, so restore both from the same point, or verification fails and the image is not served.
- A commit that reports an error can leave files that no row names, as can a process killed before its cleanup: the prepared files of an image that never became ready under `site-images/revisions/<uuid>/`, or an intake upload under `site-images/quarantine/<uuid>/`. They are private and unreferenced. Remove such a directory only after confirming that no `site_image_variants.storage_path` row, or `site_images.source_path` row for quarantine, names anything in it, and only when nothing is in flight: the directory is older than the 16-minute processing lease and no upload is in progress, or the media workers and admin uploads are stopped. A run between promoting its files and its ready commit, or an upload between storing and committing, has files that no row names yet. Nothing sweeps them automatically.
- Temporary Livewire uploads follow the admin's existing private upload settings.

## Verification and remaining scope

Required evidence:

- intake rules, header problems and authorization, including MFA;
- per-slot sizes, formats and hashes;
- metadata stripping, with byte-identical output from sources with and without metadata;
- colour fidelity;
- failure classification in the processor, in the prober itself, and in the scanner, driven by a scripted clamscan through a detection, standard output past the limit, warnings on standard error past their bound, warnings below it with a clean line (clean) and with a detection (not clean), an exit 0 without the exact clean line (a warning ahead of it, another result, no output), an error, a crash, a time-out and a binary that is not ClamAV;
- transient failure and retry, including a symbolically linked storage directory;
- lease takeover, its audit and the Interrupted status, and lost-claim cleanup;
- errors reported by the ready commit or intake's commit, which keep the files whether or not the row is visible afterwards, and errors inside those transactions, which remove them;
- database guards on both engines, including byte-exact text on MySQL, and rollback of empty tables;
- the page, upload and retry actions, escaping and preview headers, including tampered, missing and symlinked files;
- an independent-process MySQL race between two processors in both lock orders, and over an expired claim;
- a Chromium and WebKit upload in the real admin. The isolated harness has no scanner, so the upload ends Waiting.

The PHP suite uses the testing-only scanner double or a scripted clamscan, so it proves the handling of scanner results, not real malware detection. Test definitions do not establish these results; the integrating PR records the executed commands and CI.

Part 2 evidence adds:

- pinning and refusal on release creation, and the index guards;
- file checks before publish, restore and the scheduler;
- reads failing closed when a variant hash, the index or the scan evidence changes behind dropped guards, while staff can still restore another release;
- the public route: never-live, draft, scheduled, live, rolled-back, staff-session and damaged-file cases;
- storefront, editorial and preview props, with sharing metadata;
- the editor: options, both hero images, descriptions and the contrast warning;
- the doctor check;
- a MySQL race between release creation and image completion in both lock orders;
- frontend tests of the rendered `<picture>` elements.

The browser harness has no scanner, so no image becomes ready there. Its spec shows the editor offering only built-in images, and the ready-image flow is covered by the PHP and frontend tests.

Blog and video thumbnails, responsive track artwork (FP-045), image withdrawal, a CDN, and the logo, theme and fonts are out of scope.
