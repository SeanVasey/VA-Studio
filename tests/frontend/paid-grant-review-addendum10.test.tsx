import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 10 (3ed91065 completion budgets; 4dcaf4ab per-authorization retry state). Synthetic fixtures only.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const contractId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', masterId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
const line = { id: lineId, originHash: 'a'.repeat(64), position: 1, title: 'Original synthetic recording',
  license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive' as const, features: ['WAV'], deliverableRoles: ['master_wav' as const], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD' as const, lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete' as const, attempts: 1,
  files: [{ kind: 'master_wav' as const, sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract' as const, sha256: 'c'.repeat(64), sizeBytes: 2048 }] };
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [line] };
const listing = () => ({ schemaVersion: 1, originLimit: 20, origins: [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const contractToken = 'A10_CONTRACT_SYNTHETIC_TOKEN'.padEnd(43, 'a'), masterToken = 'A10_MASTER_SYNTHETIC_TOKEN'.padEnd(43, 'b');
const contract = { authorization: { id: contractId, token: contractToken, expiresAt: '2026-10-07 01:03:03', kind: 'contract', filename: `paid-license-${lineId}-contract.pdf`, mimeType: 'application/pdf' } };
const master = { authorization: { id: masterId, token: masterToken, expiresAt: '2026-10-07 01:03:04', kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } };
type State = 'unused' | 'attempted';
const statusOf = (c: State, m: State) => response({ status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: [c, m].filter(s => s === 'attempted').length, maxDownloads: 3, historyLimit: 20,
  renderRetryAllowed: false, renderRetryAfter: null, history: [
    { id: masterId, kind: 'master_wav', issuedAt: '2026-10-07 01:02:04', expiresAt: '2026-10-07 01:03:04', status: m, attemptedAt: m === 'unused' ? null : '2026-10-07 01:02:30' },
    { id: contractId, kind: 'contract', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:03:03', status: c, attemptedAt: c === 'unused' ? null : '2026-10-07 01:02:30' }] }] } });
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); document.querySelectorAll('iframe').forEach(f => f.remove()); });

const button = (name: string) => screen.getByRole('button', { name });
const retries = () => screen.queryAllByRole('button', { name: 'Retry the authorized download' });
const frames = () => Array.from(document.querySelectorAll<HTMLIFrameElement>('iframe[title="Paid license attachment response"]'));
function refuseFrame(frame: HTMLIFrameElement) {
  Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' }, body: { textContent: JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' }) } } });
  frame.dispatchEvent(new Event('load'));
}
async function openOrder(origin: PaidOrigin = complete) {
  fireEvent.click(button('Open paid licenses'));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
  return origin;
}
async function readStatus(fetcher: ReturnType<typeof vi.spyOn>, calls: number) {
  fireEvent.click(button('Refresh preparation and download status'));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(calls));
  await waitFor(() => expect(button('Refresh preparation and download status')).toBeEnabled());
}

describe('review addendum 10', () => {
  // A10-L2 (pre-existing, made more reachable by 3ed91065): when every line is prepared but completion failed (for example a
  // line over its own 300 s bound, 410) or is still running past the 320 s client timeout, the page offers no way to retry
  // completion: the prepare button needs an unfinished line.
  it('offers no prepare control for an order whose lines are all prepared but which is not fulfilled', async () => {
    const unfulfilled: PaidOrigin = { ...complete, fulfilled: false, lines: [{ ...line, files: [] }] };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: unfulfilled }));
    render(<PaidGrantJourney />);
    await openOrder(unfulfilled);
    expect(screen.getByText(/waiting for complete preparation/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Prepare original licenses and files' })).not.toBeInTheDocument();
  });

  it('offers one retry per refused authorization, each submitting its own token, and never the same authorization twice', async () => {
    const forms: string[] = [];
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) { forms.push((this.querySelector('input[name="token"]') as HTMLInputElement).value); });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response(contract)).mockResolvedValueOnce(response(master))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(statusOf('unused', 'unused')).mockResolvedValueOnce(statusOf('unused', 'unused')).mockResolvedValueOnce(statusOf('unused', 'attempted'));
    render(<PaidGrantJourney />);
    await openOrder();
    fireEvent.click(button('Authorize contract for Original synthetic recording'));
    await screen.findByRole('button', { name: 'Download authorized file' });
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Download authorized file' })).toHaveLength(2));
    // Both submitted before either answers.
    fireEvent.click(screen.getAllByRole('button', { name: 'Download authorized file' })[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Download authorized file' }));
    expect(forms).toEqual([contractToken, masterToken]);
    const [contractFrame, masterFrame] = frames();
    // Only the contract has refused: its retry alone is offered (A4-L1 per authorization; the master is still in flight).
    await act(async () => { refuseFrame(contractFrame); });
    // No later request had started, so this refusal clears the page; the order is reopened before the read.
    await openOrder();
    await readStatus(fetcher, 7);
    expect(retries()).toHaveLength(1);
    expect(screen.getByText(/contract\.pdf is still authorized and unused/)).toBeInTheDocument();
    // Now the master refuses too: two buttons, each for its own authorization.
    await act(async () => { refuseFrame(masterFrame); });
    await readStatus(fetcher, 8);
    expect(retries()).toHaveLength(2);
    expect(screen.getByText(/master_wav\.wav is still authorized and unused/)).toBeInTheDocument();
    // Retrying the master submits the master's token; its mark is replaced, so it is not offered again while in flight.
    const masterRetry = screen.getByText(/master_wav\.wav is still authorized/).parentElement!.querySelector('button')!;
    fireEvent.click(masterRetry);
    expect(forms).toEqual([contractToken, masterToken, masterToken]);
    // A read listing the master attempted shows only the contract's retry, once.
    await readStatus(fetcher, 9);
    expect(retries()).toHaveLength(1);
    expect(screen.queryByText(/master_wav\.wav is still authorized/)).not.toBeInTheDocument();
    fireEvent.click(retries()[0]);
    expect(forms).toEqual([contractToken, masterToken, masterToken, contractToken]);
    expect(document.body.innerHTML).not.toContain(contractToken);
    expect(document.body.innerHTML).not.toContain(masterToken);
    // Departure empties the list.
    act(() => { window.dispatchEvent(new Event('pagehide')); });
    expect(retries()).toHaveLength(0);
  });
});
