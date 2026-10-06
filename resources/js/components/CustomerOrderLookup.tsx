import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import type { OrderSummary } from './OrderPreparation';
import { lookupCustomerOrder } from '../lib/customer-order-reference';

export function CustomerOrderLookup({ renderOrder }: { renderOrder: (order: OrderSummary) => ReactNode }) {
  const [reference, setReference] = useState('');
  const [order, setOrder] = useState<OrderSummary | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [signIn, setSignIn] = useState(false);
  const active = useRef(false), generation = useRef(0), pending = useRef(false);
  const controller = useRef<AbortController | null>(null);
  const field = useRef<HTMLInputElement>(null), heading = useRef<HTMLHeadingElement>(null), alert = useRef<HTMLDivElement>(null);

  function clearResult() {
    ++generation.current; controller.current?.abort(); controller.current = null; pending.current = false;
    setOrder(null); setMessage(''); setSignIn(false); setBusy(false);
  }
  function clear() { clearResult(); setReference(''); }
  useEffect(() => {
    active.current = true;
    window.addEventListener('pagehide', clear);
    return () => { active.current = false; ++generation.current; controller.current?.abort(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => { if (order) heading.current?.focus(); else if (message) alert.current?.focus(); }, [order, message]);

  async function find(event: FormEvent) {
    event.preventDefault();
    if (pending.current) return;
    clearResult(); pending.current = true; setBusy(true);
    const current = generation.current, abort = new AbortController(); controller.current = abort;
    const timer = window.setTimeout(() => abort.abort(), 20_000);
    try {
      const result = await lookupCustomerOrder(reference, abort.signal);
      if (!active.current || generation.current !== current) return;
      if (result.kind === 'found') setOrder(result.order);
      else { setMessage(result.message); setSignIn(result.kind === 'reload'); }
    } finally {
      window.clearTimeout(timer);
      if (generation.current === current) {
        controller.current = null; pending.current = false;
        if (active.current) setBusy(false);
      }
    }
  }

  return <section className="customer-order-lookup" aria-label="Find an account order" aria-busy={busy}>
    <h2>Find an earlier order</h2>
    <p id="customer-order-reference-help" className="fine-print">Use the complete order reference shown in your account history. Only orders belonging to this account can be opened.</p>
    <form className="customer-order-lookup-form" onSubmit={event => void find(event)}>
      <div className="customer-account-field">
        <label htmlFor="customer-order-reference">Order reference</label>
        <input id="customer-order-reference" ref={field} type="text" value={reference} required maxLength={64}
          autoComplete="off" autoCapitalize="none" autoCorrect="off" spellCheck={false} aria-describedby="customer-order-reference-help"
          onChange={event => { clearResult(); setReference(event.target.value); }} />
      </div>
      <div className="customer-order-lookup-actions">
        <button className="button button-outline" type="submit" disabled={busy || signIn}>{busy ? 'Finding order…' : 'Find order'}</button>
        <button className="button button-outline" type="button" disabled={!reference && !order && !message && !busy}
          onClick={() => { clear(); field.current?.focus(); }}>Clear order lookup</button>
      </div>
    </form>
    {message && <div className="customer-account-message" role="alert" tabIndex={-1} ref={alert}>{message}
      {signIn && <a href="/account/sign-in">Open a fresh sign-in page</a>}
    </div>}
    {order && <div className="customer-order-lookup-result"><h3 tabIndex={-1} ref={heading}>Order found</h3>{renderOrder(order)}</div>}
  </section>;
}
