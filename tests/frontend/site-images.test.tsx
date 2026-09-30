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
    expect(hero).toHaveAttribute('sizes', '100vw');
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
    expect(studio).toHaveAttribute('sizes', '(max-width: 900px) 100vw, 50vw');
    expect(container.querySelector('.studio-visual source')).toHaveAttribute('type', 'image/webp');
    expect(container.innerHTML).not.toContain('/images/storefront-hero.jpg');
    expect(container.innerHTML).not.toContain('/images/video-studio.jpg');
  });
});
