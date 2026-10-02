import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Metadata = {
  artist: string; bpm: string; musicalKey: string; genre: string; mood: string;
  tags: string[]; description: string;
};

const original: Metadata = {
  artist: 'SYNTHETIC PRESET ARTIST', bpm: '92', musicalKey: 'D minor',
  genre: 'Synthetic hip-hop', mood: 'Synthetic reflective',
  tags: ['preset-original', 'ordered-second'],
  description: 'Synthetic metadata for isolated browser verification only.',
};

const row = (page: Page, name: string) => page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) });

async function login(page: Page, expectedPath: '/admin' | '/admin/track-metadata-presets' = '/admin') {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(expectedPath);
}

async function searchTable(page: Page, query: string) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === query) ?? false;
  });
  await page.getByRole('searchbox', { name: 'Search', exact: true }).fill(query);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(page.getByText(`Search: ${query}`, { exact: true })).toBeVisible();
}

async function locatePreset(page: Page, name: string) {
  await page.goto('/admin/track-metadata-presets');
  await searchTable(page, name);
  await expect(row(page, name)).toBeVisible();
}

async function locateTrack(page: Page, title: string) {
  await page.goto('/admin/tracks');
  await searchTable(page, title);
  await expect(row(page, title)).toBeVisible();
}

async function expectModalFits(page: Page, dialog: Locator) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  expect(await dialog.locator('.fi-modal-window').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
}

async function openDialog(page: Page, action: Locator, heading?: string) {
  await page.bringToFront();
  await action.focus();
  await action.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(heading === undefined ? dialog.getByRole('heading') : dialog.getByRole('heading', { name: heading, exact: true })).toBeVisible();
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  await expectModalFits(page, dialog);
  return dialog;
}

async function addTags(dialog: Locator, tags: string[]) {
  const input = dialog.getByLabel('Tags', { exact: false }).and(dialog.locator('input[type="text"]'));
  for (const tag of tags) {
    await input.fill(tag);
    await input.press('Enter');
    await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label').filter({ hasText: tag })).toBeVisible();
  }
  await expect(input).toHaveValue('');
}

async function fillMetadata(dialog: Locator, data: Metadata) {
  await dialog.getByLabel('Artist', { exact: false }).fill(data.artist);
  await dialog.getByLabel('Bpm', { exact: false }).fill(data.bpm);
  await dialog.getByLabel('Musical key', { exact: false }).fill(data.musicalKey);
  await dialog.getByLabel('Genre', { exact: false }).fill(data.genre);
  await dialog.getByLabel('Mood', { exact: false }).fill(data.mood);
  await addTags(dialog, data.tags);
  await dialog.getByLabel('Description', { exact: false }).fill(data.description);
}

async function expectMetadata(dialog: Locator, data: Metadata) {
  await expect(dialog.getByLabel('Artist', { exact: false })).toHaveValue(data.artist);
  await expect(dialog.getByLabel('Bpm', { exact: false })).toHaveValue(data.bpm);
  await expect(dialog.getByLabel('Musical key', { exact: false })).toHaveValue(data.musicalKey);
  await expect(dialog.getByLabel('Genre', { exact: false })).toHaveValue(data.genre);
  await expect(dialog.getByLabel('Mood', { exact: false })).toHaveValue(data.mood);
  await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label')).toHaveText(data.tags);
  await expect(dialog.getByLabel('Description', { exact: false })).toHaveValue(data.description);
}

async function cancelDialog(page: Page, dialog: Locator) {
  // Await the actual unmount acknowledgement before opening another action or navigating.
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
  });
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  const closed = await response;
  expect(closed.status()).toBe(200);
  expect(await closed.finished()).toBeNull();
  await expect(dialog.getByRole('heading')).toBeHidden();
}

async function createPreset(page: Page, name: string, data: Metadata) {
  await page.goto('/admin/track-metadata-presets');
  const dialog = await openDialog(page, page.getByRole('button', { name: 'Create preset', exact: true }), 'Create metadata preset');
  await dialog.getByLabel('Preset name', { exact: false }).fill(name);
  await fillMetadata(dialog, data);
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Save preset', exact: true }).click());
  await expect(dialog.getByRole('heading')).toBeHidden();
  await locatePreset(page, name);
  await expect(row(page, name).getByText('Active', { exact: true })).toBeVisible();
}

async function editPreset(page: Page, name: string) {
  await locatePreset(page, name);
  return openDialog(page, row(page, name).getByRole('button', { name: 'Edit', exact: true }), 'Edit metadata preset');
}

async function archivePreset(page: Page, name: string) {
  await locatePreset(page, name);
  await row(page, name).getByRole('button', { name: 'Archive', exact: true }).click();
  const confirmation = page.getByRole('alertdialog', { name: 'Archive metadata preset', exact: true });
  await expect(confirmation.getByRole('heading', { name: 'Archive metadata preset', exact: true })).toBeVisible();
  await syncSuccessNotification(page, 'Metadata preset archived', () => confirmation.getByRole('button', { name: 'Archive preset', exact: true }).click());
  await expect(confirmation.getByRole('heading')).toBeHidden();
  await locatePreset(page, name);
  await expect(row(page, name).getByText('Archived', { exact: true })).toBeVisible();
}

async function copyPreset(page: Page, name: string, data: Metadata) {
  await page.goto('/admin/tracks');
  let dialog = await openDialog(page, page.getByRole('button', { name: 'Create from preset', exact: true }), 'Create from preset');
  const select = dialog.getByLabel('Metadata preset', { exact: false });
  await expect(select).toBeVisible();
  await expect(select).toBeEnabled();
  // Names are not unique. The visible option includes the persisted ID that disambiguates the copy.
  // Native option markup pads its label; accept only that outer formatting whitespace.
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const option = select.locator('option').filter({ hasText: new RegExp(`^\\s*${escapedName} \\(#\\d+\\)\\s*$`) });
  await expect(option).toHaveCount(1);
  const id = await option.getAttribute('value');
  const label = (await option.textContent())?.trim();
  expect(id).toMatch(/^[1-9]\d*$/);
  expect(label).toBe(`${name} (#${id})`);
  await select.selectOption(id!);
  await expect(select).toHaveValue(id!);
  await expect(select.locator('option:checked')).toHaveText(label!);
  await dialog.getByRole('button', { name: 'Copy metadata', exact: true }).click();
  dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Create private draft from preset', exact: true })).toBeVisible();
  await expectMetadata(dialog, data);
  await expect(dialog.getByLabel('Title', { exact: false })).toHaveValue('');
  await expect(dialog.getByLabel('Slug', { exact: false })).toHaveValue('');
  await expectModalFits(page, dialog);
  return dialog;
}

async function expectPrivateTrack(page: Page, title: string, slug: string, data: Metadata) {
  // A new document and normal editor prove persisted track values, not retained form state.
  await locateTrack(page, title);
  await expect(row(page, title).getByText('draft', { exact: true })).toBeVisible();
  const dialog = await openDialog(page, row(page, title).getByRole('button', { name: 'Edit', exact: true }));
  await expect(dialog.getByLabel('Title', { exact: false })).toHaveValue(title);
  await expect(dialog.getByLabel('Slug', { exact: false })).toHaveValue(slug);
  await expectMetadata(dialog, data);
  await cancelDialog(page, dialog);
}

test('preset authoring recovers from field errors and creates an independent private draft', async ({ page, playwright }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  const name = `Synthetic authoring preset ${testInfo.project.name}`;
  const title = `Synthetic preset draft ${testInfo.project.name}`;
  const slug = `preset-draft-${testInfo.project.name}`;
  await page.goto('/admin/track-metadata-presets');
  await expect(page).toHaveURL(/\/admin\/login$/);
  await login(page, '/admin/track-metadata-presets');
  await page.goto('/admin/track-metadata-presets');
  let dialog = await openDialog(page, page.getByRole('button', { name: 'Create preset', exact: true }), 'Create metadata preset');
  // Nonempty whitespace reaches the server's required-name validation rather than a native required-input bubble.
  await dialog.getByLabel('Preset name', { exact: false }).fill('   ');
  await fillMetadata(dialog, original);
  await dialog.getByRole('button', { name: 'Save preset', exact: true }).click();
  const error = dialog.getByText(/name.*required/i);
  await expect(error).toBeVisible();
  await expectMetadata(dialog, original);
  await error.scrollIntoViewIfNeeded();
  await expect(error).toBeInViewport();
  await expectModalFits(page, dialog);
  await page.screenshot({ path: testInfo.outputPath('preset-validation.png'), fullPage: false });
  await dialog.getByLabel('Preset name', { exact: false }).focus();
  await expect(dialog.getByLabel('Preset name', { exact: false })).toBeFocused();
  await dialog.getByLabel('Preset name', { exact: false }).fill(name);
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Save preset', exact: true }).click());
  await expect(dialog.getByRole('heading')).toBeHidden();

  dialog = await editPreset(page, name);
  await expectMetadata(dialog, original);
  const reviewed = { ...original, genre: 'Synthetic reviewed genre', tags: [...original.tags, 'reviewed-third'] };
  await dialog.getByLabel('Genre', { exact: false }).fill(reviewed.genre);
  await addTags(dialog, ['reviewed-third']);
  await syncSuccessNotification(page, 'Saved', () => dialog.getByRole('button', { name: 'Save changes', exact: true }).click());
  await expect(dialog.getByRole('heading')).toBeHidden();
  dialog = await editPreset(page, name);
  await expectMetadata(dialog, reviewed);
  await cancelDialog(page, dialog);

  dialog = await copyPreset(page, name, reviewed);
  const copied = { ...reviewed, artist: 'SYNTHETIC DRAFT OVERRIDE' };
  await dialog.getByLabel('Title', { exact: false }).fill(title);
  await dialog.getByLabel('Slug', { exact: false }).fill(slug);
  await dialog.getByLabel('Artist', { exact: false }).fill(copied.artist);
  await page.screenshot({ path: testInfo.outputPath('preset-copied-draft.png'), fullPage: false });
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Create private draft', exact: true }).click());
  await expect(dialog.getByRole('heading')).toBeHidden();
  await expectPrivateTrack(page, title, slug, copied);

  await archivePreset(page, name);
  // Archived source records cannot be selected for another new copy.
  await page.goto('/admin/tracks');
  dialog = await openDialog(page, page.getByRole('button', { name: 'Create from preset', exact: true }), 'Create from preset');
  await expect(dialog.getByLabel('Metadata preset', { exact: false }).locator('option').filter({ hasText: name })).toHaveCount(0);
  await cancelDialog(page, dialog);
  await expectPrivateTrack(page, title, slug, copied);

  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    expect((await visitor.get(`/tracks/${slug}`)).status()).toBe(404);
    const publicCatalog = await visitor.get('/api/catalog');
    expect(publicCatalog.status()).toBe(200);
    expect(await publicCatalog.text()).not.toContain(slug);
    expect(await publicCatalog.text()).not.toContain(title);
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});

test('stale preset edits preserve the winner and copied forms survive source edits and archive', async ({ page, browser, playwright }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  const watch = (target: Page) => target.on('pageerror', error => failures.push(error.message));
  watch(page);
  const name = `Synthetic snapshot preset ${testInfo.project.name}`;
  const title = `Synthetic retained preset copy ${testInfo.project.name}`;
  const slug = `preset-snapshot-${testInfo.project.name}`;
  await login(page);
  await createPreset(page, name, original);
  let dialog = await editPreset(page, name);
  await expectMetadata(dialog, original);
  const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8173', viewport: page.viewportSize() });
  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    const other = await otherContext.newPage();
    watch(other);
    await login(other);
    const winner = { ...original, artist: 'SYNTHETIC WINNING PRESET', tags: [...original.tags, 'winner-tag'] };
    const otherDialog = await editPreset(other, name);
    await otherDialog.getByLabel('Artist', { exact: false }).fill(winner.artist);
    await addTags(otherDialog, ['winner-tag']);
    await syncSuccessNotification(other, 'Saved', () => otherDialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(otherDialog.getByRole('heading')).toBeHidden();

    await page.bringToFront();
    await dialog.getByLabel('Artist', { exact: false }).fill('SYNTHETIC LOSING PRESET');
    await dialog.getByRole('button', { name: 'Save changes', exact: true }).click();
    const error = dialog.getByText('This preset changed since you opened it. Close and reopen the editor, then apply your changes.', { exact: true });
    await expect(error).toBeVisible();
    await expect(dialog.getByLabel('Artist', { exact: false })).toHaveValue('SYNTHETIC LOSING PRESET');
    await error.scrollIntoViewIfNeeded();
    await expect(error).toBeInViewport();
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('preset-stale-edit.png'), fullPage: false });
    await cancelDialog(page, dialog);
    dialog = await editPreset(page, name);
    await expectMetadata(dialog, winner);
    await cancelDialog(page, dialog);

    dialog = await copyPreset(page, name, winner);
    await dialog.getByLabel('Title', { exact: false }).fill(title);
    await dialog.getByLabel('Slug', { exact: false }).fill(slug);
    const copied = { ...winner, mood: 'Synthetic copied-form override' };
    await dialog.getByLabel('Mood', { exact: false }).fill(copied.mood);

    // Change then archive the real source while the other operator still reviews its copied metadata.
    const editedSource = await editPreset(other, name);
    await editedSource.getByLabel('Artist', { exact: false }).fill('SYNTHETIC LATER SOURCE');
    await editedSource.getByLabel('Genre', { exact: false }).fill('Synthetic later source genre');
    await syncSuccessNotification(other, 'Saved', () => editedSource.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(editedSource.getByRole('heading')).toBeHidden();
    await archivePreset(other, name);

    await page.bringToFront();
    await expectMetadata(dialog, copied);
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('preset-independent-copy.png'), fullPage: false });
    await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Create private draft', exact: true }).click());
    await expect(dialog.getByRole('heading')).toBeHidden();
    await expectPrivateTrack(page, title, slug, copied);
    expect((await visitor.get(`/tracks/${slug}`)).status()).toBe(404);
    const publicCatalog = await visitor.get('/api/catalog');
    expect(publicCatalog.status()).toBe(200);
    expect(await publicCatalog.text()).not.toContain(slug);
    expect(await publicCatalog.text()).not.toContain(title);
    expect(failures).toEqual([]);
  } finally {
    await otherContext.close();
    await visitor.dispose();
  }
});
