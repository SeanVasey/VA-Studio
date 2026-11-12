import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SoundKitBuy } from '../../resources/js/components/SoundKitBuy';
import SoundKitOrder from '../../resources/js/Pages/SoundKitOrder';
import type { SoundKitOrderState } from '../../resources/js/lib/sound-kits';
import { stripeTestCheckoutUrl } from '../../resources/js/lib/sound-kits';
import { defaultSiteContent } from '../../resources/js/lib/site-content';

// BL-13: Stripe TEST purchase of a kit. Every id, name and price is synthetic; fetch is a stub.
const orderId = '0b8e5f7e-6f34-4a52-9a43-0d6e8d8f3e11';
const order: SoundKitOrderState = { schema: 'sound-kit-test-order-v1', orderId, testOnly: true, kit: { title: 'Synthetic Kit', slug: 'synthetic-kit' },
  priceMinor: 1499, currency: 'USD', status: 'not_started', checkoutUrl: null, deliverables: [] };
const json = (body: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

afterEach(() => vi.restoreAllMocks());

describe('kit purchase', () => {
  it('sends only the licensee name and a key, never a price or revision, then follows only a Stripe test checkout URL', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => json({ orderId, orderUrl: `/sound-kit-orders/${orderId}` }, 201))
      .mockImplementationOnce(() => json({ ...order, status: 'checkout_started', checkoutUrl: 'https://checkout.stripe.com/c/pay/cs_test_SYNTHETIC' }));
    const navigate = vi.fn();
    const user = userEvent.setup();
    render(<SoundKitBuy slug="synthetic-kit" title="Synthetic Kit" priceLabel="$14.99" navigate={navigate} />);
    expect(screen.getByRole('region', { name: 'Buy this kit (test mode)' })).toHaveTextContent('no real payment is taken');
    await user.type(screen.getByLabelText('Licensee legal name'), '  Ada Synthetic ');
    await user.click(screen.getByRole('button', { name: 'Buy with Stripe test checkout' }));
    expect(fetchMock).toHaveBeenCalledTimes(2);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/sound-kits/synthetic-kit/orders');
    const body = JSON.parse(String((init as RequestInit).body));
    expect(Object.keys(body).sort()).toEqual(['idempotencyKey', 'licenseeName']);
    expect(body.licenseeName).toBe('Ada Synthetic');
    expect(fetchMock.mock.calls[1][0]).toBe(`/sound-kit-orders/${orderId}/checkout`);
    expect(navigate).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_test_SYNTHETIC');
  });

  it('shows the server refusal and does not navigate', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => json({ code: 'LICENSEE_NAME_INVALID', message: 'Enter the licensee\'s legal name in Latin characters.' }, 422));
    const navigate = vi.fn();
    const user = userEvent.setup();
    render(<SoundKitBuy slug="synthetic-kit" title="Synthetic Kit" priceLabel="$14.99" navigate={navigate} />);
    await user.type(screen.getByLabelText('Licensee legal name'), 'Ада');
    await user.click(screen.getByRole('button', { name: 'Buy with Stripe test checkout' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Latin characters');
    expect(navigate).not.toHaveBeenCalled();
  });

  it('keeps one idempotency key while the licensee name is unchanged and starts a new one when it changes', async () => {
    const refusal = () => json({ code: 'CHECKOUT_UNAVAILABLE', message: 'Test checkout is unavailable.' }, 503);
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(refusal);
    const user = userEvent.setup();
    render(<SoundKitBuy slug="synthetic-kit" title="Synthetic Kit" priceLabel="$14.99" navigate={vi.fn()} />);
    const input = screen.getByLabelText('Licensee legal name');
    const button = screen.getByRole('button', { name: 'Buy with Stripe test checkout' });
    const sent = (call: number) => JSON.parse(String((fetchMock.mock.calls[call][1] as RequestInit).body));
    await user.type(input, 'Ada Synthetic');
    await user.click(button);
    await screen.findByRole('alert');
    await user.click(button);
    await screen.findByRole('alert');
    expect(sent(1)).toEqual(sent(0));
    await user.clear(input);
    await user.type(input, 'Grace Synthetic');
    await user.click(button);
    await screen.findByRole('alert');
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(sent(2).licenseeName).toBe('Grace Synthetic');
    expect(sent(2).idempotencyKey).toMatch(/^kit-[0-9a-f]{32}$/);
    expect(sent(2).idempotencyKey).not.toBe(sent(0).idempotencyKey);
  });

  it('accepts only Stripe test checkout addresses', () => {
    expect(stripeTestCheckoutUrl('https://checkout.stripe.com/c/pay/cs_test_X')).toBe(true);
    for (const bad of ['https://checkout.stripe.com/c/pay/cs_live_X', 'http://checkout.stripe.com/c/pay/cs_test_X', 'https://evil.example/c/pay/cs_test_X', null]) {
      expect(stripeTestCheckoutUrl(bad)).toBe(false);
    }
  });
});

describe('kit order page', () => {
  it('offers checkout and a payment check before payment, and no download', () => {
    render(<SoundKitOrder siteContent={defaultSiteContent} order={order} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Synthetic Kit' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('No Stripe test checkout has been started');
    expect(screen.getByRole('button', { name: 'Continue to Stripe test checkout' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check payment status' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Download/ })).toBeNull();
  });

  it('continues a started checkout through the server, following only the open session URL it returns', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => json({ ...order, status: 'checkout_started', checkoutUrl: 'https://checkout.stripe.com/c/pay/cs_test_SYNTHETIC' }))
      .mockImplementationOnce(() => json({ ...order, status: 'checkout_started', checkoutUrl: null }));
    const navigate = vi.fn();
    const user = userEvent.setup();
    render(<SoundKitOrder siteContent={defaultSiteContent} order={{ ...order, status: 'checkout_started' }} navigate={navigate} />);
    expect(screen.queryByRole('button', { name: 'Continue to Stripe test checkout' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Check payment status' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Continue to checkout' }));
    expect(fetchMock.mock.calls[0][0]).toBe(`/sound-kit-orders/${orderId}/checkout`);
    expect((fetchMock.mock.calls[0][1] as RequestInit).method).toBe('POST');
    expect(navigate).toHaveBeenCalledExactlyOnceWith('https://checkout.stripe.com/c/pay/cs_test_SYNTHETIC');
    // A session that is no longer open returns no URL: nothing is followed and the buyer is told to check the payment.
    await user.click(screen.getByRole('button', { name: 'Continue to checkout' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('no longer open');
    expect(navigate).toHaveBeenCalledTimes(1);
  });

  it('lets the owner ask for the grant again while the order is paid but not yet granted', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => json({ ...order, status: 'awaiting_contract' }));
    const user = userEvent.setup();
    render(<SoundKitOrder siteContent={defaultSiteContent} order={{ ...order, status: 'paid' }} />);
    expect(screen.queryByRole('button', { name: /checkout/i })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Check payment status' }));
    expect(fetchMock.mock.calls[0][0]).toBe(`/sound-kit-orders/${orderId}/checkout/reconcile`);
    expect(await screen.findByRole('status')).toHaveTextContent('license contract is being prepared');
    expect(screen.queryByRole('button', { name: 'Check payment status' })).toBeNull();
  });

  it('downloads through a fresh authorization posted as a form, never a stored link', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => json({ authorizationId: 'a-1', token: 'f'.repeat(64), kind: 'archive', expiresAt: '2026-11-11T00:01:00Z' }, 201));
    const submitted: Array<Record<string, string>> = [];
    const submitForm = (form: HTMLFormElement) => submitted.push({ action: form.getAttribute('action') ?? '', method: form.method,
      ...Object.fromEntries(Array.from(form.querySelectorAll('input')).map(input => [input.name, input.value])) });
    const user = userEvent.setup();
    render(<SoundKitOrder siteContent={defaultSiteContent} order={{ ...order, status: 'downloadable', deliverables: ['archive', 'contract'] }} submitForm={submitForm} />);
    const main = within(screen.getByRole('main'));
    expect(main.queryByRole('button', { name: /checkout/i })).toBeNull();
    await user.click(main.getByRole('button', { name: 'Download the kit (ZIP)' }));
    expect(JSON.parse(String((fetchMock.mock.calls[0][1] as RequestInit).body))).toEqual({ kind: 'archive' });
    expect(submitted).toEqual([{ action: `/sound-kit-orders/${orderId}/delivery/download`, method: 'post', authorizationId: 'a-1', token: 'f'.repeat(64), _token: '' }]);
    expect(document.querySelector('form[hidden]')).toBeNull();
  });
});

describe('kit order page automatic refresh', () => {
  const flush = async (ms: number) => { await act(async () => { await vi.advanceTimersByTimeAsync(ms); }); };
  const statusUrl = `/sound-kit-orders/${orderId}/status`;
  const at = (status: SoundKitOrderState['status']) => ({ ...order, status });
  const page = (status: SoundKitOrderState['status']) => render(<SoundKitOrder siteContent={defaultSiteContent} order={at(status)} />);
  afterEach(() => vi.useRealTimers());

  it('polls the owner-authorized status read every 10 seconds until the kit is downloadable, announcing it politely', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => json(at('awaiting_contract'))).mockImplementationOnce(() => json(at('awaiting_delivery')))
      .mockImplementationOnce(() => json({ ...at('downloadable'), deliverables: ['archive', 'contract'] }));
    page('paid');
    const note = screen.getByTestId('order-auto-refresh');
    expect(note).toHaveTextContent('Checking for updates automatically');
    expect(note).toHaveAttribute('aria-live', 'polite');
    await flush(9_999);
    expect(fetcher).not.toHaveBeenCalled();
    await flush(1);
    expect(screen.getByRole('status')).toHaveTextContent('license contract is being prepared');
    await flush(10_000);
    expect(screen.getByRole('status')).toHaveTextContent('Test downloads open once delivery is enabled');
    await flush(10_000);
    expect(screen.getByRole('status')).toHaveTextContent('Each download link is single-use');
    expect(screen.getByRole('button', { name: 'Download the kit (ZIP)' })).toBeInTheDocument();
    expect(screen.queryByTestId('order-auto-refresh')).toBeNull();
    await flush(60_000);
    expect(fetcher).toHaveBeenCalledTimes(3);
    expect(fetcher.mock.calls.every(([url, init]) => url === statusUrl && init?.method === 'GET')).toBe(true);
  });

  it.each(['not_started', 'checkout_started', 'expired', 'downloadable'] as const)('does not poll a %s order', async status => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch');
    page(status);
    await flush(60_000);
    expect(fetcher).not.toHaveBeenCalled();
    expect(screen.queryByTestId('order-auto-refresh')).toBeNull();
  });

  it('refuses a read that would move the order backwards, shows the error treatment and stops', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => json(at('awaiting_contract'))).mockImplementation(() => json(at('paid')));
    page('paid');
    await flush(10_000);
    expect(screen.getByRole('status')).toHaveTextContent('license contract is being prepared');
    await flush(10_000);
    // The older `paid` read is not applied; the newer state stays and polling stops with the alert.
    expect(screen.getByRole('status')).toHaveTextContent('license contract is being prepared');
    expect(screen.getByRole('alert')).toHaveTextContent('could not be loaded');
    expect(screen.getByTestId('order-auto-refresh')).toHaveTextContent('Automatic checking has stopped because the status could not be loaded');
    await flush(60_000);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('stops on the first failed read', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => json({ code: 'UNAVAILABLE', message: 'Nope.' }, 500));
    page('awaiting_contract');
    await flush(10_000);
    expect(screen.getByRole('alert')).toHaveTextContent('could not be loaded');
    await flush(60_000);
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('stops after about fifteen minutes', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => json(at('awaiting_delivery')));
    page('awaiting_delivery');
    await flush(900_000);
    const calls = fetcher.mock.calls.length;
    expect(calls).toBeGreaterThanOrEqual(89);
    expect(calls).toBeLessThanOrEqual(91);
    await flush(60_000);
    expect(fetcher).toHaveBeenCalledTimes(calls);
    expect(screen.getByTestId('order-auto-refresh')).toHaveTextContent('Automatic checking has stopped after 15 minutes');
  });

  it('stops polling on unmount', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => json(at('paid')));
    const { unmount } = page('paid');
    await flush(10_000);
    expect(fetcher).toHaveBeenCalledTimes(1);
    unmount();
    await flush(60_000);
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('does not start a poll while an owner action is running, using the current render rather than the one that started the timer', async () => {
    vi.useFakeTimers();
    let finish: (value: Response) => void = () => undefined;
    const fetcher = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }))
      .mockImplementation(() => json(at('paid')));
    render(<SoundKitOrder siteContent={defaultSiteContent} order={at('paid')} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check payment status' }));
    await flush(110_000);
    // Only the reconcile POST is in flight: the interval saw busy=true through the latest-request ref.
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect((fetcher.mock.calls[0][1] as RequestInit).method).toBe('POST');
    await act(async () => { finish(new Response(JSON.stringify(at('awaiting_contract')), { status: 200, headers: { 'Content-Type': 'application/json' } })); await vi.advanceTimersByTimeAsync(0); });
    expect(screen.getByRole('status')).toHaveTextContent('license contract is being prepared');
  });

  it('discards a poll that was in flight when a newer owner write landed', async () => {
    vi.useFakeTimers();
    let release: (value: Response) => void = () => undefined;
    vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => new Promise<Response>(resolve => { release = resolve; }))
      .mockImplementationOnce(() => json(at('awaiting_delivery')));
    render(<SoundKitOrder siteContent={defaultSiteContent} order={at('paid')} />);
    await flush(10_000);
    fireEvent.click(screen.getByRole('button', { name: 'Check payment status' }));
    await flush(0);
    expect(screen.getByRole('status')).toHaveTextContent('Test downloads open once delivery is enabled');
    await act(async () => { release(new Response(JSON.stringify(at('awaiting_contract')), { status: 200, headers: { 'Content-Type': 'application/json' } })); await vi.advanceTimersByTimeAsync(0); });
    expect(screen.getByRole('status')).toHaveTextContent('Test downloads open once delivery is enabled');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('drops a slow reconcile response that arrives after a newer poll result, keeping the download buttons', async () => {
    vi.useFakeTimers();
    let pollDone: (value: Response) => void = () => undefined;
    let reconcileDone: (value: Response) => void = () => undefined;
    const reply = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => new Promise<Response>(resolve => { pollDone = resolve; }))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { reconcileDone = resolve; }));
    render(<SoundKitOrder siteContent={defaultSiteContent} order={at('paid')} />);
    await flush(10_000); // poll starts and stays in flight
    fireEvent.click(screen.getByRole('button', { name: 'Check payment status' })); // reconcile starts after the poll
    await flush(0);
    await act(async () => { pollDone(reply({ ...at('downloadable'), deliverables: ['archive', 'contract'] })); await vi.advanceTimersByTimeAsync(0); });
    expect(screen.getByRole('button', { name: 'Download the kit (ZIP)' })).toBeInTheDocument();
    await act(async () => { reconcileDone(reply(at('paid'))); await vi.advanceTimersByTimeAsync(0); });
    expect(screen.getByRole('status')).toHaveTextContent('Each download link is single-use');
    expect(screen.getByRole('button', { name: 'Download the kit (ZIP)' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Check payment status' })).toBeNull();
  });

  it('applies a reconcile response when no status read landed meanwhile', async () => {
    vi.useFakeTimers();
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => json(at('awaiting_contract')));
    render(<SoundKitOrder siteContent={defaultSiteContent} order={at('paid')} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check payment status' }));
    await flush(0);
    expect(screen.getByRole('status')).toHaveTextContent('license contract is being prepared');
  });
});
