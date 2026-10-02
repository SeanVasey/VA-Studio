import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { OwnedTestOrderHistory, validOrderHistory } from '../../resources/js/components/OwnedTestOrderHistory';
import { PreparedOrderRecovery } from '../../resources/js/components/OrderPreparation';

const id = (number = 1) => `730000ab-0000-4000-8000-${String(number).padStart(12, '0')}`;
const order = (number = 1) => ({ id: id(number), createdAt: '2026-10-01T12:00:00.000000Z',
  testOnly: true as const, payable: false as const, currency: 'USD' as const, totalMinor: 4999, status: 'prepared' as const,
  paymentStatus: 'not_started' as const, finalizationStatus: 'not_started' as const,
  contractStatus: 'not_started' as const, fulfillmentStatus: 'not_started' as const });
const page = (orders = [order()], nextCursor: string | null = null) => ({ orderHistorySchema: 1, testOnly: true, orders, limit: 20, nextCursor });
const json = (history: unknown, status = 200) => new Response(JSON.stringify({ history }), { status });
const element = () => <OwnedTestOrderHistory renderOrder={entry => <p>Session order {entry.id}</p>} />;

describe('current session owned test order history', () => {
  it('requests no history until selected and needs no persisted tab locators or identity', async () => {
    const user = userEvent.setup(); const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(page()));
    const storageRead = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Unavailable'); });
    const storageWrite = vi.spyOn(Storage.prototype, 'setItem');
    render(element()); expect(fetcher).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
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
    await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
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
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
    await user.click(await screen.findByRole('button', { name: 'Older session orders' }));
    expect(await screen.findByText(`Order ${id(21)}`)).toBeInTheDocument();
    expect(screen.queryByText(`Session order ${id()}`)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Older session orders' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh session orders' }));
    expect(await screen.findByText(`Order ${id()}`)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Session orders' })).toHaveFocus();
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual(['/orders/history', `/orders/history?before=${id(20)}`, '/orders/history']);
  });

  it('shows empty and private retry states without reflecting failed responses or performing writes', async () => {
    const user = userEvent.setup(); const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(page([])))
      .mockResolvedValueOnce(new Response('PRIVATE_ERROR_BODY', { status: 503 })).mockResolvedValueOnce(json(page()));
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
    expect(await screen.findByRole('status')).toHaveTextContent('No test orders belong to this session.');
    await user.click(screen.getByRole('button', { name: 'Refresh session orders' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('history could not be loaded');
    expect(screen.queryByText(/PRIVATE_ERROR_BODY/)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh session orders' }));
    expect(await screen.findByText(`Order ${id()}`)).toBeInTheDocument();
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
  });

  it('ignores responses after unmount and does not carry history into a new instance', async () => {
    const user = userEvent.setup(); let finish!: (response: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json(page([])));
    const first = render(element()); await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
    first.unmount(); render(element()); await act(async () => finish(json(page())));
    expect(screen.queryByText(`Session order ${id()}`)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
    expect(await screen.findByRole('status')).toHaveTextContent('No test orders belong to this session.');
  });

  it('rejects an oversized history response before exposing any order', async () => {
    const user = userEvent.setup(); vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(' '.repeat(64 * 1024 + 1)));
    render(element()); await user.click(screen.getByRole('button', { name: 'Browse session orders' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('history could not be loaded');
    expect(screen.queryByText(`Session order ${id()}`)).not.toBeInTheDocument();
  });

  it.each([
    ['live mode', { testOnly: false }], ['wrong version', { orderHistorySchema: 2 }], ['unknown field', { ownerKey: 'PRIVATE' }],
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
