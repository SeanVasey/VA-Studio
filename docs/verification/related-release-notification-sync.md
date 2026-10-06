# Complete release notifications before related-content navigation

The inclusive candidate at published head `5be12ac49f5afa21939c358fcd80e89f40fd9589`, tree `8c4c7b8b5aba03c86e8e3ce535d9a4d33fa0977d`, failed the related-content WebKit gate in Foundation run `37490570886`, attempt 1. Chromium passed its related journey. All eleven startup methods passed; this was a native browser failure after fixture setup.

The complete 19,671,456-byte related-browser archive was retrieved and its API digest verified as SHA-256 `efd6c15c6bd09ce9df07d89b9945c813160f5dcfe8199185e9969c82e0838bd8`. Independent review of its WebKit trace identified a pending Filament notification-close request when the test navigated from site releases to tracks. The terminal page-error assertion correctly retained the browser's access-control message.

## Observed ordering

- A successful restore delivered a `Previous release restored` notification with a 6,000 ms duration.
- At trace time 129018.455 ms, `reviewedPublication()` began `page.goto('/admin/tracks')`.
- At 129019.772 ms, the prior page's notification component sent its `notificationClosed` dispatch for that retained toast.
- The same-origin POST has trace status `-1`; WebKit reported the access-control console error at 129044.576 ms.

This is evidence of notification dismissal overlapping document navigation. It does not establish a server-side 403 or an incorrect CORS policy. The test's existing `activate()` helper awaited only the active table row and hidden modal heading. Those visible transitions do not acknowledge the notification component's separate delivery and close requests.

## Bounded correction

Each of the existing four successful publish/restore calls now uses the already shared `syncSuccessNotification` helper. It waits for successful notification delivery, retains the original active-row and modal checks, closes the exact notification selected by its rendered identity, waits for its successful close response and confirms removal before returning to the caller. Both production notification titles are taken from the existing site-release resource.

No application, dependency, fixture, scanner, network allowlist, error assertion, test identity, retry, timeout or budget changes. The existing helper is unchanged. No error is filtered or reclassified as an expected browser result.

## Validation boundary

TypeScript checking and direct related-browser discovery pass: the same two identities in one file, one per configured engine. The change retains all prior assertions and changes only the successful release transition's completion boundary. No new local native browser execution is claimed; the pinned browsers and genuine scanner are not available here. Fresh complete hosted acceptance, including both native related journeys, all ordinary browser cases and all ten database receipts, remains required on the corrected head.
