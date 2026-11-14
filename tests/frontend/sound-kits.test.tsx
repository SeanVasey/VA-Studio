import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { player } from '../../resources/js/lib/audio';
import SoundKits from '../../resources/js/Pages/SoundKits';
import SoundKit from '../../resources/js/Pages/SoundKit';
import type { SoundKitDetail, SoundKitSummary } from '../../resources/js/lib/sound-kits';
import { defaultSiteContent, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

// Every title, summary and price below is synthetic.
const metadata: PageMetadata = { title: 'Synthetic', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/sound-kits', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'noindex, nofollow' };
const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2, navigation: [{ label: 'Catalog', href: '/#catalog' }, { label: 'Sound kits', href: '/sound-kits' }] };
const summary: SoundKitSummary = { slug: 'synthetic-kit', path: '/sound-kits/synthetic-kit', title: 'Synthetic <Kit>', memberCount: 2, totalDurationSeconds: 125, priceMinor: 2500, currency: 'USD', purchasable: false, cover: null };
const detail: SoundKitDetail = { ...summary, summary: 'Plain <b>text</b> summary.', demo: null };
// Synthetic cover: addresses only, as the server sends them (content-hashed image routes), with a staff-written description.
const cover = { alt: 'A synthetic <cover> description', width: 1200, height: 1200,
  jpeg: [{ url: '/site-images/' + 'a'.repeat(64) + '.jpg', width: 400, height: 400 }, { url: '/site-images/' + 'b'.repeat(64) + '.jpg', width: 1200, height: 1200 }],
  webp: [{ url: '/site-images/' + 'c'.repeat(64) + '.webp', width: 400, height: 400 }] };
const demo = { url: '/sound-kits/synthetic-kit/demo/0b8e5f7e-6f34-4a52-9a43-0d6e8d8f3e11', durationSeconds: 75, waveform: [0.1, 0.5, 0.9] };

afterEach(() => {
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
  vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
  act(() => player.close());
});

describe('sound kits index', () => {
  it('lists kits as plain text with a price and no purchase control', () => {
    render(<SoundKits siteContent={chrome} kits={[summary, { ...summary, slug: 'two', path: '/sound-kits/two', title: 'Two', memberCount: 1, priceMinor: 999 }]} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Sound kits' })).toBeInTheDocument();
    const items = within(screen.getByRole('list', { name: 'Sound kits' })).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(within(items[0]).getByRole('heading', { level: 2, name: 'Synthetic <Kit>' })).toBeInTheDocument();
    expect(within(items[0]).getByRole('link', { name: 'View Synthetic <Kit>' })).toHaveAttribute('href', '/sound-kits/synthetic-kit');
    expect(within(items[0]).getByText('2 samples')).toBeInTheDocument();
    expect(within(items[0]).getByText('$25')).toBeInTheDocument();
    expect(within(items[1]).getByText('1 sample')).toBeInTheDocument();
    expect(within(items[1]).getByText('$9.99')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /buy|cart|checkout|download/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /buy|cart|checkout|download/i })).toBeNull();
    expect(within(screen.getByRole('navigation', { name: 'Main navigation' })).getByRole('link', { name: 'Sound kits' })).toHaveAttribute('aria-current', 'page');
  });

  it('shows each kit\'s approved cover beside its title, and none for a kit without one', () => {
    render(<SoundKits siteContent={chrome} kits={[{ ...summary, cover }, { ...summary, slug: 'two', path: '/sound-kits/two', title: 'Two' }]} metadata={metadata} />);
    const items = within(screen.getByRole('list', { name: 'Sound kits' })).getAllByRole('listitem');
    expect(within(items[0]).getByRole('img', { name: 'A synthetic <cover> description' })).toBeInTheDocument();
    expect(within(items[1]).queryByRole('img')).toBeNull();
  });

  it('shows an honest empty state instead of invented kits', () => {
    render(<SoundKits siteContent={chrome} kits={[]} metadata={metadata} />);
    expect(screen.getByText('No sound kits are published yet.')).toBeInTheDocument();
    expect(screen.queryByRole('list', { name: 'Sound kits' })).toBeNull();
  });
});

describe('sound kit detail', () => {
  it('renders the summary as text, the counted facts and an honest not-available notice with no purchase control', () => {
    render(<SoundKit siteContent={chrome} kit={detail} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Synthetic <Kit>' })).toBeInTheDocument();
    expect(screen.getByText('Plain <b>text</b> summary.')).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
    const facts = within(screen.getByRole('region', { name: 'Details' }));
    expect(facts.getByText('2:05')).toBeInTheDocument();
    expect(facts.getByText('$25')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('This kit is not available to buy yet.');
    expect(within(screen.getByRole('main')).queryByRole('button')).toBeNull();
    expect(screen.queryByRole('link', { name: /buy|cart|checkout|download/i })).toBeNull();
    expect(screen.getByRole('link', { name: /Back to sound kits/ })).toHaveAttribute('href', '/sound-kits');
  });

  it('omits the summary paragraph when staff wrote none', () => {
    render(<SoundKit siteContent={chrome} kit={{ ...detail, summary: null }} metadata={metadata} />);
    expect(document.querySelector('.sound-kit-summary')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  });

  it('draws the approved cover with its description, and nothing when there is none', () => {
    const { rerender } = render(<SoundKit siteContent={chrome} kit={{ ...detail, cover }} metadata={metadata} />);
    const image = screen.getByRole('img', { name: 'A synthetic <cover> description' });
    expect(image).toHaveAttribute('src', cover.jpeg[1].url);
    expect(image).toHaveAttribute('srcset', expect.stringContaining('400w'));
    expect(document.querySelector('source[type="image/webp"]')).not.toBeNull();
    rerender(<SoundKit siteContent={chrome} kit={detail} metadata={metadata} />);
    expect(screen.queryByRole('img', { name: /cover/ })).toBeNull();
    expect(document.querySelector('.sound-kit-art')).toBeNull();
  });

  it('plays the approved demo through the storefront player, with no licence offered', async () => {
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('playing')); return Promise.resolve(); });
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('pause')); });
    const user = userEvent.setup();
    render(<SoundKit siteContent={chrome} kit={{ ...detail, cover, demo }} metadata={metadata} />);
    const region = within(screen.getByRole('region', { name: 'Demo' }));
    expect(region.getByText('1:15')).toBeInTheDocument();
    await user.click(region.getByRole('button', { name: 'Play the demo of Synthetic <Kit>' }));
    expect(play).toHaveBeenCalledTimes(1);
    expect((play.mock.contexts[0] as HTMLMediaElement).src).toBe(`${window.location.origin}${demo.url}`);
    expect(region.getByRole('button', { name: 'Pause the demo of Synthetic <Kit>' })).toBeInTheDocument();
    // The shared player shows the demo with the cover as artwork and cannot start a licence or a purchase.
    const bar = within(screen.getByRole('complementary', { name: 'Audio preview player' }));
    expect(bar.getByText('Synthetic <Kit> (demo)')).toBeInTheDocument();
    expect(bar.getByRole('button', { name: /^License/ })).toBeDisabled();
    expect(screen.queryByRole('link', { name: /buy|cart|checkout|download/i })).toBeNull();
    await user.click(region.getByRole('button', { name: 'Pause the demo of Synthetic <Kit>' }));
    expect(region.getByRole('button', { name: 'Play the demo of Synthetic <Kit>' })).toBeInTheDocument();
  });

  it('offers no player and no demo section when there is no approved demo', () => {
    render(<SoundKit siteContent={chrome} kit={{ ...detail, cover }} metadata={metadata} />);
    expect(screen.queryByRole('region', { name: 'Demo' })).toBeNull();
    expect(screen.queryByRole('button', { name: /demo/i })).toBeNull();
  });
});
