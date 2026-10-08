import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 11 probe (untracked). Helpers copied from paid-grant-continuation.test.tsx.
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
    const path = String(input); calls.push({ path, at: Date.now() });
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
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
  return s;
}

describe('review addendum 11: continuation edges', () => {
  // A11-I5: the progress live region sits inside the section that carries aria-busy="true" for the whole continuation.
  it('keeps the progress live region inside an aria-busy="true" ancestor while continuing', async () => {
    await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [never] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(1_000);
    expect(live()).toHaveTextContent('Preparing your files: 0 of 3 lines ready.');
    expect(live().closest('[aria-busy="true"]')).not.toBeNull();
  });

  // A11-I4: a completion pass that outlasts the client timeout plus the waiting window is not seen through by the page.
  it('gives up on a long completion held by its own lost request, leaving the order to a later manual finish', async () => {
    const done = originOf(['complete', 'complete', 'complete']);
    const s = await openOrder(done, {
      [documentPath]: [never, answer({ origin: done, busy: true })], [statusPath]: [answer(statusOf(null))] });
    fireEvent.click(screen.getByRole('button', { name: 'Finish preparing this order' }));
    await advance(320_000);
    expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed');
    await advance(400_000);
    const posts = s.posts().length, reads = s.calls.filter(c => c.path === statusPath).length;
    expect(live().textContent).toMatch(/Still waiting for other work|Paused after/);
    await advance(600_000);
    expect(s.posts()).toHaveLength(posts);
    expect(s.calls.filter(c => c.path === statusPath)).toHaveLength(reads);
    expect(screen.getByRole('button', { name: 'Finish preparing this order' })).toBeEnabled();
    // Bounded: at most 20 posts and one read per 15 s.
    expect(posts).toBeLessThanOrEqual(20);
    expect(reads).toBeLessThanOrEqual(Math.ceil(400_000 / 15_000));
  });

  // A11-I6: spacing is measured on the wall clock; a backward step stalls the next request for the size of the step.
  it('stalls the next document request after a backward wall-clock step', async () => {
    let release: (r: Response) => void = () => {};
    const held = () => new Promise<Response>(resolve => { release = resolve; });
    const s = await openOrder(originOf(['pending', 'pending', 'pending']), {
      [documentPath]: [held, answer({ origin: originOf(['complete', 'complete', 'pending']), busy: false })] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(1_000);
    expect(s.posts()).toHaveLength(1);
    // The wall clock steps back one hour while the first request is in flight; its progress answer then arrives.
    vi.setSystemTime(Date.now() - 3_600_000);
    await act(async () => { release(json({ origin: originOf(['complete', 'pending', 'pending']), busy: false })); });
    await advance(120_000);
    expect(s.posts()).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Refresh preparation and download status' })).toBeDisabled();
    // Only after the step has been waited out does the next request go.
    await advance(3_600_000);
    expect(s.posts().length).toBeGreaterThanOrEqual(2);
  });
});
