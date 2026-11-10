import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { AlbumCover } from '../components/AlbumCover';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import { releaseDateLabel, type AlbumDetail } from '../lib/albums';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import type { PageMetadata } from '../lib/catalog';
import '../../css/albums.css';

interface AlbumProps { siteContent: SiteContent; album: AlbumDetail; metadata: PageMetadata }

export default function Album({ siteContent, album, metadata }: AlbumProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => (document.getElementById('album-title') ?? document.getElementById('albums-title'))?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/albums" />
    <main id="main" className="editorial-page section-pad albums-page">
      <a className="text-link editorial-back" href={href('/albums')} onClick={navigate}>Back to albums <Icon name="arrow" size={16} /></a>
      <article className="album-detail" aria-labelledby="album-title">
        <div className="album-detail-art"><AlbumCover cover={album.cover} sizes="(min-width: 900px) 480px, 90vw" priority /></div>
        <div className="album-detail-body">
          <p className="eyebrow">{album.typeLabel} · <time dateTime={album.releaseDate}>{releaseDateLabel(album.releaseDate)}</time></p>
          <h1 id="album-title" tabIndex={-1}>{album.title}</h1>
          <p className="album-artist">{album.artist}</p>
          {album.description && <p className="album-description">{album.description}</p>}
          {album.links.length > 0 && <section aria-labelledby="album-listen-title">
            <h2 id="album-listen-title">Listen</h2>
            <ul className="album-links">{album.links.map(link => <li key={link.platform}>
              <a className="button button-outline" href={link.url} rel="noopener noreferrer" aria-label={`Listen to ${album.title} on ${link.label}`}>{link.label} <Icon name="northeast" size={16} /></a>
            </li>)}</ul>
            <p className="fine-print">These links open the release on the named service. VASEY.AUDIO does not sell this release.</p>
          </section>}
        </div>
        <section className="album-tracklist" aria-labelledby="album-tracks-title">
          <h2 id="album-tracks-title">Tracklist</h2>
          <ol>{album.tracks.map(track => <li key={track.position}>
            <span className="album-track-title">{track.title}</span>
            <span className="album-track-time"><span className="sr-only">Length </span><time dateTime={`PT${Math.floor(track.durationSeconds / 60)}M${track.durationSeconds % 60}S`}>{track.duration}</time></span>
          </li>)}</ol>
        </section>
      </article>
    </main>
    <SiteFooter {...chrome}><InstallApp /></SiteFooter>
  </>;
}
