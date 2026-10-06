import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

test('operator resumes real eight MiB transport after a lost response and saves private media', async ({ page }, testInfo) => {
  let parts = 0;
  await page.exposeBinding('__vaseyLoseFirstChunkAcknowledgement', ({ frame }, status: number) => {
    expect(frame).toBe(page.mainFrame());
    expect(status).toBe(200); // Actual PHP multipart admission, CSRF, service and storage ran.
    parts++;
    return parts === 1;
  });
  await page.addInitScript(() => {
    const nativeFetch = window.fetch.bind(window);
    const harness = window as unknown as { __vaseyLoseFirstChunkAcknowledgement: (status: number) => Promise<boolean> };
    window.fetch = async (input, init) => {
      const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url, location.href);
      const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
      // Preserve the browser's exact Request/FormData transport, including its multipart boundary.
      const response = await nativeFetch(input, init);
      if (url.origin === location.origin && /^\/admin\/resumable-uploads\/[a-f0-9-]{36}\/chunks$/.test(url.pathname)
        && method === 'POST' && await harness.__vaseyLoseFirstChunkAcknowledgement(response.status)) {
        // The real first chunk committed; only its acknowledgement is withheld from the client.
        throw new TypeError('Synthetic lost chunk acknowledgement');
      }
      return response;
    };
  });
  if (testInfo.project.name === 'webkit-mobile') await page.setViewportSize({ width: 320, height: 900 });
  const fixtures = JSON.parse(readFileSync(join(process.env.VASEY_BROWSER_DIRECTORY!, 'fixtures.json'), 'utf8'));
  const trackTitle = fixtures[testInfo.project.name].retained.title;
  const pageErrors: string[] = [];
  page.on('pageerror', error => pageErrors.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/media-assets');
  await page.getByRole('link', { name: 'Resumable upload', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/admin\/media-assets\/resumable-upload$/);
  await expect(page.getByRole('heading', { name: 'Resumable private upload' })).toBeVisible();
  const withoutCsrf = await page.request.post('/admin/resumable-uploads', { data: {} });
  expect(withoutCsrf.status()).toBe(419);
  expect(withoutCsrf.headers()['cache-control']).toContain('no-store');

  await page.getByRole('combobox', { name: 'Track', exact: false }).click();
  await page.getByRole('textbox', { name: 'Search', exact: true }).fill(trackTitle);
  await page.getByRole('option', { name: trackTitle, exact: true }).click();
  await page.getByLabel('Role', { exact: false }).selectOption('artwork');
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZuoAAAAASUVORK5CYII=', 'base64');
  // Synthetic PNG with bounded trailing bytes: exercise a genuine full transport part,
  // then quarantine it. This is not scanner/derivative acceptance.
  const bytes = Buffer.alloc(8 * 1024 * 1024 + 17); png.copy(bytes);
  const name = `synthetic-resumable-${testInfo.project.name}.png`;
  const file = { name, mimeType: 'image/png', buffer: bytes };
  await page.getByLabel('File from your device', { exact: true }).setInputFiles(file);
  const status = page.locator('[data-upload-status]');
  await page.getByRole('button', { name: 'Start upload', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(status).toContainText('could not be confirmed');
  expect(parts, 'The real first chunk must commit before its acknowledgement is deliberately lost').toBe(1);
  const id = await page.getByLabel('Upload ID', { exact: true }).inputValue();
  expect(id).toMatch(/^[a-f0-9-]{36}$/);
  await page.reload();
  await expect(page.getByLabel('Upload ID', { exact: true })).toHaveValue(id);
  await page.getByRole('button', { name: 'Inspect upload', exact: true }).click();
  await expect(status).toContainText('8,388,608');
  await page.getByLabel('File from your device', { exact: true }).setInputFiles(file);
  await page.getByRole('button', { name: 'Continue upload', exact: true }).click();
  await expect(status).toContainText('All bytes received');
  expect(parts).toBe(2); // The retained first part was not sent again after reload.
  const finish = page.getByRole('button', { name: 'Finish upload', exact: true });
  await finish.focus();
  await page.keyboard.press('Enter');
  await expect(status).toContainText('Upload saved as media #');
  await expect(status).toContainText('Processing and publication have not been requested');
  const inspected = await page.request.get('/admin/resumable-uploads/' + id);
  expect(inspected.ok()).toBe(true);
  const saved = (await inspected.json()).session;
  expect(saved.status).toBe('completed');
  expect(saved.receivedBytes).toBe(bytes.length);
  expect((await page.request.get('/media/' + saved.assetId)).status()).toBe(404);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('resumable-private-upload-completed.png'), fullPage: true });
  await page.getByRole('link', { name: 'Return to Media assets' }).click();
  const searched = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === name) ?? false;
  });
  // The main table search is distinct from the topbar's global search on mobile.
  await page.getByRole('main').getByRole('searchbox', { name: 'Search', exact: true }).fill(name);
  const search = await searched; expect(search.status()).toBe(200); expect(await search.finished()).toBeNull();
  const row = page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) });
  await expect(row).toBeVisible();
  await expect(row.getByText('quarantined', { exact: true })).toBeVisible();
  expect(pageErrors).toEqual([]);
});
