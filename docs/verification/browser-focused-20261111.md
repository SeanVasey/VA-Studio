# Focused browser feedback, 2026-11-11

Source: `main` at `101c10e831feceaad83734ad16ac023c8c1b19d7` (#338, the fix for specs that collected no tests). These are
the first real browser results since that import arrived with #268 on 2026-11-09 (and full CI has not run since 2026-10-10).
Both runs were manual "Focused development feedback" dispatches (`focused-feedback.yml`, engine sqlite, informative only: not merge
or release acceptance).

| Suite | Run | Job | Selected | Result |
| --- | --- | --- | --- | --- |
| `browser` | 43115861337 | 130843647476 | 32 spec files, 118 tests (chromium-desktop then webkit-mobile) | Stopped by the runner's 720 s budget (`scripts/ci/focused-tests.py`) after 26 tests completed: 22 passed, 4 failed, 1 in flight, 91 not run (no webkit test ran). No flaky, no skipped. |
| `accessibility-browser` | 43115864825 | 130843704081 | `accessibility-public.spec.ts`, 14 cases | 3 passed, 11 failed, 0 flaky, 0 skipped. |

## `browser`: the 4 failures

Three of them waited 120-150 s each on the same helper, which is why the budget ran out at test 27.

| # | Project | Spec: test | First error | Cause | Class | Fix |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | chromium-desktop | `bulk-track-metadata.spec.ts:202` bulk metadata choices review exact rows... | `page.waitForResponse: Test timeout of 150000ms exceeded` at `notification-sync.ts:34` (via `createDraft`, spec line 111) | Filament 5.11 (lock bump 20c7a91e, 2026-11-07) delivers a sent notification with a direct `pullNotifications(batches)` call on the Notifications component (`vendor/filament/notifications/resources/js/components/notifications.js`) and no longer dispatches `notificationsSent`; the helper only matched `__dispatch notificationsSent`. The record had been created (page snapshot shows it). | A/D (library change, helper stale) | `notification-match.ts` matches `pullNotifications` with an array argument for the sent step, still scoped to the `Filament\Livewire\Notifications` component and POST `/update`; the close step is unchanged. Vitest `browser-notification-match.test.ts` pins the wire format. |
| 2 | chromium-desktop | `bulk-track-metadata.spec.ts:306` a competing normal metadata edit rejects the entire reviewed batch... | same, 120000 ms | same | A/D | same |
| 3 | chromium-desktop | `editorial-content.spec.ts:100` seller edits persisted pages... | `expect(getByRole('row')...getByRole('button', { name: 'Edit as new draft' })).toBeVisible()` not found, `site-release-row.ts:48` (reached from spec line 234) | Filament 5.11's dropdown item view sets `role="menuitem"` on every entry (`vendor/filament/support/resources/views/components/dropdown/list/item.blade.php`), so the More menu actions are menu items. The snapshot shows `menuitem "Edit as new draft"`. The standalone `Publish release` action stays a button. | A | `releaseMenuItem()` in `site-release-row.ts` (role `menuitem`) used by `openReleaseMenu`/`releaseMenuAction`, `site-content.spec.ts`, and the "Schedule publication is absent" check in `site-schedule.spec.ts`, which would otherwise have passed vacuously. The synthetic fixture in `site-release-menu-readiness.spec.ts` now carries `role="menuitem"` like the real markup. |
| 4 | chromium-desktop | `license-template-authoring.spec.ts:72` operator creates and edits template identity... | `page.waitForResponse: Test timeout of 120000ms exceeded`, `notification-sync.ts:34` (spec line 116) | as 1 | A/D | as 1 |

Unverified locally: the browser could not run. The close step (`notificationClosed` as a `__dispatch`) is unchanged and assumed to still
hold; the first confirming run will show it. `site-content.spec.ts` presses Tab to reach the menu item, and Filament sets `tabindex="-1"`
on menu items, so that keyboard test may need a further look in the confirming run.

## `accessibility-browser`: the 11 failures

| # | Project | Test | First error | Class | Fix |
| --- | --- | --- | --- | --- | --- |
| 1, 7 | chromium, webkit | `:9` public catalog and keyboard-opened detail | axe `label-content-name-mismatch` (serious, WCAG 2.5.3): `<button class="cart-toggle" aria-label="Open cart, 0 items">` | C | see below |
| 2, 8 | chromium, webkit | `:29` empty results | same cart toggle | C | same |
| 3, 9 | chromium, webkit | `:39` license error and loaded dialog | `toBeFocused` on the `Add license` button: received `inactive` after Shift+Tab from the Close button (`spec :63`) | C (focus containment) | `Modal` wraps Tab and Shift+Tab. A native `<dialog>` makes the page inert but lets focus leave to the browser UI at either end. |
| 4, 10 | chromium, webkit | `:75` 320px detail, full terms and cart | label-content-name-mismatch on the cart toggle and on `<button aria-label="Play Synthetic browser track">Play preview</button>` | C | see below |
| 5, 11 | chromium, webkit | `:111` native player controls and queue | cart toggle | C | same |
| 6 | chromium | `:174` forced colors | `color-contrast` (serious), 11 nodes in the license dialog: foreground `#c9d0d3` / `#aab4bd` / `#00b8d9` on background `#ffffff` | open | Not changed, see below. |

(Webkit-mobile did not report the forced-colors case; the run shows 11 failures of 14 cases, so webkit's forced-colors test passed.)

Fixes for label in name:
- Cart toggle (`Storefront.tsx`, `app.css`): the visible text was `Cart` + `00` against the name `Open cart, 0 items` (and only `00` below the mobile breakpoint, where `.cart-text` is hidden). The count now renders its real value (`0`, `1`) with the leading zero drawn by `.cart-count[data-pad]::before`, and a space separates it from `Cart`. The look is unchanged; the accessible name is unchanged (so no test names changed); the visible words are a contiguous part of it.
- Track detail play button (`TrackDetail.tsx`): name is now `Play preview of <title>` / `Pause preview of <title>` so the visible `Play preview` / `Pause preview` is inside it. Assertions that targeted that button by its old name were updated (`track-detail.test.tsx`, `design-preview.test.tsx`, `storefront.spec.ts`).
- Regression: `tests/frontend/label-in-name.test.tsx` applies the axe rule's contiguous-token comparison to both controls (it fails on the previous code).

Open, not changed: the forced-colors contrast report. The foreground values are the brand tokens (`--va-text` silver `#C9D0D3` and the muted/accent tokens) while axe resolved the dialog background to `#ffffff`, the forced `Canvas`. The dialog's own `color`/`background` come from `.modal`, so under real forced colors both are replaced by system colors and the reported pair may not be what a user sees; axe-core is known to mis-resolve some forced-colors cases. Whether this is a real defect (author `color` surviving forced colors) or a tool artifact needs a look at the screenshot/trace in artifact 13898562193 of run 43115864825 or on Windows high contrast. Weakening the spec (excluding the rule) is not acceptable and no brand colour was changed.

## Local verification

`npx vitest run` (full), `npx tsc --noEmit` (only the two known `@axe-core/playwright` errors), `npx playwright test --list` on the touched browser specs (collection proven; `accessibility-public.spec.ts` cannot collect locally because the axe package is not installed), `python3 scripts/ci/test-focused-tests.py`, `python3 scripts/ci/test-phpunit-shards.py`. No PHP was touched. Mutations: removing the Modal key handler fails 2 of 3 Modal tests; making the matcher require the old `__dispatch` fails the Filament 5.11 case.

## Confirming dispatches

1. `accessibility-browser` (engine sqlite): expect the cart, play-name and focus-containment cases to pass; the forced-colors case may still fail (open above).
2. The old `browser` suite was split by the follow-up commit into nine suites that each fit the 720 s cap and run both projects: `browser-storefront`, `browser-customer`, `browser-site`, `browser-checkout`, `browser-delivery`, `browser-payment-exceptions`, `browser-operator-tracks`, `browser-operator-uploads`, `browser-operator-publication` (engine sqlite). Dispatch all nine; webkit-mobile has produced no browser result yet.
3. A run that hits the cap is now recorded as an incomplete failure. Across the nine suites the evidence should total 118 executed cases.
