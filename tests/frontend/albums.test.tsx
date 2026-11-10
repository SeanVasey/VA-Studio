import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Albums from '../../resources/js/Pages/Albums';
import Album from '../../resources/js/Pages/Album';
import { releaseDateLabel, type AlbumDetail, type AlbumSummary } from '../../resources/js/lib/albums';
import { defaultSiteContent, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

// Every title, artist, link and image below is synthetic.
const metadata: PageMetadata = { title: 'Synthetic', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/albums', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'noindex, nofollow' };
const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2, navigation: [{ label: 'Catalog', href: '/#catalog' }, { label: 'Albums', href: '/albums' }] };
const cover = { alt: 'A synthetic blue field', width: 1200, height: 1200,
  jpeg: [{ url: '/site-images/a.jpg', width: 400, height: 400 }, { url: '/site-images/b.jpg', width: 1200, height: 1200 }],
  webp: [{ url: '/site-images/a.webp', width: 400, height: 400 }] };
const summary: AlbumSummary = { slug: 'synthetic-one', path: '/albums/synthetic-one', title: 'Synthetic <One>', artist: 'Synthetic Artist', type: 'ep', typeLabel: 'EP', releaseDate: '2024-03-01', trackCount: 2, cover };
const detail: AlbumDetail = { ...summary, description: 'Plain <b>text</b> description.',
  tracks: [{ position: 1, title: 'Track A', durationSeconds: 225, duration: '3:45' }, { position: 2, title: 'Track B', durationSeconds: 3725, duration: '1:02:05' }],
  links: [{ platform: 'spotify', label: 'Spotify', url: 'https://open.spotify.com/album/SYNTHETIC' }] };

describe('albums index', () => {
  it('renders a semantic list of releases with cover alt text, plain-text titles and links', () => {
    render(<Albums siteContent={chrome} albums={[summary, { ...summary, slug: 'two', path: '/albums/two', title: 'Two', cover: null, trackCount: 1 }]} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Albums' })).toBeInTheDocument();
    const list = screen.getByRole('list', { name: 'Releases, newest first' });
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(within(items[0]).getByRole('img', { name: 'A synthetic blue field' })).toHaveAttribute('src', '/site-images/b.jpg');
    expect(within(items[0]).getByRole('heading', { level: 2, name: 'Synthetic <One>' })).toBeInTheDocument();
    expect(within(items[0]).getByRole('link', { name: 'View Synthetic <One>' })).toHaveAttribute('href', '/albums/synthetic-one');
    expect(within(items[0]).getByText('2 tracks')).toBeInTheDocument();
    expect(within(items[1]).queryByRole('img')).toBeNull();
    expect(within(items[1]).getByText('1 track')).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
    expect(within(screen.getByRole('navigation', { name: 'Main navigation' })).getByRole('link', { name: 'Albums' })).toHaveAttribute('aria-current', 'page');
  });

  it('shows an honest empty state instead of invented releases', () => {
    render(<Albums siteContent={chrome} albums={[]} metadata={metadata} />);
    expect(screen.getByText('No releases are published yet.')).toBeInTheDocument();
    expect(screen.queryByRole('list', { name: 'Releases, newest first' })).toBeNull();
  });
});

describe('album detail', () => {
  it('renders the ordered tracklist, listening links and no purchase controls', () => {
    render(<Album siteContent={chrome} album={detail} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Synthetic <One>' })).toBeInTheDocument();
    expect(screen.getByText('Plain <b>text</b> description.')).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
    const tracks = within(screen.getByRole('region', { name: 'Tracklist' })).getAllByRole('listitem');
    expect(tracks.map(item => item.textContent)).toEqual(['Track ALength 3:45', 'Track BLength 1:02:05']);
    const link = screen.getByRole('link', { name: 'Listen to Synthetic <One> on Spotify' });
    expect(link).toHaveAttribute('href', 'https://open.spotify.com/album/SYNTHETIC');
    expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    expect(screen.getByRole('img', { name: 'A synthetic blue field' })).toHaveAttribute('width', '1200');
    expect(screen.queryByRole('button', { name: /buy|cart|checkout|license/i })).toBeNull();
    expect(screen.getByRole('link', { name: /Back to albums/ })).toHaveAttribute('href', '/albums');
    expect(document.querySelector('time[datetime="2024-03-01"]')).toHaveTextContent('March 1, 2024');
  });

  it('omits the listen section when a release has no links and the cover when it has no art', () => {
    render(<Album siteContent={chrome} album={{ ...detail, links: [], cover: null }} metadata={metadata} />);
    expect(screen.queryByRole('heading', { name: 'Listen' })).toBeNull();
    expect(screen.queryByRole('img', { name: 'A synthetic blue field' })).toBeNull();
  });
});

describe('release dates', () => {
  it('formats a stored date without shifting the day and keeps an invalid value readable', () => {
    expect(releaseDateLabel('2024-12-31')).toBe('December 31, 2024');
    expect(releaseDateLabel('not-a-date')).toBe('not-a-date');
  });
});
