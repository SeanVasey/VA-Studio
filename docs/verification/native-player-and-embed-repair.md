# Native player and preview-embed repair

Status: source correction and local verification complete; corrected Chromium/WebKit execution pending.

## Observed failure evidence

[Focused native run 36815591697](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36815591697) tested diagnostic commit `1d0c080e43fe23e54a98819b15ebf78bbdffd779`, tree `b7e283ad4bb996f76731f6d75132cfa543821bf0`. Its application source is the experience checkpoint `42cc693db0bb20f442e16607b0d50741e6054400`, tree `661ef7a2cdb7109f39e4113abc8d225de2c901a7`; the diagnostic adds one workflow only. The retained JUnit, traces, page snapshots and screenshots establish three failures owned by this repair:

- Chromium's section-loop case cleared the loop, disabled the just-activated Clear loop button, pressed Escape and still found the queue visible after 10 seconds. The panel's Escape handler only receives events from inside it. Disabling its focused action loses the reliable keyboard path. The prior frontend case masked this by manually focusing Set A before pressing Escape.
- WebKit's section-loop case retained visible A/B markers at 2 and 4 seconds and Loop on, but native time advanced to 14.45091092 after the direct seek to 4.5. The snapshot still showed Loading. Production checks required the application status to be playing, so an advancing native element in loading could bypass both loop enforcement and the next animation frame. The trace did not capture a complete native media-event timeline; a seek-related waiting event is a source-supported explanation, reproduced explicitly in the frontend regression, rather than a claimed captured event.
- WebKit's preview-embed case focused the outer audio container and pressed Space successfully, but its audio-route callback remained at zero throughout the 10-second check. The screenshot shows intact native controls with their visible play button and time zero. The accessibility snapshot exposes the audio container and store link, without a DOM Play button. Space on that container is an invalid assumption about this pinned WebKit control; this result is not evidence that decoding failed.

## Source changes

The player continues to use one real native element. Loop checks accept playing or loading only while that element is unpaused and not ended. They leave an in-progress native seek alone, then recheck on seeked and continue one animation-frame watcher. A late waiting event cannot overwrite paused, ended or error state. Loop enforcement seeks the existing owner and never calls play; pause, close, default-off continuation, source identity and explicit queue/repeat behavior remain intact.

Clear loop restores focus to Set A, and the analogous Reset speed action restores focus to its enabled selector before either action disables itself. Escape stays scoped to the panel; no global keyboard handler consumes other dialogs' keys. Frontend tests remove the manual focus workaround and check the actual resulting focus. Native cases retain Escape/closed-panel/opener focus assertions and add explicit post-action focus checks.

The embed Blade, CSP, permissions, routes and script-free/session-free boundary are unchanged. Chromium keeps Space playback and pause. The pinned touch-enabled WebKit project uses a trusted tap on the visible left native play/pause control, located from the current audio bounds. It still requires initial paused state/time zero, a real media request, native unpaused state, advancing time and subsequent native pause. The store exit remains keyboard-operated with a null opener. There are no JS play calls, synthetic media events, fake playback state, retries, sleeps, skipped cases or increased timeouts in the native test. WebKit native media keyboard accessibility and physical Safari remain explicit acceptance gates.

## Executed local verification

- Regression-first `npm test -- tests/frontend/audio.test.tsx`: 19 cases, 16 passed and 3 failed on unrepaired production source. The failures cover loop enforcement after waiting, Clear loop focus and Reset speed focus.
- Corrected player suite: 19 of 19 passed. The new event regression covers native-state guards through waiting, timeupdate and animation frames, in-progress seek suppression and completed-seek recovery, one watcher, no second play call, and late waiting/timeupdate after pause.
- Full `npm test`: 327 tests across 20 files passed under Node 24.19.0.
- `npm run typecheck`, `npm run build` and `git diff --check` passed.
- Proper isolated `npm run test:browser -- --list tests/browser/player-controls.spec.ts tests/browser/public-track-embed.spec.ts`: migrations, fresh operator provisioning and installation diagnostics passed; eight definitions in two files listed for both projects. This is inventory/bootstrap evidence only.

No corrected native execution, physical-device, production media, production catalog or launch acceptance is claimed by this record. The next dependency is the integrating candidate's real Chromium/WebKit run; preserve these assertions and inspect any concrete new failure before changing scope.
