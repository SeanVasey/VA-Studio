import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Draft = { title: string; slug: string; oldTag: string; id: string };

const trackRow = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });

async function login(page: Page) {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

async function addTags(dialog: Locator, label: string, tags: string[]) {
  const input = dialog.getByLabel(label, { exact: false }).and(dialog.locator('input[type="text"]'));
  for (const tag of tags) {
    await input.fill(tag);
    await input.press('Enter');
    await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label').filter({ hasText: tag })).toBeVisible();
  }
  await expect(input).toHaveValue('');
}

async function draft(page: Page, title: string, slug: string, oldTag: string): Promise<Omit<Draft, 'id'>> {
  await page.getByRole('button', { name: 'New track', exact: true }).click();
  const dialog = page.getByRole('dialog');
  const heading = dialog.getByRole('heading');
  await expect(heading).toBeVisible();
  await dialog.getByLabel('Title', { exact: false }).fill(title);
  await dialog.getByLabel('Slug', { exact: false }).fill(slug);
  await addTags(dialog, 'Tags', [oldTag]);
  await dialog.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(heading).toBeHidden();
  await expect(trackRow(page, title)).toBeVisible();
  expect((await page.request.get(`/tracks/${slug}`)).status()).toBe(404);
  return { title, slug, oldTag };
}

async function findPair(page: Page, prefix: string) {
  await page.goto('/admin/tracks');
  await page.getByRole('searchbox', { name: 'Search', exact: true }).fill(prefix);
  await expect(page.getByRole('row').filter({ has: page.getByText(new RegExp(`^${prefix}`)) })).toHaveCount(2);
}

async function selectPair(page: Page, drafts: Omit<Draft, 'id'>[]): Promise<Draft[]> {
  const selected: Draft[] = [];
  for (const draft of drafts) {
    const checkbox = trackRow(page, draft.title).getByRole('checkbox');
    const id = await checkbox.getAttribute('value');
    expect(id).toMatch(/^[1-9]\d*$/);
    await checkbox.check();
    await expect(checkbox).toBeChecked();
    selected.push({ ...draft, id: id! });
  }
  return selected;
}

async function cancelEdit(page: Page, dialog: Locator) {
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

async function expectSavedTags(page: Page, draft: Draft, tags: string[]) {
  await trackRow(page, draft.title).getByRole('button', { name: 'Edit', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading')).toBeVisible();
  await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label')).toHaveText(tags);
  await expect(dialog.getByLabel('Title', { exact: false })).toHaveValue(draft.title);
  await expect(dialog.getByLabel('Slug', { exact: false })).toHaveValue(draft.slug);
  await cancelEdit(page, dialog);
  expect((await page.request.get(`/tracks/${draft.slug}`)).status()).toBe(404);
}

async function expectModalFits(page: Page, dialog: Locator) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  expect(await dialog.locator('.fi-modal-window').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
}

async function openAdditions(page: Page) {
  const launch = page.getByRole('button', { name: 'Add tags', exact: true });
  await expect(launch).toBeVisible();
  await expect(launch).toBeEnabled();
  await launch.focus();
  await launch.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Add tags to selected tracks', exact: true })).toBeVisible();
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  await expectModalFits(page, dialog);
  return dialog;
}

async function reviewAdditions(page: Page, drafts: Draft[], before: string[][], after: string[][], changed: number, already: number) {
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'Review additions', exact: true }).click();
  await expect(dialog.getByRole('heading', { name: 'Review tag additions', exact: true })).toBeVisible();
  await expect(dialog.getByText(`${changed} tracks will change; ${already} already contain these tags.`, { exact: true })).toBeVisible();
  await expect(dialog.getByRole('group')).toHaveCount(drafts.length);
  for (const [index, draft] of drafts.entries()) {
    const group = dialog.getByRole('group', { name: draft.title, exact: true });
    await expect(group).toBeVisible();
    await expect(group).toContainText(new RegExp(`Track ID:\\s*${draft.id}\\b`));
    await expect(group.getByRole('list', { name: 'Current tags', exact: true }).getByRole('listitem')).toHaveText(before[index]);
    await expect(group.getByRole('list', { name: 'Proposed tags', exact: true }).getByRole('listitem')).toHaveText(after[index]);
  }
  await expect(dialog.getByRole('button', { name: 'Add reviewed tags', exact: true })).toBeEnabled();
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  await expectModalFits(page, dialog);
  return dialog;
}

async function applyReview(page: Page, dialog: Locator, message: string) {
  const apply = dialog.getByRole('button', { name: 'Add reviewed tags', exact: true });
  await apply.focus();
  await apply.press('Enter');
  await expect(dialog.getByRole('heading', { name: 'Review tag additions', exact: true })).toBeHidden();
  await expect(page.getByText(message, { exact: true })).toBeVisible();
}

test('reviewed bulk tag additions preserve existing tags, reject stale reviews and keep drafts private', async ({ page, browser }, testInfo) => {
  // This one journey creates its own pair and uses a second authenticated browser context for a real competing edit.
  test.setTimeout(120_000);
  const pageErrors: string[] = [];
  const watch = (target: Page) => target.on('pageerror', error => pageErrors.push(error.message));
  watch(page);
  await login(page);
  await page.goto('/admin/tracks');
  const prefix = `Synthetic bulk tags ${testInfo.project.name}`;
  const created = [
    await draft(page, `${prefix} A`, `bulk-tags-${testInfo.project.name}-a`, 'original-a'),
    await draft(page, `${prefix} B`, `bulk-tags-${testInfo.project.name}-b`, 'original-b'),
  ];
  await findPair(page, prefix);
  let drafts = await selectPair(page, created);
  expect(Number(drafts[0].id)).toBeLessThan(Number(drafts[1].id));
  await page.bringToFront();
  let dialog = await openAdditions(page);
  const additions = ['shared-new', 'wave-tag'];
  await addTags(dialog, 'Tags to add', additions);
  const original = drafts.map(draft => [draft.oldTag]);
  await reviewAdditions(page, drafts, original, original.map(tags => [...tags, ...additions]), 2, 0);

  // Going back removes the reviewed apply action. A changed proposal needs its own visible review before it can save.
  await dialog.getByRole('button', { name: 'Back to additions', exact: true }).click();
  await expect(dialog.getByRole('heading', { name: 'Add tags to selected tracks', exact: true })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Add reviewed tags', exact: true })).toHaveCount(0);
  additions.push('reviewed-third');
  await addTags(dialog, 'Tags to add', ['reviewed-third']);
  const saved = original.map(tags => [...tags, ...additions]);
  dialog = await reviewAdditions(page, drafts, original, saved, 2, 0);
  await page.screenshot({ path: testInfo.outputPath('bulk-tags-review.png'), fullPage: false });
  await applyReview(page, dialog, 'Tags added to 2 tracks. 0 tracks already contained these tags.');
  await findPair(page, prefix);
  for (const [index, draft] of drafts.entries()) await expectSavedTags(page, draft, saved[index]);

  // A second, freshly authorized review of the same additions is a visible no-op.
  drafts = await selectPair(page, created);
  dialog = await openAdditions(page);
  await addTags(dialog, 'Tags to add', additions);
  dialog = await reviewAdditions(page, drafts, saved, saved, 0, 2);
  await applyReview(page, dialog, 'No tags were added. All 2 reviewed tracks already contained these tags.');
  await findPair(page, prefix);
  for (const [index, draft] of drafts.entries()) await expectSavedTags(page, draft, saved[index]);

  drafts = await selectPair(page, created);
  dialog = await openAdditions(page);
  await addTags(dialog, 'Tags to add', ['stale-new']);
  await reviewAdditions(page, drafts, saved, saved.map(tags => [...tags, 'stale-new']), 2, 0);
  const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8173', viewport: page.viewportSize() });
  try {
    const other = await otherContext.newPage();
    watch(other);
    await login(other);
    await findPair(other, prefix);
    await trackRow(other, drafts[1].title).getByRole('button', { name: 'Edit', exact: true }).click();
    const otherDialog = other.getByRole('dialog');
    await expect(otherDialog.getByRole('heading')).toBeVisible();
    await addTags(otherDialog, 'Tags', ['external-edit']);
    await otherDialog.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(otherDialog.getByRole('heading')).toBeHidden();

    // The later-ID row is stale. The earlier eligible row must stay unchanged even if a broken command starts writing in ID order.
    await page.bringToFront();
    await dialog.getByRole('button', { name: 'Add reviewed tags', exact: true }).click();
    await expect(page.getByText('No changes were saved by this attempt.', { exact: true })).toBeVisible();
    await expect(page.getByText('A selected track changed after review. Review the current tracks before trying again.', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Add tags to selected tracks', exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Add reviewed tags', exact: true })).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: 'Review additions', exact: true })).toBeEnabled();
    await page.screenshot({ path: testInfo.outputPath('bulk-tags-stale-review.png'), fullPage: false });
    const current = [saved[0], [...saved[1], 'external-edit']];
    await findPair(other, prefix);
    for (const [index, draft] of drafts.entries()) await expectSavedTags(other, draft, current[index]);

    // Recovery reads both current versions through the normal review action and displays the new proposal before applying it.
    await page.bringToFront();
    dialog = await reviewAdditions(page, drafts, current, current.map(tags => [...tags, 'stale-new']), 2, 0);
    await applyReview(page, dialog, 'Tags added to 2 tracks. 0 tracks already contained these tags.');
    await findPair(page, prefix);
    for (const [index, draft] of drafts.entries()) await expectSavedTags(page, draft, [...current[index], 'stale-new']);
    expect(pageErrors).toEqual([]);
  } finally {
    await otherContext.close();
  }
});
