import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

const password = process.env.VASEY_BROWSER_PASSWORD!;

/** A 1440 x 630 test-pattern JPEG from the fixture the PHP tests use. */
function studioJpeg(): Buffer {
  return execFileSync('php', ['-r', 'require "vendor/autoload.php"; echo Tests\\Support\\SiteImageFixtures::jpeg(1440, 630);'], {
    cwd: process.cwd(), env: process.env, stdio: ['ignore', 'pipe', 'pipe'], timeout: 30_000, maxBuffer: 8 * 1024 * 1024,
  });
}

// The isolated harness has no malware scanner, so an upload is kept private and waits for a retry instead of becoming ready.
test('staff upload a site image privately and, without a scanner, it waits for a retry', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/site-images');

  const name = `synthetic-studio-${testInfo.project.name}.jpg`;
  await page.getByRole('button', { name: 'Upload site image', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Upload a site image', exact: true });
  await dialog.getByLabel('Used for', { exact: false }).selectOption('studio');
  await expect(dialog.getByText('At least 1440 px wide, about 2.29:1 (for example 1440 × 630).', { exact: true })).toBeVisible();
  await dialog.locator('input[type="file"]').setInputFiles({ name, mimeType: 'image/jpeg', buffer: studioJpeg() });
  await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toBeVisible({ timeout: 30_000 });
  await dialog.getByLabel('Source or credit', { exact: false }).fill('Synthetic studio photograph');
  await dialog.getByLabel('We have the rights to use this image on the site', { exact: true }).check();
  await dialog.getByRole('button', { name: 'Upload privately', exact: true }).click();
  await expect(page.getByText('Image uploaded', { exact: true })).toBeVisible();
  await expect(dialog).toBeHidden();

  const row = page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) });
  await expect(row.getByText('Waiting', { exact: true })).toBeVisible();
  await expect(row.getByText('Studio image', { exact: true })).toBeVisible();
  await expect(row.getByText('1440 × 630', { exact: true })).toBeVisible();
  await expect(row.getByText('The malware scanner was unavailable or gave no clear result. Retry once it is working.', { exact: true })).toBeVisible();
  await expect(row.locator('.fi-ta-cell-attempts')).toHaveText('1');

  await row.getByRole('button', { name: 'Retry processing', exact: true }).click();
  const confirm = page.getByRole('alertdialog', { name: 'Retry processing', exact: true });
  await expect(confirm.getByText('Queues another attempt to scan and prepare this image. Nothing is published.', { exact: true })).toBeVisible();
  await confirm.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(page.getByText('Processing queued', { exact: true })).toBeVisible();
  // Still no scanner: a second attempt ran and the image is back to waiting with the same explanation.
  await expect(row.locator('.fi-ta-cell-attempts')).toHaveText('2');
  await expect(row.getByText('Waiting', { exact: true })).toBeVisible();

  // The release editor offers only ready images, so every place keeps its built-in image here.
  await page.goto('/admin/site-releases');
  await page.getByRole('button', { name: 'New content draft', exact: true }).click();
  const editor = page.getByRole('dialog', { name: 'Create a private content draft', exact: true });
  for (const [label, placeholder] of [['Home hero, desktop', 'Built-in image'], ['Home hero, mobile', 'Built-in image'], ['Studio image', 'Built-in image'], ['Share image', 'The hero in use']]) {
    const select = editor.getByLabel(label, { exact: true });
    await expect(select).toHaveValue('');
    await expect(select.locator('option')).toHaveText([placeholder]);
  }
  await expect(editor.getByText('which keep their own copy: it cannot be withdrawn later.', { exact: false })).toBeVisible();
  await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(editor).toBeHidden();
  expect(failures).toEqual([]);
});
