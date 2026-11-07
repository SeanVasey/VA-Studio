# Production buyer contracts from Sean's license templates (plan, 2026-11-07)

Status: **plan only, nothing implemented.** It narrows the production part of
[U-06](architecture/decision-register.md) and follows from the real BeatStars contract evidence (SRC-027 to SRC-034,
[dry-run record](verification/beatstars-catalog-dry-run-20261107/README.md)). Buyer contracts stay test-only until each gate
below is met.

## Current state

- Every issued test contract is rendered from frozen grant sections by a pinned, test-only TCPDF profile
  (`test-buyer-pdf-v1`, with v2 as a candidate). It uses DejaVu Sans with no branding, and Sean's contract text never reaches it.
- License versions already keep an authored source text, typed and economic terms (schema v4) and frozen policy text, all
  reviewed and immutable once published. They are the natural home for Sean's template text.
- Sean has six VASEY.AUDIO templates (Basic, Premium, Premium Plus, Unlimited, Custom Beat, Exclusive), kept in private
  storage. They are derived from his verbatim BeatStars contracts:
  - BeatStars references are removed and the undefined placeholders are fixed.
  - The typos are corrected, and the unfilled law-firm notice is removed.
  - The layout is branded with the site's design.
  - His signature appears on the Producer/Licensor line.

  The licensor is Sean personally (his decision, 2026-11-07). Two items are new wording: the drafted no-samples warranty, and
  the removal of the law-firm sentence. Counsel should review both.

## Target

A new reviewed profile, `vasey-license-pdf-v1`. It renders the license version's frozen template text, with its placeholders
substituted only from the frozen grant input. The output is a branded PDF with:

- the VASEY.AUDIO lockup (`public/brand/vasey-audio-logo.png`, unmodified) on the charcoal band;
- Bebas Neue, Reddit Sans and JetBrains Mono (OFL, already in `public/brand/fonts/`), converted for the renderer and pinned by
  hash;
- Sean's signature image, read from private storage and pinned by SHA-256 in the profile manifest. The image never goes into
  Git or a public path, and a missing or changed file refuses issuance.

Existing originals stay byte-preserved, and v1 and v2 stay registered for retained evidence.

## Decisions needed before implementation

| # | Decision | Why it blocks |
| --- | --- | --- |
| C-1 | **Buyer address: decided 2026-11-07 by Sean, collect it.** The clause "{CUSTOMER_FULLNAME} residing at {CUSTOMER_ADDRESS}" stays as written. Checkout gains a postal address step, frozen into the grant input. Before collection starts, the privacy policy, retention schedule and customer data-rights export/deletion must cover it. | Checkout fields, privacy and retention work (new increment 2a). |
| C-2 | **Buyer legal name: decided 2026-11-07 by Sean, confirmed at checkout.** The buyer types or confirms their legal name at the agreement step, and that exact name is frozen as `{CUSTOMER_FULLNAME}`. The account name is not substituted. | Assent screen and frozen input. |
| C-3 | **Counsel review** of the drafted no-samples warranty and the removed law-firm notice sentence. | It is new legal wording. |
| C-4 | **Per-track tier enablement.** The export enables Premium on 22 tracks, Unlimited on 7 and Exclusive (offer-only) on 1, on top of Basic and Premium Plus. These become individual offer approvals. | It decides the offer drafts after import. |

## Implementation increments (each its own reviewed PR)

1. **License sources.** Author the six templates as license versions (source text, plus v4 terms mapped from the tier
   variables), and get human review. This needs a live target, which means staging.
2. **Buyer identity capture (C-1, C-2).** Legal name and postal address at the agreement step. They are validated, stored
    encrypted with the grant input, covered by retention and data-rights export/deletion, and disclosed in the privacy policy
    first. Never inferred from payment or account data.
3. **Variable binding.** A closed map from the frozen grant input to the template variables. Any unknown or unfilled placeholder
   refuses issuance, and nothing is substituted silently. This includes golden synthetic fixtures.
4. **Profile `vasey-license-pdf-v1`.** Pin the fonts, logo and signature hash, the template layout and the renderer graph.
   Determinism tests and a mutation check prove that a changed signature or font refuses issuance.
5. **Activation.** Select the profile for new issuance only, never rerendering old originals. Independent review is required.

The current `tc-lib` pins and the held `tc-lib` updates (Dependabot #162/#164) are re-evaluated in increment 4. A new profile is
the one place a renderer upgrade can land safely.

## Variable map (increment 3 design, 2026-11-07)

The six templates use 22 placeholders, and each one needs exactly one source. Two layers already exist:

- **License-level `{{…}}` variables** in a license version's authored source. `LicenseSourceVariables::render` substitutes them
  at authoring time from the structured terms (`LicenseTerms::sourceValues`). It rejects unknown keys and requires every
  terms statement to appear.
- **Purchase-level fields** in the frozen grant input (`PaidGrantRenderInput`). These are `effective_at`,
  `buyer.declarations.legal_name` (already declared at checkout), the seller, the line's track title and artist, and the
  amounts. Nothing substitutes them into a template yet; increment 3 adds that binder.

| Placeholder (count) | Layer | Source |
| --- | --- | --- |
| `CONTRACT_DATE` (18) | purchase | `effective_at`, as a UTC calendar date |
| `CUSTOMER_FULLNAME` (11) | purchase | `buyer.declarations.legal_name` (C-2) |
| `CUSTOMER_ADDRESS` (5) | purchase | the new declared postal address (C-1, increment 2). Refuses until it is captured. |
| `CUSTOMER_ALIAS` (2, Exclusive) | purchase | the declared artist/stage name, asked at Exclusive checkout (D-1, decided) |
| `PRODUCT_TITLE` (7) | purchase | the line's frozen `offer_snapshot.product.title` |
| `PRODUCT_PRICE` (6), `PRODUCT_PRICE_WORD` (1) | purchase | the line's frozen amount in minor units, with words from a fixed deterministic speller |
| `LICENSE_NAME` (10) | purchase | the frozen `license.name` |
| `PRODUCER_ALIAS` (19) | license constant | the seller's display alias, fixed in each license source |
| `STATE_PROVINCE_COUNTRY` (14) | license constant | "State of Iowa, United States", fixed in each license source |
| `FILE_TYPE` (5) | license | deliverables from `required_asset_roles` |
| `PUBLISHING_RIGHTS` (6) | license | v4 `publishing_income` / ownership statements |
| `DISTRIBUTE_COPIES` (5) | license | usage `copies_downloads` |
| `NUMBER_OF_RADIO_STATIONS` (5) | license | usage `radio_stations` |
| `MONETIZED_MUSIC_VIDEOS` (5), `_WORD` (5) | license | usage `music_videos` |
| `AUDIO_STREAMS` (5) | license | usage `monetized_streams` |
| `NON_MONETIZED_VIDEO_STREAMS_ALLOWED` (5) | license | usage `non_monetized_streams` |
| `MONETIZED_VIDEO_STREAMS_ALLOWED` (5) | license | **gap: G-1** |
| `FREE_DOWNLOADS` (5) | license | **gap: G-1** |
| `PERFORMANCES_FOR_PROFIT` (5), `PERFORMANCES_NOT_FOR_PROFIT` (5) | license | **gap: G-1** (one `live_performances` slot for two terms) |

### G-1: the usage schema cannot express Sean's tiers

Sean's tiers distinguish four things the typed usage schema (`TypedLicenseTerms::USAGE`) has no slot for:

- monetized video streams, separately from audio streams;
- free downloads;
- for-profit performances;
- not-for-profit performances (the schema has only one combined performances slot).

There are two ways to bridge this:

- **Write the values as literal text** in the license source. The required `{{…}}` statement schedule would then summarise the
  same rights in coarser categories, so the prose and the schedule could disagree. A buyer could read a contract that
  contradicts itself.
- **A schema v5 with those slots.** This is the correct fix. Each value is entered once and then reaches the prose, the
  schedule, the storefront disclosure and the contract identically. It extends the reviewed licensing model, so it needs its
  own increment and independent review before increment 1 can author the six licenses.

**Decided 2026-11-07 by Sean: schema v5**, delivered as its own reviewed increment before increment 1.

### D-1: `CUSTOMER_ALIAS` in the Exclusive template

The Exclusive agreement names the buyer's performing name ("the musical performance of {CUSTOMER_ALIAS}"). Checkout has no
such field. **Decided 2026-11-07 by Sean:** Exclusive checkout asks for the buyer's artist/stage name, in addition to the legal name.
It is frozen into the grant input with the identity fields from increment 2.
