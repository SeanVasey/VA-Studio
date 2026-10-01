import { execFileSync } from 'node:child_process';
import { lstatSync, readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type APIRequestContext, type Locator, type Page, type TestInfo } from '@playwright/test';
import { resetBrowserLoginRateLimit } from '../browser/auth-fixture';
import { releaseMenuAction, releaseRow } from '../browser/site-release-row';

type Track = { id: number; title: string; artist: string; slug: string; href: string };
type Manifest = { schemaVersion: 1; marker: string; origin: string; database: string; projects: Record<string, { tracks: [Track, Track] }>; evidenceHash: string };
function loadManifest(): Manifest {
  const directory = process.env.VASEY_BROWSER_DIRECTORY!;
  const manifestPath = join(directory, 'related-track-fixtures.json');
  const stat = lstatSync(manifestPath);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 262144 || (stat.mode & 0o777) !== 0o600 || realpathSync(manifestPath) !== manifestPath) {
    throw new Error('The related-track manifest is not the bounded private isolated fixture.');
  }
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8')) as Manifest;
  if (manifest.schemaVersion !== 1 || manifest.marker !== process.env.VASEY_BROWSER_RELATED_MARKER
    || manifest.origin !== 'http://127.0.0.1:8173' || manifest.database !== join(directory, 'database.sqlite')
    || Object.keys(manifest.projects).join(',') !== 'chromium-desktop,webkit-mobile'
    || manifest.evidenceHash?.length !== 64 || !/^[a-f0-9]{64}$/.test(manifest.evidenceHash)) {
    throw new Error('The related-track manifest identity does not match this selected run.');
  }
  return manifest;
}

test.beforeEach(() => resetBrowserLoginRateLimit());

async function persisted(testInfo: TestInfo, manifest: Manifest, state: 'published' | 'withdrawn', label: string) {
  const output = execFileSync('php', ['tests/browser/prepare-related-tracks.php', 'verify', testInfo.project.name, state], {
    cwd: process.cwd(), env: process.env, timeout: 30000, maxBuffer: 65536, encoding: 'utf8', stdio: 'pipe',
  });
  const result = JSON.parse(output) as { verified: boolean; project: string; state: string; evidenceHash: string };
  expect(result.verified).toBe(true);
  expect(result.project).toBe(testInfo.project.name);
  expect(result.state).toBe(state);
  expect(result.evidenceHash).toBe(manifest.evidenceHash);
  await testInfo.attach(label, { body: Buffer.from(output), contentType: 'application/json' });
}

async function props(request: APIRequestContext, path: string) {
  // Bootstrap this route's exact server version; private panel previews legitimately use an empty version.
  const shell = await request.get(path, { headers: { Accept: 'text/html, application/xhtml+xml' } });
  expect(shell.status()).toBe(200);
  const embedded = (await shell.text()).match(/<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
  expect(embedded).not.toBeNull();
  const bootstrap = JSON.parse(embedded![1]) as { component: string; version: string };
  expect(bootstrap.component).toBe('Editorial');
  expect(typeof bootstrap.version).toBe('string');
  const version = bootstrap.version;
  const inertiaHeaders = { 'X-Inertia': 'true', 'X-Inertia-Version': version, Accept: 'text/html, application/xhtml+xml' };
  const response = await request.get(path, { headers: inertiaHeaders });
  expect(response.status()).toBe(200);
  expect(response.headers()['x-inertia']).toBe('true');
  const payload = await response.json();
  expect(payload.component).toBe('Editorial');
  expect(payload.version).toBe(version);
  return payload.props as { editorial: { relatedTracks?: Array<{ title: string; artist: string; href?: string }> }; sitePreview?: boolean; commerceEnabled?: boolean };
}

async function activate(page: Page, label: string, action: 'Publish release' | 'Restore previous release') {
  const trigger = action === 'Publish release'
    ? releaseRow(page, label).getByRole('button', { name: action, exact: true })
    : await releaseMenuAction(page, label, action);
  await trigger.click();
  const dialog = page.getByRole('alertdialog', { name: action, exact: true });
  await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(releaseRow(page, label).getByText('Active', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading')).not.toBeVisible();
}

async function addTrack(group: Locator, track: Track) {
  const before = await group.getByRole('combobox').count();
  await group.getByRole('button', { name: 'Add to related tracks', exact: true }).click();
  await expect(group.getByRole('combobox')).toHaveCount(before + 1);
  const select = group.getByRole('combobox').last();
  await select.focus();
  await select.press('Enter');
  await group.getByRole('textbox', { name: 'Search', exact: true }).fill(track.title.slice(0, 80));
  await group.getByRole('option', { name: `${track.title} · ${track.artist}`, exact: true }).click();
  await expect(select).toContainText(track.title);
}

async function clearTracks(group: Locator) {
  let remaining = await group.getByRole('combobox').count();
  while (remaining > 0) {
    await group.getByRole('button', { name: 'Delete', exact: true }).last().click();
    await expect(group.getByRole('combobox')).toHaveCount(--remaining);
  }
  await expect(group.getByRole('combobox')).toHaveCount(0);
}

test('ordinary editorial associations preserve private order, current eligibility and first-party destinations', async ({ page, context, playwright }, testInfo) => {
  test.setTimeout(150_000);
  const manifest = loadManifest();
  const fixture = manifest.projects[testInfo.project.name];
  expect(fixture.tracks).toHaveLength(2);
  const [first, second] = fixture.tracks;
  for (const track of fixture.tracks) {
    expect(Number.isSafeInteger(track.id) && track.id > 0).toBe(true);
    expect(track.href).toBe(`/tracks/${track.slug}`);
  }
  expect(first.title).toHaveLength(255);
  expect(first.id).not.toBe(second.id);
  await persisted(testInfo, manifest, 'published', 'genuine-ready-track-evidence');
  const failures: string[] = [];
  const externalRequests: string[] = [];
  const editorialMediaRequests: string[] = [];
  let watchMedia = true;
  const watch = (target: Page, editorial = false) => {
    target.on('pageerror', error => failures.push(error.message));
    // The no-provider requirement covers every request from editorial previews/public pages; admin keeps its own shell.
    if (!editorial) return;
    target.on('request', request => {
      const url = new URL(request.url());
      if (url.origin !== manifest.origin) externalRequests.push(url.origin);
      if (watchMedia && /^\/media\//.test(url.pathname)) editorialMediaRequests.push(url.pathname);
    });
  };
  watch(page);
  const visitor = await playwright.request.newContext({ baseURL: manifest.origin });
  const suffix = testInfo.project.name;
  const firstLabel = `Synthetic related original ${suffix}`;
  const copiedLabel = `Synthetic related copy ${suffix}`;
  const slug = `synthetic-related-${suffix}`;
  const articleTitle = `Synthetic related article ${suffix}`;
  const firstParagraph = `Synthetic retained related paragraph ${suffix}.`;
  const copiedParagraph = `Synthetic copied related paragraph ${suffix}.`;
  const publicLinks = [second, first].map(track => ({ title: track.title, artist: track.artist, href: track.href }));
  try {
    await page.goto('/admin/login');
    await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
    await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/site-releases');
    await page.getByRole('button', { name: 'New content draft', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('Release label', { exact: false }).fill(firstLabel);
    for (const section of ['Blog', 'Videos']) {
      await dialog.getByLabel(`Include ${section} page`, { exact: true }).check();
      await dialog.getByLabel(`${section} page title`, { exact: false }).fill(`Synthetic related ${section} ${suffix}`);
      await dialog.getByLabel(`${section} page description`, { exact: false }).fill('Synthetic related detail associations.');
    }
    await dialog.getByLabel('Article URL slug', { exact: false }).fill(slug);
    await dialog.getByLabel('Article title', { exact: false }).fill(articleTitle);
    await dialog.getByLabel('Article description', { exact: false }).fill('Synthetic related article description.');
    await dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea')).fill(firstParagraph);
    await dialog.getByLabel('Video URL slug', { exact: false }).fill(slug);
    await dialog.getByLabel('Video title', { exact: false }).fill(`Synthetic related video ${suffix}`);
    await dialog.getByLabel('Video description', { exact: false }).fill('Synthetic related video description.');
    await dialog.getByLabel('Video provider', { exact: false }).selectOption('youtube');
    await dialog.getByLabel('Video ID', { exact: false }).fill('abcdefghijk');
    const groups = dialog.getByRole('group', { name: 'Related tracks', exact: true });
    await expect(groups).toHaveCount(2);
    // New drafts copy the active release. Remove any inherited choices through its normal repeater controls.
    await clearTracks(groups.nth(0));
    await clearTracks(groups.nth(1));
    await addTrack(groups.nth(0), first);
    await addTrack(groups.nth(0), second);
    const move = groups.nth(0).getByRole('button', { name: 'Move up', exact: true }).nth(1);
    await move.focus();
    await move.press('Enter');
    await expect(groups.nth(0).getByRole('combobox').first()).toContainText(second.title);
    await addTrack(groups.nth(1), first);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(releaseRow(page, firstLabel).getByText('Private draft', { exact: true })).toBeVisible();
    expect((await visitor.get(`/blog/${slug}`)).status()).toBe(404);
    const previewUrl = await releaseRow(page, firstLabel).getByRole('link', { name: 'Preview', exact: true }).getAttribute('href');
    expect(previewUrl).toBeTruthy();
    const privatePath = `${new URL(previewUrl!, manifest.origin).pathname}/blog/${slug}`;
    const denied = await visitor.get(privatePath, { maxRedirects: 0 });
    expect([302, 403]).toContain(denied.status());
    expect(await denied.text()).not.toContain(firstParagraph);
    const preview = await context.newPage(); watch(preview, true);
    const privateResponse = await preview.goto(privatePath);
    expect(privateResponse?.headers()['cache-control']).toContain('no-store');
    expect(privateResponse?.headers()['x-robots-tag']).toContain('noindex');
    const privateRelated = preview.getByRole('region', { name: 'Related tracks', exact: true });
    await expect(privateRelated.getByRole('heading', { level: 3 })).toHaveText([second.title, first.title]);
    await expect(privateRelated.getByRole('link')).toHaveCount(0);
    const privateProps = await props(preview.request, privatePath);
    expect(privateProps.sitePreview).toBe(true);
    expect(privateProps.commerceEnabled).toBe(false);
    expect(privateProps.editorial.relatedTracks).toEqual(publicLinks.map(({ title, artist }) => ({ title, artist })));
    await preview.screenshot({ path: testInfo.outputPath('related-private-preview.png'), fullPage: true });
    await preview.close();

    await activate(page, firstLabel, 'Publish release');
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual(publicLinks);
    expect((await props(visitor, `/videos/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[1]]);
    for (const path of ['/blog', '/videos']) expect((await props(visitor, path)).editorial.relatedTracks).toBeUndefined();
    const publicPage = await context.newPage(); watch(publicPage, true);
    await publicPage.goto(`/blog/${slug}`);
    const related = publicPage.getByRole('region', { name: 'Related tracks', exact: true });
    await expect(related.getByRole('link')).toHaveText([second.title, first.title]);
    expect(await publicPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await publicPage.screenshot({ path: testInfo.outputPath('related-public-wrapping.png'), fullPage: true });

    await page.goto('/admin/tracks');
    const trackRow = page.getByRole('row').filter({ has: page.getByText(first.title, { exact: true }) });
    await trackRow.getByRole('button', { name: 'Unpublish', exact: true }).click();
    const withdrawal = page.getByRole('alertdialog', { name: 'Unpublish', exact: true });
    await withdrawal.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(trackRow.getByRole('button', { name: 'Publish', exact: true })).toBeVisible();
    await persisted(testInfo, manifest, 'withdrawn', 'ordinary-withdrawal-evidence');
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[0]]);
    expect((await props(visitor, `/videos/${slug}`)).editorial.relatedTracks).toEqual([]);
    expect((await visitor.get(first.href)).status()).toBe(404);
    await publicPage.reload();
    await expect(related.getByRole('link')).toHaveText([second.title]);
    await expect(publicPage.getByText(firstParagraph, { exact: true })).toBeVisible();

    await page.goto('/admin/site-releases');
    await (await releaseMenuAction(page, firstLabel, 'Edit as new draft')).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('group', { name: 'Related tracks', exact: true }).first().getByRole('combobox').first()).toContainText(second.title);
    await expect(dialog.getByRole('group', { name: 'Related tracks', exact: true }).first().getByRole('combobox').nth(1)).toContainText(`${first.title} · ${first.artist} (not currently available)`);
    await dialog.getByLabel('Release label', { exact: false }).fill(copiedLabel);
    await dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea')).fill(copiedParagraph);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(releaseRow(page, copiedLabel).getByText('Private draft', { exact: true })).toBeVisible();
    await activate(page, copiedLabel, 'Publish release');
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[0]]);
    expect(await (await visitor.get(`/blog/${slug}`)).text()).toContain(copiedParagraph);
    await activate(page, firstLabel, 'Restore previous release');
    expect(await (await visitor.get(`/blog/${slug}`)).text()).toContain(firstParagraph);
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[0]]);
    await persisted(testInfo, manifest, 'withdrawn', 'unchanged-media-license-commerce-evidence');
    expect(editorialMediaRequests).toEqual([]);
    expect(externalRequests).toEqual([]);

    // Only after the editorial no-media checks, follow the genuine surviving track destination.
    watchMedia = false;
    await publicPage.reload();
    const link = related.getByRole('link', { name: second.title, exact: true });
    await link.focus();
    await publicPage.keyboard.press('Shift+Tab');
    await publicPage.keyboard.press('Tab');
    await expect(link).toBeFocused();
    expect(await link.evaluate(element => parseFloat(getComputedStyle(element).outlineWidth))).toBeGreaterThanOrEqual(2);
    await link.press('Enter');
    await expect(publicPage).toHaveURL(second.href);
    await expect(publicPage.locator('#detail-title')).toBeFocused();
    await publicPage.close();
    await activate(page, 'Original site content', 'Restore previous release');
    expect((await visitor.get(`/blog/${slug}`)).status()).toBe(404);
    expect((await visitor.get(`/videos/${slug}`)).status()).toBe(404);
    expect(failures).toEqual([]);
    expect(externalRequests).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
