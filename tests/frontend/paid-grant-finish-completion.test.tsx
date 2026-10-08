import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review A10-L2 / condition C13 (page part): an order whose lines are all prepared but which is not fulfilled
// (completion refused, or still running past the 320 s client timeout) can be finished from the page. Synthetic fixtures only.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const line = { id: lineId, originHash: 'a'.repeat(64), position: 1, title: 'Original synthetic recording',
  license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive' as const, features: ['WAV'], deliverableRoles: ['master_wav' as const], termsText: 'SYNTHETIC TERMS' },
  declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD' as const, lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus: 'complete' as const, attempts: 1,
  files: [{ kind: 'master_wav' as const, sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract' as const, sha256: 'c'.repeat(64), sizeBytes: 2048 }] };
const fulfilled: PaidOrigin = { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled: true, lines: [line] };
const prepared: PaidOrigin = { ...fulfilled, fulfilled: false, lines: [{ ...line, files: [] }] };
const listing = () => ({ schemaVersion: 1, originLimit: 20, origins: [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const finish = 'Finish preparing this order', prepare = 'Prepare original licenses and files';
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove()); });
async function openOrder() {
  fireEvent.click(screen.getByRole('button', { name: 'Open paid licenses' }));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
}

describe('finishing a prepared but unfulfilled paid order', () => {
  it('offers to finish an order whose lines are all prepared, sends the same document request and shows the fulfilled order', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: prepared }))
      .mockResolvedValueOnce(response({ origin: fulfilled }));
    render(<PaidGrantJourney />);
    await openOrder();
    expect(screen.getByText(/waiting for complete preparation/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: prepare })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: finish }));
    await screen.findByRole('button', { name: 'Authorize master_wav for Original synthetic recording' });
    expect(fetcher).toHaveBeenCalledTimes(3);
    expect(fetcher.mock.calls[2][0]).toBe(`/paid-grants/origins/${batchId}/document`);
    expect(fetcher.mock.calls[2][1]?.method).toBe('POST');
    expect(JSON.parse(String(fetcher.mock.calls[2][1]?.body))).toEqual({});
    expect(screen.getByText(/Complete-order preparation is recorded/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: finish })).not.toBeInTheDocument();
  });

  it('keeps the control after a lost answer, so the order stays recoverable', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: prepared }))
      .mockRejectedValueOnce(new Error('SYNTHETIC CLIENT TIMEOUT'));
    render(<PaidGrantJourney />);
    await openOrder();
    fireEvent.click(screen.getByRole('button', { name: finish }));
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    await waitFor(() => expect(screen.getByRole('button', { name: finish })).toBeEnabled());
  });

  it('offers no finish control for a fulfilled order or while any line is unprepared', async () => {
    const unprepared: PaidOrigin = { ...prepared, lines: [{ ...line, documentStatus: 'failed', files: [] }] };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: fulfilled }))
      .mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: unprepared }));
    render(<PaidGrantJourney />);
    await openOrder();
    expect(screen.queryByRole('button', { name: finish })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: prepare })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh paid licenses' }));
    fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
    await screen.findByText(/waiting for complete preparation/);
    expect(screen.queryByRole('button', { name: finish })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: prepare })).toBeEnabled();
  });
});
