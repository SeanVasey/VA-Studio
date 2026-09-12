import { type MouseEvent } from 'react';
import { player, useAudio } from '../lib/audio';
import { availableOffer, fileRoleLabels, formatMoney, formatTime, safeMediaUrl, type LicenseTier, type Offer, type Track } from '../lib/catalog';
import { Icon } from './Icon';

export function TrackDetail({ track, tiers, catalogUrl, onNavigate, onLicense, onShare }: {
  track: Track; tiers: LicenseTier[]; catalogUrl: string;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>) => void;
  onLicense: (track: Track, offer?: Offer) => void; onShare: (track: Track) => void;
}) {
  const audio = useAudio();
  const current = audio.track?.id === track.id && audio.track?.previewUrl === track.previewUrl;
  const playing = current && (audio.status === 'playing' || audio.status === 'loading');
  const peaks = track.waveform.filter(value => Number.isFinite(value) && value >= 0).slice(0, 80);
  const peakMaximum = Math.max(1, ...peaks);
  const artwork = safeMediaUrl(track.artworkUrl);
  return <article className="track-detail section-pad" aria-labelledby="detail-title">
    <a className="text-link detail-back" href={catalogUrl} onClick={onNavigate}><Icon name="previous" size={16} />Back to results</a>
    <div className="detail-hero">
      <div className="detail-artwork">{artwork ? <img src={artwork} alt={`Cover artwork for ${track.title}`} width="640" height="640" fetchPriority="high" /> : <Icon name="music" size={80} />}</div>
      <div className="detail-copy"><p className="eyebrow">{track.genre} / {track.mood || 'ORIGINAL MUSIC'}</p><h1 id="detail-title" tabIndex={-1}>{track.title}</h1><p className="detail-artist">{track.artist}</p>
        <dl className="detail-metadata"><div><dt>Tempo</dt><dd>{track.bpm} BPM</dd></div><div><dt>Key</dt><dd>{track.musicalKey}</dd></div><div><dt>Preview</dt><dd>{formatTime(track.durationSeconds)}</dd></div></dl>
        <div className="detail-waveform" aria-hidden="true">{peaks.map((peak, index) => <span key={index} className={current && index / peaks.length < audio.currentTime / (audio.duration || track.durationSeconds || 1) ? 'played' : ''} style={{ height: `${Math.max(4, peak / peakMaximum * 100)}%` }} />)}</div>
        <div className="detail-actions"><button className="button" disabled={!safeMediaUrl(track.previewUrl)} onClick={() => player.toggle(track)} aria-label={playing ? `Pause ${track.title}` : `Play ${track.title}`}><Icon name={playing ? 'pause' : 'play'} />{playing ? 'Pause preview' : 'Play preview'}</button><a className="button button-outline" href="#licenses">View licenses <Icon name="arrow" /></a><button className="icon-button" onClick={() => onShare(track)} aria-label={`Share ${track.title}`}><Icon name="share" /></button></div>
        {!safeMediaUrl(track.previewUrl) && <p className="fine-print">This preview is unavailable.</p>}
        <p className="fine-print">Listen to the tagged preview. Your license determines the delivered files.</p>
        {!!track.tags.length && <ul className="detail-tags" aria-label="Track tags">{track.tags.map(tag => <li key={tag}>{tag}</li>)}</ul>}
      </div>
    </div>
    <section id="licenses" className="detail-licenses" aria-labelledby="detail-licenses-title"><div className="section-heading"><div><p className="eyebrow">MAKE IT YOURS</p><h2 id="detail-licenses-title">THE RIGHT LICENSE.</h2></div><p>Compare the included files and published rights.<br />Read the full terms for your selected license.</p></div>
      <div className="detail-offers">{track.offers.filter(availableOffer).map(offer => {
        const tier = tiers.find(item => item.id === offer.licenseVersionId);
        return <article className="detail-offer" key={offer.offerRevisionId}><div><p className="eyebrow">{tier?.type.replaceAll('_', ' ') || 'LICENSE'}</p><h3>{offer.licenseName}</h3><p className="license-files">{offer.deliverableRoles.map(role => fileRoleLabels[role] ?? role).join(' + ')}</p></div><div className="detail-offer-price"><strong>{formatMoney(offer.priceMinor, offer.currency)}</strong><span>{offer.currency}</span></div>{tier && <details><summary>Compare usage rights · version {tier.version}</summary><ul>{tier.features.map(feature => <li key={feature}>{feature}</li>)}</ul></details>}<button className="button button-outline" onClick={() => onLicense(track, offer)}>Read terms & choose <Icon name="arrow" size={16} /></button></article>;
      })}</div>
    </section>
  </article>;
}
