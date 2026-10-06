import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { deliveryRoles, type DeliveryKind } from '../../resources/js/lib/test-delivery';
import { resetBrowserLoginRateLimit } from './auth-fixture';

type Fixture = { orderId: string; files: { kind: DeliveryKind; filename: string; sha256: string; sizeBytes: number }[] };
type Captured = { path: string; status: number; cache: string | null; body: string };
function fixture(mode: 'prepare' | 'verify', project: string, input?: unknown) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-purchase-claim.php', mode, project], {
    cwd: process.cwd(), env: process.env, input: input ? JSON.stringify(input) : undefined, timeout: 90_000, encoding: 'utf8',
  }));
}
async function capture(page: Page) {
  const responses: Captured[] = [];
  await page.exposeBinding('__vaseyPurchaseResponse', (_source, value: Captured) => { responses.push(value); });
  await page.addInitScript(() => {
    const native = window.fetch.bind(window);
    window.fetch = async (input, init) => {
      const url = new URL(input instanceof Request ? input.url : String(input), location.href);
      const response = await native(input, init);
      if ((init?.method ?? 'GET').toUpperCase() === 'POST' && url.origin === location.origin
        && (url.pathname.startsWith('/account/') || url.pathname.endsWith('/delivery/authorizations'))) {
        await (window as unknown as { __vaseyPurchaseResponse: (value: Captured) => Promise<void> }).__vaseyPurchaseResponse({
          path: url.pathname, status: response.status, cache: response.headers.get('cache-control'), body: await response.clone().text(),
        });
      }
      return response;
    };
  });
  return responses;
}
async function login(page: Page) {
  await page.getByLabel('Email address', { exact: true }).fill('browser-customer@example.test');
  await page.getByLabel('Password', { exact: true }).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/account$/);
}

test('original guest browser saves one purchase explicitly and a fresh account session downloads the unchanged originals', async ({ page, browser }, testInfo) => {
  test.setTimeout(180_000); resetBrowserLoginRateLimit();
  const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
  const responses = await capture(page); let fresh: BrowserContext | undefined;
  try {
    await page.goto('/account/sign-in');
    expect((await page.request.get('/orders/history')).status()).toBe(200); // Creates only original guest possession.
    const cookie = (await page.context().cookies()).find(value => value.name === process.env.SESSION_COOKIE);
    expect(cookie).toBeTruthy();
    const prepared: Fixture = fixture('prepare', testInfo.project.name, { name: cookie!.name, value: cookie!.value });
    const csrfDenied = await page.request.post('/account/purchase-claim/stage', { data: { orderId: prepared.orderId } });
    expect(csrfDenied.status()).toBe(419); expect(csrfDenied.headers()['cache-control']).toContain('no-store');
    await page.getByLabel('Guest test-order reference', { exact: true }).fill(prepared.orderId);
    await page.getByRole('button', { name: 'Select purchase before sign-in', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Save a guest purchase' }).getByRole('status')).toContainText('Sign in below');
    const staged = responses.find(value => value.path.endsWith('/purchase-claim/stage'))!;
    expect(staged.status).toBe(200); expect(staged.cache).toContain('no-store');
    expect(JSON.parse(staged.body)).toEqual({ orderId: prepared.orderId, staged: true });
    await login(page);
    expect((await page.request.get(`/orders/${prepared.orderId}/status`)).status()).toBe(404);
    await page.getByRole('button', { name: 'Save purchase to this account', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Save selected guest purchase' }).getByRole('status')).toContainText('Saved to your test account');
    const saved = responses.find(value => value.path.endsWith('/purchase-claim/complete'))!;
    expect(saved.status).toBe(200); expect(saved.cache).toContain('no-store');
    expect(JSON.parse(saved.body)).toEqual({ orderId: prepared.orderId, saved: true });
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/sign-in$/);
    expect((await page.request.get(`/orders/${prepared.orderId}/status`)).status()).toBe(404);
    fresh = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173', acceptDownloads: true });
    const account = await fresh.newPage(); account.on('pageerror', error => errors.push(error.message));
    const downloads = await capture(account);
    expect((await account.request.get(`/orders/${prepared.orderId}/status`)).status()).toBe(404);
    await account.goto('/account/sign-in'); await login(account);
    await account.getByRole('button', { name: 'Browse account orders', exact: true }).click();
    await account.getByRole('button', { name: `View test order status ${prepared.orderId}`, exact: true }).click();
    const history = account.getByRole('region', { name: 'Your account test orders', exact: true });
    await history.getByRole('button', { name: 'View original test-order items', exact: true }).click();
    await expect(history.getByRole('region', { name: 'Original test-order items', exact: true })).toBeVisible();
    const items = await account.request.get(`/orders/${prepared.orderId}/items`);
    expect(items.status()).toBe(200); expect((await items.json()).items.orderId).toBe(prepared.orderId);
    await account.getByLabel('Order reference', { exact: true }).fill(prepared.orderId);
    await account.getByRole('button', { name: 'Find order', exact: true }).click();
    await expect(account.getByRole('region', { name: 'Find an account order', exact: true })).toContainText('TEST ORDER STATUS');
    await account.getByRole('button', { name: 'Clear order lookup', exact: true }).click();
    const lookup = await account.request.get(`/orders/${prepared.orderId}/status`);
    expect(lookup.status()).toBe(200); expect((await lookup.json()).order.paymentStatus).toBe('verified');
    const panel = history.getByRole('region', { name: 'Test order downloads', exact: true });
    for (const expected of prepared.files) {
      const downloaded = account.waitForEvent('download');
      const button = panel.getByRole('button', { name: `Download ${deliveryRoles[expected.kind].label}`, exact: true });
      await button.focus(); await button.press('Enter');
      const download = await downloaded;
      expect(download.suggestedFilename()).toBe(expected.filename);
      const output = testInfo.outputPath(expected.filename); await download.saveAs(output);
      const bytes = await readFile(output); expect(bytes.length).toBe(expected.sizeBytes); expect(createHash('sha256').update(bytes).digest('hex')).toBe(expected.sha256);
    }
    expect(downloads.filter(value => value.path.endsWith('/delivery/authorizations'))).toHaveLength(prepared.files.length);
    for (const value of downloads.filter(value => value.path.endsWith('/delivery/authorizations'))) {
      expect(value.status).toBe(201); expect(value.cache).toContain('no-store');
      const token = JSON.parse(value.body).authorization.token;
      expect(await account.locator('html').innerHTML()).not.toContain(token);
      expect(await account.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).not.toContain(token);
    }
    const receipt = fixture('verify', testInfo.project.name);
    expect(receipt).toEqual({ orderId: prepared.orderId, retainedOriginalsUnchanged: true, singleClaim: true, downloadAttempts: prepared.files.length });
    await testInfo.attach('guest-purchase-claim-receipt', { body: JSON.stringify(receipt), contentType: 'application/json' });
    await account.screenshot({ path: testInfo.outputPath('saved-guest-purchase.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally { await fresh?.close(); }
});
