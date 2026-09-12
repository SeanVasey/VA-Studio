import { useSyncExternalStore } from 'react';
import { safeMediaUrl, type Track } from './catalog';

type PlaybackStatus = 'idle' | 'loading' | 'playing' | 'paused' | 'ended' | 'error';
interface AudioSnapshot { track: Track | null; status: PlaybackStatus; currentTime: number; duration: number; volume: number; muted: boolean; error: string | null }
let snapshot: AudioSnapshot = { track: null, status: 'idle', currentTime: 0, duration: 0, volume: 0.8, muted: false, error: null };
let audio: HTMLAudioElement | null = null;
let request = 0;
const listeners = new Set<() => void>();
const update = (patch: Partial<AudioSnapshot>) => { snapshot = { ...snapshot, ...patch }; listeners.forEach(listener => listener()); };
const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };

function owner(): HTMLAudioElement {
  if (audio) return audio;
  audio = new Audio();
  audio.preload = 'metadata';
  audio.volume = snapshot.volume;
  const element = audio;
  element.addEventListener('playing', () => update({ status: 'playing', error: null }));
  element.addEventListener('pause', () => { if (!element.ended && snapshot.status !== 'error') update({ status: 'paused' }); });
  element.addEventListener('waiting', () => update({ status: 'loading' }));
  element.addEventListener('timeupdate', () => update({ currentTime: element.currentTime }));
  element.addEventListener('durationchange', () => update({ duration: Number.isFinite(element.duration) ? element.duration : 0 }));
  element.addEventListener('ended', () => update({ status: 'ended' }));
  element.addEventListener('error', () => update({ status: 'error', error: 'This preview could not load. Check your connection and try again.' }));
  return element;
}

export const player = {
  async play(track: Track) {
    const url = safeMediaUrl(track.previewUrl);
    if (!url) return;
    const element = owner();
    const currentRequest = ++request;
    if (snapshot.track?.id !== track.id || snapshot.track?.previewUrl !== track.previewUrl || snapshot.status === 'error') {
      element.pause();
      element.src = url;
      update({ track, currentTime: 0, duration: 0, error: null, status: 'loading' });
    } else update({ status: 'loading', error: null });
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
    if (snapshot.track?.id === target.id && snapshot.track?.previewUrl === target.previewUrl && (snapshot.status === 'playing' || snapshot.status === 'loading')) player.pause();
    else void player.play(target);
  },
  seek(time: number) {
    if (!audio || !Number.isFinite(time) || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
    audio.currentTime = Math.max(0, Math.min(time, audio.duration));
    update({ currentTime: audio.currentTime });
  },
  volume(value: number) {
    const volume = Math.max(0, Math.min(1, value));
    owner().volume = volume;
    update({ volume });
  },
  mute() { owner().muted = !snapshot.muted; update({ muted: !snapshot.muted }); },
  close() { ++request; audio?.pause(); if (audio) { audio.removeAttribute('src'); audio.load(); } update({ track: null, status: 'idle', currentTime: 0, duration: 0, error: null }); },
};

export function useAudio() { return useSyncExternalStore(subscribe, () => snapshot, () => snapshot); }
