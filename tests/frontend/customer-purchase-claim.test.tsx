import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CustomerPurchaseClaim } from '../../resources/js/components/CustomerPurchaseClaim';
import { savePurchase } from '../../resources/js/lib/purchase-claim';

const id = 'f456b861-2126-4ba5-88a1-949e10855cd1';
const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } });
afterEach(() => { cleanup(); vi.restoreAllMocks(); document.head.innerHTML = ''; });

describe('explicit guest purchase claim', () => {
  it('stages only one exact reference after a deliberate action and carries no proof or account fields', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-csrf">';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ orderId: id, staged: true }));
    render(<CustomerPurchaseClaim />);
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Guest test-order reference'), { target: { value: id } });
    fireEvent.click(screen.getByRole('button', { name: 'Select purchase before sign-in' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Sign in below');
    await waitFor(() => expect(screen.getByRole('status')).toHaveFocus());
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0][0]).toBe('/account/purchase-claim/stage');
    expect(fetcher.mock.calls[0][1]).toMatchObject({ method: 'POST', body: JSON.stringify({ orderId: id }), credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { 'X-CSRF-TOKEN': 'test-csrf' } });
    expect(screen.getByLabelText('Guest test-order reference')).toBeDisabled();
  });

  it('requires confirmation after sign-in and retains a read-only original reference', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ orderId: id, saved: true }));
    render(<CustomerPurchaseClaim claim={{ orderId: id, expiresAt: '2026-10-06T12:00:00Z', saved: false }} />);
    expect(fetcher).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Guest test-order reference')).toHaveAttribute('readonly');
    fireEvent.click(screen.getByRole('button', { name: 'Save purchase to this account' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Saved to your test account');
    expect(fetcher.mock.calls[0][0]).toBe('/account/purchase-claim/complete');
    expect(screen.getByRole('link', { name: 'Reload your library' })).toHaveAttribute('href', '/account');
  });

  it.each([403, 419, 422, 429, 503])('uses a generic bounded failure for status %s', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ message: 'PRIVATE account details' }, status));
    render(<CustomerPurchaseClaim claim={{ orderId: id, expiresAt: '2026-10-06T12:00:00Z', saved: false }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save purchase to this account' }));
    expect(await screen.findByRole('status')).toHaveTextContent('could not be saved');
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save purchase to this account' })).toBeEnabled();
  });

  it.each([null, [], { orderId: id, saved: false }, { orderId: id, staged: true }, { orderId: id, saved: true, private: 'PRIVATE' }, { orderId: 'foreign', saved: true }])('rejects malformed acknowledgement %#', async body => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(body));
    expect(await savePurchase('complete', id, new AbortController().signal)).toBe(false);
  });

  it('rejects an oversized streamed response and never renders reflected details', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(' '.repeat(4097), { headers: { 'Content-Type': 'application/json' } }));
    expect(await savePurchase('complete', id, new AbortController().signal)).toBe(false);
  });

  it('does not issue requests for malformed references or already aborted requests', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch'); const abort = new AbortController(); abort.abort();
    expect(await savePurchase('stage', '../private', new AbortController().signal)).toBe(false);
    expect(await savePurchase('stage', id, abort.signal)).toBe(false);
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('prevents duplicate requests and clears the reference on pagehide while ignoring late acknowledgement', async () => {
    let finish!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    render(<CustomerPurchaseClaim claim={{ orderId: id, expiresAt: '2026-10-06T12:00:00Z', saved: false }} />);
    const form = screen.getByRole('button', { name: 'Save purchase to this account' }).closest('form')!;
    fireEvent.submit(form); fireEvent.submit(form); expect(fetcher).toHaveBeenCalledTimes(1);
    fireEvent(window, new Event('pagehide'));
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    finish(json({ orderId: id, saved: true }));
    await waitFor(() => expect(screen.queryByLabelText('Guest test-order reference')).not.toBeInTheDocument());
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('aborts pending requests on unmount', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}));
    const view = render(<CustomerPurchaseClaim claim={{ orderId: id, expiresAt: '2026-10-06T12:00:00Z', saved: false }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save purchase to this account' })); view.unmount();
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
  });
});
