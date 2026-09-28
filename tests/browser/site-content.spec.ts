import { test, expect, type Page } from '@playwright/test';

const password = process.env.VASEY_BROWSER_PASSWORD!;
const row = (page: Page, label: string) => page.getByRole('row').filter({ has: page.getByText(label, { exact: true }) });

async function confirm(page: Page, label: string, action: 'Publish release' | 'Restore previous release') {
  await row(page, label).getByRole('button', { name: action, exact: true }).click();
  await page.getByRole('alertdialog', { name: action, exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(row(page, label).getByText('Active', { exact: true })).toBeVisible();
}

test('private draft preview, publication, immutable copy and rollback preserve the active site', async ({ page, context, playwright }, testInfo) => {
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/site-releases');
  const firstLabel = `Synthetic content one ${testInfo.project.name}`;
  const secondLabel = `Synthetic content two ${testInfo.project.name}`;
  const firstHeading = `SYNTHETIC FIRST ${testInfo.project.name.toUpperCase()}`;
  const secondHeading = `SYNTHETIC SECOND ${testInfo.project.name.toUpperCase()}`;
  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    const before = await (await visitor.get('/')).text();
    await page.getByRole('button', { name: 'New content draft', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('Release label', { exact: false }).fill(firstLabel);
    await dialog.getByLabel('Heading, first line', { exact: false }).first().fill(firstHeading);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(row(page, firstLabel).getByText('Private draft', { exact: true })).toBeVisible();
    expect(await (await visitor.get('/')).text()).not.toContain(firstHeading);
    const previewUrl = await row(page, firstLabel).getByRole('link', { name: 'Preview', exact: true }).getAttribute('href');
    expect(previewUrl).toBeTruthy();
    const denied = await visitor.get(previewUrl!, { maxRedirects: 0 });
    expect([302, 403]).toContain(denied.status());
    expect(await denied.text()).not.toContain(firstHeading);
    const preview = await context.newPage();
    const previewResponse = await preview.goto(previewUrl!);
    expect(previewResponse?.headers()['cache-control']).toContain('no-store');
    expect(previewResponse?.headers()['x-robots-tag']).toContain('noindex');
    await expect(preview.locator('#hero-title')).toContainText(firstHeading);
    await expect(preview.getByRole('button', { name: /Open cart/ })).toHaveCount(0);
    await preview.screenshot({ path: testInfo.outputPath('private-content-preview.png'), fullPage: false });
    await preview.close();

    await confirm(page, firstLabel, 'Publish release');
    expect(await (await visitor.get('/')).text()).toContain(firstHeading);
    await row(page, firstLabel).getByRole('button', { name: 'Edit as new draft', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Release label', { exact: false }).fill(secondLabel);
    await dialog.getByLabel('Heading, first line', { exact: false }).first().fill(secondHeading);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(row(page, secondLabel).getByText('Private draft', { exact: true })).toBeVisible();
    expect(await (await visitor.get('/')).text()).toContain(firstHeading);
    expect(await (await visitor.get('/')).text()).not.toContain(secondHeading);
    await confirm(page, secondLabel, 'Publish release');
    expect(await (await visitor.get('/')).text()).toContain(secondHeading);
    await confirm(page, firstLabel, 'Restore previous release');
    const restored = await (await visitor.get('/')).text();
    expect(restored).toContain(firstHeading); expect(restored).not.toContain(secondHeading);
    await confirm(page, 'Original site content', 'Restore previous release');
    const baseline = await (await visitor.get('/')).text();
    expect(baseline).not.toContain(firstHeading); expect(baseline).not.toContain(secondHeading);
    expect(before).not.toContain(firstHeading);
    expect((await (await visitor.get('/api/catalog')).json()).tracks).toEqual([]);
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
