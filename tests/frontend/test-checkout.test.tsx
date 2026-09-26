import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TestCheckout } from '../../resources/js/components/TestCheckout';

const orderId = '73000000-0000-4000-8000-000000000001';
const checkoutId = '73000000-0000-4000-8000-000000000002';
const checkoutUrl = 'https://checkout.stripe.com/c/pay/cs_test_SyntheticOnly';
const json = (checkout: unknown, status = 200) => new Response(JSON.stringify({ checkout }), { status });
function fixture(status = 'not_started', id = orderId) {
  return {
    checkoutSchema: 1, orderId: id, id: status === 'not_started' ? null : checkoutId,
    currency: 'USD', totalMinor: 4280, status, testOnly: true,
    paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started',
    url: status === 'open' ? checkoutUrl : null,
    expiresAt: status === 'not_started' || status === 'pending' ? null : new Date(Date.now() + 60_000).toISOString(),
    observedAt: status === 'not_started' || status === 'pending' ? null : new Date().toISOString(),
  };
}
const checkoutPath = (id = orderId) => `/orders/${id}/checkout`;
const postCalls = (fetcher: { mock: { calls: Parameters<typeof fetch>[] } }) => fetcher.mock.calls.filter(([, init]) => init?.method === 'POST');

afterEach(() => {
  vi.useRealTimers();
  document.head.querySelectorAll('[data-checkout-csrf]').forEach(element => element.remove());
});

describe('hosted Stripe test checkout', () => {

  it.each([
    ['awaiting_finalization', 'not_started', 'not_started', 'Order finalization is pending.'],
    ['paid', 'pending', 'pending_contracts', 'Contracts are pending;'],
    ['paid', 'issued', 'pending_activation', 'Test contracts have been issued. Delivery is pending;'],
    ['paid', 'attention', 'blocked', 'Contract preparation needs attention.'],
    ['paid_exception', 'blocked', 'blocked', 'This order needs review'],
  ])('shows verified %s without payment actions, fulfillment claims or private evidence', async (finalizationStatus, contractStatus, fulfillmentStatus, copy) => {
    const user = userEvent.setup();
    const body = { ...fixture('complete'), paymentStatus: 'verified', finalizationStatus, contractStatus, fulfillmentStatus,
      providerPaymentIntentId: 'pi_PRIVATE_SHOULD_NOT_RENDER', reason: 'PRIVATE_FAILURE_REASON', buyer: { email: 'private@example.invalid' } };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async () => json(body));
    const store = vi.spyOn(Storage.prototype, 'setItem');
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('status')).toHaveTextContent(copy);
    expect(screen.getByRole('status')).toHaveTextContent('Test payment verified');
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(screen.queryByText(/license granted|download ready|pi_PRIVATE|PRIVATE_FAILURE_REASON|private@example/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh test order status' }));
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(fetcher.mock.calls.every(([url, init]) => url === checkoutPath() && init?.method === 'GET')).toBe(true);
    expect(postCalls(fetcher)).toHaveLength(0);
    expect(store).not.toHaveBeenCalled();
  });


  it('refreshes pending, attention and issued contract states without repeating any payment operation', async () => {
    const user = userEvent.setup();
    const base = { ...fixture('complete'), paymentStatus: 'verified', finalizationStatus: 'paid' };
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' }))
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'attention', fulfillmentStatus: 'blocked' }))
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' }))
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'issued', fulfillmentStatus: 'pending_activation',
        contractUrl: 'https://private.invalid/contract.pdf', privatePath: '/private/contracts/secret.pdf', buyer: 'PRIVATE_BUYER' }));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('status')).toHaveTextContent('Contracts are pending;');
    await user.click(screen.getByRole('button', { name: 'Refresh test order status' }));
    expect(screen.getByRole('status')).toHaveTextContent('Contract preparation needs attention.');
    await user.click(screen.getByRole('button', { name: 'Refresh test order status' }));
    expect(screen.getByRole('status')).toHaveTextContent('Contracts are pending;');
    await user.click(screen.getByRole('button', { name: 'Refresh test order status' }));
    expect(screen.getByRole('status')).toHaveTextContent('Test contracts have been issued. Delivery is pending;');
    expect(screen.getByRole('status')).toHaveTextContent('contracts and downloads are not available here yet');
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByText(/PRIVATE_BUYER|private.invalid|secret.pdf|refund/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(4);
    expect(postCalls(fetcher)).toHaveLength(0);
  });

  it('rejects stale pending status after issuance but accepts a server-reported contract issue while retaining verified payment', async () => {
    const user = userEvent.setup();
    const base = { ...fixture('complete'), paymentStatus: 'verified', finalizationStatus: 'paid' };
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'issued', fulfillmentStatus: 'pending_activation' }))
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' }))
      .mockResolvedValueOnce(json({ ...base, contractStatus: 'attention', fulfillmentStatus: 'blocked' }));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Refresh test order status' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('previously verified test payment remains recorded');
    expect(screen.getByRole('status')).toHaveTextContent('Test contracts have been issued.');
    await user.click(screen.getByRole('button', { name: 'Refresh test order status' }));
    expect(screen.getByRole('status')).toHaveTextContent('Test payment verified and order finalized. Contract preparation needs attention.');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(postCalls(fetcher)).toHaveLength(0);
  });

  it('advances a verified payment to a finalized order using only a read-only refresh', async () => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(json({ ...fixture('complete'), paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization' }))
      .mockResolvedValueOnce(json({ ...fixture('complete'), paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' }));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Refresh test order status' }));
    expect(screen.getByRole('status')).toHaveTextContent('order finalized');
    expect(postCalls(fetcher)).toHaveLength(0);
  });

  it('allows a historical open observation only without a payment URL after verification', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('open'), url: null, paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization' }));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('status')).toHaveTextContent('Order finalization is pending');
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
  });

  it.each([
    ['missing finalization', { finalizationStatus: undefined }],
    ['missing contract status', { contractStatus: undefined }],
    ['unknown contract status', { contractStatus: 'ready' }],
    ['unverified with issued contracts', { contractStatus: 'issued' }],
    ['awaiting finalization with contracts', { paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization', contractStatus: 'issued' }],
    ['paid with issued contracts but pending rendering', { paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'issued', fulfillmentStatus: 'pending_contracts' }],
    ['paid with attention but unblocked fulfillment', { paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'attention', fulfillmentStatus: 'pending_activation' }],
    ['paid with pending contracts but pending activation', { paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_activation' }],
    ['paid exception with issued contracts', { paymentStatus: 'verified', finalizationStatus: 'paid_exception', contractStatus: 'issued', fulfillmentStatus: 'blocked' }],
    ['unverified paid', { finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' }],
    ['verified without finalization', { paymentStatus: 'verified' }],
    ['verified awaiting with contracts', { paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization', fulfillmentStatus: 'pending_contracts' }],
    ['paid without pending contracts', { paymentStatus: 'verified', finalizationStatus: 'paid' }],
    ['exception without blocked fulfillment', { paymentStatus: 'verified', finalizationStatus: 'paid_exception' }],
    ['verified without intent', { paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization', id: null }],
    ['verified without started checkout', { paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization', id: null, status: 'not_started' }],
    ['verified with payment URL', { paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization', status: 'open', url: checkoutUrl }],
  ])('rejects incoherent payment progress: %s', async (_name, change) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('complete'), ...change }));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
  });

  it.each(['unverified', 'awaiting_finalization', 'paid_exception', 'unavailable', 'expired'])('retains finalized verification after a %s refresh result without restarting payment', async outcome => {
    const user = userEvent.setup();
    const paid = { ...fixture('complete'), paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' };
    const changed = outcome === 'unverified' ? fixture('open') : { ...paid, finalizationStatus: outcome, contractStatus: outcome === 'paid_exception' ? 'blocked' : 'not_started', fulfillmentStatus: outcome === 'paid_exception' ? 'blocked' : 'not_started' };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(paid))
      .mockResolvedValueOnce(outcome === 'unavailable' ? new Response('{}', { status: 500 })
        : outcome === 'expired' ? new Response(JSON.stringify({ code: 'CHECKOUT_EXPIRED' }), { status: 410 }) : json(changed));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Refresh test order status' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('previously verified test payment remains recorded');
    expect(screen.getByRole('status')).toHaveTextContent('order finalized');
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(postCalls(fetcher)).toHaveLength(0);
  });

  it('recovers an existing checkout when creation is disabled without writes or browser persistence', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(fixture('open')));
    const store = vi.spyOn(Storage.prototype, 'setItem');
    render(<TestCheckout orderId={orderId} enabled={false} expectedTotalMinor={4280} />);
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toHaveAttribute('href', checkoutUrl);
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]).toEqual([checkoutPath(), expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' })]);
    expect(postCalls(fetcher)).toHaveLength(0);
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(store).not.toHaveBeenCalled();
  });

  it('does not offer creation when the flag is absent and no existing checkout is available', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(fixture()));
    render(<TestCheckout orderId={orderId} />);
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));
    await act(async () => {});
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(postCalls(fetcher)).toHaveLength(0);
  });

  it('creates only after an explicit action with CSRF protection and an empty body', async () => {
    const user = userEvent.setup();
    document.head.insertAdjacentHTML('beforeend', '<meta data-checkout-csrf name="csrf-token" content="synthetic-csrf">');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture())).mockResolvedValueOnce(json(fixture('open')));
    const store = vi.spyOn(Storage.prototype, 'setItem');
    render(<TestCheckout orderId={orderId} enabled expectedTotalMinor={4280} />);
    const create = await screen.findByRole('button', { name: 'Open Stripe test checkout' });
    expect(postCalls(fetcher)).toHaveLength(0);
    await user.click(create);
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toHaveAttribute('href', checkoutUrl);
    expect(postCalls(fetcher)).toHaveLength(1);
    expect(postCalls(fetcher)[0]).toEqual([checkoutPath(), expect.objectContaining({
      method: 'POST', credentials: 'same-origin', cache: 'no-store', body: '{}',
      headers: expect.objectContaining({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'synthetic-csrf' }),
    })]);
    expect(store).not.toHaveBeenCalled();
  });

  it('deduplicates creation while the first request has no result', async () => {
    const user = userEvent.setup(); let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture()))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.dblClick(await screen.findByRole('button', { name: 'Open Stripe test checkout' }));
    expect(postCalls(fetcher)).toHaveLength(1);
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    await act(async () => finish(json(fixture('open'))));
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toBeInTheDocument();
  });

  it('recovers an uncertain creation before retrying the same durable intent', async () => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture()))
      .mockResolvedValueOnce(new Response('{}', { status: 500 }))
      .mockResolvedValueOnce(json(fixture('pending'))).mockResolvedValueOnce(json(fixture('open')));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Open Stripe test checkout' }));
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Reload checkout status' }));
    await user.click(await screen.findByRole('button', { name: 'Retry Stripe test checkout' }));
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toBeInTheDocument();
    expect(fetcher.mock.calls.map(([url, init]) => [url, init?.method ?? 'GET'])).toEqual([
      [checkoutPath(), 'GET'], [checkoutPath(), 'POST'], [checkoutPath(), 'GET'], [checkoutPath(), 'POST'],
    ]);
    expect(postCalls(fetcher).map(([, init]) => init?.body)).toEqual(['{}', '{}']);
  });

  it.each([[409, 'CHECKOUT_UNSUPPORTED', /pricing is not supported/], [410, 'CHECKOUT_EXPIRED', /preparation window has expired/]] as const)('explains definitive %s %s and permits read-only recovery without another creation', async (status, code, copy) => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture()))
      .mockResolvedValueOnce(new Response(JSON.stringify({ code }), { status }))
      .mockResolvedValueOnce(json(fixture())).mockResolvedValueOnce(json(fixture('open')));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Open Stripe test checkout' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(copy);
    expect(screen.queryByRole('button', { name: 'Retry Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Check Stripe test checkout status' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(copy);
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Reload checkout status' }));
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toBeInTheDocument();
    expect(postCalls(fetcher)).toHaveLength(1);
    expect(fetcher.mock.calls.slice(2).every(([, init]) => init?.method === 'GET')).toBe(true);
  });

  it.each([[409, 'CHECKOUT_CHANGED'], [500, 'CHECKOUT_UNSUPPORTED'], [410, 'UNKNOWN_ERROR']] as const)('preserves an uncertain retry for %s %s', async (status, code) => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture()))
      .mockResolvedValueOnce(new Response(JSON.stringify({ code }), { status })).mockResolvedValueOnce(json(fixture('open')));
    render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Open Stripe test checkout' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('result is unconfirmed');
    await user.click(screen.getByRole('button', { name: 'Retry Stripe test checkout' }));
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toBeInTheDocument();
    expect(postCalls(fetcher).map(([, init]) => init?.body)).toEqual(['{}', '{}']);
  });

  it('reconciles an existing checkout explicitly even when creation is disabled, hiding the old URL while busy', async () => {
    const user = userEvent.setup(); let finish!: (response: Response) => void;
    document.head.insertAdjacentHTML('beforeend', '<meta data-checkout-csrf name="csrf-token" content="reconcile-csrf">');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture('open')))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }));
    render(<TestCheckout orderId={orderId} enabled={false} />);
    await screen.findByRole('link', { name: 'Continue to Stripe test checkout' });
    await user.dblClick(screen.getByRole('button', { name: 'Check Stripe test checkout status' }));
    expect(postCalls(fetcher)).toHaveLength(1);
    expect(postCalls(fetcher)[0]).toEqual([`${checkoutPath()}/reconcile`, expect.objectContaining({
      method: 'POST', body: '{}', credentials: 'same-origin', cache: 'no-store',
      headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'reconcile-csrf' }),
    })]);
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    await act(async () => finish(json(fixture('complete'))));
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check Stripe test checkout status' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe/ })).not.toBeInTheDocument();
  });

  it('a status check with no intent stays read-only', async () => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture())).mockResolvedValueOnce(json(fixture('pending')));
    render(<TestCheckout orderId={orderId} />);
    await user.click(await screen.findByRole('button', { name: 'Check Stripe test checkout status' }));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([checkoutPath(), checkoutPath()]);
    expect(postCalls(fetcher)).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Retry Stripe test checkout' })).toBeEnabled();
  });

  it.each(['complete', 'expired', 'reconciliation_required'])('never presents %s as verified payment or granted rights', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(fixture(status)));
    render(<TestCheckout orderId={orderId} enabled />);
    await screen.findByRole('button', { name: 'Check Stripe test checkout status' });
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByText(/payment successful|payment verified|order paid|license granted|download ready/i)).not.toBeInTheDocument();
  });

  it.each([
    ['unknown schema', { checkoutSchema: 2 }], ['another order', { orderId: 'another-order' }],
    ['live mode', { testOnly: false }], ['paid claim', { paymentStatus: 'paid' }],
    ['fulfilled claim', { fulfillmentStatus: 'complete' }], ['unknown status', { status: 'success' }],
    ['wrong currency', { currency: 'EUR' }], ['different retained amount', { totalMinor: 4279 }],
    ['fractional amount', { totalMinor: 4280.5 }], ['negative amount', { totalMinor: -1 }],
    ['missing intent', { id: null }], ['invalid observation date', { observedAt: 'not-a-date' }],
  ])('fails closed for %s', async (_name, change) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('open'), ...change }));
    render(<TestCheckout orderId={orderId} enabled expectedTotalMinor={4280} />);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reload checkout status' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
  });

  it.each([
    'https://checkout.stripe.com/c/pay/cs_live_contradictory_mode',
    'https://checkout.stripe.com/c/pay/cs_test_fixture#' + 'a'.repeat(4096),
    'https://checkout.stripe.com/c/pay/cs_test_fixture#' + 'é'.repeat(2050),
    'http://checkout.stripe.com/c/pay/cs_test_fixture',
    'https://checkout.stripe.com.evil.invalid/c/pay/cs_test_fixture',
    'https://evil.invalid/?redirect=https://checkout.stripe.com',
    'https://checkout.stripe.com@evil.invalid/c/pay/cs_test_fixture',
    'https://buyer:secret@checkout.stripe.com/c/pay/cs_test_fixture',
    'https://checkout.stripe.com:444/c/pay/cs_test_fixture',
    'https://checkout.stripe.com\\@evil.invalid/c/pay/cs_test_fixture',
    'https://checkout.stripe.com/c/pay/cs_test_fixture\n',
    'https://checkout.stripe.com/',
    '//checkout.stripe.com/c/pay/cs_test_fixture',
    'javascript:alert(document.cookie)',
  ])('never renders an unsafe checkout URL: %s', async url => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('open'), url }));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
  });

  it('accepts a validated test checkout URL exactly at the 4096-byte boundary', async () => {
    const prefix = 'https://checkout.stripe.com/c/pay/cs_test_boundary#';
    const url = prefix + 'a'.repeat(4096 - prefix.length);
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('open'), url }));
    render(<TestCheckout orderId={orderId} />);
    expect(await screen.findByRole('link', { name: 'Continue to Stripe test checkout' })).toHaveAttribute('href', url);
  });

  it('removes the open checkout link when its recorded expiry passes without a response', async () => {
    vi.useFakeTimers();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('open'), expiresAt: new Date(Date.now() + 2000).toISOString() }));
    render(<TestCheckout orderId={orderId} enabled />);
    await act(async () => {});
    expect(screen.getByRole('link', { name: 'Continue to Stripe test checkout' })).toBeInTheDocument();
    await act(async () => { vi.advanceTimersByTime(3000); });
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
  });

  it('does not render an already expired open link', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ ...fixture('open'), expiresAt: new Date(Date.now() - 1000).toISOString() }));
    render(<TestCheckout orderId={orderId} enabled />);
    await screen.findByRole('button', { name: 'Check Stripe test checkout status' });
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
  });

  it('keeps an old open URL hidden after reconciliation fails until a fresh read succeeds', async () => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture('open')))
      .mockRejectedValueOnce(new Error('Network unavailable')).mockResolvedValueOnce(json(fixture('expired')));
    render(<TestCheckout orderId={orderId} enabled />);
    await screen.findByRole('link', { name: 'Continue to Stripe test checkout' });
    await user.click(screen.getByRole('button', { name: 'Check Stripe test checkout status' }));
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Reload checkout status' }));
    await screen.findByRole('button', { name: 'Check Stripe test checkout status' });
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(fetcher.mock.calls[2][0]).toBe(checkoutPath());
    expect(fetcher.mock.calls[2][1]?.method ?? 'GET').toBe('GET');
  });

  it('ignores an old order status after the selected order changes', async () => {
    let finish!: (response: Response) => void;
    const otherId = '73000000-0000-4000-8000-000000000003';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json(fixture('not_started', otherId)));
    const view = render(<TestCheckout orderId={orderId} enabled />);
    view.rerender(<TestCheckout orderId={otherId} enabled />);
    expect(await screen.findByRole('button', { name: 'Open Stripe test checkout' })).toBeEnabled();
    await act(async () => finish(json(fixture('open'))));
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open Stripe test checkout' })).toBeEnabled();
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([checkoutPath(), checkoutPath(otherId)]);
  });

  it('ignores an old creation result after the selected order changes', async () => {
    const user = userEvent.setup(); let finish!: (response: Response) => void;
    const otherId = '73000000-0000-4000-8000-000000000003';
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(fixture()))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json(fixture('not_started', otherId)));
    const view = render(<TestCheckout orderId={orderId} enabled />);
    await user.click(await screen.findByRole('button', { name: 'Open Stripe test checkout' }));
    view.rerender(<TestCheckout orderId={otherId} enabled />);
    expect(await screen.findByRole('button', { name: 'Open Stripe test checkout' })).toBeEnabled();
    await act(async () => finish(json(fixture('open'))));
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open Stripe test checkout' })).toBeEnabled();
  });

  it('handles malformed JSON as an unavailable result without automatic provider writes', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('<html>upstream error</html>'));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(postCalls(fetcher)).toHaveLength(0);
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
  });

  it.each([401, 403, 404, 419, 503])('does not expose an error body or start checkout after a %s read', async status => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ message: 'Private provider identity and diagnostics must stay hidden' }), { status }));
    render(<TestCheckout orderId={orderId} enabled />);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByText(/Private provider identity/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(postCalls(fetcher)).toHaveLength(0);
  });
});
