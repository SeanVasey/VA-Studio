import { useEffect, useRef, useState } from 'react';

export interface ListeningItem { trackId: string; available: boolean; track?: { title: string; artist: string; href: string } }
export interface ListeningPlaylist { id: string; name: string; tracks: ListeningItem[] }
export interface ListeningNote { trackId: string; body: string }
interface LibraryBase { version: number; favorites: ListeningItem[]; playlists: ListeningPlaylist[] }
type BaseLimits = { favorites: 50; playlists: 10; playlistTracks: 25 };
export type ListeningLibraryData = LibraryBase & ({ listeningSchema: 1; limits: BaseLimits }
  | { listeningSchema: 2; notes: ListeningNote[]; limits: BaseLimits & { notes: 25; noteCharacters: 2000; noteBytes: 4000 } });
export interface ListeningExport { exportSchema: 1; feature: 'customer-listening-library'; version: number; favorites: string[];
  playlists: { id: string; name: string; trackIds: string[] }[]; notes: ListeningNote[] }
type Choice = { trackId: string; title: string; artist: string; href: string };
const record = (v: unknown): v is Record<string, unknown> => !!v && typeof v === 'object' && !Array.isArray(v);
const keys = (v: Record<string, unknown>, names: string[]) => Object.keys(v).length === names.length && names.every(name => Object.hasOwn(v, name));
const trackId = (v: unknown): v is string => typeof v === 'string' && /^[1-9][0-9]{0,17}$/.test(v);
const uuid = (v: unknown): v is string => typeof v === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(v);
const text = (v: unknown, max: number): v is string => typeof v === 'string' && [...v].length >= 1 && [...v].length <= max && !/[\p{Cc}\p{Cf}\ud800-\udfff]/u.test(v);
const trackPath = (v: unknown): v is string => typeof v === 'string' && /^\/tracks\/[a-z0-9]+(?:-[a-z0-9]+)*$/.test(v);
const noteBody = (v: unknown): v is string => typeof v === 'string' && v.trim().length > 0 && [...v].length <= 2000
  && new TextEncoder().encode(v).byteLength <= 4000 && !/(?![\n\t])[\p{Cc}\p{Cf}\ud800-\udfff]/u.test(v);
const idList = (v: unknown, max: number): v is string[] => Array.isArray(v) && v.length <= max && v.every(trackId) && new Set(v).size === v.length;
function notes(v: unknown, references: string[]): v is ListeningNote[] {
  return Array.isArray(v) && v.length <= 25 && v.every(note => record(note) && keys(note, ['trackId', 'body'])
    && trackId(note.trackId) && references.includes(note.trackId) && noteBody(note.body)) && new Set(v.map(note => note.trackId)).size === v.length;
}
function items(v: unknown, max: number): v is ListeningItem[] {
  return Array.isArray(v) && v.length <= max && v.every(item => record(item) && trackId(item.trackId)
    && (item.available === false ? keys(item, ['trackId', 'available']) : item.available === true && keys(item, ['trackId', 'available', 'track'])
      && record(item.track) && keys(item.track, ['title', 'artist', 'href']) && text(item.track.title, 255) && text(item.track.artist, 255) && trackPath(item.track.href)))
    && new Set(v.map(item => item.trackId)).size === v.length;
}
export function validListeningLibrary(v: unknown): v is ListeningLibraryData {
  if (!(record(v) && (v.listeningSchema === 1 || v.listeningSchema === 2)
    && keys(v, v.listeningSchema === 1 ? ['listeningSchema', 'version', 'favorites', 'playlists', 'limits'] : ['listeningSchema', 'version', 'favorites', 'playlists', 'notes', 'limits'])
    && typeof v.version === 'number' && Number.isInteger(v.version) && v.version >= 0 && v.version <= 2147483646
    && items(v.favorites, 50) && Array.isArray(v.playlists) && v.playlists.length <= 10
    && v.playlists.every(list => record(list) && keys(list, ['id', 'name', 'tracks']) && uuid(list.id) && text(list.name, 80) && list.name.trim() === list.name && items(list.tracks, 25))
    && new Set(v.playlists.map(list => list.id)).size === v.playlists.length
    && (v.version > 0 || (v.favorites.length === 0 && v.playlists.length === 0))
    && record(v.limits) && v.limits.favorites === 50 && v.limits.playlists === 10 && v.limits.playlistTracks === 25)) return false;
  if (v.listeningSchema === 1) return keys(v.limits, ['favorites', 'playlists', 'playlistTracks']);
  return keys(v.limits, ['favorites', 'playlists', 'playlistTracks', 'notes', 'noteCharacters', 'noteBytes'])
    && v.limits.notes === 25 && v.limits.noteCharacters === 2000 && v.limits.noteBytes === 4000
    && notes(v.notes, [...v.favorites.map(item => item.trackId), ...v.playlists.flatMap(list => list.tracks.map((item: ListeningItem) => item.trackId))]);
}
export function validListeningExport(v: unknown): v is ListeningExport {
  return record(v) && keys(v, ['exportSchema', 'feature', 'version', 'favorites', 'playlists', 'notes'])
    && v.exportSchema === 1 && v.feature === 'customer-listening-library' && typeof v.version === 'number'
    && Number.isInteger(v.version) && v.version >= 0 && v.version <= 2147483646 && idList(v.favorites, 50)
    && Array.isArray(v.playlists) && v.playlists.length <= 10 && v.playlists.every(list => record(list)
      && keys(list, ['id', 'name', 'trackIds']) && uuid(list.id) && text(list.name, 80) && list.name.trim() === list.name && idList(list.trackIds, 25))
    && new Set(v.playlists.map(list => list.id)).size === v.playlists.length
    && notes(v.notes, [...v.favorites, ...v.playlists.flatMap(list => list.trackIds)])
    && (v.version > 0 || (v.favorites.length === 0 && v.playlists.length === 0 && v.notes.length === 0));
}
function ownExport(library: ListeningLibraryData): ListeningExport {
  return { exportSchema: 1, feature: 'customer-listening-library', version: library.version, favorites: library.favorites.map(item => item.trackId),
    playlists: library.playlists.map(list => ({ id: list.id, name: list.name, trackIds: list.tracks.map(item => item.trackId) })), notes: library.listeningSchema === 2 ? library.notes : [] };
}
function csrfHeaders(): Record<string, string> {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }; } catch { /* Use the current document token. */ } }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}
async function boundedJson(response: Response, signal: AbortSignal, maxBytes: number): Promise<unknown> {
  if (response.status !== 200 || response.redirected || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json' || !response.body) throw new Error('Unavailable');
  const reader = response.body.getReader(), chunks: Uint8Array[] = []; let size = 0;
  const cancel = () => { void reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  try {
    while (true) {
      if (signal.aborted) throw new Error('Cancelled');
      const part = await reader.read(); if (part.done) break;
      size += part.value.byteLength; if (size > maxBytes) throw new Error('Too large'); chunks.push(part.value);
    }
    if (signal.aborted) throw new Error('Cancelled');
    const bytes = new Uint8Array(size); let position = 0;
    for (const chunk of chunks) { bytes.set(chunk, position); position += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}

/** Mounted only behind the server's synthetic account capability and fresh render scope. */
export function CustomerListeningLibrary() {
  const [library, setLibrary] = useState<ListeningLibraryData | null>(null), [choices, setChoices] = useState<Choice[]>([]);
  const [searched, setSearched] = useState(false);
  const [query, setQuery] = useState(''), [newName, setNewName] = useState(''), [rename, setRename] = useState(''), [selected, setSelected] = useState<string | null>(null);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [signIn, setSignIn] = useState(false);
  const [noteTrack, setNoteTrack] = useState<string | null>(null), [draft, setDraft] = useState(''), [clearing, setClearing] = useState(false);
  const downloads = useRef(new Set<string>());
  const active = useRef(false), generation = useRef(0), pending = useRef<AbortController | null>(null), deadline = useRef<number | null>(null);
  const alert = useRef<HTMLParagraphElement>(null);
  const playlist = library?.playlists.find(list => list.id === selected);
  const references = [...new Map([...(library?.favorites ?? []), ...(library?.playlists.flatMap(list => list.tracks) ?? [])].map(item => [item.trackId, item])).values()];
  const existingNote = library?.listeningSchema === 2 ? library.notes.find(note => note.trackId === noteTrack) : undefined;
  function erase() {
    setLibrary(null); setChoices([]); setSearched(false); setSelected(null); setRename(''); setNewName(''); setQuery(''); setNoteTrack(null); setDraft(''); setClearing(false);
    for (const url of downloads.current) URL.revokeObjectURL(url); downloads.current.clear();
  }
  useEffect(() => {
    active.current = true;
    const leave = () => {
      ++generation.current; pending.current?.abort(); pending.current = null;
      if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null;
      erase(); setBusy(false); setMessage(''); setSignIn(false);
    };
    window.addEventListener('pagehide', leave);
    return () => { active.current = false; ++generation.current; pending.current?.abort(); if (deadline.current !== null) window.clearTimeout(deadline.current);
      for (const url of downloads.current) URL.revokeObjectURL(url); downloads.current.clear(); window.removeEventListener('pagehide', leave); };
  }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);

  async function request(kind: 'read' | 'change' | 'search' | 'export', command?: Record<string, unknown>) {
    if (pending.current || signIn || (kind !== 'read' && !library)) return;
    const abort = new AbortController(), current = ++generation.current; pending.current = abort; setBusy(true); setMessage('');
    const owns = () => active.current && current === generation.current && pending.current === abort;
    const fail = (expired = false) => {
      erase(); setSignIn(expired);
      setMessage(expired ? 'Your saved tracks are unavailable. Sign in again to continue.' : kind === 'change'
        ? 'The change could not be confirmed. Reload saved tracks before making another change.' : 'Saved tracks are unavailable. Reload the list to try again.');
    };
    const timer = window.setTimeout(() => {
      if (!owns()) return;
      abort.abort(); ++generation.current; pending.current = null; deadline.current = null; setBusy(false); fail();
    }, 20_000); deadline.current = timer;
    try {
      const path = kind === 'search' ? `/api/catalog?${new URLSearchParams({ q: query })}` : kind === 'export' ? '/account/listening-library/export' : '/account/listening-library';
      const post = kind === 'change' || kind === 'export';
      const response = await fetch(path, { method: post ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
        headers: { Accept: 'application/json', ...(post ? { 'Content-Type': 'application/json', ...csrfHeaders() } : {}) },
        ...(post ? { body: JSON.stringify({ ...command, version: library!.version }) } : {}) });
      if (!owns() || abort.signal.aborted) return;
      if ([401, 403, 419].includes(response.status)) { fail(true); return; }
      const body = await boundedJson(response, abort.signal, kind === 'search' ? 2 * 1024 * 1024 : 3 * 1024 * 1024);
      if (!owns() || abort.signal.aborted) return;
      if (kind === 'search') {
        if (!record(body) || !Array.isArray(body.tracks) || body.tracks.length > 12) throw new Error('Invalid catalog');
        const next: Choice[] = body.tracks.map(track => {
          if (!record(track) || !trackId(track.id) || !text(track.title, 255) || !text(track.artist, 255) || typeof track.slug !== 'string' || !trackPath(`/tracks/${track.slug}`)) throw new Error('Invalid track');
          return { trackId: track.id, title: track.title, artist: track.artist, href: `/tracks/${track.slug}` };
        });
        if (new Set(next.map(track => track.trackId)).size !== next.length) throw new Error('Duplicate track');
        setChoices(next); setSearched(true);
      } else if (kind === 'export') {
        if (!record(body) || !keys(body, ['export']) || !validListeningExport(body.export)) throw new Error('Invalid export');
        const expected = ownExport(library!);
        if (body.export.version !== expected.version || JSON.stringify(body.export.favorites) !== JSON.stringify(expected.favorites)
          || JSON.stringify(body.export.playlists) !== JSON.stringify(expected.playlists) || JSON.stringify(body.export.notes) !== JSON.stringify(expected.notes)) throw new Error('Changed export');
        const url = URL.createObjectURL(new Blob([JSON.stringify(body.export, null, 2)], { type: 'application/json;charset=utf-8' }));
        downloads.current.add(url);
        try {
          if (!owns() || abort.signal.aborted) return;
          const link = document.createElement('a'); link.href = url; link.download = 'vasey-audio-listening-library.json';
          document.body.append(link);
          try { if (owns() && !abort.signal.aborted) link.click(); } finally { link.remove(); }
          if (owns() && !abort.signal.aborted) setMessage('Your private saved tracks, playlists and lyric notes were exported.');
        } finally { URL.revokeObjectURL(url); downloads.current.delete(url); }
      } else {
        if (!record(body) || !keys(body, ['library']) || !validListeningLibrary(body.library)
          || (kind === 'change' && (body.library.version < library!.version || body.library.version > library!.version + 1))) throw new Error('Invalid library');
        const selectedId = kind === 'change' && command?.action === 'create-playlist' ? body.library.playlists.at(-1)?.id : selected;
        const currentPlaylist = body.library.playlists.find(list => list.id === selectedId);
        setLibrary(body.library); setNewName(''); setRename(currentPlaylist?.name ?? ''); setSelected(currentPlaylist?.id ?? null);
        const nextNoteTrack = kind === 'read' ? null : noteTrack;
        const retained = [...body.library.favorites, ...body.library.playlists.flatMap(list => list.tracks)].some(item => item.trackId === nextNoteTrack);
        setNoteTrack(retained ? nextNoteTrack : null); setDraft(retained && body.library.listeningSchema === 2 ? body.library.notes.find(note => note.trackId === nextNoteTrack)?.body ?? '' : ''); setClearing(false);
        if (kind === 'read') { setChoices([]); setSearched(false); }
      }
    } catch { if (owns()) fail(); }
    finally { if (owns()) { window.clearTimeout(timer); deadline.current = null; pending.current = null; setBusy(false); } }
  }
  function change(action: string, fields: Record<string, unknown>) { void request('change', { action, ...fields }); }
  function select(list: ListeningPlaylist) { setSelected(list.id); setRename(list.name); }
  function move(index: number, direction: number) {
    if (!playlist) return;
    const ids = playlist.tracks.map(item => item.trackId); [ids[index], ids[index + direction]] = [ids[index + direction], ids[index]];
    change('reorder-playlist', { playlistId: playlist.id, trackIds: ids });
  }
  function itemTitle(item: ListeningItem) { return item.available ? item.track!.title : 'Unavailable track'; }
  function displayItem(item: ListeningItem) { return item.available ? <><a className="text-link" href={item.track!.href}>{item.track!.title}</a><span> · {item.track!.artist}</span></> : <span>Unavailable track</span>; }
  return <section aria-label="Your saved tracks and playlists" aria-busy={busy}>
    <h2>Saved tracks and playlists</h2>
    <p className="customer-account-note">Private to this test account. Saving a track does not purchase it or grant downloads. A track can become unavailable.</p>
    <button type="button" className="button button-outline" disabled={busy || signIn} onClick={() => void request('read')}>{library ? 'Refresh saved tracks' : 'Open saved tracks'}</button>
    {busy && <p role="status">Updating saved tracks…</p>}
    {message && <p role="alert" tabIndex={-1} ref={alert}>{message}{signIn && <a href="/account/sign-in">Open a fresh sign-in page</a>}</p>}
    {library && <>
      <div aria-label="Saved favorites"><h3>Favorites</h3>
        {library.favorites.length === 0 ? <p>No saved tracks yet.</p> : <ul>{library.favorites.map(item => <li key={item.trackId}>{displayItem(item)}{' '}
          <button type="button" className="button button-outline" disabled={busy} onClick={() => change('remove-saved-track', { trackId: item.trackId })} aria-label={`Remove ${itemTitle(item)} from favorites`}>Remove</button>
          {playlist && item.available && <button type="button" className="button button-outline" disabled={busy || playlist.tracks.some(track => track.trackId === item.trackId)} onClick={() => change('add-playlist-track', { playlistId: playlist.id, trackId: item.trackId })}>Add to {playlist.name}</button>}
        </li>)}</ul>}
      </div>
      <form className="customer-order-lookup-form" onSubmit={event => { event.preventDefault(); change('create-playlist', { name: newName.trim() }); }}>
        <div className="customer-account-field"><label htmlFor="listening-new-playlist">New playlist name</label><input id="listening-new-playlist" value={newName} maxLength={80} disabled={busy} onChange={event => setNewName(event.target.value)} /></div>
        <button type="submit" className="button button-outline" disabled={busy || !newName.trim() || library.playlists.length >= library.limits.playlists}>Create playlist</button>
      </form>
      <div aria-label="Your playlists"><h3>Playlists</h3>{library.playlists.length === 0 ? <p>No playlists yet.</p> : library.playlists.map(list => <button key={list.id} type="button" className="button button-outline" aria-pressed={selected === list.id} disabled={busy} onClick={() => select(list)}>Open playlist {list.name}</button>)}</div>
      {library.listeningSchema === 2 && <div aria-label="Private lyric notes"><h3>Lyric notes</h3>
        <p>Private notes for your saved track references. Removing the last favorite or playlist reference also removes its note. If your library is full, shorten a note or remove older notes.</p>
        {references.length === 0 ? <p>Save a public track or add it to a playlist before adding lyric notes.</p> : <form className="customer-order-lookup-form" onSubmit={event => { event.preventDefault(); if (noteTrack && noteBody(draft)) change('set-track-note', { trackId: noteTrack, body: draft }); }}>
          <div className="customer-account-field"><label htmlFor="listening-note-track">Saved track for lyric notes</label><select id="listening-note-track" value={noteTrack ?? ''} disabled={busy} onChange={event => { setNoteTrack(event.target.value || null); setDraft(library.notes.find(note => note.trackId === event.target.value)?.body ?? ''); }}>
            <option value="">Choose a saved track</option>{references.map(item => <option key={item.trackId} value={item.trackId}>{itemTitle(item)}</option>)}
          </select></div>
          <div className="customer-account-field"><label htmlFor="listening-note-body">Your private lyric note</label><textarea id="listening-note-body" value={draft} maxLength={2000} disabled={busy || !noteTrack} onChange={event => setDraft(event.target.value)} /></div>
          {draft && !noteBody(draft) && <p role="status">Use a shorter plain-text note with valid characters.</p>}
          <button type="submit" className="button button-outline" disabled={busy || !noteTrack || !noteBody(draft) || (!existingNote && library.notes.length >= library.limits.notes)}>Save lyric note</button>
          <button type="button" className="button button-outline" disabled={busy || !existingNote} onClick={() => noteTrack && change('delete-track-note', { trackId: noteTrack })}>Delete lyric note</button>
        </form>}
      </div>}
      <div aria-label="Private saved library controls">
        <button type="button" className="button button-outline" disabled={busy} onClick={() => void request('export')}>Export saved library</button>
        <button type="button" className="button button-outline" disabled={busy} onClick={() => setClearing(true)}>Clear saved tracks, playlists and notes</button>
        {clearing && <div role="group" aria-label="Clear saved library confirmation"><p>Delete your saved tracks, playlists and lyric notes from this account?</p>
          <button type="button" className="button button-outline" disabled={busy} onClick={() => change('clear-library', {})}>Confirm clear</button>
          <button type="button" className="button button-outline" disabled={busy} onClick={() => setClearing(false)}>Keep saved library</button>
        </div>}
      </div>
      {playlist && <div aria-label="Selected playlist"><h3>{playlist.name}</h3>
        <form className="customer-order-lookup-form" onSubmit={event => { event.preventDefault(); change('rename-playlist', { playlistId: playlist.id, name: rename.trim() }); }}>
          <div className="customer-account-field"><label htmlFor="listening-rename-playlist">Rename playlist</label><input id="listening-rename-playlist" value={rename} maxLength={80} disabled={busy} onChange={event => setRename(event.target.value)} /></div>
          <button type="submit" className="button button-outline" disabled={busy || !rename.trim()}>Save playlist name</button>
        </form>
        <button type="button" className="button button-outline" disabled={busy} onClick={() => change('delete-playlist', { playlistId: playlist.id })}>Delete playlist</button>
        {playlist.tracks.length === 0 ? <p>This playlist is empty.</p> : <ol>{playlist.tracks.map((item, index) => <li key={item.trackId}>{displayItem(item)}{' '}
          <button type="button" className="button button-outline" disabled={busy} onClick={() => change('remove-playlist-track', { playlistId: playlist.id, trackId: item.trackId })} aria-label={`Remove ${itemTitle(item)} from playlist`}>Remove</button>
          <button type="button" className="button button-outline" disabled={busy || index === 0} onClick={() => move(index, -1)} aria-label={`Move ${itemTitle(item)} earlier`}>Earlier</button>
          <button type="button" className="button button-outline" disabled={busy || index === playlist.tracks.length - 1} onClick={() => move(index, 1)} aria-label={`Move ${itemTitle(item)} later`}>Later</button>
        </li>)}</ol>}
      </div>}
      <form className="customer-order-lookup-form" onSubmit={event => { event.preventDefault(); void request('search'); }}>
        <div className="customer-account-field"><label htmlFor="listening-search">Find public tracks</label><input id="listening-search" type="search" value={query} maxLength={100} disabled={busy} onChange={event => setQuery(event.target.value)} /></div>
        <button type="submit" className="button button-outline" disabled={busy}>Search tracks</button>
      </form>
      {searched && choices.length === 0 && <p role="status">No public tracks match this search.</p>}
      {choices.length > 0 && <ul aria-label="Public track choices">{choices.map(choice => <li key={choice.trackId}><a href={choice.href}>{choice.title}</a> · {choice.artist}{' '}
        <button type="button" className="button button-outline" disabled={busy || library.favorites.length >= library.limits.favorites || library.favorites.some(item => item.trackId === choice.trackId)} onClick={() => change('save-track', { trackId: choice.trackId })}>Save {choice.title}</button>
        {playlist && <button type="button" className="button button-outline" disabled={busy || playlist.tracks.length >= library.limits.playlistTracks || playlist.tracks.some(item => item.trackId === choice.trackId)} onClick={() => change('add-playlist-track', { playlistId: playlist.id, trackId: choice.trackId })}>Add {choice.title} to playlist</button>}
      </li>)}</ul>}
    </>}
  </section>;
}
