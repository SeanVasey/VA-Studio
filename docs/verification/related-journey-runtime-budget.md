# Complete related-track journey runtime

The genuine related-track journey in run `37419522173`, published head `5664c0a03cdfd306ab67e5455462064a6392e95e` (local `495cd85863f87f93b1196ed3f4e436f0fe6af4cb`, tree `12eafe4afc4b32b68b4d6448c3fc2db1d47cef0c`), reached its 150,000 ms whole-test ceiling on WebKit. Scanner installation, build, all nine startup safeguards and genuine six-track/two-project preparation passed. Chromium passed the same journey in 52.2 seconds. WebKit reported `timedOut` at 152,290 ms; the job correctly failed.

Artifact `11393020830`, 18,166,069 bytes, was inspected after verifying SHA-256 `68881bbc7fdbb39cae3f6443c105a2c9becad69b3d138e9999e247a98273cc85`. Its genuine fixture receipt retains evidence hash `724e1e43357000877882f490bec84239d4ebae98440c81a2ee3c97d2e8ea614a`. The trace shows the reviewed-publication receipt and its empty-error assertion completed at elapsed 149,684.329 ms, before the deadline. The timeout crossed awaited operator-context cleanup; final retained-graph evidence and error/external-request assertions completed by elapsed 152,066.671 ms. Their after-events contain no assertion errors. This demonstrates continued progress through final evidence and cleanup, rather than a stalled locator.

The traced low-level context close took 7.145 ms, but that is not the complete public `BrowserContext.close()` duration: the pinned implementation also awaits request disposal, instrumentation and trace export before that channel call. No claim attributes the whole overrun to those seven milliseconds or to one specific cleanup sub-operation.

Independent artifact inspection found that the preceding successful WebKit journeys took **149,775 ms** in run `37414702061` (225 ms spare) and **147,837 ms** in run `37416246767` (2,163 ms spare). The related spec, configuration and runner are byte-identical across those two candidates and the failed candidate. Rounded `2.5m` log summaries concealed that narrow margin.

The 150-second limit was a browser-harness allowance borrowed from the earlier editorial journey, as recorded in [the original stage design](related-track-native-stage.md). The current case additionally performs genuine private-track review, reviewed publication, concurrent metadata/offer rejection, retained-state checks and awaited cleanup. This limit does not define an application response-time requirement or a scanner/process limit.

The correction raises **only this complete case's harness ceiling to 180,000 ms**, leaving approximately 27.9 seconds above the observed completed assertion sequence. Both engines still run every action, assertion and cleanup operation once. Two bounded 180-second cases plus the existing 30-second server startup allowance fit the unchanged 600-second related browser subprocess bound. The 10-second expectation timeout, one worker, zero retries, scanner and media-process limits, preparation bounds, workflow limits, fixtures, evidence requirements and all acceptance gates remain unchanged. No production source or database policy changes are included. This is runtime allowance for the complete journey, not a measured performance improvement.

The correction is confined to that timeout/comment and its documentation. Local validation passed:

- `npm run typecheck`, including the browser source.
- `node node_modules/@playwright/test/cli.js test --config=playwright.related.config.ts --list`, with the required isolated stage variables: exactly two cases, one per engine.
- `git diff --check`.

Local native browser executables and ClamAV remain unavailable. Genuine rendered execution and complete current-source hosted acceptance remain required. Prior successful assertions, predecessor database receipts and source review do not accept this successor. The failed run remains recorded without relabeling it as successful.
