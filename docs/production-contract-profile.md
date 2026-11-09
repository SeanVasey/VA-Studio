# Production buyer contracts from Sean's license templates (plan, 2026-11-07)

Status: **plan; increment 3 (variable binding) is implemented as a pure binder, and increment 4 (the `vasey-license-pdf-v1`
profile) is built and pinned. Neither is wired into issuance or selected by any issuer. The `vasey-license-pdf-v2` successor
is selectable for new paid originals only behind a literal-false, local/testing-only gate (2026-11-08, "Issuer wiring"
below).** It narrows the production part of
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
4. **Profile `vasey-license-pdf-v1`. Built 2026-11-07, not selected for issuance** (see
   [Render profile](#render-profile-increment-4-built-2026-11-07)). Pin the fonts, logo and signature hash, the template
   layout and the renderer graph. Determinism tests and a mutation check prove that a changed signature or font refuses
   issuance.
5. **Activation.** Select the profile for new issuance only, never rerendering old originals. Independent review is required.

The current `tc-lib` pins and the held `tc-lib` updates (Dependabot #162/#164) are re-evaluated in increment 4. A new profile is
the one place a renderer upgrade can land safely. **Increment 4 kept the current pins** (tc-lib-pdf 8.76.3, tc-lib-pdf-font
4.4.1, the same graph as `test-buyer-pdf-v2`); #162/#164 stay held and must be decided before activation, because an upgrade
after activation needs a successor profile.

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
over a V1 source), the license version's structured terms, the two license constants, and the pinned glyph coverage.

- **Commitments.** Before reading any value, the binder recomputes the producers' hashes with `CanonicalJson::hash`:
  `source.source_hash`, `buyer_binding_hash`, `product_hash`, `assent_hash` and `license_hash`. It also checks that
  `input.source_commitments` names exactly that source hash, assent hash and license source, submission and review hashes,
  and that `input.assent` is the source's assent. An edited title, license or assent under a stale hash refuses with
  `unsupported_input`.
- **Terms.** They must be schema 5 and canonically equal to the line's frozen `source.license.structured_terms`. A v4 line or
  v4 terms refuse with `license_schema_unsupported`, so a v4 limit never fills a "monetized" placeholder.
- **Copies.** The render input's `buyer.declarations`, `license.name`/`type` and `effective_at` must equal the source's frozen
  copies.

**Output.** Placeholder to string, in the table's order: 22 entries for an Exclusive line, 21 otherwise. `CUSTOMER_ALIAS` is
absent (not blank) on a non-exclusive line, so a template that uses it there refuses with `missing_value`.

| Placeholder | Bound value (format pinned by the golden tests) |
| --- | --- |
| `CONTRACT_DATE` | UTC calendar date of `effective_at`, English month: `November 7, 2026` |
| `CUSTOMER_FULLNAME` | v2 `legal_name` (for a business, the business's legal name) |
| `CUSTOMER_ADDRESS` | `ContractIdentityDeclarationV2::customerAddress()` |
| `CUSTOMER_ALIAS` | v2 `artist_name`, Exclusive only (also for a business licensee) |
| `PRODUCT_TITLE`, `LICENSE_NAME` | frozen `source.product.title`, `source.license.name` |
| `PRODUCT_PRICE`, `PRODUCT_PRICE_WORD` | the **pre-tax license fee** in USD: `$1,500.50`, `one thousand five hundred dollars and fifty cents`. V2 tax-exclusive: the line subtotal. V2 tax-inclusive: the line subtotal less the line's own tax, exactly, in minor units. V1 (tax-exempt): the line amount, and the line's tax must be zero. |
| `PRODUCER_ALIAS`, `STATE_PROVINCE_COUNTRY` | `ContractLicenseConstants`, supplied by the caller from the license source; no default in code |
| `FILE_TYPE` | `required_asset_roles` in the reviewed deliverable labels: `MP3, WAV master and Stems ZIP` |
| `PUBLISHING_RIGHTS` | `publishing_income.licensor_bps` in the statement schedule's form: `12.50%`, `50.00%` |
| usage placeholders | limited: the limit with thousands separators (`250,000`); unlimited: `unlimited`; prohibited: `0` |
| `MONETIZED_MUSIC_VIDEOS_WORD` | limited: the limit in words (`one`); unlimited: `unlimited`; prohibited: `zero` |

**Printability.** The title, license name and both constants follow the D-30 character rule
(`ContractIdentityDeclarationV2::isPrintable`: no refused codepoint, at least one letter or digit, every codepoint covered
by the pinned faces), after a shape check and a 255-byte cap. A shape failure refuses with `value_invalid` and a character
failure with `unsupported_characters`, each naming the placeholder.

**Refusals** (`ContractBindingException::$reason`, with the placeholder where one is involved): a legacy two-key declaration
(`contract_identity_missing`), a v2 declaration that does not re-verify for the line, such as a missing Exclusive artist name
(`contract_identity_invalid`), publishing mode `none` (`publishing_share_unbound`), a non-USD price
(`currency_unsupported`), a fee outside 1 to 2,147,483,647 minor units, missing line tax or inconsistent tax totals
(`amount_invalid`), a `free` license (`license_type_unsupported`) and any other shape or commitment drift
(`unsupported_input`).

**Substitution.** `ContractVariableBinder::substitute($template, $values)` replaces each `{NAME}` in one pass. It refuses a
name outside the closed set (`unknown_placeholder`), a name with no value in the map (`missing_value`), a map entry outside
the set or with an empty value, a value containing a C0 control or DEL (`value_invalid`), and any `{` or `}` in the template
that is not part of a well-formed placeholder, including leftover `{{…}}` license source variables (`malformed_template`).
Substituted values are never scanned again, so buyer text such as a legal name of `{PRODUCT_TITLE}` prints literally.

### Decided by Sean with his lawyer (2026-11-07)

1. **Prohibited uses print `0`** (`zero` for the `_WORD` form). The clause is not dropped, so the template text stays
   verbatim. Publishing mode `none` still refuses until a tier uses it.
2. **The business representative** appears in the acceptance/signature block, outside the template text: "Accepted for
   {business legal name} by {representative name}, authorized representative." No template placeholder carries it, and the
   binder does not output `representative_name`. Increment 4 builds this line from the frozen declaration.
3. **The pinned formats are kept.** Amount words say "dollars", because the store is USD only.
4. **The contract states the pre-tax license fee**, for tax-exclusive and tax-inclusive lines alike.
5. **License constants:** producer alias "Sean Vasey"; governing place "State of Iowa, United States". They stay caller
   inputs (`ContractLicenseConstants`), not code. Increment 1 records them in each license source.

### Gates for increment 4

- The golden render of each of the six templates must refuse doubled symbols such as `$$` or `%%` where a template already
  writes a symbol next to its placeholder. **Implemented** (`doubled_symbol`); running it on the six real templates is an
  activation step, below.
- It must diff the placeholders actually present in the six real templates against `ContractVariableBinder::PLACEHOLDERS`
  (review P3-2), so a template placeholder the binder does not know is caught before activation. **The tool is implemented**
  (`scripts/contract-placeholder-census.php`); it has run only on synthetic fixtures.
- It renders the business representative line above. **Implemented.**

## Render profile (increment 4, built 2026-11-07)

Code: `App\Domain\Contracts\Production\VaseyLicenseProfile`, `VaseyLicenseDocument`, `VaseyLicensePdfRenderer`,
`VaseyLicenseLayout`, `VaseyLicenseSignature` and `ContractTemplateCensus`; `ContractGlyphCoverage::vaseyLicenseFaces()`.
Assets: `resources/contracts/vasey-license-v1/` (see its `PROVENANCE.md`). Tests: `tests/Unit/VaseyLicensePdfRendererTest.php`,
`VaseyLicenseGlyphCoverageTest.php` and `ContractTemplateCensusTest.php`, all on synthetic fixtures and a generated stand-in
signature.

**Registration.** The profile has its own registry class, pinned by the SHA-256 of its `profile-assets.json` exactly as
`test-buyer-pdf-v2` is pinned. It is deliberately not an entry in `ContractRenderProfileRegistry`. That file is frozen-byte
reviewed substrate for the production free grant family (`ProductionFreeGrantFrozenBytesTest`), and anything it lists is
accepted by `ContractRenderProfile::validate` on the test issuance path. No issuer, `VersionedContractRenderer`, policy or
configuration selects the new profile, and a test checks that.

**Manifest.** It pins the tc-lib package graph (unchanged), the implementation closure and 23 assets.

The implementation pin is derived mechanically by `VaseyLicenseImplementation::closure()`. It covers every application class
reachable from the renderer, the binder, the template census and the profile definition, through `use App\…` imports and
same-namespace references. Today that is 25 files:

- the renderer, layout, document and signature;
- the census, binder, number speller, license constants and binding exception;
- the v2 declaration, glyph coverage and `CheckoutException`;
- `TypedLicenseTerms`, `ScopedLicenseTerms`, `EconomicLicenseTerms`, `TieredLicenseTerms` and `LicenseTerritories`;
- `CanonicalJson`, `TestEnvironment`, the frozen `ContractRenderProfileRegistry` and `ContractIssuancePolicy`,
  `ContractIssuanceException` and `RenderedContract`;
- `VaseyLicenseProfileDefinition`, which holds the metadata, faces and logo path, and the closure class itself.

A test fails when the pin and the derived closure differ. `VaseyLicenseProfile`, which holds `MANIFEST_SHA256`, is the anchor
and cannot pin itself, so it carries no metadata, only the verification code, which is the same trust model as
`ContractRenderProfileRegistry`. Vendor code is pinned by package identity only, for example the Laravel validator that
`TieredLicenseTerms` calls.

The 23 assets are:

- the three brand woff2 faces and their OFL texts, byte-identical to `public/brand/fonts/`;
- four static TrueType files decoded with fontTools 4.68.0 and brotli 1.2.1 (`scripts/vasey-license-fonts.py`): Bebas Neue,
  Reddit Sans at `wght` 400 and 700 (the 700 instance renamed `RedditSans-Bold`), and JetBrains Mono at 400;
- tc-lib's twelve generated metric and program files;
- the unmodified lockup, `public/brand/vasey-audio-logo.png` (SHA-256 `7b0ffd26…f07571`).

`php scripts/build-contract-profile-vasey-license-v1.php --check` rebuilds every asset and the manifest in a temporary
directory and compares them byte for byte. Changing any pinned file therefore means a rebuilt manifest and a new
`MANIFEST_SHA256`, with review.

**Signature.** Sean's signature PNG is read at render time from the private local disk: `VASEY_LICENSE_SIGNATURE_PATH`,
relative to `storage/app/private`, never under `public/` and never in Git. `VASEY_LICENSE_SIGNATURE_SHA256` pins it. A
missing file or link refuses with `signature_unavailable`, changed bytes with `signature_changed`, and a non-PNG or oversized
image with `signature_invalid` (each a `ContractIssuanceException`). The PDF's recorded profile hash covers the signature pin,
so a different signature is a different profile identity. The constructor is private. Production code can only obtain a
signature through `fromConfig()`, with its private-disk path rules. The stand-in factory `forTesting()` is refused outside the
`testing` environment.

**Document (`vasey-license-text-v1`).** The input is the license version's frozen template text, the binder's output for the
line, and the line's frozen v2 declaration.

- The map must be exactly the binder's closed set in its order. `CUSTOMER_FULLNAME`, `CUSTOMER_ADDRESS` and `CUSTOMER_ALIAS`
  must equal the declaration's values.
- The declaration re-verifies against the profile's own faces.
- Substitution is the binder's one-pass `substitute`.
- Layout: blocks are separated by blank lines. The first block is the title. A one-line block whose own literal words, with
  placeholders removed, are all uppercase is a heading. Every other block is a paragraph that keeps its line breaks.
- Blocks are classified on the template before substitution, so a buyer value such as an all-caps legal name can never turn a
  paragraph into a heading.
- Tabs and carriage returns refuse.

**Layout.**

- A charcoal (`#29363f`) band with the lockup at its native aspect ratio and a turquoise rule.
- Bebas Neue for the title, headings and the two block labels; Reddit Sans for the body, justified; Reddit Sans Bold for the
  two names in the acceptance block; JetBrains Mono for the profile name and the document URN.
- The acceptance block follows the template text. On the left, under the label "PRODUCER / LICENSOR", are the signature, a
  rule, the producer alias and the date. On the right, under "LICENSEE", are the licensee's legal name, then for a business
  "Accepted for {business legal name} by {representative name}, authorized representative.", then the date. The labels and
  "Date:" are layout text for Sean's review, not template wording.

**Determinism.** The same document, profile and signature give byte-identical output, and a golden hash pins it. The creation
and modification dates are the contract date at UTC midnight. The file and document IDs derive from the input, profile and
signature hashes. Fonts are embedded whole and uncompressed (`font_subset: false`, `compression: false`), and the timezone is
forced to UTC. The output does not depend on the project root.

**Verified bytes.**

- The manifest is read once, and the bytes that are hashed are the bytes that are decoded.
- The logo and signature are drawn from the bytes that were hashed.
- tc-lib reads font files itself, so the renderer gives it a private temporary copy written from the verified bytes, and
  deletes it after rendering. Another profile's `K_PATH_FONTS` in the same process therefore cannot change the fonts.
- Implementation files are hashed on disk, but PHP runs whatever code it has loaded. With opcache and
  `opcache.validate_timestamps=0`, a server can keep serving code that differs from the pinned files until opcache is reset.
  Deployment must reset opcache whenever these files change; this is an activation condition.

One runtime dependency is outside the manifest: tc-lib separates a PNG's alpha channel with GD and re-encodes it, so the lockup
and the signature pass through the host's libgd, libpng and zlib. The golden hash was produced with GD 2.3.3 and zlib 1.3.
**Before activation, the golden render must be confirmed byte-identical on the Forge production host.** Otherwise the host's
own hash must be pinned, with review.

**Gates implemented** (each with a test):

- the business representative line, present only for a business;
- `doubled_symbol`, on the template and on every substitution boundary of the bound values. For `{PRODUCT_PRICE}` and
  `{PRODUCT_PRICE_WORD}`, any of these refuses:
  - a currency symbol (`\p{Sc}`: `$`, `€`, `£` …), a currency code (`USD`, `US`, `EUR`, `GBP`, `CAD`, `AUD`) or a dollar or
    cent word before the placeholder;
  - `dollars`, `cents` or `US Dollars` after it.

  `{PUBLISHING_RIGHTS}` followed by `%`, `percent`, `per cent`, `per-cent` or `pct` refuses. A currency code after the
  figure (`{PRODUCT_PRICE} USD`) is accepted on purpose: it names the currency of the `$` figure rather than repeating a
  symbol or unit.
- the placeholder census tool, which refuses any file inside the repository. It prints only placeholder names that match
  `^[A-Z0-9_]{1,64}$`, counts and line numbers. Any other brace content is reported only as a redacted line number, and an
  empty template is a finding (exit 1);
- printability in the profile's own faces.

**Glyph coverage, compared with DejaVu.** The v1 brand files are Fontsource **Latin subsets**. The four faces share 214
positive-width codepoints, all Latin or Common, and the D-30 rule accepts 212 of them (U+000D and U+00A0 are refused). The
`test-v2` DejaVu set the paid renderers use accepts 3,799 under its own script restriction, and the v1 set is a strict subset
of it. v1 covers Basic Latin, Latin-1 Supplement, ı, Œ, œ, the typographic quotes and dashes, € and ™, but not Latin
Extended-A and B (Ł, Ż, Ș, Ț, ğ, ş and others), Latin Extended Additional (Vietnamese), Greek or Cyrillic. Every pinned
country name prints.

**Successor profile `vasey-license-pdf-v2` (increment 5, part 1, built 2026-11-08, not selected for issuance).** Decided
2026-11-08 (decision register): the same three families with Latin Extended, as a successor; v1 stays byte-identical and pinned.

- Sources: the Fontsource 5.3.0 tarballs the brand manifest already pins (same SHA-256 and npm integrity). Each face is its
  `latin` subset (the brand file) merged with its `latin-ext` subset by `scripts/vasey-license-v2-fonts.py` (fontTools 4.68.0
  `merge`, Latin first, instanced before the merge; `head` dates restored from the Latin source). The three `latin-ext` woff2
  files are vendored in `resources/contracts/vasey-license-v2/fonts/source/` and pinned by SHA-256 in
  `scripts/build-contract-profile-vasey-license-v2.php`. `--check` rebuilds byte for byte; `--archives=DIR` proves every source
  face and license is the member of its pinned tarball. Bebas Neue has no Vietnamese subset; Reddit Sans and JetBrains Mono do,
  but they are not merged because the intersection could not use them.
- Pin: `VaseyLicenseV2Profile::MANIFEST_SHA256` over `profile-assets.json` (26 assets, the tc-lib graph and the implementation
  closure of `VaseyLicenseV2ProfileDefinition`, `VaseyLicenseV2Implementation`, the checkout printable set and, since part 2,
  the v2 renderer: 29 files).
- Coverage: the four faces share **359** positive-width codepoints, all Latin or Common, and the D-30 rule accepts **357**
  (U+000D and U+00A0 refused). That adds Latin Extended-A, the Latin Extended-B letters Fontsource's `latin-ext` subsets carry
  (Ș, ș, Ț, ț among them; not Ǎ, Ǧ, Ơ or Ư), and ẞ, Ẁ–ẅ, Ỳ and ỳ. The v1 set is a subset; the accepted set is a subset of what the `test-v2` paid renderers accept.
  "Łukasz Żółć", "Ștefan Țepeș", "Đorđe Đorđević" and "Gülşen Kılıç" print. Greek, Cyrillic, Vietnamese (ặ, ễ and the rest of
  Latin Extended Additional), Ə/ə and the titlecase digraphs (ǅ, ǈ, ǋ) are refused.
- **Checkout rebound (D-30 step 5).** Both production checkouts take the printable set from
  `ContractPrintableSet::checkout()` (the v2 faces, verified against the manifest hash and each metric file's SHA-256,
  restricted to `allowed_scripts`), and re-verify retained declarations with `ContractPrintableSet::verifyRetained()`.
  `ContractGlyphCoverage` and `ContractIdentityDeclarationV2` are in v1's implementation pin, so their `test-v2` methods
  (`pinnedContractFaces()`, `verifyRetained()`) are unchanged and no longer used by checkout.
- Tests: `VaseyLicenseV2ProfileTest` (pin, sources, closure, tamper refusals, counts, the subset of the paid renderers' rule,
  per-face drawability, names) and the Latin Extended cases in both checkout identity tests.

**v2 renderer (increment 5, part 2, built 2026-11-08; selectable for new paid originals only behind a local/testing gate).** `VaseyLicenseV2PdfRenderer` is the
successor of `VaseyLicensePdfRenderer`, which v1's manifest pins to `VaseyLicenseProfile` and which therefore stays
byte-identical.

- Reused unchanged: `VaseyLicenseDocument` (its `build()` takes the coverage as a parameter), `VaseyLicenseLayout` (takes the
  profile and the font directory; its page geometry is v1's `VaseyLicensePdfRenderer::geometry()`, identical for v2) and
  `VaseyLicenseSignature` with the `contracts.vasey_license_v1` pin (decided). Same template grammar, layout, acceptance
  block, determinism and verified-bytes handling as v1.
- New in the successor: the binding to `VaseyLicenseV2Profile` (version check, `verifyRuntime`, asset reads, hash), the
  creator string and keywords (`vasey-license-pdf-v2,<effective profile hash>`), the identifier line, and two entry points:
  `coverage()`, the renderer's input rule (the v2 faces via `ContractPrintableSet::faces()`), and `document()`, which builds
  the document under it.
- Pin: the renderer is a root of `VaseyLicenseV2Implementation`; its closure adds the reused v1 classes, the binder and the
  census, so a change to any of them refuses v2 (`profile_changed`) until the v2 manifest is rebuilt. Since the isolated
  render (activation blockers below, 2026-11-09) the roots also include its application-free core (`VaseyLicenseV2Render`)
  and the child script (`scripts/render-vasey-license-v2.php`); the parent-side `VaseyLicenseV2RendererProcess` is not a
  root, because the child never loads it. Manifest SHA-256 `fe8c02e6…e94e854b` (was `bcd40954…34c4dd82` after the wiring
  change, `1e02e732…c30af5e` before it).
- Golden: the synthetic business render is `81f228d8…1aeb628d` (GD 2.3.3, zlib 1.3, as v1's; was `2b2a04e4…a2cc8403`, and
  `ec2f685b…bb3dc157` before that), in process and from the isolated child alike. It changes with every v2 manifest rebuild,
  because the document ID and keywords carry the profile hash.
- Tests (`VaseyLicenseV2PdfRendererTest`): determinism and golden hash, dates, embedded `/BaseFont` names, keywords;
  "Łukasz Żółć", "Đorđe Kılıç SRL", "Ștefan Țepeș", "Dvořák Őrsi" and "MC Ęwa Mąkowska" render and extract with
  `pdftotext`, while v1 refuses the same document; every codepoint checkout accepts (357) is in `coverage()`, passes the
  layout's check in all four roles and renders; Cyrillic, Greek, Vietnamese, Ə and ǈ refuse at `document()`
  (`unsupported_characters`), and text a face cannot draw refuses at render (`unsupported_input`) with the font copy
  removed; one-byte changes to each of the 26 assets, the manifest, the renderer and each reused class refuse; v1, a
  relabelled v1 and `test-v2` profiles refuse, and v1's renderer refuses v2's profile; changed, missing, linked and non-PNG
  signatures refuse; root and `K_PATH_FONTS` independence; nothing outside the v2 profile files names the renderer.
- `ContractPrintableSet::faces()` now reads each metric file once and decodes the bytes it hashed (#241 review P3).

**v1 pin (fixed in #240).** `ContractIdentityDeclarationV2.php` changed in #216 after #223 pinned it in v1's manifest;
#240 rebuilt the v1 manifest and added both profiles' `--check` to CI. Because v2's implementation closure includes
`VaseyLicenseProfile.php`, every v1 rebuild also requires a v2 rebuild.

**Remaining for activation (increment 5).**

- [x] Decide the repertoire: Latin Extended successor (2026-11-08). Built and checkout rebound (above).
- [x] A v2 renderer (part 2, above). A buyer accepted at checkout with, say, "Ł" is rendered by the `test-v2` paid
  renderers unless the gated v2 selection below picks the line.
- Run the census and a golden render with the v2 renderer on the six real templates, locally, from private paths. Sean
  reviews the rendered layout, the block labels and the acceptance block wording.
- Install the real signature on the private disk with its pin. Confirm the golden hash on the Forge host (above), and reset
  opcache on every deploy of pinned files.
- Decide on the held tc-lib updates (#162/#164).
- [x] Wire the v2 renderer into the paid and paid-tax issuers for new issuance only, **behind a local/testing gate**
  (2026-11-08, below). Activation still needs independent review, the D-30 prerequisites (steps 2, 3a and 3b and the U-13
  retention values), contract increment 1 (the license versions' v2 contract text) and the items below.
  Wiring conditions from the v2 renderer review (2026-11-08):
  - [x] `render()` accepts only documents built by `VaseyLicenseV2PdfRenderer::document()`: a process-wide `WeakMap` of the
    documents it built; anything else refuses `unsupported_input`. `VaseyLicenseDocument` does not record which coverage
    built it, and the layout's per-face check has no script restriction, so a document built under a wider set (such as
    `test-v2`) could otherwise print characters checkout refuses. Test: documents built directly under the v2, `test-v2` and
    v1 sets refuse, including one carrying Greek.
  - [x] Declarations frozen before #241 under the `test-v2` set never reach v2: finalization already holds any v2
    declaration that does not re-verify against checkout's set (`contract_identity_held`), and the selection rule repeats
    the check (`identity_held`). Legacy name-only declarations keep the existing paid profiles. Neither refuses because of
    the switch.
  - [x] `InvalidArgumentException` and `JsonException` from `ContractPrintableSet` (tampered metrics) map to `profile_changed`
    in `coverage()`, so `document()` refuses with it too. Test: a flipped byte in each of the four metric files.
  - [x] A font file swapped after `verifyRuntime` refuses (`profile_changed`): the private font copy is written from the bytes
    `assetBytes()` hashed. Test: a test-only hook on the renderer (refused outside `testing`) swaps a metric or font program
    between verification and use.
  - [ ] Cover zero-width template glyphs in the six-template census. Remaining: the census runs on the private templates,
    which are not in Git; no template is fabricated for it.

**Issuer wiring (built 2026-11-08, local and testing only).** `App\Domain\Grants\VaseyLicenseIssuance` is the dispatch. The
pinned paid renderers, their child processes and profiles (`paid-v1`, `paid-tax-v2`) are unchanged; the dispatch sits in the
unpinned issuers: `PaidGrants`/`PaidTaxGrants` choose the profile when a line's grant origin is created, `PaidGrantDocuments`/
`PaidTaxDocuments` render by the recorded profile, and both graph checks verify a v2 original against frozen digests.

- **Gate.** `contracts.vasey_license_v2_issuance`, a code literal `false` (no environment read), admitted only as an exact
  `true` in `local` or `testing`. `ops/production/validate-runtime.php` holds it false (`holds.vasey_license_v2_issuance`).
- **Selection rule**, once per line at origin creation (paid-grant finalization, after the payment is recorded): the gate is
  on; the declaration is a v2 one; it re-verifies against checkout's printable set; a `VaseyLicenseContractSource` is bound
  and returns a template and the two license constants for the line's license version; the configured signature reads and
  matches its pin; and the line binds (schema 5 terms) and its document builds under the v2 input rule. Otherwise, including
  on any unexpected failure while selecting (a throwing source, a runtime error), the line keeps the family's existing
  profile, so **selection** never refuses a paid line. Each line's branch code (`selected`, `gate_off`,
  `name_only_declaration`, `identity_held`, `no_contract_source`, `template_too_large`, `signature_unavailable`,
  `not_renderable`, `contract_text_unavailable`; never buyer data) is recorded as `contract_profile_selection` in the
  `paid_origin.retained` / `paid_tax_origin.retained` audit context; while the gate is off the context is unchanged. A line
  whose render request (with the stored texts) would exceed the isolated child's 1 MiB input bound is `not_renderable`.
  Selection builds the document but does not render it, so a refusal only rendering finds can still fail a selected line
  after payment; that leaves a staff hold (below). Nothing in the application
  binds `VaseyLicenseContractSource` yet: contract increment 1 supplies the text, and tests bind a synthetic source.
- **Selection file reads happen before the locked frame** (2026-11-09). `VaseyLicenseIssuance::prepare()` runs in each
  issuer after its outside-transaction check and before the origin transaction: it reads and verifies the manifest, the
  four metric files (the renderer's input rule, and checkout's cached printable set, so the in-frame identity check reads
  nothing), and the configured signature against its pin. It reads nothing while the gate is off and never throws; a
  failed read leaves its field empty and the line keeps the existing profile with the branch code it had before
  (`signature_unavailable`, `not_renderable`). Inside the frame `decide($input, $prepared)` reads no file: it re-checks the
  gate, that the configured pin is still the prepared signature's, and that the prepared profile is its recorded hash's,
  then binds and builds the document under the prepared coverage, and stores the template and the prepared profile by
  hash (`VaseyLicenseContractTexts::retain()`, a database write that stays in the frame, so no origin names a text that is
  not stored). Called without a preparation inside a transaction it prepares nothing and keeps the existing profile
  (`not_renderable`). A pinned file or the signature changed after preparing is found by the render, which re-verifies
  everything, and leaves a staff hold. The contract source is still called inside the frame (it needs the line's license
  snapshot); contract increment 1 must keep it free of file I/O.
- **Recorded profile.** The origin's `profile` becomes a selection descriptor (`paid-vasey-license-v2-selection-v2`): the
  template's SHA-256 and the two constants, the signature pin, the v2 profile's hash, the effective hash (profile and
  signature), and the document's input hash and text digest. Every render, retry and replay uses it, whatever the gate says
  later. The template and the v2 profile themselves are stored once each by SHA-256 in `vasey_license_contract_texts`
  (see Payload growth below). At render both are resolved by their recorded hashes and verified (a missing row, a row that
  does not decrypt or a database error refuses `contract_text_unavailable`, which a retry may fix: the ciphertext is
  authenticated, so a failed decrypt cannot tell tampering from a key the worker lacks during an `APP_KEY` rotation or after
  a restore paired with another key; a row that decrypts but whose bytes do not match its address, or a malformed address,
  refuses `profile_changed`, permanent and held at once (review P3-1 of #249, P2 of the #251 review)) and passed to the
  isolated child, which verifies both hashes again; the document is rebuilt from the frozen input and must match the frozen input hash; the PDF must carry the frozen effective
  hash and text digest; a different signature refuses `signature_changed`; a changed profile refuses `profile_changed`.
  The stored original records the effective hash as its artifact profile hash and the descriptor's hash as the manifest's.
  A completed v2 original is checked against the frozen digests and never re-rendered or re-bound.
- **Gate off.** Origins record `PaidGrantRenderProfile::current()` / `PaidTaxRenderProfile::current()` as before, and the
  stored originals equal the existing isolated renderers' bytes (journey tests compare them).
- **Render-time defence.** A recorded selection renders only in `local` or `testing`, and its recorded template and profile
  hashes must resolve to stored bytes with those hashes before the signature or anything else is read.
- **Isolated render** (2026-11-09). Every v2 original renders in a bounded child, `scripts/render-vasey-license-v2.php`,
  started by `VaseyLicenseV2RendererProcess` with the limits of the paid renderers' child: `php -n` with the same fixed
  extensions, `memory_limit=128M`, `max_execution_time=60` and a 60 s wall clock, `allow_url_fopen=0`, the network and
  process functions plus `mail`, `putenv` and `dl` disabled, `open_basedir` limited to what the child reads (`app/`, its
  script, `resources/contracts/vasey-license-v2`, `vendor/composer` and the tc-lib package paths; never `.env`, `storage/`
  or `public/`) and a private per-render workspace (also the child's temporary directory); every directory entry ends with
  the path separator, so a sibling sharing its name as a prefix (`app2/`, `<workspace>-other/`) is outside, and only the
  script is an exact file entry (review P3-2 of #249). Every inherited environment name is removed (only `LANG`, `LC_ALL`, `TZ`,
  `TMPDIR` and a fixed `LD_LIBRARY_PATH` remain), at most 1 MiB of input, 24 MiB of output and no diagnostics. Under PHP-FPM
  the container gives it `PhpCliProcess::factory()`, like the pinned renderers. The parent binds the line's values (the
  binder needs the application's validator), checks the configured signature against the recorded pin and passes the line,
  the descriptor, the resolved template and renderer-profile bytes (the child re-checks both against the descriptor's
  hashes; payload keys in canonical sorted order) and the ordered values on standard input, and the checked signature bytes as `signature.png` in the
  workspace, deleted as soon as the child exits and again before the workspace is removed. The child loads no Laravel, configuration or environment: Composer's tc-lib packages and `InstalledVersions`
  only, and an application class only when the v2 manifest (checked against the anchor's hash) pins its file and the file's
  bytes match the pin. It then re-verifies everything itself in `VaseyLicenseV2Render` (application-free, so the dispatch's
  descriptor checks are the same code): the descriptor's shape and internal hashes, the recorded profile against the
  manifest and every pinned file (`verifyRuntime`), the signature bytes against the recorded pin, the document rebuilt from
  the frozen template and the values under its own input rule against the frozen input hash, and the PDF's effective hash
  and text digest. The parent checks the answer again (size, page limit, hashes, PDF framing, effective hash, text digest);
  that catches accidental drift, not a malicious child, which could echo the descriptor's values over other bytes.
  The child's bytes equal the in-process renderer's (golden test).
- **Post-payment render refusals leave a staff hold** (2026-11-09). When a v2 line's attempt fails with a refusal no retry
  fixes (`profile_changed`, `signature_changed`, `signature_invalid`, `unsupported_input` (including text a face cannot
  draw), `invalid_pdf`), or any failure spends its last attempt (`attempts_exhausted`; this is how a retryable
  `render_failed` such as a timeout, failed fork or full disk, a `signature_unavailable` storage hiccup or a
  missing or undecryptable `contract_text_unavailable` stored text, is held; a stored text that decrypts but does not
  match its hash refuses `profile_changed` and is held at once),
  `PaidGrantDocuments`/`PaidTaxDocuments` record one `paid_order.contract_render_held` row (`ContractRenderHolds`), after the
  attempt's own frames and outside any transaction: the order's row id as the subject and only the chain, the code and the
  order's public id as context. It is idempotent under the order-row lock (one per order however often it is retried or how
  many lines fail), reported and swallowed if the write fails, and listed in the existing read-only staff list
  (`/admin/contract-identity-holds`, "Hold: Contract render", same Gate and staff MFA). The buyer's response is the render's
  own failure, as before. Lines with an existing paid profile never reach it, so nothing changes while the gate is off.
- **Runbook rule.** Do not rotate the signature (file or `VASEY_LICENSE_SIGNATURE_SHA256`) and do not deploy a change to any
  file the v2 manifest pins (its implementation closure, assets or the manifest itself) while any v2 origin is unrendered
  (a `paid_document_work`/`paid_tax_document_work` row that is not `complete` for an origin whose profile is a v2
  selection). Such a line refuses `signature_changed` or `profile_changed` and is held for staff; its recorded descriptor is
  never re-bound. The staging runbook repeats the rule for deploys.
- **Payload growth (built 2026-11-09).** `App\Domain\Grants\VaseyLicenseContractTexts` and migration
  `2026_11_09_270200_vasey_license_contract_texts` (additive; `down()` refuses once a row exists): one row per kind
  (`template`, `renderer_profile`) and SHA-256, the bytes encrypted at rest with the application key as the origins are,
  immutable (triggers refuse UPDATE and DELETE, and an INSERT of another kind or a malformed hash, on SQLite and MySQL).
  Selection stores the line's template and the canonical v2 profile in the origin transaction with an idempotent
  insert-or-ignore, then reads the address back with a locking read and requires the stored row to decrypt to exactly
  those bytes; a row that differs keeps the existing profile (`contract_text_unavailable`). A database error there is not
  caught, so it aborts the origin transaction as an origin insert failure would. Measured with a synthetic template at the
  256 KiB selection bound: the stored V1 origin is 26,280 bytes against 532,880 with the #245 inline descriptor and 34,560
  for the same origin with the gate-off profile. The rows hold license wording shared by every buyer of a version, not
  customer data: the data export never reads them and deletion neither erases nor lists them (`docs/customer-data-rights.md`).
  Descriptors of the #245 shape (`paid-vasey-license-v2-selection-v1`, only ever written in `local`/`testing` with the gate
  overridden) are still recognized as v2 selections, so they never reach a paid renderer. A completed original under one
  still verifies by its frozen digests (`expected()`/`artifactProfileHash()` admit that shape when its inline template and
  profile match their recorded hashes), so its order keeps finalizing and serving downloads, but it never renders
  (`profile_changed`). On SQLite a second insert at a stored address is ignored by a trigger, so `INSERT OR REPLACE`
  (which skips DELETE triggers) cannot replace a row; MySQL's `REPLACE` fires the DELETE trigger and refuses. `down()`
  also refuses unless the table's triggers are exactly the migration's own.
- **Activation blockers for this wiring** (review of c5d0ce4c, 2026-11-09):
  - [x] **Post-payment render refusals** (2026-11-09: staff hold, above). Selection does not render, so drawability, page or byte limits, `invalid_pdf` and
    `render_failed`, and a signature rotation or pinned-file deploy between selection and render (`signature_changed`,
    `profile_changed`) fail a paid line's attempts up to `MAX_ATTEMPTS`, with no staff record. This matches the existing
    paid profiles' behaviour for a changed profile, but before activation either dry-render at selection (outside the
    origin transaction) or route v2 render refusals to a staff hold like `ContractIdentityHolds`.
  - [x] **Runbook rule.** No signature rotation and no change to a pinned v2 file while any v2 origin is unrendered (above,
    and docs/ops/staging-runbook.md section 7).
  - [x] **Isolation** (2026-11-09: isolated render, above). The v2 render ran in the PHP worker, not in an isolated child process like the paid renderers (its
    renderer needs the application's binder, validator and private-disk signature rules). Render it in a child with the same
    limits as `PaidGrantRendererProcess` (memory, time, input and output bytes, `open_basedir`, disabled functions, no
    inherited environment), or measure its bounds and record an accepted-risk entry.
  - [x] **Transaction I/O** (2026-11-09: file reads before the locked frame, above). `decide()` read the manifest, metric files and the signature inside the origin transaction
    that holds the order lock. Move the selection outside it (for example, prepare it before the frame and re-check
    inside) before activation. `VaseyLicenseContractTexts::retain()` stays inside the origin transaction now that the file
    reads moved out, so no origin ever names a text that is not stored.
  - [ ] **Key rotation.** v2 contract texts and grant origins are encrypted with the application key. Any `APP_KEY`
    rotation must keep the old key in `APP_PREVIOUS_KEYS` for as long as those rows are retained, or retained v2 lines
    stop rendering (`contract_text_unavailable`, held for staff once the line's attempts are spent) and origins stop opening. Record this in the rotation runbook before activation.
  - [ ] **Staff alert on `contract_text_unavailable`.** That branch means a row at a template's or profile's address does
    not hold those bytes (planted or damaged). The line keeps the existing profile, but staff must be alerted from the
    `paid_origin.retained`/`paid_tax_origin.retained` audit context before activation.
  - [x] **Payload growth.** A v2 origin carried the template and the v2 profile (about 125–600 KiB per line with a real
    template, against about 23 KiB today), so `PaidGrantExportSection::MAX_PAYLOAD_BYTES` (64 MiB) refused exports at a
    few hundred lines. Done (2026-11-09): the template and profile are stored once by hash (Payload growth above), a v2
    origin is no larger than a gate-off one, and the budget, its comments and the export code are unchanged. Tests: the
    descriptor holds hashes only and selections share one row of each (`VaseyLicenseIssuanceTest`); a template at the
    selection bound leaves the origin within 4 KiB of its gate-off size (`PaidGrantDocumentJourneyTest`); an account with
    enough v2 lines to pass the budget with inline descriptors exports, without a query on the texts table
    (`PaidGrantDataExportTest`); render refuses a missing or undecryptable stored text (`contract_text_unavailable`, retryable; one under a rotated key resolves once that key is in `APP_PREVIOUS_KEYS`), a changed one or a non-canonical one (`profile_changed`, held at once) and another
    stored template whose hash verifies; triggers, idempotent storing and rollback (`VaseyLicenseContractTextMigrationTest`).
- Tests: `VaseyLicenseIssuanceTest` (including the stored texts, selection with its files removed after preparing, inside a transaction, and
  the issuers preparing before their frames), the wiring-condition tests and the child's golden render in
  `VaseyLicenseV2PdfRendererTest`, `VaseyLicenseContractTextMigrationTest`, the v2 export case in `PaidGrantDataExportTest`,
  `VaseyLicenseV2RendererProcessTest` (child bytes equal the in-process renderer's; the
  child refuses a tampered descriptor, template, value, signature pin or signature bytes, and a font program, the manifest,
  an implementation file or the script changed after the parent's checks; probes of its environment, limits and functions;
  oversized output, diagnostics, exit codes, memory and a wall clock; the input bound; the PHP-FPM factory), the render hold
  in `ContractIdentityHoldsTest`, gate-on and gate-off journeys in `PaidGrantDocumentJourneyTest` (a signature rotated after
  selection: one hold across retries, then a successful attempt) and `PaidTaxGrantJourneyTest` (spent attempts: a hold on
  the last one only; none with the gate off), and the production hold in `tests/ops`. Review of ef327df7 added: a transient
  `render_failed` then success leaves no hold; probes that `.env`, `composer.json` and `storage/` are unreadable and
  `public/` unwritable from the child and that `mail`, `putenv` and `dl` are disabled; an unpinned change to a class the
  child loads never runs (it would announce itself on stderr); an answer with the right bytes but another text digest refuses.
- Stale workspaces (built 2026-11-09, from #249's remaining list): a parent killed mid-render can leave a workspace (with
  the signature copy if the kill lands before the child exits) under `contracts/render-cache`.
  `vasey:contract-render-cache-sweep` (`SweepContractRenderCache`, `ContractRenderWorkspace::sweep()`), scheduled hourly at
  :47 with `withoutOverlapping(10)`, removes direct children of that directory only when they are named as workspaces are
  (32 hex characters), are private real directories (never a symbolic link) and were last modified at least an hour ago
  (`--older-than`, never below 3600 s; a render's child has a 60 s wall clock). Contents go as `close()` removes them: a link
  inside is unlinked, never followed. It creates nothing, sweeps nothing (and succeeds) when the private disk root or the
  cache is absent, refuses a cache directory that is a link or not private, prints counts only and fails the run when it
  leaves any other entry in place. It refuses to run as root (effective uid 0) and removes only workspaces owned by its own
  effective user (another user's entry is left and counted), so **the scheduler must run as the site user that renders
  contracts** (review of #251, P3). Tests: `ContractRenderCacheSweepTest`.
- Tracked follow-ups from the #251 review (P3, not built):
  - The paid, paid-tax and member render caches (`contracts/paid-render-cache`, `contracts/paid-tax-render-cache`,
    `contracts/member-render-cache`) are not swept.
  - The Isolated (`IsolatedContractRenderer`), Free (`FreeGrantRendererProcess`) and ProductionFree
    (`ProductionFreeGrantRendererProcess`) children's `open_basedir` directory entries still lack the trailing separator
    the v2 child now has (the paid and paid-tax children are pinned files; any change there rebuilds their manifests).
  - Sweeper test gaps: a 32-hex directory that is not mode 0700 (left in place) and the race between a workspace's mtime
    check and a render closing it are not exercised.

### Wording item for Sean

`CONTRACT_DATE` is the **UTC** calendar day of the assent instant (review P3-5). A buyer who accepts late in the evening in
the Americas gets the next day's date. That is a wording question for Sean (for example, whether the contract should say the
date is in UTC), not a code change.

`audio_releases` has no template placeholder. It still appears in the license's statement schedule.
