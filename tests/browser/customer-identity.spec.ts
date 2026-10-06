import { test, expect, type Page } from '@playwright/test';
import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';

/** The guarded local wrapper captures synthetic messages privately. No mailbox or email transport is used. */
async function captured(email: string, purpose: 'enroll' | 'recover'): Promise<string> {
  const directory = join(process.env.VASEY_BROWSER_DIRECTORY!, 'app/private/customer-identity-capture');
  const files = await readdir(directory);
  for (const file of files) {
    const message = JSON.parse(await readFile(join(directory, file), 'utf8'));
    if (message.to === email && message.purpose === purpose && message.testOnly === true) return message.url;
  }
  throw new Error('Expected synthetic private customer message was not captured.');
}

async function request(page: Page, email: string, purpose: 'enroll' | 'recover') {
  await page.goto(purpose === 'enroll' ? '/account/create' : '/account/recover');
  await page.getByLabel('Email address', { exact: true }).fill(email);
  const accepted = page.waitForResponse(response => response.url().endsWith('/account/identity/request') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Request test message', exact: true }).click();
  const response = await accepted;
  expect(response.status()).toBe(202);
  expect(await response.json()).toEqual({ accepted: true });
  expect(response.headers()['cache-control']).toContain('no-store');
  await expect(page.getByRole('status')).toContainText('No email is sent.');
}

async function finish(page: Page, url: string, password: string, enroll: boolean) {
  const proof = new URL(url).hash.split('.').at(-1)!;
  await page.goto(url);
  await expect(page).toHaveURL(/\/account\/access$/);
  if (enroll) await page.getByLabel('Name', { exact: true }).fill('Synthetic self-service customer');
  await page.getByLabel('New password', { exact: true }).fill(password);
  expect(await page.evaluate(() => JSON.stringify({ state: history.state, local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).not.toContain(proof);
  const completed = page.waitForResponse(response => response.url().endsWith('/account/identity/complete') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Complete account request', exact: true }).click();
  const response = await completed;
  expect(response.status()).toBe(200);
  expect(await response.json()).toEqual({ completed: true, next: '/account/sign-in' });
  await expect(page.getByRole('status')).toContainText('Sign in with your new password.');
  expect(await page.locator('html').innerHTML()).not.toContain(proof);
  expect(await page.evaluate(() => JSON.stringify({ state: history.state, local: Object.entries(localStorage), session: Object.entries(sessionStorage) }))).not.toContain(password);
  const account = await page.request.get('/account', { maxRedirects: 0 });
  expect(account.status()).toBe(302);
}

async function signIn(page: Page, email: string, password: string) {
  await page.goto('/account/sign-in');
  await page.getByLabel('Email address', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/account$/);
}

test('private test enrollment and recovery use single-use proof, separate sign-in and invalidate the old session', async ({ browser }, testInfo) => {
  const email = `self-service-${testInfo.project.name}@example.test`;
  const password = process.env.VASEY_BROWSER_PASSWORD!;
  const replacement = `Reset-${password}`;
  const original = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
  const recovery = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
  const errors: string[] = [];
  try {
    const first = await original.newPage();
    first.on('pageerror', error => errors.push(error.message));
    await request(first, email, 'enroll');
    const enrollment = await captured(email, 'enroll');
    await finish(first, enrollment, password, true);
    await signIn(first, email, password);
    await expect(first.getByText('Synthetic self-service customer', { exact: true })).toBeVisible();
    const admin = await first.request.get('/admin', { maxRedirects: 0 });
    expect(admin.status()).toBe(302);
    expect(admin.headers().location).toMatch(/\/admin\/login$/);
    const second = await recovery.newPage();
    second.on('pageerror', error => errors.push(error.message));
    await request(second, email, 'recover');
    const reset = await captured(email, 'recover');
    await finish(second, reset, replacement, false);
    const stale = await first.request.get('/orders/history');
    expect(stale.status()).toBe(403);
    await signIn(second, email, replacement);
    await expect(second.getByText('Synthetic self-service customer', { exact: true })).toBeVisible();
    expect(await second.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await second.screenshot({ path: testInfo.outputPath('customer-self-service-library.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally {
    await original.close();
    await recovery.close();
  }
});
