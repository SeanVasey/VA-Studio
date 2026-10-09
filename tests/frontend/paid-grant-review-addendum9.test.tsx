import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 9 (1b500ac6, Codex round 15): frame answers no longer depend on the request generation.
// Documents which status reads can now enable the retry. Synthetic fixtures only.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', authId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
const line = { id: lineId, originHash: 'a'.repeat(64), position: 1, title: 'Original synthetic recording',
  license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive' as const, features: ['WAV'], deliverableRoles: ['master_wav' as const], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD' as const, lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete' as const, attempts: 1,
  files: [{ kind: 'master_wav' as const, sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract' as const, sha256: 'c'.repeat(64), sizeBytes: 2048 }] };
const complete: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [line] };
const listing = () => ({ schemaVersion: 1, originLimit: 20, origins: [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const token = 'A9_KEPT_SYNTHETIC_TOKEN'.padEnd(43, 'a');
const auth = { authorization: { id: authId, token, expiresAt: '2026-10-07 01:03:03', kind: 'contract', filename: `paid-license-${lineId}-contract.pdf`, mimeType: 'application/pdf' } };
const statusWith = (state: 'unused' | 'attempted') => response({ status: { schemaVersion: 1, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: state === 'unused' ? 0 : 1, maxDownloads: 3, historyLimit: 20,
  renderRetryAllowed: false, renderRetryAfter: null, history: [{ id: authId, kind: 'contract', issuedAt: '2026-10-07 01:02:03', expiresAt: '2026-10-07 01:03:03', status: state, attemptedAt: state === 'unused' ? null : '2026-10-07 01:02:30' }] }] } });
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); document.querySelectorAll('iframe').forEach(f => f.remove()); });

const button = (name: string) => screen.getByRole('button', { name });
const retry = () => screen.queryByRole('button', { name: 'Retry the authorized download' });
const statusButton = () => button('Refresh preparation and download status');
const frames = () => Array.from(document.querySelectorAll<HTMLIFrameElement>('iframe[title="Paid license attachment response"]'));
function answer(frame: HTMLIFrameElement, body: string) {
  Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' }, body: { textContent: body } } });
  frame.dispatchEvent(new Event('load'));
}
async function downloadOnce(fetcher: ReturnType<typeof vi.spyOn>) {
  const view = render(<PaidGrantJourney />);
  fireEvent.click(button('Open paid licenses'));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
  fireEvent.click(button('Authorize contract for Original synthetic recording'));
  fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
  return view;
}

describe('review addendum 9: refusals recorded independently of the request generation', () => {
  // A9-I3: a read taken after the submission but before its refusal arrived now enables the retry as soon as the refusal is
  // recorded, without a read taken after the refusal (Addendum 5 (a) required one). The retry still needs the submission's own
  // refusal, so it cannot race the original; the server re-proves the authorization on the retry.
  it('offers the retry from a read that completed before a non-current refusal arrived', async () => {
    const forms: string[] = [];
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) { forms.push((this.querySelector('input[name="token"]') as HTMLInputElement).value); });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(statusWith('unused'));
    const view = await downloadOnce(fetcher);
    const frame = frames()[0];
    fireEvent.click(statusButton());
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(4)); await waitFor(() => expect(statusButton()).toBeEnabled());
    // In-flight original: no retry while its frame has not answered (A4-L1 gate intact).
    expect(retry()).not.toBeInTheDocument();
    await act(async () => { answer(frame, JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' })); });
    expect(frame.isConnected).toBe(false);
    expect(screen.queryByText(/download was refused/)).not.toBeInTheDocument();
    // A9-I4, now fixed (Codex 4228172320): the refusal itself renders, so the retry is offered at once from the pre-refusal
    // read, and a later render keeps it.
    expect(retry()).toBeInTheDocument();
    view.rerender(<PaidGrantJourney />);
    expect(retry()).toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(4);
    fireEvent.click(retry()!);
    expect(forms).toEqual([token, token]);
    expect(document.body.innerHTML).not.toContain(token);
    expect(retry()).not.toBeInTheDocument();
  });

  it('offers the retry from a read still in flight when the refusal arrived, and not when that read lists the authorization attempted', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    for (const state of ['unused', 'attempted'] as const) {
      let release: (value: Response) => void = () => {};
      const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
        .mockImplementationOnce(() => new Promise<Response>(resolve => { release = resolve; }));
      await downloadOnce(fetcher);
      const frame = frames()[0];
      fireEvent.click(statusButton());
      await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(4));
      await act(async () => { answer(frame, JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' })); });
      expect(retry()).not.toBeInTheDocument();
      await act(async () => { release(statusWith(state)); });
      await waitFor(() => expect(statusButton()).toBeEnabled());
      if (state === 'unused') expect(retry()).toBeInTheDocument(); else expect(retry()).not.toBeInTheDocument();
      // Departure drops the kept mark either way.
      act(() => { window.dispatchEvent(new Event('pagehide')); });
      expect(retry()).not.toBeInTheDocument();
      document.body.innerHTML = '';
      vi.restoreAllMocks();
      vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    }
  });

  it('records nothing for a non-refusal answer that arrives after a later request started, and shows no message', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response(auth))
      .mockResolvedValueOnce(statusWith('unused'));
    await downloadOnce(fetcher);
    const frame = frames()[0];
    fireEvent.click(statusButton());
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(4)); await waitFor(() => expect(statusButton()).toBeEnabled());
    await act(async () => { answer(frame, '<html>502 Bad Gateway</html>'); });
    expect(frame.isConnected).toBe(true);
    expect(retry()).not.toBeInTheDocument();
    expect(screen.queryByText(/could not be confirmed/)).not.toBeInTheDocument();
    await act(async () => { answer(frame, JSON.stringify({ code: 'SOMETHING_ELSE' })); });
    expect(retry()).not.toBeInTheDocument();
  });
});
