import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { OrderPreparation } from '../../resources/js/components/OrderPreparation';

const digest = 'a'.repeat(64);
function fixture(quoteId = 'quote-1') {
  const expiresAt = new Date(Date.now() + 60_000).toISOString();
  const pricing = {
    pricingSchema: 2, id: 'pricing-1', quoteId, expiresAt, currency: 'USD', subtotalMinor: 5000, discountMinor: 1000,
    taxBasisMinor: 4000, taxMinor: 280, totalMinor: 4280, taxStatus: 'fixed_test', payable: false, testOnly: true, pricingHash: digest,
    promotion: { key: 'fixture', version: 1, code: 'TEST20', hash: digest },
    items: [{ offerRevisionId: '101', quantity: 1, baseMinor: 5000, discountMinor: 1000, taxBasisMinor: 4000, taxMinor: 280, totalMinor: 4280, disclosureHash: digest }],
  };
  const review = {
    reviewSchema: 1, quoteId, expiresAt, pricing, sellerName: 'Synthetic test seller', policyVersion: 'order-test-v1', assent: { version: 'assent-test-v1', text: 'I accept these synthetic test terms and the displayed test total.' },
    items: [{ offerRevisionId: '101', title: 'Synthetic track', licenseName: 'Synthetic license', disclosure: {
      disclosureSchema: 2, quoteId, offerId: '1', offerRevisionId: '101', licenseVersionId: '2', disclosureHash: digest, testOnly: true,
      name: 'Synthetic license', version: 1, type: 'exclusive', features: ['Synthetic feature'], deliverableRoles: ['master_wav'], termsText: 'Full frozen terms <script>alert("never execute")</script>',
    } }], testOnly: true, payable: false, reviewHash: digest,
  };
  const order = { orderSchema: 1, id: 'order-1', quoteId, pricingId: 'pricing-1', reviewHash: digest, createdAt: new Date().toISOString(), status: 'prepared', paymentStatus: 'not_started', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started', testOnly: true, payable: false, currency: 'USD', totalMinor: 4280 };
  return { pricing, review, order, expiresAt };
}
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
function mockFlow(data = fixture()) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input);
    if (url.endsWith('/checkout')) return json({ checkout: { checkoutSchema: 1, orderId: data.order.id, id: null, currency: 'USD', totalMinor: data.order.totalMinor, status: 'not_started', testOnly: true, paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started', url: null, expiresAt: null, observedAt: null } });
    if (url.endsWith('/order')) return json({}, 404);
    if (url.endsWith('/pricing')) return json({ pricing: data.pricing });
    if (url.endsWith('/order-review')) return json({ review: data.review });
    if (url === '/orders' && init?.method === 'POST') return json({ order: data.order });
    throw new Error('Unexpected request');
  });
}
function mount(data = fixture()) {
  return render(<OrderPreparation quoteId={data.review.quoteId} expiresAt={data.expiresAt} offerRevisionIds={['101']} />);
}
async function load(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Review test order' }));
  return screen.findByRole('button', { name: 'Prepare test order' });
}
async function identify(user: ReturnType<typeof userEvent.setup>, assent = true) {
  await user.type(screen.getByRole('textbox', { name: 'Legal name' }), 'Synthetic Buyer');
  await user.type(screen.getByRole('textbox', { name: 'Email address' }), 'buyer@example.invalid');
  if (assent) await user.click(screen.getByRole('checkbox'));
}

describe('test order preparation', () => {
  it('checks recovery first and displays complete server pricing, frozen escaped terms and exact assent without browser PII storage', async () => {
    const user = userEvent.setup();
    const data = fixture();
    const fetcher = mockFlow(data);
    const store = vi.spyOn(Storage.prototype, 'setItem');
    const view = mount(data);
    await screen.findByRole('button', { name: 'Review test order' });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0][0]).toBe('/quotes/quote-1/order');
    const submit = await load(user);
    expect(screen.getByText('Seller: Synthetic test seller')).toBeInTheDocument();
    expect(screen.getByText('Promotion: TEST20')).toBeInTheDocument();
    expect(screen.getByText('Subtotal: $50 USD')).toBeInTheDocument();
    expect(screen.getByText('Discount: $10')).toBeInTheDocument();
    expect(screen.getByText('Tax basis: $40')).toBeInTheDocument();
    expect(screen.getByText('Fixed test tax: $2.80')).toBeInTheDocument();
    expect(screen.getByText('$42.80 USD')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Synthetic track full license text' })).toHaveTextContent(data.review.items[0].disclosure.termsText);
    expect(view.container.querySelector('script')).toBeNull();
    expect(screen.getByRole('checkbox', { name: data.review.assent.text })).not.toBeChecked();
    expect(submit).toBeDisabled();
    await identify(user);
    await user.click(submit);
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    expect(screen.getByText(/This prepared record alone does not confirm payment/)).toBeInTheDocument();
    const [url, request] = fetcher.mock.calls.find(([url]) => url === '/orders')!;
    expect(url).toBe('/orders');
    expect(request?.credentials).toBe('same-origin');
    expect((request?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[a-f0-9-]{36}$/);
    expect(JSON.parse(String(request?.body))).toEqual({ quoteId: 'quote-1', reviewHash: digest, buyer: { legalName: 'Synthetic Buyer', email: 'buyer@example.invalid' }, accepted: true });
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(1);
    expect(store.mock.calls).toEqual([['vaseyaudio-order-recovery-v1', JSON.stringify(['quote-1'])]]);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });

  it('creates pricing only after its GET returned 404 and preserves existing promoted pricing', async () => {
    const user = userEvent.setup();
    const data = fixture();
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(json({}, 404)).mockResolvedValueOnce(json({}, 404))
      .mockResolvedValueOnce(json({ pricing: data.pricing })).mockResolvedValueOnce(json({ review: data.review }));
    mount(data);
    await load(user);
    expect(fetcher.mock.calls.map(([url, init]) => [url, init?.method ?? 'GET'])).toEqual([
      ['/quotes/quote-1/order', 'GET'], ['/quotes/quote-1/pricing', 'GET'], ['/quotes/quote-1/pricing', 'POST'], ['/quotes/quote-1/order-review', 'GET'],
    ]);
    expect(fetcher.mock.calls[2][1]?.body).toBe('{}');
  });

  it('requires explicit assent and valid bounded buyer identity', async () => {
    const user = userEvent.setup();
    const fetcher = mockFlow();
    const view = mount();
    await load(user);
    await identify(user, false);
    fireEvent.submit(view.container.querySelector('form')!);
    expect(fetcher.mock.calls.filter(([url]) => url === '/orders')).toHaveLength(0);
    await user.click(screen.getByRole('checkbox'));
    fireEvent.change(screen.getByRole('textbox', { name: 'Email address' }), { target: { value: 'invalid-email' } });
    fireEvent.submit(view.container.querySelector('form')!);
    expect(fetcher.mock.calls.filter(([url]) => url === '/orders')).toHaveLength(0);
    expect(screen.getByRole('textbox', { name: 'Legal name' })).toHaveAttribute('maxlength', '160');
    expect(screen.getByRole('textbox', { name: 'Email address' })).toHaveAttribute('maxlength', '254');
  });

  it.each([
    ['payable review', (data: ReturnType<typeof fixture>) => { data.review.payable = true; }],
    ['unsupported schema', (data: ReturnType<typeof fixture>) => { data.review.reviewSchema = 2; }],
    ['different quote', (data: ReturnType<typeof fixture>) => { data.review.quoteId = 'other'; }],
    ['different revision', (data: ReturnType<typeof fixture>) => { data.review.items[0].offerRevisionId = 'other'; }],
    ['mismatched disclosure', (data: ReturnType<typeof fixture>) => { data.review.items[0].disclosure.disclosureHash = 'b'.repeat(64); }],
    ['different pricing', (data: ReturnType<typeof fixture>) => { data.review.pricing = { ...data.pricing, id: 'other' }; }],
    ['inconsistent money', (data: ReturnType<typeof fixture>) => { data.pricing.totalMinor++; }],
    ['missing assent', (data: ReturnType<typeof fixture>) => { data.review.assent.text = ''; }],
  ])('fails closed for %s', async (_name, change) => {
    const user = userEvent.setup();
    const data = fixture(); change(data); mockFlow(data); mount(data);
    await user.click(await screen.findByRole('button', { name: 'Review test order' }));
    expect(await screen.findByText(/The complete test order could not be reviewed/)).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  it('does not create replacement pricing after an unavailable or malformed existing pricing response', async () => {
    const user = userEvent.setup();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({}, 404)).mockResolvedValueOnce(json({ pricing: { taxStatus: 'provider_pending' } }));
    mount();
    await user.click(await screen.findByRole('button', { name: 'Review test order' }));
    expect(await screen.findByText(/The complete test order could not be reviewed/)).toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(fetcher.mock.calls.every(([, init]) => !init?.method)).toBe(true);
  });

  it('recovers a prepared order after expiry without pricing, review or another creation', async () => {
    const data = fixture();
    data.expiresAt = new Date(Date.now() - 1000).toISOString();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ order: data.order }));
    mount(data);
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Prepare test order' })).not.toBeInTheDocument();
    await waitFor(() => expect(fetcher.mock.calls.map(([url]) => url)).toEqual(['/quotes/quote-1/order', '/orders/order-1/checkout']));
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('blocks creation until recovery is checked successfully and rejects a mismatched recovered order', async () => {
    const user = userEvent.setup();
    const data = fixture();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({ order: { ...data.order, quoteId: 'other' } })).mockResolvedValueOnce(json({}, 404));
    mount(data);
    expect(await screen.findByText(/The existing test order could not be checked/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Review test order' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Retry existing order check' }));
    expect(await screen.findByRole('button', { name: 'Review test order' })).toBeEnabled();
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('deduplicates submission and retries the identical body and key after an unknown outcome even after expiry', async () => {
    const user = userEvent.setup();
    const data = fixture();
    const fetcher = mockFlow(data);
    let reject!: (error: Error) => void;
    let requests = 0;
    const normal = fetcher.getMockImplementation()!;
    fetcher.mockImplementation((input, init) => {
      if (input === '/orders' && requests++ === 0) return new Promise<Response>((_resolve, fail) => { reject = fail; });
      return normal(input, init);
    });
    mount(data); const submit = await load(user); await identify(user);
    await user.dblClick(submit);
    expect(fetcher.mock.calls.filter(([url]) => url === '/orders')).toHaveLength(1);
    expect(screen.getByRole('textbox', { name: 'Legal name' })).toBeDisabled();
    await act(async () => reject(new Error('Network outcome unknown')));
    expect(await screen.findByText(/The result is unconfirmed/)).toBeInTheDocument();
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse(data.expiresAt) + 1000);
    await user.click(screen.getByRole('button', { name: 'Retry same test order' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    const submissions = fetcher.mock.calls.filter(([url]) => url === '/orders');
    expect(submissions).toHaveLength(2);
    expect(submissions[1][1]?.body).toBe(submissions[0][1]?.body);
    expect(submissions[1][1]?.headers).toEqual(submissions[0][1]?.headers);
  });

  it('rejects a mismatched successful order response without clearing the captured retry', async () => {
    const user = userEvent.setup();
    const data = fixture();
    data.order.reviewHash = 'b'.repeat(64);
    mockFlow(data); mount(data);
    const submit = await load(user); await identify(user); await user.click(submit);
    expect(await screen.findByText(/The result is unconfirmed/)).toBeInTheDocument();
    expect(screen.queryByText('TEST ORDER PREPARED')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry same test order' })).toBeEnabled();
  });

  it('unlocks a definitive validation rejection and requires fresh assent with a new request key', async () => {
    const user = userEvent.setup(); const data = fixture(); const fetcher = mockFlow(data);
    const normal = fetcher.getMockImplementation()!;
    let submissions = 0;
    fetcher.mockImplementation((input, init) => input === '/orders' && submissions++ === 0
      ? Promise.resolve(json({ code: 'INVALID_ORDER_REQUEST' }, 422)) : normal(input, init));
    mount(data); await load(user); await identify(user);
    await user.click(screen.getByRole('button', { name: 'Prepare test order' }));
    expect(await screen.findByText(/Correct your name or email/)).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Legal name' })).toBeEnabled();
    expect(screen.getByRole('checkbox')).not.toBeChecked();
    await user.clear(screen.getByRole('textbox', { name: 'Email address' }));
    await user.type(screen.getByRole('textbox', { name: 'Email address' }), 'corrected@example.invalid');
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Prepare test order' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    const requests = fetcher.mock.calls.filter(([url]) => url === '/orders');
    expect(requests).toHaveLength(2);
    expect(requests[1][1]?.headers).not.toEqual(requests[0][1]?.headers);
    expect(JSON.parse(String(requests[1][1]?.body)).buyer.email).toBe('corrected@example.invalid');
  });

  it('discards a definitively changed review and requires acceptance of the newly displayed terms', async () => {
    const user = userEvent.setup(); const data = fixture(); const fetcher = mockFlow(data);
    const normal = fetcher.getMockImplementation()!;
    let submissions = 0;
    fetcher.mockImplementation((input, init) => input === '/orders' && submissions++ === 0
      ? Promise.resolve(json({ code: 'ORDER_REVIEW_CHANGED' }, 409)) : normal(input, init));
    mount(data); await load(user); await identify(user);
    await user.click(screen.getByRole('button', { name: 'Prepare test order' }));
    expect(await screen.findByText(/reviewed terms or availability changed/)).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    data.review.assent.text = 'New synthetic assent text for the current review.';
    data.review.reviewHash = data.order.reviewHash = 'b'.repeat(64);
    await load(user);
    const acceptance = screen.getByRole('checkbox', { name: data.review.assent.text });
    expect(acceptance).not.toBeChecked();
    await user.click(acceptance);
    await user.click(screen.getByRole('button', { name: 'Prepare test order' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    const requests = fetcher.mock.calls.filter(([url]) => url === '/orders');
    expect(requests[1][1]?.headers).not.toEqual(requests[0][1]?.headers);
    expect(JSON.parse(String(requests[1][1]?.body)).reviewHash).toBe('b'.repeat(64));
  });

  it.each(['ORDER_ALREADY_PREPARED', 'IDEMPOTENCY_CONFLICT'])('recovers the durable order for %s without creating a new quote', async code => {
    const user = userEvent.setup(); const data = fixture(); const fetcher = mockFlow(data);
    const normal = fetcher.getMockImplementation()!;
    let submitted = false;
    fetcher.mockImplementation((input, init) => {
      if (input === '/orders') { submitted = true; return Promise.resolve(json({ code }, 409)); }
      if (submitted && String(input).endsWith('/order')) return Promise.resolve(json({ order: data.order }));
      return normal(input, init);
    });
    mount(data); await load(user); await identify(user);
    await user.click(screen.getByRole('button', { name: 'Prepare test order' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    expect(fetcher.mock.calls.filter(([url]) => url === '/quotes/quote-1/order')).toHaveLength(2);
    expect(fetcher.mock.calls.filter(([url]) => url === '/orders')).toHaveLength(1);
    expect(fetcher.mock.calls.filter(([url]) => url === '/quotes')).toHaveLength(0);
  });

  it.each([[409, 'ORDER_CHANGED'], [409, 'UNRECOGNIZED_CONFLICT'], [422, 'UNRECOGNIZED_REJECTION'], [500, 'INVALID_ORDER_REQUEST']])('keeps %i %s uncertain and retries its exact body and key', async (status, code) => {
    const user = userEvent.setup(); const data = fixture(); const fetcher = mockFlow(data);
    const normal = fetcher.getMockImplementation()!;
    let submissions = 0;
    fetcher.mockImplementation((input, init) => input === '/orders' && submissions++ === 0
      ? Promise.resolve(json({ code }, Number(status))) : normal(input, init));
    mount(data); await load(user); await identify(user);
    await user.click(screen.getByRole('button', { name: 'Prepare test order' }));
    expect(await screen.findByText(/The result is unconfirmed/)).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Legal name' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Retry same test order' }));
    expect(await screen.findByText('TEST ORDER PREPARED')).toBeInTheDocument();
    const requests = fetcher.mock.calls.filter(([url]) => url === '/orders');
    expect(requests[1][1]?.headers).toEqual(requests[0][1]?.headers);
    expect(requests[1][1]?.body).toEqual(requests[0][1]?.body);
  });

  it('does not submit a newly expired review', async () => {
    const user = userEvent.setup();
    const data = fixture(); const fetcher = mockFlow(data); mount(data);
    const submit = await load(user); await identify(user);
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse(data.expiresAt) + 1000);
    await user.click(submit);
    expect(fetcher.mock.calls.filter(([url]) => url === '/orders')).toHaveLength(0);
  });

  it('ignores an old review response when the selection changes', async () => {
    const user = userEvent.setup();
    const data = fixture();
    let resolve!: (response: Response) => void;
    const fetcher = mockFlow(data);
    const normal = fetcher.getMockImplementation()!;
    fetcher.mockImplementation((input, init) => String(input).endsWith('/order-review') ? new Promise<Response>(done => { resolve = done; }) : normal(input, init));
    const view = mount(data);
    await user.click(await screen.findByRole('button', { name: 'Review test order' }));
    await waitFor(() => expect(resolve).toBeDefined());
    view.rerender(<OrderPreparation quoteId="quote-2" expiresAt={data.expiresAt} offerRevisionIds={['101']} />);
    await act(async () => resolve(json({ review: data.review })));
    expect(screen.queryByText('Seller: Synthetic test seller')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Review test order' })).toBeEnabled();
  });

  it('does not display an old prepared order or buyer identity after selection changes during submission', async () => {
    const user = userEvent.setup(); const data = fixture();
    let resolve!: (response: Response) => void;
    const fetcher = mockFlow(data); const normal = fetcher.getMockImplementation()!;
    fetcher.mockImplementation((input, init) => input === '/orders' ? new Promise<Response>(done => { resolve = done; }) : normal(input, init));
    const view = mount(data); const submit = await load(user); await identify(user); await user.click(submit);
    view.rerender(<OrderPreparation quoteId="quote-2" expiresAt={data.expiresAt} offerRevisionIds={['101']} />);
    await act(async () => resolve(json({ order: data.order })));
    expect(screen.queryByText('TEST ORDER PREPARED')).not.toBeInTheDocument();
    expect(screen.queryByDisplayValue('Synthetic Buyer')).not.toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Review test order' })).toBeEnabled();
  });
});
