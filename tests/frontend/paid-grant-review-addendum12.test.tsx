import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidGrantJourney, type PaidOrigin } from '../../resources/js/components/PaidGrantJourney';

// Independent review addendum 12 probe (untracked). Helpers copied from paid-grant-continuation.test.tsx.
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

describe('review addendum 12: continuation edges', () => {
  // A12-I3: when the holder is the page's own lost completion request (no claim visible), every poll re-POSTs and gets
  // busy, so the 20-request cap ends the wait well before the 660 s window.
  it('ends a wait on its own lost completion by the 20-request cap, before the 660 s window', async () => {
    const done = originOf(['complete', 'complete', 'complete']);
    const s = await openOrder(done, { [documentPath]: [never, answer({ origin: done, busy: true })], [statusPath]: [answer(statusOf(null))] });
    fireEvent.click(screen.getByRole('button', { name: 'Finish preparing this order' }));
    const start = Date.now();
    let stoppedAt = 0;
    for (let t = 0; t < 1_200_000 && !stoppedAt; t += 5_000) {
      await advance(5_000);
      if (/Paused after|Still waiting/.test(live().textContent ?? '')) stoppedAt = Date.now() - start;
    }
    expect(live()).toHaveTextContent('Paused after 20 requests');
    expect(s.posts()).toHaveLength(20);
    // The cap binds about 320 s (the lost answer) plus 19 busy rounds of about 15 s, far inside 320 s + 660 s.
    expect(stoppedAt).toBeLessThan(320_000 + 660_000);
    expect(stoppedAt).toBeLessThan(320_000 + 20 * 16_000);
    fwrite(stoppedAt);
  });

  // A12-I4: choosing Stop removes the Stop button, which held focus, and focus fell back to the document body.
  // Fixed in round 21 (codex-21): Stop moves focus to the progress line.
  it('moves focus to the progress line after Stop removes its own button (fixed in round 21)', async () => {
    await openOrder(originOf(['pending', 'pending', 'pending']), { [documentPath]: [never] });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original licenses and files' }));
    await advance(1_000);
    const stop = screen.getByRole('button', { name: 'Stop preparing' });
    stop.focus();
    expect(document.activeElement).toBe(stop);
    fireEvent.click(stop);
    await advance(1_000);
    expect(screen.queryByRole('button', { name: 'Stop preparing' })).not.toBeInTheDocument();
    expect(document.activeElement).not.toBe(document.body);
    expect(document.activeElement).toBe(screen.getByRole('status'));
    expect(live()).toHaveTextContent('Preparation stopped');
  });
});
function fwrite(ms: number) { process.stdout.write(`A12 P3 stopped after ${ms} ms\n`); }
