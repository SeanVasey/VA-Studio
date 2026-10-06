import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ContactInquiryForm } from '../../resources/js/components/ContactInquiryForm';
import { InquiryConversation } from '../../resources/js/components/InquiryConversation';
import { OrderInquiry } from '../../resources/js/components/OrderInquiry';
import { InquiryOrderContext } from '../../resources/js/components/InquiryOrderContext';
import { OrderStatus, type OrderSummary } from '../../resources/js/components/OrderPreparation';
import { ORDER_INQUIRY_MAX_BYTES, readOrderInquiryContext, readOrderInquirySetup } from '../../resources/js/lib/order-inquiry';

const orderId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const otherId = '11111111-2222-4333-8444-555555555555';
const receipt = '66666666-7777-4888-8999-aaaaaaaaaaaa';
const noticeToken = 'a'.repeat(64);
const setup = { orderInquirySchema: 1, orderId, testOnly: true, privacyNotice: 'Synthetic private notice. No email is sent.', noticeToken };
const context = { orderInquiryContextSchema: 1, order: { id: orderId, testOnly: true } };
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const draft = { name: 'Synthetic visitor', email: 'visitor@example.test', subject: 'Question about my test order', message: 'Exact private text.\nDo not duplicate.', website: '' };
function fill() { for (const [field, value] of Object.entries(draft)) if (field !== 'website') fireEvent.change(screen.getByLabelText(new RegExp(`^${field}`, 'i')), { target: { value } }); }
function send() { fireEvent.click(screen.getByRole('button', { name: /^(Send inquiry|Retry same inquiry)$/ })); }
const readSetup = () => readOrderInquirySetup(orderId, new AbortController().signal);
const readContext = () => readOrderInquiryContext(receipt, new AbortController().signal);
beforeEach(() => { vi.spyOn(crypto, 'randomUUID').mockReturnValue(otherId); document.head.innerHTML = '<meta name="csrf-token" content="synthetic-csrf">'; });
afterEach(() => { vi.useRealTimers(); document.head.innerHTML = ''; });

describe('strict order inquiry boundary', () => {
  it('uses private GETs without query, body, credentials in a URL or browser storage', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ orderInquiry: setup })).mockResolvedValueOnce(response({ context }));
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    expect(await readSetup()).toEqual({ kind: 'loaded', value: setup }); expect(await readContext()).toEqual({ kind: 'loaded', value: context });
    expect(fetcher.mock.calls.map(([url]) => url)).toEqual([`/contact/inquiries/for-order/${orderId}`, `/contact/inquiries/${receipt}/order-context`]);
    for (const [, options] of fetcher.mock.calls) { expect(options).toMatchObject({ method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' } }); expect(options?.body).toBeUndefined(); }
    expect(storage).not.toHaveBeenCalled();
  });
  it.each(['', `${orderId}\n`, '../foreign', orderId.toUpperCase()])('refuses a malformed route locator without a request: %s', async locator => {
    const fetcher = vi.spyOn(globalThis, 'fetch'); const signal = new AbortController().signal;
    expect(await readOrderInquirySetup(locator, signal)).toEqual({ kind: 'unavailable' }); expect(await readOrderInquiryContext(locator, signal)).toEqual({ kind: 'unavailable' }); expect(fetcher).not.toHaveBeenCalled();
  });
  it.each([
    { orderInquiry: { ...setup, orderId: otherId } }, { orderInquiry: { ...setup, orderInquirySchema: 2 } },
    { orderInquiry: { ...setup, testOnly: false } }, { orderInquiry: { ...setup, privacyNotice: ' ' } },
    { orderInquiry: { ...setup, noticeToken: `${noticeToken}\n` } }, { orderInquiry: { ...setup, privateEmail: 'private@example.test' } },
    { orderInquiry: setup, extra: 'private' }, { orderInquiry: { ...setup, privacyNotice: 'x'.repeat(3001) } },
    { orderInquiry: { ...setup, privacyNotice: '\ud800' } },
  ])('rejects unsupported or mismatched setup data without rendering server detail %#', async data => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(data)); expect(await readSetup()).toEqual({ kind: 'unavailable' });
  });
  it.each([
    { context: { ...context, orderInquiryContextSchema: 2 } }, { context: { ...context, extra: true } },
    { context: { ...context, order: { id: orderId, testOnly: true, status: 'paid' } } },
    { context: { ...context, order: { id: `${orderId}\n`, testOnly: true } } },
    { context: { ...context, order: { id: orderId, testOnly: false } } }, { context, extra: true },
  ])('rejects broader or malformed retained order context %#', async data => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(data)); expect(await readContext()).toEqual({ kind: 'unavailable' });
  });
  it.each([403, 404, 419, 429, 503])('keeps private HTTP %s bodies out of the UI', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response({ orderInquiry: setup, privateError: 'private evidence' }, status));
    render(<OrderInquiry orderId={orderId} />); fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus()); expect(document.body.textContent).not.toContain('private evidence'); expect(screen.queryByLabelText(/^Name/)).not.toBeInTheDocument();
  });
  it('bounds response bytes and rejects wrong media type, redirects and invalid UTF-8', async () => {
    const redirected = response({ orderInquiry: setup }); Object.defineProperty(redirected, 'redirected', { value: true });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response(' '.repeat(ORDER_INQUIRY_MAX_BYTES + 1), { headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ orderInquiry: setup }), { headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(redirected).mockResolvedValueOnce(new Response(new Uint8Array([0xc3, 0x28]), { headers: { 'Content-Type': 'application/json' } }));
    for (let i = 0; i < 4; i++) expect(await readSetup()).toEqual({ kind: 'unavailable' }); expect(fetcher).toHaveBeenCalledTimes(4);
  });
  it('accepts the maximum approved notice under Laravel escaped Unicode encoding, but rejects one extra code point', async () => {
    const maximum = { ...setup, privacyNotice: '🎧'.repeat(3000) };
    const encoded = JSON.stringify({ orderInquiry: maximum }).replaceAll('🎧', '\\ud83c\\udfa7');
    expect(new TextEncoder().encode(encoded).byteLength).toBeGreaterThan(32768);
    const overPolicy = JSON.stringify({ orderInquiry: { ...maximum, privacyNotice: maximum.privacyNotice + '🎧' } }).replaceAll('🎧', '\\ud83c\\udfa7');
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response(encoded, { headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(overPolicy, { headers: { 'Content-Type': 'application/json' } }));
    expect(await readSetup()).toEqual({ kind: 'loaded', value: maximum }); expect(await readSetup()).toEqual({ kind: 'unavailable' });
  });
});

describe('owned order inquiry flow', () => {
  it('offers an explicit lazy action on a retained order, then focuses verified context with labelled fields', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async () => response({ orderInquiry: setup }));
    const order: OrderSummary = { id: orderId, createdAt: '2026-10-06T00:00:00Z', status: 'prepared', testOnly: true, payable: false, currency: 'USD', totalMinor: 1000, paymentStatus: 'not_started', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started' };
    render(<OrderStatus order={order} />); expect(fetcher.mock.calls.filter(([path]) => String(path).startsWith('/contact/inquiries/'))).toEqual([]);
    await userEvent.click(screen.getByRole('button', { name: 'Ask about this test order' }));
    expect(await screen.findByLabelText(/^Name/)).toBeRequired(); expect(screen.getByText(/Linked test order/)).toHaveTextContent(orderId);
    await waitFor(() => expect(screen.getByText(/Linked test order/).parentElement).toHaveFocus()); expect(fetcher.mock.calls.filter(([path]) => String(path).startsWith('/contact/inquiries/'))).toHaveLength(1);
    expect(screen.queryByRole('heading', { name: 'Read an existing inquiry' })).not.toBeInTheDocument();
  });
  it('keeps the exact original route, seven-field body, key and notice through lost acknowledgement and a 404 retry', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ orderInquiry: setup })).mockRejectedValueOnce(new Error('lost'))
      .mockResolvedValueOnce(response({ code: 'UNAVAILABLE' }, 404)).mockResolvedValueOnce(response({ state: 'saved', receipt }, 200));
    const storage = vi.spyOn(Storage.prototype, 'setItem'); render(<OrderInquiry orderId={orderId} />);
    fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' })); await screen.findByLabelText(/^Name/); fill(); send();
    await screen.findByRole('button', { name: 'Retry same inquiry' }); expect(screen.getByLabelText(/^Message/)).toHaveAttribute('readonly');
    send(); await screen.findByText(/An inquiry for this order is currently unavailable/); send(); await screen.findByText(receipt);
    const posts = fetcher.mock.calls.filter(([, init]) => init?.method === 'POST'); expect(posts).toHaveLength(3);
    expect(new Set(posts.map(([path]) => path))).toEqual(new Set([`/contact/inquiries/for-order/${orderId}`]));
    expect(new Set(posts.map(([, init]) => init?.body)).size).toBe(1);
    expect(JSON.parse(posts[0][1]!.body as string)).toEqual({ ...draft, noticeToken, requestKey: otherId });
    expect(crypto.randomUUID).toHaveBeenCalledTimes(1); expect(storage).not.toHaveBeenCalled(); expect(screen.queryByLabelText(/^Message/)).not.toBeInTheDocument();
  });
  it('rejects a broader success acknowledgement while preserving the same request', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ state: 'saved', receipt, secret: 'never display' }, 201)).mockResolvedValueOnce(response({ state: 'saved', receipt }, 200));
    render(<ContactInquiryForm orderId={orderId} privacyNotice={setup.privacyNotice} noticeToken={noticeToken} />); fill(); send();
    await screen.findByRole('button', { name: 'Retry same inquiry' }); expect(document.body.textContent).not.toContain('never display'); send(); await screen.findByText(receipt);
    expect(fetcher.mock.calls[1][1]?.body).toBe(fetcher.mock.calls[0][1]?.body);
  });
  it('keeps an order inquiry uncertain after an oversized or wrongly typed acknowledgement', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response(JSON.stringify({ state: 'saved', receipt }), { status: 201, headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response(' '.repeat(ORDER_INQUIRY_MAX_BYTES + 1), { status: 201, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(response({ state: 'saved', receipt }, 200));
    render(<ContactInquiryForm orderId={orderId} privacyNotice={setup.privacyNotice} noticeToken={noticeToken} />); fill(); send();
    await screen.findByRole('button', { name: 'Retry same inquiry' }); send(); await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same inquiry' })).toBeEnabled());
    expect(screen.queryByText(receipt)).not.toBeInTheDocument(); send(); await screen.findByText(receipt);
    expect(new Set(fetcher.mock.calls.map(([, init]) => init?.body)).size).toBe(1);
  });
  it('requires a new verified order setup after definitive stale notice rejection', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ orderInquiry: setup }))
      .mockResolvedValueOnce(response({ code: 'INQUIRY_VALIDATION_FAILED', errors: { noticeToken: [] } }, 422))
      .mockResolvedValueOnce(response({ orderInquiry: { ...setup, noticeToken: 'b'.repeat(64) } }));
    render(<OrderInquiry orderId={orderId} />); fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' })); await screen.findByLabelText(/^Name/); fill(); send();
    await screen.findByText(/close and reopen the order inquiry/); expect(screen.getByRole('button', { name: 'Send inquiry' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Close order inquiry' })); await waitFor(() => expect(screen.getByRole('button', { name: 'Ask about this test order' })).toHaveFocus());
    fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' })); await screen.findByLabelText(/^Name/); expect(screen.getByLabelText(/^Message/)).toHaveValue(''); expect(fetcher).toHaveBeenCalledTimes(3);
  });
  it('aborts a pending submission and clears private draft on departure without accepting a late saved response', async () => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ orderInquiry: setup })).mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    render(<OrderInquiry orderId={orderId} />); fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' })); await screen.findByLabelText(/^Name/); fill(); send();
    const signal = fetcher.mock.calls[1][1]?.signal; fireEvent(window, new Event('pagehide')); expect(signal?.aborted).toBe(true);
    await act(async () => finish(response({ state: 'saved', receipt }, 201))); expect(document.body.textContent).not.toContain(receipt); expect(screen.queryByLabelText(/^Message/)).not.toBeInTheDocument();
  });
  it('does not carry a pending attempt or late setup into another order', async () => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    const view = render(<OrderInquiry orderId={orderId} />); fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' }));
    const signal = fetcher.mock.calls[0][1]?.signal; view.rerender(<OrderInquiry orderId={otherId} />); expect(signal?.aborted).toBe(true);
    await act(async () => finish(response({ orderInquiry: setup }))); expect(screen.queryByText(orderId)).not.toBeInTheDocument(); expect(screen.queryByLabelText(/^Name/)).not.toBeInTheDocument();
  });
  it('expires a stalled availability read at the existing 20-second limit and ignores late data', async () => {
    vi.useFakeTimers(); let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    render(<OrderInquiry orderId={orderId} />); fireEvent.click(screen.getByRole('button', { name: 'Ask about this test order' }));
    await act(async () => vi.advanceTimersByTime(20_000)); expect(screen.getByRole('alert')).toHaveFocus(); expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    await act(async () => finish(response({ orderInquiry: setup }))); expect(screen.queryByLabelText(/^Name/)).not.toBeInTheDocument();
  });
});

describe('retained inquiry order reference', () => {
  it('reads only on demand, shows the minimal escaped reference and clears it before a denied refresh', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ context })).mockResolvedValueOnce(response({ privateError: 'secret' }, 404));
    render(<InquiryOrderContext receipt={receipt} />); expect(fetcher).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'Read inquiry order reference' })); expect(await screen.findByText(orderId)).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Refresh inquiry order reference' })); await screen.findByRole('alert'); expect(screen.queryByText(orderId)).not.toBeInTheDocument(); expect(document.body.textContent).not.toContain('secret');
  });
  it('supports a generic retained inquiry with no linked order, and restores trigger focus after hiding', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response({ context: { ...context, order: null } })); render(<InquiryOrderContext receipt={receipt} />);
    await userEvent.click(screen.getByRole('button', { name: 'Read inquiry order reference' })); expect(await screen.findByText('This inquiry has no linked test order.')).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Hide inquiry order reference' })); await waitFor(() => expect(screen.getByRole('button', { name: 'Read inquiry order reference' })).toHaveFocus()); expect(screen.queryByText(/no linked/)).not.toBeInTheDocument();
  });
  it('clears retained context on departure and aborts a later unresolved read', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ context })).mockImplementationOnce(() => new Promise(() => {}));
    render(<InquiryOrderContext receipt={receipt} />); fireEvent.click(screen.getByRole('button', { name: 'Read inquiry order reference' })); await screen.findByText(orderId);
    fireEvent(window, new Event('pagehide')); expect(screen.queryByText(orderId)).not.toBeInTheDocument(); fireEvent.click(screen.getByRole('button', { name: 'Read inquiry order reference' }));
    fireEvent(window, new Event('pagehide')); expect(fetcher.mock.calls[1][1]?.signal?.aborted).toBe(true);
  });
  it('offers retained context only while the conversation itself remains freshly readable', async () => {
    const snapshot = { receipt, state: 'archived', subject: 'Original subject', original: { message: 'Private message', createdAt: '2026-10-06T00:00:00Z' }, messages: [], canReply: false };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(snapshot)).mockResolvedValueOnce(response({ context })).mockResolvedValueOnce(response({}, 404));
    render(<InquiryConversation receipt={receipt} onClose={vi.fn()} />); await screen.findByText('Private message');
    const region = screen.getByRole('region', { name: 'Inquiry order reference' }); fireEvent.click(within(region).getByRole('button', { name: 'Read inquiry order reference' })); await screen.findByText(orderId);
    fireEvent.click(screen.getByRole('button', { name: 'Refresh conversation' })); await screen.findByRole('alert'); expect(screen.queryByText(orderId)).not.toBeInTheDocument(); expect(screen.queryByRole('region', { name: 'Inquiry order reference' })).not.toBeInTheDocument();
  });
});
