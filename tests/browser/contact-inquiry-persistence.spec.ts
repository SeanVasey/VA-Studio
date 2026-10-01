import { createHash } from 'node:crypto';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { fixtureOperation, type InquiryFixture as Fixture } from './contact-inquiry-fixture';

async function confirm(page: Page, label: 'Mark read' | 'Archive') {
  await page.getByRole('button', { name: label, exact: true }).click();
  const persisted = page.waitForResponse(response => {
    if (response.request().method() !== 'POST' || ! new URL(response.url()).pathname.endsWith('/update')) return false;
    try {
      const body = JSON.parse(response.request().postData()!) as { components?: { calls?: { method?: string }[] }[] };
      return body.components?.some(component => component.calls?.some(call => call.method === 'callMountedAction')) ?? false;
    } catch { return false; }
  });
  await page.getByRole('alertdialog', { name: label, exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
  expect((await persisted).status()).toBe(200);
  await expect(page.getByRole('alertdialog', { name: label, exact: true })).not.toBeVisible();
}

test('real customer inquiry persists encrypted and ordinary staff can read and archive it', async ({ page, browser }, testInfo) => {
  test.setTimeout(120_000);
  resetBrowserLoginRateLimit();
  const fixture: Fixture = fixtureOperation('prepare', testInfo.project.name);
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  let staff: BrowserContext | undefined;
  try {
    staff = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
    const contact = await page.goto('/contact');
    expect(contact?.status()).toBe(200);
    const embedded = (await contact!.text()).match(/<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
    expect(embedded).not.toBeNull();
    const contactProps = JSON.parse(embedded![1]).props;
    expect(contactProps.contactInquiryEnabled).toBe(true);
    expect(contactProps.contactInquiryPrivacyNotice).toBe(fixture.privacyNotice);
    const noticeToken = contactProps.contactInquiryNoticeToken;
    expect(noticeToken).toMatch(/^[0-9a-f]{64}$/);
    await expect(page.getByRole('heading', { name: `SYNTHETIC INQUIRY ${testInfo.project.name} CONTACT`, exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: /Open email/ })).toHaveAttribute('href', 'mailto:editorial%2Bsynthetic%40example.test');
    await expect(page.getByText(fixture.privacyNotice, { exact: true })).toBeVisible();
    for (const label of ['Name', 'Email', 'Subject', 'Message'] as const) {
      await page.getByLabel(new RegExp(`^${label}`)).fill(fixture.values[label.toLowerCase() as keyof Fixture['values']]);
    }
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(csrf).toBeTruthy();
    // The real form prefers the current cookie so another tab can renew a pending inquiry's token.
    const xsrf = await page.evaluate(() => {
      const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('XSRF-TOKEN='));
      return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : null;
    });
    expect(xsrf).toBeTruthy();
    const submission = page.waitForResponse(response => response.url().endsWith('/contact/inquiries') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
    const saved = await submission;
    expect(saved.status()).toBe(201);
    expect(saved.headers()['cache-control']).toContain('no-store');
    expect(await saved.request().headerValue('x-xsrf-token')).toBe(xsrf);
    expect(await saved.request().headerValue('x-csrf-token')).toBeNull();
    const body = saved.request().postData()!;
    expect(JSON.parse(body)).toEqual({ ...fixture.values, requestKey: expect.stringMatching(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/), noticeToken });
    const savedBody = await saved.json();
    const { receipt } = savedBody;
    expect(receipt).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(savedBody).toEqual({ state: 'saved', receipt });
    await expect(page.getByRole('heading', { name: 'Inquiry saved', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Contact inquiry', exact: true }).getByRole('status')).toBeFocused();
    await expect(page.getByText(receipt, { exact: true })).toBeVisible();
    const verified = fixtureOperation('verify', testInfo.project.name, receipt, 'new', body);
    expect(verified).toEqual({ state: 'new', version: 0, exactEncryptedInput: true, issuedNoticeToken: true, rawNoticeTokenRetained: false, audited: true });
    await testInfo.attach('real-issued-notice-and-encrypted-five-field-evidence', {
      body: Buffer.from(JSON.stringify({ ...verified, noticeTokenHash: createHash('sha256').update(noticeToken).digest('hex') })), contentType: 'application/json',
    });
    // API requests have no browser fetch-metadata bypass: missing/invalid tokens must fail before replay.
    const rejectedHeaders: Record<string, string>[] = [{}, { 'X-XSRF-TOKEN': 'synthetic-invalid-xsrf' }];
    for (const headers of rejectedHeaders) {
      const rejected = await page.request.post('/contact/inquiries', { data: body, headers: {
        'Content-Type': 'application/json', Accept: 'application/json', Origin: 'http://127.0.0.1:8173', ...headers,
      } });
      expect(rejected.status()).toBe(419);
      expect(rejected.headers()['cache-control']).toContain('no-store');
      expect(await rejected.json()).toEqual({ code: 'INQUIRY_REQUEST_EXPIRED', message: 'Your session expired. Refresh the page before trying again.' });
      fixtureOperation('verify', testInfo.project.name, receipt, 'new', body);
    }
    // A real same-session HTTP replay must retain one receipt and one received audit.
    const replay = await page.request.post('/contact/inquiries', { data: body, headers: {
      'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf!, Origin: 'http://127.0.0.1:8173',
    } });
    expect(replay.status()).toBe(200);
    expect(await replay.json()).toEqual({ state: 'saved', receipt });
    fixtureOperation('verify', testInfo.project.name, receipt, 'new', body);
    const stored = await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n'));
    for (const value of Object.values(fixture.values).filter(Boolean)) expect(stored).not.toContain(value);
    expect(stored).not.toContain(noticeToken);
    expect(stored).not.toContain(JSON.parse(body).requestKey);
    await page.screenshot({ path: testInfo.outputPath('real-inquiry-customer-receipt.png'), fullPage: true });
    const denied = await page.request.get(`/admin/customer-inquiries/${receipt}`, { maxRedirects: 0 });
    expect(denied.status()).toBe(302);
    expect(denied.headers()['cache-control']).toContain('no-store');
    expect(await denied.text()).not.toContain(fixture.values.email);

    const operator = await staff.newPage();
    operator.on('pageerror', error => errors.push(error.message));
    await operator.goto('/admin/login');
    await operator.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
    await operator.getByLabel('Password', { exact: false }).and(operator.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
    await operator.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(operator).toHaveURL(/\/admin$/);
    const inbox = await operator.goto('/admin/customer-inquiries');
    expect(inbox?.status()).toBe(200);
    expect(inbox?.headers()['cache-control']).toContain('no-store');
    await expect(operator.getByText(fixture.values.subject, { exact: true })).toBeVisible();
    const view = operator.getByRole('row').filter({ hasText: receipt }).getByRole('link', { name: 'View', exact: true });
    await expect(view).toHaveAttribute('href', `http://127.0.0.1:8173/admin/customer-inquiries/${receipt}`);
    await view.click();
    await expect(operator).toHaveURL(new RegExp(`/admin/customer-inquiries/${receipt}$`));
    await expect(operator.getByText(fixture.values.email, { exact: true })).toBeVisible();
    await expect(operator.getByText(fixture.values.message, { exact: true })).toBeVisible();
    await confirm(operator, 'Mark read');
    await expect(operator.getByRole('button', { name: 'Mark read', exact: true })).toHaveCount(0);
    await expect(operator.getByText('read', { exact: true })).toBeVisible();
    fixtureOperation('verify', testInfo.project.name, receipt, 'read', body);
    await confirm(operator, 'Archive');
    await expect(operator.getByRole('button', { name: 'Archive', exact: true })).toHaveCount(0);
    await expect(operator.getByText('archived', { exact: true })).toBeVisible();
    fixtureOperation('verify', testInfo.project.name, receipt, 'archived', body);
    await operator.reload();
    await expect(operator.getByText('archived', { exact: true })).toBeVisible();
    await expect(operator.getByText(fixture.values.message, { exact: true })).toBeVisible();
    await expect(operator.getByRole('button', { name: 'Archive', exact: true })).toHaveCount(0);
    fixtureOperation('verify', testInfo.project.name, receipt, 'archived', body);
    await operator.screenshot({ path: testInfo.outputPath('real-inquiry-operator-archived.png'), fullPage: true });
    await operator.goto('/admin/customer-inquiries');
    await expect(operator.getByText(receipt, { exact: true })).toHaveCount(0);
    const preview = await operator.goto(fixture.previewPath);
    expect(preview?.status()).toBe(200);
    expect(preview?.headers()['cache-control']).toContain('no-store');
    await expect(operator.getByRole('heading', { name: /PRIVATE SYNTHETIC INQUIRY .* CONTACT/ })).toBeVisible();
    await expect(operator.getByRole('button', { name: 'Send inquiry', exact: true })).toHaveCount(0);
    await expect(operator.getByRole('region', { name: 'Contact inquiry', exact: true })).toHaveCount(0);
    await expect(operator.getByText(fixture.privacyNotice, { exact: true })).toHaveCount(0);
    const previewText = await operator.locator('body').innerText();
    for (const value of Object.values(fixture.values).filter(Boolean)) expect(previewText).not.toContain(value);
    await operator.screenshot({ path: testInfo.outputPath('real-inquiry-private-preview-disabled.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally {
    // Restore even when closing the independently authenticated browser context fails.
    try { await staff?.close(); } finally { fixtureOperation('restore', testInfo.project.name); }
  }
});
