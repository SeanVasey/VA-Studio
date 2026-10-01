import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { EditorialVideo } from '../../resources/js/components/EditorialVideo';
import { player } from '../../resources/js/lib/audio';

const youtube = { provider: 'youtube', videoId: 'abcdefghijk', watchUrl: 'https://www.youtube.com/watch?v=abcdefghijk' };
const vimeo = { provider: 'vimeo', videoId: '123456789', watchUrl: 'https://vimeo.com/123456789' };

describe('visitor-controlled editorial videos', () => {
  it.each([
    [youtube, 'YouTube', 'https://www.youtube-nocookie.com/embed/abcdefghijk?autoplay=0&playsinline=1'],
    [vimeo, 'Vimeo', 'https://player.vimeo.com/video/123456789?autoplay=0&dnt=1'],
  ])('loads only after intent and removes the provider frame with keyboard focus recovery', async (video, name, src) => {
    const user = userEvent.setup();
    const pause = vi.spyOn(player, 'pause').mockImplementation(() => {});
    const write = vi.spyOn(Storage.prototype, 'setItem');
    render(<EditorialVideo video={video} title="Synthetic video" />);
    expect(document.querySelector('iframe, script, video')).toBeNull();
    expect(pause).not.toHaveBeenCalled();
    const load = screen.getByRole('button', { name: `Load ${name} video` });
    load.focus(); await user.keyboard('{Enter}');
    const frame = screen.getByTitle(`Synthetic video — ${name} video`);
    expect(frame).toHaveAttribute('src', src);
    expect(frame).toHaveAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    expect(frame).toHaveAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
    expect(frame).not.toHaveAttribute('allow', expect.stringContaining('autoplay'));
    expect(pause).toHaveBeenCalledTimes(1);
    expect(load).toBeDisabled();
    expect(screen.getByRole('link', { name: `Watch on ${name}` })).toHaveAttribute('href', typeof video === 'object' ? video.watchUrl : '');
    await user.click(screen.getByRole('button', { name: 'Remove video player' }));
    expect(document.querySelector('iframe')).toBeNull();
    await waitFor(() => expect(load).toHaveFocus());
    expect(write).not.toHaveBeenCalled();
  });

  it('resets intent across video changes and public/private transitions', () => {
    const view = render(<EditorialVideo video={youtube} title="One" />);
    fireEvent.click(screen.getByRole('button', { name: 'Load YouTube video' }));
    expect(document.querySelector('iframe')).not.toBeNull();
    view.rerender(<EditorialVideo video={vimeo} title="Two" />);
    expect(document.querySelector('iframe')).toBeNull();
    view.rerender(<EditorialVideo video={youtube} title="One" />);
    expect(document.querySelector('iframe')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Load YouTube video' }));
    view.rerender(<EditorialVideo video={youtube} title="One" privatePreview />);
    expect(document.querySelector('iframe')).toBeNull();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    view.rerender(<EditorialVideo video={youtube} title="One" />);
    expect(document.querySelector('iframe')).toBeNull();
  });

  it.each([
    null, {}, { ...youtube, provider: 'unknown' }, { ...youtube, videoId: 'abc/efghijk' },
    { ...youtube, videoId: 'abcdefghijk\n' }, { ...youtube, watchUrl: 'javascript:alert(1)' },
    { ...vimeo, videoId: '123456789\n', watchUrl: 'https://vimeo.com/123456789\n' },
    { ...vimeo, videoId: '0', watchUrl: 'https://vimeo.com/0' },
    { ...vimeo, watchUrl: 'https://vimeo.com/123456789?untrusted=1' },
  ])('does not embed or link an invalid descriptor', value => {
    render(<EditorialVideo video={value} title="Synthetic" />);
    expect(screen.getByRole('status')).toHaveTextContent('unavailable');
    expect(document.querySelector('iframe, a, button')).toBeNull();
  });
});
