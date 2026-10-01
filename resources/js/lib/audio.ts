import { useSyncExternalStore } from 'react';
import { safeMediaUrl, type Track } from './catalog';
import { syncMediaSession } from './media-session';

type PlaybackStatus = 'idle' | 'loading' | 'playing' | 'paused' | 'ended' | 'error';
export type RepeatMode = 'off' | 'track' | 'queue';
export const playbackRates = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
interface AudioSnapshot {
  rate: number; pitchPreservation: boolean | null; speedError: string | null;
  track: Track | null; status: PlaybackStatus; currentTime: number; duration: number; volume: number; muted: boolean; error: string | null;
  queue: Track[]; autoNext: boolean; repeat: RepeatMode; loopStart: number | null; loopEnd: number | null; loopError: string | null;
}
const noLoop = { loopStart: null, loopEnd: null, loopError: null };
let snapshot: AudioSnapshot = { rate: 1, pitchPreservation: null, speedError: null, track: null, status: 'idle', currentTime: 0, duration: 0, volume: 0.8, muted: false, error: null, queue: [], autoNext: false, repeat: 'off', ...noLoop };
let audio: HTMLAudioElement | null = null;
let catalog: Track[] = [];
let request = 0;
let loopFrame: number | null = null;
const listeners = new Set<() => void>();
const update = (patch: Partial<AudioSnapshot>) => {
  snapshot = { ...snapshot, ...patch };
  syncMediaSession(snapshot, {
    play: () => { if (snapshot.track) void player.play(snapshot.track); }, pause: () => player.pause(),
    next: () => player.next(), previous: () => player.next(-1), seek: time => player.seek(time),
  });
  listeners.forEach(listener => listener());
};
const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };
const sameSource = (a: Track | null, b: Track) => a?.id === b.id && a.previewUrl === b.previewUrl;
const looping = () => snapshot.loopStart !== null && snapshot.loopEnd !== null;

function stopLoopWatch() {
  if (loopFrame !== null) cancelAnimationFrame(loopFrame);
  loopFrame = null;
}

function enforceLoop(element: HTMLAudioElement): boolean {
  if (snapshot.status !== 'playing' || !looping() || (element.currentTime < snapshot.loopEnd! && element.currentTime >= snapshot.loopStart!)) return false;
  element.currentTime = snapshot.loopStart!;
  return true;
}

function watchLoop() {
  stopLoopWatch();
  if (!audio || snapshot.status !== 'playing' || !looping() || typeof requestAnimationFrame !== 'function') return;
  loopFrame = requestAnimationFrame(() => {
    loopFrame = null;
    if (audio && enforceLoop(audio)) update({ currentTime: audio.currentTime });
    watchLoop();
  });
}

function applyRate(element: HTMLAudioElement, rate: number): boolean {
  // Native resource loading restores playbackRate from defaultPlaybackRate.
  try { element.playbackRate = rate; element.defaultPlaybackRate = rate; }
  catch { update({ speedError: 'This browser could not change playback speed. Try normal speed.' }); return false; }
  let pitchPreservation = false;
  try { if ('preservesPitch' in element) { element.preservesPitch = true; pitchPreservation = element.preservesPitch; } } catch { /* Native pitch control is optional. */ }
  update({ rate: element.playbackRate, pitchPreservation, speedError: null });
  return true;
}

function owner(): HTMLAudioElement {
  if (audio) return audio;
  audio = new Audio();
  audio.preload = 'metadata';
  audio.volume = snapshot.volume;
  audio.muted = snapshot.muted;
  const element = audio;
  element.addEventListener('playing', () => { if (snapshot.track) { update({ status: 'playing', error: null }); watchLoop(); } });
  element.addEventListener('pause', () => { stopLoopWatch(); if (snapshot.track && !element.ended && snapshot.status !== 'error') update({ status: 'paused' }); });
  element.addEventListener('waiting', () => { if (snapshot.track) update({ status: 'loading' }); });
  element.addEventListener('timeupdate', () => {
    if (!snapshot.track) return;
    enforceLoop(element);
    update({ currentTime: element.currentTime });
  });
  element.addEventListener('durationchange', () => {
    if (!snapshot.track) return;
    const duration = Number.isFinite(element.duration) && element.duration > 0 ? element.duration : 0;
    const invalidLoop = snapshot.loopStart !== null && (!duration || snapshot.loopStart >= duration || (snapshot.loopEnd !== null && snapshot.loopEnd > duration));
    update({ duration, ...(invalidLoop ? noLoop : {}) });
    if (invalidLoop) stopLoopWatch();
  });
  element.addEventListener('ended', () => {
    if (!snapshot.track) return;
    stopLoopWatch();
    const wasPlaying = snapshot.status === 'playing' || snapshot.status === 'loading';
    update({ status: 'ended' });
    if (!wasPlaying) return;
    if (looping() || snapshot.repeat === 'track') {
      element.currentTime = looping() ? snapshot.loopStart! : 0;
      void player.play(snapshot.track);
    } else if (snapshot.autoNext) {
      const index = snapshot.queue.findIndex(track => sameSource(snapshot.track, track));
      const next = index < 0 ? undefined : snapshot.queue[index + 1] ?? (snapshot.repeat === 'queue' ? snapshot.queue[0] : undefined);
      if (next) void player.play(next);
    }
  });
  element.addEventListener('ratechange', () => { if (snapshot.track && Number.isFinite(element.playbackRate) && element.playbackRate > 0) update({ rate: element.playbackRate }); });
  element.addEventListener('error', () => { if (snapshot.track) update({ status: 'error', error: 'This preview could not load. Check your connection and try again.' }); });
  return element;
}

export const player = {
  // Navigation updates the available catalog, never the session queue or the native source.
  catalog(tracks: Track[]) { catalog = tracks.filter((track, index) => safeMediaUrl(track.previewUrl) && tracks.findIndex(item => item.id === track.id) === index); },
  async play(track: Track) {
    const url = safeMediaUrl(track.previewUrl);
    if (!url) return;
    const element = owner();
    const currentRequest = ++request;
    const changed = !sameSource(snapshot.track, track);
    let queue = snapshot.queue;
    if (changed && !queue.length) queue = catalog.some(item => item.id === track.id) ? catalog : [track];
    if (changed && !queue.some(item => item.id === track.id)) queue = [...queue, track];
    else queue = queue.map(item => item.id === track.id ? track : item);
    if (changed || snapshot.status === 'error') {
      element.pause();
      element.src = url;
      update({ track, queue, currentTime: 0, duration: 0, error: null, status: 'loading', ...noLoop });
    } else {
      if (looping() && (element.currentTime < snapshot.loopStart! || element.currentTime >= snapshot.loopEnd!)) element.currentTime = snapshot.loopStart!;
      else if (snapshot.status === 'ended' && !looping()) element.currentTime = 0;
      update({ track, queue, status: 'loading', error: null, currentTime: element.currentTime });
    }
    applyRate(element, snapshot.rate);
    try { await element.play(); }
    catch (error) {
      if (currentRequest !== request || (error instanceof DOMException && error.name === 'AbortError')) return;
      update({ status: 'error', error: 'Playback was interrupted. Select play to try again.' });
    }
  },
  pause() { ++request; audio?.pause(); if (snapshot.track && snapshot.status !== 'error') update({ status: 'paused' }); },
  toggle(track?: Track) {
    const target = track ?? snapshot.track;
    if (!target) return;
    if (sameSource(snapshot.track, target) && (snapshot.status === 'playing' || snapshot.status === 'loading')) player.pause();
    else void player.play(target);
  },
  next(direction: 1 | -1 = 1) {
    const index = snapshot.queue.findIndex(track => sameSource(snapshot.track, track));
    const nextIndex = index + direction;
    const track = snapshot.queue[nextIndex] ?? (snapshot.repeat === 'queue' ? snapshot.queue[direction === 1 ? 0 : snapshot.queue.length - 1] : undefined);
    if (track) void player.play(track);
  },
  autoNext(enabled: boolean) { update({ autoNext: enabled, repeat: !enabled && snapshot.repeat === 'queue' ? 'off' : snapshot.repeat }); },
  repeat(mode: RepeatMode) {
    if (!['off', 'track', 'queue'].includes(mode)) return;
    update({ repeat: mode, autoNext: mode === 'queue' ? true : snapshot.autoNext });
  },
  move(id: string, direction: 1 | -1) {
    const index = snapshot.queue.findIndex(track => track.id === id);
    const destination = index + direction;
    if (index < 0 || destination < 0 || destination >= snapshot.queue.length) return;
    const queue = [...snapshot.queue];
    [queue[index], queue[destination]] = [queue[destination], queue[index]];
    update({ queue });
  },
  remove(id: string) { update({ queue: snapshot.queue.filter(track => track.id !== id) }); },
  markLoopStart() {
    if (!audio || !snapshot.duration) return;
    if (!Number.isFinite(audio.currentTime) || audio.currentTime >= snapshot.duration) {
      update({ loopError: 'Choose a start point before the end of the preview.' });
      return;
    }
    stopLoopWatch();
    update({ loopStart: Math.max(0, audio.currentTime), loopEnd: null, loopError: null });
  },
  markLoopEnd() {
    if (!audio || !snapshot.duration || snapshot.loopStart === null) return;
    const end = Math.min(audio.currentTime, snapshot.duration);
    if (!Number.isFinite(end) || end - snapshot.loopStart < 0.1) {
      update({ loopError: 'Choose an end point at least 0.1 seconds after the start.' });
      return;
    }
    update({ loopEnd: end, loopError: null });
    if (snapshot.status === 'playing') player.seek(snapshot.loopStart);
    watchLoop();
  },
  clearLoop() { stopLoopWatch(); update(noLoop); },
  seek(time: number) {
    if (!audio || !Number.isFinite(time) || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
    let target = Math.max(0, Math.min(time, audio.duration));
    if (looping()) target = target >= snapshot.loopEnd! ? snapshot.loopStart! : Math.max(snapshot.loopStart!, target);
    audio.currentTime = target;
    update({ currentTime: audio.currentTime });
  },
  speed(rate: number) {
    if (!Number.isFinite(rate) || !playbackRates.includes(rate)) return;
    applyRate(owner(), rate);
  },
  volume(value: number) {
    if (!Number.isFinite(value)) return;
    const volume = Math.max(0, Math.min(1, value));
    owner().volume = volume;
    update({ volume });
  },
  mute() { owner().muted = !snapshot.muted; update({ muted: !snapshot.muted }); },
  close() {
    ++request; stopLoopWatch(); audio?.pause();
    if (audio) { audio.removeAttribute('src'); audio.load(); applyRate(audio, 1); }
    update({ rate: 1, pitchPreservation: null, speedError: null, track: null, status: 'idle', currentTime: 0, duration: 0, error: null, queue: [], autoNext: false, repeat: 'off', ...noLoop });
  },
};

export function useAudio() { return useSyncExternalStore(subscribe, () => snapshot, () => snapshot); }
