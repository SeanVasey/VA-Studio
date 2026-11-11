# Sound-kit storefront and entitlement delivery (BL-53, FP-007)

Status, 2026-11-11: the **catalog, publication gate, cover art and demo audio, public pages and a purchase pin are built, default-off. Purchase,
entitlement and delivery of a kit are NOT built.** This work stopped at the payment boundary on purpose (see "Where it
stopped"). Nothing here is test acceptance of FP-007; the parity row keeps its status. No kit, price, license wording or demo
audio is invented: tests use synthetic fixtures, and the kit license text stays private (BL-66, `docs/sound-kit-license.md`).

## Audit: what existed, what was a gap

| Piece | State before BL-53 | Now |
| --- | --- | --- |
| Private kit drafts, verified WAV archive revisions with scan evidence, resumable upload (`app/Domain/SoundKits/*`, `docs/sound-kit-intake.md`) | exists | unchanged; reused as the deliverable and its scan gate |
| Internal technical revision snapshot (`SoundKitRevisionReadiness`, flag `product-families.wav_sample_kit.technical_revision_preparation`) | exists, staff-only, `payable: false` | unchanged |
| Generic sound-kit license kind, six placeholders, `vasey-kit-license-pdf-v1` renderer, attach guards (BL-66) | exists, no issuer | reused: a kit carries only a published `sound-kit` license version |
| `QuoteSelection::resolveFamily` | refuses every kit with `FAMILY_LICENSE_POLICY_UNSET` | **unchanged** (a test pins it) |
| Kit product record (slug, public summary, price, chosen archive revision, license, rights, offer) | missing | `sound_kit_products`, `SoundKitProducts` |
| Publication gate for a kit | missing | `SoundKitProducts` (below) |
| Staff editor | missing | `SoundKitProductResource` (Publishing group) |
| Public kit pages, sitemap, navigation | missing | `/sound-kits`, `/sound-kits/{slug}`, literal-false flag |
| Kit line in quote, order, payment, grant, contract, delivery | missing | **still missing** (payment-domain change; below) |
| Entitlement of a kit and an authorized download of the purchased archive | missing | **still missing** (depends on the line above) |
| Approved demo audio or artwork for a kit | missing | `sound_kit_demos`, cover reference and per-asset approvals on the product (below) |

## Off by default

`config/sound-kits.php` holds `storefront_enabled` as a code literal `false`, not an environment key (so no `.env.example`
entry). Everything public goes through `SoundKitStorefrontPolicy::enabled()`, true only for the exact boolean `true`. While it
is false, `/sound-kits` and `/sound-kits/{slug}` answer 404 and the navigation item and sitemap entries do not exist. Staff
editing is not gated by the flag. `SoundKitStorefrontPolicy::purchasable()` is a separate constant `false`: **do not set the
flag to `true` before a kit checkout exists**, or the pages list kits that say "This kit is not available to buy yet".

## Data model

Migration `2026_11_11_000500_sound_kit_products.php`, one table, `NOT_CUSTOMER_DATA` in `RetentionSchedule`:

`sound_kit_products` holds the private kit (unique per kit), title, slug (unique, ASCII), public summary, the **chosen archive
revision and its manifest hash** (pinned), the license version, an integer price in cents with currency (USD only, as for
tracks), rights clearance (revision, note, time, staff), offer approval (terms hash, time, staff), publication state and a
revision counter. It holds no customer, payment, order or entitlement data, and nothing can create any. Products are never
deleted (unpublish). A populated table refuses `down()`.

## Publication rules

`SoundKitProducts` is the only writer. Every command runs in one transaction that locks the acting staff user
(`administer-catalog` plus enrolled MFA, re-read under the lock), then the product, then the private kit and archive revision,
checks the expected revision, and records one audit event (the clearance note is hashed in the audit, not copied).

A kit is **listed** (`SoundKitProducts::listed()`, the only public predicate, re-evaluated on every public read) only when all hold
(the seven rules below; 7 is the cover and demo, see "Cover art and demo audio"):

1. a title and a public summary;
2. the chosen archive revision is `ready`, belongs to this kit and still matches the pinned manifest hash;
3. staff recorded **rights clearance for exactly that revision** (a provenance note on the private kit approves nothing);
4. a **published, effective, reviewed `sound-kit` license version** (`VerifiedLicense`); a beat license, a draft or a successor
   not yet published refuses;
5. a positive integer USD price entered by staff;
6. staff **approved the offer** for the exact terms. The approval stores `termsHash` over (product, archive revision, manifest,
   license version, price, currency) and is compared at every read, so a price edit voids it even if the voiding were skipped;
7. it has a **staff-approved cover** and a **staff-approved, processed demo** (below).

At publication only, the archive is **re-verified against its retained scan evidence and the private bytes**
(`SoundKitManifest::verified`): a replaced or deleted archive refuses. Public reads do not hash a 200 MiB file; the revision
row is immutable once `ready` (model guard and database trigger), so the pin is the read-time check. A published kit's price,
license and archive cannot be edited (the edit would void the approval); unpublish first. Revoking rights hides a published
kit at once without unpublishing it.

## What the public sees

`SoundKitPresentation` projects the staff-entered title, summary and price plus counts from the verified manifest (sample
count, total length). It never carries member file names, hashes, storage paths, the archive location, license text or any
purchase or download target. Pages follow the collections pattern and `docs/brand/storefront-visual-review.md` (Bebas
signage, alternating canvas and surface, tokens only). The detail page shows "This kit is not available to buy yet." instead
of a purchase control. The sitemap lists listed kits in production only, while the flag is on.

## Purchase pin (entitlement-ready half)

`SoundKitPurchaseBinding::capture()` returns a canonical, hashed description of what a future purchase must bind: product,
`offer_terms_hash`, kit and archive revision, manifest hash, archive hash and size, member count, license version and its
review submission hash, price and currency. It re-verifies the archive bytes, refuses a kit that is not listed, writes nothing,
and always reports `payable: false` with `commercial_blockers: ["kit_checkout_unbuilt"]`. It is the exact input the payment
lane needs; it is not a checkout.

## Where it stopped: what payment integration remains

The existing quote, order, verified-payment, grant, contract and delivery chain is track-bound end to end:
`quote_lines.offer_revision_id` and `order_lines.offer_revision_id` reference `offer_revisions` of a track offer, the tables are
immutable by trigger, `QuoteSelection::resolve` locks tracks and offers, `PricingSnapshot` and the paid-grant and contract
issuance read track assets and the six beat license kinds, and `DeliveryAssets` and the short-lived delivery authorization are
keyed to track media assets. A kit line cannot be added without a **payment-domain redesign**, which this task is forbidden to
improvise. Remaining, in dependency order:

1. A versioned product-target on `quote_lines` and `order_lines` (a kit product revision instead of an `offer_revision_id`),
   with new immutable evidence tables, MySQL triggers and a data-rights classification, keeping every old track row byte-compatible.
2. `QuoteSelection::resolveFamily` returning a kit line from `SoundKitPurchaseBinding` (it refuses today), pricing from the
   pinned integer price, and Stripe-test checkout admission through `TestEnvironment::admitsTestCommerce()` only.
3. Durable verified payment before any grant, with deduplicated webhook and finalization replay (the existing engine; no new
   charge engine).
4. A kit grant, the generic kit license issued as a contract through the `vasey-kit-license-pdf-v1` profile (still unwired:
   no issuer, dispatch or policy names it), and the purchased snapshot retaining the exact archive revision.
5. A delivery asset adapter so the existing owner-checked, short-lived delivery authorization serves the purchased archive
   (never a public path), with entitlement required for every download.
6. Kit support in the customer library, refund and dispute handling for a kit, and native browser acceptance. (Approved demo
   audio and cover art are built: see "Cover art and demo audio".)

Until those land, none of these properties apply and none is claimed: grant only on verified payment, replay safety of a kit
grant, entitlement-gated download. The tests pin the absence instead (no kit route other than the two read-only pages, the
quote refusal unchanged, no order, grant or entitlement rows after publishing and capturing).

## Cover art and demo audio

Sean's 2026-11-10 decision: kits get public demo audio and cover art. They are two public assets of their own, entirely separate
from the private kit archive: neither can name the archive, its members, hashes or paths, and no asset address can reach it.
Both reuse the pipelines tracks and releases already use; nothing parallel was added.

| Piece | How it is made | Public address |
| --- | --- | --- |
| Cover art | The existing site image pipeline (D-25) with the square `release_cover` slot also used by albums: JPEG or PNG, at least 1200 x 1200 px, scanned, metadata stripped, re-encoded to 400, 800 and 1200 px in JPEG and WebP, manifest-hashed. Staff upload it under **Site images** with the use "Release cover art" and choose it on the kit product. | `/site-images/{sha256}.jpg` or `.webp` (content-hashed, the albums pattern), served only while a **listed** kit pins that exact approved image and the kit pages are on |
| Demo audio | `SoundKitDemoProcessor`: the track path's own parts, in the same order. The quarantined copy is snapshotted and checked against its stored hash, size and type, then scanned (`MalwareScanner`) together with the seller tag (hash, staging-placeholder and tag checks first), and only then validated (`AudioDerivatives::validateWav`, the first ffprobe call) and mixed with the tag at the configured interval and encoded to the same 192k MP3 and 200-point waveform (`AudioDerivatives::build`), and stored under `sound-kit-demos/revisions/` (private, `PrivateMediaFiles::promoteUnder`). | `/sound-kits/{slug}/demo/{demo uuid}`, the tagged preview only, hashed against its retained evidence before it is sent |

**Seller tag.** The track policy applies to demos unchanged: the public demo is the tagged preview, never the full-quality
source (which stays in private quarantine). A missing, mismatched or synthetic-staging tag leaves the demo waiting for an
operator fix, exactly as for a track master; production refuses the staging placeholder.

**Order of work.** The same as a track (`MediaProcessor`): snapshot, hash check, scan of the source, tag checks and scan of the tag,
then WAV validation and build. No media tool (ffprobe or ffmpeg) runs on bytes the scanner has not cleared, and none runs at upload:
`uploadDemo` checks only size, the WAV types `validateWav` itself accepts (`audio/x-wav`, `audio/wav`, `audio/vnd.wave`), the RIFF/WAVE
header with its size field, and emptiness. Codec, channels and duration are judged by the processor on the scanned copy.

**Limits (kit-specific).** A demo is a taster, not the kit: WAV only, up to 100 MiB (checked at upload) and 5 minutes (checked by the
processor after the scan; a longer demo fails for good with `demo_too_long`, a track master may be 200 MiB
and 20 minutes). Cover art keeps the 20 MiB, 6000 px and slot-shape limits of the site image pipeline.

**Waiting and retry.** A missing scanner, media tool (`tool_unavailable`, shown as "the media tools are unavailable on the server",
never as a bad file), storage fault or tag setting leaves the demo waiting; **Retry demo** re-queues it once the cause is fixed.
A demo still `processing` after its claim lease (16 minutes) has expired, because a worker died holding it, can be retried the same
way; while the lease is live the retry is refused. Retry runs under the same authority, MFA, expected product revision and audit event
(`demo_retry_queued`, with the prior status) as every other command. It takes the product and its expected revision like the others;
it does not change the product, so it does not advance the revision, and it can only retry the product's current demo.

**Approval.** One MFA-enrolled catalog staff member chooses and approves each asset (Sean's rule; no second approver).
`SoundKitProductMedia` is the writer: administer-catalog plus enrolled MFA re-read under the lock, expected revision, one audit
event per command (`cover_chosen`, `cover_approved`, `demo_uploaded`, `demo_retry_queued`, `demo_approved`). An approval stores a
hash over the exact asset (product, image id and manifest hash; or demo id, source and preview hash) that is recomputed at every
public read. So a replacement voids it in the writer, and an asset swapped behind the writer is simply not shown. As for tracks,
media cannot be replaced while the kit is published (unpublish first). Scan-engine acceptance and every stored file's bytes are
verified when an asset is approved and when the kit is published; page views rely on the immutable rows.

**Caches.** Covers are served with `Cache-Control: public, max-age=31536000, immutable`, so after a kit is unlisted its cover can
stay in browser and CDN caches: "gone at once" holds at the origin only. The demo is read and hashed against its retained evidence
on every request (per-IP rate limiting applies) and is cached for five minutes (`max-age=300`).

**Defence in depth.** Some guards have a second layer behind them, so a test removing only the first still finds the second. Pinned
by a test of their own: the three processor integrity guards (stored hash, staging placeholder, tag scan), the file verification at
both approvals and at publication, `chooseCover` refusing a problem image, the processor's ordering, the lease-expiry retry and the
expected revision. Left documented rather than pinned singly, with the layer that covers each:

- full-length check of the tagged preview (processor): `AudioDerivatives::build` already refuses a duration mismatch over 0.15 s;
- claim-token check when completing (processor): the row lock and lease in `claim()`, and the immutable-once-ready model guard and trigger;
- the demo belongs to the product (`demoProblem`): the approval hash binds the product's and the demo's public ids, so a foreign demo cannot match it;
- cover scan evidence equals the source hash (`imageProblem`): the site image pipeline writes that evidence and `SiteImageManifest::matches` pins it;
- the cheap authority check before the upload copy (`authorise`): `lockActor` re-checks authority and MFA under the lock, and a failed upload removes its copy;
- the preview path pattern (`previewPathValid`): `PrivateMediaFiles::resolve` refuses any path outside the private root, and the file is then hashed.

**Required to list.** Both. A track needs a ready tagged preview and artwork to be published; a kit is an audio product bought by
ear, so it needs the same two public assets. (This is one rule in `SoundKitProductMedia::problems()` if Sean prefers an optional demo.)

**Data.** Migration `2026_11_11_000600_sound_kit_demos.php`: `sound_kit_demos` (private source path and hash, retained scan
evidence, prepared preview path, hash, duration and waveform; never deleted, immutable once `ready` or `failed` by model guard and
database trigger; `NOT_CUSTOMER_DATA` in `RetentionSchedule`). Migration `000601`: cover and demo references and their approval hashes
on `sound_kit_products`. Neither holds customer, payment or entitlement data. A populated table refuses `down()`.

**What the public sees.** Cover: staff-written description and the prepared sizes only. Demo: the opaque address, length and
waveform. The detail page plays the demo through the same `PersistentPlayer` the catalog uses, with no offers, so it never
offers a license; the index shows each cover. Never a member name, path, hash, source file name or archive address.

## Staff how-to: adding IMPERIAL DRVMZ (or any kit) on staging

No file content goes into Git: the ZIP, cover and demo are uploaded in the admin only.

1. **ZIP.** Admin, Publishing, **Sound kit drafts**: create the draft (title, description, provenance), upload the ZIP, wait for the
   revision to become **ready** (see `docs/sound-kit-intake.md`).
2. **Cover.** Admin, **Site images**, Upload site image: use = "Release cover art", the square cover (at least 1200 x 1200 px, no
   transparency, no text or prices), source or credit, rights confirmation. Wait until its status is **ready**.
3. **Product.** Admin, Publishing, **Sound kit products**, Add kit product: choose the private kit, title, web address, summary,
   the ready archive revision, a published sound-kit license version and the price you decided. **Clear rights** for that revision,
   then **Approve offer**.
4. **Cover on the product.** Row action **Cover art**: choose the ready image and write its description, then **Approve cover**.
5. **Demo.** Row action **Upload demo**: a WAV of up to 5 minutes (the original stays private). Processing runs on the media queue
   worker; the **Demo audio** column changes from "still being prepared" to "Approve the demo audio". If it shows a waiting reason
   (scanner, tools, storage or the seller tag setting), fix that and use **Retry demo** (it also appears for a demo stuck in processing after its time allowance ended). A demo that fails the scan or cannot be
   read is final: upload another. **Approve demo** once it is ready.
6. **Publish.** Row action **Publish** (it re-verifies the archive, cover and demo bytes). The public pages stay 404 until
   `sound-kits.storefront_enabled` is turned on by a reviewed change, which waits for kit checkout (see above).

Check before publishing: the **Cover art** and **Demo audio** columns both read "Approved". To change either later, **Unpublish**
first; uploading a different image or demo voids its approval.

## Needs Sean

- Go-ahead to enable `sound-kits.storefront_enabled`, only after the payment lane above exists.

## Owner decisions (Sean, 2026-11-10)

- One MFA-enrolled catalog staff member may set up a kit, clear its rights and approve its offer; no separate approver is required.
- Kits get public demo audio and artwork (built: "Cover art and demo audio"; scanned, staff-approved, never served from the private
  archive); IMPERIAL DRVMZ is the first real kit.
- The flag stays a literal `false` until kit checkout exists: enabling it before then would list prices with "not available to buy yet".

## Owner decisions (Sean, 2026-11-11)

- IMPERIAL DRVMZ sells for **$14.99** (USD, `price_minor` 1499), under the generic sound-kit license.
- The kit's demo is built from the kit's own samples (74 BPM, C minor, the kit's bonus melody loop, rap backbeat); Sean
  approved the third revision. The cover is Sean's 1320 x 1320 artwork. On staging the product, rights, offer, cover and
  demo are approved and it has no publication blockers; it stays unpublished while the storefront flag is off.

## Tests

`tests/Feature/SoundKitStorefrontTest.php` (SQLite): flag off and exact-true gating, listing facts and the absence of private
or actionable fields, sitemap and navigation, unpublished/unknown/withdrawn 404, every publication blocker, unready and foreign
revisions, replaced and deleted archives, beat licenses and drafts refused, rights and approval bound to exact terms, field
rules and audit, MFA and role on every command, the Filament lifecycle, retention classification, and the absence of any
quote, order, grant, entitlement, download or checkout route. `tests/frontend/sound-kits.test.tsx` covers both pages, the cover and the demo player.

`tests/Feature/SoundKitMediaTest.php` (SQLite, real media tools with the testing-only scanner): a kit is not listed without an approved
cover and an approved demo; wrong-slot, unready and unknown covers and bad descriptions refused; the approval bound to the exact
image and demo, and replaced or swapped assets not shown; oversize, wrong-type and truncated uploads refused with nothing
left behind, no ffprobe call at upload or before both scans, a demo over 5 minutes failing in the processor, a stored-bytes swap, the staging placeholder and a flagged tag refused, missing tools waiting with an honest message, a stuck `processing` demo retried only after its lease, retry needing the current revision, and approval, publication and cover choice refusing damaged files; the demo scanned, tagged and turned into a preview; an infected demo failing for good, and scanner, tag-missing and
tag-mismatch problems waiting for a retry; published kits refusing media replacement; flag off serving nothing; the exact approved
bytes served and a damaged file not; the private archive unreachable through either address; staff-only, MFA and one audit event per
command; the Filament actions. `tests/Feature/SoundKitMediaMigrationTest.php` (both engines) covers the two migrations and triggers.
