import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 5 (97801ddf): the retry offered only after the submission's own refusal. Synthetic fixtures only.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', authId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
const line = { id: lineId, originHash: 'a'.repeat(64), position: 1, title: 'Original synthetic recording',
  license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive' as const, features: ['WAV'], deliverableRoles: ['master_wav' as const], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD' as const, lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete' as const, attempts: 1,
  files: [{ kind: 'master_wav' as const, sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract' as const, sha256: 'c'.repeat(64), sizeBytes: 2048 }] };
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [line] };
const listing = () => ({ schemaVersion: 1, originLimit: 20, origins: [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const token = 'A4_KEPT_SYNTHETIC_TOKEN'.padEnd(43, 'a');
const auth = { authorization: { id: authId, token, expiresAt: '2026-10-07 01:03:03', kind: 'contract', filename: `paid-license-${lineId}-contract.pdf`, mimeType: 'application/pdf' } };
const unusedStatus = () => response({ status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: 0, maxDownloads: 3, historyLimit: 20,
  renderRetryAllowed: false, renderRetryAfter: null, history: [{ id: authId, kind: 'contract', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:03:03', status: 'unused', attemptedAt: null }] }] } });
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); document.querySelectorAll('iframe').forEach(f => f.remove()); });

const button = (name: string) => screen.getByRole('button', { name });
const retry = () => screen.queryByRole('button', { name: 'Retry the authorized download' });
const frames = () => Array.from(document.querySelectorAll<HTMLIFrameElement>('iframe[title="Paid license attachment response"]'));
function refuseFrame(frame: HTMLIFrameElement) {
  Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' }, body: { textContent: JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' }) } } });
  frame.dispatchEvent(new Event('load'));
}
async function downloadOnce(fetcher: ReturnType<typeof vi.spyOn>) {
  render(<PaidGrantJourney />);
  fireEvent.click(button('Open paid licenses'));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
  fireEvent.click(button('Authorize contract for Original synthetic recording'));
  fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
}

describe('review addendum 5: retry only after its own refusal', () => {
  const statusButton = () => button('Refresh preparation and download status');
  async function readStatus(fetcher: ReturnType<typeof vi.spyOn>, calls: number) {
    fireEvent.click(statusButton());
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(calls));
    await waitFor(() => expect(statusButton()).toBeEnabled());
  }

  it('offers the retry after the own refusal and a later read, hides it while the retry is in flight, and needs that retry to refuse again', async () => {
    const forms: string[] = [];
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) { forms.push((this.querySelector('input[name="token"]') as HTMLInputElement).value); });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(unusedStatus()).mockResolvedValueOnce(unusedStatus());
    await downloadOnce(fetcher);
    await act(async () => { refuseFrame(frames()[0]); });
    expect(frames()).toHaveLength(0);
    // The refusal cleared the page; the order is reopened and status read after the refusal.
    fireEvent.click(button('Open paid licenses'));
    fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` })); await screen.findByLabelText('Retained paid order');
    await readStatus(fetcher, 6);
    fireEvent.click(retry()!);
    expect(forms).toEqual([token, token]);
    // The retry is a new submission that has not reported: a read now lists it unused, but no retry is offered.
    await readStatus(fetcher, 7);
    expect(retry()).not.toBeInTheDocument();
    expect(document.body.innerHTML).not.toContain(token);
  });

  // (c) Conservative: the refusal is ignored once any later request has started (generation guard), so no retry is offered and
  // the frame stays until the page is cleared. The customer authorizes again instead.
  it('ignores a refusal that arrives after a status read started during the submission and never offers the retry', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(unusedStatus()).mockResolvedValueOnce(unusedStatus());
    await downloadOnce(fetcher);
    const frame = frames()[0];
    await readStatus(fetcher, 4);
    await act(async () => { refuseFrame(frame); });
    expect(frame.isConnected).toBe(true);
    expect(screen.queryByText(/download was refused/)).not.toBeInTheDocument();
    await readStatus(fetcher, 5);
    expect(retry()).not.toBeInTheDocument();
    // Denial or departure still removes it.
    window.dispatchEvent(new Event('pagehide'));
    expect(frame.isConnected).toBe(false);
  });

  it('a newer submission replaces the refused mark, so an older refused authorization is no longer offered', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const master = { authorization: { ...auth.authorization, id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(response(master)).mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(unusedStatus());
    await downloadOnce(fetcher);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    const [contract, masterFrame] = frames();
    // Not ignored: no request started after the master submission. The contract frame was submitted before the master
    // authorize call, so its late refusal is ignored by the generation guard; the master is refused here instead.
    await act(async () => { refuseFrame(masterFrame); });
    expect(contract.isConnected).toBe(true);
    // The read lists the contract authorization unused, but the kept (refused) mark is the master's, which it does not list.
    fireEvent.click(button('Open paid licenses'));
    fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` })); await screen.findByLabelText('Retained paid order');
    await readStatus(fetcher, 7);
    expect(screen.getByText(/contract \/ unused/)).toBeInTheDocument();
    expect(retry()).not.toBeInTheDocument();
  });
});
