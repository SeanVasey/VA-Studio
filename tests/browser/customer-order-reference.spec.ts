import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { deliveryRoles } from '../../resources/js/lib/test-delivery';

interface Fixture { orderId: string; contract: { filename: string; sha256: string; sizeBytes: number } }
const missing = '740000ab-0000-4000-8000-999999999999';
interface CapturedResponse { requestUrl: string; responseUrl: string; status: number; cacheControl: string | null; redirected: boolean; body: string }

/** Like the account transfer proof, copy actual native bytes before the app consumes its Response.
 * Chromium can lose the CDP body even without document navigation. Only these two exact GETs
 * are observed: no interception, request replay, or replacement of the Response reaches the app.
 */
async function captureLookupResponses(page: Page, orderId: string) {
  const paths = [`/orders/${orderId}/status`, `/orders/${orderId}/delivery`];
  const pending = new Map<string, (response: CapturedResponse) => void>();
  await page.exposeBinding('__vaseyLookupResponseCaptured', (_source, response: CapturedResponse) => {
    pending.get(response.requestUrl)?.(response);
  });
  await page.addInitScript(paths => {
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
      const requestUrl = new URL(input instanceof Request ? input.url : String(input), window.location.href);
      const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
      const response = await nativeFetch(input, init);
      if (method === 'GET' && requestUrl.origin === window.location.origin && paths.includes(requestUrl.pathname) && !requestUrl.search) {
        const body = await response.clone().text();
        await (window as unknown as { __vaseyLookupResponseCaptured: (value: CapturedResponse) => Promise<void> }).__vaseyLookupResponseCaptured({
          requestUrl: requestUrl.href, responseUrl: response.url, status: response.status,
          cacheControl: response.headers.get('cache-control'), redirected: response.redirected, body,
        });
      }
      return response;
    };
  }, paths);
  return async (action: () => Promise<void>) => {
    const urls = paths.map(path => new URL(path, page.url()).href);
    const captures = urls.map(async url => {
      const captured = new Promise<CapturedResponse>(resolve => pending.set(url, resolve));
      const observed = page.waitForResponse(response => response.url() === url && response.request().method() === 'GET');
      const [response, copy] = await Promise.all([observed, captured]);
      expect(copy.responseUrl).toBe(response.url()); expect(copy.status).toBe(response.status());
      expect(copy.cacheControl).toBe(response.headers()['cache-control']); expect(copy.redirected).toBe(false);
      return { response, body: JSON.parse(copy.body) };
    });
    try { const [responses] = await Promise.all([Promise.all(captures), action()]); return responses; }
    finally { for (const url of urls) pending.delete(url); }
  };
}

/** Real read-only account/status/delivery HTTP; original transfers are covered by customer-account.spec.ts. */
test('customer finds an original purchase by reference with keyboard controls and clears private lookup state', async ({ page }, testInfo) => {
  const fixture: Fixture = JSON.parse(readFileSync(join(process.env.VASEY_BROWSER_DIRECTORY!, 'customer-fixtures.json'), 'utf8')).projects[testInfo.project.name];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  const capture = await captureLookupResponses(page, fixture.orderId);
  await page.goto('/account/sign-in');
  await page.getByLabel('Email address', { exact: true }).fill('browser-customer@example.test');
  await page.getByLabel('Password', { exact: true }).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/account$/);
  const documentNavigations: string[] = [];
  const requests: Array<{ path: string; method: string }> = [];
  page.on('request', request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documentNavigations.push(request.url());
    const path = new URL(request.url()).pathname;
    if (path.startsWith('/orders/')) requests.push({ path, method: request.method() });
  });
  const lookup = page.getByRole('region', { name: 'Find an account order', exact: true });
  await expect(lookup).toBeVisible();
  // Frame events also include same-document history updates. Retain the actual Document
  // and reject navigation requests so the lookup must keep this loaded account document.
  const accountDocument = await page.evaluateHandle(() => document);
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
  const [{ response: found, body: foundBody }, { response: available, body: availableBody }] = await capture(() => reference.press('Enter'));
  expect(found.status()).toBe(200); expect(found.headers()['cache-control']).toContain('no-store');
  expect(foundBody).toMatchObject({ order: { id: fixture.orderId, paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'issued' } });
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
  expect(availableBody).toMatchObject({ delivery: { orderId: fixture.orderId, testOnly: true, status: 'available',
    items: expect.arrayContaining([expect.objectContaining({ kind: 'contract', filename: fixture.contract.filename, sizeBytes: fixture.contract.sizeBytes })]),
  } });
  // Keep this lookup check independent of the transfer case's exact attempt/budget census.
  expect(requests.every(request => request.method === 'GET')).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(await reference.evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  expect(await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).not.toContain(fixture.orderId);
  await expect(page).toHaveURL(/\/account$/);
  expect(documentNavigations).toEqual([]);
  expect(await accountDocument.evaluate(original => original === document)).toBe(true);
  await accountDocument.dispose();
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
