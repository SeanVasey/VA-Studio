# Remaining journeys and state contracts

T11 source-derived contract, October 1, 2026. Baseline: `b41f8f58845f25e686f8f11ca70b147a362af9ec`, tree `3b5aa741c0c9a1407514390ca7d740488704e814`. This document maps inspected implementation and remaining work; it adds no runtime behavior and establishes no browser, device, production or work-package acceptance. “Present” below means source exists, with its stated enablement and ownership boundaries. Final candidate results belong in the integrating PR and [ordered record](../development-order.md).

The [task register](../remaining-development-tasks.csv), [design delivery matrix](../remaining-development-plan.md#design-delivery-matrix) and [decision register](../architecture/decision-register.md) govern scope. Sean's first-party store is the audience boundary: listeners, his customers and authorized staff, without independent seller onboarding. Feature owners implement these contracts with their existing domain commands. T11 and T33 remain open beyond this bounded map.

## Existing presentation authority

[AGENTS.md](../../AGENTS.md) and the [active brand mapping](../brand/README.md) take precedence over the brand UI skill's generic Edition 04 palette, master-brand SVGs and example commerce flows. Edition 04 supplies composition, type roles, geometry preservation and state/accessibility discipline. It does not replace this project's colors or authorize a signed-URL delivery flow or a claim of completed receipt.

Use [public/brand/theme.css](../../public/brand/theme.css), as already consumed by [app.css](../../resources/css/app.css):

| Existing semantic roles | Value / use |
| --- | --- |
| `--va-canvas`, `--va-surface` | `#052E3A` canvas; `#29363F` panels |
| `--va-text`, `--va-text-strong`, `--va-text-muted` | `#C9D0D3`, white, `#AAB4BD`; state meaning remains live text |
| `--va-link`, `--va-action`, `--va-focus` | `#00B8D9`; primary action uses deep-turquoise text |
| `--va-action-hover`, `--va-action-hover-text` | `#397281` with white text |
| `--va-border`, `--va-rule` | `#6A8E98` meaningful boundary; silver at 20% for supporting rules |
| `--va-font-impact`, `--va-font-section` | Noto Sans Display ExtraCondensed; Bebas Neue for short headings |
| `--va-font-body`, `--va-font-mono` | Reddit Sans controls/body; JetBrains Mono IDs, times and specifications |
| `--va-radius-panel`, `--va-radius-control`, `--va-space-*` | 2 px editorial panels, 6 px controls, existing 4 px spacing base |

Preserve `font-synthesis: none`, the designed condensed width axis and the four self-hosted fonts in [font-manifest.json](../brand/font-manifest.json), including their hashes, OFL licenses and loading/fallback records. Loaded-face and reflow checks remain rendered acceptance, not a consequence of declaring a family. Do not introduce brighter cyan, purple/magenta, a new status palette or CSS-compressed lettering.

The [asset manifest](../brand/asset-manifest.json) records the original URLs, dimensions, byte counts and SHA-256 values. Reuse these exact sources where the current composition calls for them:

| Source | Dimensions | Existing permitted role |
| --- | --- | --- |
| [vasey-audio-logo.png](../../public/brand/vasey-audio-logo.png) | 420 × 100 | Header/footer lockup, natural ratio, at or below native dimensions |
| [storefront-hero.jpg](../../public/images/storefront-hero.jpg), [storefront-hero-mobile.jpg](../../public/images/storefront-hero-mobile.jpg) | 2400 × 890; 960 × 890 | Existing responsive hero |
| [section-banner.jpg](../../public/images/section-banner.jpg) | 1800 × 200 | Decorative section strip |
| [memberships.jpg](../../public/images/memberships.jpg), [video-studio.jpg](../../public/images/video-studio.jpg) | Both 1440 × 630 | Existing studio imagery; filenames do not establish a membership product |

Official VASEY.AUDIO SVG retrieval remains unverified in the manifest/brand guide. Do not trace the PNG, typeset a replacement, substitute the VM mark or reuse the excluded footer raster. New production copy, source catalog media and vector provenance remain separate evidence. D-25's [SiteImagery](../../resources/js/components/SiteImagery.tsx) and [site-image contract](../architecture/D-25-editable-site-images.md) govern editable hero/studio/share images; retain pinned manifests and content-hashed derivatives rather than bypassing that pipeline.

| Motif / data | Purpose and accessible treatment | Boundary |
| --- | --- | --- |
| Existing atmospheric signal/studio assets | Communicate the existing practice; decorative treatment or contextual alt | No claimed track waveform, equipment ownership or product benefit |
| Public tagged-preview peaks in [Storefront](../../resources/js/Pages/Storefront.tsx) | Locate preview progress; labeled native seek in the player remains operable | No fabricated peaks or private-master visualization |
| Time, queue position and status from [audio.ts](../../resources/js/lib/audio.ts) | Playback/queue decisions with labeled controls and stable status text | No fake processing percentage or download-completion meter |

## Work-package route and component map

Routes are inspected in [web.php](../../routes/web.php), [embeds.php](../../routes/embeds.php) and the [admin provider](../../app/Providers/Filament/AdminPanelProvider.php). The React resolver currently exposes only Storefront, Editorial and CheckoutReturn in [app.tsx](../../resources/js/app.tsx). Planned customer/product screens below have no implemented route/component contract; do not add dead navigation destinations to imply availability.

| Work package | Present route / component and states | Remaining contract and acceptance / dependency |
| --- | --- | --- |
| [WP02](../work-packages/WP-02-catalog-and-admin-authorization.md) | Filament `/admin`, [TrackResource](../../app/Filament/Resources/TrackResource.php), protected preview routes; save validation, stale revision, readiness blockers, draft/published; unpublish returns to draft, read-only bulk review and audited apply. Provider supplies staff login/profile and recoverable app MFA, required in production. | Direct-route and command permission checks, persisted failure results, mobile modal/menu/focus recovery, no purchased-history deletion. Fine-grained roles and production recovery acceptance remain separate; do not equate a hidden action with authorization. See [catalog guide](../catalog-administration.md). |
| [WP05](../work-packages/WP-05-storefront-and-persistent-player.md) | `/`, `/tracks/{slug}`, `/api/catalog`; [TrackDetail](../../resources/js/components/TrackDetail.tsx), [LicenseDisclosure](../../resources/js/components/LicenseDisclosure.tsx), [PersistentPlayer](../../resources/js/components/PersistentPlayer.tsx). Search/empty/loading/retry, exact offer disclosure, idle/loading/playing/paused/ended/error, queue/repeat/loop/speed. Public `/embed/tracks/{slug}` uses native controls in a [separate Blade view](../../resources/views/public-track-embed.blade.php). | Preserve one audio owner, current public eligibility, exact disclosure, keyboard controls and native fallbacks. Independent pitch adjustment, PWA/install flow, physical playback/background behavior and production budgets remain open under T33. See [player guide](../player-queue-and-section-loop.md) and [embed guide](../verification/public-track-embed.md). |
| [WP08](../work-packages/WP-08-contracts-entitlements-and-customer-library.md) | Current-session history and existing quote/order/status/checkout/delivery routes; components detailed below. Private original test contracts and native owner attachments exist under explicit policies. | Account claim/recovery, cross-session library/search, historical source continuity, production storage/archival and large-file range/resume/retry acceptance remain open. T23/U-07 precede a complete T24 library; U-06 governs production archival/render acceptance. |
| [WP09](../work-packages/WP-09-seller-cms-sharing-and-promotions.md) | `/about`, `/contact`, `/blog`, `/blog/{slug}`, `/videos`, `/videos/{slug}` when selected content exists; [Editorial](../../resources/js/Pages/Editorial.tsx), consent-aware [EditorialVideo](../../resources/js/components/EditorialVideo.tsx), inquiry form, related-track links, protected site preview and Filament release/image/promotion tools. Draft, scheduled, published, restored, failed schedule/image work, withdrawn track eligibility and private-preview states. | Retain atomic release truth, current eligibility, external-action denial in private previews, explicit video loading/removal and inquiry uncertainty. Free-download exact-license assent and order-aware support are absent. T18 depends on policy/delivery; inquiry intake needs configured notice/retention/operator. See [editorial guide](../editorial-content.md) and [related-track guide](../verification/editorial-related-tracks.md). |
| [WP10](../work-packages/WP-10-collections-kits-services-and-merchandise.md) | No collection/album/kit/service/merch product pages, customer fulfillment screens or corresponding complete product administration workflow. Studio copy and images are presentation, not these workflows. | Specify creation → public preview/terms → test purchase → exact fulfillment → failure/cancellation/support per retained type. Reuse current chrome, disclosure, server money and private delivery patterns when appropriate; do not invent bundle/license/provider rules. T26–T29 depend on T24/support and their listed domain prerequisites; services/merch require U-12 and source obligations from T09/U-09. |
| [WP11](../work-packages/WP-11-memberships-crm-and-optional-integrations.md) | Session history and generic contact/inbox are preparation only. No customer membership, balance/redemption, renewal/cancellation, wishlist/preferences or account-recovery pages exist. | Versioned plan/credit, failed/late renewal, cancellation, grandfathered benefit and consent states require their domain ledgers. T30–T32 depend on identity/library/source work and U-10/U-13; each verified integration needs its own disposition. Marketing consent cannot be a condition of transactional access. |

## Detailed current-session customer slice

This sequence is **open cart → explicitly browse session orders → select retained status → explicitly request a currently available exact item**. It is not a customer sign-in or guest-claim design. [QuoteOwner](../../app/Support/QuoteOwner.php) derives ownership from the session secret and authentication context. Login/logout, another session of the same account, email matching and knowledge of an order ID cannot claim the old order. Lost-session identity remains unresolved U-07, rather than an empty-list promise of permanent loss of rights.

Reuse [OrderPreparation](../../resources/js/components/OrderPreparation.tsx), [OwnedTestOrderHistory](../../resources/js/components/OwnedTestOrderHistory.tsx), [TestCheckout](../../resources/js/components/TestCheckout.tsx) and [TestOwnerDelivery](../../resources/js/components/TestOwnerDelivery.tsx). Their existing `.quote-review`, `.quote-review-result`, `.quote-review-item`, `.checkout-advisory`, `.fine-print`, `.button` and `.button-outline` treatments supply panels, boundaries, wrapping metadata and recovery actions. [Modal](../../resources/js/components/Modal.tsx) supplies the cart/dialog composition. Do not create a parallel ownership, pricing or entitlement state machine in a shared visual component.

### Discovery and retained status

| State / authoritative source | Present action and treatment | Acceptance to preserve |
| --- | --- | --- |
| Undiscovered | Browse session orders; no history request before the action | No automatic checkout, delivery, provider or file-health I/O from listing |
| Loading | Busy section and disabled browse/refresh control | Prevent overlapping requests; ignore responses after unmount |
| Empty | `No test orders belong to this session` status | Never imply a global account search or recovered ownership |
| Listed / older page | `GET /orders/history`, newest twenty summaries; Older session orders and refresh-from-newest | Server owner-checked cursor; strict allowlist, private no-store response; replace pages instead of appending stale rows; focus Session orders heading after load |
| Selected | Expanded View test order status reuses existing OrderStatus/TestCheckout | Selection does not start payment or authorize a download; no identity or secrets persisted by history |
| Failed / malformed / unavailable | Generic alert, Refresh session orders | No reflected private error body; preserve the distinction between unavailable reads and retained rights |
| Tab-local uncertain preparation | Existing quote-locator recovery uses `GET /quotes/{quote}/order` | Opaque optional tab locators are separate from server history; clearing them does not link or erase original ownership |

See [history contract](../test-order-history.md), [OrderController](../../app/Http/Controllers/OrderController.php) and [ReadOwnedTestOrders](../../app/Domain/Commerce/Orders/ReadOwnedTestOrders.php). No top-level customer library or sign-in/claim/recovery route exists in this baseline.

| Coherent TestCheckout progress | Commercial truth / permitted next step |
| --- | --- |
| `not_verified`; checkout `not_started`, `pending`, `open`, `complete`, `expired` or `reconciliation_required` | Provider-session state is separate from payment. Use the component's explicit policy-gated start/retry/reconcile actions; browser return/query parameters prove nothing. No success or fulfilled label. |
| `verified` / `awaiting_finalization` | Retained test payment; finalization pending, no contracts/downloads yet; refresh status |
| `verified` / `paid_exception` / contract and fulfillment `blocked` | Needs operator review; do not start another payment or fabricate a customer resolution button |
| `verified` / `paid` / contract `pending`, fulfillment `pending_contracts` | Finalized, contracts pending; refresh status |
| `verified` / `paid` / contract `attention`, fulfillment `blocked` | Contract work needs attention; preserve payment truth and block delivery |
| `verified` / `paid` / contract `issued`, fulfillment `pending_activation` | Original-contract evidence is recorded. Mount the separate delivery panel to check access; issued status alone proves neither present file health nor available delivery. |
| Read/action failure or uncertain mutation | Keep previously verified progress; expose explicit saved-status refresh or same-operation retry. A late/unavailable read must not reopen payment. |

These are existing `validPaymentProgress` combinations, not new production statuses. `paymentStatus: not_started` also occurs in prepared-order summaries before checkout; it has no finalization/contract/fulfillment effects. The return route and checkout GET are owner checked and read-only; explicit checkout/reconciliation POST behavior stays in the existing domain. [D-14](../architecture/D-14-hosted-test-checkout.md) through [D-20](../architecture/D-20-test-owner-delivery-http.md) retain the original test boundaries.

### Delivery and interruption

| State / route | Present action and truthful result | Acceptance to preserve |
| --- | --- | --- |
| Loading / failed listing | `GET /orders/{order}/delivery`; loading status, generic alert, refresh | Database-only projection; no secret or new grant from GET |
| Unavailable / empty / available | Explain current access or show exact purchased roles, size and download action | Availability is separate from payment/contract status and is not a current-byte-health guarantee |
| Authorization in flight | Explicit `POST /orders/{order}/delivery/authorizations`, exact grant/kind and idempotency key; disable conflicting actions | Revalidate owner, policy, control and exact private evidence server-side |
| Unconfirmed authorization | Retry the same authorization request or deliberately start a new request | Preserve the original key/item in memory; never automatically issue again or imply a recoverable lost secret |
| Attachment submitted | Native `POST /orders/{order}/delivery/download` with temporary secret-bearing fields | Secret never enters URLs, persistent storage or a file blob; committed attempt may be consumed by interruption; browser submission is not confirmed receipt |
| Recent attempts | `unused`, `attempted`, `expired`; newest twenty, refresh | Explain no recorded stream, stream attempted or authorization expired; no completed-download label or secret recovery |
| Native error / stale frame response / teardown | Generic download uncertainty; check browser downloads and saved history; remove frames on unmount | No raw private response reflection; ignore old response generations, clear ephemeral fields and preserve retained evidence |

Use the [owner delivery guide](../test-owner-delivery-http.md), [TestOwnerDeliveryController](../../app/Http/Controllers/TestOwnerDeliveryController.php) and [test-delivery validators](../../resources/js/lib/test-delivery.ts). Current private attachments reject range/resume requests; public preview range support is a different boundary. Large-file range/resume/retry requirements remain T24 work, not an implication of this native form.

### Contact and future order support

The public [ContactInquiryForm](../../resources/js/components/ContactInquiryForm.tsx) appears only on an enabled published contact page with a configured notice. It sends `POST /contact/inquiries`; the existing email fallback remains. [CustomerInquiryResource](../../app/Filament/Resources/CustomerInquiryResource.php) supplies the authorized private inbox. Neither is an order-linked support thread, buyer reply interface or attachment workflow.

| Present inquiry state | Action / recovery and truth |
| --- | --- |
| Editing / invalid | Native labels, adjacent errors and linked focused summary; correct a definite first validation/size rejection |
| Sending | Busy text; serialized original payload/key retained in memory; no duplicate click |
| Unconfirmed, timeout, 419, 429, unavailable or conflict | Original inputs remain copyable/read-only; explicit same-inquiry retry. Renewing CSRF in another tab does not promise owner recovery; email fallback remains. |
| Saved receipt | Focus the status summary and offer Write another inquiry; receipt means saved privately, not delivered email or resolved support |
| Leave/reload | In-memory draft and retry identity are lost; no persistent draft/PII storage or cross-session recovery claim |

See [inquiry UI guide](../verification/contact-inquiry-ui.md) and [operator contract](../contact-inquiries.md). Future T17/T32 support must define owner-checked order context, thread/message outcomes and scanned private attachments before exposing those actions. U-07 identity and U-13 retention/notification/processor choices remain open; generic inquiry must not silently substitute for that service.

## Next bounded implementation contract: CheckoutReturn shell

Observed gap: [CheckoutReturn](../../resources/js/Pages/CheckoutReturn.tsx) currently contains only a main region, test-status copy, TestCheckout and a catalog link. [TestCheckoutController::returned](../../app/Http/Controllers/TestCheckoutController.php) passes only `orderId`. Storefront and Editorial separately mount [SiteChrome](../../resources/js/components/SiteChrome.tsx), a skip target, page metadata and the persistent player. The return page therefore has no equivalent chrome or player controls; this source fact does not establish a reproduced playback failure.

One separate implementation owner can supply verified public chrome from one current SiteContent snapshot, reuse SiteHeader/SiteFooter and the existing player owner, and add a named main/skip destination and predictable navigation focus. Use only real published navigation destinations. Preserve readable stacked status/delivery panels, natural-ratio logo and player-safe content spacing. Do not redesign the return flow or extract all pages into a new layout framework in this slice.

Private metadata needs a generic test-status title and noindex behavior. [MetadataHead](../../resources/js/components/MetadataHead.tsx) currently emits public canonical/social tags; it must not be reused blindly with an order URL or private state. Exclude customer/order/provider identifiers and amounts from social metadata and keep existing private headers. A damaged/unavailable CMS shell must not make retained order status inaccessible; define and test the same safe public-shell fallback boundary before implementation.

Proposed runtime ownership for that future increment is limited to CheckoutReturn, TestCheckoutController, directly necessary private metadata/shell handling, and their targeted frontend/HTTP/browser tests. Retain the exact owner check, status GET, no-store/Cookie/X-Inertia variance, no-referrer/noindex protection and all current payment/delivery semantics. Do not add account claims, support writes, automatic reconciliation or automatic downloads. Acceptance must include direct load and Inertia navigation, current published versus private navigation, keyboard/mobile reflow, single audio owner/reachable controls, ignored redirect parameters, unavailable CMS, foreign order denial and no additional payment/delivery POST on arrival.

## Responsive, state and evidence handoff

For every owning feature, record default, hover, focus-visible, pressed, selected/current, expanded/collapsed, invalid, read-only, disabled-with-reason, loading, empty, interrupted, permission/session failure, error and confirmed outcome where applicable. Mark absent states as dependent or inapplicable with a reason; do not invent a success state for asynchronous work. Use live text with existing panel/rule/outline treatments, `role=status` for stable progress and `role=alert` for blocking errors. Preserve current heading focus, dialog close recovery and explicit retry actions.

Reuse mobile-first stacked panels/cards, wrapping IDs and current responsive image sources. Required future rendered acceptance includes 320 CSS px reflow, 200% zoom, long titles/IDs/messages, tablet portrait/landscape and 1280/1440/1600/1920 desktop; 44 × 44 public targets; visible 3 px focus; no focused action obscured by player/browser chrome; reduced motion and forced colors. Native labeled seek and explicit queue move buttons remain alternatives to graphical/drag interaction. Test actual font loading, meaningful contrast over imagery, image layout shifts and keyboard/touch navigation. Record VoiceOver/iOS Safari, NVDA/Windows and physical iPhone/Android playback/background tests separately; the physical-device tester is unassigned. Emulator/component screenshots are not those results.

Existing regression sources include [history frontend](../../tests/frontend/owned-order-history.test.tsx), [history native fixture](../../tests/browser/owned-order-history.spec.ts), [checkout return frontend](../../tests/frontend/checkout-return.test.tsx), [owner delivery frontend](../../tests/frontend/test-owner-delivery.test.tsx), [native delivery](../../tests/browser/test-owner-delivery.spec.ts), [contact frontend](../../tests/frontend/contact-inquiry.test.tsx) and [real inquiry/inbox browser flow](../../tests/browser/contact-inquiry-persistence.spec.ts). Intercepted browser transport proves built UI behavior, not server ownership or actual persistence; backend and MySQL race evidence stay distinct. Their presence is not a new execution claim.

Performance impact of this change is documentation only: no bundle, media request, font, asset or provider change. Later runtime owners must measure route JavaScript, image/font bytes, LCP/INP/CLS, audio-start latency and relevant API latency against approved budgets; do not infer production performance from a build size. No new performance measurements, browser runs, device tests or external source research were performed for this document.

Local source-consistency checks resolved all 71 relative file references, matched the six committed image byte counts/SHA-256 values against the asset manifest, matched all four font package versions against the lockfile, and checked the named semantic roles/colors and customer/embed routes against source. This verifies references and source mapping only, not rendered typography, accessibility, authentication or transport execution. The one-file change also requires `git diff --check`; no PHP/frontend runtime change or new behavioral test is introduced.

Keep U-07 guest/account claim and recovery, U-10 membership/credit/renewal policy, U-12 services/merch fulfillment and U-13 consent/retention/processors unresolved. U-04/U-05/U-08 still govern affected live commerce; U-06 production archival/render and U-09 actual legacy obligations/source inventory remain separate. These choices do not block this source map or the bounded return-shell preparation. This document neither resolves them nor changes activation, source obligations, existing rights or cutover authority.
