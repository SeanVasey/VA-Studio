# PR #85 reconciliation — September 30, 2026

Reviewed [PR #85](https://github.com/VASEYDEV/VASEYAUDIO/pull/85), head `487f0b6c206f1fb0392ffe08629169bdbf8f401e`, against main `bfd5448b0080cad0691e7d408aba7a362da635d7` (tree `913dcb6857e3521ea54f708d53b96e60124015f1`). Its original base is `b3930d909880afcd176a6e2cb029c79650072389`.

**Disposition: all useful work is already incorporated.** No original behavior, regression coverage or documentation needs recovery. The PR stays closed and its branch remains available. This conclusion follows individual commit, edit and current-source comparison, including independent row-menu and image-library/release-slot reviews.

## Commit mapping

| Scope | PR #85 commit | Landed commit | Integrating PR |
| --- | --- | --- | --- |
| Row menu | [`d41af2e`](https://github.com/VASEYDEV/VASEYAUDIO/commit/d41af2e0715fae664d63a01d54a92f4f4bd5d65c) | [`06f16a3`](https://github.com/VASEYDEV/VASEYAUDIO/commit/06f16a3513dde9dc4af9b7891846affd6e4a0df9) | [#78](https://github.com/VASEYDEV/VASEYAUDIO/pull/78) |
| Shared scan evidence and revision roots | [`4c54329`](https://github.com/VASEYDEV/VASEYAUDIO/commit/4c54329bac21ec6058c7382a83fe8c5cdab7ffd6) | [`957d350`](https://github.com/VASEYDEV/VASEYAUDIO/commit/957d350d49755b4d0fea579a204b281c30f87489) | [#81](https://github.com/VASEYDEV/VASEYAUDIO/pull/81) |
| Private site-image library | [`66c06c1`](https://github.com/VASEYDEV/VASEYAUDIO/commit/66c06c18c1fe42c34e80e911429f97d7c8c96e23) | [`74aca70`](https://github.com/VASEYDEV/VASEYAUDIO/commit/74aca70e379eab844b7cca62e74295a5b2b6dc34) | [#81](https://github.com/VASEYDEV/VASEYAUDIO/pull/81) |
| D-25 part 1 documentation | [`a71e764`](https://github.com/VASEYDEV/VASEYAUDIO/commit/a71e764dd97a28e40b1d1b0e3c36c4581b027ff9) | [`e75c40f`](https://github.com/VASEYDEV/VASEYAUDIO/commit/e75c40fc681c3a4d713ce88841b47a5accc351bc) | [#81](https://github.com/VASEYDEV/VASEYAUDIO/pull/81) |
| Release image slots and public serving | [`cc84e57`](https://github.com/VASEYDEV/VASEYAUDIO/commit/cc84e57653f606a7235ff8c5daf6ec0f6fa4bc0a) | [`7755040`](https://github.com/VASEYDEV/VASEYAUDIO/commit/7755040302209266ec59fdfc61bd68cd786652cc) | [#82](https://github.com/VASEYDEV/VASEYAUDIO/pull/82) |
| D-25 part 2 documentation | [`487f0b6`](https://github.com/VASEYDEV/VASEYAUDIO/commit/487f0b6c206f1fb0392ffe08629169bdbf8f401e) | [`365aa85`](https://github.com/VASEYDEV/VASEYAUDIO/commit/365aa85a02e24e6c0a2b441b8365f0806eaa773e) | [#82](https://github.com/VASEYDEV/VASEYAUDIO/pull/82) |

GitHub's ancestry comparison includes all six landed commits in reviewed main. Across the six pairs, all 80 per-commit file changes are accounted for: 71 patches are identical, six differ only in surrounding context, and three retain changes already made before the replay.

The three adaptations are:

- `SiteImageManifest::matches()` retains the earlier fix that checks the pinned stored variant set without consulting today's slot variant count. It also retains #85's loaded-variants optimization. Historical images remain usable after profile changes; the current library regression explicitly covers this.
- `SiteImageFixtures` retains the expanded EXIF/polyglot fixture option documentation while adding the same solid-colour fixture and ready-image helpers.
- D-25 retains the matching historical-manifest explanation while adding the same part 2 contract.

Current main also retains the later menu accessibility, stored-path refusal, commit-safety, header validation, public-image serving and browser-race fixes from the integrating and subsequent PRs.

## Verification boundary

[CI 36785960802](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36785960802) passed all 10 jobs on `e83860e771be52b36d316a49fff5d06f00cc18c0`; merge `bfd5448` has the identical source tree. Results: 1,848 MySQL tests / 21,912 assertions with no skips; 1,754 executed SQLite tests / 18,155 assertions plus 94 expected MySQL-only skips; 262 frontend tests; 35 browser tests with one intentional mobile skip. The reconciliation itself is read-only source review and does not claim an additional runtime test run.

This accounts for the six commits saved in #85. It does not inspect any uncommitted files in a separate Claude environment. Sean identified #85 as the remaining unfinished work on September 30.

## File inventory

All 71 changed paths exist in reviewed main. Twenty-seven blobs are identical; 44 contain later changes. The inventory provides file traceability; the commit mapping and reviews above establish preservation of the original work.

| Path | #85 blob | Reviewed main blob | Result |
| --- | --- | --- | --- |
| `CHANGELOG.md` | `2dcb510dc68c` | `c63a35ca78cd` | Later changes |
| `app/Application/SiteBuilder/IngestSiteImage.php` | `473a2bf88c8b` | `acf776ba8a48` | Later changes |
| `app/Application/SiteBuilder/RetrySiteImage.php` | `998906ceaad3` | `998906ceaad3` | Identical |
| `app/Domain/Media/MediaProcessor.php` | `0c6a109deab8` | `aee8856afcf8` | Later changes |
| `app/Domain/Media/PrivateMediaFiles.php` | `4287436357f0` | `fb6e1c18df7f` | Later changes |
| `app/Domain/Media/ScanEngines.php` | `8391c830c369` | `8391c830c369` | Identical |
| `app/Domain/Media/StemsArchive.php` | `967fd8df1a2f` | `6b71050658b7` | Later changes |
| `app/Domain/Media/VerifiedMedia.php` | `72b030e28a04` | `72b030e28a04` | Identical |
| `app/Domain/SiteBuilder/EditorialContent.php` | `26a8e3018058` | `26a8e3018058` | Identical |
| `app/Domain/SiteBuilder/Models/SiteImage.php` | `48f2343fea69` | `48f2343fea69` | Identical |
| `app/Domain/SiteBuilder/Models/SiteImageVariant.php` | `b212fcf6a398` | `b212fcf6a398` | Identical |
| `app/Domain/SiteBuilder/Models/SiteReleaseImage.php` | `ffe930e30eef` | `ffe930e30eef` | Identical |
| `app/Domain/SiteBuilder/SiteContent.php` | `47d65da7a628` | `7936a368e3cd` | Later changes |
| `app/Domain/SiteBuilder/SiteContentSchema.php` | `df4a8fea8892` | `df4a8fea8892` | Identical |
| `app/Domain/SiteBuilder/SiteImageContrast.php` | `db4fdd101b3e` | `db4fdd101b3e` | Identical |
| `app/Domain/SiteBuilder/SiteImageDerivatives.php` | `bb4a6d83faf0` | `90aa8a8f27c3` | Later changes |
| `app/Domain/SiteBuilder/SiteImageFiles.php` | `c192a6bf4d45` | `c192a6bf4d45` | Identical |
| `app/Domain/SiteBuilder/SiteImageInspection.php` | `75e110be6fc2` | `2e494804ba2d` | Later changes |
| `app/Domain/SiteBuilder/SiteImageManifest.php` | `5be2be19418d` | `49dd21b41118` | Later changes |
| `app/Domain/SiteBuilder/SiteImagePresentation.php` | `42e082aa08ad` | `42e082aa08ad` | Identical |
| `app/Domain/SiteBuilder/SiteImageProblem.php` | `8bc51923c159` | `5867799660a9` | Later changes |
| `app/Domain/SiteBuilder/SiteImageProcessor.php` | `272a73d66a59` | `d3549d0dfec2` | Later changes |
| `app/Domain/SiteBuilder/SiteImageReferences.php` | `3a27eebffe37` | `cb6385cc603e` | Later changes |
| `app/Domain/SiteBuilder/SiteImageSlot.php` | `0a40fb542e4a` | `a1b12bb2c3e1` | Later changes |
| `app/Filament/Resources/SiteImageResource.php` | `c9bca78799bd` | `5c4ceb01d699` | Later changes |
| `app/Filament/Resources/SiteImageResource/Pages/ListSiteImages.php` | `e2fa4d4eca2a` | `e2fa4d4eca2a` | Identical |
| `app/Filament/Resources/SiteReleaseResource.php` | `d1e3e3b8c7fa` | `cc702d72c223` | Later changes |
| `app/Http/Controllers/EditorialController.php` | `f0759ab4ee8e` | `f0759ab4ee8e` | Identical |
| `app/Http/Controllers/PublicSiteImageController.php` | `2f669fcc6910` | `8d7b94083937` | Later changes |
| `app/Http/Controllers/SiteImagePreviewController.php` | `944160dbd448` | `944160dbd448` | Identical |
| `app/Http/Controllers/SiteReleasePreviewController.php` | `a691754f5597` | `a691754f5597` | Identical |
| `app/Http/Controllers/StorefrontController.php` | `55fa5cbf9872` | `55fa5cbf9872` | Identical |
| `app/Http/Middleware/SitePreviewPrivacy.php` | `3e47f2e6e83c` | `3e47f2e6e83c` | Identical |
| `app/Jobs/ProcessSiteImage.php` | `168c5e923a32` | `168c5e923a32` | Identical |
| `app/Providers/Filament/AdminPanelProvider.php` | `f2a6a58e3908` | `9f74196a6887` | Later changes |
| `app/Support/Diagnostics/InstallationReport.php` | `83a1610fd6f9` | `1b20dc8b7c9c` | Later changes |
| `app/Support/StorefrontMetadata.php` | `a87ee646ea4e` | `a87ee646ea4e` | Identical |
| `database/migrations/2026_09_30_000027_site_images.php` | `346e5604cd6d` | `72cbbeca7b1c` | Later changes |
| `database/migrations/2026_09_30_000028_site_release_images.php` | `5d9e4cd5cbc8` | `9b5c7e45912d` | Later changes |
| `docs/architecture/D-24-scheduled-site-publication.md` | `30bcab9249f7` | `b19cc9909058` | Later changes |
| `docs/architecture/D-25-editable-site-images.md` | `67fd4d501519` | `dedb2df9e40d` | Later changes |
| `docs/architecture/README.md` | `d1b3ed35fd33` | `a533fb785468` | Later changes |
| `docs/architecture/decision-register.md` | `a1abef6ebdf9` | `93ca2a43900c` | Later changes |
| `docs/editorial-content.md` | `1509a1f1010f` | `1509a1f1010f` | Identical |
| `docs/media-processing.md` | `fe1ae69dd474` | `e86c3f183cdd` | Later changes |
| `docs/operator-setup-and-verification.md` | `2b86d2126713` | `60da64a55ea6` | Later changes |
| `docs/site-content-releases.md` | `a8d3868af6bc` | `d38622a629a7` | Later changes |
| `resources/js/Pages/Storefront.tsx` | `bc68130a7b72` | `bc68130a7b72` | Identical |
| `resources/js/components/MetadataHead.tsx` | `404d925263b3` | `404d925263b3` | Identical |
| `resources/js/components/SiteImagery.tsx` | `6a862f10a8f5` | `6b061874dcad` | Later changes |
| `resources/js/lib/catalog.ts` | `60b50d773ba0` | `60b50d773ba0` | Identical |
| `resources/js/lib/site-content.ts` | `5aa5ccd48c89` | `5aa5ccd48c89` | Identical |
| `resources/views/app.blade.php` | `05e64c2d6a41` | `05e64c2d6a41` | Identical |
| `routes/web.php` | `5f920c558e68` | `1c45dfbcaa2a` | Later changes |
| `tests/Feature/PrivateMediaRevisionRootsTest.php` | `f73cfb91962e` | `f73cfb91962e` | Identical |
| `tests/Feature/SiteImageConcurrencyTest.php` | `0383e4a3638e` | `b87518742b83` | Later changes |
| `tests/Feature/SiteImageEditorTest.php` | `cfa1ac697fcc` | `94e16a193512` | Later changes |
| `tests/Feature/SiteImageHttpTest.php` | `3969effec9e5` | `940d6d4c1779` | Later changes |
| `tests/Feature/SiteImageLibraryTest.php` | `df0f616fd2f7` | `a348517867ac` | Later changes |
| `tests/Feature/SiteImagePublicTest.php` | `bb2d4992c990` | `7f699fb921a2` | Later changes |
| `tests/Feature/SiteImageReleaseDamageTest.php` | `3d1ae9ae9327` | `e559fa71611a` | Later changes |
| `tests/Feature/SiteImageReleaseTest.php` | `242954d09857` | `aedb08395ab6` | Later changes |
| `tests/Support/SiteContentRace.php` | `c3b3f531be63` | `c3b3f531be63` | Identical |
| `tests/Support/SiteImageFixtures.php` | `04100f73e75d` | `5bb4b13b30bf` | Later changes |
| `tests/Support/site-content-race-worker.php` | `ffab51e0d2ec` | `252f574d783b` | Later changes |
| `tests/browser/editorial-content.spec.ts` | `df9f9825bf12` | `efb4090a010e` | Later changes |
| `tests/browser/site-content.spec.ts` | `f7d6ab79221c` | `f9cf0e422659` | Later changes |
| `tests/browser/site-images.spec.ts` | `818293b8eaca` | `7b4e78f40ba2` | Later changes |
| `tests/browser/site-release-row.ts` | `31b1c1df180e` | `43cfe5e6e885` | Later changes |
| `tests/browser/site-schedule.spec.ts` | `d1e9ac8a870e` | `24292b1f392d` | Later changes |
| `tests/frontend/site-images.test.tsx` | `fca4bee8a65b` | `4763e0396a51` | Later changes |
