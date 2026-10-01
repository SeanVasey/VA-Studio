import { expect, test, type Page } from '@playwright/test';

const receipt = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const privacyNotice = 'Synthetic approved privacy notice. Inquiries are saved privately for review.';
const values = { name: 'Synthetic inquiry visitor', email: 'inquiry-visitor@example.test', subject: 'Synthetic inquiry subject', message: 'Synthetic private message.\nKeep this exact draft.' };

/** Real built UI/native browser; contact HTTP transport is synthetic. PHP tests prove persistence/security. */
async function inquiryFixture(page: Page, options: { enabled?: boolean; preview?: boolean; section?: 'contact' | 'about' } = {}) {
  const response = await page.request.get('/'); expect(response.ok()).toBe(true);
  const shell = await response.text();
  const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
  const embedded = shell.match(pattern); expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]);
  await page.route('**/contact', async route => {
    if (route.request().method() !== 'GET') return route.continue();
    const payload = { ...base, component: 'Editorial', url: '/contact', props: { ...base.props,
      sitePreview: options.preview ?? false,
      sitePreviewBase: options.preview ? '/admin/site-releases/777/preview' : null,
      contactInquiryEnabled: options.enabled ?? true,
      contactInquiryPrivacyNotice: privacyNotice,
      editorial: { section: options.section ?? 'contact', kind: 'page', title: 'Synthetic contact', description: 'Synthetic public contact page.', paragraphs: [], entries: [], email: 'synthetic@example.test', contactHref: 'mailto:synthetic%40example.test' },
      metadata: { ...base.props.metadata, title: 'Synthetic contact — VASEY.AUDIO', canonicalUrl: 'http://127.0.0.1:8173/contact', type: 'website' },
    } };
    if (route.request().headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true', Vary: 'X-Inertia' }, json: payload });
    const json = JSON.stringify(payload).replaceAll('<', '\\u003c');
    return route.fulfill({ contentType: 'text/html', body: shell.replace(pattern, (_match, start, _page, end) => start + json + end) });
  });
}

async function fill(page: Page) {
  for (const [name, value] of Object.entries(values)) await page.getByLabel(new RegExp(`^${name}`, 'i')).fill(value);
}

for (const [description, options] of [
  ['disabled contact', { enabled: false }],
  ['private contact preview', { preview: true }],
  ['another editorial section', { section: 'about' as const }],
] as const) {
  test(`inquiry stays absent for ${description}`, async ({ page }) => {
    const requests: string[] = [];
    page.on('request', request => { if (request.url().includes('/contact/inquiries')) requests.push(request.url()); });
    await inquiryFixture(page, options); await page.goto('/contact');
    await expect(page.getByRole('heading', { name: 'Synthetic contact', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send inquiry', exact: true })).toHaveCount(0);
    await expect(page.getByRole('textbox')).toHaveCount(0); expect(requests).toEqual([]);
    if (!('preview' in options)) await expect(page.getByRole('link', { name: /Open email/ })).toHaveAttribute('href', 'mailto:synthetic%40example.test');
  });
}

test('keyboard validation and uncertain retry preserve a private inquiry on desktop and mobile', async ({ page }, testInfo) => {
  const errors: string[] = []; const attempts: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => {
    const original = Storage.prototype.setItem;
    (window as unknown as { inquiryStorageWrites: string[] }).inquiryStorageWrites = [];
    Storage.prototype.setItem = function (key: string, value: string) {
      (window as unknown as { inquiryStorageWrites: string[] }).inquiryStorageWrites.push(`${key}:${value}`);
      return original.call(this, key, value);
    };
  });
  await inquiryFixture(page);
  await page.route('**/contact/inquiries', async route => {
    expect(route.request().method()).toBe('POST');
    attempts.push(route.request().postData()!);
    if (attempts.length === 1) return route.abort('failed');
    return route.fulfill({ status: 200, headers: { 'Cache-Control': 'private, no-store' }, json: { state: 'saved', receipt } });
  });
  await page.goto('/contact');
  await expect(page.getByText(privacyNotice, { exact: true })).toBeVisible();
  const send = page.getByRole('button', { name: 'Send inquiry', exact: true });
  await send.focus(); await page.keyboard.press('Enter');
  await expect(page.getByRole('alert')).toBeFocused();
  await page.getByRole('link', { name: 'Enter your email address.', exact: true }).focus();
  await page.keyboard.press('Enter'); await expect(page.getByLabel(/^Email/)).toBeFocused();
  await fill(page);
  for (const label of ['Name', 'Email', 'Subject', 'Message']) {
    const bounds = await page.getByLabel(new RegExp(`^${label}`)).boundingBox(); expect(bounds!.height).toBeGreaterThanOrEqual(44);
  }
  await send.focus(); await page.keyboard.press('Enter');
  await expect(page.getByRole('alert')).toBeFocused();
  await expect(page.getByRole('alert')).toContainText('could not confirm whether your inquiry was saved');
  await expect(page.getByLabel(/^Message/)).toHaveAttribute('readonly', '');
  await expect(page.getByLabel(/^Message/)).toHaveValue(values.message);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('contact-inquiry-uncertain.png'), fullPage: true });
  await page.getByRole('button', { name: 'Retry same inquiry', exact: true }).focus(); await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Inquiry saved', exact: true })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Contact inquiry', exact: true }).getByRole('status')).toBeFocused(); await expect(page.getByText(receipt, { exact: true })).toBeVisible();
  expect(attempts).toHaveLength(2); expect(attempts[0]).toBe(attempts[1]);
  expect(JSON.parse(attempts[0])).toEqual({ ...values, website: '', requestKey: expect.stringMatching(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/) });
  const writes = await page.evaluate(() => (window as unknown as { inquiryStorageWrites: string[] }).inquiryStorageWrites);
  for (const write of writes) for (const value of Object.values(values)) expect(write).not.toContain(value);
  await expect(page.getByRole('link', { name: /Open email/ })).toHaveAttribute('href', 'mailto:synthetic%40example.test');
  await page.getByRole('button', { name: 'Write another inquiry', exact: true }).click();
  await expect(page.getByLabel(/^Name/)).toBeFocused(); await expect(page.getByLabel(/^Message/)).toHaveValue('');
  expect(errors).toEqual([]);
});
