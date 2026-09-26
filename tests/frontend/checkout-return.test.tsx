import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CheckoutReturn from '../../resources/js/Pages/CheckoutReturn';

const orderId = '74000000-0000-4000-8000-000000000001';
function response(status: 'not_started' | 'complete' | 'open') {
  return new Response(JSON.stringify({ checkout: {
    checkoutSchema: 1, orderId, id: status === 'not_started' ? null : '74000000-0000-4000-8000-000000000002',
    currency: 'USD', totalMinor: 4280, status, testOnly: true,
    paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started',
    url: status === 'open' ? 'https://checkout.stripe.com/c/pay/cs_test_ReturnFixture' : null,
    expiresAt: status === 'not_started' ? null : new Date(Date.now() + 60_000).toISOString(),
    observedAt: status === 'not_started' ? null : new Date().toISOString(),
  } }));
}

afterEach(() => window.history.replaceState({}, '', '/'));

describe('read-only checkout return', () => {
  it('displays retained verification despite contradictory redirect parameters without performing a payment operation', async () => {
    window.history.replaceState({}, '', '/checkout/return?canceled=true&payment_status=unpaid');
    const body = await response('complete').json();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ checkout: {
      ...body.checkout, paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_contracts',
    } })));
    render(<CheckoutReturn orderId={orderId} />);
    expect(await screen.findByRole('status')).toHaveTextContent('Test payment verified and order finalized');
    expect(screen.getByRole('status')).toHaveTextContent('download access is not available yet');
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]).toEqual([`/orders/${orderId}/checkout`, expect.objectContaining({ method: 'GET' })]);
  });

  it.each(['success=true&payment_status=paid', 'canceled=true', 'session_id=cs_live_untrusted&status=complete'])('does not interpret redirect parameters as payment evidence: %s', async query => {
    window.history.replaceState({}, '', `/checkout/return?${query}`);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response('not_started'));
    const store = vi.spyOn(Storage.prototype, 'setItem');
    render(<CheckoutReturn orderId={orderId} />);
    await act(async () => {});
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]).toEqual([`/orders/${orderId}/checkout`, expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' })]);
    expect(fetcher.mock.calls[0][1]?.method ?? 'GET').toBe('GET');
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByText(/payment successful|payment verified|order paid|license granted|download ready/i)).not.toBeInTheDocument();
    expect(store).not.toHaveBeenCalled();
  });

  it('shows a completed provider session without asserting payment or starting fulfillment', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response('complete'));
    render(<CheckoutReturn orderId={orderId} />);
    await screen.findByRole('button', { name: 'Check Stripe test checkout status' });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByText(/payment successful|payment verified|order paid|license granted|download ready/i)).not.toBeInTheDocument();
  });
});
