import { execFileSync } from 'node:child_process';
import { expect, test, type Locator, type Page, type Response } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

type Fixture = { operatorEmail: string; verifiedId: string; attentionId: string; orderId: string; privateMarkers: string[] };
type Proof = { phase: string; inspectionAudits: { verified: number; attention: number }; evidenceUnchanged: boolean; guardsRestored: boolean };

function fixtureOperation(mode: 'prepare' | 'verify' | 'withdraw' | 'restore', project: string, phase?: string) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-test-payment-exception-inspection.php', mode, project, ...(phase ? [phase] : [])], {
    cwd: process.cwd(), env: process.env, encoding: 'utf8', timeout: 90_000, stdio: 'pipe',
  }));
}

function verify(project: string, phase: string): Proof {
  const proof = fixtureOperation('verify', project, phase) as Proof;
  expect(proof).toEqual({ phase, inspectionAudits: { verified: expect.any(Number), attention: expect.any(Number) }, evidenceUnchanged: true, guardsRestored: true });
  return proof;
}

function inspectionButton(page: Page, id: string) {
  return page.getByRole('row').filter({ has: page.getByText(id, { exact: true }) }).getByRole('button', { name: 'Inspect retained evidence', exact: true });
}

async function operate(page: Page, button: Locator, expectedStatus = 200): Promise<Response> {
  // Operate the ordinary action; capture its real Livewire response without intercepting or fabricating a request.
  const pending = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/update') && response.request().method() === 'POST');
  await button.focus();
  await button.press('Enter');
  const response = await pending;
  expect(response.status()).toBe(expectedStatus);
  await response.finished();
  return response;
}

async function close(page: Page, returnTo: Locator) {
  const dialog = page.getByRole('dialog');
  await operate(page, dialog.getByRole('button', { name: 'Close', exact: true }));
  await expect(dialog.getByRole('heading', { name: 'Retained test-payment evidence', exact: true })).not.toBeVisible();
  await expect(returnTo).toBeFocused();
}

async function assertPrivate(page: Page, fixture: Fixture, response?: Response) {
  const surfaces = [await page.content(), await page.locator('[wire\\:snapshot]').evaluateAll(elements => elements.map(element => element.getAttribute('wire:snapshot')).join('\n')),
    await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n')), ...(response ? [await response.text()] : [])];
  for (const encoded of surfaces) {
    const surface = encoded.replaceAll('\\/', '/');
    for (const marker of fixture.privateMarkers) expect(surface).not.toContain(marker);
    for (const field of ['owner_key', 'evidence_hash', 'evidence_json', 'raw_payload', 'provider_payment_intent_id', 'idempotency_key', 'storage_path', 'signed_url']) {
      expect(surface).not.toContain(field);
    }
  }
}

test('ordinary operator inspects retained synthetic exceptions and stale authority cannot reopen evidence', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  resetBrowserLoginRateLimit();
  const fixture = fixtureOperation('prepare', testInfo.project.name) as Fixture;
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.goto('/admin/test-payment-exceptions');
    await expect(page).toHaveURL(/\/admin\/login$/);
    await page.getByLabel('Email address', { exact: false }).fill(fixture.operatorEmail);
    await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin(?:\/test-payment-exceptions)?$/);
    expect((await page.goto('/admin/test-payment-exceptions'))?.status()).toBe(200);
    await page.bringToFront();
    const verified = inspectionButton(page, fixture.verifiedId);
    const first = await operate(page, verified);
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Retained test-payment evidence', exact: true })).toBeVisible();
    await expect(dialog.getByText('Stored graph verified', { exact: true })).toBeVisible();
    await expect(dialog.getByText(fixture.orderId, { exact: true })).toBeVisible();
    await expect(dialog.getByText('Original eligibility cutoff (UTC)', { exact: true })).toBeVisible();
    await expect(dialog.getByText('0 grants · 0 exclusive sales', { exact: true })).toBeVisible();
    await expect(dialog.getByText('1 pending event', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Current provider, refund and dispute state: not inspected. Asset and contract file health: not inspected.', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: /^(Submit|Resolve|Refund|Confirm)$/ })).toHaveCount(0);
    await assertPrivate(page, fixture, first);
    const initial = verify(testInfo.project.name, 'first');
    expect(initial.inspectionAudits.verified).toBeGreaterThanOrEqual(1);
    expect(initial.inspectionAudits.attention).toBe(0);
    await page.screenshot({ path: testInfo.outputPath('retained-exception-verified.png'), fullPage: false });
    await close(page, verified);

    const reopened = await operate(page, verified);
    await expect(dialog.getByText('Stored graph verified', { exact: true })).toBeVisible();
    await assertPrivate(page, fixture, reopened);
    expect(verify(testInfo.project.name, 'reopened').inspectionAudits.verified).toBeGreaterThan(initial.inspectionAudits.verified);
    await close(page, verified);

    const attention = inspectionButton(page, fixture.attentionId);
    const corrupt = await operate(page, attention);
    await expect(dialog.getByText('Evidence needs attention', { exact: true })).toBeVisible();
    await expect(dialog.getByText('The complete retained evidence could not be verified. No financial or fulfillment change was made.', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Stored graph verified', { exact: true })).toHaveCount(0);
    await expect(dialog.getByText('Order', { exact: true })).toHaveCount(0);
    await expect(dialog.getByText('0 grants · 0 exclusive sales', { exact: true })).toHaveCount(0);
    await assertPrivate(page, fixture, corrupt);
    expect(verify(testInfo.project.name, 'attention').inspectionAudits.attention).toBeGreaterThanOrEqual(1);
    await page.screenshot({ path: testInfo.outputPath('retained-exception-attention.png'), fullPage: false });
    await close(page, attention);
    expect(errors).toEqual([]);

    expect(fixtureOperation('withdraw', testInfo.project.name)).toEqual({ withdrawn: true });
    const denied = await operate(page, verified, 403);
    await assertPrivate(page, fixture, denied);
    verify(testInfo.project.name, 'withdrawn');
    const deniedPage = await page.goto('/admin/test-payment-exceptions');
    expect(deniedPage?.status()).toBe(403);
    await assertPrivate(page, fixture);
  } finally {
    // Restore only the dedicated synthetic inspector, including after a failed browser assertion.
    expect(fixtureOperation('restore', testInfo.project.name)).toEqual({ restored: true, evidenceUnchanged: true, guardsRestored: true });
    verify(testInfo.project.name, 'restored');
  }
});
