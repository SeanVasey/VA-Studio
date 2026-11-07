# Production buyer contracts from Sean's license templates (plan, 2026-11-07)

Status: **plan; increment 3 (variable binding) is implemented as a pure binder, not wired into issuance.** It narrows the production part of
[U-06](architecture/decision-register.md) and follows from the real BeatStars contract evidence (SRC-027 to SRC-034,
[dry-run record](verification/beatstars-catalog-dry-run-20261107/README.md)). Buyer contracts stay test-only until each gate
below is met.

## Current state

- Every issued test contract is rendered from frozen grant sections by a pinned, test-only TCPDF profile
  (`test-buyer-pdf-v1`, with v2 as a candidate). It uses DejaVu Sans with no branding, and Sean's contract text never reaches it.
- License versions already keep an authored source text, typed and economic terms (schema v4) and frozen policy text, all
  reviewed and immutable once published. They are the natural home for Sean's template text.
- G-1 (tier usage limits) is implemented as [license terms schema v5](license-usage-v5.md): one usage slot for each tier
  limit, so increment 1 maps every tier variable to its own statement.
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
2. **Buyer identity capture (C-1, C-2, D-1)**, designed in [D-30](architecture/D-30-buyer-contract-identity.md). Legal name and postal address at the agreement step. They are validated, stored
    encrypted with the grant input, covered by retention and data-rights export/deletion, and disclosed in the privacy policy
    first. Never inferred from payment or account data.
3. **Variable binding. Implemented 2026-11-07** as a pure binder (see [Variable binding](#variable-binding-increment-3-implemented-2026-11-07)).
   A closed map from the frozen grant input to the template variables. Any unknown or unfilled placeholder refuses, and nothing
   is substituted silently. Golden synthetic fixtures included. Not yet wired into issuance; increment 4 consumes it.
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
  amounts. Increment 3's binder (below) maps them; nothing substitutes them into an issued contract yet.

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
| `AUDIO_STREAMS` (5) | license | v5 usage `audio_streams` |
| `NON_MONETIZED_VIDEO_STREAMS_ALLOWED` (5) | license | v5 usage `non_monetized_video_streams` |
| `MONETIZED_VIDEO_STREAMS_ALLOWED` (5) | license | v5 usage `monetized_video_streams` (G-1) |
| `FREE_DOWNLOADS` (5) | license | v5 usage `free_downloads` (G-1) |
| `PERFORMANCES_FOR_PROFIT` (5), `PERFORMANCES_NOT_FOR_PROFIT` (5) | license | v5 usage `for_profit_performances`, `not_for_profit_performances` (G-1) |

The license-level rows above assume schema 5 vocabulary. In schemas 2–4, `music_videos` means plain "Music videos", so the
contract profile must accept **only schema 5 license sources**. A v4 limit must never fill a "monetized" placeholder.

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

**Decided 2026-11-07 by Sean: schema v5**, delivered as its own reviewed increment before increment 1. Implemented in [license terms schema v5](license-usage-v5.md).

### D-1: `CUSTOMER_ALIAS` in the Exclusive template

The Exclusive agreement names the buyer's performing name ("the musical performance of {CUSTOMER_ALIAS}"). Checkout has no
such field. **Decided 2026-11-07 by Sean:** Exclusive checkout asks for the buyer's artist/stage name, in addition to the legal name.
It is frozen into the grant input with the identity fields from increment 2.

## Variable binding (increment 3, implemented 2026-11-07)

Code: `App\Domain\Contracts\Production\ContractVariableBinder`, with `ContractLicenseConstants`, `ContractNumberWords` and
`ContractBindingException`. Tests: `tests/Unit/ContractVariableBinderTest.php` (synthetic, nonbinding fixtures). It is pure:
no database, file, clock, locale or network. It is **not wired** into issuance, a renderer or a profile, and no hash-pinned
file changed. This is the binder part of D-30 step 7; the profile part belongs to increment 4.

**Input.** One frozen grant render input (`paid-tax-render-input-v2` over a V2 tax source, or `paid-grant-render-input-v1`
over a V1 source), the license version's structured terms, the two license constants, and the pinned glyph coverage used to
re-verify the buyer declaration. The terms must be schema 5 and canonically equal to the line's frozen
`source.license.structured_terms`; a v4 line or v4 terms refuse with `license_schema_unsupported`, so a v4 limit never fills
a "monetized" placeholder. The render input's `buyer.declarations`, `license.name`/`type` and `effective_at` must equal the
source's frozen copies.

**Output.** Placeholder to string, in the table's order: 22 entries for an Exclusive line, 21 otherwise. `CUSTOMER_ALIAS` is
absent (not blank) on a non-exclusive line, so a template that uses it there refuses with `missing_value`.

| Placeholder | Bound value (format pinned by the golden tests) |
| --- | --- |
| `CONTRACT_DATE` | UTC calendar date of `effective_at`, English month: `November 7, 2026` |
| `CUSTOMER_FULLNAME` | v2 `legal_name` (for a business, the business's legal name) |
| `CUSTOMER_ADDRESS` | `ContractIdentityDeclarationV2::customerAddress()` |
| `CUSTOMER_ALIAS` | v2 `artist_name`, Exclusive only |
| `PRODUCT_TITLE`, `LICENSE_NAME` | frozen `source.product.title`, `source.license.name` |
| `PRODUCT_PRICE`, `PRODUCT_PRICE_WORD` | the **pre-tax** USD line amount: `$1,500.50`, `one thousand five hundred dollars and fifty cents` |
| `PRODUCER_ALIAS`, `STATE_PROVINCE_COUNTRY` | `ContractLicenseConstants`, supplied by the caller from the license source; no default in code |
| `FILE_TYPE` | `required_asset_roles` in the reviewed deliverable labels: `MP3, WAV master and Stems ZIP` |
| `PUBLISHING_RIGHTS` | `publishing_income.licensor_bps` as a percentage: `50%`, `12.5%` |
| usage placeholders | limited: the limit with thousands separators (`250,000`); unlimited: `unlimited` |
| `MONETIZED_MUSIC_VIDEOS_WORD` | limited: the limit in words (`one`); unlimited: `unlimited` |

**Refusals** (`ContractBindingException::$reason`, with the placeholder where one is involved): a legacy two-key declaration
(`contract_identity_missing`), a v2 declaration that does not re-verify for the line, such as a missing Exclusive artist name
(`contract_identity_invalid`), a prohibited use (`usage_prohibited`), publishing mode `none` (`publishing_share_unbound`), a
non-USD price (`currency_unsupported`), an amount outside 1 to 2,147,483,647 minor units or unequal to the taxed subtotal
(`amount_invalid`), a `free` license (`license_type_unsupported`) and any other shape drift (`unsupported_input`).

**Substitution.** `ContractVariableBinder::substitute($template, $values)` replaces each `{NAME}` in one pass. It refuses a
name outside the closed set (`unknown_placeholder`), a name with no value in the map (`missing_value`), a map entry outside
the set or with an empty value, and any `{` or `}` in the template that is not part of a well-formed placeholder, including
leftover `{{…}}` license source variables (`malformed_template`). Substituted values are never scanned again, so buyer text
such as a legal name of `{PRODUCT_TITLE}` prints literally.

**Business licensee.** No template placeholder carries the representative, and the binder does not output
`representative_name`. The frozen declaration keeps it, so increment 4 can show it outside the template text, for example in
the profile's acceptance or signature block next to the business's name. The wording and placement of that line are not
decided; they need Sean's approval (and counsel's, with C-3) before a business contract is issued.

**Open before increment 4 renders a real template** (decisions for Sean, and counsel where noted; the binder refuses or uses
the pinned format until then):

- **Prohibited uses.** A prohibited use refuses rather than printing `0` or removing a clause. Sean decides, per tier, whether
  a prohibited use is written as zero or the clause is dropped from that tier's template.
- **No publishing share.** Publishing mode `none` refuses for the same reason.
- **Formats in context.** Each pinned format must be checked against the words around its placeholder in the six templates:
  for example `$` or `%` already in the template text, `unlimited` read inside a sentence, capitalisation, and whether the
  price words should say "US dollars".
- **Pre-tax price.** `PRODUCT_PRICE` is the license fee before tax. Sean confirms that the contract states the pre-tax fee.
- **License constants.** The producer alias and "State of Iowa, United States" (the dry run asked Sean to confirm Iowa) are
  inputs, not code. Increment 1 records where each license source fixes them.
- **Usage `audio_releases`** has no template placeholder. It still appears in the license's statement schedule.

