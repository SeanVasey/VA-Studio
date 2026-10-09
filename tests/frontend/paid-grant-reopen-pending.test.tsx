import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, paidRequestTimeouts, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Codex P2 4224824643: an uncertain authorize for an order older than the 20-row index, a hidden tab (which clears the shown
// order but keeps the exact request) and an expired retry left the customer stuck: the order was not in the index, the
// order-reference form is disabled while a request is pending, and without the order the saved status read and the
// set-aside path could never be reached. The pending banner now offers to reopen that request's own order.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const lostId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', otherBatch = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', otherOrder = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [{ id: lineId, originHash: 'a'.repeat(64), position: 1,
  title: 'Original synthetic recording', license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive', features: ['WAV'], deliverableRoles: ['master_wav'], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD', lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete', attempts: 1,
  files: [{ kind: 'master_wav', sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract', sha256: 'c'.repeat(64), sizeBytes: 2048 }] }] };
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const listing = (includeOrder: boolean) => ({ schemaVersion: 1, originLimit: 20, origins: [
  { id: otherBatch, orderId: otherOrder, createdAt: '2026-10-07 02:02:03', provenance: 'synthetic_rehearsal' },
  ...(includeOrder ? [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] : [])] });
const expiredStatus = { status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null,
  history: [{ id: lostId, kind: 'master_wav', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:04:03', status: 'expired', attemptedAt: null }] }] } };
const refused410 = () => response({ error: 'Paid grant request unavailable.', status: 410 }, 410);
const button = (name: string) => screen.getByRole('button', { name });
const idle = () => waitFor(() => expect(screen.getByLabelText('Paid license journey')).not.toHaveAttribute('aria-busy', 'true'));

beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove());
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' }); });

/** An uncertain authorize, then the tab is hidden (clearing the shown order) and shown again, then the index is read. */
async function lostAuthorizeAcrossHiddenTab(fetcher: ReturnType<typeof vi.spyOn>, openOrder: () => Promise<void>) {
  render(<PaidGrantJourney />);
  await openOrder();
  fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
  expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
  await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
  await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
  expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
  expect(button('Retry the exact request')).toBeInTheDocument();
  return fetcher;
}

describe('reopening the order of a pending authorize', () => {
  it('reopens an order older than the index, so an expired request can be set aside after a saved read', async () => {
    let now = 0; vi.spyOn(performance, 'now').mockImplementation(() => now);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ origin: complete })).mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(response(listing(false))).mockImplementationOnce(() => Promise.resolve(refused410()))
      .mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(expiredStatus));
    await lostAuthorizeAcrossHiddenTab(fetcher, async () => {
      // An older order is opened by its reference (finalize is idempotent and returns the retained order).
      fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
      fireEvent.click(button('Prepare licenses for this paid order'));
      await screen.findByLabelText('Retained paid order');
    });
    // The bounded index does not list the order, and the reference form is disabled while the request is pending.
    fireEvent.click(button('Open paid licenses'));
    await screen.findByRole('button', { name: `Open saved order ${otherOrder}` });
    expect(screen.queryByRole('button', { name: `Open saved order ${orderId}` })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Saved order reference')).toBeDisabled();
    // The exact retry is refused: the authorization (if it was ever committed) has expired. The request stays pending.
    fireEvent.click(button('Retry the exact request'));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(4));
    await idle();
    expect(button('Retry the exact request')).toBeInTheDocument();
    // The pending request's own order can be reopened from the banner.
    fireEvent.click(button('Reopen this order'));
    await screen.findByLabelText('Retained paid order');
    expect(fetcher.mock.calls[4][0]).toBe(`/paid-grants/origins/${batchId}`);
    expect(fetcher.mock.calls[4][1]?.method).toBe('GET');
    expect(screen.queryByRole('button', { name: 'Reopen this order' })).not.toBeInTheDocument();
    // A saved read issued the authorize timeout after the retry shows nothing live for that file: it may be set aside.
    now = paidRequestTimeouts.authorize;
    fireEvent.click(button('Refresh preparation and download status'));
    await screen.findByLabelText('Download status for Original synthetic recording');
    await idle();
    const setAside = button('Set aside and request a new authorization');
    expect(setAside).toBeEnabled();
    fireEvent.click(setAside);
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument();
    expect(button('Authorize master_wav for Original synthetic recording')).toBeEnabled();
    expect(screen.getByLabelText('Saved order reference')).toBeEnabled();
    // The request key and nonce were never rendered.
    const sent = JSON.parse(String(fetcher.mock.calls[1][1]?.body));
    expect(document.body.innerHTML).not.toContain(sent.nonce);
    expect(document.body.innerHTML).not.toContain(sent.requestKey);
  });

  it('keeps the newer-order path unchanged: the index reopens it and the banner offers no duplicate once it is shown', async () => {
    let now = 0; vi.spyOn(performance, 'now').mockImplementation(() => now);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing(true))).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(response(listing(true))).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(expiredStatus));
    await lostAuthorizeAcrossHiddenTab(fetcher, async () => {
      fireEvent.click(button('Open paid licenses'));
      fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
      await screen.findByLabelText('Retained paid order');
      expect(screen.queryByRole('button', { name: 'Reopen this order' })).not.toBeInTheDocument();
    });
    fireEvent.click(button('Open paid licenses'));
    fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
    await screen.findByLabelText('Retained paid order');
    expect(screen.queryByRole('button', { name: 'Reopen this order' })).not.toBeInTheDocument();
    now = paidRequestTimeouts.authorize;
    fireEvent.click(button('Refresh preparation and download status'));
    await screen.findByLabelText('Download status for Original synthetic recording');
    await idle();
    expect(button('Set aside and request a new authorization')).toBeEnabled();
    expect(fetcher).toHaveBeenCalledTimes(6);
  });

  it('offers no reopen control for a pending finalize, whose exact retry itself reopens the order', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('lost finalize answer'));
    render(<PaidGrantJourney />);
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(button('Prepare licenses for this paid order'));
    await screen.findByRole('button', { name: 'Retry the exact request' });
    expect(screen.queryByRole('button', { name: 'Reopen this order' })).not.toBeInTheDocument();
  });
});
