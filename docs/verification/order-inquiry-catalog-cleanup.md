# Order-inquiry native fixture catalog restoration

October 6, 2026. This focused WP-01/WP-09 browser-fixture correction starts from the reviewed license/download composition `a108058`. It removes a specific ordinary-browser blocker while retaining the existing public/draft privacy assertions and native media prerequisites.

## Observed failure and cause

PR #13 Foundation CI run `37494698386`, attempt 1, tested head `1de6b314bd2c96323cd491733240a54ddf09bd42`, merge checkout `2bf5ca98993ad8b2f18a74e87bd982ffa837d021`, tree `b2a83eb75508a297e37b82abbeec8b2637825091`. Ordinary Chromium finished with 59 passed / 3 failed; mobile WebKit finished with 58 passed / 1 skipped / 3 failed. Each failed only the empty `/api/catalog` assertion in `operator.spec.ts:49`, `site-content.spec.ts:94` or `site-schedule.spec.ts:112`. The returned row was track 14, **Synthetic quote recording**, with its `quote-fixture-…` slug and offer revision 45. There was no timeout or browser crash in these failures.

The preceding native inquiry-conversation journey passed on both engines. Its guarded `conversation-prepare` creates a genuinely scanned, published `QuoteFixtures` recording so the browser can prepare an order through real HTTP and test an order-linked private inquiry. Its mandatory `conversation-restore` restored the previous site release but left that recording published. The bootstrap's retained paid-customer recordings already request `hideCatalog: true`; removing their coverage or relaxing the three empty-catalog assertions would leave this actual fixture leak unresolved.

The retained full job logs are `ci-run-37494698386-chromium.log` (SHA-256 `d716c5f59380ac37eef089353811dbcfc0a2c97c25872fcb7ff913fac415f219`) and `ci-run-37494698386-webkit.log` (`c627abddfde0e797bf79aac97bd2863181f06dc3457cf76eca20af060917e7a3`). Their original artifact metadata records ordinary ZIP sizes of 126,317,331 and 45,211,980 bytes, exceeding the authorized 32 MiB downloader ceiling; those two raw ZIPs were not available locally. The available related-browser ZIP was inspected, including its embedded original report: 2 expected / 0 unexpected / 0 skipped / 0 flaky and no runner errors. That separate ready-catalog stage does not prove ordinary-suite restoration.

## Correction and retained boundaries

Only `tests/browser/prepare-contact-inquiry.php` changes fixture behavior. Its private `orderSupport` manifest now retains the prepared track's publication version. Restoration locks and verifies that exact track ID, slug, reserved published URL, state and captured publication version inside its database transaction. The existing authorized operator withdraws that one recording through `PublishTrack::unpublish`, preserving its permanent URL and retaining one normal withdrawal audit. The same transaction restores the previous site release. A changed identity/version or withdrawal-audit failure refuses restoration without committing database effects.

Before and after withdrawal, hashes verify unchanged retained commerce evidence and the inquiry graph, including inquiry audit rows. Purchased snapshots, contracts, pending entitlements, inquiry links and private files remain retained. An already restored fixture validates its withdrawn state and incremented version and does no further writes. Other public recordings remain published. The helper retains its existing loopback/CLI/disposable SQLite identity, current operator/MFA admission, fixed redacted failure output and temporary metadata handling.

No application policy, migration, native scanner requirement, ordinary browser assertion, retry, timeout or CI workflow changes in this child. Shared selector/status registration belongs to the integrating owner.

## Executed verification

`InquiryBrowserCatalogCleanupTest` invokes the actual guarded restoration helper in real PHP subprocesses over isolated freshly migrated SQLite installations. Its test-only worker uses the existing explicit synthetic scanner/provider/renderer fixtures to retain a paid order, original contract, asset and two private inquiries, one linked to that order. Public catalog projection uses the synthetic scanner only under `testing`; the restoration helper itself runs under `local`. This is domain/helper feedback, not genuine scanner, HTTP browser, MySQL concurrency or physical-device acceptance.

| Check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit tests/Feature/InquiryBrowserCatalogCleanupTest.php --log-junit=… --fail-on-phpunit-warning --display-warnings` | Final source: 6 cases / 106 assertions passed in 23.916 seconds, with no skipped cases or warnings. Covers empty catalog restoration, preservation of an unrelated published recording, mutation-free repeated restoration, three changed identity/version refusals, private-history/file preservation and withdrawal-audit rollback. |
| Same two positive restoration cases against original `a108058` helper bytes in a separate worktree | Expected red: 2 failed / 26 assertions, 7.897 seconds. The original helper still returned the fixture recording after restoration, including when another public recording was present. Only the new regression files were copied; original helper bytes were preserved. |
| Scoped Pint, syntax for all three PHP files and `git diff --check` | Passed. |

The final original JUnit receipt is `inquiry-catalog-cleanup-final.xml`, SHA-256 `bb58d5578a215026e60ee4122f916d70f462b40ac230aeb30c6afa459e16e5c5`; expected-red receipt is `inquiry-catalog-cleanup-original-red.xml`, `0b27b2ae7cf37dc2f2459cc4b9c94309271f5a96a673398ad41b58e12e0536b7`. An earlier worker attempt failed because `OrderLine` holds an immutable offer-revision reference rather than `track_id`; the corrected worker reconstructs that retained revision. This harness repair made no application or native fixture exception.

Local `/usr/bin/clamscan` and installed browser engines are absent. Both unchanged ordinary Chromium/mobile-WebKit journeys, the separate genuine related-track stage and all applicable complete source-bound hosted gates remain required for the integrating candidate. Their prior failed run is diagnostic evidence, not fresh acceptance of this correction.
