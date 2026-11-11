import { useState, type MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { formatMoney } from '../lib/catalog';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import { soundKitPost, SoundKitRequestError, stripeTestCheckoutUrl, type SoundKitOrderState } from '../lib/sound-kits';
import '../../css/sound-kits.css';

interface Props { siteContent: SiteContent; order: SoundKitOrderState; navigate?: (url: string) => void; submitForm?: (form: HTMLFormElement) => void }

const descriptions: Record<SoundKitOrderState['status'], string> = {
  not_started: 'No Stripe test checkout has been started for this order.',
  expired: 'This order expired before its checkout started. Start a new purchase from the kit page.',
  checkout_started: 'A Stripe test checkout was started. Returning here does not confirm payment: check the payment status below.',
  paid: 'Test payment verified. The kit is being granted.',
  awaiting_contract: 'Test payment verified and the kit granted. Your license contract is being prepared; downloads open after it is issued.',
  awaiting_delivery: 'Test payment verified, kit granted and license issued. Test downloads open once delivery is enabled for this order.',
  downloadable: 'Test payment verified. Your kit and license are ready. Each download link is single-use and lasts one minute.',
};

/** The owner's Stripe-test kit order (BL-13). Shows retained state only; a redirect from Stripe proves nothing. */
export default function SoundKitOrder({ siteContent, order: initial, navigate = url => window.location.assign(url), submitForm = form => form.submit() }: Props) {
  const [order, setOrder] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const href = (path: string) => siteContentHref(path, null);
  function go(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href);
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: go };
  const base = `/sound-kit-orders/${order.orderId}`;
  async function run(action: () => Promise<void>) {
    if (busy) return;
    setBusy(true); setMessage(null);
    try { await action(); } catch (caught) {
      setMessage(caught instanceof SoundKitRequestError ? caught.message : 'This request could not be completed.');
    } finally { setBusy(false); }
  }
  const checkout = () => run(async () => {
    const state = await soundKitPost<SoundKitOrderState>(`${base}/checkout`);
    setOrder(state);
    if (stripeTestCheckoutUrl(state.checkoutUrl)) navigate(state.checkoutUrl);
  });
  const reconcile = () => run(async () => { setOrder(await soundKitPost<SoundKitOrderState>(`${base}/checkout/reconcile`)); });
  const download = (kind: 'archive' | 'contract') => run(async () => {
    const grant = await soundKitPost<{ authorizationId: string; token: string }>(`${base}/delivery/authorizations`, { kind });
    const form = document.createElement('form');
    form.method = 'POST'; form.action = `${base}/delivery/download`; form.hidden = true;
    const fields: Record<string, string> = { authorizationId: grant.authorizationId, token: grant.token,
      _token: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' };
    for (const [name, value] of Object.entries(fields)) {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
    }
    document.body.appendChild(form);
    try { submitForm(form); } finally { form.remove(); }
  });
  const unpaid = order.status === 'not_started' || order.status === 'checkout_started';
  return <>
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/sound-kits" />
    <main id="main" className="editorial-page section-pad sound-kits-page">
      <section className="sound-kit-order" aria-labelledby="sound-kit-order-title">
        <p className="eyebrow">Test order · Stripe test mode</p>
        <h1 id="sound-kit-order-title">{order.kit.title}</h1>
        <p>{formatMoney(order.priceMinor, order.currency)}</p>
        <p role="status">{descriptions[order.status]}</p>
        <div className="sound-kit-order-actions">
          {order.status === 'not_started' && <button type="button" className="button" disabled={busy} onClick={checkout}>Continue to Stripe test checkout</button>}
          {unpaid && <button type="button" className="button" disabled={busy} onClick={reconcile}>Check payment status</button>}
          {order.deliverables.includes('archive') && <button type="button" className="button" disabled={busy} onClick={() => download('archive')}>Download the kit (ZIP)</button>}
          {order.deliverables.includes('contract') && <button type="button" className="button" disabled={busy} onClick={() => download('contract')}>Download the license (PDF)</button>}
        </div>
        {message && <p className="sound-kit-notice" role="alert">{message}</p>}
        <a className="text-link" href={href('/sound-kits/' + order.kit.slug)} onClick={go}>Back to the kit</a>
      </section>
    </main>
    <SiteFooter {...chrome} />
  </>;
}
