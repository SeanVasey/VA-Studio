import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Storefront from '../../resources/js/Pages/Storefront';
import { useCart } from '../../resources/js/lib/useCart';
import { savedCartSelection, type CatalogPage } from '../../resources/js/lib/catalog';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';

const tracks = fixtureTracks.map((track, index) => ({ ...track, id: String(index + 1) }));
const firstPage = tracks.slice(0, 1);
const secondPage = tracks.slice(1, 2);
const page: CatalogPage = { filters: { q: '', genre: '', sort: 'featured' }, previousUrl: null, nextUrl: '/?cursor=opaque', restartUrl: '/?', currentUrl: '/?', hasCursor: false };
const selection = savedCartSelection({ track: tracks[0], offer: tracks[0].offers[0] });
const response = (items = firstPage) => new Response(JSON.stringify({ tracks: items, licenseTiers: fixtureTiers }));

it('restores and changes a selection outside the current page using current server data', async () => {
  sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([{ ...selection, priceMinor: 1 }]));
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async () => response());
  const user = userEvent.setup();
  const view = render(<Storefront tracks={secondPage} licenseTiers={fixtureTiers} catalogPage={page} />);
  await user.click(screen.getByRole('button', { name: 'Open cart, 1 item' }));
  await waitFor(() => expect(screen.queryByText('Checking your saved selections…')).not.toBeInTheDocument());
  expect(within(screen.getByRole('dialog')).getByRole('heading', { name: tracks[0].title })).toBeInTheDocument();
  expect(within(screen.getByRole('dialog')).getByText('$29.95', { selector: '.cart-line-price strong' })).toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Change license' }));
  await user.click(screen.getByRole('radio', { name: /Premium license/ }));
  await user.click(screen.getByRole('button', { name: /Add license/ }));
  await waitFor(() => expect(JSON.parse(sessionStorage.getItem('vaseyaudio-cart-v1')!)[0].offerId).toBe(tracks[0].offers[1].id));
  view.rerender(<Storefront tracks={firstPage} licenseTiers={fixtureTiers} catalogPage={page} />);
  expect(screen.getByRole('button', { name: 'Open cart, 1 item' })).toBeInTheDocument();
  expect(fetcher.mock.calls.every(([url]) => url === '/catalog/selections')).toBe(true);
  expect(JSON.parse(String(fetcher.mock.calls[0][1]?.body))).toEqual({ trackIds: ['1'] });
  expect(sessionStorage.getItem('vaseyaudio-cart-v1')).not.toContain('priceMinor');
});

it('retains stored choices on network failure and retries before permitting a quote review', async () => {
  sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([selection]));
  const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('offline')).mockImplementation(async () => response());
  const user = userEvent.setup();
  render(<Storefront tracks={secondPage} licenseTiers={fixtureTiers} catalogPage={page} />);
  await user.click(screen.getByRole('button', { name: 'Open cart, 1 item' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Your selections are saved.');
  expect(screen.queryByRole('button', { name: /Review selections/ })).not.toBeInTheDocument();
  expect(JSON.parse(sessionStorage.getItem('vaseyaudio-cart-v1')!)).toEqual([selection]);
  await user.click(screen.getByRole('button', { name: 'Retry selection check' }));
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  expect(fetcher).toHaveBeenCalledTimes(2);
  expect(screen.getByRole('button', { name: 'Open cart, 1 item' })).toBeInTheDocument();
});

it.each(['revision', 'withdrawal'])('removes an off-page selection only after confirmed %s changes', async change => {
  sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([selection]));
  const changed = change === 'withdrawal' ? [] : [{ ...tracks[0], offers: tracks[0].offers.map(offer => ({ ...offer, offerRevisionId: 'successor' })) }];
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => response(changed));
  const { result } = renderHook(() => useCart(secondPage, true));
  await waitFor(() => expect(result.current.pending).toBe(false));
  expect(result.current.lines).toHaveLength(0);
  expect(result.current.unavailable).toBe(1);
  expect(JSON.parse(sessionStorage.getItem('vaseyaudio-cart-v1')!)).toEqual([]);
});

it('ignores an older reconciliation response after navigating to another page', async () => {
  sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([selection]));
  let finishOld!: (response: Response) => void;
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve; })).mockImplementation(async () => response());
  const { result, rerender } = renderHook(({ items }) => useCart(items, true), { initialProps: { items: firstPage } });
  rerender({ items: secondPage });
  await waitFor(() => expect(result.current.pending).toBe(false));
  await act(async () => { finishOld(response([])); });
  expect(fetcher).toHaveBeenCalledTimes(2);
  expect(result.current.lines).toHaveLength(1);
  expect(result.current.unavailable).toBe(0);
});

it('preserves choices on a malformed lookup response', async () => {
  sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([selection]));
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ tracks: null })));
  const { result } = renderHook(() => useCart(secondPage, true));
  await waitFor(() => expect(result.current.error).toBe(true));
  expect(result.current.selections).toEqual([selection]);
});

it('submits server filters and preserves cursor/query state on page and track navigation', async () => {
  const user = userEvent.setup();
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
  const filtered = { ...page, filters: { q: 'quiet', genre: 'Cinematic', sort: 'title' as const }, currentUrl: '/?q=quiet&genre=Cinematic&sort=title&cursor=opaque' };
  const view = render(<Storefront tracks={secondPage} licenseTiers={[]} catalogPage={filtered} />);
  expect(screen.getByRole('searchbox')).toHaveValue('quiet');
  expect(screen.getByRole('combobox', { name: 'Sort tracks' })).toHaveValue('title');
  // Filtering is on the server: a returned track is never filtered a second time locally.
  expect(screen.getByRole('article', { name: secondPage[0].title })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: secondPage[0].title })).toHaveAttribute('href', window.location.origin + secondPage[0].shareUrl + filtered.currentUrl.slice(1));
  await user.click(screen.getByRole('link', { name: 'Next tracks' }));
  expect(visit).toHaveBeenLastCalledWith('/?cursor=opaque', expect.objectContaining({ preserveState: true, preserveScroll: true }));
  view.rerender(<Storefront tracks={firstPage} licenseTiers={[]} catalogPage={page} />);
  expect(screen.getByRole('searchbox')).toHaveValue('');
  // Finish the simulated visit before submitting a new search.
  act(() => { const options = visit.mock.calls[0][1]; options?.onFinish?.({} as never); });
  await user.type(screen.getByRole('searchbox'), 'piano');
  await user.click(screen.getByRole('button', { name: 'Search', exact: true }));
  expect(visit).toHaveBeenLastCalledWith('/?q=piano&genre=&sort=featured', expect.objectContaining({ preserveState: true }));
});
