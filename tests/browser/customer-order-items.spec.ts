import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

interface Capture { requestUrl: string; responseUrl: string; status: number; cacheControl: string | null; redirected: boolean; body: string }

/** Preserve the actual native GET body before application consumption can discard its CDP body.
 * This repeats the accepted lookup observation boundary: fetch once, clone real bytes, return the same Response.
 */
async function captureItems(page: Page, path: string) {
  let pending: ((response: Capture) => void) | undefined;
  await page.exposeBinding('__vaseyOrderItemsCaptured', (_source, response: Capture) => pending?.(response));
  await page.addInitScript(path => {
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
      const request = new URL(input instanceof Request ? input.url : String(input), location.href);
      const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
      const response = await nativeFetch(input, init);
      if (method === 'GET' && request.origin === location.origin && request.pathname === path && !request.search) {
        const body = await response.clone().text();
        await (window as unknown as { __vaseyOrderItemsCaptured: (value: Capture) => Promise<void> }).__vaseyOrderItemsCaptured({
          requestUrl: request.href, responseUrl: response.url, status: response.status,
          cacheControl: response.headers.get('cache-control'), redirected: response.redirected, body,
        });
      }
      return response;
    };
  }, path);
  return async (action: () => Promise<void>) => {
    if (pending) throw new Error('An item read is already pending.');
    const url = new URL(path, page.url()).href;
    const captured = new Promise<Capture>(resolve => { pending = resolve; });
    const observed = page.waitForResponse(response => response.url() === url && response.request().method() === 'GET');
    try {
      const [response, copy] = await Promise.all([observed, captured, action()]);
      expect(copy.requestUrl).toBe(url); expect(copy.responseUrl).toBe(response.url());
      expect(copy.status).toBe(response.status()); expect(copy.cacheControl).toBe(response.headers()['cache-control']); expect(copy.redirected).toBe(false);
      expect(response.status()).toBe(200); expect(response.headers()['cache-control']).toContain('no-store');
      return JSON.parse(copy.body);
    } finally { pending = undefined; }
  };
}

test('customer reads original item details through history and reference without altering purchase or download attempts', async ({ page }, testInfo) => {
  const orderId: string = JSON.parse(readFileSync(join(process.env.VASEY_BROWSER_DIRECTORY!, 'customer-fixtures.json'), 'utf8')).projects[testInfo.project.name].orderId;
  const capture = await captureItems(page, `/orders/${orderId}/items`);
  const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
  await page.goto('/account/sign-in');
  await page.getByLabel('Email address', { exact: true }).fill('browser-customer@example.test');
  await page.getByLabel('Password', { exact: true }).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await expect(page).toHaveURL(/\/account$/);
  const originalDocument = await page.evaluateHandle(() => document);
  const requests: Array<{ path: string; method: string }> = [], navigations: string[] = [];
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) navigations.push(request.url());
    const path = new URL(request.url()).pathname; if (path.startsWith('/orders/')) requests.push({ path, method: request.method() });
  });
  const expected = { items: { orderItemsSchema: 1, orderId, testOnly: true, currency: 'USD', subtotalMinor: 4999,
    discountMinor: 0, taxBasisMinor: 4999, taxMinor: 0, totalMinor: 4999,
    lines: [{ position: 0, title: 'Synthetic quote recording', licenseName: 'NONBINDING TEST FIXTURE', licenseVersion: 1,
      quantity: 1, baseMinor: 4999, discountMinor: 0, taxBasisMinor: 4999, taxMinor: 0, totalMinor: 4999 }] } };
  const history = page.getByRole('region', { name: 'Your account test orders', exact: true });
  await history.getByRole('button', { name: 'Browse account orders', exact: true }).click();
  const openStatus = history.getByRole('button', { name: `View test order status ${orderId}`, exact: true });
  await openStatus.click();
  const historicalItems = history.getByRole('region', { name: 'Original test-order items', exact: true });
  const view = historicalItems.getByRole('button', { name: 'View original test-order items', exact: true });
  await expect(view).toBeVisible(); expect(requests.filter(request => request.path.endsWith('/items'))).toEqual([]);
  const original = await capture(async () => { await view.focus(); await view.press('Enter'); });
  expect(original).toEqual(expected);
  await expect(historicalItems.getByRole('heading', { name: 'Original test-order items', exact: true })).toBeFocused();
  await expect(historicalItems.getByRole('heading', { name: expected.items.lines[0].title, exact: true })).toBeVisible();
  await expect(historicalItems.getByText('NONBINDING TEST FIXTURE · Version 1', { exact: true })).toBeVisible();
  await expect(historicalItems.getByText('$49.99 USD', { exact: true })).toBeVisible();
  await expect(historicalItems.getByText(/do not confirm payment, grant rights/)).toBeVisible();
  await historicalItems.getByRole('button', { name: 'Hide original test-order items', exact: true }).click();
  await expect(view).toBeFocused(); await expect(historicalItems.getByRole('heading')).toHaveCount(0);
  await openStatus.click();
  const historyReads = requests.filter(request => request.path === '/orders/history').length;
  const lookup = page.getByRole('region', { name: 'Find an account order', exact: true });
  await lookup.getByRole('textbox', { name: 'Order reference', exact: true }).fill(orderId);
  await lookup.getByRole('textbox', { name: 'Order reference', exact: true }).press('Enter');
  await expect(lookup.getByRole('heading', { name: 'Order found', exact: true })).toBeFocused();
  const referenceItems = lookup.getByRole('region', { name: 'Original test-order items', exact: true });
  expect(await capture(() => referenceItems.getByRole('button', { name: 'View original test-order items', exact: true }).click())).toEqual(original);
  await expect(referenceItems.getByRole('heading', { name: expected.items.lines[0].title, exact: true })).toBeVisible();
  expect(requests.filter(request => request.path === '/orders/history')).toHaveLength(historyReads);
  expect(requests.filter(request => request.path.endsWith('/items'))).toHaveLength(2);
  expect(requests.every(request => request.method === 'GET')).toBe(true);
  // Inertia saves scroll with same-document history updates; prove no document replacement instead.
  expect(navigations).toEqual([]); expect(await originalDocument.evaluate(original => original === document)).toBe(true);
  await originalDocument.dispose(); await expect(page).toHaveURL(/\/account$/);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(await referenceItems.getByRole('button', { name: 'Hide original test-order items', exact: true }).evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  const stored = await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }));
  for (const value of [orderId, expected.items.lines[0].title, expected.items.lines[0].licenseName]) expect(stored).not.toContain(value);
  await page.screenshot({ path: testInfo.outputPath('customer-original-order-items.png'), fullPage: true });
  await lookup.getByRole('button', { name: 'Clear order lookup', exact: true }).click();
  await expect(referenceItems).toHaveCount(0);
  await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await expect(page).toHaveURL(/\/account\/sign-in$/);
  await expect(page.getByRole('region', { name: 'Original test-order items', exact: true })).toHaveCount(0);
  expect(errors).toEqual([]);
});
