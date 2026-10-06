import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { join } from 'node:path';
import { deliveryRoles, type DeliveryKind } from '../../resources/js/lib/test-delivery';

type FileFixture = { filename: string; sha256: string; sizeBytes: number };
interface CustomerFixture { orderId: string; contract: FileFixture; asset: FileFixture & { kind: Exclude<DeliveryKind, 'contract'> } }
interface CapturedResponse { requestUrl: string; responseUrl: string; status: number; cacheControl: string | null; redirected: boolean; body: string }

/** Observe the real native response before the app can navigate away from its CDP resource.
 * No routes are intercepted, requests replayed, or response bytes/status replaced: native fetch
 * runs once, a clone is copied through the binding, then the identical Response reaches the app.
 */
async function captureNavigatingResponses(page: Page) {
  const pending = new Map<string, (response: CapturedResponse) => void>();
  await page.exposeBinding('__vaseyCustomerResponseCaptured', (_source, response: CapturedResponse) => {
    pending.get(response.requestUrl)?.(response);
  });
  await page.addInitScript(() => {
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
      const requestUrl = new URL(input instanceof Request ? input.url : String(input), window.location.href);
      const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
      const response = await nativeFetch(input, init);
      if (requestUrl.origin === window.location.origin
        && ((method === 'POST' && (/^\/account\/sign-(?:in|out)$/.test(requestUrl.pathname)
          || /^\/orders\/[a-f0-9-]{36}\/delivery\/authorizations$/.test(requestUrl.pathname)))
          || (method === 'GET' && requestUrl.pathname === '/orders/history'))) {
        const body = await response.clone().text();
        await (window as unknown as { __vaseyCustomerResponseCaptured: (value: CapturedResponse) => Promise<void> }).__vaseyCustomerResponseCaptured({
          requestUrl: requestUrl.href, responseUrl: response.url, status: response.status,
          cacheControl: response.headers.get('cache-control'), redirected: response.redirected, body,
        });
      }
      return response;
    };
  });
  return async (path: string, action: () => Promise<void>, method: 'GET' | 'POST' = 'POST') => {
    const url = new URL(path, page.url()).href;
    if (pending.has(url)) throw new Error('A customer response capture is already pending for this URL.');
    const captured = new Promise<CapturedResponse>(resolve => pending.set(url, resolve));
    const observed = page.waitForResponse(response => response.url() === url && response.request().method() === method);
    try {
      const [response, copy] = await Promise.all([observed, captured, action()]);
      expect(copy.responseUrl).toBe(response.url());
      expect(copy.status).toBe(response.status());
      expect(copy.cacheControl).toBe(response.headers()['cache-control']);
      expect(copy.redirected).toBe(false);
      return { response, body: JSON.parse(copy.body) };
    } finally { pending.delete(url); }
  };
}

function customerFixture(project: string): CustomerFixture {
  return JSON.parse(readFileSync(join(process.env.VASEY_BROWSER_DIRECTORY!, 'customer-fixtures.json'), 'utf8')).projects[project];
}
async function login(page: Page) {
  await page.goto('/account/sign-in');
  await page.getByLabel('Email address', { exact: true }).fill('browser-customer@example.test');
  await page.getByLabel('Password', { exact: true }).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/account$/);
}

/** Real isolated Laravel auth, CSRF, private Inertia pages and owner-history HTTP.
 * The guarded bootstrap provisions one synthetic test account; no provider is enabled.
 */
test('customer signs in with a fresh session, reads the account library and signs out without gaining staff access', async ({ page }, testInfo) => {
  const fixture = customerFixture(testInfo.project.name);
  const password = process.env.VASEY_BROWSER_PASSWORD;
  expect(password).toBeTruthy();
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  const capture = await captureNavigatingResponses(page);
  await page.goto('/');
  const navigation = page.getByRole('navigation', { name: 'Main navigation' });
  const menu = page.getByRole('button', { name: 'Menu', exact: true });
  if (await menu.isVisible()) await menu.click();
  await navigation.getByRole('link', { name: 'Your library', exact: true }).click();
  await expect(page).toHaveURL(/\/account\/sign-in$/);
  await expect(page.getByRole('heading', { name: 'Customer sign-in', exact: true })).toBeVisible();
  const signInPage = await page.request.get('/account/sign-in');
  expect(signInPage.headers()['cache-control']).toContain('no-store');
  expect(signInPage.headers()['x-robots-tag']).toBe('noindex, nofollow');
  const initialToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  expect(initialToken).toBeTruthy();
  await page.getByLabel('Email address', { exact: true }).fill('browser-customer@example.test');
  await page.getByLabel('Password', { exact: true }).fill('Wrong synthetic password');
  const rejection = page.waitForResponse(response => response.url().endsWith('/account/sign-in') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  expect((await rejection).status()).toBe(422);
  await expect(page.getByRole('alert')).toBeFocused();
  await expect(page.getByRole('alert')).toHaveText('Sign-in could not be completed. Check your details and try again.');
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
  await page.getByLabel('Password', { exact: true }).fill(password!);
  const { response: saved, body: authenticated } = await capture('/account/sign-in', async () => {
    await page.getByRole('button', { name: 'Sign in', exact: true }).focus();
    await page.keyboard.press('Enter');
  });
  expect(saved.status()).toBe(200);
  expect(authenticated).toEqual({ authenticated: true, next: '/account' });
  expect(saved.headers()['cache-control']).toContain('no-store');
  await expect(page).toHaveURL(/\/account$/);
  await expect(page.getByRole('heading', { name: 'Your test order library', exact: true })).toBeVisible();
  await expect(page.getByText('Synthetic Customer', { exact: true })).toBeVisible();
  const currentToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  expect(currentToken).toBeTruthy(); expect(currentToken).not.toBe(initialToken);
  const region = page.getByRole('region', { name: 'Your account test orders', exact: true });
  const { response: history, body: originalHistory } = await capture('/orders/history',
    () => region.getByRole('button', { name: 'Browse account orders', exact: true }).click(), 'GET');
  expect(history.status()).toBe(200); expect(history.headers()['cache-control']).toContain('no-store');
  expect(originalHistory).toEqual({ history: { orderHistorySchema: 2, testOnly: true, orders: expect.arrayContaining([expect.objectContaining({ id: fixture.orderId, paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'issued' })]), previews: expect.arrayContaining([expect.objectContaining({ orderId: fixture.orderId, itemCount: 1, firstItem: expect.objectContaining({ title: expect.any(String), licenseName: expect.any(String), licenseVersion: expect.any(Number) }) })]), limit: 20, nextCursor: null } });
  await expect(region.getByRole('heading', { name: 'Account orders', exact: true })).toBeFocused();
  await expect(region.getByRole('button', { name: `View test order status ${fixture.orderId}`, exact: true })).toBeVisible();
  const admin = await page.request.get('/admin', { maxRedirects: 0 });
  expect(admin.status()).toBe(302); expect(admin.headers().location).toMatch(/\/admin\/login$/);
  const missingCsrf = await page.request.post('/account/sign-out', { data: {}, headers: { Accept: 'application/json' } });
  expect(missingCsrf.status()).toBe(419);
  const retained = await page.request.get('/account', { maxRedirects: 0 });
  expect(retained.status()).toBe(200); expect(retained.headers()['cache-control']).toContain('no-store');
  const storage = await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n'));
  for (const privateValue of [password!, 'browser-customer@example.test', 'Synthetic Customer', currentToken!]) expect(storage).not.toContain(privateValue);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('customer-account-native-library.png'), fullPage: true });
  const { response: ended, body: signedOut } = await capture('/account/sign-out', () => page.getByRole('button', { name: 'Sign out', exact: true }).click());
  expect(ended.status()).toBe(200); expect(signedOut).toEqual({ authenticated: false, next: '/account/sign-in' });
  await expect(page).toHaveURL(/\/account\/sign-in$/);
  await expect(page.getByText('Synthetic Customer', { exact: true })).toHaveCount(0);
  expect(await page.locator('meta[name="csrf-token"]').getAttribute('content')).not.toBe(currentToken);
  await page.goto('/account'); await expect(page).toHaveURL(/\/account\/sign-in$/);
  expect(errors).toEqual([]);
});


/** Setup passes synthetic provider evidence through real verification/finalization/issuance/
 * activation services. This journey uses real HTTP throughout and never intercepts routes.
 */
test('a separate login session discovers the frozen test purchase and downloads its original contract and asset', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  const fixture = customerFixture(testInfo.project.name);
  const first = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
  let fresh: Awaited<ReturnType<typeof browser.newContext>> | undefined;
  const errors: string[] = [];
  try {
    const initial = await first.newPage(); initial.on('pageerror', error => errors.push(error.message));
    await login(initial);
    const beforeResponse = await initial.request.get(`/orders/${fixture.orderId}/status`);
    expect(beforeResponse.status()).toBe(200);
    const before = await beforeResponse.json();
    expect(before.order).toMatchObject({ id: fixture.orderId, testOnly: true, status: 'paid', paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'issued' });
    await initial.evaluate(() => { sessionStorage.setItem('synthetic-prior-customer-context', 'prior'); localStorage.setItem('synthetic-prior-customer-context', 'prior'); });
    const firstCookies = await first.cookies();
    const firstSession = firstCookies.find(cookie => cookie.name === process.env.SESSION_COOKIE)?.value;
    expect(firstSession).toBeTruthy();
    // Closing this entire context discards its cookies and storage; none are copied to the next one.
    await first.close();
    fresh = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
    const page = await fresh.newPage(); page.on('pageerror', error => errors.push(error.message));
    const capture = await captureNavigatingResponses(page);
    const denied = await page.request.get(`/orders/${fixture.orderId}/status`);
    expect(denied.status()).toBe(404);
    expect(await denied.text()).not.toContain(fixture.orderId);
    await login(page);
    const newSession = (await fresh.cookies()).find(cookie => cookie.name === process.env.SESSION_COOKIE)?.value;
    expect(newSession).toBeTruthy(); expect(newSession).not.toBe(firstSession);
    expect(await page.evaluate(() => ({
      previousSession: sessionStorage.getItem('synthetic-prior-customer-context'),
      previousLocal: localStorage.getItem('synthetic-prior-customer-context'),
      // Inertia stores only its fresh history encryption material for these private pages.
      extraSessionKeys: Object.keys(sessionStorage).filter(key => !['historyKey', 'historyIv'].includes(key)),
    }))).toEqual({ previousSession: null, previousLocal: null, extraSessionKeys: [] });
    const requests: Array<{ path: string; method: string }> = [];
    page.on('request', request => { const path = new URL(request.url()).pathname; if (path.startsWith('/orders/')) requests.push({ path, method: request.method() }); });
    const history = page.getByRole('region', { name: 'Your account test orders', exact: true });
    await history.getByRole('button', { name: 'Browse account orders', exact: true }).click();
    await history.getByRole('button', { name: `View test order status ${fixture.orderId}`, exact: true }).click();
    await expect(history).toContainText('Test contracts have been issued.');
    await expect(history.getByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).toHaveCount(0);
    const panel = history.getByRole('region', { name: 'Test order downloads', exact: true });
    const files: Array<{ kind: DeliveryKind; expected: FileFixture }> = [
      { kind: 'contract', expected: fixture.contract }, { kind: fixture.asset.kind, expected: fixture.asset },
    ];
    for (const { kind, expected } of files) {
      const downloaded = page.waitForEvent('download');
      const button = panel.getByRole('button', { name: `Download ${deliveryRoles[kind].label}`, exact: true });
      const [{ response, body }, download] = await Promise.all([
        capture(`/orders/${fixture.orderId}/delivery/authorizations`, async () => { await button.focus(); await button.press('Enter'); }),
        downloaded,
      ]);
      expect(response.status()).toBe(201);
      expect(response.headers()['cache-control']).toContain('no-store');
      const authorization = body.authorization;
      expect(download.suggestedFilename()).toBe(expected.filename);
      const path = testInfo.outputPath(expected.filename); await download.saveAs(path);
      const bytes = await readFile(path);
      expect(bytes.byteLength).toBe(expected.sizeBytes);
      expect(createHash('sha256').update(bytes).digest('hex')).toBe(expected.sha256);
      await expect(panel.getByRole('status')).toContainText('cannot confirm file receipt');
      expect(await page.locator('html').innerHTML()).not.toContain(authorization.token);
      expect(page.url()).not.toContain(authorization.token);
      expect(await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).not.toContain(authorization.token);
      await expect(page.locator('input[name="token"], form[action$="/delivery/download"]')).toHaveCount(0);
    }
    await panel.getByRole('button', { name: 'Refresh downloads and recent attempts', exact: true }).click();
    await expect(panel.getByText('Stream attempted', { exact: false })).toHaveCount(2);
    expect(requests.filter(request => request.method === 'POST')).toEqual([
      { path: `/orders/${fixture.orderId}/delivery/authorizations`, method: 'POST' },
      { path: `/orders/${fixture.orderId}/delivery/download`, method: 'POST' },
      { path: `/orders/${fixture.orderId}/delivery/authorizations`, method: 'POST' },
      { path: `/orders/${fixture.orderId}/delivery/download`, method: 'POST' },
    ]);
    const after = await page.request.get(`/orders/${fixture.orderId}/status`);
    expect(after.status()).toBe(200); expect(await after.json()).toEqual(before);
    await page.screenshot({ path: testInfo.outputPath('customer-original-downloads.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally { await first.close(); await fresh?.close(); }
});
