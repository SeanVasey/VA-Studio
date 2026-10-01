import { useEffect, useRef, type MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { TestCheckout } from '../components/TestCheckout';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { PersistentPlayer } from '../components/PersistentPlayer';
import { PrivatePageHead } from '../components/PrivatePageHead';
import { defaultSiteContent, type SiteContent } from '../lib/site-content';
import type { Track } from '../lib/catalog';

const EMPTY_TRACKS: Track[] = [];

export default function CheckoutReturn({ orderId, siteContent = defaultSiteContent }: { orderId: string; siteContent?: SiteContent }) {
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus({ preventScroll: true }); }, []);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    const destination = event.currentTarget.href;
    router.visit(destination, { preserveScroll: false, onSuccess: () => {
      const fragment = new URL(destination).hash.slice(1);
      const target = (fragment ? document.getElementById(fragment)?.querySelector<HTMLElement>('h1, h2') : null)
        ?? document.getElementById('editorial-title') ?? document.getElementById('detail-title') ?? document.getElementById('catalog-title');
      if (target) { target.tabIndex = -1; target.focus({ preventScroll: true }); }
    } });
  }
  const chrome = { content: siteContent, homeHref: '/', href: (path: string) => path, onNavigate: navigate };
  return <>
    <PrivatePageHead />
    <a className="skip-link" href="#main" onClick={() => document.getElementById('main')?.focus()}>Skip to content</a>
    <SiteHeader {...chrome} />
    <main id="main" tabIndex={-1} aria-labelledby="checkout-return-title" className="editorial-page section-pad">
      <div className="editorial-heading">
        <p className="eyebrow">VASEY.AUDIO / TEST CHECKOUT</p>
        <h1 id="checkout-return-title" tabIndex={-1} ref={heading}>CHECKOUT STATUS</h1>
        <p className="editorial-description">Returning from Stripe does not verify a payment. The status below comes from the saved test order. Refresh it to check for updates.</p>
      </div>
      <TestCheckout orderId={orderId} />
      <a className="text-link" href="/" onClick={navigate}>Return to the catalog</a>
    </main>
    <SiteFooter {...chrome} />
    <PersistentPlayer tracks={EMPTY_TRACKS} catalogUrl="/#catalog" onNavigate={navigate} onLicense={track => router.visit(track.shareUrl)} />
  </>;
}
