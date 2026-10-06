# Refund browser committed-response evidence

PR #8 run `37475905240`, attempt 1, checked head `037d88bc` through merge `2a8d7e451850517c3c0ae7bd869a1a883687ca12`, tree `786db64f`. Chromium job `112311940324` was ultimately cancelled when the source was superseded, but its retained complete native report records **60 passed and one failed**, with zero retries. The corrected customer-account and guest-purchase journeys passed. The refund journey failed after fixture preparation, at the assertion requiring `Refunded test resources released` inside the intercepted main Livewire response.

Artifact `11420006157` was retained and verified at **14,515,058 bytes**, SHA-256 `97a84aa6868e170a4cb6112333a462bde480a8ef19eab74598e23e4dde9bf122`. The actual response was HTTP 200 with a main table component and `sync-action-modals` / `notificationsSent` dispatches. It contained no notification title. The installed Filament notification implementation stores notification data in the session; its separate notifications component listens for `notificationsSent` and pulls that data. The main component response is therefore not evidence that the success toast has already been serialized into its body.

The failed assertion ran before the deliberate response abort and before the existing `released` verifier. The retained response alone does **not** establish that the resolution committed. No successful release, exact retry or partial-refund refusal is claimed from that failed journey. The subsequent `requestfailed` waiter error occurred when the test ended without reaching its deliberate abort.

## Correction

The browser now invokes the existing guarded `verify(project, 'released')` after reading the real HTTP 200 response and **before** dropping it. That verifier checks the committed resolution, exact original business rows and payload hashes, unchanged SQL guards, two retained history events, zero partial-refund history, and exactly four transaction-free provider-interface reads. Failure prevents the test from representing the response as a successful commit.

After the deliberate connection reset, the original `released` verifier still runs and its complete proof must equal the pre-drop proof. The test retains the original signed request sequence and request ID, retries that exact request, requires the visible success notification, and checks the same resolution with no additional provider reads. The partial-refund refusal, original evidence and privacy checks, history screenshot, one deliberately failed network request, zero page errors and zero external requests remain. There is no application, fixture, transport substitution, retry, timeout, skip, or case-count change.

## Verification boundary

The correction is based on local `a831d67`, the reviewed readiness composition; its refund browser source is identical to the failed source before this correction. `npm run typecheck` and `git diff --check` passed. Actual Playwright discovery still lists **122 cases across 35 files**, 61 per browser, including the unchanged refund journey identity in both engines. Discovery did not execute a browser.

Local native browser executables and genuine scanner/signatures remain unavailable. The new committed-before-drop assertion and the complete refund journey require fresh hosted native execution and independent source review. Earlier partial or passing results remain bound to their original source and do not accept this correction.
