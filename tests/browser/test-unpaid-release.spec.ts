import { execFileSync } from 'node:child_process';
import { expect, test, type Locator, type Page, type Response } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse } from './publication-fixture';

type Fixture = { orderId: string; operatorEmail: string; capability: string; privateMarkers: string[] };
type Proof = {
  phase: string; releaseId: string | null; historyCount: number; providerReads: number;
  originalsUnchanged: boolean; noRightsOrMoneyEffects: boolean; guardsUnchanged: boolean;
};
const componentName = 'App\\Filament\\Resources\\TestUnpaidOrderResource\\Pages\\ListTestUnpaidOrders';

function fixtureOperation(mode: 'prepare' | 'verify', project: string, phase?: string) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-test-unpaid-release.php', mode, project, ...(phase ? [phase] : [])], {
    cwd: process.cwd(), env: process.env, encoding: 'utf8', timeout: 90_000, stdio: 'pipe',
  }));
}

function verify(project: string, phase: 'prepared' | 'released' | 'replayed'): Proof {
  const proof = fixtureOperation('verify', project, phase) as Proof;
  expect(proof).toEqual({ phase, releaseId: phase === 'prepared' ? null : expect.any(String),
    historyCount: phase === 'prepared' ? 0 : 2, providerReads: phase === 'prepared' ? 0 : 4,
    originalsUnchanged: true, noRightsOrMoneyEffects: true, guardsUnchanged: true });
  return proof;
}

async function operate(page: Page, button: Locator, method: Parameters<typeof actionResponse>[1], actionName?: string): Promise<Response> {
  const pending = actionResponse(page, method, actionName);
  await button.scrollIntoViewIfNeeded();
  await button.focus();
  await button.press('Enter');
  const response = await pending;
  expect(response.status()).toBe(200);
  await response.finished();
  return response;
}

async function assertPrivate(page: Page, fixture: Fixture, response: Response) {
  const surfaces = [await page.content(), await response.text(),
    await page.locator('[wire\\:snapshot]').evaluateAll(elements => elements.map(element => element.getAttribute('wire:snapshot')).join('\n')),
    await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n'))];
  for (const encoded of surfaces) {
    const surface = encoded.replaceAll('\\/', '/');
    for (const marker of [...fixture.privateMarkers, fixture.capability]) expect(surface).not.toContain(marker);
    for (const field of ['owner_key', 'evidence_ciphertext', 'provider_payment_intent_id', 'storage_path', 'claim_token']) {
      expect(surface).not.toContain(field);
    }
  }
}

test('operator confirms a real terminal-unpaid release, reads retained history and safely repeats the review', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  resetBrowserLoginRateLimit();
  const fixture = fixtureOperation('prepare', testInfo.project.name) as Fixture;
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/test-unpaid-orders');
  await expect(page).toHaveURL(/\/admin\/login$/);
  await page.getByLabel('Email address', { exact: false }).fill(fixture.operatorEmail);
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin(?:\/test-unpaid-orders)?$/);
  expect((await page.goto('/admin/test-unpaid-orders'))?.status()).toBe(200);

  // Livewire's pinned request interceptor only adds a private harness header. The ordinary
  // payload, signed snapshot, session/CSRF middleware, route and response are never mocked.
  await page.evaluate(({ capability, expectedName }) => {
    type Request = { uri: string; payload: { components?: { snapshot: string }[] }; options: { headers: Record<string, string> } };
    const livewire = (window as unknown as { Livewire: { interceptRequest: (callback: (input: { request: Request }) => void) => void } }).Livewire;
    livewire.interceptRequest(({ request }) => {
      const url = new URL(request.uri, location.href);
      const components = request.payload.components;
      if (url.origin !== location.origin || ! /^\/livewire(?:-[A-Za-z0-9]+)?\/update$/.test(url.pathname)
        || components?.length !== 1 || JSON.parse(components[0].snapshot).memo.name !== expectedName) return;
      request.options.headers['X-Vasey-Unpaid-Fixture'] = capability;
    });
  }, { capability: fixture.capability, expectedName: componentName });

  const row = page.getByRole('row').filter({ has: page.getByText(fixture.orderId, { exact: true }) });
  const release = row.getByRole('button', { name: 'Verify unpaid status and release', exact: true });
  const dialog = page.getByRole('dialog');
  await operate(page, release, 'mountAction', 'releaseUnpaid');
  await expect(dialog.getByRole('heading', { name: 'Verify unpaid status and release', exact: true })).toBeVisible();
  await expect(dialog).toContainText('Expiry alone is not proof');
  verify(testInfo.project.name, 'prepared');
  const submitted = await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Submit', exact: true }), 'callMountedAction');
  await expect(page.getByText('Test resources released', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading', { name: 'Verify unpaid status and release', exact: true })).not.toBeVisible();
  await assertPrivate(page, fixture, submitted);
  const initial = verify(testInfo.project.name, 'released');

  const history = await operate(page, row.getByRole('button', { name: 'Release history', exact: true }), 'mountAction', 'releaseHistory');
  await expect(dialog.getByRole('heading', { name: 'Test unpaid release history', exact: true })).toBeVisible();
  await expect(dialog).toContainText(initial.releaseId!);
  await expect(dialog).toContainText('Current history sequence: 2');
  await expect(dialog).toContainText('A release is recorded');
  await expect(dialog.locator('.fi-modal-window')).toHaveCSS('opacity', '1');
  await assertPrivate(page, fixture, history);
  await page.screenshot({ path: testInfo.outputPath('test-unpaid-release-history.png'), fullPage: false });
  await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Close', exact: true }), 'unmountAction');

  // A newly reviewed repeat sees the same retained release and performs no new provider GET.
  await operate(page, release, 'mountAction', 'releaseUnpaid');
  const replay = await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Submit', exact: true }), 'callMountedAction');
  await expect(page.getByText('Test resources released', { exact: true }).last()).toBeVisible();
  await assertPrivate(page, fixture, replay);
  expect(verify(testInfo.project.name, 'replayed').releaseId).toBe(initial.releaseId);
  expect(errors).toEqual([]);
});
