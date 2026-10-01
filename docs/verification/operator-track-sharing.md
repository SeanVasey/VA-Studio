# T17 operator public sharing candidate

The original child was implemented against base `8f6a0c96774acc4c56005a4597458bf1fe2b86fc` (tree `fa4a7e4`). Its twelve paths were independently source/privacy reviewed at `62724ae4ce0e17fdc3b31b26e4cba003147f07c2` (tree `ddbfb7b43476b5139755c78436c907898e9e922e`), then rebased unchanged onto the then-current PR89 candidate `d70a0edcfb402c67f3b66ac4a30489f2e7d1a3d7` in permanent integration `991cb4f0978f17c66c4ec779216277a3533973c4` (tree `bd02d461d2fdbad43ae089c385042424d8e2bb35`). PR89 remains a separate evolving acceptance boundary. This is an isolated sharing child, not acceptance of T17, WP-09 or the release. The preceding inquiry-notification and native-player candidates are separate evidence boundaries.

## Owned implementation

- `app/Domain/Catalog/ReadTrackSharing.php`: fresh persisted staff/MFA and public catalog eligibility, canonical slug and root-origin validation, minimal read-only descriptor.
- `app/Filament/Resources/TrackResource.php`: replace the status-only open-link action with a freshly rendered sharing dialog.
- `resources/views/filament/catalog/track-sharing.blade.php` and `resources/js/admin/operator-sharing.js`: escaped public fields, fixed embed text, semantic copy/select controls and guarded clipboard lifecycle.
- `tests/Feature/ReadTrackSharingTest.php`, `tests/frontend/operator-sharing.test.mjs`, its Vitest wrapper, and `tests/browser-related/operator-sharing.spec.ts`: dedicated backend, client-state and isolated operator journey coverage.
- [Operator contract](../track-sharing.md#operator-copy-controls--t17-child-october-1-2026).

The core implementation owns the ten paths above. The separately owned native selection/discovery commit adds two paths (`playwright.related.config.ts` and `scripts/ci/test-related-browser-stage.py`), making twelve paths in the integrated candidate. No migration, customer payload, provider activation, inquiry schema, scanner preparer, native-player code, CI workflow, root task register or development-order change is part of this child. The copied iframe uses the existing public embed routes and their established CSP and media authorization.

## Executed local checks

On October 1, 2026, Node `v24.19.0` executed these checks successfully:

```sh
node --check resources/js/admin/operator-sharing.js
node --check tests/frontend/operator-sharing.test.ts
node --check tests/browser-related/operator-sharing.spec.ts
node --test tests/frontend/operator-sharing.test.mjs
git diff --check
```

The exact standalone Alpine state expression passed **15 tests**, with zero failures or skips. Cases cover pending/fulfilled/rejected/non-promise writes, unavailable/insecure/throwing APIs, exact readonly values, duplicate-click serialization, manual selection, destruction, modal closure and stale/disconnected callbacks. These tests evaluate the real helper against bounded DOM/API doubles; they do not execute Blade, Filament, Alpine integration, a browser or actual OS clipboard permissions. Node syntax checks do not establish TypeScript type checking or Playwright execution.

PHP, Composer/vendor dependencies and the project's frontend dependencies are unavailable locally. PHP syntax/Pint, feature/Livewire tests, Vitest integration, TypeScript/Vite and native browser execution have not been performed here. No prior CI receipt substitutes for this candidate's tests.

The separately owned native-stage guard was attempted locally. Its three standalone wrapper/installer refusal methods passed. The full command encountered five prerequisite failures (four marker subcases and one discovery case), each reporting `MODULE_NOT_FOUND` for the absent locked `node_modules/@playwright/test/cli.js`. Python compilation and whitespace checks passed. These prerequisite failures do not prove the intended runtime marker/discovery behavior, which still requires the locked dependencies.

## Hosted unit feedback — October 1, 2026

[Focused run `36835922340`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835922340), PHP job `110283273423`, passed on actual remote source `7be7d48c9220de4195d326ebfbb6f65498f431c3`, tree `daed97e7d0ff55b549ba841fc6e2ce263c6e6ba9`. That tree matches local temporary native-proof `1d8508d`; it differs from permanent `991cb4f` only by the three reviewed temporary proof files. All twelve sharing blobs match the reviewed original child. The source/tree values were recorded by the actual selected-test runner, not inferred from a later branch head.

This automatic push selected **only sixteen existing unit files** (`FOCUSED_SUITE=unit`, SQLite); it did **not** discover or execute `ReadTrackSharingTest.php`. The frontend and browser jobs in this run were skipped. Selection safeguards passed 21 tests, and PHP `8.4.26` / PHPUnit `12.5.34` completed **353 unique unit cases, 1,443 assertions, zero failures/errors/skips**, in **3.500 seconds**. This receipt supplies unit regression feedback; it does not establish the new sharing service, mounted modal, frontend wrapper or native journey.

[Artifact `11148833599`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835922340/artifacts/11148833599) was downloaded and independently checked in full. Its three members (focused evidence, generated PHPUnit configuration and JUnit XML) agree on the exact sixteen unit-file paths and 353 unique case identities, with no `ReadTrackSharingTest` or frontend identity. ZIP CRC checks pass and its SHA-256 matches the upload log: `4ddf07d949ce5cda14dbcacd852243fe56caf230e33e19bb58ddf8fdf7377325`. The JUnit XML SHA-256 is `55827a64e73a598a2acea40aa6a68283d05dd3cb7ee87c37d8e8c9c414d2a401`.

Dedicated fixed selection of `ReadTrackSharingTest.php` on SQLite and MySQL, frontend integration and genuine native proof remain separate evidence requirements below. Temporary proof files are excluded from the permanent feature candidate.

## Initial native proof — actual failure, October 1, 2026

[Native run `36835922175`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835922175), job `110283188662`, executed on the same remote `7be7d48c9220de4195d326ebfbb6f65498f431c3` / tree `daed97e7d0ff55b549ba841fc6e2ce263c6e6ba9`. PHP `8.4.26`, Node `v24.21.0`, Playwright `1.63.0` and genuine ClamAV `1.5.4` with current official signature database `28140` were recorded. All five permanent startup/discovery guard methods passed; the temporary census selected exactly the sharing case in Chromium desktop and WebKit mobile. The TypeScript/Vite build passed.

Genuine preparation completed with two projects and four tracks, evidence hash `10947eb118ee667d905e99dcd4d3dfe4c72ea0a8bb28fc2fd4b79467db403cc8`. Both trace attachments contain successful pre-journey verification of retained evidence and current eligibility in the `published` fixture state. The actual journeys then **both failed**: Chromium after **14.7 seconds**, WebKit after **20.4 seconds**. Each opened the real Share action and passed the public field, route, inert embed and reflow checks, but **Copy link left the status empty** until the ordinary ten-second expectation failed (`operator-sharing.spec.ts`, lines 112/115). Embed copying, manual selection, close/focus restoration, post-journey evidence verification and final request/error assertions were not reached; this is not native acceptance.

[Artifact `11149058481`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36835922175/artifacts/11149058481) was downloaded and its complete ZIP passed CRC and upload-digest comparison: `23a205b914f875d3253f1792d31fbfb4031e418411e84d6c7b4eaf6386b18d09`. Both traces and failure contexts were inspected. The captured `x-data` helper is byte-identical to the frozen source; each correctly wired copy button is a sibling of the readonly field within the sharing root, and the click completes without a console/page error. The helper's pre-write guard uses `this.$el.contains(field)`, so it returns before starting an operation or updating status. Alpine documents [`$el`](https://alpinejs.dev/magics/el) as the current directive node and [`$root`](https://alpinejs.dev/magics/root) as the nearest `x-data` root: the clicked button cannot contain that sibling input.

The bounded repair uses the component root for the same connectivity/containment guards and separates the triggering button from that root in the helper regression fixture. An absent component root also fails closed: Alpine can lose the nearest root when a trigger detaches while the clipboard promise is pending. The two-path repair was independently reviewed at `53ad5b0b2ee72a495779e95dc4881af19864e8a4` (tree `a56c7fa0c33e68fd8ea2d53989563a7b19b6344c`), integrated into the permanent candidate as `34b7e23f8f6f9f912f18334e28ec86877cdedfb4` with that same tree. The original fifteen helper cases retain their assertions and five regressions distinguish button/root scope, invalid roots and root loss during fulfilled/rejected pending callbacks. **Twenty local helper cases passed**, zero failures or skips. The initial eighteen-case corrected fixture from `44ffbef`, run against the original defective helper as a negative control, produced two passes and sixteen failures, exposing the integration mistake.

No native assertion, clipboard path, permission, media preparation, marker, limit or public eligibility rule is relaxed. The repaired exact source still requires a new genuine native run; the initial failed run remains informative evidence of the integration defect.

## Component-root successor — actual failures, October 1, 2026

[Native run `36837330353`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36837330353), job `110287805682`, executed remote `a4e159cc27241cff472f17b58c240f13b1f07c10`, tree `a364fc946e2d81402e52437678c7b4447fc55e22`. This contains the reviewed component-root repair and only the same three temporary proof files beyond permanent `34b7e23`. Genuine preparation again completed with two projects and four tracks, evidence hash `cebf1e57492a0a69e77aa196d1f482c7ffe46af3a2a22393e4aadf0ad5a84cfe`.

Both projects passed the real copy-link, copy-embed and manual-selection assertions. Chromium's ordinary clipboard readback verified both exact copied values; WebKit reported fulfilled native writes without a clipboard-readback claim. Both reported `Public link copied.` and `Embed code copied.`. The journeys nevertheless **both failed** (Chromium **4.5 seconds**, WebKit **12.8 seconds**) because the strict accessible-name `Close` locator matched Filament's header icon and footer action. Close/focus restoration, post-journey retained-evidence verification and final request/error assertions were not reached. [Artifact `11149123858`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36837330353/artifacts/11149123858) passed complete ZIP CRC and upload-digest checks: SHA-256 `64706f90970b743b3cf28f4e286821e2e43b940a924bca16d8e623781accaa35`. Independent inspection of both actual DOM snapshots confirmed the two buttons and the `.fi-modal-footer` containing the named cancel action.

[Application run `36837342722`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36837342722) executed remote `0bb1954181d737baea3b6129a0c574ec98c4a4ea`, tree `5fafa864288ffb72b60e7c09234c4dc870ae67e2`. That tree differs from permanent `34b7e23` only by the independently reviewed, branch-only application-proof workflow. PHP `8.4.26` / PHPUnit `12.5.34` executed the actual fixed `ReadTrackSharingTest.php` selection on both engines; MySQL was `8.4.11`, `REPEATABLE-READ`, with zero tables before tests. All three jobs failed:

| Job | Actual result | Duration |
| --- | --- | --- |
| SQLite `110287846395` | 21 cases, 104 assertions, four failures, zero errors/skips, one risky case | 28.709 s |
| MySQL `110287846236` | Same 21 case identities, 98 assertions, four failures, one error, zero skips, one risky case | 3:37.631 |
| Frontend `110287846020` | 21 of 22 files and 336 of 337 cases passed; the sharing subprocess wrapper failed before invoking Node | 48.27 s |

The four PHP failures came from whole-component `assertSee` calls against Livewire 4's empty `action-modals` shell; the genuine rendered modal is in the response partial. The risky case caught the six expected authorization exceptions but counted no assertions. MySQL additionally stopped the malformed-slug loop when `Legacy` and `legacy ` collided under the real unique-index collation; SQLite exercised all eight invalid grammar classes. Assertions following each initial modal failure were not reached. The frontend wrapper raised `ERR_INVALID_URL_SCHEME` while converting `new URL(..., import.meta.url)` to a filesystem path, before its unchanged twenty-case Node suite could launch. The log does not expose the exact rewritten URL. Build, client scanning, audit and targeted Pint were not reached after the preceding failures.

The complete application artifacts were downloaded, CRC checked and compared with upload digests. Both JUnit files contain the same **21 unique `Tests.Feature.ReadTrackSharingTest` identities** (fourteen methods expanded by seven eligibility and two authority datasets), identity-set SHA-256 `5391880ade4ce0678c1e25081dda71291306b7de4386a50fef24e047d3bc2140`:

| Artifact | ZIP SHA-256 |
| --- | --- |
| [SQLite `11149842261`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36837342722/artifacts/11149842261) | `e64098f147f6cb3a3094090b67eecaad16c4c5854f7fde34d21ace5a29c6cbdf` |
| [MySQL `11150196742`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36837342722/artifacts/11150196742) | `b6e32dfac3f8a9e013978acc9dae10dda7afc6c89ddf31922bb612d204326d86` |
| [Frontend `11150190947`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36837342722/artifacts/11150190947) | `cb4833c17687786835a4a5923ef0086d3b9db33da5a3e35a3134f3a1cbd3ab12` |

## Reviewed three-path proof successor

The permanent source was frozen at `cce1acb95e4eab8be696cd8f908f6c40cf0b7bb0`, tree `18a43c92ce8af46106e88a87c6f126ff8a628772`. It adds exactly three independently reviewed test-harness repairs to `34b7e23`; no application, authority, eligibility, privacy, schema or provider rule changed:

- `tests/frontend/operator-sharing.test.ts`, reviewed at `8ddc8ac35b54cba2ff86341f90469a278f3ffb93`: resolve the fixed standalone suite from the repository working directory. The same Node executable, no-shell subprocess, ten-second timeout and zero-failure output assertion remain.
- `tests/Feature/ReadTrackSharingTest.php`, reviewed at `6b526d893d9dc3648dd363aab9f559ca493ac8fd`: use the locked Filament response-partial modal assertions for both initial mount and ordinary `$refresh`; count seven expected authorization exceptions; give uppercase and trailing-space invalid slugs distinct names. All 21 identities, eight invalid grammar classes, strings, URLs, privacy/evidence comparisons and forbidden assertions remain.
- `tests/browser-related/operator-sharing.spec.ts`, reviewed at `43cd0b2e06e9d0f2055196e4671938805f408222`: scope the exact named `Close` action to the actual semantic modal footer. No positional selection or assertion removal is introduced.

Reversible source comparisons confirmed each imported blob equals its reviewed source, and both temporary proof trees differ only by their unchanged protocol files: native local `0d2ff4ab29efa01073a2a5680fd4b4332f522ad8` / tree `3e5db19f991eb4d40f87d822f39b9c495c27d6c0`; application local `cbeef61ad70c31b8db1d32232f9389805ebaec63` / tree `9dbf9b1a4a63b85a31e87abb157055751ec73802`. Twenty local helper cases, syntax checks and whitespace checks passed. Actual focused results for these exact trees follow; the final integrating gate remains separate.

## Genuine sharing journeys passed — October 1, 2026

[Native run `36838649948`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36838649948), job `110292115042`, passed on remote `14d0589da30be8a7a7edc28212846e5588d26a6a`, tree `3e5db19f991eb4d40f87d822f39b9c495c27d6c0`. The logged source tree equals the reviewed local temporary native proof above. PHP `8.4.26`, Node `v24.21.0`, Playwright `1.63.0` and genuine ClamAV `1.5.4` / official signatures `28140` were recorded. All five permanent startup/discovery safeguards passed in **2.892 seconds**, the fixed temporary census selected exactly two sharing cases, and TypeScript/Vite build passed. No marker, scanner, preparation, project, timeout or retry rule changed.

Genuine preparation completed with two projects and four tracks, evidence hash `0ac10a06f688b129c6afb7e94a796ffda73ad1b85aec2b55acb5308e99b45f2b`. Both journeys passed: **Chromium desktop 5.868 seconds**, **WebKit mobile 16.654 seconds**, **24.871 seconds total**, zero failed/flaky/skipped cases and no report-level errors. Both executed the actual public track/embed requests, inert iframe text, wrapping/focus checks, keyboard manual embed selection, manual link selection, semantic footer Close, focus restoration and final page-error/external-request assertions.

Chromium fulfilled both native clipboard writes and read back the exact public link and iframe code. WebKit fulfilled both ordinary native writes; its attachment explicitly records `clipboardReadVerified: false`. Both before/after attachments verify `retainedEvidence: true`, `currentEligibility: true`, the same `published` fixture state and the same complete evidence hash. This selected proof does not execute the editorial withdrawal journey, demonstrate the WebKit failure path, or establish real-device permissions; the permanent four-case related stage and local synthetic failure-state tests retain those distinct boundaries.

[Artifact `11150690306`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36838649948/artifacts/11150690306), **524,664 bytes**, was checked in full against the upload/API SHA-256 `23967f0767290b2010d2b034e6944b12838e6038978931874e7747c5b78d2ff4`. Its three members are the self-contained report and two actual screenshots. ZIP CRC checks passed, as did the report's embedded ZIP; the embedded JSON contains exactly the two expected projects, both successful results, all six before/copy/after evidence attachments and the final completed assertions. Successful traces are not retained by the existing configuration, so this receipt relies on the actual complete report and screenshots rather than claiming a success trace.

## Application successor — feature/frontend pass, formatting blocked

[Application run `36838682200`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36838682200) executed remote `bb1c04063cd29b02b979f6df7025c8e701815ef9`, tree `9dbf9b1a4a63b85a31e87abb157055751ec73802`, matching the reviewed local temporary application proof. The workflow and three repaired test blobs match the approved source. The complete fixed sharing PHP selection now passes on both actual engines, and the complete standard frontend passes. The overall run remains **failed** because the SQLite job's subsequent targeted Pint check found one style issue in `ReadTrackSharingTest.php`:

| Job | Actual executed result | Duration |
| --- | --- | --- |
| SQLite `110292215712` | 21 unique sharing cases / 122 assertions; zero failures/errors/risky/skips. Targeted Pint failed only the test file; the two application PHP paths passed. | PHPUnit 29.190 s |
| MySQL `110292216192` | Identical 21 cases / 122 assertions; zero failures/errors/risky/skips. Formatting is intentionally checked once by SQLite. | PHPUnit 3:50.328 |
| Frontend `110292216111` | All 22 files / 337 Vitest cases passed, including the wrapper that executes the unchanged twenty-case Node suite; TypeScript/Vite build, client scan and high-severity audit passed. | Vitest 45.86 s |

PHP `8.4.26` / PHPUnit `12.5.34` were recorded; MySQL was again `8.4.11`, `REPEATABLE-READ`, with zero tables before tests. Both complete JUnit files contain exactly the original 21 identities with no issue elements and 122 total assertions. Their SHA-256 values are SQLite `5fd144c5f15eee5f8e1678616cb608f766f05c05311c9fa429500de07929d242` and MySQL `94420dadff1ab2d78a6749bde63c9ee05feb21b7bbd219ccf9d908a5e8f0015a`. Both source logs pin the actual remote/tree and test SHA-256 `e737716a3402ce8a588eddd9172d1971e872e21c0897c3a3fb77a138d077a753`.

Frontend used Node `v24.21.0`, npm `11.19.0`, Vitest `5.0.1` and Vite `8.3.0`. Its wrapper passed in **163 ms**; captured subprocess TAP is checked inside the wrapper rather than printed as an additional twenty-case Vitest census. TypeScript `--noEmit` and the 592-module Vite build passed. The client scan checked three bundles for eight secret names and Stripe prefixes with no hits; the dependency audit reported zero vulnerabilities. All seven frontend log members and their source/lock hashes were independently verified.

| Complete artifact | ZIP SHA-256 |
| --- | --- |
| [SQLite `11150446425`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36838682200/artifacts/11150446425) | `0b29c4fd970edb23d64c2a7aeca2c0e4dd610a401911f8ca14fc8c82e5270340` |
| [MySQL `11151040075`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36838682200/artifacts/11151040075) | `18b60b0ed1c1d8a0e3171eae0032c7288715a8d69afd7a0d34202ccbeddc8a49` |
| [Frontend `11149874790`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36838682200/artifacts/11149874790) | `fe91689de8416fe3fcf019a9992221f86a296d765749cbb9007f4e8abe5bab23` |

Every archive passed complete CRC and upload/API digest comparisons. These are focused candidate receipts, not a successful overall application run or final Foundation acceptance. The remaining formatting issue is resolved by the exact proof below; the preceding failed overall run remains recorded as failed.

## Exact formatting bridge passed — October 1, 2026

The independently reviewed whitespace-only expansion `f088695846e0fd3d9dc778b4a38dda8c9f6e1ed3` was integrated as permanent source checkpoint `bbdffaf36afbb5dc16128d2683da0b63b32a8a5b`, with the identical tree `bfda00c8cdbbe5bea760e924dd00b4e2ffad5726`. It expands only the inline test `match` expression; all fourteen methods, 21 case identities, assertions, punctuation and strings remain. Every application, frontend and native blob remains byte-identical to the passing source above. The three-path verification/contract/work-package documentation update is separate from this runtime source checkpoint.

[Formatting run `36839559839`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36839559839), job `110295124155`, **passed** on actual remote `5256e89cd6ca0ad6eb59ff103044b53e0f3436d1`, tree `5da12480ab3e802c9a3c41e48dbbcfe9ae2a57a1`. Its reviewed temporary workflow requires the exact preceding `bb1c040` parent and original test hash before comparing PHP tokens. It selects only syntax/token/formatting checks; no PHPUnit, frontend or native suite is replayed or claimed in this run. The temporary workflow is excluded from the permanent feature.

Actual PHP `8.4.26` reported no syntax errors. `token_get_all(..., TOKEN_PARSE)` returned **2,871 identical non-whitespace tokens** for the prior and current files, token-array SHA-256 `6173c456ce497207b612051ad8ef395477e79ecbb05727f621ddd0515480b08c`. The original file SHA-256 is `e737716a3402ce8a588eddd9172d1971e872e21c0897c3a3fb77a138d077a753`; the current file is `609fab3487f23c9d2a3cacdb475b0e68e31ccfa883b7372df13e6295551757dc`. Locked Pint `--test -v` passed all three fixed paths: the sharing service, Track Resource and sharing feature test.

[Artifact `11150986304`](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36839559839/artifacts/11150986304), **10,290 bytes**, passed complete ZIP CRC and upload/API SHA-256 comparison: `d66b68b8ebcd71160cbb5d0dff1ed037dcb064b4ab92676012ee254f03cb20e8`. All seven members were inspected; the retained previous PHP file is byte-identical to the original executed test, and source, syntax, token and Pint logs agree with the exact frozen checkpoint.

The focused boundary therefore includes the actual 21-case/122-assertion passes on both engines, 337 frontend cases plus build/scan/audit, two genuine native sharing journeys and the syntax/token/Pint bridge. It does not establish full Foundation acceptance of a future rebased integrating commit, the permanent four-case related journey execution, real-device clipboard behavior or completion of parent T17/WP-09. No runtime source change is needed to repeat the passed frontend/native checks for this test-only whitespace edit; all required permanent gates remain required for promotion.

## Required focused and integrating evidence

```sh
php vendor/bin/phpunit tests/Feature/ReadTrackSharingTest.php tests/Feature/PublicTrackEmbedTest.php tests/Feature/TrackMetadataTest.php --fail-on-phpunit-warning --display-warnings
php vendor/bin/pint --test app/Domain/Catalog/ReadTrackSharing.php app/Filament/Resources/TrackResource.php tests/Feature/ReadTrackSharingTest.php
npm run test -- tests/frontend/operator-sharing.test.ts
npm run typecheck
npm run build
```

Run the backend cases on **both MySQL and SQLite**. They cover minimal projection/escaping, hostile Host/query isolation, unsafe origins, malformed genuinely published legacy slugs, draft/unknown/stale identity, withdrawal, offers, rights, missing/corrupt media, superseded-media republication, sold exclusive scope, unchanged retained rows, revoked staff/email verification, removed MFA, default/secondary caller-owned transaction refusal and actual mounted-modal refresh.

The integrated native selector chooses exactly `editorial-related-tracks.spec.ts` and `operator-sharing.spec.ts`, with a strict discovery guard in the separately owned wiring commit `1df3427a501ccda2f64d986e998d271a93787434`. The wrapper, preparer, scanner, projects and resource limits remain unchanged; the new sharing case retains the existing 60-second default. The runtime-discovery/startup guard attempt stopped at the missing locked CLI prerequisite; both native journeys remain unexecuted locally. Independent review and actual exact-source native acceptance remain required; an unexecuted spec is not acceptance. No default empty-catalog fixture or scanner bypass is introduced.

That browser case opens the actual Filament action for a prepared currently public track, checks actual track/embed GETs, inert embed text, focus/reflow, native clipboard outcome and native manual text selection, then verifies retained ready-track evidence is unchanged. The no-external-request observation starts after the existing admin shell and server-confirmed table search have loaded, immediately before opening Share, and covers the sharing dialog/copy/manual flow. Page errors are observed throughout login and sharing. Chromium receives ordinary clipboard permissions and must round-trip the real public link and iframe text. WebKit retains its natural successful-write or manual-fallback outcome; no clipboard mock, probe, method replacement/removal or application-state injection is used. Pending, rejected and missing-API state cases are separately covered by the local helper tests. Real-device permissions and iOS/Safari hardware behavior remain device checks.

The existing editorial journey intentionally withdraws the first fixture. Before any fixture URL request, sharing validates both positive safe-integer track IDs, canonical slugs, exact fixed-route hrefs and bounded titles. It does not republish a fixture: it uses the long-title first track when still public, otherwise the unchanged second track, and invokes the strict existing fixture verifier with the observed `published`/`withdrawn` first-track state both before and after the sharing flow. A server-confirmed table-search response and visible Search indicator precede opening the modal, avoiding debounce races. The copy fields exercise long-text wrapping even when the selected second track has a shorter title. This case performs no publication, playback, messaging or external-provider action.

Record the integrating commit/tree, exact executed counts, independent source/privacy review, focused proof and full required CI results before promoting this child. Retain the provider, privacy/retention, support and launch boundaries in the existing decision register; this child supplies no new policy approval.
