import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OrderItems } from '../../resources/js/components/OrderItems';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { readOrderItems, validOrderItems } from '../../resources/js/lib/order-items';

vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/lib/customer-session', async original => ({ ...await original<typeof import('../../resources/js/lib/customer-session')>(), navigateCustomerSession: vi.fn() }));
const id = (n = 1) => `750000ab-0000-4000-8000-${String(n).padStart(12, '0')}`;
const line = () => ({ position: 0, title: 'Original recording', licenseName: 'Original license', licenseVersion: 3,
  quantity: 1, baseMinor: 4999, discountMinor: 1000, taxBasisMinor: 3999, taxMinor: 400, totalMinor: 4399 });
const items = (n = 1) => ({ orderItemsSchema: 1, orderId: id(n), testOnly: true, currency: 'USD', subtotalMinor: 4999,
  discountMinor: 1000, taxBasisMinor: 3999, taxMinor: 400, totalMinor: 4399, lines: [line()] });
const summary = { id: id(), createdAt: '2026-10-01T12:00:00.000000Z', testOnly: true, payable: false, currency: 'USD', totalMinor: 4399,
  status: 'paid_exception', paymentStatus: 'verified', finalizationStatus: 'paid_exception', contractStatus: 'blocked', fulfillmentStatus: 'blocked' };
const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } });
const panel = () => render(<OrderItems orderId={id()} expectedTotalMinor={4399} />);
const view = () => screen.getByRole('button', { name: 'View original test-order items' });
const hide = () => screen.getByRole('button', { name: 'Hide original test-order items' });
const signal = () => new AbortController().signal;
afterEach(() => vi.useRealTimers());

describe('retained order items transport', () => {
  it('reads one exact owner-protected order with private cache and no navigation or mutation', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ items: items() }));
    const abort = signal();
    expect(await readOrderItems(id(), 4399, abort)).toEqual({ kind: 'loaded', items: items() });
    expect(fetcher).toHaveBeenCalledExactlyOnceWith(`/orders/${id()}/items`, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort, headers: { Accept: 'application/json' },
    });
  });
  it.each(['', '../orders', id() + '?owner=PRIVATE', id().toUpperCase()])('refuses noncanonical identity %s before HTTP', async value => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    expect((await readOrderItems(value, 4399, signal())).kind).toBe('unavailable'); expect(fetcher).not.toHaveBeenCalled();
  });
  it.each([401, 403, 419])('requires fresh access after %s without reading private failure bytes', async status => {
    const response = json({ private: 'PRIVATE' }, status); const reader = vi.spyOn(response.body!, 'getReader');
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response);
    const result = await readOrderItems(id(), 4399, signal());
    expect(result.kind).toBe('reload'); expect(JSON.stringify(result)).not.toContain('PRIVATE'); expect(reader).not.toHaveBeenCalled();
  });
  it.each([404, 409, 429, 500, 503])('keeps denial/unavailability %s generic without reflecting body details', async status => {
    const response = json({ private: 'PRIVATE' }, status); const reader = vi.spyOn(response.body!, 'getReader');
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response);
    expect(await readOrderItems(id(), 4399, signal())).toEqual({ kind: 'unavailable', message: 'Original test-order items are unavailable. Reload the order status or try again.' });
    expect(reader).not.toHaveBeenCalled();
  });
  it.each([
    ['other order', { ...items(), orderId: id(2) }], ['schema', { ...items(), orderItemsSchema: 2 }],
    ['live data', { ...items(), testOnly: false }], ['currency', { ...items(), currency: 'EUR' }],
    ['total mismatch', { ...items(), totalMinor: 4400 }], ['negative', { ...items(), taxMinor: -1 }],
    ['unsafe amount', { ...items(), subtotalMinor: Number.MAX_SAFE_INTEGER + 1 }],
    ['private fields', { ...items(), buyer: 'PRIVATE' }], ['empty lines', { ...items(), lines: [] }],
    ['too many lines', { ...items(), lines: Array.from({ length: 11 }, (_, position) => ({ ...line(), position })) }],
    ['reordered position', { ...items(), lines: [{ ...line(), position: 1 }] }],
    ['unknown line field', { ...items(), lines: [{ ...line(), privatePath: 'PRIVATE' }] }],
    ['missing title', { ...items(), lines: [{ ...line(), title: '' }] }], ['long title', { ...items(), lines: [{ ...line(), title: 'x'.repeat(513) }] }],
    ['invalid license', { ...items(), lines: [{ ...line(), licenseVersion: 0 }] }],
    ['fractional amount', { ...items(), lines: [{ ...line(), totalMinor: 1.5 }] }],
    ['invalid quantity', { ...items(), lines: [{ ...line(), quantity: 2 }] }],
  ])('rejects unverified %s without rendering', async (_case, value) => {
    expect(validOrderItems(value, id(), 4399)).toBe(false);
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ items: value }));
    expect((await readOrderItems(id(), 4399, signal())).kind).toBe('unavailable');
  });
  it('rejects extra envelope fields, redirected or non-JSON responses and oversized bodies', async () => {
    const redirected = json({ items: items() }); Object.defineProperty(redirected, 'redirected', { value: true });
    const responses = [json({ items: items(), owner: 'PRIVATE' }), redirected,
      new Response(JSON.stringify({ items: items() }), { headers: { 'Content-Type': 'text/html' } }),
      json({ items: items(), padding: 'x'.repeat(128 * 1024) }), new Response('{invalid', { headers: { 'Content-Type': 'application/json' } })];
    const fetcher = vi.spyOn(globalThis, 'fetch');
    for (const response of responses) { fetcher.mockResolvedValueOnce(response); expect((await readOrderItems(id(), 4399, signal())).kind).toBe('unavailable'); }
  });
  it('discards aborted results even when a transport ignores the signal', async () => {
    const abort = new AbortController();
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => { abort.abort(); return json({ items: items() }); });
    expect((await readOrderItems(id(), 4399, abort.signal)).kind).toBe('unavailable');
  });
  it('accepts ten maximum current-schema prices and full Unicode labels without truncating or coercing retained amounts', async () => {
    // Ten retained max-price lines with the supported 100% fixed test tax: no 32-bit narrowing.
    const maximum = { ...items(), subtotalMinor: 21_474_836_470, discountMinor: 0, taxBasisMinor: 21_474_836_470,
      taxMinor: 21_474_836_470, totalMinor: 42_949_672_940,
      lines: Array.from({ length: 10 }, (_, position) => ({ ...line(), position, title: '🎵'.repeat(255), licenseName: '🎼'.repeat(255),
        baseMinor: 2_147_483_647, discountMinor: 0, taxBasisMinor: 2_147_483_647, taxMinor: 2_147_483_647, totalMinor: 4_294_967_294 })) };
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ items: maximum }));
    expect(await readOrderItems(id(), maximum.totalMinor, signal())).toEqual({ kind: 'loaded', items: maximum });
  });
});

describe('original test-order items panel', () => {
  it('loads only on explicit keyboard action, displays frozen prices and clears on hide without storage', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ items: items() }));
    const storageRead = vi.spyOn(Storage.prototype, 'getItem'), storageWrite = vi.spyOn(Storage.prototype, 'setItem'); const url = window.location.href;
    panel(); expect(fetcher).not.toHaveBeenCalled();
    view().focus(); await userEvent.setup().keyboard('{Enter}');
    expect(await screen.findByRole('heading', { name: 'Original test-order items' })).toHaveFocus();
    expect(screen.getByRole('heading', { name: 'Original recording' })).toBeVisible();
    expect(screen.getByText('Original license · Version 3')).toBeVisible();
    expect(screen.getByText('$43.99 USD')).toBeVisible();
    expect(screen.getByText(/do not confirm payment, grant rights/)).toBeVisible();
    expect(storageRead).not.toHaveBeenCalled(); expect(storageWrite).not.toHaveBeenCalled(); expect(window.location.href).toBe(url);
    await userEvent.setup().click(hide()); expect(screen.queryByText('Original recording')).not.toBeInTheDocument(); expect(view()).toHaveFocus();
  });
  it('escapes retained text rather than treating it as markup or a link', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ items: { ...items(), lines: [{ ...line(), title: '<img src=x onerror=alert(1)>', licenseName: '<script>PRIVATE</script>' }] } }));
    panel(); fireEvent.click(view());
    expect(await screen.findByText('<img src=x onerror=alert(1)>')).toBeVisible(); expect(document.querySelector('.order-items img, .order-items script')).toBeNull();
  });
  it.each(['hide', 'pagehide', 'unmount', 'replace'])('cancels pending reads and rejects late bytes after %s', async action => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const rendered = panel(); fireEvent.click(view());
    const requestSignal = fetcher.mock.calls[0][1]!.signal!;
    if (action === 'hide') fireEvent.click(hide());
    else if (action === 'pagehide') act(() => window.dispatchEvent(new Event('pagehide')));
    else if (action === 'unmount') rendered.unmount();
    else rendered.rerender(<OrderItems orderId={id(2)} expectedTotalMinor={4399} />);
    expect(requestSignal.aborted).toBe(true);
    await act(async () => finish(json({ items: items() })));
    expect(screen.queryByText('Original recording')).not.toBeInTheDocument();
    if (action !== 'unmount') expect(view()).toBeEnabled();
    if (action === 'hide') expect(view()).toHaveFocus();
  });
  it('clears previously loaded items before a failed refresh and focuses a generic error', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({ items: items() })).mockResolvedValueOnce(json({ message: 'PRIVATE' }, 409));
    panel(); fireEvent.click(view()); await screen.findByText('Original recording');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh original test-order items' }));
    expect(screen.queryByText('Original recording')).not.toBeInTheDocument();
    expect(await screen.findByRole('alert')).toHaveFocus(); expect(screen.queryByText('PRIVATE')).not.toBeInTheDocument();
  });
  it('bounds pending reads to twenty seconds with no retry and no retained result', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation((_url, init) => new Promise((_resolve, reject) => init!.signal!.addEventListener('abort', () => reject(new Error('Aborted')))));
    panel(); fireEvent.click(view());
    await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(fetcher).toHaveBeenCalledTimes(1); expect(screen.getByRole('alert')).toHaveFocus(); expect(screen.queryByText('Original recording')).not.toBeInTheDocument();
  });
  it('offers fresh sign-in after lost access and clears it on hide', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ message: 'PRIVATE' }, 403)); panel(); fireEvent.click(view());
    await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus()); expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
    expect(screen.getByRole('button', { name: 'Refresh original test-order items' })).toBeDisabled();
    fireEvent.click(hide()); expect(screen.queryByRole('link')).not.toBeInTheDocument(); expect(view()).toHaveFocus();
  });
  it.each(['history', 'reference'])('shares the lazy panel through the %s path and discards it immediately on sign-out', async path => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => {
      const url = String(input);
      if (url === '/orders/history') return json({ history: { orderHistorySchema: 2, testOnly: true, orders: [summary], previews: [{ orderId: summary.id, itemCount: 1, firstItem: { title: 'Original track', licenseName: 'Original license', licenseVersion: 1 } }], limit: 20, nextCursor: null } });
      if (url.endsWith('/status')) return json({ order: { ...summary, orderSchema: 1, quoteId: id(91), pricingId: id(92), reviewHash: 'a'.repeat(64) } });
      if (url.endsWith('/items')) return json({ items: items() });
      return json({}, 503);
    });
    render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic Customer' }} />);
    const user = userEvent.setup(); expect(fetcher).not.toHaveBeenCalled();
    if (path === 'history') {
      await user.click(screen.getByRole('button', { name: 'Browse account orders' }));
      await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` }));
    } else {
      await user.type(screen.getByRole('textbox', { name: 'Order reference' }), id()); await user.keyboard('{Enter}');
    }
    await screen.findByRole('button', { name: 'View original test-order items' });
    expect(fetcher.mock.calls.filter(([url]) => String(url).endsWith('/items'))).toHaveLength(0);
    await user.click(view()); expect(await screen.findByText('Original recording')).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(screen.queryByText('Original recording')).not.toBeInTheDocument(); expect(screen.queryByRole('region', { name: 'Original test-order items' })).not.toBeInTheDocument();
    expect(fetcher.mock.calls.filter(([url]) => String(url).endsWith('/items'))).toHaveLength(1);
  });
});
