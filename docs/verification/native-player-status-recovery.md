# Native preview loading-status recovery

Status: the initial correction is refined by captured native evidence on October 1, 2026. Narrow source correction, local checks and independent final-source review are complete; corrected native execution remains pending.

## Finding and boundary

The [first native-failure repair](native-player-and-embed-repair.md) restores section-loop enforcement through loading and fixes self-disabling-control focus. Reviewing its integrating checkpoint `50e5b7c4b4f254f6f6adaba143e4d7814beca327`, tree `acf639f2eacd77e52f5112c628b151fbb2dcc377`, found a remaining status defect: seeked and timeupdate update position but cannot restore playing. If a browser resumes native progress without another playing event, Loading remains visible and optional Media Session state remains paused. The existing toggle treats loading as active, so this finding does not establish a broken in-page pause action.

The original WebKit trace showed native time advancing beyond 14 seconds with Loading visible. It did not record a complete native event timeline. The missing-playing-event sequence is therefore reproduced as a client-state regression, rather than asserted as a fully captured WebKit event sequence.

### Captured readiness finding — October 1, 2026

The temporary diagnostic-only [run 36820401233](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36820401233), head `7581480b9f85c5ff710a548e9d9bb5a5572da000`, tree `0fa94db2bf59af6a5244df6977b7ad31c847ee58`, preserved the original section-loop assertions and canonical application audio blob `591b782a36609fbbf8625b31c76c1895c90fb2f4`. Its observer runs only in the temporary test spec, not application code. The original WebKit status assertion still failed while recording 113 bounded observations of fixed native events/samples and public numeric/boolean media fields.

After seeking to 12 seconds, all 85 retained observations reported `readyState = 2` (`HAVE_CURRENT_DATA`), `paused = false`, `seeking = false`, `ended = false` and no native error. Native `timeupdate` events continued alongside samples as the position advanced from `12.000617227` to `22.055175564` during the ten-second Loading assertion. No replacement `playing` event followed that seek. The former `HAVE_FUTURE_DATA >= 3` predicate could therefore never establish a progress baseline despite captured real playback advancement. This resolves the previous uncertainty about which readiness predicate prevented recovery; it does not establish physical-device or production-media acceptance.

Evidence identity: artifact `11142789529`, archive SHA-256 `251da328b5ca149dd38f21d5d20be903501ce0c48e6cb55c21ffd2f655625b12`; trace ZIP SHA-256 `ee1500de0bc703bc594e11fa38897d4eb51a10391927b0bdb9dd6f67b915520e`; original trace entry `attachments/0572ea10a7805f57f2b64e45a89aba016be29421`, raw JSON SHA-256 `b4651cc875d3c5e23c0f8aa4ba6bd7055349b98d085799bb5f8528fd56c28393`. The diagnostic spec blob is `32a9b92611b2a2f67f0f7e7972bdc945cb896c74`; removing only its observation instrumentation recovers the canonical spec blob `78c2c6acd1009ebe85a6f83a532da234d9067a32` byte-for-byte, including the unchanged empty-status assertion.

## Narrow correction

Recovery requires an increasing native position across stable observations, with the element unpaused, not ended or seeking, free of a native error, and at least HAVE_CURRENT_DATA. Readiness alone does not claim playback: a later native timeupdate must advance beyond the established baseline. Waiting, seeking, source loading/emptying, pause and error reset the observation. Seek completion establishes a fresh position baseline and does not itself claim playback. A native timeupdate that merely reports a seek jump, unchanged time or insufficient readiness keeps Loading.

The [HTML Standard ready-state definition](https://html.spec.whatwg.org/multipage/media.html#ready-states) distinguishes available current data from future buffering. The observed native position advancement at current-data readiness is the evidence used for recovery; buffering readiness alone is insufficient. Its [seeking algorithm](https://html.spec.whatwg.org/multipage/media.html#seeking) queues timeupdate before seeked after setting seeking false. This ordering makes a completed-seek jump unsafe as playback proof; the reset and baseline handle it explicitly.

The correction adds no play request, animation frame, timer, retry, provider interaction or source owner. Loop enforcement, default-off continuation, pause, error and close semantics remain unchanged. The native section-loop case adds one empty-status assertion after its existing real native time-progress check following loop clearing. Its playback, keyboard/focus, overflow and single-owner assertions remain intact.

## Earlier correction verification

- Final regression definition against original audio blob `f0a148350afb44fd171442347fc8ebce6aff2ecb`: 21 cases, 20 passed and one failed because Loading remained after waiting, seek completion and advancing ready native time without a replacement playing event. The corrected source was restored after this isolated comparison.
- Corrected player suite: 21 of 21 passed. The two new cases cover recovery after the actual seek-event ordering, unchanged time, insufficient data, active seeking, native error/ended states, pause, close and no additional play call.
- Full Node 24.19.0 frontend suite: 329 tests across 20 files passed. Typecheck and production build passed.
- The proper isolated browser wrapper passed migrations, fresh operator provisioning and installation diagnostics, then listed all six player definitions in both projects. Listing is not native execution.

The earlier frozen 14-spec diagnostic remains unchanged. Its native result can validate that frozen loop/focus/embed source; it cannot establish execution of this later status assertion. Integrate this bounded follow-up only after independent source review and retain real native verification against the resulting candidate. Physical Safari, background controls and production-catalog acceptance remain open.

## Readiness correction verification — October 1, 2026

Author checkout is isolated from base `04e4743301a47ca720d849e01bdeba9203708a23`, tree `6fbd125d143afead56bbb8dced0a30a1eef25595`. Only the audio readiness threshold/comment, player regression definitions and this evidence guide change. No diagnostic observation timer/listener enters the canonical test or application.

- Final 23-case player regression definition against original audio blob `591b782a36609fbbf8625b31c76c1895c90fb2f4`: 22 passed, one failed. The observed readiness-2 case remained Loading after the seek baseline and advancing native time. Readiness-3 and all negative boundary examples passed.
- Existing recovery and negative-boundary regressions now execute at both readiness 3 and observed readiness 2. The insufficient-data negative example uses `HAVE_METADATA = 1`; treating real current-data advancement as insufficient was disproven by the native recording. Both variants retain the seek jump/completion and unchanged-time Loading assertions, seeking/error/ended/pause/close guards and exactly one play request.
- Corrected full frontend suite: `npm test` passed 331 tests across 20 files, including all 23 player cases. `npm run build` passed TypeScript checking and the production build. `git diff --check` passed.
- Independent review approved frozen commit `ffe6f8f6be6be28030b3a907b6671f45dc963a47`, tree `0af76e6bd0716b6ee114e8ee293cd081ebbede9c`, verified all three changed blobs and retained native assertion/observation identities, and independently passed all 23 player cases. The reviewer found no blocking issue; corrected native execution is a separate gate.

These client-state regressions supplement the captured native evidence; they do not prove the corrected native journey. After independent review of the exact frozen source, run the original uninstrumented six player cases through the proper native browser wrapper on Chromium desktop and WebKit mobile. Keep the status, actual position, loop, owner-count, pause, navigation and keyboard/focus assertions intact. No extra waits, retries, skips, mocked native playback or weakened assertions are part of this correction.
