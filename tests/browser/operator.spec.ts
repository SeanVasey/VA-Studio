import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Fixture = { editable: { title: string; slug: string }; retained: { title: string; slug: string } };
const fixtures = JSON.parse(readFileSync(join(process.env.VASEY_BROWSER_DIRECTORY!, 'fixtures.json'), 'utf8')) as Record<string, Fixture>;
const password = process.env.VASEY_BROWSER_PASSWORD!;

async function login(page: Page, email: string) {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill(email);
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

function row(page: Page, title: string) {
  return page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });
}

async function searchTracks(page: Page, title: string) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === title) ?? false;
  });
  await page.getByRole('searchbox', { name: 'Search', exact: true }).fill(title);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(page.getByText(`Search: ${title}`, { exact: true })).toBeVisible();
}

test('guest/customer denial and real CSRF protection', async ({ page }) => {
  await page.goto('/admin/tracks');
  await expect(page).toHaveURL(/\/admin\/login$/);
  const updateUri = await page.locator('script[data-update-uri]').getAttribute('data-update-uri');
  expect(updateUri).toBeTruthy();
  const csrf = await page.request.post(updateUri!, { data: { components: [] } });
  expect(csrf.status()).toBe(419);
  await login(page, 'browser-customer@example.test');
  await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
  await expect(page).toHaveURL(/\/admin\/login$/);
  const catalog = await page.request.get('/api/catalog');
  expect((await catalog.json()).tracks).toEqual([]);
});

test('operator create, field errors, keyboard recovery, stale saves and retained URLs', async ({ page, context }, testInfo) => {
  const fixture = fixtures[testInfo.project.name];
  const failures: string[] = [];
  const watch = (target: Page) => target.on('pageerror', error => failures.push(error.message));
  watch(page);
  await login(page, 'browser-operator@example.test');
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/tracks');
  await page.bringToFront();
  const launch = page.getByRole('button', { name: 'New track', exact: true });
  await launch.focus();
  await launch.press('Enter');
  let dialog = page.getByRole('dialog');
  // Filament's dialog wrapper has no box; assert its rendered content instead.
  await expect(dialog.getByRole('heading')).toBeVisible();
  await expect.poll(() => dialog.evaluate(element => ({
    inside: element.contains(document.activeElement),
    activeTag: document.activeElement?.tagName,
    documentFocused: document.hasFocus(),
  }))).toEqual({ inside: true, activeTag: 'FORM', documentFocused: true });
  await page.keyboard.press('Tab');
  await expect(dialog.getByLabel('Title', { exact: false })).toBeFocused();
  const title = `Synthetic browser creation ${testInfo.project.name}`;
  const slug = `browser-creation-${testInfo.project.name}`;
  await dialog.getByLabel('Title', { exact: false }).fill(title);
  await dialog.getByLabel('Slug', { exact: false }).fill(fixture.retained.slug);
  await dialog.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(dialog.getByText(/slug has already been taken/i)).toBeVisible();
  await expect(dialog.getByLabel('Title', { exact: false })).toHaveValue(title);
  await dialog.getByText(/slug has already been taken/i).scrollIntoViewIfNeeded();
  await expect(dialog.getByText(/slug has already been taken/i)).toBeInViewport();
  await page.screenshot({ path: testInfo.outputPath('create-validation.png'), fullPage: false });
  await dialog.getByLabel('Slug', { exact: false }).fill(slug);
  await dialog.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(dialog.getByRole('heading')).not.toBeVisible();
  // Prior journeys can fill the first page. Wait for the real debounced search before locating the persisted row.
  await searchTracks(page, title);
  await expect(row(page, title)).toBeVisible();
  // A new document verifies persistence through a fresh server read, with no retained component state.
  const second = await context.newPage();
  watch(second);
  await second.goto('/admin/tracks');
  await searchTracks(second, title);
  await expect(row(second, title)).toBeVisible();
  expect((await page.request.get(`/tracks/${slug}`)).status()).toBe(404);

  // Two real tabs load one revision. The second save must remain visible and preserve the winner.
  await page.bringToFront();
  await searchTracks(page, fixture.editable.title);
  await searchTracks(second, fixture.editable.title);
  await row(page, fixture.editable.title).getByRole('button', { name: 'Edit', exact: true }).click();
  await row(second, fixture.editable.title).getByRole('button', { name: 'Edit', exact: true }).click();
  dialog = page.getByRole('dialog');
  const secondDialog = second.getByRole('dialog');
  const winner = `Synthetic winning edit ${testInfo.project.name}`;
  await dialog.getByLabel('Title', { exact: false }).fill(winner);
  await dialog.getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog.getByRole('heading')).not.toBeVisible();
  await secondDialog.getByLabel('Title', { exact: false }).fill('Synthetic losing edit');
  await secondDialog.getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(secondDialog.getByText(/changed since you opened it/)).toBeVisible();
  await expect(secondDialog.getByLabel('Title', { exact: false })).toHaveValue('Synthetic losing edit');
  await secondDialog.getByText(/changed since you opened it/).scrollIntoViewIfNeeded();
  await expect(secondDialog.getByText(/changed since you opened it/)).toBeInViewport();
  await second.screenshot({ path: testInfo.outputPath('stale-edit.png'), fullPage: false });
  // Cancel animates locally before its unmount request settles. Wait for the server acknowledgement.
  const cancelResponse = second.waitForResponse(response => response.url().endsWith('/update') && response.request().method() === 'POST');
  await secondDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  const cancelled = await cancelResponse;
  expect(cancelled.status()).toBe(200);
  await cancelled.finished();
  await expect(secondDialog.getByRole('heading')).not.toBeVisible();
  const recovered = await context.newPage();
  watch(recovered);
  await recovered.goto('/admin/tracks');
  await searchTracks(recovered, winner);
  await expect(row(recovered, winner)).toBeVisible();
  await page.bringToFront();

  await searchTracks(page, fixture.retained.title);
  const editRetained = row(page, fixture.retained.title).getByRole('button', { name: 'Edit', exact: true });
  await editRetained.focus();
  await editRetained.press('Enter');
  dialog = page.getByRole('dialog');
  await expect(dialog.getByLabel('Slug', { exact: false })).toBeDisabled();
  await expect(dialog.getByText('This URL stays reserved, including after unpublishing.')).toBeVisible();
  const retainedCancelResponse = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
  });
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).focus();
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).press('Enter');
  const retainedCancelled = await retainedCancelResponse;
  expect(retainedCancelled.status()).toBe(200);
  expect(await retainedCancelled.finished()).toBeNull();
  await expect(dialog.getByRole('heading')).not.toBeVisible();
  await expect(editRetained).toBeFocused();

  await searchTracks(page, title);
  await row(page, title).getByRole('button', { name: 'Publish', exact: true }).click();
  dialog = page.getByRole('alertdialog', { name: 'Publish', exact: true });
  await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(page.getByText('Publication blocked', { exact: true })).toBeVisible();
  await expect(page.getByText(/The latest rights declaration must be verified/)).toBeVisible();
  expect((await page.request.get(`/tracks/${slug}`)).status()).toBe(404);
  await page.getByText('Publication blocked', { exact: true }).scrollIntoViewIfNeeded();
  await expect(page.getByText('Publication blocked', { exact: true })).toBeInViewport();
  await page.screenshot({ path: testInfo.outputPath('publication-blockers.png'), fullPage: false });
  expect(failures).toEqual([]);
});
