# Site-release menu ARIA after a Livewire morph

Status: **Production correction and focused regressions prepared; native candidate execution remains pending.** This resolves observed browser failures without dropping the menu's ARIA assertions, introducing retries, increasing timeouts or skipping a journey. It changes no publication, schedule, authorization or content-retention command.

## Retained failure evidence

[Foundation run 36813625513](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36813625513), browser job `110213862323`, checked out PR #88 merge `56940be68fe0673688310aa8be92afe4df9df8a2`, tree `82072bfe450a1c1d40407b17d8dfed0fd39680bb`, for head `abb96ebc2bbddccb04bc18981cda07cc72b19d4f`. Its actual result was **31 passed, one existing mobile keyboard-focus skip and six failures**.

The [retained artifact](https://github.com/VASEYDEV/VASEYAUDIO/actions/runs/36813625513/artifacts/11141075375) was downloaded and its 105,432,276-byte archive verified against SHA-256 `7e1b5562cdb4e2242932ebaed62adae1ca0e044fc81600d76fd78b4148d17637`. All six failure traces, DOM snapshots, screenshots and request/response records were inspected. The table uses each trace's relative millisecond clock; different traces do not share one clock.

| Journey | Actual observed boundary |
| --- | --- |
| Editorial, Chromium | Original-site menu panel remained `display: block`, with matching panel id and controls, while the actual trigger remained `aria-expanded="false"` from `call@379` before at 22048.331 ms through after at 32053.024 ms. |
| Content, Chromium | The first-content menu had the same stable open-panel/false-ARIA contradiction from `call@756` before at 71256.421 ms through after at 81262.326 ms. |
| Schedule, Chromium | Scheduled-release menu had the same contradiction from `call@393` before at 112008.027 ms through after at 122013.812 ms. |
| Schedule, WebKit | Scheduled-release menu had the same contradiction from `call@254` before at 396609.076 ms through after at 406614.891 ms. |
| Content, WebKit | After publishing the second release, the first-content trigger kept controls `fi-dropdown-panel-7t2yx6cc`, but its current closed panel had no id. Repeated predicate snapshots, including `call@802` after at 334026.983 ms, retained that missing identity. |
| Editorial, WebKit | The copied active release already contained four navigation rows after the earlier Chromium failures prevented their final restoration. The test unconditionally added a fifth, filled only rows zero through three and left its required destination blank. The screenshot shows that placeholder; the request trace contains the Add action but no subsequent Save action POST. This was native form validation of an incomplete test fixture, not a failed server save. |

The first five failures establish a real accessibility regression. Waiting longer or removing the assertions would leave staff with an open menu announced as closed, or controls naming a missing panel.

## Correct layer and lifecycle

Pinned Filament Support `v5.8.4` initializes one observer over the original panel's `id`/`style` and the original trigger's ARIA attributes. Livewire can replace those nodes while retaining their Alpine component. The original observer then misses mutations on the replacements. The installed sources are `vendor/filament/support/resources/js/components/dropdown.js` and the dropdown Blade component. Livewire `v4.4.6` supplies Alpine's closest-data-stack behavior and `$el` magic in its installed distribution.

The existing administration accessibility view now binds the component's one ARIA observer to the current direct panel and trigger. It retains the component's stable `panelId`, reads actual display state and writes only changed attributes. On node replacement it disconnects the old registrations and observes the current nodes. If the panel is temporarily absent, controls/haspopup are removed and expanded becomes false; adding it repairs the relationship. Root child-list observation exists even if both nodes are absent at first binding.

The observer remains in the component's existing `observer` property, so its ordinary `destroy()` disconnects it. A WeakMap identifies bindings without keeping a separate list of removed components. The global discovery observer watches only child-list changes and `id` attributes. Initial Filament panel-id assignment supplies rediscovery when this HEAD hook encounters a node before Alpine initializes it; there is no timer or indefinite polling. A `$el` equality guard prevents an uninitialized nested dropdown's inherited ancestor scope from taking over that ancestor's observer. Reinitializing a new scope on the same root replaces its prior observer rather than retaining the old identity.

The pinned `x-float.teleport` modifier selects fixed positioning; it does not relocate these panels outside their dropdown roots. This repair follows the installed component's actual DOM contract. Dependency upgrades must retain the current-node, scope and destroy regressions. No vendor source or generated vendor asset is edited. Existing activation, navigation and keyboard-focus restoration remain in place.

The editorial test normalizes its copied navigation to exactly four rows through ordinary Add/Delete controls and explicit counts, then fills all required fields. Actual Save, publication/privacy, provider-link and restoration assertions remain. Other failed journeys can still leave their active synthetic publication because their inherited `finally` blocks dispose request contexts without rolling publication back; this correction does not claim a new universal failure-cleanup mechanism.

## Verification and next gate

- `npm run typecheck` and `npm run build` passed after the changed native definitions.
- `php artisan view:cache` passed, compiling the administration view and the other Blade templates.
- Proper `npm run test:browser -- <four affected specs> --list` passed fresh SQLite bootstrap and listed **12 definitions in four files**, across Chromium and WebKit. Listing is not native execution; the inherited mobile keyboard-focus case remains intentionally skipped at execution.
- A scratch jsdom check executed the exact production Blade script and passed current/replaced/removed/reinserted nodes, stripped attributes, late attribute-only initialization, initial empty-node discovery, nested ancestor-scope isolation, same-root new-scope replacement and destroy isolation. This verifies observer logic, not browser actionability or an actual Alpine/Livewire journey.
- The controlled native regression loads the exact production script and covers these same lifecycle boundaries. The earlier helper-readiness fixture stays intact, as do the real content, editorial, schedule and focus journeys.

Before another expensive full database run, execute these four real/controlled specs in a focused native diagnostic on the exact corrected candidate with ordinary application bootstrap and both native projects. Inspect its current-source trace/screenshots and retain its result. Only the required full Foundation run can accept the eventual PR candidate; neither these definitions, jsdom checks nor an informative diagnostic substitute for that gate.
