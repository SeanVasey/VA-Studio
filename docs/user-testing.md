# Try VASEY.AUDIO

Two testing surfaces let Sean explore the existing website before production content, provider and hosting acceptance is complete.

| Surface | Open or start | What can be tested |
| --- | --- | --- |
| Protected storefront preview | [Open sample catalog](https://va-studio-preview-4f5o99xb7-vaseydev.vercel.app/?fixtures); Vercel account access is required | Actual React storefront, approved identity and imagery, empty/sample catalog, search, genre filtering, sorting, track detail, license selection and local cart |
| Disposable real application | Follow [private alpha setup](private-alpha.md), then run `npm run alpha:local` | Real Laravel/Filament sign-in, synthetic private drafts, metadata saves, readiness blockers and site-content administration in a fresh local database |

## Storefront review

1. Use **Sample catalog** at the top. Search for “Midnight”, clear the search, try a genre and change the sort order.
2. Choose a track's license, change its tier and add it to the cart. Confirm the track, selected license and subtotal, then remove it.
3. Use **Track detail** at the top to inspect metadata and the license comparison. Use **Empty catalog** to review the initial real-catalog state.
4. Review the header, hero, studio section, spacing, typography and persistent player at your normal browser size. Record device/browser and the exact action whenever something looks wrong or feels unclear.

The top view links navigate this static composition preview. Its production route links do not supply a Laravel backend; **Artist admin** requires the real application below. Tracks, prices and terms are existing, explicitly labeled development fixtures. No audio files or full license text ship in this sample; unavailable controls stay unavailable. It cannot issue a purchase, license or download. Local cart selections can remain between preview visits.

The hosted build lives in a separate `va-studio-preview` Vercel project in VASEYDEV with account protection on all deployments. It contains the selected frontend build and six byte-exact approved images. It has no production database, private media or provider credentials. Vercel labels the first deployment as production for this isolated project; this does not make it the live VASEY.AUDIO store. No custom domain or sales authority changes.

## Administration review

The [alpha launcher](private-alpha.md) starts the real app on loopback and prints per-run credentials after readiness. Edit a **SYNTHETIC ALPHA** draft, save and reload. Check publication blockers. Explore site-content drafts and private previews. The storefront is initially empty because these drafts are not publication-ready.

On the customer/product integration source, **Collections and albums** also lets you create a private draft from the synthetic tracks, arrange their order, save a new version, review history and use an earlier composition as a new draft version. The original versions stay retained. This authoring flow does not set prices/licenses or enable public collection purchases.

**Sound kit drafts** lets you create a private kit with a provenance reference. On the resumable-kit branch, open **Resumable kit upload**, select a synthetic WAV ZIP, and inspect/resume its exact bytes after an interrupted request. Finish retains a private revision; inspect its processing result separately. The launcher has no accepted malware scanner, so retained input is not a verified or sellable product. Use only disposable synthetic test files.

Stopping the launcher deletes that session's database, uploads, credentials and edits. Keep useful wording separately. This is a practice installation, not a place to begin uploading production originals or customer records. Its data and the static preview fixtures are separate.

## Customer account testing boundary

The composed account-first test journey has real sign-in, private order history and authorized original downloads across fresh sessions. Its synthetic accounts and paid test orders are provisioned by the isolated browser-test fixture; the ordinary alpha launcher does not create a customer login, and the static preview has no account backend. See [customer setup and evidence](verification/customer-account-test-journey.md). Account UI/domain/HTTP tests and browser discovery are recorded separately from native Chromium/WebKit acceptance. The next recovery branch adds [local test enrollment and recovery](customer-test-self-service.md) through private synthetic captures, enabled only in an explicitly configured local/testing installation and its guarded browser fixture. The ordinary alpha launcher does not enable that transport. Production registration/email delivery, guest-order claims and production recovery remain pending.

## October 6 verification checkpoint

The storefront/build composition at local `783c3a9a0e8eb6f1b7cb48947e1236f924881b5d` passed 368 frontend tests, TypeScript, both production and isolated preview builds, both client secret scans, 24 CI-scope guards and 28 focused-routing guards. Four existing operator suites passed 50 tests and 313 assertions using PHP 8.4.26 and SQLite. Independent reviewers approved the preview source and selected 15-file deployment artifact.

The deployed Chrome cloud-browser check exercised sample navigation, search, genre filtering, premium-license selection, the matching cart subtotal, disabled selection review, Escape dialog dismissal and track-detail navigation. Cart selection survived navigation. Original branding and hero imagery rendered. This is desktop interaction evidence, not physical-device, screen-reader or mobile playback acceptance. The local Chromium download returned HTML rather than an archive; local browser execution was unavailable. Native alpha and final combined CI results are recorded against the integrating PR head, rather than inferred from this earlier storefront checkpoint.

Next: finish the selected private hosting and real-media/worker setup, collect feedback on these existing journeys, and implement the remaining task-register children in reviewed batches. T11/T33 remain open; this milestone does not close the full experience, commerce or release packages.
