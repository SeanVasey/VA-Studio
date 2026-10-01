# Genuine related-track native fixture preparer

Implementation plan, October 1, 2026. Author base `bee2412a7465e8cda8a2a0d88316c2fb6b4e518e`, tree `25b823df349dafc7dbce9211a58ba6013b1ab450`. This child owns only `tests/browser/prepare-related-tracks.php`, its guard regressions and this guide. The separate wrapper, native spec and genuine ClamAV provisioning belong to the parent implementation. No default wrapper, workflow, application service, scanner policy or environment acceptance changes are authorized here.

The separate wrapper first runs the unchanged fresh local bootstrap, then exclusively writes private `related-track-fixture-marker.json` with exactly `{marker, database, origin, operatorId: 1}`. `marker` equals the 64-character lowercase hex `VASEY_BROWSER_RELATED_MARKER`; database and origin equal the existing disposable SQLite path and loopback origin. Every helper invocation requires exact `VASEY_BROWSER_RELATED_STAGE=1`. The wrapper invokes `php tests/browser/prepare-related-tracks.php prepare` once, with genuine packaged `/usr/bin/clamscan`, outside HTTP under a bounded ten-minute stage. It never invokes this preparation from the default browser wrapper.

Preparation requires the exact local CLI directory/database/config/private-root/account guards, an actual synchronous queue, private storage, array mail and disabled provider access. It validates packaged ClamAV/FreshClam ELF binaries, official signature-file identities and actual scanner version/date/detection through ordinary `MalwareScanner`. No test scanner, scanner wrapper, direct ready/approved/verified insertion, database reset or environment switch is permitted.

The helper must be tracked in a clean source checkout. It captures commit/tree identity before any scan, scratch write or preparation domain action, and confirms the unchanged identity before retaining evidence. Guard regressions can include the file without booting the application.

Normal commands create four distinct tracks, two for each fixed project `chromium-desktop` and `webkit-mobile`. The first title has exactly 255 unbroken characters to exercise wrapping. Newly generated synthetic WAV/artwork enter ordinary upload intake and queued synchronous processing. A generated audible tag is hash-pinned in ordinary processing configuration. Rights start pending and are verified through their command. A separately console-provisioned reviewer approves the exact nonbinding license submission, then ordinary license/offer/track publication supplies eligibility.

The exclusive private `related-track-fixtures.json` has exact top-level keys `schemaVersion: 1`, `marker`, `origin`, `database`, `projects`, `evidence`, `evidenceHash`. `projects` uses the two fixed project keys in the order above; each has only ordered `tracks[2]` with `id/title/artist/slug/href`. Their four distinct IDs must match the retained track graphs in order. Bounded initial source/runtime/media/rights/license/offer/audit evidence is hashed with `CanonicalJson`. The helper independently checks all 49 expected ordinary command audit subjects and actors, then retains their IDs and context hashes. Existing `fixtures.json` is neither rewritten nor extended.

`verify <project> <published|withdrawn>` inherits HTTP's unchanged `<directory>/no-clamscan` configuration and performs no scan or executable/signature checks. It checks retained media graphs and actual private output file integrity, license review evidence, rights, offer revisions, initial audits, empty orders/grants/receipts and exact current public/private projections. `VerifiedMedia` validates stored profile fingerprints and source/tag scan evidence without consulting the current tag path or scanner executable. `withdrawn` means only the first saved track is ordinarily unpublished with one exact operator audit; the second remains eligible. Native feature actions remain ordinary UI actions. Verifier stdout is bounded JSON `{verified: true, project, state, evidenceHash, retainedEvidence: true, currentEligibility: true}`; preparation returns `{state: "prepared", projects: 2, tracks: 4, evidenceHash}`.

Each CLI call refuses a mismatched invocation or dependency with status 1 and fixed redacted labels. Preparation does not wrap the entire run in a database transaction: the normal synchronous job must run after its ordinary queue transaction commits. Any failure stops the wrapper before HTTP, retains only this invocation's incomplete manifest if already created, and leaves cleanup to the disposable directory owner. It never retries or repairs a partially prepared database.

Guard acceptance requires hostile directory/config/marker/project/manifest inputs to fail before application boot or mutation. Source syntax/format and these isolated regressions are proportionate local checks. Actual scanner execution, ready-track preparation, native workflow and full integrated-source review remain pending until separately recorded. Local investigation found no ClamAV binaries or signature files; FFmpeg/ffprobe/prlimit are present. Missing genuine prerequisites must fail preparation, never fabricate readiness or silently skip acceptance.

Local source verification used the supplied native PHP 8.4.26 runtime (physical ELF interpreter with its extension configuration), with physically copied Composer dependencies belonging to this checkout:

```text
php -l tests/browser/prepare-related-tracks.php
php -l tests/Unit/RelatedTrackBrowserFixtureGuardTest.php
php vendor/bin/pint --test tests/browser/prepare-related-tracks.php tests/Unit/RelatedTrackBrowserFixtureGuardTest.php
php vendor/bin/phpunit tests/Unit/RelatedTrackBrowserFixtureGuardTest.php --fail-on-phpunit-warning --display-warnings
git diff --check
```

Syntax and formatting passed; the dedicated guard suite passed 6 tests / 93 assertions with no warning or skip. These checks establish refusal/read-only boundaries and exact audit-census validation. They do not establish a genuine processed-media fixture or browser acceptance. MySQL concurrency is unaffected and untested by this CLI-only SQLite prerequisite.
