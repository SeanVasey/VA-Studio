# Customer and operator recovery continuation

October 6, 2026 UTC. This is the next coherent update to [PR #13](https://github.com/SeanVasey/VA-Studio/pull/13), preserving the reviewed ancestry of PRs #7–#12 against accepted main `387fdeb95e72e96e5cd17c0b54f38c849fc37adb`. It advances WP-02/WP-08/WP-09 and T12/T24 without closing their parent criteria.

## Resulting behavior

The license editor captures an exact draft before editing and consumes that capture on submission. Current staff authority/MFA, lifecycle and retained audit identity are checked by domain services. Stale or uncertain results retain entered fields and require explicit reopening; nested policy-row edits remain intact. Published terms and purchased evidence are unchanged. The [license record](reviewed-license-draft.md) retains component/native evidence and remaining limits.

Customer download metadata and authorization fetches, including body reads, have an effective 20-second deadline even if abort or body cancellation never settles. Late generations cannot submit an attachment. Uncertain retries and read-only refresh retain the exact request key; a new request is explicit. Page exit/unmount releases owned work. Native attachment POST streaming and server entitlement/lifetime rules are unchanged. See [download recovery](download-request-recovery.md).

The order-inquiry fixture withdraws only its exact captured synthetic recording through the audited publication command. Identity/version checks, site-release restoration and withdrawal are atomic; private commerce/inquiry history and unrelated recordings are retained. Repeated restoration performs no new withdrawal. The [fixture correction](order-inquiry-catalog-cleanup.md) records the original failing catalog assertions and six actual-helper regressions. The existing guest-claim focus assertion now waits for the React effect that moves focus; its criterion is unchanged.

## Source and independent review

| Local source | Scope and tree |
| --- | --- |
| `b83e67978c919ffb2ee8273b661730323bdab872` | Reviewed captured-draft composition; tree `c36a8c7492684eafbbe92a17a963f09efd8946ac` |
| `8a4f3a6d998432e1d7ba106e238e7cc6efd7b63b` | Reviewed bounded download recovery; tree `6fb78cad607d3b940dd12b0519eec6eb1a9378bb` |
| `14748b61f20bc81bdf4128b2a13890cec847e971` | License/download composition and focus synchronization; tree `8ac38a6a9357d1ceb95e5f8fdf52f6de4d7ae3bc` |
| `d03403338e37e5b0c7e9bed3fe255e0ea691c53b` | Actual inquiry-fixture cleanup and subprocess regressions; tree `d8ec058212be9905b5c09f2e7fd79cd2a9d976a1` |
| `fde660f72b3d38ce53a39c7fb8fb4809914693ad` | Combined executable correction; tree `54a6ddde247c9dfaa8610b8cc54ab83f2e5d608b` |
| `9aafc5012d85c121e4b086faa9e6c291d3523496` | Final executable/test/selector source; tree `ed3cceb9d59e0b718203d4fc40ff7afe56d72fdd` |

`/root/composition_review` independently approved the actual committed license/download/focus bytes at `14748b6` with no blocking findings. The lead inspected the separate cleanup at `d034033` and reran its regressions after composition. `/root/acceptance_evidence` independently approved the exact two-path, three-insertion selector delta at `9aafc50` and verified complete discovery/partition preservation. Those reviews have distinct scopes; none substitutes for fresh hosted execution. Subsequent integration-record edits change documentation only. GitHub publication must preserve these exact trees and ordered parents; the PR records the resulting remote head and test merge.

## Executed local feedback

The recovered locked dependencies were used. PHP uses the recovered runtime with a disposable 32-byte fixture encryption key and SQLite `:memory:` from `phpunit.xml`; the helper regressions use isolated migrated SQLite directories. No production credentials or database were used.

| Check | Actual result and source scope |
| --- | --- |
| `npm test` | 768 passed across 34 files after focus synchronization at `14748b6`; those frontend bytes are unchanged in `9aafc50`. |
| `php vendor/bin/phpunit` with `ReviewedLicenseDraftTest`, `LicenseDraftAuthoringActionTest`, `TypedLicenseTest`, `ScopedLicenseTest`, `EconomicLicenseTest` | 73 cases / 958 assertions; zero failures/errors/skips on composed `14748b6`. Those relevant application/test bytes are unchanged in the final composition. This is SQLite feedback, not native races. |
| `php vendor/bin/phpunit tests/Feature/InquiryBrowserCatalogCleanupTest.php --log-junit=… --fail-on-phpunit-warning --display-warnings` | Composed rerun: 6 cases / 106 assertions; zero failures/errors/skips. The author's separate final run also passed 6 / 106. |
| `npm run build`; `npm run build:preview` | Both passed, including typechecking. Production retains the existing chunk-size warning. |
| `python3 scripts/ci/scan-client-bundle.py`; preview scan targeting `dist/design-preview` | Passed; production scan inspected six files with no prohibited hits. |
| Scoped Pint, PHP syntax and `git diff --check` | Passed for the changed PHP and composed source. |
| Focused-selector / receipt / partition / scope safeguard scripts | 42 / 34 / 36 / 27 checks passed respectively. |

Original failures remain retained: the pre-correction full frontend run passed 767 and failed the same focus assertion; the unchanged original inquiry helper failed both new positive restoration cases. An initial PHP command used an invalid 35-byte fixture key and failed setup; the corrected disposable key produced the 73-case result above. An initial preview scan used the wrong output directory and refused admission; the correct preview target passed. Initial browser listing without the required fixture manifest failed import admission; the corrected discovery below executed no journeys.

## Discovery and final acceptance

Independent actual PHPUnit `--list-tests-xml` on both database configurations and all eight MySQL/two SQLite partitions at frozen `9aafc50` found 4,000 cases across 252 files. Every prior 3,994 identity was preserved exactly and the six cleanup cases added. Every engine's whole-file partitions cover the census exactly once. The reviewed captured-license source adds one native-only race method expanding to 12 cases; all 407 preceding native identities remain. The final SQLite policy therefore contains 419 identities across 132 methods, leaving 3,581 expected SQLite executions. Committed timing inputs are unchanged; 29 untimed files still use fallback estimates.

Actual `playwright test --list --reporter=json` with a discovery-only empty fixture manifest found 126 identities across 36 files, 63 per engine, preserving the preceding license-draft inventory. These listings and partition proofs execute no PHP/browser test bodies. Genuine ClamAV and installed native browser engines are unavailable locally. The hosted native suites and independent-process MySQL races remain required. Lockfiles, workflows, timings, timeouts and retries are unchanged by this continuation. The reviewed licensing registration adds its three classes and exact native method; the subsequent cleanup registration adds that class once to customer coverage and protects it from native exclusion. Final composition preserves the reviewed license policy without further exclusions.

Preceding [Foundation run 37494698386](https://github.com/SeanVasey/VA-Studio/actions/runs/37494698386), attempt 1, belongs to head `1de6b314bd2c96323cd491733240a54ddf09bd42`, tree `b2a83eb75508a297e37b82abbeec8b2637825091`, test merge `2bf5ca98993ad8b2f18a74e87bd982ffa837d021`. Its ordered parents were accepted main then that head. Independent terminal receipt verification found:

- MySQL: 3,936 executed / 71,079 assertions, zero errors/failures/skips.
- SQLite: 3,936 reported, 3,529 executed / 39,687 assertions, exactly 407 reviewed native-only skips, zero errors/failures. Every skipped identity passed on genuine MySQL.
- Related native Chromium/WebKit: both actual journeys passed with no retries/skips/flakes; original media receipts and the two reviewed pregesture WebKit status-zero notes are retained.
- Quality and frontend passed. Ordinary Chromium was 59 passed / 3 failed; WebKit was 58 passed / 3 failed / one unchanged skip. All six failures were the later empty-catalog assertions. The backend aggregate correctly refused acceptance.

That complete failed run is diagnostic evidence for its original source. The changed candidate requires every applicable Foundation gate, all ten same-run/same-attempt database receipts, ordinary native Chromium/WebKit, genuine related journeys with startup safeguards, and successful strict aggregation. Verify remote head/tree, actual test merge and ordered parents before accepting its receipts. Merge only the expected current head after complete successful acceptance and review, then run fresh main verification. Earlier PRs remain evidence records and must not merge separately. Six parent groups remain accepted and 34 open; production provider/terms, selected storage/host, source continuity, physical-device and cutover acceptance retain their dependencies.
