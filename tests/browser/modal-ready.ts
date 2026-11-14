import { expect, type Locator } from '@playwright/test';

/**
 * Wait until an opened Filament modal has moved focus inside itself.
 *
 * The modal's focus trap takes focus shortly after its heading becomes visible. A `fill()` that starts in that gap
 * focuses the field and then loses focus to the trap before the text is inserted, so the first field stays empty and
 * the browser's required-field check blocks the submit (Foundation 43485590174, webkit-mobile, Create Track).
 */
export async function modalReady(dialog: Locator) {
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
}
