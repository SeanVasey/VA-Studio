import { useEffect, useRef, useState, type MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { formatMoney } from '../lib/catalog';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import { soundKitGet, soundKitPost, SoundKitRequestError, stripeTestCheckoutUrl, type SoundKitOrderState } from '../lib/sound-kits';
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

/** Later states outrank earlier ones; a read that would move an order backwards is refused. `expired` is not part of the sequence. */
const progress: Record<SoundKitOrderState['status'], number> = { expired: 0, not_started: 0, checkout_started: 1, paid: 2, awaiting_contract: 3, awaiting_delivery: 4, downloadable: 5 };
/** The server grants, issues and enables delivery by itself, or on staff action, while the order is in one of these states. */
const pending = new Set<SoundKitOrderState['status']>(['paid', 'awaiting_contract', 'awaiting_delivery']);
/**
 * The kit lane is advanced by schedules, not by the buyer's request: grants by `vasey:finalize-test-kit-grants` (cron 4-59/10) and contracts
 * by `vasey:issue-test-kit-contracts` (cron 6-59/10), see routes/console.php. A pending order can wait about 10 minutes, so the cap is 15
 * minutes (that cadence plus margin). One read per 10 seconds is at most 90 reads, under the status route's 60 a minute throttle.
 */
const POLL_INTERVAL_MS = 10_000;
const POLL_MAX_MS = 900_000;
type PollState = 'active' | 'timed_out' | 'error';

/** The owner's Stripe-test kit order (BL-13). Shows retained state only; a redirect from Stripe proves nothing. */
export default function SoundKitOrder({ siteContent, order: initial, navigate = url => window.location.assign(url), submitForm = form => form.submit() }: Props) {
  const [order, setOrder] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [pollState, setPollState] = useState<PollState>('active');
  const active = useRef(true);
  const pollStartedAt = useRef<number | null>(null);
  const inFlight = useRef(false);
  // Counts every write to `order`, so a read that began before a newer write cannot overwrite it.
  const version = useRef(0);
  const commit = (next: SoundKitOrderState) => { version.current += 1; setOrder(next); };
  /**
   * An owner action's response replaces the order only if nothing was written since it was sent. A status read that was already in flight
   * can land while the action is still pending; the later-arriving action response would then be the older view, so it is dropped (the
   * poll or a repeated click shows the current state). Returns whether the response was applied.
   */
  const commitIfCurrent = (startedAt: number, next: SoundKitOrderState) => { if (version.current !== startedAt) return false; commit(next); return true; };
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
  // Starting and continuing both ask the server: it creates the session once, then re-reads it and returns its URL only while open.
  const checkout = () => run(async () => {
    const startedAt = version.current;
    const state = await soundKitPost<SoundKitOrderState>(`${base}/checkout`);
    commitIfCurrent(startedAt, state);
    if (stripeTestCheckoutUrl(state.checkoutUrl)) navigate(state.checkoutUrl);
    else if (state.status === 'checkout_started') setMessage('This Stripe test checkout is no longer open. Check the payment status instead.');
  });
  const reconcile = () => run(async () => {
    const startedAt = version.current;
    commitIfCurrent(startedAt, await soundKitPost<SoundKitOrderState>(`${base}/checkout/reconcile`));
  });
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
  /** One read of the retained order state through the owner-authorized GET endpoint. Resolves false when the read failed or was refused. */
  async function refresh(): Promise<boolean> {
    if (inFlight.current || busy) return true;
    inFlight.current = true;
    const startedAt = version.current;
    try {
      const next = await soundKitGet<SoundKitOrderState>(`${base}/status`);
      if (!active.current) return true;
      if (next?.schema !== 'sound-kit-test-order-v1' || next.orderId !== order.orderId || !(next.status in progress)) throw new Error('Invalid kit order status');
      // A write that landed while this read was in flight is newer than it; and a read never moves the order backwards.
      if (version.current !== startedAt) return true;
      if (progress[next.status] < progress[order.status]) throw new Error('Kit order status regressed');
      if (next.status !== order.status) commit(next);
      return true;
    } catch {
      if (active.current) setMessage('The latest order status could not be loaded. Nothing about your order has changed. Reload the page to try again.');
      return false;
    } finally { inFlight.current = false; }
  }
  // Timers outlive the render that started them; they call the latest refresh so its guard sees the current order.
  const latestRefresh = useRef(refresh);
  latestRefresh.current = refresh;
  useEffect(() => { active.current = true; return () => { active.current = false; }; }, []);
  const watching = pending.has(order.status);
  const polling = pollState === 'active' && watching;
  // Bounded automatic refresh: it stops on a settled order, on the first error, on unmount and after POLL_MAX_MS.
  useEffect(() => {
    if (!polling) return;
    pollStartedAt.current ??= Date.now();
    const timer = window.setInterval(() => {
      if (Date.now() - (pollStartedAt.current ?? Date.now()) >= POLL_MAX_MS) { setPollState('timed_out'); return; }
      void latestRefresh.current().then(ok => { if (!ok && active.current) setPollState('error'); });
    }, POLL_INTERVAL_MS);
    return () => window.clearInterval(timer);
  // The interval reads refresh through latestRefresh, so it never holds a stale closure.
  }, [polling]);
  // `paid` without a grant is retried by the server; the owner may also ask it to try again now.
  const checkable = order.status === 'not_started' || order.status === 'checkout_started' || order.status === 'paid';
  return <>
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/sound-kits" />
    <main id="main" className="editorial-page section-pad sound-kits-page">
      <section className="sound-kit-order" aria-labelledby="sound-kit-order-title">
        <p className="eyebrow">Test order · Stripe test mode</p>
        <h1 id="sound-kit-order-title">{order.kit.title}</h1>
        <p>{formatMoney(order.priceMinor, order.currency)}</p>
        <p role="status">{descriptions[order.status]}</p>
        {watching && <p className="fine-print" aria-live="polite" data-testid="order-auto-refresh">{polling ? 'Checking for updates automatically. This page refreshes about every 10 seconds for up to 15 minutes.'
          : pollState === 'timed_out' ? 'Automatic checking has stopped after 15 minutes. Reload the page to check again.'
            : 'Automatic checking has stopped because the status could not be loaded. Reload the page to check again.'}</p>}
        <div className="sound-kit-order-actions">
          {order.status === 'not_started' && <button type="button" className="button" disabled={busy} onClick={checkout}>Continue to Stripe test checkout</button>}
          {order.status === 'checkout_started' && <button type="button" className="button" disabled={busy} onClick={checkout}>Continue to checkout</button>}
          {checkable && <button type="button" className="button" disabled={busy} onClick={reconcile}>Check payment status</button>}
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
