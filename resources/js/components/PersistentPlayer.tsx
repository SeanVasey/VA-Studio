import { useState } from 'react';
import { player, useAudio } from '../lib/audio';
import { formatTime, safeMediaUrl, type Track } from '../lib/catalog';
import { Icon } from './Icon';

export function PersistentPlayer({ tracks, onLicense }: { tracks: Track[]; onLicense: (track: Track) => void }) {
  const audio = useAudio();
  const [queueOpen, setQueueOpen] = useState(false);
  const queue = tracks.filter(track => safeMediaUrl(track.previewUrl));
  const index = queue.findIndex(track => track.id === audio.track?.id);
  const active = audio.status === 'playing' || audio.status === 'loading';
  if (!audio.track) return <div className="player player-idle" aria-label="Audio preview player"><span className="idle-icon"><Icon name="music" /></span><div><strong>Your next idea starts with a sound.</strong><span>Select a track to preview.</span></div><a className="text-link" href="#catalog">Explore the catalog <Icon name="arrow" size={16} /></a></div>;
  const track = audio.track;
  return <aside className="player-wrap" aria-label="Audio preview player">
    {queueOpen && <div className="queue-panel"><div className="queue-heading"><strong>Preview queue</strong><button className="icon-button" aria-label="Close queue" onClick={() => setQueueOpen(false)}><Icon name="close" /></button></div><p className="fine-print">Tracks play only when selected. Automatic playback is off.</p><div className="queue-volume"><button className="icon-button" aria-label={audio.muted ? 'Unmute audio' : 'Mute audio'} onClick={() => player.mute()}><Icon name={audio.muted ? 'mute' : 'volume'} size={18} /></button><input aria-label="Playback volume" type="range" min="0" max="1" step="0.01" value={audio.muted ? 0 : audio.volume} onChange={event => { if (audio.muted) player.mute(); player.volume(Number(event.target.value)); }} /><button className="text-link" disabled={!track.offers.length} onClick={() => { setQueueOpen(false); onLicense(track); }}>License this track <Icon name="arrow" size={15} /></button></div>{queue.map(item => <button key={item.id} className={`queue-track ${track.id === item.id ? 'selected' : ''}`} onClick={() => player.toggle(item)}><span>{item.title}</span><span>{formatTime(item.durationSeconds)}</span></button>)}</div>}
    <div className="player">
      <div className="player-track"><div className="player-art">{safeMediaUrl(track.artworkUrl) ? <img src={safeMediaUrl(track.artworkUrl)} alt="" width="52" height="52" /> : <Icon name="music" />}</div><div><strong>{track.title}</strong><span>{track.artist}</span></div></div>
      <div className="transport"><button className="icon-button skip-control" aria-label="Previous track" disabled={index <= 0} onClick={() => void player.play(queue[index - 1])}><Icon name="previous" size={16} /></button><button className="transport-play" aria-label={active ? 'Pause preview' : `Play ${track.title}`} onClick={() => player.toggle()}><Icon name={active ? 'pause' : 'play'} size={20} /></button><button className="icon-button skip-control" aria-label="Next track" disabled={index < 0 || index >= queue.length - 1} onClick={() => void player.play(queue[index + 1])}><Icon name="next" size={16} /></button></div>
      <div className="player-progress"><span>{formatTime(audio.currentTime)}</span><input type="range" min="0" max={audio.duration || 1} step="0.1" value={audio.currentTime} disabled={!audio.duration} onChange={event => player.seek(Number(event.target.value))} aria-label="Seek preview" aria-valuetext={`${formatTime(audio.currentTime)} of ${formatTime(audio.duration)}`} /><span>{formatTime(audio.duration || track.durationSeconds)}</span></div>
      <div className="player-volume"><button className="icon-button" aria-label={audio.muted ? 'Unmute preview' : 'Mute preview'} onClick={() => player.mute()}><Icon name={audio.muted || audio.volume === 0 ? 'mute' : 'volume'} size={18} /></button><input aria-label="Preview volume" type="range" min="0" max="1" step="0.01" value={audio.muted ? 0 : audio.volume} onChange={event => { if (audio.muted) player.mute(); player.volume(Number(event.target.value)); }} /></div>
      <button className="player-queue" aria-expanded={queueOpen} onClick={() => setQueueOpen(!queueOpen)}>Queue</button><button className="button button-small player-license" onClick={() => onLicense(track)} disabled={!track.offers.length}>License <Icon name="arrow" size={16} /></button><button className="icon-button player-close" aria-label="Close player" onClick={() => player.close()}><Icon name="close" size={18} /></button>
    </div>
    <div className={`playback-status ${audio.error ? 'playback-error' : ''}`} role="status">{audio.error || (audio.status === 'loading' ? `Loading ${track.title}…` : audio.status === 'ended' ? `Preview ended. Replay ${track.title} or choose another track.` : '')}</div>
  </aside>;
}
