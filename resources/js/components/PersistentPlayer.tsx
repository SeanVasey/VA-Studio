import { useEffect, useId, useRef, useState, type MouseEvent } from 'react';
import { player, playbackRates, useAudio, type RepeatMode } from '../lib/audio';
import { formatTime, safeMediaUrl, type Track } from '../lib/catalog';
import { Icon } from './Icon';

const loopTime = (time: number | null) => time === null ? 'Not set' : `${formatTime(time)}.${Math.floor(time * 10) % 10}`;

export function PersistentPlayer({ tracks, onLicense, purchasingDisabled = false, catalogUrl = '#catalog', onNavigate }: { tracks: Track[]; onLicense: (track: Track) => void; purchasingDisabled?: boolean; catalogUrl?: string; onNavigate?: (event: MouseEvent<HTMLAnchorElement>) => void }) {
  const audio = useAudio();
  const [queueOpen, setQueueOpen] = useState(false);
  const queueId = useId();
  const queueButton = useRef<HTMLButtonElement>(null);
  const loopStartButton = useRef<HTMLButtonElement>(null);
  const speedSelect = useRef<HTMLSelectElement>(null);
  useEffect(() => { player.catalog(tracks); }, [tracks]);
  const queue = audio.queue;
  const index = queue.findIndex(track => track.id === audio.track?.id && track.previewUrl === audio.track?.previewUrl);
  const active = audio.status === 'playing' || audio.status === 'loading';
  const closeQueue = () => { setQueueOpen(false); queueButton.current?.focus(); };
  if (!audio.track) return <div className="player player-idle" aria-label="Audio preview player"><span className="idle-icon"><Icon name="music" /></span><div><strong>Your next idea starts with a sound.</strong><span>Select a track to preview.</span></div><a className="text-link" href={catalogUrl} onClick={onNavigate}>Explore the catalog <Icon name="arrow" size={16} /></a></div>;
  const track = audio.track;
  return <aside className="player-wrap" aria-label="Audio preview player">
    {queueOpen && <section className="queue-panel" id={queueId} aria-label="Preview queue and loop controls" onKeyDown={event => { if (event.key === 'Escape') { event.stopPropagation(); closeQueue(); } }}>
      <div className="queue-heading"><strong>Preview queue</strong><button className="icon-button" aria-label="Close queue" onClick={closeQueue}><Icon name="close" /></button></div>
      <p className="fine-print">Your queue stays with you as you browse. Removing the current track leaves its preview playing.</p>
      <div className="queue-options">
        <label><input type="checkbox" checked={audio.autoNext} onChange={event => player.autoNext(event.target.checked)} /> Play next automatically</label>
        <label>Repeat <select value={audio.repeat} onChange={event => player.repeat(event.target.value as RepeatMode)}><option value="off">Off</option><option value="track">This track</option><option value="queue">Queue</option></select></label>
      </div>
      <p className="fine-print">{audio.autoNext ? 'The next queued preview plays when this one ends.' : 'Automatic next is off.'} {audio.repeat === 'track' ? 'This track repeats until you stop it.' : audio.repeat === 'queue' ? 'The queue repeats from the start.' : ''}</p>
      <fieldset className="section-loop">
        <legend>Preview speed</legend>
        <div className="queue-options"><label>Playback speed <select ref={speedSelect} value={audio.rate} onChange={event => player.speed(Number(event.target.value))}>{playbackRates.map(rate => <option key={rate} value={rate}>{rate}×</option>)}</select></label><button type="button" className="text-link" disabled={audio.rate === 1} onClick={() => { player.speed(1); speedSelect.current?.focus(); }}>Reset speed</button></div>
        <p className="fine-print">{audio.pitchPreservation ? 'Pitch preservation is requested from your browser; results can vary.' : 'This browser could not enable pitch preservation; changing speed may change pitch.'}</p>
        {audio.speedError && <p className="playback-error" role="alert">{audio.speedError}</p>}
      </fieldset>
      <fieldset className="section-loop">
        <legend>Loop a section</legend>
        <p className="fine-print">Seek to a position, then set A and B. Clear the loop to seek outside it.</p>
        <div className="loop-bounds" aria-live="polite"><span>A: {loopTime(audio.loopStart)}</span><span>B: {loopTime(audio.loopEnd)}</span><strong>{audio.loopEnd !== null ? 'Loop on' : 'Loop off'}</strong></div>
        <div className="loop-actions">
          <button type="button" ref={loopStartButton} className="button button-outline button-small" disabled={!audio.duration} onClick={() => player.markLoopStart()}>Set A here</button>
          <button type="button" className="button button-outline button-small" disabled={!audio.duration || audio.loopStart === null} onClick={() => player.markLoopEnd()}>Set B here</button>
          <button type="button" className="text-link" disabled={audio.loopStart === null} onClick={() => { player.clearLoop(); loopStartButton.current?.focus(); }}>Clear loop</button>
        </div>
        {audio.loopError && <p className="playback-error" role="alert">{audio.loopError}</p>}
      </fieldset>
      <div className="queue-volume"><button className="icon-button" aria-label={audio.muted ? 'Unmute audio' : 'Mute audio'} onClick={() => player.mute()}><Icon name={audio.muted ? 'mute' : 'volume'} size={18} /></button><input aria-label="Playback volume" type="range" min="0" max="1" step="0.01" value={audio.muted ? 0 : audio.volume} onChange={event => { if (audio.muted) player.mute(); player.volume(Number(event.target.value)); }} /><button className="text-link" disabled={purchasingDisabled || !track.offers.length} onClick={() => { setQueueOpen(false); onLicense(track); }}>License this track <Icon name="arrow" size={15} /></button></div>
      {queue.length ? <ol className="queue-list">{queue.map((item, position) => <li key={item.id} className="queue-row">
        <button className={`queue-track ${track.id === item.id ? 'selected' : ''}`} aria-current={track.id === item.id ? 'true' : undefined} onClick={() => player.toggle(item)}><span>{item.title}</span><span>{formatTime(item.durationSeconds)}</span></button>
        <div className="queue-order"><button className="icon-button" aria-label={`Move ${item.title} earlier`} disabled={position === 0} onClick={() => player.move(item.id, -1)}><Icon name="previous" size={15} /></button><button className="icon-button" aria-label={`Move ${item.title} later`} disabled={position === queue.length - 1} onClick={() => player.move(item.id, 1)}><Icon name="next" size={15} /></button><button className="icon-button" aria-label={`Remove ${item.title} from queue`} onClick={() => player.remove(item.id)}><Icon name="close" size={15} /></button></div>
      </li>)}</ol> : <p className="fine-print">The queue is empty. Select a catalog track to start a new queue.</p>}
    </section>}
    <div className="player">
      <div className="player-track"><div className="player-art">{safeMediaUrl(track.artworkUrl) ? <img src={safeMediaUrl(track.artworkUrl)} alt="" width="52" height="52" /> : <Icon name="music" />}</div><div><strong>{track.title}</strong><span>{track.artist}</span></div></div>
      <div className="transport"><button className="icon-button skip-control" aria-label="Previous track" disabled={index <= 0 && !(audio.repeat === 'queue' && queue.length > 1)} onClick={() => player.next(-1)}><Icon name="previous" size={16} /></button><button className="transport-play" aria-label={active ? 'Pause preview' : `Play ${track.title}`} onClick={() => player.toggle()}><Icon name={active ? 'pause' : 'play'} size={20} /></button><button className="icon-button skip-control" aria-label="Next track" disabled={!queue.length || (index >= queue.length - 1 && audio.repeat !== 'queue')} onClick={() => player.next()}><Icon name="next" size={16} /></button></div>
      <div className="player-progress"><span>{formatTime(audio.currentTime)}</span><input type="range" min="0" max={audio.duration || 1} step="0.1" value={audio.currentTime} disabled={!audio.duration} onChange={event => player.seek(Number(event.target.value))} aria-label="Seek preview" aria-valuetext={`${formatTime(audio.currentTime)} of ${formatTime(audio.duration)}`} /><span>{formatTime(audio.duration || track.durationSeconds)}</span></div>
      <div className="player-volume"><button className="icon-button" aria-label={audio.muted ? 'Unmute preview' : 'Mute preview'} onClick={() => player.mute()}><Icon name={audio.muted || audio.volume === 0 ? 'mute' : 'volume'} size={18} /></button><input aria-label="Preview volume" type="range" min="0" max="1" step="0.01" value={audio.muted ? 0 : audio.volume} onChange={event => { if (audio.muted) player.mute(); player.volume(Number(event.target.value)); }} /></div>
      <button ref={queueButton} className="player-queue" aria-expanded={queueOpen} aria-controls={queueOpen ? queueId : undefined} onClick={() => setQueueOpen(!queueOpen)}>Queue & loop</button><button className="button button-small player-license" onClick={() => onLicense(track)} disabled={purchasingDisabled || !track.offers.length}>License <Icon name="arrow" size={16} /></button><button className="icon-button player-close" aria-label="Close player" onClick={() => { setQueueOpen(false); player.close(); }}><Icon name="close" size={18} /></button>
    </div>
    <div className={`playback-status ${audio.error ? 'playback-error' : ''}`} role="status">{audio.error || (audio.status === 'loading' ? `Loading ${track.title}…` : audio.status === 'ended' ? `Preview ended. Replay ${track.title} or choose another track.` : '')}</div>
  </aside>;
}
