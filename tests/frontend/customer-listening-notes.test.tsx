import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomerListeningLibrary, validListeningExport, validListeningLibrary, type ListeningExport, type ListeningLibraryData, type ListeningNote } from '../../resources/js/components/CustomerListeningLibrary';

const available = { trackId: '1', available: true, track: { title: 'Public recording', artist: 'Public artist', href: '/tracks/public-recording' } };
const unavailable = { trackId: '2', available: false };
const listId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
type V2 = Extract<ListeningLibraryData, { listeningSchema: 2 }>;
const library = (version = 1, notes: ListeningNote[] = []): V2 => ({ listeningSchema: 2, version, favorites: [available, unavailable],
  playlists: [{ id: listId, name: 'PRIVATE playlist', tracks: [unavailable] }], notes,
  limits: { favorites: 50, playlists: 10, playlistTracks: 25, notes: 25, noteCharacters: 2000, noteBytes: 4000 } });
const exported = (data: V2): ListeningExport => ({ exportSchema: 1, feature: 'customer-listening-library', version: data.version,
  favorites: data.favorites.map(item => item.trackId), playlists: data.playlists.map(list => ({ id: list.id, name: list.name, trackIds: list.tracks.map(item => item.trackId) })), notes: data.notes });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const loaded = (data: unknown) => response({ library: data });
async function open() { fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await screen.findByRole('button', { name: 'Refresh saved tracks' }); }
const command = (fetcher: ReturnType<typeof vi.spyOn>, index = 1) => JSON.parse(String(fetcher.mock.calls[index][1]?.body));
let blobs: Blob[];
let clicked: { href: string; filename: string }[];
const RealURL = globalThis.URL;
beforeEach(() => {
  blobs = []; clicked = [];
  vi.stubGlobal('URL', class extends RealURL {
    static createObjectURL = vi.fn((blob: Blob) => { blobs.push(blob); return 'blob:synthetic-private-feature'; });
    static revokeObjectURL = vi.fn();
  });
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { clicked.push({ href: this.href, filename: this.download }); });
  const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'synthetic-note-token'; document.head.append(meta);
});
afterEach(() => { vi.unstubAllGlobals(); document.querySelectorAll('meta[name="csrf-token"]').forEach(meta => meta.remove()); });

async function blobText(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(reader.error); reader.readAsText(blob); });
}

describe('V2 private listening inputs', () => {
  it('accepts exact V1/V2 projections and exports owned unavailable references without catalog data', () => {
    expect(validListeningLibrary(library())).toBe(true);
    const data = library(2, [{ trackId: '2', body: 'PRIVATE verse\n\tsecond line 🎵' }]);
    expect(validListeningExport(exported(data))).toBe(true);
    expect(validListeningLibrary(data)).toBe(true);
  });
  it.each([
    { ...library(), notes: [{ trackId: '3', body: 'PRIVATE unowned' }] },
    { ...library(), notes: [{ trackId: '1', body: 'PRIVATE', title: 'Hidden metadata' }] },
    { ...library(), notes: [{ trackId: '1', body: 'PRIVATE' }, { trackId: '1', body: 'Duplicate' }] },
    { ...library(), notes: [{ trackId: '1', body: 'a'.repeat(2001) }] },
    { ...library(), notes: [{ trackId: '1', body: '🎵'.repeat(1001) }] },
    { ...library(), notes: [{ trackId: '1', body: 'PRIVATE\u202Ebad' }] },
    { ...library(), notes: [{ trackId: '1', body: 'PRIVATE\r\nline' }] },
    { ...library(), limits: { ...library().limits, noteBytes: 5000 } },
    { ...library(), ownerKey: 'PRIVATE account evidence' },
  ])('rejects malformed or over-bound private projections', value => expect(validListeningLibrary(value)).toBe(false));
  it.each([
    { ...exported(library()), accountId: 42 },
    { ...exported(library()), feature: 'purchased-rights' },
    { ...exported(library()), favorites: [available] },
    { ...exported(library()), notes: [{ trackId: '3', body: 'PRIVATE unowned' }] },
    { ...exported(library()), playlists: [{ id: listId, name: 'List', trackIds: ['1', '1'] }] },
    { ...exported(library()), playlists: [{ id: listId, name: 'List', trackIds: [], href: '/private/master' }] },
  ])('rejects an export extended with identifiers, metadata or invalid references', value => expect(validListeningExport(value)).toBe(false));
});

describe('actual private note and data controls', () => {
  it('saves, edits and deletes a private note on an unavailable owned reference with fresh versions', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(library()))
      .mockResolvedValueOnce(loaded(library(2, [{ trackId: '2', body: 'PRIVATE verse\nLine two' }])))
      .mockResolvedValueOnce(loaded(library(3, [{ trackId: '2', body: 'PRIVATE revised verse' }])))
      .mockResolvedValueOnce(loaded(library(4)));
    render(<CustomerListeningLibrary />); await open();
    fireEvent.change(screen.getByLabelText('Saved track for lyric notes'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Your private lyric note'), { target: { value: 'PRIVATE verse\nLine two' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save lyric note' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Delete lyric note' })).toBeEnabled());
    expect(command(fetcher)).toEqual({ action: 'set-track-note', trackId: '2', body: 'PRIVATE verse\nLine two', version: 1 });
    expect(fetcher.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'synthetic-note-token' }) }));
    fireEvent.change(screen.getByLabelText('Your private lyric note'), { target: { value: 'PRIVATE revised verse' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save lyric note' }));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save lyric note' })).toBeEnabled());
    expect(command(fetcher, 2)).toEqual({ action: 'set-track-note', trackId: '2', body: 'PRIVATE revised verse', version: 2 });
    fireEvent.click(screen.getByRole('button', { name: 'Delete lyric note' }));
    await waitFor(() => expect(screen.getByLabelText('Your private lyric note')).toHaveValue(''));
    expect(command(fetcher, 3)).toEqual({ action: 'delete-track-note', trackId: '2', version: 3 });
    expect(screen.queryByRole('link', { name: /Unavailable/ })).not.toBeInTheDocument();
  });
  it('exports the actual current own inputs to a fixed-name private JSON blob and releases its URL', async () => {
    const data = library(2, [{ trackId: '2', body: 'PRIVATE verse\nLine two' }]);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(data)).mockResolvedValueOnce(response({ export: exported(data) }));
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Export saved library' }));
    await screen.findByText('Your private saved tracks, playlists and lyric notes were exported.');
    expect(fetcher.mock.calls[1][0]).toBe('/account/listening-library/export'); expect(command(fetcher)).toEqual({ version: 2 });
    expect(fetcher.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'synthetic-note-token' }) }));
    expect(clicked).toEqual([{ href: 'blob:synthetic-private-feature', filename: 'vasey-audio-listening-library.json' }]);
    expect(JSON.parse(await blobText(blobs[0]))).toEqual(exported(data));
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:synthetic-private-feature');
    expect(document.querySelector('a[download]')).toBeNull();
  });
  it('requires an explicit clear choice and sends the current version, then removes all private drafts', async () => {
    const data = library(2, [{ trackId: '2', body: 'PRIVATE lyric' }]);
    const empty: V2 = { ...library(3), favorites: [], playlists: [], notes: [] };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(data)).mockResolvedValueOnce(loaded(empty));
    render(<CustomerListeningLibrary />); await open(); fireEvent.change(screen.getByLabelText('Saved track for lyric notes'), { target: { value: '2' } });
    expect(screen.getByLabelText('Your private lyric note')).toHaveValue('PRIVATE lyric');
    fireEvent.click(screen.getByRole('button', { name: 'Clear saved tracks, playlists and notes' })); expect(fetcher).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Keep saved library' })); expect(screen.queryByRole('button', { name: 'Confirm clear' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Clear saved tracks, playlists and notes' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm clear' }));
    await screen.findByText('No saved tracks yet.'); expect(command(fetcher)).toEqual({ action: 'clear-library', version: 2 });
    expect(screen.queryByLabelText('Your private lyric note')).not.toBeInTheDocument(); expect(screen.queryByText('PRIVATE lyric')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Confirm clear' })).not.toBeInTheDocument();
  });
  it('requires a fresh GET after an uncertain note mutation and never retries the note automatically', async () => {
    const data = library();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(data)).mockRejectedValueOnce(new Error('PRIVATE transport details'))
      .mockResolvedValueOnce(loaded(library(2, [{ trackId: '2', body: 'PRIVATE actually committed' }])));
    render(<CustomerListeningLibrary />); await open(); fireEvent.change(screen.getByLabelText('Saved track for lyric notes'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Your private lyric note'), { target: { value: 'PRIVATE uncertain intent' } }); fireEvent.click(screen.getByRole('button', { name: 'Save lyric note' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The change could not be confirmed'); expect(screen.queryByLabelText('Your private lyric note')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await screen.findByLabelText('Saved track for lyric notes');
    fireEvent.change(screen.getByLabelText('Saved track for lyric notes'), { target: { value: '2' } }); expect(screen.getByLabelText('Your private lyric note')).toHaveValue('PRIVATE actually committed');
    expect(fetcher.mock.calls.map(([, options]) => options?.method)).toEqual(['GET', 'POST', 'GET']);
  });
  it.each([409, 503, 'mismatched contents', 'extended metadata'])('refuses %s export and never downloads uncertain private data', async mode => {
    const data = library(2, [{ trackId: '2', body: 'PRIVATE own note' }]);
    const bad = mode === 'mismatched contents' ? { ...exported(data), notes: [{ trackId: '2', body: 'PRIVATE other account note' }] } : { ...exported(data), email: 'PRIVATE account metadata' };
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(data)).mockResolvedValueOnce(typeof mode === 'number' ? response({}, mode) : response({ export: bad }));
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Export saved library' }));
    await screen.findByRole('alert'); expect(URL.createObjectURL).not.toHaveBeenCalled(); expect(clicked).toEqual([]); expect(screen.queryByLabelText('Saved track for lyric notes')).not.toBeInTheDocument();
    expect(screen.queryByText('PRIVATE other account note')).not.toBeInTheDocument();
  });
  it('clears private notes after revoked authority and never begins the export download', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(library(2, [{ trackId: '2', body: 'PRIVATE lyric' }]))).mockResolvedValueOnce(response({}, 403));
    render(<CustomerListeningLibrary />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Export saved library' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Sign in again'); expect(clicked).toEqual([]); expect(screen.queryByLabelText('Your private lyric note')).not.toBeInTheDocument();
  });
  it('ignores a late export after pagehide and clears the private note and confirmation', async () => {
    const data = library(2, [{ trackId: '2', body: 'PRIVATE lyric' }]); let resolve!: (value: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(data)).mockImplementationOnce(() => new Promise<Response>(done => { resolve = done; }));
    render(<CustomerListeningLibrary />); await open(); fireEvent.change(screen.getByLabelText('Saved track for lyric notes'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Clear saved tracks, playlists and notes' })); fireEvent.click(screen.getByRole('button', { name: 'Export saved library' }));
    fireEvent(window, new Event('pagehide')); await act(async () => { resolve(response({ export: exported(data) })); });
    expect(clicked).toEqual([]); expect(URL.createObjectURL).not.toHaveBeenCalled(); expect(screen.queryByLabelText('Your private lyric note')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Confirm clear' })).not.toBeInTheDocument();
  });
});
