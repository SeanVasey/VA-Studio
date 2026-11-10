import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import type { CollectionSummary } from '../lib/collections';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import type { PageMetadata } from '../lib/catalog';
import '../../css/collections.css';

interface CollectionsProps { siteContent: SiteContent; collections: CollectionSummary[]; metadata: PageMetadata }

export default function Collections({ siteContent, collections, metadata }: CollectionsProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => document.getElementById('collections-title')?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/collections" />
    <main id="main" className="editorial-page section-pad collections-page">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / collections</p>
        <h1 id="collections-title" tabIndex={-1}>Collections</h1>
        <p className="editorial-description">Beats grouped to browse together. Each track is licensed on its own page; a collection has no price.</p>
      </div>
      {collections.length === 0
        ? <p className="collections-empty">No collections are published yet.</p>
        : <ul className="collection-list" aria-label="Collections">{collections.map(collection => <li className="collection-card" key={collection.slug}>
          <h2><a href={href(collection.path)} onClick={navigate}>{collection.title}</a></h2>
          <p className="collection-meta">{collection.trackCount} {collection.trackCount === 1 ? 'track' : 'tracks'}</p>
          {collection.preview.length > 0 && <p className="collection-preview">{collection.preview.join(' · ')}</p>}
          <a className="text-link" href={href(collection.path)} onClick={navigate} aria-label={`View ${collection.title}`}>View collection <Icon name="arrow" size={16} /></a>
        </li>)}</ul>}
    </main>
    <SiteFooter {...chrome}><InstallApp /></SiteFooter>
  </>;
}
