import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { fixtureTrack, storefrontFixture } from './storefront-fixture';
import { releaseMenuAction, releaseRow as row } from './site-release-row';

test.beforeEach(() => resetBrowserLoginRateLimit());

async function activate(page: Page, label: string, action: 'Publish release' | 'Restore previous release') {
  const trigger = action === 'Publish release'
    ? row(page, label).getByRole('button', { name: action, exact: true })
    : await releaseMenuAction(page, label, action);
  await trigger.click();
  const dialog = page.getByRole('alertdialog', { name: action, exact: true });
  await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(row(page, label).getByText('Active', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading', { name: action, exact: true })).not.toBeVisible();
}

async function followNavigation(page: Page, label: string) {
  const menu = page.getByRole('button', { name: 'Menu', exact: true });
  if (await menu.isVisible()) await menu.click();
  await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: label, exact: true }).click();
}

test('seller edits persisted pages, previews one private release and restores the original site', async ({ page, context, playwright }, testInfo) => {
  test.setTimeout(150_000);
  const failures: string[] = [];
  const providerRequests: string[] = [];
  const watch = (target: Page) => {
    target.on('pageerror', error => failures.push(error.message));
    target.on('request', request => { if (/^https:\/\/(?:www\.)?(?:youtube\.com|vimeo\.com|youtube-nocookie\.com)/.test(request.url())) providerRequests.push(request.url()); });
  };
  watch(page);
  const suffix = testInfo.project.name;
  const firstLabel = `Synthetic editorial ${suffix}`;
  const secondLabel = `Synthetic editorial copy ${suffix}`;
  const title = `Synthetic article ${suffix}`;
  const firstParagraph = `Synthetic retained article paragraph ${suffix}.`;
  const secondParagraph = `Synthetic copied article paragraph ${suffix}.`;
  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    await page.goto('/admin/login');
    await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
    await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/site-releases');
    await page.getByRole('button', { name: 'New content draft', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Create a private content draft', exact: true })).toBeVisible();
    await dialog.getByLabel('Release label', { exact: false }).fill(firstLabel);
    for (const section of ['About', 'Contact', 'Blog', 'Videos']) {
      await dialog.getByLabel(`Include ${section} page`, { exact: true }).check();
      await dialog.getByLabel(`${section} page title`, { exact: false }).fill(`Synthetic ${section} ${suffix}`);
      await dialog.getByLabel(`${section} page description`, { exact: false }).fill(`Synthetic ${section} description.`);
    }
    await dialog.getByLabel('About paragraph', { exact: false }).and(dialog.locator('textarea')).fill('Synthetic about paragraph.');
    await dialog.getByLabel('Contact paragraph', { exact: false }).and(dialog.locator('textarea')).fill('Synthetic contact paragraph.');
    await dialog.getByLabel('Contact email address', { exact: false }).fill('synthetic@example.test');
    await dialog.getByLabel('Article URL slug', { exact: false }).fill('synthetic-article');
    await dialog.getByLabel('Article title', { exact: false }).fill(title);
    await dialog.getByLabel('Article description', { exact: false }).fill('Synthetic article summary.');
    await dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea')).fill(firstParagraph);
    await dialog.getByLabel('Video URL slug', { exact: false }).fill('synthetic-video');
    await dialog.getByLabel('Video title', { exact: false }).fill(`Synthetic video ${suffix}`);
    await dialog.getByLabel('Video description', { exact: false }).fill('Synthetic video summary.');
    await dialog.getByLabel('Video provider', { exact: false }).selectOption('youtube');
    await dialog.getByLabel('Video ID', { exact: false }).fill('abcdefghijk');
    const navigation = dialog.getByRole('region', { name: 'Navigation and footer', exact: true });
    // A new draft copies the active release. Another journey failing before restoration may leave
    // four editorial links here already; adding a fifth would leave a required empty destination.
    const links = navigation.getByLabel('Destination', { exact: false });
    const inheritedLinks = await links.count();
    expect(inheritedLinks).toBeGreaterThan(0);
    expect(inheritedLinks).toBeLessThanOrEqual(8);
    for (let count = inheritedLinks; count > 4; count--) {
      await navigation.getByRole('button', { name: 'Delete', exact: true }).last().click();
      await expect(links).toHaveCount(count - 1);
    }
    for (let count = inheritedLinks; count < 4; count++) {
      await navigation.getByRole('button', { name: 'Add navigation link', exact: true }).click();
      await expect(links).toHaveCount(count + 1);
    }
    await expect(links).toHaveCount(4);
    for (const [index, section] of ['About', 'Contact', 'Blog', 'Videos'].entries()) {
      await navigation.getByLabel('Label', { exact: false }).nth(index).fill(section === 'About' ? 'A'.repeat(48) : section);
      await navigation.getByLabel('Destination', { exact: false }).nth(index).selectOption(`/${section.toLowerCase()}`);
    }
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(dialog.getByRole('heading', { name: 'Create a private content draft', exact: true })).not.toBeVisible();
    await expect(row(page, firstLabel).getByText('Private draft', { exact: true })).toBeVisible();
    for (const path of ['/about', '/contact', '/blog', '/blog/synthetic-article', '/videos', '/videos/synthetic-video']) expect((await visitor.get(path)).status()).toBe(404);

    const previewUrl = await row(page, firstLabel).getByRole('link', { name: 'Preview', exact: true }).getAttribute('href');
    expect(previewUrl).toBeTruthy();
    const denied = await visitor.get(`${previewUrl}/blog/synthetic-article`, { maxRedirects: 0 });
    expect([302, 403]).toContain(denied.status());
    expect(await denied.text()).not.toContain(firstParagraph);
    const preview = await context.newPage(); watch(preview);
    const privateResponse = await preview.goto(previewUrl!);
    expect(privateResponse?.headers()['cache-control']).toContain('no-store');
    expect(privateResponse?.headers()['x-robots-tag']).toContain('noindex');
    await followNavigation(preview, 'Blog');
    await expect(preview).toHaveURL(`${previewUrl}/blog`);
    await preview.getByRole('link', { name: `Read ${title}`, exact: true }).click();
    await expect(preview).toHaveURL(`${previewUrl}/blog/synthetic-article`);
    await expect(preview.getByText(firstParagraph, { exact: true })).toBeVisible();
    await expect(preview.getByRole('link', { name: /Back to blog/ })).toHaveAttribute('href', new URL(`${previewUrl}/blog`).pathname);
    await expect(preview.getByRole('button', { name: /Open cart/ })).toHaveCount(0);
    await followNavigation(preview, 'Contact');
    await expect(preview.getByText('synthetic@example.test', { exact: true })).toBeVisible();
    await expect(preview.getByRole('link', { name: /Open email/ })).toHaveCount(0);
    await followNavigation(preview, 'Videos');
    await preview.getByRole('link', { name: `View Synthetic video ${suffix}`, exact: true }).click();
    await expect(preview.getByRole('link', { name: /Watch on/ })).toHaveCount(0);
    await expect(preview.locator('iframe, video')).toHaveCount(0);
    await preview.screenshot({ path: testInfo.outputPath('private-editorial-video.png'), fullPage: false });
    await preview.close();

    await activate(page, firstLabel, 'Publish release');
    for (const path of ['/about', '/contact', '/blog', '/blog/synthetic-article', '/videos', '/videos/synthetic-video']) expect((await visitor.get(path)).status()).toBe(200);
    const published = await context.newPage(); watch(published);
    // Only catalog transport is synthetic here; editorial routes still read the real published release.
    // Observe the same native audio element surviving both directions of Inertia navigation.
    await storefrontFixture(published);
    await published.goto('/');
    await published.getByRole('article', { name: fixtureTrack.title, exact: true }).getByRole('button', { name: `Play ${fixtureTrack.title}`, exact: true }).click();
    await expect(published.getByRole('button', { name: 'Pause preview', exact: true })).toBeVisible();
    await expect.poll(() => published.evaluate(() => window.__nativePreviews[0]?.currentTime ?? 0)).toBeGreaterThan(0);
    const playbackTime = await published.evaluate(() => window.__nativePreviews[0].currentTime);
    await followNavigation(published, 'Blog');
    await expect(published).toHaveURL('/blog');
    await expect(published.getByRole('heading', { name: `Synthetic Blog ${suffix}`, exact: true })).toBeFocused();
    expect(await published.evaluate(() => window.__nativePreviews.length)).toBe(1);
    expect(await published.evaluate(() => window.__nativePreviews[0].paused)).toBe(false);
    expect(await published.evaluate(() => window.__nativePreviews[0].currentTime)).toBeGreaterThanOrEqual(playbackTime);
    await published.getByRole('link', { name: `Read ${title}`, exact: true }).click();
    await expect(published.getByText(firstParagraph, { exact: true })).toBeVisible();
    expect(await published.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await published.screenshot({ path: testInfo.outputPath('published-editorial-article.png'), fullPage: false });
    const publicMenu = published.getByRole('button', { name: 'Menu', exact: true });
    if (await publicMenu.isVisible()) {
      await publicMenu.click();
      await expect(published.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'A'.repeat(48), exact: true })).toBeVisible();
      expect(await published.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
      await published.getByRole('button', { name: 'Close menu', exact: true }).click();
    }
    await published.getByRole('link', { name: 'VASEY.AUDIO home', exact: true }).first().click();
    await expect(published).toHaveURL('/');
    expect(await published.evaluate(() => window.__nativePreviews.length)).toBe(1);
    expect(await published.evaluate(() => window.__nativePreviews[0].paused)).toBe(false);
    await followNavigation(published, 'Contact');
    await expect(published.getByRole('link', { name: /Open email/ })).toHaveAttribute('href', 'mailto:synthetic%40example.test');
    await published.goto('/videos/synthetic-video');
    await expect(published.getByRole('link', { name: /Watch on YouTube/ })).toHaveAttribute('href', 'https://www.youtube.com/watch?v=abcdefghijk');
    await expect(published.locator('iframe, video')).toHaveCount(0);
    await published.close();

    await (await releaseMenuAction(page, firstLabel, 'Edit as new draft')).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Edit a copy as a private draft', exact: true })).toBeVisible();
    await expect(dialog.getByLabel('Include Blog page', { exact: true })).toBeChecked();
    await expect(dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea'))).toHaveValue(firstParagraph);
    await dialog.getByLabel('Release label', { exact: false }).fill(secondLabel);
    await dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea')).fill(secondParagraph);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(row(page, secondLabel).getByText('Private draft', { exact: true })).toBeVisible();
    expect(await (await visitor.get('/blog/synthetic-article')).text()).toContain(firstParagraph);
    expect(await (await visitor.get('/blog/synthetic-article')).text()).not.toContain(secondParagraph);
    await activate(page, secondLabel, 'Publish release');
    expect(await (await visitor.get('/blog/synthetic-article')).text()).toContain(secondParagraph);
    await activate(page, firstLabel, 'Restore previous release');
    expect(await (await visitor.get('/blog/synthetic-article')).text()).toContain(firstParagraph);
    await activate(page, 'Original site content', 'Restore previous release');
    for (const path of ['/about', '/contact', '/blog', '/blog/synthetic-article', '/videos', '/videos/synthetic-video']) expect((await visitor.get(path)).status()).toBe(404);
    expect(providerRequests).toEqual([]);
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
