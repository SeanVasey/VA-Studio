import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import type { SoundKitDetail } from '../lib/sound-kits';
import { formatMoney, formatTime, type PageMetadata } from '../lib/catalog';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import '../../css/sound-kits.css';

interface SoundKitProps { siteContent: SiteContent; kit: SoundKitDetail; metadata: PageMetadata }

export default function SoundKit({ siteContent, kit, metadata }: SoundKitProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => (document.getElementById('sound-kit-title') ?? document.getElementById('sound-kits-title'))?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/sound-kits" />
    <main id="main" className="editorial-page section-pad sound-kits-page">
      <a className="text-link editorial-back" href={href('/sound-kits')} onClick={navigate}>Back to sound kits <Icon name="arrow" size={16} /></a>
      <article className="sound-kit-detail" aria-labelledby="sound-kit-title">
        <header>
          <p className="eyebrow">{kit.memberCount} {kit.memberCount === 1 ? 'sample' : 'samples'}</p>
          <h1 id="sound-kit-title" tabIndex={-1}>{kit.title}</h1>
          {kit.summary && <p className="sound-kit-summary">{kit.summary}</p>}
        </header>
        <section className="sound-kit-facts" aria-labelledby="sound-kit-facts-title">
          <h2 id="sound-kit-facts-title">Details</h2>
          <dl>
            <dt>Samples</dt><dd>{kit.memberCount}</dd>
            <dt>Total length</dt><dd>{formatTime(kit.totalDurationSeconds)}</dd>
            <dt>Price</dt><dd>{formatMoney(kit.priceMinor, kit.currency)}</dd>
          </dl>
        </section>
        {!kit.purchasable && <p className="sound-kit-notice" role="status">This kit is not available to buy yet.</p>}
      </article>
    </main>
    <SiteFooter {...chrome}><InstallApp /></SiteFooter>
  </>;
}
