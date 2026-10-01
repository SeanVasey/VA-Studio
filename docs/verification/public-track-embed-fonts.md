# Standalone public preview fonts

Evidence date: 2026-10-01. Status: bounded source correction reviewed; actual focused font verification and fresh full Foundation acceptance remain pending.

The public preview iframe previously loaded `public/brand/theme.css` and `public/css/track-embed.css`. Those styles supply font-family tokens and layout, but the iframe has its own document and cannot inherit the storefront's `@font-face` declarations. The body could therefore use a fallback font despite naming the approved family. The previous embed CSP also denied fonts through `default-src 'none'` without a `font-src` allowance.

## Exact source boundary

Source commit `eef4918605de0096a03a30ac10026080834c9890`, tree `897386a6a66b377db93f2442c12242f1cf91fa07`, starts from final PR #89 checkpoint `0d9583a8cfe8d4085eb3e2295c20df038fa73253`, tree `1c4a61767267d693b58a879e4d93a42d891ceed1`. Its complete delta contains only these seven paths:

| Path | Bounded change |
| --- | --- |
| `resources/css/fonts.css` | Extract the exact existing Bebas Neue import and three Reddit Sans, Noto Sans Display and JetBrains Mono declarations. |
| `resources/css/app.css` | Import that shared declaration file in place of the extracted block; every other byte remains unchanged. |
| `vite.config.ts` | Add the shared CSS as an independent build entry beside the existing application entry. |
| `resources/views/public-track-embed.blade.php` | Load only that CSS entry through `@vite`; retain existing theme, layout and script-free markup. |
| `app/Http/Responses/PublicTrackEmbedResponse.php` | Add only `font-src 'self'` to the existing CSP. |
| `tests/Feature/PublicTrackEmbedTest.php` | Use ordinary `withoutVite()` for backend-only tests and update the exact CSP expectation. |
| `tests/browser/public-track-embed.spec.ts` | Add actual font-face, font-response and CSP evidence to the existing native case. |

Vite resolves the unchanged approved package font files into built assets on the same store origin. The standalone CSS entry does not load the React storefront. The native Blade helper remains unchanged and uses the actual built manifest; its browser wrapper refuses a hot-server file. Package versions, lockfile, font asset declarations, brand tokens and embed layout remain unchanged. Font licensing and provenance remain in the [brand font manifest](../brand/font-manifest.json) and [active theme guide](../brand/README.md).

The embed still denies content by default. Styles, fonts and media are limited to `'self'`; script, base-URL and form permissions remain denied, and the existing HTTP(S) framing boundary is unchanged. No route, session, catalog eligibility, media-integrity, provider or purchase authority changes. See the [public embed boundary](public-track-embed.md).

## Source checks and pending runtime proof

Local whole-file inverses restore the original application CSS, Blade, Vite configuration, response and HTTP-test source exactly. The shared declarations match the original extracted block excluding its terminal separator newline; restoring that separator after the shared file reconstructs the original application CSS exactly. Removing only the three new native-test blocks restores the complete prior spec, including its case title, audio playback/pause, keyboard store exit, foreign-origin iframe and null-opener assertions. All 895 other base blobs and modes remain unchanged. Node syntax checks for the Vite configuration and native spec pass. The first pre-stage whitespace check missed a blank line at EOF in the untracked shared file; the successor removes only that terminal separator. Actual committed `git diff --check` comparisons from the base to the corrected source and from the corrected source to this guide pass. These are source checks, not PHP, TypeScript type checking, Vite build or native font execution.

The existing browser case now requires `document.fonts.load()` to return nonempty faces for all four approved families, each with the expected family and `loaded` status, and requires `document.fonts.check()` to succeed. It also requires four real same-origin WOFF2 responses with HTTP 200 and no iframe `securitypolicyviolation` event. Its JSON attachment records loaded faces and font responses. No font response is mocked; the existing synthetic audio transport remains limited native UI evidence rather than publication or media-integrity proof. No test identity, retry, timeout, skip or original assertion is removed.

[Foundation run 36851992293](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36851992293) checks the earlier head `45050eb8f6a229ec6a115eeb584e3b2d08c5e161` through merge `c43770c37df417e90a333e3f788b13fc6f881332`, tree `1c4a61767267d693b58a879e4d93a42d891ceed1`. It does not contain this font correction and remains diagnostic/source-specific evidence; its results cannot accept the successor. A genuine focused font run and a fresh complete Foundation on the final corrected source, followed by independent receipt/source review and the expected-head merge, remain required. No full acceptance, parent task completion, physical-device font result or package completion is claimed here.

## Actual failed focused proofs and reviewed successor

The following original archives were independently checked for hard SHA-256, full-member CRC, exact source hashes and actual reports. Both runs remain **failed** narrow proofs; their successful steps do not establish two-browser or full Foundation acceptance.

| Run / exact hosted source | Original artifact / verified ZIP SHA-256 | Actual outcome |
| --- | --- | --- |
| [36856788745](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36856788745), head `4a1829eac450a82b63a8e3187b5224667554eb3f`, tree `eea7c4659c298ed90cb83d3837ff52d2077d0d14`, parent `45050eb8f6a229ec6a115eeb584e3b2d08c5e161` | `11159178737` / `b2c711df098fae296ae8c8b805f3f79ee6225d0d459e0ae28bbc3a81b001fef6` | All 336 frontend cases across 21 files passed. Type checking then failed with two TS2352 errors in the new Window casts. Vite, client scan, npm high audit, PHP discovery/execution, native discovery/execution and final receipt validation were not reached. |
| [36857644809](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36857644809), head `eabe5747ff53b3e779bf96110dc8d717f5bed2cf`, tree `07d4c1f885dc9b2f3dad4583196e259005c3ef67`, parent `4a1829eac450a82b63a8e3187b5224667554eb3f` | `11159690538` / `b36ec767432841218afd58736013397ed5c4772ea713968fe96741f963118217` | All 336 frontend cases, TypeScript/Vite, client scan and npm high audit passed. All 15 discovered PHP cases passed with 959 assertions and no skips. Chromium's native case passed; WebKit's case failed at the final strict CSP assertion after screenshot instrumentation. Final receipt validation was not reached. |

The first run used the reviewed `eef4918` source above. Its narrow typing successor `06c459da17c84fb95808d1e6be328ed868af542a`, tree `89e0964ce84da3a0b5ada6f399af5e9fb3b2a65d`, inserts only an explicit `unknown` intermediary into the two new Window type assertions. The full byte inverse restores the previous spec; these TypeScript casts erase without changing runtime expressions, the event listener or assertions. The second run used those corrected source blobs, with its temporary proof workflow kept outside PR #89. The focused protocol recorded Pint's version, rather than claiming a Pint formatting pass.

WebKit's original trace identifies screenshot `call@72` starting at 7,447.635ms, the first iframe CSP console violation at 7,458.279ms, and screenshot completion at 7,534.774ms. The locked Playwright 1.63.0 source confirms that WebKit screenshot preparation unconditionally injects a `<style>body{}</style>` into frames to synchronize animations before its caret/style/animation early return. Setting `caret: 'initial'` alone would therefore leave this injection. The observed `style-src-elem inline` violation comes from that screenshot operation. The original WebKit trace also retains four real HTTP 200 WOFF2 responses; that partial font evidence does not convert its failed journey into a pass.

Reviewed successor `543113552eda465f3e45a70bac8ff9342d3d037b`, tree `d1bcbb52f4b508e6436b63bab7634a2be57614cb`, changes only the native spec from `06c459d`. It moves the existing screenshot to the very end, after complete play/pause, keyboard exit, popup and null-opener assertions, the cumulative iframe CSP `expect(cspViolations).toEqual([])`, and the font evidence attachment. That JSON now includes the captured `cspViolations` array so an independent receipt reader can require an explicit empty array. Screenshot filename and `fullPage` stay unchanged. No event is filtered or reset, no CSP/header permission is relaxed, and no caret workaround, timeout, retry, skip, font response, test identity or original assertion is changed.

The one-path successor passes exact byte-inverse, Node syntax and committed whitespace checks. Its genuine focused runtime proof remains pending at this append checkpoint. The composed source and refreshed timing inputs still require fresh complete Foundation acceptance and independent exact-source review; neither failed predecessor accepts that composition. Physical-device results, parent task completion and package completion remain open.

## Actual successful focused proof

[Run 36858946180](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36858946180), job `110358114986`, completed **SUCCESS** on head `74822f1f36c79f76da3da04ac6359362e792f167`, tree `3e726e6f854b4471ad30994c6e2a761b1f906873`, sole parent `eabe5747ff53b3e779bf96110dc8d717f5bed2cf`. Its seven permanent source blobs equal reviewed `543113552eda465f3e45a70bac8ff9342d3d037b`; isolated protocol `ffbea5ea502af77005a7acbb1545fc4f32b106f4` adds the temporary proof workflow. That workflow is excluded from the production candidate.

Original artifact `11160711405` is 366,712 bytes, with 29 members, verified ZIP SHA-256 `e204bd5039f1382be336cda6e19d663fb6acbe1b0e6dc41537bda5e8f725ecc0` and full-member CRC. Independent review checked all 903 tracked source hashes, original raw evidence digests, discovery identities, JUnit/native reports and the positive focused receipt against the exact hosted source.

| Actual scope | Result |
| --- | --- |
| PHP on fresh SQLite | 15 unique discovered/executed cases, 959 assertions, no errors, failures or skips; CLI 14.225s, JUnit suite 14.222374s. |
| Frontend | All 336 cases across 21 files passed with no errors, failures or skips; CLI 38.02s. |
| Build/security checks | TypeScript passed; Vite built 593 modules in 350ms with the standalone CSS entry; client scan checked four bundles without hits; npm high audit reported zero vulnerabilities. Composer validation/audit and both affected PHP syntax checks passed. Pint recorded its version only. |
| Existing native case on both engines | Chromium desktop 1.348s and WebKit mobile 4.200s, both first-attempt passes; zero retries, unexpected results, flaky results or skips. |

Each native result retains nonempty checked and `loaded` faces for Reddit Sans, Noto Sans Display, JetBrains Mono and Bebas Neue, four real same-origin WOFF2 HTTP 200 responses, and explicit `cspViolations: []`. Both JSON attachments have SHA-256 `25e5114d3d122d50282127038e2035d8050556dbe1032e3a94f10402994700c9`. The empty cumulative CSP snapshot covers the entire application journey, including the original playback/pause, keyboard exit, popup and null-opener assertions, **before** final Playwright screenshot instrumentation. It does not claim that screenshot-injected styles produce no CSP event. No violation is filtered or reset and no header permission is weakened. Genuine HTML evidence and both final screenshots are retained.

This establishes only the exact source-specific focused scope above. Both predecessors remain failed runs. The final composed font source and refreshed timing inputs still require fresh complete Foundation acceptance and independent exact-source review. Broad device acceptance, physical-device results, parent task completion and package completion remain pending.

## Full-run worker expectation failure and focused correction

[Foundation run 36859991297](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36859991297) used head `1635101e646825f293c25459fa79cd09b11e9825` and signed checkout merge `8ba14a10f78648f85499fc0f34c57f87c2888b93`, both with tree `447044c33badbd6eeb61630b051bfb2911f30ba4`. Its SQLite shard 1 failed the two existing cached/uncached `PublicTrackEmbedRouteCacheTest` cases: the fresh-boot worker still compared against the pre-font exact CSP literal. The production response correctly included the reviewed `font-src 'self'`; that added directive made the stale full-string expectation classify responses as privacy failures. Original failed artifact `11160924499` has verified ZIP SHA-256 `b6738253efad5c9024d14f95c9c04f3e1e88c4e9e644a12010c483096fc14bcd`. This attempt does not establish full Foundation acceptance.

Reviewed correction `41850ec6799d2195172dc3fd9744d92460b5f7a9`, tree `c57521c29bac8eca12dad6e82f5085f840d44d3e`, changes only `tests/Support/public-embed-route-cache-worker.php`: insert `font-src 'self'` into that expected literal. Its whole-file inverse restores the old helper exactly. Every other privacy/auth/header predicate, response/status/budget assertion, request, test identity and 60-second worker deadline remains unchanged. The feature test and all production font, layout and CSP source stay byte-identical.

[Focused run 36863304538](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36863304538), job `110372563308`, completed **SUCCESS** on head `8ca349c011bb66193f99fef8f3c4617ca9fa8021`, tree `6e9dee5a1168167becbbfc64968c239df409ea20`, sole parent `1635101e646825f293c25459fa79cd09b11e9825`. Independent original-archive review verified all 904 source hashes: the 903 permanent files match the reviewed correction, plus one temporary proof workflow excluded from the production candidate. Artifact `11163286550` is 66,011 bytes with 19 unique members, full-member CRC and verified ZIP SHA-256 `cd9bdd7c79808f404017da44ebaaae962e0a917b36bd81445db32ed2a3adaf77`. Its positive receipt SHA-256 is `2e122dea92dfba9e17761924b38493a47707a4d992979bb361f29e3f47be7a4a`.

| Actual fresh-SQLite case | Assertions | JUnit time | Outcome |
| --- | --- | --- | --- |
| Existing uncached worker case | 374 | 0.726046s | Passed |
| Existing cached worker case | 376 | 0.923404s | Passed |

Both genuinely discovered identities executed: 2 cases, 750 assertions, zero failures, errors or skips; CLI 1.651s, JUnit suite 1.649450s. Actual runtime was PHP 8.4.26, PHPUnit 12.5.34 and SQLite 3.45.1, with zero outer tables before the tests and a disposable file database per worker. All 364 original requests per mode retain their strict privacy/auth/header, status and separate per-IP budget checks. Both affected PHP syntax checks and Pint 1.32.1's two-file check passed; Composer 2.10.3 strict validation passed and its audit reported zero vulnerabilities. This isolated check ran no MySQL, frontend or native suite.

The worker-only expectation correction leaves the reviewed font source and both passing native font journeys from head `1635101` unchanged; it requires no separate native replay. Those results remain narrow source-specific evidence. The failed Foundation attempt remains unsuccessful, the older `45050` results cannot accept this successor, and the final composed source still requires a fresh complete Foundation pass, independent exact-source/receipt review and expected-head merge. No current composition pass, physical-device acceptance, parent task completion or package completion is claimed.

## Origin independence correction — October 1, 2026

The standalone Vite CSS entry worked in the verified ordinary build, but it could still select a development hot-server URL or a configured cross-origin `ASSET_URL`. Both are correctly denied by the embed's unchanged `style-src 'self'; font-src 'self'` policy. The earlier ordinary-build proof did not cover those configurations. This section supersedes the preceding description of the embed's asset resolution; the dated verification results above remain tied to their original source.

The successor starts from the latest PR #89 source, `d8791c1`, represented locally by commit `fe15ba6f322c36bcd3fb40b3239fed6afd4eaf09`, tree `3fa7de98f3bfaa2e6a9c4effe8530f99beb701da`. It replaces only the embed's `@vite` call with `/css/track-embed-fonts.css`. That stylesheet refers directly to the four approved WOFF2 files under `/brand/fonts/`. These root-relative public paths stay on the document's origin, work independently of a Vite manifest or hot file, and bypass Laravel's configured asset host. The storefront's application stylesheet, shared `resources/css/fonts.css`, Vite configuration and generated application assets keep their existing behavior.

The retained static fonts have these exact identities:

| Family / Latin WOFF2 | Bytes | Approved SHA-256 |
| --- | --- | --- |
| Noto Sans Display / `noto-sans-display-latin-standard-normal.woff2` | 69,912 | `b669ff51021c003e3c596d9e11671ba890f87e59c017b309e4633e699133759c` |
| Reddit Sans / `reddit-sans-latin-wght-normal.woff2` | 41,844 | `3e3b66d208b63e175830ee0dedb631c5df11bd93d01e890e3692ecffab612262` |
| JetBrains Mono / `jetbrains-mono-latin-wght-normal.woff2` | 40,404 | `18be452724bfdc236c074ca94a249a7f41a86752c7d04ab258ce9ed5651f6a7e` |
| Bebas Neue / `bebas-neue-latin-400-normal.woff2` | 13,768 | `a7c90c89240c134f7fdd33d40c000ec90b79d675ea53e8cc5a6d423c073de412` |

Each file is copied without transformation from its locked Fontsource 5.3.0 package. The downloaded package archives were verified against `package-lock.json` SHA-512 integrity before extracting the approved Latin files and each package's complete OFL license. `public/brand/fonts/source-manifest.json` records package URLs, versions, archive SHA-256, lock integrity, font byte counts and SHA-256, and license paths and SHA-256. The original [brand font manifest](../brand/font-manifest.json), theme tokens, layout, approved 420 × 100 raster logo and identity bytes remain unchanged. No font host, wildcard, development script, inline-style permission or other CSP allowance is added.

The source delta comprises the Blade file, the dedicated static stylesheet, four WOFF2 files, four exact package licenses, their source manifest, backend/native/frontend tests, the isolated Blade-render helper and this append-only guide. Backend tests no longer suppress Vite, and three added configurations exercise a real temporary hot file, a foreign configured asset origin, and both together. Each first proves that its configuration is active, then requires only the three exact root-relative stylesheet links, no script/modulepreload/style element, and the unchanged response privacy and CSP assertions. Existing cached/uncached route-budget workers and their strict response checks are unchanged.

The existing native iframe journey retains its title, browser projects, audio/keyboard/store-exit assertions and empty cumulative CSP requirement. Its font response assertions now require the exact static paths. A new native case exercises the same three configurations on each engine. Its renderer confines the temporary hot-file override to the wrapper-owned disposable directory, proves the active hot/asset settings, and removes only a file it created. Real stylesheet and font responses are never mocked. Each configuration requires three same-origin stylesheet HTTP 200 responses, all four actual font HTTP 200 responses with the approved raw response SHA-256, nonempty checked and loaded faces for every approved family, no foreign requests, and an unfiltered empty CSP event list. The only mocked document is the existing isolated real-Blade projection; this native test does not establish published-track readiness or media integrity, which remain backend gates.

Five frontend checks verify the copied font bytes against the approved manifest and installed locked package files, exact accompanying license bytes and provenance, and root-relative standalone font URLs with the retained family weights and width axis. Local Node 24.19.0 installation added 125 locked packages. The five added frontend tests passed; TypeScript checking and Vite's 593-module build passed, with the build completing in 425ms. Committed source checks remain separate from PHP and native runtime proof: this environment has no PHP or Composer runtime, so neither was run locally. A fresh complete Foundation on the final composed source, its original database/browser evidence and independent exact-source review remain required. No full acceptance, physical-device result, merge, deployment or parent-task completion is claimed here.
