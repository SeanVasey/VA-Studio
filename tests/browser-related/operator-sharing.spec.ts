import { execFileSync } from 'node:child_process';
import { lstatSync, readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '@playwright/test';
import { resetBrowserLoginRateLimit } from '../browser/auth-fixture';

type Track = { id: number; title: string; slug: string; href: string };
type Manifest = { schemaVersion: 1; marker: string; origin: string; database: string; projects: Record<string, { tracks: [Track, Track] }>; evidenceHash: string };

function manifest(): Manifest {
  const directory = process.env.VASEY_BROWSER_DIRECTORY!;
  const path = join(directory, 'related-track-fixtures.json');
  const stat = lstatSync(path);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 262144 || (stat.mode & 0o777) !== 0o600 || realpathSync(path) !== path) {
    throw new Error('The sharing journey requires the bounded isolated ready-track manifest.');
  }
  const value = JSON.parse(readFileSync(path, 'utf8')) as Manifest;
  if (value.schemaVersion !== 1 || value.marker !== process.env.VASEY_BROWSER_RELATED_MARKER
    || value.origin !== 'http://127.0.0.1:8173' || value.database !== join(directory, 'database.sqlite')
    || Object.keys(value.projects).join(',') !== 'chromium-desktop,webkit-mobile'
    || !/^[a-f0-9]{64}$/.test(value.evidenceHash)) {
    throw new Error('The sharing journey manifest does not match this selected run.');
  }
  return value;
}

test.beforeEach(() => resetBrowserLoginRateLimit());

test('operator sharing uses the current public route and fixed embed with truthful copy and manual fallback', async ({ page, context }, testInfo) => {
  const fixture = manifest();
  const tracks = fixture.projects[testInfo.project.name].tracks;
  expect(Array.isArray(tracks)).toBe(true);
  expect(tracks).toHaveLength(2);
  for (const track of tracks) {
    expect(Number.isSafeInteger(track.id) && track.id > 0).toBe(true);
    expect(typeof track.slug).toBe('string');
    expect(track.slug.length).toBeLessThanOrEqual(255);
    expect(track.slug).toMatch(/^[a-z0-9]+(?:-[a-z0-9]+)*(?![\s\S])/);
    expect(track.href).toBe(`/tracks/${track.slug}`);
    expect(typeof track.title).toBe('string');
    expect(track.title.length).toBeGreaterThan(0);
    expect(track.title.length).toBeLessThanOrEqual(255);
  }
  const [first, second] = tracks;
  expect(first.id).not.toBe(second.id);
  expect(first.title).toHaveLength(255);
  const firstStatus = (await page.request.get(first.href)).status();
  expect([200, 404]).toContain(firstStatus);
  // The editorial journey intentionally withdraws the first fixture; never republish it to satisfy sharing.
  const retainedState = firstStatus === 200 ? 'published' : 'withdrawn';
  const track = firstStatus === 200 ? first : second;
  async function persisted(label: string) {
    const result = execFileSync('php', ['tests/browser/prepare-related-tracks.php', 'verify', testInfo.project.name, retainedState], {
      cwd: process.cwd(), env: process.env, timeout: 30_000, maxBuffer: 65536, encoding: 'utf8', stdio: 'pipe',
    });
    expect(JSON.parse(result)).toMatchObject({ verified: true, project: testInfo.project.name, state: retainedState, evidenceHash: fixture.evidenceHash });
    await testInfo.attach(label, { body: Buffer.from(result), contentType: 'application/json' });
  }
  await persisted('sharing-before-ready-track-evidence');
  if (testInfo.project.name === 'chromium-desktop') {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: fixture.origin });
  }
  const errors: string[] = [];
  const externalRequests: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/tracks');
  const search = track.title.slice(0, 80);
  const searchResponse = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: Array<{ updates?: Record<string, unknown> }> };
    return payload.components?.some(component => component.updates?.tableSearch === search) ?? false;
  });
  await page.getByRole('searchbox', { name: 'Search', exact: true }).fill(search);
  const searched = await searchResponse;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(page.getByText(`Search: ${search}`, { exact: true })).toBeVisible();
  const row = page.getByRole('row').filter({ has: page.getByText(track.title, { exact: true }) });
  await expect(row).toBeVisible();
  // Observe sharing after the existing admin shell and confirmed search have loaded.
  page.on('request', request => {
    if (new URL(request.url()).origin !== fixture.origin) externalRequests.push(request.url());
  });
  const launch = row.getByRole('button', { name: 'Share', exact: true });
  await launch.focus();
  await launch.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Share public track', exact: true })).toBeVisible();
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  expect(await dialog.locator('.fi-modal-window').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  const link = dialog.getByLabel('Public link', { exact: true });
  const embed = dialog.getByLabel('Embed code', { exact: true });
  await expect(link).toHaveValue(fixture.origin + track.href);
  await expect(link).toHaveJSProperty('readOnly', true);
  await expect(dialog.getByLabel('Preview embed URL', { exact: true })).toHaveValue(`${fixture.origin}/embed/tracks/${track.slug}`);
  const code = await embed.inputValue();
  expect(code).toBe(`<iframe src="${fixture.origin}/embed/tracks/${track.slug}" title="VASEY.AUDIO tagged track preview" loading="lazy" referrerpolicy="no-referrer" style="width:100%;max-width:720px;height:300px;border:0"></iframe>`);
  await expect(dialog.locator('iframe')).toHaveCount(0);
  expect((await page.request.get(await link.inputValue())).status()).toBe(200);
  const embedded = await page.request.get(`${fixture.origin}/embed/tracks/${track.slug}`);
  expect(embedded.status()).toBe(200);
  expect(await embedded.text()).not.toContain('<script');
  const status = dialog.getByRole('status');
  await dialog.getByRole('button', { name: 'Copy link', exact: true }).click();
  if (testInfo.project.name === 'chromium-desktop') {
    await expect(status).toHaveText('Public link copied.');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(fixture.origin + track.href);
  } else {
    await expect(status).toHaveText(/^(Public link copied\.|Clipboard is unavailable\. Select the public link field and copy it manually\.)$/);
  }
  const linkOutcome = await status.innerText();
  await dialog.getByRole('button', { name: 'Copy embed', exact: true }).click();
  if (testInfo.project.name === 'chromium-desktop') {
    await expect(status).toHaveText('Embed code copied.');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(code);
  } else {
    await expect(status).toHaveText(/^(Embed code copied\.|Clipboard is unavailable\. Select the embed code field and copy it manually\.)$/);
  }
  await testInfo.attach('native-clipboard-outcomes', { contentType: 'application/json', body: Buffer.from(JSON.stringify({
    link: linkOutcome, embed: await status.innerText(), clipboardReadVerified: testInfo.project.name === 'chromium-desktop',
  })) });
  const select = dialog.getByRole('button', { name: 'Select embed code', exact: true });
  await select.focus();
  await select.press('Enter');
  await expect(embed).toBeFocused();
  expect(await embed.evaluate(element => {
    const field = element as HTMLTextAreaElement;
    return field.value.slice(field.selectionStart, field.selectionEnd);
  })).toBe(code);
  await dialog.getByRole('button', { name: 'Select link', exact: true }).click();
  await expect(link).toBeFocused();
  await page.screenshot({ path: testInfo.outputPath('operator-sharing.png'), fullPage: true });
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(dialog.getByRole('heading')).toBeHidden();
  await expect.poll(() => row.evaluate(element => element.contains(document.activeElement))).toBe(true);
  await persisted('sharing-after-read-only-track-evidence');
  expect(errors).toEqual([]);
  expect(externalRequests).toEqual([]);
});
