import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { AlbumCover } from '../components/AlbumCover';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import { PersistentPlayer } from '../components/PersistentPlayer';
import { player, useAudio } from '../lib/audio';
import type { SoundKitDetail } from '../lib/sound-kits';
import { formatMoney, formatTime, type PageMetadata, type Track } from '../lib/catalog';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import '../../css/sound-kits.css';

interface SoundKitProps { siteContent: SiteContent; kit: SoundKitDetail; metadata: PageMetadata }

/** The demo as the storefront player's track shape: the same player the catalog uses, with no offers, so it never offers a licence. */
function demoTrack(kit: SoundKitDetail): Track | null {
  if (!kit.demo) return null;
  const art = kit.cover?.jpeg[kit.cover.jpeg.length - 1];
  return { id: `sound-kit:${kit.slug}`, slug: kit.slug, title: `${kit.title} (demo)`, artist: 'VASEY.AUDIO', bpm: 0, musicalKey: '', genre: '', mood: '',
    durationSeconds: kit.demo.durationSeconds, artworkUrl: art?.url ?? null, previewUrl: kit.demo.url, waveform: kit.demo.waveform, tags: [], offers: [], shareUrl: kit.path };
}

export default function SoundKit({ siteContent, kit, metadata }: SoundKitProps) {
  const audio = useAudio();
  const demo = demoTrack(kit);
  const playing = demo !== null && audio.track?.id === demo.id && (audio.status === 'playing' || audio.status === 'loading');
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
        {kit.cover && <div className="sound-kit-art"><AlbumCover cover={kit.cover} sizes="(min-width: 900px) 480px, 100vw" priority /></div>}
        <header>
          <p className="eyebrow">{kit.memberCount} {kit.memberCount === 1 ? 'sample' : 'samples'}</p>
          <h1 id="sound-kit-title" tabIndex={-1}>{kit.title}</h1>
          {kit.summary && <p className="sound-kit-summary">{kit.summary}</p>}
        </header>
        {demo && kit.demo && <section className="sound-kit-demo" aria-labelledby="sound-kit-demo-title">
          <h2 id="sound-kit-demo-title">Demo</h2>
          <div className="sound-kit-demo-controls">
            <button type="button" className="button" onClick={() => player.toggle(demo)} aria-label={playing ? `Pause the demo of ${kit.title}` : `Play the demo of ${kit.title}`}>
              <Icon name={playing ? 'pause' : 'play'} />{playing ? 'Pause demo' : 'Play demo'}
            </button>
            <span className="sound-kit-meta">{formatTime(kit.demo.durationSeconds)}</span>
          </div>
          <p className="fine-print">A tagged preview of sounds from this kit. The files you would own are not part of it.</p>
        </section>}
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
    <PersistentPlayer tracks={demo ? [demo] : []} purchasingDisabled catalogUrl={href('/#catalog')} onNavigate={navigate} onLicense={() => undefined} />
  </>;
}
