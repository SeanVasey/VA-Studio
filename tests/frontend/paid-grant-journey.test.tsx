import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, paidRequestTimeouts, validPaidOrigin, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', authorizationId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
const origin: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: false, lines: [{ id: lineId, originHash: 'a'.repeat(64), position: 1,
  title: 'Original synthetic recording', license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive', features: ['WAV'], deliverableRoles: ['master_wav'], termsText: 'RETAINED PRIVATE ORIGINAL TERMS <script>private()</script>' },
  declaredName: 'Original private buyer declaration', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD', lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'pending', attempts: 0, files: [] }] };
const complete: PaidOrigin = { ...origin, fulfilled: true, lines: [{ ...origin.lines[0], documentStatus: 'complete', attempts: 1,
  files: [{ kind: 'master_wav', sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract', sha256: 'c'.repeat(64), sizeBytes: 2048 }] }] };
const listing = (saved = true) => ({ schemaVersion: 1, originLimit: 20, origins: saved ? [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] : [] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.useRealTimers(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); });
async function openSaved() {
  fireEvent.click(screen.getByRole('button', { name: 'Open paid licenses' }));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
}

describe('mounted original paid-purpose journey', () => {
  it('clears every private field on a sealed body denial after successful HTTP headers', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response({ error: 'Paid grant request unavailable.', status: 403 }));
    render(<PaidGrantJourney />); await openSaved();
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' } });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh paid licenses' }));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Saved order reference')).not.toBeInTheDocument();
    expect(screen.queryByText(complete.lines[0].license.termsText)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Authorize/ })).not.toBeInTheDocument();
  });
  it('retains original accepted terms, waits for all-line preparation and requires explicit native token POST without storage or URLs', async () => {
    const token = 'PRIVATE_SYNTHETIC_PAID_TOKEN'.padEnd(43, 'a');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin })).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response({ authorization: { id: authorizationId, token, expiresAt: '2026-10-07 01:03:03', kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } }));
    const forms: { action: string; fields: Record<string, string> }[] = [];
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) { forms.push({ action: this.getAttribute('action')!, fields: Object.fromEntries(Array.from(this.querySelectorAll('input'), f => [f.name, f.value])) }); });
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    render(<PaidGrantJourney />); expect(fetcher).not.toHaveBeenCalled(); await openSaved();
    expect(screen.getByText(origin.lines[0].license.termsText)).toBeInTheDocument(); expect(document.querySelector('script')).toBeNull();
    expect(screen.queryByRole('button', { name: /Authorize/ })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Authorize master_wav for Original synthetic recording' }));
    await screen.findByRole('button', { name: 'Download authorized file' }); expect(submit).not.toHaveBeenCalled();
    expect(JSON.parse(String(fetcher.mock.calls[3][1]?.body))).toEqual({ requestKey: expect.any(String), originHash: origin.lines[0].originHash, kind: 'master_wav', nonce: expect.stringMatching(/^[a-f0-9]{64}$/) });
    fireEvent.click(screen.getByRole('button', { name: 'Download authorized file' }));
    expect(forms).toEqual([{ action: `/paid-grants/authorizations/${authorizationId}/redeem`, fields: { token, _token: 'c'.repeat(40) } }]);
    expect(document.querySelector(`input[value="${token}"]`)).toBeNull(); expect(document.querySelector('form[hidden]')).toBeNull();
    expect(fetcher.mock.calls.every(([path, init]) => !String(path).includes(token) && init?.cache === 'no-store' && init.credentials === 'same-origin' && init.redirect === 'error')).toBe(true);
    expect(storage).not.toHaveBeenCalled(); expect(screen.getByRole('alert')).toHaveTextContent('an interrupted attempt can be consumed');
  });
  it('keeps an uncertain exact finalization request and permits deliberate replay only after a fresh saved read', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('PRIVATE TRANSPORT MESSAGE')).mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin }));
    render(<PaidGrantJourney />); fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare licenses for this paid order' }));
    const retry = await screen.findByRole('button', { name: 'Retry the exact request' }); expect(retry).toBeDisabled();
    await screen.findByRole('alert'); const body = fetcher.mock.calls[0][1]?.body;
    fireEvent.click(screen.getByRole('button', { name: 'Open paid licenses' })); await screen.findByRole('button', { name: `Open saved order ${orderId}` });
    expect(retry).toBeEnabled(); fireEvent.click(retry); await screen.findByLabelText('Retained paid order');
    expect(fetcher.mock.calls[2][1]?.body).toBe(body); expect(fetcher.mock.calls[2][0]).toBe(fetcher.mock.calls[0][0]);
    expect(screen.queryByText(/PRIVATE TRANSPORT/)).not.toBeInTheDocument();
  });
  it.each([403, 404, 419])('clears selected original terms, entered references and all capabilities on a %s read denial', async code => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response({}, code));
    render(<PaidGrantJourney />); await openSaved(); fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' } });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh paid licenses' })); await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Saved order reference')).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(origin.lines[0].declaredName); expect(document.body.textContent).not.toContain(origin.lines[0].license.termsText);
    expect(screen.getByRole('button', { name: 'Open paid licenses' })).toBeDisabled();
  });
  it('ignores a late authorization after departure and never submits a file automatically', async () => {
    let finish!: (r: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockImplementationOnce(() => new Promise(r => { finish = r; }));
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    render(<PaidGrantJourney />); await openSaved(); fireEvent.click(screen.getByRole('button', { name: 'Authorize contract for Original synthetic recording' }));
    await waitFor(() => expect(finish).toBeDefined()); await act(async () => { window.dispatchEvent(new Event('pagehide')); finish(response({ authorization: { token: 'LATE_PRIVATE_TOKEN' } })); });
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Download authorized file' })).not.toBeInTheDocument(); expect(submit).not.toHaveBeenCalled();
    // Departure drops the uncertain request too; nothing is left to replay.
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument();
  });
  it('keeps the exact authorize replay when the tab is only hidden, while clearing everything shown and ignoring the late answer', async () => {
    let finish!: (r: Response) => void; let signal!: AbortSignal;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockImplementationOnce((_, init) => { signal = init!.signal!; return new Promise(r => { finish = r; }); })
      .mockResolvedValueOnce(response(listing()))
      .mockResolvedValueOnce(response({ authorization: { id: authorizationId, token: 'RECOVERED_SYNTHETIC_TOKEN'.padEnd(43, 'a'), expiresAt: '2026-10-07 01:03:03', kind: 'contract', filename: `paid-license-${lineId}-contract.pdf`, mimeType: 'application/pdf' } }));
    const visibility = vi.spyOn(document, 'visibilityState', 'get');
    try {
      render(<PaidGrantJourney />); await openSaved(); fireEvent.click(screen.getByRole('button', { name: 'Authorize contract for Original synthetic recording' }));
      await waitFor(() => expect(finish).toBeDefined());
      visibility.mockReturnValue('hidden');
      await act(async () => { document.dispatchEvent(new Event('visibilitychange')); finish(response({ authorization: { token: 'LATE_PRIVATE_TOKEN' } })); });
      expect(signal.aborted).toBe(true);
      expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument(); expect(document.body.textContent).not.toContain(complete.lines[0].license.termsText);
      expect(screen.queryByRole('button', { name: 'Download authorized file' })).not.toBeInTheDocument();
      expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
      visibility.mockReturnValue('visible'); document.dispatchEvent(new Event('visibilitychange'));
      const retry = screen.getByRole('button', { name: 'Retry the exact request' }); expect(retry).toBeDisabled();
      fireEvent.click(screen.getByRole('button', { name: 'Open paid licenses' })); await waitFor(() => expect(retry).toBeEnabled());
      fireEvent.click(retry); await screen.findByRole('button', { name: 'Download authorized file' });
      // The replay is the same request: same path and byte-identical body (request key and nonce), so the server returns the same authorization.
      expect(fetcher.mock.calls[4][0]).toBe(fetcher.mock.calls[2][0]); expect(fetcher.mock.calls[4][1]?.body).toBe(fetcher.mock.calls[2][1]?.body);
    } finally { visibility.mockRestore(); }
  });
  it('uses token-free saved lease admission before retrying a claimed original and displays consumed attempts truthfully', async () => {
    const claimed: PaidOrigin = { ...origin, lines: [{ ...origin.lines[0], documentStatus: 'claimed', attempts: 1 }] };
    const status = { schemaVersion: 1, originId: batchId, fulfilled: false, lines: [{ id: lineId, attemptCount: 1, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: true, renderRetryAfter: '2026-10-07 01:07:03',
      history: [{ id: authorizationId, kind: 'contract', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:03:03', status: 'attempted', attemptedAt: '2026-10-07 01:02:05' }] }] };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: claimed })).mockResolvedValueOnce(response({ status })).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />); await openSaved(); expect(screen.getByRole('button', { name: 'Prepare original licenses and files' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh preparation and download status' })); await screen.findByLabelText('Download status for Original synthetic recording');
    expect(screen.getByText(/1\/3 committed download attempts/)).toBeInTheDocument(); expect(screen.getByText(/contract \/ attempted/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Prepare original licenses and files' })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' })); await screen.findByRole('button', { name: 'Authorize contract for Original synthetic recording' });
  });
  it('rejects cross-purpose or malformed private graph extensions and incomplete-order file projections', () => {
    expect(validPaidOrigin(complete)).toBe(true); expect(validPaidOrigin(origin)).toBe(true);
    expect(validPaidOrigin({ ...complete, ownerKey: 'PRIVATE' })).toBe(false); expect(validPaidOrigin({ ...complete, purpose: 'free-license-grant' })).toBe(false);
    expect(validPaidOrigin({ ...complete, fulfilled: false })).toBe(false); expect(validPaidOrigin({ ...complete, lines: [complete.lines[0], complete.lines[0]] })).toBe(false);
    expect(validPaidOrigin({ ...complete, lines: [{ ...complete.lines[0], files: [{ ...complete.lines[0].files[0], storagePath: 'PRIVATE' }] }] })).toBe(false);
  });
});

describe('per-operation request timeouts', () => {
  const authorization = { id: authorizationId, token: 'REPLAYED_SYNTHETIC_PAID_TOKEN'.padEnd(43, 'a'), expiresAt: '2026-10-07 01:03:03', kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' };
  const pendingFetch = (signals: AbortSignal[]) => (_: unknown, init?: RequestInit) => { signals.push(init!.signal!); return new Promise<Response>(() => {}); };
  it('sizes each abort from the measured server work instead of one 20 s limit', async () => {
    expect(paidRequestTimeouts).toEqual({ read: 80_000, finalize: 80_000, authorize: 80_000, document: 320_000 });
    // Reads share the 60 s server budget (PaidGrantReads::index, PaidGrantCommands::run) and the 5 s session-lock wait.
    expect(paidRequestTimeouts.read).toBeGreaterThanOrEqual(60_000 + 5_000 + 15_000);
    const timers = vi.spyOn(window, 'setTimeout');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin })).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response({ status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null, history: [] }] } }))
      .mockResolvedValueOnce(response({ authorization })).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />); await openSaved();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await screen.findByRole('button', { name: 'Authorize master_wav for Original synthetic recording' });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh preparation and download status' })); await screen.findByLabelText('Download status for Original synthetic recording');
    fireEvent.click(screen.getByRole('button', { name: 'Authorize master_wav for Original synthetic recording' })); await screen.findByRole('button', { name: 'Download authorized file' });
    fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare licenses for this paid order' })); await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(6));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Prepare licenses for this paid order' })).toBeDisabled());
    // Only the component's request aborts are longer than the testing library's own 1 s polling bound.
    const aborts = timers.mock.calls.map(([, delay]) => delay ?? 0).filter(delay => delay > 1_000);
    expect(fetcher.mock.calls.map(([path]) => String(path))).toEqual(['/paid-grants/index', `/paid-grants/origins/${batchId}`, `/paid-grants/origins/${batchId}/document`,
      `/paid-grants/origins/${batchId}/downloads`, `/paid-grants/origins/${batchId}/lines/${lineId}/authorize`, `/paid-grants/orders/${orderId}/finalize`]);
    expect(aborts).toEqual([80_000, 80_000, 320_000, 80_000, 80_000, 80_000]);
  });
  it('keeps a measured-length document preparation open and still aborts it at its own bound', async () => {
    const signals: AbortSignal[] = [];
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin })).mockImplementationOnce(pendingFetch(signals));
    render(<PaidGrantJourney />); await openSaved();
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await act(() => vi.advanceTimersByTimeAsync(100_000)); expect(signals[0].aborted).toBe(false);
    await act(() => vi.advanceTimersByTimeAsync(219_999)); expect(signals[0].aborted).toBe(false);
    await act(() => vi.advanceTimersByTimeAsync(1)); expect(signals[0].aborted).toBe(true);
    vi.useRealTimers();
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
  });
  it('recovers the same server authorization when a timed-out authorize is deliberately replayed', async () => {
    const signals: AbortSignal[] = [];
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockImplementationOnce(pendingFetch(signals))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ authorization }));
    render(<PaidGrantJourney />); await openSaved();
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    fireEvent.click(screen.getByRole('button', { name: 'Authorize master_wav for Original synthetic recording' }));
    // The measured native authorize took 12-20 s; the old unconditional 20 s abort discarded the only token here.
    await act(() => vi.advanceTimersByTimeAsync(79_999)); expect(signals[0].aborted).toBe(false);
    await act(() => vi.advanceTimersByTimeAsync(1)); expect(signals[0].aborted).toBe(true);
    vi.useRealTimers();
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    const retry = screen.getByRole('button', { name: 'Retry the exact request' }); expect(retry).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Authorize master_wav for Original synthetic recording' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh paid licenses' })); await waitFor(() => expect(retry).toBeEnabled());
    fireEvent.click(retry); await screen.findByRole('button', { name: 'Download authorized file' });
    expect(screen.getByText(`${authorization.filename}; expires ${authorization.expiresAt} UTC.`)).toBeInTheDocument();
    const [first, replay] = [fetcher.mock.calls[2], fetcher.mock.calls[4]];
    // Same path and byte-identical body: the server's (account, requestKey) replay returns the same row and token, never a second one.
    expect(replay[0]).toBe(first[0]); expect(replay[1]?.body).toBe(first[1]?.body);
    expect(JSON.parse(String(replay[1]?.body))).toEqual({ requestKey: expect.stringMatching(/^[a-f0-9-]{36}$/), originHash: origin.lines[0].originHash, kind: 'master_wav', nonce: expect.stringMatching(/^[a-f0-9]{64}$/) });
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument();
  });
  it('lets a lost authorize be set aside only after a later saved read shows no live authorization of that file', async () => {
    const statusWith = (history: unknown[]) => response({ status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null, history }] } });
    const lost = { id: authorizationId, kind: 'master_wav', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:03:03', attemptedAt: null };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(statusWith([])).mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(statusWith([{ ...lost, status: 'expired' }]))
      .mockResolvedValueOnce(statusWith([{ ...lost, status: 'unused' }])).mockResolvedValueOnce(statusWith([{ ...lost, status: 'expired' }]))
      .mockResolvedValueOnce(response({ authorization: { ...authorization, id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' } }));
    let now = 0; vi.spyOn(performance, 'now').mockImplementation(() => now);
    render(<PaidGrantJourney />); await openSaved();
    const authorize = () => screen.getByRole('button', { name: 'Authorize master_wav for Original synthetic recording' });
    const refreshStatus = async (calls: number) => { fireEvent.click(screen.getByRole('button', { name: 'Refresh preparation and download status' })); await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(calls)); await waitFor(() => expect(authorize().closest('section')).not.toHaveAttribute('aria-busy', 'true')); };
    // A status read taken before the request says nothing about it.
    await refreshStatus(3);
    fireEvent.click(authorize()); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    const setAside = screen.getByRole('button', { name: 'Set aside and request a new authorization' });
    expect(setAside).toBeDisabled(); expect(authorize()).toBeDisabled();
    // A read issued before the client authorize timeout has passed since the request was sent cannot prove it finished:
    // the server may still commit it after this read.
    now = paidRequestTimeouts.authorize - 1; await refreshStatus(5); expect(setAside).toBeDisabled();
    now = paidRequestTimeouts.authorize;
    // The lost request may have committed a live authorization: only the exact retry may recover it.
    await refreshStatus(6);
    expect(screen.getByRole('button', { name: 'Retry the exact request' })).toBeEnabled(); expect(setAside).toBeDisabled(); expect(authorize()).toBeDisabled();
    // Once a later read shows that file has no live authorization on the line, the customer may drop the old request.
    await refreshStatus(7); expect(setAside).toBeEnabled();
    fireEvent.click(setAside);
    expect(screen.getByRole('alert')).toHaveTextContent('set aside');
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument(); expect(authorize()).toBeEnabled();
    fireEvent.click(authorize()); await screen.findByRole('button', { name: 'Download authorized file' });
    const [first, fresh] = [JSON.parse(String(fetcher.mock.calls[3][1]?.body)), JSON.parse(String(fetcher.mock.calls[7][1]?.body))];
    expect(fetcher.mock.calls[7][0]).toBe(fetcher.mock.calls[3][0]); expect(fresh.requestKey).not.toBe(first.requestKey); expect(fresh.nonce).not.toBe(first.nonce);
    expect(fresh).toEqual({ requestKey: expect.stringMatching(/^[a-f0-9-]{36}$/), originHash: origin.lines[0].originHash, kind: 'master_wav', nonce: expect.stringMatching(/^[a-f0-9]{64}$/) });
  });
  it('does not treat a full status window as proof that a pushed-out authorization expired', async () => {
    const statusWith = (history: unknown[]) => response({ status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: null, history }] } });
    // Twenty newer authorizations of another file from other tabs fill the window; none of them says anything about expiry.
    const newer = (status: 'unused' | 'attempted' | 'expired', at = 0) => Array.from({ length: 20 }, (_, i) => ({ id: `ffffffff-ffff-4fff-8fff-${String(i).padStart(12, '0')}`, kind: 'contract',
      issuedAt: '2026-10-07 01:09:03', expiresAt: '2026-10-07 01:19:03', status: i === at ? status : 'attempted', attemptedAt: i === at && status !== 'attempted' ? null : '2026-10-07 01:09:05' }));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(statusWith(newer('unused'))).mockResolvedValueOnce(statusWith(newer('attempted'))).mockResolvedValueOnce(statusWith(newer('expired', 19)));
    let now = 0; vi.spyOn(performance, 'now').mockImplementation(() => now);
    render(<PaidGrantJourney />); await openSaved();
    const authorize = () => screen.getByRole('button', { name: 'Authorize master_wav for Original synthetic recording' });
    const refreshStatus = async (calls: number) => { fireEvent.click(screen.getByRole('button', { name: 'Refresh preparation and download status' })); await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(calls)); await waitFor(() => expect(authorize().closest('section')).not.toHaveAttribute('aria-busy', 'true')); };
    fireEvent.click(authorize()); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    now = paidRequestTimeouts.authorize;
    const setAside = screen.getByRole('button', { name: 'Set aside and request a new authorization' });
    await refreshStatus(4); expect(setAside).toBeDisabled();
    await refreshStatus(5); expect(setAside).toBeDisabled();
    // The oldest entry in the full window has expired, so every older authorization on the line (the lost one included) has too.
    await refreshStatus(6); expect(setAside).toBeEnabled();
  });
  it('never offers to set aside an uncertain finalization', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('lost finalize answer'));
    render(<PaidGrantJourney />); fireEvent.change(screen.getByLabelText('Saved order reference'), { target: { value: orderId } });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare licenses for this paid order' }));
    await screen.findByRole('button', { name: 'Retry the exact request' });
    expect(screen.queryByRole('button', { name: 'Set aside and request a new authorization' })).not.toBeInTheDocument();
  });
});
