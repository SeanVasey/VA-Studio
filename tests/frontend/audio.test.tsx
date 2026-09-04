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
