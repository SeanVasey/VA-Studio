import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { purchaseReference, savePurchase, type PendingPurchaseClaim } from '../lib/purchase-claim';

export function CustomerPurchaseClaim({ claim }: { claim?: PendingPurchaseClaim }) {
  const id = useId();
  const [reference, setReference] = useState(claim?.orderId ?? '');
  const [busy, setBusy] = useState(false), [saved, setSaved] = useState(claim?.saved ?? false), [message, setMessage] = useState('');
  const [hidden, setHidden] = useState(false);
  const active = useRef(false), pending = useRef(false), generation = useRef(0);
  const controller = useRef<AbortController | null>(null), status = useRef<HTMLParagraphElement>(null);
  useEffect(() => {
    active.current = true;
    const clear = () => { generation.current++; controller.current?.abort(); setHidden(true); setReference(''); setMessage(''); };
    window.addEventListener('pagehide', clear);
    return () => { active.current = false; generation.current++; controller.current?.abort(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => { if (message) status.current?.focus(); }, [message]);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending.current || saved || hidden || !purchaseReference(reference)) return;
    pending.current = true; setBusy(true); setMessage('');
    const own = ++generation.current, abort = new AbortController(); controller.current = abort;
    const timeout = window.setTimeout(() => abort.abort(), 20_000);
    try {
      const accepted = await savePurchase(claim ? 'complete' : 'stage', reference, abort.signal);
      if (!active.current || own !== generation.current || abort.signal.aborted) return;
      setSaved(accepted);
      setMessage(accepted ? claim ? 'Saved to your test account. Reload your library to view this purchase.'
        : 'Purchase selected. Sign in below, then confirm saving this exact purchase within 10 minutes.'
        : 'This purchase could not be saved. Check the reference, original browser session and current account, then try again.');
    } finally {
      window.clearTimeout(timeout);
      if (active.current && own === generation.current) {
        pending.current = false; setBusy(false); controller.current = null;
        if (abort.signal.aborted) setMessage('The request could not be confirmed. Try the same purchase again.');
      }
    }
  }
  if (hidden) return null;
  return <section className="customer-account-note" aria-label={claim ? 'Save selected guest purchase' : 'Save a guest purchase'}>
    <h2>{claim ? 'Save this test purchase' : 'Bought as a guest?'}</h2>
    <p>{claim ? 'Save only the purchase below to this signed-in account. Its original terms, prices and files stay unchanged.'
      : 'In the original purchase browser, enter one completed test-order reference before signing in. You will confirm saving it after sign-in.'}</p>
    {claim && <p>Available until {new Date(claim.expiresAt).toLocaleTimeString()}.</p>}
    <form onSubmit={event => void submit(event)} aria-busy={busy}>
      <div className="customer-account-field"><label htmlFor={`${id}-reference`}>Guest test-order reference</label>
        <input id={`${id}-reference`} value={reference} required maxLength={36} readOnly={!!claim} disabled={busy || saved}
          autoComplete="off" spellCheck={false} onChange={event => { setReference(event.target.value); setMessage(''); }} /></div>
      {!saved && <button type="submit" className="button button-outline" disabled={busy || !purchaseReference(reference)}>{busy ? 'Saving…' : claim ? 'Save purchase to this account' : 'Select purchase before sign-in'}</button>}
    </form>
    {message && <p role="status" tabIndex={-1} ref={status}>{message}</p>}
    {saved && claim && <a className="text-link" href="/account">Reload your library</a>}
  </section>;
}
