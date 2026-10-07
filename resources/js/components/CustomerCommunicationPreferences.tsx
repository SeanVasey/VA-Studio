import { useEffect, useRef, useState } from 'react';

export interface CommunicationPurpose { purpose: 'email_marketing'; version: number; status: 'unknown' | 'granted' | 'withdrawn';
  notice: null | { version: string; hash: string; text: string }; canGrant: boolean }
export interface CommunicationPreferences { schema: 1; purposes: [CommunicationPurpose] }
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, names: string[]) => Object.keys(value).length === names.length && names.every(name => Object.hasOwn(value, name));
const noticeText = (value: unknown): value is string => typeof value === 'string' && value.trim().length > 0 && [...value].length <= 2000
  && new TextEncoder().encode(value).byteLength <= 8000 && !/(?![\n\t])[\p{Cc}\p{Cf}\ud800-\udfff]/u.test(value);
export function validCommunicationPreferences(value: unknown): value is CommunicationPreferences {
  if (!record(value) || !keys(value, ['schema', 'purposes']) || value.schema !== 1 || !Array.isArray(value.purposes) || value.purposes.length !== 1) return false;
  const purpose = value.purposes[0];
  if (!record(purpose) || !keys(purpose, ['purpose', 'version', 'status', 'notice', 'canGrant']) || purpose.purpose !== 'email_marketing'
    || typeof purpose.version !== 'number' || !Number.isInteger(purpose.version) || purpose.version < 0 || purpose.version > 2147483646
    || typeof purpose.status !== 'string' || !['unknown', 'granted', 'withdrawn'].includes(purpose.status) || typeof purpose.canGrant !== 'boolean'
    || (purpose.version === 0 && purpose.status !== 'unknown')) return false;
  if (purpose.notice !== null && !(record(purpose.notice) && keys(purpose.notice, ['version', 'hash', 'text'])
    && typeof purpose.notice.version === 'string' && /^[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}$/.test(purpose.notice.version)
    && typeof purpose.notice.hash === 'string' && /^[a-f0-9]{64}$/.test(purpose.notice.hash) && noticeText(purpose.notice.text))) return false;
  return (!purpose.canGrant || purpose.notice !== null) && (purpose.status !== 'granted' || (purpose.notice !== null && purpose.canGrant));
}
function csrfHeaders(): Record<string, string> {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }; } catch { /* Fall through to the current document token. */ } }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}
async function boundedJson(response: Response, signal: AbortSignal): Promise<unknown> {
  if (response.status !== 200 || response.redirected || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json' || !response.body) throw new Error('Unavailable');
  const reader = response.body.getReader(), chunks: Uint8Array[] = []; let size = 0;
  const cancel = () => { void reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  try {
    while (true) {
      if (signal.aborted) throw new Error('Cancelled');
      const part = await reader.read(); if (part.done) break;
      size += part.value.byteLength; if (size > 32768) throw new Error('Too large'); chunks.push(part.value);
    }
    if (signal.aborted) throw new Error('Cancelled');
    const bytes = new Uint8Array(size); let at = 0;
    for (const chunk of chunks) { bytes.set(chunk, at); at += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}

/** The root mounts this with a fresh opaque authenticated session scope, never an account ID. */
export function CustomerCommunicationPreferences({ scope }: { scope: string }) {
  const [loaded, setLoaded] = useState<{ scope: string; preferences: CommunicationPreferences } | null>(null);
  const [affirmative, setAffirmative] = useState(false), [busy, setBusy] = useState(false), [expired, setExpired] = useState(false);
  const [message, setMessage] = useState<{ scope: string; text: string } | null>(null);
  const active = useRef(false), currentScope = useRef(scope), generation = useRef(0), pending = useRef<AbortController | null>(null), deadline = useRef<number | null>(null);
  const alert = useRef<HTMLParagraphElement>(null);
  currentScope.current = scope;
  const preferences = loaded?.scope === scope ? loaded.preferences : null, purpose = preferences?.purposes[0];
  const currentMessage = message?.scope === scope ? message.text : '';
  useEffect(() => {
    active.current = true; setLoaded(null); setAffirmative(false); setBusy(false); setExpired(false); setMessage(null);
    const leave = () => { ++generation.current; pending.current?.abort(); pending.current = null;
      if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null;
      setLoaded(null); setAffirmative(false); setBusy(false); setExpired(false); setMessage(null); };
    window.addEventListener('pagehide', leave);
    return () => { active.current = false; ++generation.current; pending.current?.abort(); pending.current = null;
      if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null; window.removeEventListener('pagehide', leave); };
  }, [scope]);
  useEffect(() => { if (currentMessage) alert.current?.focus(); }, [currentMessage]);

  async function request(action?: 'grant-consent' | 'withdraw-consent') {
    if (pending.current || expired || (action && !purpose) || (action === 'grant-consent' && (!affirmative || !purpose?.canGrant || !purpose.notice))) return;
    const abort = new AbortController(), current = ++generation.current, requestScope = scope;
    pending.current = abort; setBusy(true); setMessage(null);
    const owns = () => active.current && currentScope.current === requestScope && generation.current === current && pending.current === abort;
    const fail = (auth = false) => { setLoaded(null); setAffirmative(false); setExpired(auth);
      setMessage({ scope: requestScope, text: auth ? 'Communication preferences are unavailable. Sign in again to continue.'
        : action ? 'Your choice could not be confirmed. Reload communication preferences before making another choice.'
          : 'Communication preferences are unavailable. Reload to try again.' }); };
    const timer = window.setTimeout(() => { if (!owns()) return; abort.abort(); ++generation.current; pending.current = null; deadline.current = null; setBusy(false); fail(); }, 20000);
    deadline.current = timer;
    try {
      const command = action ? { action, version: purpose!.version, purpose: 'email_marketing', ...(action === 'grant-consent'
        ? { noticeVersion: purpose!.notice!.version, noticeHash: purpose!.notice!.hash, affirmative: true } : {}) } : null;
      const response = await fetch('/account/communication-preferences', { method: action ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
        headers: { Accept: 'application/json', ...(action ? { 'Content-Type': 'application/json', ...csrfHeaders() } : {}) }, ...(action ? { body: JSON.stringify(command) } : {}) });
      if (!owns() || abort.signal.aborted) return;
      if ([401, 403, 419].includes(response.status)) { fail(true); return; }
      const body = await boundedJson(response, abort.signal);
      if (!owns() || abort.signal.aborted) return;
      if (!record(body) || !keys(body, ['preferences']) || !validCommunicationPreferences(body.preferences)) throw new Error('Invalid preferences');
      const next = body.preferences.purposes[0];
      if (action && (next.version !== purpose!.version + 1 || next.status !== (action === 'grant-consent' ? 'granted' : 'withdrawn')
        || (action === 'grant-consent' && JSON.stringify(next.notice) !== JSON.stringify(purpose!.notice)))) throw new Error('Unconfirmed choice');
      setLoaded({ scope: requestScope, preferences: body.preferences }); setAffirmative(false);
      if (action) setMessage({ scope: requestScope, text: action === 'grant-consent' ? 'Your opt-in choice was saved.' : 'Your withdrawn choice was saved.' });
    } catch { if (owns()) fail(); }
    finally { if (owns()) { window.clearTimeout(timer); deadline.current = null; pending.current = null; setBusy(false); } }
  }

  return <section aria-label="Your communication preferences" aria-busy={busy}>
    <h2>Communication preferences</h2>
    <button type="button" className="button button-outline" disabled={busy || expired} onClick={() => void request()}>{preferences ? 'Refresh communication preferences' : 'Open communication preferences'}</button>
    {busy && <p role="status">Updating communication preferences…</p>}
    {currentMessage && <p role="alert" tabIndex={-1} ref={alert}>{currentMessage}{expired && <a href="/account/sign-in">Open a fresh sign-in page</a>}</p>}
    {purpose && <div aria-label="Email marketing preference"><h3>Email marketing</h3>
      <p>{purpose.status === 'unknown' ? 'Current consent is unknown.' : purpose.status === 'granted' ? 'Your saved choice is opt in.' : 'Your saved choice is withdrawn.'}</p>
      {purpose.notice && <div aria-label="Current email marketing notice"><p style={{ whiteSpace: 'pre-wrap' }}>{purpose.notice.text}</p><p>Notice version: {purpose.notice.version}</p></div>}
      {purpose.canGrant && purpose.notice ? <form onSubmit={event => { event.preventDefault(); void request('grant-consent'); }}>
        <label><input type="checkbox" checked={affirmative} disabled={busy} onChange={event => setAffirmative(event.target.checked)} /> I choose to opt in to email marketing under the notice above.</label>
        <button type="submit" className="button button-outline" disabled={busy || !affirmative || purpose.version >= 2147483646}>Save opt-in choice</button>
      </form> : <p>Opt in is currently unavailable.</p>}
      <button type="button" className="button button-outline" disabled={busy || purpose.version >= 2147483646} onClick={() => void request('withdraw-consent')}>Withdraw email marketing consent</button>
    </div>}
  </section>;
}
