import { test, expect } from '@playwright/test';

test('public navigation offers branded offline retry without retaining catalog or customer responses', async ({ page, context }, testInfo) => {
  const pageErrors: string[] = [];
  page.on('pageerror', error => pageErrors.push(error.message));
  const address = '/?q=synthetic-offline-retry';
  await page.goto(address);
  await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
  // These real reads may be unauthorized, but none may enter the offline cache.
  await page.evaluate(async () => {
    await Promise.all(['/api/catalog', '/orders/history', '/account'].map(path => fetch(path)));
  });
  const cacheKeys = async () => page.evaluate(async () => {
    const names = await caches.keys();
    const cache = await caches.open('vasey-audio-public-offline-v1');
    return { names, urls: (await cache.keys()).map(request => new URL(request.url).pathname).sort() };
  });
  const expectedPaths = ['/offline.html', '/brand/theme.css', '/brand/vasey-audio-logo.png',
    '/brand/fonts/reddit-sans-latin-wght-normal.woff2', '/brand/fonts/bebas-neue-latin-400-normal.woff2'].sort();
  expect(await cacheKeys()).toEqual({ names: ['vasey-audio-public-offline-v1'], urls: expectedPaths });

  await context.setOffline(true);
  await page.reload();
  await expect(page).toHaveURL(new RegExp('\\?q=synthetic-offline-retry$'));
  await expect(page.getByRole('heading', { name: 'We couldn’t reach the store' })).toBeVisible();
  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Back to the store' })).toHaveAttribute('href', '/');
  const logo = page.getByRole('img', { name: 'VASEY.AUDIO' });
  await expect(logo).toBeVisible();
  expect(await logo.evaluate(image => (image as HTMLImageElement).naturalWidth)).toBe(420);
  await page.evaluate(() => document.fonts.ready);
  expect(await page.evaluate(() => document.fonts.check('16px "Reddit Sans"') && document.fonts.check('44px "Bebas Neue"'))).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('public-offline-recovery.png'), fullPage: true });

  // Keyboard retry remains at the original address while disconnected.
  await page.getByRole('button', { name: 'Try again' }).focus();
  await expect(page.getByRole('button', { name: 'Try again' })).toBeFocused();
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.keyboard.press('Enter')]);
  await expect(page.getByRole('heading', { name: 'We couldn’t reach the store' })).toBeVisible();
  await expect(page).toHaveURL(new RegExp('\\?q=synthetic-offline-retry$'));
  expect(await cacheKeys()).toEqual({ names: ['vasey-audio-public-offline-v1'], urls: expectedPaths });

  // Inertia/API reads are not converted into HTML or synthetic success.
  expect(await page.evaluate(async () => {
    try { await fetch('/api/catalog'); return 'unexpected response'; } catch { return 'network failure'; }
  })).toBe('network failure');
  await context.setOffline(false);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.getByRole('button', { name: 'Try again' }).click()]);
  await expect(page.locator('.site-header')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'We couldn’t reach the store' })).toHaveCount(0);
  await expect(page).toHaveURL(new RegExp('\\?q=synthetic-offline-retry$'));
  expect(await cacheKeys()).toEqual({ names: ['vasey-audio-public-offline-v1'], urls: expectedPaths });
  expect(pageErrors).toEqual([]);
});
