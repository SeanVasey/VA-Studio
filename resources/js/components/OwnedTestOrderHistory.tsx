import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { OrderSummary } from './OrderPreparation';
import { validPaymentProgress, type PaymentProgress } from './TestCheckout';
import { formatMoney } from '../lib/catalog';

interface History { orderHistorySchema: 1; testOnly: true; orders: OrderSummary[]; limit: 20; nextCursor: string | null }
const uuid = (value: unknown): value is string => typeof value === 'string' && value.length === 36 && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, expected: string[]) => Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));

export function validOrderHistory(value: unknown): value is History {
  if (!record(value) || !keys(value, ['orderHistorySchema', 'testOnly', 'orders', 'limit', 'nextCursor'])
    || value.orderHistorySchema !== 1 || value.testOnly !== true || value.limit !== 20
    || !Array.isArray(value.orders) || value.orders.length > 20 || (value.nextCursor !== null && !uuid(value.nextCursor))) return false;
  const ids = new Set<string>();
  for (const order of value.orders) {
    if (!record(order) || !keys(order, ['id', 'createdAt', 'testOnly', 'payable', 'currency', 'totalMinor', 'status',
      'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus'])
      || !uuid(order.id) || ids.has(order.id) || typeof order.createdAt !== 'string' || !Number.isFinite(Date.parse(order.createdAt))
      || order.testOnly !== true || order.payable !== false || order.currency !== 'USD'
      || !Number.isSafeInteger(order.totalMinor) || Number(order.totalMinor) < 0 || !validPaymentProgress(order as unknown as PaymentProgress)
      || order.status !== (order.finalizationStatus === 'paid' || order.finalizationStatus === 'paid_exception' ? order.finalizationStatus : 'prepared')) return false;
    ids.add(order.id);
  }
  return value.nextCursor === null || (value.orders.length === 20 && value.nextCursor === value.orders[19].id);
}

export function OwnedTestOrderHistory({ renderOrder, scope = 'session' }: { scope?: 'session' | 'account'; renderOrder: (order: OrderSummary) => ReactNode }) {
  const label = scope === 'account' ? 'account' : 'test';
  const [history, setHistory] = useState<History | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [selected, setSelected] = useState<string | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const active = useRef(false);
  const generation = useRef(0);
  const pending = useRef(false);
  useEffect(() => { active.current = true; return () => { active.current = false; ++generation.current; }; }, []);
  useEffect(() => { if (history) heading.current?.focus(); }, [history]);

  async function load(before: string | null = null) {
    if (pending.current) return;
    pending.current = true;
    const current = ++generation.current;
    setBusy(true); setHistory(null); setSelected(null); setMessage('');
    try {
      const response = await fetch(`/orders/history${before === null ? '' : `?before=${encodeURIComponent(before)}`}`, {
        credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store',
      });
      if (!active.current || generation.current !== current) return;
      if (!response.ok) throw new Error('History unavailable');
      const bytes = await response.text();
      if (!active.current || generation.current !== current) return;
      if (bytes.length > 64 * 1024) throw new Error('History too large');
      const body: unknown = JSON.parse(bytes);
      if (!record(body) || !keys(body, ['history']) || !validOrderHistory(body.history)) throw new Error('Invalid history');
      setHistory(body.history);
    } catch {
      if (active.current && generation.current === current) setMessage(scope === 'account' ? 'Your account’s test order history could not be loaded. Refresh the list or sign in again.' : 'Available test order history could not be loaded. Refresh the list to try again.');
    } finally {
      pending.current = false;
      if (active.current && generation.current === current) setBusy(false);
    }
  }

  return <section aria-label={scope === 'account' ? 'Your account test orders' : 'Available test orders'} aria-busy={busy}>
    <p className="fine-print">{scope === 'account' ? 'Browse test orders prepared while signed in to this account. Earlier guest orders and purchases from other accounts are not linked here.' : 'Lists orders available to your current session or signed-in test account. Guest orders are not linked when you sign in.'}</p>
    <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void load()}>
      {busy ? `Loading ${label} orders…` : history || message ? `Refresh ${label} orders` : `Browse ${label} orders`}
    </button>
    {message && <p role="alert">{message}</p>}
    {history && <>
      <h4 ref={heading} tabIndex={-1}>{scope === 'account' ? 'Account orders' : 'Available test orders'}</h4>
      {history.orders.length === 0 ? <p role="status">{scope === 'account' ? 'No test orders belong to this account.' : 'No test orders are available.'}</p>
        : <><p className="fine-print">Newest orders first. Read-only summaries do not confirm that a download completed.</p>
          {history.orders.map(order => <div className="quote-review-item" key={order.id}>
            <p>Order {order.id}</p><p>Prepared {new Date(order.createdAt).toISOString()} · {formatMoney(order.totalMinor, order.currency)} {order.currency}</p>
            <button type="button" className="button button-outline" aria-expanded={selected === order.id}
              onClick={() => setSelected(selected === order.id ? null : order.id)}>View test order status {order.id}</button>
            {selected === order.id && renderOrder(order)}
          </div>)}</>}
      {history.nextCursor !== null && <button type="button" className="button button-outline full-width" onClick={() => void load(history.nextCursor)}>Older {label} orders</button>}
    </>}
  </section>;
}
