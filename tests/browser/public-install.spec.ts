import { test, expect } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { fixtureTrack, query, storefrontFixture } from './storefront-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

/** Real static files, built UI, authenticated preview endpoint, Inertia and native audio.
 * Only the existing storefront/WAV transport is synthetic; no installer event or OS installation is simulated.
 */
test('public installation guidance and real assets survive private preview navigation without replacing the audio owner', async ({ page }, testInfo) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  const privatePath = '/admin/site-releases/1/preview';
  // Read the existing original snapshot: no new release, publication or readiness mutation.
  const privateResponse = await page.request.get(privatePath);
  expect(privateResponse.status()).toBe(200);
  expect(privateResponse.headers()['cache-control']).toContain('no-store');
  expect(privateResponse.headers()['referrer-policy']).toBe('no-referrer');
  expect(await privateResponse.text()).not.toContain('rel="manifest"');
  const track = { ...fixtureTrack, shareUrl: privatePath };
  await storefrontFixture(page, { tracks: [track] });
  // Bypass the UI-only catalog fixture for this real authorized controller response.
  await page.route('**/admin/site-releases/1/preview*', async route => {
    expect(route.request().method()).toBe('GET');
    await route.continue();
  });
  if (testInfo.project.name === 'webkit-mobile') await page.setViewportSize({ width: 320, height: 844 });
  await page.goto('/' + query);
  const summary = page.locator('summary').filter({ hasText: 'Add VASEY.AUDIO to your device' });
  await expect(summary).toBeVisible();
  await expect(page.locator('head link[rel="manifest"]')).toHaveCount(1);
  await expect(page.locator('head link[rel="manifest"]')).toHaveAttribute('href', '/manifest.webmanifest');
  await expect(page.locator('head link[rel="apple-touch-icon"]')).toHaveCount(1);
  await expect(page.locator('head meta[name="theme-color"]')).toHaveCount(1);
  await expect(page.locator('head meta[name="theme-color"]')).toHaveAttribute('content', '#052e3a');
  const manifestResponse = await page.request.get('/manifest.webmanifest');
  expect(manifestResponse.status()).toBe(200);
  const manifest = await manifestResponse.json();
  expect(manifest).toEqual({ id: '/', name: 'VASEY.AUDIO', short_name: 'VASEY.AUDIO', start_url: '/', scope: '/', display: 'standalone', background_color: '#052e3a', theme_color: '#052e3a', icons: [
    { src: '/brand/app-icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
    { src: '/brand/app-icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
  ] });
  for (const [path, size] of [['/brand/app-icon-192.png', 192], ['/brand/app-icon-512.png', 512], ['/brand/apple-touch-icon.png', 180]] as const) {
    const response = await page.request.get(path); expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('image/png');
    const bytes = await response.body();
    expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
    expect(bytes.readUInt32BE(16)).toBe(size); expect(bytes.readUInt32BE(20)).toBe(size);
  }
  // Observe only the new guidance/navigation interactions after the ordinary admin/public shell.
  const external: string[] = [], privateAssets: string[] = [];
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.origin !== 'http://127.0.0.1:8173') external.push(request.url());
    if (/\/admin\/(?:media|site-images|licenses)\//.test(url.pathname) || /master|stem/i.test(url.pathname)) privateAssets.push(url.pathname);
  });
  const article = page.getByRole('article', { name: track.title, exact: true });
  await article.getByRole('button', { name: `Play ${track.title}`, exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.__nativePreviews[0].currentTime)).toBeGreaterThan(0);
  const before = await page.evaluate(() => ({ source: window.__nativePreviews[0].src, time: window.__nativePreviews[0].currentTime }));
  const destination = article.getByRole('link', { name: track.title, exact: true });
  await destination.focus(); await destination.press('Enter');
  await expect(page).toHaveURL(privatePath + query);
  await expect(page.locator('.preview-banner')).toContainText('PRIVATE CONTENT PREVIEW');
  await expect(page.locator('head link[rel="manifest"], head link[rel="apple-touch-icon"]')).toHaveCount(0);
  await expect(page.locator('summary').filter({ hasText: 'Add VASEY.AUDIO to your device' })).toHaveCount(0);
  await expect(page.locator('head meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow');
  await page.goBack();
  await expect(page).toHaveURL('/' + query);
  await expect(summary).toBeVisible();
  await expect(page.locator('head link[rel="manifest"]')).toHaveCount(1);
  await expect(page.locator('head link[rel="apple-touch-icon"]')).toHaveCount(1);
  await summary.focus(); await summary.press('Enter');
  const help = page.locator('details.install-help');
  await expect(help).toHaveAttribute('open', '');
  await expect(help).toContainText('An internet connection is required');
  await expect(help).toContainText('Open as Web App if shown');
  await expect(help).toContainText('If neither is available, keep using the website.');
  const home = help.getByRole('link', { name: 'Open the public home page', exact: true });
  await home.focus(); await home.press('Enter');
  await expect(page).toHaveURL('/');
  await expect(page.locator('#catalog-title')).toBeFocused();
  expect(await page.evaluate(() => ({ count: window.__nativePreviews.length, source: window.__nativePreviews[0].src, paused: window.__nativePreviews[0].paused })))
    .toEqual({ count: 1, source: before.source, paused: false });
  await expect.poll(() => page.evaluate(() => window.__nativePreviews[0].currentTime)).toBeGreaterThanOrEqual(before.time);
  await expect(page.locator('head meta[name="theme-color"]')).toHaveCount(1);
  await expect(page.locator('head meta[name="theme-color"]')).toHaveAttribute('content', '#052e3a');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(external).toEqual([]); expect(privateAssets).toEqual([]); expect(errors).toEqual([]);
  await testInfo.attach('public-installation-metadata', { body: JSON.stringify({ manifest, privateHeadRemoved: true, publicHeadRestored: true, nativeOwnerPreserved: true, physicalInstallationVerified: false }), contentType: 'application/json' });
  await summary.focus(); await summary.press('Enter');
  await expect(help).toHaveAttribute('open', '');
  await page.screenshot({ path: testInfo.outputPath('public-installation-guidance.png'), fullPage: true });
});
