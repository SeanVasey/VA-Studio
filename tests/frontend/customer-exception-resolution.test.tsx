import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OrderExceptionResolution } from '../../resources/js/components/OrderExceptionResolution';
import { TestCheckout, type PaymentProgress } from '../../resources/js/components/TestCheckout';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { readExceptionResolution, validExceptionResolution, RESOLUTION_MAX_BYTES, RESOLUTION_UNAVAILABLE } from '../../resources/js/lib/customer-exception-resolution';

vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/lib/customer-session', async original => ({ ...await original<typeof import('../../resources/js/lib/customer-session')>(), navigateCustomerSession: vi.fn() }));
const id = (n = 1) => `750000ab-0000-4000-8000-${String(n).padStart(12, '0')}`;
const saved = () => ({ kind: 'full_refund_verified_resources_released', observedAt: '2026-10-01T12:00:00Z', releasedAt: '2026-10-01T12:00:01Z' });
const resolution = (record: unknown = saved(), n = 1) => ({ exceptionResolutionSchema: 1, orderId: id(n), testOnly: true, record });
const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } });
const body = (record: unknown = saved(), n = 1) => json({ resolution: resolution(record, n) });
const signal = () => new AbortController().signal;
const view = () => screen.getByRole('button', { name: 'View recorded test-order resolution' });
const refresh = () => screen.getByRole('button', { name: 'Refresh recorded test-order resolution' });
const hide = () => screen.getByRole('button', { name: 'Hide recorded test-order resolution' });
const panel = () => render(<OrderExceptionResolution orderId={id()} />);
const proofText = 'A full test refund was verified and this order’s reservations were released.';
const progress: PaymentProgress = { paymentStatus: 'verified', finalizationStatus: 'paid_exception', contractStatus: 'blocked', fulfillmentStatus: 'blocked' };
const summary = { id: id(), createdAt: '2026-10-01T11:00:00.000000Z', testOnly: true, payable: false, currency: 'USD', totalMinor: 4399,
  status: 'paid_exception', ...progress };
afterEach(() => vi.useRealTimers());

describe('retained exception-resolution reader', () => {
  it('reads only one exact order with no query, body, mutation, navigation or cache', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(body()); const abort = signal();
    expect(await readExceptionResolution(id(), abort)).toEqual({ kind: 'loaded', resolution: resolution() });
    expect(fetcher).toHaveBeenCalledExactlyOnceWith(`/orders/${id()}/exception-resolution`, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort, headers: { Accept: 'application/json' },
    });
  });
  it('accepts absent history and old canonical history without browser-clock expiry', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(body(null)).mockResolvedValueOnce(body({ ...saved(), observedAt: '2000-02-29T00:00:00Z', releasedAt: '2000-02-29T00:00:00Z' }));
    expect(await readExceptionResolution(id(), signal())).toEqual({ kind: 'loaded', resolution: resolution(null) });
    expect((await readExceptionResolution(id(), signal())).kind).toBe('loaded'); expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it.each(['', '../orders', id() + '?owner=PRIVATE', id().toUpperCase()])('rejects noncanonical identity %s before HTTP', async value => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    expect((await readExceptionResolution(value, signal())).kind).toBe('unavailable'); expect(fetcher).not.toHaveBeenCalled();
  });
  it('rejects a newline-suffixed locator before HTTP and schema validation', async () => {
    const malformed = id() + '\n', fetcher = vi.spyOn(globalThis, 'fetch');
    expect(validExceptionResolution({ ...resolution(), orderId: malformed }, malformed)).toBe(false);
    expect((await readExceptionResolution(malformed, signal())).kind).toBe('unavailable'); expect(fetcher).not.toHaveBeenCalled();
  });
  it.each([401, 403, 419, 404, 422, 429, 500, 503])('keeps %s response bytes private with bounded fixed guidance', async status => {
    const response = json({ message: 'PRIVATE', reason: 'PRIVATE_REASON' }, status); const reader = vi.spyOn(response.body!, 'getReader');
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response);
    const result = await readExceptionResolution(id(), signal());
    expect(result.kind).toBe([401, 403, 419].includes(status) ? 'reload' : 'unavailable');
    expect(JSON.stringify(result)).not.toMatch(/PRIVATE/); expect(reader).not.toHaveBeenCalled();
  });
  it.each([
    ['wrong order', resolution(saved(), 2)], ['wrong schema', { ...resolution(), exceptionResolutionSchema: 2 }],
    ['not test data', { ...resolution(), testOnly: false }], ['private projection', { ...resolution(), ownerKey: 'PRIVATE' }],
    ['missing record', { exceptionResolutionSchema: 1, orderId: id(), testOnly: true }], ['array record', resolution([])],
    ['unknown outcome', resolution({ ...saved(), kind: 'refund_sent' })], ['extra record', resolution({ ...saved(), refundId: 'PRIVATE' })],
    ['invalid calendar day', resolution({ ...saved(), observedAt: '2026-02-30T00:00:00Z' })],
    ['invalid time', resolution({ ...saved(), observedAt: '2026-10-01T24:00:00Z' })],
    ['offset time', resolution({ ...saved(), observedAt: '2026-10-01T12:00:00+00:00' })],
    ['fractional time', resolution({ ...saved(), observedAt: '2026-10-01T12:00:00.000Z' })],
    ['missing release', resolution({ kind: saved().kind, observedAt: saved().observedAt })],
    ['release before observation', resolution({ ...saved(), releasedAt: '2026-10-01T11:59:59Z' })],
  ])('refuses %s without coercion or private fallback fields', async (_case, value) => {
    expect(validExceptionResolution(value, id())).toBe(false);
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ resolution: value }));
    expect(await readExceptionResolution(id(), signal())).toEqual({ kind: 'unavailable', message: RESOLUTION_UNAVAILABLE });
  });
  it('rejects envelope additions, redirects, HTML, malformed JSON and invalid UTF-8', async () => {
    const redirected = body(); Object.defineProperty(redirected, 'redirected', { value: true });
    const responses = [json({ resolution: resolution(), provider: 'PRIVATE' }), redirected,
      new Response(JSON.stringify({ resolution: resolution() }), { headers: { 'Content-Type': 'text/html' } }),
      new Response('{bad', { headers: { 'Content-Type': 'application/json' } }),
      new Response(new Uint8Array([0xc0, 0xaf]), { headers: { 'Content-Type': 'application/json' } })];
    const fetcher = vi.spyOn(globalThis, 'fetch');
    for (const response of responses) { fetcher.mockResolvedValueOnce(response); expect((await readExceptionResolution(id(), signal())).kind).toBe('unavailable'); }
  });
  it('counts streamed bytes without Content-Length and cancels an oversized response before more reads', async () => {
    const cancel = vi.fn(); let pulls = 0;
    const stream = new ReadableStream<Uint8Array>({ pull(controller) { pulls++; controller.enqueue(new Uint8Array(RESOLUTION_MAX_BYTES + 1)); }, cancel }, { highWaterMark: 0 });
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(stream, { headers: { 'Content-Type': 'application/json' } }));
    expect((await readExceptionResolution(id(), signal())).kind).toBe('unavailable'); expect(cancel).toHaveBeenCalledTimes(1); expect(pulls).toBe(1);
  });
  it('cancels an unfinished body read on abort without exposing a partial record', async () => {
    const cancel = vi.fn(), abort = new AbortController();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(new ReadableStream({ start(controller) { controller.enqueue(new TextEncoder().encode('{"resolution":')); }, cancel }), { headers: { 'Content-Type': 'application/json' } }));
    const reading = readExceptionResolution(id(), abort.signal); await Promise.resolve(); abort.abort();
    expect((await reading).kind).toBe('unavailable'); expect(cancel).toHaveBeenCalledTimes(1);
  });
  it('refuses an already aborted request and discards ignored-abort transport results', async () => {
    const first = new AbortController(); first.abort(); const fetcher = vi.spyOn(globalThis, 'fetch');
    expect((await readExceptionResolution(id(), first.signal)).kind).toBe('unavailable'); expect(fetcher).not.toHaveBeenCalled();
    const next = new AbortController(); fetcher.mockImplementation(async () => { next.abort(); return body(); });
    expect((await readExceptionResolution(id(), next.signal)).kind).toBe('unavailable');
  });
});

describe('recorded test-order resolution panel', () => {
  it('loads only by explicit keyboard action, explains retained dates and hides private memory without storage', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(body());
    const read = vi.spyOn(Storage.prototype, 'getItem'), write = vi.spyOn(Storage.prototype, 'setItem'); const location = window.location.href;
    panel(); expect(fetcher).not.toHaveBeenCalled(); view().focus(); await userEvent.setup().keyboard('{Enter}');
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Recorded test-order resolution' })).toHaveFocus());
    expect(screen.getByText(proofText)).toBeVisible(); expect(screen.getByText(/does not confirm refund arrival/)).toBeVisible();
    expect(screen.getByText(/not when a refund was sent/)).toBeVisible(); expect(screen.getByText(/Contracts and downloads remain blocked/)).toBeVisible();
    expect([...document.querySelectorAll('time')].map(time => time.dateTime)).toEqual([saved().observedAt, saved().releasedAt]);
    expect(read).not.toHaveBeenCalled(); expect(write).not.toHaveBeenCalled(); expect(window.location.href).toBe(location);
    await userEvent.setup().click(hide()); expect(screen.queryByText(proofText)).not.toBeInTheDocument(); expect(view()).toHaveFocus();
  });
  it('treats a missing retained record as absence rather than refund or workflow status', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(body(null)); panel(); fireEvent.click(view());
    expect(await screen.findByText(/No retained full-refund resolution was found/)).toHaveTextContent('This does not establish whether a refund was made.');
    expect(document.querySelector('time')).toBeNull(); expect(screen.queryByText(/needs review|refund pending|not refunded/i)).not.toBeInTheDocument();
  });
  it.each(['hide', 'pagehide', 'unmount', 'replace'])('aborts, disposes the deadline and rejects late response after %s', async action => {
    vi.useFakeTimers(); let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const rendered = panel(); fireEvent.click(view()); expect(vi.getTimerCount()).toBe(1);
    const requestSignal = fetcher.mock.calls[0][1]!.signal!;
    if (action === 'hide') fireEvent.click(hide());
    else if (action === 'pagehide') act(() => window.dispatchEvent(new Event('pagehide')));
    else if (action === 'unmount') rendered.unmount();
    else rendered.rerender(<OrderExceptionResolution orderId={id(2)} />);
    await act(async () => vi.advanceTimersByTimeAsync(0)); // jsdom's focus selectionchange notification, not a request deadline.
    expect(requestSignal.aborted).toBe(true); expect(vi.getTimerCount()).toBe(0);
    await act(async () => finish(body())); expect(screen.queryByText(proofText)).not.toBeInTheDocument();
    if (action !== 'unmount') expect(view()).toBeEnabled();
  });
  it('clears a shown record on page departure and fetches anew when reopened', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(body()).mockResolvedValueOnce(body(null));
    panel(); fireEvent.click(view()); await screen.findByText(proofText);
    act(() => window.dispatchEvent(new Event('pagehide'))); expect(screen.queryByText(proofText)).not.toBeInTheDocument();
    fireEvent.click(view()); expect(await screen.findByText(/No retained full-refund resolution was found/)).toBeVisible(); expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it('clears retained history before a failed refresh and requires fresh access after denial', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(body()).mockResolvedValueOnce(json({ private: 'PRIVATE' }, 403));
    panel(); fireEvent.click(view()); await screen.findByText(proofText); fireEvent.click(refresh());
    expect(screen.queryByText(proofText)).not.toBeInTheDocument(); await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
    expect(refresh()).toBeDisabled(); expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
    expect(screen.queryByText('PRIVATE')).not.toBeInTheDocument(); fireEvent.click(hide()); expect(view()).toHaveFocus();
  });
  it('expires even ignored aborts and prevents an old finally from releasing a newer request or deadline', async () => {
    vi.useFakeTimers(); const completions: Array<(response: Response) => void> = [];
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => completions.push(resolve)));
    panel(); fireEvent.click(view()); await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(screen.getByRole('alert')).toHaveTextContent(RESOLUTION_UNAVAILABLE); expect(refresh()).toBeEnabled();
    fireEvent.click(refresh()); const latestSignal = fetcher.mock.calls[1][1]!.signal!;
    await act(async () => completions[0](body()));
    expect(screen.getByRole('button', { name: 'Loading recorded test-order resolution…' })).toBeDisabled(); expect(latestSignal.aborted).toBe(false);
    expect(screen.queryByText(proofText)).not.toBeInTheDocument();
    await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(latestSignal.aborted).toBe(true); expect(screen.getByRole('alert')).toHaveTextContent(RESOLUTION_UNAVAILABLE); expect(fetcher).toHaveBeenCalledTimes(2);
    await act(async () => completions[1](body())); expect(screen.queryByText(proofText)).not.toBeInTheDocument();
  });
  it.each(['history', 'reference'])('shares a lazy read through library %s and clears it immediately on sign-out', async path => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => {
      const url = String(input);
      if (url === '/orders/history') return json({ history: { orderHistorySchema: 2, testOnly: true, orders: [summary], previews: [{ orderId: id(), itemCount: 1, firstItem: { title: 'Original track', licenseName: 'Original license', licenseVersion: 1 } }], limit: 20, nextCursor: null } });
      if (url.endsWith('/status')) return json({ order: { ...summary, orderSchema: 1, quoteId: id(91), pricingId: id(92), reviewHash: 'a'.repeat(64) } });
      if (url.endsWith('/exception-resolution')) return body();
      return json({}, 503);
    });
    render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic Customer' }} />); const user = userEvent.setup();
    if (path === 'history') { await user.click(screen.getByRole('button', { name: 'Browse account orders' })); await user.click(await screen.findByRole('button', { name: `View test order status ${id()}` })); }
    else { await user.type(screen.getByRole('textbox', { name: 'Order reference' }), id()); await user.keyboard('{Enter}'); }
    await screen.findByRole('button', { name: 'View recorded test-order resolution' });
    expect(fetcher.mock.calls.filter(([url]) => String(url).endsWith('/exception-resolution'))).toHaveLength(0);
    expect(screen.queryByText(/needs review before fulfillment can continue/i)).not.toBeInTheDocument();
    await user.click(view()); expect(await screen.findByText(proofText)).toBeVisible();
    expect(screen.queryByRole('region', { name: 'Test order downloads' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(screen.queryByRole('region', { name: 'Recorded test-order resolution' })).not.toBeInTheDocument();
    expect(fetcher.mock.calls.filter(([url]) => String(url).endsWith('/exception-resolution'))).toHaveLength(1);
    expect(fetcher.mock.calls.filter(([url, init]) => String(url).startsWith('/orders/') && init?.method === 'POST')).toHaveLength(0);
  });
  it.each([
    ['unverified', { paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started' }],
    ['awaiting finalization', { paymentStatus: 'verified', finalizationStatus: 'awaiting_finalization', contractStatus: 'not_started', fulfillmentStatus: 'not_started' }],
    ['paid', { paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_contracts' }],
  ])('does not offer the exception panel or read for %s orders', async (_label, retained) => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({}, 503));
    render(<TestCheckout orderId={id()} retainedProgress={retained as PaymentProgress} />);
    await screen.findByRole('alert'); expect(screen.queryByRole('region', { name: 'Recorded test-order resolution' })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(1); expect(fetcher.mock.calls[0][0]).toBe(`/orders/${id()}/checkout`);
  });
});
