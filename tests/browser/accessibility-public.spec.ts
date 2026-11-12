import { expect, test } from '@playwright/test';
import { expectAccessible, expectFocusInside, expectNoHorizontalOverflow, tabTo } from './accessibility-helpers';
import { fixtureTrack, policyText, query, storefrontFixture } from './storefront-fixture';

// Existing transport supplies labeled synthetic data to the real built React/CSS.
// These are public UI checks, not backend publication, payment or entitlement proof.
test.use({ serviceWorkers: 'block' });

test('public catalog and keyboard-opened detail have no automated WCAG violations', async ({ page }, testInfo) => {
  await storefrontFixture(page);
  await page.goto('/' + query);
  const article = page.getByRole('article', { name: fixtureTrack.title, exact: true });
  await expect(article).toBeVisible();
  await expect(page.getByRole('searchbox', { name: 'Search tracks', exact: true })).toHaveValue('Synthetic');
  await expect(page.getByRole('combobox', { name: 'Sort tracks', exact: true })).toHaveValue('title');
  await expectAccessible(page, testInfo, 'populated-catalog');

  const title = article.getByRole('link', { name: fixtureTrack.title, exact: true });
  await title.focus();
  await title.press('Enter');
  await expect(page).toHaveURL(fixtureTrack.shareUrl + query);
  const heading = page.getByRole('heading', { name: fixtureTrack.title, level: 1 });
  await expect(heading).toBeVisible();
  await expect(heading).toBeFocused();
  await expect(page.getByRole('link', { name: 'Back to results', exact: true })).toHaveAttribute('href', '/' + query);
  await expectAccessible(page, testInfo, 'track-detail');
});

test('empty results retain an actionable search surface before the accessibility scan', async ({ page }, testInfo) => {
  await storefrontFixture(page, { tracks: [] });
  await page.goto('/' + query);
  await expect(page.getByRole('heading', { name: 'NO MATCHES. KEEP EXPLORING.', exact: true })).toBeVisible();
  await expect(page.getByRole('searchbox', { name: 'Search tracks', exact: true })).toHaveValue('Synthetic');
  await expect(page.getByRole('button', { name: 'Reset filters', exact: true })).toBeEnabled();
  await expect(page.getByRole('article', { name: fixtureTrack.title, exact: true })).toHaveCount(0);
  await expectAccessible(page, testInfo, 'empty-results');
});

test('license error and loaded dialog preserve keyboard containment and restored focus', async ({ page }, testInfo) => {
  await storefrontFixture(page, { failFirstTerms: true });
  await page.goto(fixtureTrack.shareUrl + query);
  await expect(page.getByRole('heading', { name: fixtureTrack.title, level: 1 })).toBeVisible();
  const launch = page.getByRole('button', { name: 'Read terms & choose', exact: true }).first();
  await launch.focus();
  await launch.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.', exact: true });
  await expect(dialog).toBeVisible();
  await expectFocusInside(dialog);
  await expect(dialog.getByRole('alert')).toContainText('These terms could not be loaded.');
  await expectAccessible(page, testInfo, 'license-load-error');

  const retry = dialog.getByRole('button', { name: 'Retry terms', exact: true });
  await tabTo(page, retry);
  await retry.press('Enter');
  const terms = dialog.getByRole('region', { name: 'Published license text', exact: true });
  await expect(terms).toHaveText(policyText(0));
  await expect(dialog.getByRole('alert')).toHaveCount(0);
  const close = dialog.getByRole('button', { name: 'Close dialog', exact: true });
  const add = dialog.getByRole('button', { name: /^Add license/ });
  await expect(add).toBeEnabled();
  await close.focus();
  await page.keyboard.press('Shift+Tab');
  await expect(add).toBeFocused();
  await expectFocusInside(dialog);
  await page.keyboard.press('Tab');
  await expect(close).toBeFocused();
  await page.keyboard.press('Tab');
  await expectFocusInside(dialog);
  await expectAccessible(page, testInfo, 'license-loaded');
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(launch).toBeFocused();
});

test('320px detail, full terms and cart reflow without horizontal scrolling', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 320, height: 800 });
  await storefrontFixture(page);
  await page.goto(fixtureTrack.shareUrl + query);
  await expect(page.getByRole('heading', { name: fixtureTrack.title, level: 1 })).toBeVisible();
  await expectNoHorizontalOverflow(page);
  await expectAccessible(page, testInfo, '320-detail');

  await page.getByRole('button', { name: 'Read terms & choose', exact: true }).first().click();
  const license = page.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.', exact: true });
  const terms = license.getByRole('region', { name: 'Published license text', exact: true });
  await expect(terms).toHaveText(policyText(0));
  await expectNoHorizontalOverflow(page);
  const width = await license.evaluate(element => ({ scroll: element.scrollWidth, client: element.clientWidth }));
  expect(width.scroll, 'the modal must not hide horizontally clipped terms or controls').toBeLessThanOrEqual(width.client + 1);
  await terms.focus();
  await terms.press('End');
  await expect.poll(() => terms.evaluate(element => Math.abs(element.scrollHeight - element.clientHeight - element.scrollTop))).toBeLessThanOrEqual(1);
  await expectAccessible(page, testInfo, '320-license');
  await license.getByRole('button', { name: /^Add license/ }).click();
  await expect(license).toBeHidden();

  const openCart = page.getByRole('button', { name: 'Open cart, 1 item', exact: true });
  await openCart.focus();
  await openCart.press('Enter');
  const cart = page.getByRole('dialog', { name: 'YOUR SELECTIONS.', exact: true });
  await expect(cart.getByRole('heading', { name: fixtureTrack.title, exact: true })).toBeVisible();
  await expect(cart.getByRole('button', { name: `Remove ${fixtureTrack.title} from cart`, exact: true })).toBeEnabled();
  await expectNoHorizontalOverflow(page);
  await expectAccessible(page, testInfo, '320-cart');
  await page.screenshot({ path: testInfo.outputPath('accessibility-320-cart.png'), fullPage: false });
  await page.keyboard.press('Escape');
  await expect(cart).toBeHidden();
  await expect(openCart).toBeFocused();
});

test('native player controls and queue are reachable and operable with the keyboard', async ({ page }, testInfo) => {
  await storefrontFixture(page);
  await page.goto('/' + query);
  const play = page.getByRole('article', { name: fixtureTrack.title, exact: true }).getByRole('button', { name: `Play ${fixtureTrack.title}`, exact: true });
  await play.focus();
  await play.press('Enter');
  const player = page.getByRole('complementary', { name: 'Audio preview player', exact: true });
  const pause = player.getByRole('button', { name: 'Pause preview', exact: true });
  await expect(pause).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__nativePreviews[0]?.currentTime ?? 0)).toBeGreaterThan(0);
  await tabTo(page, pause);
  await pause.press('Space');
  await expect.poll(() => page.evaluate(() => window.__nativePreviews[0].paused)).toBe(true);
  const seek = player.getByRole('slider', { name: 'Seek preview', exact: true });
  await expect(seek).toBeEnabled();
  await tabTo(page, seek);
  const position = Number(await seek.inputValue());
  await seek.press('ArrowRight');
  await expect.poll(async () => Number(await seek.inputValue())).toBeGreaterThan(position);
  await expect(seek).toBeFocused();

  const openQueue = player.getByRole('button', { name: 'Queue & loop', exact: true });
  await tabTo(page, openQueue);
  await openQueue.press('Enter');
  await expect(openQueue).toHaveAttribute('aria-expanded', 'true');
  const queue = player.getByRole('region', { name: 'Preview queue and loop controls', exact: true });
  await expect(queue).toBeVisible();
  const autoNext = queue.getByRole('checkbox', { name: 'Play next automatically', exact: true });
  await expect(autoNext).not.toBeChecked();
  await tabTo(page, autoNext, true);
  await autoNext.press('Space');
  await expect(autoNext).toBeChecked();
  await expectAccessible(page, testInfo, 'paused-player-and-queue');
  await page.keyboard.press('Escape');
  await expect(queue).toBeHidden();
  await expect(openQueue).toBeFocused();
  await expect(openQueue).toHaveAttribute('aria-expanded', 'false');
  expect(await page.evaluate(() => window.__nativePreviews.length)).toBe(1);
});

test('reduced motion removes animated transitions while terms remain usable', async ({ page }, testInfo) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await storefrontFixture(page);
  await page.goto(fixtureTrack.shareUrl + query);
  await expect(page.getByRole('heading', { name: fixtureTrack.title, level: 1 })).toBeVisible();
  expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto');
  const launch = page.getByRole('button', { name: 'Read terms & choose', exact: true }).first();
  const motion = await launch.evaluate(element => {
    const style = getComputedStyle(element);
    return { transition: style.transitionDuration, animation: style.animationName };
  });
  expect(motion.transition.split(',').every(duration => Number.parseFloat(duration) === 0)).toBe(true);
  expect(motion.animation.split(',').every(name => name.trim() === 'none')).toBe(true);
  await launch.focus();
  await launch.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.', exact: true });
  await expect(dialog.getByRole('region', { name: 'Published license text', exact: true })).toHaveText(policyText(0));
  await expectAccessible(page, testInfo, 'reduced-motion-license');
  await page.keyboard.press('Escape');
  await expect(launch).toBeFocused();
});

test('forced colors retain visible keyboard focus and usable license controls', async ({ page }, testInfo) => {
  await page.emulateMedia({ forcedColors: 'active' });
  await storefrontFixture(page);
  await page.goto(fixtureTrack.shareUrl + query);
  await expect(page.getByRole('heading', { name: fixtureTrack.title, level: 1 })).toBeVisible();
  // Unsupported emulation must fail explicitly rather than silently count as coverage.
  expect(await page.evaluate(() => matchMedia('(forced-colors: active)').matches)).toBe(true);
  const launch = page.getByRole('button', { name: 'Read terms & choose', exact: true }).first();
  await launch.focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  await expect(launch).toBeFocused();
  const paint = await launch.evaluate(element => {
    const style = getComputedStyle(element);
    return { outline: style.outlineStyle, width: Number.parseFloat(style.outlineWidth), adjustment: style.forcedColorAdjust };
  });
  expect(paint.outline).not.toBe('none');
  expect(paint.width).toBeGreaterThanOrEqual(2);
  expect(paint.adjustment).not.toBe('none');
  await expect(launch).toBeFocused();
  await launch.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.', exact: true });
  await expect(dialog.getByRole('region', { name: 'Published license text', exact: true })).toHaveText(policyText(0));
  const extended = dialog.getByRole('radio', { name: /Extended test license/ });
  await tabTo(page, dialog.getByRole('radio', { name: /Standard test license/ }));
  await page.keyboard.press('ArrowDown');
  await expect(extended).toBeChecked();
  await expect(dialog.getByRole('region', { name: 'Published license text', exact: true })).toHaveText(policyText(1));
  // axe-core 4.14 has no forced-colors support: it reports the author's brand colours (silver on a forced white Canvas),
  // a pair that is never painted (run 43122303001 and 43127350343; evidence in docs/verification/browser-focused-20261112.md).
  // So this one scan disables color-contrast only, and the contrast under forced colors is asserted from the computed
  // system colours instead. Every other axe rule still runs on this state.
  const painted = await dialog.evaluate(element => {
    const parse = (value: string) => (value.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
    const luminance = ([r, g, b]: number[]) => 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
    const linear = (channel: number) => { const c = channel / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    const background = parse(getComputedStyle(element).backgroundColor);
    return ['.eyebrow', '.modal-description', '.offer-files', '.offer-terms', '.fine-print', '.text-link', '.license-source'].map(selector => {
      const node = element.querySelector(selector);
      if (!node) return { selector, missing: true as const };
      const color = getComputedStyle(node).color;
      const [lighter, darker] = [luminance(parse(color)), luminance(background)].sort((a, b) => b - a);
      return { selector, color, ratio: (lighter + 0.05) / (darker + 0.05) };
    });
  });
  for (const sample of painted) {
    expect(sample, `${sample.selector} must exist in the forced-colors dialog`).not.toHaveProperty('missing');
    // Brand text colours (silver #C9D0D3, muted #AAB4BD, turquoise #00B8D9) must not survive forced colors.
    expect(['rgb(201, 208, 211)', 'rgb(170, 180, 189)', 'rgb(0, 184, 217)'], `${sample.selector} kept an author colour`).not.toContain((sample as { color: string }).color);
    expect((sample as { ratio: number }).ratio, `${sample.selector} system-colour contrast`).toBeGreaterThanOrEqual(4.5);
  }
  await expectAccessible(page, testInfo, 'forced-colors-license', { disableRules: ['color-contrast'] });
  // Native/system paint still needs human inspection; computed CSS is not contrast proof.
  await page.screenshot({ path: testInfo.outputPath('accessibility-forced-colors-license.png'), fullPage: false });
});
