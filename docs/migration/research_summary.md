# VASEY.AUDIO source reconciliation

Prepared 2026-09-04. Scope: begin the complete first-party replacement in a new VASEYAUDIO GitHub repository, with phased agentic development, current VASEY visual styling, administrator publishing and sharing, and music licensing commerce. Live BeatStars settings, billing and DNS have not been changed.

## What the attachments contribute

| Evidence | Contribution | Treatment |
| --- | --- | --- |
| SRC-001, undated redesign DOCX | Historical storefront critique, section/media constraints, navy/cyan styling proposals | Useful design history and audit checklist; its stale-site conclusions are not fresh observations |
| SRC-002, March 2026 12-page PDF | Broad Pro Page/back-office scope, service/merch/membership obligations, Laravel/MySQL/Next.js proposal | Scope input; versioned architectural suggestions rather than a required stack |
| SRC-003, September 1 2026 Markdown | Expanded commerce, pipeline, immutable contracts, security, mobile and seven-color proposals | Detailed secondary research; reconcile claims and claimed prior decisions before implementation |

All three raw files were hashed before local extraction. Their original binaries and full confidential contents are deliberately not committed. Hashes are recorded in `source_ledger.csv`.

The common functional scope is substantial: public music discovery and persistent playback; master/preview/artwork/stem ingestion; editable products and site content; configurable licenses; checkout and settlement; buyer-specific contracts and downloads; buyer accounts; kits and collections; services; memberships; merch; offers and promotions; messaging; customer/consent records; analytics; and migration/operational continuity. The parity matrix preserves these areas while separating additive ideas such as affiliate marketing, crypto, on-chain receipts and AI metadata assistance.

## Material corrections

1. **Payment processor and merchant of record are different decisions.** SRC-003 describes Stripe as merchant of record while proposing Connect payouts. Stripe's current comparison assigns merchant responsibility to the business for ordinary products and to Stripe for Managed Payments; Managed Payments currently excludes Connect. Eligibility and product mix require a concrete selection. No document here assumes that tax filing, disputes or legal merchant duties transfer merely by using Stripe. [Stripe Managed Payments](https://docs.stripe.com/payments/managed-payments)

2. **The 12% Marketplace fee is not a current Pro Page saving.** Official documentation excludes Pro Page and Blaze Player purchases. The replacement's economic assessment must compare actual subscription, processing, infrastructure and operating costs, rather than subtracting 12% from every existing VASEY sale. [BeatStars Marketplace fee](https://help.beatstars.com/hc/en-us/articles/17747783078171-Why-is-there-a-service-fee-on-the-Marketplace)

3. **Content ID & More is not an active submission feature to clone.** Current official guidance describes a wind-down, with submissions stopped December 8, 2025 and existing removals by January 14, 2026. Residual royalties can remain relevant. Inventory Sean's actual contracts and provider history; evaluate any successor independently. [BeatStars wind-down FAQ](https://help.beatstars.com/hc/en-us/articles/44500647605787-Content-ID-More-Wind-Down-FAQs)

4. **A free download is not necessarily noncommercial.** Current settings support commercial non-exclusive grants with configurable royalty terms, alongside standard promotional options. These grants do not apply retroactively. Import the actual accepted terms and distinguish marketing consent from required delivery identity. [Free Download FAQs](https://help.beatstars.com/hc/en-us/articles/360048521413-Free-Download-FAQs)

5. **Exclusive retirement must cover all controlled versions and sales channels.** BeatStars documents automatic removal of the purchased original for its own exclusive sale, with off-platform sales and alternate/collaborator versions needing separate handling. The target needs a shared rights/inventory identity, not just a hidden product row. [Exclusive removal rules](https://help.beatstars.com/hc/en-us/articles/360039030594-Do-I-have-to-remove-the-track-when-I-sell-an-Exclusive-contract)

6. **The actual contract determines ownership.** The PDF's blanket description of exclusive master transfer must not become a hard-coded rule. Producer terms vary. The legal model must distinguish license grants, master ownership, composition/publishing shares, revenue splits and any assignment. [BeatStars license questions](https://help.beatstars.com/hc/en-us/articles/360047401174-What-if-I-have-a-question-about-a-license-product-or-service-purchase)

7. **Protected preview is not unrecordable audio.** Signed URLs, HLS and access controls can reduce unauthorized reuse and isolate valuable masters; anything playable can be captured. Preserve a tagged derivative and avoid an absolute anti-rip guarantee. A lower-bitrate preview, invisible watermark, normalized master or HLS deployment is a proposal to evaluate, not a migration fact. Do not alter production masters simply to fit a preview recipe.

8. **Link expiry is distinct from entitlement lifetime.** Avoid the attachment's generalized claim that BeatStars permits only one download. Design the target around recorded grant terms and private assets: a short-lived link can be reissued when the authenticated entitlement remains valid. Actual historic download behavior still needs audit.

9. **Asset revisions belong in the foundation.** SRC-003 postpones versioning in one paragraph despite depending on immutable contracts and delivery elsewhere. Store immutable originals, derivative lineage, contract versions and purchased asset bindings before accepting any real sale. Price changes, asset replacement, refunds and exclusives must not rewrite historic evidence.

10. **Private export and subscription portability remain unknown.** Advertised membership plans do not prove active subscribers; possession of customer emails is not marketing consent; an order CSV is not enough to recreate an entitlement; payment-method references are not portable credentials. Official membership guidance places the recurring relationship with the producer and describes monthly credit behavior, making provider ownership and paid-period continuity essential audit items. [Membership FAQ](https://help.beatstars.com/hc/en-us/articles/360039747113-Membership-Subscription-to-a-Producer-s-Pro-Page-FAQs)

## Public VASEY.AUDIO observation limits

The retrieved [homepage](https://www.vasey.audio/) contains navigation for beats, memberships, kits, services, merch, albums, video, updates, biography and contact. It displays Basic/Premium license figures of $40/$75 and membership figures of $15/$25. These are public text observations from a crawl reported as roughly three weeks old; they are not verified current checkout prices, complete license text or subscriber counts.

Deep route retrievals varied from old, cookie-disabled shells to fetch failures for `/merch` and `/music/albums`. Shell text containing account menus or Customize/Publish does not establish authentication or admin access. The evidence cannot establish that pages are truly empty, products are broken, or the latest applied theme has been seen. A live browser audit and original approved assets are required. Public URLs are recorded separately from verified control of DNS or routing.

## Brand reconciliation

**Decision:** Sean explicitly asks for the latest VASEY Multimedia style and colors from the media/theme now used on BeatStars. This instruction takes precedence over historical attachment recommendations.

| Source | Palette/type proposal | Consequence |
| --- | --- | --- |
| DOCX | Near-black `#050505`; navy `#04121F`/`#02080E`; panel `#031926`; cell `#0A1A26`; cyan `#1EB3D7`; brighter `#23D0E0`/`#03D9FC`; silver `#AAC5D3`; text `#EAF6FA` | Historical proposal, including very bright accents that may conflict with later normal-turquoise direction |
| September Markdown | Deep turquoise `#052E3A`; charcoal `#29363F`; silver `#C9D0D3`; teal `#397281`; turquoise `#00B8D9`; navy `#191970`; blue `#454CFC` | Cannot declare canonical without reconciling current media; its prose contradicts its own table about dark text on turquoise |
| March PDF | Bebas Neue, Reddit Sans, JetBrains Mono; references a broader 14-color palette | Useful font evidence; the PDF's VASEY/AI labeling must not rebrand the audio store |

Preserve exact owner SVG geometry, logo proportions and existing artwork. Do not replace marks by generative approximations. Current known preferences favor navy, charcoal, slate and ordinary turquoise, with Reddit Sans body, condensed display headings and JetBrains Mono metadata; the current approved token/artwork revisions still need retrieval. Native text should carry UI copy, with photographic audio equipment and sound imagery framing it. Do not derive semantic status states by importing unapproved lime, pink or Project DIG|TAL yellow accents. Measure contrast after compositing translucent surfaces over actual images; nominal opaque-color ratios do not verify the rendered page.

## Architectural implications for the first implementation

These are recommendations to be resolved by repository decisions, not additional provider commitments:

- Keep the first release a single VASEY storefront with explicit domain modules. A separate public multi-seller marketplace is not required to reproduce Sean's site.
- Create the relational product/asset/license/offer/order/grant/entitlement model before vendor integration. Avoid the PDF's mutable file paths and one generic split record for unrelated ownership/revenue concepts.
- Implement one complete vertical slice first, then the full parity modules. The live domain moves only after the applicable full-replacement gates pass; an attractive storefront or admin mockup is not a migration.
- Process trusted payment evidence through an idempotent inbox, transactional grant/inventory finalization, and retryable fulfillment outbox. Client success URLs are presentation only.
- Use private object storage for masters, stems, executed contracts and buyer deliverables. Public preview derivatives and art are separate classes.
- Preserve historic orders as `archive_only` until an approved policy and complete buyer/payment/contract/asset evidence justify a `verified_grant` or an `active_entitlement_candidate`. Do not regenerate missing historical contracts.
- Keep marketing integrations, memberships, service workflows and merch independent of the first track-store slice while recording their complete acceptance criteria and active obligations.
- Outsource or integrate distribution, publishing administration, payment processing and physical fulfillment according to approved accounts; do not claim that a website recreates BeatStars' external audience, collecting-society relationships or provider functions.

Next safe work: continue foundation implementation and prepare the first authenticated admin-to-buyer path against fixtures. In parallel, acquire current brand assets and authorized official exports to replace unresolved fields with evidence. No private-account assertion has been promoted to a verified fact in these records.
