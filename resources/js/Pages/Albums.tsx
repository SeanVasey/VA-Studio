import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { AlbumCover } from '../components/AlbumCover';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import { releaseDateLabel, type AlbumSummary } from '../lib/albums';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import type { PageMetadata } from '../lib/catalog';
import '../../css/albums.css';

interface AlbumsProps { siteContent: SiteContent; albums: AlbumSummary[]; metadata: PageMetadata }

export default function Albums({ siteContent, albums, metadata }: AlbumsProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => document.getElementById('albums-title')?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/albums" />
    <main id="main" className="editorial-page section-pad albums-page">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / albums</p>
        <h1 id="albums-title" tabIndex={-1}>Albums</h1>
        <p className="editorial-description">Music released by Sean Vasey. Open a release for its tracklist and where to listen.</p>
      </div>
      {albums.length === 0
        ? <p className="albums-empty">No releases are published yet.</p>
        : <ul className="album-grid" aria-label="Releases, newest first">{albums.map(album => <li className="album-card" key={album.slug}>
          <div className="album-card-art"><AlbumCover cover={album.cover} sizes="(min-width: 900px) 280px, 45vw" /></div>
          <div className="album-card-body">
            <p className="album-meta"><span>{album.typeLabel}</span> · <time dateTime={album.releaseDate}>{releaseDateLabel(album.releaseDate)}</time></p>
            <h2><a href={href(album.path)} onClick={navigate}>{album.title}</a></h2>
            <p className="album-artist">{album.artist}</p>
            <p className="album-meta">{album.trackCount} {album.trackCount === 1 ? 'track' : 'tracks'}</p>
            <a className="text-link" href={href(album.path)} onClick={navigate} aria-label={`View ${album.title}`}>View release <Icon name="arrow" size={16} /></a>
          </div>
        </li>)}</ul>}
    </main>
    <SiteFooter {...chrome}><InstallApp /></SiteFooter>
  </>;
}
