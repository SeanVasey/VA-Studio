# Paid Tax history index: evidence

Lane T1 adds a bounded, default-off history index for retained paid Tax batches, plus a page shell that carries no private props. The [design](design.md) explains the decisions:
- the owner-only index mode
- the current-owner closure
- the sentinel window
- the pre-bytes proof
- why the page does not need a privacy exception

This is a local development candidate. Nothing has been pushed, no PR is open and no workflow was dispatched. A separate independent review of the exact SHA is required before merge, because the change touches authorization and private data.

## Tested source

- **Code commit:** `3711a7b6542b989f996bd70e01e59b00fedd688f` on branch `harness/tax-history-index-20261017`, with base `origin/main` at `efa6eea5b007f1f2623c4009c1e9bef737f06bee`.
- **Packet commits:** this packet is documentation-only. It changes no source, so the Tax evidence below binds to `3711a7b6`.
- **Separate fix commit:** `902ac4be2bc1003e091d120c260668259ab2bcb1`, outside the Tax lane, changes only `resources/js/app.tsx`, a new frontend guard test and `CHANGELOG.md`. Its own evidence is in [Outside the Tax lane](#outside-the-tax-lane-page-registration-fix). It changes no PHP, so the PHP and native evidence carry unchanged. The full frontend suite and build were rerun at that commit.
- **Tree state:** every exact-source run recorded `head=3711a7b6…` and an empty tracked diff (SHA-256 of the empty string). The only untracked path was this packet directory.
- **Frontend binding:** the frontend run (r06) happened just before the commit. All four changed frontend files have Git blob hashes identical to the commit's, and their modification times precede the run's start (17:49:05 UTC). No frontend input changed afterwards.
- **Tool versions:** PHP 8.4.26, PHPUnit 12.5.34, Laravel 13 and Node 24.22.0, with vitest 5.0.3, TypeScript 7.0.2 and Vite 8. Native runs used Oracle MySQL Community Server 8.4.11 on a private datadir `/var/lib/va-mysql84-t1/data`, port 33587, with its own socket and pid file; no shared instance was used.

## Results on the exact source

### SQLite (in-memory)

Each row is one PHPUnit invocation; failures, errors and skips are from the JUnit file.

| Run | Files | Tests | Assertions | Failures / errors / skips | PHP exit |
| --- | --- | --- | --- | --- | --- |
| e01 | `PaidTaxHistoryIndexTest` (new, whole file) | 23 | 264 | 0 / 0 / 0 | 0 |
| e02 | `PaidTaxRootHttpBoundaryTest` (whole file, with new page/index cases) | 46 | 891 | 0 / 0 / 0 | 0 |
| e03 | `PaidTaxHttpJourneyTest` (whole file, regression) | 12 | 212 | 0 / 0 / 0 | 0 |
| e04 | `PaidTaxGrantJourneyTest` (whole file, with the new dual-driver case) | 21 | 278 | 0 / 0 / 0 | 0 |
| e05 | `PaidTaxAdversarialJourneyTest` + `PaidTaxDownloadJourneyTest` (projection-read consumers, regression) | 26 | 264 | 0 / 0 / 0 | 0 |
| e06 | `CheckoutReturnPresentationTest` (the existing `HandleInertiaRequests` Vary branch, regression) | 6 | 612 | 0 / 0 / 0 | 0 |

### Native MySQL 8.4.11

| Run | Selection | Tests | Assertions | Failures / errors / skips | PHP exit | Time |
| --- | --- | --- | --- | --- | --- | --- |
| m02 | `PaidTaxGrantJourneyTest`, the new case `test_history_index_lists_only_current_owner_batches_and_refuses_late_withdrawal_before_bytes` | 1 | 14 | 0 / 0 / 0 | 0 | 261 s |
| m03 | `PaidTaxGrantJourneyTest`, whole file (see "Native timeline" below) | 21 | 278 | 0 / 0 / 0 | 0 | 6,348 s |
| m04 | `PaidTaxHistoryIndexTest`, four cases (listed below) | 4 | 36 | 0 / 0 / 0 | 0 | 924 s |
| m05 | `PaidTaxGrantJourneyTest`, two cases (listed below) | 2 | 24 | 0 / 0 / 0 | 0 | 538 s |

The m04 cases were run on MySQL after the server restart because they exercise the native `FOR UPDATE` list read, the window snapshot and owner rollback:
- `test_twenty_rows_fit_and_the_twenty_first_is_only_a_sentinel`, data set "twenty-one sentinel"
- `test_a_tampered_retained_row_refuses_the_whole_list_without_bytes`, data set "transplanted ciphertext"
- `test_owner_withdrawal_at_each_original_frame_point_refuses_without_list_bytes`, data sets "final fence" and "commit callback"

The m05 cases are the new index case again and the existing owner-closure case `test_late_current_owner_withdrawal_rolls_back_origin_and_allows_a_fresh_authorized_retry`.

The other 19 cases of `PaidTaxHistoryIndexTest` ran only on SQLite. That file is not in the MySQL native selection.

### Frontend and quality gates

- **Vitest:** `npm test` passed 67 files and 1,159 tests, exit 0, including 18 tests in the new `tests/frontend/paid-tax-journey.test.tsx`.
- **Build:** `npm run build` runs `tsc --noEmit` and then `vite build`, and exited 0. `PaidTaxLicenses` builds as its own lazy chunk (18.37 kB of JavaScript and 0.25 kB of CSS). `public/build` was deleted afterwards.
- **Client-bundle secret scan:** `python3 scripts/ci/scan-client-bundle.py public/build` ran on a fresh build: 8 files, no hits, exit 0. That build was deleted too.
- **Pint:** `vendor/bin/pint --test` on all nine changed PHP files at `3711a7b6` passed with exit 0.
  - At base, `app/Http/Middleware/HandleInertiaRequests.php` already failed `ordered_imports` (exit 1, retained).
  - Because this change edits that file, it applies only Pint's import-order fix there.
- **Preflight safeguard scripts at `3711a7b6`:** all ten exited 0, with 266 Python tests in total. They are `test-phpunit-shards`, `test-ci-scope`, `test-workflow-cadence`, `test-focused-tests`, `test-database-receipts`, `test-gitlab-database-receipts`, `test-gitlab-writer-feedback`, `test-php-test-runtime`, `test-bootstrap-macos` and `test-publish-github`. They include the repository check that every driver-branching test file is selected or residual.

## Outside the Tax lane: page registration fix

This fix is not part of the Tax history index. It was made on this branch at the coordinator's request, because the Tax change already edits `resources/js/app.tsx` and a separate PR would conflict there.

**The gap.** At base `efa6eea5`, the server renders 13 Inertia page names, but `resources/js/app.tsx`'s explicit `resolve()` had no branch for `PaidGrants` (rendered by `PaidGrantController::page`) or `ProductionCustomerFeatures` (rendered by `AccountFeatureController`). Either page would throw "Unknown page" in the browser as soon as its default-off route was mounted.

**The fix.** Commit `902ac4be2bc1003e091d120c260668259ab2bcb1` registers both pages in the same lazy form as `PaidTaxLicenses`. The existing static imports and branch order are unchanged. It also adds a CHANGELOG line under Fixed.

**The guard.** `tests/frontend/inertia-page-registry.test.ts` reads the repository statically, following the `embed-font-assets.test.ts` precedent, and runs three tests:
- It collects every literal page name passed to `Inertia::render`, `Route::inertia` or `inertia()` in `app/` and `routes/`. Any computed name is refused rather than skipped; none exists today.
- Every collected name must have a `resolve()` branch and a `resources/js/Pages/<Name>.tsx` file.
- Every branch must load the Pages file of its own name, and no name may appear twice.

Guard results (the archive keeps each run's output):

| Run | Source | Result |
| --- | --- | --- |
| f02, red on main | Detached `efa6eea5` with only the untracked guard file | 1 failed, 2 passed, exit 1. Missing names: exactly `PaidGrants` and `ProductionCustomerFeatures`. |
| f01, red on the prior branch head | `c172abe8` | 1 failed, 2 passed, exit 1. The same two names. |
| f03, green | Fixed working tree | 3 passed, exit 0; `npm run typecheck` exit 0. |
| f04, mutation check | The `PaidGrants` branch temporarily pointed at `./Pages/FreeGrants` | The same-name test failed ("expected 'FreeGrants' to be 'PaidGrants'"), exit 1. The file was then restored byte for byte. |

Frontend results at `902ac4be`:

| Run | Result |
| --- | --- |
| f06 guard | 3 passed |
| f06 `npm test` | 68 files and 1,162 tests passed, exit 0. This includes the 18 Tax journey tests, so the Tax frontend is reconfirmed at this commit. |
| f06 `npm run build` (`tsc --noEmit` + `vite build`) | exit 0 |
| f06 bundle secret scan | 14 bundle files, no hits, exit 0 |

`public/build` was deleted afterwards.

f05 ran the same checks at `58573ba0` with identical results. That was the fix commit before I amended it. The amendment changed only a code comment and the commit message, and the built chunk hashes are identical.

**Bundle layout.** With three lazy pages, the bundler (rolldown in Vite 8) hoists the modules they share with the entry into one eagerly loaded chunk, which it named `customer-account`.

| Build | Eager JavaScript files | Eager total |
| --- | --- | --- |
| At `3711a7b6` | `app` 617.66 kB | 617.66 kB |
| At `902ac4be` | `app` 273.05 kB, shared chunk 344.39 kB, runtime 0.58 kB | 618.02 kB |

Eager bytes are therefore essentially unchanged, but the entry now loads three files. The page chunks load only when opened: `PaidGrants` 20.68 kB, `PaidTaxLicenses` 18.46 kB, `ProductionCustomerFeatures` 14.39 kB plus 1.05 kB of CSS.

Nothing that consumes the build assumes a single entry file:
- Laravel's `@vite` preloads chunk imports from the manifest.
- `InstallationReport` checks every manifest entry.
- The offline storefront worker caches only fixed `/brand` assets and `/offline.html`.

No browser (Playwright) run was made, so actual loading of the split chunks in a browser is untested here. The base build at `efa6eea5` was not measured.

## Native selection delta (for the lead's census reconciliation)

`python3 scripts/ci/phpunit-shards.py --shards=1 --prefix=phpunit-ci-mysql --mysql-native-selection` lists tests without running them. Its generated files were deleted after each run.

| Source | Selected native cases (files) | Discovered cases (files) |
| --- | --- | --- |
| Base `efa6eea5` | 2,432 (233) | 9,202 (677) |
| `3711a7b6` | 2,433 (233) | 9,227 (678) |

The native selection grows by one case: the new method in `PaidTaxGrantJourneyTest`, which is already in `include_files` and runs on both drivers. Discovery grows by 25 cases: 23 in the new index file, 1 journey case and 1 boundary case.

The new `PaidTaxHistoryIndexTest` does not branch on the driver. It contains no match for the `DRIVER_BRANCHING` pattern, so it needs no selection or residual entry. No `scripts/ci/*.json` census, timing or workflow file was edited. The new file has no timing entry, so the shard balancer will use its fallback weight.

## Native timeline, and a correction to the coordinator's note

The coordinator reported that a container reboot at about 00:36 UTC on 18 October killed the whole-file native run m03 and the private mysqld, and asked for m03 to be recorded as interrupted. The recorded evidence contradicts that, so it is recorded as it actually happened.

- **Run start and server start.** m03 started at 18:16:17 UTC on 17 October on a server started at 18:11:03.
- **Run completion.** m03 wrote its JUnit file and exit code at 20:02:05 UTC: 21 tests, 278 assertions, no failures, errors or skips, PHP exit 0, 6,347.8 s.
- **Server shutdown.** At 20:11:03 the server received a SIGTERM and logged "Shutdown complete" one second later. That was exactly two hours after its start, when my own background-task time limit ended the server process. The shutdown was clean, about 4.5 hours before the reboot.
- **Earlier monitor message.** A live monitor once showed an `R` in progress output. That came from my monitor's pattern matching the "Runtime:" header line; the final output and JUnit show no risky test.
- **Restart.** After the reboot the run directory held no stale `pid`, `sock` or `sock.lock` files. Restart at 00:39:47 UTC on 18 October showed ordinary InnoDB initialization with no crash-recovery messages.
- **Bounded runs and shutdown.** The bounded runs m04 and m05 followed. `mysqladmin shutdown` exited 0 at 01:05:28, and the server logged "Shutdown complete (mysqld 8.4.11)".
- **Execution mode.** The whole-file m03 run took about 1 h 46 m, which no foreground call can hold. It therefore ran as a background process that I monitored; m02, m04 and m05 were too. The whole-file run was not repeated after the reboot, as instructed.

## Retained original failures

None of these were rerun under a new label to hide them. Each was a fixture or expectation defect, diagnosed before the change described.

| Run | Source | Result | Cause and correction |
| --- | --- | --- | --- |
| r01 | pre-commit tree | 21 tests, 197 assertions, 4 failures, exit 1 | See the four causes below. |
| r02 | pre-commit tree | 6 tests, 95 assertions, 1 failure, exit 1 | The "in-frame withdrawal" test fired its write on the first commit it saw. That was the separate pre-frame `ProductionCustomerAccess::current()` transaction, so the refusal came from that identity check and the index frame never ran. The listener now fires only inside `PaidTaxTransaction::run`. |
| r03 | pre-commit tree | 3 tests, 31 assertions, 1 error, exit 2 | The two in-frame cases passed. The commit case's own clean-up tried to reactivate the account, which the identity retention trigger forbids. That case now asserts that later reads stay refused. |
| m01 | `3711a7b6` | 1 test, 0 assertions, 1 error, exit 2 | `Disposable test database admission refused` before any test code ran. The local disposable declaration `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_catalog_import` was missing. It is true for this private datadir and was added for m02 onward. |
| Pint, base blob | `efa6eea5` | exit 1 | Pre-existing `ordered_imports` failure in `HandleInertiaRequests.php`. |

The four r01 failures and their corrections:
1. A test helper posted the JSON array `[]`, and the Tax intake requires an object (422). It now posts `{}`.
2. The test expected an in-frame rollback of a write that a commit callback injected. The index frame writes nothing and has no commit admission, so that write commits; the terminal proof refuses it. The test was split into three frame points with correct expectations.
3. The test expected a replaced delivery-policy version with the same provenance to refuse the next request. Retained batches stay listable under the same `retained()` rule `show` uses, and only the captured read refuses. The expectation was corrected.
4. A real gap: Inertia's middleware overwrote `Vary`, dropping `Cookie` on the page shell. `HandleInertiaRequests` now keeps `Cookie, X-Inertia` for `paid-tax-licenses`.

Passing pre-commit runs r04, r05 and r07, and the pre-commit native-selection run r08, are also retained in the archive. The exact-source runs above supersede them.

## What the tests prove

On SQLite:
- empty, 20-row and 21/22-row windows, with the sentinel only setting `hasMore`
- newest-first ordering and exact output keys, with no private column, declaration, token or path in the response
- every listed id opens through `show`
- foreign accounts excluded in both directions
- whole-list refusal for each tampering kind, including a tampered sentinel
- owner withdrawal after commit, between response and stream (fixed body only), and at three points inside the original frame
- policy flips before bytes and on the next request
- an index read is single-use and cannot be captured in producer mode
- no producer commit observer while an index response is pending, which `show` does install
- unmounted and runtime-withdrawn index and page refusing with no policy, identity or SQL
- query strings, bodies and wrong methods refused before identity
- the page shell requiring the buyer, carrying only `props.errors`, and keeping the private header set and `Vary: Cookie`

On native MySQL: the list read, owner closure, sentinel window, tamper refusal, in-frame fence rollback and commit-callback refusal, for the cases named above.

In jsdom:
- exact shape validation for the list, projection, status and authorization
- the full journey of index, show, document, authorize and native-form redeem
- no token in any URL, form action, history call, storage write or rendered markup; inputs cleared and the form removed
- refusal states: 403, 404 and 419 denials and sealed late denials; refusals, malformed shapes and network loss
- exact replay of an uncertain authorize, and retry of a refused download gated on status
- clearing on a hidden tab and on page departure
- headings, landmarks, skip link, `role="status"` and focused `role="alert"`, `aria-busy`, and focus moved to the opened order

## Untested conditions

- **Whole files on MySQL:** the whole `PaidTaxHistoryIndexTest` was not run on MySQL; only the four cases above. The whole-file `PaidTaxGrantJourneyTest` run m03 completed before the reboot and was not repeated afterwards.
- **Concurrency on MySQL:** no two-connection race was run, such as a concurrent same-buyer finalize, an insert into another account next to the list's gap, or lock-wait timing. Under load, the list select's lock wait is not capped by `PaidTaxRows::held()`; see the design.
- **Browser, keyboard and contrast:** there was no real browser (Playwright), screen-reader or keyboard-only run of the page. WCAG 2.2 AA contrast of the reused styles was not measured. Accessibility evidence is limited to the jsdom basics listed above.
- **Page edge cases:** neither Inertia's asset-version 409 handling for the shell nor a 429 on the page route (ordinary handler) was tested.
- **Performance:** the cost of decrypting and verifying up to 21 rows per request was not measured.
- **Hosting:** route and config cache rebuilds, FPM, distributed sessions or cache, a real host and any production provenance are untested. Only `synthetic_rehearsal` exists.
- **Repository-level gates:** no Foundation CI, focused CI or preflight was dispatched, by policy. These local checks are not a full-suite pass.

## Activation inputs (all unchanged)

Exposing the page or index in a rehearsal still requires all of the following, and none are set by this change:
- `paid-tax-grants.http_mounted=true` and `rehearsal_enabled=true` in `local` or `testing`, with `operative_enabled=false`
- an explicit synthetic `delivery_policy`
- production customer identity enabled with synthetic provenance
- the Tax checkout policy enabled

Live payment, production provenance, deployment and DNS remain outside this lane and need Sean's explicit authorization.

## Commands

The SQLite runs used this command from `/home/user/wt/t1`, with `--log-junit` added by the recorder (`tools/t1-run.sh` in the archive):

```
env APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_SOCKET= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  php -d memory_limit=512M -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- <files>
```

The MySQL runs replaced the database variables with the following, and the m04 and m05 filters are recorded in each run's `meta.txt`:

```
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33587 DB_USERNAME=root DB_PASSWORD= DB_DATABASE=vaseyaudio_catalog_import VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_catalog_import
```

The server was initialized, started, prepared and stopped with:

```
mkdir -p /var/lib/va-mysql84-t1/run && /opt/mysql-8.4.11/bin/mysqld --no-defaults --initialize-insecure --user=root --datadir=/var/lib/va-mysql84-t1/data
/opt/mysql-8.4.11/bin/mysqld --no-defaults --user=root --datadir=/var/lib/va-mysql84-t1/data --port=33587 --bind-address=127.0.0.1 \
  --socket=/var/lib/va-mysql84-t1/run/mysqld.sock --mysqlx=OFF --pid-file=/var/lib/va-mysql84-t1/run/mysqld.pid --log-error=/var/lib/va-mysql84-t1/server.err
mysql -uroot -h127.0.0.1 -P33587 -e 'CREATE DATABASE vaseyaudio_catalog_import'
mysqladmin -uroot -h127.0.0.1 -P33587 shutdown
```

The frontend checks ran with `. /opt/nvm/nvm.sh && nvm use 24`, then `npm test`, `npm run build` (which includes `tsc --noEmit`), `python3 scripts/ci/scan-client-bundle.py public/build` and `rm -rf public/build`.

## Evidence archive

`recorded-evidence.tar.gz` (SHA-256 `bca2db1c2d62b974e52882b0595cbacdd6a33e0242f52369e687f455328792df`) holds 108 files. For every invocation it keeps:
- `meta.txt`: HEAD, status, the tracked-diff and untracked hashes, the exact command and the PHP exit code
- `stdout.txt`
- `junit.xml`

It also holds the frontend logs, the page-registration guard runs f01 to f06, both native-selection manifests, the safeguard and Pint outputs, the MySQL initialization, restart and shutdown records, the server error log and the recorder scripts. `recorded-evidence-index.json` lists each file with its size and SHA-256.

All data in it is synthetic: local loopback-SMTP buyers, a recording synthetic Tax transport and the fixture's fixed testing app key, which is defined in test code and never printed. No real buyer, payment, credential or token is included.

## Independent review

A separate reviewer approved the batch at `e947cdb3e08a570362d3380727384d51b97ab43d` with no P0, P1 or P2 ([decision record](independent-review-e947cdb3-DECISION.md), imported verbatim). P3-4 (the changelog said "20 per page", but there is no paging) is corrected in the changelog. P3-1 to P3-3 and P3-5 to P3-7 are deferred to the next Tax batch and recorded in `docs/handoff/2026-10-07/codex-deferred-20261011.md`:
- P3-1: null-safe producer abort;
- P3-2: a capped locking list helper for the index and status selects;
- P3-3: distinguish a changed window from a sign-out in the client;
- P3-5: label the client tax arithmetic as non-authoritative, or drop it;
- P3-6: `frame-ancestors 'none'` on private shells, including the V1 PaidGrants page;
- P3-7: a signed-out page response.

