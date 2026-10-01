# Public app installation child — T33 / FP-017

This is a bounded online installation-metadata and guidance child from follow-up source `1e1f7a3ff1a2320a2150909a0c00d11f7e0d7079`, tree `65df793b33a9d98d6ad29a445d08b00625e76e15`. It remains separate from PR #89 and the alerts/sharing/private-checkout follow-up. T33, WP-05, physical-device installation, broader experience acceptance and the final Foundation gate remain open. No production deployment or offline/background-playback acceptance is claimed.

## Public identity and privacy

`public/manifest.webmanifest` is static public metadata: VASEY.AUDIO, a fixed root `id` and `start_url`, root navigation scope, standalone display, and the approved `#052e3a` canvas/background/theme. It contains no current URL, query, tracking parameter, customer/order/preview identity, shortcuts, provider or private asset. Manifest scope is browser navigation metadata, not an authorization boundary.

The existing single unkeyed Blade `theme-color` changes deliberately from `#101214` to `#052e3a`. It stays one tag on public and private shell pages. The server fallback emits installation links only for `home`, `tracks.show` and `editorial.show`. Matching `install:manifest` / `install:touch-icon` Inertia keys are owned by `MetadataHead`, default false, explicitly enabled only for ordinary public Storefront/Editorial pages. Design and CMS previews do not enable them; private CheckoutReturn uses its existing private head. Installation metadata is independent of whether guidance is expanded or an installation is observed.

The footer slot defaults absent. Public pages alone supply the quiet installation disclosure. Existing authorization, no-store/no-referrer/private metadata, catalog eligibility, payment state and audio services are unchanged. No service worker, cache, offline shell, push, telemetry or persistent installation history is introduced.

## Exact existing identity, square packaging

The original six asset records and `public/brand/vasey-audio-logo.png` remain unchanged. The whole approved 420 × 100 raster lockup is the only identity source; its SHA-256 is `7b0ffd26d5ddffab9695fdd3e0a4bb306d50642dc6a51e25233fd1626ff07571`. The unverified SVG status is retained. No standalone monogram, crop, tracing, replacement mark, component rescaling or enlargement is inferred.

| Derived PNG | Canvas | Whole contained lockup | Offset | Bytes |
| --- | --- | --- | --- | --- |
| `app-icon-192.png` | 192 × 192 | 168 × 40 | 12, 76 | 6,670 |
| `app-icon-512.png` | 512 × 512 | Original 420 × 100 | 46, 206 | 24,459 |
| `apple-touch-icon.png` | 180 × 180 | 168 × 40 | 6, 70 | 6,618 |

All canvases are opaque approved deep turquoise. Smaller lockups use proportional Lanczos downsampling; the largest preserves native dimensions. The separate `installation_derivatives` provenance records hold source/output hashes, exact dimensions/offsets and Pillow 12.3.0 RGB PNG generation settings. Manifest icon purpose is `any`, with no maskable claim. These are source-preserving whole-logo derivatives; actual launcher masking, tiny-size legibility and installed OS appearance remain unverified.

## User-controlled installation states

| Observed state | UI / next action |
| --- | --- |
| No browser event | Collapsed optional manual guidance; ordinary website remains available |
| Callable browser event | Offer Install VASEY.AUDIO after a deliberate user action |
| Prompt pending | Disable the one-use action; tell the user to follow the browser |
| Request accepted | Report request acceptance only; browser instructions remain available |
| Dismissed / failed / unsupported result | Keep useful manual guidance; never reuse the consumed event |
| `appinstalled` or standalone evidence | Hide the redundant installation disclosure; keep public metadata |
| Navigation / disposal | Drop deferred events/listeners and ignore late outcomes |

Each public-page controller consumes its event synchronously before awaiting, rejects the same consumed object through a lifetime-local WeakSet, and allows a genuinely new event. Observed installation/standalone evidence wins over late acceptance, dismissal or rejection. Modern media-query listeners, legacy add/remove listeners and absent listener capabilities are handled explicitly. No saved event survives private navigation. The public-home link uses existing Inertia navigation and restores catalog focus while retaining the single audio owner.

Manual guidance is conditional: Safari on iPhone/iPad can use Share / Add to Home Screen / Open as Web App if shown; supported Safari on Mac can use File / Add to Dock; other browsers may offer an address-bar/menu installation action. An internet connection remains required. No promise of universal install availability is made.

Primary documentation inspected October 1, 2026:

- [Current Chrome promotion criteria](https://web.dev/articles/install-criteria): manifest names, 192/512 icons, start URL/display, HTTPS, engagement and browser eligibility. Its current list does not require a service worker.
- [Prompt API](https://web.dev/articles/customize-install): user-controlled prompt after a click or tap, once per captured event.
- [Apple iPhone installation](https://support.apple.com/guide/iphone/open-as-web-app-iphea86e5236/ios), [iPad installation](https://support.apple.com/en-ca/guide/ipad/ipad8f1f7a29/ipados), and [Safari web apps on Mac](https://support.apple.com/en-us/104996): user-controlled browser actions.
- [WebKit Home Screen support](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/): third-party browsers can offer Add to Home Screen from iOS/iPadOS 16.4. Older course statements restricting every such browser are not used as a capability rule.

## Defined verification and actual boundary

The new PHP class defines six independent methods, without inheriting another feature test: exact minimal manifest; pinned complete icon/provenance geometry and actual PNG bytes; public server fallback/query isolation; eligible track plus withdrawal; authorized private CMS preview with strict raw row preservation; real synthetic owner/order return with private head, strict retained rows and no provider handoff. PHP syntax, discovery and execution are pending because no local PHP executable is available.

The new frontend file defines 20 cases, including dataset-expanded lifecycle variants. It uses actual Inertia providers for public/editorial/design/CMS/private-return head transitions, and synthetic installation events only for controller/UI state. An early full draft run recorded 361 cases with 52 failures / 309 passes: placing Head unconditionally inside the new footer UI broke ordinary component-test contexts outside an Inertia provider. The correction moves the links into the established MetadataHead boundary; no existing test, assertion or mock is weakened. A subsequent draft run passed all 341 prior cases and 19 of the 20 new cases; the remaining new assertion needed its status locator scoped to the installation disclosure rather than the whole storefront.

The new native definition is selected by the unchanged ordinary Playwright configuration for Chromium desktop and WebKit mobile, with existing timeouts and zero retries. It reads real manifest/icon files and an authenticated existing original CMS preview through the real controller, then proves public/private/public Inertia head cleanup, keyboard disclosure/home focus, 320px reflow and an advancing single native audio owner. Only the established storefront/WAV transport is synthetic; no private controller or installer event is simulated. The external/private-asset request observer covers new interactions after the ordinary admin/public shell, while page errors cover the whole journey. Native execution and screenshots remain pending.

Local JavaScript dependencies are a task-local copy of the existing cache, not a fresh npm installation. Read-only review found all 125 installed package versions and cache metadata aligned with the unchanged lock; 42 absent entries were incompatible-platform optional packages. This does not certify fresh tarball bytes. Actual local Node is 24.19.0, npm 11.9.0, Vitest 5.0.1 and TypeScript 7.0.2. Hosted clean installation/audits, PHP and both native projects remain required before exact-source acceptance.

Final local checks on October 1, 2026 used the task-local cached dependency copy described above. All application, test and asset hashes remained unchanged through the following successful checks; this guide was completed afterward.

| Actual local check | Result |
| --- | --- |
| Full `npm test` | 23 files / 361 cases passed, including all 341 prior cases and all 20 new cases; zero failed or pending cases. CLI duration 9.59 seconds, started 12:31:19 UTC. |
| TypeScript | `tsc --noEmit` passed; the production-build command also ran it successfully. |
| `npm run build` | Passed with Vite 8.3.0; 596 modules transformed, genuine build manifest and bundles emitted in 417 ms. |
| Client bundle scan | Passed: four bundle files checked for eight secret names and Stripe key prefixes, zero hits. |
| `npm audit --audit-level=high` | Actual audit passed with zero reported vulnerabilities. This does not replace a future clean locked installation. |
| Asset/source inverse | Passed: original six records and complete original PNG unchanged, all three derivative PNGs reproduced byte for byte, and dependency lock files unchanged. |
| `git diff --check` | Passed. |
| Ordinary native wrapper attempt | Prerequisite-blocked before PHP setup, discovery or browser launch: task-local `vendor/autoload.php` is absent. A local PHP executable is also unavailable. |

The retained scratch evidence directory is `attachments/pwa-public-installation`. The complete successful Vitest JSON is 148,950 bytes, SHA-256 `b1300e401a781653919372948b2bfb487c4936393c3768c433496852229e0e9b`; all 361 recorded test results have passed status. Some existing parameterized cases share titles, so this is a recorded-case count rather than a claim of 361 unique titles. The raw build log SHA-256 is `6a3927856203f027a60cb65386bb23e7a1e1e87c33b21d74d56212ff6cf62266`, client-scan log `33ef6df872e06e1503f4f6b81d7e3d74c9a3edfb028b059514c309aa8ef41156`, audit log `68d7b0e53628962865da889964201260d748d6d98ba492735e201d6b80cc9bb5`, and actual prerequisite-failure log `cd4b2d6b1c8d16693cb1d2bbff043b282fc6c786b6d0067a0eeb348103c274ed`. These are local source-specific results, not hosted artifacts or a Foundation pass.

The six PHP methods and both native project identities remain source-defined expectations, without actual PHP discovery, execution, native screenshots or OS installation proof. Physical iOS/Android installation, assistive technology, launcher legibility and background behavior remain separate, unverified device acceptance. A mock event or headless browser guidance pass cannot close those boundaries.
