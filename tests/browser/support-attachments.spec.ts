import { expect, test } from '@playwright/test';
import { fixtureOperation, type InquiryFixture } from './contact-inquiry-fixture';

test.use({ serviceWorkers: 'block' });

// Explicit disposable rehearsal only. Root owns mounted-page/config registration and selects this test after composition.
test('visitor attachment uses real quarantine, exact upload replay, scanner refusal and private erasure', async ({ page, browser }, testInfo) => {
  test.skip(process.env.VASEY_BROWSER_SUPPORT_ATTACHMENTS !== 'true', 'Requires root composed attachment registration and an explicit disposable local fixture policy.');
  const fixture: InquiryFixture = fixtureOperation('conversation-prepare', testInfo.project.name);
  const contents = Buffer.from('SYNTHETIC PRIVATE BROWSER ATTACHMENT.\n');
  const filename = 'synthetic-private-attachment.txt';
  const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
  let foreign: Awaited<ReturnType<typeof browser.newContext>> | undefined;
  try {
    await page.goto('/contact');
    for (const label of ['Name', 'Email', 'Subject', 'Message'] as const) await page.getByLabel(new RegExp(`^${label}`)).fill(fixture.values[label.toLowerCase() as keyof InquiryFixture['values']]);
    const submitted = page.waitForResponse(response => response.url().endsWith('/contact/inquiries') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Send inquiry', exact: true }).click(); const accepted = await submitted; expect(accepted.status()).toBe(201);
    const { receipt } = await accepted.json();
    await page.getByRole('button', { name: 'Read replies and follow up', exact: true }).click();
    const panel = page.getByRole('region', { name: 'Private attachments', exact: true });
    await panel.getByRole('button', { name: 'Open private attachments', exact: true }).click();
    const base = `/private-support/inquiries/${receipt}/attachments`;
    const uploads: string[] = []; let committedId = '';
    await page.route('**' + base + '/upload', async route => {
      uploads.push(route.request().headers()['x-request-key']);
      if (uploads.length > 1) { await route.continue(); return; }
      const response = await route.fetch(); expect(response.status()).toBe(201); committedId = (await response.json()).attachment.attachmentId;
      await route.abort('failed'); // The server retained the original; only the acknowledgement is interrupted.
    });
    await panel.getByLabel('Choose private file', { exact: true }).setInputFiles({ name: filename, mimeType: 'text/plain', buffer: contents });
    await panel.getByRole('button', { name: 'Send private file', exact: true }).click();
    await expect(panel.getByRole('button', { name: 'Retry same upload', exact: true })).toBeVisible(); expect(uploads).toHaveLength(1);
    const replayed = page.waitForResponse(response => response.url().endsWith(base + '/upload') && response.request().method() === 'POST');
    await panel.getByRole('button', { name: 'Retry same upload', exact: true }).click(); const replay = await replayed;
    expect(replay.status()).toBe(200); expect((await replay.json()).attachment.attachmentId).toBe(committedId); expect(uploads[1]).toBe(uploads[0]);
    await expect(panel.getByText(new RegExp(`${contents.length} of ${contents.length} bytes confirmed`))).toBeVisible();
    const scan = page.waitForResponse(response => response.url().endsWith(base + '/' + committedId + '/process'));
    await panel.getByRole('button', { name: `Scan ${filename}`, exact: true }).click(); const scanned = await scan;
    expect(scanned.status()).toBe(200); expect((await scanned.json()).attachment.state).toBe('failed');
    await expect(panel.getByRole('button', { name: `Download ${filename}`, exact: true })).toHaveCount(0);
    foreign = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
    const denied = await foreign.request.get(base); expect(denied.status()).toBe(404); expect(denied.headers()['cache-control']).toContain('no-store'); expect(await denied.text()).not.toContain(filename);
    const csrf = await page.request.post(base + '/' + committedId + '/delete', { data: {} }); expect(csrf.status()).toBe(419); expect(csrf.headers()['cache-control']).toContain('no-store');
    expect(await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n'))).not.toContain(filename);
    await page.route('**' + base, route => route.fulfill({ status: 403, contentType: 'application/json', body: '{"code":"PRIVATE_ATTACHMENT_UNAVAILABLE"}' }));
    await panel.getByRole('button', { name: 'Refresh private attachments', exact: true }).click();
    await expect(panel.getByText(filename, { exact: true })).toHaveCount(0); await expect(panel.getByLabel('Choose private file', { exact: true })).toHaveCount(0);
    await expect(panel.getByRole('button')).toHaveCount(0); expect(errors).toEqual([]);
  } finally {
    await foreign?.close(); fixtureOperation('conversation-cleanup', testInfo.project.name, fixture);
  }
});
