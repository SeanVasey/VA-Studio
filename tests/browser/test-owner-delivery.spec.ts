import { test as baseTest, expect, type Page, type Route } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import type { AddressInfo } from 'node:net';

/** Synthetic transport verifies built React, browser POST/download behavior and privacy in
 * Chromium/WebKit only. PHP feature/MySQL tests separately prove actual owner authorization,
 * retained targets and one-attempt consumption. No provider or purchased files are involved.
 */
const orderId = '77000000-0000-4000-8000-000000000001';
const grantId = '77000000-0000-4000-8000-000000000002';
const authorizationId = '77000000-0000-4000-8000-000000000003';
const token = 'SYNTHETIC_BROWSER_ONLY_PRIVATE_TOKEN'.padEnd(43, '0');
const path = `/orders/${orderId}/delivery`;
const returnPath = '/synthetic-owner-delivery';
const filename = `${grantId}-contract.pdf`;
const fixtureBytes = '%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF\n';
interface AttachmentServer { url: string; received: string[] }
const test = baseTest.extend<{ attachmentServer: AttachmentServer }>({
  attachmentServer: async ({}, use) => {
    const received: string[] = [];
    // WebKit does not reliably expose downloads from route.fulfill (Playwright #22691).
    // Serve actual HTTP attachment bytes while retaining the native POST and download assertions.
    const server = createServer(async (request, response) => {
      if (request.method !== 'POST' || request.url !== '/attachment') { response.writeHead(404).end(); return; }
      if (request.headers['content-type'] !== 'application/x-www-form-urlencoded') { response.writeHead(415).end(); return; }
      try {
        const chunks: Buffer[] = []; let size = 0;
        for await (const chunk of request) {
          size += chunk.length;
          if (size > 4096) { response.writeHead(413).end(); return; }
          chunks.push(Buffer.from(chunk));
        }
        const body = Buffer.concat(chunks).toString('utf8'), fields = new URLSearchParams(body);
        if (fields.size !== 3 || fields.get('authorizationId') !== authorizationId || fields.get('token') !== token
          || !/^[A-Za-z0-9]{40}$/.test(fields.get('_token') ?? '')) { response.writeHead(422).end(); return; }
        received.push(body);
        response.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Disposition': `attachment; filename="${filename}"`,
          'Content-Length': Buffer.byteLength(fixtureBytes), 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff' });
        response.end(fixtureBytes);
      } catch { if (!response.writableEnded) response.writeHead(400).end(); }
    });
    await new Promise<void>((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    try { await use({ url: `http://127.0.0.1:${(server.address() as AddressInfo).port}/attachment`, received }); }
    finally { server.closeAllConnections(); await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve())); }
  },
});
const utc = (time = Date.now()) => new Date(Math.floor(time / 1000) * 1000).toISOString().replace('.000Z', 'Z');
const delivery = () => ({ deliverySchema: 1, orderId, testOnly: true, status: 'available',
  items: [{ grantId, kind: 'contract', filename, mimeType: 'application/pdf', sizeBytes: 32 }], history: [] as unknown[], historyLimit: 20, historyHasMore: false });
const authorization = () => ({ authorizationId, token, expiresAt: utc(Date.now() + 60_000), filename, mimeType: 'application/pdf' });
interface Request { path: string; method: string; body: string | null; headers: Record<string, string> }
async function install(page: Page, handle: (route: Route, request: Request) => Promise<void>, contractIssued = () => true) {
  const response = await page.request.get('/'); expect(response.ok()).toBe(true);
  const shell = await response.text();
  const script = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
  const embedded = shell.match(script); expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]); const requests: Request[] = []; const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === returnPath) {
      const payload = { ...base, component: 'CheckoutReturn', url: url.pathname, props: { ...base.props, orderId } };
      const serialized = JSON.stringify(payload).replaceAll('<', '\\u003c');
      return route.fulfill({ contentType: 'text/html', body: shell.replace(script, (_match, open, _old, close) => open + serialized + close) });
    }
    if (url.pathname === `/orders/${orderId}/checkout`) return route.fulfill({ json: { checkout: {
      checkoutSchema: 1, orderId, id: '77000000-0000-4000-8000-000000000004', currency: 'USD', totalMinor: 4280,
      status: 'complete', testOnly: true, paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: contractIssued() ? 'issued' : 'attention', fulfillmentStatus: contractIssued() ? 'pending_activation' : 'blocked',
      url: null, expiresAt: null, observedAt: utc(),
    } } });
    if (!url.pathname.startsWith(path)) return route.continue();
    const entry = { path: url.pathname, method: request.method(), body: request.postData(), headers: request.headers() };
    requests.push(entry); expect(request.url()).not.toContain(token); expect(url.search).toBe('');
    return handle(route, entry);
  });
  return { requests, errors };
}
async function assertPrivate(page: Page) {
  expect(await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).toBe('{"local":[],"session":[]}');
  expect(await page.locator('html').innerHTML()).not.toContain(token);
  expect(page.url()).not.toContain(token);
  await expect(page.locator('input[name="token"], form[action$="/delivery/download"]')).toHaveCount(0);
}

test('native attachment uses an exact CSRF-protected POST and reports attempts without claiming receipt', async ({ page, attachmentServer }, testInfo) => {
  let attempted = false;
  const { requests, errors } = await install(page, async (route, request) => {
    if (request.path === path) return route.fulfill({ json: { delivery: { ...delivery(), history: attempted ? [{ authorizationId, grantId, kind: 'contract', issuedAt: utc(), expiresAt: utc(Date.now() + 60_000), status: 'attempted', attemptedAt: utc() }] : [] } } });
    if (request.path.endsWith('/authorizations')) return route.fulfill({ status: 201, json: { authorization: authorization() } });
    attempted = true;
    return route.continue({ url: attachmentServer.url });
  });
  await page.goto(returnPath);
  const panel = page.getByRole('region', { name: 'Test order downloads', exact: true });
  const button = panel.getByRole('button', { name: 'Download Original contract', exact: true });
  await expect(button).toBeVisible(); expect(requests.map(request => request.method)).toEqual(['GET']);
  const downloadEvent = page.waitForEvent('download');
  await button.focus(); await button.press('Enter');
  const download = await downloadEvent;
  expect(download.suggestedFilename()).toBe(filename);
  const filePath = testInfo.outputPath(filename); await download.saveAs(filePath); expect(await readFile(filePath, 'utf8')).toBe(fixtureBytes);
  await expect(panel.getByRole('status')).toContainText('cannot confirm file receipt');
  const issue = requests.find(request => request.path.endsWith('/authorizations'))!;
  expect(issue.method).toBe('POST'); expect(JSON.parse(issue.body!)).toEqual({ grantId, kind: 'contract' });
  expect(issue.headers['x-csrf-token']).toBeTruthy(); expect(issue.headers['idempotency-key']).toMatch(/^[a-f0-9-]{36}$/);
  const native = requests.find(request => request.path.endsWith('/download'))!;
  expect(native.method).toBe('POST'); expect(native.headers['content-type']).toContain('application/x-www-form-urlencoded');
  expect(Object.fromEntries(new URLSearchParams(native.body!))).toEqual({ authorizationId, token, _token: issue.headers['x-csrf-token'] });
  expect(attachmentServer.received).toEqual([native.body]);
  await assertPrivate(page);
  await panel.getByRole('button', { name: 'Refresh downloads and recent attempts' }).click();
  await expect(panel).toContainText('Stream attempted'); expect(requests.filter(request => request.method === 'POST')).toHaveLength(2);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('test-owner-delivery.png'), fullPage: true }); expect(errors).toEqual([]);
});

test('uncertain issuance retries one key, replacement is explicit, and native expiry remains a private failure', async ({ page }) => {
  let issues = 0;
  const { requests, errors } = await install(page, async (route, request) => {
    if (request.path === path) return route.fulfill({ json: { delivery: delivery() } });
    if (request.path.endsWith('/authorizations')) {
      issues += 1;
      if (issues === 1) return route.abort('failed');
      if (issues === 2) return route.fulfill({ status: 409, json: { code: 'DELIVERY_ALREADY_ISSUED', message: 'PRIVATE_DIAGNOSTIC' } });
      return route.fulfill({ status: 201, json: { authorization: authorization() } });
    }
    return route.fulfill({ status: 410, json: { code: 'DELIVERY_EXPIRED', message: 'PRIVATE_DIAGNOSTIC' } });
  });
  await page.goto(returnPath); const panel = page.getByRole('region', { name: 'Test order downloads', exact: true });
  await panel.getByRole('button', { name: 'Download Original contract', exact: true }).click();
  await expect(panel.getByRole('alert')).toContainText('unconfirmed');
  await expect(panel.getByRole('button', { name: 'Download Original contract', exact: true })).toBeDisabled();
  await panel.getByRole('button', { name: 'Refresh downloads and recent attempts' }).click();
  await panel.getByRole('button', { name: 'Retry the same authorization request' }).click();
  await expect(panel.getByRole('alert')).toContainText('secret cannot be recovered');
  await panel.getByRole('button', { name: 'Request another Original contract', exact: true }).click();
  await expect(panel.getByRole('alert')).toContainText('authorization expired');
  const issued = requests.filter(request => request.path.endsWith('/authorizations'));
  expect(issued).toHaveLength(3); expect(issued[0].headers['idempotency-key']).toBe(issued[1].headers['idempotency-key']);
  expect(issued[2].headers['idempotency-key']).not.toBe(issued[1].headers['idempotency-key']);
  expect(issued.every(request => request.body === JSON.stringify({ grantId, kind: 'contract' }))).toBe(true);
  expect(requests.filter(request => request.path.endsWith('/download'))).toHaveLength(1);
  await expect(panel).not.toContainText('PRIVATE_DIAGNOSTIC'); await expect(panel.getByRole('status')).toHaveCount(0);
  await assertPrivate(page); expect(errors).toEqual([]);
});

test('malformed responses never submit, while server expiry handles a skewed browser clock privately', async ({ page }) => {
  let reads = 0, issues = 0;
  const { requests, errors } = await install(page, async (route, request) => {
    if (request.path === path) return route.fulfill({ json: { delivery: ++reads === 1 ? { ...delivery(), privatePath: 'PRIVATE_SERVER_PATH' } : delivery() } });
    if (request.path.endsWith('/authorizations')) return route.fulfill({ status: 201, json: { authorization: ++issues === 1
      ? { ...authorization(), filename: '../PRIVATE_SERVER_PATH.pdf' } : authorization() } });
    return route.fulfill({ status: 410, json: { code: 'DELIVERY_EXPIRED', message: 'PRIVATE_SERVER_PATH' } });
  });
  await page.goto(returnPath); const panel = page.getByRole('region', { name: 'Test order downloads', exact: true });
  await expect(panel.getByRole('alert')).toContainText('could not be loaded');
  await expect(panel.getByRole('button', { name: 'Download Original contract', exact: true })).toHaveCount(0);
  await panel.getByRole('button', { name: 'Refresh downloads and recent attempts' }).click();
  await panel.getByRole('button', { name: 'Download Original contract', exact: true }).click();
  await expect(panel.getByRole('alert')).toContainText('unconfirmed');
  expect(requests.filter(request => request.path.endsWith('/download'))).toHaveLength(0);
  await panel.getByRole('button', { name: 'Start a new authorization request' }).click();
  await page.evaluate(() => { const originalNow = Date.now; Date.now = () => originalNow() + 600_000; });
  await panel.getByRole('button', { name: 'Download Original contract', exact: true }).click();
  await expect(panel.getByRole('alert')).toContainText('authorization expired');
  expect(requests.filter(request => request.path.endsWith('/download'))).toHaveLength(1);
  await expect(panel).not.toContainText('PRIVATE_SERVER_PATH'); await assertPrivate(page); expect(errors).toEqual([]);
});


test('a late authorization cannot stream after the checkout state unmounts its delivery panel', async ({ page }) => {
  let issued = true; let release!: () => void;
  const held = new Promise<void>(resolve => { release = resolve; });
  const { requests, errors } = await install(page, async (route, request) => {
    if (request.path === path) return route.fulfill({ json: { delivery: delivery() } });
    if (request.path.endsWith('/authorizations')) {
      await held;
      // Fetch is aborted by component cleanup; some engines discard its intercepted response.
      try { await route.fulfill({ status: 201, json: { authorization: authorization() } }); }
      catch (error) { expect(String(error)).toMatch(/closed|aborted|handled|intercept/i); }
      return;
    }
    throw new Error('An unmounted delivery panel must never submit an attachment');
  }, () => issued);
  await page.goto(returnPath);
  const panel = page.getByRole('region', { name: 'Test order downloads', exact: true });
  const issueRequest = page.waitForRequest(request => new URL(request.url()).pathname === `${path}/authorizations`);
  await panel.getByRole('button', { name: 'Download Original contract', exact: true }).click(); await issueRequest;
  issued = false;
  await page.getByRole('button', { name: 'Refresh test order status' }).click();
  await expect(panel).toHaveCount(0); release();
  await expect(page.getByRole('region', { name: 'Stripe test checkout', exact: true })).toContainText('Contract preparation needs attention.');
  await expect(page.locator('iframe[title="Test download response"]')).toHaveCount(0);
  expect(requests.filter(request => request.path.endsWith('/download'))).toHaveLength(0);
  await assertPrivate(page); expect(errors).toEqual([]);
});
