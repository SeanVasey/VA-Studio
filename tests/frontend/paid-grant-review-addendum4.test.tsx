import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 4 (4cb35010): the kept token and "Retry the authorized download". Synthetic fixtures only.
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

describe('review addendum 4: retry of a refused download', () => {
  // (a) The kept token never reaches the DOM, and a denial drops it so no retry can be offered afterwards.
  it('never renders the kept token and drops it on a denial even if a later read would list it unused', async () => {
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(response({ error: 'Paid grant request unavailable.', status: 403 }));
    await downloadOnce(fetcher);
    expect(submit).toHaveBeenCalledTimes(1);
    expect(document.body.innerHTML).not.toContain(token);
    // The status read is denied: refuse() clears the page and the kept token.
    fireEvent.click(button('Refresh preparation and download status'));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(retry()).not.toBeInTheDocument();
    expect(document.body.innerHTML).not.toContain(token);
    expect(screen.queryByRole('button', { name: 'Open paid licenses' })).toBeDisabled();
  });

  // (b) A4-L1 reproduction. The redeem POST runs in an iframe, outside call(), so `busy` stays false and a status read can be
  // taken while the original submission is still being prepared on the server (before its redemption commits). That read
  // lists the authorization unused and the retry is offered. Expected: no retry until the submission's own frame has
  // reported its refusal. Reviewer's it.fails reproduction, now a regression test (fixed by the integration owner).
  it('does not offer the retry while the original submission has not reported back', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(unusedStatus());
    await downloadOnce(fetcher);
    fireEvent.click(button('Refresh preparation and download status'));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(4));
    await waitFor(() => expect(button('Refresh preparation and download status')).toBeEnabled());
    expect(retry()).not.toBeInTheDocument();
  });

  // (c) A4-L1 consequence, fixed: a refusal used to clear every frame, which in a browser aborts another submission still
  // waiting for its response (the server may then commit that redemption without delivering the file). A refusal now
  // removes only its own frame. Reviewer's documenting case, rewritten as a regression test by the integration owner.
  it('a refusal removes only its own frame, never another download still waiting for its response', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const master = { authorization: { ...auth.authorization, id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', kind: 'master_wav', filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(response(master));
    await downloadOnce(fetcher);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    // The newer master submission is refused while the contract submission is still waiting for its response.
    const [pending, refused] = frames(); expect(frames()).toHaveLength(2);
    await act(async () => { refuseFrame(refused); });
    expect(refused.isConnected).toBe(false); expect(pending.isConnected).toBe(true); expect(frames()).toEqual([pending]);
    expect(screen.getByRole('alert')).toHaveTextContent('refused'); expect(document.body.innerHTML).not.toContain(token);
  });
});
