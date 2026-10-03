import { test, expect, type Locator, type Page } from '@playwright/test';
import { actionResponse, closeDialog, expectModalFits, login, publicationReview, row, searchTracks } from './publication-fixture';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Metadata = {
  title: string; slug: string; artist: string; bpm: string; musicalKey: string;
  genre: string; mood: string; tags: string[]; description: string;
};
const blockers = [
  'The latest rights declaration must be verified.',
  'A verified artwork asset is required.',
  'A verified preview_tagged asset is required.',
  'Measured preview duration and waveform peaks are required.',
  'At least one active license offer is required.',
];
async function expectMetadata(dialog: Locator, data: Metadata) {
  for (const [label, value] of [
    ['Title', data.title], ['Slug', data.slug], ['Artist', data.artist], ['Bpm', data.bpm],
    ['Musical key', data.musicalKey], ['Genre', data.genre], ['Mood', data.mood], ['Description', data.description],
  ]) await expect(dialog.getByLabel(label, { exact: false })).toHaveValue(value);
  await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label')).toHaveText(data.tags);
}

test('publication refuses an unready draft before opening confirmation and preserves private metadata', async ({ page, playwright }, testInfo) => {
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

  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    // No review can be captured for this media-free draft. A repeated mount must remain blocked.
    for (let attempt = 0; attempt < 2; attempt++) {
      const launch = row(page, data.title).getByRole('button', { name: 'Publish', exact: true });
      await launch.focus();
      await syncSuccessNotification(page, 'Publication blocked', async () => {
        const [mounted] = await Promise.all([actionResponse(page, 'mountAction', 'publish'), launch.press('Enter')]);
        expect(mounted.status()).toBe(200);
        expect(await mounted.finished()).toBeNull();
        expect(mounted.headers()['cache-control']).toContain('private');
        expect(mounted.headers()['cache-control']).toContain('no-store');
        expect(mounted.headers()['x-robots-tag']).toContain('noindex');
        expect(await publicationReview(mounted)).toBeNull();
        await expect(page.getByRole('alertdialog', { name: 'Publish', exact: true })).toHaveCount(0);
      }, async () => {
        const notification = page.locator('.fi-no-notification').filter({ has: page.getByRole('heading', { name: 'Publication blocked', exact: true }) });
        await expect(notification.getByText(blockers.join(' '), { exact: true })).toBeVisible();
        for (const privateValue of [data.description, data.mood]) await expect(notification).not.toContainText(privateValue);
        await page.screenshot({ path: testInfo.outputPath(`publication-mount-blocked-${attempt}.png`), fullPage: false });
      });
    }

    await page.goto('/admin/tracks');
    await searchTracks(page, data.title);
    await expect(row(page, data.title).getByText('draft', { exact: true })).toBeVisible();
    await row(page, data.title).getByRole('button', { name: 'Edit', exact: true }).click();
    dialog = page.getByRole('dialog');
    await expectMetadata(dialog, data);
    await closeDialog(page, dialog, 'Cancel');
    const reviewLaunch = row(page, data.title).getByRole('button', { name: 'Review track', exact: true });
    await reviewLaunch.focus();
    await reviewLaunch.press('Enter');
    dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Private track review', exact: true })).toBeVisible();
    const metadata = dialog.getByRole('group', { name: 'Metadata', exact: true });
    await expect(metadata.locator('dt').filter({ hasText: /^Metadata revision$/ }).locator('xpath=following-sibling::dd[1]')).toHaveText('1');
    await expect(dialog.getByText('Status: draft', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('list', { name: 'Publication blockers', exact: true }).getByRole('listitem')).toHaveText(blockers);
    await expect(metadata.getByText(data.description, { exact: true })).toBeVisible();
    await dialog.locator('.fi-modal-footer').getByRole('button', { name: 'Close', exact: true }).focus();
    await page.keyboard.press('Tab');
    await expectModalFits(page, dialog);
    await closeDialog(page, dialog, 'Close');

    expect((await visitor.get(`/tracks/${data.slug}`)).status()).toBe(404);
    const catalog = await visitor.get('/api/catalog');
    expect(catalog.status()).toBe(200);
    for (const privateValue of [data.title, data.slug, data.description, data.mood]) expect(await catalog.text()).not.toContain(privateValue);
    expect(mediaRequests).toEqual([]);
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
