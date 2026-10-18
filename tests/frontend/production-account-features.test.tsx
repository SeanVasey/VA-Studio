import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import ProductionCustomerFeatures from '../../resources/js/Pages/ProductionCustomerFeatures';
import { boundedFeatureJson, libraryEnvelope, preferencesEnvelope, exportEnvelope } from '../../resources/js/lib/production-account-features';

vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { on: () => () => {} } }));
const library = { listeningSchema: 1, version: 0, favorites: [], playlists: [], limits: { favorites: 50, playlists: 10, playlistTracks: 25 } };
const unknown = { schema: 1, purposes: [{ purpose: 'email_marketing', version: 0, status: 'unknown', notice: null, canGrant: false, suppression: { status: 'not_requested' } }] };
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); });

it('sign-out aborts a pending feature request and clears private state immediately', async () => {
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library }))
    .mockImplementationOnce(() => new Promise(() => {})).mockResolvedValueOnce(response({ code: 'unavailable' }, 503));
  render(<ProductionCustomerFeatures scope="opaque-sign-out" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  await screen.findByRole('heading', { name: 'Saved tracks' });
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  expect(screen.getByRole('button', { name: 'Sign out' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
  expect(fetch.mock.calls[1][1]!.signal!.aborted).toBe(true);
  expect(screen.queryByRole('heading', { name: 'Saved tracks' })).not.toBeInTheDocument();
  expect(fetch.mock.calls[2][0]).toBe('/customer/sign-out');
});

it('locally ends an abort-ignoring public search and rejects its late choices', async () => {
  let finish!: (response: Response) => void;
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library }))
    .mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
  render(<ProductionCustomerFeatures scope="opaque-search" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  await screen.findByRole('heading', { name: 'Saved tracks' });
  vi.useFakeTimers();
  fireEvent.change(screen.getByLabelText('Find public tracks'), { target: { value: 'Synthetic track' } });
  fireEvent.submit(screen.getByRole('button', { name: 'Search public tracks' }).closest('form')!);
  await act(async () => { vi.advanceTimersByTime(20000); });
  expect(fetch.mock.calls[1][1]!.signal!.aborted).toBe(true);
  expect(screen.getByRole('button', { name: 'Reload listening library' })).toBeEnabled();
  await act(async () => finish(response({ tracks: [{ id: '1', title: 'Synthetic late choice', artist: 'Synthetic', slug: 'synthetic-track' }] })));
  expect(screen.queryByRole('option', { name: 'Synthetic late choice — Synthetic' })).not.toBeInTheDocument();
});

it('uses explicit uninitialized envelopes and a dedicated closed-empty initialization without automatic writes', async () => {
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: false })).mockResolvedValueOnce(response({ initialized: true, library }));
  render(<ProductionCustomerFeatures scope="opaque-render-one" />);
  expect(fetch).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  expect(await screen.findByText('Your library has not been initialized.')).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Initialize listening library' }));
  expect(await screen.findByRole('heading', { name: 'Saved tracks' })).toBeInTheDocument();
  expect(fetch.mock.calls[1][0]).toBe('/customer/features/listening/initialize');
  expect(fetch.mock.calls[1][1]?.body).toBe('{}');
  expect(fetch.mock.calls[1][1]?.credentials).toBe('same-origin');
  expect(fetch.mock.calls[1][1]?.redirect).toBe('error');
  expect(screen.getByText('Private notes are unavailable for this library.')).toBeInTheDocument();
});

it('keeps unknown consent unknown and disables grants while preserving explicit withdrawal', async () => {
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, preferences: unknown }));
  render(<ProductionCustomerFeatures scope="opaque-consent" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload communication preferences' }));
  expect(await screen.findByText('Marketing choice: unknown')).toBeInTheDocument();
  expect(screen.getByText('Marketing opt-in is currently unavailable.')).toBeInTheDocument();
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Withdraw marketing consent' })).toBeEnabled();
  expect(fetch).toHaveBeenCalledTimes(1);
});

it('requires fresh read after an uncertain version conflict and never resends the mutation', async () => {
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library }))
    .mockResolvedValueOnce(response({ code: 'ACCOUNT_FEATURE_UNAVAILABLE' }, 409)).mockResolvedValueOnce(response({ initialized: true, library: { ...library, version: 1 } }));
  render(<ProductionCustomerFeatures scope="opaque-conflict" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  await screen.findByRole('button', { name: 'Create playlist' });
  fireEvent.change(screen.getByLabelText('Playlist name'), { target: { value: 'Synthetic private idea' } });
  fireEvent.submit(screen.getByRole('button', { name: 'Create playlist' }).closest('form')!);
  expect(await screen.findByText('The result could not be confirmed. Reload before making another change.')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Create playlist' })).not.toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(2);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  // The fresh read restores the form with a cleared name, which the blank-name guard keeps disabled until a name is typed.
  expect(await screen.findByRole('button', { name: 'Create playlist' })).toBeDisabled();
  expect(screen.getByLabelText('Playlist name')).toBeEnabled();
  fireEvent.change(screen.getByLabelText('Playlist name'), { target: { value: 'Synthetic next idea' } });
  expect(screen.getByRole('button', { name: 'Create playlist' })).toBeEnabled();
  expect(fetch).toHaveBeenCalledTimes(3);
  expect(JSON.parse(fetch.mock.calls[1][1]!.body as string)).toEqual({ action: 'create-playlist', name: 'Synthetic private idea', version: 0 });
});

it('clears private text and controls on page departure, aborts and rejects a late response', async () => {
  let finish!: (response: Response) => void;
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library: { ...library, version: 1, favorites: [{ trackId: '1', available: false }] } }))
    .mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
  render(<ProductionCustomerFeatures scope="opaque-exit" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  await screen.findByText('Unavailable track');
  fireEvent.change(screen.getByLabelText('Playlist name'), { target: { value: 'Private unsaved name' } });
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  fireEvent(window, new Event('pagehide'));
  expect(fetch.mock.calls[1][1]!.signal!.aborted).toBe(true);
  expect(screen.queryByDisplayValue('Private unsaved name')).not.toBeInTheDocument();
  await act(async () => finish(response({ initialized: true, library })));
  expect(screen.queryByRole('heading', { name: 'Saved tracks' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Reload listening library' })).toBeDisabled();
});

it('rejects the old pending projection when the opaque render scope changes', async () => {
  let finish!: (response: Response) => void;
  const fetch = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
  const view = render(<ProductionCustomerFeatures scope="original-opaque" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  view.rerender(<ProductionCustomerFeatures scope="next-opaque" />);
  expect(fetch.mock.calls[0][1]!.signal!.aborted).toBe(true);
  await act(async () => finish(response({ initialized: true, library })));
  expect(screen.queryByRole('heading', { name: 'Saved tracks' })).not.toBeInTheDocument();
});

it('revokes own-version minimized export URLs on page exit and ignores duplicate emission state', async () => {
  const create = vi.fn(() => 'blob:synthetic-own-export'), revoke = vi.fn();
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke }));
  const value = { exportSchema: 1, feature: 'customer-listening-library', version: 0, favorites: [], playlists: [], notes: [] };
  vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library })).mockResolvedValueOnce(response({ export: value }));
  render(<ProductionCustomerFeatures scope="opaque-export" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Prepare own library export' }));
  expect(await screen.findByRole('link', { name: 'Download library export' })).toHaveAttribute('href', 'blob:synthetic-own-export');
  fireEvent(window, new Event('pagehide'));
  expect(revoke).toHaveBeenCalledWith('blob:synthetic-own-export');
  expect(screen.queryByRole('link', { name: 'Download library export' })).not.toBeInTheDocument();
});

it('validates exact production envelopes and refuses legacy or provider-confirmed suppression shapes', () => {
  expect(libraryEnvelope({ initialized: false })).toBe(true);
  expect(libraryEnvelope(library)).toBe(false);
  expect(libraryEnvelope({ initialized: false, library })).toBe(false);
  expect(libraryEnvelope({ initialized: true, library })).toBe(true);
  expect(preferencesEnvelope({ initialized: true, preferences: unknown })).toBe(true);
  expect(preferencesEnvelope({ initialized: true, preferences: { ...unknown, purposes: [{ ...unknown.purposes[0], version: 1, status: 'withdrawn', suppression: { status: 'confirmed' } }] } })).toBe(false);
  expect(exportEnvelope({ export: { exportSchema: 1, feature: 'customer-listening-library', version: 0, favorites: [], playlists: [], notes: [], accountId: 1 } })).toBe(false);
});

it('bounds UTF-8 wire bytes and refuses malformed encoding before projections enter state', async () => {
  const signal = new AbortController().signal;
  await expect(boundedFeatureJson(new Response(new Uint8Array([0xff]), { headers: { 'Content-Type': 'application/json' } }), signal, 100)).rejects.toThrow();
  await expect(boundedFeatureJson(response({ text: 'é'.repeat(100) }), signal, 128)).rejects.toThrow('Too large');
  await expect(boundedFeatureJson(response({ initialized: false }), signal, 100)).resolves.toEqual({ initialized: false });
});

it('ends a timed-out mutation attempt and accepts only a fresh read before another change', async () => {
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library })).mockImplementationOnce(() => new Promise(() => {}));
  render(<ProductionCustomerFeatures scope="opaque-timeout" />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  await screen.findByRole('button', { name: 'Clear listening library' });
  vi.useFakeTimers();
  fireEvent.click(screen.getByRole('button', { name: 'Clear listening library' }));
  await act(async () => { vi.advanceTimersByTime(20000); });
  expect(fetch.mock.calls[1][1]!.signal!.aborted).toBe(true);
  expect(screen.queryByRole('button', { name: 'Clear listening library' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Reload listening library' })).toBeEnabled();
  expect(fetch).toHaveBeenCalledTimes(2);
});

// Create-playlist guard: a UX convenience only. The server (ProductionListeningState) stays authoritative, supplies the playlist
// limit but no name-length rule, and refuses empty, padded and over-capacity writes. The client treats any failed write as uncertain
// and clears the library, so avoidable invalid submissions must never leave the page.
const blankHint = 'Enter a playlist name to create a playlist.';
const limitHint = `You have reached the limit of ${library.limits.playlists} playlists. Delete a playlist to create another.`;
const uncertainMessage = 'The result could not be confirmed. Reload before making another change.';
const playlistsOf = (count: number) => Array.from({ length: count }, (_, index) => ({ id: `00000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`, name: `Synthetic list ${index + 1}`, tracks: [] }));
async function openCreateForm(scope: string, count: number) {
  const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ initialized: true, library: { ...library, version: count, playlists: playlistsOf(count) } }));
  render(<ProductionCustomerFeatures scope={scope} />);
  fireEvent.click(screen.getByRole('button', { name: 'Reload listening library' }));
  await screen.findByRole('heading', { name: 'Saved tracks' });
  return { fetch, input: screen.getByLabelText('Playlist name'), create: screen.getByRole('button', { name: 'Create playlist' }) };
}
function expectNoRequestAndLibraryKept(fetch: { mock: { calls: unknown[] } }) {
  expect(fetch.mock.calls).toHaveLength(1);
  expect(screen.getByRole('heading', { name: 'Saved tracks' })).toBeInTheDocument();
  expect(screen.queryByText(uncertainMessage)).not.toBeInTheDocument();
}

it.each([['empty', ''], ['whitespace-only', '   '], ['tab and space only', ' \t ']])('disables playlist creation and explains why for a %s name, sending nothing', async (kind, value) => {
  const { fetch, input, create } = await openCreateForm('opaque-create-blank-' + kind, 0);
  fireEvent.change(input, { target: { value } });
  expect(create).toBeDisabled();
  expect(create).not.toHaveAttribute('aria-disabled');
  expect(input).toBeEnabled();
  expect(screen.getByText(blankHint)).toBeVisible();
  expect(input).toHaveAccessibleDescription(blankHint);
  expect(create).toHaveAccessibleDescription(blankHint);
  fireEvent.click(create);
  fireEvent.submit(create.closest('form')!);
  expectNoRequestAndLibraryKept(fetch);
});

it.each([['a valid name', 'Synthetic mix', 'Synthetic mix'], ['a valid name with surrounding spaces, sent trimmed', '  Synthetic mix  ', 'Synthetic mix']])('enables playlist creation for %s and sends one request', async (kind, value, sent) => {
  const { fetch, input, create } = await openCreateForm('opaque-create-valid-' + kind, 0);
  fetch.mockResolvedValueOnce(response({ initialized: true, library: { ...library, version: 1, playlists: [{ id: '00000000-0000-4000-8000-000000000001', name: sent, tracks: [] }] } }));
  fireEvent.change(input, { target: { value } });
  expect(create).toBeEnabled();
  expect(screen.queryByText(blankHint)).not.toBeInTheDocument();
  expect(screen.queryByText(limitHint)).not.toBeInTheDocument();
  expect(input).not.toHaveAttribute('aria-describedby');
  expect(create).not.toHaveAttribute('aria-describedby');
  fireEvent.click(create);
  expect(await screen.findByRole('heading', { name: sent, level: 4 })).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(2);
  expect(fetch.mock.calls[1][0]).toBe('/customer/features/listening/change');
  expect(JSON.parse(fetch.mock.calls[1][1]!.body as string)).toEqual({ action: 'create-playlist', name: sent, version: 0 });
});

it('re-enables creation as soon as a blank name becomes valid and withdraws the explanation', async () => {
  const { input, create } = await openCreateForm('opaque-create-recover', 0);
  fireEvent.change(input, { target: { value: '   ' } });
  expect(create).toBeDisabled();
  fireEvent.change(input, { target: { value: 'Synthetic mix' } });
  expect(create).toBeEnabled();
  expect(screen.queryByText(blankHint)).not.toBeInTheDocument();
  fireEvent.change(input, { target: { value: '' } });
  expect(create).toBeDisabled();
  expect(screen.getByText(blankHint)).toBeInTheDocument();
});

it('keeps creation available one below the supplied playlist limit', async () => {
  const count = library.limits.playlists - 1;
  const { fetch, input, create } = await openCreateForm('opaque-create-below-limit', count);
  fetch.mockResolvedValueOnce(response({ initialized: true, library: { ...library, version: count + 1, playlists: playlistsOf(count + 1) } }));
  fireEvent.change(input, { target: { value: 'Synthetic last slot' } });
  expect(create).toBeEnabled();
  expect(screen.queryByText(limitHint)).not.toBeInTheDocument();
  fireEvent.click(create);
  expect(await screen.findByText(limitHint)).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(2);
  expect(JSON.parse(fetch.mock.calls[1][1]!.body as string)).toEqual({ action: 'create-playlist', name: 'Synthetic last slot', version: count });
});

it('disables creation at the supplied playlist limit even for a valid name, explains it and sends nothing', async () => {
  const { fetch, input, create } = await openCreateForm('opaque-create-at-limit', library.limits.playlists);
  fireEvent.change(input, { target: { value: 'Synthetic one too many' } });
  expect(create).toBeDisabled();
  expect(create).not.toHaveAttribute('aria-disabled');
  expect(screen.getByText(limitHint)).toBeVisible();
  expect(screen.queryByText(blankHint)).not.toBeInTheDocument();
  expect(input).toHaveAccessibleDescription(limitHint);
  expect(create).toHaveAccessibleDescription(limitHint);
  fireEvent.click(create);
  fireEvent.submit(create.closest('form')!);
  expectNoRequestAndLibraryKept(fetch);
});

it('explains the capacity limit rather than the blank name when both apply', async () => {
  const { fetch, input, create } = await openCreateForm('opaque-create-limit-and-blank', library.limits.playlists);
  fireEvent.change(input, { target: { value: '  ' } });
  expect(create).toBeDisabled();
  expect(create).toHaveAccessibleDescription(limitHint);
  expect(screen.queryByText(blankHint)).not.toBeInTheDocument();
  fireEvent.submit(create.closest('form')!);
  expectNoRequestAndLibraryKept(fetch);
});

it('keeps the keyboard path: Enter never sends a blank or padded-blank name and creates once for a valid name', async () => {
  const user = userEvent.setup();
  const { fetch, input } = await openCreateForm('opaque-create-keyboard', 0);
  fetch.mockResolvedValueOnce(response({ initialized: true, library: { ...library, version: 1, playlists: [{ id: '00000000-0000-4000-8000-000000000001', name: 'Synthetic keyboard mix', tracks: [] }] } }));
  await user.click(input);
  await user.keyboard('{Enter}');
  await user.keyboard('   {Enter}');
  expect(input).toHaveFocus();
  expectNoRequestAndLibraryKept(fetch);
  await user.clear(input);
  await user.keyboard('Synthetic keyboard mix{Enter}');
  expect(await screen.findByRole('heading', { name: 'Synthetic keyboard mix', level: 4 })).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(2);
  expect(JSON.parse(fetch.mock.calls[1][1]!.body as string)).toEqual({ action: 'create-playlist', name: 'Synthetic keyboard mix', version: 0 });
});
