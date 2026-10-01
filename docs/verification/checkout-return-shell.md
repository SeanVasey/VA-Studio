# Private checkout-return shell

This bounded T11 candidate adds the existing public site header, footer and persistent preview controls to the owner-checked saved test-order return page. It follows the [approved remaining-journey contract](../design/remaining-journeys-and-states.md#next-bounded-implementation-contract-checkoutreturn-shell). Runtime acceptance remains open: PHP, npm, frontend tests, browser tests, typechecking and builds were not executed locally.

The initial application/test source is frozen at `3f37602ec0ed0d7e44d61b8b6514cffbc82a8058` (tree `0d286e646474437a9a47fe2d86039d961b087109`) from approved base `05fa8669308aefc75908a8e1f4b43ad969685737`. This document is the eighth scoped path; the source commit owns only the return page, returned controller presentation, private head, private Blade fallback, two existing frontend suites and the new HTTP presentation suite.

The requested controller formatting follow-up is `a6047ba521a03f5f3c430141f0e850efc67d3418` (tree `68c59b24477655174528bb33bbb8160d9f8def9b`). It expands existing compact control-flow bodies and return spacing across the controller without changing any non-whitespace token, quoted string or comment. Single-line array expressions preserve existing punctuation. This separately recorded source check is not a local Pint result; the locked runtime must validate formatting.

The controller retains the original owner/status check before any CMS read. One verified `SiteContent::current()` snapshot supplies `EditorialContent::chrome()`. Draft bodies, release IDs, labels, hashes, image provenance and preview bases are not projected. Only typed `SiteContentUnavailable` uses the original approved static defaults for this private shell; it neither repairs publication nor changes the public routes' unavailable response. The private status page still depends on its original saved-order evidence and owner session.

`TestCheckout` still receives only `orderId`. Its initial saved-status GET and existing explicit actions/delivery behavior are unchanged. Redirect query parameters verify nothing. Arrival does not create checkout, reconcile payment, issue rights, authorize delivery, download a file or contact a provider.

## Private metadata and navigation

`PrivatePageHead` and the Blade fallback use matching `title`, `description`, `robots` and `referrer` keys. The fixed title is `Checkout status — VASEY.AUDIO`; the fixed description is `View the saved test order status for this session. A browser return does not verify payment.` Robots are `noindex, nofollow` and referrer policy is `no-referrer`. Private metadata has no canonical URL, Open Graph/Twitter tags, imagery, order/customer/provider identifiers or money. Generic public `MetadataHead` remains unchanged. HTTP responses retain private no-store, Cookie/X-Inertia variance, nosniff, noindex/no-referrer and foreign-owner 404.

The shell supplies a named, focusable main region, native skip destination and focusable status H1. The heading receives focus on entry. Ordinary public chrome/catalog links use Inertia; modified clicks retain native behavior. After a public visit, the destination section's heading or existing page heading receives focus; previously nonfocusable public section headings receive `tabIndex=-1` before focus. The mobile menu reuses its existing expanded/collapsed state and Escape focus recovery. No product route or parallel customer layout framework is added.

The page reuses `PersistentPlayer` with an empty catalog input and the existing single audio owner. Existing queue/source/position and playback controls remain available. License controls navigate to the queued public track; they do not initiate checkout. No audio element, autoplay policy or player state machine is added.

## Active-theme and state evidence

The [active repository theme](../brand/README.md) takes precedence over generic Edition 04 palette defaults under the requested brand UI skill. Existing `.editorial-page`, `.section-pad`, `.editorial-heading`, `.editorial-description`, `.quote-review` and `.text-link` treatments use the established semantic tokens:

| Role | Existing tokens / source |
| --- | --- |
| Canvas, panel, readable text | `--va-canvas`, `--va-surface`, `--va-text`, `--va-text-strong` |
| Links, focus, boundaries | `--va-link`, `--va-focus`, `--va-border`, `--va-rule` |
| Display, body, status metadata | `--va-font-impact`, `--va-font-body`, `--va-font-mono` |
| Mobile spacing and player clearance | Existing page margin, section breakpoints, body player padding and safe-area rules |

SiteHeader/SiteFooter use the unchanged approved navbar PNG at its natural 420:100 ratio. Its SHA-256 is `7b0ffd26d5ddffab9695fdd3e0a4bb306d50642dc6a51e25233fd1626ff07571`, matching the committed [asset manifest](../brand/asset-manifest.json). No identity redraw, new image/font, motif, CSS or theme change is introduced. Existing player icons describe playback/queue state; there is no decorative signal art or simulated waveform on this page. Exact font sources remain in the [font manifest](../brand/font-manifest.json); loaded-font/rendered evidence is not established by reuse.

The existing checkout loading, unverified, pending, verified, blocked, error and explicit retry states retain their original domain-driven copy/actions. Header hover/focus/current/menu states and idle/active/error player controls are inherited. The new shell has no independent success, cancellation, payment, readonly or fulfillment state. Session/permission failures remain server denials; a CMS integrity outage changes chrome to static defaults without relabeling saved payment truth.

## Regression sources and pending runtime gates

The original checkout-return frontend cases remain unchanged. Additive cases cover shared chrome and logo dimensions, hostile public text as inert text, entry/skip/menu/destination focus, native modified clicks and GET-only arrival. The metadata suite uses actual `createInertiaApp`/router transitions from public sharing tags (including image dimensions/type) to the private return and back, checks server fallback adoption, retained CSRF/theme tags and private referrer removal, and retains the same native preview source/time/queue without another playback call. These are test definitions, not local execution results or physical audio evidence.

The new real HTTP presentation tests use synthetic checkout/site fixtures to cover owner/nonowner boundaries, direct/Inertia private headers, active public versus unpublished chrome, fixed metadata, damaged/unavailable CMS fallback and unchanged retained records/provider calls. Synthetic CMS damage setup does not weaken production guards. Actual test discovery/counts, assertions, database engines and results must be recorded against the reviewed source before acceptance.

Planned focused commands in a locked supported runtime:

```sh
npm test -- tests/frontend/checkout-return.test.tsx tests/frontend/metadata.test.tsx
npm run typecheck
npm run build
php artisan test tests/Feature/CheckoutReturnPresentationTest.php tests/Feature/HostedCheckoutTest.php tests/Feature/OwnedTestOrderHistoryTest.php
```

Local evidence is limited to `git diff --check`, source-preservation/metadata-key checks and the unchanged logo hash. At the initial `3f37602` source boundary, reversing only the added presentation/imports restores the original controller byte-for-byte; the formatting follow-up preserves its tokens rather than its whitespace. Reversing only the private fallback restores the original public Blade branch. Removing the additive checkout block/imports restores the original checkout suite; all three original metadata test bodies remain byte-identical. `MetadataHead`, `TestCheckout`, shared chrome/player, audio owner, CSS, routes and package/lock sources are unchanged. The 46 `id`-ordered raw retention tables and separate `quote_owners.owner_key` ordering were checked against migration sources. Source definitions contain seven checkout cases, five metadata cases and six new HTTP cases (five methods including a two-case provider), with no new skip policy; this is not an executed discovery census.

There are no measured route bytes, request latency, LCP/INP/CLS, font loading, image layout shifts or audio-start measurements. No 320px/200% zoom, tablet/desktop, forced-colors, reduced-motion, keyboard/touch rendered run, screen-reader pairing or physical iPhone/Android playback/background acceptance is claimed. Existing CSS breakpoints/player clearance are source reuse, not proof of reflow or unobscured focus. Root owns focused locked-runtime proof and independent exact-source review.

Rollback reverts this presentation slice while preserving all existing order, checkout, payment, grant, contract, entitlement, delivery and publication evidence. Customer identity/recovery, production providers, fulfillment/storage and broader WP08/T11 device acceptance remain their existing separate boundaries.
