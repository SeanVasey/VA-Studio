# Sound-kit storefront and entitlement delivery (BL-53, FP-007)

Status, 2026-11-11: the **catalog, publication gate, cover art and demo audio and public pages are built, default-off, and a Stripe
TEST-mode purchase lane (BL-13) now sells, grants, contracts and delivers a kit in local, testing and staging only** (see "Kit checkout
(BL-13)"). There is no production kit checkout. Nothing here is test acceptance of FP-007; the parity row keeps its status. No kit, price, license wording or demo
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
| Kit order, payment, grant, contract, delivery | missing | Stripe TEST lane only (BL-13, "Kit checkout"): its own immutable tables beside the track chain, reusing its gateway, receipt inbox and verification |
| Entitlement of a kit and an authorized download of the purchased archive | missing | Stripe TEST lane only: grant per verified payment, per-order control, 60 s single-use authorization, re-hash before streaming |
| Approved demo audio or artwork for a kit | missing | `sound_kit_demos`, cover reference and per-asset approvals on the product (below) |

## Off by default

`config/sound-kits.php` holds `storefront_enabled` as a code literal `false`, not an environment key (so no `.env.example`
entry). Everything public goes through `SoundKitStorefrontPolicy::enabled()`, true only for the exact boolean `true`, **or** where the
Stripe-test kit purchase lane is admitted (`SoundKitPurchasePolicy`: local, testing or staging with `VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED=true`;
production never). While it
is false, `/sound-kits` and `/sound-kits/{slug}` answer 404 and the navigation item and sitemap entries do not exist. Staff
editing is not gated by the flag. `SoundKitStorefrontPolicy::purchasable()` is true only where the test lane is admitted: **do not set
the production literal to `true`**, there is no production kit checkout and the pages would list kits that say "This kit is not available to buy yet".

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
review submission hash, price and currency. It re-verifies the archive bytes, refuses a kit that is not listed and writes nothing.
`payable` is true only where the test lane is admitted (otherwise `commercial_blockers: ["kit_test_checkout_off"]`). A kit order freezes it
into its immutable snapshot.

## Kit checkout (BL-13): Stripe TEST purchase, grant, contract and delivery

Default-off, admitted only where `TestEnvironment::admitsTestCommerce()` admits the track chain (local, testing, staging) **and**
`VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED=true` (`config('sound-kits.test_checkout_enabled')`, exact `true`). Production can never admit it.
Every route and command answers 404 or refuses while it is closed. Code: `app/Domain/SoundKits/Purchases/`, migration
`2026_11_11_000800_sound_kit_test_purchases.php`, `SoundKitPurchaseController`, `routes/sound-kits.php`.

**Design decisions.**

- **Own tables, shared machinery.** The track quote/order/grant tables are bound to track offer revisions and their immutable triggers; widening
  them for a kit would touch every track reader. The kit lane has its own seven tables (orders, checkout sessions, payments, grants,
  contracts, delivery controls, delivery authorizations) and reuses, unchanged, the track chain's Stripe gateway (`StripeCheckoutGateway`,
  `StripePaymentGateway`), `CheckoutPolicy`, the webhook endpoint and receipt inbox with its fenced work rows (`PaymentWork`), and the
  field-by-field session and PaymentIntent verification (`CheckoutEvidence::session`, `PaymentEvidence::capture`). `ProcessStripeReceipt`
  routes a receipt whose session is bound to a kit order to `SoundKitPayments`; every other receipt is processed exactly as before. This
  follows the service-invoice precedent (BL-70).
- **No inventory reservation.** A kit is non-exclusive and unlimited: nothing can be oversold and there is no exclusive sale to serialize, so a
  rights-scope reservation would add a lock with nothing to protect. The terms are still re-checked under a product lock at order time and the
  kit must still be listed on the exact frozen terms (`termsHash`) before a Stripe session is created.
- **No mixed carts.** A kit is bought on its own from its page. The track quote accepts only track items (`QuoteRequest`), and
  `QuoteSelection::resolveFamily` still refuses a kit, so a kit can never enter a track order. One kit, one order, one license.
- **Server-side everything.** The browser sends only the kit's address, the licensee's name and an idempotency key. Price (integer minor units),
  currency, archive revision, archive SHA-256 and size, license version, its review hash and the SHA-256 of its text come from the listed product
  through `SoundKitPurchaseBinding` (which re-hashes the private archive) into an immutable snapshot. A client-supplied price, revision or
  license is never read. The exact Stripe request (one line named `VASEY.AUDIO sound kit (test): <title>`, the server price, no tax,
  promotion codes or adaptive pricing) is frozen in the order and replayed with idempotency key `vasey-kit-checkout-v1-<order>`.
- **Refuse before money moves.** Order creation refuses a license text that does not declare exactly the six placeholders, a missing licensor name
  and a licensee name the kit profile cannot print, so a paid order can always get its contract.
- **Verified payment before grant, once.** The payment row is written only from GET-only provider reads (session complete and paid, PaymentIntent
  succeeded with exactly the order price) inside a fenced commit; a trigger also requires the order's exact price, currency and bound session.
  The grant is written afterwards in its own transaction from that committed row. `UNIQUE(order)` on both, so a replayed or duplicated webhook,
  an `async_payment_succeeded` and a reconcile converge on one payment and one grant. A grant refused after its payment committed (a transient
  error, or a purchased archive row that no longer matches the snapshot) leaves the order `paid`: the refusal is audited once per reason
  (`sound_kit.test_grant.refused`), `vasey:finalize-test-kit-grants` (scheduled every 10 minutes at :04/:14/…, tracked on the operational
  dashboard, run only while the lane is admitted) retries every paid order without a grant and exits non-zero while one is still refused, and the
  owner's **Check payment status** retries it too. A `requires_capture`, `processing` or `canceled` PaymentIntent records no payment.
- **Contract.** `SoundKitContracts` renders the published license version's frozen text through `vasey-kit-license-pdf-v1`
  (`VaseyKitLicensePdfRenderer`, Sean's pinned signature). Only the six purchase-details placeholders are filled: `{KIT_NAME}` (order snapshot
  title), `{LICENSEE_NAME}` (the encrypted name from the order), `{LICENSOR_NAME}` (`VASEY_KIT_LICENSE_LICENSOR_NAME`), `{PURCHASE_DATE}` (the
  verified payment time, UTC), `{LICENSE_FEE}` (the verified amount) and `{ORDER_REFERENCE}` (the order id). The original is written once to the
  private disk (`sound-kit-contracts/<grant>/<sha256>.pdf`) and recorded immutably (SHA-256, size, pages, drawn-text hash, effective profile hash,
  input hash); every read re-hashes it. Rendering is in-process (the track v2 originals use an isolated child; a follow-up).
- **Delivery.** The track test-delivery model: an operator control per order (absent means not enabled), a 60-second single-use authorization
  bound to the owner, order, grant and one deliverable (`archive` or `contract`), its secret stored only as a SHA-256. On redemption the private
  archive is copied into an unlinked temporary file while being hashed and compared with the snapshot; only that checked copy is streamed. A
  tampered archive is refused and audited; nothing is served from a public path. No ranges or resume for kits yet.

**Routes** (all 404 while the lane is closed): `POST /sound-kits/{slug}/orders` (`licenseeName`, `idempotencyKey`),
`GET /sound-kit-orders/{order}` (owner page, also Stripe's return address; arrival proves nothing), `GET …/status`, `POST …/checkout`,
`POST …/checkout/reconcile`, `POST …/delivery/authorizations` (`kind`), `POST …/delivery/download` (`authorizationId`, `token`).

**Commands:** `vasey:reconcile-test-kit-order {order}` (a webhook that never arrived), `vasey:finalize-test-kit-grants --limit=` (paid orders
without a grant; also scheduled), `vasey:issue-test-kit-contracts {grant?} --limit=`,
`vasey:control-test-kit-delivery {order} enable|block --expected-version=N --reference=REF` (first use `--expected-version=0`).

### Staging: enable and test-buy IMPERIAL DRVMZ

The track Stripe-test chain must already be configured on the host (ops/staging/test-commerce profile: `STRIPE_ACCOUNT_ID`, `STRIPE_TEST_SECRET_KEY`,
`STRIPE_WEBHOOK_ENABLED=true` with its secret, `STRIPE_TEST_CHECKOUT_ENABLED=true`, `STRIPE_TEST_PAYMENT_PROCESSING_ENABLED=true`,
`VASEY_TEST_CHECKOUT_POLICY` with `return_origin` equal to `APP_URL`, and the queue worker processing receipts). Then:

1. In the staging `.env`: `VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED=true`, `VASEY_KIT_LICENSE_LICENSOR_NAME=VASEY.AUDIO` (the decided licensor line),
   and the signature pin `VASEY_LICENSE_SIGNATURE_PATH` / `VASEY_LICENSE_SIGNATURE_SHA256` (a PNG on the private disk; without it the contract
   refuses `signature_unavailable`). Run `php artisan config:cache` (or redeploy).
2. Admin: the IMPERIAL DRVMZ product must be **published** (row action Publish; it re-verifies archive, cover and demo). The storefront literal
   stays `false`: the pages open on staging because the test lane is admitted.
3. As a buyer, open `/sound-kits/<slug>`, enter the licensee name, **Buy with Stripe test checkout**, pay with a Stripe test card
   (`4242 4242 4242 4242`). Stripe returns to `/sound-kit-orders/<order>`.
4. The webhook grants the kit (or press **Check payment status**, or run `php artisan vasey:reconcile-test-kit-order <order>`).
5. `php artisan vasey:issue-test-kit-contracts` (prints `<grant> issued <sha256>`).
6. `php artisan vasey:control-test-kit-delivery <order> enable --expected-version=0 --reference=STAGING-KIT-1`.
7. Reload the order page: **Download the kit (ZIP)** and **Download the license (PDF)**. Each click mints a fresh 60-second single-use link.

If the order page stays at "Test payment verified. The kit is being granted.", run `php artisan vasey:finalize-test-kit-grants`: it prints
`<order> granted`, or `<order> payment_changed` when the purchased archive row no longer matches the order (investigate before anything else;
the audit log holds `sound_kit.test_grant.refused`).

**Refunds and disputes are not handled by the kit lane.** A refund or dispute on a kit order's Stripe test payment changes nothing here: the
payment and grant stay and delivery stays as it was. When one happens, block delivery by hand with the next control version (read the current
`version` from the last command output or `sound_kit_delivery_controls`; `0` if delivery was never enabled):
`php artisan vasey:control-test-kit-delivery <order> block --expected-version=<current version> --reference=<refund or dispute id>`. That closes
new and outstanding download authorizations; nothing is revoked or deleted.

To close it again set `VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED=false` and re-cache config: every kit purchase route answers 404 and the pages close.

**Not built** (follow-ups): production kit checkout and tax; refund and dispute integration for a kit (refunds and disputes neither block
delivery nor mark the order; this is a blocker for any production kit lane, and until then an operator blocks delivery by hand as above); kit orders in the customer library, order history and
the data-rights export (the tables are classified `not_applicable`, test-only); staff views of kit orders (inspect them with the commands above and
the audit log); resumable kit downloads; isolated-child contract rendering; native browser acceptance.

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
The track-level preview-tag setting (2026-11-11, [media processing](media-processing.md#preview-tag-tagged-or-untagged-public-preview)) does not apply to demos: they keep a single tagged preview and no untagged variant is made for them (a follow-up that needs new `sound_kit_demos` columns and a decision on the pinned demo approval).

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
6. **Publish.** Row action **Publish** (it re-verifies the archive, cover and demo bytes). In production the public pages stay 404 while
   `sound-kits.storefront_enabled` is `false`; on staging they open with the Stripe-test kit lane (see "Kit checkout").

Check before publishing: the **Cover art** and **Demo audio** columns both read "Approved". To change either later, **Unpublish**
first; uploading a different image or demo voids its approval.

## Needs Sean

- A production kit checkout (tax, refunds, customer library) before `sound-kits.storefront_enabled` is turned on; the test lane is staging-only.

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

`tests/Feature/SoundKitCheckoutTest.php` (SQLite; three tests also run on native MySQL 8.4 locally): every route and command closed with the
flag off, a non-boolean flag or in production; the HTTP journey ignoring client price, revision and license and naming the kit at Stripe;
idempotency; one payment and one grant across webhook replay, a duplicate event, `async_payment_succeeded` and reconcile; reconcile recovering a
missing webhook while unpaid, short-paid, mis-totalled or foreign-metadata provider evidence never grants; a `requires_capture`, `processing` or
`canceled` PaymentIntent recording nothing; a session bound to one real order whose metadata (or every term) names another paying neither; a grant
refused after payment (archive size, then hash, changed on the revision row) audited once and later swept into exactly one grant; the owner's
reconcile at `paid` granting once; unpublished, uncleared, unapproved,
blocked, tampered-archive, expired and unprintable-name purchases refused before any Stripe call; the contract filling exactly the six
placeholders with the licensor from configuration, recorded once and re-hashed on read; delivery needing grant, contract and control, owner-bound,
single-use, 60-second authorizations; a tampered archive never streamed; the HTTP download; immutable evidence and the payment trigger (the honest bound row accepted; only the amount, currency, session or
account wrong refused);
retention classification.

`tests/Feature/SoundKitStorefrontTest.php` (SQLite): flag off and exact-true gating, listing facts and the absence of private
or actionable fields, sitemap and navigation, unpublished/unknown/withdrawn 404, every publication blocker, unready and foreign
revisions, replaced and deleted archives, beat licenses and drafts refused, rights and approval bound to exact terms, field
rules and audit, MFA and role on every command, the Filament lifecycle, retention classification, a kit never entering the track
quote, and the exact route list (the only purchase routes are the BL-13 test lane's). `tests/frontend/sound-kits.test.tsx` covers both pages,
the cover and the demo player; `tests/frontend/sound-kit-checkout.test.tsx` the buy form and the order page.

`tests/Feature/SoundKitMediaTest.php` (SQLite, real media tools with the testing-only scanner): a kit is not listed without an approved
cover and an approved demo; wrong-slot, unready and unknown covers and bad descriptions refused; the approval bound to the exact
image and demo, and replaced or swapped assets not shown; oversize, wrong-type and truncated uploads refused with nothing
left behind, no ffprobe call at upload or before both scans, a demo over 5 minutes failing in the processor, a stored-bytes swap, the staging placeholder and a flagged tag refused, missing tools waiting with an honest message, a stuck `processing` demo retried only after its lease, retry needing the current revision, and approval, publication and cover choice refusing damaged files; the demo scanned, tagged and turned into a preview; an infected demo failing for good, and scanner, tag-missing and
tag-mismatch problems waiting for a retry; published kits refusing media replacement; flag off serving nothing; the exact approved
bytes served and a damaged file not; the private archive unreachable through either address; staff-only, MFA and one audit event per
command; the Filament actions. `tests/Feature/SoundKitMediaMigrationTest.php` (both engines) covers the two migrations and triggers.
