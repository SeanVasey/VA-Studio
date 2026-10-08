import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, paidRequestTimeouts, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 14 probe (untracked): adversarial cases for the round 22 "Reopen this order" control.
// Synthetic fixtures only.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const otherBatch = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', otherOrder = 'ffffffff-ffff-4fff-8fff-ffffffffffff', foreignOrder = '99999999-9999-4999-8999-999999999999';
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [{ id: lineId, originHash: 'a'.repeat(64), position: 1,
  title: 'Original synthetic recording', license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive', features: ['WAV'], deliverableRoles: ['master_wav'], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD', lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete', attempts: 1,
  files: [{ kind: 'master_wav', sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract', sha256: 'c'.repeat(64), sizeBytes: 2048 }] }] };
const other: PaidOrigin = { ...complete, id: otherBatch, orderId: otherOrder };
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const listing = { schemaVersion: 1, originLimit: 20, origins: [
  { id: otherBatch, orderId: otherOrder, createdAt: '2026-10-07 02:02:03', provenance: 'synthetic_rehearsal' },
  { id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] };
const expiredStatus = { status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null,
  history: [{ id: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', kind: 'master_wav', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:04:03', status: 'expired', attemptedAt: null }] }] } };
const button = (name: string) => screen.getByRole('button', { name });
const query = (name: string) => screen.queryByRole('button', { name });
const idle = () => waitFor(() => expect(screen.getByLabelText('Paid license journey')).not.toHaveAttribute('aria-busy', 'true'));

beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); });

type Route = (init?: RequestInit) => Promise<Response>;
/** Per-path queues; the last answer repeats. Records method and body of every call. */
function server(routes: Record<string, Route[]>) {
  const calls: { path: string; method: string; body: unknown }[] = [];
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const path = String(input); calls.push({ path, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : null });
    const queue = routes[path]; if (!queue?.length) throw new Error(`unexpected ${path}`);
    return (queue.length > 1 ? queue.shift()! : queue[0])(init);
  });
  return calls;
}
const ok = (body: unknown): Route => () => Promise.resolve(response(body));
const lost: Route = () => Promise.reject(new TypeError('network'));
const authorizePath = `/paid-grants/origins/${batchId}/lines/${lineId}/authorize`;

/** A lost authorize on the pending order, then another order is opened, so the banner offers to reopen the pending one. */
async function pendingWhileAnotherOrderIsOpen(routes: Record<string, Route[]>) {
  const reopen = routes[`/paid-grants/origins/${batchId}`] ?? [ok({ origin: complete })];
  const calls = server({ '/paid-grants/index': [ok(listing)], [authorizePath]: [lost], [`/paid-grants/origins/${otherBatch}`]: [ok({ origin: other })], ...routes,
    // The first open of the pending order is normal; the reopen gets the case's own answer.
    [`/paid-grants/origins/${batchId}`]: [ok({ origin: complete }), ...reopen] });
  render(<PaidGrantJourney />);
  fireEvent.click(button('Open paid licenses'));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
  fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
  expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
  fireEvent.click(screen.getByRole('button', { name: /paid licenses$/ }));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${otherOrder}` }));
  await screen.findByText(`Order ${otherOrder}`);
  await idle();
  expect(button('Reopen this order')).toBeEnabled();
  return calls;
}

describe('review addendum 14: reopening a pending authorize order', () => {
  it('A: reopening sends one GET only, keeps the identical replay, and a saved read under 80 s after the send cannot set it aside', async () => {
    let now = 0; vi.spyOn(performance, 'now').mockImplementation(() => now);
    const calls = await pendingWhileAnotherOrderIsOpen({ [`/paid-grants/origins/${batchId}`]: [ok({ origin: complete })], [`/paid-grants/origins/${batchId}/downloads`]: [ok(expiredStatus)] });
    const sent = calls.find(c => c.path === authorizePath)!.body as Record<string, string>;
    const before = calls.length;
    fireEvent.click(button('Reopen this order'));
    await screen.findByText(`Order ${orderId}`); await idle();
    expect(calls.slice(before).map(c => `${c.method} ${c.path}`)).toEqual([`GET /paid-grants/origins/${batchId}`]);
    // A saved read issued 79 s after the send cannot qualify the set-aside, reopened or not.
    now = paidRequestTimeouts.authorize - 1_000;
    fireEvent.click(button('Refresh preparation and download status'));
    await idle();
    expect(button('Set aside and request a new authorization')).toBeDisabled();
    // The exact retry still sends the identical request key and nonce: reopening neither lost nor re-minted the replay.
    fireEvent.click(button('Refresh paid licenses'));
    await idle();
    fireEvent.click(button('Retry the exact request'));
    await waitFor(() => expect(calls.filter(c => c.path === authorizePath)).toHaveLength(2));
    const retried = calls.filter(c => c.path === authorizePath)[1].body;
    expect(retried).toEqual(sent);
    expect(document.body.innerHTML).not.toContain(sent.nonce);
    expect(document.body.innerHTML).not.toContain(sent.requestKey);
  });

  it('B: an answer for a different order is rejected; no order is shown and the replay is kept', async () => {
    await pendingWhileAnotherOrderIsOpen({ [`/paid-grants/origins/${batchId}`]: [ok({ origin: { ...complete, orderId: foreignOrder } })] });
    fireEvent.click(button('Reopen this order'));
    await idle();
    expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
    expect(screen.queryByText(`Order ${foreignOrder}`)).not.toBeInTheDocument();
    expect(button('Retry the exact request')).toBeInTheDocument();
    expect(button('Reopen this order')).toBeEnabled();
  });

  it('C: the control is disabled while a read is in flight', async () => {
    let release: (r: Response) => void = () => {};
    await pendingWhileAnotherOrderIsOpen({ [`/paid-grants/origins/${batchId}`]: [() => new Promise<Response>(resolve => { release = resolve; })] });
    fireEvent.click(button('Reopen this order'));
    await waitFor(() => expect(button('Reopen this order')).toBeDisabled());
    expect(button('Retry the exact request')).toBeDisabled();
    await act(async () => { release(response({ origin: complete })); });
    await screen.findByText(`Order ${orderId}`);
    expect(query('Reopen this order')).not.toBeInTheDocument();
  });

  it('D: a 404 on reopen is a denial and drops the replay, like any read', async () => {
    await pendingWhileAnotherOrderIsOpen({ [`/paid-grants/origins/${batchId}`]: [() => Promise.resolve(response({}, 404))] });
    fireEvent.click(button('Reopen this order'));
    await idle();
    expect(screen.getByRole('alert')).toHaveTextContent('Access changed');
    expect(query('Retry the exact request')).not.toBeInTheDocument();
    expect(query('Reopen this order')).not.toBeInTheDocument();
  });
});
