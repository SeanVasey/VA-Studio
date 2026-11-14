import { validListeningLibrary, validListeningExport, type ListeningLibraryData, type ListeningExport } from '../components/CustomerListeningLibrary';
import { validCommunicationPreferences, type CommunicationPreferences } from '../components/CustomerCommunicationPreferences';

export type LibraryEnvelope = { initialized: false } | { initialized: true; library: ListeningLibraryData };
export type PreferencesEnvelope = { initialized: false } | { initialized: true; preferences: CommunicationPreferences };
const record = (v: unknown): v is Record<string, unknown> => !!v && typeof v === 'object' && !Array.isArray(v);
const keys = (v: Record<string, unknown>, names: string[]) => Object.keys(v).length === names.length && names.every(name => Object.hasOwn(v, name));
export function libraryEnvelope(v: unknown): v is LibraryEnvelope {
  return record(v) && (v.initialized === false ? keys(v, ['initialized'])
    : v.initialized === true && keys(v, ['initialized', 'library']) && validListeningLibrary(v.library));
}
export function preferencesEnvelope(v: unknown): v is PreferencesEnvelope {
  return record(v) && (v.initialized === false ? keys(v, ['initialized']) : v.initialized === true && keys(v, ['initialized', 'preferences'])
    && validCommunicationPreferences(v.preferences) && ['pending', 'not_requested'].includes(v.preferences.purposes[0].suppression.status));
}
export function exportEnvelope(v: unknown): v is { export: ListeningExport } {
  return record(v) && keys(v, ['export']) && validListeningExport(v.export);
}
export type PublicChoice = { trackId: string; title: string; artist: string; href: string };
export async function publicFeatureChoices(query: string, signal: AbortSignal): Promise<PublicChoice[]> {
  const response = await fetch('/api/catalog?' + new URLSearchParams({ q: query }), { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal, headers: { Accept: 'application/json' } });
  const body = await boundedFeatureJson(response, signal, 2 * 1024 * 1024);
  if (!record(body) || !Array.isArray(body.tracks) || body.tracks.length > 12) throw new Error('Unavailable');
  const choices = body.tracks.map(track => {
    if (!record(track) || typeof track.id !== 'string' || !/^[1-9][0-9]{0,17}$/.test(track.id)
      || typeof track.title !== 'string' || track.title.length > 255 || typeof track.artist !== 'string' || track.artist.length > 255
      || typeof track.slug !== 'string' || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(track.slug)) throw new Error('Unavailable');
    return { trackId: track.id, title: track.title, artist: track.artist, href: '/tracks/' + track.slug };
  });
  if (new Set(choices.map(choice => choice.trackId)).size !== choices.length) throw new Error('Unavailable');
  return choices;
}
function csrf(): Record<string, string> {
  const cookie = document.cookie.split(';').map(v => v.trim()).find(v => v.startsWith('XSRF-TOKEN='));
  if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }; } catch { /* Current document token below. */ } }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}
export async function featureRequest(feature: 'listening' | 'preferences', action: 'read' | 'initialize' | 'change' | 'export',
  body: Record<string, unknown> | null, signal: AbortSignal): Promise<unknown> {
  const response = await fetch('/customer/features/' + feature + (action === 'read' ? '' : '/' + action), {
    method: action === 'read' ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
    headers: { Accept: 'application/json', ...(action === 'read' ? {} : { 'Content-Type': 'application/json', ...csrf() }) },
    ...(action === 'read' ? {} : { body: JSON.stringify(body) }),
  });
  return boundedFeatureJson(response, signal, feature === 'listening' ? 3 * 1024 * 1024 : 65536);
}
export async function boundedFeatureJson(response: Response, signal: AbortSignal, maximum: number): Promise<unknown> {
  if (response.status !== 200 || response.redirected || !response.body
    || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') throw new Error('Unavailable');
  const reader = response.body.getReader(), chunks: Uint8Array[] = []; let size = 0;
  const cancel = () => { void reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  try {
    while (true) {
      if (signal.aborted) throw new Error('Cancelled');
      const next = await reader.read(); if (next.done) break;
      size += next.value.byteLength; if (size > maximum) throw new Error('Too large'); chunks.push(next.value);
    }
    if (signal.aborted) throw new Error('Cancelled');
    const bytes = new Uint8Array(size); let position = 0;
    for (const chunk of chunks) { bytes.set(chunk, position); position += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}
