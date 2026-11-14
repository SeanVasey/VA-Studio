import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { AlbumCover } from '../components/AlbumCover';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import type { SoundKitSummary } from '../lib/sound-kits';
import { formatMoney, type PageMetadata } from '../lib/catalog';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import '../../css/sound-kits.css';

interface SoundKitsProps { siteContent: SiteContent; kits: SoundKitSummary[]; metadata: PageMetadata }

export default function SoundKits({ siteContent, kits, metadata }: SoundKitsProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => (document.getElementById('sound-kits-title') ?? document.getElementById('sound-kit-title'))?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/sound-kits" />
    <main id="main" className="editorial-page section-pad sound-kits-page">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / sound kits</p>
        <h1 id="sound-kits-title" tabIndex={-1}>Sound kits</h1>
        <p className="editorial-description">Sample kits from the studio.</p>
      </div>
      {kits.length === 0
        ? <p className="sound-kits-empty">No sound kits are published yet.</p>
        : <ul className="sound-kit-list" aria-label="Sound kits">{kits.map(kit => <li className="sound-kit-card" key={kit.slug}>
          {kit.cover && <div className="sound-kit-art"><AlbumCover cover={kit.cover} sizes="(min-width: 900px) 320px, 100vw" /></div>}
          <h2><a href={href(kit.path)} onClick={navigate}>{kit.title}</a></h2>
          <p className="sound-kit-meta">{kit.memberCount} {kit.memberCount === 1 ? 'sample' : 'samples'}</p>
          <p className="sound-kit-price">{formatMoney(kit.priceMinor, kit.currency)}</p>
          <a className="text-link" href={href(kit.path)} onClick={navigate} aria-label={`View ${kit.title}`}>View kit <Icon name="arrow" size={16} /></a>
        </li>)}</ul>}
    </main>
    <SiteFooter {...chrome}><InstallApp /></SiteFooter>
  </>;
}
