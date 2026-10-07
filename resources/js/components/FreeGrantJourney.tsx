import { useEffect, useRef, useState } from 'react';

export interface FreeLicense { licenseVersionId: string; name: string; version: number; type: string; features: string[]; deliverableRoles: string[]; termsText: string }
export interface FreeDefinition {
  id: string; definitionHash: string; reviewHash: string | null; version: number; open: boolean; title: string; purpose: 'free-license-grant';
  assentText: string; termsReference: string; license: FreeLicense; product: { id: number; slug: string; title: string; artist: string }; testOnly: true;
  maxOrigins: number; maxDownloads: number; tokenTtlSeconds: number; assets: { id: number; role: string; sha256: string; mime_type: string; size_bytes: number }[];
}
export interface FreeOrigin {
  id: string; definitionId: string; title: string; originHash: string; purpose: 'free-license-grant'; declaredName: string; acceptedAt: string;
  assentText: string; license: FreeLicense; collection: 'none'; testOnly: true; documentStatus: 'pending' | 'claimed' | 'failed' | 'complete';
  renderAttempts: number; maxDownloads: number; files: { kind: string; sha256: string; sizeBytes: number; mimeType: string }[];
}
interface Listing { schemaVersion: 1; definitions: FreeDefinition[]; origins: FreeOrigin[]; definitionLimit: 50; originLimit: 20 }
interface DownloadStatus { schemaVersion: 1; originId: string; attemptCount: number; maxDownloads: number; historyLimit: 20; history: { id: string; kind: string; issuedAt: string; expiresAt: string; status: 'attempted' | 'unused' | 'expired'; attemptedAt: string | null }[]; renderRetryAllowed: boolean; renderRetryAfter: string | null }
interface Review { definition: FreeDefinition; declaredName: string; assentHash: string }
interface Pending { path: string; body: Record<string, unknown>; kind: 'accept' | 'authorize'; origin?: FreeOrigin }
interface Authorization { id: string; token: string; expiresAt: string; kind: string; filename: string; mimeType: string }
const obj = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object' && !Array.isArray(x);
const exact = (x: Record<string, unknown>, keys: string[]) => Object.keys(x).length === keys.length && keys.every(k => Object.hasOwn(x, k));
const uuid = (x: unknown): x is string => typeof x === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(x);
const hash = (x: unknown): x is string => typeof x === 'string' && /^[a-f0-9]{64}$/.test(x);
const text = (x: unknown, max = 131072): x is string => typeof x === 'string' && x.length > 0 && x.length <= max;
const int = (x: unknown, min: number, max: number) => Number.isSafeInteger(x) && (x as number) >= min && (x as number) <= max;
const role = (x: unknown): x is 'contract' | 'master_wav' | 'download_mp3' => ['contract', 'master_wav', 'download_mp3'].includes(String(x));
const media = { contract: ['.pdf', 'application/pdf'], master_wav: ['.wav', 'audio/wav'], download_mp3: ['.mp3', 'audio/mpeg'] } as const;
function license(x: unknown): x is FreeLicense {
  return obj(x) && exact(x, ['licenseVersionId', 'name', 'version', 'type', 'features', 'deliverableRoles', 'termsText'])
    && text(x.licenseVersionId, 20) && text(x.name, 192) && int(x.version, 1, 100000) && x.type === 'non-exclusive'
    && Array.isArray(x.features) && x.features.length <= 100 && x.features.every(t => text(t, 8192))
    && Array.isArray(x.deliverableRoles) && x.deliverableRoles.length >= 1 && x.deliverableRoles.length <= 2 && x.deliverableRoles.every(r => role(r) && r !== 'contract') && text(x.termsText);
}
export function validFreeDefinition(x: unknown): x is FreeDefinition {
  return obj(x) && exact(x, ['id', 'definitionHash', 'reviewHash', 'version', 'open', 'title', 'purpose', 'assentText', 'termsReference', 'license', 'product', 'testOnly', 'maxOrigins', 'maxDownloads', 'tokenTtlSeconds', 'assets'])
    && uuid(x.id) && hash(x.definitionHash) && (x.reviewHash === null || hash(x.reviewHash)) && int(x.version, 0, 1000) && typeof x.open === 'boolean' && (!x.open || x.reviewHash !== null && (x.version as number) > 0)
    && text(x.title, 160) && x.purpose === 'free-license-grant' && text(x.assentText, 8192) && text(x.termsReference, 192) && license(x.license) && x.testOnly === true
    && obj(x.product) && exact(x.product, ['id', 'slug', 'title', 'artist']) && int(x.product.id, 1, Number.MAX_SAFE_INTEGER) && text(x.product.slug, 192) && text(x.product.title, 256) && text(x.product.artist, 256)
    && int(x.maxOrigins, 1, 100000) && int(x.maxDownloads, 1, 1000) && int(x.tokenTtlSeconds, 30, 300)
    && Array.isArray(x.assets) && x.assets.length >= 1 && x.assets.length <= 2 && x.assets.every(a => obj(a) && exact(a, ['id', 'role', 'sha256', 'mime_type', 'size_bytes']) && int(a.id, 1, Number.MAX_SAFE_INTEGER) && role(a.role) && a.role !== 'contract' && hash(a.sha256) && a.mime_type === media[a.role][1] && int(a.size_bytes, 1, 1073741824));
}
export function validFreeOrigin(x: unknown): x is FreeOrigin {
  return obj(x) && exact(x, ['id', 'definitionId', 'title', 'originHash', 'purpose', 'declaredName', 'acceptedAt', 'assentText', 'license', 'collection', 'testOnly', 'documentStatus', 'renderAttempts', 'maxDownloads', 'files'])
    && uuid(x.id) && uuid(x.definitionId) && text(x.title, 160) && hash(x.originHash) && x.purpose === 'free-license-grant' && text(x.declaredName, 120)
    && typeof x.acceptedAt === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(x.acceptedAt) && Number.isFinite(Date.parse(x.acceptedAt))
    && text(x.assentText, 8192) && license(x.license) && x.collection === 'none' && x.testOnly === true && ['pending', 'claimed', 'failed', 'complete'].includes(String(x.documentStatus))
    && int(x.renderAttempts, 0, 5) && int(x.maxDownloads, 1, 1000) && Array.isArray(x.files) && x.files.length <= 3
    && (x.documentStatus === 'complete' ? x.files.length >= 2 : x.files.length === 0)
    && x.files.every(f => obj(f) && exact(f, ['kind', 'sha256', 'sizeBytes', 'mimeType']) && role(f.kind) && hash(f.sha256) && int(f.sizeBytes, 1, f.kind === 'contract' ? 16777216 : 1073741824) && f.mimeType === media[f.kind][1]);
}
const sqlDate = (x: unknown): x is string => typeof x === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(x);
function validStatus(x: unknown, id: string): x is DownloadStatus {
  return obj(x) && exact(x, ['schemaVersion', 'originId', 'attemptCount', 'maxDownloads', 'historyLimit', 'history', 'renderRetryAllowed', 'renderRetryAfter']) && x.schemaVersion === 1 && x.originId === id && x.historyLimit === 20 && int(x.attemptCount, 0, 1000) && int(x.maxDownloads, 1, 1000) && typeof x.renderRetryAllowed === 'boolean' && (x.renderRetryAfter === null || sqlDate(x.renderRetryAfter))
    && Array.isArray(x.history) && x.history.length <= 20 && x.history.every(h => obj(h) && exact(h, ['id', 'kind', 'issuedAt', 'expiresAt', 'status', 'attemptedAt']) && uuid(h.id) && role(h.kind) && sqlDate(h.issuedAt) && sqlDate(h.expiresAt) && ['attempted', 'unused', 'expired'].includes(String(h.status)) && (h.status === 'attempted' ? sqlDate(h.attemptedAt) : h.attemptedAt === null));
}
function listing(x: unknown): x is Listing { return obj(x) && exact(x, ['schemaVersion', 'definitions', 'origins', 'definitionLimit', 'originLimit']) && x.schemaVersion === 1 && x.definitionLimit === 50 && x.originLimit === 20 && Array.isArray(x.definitions) && x.definitions.length <= 50 && x.definitions.every(validFreeDefinition) && Array.isArray(x.origins) && x.origins.length <= 20 && x.origins.every(validFreeOrigin); }
async function readJson(response: Response, signal: AbortSignal): Promise<unknown> {
  const reader = response.body?.getReader(); if (!reader) throw new Error();
  const chunks: Uint8Array[] = []; let size = 0;
  let rejectAbort!: () => void;
  const interrupted = new Promise<never>((_, reject) => { rejectAbort = () => { void reader.cancel().catch(() => {}); reject(new Error()); }; signal.addEventListener('abort', rejectAbort, { once: true }); });
  try {
    if (signal.aborted) throw new Error();
    while (true) { const result = await Promise.race([reader.read(), interrupted]); if (signal.aborted) throw new Error(); if (result.done) break; size += result.value.byteLength; if (size > 4 * 1024 * 1024) throw new Error(); chunks.push(result.value); }
    const bytes = new Uint8Array(size); let at = 0; for (const chunk of chunks) { bytes.set(chunk, at); at += chunk.length; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', rejectAbort); void reader.cancel().catch(() => {}); reader.releaseLock(); }
}
function csrf(): string | null { return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null; }
const unknown = 'The result could not be confirmed. Refresh the saved grants before deliberately retrying the same request.';
export function FreeGrantJourney() {
  const [data, setData] = useState<Listing | null>(null), [selected, setSelected] = useState<FreeDefinition | null>(null), [name, setName] = useState('');
  const [review, setReview] = useState<Review | null>(null), [affirmed, setAffirmed] = useState(false), [origin, setOrigin] = useState<FreeOrigin | null>(null);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [denied, setDenied] = useState(false), [pending, setPending] = useState<Pending | null>(null);
  const [downloadStatus, setDownloadStatus] = useState<DownloadStatus | null>(null);
  const [authorization, setAuthorization] = useState<Authorization | null>(null);
  const active = useRef(false), generation = useRef(0), request = useRef<AbortController | null>(null), inflight = useRef(false), frames = useRef<HTMLIFrameElement[]>([]), alert = useRef<HTMLDivElement>(null);
  function clear() { setData(null); setSelected(null); setName(''); setReview(null); setAffirmed(false); setOrigin(null); setPending(null); setAuthorization(null); setDownloadStatus(null); frames.current.forEach(f => f.remove()); frames.current = []; }
  function refuse() { clear(); setDenied(true); setMessage('Access changed. Open a fresh sign-in page before continuing.'); }
  useEffect(() => {
    active.current = true;
    const leave = () => { generation.current += 1; request.current?.abort(); clear(); setBusy(false); inflight.current = false; };
    const visibility = () => { if (document.visibilityState === 'hidden') leave(); };
    window.addEventListener('pagehide', leave); document.addEventListener('visibilitychange', visibility);
    return () => { active.current = false; generation.current += 1; request.current?.abort(); frames.current.forEach(f => f.remove()); window.removeEventListener('pagehide', leave); document.removeEventListener('visibilitychange', visibility); };
  }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);
  async function call(path: string, body: Record<string, unknown> | null, commit: (x: unknown) => void, uncertain = false) {
    if (inflight.current || denied) return; inflight.current = true; setBusy(true); setMessage(''); setAuthorization(null);
    const abort = new AbortController(), mine = ++generation.current; request.current = abort;
    const timer = window.setTimeout(() => abort.abort(), 20_000);
    const owns = () => active.current && mine === generation.current;
    try {
      const token = csrf(); if (body && !token) { refuse(); return; }
      const response = await Promise.race([fetch(path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token! } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) }), new Promise<never>((_, reject) => abort.signal.addEventListener('abort', () => reject(new Error()), { once: true }))]);
      if (!owns() || abort.signal.aborted) return;
      if ([403, 404, 419].includes(response.status)) { refuse(); return; }
      const value = await readJson(response, abort.signal); if (!owns() || abort.signal.aborted) return;
      if (!response.ok) { if (!uncertain) setReview(null); setMessage(uncertain ? unknown : 'This request is unavailable or changed. Refresh before trying again.'); return; }
      commit(value);
    } catch { if (owns()) { if (!uncertain) { setData(null); setReview(null); setOrigin(null); } setMessage(unknown); } }
    finally { window.clearTimeout(timer); if (owns()) { inflight.current = false; setBusy(false); request.current = null; } }
  }
  function refresh() { void call('/free-grants/index', null, x => { if (!listing(x)) throw new Error(); setData(x); setReview(null); setAffirmed(false); setOrigin(null); }); }
  function preview() { if (!selected || !name.trim()) return; const id = selected.id, declaredName = name.trim(); void call(`/free-grants/definitions/${id}/review`, { declaredName }, x => { if (!obj(x) || !exact(x, ['definition', 'declaredName', 'assentHash']) || !validFreeDefinition(x.definition) || x.definition.id !== id || !x.definition.open || x.declaredName !== declaredName || !hash(x.assentHash)) throw new Error(); setReview(x as unknown as Review); setAffirmed(false); setPending(null); }); }
  function accept() { if (!review || !affirmed || pending) return; const operation: Pending = { path: `/free-grants/definitions/${review.definition.id}/accept`, kind: 'accept', body: { requestKey: crypto.randomUUID(), definitionHash: review.definition.definitionHash, reviewHash: review.definition.reviewHash, expectedVersion: review.definition.version, declaredName: review.declaredName, affirmed: true, assentHash: review.assentHash } }; setPending(operation); execute(operation); }
  function execute(operation: Pending) { void call(operation.path, operation.body, x => {
    if (operation.kind === 'accept') { if (!obj(x) || !exact(x, ['origin']) || !validFreeOrigin(x.origin) || operation.path !== `/free-grants/definitions/${x.origin.definitionId}/accept` || x.origin.declaredName !== operation.body.declaredName) throw new Error(); setOrigin(x.origin); setReview(null); setName(''); setAffirmed(false); setSelected(null); }
    else { const a = obj(x) && exact(x, ['authorization']) ? x.authorization : null, item = operation.origin?.files.find(f => f.kind === operation.body.kind);
      if (!obj(a) || !exact(a, ['id', 'token', 'expiresAt', 'kind', 'filename', 'mimeType']) || !uuid(a.id) || typeof a.token !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(a.token) || typeof a.expiresAt !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(a.expiresAt) || !item || !role(item.kind) || a.kind !== item.kind || a.filename !== `free-grant-${operation.origin!.id}-${item.kind}${media[item.kind][0]}` || a.mimeType !== media[item.kind][1]) throw new Error(); setOrigin(operation.origin!); setAuthorization(a as unknown as Authorization); }
    setPending(null);
  }, true); }
  function openOrigin(o: FreeOrigin) { setDownloadStatus(null); setOrigin(null); setReview(null); setSelected(null); setName(''); setAffirmed(false); void call(`/free-grants/origins/${o.id}`, null, x => { if (!obj(x) || !exact(x, ['origin']) || !validFreeOrigin(x.origin) || x.origin.id !== o.id) throw new Error(); setOrigin(x.origin); }); }
  function prepare() { if (!origin) return; const o = origin; void call(`/free-grants/origins/${o.id}/document`, { originHash: o.originHash }, x => { if (!obj(x) || !exact(x, ['origin']) || !validFreeOrigin(x.origin) || x.origin.id !== o.id || x.origin.originHash !== o.originHash) throw new Error(); setOrigin(x.origin); setDownloadStatus(null); }); }
  function status() { if (!origin) return; const o = origin; void call(`/free-grants/origins/${o.id}/downloads`, null, x => { if (!obj(x) || !exact(x, ['status']) || !validStatus(x.status, o.id) || x.status.maxDownloads !== o.maxDownloads) throw new Error(); setDownloadStatus(x.status); }); }
  function authorize(kind: string) { if (!origin || pending || !role(kind)) return; const bytes = crypto.getRandomValues(new Uint8Array(32)); const nonce = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join(''); const operation: Pending = { path: `/free-grants/origins/${origin.id}/authorize`, kind: 'authorize', origin, body: { requestKey: crypto.randomUUID(), originHash: origin.originHash, kind, nonce } }; setPending(operation); execute(operation); }
  function download() {
    const token = csrf(), a = authorization; if (!a || !token || denied || busy) return;
    setAuthorization(null);
    if (frames.current.length === 3) frames.current.shift()?.remove();
    const frame = document.createElement('iframe'); frame.name = `free-grant-${crypto.randomUUID()}`; frame.title = 'Free grant attachment response'; frame.hidden = true; frame.setAttribute('referrerpolicy', 'no-referrer');
    const mine = generation.current;
    frame.addEventListener('load', () => { if (!active.current || mine !== generation.current) return; try { const doc = frame.contentDocument; if (!doc || doc.location.href === 'about:blank') return; const raw = doc.body?.textContent ?? ''; if (raw.length > 4096) throw new Error(); const failure = JSON.parse(raw); if (obj(failure) && failure.code === 'FREE_GRANT_UNAVAILABLE') { clear(); setMessage('The download was refused. Open fresh saved status before trying again.'); } else throw new Error(); } catch { setMessage('The attachment result could not be confirmed. Check browser downloads and refresh saved status.'); } });
    document.body.append(frame); frames.current.push(frame);
    const form = document.createElement('form'); form.method = 'POST'; form.action = `/free-grants/authorizations/${a.id}/redeem`; form.target = frame.name; form.enctype = 'application/x-www-form-urlencoded'; form.hidden = true;
    for (const [key, value] of Object.entries({ token: a.token, _token: token })) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = value; form.append(input); }
    document.body.append(form); try { HTMLFormElement.prototype.submit.call(form); setMessage('A download attempt was submitted. Check browser downloads; an interrupted attempt can be consumed without file receipt.'); } catch { setMessage(unknown); } finally { form.querySelectorAll('input').forEach(f => { f.value = ''; }); form.remove(); }
  }
  return <section aria-label="Free grant journey" aria-busy={busy}>
    <p>Local/test preparation. Review the explicit terms and request a free grant. Your name is a declaration, not verified legal identity. No marketing enrollment is inferred.</p>
    {message && <div role="alert" tabIndex={-1} ref={alert} className="customer-account-message">{message}{denied && <a href="/account/sign-in">Open a fresh sign-in page</a>}</div>}
    <button type="button" className="button button-outline" disabled={busy || denied} onClick={refresh}>{data ? 'Refresh free grants' : 'Open free grants'}</button>
    {pending && !denied && <div><p>The same request can be retried after reviewing saved status. Do not create a replacement request while this result is uncertain.</p><button type="button" disabled={busy} onClick={() => execute(pending)}>Retry the exact request</button></div>}
    {data && <><p>Latest {data.definitionLimit} published definitions and latest {data.originLimit} saved grants.</p><div aria-label="Published free definitions">{data.definitions.map(d => <article key={d.id}><h2>{d.title}</h2><p>{d.open ? 'Accepting new requests' : 'Closed to new requests'}</p><button type="button" disabled={busy || !d.open || !!pending} onClick={() => { setSelected(d); setReview(null); setOrigin(null); setDownloadStatus(null); setName(''); setAffirmed(false); setAuthorization(null); }}>Review {d.title}</button></article>)}{!data.definitions.length && <p>No published free definitions.</p>}</div>
      <div aria-label="Saved free grants">{data.origins.map(o => <button type="button" disabled={busy} key={o.id} onClick={() => openOrigin(o)}>Open saved grant {o.title}</button>)}</div></>}
    {selected && !review && <form onSubmit={e => { e.preventDefault(); preview(); }}><div className="customer-account-field"><label htmlFor="free-declared-name">Your declared name</label><input id="free-declared-name" value={name} maxLength={120} disabled={busy || !!pending} onChange={e => { setName(e.target.value); setReview(null); setAffirmed(false); }} autoComplete="off" /></div><button type="submit" disabled={busy || !name.trim() || !!pending}>Review exact free terms</button></form>}
    {review && <section aria-label="Exact free assent"><h2>{review.definition.title}</h2><p>Declared name: {review.declaredName}</p><p>Reference: {review.definition.termsReference}</p><p>{review.definition.product.title} / {review.definition.product.artist}</p><h3>{review.definition.license.name} / version {review.definition.license.version}</h3><pre className="free-grant-terms">{review.definition.license.termsText}</pre><p>{review.definition.assentText}</p><p>{review.definition.maxDownloads} committed download attempts across files; authorization expires after {review.definition.tokenTtlSeconds} seconds. PDF preparation must succeed before downloads are available.</p><ul>{review.definition.assets.map(a => <li key={a.id}>{a.role} / {a.size_bytes} bytes / SHA256 {a.sha256}</li>)}</ul><label><input type="checkbox" checked={affirmed} disabled={busy || !!pending} onChange={e => setAffirmed(e.target.checked)} /> I affirm the displayed free-purpose terms for my declared name.</label><button type="button" disabled={busy || !affirmed || !!pending} onClick={accept}>Accept this free grant</button></section>}
    {origin && <section aria-label="Retained free grant"><h2>{origin.title}</h2><p>Declared name: {origin.declaredName}</p><p>Accepted: {origin.acceptedAt}</p><p>Original PDF: {{ pending: 'ready to prepare', claimed: 'preparation in progress', failed: 'preparation needs a retry', complete: 'ready' }[origin.documentStatus]}.</p><p>{origin.assentText}</p><details><summary>Retained license terms</summary><pre className="free-grant-terms">{origin.license.termsText}</pre></details><p>Up to {origin.maxDownloads} committed download attempts across all files. A stream attempt does not confirm file receipt.</p>
      {origin.documentStatus !== 'complete' && <button type="button" disabled={busy || origin.renderAttempts >= 5 || (origin.documentStatus === 'claimed' && downloadStatus?.renderRetryAllowed !== true)} onClick={prepare}>Prepare original PDF</button>}
      <button type="button" disabled={busy} onClick={status}>Refresh preparation and download status</button>
      {downloadStatus && <div aria-label="Preparation and download status"><p>{downloadStatus.attemptCount}/{downloadStatus.maxDownloads} committed download attempts. This does not confirm file receipt.</p>{downloadStatus.renderRetryAfter && <p>Preparation retry check: {downloadStatus.renderRetryAfter} UTC. Refresh status after this time.</p>}<ul>{downloadStatus.history.map(h => <li key={h.id}>{h.kind} / {h.status}{h.attemptedAt && ` / ${h.attemptedAt} UTC`}</li>)}</ul></div>}
      {origin.files.map(f => <div key={f.kind}><p>{f.kind} / {f.sizeBytes} bytes / SHA256 {f.sha256}</p><button type="button" disabled={busy || !!pending} onClick={() => authorize(f.kind)}>Authorize {f.kind} download</button></div>)}
      {authorization && <div><p>{authorization.filename}; expires {authorization.expiresAt} UTC.</p><button type="button" disabled={busy} onClick={download}>Download authorized file</button></div>}
    </section>}
  </section>;
}
