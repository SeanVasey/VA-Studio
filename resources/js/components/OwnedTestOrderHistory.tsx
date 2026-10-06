import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { OrderSummary } from './OrderPreparation';
import { formatMoney } from '../lib/catalog';
import { readOwnedOrderHistory, type OwnedOrderHistory } from '../lib/owned-order-history';
export { validOrderHistory, validOrderSummary } from '../lib/owned-order-history';

function retainedStatus(order: OrderSummary): string {
  if (order.paymentStatus !== 'verified') return 'Payment has not been verified.';
  if (order.finalizationStatus === 'awaiting_finalization') return 'Test payment verified. Finalization is pending.';
  if (order.finalizationStatus === 'paid_exception') return 'Test payment verified. This order needs review before fulfillment can continue.';
  if (order.contractStatus === 'attention') return 'Test payment verified. Contract preparation needs attention.';
  if (order.contractStatus === 'pending') return 'Test payment verified. Original contracts are pending.';
  return 'Original test contracts issued. Check the downloads panel for current availability.';
}

export function OwnedTestOrderHistory({ renderOrder, scope = 'session' }: { scope?: 'session' | 'account'; renderOrder: (order: OrderSummary) => ReactNode }) {
  const label = scope === 'account' ? 'account' : 'test';
  const [history, setHistory] = useState<OwnedOrderHistory | null>(null);
  const [anchors, setAnchors] = useState<Array<string | null>>([]);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [signIn, setSignIn] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);
  const heading = useRef<HTMLHeadingElement>(null), alert = useRef<HTMLParagraphElement>(null);
  const active = useRef(false), generation = useRef(0);
  const pending = useRef<AbortController | null>(null), deadline = useRef<number | null>(null);
  const unavailable = scope === 'account' ? 'Your account’s test order history could not be loaded. Refresh the list or sign in again.' : 'Available test order history could not be loaded. Refresh the list to try again.';

  function stopDeadline() { if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null; }
  function clear() {
    stopDeadline();
    ++generation.current; pending.current?.abort(); pending.current = null;
    setHistory(null); setAnchors([]); setSelected(null); setBusy(false); setMessage(''); setSignIn(false);
  }
  useEffect(() => {
    active.current = true;
    window.addEventListener('pagehide', clear);
    return () => { active.current = false; stopDeadline(); ++generation.current; pending.current?.abort(); pending.current = null; window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => { if (history) heading.current?.focus(); else if (message) alert.current?.focus(); }, [history, message]);

  async function load(direction: 'newest' | 'older' | 'newer' = 'newest') {
    if (pending.current || signIn) return;
    let nextAnchors: Array<string | null> = [null];
    if (direction === 'older') {
      if (!history?.nextCursor || anchors.includes(history.nextCursor)) return;
      nextAnchors = [...anchors, history.nextCursor];
    } else if (direction === 'newer') {
      if (anchors.length < 2) return;
      nextAnchors = anchors.slice(0, -1);
    }
    const current = ++generation.current, abort = new AbortController(); pending.current = abort;
    setBusy(true); setHistory(null); setSelected(null); setMessage(''); setSignIn(false);
    const ownsRequest = () => active.current && generation.current === current && pending.current === abort;
    const fail = (reload: boolean) => {
      setHistory(null); setAnchors([]); setSelected(null); setMessage(unavailable); setSignIn(reload);
    };
    const timer = window.setTimeout(() => {
      if (!ownsRequest()) return;
      deadline.current = null; abort.abort(); ++generation.current; pending.current = null; setBusy(false); fail(false);
    }, 20_000);
    deadline.current = timer;
    try {
      const result = await readOwnedOrderHistory(nextAnchors[nextAnchors.length - 1], abort.signal);
      if (!ownsRequest() || abort.signal.aborted) return;
      if (result.kind === 'loaded' && (result.history.nextCursor === null || !nextAnchors.includes(result.history.nextCursor))) {
        setHistory(result.history); setAnchors(nextAnchors);
      } else fail(result.kind === 'reload');
    } finally {
      if (deadline.current === timer && pending.current === abort) stopDeadline();
      if (ownsRequest()) { pending.current = null; setBusy(false); }
    }
  }

  return <section aria-label={scope === 'account' ? 'Your account test orders' : 'Available test orders'} aria-busy={busy}>
    <p className="fine-print">{scope === 'account' ? 'Browse test orders prepared with this account. Earlier guest orders appear only after explicitly saving them to this account; signing in alone does not link them.' : 'Lists orders available to your current session or signed-in test account. Guest orders are not linked when you sign in.'}</p>
    <button type="button" className="button button-outline full-width" disabled={busy || signIn} onClick={() => void load()}>
      {busy ? `Loading ${label} orders…` : history || message ? `Refresh ${label} orders` : `Browse ${label} orders`}
    </button>
    {message && <p role="alert" tabIndex={-1} ref={alert}>{message}{signIn && <a href="/account/sign-in">Open a fresh sign-in page</a>}</p>}
    {history && <>
      <h4 ref={heading} tabIndex={-1}>{scope === 'account' ? 'Account orders' : 'Available test orders'}</h4>
      {history.orders.length === 0 ? <p role="status">{scope === 'account' ? 'No test orders belong to this account.' : 'No test orders are available.'}</p>
        : <><p className="fine-print">Newest orders first. First-item labels are from the original order; open its details to see every item. Read-only summaries do not confirm that a download completed.</p>
          {history.orders.map((order, index) => {
            const preview = history.previews[index];
            return <div className="quote-review-item" key={order.id}>
              <p className="fine-print">First original item · {preview.itemCount} {preview.itemCount === 1 ? 'item' : 'items'} in this order</p>
              <h5>{preview.firstItem.title}</h5><p>{preview.firstItem.licenseName} · Version {preview.firstItem.licenseVersion}</p>
              <p>Order {order.id}</p><p>Prepared {new Date(order.createdAt).toISOString()} · {formatMoney(order.totalMinor, order.currency)} {order.currency}</p>
              <p>{retainedStatus(order)}</p>
              <button type="button" className="button button-outline" aria-expanded={selected === order.id}
                onClick={() => setSelected(selected === order.id ? null : order.id)}>View test order status {order.id}</button>
              {selected === order.id && renderOrder(order)}
            </div>;
          })}</>}
      {anchors.length > 1 && <>
        <button type="button" className="button button-outline full-width" onClick={() => void load('newer')}>Newer {label} orders</button>
        <button type="button" className="button button-outline full-width" onClick={() => void load('newest')}>Newest {label} orders</button>
      </>}
      {history.nextCursor !== null && <button type="button" className="button button-outline full-width" onClick={() => void load('older')}>Older {label} orders</button>}
    </>}
  </section>;
}
