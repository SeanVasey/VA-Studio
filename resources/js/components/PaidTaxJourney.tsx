import { useEffect, useRef, useState } from 'react';

/**
 * Tax license history journey: index -> show -> document/status -> authorize -> native-form redeem.
 *
 * Every private value comes from the default-off JSON endpoints under /paid-tax-grants, never from Inertia props. Each
 * answer is shape-checked before it is shown. A download token lives only in component memory and in one hidden native
 * form POST into a hidden frame: it is never rendered, put in a URL or history entry, or written to storage.
 */
type Kind = 'contract' | 'master_wav' | 'download_mp3' | 'stems_zip';
interface License { licenseVersionId: string; name: string; version: number; type: 'non-exclusive'; features: string[]; deliverableRoles: Exclude<Kind, 'contract'>[]; termsText: string }
interface PreTax { currency: 'USD'; line_amount_minor: number; order_subtotal_minor: number }
interface TaxAmounts {
  authority: 'provider_calculated_buyer_reviewed'; calculator: 'stripe_checkout_automatic_tax'; automatic_tax: { enabled: true; status: 'complete'; provider: string | null };
  tax_behavior: 'exclusive' | 'inclusive'; currency: 'USD'; line_subtotal_minor: number; line_tax_minor: number; line_total_minor: number;
  order_subtotal_minor: number; order_tax_minor: number; order_total_minor: number;
}
export interface TaxLine {
  id: string; originHash: string; position: number; title: string; license: License; declaredName: string; assentedAt: string; preTax: PreTax; tax: TaxAmounts;
  documentStatus: 'pending' | 'claimed' | 'failed' | 'complete'; attempts: number; files: { kind: Kind; sha256: string; sizeBytes: number }[];
}
export interface TaxOrigin { id: string; orderId: string; purpose: 'paid-tax-license-grant'; schemaVersion: 2; provenance: 'synthetic_rehearsal'; fulfilled: boolean; lines: TaxLine[] }
export interface TaxListing { schemaVersion: 2; originLimit: 20; hasMore: boolean; origins: { id: string; orderId: string; createdAt: string; lineCount: number; provenance: 'synthetic_rehearsal' }[] }
interface LineStatus { id: string; attemptCount: number; maxDownloads: number; historyLimit: 20; renderRetryAllowed: boolean; renderRetryAfter: string | null;
  history: { id: string; kind: Kind; issuedAt: string; expiresAt: string; status: 'attempted' | 'unused' | 'expired'; attemptedAt: string | null }[] }
interface TaxStatus { schemaVersion: 2; originId: string; fulfilled: boolean; lines: LineStatus[] }
interface Authorization { id: string; token: string; expiresAt: string; kind: Kind; filename: string; mimeType: string }
/** issued: never submitted; submitted: its native form was sent; refused: its response frame reported a refusal. */
interface Issued { auth: Authorization; originId: string; orderId: string; state: 'issued' | 'submitted' | 'refused' }
interface Pending { path: string; body: Record<string, unknown>; origin: TaxOrigin; lineId: string }

const obj = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object' && !Array.isArray(x);
const exact = (x: Record<string, unknown>, keys: string[]) => Object.keys(x).length === keys.length && keys.every(k => Object.hasOwn(x, k));
const uuid = (x: unknown): x is string => typeof x === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(x);
const hash = (x: unknown): x is string => typeof x === 'string' && /^[a-f0-9]{64}$/.test(x);
const text = (x: unknown, max = 1_048_576): x is string => typeof x === 'string' && x.length > 0 && x.length <= max;
const int = (x: unknown, min: number, max: number): x is number => Number.isSafeInteger(x) && (x as number) >= min && (x as number) <= max;
const kind = (x: unknown): x is Kind => ['contract', 'master_wav', 'download_mp3', 'stems_zip'].includes(String(x));
const sqlDate = (x: unknown): x is string => typeof x === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(x);
const isoDate = (x: unknown): x is string => typeof x === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(x) && Number.isFinite(Date.parse(x));
const media = { contract: ['.pdf', 'application/pdf'], master_wav: ['.wav', 'audio/wav'], download_mp3: ['.mp3', 'audio/mpeg'], stems_zip: ['.zip', 'application/zip'] } as const;
const minor = (x: unknown): x is number => int(x, 0, Number.MAX_SAFE_INTEGER);

function license(x: unknown): x is License {
  return obj(x) && exact(x, ['licenseVersionId', 'name', 'version', 'type', 'features', 'deliverableRoles', 'termsText'])
    && text(x.licenseVersionId, 20) && text(x.name, 192) && int(x.version, 1, 100000) && x.type === 'non-exclusive'
    && Array.isArray(x.features) && x.features.length <= 100 && x.features.every(t => text(t, 8192))
    && Array.isArray(x.deliverableRoles) && x.deliverableRoles.length >= 1 && x.deliverableRoles.length <= 3 && x.deliverableRoles.every(k => kind(k) && k !== 'contract')
    && new Set(x.deliverableRoles).size === x.deliverableRoles.length && text(x.termsText);
}
function amounts(pre: unknown, tax: unknown): boolean {
  if (!obj(pre) || !exact(pre, ['currency', 'line_amount_minor', 'order_subtotal_minor']) || pre.currency !== 'USD' || !int(pre.line_amount_minor, 1, Number.MAX_SAFE_INTEGER)
    || !int(pre.order_subtotal_minor, 1, Number.MAX_SAFE_INTEGER)) return false;
  if (!obj(tax) || !exact(tax, ['authority', 'calculator', 'automatic_tax', 'tax_behavior', 'currency', 'line_subtotal_minor', 'line_tax_minor', 'line_total_minor',
    'order_subtotal_minor', 'order_tax_minor', 'order_total_minor']) || tax.authority !== 'provider_calculated_buyer_reviewed'
    || tax.calculator !== 'stripe_checkout_automatic_tax' || tax.currency !== 'USD' || (tax.tax_behavior !== 'exclusive' && tax.tax_behavior !== 'inclusive')) return false;
  const auto = tax.automatic_tax;
  if (!obj(auto) || !exact(auto, ['enabled', 'status', 'provider']) || auto.enabled !== true || auto.status !== 'complete' || !(auto.provider === null || text(auto.provider, 255))) return false;
  const exclusive = tax.tax_behavior === 'exclusive';
  return ['line', 'order'].every(scope => {
    const subtotal = tax[`${scope}_subtotal_minor`], taxed = tax[`${scope}_tax_minor`], total = tax[`${scope}_total_minor`];
    return int(subtotal, 1, Number.MAX_SAFE_INTEGER) && minor(taxed) && minor(total)
      && total === (exclusive ? subtotal + taxed : subtotal) && (exclusive || taxed <= subtotal);
  }) && tax.line_subtotal_minor === pre.line_amount_minor && tax.order_subtotal_minor === pre.order_subtotal_minor;
}
/** Exact Tax projection shape; anything else is refused before display. */
export function validTaxOrigin(x: unknown): x is TaxOrigin {
  if (!obj(x) || !exact(x, ['id', 'orderId', 'purpose', 'schemaVersion', 'provenance', 'fulfilled', 'lines']) || !uuid(x.id) || !uuid(x.orderId)
    || x.purpose !== 'paid-tax-license-grant' || x.schemaVersion !== 2 || x.provenance !== 'synthetic_rehearsal' || typeof x.fulfilled !== 'boolean'
    || !Array.isArray(x.lines) || x.lines.length < 1 || x.lines.length > 10) return false;
  return new Set(x.lines.map(l => obj(l) ? l.id : null)).size === x.lines.length && x.lines.every((l, i) => {
    if (!obj(l) || !exact(l, ['id', 'originHash', 'position', 'title', 'license', 'declaredName', 'assentedAt', 'preTax', 'tax', 'documentStatus', 'attempts', 'files'])
      || !uuid(l.id) || !hash(l.originHash) || l.position !== i + 1 || !text(l.title, 256) || !license(l.license) || !text(l.declaredName, 120) || !isoDate(l.assentedAt)
      || !amounts(l.preTax, l.tax) || !['pending', 'claimed', 'failed', 'complete'].includes(String(l.documentStatus)) || !int(l.attempts, 0, 5) || !Array.isArray(l.files)) return false;
    if (!x.fulfilled) return l.files.length === 0;
    const files = l.files;
    return l.documentStatus === 'complete' && files.length === l.license.deliverableRoles.length + 1 && files.every(f => obj(f)
      && exact(f, ['kind', 'sha256', 'sizeBytes']) && kind(f.kind) && hash(f.sha256) && int(f.sizeBytes, 1, f.kind === 'contract' ? 16777216 : 1073741824))
      && new Set(files.map(f => f.kind)).size === files.length && files.some(f => f.kind === 'contract') && l.license.deliverableRoles.every(k => files.some(f => f.kind === k));
  });
}
/** Exact bounded locator list: at most 20 unique entries plus a boolean sentinel. */
export function validTaxListing(x: unknown): x is TaxListing {
  return obj(x) && exact(x, ['schemaVersion', 'originLimit', 'hasMore', 'origins']) && x.schemaVersion === 2 && x.originLimit === 20 && typeof x.hasMore === 'boolean'
    && Array.isArray(x.origins) && x.origins.length <= 20 && (!x.hasMore || x.origins.length === 20)
    && new Set(x.origins.map(o => obj(o) ? o.id : null)).size === x.origins.length
    && x.origins.every(o => obj(o) && exact(o, ['id', 'orderId', 'createdAt', 'lineCount', 'provenance']) && uuid(o.id) && uuid(o.orderId) && isoDate(o.createdAt)
      && int(o.lineCount, 1, 10) && o.provenance === 'synthetic_rehearsal');
}
export function validTaxStatus(x: unknown, origin: TaxOrigin): x is TaxStatus {
  return obj(x) && exact(x, ['schemaVersion', 'originId', 'fulfilled', 'lines']) && x.schemaVersion === 2 && x.originId === origin.id && typeof x.fulfilled === 'boolean'
    && Array.isArray(x.lines) && x.lines.length === origin.lines.length && x.lines.every((l, i) => obj(l)
      && exact(l, ['id', 'attemptCount', 'maxDownloads', 'historyLimit', 'history', 'renderRetryAllowed', 'renderRetryAfter']) && l.id === origin.lines[i].id
      && int(l.attemptCount, 0, 100) && int(l.maxDownloads, 1, 100) && l.attemptCount <= l.maxDownloads && l.historyLimit === 20
      && typeof l.renderRetryAllowed === 'boolean' && (l.renderRetryAfter === null || sqlDate(l.renderRetryAfter)) && Array.isArray(l.history) && l.history.length <= 20
      && l.history.every(h => obj(h) && exact(h, ['id', 'kind', 'issuedAt', 'expiresAt', 'status', 'attemptedAt']) && uuid(h.id) && kind(h.kind)
        && sqlDate(h.issuedAt) && sqlDate(h.expiresAt) && ['attempted', 'unused', 'expired'].includes(String(h.status)) && (h.status === 'attempted' ? sqlDate(h.attemptedAt) : h.attemptedAt === null)));
}
/** The authorization for exactly the requested line and kind, with the server's fixed filename and media type. */
export function validTaxAuthorization(x: unknown, line: TaxLine, requested: Kind): x is Authorization {
  return obj(x) && exact(x, ['id', 'token', 'expiresAt', 'kind', 'filename', 'mimeType']) && uuid(x.id) && typeof x.token === 'string' && /^[A-Za-z0-9_-]{43}$/.test(x.token)
    && sqlDate(x.expiresAt) && kind(x.kind) && x.kind === requested && line.files.some(f => f.kind === requested)
    && x.filename === `paid-tax-license-${line.id}-${requested}${media[requested][0]}` && x.mimeType === media[requested][1];
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
const unknown = 'The result could not be confirmed. Refresh saved Tax licenses before deliberately retrying the same request.';
/** Client aborts wait past the server budgets (60 s reads/authorize, the 300 s render lease) plus the 5 s session-lock wait and a margin. */
export const taxRequestTimeouts = { read: 80_000, authorize: 80_000, document: 320_000 } as const;
type Operation = keyof typeof taxRequestTimeouts;
type Outcome = 'ok' | 'lost' | 'refused' | 'denied' | 'invalid' | 'skipped';
const usd = (value: number) => (value / 100).toFixed(2);
const ready = (o: TaxOrigin) => o.lines.filter(l => l.documentStatus === 'complete').length;

export function PaidTaxJourney() {
  const [list, setList] = useState<TaxListing | null>(null), [origin, setOrigin] = useState<TaxOrigin | null>(null), [status, setStatus] = useState<TaxStatus | null>(null);
  const [issued, setIssued] = useState<Issued[]>([]), [pending, setPending] = useState<Pending | null>(null), [reviewedSaved, setReviewedSaved] = useState(false);
  const [busy, setBusy] = useState(false), [denied, setDenied] = useState(false), [message, setMessage] = useState(''), [progress, setProgress] = useState('');
  const active = useRef(false), generation = useRef(0), request = useRef<AbortController | null>(null), inflight = useRef(false);
  const frames = useRef<HTMLIFrameElement[]>([]), alert = useRef<HTMLDivElement>(null), orderHeading = useRef<HTMLHeadingElement>(null);
  function clearShown(keepFrames = false) { setList(null); setOrigin(null); setStatus(null); setProgress(''); setReviewedSaved(false); if (!keepFrames) { frames.current.forEach(f => f.remove()); frames.current = []; } }
  function refuse() { generation.current++; request.current?.abort(); clearShown(); setPending(null); setIssued([]); setDenied(true); setMessage('Access changed. Open a fresh sign-in page before continuing.'); }
  useEffect(() => {
    active.current = true;
    // Leaving drops everything, tokens included. Hiding the tab clears what is shown and abandons the request in flight,
    // but keeps an uncertain authorize's exact replay and issued tokens in memory (never rendered) so nothing is duplicated.
    const leave = () => { generation.current++; request.current?.abort(); clearShown(); setPending(null); setIssued([]); inflight.current = false; setBusy(false); };
    const visibility = () => { if (document.visibilityState !== 'hidden') return; const interrupted = inflight.current;
      generation.current++; request.current?.abort(); clearShown(true); inflight.current = false; setBusy(false); if (interrupted) setMessage(unknown); };
    window.addEventListener('pagehide', leave); document.addEventListener('visibilitychange', visibility);
    return () => { active.current = false; generation.current++; request.current?.abort(); frames.current.forEach(f => f.remove()); frames.current = [];
      window.removeEventListener('pagehide', leave); document.removeEventListener('visibilitychange', visibility); };
  }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);
  async function call(operation: Operation, path: string, body: Record<string, unknown> | null, commit: (x: unknown) => void): Promise<Outcome> {
    if (inflight.current || denied) return 'skipped'; inflight.current = true; setBusy(true); setMessage('');
    const abort = new AbortController(), mine = ++generation.current; request.current = abort;
    const timer = window.setTimeout(() => abort.abort(), taxRequestTimeouts[operation]), owns = () => active.current && mine === generation.current;
    try {
      const token = csrf(); if (body && !token) { refuse(); return 'denied'; }
      const response = await Promise.race([fetch(path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token! } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) }),
      new Promise<never>((_, reject) => abort.signal.addEventListener('abort', () => reject(new Error()), { once: true }))]);
      if (!owns() || abort.signal.aborted) return 'skipped';
      if ([403, 404, 419].includes(response.status)) { refuse(); return 'denied'; }
      const value = await readJson(response, abort.signal); if (!owns() || abort.signal.aborted) return 'skipped';
      // A closure can deny before private bytes after 200 headers already left the server.
      if (obj(value) && exact(value, ['error', 'status']) && value.error === 'Paid Tax request unavailable.' && Number.isInteger(value.status)) {
        if ([403, 404, 419].includes(value.status as number)) { refuse(); return 'denied'; }
        setMessage(unknown); return 'refused';
      }
      if (!response.ok) { setMessage(unknown); return 'refused'; }
      try { commit(value); } catch { setMessage(unknown); return 'invalid'; }
      return 'ok';
    } catch { if (owns()) { if (!body) { setList(null); setOrigin(null); setStatus(null); } setMessage(unknown); return 'lost'; } return 'skipped'; }
    finally { window.clearTimeout(timer); if (owns()) { inflight.current = false; setBusy(false); request.current = null; } }
  }
  function refresh() { void call('read', '/paid-tax-grants/index', null, x => { if (!validTaxListing(x)) throw new Error(); setList(x); setOrigin(null); setStatus(null); setProgress(''); setReviewedSaved(true); }); }
  async function open(id: string, orderId: string) {
    setOrigin(null); setStatus(null); setProgress('');
    const outcome = await call('read', `/paid-tax-grants/origins/${id}`, null, x => { if (!obj(x) || !exact(x, ['origin']) || !validTaxOrigin(x.origin) || x.origin.id !== id || x.origin.orderId !== orderId) throw new Error(); setOrigin(x.origin); });
    if (outcome === 'ok') window.setTimeout(() => orderHeading.current?.focus(), 0);
  }
  function prepare() {
    if (!origin) return; const before = origin;
    void call('document', `/paid-tax-grants/origins/${before.id}/document`, {}, x => {
      if (!obj(x) || !exact(x, ['origin', 'busy']) || typeof x.busy !== 'boolean' || !validTaxOrigin(x.origin) || x.origin.id !== before.id || x.origin.orderId !== before.orderId
        || x.origin.lines.some((l, i) => l.id !== before.lines[i]?.id || l.originHash !== before.lines[i]?.originHash)) throw new Error();
      const next = x.origin; setOrigin(next); setStatus(null);
      setProgress(next.fulfilled ? 'Your files are ready.' : x.busy ? `Another request is preparing this order (${ready(next)} of ${next.lines.length} lines ready). Refresh status shortly.`
        : `${ready(next)} of ${next.lines.length} lines ready. Choose prepare again to continue.`);
    });
  }
  function savedStatus() { if (!origin) return; const o = origin; void call('read', `/paid-tax-grants/origins/${o.id}/downloads`, null, x => { if (!obj(x) || !exact(x, ['status']) || !validTaxStatus(x.status, o)) throw new Error(); setStatus(x.status); setReviewedSaved(true); }); }
  function execute(p: Pending) {
    void call('authorize', p.path, p.body, x => {
      const line = p.origin.lines.find(l => l.id === p.lineId);
      if (!line || !obj(x) || !exact(x, ['authorization']) || !validTaxAuthorization(x.authorization, line, p.body.kind as Kind)) throw new Error();
      const auth = x.authorization;
      setIssued(all => [...all.filter(i => i.auth.id !== auth.id), { auth, originId: p.origin.id, orderId: p.origin.orderId, state: 'issued' }]);
      setPending(null); setReviewedSaved(false);
    });
  }
  function authorize(line: TaxLine, k: Kind) {
    if (!origin || pending || !origin.fulfilled) return;
    const nonce = Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
    // The exact body (request key and nonce) is kept in memory so an uncertain answer is retried, never duplicated.
    const p: Pending = { path: `/paid-tax-grants/origins/${origin.id}/lines/${line.id}/authorize`, body: { requestKey: crypto.randomUUID(), originHash: line.originHash, kind: k, nonce }, origin, lineId: line.id };
    setPending(p); setReviewedSaved(false); execute(p);
  }
  function submit(entry: Issued) {
    const token = csrf(); if (!token || denied || busy || !origin || entry.originId !== origin.id) return;
    setIssued(all => all.map(i => i.auth.id === entry.auth.id ? { ...i, state: 'submitted' } : i)); setStatus(null);
    const frame = document.createElement('iframe'); frame.name = `paid-tax-${crypto.randomUUID()}`; frame.title = 'Tax license attachment response'; frame.hidden = true; frame.setAttribute('referrerpolicy', 'no-referrer');
    const mine = generation.current;
    frame.addEventListener('load', () => { if (!active.current || !frames.current.includes(frame)) return; try {
      const doc = frame.contentDocument; if (!doc || doc.location.href === 'about:blank') return; const raw = doc.body?.textContent ?? ''; if (raw.length > 4096) throw new Error(); const failure = JSON.parse(raw);
      if (!obj(failure) || failure.code !== 'PAID_TAX_UNAVAILABLE') throw new Error();
      setIssued(all => all.map(i => i.auth.id === entry.auth.id ? { ...i, state: 'refused' } : i)); frame.remove(); frames.current = frames.current.filter(f => f !== frame);
      if (mine === generation.current) setMessage('The download was refused. Refresh download status; an authorization that is still unused can then be retried.');
    } catch { if (mine === generation.current) setMessage('The attachment result could not be confirmed. Check browser downloads and refresh download status.'); } });
    document.body.append(frame); frames.current.push(frame);
    const form = document.createElement('form'); form.method = 'POST'; form.action = `/paid-tax-grants/origins/${entry.originId}/authorizations/${entry.auth.id}/redeem`;
    form.target = frame.name; form.enctype = 'application/x-www-form-urlencoded'; form.hidden = true;
    for (const [key, value] of Object.entries({ token: entry.auth.token, _token: token })) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = value; form.append(input); }
    document.body.append(form);
    try { HTMLFormElement.prototype.submit.call(form); setMessage('A download attempt was submitted. Check browser downloads; an interrupted attempt can be consumed without the file arriving.'); }
    catch { setMessage(unknown); } finally { form.querySelectorAll('input').forEach(f => { f.value = ''; }); form.remove(); }
  }
  const shownIssued = origin ? issued.filter(i => i.originId === origin.id) : [];
  // A refused download is offered again only when a saved status read shows that exact authorization still unused.
  const usable = shownIssued.filter(i => i.state === 'issued' || (i.state === 'refused' && !!status && status.originId === origin?.id
    && status.lines.some(l => l.history.some(h => h.id === i.auth.id && h.kind === i.auth.kind && h.status === 'unused'))));
  const unshown = denied ? [] : issued.filter((i, n, all) => i.state !== 'submitted' && i.originId !== origin?.id && all.findIndex(j => j.state !== 'submitted' && j.originId === i.originId) === n);
  const unfinished = origin?.lines.filter(l => l.documentStatus !== 'complete') ?? [];
  return <div className="paid-grant-journey">
    <p role="status" aria-live="polite" className="paid-preparation-progress">{progress}</p>
    <section aria-labelledby="paid-tax-journey-heading" aria-busy={busy}>
      <h2 id="paid-tax-journey-heading">Your Tax licenses</h2>
      <p>Each saved order keeps its original accepted license, tax amounts and exact purchased files together. Every line must finish preparation before an order can download. A declared buyer name is not verified legal identity.</p>
      {message && <div role="alert" tabIndex={-1} ref={alert} className="customer-account-message">{message} {denied && <a href="/customer/sign-in">Open a fresh sign-in page</a>}</div>}
      <button type="button" disabled={busy || denied} onClick={refresh}>{list ? 'Refresh saved Tax licenses' : 'Open saved Tax licenses'}</button>
      {pending && !denied && <div>
        <p>The last authorization request could not be confirmed. Review saved status before deliberately retrying this exact request.</p>
        <button type="button" disabled={busy || !reviewedSaved} onClick={() => execute(pending)}>Retry the exact request</button>
        <button type="button" disabled={busy} onClick={() => { setPending(null); setReviewedSaved(false); setMessage('The uncertain request was discarded. If it was recorded, that authorization simply expires unused.'); }}>Discard this request</button>
      </div>}
      {unshown.map(i => <button type="button" key={i.originId} disabled={busy} onClick={() => void open(i.originId, i.orderId)}>Reopen order {i.orderId} to download its authorized file</button>)}
      {list && <section aria-labelledby="paid-tax-history-heading">
        <h2 id="paid-tax-history-heading">Saved Tax license orders</h2>
        <p>Newest {list.originLimit} saved orders.{list.hasMore && ' Older saved orders exist and are not shown here.'}</p>
        {list.origins.length === 0 ? <p>No saved Tax licenses yet.</p> : <ul>{list.origins.map(o => <li key={o.id}>
          <button type="button" disabled={busy} onClick={() => void open(o.id, o.orderId)}>Open saved order {o.orderId}</button>
          <span> Saved {o.createdAt.replace('T', ' ').replace('Z', '')} UTC; {o.lineCount} {o.lineCount === 1 ? 'license line' : 'license lines'}.</span></li>)}</ul>}
      </section>}
      {origin && <section aria-labelledby="paid-tax-order-heading">
        <h2 id="paid-tax-order-heading" tabIndex={-1} ref={orderHeading} className="paid-license-reference">Order {origin.orderId}</h2>
        <p>Rehearsal original. No real payment or production legal facts are certified.</p>
        <p>{origin.fulfilled ? 'Complete-order preparation is recorded. Exact files are checked again for each download.' : 'This order is waiting for complete preparation. No line can download yet.'}</p>
        {unfinished.length > 0 && <button type="button" disabled={busy} onClick={prepare}>Prepare original licenses and files</button>}
        {!origin.fulfilled && unfinished.length === 0 && <button type="button" disabled={busy} onClick={prepare}>Finish preparing this order</button>}
        <button type="button" disabled={busy} onClick={savedStatus}>Refresh preparation and download status</button>
        {origin.lines.map(l => <article className="paid-license-line" key={l.id} aria-labelledby={`paid-tax-line-${l.id}`}>
          <h3 id={`paid-tax-line-${l.id}`}>{l.title}</h3>
          <p>USD {usd(l.tax.line_subtotal_minor)} + tax {usd(l.tax.line_tax_minor)} ({l.tax.tax_behavior}) = {usd(l.tax.line_total_minor)}, from the original accepted order.</p>
          <p>Declared name: {l.declaredName}</p><p>License accepted: {l.assentedAt.replace('T', ' ').replace('Z', '')} UTC</p>
          <p>Original PDF: {l.documentStatus}; preparation attempts {l.attempts}/5.</p>
          <details><summary>{l.license.name} / retained version {l.license.version}</summary><pre className="paid-license-terms">{l.license.termsText}</pre></details>
          {status?.lines.filter(s => s.id === l.id).map(s => <div key={s.id} aria-label={`Download status for ${l.title}`}>
            <p>{s.attemptCount}/{s.maxDownloads} committed download attempts. This does not confirm file receipt.</p>
            {s.renderRetryAfter && <p>Preparation retry check: {s.renderRetryAfter} UTC. Refresh after this time.</p>}
            <ul>{s.history.map(h => <li key={h.id}>{h.kind} / {h.status}{h.attemptedAt && ` / ${h.attemptedAt} UTC`}</li>)}</ul></div>)}
          {l.files.map(f => <div key={f.kind}><p>{f.kind} / {f.sizeBytes} bytes / SHA256 {f.sha256}</p>
            <button type="button" disabled={busy || !!pending} onClick={() => authorize(l, f.kind)}>Authorize {f.kind} for {l.title}</button></div>)}
        </article>)}
        {usable.map(i => <div key={i.auth.id}><p>{i.auth.filename}; expires {i.auth.expiresAt} UTC.{i.state === 'refused' && ' Saved status shows it is still unused.'}</p>
          <button type="button" disabled={busy} onClick={() => submit(i)}>{i.state === 'refused' ? 'Retry the authorized download' : 'Download authorized file'}</button></div>)}
      </section>}
    </section>
  </div>;
}
