import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { featureRequest, libraryEnvelope, preferencesEnvelope, exportEnvelope, publicFeatureChoices, type PublicChoice, type LibraryEnvelope, type PreferencesEnvelope } from '../lib/production-account-features';
import { productionIdentityRequest } from '../lib/production-customer-identity';
import type { ListeningItem } from '../components/CustomerListeningLibrary';
import '../../css/customer-account.css';
import '../../css/production-customer-features.css';

export default function ProductionCustomerFeatures({ scope }: { scope: string }) {
  return <ProductionFeatureJourney key={scope} scope={scope} />;
}

function ProductionFeatureJourney({ scope }: { scope: string }) {
  const [library, setLibrary] = useState<{ scope: string; value: LibraryEnvelope } | null>(null);
  const [preferences, setPreferences] = useState<{ scope: string; value: PreferencesEnvelope } | null>(null);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [closed, setClosed] = useState(false);
  const [name, setName] = useState(''), [track, setTrack] = useState(''), [affirmative, setAffirmative] = useState(false);
  const [query, setQuery] = useState(''), [choices, setChoices] = useState<PublicChoice[]>([]), [noteTrack, setNoteTrack] = useState(''), [note, setNote] = useState('');
  const [uncertain, setUncertain] = useState({ listening: false, preferences: false });
  const [download, setDownload] = useState<string | null>(null);
  const generation = useRef(0), active = useRef(false), currentScope = useRef(scope), pending = useRef<AbortController | null>(null);
  const url = useRef<string | null>(null), container = useRef<HTMLElement>(null), timer = useRef<number | null>(null), alert = useRef<HTMLParagraphElement>(null);
  currentScope.current = scope;
  const lib = library?.scope === scope ? library.value : null, pref = preferences?.scope === scope ? preferences.value : null;
  const data = lib?.initialized ? lib.library : null, purpose = pref?.initialized ? pref.preferences.purposes[0] : null;
  function revoke() { if (url.current) URL.revokeObjectURL(url.current); url.current = null; setDownload(null); }
  function erase(render: boolean) {
    active.current = false; ++generation.current; pending.current?.abort(); pending.current = null;
    if (timer.current !== null) window.clearTimeout(timer.current); timer.current = null;
    if (url.current) URL.revokeObjectURL(url.current); url.current = null;
    container.current?.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('input,textarea,select').forEach(input => { input.value = ''; if (input instanceof HTMLInputElement) input.checked = false; });
    container.current?.querySelectorAll<HTMLElement>('[data-private]').forEach(node => { node.textContent = ''; });
    if (render) { setLibrary(null); setPreferences(null); setName(''); setTrack(''); setQuery(''); setChoices([]); setNoteTrack(''); setNote(''); setAffirmative(false); setDownload(null); setBusy(false); setClosed(true); setMessage('This page is closed. Open a fresh account page to continue.'); }
  }
  useEffect(() => {
    active.current = true; setLibrary(null); setPreferences(null); setName(''); setTrack(''); setQuery(''); setChoices([]); setNoteTrack(''); setNote(''); setAffirmative(false); setDownload(null); setClosed(false); setBusy(false); setMessage(''); setUncertain({ listening: false, preferences: false });
    const leave = () => erase(true);
    const restore = (event: PageTransitionEvent) => { if (event.persisted) erase(true); };
    window.addEventListener('pagehide', leave); window.addEventListener('pageshow', restore);
    const remove = router.on('before', leave);
    return () => { erase(false); window.removeEventListener('pagehide', leave); window.removeEventListener('pageshow', restore); remove(); };
  }, [scope]);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);
  async function request(feature: 'listening' | 'preferences', action: 'read' | 'initialize' | 'change' | 'export', command: Record<string, unknown> | null = null) {
    if (!active.current || closed || pending.current || (action !== 'read' && uncertain[feature])) return;
    const originalScope = scope, token = ++generation.current, abort = new AbortController(); pending.current = abort;
    const owns = () => active.current && currentScope.current === originalScope && generation.current === token && pending.current === abort;
    setBusy(true); setMessage(''); revoke(); setAffirmative(false);
    if (action !== 'read') setUncertain(old => ({ ...old, [feature]: true }));
    const deadline = window.setTimeout(() => {
      if (!owns()) return; abort.abort(); ++generation.current; pending.current = null; timer.current = null; setBusy(false);
      if (feature === 'listening') setLibrary(null); else setPreferences(null);
      setMessage(action === 'read' ? 'This account feature is unavailable. Reload to try again.' : 'The result could not be confirmed. Reload before making another change.');
    }, 20000); timer.current = deadline;
    try {
      const result = await featureRequest(feature, action, command, abort.signal);
      if (!owns() || abort.signal.aborted) return;
      if (action === 'export') {
        if (!exportEnvelope(result) || !data || result.export.version !== data.version
          || JSON.stringify(result.export.favorites) !== JSON.stringify(data.favorites.map(item => item.trackId))
          || JSON.stringify(result.export.playlists) !== JSON.stringify(data.playlists.map(list => ({ id: list.id, name: list.name, trackIds: list.tracks.map(item => item.trackId) })))
          || JSON.stringify(result.export.notes) !== JSON.stringify(data.listeningSchema === 2 ? data.notes : [])) throw new Error('Unavailable');
        url.current = URL.createObjectURL(new Blob([JSON.stringify(result.export)], { type: 'application/json' })); setDownload(url.current);
      } else if (feature === 'listening') {
        if (!libraryEnvelope(result)) throw new Error('Unavailable'); setLibrary({ scope: originalScope, value: result });
      } else {
        if (!preferencesEnvelope(result)) throw new Error('Unavailable'); setPreferences({ scope: originalScope, value: result });
      }
      setUncertain(old => ({ ...old, [feature]: false })); setName(''); setTrack(''); setNote(''); setNoteTrack('');
    } catch {
      if (!owns()) return;
      if (feature === 'listening') setLibrary(null); else setPreferences(null);
      setMessage(action === 'read' ? 'This account feature is unavailable. Reload to try again.' : 'The result could not be confirmed. Reload before making another change.');
    } finally {
      window.clearTimeout(deadline);
      if (owns()) { pending.current = null; timer.current = null; setBusy(false); }
    }
  }
  const change = (command: Record<string, unknown>) => { if (data) void request('listening', 'change', { ...command, version: data.version }); };
  const locked = busy || closed || uncertain.listening;
  const item = (value: ListeningItem) => value.available ? <a data-private href={value.track!.href} onClick={() => erase(true)}>{value.track!.title} — {value.track!.artist}</a> : <span>Unavailable track</span>;
  async function search() {
    if (!active.current || closed || pending.current) return;
    const token = ++generation.current, originalScope = scope, abort = new AbortController(); pending.current = abort; setBusy(true); setChoices([]);
    const timeout = window.setTimeout(() => abort.abort(), 20000); timer.current = timeout;
    const owns = () => active.current && currentScope.current === originalScope && token === generation.current && pending.current === abort;
    try { const values = await publicFeatureChoices(query, abort.signal); if (owns() && !abort.signal.aborted) setChoices(values); }
    catch { if (owns()) setMessage('Public track search is unavailable.'); }
    finally { window.clearTimeout(timeout); if (owns()) { pending.current = null; timer.current = null; setBusy(false); } }
  }
  async function signOut() {
    if (pending.current) return;
    erase(true);
    const abort = new AbortController(), timeout = window.setTimeout(() => abort.abort(), 20000);
    try { if (await productionIdentityRequest('sign-out', '{}', abort.signal) === 'saved') window.location.assign('/customer/sign-in'); }
    finally { window.clearTimeout(timeout); }
  }
  return <><Head title="Your listening and preferences"><meta name="robots" content="noindex, nofollow" /><meta name="referrer" content="no-referrer" /></Head>
    <a className="skip-link" href="#main">Skip to content</a><header className="section-pad"><a href="/" onClick={() => erase(true)}>VASEY.AUDIO</a></header>
    <main id="main" ref={container} className="customer-account production-customer-features section-pad"><h1>Your listening and preferences</h1>
      <p ref={alert} role="status" tabIndex={-1}>{message}</p>
      <section aria-labelledby="listening-title"><h2 id="listening-title">Listening library</h2>
        <button className="button" disabled={busy || closed} onClick={() => void request('listening', 'read')}>Reload listening library</button>
        {lib?.initialized === false && <><p>Your library has not been initialized.</p><button className="button" disabled={locked} onClick={() => void request('listening', 'initialize', {})}>Initialize listening library</button></>}
        {data && <><h3>Saved tracks</h3><ul>{data.favorites.map(value => <li key={value.trackId}>{item(value)} <button className="button" disabled={locked} onClick={() => change({ action: 'remove-saved-track', trackId: value.trackId })}>Remove saved track</button></li>)}</ul>
          <form className="customer-order-lookup-form" onSubmit={event => { event.preventDefault(); void search(); }}><label className="customer-account-field">Find public tracks<input type="search" value={query} maxLength={100} disabled={busy || closed} onChange={event => setQuery(event.target.value)} /></label><button className="button" disabled={busy || closed}>Search public tracks</button></form>
          <label className="customer-account-field">Selected public track<select disabled={locked} value={track} onChange={event => setTrack(event.target.value)}><option value="">Choose a track</option>{choices.map(choice => <option key={choice.trackId} value={choice.trackId}>{choice.title} — {choice.artist}</option>)}</select></label>
          <button className="button" disabled={locked || !track} onClick={() => change({ action: 'save-track', trackId: track })}>Save selected track</button>
          <form onSubmit={event => { event.preventDefault(); change({ action: 'create-playlist', name }); }}><label className="customer-account-field">Playlist name<input value={name} maxLength={80} disabled={locked} onChange={event => setName(event.target.value)} /></label><button className="button" disabled={locked}>Create playlist</button></form>
          <h3>Playlists</h3>{data.playlists.map(list => <article key={list.id}><h4 data-private>{list.name}</h4><ul>{list.tracks.map(value => <li key={value.trackId}>{item(value)} <button className="button" disabled={locked} onClick={() => change({ action: 'remove-playlist-track', playlistId: list.id, trackId: value.trackId })}>Remove from playlist</button></li>)}</ul>
            <button className="button" disabled={locked || !track} onClick={() => change({ action: 'add-playlist-track', playlistId: list.id, trackId: track })}>Add selected track to playlist</button>
            <button className="button" disabled={locked || !name} onClick={() => change({ action: 'rename-playlist', playlistId: list.id, name })}>Rename playlist</button>
            <button className="button" disabled={locked} onClick={() => change({ action: 'delete-playlist', playlistId: list.id })}>Delete playlist</button></article>)}
          {data.listeningSchema === 2 ? <><h3>Private notes</h3>{data.notes.map(note => <p key={note.trackId}><span data-private>{note.body}</span><button className="button" disabled={locked} onClick={() => change({ action: 'delete-track-note', trackId: note.trackId })}>Delete note</button></p>)}
            <form onSubmit={event => { event.preventDefault(); change({ action: 'set-track-note', trackId: noteTrack, body: note }); }}><label className="customer-account-field">Note track<select value={noteTrack} disabled={locked} onChange={event => setNoteTrack(event.target.value)}><option value="">Choose a saved track</option>{[...new Map([...data.favorites, ...data.playlists.flatMap(list => list.tracks)].map(value => [value.trackId, value])).values()].map(value => <option key={value.trackId} value={value.trackId}>{value.available ? value.track!.title : 'Unavailable track'}</option>)}</select></label>
              <label className="customer-account-field">Private note<textarea value={note} disabled={locked} maxLength={2000} onChange={event => setNote(event.target.value)} /></label><button className="button" disabled={locked || !noteTrack || !note.trim()}>Save private note</button></form></> : <p>Private notes are unavailable for this library.</p>}
          <button className="button" disabled={locked} onClick={() => change({ action: 'clear-library' })}>Clear listening library</button>
          <button className="button" disabled={locked} onClick={() => void request('listening', 'export', { version: data.version })}>Prepare own library export</button>
          {download && <a href={download} download="listening-library.json">Download library export</a>}</>}
      </section>
      <section aria-labelledby="preferences-title"><h2 id="preferences-title">Communication preferences</h2>
        <button className="button" disabled={busy || closed} onClick={() => void request('preferences', 'read')}>Reload communication preferences</button>
        {pref?.initialized === false && <><p>Your preferences have not been initialized. No marketing choice is inferred.</p><button className="button" disabled={busy || closed || uncertain.preferences} onClick={() => void request('preferences', 'initialize', {})}>Initialize communication preferences</button></>}
        {purpose && <><p>Marketing choice: {purpose.status}</p>{purpose.notice && <p>{purpose.notice.text}</p>}
          {purpose.canGrant && purpose.notice ? <><label className="customer-account-field"><input type="checkbox" checked={affirmative} disabled={busy || closed || uncertain.preferences} onChange={event => setAffirmative(event.target.checked)} />I choose to receive marketing email under this notice.</label>
            <button className="button" disabled={!affirmative || busy || closed || uncertain.preferences} onClick={() => void request('preferences', 'change', { action: 'grant-consent', version: purpose.version, purpose: 'email_marketing', noticeVersion: purpose.notice!.version, noticeHash: purpose.notice!.hash, affirmative: true })}>Save marketing choice</button></> : <p>Marketing opt-in is currently unavailable.</p>}
          <button className="button" disabled={busy || closed || uncertain.preferences} onClick={() => void request('preferences', 'change', { action: 'withdraw-consent', version: purpose.version, purpose: 'email_marketing' })}>Withdraw marketing consent</button>
          {purpose.suppression.status === 'pending' && <p>Suppression is pending. Provider completion has not been confirmed.</p>}</>}
      </section><button className="button" disabled={busy || closed} onClick={() => void signOut()}>Sign out</button>
    </main></>;
}
