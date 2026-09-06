import { useEffect, useMemo, useState, type MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { Modal } from '../components/Modal';
import { PersistentPlayer } from '../components/PersistentPlayer';
import { QuoteReview } from '../components/QuoteReview';
import { player, useAudio } from '../lib/audio';
import { availableOffer, restoreCartSelections, savedCartSelection, cartSubtotals, fileRoleLabels, filterTracks, formatMoney, formatTime, safeMediaUrl, type CartLine, type Offer, type StorefrontProps, type Track } from '../lib/catalog';

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
  if (!preview) router.visit(event.currentTarget.href, { preserveScroll: true, preserveState: true });
}

function TrackRow({ track, index, onLicense, onShare, designPreview }: { track: Track; index: number; onLicense: (track: Track) => void; onShare: (track: Track) => void; designPreview: boolean }) {
  const audio = useAudio();
  const current = audio.track?.id === track.id;
  const active = current && (audio.status === 'playing' || audio.status === 'loading');
  const offers = track.offers.filter(availableOffer);
  const cheapest = offers.reduce<Offer | null>((result, offer) => !result || offer.priceMinor < result.priceMinor ? offer : result, null);
  const preview = safeMediaUrl(track.previewUrl);
  return <article className={`track-row ${current ? 'is-current' : ''}`} aria-label={track.title} id={`track-${track.slug}`}>
    <span className="track-index">{String(index + 1).padStart(2, '0')}</span>
    <button className="track-play" onClick={() => player.toggle(track)} disabled={!preview} aria-label={!preview ? `Preview unavailable for ${track.title}` : active ? `Pause ${track.title}` : `Play ${track.title}`} title={!preview ? 'A published audio preview is not yet available.' : undefined}><Artwork track={track} /><span className="play-overlay"><Icon name={active ? 'pause' : 'play'} size={19} /></span></button>
    <div className="track-title"><h3><a href={safeMediaUrl(track.shareUrl)} onClick={event => navigate(event, designPreview)}>{track.title}</a></h3><p>{track.artist}</p><div className="track-mobile-meta">{track.bpm} BPM <span>·</span> {track.musicalKey} <span>·</span> {track.genre}</div></div>
    <div className="track-genre"><span>{track.genre}</span><span>{track.mood}</span></div>
    <div className="track-key"><span>{track.bpm} <small>BPM</small></span><span>{track.musicalKey}</span></div>
    <div className="track-wave"><Waveform track={track} current={current} time={audio.currentTime} duration={audio.duration} /><span>{formatTime(track.durationSeconds)}</span></div>
    <button className="icon-button track-share" onClick={() => onShare(track)} aria-label={`Share ${track.title}`}><Icon name="share" size={18} /></button>
    <button className="track-license" disabled={!cheapest} onClick={() => onLicense(track)} aria-label={cheapest ? `Choose license for ${track.title}, from ${formatMoney(cheapest.priceMinor, cheapest.currency)}` : `Licenses unavailable for ${track.title}`}><span>{cheapest ? <><small>from </small>{formatMoney(cheapest.priceMinor, cheapest.currency)}</> : 'Unavailable'}</span>{cheapest && <Icon name="bag" size={17} />}</button>
  </article>;
}

function useCart(tracks: Track[]) {
  const [cart, setCart] = useState(() => {
    try {
      return restoreCartSelections(JSON.parse(sessionStorage.getItem('vaseyaudio-cart-v1') ?? '[]'), tracks);
    } catch { return { lines: [] as CartLine[], unavailable: 0 }; }
  });
  useEffect(() => {
    setCart(current => {
      const checked = restoreCartSelections(current.lines.map(savedCartSelection), tracks);
      return { lines: checked.lines, unavailable: current.unavailable + checked.unavailable };
    });
  }, [tracks]);
  useEffect(() => {
    try { sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify(cart.lines.map(savedCartSelection))); } catch { /* Selections remain usable when browser storage is unavailable. */ }
  }, [cart.lines]);
  const setLines = (update: (lines: CartLine[]) => CartLine[]) => setCart(current => ({ ...current, lines: update(current.lines) }));
  return { lines: cart.lines, setLines, unavailable: cart.unavailable };
}

export default function Storefront({ tracks = EMPTY_TRACKS, licenseTiers = [], selectedTrackSlug, designPreview = false }: StorefrontProps) {
  const [query, setQuery] = useState('');
  const [genre, setGenre] = useState('All sounds');
  const [sort, setSort] = useState('featured');
  const [licenseTrack, setLicenseTrack] = useState<Track | null>(null);
  const [selectedOfferId, setSelectedOfferId] = useState('');
  const [cartOpen, setCartOpen] = useState(false);
  const [notice, setNotice] = useState('');
  const [shareFallback, setShareFallback] = useState<string | null>(null);
  const [checkoutStatus, setCheckoutStatus] = useState('');
  const [checkingOut, setCheckingOut] = useState(false);
  const { lines, setLines, unavailable } = useCart(tracks);
  useEffect(() => { if (unavailable) setNotice('Some saved selections changed or are no longer available. Please choose their licenses again.'); }, [unavailable]);
  const genres = useMemo(() => ['All sounds', ...new Set(tracks.map(track => track.genre).filter(Boolean))], [tracks]);
  const visibleTracks = useMemo(() => filterTracks(tracks, query, genre, sort), [tracks, query, genre, sort]);
  const selectedOffer = licenseTrack?.offers.find(offer => offer.id === selectedOfferId && availableOffer(offer));

  function openLicense(track: Track) { setLicenseTrack(track); setSelectedOfferId(lines.find(line => line.track.id === track.id)?.offer.id ?? track.offers.find(availableOffer)?.id ?? ''); }
  function addLicense() {
    if (!licenseTrack || !selectedOffer) return;
    const track = tracks.find(track => track.id === licenseTrack.id);
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
    if (checkingOut || !lines.length) return;
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
    if (!selectedTrackSlug) return;
    document.getElementById(`track-${selectedTrackSlug}`)?.scrollIntoView({ block: 'center' });
  }, [selectedTrackSlug]);

  return <>
    <a className="skip-link" href="#main">Skip to content</a>
    {designPreview && <div className="preview-banner">DEVELOPMENT PREVIEW <span>Sample catalog for design review. No purchases or licenses are issued.</span></div>}
    <header className="site-header"><a className="brand-link" href="/" onClick={event => navigate(event, designPreview)} aria-label="VASEY.AUDIO home"><img src="/brand/vasey-audio-logo.png" alt="VASEY.AUDIO" width="420" height="100" /></a><nav aria-label="Main navigation"><a href="#catalog">The catalog</a><a href="#licenses">Licensing</a><a href="#studio">The studio</a></nav><div className="header-actions"><a className="admin-link" href="/admin">Artist admin <Icon name="northeast" size={13} /></a><button className="cart-toggle" aria-label={`Open cart, ${lines.length} ${lines.length === 1 ? 'item' : 'items'}`} onClick={() => { setCartOpen(true); setCheckoutStatus(''); }}><Icon name="bag" size={19} /><span className="cart-text">Cart</span><span className="cart-count">{String(lines.length).padStart(2, '0')}</span></button></div></header>
    <main id="main">
      <section className="hero" aria-labelledby="hero-title">
        <div className="hero-copy"><p className="eyebrow"><span className="small-rule" />INDEPENDENT SOUND. DISTINCT IDENTITY.</p><h1 id="hero-title">SOUND<br />WITH <span>INTENT.</span></h1><div className="hero-bottom"><p>Beats with character. Sound with depth.<br />Original music and production by Sean Vasey.</p><a className="button" href="#catalog">Find your sound <Icon name="arrow" /></a></div></div>
        <div className="hero-media"><picture><source media="(max-width: 700px)" srcSet="/images/storefront-hero-mobile.jpg" /><img src="/images/storefront-hero.jpg" alt="Audio production console in the VASEY.AUDIO visual world" width="2400" height="890" fetchPriority="high" /></picture><div className="hero-media-caption"><span className="eyebrow">THE INDEPENDENT FREQUENCY</span></div></div>
      </section>
      <div className="discipline-strip"><span>HIP-HOP / CINEMATIC / EXPERIMENTAL</span><span>COMPOSITION <i>+</i> PRODUCTION <i>+</i> SOUND DESIGN</span><a href="#studio">FROM THE STUDIO <Icon name="northeast" size={14} /></a></div>
      <section id="catalog" className="catalog section-pad" aria-labelledby="catalog-title">
        <div className="section-heading"><div><p className="eyebrow">01 / THE CATALOG</p><h2 id="catalog-title">FIND YOUR<br className="mobile-break" /> NEXT RECORD.</h2></div><p>Press play. Follow the feeling.<br />Find the foundation for something original.</p></div>
        <div className="catalog-tools"><div className="search-field"><Icon name="search" size={19} /><input type="search" aria-label="Search tracks" placeholder="Search by title, mood, genre…" value={query} onChange={event => setQuery(event.target.value)} />{query && <button className="icon-button" onClick={() => setQuery('')} aria-label="Clear search"><Icon name="close" size={16} /></button>}</div><label className="sort-field"><span>Sort by</span><select aria-label="Sort tracks" value={sort} onChange={event => setSort(event.target.value)}><option value="featured">Featured</option><option value="tempo">Tempo: low to high</option><option value="title">Title: A–Z</option></select></label></div>
        <div className="catalog-filter-row"><div className="genre-filters" aria-label="Filter by genre">{genres.map(option => <button key={option} aria-pressed={genre === option} className={genre === option ? 'filter-selected' : ''} onClick={() => setGenre(option)}>{option}</button>)}</div><span className="results-count" aria-live="polite">{String(visibleTracks.length).padStart(2, '0')} {visibleTracks.length === 1 ? 'TRACK' : 'TRACKS'}</span></div>
        {!!visibleTracks.length && <div className="track-list-header" aria-hidden="true"><span>TRACK / ARTIST</span><span>GENRE / MOOD</span><span>TEMPO / KEY</span><span>PREVIEW</span><span>LICENSE</span></div>}
        <div className="track-list">{visibleTracks.map((track, index) => <TrackRow key={track.id} track={track} index={index} onLicense={openLicense} onShare={share} designPreview={designPreview} />)}</div>
        {!visibleTracks.length && <div className="catalog-empty"><span className="empty-motif"><Icon name={tracks.length ? 'search' : 'music'} size={32} /></span><h3>{tracks.length ? 'NO MATCHES. KEEP EXPLORING.' : 'A NEW CHAPTER IN SOUND.'}</h3><p>{tracks.length ? 'Try another title, mood, or genre to find your next record.' : 'The independent catalog is being prepared. New music will appear here as releases are published.'}</p>{tracks.length ? <button className="button button-outline" onClick={() => { setQuery(''); setGenre('All sounds'); }}>Reset filters <Icon name="arrow" size={18} /></button> : <a className="text-link" href="#studio">Meet the producer <Icon name="arrow" size={16} /></a>}</div>}
        <div className="catalog-footnote"><span>ORIGINAL MUSIC. A DIRECT CONNECTION TO THE CREATOR.</span><a className="text-link" href="#licenses">Find the right license <Icon name="northeast" size={14} /></a></div>
      </section>
      <section id="licenses" className="license-section section-pad" aria-labelledby="licenses-title"><div className="section-heading"><div><p className="eyebrow">02 / MAKE IT YOURS</p><h2 id="licenses-title">YOUR RECORD.<br />THE RIGHT LICENSE.</h2></div><p>Choose a license that fits your release.<br />Review the terms and included files before you commit.</p></div>
        {licenseTiers.length ? <div className="license-grid">{licenseTiers.map((tier, index) => <article className="license-card" key={tier.id}><div className="license-number">0{index + 1}<span>{tier.type.replace(/_/g, ' ')}</span></div><h3>{tier.name}</h3><p className="license-files">{tier.requiredAssetRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</p><ul>{tier.features.map(feature => <li key={feature}><Icon name="check" size={16} />{feature}</li>)}</ul><div className="license-card-bottom"><span className="fine-print">Terms version {tier.version}</span><a className="text-link" href="#catalog">Choose a track <Icon name="arrow" size={16} /></a></div></article>)}</div> : <div className="license-empty"><div><h3>GOOD MUSIC. CLEAR TERMS.</h3><p>Release-specific licensing and file options will be listed alongside each published track. Published terms are not available yet.</p></div><a href="#catalog" className="button button-outline">Explore the catalog <Icon name="arrow" /></a></div>}
        <p className="licensing-note">License availability, pricing, and included files depend on the track. Only published terms apply.</p>
      </section>
      <section id="studio" className="studio-section section-pad" aria-labelledby="studio-title"><div className="studio-visual"><img src="/images/video-studio.jpg" alt="VASEY.AUDIO production studio visual" width="1440" height="630" loading="lazy" /><span className="studio-caption">SEAN VASEY PRODUCTIONS / VASEY.AUDIO</span></div><div className="studio-copy"><p className="eyebrow">03 / BEHIND THE SOUND</p><h2 id="studio-title">CRAFT FIRST.<br />ALWAYS.</h2><p className="studio-lead">From the first note to the last detail.</p><p>Sean Vasey brings over two decades of composition, music production, and sound design to a practice shaped by hip-hop, classical music, and the space between them.</p><p>Original beats. Bespoke composition. Detailed sonic worlds. Built with intention, for artists with something to say.</p><div className="studio-disciplines"><span>MUSIC PRODUCTION</span><span>COMPOSITION</span><span>SOUND DESIGN</span></div></div></section>
      <section className="closing-statement"><span className="eyebrow">VASEY.AUDIO</span><p>MAKE SOMETHING<br /><span>ONLY YOU CAN.</span></p><a className="button button-outline" href="#catalog">Start with a sound <Icon name="northeast" /></a></section>
    </main>
    <footer className="site-footer"><div className="footer-top"><a href="/" onClick={event => navigate(event, designPreview)} aria-label="VASEY.AUDIO home"><img className="footer-logo" src="/brand/vasey-audio-logo.png" alt="VASEY.AUDIO" width="420" height="100" /></a><p>Independent sound.<br />A studio/VASEY venture.</p><nav aria-label="Footer navigation"><a href="#catalog">Catalog</a><a href="#licenses">Licensing</a><a href="#studio">Studio</a><a href="/admin">Artist admin <Icon name="northeast" size={14} /></a></nav></div><div className="footer-bottom"><span>© {new Date().getFullYear()} VASEY.AUDIO</span><span>COMPOSED WITH INTENT.</span></div></footer>
    <PersistentPlayer tracks={tracks} onLicense={openLicense} />
    <div className={`toast ${notice ? 'toast-visible' : ''}`} role="status">{notice && <><span>{notice}{shareFallback && <a className="text-link" href={shareFallback} onClick={event => navigate(event, designPreview)}>Open track link <Icon name="northeast" size={14} /></a>}</span><button className="icon-button" aria-label="Dismiss notification" onClick={() => { setNotice(''); setShareFallback(null); }}><Icon name="close" size={17} /></button></>}</div>
    {licenseTrack && <Modal title="CHOOSE YOUR LICENSE." eyebrow={licenseTrack.title} onClose={() => setLicenseTrack(null)}><p className="modal-description">Select the published offer for this track. Review its files and version before adding it to your cart.</p><fieldset className="offer-options"><legend className="sr-only">Available licenses for {licenseTrack.title}</legend>{licenseTrack.offers.filter(availableOffer).map(offer => { const tier = licenseTiers.find(tier => tier.id === offer.licenseVersionId); return <label className={`offer-option ${selectedOfferId === offer.id ? 'selected' : ''}`} key={offer.id}><input type="radio" name="license" value={offer.id} checked={selectedOfferId === offer.id} onChange={() => setSelectedOfferId(offer.id)} /><span className="offer-content"><span className="offer-heading"><strong>{offer.licenseName}</strong><strong>{formatMoney(offer.priceMinor, offer.currency)}</strong></span><span className="offer-files">{offer.deliverableRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</span>{tier && <span className="offer-terms">{tier.features.join(' · ')}</span>}<span className="fine-print">{tier ? `Published terms version ${tier.version}` : 'Published license offer'}</span></span></label>; })}</fieldset><p className="checkout-advisory">Checkout and contract delivery are not available yet. Adding a license saves a selection only.</p><button className="button full-width" disabled={!selectedOffer} onClick={addLicense}>{selectedOffer ? `Add license · ${formatMoney(selectedOffer.priceMinor, selectedOffer.currency)}` : 'No license available'}<Icon name="bag" size={18} /></button></Modal>}
    {cartOpen && <Modal title="YOUR SELECTIONS." eyebrow={`CART / ${lines.length} ${lines.length === 1 ? 'TRACK' : 'TRACKS'}`} onClose={() => setCartOpen(false)}>{lines.length ? <><div className="cart-lines">{lines.map(({ track, offer }) => <div className="cart-line" key={track.id}><Artwork track={track} size={52} /><div><h3>{track.title}</h3><p>{offer.licenseName}</p><button className="text-link small-text" onClick={() => { setCartOpen(false); openLicense(track); }}>Change license</button></div><div className="cart-line-price"><strong>{formatMoney(offer.priceMinor, offer.currency)}</strong><button className="text-link small-text" aria-label={`Remove ${track.title} from cart`} onClick={() => setLines(existing => existing.filter(line => line.track.id !== track.id))}>Remove</button></div></div>)}</div><div className="cart-total"><span>Estimated subtotal</span><div>{cartSubtotals(lines).map(total => <strong key={total.currency}>{formatMoney(total.amount, total.currency)} <small>{total.currency}</small></strong>)}</div></div><p className="fine-print">Prices and availability must be revalidated before purchase. Taxes, discounts, and final terms are not calculated here.</p><QuoteReview lines={lines} designPreview={designPreview} /><p className="checkout-advisory">Checkout is not available yet. No payment will be taken and no license will be issued.</p><button className="button full-width" onClick={checkout} disabled={checkingOut}>{checkingOut ? 'Checking availability…' : 'Check checkout availability'}<Icon name="arrow" /></button><p className="checkout-status" role="status">{checkoutStatus}</p></> : <div className="empty-cart"><Icon name="bag" size={36} /><h3>ROOM FOR YOUR NEXT RECORD.</h3><p>Choose a track and license to get started.</p><button className="button" onClick={() => { setCartOpen(false); document.getElementById('catalog')?.scrollIntoView(); }}>Browse the catalog <Icon name="arrow" /></button></div>}</Modal>}
  </>;
}
