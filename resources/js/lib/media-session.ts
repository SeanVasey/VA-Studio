import { safeMediaUrl, type Track } from './catalog';

type Playback = { track: Track | null; status: string; currentTime: number; duration: number; rate: number };
type Controls = { play: () => void; pause: () => void; next: () => void; previous: () => void; seek: (time: number) => void };
let attached: MediaSession | null = null;
let metadataKey: string | null = null;
let registered: MediaSessionAction[] = [];
let position = 0;
let generation = 0;

function clear() {
  ++generation;
  const session = attached;
  attached = null;
  metadataKey = null;
  position = 0;
  if (!session) return;
  for (const action of registered) {
    try { session.setActionHandler(action, null); } catch { /* Unsupported platform action. */ }
  }
  registered = [];
  try { session.metadata = null; } catch { /* Metadata may be unavailable. */ }
  try { session.playbackState = 'none'; } catch { /* Older browsers expose partial support. */ }
  try { session.setPositionState?.(); } catch { /* Position state is optional. */ }
}

/** OS controls are optional; their absence or a rejected action must never break the in-page player. */
export function syncMediaSession(state: Playback, controls: Controls) {
  const session = typeof navigator !== 'undefined' ? navigator.mediaSession : undefined;
  if (!state.track || !session) { clear(); return; }
  if (attached !== session) {
    clear();
    attached = session;
    const binding = generation;
    const actions: Partial<Record<MediaSessionAction, (details: MediaSessionActionDetails) => void>> = {
      play: controls.play, pause: controls.pause, nexttrack: controls.next, previoustrack: controls.previous,
      seekto: details => { if (Number.isFinite(details.seekTime)) controls.seek(details.seekTime!); },
      seekbackward: details => { const offset = details.seekOffset ?? 10; if (Number.isFinite(offset) && offset > 0) controls.seek(position - offset); },
      seekforward: details => { const offset = details.seekOffset ?? 10; if (Number.isFinite(offset) && offset > 0) controls.seek(position + offset); },
    };
    for (const [action, handle] of Object.entries(actions)) {
      try {
        session.setActionHandler(action as MediaSessionAction, details => { if (attached === session && generation === binding) handle(details); });
        registered.push(action as MediaSessionAction);
      } catch { /* This browser may support only a subset of actions. */ }
    }
  }
  position = Number.isFinite(state.currentTime) ? Math.max(0, state.currentTime) : 0;
  const artwork = safeMediaUrl(state.track.artworkUrl);
  const key = JSON.stringify([state.track.id, state.track.title, state.track.artist, artwork]);
  if (metadataKey !== key) {
    metadataKey = key;
    try {
      session.metadata = typeof MediaMetadata === 'function' ? new MediaMetadata({ title: state.track.title, artist: state.track.artist, artwork: artwork ? [{ src: artwork }] : [] }) : null;
    } catch { /* Keep transport available even when metadata is unsupported. */ }
  }
  try { session.playbackState = state.status === 'playing' ? 'playing' : 'paused'; } catch { /* Partial platform support. */ }
  try {
    if (Number.isFinite(state.duration) && state.duration > 0 && Number.isFinite(state.rate) && state.rate > 0) {
      session.setPositionState?.({ duration: state.duration, playbackRate: state.rate, position: Math.min(position, state.duration) });
    } else session.setPositionState?.();
  } catch { /* Position reporting must not prevent playback. */ }
}
