import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CustomerOrderLookup } from '../../resources/js/components/CustomerOrderLookup';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { lookupCustomerOrder, normalizeOrderReference } from '../../resources/js/lib/customer-order-reference';

vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
const id = (n = 1) => `740000ab-0000-4000-8000-${String(n).padStart(12, '0')}`;
const summary = (n = 1) => ({ id: id(n), createdAt: '2026-10-01T12:00:00.000000Z', testOnly: true,
  payable: false, currency: 'USD', totalMinor: 4999, status: 'paid', paymentStatus: 'verified',
  finalizationStatus: 'paid', contractStatus: 'issued', fulfillmentStatus: 'pending_activation' });
const order = (n = 1) => ({ ...summary(n), orderSchema: 1, quoteId: id(91), pricingId: id(92), reviewHash: 'a'.repeat(64) });
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const fixture = () => <CustomerOrderLookup renderOrder={entry => <p>Original order {entry.id}</p>} />;
const field = () => screen.getByRole('textbox', { name: 'Order reference' });
const submit = () => fireEvent.submit(screen.getByRole('button', { name: 'Find order' }).closest('form')!);
const change = (value: string) => fireEvent.change(field(), { target: { value } });
afterEach(() => vi.useRealTimers());

describe('account order reference transport', () => {
  it('normalizes only a complete UUID and requests its existing protected read endpoint once', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ order: order() }));
    const signal = new AbortController().signal;
    expect(await lookupCustomerOrder(`  ${id().toUpperCase()}  `, signal)).toEqual({ kind: 'found', order: summary() });
    expect(fetcher).toHaveBeenCalledExactlyOnceWith(`/orders/${id()}/status`, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
      headers: { Accept: 'application/json' },
    });
  });

  it.each(['', '   ', 'partial', id() + '/delivery', 'https://example.test/' + id(), '../' + id(), id() + '?owner=PRIVATE',
    id().replace('-4000-', '-1000-'), id().replace('-8000-', '-0000-'), id() + '\u0000', '\u0000' + id(), id() + id()])('refuses invalid reference %s before HTTP', async value => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    expect(normalizeOrderReference(value)).toBeNull();
    expect((await lookupCustomerOrder(value, new AbortController().signal)).kind).toBe('invalid');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it.each(['unknown', 'foreign customer', 'guest'])('never reads or reflects the %s private404 body', async kind => {
    const response = new Response(`PRIVATE ${kind}`, { status: 404 }); const reader = vi.spyOn(response.body!, 'getReader');
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response);
    expect(await lookupCustomerOrder(id(), new AbortController().signal)).toEqual({ kind: 'not_found',
      message: 'This order is not available to your account. Check the reference or browse your account orders.' });
    expect(reader).not.toHaveBeenCalled();
  });

  it.each([401, 403, 419])('requires fresh account access after%s without using the response body', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ order: order(), message: 'PRIVATE' }, status));
    const result = await lookupCustomerOrder(id(), new AbortController().signal);
    expect(result.kind).toBe('reload'); expect(JSON.stringify(result)).not.toContain('PRIVATE');
  });

  it.each([
    ['different order', { order: order(2) }], ['private envelope', { order: order(), owner: 'PRIVATE' }],
    ['private order field', { order: { ...order(), buyer: 'PRIVATE' } }], ['live mode', { order: { ...order(), testOnly: false } }],
    ['unsupported schema', { order: { ...order(), orderSchema: 2 } }], ['missing quote', { order: { ...order(), quoteId: null } }],
    ['invalid pricing id', { order: { ...order(), pricingId: 'PRIVATE' } }], ['invalid review', { order: { ...order(), reviewHash: 'a'.repeat(63) } }],
    ['invalid amount', { order: { ...order(), totalMinor: -1 } }], ['incoherent payment', { order: { ...order(), paymentStatus: 'not_started' } }],
    ['array', []], ['missing body', {}], ['null', null],
  ])('rejects %s without retaining unverified data', async (_case, body) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(body));
    const result = await lookupCustomerOrder(id(), new AbortController().signal);
    expect(result.kind).toBe('unavailable'); expect(JSON.stringify(result)).not.toContain('PRIVATE');
  });

  it.each(['network', 'html', 'bad-json', 'wrong-content-type', 'redirect', 'oversized', 'server-error'])('fails closed for %s transport', async kind => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    if (kind === 'network') fetcher.mockRejectedValue(new Error('PRIVATE'));
    else {
      const response = kind === 'oversized' ? json('PRIVATE'.repeat(128 * 1024))
        : kind === 'bad-json' ? new Response('PRIVATE', { headers: { 'Content-Type': 'application/json' } })
        : kind === 'html' ? new Response('<html>PRIVATE</html>')
        : kind === 'wrong-content-type' ? new Response(JSON.stringify({ order: order() }))
        : json({ order: order() }, kind === 'server-error' ? 503 : 200);
      if (kind === 'redirect') Object.defineProperty(response, 'redirected', { value: true });
      fetcher.mockResolvedValue(response);
    }
    expect((await lookupCustomerOrder(id(), new AbortController().signal)).kind).toBe('unavailable');
  });
});

describe('account library order lookup', () => {
  it('finds an exact order by keyboard without loading history, storage access or URL changes', async () => {
    const user = userEvent.setup(); const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ order: order() }));
    const read = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Disabled'); });
    const write = vi.spyOn(Storage.prototype, 'setItem'); const url = window.location.href;
    render(fixture()); expect(fetcher).not.toHaveBeenCalled();
    await user.type(field(), id().toUpperCase()); await user.keyboard('{Enter}');
    expect(await screen.findByRole('heading', { name: 'Order found' })).toHaveFocus();
    expect(screen.getByText(`Original order ${id()}`)).toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(1); expect(read).not.toHaveBeenCalled(); expect(write).not.toHaveBeenCalled();
    expect(window.location.href).toBe(url);
    await user.click(screen.getByRole('button', { name: 'Clear order lookup' }));
    expect(field()).toHaveValue(''); expect(field()).toHaveFocus(); expect(screen.queryByText(`Original order ${id()}`)).not.toBeInTheDocument();
  });

  it('clears the old order immediately on editing or unavailable lookup and focuses a generic result', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({ order: order() })).mockResolvedValueOnce(json({ message: 'PRIVATE' }, 404));
    render(fixture()); change(id()); submit(); await screen.findByText(`Original order ${id()}`);
    change(id(2)); expect(screen.queryByText(`Original order ${id()}`)).not.toBeInTheDocument(); submit();
    expect(await screen.findByRole('alert')).toHaveFocus(); expect(screen.getByRole('alert')).toHaveTextContent('not available to your account');
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument(); expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('discards duplicate submissions and stale responses after editing, clearing or unmounting', async () => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json({ order: order(2) }));
    const view = render(fixture()); change(id()); submit(); fireEvent.submit(screen.getByRole('button', { name: 'Finding order…' }).closest('form')!);
    expect(fetcher).toHaveBeenCalledTimes(1); const oldSignal = fetcher.mock.calls[0][1]?.signal;
    change(id(2)); expect(oldSignal?.aborted).toBe(true); submit(); await screen.findByText(`Original order ${id(2)}`);
    await act(async () => finish(json({ order: order() })));
    expect(screen.queryByText(`Original order ${id()}`)).not.toBeInTheDocument();
    view.unmount(); render(fixture()); expect(field()).toHaveValue(''); expect(screen.queryByText(`Original order ${id(2)}`)).not.toBeInTheDocument();
  });

  it.each(['clear', 'unmount', 'pagehide'])('aborts and ignores a pending response on%s', async action => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const view = render(fixture()); change(id()); submit();
    if (action === 'clear') fireEvent.click(screen.getByRole('button', { name: 'Clear order lookup' }));
    if (action === 'unmount') { view.unmount(); render(fixture()); }
    if (action === 'pagehide') fireEvent(window, new Event('pagehide'));
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    await act(async () => finish(json({ order: order() })));
    expect(field()).toHaveValue(''); expect(screen.queryByText(`Original order ${id()}`)).not.toBeInTheDocument();
  });

  it('provides deliberate recovery after timeout without automatically retrying', async () => {
    vi.useFakeTimers(); const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation((_input, init) => new Promise((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    }));
    render(fixture()); change(id()); submit(); await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(screen.getByRole('alert')).toHaveTextContent('could not be loaded');
    expect(screen.getByRole('button', { name: 'Find order' })).toBeEnabled(); expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('removes a previously found order when access is withdrawn and offers a fresh sign-in', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({ order: order() })).mockResolvedValueOnce(json({ order: order() }, 403));
    render(fixture()); change(id()); submit(); await screen.findByText(`Original order ${id()}`); submit();
    expect(screen.queryByText(`Original order ${id()}`)).not.toBeInTheDocument();
    expect(await screen.findByRole('alert')).toHaveTextContent('account access could not be confirmed');
    expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
    expect(screen.getByRole('button', { name: 'Find order' })).toBeDisabled();
  });

  it('unmounts and cancels pending private lookup immediately when signing out of the library', async () => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json({}, 419));
    render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic Customer' }} />);
    change(id()); submit(); fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    expect(screen.queryByRole('region', { name: 'Find an account order' })).not.toBeInTheDocument();
    await act(async () => finish(json({ order: order() })));
    expect(screen.queryByRole('heading', { name: 'Order found' })).not.toBeInTheDocument();
    expect(screen.queryByText('Synthetic Customer')).not.toBeInTheDocument();
  });
});
