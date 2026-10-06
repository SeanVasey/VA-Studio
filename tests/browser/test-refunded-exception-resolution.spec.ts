import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { expect, test, type Locator, type Page, type Request, type Response } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { actionResponse } from './publication-fixture';

type Fixture = { refundedId: string; partialId: string; operatorEmail: string; capability: string; privateMarkers: string[] };
type Proof = {
  phase: string; resolutionId: string | null; historyCount: number; partialHistoryCount: number; providerReads: number;
  originalsUnchanged: boolean; noRightsOrMoneyEffects: boolean; guardsUnchanged: boolean;
};
const componentName = 'App\\Filament\\Resources\\TestPaymentExceptionResource\\Pages\\ListTestPaymentExceptions';
const livewireUrl = /\/livewire(?:-[A-Za-z0-9]+)?\/update$/;

function fixtureOperation(mode: 'prepare' | 'verify', project: string, phase?: string) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-test-refund-resolution.php', mode, project, ...(phase ? [phase] : [])], {
    cwd: process.cwd(), env: process.env, encoding: 'utf8', timeout: 90_000, stdio: 'pipe',
  }));
}

function verify(project: string, phase: 'prepared' | 'released' | 'replayed' | 'partial'): Proof {
  const proof = fixtureOperation('verify', project, phase) as Proof;
  expect(proof).toEqual({ phase, resolutionId: phase === 'prepared' ? null : expect.any(String),
    historyCount: phase === 'prepared' ? 0 : 2, partialHistoryCount: phase === 'partial' ? 2 : 0,
    providerReads: phase === 'prepared' ? 0 : phase === 'partial' ? 8 : 4,
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
  expect(await response.finished()).toBeNull();
  return response;
}

async function assertPrivate(page: Page, fixture: Fixture, responseBody: string) {
  const surfaces = [await page.content(), responseBody,
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

function plain(value: unknown): unknown {
  if (Array.isArray(value)) {
    if (value.length === 2 && typeof value[1] === 'object' && value[1] !== null && 's' in value[1]) return plain(value[0]);
    return value.map(plain);
  }
  if (value !== null && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, entry]) => [key, plain(entry)]));
  return value;
}

function requestReview(payload: { components?: { snapshot: string }[] }) {
  expect(payload.components).toHaveLength(1);
  const snapshot = JSON.parse(payload.components![0].snapshot) as { data: Record<string, unknown>; memo: { name: string } };
  expect(snapshot.memo.name).toBe(componentName);
  const data = plain(snapshot.data) as { mountedActions: { name: string; data: { sequence: number; request_id: string } }[] };
  expect(data.mountedActions).toHaveLength(1);
  expect(data.mountedActions[0].name).toBe('resolveFullRefund');
  expect(data.mountedActions[0].data.request_id).toMatch(/^[a-f0-9-]{36}$/);
  return data.mountedActions[0].data;
}

test('operator releases a fully refunded exception, retries a lost success and rejects a partial refund', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  resetBrowserLoginRateLimit();
  const fixture = fixtureOperation('prepare', testInfo.project.name) as Fixture;
  const errors: string[] = [];
  const externalRequests: string[] = [];
  const failedRequests: { url: string; error: string | null; deliberatelyAborted: boolean }[] = [];
  let abortedRequest: Request | undefined;
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => {
    if (new URL(request.url()).origin !== 'http://127.0.0.1:8173') externalRequests.push(request.url());
  });
  page.on('requestfailed', request => failedRequests.push({ url: request.url(), error: request.failure()?.errorText ?? null, deliberatelyAborted: request === abortedRequest }));
  await page.goto('/admin/test-payment-exceptions');
  await expect(page).toHaveURL(/\/admin\/login$/);
  await page.getByLabel('Email address', { exact: false }).fill(fixture.operatorEmail);
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin(?:\/test-payment-exceptions)?$/);
  expect((await page.goto('/admin/test-payment-exceptions'))?.status()).toBe(200);
  await page.evaluate(({ capability, expectedName }) => {
    type Request = { uri: string; payload: { components?: { snapshot: string }[] }; options: { headers: Record<string, string> } };
    const livewire = (window as unknown as { Livewire: { interceptRequest: (callback: (input: { request: Request }) => void) => void } }).Livewire;
    livewire.interceptRequest(({ request }) => {
      const url = new URL(request.uri, location.href);
      const components = request.payload.components;
      if (url.origin !== location.origin || ! /^\/livewire(?:-[A-Za-z0-9]+)?\/update$/.test(url.pathname)
        || components?.length !== 1 || JSON.parse(components[0].snapshot).memo.name !== expectedName) return;
      request.options.headers['X-Vasey-Refund-Fixture'] = capability;
    });
  }, { capability: fixture.capability, expectedName: componentName });

  const row = page.getByRole('row').filter({ has: page.getByText(fixture.refundedId, { exact: true }) });
  const release = row.getByRole('button', { name: 'Verify full refund and release', exact: true });
  const dialog = page.getByRole('dialog');
  await operate(page, release, 'mountAction', 'resolveFullRefund');
  await expect(dialog.getByRole('heading', { name: 'Verify full refund and release', exact: true })).toBeVisible();
  await expect(dialog).toContainText('No refund is sent');
  await expect(dialog).toContainText('Grants and original contracts are unchanged');
  const prepared = verify(testInfo.project.name, 'prepared');
  const submit = dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Submit', exact: true });
  let originalReview: { sequence: number; request_id: string } | undefined;
  let lostResponseBody = '';
  let intercepted = 0;
  // The server executes the real signed Livewire request. Drop only its successful response,
  // creating genuine client uncertainty without changing snapshots or substituting domain results.
  await page.route(livewireUrl, async route => {
    const payload = route.request().postDataJSON() as { components?: { snapshot: string; calls?: { method: string }[] }[] };
    if (!payload.components?.some(component => component.calls?.some(call => call.method === 'callMountedAction'))) {
      await route.continue();
      return;
    }
    intercepted++;
    expect(intercepted).toBe(1);
    originalReview = requestReview(payload);
    expect(originalReview.sequence).toBe(0);
    const actualResponse = await route.fetch();
    expect(actualResponse.status()).toBe(200);
    lostResponseBody = await actualResponse.text();
    expect(lostResponseBody).toContain('Refunded test resources released');
    await actualResponse.dispose();
    abortedRequest = route.request();
    await route.abort('connectionreset');
  });
  const failed = page.waitForEvent('requestfailed', { predicate: request => livewireUrl.test(request.url()) });
  await submit.focus();
  await submit.press('Enter');
  await failed;
  await page.unroute(livewireUrl);
  await expect(submit).toBeEnabled();
  await expect(dialog.getByRole('heading', { name: 'Verify full refund and release', exact: true })).toBeVisible();
  await expect(page.getByText('Refunded test resources released', { exact: true })).toHaveCount(0);
  const released = verify(testInfo.project.name, 'released');
  await assertPrivate(page, fixture, lostResponseBody);
  const replay = await operate(page, submit, 'callMountedAction');
  expect(requestReview(replay.request().postDataJSON())).toEqual(originalReview);
  await expect(page.getByText('Refunded test resources released', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading', { name: 'Verify full refund and release', exact: true })).not.toBeVisible();
  await assertPrivate(page, fixture, await replay.text());
  const replayed = verify(testInfo.project.name, 'replayed');
  expect(replayed.resolutionId).toBe(released.resolutionId);

  const history = await operate(page, row.getByRole('button', { name: 'Refund resolution history', exact: true }), 'mountAction', 'refundResolutionHistory');
  await expect(dialog.getByRole('heading', { name: 'Test refund resolution history', exact: true })).toBeVisible();
  await expect(dialog).toContainText(released.resolutionId!);
  await expect(dialog).toContainText('Current history sequence: 2');
  await expect(dialog).toContainText('fulfillment remains blocked');
  await expect(dialog.locator('.fi-modal-window')).toHaveCSS('opacity', '1');
  await assertPrivate(page, fixture, await history.text());
  await page.screenshot({ path: testInfo.outputPath('refunded-exception-history.png'), fullPage: false });
  await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Close', exact: true }), 'unmountAction');

  const partialRow = page.getByRole('row').filter({ has: page.getByText(fixture.partialId, { exact: true }) });
  await operate(page, partialRow.getByRole('button', { name: 'Verify full refund and release', exact: true }), 'mountAction', 'resolveFullRefund');
  const partial = await operate(page, submit, 'callMountedAction');
  await expect(page.getByText('Full refund was not established', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading', { name: 'Verify full refund and release', exact: true })).toBeVisible();
  await assertPrivate(page, fixture, await partial.text());
  const partialProof = verify(testInfo.project.name, 'partial');
  await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Cancel', exact: true }), 'unmountAction');
  await operate(page, partialRow.getByRole('button', { name: 'Refund resolution history', exact: true }), 'mountAction', 'refundResolutionHistory');
  await expect(dialog).toContainText('No verified resource resolution is recorded in this review');
  await operate(page, dialog.locator('.fi-modal-footer-actions').getByRole('button', { name: 'Close', exact: true }), 'unmountAction');
  expect(intercepted).toBe(1);
  expect(failedRequests).toHaveLength(1);
  expect(failedRequests[0].url).toMatch(livewireUrl);
  expect(failedRequests[0].deliberatelyAborted).toBe(true);
  expect(failedRequests[0].error).toEqual(expect.any(String));
  expect(failedRequests[0].error!.length).toBeGreaterThan(0);
  expect(errors).toEqual([]);
  expect(externalRequests).toEqual([]);
  const proofPath = testInfo.outputPath('refunded-exception-resolution-proof.json');
  writeFileSync(proofPath, JSON.stringify({ schemaVersion: 1, prepared, released, replayed, partial: partialProof,
    sameRequestRetried: true, successfulResponseDeliberatelyDropped: true, expectedNetworkFailures: 1,
    networkFailures: failedRequests, pageErrors: errors, externalRequests }, null, 2));
  await testInfo.attach('refunded-exception-resolution-proof', { path: proofPath, contentType: 'application/json' });
});
