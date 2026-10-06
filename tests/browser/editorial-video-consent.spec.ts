import { test, expect } from '@playwright/test';

// page.route owns this synthetic transport; service-worker fetches bypass it.
// The separate public-offline journey exercises the real worker in both engines.
test.use({ serviceWorkers: 'block' });

// Synthetic page/provider transport; native DOM, keyboard and iframe requests remain real.
// This proves the consent boundary, not third-party playback availability or publication authorization.
for (const provider of ['youtube', 'vimeo'] as const) {
  test(`${provider} video connects only after intent and can be removed`, async ({ page }, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    const shellResponse = await page.request.get('/');
    expect(shellResponse.ok()).toBe(true);
    const shell = await shellResponse.text();
    const pageScript = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const embedded = shell.match(pageScript);
    expect(embedded).not.toBeNull();
    const base = JSON.parse(embedded![2]);
    const path = '/videos/synthetic-consent-video';
    const name = provider === 'youtube' ? 'YouTube' : 'Vimeo';
    const videoId = provider === 'youtube' ? 'abcdefghijk' : '123456789';
    const watchUrl = provider === 'youtube' ? `https://www.youtube.com/watch?v=${videoId}` : `https://vimeo.com/${videoId}`;
    const providerRequests: string[] = [];
    await page.route(provider === 'youtube' ? 'https://www.youtube-nocookie.com/**' : 'https://player.vimeo.com/**', async route => {
      providerRequests.push(route.request().url());
      await route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Synthetic provider frame</title><p>Provider response fixture</p>' });
    });
    await page.route(`**${path}*`, async route => {
      const preview = new URL(route.request().url()).searchParams.get('preview') === '1';
      const payload = { ...base, component: 'Editorial', url: path, props: { ...base.props,
        sitePreview: preview, sitePreviewBase: preview ? '/site/preview/synthetic' : null,
        editorial: { section: 'videos', kind: 'entry', path, title: 'Synthetic consent video', description: 'Synthetic video consent boundary.',
          paragraphs: [], entries: [], email: null, contactHref: null, video: { provider, videoId, watchUrl } },
      } };
      const json = JSON.stringify(payload).replaceAll('<', '\\u003c');
      await route.fulfill({ contentType: 'text/html', body: shell.replace(pageScript, (_match, opening, _original, closing) => opening + json + closing) });
    });

    await page.goto(path);
    await expect(page.getByRole('heading', { name: 'Synthetic consent video', exact: true })).toBeVisible();
    await expect(page.locator('iframe')).toHaveCount(0);
    expect(providerRequests).toHaveLength(0);
    const load = page.getByRole('button', { name: `Load ${name} video`, exact: true });
    await load.focus(); await load.press('Enter');
    await expect(page.locator('iframe')).toHaveCount(1);
    await expect(page.getByRole('region', { name: `${name} video`, exact: true })).toHaveCSS('display', 'block');
    const frameBox = await page.locator('iframe').boundingBox();
    expect(frameBox?.width).toBeGreaterThanOrEqual(200);
    expect(frameBox?.height).toBeGreaterThanOrEqual(200);
    await expect.poll(() => providerRequests.length).toBe(1);
    await expect(page.locator('iframe')).toHaveAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    expect(new URL(providerRequests[0]).searchParams.get('autoplay')).toBe('0');
    await expect(page.getByRole('link', { name: `Watch on ${name}` })).toHaveAttribute('href', watchUrl);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`consented-${provider}.png`), fullPage: true });
    await page.getByRole('button', { name: 'Remove video player' }).click();
    await expect(page.locator('iframe')).toHaveCount(0);
    await expect(load).toBeFocused();
    await page.goto(path + '?preview=1');
    await expect(page.getByText('Video playback and links are disabled in private preview.')).toBeVisible();
    await expect(page.locator('iframe')).toHaveCount(0);
    await expect(page.getByRole('link', { name: `Watch on ${name}` })).toHaveCount(0);
    expect(providerRequests).toHaveLength(1);
    expect(errors).toEqual([]);
  });
}
