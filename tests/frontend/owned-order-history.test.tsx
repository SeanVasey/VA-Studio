import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { OwnedTestOrderHistory, validOrderHistory } from '../../resources/js/components/OwnedTestOrderHistory';
import { HISTORY_MAX_BYTES, readOwnedOrderHistory } from '../../resources/js/lib/owned-order-history';
import { PreparedOrderRecovery } from '../../resources/js/components/OrderPreparation';

const id = (number = 1) => `730000ab-0000-4000-8000-${String(number).padStart(12, '0')}`;
const order = (number = 1) => ({ id: id(number), createdAt: '2026-10-01T12:00:00.000000Z',
  testOnly: true as const, payable: false as const, currency: 'USD' as const, totalMinor: 4999, status: 'prepared' as const,
  paymentStatus: 'not_started' as const, finalizationStatus: 'not_started' as const,
  contractStatus: 'not_started' as const, fulfillmentStatus: 'not_started' as const });
const preview = (orderId = id()) => ({ orderId, itemCount: 1, firstItem: { title: 'Original track', licenseName: 'Original license', licenseVersion: 1 } });
const page = (orders = [order()], nextCursor: string | null = null) => ({ orderHistorySchema: 2, testOnly: true, orders, previews: orders.map(entry => preview(entry.id)), limit: 20, nextCursor });
const json = (history: unknown, status = 200) => new Response(JSON.stringify({ history }), { status, headers: { 'Content-Type': 'application/json' } });
const element = () => <OwnedTestOrderHistory renderOrder={entry => <p>Session order {entry.id}</p>} />;

describe('current session owned test order history', () => {
  it('requests no history until selected and needs no persisted tab locators or identity', async () => {
    const user = userEvent.setup(); const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(page()));
    const storageRead = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Unavailable'); });
    const storageWrite = vi.spyOn(Storage.prototype, 'setItem');
    render(element()); expect(fetcher).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` }));
    expect(await screen.findByText(`Session order ${id()}`)).toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledExactlyOnceWith('/orders/history', expect.objectContaining({
      credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
    }));
    expect(storageRead).not.toHaveBeenCalled(); expect(storageWrite).not.toHaveBeenCalled();
  });

  it('reuses the status display for server-discovered orders even when tab storage is unavailable', async () => {
    const user = userEvent.setup();
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Unavailable'); });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input) === '/orders/history'
      ? json(page()) : new Response('{}', { status: 503 }));
    render(<PreparedOrderRecovery />);
    await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` }));
    expect(screen.getAllByText(`Order ${id()}`)).toHaveLength(2);
    expect(screen.getByText('Prepared total: $49.99 USD')).toBeInTheDocument();
    expect(screen.getByText(/prepared record alone does not confirm payment/)).toBeInTheDocument();
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('pages through the server cursor without appending stale pages and refreshes from newest', async () => {
    const user = userEvent.setup(); const first = Array.from({ length: 20 }, (_, index) => order(index + 1));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page(first, id(20))))
      .mockResolvedValueOnce(json(page([order(21)]))).mockResolvedValueOnce(json(page(first, id(20))));
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByText(`Order ${id(21)}`)).toBeInTheDocument();
    expect(screen.queryByText(`Session order ${id()}`)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Older test orders' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh test orders' }));
    expect(await screen.findByText(`Order ${id()}`)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Available test orders' })).toHaveFocus();
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual(['/orders/history', `/orders/history?before=${id(20)}`, '/orders/history']);
  });

  it('shows empty and private retry states without reflecting failed responses or performing writes', async () => {
    const user = userEvent.setup(); const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page([])))
      .mockResolvedValueOnce(new Response('PRIVATE_ERROR_BODY', { status: 503 })).mockResolvedValueOnce(json(page()));
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    expect(await screen.findByRole('status')).toHaveTextContent('No test orders are available.');
    await user.click(screen.getByRole('button', { name: 'Refresh test orders' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('history could not be loaded');
    expect(screen.queryByText(/PRIVATE_ERROR_BODY/)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh test orders' }));
    expect(await screen.findByText(`Order ${id()}`)).toBeInTheDocument();
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('ignores responses after unmount and does not carry history into a new instance', async () => {
    const user = userEvent.setup(); let finish!: (response: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json(page([])));
    const first = render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    first.unmount(); render(element()); await act(async () => finish(json(page())));
    expect(screen.queryByText(`Session order ${id()}`)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    expect(await screen.findByRole('status')).toHaveTextContent('No test orders are available.');
  });

  it('rejects an oversized history response before exposing any order', async () => {
    const user = userEvent.setup(); vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(' '.repeat(HISTORY_MAX_BYTES + 1), { headers: { 'Content-Type': 'application/json' } }));
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('history could not be loaded');
    expect(screen.queryByText(`Session order ${id()}`)).not.toBeInTheDocument();
  });

  it.each([
    ['live mode', { testOnly: false }], ['wrong version', { orderHistorySchema: 1 }], ['unknown field', { ownerKey: 'PRIVATE' }],
    ['oversized page', { orders: Array.from({ length: 21 }, (_, index) => order(index + 1)) }],
    ['duplicate rows', { orders: [order(), order()] }], ['private row field', { orders: [{ ...order(), buyer: { email: 'PRIVATE' } }] }],
    ['wrong identity', { orders: [{ ...order(), id: 'not-an-id' }] }], ['bad amount', { orders: [{ ...order(), totalMinor: -1 }] }],
    ['identifier newline suffix', { orders: [{ ...order(), id: id() + '\n' }] }],
    ['incoherent verified state', { orders: [{ ...order(), status: 'paid' }] }],
    ['nonowned extra cursor', { nextCursor: id(99) }], ['wrong page bound', { limit: 21 }],
  ])('rejects malformed bounded summary schema: %s', (_name, changes) => {
    expect(validOrderHistory({ ...page(), ...changes })).toBe(false);
  });
});

describe('recognizable original purchases and fresh cursor navigation', () => {
  it('identifies the first item without presenting its license as every line or granting availability', async () => {
    const history = page(); history.previews[0] = { orderId: id(), itemCount: 3, firstItem: { title: '<img src=x onerror=alert(1)>', licenseName: 'Original license', licenseVersion: 7 } };
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(history));
    render(element()); await userEvent.setup().click(screen.getByRole('button', { name: 'Browse test orders' }));
    expect(await screen.findByRole('heading', { name: '<img src=x onerror=alert(1)>' })).toBeInTheDocument();
    expect(screen.getByText('First original item · 3 items in this order')).toBeInTheDocument();
    expect(screen.getByText('Original license · Version 7')).toBeInTheDocument();
    expect(screen.getByText('Payment has not been verified.')).toBeInTheDocument();
    expect(document.querySelector('img[src=x]')).toBeNull();
    expect(screen.queryByRole('button', { name: /Download/ })).toBeNull();
  });

  it('freshly fetches three pages in both directions and Newest observes a later insertion', async () => {
    const user = userEvent.setup();
    const first = Array.from({ length: 20 }, (_, i) => order(i + 1));
    const second = Array.from({ length: 20 }, (_, i) => order(i + 21));
    const inserted = [order(99), ...first.slice(0, 19)];
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page(first, id(20))))
      .mockResolvedValueOnce(json(page(second, id(40)))).mockResolvedValueOnce(json(page([order(41)])))
      .mockResolvedValueOnce(json(page(second, id(40)))).mockResolvedValueOnce(json(page([order(41)])))
      .mockResolvedValueOnce(json(page(inserted, id(19)))).mockResolvedValueOnce(json(page([order(20)])));
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` }));
    await user.click(screen.getByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByText(`Order ${id(21)}`)).toBeInTheDocument();
    expect(screen.queryByText(`Session order ${id()}`)).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByText(`Order ${id(41)}`)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Older test orders' })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Newer test orders' }));
    expect(await screen.findByText(`Order ${id(21)}`)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByText(`Order ${id(41)}`)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Newest test orders' }));
    expect(await screen.findByText(`Order ${id(99)}`)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Newer test orders' })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByText(`Order ${id(20)}`)).toBeInTheDocument();
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual(['/orders/history', `/orders/history?before=${id(20)}`, `/orders/history?before=${id(40)}`,
      `/orders/history?before=${id(20)}`, `/orders/history?before=${id(40)}`, '/orders/history', `/orders/history?before=${id(19)}`]);
    expect(fetcher.mock.calls.every(([, init]) => init?.method === 'GET' && init?.cache === 'no-store')).toBe(true);
  });

  it('withdrawal after an older-page request clears rows, details and all page navigation', async () => {
    const first = Array.from({ length: 20 }, (_, i) => order(i + 1));
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page(first, id(20)))).mockResolvedValueOnce(json({ private: 'DO_NOT_RENDER' }, 403));
    const user = userEvent.setup(); render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` }));
    await user.click(screen.getByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByRole('alert')).toHaveFocus();
    expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
    expect(screen.queryByText(`Order ${id()}`)).toBeNull(); expect(screen.queryByText(`Session order ${id()}`)).toBeNull();
    expect(screen.queryByText(/DO_NOT_RENDER/)).toBeNull();
    expect(screen.queryByRole('button', { name: /Older|Newer|Newest/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Refresh test orders' })).toBeDisabled();
  });

  it('a timed-out old completion and finally cannot replace or unlock a newer request', async () => {
    vi.useFakeTimers();
    try {
      let oldFinish!: (response: Response) => void, newFinish!: (response: Response) => void;
      const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { oldFinish = resolve; }))
        .mockImplementationOnce(() => new Promise(resolve => { newFinish = resolve; }));
      render(element()); fireEvent.click(screen.getByRole('button', { name: 'Browse test orders' }));
      await act(async () => vi.advanceTimersByTimeAsync(20_000));
      expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
      expect(screen.getByRole('alert')).toBeInTheDocument();
      fireEvent.click(screen.getByRole('button', { name: 'Refresh test orders' }));
      await act(async () => oldFinish(json(page())));
      expect(screen.queryByText(`Order ${id()}`)).toBeNull();
      expect(screen.getByRole('button', { name: 'Loading test orders…' })).toBeDisabled();
      fireEvent.click(screen.getByRole('button', { name: 'Loading test orders…' }));
      expect(fetcher).toHaveBeenCalledTimes(2);
      await act(async () => newFinish(json(page([order(2)]))));
      expect(screen.getByText(`Order ${id(2)}`)).toBeInTheDocument();
    } finally { vi.useRealTimers(); }
  });

  it('pagehide removes expanded private details and late responses cannot restore the abandoned page', async () => {
    let finish!: (response: Response) => void;
    const first = Array.from({ length: 20 }, (_, i) => order(i + 1));
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page(first, id(20))))
      .mockImplementationOnce(() => new Promise(resolve => { finish = resolve; })).mockResolvedValueOnce(json(page([])));
    const user = userEvent.setup(); render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` }));
    act(() => window.dispatchEvent(new Event('pagehide')));
    expect(screen.queryByText(`Session order ${id()}`)).toBeNull(); expect(screen.queryByRole('button', { name: /Older|Newer|Newest/ })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    act(() => window.dispatchEvent(new Event('pagehide')));
    expect(fetcher.mock.calls[1][1]?.signal?.aborted).toBe(true);
    await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await act(async () => finish(json(page(first, id(20)))));
    expect(await screen.findByRole('status')).toHaveTextContent('No test orders are available.');
    expect(screen.queryByText(`Order ${id()}`)).toBeNull();
  });

  it('refuses a repeated cursor and requires an explicit fresh newest page', async () => {
    const first = Array.from({ length: 20 }, (_, i) => order(i + 1));
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page(first, id(20)))).mockResolvedValueOnce(json(page(first, id(20))));
    const user = userEvent.setup(); render(element()); await user.click(screen.getByRole('button', { name: 'Browse test orders' }));
    await user.click(await screen.findByRole('button', { name: 'Older test orders' }));
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByText(`Order ${id()}`)).toBeNull(); expect(screen.queryByRole('button', { name: /Older|Newer|Newest/ })).toBeNull();
  });

  it.each([
    ['missing previews', { previews: [] }], ['extra preview', { previews: [preview(), preview(id(2))] }],
    ['foreign preview', { previews: [preview(id(2))] }], ['private preview field', { previews: [{ ...preview(), owner: 'PRIVATE' }] }],
    ['empty order', { previews: [{ ...preview(), itemCount: 0 }] }], ['too many items', { previews: [{ ...preview(), itemCount: 11 }] }],
    ['fractional items', { previews: [{ ...preview(), itemCount: 1.5 }] }],
    ['unknown item field', { previews: [{ ...preview(), firstItem: { ...preview().firstItem, hash: 'PRIVATE' } }] }],
    ['long unicode title', { previews: [{ ...preview(), firstItem: { ...preview().firstItem, title: '🎧'.repeat(256) } }] }],
    ['unpaired surrogate', { previews: [{ ...preview(), firstItem: { ...preview().firstItem, title: '\ud800' } }] }],
    ['invalid license version', { previews: [{ ...preview(), firstItem: { ...preview().firstItem, licenseVersion: 0 } }] }],
  ])('rejects incoherent original previews: %s', (_name, changes) => {
    expect(validOrderHistory({ ...page(), ...changes })).toBe(false);
  });

  it('accepts the maximum escaped Unicode page inside its separate streamed byte ceiling', async () => {
    const history = page(Array.from({ length: 20 }, (_, i) => order(i + 1)), id(20));
    for (const entry of history.previews) entry.firstItem = { title: '🎧'.repeat(255), licenseName: '🎧'.repeat(255), licenseVersion: 2147483647 };
    const bytes = JSON.stringify({ history }).replace(/[\u007f-\uffff]/g, character => `\\u${character.charCodeAt(0).toString(16).padStart(4, '0')}`);
    expect(bytes.length).toBeGreaterThan(128 * 1024); expect(bytes.length).toBeLessThan(HISTORY_MAX_BYTES);
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(bytes, { headers: { 'Content-Type': 'application/json' } }));
    expect(await readOwnedOrderHistory(null, new AbortController().signal)).toEqual({ kind: 'loaded', history });
  });

  it('cancels an oversized stream without consuming its private remainder', async () => {
    const cancel = vi.fn(); let calls = 0;
    const body = new ReadableStream<Uint8Array>({ pull(controller) { calls++; controller.enqueue(new Uint8Array(HISTORY_MAX_BYTES + 1)); }, cancel });
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(body, { headers: { 'Content-Type': 'application/json' } }));
    expect(await readOwnedOrderHistory(null, new AbortController().signal)).toEqual({ kind: 'unavailable' });
    expect(cancel).toHaveBeenCalledTimes(1); expect(calls).toBeLessThanOrEqual(2);
  });

  it.each(['text/html', 'redirect', 'invalid-utf8'])('rejects an ambiguous transport: %s', async mode => {
    const response = mode === 'invalid-utf8' ? new Response(new Uint8Array([0xc3, 0x28]), { headers: { 'Content-Type': 'application/json' } }) : json(page());
    if (mode === 'text/html') response.headers.set('Content-Type', 'text/html');
    if (mode === 'redirect') Object.defineProperty(response, 'redirected', { value: true });
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response);
    expect(await readOwnedOrderHistory(null, new AbortController().signal)).toEqual({ kind: 'unavailable' });
  });
});

describe('private history request lifetime', () => {
  it('disposes deadlines immediately on pagehide and unmount even when fetch ignores abort', () => {
    vi.useFakeTimers();
    try {
      vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}));
      const view = render(element()); fireEvent.click(screen.getByRole('button', { name: 'Browse test orders' }));
      expect(vi.getTimerCount()).toBe(1);
      act(() => window.dispatchEvent(new Event('pagehide'))); expect(vi.getTimerCount()).toBe(0);
      fireEvent.click(screen.getByRole('button', { name: 'Browse test orders' })); expect(vi.getTimerCount()).toBe(1);
      view.unmount(); expect(vi.getTimerCount()).toBe(0);
    } finally { vi.useRealTimers(); }
  });
});
