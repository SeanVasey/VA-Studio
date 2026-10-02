import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Metadata = {
  title: string; slug: string; artist: string; bpm: string; musicalKey: string;
  genre: string; mood: string; tags: string[]; description: string;
};
const staleMessage = 'This track changed after publication review. Close and reopen the confirmation before trying again.';
const blockers = [
  'The latest rights declaration must be verified.',
  'A verified artwork asset is required.',
  'A verified preview_tagged asset is required.',
  'Measured preview duration and waveform peaks are required.',
  'At least one active license offer is required.',
];
const row = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });

function actionResponse(page: Page, method: 'mountAction' | 'unmountAction' | 'callMountedAction', name?: string) {
  return page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string; params?: unknown[] }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === method
      && (name === undefined || call.params?.[0] === name))) ?? false;
  });
}

async function login(page: Page) {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

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
  await expect(row(page, title)).toBeVisible();
}

async function expectModalFits(page: Page, dialog: Locator) {
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  expect(await dialog.locator('.fi-modal-window').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

async function closeDialog(page: Page, dialog: Locator, label: 'Cancel' | 'Close') {
  const close = dialog.locator('.fi-modal-footer').getByRole('button', { name: label, exact: true });
  await close.focus();
  await expect(close).toBeFocused();
  const [closed] = await Promise.all([actionResponse(page, 'unmountAction'), close.press('Enter')]);
  expect(closed.status()).toBe(200);
  expect(await closed.finished()).toBeNull();
  await expect(dialog.getByRole('heading')).toBeHidden();
}

async function openPublication(page: Page, title: string) {
  await page.bringToFront();
  const launch = row(page, title).getByRole('button', { name: 'Publish', exact: true });
  await launch.focus();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'publish'), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  expect(opened.headers()['cache-control']).toContain('private');
  expect(opened.headers()['cache-control']).toContain('no-store');
  expect(opened.headers()['x-robots-tag']).toContain('noindex');
  const dialog = page.getByRole('alertdialog', { name: 'Publish', exact: true });
  await expect(dialog.getByRole('heading', { name: 'Publish', exact: true })).toBeVisible();
  const confirm = dialog.getByRole('button', { name: 'Confirm', exact: true });
  await confirm.focus();
  await expect(confirm).toBeFocused();
  // Prove native keyboard traversal stays within the existing confirmation trap.
  await page.keyboard.press('Tab');
  await expectModalFits(page, dialog);
  return { dialog, launch, confirm };
}

async function expectMetadata(dialog: Locator, data: Metadata) {
  for (const [label, value] of [
    ['Title', data.title], ['Slug', data.slug], ['Artist', data.artist], ['Bpm', data.bpm],
    ['Musical key', data.musicalKey], ['Genre', data.genre], ['Mood', data.mood], ['Description', data.description],
  ]) await expect(dialog.getByLabel(label, { exact: false })).toHaveValue(value);
  await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label')).toHaveText(data.tags);
}

test('manual publication consumes cancelled reviews, rejects concurrent metadata and freshly checks missing media', async ({ page, browser, playwright }, testInfo) => {
  const failures: string[] = [];
  const mediaRequests: string[] = [];
  const watch = (target: Page) => {
    target.on('pageerror', error => failures.push(error.message));
    target.on('request', request => {
      if (/^\/(?:admin\/media\/|media\/)/.test(new URL(request.url()).pathname)) mediaRequests.push(request.url());
    });
  };
  watch(page);
  const data: Metadata = {
    title: `Synthetic publication guard ${testInfo.project.name}`, slug: `publication-guard-${testInfo.project.name}`,
    artist: 'SYNTHETIC PUBLICATION ARTIST', bpm: '96', musicalKey: 'C minor', genre: 'Synthetic guarded genre',
    mood: 'Synthetic original mood', tags: ['publication-first', 'ordered-second'],
    description: 'Synthetic private publication note retained before confirmation.',
  };
  await login(page);
  await page.goto('/admin/tracks');
  await page.getByRole('button', { name: 'New track', exact: true }).click();
  let dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading')).toBeVisible();
  for (const [label, value] of [
    ['Title', data.title], ['Slug', data.slug], ['Artist', data.artist], ['Bpm', data.bpm],
    ['Musical key', data.musicalKey], ['Genre', data.genre], ['Mood', data.mood], ['Description', data.description],
  ]) await dialog.getByLabel(label, { exact: false }).fill(value);
  const tags = dialog.getByLabel('Tags', { exact: false }).and(dialog.locator('input[type="text"]'));
  for (const tag of data.tags) {
    await tags.fill(tag);
    await tags.press('Enter');
    await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label').filter({ hasText: tag })).toBeVisible();
  }
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Create', exact: true }).click(),
    async () => { await expect(dialog.getByRole('heading')).toBeHidden(); });
  await searchTracks(page, data.title);
  await expect(row(page, data.title).getByText('draft', { exact: true })).toBeVisible();

  let publication = await openPublication(page, data.title);
  await closeDialog(page, publication.dialog, 'Cancel');
  await expect(publication.launch).toBeFocused();
  await expect(page.getByRole('heading', { name: 'Publication blocked', exact: true })).toHaveCount(0);
  publication = await openPublication(page, data.title);

  const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8173', viewport: page.viewportSize() });
  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    const other = await otherContext.newPage();
    watch(other);
    await login(other);
    await other.goto('/admin/tracks');
    await searchTracks(other, data.title);
    await row(other, data.title).getByRole('button', { name: 'Edit', exact: true }).click();
    const editor = other.getByRole('dialog');
    await expectMetadata(editor, data);
    const winner = { ...data, mood: 'Synthetic concurrent winning mood', description: 'Synthetic private winner note from the second real operator context.' };
    await editor.getByLabel('Mood', { exact: false }).fill(winner.mood);
    await editor.getByLabel('Description', { exact: false }).fill(winner.description);
    await syncSuccessNotification(other, 'Saved', () => editor.getByRole('button', { name: 'Save changes', exact: true }).click(),
      async () => { await expect(editor.getByRole('heading')).toBeHidden(); });

    await page.bringToFront();
    await expect(publication.dialog.getByRole('heading')).toBeVisible();
    await publication.confirm.focus();
    await expectModalFits(page, publication.dialog);
    await syncSuccessNotification(page, 'Publication blocked', async () => {
      const [submitted] = await Promise.all([actionResponse(page, 'callMountedAction'), publication.confirm.press('Enter')]);
      expect(submitted.status()).toBe(200);
      expect(await submitted.finished()).toBeNull();
      await expect(publication.dialog.getByRole('heading')).toBeHidden();
    }, async () => {
      const notification = page.locator('.fi-no-notification').filter({ has: page.getByRole('heading', { name: 'Publication blocked', exact: true }) });
      await expect(notification.getByText(staleMessage, { exact: true })).toBeVisible();
      for (const privateValue of [data.description, winner.description, winner.mood, ...blockers]) await expect(notification).not.toContainText(privateValue);
      await page.screenshot({ path: testInfo.outputPath('publication-stale-review.png'), fullPage: false });
      await testInfo.attach('publication-stale-notification-dom', { body: await notification.evaluate(element => element.outerHTML), contentType: 'text/html' });
    });

    // The stale review was consumed. Reopening captures the winner, then real readiness still blocks this media-free draft.
    publication = await openPublication(page, data.title);
    await publication.confirm.focus();
    await syncSuccessNotification(page, 'Publication blocked', async () => {
      const [submitted] = await Promise.all([actionResponse(page, 'callMountedAction'), publication.confirm.press('Enter')]);
      expect(submitted.status()).toBe(200);
      expect(await submitted.finished()).toBeNull();
      await expect(publication.dialog.getByRole('heading')).toBeHidden();
    }, async () => {
      const notification = page.locator('.fi-no-notification').filter({ has: page.getByRole('heading', { name: 'Publication blocked', exact: true }) });
      await expect(notification.getByText(blockers.join(' '), { exact: true })).toBeVisible();
      for (const privateValue of [staleMessage, data.description, winner.description, winner.mood]) await expect(notification).not.toContainText(privateValue);
      await page.screenshot({ path: testInfo.outputPath('publication-fresh-readiness.png'), fullPage: false });
      await testInfo.attach('publication-readiness-notification-dom', { body: await notification.evaluate(element => element.outerHTML), contentType: 'text/html' });
    });

    await page.goto('/admin/tracks');
    await searchTracks(page, data.title);
    await expect(row(page, data.title).getByText('draft', { exact: true })).toBeVisible();
    await row(page, data.title).getByRole('button', { name: 'Edit', exact: true }).click();
    dialog = page.getByRole('dialog');
    await expectMetadata(dialog, winner);
    await closeDialog(page, dialog, 'Cancel');
    await row(page, data.title).getByRole('button', { name: 'Review track', exact: true }).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Private track review', exact: true })).toBeVisible();
    const metadata = dialog.getByRole('group', { name: 'Metadata', exact: true });
    await expect(metadata.locator('dt').filter({ hasText: /^Metadata revision$/ }).locator('xpath=following-sibling::dd[1]')).toHaveText('2');
    await expect(dialog.getByText('Status: draft', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('list', { name: 'Publication blockers', exact: true }).getByRole('listitem')).toHaveText(blockers);
    await expect(metadata.getByText(winner.description, { exact: true })).toBeVisible();
    await expectModalFits(page, dialog);
    await closeDialog(page, dialog, 'Close');

    expect((await visitor.get(`/tracks/${data.slug}`)).status()).toBe(404);
    const catalog = await visitor.get('/api/catalog');
    expect(catalog.status()).toBe(200);
    for (const privateValue of [data.title, data.slug, data.description, winner.description, winner.mood]) expect(await catalog.text()).not.toContain(privateValue);
    expect(mediaRequests).toEqual([]);
    expect(failures).toEqual([]);
  } finally {
    await otherContext.close();
    await visitor.dispose();
  }
});
