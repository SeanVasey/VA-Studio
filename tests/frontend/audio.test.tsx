import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { player } from '../../resources/js/lib/audio';
import { PersistentPlayer } from '../../resources/js/components/PersistentPlayer';
import { fixtureTracks } from '../../resources/js/test/fixtures';

const playable = { ...fixtureTracks[0], previewUrl: '/media/tagged-preview' };

afterEach(() => {
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
  vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
  act(() => player.close());
});

describe('single native audio owner', () => {
  it('never invokes native playback when preview media is absent', async () => {
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play');
    await player.play(fixtureTracks[0]);
    expect(play).not.toHaveBeenCalled();
  });
  it('uses one audio element for successive tracks and follows native playback state', async () => {
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('playing')); return Promise.resolve(); });
    const pause = vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('pause')); });
    const user = userEvent.setup();
    const next = { ...fixtureTracks[1], previewUrl: '/media/another-tagged-preview' };
    render(<PersistentPlayer tracks={[playable, next]} onLicense={() => {}} />);
    await act(() => player.play(playable));
    expect(screen.getByRole('button', { name: 'Pause preview' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Pause preview' }));
    expect(pause).toHaveBeenCalled();
    expect(screen.getByRole('button', { name: `Play ${playable.title}` })).toBeInTheDocument();
    await act(() => player.play(next));
    expect(play.mock.contexts[0]).toBe(play.mock.contexts[1]);
    expect((play.mock.contexts[1] as HTMLMediaElement).src).toBe(`${window.location.origin}/media/another-tagged-preview`);
  });
  it('shows a recoverable error when the native play promise rejects', async () => {
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockRejectedValue(new DOMException('Gesture required', 'NotAllowedError'));
    render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
    await act(() => player.play(playable));
    expect(screen.getByRole('status')).toHaveTextContent('Playback was interrupted. Select play to try again.');
    expect(screen.queryByRole('button', { name: 'Pause preview' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: `Play ${playable.title}` })).toBeInTheDocument();
  });
});


it('keeps the same active audio source and time while catalog pages change', async () => {
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
  const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('playing')); return Promise.resolve(); });
  const view = render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  act(() => { source.currentTime = 42; source.dispatchEvent(new Event('timeupdate')); });
  view.rerender(<PersistentPlayer tracks={[{ ...fixtureTracks[1], previewUrl: '/media/next-page-preview' }]} onLicense={() => {}} />);
  expect(screen.getByRole('button', { name: 'Pause preview' })).toBeInTheDocument();
  expect(play).toHaveBeenCalledTimes(1);
  expect(source.currentTime).toBe(42);
  expect(source.src).toBe(window.location.origin + playable.previewUrl);
});

it('replaces the native source when the same track publishes a new preview', async () => {
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
  const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('playing')); return Promise.resolve(); });
  render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  await act(async () => player.toggle({ ...playable, previewUrl: '/media/revised-preview' }));
  expect(play).toHaveBeenCalledTimes(2);
  expect(play.mock.contexts[1]).toBe(source);
  expect(source.src).toBe(window.location.origin + '/media/revised-preview');
  expect(screen.getByRole('button', { name: 'Pause preview' })).toBeInTheDocument();
});

const nextPreview = { ...fixtureTracks[1], previewUrl: '/media/another-tagged-preview' };
const lastPreview = { ...fixtureTracks[2], previewUrl: '/media/last-tagged-preview' };

function nativePlayback() {
  vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
  vi.spyOn(HTMLMediaElement.prototype, 'duration', 'get').mockReturnValue(60);
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('pause')); });
  return vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) {
    this.dispatchEvent(new Event('durationchange'));
    this.dispatchEvent(new Event('playing'));
    return Promise.resolve();
  });
}

it('keeps first-load and next-track playback off until the listener explicitly enables it', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
  expect(play).not.toHaveBeenCalled();
  await act(() => player.play(playable));
  await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
  expect(screen.getByRole('checkbox', { name: 'Play next automatically' })).not.toBeChecked();
  expect(screen.getByRole('combobox', { name: 'Repeat' })).toHaveValue('off');
  const source = play.mock.contexts[0] as HTMLMediaElement;
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(play).toHaveBeenCalledTimes(1);
  expect(screen.getByRole('status')).toHaveTextContent('Preview ended.');
  // Turning controls on while ended is not a gesture to play now.
  await user.click(screen.getByRole('checkbox', { name: 'Play next automatically' }));
  await user.selectOptions(screen.getByRole('combobox', { name: 'Repeat' }), 'queue');
  expect(play).toHaveBeenCalledTimes(1);
});

it('follows the reordered queue on end, preserves it through navigation, and stops at its end', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  const view = render(<PersistentPlayer tracks={[playable, nextPreview, lastPreview]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
  await user.click(screen.getByRole('checkbox', { name: 'Play next automatically' }));
  await user.click(screen.getByRole('button', { name: `Move ${lastPreview.title} earlier` }));
  await user.click(screen.getByRole('button', { name: `Remove ${nextPreview.title} from queue` }));
  view.rerender(<PersistentPlayer tracks={[]} onLicense={() => {}} />);
  expect(screen.getAllByRole('listitem')).toHaveLength(2);
  expect(screen.getByRole('button', { name: `Move ${lastPreview.title} later` })).toBeDisabled();
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(source.src).toBe(window.location.origin + lastPreview.previewUrl);
  expect(play).toHaveBeenCalledTimes(2);
  expect(play.mock.contexts[1]).toBe(source);
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(play).toHaveBeenCalledTimes(2);
  expect(screen.getByRole('status')).toHaveTextContent('Preview ended.');
});

it('repeats the queue only after explicit selection and clears that mode when automatic next is disabled', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
  await act(() => player.play(nextPreview));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
  await user.selectOptions(screen.getByRole('combobox', { name: 'Repeat' }), 'queue');
  expect(screen.getByRole('checkbox', { name: 'Play next automatically' })).toBeChecked();
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(source.src).toBe(window.location.origin + playable.previewUrl);
  await user.click(screen.getByRole('checkbox', { name: 'Play next automatically' }));
  expect(screen.getByRole('combobox', { name: 'Repeat' })).toHaveValue('off');
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(play).toHaveBeenCalledTimes(2);
});

it('repeats the current track from zero, while pause and close stop continuation', async () => {
  const play = nativePlayback();
  render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  act(() => { player.repeat('track'); source.currentTime = 60; });
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(source.currentTime).toBe(0);
  expect(play).toHaveBeenCalledTimes(2);
  act(() => player.pause());
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(play).toHaveBeenCalledTimes(2);
  act(() => player.close());
  await act(async () => { source.dispatchEvent(new Event('ended')); source.dispatchEvent(new Event('error')); });
  expect(play).toHaveBeenCalledTimes(2);
  expect(screen.getByText('Select a track to preview.')).toBeInTheDocument();
});

it('removing the current track keeps its source playing but does not guess a new queue position when it ends', async () => {
  const play = nativePlayback();
  render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  act(() => { player.autoNext(true); player.remove(playable.id); });
  expect(source.src).toBe(window.location.origin + playable.previewUrl);
  expect(screen.getByRole('button', { name: 'Pause preview' })).toBeInTheDocument();
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(play).toHaveBeenCalledTimes(1);
  await act(async () => player.next());
  expect(source.src).toBe(window.location.origin + nextPreview.previewUrl);
});

it('keeps loop markers accessible, rejects reversed bounds, loops within the preview and can clear them', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
  expect(screen.getByRole('button', { name: 'Set B here' })).toBeDisabled();
  act(() => player.seek(60));
  await user.click(screen.getByRole('button', { name: 'Set A here' }));
  expect(screen.getByRole('alert')).toHaveTextContent('before the end of the preview');
  act(() => player.seek(10));
  await user.click(screen.getByRole('button', { name: 'Set A here' }));
  await user.click(screen.getByRole('button', { name: 'Set B here' }));
  expect(screen.getByRole('alert')).toHaveTextContent('at least 0.1 seconds after the start');
  expect(screen.getByText('Loop off')).toBeInTheDocument();
  act(() => player.seek(20));
  await user.click(screen.getByRole('button', { name: 'Set B here' }));
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(screen.getByText('A: 0:10.0')).toBeInTheDocument();
  expect(screen.getByText('B: 0:20.0')).toBeInTheDocument();
  expect(source.currentTime).toBe(10);
  act(() => { source.currentTime = 20.3; source.dispatchEvent(new Event('timeupdate')); });
  expect(source.currentTime).toBe(10);
  act(() => player.seek(40));
  expect(source.currentTime).toBe(10);
  // Looping seeks the existing owner; it never stacks a second play call.
  expect(play).toHaveBeenCalledTimes(1);
  await user.click(screen.getByRole('button', { name: 'Clear loop' }));
  act(() => player.seek(40));
  expect(source.currentTime).toBe(40);
  expect(screen.getByText('Loop off')).toBeInTheDocument();
  await screen.getByRole('button', { name: 'Set A here' }).focus();
  await user.keyboard('{Escape}');
  expect(screen.queryByRole('region', { name: 'Preview queue and loop controls' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Queue & loop' })).toHaveFocus();
});

it('setting loop bounds while paused never starts playback, and source replacement clears the old loop', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  act(() => { player.pause(); player.seek(10); player.markLoopStart(); player.seek(20); player.markLoopEnd(); });
  expect(play).toHaveBeenCalledTimes(1);
  expect(source.currentTime).toBe(20);
  await act(() => player.play(playable));
  expect(source.currentTime).toBe(10);
  await act(() => player.play({ ...playable, previewUrl: '/media/new-revision' }));
  await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
  expect(screen.getByText('Loop off')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Set B here' })).toBeDisabled();
  expect(source.src).toBe(window.location.origin + '/media/new-revision');
});

it('a full-preview loop restarts at A on native ended, taking priority over automatic next', async () => {
  const play = nativePlayback();
  render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  act(() => { player.seek(10); player.markLoopStart(); player.seek(60); player.markLoopEnd(); player.autoNext(true); });
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(source.currentTime).toBe(10);
  expect(source.src).toBe(window.location.origin + playable.previewUrl);
  expect(play).toHaveBeenCalledTimes(2);
});

it('stops automatic continuation on a browser playback rejection and lets the listener retry', async () => {
  const play = nativePlayback();
  render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  act(() => player.autoNext(true));
  play.mockRejectedValueOnce(new DOMException('Gesture required', 'NotAllowedError'));
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(screen.getByRole('status')).toHaveTextContent('Playback was interrupted.');
  expect(screen.getByRole('button', { name: `Play ${nextPreview.title}` })).toBeInTheDocument();
  await act(async () => source.dispatchEvent(new Event('ended')));
  expect(play).toHaveBeenCalledTimes(2);
});

it('changes and resets preview speed without starting a paused player, requests native pitch preservation, and rejects invalid rates', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  Object.defineProperty(HTMLMediaElement.prototype, 'preservesPitch', { configurable: true, writable: true, value: false });
  try {
    render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
    await act(() => player.play(playable));
    const source = play.mock.contexts[0] as HTMLMediaElement;
    act(() => player.pause());
    await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
    const speed = screen.getByRole('combobox', { name: 'Playback speed' });
    expect(speed).toHaveValue('1');
    await user.selectOptions(speed, '1.5');
    expect(source.playbackRate).toBe(1.5);
    expect(source.defaultPlaybackRate).toBe(1.5);
    expect(source.preservesPitch).toBe(true);
    expect(play).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Pitch preservation is requested from your browser; results can vary.')).toBeInTheDocument();
    act(() => { player.speed(NaN); player.speed(Infinity); player.speed(0); player.speed(4); });
    expect(source.playbackRate).toBe(1.5);
    await act(() => player.play(nextPreview));
    expect(source.playbackRate).toBe(1.5);
    await user.click(screen.getByRole('button', { name: 'Reset speed' }));
    expect(speed).toHaveValue('1');
    expect(source.playbackRate).toBe(1);
    act(() => player.speed(2));
    act(() => player.close());
    await act(() => player.play(playable));
    expect(source.playbackRate).toBe(1);
    expect(source.defaultPlaybackRate).toBe(1);
  } finally {
    // The audio owner may outlive this component; remove only the test's capability override.
    Reflect.deleteProperty(HTMLMediaElement.prototype, 'preservesPitch');
    const source = play.mock.contexts[0] as HTMLMediaElement;
    if (source) Reflect.deleteProperty(source, 'preservesPitch');
  }
});

it('reports unavailable pitch control honestly and keeps a rejected speed change recoverable', async () => {
  const play = nativePlayback();
  const user = userEvent.setup();
  render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
  await act(() => player.play(playable));
  const source = play.mock.contexts[0] as HTMLMediaElement;
  await user.click(screen.getByRole('button', { name: 'Queue & loop' }));
  expect(screen.getByText('This browser could not enable pitch preservation; changing speed may change pitch.')).toBeInTheDocument();
  vi.spyOn(HTMLMediaElement.prototype, 'playbackRate', 'set').mockImplementationOnce(() => { throw new DOMException('Unsupported', 'NotSupportedError'); });
  await user.selectOptions(screen.getByRole('combobox', { name: 'Playback speed' }), '2');
  expect(screen.getByRole('alert')).toHaveTextContent('could not change playback speed');
  expect(source.playbackRate).toBe(1);
  expect(play).toHaveBeenCalledTimes(1);
  await user.selectOptions(screen.getByRole('combobox', { name: 'Playback speed' }), '0.75');
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(source.playbackRate).toBe(0.75);
});

function mediaSessionFixture(unsupported: MediaSessionAction[] = []) {
  const handlers = new Map<MediaSessionAction, MediaSessionActionHandler>();
  const session = {
    metadata: null as MediaMetadata | null, playbackState: 'none' as MediaSessionPlaybackState,
    setActionHandler: vi.fn((action: MediaSessionAction, handler: MediaSessionActionHandler | null) => {
      if (unsupported.includes(action)) throw new DOMException('Unsupported', 'NotSupportedError');
      if (handler) handlers.set(action, handler); else handlers.delete(action);
    }),
    setPositionState: vi.fn(),
  };
  Object.defineProperty(navigator, 'mediaSession', { configurable: true, value: session });
  vi.stubGlobal('MediaMetadata', class { constructor(input: MediaMetadataInit) { Object.assign(this, input); } });
  return { session, handlers, dispose: () => { act(() => player.close()); Reflect.deleteProperty(navigator, 'mediaSession'); vi.unstubAllGlobals(); } };
}

it('publishes public metadata only after selection and routes platform controls through the single player with finite position state', async () => {
  const platform = mediaSessionFixture();
  const play = nativePlayback();
  try {
    render(<PersistentPlayer tracks={[playable, nextPreview]} onLicense={() => {}} />);
    expect(platform.session.setActionHandler).not.toHaveBeenCalled();
    expect(platform.session.metadata).toBeNull();
    await act(() => player.play(playable));
    const source = play.mock.contexts[0] as HTMLMediaElement;
    expect(platform.session.metadata).toEqual(expect.objectContaining({ title: playable.title, artist: playable.artist }));
    expect(JSON.stringify(platform.session.metadata)).not.toContain('offer');
    expect(JSON.stringify(platform.session.metadata)).not.toContain(playable.previewUrl);
    expect(platform.session.playbackState).toBe('playing');
    act(() => platform.handlers.get('pause')!({ action: 'pause' }));
    expect(platform.session.playbackState).toBe('paused');
    await act(async () => platform.handlers.get('play')!({ action: 'play' }));
    expect(play.mock.contexts[1]).toBe(source);
    act(() => platform.handlers.get('seekto')!({ action: 'seekto', seekTime: 20 }));
    expect(source.currentTime).toBe(20);
    act(() => platform.handlers.get('seekforward')!({ action: 'seekforward', seekOffset: 5 }));
    expect(source.currentTime).toBe(25);
    act(() => platform.handlers.get('seekbackward')!({ action: 'seekbackward' }));
    expect(source.currentTime).toBe(15);
    act(() => { platform.handlers.get('seekto')!({ action: 'seekto', seekTime: NaN }); platform.handlers.get('seekforward')!({ action: 'seekforward', seekOffset: -5 }); });
    expect(source.currentTime).toBe(15);
    act(() => player.speed(1.25));
    expect(platform.session.setPositionState).toHaveBeenLastCalledWith({ duration: 60, playbackRate: 1.25, position: 15 });
    act(() => { player.markLoopStart(); player.seek(20); player.markLoopEnd(); });
    act(() => platform.handlers.get('seekto')!({ action: 'seekto', seekTime: 50 }));
    expect(source.currentTime).toBe(15);
    // Unknown/unbounded media duration cannot be sent as valid OS position state.
    vi.spyOn(HTMLMediaElement.prototype, 'duration', 'get').mockReturnValue(Infinity);
    act(() => source.dispatchEvent(new Event('durationchange')));
    expect(platform.session.setPositionState).toHaveBeenLastCalledWith();
    vi.spyOn(HTMLMediaElement.prototype, 'duration', 'get').mockReturnValue(60);
    await act(async () => platform.handlers.get('nexttrack')!({ action: 'nexttrack' }));
    expect(source.src).toBe(window.location.origin + nextPreview.previewUrl);
    expect(platform.session.metadata?.title).toBe(nextPreview.title);
    await act(async () => platform.handlers.get('previoustrack')!({ action: 'previoustrack' }));
    expect(source.src).toBe(window.location.origin + playable.previewUrl);
    const latePlay = platform.handlers.get('play')!;
    const lateSeek = platform.handlers.get('seekto')!;
    const calls = play.mock.calls.length;
    act(() => player.close());
    expect(platform.handlers.size).toBe(0);
    expect(platform.session.metadata).toBeNull();
    expect(platform.session.playbackState).toBe('none');
    expect(platform.session.setPositionState).toHaveBeenLastCalledWith();
    await act(async () => { latePlay({ action: 'play' }); lateSeek({ action: 'seekto', seekTime: 40 }); });
    expect(play).toHaveBeenCalledTimes(calls);
    expect(screen.getByText('Select a track to preview.')).toBeInTheDocument();
    await act(() => player.play(nextPreview));
    // A queued callback from a closed binding must remain inert after reopening.
    await act(async () => { latePlay({ action: 'play' }); lateSeek({ action: 'seekto', seekTime: 40 }); });
    expect(play).toHaveBeenCalledTimes(calls + 1);
    expect(source.currentTime).not.toBe(40);
  } finally { platform.dispose(); }
});

it('keeps playback and supported platform actions working when optional media-session capabilities throw', async () => {
  const platform = mediaSessionFixture(['seekto', 'nexttrack']);
  const play = nativePlayback();
  platform.session.setPositionState.mockImplementation(() => { throw new DOMException('Not supported'); });
  try {
    render(<PersistentPlayer tracks={[playable]} onLicense={() => {}} />);
    await act(() => player.play(playable));
    expect(play).toHaveBeenCalledTimes(1);
    expect(platform.handlers.has('seekto')).toBe(false);
    expect(platform.handlers.has('pause')).toBe(true);
    act(() => platform.handlers.get('pause')!({ action: 'pause' }));
    expect(screen.getByRole('button', { name: `Play ${playable.title}` })).toBeInTheDocument();
    expect(screen.getByRole('status')).not.toHaveTextContent('could not');
  } finally { platform.dispose(); }
});
