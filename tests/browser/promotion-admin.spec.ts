import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

const password = process.env.VASEY_BROWSER_PASSWORD!;
const row = (page: Page, code: string) => page.getByRole('row').filter({ has: page.getByText(code, { exact: true }) });

async function login(page: Page, email = 'browser-operator@example.test') {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill(email);
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

async function changeAvailability(page: Page, code: string, action: 'Enable promotion' | 'Disable promotion') {
  await row(page, code).getByRole('button', { name: action, exact: true }).click();
  const dialog = page.getByRole('alertdialog', { name: action, exact: true });
  await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(row(page, code).getByText(action === 'Enable promotion' ? 'Active' : 'Disabled', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading', { name: action, exact: true })).not.toBeVisible();
}

test('promotion administration rejects guests and customer accounts', async ({ page }) => {
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await page.goto('/admin/test-promotions');
  await expect(page).toHaveURL(/\/admin\/login$/);
  await expect(page.getByRole('button', { name: 'New test promotion', exact: true })).toHaveCount(0);
  await login(page, 'browser-customer@example.test');
  await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
  await page.goto('/admin/test-promotions');
  await expect(page).toHaveURL(/\/admin\/login$/);
  expect(failures).toEqual([]);
});

test('real monetary inputs, immutable copying and enable/disable changes persist', async ({ page, context }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  const watch = (target: Page) => target.on('pageerror', error => failures.push(error.message));
  watch(page);
  const suffix = testInfo.project.name.toUpperCase().replaceAll('-', '_');
  const firstKey = `browser-fixed-${testInfo.project.name}`;
  const firstCode = `BROWSER_FIXED_${suffix}`;
  const secondKey = `browser-copy-${testInfo.project.name}`;
  const secondCode = `BROWSER_COPY_${suffix}`;
  await login(page);
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/test-promotions');
  await page.getByRole('button', { name: 'New test promotion', exact: true }).click();
  let dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Create a disabled test promotion', exact: true })).toBeVisible();
  await dialog.getByLabel('Campaign key', { exact: false }).fill(firstKey);
  await dialog.getByLabel('Promotion code', { exact: false }).fill(firstCode);
  await dialog.getByLabel('Starts at (UTC)', { exact: false }).fill('2020-01-01T00:00:00Z');
  await dialog.getByLabel('Ends at (UTC)', { exact: false }).fill('2099-01-01T00:00:00Z');
  await expect(dialog.getByLabel('Discount type', { exact: false })).toHaveValue('fixed');
  await dialog.getByLabel('Minimum eligible subtotal (cents)', { exact: false }).fill('1000');
  await dialog.getByLabel('Lifetime use limit', { exact: false }).fill('7');

  // Browser-entered amounts must reach server validation as decimal digit strings.
  await dialog.getByLabel('Fixed discount (cents)', { exact: false }).fill('12.50');
  await dialog.getByRole('button', { name: 'Create disabled promotion', exact: true }).click();
  await expect(dialog.getByText(/format is invalid|digits only|whole numbers|integer/i)).toBeVisible();
  await expect(dialog.getByLabel('Fixed discount (cents)', { exact: false })).toHaveValue('12.50');
  await expect(row(page, firstCode)).toHaveCount(0);
  await dialog.getByLabel('Fixed discount (cents)', { exact: false }).fill('250');
  await dialog.getByLabel('Minimum eligible subtotal (cents)', { exact: false }).fill('1e3');
  await dialog.getByRole('button', { name: 'Create disabled promotion', exact: true }).click();
  await expect(dialog.getByText(/format is invalid|digits only|whole numbers|integer/i)).toBeVisible();
  await expect(dialog.getByLabel('Minimum eligible subtotal (cents)', { exact: false })).toHaveValue('1e3');
  await expect(row(page, firstCode)).toHaveCount(0);
  await page.screenshot({ path: testInfo.outputPath('promotion-numeric-validation.png'), fullPage: false });
  await dialog.getByLabel('Minimum eligible subtotal (cents)', { exact: false }).fill('1000');
  await dialog.getByRole('button', { name: 'Create disabled promotion', exact: true }).click();
  await expect(dialog.getByRole('heading', { name: 'Create a disabled test promotion', exact: true })).not.toBeVisible();
  await expect(row(page, firstCode).getByText('Disabled', { exact: true })).toBeVisible();

  // A fresh document verifies the saved campaign rather than retained component state.
  const editor = await context.newPage();
  watch(editor);
  await editor.goto('/admin/test-promotions');
  await expect(row(editor, firstCode).getByText(firstKey, { exact: true })).toBeVisible();
  await expect(row(editor, firstCode).getByText('Disabled', { exact: true })).toBeVisible();
  await row(editor, firstCode).getByRole('button', { name: 'Review and usage', exact: true }).click();
  const review = editor.getByRole('dialog');
  await expect(review.getByText('250 USD cents', { exact: true })).toBeVisible();
  await expect(review.getByText('Remaining capacity: 7', { exact: true })).toBeVisible();
  const closeReview = editor.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
  });
  await review.locator('.fi-modal-footer').getByRole('button', { name: 'Close', exact: true }).click();
  const closedReview = await closeReview;
  expect(closedReview.status()).toBe(200);
  await closedReview.finished();
  await expect(review.getByRole('heading', { name: 'Test promotion terms and usage', exact: true })).not.toBeVisible();
  await changeAvailability(editor, firstCode, 'Enable promotion');
  await row(editor, firstCode).getByRole('button', { name: 'Copy as new promotion', exact: true }).click();
  dialog = editor.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Create a disabled test promotion', exact: true })).toBeVisible();
  await expect(dialog.getByLabel('Campaign key', { exact: false })).toHaveValue('');
  await expect(dialog.getByLabel('Promotion code', { exact: false })).toHaveValue('');
  await expect(dialog.getByLabel('Fixed discount (cents)', { exact: false })).toHaveValue('250');
  await expect(dialog.getByLabel('Minimum eligible subtotal (cents)', { exact: false })).toHaveValue('1000');
  await expect(dialog.getByLabel('Lifetime use limit', { exact: false })).toHaveValue('7');
  await expect(dialog.getByLabel('Starts at (UTC)', { exact: false })).toHaveValue('2020-01-01T00:00:00Z');
  await expect(dialog.getByLabel('Ends at (UTC)', { exact: false })).toHaveValue('2099-01-01T00:00:00Z');
  await dialog.getByLabel('Campaign key', { exact: false }).fill(secondKey);
  await dialog.getByLabel('Promotion code', { exact: false }).fill(secondCode);
  await dialog.getByLabel('Discount type', { exact: false }).selectOption('percentage');
  await dialog.getByLabel('Percentage (basis points)', { exact: false }).fill('1250');
  await dialog.getByLabel('Maximum discount (cents)', { exact: false }).fill('300');
  await dialog.getByLabel('Minimum eligible subtotal (cents)', { exact: false }).fill('2000');
  await dialog.getByLabel('Lifetime use limit', { exact: false }).fill('3');
  await dialog.getByRole('button', { name: 'Create disabled promotion', exact: true }).click();
  await expect(dialog.getByRole('heading', { name: 'Create a disabled test promotion', exact: true })).not.toBeVisible();
  await expect(row(editor, secondCode).getByText('Disabled', { exact: true })).toBeVisible();
  await expect(row(editor, firstCode).getByText('Active', { exact: true })).toBeVisible();
  await changeAvailability(editor, secondCode, 'Enable promotion');
  await changeAvailability(editor, firstCode, 'Disable promotion');

  const persisted = await context.newPage();
  watch(persisted);
  await persisted.goto('/admin/test-promotions');
  await expect(row(persisted, firstCode).getByText('Disabled', { exact: true })).toBeVisible();
  await expect(row(persisted, secondCode).getByText(secondKey, { exact: true })).toBeVisible();
  await expect(row(persisted, secondCode).getByText('Active', { exact: true })).toBeVisible();
  await persisted.screenshot({ path: testInfo.outputPath('promotion-lifecycle.png'), fullPage: false });
  // The success notification has its own Livewire request after the action renders.
  // Finish that request before reloading, which otherwise aborts it in WebKit.
  const notificationSync = persisted.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string; params?: unknown[] }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === '__dispatch' && call.params?.[0] === 'notificationsSent')) ?? false;
  });
  await changeAvailability(persisted, secondCode, 'Disable promotion');
  const notificationResponse = await notificationSync;
  expect(notificationResponse.status()).toBe(200);
  expect(await notificationResponse.finished()).toBeNull();
  await persisted.reload();
  await expect(row(persisted, firstCode).getByText('Disabled', { exact: true })).toBeVisible();
  await expect(row(persisted, secondCode).getByText('Disabled', { exact: true })).toBeVisible();
  expect(failures).toEqual([]);
  await editor.close();
  await persisted.close();
});
