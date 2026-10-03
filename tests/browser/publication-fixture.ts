import { expect, type Locator, type Page, type Response } from '@playwright/test';

export const row = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });

export function actionResponse(page: Page, method: 'mountAction' | 'unmountAction' | 'callMountedAction', name?: string) {
  return page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string; params?: unknown[] }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === method
      && (name === undefined || call.params?.[0] === name))) ?? false;
  });
}

export async function login(page: Page) {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

export async function searchTracks(page: Page, title: string) {
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
  await expect(row(page, title)).toBeVisible();
}

export async function expectModalFits(page: Page, dialog: Locator) {
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  expect(await dialog.locator('.fi-modal-window').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

export async function closeDialog(page: Page, dialog: Locator, label: 'Cancel' | 'Close') {
  const close = dialog.locator('.fi-modal-footer').getByRole('button', { name: label, exact: true });
  await close.focus();
  await expect(close).toBeFocused();
  const [closed] = await Promise.all([actionResponse(page, 'unmountAction'), close.press('Enter')]);
  expect(closed.status()).toBe(200);
  expect(await closed.finished()).toBeNull();
  await expect(dialog.getByRole('heading')).toBeHidden();
  return closed;
}

export async function openPublication(page: Page, title: string) {
  await page.bringToFront();
  const launch = row(page, title).getByRole('button', { name: 'Publish', exact: true });
  await launch.focus();
  const [opened] = await Promise.all([actionResponse(page, 'mountAction', 'publish'), launch.press('Enter')]);
  expect(opened.status()).toBe(200);
  expect(await opened.finished()).toBeNull();
  expect(opened.headers()['cache-control']).toContain('private');
  expect(opened.headers()['cache-control']).toContain('no-store');
  expect(opened.headers()['x-robots-tag']).toContain('noindex');
  const review = await publicationReview(opened);
  expect(review).toMatchObject({ schema_version: 2, actor_id: 1, intent: 'publish', status: 'draft' });
  expect(Object.keys(review!).sort()).toEqual(['actor_id', 'intent', 'manifest_hash', 'metadata_version', 'publication_version', 'schema_version', 'status', 'track_id']);
  expect(review!.manifest_hash).toMatch(/^[a-f0-9]{64}$/);
  const dialog = page.getByRole('alertdialog', { name: 'Publish', exact: true });
  await expect(dialog.getByRole('heading', { name: 'Publish', exact: true })).toBeVisible();
  const confirm = dialog.getByRole('button', { name: 'Confirm', exact: true });
  await confirm.focus();
  await expect(confirm).toBeFocused();
  // Prove native keyboard traversal stays within the existing confirmation trap.
  await page.keyboard.press('Tab');
  await expectModalFits(page, dialog);
  return { dialog, launch, confirm, review: review! };
}

export async function publicationReview(response: Response): Promise<Record<string, unknown> | null> {
  const body = await response.json() as { components: { snapshot: string }[] };
  const snapshots = body.components.map(component => JSON.parse(component.snapshot) as { data: Record<string, unknown> });
  const owner = snapshots.find(snapshot => Object.hasOwn(snapshot.data, 'publicationReview'));
  expect(owner).toBeDefined();
  const value = owner!.data.publicationReview;
  if (value === null) return null;
  // Livewire serializes arrays as their value and synthesizer metadata.
  expect(Array.isArray(value)).toBe(true);
  expect((value as unknown[])[1]).toEqual({ s: 'arr' });
  return (value as [Record<string, unknown>, unknown])[0];
}
