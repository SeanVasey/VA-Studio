import { execFileSync } from 'node:child_process';
import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse, closeDialog, expectModalFits, login, row } from './publication-fixture';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Fixture = {
  name: string; versionIds: [number, number]; versionNumbers: [number, number]; unselectedId: number;
  sources: { prepared: [string, string]; winner: string; recovered: string; uncertain: string };
};
type Phase = 'prepared' | 'winner' | 'recovered' | 'uncertain';
type Receipt = {
  verified: true; phase: Phase; versionIds: [number, number]; sources: [string, string];
  updates: number; auditIds: number[]; batchHashes: string[]; originalsUnchanged: true; guardsUnchanged: true;
};
type Payload = { components?: { snapshot: string; calls?: { method: string }[] }[] };
const inputHeading = 'Replace source for selected license drafts';
const reviewHeading = 'Review license draft source replacement';

function evidence(mode: 'prepare' | 'verify', project: string, phase?: Phase) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-bulk-license-draft-source.php', mode, project, ...(phase ? [phase] : [])], {
    cwd: process.cwd(), env: process.env, stdio: 'pipe', timeout: 30_000,
  }).toString()) as Fixture | Receipt;
}

const draftRow = (page: Page, fixture: Fixture, id: number) => row(page, fixture.name).filter({
  has: page.locator('input[type="checkbox"][value="' + id + '"]'),
});

async function search(page: Page, name: string) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === name) ?? false;
  });
  await page.getByRole('main').getByRole('searchbox', { name: 'Search', exact: true }).fill(name);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
}

async function select(page: Page, fixture: Fixture) {
  for (const id of fixture.versionIds) {
    const checkbox = draftRow(page, fixture, id).getByRole('checkbox');
    await checkbox.check();
    await expect(checkbox).toBeChecked();
  }
  await expect(draftRow(page, fixture, fixture.unselectedId).getByRole('checkbox')).not.toBeChecked();
}

async function openInput(page: Page) {
  const launch = page.getByRole('button', { name: 'Replace draft source', exact: true });
  await page.bringToFront();
  await launch.focus();
  await expect(launch).toBeFocused();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'replaceDraftSource'), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: inputHeading, exact: true })).toBeVisible();
  await expectModalFits(page, dialog);
  return dialog;
}

async function review(page: Page, fixture: Fixture, before: [string, string], after: string) {
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Replacement source', { exact: false }).fill(after);
  const [reviewed] = await Promise.all([actionResponse(page, 'callMountedAction'),
    dialog.getByRole('button', { name: 'Review source replacement', exact: true }).click()]);
  expect(reviewed.status()).toBe(200);
  expect(await reviewed.finished()).toBeNull();
  await expect(dialog.getByRole('heading', { name: reviewHeading, exact: true })).toBeVisible();
  const changed = before.filter(source => source !== after).length;
  await expect(dialog.getByText(changed + ' drafts will change; ' + (2 - changed) + ' already match this source.', { exact: true })).toBeVisible();
  await expect(dialog.getByLabel('Replacement source to keep', { exact: false })).toHaveValue(after);
  await expect(dialog.getByLabel('Replacement source to keep', { exact: false })).toHaveAttribute('readonly', /.*/);
  for (const [index, id] of fixture.versionIds.entries()) {
    const group = dialog.getByRole('group', { name: fixture.name + ' version ' + fixture.versionNumbers[index], exact: true });
    await expect(group).toBeVisible();
    await expect(group).toContainText('Draft ID: ' + id + '.');
    await expect(group.locator('pre[aria-label="Current source for draft ' + id + '"]')).toHaveText(before[index]);
    await expect(group.locator('pre[aria-label="Proposed source for draft ' + id + '"]')).toHaveText(after);
    await group.locator('summary').click();
    await expect(group.locator('pre[aria-label="Unchanged structured terms for draft ' + id + '"]')).toContainText('Nonbinding native verification only');
    await expect(group).toContainText('Availability starts (UTC): On publication.');
    await expect(group).toContainText('Availability ends (UTC): No scheduled end.');
  }
  await expect(dialog.getByRole('button', { name: 'Save reviewed source', exact: true })).toBeEnabled();
  await expectModalFits(page, dialog);
  return dialog;
}

async function save(page: Page, dialog: Locator, title: string) {
  const submit = dialog.getByRole('button', { name: 'Save reviewed source', exact: true });
  await submit.focus();
  await syncSuccessNotification(page, title, () => submit.press('Enter'));
  await expect(dialog.getByRole('heading', { name: reviewHeading, exact: true })).toBeHidden();
}

function submittedReview(payload: Payload) {
  const component = payload.components?.find(item => item.calls?.some(call => call.method === 'callMountedAction'));
  expect(component).toBeDefined();
  const snapshot = JSON.parse(component!.snapshot) as { data: Record<string, unknown> };
  expect(snapshot.data.bulkSourceReview).not.toBeNull();
  expect(snapshot.data.bulkSourceContext).not.toBeNull();
  return { review: snapshot.data.bulkSourceReview, context: snapshot.data.bulkSourceContext };
}

test('operator reviews exact bulk draft text, rejects a competing edit and safely retries a lost response', async ({ page, context }, testInfo) => {
  test.setTimeout(120_000);
  const fixture = evidence('prepare', testInfo.project.name) as Fixture;
  const receipts: Receipt[] = [];
  const verify = (phase: Phase) => {
    const receipt = evidence('verify', testInfo.project.name, phase) as Receipt;
    expect(receipt).toMatchObject({ verified: true, phase, versionIds: fixture.versionIds,
      originalsUnchanged: true, guardsUnchanged: true });
    expect(receipt.updates).toBe(({ prepared: 0, winner: 1, recovered: 3, uncertain: 5 })[phase]);
    return receipt;
  };
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await login(page);
  await page.goto('/admin/license-versions');
  await search(page, fixture.name);
  await select(page, fixture);
  receipts.push(verify('prepared'));
  await openInput(page);
  let dialog = await review(page, fixture, fixture.sources.prepared, fixture.sources.recovered);
  await page.screenshot({ path: testInfo.outputPath('bulk-license-source-review.png'), fullPage: false });

  // Returning to authoring retains entered text but requires another explicit current comparison.
  const [back] = await Promise.all([actionResponse(page, 'mountAction', 'backToBulkSource'),
    dialog.getByRole('button', { name: 'Back to source', exact: true }).click()]);
  expect(back.status()).toBe(200);
  expect(await back.finished()).toBeNull();
  await expect(dialog.getByRole('heading', { name: inputHeading, exact: true })).toBeVisible();
  await expect(dialog.getByLabel('Replacement source', { exact: false })).toHaveValue(fixture.sources.recovered);
  expect(verify('prepared')).toEqual(receipts[0]);
  dialog = await review(page, fixture, fixture.sources.prepared, fixture.sources.recovered);

  // A second real editor commits after the bulk preview; the entire old batch must be refused.
  const other = await context.newPage();
  other.on('pageerror', error => failures.push(error.message));
  try {
    await other.goto('/admin/license-versions');
    await search(other, fixture.name);
    const launch = draftRow(other, fixture, fixture.versionIds[0]).getByRole('button', { name: 'Edit', exact: true });
    const [opened] = await Promise.all([actionResponse(other, 'mountAction', 'edit'), launch.click()]);
    expect(opened.status()).toBe(200);
    expect(await opened.finished()).toBeNull();
    const editor = other.getByRole('dialog');
    await expect(editor.getByRole('heading', { name: 'Edit license version', exact: true })).toBeVisible();
    await expect(editor.getByLabel('Authored source', { exact: false })).toHaveValue(fixture.sources.prepared[0]);
    await editor.getByLabel('Authored source', { exact: false }).fill(fixture.sources.winner);
    await syncSuccessNotification(other, 'Saved', () => editor.getByRole('button', { name: 'Save changes', exact: true }).click());
    receipts.push(verify('winner'));
    await page.bringToFront();
    await syncSuccessNotification(page, 'Review current drafts to continue', async () => {
      const [blocked] = await Promise.all([actionResponse(page, 'callMountedAction'),
        dialog.getByRole('button', { name: 'Save reviewed source', exact: true }).click()]);
      expect(blocked.status()).toBe(200);
      expect(await blocked.finished()).toBeNull();
    }, async () => {
      await expect(dialog.getByLabel('Replacement source to keep', { exact: false })).toHaveValue(fixture.sources.recovered);
      await expect(dialog.getByText(/This comparison is no longer available/)).toBeVisible();
      await expect(dialog.getByRole('button', { name: 'Save reviewed source', exact: true })).toBeDisabled();
      await expectModalFits(page, dialog);
      await page.screenshot({ path: testInfo.outputPath('bulk-license-source-stale-review.png'), fullPage: false });
    });
    expect(verify('winner')).toEqual(receipts.at(-1));
    await closeDialog(page, dialog, 'Cancel');
  } finally {
    await other.close();
  }

  await openInput(page);
  await expect(page.getByRole('dialog').getByLabel('Replacement source', { exact: false })).toHaveValue(fixture.sources.recovered);
  dialog = await review(page, fixture, [fixture.sources.winner, fixture.sources.prepared[1]], fixture.sources.recovered);
  await save(page, dialog, 'Source saved for 2 drafts. 0 drafts already matched.');
  receipts.push(verify('recovered'));

  // A fresh exact no-op preview saves no rows, timestamps or audits.
  await select(page, fixture);
  await openInput(page);
  dialog = await review(page, fixture, [fixture.sources.recovered, fixture.sources.recovered], fixture.sources.recovered);
  await save(page, dialog, 'No draft source changed. 2 reviewed drafts already matched.');
  expect(verify('recovered')).toEqual(receipts.at(-1));

  await select(page, fixture);
  await openInput(page);
  dialog = await review(page, fixture, [fixture.sources.recovered, fixture.sources.recovered], fixture.sources.uncertain);
  const submit = dialog.getByRole('button', { name: 'Save reviewed source', exact: true });
  const livewireUrl = /\/livewire(?:-[A-Za-z0-9]+)?\/update$/;
  let intercepted = 0;
  let originalReview: unknown;
  let committed: Receipt | undefined;
  // Preserve the signed request, prove both durable changes, then lose only the acknowledgement.
  await page.route(livewireUrl, async route => {
    const payload = route.request().postDataJSON() as Payload;
    if (!payload.components?.some(component => component.calls?.some(call => call.method === 'callMountedAction'))) {
      await route.continue();
      return;
    }
    expect(++intercepted).toBe(1);
    originalReview = submittedReview(payload);
    await expect(submit).toBeDisabled();
    const response = await route.fetch();
    expect(response.status()).toBe(200);
    committed = verify('uncertain');
    await response.dispose();
    await route.abort('connectionreset');
  });
  const failed = page.waitForEvent('requestfailed', { predicate: request => livewireUrl.test(request.url()) });
  await submit.focus();
  await submit.press('Enter');
  await failed;
  await page.unrouteAll({ behavior: 'wait' });
  await page.unroute(livewireUrl);
  await expect(submit).toBeEnabled();
  await expect(dialog.getByLabel('Replacement source to keep', { exact: false })).toHaveValue(fixture.sources.uncertain);
  expect(verify('uncertain')).toEqual(committed);
  await syncSuccessNotification(page, 'Review current drafts to continue', async () => {
    const [retry] = await Promise.all([actionResponse(page, 'callMountedAction'), submit.click()]);
    expect(retry.status()).toBe(200);
    expect(await retry.finished()).toBeNull();
    expect(submittedReview(retry.request().postDataJSON())).toEqual(originalReview);
  }, async () => {
    await expect(dialog.getByText(/This comparison is no longer available/)).toBeVisible();
    await expect(dialog.getByLabel('Replacement source to keep', { exact: false })).toHaveValue(fixture.sources.uncertain);
    await expect(submit).toBeDisabled();
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('bulk-license-source-lost-response.png'), fullPage: false });
  });
  expect(verify('uncertain')).toEqual(committed);
  receipts.push(committed!);
  await closeDialog(page, dialog, 'Cancel');
  await openInput(page);
  dialog = await review(page, fixture, [fixture.sources.uncertain, fixture.sources.uncertain], fixture.sources.uncertain);
  await closeDialog(page, dialog, 'Cancel');
  expect(verify('uncertain')).toEqual(committed);
  await testInfo.attach('bulk-license-source-retained-evidence', { body: JSON.stringify(receipts, null, 2), contentType: 'application/json' });
  expect(failures).toEqual([]);
});

