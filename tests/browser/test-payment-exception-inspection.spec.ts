import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { expect, test, type Locator, type Page, type Response, type TestInfo } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse } from './publication-fixture';

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

async function operate(page: Page, button: Locator, method: Parameters<typeof actionResponse>[1], actionName?: string, expectedStatus = 200): Promise<Response> {
  // Retain the record context emitted by the real table button; never fabricate an action request.
  const encodedContext = method === 'mountAction'
    ? (await button.getAttribute('wire:click'))?.match(/JSON\.parse\('([^']+)'\)/)?.[1] : undefined;
  if (method === 'mountAction') expect(encodedContext).toBeDefined();
  const expectedContext = encodedContext === undefined ? undefined : JSON.parse(JSON.parse(`"${encodedContext}"`));
  const pending = actionResponse(page, method, actionName);
  await button.focus();
  await button.press('Enter');
  const response = await pending;
  if (expectedContext !== undefined) {
    const payload = response.request().postDataJSON() as { components?: { calls?: { method: string; params: unknown[] }[] }[] };
    const call = payload.components?.flatMap(component => component.calls ?? [])
      .find(call => call.method === method && call.params[0] === actionName);
    expect(call?.params[2]).toEqual(expectedContext);
  }
  expect(response.status()).toBe(expectedStatus);
  await response.finished();
  return response;
}

async function close(page: Page, returnTo: Locator) {
  const dialog = page.getByRole('dialog');
  // Filament also labels the header icon Close; operate the ordinary footer cancel action.
  const cancel = dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Close', exact: true });
  await expect(cancel).toHaveCount(1);
  await expect(cancel).toBeVisible();
  await expect(cancel).toBeEnabled();
  await cancel.scrollIntoViewIfNeeded();
  await expect(cancel).toBeInViewport({ ratio: 1 });
  await operate(page, cancel, 'unmountAction');
  await expect(dialog.getByRole('heading', { name: 'Retained test-payment evidence', exact: true })).not.toBeVisible();
  await expect(returnTo).toBeFocused();
}

async function captureInspection(page: Page, dialog: Locator, verdict: 'Stored graph verified' | 'Evidence needs attention', phase: 'verified' | 'attention', testInfo: TestInfo) {
  const window = dialog.locator('.fi-modal-window');
  const heading = dialog.getByRole('heading', { name: 'Retained test-payment evidence', exact: true });
  const result = dialog.getByText(verdict, { exact: true });
  // Visibility alone accepts opacity zero. Wait for the ordinary modal's natural paint state.
  await expect(window).toHaveCount(1);
  await expect(window).toBeVisible();
  await expect(window).toHaveCSS('opacity', '1');
  await heading.scrollIntoViewIfNeeded();
  await expect(heading).toBeInViewport({ ratio: 1 });
  await expect(result).toBeInViewport({ ratio: 1 });
  const layout = {
    schemaVersion: 1,
    viewport: page.viewportSize(),
    window: await window.boundingBox(),
    heading: await heading.boundingBox(),
    verdict: await result.boundingBox(),
    windowOpaque: await window.evaluate(element => getComputedStyle(element).opacity === '1'),
  };
  expect(layout.windowOpaque).toBe(true);
  for (const bounds of [layout.viewport, layout.window, layout.heading, layout.verdict]) expect(bounds).not.toBeNull();
  const path = testInfo.outputPath(`retained-exception-${phase}-layout.json`);
  writeFileSync(path, JSON.stringify(layout, null, 2), 'utf8');
  await testInfo.attach(`retained-exception-${phase}-layout`, { path, contentType: 'application/json' });
  await page.screenshot({ path: testInfo.outputPath(`retained-exception-${phase}.png`), fullPage: false });
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
    const first = await operate(page, verified, 'mountAction', 'inspectEvidence');
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
    await captureInspection(page, dialog, 'Stored graph verified', 'verified', testInfo);
    await close(page, verified);

    const reopened = await operate(page, verified, 'mountAction', 'inspectEvidence');
    await expect(dialog.getByText('Stored graph verified', { exact: true })).toBeVisible();
    await assertPrivate(page, fixture, reopened);
    expect(verify(testInfo.project.name, 'reopened').inspectionAudits.verified).toBeGreaterThan(initial.inspectionAudits.verified);
    await close(page, verified);

    const attention = inspectionButton(page, fixture.attentionId);
    const corrupt = await operate(page, attention, 'mountAction', 'inspectEvidence');
    await expect(dialog.getByText('Evidence needs attention', { exact: true })).toBeVisible();
    await expect(dialog.getByText('The complete retained evidence could not be verified. No financial or fulfillment change was made.', { exact: true })).toBeVisible();
    await expect(dialog.getByText('Stored graph verified', { exact: true })).toHaveCount(0);
    await expect(dialog.getByText('Order', { exact: true })).toHaveCount(0);
    await expect(dialog.getByText('0 grants · 0 exclusive sales', { exact: true })).toHaveCount(0);
    await assertPrivate(page, fixture, corrupt);
    expect(verify(testInfo.project.name, 'attention').inspectionAudits.attention).toBeGreaterThanOrEqual(1);
    await captureInspection(page, dialog, 'Evidence needs attention', 'attention', testInfo);
    await close(page, attention);

    const row = page.getByRole('row').filter({ has: page.getByText(fixture.verifiedId, { exact: true }) });
    await operate(page, row.getByRole('button', { name: 'Record operational status', exact: true }), 'mountAction', 'recordDisposition');
    await expect(dialog.getByRole('heading', { name: 'Record test exception review', exact: true })).toBeVisible();
    await dialog.getByLabel('Operational status', { exact: false }).selectOption('acknowledged');
    await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Submit', exact: true }), 'callMountedAction');
    await expect(page.getByText('Operational status recorded', { exact: true })).toBeVisible();
    verify(testInfo.project.name, 'disposition');

    await operate(page, row.getByRole('button', { name: 'Operational history', exact: true }), 'mountAction', 'operationHistory');
    await expect(dialog.getByRole('heading', { name: 'Test exception operational history', exact: true })).toBeVisible();
    await expect(dialog).toContainText('acknowledged');
    await expect(dialog).toContainText('Fulfillment remains blocked');
    await assertPrivate(page, fixture);
    verify(testInfo.project.name, 'operation_history');
    await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Close', exact: true }), 'unmountAction');

    // The browser fixture intentionally has no provider credential or enabled processing policy.
    await operate(page, row.getByRole('button', { name: 'Check current test payment', exact: true }), 'mountAction', 'reconcilePayment');
    await expect(dialog.getByRole('heading', { name: 'Check current test payment', exact: true })).toBeVisible();
    await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Submit', exact: true }), 'callMountedAction');
    await expect(page.getByText('Test payment check was not confirmed', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Check current test payment', exact: true })).toBeVisible();
    await assertPrivate(page, fixture);
    verify(testInfo.project.name, 'processing_unavailable');
    await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Cancel', exact: true }), 'unmountAction');
    expect(errors).toEqual([]);

    expect(fixtureOperation('withdraw', testInfo.project.name)).toEqual({ withdrawn: true });
    const denied = await operate(page, verified, 'mountAction', 'inspectEvidence', 403);
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
