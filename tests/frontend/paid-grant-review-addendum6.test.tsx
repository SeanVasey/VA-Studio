import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 6 (f785c494): issued authorizations kept per order until submitted. Synthetic fixtures only.
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

describe('review addendum 6: issued authorizations', () => {
  // (a) Never in the DOM, never under another order, and dropped by a denial.
  it('shows issued tokens only under their own order, never renders them, and a denial drops every one', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(issue(idA, tokenA)).mockResolvedValueOnce(issue(idB, tokenB))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: other }))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response({ error: 'Paid grant request unavailable.', status: 403 }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording')); await screen.findByRole('button', { name: 'Download authorized file' });
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    await waitFor(() => expect(downloads()).toHaveLength(2));
    expect(document.body.innerHTML).not.toContain(tokenA); expect(document.body.innerHTML).not.toContain(tokenB);
    // Another order of the same account shows none of them.
    fireEvent.click(button('Refresh paid licenses')); await openOrder(otherOrder);
    expect(downloads()).toHaveLength(0);
    fireEvent.click(button('Refresh paid licenses')); await openOrder(orderId);
    expect(downloads()).toHaveLength(2);
    // A denied read drops them all; nothing is offered afterwards.
    fireEvent.click(button('Refresh preparation and download status'));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(downloads()).toHaveLength(0);
    expect(document.body.innerHTML).not.toContain(tokenA); expect(document.body.innerHTML).not.toContain(tokenB);
  });

  // (b) A hidden tab clears everything shown; the issued tokens stay in memory only and reappear after a fresh order read.
  it('renders nothing while hidden and shows the kept tokens again only after the order is read again', async () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get');
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(issue(idA, tokenA)).mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording')); await screen.findByRole('button', { name: 'Download authorized file' });
    visibility.mockReturnValue('hidden'); await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    expect(screen.queryByLabelText('Retained paid order')).not.toBeInTheDocument();
    expect(downloads()).toHaveLength(0);
    expect(document.body.innerHTML).not.toContain(tokenA); expect(document.body.innerHTML).not.toContain('paid-license-');
    visibility.mockReturnValue('visible'); await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    expect(downloads()).toHaveLength(1);
  });

  // (c) Informational: an issued authorization past its expiry still shows a Download button (the server answers 410 and
  // records nothing); a redeem-frame refusal, which may be a 403, does not drop the other issued tokens.
  it('documents that an expired issued authorization stays listed and a frame refusal keeps the other issued tokens', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(issue(idA, tokenA, '2000-01-01 00:00:00')).mockResolvedValueOnce(issue(idB, tokenB))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }));
    render(<PaidGrantJourney />);
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    fireEvent.click(button('Authorize master_wav for Original synthetic recording')); await screen.findByRole('button', { name: 'Download authorized file' });
    fireEvent.click(button('Authorize master_wav for Original synthetic recording'));
    await waitFor(() => expect(downloads()).toHaveLength(2));
    expect(screen.getByText(/expires 2000-01-01 00:00:00 UTC/)).toBeInTheDocument();
    fireEvent.click(downloads()[1]);
    const frame = document.querySelector<HTMLIFrameElement>('iframe[title="Paid license attachment response"]')!;
    Object.defineProperty(frame, 'contentDocument', { value: { location: { href: 'http://localhost/paid-grants/authorizations/x/redeem' }, body: { textContent: JSON.stringify({ code: 'PAID_GRANT_UNAVAILABLE' }) } } });
    await act(async () => { frame.dispatchEvent(new Event('load')); });
    fireEvent.click(button('Open paid licenses')); await openOrder(orderId);
    expect(downloads()).toHaveLength(1);
    expect(screen.getByText(/expires 2000-01-01 00:00:00 UTC/)).toBeInTheDocument();
    frame.remove();
  });
});
