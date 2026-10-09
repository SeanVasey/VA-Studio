import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 1: the client-only "set aside a lost authorize" gate (e479a8cb, df9fd823) and the hidden-tab
// replay (c0a68b2d). Synthetic fixtures only.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const otherBatch = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', otherOrder = 'ffffffff-ffff-4fff-8fff-ffffffffffff', otherLine = '99999999-9999-4999-8999-999999999999';
const line = { id: lineId, originHash: 'a'.repeat(64), position: 1, title: 'Original synthetic recording',
  license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive' as const, features: ['WAV'], deliverableRoles: ['master_wav' as const], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD' as const, lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete' as const, attempts: 1,
  files: [{ kind: 'master_wav' as const, sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract' as const, sha256: 'c'.repeat(64), sizeBytes: 2048 }] };
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [line] };
const other: PaidOrigin = { ...complete, id: otherBatch, orderId: otherOrder, lines: [{ ...line, id: otherLine, originHash: 'd'.repeat(64) }] };
const listing = () => ({ schemaVersion: 1, originLimit: 20, origins: [
  { id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' },
  { id: otherBatch, orderId: otherOrder, createdAt: '2026-10-07 01:02:04', provenance: 'synthetic_rehearsal' }] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const statusFor = (o: PaidOrigin, history: unknown[] = []) => response({ status: { schemaVersion: 1, originId: o.id, fulfilled: true,
  lines: [{ id: o.lines[0].id, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null, history }] } });
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); });

const button = (name: string) => screen.getByRole('button', { name });
const setAside = () => button('Set aside and request a new authorization');
const authorizeMaster = () => button('Authorize master_wav for Original synthetic recording');
async function open(id: string, oid: string, calls: number, fetcher: ReturnType<typeof vi.spyOn>) {
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${oid}` }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(calls));
  await screen.findByLabelText('Retained paid order');
  expect(id).toBeTruthy();
}
async function refreshStatus(calls: number, fetcher: ReturnType<typeof vi.spyOn>) {
  fireEvent.click(button('Refresh preparation and download status'));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(calls));
  await waitFor(() => expect(button('Refresh preparation and download status')).toBeEnabled());
}

describe('review addendum 1: set-aside gate for a lost authorize', () => {
  // The gate also needs the saved read to be issued at least the client authorize timeout (80 s) after the request was last
  // sent (fix for A1-L1, integration owner). `now` drives performance.now(); restoreAllMocks in the shared setup resets it.
  let now = 0;
  beforeEach(() => { now = 0; vi.spyOn(performance, 'now').mockImplementation(() => now); });
  // A1-L1 (i). A failed retry of the pending authorize is a new transmission that may itself commit, so the earlier saved
  // status read must no longer satisfy the gate. Reviewer's it.fails reproduction, now a regression test.
  it('a failed exact retry invalidates the earlier saved status read for setting the request aside', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(statusFor(complete))
      .mockRejectedValueOnce(new Error('lost retry answer'));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster()); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    now = 80_000;
    await refreshStatus(4, fetcher);
    expect(setAside()).toBeEnabled();
    // The customer retries the exact request instead; its answer is lost too.
    fireEvent.click(button('Retry the exact request'));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(5));
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(setAside()).toBeDisabled();
  });

  it('a saved status read of a different origin cannot satisfy the gate', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(response({ origin: other })).mockResolvedValueOnce(statusFor(other));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster()); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    now = 80_000;
    await open(otherBatch, otherOrder, 4, fetcher);
    await refreshStatus(5, fetcher);
    expect(setAside()).toBeDisabled();
    expect(button('Retry the exact request')).toBeInTheDocument();
  });

  it('a stale status object for the pending origin is cleared by open and refresh, so the gate needs a new read', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(statusFor(complete))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster()); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    now = 80_000;
    await refreshStatus(4, fetcher);
    expect(setAside()).toBeEnabled();
    fireEvent.click(button('Refresh paid licenses'));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(5));
    expect(setAside()).toBeDisabled();
    await open(batchId, orderId, 6, fetcher);
    expect(setAside()).toBeDisabled();
  });

  // A1-I4 (informational, pre-existing): the leave path discards the pending request without any saved read, after which a
  // reopened page may authorize again. The server's per-line 3-per-60 s limit, not this client gate, bounds the duplicate.
  it('documents that pagehide drops the pending authorize without a saved status read', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster()); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(authorizeMaster()).toBeDisabled();
    window.dispatchEvent(new Event('pagehide'));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument());
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 5, fetcher);
    expect(authorizeMaster()).toBeEnabled();
  });
});

describe('review addendum 1: a hidden tab keeps the exact replay (c0a68b2d)', () => {
  const authorization = { authorization: { id: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', token: 'LATE_PRIVATE_TOKEN'.padEnd(43, 'a'), expiresAt: '2026-10-07 01:03:03',
    kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } };
  let visibility: ReturnType<typeof vi.spyOn>;
  beforeEach(() => { visibility = vi.spyOn(document, 'visibilityState', 'get'); });
  const hide = async () => { visibility.mockReturnValue('hidden'); await act(async () => { document.dispatchEvent(new Event('visibilitychange')); }); };
  const show = async () => { visibility.mockReturnValue('visible'); await act(async () => { document.dispatchEvent(new Event('visibilitychange')); }); };

  // (a) The kept replay is memory-only and never rendered, and a denial on return drops it.
  it('renders no request key, nonce or private origin field while hidden, and a denial after return drops the kept replay', async () => {
    let body = '';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockImplementationOnce((_, init) => { body = String(init!.body); return new Promise(() => {}); })
      .mockResolvedValueOnce(response({ error: 'Paid grant request unavailable.', status: 403 }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster());
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
    await hide(); await show();
    const sent = JSON.parse(body) as { requestKey: string; nonce: string };
    for (const secret of [sent.requestKey, sent.nonce, line.license.termsText, line.declaredName, line.originHash]) expect(document.body.innerHTML).not.toContain(secret);
    expect(button('Retry the exact request')).toBeDisabled();
    expect(setAside()).toBeDisabled();
    fireEvent.click(button('Open paid licenses'));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Set aside and request a new authorization' })).not.toBeInTheDocument();
  });

  // (b) The aborted request's late answer is ignored even when it arrives after the customer returned and started a new read.
  it('never commits the aborted authorize answer, even when it resolves during a later read', async () => {
    let finish!: (r: Response) => void, later!: (r: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockImplementationOnce(() => new Promise(r => { finish = r; }))
      .mockImplementationOnce(() => new Promise(r => { later = r; }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster());
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
    await hide(); await show();
    fireEvent.click(button('Open paid licenses'));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(4));
    await act(async () => { finish(response(authorization)); });
    await act(async () => { later(response(listing())); });
    await screen.findByRole('button', { name: `Open saved order ${orderId}` });
    expect(screen.queryByRole('button', { name: 'Download authorized file' })).not.toBeInTheDocument();
    expect(document.body.innerHTML).not.toContain('LATE_PRIVATE_TOKEN');
    expect(button('Retry the exact request')).toBeEnabled();
  });

  // (c) A1-L1 (ii) reproduction. The hidden tab abandons the authorize long before the server's 60 s budget, so the server may
  // still commit it after the customer's next status read. Expected behaviour: set-aside stays disabled until the read was
  // issued after the original could no longer commit (for example, paidRequestTimeouts.authorize after its last transmission).
  // Reviewer's it.fails reproduction, now a regression test.
  it('does not offer set-aside on a status read taken while the abandoned authorize may still commit', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockImplementationOnce(() => new Promise(() => {}))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(statusFor(complete));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 2, fetcher);
    fireEvent.click(authorizeMaster());
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
    await hide(); await show();
    fireEvent.click(button('Open paid licenses'));
    await open(batchId, orderId, 5, fetcher);
    await refreshStatus(6, fetcher);
    expect(setAside()).toBeDisabled();
  });
});
