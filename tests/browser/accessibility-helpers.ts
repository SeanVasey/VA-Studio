import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page, type TestInfo } from '@playwright/test';

export const accessibilityTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/** Scan the whole rendered document. No excludes, disabled rules or result filtering. */
export async function expectAccessible(page: Page, testInfo: TestInfo, state: string) {
  // Scan the settled page: a hover or focus transition still running would be sampled at an in-between colour
  // (run 43122303001: a button mid-way between its action and hover colours). Finite animations end on their own.
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(document.getAnimations()
      .filter(animation => Number.isFinite(animation.effect?.getComputedTiming().endTime as number))
      .map(animation => animation.finished.catch(() => undefined)));
  });
  const results = await new AxeBuilder({ page }).withTags(accessibilityTags).analyze();
  await testInfo.attach(`axe-${state}`, {
    body: JSON.stringify({
      state,
      tags: accessibilityTags,
      url: results.url,
      engine: results.testEngine,
      environment: results.testEnvironment,
      passedRules: results.passes.map(result => result.id),
      violations: results.violations,
      incomplete: results.incomplete,
    }, null, 2),
    contentType: 'application/json',
  });
  expect(results.passes.length, `${state}: axe must execute checks`).toBeGreaterThan(0);
  expect(results.violations, `${state}: full-document WCAG violations`).toEqual([]);
  // Incomplete checks remain in the report for human review; they are not passes.
}

export async function expectNoHorizontalOverflow(page: Page) {
  const dimensions = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(dimensions.document, 'document must reflow without horizontal scrolling').toBeLessThanOrEqual(dimensions.viewport + 1);
  expect(dimensions.body, 'body must reflow without horizontal scrolling').toBeLessThanOrEqual(dimensions.viewport + 1);
}

/** Reach a control through native tab order, without assigning focus to that target. */
export async function tabTo(page: Page, target: Locator, backwards = false) {
  for (let step = 0; step < 30; step++) {
    if (await target.evaluate(element => element === document.activeElement)) return;
    await page.keyboard.press(backwards ? 'Shift+Tab' : 'Tab');
  }
  await expect(target, 'control must be reachable within the bounded public-page tab traversal').toBeFocused();
}

export async function expectFocusInside(dialog: Locator) {
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
}
