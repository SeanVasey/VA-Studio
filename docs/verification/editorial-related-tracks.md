# T16-RELATED-TRACKS-01 — manual editorial track links

Status: source implemented and independently approved in an isolated candidate after the plan was recorded October 1, 2026, before application mutation. Author base is approved player source `ffe6f8f6be6be28030b3a907b6671f45dc963a47`, tree `0af76e6bd0716b6ee114e8ee293cd081ebbede9c`; the relevant CMS/catalog/image source is identical to inspected `04e4743301a47ca720d849e01bdeba9203708a23`, tree `6fbd125d143afead56bbb8dced0a30a1eef25595`. T16 and WP-09 remain open. Actual MySQL/native acceptance remains pending.

## Authority and bounded contract

Sean authorized continued development of his personal first-party store. D-07, D-23, D-25 and WP-09 supply the existing boundaries. The independently reviewed preparation is local `80dee9252f31d19906bfa21c6d788326b3641e05`, tree `ec338858fe0ceb9767feec22e8accc911d668a41`, inspected against the same application source. This child supplies manual blog/video detail associations only. It introduces no recommendations, independent sellers, production content, purchasing behavior or new provider/license policy.

Strict schema 4 adds required ordered `related_track_ids` lists: 0–6 distinct positive native integers per blog/video entry, with reuse across entries allowed. The existing section/entry bounds limit a release to 360 reference occurrences. Schema 4 inherits the required image shape, using `NO_IMAGES` when empty. Versions 1–3 retain exact validators, bytes, hashes and indexes. No URL, title snapshot, media, price, license or provider payload is stored in an association.

Draft creation remains inside `SiteContent::create` with fresh persisted staff authority and the existing immutable release/hash/image-index/audit transaction. Identity checks require an existing retained track with a reserved published URL, rather than current availability. Catalog withdrawal must preserve saved IDs/order and must not make a CMS release corrupt or prevent whole-site restoration. There is no mutable relation table or new actor-lock contract.

The authoring form uses reorderable single-choice rows, canonical native form digit-string validation before integer conversion and explicit duplicate/type/bound rejection. The picker searches current eligible public tracks. Copied withdrawn identities remain visible/removable. Only an enabled entry with nonempty associations selects schema 4; otherwise empty new keys are removed and existing version 2/3 image selection remains intact. Copying or clearing associations must preserve image references.

## Owned implementation and acceptance

| Area | Owned paths and intended change | Required evidence |
| --- | --- | --- |
| Schema/write identity | `SiteContentSchema`, new `SiteRelatedTracks`, `SiteContent` | Strict types/count/order/unknown keys; legacy hashes; retained URL identity; withdrawn copies; fresh authority and atomic audit failure |
| Catalog/editorial projection | `PublicCatalog`, `EditorialContent`, private preview controller | Reuse exact public query/readiness/selection eligibility; saved order; selected-entry-only narrow fields; current withdrawal/rights/media/inventory omission; server-side private href absence |
| Authoring | `SiteReleaseResource` | Ordinary create/copy/save; ordered single-choice controls; malformed form IDs rejected; unavailable retained labels/removal; clearing/downgrade without image loss; existing panel MFA |
| Image compatibility | `SiteImageReferences`, `SiteContent`, `InstallationReport`; additive `2026_10_01_000032_editorial_related_tracks_images.php` | Exactly versions 3/4; manifest/index/file/doctor/scheduler checks; protective successor installed before old guard removal; raw insert/immutability/retention checks; down refuses any version-4 release |
| Presentation | `resources/js/lib/site-content.ts`, `resources/js/Pages/Editorial.tsx` | Approved theme composition; current title/artist; ordinary keyboard-accessible server track links; private nonlinks; no automatic media/provider request |
| Tests/evidence | New focused schema/editor/HTTP/migration tests, existing CMS race harness extended to v4, frontend coverage and this guide | SQLite client/domain regressions; exact independent source review; later actual MySQL contention and original native browser workflow |

Public projection reuses `PublicCatalog::query()` and `eligible()`, including full `PublicationReadiness` and current `SelectionInventory::available` offers. It must not use the full unordered `selections()` media/price projection. Only the selected detail entry resolves IDs, in saved order, to current title/artist and server-derived track href. Unavailable targets, raw saved IDs, unrelated references, prices, media URLs and private metadata never enter public props. Collections/chrome do not receive related lists. A fresh read reflects ordinary withdrawal; this is advisory browse state, not inventory reservation.

Private previews retain the exact immutable CMS text and association order. Under the existing private home-preview contract, current eligible public summaries may appear, with href omitted server-side and rendered as nonclickable text. Track destinations must never pass through `siteContentHref`, which pins CMS paths to a private release. Existing no-store/noindex/privacy and disabled-action boundaries remain intact.

The deployed image-index insert trigger admits only schema 3. Its new successor permits exactly 3/4 while preserving bytewise MySQL slot/status comparisons, ready same-slot images, pre-publication/pre-schedule restrictions and all retention/immutability guards. Create protective replacement before removing the old guard. Down first refuses any retained v4 row, including image-free releases. Older-code rollback first activates an intact v1–v3 release using new code; never delete/downgrade v4 evidence.

## Verification plan and explicit dependency

Run focused schema, identity, editor, HTTP and migration cases plus existing editorial/image/publication regressions on isolated SQLite; run changed PHP formatting/syntax, frontend regressions and production typecheck/build. Extend real publication contention cases using unchanged `SiteContentRace`: separate actors/processes/connections, observed publication `PRIMARY` lock wait, both publish-versus-rollback orders and exact winner release/hash/revision/history/audit actor/subject with unchanged loser evidence. Actual MySQL execution remains a later acceptance gate; SQLite proves no locking behavior.

A genuine native ready-track fixture is presently missing. The browser wrapper/bootstrap require `APP_ENV=local`; `MediaFixtures`, `TestOnlyMediaScanner` and accepted `test-only` scan evidence require `testing`. Preparing rows under a separate testing CLI does not make them eligible under local HTTP `VerifiedMedia`. Existing intercepted storefront fixtures and synthetic embed rendering do not prove the required normal-domain publication/withdrawal workflow. Do not relax scan acceptance, forge readiness, reset a nonempty database or substitute interception. Continue useful implementation while preparing a bounded normal-scanner execution solution; record it separately before native acceptance.

Once that dependency is resolved, a guarded CLI fixture and ordinary Chromium/WebKit spec must exercise normal ready media/rights/offer/publication, author/copy/private preview/publication, catalog withdrawal, fresh public omission and prior-release restoration. Preserve wrapper origin/config/marker/accounts/private-file guards, keyboard/focus/viewport evidence and exact persisted/public/private responses. No provider access or owner-policy assumption is required for this manual hyperlink feature.

## Implementation review and CI integration dependencies

The narrow catalog child was frozen as `b543ecaadff448a883678b39016d40a7d0bb7713`, tree `e8ee41d32dd0e9d349b53ad0aaeec8f8de05244b`, and the additive image guard child as `bceafc388aef4714141a4972242ce80c7198bd8e`, tree `97426ce8a904a4d834ca841aca5a300bc0ad8d1a`. Both were reviewed from their exact sources and integrated only into this isolated author branch. The catalog child passed 32 focused SQLite cases, including genuine synthetic exclusive finalization; the guard child passed 10 raw SQLite cases. Their independent rechecks passed 32/142 and 10/120 cases/assertions respectively. These counts are focused execution, not whole-project acceptance.

Integrated independent review identified two source issues before freeze: valid unbroken current track titles needed the existing editorial wrapping rules, and Filament's default `OptionStateCast` silently normalized booleans/integral floats before domain validation. The related select now uses a lossless `PreserveTrackIdState`. Five adversarial tests submit raw values and `callMountedAction` in one ordinary Livewire request; an intermediate snapshot would serialize an integral float as an integer and hide the hostile type. With the default cast restored temporarily, the same five tests produced exactly two failures (boolean and integral float accepted), then restored the corrected source byte-for-byte. The corrected five tests all passed. Leading-zero, exponent and overflow cases remain rejected.

Two additional container/null cases require each selection row to fail validation without crashing Filament's fallback label rendering. The cast maps malformed containers to no selection, so its required field rejects them; scalar boolean/float types remain intact until rejection. Native integer state is encoded to exact decimal strings before JavaScript sees it, preventing browser rounding above `2^53 − 1`; a `PHP_INT_MAX` JSON state/round-trip regression covers the boundary. No invalid value is normalized into a valid identifier.

The prepared MySQL method `SiteContentConcurrencyTest::test_v4_publish_and_rollback_preserve_exact_winner_and_retained_loser_in_both_lock_orders` has two explicit datasets, `publish first` and `rollback first`. It uses the unchanged independent worker/connection/process and observed `PRIMARY` lock-wait harness. SQLite deliberately executes neither locking scenario. If the pending T01 receipts child is later rebased onto this feature, its reviewed SQLite skip policy must explicitly include this exact new method and derive both datasets; do not silently expand permissions from path or class membership. Reconcile the separately approved bulk-tag methods at that same explicit integration boundary. Committed timing files are historical compatibility weights and must not be rewritten as new measurements without actual complete run evidence.

The native wrapper deliberately sets `MEDIA_CLAMSCAN` to an unavailable path, and existing image tests rely on that failure state. A supported optional normal-scanner CLI preparation can run before HTTP using actual ClamAV/current signatures and isolated synthetic inputs; retained real `clamav` evidence is accepted by local HTTP even when its processing tool remains unavailable. No such tool or signature database is available on this author host (`clamscan`/`freshclam` absent; ffmpeg/ffprobe/prlimit present). Native preparation must therefore remain separate and fail closed until the real tool prerequisite is established. No runtime environment, accepted scanner engine, fabricated readiness row, provider response or browser interception is changed by this candidate.

Do not add ready tracks to the shared default browser database: `operator.spec.ts`, `site-content.spec.ts` and `site-schedule.spec.ts` explicitly require an empty public catalog. The next prerequisite should supply a separate guarded wrapper stage enforcing only the related-track spec, with a fresh directory/database, distinct project IDs/slugs and its own exclusive manifest. Keep existing `fixtures.json` exact (the inquiry helper validates its project-key contract), and keep the default full browser set unchanged. Before HTTP starts, only this stage runs the real scanner preparer with its own bounded ten-minute budget; it never enlarges existing case timeouts, resets an installation or relaxes empty-catalog assertions. Preparation must fail closed on missing/stale signatures, scanner update failure, abnormal output, EICAR not producing the actual `scan_not_clean` result, processing failure or incomplete ordinary rights/license/offer/track publication evidence. Use the current 48-hour signature age and one-hour future-skew checks.

## Frozen source verification

Application/test source was frozen as `c5b368f0ea799ecb09ecb74182f5bd5348b698df`, tree `d1d5f13b0504b976ae23626334688d97f4141469`, and independently approved from a separate clean detached checkout. Any successor that only records these results must preserve every application/test blob from that freeze. The cumulative feature diff from the approved player base owns 25 paths, including this guide and the WP-09 child note; it does not modify workflows, dependency locks, earlier migrations, browser fixtures or scanner policy.

Author verification used PHP 8.4.26 with committed Composer dependencies in a fresh detached checkout of that exact source, without frontend dependencies or generated `public/build` assets. The complete selected SQLite regression command was:

```sh
php vendor/bin/phpunit --log-junit /tmp/vasey-related-clean-sqlite.xml \
  tests/Feature/SiteRelatedTrackContentTest.php \
  tests/Feature/SiteRelatedTrackHttpTest.php \
  tests/Feature/SiteRelatedTrackEditorTest.php \
  tests/Feature/SiteRelatedTrackDamageTest.php \
  tests/Feature/SiteRelatedTrackImageMigrationTest.php \
  tests/Feature/PublicCatalogRelatedLinksTest.php \
  tests/Feature/SiteEditorialContentTest.php \
  tests/Feature/SiteEditorialHttpTest.php \
  tests/Feature/SiteContentTest.php \
  tests/Feature/SiteContentHttpTest.php \
  tests/Feature/SiteContentUnavailableHttpTest.php \
  tests/Feature/SiteContentDamagedPublicationTest.php \
  tests/Feature/SiteContentConcurrencyTest.php \
  tests/Feature/SiteImageEditorTest.php \
  tests/Feature/SiteImageReleaseTest.php \
  tests/Feature/SiteImageReleaseDamageTest.php \
  tests/Feature/SiteImageMigrationTest.php \
  tests/Feature/SiteImagePublicTest.php \
  tests/Feature/SiteImageHttpTest.php \
  tests/Feature/CatalogPaginationTest.php \
  tests/Feature/PublicTrackEmbedTest.php
```

Result: **216 cases, 212 passed, 4 explicit MySQL-only skips, 4,621 assertions**, 157.392 seconds; no failures/errors/warnings. JUnit SHA-256: `9e187e1da1f972151fa4e358e3948e12aa3f047be9e7e140ef4e9dd262fa0c78`. The four skips are exactly the two existing `SiteContentConcurrencyTest` methods and the two new named v4 lock-order datasets. No raw image-guard test is skipped on SQLite.

Earlier executions in the author checkout encountered a regenerated local Vite manifest. The unchanged `CatalogPaginationTest` omits `X-Inertia-Version`; with a manifest present, the ordinary Inertia middleware correctly returns a 409 version-refresh response. The fresh asset-free checkout passed that original assertion and all selected cases without changing middleware, test headers or tracked source. The process that regenerated the ignored local asset was not established; do not attribute that artifact to application behavior or use those earlier failed runs as acceptance evidence.

With Node 24.19.0 and committed frontend dependencies, `npm test` passed **336 cases across 21 files** and `npm run build` passed TypeScript checking and the Vite production build. Changed PHP files passed `php vendor/bin/pint --test` and PHP syntax checking; `git diff --check` passed. Browser emulation in these frontend tests proves component assertions only, not native playback, viewport or browser workflow behavior.

Independent verification of the same frozen source passed **93 PHP cases plus the same four explicit MySQL skips, 478 assertions** in a separate asset-free checkout; **336 frontend cases across 21 files**, `npm run typecheck`, changed PHP `pint --test` and the full feature `git diff --check` passed. The reviewer found no unresolved source, schema, privacy or migration finding after the recorded casting, wrapping and integer-state corrections. Actual MySQL trigger behavior/publication contention, real-scanner fixture execution, and the ordinary Chromium/WebKit related-track author/publish/withdraw/restore workflow remain required and unproven. These results do not close T16 or imply deployment.

The independent PHP command used a fresh ephemeral application key supplied only through subprocess environment, asserted `public/build` absent both before and after, and ran:

```sh
php vendor/bin/phpunit \
  tests/Feature/PublicCatalogRelatedLinksTest.php \
  tests/Feature/SiteRelatedTrackContentTest.php \
  tests/Feature/SiteRelatedTrackEditorTest.php \
  tests/Feature/SiteRelatedTrackHttpTest.php \
  tests/Feature/SiteRelatedTrackDamageTest.php \
  tests/Feature/SiteRelatedTrackImageMigrationTest.php \
  tests/Feature/SiteContentConcurrencyTest.php \
  --do-not-cache-result --fail-on-phpunit-warning --display-warnings
```

That execution took 57.522 seconds. The reviewer ran `npm test -- --reporter=dot` and `npm run typecheck`, not a production build. Its PHP formatting command selected every `.php` path in `git diff --name-only ffe6f8f6be6be28030b3a907b6671f45dc963a47 HEAD`, then passed those exact paths to `php vendor/bin/pint --test`; `git diff --check ffe6f8f6be6be28030b3a907b6671f45dc963a47 HEAD` and final clean source identity also passed.

## Recovered native integration — October 1, 2026

The separately reviewed preparer is now carried into the recovered source with [a fixed dedicated native stage](related-track-native-stage.md). It uses genuine packaged ClamAV/current official signatures and normal media/rights/license/offer/publication commands in its own disposable installation. The Foundation aggregate requires that separate browser job, while the unchanged default browser fixtures retain an empty public catalog. The earlier missing-prerequisite notes above describe the frozen author checkpoint; no scanner/native/MySQL acceptance is implied by this later implementation. Exact final integrated gates remain pending.
