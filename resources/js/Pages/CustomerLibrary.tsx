import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { CustomerPurchaseClaim } from '../components/CustomerPurchaseClaim';
import type { PendingPurchaseClaim } from '../lib/purchase-claim';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { CustomerOrderLookup } from '../components/CustomerOrderLookup';
import { OwnedMembershipHistory } from '../components/OwnedMembershipHistory';
import { OwnedTestOrderHistory } from '../components/OwnedTestOrderHistory';
import { OrderStatus } from '../components/OrderPreparation';
import type { SiteContent } from '../lib/site-content';
import { changeCustomerSession, navigateCustomerSession } from '../lib/customer-session';
import '../../css/customer-account.css';

export default function CustomerLibrary({ siteContent, customer, testCheckoutEnabled = false, testMembershipsEnabled = false, membershipHistoryScope, guestPurchaseClaim }: {
  testOnly: true; siteContent: SiteContent; customer: { name: string }; testCheckoutEnabled?: boolean; testMembershipsEnabled?: boolean; membershipHistoryScope?: string | null; guestPurchaseClaim?: PendingPurchaseClaim;
}) {
  const [leaving, setLeaving] = useState(false), [message, setMessage] = useState('');
  const active = useRef(false), pending = useRef(false);
  const controller = useRef<AbortController | null>(null);
  const alert = useRef<HTMLDivElement>(null);
  useEffect(() => { active.current = true; return () => { active.current = false; controller.current?.abort(); }; }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);

  async function signOut() {
    if (pending.current || leaving) return;
    pending.current = true; setLeaving(true); setMessage('');
    const abort = new AbortController(); controller.current = abort;
    const timer = window.setTimeout(() => abort.abort(), 20_000);
    try {
      const result = await changeCustomerSession('sign-out', null, abort.signal);
      if (!active.current) return;
      if (result.kind === 'complete') { navigateCustomerSession(result.next); return; }
      setMessage(result.message);
    } finally { window.clearTimeout(timer); controller.current = null; }
  }
  const chrome = { content: siteContent, homeHref: '/', href: (path: string) => path, onNavigate: () => {} };
  return <>
    <Head title="Your test order library"><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} customerAccountEnabled currentPath="/account" />
    <main id="main" className="customer-account customer-library section-pad">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / TEST ACCOUNT</p><h1>Your test order library</h1></div>
      {leaving ? <section aria-label="Customer session" aria-busy={!message}>
        {message ? <div className="customer-account-message" role="alert" tabIndex={-1} ref={alert}>{message}<a href="/account/sign-in">Open a fresh sign-in page</a></div> : <p role="status">Signing out…</p>}
      </section> : <>
        <div className="customer-account-identity"><p>Signed in as <strong>{customer.name}</strong></p><button type="button" className="button button-outline" onClick={() => void signOut()}>Sign out</button></div>
        <p className="customer-account-note">Test mode only. Order status, contracts and downloads remain subject to their current availability checks.</p>
        {guestPurchaseClaim && <CustomerPurchaseClaim key={guestPurchaseClaim.orderId} claim={guestPurchaseClaim} />}
        <CustomerOrderLookup renderOrder={order => <OrderStatus order={order} testCheckoutEnabled={testCheckoutEnabled} />} />
        {testMembershipsEnabled === true && typeof membershipHistoryScope === 'string' && /^[0-9a-f]{32}$/.test(membershipHistoryScope) && <OwnedMembershipHistory key={membershipHistoryScope} />}
        <OwnedTestOrderHistory scope="account" renderOrder={order => <OrderStatus order={order} testCheckoutEnabled={testCheckoutEnabled} />} />
      </>}
      <a className="text-link customer-account-back" href="/">Back to the catalog</a>
    </main>
    <SiteFooter {...chrome} />
  </>;
}
