import { useRef, useState } from 'react';
import { player } from '../lib/audio';
import { Icon } from './Icon';
import '../../css/editorial-video.css';

interface Video { provider: 'youtube' | 'vimeo'; videoId: string; watchUrl: string }

function sources(value: unknown) {
  if (!value || typeof value !== 'object') return null;
  const video = value as Video;
  if (typeof video.videoId !== 'string' || video.videoId.trim() !== video.videoId) return null;
  if (video.provider === 'youtube' && video.videoId.length === 11 && /^[A-Za-z0-9_-]{11}$/.test(video.videoId)
    && video.watchUrl === `https://www.youtube.com/watch?v=${video.videoId}`) {
    return { name: 'YouTube', watch: video.watchUrl, embed: `https://www.youtube-nocookie.com/embed/${video.videoId}?autoplay=0&playsinline=1` };
  }
  if (video.provider === 'vimeo' && /^[1-9][0-9]{0,11}$/.test(video.videoId)
    && video.watchUrl === `https://vimeo.com/${video.videoId}`) {
    return { name: 'Vimeo', watch: video.watchUrl, embed: `https://player.vimeo.com/video/${video.videoId}?autoplay=0&dnt=1` };
  }
  return null;
}

export function EditorialVideo({ video, title, privatePreview = false }: { video: unknown; title: string; privatePreview?: boolean }) {
  const source = sources(video);
  if (!source) return <p role="status">This video is unavailable.</p>;
  return <ConsentPlayer key={`${source.embed}:${privatePreview}`} source={source} title={title} privatePreview={privatePreview} />;
}

function ConsentPlayer({ source, title, privatePreview }: { source: NonNullable<ReturnType<typeof sources>>; title: string; privatePreview: boolean }) {
  const [loaded, setLoaded] = useState(false);
  const loadButton = useRef<HTMLButtonElement>(null);

  return <section className="editorial-video editorial-video-consent" aria-label={`${source.name} video`}>
    <div className="editorial-video-intro"><Icon name="play" size={32} /><div><p className="eyebrow">{source.name}</p>
      {privatePreview ? <p className="fine-print">Video playback and links are disabled in private preview.</p> : <>
        <p>Load this video to connect to {source.name}. The provider receives your IP address and may use cookies.</p>
        <p className="fine-print">Loading pauses the audio preview. You can remove the video player at any time.</p>
        <div className="editorial-video-actions">
          <button ref={loadButton} className="button" type="button" disabled={loaded} onClick={() => { player.pause(); setLoaded(true); }}>Load {source.name} video</button>
          <a className="button button-outline" href={source.watch} target="_blank" rel="noopener noreferrer">Watch on {source.name} <Icon name="northeast" size={18} /></a>
        </div>
      </>}
    </div></div>
    {!privatePreview && loaded && <div className="editorial-video-loaded">
      <iframe title={`${title} — ${source.name} video`} src={source.embed} width="640" height="360"
        referrerPolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-presentation"
        allow="encrypted-media; fullscreen; picture-in-picture" allowFullScreen />
      <p className="fine-print">If the player is unavailable, use the watch link above.</p>
      <button className="button button-outline" type="button" onClick={() => {
        setLoaded(false);
        // Focus after React re-enables the load button; do not return focus into a removed frame.
        requestAnimationFrame(() => loadButton.current?.focus());
      }}>Remove video player</button>
    </div>}
  </section>;
}
