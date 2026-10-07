import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomerListeningLibrary, validListeningLibrary, type ListeningLibraryData } from '../../resources/js/components/CustomerListeningLibrary';

const playlistId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const first = { trackId: '1', available: true, track: { title: 'First recording', artist: 'Synthetic artist', href: '/tracks/first-recording' } };
const second = { trackId: '2', available: true, track: { title: 'Second recording', artist: 'Synthetic artist', href: '/tracks/second-recording' } };
const empty = (version = 0): ListeningLibraryData => ({ listeningSchema: 1, version, favorites: [], playlists: [], limits: { favorites: 50, playlists: 10, playlistTracks: 25 } });
const listed = (version = 1, tracks = [first, second]): ListeningLibraryData => ({ ...empty(version), playlists: [{ id: playlistId, name: 'Writing session', tracks }] });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const libraryResponse = (library: unknown, status = 200) => response({ library }, status);
async function open() { fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await screen.findByRole('button', { name: 'Refresh saved tracks' }); }
const command = (fetcher: ReturnType<typeof vi.spyOn>, index = 1) => JSON.parse(String(fetcher.mock.calls[index][1]?.body));
beforeEach(() => { const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'synthetic-document-token'; document.head.append(meta); });
afterEach(() => { vi.useRealTimers(); document.querySelectorAll('meta[name="csrf-token"]').forEach(meta => meta.remove()); document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; });

describe('private saved listening projection', () => {
  it('accepts bounded exact available and unavailable references without private metadata', () => {
    expect(validListeningLibrary(empty())).toBe(true);
    expect(validListeningLibrary({ ...listed(), favorites: [{ trackId: '3', available: false }] })).toBe(true);
  });
  it.each([
    { ...empty(), privateAccountId: 42 }, { ...empty(), version: -1 }, { ...empty(), favorites: [first] },
    { ...empty(1), favorites: [first, first] }, { ...empty(1), favorites: [{ trackId: '3', available: false, track: first.track }] },
    { ...empty(1), favorites: [{ ...first, track: { ...first.track, href: '//foreign.example/private' } }] },
    { ...empty(1), favorites: [{ ...first, track: { ...first.track, previewUrl: '/private/master' } }] },
    { ...listed(), playlists: [{ id: playlistId, name: 'Private\nname', tracks: [] }] },
    { ...listed(), playlists: [{ id: playlistId, name: 'List', tracks: Array.from({ length: 26 }, (_, i) => ({ trackId: String(i + 1), available: false })) }] },
  ])('rejects malformed, over-bound or privately extended projections', value => expect(validListeningLibrary(value)).toBe(false));
});

describe('saved tracks and named playlists', () => {
  it('loads deliberately with private requests and persists a named playlist using a version and current CSRF cookie', async () => {
    document.cookie = 'XSRF-TOKEN=synthetic%2Bcookie%3D; path=/';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(empty())).mockResolvedValueOnce(libraryResponse({ ...empty(1), playlists: [{ id: playlistId, name: 'Private new name', tracks: [] }] }));
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    render(<CustomerListeningLibrary />); expect(fetcher).not.toHaveBeenCalled(); await open();
    expect(fetcher).toHaveBeenNthCalledWith(1, '/account/listening-library', expect.objectContaining({ method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: expect.any(AbortSignal), headers: { Accept: 'application/json' } }));
    fireEvent.change(screen.getByLabelText('New playlist name'), { target: { value: 'Private new name' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create playlist' }));
    await screen.findByRole('button', { name: 'Open playlist Private new name' });
    expect(command(fetcher)).toEqual({ action: 'create-playlist', name: 'Private new name', version: 0 });
    expect(fetcher.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'synthetic+cookie=' } }));
    expect(storage).not.toHaveBeenCalled();
  });
  it('searches real public choices and saves a chosen track without making a client-side availability claim', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(empty()))
      .mockResolvedValueOnce(response({ tracks: [{ id: '1', title: 'First recording', artist: 'Synthetic artist', slug: 'first-recording', previewUrl: '/media/public-preview' }] }))
      .mockResolvedValueOnce(libraryResponse({ ...empty(1), favorites: [first] }));
    render(<CustomerListeningLibrary />); await open();
    fireEvent.change(screen.getByLabelText('Find public tracks'), { target: { value: 'First & recording' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search tracks' })); await screen.findByRole('button', { name: 'Save First recording' });
    expect(fetcher.mock.calls[1][0]).toBe('/api/catalog?q=First+%26+recording');
    fireEvent.click(screen.getByRole('button', { name: 'Save First recording' }));
    await screen.findByRole('button', { name: 'Remove First recording from favorites' });
    expect(command(fetcher, 2)).toEqual({ action: 'save-track', trackId: '1', version: 0 });
    expect(screen.queryByRole('audio')).not.toBeInTheDocument();
  });
  it('renames, reorders, removes and deletes a selected playlist with the current server revision', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(listed(1)))
      .mockResolvedValueOnce(libraryResponse({ ...listed(2), playlists: [{ ...listed().playlists[0], name: 'Renamed private list' }] }))
      .mockResolvedValueOnce(libraryResponse({ ...listed(3, [second, first]), playlists: [{ ...listed(3, [second, first]).playlists[0], name: 'Renamed private list' }] }))
      .mockResolvedValueOnce(libraryResponse({ ...listed(4, [first]), playlists: [{ ...listed(4, [first]).playlists[0], name: 'Renamed private list' }] }))
      .mockResolvedValueOnce(libraryResponse(empty(5)));
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Open playlist Writing session' }));
    fireEvent.change(screen.getByLabelText('Rename playlist'), { target: { value: 'Renamed private list' } }); fireEvent.click(screen.getByRole('button', { name: 'Save playlist name' }));
    await screen.findByRole('button', { name: 'Open playlist Renamed private list' });
    expect(command(fetcher)).toEqual({ action: 'rename-playlist', playlistId, name: 'Renamed private list', version: 1 });
    fireEvent.click(screen.getByRole('button', { name: 'Move Second recording earlier' }));
    await waitFor(() => expect(within(screen.getByLabelText('Selected playlist')).getAllByRole('link')[0]).toHaveTextContent('Second recording'));
    expect(command(fetcher, 2)).toEqual({ action: 'reorder-playlist', playlistId, trackIds: ['2', '1'], version: 2 });
    fireEvent.click(screen.getByRole('button', { name: 'Remove Second recording from playlist' }));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Remove Second recording from playlist' })).not.toBeInTheDocument());
    expect(command(fetcher, 3)).toEqual({ action: 'remove-playlist-track', playlistId, trackId: '2', version: 3 });
    fireEvent.click(screen.getByRole('button', { name: 'Delete playlist' })); await screen.findByText('No playlists yet.');
    expect(command(fetcher, 4)).toEqual({ action: 'delete-playlist', playlistId, version: 4 });
  });
  it('shows unavailable references without stale title, URL or preview and still lets the owner remove them', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse({ ...empty(1), favorites: [{ trackId: '3', available: false }] })).mockResolvedValueOnce(libraryResponse(empty(2)));
    render(<CustomerListeningLibrary />); await open();
    const favorites = screen.getByLabelText('Saved favorites'); expect(within(favorites).getByText('Unavailable track')).toBeInTheDocument();
    expect(within(favorites).queryByRole('link')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Remove Unavailable track from favorites' })); await screen.findByText('No saved tracks yet.');
    expect(command(fetcher)).toEqual({ action: 'remove-saved-track', trackId: '3', version: 1 });
  });
  it('adds a public choice to the selected playlist while preserving its saved order', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(listed(1, [first])))
      .mockResolvedValueOnce(response({ tracks: [{ id: '2', title: 'Second recording', artist: 'Synthetic artist', slug: 'second-recording' }] }))
      .mockResolvedValueOnce(libraryResponse(listed(2)));
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Open playlist Writing session' }));
    fireEvent.click(screen.getByRole('button', { name: 'Search tracks' })); await screen.findByRole('button', { name: 'Add Second recording to playlist' });
    fireEvent.click(screen.getByRole('button', { name: 'Add Second recording to playlist' }));
    await screen.findByRole('button', { name: 'Remove Second recording from playlist' });
    expect(command(fetcher, 2)).toEqual({ action: 'add-playlist-track', playlistId, trackId: '2', version: 1 });
    expect(within(screen.getByLabelText('Selected playlist')).getAllByRole('link').map(link => link.textContent)).toEqual(['First recording', 'Second recording']);
  });
  it.each([409, 422, 503, 'lost response'])('requires a fresh GET after %s and never retries the previous mutation', async status => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(empty()));
    if (status === 'lost response') fetcher.mockRejectedValueOnce(new Error('PRIVATE server detail'));
    else fetcher.mockResolvedValueOnce(response({ message: 'PRIVATE server detail' }, Number(status)));
    fetcher.mockResolvedValueOnce(libraryResponse({ ...empty(1), playlists: [{ id: playlistId, name: 'Actually committed', tracks: [] }] }));
    render(<CustomerListeningLibrary />); await open();
    fireEvent.change(screen.getByLabelText('New playlist name'), { target: { value: 'Uncertain save' } }); fireEvent.click(screen.getByRole('button', { name: 'Create playlist' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The change could not be confirmed'); expect(screen.getByRole('alert')).toHaveFocus();
    expect(screen.queryByLabelText('New playlist name')).not.toBeInTheDocument(); expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await screen.findByRole('button', { name: 'Open playlist Actually committed' });
    expect(fetcher.mock.calls.map(([, options]) => options?.method)).toEqual(['GET', 'POST', 'GET']);
  });
  it('clears private data and requires a new sign-in after access refusal', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(listed())).mockResolvedValueOnce(response({}, 403));
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Refresh saved tracks' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Sign in again');
    expect(screen.queryByText('Writing session')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Open saved tracks' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
  });
  it('bounds streamed bytes and clears state after a malformed private envelope', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response(' '.repeat(512 * 1024 + 1), { headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(response({ library: empty(), private: 'PRIVATE' }));
    render(<CustomerListeningLibrary />); fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    expect(screen.queryByLabelText('New playlist name')).not.toBeInTheDocument();
  });
  it('prevents double submission and ignores a late response after unmount', async () => {
    let resolve!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(empty())).mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    const view = render(<CustomerListeningLibrary />); await open(); fireEvent.change(screen.getByLabelText('New playlist name'), { target: { value: 'Private' } });
    const form = screen.getByRole('button', { name: 'Create playlist' }).closest('form')!; fireEvent.submit(form); fireEvent.submit(form);
    expect(fetcher).toHaveBeenCalledTimes(2); view.unmount(); expect(fetcher.mock.calls[1][1]?.signal?.aborted).toBe(true);
    await act(async () => { resolve(libraryResponse(listed())); }); expect(screen.queryByText('Writing session')).not.toBeInTheDocument();
  });
  it('expires a stalled mutation and ignores its late response before a fresh read', async () => {
    let resolve!: (value: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(empty())).mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    render(<CustomerListeningLibrary />); await open(); vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText('New playlist name'), { target: { value: 'Private' } }); fireEvent.click(screen.getByRole('button', { name: 'Create playlist' }));
    await act(async () => { vi.advanceTimersByTime(20_000); });
    expect(screen.getByRole('alert')).toHaveTextContent('The change could not be confirmed');
    await act(async () => { resolve(libraryResponse(listed())); }); expect(screen.queryByRole('button', { name: 'Open playlist Writing session' })).not.toBeInTheDocument();
  });
  it('clears names and loaded preferences on pagehide without persisting them in browser storage', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(libraryResponse(listed())); const storage = vi.spyOn(Storage.prototype, 'setItem');
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Open playlist Writing session' }));
    act(() => window.dispatchEvent(new Event('pagehide')));
    expect(screen.queryByText('Writing session')).not.toBeInTheDocument(); expect(screen.queryByLabelText('Rename playlist')).not.toBeInTheDocument(); expect(storage).not.toHaveBeenCalled();
  });
});
