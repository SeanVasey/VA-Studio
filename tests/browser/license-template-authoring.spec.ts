import { execFileSync } from 'node:child_process';
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


type DraftFixture = { name: string; versionId: number; sources: Record<'prepared' | 'winner' | 'recovered' | 'uncertain', string> };
type DraftReceipt = { verified: true; phase: string; versionId: number; source: string; updates: number; originalsUnchanged: true; guardsUnchanged: true };
function draftFixture(mode: 'prepare' | 'verify', project: string, phase?: keyof DraftFixture['sources']) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-license-draft.php', mode, project, ...(phase ? [phase] : [])], {
    cwd: process.cwd(), env: process.env, stdio: 'pipe', timeout: 30_000,
  }).toString()) as DraftFixture | DraftReceipt;
}

async function editDraft(page: Page, name: string) {
  await page.bringToFront();
  await page.goto('/admin/license-versions');
  await searchTemplates(page, name);
  const launch = row(page, name).getByRole('button', { name: 'Edit', exact: true });
  await launch.focus();
  await expect(launch).toBeFocused();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'edit'), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Edit license version', exact: true })).toBeVisible();
  await expectModalFits(page, dialog);
  return dialog;
}

function submittedDraftReview(payload: { components?: { snapshot: string; calls?: { method: string }[] }[] }) {
  const component = payload.components?.find(item => item.calls?.some(call => call.method === 'callMountedAction'));
  expect(component).toBeDefined();
  const snapshot = JSON.parse(component!.snapshot) as { data: Record<string, unknown> };
  expect(snapshot.data.draftReview).not.toBeNull();
  return snapshot.data.draftReview;
}

test('operator preserves stale draft input, reopens the current winner and recovers a lost save response', async ({ page, context }, testInfo) => {
  test.setTimeout(120_000);
  const fixture = draftFixture('prepare', testInfo.project.name) as DraftFixture;
  const verify = (phase: keyof DraftFixture['sources']) => {
    const receipt = draftFixture('verify', testInfo.project.name, phase) as DraftReceipt;
    expect(receipt).toMatchObject({ verified: true, phase, versionId: fixture.versionId, source: fixture.sources[phase],
      originalsUnchanged: true, guardsUnchanged: true });
    return receipt;
  };
  expect(verify('prepared').updates).toBe(0);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await login(page);
  let dialog = await editDraft(page, fixture.name);
  const source = () => dialog.getByLabel('Authored source', { exact: false });
  await expect(source()).toHaveValue(fixture.sources.prepared);
  await expect(dialog.getByText(/keep a copy of your entered changes, then close and reopen/)).toBeVisible();
  const other = await context.newPage();
  other.on('pageerror', error => failures.push(error.message));
  const receipts: DraftReceipt[] = [];
  try {
    const otherDialog = await editDraft(other, fixture.name);
    await expect(otherDialog.getByLabel('Authored source', { exact: false })).toHaveValue(fixture.sources.prepared);
    await otherDialog.getByLabel('Authored source', { exact: false }).fill(fixture.sources.winner);
    await syncSuccessNotification(other, 'Saved', () => otherDialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(otherDialog.getByRole('heading')).toBeHidden();
    receipts.push(verify('winner'));
    expect(receipts.at(-1)!.updates).toBe(1);

    await page.bringToFront();
    const losing = `${fixture.sources.prepared} Unsaved first-tab text.`;
    await source().fill(losing);
    await syncSuccessNotification(page, 'Reopen draft to continue', async () => {
      const [blocked] = await Promise.all([actionResponse(page, 'callMountedAction'),
        dialog.getByRole('button', { name: 'Save changes', exact: true }).click()]);
      expect(blocked.status()).toBe(200);
      expect(await blocked.finished()).toBeNull();
    }, async () => {
      const error = dialog.getByText('This license draft changed or its edit review is no longer available. Close and reopen the editor, then review your changes.', { exact: true });
      await expect(error).toBeVisible();
      await expect(source()).toHaveValue(losing);
      await error.scrollIntoViewIfNeeded();
      await expect(error).toBeInViewport();
      await expectModalFits(page, dialog);
      await page.screenshot({ path: testInfo.outputPath('license-draft-stale-input.png'), fullPage: false });
    });
    expect(verify('winner')).toEqual(receipts.at(-1));
    await syncSuccessNotification(page, 'Reopen draft to continue', () =>
      dialog.getByRole('button', { name: 'Save changes', exact: true }).click(), async () => {
      await expect(dialog.getByText('Close and reopen the current draft before saving.', { exact: true })).toBeVisible();
      await expect(source()).toHaveValue(losing);
    });
    expect(verify('winner')).toEqual(receipts.at(-1));
    await closeDialog(page, dialog, 'Cancel');
    await expect(row(page, fixture.name).getByRole('button', { name: 'Edit', exact: true })).toBeFocused();
    dialog = await editDraft(page, fixture.name);
    await expect(source()).toHaveValue(fixture.sources.winner);
    await source().fill(fixture.sources.recovered);
    await syncSuccessNotification(page, 'Saved', () => dialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(dialog.getByRole('heading')).toBeHidden();
    receipts.push(verify('recovered'));
    expect(receipts.at(-1)!.updates).toBe(2);

    dialog = await editDraft(page, fixture.name);
    await expect(source()).toHaveValue(fixture.sources.recovered);
    await source().fill(fixture.sources.uncertain);
    const submit = dialog.getByRole('button', { name: 'Save changes', exact: true });
    const livewireUrl = /\/livewire(?:-[A-Za-z0-9]+)?\/update$/;
    let intercepted = 0;
    let originalReview: unknown;
    let committed: DraftReceipt | undefined;
    // Execute the unchanged signed request, establish the actual durable save, then drop only its response.
    await page.route(livewireUrl, async route => {
      const payload = route.request().postDataJSON() as { components?: { snapshot: string; calls?: { method: string }[] }[] };
      if (!payload.components?.some(component => component.calls?.some(call => call.method === 'callMountedAction'))) {
        await route.continue();
        return;
      }
      expect(++intercepted).toBe(1);
      originalReview = submittedDraftReview(payload);
      await expect(submit).toBeDisabled();
      const actualResponse = await route.fetch();
      expect(actualResponse.status()).toBe(200);
      committed = verify('uncertain');
      expect(committed.updates).toBe(3);
      await actualResponse.dispose();
      await route.abort('connectionreset');
    });
    const failed = page.waitForEvent('requestfailed', { predicate: request => livewireUrl.test(request.url()) });
    await submit.focus();
    await submit.press('Enter');
    await failed;
    await page.unrouteAll({ behavior: 'wait' });
    await page.unroute(livewireUrl);
    await expect(submit).toBeEnabled();
    await expect(source()).toHaveValue(fixture.sources.uncertain);
    await expect(dialog.getByRole('heading', { name: 'Edit license version', exact: true })).toBeVisible();
    expect(verify('uncertain')).toEqual(committed);
    await syncSuccessNotification(page, 'Reopen draft to continue', async () => {
      const [retry] = await Promise.all([actionResponse(page, 'callMountedAction'), submit.click()]);
      expect(retry.status()).toBe(200);
      expect(await retry.finished()).toBeNull();
      expect(submittedDraftReview(retry.request().postDataJSON())).toEqual(originalReview);
    }, async () => {
      await expect(dialog.getByText('This license draft changed or its edit review is no longer available. Close and reopen the editor, then review your changes.', { exact: true })).toBeVisible();
      await expect(source()).toHaveValue(fixture.sources.uncertain);
      await expectModalFits(page, dialog);
      await page.screenshot({ path: testInfo.outputPath('license-draft-lost-response-recovery.png'), fullPage: false });
    });
    expect(verify('uncertain')).toEqual(committed);
    receipts.push(committed!);
    await closeDialog(page, dialog, 'Cancel');
    dialog = await editDraft(page, fixture.name);
    await expect(source()).toHaveValue(fixture.sources.uncertain);
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('license-draft-saved-reopen.png'), fullPage: false });
    await closeDialog(page, dialog, 'Cancel');
    await expect(row(page, fixture.name).getByRole('button', { name: 'Edit', exact: true })).toBeFocused();
    expect(verify('uncertain')).toEqual(committed);
    await testInfo.attach('license-draft-retained-evidence', { body: JSON.stringify(receipts, null, 2), contentType: 'application/json' });
  } finally {
    await other.close();
  }
  expect(failures).toEqual([]);
});
