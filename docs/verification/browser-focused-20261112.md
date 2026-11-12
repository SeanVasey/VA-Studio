# Focused browser feedback, round two, 2026-11-12

Source: `main` at `f45755ab1a2c652f36076ed02c9e5cbf125cace2` (#340). Ten manual "Focused development feedback" dispatches
(engine sqlite, informative only). First results for webkit-mobile and for most of the 118 tests.

| Suite | Run | Conclusion | Cases (chromium + webkit) | Passed / failed | Test step | Cap |
| --- | --- | --- | --- | --- | --- | --- |
| `accessibility-browser` | 43122303001 | failure | 14 (7 + 7) | 11 / 3: chromium 2 (320 px, forced colors), webkit 1 (320 px) | 110 s | no |
| `browser-storefront` | 43122306765 | success | 14 | 14 / 0 | 94 s | no |
| `browser-customer` | 43122310312 | failure | 18 | 16 / 2: `product-member-refresh` both projects | 474 s | no |
| `browser-site` | 43122314411 | failure | 42 (1 skipped) | 24 / 17: chromium 4 (menu), webkit 13 (9 inquiry fixture, 2 menu, 1 editorial, 1 more) | 420 s | no |
| `browser-checkout` | 43122319235 | success | 6 | 6 / 0 | 72 s | no |
| `browser-delivery` | 43122323398 | success | 8 | 8 / 0 | 122 s | no |
| `browser-payment-exceptions` | 43122327400 | success | 4 | 4 / 0 | 126 s | no |
| `browser-operator-tracks` | 43122331169 | success | 10 | 10 / 0 | 450 s | no |
| `browser-operator-uploads` | 43122335098 | failure | 8 | 4 / 4: both specs, both projects | 265 s | no |
| `browser-operator-publication` | 43122338831 | failure | 8 | 7 / 1: webkit `track-publication-guard` | 206 s | no |

No suite reached the 720 s cap (longest 474 s). 132 cases ran in total (118 former `browser` cases plus the 14 accessibility ones).
Per-project split of the passing and failing cases is in the failure table; the one skipped case is in `browser-site`: `site-content.spec.ts:101` skips itself on webkit-mobile by design (`test.skip` for a desktop-only keyboard check).

## Failures

| Suite | Spec: test | Project | First error | Cause | Class | Action |
| --- | --- | --- | --- | --- | --- | --- |
| `browser-site` | `editorial-content`, `site-content` (2), `site-schedule`: the release More menu | chromium (4), webkit (3) | `menuitem "Edit as new draft"` not found; the snapshot shows plain `button`s with no `menu` role | The app's `dropdown-focus.blade.php` replaced Filament's own `observer` and `syncAria` for every dropdown. Filament 5.11 sets menu/menuitem/group roles and `aria-haspopup="menu"` in `setUpMenu()` and re-runs it from that observer after a Livewire morph, so menus morphed in after page load lost their roles and Filament's arrow-key navigation. | B (admin UI accessibility; no payment, licence, entitlement or auth) | The shim now leaves a component alone when it has `setUpMenu` (Filament 5.11+); older Filament still gets the binding. New synthetic Playwright test pins it (fails without the guard; ran locally). |
| `browser-site` | `contact-inquiry` (8), `contact-inquiry-persistence`, `inquiry-conversation`, `editorial-content` (`/about` 200 instead of 404) | webkit | `php tests/browser/prepare-contact-inquiry.php ... webkit-mobile` failed (details are suppressed by design) and an old public release was still live | Cascade: the chromium site specs above failed before restoring the original site, so the shared database no longer matched what the webkit fixtures require. | D (follows the first row) | None directly; confirm after the menu fix. If webkit still fails, the fixture's hidden exception must be surfaced locally. |
| `browser-operator-uploads` | `resumable-kit-upload`: Finish upload | chromium, webkit | status stays "All bytes received. Choose Finish upload ..." after Enter on the focused button | The server-rendered `x-filament::button disabled` carries `aria-disabled="true"` and `fi-disabled`. The upload scripts enabled it by setting only `.disabled`; Filament 5.11's window click listener (`disabled-button.js`) swallows every click on `.fi-btn[aria-disabled="true"]`, so Finish (and Cancel, Pause) never worked and were announced as dimmed. | B (admin UI; the upload endpoints and authorisation are untouched) | New `resources/js/admin/disabled-button.ts` keeps `disabled`, `aria-disabled` and `fi-disabled` in step; both upload scripts use it. Vitest pins it. |
| `browser-operator-uploads` | `resumable-media-upload` | chromium, webkit | `getByRole('combobox', { name: 'Track' })` timed out (60 s) | Filament 5.11 select: the searchable select's trigger is a `button`; the `combobox` role moved to its search input (`vendor/filament/support/resources/js/utilities/select.js`). | A | Spec uses the button, then the search combobox (name starts with `Search`). Not run locally. |
| `browser-customer` | `product-member-refresh` | chromium, webkit | same combobox selector | as above | A | same change |
| `browser-operator-publication` | `track-publication-guard:27` | webkit | `closeDialog`: `expect(Cancel).toBeFocused()` received `inactive` (Edit dialog, Cancel at the very bottom edge of the viewport) | Not determined. Chromium passes; every other webkit focus assertion passes. | D, unresolved | Not changed; re-dispatch to see whether it reproduces. |
| `accessibility-browser` | `:75` 320 px detail, full terms and cart | chromium, webkit | axe `color-contrast` on `Check checkout availability` (fg `#eaedee`/`#e1e6e8` on bg `#347888`/`#327a8b`, 4.26/3.88) | The button had just appeared under the pointer left by the previous click, so axe sampled the 180 ms hover transition (`--va-action` to `--va-action-hover`) at an in-between colour. The final hover pair (white on `#397281`) is fine and no token was involved; the two engines reported different in-between values. | D | `expectAccessible` waits for finite animations and transitions to finish before scanning. |
| `accessibility-browser` | `:174` forced colors | chromium | `color-contrast`, 11 nodes in the licence dialog, fg `#c9d0d3`/`#aab4bd`/`#00b8d9` on `#ffffff` | Reproduces identically. Judged an axe/Chromium artifact, not a defect (below). | tool artifact | Not changed; the spec still fails. |

## Forced colors (`accessibility-public.spec.ts:174`)

It reproduces exactly as in run 43115864825. Evidence that it is not a rendering defect:

- The Chromium failure screenshot (artifact 13901227587) shows the licence dialog in forced colors with black text on a white dialog and system-coloured controls; every label is legible. WebKit has no forced-colors emulation, so its case passes.
- A local Chromium (forced colors emulated, the app's real `theme.css` and `app.css`, a modal `<dialog>` opened with `showModal()`) gives `color: rgb(0, 0, 0)` and a white `background-color` for the dialog and its text, `forced-color-adjust: auto`; the author silver is not in play. No rule in `resources/css` sets `forced-color-adjust: none`; `.modal` only declares ordinary `color` and `background` from the brand tokens.
- axe-core 4.14 contains no forced-colors handling. It reported the author colour (`--va-text` silver) against a white background, a pair that exists in no rendering, so the check measures values the user never sees.

Whether CI's Chromium reports the author `color` through `getComputedStyle` while painting the forced one could not be confirmed from the artifacts (traces hold DOM snapshots, not computed styles). Nothing was changed in the CSS and no rule was excluded; leaving the case red until the owner decides how to treat axe under forced colors (for example comparing only the state with system colors, which WCAG 1.4.3 does not require the author to meet) is deliberate.

## Re-dispatch to confirm
`browser-site`, `browser-customer`, `browser-operator-uploads`, `browser-operator-publication`, `accessibility-browser` (expect only the forced-colors case red). The six other suites passed unchanged; no need to repeat them.

## Round three: confirming runs on `main` 1f11bd94 (#343), sqlite, none near the cap

| Suite | Run | Result | Chromium | Webkit | Test step |
| --- | --- | --- | --- | --- | --- |
| `accessibility-browser` | 43127350343 | fail | 6 pass / 1 fail (forced colors) | 6 pass / 1 fail (`:75` 320 px cart) | 93 s |
| `browser-site` | 43127354078 | fail | 22 pass | 20 pass / 1 fail (`contact-inquiry-persistence`) / 1 skipped by design | 432 s |
| `browser-customer` | 43127358316 | fail | 8 pass / 1 fail | 8 pass / 1 fail (both `product-member-refresh`) | 550 s |
| `browser-operator-uploads` | 43127362460 | fail | 3 pass / 1 fail | 3 pass / 1 fail (both `resumable-media-upload`) | 273 s |
| `browser-operator-publication` | 43127366463 | pass | 4 pass | 4 pass | 236 s |

Answers:
1. The upload Finish/Cancel/Pause fix worked: `resumable-kit-upload` passes on both projects. The dropdown shim fix worked: every release-menu test passes (the chromium keyboard test included).
2. The webkit contact/inquiry failures were the cascade and are gone (20 of 21 webkit `browser-site` cases pass). One new, unrelated failure surfaced (below).
3. `track-publication-guard:27` on webkit did not reproduce (`browser-operator-publication` 8/8). Not a confirmed defect; treat the first failure as a flake.
4. Forced colors was not the only accessibility failure: webkit `:75` still failed on the 320 px cart button.

| Suite | Spec: test | Project | First error | Class | Fix |
| --- | --- | --- | --- | --- | --- |
| `browser-operator-uploads`, `browser-customer` | `resumable-media-upload`, `product-member-refresh` | both | `getByRole('combobox', { name: /^Search/ })` not found | A | Round two guessed the search input's name. The page snapshot gives `combobox "Track*: Search"`, so both specs now match `/^Track\b.*: Search$/`. |
| `browser-site` | `contact-inquiry-persistence`: inbox row | webkit | strict mode violation: `getByText('Service request')` matched 2 rows | D | The inbox is shared across projects and specs (chromium's unarchived `contact-inquiry` rows remain), so the subject is now asserted on the row of this test's own receipt. |
| `accessibility-browser` | `:75` 320 px detail, full terms and cart | webkit | `color-contrast` on `Check checkout availability`: fg `#3c5c65` on bg `#0da9c6` (2.57) | D | Still a transition sampled mid-way (hover left under the pointer): the round-two wait ran before the transition began. The helper now moves the pointer off the page, lets two frames pass, then waits for finite animations, repeating up to five rounds. |
| `accessibility-browser` | `:174` forced colors | chromium | as round two | tool artifact | See the separate commit. |
