# VASEY.AUDIO License Tiers

> **DRAFT for Sean Vasey's approval — not yet published — not legal advice; qualified legal review recommended before publication.**
>
> Draft date: 2026-10-24. Written against source `786fb7c755291184f9e01b4faaab28dbd430d698` (`origin/main`). Draft version label: `license-tiers-draft-2026-10-24`.
> Every number marked **proposed — Sean to confirm** is a common industry figure offered as a starting point, not a decision. **Prices are not set here: Sean sets every price.** Nothing in this document creates a license version, offer or contract; licenses become operative only after they are authored, independently reviewed and published in the administration panel and Sean has approved the text and prices.

This document defines the license tiers new VASEY.AUDIO purchases would use, the full license text for each tier, and how an operator enters each tier into the store's typed license fields. The tiers follow the structure buyers already know from the BeatStars-era store (the public site has shown "Basic" and "Premium" leases; see `docs/migration/research_summary.md`), but all wording here is original to VASEY.AUDIO.

- **Merchant:** Vasey Multimedia, doing business as VASEY.AUDIO.
- **Licensor:** Sean Vasey.
- **Contact:** support@vasey.audio.
- **Governing law:** State of Iowa, USA.

## 1. Tier matrix

| Tier | Template type | Deliverables (`required_asset_roles`) | Exclusive? | Term | Territory | New songs | Audio releases | Copies / downloads | Monetized streams | Music videos | Paid live shows | Radio stations | Price (USD) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **Free Promotional** | non-exclusive (free-grant family) | MP3 (`download_mp3`) | No | Perpetual | Worldwide | 1 | 1 (non-commercial) | Not permitted | Not permitted | 1 (non-monetized) | Not permitted | Not permitted | Free |
| **Basic Lease** (MP3) | non-exclusive | MP3 (`download_mp3`) | No | 10 years | Worldwide | 1 | 2 | 5,000 | 100,000 | 1 | 10 | Not permitted | Sean to set |
| **Premium Lease** (WAV) | non-exclusive | MP3 + WAV (`download_mp3`, `master_wav`) | No | 10 years | Worldwide | 1 | 3 | 10,000 | 250,000 | 2 | Unlimited | 2 | Sean to set |
| **Trackout Lease** (WAV + stems) | non-exclusive | MP3 + WAV + stems (`download_mp3`, `master_wav`, `stems_zip`) | No | 10 years | Worldwide | 1 | 3 | 20,000 | 500,000 | 3 | Unlimited | 2 | Sean to set |
| **Unlimited Lease** | non-exclusive | MP3 + WAV + stems (`download_mp3`, `master_wav`, `stems_zip`) | No | Perpetual | Worldwide | 1 | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited | Sean to set |
| **Exclusive Rights** | exclusive | MP3 + WAV + stems (`download_mp3`, `master_wav`, `stems_zip`) | Yes, after purchase | Perpetual | Worldwide | 1 | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited | Sean to set |

All caps, terms and permissions above are **proposed — Sean to confirm**. Non-monetized streams are unlimited on every paid tier and capped at 25,000 on the free tier (section 2). Every tier requires the producer credit and keeps the Licensor's 50% publishing share (section 2).

## 2. Proposed numbers — change them here first

This is the single place to change a number. After Sean confirms or edits a value, copy it into section 6 (the typed fields) and the matching license text in section 5. Values are written in the form the admin form accepts: a whole number for a limited cap, or `unlimited` / `prohibited`.

| Field (admin path) | Free Promotional | Basic Lease | Premium Lease | Trackout Lease | Unlimited Lease | Exclusive Rights |
| --- | --- | --- | --- | --- | --- | --- |
| `usage.audio_releases` | 1 | 2 | 3 | 3 | unlimited | unlimited |
| `usage.copies_downloads` | prohibited | 5,000 | 10,000 | 20,000 | unlimited | unlimited |
| `usage.monetized_streams` | prohibited | 100,000 | 250,000 | 500,000 | unlimited | unlimited |
| `usage.non_monetized_streams` | 25,000 | unlimited | unlimited | unlimited | unlimited | unlimited |
| `usage.music_videos` | 1 | 1 | 2 | 3 | unlimited | unlimited |
| `usage.live_performances` (paid shows) | prohibited | 10 | unlimited | unlimited | unlimited | unlimited |
| `usage.radio_stations` | prohibited | prohibited | 2 | 2 | unlimited | unlimited |
| `permissions.content_id` | prohibited | prohibited | prohibited | prohibited | prohibited | permitted (New Song only) |
| `permissions.paid_advertising` | prohibited | prohibited | prohibited | prohibited | prohibited | permitted |
| `permissions.sublicensing` | prohibited | prohibited | prohibited | prohibited | prohibited | permitted (New Song only) |
| `permissions.standalone_resale` | prohibited | prohibited | prohibited | prohibited | prohibited | prohibited |
| `credit` | required: `Prod. by Sean Vasey` | same | same | same | same | same |
| `territory` | worldwide | worldwide | worldwide | worldwide | worldwide | worldwide |
| `duration` | perpetual | 120 months | 120 months | 120 months | perpetual | perpetual |
| `publishing_income.licensor_bps` | 5,000 (50%) | 5,000 | 5,000 | 5,000 | 5,000 | 5,000 |
| `recording_royalty` | none | none | none | none | none | none (optional rate, see section 8) |
| Sync (contract text only, no typed field) | Personal non-monetized online video only | Own music video(s) and promo clips | Same as Basic | Same as Basic | Own audiovisual works (no third-party licensing) | Any media, including licensing to third parties |
| Price | Free | Sean to set | Sean to set | Sean to set | Sean to set | Sean to set |

Notes on the numbers:

- **Credit text** "Prod. by Sean Vasey" is a proposal; the repository records no producer-credit convention (`docs/brand/` has none). The admin field accepts up to 120 characters.
- **Publishing share** of 50% of the New Song's composition to the Licensor is the most common producer split for leased beats. It is stored as `licensor_bps = 5000` (basis points).
- **Prices.** AGENTS.md forbids invented prices. Offers are created separately with a positive whole-number price in U.S. cents (`docs/licensing-and-offers.md`). The legacy public figures recorded in `docs/migration/research_summary.md` are an old crawl, not verified prices, and are not proposed here.

## 3. Plain-English comparison (store copy)

Short copy for a comparison page. It summarises; the license text governs.

- **Free Promotional** — an MP3 to write and record to for non-commercial use: share a demo or post a non-monetized video. Upgrade before you sell, monetize or distribute.
- **Basic Lease** — an MP3 for your first commercial release: up to 5,000 sales or downloads, 100,000 monetized streams, one music video and ten paid shows, for 10 years.
- **Premium Lease** — the WAV master and MP3 for a higher-quality release: 10,000 sales or downloads, 250,000 monetized streams, two music videos, unlimited paid shows and two radio stations, for 10 years.
- **Trackout Lease** — everything in Premium plus the stems, so your engineer can mix the beat around your vocals: 20,000 sales or downloads, 500,000 monetized streams, three music videos, for 10 years.
- **Unlimited Lease** — WAV, MP3 and stems with no caps on sales, streams, videos, shows or radio, forever. Still non-exclusive: others may license the same beat.
- **Exclusive Rights** — WAV, MP3 and stems, no caps, forever, and nobody new can license the beat after you buy it. Licenses sold before your purchase stay valid.

Every license: one new song, worldwide, credit "Prod. by Sean Vasey", Sean keeps 50% of the song's publishing, no reselling the beat, no AI training, and no Content ID registration (except the exclusive, for your own song).

## 4. License text — common terms (all tiers)

Section 4 is the shared body of every license. Section 5 adds what is specific to each tier. When an operator authors a tier, the license source is: the tier heading and grant from section 5, the common terms below, and the generated variables shown in section 6.3. The words in **bold** are defined terms.

### 4.1 Parties and purchase

This license is granted by **Sean Vasey** ("**Licensor**") through **Vasey Multimedia, doing business as VASEY.AUDIO** ("**VASEY.AUDIO**"), to the person or business named as the buyer on the license document issued for this purchase ("**Licensee**"). It takes effect at the grant time shown on that license document, once VASEY.AUDIO has confirmed payment (or, for a free license, once the Licensee has accepted it). The license document, which records the Licensee, the track, this license version, the files delivered and the price, forms part of this license.

### 4.2 Definitions

- **Beat**: the instrumental musical work identified on the license document, both its musical composition and its sound recording, including every file delivered under this license (MP3, WAV master and stems, as listed).
- **New Song**: one new musical work and recording created by the Licensee that combines the Beat with the Licensee's own substantial original contribution, such as lyrics, vocals or melodies. Edits of the same New Song (clean, explicit, instrumental, a cappella, sped-up or slowed, extended or radio edits) count as the same New Song.
- **Audio release**: a distinct audio product made available to the public that contains the New Song, such as a single, EP, album, mixtape or compilation.
- **Copies / downloads**: the combined number of physical copies (for example CD, vinyl or cassette) and permanent digital downloads (paid or free) of the New Song distributed to the public.
- **Monetized stream**: one play of the New Song, audio or video, on a service where the Licensee or someone acting for the Licensee earns revenue from that play (for example a subscription or ad-supported streaming service, or a monetized video). Plays are counted across all services combined.
- **Non-monetized stream**: one play of the New Song from which no one earns revenue.
- **Music video**: a distinct audiovisual work made to present the New Song (including lyric videos and visualizers), published on any platform. Its plays count as streams.
- **Paid live performance**: a public live performance of the New Song for which the performer is paid or admission is charged. Unpaid and charity performances are always allowed and are not counted.
- **Radio station**: a terrestrial, satellite or internet broadcast station that plays programmed content to the public. Each station or channel counts once, however often it plays the New Song.
- **Content ID**: YouTube Content ID and any similar system that fingerprints audio to identify, claim, block or monetize content (including Facebook/Instagram Rights Manager, TikTok and SoundCloud content identification, and distributor services that register recordings with such systems).
- **Paid advertising**: using the Beat or the New Song in an advertisement or sponsored content for a product, service, brand or person other than the New Song itself or the Licensee's own music and performances. Paying to promote the New Song is always allowed.
- **Sublicensing**: granting any third party a right to use the Beat or the New Song, other than distributors, stores, streaming services, broadcasters and venues acting in the ordinary course to make the New Song available as this license allows.
- **AI use**: using any part of the Beat or the New Song to train, fine-tune, evaluate or condition an artificial-intelligence or machine-learning system, to build a dataset, or to create a voice, style or music model, or uploading it to a service that may use uploads for these purposes.

### 4.3 What the Licensee may do

Licensor grants the Licensee a license, of the type stated in section 5 (non-exclusive or exclusive), to:

1. use the Beat to create **one** New Song, editing, arranging, re-recording parts and adding to the Beat as needed;
2. reproduce, distribute, stream, sell, perform, broadcast and synchronize that New Song, within the limits, term and territory stated in this license; and
3. use short excerpts of the New Song to promote it, including on social media.

Each limit stated in this license applies to the New Song in total across all formats and services. The Licensee must not exceed a limit; to do more, the Licensee must buy a license with higher limits before the limit is reached. VASEY.AUDIO does not monitor releases or streams: staying within the limits is the Licensee's responsibility.

### 4.4 What the Licensee may not do

Under every tier the Licensee must not:

1. sell, lease, license, give away, share or otherwise distribute the Beat on its own, or in a form where it can be extracted and used apart from the New Song (for example instrumental packs, sample packs, loop libraries, stem libraries or "type beat" uploads);
2. use the Beat in more than one New Song;
3. claim to be the author, producer or owner of the Beat, register the Beat's composition or recording as the Licensee's own, or remove or alter the producer credit;
4. register the Beat, unaltered, with any Content ID system or collecting service; or register the New Song with Content ID unless the tier expressly permits it;
5. make any AI use of the Beat or the New Song;
6. use the Beat or the New Song in anything defamatory, hateful, obscene in a way that is unlawful, or infringing; or in political campaigns, unless Licensor agrees in writing;
7. do anything the tier marks as not permitted.

### 4.5 Ownership

- Licensor keeps all ownership of the Beat, its composition and its sound recording. This license does not transfer any copyright in the Beat, under any tier, unless a written assignment signed by Licensor says so.
- The Licensee owns the copyright in the Licensee's own original contributions (for example lyrics, vocal recordings and melodies the Licensee writes).
- The New Song's sound recording contains the Beat. The Licensee may exploit that recording only as this license allows, and Licensor's rights in the Beat continue in it.

### 4.6 Publishing and writer shares

- The New Song's musical composition is shared: Licensor is a writer and publisher of the New Song for the music of the Beat; the Licensee's writers own the remaining share.
- Unless Licensor and the Licensee agree otherwise in writing, Licensor's share is **50%** of the New Song's composition, which entitles Licensor to 50% of all publishing income from the New Song (writer's share and publisher's share together).
- When the New Song is registered with a performing rights organization, collecting society, publisher administrator or distributor that collects publishing income, the Licensee must list Sean Vasey as a writer with that share, using the details Licensor provides on request at support@vasey.audio, and must not register the composition in a way that omits or reduces Licensor's share.
- This license does not give Licensor any share of the Licensee's income from the New Song's sound recording (for example streaming or sales royalties paid to the recording owner), unless the tier states a recording royalty.

### 4.7 Credit

The Licensee must credit the producer as **"Prod. by Sean Vasey"** wherever credits for the New Song appear, including streaming metadata where the service allows producer credits, release notes, physical packaging and video descriptions. Accidental omission is not a breach if corrected within 30 days of Licensor's request.

### 4.8 Producer tag

Store previews carry an audible producer tag. Files delivered under this license do not contain that tag. The Licensee must not make a New Song from a store preview or any copy of one. The Licensee may, but need not, include a producer tag that Licensor supplies.

### 4.9 Content ID and claims

- Unless the tier permits it, the Licensee must not register the Beat or the New Song with any Content ID system, or allow a distributor to do so. If the Licensee's distributor offers such a service, the Licensee must opt out for the New Song.
- If the New Song, used within this license, receives a claim from a system administered for Licensor, the Licensee should email support@vasey.audio with the license document; Licensor will release the claim promptly.

### 4.10 Samples and clearances

Licensor confirms that, to Licensor's knowledge, the Beat as delivered contains no third-party material that needs a further clearance for the uses this license allows, unless the track page and license document disclose it. The Licensee is responsible for clearing anything the Licensee adds to the New Song, such as samples, interpolations or featured artists.

### 4.11 Term and ending of the license

- The license lasts for the term stated in the tier, from the grant time.
- **When a fixed term ends**, the Licensee must stop new distribution, streaming, performance and other exploitation of the New Song and remove it from services within 30 days, unless the Licensee has bought a new license for it. Physical copies already sold to the public may continue to circulate.
- **Ending for breach.** If the Licensee materially breaks this license and does not fix the breach within 14 days after Licensor's notice, Licensor may end the license by notice. Licensor may end the license immediately by notice if the Licensee resells or distributes the Beat on its own, registers the unaltered Beat with Content ID, or claims authorship of the Beat.
- **Refunds and chargebacks.** If VASEY.AUDIO refunds the purchase, or a payment is reversed by a chargeback, the license ends on the refund or reversal date.
- When the license ends for any reason, the Licensee must stop exploiting the New Song and remove it from services within 30 days. Sections 4.4, 4.5, 4.6 and 4.12 to 4.14 continue to apply.

### 4.12 Transfer

The Licensee may transfer this license only together with all of the Licensee's rights in the New Song, to a single person or company (for example a record label, a company the Licensee owns, or a buyer of the New Song), by written agreement that binds the new holder to this license, and with written notice to support@vasey.audio including the license reference. The limits are not reset by a transfer. Licensor may transfer its rights under this license to any successor in its rights to the Beat; this license stays valid.

### 4.13 If an exclusive license is sold

Unless the tier is the Exclusive Rights license:

- this license is **non-exclusive**: Licensor may license the Beat to others, and may later sell an exclusive license for it;
- if an exclusive license is sold, **this license continues unchanged** for its full term and limits; it is not revoked or shortened;
- after an exclusive sale, VASEY.AUDIO will not sell renewals, upgrades or higher-tier licenses of the Beat to the Licensee; when this license ends, it cannot be renewed.

### 4.14 Promises, liability and general terms

- Licensor promises that Licensor owns or controls the Beat and has the right to grant this license. The Licensee promises that the Licensee's contributions to the New Song do not infringe anyone's rights.
- Except for those promises, the Beat is licensed "as is". To the extent the law allows, Licensor's total liability under this license is limited to the price paid for it, and neither party is liable for indirect or consequential losses. **[For legal review.]**
- This license is governed by the laws of the State of Iowa, USA. Courts located in Iowa have jurisdiction, without removing any consumer's mandatory rights in their home country. **[Venue county: Sean to confirm.]**
- This license, the license document and the VASEY.AUDIO Terms of Sale and Use are the whole agreement for this purchase. If they conflict about the use of the Beat or the New Song, this license controls. If any part is unenforceable, the rest remains in effect.
- Notices to Licensor go to support@vasey.audio; notices to the Licensee go to the email address on the license document or account.

## 5. Tier schedules

Each schedule is the tier-specific opening of the license. "Typed values" refers to the statements the store generates from section 6.

### 5.1 Free Promotional License

- **Type:** non-exclusive, **free** (no payment). Claimed through the store's free-license flow: an account, the name for the license, and an explicit acceptance box. Each account may claim it once per track; Licensor may limit how many are available and for how long.
- **Deliverables:** MP3 (`download_mp3`).
- **Term:** perpetual. **Territory:** worldwide.
- **Permitted:** one New Song for **non-commercial** use only: one non-commercial audio release, up to 25,000 non-monetized streams, and one non-monetized music video or social video. Personal sharing, demos, school and portfolio use.
- **Not permitted:** any sale or download distribution, monetized streams or videos, paid live performances, radio broadcast, Content ID, paid advertising, sublicensing, synchronization other than the Licensee's own non-monetized online video, resale of the Beat.
- **Going commercial:** to monetize, distribute for sale or exceed any limit, the Licensee must first buy a paid license. A paid license replaces this one for the New Song from its own grant time.
- **Credit, publishing, producer tag, Content ID, termination, transfer, exclusive-sale effect:** as in section 4 (credit required; 50% publishing share; no Content ID; non-exclusive and continues after an exclusive sale, but cannot be upgraded once an exclusive is sold).

### 5.2 Basic Lease (MP3)

- **Type:** non-exclusive. **Deliverables:** MP3 (`download_mp3`), untagged 320 kbps.
- **Term:** 10 years (120 calendar months) from the grant time. **Territory:** worldwide.
- **Permitted:** one New Song; up to **2** audio releases; up to **5,000** copies/downloads combined; up to **100,000** monetized streams; unlimited non-monetized streams; **1** music video; up to **10** paid live performances; synchronization only in the Licensee's own music video and short promotional clips for the New Song.
- **Not permitted:** radio broadcast; Content ID registration; paid advertising; sublicensing; any other synchronization (film, TV, games, advertising, trailers); resale of the Beat.
- **Credit, publishing, producer tag, Content ID, termination, transfer, exclusive-sale effect:** as in section 4.

### 5.3 Premium Lease (WAV)

- **Type:** non-exclusive. **Deliverables:** MP3 (`download_mp3`) and WAV master (`master_wav`).
- **Term:** 10 years (120 calendar months). **Territory:** worldwide.
- **Permitted:** one New Song; up to **3** audio releases; up to **10,000** copies/downloads; up to **250,000** monetized streams; unlimited non-monetized streams; **2** music videos; unlimited paid live performances; up to **2** radio stations; synchronization only in the Licensee's own music videos and promotional clips.
- **Not permitted:** Content ID registration; paid advertising; sublicensing; other synchronization; resale of the Beat.
- **Common terms** as in section 4.

### 5.4 Trackout Lease (WAV + stems)

- **Type:** non-exclusive. **Deliverables:** MP3 (`download_mp3`), WAV master (`master_wav`) and stems archive (`stems_zip`).
- **Term:** 10 years (120 calendar months). **Territory:** worldwide.
- **Permitted:** one New Song; up to **3** audio releases; up to **20,000** copies/downloads; up to **500,000** monetized streams; unlimited non-monetized streams; **3** music videos; unlimited paid live performances; up to **2** radio stations; synchronization only in the Licensee's own music videos and promotional clips. The stems may be mixed, edited and processed for the New Song only.
- **Not permitted:** distributing the stems in any form, including to remixers outside the New Song's production team; Content ID registration; paid advertising; sublicensing; other synchronization; resale of the Beat.
- **Common terms** as in section 4.

### 5.5 Unlimited Lease

- **Type:** non-exclusive. **Deliverables:** MP3, WAV master and stems (`download_mp3`, `master_wav`, `stems_zip`).
- **Term:** perpetual. **Territory:** worldwide.
- **Permitted:** one New Song with **no numeric limits** on audio releases, copies/downloads, streams, music videos, paid live performances or radio stations; synchronization of the New Song in audiovisual works the Licensee produces or appears in (music videos, documentaries, independent films, podcasts, livestreams and video content), provided the Licensee does not license the New Song to a third party for their production.
- **Not permitted:** Content ID registration; paid advertising; sublicensing (including licensing the New Song to film, TV, game or advertising producers); distributing the stems; resale of the Beat.
- **Note for buyers:** "Unlimited" means no numeric caps. The license is still non-exclusive: others may license the same Beat, and an exclusive may be sold later.
- **Common terms** as in section 4.

### 5.6 Exclusive Rights License

- **Type:** **exclusive** from the grant time. After the grant, Licensor will not grant any new license of the Beat to anyone, and VASEY.AUDIO removes the Beat from sale.
- **Licenses granted before the exclusive.** Non-exclusive licenses (and free licenses) of the Beat granted before this exclusive license **remain valid** for their own term and limits and are not revoked. The Licensee takes this exclusive license subject to them. On request, VASEY.AUDIO will confirm how many earlier licenses exist and their tiers (without disclosing the buyers' personal information). Releases Licensor made of the Beat before the grant may also remain available; they are listed on the license document if any exist **[Sean to confirm per track]**.
- **Deliverables:** MP3, WAV master and stems (`download_mp3`, `master_wav`, `stems_zip`).
- **Term:** perpetual. **Territory:** worldwide.
- **Permitted:** one New Song with no numeric limits on any use; synchronization in any media; paid advertising; sublicensing of the New Song (for example to a label, a distributor, a sync agency or a production) on terms consistent with this license; registering **the New Song** (not the Beat on its own) with Content ID.
- **Content ID duty.** If the Licensee registers the New Song with Content ID, the Licensee must, within 7 days of a request, release claims against (a) songs made under licenses granted before this exclusive and (b) Licensor's own pre-existing uses of the Beat, and must instruct its distributor accordingly.
- **Not permitted:** resale or distribution of the Beat or stems on their own; using the Beat in a second song without a separate agreement; claiming authorship of the Beat.
- **Ownership.** As in section 4.5: this is an exclusive **license**; copyright in the Beat stays with Licensor unless a separate written assignment signed by Licensor says otherwise. **[Sean to decide whether exclusives transfer the Beat's sound-recording copyright; see section 8.]**
- **Portfolio use.** Licensor may continue to identify the Beat and the New Song as Licensor's work and play short excerpts in a professional portfolio, but will not license the Beat to anyone else.
- **Credit, publishing (50%), producer tag, termination and transfer:** as in section 4. An exclusive license may be transferred under section 4.12.
- **Refund.** If the purchase is refunded, the exclusive license ends; whether the Beat is offered again is decided by VASEY.AUDIO after review.

## 6. Admin mapping (typed license fields)

This section tells an operator how to author each tier in the administration panel (`app/Filament/Resources/LicenseTemplateResource.php`, `app/Filament/Resources/LicenseVersionResource.php`) using the current schema version 4 (`app/Domain/Rights/LicenseTerms.php`, `SCHEMA_VERSION = 4`; `docs/license-economic-policies.md`). **Do not author these until Sean has approved the text and numbers.** Authoring creates a draft; a different authorized administrator must review the exact preview and hash before publication, and the commercial offer (price in U.S. cents, exact deliverable files, rights-scope link) is published separately (`docs/licensing-and-offers.md`; `docs/shared-rights-inventory.md`).

### 6.1 Templates

| Tier | Template name | Template type |
| --- | --- | --- |
| Free Promotional | Free Promotional License | `non-exclusive` (the free-grant family requires a published non-exclusive license: `app/Domain/Grants/Free/FreeGrantSources.php`; the `free` type is commerce-blocked) |
| Basic Lease | Basic Lease | `non-exclusive` |
| Premium Lease | Premium Lease | `non-exclusive` |
| Trackout Lease | Trackout Lease | `non-exclusive` |
| Unlimited Lease | Unlimited Lease | `non-exclusive` |
| Exclusive Rights | Exclusive Rights License | `exclusive` (currently "commerce blocked": production exclusive sale is not yet admitted) |

### 6.2 Typed values per tier

Schema 4 `structured_terms`, as the domain validates them. Caps are integers (no thousands separators in the stored value). Policy keys and texts are in section 6.4. On 2026-10-24 all six tiers, built from these values and the section 6.4 policy texts, passed `App\Domain\Rights\LicenseTerms::validate()` and generated 21 card statements each at source `786fb7c7` (a read-only check; nothing was saved).

**Basic Lease**

```json
{
  "schema_version": 4,
  "required_asset_roles": ["download_mp3"],
  "usage": {
    "audio_releases": {"mode": "limited", "limit": 2},
    "copies_downloads": {"mode": "limited", "limit": 5000},
    "monetized_streams": {"mode": "limited", "limit": 100000},
    "non_monetized_streams": {"mode": "unlimited"},
    "music_videos": {"mode": "limited", "limit": 1},
    "live_performances": {"mode": "limited", "limit": 10},
    "radio_stations": {"mode": "prohibited"}
  },
  "permissions": {"content_id": "prohibited", "paid_advertising": "prohibited", "sublicensing": "prohibited", "standalone_resale": "prohibited"},
  "credit": {"mode": "required", "text": "Prod. by Sean Vasey"},
  "territory": {"mode": "worldwide"},
  "duration": {"mode": "fixed_months", "starts_at": "grant", "months": 120},
  "ownership": {
    "source_recording": {"policy_key": "ownership-nonexclusive"},
    "source_composition": {"policy_key": "ownership-nonexclusive"},
    "resulting_recording": {"policy_key": "ownership-nonexclusive"},
    "resulting_composition": {"policy_key": "ownership-nonexclusive"}
  },
  "publishing_income": {"mode": "share", "policy_key": "publishing-share", "licensor_bps": 5000},
  "recording_royalty": {"mode": "none", "policy_key": "royalty-none"},
  "policies": [
    {"key": "ownership-nonexclusive", "version": "draft-2026-10-24", "text": "(section 6.4)"},
    {"key": "publishing-share", "version": "draft-2026-10-24", "text": "(section 6.4)"},
    {"key": "royalty-none", "version": "draft-2026-10-24", "text": "(section 6.4)"}
  ]
}
```

**Differences for the other tiers** (every other field as Basic):

| Field | Free Promotional | Premium Lease | Trackout Lease | Unlimited Lease | Exclusive Rights |
| --- | --- | --- | --- | --- | --- |
| `required_asset_roles` | `["download_mp3"]` | `["download_mp3","master_wav"]` | `["download_mp3","master_wav","stems_zip"]` | `["download_mp3","master_wav","stems_zip"]` | `["download_mp3","master_wav","stems_zip"]` |
| `usage.audio_releases` | `limited` 1 | `limited` 3 | `limited` 3 | `unlimited` | `unlimited` |
| `usage.copies_downloads` | `prohibited` | `limited` 10000 | `limited` 20000 | `unlimited` | `unlimited` |
| `usage.monetized_streams` | `prohibited` | `limited` 250000 | `limited` 500000 | `unlimited` | `unlimited` |
| `usage.non_monetized_streams` | `limited` 25000 | `unlimited` | `unlimited` | `unlimited` | `unlimited` |
| `usage.music_videos` | `limited` 1 | `limited` 2 | `limited` 3 | `unlimited` | `unlimited` |
| `usage.live_performances` | `prohibited` | `unlimited` | `unlimited` | `unlimited` | `unlimited` |
| `usage.radio_stations` | `prohibited` | `limited` 2 | `limited` 2 | `unlimited` | `unlimited` |
| `permissions.content_id` | `prohibited` | `prohibited` | `prohibited` | `prohibited` | `permitted` |
| `permissions.paid_advertising` | `prohibited` | `prohibited` | `prohibited` | `prohibited` | `permitted` |
| `permissions.sublicensing` | `prohibited` | `prohibited` | `prohibited` | `prohibited` | `permitted` |
| `duration` | `perpetual` | `fixed_months` 120 | `fixed_months` 120 | `perpetual` | `perpetual` |
| `ownership.*.policy_key` | `ownership-nonexclusive` | `ownership-nonexclusive` | `ownership-nonexclusive` | `ownership-nonexclusive` | `ownership-exclusive` |
| `policies` keys | `ownership-nonexclusive`, `publishing-share`, `royalty-none` | same | same | same | `ownership-exclusive`, `publishing-share`, `royalty-none` |

A perpetual duration is stored as `{"mode": "perpetual", "starts_at": "grant"}`. Every policy listed must be referenced and every reference must resolve (`docs/license-economic-policies.md`).

### 6.3 License source skeleton

Every schema 4 source must contain all 22 variables (the 13 version 2 variables, `territory` and `duration`, the four ownership variables, `publishing_income`, `recording_royalty`, and `policy_texts` exactly once). Each variable is replaced by its complete generated statement, label included (`docs/typed-license-terms.md`; `docs/license-territory-duration.md`). The source may be up to 100,000 bytes (`app/Domain/Rights/LicenseContent.php`) and must not contain buyer, order or seller variables; the contract document prints those separately.

Suggested layout for each tier's source:

```text
[Tier name] — VASEY.AUDIO license, version [label]

[Section 5 schedule for the tier, as prose]

Files and scope
{{deliverables}}
{{territory}}
{{duration}}

Limits for the New Song
{{usage.audio_releases}}
{{usage.copies_downloads}}
{{usage.monetized_streams}}
{{usage.non_monetized_streams}}
{{usage.music_videos}}
{{usage.live_performances}}
{{usage.radio_stations}}

Permissions
{{permissions.content_id}}
{{permissions.paid_advertising}}
{{permissions.sublicensing}}
{{permissions.standalone_resale}}
{{credit}}

Ownership and income
{{ownership.source_recording}}
{{ownership.source_composition}}
{{ownership.resulting_recording}}
{{ownership.resulting_composition}}
{{publishing_income}}
{{recording_royalty}}

[Section 4 common terms, 4.1 to 4.14, as prose]

Policies referenced above
{{policy_texts}}
```

The generated statements read, for example, "Monetized streams: up to 100,000.", "Content ID registration: not permitted.", "Producer credit required: Prod. by Sean Vasey", "License duration: 120 calendar months from the grant timestamp (UTC; end-of-month clamping).", "Publishing income: licensor share is 50.00% of total publishing income attributable to the resulting composition, as defined by retained policy publishing-share @ draft-2026-10-24." (`app/Domain/Rights/TypedLicenseTerms.php`; `app/Domain/Rights/EconomicLicenseTerms.php`). The surrounding prose in sections 4 and 5 uses the same numbers; the reviewer must confirm they agree.

**Paid live performances.** The typed label is "Live performances". Section 4.2 defines it as paid performances, and unpaid performances are always allowed; keep that definition in the source so the card statement is read correctly.

### 6.4 Policy texts

Plain text, no `{{` or `}}`, at most 20,000 bytes each. Version label `draft-2026-10-24` until Sean approves; change the label whenever the text changes.

**`ownership-nonexclusive`**

> The Licensor, Sean Vasey, keeps all ownership of the licensed Beat: its sound recording (source recording) and its musical composition (source composition). This license transfers no copyright in either. The Licensee owns the copyright in the Licensee's own original contributions to the New Song. The New Song's sound recording (resulting recording) embodies the Beat; the Licensee may exploit it only within this license, and the Licensor's rights in the Beat continue in it. The New Song's composition (resulting composition) is shared between the Licensor, as writer and publisher of the Beat's music, and the Licensee's writers, in the proportions stated in the publishing-income policy. This license is non-exclusive: the Licensor may license the Beat to others and may later grant an exclusive license, which does not revoke or shorten this license.

**`ownership-exclusive`**

> The Licensor, Sean Vasey, keeps ownership of the licensed Beat's sound recording (source recording) and musical composition (source composition). This exclusive license transfers no copyright in either unless a separate written assignment signed by the Licensor says so. From the grant time the Licensor will grant no new license of the Beat to anyone. Licenses of the Beat granted before the grant time remain valid for their own term and limits, and this exclusive license is subject to them. The Licensee owns the copyright in the Licensee's own original contributions to the New Song. The New Song's sound recording (resulting recording) embodies the Beat; the Licensee may exploit it in any media within this license. The New Song's composition (resulting composition) is shared between the Licensor, as writer and publisher of the Beat's music, and the Licensee's writers, in the proportions stated in the publishing-income policy.

**`publishing-share`**

> The Licensor's share of the resulting composition (the New Song's musical composition) is 50 percent, covering both the writer's share and the publisher's share. The Licensor is entitled to 50 percent of all publishing income attributable to the resulting composition from every source, including performance, mechanical, synchronization, print and neighbouring-rights income where it is paid to composition owners. When registering the New Song with any performing rights organization, collecting society, publishing administrator or distributor, the Licensee must list Sean Vasey as a writer and his publishing designee as publisher for this share, using details the Licensor provides on request at support@vasey.audio. Where income is paid to the Licensee for the Licensor's share, the Licensee must account for and pay it to the Licensor within 60 days after receipt. This entitlement is not a split of the price paid for the license and is not a share of recording income.

**`royalty-none`**

> No recording royalty is payable by the Licensee to the Licensor under this license. The Licensee keeps all income paid to the owner of the New Song's sound recording, subject to the Licensor's publishing-income entitlement and to any obligation the Licensee owes to third parties.

### 6.5 Feature summaries (comparison copy and schema 1 fallback)

Schema 2 to 4 licenses generate their card statements from the typed fields; editable feature lists are rejected for them. The lists below are for the store's comparison copy, or for a schema 1 license if one is ever needed (1 to 20 distinct plain strings, each at most 240 characters: `app/Domain/Rights/LicenseTerms.php`).

- **Free Promotional:** "MP3 file"; "One new song, non-commercial use only"; "1 non-commercial release"; "Up to 25,000 non-monetized streams"; "1 non-monetized music video"; "No sales, downloads or monetized streams"; "No radio, paid shows or Content ID"; "Worldwide, perpetual"; "Credit: Prod. by Sean Vasey"; "Upgrade to a paid license before monetizing".
- **Basic Lease:** "MP3 file (untagged)"; "One new song"; "Up to 2 audio releases"; "Up to 5,000 copies or downloads"; "Up to 100,000 monetized streams"; "Unlimited non-monetized streams"; "1 music video"; "Up to 10 paid live performances"; "No radio broadcast or Content ID"; "Worldwide, 10 years"; "Credit: Prod. by Sean Vasey"; "Sean Vasey keeps 50% of publishing".
- **Premium Lease:** "WAV master and MP3"; "One new song"; "Up to 3 audio releases"; "Up to 10,000 copies or downloads"; "Up to 250,000 monetized streams"; "Unlimited non-monetized streams"; "2 music videos"; "Unlimited paid live performances"; "Up to 2 radio stations"; "No Content ID"; "Worldwide, 10 years"; "Credit: Prod. by Sean Vasey"; "Sean Vasey keeps 50% of publishing".
- **Trackout Lease:** "WAV master, MP3 and stems"; "One new song"; "Up to 3 audio releases"; "Up to 20,000 copies or downloads"; "Up to 500,000 monetized streams"; "Unlimited non-monetized streams"; "3 music videos"; "Unlimited paid live performances"; "Up to 2 radio stations"; "Stems for your mix only, never shared"; "No Content ID"; "Worldwide, 10 years"; "Credit: Prod. by Sean Vasey"; "Sean Vasey keeps 50% of publishing".
- **Unlimited Lease:** "WAV master, MP3 and stems"; "One new song"; "Unlimited releases, copies and downloads"; "Unlimited streams and music videos"; "Unlimited paid shows and radio"; "Sync in your own videos, films and podcasts"; "Non-exclusive: others may license this beat"; "No Content ID or third-party sync licensing"; "Worldwide, perpetual"; "Credit: Prod. by Sean Vasey"; "Sean Vasey keeps 50% of publishing".
- **Exclusive Rights:** "WAV master, MP3 and stems"; "One new song, no usage caps"; "No new licenses of this beat after your purchase"; "Licenses sold before your purchase stay valid"; "Sync, advertising and sublicensing allowed"; "Content ID for your new song (release earlier licensees)"; "Worldwide, perpetual"; "Credit: Prod. by Sean Vasey"; "Sean Vasey keeps 50% of publishing"; "Copyright in the beat stays with Sean Vasey".

### 6.6 What the store enforces and what is contractual

| Term | Enforced by the code | Contractual only |
| --- | --- | --- |
| Deliverables per tier | Yes: the offer's exact files must match `required_asset_roles`; only those revisions are delivered (`docs/test-owner-delivery.md`). Stems need a ready, recording-bound `stems_zip` (`docs/media-processing.md`). | — |
| Full terms shown before purchase and frozen | Yes: exact license revision disclosed and frozen into order and contract (`docs/track-detail-and-license-disclosure.md`, `docs/order-preparation.md`). | — |
| Prior licenses retained after a new version or an exclusive sale | Yes: published versions are immutable; `license_terms.prior_grants = retain`; no revocation path (`MachinePolicyV1`; `docs/exclusive-selection.md`). | — |
| Exclusive serialization | Yes, in the test path: one exclusive per rights scope, reservation during checkout, pending non-exclusive sales block (`docs/shared-rights-inventory.md`). Production exclusive sale is not yet admitted. | — |
| Free claim once per account, capacity and download caps | Yes (`docs/free-grants.md`). Production free family is default-off. | — |
| Stream, copy, video, show and radio caps | No: the store does not observe releases or streams. | Yes |
| Term expiry (120 months) | No: the duration is recorded and printed; no expiry is calculated or enforced (`docs/license-territory-duration.md`: grant-time calculation is WP-08 work). Downloads are not tied to the term. | Yes |
| Territory | No: territory describes permitted use, not buyer location; no geolocation (`docs/license-territory-duration.md`). | Yes |
| Credit, publishing share, Content ID, AI use, sync, resale | No | Yes |
| Termination on refund or breach | Partly: staff can block delivery as a technical restriction; a refund does not automatically change the grant (`docs/test-refunded-exception-resolution.md`). | Yes |
| Upgrades between tiers | No: license upgrades are not implemented (U-11; parity row FP-057). | Yes (buy the higher tier) |

## 7. What happens to existing licenses when an exclusive is sold

1. Every non-exclusive and free license of the Beat granted before the exclusive grant time stays valid for its own term and limits. Nothing is revoked, shortened or re-priced (AGENTS.md invariant; parity row FP-052).
2. From the exclusive grant time, the store stops selling any license of the Beat. In the software, the exclusive and non-exclusive offers of a track share one rights scope, so an exclusive reservation or sale blocks new non-exclusive sales (`docs/exclusive-selection.md`, `docs/shared-rights-inventory.md`).
3. Holders of earlier licenses cannot renew, upgrade or buy a higher tier for that Beat after the exclusive sale. When a fixed-term license ends, it cannot be renewed.
4. The exclusive buyer is told before purchase (in the Exclusive Rights schedule) that earlier licenses may exist and may ask how many and of which tiers.
5. If the exclusive buyer registers the New Song with Content ID, they must release claims against songs made under earlier licenses (section 5.6).
6. Licenses imported from BeatStars-era sales (if any are ever imported) are earlier licenses on the same footing; their own original terms continue to govern them (U-09).

## 8. Open items for Sean

1. **Confirm or change every number in section 2** (caps, 10-year term for Basic, Premium and Trackout, 50% publishing share).
2. **Set every price** (USD, entered as whole cents on each offer).
3. **Producer credit wording** ("Prod. by Sean Vasey" proposed).
4. **Exclusive ownership:** keep the default (exclusive license, no copyright transfer), or offer an assignment of the Beat's sound-recording copyright on exclusive purchase. If assignment is chosen, `ownership-exclusive` must be rewritten and reviewed.
5. **Exclusive recording royalty:** none proposed. If Sean wants one (for example a percentage of net receipts), set `recording_royalty` to `{"mode": "rate", "rate_bps": N, "basis": "net_receipts"}` with a new policy text that defines receipts, accounting and payment.
6. **Publishing details** for section 4.6: Sean's PRO, IPI number and publishing designee, given to licensees on request.
7. **Free downloads are delivered untagged.** The free-grant family delivers the untagged `download_mp3` (or `master_wav`); the code has no tagged-download role. If Sean wants free downloads tagged, that needs a code change.
8. **Free tier delivery and caps:** whether the free license should exist at launch, and its availability limits per track (authored on each free definition).
9. **Sync scope for leases** (own videos only) and for the Unlimited Lease (own audiovisual works, no third-party licensing).
10. **Per-track disclosures for exclusives:** any earlier releases Sean made of a Beat himself (section 5.6).
11. **Iowa venue county** for section 4.14.

## Change log

- 2026-10-24: first draft from source `786fb7c755291184f9e01b4faaab28dbd430d698`, after Sean named the merchant (Vasey Multimedia dba VASEY.AUDIO), the licensor (Sean Vasey) and Iowa governing law, and assigned the license tiers to the development lead. Not approved; no license version, offer or contract was created.
