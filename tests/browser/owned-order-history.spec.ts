import { test, expect } from '@playwright/test';
import { query, storefrontFixture } from './storefront-fixture';

/** Built React and native sessionStorage/keyboard behavior with synthetic HTTP responses.
 * PHP feature tests independently prove the real server's owner and frozen-evidence boundary.
 */
test('session order discovery survives cleared tab storage and status selection never starts payment or delivery', async ({ page }, testInfo) => {
  await storefrontFixture(page);
  const orderId = '730000ab-0000-4000-8000-000000000001';
  const summary = { id: orderId, createdAt: '2026-10-01T12:00:00.000000Z', testOnly: true, payable: false,
    currency: 'USD', totalMinor: 4999, status: 'paid_exception', paymentStatus: 'verified',
    finalizationStatus: 'paid_exception', contractStatus: 'blocked', fulfillmentStatus: 'blocked' };
  const requests: Array<{ path: string; method: string }> = [];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/orders/**', async route => {
    const request = route.request(), path = new URL(request.url()).pathname;
    requests.push({ path, method: request.method() });
    if (path === '/orders/history') return route.fulfill({ json: { history: {
      orderHistorySchema: 2, testOnly: true, orders: [summary], previews: [{ orderId: summary.id, itemCount: 1, firstItem: { title: 'Original track', licenseName: 'Original license', licenseVersion: 1 } }], limit: 20, nextCursor: null,
    } } });
    if (path === `/orders/${orderId}/checkout`) return route.fulfill({ status: 503, json: { code: 'CHECKOUT_UNAVAILABLE' } });
    throw new Error('History browsing must not create checkout or delivery effects');
  });
  await page.goto('/' + query);
  const cookies = await page.context().cookies();
  await page.evaluate(() => { sessionStorage.setItem('vaseyaudio-order-recovery-v1', JSON.stringify(['old-tab-locator'])); sessionStorage.clear(); });
  await page.reload();
  expect(await page.context().cookies()).toEqual(cookies);
  // The existing cart hook restores an empty-cart record after a reload.
  // History must neither change it nor persist recovered order locators.
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('vaseyaudio-cart-v1'))).toBe('[]');
  const storage = await page.evaluate(() => Object.fromEntries(
    Object.keys(sessionStorage).sort().map(key => [key, sessionStorage.getItem(key)]),
  ));
  expect(storage).toEqual({ 'vaseyaudio-cart-v1': '[]' });
  await page.getByRole('button', { name: 'Open cart, 0 items' }).click();
  const history = page.getByRole('region', { name: 'Available test orders', exact: true });
  expect(requests).toEqual([]);
  const browse = history.getByRole('button', { name: 'Browse test orders' });
  await browse.focus(); await browse.press('Enter');
  const view = history.getByRole('button', { name: `View test order status ${orderId}`, exact: true });
  await expect(view).toBeVisible();
  await expect(history.getByRole('heading', { name: 'Available test orders' })).toBeFocused();
  expect(requests).toEqual([{ path: '/orders/history', method: 'GET' }]);
  await view.focus(); await view.press('Enter');
  await expect(history).toContainText('This order needs review before fulfillment can continue.');
  await expect(history.getByRole('region', { name: 'Stripe test checkout', exact: true })).toContainText('previously verified test payment remains recorded');
  await expect(history.getByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe|Download/ })).toHaveCount(0);
  await expect(history.getByRole('region', { name: 'Test order downloads', exact: true })).toHaveCount(0);
  expect(requests).toEqual([{ path: '/orders/history', method: 'GET' }, { path: `/orders/${orderId}/checkout`, method: 'GET' }]);
  expect(await page.evaluate(() => Object.fromEntries(
    Object.keys(sessionStorage).sort().map(key => [key, sessionStorage.getItem(key)]),
  ))).toEqual(storage);
  expect(await page.evaluate(() => sessionStorage.getItem('vaseyaudio-order-recovery-v1'))).toBeNull();
  expect(await page.context().cookies()).toEqual(cookies);
  await page.screenshot({ path: testInfo.outputPath('current-session-test-order-history.png'), fullPage: true });
  expect(errors).toEqual([]);
});
