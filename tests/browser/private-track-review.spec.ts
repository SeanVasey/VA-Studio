import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Metadata = { title: string; slug: string; artist: string; bpm: string; musicalKey: string; genre: string; mood: string; tags: string[]; description: string };
const row = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });

async function searchTracks(page: Page, title: string) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === title) ?? false;
  });
  await page.getByRole('searchbox', { name: 'Search', exact: true }).fill(title);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(page.getByText(`Search: ${title}`, { exact: true })).toBeVisible();
}

async function closeDialog(page: Page, dialog: Locator, label: 'Close' | 'Cancel') {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
  });
  await dialog.getByRole('button', { name: label, exact: true }).focus();
  await dialog.getByRole('button', { name: label, exact: true }).press('Enter');
  const closed = await response;
  expect(closed.status()).toBe(200);
  expect(await closed.finished()).toBeNull();
  await expect(dialog.getByRole('heading')).toBeHidden();
}

async function expectPrivateReview(page: Page, data: Metadata, id: string) {
  await page.bringToFront();
  const launch = row(page, data.title).getByRole('button', { name: 'Review track', exact: true });
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string; params?: unknown[] }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'mountAction' && call.params?.[0] === 'reviewTrack')) ?? false;
  });
  await launch.focus();
  await launch.press('Enter');
  const opened = await response;
  expect(opened.status()).toBe(200);
  expect(opened.headers()['cache-control']).toContain('private');
  expect(opened.headers()['cache-control']).toContain('no-store');
  expect(opened.headers()['x-robots-tag']).toContain('noindex');
  expect(opened.headers()['x-content-type-options']).toBe('nosniff');
  expect(opened.headers()['referrer-policy']).toBe('no-referrer');
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Private track review', exact: true })).toBeVisible();
  await expect(dialog.getByText('Private staff review. This track keeps its current publication state.', { exact: true })).toBeVisible();
  await expect(dialog.getByText('Status: draft', { exact: true })).toBeVisible();
  const metadata = dialog.getByRole('group', { name: 'Metadata', exact: true });
  for (const [label, value] of [
    ['Track ID', id], ['Title', data.title], ['URL', data.slug], ['Artist', data.artist], ['BPM', data.bpm],
    ['Musical key', data.musicalKey], ['Genre', data.genre], ['Mood', data.mood],
  ]) {
    const term = metadata.locator('dt').filter({ hasText: new RegExp(`^${label}$`) });
    await expect(term).toHaveCount(1);
    await expect(term.locator('xpath=following-sibling::dd[1]')).toHaveText(value);
  }
  await expect(metadata.locator('dt').filter({ hasText: /^Metadata revision$/ }).locator('xpath=following-sibling::dd[1]')).toHaveText(/^\d+$/);
  await expect(metadata.getByRole('list', { name: 'Track tags', exact: true }).getByRole('listitem')).toHaveText(data.tags);
  const description = metadata.getByText(data.description, { exact: true });
  await expect(description).toBeVisible();
  expect(await description.evaluate(element => getComputedStyle(element).whiteSpace)).toBe('pre-wrap');
  const readiness = dialog.getByRole('group', { name: 'Publication readiness', exact: true });
  await expect(readiness.getByRole('list', { name: 'Publication blockers', exact: true }).getByRole('listitem')).toHaveText([
    'The latest rights declaration must be verified.',
    'A verified artwork asset is required.',
    'A verified preview_tagged asset is required.',
    'Measured preview duration and waveform peaks are required.',
    'At least one active license offer is required.',
  ]);
  await expect(readiness.getByText('Ready to publish', { exact: true })).toHaveCount(0);
  await expect(dialog.getByRole('group', { name: 'Artwork', exact: true }).getByText('Artwork unavailable', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('group', { name: 'Tagged preview', exact: true }).getByText('Tagged preview unavailable', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('img')).toHaveCount(0);
  await expect(dialog.locator('audio')).toHaveCount(0);
  await expect(dialog.locator('input:not([type="hidden"]), select, textarea')).toHaveCount(0);
  await expect(dialog.getByRole('button', { name: /Save|Publish/i })).toHaveCount(0);
  await expect(dialog.getByRole('link')).toHaveCount(0);
  await expect(page.locator('#private-review-injection')).toHaveCount(0);
  expect(await page.evaluate(() => (window as Window & { privateReviewInjected?: boolean }).privateReviewInjected)).toBeUndefined();
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  const modalWindow = dialog.locator('.fi-modal-window');
  await expect(modalWindow).toHaveAttribute('tabindex', '-1');
  await expect(modalWindow).toHaveAttribute('autofocus', /.*/);
  expect(await modalWindow.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  return { dialog, launch };
}

test('ordinary private track review shows persisted metadata and honest missing-media blockers without publishing', async ({ page, playwright }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  const mediaRequests: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  page.on('request', request => {
    if (/^\/(?:admin\/media\/|media\/)/.test(new URL(request.url()).pathname)) mediaRequests.push(request.url());
  });
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  const tracks = await page.goto('/admin/tracks');
  expect(tracks?.headers()['cache-control']).toContain('no-store');
  expect(tracks?.headers()['x-robots-tag']).toContain('noindex');
  const data: Metadata = {
    title: `Synthetic private review ${testInfo.project.name}`,
    slug: `private-review-${testInfo.project.name}`, artist: 'SYNTHETIC <REVIEW ARTIST>', bpm: '94', musicalKey: 'A minor',
    genre: 'Synthetic '.padEnd(150, 'genre-'), mood: 'Synthetic reflected mood', tags: ['review-first', 'review-second'],
    description: 'Synthetic private description.\n<script id="private-review-injection">window.privateReviewInjected=true</script>',
  };
  await page.getByRole('button', { name: 'New track', exact: true }).click();
  let dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading')).toBeVisible();
  for (const [label, value] of [['Title', data.title], ['Slug', data.slug], ['Artist', data.artist], ['Bpm', data.bpm],
    ['Musical key', data.musicalKey], ['Genre', data.genre], ['Mood', data.mood], ['Description', data.description]]) {
    await dialog.getByLabel(label, { exact: false }).fill(value);
  }
  const tagInput = dialog.getByLabel('Tags', { exact: false }).and(dialog.locator('input[type="text"]'));
  for (const tag of data.tags) {
    await tagInput.fill(tag);
    await tagInput.press('Enter');
    await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label').filter({ hasText: tag })).toBeVisible();
  }
  await syncSuccessNotification(page, 'Created',
    () => dialog.getByRole('button', { name: 'Create', exact: true }).click(),
    async () => { await expect(dialog.getByRole('heading')).toBeHidden(); });
  await searchTracks(page, data.title);
  const id = await row(page, data.title).getByRole('checkbox').getAttribute('value');
  expect(id).toMatch(/^[1-9]\d*$/);
  let review = await expectPrivateReview(page, data, id!);
  await page.screenshot({ path: testInfo.outputPath('private-track-review-missing-media.png'), fullPage: false });
  await closeDialog(page, review.dialog, 'Close');
  await expect(review.launch).toBeFocused();
  await expect(row(page, data.title).getByText('draft', { exact: true })).toBeVisible();

  // The review has no editor controls. An ordinary edit between openings proves the next review reads fresh persisted values.
  await row(page, data.title).getByRole('button', { name: 'Edit', exact: true }).click();
  dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading')).toBeVisible();
  const changed = { ...data, mood: 'Synthetic newly saved mood', description: data.description + '\nFreshly persisted second opening.' };
  await dialog.getByLabel('Mood', { exact: false }).fill(changed.mood);
  await dialog.getByLabel('Description', { exact: false }).fill(changed.description);
  await syncSuccessNotification(page, 'Saved',
    () => dialog.getByRole('button', { name: 'Save changes', exact: true }).click(),
    async () => { await expect(dialog.getByRole('heading')).toBeHidden(); });
  await page.goto('/admin/tracks');
  await searchTracks(page, data.title);
  review = await expectPrivateReview(page, changed, id!);
  await closeDialog(page, review.dialog, 'Close');
  await expect(row(page, data.title).getByText('draft', { exact: true })).toBeVisible();
  expect(mediaRequests).toEqual([]);

  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    const denied = await visitor.get('/admin/tracks', { maxRedirects: 0, headers: { Accept: 'text/html' } });
    expect(denied.status()).toBe(302);
    expect(new URL(denied.headers().location, 'http://127.0.0.1:8173').pathname).toBe('/admin/login');
    expect(denied.headers()['cache-control']).toContain('private');
    expect(denied.headers()['cache-control']).toContain('no-store');
    expect(denied.headers()['x-robots-tag']).toContain('noindex');
    expect(await denied.text()).not.toContain(changed.description);
    expect((await visitor.get(`/tracks/${data.slug}`)).status()).toBe(404);
    const catalog = await visitor.get('/api/catalog');
    expect(catalog.status()).toBe(200);
    const content = await catalog.text();
    expect(content).not.toContain(data.slug);
    expect(content).not.toContain(data.title);
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
