# Customer browser response and counter corrections

The original PR #8 candidate is published `0602f923`, local `75722ac`, tree `dc0cc0c8`. Foundation run `37472116788`, attempt 1, checks merge commit `b677fbc7` with ordered parents `387fdeb` / `0602f923`. Chromium job `112298105133` passed 58 cases and failed three. Its original log and 20,291,819-byte artifact `11417738004` remain available. These corrections address two observed customer-test failures; the independent refund-fixture preparation failure is tracked separately. No original result certifies the corrected source.

## Observe the actual streamed history response

The first account journey received HTTP 200 with private/no-store history metadata and rendered the expected purchase cards and focused heading. Its later Playwright `response.json()` failed at Chromium `Network.getResponseBody`. The retained trace records an aborted resource/body-unavailable result. This establishes unavailable protocol bytes after rendering; it does not establish a navigation or the underlying browser mechanism.

The existing native-response binding now also observes the exact same-origin `GET /orders/history`. It invokes native fetch once, reads a clone through the awaited binding before returning the identical Response to the application, and compares the observed URL, status, cache header and no-redirect property. The complete existing history JSON, focus, ownership, privacy, CSRF, storage and sign-out assertions remain. No route is intercepted, response substituted, extra request issued or application stream behavior changed. Existing sign-in/out and authorization POST captures retain their behavior.

## Isolate independent browser login counters

The guest-purchase journey completed its initial sign-in, explicit claim and sign-out with HTTP 200. The fresh-context sign-in returned HTTP 429. All journeys share a disposable server/IP; `resetBrowserLoginRateLimit()` was already invoked at this test's beginning but cleared only the Filament operator counter. It omitted the distinct customer counter accumulated by preceding journeys.

The guarded CLI helper additionally clears only `customer-auth` plus the current framework's unauthenticated loopback signature. The actual customer routes share that prefix; customer identity does not sign into the staff web guard. All existing directory, database, marker, environment and effective-cache confinement checks remain. Application middleware, ten-per-minute sign-in limit, within-journey attempts, other prefixes, other IPs and authenticated staff counters are unchanged.

Two new process-level regressions invoke the real framework middleware and actual guarded helper with a fresh isolated file cache. They prove that ten requests exhaust the counter; an allowed reset gives exactly ten fresh requests before the next 429; other-prefix and other-IP counters remain; and a mismatched environment refuses the helper without clearing the exhausted counter. They are shared-runtime tests of a SQLite browser fixture, not MySQL concurrency tests. The fixed unit selection gains this file under its unchanged 32-file cap; no native exclusion is added.

## Executed checks and remaining acceptance

- The first local regression setup omitted the disposable Blade compiled-view directory and failed during boot. Adding that directory repaired only test setup.
- PHP 8.4.26: both regressions passed, 11 assertions, zero skips/failures, 0.996 seconds.
- Red reproduction against the exact original `75722ac` helper: the allowed-reset regression failed with zero fresh requests instead of ten; unrelated counters remained unchanged. The corrected helper was restored in `finally` and its exact bytes checked.
- The new PHP file passed Pint; TypeScript passed. Existing selector safeguards and final discovery are recorded with the integrating correction.

Local native browser executables and genuine scanner/signatures remain unavailable. No local rendered-browser pass is claimed. The original three failed cases, other original-run outcomes and the corrected candidate's fresh full browser/database acceptance belong to PR #8. Preserve every original assertion, runtime budget, retry policy, exact partition/skip rule and required gate; require independent source review, fresh full acceptance and expected-head merge, followed by fresh main verification.
