import { test, expect, type Page, type Locator } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { resetBrowserLoginRateLimit } from './auth-fixture';

type FirstItem = { title: string; licenseName: string; licenseVersion: number };
type PreparedOrder = { id: string; firstItem: FirstItem; items: { orderId: string; totalMinor: number; lines: FirstItem[] } };
type Fixture = { email: string; orders: PreparedOrder[]; changedTitle: string };
type Captured = { requestUrl: string; responseUrl: string; status: number; cache: string | null; redirected: boolean; body: string };

function fixture(mode: 'prepare' | 'withdraw' | 'verify', project: string) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-customer-history.php', mode, project], {
    cwd: process.cwd(), env: process.env, timeout: 120_000, encoding: 'utf8',
  }));
}

/** Observe actual native GET bytes without intercepting, replacing or retrying application requests. */
async function captureReads(page: Page) {
  const copies: Captured[] = [];
  await page.exposeBinding('__vaseyHistoryRead', (_source, copy: Captured) => { copies.push(copy); });
  await page.addInitScript(() => {
    const native = window.fetch.bind(window);
    window.fetch = async (input, init) => {
      const url = new URL(input instanceof Request ? input.url : String(input), location.href);
      const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
      const response = await native(input, init);
      if (url.origin === location.origin && method === 'GET' && (url.pathname === '/orders/history' || url.pathname.endsWith('/items'))) {
        await (window as unknown as { __vaseyHistoryRead: (copy: Captured) => Promise<void> }).__vaseyHistoryRead({
          requestUrl: url.href, responseUrl: response.url, status: response.status,
          cache: response.headers.get('cache-control'), redirected: response.redirected, body: await response.clone().text(),
        });
      }
      return response;
    };
  });
  return async (path: string, action: () => Promise<void>, status = 200) => {
    const url = new URL(path, page.url()).href;
    const previous = copies.length;
    const waiting = page.waitForResponse(response => response.url() === url && response.request().method() === 'GET');
    const [response] = await Promise.all([waiting, action()]);
    await expect.poll(() => copies.length).toBe(previous + 1);
    const copy = copies[previous];
    expect(copy.requestUrl).toBe(url); expect(copy.responseUrl).toBe(response.url()); expect(copy.redirected).toBe(false);
    expect(copy.status).toBe(status); expect(response.status()).toBe(status);
    expect(copy.cache).toBe(response.headers()['cache-control']); expect(copy.cache).toContain('no-store');
    return JSON.parse(copy.body);
  };
}

async function keyboard(button: Locator) { await button.focus(); await button.press('Enter'); }
async function login(page: Page, email: string) {
  await page.goto('/account/sign-in');
  await page.getByLabel('Email address', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await keyboard(page.getByRole('button', { name: 'Sign in', exact: true }));
  await expect(page).toHaveURL(/\/account$/);
}

test('account library browses three fresh pages of original snapshots and clears private results on sign-out and withdrawal', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  resetBrowserLoginRateLimit();
  const prepared: Fixture = fixture('prepare', testInfo.project.name);
  expect(prepared.orders).toHaveLength(41);
  const capture = await captureReads(page);
  const errors: string[] = [], reads: string[] = [], mutations: string[] = [], externalRequests: string[] = [];
  const failures: Array<{ url: string; error: string | null }> = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('requestfailed', request => failures.push({ url: request.url(), error: request.failure()?.errorText ?? null }));
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.origin !== 'http://127.0.0.1:8173') externalRequests.push(request.url());
    if (url.pathname === '/orders/history') reads.push(url.pathname + url.search);
    if ((url.pathname === '/orders' || url.pathname.startsWith('/orders/')) && request.method() !== 'GET') mutations.push(`${request.method()} ${url.pathname}`);
  });
  await login(page, prepared.email);
  const history = page.getByRole('region', { name: 'Your account test orders', exact: true });
  const heading = history.getByRole('heading', { name: 'Account orders', exact: true });
  const button = (name: string) => history.getByRole('button', { name, exact: true });
  const firstCursor = prepared.orders[19].id, secondCursor = prepared.orders[39].id;
  const paths = ['/orders/history', `/orders/history?before=${firstCursor}`, `/orders/history?before=${secondCursor}`];
  expect(reads).toEqual([]);

  async function readPage(index: number, label: string) {
    const expected = prepared.orders.slice(index * 20, (index + 1) * 20);
    const body = await capture(paths[index], () => keyboard(button(label)));
    expect(Object.keys(body)).toEqual(['history']);
    expect(Object.keys(body.history).sort()).toEqual(['limit', 'nextCursor', 'orderHistorySchema', 'orders', 'previews', 'testOnly'].sort());
    expect(body.history).toMatchObject({ orderHistorySchema: 2, testOnly: true, limit: 20,
      nextCursor: index === 2 ? null : expected[19].id });
    expect(body.history.orders.map((order: { id: string }) => order.id)).toEqual(expected.map(order => order.id));
    expect(body.history.previews).toEqual(expected.map(order => ({ orderId: order.id, itemCount: 1, firstItem: order.firstItem })));
    for (const order of body.history.orders) {
      expect(Object.keys(order).sort()).toEqual(['id', 'createdAt', 'testOnly', 'payable', 'currency', 'totalMinor',
        'status', 'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus'].sort());
      expect(order).toMatchObject({ status: 'prepared', paymentStatus: 'not_started', finalizationStatus: 'not_started',
        contractStatus: 'not_started', fulfillmentStatus: 'not_started', testOnly: true, payable: false, currency: 'USD',
        totalMinor: expected.find(original => original.id === order.id)!.items.totalMinor });
    }
    await expect(heading).toBeFocused();
    await expect(history.locator('.quote-review-item')).toHaveCount(expected.length);
    for (const order of expected) {
      const card = history.locator('.quote-review-item').filter({ has: page.getByRole('button', { name: `View test order status ${order.id}`, exact: true }) });
      await expect(card.getByRole('heading', { name: order.firstItem.title, exact: true })).toBeVisible();
      await expect(card.getByText('First original item · 1 item in this order', { exact: true })).toBeVisible();
      await expect(card.getByText(`${order.firstItem.licenseName} · Version ${order.firstItem.licenseVersion}`, { exact: true })).toBeVisible();
      await expect(card.getByText('Payment has not been verified.', { exact: true })).toBeVisible();
    }
    await expect(history).not.toContainText(prepared.changedTitle);
    return body;
  }

  const newest = await readPage(0, 'Browse account orders');
  await expect(button('Newer account orders')).toHaveCount(0);
  await readPage(1, 'Older account orders');
  await readPage(2, 'Older account orders');
  await expect(button('Older account orders')).toHaveCount(0);
  await readPage(1, 'Newer account orders');
  expect(await readPage(0, 'Newer account orders')).toEqual(newest);
  await readPage(1, 'Older account orders');
  expect(await readPage(0, 'Newest account orders')).toEqual(newest);
  // Returning newer/newest performs another actual HTTP GET; no retained page is reused.
  expect(reads).toEqual([paths[0], paths[1], paths[2], paths[1], paths[0], paths[1], paths[0]]);

  const original = prepared.orders[0];
  await keyboard(button(`View test order status ${original.id}`));
  const items = history.getByRole('region', { name: 'Original test-order items', exact: true });
  const details = await capture(`/orders/${original.id}/items`, () => keyboard(items.getByRole('button', { name: 'View original test-order items', exact: true })));
  expect(details).toEqual({ items: original.items });
  await expect(items.getByRole('heading', { name: original.firstItem.title, exact: true })).toBeVisible();
  await expect(items).not.toContainText(prepared.changedTitle);
  await expect(history.getByRole('button', { name: /Open Stripe|Retry Stripe|Download / })).toHaveCount(0);
  await expect(page).toHaveURL(/\/account$/);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(await button('Older account orders').evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  const stored = await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }));
  for (const value of [prepared.email, original.firstItem.title, original.firstItem.licenseName, ...prepared.orders.map(order => order.id)]) expect(stored).not.toContain(value);
  await page.screenshot({ path: testInfo.outputPath('customer-library-three-page-browsing.png'), fullPage: true });

  await keyboard(page.getByRole('button', { name: 'Sign out', exact: true }));
  await expect(page).toHaveURL(/\/account\/sign-in$/);
  await expect(history).toHaveCount(0); await expect(items).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText(original.id);
  await login(page, prepared.email);
  expect(await readPage(0, 'Browse account orders')).toEqual(newest);
  expect(fixture('withdraw', testInfo.project.name)).toEqual({ withdrawn: true });
  const denied = await capture(paths[1], () => keyboard(button('Older account orders')), 403);
  expect(denied).toEqual({ code: 'CUSTOMER_SIGN_IN_UNAVAILABLE', message: 'Sign-in could not be completed. Check your details and try again.' });
  await expect(history.getByRole('alert')).toContainText('could not be loaded');
  await expect(history.locator('.quote-review-item')).toHaveCount(0);
  await expect(heading).toHaveCount(0);
  await expect(button('Newer account orders')).toHaveCount(0); await expect(button('Newest account orders')).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText(original.id);
  expect(mutations).toEqual([]);
  const receipt = fixture('verify', testInfo.project.name);
  expect(receipt).toEqual({ orderCount: 41, retainedOriginalsUnchanged: true, businessRowsUnchanged: true, guardsUnchanged: true, accountWithdrawn: true });
  await testInfo.attach('customer-library-browsing-receipt', { body: JSON.stringify({ ...receipt, historyReads: reads, orderMutations: mutations, externalRequests, networkFailures: failures, pageErrors: errors }), contentType: 'application/json' });
  await keyboard(page.getByRole('button', { name: 'Sign out', exact: true }));
  await expect(page).toHaveURL(/\/account\/sign-in$/);
  expect(errors).toEqual([]); expect(externalRequests).toEqual([]); expect(failures).toEqual([]);
});
