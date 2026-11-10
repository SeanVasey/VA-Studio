import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import SoundKits from '../../resources/js/Pages/SoundKits';
import SoundKit from '../../resources/js/Pages/SoundKit';
import type { SoundKitDetail, SoundKitSummary } from '../../resources/js/lib/sound-kits';
import { defaultSiteContent, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

// Every title, summary and price below is synthetic.
const metadata: PageMetadata = { title: 'Synthetic', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/sound-kits', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'noindex, nofollow' };
const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2, navigation: [{ label: 'Catalog', href: '/#catalog' }, { label: 'Sound kits', href: '/sound-kits' }] };
const summary: SoundKitSummary = { slug: 'synthetic-kit', path: '/sound-kits/synthetic-kit', title: 'Synthetic <Kit>', memberCount: 2, totalDurationSeconds: 125, priceMinor: 2500, currency: 'USD', purchasable: false };
const detail: SoundKitDetail = { ...summary, summary: 'Plain <b>text</b> summary.' };

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
});
