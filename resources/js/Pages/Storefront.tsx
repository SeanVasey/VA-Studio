import { useEffect, useMemo, useState, type MouseEvent, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { Modal } from '../components/Modal';
import { PersistentPlayer } from '../components/PersistentPlayer';
import { QuoteReview } from '../components/QuoteReview';
import { PreparedOrderRecovery } from '../components/OrderPreparation';
import { TrackDetail } from '../components/TrackDetail';
import { LicenseDisclosure } from '../components/LicenseDisclosure';
import { MetadataHead } from '../components/MetadataHead';
import { player, useAudio } from '../lib/audio';
import { availableOffer, savedCartSelection, cartSubtotals, fileRoleLabels, filterTracks, formatMoney, formatTime, safeMediaUrl, type Offer, type CatalogFilters, type StorefrontProps, type Track } from '../lib/catalog';

import { useCart } from '../lib/useCart';
import { builtInSiteImages, defaultSiteContent, siteContentHref } from '../lib/site-content';
import { HeroPicture, StudioPicture } from '../components/SiteImagery';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';

const EMPTY_TRACKS: Track[] = [];

function Artwork({ track, size = 64 }: { track: Track; size?: number }) {
  const src = safeMediaUrl(track.artworkUrl);
  return <div className="track-art" style={{ width: size, height: size }}>{src ? <img src={src} alt="" width={size} height={size} loading="lazy" /> : <Icon name="music" size={24} />}</div>;
}

function Waveform({ track, current, time, duration }: { track: Track; current: boolean; time: number; duration: number }) {
  const peaks = track.waveform.filter(value => Number.isFinite(value) && value >= 0).slice(0, 80);
  if (!peaks.length) return <span className="waveform-empty">{safeMediaUrl(track.previewUrl) ? 'Preview available' : 'Preview unavailable'}</span>;
  const max = Math.max(...peaks, 1);
  return <div className="waveform" aria-hidden="true">{peaks.map((peak, index) => <span key={index} className={current && index / peaks.length < time / (duration || track.durationSeconds || 1) ? 'played' : ''} style={{ height: `${Math.max(6, peak / max * 100)}%` }} />)}</div>;
}

function navigate(event: MouseEvent<HTMLAnchorElement>, preview = false) {
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  if (!preview) router.visit(event.currentTarget.href, { preserveScroll: false, preserveState: true, onSuccess: () => { (document.getElementById('editorial-title') ?? document.getElementById('detail-title') ?? document.getElementById('catalog-title'))?.focus({ preventScroll: true }); } });
}

function TrackRow({ track, index, onLicense, onShare, designPreview, purchasingDisabled = false, querySuffix = '' }: { track: Track; index: number; onLicense: (track: Track) => void; onShare: (track: Track) => void; designPreview: boolean; purchasingDisabled?: boolean; querySuffix?: string }) {
  const audio = useAudio();
  const current = audio.track?.id === track.id && audio.track?.previewUrl === track.previewUrl;
  const active = current && (audio.status === 'playing' || audio.status === 'loading');
  const offers = track.offers.filter(availableOffer);
  const cheapest = offers.reduce<Offer | null>((result, offer) => !result || offer.priceMinor < result.priceMinor ? offer : result, null);
  const preview = safeMediaUrl(track.previewUrl);
  return <article className={`track-row ${current ? 'is-current' : ''}`} aria-label={track.title} id={`track-${track.slug}`}>
    <span className="track-index">{String(index + 1).padStart(2, '0')}</span>
    <button className="track-play" onClick={() => player.toggle(track)} disabled={!preview} aria-label={!preview ? `Preview unavailable for ${track.title}` : active ? `Pause ${track.title}` : `Play ${track.title}`} title={!preview ? 'A published audio preview is not yet available.' : undefined}><Artwork track={track} /><span className="play-overlay"><Icon name={active ? 'pause' : 'play'} size={19} /></span></button>
    <div className="track-title"><h3><a href={safeMediaUrl(track.shareUrl + querySuffix)} onClick={event => navigate(event, designPreview)}>{track.title}</a></h3><p>{track.artist}</p><div className="track-mobile-meta">{track.bpm} BPM <span>·</span> {track.musicalKey} <span>·</span> {track.genre}</div></div>
    <div className="track-genre"><span>{track.genre}</span><span>{track.mood}</span></div>
    <div className="track-key"><span>{track.bpm} <small>BPM</small></span><span>{track.musicalKey}</span></div>
    <div className="track-wave"><Waveform track={track} current={current} time={audio.currentTime} duration={audio.duration} /><span>{formatTime(track.durationSeconds)}</span></div>
    <button className="icon-button track-share" onClick={() => onShare(track)} aria-label={`Share ${track.title}`}><Icon name="share" size={18} /></button>
    <button className="track-license" disabled={!cheapest || purchasingDisabled} onClick={() => onLicense(track)} aria-label={cheapest ? `Choose license for ${track.title}, from ${formatMoney(cheapest.priceMinor, cheapest.currency)}` : `Licenses unavailable for ${track.title}`}><span>{cheapest ? <><small>from </small>{formatMoney(cheapest.priceMinor, cheapest.currency)}</> : 'Unavailable'}</span>{cheapest && <Icon name="bag" size={17} />}</button>
  </article>;
}

export default function Storefront({ tracks = EMPTY_TRACKS, licenseTiers = [], selectedTrackSlug, selectedTrack, catalogPage, designPreview = false, sitePreview = false, sitePreviewBase = null, siteContent = defaultSiteContent, siteImages = builtInSiteImages, testOrderPreparationEnabled = false, testCheckoutEnabled = false, metadata }: StorefrontProps) {
  const [query, setQuery] = useState(catalogPage?.filters.q ?? '');
  const [genre, setGenre] = useState(catalogPage?.filters.genre || 'All sounds');
  const [sort, setSort] = useState<CatalogFilters['sort']>(catalogPage?.filters.sort ?? 'featured');
  const [browsing, setBrowsing] = useState(false);
  const [browseError, setBrowseError] = useState('');
  const [licenseTrack, setLicenseTrack] = useState<Track | null>(null);
  const [selectedOfferId, setSelectedOfferId] = useState('');
  const [showFullTerms, setShowFullTerms] = useState(false);
  const [cartOpen, setCartOpen] = useState(false);
  const [notice, setNotice] = useState('');
  const [shareFallback, setShareFallback] = useState<string | null>(null);
  const [checkoutStatus, setCheckoutStatus] = useState('');
  const [checkingOut, setCheckingOut] = useState(false);
  const pageTracks = useMemo(() => selectedTrack && !tracks.some(track => track.id === selectedTrack.id) ? [selectedTrack, ...tracks] : tracks, [tracks, selectedTrack]);
  const { lines, selections, setLines, unavailable, pending, error: cartError, retry, tiers } = useCart(pageTracks, !!catalogPage && !designPreview && !sitePreview, !sitePreview);
  const knownTracks = useMemo(() => [...pageTracks, ...lines.map(line => line.track)], [pageTracks, lines]);
  const knownTiers = [...licenseTiers, ...tiers];
  useEffect(() => {
    if (!catalogPage) return;
    setQuery(catalogPage.filters.q); setGenre(catalogPage.filters.genre || 'All sounds'); setSort(catalogPage.filters.sort);
  }, [catalogPage]);
  const querySuffix = catalogPage ? catalogPage.currentUrl.slice(1) : '';
  const heroAccentStart = siteContent.hero.line_two.lastIndexOf(' ') + 1;
  function navigationHref(href: string) {
    if (sitePreviewBase) return siteContentHref(href, sitePreviewBase);
    if (href === '/') return sitePreview ? '#main' : '/' + querySuffix;
    if (!href.startsWith('/#')) return sitePreview ? '#main' : href;
    return selectedTrack && href === '/#licenses' ? '/' + querySuffix + href.slice(1) : href.slice(1);
  }
  function navigateContent(event: MouseEvent<HTMLAnchorElement>) {
    if (event.currentTarget.getAttribute('href')?.startsWith('#')) return;
    navigate(event, designPreview || (sitePreview && !sitePreviewBase));
  }
  function browse(url: string) {
    if (sitePreview) return;
    setBrowsing(true); setBrowseError('');
    router.visit(url, { preserveScroll: true, preserveState: true,
      onSuccess: () => { document.getElementById('catalog-title')?.focus({ preventScroll: true }); },
      onError: () => setBrowseError('The catalog could not be updated. Try again or restart your search.'),
      onFinish: () => setBrowsing(false),
    });
  }
  function applyFilters(changes: Partial<CatalogFilters> = {}) {
    const filters = { q: query, genre: genre === 'All sounds' ? '' : genre, sort, ...changes };
    setQuery(filters.q); setGenre(filters.genre || 'All sounds'); setSort(filters.sort);
    if (catalogPage && !designPreview && !sitePreview) browse('/?' + new URLSearchParams(filters).toString());
  }
  function search(event: FormEvent) { event.preventDefault(); applyFilters(); }

  useEffect(() => { if (unavailable) setNotice('Some saved selections changed or are no longer available. Please choose their licenses again.'); }, [unavailable]);
  const genres = useMemo(() => ['All sounds', ...new Set([...(genre === 'All sounds' ? [] : [genre]), ...tracks.map(track => track.genre).filter(Boolean)])], [tracks, genre]);
  const visibleTracks = useMemo(() => catalogPage && !designPreview && !sitePreview ? tracks : filterTracks(tracks, query, genre, sort), [tracks, query, genre, sort, catalogPage, designPreview, sitePreview]);
  const hasCatalogContext = tracks.length > 0 || (catalogPage ? !!(catalogPage.filters.q || catalogPage.filters.genre || catalogPage.hasCursor || catalogPage.nextUrl) : !!(query || genre !== 'All sounds'));
  const selectedOffer = licenseTrack?.offers.find(offer => offer.id === selectedOfferId && availableOffer(offer));

  function openLicense(track: Track, requestedOffer?: Offer) {
    if (sitePreview) return;
    setShowFullTerms(!!requestedOffer);
    if (!knownTracks.some(item => item.id === track.id)) { if (!designPreview) router.visit(track.shareUrl + querySuffix, { preserveScroll: true, preserveState: true }); return; }
    setLicenseTrack(knownTracks.find(item => item.id === track.id) ?? track); setSelectedOfferId(requestedOffer?.id ?? lines.find(line => line.track.id === track.id)?.offer.id ?? track.offers.find(availableOffer)?.id ?? ''); }
  function addLicense() {
    if (sitePreview || !licenseTrack || !selectedOffer) return;
    if (pending || cartError) { setNotice('Your saved selections need to be checked before adding another license. Open the cart to retry.'); return; }
    if (lines.length >= 10 && !lines.some(line => line.track.id === licenseTrack.id)) { setNotice('You can select up to 10 tracks at a time.'); return; }
    const track = knownTracks.find(track => track.id === licenseTrack.id);
    const currentOffer = track?.offers.find(offer => offer.id === selectedOffer.id && offer.offerRevisionId === selectedOffer.offerRevisionId && offer.licenseVersionId === selectedOffer.licenseVersionId && availableOffer(offer));
    if (!track || !currentOffer) {
      setLicenseTrack(null);
      setNotice('This offer changed. Please choose its license again.');
      return;
    }
    setLines(existing => [...existing.filter(line => line.track.id !== track.id), { track, offer: currentOffer }]);
    setLicenseTrack(null);
    setNotice(`${selectedOffer.licenseName} for ${track.title} added to your cart.`);
  }
  async function share(track: Track) {
    setShareFallback(null);
    try {
      const url = new URL(track.shareUrl, window.location.origin);
      if (url.origin !== window.location.origin) throw new Error('External share URL');
      await navigator.clipboard.writeText(url.href);
      setNotice(`Link copied for ${track.title}.`);
    } catch { setShareFallback(safeMediaUrl(track.shareUrl) ?? null); setNotice('The link could not be copied. Use the track link to share from your browser.'); }
  }
  async function checkout() {
    if (sitePreview || checkingOut || !lines.length || pending || cartError) return;
    setCheckingOut(true); setCheckoutStatus('');
    try {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      const response = await fetch('/checkout', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) }, body: JSON.stringify({ items: lines.map(savedCartSelection) }) });
      // Payment initiation is deliberately unavailable until the verified commerce pipeline is installed.
      setCheckoutStatus(response.status === 503 ? 'Checkout is not available yet. No payment was taken. Your selections are saved in this browser tab.' : 'Checkout could not be started. No order has been confirmed. Your selections are still here.');
    } catch { setCheckoutStatus('Unable to connect. No order has been confirmed. Your selections are still here; try again when connected.'); }
    finally { setCheckingOut(false); }
  }

  useEffect(() => {
    setLicenseTrack(null);
  }, [selectedTrackSlug]);

  return <>
    {metadata && !designPreview && <MetadataHead metadata={metadata} />}
    <a className="skip-link" href="#main">Skip to content</a>
    {designPreview && <div className="preview-banner">DEVELOPMENT PREVIEW <span>Sample catalog for design review. No purchases or licenses are issued.</span></div>}
    {sitePreview && <div className="preview-banner">PRIVATE CONTENT PREVIEW <span>Visible only to staff. Purchasing is disabled.</span></div>}
    <SiteHeader content={siteContent} homeHref={navigationHref('/')} href={navigationHref} onNavigate={navigateContent}>{!sitePreview && <button className="cart-toggle" aria-label={`Open cart, ${selections.length} ${selections.length === 1 ? 'item' : 'items'}`} onClick={() => { setCartOpen(true); setCheckoutStatus(''); }}><Icon name="bag" size={19} /><span className="cart-text">Cart</span><span className="cart-count">{String(selections.length).padStart(2, '0')}</span></button>}</SiteHeader>
    <main id="main">
      {selectedTrack ? <TrackDetail track={selectedTrack} tiers={knownTiers} catalogUrl={"/" + querySuffix} onNavigate={event => navigate(event, designPreview || sitePreview)} onLicense={openLicense} onShare={share} /> : <>
      <section className="hero" aria-labelledby="hero-title">
        <div className="hero-copy"><p className="eyebrow"><span className="small-rule" />{siteContent.hero.eyebrow}</p><h1 id="hero-title">{siteContent.hero.title}<br />{siteContent.hero.line_two.slice(0, heroAccentStart)}<span>{siteContent.hero.line_two.slice(heroAccentStart)}</span></h1><div className="hero-bottom"><p style={{ whiteSpace: 'pre-line' }}>{siteContent.hero.description}</p><a className="button" href="#catalog">Find your sound <Icon name="arrow" /></a></div></div>
        <div className="hero-media"><HeroPicture image={siteImages.hero} /><div className="hero-media-caption"><span className="eyebrow">THE INDEPENDENT FREQUENCY</span></div></div>
      </section>
      <div className="discipline-strip"><span>HIP-HOP / CINEMATIC / EXPERIMENTAL</span><span>COMPOSITION <i>+</i> PRODUCTION <i>+</i> SOUND DESIGN</span><a href="#studio">FROM THE STUDIO <Icon name="northeast" size={14} /></a></div></>}
      <section id="catalog" className="catalog section-pad" aria-labelledby="catalog-title">
        <div className="section-heading"><div><p className="eyebrow">01 / THE CATALOG</p><h2 id="catalog-title" tabIndex={-1}>FIND YOUR<br className="mobile-break" /> NEXT RECORD.</h2></div><p>Press play. Follow the feeling.<br />Find the foundation for something original.</p></div>
        <form className="catalog-tools" onSubmit={search} role="search"><div className="search-field"><Icon name="search" size={19} /><input type="search" aria-label="Search tracks" placeholder="Search by title, mood, genre…" value={query} onChange={event => setQuery(event.target.value)} />{query && <button className="icon-button" type="button" onClick={() => applyFilters({ q: '' })} aria-label="Clear search"><Icon name="close" size={16} /></button>}</div>{catalogPage && <button className="button button-outline" type="submit" disabled={browsing}>Search</button>}<label className="sort-field"><span>Sort by</span><select aria-label="Sort tracks" value={sort} onChange={event => applyFilters({ sort: event.target.value as CatalogFilters['sort'] })}><option value="featured">Newest releases</option><option value="tempo">Tempo: low to high</option><option value="title">Title: A–Z</option></select></label></form>
        <div className="catalog-filter-row"><div className="genre-filters" aria-label="Filter by genre">{genres.map(option => <button key={option} aria-pressed={genre === option} className={genre === option ? 'filter-selected' : ''} onClick={() => applyFilters({ genre: option === 'All sounds' ? '' : option })}>{option}</button>)}</div><span className="results-count" aria-live="polite">{String(visibleTracks.length).padStart(2, '0')} {visibleTracks.length === 1 ? 'TRACK' : 'TRACKS'}{catalogPage ? ' ON THIS PAGE' : ''}</span></div>
        {!!visibleTracks.length && <div className="track-list-header" aria-hidden="true"><span>TRACK / ARTIST</span><span>GENRE / MOOD</span><span>TEMPO / KEY</span><span>PREVIEW</span><span>LICENSE</span></div>}
        {catalogPage && <p className="fine-print">Genre shortcuts reflect this page. Search explores the full published catalog.</p>}
        <div className="track-list" aria-busy={browsing}>{visibleTracks.map((track, index) => <TrackRow key={track.id} track={track} index={index} onLicense={openLicense} onShare={share} designPreview={designPreview || sitePreview} purchasingDisabled={sitePreview} querySuffix={querySuffix} />)}</div>
        {!visibleTracks.length && <div className="catalog-empty"><span className="empty-motif"><Icon name={hasCatalogContext ? 'search' : 'music'} size={32} /></span><h3>{hasCatalogContext ? catalogPage?.nextUrl ? 'KEEP EXPLORING THE CATALOG.' : 'NO MATCHES. KEEP EXPLORING.' : 'A NEW CHAPTER IN SOUND.'}</h3><p>{hasCatalogContext ? catalogPage?.nextUrl ? 'Continue to the next page or try another search.' : 'Try another title, mood, or genre to find your next record.' : 'The independent catalog is being prepared. New music will appear here as releases are published.'}</p>{hasCatalogContext ? <button className="button button-outline" onClick={() => applyFilters({ q: '', genre: '' })}>Reset filters <Icon name="arrow" size={18} /></button> : <a className="text-link" href="#studio">Meet the producer <Icon name="arrow" size={16} /></a>}</div>}
        {catalogPage && !sitePreview && <nav className="catalog-pagination" aria-label="Catalog pages">
          {catalogPage.previousUrl && <a className="button button-outline" href={catalogPage.previousUrl} onClick={event => { if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) { event.preventDefault(); browse(catalogPage.previousUrl!); } }}>Previous tracks</a>}
          {catalogPage.hasCursor && <a className="text-link" href={catalogPage.restartUrl} onClick={event => { if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) { event.preventDefault(); browse(catalogPage.restartUrl); } }}>Back to first results</a>}
          {catalogPage.nextUrl && <a className="button button-outline" href={catalogPage.nextUrl} onClick={event => { if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) { event.preventDefault(); browse(catalogPage.nextUrl!); } }}>Next tracks</a>}
        </nav>}
        <p role="status" className="fine-print">{browsing ? 'Loading tracks…' : browseError}</p>
        <div className="catalog-footnote"><span>ORIGINAL MUSIC. A DIRECT CONNECTION TO THE CREATOR.</span><a className="text-link" href="#licenses">Find the right license <Icon name="northeast" size={14} /></a></div>
      </section>
      {!selectedTrack && <section id="licenses" className="license-section section-pad" aria-labelledby="licenses-title"><div className="section-heading"><div><p className="eyebrow">02 / MAKE IT YOURS</p><h2 id="licenses-title">YOUR RECORD.<br />THE RIGHT LICENSE.</h2></div><p>Choose a license that fits your release.<br />Review the terms and included files before you commit.</p></div>
        {licenseTiers.length ? <div className="license-grid">{licenseTiers.map((tier, index) => <article className="license-card" key={tier.id}><div className="license-number">0{index + 1}<span>{tier.type.replace(/_/g, ' ')}</span></div><h3>{tier.name}</h3><p className="license-files">{tier.requiredAssetRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</p><ul>{tier.features.map(feature => <li key={feature}><Icon name="check" size={16} />{feature}</li>)}</ul><div className="license-card-bottom"><span className="fine-print">Terms version {tier.version}</span><a className="text-link" href="#catalog">Choose a track <Icon name="arrow" size={16} /></a></div></article>)}</div> : <div className="license-empty"><div><h3>GOOD MUSIC. CLEAR TERMS.</h3><p>Release-specific licensing and file options will be listed alongside each published track. Published terms are not available yet.</p></div><a href="#catalog" className="button button-outline">Explore the catalog <Icon name="arrow" /></a></div>}
        <p className="licensing-note">License availability, pricing, and included files depend on the track. Only published terms apply.</p>
      </section>}
      <section id="studio" className="studio-section section-pad" aria-labelledby="studio-title"><div className="studio-visual"><StudioPicture image={siteImages.studio} /><span className="studio-caption">SEAN VASEY PRODUCTIONS / VASEY.AUDIO</span></div><div className="studio-copy"><p className="eyebrow">{siteContent.studio.eyebrow}</p><h2 id="studio-title">{siteContent.studio.title}<br />{siteContent.studio.line_two}</h2><p className="studio-lead">{siteContent.studio.lead}</p>{siteContent.studio.paragraphs.map((paragraph, index) => <p key={index} style={{ whiteSpace: 'pre-line' }}>{paragraph}</p>)}<div className="studio-disciplines"><span>MUSIC PRODUCTION</span><span>COMPOSITION</span><span>SOUND DESIGN</span></div></div></section>
      <section className="closing-statement"><span className="eyebrow">VASEY.AUDIO</span><p>MAKE SOMETHING<br /><span>ONLY YOU CAN.</span></p><a className="button button-outline" href="#catalog">Start with a sound <Icon name="northeast" /></a></section>
    </main>
    <SiteFooter content={siteContent} homeHref={navigationHref('/')} href={navigationHref} onNavigate={navigateContent} />
    <PersistentPlayer tracks={pageTracks} onLicense={openLicense} purchasingDisabled={sitePreview} catalogUrl={sitePreviewBase ? siteContentHref('/#catalog', sitePreviewBase) : '#catalog'} onNavigate={navigateContent} />
    <div className={`toast ${notice ? 'toast-visible' : ''}`} role="status">{notice && <><span>{notice}{shareFallback && <a className="text-link" href={shareFallback} onClick={event => navigate(event, designPreview || sitePreview)}>Open track link <Icon name="northeast" size={14} /></a>}</span><button className="icon-button" aria-label="Dismiss notification" onClick={() => { setNotice(''); setShareFallback(null); }}><Icon name="close" size={17} /></button></>}</div>
    {!sitePreview && licenseTrack && <Modal title="CHOOSE YOUR LICENSE." eyebrow={licenseTrack.title} onClose={() => setLicenseTrack(null)}><p className="modal-description">Select the published offer for this track. Review its files and version before adding it to your cart.</p><fieldset className="offer-options"><legend className="sr-only">Available licenses for {licenseTrack.title}</legend>{licenseTrack.offers.filter(availableOffer).map(offer => { const tier = knownTiers.find(tier => tier.id === offer.licenseVersionId); return <label className={`offer-option ${selectedOfferId === offer.id ? 'selected' : ''}`} key={offer.id}><input type="radio" name="license" value={offer.id} checked={selectedOfferId === offer.id} onChange={() => setSelectedOfferId(offer.id)} /><span className="offer-content"><span className="offer-heading"><strong>{offer.licenseName}</strong><strong>{formatMoney(offer.priceMinor, offer.currency)}</strong></span><span className="offer-files">{offer.deliverableRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</span>{tier && <span className="offer-terms">{tier.features.join(' · ')}</span>}<span className="fine-print">{tier ? `Published terms version ${tier.version}` : 'Published license offer'}</span></span></label>; })}</fieldset>{selectedOffer && <LicenseDisclosure offer={selectedOffer} defaultOpen={showFullTerms} />}<p className="checkout-advisory">{testCheckoutEnabled ? 'Stripe checkout is available only for prepared test orders. Adding a license saves a selection only.' : 'Checkout and contract delivery are not available yet. Adding a license saves a selection only.'}</p><button className="button full-width" disabled={!selectedOffer || pending || cartError} onClick={addLicense}>{selectedOffer ? `Add license · ${formatMoney(selectedOffer.priceMinor, selectedOffer.currency)}` : 'No license available'}<Icon name="bag" size={18} /></button></Modal>}
    {!sitePreview && cartOpen && <Modal title="YOUR SELECTIONS." eyebrow={`CART / ${selections.length} ${selections.length === 1 ? 'TRACK' : 'TRACKS'}`} onClose={() => setCartOpen(false)}>{!designPreview && <PreparedOrderRecovery testCheckoutEnabled={testCheckoutEnabled} />}{pending && <p role="status">Checking your saved selections…</p>}{cartError && <div role="alert"><p>Your selections are saved. Availability could not be checked.</p><button className="button button-outline" onClick={retry}>Retry selection check</button></div>}{lines.length ? <><div className="cart-lines">{lines.map(({ track, offer }) => <div className="cart-line" key={track.id}><Artwork track={track} size={52} /><div><h3>{track.title}</h3><p>{offer.licenseName}</p><button className="text-link small-text" disabled={pending || cartError} onClick={() => { setCartOpen(false); openLicense(track); }}>Change license</button></div><div className="cart-line-price"><strong>{formatMoney(offer.priceMinor, offer.currency)}</strong><button className="text-link small-text" aria-label={`Remove ${track.title} from cart`} disabled={pending || cartError} onClick={() => setLines(existing => existing.filter(line => line.track.id !== track.id))}>Remove</button></div></div>)}</div><div className="cart-total"><span>Estimated subtotal</span><div>{cartSubtotals(lines).map(total => <strong key={total.currency}>{formatMoney(total.amount, total.currency)} <small>{total.currency}</small></strong>)}</div></div><p className="fine-print">Prices and availability must be revalidated before purchase. Taxes, discounts, and final terms are not calculated here.</p>{!pending && !cartError && <QuoteReview lines={lines} designPreview={designPreview} testOrderPreparationEnabled={testOrderPreparationEnabled} testCheckoutEnabled={testCheckoutEnabled} />}{testCheckoutEnabled ? <p className="checkout-advisory">Use a prepared test order to open Stripe test checkout. Live payments and license delivery remain unavailable.</p> : <><p className="checkout-advisory">Checkout is not available yet. No payment will be taken and no license will be issued.</p><button className="button full-width" onClick={checkout} disabled={checkingOut || pending || cartError}>{checkingOut ? 'Checking availability…' : 'Check checkout availability'}<Icon name="arrow" /></button><p className="checkout-status" role="status">{checkoutStatus}</p></>}</> : !pending && !cartError ? <div className="empty-cart"><Icon name="bag" size={36} /><h3>ROOM FOR YOUR NEXT RECORD.</h3><p>Choose a track and license to get started.</p><button className="button" onClick={() => { setCartOpen(false); document.getElementById('catalog')?.scrollIntoView(); }}>Browse the catalog <Icon name="arrow" /></button></div> : null}</Modal>}
  </>;
}
