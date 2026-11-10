import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Collections from '../../resources/js/Pages/Collections';
import Collection from '../../resources/js/Pages/Collection';
import type { CollectionDetail, CollectionSummary } from '../../resources/js/lib/collections';
import { defaultSiteContent, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

// Every title, artist and path below is synthetic.
const metadata: PageMetadata = { title: 'Synthetic', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/collections', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'noindex, nofollow' };
const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2, navigation: [{ label: 'Catalog', href: '/#catalog' }, { label: 'Collections', href: '/collections' }] };
const summary: CollectionSummary = { slug: 'synthetic-one', path: '/collections/synthetic-one', title: 'Synthetic <One>', trackCount: 2, preview: ['Track A', 'Track B'] };
const detail: CollectionDetail = { slug: 'synthetic-one', path: '/collections/synthetic-one', title: 'Synthetic <One>', trackCount: 2, description: 'Plain <b>text</b> description.',
  tracks: [
    { id: '7', slug: 'track-a', path: '/tracks/track-a', title: 'Track A', artist: 'Synthetic Artist', bpm: 90, musicalKey: 'C minor', genre: 'Test', durationSeconds: 125, artworkUrl: '/media/public/7' },
    { id: '9', slug: 'track-b', path: '/tracks/track-b', title: 'Track B', artist: 'Synthetic Artist', bpm: 120, musicalKey: 'A major', genre: 'Test', durationSeconds: 61, artworkUrl: null },
  ] };

describe('collections index', () => {
  it('renders a list of collections linking to their own pages, as plain text, with no price or purchase control', () => {
    render(<Collections siteContent={chrome} collections={[summary, { ...summary, slug: 'two', path: '/collections/two', title: 'Two', trackCount: 1, preview: ['Track C'] }]} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Collections' })).toBeInTheDocument();
    const items = within(screen.getByRole('list', { name: 'Collections' })).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(within(items[0]).getByRole('heading', { level: 2, name: 'Synthetic <One>' })).toBeInTheDocument();
    expect(within(items[0]).getByRole('link', { name: 'View Synthetic <One>' })).toHaveAttribute('href', '/collections/synthetic-one');
    expect(within(items[0]).getByText('2 tracks')).toBeInTheDocument();
    expect(within(items[1]).getByText('1 track')).toBeInTheDocument();
    expect(items[0]).toHaveTextContent('Track A · Track B');
    expect(document.querySelector('b')).toBeNull();
    expect(screen.queryByRole('button', { name: /buy|cart|checkout|bundle/i })).toBeNull();
    expect(document.body.textContent ?? '').not.toMatch(/[$€£]\s?\d/);
    expect(within(screen.getByRole('navigation', { name: 'Main navigation' })).getByRole('link', { name: 'Collections' })).toHaveAttribute('aria-current', 'page');
  });

  it('shows an honest empty state instead of invented collections', () => {
    render(<Collections siteContent={chrome} collections={[]} metadata={metadata} />);
    expect(screen.getByText('No collections are published yet.')).toBeInTheDocument();
    expect(screen.queryByRole('list', { name: 'Collections' })).toBeNull();
  });
});

describe('collection detail', () => {
  it('renders the ordered tracks, each linking to its own page for licensing, and no collection price or bundle control', () => {
    render(<Collection siteContent={chrome} collection={detail} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Synthetic <One>' })).toBeInTheDocument();
    expect(screen.getByText('Plain <b>text</b> description.')).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
    const rows = within(screen.getByRole('region', { name: 'Tracks' })).getAllByRole('listitem');
    expect(rows).toHaveLength(2);
    expect(within(rows[0]).getByRole('link', { name: 'Track A' })).toHaveAttribute('href', '/tracks/track-a');
    expect(within(rows[1]).getByRole('link', { name: 'Track B' })).toHaveAttribute('href', '/tracks/track-b');
    expect(within(rows[0]).getByRole('link', { name: 'License options for Track A' })).toHaveAttribute('href', '/tracks/track-a');
    expect(rows[0]).toHaveTextContent('90 BPM · C minor · 2:05');
    expect(rows[1]).toHaveTextContent('120 BPM · A major · 1:01');
    expect(rows[0].querySelector('img')).not.toBeNull();
    expect(rows[1].querySelector('img')).toBeNull();
    expect(screen.getByText('Each track is licensed on its own page. A collection has no price of its own.')).toBeInTheDocument();
    expect(within(screen.getByRole('main')).queryByRole('button')).toBeNull();
    expect(document.body.textContent ?? '').not.toMatch(/[$€£]\s?\d/);
    expect(screen.getByRole('link', { name: /Back to collections/ })).toHaveAttribute('href', '/collections');
  });
});
