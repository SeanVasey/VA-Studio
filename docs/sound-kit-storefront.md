# Sound-kit storefront and entitlement delivery (BL-53, FP-007)

Status, 2026-11-11: the **catalog, publication gate, public pages and a purchase pin are built, default-off. Purchase,
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
| Approved demo audio or artwork for a kit | missing | still missing (no asset role or approval exists for kits) |

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

A kit is **listed** (`SoundKitProducts::listed()`, the only public predicate, re-evaluated on every public read) only when all hold:

1. a title and a public summary;
2. the chosen archive revision is `ready`, belongs to this kit and still matches the pinned manifest hash;
3. staff recorded **rights clearance for exactly that revision** (a provenance note on the private kit approves nothing);
4. a **published, effective, reviewed `sound-kit` license version** (`VerifiedLicense`); a beat license, a draft or a successor
   not yet published refuses;
5. a positive integer USD price entered by staff;
6. staff **approved the offer** for the exact terms. The approval stores `termsHash` over (product, archive revision, manifest,
   license version, price, currency) and is compared at every read, so a price edit voids it even if the voiding were skipped.

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
6. Approved kit demo audio and artwork (asset roles and an approval step), kit support in the customer library, refund and
   dispute handling for a kit, and native browser acceptance.

Until those land, none of these properties apply and none is claimed: grant only on verified payment, replay safety of a kit
grant, entitlement-gated download. The tests pin the absence instead (no kit route other than the two read-only pages, the
quote refusal unchanged, no order, grant or entitlement rows after publishing and capturing).

## Needs Sean

- Go-ahead to enable `sound-kits.storefront_enabled`, only after the payment lane above exists.
- Whether a kit needs a rights approver separate from the staff member who set it up (today one MFA staff user may do both).
- The kit demo and artwork approval policy.

## Tests

`tests/Feature/SoundKitStorefrontTest.php` (SQLite): flag off and exact-true gating, listing facts and the absence of private
or actionable fields, sitemap and navigation, unpublished/unknown/withdrawn 404, every publication blocker, unready and foreign
revisions, replaced and deleted archives, beat licenses and drafts refused, rights and approval bound to exact terms, field
rules and audit, MFA and role on every command, the Filament lifecycle, retention classification, and the absence of any
quote, order, grant, entitlement, download or checkout route. `tests/frontend/sound-kits.test.tsx` covers both pages.
