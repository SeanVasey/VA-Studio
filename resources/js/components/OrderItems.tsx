import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '../lib/catalog';
import { readOrderItems, type RetainedOrderItems } from '../lib/order-items';
import '../../css/order-items.css';

export function OrderItems(props: { orderId: string; expectedTotalMinor: number }) {
  return <Items key={`${props.orderId}:${props.expectedTotalMinor}`} {...props} />;
}

function Items({ orderId, expectedTotalMinor }: { orderId: string; expectedTotalMinor: number }) {
  const [items, setItems] = useState<RetainedOrderItems | null>(null);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [signIn, setSignIn] = useState(false);
  const active = useRef(false), pending = useRef(false), generation = useRef(0);
  const restoreFocus = useRef(false);
  const controller = useRef<AbortController | null>(null);
  const trigger = useRef<HTMLButtonElement>(null), heading = useRef<HTMLHeadingElement>(null), alert = useRef<HTMLDivElement>(null);
  function clear() {
    ++generation.current; controller.current?.abort(); controller.current = null; pending.current = false;
    setItems(null); setBusy(false); setMessage(''); setSignIn(false);
  }
  useEffect(() => {
    active.current = true; window.addEventListener('pagehide', clear);
    return () => { active.current = false; ++generation.current; controller.current?.abort(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => { if (items) heading.current?.focus(); else if (message) alert.current?.focus(); }, [items, message]);
  useEffect(() => {
    if (restoreFocus.current && !busy && !signIn) { restoreFocus.current = false; trigger.current?.focus(); }
  }, [busy, signIn, items, message]);
  async function load() {
    if (pending.current) return;
    clear(); pending.current = true; setBusy(true);
    const current = generation.current, abort = new AbortController(); controller.current = abort;
    const timer = window.setTimeout(() => abort.abort(), 20_000);
    try {
      const result = await readOrderItems(orderId, expectedTotalMinor, abort.signal);
      if (!active.current || current !== generation.current) return;
      if (result.kind === 'loaded') setItems(result.items);
      else { setMessage(result.message); setSignIn(result.kind === 'reload'); }
    } finally {
      window.clearTimeout(timer);
      if (current === generation.current) { controller.current = null; pending.current = false; if (active.current) setBusy(false); }
    }
  }
  return <section className="order-items" aria-label="Original test-order items" aria-busy={busy}>
    <button ref={trigger} type="button" className="button button-outline full-width" disabled={busy || signIn} onClick={() => void load()}>
      {busy ? 'Loading original test-order items…' : items || message ? 'Refresh original test-order items' : 'View original test-order items'}
    </button>
    {(items || message || busy) && <button type="button" className="button button-outline full-width" onClick={() => { restoreFocus.current = true; clear(); }}>Hide original test-order items</button>}
    {message && <div role="alert" tabIndex={-1} ref={alert}>{message}{signIn && <a href="/account/sign-in">Open a fresh sign-in page</a>}</div>}
    {items && <>
      <h4 tabIndex={-1} ref={heading}>Original test-order items</h4>
      <p className="fine-print">Names, license versions and prepared prices come from this order’s original review. They do not confirm payment, grant rights or establish current download availability.</p>
      <ol className="quote-review-result">
        {items.lines.map(line => <li className="quote-review-item" key={line.position}>
          <h5>{line.title}</h5><p>{line.licenseName} · Version {line.licenseVersion}</p><p>Quantity {line.quantity}</p>
          <dl className="order-items-amounts">
            <dt>Base price</dt><dd>{formatMoney(line.baseMinor, items.currency)}</dd>
            <dt>Discount</dt><dd>{formatMoney(line.discountMinor, items.currency)}</dd>
            <dt>Tax basis</dt><dd>{formatMoney(line.taxBasisMinor, items.currency)}</dd>
            <dt>Test tax</dt><dd>{formatMoney(line.taxMinor, items.currency)}</dd>
            <dt>Prepared item total</dt><dd>{formatMoney(line.totalMinor, items.currency)}</dd>
          </dl>
        </li>)}
      </ol>
      <dl className="order-items-amounts">
        <dt>Subtotal</dt><dd>{formatMoney(items.subtotalMinor, items.currency)}</dd>
        <dt>Discount</dt><dd>{formatMoney(items.discountMinor, items.currency)}</dd>
        <dt>Tax basis</dt><dd>{formatMoney(items.taxBasisMinor, items.currency)}</dd>
        <dt>Test tax</dt><dd>{formatMoney(items.taxMinor, items.currency)}</dd>
        <dt>Prepared order total</dt><dd>{formatMoney(items.totalMinor, items.currency)} {items.currency}</dd>
      </dl>
    </>}
  </section>;
}
