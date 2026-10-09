import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 7 (a7e8c496): frames kept across a hidden tab, a pending replay kept across a refusal. Synthetic fixtures only.
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
const downloads = () => screen.queryAllByRole('button', { name: 'Download authorized file' });
const issue = (id: string, token: string, expiresAt = '2026-10-07 01:03:03') => response({ authorization: { id, token, expiresAt, kind: 'master_wav',
  filename: `paid-license-${lineId}-master_wav.wav`, mimeType: 'audio/wav' } });
const tokenA = 'A6_FIRST_PRIVATE_TOKEN'.padEnd(43, 'a'), tokenB = 'A6_SECOND_PRIVATE_TOKEN'.padEnd(43, 'b');
const idA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', idB = '88888888-8888-4888-8888-888888888888';
async function openOrder(oid: string) {
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${oid}` }));
  await screen.findByLabelText('Retained paid order');
}

const frames = () => Array.from(document.querySelectorAll<HTMLIFrameElement>('iframe[title="Paid license attachment response"]'));
function refuseFrame(frame: HTMLIFrameElement) {
  Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' }, body: { textContent: JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' }) } } });
  frame.dispatchEvent(new Event('load'));
}
const ids = ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', '88888888-8888-4888-8888-888888888888', '77777777-7777-4777-8777-777777777777', '66666666-6666-4666-8666-666666666666'];
const tokens = ids.map((_, i) => `A7_PRIVATE_TOKEN_${i}`.padEnd(43, 'a'));

describe('review addendum 7', () => {
  afterEach(() => { document.querySelectorAll('iframe').forEach(f => f.remove()); });

  // (a) Hidden frames carry no private field; departure still removes them.
  it('keeps an in-flight download frame through a hidden tab without rendering any private field, and pagehide removes it', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const visibility = vi.spyOn(document, 'visibilityState', 'get');
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(issue(ids[0], tokens[0]));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    const frame = frames()[0];
    visibility.mockReturnValue('hidden'); await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    expect(frame.isConnected).toBe(true);
    expect(frame.hidden).toBe(true); expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
    for (const secret of [tokens[0], line.license.termsText, line.declaredName, line.originHash, 'paid-license-']) expect(document.body.innerHTML).not.toContain(secret);
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
    window.dispatchEvent(new Event('pagehide'));
    expect(frame.isConnected).toBe(false);
  });

  // (b) The kept replay survives a download refusal but stays inert until a successful read, and a denial drops it.
  it('keeps an unrelated pending authorize across a download refusal, inert until a read, and a denied read drops it', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(issue(ids[0], tokens[0]))
      .mockRejectedValueOnce(new Error('lost authorize answer'))
      .mockResolvedValueOnce(response({ error: 'Paid grant request unavailable.', status: 403 }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording')); await screen.findByRole('button', { name: 'Download authorized file' });
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    await screen.findByRole('button', { name: 'Retry the exact request' });
    fireEvent.click(button('Download authorized file'));
    await act(async () => { refuseFrame(frames()[0]); });
    expect(button('Retry the exact request')).toBeDisabled();
    expect(button('Set aside and request a new authorization')).toBeDisabled();
    fireEvent.click(button('Open paid licenses'));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument();
  });

  // (c) A7-L1 reproduction. With several issued authorizations on screen (round 11), a fourth submission evicts the oldest
  // frame (cap of 3) even while that submission has not reported back; in a browser removing it aborts that request before
  // headers, and the server may still commit its redemption. Expected: an unresolved frame is never evicted.
  // Reviewer's it.fails reproduction, now a regression test (fixed by the integration owner: frames are never evicted).
  it('never evicts an unresolved download frame when a fourth download is submitted', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }));
    ids.forEach((id, i) => fetcher.mockResolvedValueOnce(issue(id, tokens[i])));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    for (let i = 1; i <= 4; i++) {
      fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
      await waitFor(() => expect(downloads()).toHaveLength(i));
    }
    fireEvent.click(downloads()[0]);
    const first = frames()[0];
    for (let i = 0; i < 3; i++) fireEvent.click(downloads()[0]);
    expect(frames()).toHaveLength(4); // no eviction: every submission keeps its frame until it reports back or the page is left
    expect(first.isConnected).toBe(true);
  });
});
