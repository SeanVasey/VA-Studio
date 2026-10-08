import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Condition C13 (page part): after one click the page keeps preparing while each request makes progress, waits on other
// work by polling saved status, recovers from a client timeout, and stops on no progress, a refusal, the 20-request cap
// or a hidden tab. Document POSTs stay at least 10 s apart (route throttle 6/min). Synthetic fixtures only.
type Doc = PaidOrigin['lines'][number]['documentStatus'];
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const lineId = (i: number) => `cccccccc-cccc-4ccc-8ccc-cccccccccc0${i}`;
const csrfValue = 'SYNTHETIC_CSRF_VALUE'.padEnd(40, 'q');
function originOf(states: Doc[], fulfilled = false): PaidOrigin {
  return { id: batchId, orderId, purpose: 'paid-license-grant', provenance: 'synthetic_rehearsal', fulfilled, lines: states.map((documentStatus, i) => ({
    id: lineId(i + 1), originHash: String(i + 1).repeat(64), position: i + 1, title: `Synthetic recording ${i + 1}`,
    license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive' as const, features: ['WAV'], deliverableRoles: ['master_wav' as const], termsText: 'SYNTHETIC TERMS' },
    declaredName: 'Synthetic buyer', assentedAt: '2026-10-07T01:02:03Z', currency: 'USD' as const, lineAmountMinor: 4999, lineTaxMinor: 0, documentStatus, attempts: documentStatus === 'pending' ? 0 : 1,
    files: fulfilled ? [{ kind: 'master_wav' as const, sha256: 'b'.repeat(64), sizeBytes: 1024 }, { kind: 'contract' as const, sha256: 'c'.repeat(64), sizeBytes: 2048 }] : [] })) };
}
const fulfilledOrigin = originOf(['complete', 'complete', 'complete'], true);
const statusOf = (claimed: number | null, fulfilled = false) => ({ status: { schemaVersion: 1, originId: batchId, fulfilled, lines: [1, 2, 3].map(i => ({
  id: lineId(i), attemptCount: 0, maxDownloads: 3, historyLimit: 20, renderRetryAllowed: false, renderRetryAfter: i === claimed ? '2026-10-07 01:07:03' : null, history: [] })) } });
const listing = { schemaVersion: 1, originLimit: 20, origins: [{ id: batchId, orderId, createdAt: '2026-10-07 01:02:03', provenance: 'synthetic_rehearsal' }] };
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const documentPath = `/paid-grants/origins/${batchId}/document`, statusPath = `/paid-grants/origins/${batchId}/downloads`, showPath = `/paid-grants/origins/${batchId}`;

/** Routes each path to its own queue of answers; the last answer repeats. Records every call with the fake clock time. */
function server(routes: Record<string, (() => Promise<Response>)[]>) {
  const calls: { path: string; at: number }[] = [];
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
    const path = String(input); calls.push({ path, at: performance.now() });
    const queue = routes[path]; if (!queue?.length) throw new Error(`unexpected ${path}`);
    return (queue.length > 1 ? queue.shift()! : queue[0])();
  });
  return { calls, fetcher, posts: () => calls.filter(c => c.path === documentPath) };
}
const answer = (body: unknown) => () => Promise.resolve(json(body));
const never = () => new Promise<Response>(() => {});
const advance = (ms: number) => act(() => vi.advanceTimersByTimeAsync(ms));
const live = () => screen.getByRole('status');

beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-paid-csrf name="csrf-token" content="${csrfValue}">`); });
afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); document.head.querySelectorAll('[data-paid-csrf]').forEach(e => e.remove());
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' }); });
async function openOrder(start: PaidOrigin, routes: Record<string, (() => Promise<Response>)[]>) {
  const s = server({ '/paid-grants/index': [answer(listing)], ...routes, [showPath]: [answer({ origin: start }), ...(routes[showPath] ?? [])] });
  render(<PaidGrantJourney />);
  fireEvent.click(screen.getByRole('button', { name: 'Open paid licenses' }));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  await screen.findByLabelText('Retained paid order');
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
  return s;
}

describe('continuing paid preparation after one click', () => {
  it('keeps posting while each request makes progress, at least 10 s apart, until the order is fulfilled', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [
      answer({ origin: originOf(['complete', 'pending', 'pending']), busy: false }),
      answer({ origin: originOf(['complete', 'complete', 'pending']), busy: false }),
      answer({ origin: fulfilledOrigin, busy: false })] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    expect(s.posts()).toHaveLength(1);
    expect(live()).toHaveTextContent('Preparing your files: 1 of 3 lines ready.');
    expect(live()).toHaveAttribute('aria-live', 'polite');
    await advance(9_999);
    expect(s.posts()).toHaveLength(1);
    await advance(1);
    expect(s.posts()).toHaveLength(2);
    expect(live()).toHaveTextContent('Preparing your files: 2 of 3 lines ready.');
    await advance(10_000);
    expect(s.posts()).toHaveLength(3);
    expect(live()).toHaveTextContent('Your files are ready.');
    expect(screen.getByRole('button', { name: 'Authorize master_wav for Synthetic recording 1' })).toBeEnabled();
    await advance(120_000);
    expect(s.posts()).toHaveLength(3);
    const at = s.posts().map(p => p.at);
    expect(at.slice(1).map((t, i) => t - at[i]).every(gap => gap >= 10_000)).toBe(true);
    expect(document.activeElement).toBe(document.body);
    expect(document.body.innerHTML).not.toContain(csrfValue);
  });

  it('waits on another request\'s claim by polling saved status every 15 s, then continues', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), {
      [documentPath]: [answer({ origin: originOf(['complete', 'claimed', 'pending']), busy: true }), answer({ origin: fulfilledOrigin, busy: false })],
      [statusPath]: [answer(statusOf(2)), answer(statusOf(null))] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    expect(live()).toHaveTextContent('Another request is still working on this order');
    await advance(14_999);
    expect(s.calls.filter(c => c.path === statusPath)).toHaveLength(0);
    await advance(1);
    expect(s.calls.filter(c => c.path === statusPath)).toHaveLength(1);
    expect(s.posts()).toHaveLength(1);
    await advance(15_000);
    expect(s.calls.filter(c => c.path === statusPath)).toHaveLength(2);
    expect(s.posts()).toHaveLength(2);
    expect(live()).toHaveTextContent('Your files are ready.');
    expect(s.calls.map(c => c.path).slice(2)).toEqual([documentPath, statusPath, statusPath, documentPath]);
  });

  it('recovers from a document timeout by polling saved status instead of stopping', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), {
      [documentPath]: [never], [statusPath]: [answer(statusOf(1)), answer(statusOf(null, true))], [showPath]: [answer({ origin: fulfilledOrigin })] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(320_000);
    expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
    await advance(15_000);
    expect(s.calls.filter(c => c.path === statusPath)).toHaveLength(1);
    await advance(15_000);
    expect(s.calls.map(c => c.path).slice(2)).toEqual([documentPath, statusPath, statusPath, showPath]);
    expect(live()).toHaveTextContent('Your files are ready.');
    expect(screen.getByText(/Complete-order preparation is recorded/)).toBeInTheDocument();
    expect(s.posts()).toHaveLength(1);
  });

  it('stops with the manual controls when a request makes no progress and nothing else holds the work', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [answer({ origin: originOf(['failed', 'pending', 'pending']), busy: false })] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    expect(live()).toHaveTextContent('0 of 3 lines ready');
    expect(live()).toHaveTextContent('stopped');
    await advance(120_000);
    expect(s.posts()).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Prepare original licenses and files' })).toBeEnabled();
  });

  it('stops after an error refusal', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [() => Promise.resolve(json({ error: 'conflict' }, 409))] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
    await advance(120_000);
    expect(s.calls.map(c => c.path).slice(2)).toEqual([documentPath]);
  });

  it('never sends more than 20 document requests for one click', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), {
      [documentPath]: [answer({ origin: originOf(['pending', 'pending', 'pending']), busy: true })], [statusPath]: [answer(statusOf(null))] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    for (let i = 0; i < 60; i++) await advance(15_000);
    expect(s.posts()).toHaveLength(20);
    expect(live()).toHaveTextContent('Paused after 20 requests');
    const at = s.posts().map(p => p.at);
    expect(at.slice(1).map((t, i) => t - at[i]).every(gap => gap >= 10_000)).toBe(true);
  });

  it('keeps the progress region outside the busy subtree and offers a Stop control that ends the continuation (A11-I5)', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [
      answer({ origin: originOf(['complete', 'pending', 'pending']), busy: false }), answer({ origin: originOf(['complete', 'complete', 'pending']), busy: false })] });
    expect(screen.queryByRole('button', { name: 'Stop preparing' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    expect(live().closest('[aria-busy="true"]')).toBeNull();
    expect(screen.getByLabelText('Paid license journey')).toHaveAttribute('aria-busy', 'true');
    const stop = screen.getByRole('button', { name: 'Stop preparing' });
    expect(stop).toBeEnabled();
    fireEvent.click(stop);
    expect(live()).toHaveTextContent('1 of 3 lines ready');
    expect(live()).toHaveTextContent('stopped');
    await advance(120_000);
    expect(s.posts()).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Stop preparing' })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Retained paid order')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Prepare original licenses and files' })).toBeEnabled();
  });

  it('stops a request in flight when Stop is chosen and sends nothing more', async () => {
    const signals: AbortSignal[] = [];
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [() => new Promise<Response>(() => {})] });
    s.fetcher.mockImplementation((input: RequestInfo | URL, init?: RequestInit) => { s.calls.push({ path: String(input), at: performance.now() }); signals.push(init!.signal!); return new Promise<Response>(() => {}); });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(1_000);
    fireEvent.click(screen.getByRole('button', { name: 'Stop preparing' }));
    expect(signals[0].aborted).toBe(true);
    await advance(400_000);
    expect(s.posts()).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Refresh preparation and download status' })).toBeEnabled();
  });

  it('measures POST spacing on the monotonic clock, so a backward wall-clock step does not stall the next request (A11-I6)', async () => {
    let release: (r: Response) => void = () => {};
    const held = () => new Promise<Response>(resolve => { release = resolve; });
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), {
      [documentPath]: [held, answer({ origin: originOf(['complete', 'complete', 'pending']), busy: false })] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(1_000);
    vi.setSystemTime(Date.now() - 3_600_000);
    await act(async () => { release(json({ origin: originOf(['complete', 'pending', 'pending']), busy: false })); });
    await advance(9_000);
    expect(s.posts()).toHaveLength(2);
  });

  it('keeps polling while another holder may still legitimately hold the buyer lock (up to 660 s), then stops', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), {
      [documentPath]: [answer({ origin: originOf(['complete', 'claimed', 'pending']), busy: true })], [statusPath]: [answer(statusOf(2))] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    await advance(600_000);
    const reads = s.calls.filter(c => c.path === statusPath).length;
    expect(reads).toBe(40);
    expect(live()).toHaveTextContent('checking again shortly');
    await advance(120_000);
    expect(live()).toHaveTextContent('Still waiting for other work');
    expect(s.calls.filter(c => c.path === statusPath).length).toBeLessThanOrEqual(Math.ceil(660_000 / 15_000));
    expect(s.posts()).toHaveLength(1);
  });

  it('stops when the tab is hidden and does not resume on return', async () => {
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [answer({ origin: originOf(['complete', 'pending', 'pending']), busy: false })] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(0);
    expect(s.posts()).toHaveLength(1);
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });
    await advance(120_000);
    expect(s.posts()).toHaveLength(1);
    expect(s.calls).toHaveLength(3);
  });
});
