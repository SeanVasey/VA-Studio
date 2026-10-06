# Bounded browser setup failure diagnostics

October 6, 2026 UTC. This WP-01 correction starts from inclusive customer candidate `d45ebf08e9e88026e9396229f755fec6d3372e22`. It makes a refused fixture setup reviewable without retaining its private installation or changing its outcome. It does not repair or infer the underlying setup failure.

## Observed failure

PR #12 Foundation run `37485777788`, attempt 1, Chromium job `112345987142`, checked out `c5b80e55b8beff4afb59cc1c3a421da54a37d681` (tree `f7016c2c0231677d13ddd1da5bdac9c2f54af968`). Genuine ClamAV installation/signature setup passed (`1.5.4/28145`). The guarded PHP fixture builder then exited 1 after emitting only its fixed refusal sentence. Playwright never started; no template-focus journey ran. The upload step found no report or test-result files. The original log and source-bound failure record are retained as `ci-run-37485777788-chromium.log` and `ci-run-37485777788-chromium-setup-failure-evidence.json`.

The original catch discarded the throwable, and the runner's `finally` removed temporary logs with the private installation. No retained evidence identifies the underlying exception or setup phase. Bootstrap, runner and customer fixture source bytes were unchanged from original PR #9; that fact does not establish an environmental cause.

## Correction and boundaries

The PHP refusal retains its fixed sentence and exit 1, followed by one JSON line containing exactly `phase` and `exception_class`. Sixteen fixed milestones distinguish isolation, stage/scanner checks, application/configuration, migrations, provisioning, catalog/installation checks, customer purchases/manifests and delivery verification. An unexpected phase becomes `unknown`. The class is limited to 160 ASCII bytes with ordinary PHP namespace syntax; anonymous or overlong names become `Throwable`. Messages, traces, file locations, request/body data, credentials and fixture values are never read into this diagnostic.

The ordinary runner prints its existing fixed setup-failure sentence and sets exit code 1 instead of constructing a Node stack for that known refusal. The browser launch remains inside the successful-setup branch. The existing `finally` still removes the temporary directory. Scanner requirements, isolation checks, domain transitions, stage selection, timeouts, retries and all browser assertions remain unchanged. No private fixture directory, database, logs or credentials are retained. A process that cannot reach the PHP catch still has the fixed runner failure; no exception cause is invented for it.

## Executed checks

All checks used the installed PHP runtime with the existing committed dependencies. No scanner, domain or browser success was fabricated.

| Check | Actual result |
| --- | --- |
| Three targeted methods in `scripts/ci/test-related-browser-stage.py` | Passed, 0.855 seconds. Existing malformed-stage cases retain pre-migration refusal; new cases exercise real PHP catch output and real ordinary-runner cleanup. |
| Available harness selection: `RelatedBrowserStageSafeguards`, related-bootstrap positive case and the three targeted methods | 10 methods passed, 5.514 seconds. The positive case performs actual fresh SQLite migrations, interactive provisioning and installation diagnostics with an empty commerce graph. |
| Original `d45ebf08` bootstrap/runner with the two new regression methods in a separate checkout | Expected red: 2 methods, 5 failed subcases. Original product and runner bytes were preserved; only the regression harness was copied. |
| `python3 scripts/ci/test-ci-scope.py` / `python3 scripts/ci/test-focused-tests.py` | 27 / 40 passed. Existing selection, gate and budget boundaries remain intact. |
| `npm run build` | TypeScript and build passed; existing chunk advisory retained (521.17 kB, 152.60 kB gzip). The build supplies the manifest required by actual installation diagnostics. |
| Scoped Pint, PHP syntax, Node syntax, whitespace | Passed. |

The catch regression uses PHP's existing prepend option only in the private test harness to throw at actual application-provider loading. It checks normal and namespaced classes, an anonymous class carrying a temporary path, and an overlong class. Each exception contains private canaries and generated fixture values; exact stderr equality allows only the fixed sentence and the two permitted fields. The actual runner regression delegates to installed PHP with the same test-only prepend option, refuses an invalid isolation identity, verifies exit 1 and exact safe output, and confirms the runner deleted its real temporary directory. There is no application fault-injection switch.

An initial runner-harness attempt used `PHP_INI_SCAN_DIR`; the local runtime wrapper explicitly resets that variable, so one of three targeted methods failed. `browser-setup-diagnostics-initial-ini-harness.log` retains that observation. The corrected test delegates to the same installed PHP with explicit `-d auto_prepend_file=...`; product behavior and assertions were not relaxed.

Local `/usr/bin/clamscan` is absent. The existing ordinary paid-customer bootstrap method and native Chromium/WebKit journeys were therefore not executed locally; no skip or weakened scanner branch was added. The complete existing harness and both genuine native browser engines remain required in fresh hosted acceptance. This diagnostic change supplies no template-focus, refund, customer-commerce or production acceptance.
