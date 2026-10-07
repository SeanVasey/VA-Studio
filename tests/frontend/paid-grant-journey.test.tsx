import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, validPaidOrigin, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

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
