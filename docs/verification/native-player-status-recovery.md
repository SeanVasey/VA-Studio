# Native preview loading-status recovery

Status: narrow source correction and local checks complete; independent review and corrected native execution pending.

## Finding and boundary

The [first native-failure repair](native-player-and-embed-repair.md) restores section-loop enforcement through loading and fixes self-disabling-control focus. Reviewing its integrating checkpoint `50e5b7c4b4f254f6f6adaba143e4d7814beca327`, tree `acf639f2eacd77e52f5112c628b151fbb2dcc377`, found a remaining status defect: seeked and timeupdate update position but cannot restore playing. If a browser resumes native progress without another playing event, Loading remains visible and optional Media Session state remains paused. The existing toggle treats loading as active, so this finding does not establish a broken in-page pause action.

The original WebKit trace showed native time advancing beyond 14 seconds with Loading visible. It did not record a complete native event timeline. The missing-playing-event sequence is therefore reproduced as a client-state regression, rather than asserted as a fully captured WebKit event sequence.

## Narrow correction

Recovery requires an increasing native position across stable observations, with the element unpaused, not ended or seeking, free of a native error, and at least HAVE_FUTURE_DATA. Waiting, seeking, source loading/emptying, pause and error reset the observation. Seek completion establishes a fresh position baseline and does not itself claim playback. A native timeupdate that merely reports a seek jump, unchanged time or insufficient readiness keeps Loading.

The [HTML Standard ready-state definition](https://html.spec.whatwg.org/multipage/media.html#ready-states) distinguishes current data from enough future data to advance. Its [seeking algorithm](https://html.spec.whatwg.org/multipage/media.html#seeking) queues timeupdate before seeked after setting seeking false. This ordering makes a completed-seek jump unsafe as playback proof; the reset and baseline handle it explicitly.

The correction adds no play request, animation frame, timer, retry, provider interaction or source owner. Loop enforcement, default-off continuation, pause, error and close semantics remain unchanged. The native section-loop case adds one empty-status assertion after its existing real native time-progress check following loop clearing. Its playback, keyboard/focus, overflow and single-owner assertions remain intact.

## Executed verification

- Final regression definition against original audio blob `f0a148350afb44fd171442347fc8ebce6aff2ecb`: 21 cases, 20 passed and one failed because Loading remained after waiting, seek completion and advancing ready native time without a replacement playing event. The corrected source was restored after this isolated comparison.
- Corrected player suite: 21 of 21 passed. The two new cases cover recovery after the actual seek-event ordering, unchanged time, insufficient data, active seeking, native error/ended states, pause, close and no additional play call.
- Full Node 24.19.0 frontend suite: 329 tests across 20 files passed. Typecheck and production build passed.
- The proper isolated browser wrapper passed migrations, fresh operator provisioning and installation diagnostics, then listed all six player definitions in both projects. Listing is not native execution.

The earlier frozen 14-spec diagnostic remains unchanged. Its native result can validate that frozen loop/focus/embed source; it cannot establish execution of this later status assertion. Integrate this bounded follow-up only after independent source review and retain real native verification against the resulting candidate. Physical Safari, background controls and production-catalog acceptance remain open.
