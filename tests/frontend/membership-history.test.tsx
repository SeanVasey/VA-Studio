import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { OwnedMembershipHistory } from '../../resources/js/components/OwnedMembershipHistory';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { readMembershipBuckets, readMembershipHistory, validMembershipBuckets, validMembershipHistory } from '../../resources/js/lib/membership-history';
import { navigateCustomerSession } from '../../resources/js/lib/customer-session';
vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/lib/customer-session', async original => ({ ...await original<typeof import('../../resources/js/lib/customer-session')>(), navigateCustomerSession: vi.fn() }));
const balance = { available: 10, reserved: 0, consumed: 0, expired: 0 };
const history = { bucket_id: 12, plan_version_id: 7, unit: 'credit', expires_at: '2026-01-01 00:00:00', last_event_id: 20, balance, spendable_credits: 0, test_only: true,
  plan: { version_id: 7, number: 2, title: 'Synthetic retained plan', policy: { schema_version: 1, unit: 'credit', allowance: 10, validity_seconds: 100, rollover: 'none', reversal_allowed: true } },
  events: [{ id: 20, sequence: 1, kind: 'grant', amount: 10, created_at: '2025-12-31 23:58:20', balance }] };
const json = (history: unknown, status = 200) => new Response(JSON.stringify({ history }), { status, headers: { 'Content-Type': 'application/json' } });
const buckets = { test_only: true, bucket_ids: [12] };
const library = (scope = 'a'.repeat(32), enabled = true) => <CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Same name' }} testMembershipsEnabled={enabled} membershipHistoryScope={scope} />;
async function browse() { fireEvent.click(screen.getByRole('button', { name: 'Browse test credit buckets' })); await screen.findByRole('button', { name: 'View test credit bucket 1' }); }
async function details() { await browse(); fireEvent.click(screen.getByRole('button', { name: 'View test credit bucket 1' })); await screen.findByText('Synthetic retained plan · Version 2'); }

describe('bounded private membership response', () => {
  it('accepts exact owned discovery and retained expiry with zero server spendability', () => {
    expect(validMembershipBuckets(buckets)).toBe(true); expect(validMembershipHistory(history)).toBe(true);
  });
  it.each([[], [12, 12], [12, 11], [0], [Number.MAX_SAFE_INTEGER + 1], Array.from({ length: 101 }, (_, i) => i + 1)].map(ids => ({ ids })))('fails closed for malformed or oversized IDs $ids', ({ ids }) => {
    if (ids.length === 0) expect(validMembershipBuckets({ test_only: true, bucket_ids: ids })).toBe(true);
    else expect(validMembershipBuckets({ test_only: true, bucket_ids: ids })).toBe(false);
  });
  it.each([
    { ...history, test_only: false }, { ...history, private_owner: 'secret' }, { ...history, spendable_credits: 11 },
    { ...history, plan_version_id: 8 }, { ...history, last_event_id: 21 }, { ...history, expires_at: '2026-02-30 00:00:00' },
    { ...history, events: [] }, { ...history, events: [{ ...history.events[0], sequence: 2 }] },
    { ...history, events: [{ ...history.events[0], balance: { ...balance, available: 9 } }] },
  ])('rejects malformed or unexpected private fields', value => expect(validMembershipHistory(value)).toBe(false));
  it('uses exact bodyless same-origin no-store requests and rejects mismatched detail identity', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(buckets)).mockResolvedValueOnce(json({ ...history, bucket_id: 13 }));
    const signal = new AbortController().signal;
    expect((await readMembershipBuckets(signal)).kind).toBe('loaded');
    expect(fetcher).toHaveBeenNthCalledWith(1, '/account/membership-credits', { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', redirect: 'error', signal });
    expect(await readMembershipHistory(12, signal)).toEqual({ kind: 'unavailable' });
    expect(fetcher.mock.calls[1][0]).toBe('/account/membership-credits/12'); expect(fetcher.mock.calls[1][1]).not.toHaveProperty('body');
    await readMembershipHistory(0, signal); expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it('bounds streamed discovery bytes and rejects unsupported JSON envelopes without reflecting details', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response(' '.repeat(4097), { headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ history: buckets, private: 'secret' }), { headers: { 'Content-Type': 'application/json' } }));
    expect(await readMembershipBuckets(new AbortController().signal)).toEqual({ kind: 'unavailable' });
    expect(await readMembershipBuckets(new AbortController().signal)).toEqual({ kind: 'unavailable' });
  });
});

describe('read-only synthetic membership history', () => {
  it('is absent by default and requires an explicit server capability plus scope', () => {
    render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Same name' }} />);
    expect(screen.queryByRole('region', { name: 'Your test membership history' })).not.toBeInTheDocument();
    expect(vi.spyOn(globalThis, 'fetch')).not.toHaveBeenCalled();
  });
  it.each([undefined, null, '', 'bad', 'a'.repeat(31)])('fails closed without a valid opaque render scope %s', scope => {
    render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Same name' }} testMembershipsEnabled membershipHistoryScope={scope} />);
    expect(screen.queryByRole('region', { name: 'Your test membership history' })).not.toBeInTheDocument();
  });
  it('expires a stalled request and discards its late response before a retry', async () => {
    vi.useFakeTimers();
    let complete!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { complete = resolve; }));
    render(<OwnedMembershipHistory />); fireEvent.click(screen.getByRole('button', { name: 'Browse test credit buckets' }));
    await act(async () => { vi.advanceTimersByTime(20_000); });
    expect((fetcher.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
    expect(screen.getByRole('alert')).toHaveTextContent('Test membership history is unavailable');
    await act(async () => { complete(json(buckets)); });
    expect(screen.queryByRole('button', { name: 'View test credit bucket 1' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Browse test credit buckets' })).not.toBeDisabled(); vi.useRealTimers();
  });
  it('discovers owned opaque selections and displays server expiry truth without claiming a grant or entitlement', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(buckets)).mockResolvedValueOnce(json(history));
    render(<OwnedMembershipHistory />); await details();
    expect(screen.getByText(/spendable test credits at this read: 0/)).toBeInTheDocument();
    expect(screen.getByText(/Retained balance: 10 available/)).toBeInTheDocument();
    expect(screen.getByText(/does not renew credits or record expiry/)).toBeInTheDocument();
    expect(screen.getByText(/do not establish a subscription/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /spend|purchase|grant|download/i })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it('distinguishes empty ownership from unavailable and clears all earlier details on rejected refresh', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(buckets)).mockResolvedValueOnce(json(history)).mockResolvedValueOnce(json({ private: 'OTHER ACCOUNT SECRET' }, 403));
    render(<OwnedMembershipHistory />); await details();
    fireEvent.click(screen.getByRole('button', { name: 'Browse test credit buckets' })); await screen.findByRole('alert');
    expect(screen.queryByText(/Synthetic retained plan/)).not.toBeInTheDocument(); expect(screen.queryByText(/OTHER ACCOUNT SECRET/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Browse test credit buckets' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toBeInTheDocument();
  });
  it('shows honest empty test ownership', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ test_only: true, bucket_ids: [] })); render(<OwnedMembershipHistory />);
    fireEvent.click(screen.getByRole('button', { name: 'Browse test credit buckets' })); await screen.findByText('No test credit buckets belong to this account.');
  });
  it('discards late detail completion after pagehide and allows a fresh discovery', async () => {
    let complete!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(buckets)).mockImplementationOnce(() => new Promise(resolve => { complete = resolve; })).mockResolvedValueOnce(json({ test_only: true, bucket_ids: [] }));
    render(<OwnedMembershipHistory />); await browse(); fireEvent.click(screen.getByRole('button', { name: 'View test credit bucket 1' }));
    fireEvent(window, new Event('pagehide')); expect((fetcher.mock.calls[1][1]?.signal as AbortSignal).aborted).toBe(true);
    await act(async () => { complete(json(history)); }); expect(screen.queryByText(/Synthetic retained plan/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Browse test credit buckets' })); await screen.findByText('No test credit buckets belong to this account.');
  });
  it('clears a same-name account rerender immediately and rejects its stale pending request', async () => {
    let complete!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { complete = resolve; }));
    const view = render(library('a'.repeat(32))); fireEvent.click(screen.getByRole('button', { name: 'Browse test credit buckets' }));
    view.rerender(library('b'.repeat(32))); expect((fetcher.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
    await act(async () => { complete(json(buckets)); }); expect(screen.queryByRole('button', { name: 'View test credit bucket 1' })).not.toBeInTheDocument();
  });
  it('clears all private history at sign-out before navigation completes', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json(buckets)).mockResolvedValueOnce(json(history))
      .mockResolvedValueOnce(new Response(JSON.stringify({ authenticated: false, next: '/account/sign-in' }), { headers: { 'Content-Type': 'application/json' } }));
    const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'synthetic'; document.head.append(meta);
    render(library()); await details(); fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(screen.queryByText(/Synthetic retained plan/)).not.toBeInTheDocument();
    await act(async () => {}); expect(fetcher).toHaveBeenCalledTimes(3); expect(navigateCustomerSession).toHaveBeenCalledWith('/account/sign-in'); meta.remove();
  });
});
