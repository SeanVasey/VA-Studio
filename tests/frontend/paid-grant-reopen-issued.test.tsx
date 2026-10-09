import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Codex P2 4228014577: an authorization issued for an order older than the 20-row index, then a hidden tab (which clears the
// shown order but keeps the issued authorization in memory), left its Download button unreachable: the index did not list the
// order and the issued entry kept only the batch id, which open() cannot use without the order id. Its short-lived token then
// expired unused. Each issued authorization now keeps its order, and the page offers to reopen it. A refused download kept for
// retry had the same gap after the refusal cleared its order (independent review A16-L1); it keeps its order too.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const authorizationId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', otherBatch = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', otherOrder = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
const token = 'ISSUED_SYNTHETIC_TOKEN'.padEnd(43, 'a');
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [{ id: lineId, originHash: 'a'.repeat(64), position: 1,
  title: 'Original synthetic recording', license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive', features: ['WAV'], deliverableRoles: ['master_wav'], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD', lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete', attempts: 1,
  files: [{ kind: 'master_wav', sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract', sha256: 'c'.repeat(64), sizeBytes: 2048 }] }] };
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const authorization = { authorization: { id: authorizationId, token, expiresAt: '2026-10-07 01:03:03', kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } };
const olderListing = { schemaVersion: 1, originLimit: 20, origins: [{ id: otherBatch, orderId: otherOrder, createdAt: '2026-10-07 02:02:03', provenance: 'synthetic_rehearsal' }] };
const button = (name: string) => screen.getByRole('button', { name });
const reopen = `Reopen order ${orderId} to download its authorized file`;
const unusedStatus = { status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null,
  history: [{ id: authorizationId, kind: 'master_wav', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:03:03', status: 'unused', attemptedAt: null }] }] } };

beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove());
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' }); });

async function hideAndShow() {
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
  await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
  await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
}

describe('reopening the order of an issued authorization', () => {
  it('reopens an order older than the index after a hidden tab, so its issued authorization can still be downloaded', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(authorization))
      .mockResolvedValueOnce(response(olderListing)).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />);
    // An older order is opened by its reference (finalize is idempotent and returns the retained order).
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(button('Prepare licenses for this paid order'));
    await screen.findByLabelText('Retained paid order');
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    await screen.findByRole('button', { name: 'Download authorized file' });
    expect(screen.queryByRole('button', { name: reopen })).not.toBeInTheDocument();

    await hideAndShow();
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Download authorized file' })).not.toBeInTheDocument();
    // The bounded index does not list the order.
    fireEvent.click(button('Open paid licenses'));
    await screen.findByRole('button', { name: `Open saved order ${otherOrder}` });
    expect(screen.queryByRole('button', { name: `Open saved order ${orderId}` })).not.toBeInTheDocument();

    // The issued authorization's own order can be reopened, and its Download button returns.
    fireEvent.click(button(reopen));
    await screen.findByLabelText('Retained paid order');
    expect(fetcher.mock.calls[3][0]).toBe(`/paid-grants/origins/${batchId}`);
    expect(fetcher.mock.calls[3][1]?.method).toBe('GET');
    expect(button('Download authorized file')).toBeEnabled();
    expect(screen.queryByRole('button', { name: reopen })).not.toBeInTheDocument();
    // The token stays in memory only.
    expect(document.body.innerHTML).not.toContain(token);
  });

  it('reopens the order of a refused download kept for retry, so the unused authorization can be retried', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(authorization))
      .mockResolvedValueOnce(response(olderListing)).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(unusedStatus));
    render(<PaidGrantJourney />);
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(button('Prepare licenses for this paid order'));
    await screen.findByLabelText('Retained paid order');
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    // The redemption is refused before anything is recorded; the refusal clears the order and keeps the token for retry.
    const frame = document.querySelector<HTMLIFrameElement>('iframe[title="Paid license attachment response"]')!;
    Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' },
      body: { textContent: JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' }) } } });
    await act(async () => { frame.dispatchEvent(new Event('load')); });
    expect(screen.getByRole('alert')).toHaveTextContent('The download was refused');
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
    fireEvent.click(button('Open paid licenses'));
    await screen.findByRole('button', { name: `Open saved order ${otherOrder}` });
    expect(screen.queryByRole('button', { name: `Open saved order ${orderId}` })).not.toBeInTheDocument();

    fireEvent.click(button(reopen));
    await screen.findByLabelText('Retained paid order');
    expect(fetcher.mock.calls[3][0]).toBe(`/paid-grants/origins/${batchId}`);
    expect(screen.queryByRole('button', { name: reopen })).not.toBeInTheDocument();
    fireEvent.click(button('Refresh preparation and download status'));
    expect(await screen.findByRole('button', { name: 'Retry the authorized download' })).toBeEnabled();
    expect(document.body.innerHTML).not.toContain(token);
  });

  it('shows the reopen control as soon as a hidden download frame reports its refusal, without another render', async () => {
    // Codex 4228172320: the hidden tab clears the page (a render) before the frame answers; the refusal itself must render.
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(authorization));
    render(<PaidGrantJourney />);
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(button('Prepare licenses for this paid order'));
    await screen.findByLabelText('Retained paid order');
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    await hideAndShow();
    expect(screen.queryByRole('button', { name: reopen })).not.toBeInTheDocument();
    const frame = document.querySelector<HTMLIFrameElement>('iframe[title="Paid license attachment response"]')!;
    Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' },
      body: { textContent: JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' }) } } });
    await act(async () => { frame.dispatchEvent(new Event('load')); });
    expect(button(reopen)).toBeEnabled();
    expect(document.body.innerHTML).not.toContain(token);
  });

  it('drops the reopen control once the authorization is submitted, and a denial drops it with the token', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(authorization))
      .mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(authorization))
      .mockResolvedValueOnce(new Response('', { status: 403 }));
    render(<PaidGrantJourney />);
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(button('Prepare licenses for this paid order'));
    await screen.findByLabelText('Retained paid order');
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    await hideAndShow();
    // Submitted: nothing is left to reopen for.
    expect(screen.queryByRole('button', { name: reopen })).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(button('Prepare licenses for this paid order'));
    await screen.findByLabelText('Retained paid order');
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    await screen.findByRole('button', { name: 'Download authorized file' });
    await hideAndShow();
    expect(button(reopen)).toBeEnabled();
    // A denial drops every issued authorization, so the reopen control goes too.
    fireEvent.click(button('Open paid licenses'));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByRole('button', { name: reopen })).not.toBeInTheDocument();
  });
});
