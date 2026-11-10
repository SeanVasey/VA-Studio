# Albums page

BL-67, 2026-11-10. A public page for Sean's own released music (artist releases such as the albums he names in the 2026-11-10 decision register). It is **not** a beat collection (FP-006), not a licensable track and not a product: there is no price, offer, cart, grant or download, and the page says so ("VASEY.AUDIO does not sell this release"). Selling releases as digital downloads is a later decision. This is a foundation increment, not accepted parity.

## Nothing is pre-filled

No release, artwork, track title or link is seeded, in migrations, fixtures or the interface. Staff enter every detail. With the page on and nothing published, `/albums` shows "No releases are published yet." Tests use clearly synthetic releases ("Synthetic Release One").

## Off by default

`config/albums.php` holds `enabled` as a code literal `false`, deliberately not an environment key, so no deployment environment (and no env validator) can open it by accident; a reviewed change flips it. Everything public goes through `App\Domain\Albums\AlbumsPolicy::enabled()`, which is true only for the exact boolean `true`. While it is false:

- `/albums` and `/albums/{slug}` answer 404, even for published releases;
- the navigation item, sitemap entries and structured data are absent;
- the cover art files are not served from `/site-images/`.

Nothing on the page is test or synthetic, so Sean's decision to switch it on (a reviewed one-line change) is the only gate. Staff can prepare and publish releases while it is off.

## Data model

Migration `2026_11_10_286710_album_releases.php` adds two tables, both listed `NOT_CUSTOMER_DATA` in `RetentionSchedule` (catalog metadata; no customer, payment or contact data):

| Table | Holds |
| --- | --- |
| `album_releases` | title, artist name, slug (unique, ASCII), type (`album`, `ep`, `single`), release date, optional description, cover-art reference (`artwork_image_id`) with its description (`artwork_alt`) and pinned manifest hash, listening links (JSON list), published flag, revision counter, publication time and publisher |
| `album_release_tracks` | ordered tracklist rows (position, title, duration in seconds); unique per release and position |

Releases and tracks are never deleted (foreign keys restrict, the editor has no delete); unpublish instead. The artist name is a required staff-entered field because the repository does not state Sean's artist name; it is shown on the page and in structured data exactly as entered.

## Cover art reuses the site image pipeline (D-25)

There is no new upload path. Staff upload cover art under **Site images** with the use **Release cover art**, a new slot of the existing pipeline: JPEG or PNG, square (about 1:1), at least 1200 × 1200 px, scanned, metadata stripped, re-encoded to 400, 800 and 1200 px in JPEG and WebP (six variants, the count the existing ready trigger already requires). Adding the slot is profile `site-image-v2`:

- `SiteImageSlot::DEFINITIONS` gains `release_cover`, so the profile fingerprint changes and `PROFILE_VERSION` is `site-image-v2`. Images prepared under v1 stay valid; their manifest pins their own fingerprint.
- Migration `2026_11_10_286700_site_image_release_cover_slot.php` replaces the `site_images_valid_insert` trigger so intake admits the slot (SQLite and MySQL, with MySQL's byte-exact comparison). Nothing else in the trigger changes.
- A release stores `artwork_image_id`. At publication (and on every later save of a published release) the image must be in the `release_cover` slot, `ready`, with a matching manifest, a clean accepted scan record and every variant file present and byte-verified. The manifest hash is pinned on the release. The page shows the cover only while the image still has that manifest; otherwise it shows the release without a cover rather than a different image.
- `/site-images/{hash}.{jpg|webp}` serves a cover only when a published release pins that exact image **and** the albums flag is on; any other request gets the same empty 404 as before.
- Alt text is required at publication (`artwork_alt`, up to 200 characters, staff-entered) and is the image's `alt` on the index and detail pages.

## Publication rules

`App\Domain\Albums\AlbumReleases` is the only writer. Each command runs in one transaction that locks the acting staff user (`administer-catalog` and enrolled MFA where the panel requires it) and then the release, and records one audit event (`albums.release.created`, `.updated`, `.published`, `.unpublished`) with before/after hashes. Saves and publishes take the revision the editor saw and refuse a stale one.

A **draft** may be incomplete but never invalid (anything entered must pass the field rules). A release can be **published** only with: ready cover art with alt text, at least one track (each with a title and a length of 0:01 to 6:00:00), and at least one listening link. A published release cannot be saved into a state that fails these rules; unpublish it first.

Field rules: plain text only (no `<`, `>` or control characters), title up to 160, artist up to 120, description up to 2000, slug lowercase letters, numbers and single hyphens up to 80, a real date 1950-2100, at most 99 tracks, at most one link per platform.

## Listening links

Links are staff-entered, https only, up to 500 characters, with no spaces, quotes or angle brackets, no login, port or `#fragment`, and the host must be exactly one of the chosen platform's (`App\Domain\Albums\ListeningPlatforms`):

| Platform | Accepted hosts |
| --- | --- |
| Spotify | `open.spotify.com` |
| Apple Music | `music.apple.com` |
| YouTube Music | `music.youtube.com` |
| YouTube | `www.youtube.com`, `youtu.be` |
| Bandcamp | `bandcamp.com` or one `<name>.bandcamp.com` label |
| SoundCloud | `soundcloud.com` |
| TIDAL | `tidal.com`, `listen.tidal.com` |
| Amazon Music | `music.amazon.com` |
| Deezer | `www.deezer.com` |

This list is a working assumption for Sean to confirm (which services he actually releases on). Adding a platform is a one-line change plus a test. Links open in the same tab with `rel="noopener noreferrer"`; phase 1 has no embedded players and no preview audio.

## Staff editor

**Admin > Publishing > Album releases** (`AlbumReleaseResource`, gate `administer-catalog`, MFA re-read on every request like the collaborator editor). A modal form creates and edits (tracklist lengths are entered as `m:ss`), and row actions publish and unpublish with a confirmation that lists what still blocks publication. There is no delete.

## Public pages

- `/albums`: published releases, newest release date first (ties: newest record first), at most 200, as a semantic list with cover art, type, date, artist and track count.
- `/albums/{slug}`: cover, type and date, artist, description, listening links, and an ordered tracklist with lengths. Unpublished or unknown slugs and the flag being off give 404.
- The navigation item **Albums** (and its footer entry) is appended to the navigation of public pages only while the flag is on (`EditorialContent::chrome`); staff previews of a saved site release never show it. Other lanes that make navigation follow family flags should call `AlbumsPolicy::enabled()` the same way.
- Accessibility: skip link, one `h1`, labelled sections, semantic lists and `time` elements, alt text from the staff field, 44 px targets, visible focus ring, no animation, focus moves to the page heading after in-app navigation. Cover art is square with intrinsic width and height to avoid layout shift and loads lazily below the fold.
- Metadata: canonical URL from the configured site URL (never the request host), `index, follow` in production and `noindex, nofollow` elsewhere, like every other public page; Open Graph type `music.album` with the 1200 px cover.
- Sitemap: `/albums` and each published release are added to `/site-pages-sitemap.xml` (production only, like the other entries) when the flag is on and at least one release is published.
- JSON-LD (production only, flag on): a `MusicAlbum` with `albumReleaseType`, `datePublished`, `byArtist` (the staff-entered name as `MusicGroup`), `numTracks`, `MusicRecording` tracks with ISO 8601 durations, `sameAs` listening links, the cover image, and breadcrumbs. No `Offer` or price is emitted. The index emits none.

## Tests

`tests/Feature/AlbumReleasesTest.php` (flag-off 404 and absent surfaces, ordering, unpublished hidden, publication rules, link validation including host lookalikes, staff and MFA refusal, audit, cover-art serving, sitemap, JSON-LD shape and escaping, Filament editor), `tests/frontend/albums.test.tsx`, and the updated `SiteImageLibraryTest` pin for profile v2. Mutation checks of the key guards were run; see the PR.

## Not done

Embedded preview audio (out of scope for phase 1), purchase or download of releases, importing releases from a streaming service, a release delete, per-track links, and any real catalog data. Which platforms to allow and Sean's artist name are his to confirm.
