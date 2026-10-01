import { expect, test, type Page } from '@playwright/test';
import { fixtureOperation } from './contact-inquiry-fixture';

const receipt = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const privacyNotice = 'Synthetic browser privacy notice. Inquiries are saved privately for verification.';
const values = { name: 'Synthetic inquiry visitor', email: 'inquiry-visitor@example.test', subject: 'Synthetic inquiry subject', message: 'Synthetic private message.\nKeep this exact draft.' };
const requestKeyPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
let prepared = false;
let expectedRotations = 0;

test.beforeEach(({}, testInfo) => {
  prepared = false;
  expectedRotations = 0;
  fixtureOperation('transport-prepare', testInfo.project.name);
  prepared = true;
});
test.afterEach(async ({}, testInfo) => {
  if (!prepared) return;
  try {
    const restored = fixtureOperation('transport-restore', testInfo.project.name);
    expect(restored).toEqual({ restored: true, unchangedInquiryGraph: true, unchangedCommerceCounts: true,
      unchangedOriginalRowsAndAudits: true, expectedSitePublicationAudits: true, rotations: expectedRotations,
      inquiryGraphHash: expect.stringMatching(/^[a-f0-9]{64}$/), retainedRowsHash: expect.stringMatching(/^[a-f0-9]{64}$/) });
    await testInfo.attach('unchanged-real-inquiry-and-publication-evidence', { body: Buffer.from(JSON.stringify(restored)), contentType: 'application/json' });
  } finally { prepared = false; }
});

async function contactSource(page: Page) {
  const response = await page.request.get('/contact'); expect(response.status()).toBe(200);
  const shell = await response.text();
  const pattern = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
  const embedded = shell.match(pattern); expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]);
  expect(base.component).toBe('Editorial');
  expect(base.props.contactInquiryEnabled).toBe(true);
  expect(base.props.contactInquiryPrivacyNotice).toBe(privacyNotice);
  expect(base.props.contactInquiryNoticeToken).toMatch(/^[0-9a-f]{64}$/);
  return { shell, pattern, base, noticeToken: base.props.contactInquiryNoticeToken as string };
}

/** Real built UI/native browser; contact HTTP transport is synthetic. PHP tests prove persistence/security. */
async function inquiryFixture(page: Page, options: { enabled?: boolean; preview?: boolean; section?: 'contact' | 'about' } = {}) {
  let source = await contactSource(page);
  await page.route('**/contact', async route => {
    if (route.request().method() !== 'GET') return route.continue();
    const { shell, pattern, base } = source;
    const enabled = (options.enabled ?? true) && !options.preview && (options.section ?? 'contact') === 'contact';
    const payload = { ...base, component: 'Editorial', url: '/contact', props: { ...base.props,
      sitePreview: options.preview ?? false,
      sitePreviewBase: options.preview ? '/admin/site-releases/777/preview' : null,
      contactInquiryEnabled: options.enabled ?? true,
      contactInquiryPrivacyNotice: privacyNotice,
      contactInquiryNoticeToken: enabled ? source.noticeToken : null,
      editorial: { section: options.section ?? 'contact', kind: 'page', title: 'Synthetic contact', description: 'Synthetic public contact page.', paragraphs: [], entries: [], email: 'synthetic@example.test', contactHref: 'mailto:synthetic%40example.test' },
      metadata: { ...base.props.metadata, title: 'Synthetic contact — VASEY.AUDIO', canonicalUrl: 'http://127.0.0.1:8173/contact', type: 'website' },
    } };
    if (route.request().headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true', Vary: 'X-Inertia' }, json: payload });
    const json = JSON.stringify(payload).replaceAll('<', '\\u003c');
    return route.fulfill({ contentType: 'text/html', body: shell.replace(pattern, (_match, start, _page, end) => start + json + end) });
  });
  return {
    get noticeToken() { return source.noticeToken; },
    async rotate(project: string) {
      expect(fixtureOperation('transport-rotate', project)).toEqual({ rotated: true });
      expectedRotations = 1;
      source = await contactSource(page);
      return source.noticeToken;
    },
  };
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
  const fixture = await inquiryFixture(page);
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
  expect(JSON.parse(attempts[0])).toEqual({ ...values, website: '', requestKey: expect.stringMatching(requestKeyPattern), noticeToken: fixture.noticeToken });
  const writes = await page.evaluate(() => (window as unknown as { inquiryStorageWrites: string[] }).inquiryStorageWrites);
  for (const write of writes) for (const value of Object.values(values)) expect(write).not.toContain(value);
  for (const write of writes) for (const value of [fixture.noticeToken, JSON.parse(attempts[0]).requestKey]) expect(write).not.toContain(value);
  await expect(page.getByRole('link', { name: /Open email/ })).toHaveAttribute('href', 'mailto:synthetic%40example.test');
  await page.getByRole('button', { name: 'Write another inquiry', exact: true }).click();
  await expect(page.getByLabel(/^Name/)).toBeFocused(); await expect(page.getByLabel(/^Message/)).toHaveValue('');
  expect(errors).toEqual([]);
});

for (const hidden of ['website', 'requestKey'] as const) {
  test(`first definitive ${hidden} rejection keeps an editable draft and starts a new request`, async ({ page }) => {
    const attempts: string[] = [];
    const trappedWebsite = hidden === 'website' ? 'https://synthetic-autofill.example.test' : '';
    const fixture = await inquiryFixture(page);
    await page.route('**/contact/inquiries', async route => {
      attempts.push(route.request().postData()!);
      if (attempts.length === 1) return route.fulfill({ status: 422, json: { code: 'INQUIRY_VALIDATION_FAILED', errors: { [hidden]: ['Synthetic private server detail must not be displayed.'] } } });
      return route.fulfill({ status: 201, json: { state: 'saved', receipt } });
    });
    await page.goto('/contact'); await fill(page);
    if (hidden === 'website') {
      // Simulate autofill in the inaccessible honeypot through the native input setter and event.
      await page.locator('input[name="website"]').evaluate((element, value) => {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        if (!setter) throw new Error('Native input setter is unavailable.');
        setter.call(element, value);
        element.dispatchEvent(new Event('input', { bubbles: true }));
      }, trappedWebsite);
      await expect(page.locator('input[name="website"]')).toHaveValue(trappedWebsite);
    }
    await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
    await expect(page.getByRole('alert')).toBeFocused();
    await expect(page.getByRole('alert')).toContainText('Check your inquiry before sending again.');
    await expect(page.getByRole('alert')).not.toContainText('Synthetic private server detail');
    await expect(page.locator('input[name="website"]')).toHaveValue('');
    for (const [field, value] of Object.entries(values)) {
      const input = page.getByLabel(new RegExp(`^${field}`, 'i'));
      await expect(input).toHaveValue(value); await expect(input).not.toHaveAttribute('readonly', '');
    }
    const subject = `${values.subject} corrected`;
    await page.getByLabel(/^Subject/).fill(subject);
    await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Inquiry saved', exact: true })).toBeVisible();
    expect(attempts).toHaveLength(2);
    const original = JSON.parse(attempts[0]); const corrected = JSON.parse(attempts[1]);
    expect(original).toEqual({ ...values, website: trappedWebsite, requestKey: expect.stringMatching(requestKeyPattern), noticeToken: fixture.noticeToken });
    expect(corrected).toEqual({ ...values, subject, website: '', requestKey: expect.stringMatching(requestKeyPattern), noticeToken: fixture.noticeToken });
    expect(corrected.requestKey).not.toBe(original.requestKey);
  });
}

test('hidden rejections after uncertainty retain the exact original body, key and notice token', async ({ page }) => {
  const attempts: string[] = [];
  const fixture = await inquiryFixture(page);
  const hidden = ['website', 'requestKey', 'noticeToken'];
  await page.route('**/contact/inquiries', async route => {
    attempts.push(route.request().postData()!);
    if (attempts.length === 1) return route.abort('failed');
    const field = hidden[attempts.length - 2];
    if (field) return route.fulfill({ status: 422, json: { code: 'INQUIRY_VALIDATION_FAILED', errors: { [field]: ['Synthetic private server detail must not be displayed.'] } } });
    return route.fulfill({ status: 200, json: { state: 'saved', receipt } });
  });
  await page.goto('/contact'); await fill(page);
  await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
  for (let retry = 0; retry <= hidden.length; retry++) {
    await expect(page.getByRole('alert')).toBeFocused();
    await expect(page.getByRole('alert')).toContainText('could not confirm whether your inquiry was saved');
    await expect(page.getByRole('alert')).not.toContainText('Synthetic private server detail');
    await expect(page.getByRole('link', { name: 'Refresh contact to review the current privacy notice', exact: true })).toHaveCount(0);
    await expect(page.getByText(privacyNotice, { exact: true })).toBeVisible();
    for (const [field, value] of Object.entries(values)) {
      await expect(page.getByLabel(new RegExp(`^${field}`, 'i'))).toHaveValue(value);
      await expect(page.getByLabel(new RegExp(`^${field}`, 'i'))).toHaveAttribute('readonly', '');
    }
    const response = page.waitForResponse(result => new URL(result.url()).pathname === '/contact/inquiries' && result.request().method() === 'POST');
    await page.getByRole('button', { name: 'Retry same inquiry', exact: true }).click();
    expect((await response).status()).toBe(retry < hidden.length ? 422 : 200);
  }
  await expect(page.getByRole('heading', { name: 'Inquiry saved', exact: true })).toBeVisible();
  expect(attempts).toHaveLength(5);
  expect(new Set(attempts).size).toBe(1);
  expect(JSON.parse(attempts[0])).toEqual({ ...values, website: '', requestKey: expect.stringMatching(requestKeyPattern), noticeToken: fixture.noticeToken });
});

test('stale notice keeps the editable draft until an explicit refresh and a new reviewed attempt', async ({ page }, testInfo) => {
  const attempts: string[] = [];
  const fixture = await inquiryFixture(page);
  const originalToken = fixture.noticeToken;
  await page.route('**/contact/inquiries', async route => {
    attempts.push(route.request().postData()!);
    if (attempts.length === 1) return route.fulfill({ status: 422, json: { code: 'INQUIRY_VALIDATION_FAILED', errors: { noticeToken: ['Refresh contact to review the current privacy notice before sending.'] } } });
    return route.fulfill({ status: 201, json: { state: 'saved', receipt } });
  });
  await page.goto('/contact'); await fill(page);
  const freshToken = await fixture.rotate(testInfo.project.name);
  expect(freshToken).not.toBe(originalToken);
  await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
  await expect(page.getByRole('alert')).toBeFocused();
  await expect(page.getByRole('alert')).toContainText('Your inquiry was not saved. Copy your draft before refreshing contact to review the current privacy notice.');
  for (const [field, value] of Object.entries(values)) {
    await expect(page.getByLabel(new RegExp(`^${field}`, 'i'))).toHaveValue(value);
    await expect(page.getByLabel(new RegExp(`^${field}`, 'i'))).not.toHaveAttribute('readonly', '');
  }
  const revisedMessage = `${values.message}\nSynthetic copied correction before refreshing.`;
  await page.getByLabel(/^Message/).fill(revisedMessage);
  await expect(page.getByLabel(/^Message/)).toHaveValue(revisedMessage);
  await expect(page.getByRole('button', { name: 'Send inquiry', exact: true })).toBeDisabled();
  await page.getByLabel(/^Subject/).press('Enter');
  expect(attempts).toHaveLength(1);
  const refresh = page.getByRole('link', { name: 'Refresh contact to review the current privacy notice', exact: true });
  await expect(refresh).toHaveAttribute('href', '/contact');
  const refreshed = page.waitForResponse(response => new URL(response.url()).pathname === '/contact' && response.request().method() === 'GET');
  await refresh.click();
  const refreshedResponse = await refreshed;
  const embedded = (await refreshedResponse.text()).match(/<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
  expect(embedded).not.toBeNull();
  expect(JSON.parse(embedded![1]).props.contactInquiryNoticeToken).toBe(freshToken);
  await expect(page.getByText(privacyNotice, { exact: true })).toBeVisible();
  await expect(page.getByLabel(/^Message/)).toHaveValue('');
  await fill(page); await page.getByLabel(/^Message/).fill(revisedMessage);
  await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Inquiry saved', exact: true })).toBeVisible();
  expect(attempts).toHaveLength(2);
  const original = JSON.parse(attempts[0]); const reviewed = JSON.parse(attempts[1]);
  expect(original).toEqual({ ...values, website: '', requestKey: expect.stringMatching(requestKeyPattern), noticeToken: originalToken });
  expect(reviewed).toEqual({ ...values, message: revisedMessage, website: '', requestKey: expect.stringMatching(requestKeyPattern), noticeToken: freshToken });
  expect(reviewed.requestKey).not.toBe(original.requestKey);
});
