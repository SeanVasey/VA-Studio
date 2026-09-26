import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '../lib/catalog';

interface CheckoutStatus {
  checkoutSchema: 1; orderId: string; id: string | null; currency: 'USD'; totalMinor: number;
  status: 'not_started' | 'pending' | 'open' | 'complete' | 'expired' | 'reconciliation_required';
  testOnly: true; paymentStatus: 'not_verified'; fulfillmentStatus: 'not_started';
  url: string | null; expiresAt: string | null; observedAt: string | null;
}
interface Props { orderId: string; enabled?: boolean; expectedTotalMinor?: number }
type Action = 'read' | 'create' | 'reconcile';
const statuses = ['not_started', 'pending', 'open', 'complete', 'expired', 'reconciliation_required'];
const timestamp = (value: unknown) => value === null || (typeof value === 'string' && Number.isFinite(Date.parse(value)));

function stripeUrl(value: unknown): value is string {
  if (typeof value !== 'string' || value.length > 4096 || new TextEncoder().encode(value).byteLength > 4096 || value.includes('\\') || /[\u0000-\u0020\u007f]/.test(value)) return false;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && url.hostname === 'checkout.stripe.com' && url.port === ''
      && url.username === '' && url.password === '' && url.pathname.startsWith('/c/pay/cs_test_');
  } catch { return false; }
}

function validStatus(value: unknown, orderId: string, expectedTotalMinor?: number): value is CheckoutStatus {
  if (!value || typeof value !== 'object') return false;
  const s = value as CheckoutStatus;
  return s.checkoutSchema === 1 && s.orderId === orderId && statuses.includes(s.status)
    && s.testOnly === true && s.paymentStatus === 'not_verified' && s.fulfillmentStatus === 'not_started' && s.currency === 'USD'
    && Number.isSafeInteger(s.totalMinor) && s.totalMinor >= 0 && (expectedTotalMinor === undefined || s.totalMinor === expectedTotalMinor)
    && timestamp(s.expiresAt) && timestamp(s.observedAt)
    && (s.status === 'not_started' ? s.id === null && s.url === null : typeof s.id === 'string' && s.id.length > 0 && s.id.length <= 128)
    && (s.status === 'open' ? stripeUrl(s.url) && s.expiresAt !== null : s.url === null);
}

const descriptions: Record<CheckoutStatus['status'], string> = {
  not_started: 'No Stripe test checkout has been started for this order.',
  pending: 'The Stripe test checkout request is pending. Retry to recover the same attempt.',
  open: 'Stripe test checkout is available. Opening it does not verify payment or issue rights.',
  complete: 'Stripe reports that its test checkout session is complete. Payment has not been verified and fulfillment has not started.',
  expired: 'The Stripe test checkout session has expired. No payment or fulfillment is confirmed here.',
  reconciliation_required: 'This test checkout needs reconciliation. Check its status before taking another step.',
};
const rejectionMessages = {
  unsupported: 'This order’s pricing is not supported by Stripe test checkout. Review your selection again and prepare an eligible test order.',
  expired: 'This order’s checkout preparation window has expired. Review your selection again and prepare a new test order.',
};

export function TestCheckout(props: Props) {
  return <Checkout key={`${props.orderId}:${props.expectedTotalMinor ?? ''}`} {...props} />;
}

function Checkout({ orderId, enabled = false, expectedTotalMinor }: Props) {
  const [status, setStatus] = useState<CheckoutStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [uncertain, setUncertain] = useState<Exclude<Action, 'read'> | null>(null);
  const [rejected, setRejected] = useState<keyof typeof rejectionMessages | null>(null);
  const [expired, setExpired] = useState(false);
  const active = useRef(false);
  const inFlight = useRef(false);

  async function request(action: Action) {
    if (inFlight.current) return;
    if (action === 'create' && rejected) return;
    if (action === 'create' && status?.status === 'not_started' && !enabled && uncertain !== 'create') return;
    inFlight.current = true; setBusy(true); setMessage('');
    try {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const endpoint = `/orders/${encodeURIComponent(orderId)}/checkout${action === 'reconcile' ? '/reconcile' : ''}`;
      const response = await fetch(endpoint, { method: action === 'read' ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', ...(action === 'read' ? {} : { 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) }) },
        ...(action === 'read' ? {} : { body: '{}' }),
      });
      if (!active.current) return;
      if (!response.ok) {
        const failure: unknown = await response.json();
        if (!active.current) return;
        const code = failure && typeof failure === 'object' && 'code' in failure ? failure.code : null;
        const rejection = response.status === 409 && code === 'CHECKOUT_UNSUPPORTED' ? 'unsupported'
          : response.status === 410 && code === 'CHECKOUT_EXPIRED' ? 'expired' : null;
        if (rejection) { setRejected(rejection); setUncertain(null); setMessage(rejectionMessages[rejection]); return; }
        throw new Error('Checkout status unavailable');
      }
      const body = await response.json();
      if (!active.current) return;
      if (!validStatus(body?.checkout, orderId, expectedTotalMinor)) throw new Error('Invalid checkout status');
      setStatus(body.checkout); setUncertain(null);
      if (rejected && body.checkout.status === 'not_started') setMessage(rejectionMessages[rejected]);
      else setRejected(null);
    } catch {
      if (!active.current) return;
      if (action !== 'read') setUncertain(action);
      setMessage(action === 'read'
        ? 'The Stripe test checkout status could not be loaded. No payment or fulfillment is confirmed.'
        : 'The Stripe test checkout result is unconfirmed. Retry the same operation or reload its saved status. No payment or fulfillment is confirmed.');
    } finally {
      inFlight.current = false;
      if (active.current) setBusy(false);
    }
  }

  useEffect(() => {
    active.current = true;
    void request('read');
    return () => { active.current = false; };
  // The keyed component fixes the order identity for this lifetime.
  }, []);
  useEffect(() => {
    if (status?.status !== 'open' || !status.expiresAt) { setExpired(false); return; }
    const deadline = Date.parse(status.expiresAt);
    const update = () => setExpired(Date.now() >= deadline);
    update();
    const timer = window.setTimeout(update, Math.max(0, Math.min(deadline - Date.now(), 2147483647)));
    return () => window.clearTimeout(timer);
  }, [status]);

  // URLs stay only in this component's memory and disappear while an observation is uncertain or expired.
  const link = status?.status === 'open' && status.url && !busy && !message && !uncertain && !rejected && !expired && Date.parse(status.expiresAt!) > Date.now() ? status.url : null;
  const retry = !rejected && (uncertain === 'create' || status?.status === 'pending');
  return <section className="quote-review" aria-label="Stripe test checkout" aria-busy={busy}>
    <h3>STRIPE TEST CHECKOUT</h3>
    <p className="checkout-advisory">Test mode only. This page does not verify payment, issue a license or grant download access.</p>
    {status && <>
      <p role="status">{expired && status.status === 'open' ? 'This checkout link has expired. Check the current Stripe test checkout status.' : descriptions[status.status]}</p>
      <p>Test order total: {formatMoney(status.totalMinor, status.currency)} {status.currency}</p>
      {status.observedAt && <p className="fine-print">Last observed: <time dateTime={status.observedAt}>{new Date(status.observedAt).toLocaleString()}</time>.</p>}
    </>}
    {message && <p role="alert">{message}</p>}
    {link && <a className="button full-width" href={link} rel="noreferrer noopener" referrerPolicy="no-referrer">Continue to Stripe test checkout</a>}
    {status?.status === 'not_started' && enabled && !uncertain && !rejected && <button type="button" className="button full-width" disabled={busy} onClick={() => void request('create')}>Open Stripe test checkout</button>}
    {retry && <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void request('create')}>Retry Stripe test checkout</button>}
    {status && <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void request(rejected || status.status === 'not_started' ? 'read' : 'reconcile')}>Check Stripe test checkout status</button>}
    {uncertain === 'reconcile' && !status && <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void request('reconcile')}>Check Stripe test checkout status</button>}
    {(!status || message) && <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void request('read')}>{busy && !status ? 'Loading checkout status…' : 'Reload checkout status'}</button>}
    {status?.status === 'not_started' && !enabled && !uncertain && <p className="fine-print">Starting a new Stripe test checkout is currently unavailable.</p>}
  </section>;
}
