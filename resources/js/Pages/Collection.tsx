import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import type { CollectionDetail } from '../lib/collections';
import { formatTime, safeMediaUrl, type PageMetadata } from '../lib/catalog';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import '../../css/collections.css';

interface CollectionProps { siteContent: SiteContent; collection: CollectionDetail; metadata: PageMetadata }

export default function Collection({ siteContent, collection, metadata }: CollectionProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => (document.getElementById('collection-title') ?? document.getElementById('collections-title'))?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/collections" />
    <main id="main" className="editorial-page section-pad collections-page">
      <a className="text-link editorial-back" href={href('/collections')} onClick={navigate}>Back to collections <Icon name="arrow" size={16} /></a>
      <article className="collection-detail" aria-labelledby="collection-title">
        <header>
          <p className="eyebrow">{collection.trackCount} {collection.trackCount === 1 ? 'track' : 'tracks'}</p>
          <h1 id="collection-title" tabIndex={-1}>{collection.title}</h1>
          {collection.description && <p className="collection-description">{collection.description}</p>}
          <p className="fine-print">Each track is licensed on its own page. A collection has no price of its own.</p>
        </header>
        <section className="collection-tracks" aria-labelledby="collection-tracks-title">
          <h2 id="collection-tracks-title">Tracks</h2>
          <ol>{collection.tracks.map(track => {
            const art = safeMediaUrl(track.artworkUrl);
            return <li key={track.id}>
              <div className="collection-track-art">{art ? <img src={art} alt="" width={64} height={64} loading="lazy" decoding="async" /> : <Icon name="music" size={24} />}</div>
              <div className="collection-track-body">
                <a className="collection-track-title" href={href(track.path)} onClick={navigate}>{track.title}</a>
                <span className="collection-track-artist">{track.artist}</span>
                <span className="collection-track-meta">{track.bpm} BPM · {track.musicalKey} · <time dateTime={`PT${Math.floor(track.durationSeconds / 60)}M${Math.floor(track.durationSeconds % 60)}S`}>{formatTime(track.durationSeconds)}</time></span>
              </div>
              <a className="text-link" href={href(track.path)} onClick={navigate} aria-label={`License options for ${track.title}`}>License options <Icon name="arrow" size={16} /></a>
            </li>;
          })}</ol>
        </section>
      </article>
    </main>
    <SiteFooter {...chrome}><InstallApp /></SiteFooter>
  </>;
}
