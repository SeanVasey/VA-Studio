import { expect, test } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse, login, row } from './publication-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

/** One valid stored WAV member; a real ZIP larger than PHP's 9 MiB request limit. */
function sampleKit(): Buffer {
  const samples = Buffer.alloc(10 * 1024 * 1024);
  const wav = Buffer.alloc(44);
  wav.write('RIFF'); wav.writeUInt32LE(samples.length + 36, 4); wav.write('WAVEfmt ', 8);
  wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
  wav.writeUInt32LE(48000, 24); wav.writeUInt32LE(96000, 28); wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
  wav.write('data', 36); wav.writeUInt32LE(samples.length, 40);
  const source = Buffer.concat([wav, samples]);
  const table = Array.from({ length: 256 }, (_, value) => {
    let c = value;
    for (let bit = 0; bit < 8; bit++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  });
  let crc = 0xffffffff;
  for (const value of source) crc = table[(crc ^ value) & 0xff] ^ (crc >>> 8);
  crc = (crc ^ 0xffffffff) >>> 0;
  const name = Buffer.from('Synthetic/Silence.wav');
  const local = Buffer.alloc(30);
  local.writeUInt32LE(0x04034b50); local.writeUInt16LE(20, 4); local.writeUInt32LE(crc, 14);
  local.writeUInt32LE(source.length, 18); local.writeUInt32LE(source.length, 22); local.writeUInt16LE(name.length, 26);
  const central = Buffer.alloc(46);
  central.writeUInt32LE(0x02014b50); central.writeUInt16LE(0x314, 4); central.writeUInt16LE(20, 6);
  central.writeUInt32LE(crc, 16); central.writeUInt32LE(source.length, 20); central.writeUInt32LE(source.length, 24);
  central.writeUInt16LE(name.length, 28); central.writeUInt32LE((0o100600 * 65536) >>> 0, 38);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50); end.writeUInt16LE(1, 8); end.writeUInt16LE(1, 10);
  end.writeUInt32LE(central.length + name.length, 12); end.writeUInt32LE(local.length + name.length + source.length, 16);
  return Buffer.concat([local, name, source, central, name, end]);
}

test('operator resumes a native multipart WAV kit larger than the request ceiling after a lost receipt', async ({ page }, testInfo) => {
  const receipts: Array<{ offset: string; bytes: number }> = [];
  await page.exposeBinding('__vaseyLoseKitAcknowledgement', ({ frame }, status: number, offset: string, bytes: number) => {
    expect(frame).toBe(page.mainFrame());
    expect(status).toBe(200); // PHP's real multipart parser, CSRF and private persistence all ran.
    receipts.push({ offset, bytes });
    return receipts.length === 1;
  });
  await page.addInitScript(() => {
    const nativeFetch = window.fetch.bind(window);
    const harness = window as unknown as { __vaseyLoseKitAcknowledgement: (status: number, offset: string, bytes: number) => Promise<boolean> };
    window.fetch = async (input, init) => {
      const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url, location.href);
      // Preserve the actual browser FormData and multipart boundary. Do not replay through an API client.
      const response = await nativeFetch(input, init);
      if (url.origin === location.origin && /^\/admin\/sound-kit-uploads\/[a-f0-9-]{36}\/chunks$/.test(url.pathname)
        && init?.method === 'POST' && init.body instanceof FormData) {
        const chunk = init.body.get('chunk');
        if (chunk instanceof File && await harness.__vaseyLoseKitAcknowledgement(response.status, String(init.body.get('offset')), chunk.size)) {
          throw new TypeError('Synthetic lost kit acknowledgement');
        }
      }
      return response;
    };
  });
  if (testInfo.project.name === 'webkit-mobile') await page.setViewportSize({ width: 320, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await login(page);
  await page.goto('/admin/sound-kit-drafts');
  const create = page.getByRole('button', { name: 'Create kit draft', exact: true });
  await create.focus();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'create'), create.press('Enter')]);
  expect(opened.status()).toBe(200);
  const dialog = page.getByRole('dialog');
  const title = `SYNTHETIC resumable kit ${testInfo.project.name}`;
  await dialog.getByLabel('Title', { exact: false }).fill(title);
  await dialog.getByLabel('Description', { exact: false }).fill('Synthetic WAV archive for native transport verification.');
  await dialog.getByLabel('Source and provenance reference', { exact: false }).fill('Generated silence for tests only; no commercial rights approval.');
  const [created] = await Promise.all([actionResponse(page, 'callMountedAction'), dialog.getByRole('button', { name: 'Save kit draft', exact: true }).click()]);
  expect(created.status()).toBe(200);
  const kitRow = row(page, title);
  await expect(kitRow).toBeVisible();
  const link = kitRow.getByRole('link', { name: 'Resumable kit upload', exact: true });
  await link.focus(); await link.press('Enter');
  await expect(page).toHaveURL(/\/admin\/sound-kit-drafts\/[0-9]+\/resumable-upload$/);
  const pageResponse = await page.reload();
  expect(pageResponse!.headers()['cache-control']).toContain('no-store');
  await expect(page.getByRole('heading', { name: 'Resumable kit upload', exact: true })).toBeVisible();
  const noCsrf = await page.request.post('/admin/sound-kit-uploads', { data: {} });
  expect(noCsrf.status()).toBe(419);
  expect(noCsrf.headers()['cache-control']).toContain('no-store');
  const bytes = sampleKit();
  expect(bytes.length).toBeGreaterThan(9 * 1024 * 1024);
  const file = { name: `synthetic-kit-${testInfo.project.name}.zip`, mimeType: 'application/zip', buffer: bytes };
  await page.getByLabel('File from your device', { exact: true }).setInputFiles(file);
  const status = page.locator('[data-upload-status]');
  await page.getByRole('button', { name: 'Start upload', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(status).toContainText('could not be confirmed');
  expect(receipts).toEqual([{ offset: '0', bytes: 8 * 1024 * 1024 }]);
  const id = await page.getByLabel('Upload ID', { exact: true }).inputValue();
  expect(id).toMatch(/^[a-f0-9-]{36}$/);
  await page.reload();
  await expect(page.getByLabel('Upload ID', { exact: true })).toHaveValue(id);
  await page.getByRole('button', { name: 'Inspect upload', exact: true }).click();
  await expect(status).toContainText('8,388,608');
  const wrong = Buffer.from(bytes); wrong[100] ^= 1;
  await page.getByLabel('File from your device', { exact: true }).setInputFiles({ ...file, buffer: wrong });
  await page.getByRole('button', { name: 'Continue upload', exact: true }).click();
  await expect(status).toContainText('SHA-256 does not match');
  expect(receipts).toHaveLength(1);
  await page.getByLabel('File from your device', { exact: true }).setInputFiles(file);
  await page.getByRole('button', { name: 'Continue upload', exact: true }).click();
  await expect(status).toContainText('All bytes received');
  expect(receipts).toEqual([{ offset: '0', bytes: 8 * 1024 * 1024 }, { offset: String(8 * 1024 * 1024), bytes: bytes.length - 8 * 1024 * 1024 }]);
  await page.getByRole('button', { name: 'Finish upload', exact: true }).focus(); await page.keyboard.press('Enter');
  await expect(status).toContainText('Kit archive retained as revision #');
  await expect(status).toContainText('Rights, publication, checkout and delivery remain unapproved');
  const inspected = await page.request.get('/admin/sound-kit-uploads/' + id);
  expect(inspected.status()).toBe(200);
  expect(inspected.headers()['cache-control']).toContain('no-store');
  const saved = (await inspected.json()).session;
  expect(saved.status).toBe('completed');
  expect(saved.receivedBytes).toBe(bytes.length);
  expect(saved.revisionId).toBeGreaterThan(0);
  expect(Object.keys(saved).sort()).toEqual(['chunkBytes', 'cleanupPending', 'expectedVersion', 'expiresAt', 'id', 'kitId', 'originalName', 'receivedBytes', 'revisionId', 'sha256', 'sizeBytes', 'status']);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('resumable-kit-completed.png'), fullPage: true });
  await page.getByRole('link', { name: 'Return to Sound kit drafts' }).click();
  const [inspection] = await Promise.all([actionResponse(page, 'mountAction', 'inspect'), row(page, title).getByRole('button', { name: 'Inspect revision', exact: true }).click()]);
  expect(inspection.status()).toBe(200);
  const details = page.getByLabel('Retained archive and member manifest', { exact: false });
  await expect(details).toBeVisible();
  expect(await details.inputValue()).toContain(file.name);
  expect(await details.inputValue()).toContain('does not approve rights');
  expect(await details.inputValue()).not.toContain('source_path');
  expect(errors).toEqual([]);
});
