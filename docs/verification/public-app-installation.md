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

The new PHP class defines six independent methods, without inheriting another feature test: exact minimal manifest; pinned complete icon/provenance geometry and actual PNG bytes; public server fallback/query isolation; eligible track plus withdrawal; authorized private CMS preview with strict raw row preservation; real synthetic owner/order return with private head, strict retained rows and no provider handoff. At the initial local checkpoint, PHP syntax, discovery and execution were pending because no local PHP executable was available; later genuine hosted outcomes are recorded below.

The new frontend file defines 20 cases, including dataset-expanded lifecycle variants. It uses actual Inertia providers for public/editorial/design/CMS/private-return head transitions, and synthetic installation events only for controller/UI state. An early full draft run recorded 361 cases with 52 failures / 309 passes: placing Head unconditionally inside the new footer UI broke ordinary component-test contexts outside an Inertia provider. The correction moves the links into the established MetadataHead boundary; no existing test, assertion or mock is weakened. A subsequent draft run passed all 341 prior cases and 19 of the 20 new cases; the remaining new assertion needed its status locator scoped to the installation disclosure rather than the whole storefront.

The new native definition is selected by the unchanged ordinary Playwright configuration for Chromium desktop and WebKit mobile, with existing timeouts and zero retries. Its initial draft assumed an existing original CMS release; the first hosted run disproved that fixture assumption, as recorded below. The corrected definition uses the normal admin UI to save one uniquely labeled, unpublished default-content draft and reads that row's actual Preview link. It retains the real private controller and all public/private/public Inertia head cleanup, keyboard disclosure/home focus, 320px reflow and advancing single native audio-owner assertions. Only the established storefront/WAV transport is synthetic; no private controller or installer event is simulated. The external/private-asset request observer covers new interactions after the ordinary admin/public shell, while page errors cover the whole journey. Corrected native execution and guidance screenshots remain pending.

Local JavaScript dependencies are a task-local copy of the existing cache, not a fresh npm installation. Read-only review found all 125 installed package versions and cache metadata aligned with the unchanged lock; 42 absent entries were incompatible-platform optional packages. This does not certify fresh tarball bytes. Actual local Node is 24.19.0, npm 11.9.0, Vitest 5.0.1 and TypeScript 7.0.2. At that local checkpoint, hosted clean installation/audits, PHP and both native projects remained required before exact-source acceptance. Later hosted component results are recorded below; corrected native and complete Foundation acceptance remain open.

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

At that local checkpoint, the six PHP methods and both native project identities were source-defined expectations, without actual PHP discovery, execution, native screenshots or OS installation proof. Physical iOS/Android installation, assistive technology, launcher legibility and background behavior remain separate, unverified device acceptance. A mock event or headless browser guidance pass cannot close those boundaries.

## First hosted focused proof and fixture correction

The initial genuine focused run [36865662042](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36865662042), created October 1, 2026 at 13:00:39 UTC, completed **failure**. Its exact source was `e905f711fa308c99f86a1c037b7489819d63d989`, tree `620ec9c8bd806060b132061f871d725e82cc85cf`, sole parent `c456a69ea299dd308c2f995c432df8ef62e62016` / permanent tree `67c83fb343487a77d7346c21f2ad1a2767343c4a`. The parent was the reviewed source checkpoint, not accepted combined follow-up/Foundation work. The automatic Focused development feedback on the same source remains a distinct subset.

| Actual component | Recorded result |
| --- | --- |
| SQLite job `110380396767` | Six exact discovered methods and six JUnit cases passed, 160 assertions, no failures/errors/skips; JUnit 2.622467 seconds, CLI 2.624 seconds. SQLite 3.45.1, fresh `:memory:` connection with zero tables before tests. New feature-class Pint passed after behavioral execution. |
| MySQL job `110380396552` | The same six methods/cases passed, 160 assertions, no failures/errors/skips; JUnit 10.460943 seconds, CLI 10.462 seconds. Genuine MySQL 8.4.11, zero tables before tests and 64 afterward. New feature-class Pint passed after behavioral execution. |
| Frontend portion of job `110380396693` | Fresh locked npm install; all 361 recorded cases across 23 files passed, including 20 unique new cases. TypeScript and Vite passed with 596 transformed modules; client scan checked four bundles without hits; npm audit reported zero vulnerabilities. Actual Node 24.21.0 and npm 11.19.0. |
| Native portion | Exactly two ordinary project identities discovered. Both first attempts failed at the initial authenticated `/admin/site-releases/1/preview` request: expected HTTP 200, received 404 at line 21. Chromium duration 2,851 ms; WebKit 10,350 ms; zero retries. No new guidance/manifest/icon/navigation/audio assertion or success attachment was reached. The receipt-check step was skipped. Failure screenshots and traces were retained; they are not successful guidance screenshots. |

Both PHP jobs used actual PHP 8.4.26, Composer 2.10.3, PHPUnit 12.5.34 and Pint 1.32.1. Composer strict validation and security audit passed. All three original archives match the API and uploaded-log SHA-256, pass every ZIP CRC, and retain matching tracked-source bookends: 941 files, consisting of the exact 940-file permanent checkpoint plus the sole temporary workflow.

| Original archive | Bytes | SHA-256 |
| --- | --- | --- |
| [SQLite `11163850896`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36865662042/artifacts/11163850896) | 57,959 | `dd7d4f2353cd59fda1d0170f58bd89aad6902bd9e9080017a5a632a1cc1d1563` |
| [MySQL `11163446578`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36865662042/artifacts/11163446578) | 58,769 | `27bc9a994faf644e01da4edf59960337796545c510d14ce503f6420a11ca0ea4` |
| [Frontend/native `11163866322`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36865662042/artifacts/11163866322) | 2,816,340 | `3cf0ed9d76f2af711cb0723262649144fbb84268677ecc4d81075fc437aec776` |

The PHP archives contain 18/21 members and 16/19 checked raw-member digest receipts respectively. The frontend/native archive contains 55 members and 53 checked retained raw-member digest receipts. Its original source bookend also recorded the 145-byte hidden `test-results/.last-run.json`, SHA-256 `72819cf7e01896f787998e7e6fa8ae5a50e91b1eec1989c56158600882d4d845`; the original upload's hidden-file exclusion omitted those bytes. That recorded digest is not a retained original-member verification.

The ordinary wrapper/bootstrap creates the operator/customer and four synthetic draft tracks, but no CMS release. Migration creates an empty release table and a publication pointer with no active release. `Original site content` is retained only on a later first publication. The initial native test's hardcoded release 1 therefore addressed a nonexistent snapshot. The actual 404 traces retained no-store/private, noindex and no-referrer headers; this failure did not establish a defect in the PWA UI.

The correction changes only the new native definition, a new CLI evidence helper and this guide. It uses the established New content draft / Save private draft UI with one label per project, asserts Private draft and obtains the real row's Preview URL. It does not publish, change readiness or replace the controller. Before setup, after setup and after the journey, `verify-public-installation-fixture.php` reads complete raw application table rows and `sqlite_master` schema in the original wrapper's guarded disposable SQLite installation. SQLite `query_only` prevents database writes by the helper. Setup must add exactly one immutable schema-2 default draft and its precise `site.release.created` audit; every other row and the schema must stay identical. The final journey snapshot must equal the post-setup snapshot, including publication/history, catalog/readiness, customer and commercial records. Full raw snapshots remain mode-0600 files inside the wrapper's mode-0700 private temporary directory; the native artifact receives only fixed receipts, hashes and numeric locators.

The original PHP/frontend results belong to the frozen failed proof source. The corrected fixture requires genuine native execution; no pass is inferred from static review or the earlier PHP/frontend components. The original wrapper/bootstrap, configuration, timeouts, zero-retry policy and all original journey assertions remain unchanged. Physical installation and the final complete Foundation gate remain open.

## Native-only successor: canonical query expectation

The genuine native-only run [36867940736](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36867940736), created October 1 at 13:20:16 UTC, also completed **failure**. Exact source `eba5488a757c4684d25340d881ff982cd99b9c30`, tree `fb74eacc8237a274058bd57bd6bdaf4ce5d738e0`, was the sole temporary workflow over permanent checkpoint `0751e9638119516e64db13c8a2b0c47e989489a6` / tree `5b2c123d49dd839d1d64cdfaa2bc94d369dfa2d2`, whose sole parent was `c456a69ea299dd308c2f995c432df8ef62e62016`. The source change was restricted to the native draft setup, its read-only evidence helper and this guide. Application/assets, the six PHP methods and frontend tests remained identical to the earlier component proof; those cases were not rerun.

Fresh Composer install/strict validation/audit, new helper PHP syntax, fresh npm install, TypeScript/Vite build, four-bundle client scan and npm audit passed. The actual draft setup proceeded through the normal UI, and both initial real preview requests returned HTTP 200 with no-store/private, noindex/nofollow and no-referrer protections. The test reached the real manifest/icon checks and advancing native-audio setup. Both native projects then failed line 95 on the private preview's exact URL: the expected query was `q=Synthetic&genre=Test&sort=title`, while the real Inertia URL was `genre=Test&q=Synthetic&sort=title`. Chromium lasted 17,762 ms; WebKit 29,308 ms; both used retry 0. Final head cleanup/restoration, guidance/home navigation, audio retention, complete final fixture snapshot, success attachments and helper Pint were not reached. No completed journey is claimed.

The original [archive `11164554359`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36867940736/artifacts/11164554359) is 39,084,846 bytes, SHA-256 `f5cafa898a4b2cb8518ba7d3be2c24144107f5f5ce1610e7bb7218f32220d6a2`, matching API and upload-log digests. All 53 original members and all four nested trace archives pass CRC checks. All 51 retained raw-member digest receipts match, including the hidden `.last-run.json`, which records failure and the two failed identities. All 942 tracked source hashes match the exact permanent 941-file source plus its temporary workflow; safe source/path bookends passed before upload. The shared original was streamed intact from the connector's exact authorized download reference because the ordinary file-transfer tool's 32 MiB cap is smaller than this archive. No reconstructed ZIP substitutes for its hard digest.

The locked canonicalization chain is the real preview controller's Inertia response → inertia-laravel `Response::getUrl()` → Laravel request `fullUrl()` → Symfony `getQueryString()` / `normalizeQueryString()`, which sorts query keys. The fixed ASCII fixture values remain exactly the same. The correction derives the private expected query with `URLSearchParams(query).sort()` and retains exact full-URL equality, including origin/path, all three key/value pairs and absence of extra query values. Public initial/back-navigation URL expectations stay unchanged. No application canonicalizer, privacy/head/audio/fixture assertion, wrapper, configuration, timeout or retry is changed. Corrected native execution, helper Pint and complete Foundation acceptance remain pending.

## Successful native-only proof

The corrected genuine run [36870313663](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36870313663), attempt 1, completed **success**. It was created October 1, 2026 at 13:39:39 UTC; job `110396096358` ran from 13:39:41 to 13:42:07 UTC, with every step successful. Exact source `52bfd403b95d0352610931b96a6a0e492214b691`, tree `c361c39c870fce302b599bbf3d764477426f1f1a`, was the sole temporary workflow over checkpoint `36ab8871f7fb7acdf4090bcf4f65489403235948` / tree `75d815fdf64db0f10102c32705c82e26022fa3cc`. That checkpoint's sole parent was `0751e9638119516e64db13c8a2b0c47e989489a6`; only the exact private-query expectation and this guide changed. The helper, application/assets, PHP feature class, frontend tests, original wrapper/configuration and inherited shared worker remained unchanged. The automatic Focused feedback is a distinct subset.

Fresh locked Composer/npm installs, strict Composer validation/audit, both helper/worker PHP syntax checks, TypeScript/Vite build, client scan and dependency audit passed. Actual versions were PHP 8.4.26, Composer 2.10.3, PHPUnit 12.5.34, Pint 1.32.1, Node 24.21.0 and npm 11.19.0. Vite transformed 596 modules; the client scan checked four bundles without hits; npm reported zero vulnerabilities. Both ordinary native identities were genuinely discovered and executed under unchanged suite/case limits, with zero retries, skips, flaky results or errors. Helper Pint passed for one file after completed behavioral execution.

| Actual project | First-attempt duration | Unpublished draft | Original guidance PNG |
| --- | --- | --- | --- |
| Chromium desktop | 7,929 ms | 1 | 1440 × 4464 pixels |
| WebKit mobile, 320 CSS-pixel viewport | 22,115 ms | 2 | 960 × 16422 pixels |

Each completed journey reached the real authorized private controller, exact canonical three-pair URL, public/private/public keyed head removal/restoration, keyboard disclosure and home navigation/focus, and the same previously playing native audio owner, still unpaused with a nondecreasing playback time. Strict post-navigation advancement was not established. The WebKit case also checked reflow at a 320 CSS-pixel viewport. Manifest and all three real PNG endpoints returned 200. The request guards and whole-journey page-error guard passed. The established storefront/WAV transport remains synthetic; the private controller and installation assets remain real, with no simulated installer event or OS installation.

Both five-field metadata attachments have SHA-256 `6bc39e8a29031c55975e582597f59e484de76b51d887b6c999fd0af0bdcf18ae`: exact manifest, private head removed, public head restored and native owner preserved, with `physicalInstallationVerified` explicitly false. The six-field fixture attachments retain distinct numeric drafts 1/2, the same immutable default-content hash `2a8b9e304c0fbebeddeead3e2c7372a861750e9b93904dbfd5822b46042eb06c`, and distinct post-setup snapshot hashes. Both require exactly one draft plus its precise created audit as the setup delta and complete raw journey row/schema equality afterward. No publication or later fixture mutation occurred; raw snapshots stayed inside the private disposable wrapper directory.

The original [archive `11167081450`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36870313663/artifacts/11167081450) is 4,847,966 bytes, SHA-256 `3ff9279e5c5dd4d7462ed211b9b7a6abc6b447529a7bf982ba8f6d27658a951f`, matching API and upload-log digests. All 28 unique members pass CRC checks; all 26 retained raw-member digest receipts match, including the hidden passed `.last-run.json`. All 942 tracked hashes, actual push/repository/ref/source identity, exact 941-file permanent tree and unchanged-source bookends match. JSON and the two-member embedded HTML report agree on identities, first attempts, timings and the complete attachment bytes; the embedded ZIP also passes CRC. Native discovery JSON SHA-256 is `f97b8b70db6f860341d137929dfbb0870e31123d77ddf24db12da1be04f9af47`, result JSON `80ed9202c2f47c53565cb95e614c8d176ea0362e1b35a00b5c48b97e0a417048`, and strict native receipt `5957d981601ad21000486451b53a052d8a93c36e2d3c7bf21f7b7d2aa0d55d0f`.

Both original PNGs decode correctly and show readable, wrapped manual guidance with the approved identity and visible keyboard focus, without horizontal clipping at the checked widths. Their SHA-256 values are `084dec79005602645fa452e99907b0c2aab37fa8028e9fd8b3e47a9ae7208704` (Chromium) and `c7122cd8be9865c5d7b29b792e09dd1c345284b39ddff3c27e0ecbb6ce080008` (WebKit). These are genuine successful guidance screenshots; no successful trace is claimed.

This native-only source-specific result completes the corrected focused browser boundary. It does not rerun or replace the earlier six-case-per-engine PHP and 361-case frontend results, whose application/asset/feature/frontend-test blobs remain unchanged. Both earlier hosted native failures and all local draft failures remain recorded above. The inherited checkpoint's authority finding is historical source context, not resolved or accepted by this PWA proof. Complete Foundation verification on the final integrated source, accepted combined follow-up work, physical installation/launcher legibility, assistive technology, offline/background behavior, T33 and WP-05 remain open.
