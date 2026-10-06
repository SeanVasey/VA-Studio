import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse, closeDialog, expectModalFits, login, row } from './publication-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

async function searchTable(page: Page, title: string) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === title) ?? false;
  });
  await page.getByRole('main').getByRole('searchbox', { name: 'Search', exact: true }).fill(title);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(row(page, title)).toBeVisible();
}

async function openReview(page: Page, title: string) {
  await page.bringToFront();
  const launch = row(page, title).getByRole('button', { name: 'Refresh track snapshots', exact: true });
  await launch.focus();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'refreshMembers'), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Review descriptive track snapshots', exact: true })).toBeVisible();
  await expectModalFits(page, dialog);
  for (const label of ['Changes in this review', 'Saved track snapshots', 'Current track snapshots']) {
    await expect(dialog.getByLabel(label, { exact: true })).toHaveAttribute('readonly');
  }
  await expect(dialog.getByText(/This does not assess product readiness, set a price or license, or publish a product/)).toBeVisible();
  return dialog;
}

test('operator reviews changed member snapshots and retains the earlier private draft version', async ({ page, context }, testInfo) => {
  const title = `Synthetic member source ${testInfo.project.name}`;
  const renamed = `Synthetic refreshed member ${testInfo.project.name}`;
  const draftTitle = `Synthetic member collection ${testInfo.project.name}`;
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await login(page);
  await page.goto('/admin/tracks');
  await page.getByRole('button', { name: 'New track', exact: true }).click();
  let dialog = page.getByRole('dialog');
  await dialog.getByLabel('Title', { exact: false }).fill(title);
  await dialog.getByLabel('Slug', { exact: false }).fill(`synthetic-member-refresh-${testInfo.project.name}`);
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Create', exact: true }).click());
  await page.goto('/admin/collection-album-drafts');
  await page.getByRole('button', { name: 'Create draft', exact: true }).click();
  dialog = page.getByRole('dialog');
  await dialog.getByLabel('Title', { exact: false }).fill(draftTitle);
  await dialog.getByLabel('Description', { exact: false }).fill('Synthetic retained collection notes');
  await dialog.getByRole('combobox', { name: 'Track', exact: false }).click();
  await page.getByRole('textbox', { name: 'Search', exact: true }).fill(title);
  await page.getByRole('option', { name: new RegExp(`^${title} \\(#\\d+\\)$`) }).click();
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Save draft version', exact: true }).click());
  await expect(row(page, draftTitle)).toBeVisible();

  const other = await context.newPage();
  other.on('pageerror', error => failures.push(error.message));
  await other.goto('/admin/tracks');
  await other.bringToFront();
  await searchTable(other, title);
  await row(other, title).getByRole('button', { name: 'Edit', exact: true }).click();
  const editor = other.getByRole('dialog');
  await editor.getByLabel('Title', { exact: false }).fill(renamed);
  await syncSuccessNotification(other, 'Saved', () => editor.getByRole('button', { name: 'Save changes', exact: true }).click());
  await other.close();

  dialog = await openReview(page, draftTitle);
  await expect(dialog.getByLabel('Changes in this review', { exact: true })).toHaveValue(/1 of 1 track snapshots changed/);
  const saved = dialog.getByLabel('Saved track snapshots', { exact: true });
  const current = dialog.getByLabel('Current track snapshots', { exact: true });
  await expect(saved).toHaveValue(new RegExp(title));
  await expect(saved).not.toHaveValue(new RegExp(renamed));
  await expect(current).toHaveValue(new RegExp(renamed));
  await expect(current).toHaveValue(/Synthetic retained collection notes/);
  await page.screenshot({ path: testInfo.outputPath('product-member-review.png'), fullPage: false });
  await syncSuccessNotification(page, 'Track snapshots saved as a new draft version', () =>
    dialog.getByRole('button', { name: 'Confirm reviewed snapshots', exact: true }).click());

  // A fresh document must read both immutable versions, independent of retained component state.
  await page.reload();
  const [historyResponse] = await Promise.all([
    actionResponse(page, 'mountAction', 'history'),
    row(page, draftTitle).getByRole('button', { name: 'Version history', exact: true }).click(),
  ]);
  expect(historyResponse.status()).toBe(200);
  expect(await historyResponse.finished()).toBeNull();
  dialog = page.getByRole('dialog');
  await expect(dialog.getByLabel('Current draft contents', { exact: true })).toHaveValue(new RegExp(`Version 2 — ${draftTitle}`));
  await expect(dialog.getByLabel('Current draft contents', { exact: true })).toHaveValue(new RegExp(renamed));
  const history = dialog.getByLabel('Retained draft versions', { exact: true });
  await expect(history).toHaveValue(new RegExp(`Version 1 — ${draftTitle}`));
  await expect(history).toHaveValue(new RegExp(`1\\. ${title} \\(track #`));
  await closeDialog(page, dialog, 'Close');

  dialog = await openReview(page, draftTitle);
  await expect(dialog.getByLabel('Changes in this review', { exact: true })).toHaveValue('All track snapshots are current. Confirming this review will not add a draft version.');
  await syncSuccessNotification(page, 'Track snapshots are already current', () =>
    dialog.getByRole('button', { name: 'Confirm reviewed snapshots', exact: true }).click());
  await page.reload();
  await row(page, draftTitle).getByRole('button', { name: 'Version history', exact: true }).click();
  dialog = page.getByRole('dialog');
  await expect(dialog.getByLabel('Current draft contents', { exact: true })).toHaveValue(new RegExp(`Version 2 — ${draftTitle}`));
  await expect(dialog.getByLabel('Retained draft versions', { exact: true })).not.toHaveValue(/Version 3 —/);
  await closeDialog(page, dialog, 'Close');
  const catalog = await page.request.get('/api/catalog');
  expect(catalog.status()).toBe(200);
  expect(await catalog.text()).not.toContain(draftTitle);
  expect(failures).toEqual([]);
});
