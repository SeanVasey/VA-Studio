import { useEffect, useRef, useState } from 'react';
import { fileRoleLabels, formatMoney, savedCartSelection, type CartLine } from '../lib/catalog';

interface ReviewedItem {
  trackId: string | number; offerId: string | number; offerRevisionId: string | number; licenseVersionId: string | number;
  title: string; artist: string; licenseName: string; priceMinor: number; currency: string; deliverableRoles: string[]; features: string[];
}
interface ReviewedQuote {
  id: string; expiresAt: string; currency: string; subtotalMinor: number;
  taxMinor: null; totalMinor: null; taxStatus: 'unresolved'; payable: false; items: ReviewedItem[];
}
const ids = ['trackId', 'offerId', 'offerRevisionId', 'licenseVersionId'] as const;
const selectionKey = (items: Array<ReturnType<typeof savedCartSelection> | ReviewedItem>) => JSON.stringify(items.map(item => ids.map(key => String(item[key]))).sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b))));
const storageKey = 'vaseyaudio-quote-attempt-v1';

function attemptKey(selection: string): string {
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null');
    if (saved?.selection === selection && typeof saved.key === 'string' && /^[a-f0-9-]{36}$/.test(saved.key)) return saved.key;
  } catch { /* A blocked browser store does not prevent an in-memory retry. */ }
  const key = crypto.randomUUID();
  try { sessionStorage.setItem(storageKey, JSON.stringify({ selection, key })); } catch { /* Optional tab persistence. */ }
  return key;
}

function validQuote(value: unknown, selection: string): value is ReviewedQuote {
  if (!value || typeof value !== 'object') return false;
  const q = value as ReviewedQuote;
  return typeof q.id === 'string' && q.id.length > 0 && Number.isFinite(Date.parse(q.expiresAt))
    && q.currency === 'USD' && Number.isSafeInteger(q.subtotalMinor) && q.subtotalMinor > 0
    && q.taxMinor === null && q.totalMinor === null && q.taxStatus === 'unresolved' && q.payable === false
    && Array.isArray(q.items) && q.items.length > 0 && q.items.length <= 10 && q.items.every(item => item && ids.every(key => typeof item[key] === 'string' || typeof item[key] === 'number')
      && typeof item.title === 'string' && typeof item.artist === 'string' && typeof item.licenseName === 'string'
      && item.currency === q.currency && Number.isSafeInteger(item.priceMinor) && item.priceMinor > 0
      && Array.isArray(item.deliverableRoles) && item.deliverableRoles.every(role => typeof role === 'string')
      && Array.isArray(item.features) && item.features.every(feature => typeof feature === 'string'))
    && q.items.reduce((sum, item) => sum + item.priceMinor, 0) === q.subtotalMinor && selectionKey(q.items) === selection;
}

export function QuoteReview({ lines, designPreview }: { lines: CartLine[]; designPreview: boolean }) {
  const items = lines.map(savedCartSelection);
  const selection = selectionKey(items);
  const latestSelection = useRef(selection);
  latestSelection.current = selection;
  const attempt = useRef<{ selection: string; key: string } | null>(null);
  const generation = useRef(0);
  const [quote, setQuote] = useState<ReviewedQuote | null>(null);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [expired, setExpired] = useState(false);

  useEffect(() => {
    generation.current++;
    setQuote(null); setExpired(false); setMessage(''); setBusy(false);
    return () => { generation.current++; };
  }, [selection]);
  useEffect(() => {
    if (!quote) return;
    const delay = Date.parse(quote.expiresAt) - Date.now();
    if (delay <= 0) { setExpired(true); return; }
    const timer = window.setTimeout(() => setExpired(true), Math.min(delay, 2147483647));
    return () => window.clearTimeout(timer);
  }, [quote]);

  async function review() {
    if (busy || designPreview || !items.length || items.length > 10) return;
    const requestGeneration = ++generation.current;
    setBusy(true); setMessage(''); setQuote(null); setExpired(false);
    try {
      if (!attempt.current || attempt.current.selection !== selection) attempt.current = { selection, key: attemptKey(selection) };
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const response = await fetch('/quotes', {
        method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': attempt.current.key, ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
        body: JSON.stringify({ items }),
      });
      if (requestGeneration !== generation.current || selection !== latestSelection.current) return;
      if (!response.ok) {
        if ([404, 409, 410].includes(response.status)) {
          attempt.current = null;
          try { sessionStorage.removeItem(storageKey); } catch { /* Optional persistence. */ }
          setMessage(response.status === 410 ? 'This review expired. Review your selection again.' : 'Your selection changed or is no longer available. Refresh the catalog and choose its licenses again.');
        } else setMessage(response.status === 429 ? 'Too many review requests. Wait a minute, then try again.' : response.status === 419 ? 'Your session expired. Refresh the page before reviewing again.' : 'The selection could not be reviewed. Please try again.');
        return;
      }
      const body: unknown = await response.json();
      if (requestGeneration !== generation.current || selection !== latestSelection.current) return;
      const result = body && typeof body === 'object' && 'quote' in body ? body.quote : null;
      if (!validQuote(result, selection)) throw new Error('Invalid review response');
      setQuote(result);
    } catch {
      if (requestGeneration === generation.current) setMessage('Unable to complete the review. Your selections are still here; try again.');
    } finally {
      if (requestGeneration === generation.current) setBusy(false);
    }
  }

  function restart() {
    attempt.current = null;
    try { sessionStorage.removeItem(storageKey); } catch { /* Optional persistence. */ }
    void review();
  }

  return <section className="quote-review" aria-label="Selection review">
    {quote && !expired ? <div className="quote-review-result">
      <h3>SELECTION REVIEWED</h3>
      {quote.items.map(item => <div className="quote-review-item" key={String(item.trackId)}><strong>{item.title}</strong><p>{item.licenseName} · {formatMoney(item.priceMinor, item.currency)}</p><p>{item.deliverableRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</p><ul>{item.features.map((feature, index) => <li key={index}>{feature}</li>)}</ul></div>)}
      <div className="cart-total"><span>Reviewed subtotal</span><strong>{formatMoney(quote.subtotalMinor, quote.currency)} <small>{quote.currency}</small></strong></div>
      <p className="fine-print">Review expires at <time dateTime={quote.expiresAt}>{new Date(quote.expiresAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</time>. Availability can change. Tax and final total are not determined. This review does not reserve rights or create an order.</p>
    </div> : null}
    {expired && <p role="status">This review expired. Review your selection again.</p>}
    <button className="button button-outline full-width" onClick={expired ? restart : review} disabled={busy || designPreview || items.length > 10}>{busy ? 'Reviewing selection…' : quote ? 'Review selection again' : 'Review selection'}</button>
    {designPreview && <p className="fine-print">Selection review is unavailable for the sample catalog.</p>}
    {items.length > 10 && <p className="fine-print">Review up to 10 tracks at a time.</p>}
    <p className="checkout-status" role="status">{message}</p>
  </section>;
}
