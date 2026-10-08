import { useEffect, useRef, useState } from 'react';

type Kind = 'contract' | 'master_wav' | 'download_mp3' | 'stems_zip';
interface License { licenseVersionId: string; name: string; version: number; type: 'non-exclusive'; features: string[]; deliverableRoles: Exclude<Kind, 'contract'>[]; termsText: string }
export interface PaidOrigin {
  id: string; orderId: string; purpose: 'paid-license-grant'; provenance: 'synthetic_rehearsal' | 'verified_production'; fulfilled: boolean;
  lines: { id: string; originHash: string; position: number; title: string; license: License; declaredName: string; assentedAt: string;
    currency: 'USD'; lineAmountMinor: number; lineTaxMinor: number; documentStatus: 'pending' | 'claimed' | 'failed' | 'complete'; attempts: number;
    files: { kind: Kind; sha256: string; sizeBytes: number }[] }[];
}
interface Listing { schemaVersion: 1; originLimit: 20; origins: { id: string; orderId: string; createdAt: string; provenance: PaidOrigin['provenance'] }[] }
interface LineStatus { id: string; attemptCount: number; maxDownloads: number; historyLimit: 20; renderRetryAllowed: boolean; renderRetryAfter: string | null;
  history: { id: string; kind: Kind; issuedAt: string; expiresAt: string; status: 'attempted' | 'unused' | 'expired'; attemptedAt: string | null }[] }
interface Status { schemaVersion: 1; originId: string; fulfilled: boolean; lines: LineStatus[] }
interface Authorization { id: string; token: string; expiresAt: string; kind: Kind; filename: string; mimeType: string }
interface Pending { path: string; body: Record<string, unknown>; orderId?: string; origin?: PaidOrigin; lineId?: string }
const obj = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object' && !Array.isArray(x);
const exact = (x: Record<string, unknown>, keys: string[]) => Object.keys(x).length === keys.length && keys.every(k => Object.hasOwn(x, k));
const uuid = (x: unknown): x is string => typeof x === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(x);
const hash = (x: unknown): x is string => typeof x === 'string' && /^[a-f0-9]{64}$/.test(x);
const text = (x: unknown, max = 1_048_576): x is string => typeof x === 'string' && x.length > 0 && x.length <= max;
const int = (x: unknown, min: number, max: number) => Number.isSafeInteger(x) && (x as number) >= min && (x as number) <= max;
const kind = (x: unknown): x is Kind => ['contract', 'master_wav', 'download_mp3', 'stems_zip'].includes(String(x));
const provenance = (x: unknown) => x === 'synthetic_rehearsal' || x === 'verified_production';
const sqlDate = (x: unknown): x is string => typeof x === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(x);
const isoDate = (x: unknown): x is string => typeof x === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(x) && Number.isFinite(Date.parse(x));
const media = { contract: ['.pdf', 'application/pdf'], master_wav: ['.wav', 'audio/wav'], download_mp3: ['.mp3', 'audio/mpeg'], stems_zip: ['.zip', 'application/zip'] } as const;
function license(x: unknown): x is License {
  return obj(x) && exact(x, ['licenseVersionId', 'name', 'version', 'type', 'features', 'deliverableRoles', 'termsText'])
    && text(x.licenseVersionId, 20) && text(x.name, 192) && int(x.version, 1, 100000) && x.type === 'non-exclusive'
    && Array.isArray(x.features) && x.features.length <= 100 && x.features.every(t => text(t, 8192))
    && Array.isArray(x.deliverableRoles) && x.deliverableRoles.length >= 1 && x.deliverableRoles.length <= 3 && x.deliverableRoles.every(k => kind(k) && k !== 'contract')
    && new Set(x.deliverableRoles).size === x.deliverableRoles.length && text(x.termsText);
}
export function validPaidOrigin(x: unknown): x is PaidOrigin {
  if (!obj(x) || !exact(x, ['id', 'orderId', 'purpose', 'provenance', 'fulfilled', 'lines']) || !uuid(x.id) || !uuid(x.orderId) || x.purpose !== 'paid-license-grant'
    || !provenance(x.provenance) || typeof x.fulfilled !== 'boolean' || !Array.isArray(x.lines) || x.lines.length < 1 || x.lines.length > 10) return false;
  return new Set(x.lines.map(l => obj(l) ? l.id : null)).size === x.lines.length && x.lines.every((l, i) => {
    if (!obj(l) || !exact(l, ['id', 'originHash', 'position', 'title', 'license', 'declaredName', 'assentedAt', 'currency', 'lineAmountMinor', 'lineTaxMinor', 'documentStatus', 'attempts', 'files'])
      || !uuid(l.id) || !hash(l.originHash) || l.position !== i + 1 || !text(l.title, 256) || !license(l.license) || !text(l.declaredName, 120) || !isoDate(l.assentedAt)
      || l.currency !== 'USD' || !int(l.lineAmountMinor, 1, Number.MAX_SAFE_INTEGER) || !int(l.lineTaxMinor, 0, Number.MAX_SAFE_INTEGER)
      || !['pending', 'claimed', 'failed', 'complete'].includes(String(l.documentStatus)) || !int(l.attempts, 0, 5) || !Array.isArray(l.files)) return false;
    if (!x.fulfilled) return l.files.length === 0;
    const files = l.files;
    return l.documentStatus === 'complete' && files.length === l.license.deliverableRoles.length + 1 && files.every(f => obj(f)
      && exact(f, ['kind', 'sha256', 'sizeBytes']) && kind(f.kind) && hash(f.sha256) && int(f.sizeBytes, 1, f.kind === 'contract' ? 16777216 : 1073741824))
      && new Set(files.map(f => f.kind)).size === files.length && files.some(f => f.kind === 'contract') && l.license.deliverableRoles.every(k => files.some(f => f.kind === k));
  });
}
function listing(x: unknown): x is Listing {
  return obj(x) && exact(x, ['schemaVersion', 'originLimit', 'origins']) && x.schemaVersion === 1 && x.originLimit === 20
    && Array.isArray(x.origins) && x.origins.length <= 20 && new Set(x.origins.map(o => obj(o) ? o.id : null)).size === x.origins.length
    && x.origins.every(o => obj(o) && exact(o, ['id', 'orderId', 'createdAt', 'provenance']) && uuid(o.id) && uuid(o.orderId) && sqlDate(o.createdAt) && provenance(o.provenance));
}
function validStatus(x: unknown, origin: PaidOrigin): x is Status {
  return obj(x) && exact(x, ['schemaVersion', 'originId', 'fulfilled', 'lines']) && x.schemaVersion === 1 && x.originId === origin.id && x.fulfilled === origin.fulfilled
    && Array.isArray(x.lines) && x.lines.length === origin.lines.length && x.lines.every((l, i) => obj(l)
      && exact(l, ['id', 'attemptCount', 'maxDownloads', 'historyLimit', 'history', 'renderRetryAllowed', 'renderRetryAfter']) && l.id === origin.lines[i].id
      && int(l.attemptCount, 0, 100) && int(l.maxDownloads, 1, 100) && (l.attemptCount as number) <= (l.maxDownloads as number) && l.historyLimit === 20
      && typeof l.renderRetryAllowed === 'boolean' && (l.renderRetryAfter === null || sqlDate(l.renderRetryAfter)) && Array.isArray(l.history) && l.history.length <= 20
      && l.history.every(h => obj(h) && exact(h, ['id', 'kind', 'issuedAt', 'expiresAt', 'status', 'attemptedAt']) && uuid(h.id) && kind(h.kind)
        && sqlDate(h.issuedAt) && sqlDate(h.expiresAt) && ['attempted', 'unused', 'expired'].includes(String(h.status)) && (h.status === 'attempted' ? sqlDate(h.attemptedAt) : h.attemptedAt === null)));
}
async function readJson(response: Response, signal: AbortSignal): Promise<unknown> {
  const reader = response.body?.getReader(); if (!reader) throw new Error();
  const chunks: Uint8Array[] = []; let size = 0; let rejectAbort!: () => void;
  const interrupted = new Promise<never>((_, reject) => { rejectAbort = () => { void reader.cancel().catch(() => {}); reject(new Error()); }; signal.addEventListener('abort', rejectAbort, { once: true }); });
  try {
    if (signal.aborted) throw new Error();
    while (true) { const result = await Promise.race([reader.read(), interrupted]); if (signal.aborted) throw new Error(); if (result.done) break; size += result.value.byteLength; if (size > 4 * 1024 * 1024) throw new Error(); chunks.push(result.value); }
    const bytes = new Uint8Array(size); let at = 0; for (const chunk of chunks) { bytes.set(chunk, at); at += chunk.length; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', rejectAbort); void reader.cancel().catch(() => {}); reader.releaseLock(); }
}
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null;
const unknown = 'The result could not be confirmed. Refresh saved licenses before deliberately retrying the same request.';
/**
 * Client abort per operation, in milliseconds. Every request waits past the server's own hard budget (60 s for reads,
 * finalize and authorize, the 300 s render lease for document) plus the 5 s session-lock wait and a 15 s margin, so the
 * browser never discards an answer the server can still give or commit. A request abandoned earlier (hidden tab, network
 * error) may still be running on the server; its exact retry is safe anyway, because the server returns the same row for
 * the same request key.
 * Measured native work: finalize 18 s, authorize 12-20 s, document 65-76 s
 * (docs/verification/paid252-composition-20261007/hardening/codex-1/README.md).
 */
export const paidRequestTimeouts = { read: 80_000, finalize: 80_000, authorize: 80_000, document: 320_000 } as const;
export type PaidOperation = keyof typeof paidRequestTimeouts;
export function PaidGrantJourney() {
  const [data, setData] = useState<Listing | null>(null), [origin, setOrigin] = useState<PaidOrigin | null>(null), [order, setOrder] = useState('');
  const [status, setStatus] = useState<Status | null>(null), [issued, setIssued] = useState<{ auth: Authorization; originId: string }[]>([]);
  const [pending, setPending] = useState<Pending | null>(null), [reviewedSaved, setReviewedSaved] = useState(false), [statusAfter, setStatusAfter] = useState<{ pending: Pending; issuedAt: number } | null>(null);
  const [busy, setBusy] = useState(false), [denied, setDenied] = useState(false), [message, setMessage] = useState('');
  const sentAt = useRef(0), kept = useRef<{ auth: Authorization; refused: boolean }[]>([]), active = useRef(false), generation = useRef(0), request = useRef<AbortController | null>(null), inflight = useRef(false), frames = useRef<HTMLIFrameElement[]>([]), alert = useRef<HTMLDivElement>(null);
  function clear(keepPending = false, keepFrames = false) { setData(null); setOrigin(null); setOrder(''); setStatus(null); if (!keepPending) setPending(null); setReviewedSaved(false); setStatusAfter(null); if (!keepFrames) { frames.current.forEach(f => f.remove()); frames.current = []; } }
  function refuse() { clear(); kept.current = []; setIssued([]); setDenied(true); setMessage('Access changed. Open a fresh sign-in page before continuing.'); }
  useEffect(() => {
    active.current = true;
    const leave = () => { generation.current++; request.current?.abort(); clear(); kept.current = []; setIssued([]); inflight.current = false; setBusy(false); };
    // Hiding the tab is not leaving: everything shown is cleared and an in-flight request is abandoned, but an uncertain
    // finalize or authorize keeps its exact replay (same request key and nonce, held in memory and never rendered), so a
    // committed authorization can still be recovered instead of duplicated. Hidden download frames are kept too: removing
    // one would abort a download whose redemption the server may still commit. Actual departure (pagehide) clears both.
    const visibility = () => { if (document.visibilityState !== 'hidden') return; const interrupted = inflight.current;
      generation.current++; request.current?.abort(); clear(true, true); inflight.current = false; setBusy(false); if (interrupted) setMessage(unknown); };
    window.addEventListener('pagehide', leave); document.addEventListener('visibilitychange', visibility);
    return () => { active.current = false; generation.current++; request.current?.abort(); frames.current.forEach(f => f.remove()); window.removeEventListener('pagehide', leave); document.removeEventListener('visibilitychange', visibility); };
  }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);
  async function call(operation: PaidOperation, path: string, body: Record<string, unknown> | null, commit: (x: unknown) => void) {
    if (inflight.current || denied) return; inflight.current = true; setBusy(true); setMessage('');
    const abort = new AbortController(), mine = ++generation.current; request.current = abort;
    const timer = window.setTimeout(() => abort.abort(), paidRequestTimeouts[operation]), owns = () => active.current && mine === generation.current;
    try {
      const token = csrf(); if (body && !token) { refuse(); return; }
      const response = await Promise.race([fetch(path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token! } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) }),
      new Promise<never>((_, reject) => abort.signal.addEventListener('abort', () => reject(new Error()), { once: true }))]);
      if (!owns() || abort.signal.aborted) return;
      if ([403, 404, 419].includes(response.status)) { refuse(); return; }
      const value = await readJson(response, abort.signal); if (!owns() || abort.signal.aborted) return;
      // A body closure can deny before private bytes after headers have already left the server.
      if (obj(value) && exact(value, ['error', 'status']) && value.error === 'Paid grant request unavailable.'
        && Number.isInteger(value.status) && [403, 404, 419, 503].includes(value.status as number)) {
        if ([403, 404, 419].includes(value.status as number)) refuse(); else setMessage(unknown);
        return;
      }
      if (!response.ok) { setMessage(unknown); return; }
      commit(value);
    } catch { if (owns()) { if (!body) { setData(null); setOrigin(null); setStatus(null); } setMessage(unknown); } }
    finally { window.clearTimeout(timer); if (owns()) { inflight.current = false; setBusy(false); request.current = null; } }
  }
  function refresh() { void call('read', '/paid-grants/index', null, x => { if (!listing(x)) throw new Error(); setData(x); setOrigin(null); setStatus(null); setReviewedSaved(true); }); }
  function open(id: string, orderId: string) { setOrigin(null); setStatus(null); setOrder(''); void call('read', `/paid-grants/origins/${id}`, null, x => { if (!obj(x) || !exact(x, ['origin']) || !validPaidOrigin(x.origin) || x.origin.id !== id || x.origin.orderId !== orderId) throw new Error(); setOrigin(x.origin); }); }
  function execute(p: Pending) { sentAt.current = performance.now(); void call(p.orderId ? 'finalize' : 'authorize', p.path, p.body, x => {
    if (p.orderId) { if (!obj(x) || !exact(x, ['origin']) || !validPaidOrigin(x.origin) || x.origin.orderId !== p.orderId) throw new Error(); setOrigin(x.origin); setOrder(''); setStatus(null); }
    else { const a = obj(x) && exact(x, ['authorization']) ? x.authorization : null, line = p.origin?.lines.find(l => l.id === p.lineId);
      if (!obj(a) || !exact(a, ['id', 'token', 'expiresAt', 'kind', 'filename', 'mimeType']) || !uuid(a.id) || typeof a.token !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(a.token)
        || !sqlDate(a.expiresAt) || !kind(a.kind) || a.kind !== p.body.kind || !line?.files.some(f => f.kind === a.kind)
        || a.filename !== `paid-license-${line.id}-${a.kind}${media[a.kind][0]}` || a.mimeType !== media[a.kind][1]) throw new Error();
      // The server keeps only a token hash, so each issued authorization stays in memory with its order until it is
      // submitted, a denial occurs or the page is left; later reads, other orders or other authorizations keep it.
      const issuedAuth = a as unknown as Authorization, originId = p.origin!.id;
      setIssued(list => [...list.filter(i => i.auth.id !== issuedAuth.id), { auth: issuedAuth, originId }]); setOrigin(p.origin!);
    }
    setPending(null); setReviewedSaved(false);
  }); }
  function finalize() { if (!uuid(order) || pending) return; const p: Pending = { path: `/paid-grants/orders/${order}/finalize`, body: {}, orderId: order }; setPending(p); setReviewedSaved(false); execute(p); }
  function prepare() { if (!origin) return; const o = origin; void call('document', `/paid-grants/origins/${o.id}/document`, {}, x => { if (!obj(x) || !exact(x, ['origin']) || !validPaidOrigin(x.origin) || x.origin.id !== o.id || x.origin.orderId !== o.orderId || x.origin.lines.some((l, i) => l.id !== o.lines[i]?.id || l.originHash !== o.lines[i]?.originHash)) throw new Error(); setOrigin(x.origin); setStatus(null); }); }
  function savedStatus() { if (!origin) return; const o = origin, p = pending, issuedAt = performance.now(); void call('read', `/paid-grants/origins/${o.id}/downloads`, null, x => { if (!obj(x) || !exact(x, ['status']) || !validStatus(x.status, o)) throw new Error(); setStatus(x.status); setStatusAfter(p ? { pending: p, issuedAt } : null); setReviewedSaved(true); }); }
  // An authorize whose answer was lost may have committed. Only a saved status read taken after that request may show it is
  // not live, so the customer can drop it and ask again; a live one is recovered by the exact retry instead, so it never
  // spends the per-line issuance limit twice. The read lists the line's newest `historyLimit` authorizations. Every
  // authorization on a line shares the batch's fixed lifetime and is issued in id order under the batch lock, so it
  // expires no later than any newer one: a window that is not full holds the whole line, and an expired entry in a full
  // window proves every older authorization (one pushed out of the window included) expired too. The read must also be
  // issued at least the client authorize timeout after the request was last sent: by then the server has finished it
  // (its 60 s budget plus the session-lock wait), so a transmission that was abandoned (hidden tab, network error, a lost
  // retry) can no longer commit after the read.
  const pendingLine = pending && !pending.orderId && reviewedSaved && statusAfter?.pending === pending && statusAfter.issuedAt - sentAt.current >= paidRequestTimeouts.authorize
    && status && status.originId === pending.origin?.id
    ? status.lines.find(s => s.id === pending.lineId) : undefined;
  const abandonable = !!pendingLine && !pendingLine.history.some(h => h.kind === pending?.body.kind && h.status === 'unused')
    && (pendingLine.history.length < pendingLine.historyLimit || pendingLine.history.some(h => h.status === 'expired'));
  function abandon() { if (!abandonable || busy) return; setPending(null); setStatusAfter(null); setReviewedSaved(false); setMessage('The earlier authorization request was set aside. Saved status shows no live authorization for that file; you can request a new one.'); }
  function authorize(lineId: string, k: Kind) { if (!origin || pending || !origin.fulfilled) return; const line = origin.lines.find(l => l.id === lineId); if (!line) return;
    const nonce = Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
    const p: Pending = { path: `/paid-grants/origins/${origin.id}/lines/${lineId}/authorize`, body: { requestKey: crypto.randomUUID(), originHash: line.originHash, kind: k, nonce }, origin, lineId };
    setPending(p); setReviewedSaved(false); execute(p);
  }
  // A refusal can come before anything is recorded (for example a busy spool), leaving the authorization live and unused.
  // Its token is kept in memory only (never rendered). Once that submission's own frame has reported the refusal (so its
  // request is over, not still being prepared on the server) and a saved status read taken after the submission shows that
  // exact authorization still unused, the customer can retry it instead of issuing another. The server refuses a spent or
  // expired token, so a retry can never redeem twice. A denial or leaving the page drops it.

  // Kept per authorization: a second submission before the first frame answers must not orphan the first one's retry.
  function retryDownload(a: Authorization) { if (kept.current.some(m => m.auth.id === a.id && m.refused)) submit(a); }
  function submit(a: Authorization) {
    const token = csrf(); if (!token || denied || busy) return; setIssued(list => list.filter(i => i.auth.id !== a.id)); const mark = { auth: a, refused: false }; kept.current = [...kept.current.filter(m => m.auth.id !== a.id), mark]; setStatus(null);
    // Frames are never evicted to make room: removing one that has not reported back would abort a download the server may
    // still commit. They are removed on their own refusal, a denial, leaving the page and unmount.
    const frame = document.createElement('iframe'); frame.name = `paid-grant-${crypto.randomUUID()}`; frame.title = 'Paid license attachment response'; frame.hidden = true; frame.setAttribute('referrerpolicy', 'no-referrer');
    // A frame's answer is tied to the frame, not to the API-request generation: a hidden tab or a later status read must not
    // discard a refusal, or the kept authorization could never be retried. A frame removed by a denial, departure or unmount
    // is ignored. Only when no later request has started does the refusal also clear the page and show its message.
    const mine = generation.current;
    frame.addEventListener('load', () => { if (!active.current || !frames.current.includes(frame)) return; const current = mine === generation.current; try {
      const doc = frame.contentDocument; if (!doc || doc.location.href === 'about:blank') return; const raw = doc.body?.textContent ?? ''; if (raw.length > 4096) throw new Error(); const failure = JSON.parse(raw);
      // Only this submission's frame is removed: another download still waiting for its response keeps its frame, and an
      // unrelated uncertain authorize keeps its exact replay (a refusal body cannot say whether that authorize committed).
      if (obj(failure) && failure.code === 'PAID_GRANT_UNAVAILABLE') { mark.refused = true; frame.remove(); frames.current = frames.current.filter(f => f !== frame);
        if (current) { clear(true, true); setMessage('The download was refused. Refresh saved licenses and download status; an authorization that is still unused can then be retried.'); } } else throw new Error();
    } catch { if (current) setMessage('The attachment result could not be confirmed. Check browser downloads and refresh saved status.'); } });
    document.body.append(frame); frames.current.push(frame);
    const form = document.createElement('form'); form.method = 'POST'; form.action = `/paid-grants/authorizations/${a.id}/redeem`; form.target = frame.name; form.enctype = 'application/x-www-form-urlencoded'; form.hidden = true;
    for (const [key, value] of Object.entries({ token: a.token, _token: token })) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = value; form.append(input); }
    document.body.append(form); try { HTMLFormElement.prototype.submit.call(form); setMessage('A download attempt was submitted. Check browser downloads; an interrupted attempt can be consumed without file receipt.'); }
    catch { setMessage(unknown); } finally { form.querySelectorAll('input').forEach(f => { f.value = ''; }); form.remove(); }
  }
  const shown = origin ? issued.filter(i => i.originId === origin.id).map(i => i.auth) : [];
  const retryable = denied || !origin || status?.originId !== origin.id ? [] : kept.current.filter(m => m.refused).map(m => m.auth)
    .filter(r => shown.every(a => a.id !== r.id) && status.lines.some(l => l.history.some(h => h.id === r.id && h.kind === r.kind && h.status === 'unused')));
  const unfinished = origin?.lines.filter(l => l.documentStatus !== 'complete') ?? [];
  const retryAllowed = unfinished.length > 0 && unfinished.every(l => l.attempts < 5 && (l.documentStatus !== 'claimed' || status?.lines.find(s => s.id === l.id)?.renderRetryAllowed === true));
  return <section aria-label="Paid license journey" aria-busy={busy}>
    <p>Your original accepted license and exact purchased files stay together. Every line must finish preparation before an order can download. A declared buyer name is not verified legal identity.</p>
    {message && <div role="alert" tabIndex={-1} ref={alert} className="customer-account-message">{message}{denied && <a href="/customer/sign-in">Open a fresh sign-in page</a>}</div>}
    <button type="button" disabled={busy || denied} onClick={refresh}>{data ? 'Refresh paid licenses' : 'Open paid licenses'}</button>
    {pending && !denied && <div><p>Review saved licenses before deliberately retrying this exact request.</p><button type="button" disabled={busy || !reviewedSaved} onClick={() => execute(pending)}>Retry the exact request</button>
      {!pending.orderId && <button type="button" disabled={busy || !abandonable} onClick={abandon}>Set aside and request a new authorization</button>}</div>}
    {!denied && <form onSubmit={e => { e.preventDefault(); finalize(); }}><label htmlFor="paid-order-reference">Saved order reference</label>
      <input id="paid-order-reference" value={order} maxLength={36} autoComplete="off" disabled={busy || !!pending} onChange={e => setOrder(e.target.value)} />
      <button type="submit" disabled={busy || !!pending || !uuid(order)}>Prepare licenses for this paid order</button></form>}
    {data && <div aria-label="Saved paid licenses"><p>Latest {data.originLimit} saved orders. An order reference can open an older original.</p>
      {data.origins.map(o => <button type="button" key={o.id} disabled={busy} onClick={() => open(o.id, o.orderId)}>Open saved order {o.orderId}</button>)}{data.origins.length === 0 && <p>No saved paid licenses.</p>}</div>}
    {origin && <section aria-label="Retained paid order"><p className="paid-license-reference">Order {origin.orderId}</p>
      {origin.provenance === 'synthetic_rehearsal' && <p>Rehearsal original. No real payment or production legal facts are certified.</p>}
      <p>{origin.fulfilled ? 'Complete-order preparation is recorded. Exact files are checked again for each download.' : 'This order is waiting for complete preparation. No line can download yet.'}</p>
      {unfinished.length > 0 && <button type="button" disabled={busy || !retryAllowed} onClick={prepare}>Prepare original licenses and files</button>}
      <button type="button" disabled={busy} onClick={savedStatus}>Refresh preparation and download status</button>
      {origin.lines.map(l => <article className="paid-license-line" key={l.id}><h2>{l.title}</h2><p>{l.currency} {(l.lineAmountMinor / 100).toFixed(2)} + tax {(l.lineTaxMinor / 100).toFixed(2)} from the original accepted order.</p>
        <p>Declared name: {l.declaredName}</p><p>License accepted: {l.assentedAt}</p><p>Original PDF: {l.documentStatus}; preparation attempts {l.attempts}/5.</p>
        <details><summary>{l.license.name} / retained version {l.license.version}</summary><pre className="paid-license-terms">{l.license.termsText}</pre></details>
        {status?.lines.filter(s => s.id === l.id).map(s => <div key={s.id} aria-label={`Download status for ${l.title}`}><p>{s.attemptCount}/{s.maxDownloads} committed download attempts. This does not confirm file receipt.</p>
          {s.renderRetryAfter && <p>Preparation retry check: {s.renderRetryAfter} UTC. Refresh after this time.</p>}<ul>{s.history.map(h => <li key={h.id}>{h.kind} / {h.status}{h.attemptedAt && ` / ${h.attemptedAt} UTC`}</li>)}</ul></div>)}
        {l.files.map(f => <div key={f.kind}><p>{f.kind} / {f.sizeBytes} bytes / SHA256 {f.sha256}</p><button type="button" disabled={busy || !!pending} onClick={() => authorize(l.id, f.kind)}>Authorize {f.kind} for {l.title}</button></div>)}
      </article>)}
      {shown.map(a => <div key={a.id}><p>{a.filename}; expires {a.expiresAt} UTC.</p><button type="button" disabled={busy} onClick={() => submit(a)}>Download authorized file</button></div>)}
      {retryable.map(r => <div key={r.id}><p>{r.filename} is still authorized and unused; expires {r.expiresAt} UTC.</p><button type="button" disabled={busy} onClick={() => retryDownload(r)}>Retry the authorized download</button></div>)}
    </section>}
  </section>;
}
