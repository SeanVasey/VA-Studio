import { execFileSync } from 'node:child_process';
import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { expectReleaseTableFits, openReleaseMenu, releaseMenuAction, releaseRow as row } from './site-release-row';
import { syncSuccessNotification } from './notification-sync';

test.beforeEach(() => resetBrowserLoginRateLimit());

const password = process.env.VASEY_BROWSER_PASSWORD!;

/** A whole UTC minute at least `minutes` ahead, as the native input value and as the admin displays it. */
function futureMinute(minutes: number) {
  const iso = new Date(Math.ceil((Date.now() + minutes * 60_000) / 60_000) * 60_000).toISOString();
  return { input: iso.slice(0, 16), display: `${iso.slice(0, 10)} ${iso.slice(11, 16)} UTC` };
}

/** Runs the production scheduler command once, in a guarded process whose clock is set to the pending schedule. */
function runDueSchedule(): string {
  return execFileSync('php', ['tests/browser/run-due-site-schedule.php'], {
    cwd: process.cwd(), env: process.env, stdio: 'pipe', timeout: 30_000,
  }).toString().trim();
}

async function schedule(page: Page, label: string, at: { input: string; display: string }) {
  await (await releaseMenuAction(page, label, 'Schedule publication')).click();
  const dialog = page.getByRole('dialog', { name: 'Schedule publication', exact: true });
  await expect(dialog.getByText(/Current time: \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\./)).toBeVisible();
  await dialog.getByLabel('Publish at (UTC)', { exact: false }).fill(at.input);
  await syncSuccessNotification(page, 'Publication scheduled', () => dialog.getByRole('button', { name: 'Schedule publication', exact: true }).click(), async () => {
    await expect(page.getByText('Publication scheduled', { exact: true })).toBeVisible();
    await expect(page.getByText(`publishes at ${at.display}.`, { exact: false })).toBeVisible();
  });
}

async function history(page: Page, label: string, outcome: string) {
  await page.getByRole('button', { name: 'Schedule history', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Scheduled publication history', exact: true });
  await expect(dialog.getByRole('row').filter({ hasText: label }).first()).toContainText(outcome);
  // Filament's stylesheet has no utility classes for custom views, so the columns need their own spacing.
  expect(await dialog.getByRole('cell').first().evaluate(cell => parseFloat(getComputedStyle(cell).paddingLeft))).toBeGreaterThan(0);
  // The table can scroll sideways, so keyboard users need to be able to focus its container.
  await expect(dialog.getByRole('region', { name: 'Schedule history table', exact: true })).toHaveAttribute('tabindex', '0');
  // Closing sends its own Livewire request. Finish it before a later reload, which otherwise aborts it in WebKit.
  const closeHistory = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
  });
  await dialog.locator('.fi-modal-footer').getByRole('button', { name: 'Close', exact: true }).click();
  const closed = await closeHistory;
  expect(closed.status()).toBe(200);
  expect(await closed.finished()).toBeNull();
  await expect(dialog).toBeHidden();
}

test('a scheduled release waits, the scheduler publishes it, and a pending schedule can be cancelled', async ({ page, playwright }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/site-releases');
  const label = `Synthetic scheduled ${testInfo.project.name}`;
  const heading = `SYNTHETIC SCHEDULED ${testInfo.project.name.toUpperCase()}`;
  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    await page.getByRole('button', { name: 'New content draft', exact: true }).click();
    const editor = page.getByRole('dialog');
    await editor.getByLabel('Release label', { exact: false }).fill(label);
    await editor.getByLabel('Heading, first line', { exact: false }).first().fill(heading);
    await syncSuccessNotification(page, 'Private draft saved', () => editor.getByRole('button', { name: 'Save private draft', exact: true }).click());
    await expect(row(page, label).getByText('Private draft', { exact: true })).toBeVisible();

    const publishAt = futureMinute(3);
    await schedule(page, label, publishAt);
    await expect(row(page, label).getByText(publishAt.display, { exact: true })).toBeVisible();
    // The schedule badge is the widest cell; the desktop admin must still fit without scrolling sideways.
    if (testInfo.project.name === 'chromium-desktop') await expectReleaseTableFits(page);
    // Opened, so a missing action is really absent rather than hidden inside a closed menu.
    await expect((await openReleaseMenu(page, label)).getByRole('button', { name: 'Schedule publication', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
    expect(await (await visitor.get('/')).text()).not.toContain(heading);
    await history(page, label, 'Waiting for its time');

    expect(runDueSchedule()).toMatch(/^PUBLISHED schedule=\d+ revision=\d+$/);
    expect(await (await visitor.get('/')).text()).toContain(heading);
    await page.reload();
    await expect(row(page, label).getByText('Active', { exact: true })).toBeVisible();
    await expect(page.getByText('Scheduled:', { exact: false })).toHaveCount(0);
    await history(page, label, 'Published by the scheduler');

    const cancelledAt = futureMinute(10);
    await schedule(page, 'Original site content', cancelledAt);
    await page.getByRole('button', { name: 'Cancel scheduled publication', exact: true }).click();
    const confirmation = page.getByRole('alertdialog', { name: 'Cancel scheduled publication', exact: true });
    await expect(confirmation).toContainText(`Cancel the scheduled publication of “Original site content” at ${cancelledAt.display}?`);
    await syncSuccessNotification(page, 'Scheduled publication cancelled', () => confirmation.getByRole('button', { name: 'Confirm', exact: true }).click(), async () => {
      await expect(page.getByText('Scheduled publication cancelled', { exact: true })).toBeVisible();
    });
    await expect(page.getByRole('button', { name: 'Cancel scheduled publication', exact: true })).toHaveCount(0);
    await history(page, 'Original site content', 'Cancelled by staff');
    expect(await (await visitor.get('/')).text()).toContain(heading);

    // Leave the public site on its original content, as the other content specs do.
    await (await releaseMenuAction(page, 'Original site content', 'Restore previous release')).click();
    await syncSuccessNotification(page, 'Previous release restored', () => page.getByRole('alertdialog', { name: 'Restore previous release', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click());
    await expect(row(page, 'Original site content').getByText('Active', { exact: true })).toBeVisible();
    expect(await (await visitor.get('/')).text()).not.toContain(heading);
    expect((await (await visitor.get('/api/catalog')).json()).tracks).toEqual([]);
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
