import { test, expect, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { expectReleaseTableFits, releaseMenuAction, releaseMenuTrigger, releaseRow as row } from './site-release-row';

test.beforeEach(() => resetBrowserLoginRateLimit());

const password = process.env.VASEY_BROWSER_PASSWORD!;

async function confirm(page: Page, label: string, action: 'Publish release' | 'Restore previous release') {
  const trigger = action === 'Publish release'
    ? row(page, label).getByRole('button', { name: action, exact: true })
    : await releaseMenuAction(page, label, action);
  await trigger.click();
  await page.getByRole('alertdialog', { name: action, exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(row(page, label).getByText('Active', { exact: true })).toBeVisible();
}

test('private draft preview, publication, immutable copy and rollback preserve the active site', async ({ page, context, playwright }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/site-releases');
  const firstLabel = `Synthetic content one ${testInfo.project.name}`;
  // An unbroken token must wrap too, or the table scrolls sideways again.
  const secondLabel = `Synthetic content two ${testInfo.project.name} SYNTHETIC_UNBROKEN_LABEL_TOKEN_0123456789_0123456789_0123456789`;
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
    preview.on('pageerror', error => failures.push(error.message));
    const previewResponse = await preview.goto(previewUrl!);
    expect(previewResponse?.headers()['cache-control']).toContain('no-store');
    expect(previewResponse?.headers()['x-robots-tag']).toContain('noindex');
    await expect(preview.locator('#hero-title')).toContainText(firstHeading);
    await expect(preview.getByRole('button', { name: /Open cart/ })).toHaveCount(0);
    await preview.screenshot({ path: testInfo.outputPath('private-content-preview.png'), fullPage: false });
    await preview.close();

    await confirm(page, firstLabel, 'Publish release');
    expect(await (await visitor.get('/')).text()).toContain(firstHeading);
    await (await releaseMenuAction(page, firstLabel, 'Edit as new draft')).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Release label', { exact: false }).fill(secondLabel);
    await dialog.getByLabel('Heading, first line', { exact: false }).first().fill(secondHeading);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(row(page, secondLabel).getByText('Private draft', { exact: true })).toBeVisible();
    expect(await (await visitor.get('/')).text()).toContain(firstHeading);
    expect(await (await visitor.get('/')).text()).not.toContain(secondHeading);
    // Keep one confirmation open while another editor changes the active publication.
    await row(page, secondLabel).getByRole('button', { name: 'Publish release', exact: true }).click();
    const secondEditor = await context.newPage();
    secondEditor.on('pageerror', error => failures.push(error.message));
    await secondEditor.goto('/admin/site-releases');
    await confirm(secondEditor, 'Original site content', 'Restore previous release');
    await page.getByRole('alertdialog', { name: 'Publish release', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Publication blocked', { exact: true })).toBeVisible();
    await expect(page.getByText(/The published site changed/)).toBeVisible();
    const afterConflict = await (await visitor.get('/')).text();
    expect(afterConflict).not.toContain(firstHeading); expect(afterConflict).not.toContain(secondHeading);
    await secondEditor.close();
    await page.bringToFront();
    await expect(page.getByRole('alertdialog', { name: 'Publish release', exact: true })).not.toBeVisible();
    // A fresh confirmation can deliberately publish the unchanged private snapshot.
    await confirm(page, secondLabel, 'Publish release');
    expect(await (await visitor.get('/')).text()).toContain(secondHeading);
    // Three releases, two of them restorable: the widest rows must still fit the desktop admin.
    if (testInfo.project.name === 'chromium-desktop') await expectReleaseTableFits(page);
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

test('the row menu shows keyboard focus and returns it to More when a dialog or the menu closes', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Keyboard focus is checked on the desktop project.');
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await page.goto('/admin/site-releases');
  // Published and then replaced by the previous test, so its menu offers Restore previous release.
  const label = `Synthetic content one ${testInfo.project.name}`;
  const trigger = releaseMenuTrigger(page, label);
  const restore = row(page, label).getByRole('button', { name: 'Restore previous release', exact: true });

  await trigger.focus();
  await page.keyboard.press('Enter');
  await expect(restore).toBeVisible();
  for (let step = 0; step < 4 && !(await restore.evaluate(element => element === document.activeElement)); step++) {
    await page.keyboard.press('Tab');
  }
  await expect(restore).toBeFocused();
  // A visible outline, not only the faint background Filament gives a focused menu item.
  expect(await restore.evaluate(element => {
    const style = getComputedStyle(element);
    return [style.outlineStyle, parseFloat(style.outlineWidth) >= 2];
  })).toEqual(['solid', true]);

  await page.keyboard.press('Enter');
  // Filament's dialog element has no box of its own, so check its heading.
  const heading = page.getByRole('alertdialog', { name: 'Restore previous release', exact: true }).getByRole('heading', { name: 'Restore previous release', exact: true });
  await expect(heading).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(heading).toBeHidden();
  await expect(trigger).toBeFocused();

  // Escape inside the open menu closes it and also keeps focus on More.
  await page.keyboard.press('Enter');
  await expect(restore).toBeVisible();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Escape');
  await expect(restore).toBeHidden();
  await expect(trigger).toBeFocused();
  expect(failures).toEqual([]);
});
