import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InquiryHistory } from '../../resources/js/components/InquiryHistory';
import { InquiryConversationEntry } from '../../resources/js/components/InquiryConversation';
import { INQUIRY_HISTORY_MAX_BYTES, readInquiryHistory, validInquiryHistory, type InquiryHistoryPage } from '../../resources/js/lib/inquiry-history';

const receipt = (id: number) => `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`;
const page = (start = 1, count = 1, more = false): InquiryHistoryPage => ({ inquiryHistorySchema: 1, limit: 20,
  inquiries: Array.from({ length: count }, (_, index) => ({ receipt: receipt(start - index), subject: `Subject ${start - index}`, state: 'new', createdAt: '2026-10-06T01:00:00Z' })),
  nextCursor: more ? receipt(start - count + 1) : null });
const response = (history: InquiryHistoryPage, status = 200) => new Response(JSON.stringify({ history }), { status, headers: { 'Content-Type': 'application/json' } });
const open = () => fireEvent.click(screen.getByRole('button', { name: 'Show inquiries from this browser session' }));
// Focus schedules jsdom's zero-delay selectionchange event; drain it before counting application deadlines.
const defer = <T,>() => { let resolve!: (value: T) => void; const promise = new Promise<T>(done => { resolve = done; }); return { promise, resolve }; };
afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); });

describe('original-session inquiry history', () => {
  it('loads only on intent, shows minimal escaped summaries and opens the selected receipt without storage', async () => {
    const history = page(); history.inquiries[0].subject = '<img src="https://foreign.example">'; history.inquiries[0].state = 'read';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(history));
    const stored = vi.spyOn(Storage.prototype, 'setItem'), onOpen = vi.fn();
    render(<InquiryHistory onOpen={onOpen} />); expect(fetcher).not.toHaveBeenCalled(); open();
    await screen.findByText(history.inquiries[0].subject); expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText(/Read by staff/)).toBeVisible();
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Saved inquiries' })).toHaveFocus());
    fireEvent.click(screen.getByRole('button', { name: `Open inquiry ${receipt(1)}` })); expect(onOpen).toHaveBeenCalledWith(receipt(1));
    expect(fetcher).toHaveBeenCalledWith('/contact/inquiries/history', expect.objectContaining({ method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error' }));
    expect(fetcher.mock.calls[0][1]?.body).toBeUndefined(); expect(stored).not.toHaveBeenCalled();
  });

  it('freshly fetches Older, Newer and Newest pages and never retains earlier summary payloads', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input).endsWith(receipt(2)) ? response(page(1))
      : String(input).endsWith(receipt(22)) ? response(page(21, 20, true)) : response(page(41, 20, true)));
    render(<InquiryHistory onOpen={vi.fn()} />); open(); await screen.findByText('Subject 41');
    fireEvent.click(screen.getByRole('button', { name: 'Older inquiries' })); expect(screen.queryByText('Subject 41')).not.toBeInTheDocument(); await screen.findByText('Subject 21');
    fireEvent.click(screen.getByRole('button', { name: 'Newer inquiries' })); await screen.findByText('Subject 41');
    fireEvent.click(screen.getByRole('button', { name: 'Older inquiries' })); await screen.findByText('Subject 21');
    fireEvent.click(screen.getByRole('button', { name: 'Older inquiries' })); await screen.findByText('Subject 1');
    expect(screen.queryByRole('button', { name: 'Older inquiries' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Newest inquiries' })); await screen.findByText('Subject 41');
    expect(fetcher.mock.calls.map(call => call[0])).toEqual(['/contact/inquiries/history', `/contact/inquiries/history/before/${receipt(22)}`,
      '/contact/inquiries/history', `/contact/inquiries/history/before/${receipt(22)}`, `/contact/inquiries/history/before/${receipt(2)}`, '/contact/inquiries/history']);
  });

  it('clears summaries and all cursors after access denial and requires contact reload', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(page(21, 20, true))).mockResolvedValueOnce(new Response('{}', { status: 403 }));
    render(<InquiryHistory onOpen={vi.fn()} />); open(); await screen.findByText('Subject 21');
    fireEvent.click(screen.getByRole('button', { name: 'Older inquiries' })); await screen.findByRole('alert');
    expect(screen.queryByText('Subject 21')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: /^(Older|Newer|Newest) inquiries$/ })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Refresh inquiries' })).toBeDisabled(); expect(screen.getByRole('link', { name: 'Reload contact' })).toHaveAttribute('href', '/contact');
  });

  it('refuses a repeated cursor instead of showing a looped private page', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => response(page(21, 20, true)));
    render(<InquiryHistory onOpen={vi.fn()} />); open(); await screen.findByText('Subject 21');
    fireEvent.click(screen.getByRole('button', { name: 'Older inquiries' })); await screen.findByRole('alert');
    expect(screen.queryByText('Subject 21')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Newer inquiries' })).not.toBeInTheDocument();
  });

  it('times out ignored abort, starts a new request, and rejects a late completion without clearing the new deadline', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }); const old = defer<Response>(), current = defer<Response>();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise);
    render(<InquiryHistory onOpen={vi.fn()} />); open();
    await act(async () => { vi.advanceTimersByTime(20_000); });
    expect(screen.getByRole('alert')).toHaveTextContent('could not be loaded'); expect((fetcher.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Refresh inquiries' })); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(1);
    await act(async () => { old.resolve(response(page(9))); await old.promise; });
    expect(screen.queryByText('Subject 9')).not.toBeInTheDocument(); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(1);
    await act(async () => { current.resolve(response(page(1))); await current.promise; });
    expect(screen.getByText('Subject 1')).toBeVisible(); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(0);
  });

  it('clears on pagehide and unmount, cancels timers, and does not resurrect old payloads', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }); const pending = defer<Response>();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockReturnValue(pending.promise);
    const view = render(<InquiryHistory onOpen={vi.fn()} />); open(); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(1);
    act(() => window.dispatchEvent(new Event('pagehide'))); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(0);
    expect((fetcher.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
    await act(async () => { pending.resolve(response(page(1))); await pending.promise; });
    expect(screen.queryByText('Subject 1')).not.toBeInTheDocument();
    fetcher.mockReturnValue(new Promise(() => {})); open(); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(1); view.unmount(); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(0);
  });

  it('reopens through the existing conversation and pagehide removes the child and abandoned deadline', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(page(1))).mockImplementation(() => new Promise(() => {}));
    render(<InquiryConversationEntry />); open(); await screen.findByText('Subject 1');
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }); fireEvent.click(screen.getByRole('button', { name: `Open inquiry ${receipt(1)}` }));
    expect(screen.getByRole('region', { name: 'Private inquiry conversation' })).toBeVisible(); expect(screen.queryByText('Subject 1')).not.toBeInTheDocument();
    act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(1); act(() => window.dispatchEvent(new Event('pagehide')));
    expect(screen.queryByRole('region', { name: 'Private inquiry conversation' })).not.toBeInTheDocument(); act(() => vi.advanceTimersByTime(0)); expect(vi.getTimerCount()).toBe(0);
    expect((fetcher.mock.calls[1][1]?.signal as AbortSignal).aborted).toBe(true); expect(screen.getByLabelText('Inquiry receipt')).toHaveValue('');
  });

  it('shows a truthful empty state without offering pagination', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(page(1, 0)));
    render(<InquiryHistory onOpen={vi.fn()} />); open(); await screen.findByText('No inquiries are available in this browser session.');
    expect(screen.queryByRole('button', { name: 'Older inquiries' })).not.toBeInTheDocument();
  });
});

describe('inquiry history transport and schema', () => {
  it.each([
    ['unknown schema', (h: any) => { h.inquiryHistorySchema = 2; }],
    ['private extra field', (h: any) => { h.inquiries[0].email = 'private@example.test'; }],
    ['extra envelope field', (h: any) => { h.owner = 'private'; }],
    ['duplicate receipts', (h: any) => { h.inquiries.push(h.inquiries[0]); }],
    ['wrong bound', (h: any) => { h.limit = 21; }],
    ['too many rows', (h: any) => { h.inquiries = page(21, 21).inquiries; }],
    ['invalid state', (h: any) => { h.inquiries[0].state = 'unread'; }],
    ['invalid calendar date', (h: any) => { h.inquiries[0].createdAt = '2026-02-30T00:00:00Z'; }],
    ['noncanonical date', (h: any) => { h.inquiries[0].createdAt = '2026-10-06T01:00:00+00:00'; }],
    ['invalid subject', (h: any) => { h.inquiries[0].subject = 'x'.repeat(161); }],
    ['control character', (h: any) => { h.inquiries[0].subject = 'private\nsubject'; }],
    ['unpaired surrogate', (h: any) => { h.inquiries[0].subject = '\ud800'; }],
    ['cursor without full page', (h: any) => { h.nextCursor = receipt(1); }],
  ])('rejects %s', (_name, change) => { const history = page(); change(history); expect(validInquiryHistory(history)).toBe(false); });

  it('reads the maximum supported escaped supplementary Unicode envelope below its byte cap', async () => {
    const history = page(20, 20, true); for (const row of history.inquiries) row.subject = '🎛'.repeat(160);
    const raw = JSON.stringify({ history }).replace(/[^\x00-\x7f]/g, unit => '\\u' + unit.charCodeAt(0).toString(16).padStart(4, '0'));
    expect(new TextEncoder().encode(raw).byteLength).toBeLessThan(INQUIRY_HISTORY_MAX_BYTES);
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(raw, { headers: { 'Content-Type': 'application/json' } }));
    expect(await readInquiryHistory(null, new AbortController().signal)).toEqual({ kind: 'loaded', history });
  });

  it('cancels an oversized streamed body before parsing or reflecting private text', async () => {
    const cancel = vi.fn(); const stream = new ReadableStream({ start(controller) { controller.enqueue(new Uint8Array(INQUIRY_HISTORY_MAX_BYTES + 1)); }, cancel });
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(stream, { headers: { 'Content-Type': 'application/json' } }));
    expect(await readInquiryHistory(null, new AbortController().signal)).toEqual({ kind: 'unavailable' }); expect(cancel).toHaveBeenCalledTimes(1);
  });

  it.each([401, 403, 419])('returns a reload state for authority status %s', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('private body', { status }));
    expect(await readInquiryHistory(null, new AbortController().signal)).toEqual({ kind: 'reload' });
  });

  it('rejects invalid transport, redirected responses and malformed UTF-8', async () => {
    const redirect = response(page()); Object.defineProperty(redirect, 'redirected', { value: true });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response(JSON.stringify({ history: page() })))
      .mockResolvedValueOnce(redirect).mockResolvedValueOnce(new Response(new Uint8Array([0xff]), { headers: { 'Content-Type': 'application/json' } }));
    for (let i = 0; i < 3; i++) expect(await readInquiryHistory(null, new AbortController().signal)).toEqual({ kind: 'unavailable' });
    expect(await readInquiryHistory('invalid', new AbortController().signal)).toEqual({ kind: 'unavailable' }); expect(fetcher).toHaveBeenCalledTimes(3);
  });
});
