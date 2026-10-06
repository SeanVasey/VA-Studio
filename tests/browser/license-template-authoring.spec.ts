import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse, closeDialog, expectModalFits, login, row } from './publication-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Identity = { name: string; slug: string; type: 'non-exclusive' | 'exclusive' | 'free' };
const templatesPath = '/admin/license-templates';

async function searchTemplates(page: Page, name: string) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === name) ?? false;
  });
  await page.getByRole('main').getByRole('searchbox', { name: 'Search', exact: true }).fill(name);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(row(page, name).first()).toBeVisible();
}

async function openDialog(page: Page, launch: Locator, action: 'create' | 'edit') {
  await page.bringToFront();
  await launch.focus();
  await expect(launch).toBeFocused();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', action), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: action === 'create' ? 'Create License Template' : 'Edit license template', exact: true })).toBeVisible();
  await expectModalFits(page, dialog);
  return dialog;
}

async function editTemplate(page: Page, name: string) {
  await page.goto(templatesPath);
  await searchTemplates(page, name);
  await expect(row(page, name).getByText('Editable', { exact: true })).toBeVisible();
  return openDialog(page, row(page, name).getByRole('button', { name: 'Edit', exact: true }), 'edit');
}

async function fillIdentity(dialog: Locator, identity: Identity) {
  await dialog.getByLabel('Name', { exact: false }).fill(identity.name);
  await dialog.getByLabel('Slug', { exact: false }).fill(identity.slug);
  await dialog.getByLabel('Type', { exact: false }).selectOption(identity.type);
}

async function expectIdentity(dialog: Locator, identity: Identity) {
  await expect(dialog.getByLabel('Name', { exact: false })).toHaveValue(identity.name);
  await expect(dialog.getByLabel('Slug', { exact: false })).toHaveValue(identity.slug);
  await expect(dialog.getByLabel('Type', { exact: false })).toHaveValue(identity.type);
}

async function frozenTemplates(page: Page) {
  // Ordinary bootstrap creates these through the real submit/approve/publish services
  // for its retained customer purchases. No status or publication fixture is fabricated here.
  await page.goto(templatesPath);
  await searchTemplates(page, 'NONBINDING TEST FIXTURE');
  const frozen = row(page, 'NONBINDING TEST FIXTURE');
  expect(await frozen.count()).toBeGreaterThan(0);
  for (const item of await frozen.all()) {
    await expect(item.getByText('Frozen after review', { exact: true })).toBeVisible();
    await expect(item.getByText('Create a new successor template for identity changes.', { exact: true })).toBeVisible();
    await expect(item.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
  }
  return (await frozen.allTextContents()).map(text => text.replace(/\s+/g, ' ').trim());
}

test('operator creates and edits template identity, rejects stale saves and preserves frozen templates', async ({ page, context }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  const watch = (target: Page) => target.on('pageerror', error => failures.push(error.message));
  watch(page);
  const original: Identity = {
    name: `Synthetic template ${testInfo.project.name}`,
    slug: `synthetic-template-${testInfo.project.name}`,
    type: 'non-exclusive',
  };
  const winner: Identity = { name: `${original.name} saved`, slug: `${original.slug}-saved`, type: 'free' };
  const loser: Identity = { name: `${original.name} losing`, slug: `${original.slug}-losing`, type: 'exclusive' };
  const recovered: Identity = { name: `${original.name} recovered`, slug: `${original.slug}-recovered`, type: 'non-exclusive' };

  await login(page);
  const retained = await frozenTemplates(page);
  await page.screenshot({ path: testInfo.outputPath('license-template-frozen-guidance.png'), fullPage: false });
  await page.goto(templatesPath);
  let dialog = await openDialog(page, page.getByRole('button', { name: 'New license template', exact: true }), 'create');
  await expect(dialog.getByText('Create a template identity. Author and review its license versions separately; creating a template does not publish terms or offers.', { exact: true })).toBeVisible();
  const name = dialog.getByLabel('Name', { exact: false });
  await name.focus();
  await expect(name).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(dialog.getByLabel('Slug', { exact: false })).toBeFocused();
  // Nonempty whitespace reaches server validation instead of the browser's required-input bubble.
  await fillIdentity(dialog, { ...original, name: '   ' });
  const [invalid] = await Promise.all([
    actionResponse(page, 'callMountedAction'),
    dialog.getByRole('button', { name: 'Create', exact: true }).click(),
  ]);
  expect(invalid.status()).toBe(200);
  expect(await invalid.finished()).toBeNull();
  const required = dialog.getByText(/name.*required/i);
  await expect(required).toBeVisible();
  await expect(dialog.getByLabel('Slug', { exact: false })).toHaveValue(original.slug);
  await expect(dialog.getByLabel('Type', { exact: false })).toHaveValue(original.type);
  await required.scrollIntoViewIfNeeded();
  await expect(required).toBeInViewport();
  await expectModalFits(page, dialog);
  await page.screenshot({ path: testInfo.outputPath('license-template-field-error.png'), fullPage: false });
  await name.focus();
  await expect(name).toBeFocused();
  await name.fill(original.name);
  await syncSuccessNotification(page, 'Created', () => dialog.getByRole('button', { name: 'Create', exact: true }).click());
  await expect(dialog.getByRole('heading')).toBeHidden();

  // Both real tabs retain the same baseline before either save; no transport is intercepted.
  dialog = await editTemplate(page, original.name);
  await expectIdentity(dialog, original);
  await expect(dialog.getByText(/Template identity is frozen after a version enters review/)).toBeVisible();
  const other = await context.newPage();
  watch(other);
  try {
    const otherDialog = await editTemplate(other, original.name);
    await expectIdentity(otherDialog, original);
    await fillIdentity(otherDialog, winner);
    await syncSuccessNotification(other, 'Saved', () => otherDialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(otherDialog.getByRole('heading')).toBeHidden();

    await page.bringToFront();
    await fillIdentity(dialog, loser);
    await syncSuccessNotification(page, 'Reopen template to continue', async () => {
      const [blocked] = await Promise.all([
        actionResponse(page, 'callMountedAction'),
        dialog.getByRole('button', { name: 'Save changes', exact: true }).click(),
      ]);
      expect(blocked.status()).toBe(200);
      expect(await blocked.finished()).toBeNull();
    }, async () => {
      const stale = dialog.getByText('This template changed since you opened it. Close and reopen the editor, then review your changes.', { exact: true });
      await expect(stale).toBeVisible();
      await expectIdentity(dialog, loser);
      await stale.scrollIntoViewIfNeeded();
      await expect(stale).toBeInViewport();
      await expectModalFits(page, dialog);
      await page.screenshot({ path: testInfo.outputPath('license-template-stale-edit.png'), fullPage: false });
    });
    // A rejected edit consumes its review; another click cannot silently recapture the winner.
    await syncSuccessNotification(page, 'Reopen template to continue', () =>
      dialog.getByRole('button', { name: 'Save changes', exact: true }).click(), async () => {
      await expect(dialog.getByText('Close and reopen the current template before saving.', { exact: true })).toBeVisible();
      await expectIdentity(dialog, loser);
    });
    await closeDialog(page, dialog, 'Cancel');

    // Fresh documents prove saved identity independently of either tab's retained form state.
    dialog = await editTemplate(page, winner.name);
    await expectIdentity(dialog, winner);
    await fillIdentity(dialog, recovered);
    await syncSuccessNotification(page, 'Saved', () => dialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(dialog.getByRole('heading')).toBeHidden();
    dialog = await editTemplate(page, recovered.name);
    await expectIdentity(dialog, recovered);
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('license-template-saved-reload.png'), fullPage: false });
    await closeDialog(page, dialog, 'Cancel');
    await expect(row(page, recovered.name).getByRole('button', { name: 'Edit', exact: true })).toBeFocused();
    expect(await frozenTemplates(page)).toEqual(retained);
  } finally {
    await other.close();
  }
  expect(failures).toEqual([]);
});
