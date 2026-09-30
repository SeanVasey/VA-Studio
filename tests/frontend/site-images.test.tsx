import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import type { SiteImageSet, SiteImages } from '../../resources/js/lib/site-content';

function set(name: string, widths: number[], ratio: number): SiteImageSet {
  const sources = (extension: string) => widths.map(width => ({ url: `/site-images/${name}-${width}.${extension}`, width, height: Math.round(width / ratio) }));
  const largest = widths[widths.length - 1];

  return { width: largest, height: Math.round(largest / ratio), jpeg: sources('jpg'), webp: sources('webp') };
}

const images: SiteImages = {
  hero: { alt: 'Synthetic hero description', desktop: set('desktop', [1200, 1800, 2400], 2400 / 890), mobile: set('mobile', [480, 720, 960], 960 / 890) },
  studio: { alt: 'Synthetic studio description', ...set('studio', [720, 1080, 1440], 1440 / 630) },
};

describe('site images', () => {
  it('keeps the built-in hero and studio files when the release sets no images', () => {
    const { container } = render(<Storefront tracks={[]} licenseTiers={[]} />);
    const hero = screen.getByRole('img', { name: 'Audio production console in the VASEY.AUDIO visual world' });
    expect(hero).toHaveAttribute('src', '/images/storefront-hero.jpg');
    expect(container.querySelector('.hero-media source')).toHaveAttribute('srcset', '/images/storefront-hero-mobile.jpg');
    expect(screen.getByRole('img', { name: 'VASEY.AUDIO production studio visual' })).toHaveAttribute('src', '/images/video-studio.jpg');
  });

  it('serves every prepared size, WebP first, with intrinsic dimensions and the release description', () => {
    const { container } = render(<Storefront tracks={[]} licenseTiers={[]} siteImages={images} />);
    const hero = screen.getByRole('img', { name: 'Synthetic hero description' });
    expect(hero).toHaveAttribute('src', '/site-images/desktop-2400.jpg');
    expect(hero).toHaveAttribute('srcset', '/site-images/desktop-1200.jpg 1200w, /site-images/desktop-1800.jpg 1800w, /site-images/desktop-2400.jpg 2400w');
    expect(hero).toHaveAttribute('width', '2400');
    expect(hero).toHaveAttribute('height', '890');
    expect(hero).toHaveAttribute('fetchpriority', 'high');
    const sources = [...container.querySelectorAll('.hero-media source')];
    expect(sources.map(source => [source.getAttribute('media'), source.getAttribute('type')])).toEqual([
      ['(max-width: 700px)', 'image/webp'], ['(max-width: 700px)', 'image/jpeg'], [null, 'image/webp'],
    ]);
    expect(sources[0]).toHaveAttribute('srcset', '/site-images/mobile-480.webp 480w, /site-images/mobile-720.webp 720w, /site-images/mobile-960.webp 960w');
    expect(sources[0]).toHaveAttribute('width', '960');
    expect(sources[0]).toHaveAttribute('height', '890');

    const studio = screen.getByRole('img', { name: 'Synthetic studio description' });
    expect(studio).toHaveAttribute('src', '/site-images/studio-1440.jpg');
    expect(studio).toHaveAttribute('loading', 'lazy');
    expect(container.querySelector('.studio-visual source')).toHaveAttribute('type', 'image/webp');
    expect(container.innerHTML).not.toContain('/images/storefront-hero.jpg');
    expect(container.innerHTML).not.toContain('/images/video-studio.jpg');
  });

  it('gives sizes as the width object-fit: cover paints each image at, not the width of its box', () => {
    const { container } = render(<Storefront tracks={[]} licenseTiers={[]} siteImages={images} />);
    // Hero: the larger of the viewport width and the hero's height × the image's aspect ratio, where the height is the
    // min-height or copy with a three-line heading, whichever is taller, at each breakpoint of app.css.
    const desktop = '(max-width: 900px) max(100vw, 2400 / 890 * max(580px, 325.8px + 3 * 0.81 * 16vw)), '
      + '(max-width: 1200px) max(100vw, 2400 / 890 * max(600px, 329px + 3 * 0.81 * 15vw)), '
      + '(min-width: 1600px) max(100vw, 2400 / 890 * max(710px, 355px + 3 * 0.81 * 190px)), '
      + 'max(100vw, 2400 / 890 * max(625px, 333px + 3 * 0.81 * clamp(100px, 12.8vw, 186px)))';
    const mobile = '(max-width: 600px) max(100vw, 960 / 890 * max(695px, 500.2px + 3 * 0.84 * clamp(84px, 21.5vw, 129px))), '
      + 'max(100vw, 960 / 890 * max(580px, 325.8px + 3 * 0.81 * 16vw))';
    expect([...container.querySelectorAll('.hero-media source, .hero-media img')].map(node => node.getAttribute('sizes'))).toEqual([mobile, mobile, desktop, desktop]);
    // Studio: a box 320 to 500 px tall, the full width less margins up to 900 px, then 11/21 of the row less margins and gap.
    const studio = '(max-width: 600px) max(100vw - 40px, 1440 / 630 * 320px), (max-width: 900px) max(91vw, 1440 / 630 * 360px), '
      + '(max-width: 1200px) max(86vw * 11 / 21, 1440 / 630 * 450px), max((92vw - 2 * clamp(20px, 4.5vw, 80px)) * 11 / 21, 1440 / 630 * 500px)';
    expect([...container.querySelectorAll('.studio-visual source, .studio-visual img')].map(node => node.getAttribute('sizes'))).toEqual([studio, studio]);
  });

  it('uses each image\'s own shape, which may differ from its slot\'s by up to 3%', () => {
    const taller = { ...images, studio: { alt: 'Synthetic taller studio', ...set('studio', [720, 1080, 1440], 1440 / 649) } };
    render(<Storefront tracks={[]} licenseTiers={[]} siteImages={taller} />);
    expect(screen.getByRole('img', { name: 'Synthetic taller studio' }).getAttribute('sizes')).toContain('max(91vw, 1440 / 649 * 360px)');
  });
});
