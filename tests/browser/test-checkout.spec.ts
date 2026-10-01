import { test, expect } from '@playwright/test';
import { fixtureTrack, query, storefrontFixture } from './storefront-fixture';

/** Synthetic HTTP transport exercises built React and native controls only.
 * PHP feature/race tests prove authorization, durable intent and provider semantics.
 * No request reaches Stripe and no payment or fulfillment is simulated as verified.
 */
test('a return URL cannot confirm payment and an interrupted pending checkout recovers explicitly', async ({ page }, testInfo) => {
  const shellResponse = await page.request.get('/');
  expect(shellResponse.ok()).toBe(true);
  const shell = await shellResponse.text();
  const pageScript = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
  const embedded = shell.match(pageScript);
  expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]);
  const orderId = '75000000-0000-4000-8000-000000000001';
  const path = `/orders/${orderId}/checkout`;
  const returnPath = '/synthetic-checkout-return';
  const checkoutUrl = 'https://checkout.stripe.com/c/pay/cs_test_BrowserOnly';
  const checkout = {
    checkoutSchema: 1, orderId, id: '75000000-0000-4000-8000-000000000002',
    currency: 'USD', totalMinor: 4280, status: 'pending', testOnly: true,
    paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started',
    url: null as string | null, expiresAt: null as string | null, observedAt: null as string | null,
  };
  const requests: Array<{ path: string; method: string; body: string | null; csrf: string | undefined }> = [];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  let creates = 0;
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === returnPath) {
      const payload = { ...base, component: 'CheckoutReturn', url: url.pathname + url.search, props: { ...base.props, orderId } };
      const serialized = JSON.stringify(payload).replaceAll('<', '\\u003c');
      return route.fulfill({ contentType: 'text/html', body: shell.replace(pageScript, (_match, opening, _old, closing) => opening + serialized + closing) });
    }
    if (url.pathname !== path && url.pathname !== `${path}/reconcile`) return route.continue();
    requests.push({ path: url.pathname, method: request.method(), body: request.postData(), csrf: request.headers()['x-csrf-token'] });
    if (request.method() === 'GET') return route.fulfill({ json: { checkout } });
    if (url.pathname === path) {
      if (++creates === 1) return route.abort('failed');
      return route.fulfill({ json: { checkout: { ...checkout, status: 'open', url: checkoutUrl,
        expiresAt: new Date(Date.now() + 60_000).toISOString(), observedAt: new Date().toISOString() } } });
    }
    return route.fulfill({ json: { checkout: { ...checkout, status: 'complete', observedAt: new Date().toISOString() } } });
  });

  await page.goto(`${returnPath}?success=true&payment_status=paid&session_id=cs_live_untrusted`);
  const status = page.getByRole('region', { name: 'Stripe test checkout', exact: true });
  await expect(status.getByRole('button', { name: 'Retry Stripe test checkout', exact: true })).toBeVisible();
  expect(requests.map(request => request.method)).toEqual(['GET']);
  await expect(status.getByRole('button', { name: 'Open Stripe test checkout', exact: true })).toHaveCount(0);
  await expect(status).toContainText('This page does not verify payment, issue a license or grant download access.');
  await expect(page.getByText(/payment successful|payment verified|order paid|license granted|download ready/i)).toHaveCount(0);

  const retry = status.getByRole('button', { name: 'Retry Stripe test checkout', exact: true });
  await retry.focus(); await retry.press('Enter');
  await expect(status.getByRole('alert')).toContainText('The Stripe test checkout result is unconfirmed.');
  await expect(status.getByRole('link', { name: 'Continue to Stripe test checkout', exact: true })).toHaveCount(0);
  await retry.press('Enter');
  const link = status.getByRole('link', { name: 'Continue to Stripe test checkout', exact: true });
  await expect(link).toHaveAttribute('href', checkoutUrl);
  await expect(link).toHaveAttribute('referrerpolicy', 'no-referrer');
  await expect(status).toContainText('Test order total: $42.80 USD');
  expect(requests.filter(request => request.method === 'POST')).toHaveLength(2);
  for (const request of requests.filter(request => request.method === 'POST')) {
    expect(request.path).toBe(path); expect(request.body).toBe('{}'); expect(request.csrf).toBeTruthy();
  }
  await status.getByRole('button', { name: 'Check Stripe test checkout status', exact: true }).click();
  await expect(status).toContainText('Payment has not been verified and fulfillment has not started.');
  await expect(link).toHaveCount(0);
  await expect(status.getByRole('button', { name: 'Retry Stripe test checkout', exact: true })).toHaveCount(0);
  expect(requests.at(-1)).toMatchObject({ path: `${path}/reconcile`, method: 'POST', body: '{}' });
  expect(await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).toBe('{"local":[],"session":[]}');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('test-checkout-return.png'), fullPage: true });
  expect(errors).toEqual([]);
});

/** Synthetic contract states prove UI handling only, not rendering or PDF durability. */
test('contract progress stays read-only and never offers another payment or an unavailable download', async ({ page }, testInfo) => {
  const shellResponse = await page.request.get('/');
  expect(shellResponse.ok()).toBe(true);
  const shell = await shellResponse.text();
  const pageScript = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
  const embedded = shell.match(pageScript);
  expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]);
  const orderId = '75000000-0000-4000-8000-000000000003';
  const path = `/orders/${orderId}/checkout`;
  const returnPath = '/synthetic-contract-status';
  const progress = [
    { contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' },
    { contractStatus: 'attention', fulfillmentStatus: 'blocked' },
    { contractStatus: 'issued', fulfillmentStatus: 'pending_activation' },
  ];
  const requests: string[] = [];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === returnPath) {
      const payload = { ...base, component: 'CheckoutReturn', url: url.pathname, props: { ...base.props, orderId } };
      const serialized = JSON.stringify(payload).replaceAll('<', '\\u003c');
      return route.fulfill({ contentType: 'text/html', body: shell.replace(pageScript, (_match, opening, _old, closing) => opening + serialized + closing) });
    }
    if (url.pathname === `/orders/${orderId}/delivery`) return route.fulfill({ json: { delivery: { deliverySchema: 1, orderId, testOnly: true, status: 'unavailable', items: [], history: [], historyLimit: 20, historyHasMore: false } } });
    if (url.pathname !== path) return route.continue();
    requests.push(request.method());
    return route.fulfill({ json: { checkout: {
      checkoutSchema: 1, orderId, id: '75000000-0000-4000-8000-000000000004', currency: 'USD', totalMinor: 4280,
      status: 'complete', testOnly: true, paymentStatus: 'verified', finalizationStatus: 'paid',
      ...progress[Math.min(requests.length - 1, progress.length - 1)], url: null, expiresAt: null, observedAt: new Date().toISOString(),
    } } });
  });
  await page.goto(returnPath);
  const status = page.getByRole('region', { name: 'Stripe test checkout', exact: true });
  await expect(status).toContainText('Contracts are pending;');
  const refresh = status.getByRole('button', { name: 'Refresh test order status', exact: true });
  await refresh.focus(); await refresh.press('Enter');
  await expect(status).toContainText('Contract preparation needs attention.');
  await expect(status).toContainText('Do not start another payment.');
  await refresh.press('Enter');
  await expect(status).toContainText('Test contracts have been issued. Check the separate test downloads panel');
  await expect(status).toContainText('current availability.');
  await expect(status.getByRole('link')).toHaveCount(0);
  await expect(status.getByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).toHaveCount(0);
  expect(requests).toEqual(['GET', 'GET', 'GET']);
  expect(await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).toBe('{"local":[],"session":[]}');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('test-contract-status.png'), fullPage: true });
  expect(errors).toEqual([]);
});

/** Built shell/navigation and native audio only; synthetic GET status is not payment proof. */
test('private checkout chrome preserves one native preview through keyboard navigation and mobile reflow', async ({ page }, testInfo) => {
  const orderId = '75000000-0000-4000-8000-000000000005';
  const returnPath = `/orders/${orderId}/checkout/return`;
  const path = `/orders/${orderId}/checkout`;
  const track = { ...fixtureTrack, shareUrl: returnPath };
  await storefrontFixture(page, { tracks: [track] });
  const shellResponse = await page.request.get('/');
  expect(shellResponse.ok()).toBe(true);
  const shell = await shellResponse.text();
  const embedded = shell.match(/(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/);
  expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]);
  const reads: string[] = [];
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === returnPath) {
      expect(request.method()).toBe('GET');
      expect(request.headers()['x-inertia']).toBe('true');
      return route.fulfill({ headers: { 'X-Inertia': 'true', Vary: 'Cookie, X-Inertia', 'Cache-Control': 'private, no-store' }, json: {
        ...base, component: 'CheckoutReturn', url: url.pathname + url.search,
        props: { errors: {}, orderId, siteContent: base.props.siteContent },
      } });
    }
    if (url.pathname.startsWith(`/orders/${orderId}/`)) {
      reads.push(`${request.method()} ${url.pathname}`);
      expect(url.pathname).toBe(path);
      expect(request.method()).toBe('GET');
      return route.fulfill({ headers: { 'Cache-Control': 'private, no-store' }, json: { checkout: {
        checkoutSchema: 1, orderId, id: null, currency: 'USD', totalMinor: 4280,
        status: 'not_started', testOnly: true, paymentStatus: 'not_verified', finalizationStatus: 'not_started',
        contractStatus: 'not_started', fulfillmentStatus: 'not_started', url: null, expiresAt: null, observedAt: null,
      } } });
    }
    return route.fallback();
  });
  const mobile = testInfo.project.name === 'webkit-mobile';
  if (mobile) await page.setViewportSize({ width: 320, height: 844 });
  await page.goto('/' + query);
  await expect(page.locator('head link[rel="canonical"]')).toHaveCount(1);
  const article = page.getByRole('article', { name: track.title, exact: true });
  await article.getByRole('button', { name: `Play ${track.title}`, exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.__nativePreviews[0].currentTime)).toBeGreaterThan(0);
  const before = await page.evaluate(() => ({ source: window.__nativePreviews[0].src, time: window.__nativePreviews[0].currentTime }));
  const destination = article.getByRole('link', { name: track.title, exact: true });
  await destination.focus(); await destination.press('Enter');
  await expect(page).toHaveURL(returnPath + query);
  await expect(page.getByRole('heading', { name: 'CHECKOUT STATUS', exact: true })).toBeFocused();
  await expect(page).toHaveTitle('Checkout status — VASEY.AUDIO');
  await expect(page.locator('head meta[name="description"]')).toHaveAttribute('content', 'View the saved test order status for this session. A browser return does not verify payment.');
  await expect(page.locator('head meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow');
  await expect(page.locator('head meta[name="referrer"]')).toHaveAttribute('content', 'no-referrer');
  await expect(page.locator('head link[rel="canonical"], head meta[property^="og:"], head meta[name^="twitter:"]')).toHaveCount(0);
  expect(await page.evaluate(() => document.head.innerHTML)).not.toContain(orderId);
  await expect(page.locator('header .brand-link img')).toHaveAttribute('src', '/brand/vasey-audio-logo.png');
  await expect(page.locator('header .brand-link img')).toHaveAttribute('width', '420');
  await expect(page.locator('header .brand-link img')).toHaveAttribute('height', '100');
  await expect(page.getByRole('navigation', { name: 'Footer navigation', exact: true })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Stripe test checkout', exact: true })).toContainText('This page does not verify payment, issue a license or grant download access.');
  expect(reads).toEqual([`GET ${path}`]);
  await expect.poll(() => page.evaluate(() => window.__nativePreviews[0].currentTime)).toBeGreaterThanOrEqual(before.time);
  expect(await page.evaluate(() => ({ count: window.__nativePreviews.length, source: window.__nativePreviews[0].src, paused: window.__nativePreviews[0].paused })))
    .toEqual({ count: 1, source: before.source, paused: false });
  if (mobile) {
    const menu = page.getByRole('button', { name: /^(Menu|Close menu)$/ });
    await menu.click();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');
    const navigation = page.getByRole('navigation', { name: 'Main navigation', exact: true });
    await expect(navigation).toBeVisible();
    await navigation.getByRole('link').first().focus();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toBeFocused();
  }
  const skip = page.getByRole('link', { name: 'Skip to content', exact: true });
  await skip.focus(); await skip.press('Enter');
  await expect(page.getByRole('main')).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const pause = page.getByRole('button', { name: 'Pause preview', exact: true });
  await pause.focus(); await pause.press('Enter');
  expect(await page.evaluate(() => window.__nativePreviews[0].paused)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('private-checkout-shell.png'), fullPage: true });
  const home = page.getByRole('link', { name: 'Return to the catalog', exact: true });
  await home.focus(); await home.press('Enter');
  await expect(page).toHaveURL('/');
  await expect(page.locator('#catalog-title')).toBeFocused();
  await expect(page.locator('head link[rel="canonical"]')).toHaveCount(1);
  await expect(page.locator('head meta[name="referrer"]')).toHaveCount(0);
  expect(await page.evaluate(() => ({ count: window.__nativePreviews.length, source: window.__nativePreviews[0].src, paused: window.__nativePreviews[0].paused })))
    .toEqual({ count: 1, source: before.source, paused: true });
  expect(reads).toEqual([`GET ${path}`]);
  expect(errors).toEqual([]);
});
