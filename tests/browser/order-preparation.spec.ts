import { test, expect, type Page } from '@playwright/test';
import { fixtureTrack, policyText, query, storefrontFixture } from './storefront-fixture';

/** Synthetic HTTP transport exercises the built React UI and native browser controls.
 * PHP feature/race tests, not these responses, prove order persistence and authorization.
 */
test('full test-order assent and an uncertain response retain one request without saving buyer identity', async ({ page }, testInfo) => {
  await storefrontFixture(page, { testOrderPreparationEnabled: true });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  const offer = fixtureTrack.offers[0];
  const quoteId = '72000000-0000-4000-8000-000000000001';
  const pricingId = '72000000-0000-4000-8000-000000000002';
  const orderId = '72000000-0000-4000-8000-000000000003';
  const expiresAt = new Date(Date.now() + 15 * 60_000).toISOString();
  const reviewHash = 'a'.repeat(64), pricingHash = 'b'.repeat(64), disclosureHash = 'c'.repeat(64);
  const buyer = { legalName: 'Synthetic Browser Buyer Privacy Marker', email: 'browser-buyer-privacy@example.invalid' };
  const assentText = 'I explicitly accept these synthetic test terms. No purchase or rights grant occurs.';
  const features = ['NONBINDING browser fixture. No real rights or payment obligation.'];
  const licenseUrl = `/quotes/${quoteId}/offers/${offer.offerRevisionId}/license`;
  const disclosure = { disclosureSchema: 1, quoteId, expiresAt, offerId: offer.id,
    offerRevisionId: offer.offerRevisionId, licenseVersionId: offer.licenseVersionId,
    name: offer.licenseName, version: 1, type: 'non-exclusive', features,
    deliverableRoles: offer.deliverableRoles, termsText: policyText(0), disclosureHash };
  const pricing = { pricingSchema: 1, id: pricingId, quoteId, expiresAt, currency: 'USD',
    subtotalMinor: 1000, discountMinor: 0, taxBasisMinor: 1000, taxMinor: 75, totalMinor: 1075,
    taxStatus: 'fixed_test', payable: false, testOnly: true, pricingHash,
    policy: { key: 'synthetic-browser-tax', version: 1, hash: 'd'.repeat(64) },
    items: [{ offerRevisionId: offer.offerRevisionId, quantity: 1, baseMinor: 1000, discountMinor: 0,
      taxBasisMinor: 1000, taxMinor: 75, totalMinor: 1075, disclosureHash }] };
  const review = { reviewSchema: 1, quoteId, expiresAt, pricing, sellerName: 'Synthetic Browser Seller',
    policyVersion: 'SYNTHETIC-BROWSER-ORDER-1', assent: { version: 'SYNTHETIC-BROWSER-ASSENT-1', text: assentText },
    items: [{ offerRevisionId: offer.offerRevisionId, title: fixtureTrack.title, licenseName: offer.licenseName, disclosure }],
    testOnly: true, payable: false, reviewHash };
  const order = { orderSchema: 1, id: orderId, quoteId, pricingId, reviewHash, createdAt: new Date().toISOString(),
    status: 'prepared', paymentStatus: 'not_started', testOnly: true, payable: false, currency: 'USD', totalMinor: 1075 };
  const submissions: Array<{ body: string; key: string | undefined; csrf: string | undefined }> = [];
  const pricingMethods: string[] = [];
  await page.route('**/quotes**', async route => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (path === '/quotes' && request.method() === 'POST') {
      return route.fulfill({ json: { quote: { id: quoteId, expiresAt, currency: 'USD', subtotalMinor: 1000,
        taxMinor: null, totalMinor: null, taxStatus: 'unresolved', payable: false,
        items: [{ trackId: fixtureTrack.id, offerId: offer.id, offerRevisionId: offer.offerRevisionId,
          licenseVersionId: offer.licenseVersionId, title: fixtureTrack.title, artist: fixtureTrack.artist,
          licenseName: offer.licenseName, priceMinor: 1000, currency: 'USD', deliverableRoles: offer.deliverableRoles, features, licenseUrl }] } } });
    }
    if (path === `/quotes/${quoteId}/pricing`) {
      pricingMethods.push(request.method()); return route.fulfill({ json: { pricing } });
    }
    if (path === `/quotes/${quoteId}/order-review`) return route.fulfill({ json: { review } });
    if (path === licenseUrl) return route.fulfill({ json: disclosure });
    if (path === `/quotes/${quoteId}/order`) {
      return submissions.length === 0
        ? route.fulfill({ status: 404, json: { code: 'ORDER_NOT_FOUND' } })
        : route.fulfill({ json: { order } });
    }
    return route.fallback();
  });
  await page.route('**/orders', async route => {
    const request = route.request();
    submissions.push({ body: request.postData() ?? '', key: request.headers()['idempotency-key'], csrf: request.headers()['x-csrf-token'] });
    // The outcome is unknown to the browser. Its next command must preserve both body and key.
    if (submissions.length === 1) return route.abort('failed');
    return route.fulfill({ json: { order } });
  });
  await page.route(`**/orders/${orderId}/checkout`, route => route.fulfill({ json: { checkout: {
    checkoutSchema: 1, orderId, id: null, currency: 'USD', totalMinor: 1075, status: 'not_started',
    testOnly: true, paymentStatus: 'not_verified', fulfillmentStatus: 'not_started', url: null, expiresAt: null, observedAt: null,
  } } }));

  await page.goto(fixtureTrack.shareUrl + query);
  await page.getByRole('button', { name: 'Read terms & choose', exact: true }).first().click();
  await page.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.' }).getByRole('button', { name: /^Add license/ }).click();
  await page.getByRole('button', { name: 'Open cart, 1 item', exact: true }).click();
  const cart = page.getByRole('dialog', { name: 'YOUR SELECTIONS.' });
  await cart.getByRole('button', { name: 'Review selection', exact: true }).click();
  await cart.getByRole('button', { name: 'Review test order', exact: true }).click();
  const preparation = cart.getByRole('region', { name: 'Test order preparation', exact: true });
  const terms = preparation.getByRole('region', { name: `${fixtureTrack.title} full license text`, exact: true });
  await expect(terms).toHaveText(policyText(0));
  await expect(page.locator('#policy-injection')).toHaveCount(0);
  await terms.focus(); await expect(terms).toBeFocused();
  await expect.poll(() => terms.evaluate(element => element.scrollHeight - element.clientHeight)).toBeGreaterThan(0);
  await terms.press('End');
  await expect.poll(() => terms.evaluate(element => Math.abs(element.scrollHeight - element.clientHeight - element.scrollTop))).toBeLessThanOrEqual(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(await cart.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
  const submit = preparation.getByRole('button', { name: 'Prepare test order', exact: true });
  const assent = preparation.getByRole('checkbox', { name: assentText, exact: true });
  await expect(assent).not.toBeChecked(); await expect(submit).toBeDisabled();
  await preparation.getByRole('textbox', { name: 'Legal name', exact: true }).fill(buyer.legalName);
  await preparation.getByRole('textbox', { name: 'Email address', exact: true }).fill(buyer.email);
  await expect(submit).toBeDisabled();
  await assent.focus(); await assent.press('Space'); await expect(assent).toBeChecked();
  await expect(submit).toBeEnabled(); await submit.focus(); await submit.press('Enter');
  await expect(preparation.getByRole('alert')).toContainText('The result is unconfirmed.');
  await expect(preparation.getByRole('textbox', { name: 'Legal name', exact: true })).toBeDisabled();
  await expect(preparation.getByRole('textbox', { name: 'Email address', exact: true })).toBeDisabled();
  expect(submissions).toHaveLength(1);
  await expectNoSavedBuyer(page, buyer, submissions[0].key);
  await preparation.getByRole('button', { name: 'Retry same test order', exact: true }).press('Enter');
  await expect(preparation.getByRole('heading', { name: 'TEST ORDER PREPARED', exact: true })).toBeVisible();
  await expect(preparation).toContainText('This prepared record does not confirm payment or grant download access or usage rights.');
  await expect(preparation).toContainText('Prepared total: $10.75 USD');
  await expect(preparation.getByRole('textbox')).toHaveCount(0);
  expect(submissions).toHaveLength(2);
  expect(submissions[1].body).toBe(submissions[0].body);
  expect(submissions[1].key).toBe(submissions[0].key);
  expect(submissions[0].key).toMatch(/^[a-f0-9-]{36}$/);
  expect(submissions[0].csrf).toBeTruthy();
  expect(JSON.parse(submissions[0].body)).toEqual({ quoteId, reviewHash, buyer, accepted: true });
  expect(pricingMethods).toEqual(['GET']);
  await expectNoSavedBuyer(page, buyer, submissions[0].key);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(await cart.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('test-order-prepared.png'), fullPage: false });
  expect(errors).toEqual([]);
});

async function expectNoSavedBuyer(page: Page, buyer: { legalName: string; email: string }, orderKey: string | undefined) {
  const saved = await page.evaluate(() => JSON.stringify({ local: Object.entries(localStorage), session: Object.entries(sessionStorage) }));
  expect(saved).not.toContain(buyer.legalName); expect(saved).not.toContain(buyer.email);
  if (orderKey) expect(saved).not.toContain(orderKey);
}
