import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { deliveryRoles } from '../../resources/js/lib/test-delivery';

interface Fixture { orderId: string; contract: { filename: string; sha256: string; sizeBytes: number } }
const missing = '740000ab-0000-4000-8000-999999999999';

/** Real read-only account/status/delivery HTTP; original transfers are covered by customer-account.spec.ts. */
test('customer finds an original purchase by reference with keyboard controls and clears private lookup state', async ({ page }, testInfo) => {
  const fixture: Fixture = JSON.parse(readFileSync(join(process.env.VASEY_BROWSER_DIRECTORY!, 'customer-fixtures.json'), 'utf8')).projects[testInfo.project.name];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/account/sign-in');
  await page.getByLabel('Email address', { exact: true }).fill('browser-customer@example.test');
  await page.getByLabel('Password', { exact: true }).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/account$/);
  const requests: Array<{ path: string; method: string }> = [];
  page.on('request', request => { const path = new URL(request.url()).pathname; if (path.startsWith('/orders/')) requests.push({ path, method: request.method() }); });
  const lookup = page.getByRole('region', { name: 'Find an account order', exact: true });
  const reference = lookup.getByRole('textbox', { name: 'Order reference', exact: true });
  await reference.fill(missing);
  const [denied] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith(`/orders/${missing}/status`) && response.request().method() === 'GET'),
    reference.press('Enter'),
  ]);
  expect(denied.status()).toBe(404); expect(denied.headers()['cache-control']).toContain('no-store');
  await expect(lookup.getByRole('alert')).toBeFocused();
  await expect(lookup.getByRole('alert')).toHaveText('This order is not available to your account. Check the reference or browse your account orders.');
  await reference.fill(` ${fixture.orderId.toUpperCase()} `);
  const [found, available] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith(`/orders/${fixture.orderId}/status`) && response.request().method() === 'GET'),
    page.waitForResponse(response => response.url().endsWith(`/orders/${fixture.orderId}/delivery`) && response.request().method() === 'GET'),
    reference.press('Enter'),
  ]);
  expect(found.status()).toBe(200); expect(found.headers()['cache-control']).toContain('no-store');
  expect(await found.json()).toMatchObject({ order: { id: fixture.orderId, paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'issued' } });
  await expect(lookup.getByRole('heading', { name: 'Order found', exact: true })).toBeFocused();
  await expect(lookup).toContainText(`Order ${fixture.orderId}`);
  // Reference lookup never requests the newest page or walks older pages to discover this order.
  expect(requests.some(request => request.path === '/orders/history')).toBe(false);
  const delivery = lookup.getByRole('region', { name: 'Test order downloads', exact: true });
  const button = delivery.getByRole('button', { name: `Download ${deliveryRoles.contract.label}`, exact: true });
  await expect(button).toBeVisible();
  await button.focus();
  await expect(button).toBeFocused();
  expect(available.status()).toBe(200); expect(available.headers()['cache-control']).toContain('no-store');
  expect(await available.json()).toMatchObject({ delivery: { orderId: fixture.orderId, testOnly: true, status: 'available',
    items: expect.arrayContaining([expect.objectContaining({ kind: 'contract', filename: fixture.contract.filename, sizeBytes: fixture.contract.sizeBytes })]),
  } });
  // Keep this lookup check independent of the transfer case's exact attempt/budget census.
  expect(requests.every(request => request.method === 'GET')).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(await reference.evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  expect(await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).not.toContain(fixture.orderId);
  await expect(page).toHaveURL(/\/account$/);
  await page.screenshot({ path: testInfo.outputPath('customer-order-reference-original.png'), fullPage: true });
  await lookup.getByRole('button', { name: 'Clear order lookup', exact: true }).click();
  await expect(reference).toHaveValue(''); await expect(reference).toBeFocused();
  await expect(lookup.getByRole('heading', { name: 'Order found', exact: true })).toHaveCount(0);
  await expect(delivery).toHaveCount(0);
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page).toHaveURL(/\/account\/sign-in$/);
  await expect(page.getByRole('textbox', { name: 'Order reference', exact: true })).toHaveCount(0);
  expect(errors).toEqual([]);
});
