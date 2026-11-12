import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TestCheckout } from '../../resources/js/components/TestCheckout';

vi.mock('../../resources/js/components/TestOwnerDelivery', () => ({ TestOwnerDelivery: () => null }));

const orderId = '73000000-0000-4000-8000-000000000011';
const json = (checkout: unknown, status = 200) => new Response(JSON.stringify({ checkout }), { status });
const failure = (code: string, status: number) => new Response(JSON.stringify({ code }), { status });
function fixture(buyerCancel?: 'available' | 'cancelled' | 'unavailable', status = 'not_started') {
  return {
    checkoutSchema: 1, orderId, id: status === 'not_started' ? null : '73000000-0000-4000-8000-000000000012',
    currency: 'USD', totalMinor: 4280, status, testOnly: true,
    paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started',
    url: status === 'open' ? 'https://checkout.stripe.com/c/pay/cs_test_SyntheticOnly' : null,
    expiresAt: status === 'not_started' ? null : new Date(Date.now() + 60_000).toISOString(),
    observedAt: status === 'not_started' ? null : new Date().toISOString(),
    ...(buyerCancel === undefined ? {} : { buyerCancel }),
  };
}
const posts = (fetcher: { mock: { calls: Parameters<typeof fetch>[] } }) => fetcher.mock.calls.filter(([, init]) => init?.method === 'POST');

afterEach(() => {
  document.head.querySelectorAll('[data-checkout-csrf]').forEach(element => element.remove());
});

describe('buyer cancellation of a never-sent test order', () => {
  it.each([undefined, 'unavailable'] as const)('is not offered when the server reports %s', async buyerCancel => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture(buyerCancel)));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('button', { name: 'Open Stripe test checkout' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel this order' })).not.toBeInTheDocument();
  });

  it('asks for confirmation, moves focus into the confirm step and back, and sends nothing until confirmed', async () => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture('available')));
    render(<TestCheckout orderId={orderId} enabled />);
    const cancel = await screen.findByRole('button', { name: 'Cancel this order' });
    await user.click(cancel);
    const group = screen.getByRole('group', { name: 'Confirm cancelling this test order' });
    expect(group).toHaveAccessibleDescription(/has not been sent to Stripe.*cannot be undone/);
    expect(screen.getByRole('button', { name: 'Keep this order' })).toHaveFocus();
    await user.click(screen.getByRole('button', { name: 'Keep this order' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Cancel this order' })).toHaveFocus());
    expect(screen.queryByRole('group')).not.toBeInTheDocument();
    expect(posts(fetcher)).toHaveLength(0);
  });

  it('cancels with keyboard only, CSRF and an empty body, then shows the cancelled state with a way back to the catalog', async () => {
    const user = userEvent.setup();
    document.head.insertAdjacentHTML('beforeend', '<meta data-checkout-csrf name="csrf-token" content="synthetic-csrf">');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture('available'))).mockResolvedValueOnce(json(fixture('cancelled')));
    const store = vi.spyOn(Storage.prototype, 'setItem');
    render(<TestCheckout orderId={orderId} enabled />);
    const cancel = await screen.findByRole('button', { name: 'Cancel this order' });
    cancel.focus();
    await user.keyboard('{Enter}');
    expect(screen.getByRole('button', { name: 'Keep this order' })).toHaveFocus();
    await user.keyboard('{Shift>}{Tab}{/Shift}');
    expect(screen.getByRole('button', { name: 'Yes, cancel this order' })).toHaveFocus();
    await user.keyboard('{Enter}');
    const status = await screen.findByRole('status');
    expect(status).toHaveTextContent('You cancelled this test order. It was never sent to Stripe and can no longer be paid.');
    await waitFor(() => expect(status).toHaveFocus());
    expect(screen.getByRole('link', { name: 'Back to the catalog' })).toHaveAttribute('href', '/');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(posts(fetcher)).toEqual([[`/orders/${orderId}/cancel`, expect.objectContaining({
      method: 'POST', credentials: 'same-origin', cache: 'no-store', body: '{}',
      headers: expect.objectContaining({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'synthetic-csrf' }),
    })]]);
    expect(store).not.toHaveBeenCalled();
  });

  it.each([
    ['a refusal', () => failure('CHECKOUT_STARTED', 409)],
    ['an unconfirmed answer', () => json(fixture('available'))],
    ['a malformed answer', () => json({ ...fixture('cancelled'), status: 'open' })],
  ])('reports %s without claiming cancellation and offers a read-only reload', async (_label, answer) => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture('available'))).mockResolvedValueOnce(answer());
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Cancel this order' }));
    await user.click(screen.getByRole('button', { name: 'Yes, cancel this order' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The cancellation could not be confirmed.');
    expect(screen.queryByText(/You cancelled this test order/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reload checkout status' })).toBeInTheDocument();
    expect(posts(fetcher)).toHaveLength(1);
  });

  it('shows a retained cancellation on load without any checkout or cancel action', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture('cancelled')));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('status')).toHaveTextContent('can no longer be paid');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the catalog' })).toBeInTheDocument();
    expect(posts(fetcher)).toHaveLength(0);
  });

  it.each([['cancelled', 'open'], ['available', 'pending']] as const)('rejects buyerCancel %s together with status %s', async (buyerCancel, status) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture(buyerCancel, status)));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded');
    expect(screen.queryByRole('button', { name: 'Cancel this order' })).not.toBeInTheDocument();
    expect(screen.queryByText(/You cancelled this test order/)).not.toBeInTheDocument();
  });
  // Review of PR #363 (Codex P2, Cursor Bugbot): a refused start latched `rejected`, which hid Cancel while the server still
  // reported buyerCancel available, the very case (a window past its 15 minutes) where cancelling helps most.
  it.each([['CHECKOUT_EXPIRED', 410], ['CHECKOUT_UNSUPPORTED', 409]] as const)(
    'still offers cancel after the start is refused with %s, through a reload, and cancels', async (code, httpStatus) => {
      const user = userEvent.setup();
      const fetcher = vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce(json(fixture('available')))
        .mockResolvedValueOnce(failure(code, httpStatus))
        .mockResolvedValueOnce(json(fixture('available')))
        .mockResolvedValueOnce(json(fixture('cancelled')));
      render(<TestCheckout orderId={orderId} enabled />);
      await user.click(await screen.findByRole('button', { name: 'Open Stripe test checkout' }));
      expect(await screen.findByRole('alert')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Cancel this order' })).toBeInTheDocument();
      await user.click(screen.getByRole('button', { name: 'Check Stripe test checkout status' }));
      await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
      await user.click(screen.getByRole('button', { name: 'Cancel this order' }));
      await user.click(screen.getByRole('button', { name: 'Yes, cancel this order' }));
      expect(await screen.findByText(/You cancelled this test order/)).toBeInTheDocument();
      expect(posts(fetcher).map(([url]) => String(url))).toEqual([
        `/orders/${orderId}/checkout`, `/orders/${orderId}/cancel`]);
    });

  it('sends one cancel while a cancel is in flight, however often it is confirmed', async () => {
    const user = userEvent.setup();
    let settle: (response: Response) => void = () => undefined;
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(json(fixture('available')))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { settle = resolve; }));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Cancel this order' }));
    const confirm = screen.getByRole('button', { name: 'Yes, cancel this order' });
    await user.click(confirm);
    await user.click(confirm);
    expect(confirm).toBeDisabled();
    expect(posts(fetcher)).toHaveLength(1);
    settle(json(fixture('cancelled')));
    expect(await screen.findByText(/You cancelled this test order/)).toBeInTheDocument();
    expect(posts(fetcher)).toHaveLength(1);
  });
});
