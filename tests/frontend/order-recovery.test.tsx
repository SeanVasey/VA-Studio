import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';

const quoteId = 'c89f5e3a-bb81-427b-9c4f-77620fc110e0';
const recoveryKey = 'vaseyaudio-order-recovery-v1';
const order = { orderSchema: 1, id: 'a182f5e0-b99c-4b2b-840c-0138a04ff82c', quoteId, pricingId: 'pricing-test', reviewHash: 'a'.repeat(64), createdAt: '2026-01-01T00:00:00Z', status: 'prepared', paymentStatus: 'not_started', finalizationStatus: 'not_started', fulfillmentStatus: 'not_started', testOnly: true, payable: false, currency: 'USD', totalMinor: 4280 };
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const checkoutResponse = () => json({ checkout: { checkoutSchema: 1, orderId: order.id, id: null, currency: 'USD', totalMinor: order.totalMinor, status: 'not_started', testOnly: true, paymentStatus: 'not_verified', finalizationStatus: 'not_started', fulfillmentStatus: 'not_started', url: null, expiresAt: null, observedAt: null } });

describe('storefront durable order recovery', () => {

  it.each([
    ['prepared', 'awaiting_finalization', 'not_started', 'Order finalization is pending.'],
    ['paid', 'paid', 'pending_contracts', 'Contracts are pending;'],
    ['paid_exception', 'paid_exception', 'blocked', 'This order needs review'],
  ])('recovers a verified %s order despite an unavailable checkout read', async (status, finalizationStatus, fulfillmentStatus, copy) => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input).endsWith('/checkout')
      ? json({}, 503) : json({ order: { ...order, status, paymentStatus: 'verified', finalizationStatus, fulfillmentStatus } }));
    render(<Storefront tracks={[]} licenseTiers={[]} testCheckoutEnabled />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText('TEST ORDER STATUS')).toBeInTheDocument();
    expect(await screen.findByRole('alert')).toHaveTextContent('previously verified test payment remains recorded');
    expect(screen.getByText(new RegExp(copy))).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe|Prepare test order/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh test order status' }));
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
    expect(sessionStorage.getItem(recoveryKey)).toBe(JSON.stringify([quoteId]));
  });

  it('does not offer another checkout when a verified retained order encounters an unverified response', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input).endsWith('/checkout')
      ? checkoutResponse() : json({ order: { ...order, status: 'paid', paymentStatus: 'verified', finalizationStatus: 'paid', fulfillmentStatus: 'pending_contracts' } }));
    render(<Storefront tracks={[]} licenseTiers={[]} testCheckoutEnabled />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('previously verified test payment remains recorded');
    expect(screen.getByText(/Test payment verified and order finalized/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it.each([
    ['missing finalization', { finalizationStatus: undefined }],
    ['paid without verification', { status: 'paid', finalizationStatus: 'paid', fulfillmentStatus: 'pending_contracts' }],
    ['verified prepared with paid progress', { paymentStatus: 'verified', finalizationStatus: 'paid', fulfillmentStatus: 'pending_contracts' }],
    ['exception without blocked fulfillment', { status: 'paid_exception', paymentStatus: 'verified', finalizationStatus: 'paid_exception' }],
    ['paid order awaiting finalization', { status: 'paid', paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization' }],
  ])('rejects an incoherent recovered order: %s', async (_name, changes) => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ order: { ...order, ...changes } }));
    render(<Storefront tracks={[]} licenseTiers={[]} testCheckoutEnabled />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText(/status could not be recovered/)).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Stripe test checkout' })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('recovers checkout for an order marked not_verified even when new checkout is disabled', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const url = 'https://checkout.stripe.com/c/pay/cs_test_RecoveryOnly';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input).endsWith('/checkout')
      ? json({ checkout: { checkoutSchema: 1, orderId: order.id, id: 'checkout-recovery', currency: 'USD', totalMinor: order.totalMinor, status: 'open', testOnly: true, paymentStatus: 'not_verified', finalizationStatus: 'not_started', fulfillmentStatus: 'not_started', url, expiresAt: new Date(Date.now() + 60_000).toISOString(), observedAt: new Date().toISOString() } })
      : json({ order: { ...order, paymentStatus: 'not_verified' } }));
    render(<Storefront tracks={[]} licenseTiers={[]} testCheckoutEnabled={false} />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toHaveAttribute('href', url);
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('threads the separate checkout flag to a recovered prepared order', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input).endsWith('/checkout') ? checkoutResponse() : json({ order }));
    render(<Storefront tracks={[]} licenseTiers={[]} testOrderPreparationEnabled={false} testCheckoutEnabled />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByRole('button', { name: 'Open Stripe test checkout' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: 'Prepare test order' })).not.toBeInTheDocument();
  });

  it('recovers an old order after reload despite an expired quote, withdrawn creation policy and unavailable cart selections', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    sessionStorage.setItem('vaseyaudio-quote-attempt-v1', JSON.stringify({ selection: 'old-selection', key: 'b190523d-41c8-4e42-bbcb-8b32b3b6fce4' }));
    sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([{ trackId: 'withdrawn', offerId: '1', offerRevisionId: '1', licenseVersionId: '1' }]));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input) === `/quotes/${quoteId}/order` ? json({ order }) : String(input).endsWith('/checkout') ? checkoutResponse() : json({ code: 'QUOTE_EXPIRED' }, 410));
    const first = render(<Storefront tracks={[]} licenseTiers={[]} testOrderPreparationEnabled={false} />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    expect(screen.getByText(`Order ${order.id}`)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Review test order' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Prepare test order' })).not.toBeInTheDocument();
    await waitFor(() => expect(fetcher.mock.calls.map(([url]) => url)).toEqual([`/quotes/${quoteId}/order`, `/orders/${order.id}/checkout`]));
    expect(fetcher.mock.calls[0][1]).toEqual(expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' }));
    first.unmount();
    render(<Storefront tracks={[]} licenseTiers={[]} testOrderPreparationEnabled={false} />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    await waitFor(() => expect(fetcher.mock.calls.map(([url]) => url)).toEqual([`/quotes/${quoteId}/order`, `/orders/${order.id}/checkout`, `/quotes/${quoteId}/order`, `/orders/${order.id}/checkout`]));
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
    expect(sessionStorage.getItem(recoveryKey)).toBe(JSON.stringify([quoteId]));
  });

  it('checks again after closing and reopening the cart and allows interrupted recovery retry', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('Offline')).mockImplementation(async input => String(input).endsWith('/checkout') ? checkoutResponse() : json({ order }));
    render(<Storefront tracks={[]} licenseTiers={[]} testOrderPreparationEnabled={false} />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText(/status could not be recovered/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Check saved test order again' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Close dialog' }));
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(5));
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('retains an uncertain attempt locator when recovery is not yet available and never starts another order', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({}, 404)).mockImplementation(async input => String(input).endsWith('/checkout') ? checkoutResponse() : json({ order }));
    render(<Storefront tracks={[]} licenseTiers={[]} testOrderPreparationEnabled={false} />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText(/interrupted request may still be completing/)).toBeInTheDocument();
    expect(sessionStorage.getItem(recoveryKey)).toBe(JSON.stringify([quoteId]));
    await user.click(screen.getByRole('button', { name: 'Check saved test order again' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('ignores stale recovery responses after the cart closes and rejects mismatched status', async () => {
    const user = userEvent.setup(); let finish!: (value: Response) => void;
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json({ order: { ...order, quoteId: 'another-quote' } }));
    render(<Storefront tracks={[]} licenseTiers={[]} testOrderPreparationEnabled={false} />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    await user.click(screen.getByRole('button', { name: 'Close dialog' }));
    await act(async () => finish(json({ order })));
    expect(screen.queryByText('TEST ORDER PREPARED')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(await screen.findByText(/status could not be recovered/)).toBeInTheDocument();
    expect(screen.queryByText('TEST ORDER PREPARED')).not.toBeInTheDocument();
  });

  it('never uses real order recovery in the isolated design preview', async () => {
    const user = userEvent.setup();
    sessionStorage.setItem(recoveryKey, JSON.stringify([quoteId]));
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<Storefront tracks={[]} licenseTiers={[]} designPreview testOrderPreparationEnabled />);
    await user.click(screen.getByRole('button', { name: 'Open cart, 0 items' }));
    expect(screen.queryByRole('region', { name: 'Previous test order recovery' })).not.toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled();
  });
});
