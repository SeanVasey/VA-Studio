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
2a. **Buyer identity capture (C-1, C-2).** Legal name and postal address at the agreement step. They are validated, stored
    encrypted with the grant input, covered by retention and data-rights export/deletion, and disclosed in the privacy policy
    first. Never inferred from payment or account data.
2. **Variable binding.** A closed map from the frozen grant input to the template variables. Any unknown or unfilled placeholder
   refuses issuance, and nothing is substituted silently. This includes golden synthetic fixtures.
3. **Profile `vasey-license-pdf-v1`.** Pin the fonts, logo and signature hash, the template layout and the renderer graph.
   Determinism tests and a mutation check prove that a changed signature or font refuses issuance.
4. **Activation.** Select the profile for new issuance only, never rerendering old originals. Independent review is required.

The current `tc-lib` pins and the held `tc-lib` updates (Dependabot #162/#164) are re-evaluated in increment 3. A new profile is
the one place a renderer upgrade can land safely.
