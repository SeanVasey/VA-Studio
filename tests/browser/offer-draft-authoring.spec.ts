import { execFileSync } from 'node:child_process';
import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse, closeDialog, expectModalFits, login } from './publication-fixture';
import { syncSuccessNotification } from './notification-sync';

type Phase = 'prepared' | 'winner' | 'recovered' | 'uncertain';
type Fixture = { offerId: number; revisionId: number; trackId: number; title: string; prices: Record<Phase, number>; publishedPrice: number };
type Receipt = { verified: true; phase: Phase; offerId: number; revisionId: number; price: number; updates: number; originalsUnchanged: true; guardsUnchanged: true };

function evidence(mode: 'prepare' | 'verify', project: string, phase?: Phase) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-offer-draft.php', mode, project, ...(phase ? [phase] : [])], {
    cwd: process.cwd(), env: process.env, stdio: 'pipe', timeout: 30_000,
  }).toString()) as Fixture | Receipt;
}

const offerRow = (page: Page, id: number) => page.getByRole('row').and(page.locator(`[wire\\:key$=".table.records.${id}"]`));

async function editDraft(page: Page, fixture: Fixture) {
  await page.bringToFront();
  await page.goto('/admin/offers');
  const record = offerRow(page, fixture.offerId);
  await expect(record.getByText(fixture.title, { exact: true })).toBeVisible();
  const launch = record.getByRole('button', { name: 'Edit draft', exact: true });
  await launch.focus();
  await expect(launch).toBeFocused();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'edit'), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Edit offer draft', exact: true })).toBeVisible();
  await expectModalFits(page, dialog);
  return dialog;
}

function submittedReview(payload: { components?: { snapshot: string; calls?: { method: string }[] }[] }) {
  const component = payload.components?.find(item => item.calls?.some(call => call.method === 'callMountedAction'));
  expect(component).toBeDefined();
  const snapshot = JSON.parse(component!.snapshot) as { data: Record<string, unknown> };
  expect(snapshot.data.offerReview).not.toBeNull();
  return snapshot.data.offerReview;
}

test.beforeEach(() => resetBrowserLoginRateLimit());

test('operator retains stale offer input, preserves purchased revisions and recovers a lost draft save response', async ({ page, context }, testInfo) => {
  test.setTimeout(120_000);
  const fixture = evidence('prepare', testInfo.project.name) as Fixture;
  const verify = (phase: Phase) => {
    const receipt = evidence('verify', testInfo.project.name, phase) as Receipt;
    expect(receipt).toMatchObject({ verified: true, phase, offerId: fixture.offerId, revisionId: fixture.revisionId,
      price: fixture.prices[phase], originalsUnchanged: true, guardsUnchanged: true });
    return receipt;
  };
  expect(verify('prepared').updates).toBe(0);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await login(page);
  let dialog = await editDraft(page, fixture);
  const price = () => dialog.getByLabel('Draft price in cents', { exact: false });
  await expect(price()).toHaveValue(String(fixture.prices.prepared));
  await expect(dialog.getByText(/keep a copy of your entered changes, then close and reopen/)).toBeVisible();
  await price().focus();
  await expect(price()).toBeFocused();
  await page.keyboard.press('Tab');
  await expectModalFits(page, dialog);
  const other = await context.newPage();
  other.on('pageerror', error => failures.push(error.message));
  const receipts: Receipt[] = [];
  try {
    const otherDialog = await editDraft(other, fixture);
    await expect(otherDialog.getByLabel('Draft price in cents', { exact: false })).toHaveValue(String(fixture.prices.prepared));
    await otherDialog.getByLabel('Draft price in cents', { exact: false }).fill(String(fixture.prices.winner));
    await syncSuccessNotification(other, 'Saved', () => otherDialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(otherDialog.getByRole('heading')).toBeHidden();
    receipts.push(verify('winner'));
    expect(receipts.at(-1)!.updates).toBe(1);

    await page.bringToFront();
    const losingPrice = String(fixture.prices.prepared + 1000);
    await price().fill(losingPrice);
    await syncSuccessNotification(page, 'Reopen offer draft to continue', async () => {
      const [blocked] = await Promise.all([actionResponse(page, 'callMountedAction'),
        dialog.getByRole('button', { name: 'Save changes', exact: true }).click()]);
      expect(blocked.status()).toBe(200);
      expect(await blocked.finished()).toBeNull();
    }, async () => {
      const error = dialog.getByText('This offer draft changed or its edit review is no longer available. Close and reopen the editor, then review your changes.', { exact: true });
      await expect(error).toBeVisible();
      await expect(price()).toHaveValue(losingPrice);
      await error.scrollIntoViewIfNeeded();
      await expect(error).toBeInViewport();
      await expectModalFits(page, dialog);
      await page.screenshot({ path: testInfo.outputPath('offer-draft-stale-input.png'), fullPage: false });
    });
    expect(verify('winner')).toEqual(receipts.at(-1));
    await syncSuccessNotification(page, 'Reopen offer draft to continue', () =>
      dialog.getByRole('button', { name: 'Save changes', exact: true }).click(), async () => {
      await expect(dialog.getByText('Close and reopen the current offer draft before saving.', { exact: true })).toBeVisible();
      await expect(price()).toHaveValue(losingPrice);
    });
    expect(verify('winner')).toEqual(receipts.at(-1));
    await closeDialog(page, dialog, 'Cancel');
    await expect(offerRow(page, fixture.offerId).getByRole('button', { name: 'Edit draft', exact: true })).toBeFocused();

    dialog = await editDraft(page, fixture);
    await expect(price()).toHaveValue(String(fixture.prices.winner));
    await price().fill(String(fixture.prices.recovered));
    await syncSuccessNotification(page, 'Saved', () => dialog.getByRole('button', { name: 'Save changes', exact: true }).click());
    await expect(dialog.getByRole('heading')).toBeHidden();
    receipts.push(verify('recovered'));
    expect(receipts.at(-1)!.updates).toBe(2);

    dialog = await editDraft(page, fixture);
    await expect(price()).toHaveValue(String(fixture.prices.recovered));
    await price().fill(String(fixture.prices.uncertain));
    const submit = dialog.getByRole('button', { name: 'Save changes', exact: true });
    const livewireUrl = /\/livewire(?:-[A-Za-z0-9]+)?\/update$/;
    let intercepted = 0;
    let originalReview: unknown;
    let committed: Receipt | undefined;
    // Execute the original signed request, prove the durable save, and drop only its acknowledgement.
    await page.route(livewireUrl, async route => {
      const payload = route.request().postDataJSON() as { components?: { snapshot: string; calls?: { method: string }[] }[] };
      if (!payload.components?.some(component => component.calls?.some(call => call.method === 'callMountedAction'))) {
        await route.continue();
        return;
      }
      expect(++intercepted).toBe(1);
      originalReview = submittedReview(payload);
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
    // Finish the owned abort callback before restoring the native retry transport.
    await page.unrouteAll({ behavior: 'wait' });
    await page.unroute(livewireUrl);
    await expect(submit).toBeEnabled();
    await expect(price()).toHaveValue(String(fixture.prices.uncertain));
    await expect(dialog.getByRole('heading', { name: 'Edit offer draft', exact: true })).toBeVisible();
    expect(verify('uncertain')).toEqual(committed);
    await syncSuccessNotification(page, 'Reopen offer draft to continue', async () => {
      const [retry] = await Promise.all([actionResponse(page, 'callMountedAction'), submit.click()]);
      expect(retry.status()).toBe(200);
      expect(await retry.finished()).toBeNull();
      expect(submittedReview(retry.request().postDataJSON())).toEqual(originalReview);
    }, async () => {
      await expect(dialog.getByText('This offer draft changed or its edit review is no longer available. Close and reopen the editor, then review your changes.', { exact: true })).toBeVisible();
      await expect(price()).toHaveValue(String(fixture.prices.uncertain));
      await expectModalFits(page, dialog);
      await page.screenshot({ path: testInfo.outputPath('offer-draft-lost-response-recovery.png'), fullPage: false });
    });
    expect(verify('uncertain')).toEqual(committed);
    receipts.push(committed!);
    await closeDialog(page, dialog, 'Cancel');
    dialog = await editDraft(page, fixture);
    await expect(price()).toHaveValue(String(fixture.prices.uncertain));
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('offer-draft-saved-reopen.png'), fullPage: false });
    await closeDialog(page, dialog, 'Cancel');
    await expect(offerRow(page, fixture.offerId).getByRole('button', { name: 'Edit draft', exact: true })).toBeFocused();
    const published = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(fixture.publishedPrice / 100);
    await expect(offerRow(page, fixture.offerId).getByText(published, { exact: true })).toBeVisible();
    expect(verify('uncertain')).toEqual(committed);
    await testInfo.attach('offer-draft-retained-evidence', { body: JSON.stringify(receipts, null, 2), contentType: 'application/json' });
  } finally {
    await page.unroute(/\/livewire(?:-[A-Za-z0-9]+)?\/update$/);
    await other.close();
  }
  expect(failures).toEqual([]);
});
