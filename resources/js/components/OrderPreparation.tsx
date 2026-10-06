import { useEffect, useRef, useState, type FormEvent } from 'react';
import { fileRoleLabels, formatMoney, type LicenseDisclosure } from '../lib/catalog';
import { TestCheckout, validPaymentProgress, type PaymentProgress } from './TestCheckout';
import { OwnedTestOrderHistory } from './OwnedTestOrderHistory';

interface PricedLine {
  offerRevisionId: string; quantity: number; baseMinor: number; discountMinor: number;
  taxBasisMinor: number; taxMinor: number; totalMinor: number; disclosureHash: string;
}
interface Pricing {
  pricingSchema: number; id: string; quoteId: string; expiresAt: string; currency: 'USD';
  subtotalMinor: number; discountMinor: number; taxBasisMinor: number; taxMinor: number; totalMinor: number;
  taxStatus: 'fixed_test'; payable: false; testOnly: true; pricingHash: string; items: PricedLine[];
  promotion?: { key: string; version: number; code: string; hash: string };
}
interface OrderReview {
  reviewSchema: 1; quoteId: string; expiresAt: string; pricing: Pricing; sellerName: string; policyVersion: string;
  assent: { version: string; text: string };
  items: Array<{ offerRevisionId: string; title: string; licenseName: string; disclosure: LicenseDisclosure }>;
  testOnly: true; payable: false; reviewHash: string;
}
export interface OrderSummary extends PaymentProgress {
  id: string; createdAt: string;
  status: 'prepared' | 'paid' | 'paid_exception'; testOnly: true; payable: false; currency: 'USD'; totalMinor: number;
}
interface PreparedOrder extends OrderSummary { orderSchema: 1; quoteId: string; pricingId: string; reviewHash: string }
interface Props { quoteId: string; expiresAt: string; offerRevisionIds: string[]; testCheckoutEnabled?: boolean }
const recoveryStorageKey = 'vaseyaudio-order-recovery-v1';
const recoveryChangedEvent = 'vaseyaudio-order-recovery-changed';
const locator = (value: unknown): value is string => typeof value === 'string' && /^[a-zA-Z0-9_-]{1,128}$/.test(value);

function recoveryLocators(): string[] {
  try {
    const value: unknown = JSON.parse(sessionStorage.getItem(recoveryStorageKey) ?? '[]');
    return Array.isArray(value) ? [...new Set(value.filter(locator))].slice(-10) : [];
  } catch { return []; }
}

function rememberRecovery(quoteId: string) {
  if (!locator(quoteId)) return;
  try { sessionStorage.setItem(recoveryStorageKey, JSON.stringify([...recoveryLocators().filter(id => id !== quoteId), quoteId].slice(-10))); } catch { /* Optional opaque tab recovery; never persist identity or request bodies. */ }
}
const hash = (value: unknown): value is string => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const text = (value: unknown): value is string => typeof value === 'string' && value.trim().length > 0;
const money = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) >= 0;
const timestamp = (value: unknown): value is string => typeof value === 'string' && Number.isFinite(Date.parse(value));
const strings = (value: unknown): value is string[] => Array.isArray(value) && value.every(item => typeof item === 'string');
const sameIds = (a: string[], b: string[]) => a.length === b.length && new Set(a).size === a.length && [...a].sort().every((value, index) => value === [...b].sort()[index]);

function validPricing(value: unknown, quoteId: string, ids: string[]): value is Pricing {
  if (!value || typeof value !== 'object') return false;
  const p = value as Pricing;
  return [1, 2, 3].includes(p.pricingSchema) && text(p.id) && p.quoteId === quoteId && timestamp(p.expiresAt)
    && p.currency === 'USD' && p.payable === false && p.testOnly === true && p.taxStatus === 'fixed_test' && hash(p.pricingHash)
    && [p.subtotalMinor, p.discountMinor, p.taxBasisMinor, p.taxMinor, p.totalMinor].every(money)
    && p.discountMinor <= p.subtotalMinor && p.taxBasisMinor === p.subtotalMinor - p.discountMinor && p.totalMinor === p.taxBasisMinor + p.taxMinor
    && Array.isArray(p.items) && p.items.length > 0 && p.items.length <= 10 && p.items.every(line => line && text(line.offerRevisionId)
      && line.quantity === 1 && hash(line.disclosureHash) && [line.baseMinor, line.discountMinor, line.taxBasisMinor, line.taxMinor, line.totalMinor].every(money)
      && line.discountMinor <= line.baseMinor && line.taxBasisMinor === line.baseMinor - line.discountMinor && line.totalMinor === line.taxBasisMinor + line.taxMinor)
    && sameIds(p.items.map(line => line.offerRevisionId), ids)
    && p.items.reduce((sum, line) => sum + line.baseMinor, 0) === p.subtotalMinor
    && p.items.reduce((sum, line) => sum + line.discountMinor, 0) === p.discountMinor
    && p.items.reduce((sum, line) => sum + line.taxMinor, 0) === p.taxMinor
    && (p.promotion === undefined || (p.promotion !== null && text(p.promotion.key) && Number.isSafeInteger(p.promotion.version) && p.promotion.version > 0 && text(p.promotion.code) && hash(p.promotion.hash)))
    && (p.pricingSchema !== 2 || p.promotion !== undefined);
}

function validReview(value: unknown, quoteId: string, ids: string[], pricing: Pricing): value is OrderReview {
  if (!value || typeof value !== 'object') return false;
  const r = value as OrderReview;
  return r.reviewSchema === 1 && r.quoteId === quoteId && timestamp(r.expiresAt) && hash(r.reviewHash)
    && r.testOnly === true && r.payable === false && text(r.sellerName) && text(r.policyVersion) && !!r.assent && text(r.assent.text)
    && text(r.assent.version) && r.assent.version.length <= 80
    && validPricing(r.pricing, quoteId, ids) && r.pricing.id === pricing.id && r.pricing.pricingHash === pricing.pricingHash
    && Array.isArray(r.items) && r.items.length <= 10 && sameIds(r.items.map(item => item?.offerRevisionId), ids)
    && r.items.every(item => item && text(item.title) && text(item.licenseName) && !!item.disclosure
      && item.disclosure.quoteId === quoteId && item.disclosure.offerRevisionId === item.offerRevisionId
      && [1, 2].includes(item.disclosure.disclosureSchema ?? 0) && (item.disclosure.disclosureSchema !== 2 || item.disclosure.testOnly === true)
      && text(item.disclosure.offerId) && text(item.disclosure.licenseVersionId) && text(item.disclosure.name)
      && Number.isSafeInteger(item.disclosure.version) && item.disclosure.version > 0 && text(item.disclosure.type) && text(item.disclosure.termsText)
      && strings(item.disclosure.features) && strings(item.disclosure.deliverableRoles) && hash(item.disclosure.disclosureHash)
      && item.disclosure.disclosureHash === r.pricing.items.find(line => line.offerRevisionId === item.offerRevisionId)?.disclosureHash);
}

function validOrder(value: unknown, quoteId: string, review?: OrderReview): value is PreparedOrder {
  if (!value || typeof value !== 'object') return false;
  const o = value as PreparedOrder;
  return o.orderSchema === 1 && text(o.id) && o.quoteId === quoteId && text(o.pricingId) && hash(o.reviewHash) && timestamp(o.createdAt)
    && validPaymentProgress(o)
    && o.status === (o.finalizationStatus === 'paid' || o.finalizationStatus === 'paid_exception' ? o.finalizationStatus : 'prepared') && o.testOnly === true && o.payable === false && o.currency === 'USD' && money(o.totalMinor)
    && (!review || (o.reviewHash === review.reviewHash && o.pricingId === review.pricing.id && o.totalMinor === review.pricing.totalMinor));
}

const headers = () => {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) };
};

async function readPreparedOrder(quoteId: string): Promise<PreparedOrder | null> {
  const response = await fetch(`/quotes/${encodeURIComponent(quoteId)}/order`, { credentials: 'same-origin', headers: headers(), cache: 'no-store' });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error('Recovery unavailable');
  const body = await response.json();
  if (!validOrder(body?.order, quoteId)) throw new Error('Invalid order');
  return body.order;
}

export function OrderStatus({ order, testCheckoutEnabled = false }: { order: OrderSummary; testCheckoutEnabled?: boolean }) {
  return <div className="quote-review-result" role="status"><h3>{order.paymentStatus === 'verified' ? 'TEST ORDER STATUS' : 'TEST ORDER PREPARED'}</h3><p>Order {order.id}</p><p>Prepared total: {formatMoney(order.totalMinor, order.currency)} {order.currency}</p><p>This prepared record alone does not confirm payment or grant download access or usage rights.</p><TestCheckout orderId={order.id} expectedTotalMinor={order.totalMinor} enabled={testCheckoutEnabled} retainedProgress={order} /></div>;
}

// Mount outside catalog and policy gates: an immutable order outlives its quote and the creation policy.
export function PreparedOrderRecovery({ testCheckoutEnabled = false }: { testCheckoutEnabled?: boolean }) {
  const [quoteIds, setQuoteIds] = useState(recoveryLocators);
  useEffect(() => {
    const refresh = () => setQuoteIds(recoveryLocators());
    window.addEventListener(recoveryChangedEvent, refresh);
    return () => window.removeEventListener(recoveryChangedEvent, refresh);
  }, []);
  return <section className="quote-review" aria-label="Previous test order recovery"><h3>PREVIOUS TEST ORDERS</h3>
    <OwnedTestOrderHistory renderOrder={order => <OrderStatus order={order} testCheckoutEnabled={testCheckoutEnabled} />} />
    {quoteIds.length > 0 && <>
    <p className="fine-print">Read-only status for recent preparation attempts in this browser tab. No payment or license is issued.</p>
    {quoteIds.map(quoteId => <RecoveredOrder key={quoteId} quoteId={quoteId} testCheckoutEnabled={testCheckoutEnabled} />)}
    </>}
  </section>;
}

function RecoveredOrder({ quoteId, testCheckoutEnabled }: { quoteId: string; testCheckoutEnabled: boolean }) {
  const [attempt, setAttempt] = useState(0);
  const [order, setOrder] = useState<PreparedOrder | null>(null);
  const [busy, setBusy] = useState(true);
  const [message, setMessage] = useState('');
  useEffect(() => {
    let active = true;
    setBusy(true); setMessage('');
    void readPreparedOrder(quoteId).then(result => {
      if (!active) return;
      if (result) setOrder(result);
      else setMessage('No prepared test order is currently available for this attempt. An interrupted request may still be completing; check again.');
    }).catch(() => {
      if (active) setMessage('This test order status could not be recovered. Check again when your connection and original session are available.');
    }).finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [quoteId, attempt]);
  return <div aria-busy={busy}>{order ? <OrderStatus order={order} testCheckoutEnabled={testCheckoutEnabled} /> : <>
    {message && <p role="status">{message}</p>}
    <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => setAttempt(value => value + 1)}>{busy ? 'Checking saved test order…' : 'Check saved test order again'}</button>
  </>}</div>;
}

// A new selection remounts this state, so responses for an older selection cannot expose its identity or order.
export function OrderPreparation(props: Props) {
  return <Preparation key={`${props.quoteId}:${[...props.offerRevisionIds].sort().join(':')}`} {...props} />;
}

function Preparation({ quoteId, expiresAt, offerRevisionIds, testCheckoutEnabled = false }: Props) {
  const active = useRef(false);
  const inFlight = useRef(false);
  const captured = useRef<{ key: string; body: string; review: OrderReview } | null>(null);
  const [recovered, setRecovered] = useState(false);
  const [review, setReview] = useState<OrderReview | null>(null);
  const [order, setOrder] = useState<PreparedOrder | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [legalName, setLegalName] = useState('');
  const [email, setEmail] = useState('');
  const [accepted, setAccepted] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [expired, setExpired] = useState(false);
  const reviewExpiry = Math.min(Date.parse(expiresAt), ...(review ? [Date.parse(review.expiresAt), Date.parse(review.pricing.expiresAt)] : []));

  useEffect(() => {
    const check = () => setExpired(Date.now() >= reviewExpiry);
    check();
    const timer = window.setTimeout(check, Math.max(0, Math.min(reviewExpiry - Date.now(), 2147483647)));
    return () => window.clearTimeout(timer);
  }, [reviewExpiry]);

  async function recover() {
    if (inFlight.current) return;
    inFlight.current = true; setBusy(true); setMessage('');
    try {
      const response = await fetch(`/quotes/${encodeURIComponent(quoteId)}/order`, { credentials: 'same-origin', headers: headers(), cache: 'no-store' });
      if (!active.current) return;
      if (response.status === 404) { setRecovered(true); return; }
      if (!response.ok) throw new Error('Recovery unavailable');
      const body = await response.json();
      if (!active.current) return;
      if (!validOrder(body?.order, quoteId)) throw new Error('Invalid order');
      setOrder(body.order); setRecovered(true);
    } catch {
      if (active.current) setMessage('The existing test order could not be checked. Retry the check before preparing an order.');
    } finally {
      inFlight.current = false;
      if (active.current) setBusy(false);
    }
  }

  useEffect(() => {
    active.current = true;
    void recover();
    return () => { active.current = false; window.dispatchEvent(new Event(recoveryChangedEvent)); };
  // The keyed component fixes these identities for its lifetime.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function loadReview() {
    if (inFlight.current || !recovered || order || captured.current || Date.now() >= reviewExpiry) return;
    inFlight.current = true; setBusy(true); setMessage(''); setReview(null); setAccepted(false);
    try {
      const url = `/quotes/${encodeURIComponent(quoteId)}/pricing`;
      let response = await fetch(url, { credentials: 'same-origin', headers: headers(), cache: 'no-store' });
      if (!active.current) return;
      if (response.status === 404) response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers(), body: '{}' });
      if (!active.current) return;
      if (!response.ok) throw new Error('Pricing unavailable');
      const priced = await response.json();
      if (!active.current) return;
      if (!validPricing(priced?.pricing, quoteId, offerRevisionIds)) throw new Error('Incomplete pricing');
      const result = await fetch(`/quotes/${encodeURIComponent(quoteId)}/order-review`, { credentials: 'same-origin', headers: headers(), cache: 'no-store' });
      if (!active.current) return;
      if (!result.ok) throw new Error('Review unavailable');
      const body = await result.json();
      if (!active.current) return;
      if (!validReview(body?.review, quoteId, offerRevisionIds, priced.pricing)) throw new Error('Invalid review');
      setReview(body.review);
    } catch {
      if (active.current) setMessage('The complete test order could not be reviewed. A current selection, confirmed test tax and full terms are required.');
    } finally {
      inFlight.current = false;
      if (active.current) setBusy(false);
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inFlight.current || order || !review) return;
    if (!captured.current) {
      if (Date.now() >= reviewExpiry || !accepted || !legalName.trim() || legalName.trim().length > 160 || email.length > 254 || !event.currentTarget.checkValidity()) return;
      captured.current = { key: crypto.randomUUID(), body: JSON.stringify({ quoteId, reviewHash: review.reviewHash, buyer: { legalName: legalName.trim(), email: email.trim() }, accepted: true }), review };
      rememberRecovery(quoteId);
      setSubmitted(true);
    }
    const request = captured.current;
    inFlight.current = true; setBusy(true); setMessage('');
    try {
      const response = await fetch('/orders', { method: 'POST', credentials: 'same-origin', headers: { ...headers(), 'Idempotency-Key': request.key }, body: request.body });
      if (!active.current) return;
      if (!response.ok) {
        const failure: unknown = await response.json();
        if (!active.current) return;
        const code = failure && typeof failure === 'object' && 'code' in failure && typeof failure.code === 'string' ? failure.code : '';
        if (response.status === 409 && ['ORDER_ALREADY_PREPARED', 'IDEMPOTENCY_CONFLICT'].includes(code)) {
          const existing = await readPreparedOrder(quoteId);
          if (!active.current) return;
          if (existing) {
            setOrder(existing); setLegalName(''); setEmail(''); setAccepted(false); captured.current = null;
            return;
          }
          if (code === 'IDEMPOTENCY_CONFLICT') {
            captured.current = null; setSubmitted(false); setAccepted(false);
            setMessage('The previous request key cannot be reused. Check your details and accept these terms again to prepare a new request.');
            return;
          }
          throw new Error('Prepared order not yet recovered');
        }
        if (response.status === 422 && ['INVALID_ORDER_REQUEST', 'INVALID_QUOTE_REQUEST'].includes(code)) {
          captured.current = null; setSubmitted(false); setAccepted(false);
          setMessage('The test order request was rejected. Correct your name or email and accept the displayed terms again.');
          return;
        }
        const changed = response.status === 409 && ['ORDER_REVIEW_CHANGED', 'ORDER_PRICING_UNAVAILABLE', 'SELECTION_CHANGED', 'PRICING_CHANGED', 'PROMOTION_CHANGED', 'PROMOTION_UNAVAILABLE', 'PROMOTION_LIMIT_REACHED', 'INVENTORY_CHANGED', 'INVENTORY_UNAVAILABLE', 'INVENTORY_BLOCKED', 'INVENTORY_SCOPE_UNAVAILABLE', 'INVENTORY_ATTEMPT_CONFLICT'].includes(code);
        const expired = response.status === 410 && ['QUOTE_EXPIRED', 'PRICING_EXPIRED', 'INVENTORY_EXPIRED'].includes(code);
        if (changed || expired) {
          captured.current = null; setSubmitted(false); setReview(null); setAccepted(false);
          setMessage('The reviewed terms or availability changed. Load a current review and explicitly accept its terms before preparing an order.');
          return;
        }
        // Unknown codes, malformed responses, ORDER_CHANGED and server errors can follow a committed order.
        throw new Error('Preparation outcome unknown');
      }
      const body = await response.json();
      if (!active.current) return;
      if (!validOrder(body?.order, quoteId, request.review)) throw new Error('Invalid prepared order');
      setOrder(body.order); setLegalName(''); setEmail(''); setAccepted(false);
      captured.current = null;
    } catch {
      if (active.current) setMessage('The result is unconfirmed. Retry the same test order to check or complete that request. Your submitted details stay fixed for this retry.');
    } finally {
      inFlight.current = false;
      if (active.current) setBusy(false);
    }
  }

  return <section className="quote-review" aria-label="Test order preparation" aria-busy={busy}>
    <h3>TEST ORDER PREPARATION</h3>
    <p className="checkout-advisory">Test only. Preparing this order does not take payment or issue a license.</p>
    {order ? <OrderStatus order={order} testCheckoutEnabled={testCheckoutEnabled} /> : <>
      {!recovered ? <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void recover()}>{busy ? 'Checking existing test order…' : 'Retry existing order check'}</button> : !review && <button type="button" className="button button-outline full-width" disabled={busy || expired} onClick={() => void loadReview()}>{busy ? 'Loading test order review…' : 'Review test order'}</button>}
      {review && <form onSubmit={submit} autoComplete="off">
        <div className="quote-review-result">
          <p>Seller: {review.sellerName}</p>
          <p className="fine-print">Test order policy: {review.policyVersion} · Assent version: {review.assent.version}</p>
          {review.items.map(item => {
            const line = review.pricing.items.find(line => line.offerRevisionId === item.offerRevisionId)!;
            return <section className="quote-review-item" key={item.offerRevisionId} aria-label={`${item.title} order terms`}>
              <h3>{item.title}</h3><p>{item.licenseName} · version {item.disclosure.version} · {item.disclosure.type}</p>
              <p>{item.disclosure.deliverableRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</p>
              <ul>{item.disclosure.features.map((feature, index) => <li key={index}>{feature}</li>)}</ul>
              <p>Quantity: {line.quantity} · Price: {formatMoney(line.baseMinor)} · Discount: {formatMoney(line.discountMinor)} · Tax basis: {formatMoney(line.taxBasisMinor)} · Test tax: {formatMoney(line.taxMinor)} · Line total: {formatMoney(line.totalMinor)}</p>
              <div className="license-source" role="region" aria-label={`${item.title} full license text`} tabIndex={0}>{item.disclosure.termsText}</div>
            </section>;
          })}
          {review.pricing.promotion && <p>Promotion: {review.pricing.promotion.code}</p>}
          <p>Subtotal: {formatMoney(review.pricing.subtotalMinor)} {review.pricing.currency}</p>
          <p>Discount: {formatMoney(review.pricing.discountMinor)}</p><p>Tax basis: {formatMoney(review.pricing.taxBasisMinor)}</p>
          <p>Fixed test tax: {formatMoney(review.pricing.taxMinor)}</p>
          <div className="cart-total"><span>Test total</span><strong>{formatMoney(review.pricing.totalMinor)} {review.pricing.currency}</strong></div>
          <p className="fine-print">Review expires at <time dateTime={new Date(reviewExpiry).toISOString()}>{new Date(reviewExpiry).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</time>.</p>
        </div>
        <fieldset disabled={busy || submitted || expired}>
          <legend>Test buyer details and assent</legend>
          <label>Legal name <span className="search-field"><input name="legalName" value={legalName} onChange={event => setLegalName(event.target.value)} required maxLength={160} autoComplete="off" /></span></label>
          <label>Email address <span className="search-field"><input name="email" type="email" value={email} onChange={event => setEmail(event.target.value)} required maxLength={254} autoComplete="off" /></span></label>
          <label className="offer-option"><input type="checkbox" checked={accepted} onChange={event => setAccepted(event.target.checked)} required /><span>{review.assent.text}</span></label>
        </fieldset>
        <p className="fine-print">These details are used only to prepare this test record. They are not stored in this browser’s saved cart.</p>
        <button type="submit" className="button full-width" disabled={busy || (!submitted && (expired || !accepted || !legalName.trim() || !email.trim()))}>{busy ? 'Preparing test order…' : submitted ? 'Retry same test order' : 'Prepare test order'}</button>
      </form>}
      {expired && !submitted && <p role="status">This selection or order review expired. Review your selection again before preparing a new test order.</p>}
    </>}
    {message && <p className="checkout-status" role="alert">{message}</p>}
  </section>;
}
